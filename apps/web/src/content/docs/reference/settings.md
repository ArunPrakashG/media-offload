---
title: Settings reference
nav: Settings
description: Every Plume setting with its admin label, option key, default value and where to change it, including the settings that have no screen in the admin.
section: reference
order: 1
---

Plume stores all settings in one WordPress option, `nimbus_settings`. This page lists each key, the label you see in the admin, and its default. Per-attachment data lives in the `_nimbus` post meta.

## Settings tab

Open **Media → Plume → Settings**.

### Storage Connection

| Label | Key | Default | Notes |
| --- | --- | --- | --- |
| Enable Offloading | `enabled` | On | Cannot be turned on without complete credentials. |
| Provider | `provider` | `s3` | One of `s3`, `r2`, `gcs`, `backblaze_b2`, `digitalocean_spaces`, `wasabi`, `minio`, `s3_compatible` or `local`. `local` is for debugging only. |
| Bucket | `bucket` | Empty | Bucket or Space name. |
| Account ID | `r2_account_id` | Empty | Cloudflare R2 only. |
| Region | `region` | Empty | Fixed to `auto` for R2. |
| Access Key | `access_key` | Empty | Stored encrypted. See [How credentials are stored](#how-credentials-are-stored). |
| Secret Key | `secret_key` | Empty | Stored encrypted. |
| Session Token | `session_token` | Empty | Optional. Hidden under **Show advanced connection fields**. Stored encrypted. |
| Path Prefix | `prefix` | Empty | Folder inside the bucket. |
| Custom Endpoint | `endpoint` | Empty | Built for you on most providers. Always shown for S3-compatible and MinIO. |
| Use Path Style | `use_path_style_endpoint` | Off | Forced on for R2, GCS and MinIO. |

### Delivery

| Label | Key | Default | Notes |
| --- | --- | --- | --- |
| Serve From Storage | `serve_from_storage` | On | Rewrites media URLs to the storage address. Needs valid credentials. |
| Custom Domain | `custom_domain` | Empty | Shown when Serve From Storage is on. Include `https://`. See [custom domain](/docs/guides/custom-domain/). |
| Remove Local Copies | `remove_local_copies` | Off | Deletes local files after a successful offload. Needs valid credentials. |

### Off-hours Batch Offload

| Label | Key | Default | Range |
| --- | --- | --- | --- |
| Enable Off-hours Batch | `off_hours_batch_enabled` | On | |
| Batch Time | `off_hours_batch_time` | `03:00` | `HH:MM` |
| Worker Count | `off_hours_worker_count` | 1 | 1 to 8 |
| Per-worker Batch Size | `off_hours_worker_batch_size` | 100 | 10 to 1000 |
| Claim TTL (seconds) | `off_hours_claim_ttl_seconds` | 1800 | 60 to 86400 |

See [nightly batch](/docs/guides/scheduling/).

## Advanced tab

Open **Media → Plume → Advanced**.

### Path Filters

| Label | Key | Default |
| --- | --- | --- |
| Allowed Paths | `allowed_path_patterns` | Empty, which allows everything |
| Ignored Paths | `ignored_path_patterns` | Empty |

### Cache Purging

| Label | Key | Default |
| --- | --- | --- |
| Enable Cloudflare Cache Purging | `purge_cloudflare_cache_on_delete` | Off |
| Cloudflare Zone ID | `cloudflare_zone_id` | Empty |
| Cloudflare API Token | `cloudflare_api_token` | Empty |
| Enable LiteSpeed Cache Purging | `purge_litespeed_cache` | Off |
| Enable WP Fastest Cache Purging | `purge_wp_fastest_cache` | Off |
| Enable Proxy / Nginx Cache Purging | `purge_proxy_cache` | Off |
| Proxy Purge Method | `proxy_cache_purge_method` | `PURGE` (also `GET` or `POST`) |
| Proxy Purge URL Templates | `proxy_cache_purge_urls` | Empty |
| Proxy Full Purge Endpoints | `proxy_cache_purge_all_urls` | Empty |

See [cache purge](/docs/guides/cache-purge/).

## Logs tab

Open **Media → Plume → Logs** and expand **Logging settings**.

| Label | Key | Default | Range |
| --- | --- | --- | --- |
| Enable Logging | `logging_enabled` | On | |
| Minimum Log Level | `log_level` | `info` | `debug`, `info`, `notice`, `warning`, `error`, `critical` |
| Retained Entries | `log_max_entries` | 2000 | 100 to 10000 |
| Mirror To PHP Error Log | `log_to_error_log` | On | |

## How credentials are stored

Plume encrypts the access key, secret key, session token and Cloudflare API token before it saves them in the `nimbus_settings` option. Someone who reads the database or a backup of it sees only ciphertext.

The encryption key comes from `LOGGED_IN_KEY` and `LOGGED_IN_SALT` in `wp-config.php`. To use a key of your own, add this line to `wp-config.php` before the "stop editing" line:

```php
define('NIMBUS_ENCRYPTION_KEY', 'a long random string');
```

If that key or the site's security keys change, Plume can no longer read the saved credentials. It shows a notice, and you enter the keys again in **Media → Plume → Settings**. Settings saved by older versions are encrypted the next time an admin opens the dashboard.

A [config export](/docs/reference/config-export/) still contains the credentials in plain text, so keep export files private.

## Settings without a screen

These keys work, but you can only change them with [WP-CLI](/docs/reference/wp-cli/) or a [config import](/docs/reference/config-export/).

| Key | Default | Notes |
| --- | --- | --- |
| `offload_min_size_kb` | `0` | Files smaller than this are not offloaded. `0` means no limit. |
| `offload_allowed_mime_types` | Empty | Comma separated list. Wildcards like `image/*` work. Empty allows all types. |
| `storage_class` | `STANDARD` | Sent as the storage class on upload. Leave it unless your provider documents other values. |
| `delete_remote_on_wp_delete` | On | Deletes remote files when an attachment is deleted. |
| `visibility` | `public-read` | Stored, but Plume does not apply it to uploads. |

```bash
wp nimbus config --offload_min_size_kb=50
```

> **Tip:** Run `wp nimbus config` with no arguments to print the current settings. Keys and tokens are masked.
