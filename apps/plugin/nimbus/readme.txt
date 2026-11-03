=== Nimbus ===
Contributors: arunprakashg
Tags: media offload, amazon s3, cloudflare r2, s3-compatible, cdn
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.2.0
License: MIT
License URI: https://opensource.org/license/mit

Offload WordPress media to Amazon S3, Cloudflare R2, or any S3-compatible storage with automatic URL rewriting. 100% free, no license required.

== Description ==

Nimbus offloads your WordPress media library to cloud object storage — Amazon S3, Cloudflare R2, or any S3-compatible provider — and rewrites attachment URLs so your visitors are served files directly from your storage or CDN.

Nimbus is completely free and open source. There is no license key, no paid tier, and no feature is held back — everything below is available out of the box.

**Key features:**

* Offload new uploads automatically, or run a bulk migration of existing media.
* Rewrite attachment URLs in post content and metadata to point to your storage bucket or custom CDN domain.
* Restore (pull back) offloaded files to the local server at any time.
* Per-attachment offload, restore, and retry from the Media Library.
* Bulk job scheduler with pause and resume support.
* Automatic cache purging (Cloudflare, LiteSpeed, WP Fastest Cache, and generic proxy caches) after offload, rewrite, or delete.
* WP-CLI commands for config, deploy, rewrite, revert, download, and sync.
* Database URL rewriting for popular page builders and galleries (Elementor, Slider Revolution, Smart Slider 3, NextGEN Gallery).
* WooCommerce protected-download delivery via signed storage URLs.
* Multisite network admin panel with per-site offload statistics.
* Selective offload rules by MIME type and file size.
* Nightly background batch offload for anything missed by the real-time hook.
* Import / export plugin configuration as JSON.
* Detailed activity log.

== External Services ==

Nimbus does not communicate with any Nimbus-operated service. The only outbound network calls this plugin makes are directly from your server to the storage provider you configure (Amazon S3, Cloudflare R2, Backblaze B2, DigitalOcean Spaces, Wasabi, MinIO, Google Cloud Storage, or any other S3-compatible endpoint you enter), and, if you enable it, to your configured cache-purge provider (e.g. the Cloudflare API when Cloudflare cache purging is turned on). No license activation, telemetry, or update-check calls are made to any third-party service beyond WordPress.org's own plugin-update mechanism.

== Third-Party Libraries ==

This plugin bundles the following third-party libraries:

* **AWS SDK for PHP** (aws/aws-sdk-php) — Apache License 2.0. Used to communicate with Amazon S3 and S3-compatible storage providers.
* **Guzzle HTTP** (guzzlehttp/guzzle) — MIT License.
* **PSR interfaces** (psr/http-client, psr/http-factory, psr/http-message) — MIT License.
* **Symfony Polyfill** (symfony/polyfill-mbstring) — MIT License.

== Installation ==

1. Upload the `nimbus` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Navigate to **Media → Nimbus** to complete the setup wizard.
4. Enter your storage provider credentials (bucket, region, access key, secret key).
5. Start offloading media using the **Offload** tab or let Nimbus handle new uploads automatically.

== Frequently Asked Questions ==

= Which storage providers are supported? =

Amazon S3, Cloudflare R2 (via the S3-compatible API), and any other S3-compatible object storage service (MinIO, Backblaze B2, DigitalOcean Spaces, etc.).

= Does the plugin delete local files after offloading? =

No. Local copies stay on your server unless you turn on Remove Local Copies in Settings. You can restore files from storage at any time with the Restore action.

= Is a paid license required? =

No. Nimbus is free and open source under the MIT license. Every feature, including bulk migration, cache purging, WP-CLI and multisite support, is available with no license key.

= Where can I get support? =

Please use the support forum on WordPress.org or contact us at contact@arunprakashg.com.

== Screenshots ==

1. Nimbus settings dashboard.
2. Provider connection settings.
3. Bulk offload job status.
4. Per-attachment offload controls in the Media Library.

== Changelog ==

= 0.2.0 =
* Nimbus is now 100% free and open source — the Nimbus Pro add-on has been merged into this plugin and all licensing has been removed.
* Bulk migration, cache purging (Cloudflare, LiteSpeed, WP Fastest Cache, proxy caches), WP-CLI, page-builder/gallery database URL rewriting, WooCommerce protected downloads, multisite network admin, selective offload rules, and nightly batch offload are now available to everyone at no cost.
* Removed the license key requirement, license activation UI, and all calls to the former Nimbus licensing backend.

= 0.1.4 =
* Improved inline script handling for admin notices.
* Replaced error-suppressed unlink() calls with proper is_writable() checks.
* Added missing plugin header fields (Requires at least, Requires PHP).

= 0.1.3 =
* Added import / export config feature.
* Added per-attachment retry from Media Library.

= 0.1.2 =
* Added Cloudflare R2 provider support.
* Bulk job pause and resume support.

= 0.1.1 =
* Initial public release.
* Amazon S3 offload and URL rewrite.
* Free license registration.

== Upgrade Notice ==

= 0.2.0 =
Nimbus is now fully free — no license key needed. All former Pro features are unlocked automatically after updating.
