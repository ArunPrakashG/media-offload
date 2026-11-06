---
title: Config export and import
nav: Config export
description: Export Plume settings to a JSON file and import them on another site. Learn what the file contains, which settings are left out, and how to handle secrets.
section: reference
order: 4
---

You can save your Plume settings to a JSON file and load them on another site. This is useful for staging copies, multisite networks and moving to a new server.

## Export

1. Go to **Media → Plume → Tools**.
2. Under **Configuration backup**, select **Download config.json**.

The file is named like `nimbus-config-20261103-091500.json`.

```json
{
  "version": "1.0",
  "plugin": "nimbus",
  "exported_at": "2026-11-03T09:15:00+00:00",
  "has_credentials": true,
  "settings": {
    "provider": "r2",
    "bucket": "my-media",
    "serve_from_storage": true
  }
}
```

The real file lists every exported key. `has_credentials` tells you whether keys are included.

## What is included

The file holds the connection and delivery settings: `provider`, `bucket`, `r2_account_id`, `region`, `access_key`, `secret_key`, `session_token`, `prefix`, `endpoint`, `use_path_style_endpoint`, `remove_local_copies`, `serve_from_storage`, `custom_domain` and `enabled`.

It also holds the cache purge settings, including `cloudflare_zone_id` and `cloudflare_api_token`, the path filters, and `offload_min_size_kb`, `offload_allowed_mime_types`, `storage_class`, `visibility` and `delete_remote_on_wp_delete`.

It does not include the off-hours batch settings or the logging settings.

## Handle the file like a password

The export contains your access key, secret key and Cloudflare token as plain text. Do not commit it to a repository, attach it to a ticket, or leave it in a shared folder. Delete it after use. If it leaks, rotate the keys at your provider.

## Import

1. Go to **Media → Plume → Tools → Configuration backup**.
2. Choose a `.json` file and select **Import**.
3. Check the settings, then save if you want to change anything.

Rules:

- The file must be `.json` and no larger than 512 KB.
- It must contain `"plugin": "nimbus"`.
- Only known setting keys are read. Others are ignored.
- The import replaces the current settings straight away. Plume reports "Configuration imported successfully. Review the settings below and save if needed."

The first step of the [setup wizard](/docs/setup-wizard/) also accepts a config file to fill in the form.

> **Tip:** On a staging site, import the file, then run Test Connection before you turn anything on.
