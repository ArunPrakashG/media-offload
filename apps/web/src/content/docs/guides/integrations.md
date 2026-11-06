---
title: Integrations
nav: Integrations
description: See which page builders and sliders Plume can rewrite, how WooCommerce downloads get signed links, and which other offload plugins conflict with Plume.
section: guides
order: 7
---

Some plugins save media URLs in their own tables instead of in post content. The Integrations tab shows which ones Plume found and lets you rewrite them.

## The Integrations tab

Open **Media → Plume → Integrations**. Each row lists an installed plugin with its state, the asset paths Plume found, and its coverage:

- **Rewrite supported**: Plume can update the stored URLs for this plugin.
- **Detection only**: Plume found it, but does not change its data.

Use **Enable** or **Disable** on a row, then the rewrite button, for example **Rewrite Elementor URLs**. The rewrite button stays disabled until the integration is ready. After a rewrite finishes Plume runs a [cache purge](/docs/guides/cache-purge/) if one is configured.

## Supported plugins

| Plugin | What Plume rewrites |
| --- | --- |
| Elementor | `_elementor_data` and `_elementor_page_settings` post meta. |
| Smart Slider 3 | Slider data in the Nextend tables. |
| Slider Revolution | Slider data in the `revslider_` tables. |
| NextGEN Gallery | Gallery data in the `ngg_` tables. |

Custom upload folders outside the standard uploads path are listed as **Discovered asset roots**. They are detection only.

You can also start a rewrite over the [REST API](/docs/reference/rest-api/) with `POST /integrations/<key>/rewrite`, where the key is `elementor`, `smart_slider`, `slider_revolution` or `nextgen_gallery`.

## WooCommerce downloads

Plume changes WooCommerce downloadable product links for offloaded files. When a customer downloads a file that Plume has offloaded, the link becomes a signed URL that expires after one hour. Files that are not offloaded keep the normal WooCommerce link.

This works with every provider Plume supports, so the bucket can stay private. You do not need to turn it on.

To change the lifetime, add a filter. The value is in seconds and the minimum is 60:

```php
add_filter('nimbus/woocommerce/presign_ttl', function () {
    return 900;
});
```

## Conflicting plugins

Plume warns in the setup wizard when it finds another offload plugin, for example WP Offload Media, Media Cloud, WP-Stateless, S3 Uploads or Infinite Uploads. Run only one of them at a time. Deactivate the other plugin and restore its files before you switch.

> **Tip:** Run an integration rewrite on a staging copy first. It edits stored plugin data.
