---
title: Switching providers
nav: Switching providers
description: Move a WordPress site from one storage provider to another with Plume by restoring files locally, updating credentials and offloading the library again.
section: guides
order: 3
---

Plume stores files under the provider and bucket in your settings. To change either one, bring the files back first, then offload them again to the new place.

## Steps

1. Take a backup of your database and `wp-content/uploads`.
2. Under **Tools → Bulk media jobs**, select **Restore local copies**. Wait for it to finish with no failures. This downloads every offloaded file from the old bucket.
3. Put post content back on local URLs:

```bash
wp nimbus revert --limit=500
```

4. Go to **Settings**, change the provider and credentials, and select **Save Settings**. Use the page for your new provider, for example [Cloudflare R2](/docs/providers/cloudflare-r2/).
5. Select **Test Connection** under **Tools**.
6. Select **Offload existing library** to upload everything to the new bucket. See [bulk migration](/docs/guides/bulk-migration/).
7. Check the front end, then clean up the old bucket.

## Things to know

- Restoring downloads files to the path stored on each attachment. Make sure there is enough disk space for the whole library.
- If you use a Path Prefix, try the restore and offload on a few files first, or on a staging copy, and confirm the keys in the new bucket look right.
- If you only change the public address, for example by adding a CDN, you do not need to move files. Set a [custom domain](/docs/guides/custom-domain/) and update stored URLs with `wp nimbus replace-urls`.
- Keep the old bucket until you have checked pages, galleries and downloads.

> **Tip:** Export your settings first from **Tools → Configuration backup**. See [config export](/docs/reference/config-export/).
