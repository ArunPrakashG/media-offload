---
title: "Changelog"
description: "Release notes for every version of Plume, the free WordPress media offload plugin, listed from newest to oldest with added, changed, removed and fixed items."
updated: 2026-11-03
---

Every release of Plume, newest first. Since 0.2.0, Plume is a single free, open-source plugin. Every feature listed here is included on every install, with no license key.

## 0.3.4 · November 2026

The plugin is now called Plume Media Offload (formerly Nimbus) in WordPress, after feedback from the WordPress.org plugin review.

### Changed

- The plugin name is **Plume Media Offload**, and the plugin folder and text domain are `plume-media-offload`. The release ZIP is named the same way. Settings, offloaded files and the **Media → Plume** page are unchanged.
- Installed an earlier version? Follow [Upgrading from 0.3.3 or earlier](/docs/#upgrading-from-033-or-earlier) so the old copy's uninstall cleanup does not remove your settings.
- Updated the AWS SDK to 3.401.5 and its dependencies.

### Fixed

- Media Library offload, restore and retry now check that you can edit that attachment, not only that you can upload files.
- Image URLs the plugin inserts into post content are escaped.
- The delete-remote-files setting is always saved as on or off.

## 0.3.3 · November 2026

The WordPress plugin was renamed Nimbus Media Offload, ready for the WordPress.org plugin directory.

### Changed

- The plugin name is **Plume Media Offload**, and the plugin folder and text domain are `nimbus-media-offload`. The release ZIP is named the same way. Settings, offloaded files and the **Media → Plume** page are unchanged.
- Installed from GitHub? Follow [Upgrading from 0.3.2 or earlier](/docs/#upgrading-from-032-or-earlier) so the old copy's uninstall cleanup does not remove your settings.

## 0.3.2 · November 2026

Set up storage by pasting one token or key. Plume creates the bucket and fills in the settings for you.

### Added

- Quick connect in the setup wizard. Paste one token, key or line of command output and Plume creates the bucket, sets it up for public media, fills in the settings and runs an upload test. Works with every provider; Backblaze B2, Wasabi and DigitalOcean Spaces also get a new key limited to the media bucket.
- Docs and donate links next to Plume on the Plugins screen.

### Changed

- Tested with WordPress 7.1.

## 0.3.1 · November 2026

Your storage keys are now encrypted in the database. Nothing to set up: existing keys are encrypted the next time an admin opens the dashboard.

### Fixed

- Storage credentials are now encrypted in the database, with a key derived from your site's security keys or a `NIMBUS_ENCRYPTION_KEY` constant. Existing settings are encrypted automatically.
- The notice shown before deactivating Plume points to the restore job and `wp nimbus revert`, instead of a Tools panel that did not exist.

## 0.3.0 · November 2026

A new look for the plugin admin and a more permissive license. Settings and offloaded files carry over unchanged.

### Changed

- Relicensed under the MIT License.
- New Plume brand across the admin: a header with connection status, a reworked overview and a guided setup wizard.
- The setup wizard tests a connection with the values on screen before you save them.
- Fonts and provider logos ship with the plugin, so the admin makes no external requests.

## 0.2.0 · July 2026

The Plume Pro add-on has been merged into this plugin and all licensing has been removed. Features that were split across free and paid tiers are now included on every install.

### Now included

- Bulk migration with pause, resume and retry-failed.
- Cache purge integrations: Cloudflare, LiteSpeed Cache, WP Fastest Cache and generic proxy or Nginx.
- The full `wp nimbus` WP-CLI command suite.
- Page-builder and gallery database URL rewriting: Elementor, Slider Revolution, Smart Slider 3 and NextGEN Gallery.
- WooCommerce signed download delivery.
- Multisite network admin overview panel.
- Selective offload rules by MIME type and file size.
- Nightly off-hours batch offload.

### Removed

- The license key requirement, the license activation screen, and every call to the former Plume licensing backend.
- The separate Plume Pro plugin. Its code is now part of this plugin.

## 0.1.4 · May 2025

Each supported storage provider now has its own provider profile, so you no longer fill in endpoint patterns by hand.

### Provider profiles

- **Google Cloud Storage:** dedicated profile using the HMAC XML API. It needs HMAC keys, not a service account JSON file, and uniform bucket-level access.
- **DigitalOcean Spaces:** native Spaces profile with virtual-hosted style and an optional CDN URL.
- **Wasabi:** dedicated profile with the endpoint filled in from the selected region.
- **Backblaze B2:** B2 through the S3-compatible API. It uses Application Keys, with the region derived from the bucket.
- **MinIO:** profile for self-hosted S3-compatible servers, with path-style access enabled.

### WooCommerce signed downloads

- Offloaded attachments sold as WooCommerce downloadable products are delivered through a short-lived signed URL that points at your storage provider.
- The buyer's browser redirects straight to the file, so your web server is bypassed. No extra configuration is required.

### Multisite network admin panel

- Super-admins on WordPress Multisite get a **Network Admin → Plume** screen. It shows license status and offload statistics for every site in the network.
- Each sub-site keeps its own settings and license activation. The network panel is a read-only overview.

### Create bucket from WordPress

- A **Create bucket** button on the storage settings screen. Enter your credentials and a bucket name, and Plume creates the bucket through the provider API.

### Config export and import

- Export your Plume configuration (credentials, rules and integrations) as a JSON file.
- Import that file on another site or environment to copy the setup, for example from staging to production.

### Job progress stream

- New `/jobs/stream` REST endpoint that provides a Server-Sent Events stream.
- The admin UI shows a live count of files processed, succeeded, failed and skipped while a job runs. No polling or page refresh is needed.

### REST endpoints

- `POST /storage/presign`: generate a short-lived signed URL for a stored file.
- `POST /storage/create-bucket`: create a new bucket from the API.
- `POST /cache/purge`: trigger a cache purge for a list of URLs.
- `GET /jobs/stream`: Server-Sent Events stream for live job progress.

## 0.1.3 · March 2025

A nightly scheduler, three new cache purge integrations and a Smart Slider 3 rewriter.

### Nightly off-hours batch

- A nightly scheduler processes the pending-offload queue at a configurable start time. The default is 3 AM server time.
- The number of parallel workers was set by plan at the time: 2 on Personal, 5 on Pro and 8 on Agency.
- The queue drains each night without manual steps.

### Cache purge integrations

- **LiteSpeed Cache:** automatic purge through LiteSpeed's native action hooks.
- **WP Fastest Cache:** per-URL and full-cache clears after offload.
- **Nginx or proxy PURGE:** sends an HTTP PURGE request to a configurable URL template with the variables `{url}`, `{host}`, `{path}` and `{scheme}`.

### Smart Slider 3 rewriter

- Plume can rewrite URLs stored in the Nextend storage tables used by Smart Slider 3. Click **Rewrite URLs** on the Integrations screen after your first bulk migration.

### REST job control endpoints

- `POST /jobs/start`: start a new bulk job.
- `POST /jobs/pause`: pause the running job.
- `POST /jobs/resume`: resume a paused job from its saved position.
- `POST /jobs/retry-failed`: process again only the failed items from the last job.

### Selective offload rules

- Path allow-list and deny-list glob filters control which files are eligible for offload.

## 0.1.2 · January 2025

The bulk job engine, integrations for Elementor, Slider Revolution and NextGEN Gallery, storage analytics, and the `wp nimbus` WP-CLI namespace.

### Bulk migration with pause and resume

- A background bulk job processes your existing library in configurable batches on a 2-second cron interval. The batch size was set by plan at the time: 25 on Personal, 75 on Pro and 100 on Agency.
- Pause a job at any time. Plume saves its exact position and resumes from the same file. Nothing is processed twice.
- **Retry failed** re-runs only the files that failed in the last job.
- The **Restore local** job type downloads all offloaded files back to your server, for example before changing providers.

### Page-builder integrations

- **Elementor:** rewrites URLs in the `_elementor_data` and `_elementor_page_settings` postmeta JSON.
- **Slider Revolution:** rewrites all `revslider_*` database tables.
- **NextGEN Gallery:** rewrites all `ngg_*` database tables.

### Storage analytics

- A dashboard with offload counts, a MIME type breakdown, a 7-day upload trend chart and an estimated monthly cost.
- A side-by-side S3 and R2 cost comparison.

### WP-CLI

- New `wp nimbus` command namespace with the subcommands `deploy`, `download`, `sync`, `rewrite`, `revert`, `replace-urls`, `process-pending`, `offload-single` and `config`.

### Selective offload rules

- Minimum file size filter, to skip small files such as thumbnails.
- MIME type filter, to offload only files that match given patterns such as `image/*` or `video/mp4`.

## 0.1.1 · November 2024

Clearer offload status in the Media Library, automatic cache invalidation after each offload, and a guided first-run flow.

### Media Library status column

- A **Plume** column shows the status of every attachment: Offloaded, Pending, Failed, Ignored or Local.
- A new Plume dropdown filters the Media Library by status.
- Row actions for single files: offload, restore or retry.

### Cloudflare cache purge

- Purges the Cloudflare CDN cache for any file that is offloaded, restored or has its URL rewritten.
- Purge requests are batched, 5 per tick, and sent asynchronously so they never block page loads.
- Requires a Zone ID and an API token scoped to cache purge.

### Guided onboarding

- A first-activation flow walks you through license activation and bucket connection.
- A live connection test reports errors per step: DNS, authentication, bucket access and write check.

### Fixed and improved

- Better error reporting when object storage returns a non-2xx response.
- The settings screen shows current storage usage and object count.
- More reliable URL rewriting for attachments referenced in custom fields.

## 0.1.0 · October 2024

The first public version of Plume: automatic media offload to Amazon S3, Cloudflare R2 and S3-compatible providers, with URL rewriting and reversible database writes.

### Core features

- **Auto-offload on upload:** every file uploaded through WordPress is sent to your bucket.
- **URL rewriting:** the **Serve From Storage** toggle rewrites attachment URLs to your bucket or CDN domain. Turn it off to return to local serving. This is a settings change and does not alter stored data.
- **Reversible database writes:** Plume stores a relative storage key, not a full provider URL. Changing CDN domains or providers needs only a settings update.
- **Remove local copies:** optionally delete files from your server after a successful offload.
- **Custom CDN domain:** serve media from your own CDN subdomain instead of the bucket URL.
- **Bucket path prefix:** keep uploads under a sub-folder of a shared bucket.

### Storage providers

- Amazon S3, all regions.
- Cloudflare R2.
- Generic S3-compatible storage, such as Vultr, Linode and Tigris.
