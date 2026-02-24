<?php
/**
 * Cache helpers — shared between routing (fast-path) and converter (write path).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Sanitize a cache variant string.
 *
 * Allowed: lowercase a-z, 0-9, underscore, hyphen. Max length 32.
 */
function botkibble_sanitize_cache_variant( string $variant ): string {
    $variant = strtolower( trim( $variant ) );
    if ( '' === $variant ) {
        return '';
    }

    // Replace invalid characters with nothing.
    $variant = preg_replace( '/[^a-z0-9_-]+/', '', $variant ) ?? '';
    $variant = substr( $variant, 0, 32 );

    return $variant;
}

/**
 * Get the cache variant for the current request.
 *
 * Sources (in order):
 * 1) Query param: ?botkibble_variant=slim
 * 2) Filter: botkibble_cache_variant
 *
 * @param WP_Post|null $post Optional post context for the filter.
 */
function botkibble_get_cache_variant( ?WP_Post $post = null ): string {
    $variant = '';

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public API, no state change.
    if ( isset( $_GET['botkibble_variant'] ) ) {
        $variant = sanitize_text_field( wp_unslash( $_GET['botkibble_variant'] ) );
    }

    /**
     * Filter the cache variant for this request.
     *
     * Return '' for the default/normal cache.
     *
     * @param string      $variant Current variant.
     * @param WP_Post|null $post    Optional post context (null in fast-path).
     */
    $variant = (string) apply_filters( 'botkibble_cache_variant', $variant, $post );

    return botkibble_sanitize_cache_variant( $variant );
}

/**
 * Build the absolute cache path for a safe slug and variant.
 *
 * @param string $safe_slug Sanitized slug returned by botkibble_sanitize_cache_slug().
 * @param string $variant   Sanitized variant ('' for normal).
 */
function botkibble_cache_path_for_slug( string $safe_slug, string $variant = '' ): string {
    $upload_dir = wp_upload_dir();
    $base_dir   = $upload_dir['basedir'] . '/botkibble-cache';

    $variant = botkibble_sanitize_cache_variant( $variant );
    if ( '' !== $variant ) {
        return $base_dir . '/_v/' . $variant . '/' . $safe_slug . '.md';
    }

    return $base_dir . '/' . $safe_slug . '.md';
}

