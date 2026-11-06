---
title: Custom domain and CDN
nav: Custom domain
description: Serve offloaded WordPress media from your own domain or a CDN with the Plume Custom Domain setting, and learn how object keys map to URLs.
section: guides
order: 5
---

By default Plume builds public URLs from your provider's object address. Set a Custom Domain to serve media from your own hostname or a CDN instead.

## Set it

1. Go to **Media → Plume → Settings**.
2. Make sure **Serve From Storage** is on. The Custom Domain field appears under **Delivery**.
3. Enter the full address, including `https://`, for example `https://cdn.example.com`.
4. Select **Save Settings**.

You can also set it in the setup wizard as the custom delivery domain.

## How URLs are built

Plume joins the Custom Domain and the object key with a single slash. The key includes your Path Prefix.

```text
Custom Domain:  https://cdn.example.com
Path Prefix:    media
File:           2026/11/photo.jpg
URL:            https://cdn.example.com/media/2026/11/photo.jpg
```

Your CDN or domain must map to the root of the bucket, so the full key resolves. Do not add the prefix to the Custom Domain, or it will appear twice.

## By provider

| Provider | What to point the domain at |
| --- | --- |
| Amazon S3 | A CloudFront distribution with the bucket as origin. |
| Cloudflare R2 | An R2 custom domain on a Cloudflare zone, or the `r2.dev` URL for testing. Required for R2. |
| Google Cloud Storage | A CDN or load balancer in front of the bucket. |
| Backblaze B2 | A CDN or Cloudflare proxy in front of the bucket. |
| DigitalOcean Spaces | The Spaces CDN address, or your own domain on it. |
| Wasabi, MinIO, S3-compatible | A reverse proxy or CDN in front of the endpoint. |

## Changing the domain later

New URLs use the new domain straight away when Serve From Storage is on. Addresses already saved in post content need a replace:

```bash
wp nimbus replace-urls --from=https://old.example.com --to=https://cdn.example.com
```

Then [purge the cache](/docs/guides/cache-purge/) at your CDN and page cache.

> **Tip:** Open one image URL directly in a private window before you save the setting.
