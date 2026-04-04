-- Plugin release registry.
-- Each row represents a published version of a product that can be delivered
-- to WordPress sites via the built-in update mechanism.
--
-- Deployment flow:
--   1. Build the plugin zip and upload it to S3/R2.
--   2. POST /admin/v1/releases to register the new version here.
--   3. WordPress sites call GET /v1/updates/check on the next update cycle
--      and receive the new version metadata + download URL.

CREATE TABLE plugin_releases (
    id              UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    product_id      UUID        NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    version         TEXT        NOT NULL,               -- semver, e.g. "0.2.0"
    download_url    TEXT        NOT NULL,               -- direct/presigned URL to the plugin zip
    changelog       TEXT        NOT NULL DEFAULT '',    -- markdown changelog for this version
    min_wp_version  TEXT        NOT NULL DEFAULT '6.0', -- minimum WordPress version required
    min_php_version TEXT        NOT NULL DEFAULT '8.0', -- minimum PHP version required
    tested_up_to    TEXT        NOT NULL DEFAULT '',    -- highest WP version this was tested with
    released_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    UNIQUE (product_id, version)
);

CREATE INDEX idx_plugin_releases_product_released
    ON plugin_releases(product_id, released_at DESC);
