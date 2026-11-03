---
title: Troubleshooting
nav: Troubleshooting
description: "Fix common Nimbus problems: failed connection tests, images that do not load, uploads that never offload, stuck bulk jobs, WP-Cron issues and purge errors."
section: reference
order: 5
---

Start with the **Logs** tab under **Media → Nimbus**. It filters by level, source and search text, and it shows the reason for most failures.

## Test Connection fails

Go to **Tools** and select **Test Connection**. Nimbus writes a small `connection-test-<time>.txt` object, reads it back and deletes it. On failure it shows "Connection failed" with the provider's message, plus **Connection details** and **Suggested checks**.

Check these in order:

1. **Region.** It must match the bucket's region exactly.
2. **Endpoint.** Leave it empty for S3, R2, Backblaze B2, DigitalOcean Spaces and Wasabi. MinIO and S3-compatible need a full URL with `https://`.
3. **Path style.** Some S3-compatible servers need **Use Path Style**. Try toggling it.
4. **Permissions.** The key must be able to list, read, write and delete objects in the bucket.
5. **Key type.** Backblaze B2 needs an application key. Spaces needs a Spaces key. GCS needs an HMAC key.
6. **Server clock.** Signed requests fail when the server time is far off.

## Images do not load after offload

- Open the image URL in a private window. A `403` means the object is not public. Fix the bucket policy or CDN. Nimbus does not set object ACLs.
- On Cloudflare R2, set the **Custom Domain** to your public R2 domain. Without it, Nimbus builds URLs from the account endpoint, which is not public.
- If the Custom Domain has a path or prefix mismatch, the key will not resolve. See [custom domain](/docs/guides/custom-domain/).
- If old pages still show broken URLs, [purge the cache](/docs/guides/cache-purge/).
- If **Serve From Storage** is off, Nimbus does not rewrite URLs.

## New uploads stay Pending

By default Nimbus waits for the nightly run at `03:00`. This is expected. To offload at upload time, turn off **Enable Off-hours Batch**. See [nightly batch](/docs/guides/scheduling/).

If it still does not move, check that **Enable Offloading** is on and credentials are complete. Also check that the file is not excluded by Ignored Paths, a minimum size or a MIME rule. See [offloading media](/docs/guides/offloading/).

## A bulk job is stuck

Bulk jobs and the nightly batch run through WP-Cron. Check that cron works:

```bash
wp cron event list
wp cron event run --due-now
```

On sites with little traffic, or with `DISABLE_WP_CRON` set to true, add a system cron:

```bash
* * * * * cd /var/www/html && wp cron event run --due-now >/dev/null 2>&1
```

Only one bulk job runs at a time. If you see "A bulk media job is already running", wait for it, or use **Pause** in **Tools**.

## An item shows Needs attention

Open the **Logs** tab and search for the attachment ID. Then use **Retry offload** in the Media Library, or **Retry failed items** under Tools. When the local source file is missing, Nimbus waits 12 hours before it tries again.

## Cloudflare purge turned itself off

Nimbus disables it when the Zone ID or the API token is missing, and logs "Cloudflare purge was disabled because Zone ID or API token is missing." Enter both and turn it back on. The token needs the Zone: Cache Purge permission.

## Another plugin conflicts

Two offload plugins at once can break URLs. Deactivate the other plugin before you enable Nimbus. See [integrations](/docs/guides/integrations/).

## Going back to local files

Run the restore job, then put URLs back in post content:

```bash
wp nimbus revert --limit=500
```

Nimbus shows a notice on deactivation that points to a Revert URLs button in Tools. Use the command above for this step. See [restoring files](/docs/guides/restoring-files/).

## Still stuck

Open an issue at [github.com/ArunPrakashG/media-offload/issues](https://github.com/ArunPrakashG/media-offload/issues). Include the Nimbus version, your provider, the steps you took and the relevant log lines. Remove keys and tokens first.

> **Tip:** Set Minimum Log Level to `debug` while you reproduce a problem, then set it back to `info`.
