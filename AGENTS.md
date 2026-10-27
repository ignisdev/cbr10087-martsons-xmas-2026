# AGENTS.md — Pepsi Xmas Drop 2026 Promotional Platform

**Project**: cbr10066-pepsi-xmas-2026 · **Client**: Carlsberg Group / Britvic
**Campaign**: Pepsi Xmas Drop 2026
**Stack**: WordPress 6.7 · PHP 8.1+ · MySQL · Three.js · GSAP · Vite · Gravity Forms

Working rules (plan-confirm-test loop, scope, CORE win-gating invariants) are in
`CLAUDE.md`. Game rules are in `XMAS_PROMO.md`. This file is the platform
reference.

---

## What This Project Is

Instant-win promotion built on the Britvic promo platform (the same age-gate →
game → claim → Stripe payout base used for Pepsi Football 2026, with a new
game). Players tap to drop 8 Pepsi Xmas icons onto a tree; landing the star
inside an active BPM time slot wins **£250 or £10 cash**, paid automatically
via Stripe.

---

## Repository Layout

```
/
├── CLAUDE.md / AGENTS.md / skills.md / XMAS_PROMO.md
├── docker/                          Local dev Docker stack (MySQL + WP) — .env never committed
├── docs/                            Stripe + payout-provider research, stakeholder brief
├── scripts/stripe-delete-account.sh Test-mode Stripe account cleanup
├── wp-config.php                    Reads env vars via getenv_docker() — never committed
└── wp-content/
    ├── plugins/
    │   ├── britvic-promotion-manager/   BPM — prize schedule, slots, play limit, access control, audit log
    │   └── gravityforms/                Age-gate + winner-claim forms
    └── themes/
        ├── britvic-promo-base/          Shared parent theme for Britvic promos
        └── pepsi-xmas-drop/             Active campaign theme
            ├── functions.php            Loads includes/lib/*
            ├── includes/lib/
            │   ├── drop-engine.php      PURE game rules + pf_decide_drop_outcome() + engine self-test
            │   ├── api.php              REST endpoints, sessions, BPM slot reserve/release/claim, tokens
            │   ├── stripe-payout.php    Stripe v2 Global Payouts, prize tiers (pf_prize_amount_map)
            │   ├── flow-guard.php       Funnel gating (steps, tree_started, outcomes)
            │   ├── scores.php           Completion-token → claim-form score/prize fields
            │   ├── rest-security.php    Same-origin + X-PF-Nonce + per-IP rate limit
            │   ├── daily-limit.php      BPM_Play_Limit shim
            │   ├── identity-prefill.php Age-gate name/email → claim form prefill
            │   ├── methods.php          WP setup, age-gate routing, PF Debug settings page
            │   ├── page-context.php     pf_page_context() asset resolver for templates
            │   ├── assets.php           Enqueue bundle + localise PepsiXmasConfig
            │   └── wp-hardening.php     Hardening; allow-lists pepsi-xmas/v1
            ├── page-*.php               age-gate, how-to-play, game, congratulations, non-winner,
            │                            thanks, daily-limit, prize-paid/processing, payout-failed,
            │                            stripe-return/refresh/verify
            └── src/
                ├── main.ts              Bootstrap + game loop (spawn → tap → drop → resolve)
                ├── game-engine/config.ts Config types + deterministic slider motion
                ├── renderer/            scene (ortho camera), tree, pieces, overlay (HUD)
                ├── ui/                  age-gate, transitions, exit-trap, loader, date-mask
                ├── audio/sounds.ts      Web Audio SFX (cheer/groan now; ambience/drop pending)
                └── scss/                Tokens, components, pages
```

---

## Prize Types

| `prize_type` (BPM slot) | Payout | Flow |
|---|---|---|
| `cash_250` | £250 via Stripe | GF claim → Stripe v2 recipient + onboarding → OutboundPayment |
| `cash_10` | £10 via Stripe | same |
| `cash` (legacy) | `STRIPE_PRIZE_AMOUNT_PENCE` | same |
| anything else | merch (manual) | GF claim → thanks page, admin export |

Tier → pence mapping: `pf_prize_amount_map()` (filter `pf_prize_amount_map`).
Test for cash with `pf_is_cash_prize_type()`.

---

## Data Flow — Full Winner Journey

```
/session/start           → session token + game config (pieces, levels) — daily limit enforced
/drop/attempt ×1..9      → server judges each drop (pf_decide_drop_outcome)
                            piece 1 sets tree_started (refresh → age-gate)
                            piece 9 (star) reserves a BPM slot → winner / release
/session/complete        → single-use completion token (score = pieces placed, prize fields)
/congratulations/?token= → GF claim form (identity prefilled)
  gform_pre_submission   → consume token, write verified score + prize fields
  gform_after_submission → cash: Stripe v2 recipient + account link → redirect to Stripe
                           merch: mark claimed → /thanks/
Stripe onboarding        → /stripe-return/ (or v2.core.account_link.returned webhook)
                           → pf_v2_execute_payout() → OutboundPayment for the tier amount
                           → /prize-processing/ polls /payout-status → /prize-paid/
```

---

## REST Endpoints (`/wp-json/pepsi-xmas/v1`)

| Method | Route | Gate |
|---|---|---|
| POST | `/session/start` | same-origin + nonce + per-IP rate limit |
| POST | `/drop/attempt` | same-origin + nonce; in-order, ≥350 ms apart |
| POST | `/session/complete` | same-origin + nonce |
| GET | `/debug/dropflow-test` | admins only |
| GET | `/debug/stripe-v2-test`, `/debug/stripe-v2-backfill-bank-identity` | admins only |
| GET | `/payout-status?pf_claim=` | public (opaque claim token) |
| POST | `/stripe/webhook` | Stripe signature |

---

## Environment Variables / Constants

Stored in `docker/.env` (local) and server environment (production); defined in
`wp-config.php`.

| Constant | Purpose |
|---|---|
| `STRIPE_SECRET_KEY` | Platform secret key |
| `STRIPE_PAYOUTS_KEY` | Optional separate key for v2 payouts |
| `STRIPE_WEBHOOK_SECRET` / `STRIPE_WEBHOOK_SECRET_V2` | v1 endpoint / v2 Event Destination signing secrets |
| `STRIPE_FINANCIAL_ACCOUNT_ID` | Treasury financial account funding OutboundPayments |
| `STRIPE_API_VERSION` | Pinned API version |
| `STRIPE_PAYOUT_METHOD` | `instant` or `standard` (v1 path) |
| `STRIPE_PAYOUT_DESCRIPTOR` | Bank statement descriptor |
| `STRIPE_PRIZE_AMOUNT_PENCE` | Fallback amount for legacy plain `cash` slots only |
| `PF_ALT_HOST` | Optional alt host → `alt` prize route |
| `PF_CLAIM_FORM_ID` | Static fallback for the claim form id |

---

## Gravity Forms

- **Age gate** — id from BPM Brand Config (`age_gate`); final submit marks the
  `age-gate` step, captures name/email, redirects to `/how-to-play/`.
- **Winner claim** — id from BPM Brand Config (`winner_claim`) with
  `PF_CLAIM_FORM_ID` fallback. Score field parameter name `score`; confirmation
  URL is overridden dynamically.

---

## Local Development

```bash
cd docker && docker compose up -d                      # WP + MySQL
cd wp-content/themes/pepsi-xmas-drop && npm ci && npm run build   # tsc + vite → dist/
cd wp-content/themes/pepsi-xmas-drop && composer install          # Stripe PHP SDK
```

Activate **Pepsi Xmas Drop** in Appearance → Themes and assign page templates
(Age Gate, How to play, Xmas Drop Game, Congratulations, Non-Winner, …).

---

## Known gaps

- BPM `includes/admin/brand-config.php` auto-discovers font options from
  `themes/pepsi-football/fonts` (hard-coded). That theme was removed, so the
  Brand Config font dropdown is empty until the plugin reads the active theme's
  `fonts/` directory instead. Plugin change — needs sign-off.
- Placeholder 3D pieces and no Xmas ambience/drop audio yet (see `XMAS_PROMO.md`).
