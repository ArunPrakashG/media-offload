---
title: Offloading media
nav: Offloading media
description: How Nimbus offloads new uploads, when they move, what the Offload Status column shows, how to filter by path, and what happens to local copies.
section: guides
order: 1
---

Offloading copies an attachment and its image sizes to your bucket, records where they went, and points WordPress at the new location. This page explains when that happens and how to control it.

## When new uploads move

New uploads are marked as pending the moment WordPress finishes processing them. What happens next depends on the off-hours batch, which is **on by default**.

- **Off-hours batch on (default).** Pending uploads wait and are offloaded in one run at the Batch Time, which defaults to `03:00` in your site's timezone. See [nightly batch](/docs/guides/scheduling/).
- **Off-hours batch off.** Nimbus schedules a WP-Cron event for each upload. It copies the files to storage, then updates the attachment URLs in post content.

To offload right after upload, go to **Media → Nimbus → Settings**, open **Off-hours Batch Offload**, turn off **Enable Off-hours Batch**, and select **Save Settings**.

Offloading only runs when **Enable Offloading** is on and the credentials are complete. If you try to enable it with missing fields, Nimbus turns it off and tells you which fields are missing.

## What gets stored

For each attachment Nimbus uploads the original file and every generated image size. The object key is the file's path under `wp-content/uploads`, with your Path Prefix in front. For example, `2026/11/photo.jpg` with the prefix `media` becomes `media/2026/11/photo.jpg`.

Nimbus records the result in the `_nimbus` post meta of the attachment.

## Offload Status in the Media Library

The Media Library list view has an **Offload Status** column and an **All Nimbus states** filter.

| State | Meaning |
| --- | --- |
| Local only | Not offloaded. |
| Pending | Queued, waiting for the next run. |
| Processing | An offload is running. |
| Offloaded | Stored remotely. |
| Needs attention | The last attempt failed. Check [Logs](/docs/reference/troubleshooting/). |
| Ignored | Excluded from offload. |

Row actions depend on the state: **Offload now**, **Retry offload**, **Retry**, **Restore local** and **View status**. The bulk actions menu has **Offload to storage**, **Restore local copies** and **Retry failed offloads**.

## Choose what is offloaded

Under **Media → Nimbus → Advanced → Path Filters** you can limit offloading by folder.

- **Allowed Paths**: when not empty, only matching files are offloaded.
- **Ignored Paths**: matching files are never offloaded. Ignored wins over Allowed.

Patterns are relative to the uploads folder. `*` matches within one folder, `**` matches across folders, `?` matches one character, and `{year}`, `{month}` and `{day}` match digits. Type a pattern and press Enter.

```text
{year}/**
private/**
**/*.pdf
```

Two more rules have no screen in the admin. Set them with [WP-CLI](/docs/reference/wp-cli/) or a [config import](/docs/reference/config-export/):

```bash
wp nimbus config --offload_min_size_kb=50
wp nimbus config --offload_allowed_mime_types="image/*,application/pdf"
```

`offload_min_size_kb` skips files smaller than that size. `offload_allowed_mime_types` is a comma separated list, and wildcards like `image/*` work. Both are empty by default, which means no limit.

## Local copies

**Remove Local Copies** is off by default, so the files stay on your server after offload. When it is on, Nimbus deletes the local files a few minutes after a successful offload. It only does this when your credentials are valid.

You can bring files back with [restoring files](/docs/guides/restoring-files/).

## Deleting media

When you delete an attachment in WordPress, Nimbus also deletes its objects from the bucket. This is controlled by `delete_remote_on_wp_delete`, which defaults to on and has no screen. To keep remote files, run:

```bash
wp nimbus config --delete_remote_on_wp_delete=0
```

> **Tip:** Turn on Remove Local Copies only after you have run Test Connection and offloaded a few files you can open in the browser.
