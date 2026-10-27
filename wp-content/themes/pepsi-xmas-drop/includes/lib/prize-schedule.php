<?php
defined( 'ABSPATH' ) || exit;

/**
 * Xmas Drop prize schedule — generated from config, not a static CSV.
 *
 * The pool (tier → count + display name) is defined once in pf_prize_pool()
 * and every tier must have an amount in pf_prize_amount_map(), so tier, amount
 * and count can't drift apart. pf_prize_schedule_build() writes the BPM prize
 * post + wp_bpm_prize_entries row pair the game reserves from
 * (pf_reserve_bpm_win_slot) and the BPM admin displays.
 *
 * Spreading: each tier is stratified independently across the campaign's
 * playable minutes (daily hours window ∩ competition dates). The playable
 * timeline is cut into N equal blocks and each slot lands on a random minute
 * inside its block — even per day, no £250 clumping, still unpredictable.
 *
 * Re-runnable: without --wipe the build rebalances. Claimed rows and rows that
 * are live/reserved right now are never touched; every other unclaimed row
 * (expired-unwon or future) is re-spread across the remaining campaign, and the
 * tier is topped up (or trimmed) to `count − claimed`. The daily cron runs this
 * as the rollover so unwon slots aren't lost.
 */

const PF_PRIZE_ROLLOVER_HOOK = 'pf_prize_schedule_rollover';

/** @return array<string, array{count: int, name: string}> keyed by prize_type */
function pf_prize_pool(): array {
	return (array) apply_filters( 'pf_prize_pool', [
		'cash_250' => [ 'count' => 20,  'name' => '£250 Cash' ],
		'cash_10'  => [ 'count' => 500, 'name' => '£10 Cash' ],
	] );
}

/** Daily window prizes may go live in, site-local H:i. End is exclusive. */
function pf_prize_daily_hours(): array {
	return (array) apply_filters( 'pf_prize_daily_hours', [ 'start' => '07:00', 'end' => '23:00' ] );
}

/** @return array{start: string, end: string}|null competition dates, site-local MySQL datetimes */
function pf_prize_competition_dates( int $competition_id ): ?array {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT start_date, end_date FROM {$wpdb->prefix}bpm_competitions WHERE id = %d",
		$competition_id
	), ARRAY_A );
	if ( ! $row || empty( $row['start_date'] ) || empty( $row['end_date'] ) ) {
		return null;
	}
	return [ 'start' => (string) $row['start_date'], 'end' => (string) $row['end_date'] ];
}

/**
 * Playable timeline between two instants, clipped to the daily hours window.
 *
 * @return array{segments: array<int, array{0: int, 1: int}>, minutes: int}
 *   segments = [ [ start_ts, length_in_minutes ], ... ]
 */
function pf_prize_timeline( DateTimeImmutable $from, DateTimeImmutable $to ): array {
	$tz    = wp_timezone();
	$hours = pf_prize_daily_hours();
	$segs  = [];
	$total = 0;

	// Round $from up to a whole minute so every slot lands on :00 seconds.
	$from_ts = (int) ( ceil( $from->getTimestamp() / 60 ) * 60 );
	$to_ts   = $to->getTimestamp();

	$day = $from->setTimezone( $tz )->setTime( 0, 0 );
	while ( $day->getTimestamp() < $to_ts ) {
		$ymd   = $day->format( 'Y-m-d' );
		$open  = new DateTimeImmutable( $ymd . ' ' . $hours['start'], $tz );
		$close = new DateTimeImmutable( $ymd . ' ' . $hours['end'], $tz );
		$s     = max( $open->getTimestamp(), $from_ts );
		$e     = min( $close->getTimestamp(), $to_ts );
		$len   = intdiv( $e - $s, 60 );
		if ( $len > 0 ) {
			$segs[] = [ $s, $len ];
			$total += $len;
		}
		$day = $day->modify( '+1 day' );
	}
	return [ 'segments' => $segs, 'minutes' => $total ];
}

/** Minute index on the timeline → unix timestamp. */
function pf_prize_timeline_ts( array $timeline, int $index ): int {
	foreach ( $timeline['segments'] as [ $start, $len ] ) {
		if ( $index < $len ) {
			return $start + $index * 60;
		}
		$index -= $len;
	}
	return 0;
}

/**
 * $count stratified-random minute indexes across the timeline, avoiding
 * indexes in $used (shared across tiers so no two prizes share a minute).
 *
 * @return int[]
 */
function pf_prize_stratified_minutes( int $total, int $count, array &$used ): array {
	$out = [];
	if ( $count <= 0 || $total <= 0 ) {
		return $out;
	}
	$block = $total / $count;
	for ( $i = 0; $i < $count; $i++ ) {
		$lo  = (int) floor( $i * $block );
		$hi  = max( $lo, (int) floor( ( $i + 1 ) * $block ) - 1 );
		$pick = random_int( $lo, $hi );
		// Collision (only possible when tiers overlap): walk the block, then
		// accept a shared minute rather than fail — reservation copes fine.
		if ( isset( $used[ $pick ] ) ) {
			for ( $j = $lo; $j <= $hi; $j++ ) {
				if ( ! isset( $used[ $j ] ) ) {
					$pick = $j;
					break;
				}
			}
		}
		$used[ $pick ] = true;
		$out[]         = $pick;
	}
	return $out;
}

function pf_prize_generate_code(): string {
	global $wpdb;
	$table = $wpdb->prefix . 'bpm_prize_entries';
	do {
		$code = 'XD' . strtoupper( wp_generate_password( 8, false ) );
	} while ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE code = %s", $code ) ) > 0 );
	return $code;
}

/** Create one prize post + table row (mirrors BPM's add_single_prize). */
function pf_prize_create_slot( int $competition_id, string $prize_type, string $prize_name, string $date ): int {
	global $wpdb;
	$unique_id = wp_generate_uuid4();
	$code      = pf_prize_generate_code();
	$now       = current_time( 'mysql' );
	$mapped    = [
		'unique_id'  => $unique_id,
		'code'       => $code,
		'prize_type' => $prize_type,
		'prize'      => $prize_name,
		'date'       => $date,
		'claimed'    => 'false',
	];

	$post_id = wp_insert_post( [
		'post_title'  => sprintf( 'Prize %s - %s', $unique_id, mysql2date( 'd/m/Y H:i', $date ) ),
		'post_type'   => 'prize',
		'post_status' => 'publish',
		'meta_input'  => [
			'date'               => $date,
			'prize'              => $prize_name,
			'prize_name'         => $prize_name,
			'prize_type'         => $prize_type,
			'code'               => $code,
			'claimed'            => 'false',
			'competition_id'     => $competition_id,
			'unique_id'          => $unique_id,
			'original_timestamp' => strtotime( get_gmt_from_date( $date ) . ' UTC' ),
			'mapped_data'        => wp_json_encode( $mapped ),
		],
	], true );
	if ( is_wp_error( $post_id ) ) {
		return 0;
	}

	$ok = $wpdb->insert(
		$wpdb->prefix . 'bpm_prize_entries',
		[
			'competition_id' => $competition_id,
			'prize_post_id'  => $post_id,
			'unique_id'      => $unique_id,
			'code'           => $code,
			'prize_type'     => $prize_type,
			'prize_name'     => $prize_name,
			'date_available' => $date,
			'claimed'        => 0,
			'source_route'   => 'both',
			'original_data'  => wp_json_encode( $mapped + [ 'generator' => 'pf_prize_schedule_build' ] ),
			'created_at'     => $now,
			'updated_at'     => $now,
		],
		[ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
	);
	if ( $ok === false ) {
		wp_delete_post( $post_id, true );
		return 0;
	}
	return (int) $wpdb->insert_id;
}

/** Move an existing unclaimed slot to a new minute (row + post meta). */
function pf_prize_move_slot( int $entry_id, int $post_id, string $date ): void {
	global $wpdb;
	$wpdb->query( $wpdb->prepare(
		"UPDATE {$wpdb->prefix}bpm_prize_entries
		 SET date_available = %s, reserved_at = NULL, reservation_key = NULL, reservation_expires_at = NULL,
		     reseeded = 1, reseeded_count = COALESCE(reseeded_count, 0) + 1, last_reseeded = %s, updated_at = %s
		 WHERE id = %d AND claimed = 0",
		$date, current_time( 'mysql' ), current_time( 'mysql' ), $entry_id
	) );
	if ( $post_id <= 0 ) {
		return;
	}
	if ( class_exists( 'BPM_Prize_Sync' ) && method_exists( 'BPM_Prize_Sync', 'write_date' ) ) {
		BPM_Prize_Sync::write_date( $post_id, $date );
	} else {
		update_post_meta( $post_id, 'date', $date );
	}
}

/** Delete one slot's row + post. */
function pf_prize_delete_slot( int $entry_id, int $post_id ): void {
	global $wpdb;
	$wpdb->delete( $wpdb->prefix . 'bpm_prize_entries', [ 'id' => $entry_id, 'claimed' => 0 ], [ '%d', '%d' ] );
	if ( $post_id > 0 ) {
		wp_delete_post( $post_id, true );
	}
}

/**
 * Remove every prize post + entry row on the install (all competitions,
 * orphans and non-publish posts included). Audit log is left intact.
 */
function pf_prize_wipe_all(): array {
	global $wpdb;
	$rows  = (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}bpm_prize_entries" );
	$wpdb->query(
		"DELETE pm FROM {$wpdb->postmeta} pm
		 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		 WHERE p.post_type = 'prize'"
	);
	$posts = (int) $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'prize'" );
	wp_cache_flush();
	return [ 'rows' => $rows, 'posts' => $posts ];
}

/**
 * Build / rebalance the schedule for a competition.
 *
 * @param array $opts competition_id (default: pf_resolve_competition_id()),
 *                    dry_run (bool), wipe (bool — delete ALL prizes first),
 *                    trigger (string, for the audit log)
 * @return array report: ok, error?, per-tier counts, per-day preview
 */
function pf_prize_schedule_build( array $opts = [] ): array {
	global $wpdb;
	$table          = $wpdb->prefix . 'bpm_prize_entries';
	$competition_id = (int) ( $opts['competition_id'] ?? ( function_exists( 'pf_resolve_competition_id' ) ? pf_resolve_competition_id() : 0 ) );
	$dry_run        = ! empty( $opts['dry_run'] );
	$wipe           = ! empty( $opts['wipe'] );
	$trigger        = (string) ( $opts['trigger'] ?? 'manual' );

	if ( $competition_id <= 0 ) {
		return [ 'ok' => false, 'error' => 'No competition resolved (set the active competition in BPM).' ];
	}
	$dates = pf_prize_competition_dates( $competition_id );
	if ( ! $dates ) {
		return [ 'ok' => false, 'error' => "Competition {$competition_id} has no start/end dates." ];
	}

	// Every tier must pay something — never schedule a slot Stripe can't pay.
	$pool = pf_prize_pool();
	foreach ( $pool as $type => $cfg ) {
		if ( ! pf_is_cash_prize_type( (string) $type ) || pf_prize_amount_pence_for_type( (string) $type ) <= 0 ) {
			return [ 'ok' => false, 'error' => "Prize tier '{$type}' has no amount in pf_prize_amount_map()." ];
		}
	}

	if ( $wipe && wp_get_environment_type() === 'production' ) {
		$claimed = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE competition_id = %d AND claimed = 1",
			$competition_id
		) );
		if ( $claimed > 0 ) {
			return [ 'ok' => false, 'error' => "Refusing to wipe on production: competition {$competition_id} has {$claimed} claimed prize(s)." ];
		}
	}

	$tz     = wp_timezone();
	$now    = new DateTimeImmutable( 'now', $tz );
	$start  = new DateTimeImmutable( $dates['start'], $tz );
	$end    = new DateTimeImmutable( $dates['end'], $tz );
	$window = function_exists( 'pf_prize_window_minutes' ) ? pf_prize_window_minutes( $competition_id ) : 10;
	$now_sql      = $now->format( 'Y-m-d H:i:s' );
	$live_cut_sql = $now->modify( "-{$window} minutes" )->format( 'Y-m-d H:i:s' );

	// Slots go out no earlier than the next minute, and never after the
	// campaign closes minus one window (so the last slot is still winnable).
	$from     = $start > $now ? $start : $now->modify( '+1 minute' );
	$to       = $end->modify( "-{$window} minutes" );
	$timeline = pf_prize_timeline( $from, $to );
	if ( $timeline['minutes'] <= 0 ) {
		return [ 'ok' => false, 'error' => 'No playable minutes left between ' . $from->format( 'Y-m-d H:i' ) . ' and ' . $to->format( 'Y-m-d H:i' ) . ' inside the daily hours window.' ];
	}

	$used   = [];
	$plan   = []; // [ type => [ 'claimed', 'locked', 'move' => rows, 'create' => n, 'trim' => rows, 'minutes' => [] ] ]
	foreach ( $pool as $type => $cfg ) {
		$target = max( 0, (int) $cfg['count'] );
		if ( $wipe ) {
			$claimed = 0;
			$locked  = 0;
			$movable = [];
		} else {
			$claimed = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE competition_id = %d AND prize_type = %s AND claimed = 1",
				$competition_id, $type
			) );
			// Live right now, or held by an in-progress reservation: leave alone.
			$locked = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				 WHERE competition_id = %d AND prize_type = %s AND claimed = 0
				   AND ( ( date_available >= %s AND date_available <= %s ) OR reservation_expires_at >= %s )",
				$competition_id, $type, $live_cut_sql, $now_sql, $now_sql
			) );
			$movable = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT id, prize_post_id FROM {$table}
				 WHERE competition_id = %d AND prize_type = %s AND claimed = 0
				   AND NOT ( date_available >= %s AND date_available <= %s )
				   AND ( reservation_expires_at IS NULL OR reservation_expires_at < %s )
				 ORDER BY id",
				$competition_id, $type, $live_cut_sql, $now_sql, $now_sql
			) );
		}
		$need   = max( 0, $target - $claimed - $locked );
		$keep   = array_slice( $movable, 0, $need );
		$trim   = array_slice( $movable, $need );
		$create = $need - count( $keep );

		$plan[ $type ] = [
			'name'    => (string) $cfg['name'],
			'target'  => $target,
			'claimed' => $claimed,
			'locked'  => $locked,
			'move'    => $keep,
			'create'  => $create,
			'trim'    => $trim,
			'minutes' => pf_prize_stratified_minutes( $timeline['minutes'], $need, $used ),
		];
	}

	// Per-day / per-tier preview.
	$by_day = [];
	foreach ( $plan as $type => $p ) {
		foreach ( $p['minutes'] as $m ) {
			$day = wp_date( 'Y-m-d', pf_prize_timeline_ts( $timeline, $m ), $tz );
			$by_day[ $day ][ $type ] = ( $by_day[ $day ][ $type ] ?? 0 ) + 1;
		}
	}
	ksort( $by_day );

	$report = [
		'ok'             => true,
		'dry_run'        => $dry_run,
		'wipe'           => $wipe,
		'competition_id' => $competition_id,
		'from'           => $from->format( 'Y-m-d H:i' ),
		'to'             => $to->format( 'Y-m-d H:i' ),
		'daily_hours'    => pf_prize_daily_hours(),
		'window_minutes' => $window,
		'tiers'          => array_map( static fn( $p ) => [
			'name'    => $p['name'],
			'target'  => $p['target'],
			'claimed' => $p['claimed'],
			'live'    => $p['locked'],
			'moved'   => count( $p['move'] ),
			'created' => $p['create'],
			'trimmed' => count( $p['trim'] ),
		], $plan ),
		'by_day'         => $by_day,
	];
	if ( $dry_run ) {
		return $report;
	}

	if ( $wipe ) {
		$report['wiped'] = pf_prize_wipe_all();
	}

	$failed = 0;
	foreach ( $plan as $type => $p ) {
		$dates_sql = array_map(
			static fn( $m ) => wp_date( 'Y-m-d H:i:00', pf_prize_timeline_ts( $timeline, $m ), $tz ),
			$p['minutes']
		);
		// Shuffle which existing row gets which minute so a row's id says
		// nothing about when it goes live.
		shuffle( $dates_sql );
		foreach ( $p['move'] as $row ) {
			pf_prize_move_slot( (int) $row->id, (int) $row->prize_post_id, (string) array_pop( $dates_sql ) );
		}
		foreach ( $dates_sql as $date ) {
			if ( pf_prize_create_slot( $competition_id, (string) $type, $p['name'], $date ) <= 0 ) {
				$failed++;
			}
		}
		foreach ( $p['trim'] as $row ) {
			pf_prize_delete_slot( (int) $row->id, (int) $row->prize_post_id );
		}
	}
	$report['failed'] = $failed;
	if ( $failed > 0 ) {
		$report['ok']    = false;
		$report['error'] = "{$failed} slot(s) failed to insert — see DB error log.";
	}

	if ( class_exists( 'BPM_Audit_Logger' ) ) {
		BPM_Audit_Logger::log(
			$competition_id,
			$wipe ? 'pf_prize_schedule_rebuilt' : 'pf_prize_schedule_rebalanced',
			sprintf( 'Prize schedule %s (%s).', $wipe ? 'wiped + rebuilt' : 'rebalanced', $trigger ),
			$failed > 0 ? 'error' : 'info',
			[ 'tiers' => $report['tiers'], 'from' => $report['from'], 'to' => $report['to'], 'user_id' => get_current_user_id() ]
		);
	}
	return $report;
}

// ─── Daily rollover ─────────────────────────────────────────────────────────
// 06:30 site time, before the 07:00 window opens: re-spreads yesterday's unwon
// slots (and the future ones, keeping the spacing even) across what's left.

add_action( 'init', function () {
	if ( ! wp_next_scheduled( PF_PRIZE_ROLLOVER_HOOK ) ) {
		$first = new DateTimeImmutable( 'tomorrow 06:30', wp_timezone() );
		wp_schedule_event( $first->getTimestamp(), 'daily', PF_PRIZE_ROLLOVER_HOOK );
	}
} );

add_action( PF_PRIZE_ROLLOVER_HOOK, function () {
	if ( ! (bool) apply_filters( 'pf_prize_rollover_enabled', true ) ) {
		return;
	}
	$cid   = function_exists( 'pf_resolve_competition_id' ) ? pf_resolve_competition_id() : 0;
	$dates = $cid > 0 ? pf_prize_competition_dates( $cid ) : null;
	if ( ! $dates ) {
		return;
	}
	$now = current_time( 'mysql' );
	if ( $now < $dates['start'] || $now > $dates['end'] ) {
		return; // only roll over while the campaign is running
	}
	$report = pf_prize_schedule_build( [ 'competition_id' => $cid, 'trigger' => 'cron_rollover' ] );
	if ( empty( $report['ok'] ) ) {
		error_log( 'PF prize rollover failed: ' . (string) ( $report['error'] ?? 'unknown' ) );
	}
} );

// ─── Admin: Settings → PF Debug "Prize schedule" section ───────────────────

add_action( 'admin_post_pf_prize_schedule', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.' ) );
	}
	check_admin_referer( 'pf_prize_schedule' );

	$mode   = sanitize_key( (string) ( $_POST['pf_mode'] ?? 'preview' ) );
	$wipe   = ! empty( $_POST['pf_wipe'] );
	$report = pf_prize_schedule_build( [
		'dry_run' => $mode !== 'build',
		'wipe'    => $wipe,
		'trigger' => 'admin',
	] );
	set_transient( 'pf_prize_schedule_report_' . get_current_user_id(), $report, 10 * MINUTE_IN_SECONDS );
	wp_safe_redirect( admin_url( 'options-general.php?page=pf-debug#pf-prize-schedule' ) );
	exit;
} );

function pf_prize_schedule_admin_render(): void {
	$cid     = function_exists( 'pf_resolve_competition_id' ) ? pf_resolve_competition_id() : 0;
	$dates   = $cid > 0 ? pf_prize_competition_dates( $cid ) : null;
	$hours   = pf_prize_daily_hours();
	$key     = 'pf_prize_schedule_report_' . get_current_user_id();
	$report  = get_transient( $key );
	delete_transient( $key );

	global $wpdb;
	$current = $cid > 0 ? (array) $wpdb->get_results( $wpdb->prepare(
		"SELECT prize_type, COUNT(*) n, SUM(claimed) claimed, MIN(date_available) first, MAX(date_available) last
		 FROM {$wpdb->prefix}bpm_prize_entries WHERE competition_id = %d GROUP BY prize_type",
		$cid
	) ) : [];
	?>
	<h2 id="pf-prize-schedule">Prize schedule</h2>
	<p>
		Competition <strong>#<?php echo (int) $cid; ?></strong>
		<?php if ( $dates ) : ?>
			— <?php echo esc_html( $dates['start'] ); ?> → <?php echo esc_html( $dates['end'] ); ?>
		<?php else : ?>
			— <em>no dates set (edit the competition in BPM)</em>
		<?php endif; ?>
		· slots go live <?php echo esc_html( $hours['start'] . '–' . $hours['end'] ); ?> daily
		· claimable for <?php echo (int) ( function_exists( 'pf_prize_window_minutes' ) ? pf_prize_window_minutes( $cid ) : 10 ); ?> min.
		Pool:
		<?php foreach ( pf_prize_pool() as $type => $cfg ) : ?>
			<code><?php echo esc_html( $type ); ?></code> × <?php echo (int) $cfg['count']; ?>
		<?php endforeach; ?>
	</p>
	<?php if ( $current ) : ?>
		<table class="widefat striped" style="max-width: 700px; margin-bottom: 1em;">
			<thead><tr><th>prize_type</th><th>Slots</th><th>Claimed</th><th>First</th><th>Last</th></tr></thead>
			<tbody>
			<?php foreach ( $current as $r ) : ?>
				<tr>
					<td><code><?php echo esc_html( (string) $r->prize_type ); ?></code></td>
					<td><?php echo (int) $r->n; ?></td>
					<td><?php echo (int) $r->claimed; ?></td>
					<td><?php echo esc_html( (string) $r->first ); ?></td>
					<td><?php echo esc_html( (string) $r->last ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p><strong>No prize slots exist for this competition — nobody can win.</strong></p>
	<?php endif; ?>

	<?php if ( is_array( $report ) ) : ?>
		<div class="notice <?php echo ! empty( $report['ok'] ) ? 'notice-info' : 'notice-error'; ?>" style="padding: 8px 12px;">
			<?php if ( empty( $report['ok'] ) ) : ?>
				<p><strong>Failed:</strong> <?php echo esc_html( (string) ( $report['error'] ?? 'unknown' ) ); ?></p>
			<?php else : ?>
				<p><strong><?php echo ! empty( $report['dry_run'] ) ? 'Preview (nothing written)' : 'Schedule written'; ?></strong>
					— <?php echo esc_html( $report['from'] . ' → ' . $report['to'] ); ?>
					<?php echo ! empty( $report['wipe'] ) ? ' · <strong>wipe</strong>' : ''; ?></p>
				<p>
				<?php foreach ( (array) $report['tiers'] as $type => $t ) : ?>
					<code><?php echo esc_html( $type ); ?></code>:
					<?php echo (int) $t['created']; ?> new, <?php echo (int) $t['moved']; ?> moved,
					<?php echo (int) $t['trimmed']; ?> removed, <?php echo (int) $t['claimed']; ?> claimed, <?php echo (int) $t['live']; ?> live now.<br>
				<?php endforeach; ?>
				</p>
				<details><summary>Per-day spread (<?php echo count( (array) $report['by_day'] ); ?> days)</summary>
					<table class="widefat striped" style="max-width: 500px;">
						<thead><tr><th>Day</th><?php foreach ( array_keys( pf_prize_pool() ) as $type ) : ?><th><?php echo esc_html( $type ); ?></th><?php endforeach; ?></tr></thead>
						<tbody>
						<?php foreach ( (array) $report['by_day'] as $day => $counts ) : ?>
							<tr><td><?php echo esc_html( $day ); ?></td>
							<?php foreach ( array_keys( pf_prize_pool() ) as $type ) : ?><td><?php echo (int) ( $counts[ $type ] ?? 0 ); ?></td><?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</details>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="pf_prize_schedule">
		<?php wp_nonce_field( 'pf_prize_schedule' ); ?>
		<p>
			<label><input type="checkbox" name="pf_wipe" value="1">
				<strong>Wipe first</strong> — delete <em>every</em> prize post + slot on this site (all competitions) before building.
				Refused on production if this competition has claimed prizes.</label>
		</p>
		<p>
			<button type="submit" class="button" name="pf_mode" value="preview">Preview</button>
			<button type="submit" class="button button-primary" name="pf_mode" value="build"
				onclick="return confirm('Write the prize schedule now?');">Build / rebalance schedule</button>
		</p>
		<p class="description">
			Without wipe this rebalances: claimed and currently-live slots are untouched; every other unclaimed slot is
			re-spread across the remaining campaign and each tier is topped up to its target. The same rebalance runs
			daily at 06:30 as the rollover for unwon slots.
		</p>
	</form>
	<?php
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * wp pf prizes [--dry-run] [--wipe] [--competition=<id>]
	 */
	WP_CLI::add_command( 'pf prizes', function ( $args, $assoc ) {
		$report = pf_prize_schedule_build( [
			'competition_id' => isset( $assoc['competition'] ) ? (int) $assoc['competition'] : ( function_exists( 'pf_resolve_competition_id' ) ? pf_resolve_competition_id() : 0 ),
			'dry_run'        => ! empty( $assoc['dry-run'] ),
			'wipe'           => ! empty( $assoc['wipe'] ),
			'trigger'        => 'wp-cli',
		] );
		WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		empty( $report['ok'] ) ? WP_CLI::error( (string) ( $report['error'] ?? 'failed' ) ) : WP_CLI::success( 'Done.' );
	} );
}
