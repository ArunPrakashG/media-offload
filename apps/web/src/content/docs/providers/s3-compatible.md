---
title: Connect S3-compatible storage
nav: S3-compatible
description: Connect any S3-compatible object store to Nimbus with a custom endpoint, region, bucket and keys, for providers that do not have a dedicated profile.
section: providers
order: 8
---

Use this profile for any provider that speaks the S3 API but has no dedicated Nimbus profile. You supply the endpoint yourself.

## 1. Create credentials

1. Create a bucket with your provider.
2. Create an access key and secret that can list, read, write and delete objects in it.
3. Find the provider's S3 endpoint URL and region in its documentation.
4. Check whether the provider expects path-style requests. Many self-hosted stores do.

## 2. Fill in the setup wizard

Open **Media → Nimbus** and choose **S3-compatible**.

| Field | Where to find it |
| --- | --- |
| Bucket | The bucket name from your provider. |
| Region | The region your provider documents. Some providers accept any string. |
| Custom Endpoint | The full base URL, for example `https://s3.example.com`. |
| Access Key | The access key ID from your provider. |
| Secret Key | The secret from your provider. |
| Session Token (optional) | Only if your provider issues temporary credentials. |
| Path Prefix (optional) | A folder inside the bucket, for example `media`. |
| Custom Domain (optional) | The public address for files, if different from the endpoint. |

Path-style requests are not set for you here. Under **Show advanced connection fields** on the Settings tab, turn on **Use Path Style** if your provider needs it.

## 3. Test and offload

Open **Tools** and select **Test Connection**. A working setup reports that the connection succeeded and write access is verified. Then run **Offload existing library** under **Bulk media jobs**, or queue files from the command line:

```bash
wp nimbus sync --limit=500
wp nimbus process-pending --limit=500
```

`sync` marks attachments as pending. `process-pending` offloads them. See [bulk migration](/docs/guides/bulk-migration/) for details.

## Notes

- If the endpoint is a Cloudflare R2 URL, Nimbus switches to R2 behavior. Use the [Cloudflare R2](/docs/providers/cloudflare-r2/) page instead.
- Nimbus does not set object ACLs. Make sure the bucket or your CDN serves the files publicly.
- If Test Connection fails, read the "Suggested checks" under the result. They name the likely cause.

> **Tip:** If the test fails with a signature error, try toggling path-style requests first.
