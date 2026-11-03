---
title: Setup wizard
nav: Setup wizard
description: "Walk through the three-step Nimbus setup wizard: connect storage, choose delivery behavior, and review before you finish. Includes the config import shortcut."
section: start
order: 2
---

The setup wizard runs the first time you open **Media → Nimbus** after activation. It has three steps and saves your progress as you move forward.

## Step 1. Connect your storage provider

Pick a provider and fill in the fields it asks for. The form changes with the provider.

| Field | Notes |
| --- | --- |
| Provider | One of the providers listed in this documentation, or Local (debug only) for testing. |
| Bucket | The bucket or Space name. It must already exist. |
| Region | Shown for providers that need one. |
| Path prefix (optional) | A folder inside the bucket, for example `media`. |
| Access key | The access key ID from your provider. |
| Secret key | The secret from your provider. |
| R2 Account ID or custom endpoint | Cloudflare R2 only. |
| Custom endpoint | S3-compatible storage and MinIO only. |
| Session token (optional) | For temporary STS credentials. |

Provider pages explain where each value lives:

- [Amazon S3](/docs/providers/amazon-s3/)
- [Cloudflare R2](/docs/providers/cloudflare-r2/)
- [Google Cloud Storage](/docs/providers/google-cloud-storage/)
- [Backblaze B2](/docs/providers/backblaze-b2/)
- [DigitalOcean Spaces](/docs/providers/digitalocean-spaces/)
- [Wasabi](/docs/providers/wasabi/)
- [MinIO](/docs/providers/minio/)
- [S3-compatible](/docs/providers/s3-compatible/)

Select **Continue to delivery** when the form is complete. If a required field is empty, the wizard stops with the message "Please complete the storage step before continuing" and lists what is missing.

### Already have a config file?

If you exported a config from another site, you can import it in this step instead of typing everything. See [config export and import](/docs/reference/config-export/). The file contains your keys, so handle it like a password.

## Step 2. Choose delivery behavior

This step controls what happens after files are in storage.

| Option | Meaning |
| --- | --- |
| Enable Nimbus after setup | Turns offloading on. Stored as `enabled`. |
| Serve media from remote storage | Rewrites media URLs to point at storage. Stored as `serve_from_storage`. |
| Use path-style requests | Sends `https://endpoint/bucket/key` instead of `https://bucket.endpoint/key`. Stored as `use_path_style_endpoint`. |
| Custom delivery domain (optional) | A CDN or custom domain for public URLs. Stored as `custom_domain`. |

Select **Continue to review**. The wizard does not test the connection. You can run the check later from **Tools → Connectivity → Test Connection**.

## Step 3. Review and finish

The last step shows a checklist of what you entered. If another offload plugin is active, it also shows a **Conflict review** with a checkbox you must tick to confirm you have handled it. Running two offload plugins at once can conflict and break media URLs.

Select **Finish setup and unlock dashboard**. Nimbus saves your settings and shows "Setup complete. Your dashboard is now unlocked and your storage preferences have been saved."

## After the wizard

1. Go to **Tools** and select **Test Connection**.
2. Read [Offloading media](/docs/guides/offloading/) to see when new uploads move.
3. Run a [bulk migration](/docs/guides/bulk-migration/) for existing files.

> **Tip:** Settings are not locked in. Everything in the wizard can be changed later under **Settings**.
