---
title: Connect DigitalOcean Spaces
nav: DigitalOcean Spaces
description: Create a DigitalOcean Space and Spaces access keys, enter the region and keys in Nimbus, and offload your WordPress media with optional CDN delivery.
section: providers
order: 5
logo: digitalocean
---

DigitalOcean Spaces is S3-compatible storage with a built-in CDN option. Nimbus builds the Spaces endpoint from the region you choose.

## 1. Create Spaces keys

1. In the DigitalOcean control panel, create a Space. Pick a region close to your visitors.
2. Set file access to public, or plan to serve through the Space's CDN.
3. Go to **API → Spaces Keys** and generate a new key.
4. Copy the access key and the secret. The secret is shown once.

Spaces keys are separate from your DigitalOcean API tokens. A personal access token will not work here.

## 2. Fill in the setup wizard

Open **Media → Nimbus** and choose **DigitalOcean Spaces**.

| Field | Where to find it |
| --- | --- |
| Space Name (bucket) | The name of your Space. |
| Region | Spaces region slug, for example `nyc3`, `ams3`, `sgp1`, `fra1` or `blr1`. It is part of your Space URL. |
| Spaces Access Key | DigitalOcean control panel → API → Spaces Keys. |
| Spaces Secret Key | Shown once when you generate the key. |
| Path Prefix (optional) | A folder inside the Space, for example `media`. |
| Custom Endpoint (optional) | Leave empty to use the auto-built endpoint `https://<region>.digitaloceanspaces.com`. |
| Custom Domain (optional) | Your Spaces CDN or custom domain for public URLs. |

## 3. Test and offload

Open **Tools** and select **Test Connection**. A working setup reports that the connection succeeded and write access is verified. Then run **Offload existing library** under **Bulk media jobs**, or queue files from the command line:

```bash
wp nimbus sync --limit=500
wp nimbus process-pending --limit=500
```

`sync` marks attachments as pending. `process-pending` offloads them. See [bulk migration](/docs/guides/bulk-migration/) for details.

## Notes

- To use the Spaces CDN, enable it on the Space and enter the CDN address or your custom CDN domain as the Custom Domain.
- Path-style requests are off for Spaces. Nimbus sets this for you.

> **Tip:** Keep the Space and your server in nearby regions to speed up the first bulk migration.
