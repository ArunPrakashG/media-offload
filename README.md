# Arun Prakash Media Offload

Custom WordPress plugin that offloads uploaded media to Amazon S3, Cloudflare R2, and other S3-compatible object storage while preserving thumbnail generation and URL rewriting. The main WordPress bootstrap lives in `media-offload.php`, domain logic currently lives under `includes/`, and the repository also contains legacy Python tooling metadata in `pyproject.toml` that should be isolated or removed during the refactor.

## Features

- Amazon S3, Cloudflare R2, and S3-compatible storage adapter support with optional path-style endpoints and custom domains
- Local adapter for debugging or non-production environments
- Attachment metadata sync that preserves WordPress-generated image sizes
- Optional removal of local files after successful offload
- URL rewriting for attachment URLs, `srcset`, and content references
- WP-CLI command `pt-media-offload deploy` to upload everything under `wp-content/uploads`
- WP-CLI command `pt-media-offload rewrite` to verify remote objects and rewrite DB domains
- WP-CLI command `pt-media-offload revert` to restore media locally and rewrite DB back to local URLs
- WP-CLI command `pt-media-offload config` to show/update provider credentials and delivery settings
- Per-attachment ignore toggle in the Media Library to keep selected files local-only and skip offload/sync processing
- Configurable path filters so you can allow only specific uploads folders, such as `{year}/**`, and ignore everything else
- Smart Slider 3 compatibility pass for `wp_nextend2_*` tables (including escaped JSON URLs and `$upload$/...` placeholders)
- Admin settings screen for credentials and behaviour toggles
- Audit-friendly logging + admin notices when credentials are misconfigured

## Requirements

- WordPress 6.0+
- PHP 8.1+
- AWS SDK for PHP (`aws/aws-sdk-php`) when using the S3 adapter. Install via Composer inside the WordPress container, e.g. `composer require aws/aws-sdk-php`.
- Valid S3 (or compatible) credentials with write access to the target bucket.

## Configuration

1. Activate the plugin inside WordPress.
2. Navigate to **Settings → Media Offload**.
3. Provide bucket, region, access and secret keys, and an optional prefix or custom domain.
4. Toggle "Serve From Storage" to rewrite public URLs.
5. Leave "Remove Local Copies" disabled in development until the workflow is verified.

### Cloudflare R2 Setup

The plugin now supports **Cloudflare R2** as a first-class provider (internally via S3-compatible APIs).

Use these values in **Settings → Media Offload**:

- **Provider**: `Cloudflare R2`
- **Bucket**: your bucket name (example: `synarch-group-media`)
- **R2 Account ID**: your Cloudflare account ID (example: `2648c6dbb6901794166cdf02b48b8aa2`)
- **Region**: `auto`
- **Access Key**: your **R2 API Token Access Key ID**
- **Secret Key**: your **R2 API Token Secret Access Key**
- **Session Token (optional)**: only for temporary STS-style S3 credentials. Leave empty for Cloudflare R2 API keys.
- **Custom Endpoint** (optional if Account ID is set):
  - `https://<account_id>.r2.cloudflarestorage.com`
  - Do **not** append the bucket name to this endpoint field.
- **Use Path Style**: enabled automatically for R2

If you use a public/custom domain in front of R2 (recommended for production), set it in **Custom Domain**.

> Note: Some S3-compatible providers/policies can deny `HeadObject` while still allowing read access.
> The plugin now falls back to a read-only byte-range `GetObject` probe for existence checks in that case.

### WP-CLI Configuration

Use WP-CLI when you want a repeatable, production-safe way to configure the plugin for cron/manual runs.

- Show current config:
  - `wp pt-media-offload config --show`
- Configure Cloudflare R2 and test immediately:
  - `wp pt-media-offload config --provider=r2 --bucket=your-bucket --r2-account-id=your-account-id --access-key=your-access-key --secret-key=your-secret-key --enabled=1 --serve-from-storage=1 --test-connection`
- Configure S3-compatible storage:
  - `wp pt-media-offload config --provider=s3 --bucket=your-bucket --region=ap-south-1 --access-key=your-access-key --secret-key=your-secret-key --endpoint=https://s3.example.com --use-path-style-endpoint=1 --enabled=1`
- Only sync standard year-based upload folders:
  - `wp pt-media-offload config --allowed-path-patterns='{year}/**'`
- Allow year-based uploads but still skip generated/cache folders:
  - `wp pt-media-offload config --allowed-path-patterns='{year}/**' --ignored-path-patterns='cache/**,smush/**'`

Helpful flags/options:

- `--enabled=1|0`
- `--serve-from-storage=1|0`
- `--remove-local-copies=1|0`
- `--off-hours-batch-enabled=1|0`
- `--off-hours-batch-time=03:00`
- `--prefix=media/uploads`
- `--custom-domain=https://cdn.example.com`
- `--allowed-path-patterns='{year}/**'`
- `--ignored-path-patterns='cache/**,smush/**'`
- `--test-connection`

### Ignoring Specific Files

Sometimes a file should always stay on the WordPress server even when the rest of the library is offloaded.

- Open the item in the Media Library attachment details view.
- Enable **Ignore from Media Offload**.
- The file will be skipped by:
  - nightly queue processing
  - admin URL update runs
  - future sync/offload attempts
  - local cleanup after offload

This is useful for files that must remain local for theme/plugin compatibility or site-specific server access.

### Limiting Which Upload Paths Are Offloaded

You can restrict syncing/offloading to specific relative paths under `wp-content/uploads`.

- **Allowed Paths**: optional allow-list, one pattern per line
- **Ignored Paths**: optional deny-list, one pattern per line
- supported wildcards/placeholders:
  - `*` = any characters except `/`
  - `**` = any nested path
  - `?` = any single character except `/`
  - `{year}` = 4-digit year
  - `{month}` = 2-digit month
  - `{day}` = 2-digit day

Examples:

- only standard year folders:
  - `{year}/**`
- multisite year folders:
  - `sites/*/{year}/**`
- skip generated/cache folders:
  - `cache/**`
  - `smush/**`

If **Allowed Paths** is empty, everything is eligible except anything matched by **Ignored Paths**.

### High-Traffic Optimization (Cloudflare + S3)

To minimize costs and server load for high-traffic sites:

1.  **Generate Thumbnails Locally**: Let WordPress generate all image sizes as usual.
2.  **Enable "Remove Local Copies"**: In the plugin settings, check this box. This ensures your web server disk stays empty.
3.  **Configure Cloudflare Proxy**:
    - Create a CNAME record in your DNS (e.g., `cdn.yourdomain.com`) pointing to your S3 bucket URL (e.g., `your-bucket.s3.amazonaws.com`).
    - In Cloudflare, ensure the "Orange Cloud" (Proxy) is enabled for this CNAME.
4.  **Set Custom Domain**:
    - In the plugin settings, set **Custom Domain** to `https://cdn.yourdomain.com`.
    - This routes all image traffic through Cloudflare's global CDN, saving you massive amounts of S3 bandwidth fees.

## Development

```bash
uv sync
# placeholder for future asset build workflow
```

At the moment, the plugin code ships from the repository root. As part of the refactor, the release process should build a clean distributable package instead of relying on the full development tree.

See `docs/refactor-roadmap.md` for the planned architecture and commercialization refactor.

## WP-CLI Migration Workflow (Uploads Directory)

### 1) Deploy uploads to object storage

Uploads files from **year-based upload directories only** (e.g. `2026/...`, `2026/02/...`, or multisite `sites/<id>/2026/...`) to the configured bucket while preserving relative paths.

Non-year plugin/system directories under `wp-content/uploads` are skipped by design.

- Command: `wp pt-media-offload deploy`
- Flags:
  - `--parallel=<n>` number of month-based subdirectories handled per batch
  - `--force` re-upload even if object already exists remotely

After each month-group batch is uploaded, the command verifies object existence in storage.

### 2) Rewrite DB URLs to storage domain

Verifies all attachment objects exist remotely first, then replaces local uploads domain in the DB.

After the global replacement, the plugin also runs a Smart Slider 3 pass on `wp_nextend2_*` tables to update escaped URL formats and convert `$upload$/...` references to the storage base URL.

- Command: `wp pt-media-offload rewrite`
- Flags:
  - `--dry-run` verify and preview only (no DB writes)
  - `--force` delete local files after successful rewrite

### 3) Revert back to local storage

Restores offloaded attachments from object storage to local uploads and rewrites DB URLs from remote domain back to local uploads domain.

During revert, Smart Slider 3 tables are also normalized for escaped URL variants so slider assets return cleanly to local upload URLs.

Before restore begins, the plugin performs preflight checks:

- verifies bucket objects are accessible and calculates total allocated restore size
- checks local server free disk space
- proceeds only when local free space is sufficient (includes a 10% safety buffer)

- Command: `wp pt-media-offload revert`
- Flags:
  - `--batch-size=<n>` number of attachments restored per batch (default `100`)

## Notes

- Remote deletions are tied to WordPress attachment deletion; no background syncing occurs yet.
- Failed uploads surface as admin notices and fall back to local storage.
- Additional storage providers can be added by implementing `Storage_Adapter` and wiring it through the adapter factory.
