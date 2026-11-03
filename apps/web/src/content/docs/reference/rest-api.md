---
title: REST API
nav: REST API
description: Reference for the Nimbus REST API under /wp-json/nimbus/v1, with every route, method, parameter and permission, plus examples for jobs, offloads and purges.
section: reference
order: 3
---

Nimbus registers its REST routes under the `nimbus/v1` namespace. The admin screens use the same routes.

## Base URL and permissions

```text
https://example.com/wp-json/nimbus/v1
```

Every route requires a logged-in user with the `manage_options` capability. In practice that means an administrator. Authenticate with the cookie and `X-WP-Nonce` header from inside WordPress, or with an application password from outside.

## Routes

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/status` | Plugin state, onboarding, jobs, integrations and statistics. |
| GET | `/statistics` | Offload counts. |
| GET | `/integrations` | Detected integrations. |
| GET | `/jobs` | Current and recent bulk jobs. |
| GET | `/jobs/stream` | Job updates as a server-sent event stream. |
| POST | `/jobs/start` | Start a bulk job. |
| POST | `/jobs/pause` | Pause the running job. |
| POST | `/jobs/resume` | Resume a paused job. |
| POST | `/jobs/retry-failed` | Retry the failed items of the last job. |
| POST | `/offload/pending` | Offload attachments marked pending. |
| POST | `/offload/item/<id>` | Offload one attachment. |
| POST | `/integrations/<key>/rewrite` | Rewrite URLs for an integration. |
| POST | `/storage/create-bucket` | Create a bucket. |
| POST | `/storage/presign` | Create a signed URL for an offloaded file. |
| POST | `/cache/purge` | Queue a cache purge. |

## Status

`GET /status` returns `plugin` with `version`, `enabled`, `provider`, `bucket`, `serve_from_storage` and `credentials_ready`. It also returns `onboarding`, `jobs`, `integrations` and `statistics`.

## Jobs

### POST /jobs/start

| Parameter | Type | Notes |
| --- | --- | --- |
| `type` | string | Required. `offload_existing`, `restore_local` or `retry_failed`. |
| `batch_size` | integer | Optional. Default 50, from 1 to 1000. Nimbus caps each step at 100. |

Returns `202`. Only one job can run at a time. Starting another returns `409`. Offload and retry jobs need Enable Offloading on and valid credentials.

```bash
curl -X POST https://example.com/wp-json/nimbus/v1/jobs/start \
  -H "Content-Type: application/json" \
  -u "admin:APPLICATION_PASSWORD" \
  -d '{"type":"offload_existing"}'
```

`/jobs/pause`, `/jobs/resume` and `/jobs/retry-failed` take no parameters. Resume and retry return `202`.

### GET /jobs/stream

Streams job state. Optional `timeout` in seconds, from 5 to 55, default 30. The stream ends with `{"done":true}`.

## Offload

### POST /offload/pending

| Parameter | Type | Notes |
| --- | --- | --- |
| `hours` | integer | Default 24. Look back this many hours. |
| `limit` | integer | Default 100, up to 1000. |
| `background` | boolean | Default `true`. |

In the background it returns `202` with `{"scheduled":true,"hours":24,"limit":100}`. With `background` set to `false` it runs now and returns `{"scheduled":false,"result":{...}}`. If offloading is off or credentials are missing it returns `400` with `nimbus_not_ready`.

### POST /offload/item/<id>

`background` is optional and defaults to `false`. Returns `{"scheduled":false,"attachment_id":12,"success":true}`, or `{"scheduled":true,"attachment_id":12}` when run in the background.

## Integrations

### POST /integrations/<key>/rewrite

`key` is `smart_slider`, `slider_revolution`, `elementor` or `nextgen_gallery`. Errors: `nimbus_integration_unsupported` for an unknown key, `nimbus_credentials_missing` when storage is not configured.

## Storage

### POST /storage/create-bucket

| Parameter | Type | Notes |
| --- | --- | --- |
| `name` | string | Required. |
| `region` | string | Optional. |

Returns `201` with `{"created":true,"name":"..."}`. Errors: `nimbus_credentials_missing` (`400`) and `nimbus_create_bucket_failed` (`502`). There is no screen for this in the admin.

### POST /storage/presign

| Parameter | Type | Notes |
| --- | --- | --- |
| `attachment_id` | integer | Required. |
| `ttl` | integer | Seconds. Default 3600, from 60 to 86400. |

Returns `{"url":"...","expires_in":3600}`. If the attachment is not offloaded it returns `404` with `nimbus_not_offloaded`.

## Cache

### POST /cache/purge

| Parameter | Type | Notes |
| --- | --- | --- |
| `urls` | array | URLs to purge. |
| `purge_all` | boolean | Purge everything. Treated as true when `urls` is empty. |
| `reason` | string | Default `manual_api`. Used in logs. |

Returns `202`. The purge is queued. See [cache purge](/docs/guides/cache-purge/).

> **Tip:** Use an application password created for a dedicated administrator account, and revoke it when the job is done.
