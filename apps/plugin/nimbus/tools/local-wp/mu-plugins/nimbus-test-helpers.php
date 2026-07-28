<?php
/**
 * Nimbus E2E test helpers — mu-plugin (auto-loaded, NOT shipped in production).
 *
 * Provides lightweight REST endpoints used exclusively by the Playwright test
 * suite to drive the plugin without needing WP-CLI or browser UI interactions.
 *
 * Endpoints:
 *
 *   POST   /nimbus-test/v1/offload/{id}           Force-run the async offload job.
 *   GET    /nimbus-test/v1/meta/{post_id}/{key}   Read a postmeta value.
 *   GET    /nimbus-test/v1/post/{post_id}/raw      Read raw post_content.
 *   POST   /nimbus-test/v1/cleanup-local/{id}      Synchronously delete local copies.
 *   POST   /nimbus-test/v1/setting                 Read/write a nimbus_settings key.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', function () {

    // ── Force offload ─────────────────────────────────────────────────────────

    // POST /nimbus-test/v1/offload/{id}
    register_rest_route( 'nimbus-test/v1', '/offload/(?P<id>\d+)', [
        'methods'             => 'POST',
        'permission_callback' => function () { return current_user_can( 'upload_files' ); },
        'callback'            => function ( WP_REST_Request $req ) {
            $id = (int) $req['id'];
            do_action( 'nimbus_offload_attachment', $id );
            clean_post_cache( $id );
            return new WP_REST_Response( [
                'success'     => true,
                'source_url'  => wp_get_attachment_url( $id ),
                'nimbus_meta' => get_post_meta( $id, '_nimbus', true ),
            ] );
        },
    ] );

    // ── Postmeta reader ───────────────────────────────────────────────────────

    // GET /nimbus-test/v1/meta/{post_id}/{meta_key}
    register_rest_route( 'nimbus-test/v1', '/meta/(?P<post_id>\d+)/(?P<meta_key>.+)', [
        'methods'             => 'GET',
        'permission_callback' => function () { return current_user_can( 'manage_options' ); },
        'callback'            => function ( WP_REST_Request $req ) {
            $postId  = (int) $req['post_id'];
            $metaKey = sanitize_text_field( urldecode( (string) $req['meta_key'] ) );
            $value   = get_post_meta( $postId, $metaKey, true );
            return new WP_REST_Response( [
                'post_id'  => $postId,
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- False positive: this is a literal "meta_key" response field label, not a WP_Query/get_posts arg. Dev-only Playwright test helper, not shipped in production.
                'meta_key' => $metaKey,
                'value'    => $value,
            ] );
        },
    ] );

    // ── Raw post_content reader ───────────────────────────────────────────────

    // GET /nimbus-test/v1/post/{post_id}/raw
    register_rest_route( 'nimbus-test/v1', '/post/(?P<post_id>\d+)/raw', [
        'methods'             => 'GET',
        'permission_callback' => function () { return current_user_can( 'manage_options' ); },
        'callback'            => function ( WP_REST_Request $req ) {
            $post = get_post( (int) $req['post_id'] );
            if ( ! $post ) {
                return new WP_Error( 'not_found', 'Post not found', [ 'status' => 404 ] );
            }
            return new WP_REST_Response( [
                'post_content' => $post->post_content,
                'post_excerpt' => $post->post_excerpt,
            ] );
        },
    ] );

    // ── Synchronous local-copy cleanup ────────────────────────────────────────

    // POST /nimbus-test/v1/cleanup-local/{id}
    // Synchronously fires nimbus_cleanup_attachment to delete local copies.
    // Bypasses the scheduled delay (30-180 s) so tests don't have to wait.
    register_rest_route( 'nimbus-test/v1', '/cleanup-local/(?P<id>\d+)', [
        'methods'             => 'POST',
        'permission_callback' => function () { return current_user_can( 'manage_options' ); },
        'callback'            => function ( WP_REST_Request $req ) {
            $id = (int) $req['id'];
            do_action( 'nimbus_cleanup_attachment', $id );
            $attachedFile = get_attached_file( $id );
            return new WP_REST_Response( [
                'id'                => $id,
                'local_file_exists' => is_string( $attachedFile ) && $attachedFile !== '' && file_exists( $attachedFile ),
                'attached_file'     => $attachedFile,
            ] );
        },
    ] );

    // ── Nimbus settings manipulation ──────────────────────────────────────────

    // POST /nimbus-test/v1/setting
    // Body: { "key": "remove_local_copies", "value": true }
    // Used by tests to flip feature flags without navigating the settings UI.
    register_rest_route( 'nimbus-test/v1', '/setting', [
        'methods'             => 'POST',
        'permission_callback' => function () { return current_user_can( 'manage_options' ); },
        'callback'            => function ( WP_REST_Request $req ) {
            $key   = sanitize_key( (string) ( $req->get_param( 'key' ) ?? '' ) );
            $value = $req->get_param( 'value' );

            if ( $key === '' ) {
                return new WP_Error( 'invalid_key', 'key is required', [ 'status' => 400 ] );
            }

            $settings = get_option( 'nimbus_settings', [] );
            if ( ! is_array( $settings ) ) {
                $settings = [];
            }

            $old              = $settings[ $key ] ?? null;
            $settings[ $key ] = $value;
            update_option( 'nimbus_settings', $settings, false );

            return new WP_REST_Response( [
                'ok'        => true,
                'key'       => $key,
                'old_value' => $old,
                'new_value' => $value,
            ] );
        },
    ] );

} );
