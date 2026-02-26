<?php
/**
 * Converter — turns a WP_Post into a clean Markdown document with YAML
 * frontmatter.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use League\HTMLToMarkdown\HtmlConverter;
use Symfony\Component\Yaml\Yaml;

/* --------------------------------------------------------------------------
 * Main entry point
 * ---------------------------------------------------------------------- */

/**
 * Convert a published post/page to Markdown (with static file offloading).
 *
 * Serves from the static cache when fresh, otherwise regenerates via
 * the_content filters + HTML-to-Markdown conversion and writes new cache files.
 *
 * @param WP_Post $post The post to convert.
 * @return array{ markdown: string, tokens: int }
 */
function botkibble_convert_post( WP_Post $post ): array {
    $file_path = botkibble_get_cache_path( $post );
    $meta_path = botkibble_get_meta_path( $file_path );
    $variant   = botkibble_get_cache_variant( $post );

    // Try the cache — handle the file vanishing between exists/filemtime and read.
    // Both filemtime() and strtotime() return UTC unix timestamps. Appending +00:00
    // makes the GMT intent explicit and immune to default timezone misconfiguration.
    $post_modified_ts = strtotime( $post->post_modified_gmt . ' +00:00' );
    if ( file_exists( $file_path ) && filemtime( $file_path ) >= $post_modified_ts ) {
        $cached = @file_get_contents( $file_path );
        if ( false !== $cached ) {
            $meta = botkibble_read_meta( $meta_path );
            return [
                'markdown' => $cached,
                'tokens'   => (int) ( $meta['tokens'] ?? 0 ),
            ];
        }
        // File vanished or became unreadable — regenerate below.
    }

    $result     = botkibble_render_body( $post );
    $title      = html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $body       = "# " . $title . "\n\n" . trim( $result['markdown'] );

    /**
     * Allow plugins to modify the Markdown body BEFORE metrics are calculated.
     * This ensures that any added content (like ld+json) is reflected in the
     * word count, char count, and estimated tokens.
     */
    $body = apply_filters( 'botkibble_body', $body, $post );

    $word_count = str_word_count( wp_strip_all_tags( $body ) );
    $tokens     = botkibble_estimate_tokens( $word_count );
    $frontmatter = botkibble_build_frontmatter( $post, $body, $word_count, $tokens );

    $markdown = $frontmatter . "\n" . $body . "\n";

    /** Allow plugins to modify the final document (frontmatter + body). */
    $markdown = apply_filters( 'botkibble_output', $markdown, $post );

    // Ensure the directory exists. Only write protection files on creation.
    $cache_dir = dirname( $file_path );
    if ( ! is_dir( $cache_dir ) ) {
        wp_mkdir_p( $cache_dir );
        botkibble_protect_directory( $cache_dir );
    }

    // Write cache files. If writes fail (disk full, permissions), log it but
    // still return the dynamically generated content.
    if ( false === @file_put_contents( $file_path, $markdown, LOCK_EX ) ) {
        botkibble_log( 'failed to write cache file: ' . $file_path );
    } else {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- no WP equivalent; sets mtime for cache freshness.
        touch( $file_path, $post_modified_ts );
    }

    botkibble_write_meta( $meta_path, [
        'post_id' => $post->ID,
        'type'    => $post->post_type,
        'variant' => $variant,
        'tokens'  => $tokens,
        'length'  => strlen( $markdown ),
    ] );

    return [
        'markdown' => $markdown,
        'tokens'   => $tokens,
    ];
}

/**
 * Get the absolute path to the static cache file for a given post.
 *
 * @param WP_Post $post The post to get the cache path for.
 * @return string Absolute filesystem path (e.g. /wp-content/uploads/botkibble/my-post.md).
 */
function botkibble_get_cache_path( WP_Post $post, ?string $variant = null ): string {
    $uri = botkibble_get_post_uri( $post );

    // null means "use request variant"; empty string means default cache.
    $variant = ( null === $variant ) ? botkibble_get_cache_variant( $post ) : botkibble_sanitize_cache_variant( $variant );

    $safe_slug = botkibble_sanitize_cache_slug( $uri );
    if ( '' === $safe_slug ) {
        // Should not happen (URI comes from permalink), but fall back safely.
        $safe_slug = ltrim( $uri, '/' );
    }

    return botkibble_cache_path_for_slug( $safe_slug, $variant );
}

/**
 * Get the sidecar meta path for a given cache file path.
 *
 * @param string $md_path Absolute path to the .md cache file.
 * @return string Corresponding .meta.json path.
 */
function botkibble_get_meta_path( string $md_path ): string {
    return preg_replace( '/\.md$/', '.meta.json', $md_path );
}

/**
 * Read the sidecar meta file. Returns empty array on any failure.
 *
 * @param string $meta_path Absolute path to the .meta.json file.
 * @return array{ post_id?: int, type?: string, tokens?: int, length?: int }
 */
function botkibble_read_meta( string $meta_path ): array {
    if ( ! file_exists( $meta_path ) ) {
        return [];
    }
    $json = file_get_contents( $meta_path );
    if ( false === $json ) {
        return [];
    }
    $data = json_decode( $json, true );
    return is_array( $data ) ? $data : [];
}

/**
 * Write the sidecar meta file.
 *
 * @param string $meta_path Absolute path to the .meta.json file.
 * @param array  $data      Associative array of metadata to encode as JSON.
 */
function botkibble_write_meta( string $meta_path, array $data ): void {
    if ( false === @file_put_contents( $meta_path, json_encode( $data, JSON_UNESCAPED_SLASHES ), LOCK_EX ) ) {
        botkibble_log( 'failed to write meta file: ' . $meta_path );
    }
}

/**
 * Log a message to the PHP error log. Always logs — cache failures
 * on production are operational issues, not debug noise.
 *
 * @param string $message The message to log (prefixed with "Botkibble: ").
 */
function botkibble_log( string $message ): void {
    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional operational logging.
    error_log( 'Botkibble: ' . $message );
}

/**
 * Protect a cache directory from browsing/listing on any web server.
 *
 * - index.php: Blocks directory listing on Nginx, Caddy, and any server
 *   that resolves index files (all of them by default).
 * - .htaccess: Apache-specific access control — deny all except .md files.
 *
 * @param string $dir Absolute path to the directory to protect.
 */
function botkibble_protect_directory( string $dir ): void {
    // Silence directory listing on all web servers.
    $index_path = $dir . '/index.php';
    if ( ! file_exists( $index_path ) ) {
        file_put_contents( $index_path, "<?php\n// Silence is golden.\n", LOCK_EX );
    }

    // Apache-specific access control.
    $htaccess_path = $dir . '/.htaccess';
    if ( ! file_exists( $htaccess_path ) ) {
        $htaccess  = "Options -Indexes\n";
        $htaccess .= "<IfModule mod_authz_core.c>\n";
        $htaccess .= "    Require all denied\n";
        $htaccess .= "    <Files \"*.md\">\n";
        $htaccess .= "        Require all granted\n";
        $htaccess .= "    </Files>\n";
        $htaccess .= "</IfModule>\n";
        $htaccess .= "<IfModule !mod_authz_core.c>\n";
        $htaccess .= "    Deny from all\n";
        $htaccess .= "    <Files \"*.md\">\n";
        $htaccess .= "        Allow from all\n";
        $htaccess .= "    </Files>\n";
        $htaccess .= "</IfModule>\n";
        file_put_contents( $htaccess_path, $htaccess, LOCK_EX );
    }
}

/**
 * Get the relative URI path for a post, stripped of the site's subdirectory prefix.
 *
 * @param WP_Post $post The post to get the URI for.
 * @return string Relative path without leading/trailing slashes (e.g. "my-post" or "parent/child").
 */
function botkibble_get_post_uri( WP_Post $post ): string {
    $permalink = get_permalink( $post );
    if ( ! $permalink ) {
        return '';
    }

    $urlPath  = wp_parse_url( $permalink, PHP_URL_PATH ) ?: '';
    $sitePath = wp_parse_url( site_url(), PHP_URL_PATH ) ?: '';

    // Remove the site's subdirectory (if any) and trim slashes.
    $uri = substr( $urlPath, strlen( $sitePath ) );
    $uri = trim( $uri, '/' );

    // Static front page resolves to an empty URI — use a reserved slug.
    if ( '' === $uri ) {
        return '_front-page';
    }

    return $uri;
}

/* --------------------------------------------------------------------------
 * Frontmatter
 * ---------------------------------------------------------------------- */

/**
 * Build YAML frontmatter for a post's Markdown output.
 *
 * Includes title, date, type, content metrics, and taxonomy terms.
 * Filterable via the 'botkibble_frontmatter' hook.
 *
 * @param WP_Post $post       The post to build frontmatter for.
 * @param string  $body       The converted Markdown body (used for char_count).
 * @param int     $word_count Pre-computed word count from HTML plain text.
 * @param int     $tokens     Pre-computed estimated token count.
 * @return string Complete YAML frontmatter block including --- delimiters.
 */
function botkibble_build_frontmatter( WP_Post $post, string $body = '', int $word_count = 0, int $tokens = 0 ): string {
    $data = [
        'title' => html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
        'date'  => get_the_date( 'c', $post ),
        'type'  => $post->post_type,
    ];

    if ( $body ) {
        $data['word_count'] = $word_count;
        $data['char_count'] = mb_strlen( $body );
        $data['tokens']     = $tokens;
    }

    // Categories and tags only make sense on posts.
    if ( 'post' === $post->post_type ) {
        $categories = get_the_category( $post->ID );
        if ( $categories ) {
            $data['categories'] = array_map( fn( $c ) => html_entity_decode( $c->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $categories );
        }

        $tags = get_the_tags( $post->ID );
        if ( $tags ) {
            $data['tags'] = array_map( fn( $t ) => html_entity_decode( $t->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $tags );
        }
    }

    /** Allow plugins to add or remove frontmatter fields. */
    $data = apply_filters( 'botkibble_frontmatter', $data, $post );

    return botkibble_encode_yaml_frontmatter( $data );
}

/* --------------------------------------------------------------------------
 * HTML → Markdown
 * ---------------------------------------------------------------------- */

/**
 * Render a post's content as Markdown.
 *
 * Applies the_content filters, cleans the HTML, counts words from plain text,
 * then converts to Markdown via HtmlConverter.
 *
 * @param WP_Post $post The post to render.
 * @return array{ markdown: string, word_count: int }
 */
function botkibble_render_body( WP_Post $post ): array {
    // Set up global post state so the_content filters behave normally.
    $previous_post = $GLOBALS['post'] ?? null;
    $GLOBALS['post'] = $post;
    setup_postdata( $post );

    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- calling WP core hook, not defining one.
    $html = apply_filters( 'the_content', $post->post_content );

    // Restore previous state.
    $GLOBALS['post'] = $previous_post;
    wp_reset_postdata();

    $html = botkibble_clean_html( $html );

    // Count words from plain text before markdown conversion.
    $word_count = str_word_count( wp_strip_all_tags( $html ) );

    static $converter = null;
    if ( null === $converter ) {
        $converter_options = [
            'strip_tags' => true,
            'hard_break' => true,
        ];

        /**
         * Optional: remove entire DOM node types before conversion.
         *
         * Keep this empty by default to preserve legacy behavior.
         * Example return values:
         * - array: [ 'script', 'style' ]
         * - string: 'script style'
         *
         * @param array<int, string>|string $remove_nodes Requested node names.
         * @param WP_Post                   $post         Current post being rendered.
         */
        $remove_nodes = apply_filters( 'botkibble_converter_remove_nodes', [], $post );
        $remove_nodes = botkibble_normalize_remove_nodes( $remove_nodes );
        if ( ! empty( $remove_nodes ) ) {
            $converter_options['remove_nodes'] = implode( ' ', $remove_nodes );
        }

        $converter = new HtmlConverter( $converter_options );
    }

    return [
        'markdown'   => $converter->convert( $html ),
        'word_count' => $word_count,
    ];
}

/**
 * Normalize a converter remove_nodes value into a clean list of tag names.
 *
 * Accepts either a string (space/comma-separated) or an array of values and
 * returns unique lowercase tag names safe to pass to HtmlConverter.
 *
 * @param array<int, mixed>|string $nodes Raw filter value.
 * @return array<int, string>
 */
function botkibble_normalize_remove_nodes( $nodes ): array {
    if ( is_string( $nodes ) ) {
        $nodes = preg_split( '/[\s,]+/', $nodes ) ?: [];
    } elseif ( ! is_array( $nodes ) ) {
        return [];
    }

    $out = [];
    foreach ( $nodes as $node ) {
        $name = strtolower( trim( (string) $node ) );
        if ( '' === $name ) {
            continue;
        }

        // Keep DOM-like node names only (e.g. script, style, iframe).
        if ( ! preg_match( '/^[a-z][a-z0-9:_-]*$/', $name ) ) {
            continue;
        }

        $out[ $name ] = true;
    }

    return array_keys( $out );
}

/* --------------------------------------------------------------------------
 * HTML cleanup — runs between the_content and the converter
 * ---------------------------------------------------------------------- */

/**
 * Clean rendered HTML before Markdown conversion.
 *
 * Strips shortcodes, inline styles, and empty paragraphs. Filterable
 * via the 'botkibble_clean_html' hook for plugin-specific cleanup.
 *
 * @param string $html The rendered post HTML from the_content.
 * @return string Cleaned HTML ready for the Markdown converter.
 */
function botkibble_clean_html( string $html ): string {
    // Use native WP function to remove shortcodes reliably.
    $html = strip_shortcodes( $html );

    // Remove inline styles — noisy and meaningless in Markdown.
    $html = preg_replace( '/\s+style="[^"]*"/i', '', $html );

    // Remove empty paragraphs.
    $html = preg_replace( '/<p>\s*<\/p>/i', '', $html );

    // Strip syntax-highlighting spans from code blocks. Server-side
    // highlighters (Chroma, Enlighter, SyntaxHighlighter Evolved) inject
    // <span> tags that HtmlConverter preserves literally inside <pre>.
    $html = botkibble_clean_code_blocks( $html );

    /** Allow plugins to add their own cleanup rules. */
    $html = apply_filters( 'botkibble_clean_html', $html );

    return $html;
}

/**
 * Strip decorative <span> tags from inside <pre> blocks.
 *
 * Server-side syntax highlighters wrap tokens in spans for coloring.
 * HtmlConverter does not strip tags inside <pre>/<code>, so these leak
 * into the markdown output as raw HTML. This function removes them,
 * keeping only the text content.
 *
 * @param string $html HTML content potentially containing highlighted code blocks.
 * @return string HTML with code block spans removed.
 */
function botkibble_clean_code_blocks( string $html ): string {
    // Only process if there are <pre> blocks with spans inside.
    if ( false === stripos( $html, '<pre' ) ) {
        return $html;
    }

    return preg_replace_callback(
        '/<pre\b[^>]*>.*?<\/pre>/si',
        function ( $match ) {
            $block = $match[0];
            // Only bother if there are spans inside this pre block.
            if ( false === stripos( $block, '<span' ) ) {
                return $block;
            }
            // Strip all <span> open and close tags, keep content.
            $block = preg_replace( '/<\/?span[^>]*>/i', '', $block );
            return $block;
        },
        $html
    );
}

/* --------------------------------------------------------------------------
 * YAML Frontmatter Encoder
 * ---------------------------------------------------------------------- */

/**
 * Encode an associative array as a YAML frontmatter block.
 *
 * @param array $data Key-value pairs to encode.
 * @return string YAML wrapped in --- delimiters, or empty frontmatter if $data is empty.
 */
function botkibble_encode_yaml_frontmatter( array $data ): string {
    if ( empty( $data ) ) {
        return "---\n---\n";
    }

    $yaml = Yaml::dump( $data, 2, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK );

    return "---\n" . trim( $yaml ) . "\n---\n";
}

