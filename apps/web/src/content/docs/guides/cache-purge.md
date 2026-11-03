---
title: Cache purge
nav: Cache purge
description: Configure Nimbus to purge Cloudflare, LiteSpeed, WP Fastest Cache or a reverse proxy when media is deleted or URLs are rewritten, and trigger purges by API.
section: guides
order: 6
---

Cached pages and CDN copies can keep old media URLs after a change. Nimbus can ask your cache layer to purge them. Everything is off until you turn it on.

## Where to find it

Go to **Media → Nimbus → Advanced → Cache Purging**. Select **Save Advanced Settings** after you change anything.

## When Nimbus purges

| Event | What is purged |
| --- | --- |
| A remote file is deleted | The exact URLs of the deleted files. |
| A URL rewrite finishes and changed at least one post | Everything. |
| An integration rewrite finishes | Everything. |
| You call the [REST API](/docs/reference/rest-api/) | The URLs you send, or everything. |

Offloading a file on its own does not purge. Changes made with the URL update tools in the admin do not purge automatically.

Purge requests go into a queue. Nimbus sends five at a time with a short delay between batches, so large rewrites do not flood your cache service.

## Cloudflare

1. Turn on **Enable Cloudflare Cache Purging**.
2. Enter the **Cloudflare Zone ID** from the zone's Overview page.
3. Create an API token with the **Zone: Cache Purge** permission and enter it as **Cloudflare API Token**.

The Cloudflare option appears when a Custom Domain is set. If the Zone ID or token is missing, Nimbus turns the option off and logs "Cloudflare purge was disabled because Zone ID or API token is missing."

## LiteSpeed Cache and WP Fastest Cache

Turn on **Enable LiteSpeed Cache Purging** or **Enable WP Fastest Cache Purging**. Nimbus calls the plugin's own purge functions, so the plugin must be active. If it is missing, the purge is skipped.

## Proxy or Nginx cache

Turn on **Enable Proxy / Nginx Cache Purging** and fill in:

- **Proxy Purge Method**: `PURGE`, `GET` or `POST`. The default is `PURGE`.
- **Proxy Purge URL Templates**: one endpoint per line, used for exact URLs.
- **Proxy Full Purge Endpoints**: one endpoint per line, called for a full purge.

Templates can use `{url}`, `{url_encoded}`, `{scheme}`, `{host}`, `{path}` and `{path_encoded}`. A template without any placeholder gets the URL path added to the end.

```text
https://cache.example.com/purge?url={url_encoded}
https://origin.example.com{path}
```

## Purge from the API

```bash
curl -X POST https://example.com/wp-json/nimbus/v1/cache/purge \
  -H "Content-Type: application/json" \
  -u "admin:APPLICATION_PASSWORD" \
  -d '{"urls":["https://cdn.example.com/media/2026/11/photo.jpg"],"purge_all":false}'
```

The call returns `202` and queues the work. See the [REST API reference](/docs/reference/rest-api/).

> **Tip:** After you change the [custom domain](/docs/guides/custom-domain/), run a full purge once so old URLs leave every cache.
