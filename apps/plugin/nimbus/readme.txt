=== Plume Media Offload ===
Contributors: arunprakashg
Donate link: https://buymeacoffee.com/arunprakashg
Tags: media offload, amazon s3, cloudflare r2, s3-compatible, cdn
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.3.5
License: MIT
License URI: https://opensource.org/license/mit

Offload WordPress media to Amazon S3, Cloudflare R2, or any S3-compatible storage with automatic URL rewriting. 100% free, no license required.

== Description ==

Plume offloads your WordPress media library to cloud object storage — Amazon S3, Cloudflare R2, or any S3-compatible provider — and rewrites attachment URLs so your visitors are served files directly from your storage or CDN.

Plume is completely free and open source. There is no license key, no paid tier, and no feature is held back — everything below is available out of the box.

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

This plugin connects to the object storage service you choose, from your server, to store and serve your media. It does not connect to any service run by the plugin author. It makes no license, telemetry or update-check calls other than WordPress.org's own plugin updates.

**Storage provider (required, you pick one).** Every upload you offload is sent to the provider's storage API together with the access key you entered, so the provider can sign the request. Files are also read, listed and deleted there when you restore, bulk-process or delete media. The provider you select in the settings is the only one contacted.

* Amazon S3 (amazonaws.com). [Terms](https://aws.amazon.com/service-terms/), [Privacy](https://aws.amazon.com/privacy/)
* Cloudflare R2 (r2.cloudflarestorage.com). [Terms](https://www.cloudflare.com/website-terms/), [Privacy](https://www.cloudflare.com/privacypolicy/)
* Google Cloud Storage (storage.googleapis.com). [Terms](https://cloud.google.com/terms), [Privacy](https://policies.google.com/privacy)
* Backblaze B2 (backblazeb2.com). [Terms](https://www.backblaze.com/company/policy/terms-of-service), [Privacy](https://www.backblaze.com/company/policy/privacy)
* DigitalOcean Spaces (digitaloceanspaces.com). [Terms](https://www.digitalocean.com/legal/terms-of-service-agreement), [Privacy](https://www.digitalocean.com/legal/privacy-policy)
* Wasabi (wasabisys.com). [Terms](https://wasabi.com/legal/customer-agreement), [Privacy](https://wasabi.com/legal/privacy-policy)
* MinIO or any other S3-compatible endpoint: the server address you enter. Its terms and privacy policy are those of whoever runs it.

**Quick connect (optional, only when you select Connect in the setup wizard).** The token or key you paste is sent once, from your server, to the provider's account API to find or create the bucket and a key limited to it. The pasted value is not saved.

* Cloudflare API (api.cloudflare.com), for R2. Same terms and privacy links as above.
* Backblaze B2 API (api.backblazeb2.com). Same links as above.
* DigitalOcean API (api.digitalocean.com), for Spaces. Same links as above.
* Wasabi IAM API (iam.wasabisys.com). Same links as above.

For Amazon S3 and Google Cloud Storage, quick connect gives you a command to run in your own AWS CloudShell or Google Cloud Shell. The plugin only reads the line you paste back.

**Cache purge (optional, off by default).** If you turn on Cloudflare cache purging, the plugin sends the URLs of changed or deleted media and the Cloudflare API token you entered to the Cloudflare API (api.cloudflare.com), so Cloudflare clears its cached copies. Terms and privacy as above. Proxy cache purging sends a purge request to the address you configure.

**Documentation links.** Links in the admin screens to the plugin documentation at plume.arunprakashg.com open in your browser when you click them. The plugin itself sends no data to that site.

== Third-Party Libraries ==

This plugin bundles the following third-party libraries:

* **AWS SDK for PHP** (aws/aws-sdk-php) — Apache License 2.0. Used to communicate with Amazon S3 and S3-compatible storage providers.
* **Guzzle HTTP** (guzzlehttp/guzzle) — MIT License.
* **PSR interfaces** (psr/http-client, psr/http-factory, psr/http-message) — MIT License.
* **Symfony** (symfony/filesystem, symfony/deprecation-contracts and the polyfill-mbstring, polyfill-php80 and polyfill-php82 packages) — MIT License.

== Installation ==

1. In WordPress, go to **Plugins → Add New**, search for Plume Media Offload, then install and activate it. To install by hand, upload the `plume-media-offload` folder to `/wp-content/plugins/` and activate it on the **Plugins** screen.
2. Go to **Media → Plume** to open the setup wizard.
3. Connect your storage provider with quick connect, or enter the bucket, region and keys by hand.
4. New uploads are offloaded automatically. To move the existing library, use **Offload existing library** on the **Tools** tab.

== Frequently Asked Questions ==

= Which storage providers are supported? =

Amazon S3, Cloudflare R2 (via the S3-compatible API), and any other S3-compatible object storage service (MinIO, Backblaze B2, DigitalOcean Spaces, etc.).

= Does the plugin delete local files after offloading? =

No. Local copies stay on your server unless you turn on Remove Local Copies in Settings. You can restore files from storage at any time with the Restore action.

= Is a paid license required? =

No. Plume is free and open source under the MIT license. Every feature, including bulk migration, cache purging, WP-CLI and multisite support, is available with no license key.

= Where can I get support? =

Please use the support forum on WordPress.org or contact us at contact@arunprakashg.com.

== Screenshots ==

1. Overview: offload totals, setup health and a storage cost comparison.
2. Storage connection settings for the selected provider.
3. Bulk jobs to offload the existing library, restore local copies or retry failures.
4. Offload status for each file in the Media Library.

== Changelog ==

= 0.3.5 =
* The project site and documentation moved to plume.arunprakashg.com.

= 0.3.4 =
* Renamed from Nimbus to Plume Media Offload for the WordPress.org directory. The plugin folder and text domain are now plume-media-offload.
* Media Library offload, restore and retry now check that you can edit that attachment.
* Image URLs inserted into post content are escaped.
* Updated the AWS SDK to 3.401.5 and its dependencies.
* The External Services section now covers every storage provider with terms and privacy links.

= 0.3.3 =
* Renamed from Nimbus to Nimbus Media Offload. The plugin folder and text domain were nimbus-media-offload.

= 0.3.2 =
* Quick connect in the setup wizard: paste one token, key or line of command output and Plume creates the bucket, sets it up for public media, fills in the settings and runs an upload test.
* Docs and donate links on the Plugins screen.
* Tested with WordPress 7.1.

= 0.3.1 =
* Storage credentials are encrypted in the database, using your site's security keys or a NIMBUS_ENCRYPTION_KEY constant.
* The deactivation notice points to the restore job and wp nimbus revert.

= 0.3.0 =
* Relicensed under the MIT License.
* New Plume brand across the admin: header with connection status, a reworked overview and a guided setup wizard.
* The setup wizard can test a connection with the values on screen before you save them.
* Fonts and provider logos are bundled with the plugin, so the admin makes no external requests.

= 0.2.0 =
* Plume is now 100% free and open source — the Plume Pro add-on has been merged into this plugin and all licensing has been removed.
* Bulk migration, cache purging (Cloudflare, LiteSpeed, WP Fastest Cache, proxy caches), WP-CLI, page-builder/gallery database URL rewriting, WooCommerce protected downloads, multisite network admin, selective offload rules, and nightly batch offload are now available to everyone at no cost.
* Removed the license key requirement, license activation UI, and all calls to the former Plume licensing backend.

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

= 0.3.5 =
Documentation links point to the new site address.

= 0.3.4 =
Renamed to Plume Media Offload and tightened permission checks. If you installed from GitHub, deactivate the old copy, activate this one, then remove the old plugin folder by SFTP rather than with Delete.

= 0.3.3 =
Renamed to Nimbus Media Offload.

= 0.3.2 =
Adds quick connect to the setup wizard. Your settings and offloaded files are unchanged.

= 0.3.1 =
Your storage keys are now encrypted in the database. Nothing to do: Plume encrypts them the next time you open the admin.

= 0.3.0 =
New admin design and an MIT license. Your settings and offloaded files are unchanged.

= 0.2.0 =
Plume is now fully free — no license key needed. All former Pro features are unlocked automatically after updating.
