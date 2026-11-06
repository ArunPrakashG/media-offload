---
title: Connect Wasabi
nav: Wasabi
description: Create a Wasabi bucket and access keys, choose the matching region in Plume, test the connection, and offload your WordPress media to Wasabi storage.
section: providers
order: 6
logo: wasabi
---

Wasabi is S3-compatible storage that advertises no egress fees. Plume builds the endpoint from the region you select.

## 1. Create access keys

1. In the Wasabi console, create a bucket and note its region, for example `us-east-1` or `eu-central-1`.
2. Open **IAM → Access Keys** and create a key for a user that can read and write the bucket.
3. Copy the access key and the secret. The secret is shown once.

Wasabi buckets are private by default. For visitors to load files, add a public-read bucket policy, or serve through a CDN and enter its domain as the Custom Domain.

## 2. Fill in the setup wizard

Open **Media → Plume** and choose **Wasabi**.

| Field | Where to find it |
| --- | --- |
| Bucket | The bucket name from the Wasabi console. |
| Region | The bucket region, for example `us-east-1`, `us-east-2`, `us-central-1`, `eu-central-1` or `ap-northeast-1`. |
| Wasabi Access Key | Wasabi console → IAM → Access Keys. |
| Wasabi Secret Key | Shown once when you create the key. |
| Path Prefix (optional) | A folder inside the bucket, for example `media`. |
| Custom Endpoint (optional) | Leave empty. Plume builds `https://s3.<region>.wasabisys.com`. |
| Custom Domain (optional) | A CDN or custom domain for public URLs. |

## 3. Test and offload

Open **Tools** and select **Test Connection**. A working setup reports that the connection succeeded and write access is verified. Then run **Offload existing library** under **Bulk media jobs**, or queue files from the command line:

```bash
wp nimbus sync --limit=500
wp nimbus process-pending --limit=500
```

`sync` marks attachments as pending. `process-pending` offloads them. See [bulk migration](/docs/guides/bulk-migration/) for details.

## Notes

- Path-style requests are off for Wasabi. Plume sets this for you.
- The region in Plume must match the region of the bucket. A mismatch fails the connection test.

> **Tip:** Create a dedicated Wasabi user for Plume and limit it to this one bucket.
