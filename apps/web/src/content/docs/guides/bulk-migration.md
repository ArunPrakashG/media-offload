---
title: Bulk migration
nav: Bulk migration
description: Move your existing WordPress media library to object storage with the Plume bulk job or WP-CLI, including pause, resume, retry and what to check afterward.
section: guides
order: 2
---

New uploads move on their own. Files that were already in your library need a one-time bulk job.

## Before you start

- Run **Tools → Test Connection** and get a success message.
- Make sure **Enable Offloading** is on under **Settings**.
- Take a backup.
- Run the job when traffic is low. The job uses WP-Cron on your own server.

## Run the job from the admin

1. Go to **Media → Plume → Tools**.
2. Under **Bulk media jobs**, select **Offload existing library**.
3. Watch the progress counts: Processed out of Total, Succeeded, Failed and Skipped.

Controls:

| Button | What it does |
| --- | --- |
| Pause | Stops after the current batch. |
| Resume | Continues a paused job. |
| Retry failed items | Runs the failed items from the last job again. |
| Restore local copies | The reverse job. See [restoring files](/docs/guides/restoring-files/). |

Only one bulk job can run at a time. Starting a second one returns "A bulk media job is already running". A job ends as completed, completed with errors, or failed.

Each step processes up to 100 attachments, then schedules the next step about two seconds later. If progress stalls, WP-Cron is probably not running. See [troubleshooting](/docs/reference/troubleshooting/).

## Run it from the command line

On large libraries the CLI is easier to monitor. Queue the attachments, then process them:

```bash
wp nimbus sync --limit=500
wp nimbus process-pending --hours=24 --limit=500
```

`sync` marks up to `--limit` attachments that are not offloaded as pending. The default is 100. `process-pending` offloads pending attachments queued in the last `--hours` hours. Repeat both commands until `sync` reports that there is nothing left.

To offload specific attachments directly:

```bash
wp nimbus deploy --ids=12,15,18
wp nimbus offload-single 12
```

## Update URLs stored in content

With **Serve From Storage** on, Plume rewrites media URLs when pages render. URLs saved inside post content, page builder data and sliders can also be rewritten in the database:

```bash
wp nimbus rewrite --limit=500
```

For page builders and sliders, use the [Integrations](/docs/guides/integrations/) tab.

## Check the result

1. Open a few posts and load their images in a private window.
2. Check the **Offload Status** column in the Media Library.
3. Read the **Logs** tab for warnings and errors.

> **Tip:** Keep Remove Local Copies off until the whole library is offloaded and you have checked the front end.
