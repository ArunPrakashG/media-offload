---
title: Restoring files
nav: Restoring files
description: Bring offloaded media back to your server with Plume, for one attachment or the whole library, and put post content back on local URLs.
section: guides
order: 4
---

Restoring downloads offloaded files back into `wp-content/uploads` and clears the Plume offload record. Use it before you uninstall Plume or switch providers.

## Restore one attachment

In the Media Library list view, use the **Restore local** row action on an offloaded item. You can also select several items and choose **Restore local copies** from the bulk actions menu.

From the command line:

```bash
wp nimbus download 12
wp nimbus download --ids=12,15,18
wp nimbus download --limit=200
```

`--limit` restores the first N attachments by ID.

## Restore the whole library

1. Go to **Media → Plume → Tools**.
2. Under **Bulk media jobs**, select **Restore local copies**.
3. Wait for the job to finish. Use **Retry failed items** if some files failed.

Restoring needs working storage credentials. It does not need Enable Offloading to be on.

## Put URLs back on local paths

Restoring puts the files back and removes the offload record. It does not rewrite URLs that were saved into post content. Do that next:

```bash
wp nimbus revert --limit=500
```

If you also need to change a specific address, use explicit values:

```bash
wp nimbus replace-urls --from=https://cdn.example.com/media --to=https://example.com/wp-content/uploads
```

`replace-urls` changes `post_content` only, and stops after `--limit` posts. The default is 500.

## Uninstalling Plume

Before you deactivate the plugin, run the restore job and `wp nimbus revert`. Otherwise posts may still point at the bucket.

> **Tip:** Check free disk space first. A full restore needs room for every original and every image size.
