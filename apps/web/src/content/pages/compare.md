---
title: "Nimbus compared"
description: "A factual comparison of Nimbus with other WordPress media offload plugins: price, limits, storage providers, migration, cache purging and integrations."
updated: 2026-11-03
---

Nimbus is free and released under the MIT License. Every feature is included on every install, with no media cap and no limit on sites. This page compares it with other WordPress media offload plugins, both paid and free.

## Free tiers of other plugins

Some other offload plugins now have a free tier. This table shows what Nimbus includes compared with a typical free-tier plugin.

| In the free tier | Nimbus | Typical free-tier plugin |
| --- | --- | --- |
| Bulk migration of existing library | Yes. Unlimited, with pause, resume and live progress | Often a paid add-on, or a capped batch size |
| Cache purge automation | Yes. Cloudflare, LiteSpeed, WP Fastest Cache and proxy | Rarely included |
| WooCommerce signed downloads | Yes | Commonly a paid add-on |
| Page-builder and gallery database rewriting | Yes. Elementor, Slider Revolution, Smart Slider 3, NextGEN | Rarely included |
| Storage cost analytics | Yes | Rarely included |
| Multisite network admin panel | Yes | Rarely included |
| REST API and WP-CLI | Yes. Read and write endpoints, full CLI suite | CLI sometimes present, REST API rarely |

This is based on the published feature lists and pricing pages of active WordPress.org media offload plugins as of mid-2026. Free tiers change over time, so check a plugin's current listing before you decide.

## Paid plugins: price and limits

Competitor figures are representative of the leading per-file pricing plugins at regular, non-promotional rates, shown per year.

| Plan | Nimbus | Other plugins |
| --- | --- | --- |
| Price | $0. Unlimited sites, unlimited media, every feature | $99 to $1,199 per year, depending on media volume |
| Media file cap | None | Hard cap per plan, from 2K to 500K items, with unlimited as a paid extra |

## Feature comparison

### Pricing and limits

| Feature | Nimbus | Other plugins |
| --- | --- | --- |
| Media file cap | None | Hard cap per plan (2K, 500K, then paid extra for unlimited) |
| Free plan | Yes. Unlimited sites and media, no time limit | No. Paid only |
| License model | MIT License. No license key, no media cap | Priced by media item count, unlimited sites |

### Storage providers

| Feature | Nimbus | Other plugins |
| --- | --- | --- |
| Amazon S3 | Yes | Yes |
| Cloudflare R2 | Yes. Dedicated profile: endpoint, region and path-style are set from your Account ID | Manual S3-compatible setup |
| Google Cloud Storage | Yes. Dedicated profile using the HMAC XML API | Yes, varies by plugin |
| DigitalOcean Spaces | Yes. Dedicated profile | Yes |
| Backblaze B2 | Yes. Dedicated profile | Varies by plugin |
| Wasabi | Yes. Dedicated profile | Varies by plugin |
| MinIO (self-hosted) | Yes. Dedicated profile | Generic S3-compatible only |
| Any S3-compatible provider | Yes. Generic profile | Yes |
| Create bucket from WordPress | Yes. No cloud console needed | No. The bucket must be created manually |
| Switch providers without URL changes | Yes. Change a setting | Full database search-replace required |

### Media delivery

| Feature | Nimbus | Other plugins |
| --- | --- | --- |
| Automatic URL rewriting | Yes | Yes |
| Custom CDN delivery domain | Yes. Any CDN edge or subdomain | Varies by plugin |
| Toggle cloud or local serving | Yes. One setting, no post edits | No |

### Migration and scheduling

| Feature | Nimbus | Other plugins |
| --- | --- | --- |
| Bulk migrate existing library | Yes | Yes |
| Pause and resume bulk migration | Yes. Survives server restarts | Background process only, no pause or resume |
| Live migration progress stream | Yes. Server-Sent Events | No |
| Nightly off-hours scheduling | Yes. Configurable time, up to 8 workers | No |
| Restore files to the local server | Yes | Varies by plugin |

### Offload rules

| Feature | Nimbus | Other plugins |
| --- | --- | --- |
| Filter by MIME type | Yes | Varies by plugin |
| Filter by minimum file size | Yes | No |
| Path glob allow and deny rules | Yes. Block or allow specific folders | No |

### Cache purging

| Feature | Nimbus | Other plugins |
| --- | --- | --- |
| Cloudflare cache purge | Yes. Automatic after every offload | No |
| LiteSpeed and WP Fastest Cache purge | Yes | No |
| Nginx PURGE support | Yes. Configurable endpoint | No |

### Analytics

| Feature | Nimbus | Other plugins |
| --- | --- | --- |
| Storage usage dashboard | Yes. MIME breakdown and trend charts | No |
| S3 and R2 cost comparison | Yes. Estimate based on your library size | No |

### Integrations

| Feature | Nimbus | Other plugins |
| --- | --- | --- |
| Page-builder URL rewriting (Elementor and others) | Yes | Separate paid add-ons |
| WooCommerce signed download URLs | Yes | Paid add-on required |
| WordPress Multisite (per-site settings) | Yes | Yes, on all paid plans |
| Multisite network admin panel | Yes. Overview of all sites | No |

### Developer and automation

| Feature | Nimbus | Other plugins |
| --- | --- | --- |
| WP-CLI commands | Yes. Full `wp nimbus` command suite | Yes. Basic commands |
| REST API (read and job control) | Yes. Trigger jobs and query status | No |
| Use from AI agents and CI pipelines | Yes. Works with Claude, Cursor, GitHub Actions and n8n | No |
| Config export and import (JSON) | Yes. Move a setup from staging to production | No |
| Diagnostics and log viewer | Yes. Filterable structured logs and a connection test | No |

### Support

| Feature | Nimbus | Other plugins |
| --- | --- | --- |
| Community support | Yes. GitHub issues and email | Yes, paid plans only |

## Nimbus and the market leader

The market leader here is the most widely installed WordPress media offload plugin. This table compares it with Nimbus on specific capabilities.

| Capability | Nimbus | Market leader |
| --- | --- | --- |
| Cloudflare R2 dedicated profile | Yes. Enter Account ID, bucket and API token. Endpoint, region and path-style are filled in | Manual S3-compatible setup, no R2-specific guidance |
| Switching providers | Change a setting | Full database search-replace required |
| Google Cloud Storage | Yes. Dedicated profile using the HMAC XML API | Yes. Native support |
| Automatic cache purging | Yes. Cloudflare, LiteSpeed, WP Fastest Cache and Nginx | No |
| Pauseable bulk migration | Yes. Pause, resume, auto-retry and live progress stream | Background process, no pause or resume |
| Nightly off-hours scheduling | Yes. Configurable time, up to 8 parallel workers | No |
| Storage cost analytics | Yes. S3 and R2 cost comparison based on your library size | No |
| Page-builder integration rewriting | Yes. Elementor, Slider Revolution, Smart Slider 3, NextGEN | Separate paid add-ons required |
| WooCommerce signed downloads | Yes. Buyers are redirected to a signed storage URL, bypassing your server | Yes, as a paid add-on |
| Multisite network admin | Yes. Per-site settings and a network overview panel | Per-site only, no network admin panel |
| Create bucket from settings page | Yes | No. The bucket must be created manually |
| REST API | Yes. Read, job control and presign endpoints | No formal REST API |
| Config export and import | Yes. JSON backup and restore | No |
| Price | Free, with unlimited media and unlimited sites | Paid, tiered by media volume |

## Frequently asked questions

### Is Nimbus free?

Yes. Bulk migration, nightly scheduling, cache purge, cost analytics, page-builder rewriting, WooCommerce signed downloads and multisite network admin are all included. There is no license key and no paid tier.

### Is there a limit on sites or media files?

No. You can run Nimbus on as many WordPress installations as you like, with any amount of media. There is no activation limit and no per-file cap.

### Is Nimbus open source?

Yes. Nimbus is released under the MIT License and its source is public on [GitHub](https://github.com/ArunPrakashG/media-offload). Contributions, bug reports and feature requests are welcome.

### Do updates and support require payment?

No. Every release is free on GitHub. Support is community-based, through GitHub issues and email. No license or subscription is needed.

### Which storage providers does Nimbus support?

Nimbus has nine provider profiles: Amazon S3, Cloudflare R2, Google Cloud Storage, DigitalOcean Spaces, Backblaze B2, Wasabi, MinIO, a generic S3-compatible option for any other S3-speaking provider, and local disk for development. Setup for Cloudflare R2 and Google Cloud Storage is shortened: enter your Account ID or HMAC key and the rest is filled in. See the [documentation](/docs/) and the [Cloudflare R2 guide](/docs/providers/cloudflare-r2/) for setup steps.
