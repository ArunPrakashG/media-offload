---
title: Connect Backblaze B2
nav: Backblaze B2
description: Create a Backblaze B2 bucket and application key, enter the key ID, key and region in Plume, then test the connection and offload your WordPress media.
section: providers
order: 4
logo: backblaze
---

Backblaze B2 offers an S3-compatible API at low storage cost. Plume builds the endpoint from your bucket's region.

## 1. Create an application key

1. In the Backblaze console, create a bucket under **Buckets**. Set it to public if visitors will load files directly.
2. Note the region in the bucket's endpoint URL. It looks like `s3.us-west-004.backblazeb2.com`, so the region is `us-west-004`.
3. Open **App Keys** and create a new application key. Limit it to your bucket and allow read and write.
4. Copy the `keyID` and the `applicationKey`. The application key is shown once.

Use an application key from **App Keys**. Your account ID and master key are not what Plume expects.

## 2. Fill in the setup wizard

Open **Media → Plume** and choose **Backblaze B2**.

| Field | Where to find it |
| --- | --- |
| Bucket | The bucket name from the Buckets page. |
| Region | Visible in the bucket endpoint URL, for example `us-west-004` or `eu-central-003`. |
| Application Key ID | Create one under App Keys in the Backblaze console. This is the `keyID`, not your account ID. |
| Application Key | The `applicationKey`. Shown once when you create it. |
| Path Prefix (optional) | A folder inside the bucket, for example `media`. |
| Custom Endpoint (optional) | Leave empty. Plume builds `https://s3.<region>.backblazeb2.com`. |
| Custom Domain (optional) | A CDN or custom domain for public URLs. |

## 3. Test and offload

Open **Tools** and select **Test Connection**. A working setup reports that the connection succeeded and write access is verified. Then run **Offload existing library** under **Bulk media jobs**, or queue files from the command line:

```bash
wp nimbus sync --limit=500
wp nimbus process-pending --limit=500
```

`sync` marks attachments as pending. `process-pending` offloads them. See [bulk migration](/docs/guides/bulk-migration/) for details.

## Notes

- Path-style requests are off for B2. Plume sets this for you.
- If you serve images through Cloudflare, set the Custom Domain to your proxied hostname. See [custom domain and CDN](/docs/guides/custom-domain/).

> **Tip:** Create a separate application key for Plume and restrict it to one bucket.
