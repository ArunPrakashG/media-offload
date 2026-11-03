---
title: Install Nimbus
nav: Installation
description: Install the Nimbus WordPress plugin from a ZIP file, activate it, and open the setup wizard under Media → Nimbus. Needs WordPress 6.0 and PHP 8.1 or newer.
section: start
order: 1
---

Nimbus moves your WordPress media library to object storage and serves it from there. This page covers installing the plugin and what to expect on first launch.

## Requirements

- WordPress 6.0 or newer.
- PHP 8.1 or newer.
- A bucket on one of the [supported providers](/docs/providers/amazon-s3/), with an access key that can write to it.
- Working WP-Cron, or a real system cron that calls it. Offloading and bulk jobs run through WP-Cron.

## Install the plugin

Nimbus is not listed in the WordPress.org directory. You install it from a ZIP file.

1. Download the plugin ZIP from [github.com/ArunPrakashG/media-offload/releases](https://github.com/ArunPrakashG/media-offload/releases).
2. In WordPress, go to **Plugins → Add New → Upload Plugin**.
3. Choose the ZIP, select **Install Now**, then **Activate Plugin**.

After activation you land in the setup wizard at **Media → Nimbus**. Nimbus is open source under the MIT license.

### Upgrading from 0.3.2 or earlier

From 0.3.3 the plugin is called **Nimbus Media Offload** and lives in a `nimbus-media-offload` folder instead of `nimbus`, to match its WordPress.org name. Uploading the new ZIP installs it next to the old copy. Both copies share the same settings and offloaded files, so:

1. Deactivate the old **Nimbus** plugin.
2. Activate **Nimbus Media Offload**.
3. Remove the old `wp-content/plugins/nimbus` folder over SFTP or SSH.

Do not use **Delete** on the old copy in the Plugins screen. That runs its uninstall cleanup, which removes the settings and offload records the new copy uses.

## What happens on first launch

Until you finish the wizard, the Nimbus page shows the **Set up Nimbus** wizard instead of the dashboard. The [setup wizard](/docs/setup-wizard/) has three steps: Storage, Delivery and Review.

Once the wizard is done, the page shows these tabs:

| Tab | What it is for |
| --- | --- |
| Overview | Offload statistics, setup health and upload folders. |
| Settings | Storage connection, delivery and the off-hours batch. |
| Advanced | Path filters and cache purging. |
| Integrations | Page builder and slider plugins that store media URLs. |
| Tools | Test connection, bulk jobs, URL updates and config backup. |
| Logs | Plugin log viewer and logging settings. |

## Next steps

1. Pick your provider and create credentials, for example [Amazon S3](/docs/providers/amazon-s3/) or [Cloudflare R2](/docs/providers/cloudflare-r2/).
2. Run the [setup wizard](/docs/setup-wizard/).
3. Select **Test Connection** under Tools.
4. Move your existing library with the [bulk migration](/docs/guides/bulk-migration/) job.

> **Tip:** Back up your site, or at least your `wp-content/uploads` folder, before the first bulk migration.
