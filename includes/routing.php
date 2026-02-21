<?php
/**
 * Routing — detects markdown requests and serves the converted response.
 *
 * Three access methods:
 *  1. .md suffix        — /my-post.md
 *  2. Query parameter   — /my-post/?format=markdown
 *  3. Accept header     — Accept: text/markdown
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* --------------------------------------------------------------------------
 * Fast-Path Serving — Check for static files before the main WP query.
 * ---------------------------------------------------------------------- */

add_action( 'init', function (): void {
    if ( is_admin() ) {
        return;
    }

    $request_method = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
    if ( 'GET' !== $request_method ) {
        return;
    }

    $uri = wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_PATH );
    if ( ! $uri ) {
        return;
    }

    // Subdirectory-aware path resolution.
    $site_path = wp_parse_url( site_url(), PHP_URL_PATH ) ?: '';
    $relative_path = substr( $uri, strlen( $site_path ) );
    $clean_uri = trim( $relative_path, '/' );

    // Fast-Path: detect all three markdown access methods.
    $is_md_suffix   = str_ends_with( $clean_uri, '.md' );
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public API, no state change.
    $is_query_param = ( isset( $_GET['format'] ) && 'markdown' === sanitize_text_field( wp_unslash( $_GET['format'] ) ) );
    $is_accept      = (
        apply_filters( 'markdown_enable_accept_header', true )
        && isset( $_SERVER['HTTP_ACCEPT'] )
        && str_contains( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ), 'text/markdown' )
    );

    if ( ! $is_md_suffix && ! $is_query_param && ! $is_accept ) {
        return;
    }

    if ( $is_md_suffix ) {
        // Strip .md and optional trailing slash.
        $clean_uri = preg_replace( '/\.md\/?$/', '', $clean_uri );
    }

    // Homepage: empty URI = static front page.
    if ( '' === $clean_uri ) {
        $clean_uri = '_front-page';
    }

    // Sanitize the URI into a safe, normalized cache path.
    $safe_slug = mfa_sanitize_cache_slug( $clean_uri );
    if ( '' === $safe_slug ) {
        return;
    }

    // Construct the expected cache path and verify containment.
    $upload_dir = wp_upload_dir();
    $cache_base = $upload_dir['basedir'] . '/mfa-cache';
    $file_path  = $cache_base . '/' . $safe_slug . '.md';

    // Belt-and-suspenders: verify the final path is inside the cache directory.
    // Pure string check — no filesystem calls, no realpath cache issues.
    if ( ! str_starts_with( $file_path, $cache_base . '/' ) ) {
        return;
    }

    if ( ! file_exists( $file_path ) ) {
        return;
    }

    // Read the lightweight sidecar meta — no YAML parsing, no markdown loading.
    $meta_path = mfa_get_meta_path( $file_path );
    $meta      = mfa_read_meta( $meta_path );

    if ( empty( $meta ) ) {
        // No sidecar yet (pre-upgrade cache file). Let the slow path regenerate it.
        return;
    }

    // Security validation: check post type is allowed.
    $allowed = apply_filters( 'markdown_served_post_types', [ 'post', 'page' ] );
    $cached_type = (string) ( $meta['type'] ?? '' );
    if ( $cached_type && ! in_array( $cached_type, $allowed, true ) ) {
        return;
    }

    // Auth check: verify the post is still public. Single indexed lookup,
    // almost always served from the object cache.
    $post_id = (int) ( $meta['post_id'] ?? 0 );
    if ( $post_id ) {
        if ( 'publish' !== get_post_status( $post_id ) ) {
            return;
        }
        // Password-protected posts still have 'publish' status.
        if ( '' !== (string) get_post_field( 'post_password', $post_id ) ) {
            return;
        }
    }

    // Front page: verify cached post_id still matches the current setting.
    if ( '_front-page' === $safe_slug ) {
        $current_front = (int) get_option( 'page_on_front' );
        if ( ! $current_front || $current_front !== $post_id ) {
            return; // Let slow path regenerate with the correct page.
        }
    }

    // Read the file before sending any headers — if it vanished between
    // file_exists() and now, fall through to the slow path cleanly.
    $markdown = @file_get_contents( $file_path );
    if ( false === $markdown ) {
        return;
    }

    $tokens = (int) ( $meta['tokens'] ?? 0 );

    header( 'Content-Type: text/markdown; charset=utf-8' );
    header( 'Content-Length: ' . strlen( $markdown ) );
    header( 'Vary: Accept' );
    header( 'X-Content-Type-Options: nosniff' );
    header( 'X-Markdown-Tokens: ' . $tokens );
    header( 'X-Robots-Tag: noindex' );
    $canonical = ( '_front-page' === $safe_slug ) ? home_url( '/' ) : home_url( $safe_slug . '/' );
    header( 'Link: <' . esc_url( $canonical ) . '>; rel="canonical"' );
    mfa_send_content_signal_header();

    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- serving text/markdown, not HTML.
    echo $markdown;
    exit;
}, 5 );

/* --------------------------------------------------------------------------
 * Rewrite Rules — Handle .md suffix via standard WP Rewrite API.
 * ---------------------------------------------------------------------- */

/** Register the custom rewrite rule. */
function mfa_register_rewrite_rule(): void {
    add_rewrite_rule(
        '(.+)\.md/?$',
        'index.php?mfa_path=$matches[1]&mfa_format=markdown',
        'top'
    );
}
add_action( 'init', 'mfa_register_rewrite_rule' );

/** Disable canonical redirection for Markdown requests to prevent .md/ redirects. */
add_filter( 'redirect_canonical', function ( $redirect_url ) {
    // Check both the query var AND the raw URI
    if ( 'markdown' === get_query_var( 'mfa_format' ) ) {
        return false;
    }
    $uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
    if ( preg_match( '/\.md(\/?\?.*)?$/', $uri ) ) {
        return false;
    }
    return $redirect_url;
} );

/** Register custom query variables. */
add_filter( 'query_vars', function ( array $vars ): array {
    $vars[] = 'mfa_format';
    $vars[] = 'mfa_path';
    return $vars;
} );

/** 
 * Resolve the mfa_path into a post object.
 */
add_filter( 'request', function ( array $query_vars ): array {
    if ( empty( $query_vars['mfa_path'] ) ) {
        return $query_vars;
    }

    $path    = trim( $query_vars['mfa_path'], '/' );
    $allowed = apply_filters( 'markdown_served_post_types', [ 'post', 'page' ] );
    
    // Try to resolve the path to a post ID.
    // We try both with and without a trailing slash to satisfy url_to_postid.
    $url_to_check = home_url( $path );
    $post_id      = url_to_postid( $url_to_check );
    
    if ( ! $post_id ) {
        $post_id = url_to_postid( user_trailingslashit( $url_to_check ) );
    }
    
    if ( ! $post_id ) {
        return $query_vars;
    }

    $post = get_post( $post_id );
    if ( ! $post ) {
        return $query_vars;
    }

    // Post type must be in the allowed list.
    if ( ! in_array( $post->post_type, $allowed, true ) ) {
        return $query_vars;
    }

    // Only serve published, non-password-protected posts.
    // Catches status changes between url_to_postid() lookup and now.
    if ( 'publish' !== $post->post_status || '' !== $post->post_password ) {
        return $query_vars;
    }

    // Round-trip verification: confirm the resolved post's permalink matches
    // the requested path. Catches slug collisions from imports/plugins/races.
    $post_uri = trim( wp_parse_url( get_permalink( $post ), PHP_URL_PATH ) ?: '', '/' );
    $site_uri = trim( wp_parse_url( site_url(), PHP_URL_PATH ) ?: '', '/' );
    if ( $site_uri ) {
        $post_uri = substr( $post_uri, strlen( $site_uri ) + 1 );
    }
    $post_uri = trim( $post_uri, '/' );

    if ( $post_uri !== $path ) {
        return $query_vars;
    }

    // All checks passed — tell WP to load this specific post.
    unset( $query_vars['mfa_path'] );
    $query_vars['p']         = $post_id;
    $query_vars['post_type'] = $post->post_type;

    return $query_vars;
} );

/* --------------------------------------------------------------------------
 * Serve the Markdown response
 * ---------------------------------------------------------------------- */

add_action( 'template_redirect', function (): void {
    if ( ! mfa_should_serve_markdown() ) {
        return;
    }

    $post = get_queried_object();

    if ( ! $post instanceof WP_Post ) {
        return;
    }

    $allowed = apply_filters( 'markdown_served_post_types', [ 'post', 'page' ] );

    if ( ! in_array( $post->post_type, $allowed, true ) ) {
        return;
    }

    // Only published posts.
    if ( 'publish' !== $post->post_status ) {
        status_header( 404 );
        echo 'Not found.';
        exit;
    }

    // Password-protected posts — no point serving a form to a bot.
    if ( post_password_required( $post ) ) {
        status_header( 403 );
        echo 'This content is password protected.';
        exit;
    }

    // Rate-limit cache miss regeneration. Conversion is expensive (the_content
    // filters + HTML-to-Markdown). Cap at 20 regenerations per minute globally.
    if ( mfa_regen_throttled() ) {
        status_header( 429 );
        header( 'Retry-After: 60' );
        echo 'Too many requests. Try again later.';
        exit;
    }

    $result   = mfa_convert_post( $post );
    $markdown = $result['markdown'];
    $tokens   = $result['tokens'];

    status_header( 200 );
    header( 'Content-Type: text/markdown; charset=utf-8' );
    header( 'Content-Length: ' . strlen( $markdown ) );
    header( 'Vary: Accept' );
    header( 'X-Content-Type-Options: nosniff' );
    header( 'X-Markdown-Tokens: ' . $tokens );
    header( 'X-Robots-Tag: noindex' );
    header( 'Link: <' . esc_url( get_permalink( $post ) ) . '>; rel="canonical"' );
    mfa_send_content_signal_header( $post );

    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- serving text/markdown, not HTML.
    echo $markdown;
    exit;
} );

/* --------------------------------------------------------------------------
 * Discovery — inject <link rel="alternate"> in <head>
 * ---------------------------------------------------------------------- */

add_action( 'wp_head', function (): void {
    if ( ! is_singular() ) {
        return;
    }

    $post = get_queried_object();

    if ( ! $post instanceof WP_Post ) {
        return;
    }

    $allowed = apply_filters( 'markdown_served_post_types', [ 'post', 'page' ] );

    if ( ! in_array( $post->post_type, $allowed, true ) ) {
        return;
    }

    $url = add_query_arg( 'format', 'markdown', get_permalink( $post ) );
    printf(
        '<link rel="alternate" type="text/markdown" href="%s" />' . "\n",
        esc_url( $url )
    );
} );

/* --------------------------------------------------------------------------
 * Discovery — send HTTP Link header for Markdown alternate
 * ---------------------------------------------------------------------- */

/**
 * Add an HTTP Link header advertising the Markdown alternate representation.
 *
 * This complements the <link rel="alternate"> tag in <head> for clients that
 * only inspect headers (HEAD requests, some bots, tooling).
 */
add_action( 'send_headers', function (): void {
    if ( is_admin() ) {
        return;
    }

    // Only on normal HTML responses (not when we're serving Markdown).
    if ( mfa_should_serve_markdown() ) {
        return;
    }

    if ( ! is_singular() ) {
        return;
    }

    $post = get_queried_object();
    if ( ! $post instanceof WP_Post ) {
        return;
    }

    $allowed = apply_filters( 'markdown_served_post_types', [ 'post', 'page' ] );
    if ( ! in_array( $post->post_type, $allowed, true ) ) {
        return;
    }

    $markdown_url = add_query_arg( 'format', 'markdown', get_permalink( $post ) );
    // Append (don't replace) any existing Link headers.
    header(
        'Link: <' . esc_url_raw( $markdown_url ) . '>; rel="alternate"; type="text/markdown"',
        false
    );
} );

/* --------------------------------------------------------------------------
 * Cache invalidation
 * ---------------------------------------------------------------------- */

add_action( 'save_post', 'mfa_invalidate_cache' );
add_action( 'before_delete_post', 'mfa_invalidate_cache' );

/** Invalidate front-page cache when the static front page setting changes. */
add_action( 'update_option_page_on_front', function ( $old_value, $new_value ): void {
    $upload_dir = wp_upload_dir();
    $cache_base = $upload_dir['basedir'] . '/mfa-cache';
    $md_path    = $cache_base . '/_front-page.md';
    mfa_delete_cache_files( $md_path );
}, 10, 2 );

/**
 * Invalidate the Markdown cache for a post and its children (if hierarchical).
 *
 * Hooked to save_post and before_delete_post.
 *
 * @param int $post_id The post ID being saved or deleted.
 */
function mfa_invalidate_cache( int $post_id ): void {
    $post = get_post( $post_id );
    if ( ! $post ) {
        return;
    }

    mfa_delete_cache_files( mfa_get_cache_path( $post ) );

    // Hierarchical invalidation for pages.
    if ( is_post_type_hierarchical( $post->post_type ) ) {
        $children = get_pages( [ 'child_of' => $post_id, 'post_type' => $post->post_type ] );
        foreach ( $children as $child ) {
            mfa_delete_cache_files( mfa_get_cache_path( $child ) );
        }
    }
}

/**
 * Delete a cache .md file and its sidecar .meta.json.
 *
 * If unlink fails, truncates files to zero bytes so stale content is never served,
 * and logs the failure.
 *
 * @param string $md_path Absolute path to the .md cache file.
 */
function mfa_delete_cache_files( string $md_path ): void {
    mfa_safe_unlink( $md_path );
    mfa_safe_unlink( mfa_get_meta_path( $md_path ) );
}

/**
 * Attempt to delete a file. If unlink fails, truncate it and log the error.
 *
 * @param string $path Absolute path to the file to delete.
 */
function mfa_safe_unlink( string $path ): void {
    if ( ! file_exists( $path ) ) {
        return;
    }
    wp_delete_file( $path );
    if ( ! file_exists( $path ) ) {
        return;
    }
    // Unlink failed — truncate so the file is empty/unusable.
    @file_put_contents( $path, '', LOCK_EX );
    mfa_log( 'failed to delete cache file: ' . $path );
}

/* --------------------------------------------------------------------------
 * Detection helper
 * ---------------------------------------------------------------------- */

/**
 * Detect whether the current request is asking for Markdown output.
 *
 * Checks three access methods: ?format=markdown query param, .md suffix
 * (via mfa_format rewrite query var), and Accept: text/markdown header.
 *
 * @return bool True if the response should be served as Markdown.
 */
function mfa_should_serve_markdown(): bool {
    // 1. Query parameter: ?format=markdown
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public API, no state change.
    if ( isset( $_GET['format'] ) && 'markdown' === sanitize_text_field( wp_unslash( $_GET['format'] ) ) ) {
        return true;
    }

    // 2. .md suffix (sets the query var during rewrite resolution).
    if ( 'markdown' === get_query_var( 'mfa_format' ) ) {
        return true;
    }

    // 3. Accept header content negotiation.
    if ( apply_filters( 'markdown_enable_accept_header', true ) ) {
        if (
            isset( $_SERVER['HTTP_ACCEPT'] )
            && str_contains( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ), 'text/markdown' )
        ) {
            return true;
        }
    }

    return false;
}

/* --------------------------------------------------------------------------
 * Rate limiting
 * ---------------------------------------------------------------------- */

/**
 * Check if cache miss regeneration should be throttled.
 *
 * Uses a transient counter to cap regenerations at 20/minute. Prevents a
 * bot crawling thousands of uncached ?format=markdown URLs from DOSing the
 * site with expensive the_content + HTML-to-Markdown conversions.
 *
 * @return bool True if the request should be throttled (429).
 */
function mfa_regen_throttled(): bool {
    $key   = 'mfa_regen_count';
    $limit = (int) apply_filters( 'mfa_regen_rate_limit', 20 );
    $count = (int) get_transient( $key );

    if ( $count >= $limit ) {
        return true;
    }

    set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
    return false;
}

/* --------------------------------------------------------------------------
 * Response helpers
 * ---------------------------------------------------------------------- */

/**
 * Sanitize a URI into a safe cache slug, or return '' to reject.
 *
 * 1. Null byte rejection
 * 2. Character whitelist (alphanumeric, hyphens, underscores, slashes)
 * 3. Split into segments, reject '.', '..', empty (from //), or any
 *    segment that could be a Windows device name or stream
 * 4. Reassemble from clean parts — the result is normalized
 */
function mfa_sanitize_cache_slug( string $uri ): string {
    // Null bytes — instant kill.
    if ( str_contains( $uri, "\0" ) ) {
        return '';
    }

    // Character whitelist. No dots, no encoded chars, no unicode.
    if ( ! preg_match( '#^[a-zA-Z0-9\-_/]+$#', $uri ) ) {
        return '';
    }

    // Split on /, validate every segment individually.
    $segments = explode( '/', $uri );
    $clean    = [];

    foreach ( $segments as $seg ) {
        // Empty segment = leading/trailing/double slash.
        if ( '' === $seg ) {
            continue;
        }

        // Block . and .. (shouldn't pass the regex, but defense-in-depth).
        if ( '.' === $seg || '..' === $seg ) {
            return '';
        }

        // Block Windows reserved device names (CON, PRN, AUX, NUL, COM1-9, LPT1-9).
        if ( preg_match( '/^(CON|PRN|AUX|NUL|COM\d|LPT\d)$/i', $seg ) ) {
            return '';
        }

        $clean[] = $seg;
    }

    if ( empty( $clean ) ) {
        return '';
    }

    return implode( '/', $clean );
}

/**
 * Send the Content-Signal header if configured.
 */
function mfa_send_content_signal_header( ?WP_Post $post = null ): void {
    $signal = apply_filters( 'markdown_content_signal', 'ai-train=yes, search=yes, ai-input=yes', $post );
    $signal = str_replace( [ "\r", "\n" ], '', $signal );
    if ( $signal ) {
        header( 'Content-Signal: ' . $signal );
    }
}

/**
 * Estimate the number of tokens from a word count.
 */
function mfa_estimate_tokens( int $word_count ): int {
    /**
     * Filter the token multiplier. Defaults to 1.3 (Cloudflare-style heuristic).
     */
    $multiplier = apply_filters( 'markdown_token_multiplier', 1.3 );

    return (int) ceil( $word_count * $multiplier );
}
