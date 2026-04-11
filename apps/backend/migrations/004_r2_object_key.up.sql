-- Rename download_url to r2_object_key.
-- The column now stores a private R2 bucket object key (e.g.
-- "releases/nimbus-pro/0.2.0/nimbus-pro.zip") rather than a full URL.
-- The backend generates a short-lived presigned GET URL at response time so
-- the pro plugin zip is never publicly accessible.
ALTER TABLE plugin_releases RENAME COLUMN download_url TO r2_object_key;
