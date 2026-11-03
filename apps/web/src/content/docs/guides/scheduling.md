---
title: Nightly batch
nav: Nightly batch
description: Offload new uploads in one off-hours run with Nimbus. Set the batch time, worker count, batch size and claim timeout, or turn it off to offload right away.
section: guides
order: 9
---

The off-hours batch queues new uploads during the day and offloads them in one run overnight. It is on by default, so uploads are not offloaded immediately unless you turn it off.

## Settings

Go to **Media → Nimbus → Settings → Off-hours Batch Offload**.

| Setting | Default | Notes |
| --- | --- | --- |
| Enable Off-hours Batch | On | Turn it off to offload each upload right after it is added. |
| Batch Time | `03:00` | `HH:MM` in your site's timezone, set under Settings → General in WordPress. |
| Worker Count | 1 | Parallel workers, from 1 to 8. |
| Per-worker Batch Size | 100 | Attachments each worker takes per run, from 10 to 1000. |
| Claim TTL (seconds) | 1800 | How long a worker holds its attachments before others may take them, from 60 to 86400. |

Select **Save Settings** after you change them.

## How it runs

1. An upload finishes and is marked pending.
2. At the Batch Time, WP-Cron starts the workers.
3. Each worker claims a batch, offloads it and updates URLs in post content.
4. When nothing is pending, Nimbus clears the schedule.

A lock stops two runs from overlapping. It expires after 30 minutes if a run dies.

The batch looks at uploads from the last 48 hours. Older pending files wait until you run them yourself:

```bash
wp nimbus process-pending --hours=168 --limit=500
```

## Offload at upload time instead

Turn off **Enable Off-hours Batch**. Each new upload then schedules its own WP-Cron event and is offloaded as soon as WP-Cron runs.

## Check it is working

- The Media Library **Offload Status** column shows **Pending** until the run.
- The **Logs** tab records each run.
- If nothing happens at the set time, WP-Cron is probably not firing. See [troubleshooting](/docs/reference/troubleshooting/).

> **Tip:** On low-traffic sites, call WP-Cron from a real system cron so the 03:00 run starts on time.
