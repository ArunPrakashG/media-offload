# Plume — Feature & Plan Reference

> **Internal notes:**
>
> - Token tier keys are `personal`, `pro`, `agency`. Rename to match final marketing names before launch. Free tier token uses `free` (or equivalent) — issued during onboarding when the site registers with our server.
> - **Free requires a license key** obtained by registering during onboarding (site connects to our licensing server). This is different from a no-key anonymous install. `isActive()` returns `true` for free users; feature flags are just absent from the token.
> - Multisite: works per-site (each sub-site has independent settings + license via `get_option`/`home_url()`). No network-admin panel exists yet — each sub-site must be configured individually. Do not market as "full multisite support" without a network admin screen.
> - **DB writes after offload:** After a successful offload, Plume updates four things in the DB: (1) `_nimbus` postmeta — the storage key + per-size keys map; (2) `_wp_attached_file` — updated to the relative storage key (e.g. `2024/01/image.jpg`); (3) `_wp_attachment_metadata['file']` — also updated to the relative storage key; (4) `post_content` / `post_excerpt` in posts — local upload URL is search-replaced with the CDN URL. All four writes are **reversed** on restore via `revert_attachment_url_in_db()`. Never claim "no DB modification" — the plugin absolutely modifies the database.
> - **Provider-portable vs WP Offload Media:** The critical difference is _what_ is stored. Plume stores the relative storage key (`2024/01/image.jpg`), not a full provider URL. WP Offload Media stores the full S3/CDN URL (`https://bucket.s3.amazonaws.com/2024/01/image.jpg`). This means Plume DB content is provider-agnostic — switching CDN domain or provider requires only a settings change, no DB find-replace. WP Offload Media requires a full DB migration on every provider switch. Lead with "reversible, provider-portable writes" not "non-destructive" or "no DB modification."

---

---

## Why Plume vs WP Offload Media

WP Offload Media (Delicious Brains / WP Engine) is the market leader with 30,000+ active installs. Here is an honest feature-by-feature comparison to guide marketing positioning.

### Where WP Offload Media wins today

- Longer track record and community trust
- Google Cloud Storage and DigitalOcean Spaces support (Plume does not have these)
- Assets Pull addon (offload CSS/JS/fonts to CDN) — Plume does not have this
- WooCommerce and Easy Digital Downloads protected-file integrations
- "Create bucket from settings page" UX

### Where Plume wins — the points to lead with

**1. Cloudflare R2 is a first-class citizen**
WP Offload Media treats R2 as a generic S3-compatible endpoint — you need to manually figure out the right endpoint URL and settings. Plume has a dedicated R2 provider profile: just enter your Account ID, everything else (region `auto`, path-style, endpoint) is handled automatically. For most new users in 2024+ R2 is the obvious choice (zero egress fees), and Plume is the path of least resistance for it.

**2. Reversible, provider-portable DB rewrites**
WP Offload Media stores the full provider URL (e.g. `https://bucket.s3.amazonaws.com/2024/01/img.jpg`) in `_wp_attached_file`, permanently coupling your DB to a specific provider. Switching providers or CDN domains requires a full DB find-replace migration. Plume stores only the relative storage key (e.g. `2024/01/img.jpg`) — the CDN URL is reconstructed at render time from current settings. After offload, Plume writes four things to the DB (`_nimbus` postmeta, `_wp_attached_file`, `_wp_attachment_metadata[file]`, and replaces local URLs in `post_content`). Every write is fully reversed on restore — switch providers or revert to local with a settings change, no DB migration required.

**3. Cache purge automation is built-in**
WP Offload Media offers no cache purge integration — you get stale CDN responses after offloads and bulk migrations and have to purge manually or write your own code. Plume includes automated purge for Cloudflare, LiteSpeed Cache, WP Fastest Cache, and any Nginx/proxy via configurable PURGE templates — triggered automatically after every offload, rewrite, or bulk migration.

**4. Intelligent background jobs with pauseable state**
WP Offload Media runs bulk migration as a synchronous or simple background process. Plume has a full persistent job engine: pause mid-migration, see progress in real time, auto-retry failures, and resume from exactly where it stopped — all without touching WP-CLI.

**5. Nightly off-hours batch processing**
WP Offload Media has no configurable off-hours scheduling. Plume can be set to run heavy offload work at 3 AM with up to 8 parallel workers (Agency), avoiding peak-traffic performance hits.

**6. Page-builder + gallery integration URL rewriting**
WP Offload Media requires separate paid add-ons for Elementor, NextGEN, etc. Plume detects and handles Smart Slider 3, Elementor, Slider Revolution, and NextGEN Gallery URL rewriting out of the box on paid plans — database-level migration for legacy content.

**7. Transparent storage cost analytics**
Built-in side-by-side S3 vs R2 cost comparison based on the user's actual library size. WP Offload Media shows no cost analytics.

**8. Developer-friendly extension architecture**
Clean hook-based extension bus (`nimbus/extensions/register`), a published event system (30+ action hooks), full WP-CLI support, and a REST API with both read and write endpoints. WP Offload Media exposes some hooks but has no formal REST API.

### What we need to build to close the gap

- Google Cloud Storage and/or DigitalOcean Spaces provider
- WooCommerce purchases / private file delivery integration
- "Create bucket" UX shortcut (low effort, noticeable for new users)
- Network-admin panel for WordPress Multisite (currently per-site only)

---

## Plans at a Glance

| Feature                                                                                        | Free | Personal | Pro | Agency |
| ---------------------------------------------------------------------------------------------- | :--: | :------: | :-: | :----: |
| **Cloud storage providers** (S3, R2, S3-compatible)                                            |  ✅  |    ✅    | ✅  |   ✅   |
| **Auto-offload on upload**                                                                     |  ✅  |    ✅    | ✅  |   ✅   |
| **All image-size variants offloaded**                                                          |  ✅  |    ✅    | ✅  |   ✅   |
| **Reversible DB rewrites** (relative storage key — not a full URL; fully reversed on restore)  |  ✅  |    ✅    | ✅  |   ✅   |
| **Custom CDN / delivery domain**                                                               |  ✅  |    ✅    | ✅  |   ✅   |
| **Remote file deletion**                                                                       |  ✅  |    ✅    | ✅  |   ✅   |
| **Local copy removal after offload**                                                           |  ✅  |    ✅    | ✅  |   ✅   |
| **Path-prefix per bucket**                                                                     |  ✅  |    ✅    | ✅  |   ✅   |
| **Path-style endpoint** (required for R2)                                                      |  ✅  |    ✅    | ✅  |   ✅   |
| **Cloudflare R2 — dedicated provider profile**                                                 |  ✅  |    ✅    | ✅  |   ✅   |
| **Pending-offload queue + drain via cron**                                                     |  ✅  |    ✅    | ✅  |   ✅   |
| **Media library status column, filters, row actions**                                          |  ✅  |    ✅    | ✅  |   ✅   |
| **Live connection test with diagnostic hints**                                                 |  ✅  |    ✅    | ✅  |   ✅   |
| **Structured log viewer** (up to 2,000 entries)                                                |  ✅  |    ✅    | ✅  |   ✅   |
| **Basic storage statistics** (totals, %, 7-day chart)                                          |  ✅  |    ✅    | ✅  |   ✅   |
| **Bulk migration jobs** (offload all / retry / restore)                                        |  ❌  |    ✅    | ✅  |   ✅   |
| **Bulk job batch size per tick**                                                               |  —   |    25    | 75  |  100   |
| **Pauseable / resumeable bulk jobs**                                                           |  ❌  |    ✅    | ✅  |   ✅   |
| **Nightly off-hours batch processing**                                                         |  ❌  |    ✅    | ✅  |   ✅   |
| **Nightly parallel workers**                                                                   |  —   |    2     |  5  |   8    |
| **Selective offload** (MIME type + min file size)                                              |  ❌  |    ✅    | ✅  |   ✅   |
| **Path-pattern allow/deny filters**                                                            |  ❌  |    ✅    | ✅  |   ✅   |
| **Cache purge automation** (Cloudflare, LiteSpeed, WP Fastest Cache, Nginx)                    |  ❌  |    ✅    | ✅  |   ✅   |
| **Deep storage analytics** (MIME breakdown, monthly chart, cost comparison)                    |  ❌  |    ✅    | ✅  |   ✅   |
| **Third-party integration DB rewriting** (Elementor, Slider Revolution, Smart Slider, NextGEN) |  ❌  |    ✅    | ✅  |   ✅   |
| **WP-CLI commands**                                                                            |  ❌  |    ✅    | ✅  |   ✅   |
| **REST API — full read + job control**                                                         |  ❌  |    ✅    | ✅  |   ✅   |
| **Automatic plugin updates**                                                                   |  ❌  |    ✅    | ✅  |   ✅   |

---

## Free Plan

A free license is issued during onboarding — the site registers with Plume's licensing server and receives a free-tier token. No credit card required. The complete offload pipeline is available immediately after registration. The free tier is intentionally generous to earn trust and lower the barrier to switching from WP Offload Media.

### Storage & offload

- Amazon S3, Cloudflare R2 (dedicated profile — just enter Account ID), and any S3-compatible provider (MinIO, Backblaze B2, Wasabi, etc.)
- Automatic offload on upload: original file + every WordPress image size variant
- Remote file deletion when attachment is trashed
- Optional local copy removal after successful offload
- Configurable bucket path prefix (e.g. `wp-uploads/`)
- Storage class selection (e.g. S3 STANDARD, INTELLIGENT_TIERING) and public-read ACL

### URL delivery

- After a successful offload, Plume updates `_wp_attached_file`, `_wp_attachment_metadata['file']`, and `post_content` URLs to the CDN URL. The storage key stored is a relative path (e.g. `2024/01/image.jpg`), not a provider-specific URL — so switching CDN domains or providers requires only a settings change.
- All DB writes are reversed on restore via the built-in restore flow
- Custom CDN / delivery domain (e.g. `cdn.yourdomain.com`)
- `serve_from_storage` toggle — flip a switch to serve from local or remote without changing any data
- Path-style URL format option (required for R2 and most S3-compatible providers)

### Admin & visibility

- Media library status column showing offload state per attachment (offloaded / pending / failed / ignored / local)
- Status filter in media library
- Row-level actions: offload now, retry, restore to local
- Live connection test — checks real bucket access and returns specific diagnostic hints on failure
- Structured log viewer: filter by level, source, or keyword; up to 2,000 entries stored
- Basic statistics dashboard: total attachments, offloaded count, pending count, ignored count, offload percentage, 7-day daily trend chart

---

## Personal Plan

Everything in Free, plus tools for sites with an existing media library that needs migrating.

### Bulk migration

- **Offload all**: background job queues and offloads every attachment not yet in the bucket
- **Retry failed**: re-processes the failure list from the previous job
- **Restore local**: re-downloads all offloaded files back to disk (allows clean provider switch)
- Jobs show real-time progress: processed, succeeded, failed, skipped counts
- **Pause and resume** at any point — picks up exactly where it stopped
- Batch size: **25 attachments per tick** (2-second cron interval)

### Nightly off-hours batch

- Automatic sweep at a configurable time (default 03:00) to drain the pending queue
- **2 parallel workers**, configurable per-worker batch size
- Distributed claim TTL prevents double-processing across workers

### Selective offload

- Skip attachments below a minimum file size (configurable in KB)
- Restrict offload to specific MIME type patterns (e.g. `image/*, video/mp4`)
- Path-pattern allow/deny rules using fnmatch glob syntax

### Cache purge automation

- Triggered automatically after every offload batch, URL rewrite, and integration rewrite
- **Cloudflare** — per-URL and zone-wide purge via Cache API v4 (Zone ID + API token)
- **LiteSpeed Cache** — URL-level and full-cache purge via plugin hooks
- **WP Fastest Cache** — URL-level and full-cache clear
- **Proxy / Nginx** — fully configurable HTTP PURGE requests with template variables (`{url}`, `{path}`, `{host}`, `{scheme}`, `{url_encoded}`, `{path_encoded}`)
- Async: requests batched (5/tick by default) so purges don't block page loads

### Deep storage analytics

- MIME-type breakdown grouped by category (images, video, audio, documents, etc.)
- Monthly offload trend chart (last 6 months)
- Top-10 upload root directories by file count
- Estimated disk usage including all image size variants
- **Side-by-side storage cost comparison**: your actual library size × S3 Standard ($0.023/GB/mo) vs R2 ($0.015/GB/mo)

### Third-party plugin DB rewriting

One-time migration tool to update URLs embedded in page-builder and gallery plugin tables:

- **Elementor** — `_elementor_data` and `_elementor_page_settings` postmeta (JSON payload rewrite)
- **Slider Revolution** — all `revslider_*` database tables
- **Smart Slider 3** — Nextend storage tables and placeholder values
- **NextGEN Gallery** — all `ngg_*` database tables
- Integrations are auto-detected; each can be individually enabled or disabled

### WP-CLI

Full `nimbus` command namespace:

| Command                                             | What it does                                         |
| --------------------------------------------------- | ---------------------------------------------------- |
| `nimbus config [--key=value]`                       | Show or update settings (secrets masked)             |
| `nimbus process-pending [--hours=24] [--limit=500]` | Drain the pending queue                              |
| `nimbus deploy [IDs] [--limit=N]`                   | Offload specific attachments by ID                   |
| `nimbus download [IDs] [--limit=N]`                 | Restore remote files to local disk                   |
| `nimbus offload-single <id>`                        | Offload a single attachment                          |
| `nimbus sync [--limit=N]`                           | Queue attachments missing remote metadata            |
| `nimbus rewrite [--limit=500]`                      | Replace local URLs with remote in post_content       |
| `nimbus revert [--limit=500]`                       | Reverse rewrite — restore local URLs in post_content |
| `nimbus replace-urls --from=URL --to=URL`           | Arbitrary URL find/replace in post content           |

### REST API (full access)

All endpoints under `POST /nimbus/v1/`:

| Method | Endpoint                      | Purpose                           |
| ------ | ----------------------------- | --------------------------------- |
| GET    | `/status`                     | Full plugin state                 |
| GET    | `/statistics`                 | Deep analytics                    |
| GET    | `/integrations`               | Integration status                |
| GET    | `/jobs`                       | Current + last job state          |
| POST   | `/jobs/start`                 | Start a bulk job                  |
| POST   | `/jobs/pause`                 | Pause running job                 |
| POST   | `/jobs/resume`                | Resume paused job                 |
| POST   | `/jobs/retry-failed`          | Retry failures from last job      |
| POST   | `/offload/pending`            | Process pending queue             |
| POST   | `/offload/item/{id}`          | Offload a single attachment       |
| POST   | `/integrations/{key}/rewrite` | Run DB rewrite for an integration |

### Automatic updates

Plugin updates delivered through the standard WordPress update mechanism, tied to an active license.

---

## Pro Plan

Everything in Personal, with higher throughput for larger sites.

| Throughput limit      | Personal | Pro         |
| --------------------- | -------- | ----------- |
| Bulk job batch size   | 25/tick  | **75/tick** |
| Nightly batch workers | 2        | **5**       |

Pro is for sites with 10,000+ media files where Personal's batch rate creates unacceptably long migration windows.

---

## Agency Plan

Everything in Pro, with maximum throughput for high-volume or multi-property operations.

| Throughput limit      | Pro     | Agency       |
| --------------------- | ------- | ------------ |
| Bulk job batch size   | 75/tick | **100/tick** |
| Nightly batch workers | 5       | **8**        |

Agency is intended for development shops managing multiple client sites or a single site with a very large (100,000+) media library.

---

## Storage Providers

| Provider      | Key             | Notes                                                                          |
| ------------- | --------------- | ------------------------------------------------------------------------------ |
| Amazon S3     | `s3`            | Full IAM; STS session token support for temporary credentials                  |
| Cloudflare R2 | `r2`            | Enter Account ID — endpoint, region (`auto`), path-style all set automatically |
| S3-compatible | `s3_compatible` | MinIO, Backblaze B2, Wasabi, Tigris, etc. — custom endpoint required           |
| Local debug   | `local`         | Stores on local disk; for development and testing only                         |

---

## Cache Purge Integrations (Personal+)

| Cache system     | Trigger mechanism                                          | Scope                 |
| ---------------- | ---------------------------------------------------------- | --------------------- |
| Cloudflare       | Cache API v4 `/zones/{id}/purge_cache`                     | Per-URL and zone-wide |
| LiteSpeed Cache  | `litespeed_purge_url` / `litespeed_purge_all` action hooks | Per-URL and full      |
| WP Fastest Cache | `wpfc_clear_all_cache` + instance methods                  | Per-URL and full      |
| Proxy / Nginx    | Configurable HTTP PURGE with URL template variables        | Per-URL and full      |

Purge requests fire automatically on three events:

1. Remote file deletion completed
2. URL rewrite (serve-from-storage toggle changed)
3. Integration DB rewrite completed

---

## Feature Flag Reference

The signed license token carries a `features` array. These are the flags checked in the codebase:

| Flag                | What it unlocks                                    |
| ------------------- | -------------------------------------------------- |
| `bulk_migration`    | Bulk job manager + all job control REST endpoints  |
| `restore_local`     | Restore-local job type within bulk migration       |
| `nightly_batch`     | Off-hours scheduled batch processing               |
| `selective_offload` | MIME type filter + minimum file-size offload rules |
| `cache_purge`       | All cache purge integrations                       |
| `storage_analytics` | Deep stats, monthly chart, cost comparison         |
| `wpcli`             | WP-CLI `nimbus` command namespace                  |

Batch size limits and worker counts are derived from the `tier` claim (`personal` / `pro` / `agency`), not from feature flags.
