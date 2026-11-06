---
title: WP-CLI commands
nav: WP-CLI
description: "Every wp nimbus subcommand with its flags and defaults: config, sync, process-pending, deploy, download, rewrite, revert, replace-urls and offload-single."
section: reference
order: 2
---

Plume adds a `wp nimbus` command group. All commands run against the site selected with `--url` on multisite. Run them from the WordPress root as a user that can write to the uploads folder.

## Commands

| Command | What it does |
| --- | --- |
| `wp nimbus config` | Show or update Plume plugin settings. |
| `wp nimbus sync` | Queue attachments that are missing remote metadata. |
| `wp nimbus process-pending` | Process queued pending offloads. |
| `wp nimbus deploy` | Offload one or more attachments to remote storage. |
| `wp nimbus offload-single` | Offload a single attachment by ID. |
| `wp nimbus download` | Download offloaded attachments back to local storage. |
| `wp nimbus rewrite` | Rewrite local media URLs in post content to remote URLs. |
| `wp nimbus revert` | Restore remote URLs in post content back to local URLs. |
| `wp nimbus replace-urls` | Replace one URL with another in post content. |

## config

```bash
wp nimbus config
wp nimbus config --<setting_key>=<value>
```

With no arguments it prints all settings as YAML. `access_key`, `secret_key`, `session_token` and `cloudflare_api_token` show as `********`.

With one or more `--key=value` pairs it updates those settings and prints "Settings updated." Unknown keys give the warning "Ignoring unknown setting". If no valid key was given, it prints "No valid settings were provided." Keys are listed in the [settings reference](/docs/reference/settings/).

Values are passed as text. Use `1` and `0` for on and off. The text `false` counts as on.

```bash
wp nimbus config --provider=r2 --bucket=my-media --serve_from_storage=1
```

## sync

```bash
wp nimbus sync [<id>...] [--ids=<list>] [--limit=<n>]
```

Marks attachments that are not offloaded as pending. It does not copy files itself. Pass IDs as arguments or as a comma separated `--ids` list. With no IDs it picks attachments in ID order, 100 by default. It prints "Queued N attachment(s) for sync." or "No attachments found to sync."

## process-pending

```bash
wp nimbus process-pending [--hours=<n>] [--limit=<n>]
```

Offloads attachments that were marked pending in the last `--hours` hours. Defaults: `--hours=24`, `--limit=500`. It prints a summary with total, success, failed and skipped counts.

## deploy

```bash
wp nimbus deploy [<id>...] [--ids=<list>] [--limit=<n>]
```

Offloads the attachments you name now, without waiting for a queue. With `--limit` and no IDs it takes the first N attachments by ID. With neither, it warns "No attachment IDs resolved for deploy command." It ends with "Deploy complete. Success: N, Failed: N".

## offload-single

```bash
wp nimbus offload-single <id>
```

Offloads one attachment. It prints "Attachment N offloaded." or "Failed to offload attachment N." Without an ID it asks you to provide one.

## download

```bash
wp nimbus download [<id>...] [--ids=<list>] [--limit=<n>]
```

Restores offloaded attachments to the uploads folder and clears their offload record. It takes IDs the same way as `deploy`. It ends with "Download complete. Success: N, Failed: N". It does not change URLs in post content. Run `revert` for that.

## rewrite

```bash
wp nimbus rewrite [--limit=<n>]
```

Replaces local media URLs in post content with remote URLs, in up to `--limit` posts. The default is 500. It prints "Rewrote media URLs in N post(s)." If storage is not configured it stops with "Unable to determine remote base URL. Configure storage first."

## revert

```bash
wp nimbus revert [--limit=<n>]
```

The reverse of `rewrite`. It prints "Reverted media URLs in N post(s)." The default limit is 500.

## replace-urls

```bash
wp nimbus replace-urls --from=<url> --to=<url> [--limit=<n>]
```

Replaces `--from` with `--to` in `post_content`, in up to `--limit` posts. The default is 500. Revisions and menu items are skipped. Without both flags it stops with "Both --from and --to are required." It prints "Replaced URLs in N post(s)."

```bash
wp nimbus replace-urls --from=https://old.example.com --to=https://cdn.example.com
```

> **Tip:** Run `wp db export` before `rewrite`, `revert` or `replace-urls`. They edit post content in place.
