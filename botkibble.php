<?php
/**
 * Plugin Name: Botkibble
 * Plugin URI:  https://github.com/greg-randall/botkibble
 * Description: Serve published posts and pages as clean Markdown for AI agents and crawlers.
 * Version:     1.2.0
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * Author:      Greg Randall
 * Author URI:  https://gregr.org
 * License:     GPL-2.0-only
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'BOTKIBBLE_VERSION', '1.2.0' );
define( 'BOTKIBBLE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Require Composer autoloader.
$botkibble_autoload = BOTKIBBLE_PLUGIN_DIR . 'vendor/autoload.php';

if ( ! file_exists( $botkibble_autoload ) ) {
    add_action( 'admin_notices', function () {
        printf(
            '<div class="notice notice-error"><p><strong>Botkibble:</strong> '
            . 'Dependencies not installed. Run <code>composer install</code> in <code>%s</code>.</p></div>',
            esc_html( BOTKIBBLE_PLUGIN_DIR )
        );
    } );
    return;
}

require_once $botkibble_autoload;
require_once BOTKIBBLE_PLUGIN_DIR . 'includes/cache.php';
require_once BOTKIBBLE_PLUGIN_DIR . 'includes/routing.php';
require_once BOTKIBBLE_PLUGIN_DIR . 'includes/converter.php';

/**
 * Rewrite rule management.
 *
 * WordPress stores rewrite rules in the database. Our .md suffix rule
 * (registered in routing.php) must be flushed into that stored set whenever
 * it might be missing or stale:
 *
 *  - On activation:   rule doesn't exist yet.
 *  - On deactivation: rule should be removed so it doesn't 404 without us.
 *  - On version bump: rule pattern may have changed between releases.
 *
 * flush_rewrite_rules() is expensive (rewrites the .htaccess on Apache),
 * so we gate the version check behind a cheap option comparison.
 */
add_action( 'init', function () {
    $current_version = get_option( 'botkibble_version', '0.0.0' );
    if ( version_compare( $current_version, BOTKIBBLE_VERSION, '<' ) ) {
        flush_rewrite_rules();
        update_option( 'botkibble_version', BOTKIBBLE_VERSION, true );
    }
} );

register_activation_hook( __FILE__, function () {
    // init may have already fired by the time activation runs, so the
    // add_action('init', ...) callback in routing.php never executes.
    // Register the rule directly before flushing to guarantee it's saved.
    botkibble_register_rewrite_rule();
    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function () {
    flush_rewrite_rules();
    // Clear the version so the version-bump check re-flushes on reactivation,
    // even if the same version is reinstalled.
    delete_option( 'botkibble_version' );
} );

/**
 * Wipe the entire markdown cache when any plugin is activated, deactivated,
 * or switched themes. Filters like botkibble_output, botkibble_frontmatter,
 * and botkibble_clean_html may have changed — cached output is untrusted.
 */
add_action( 'activated_plugin', 'botkibble_flush_entire_cache' );
add_action( 'deactivated_plugin', 'botkibble_flush_entire_cache' );
add_action( 'switch_theme', 'botkibble_flush_entire_cache' );

function botkibble_flush_entire_cache(): void {
    $upload_dir = wp_upload_dir();
    $cache_dir  = $upload_dir['basedir'] . '/botkibble-cache';

    if ( ! is_dir( $cache_dir ) ) {
        return;
    }

    botkibble_rmdir_contents( $cache_dir );
    botkibble_protect_directory( $cache_dir );
}

/**
 * Recursively delete all files inside a directory, preserving the directory itself.
 */
function botkibble_rmdir_contents( string $dir ): void {
    $entries = @scandir( $dir );
    if ( false === $entries ) {
        botkibble_log( 'failed to scan cache directory: ' . $dir );
        return;
    }

    foreach ( $entries as $entry ) {
        if ( '.' === $entry || '..' === $entry ) {
            continue;
        }

        $path = $dir . '/' . $entry;

        // Never follow symlinks — remove the link itself and move on.
        if ( is_link( $path ) ) {
            wp_delete_file( $path );
            continue;
        }

        if ( is_dir( $path ) ) {
            botkibble_rmdir_contents( $path );
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- no wp_rmdir() exists; deleting our own cache directory.
            @rmdir( $path );
        } else {
            botkibble_safe_unlink( $path );
        }
    }
}
