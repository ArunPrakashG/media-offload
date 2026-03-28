#!/bin/sh
set -eu

WP_PATH="${WP_PATH:-/var/www/html}"
SITE_URL="${SITE_URL:-http://localhost:8080}"
SITE_TITLE="${SITE_TITLE:-Media Offload Lab}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-password}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@example.com}"
PLUGIN_SLUG="media-offload"

wp_cmd() {
  wp --allow-root --path="$WP_PATH" "$@"
}

log() {
  printf '[media-offload-setup] %s\n' "$1"
}

log 'Waiting for WordPress core files and wp-config.php...'
until [ -f "$WP_PATH/wp-load.php" ] && [ -f "$WP_PATH/wp-config.php" ]; do
  sleep 2
  log 'Still waiting for WordPress bootstrap files...'
done

log 'Waiting for database connectivity...'
until wp --allow-root --skip-plugins --skip-themes --path="$WP_PATH" eval 'global $wpdb; exit($wpdb->check_connection(false) ? 0 : 1);' >/dev/null 2>&1; do
  sleep 2
  log 'Database not ready yet...'
done

if ! wp_cmd core is-installed >/dev/null 2>&1; then
  log 'Installing WordPress...'
  wp_cmd core install \
    --url="$SITE_URL" \
    --title="$SITE_TITLE" \
    --admin_user="$ADMIN_USER" \
    --admin_password="$ADMIN_PASSWORD" \
    --admin_email="$ADMIN_EMAIL" \
    --skip-email
else
  log 'WordPress already installed; refreshing key options.'
fi

wp_cmd option update home "$SITE_URL" >/dev/null
wp_cmd option update siteurl "$SITE_URL" >/dev/null
wp_cmd option update blogdescription 'Local sandbox for Media Offload plugin testing.' >/dev/null

if ! wp_cmd plugin is-active "$PLUGIN_SLUG" >/dev/null 2>&1; then
  log 'Activating Media Offload plugin...'
  wp_cmd plugin activate "$PLUGIN_SLUG" >/dev/null
else
  log 'Media Offload plugin already active.'
fi

log 'Configuring Media Offload for local debug storage and completing onboarding...'
wp_cmd eval '
$settings = get_option("media_offload_settings", []);
$settings = is_array($settings) ? $settings : [];
$settings["enabled"] = true;
$settings["provider"] = "local";
$settings["serve_from_storage"] = true;
$settings["remove_local_copies"] = false;
$settings["logging_enabled"] = true;
$settings["log_level"] = "debug";
$settings["log_to_error_log"] = true;
update_option("media_offload_settings", $settings, false);

update_option("media_offload_license", [
  "key" => "local-dev-license",
  "status" => "active",
  "plan" => "dev",
  "last_validated_at" => time(),
], false);

update_option("media_offload_onboarding", [
  "completed" => true,
  "completed_at" => time(),
], false);

delete_option("media_offload_onboarding_redirect");
' >/dev/null

log 'Creating a sample attachment and demo page...'
wp_cmd eval '
$optionKey = "media_offload_local_test_attachment_id";
$attachmentId = (int) get_option($optionKey, 0);
if ($attachmentId > 0 && get_post($attachmentId) instanceof WP_Post) {
  $attachmentId = (int) get_post($attachmentId)->ID;
} else {
  $uploads = wp_upload_dir();
  if (!is_dir($uploads["path"])) {
    wp_mkdir_p($uploads["path"]);
  }
  $filename = trailingslashit($uploads["path"]) . "media-offload-test.png";
  if (!file_exists($filename)) {
    file_put_contents($filename, base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9WlJawAAAABJRU5ErkJggg=="));
  }

  $attachment = get_page_by_title("Media Offload Test Image", OBJECT, "attachment");
  if ($attachment instanceof WP_Post) {
    $attachmentId = (int) $attachment->ID;
  } else {
    $filetype = wp_check_filetype(basename($filename), null);
    $attachmentId = wp_insert_attachment([
      "guid" => trailingslashit($uploads["url"]) . basename($filename),
      "post_mime_type" => $filetype["type"] ?: "image/png",
      "post_title" => "Media Offload Test Image",
      "post_content" => "",
      "post_status" => "inherit",
    ], $filename);

    if (!is_wp_error($attachmentId)) {
      require_once ABSPATH . "wp-admin/includes/image.php";
      $metadata = wp_generate_attachment_metadata($attachmentId, $filename);
      if (!is_wp_error($metadata) && !empty($metadata)) {
        wp_update_attachment_metadata($attachmentId, $metadata);
      }
    } else {
      $attachmentId = 0;
    }
  }

  update_option($optionKey, (int) $attachmentId, false);
}

$page = get_page_by_path("media-offload-lab", OBJECT, "page");
$content = "<p>This site is ready for local Media Offload testing.</p>";
$content .= "<ul>";
$content .= "<li>Plugin: <strong>Media Offload</strong> is active.</li>";
$content .= "<li>Provider: <strong>Local / Debug Mode</strong>.</li>";
$content .= "<li>Onboarding: <strong>completed</strong> with a local dummy license key.</li>";
$content .= "</ul>";
if ($attachmentId > 0) {
  $content .= "<p>Sample media item:</p>" . wp_get_attachment_image($attachmentId, "full");
}
$content .= "<p>Next stop: <a href=\"/wp-admin/upload.php?page=media-offload\">Media Offload admin</a>.</p>";

$postarr = [
  "post_type" => "page",
  "post_status" => "publish",
  "post_title" => "Media Offload Lab",
  "post_name" => "media-offload-lab",
  "post_content" => $content,
];
if ($page instanceof WP_Post) {
  $postarr["ID"] = $page->ID;
  wp_update_post($postarr);
} else {
  wp_insert_post($postarr);
}
' >/dev/null

log 'Setup complete.'
log "Site: $SITE_URL"
log "Admin: $SITE_URL/wp-admin/"
log "Credentials: $ADMIN_USER / $ADMIN_PASSWORD"
