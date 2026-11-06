---
title: Connect Google Cloud Storage
nav: Google Cloud Storage
description: Create a Google Cloud Storage bucket and HMAC keys for a service account, then enter them in Plume to offload and serve your WordPress media library.
section: providers
order: 3
logo: gcs
---

Plume talks to Google Cloud Storage through its S3-compatible interoperability API. That means you use HMAC keys, not a JSON service account file.

## 1. Create HMAC keys

1. In the Cloud Console, create a bucket. Note its location, for example `us-central1` or `europe-west1`.
2. Enable **Uniform bucket-level access** on the bucket.
3. Create a service account for Plume and give it the **Storage Object Admin** role on the bucket.
4. Open **IAM → Service Accounts**, select the account, and go to the **HMAC Keys** tab. Create a key.
5. Copy the Access Key and the Secret. The secret is shown once, when you create the key.

For visitors to load files, grant `allUsers` the **Storage Object Viewer** role on the bucket, or serve through a CDN and enter its domain as the Custom Domain.

## 2. Fill in the setup wizard

Open **Media → Plume** and choose **Google Cloud Storage**.

| Field | Where to find it |
| --- | --- |
| Bucket Name | The bucket name from the Cloud Console. |
| Location | The bucket location, for example `us-central1` or `europe-west1`. |
| HMAC Access Key | IAM → Service Accounts → select account → HMAC Keys tab. |
| HMAC Secret | Shown once when you create the HMAC key. |
| Path Prefix (optional) | A folder inside the bucket, for example `media`. |
| Custom Domain (optional) | A CDN or custom domain for public URLs. |

Plume sets the endpoint to `https://storage.googleapis.com` and turns on path-style requests for you.

## 3. Test and offload

Open **Tools** and select **Test Connection**. A working setup reports that the connection succeeded and write access is verified. Then run **Offload existing library** under **Bulk media jobs**, or queue files from the command line:

```bash
wp nimbus sync --limit=500
wp nimbus process-pending --limit=500
```

`sync` marks attachments as pending. `process-pending` offloads them. See [bulk migration](/docs/guides/bulk-migration/) for details.

## Notes

- Uniform bucket-level access must be on. Per-object ACLs are not used.
- If Test Connection fails with a permission error, check that the service account has the role on this bucket and that you copied the HMAC key, not the service account email.

> **Tip:** Rotate HMAC keys by creating a new key, updating Plume, then deactivating the old one.
