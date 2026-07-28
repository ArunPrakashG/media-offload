# Nimbus: Transition to Free & Open Source

Decisions locked in:
- Merge all `nimbus-pro` features into the single `nimbus` plugin. One free plugin, no tiers.
- No paying customers to wind down — skip billing/refund handling.
- Fully decommission `apps/backend` (licensing/checkout/webhooks/updates). No replacement service.
- Distribute via WordPress.org (primary) + GitHub (canonical source, issues).

This doc is the punch list. Work top to bottom; each phase is mergeable independently.

---

## Phase 1 — Merge pro into free, strip all licensing ✅ done ([PR #9](https://github.com/ArunPrakashG/media-offload/pull/9))

Goal: one plugin, zero license gates, all current pro features available to everyone.

**1a. Fold `nimbus-pro` code into `nimbus`**
- Move these classes into `apps/plugin/nimbus/src/` (adjust namespace, wire into `Bootstrap/Plugin.php` / `Container.php` directly instead of via the `Extension\Registry` handshake, since there's no longer a separate plugin to register):
  - `Jobs/BulkJobManager.php`, `Jobs/JobTable.php`
  - `Cache/PurgeManager.php` + `CloudflareCachePurgeIntegration.php`, `LiteSpeedCachePurgeIntegration.php`, `WpFastestCachePurgeIntegration.php`, `ProxyCachePurgeIntegration.php`
  - `Cli/Command.php` (merge into free plugin's existing WP-CLI `nimbus` command)
  - `Integration/IntegrationDiscovery.php`, `DatabaseIntegrationRewriter.php`, `SmartSliderAssetRewriter.php`
  - `Integration/WooCommerceIntegration.php` (replaces the free plugin's stub of the same name)
  - `Admin/NetworkAdminPage.php` (replaces the free plugin's stub)
  - the `selective_offload` filter logic from wherever it's registered in `ProPlugin.php`
- Delete `apps/plugin/nimbus-pro/` entirely once merged.
- Delete `src/Bootstrap/ProPlugin.php`'s role — its wiring logic moves inline into `Bootstrap/Plugin.php::boot()`.
- Delete `src/Extension/Registry.php` and `Extension/Contracts/ProExtension.php` — no more external extension registration needed (unless you want to keep this as a public extensibility API for third-party add-ons; if so, keep it but strip the pro-specific coupling).

**1b. Remove tier-based limits**
- `Jobs/BulkJobManager.php` batch-size cap (`personal=25/pro=75/agency=100/default=10`) — pick one sane cap (e.g. 100) for everyone, or make it a filterable constant/setting.
- `License/LicenseManager.php`'s `match($tier)` sizing in `Media/UploadHandler.php` (L248) — same treatment.

**1c. Delete licensing code and UI gates**
- Delete: `src/License/LicenseManager.php`, `src/License/LicenseClient.php`, and (from the merged-in pro code) `License/UpdateChecker.php`, `License/LicenseRefresher.php`.
- `src/Admin/PageRenderer.php`: remove `renderLicenseOverlay()`, the `$showOverlay` gate, `renderProStatusCard()`'s "Upgrade to Pro" card, and the License tab entirely.
- `src/Admin/SettingsUi.php`: remove `render_license_banner()`, `enqueue_notice_scripts()`'s dismiss-banner AJAX wiring.
- `src/Admin/ActionsController.php`: remove `handle_license_activate_free` and any other license admin-post handlers.
- `src/Onboarding/OnboardingManager.php`: remove the license-key onboarding step.
- `src/Rest/ApiController.php`: remove every `nimbus_pro_required` / `WP_Error` gate (bulk-job start/pause/resume/retry, cache-purge trigger) and the `hasFeature('storage_analytics')` gate on stats — open all endpoints unconditionally.
- `nimbus.php`: remove `NIMBUS_BACKEND_URL`, `NIMBUS_LICENSE_PUBLIC_KEY`, `NIMBUS_FILTER_PRO_STATUS`.
- Grep for any remaining `hasFeature(`, `isActive(`, `->tier()`, `nimbus_pro_status` references after the above and clean up stragglers.

**1d. Update `readme.txt`**
- Rewrite the `== External Services ==` section — after this phase the plugin should make **zero** calls to `api.usenimbus.dev`. If nothing else phones home, this section can likely be removed or reduced to just the storage-provider disclosure (S3/R2/etc., which is user-configured, not a Nimbus-operated service).
- Update the feature list to reflect the merged pro features now being standard.

**Verification:** fresh WP install, activate plugin, confirm no license wall/banner appears anywhere, confirm bulk offload/cache-purge/page-builder rewrite features work without any key.

---

## Phase 2 — Decommission paid infrastructure ✅ code done, ops action still required (see below)

**Done in code** (repo work, this session):
- Deleted `apps/backend` entirely, and `bruno/backend/` (its API test collections).
- `infra/docker-compose.yml`: removed the `nimbus-api`, `nimbus-db`, `nimbus-redis` services and `BACKEND_URL` wiring — only `nimbus-web` remains.
- `infra/deploy.sh`: simplified to only build/restart the web frontend.
- `infra/.env.example`: stripped all backend-only secrets (DB password, license signing keys, admin API secret, Razorpay keys/plan IDs).
- Root `README.md` / `packages/README.md`: dropped backend references.
- `bruno/environments/{Local,Production}.bru`: removed `backendUrl` and a hardcoded `adminToken` that was committed in plaintext (see ops action below).

**Still required — these are real-world actions outside this repo, not something committing code accomplishes:**
- Revoke/rotate the Razorpay API keys (`RazorpayKeyID/KeySecret/WebhookSecret`) and cancel the Razorpay account/webhook subscription once confirmed no other product depends on them.
- Revoke the `LICENSE_PRIVATE_KEY` Ed25519 signing key material — it no longer signs anything meaningful once `LicenseManager` is deleted (done in Phase 1).
- **Rotate the `adminToken` found hardcoded in `bruno/environments/{Local,Production}.bru` git history** — removing it from the working tree does not remove it from history; treat it as compromised.
- Cloudflare R2 bucket used for pro-zip distribution — no longer needed once WordPress.org hosts the zip; deprovision or repurpose.
- DNS/hosting for `api.usenimbus.dev` and the backend's DB/Redis hosting — take down now that the code is gone.

Since there are no existing paying customers, no billing wind-down or customer-refund emails are needed here.

---

## Phase 3 — Update `apps/web` marketing site ✅ done

- ~~`src/components/Pricing.astro`: delete entirely~~ — done, along with `create-subscription.ts`/`verify-payment.ts`.
- ~~`src/pages/{refund,terms}.astro`~~ — `refund.astro` now shows a short "Nimbus is free, nothing to refund" notice (route kept alive for old bookmarks); `terms.astro` rewritten around the GPL-2.0-or-later grant; `privacy.astro` rewritten to reflect that the plugin has no license server or telemetry.
- CTAs across `Hero`/`Cta`/`Navbar`/`Footer` now point at `/docs#installation` instead of `/#pricing`; the pricing nav entry and "cryptographically secured licensing" assurance are gone.
- `compare.astro` and `docs.astro` (not originally scoped, but found to be just as stale) were also rewritten — pricing tables and every "Personal & above"/"Agency" qualifier removed.
- `changelog.astro` gained a v0.2.0 entry documenting the free/OSS transition.
- `razorpay` npm dependency removed; `pnpm-lock.yaml` regenerated. Verified with a full `astro build`.

---

## Phase 4 — Open-source the repo

- Add a top-level `LICENSE` file: `GPL-2.0-or-later` (already declared in `nimbus.php`, `readme.txt`, `composer.json` — this is also required for WordPress.org distribution).
- Since `nimbus-pro` had no license header and was closed-source, and its code is being merged into the GPL-licensed `nimbus`, make sure all merged files get proper GPL headers/doc-blocks for consistency.
- Secrets audit before making the repo public — check for committed real credentials, not just `.env.example` placeholders:
  - `apps/backend` config/`.env` files, `bruno/environments/*` (Bruno environment files often contain real tokens/keys used during dev/testing)
  - `infra/dev` configs
  - any Razorpay test/live keys, DB connection strings, `ADMIN_API_SECRET`, `LICENSE_PRIVATE_KEY` committed anywhere in git history (not just working tree — run a history scan, e.g. `git log -p | grep`, or a secrets-scanning tool, since deleting a file doesn't remove it from history)
- If `apps/backend` and `apps/web`'s payment bits are being deleted per Phases 2–3, decide whether to keep them in the OSS repo's history or start the public GitHub repo from a clean history/squash to avoid ever exposing old secrets. Given secrets may already be baked into git history, prefer **starting the public repo fresh from the current tree** rather than pushing the existing history, unless a history scan comes back clean.
- Update root `README.md`: drop backend/checkout/billing sections, describe the monorepo as `apps/plugin/nimbus` (the product) + `apps/web` (marketing site) + `tests/e2e`.
- `docs/refactor-roadmap.md` and `docs/features.md`: either archive as historical context (rename to `docs/history/`) or rewrite — both currently describe a tiered-licensing model that no longer exists and would confuse OSS contributors reading current docs.
- Add standard OSS scaffolding: `CONTRIBUTING.md`, issue templates, a `SECURITY.md` (WordPress plugins are a common attack surface — worth a disclosure policy).

---

## Phase 5 — WordPress.org submission

- Check "Nimbus" for trademark/name collisions on WordPress.org (search the plugin directory and trademark databases — a fairly common name, may need a suffix like "Nimbus Media Offload" or similar; `readme.txt`'s existing plugin slug should be checked against this before submitting).
- WordPress.org plugin review requirements to double check post-Phase-1: no remote phone-home without disclosure, no obfuscated code, proper sanitization/escaping (the review is manual and strict about security basics — worth a self-review pass on `Admin/ActionsController.php` and `Rest/ApiController.php` for nonce/capability checks now that gates are being removed/rewired).
- Submit via the standard `svn` process once the above is clean; WordPress.org then handles update distribution, replacing the old `/v1/updates/check` mechanism entirely.

---

## Suggested sequencing

Phase 1 is the only one with real engineering risk (merging two codebases, removing gating logic without breaking the core offload path) — do it first, on a branch, with the verification step above before moving on. Phases 2–5 are largely mechanical/ops once Phase 1 is merged and tested.
