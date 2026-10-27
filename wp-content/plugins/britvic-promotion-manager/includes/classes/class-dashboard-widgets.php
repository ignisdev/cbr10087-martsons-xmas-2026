<?php
/**
 * BPM Dashboard Widgets
 *
 * Registers wp-admin Dashboard widgets that surface campaign telemetry:
 *
 *   1. "Entries by Route" — Age-gate (entry) form submissions bucketed by
 *      sub-domain route: main vs alt. The bucket is derived from Gravity
 *      Forms' built-in `source_url` column (always captured), NOT from the
 *      hidden `route` field — so the count is reliable even for entries
 *      submitted before the route field existed. Includes a one-click,
 *      re-runnable backfill that writes the derived route back into the GF
 *      `route` field for every existing entry.
 *
 *   2. "Prizes Redeemed" — mirrors the BPM Prizes admin page headline stats
 *      (Total / Claimed / Available / Rate) for the active competition, plus
 *      a dynamic per-prize-name breakdown. All figures are computed live from
 *      the DB; nothing is hard-coded.
 *
 * Route convention (campaign-wide): the alt sub-domain label ends in `2`
 *   - pepsifootball.britvic.promo            → main
 *   - pepsifootball2.britvic.promo           → alt
 *   - pepsifootballmarstons.britvic.promo    → main
 *   - pepsifootballmarstons2.britvic.promo   → alt
 *
 * @package Britvic_Promotion_Manager
 */

defined( 'ABSPATH' ) || exit;

class BPM_Dashboard_Widgets {

	/** admin-post action slug for the route backfill. */
	const RECALC_ACTION = 'bpm_pf_recalc_route';

	/** transient key (suffixed with user id) holding the last backfill result. */
	const RECALC_RESULT = 'bpm_pf_recalc_route_result_';

	/**
	 * Wire up hooks. Called once from the plugin bootstrap.
	 */
	public static function init(): void {
		add_action( 'wp_dashboard_setup', [ __CLASS__, 'register_widgets' ] );
		add_action( 'admin_post_' . self::RECALC_ACTION, [ __CLASS__, 'handle_recalc_route' ] );

		// Going-forward: stamp the route field from the entry's own source_url
		// after every age-gate submission, so new and backfilled entries share
		// the identical (source_url-derived) logic regardless of PF_ALT_HOST.
		add_action( 'gform_after_submission', [ __CLASS__, 'stamp_route_on_submission' ], 20, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Route derivation
	 * ------------------------------------------------------------------- */

	/**
	 * Classify a source URL into a route bucket.
	 *
	 * @param string $url Full URL (Gravity Forms entry source_url).
	 * @return string 'alt' when the first host label ends in "2", else 'main'.
	 */
	public static function route_from_source_url( string $url ): string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( $host === '' ) {
			return 'main';
		}
		$first_label = strtok( $host, '.' ); // e.g. "pepsifootball2"
		return ( $first_label !== false && substr( $first_label, -1 ) === '2' ) ? 'alt' : 'main';
	}

	/**
	 * Human-readable labels for each route. Filterable so other promos /
	 * the Marstons theme can override (e.g. different nations).
	 *
	 * @return array{main:string,alt:string,overall:string}
	 */
	public static function route_labels(): array {
		return apply_filters( 'bpm_route_labels', [
			'main'    => __( 'England (main)', 'britvic-promo' ),
			'alt'     => __( 'Home Nation (alt)', 'britvic-promo' ),
			'overall' => __( 'Overall', 'britvic-promo' ),
		] );
	}

	/* ---------------------------------------------------------------------
	 * Age-gate form resolution
	 * ------------------------------------------------------------------- */

	/**
	 * Resolve the age-gate (entry) Gravity Form ID. Prefers the theme helper
	 * when present (single source of truth); otherwise replicates the BPM
	 * option lookup so the widget still works with any active theme.
	 */
	public static function age_gate_form_id(): int {
		if ( function_exists( 'pepsi_get_age_gate_form_id' ) ) {
			return (int) pepsi_get_age_gate_form_id();
		}

		$competition_id = function_exists( 'bpm_get_active_competition_id' ) ? (int) bpm_get_active_competition_id() : 0;

		global $wpdb;
		if ( $competition_id <= 0 ) {
			$comp_ids = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}bpm_competitions ORDER BY id DESC" );
			foreach ( (array) $comp_ids as $cid ) {
				$fs = get_option( "bpm_form_settings_{$cid}", [] );
				if ( (int) ( $fs['age_gate']['form_id'] ?? 0 ) > 0 ) {
					$competition_id = (int) $cid;
					break;
				}
			}
		}

		if ( $competition_id <= 0 ) {
			return 0;
		}

		$fs  = get_option( "bpm_form_settings_{$competition_id}", [] );
		$fid = (int) ( $fs['age_gate']['form_id'] ?? 0 );
		if ( $fid > 0 ) {
			return $fid;
		}

		$brand = get_option( "bpm_brand_{$competition_id}", [] );
		foreach ( [ 'age_gate', 'initial_entry', 'entry' ] as $key ) {
			$fid = (int) ( $brand['gf_ids'][ $key ] ?? 0 );
			if ( $fid > 0 ) {
				return $fid;
			}
		}

		return 0;
	}

	/**
	 * Find the GF field whose parameter name (inputName) is "route".
	 * Matched by parameter name — not a hard-coded ID — so it works across
	 * environments (staging vs production field numbering).
	 *
	 * @param array $form A Gravity Forms form array.
	 * @return int Field ID, or 0 when no route field is present.
	 */
	public static function route_field_id( array $form ): int {
		foreach ( (array) ( $form['fields'] ?? [] ) as $field ) {
			$param = strtolower( trim( (string) ( $field->inputName ?? '' ) ) );
			if ( $param === 'route' ) {
				return (int) ( $field->id ?? 0 );
			}
		}
		return 0;
	}

	/* ---------------------------------------------------------------------
	 * Stats queries
	 * ------------------------------------------------------------------- */

	/**
	 * Count active age-gate entries bucketed by route.
	 *
	 * @return array{form_id:int,main:int,alt:int,overall:int}
	 */
	public static function entry_counts(): array {
		$out = [ 'form_id' => 0, 'main' => 0, 'alt' => 0, 'overall' => 0 ];

		$form_id = self::age_gate_form_id();
		$out['form_id'] = $form_id;
		if ( $form_id <= 0 ) {
			return $out;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'gf_entry';
		// Guard: GF may be inactive / table missing.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return $out;
		}

		$urls = $wpdb->get_col( $wpdb->prepare(
			"SELECT source_url FROM {$table} WHERE form_id = %d AND status = %s",
			$form_id,
			'active'
		) );

		foreach ( (array) $urls as $url ) {
			$out['overall']++;
			$out[ self::route_from_source_url( (string) $url ) ]++;
		}

		return $out;
	}

	/**
	 * Resolve a prize post's display name using the same precedence as the
	 * BPM Prizes admin page (mapped distribution > mapped file > prize_name
	 * meta > prize meta).
	 */
	public static function resolve_prize_name( int $post_id ): string {
		$mapped_json = get_post_meta( $post_id, 'mapped_data', true );
		$mapped      = $mapped_json ? json_decode( $mapped_json, true ) : [];

		if ( ! empty( $mapped['prize_name_from_distribution'] ) ) {
			return (string) $mapped['prize_name_from_distribution'];
		}
		if ( ! empty( $mapped['prize_name_from_file'] ) ) {
			return (string) $mapped['prize_name_from_file'];
		}

		$prize_name = get_post_meta( $post_id, 'prize_name', true );
		if ( ! empty( $prize_name ) ) {
			return (string) $prize_name;
		}

		$prize = get_post_meta( $post_id, 'prize', true );
		if ( is_numeric( $prize ) ) {
			return 'Prize ' . $prize;
		}
		return $prize ? (string) $prize : __( 'Unknown Prize', 'britvic-promo' );
	}

	/**
	 * Compute prize redemption stats for the active competition. Mirrors the
	 * BPM Prizes page headline figures (claimed = post-meta `claimed` === 'true')
	 * and adds a per-prize-name breakdown whose sub-totals reconcile with the
	 * headline.
	 *
	 * @return array{competition_id:int,total:int,claimed:int,available:int,rate:float,breakdown:array<string,array{total:int,claimed:int}>}
	 */
	public static function prize_stats(): array {
		$competition_id = function_exists( 'bpm_get_active_competition_id' ) ? (int) bpm_get_active_competition_id() : 0;

		$out = [
			'competition_id' => $competition_id,
			'total'          => 0,
			'claimed'        => 0,
			'available'      => 0,
			'rate'           => 0.0,
			'breakdown'      => [],
		];

		if ( $competition_id <= 0 ) {
			return $out;
		}

		$ids = get_posts( [
			'post_type'      => 'prize',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [
				[ 'key' => 'competition_id', 'value' => $competition_id, 'compare' => '=' ],
			],
		] );

		if ( empty( $ids ) ) {
			return $out;
		}

		// Prime the meta cache once so the per-prize loop hits memory, not the DB.
		update_meta_cache( 'post', $ids );

		$total     = 0;
		$claimed   = 0;
		$breakdown = [];

		foreach ( $ids as $id ) {
			$total++;
			$is_claimed = ( get_post_meta( $id, 'claimed', true ) === 'true' );
			if ( $is_claimed ) {
				$claimed++;
			}

			$name = self::resolve_prize_name( (int) $id );
			if ( ! isset( $breakdown[ $name ] ) ) {
				$breakdown[ $name ] = [ 'total' => 0, 'claimed' => 0 ];
			}
			$breakdown[ $name ]['total']++;
			if ( $is_claimed ) {
				$breakdown[ $name ]['claimed']++;
			}
		}

		ksort( $breakdown );

		$out['total']     = $total;
		$out['claimed']   = $claimed;
		$out['available'] = $total - $claimed;
		$out['rate']      = $total > 0 ? ( $claimed / $total ) * 100 : 0.0;
		$out['breakdown'] = $breakdown;

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Widget registration + render
	 * ------------------------------------------------------------------- */

	public static function register_widgets(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'bpm_entries_by_route',
			__( 'Entries by Route', 'britvic-promo' ),
			[ __CLASS__, 'render_entries_widget' ]
		);

		wp_add_dashboard_widget(
			'bpm_prizes_redeemed',
			__( 'Prizes Redeemed', 'britvic-promo' ),
			[ __CLASS__, 'render_prizes_widget' ]
		);
	}

	public static function render_entries_widget(): void {
		$counts = self::entry_counts();
		$labels = self::route_labels();

		if ( $counts['form_id'] <= 0 ) {
			echo '<p>' . esc_html__( 'No age-gate form is configured for the active competition yet.', 'britvic-promo' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="border:none;"><tbody>';
		foreach ( [ 'main', 'alt' ] as $route ) {
			printf(
				'<tr><td>%s</td><td style="text-align:right;font-weight:600;">%s</td></tr>',
				esc_html( $labels[ $route ] ),
				esc_html( number_format_i18n( $counts[ $route ] ) )
			);
		}
		printf(
			'<tr><td style="border-top:1px solid #dcdcde;font-weight:600;">%s</td><td style="border-top:1px solid #dcdcde;text-align:right;font-weight:700;">%s</td></tr>',
			esc_html( $labels['overall'] ),
			esc_html( number_format_i18n( $counts['overall'] ) )
		);
		echo '</tbody></table>';

		printf(
			'<p style="color:#646970;margin:8px 0 0;font-size:11px;">%s</p>',
			esc_html( sprintf(
				/* translators: %d: Gravity Forms form ID. */
				__( 'Source: Gravity Forms entry source_url (form #%d).', 'britvic-promo' ),
				$counts['form_id']
			) )
		);

		self::render_recalc_controls();
	}

	/**
	 * The "Recalculate route" backfill button + last-run result notice.
	 */
	protected static function render_recalc_controls(): void {
		$result_key = self::RECALC_RESULT . get_current_user_id();
		$result     = get_transient( $result_key );
		if ( is_array( $result ) ) {
			delete_transient( $result_key );
			if ( empty( $result['field'] ) ) {
				echo '<div class="notice notice-warning inline" style="margin:8px 0;padding:6px 10px;"><p>'
					. esc_html__( 'No field with parameter name "route" was found on the age-gate form, so nothing was updated. Add a hidden field with parameter name "route" in the Gravity Forms editor.', 'britvic-promo' )
					. '</p></div>';
			} else {
				echo '<div class="notice notice-success inline" style="margin:8px 0;padding:6px 10px;"><p>'
					. esc_html( sprintf(
						/* translators: 1: updated count, 2: scanned count. */
						__( 'Route backfill complete: %1$d of %2$d entries updated.', 'britvic-promo' ),
						(int) $result['updated'],
						(int) $result['scanned']
					) )
					. '</p></div>';
			}
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px;">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::RECALC_ACTION ) . '" />';
		wp_nonce_field( self::RECALC_ACTION );
		echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Recalculate route from source URLs', 'britvic-promo' ) . '</button>';
		echo '</form>';
	}

	public static function render_prizes_widget(): void {
		$stats = self::prize_stats();

		if ( $stats['competition_id'] <= 0 ) {
			echo '<p>' . esc_html__( 'No active competition selected.', 'britvic-promo' ) . '</p>';
			return;
		}
		if ( $stats['total'] <= 0 ) {
			echo '<p>' . esc_html__( 'No prizes have been generated for the active competition yet.', 'britvic-promo' ) . '</p>';
			return;
		}

		$rows = [
			[ __( 'Total:', 'britvic-promo' ), number_format_i18n( $stats['total'] ), '' ],
			[ __( 'Claimed:', 'britvic-promo' ), number_format_i18n( $stats['claimed'] ), '' ],
			[ __( 'Available:', 'britvic-promo' ), number_format_i18n( $stats['available'] ), '' ],
			[ __( 'Rate:', 'britvic-promo' ), number_format_i18n( $stats['rate'], 1 ) . '%', '' ],
		];

		echo '<table class="widefat striped" style="border:none;"><tbody>';
		foreach ( $rows as $row ) {
			printf(
				'<tr><td>%s</td><td style="text-align:right;font-weight:600;">%s</td></tr>',
				esc_html( $row[0] ),
				esc_html( $row[1] )
			);
		}
		echo '</tbody></table>';

		echo '<h4 style="margin:12px 0 4px;">' . esc_html__( 'Breakdown', 'britvic-promo' ) . '</h4>';
		echo '<table class="widefat striped" style="border:none;"><thead><tr>'
			. '<th>' . esc_html__( 'Prize', 'britvic-promo' ) . '</th>'
			. '<th style="text-align:right;">' . esc_html__( 'Claimed', 'britvic-promo' ) . '</th>'
			. '<th style="text-align:right;">' . esc_html__( 'Total', 'britvic-promo' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $stats['breakdown'] as $name => $counts ) {
			printf(
				'<tr><td>%s</td><td style="text-align:right;">%s</td><td style="text-align:right;">%s</td></tr>',
				esc_html( $name ),
				esc_html( number_format_i18n( $counts['claimed'] ) ),
				esc_html( number_format_i18n( $counts['total'] ) )
			);
		}
		echo '</tbody></table>';
	}

	/* ---------------------------------------------------------------------
	 * Route backfill + going-forward stamping
	 * ------------------------------------------------------------------- */

	/**
	 * admin-post handler: recompute the route field for every age-gate entry
	 * from its source_url. Idempotent — only writes when the value differs.
	 */
	public static function handle_recalc_route(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'britvic-promo' ) );
		}
		check_admin_referer( self::RECALC_ACTION );

		$scanned = 0;
		$updated = 0;
		$field   = 0;

		if ( class_exists( 'GFAPI' ) ) {
			$form_id = self::age_gate_form_id();
			$form    = $form_id > 0 ? GFAPI::get_form( $form_id ) : false;
			$field   = ( is_array( $form ) ) ? self::route_field_id( $form ) : 0;

			if ( $form_id > 0 && $field > 0 ) {
				$page_size = 200;
				$offset    = 0;
				do {
					$entries = GFAPI::get_entries(
						$form_id,
						[ 'status' => 'active' ],
						null,
						[ 'offset' => $offset, 'page_size' => $page_size ]
					);
					if ( is_wp_error( $entries ) || empty( $entries ) ) {
						break;
					}
					foreach ( $entries as $entry ) {
						$scanned++;
						$route   = self::route_from_source_url( (string) rgar( $entry, 'source_url' ) );
						$current = (string) rgar( $entry, (string) $field );
						if ( $current !== $route ) {
							GFAPI::update_entry_field( $entry['id'], $field, $route );
							$updated++;
						}
					}
					$offset += $page_size;
				} while ( count( $entries ) === $page_size );
			}
		}

		set_transient(
			self::RECALC_RESULT . get_current_user_id(),
			[ 'scanned' => $scanned, 'updated' => $updated, 'field' => $field ],
			60
		);

		wp_safe_redirect( admin_url( 'index.php' ) );
		exit;
	}

	/**
	 * gform_after_submission: stamp the route field from the entry's own
	 * source_url so newly created entries match the backfill logic exactly.
	 *
	 * @param array $entry The saved entry.
	 * @param array $form  The form.
	 */
	public static function stamp_route_on_submission( $entry, $form ): void {
		if ( ! is_array( $entry ) || ! is_array( $form ) ) {
			return;
		}
		if ( (int) ( $form['id'] ?? 0 ) !== self::age_gate_form_id() ) {
			return;
		}
		$field = self::route_field_id( $form );
		if ( $field <= 0 || ! class_exists( 'GFAPI' ) ) {
			return;
		}
		$route   = self::route_from_source_url( (string) rgar( $entry, 'source_url' ) );
		$current = (string) rgar( $entry, (string) $field );
		if ( $current !== $route ) {
			GFAPI::update_entry_field( $entry['id'], $field, $route );
		}
	}
}
