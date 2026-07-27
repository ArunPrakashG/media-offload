# Nimbus

> Your media, elevated.

Nimbus is a free, open-source WordPress plugin that offloads uploaded media to Amazon S3, Cloudflare R2, or any S3-compatible storage provider, with automatic URL rewriting, optional CDN delivery, and a clean admin UI designed for developers and site operators. There is no license key and no paid tier — every feature is available out of the box.

The plugin entry point is `nimbus.php`. Runtime and domain logic lives under `src/`, namespaced as `Nimbus\Plugin\*`.

Maintained by **Arun Prakash** — `contact@arunprakashg.com`

---

## Features

- Amazon S3, Cloudflare R2, and S3-compatible storage with optional path-style endpoints and custom domains
- Local adapter for development and offline environments
- Attachment metadata sync that preserves all WordPress-generated image sizes
- Optional removal of local files after a successful offload
- URL rewriting for attachment URLs, `srcset`, and raw content references
- WP-CLI commands: `nimbus deploy`, `nimbus rewrite`, `nimbus revert`, `nimbus config`
- Per-attachment ignore toggle in the Media Library
- Media Library status column, dropdown filter, and native bulk actions (offload / restore / retry)
- Configurable path filters: allow-list and deny-list with glob placeholders
- Smart Slider 3, Slider Revolution, Elementor, and NextGEN Gallery database URL rewrites
- First-run onboarding flow for bucket configuration
- Integrations tab driven by the `nimbus_integrations` filter — extensible without core edits
- Cache purge integrations for Cloudflare, LiteSpeed Cache, WP Fastest Cache, and generic proxy/Nginx endpoints
- Bulk job scheduler (offload existing media, restore local copies, retry failed items) with pause/resume support
- WooCommerce protected-download delivery via signed storage URLs
- Multisite network admin panel with per-site offload statistics
- Selective offload rules by MIME type and file size
- Nightly background batch offload for anything missed by the real-time hook
- REST API endpoints for status, statistics, integration discovery, bulk jobs, cache purge, and offload triggers
- Statistics dashboard with media-type, monthly trend, asset-root, and estimated provider cost breakdowns
- Structured logger with source/level/context metadata, retention cap, and a dedicated Logs admin tab

---

## Requirements

- WordPress 6.0+
- PHP 8.1+
- `aws/aws-sdk-php` ^3.200 (install via Composer — see below)
- Valid S3-compatible credentials with write access to the target bucket

---

## Installation

```bash
# Inside the plugin folder (or your WordPress container)
composer install
```

Activate the plugin in WordPress. On first activation you will be redirected to the **Onboarding** tab.

---

## Configuration

1. Activate Nimbus in WordPress.
2. Complete the **Onboarding** tab: enter your bucket credentials.
3. Review any conflict warnings for other media offload plugins you may have installed.
4. Enable **Serve From Storage** to rewrite public URLs.
5. Leave **Remove Local Copies** disabled in development until everything is verified.

### REST API

All endpoints require a user with `manage_options` capability.

| Method | Endpoint                                               |
| ------ | ------------------------------------------------------ |
| `GET`  | `/wp-json/nimbus/v1/status`                            |
| `GET`  | `/wp-json/nimbus/v1/statistics`                        |
| `GET`  | `/wp-json/nimbus/v1/integrations`                      |
| `POST` | `/wp-json/nimbus/v1/offload/pending`                   |
| `POST` | `/wp-json/nimbus/v1/offload/item/<attachment_id>`      |
| `POST` | `/wp-json/nimbus/v1/integrations/smart_slider/rewrite` |

### Hooks

Nimbus emits standard WordPress actions for every core lifecycle operation. Each action receives a single associative array payload containing fields such as `source`, `scope`, `operation`, `attachment_id`, `urls`, `keys`, counts, and error details where applicable.

Key actions:

`nimbus_rewrite_started` · `nimbus_rewrite_completed` · `nimbus_rewrite_failed`  
`nimbus_offload_started` · `nimbus_offload_completed` · `nimbus_offload_failed`  
`nimbus_restore_started` · `nimbus_restore_completed` · `nimbus_restore_failed`  
`nimbus_remote_delete_started` · `nimbus_remote_delete_completed` · `nimbus_remote_delete_failed`  
`nimbus_cache_purge_requested` · `nimbus_cache_purge_started` · `nimbus_cache_purge_completed` · `nimbus_cache_purge_failed`  
`nimbus_integration_rewrite_started` · `nimbus_integration_rewrite_completed` · `nimbus_integration_rewrite_failed`

#### Custom cache purge integration

Register an object implementing `Nimbus\Plugin\Cache\PurgeIntegration` via the `nimbus_cache_purge_integrations` filter:

- use `$request['urls']` for per-URL purges
- use `$request['purge_all']` for full-cache purges after a rewrite operation
- use `$request['reason']` to distinguish operation types
- respect `$request['context']['auto_purge']` if you want to suppress automatic purges selectively

Opt-out filters: `nimbus_should_purge_cache_on_remote_delete` · `nimbus_should_purge_cache_on_rewrite` · `nimbus_should_purge_cache_on_integration_rewrite`

#### Register additional integrations

Add to the `nimbus_integrations` filter with these fields:

| Field                    | Purpose                                               |
| ------------------------ | ----------------------------------------------------- |
| `key`                    | Unique machine identifier                             |
| `label`                  | Display name                                          |
| `plugin_files`           | Array of plugin file paths to detect installation     |
| `directories`            | Asset root directories for scanning                   |
| `rewrite_supported`      | `true` if a rewrite pass is available                 |
| `rewrite_action_label`   | Button label for the admin rewrite action             |
| `table_callback`         | `callable` returning tables to rewrite                |
| `rewrite_callback`       | `callable` performing the rewrite                     |
| `rewrite_ready_callback` | `callable` returning whether the integration is ready |

---

## Cloudflare R2 Setup

| Setting         | Value                                                                       |
| --------------- | --------------------------------------------------------------------------- |
| Provider        | `Cloudflare R2`                                                             |
| Bucket          | your bucket name                                                            |
| R2 Account ID   | your Cloudflare account ID                                                  |
| Region          | `auto`                                                                      |
| Access Key      | R2 API Token Access Key ID                                                  |
| Secret Key      | R2 API Token Secret Access Key                                              |
| Session Token   | leave empty for R2 API keys                                                 |
| Custom Endpoint | `https://<account_id>.r2.cloudflarestorage.com` (do not append bucket name) |
| Use Path Style  | enabled automatically                                                       |

Set **Custom Domain** to your CDN or public bucket domain for production delivery.

> Some S3-compatible providers deny `HeadObject` while still allowing read access. Nimbus falls back to a byte-range `GetObject` probe for existence checks in that case.

---

## WP-CLI

### Configure

```bash
# Show current config
wp nimbus config --show

# Configure Cloudflare R2
wp nimbus config --provider=r2 --bucket=my-bucket --r2-account-id=abc123 \
  --access-key=KEY --secret-key=SECRET --enabled=1 --serve-from-storage=1 --test-connection

# Configure an S3-compatible endpoint
wp nimbus config --provider=s3 --bucket=my-bucket --region=ap-south-1 \
  --access-key=KEY --secret-key=SECRET --endpoint=https://s3.example.com \
  --use-path-style-endpoint=1 --enabled=1

# Restrict offloading to year-based upload folders only
wp nimbus config --allowed-path-patterns='{year}/**'
```

Available flags: `--enabled`, `--serve-from-storage`, `--remove-local-copies`, `--off-hours-batch-enabled`, `--off-hours-batch-time`, `--prefix`, `--custom-domain`, `--allowed-path-patterns`, `--ignored-path-patterns`, `--test-connection`

### Deploy uploads to storage

```bash
wp nimbus deploy [--parallel=<n>] [--force]
```

Uploads files from year-based upload directories (e.g. `2026/02/...`, `sites/<id>/2026/...`) to the configured bucket. Non-year plugin/system directories are skipped. After each month-group batch, object existence in storage is verified.

### Rewrite DB URLs to storage domain

```bash
wp nimbus rewrite [--dry-run] [--force]
```

Verifies all attachment objects exist remotely first, then replaces the local uploads domain in the database. Also runs a Smart Slider 3 pass on `wp_nextend2_*` tables.

### Revert to local storage

```bash
wp nimbus revert [--batch-size=<n>]
```

Restores offloaded attachments from storage to local uploads and rewrites DB URLs back to local. Performs pre-flight disk space checks before starting.

---

## Path Filters

Restrict which upload paths are eligible for offloading:

| Pattern             | Meaning                        |
| ------------------- | ------------------------------ |
| `{year}/**`         | year-based upload folders only |
| `sites/*/{year}/**` | multisite year folders         |
| `cache/**`          | ignore cache folders           |
| `{year}/{month}/**` | specific month folders         |

Supported wildcards: `*` (any chars except `/`), `**` (nested path), `?` (single char), `{year}`, `{month}`, `{day}`.

If **Allowed Paths** is empty, all uploads are eligible except anything matched by **Ignored Paths**.

---

## Logging

The **Logs** tab provides:

- enable/disable logging
- minimum stored log level
- retained-entry cap
- optional mirroring to the PHP error log
- filtering by level, source, and text search

Each stored entry includes: `level`, `level_key`, `source`, `event`, `code`, `message`, `context`.

---

## Media Library Controls

- **Offload Status** column with state badges
- **Nimbus status** dropdown filter on `upload.php`
- Native bulk actions: Offload to storage · Restore local copies · Retry failed offloads
- Per-item ignore toggle in the attachment detail view

---

## Local Development Sandbox

```bash
# Start
docker compose -f ../../infra/dev/docker-compose.yml up -d
# Re-run setup after a volume reset
docker compose -f ../../infra/dev/docker-compose.yml run --rm setup

# WP-CLI
docker compose -f ../../infra/dev/docker-compose.yml --profile cli run --rm wpcli plugin list

# Stop
docker compose -f ../../infra/dev/docker-compose.yml down
# Full reset (database + files)
docker compose -f ../../infra/dev/docker-compose.yml down -v
```

- WordPress URL: `http://localhost:8080`
- Admin: `http://localhost:8080/wp-admin/` · user `admin` · password `password`
- Plugin mount: `wp-content/plugins/nimbus`

The sandbox uses the `Local / Debug Mode` provider so you can test all admin flows, URL rewriting, and logs without real cloud credentials.

---

## High-Traffic Optimization

1. Let WordPress generate all image sizes locally.
2. Enable **Remove Local Copies** in settings.
3. Create a Cloudflare CNAME (`cdn.yourdomain.com`) pointing to your S3/R2 bucket URL with the orange-cloud proxy enabled.
4. Set **Custom Domain** to `https://cdn.yourdomain.com`.

All image traffic flows through Cloudflare's CDN — eliminating inbound S3/R2 bandwidth costs.

---

## Notes

- Remote deletions fire when a WordPress attachment is deleted; no independent background sync runs.
- Failed uploads surface as admin notices and fall back transparently to local storage.
- Additional storage providers can be added by implementing `Nimbus\Plugin\Storage\StorageAdapter` and registering via `StorageAdapterFactory`.

See [`docs/competitor-analysis.md`](docs/competitor-analysis.md) for the UI and feature gap analysis. [`docs/refactor-roadmap.md`](docs/refactor-roadmap.md) documents the plugin's earlier commercial-licensing architecture and is kept for historical context only — it no longer reflects how the plugin works.
