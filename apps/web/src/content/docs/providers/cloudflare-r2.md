---
title: Connect Cloudflare R2
nav: Cloudflare R2
description: Create an R2 bucket and API token, enter your Account ID and keys in Nimbus, and set a public domain so offloaded WordPress media loads for visitors.
section: providers
order: 2
logo: cloudflare
---

Cloudflare R2 is S3-compatible storage with no egress fees. Nimbus has a dedicated R2 profile, so you only need an Account ID and an API token.

## 1. Create an API token

1. In the Cloudflare dashboard, go to **R2** and create a bucket.
2. Open **R2 → Manage API tokens** and create a token.
3. Choose **Object Read & Write** and limit it to your bucket.
4. Copy the Access Key ID and the Secret Access Key. The secret is shown once.
5. Copy your Account ID from the right sidebar of the dashboard home page, or from **R2 → Overview**.

Then make the bucket public. Under the bucket's **Settings**, either connect a custom domain or enable the `r2.dev` public URL. You will enter that address as the Custom Domain in the next step.

## 2. Fill in the setup wizard

Open **Media → Nimbus** and choose **Cloudflare R2**.

| Field | Where to find it |
| --- | --- |
| Bucket | The bucket name from the R2 page. |
| R2 Account ID | Right sidebar of the dashboard home page, or R2 → Overview. |
| Access Key | From your R2 API token. Create one under R2 → Manage API tokens. |
| Secret Key | Shown once when you create the token. |
| Path Prefix (optional) | A folder inside the bucket, for example `media`. |
| Custom Endpoint (optional) | Leave empty to build the endpoint from the Account ID, or enter a full R2 endpoint URL. |
| Custom Domain | Your R2 custom domain or `r2.dev` URL, for example `https://media.example.com`. |

Nimbus sets the region to `auto`, builds the endpoint as `https://<account-id>.r2.cloudflarestorage.com`, and turns on path-style requests for you.

## 3. Test and offload

Open **Tools** and select **Test Connection**. A working setup reports that the connection succeeded and write access is verified. Then run **Offload existing library** under **Bulk media jobs**, or queue files from the command line:

```bash
wp nimbus sync --limit=500
wp nimbus process-pending --limit=500
```

`sync` marks attachments as pending. `process-pending` offloads them. See [bulk migration](/docs/guides/bulk-migration/) for details.

## Notes

- Without a Custom Domain, Nimbus generates URLs from the account endpoint. That endpoint is not public, so images will not load. Always set the Custom Domain for R2.
- `r2.dev` URLs are rate limited and meant for testing. Use a custom domain on a Cloudflare zone for production.
- If your domain is on Cloudflare, you can also [purge the cache](/docs/guides/cache-purge/) when files change.

> **Tip:** Scope the API token to the single bucket Nimbus uses.
