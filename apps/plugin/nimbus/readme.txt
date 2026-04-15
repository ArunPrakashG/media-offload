=== Nimbus ===
Contributors: arunprakashg
Tags: media offload, amazon s3, cloudflare r2, s3-compatible, cdn
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offload WordPress media to Amazon S3, Cloudflare R2, or any S3-compatible storage with automatic URL rewriting.

== Description ==

Nimbus offloads your WordPress media library to cloud object storage — Amazon S3, Cloudflare R2, or any S3-compatible provider — and rewrites attachment URLs so your visitors are served files directly from your storage or CDN.

**Key features:**

* Offload new uploads automatically, or run a bulk migration of existing media.
* Rewrite attachment URLs in post content and metadata to point to your storage bucket or custom CDN domain.
* Restore (pull back) offloaded files to the local server at any time.
* Per-attachment offload, restore, and retry from the Media Library.
* Bulk job scheduler with pause and resume support.
* Integration support for popular CDN and image optimisation plugins.
* Import / export plugin configuration as JSON.
* Detailed activity log.

A valid license key is required to enable offloading. A free-tier license is available for single-site use.

== External Services ==

This plugin communicates with the Nimbus licensing backend at **https://api.usenimbus.dev** in the following situations:

* **License activation** — sends your license key, the current site domain, and the plugin version to verify and activate the license. The license key is HMAC-signed and is never stored in plain text after activation.
* **License status / deactivation** — uses a signed activation token (not the raw key) to check the current license status or deactivate a domain.
* **Free license registration** — sends the site domain and the admin email address to create a free-tier license.

No tracking, analytics, or telemetry data is collected. No media files are processed by the Nimbus backend; all cloud-storage transfers go directly from your server to your configured storage provider (Amazon S3, Cloudflare R2, etc.).

The licensing service Terms of Use and Privacy Policy are available at https://usenimbus.dev/legal.

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
5. Activate your license key on the **License** tab, or register for a free license.
6. Start offloading media using the **Offload** tab or let Nimbus handle new uploads automatically.

== Frequently Asked Questions ==

= Which storage providers are supported? =

Amazon S3, Cloudflare R2 (via the S3-compatible API), and any other S3-compatible object storage service (MinIO, Backblaze B2, DigitalOcean Spaces, etc.).

= Does the plugin delete local files after offloading? =

Yes, local copies are removed after a successful offload by default. You can restore them at any time using the Restore action.

= Is a paid license required? =

A free-tier single-site license is available. Premium licenses unlock additional features such as bulk migration, advanced scheduling, and multi-site support.

= Where can I get support? =

Please use the support forum on WordPress.org or contact us at contact@arunprakashg.com.

== Screenshots ==

1. Nimbus settings dashboard.
2. Provider connection settings.
3. Bulk offload job status.
4. Per-attachment offload controls in the Media Library.

== Changelog ==

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

= 0.1.4 =
Maintenance release — no action required.
