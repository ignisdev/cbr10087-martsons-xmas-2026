# Pepsi Xmas Drop 2026 Promotional Platform

**Project**: cbr10066-pepsi-xmas-2026 · **Client**: Carlsberg Group / Britvic
**Stack**: WordPress 6.7 · PHP 8.1+ · MySQL · Three.js · GSAP · Vite · Gravity Forms

> Game spec in `XMAS_PROMO.md`. Platform reference in `AGENTS.md` (layout, REST,
> schema, env vars, Stripe, GF). Implementation patterns in `skills.md`.

---

## Task Workflow (MANDATORY — applies to every change)

Before writing or editing any code, follow this loop:

1. **Plan first.** State, in plain English:
   - What's currently happening (one-sentence diagnosis if it's a fix).
   - What you're going to change, file-by-file (paths + the substance of the
     edit — not the diff, but enough that the user can picture it).
   - Why this approach over the obvious alternatives.
   - Anything you're deliberately NOT changing and why.
2. **Wait for confirmation.** Do not start editing files until the user has
   said the plan looks right. If the user asks for revisions, restate the
   revised plan and wait again. Plans are cheap; reverts are not.
3. **Testing plan.** As part of the same message, lay out **how the user can
   verify** the change worked — exact pages, buttons, log lines, network
   entries, expected vs failure outcomes — plus a "how I'll verify on my side"
   line (lint / typecheck / self-test / harness screenshot, as relevant).
4. **Execute, then re-state what was actually done.** Run the verifier you
   committed to and report outcomes faithfully — note anything that diverged.

### When this can be skipped

- Single-line typo / comment fix where the diff is obvious and risk-free.
- The user explicitly says "just do it" / "no plan needed" / "go ahead".
- A scoped continuation of a plan already confirmed in the same session.

Everything else — including anything that touches the win-gating logic,
flow-guard, Stripe payout flow, REST permissions, or the build system —
requires the plan-confirm-test loop.

---

## What This Project Is

WordPress instant-win promotion. Players buy a Pepsi, pass the age gate, and
play **The Xmas Drop**: a four-level tap-to-drop game that builds the 9-piece
Pepsi Xmas Tree, star last. Completing the tree inside an active BPM time slot
wins a cash prize (20 × £250, 500 × £10) paid via Stripe. No user accounts, no
cart — a promotional mechanics engine with anti-cheat, prize scheduling, form
capture and automated cash disbursement.

Flow: **age-gate → how-to-play → game → congratulations (winner) → Stripe →
prize-paid**, or **game → non-winner**.

---

## Change Scope Rule

- Default scope: `wp-content/themes/pepsi-xmas-drop` only. Don't touch the BPM
  plugin (`britvic-promotion-manager`), Gravity Forms, or `britvic-promo-base`
  unless explicitly asked.
- Single theme — there is no brand-variant twin to mirror.

---

## Key Design Principles

1. **Server is authoritative** — landing, win/loss, prize type and amount are
   decided server-side. Client animation is cosmetic only.
2. **Single-use tokens** — completion tokens consumed exactly once in `gform_pre_submission`.
3. **No payment details stored** — all bank data goes direct to Stripe's domain.
4. **Atomic claiming** — `claimed=1` only set after all validation passes; `WHERE claimed=0` prevents races.
5. **Audit trail** — every prize event written to `wp_bpm_audit_log`.

---

## Win-Gating Invariants (CORE — never regress)

All game rules live in `includes/lib/drop-engine.php` (pure PHP, no WP). The
endpoint is `pf_api_drop_attempt()` in `includes/lib/api.php`.

**The single decision path:** `pf_decide_drop_outcome($piece, $drop_x, $slot_available)`.
Nothing else may decide a landing or a win.

- 9 pieces, fixed order, stacked 3-3-2-1 to match the key visual: L1 cracker /
  present / bauble, L2 mouth / heart / snowman, L3 snow globe + gift, L4 the
  star. Speed rises and tolerance shrinks per level. The baubles are uneven
  and overlap, so each anchor is placed individually — there is no grid. The four light strands are client-side decoration only (`DECOR` in
  `src/renderer/baubles.ts`): never dropped, never sent to the server.
- A piece seats when `|drop_x − anchor_x| ≤ tolerance`; otherwise it falls and
  the run ends.
- **The star (piece 9) is the killer piece and the only drop that reserves a
  BPM slot.** Pieces 1–8 are judged honestly and never touch the prize pool.

### Scenario 1 — WIN-ASSIST

Slot reserved + star within `tolerance × PF_KILLER_ASSIST_MULT` (2.5) → star
seats, outcome `winner`. The scheduled slot pool is the real limiter — slot
reservation is atomic. A star outside the assist band falls even with a slot,
and the slot is **released immediately**.

### Scenario 2 — WIN-BLOCK

No slot reserved (empty window, access-control restriction, or winner-dedupe
match) → the star **always falls**, however well it was dropped. Anti-tell
rules that must never be broken:
1. **No sideways shift** — the star touches down at the player's `drop_x`
   (`land_x === drop_x`). Never nudge it off-anchor.
2. **Shared fail animation** — `pf_drop_fail_style()` picks from the same
   `PF_DROP_FAIL_STYLES` pool with the same side rule as an honest miss.
3. **Shared commentary** — the `fell` pool, same as any honest miss. No "SO
   CLOSE!" / near-miss messaging keyed to `near_miss`.

### How to test (both required before shipping changes here)

- **Self-test harness** — `GET /wp-json/pepsi-xmas/v1/debug/dropflow-test`
  (admins only). Must report `all_pass: true`. The pure part also runs from the
  CLI: `php -r 'require "includes/lib/drop-engine.php"; var_dump(pf_drop_engine_self_test()["all_pass"]);'`
- **Live DEBUG panel** on `/game/` (tick Settings → PF Debug first) — PERFECT
  RUN / STAR IN ASSIST BAND / WIDE STAR / RUN SELF-TEST. Pair with a live vs
  empty BPM minute.
- Level speeds/tolerances are served to the client from `pf_drop_public_config()`
  at `/session/start` — never hard-code them in TypeScript.

---

## Prize Tiers

BPM has no amount column, so the tier is the slot's `prize_type`:
`cash_250` → £250, `cash_10` → £10 (`pf_prize_amount_map()` in
`stripe-payout.php`, filterable). Plain `cash` falls back to
`STRIPE_PRIZE_AMOUNT_PENCE`. An unmapped `cash_*` pays **nothing** and logs
`stripe_v2_no_amount`. Use `pf_is_cash_prize_type()` — never compare to `'cash'`.

---

## Debug Panel — Security Rules

Single source of truth: **`pf_is_debug_panel_visible()`** in `includes/lib/methods.php`.

Visibility (first true wins):
1. `pf_debug_panel_enabled` wp-option (Settings → PF Debug) — the canonical toggle.
2. Logged-in admin (`current_user_can('manage_options')`) — always sees panel.

Exception: the **on-page bar on `/game/`** shows only when the Settings → PF
Debug option is ticked — admins do NOT see it by default, so they get the real
game UI. Tick the option to use PERFECT RUN / STAR IN ASSIST BAND / WIDE STAR /
RUN SELF-TEST.

**Never re-introduce:** `?pf_debug=1` URL flag, `pf_debug` cookie, or
`defined('WP_DEBUG') && WP_DEBUG` inline anywhere. Always call the helper.

**Production logging rule:** new `error_log()` calls that fire per-request must
be gated by `pf_is_debug_panel_visible()` or removed before deploy. Significant
flow/security events stay as audit trail.

---

## Debug & Verification Standard

For admin UI/config fixes:
1. Reproduce and identify the failing layer (browser validation / JS submit / AJAX / PHP / DB).
2. Check browser console + network before assuming backend failure.
3. Patch root cause + related edge cases in one pass.
4. Verify end-to-end: no browser blockers → AJAX success → persists after reload → reflected on frontend.
5. Report results. Don't ask for user retesting until this pass is done.

Never render invalid default values on number inputs (e.g. `value="0"` with `min="1"`).

---

## Stripe v2 Payout Recovery (if payouts stop firing)

`return_url` is the completion redirect (→ `/stripe-return/`); `refresh_url` is
only called on error conditions. The `v2.core.account_link.returned` webhook
fires in parallel as a fallback payout trigger.

**Checklist if payouts stop:**
1. Stripe Dashboard → Event Destinations — the v2 destination exists and is
   subscribed to `v2.core.account_link.returned` (separate from the v1 endpoint).
2. `wp-config.php` — both `STRIPE_WEBHOOK_SECRET` (v1) and
   `STRIPE_WEBHOOK_SECRET_V2` (v2) are set.
3. PHP log — `PF FLOW stripe_v2 webhook: event_id=... type=v2.core.account_link.returned`.
   Missing = webhook not firing or wrong secret. `stripe_v2_no_amount` in the
   audit log = a slot's `prize_type` isn't in `pf_prize_amount_map()`.

**Recovering stuck winners (stripe_payout_status = 'failed', no OBP ID):**
```sql
SELECT id, prize_type, stripe_account_id_v2, stripe_payout_status, stripe_outbound_payment_id
FROM wp_bpm_prize_entries
WHERE prize_type LIKE 'cash%' AND stripe_payout_status IN ('pending','failed')
AND (stripe_outbound_payment_id IS NULL OR stripe_outbound_payment_id = '');

UPDATE wp_bpm_prize_entries SET stripe_payout_status = 'pending'
WHERE prize_type LIKE 'cash%' AND stripe_payout_status = 'failed'
AND (stripe_outbound_payment_id IS NULL OR stripe_outbound_payment_id = '');
```
Then replay failed `v2.core.account_link.returned` events from Stripe Dashboard
→ Event Destinations → failed deliveries → Resend.

**Stuck in `processing` with no OBP ID** = the payout lock was taken but the
request died before the OutboundPayment was created (nothing retries these).
Check Stripe has no OBP for the recipient first, then reset to `pending` and
replay as above:
```sql
UPDATE wp_bpm_prize_entries SET stripe_payout_status = 'pending'
WHERE prize_type LIKE 'cash%' AND stripe_payout_status = 'processing'
AND (stripe_outbound_payment_id IS NULL OR stripe_outbound_payment_id = '');
```

**`already_done` redirects** read the entry's real status
(`pf_v2_settled_entry_redirect_url()`): `cop_blocked` → payout-failed with
TRY AGAIN (an `account_update` link so the winner can change bank details),
`payout_blocked` → review, `failed/canceled/returned` → support. Only an
in-flight or posted payout shows `/prize-paid/`.

**`account_rejected`** = Stripe set the recipient's `bank_accounts.local`
capability to `rejected`. Terminal (outside the pending/failed lock set), never
paid, winner sent to support; audit action `stripe_v2_account_rejected`.

**Payment fields for support queries** — `receipt_url`, `expected_arrival_date`,
`trace_id` (+ `status_details` on failed/returned) are logged in the metadata of
the `stripe_v2_outbound_payment_created` and `stripe_v2_webhook_*` audit rows.
