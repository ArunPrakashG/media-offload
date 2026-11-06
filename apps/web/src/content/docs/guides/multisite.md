---
title: Multisite
nav: Multisite
description: "How Plume behaves on a WordPress multisite network: per-site settings, the read-only Network Overview page, and what to set up on each site."
section: guides
order: 8
---

On a multisite network, Plume keeps its settings separately for every site. Each site connects to its own bucket, or the same bucket with a different Path Prefix.

## Per-site setup

1. Open the site's dashboard and go to **Media → Plume**.
2. Run the [setup wizard](/docs/setup-wizard/) for that site.
3. Give each site its own Path Prefix if sites share a bucket, for example `site-2`.

Offloading, bulk jobs, logs and the nightly batch all run per site. The [REST API](/docs/reference/rest-api/) and [WP-CLI](/docs/reference/wp-cli/) act on the site you target. In WP-CLI, add `--url=` to choose it:

```bash
wp --url=https://site2.example.com nimbus sync --limit=500
```

## Network Overview

In **Network Admin**, Plume adds a top-level **Plume** menu. It opens a read-only **Network Overview** table with these columns:

- Site
- Provider
- Offloaded files
- Pending
- A link to that site's settings

The table lists up to 200 sites. It needs the network admin capability, and you cannot change settings from it.

## Notes

- Settings and credentials are not shared between sites. Import a [config file](/docs/reference/config-export/) on each site to reuse them.
- Use a different Path Prefix per site on a shared bucket so keys never collide.

> **Tip:** Set up one site fully, export its config, and import it on the others. Then change only the Path Prefix.
