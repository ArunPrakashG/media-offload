---
title: Connect MinIO
nav: MinIO
description: Connect Plume to a self-hosted MinIO server with an endpoint URL, bucket and access key, then test the connection and offload your WordPress media.
section: providers
order: 7
logo: minio
---

MinIO is self-hosted, S3-compatible storage. Plume needs the full URL of your server, a bucket and an access key.

## 1. Create credentials

1. In the MinIO console, create a bucket.
2. Create an access key under **Access Keys**. A root user key works, but a key scoped by policy to this bucket is safer.
3. Copy the access key and the secret. The secret is shown once.
4. Make sure the server is reachable over HTTPS from your WordPress host, and from your visitors if you serve files directly.

For public delivery, set an anonymous download policy on the bucket, or put a reverse proxy or CDN in front and enter its domain as the Custom Domain.

## 2. Fill in the setup wizard

Open **Media → Plume** and choose **MinIO**.

| Field | Where to find it |
| --- | --- |
| Bucket | The bucket name from the MinIO console. |
| MinIO Endpoint URL | The full URL of your server, for example `https://minio.example.com`. |
| Access Key | The root user or a policy-scoped key from the MinIO console. |
| Secret Key | Shown once when you create the key. |
| Region (optional) | Leave as `us-east-1` or any value your server accepts. |
| Path Prefix (optional) | A folder inside the bucket, for example `media`. |
| Custom Domain (optional) | The public address for files, if different from the endpoint. |

Plume turns on path-style requests for MinIO and ignores any session token.

## 3. Test and offload

Open **Tools** and select **Test Connection**. A working setup reports that the connection succeeded and write access is verified. Then run **Offload existing library** under **Bulk media jobs**, or queue files from the command line:

```bash
wp nimbus sync --limit=500
wp nimbus process-pending --limit=500
```

`sync` marks attachments as pending. `process-pending` offloads them. See [bulk migration](/docs/guides/bulk-migration/) for details.

## Notes

- A self-signed certificate on the MinIO server will make connections fail. Use a certificate your WordPress host trusts.
- The endpoint is always visible for MinIO. You do not need to open the advanced fields.

> **Tip:** Back up the MinIO data volume. Once local copies are removed, the bucket is the only copy of your media.
