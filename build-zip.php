<?php
/**
 * Package the plugin into a distributable .zip file.
 *
 * Usage: php build-zip.php
 *
 * Produces: markdown-for-agents.zip in the current directory,
 * containing a markdown-for-agents/ folder ready to install via
 * WordPress > Plugins > Add New > Upload Plugin.
 */

$plugin_slug = 'markdown-for-agents';

// Files and directories to include (relative to this script).
$include = [
    'markdown-for-agents.php',
    'readme.txt',
    'composer.json',
    'includes/',
    'vendor/',
];

// Directories inside vendor/ that aren't needed at runtime.
$vendor_skip = [
    '.github',
    'bin',
    'test',
    'tests',
    'Test',
    'Tests',
    'doc',
    'docs',
];

$base     = __DIR__;
$zip_path = $base . '/' . $plugin_slug . '.zip';
$tmp_dir  = sys_get_temp_dir() . '/' . $plugin_slug . '-build-' . getmypid();
$stage    = $tmp_dir . '/' . $plugin_slug;

// Clean up any previous run.
if ( file_exists( $zip_path ) ) {
    unlink( $zip_path );
}
exec( 'rm -rf ' . escapeshellarg( $tmp_dir ) );
mkdir( $stage, 0755, true );

// Copy included files into the staging directory.
foreach ( $include as $entry ) {
    $src = $base . '/' . $entry;
    $dst = $stage . '/' . $entry;

    if ( is_file( $src ) ) {
        copy( $src, $dst );
        continue;
    }

    if ( ! is_dir( $src ) ) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ( $iterator as $file ) {
        $relative = substr( $file->getPathname(), strlen( $base ) + 1 );

        // Skip non-runtime directories inside vendor/.
        if ( str_starts_with( $relative, 'vendor/' ) ) {
            foreach ( $vendor_skip as $skip ) {
                if ( str_contains( $relative, '/' . $skip . '/' ) || str_ends_with( $relative, '/' . $skip ) ) {
                    continue 2;
                }
            }
        }

        $target = $stage . '/' . $relative;
        if ( $file->isDir() ) {
            if ( ! is_dir( $target ) ) {
                mkdir( $target, 0755, true );
            }
        } else {
            $dir = dirname( $target );
            if ( ! is_dir( $dir ) ) {
                mkdir( $dir, 0755, true );
            }
            copy( $file->getPathname(), $target );
        }
    }
}

// Build the zip from the staging directory.
$cmd = sprintf(
    'cd %s && zip -r %s %s',
    escapeshellarg( $tmp_dir ),
    escapeshellarg( $zip_path ),
    escapeshellarg( $plugin_slug )
);

exec( $cmd, $output, $code );
exec( 'rm -rf ' . escapeshellarg( $tmp_dir ) );

if ( 0 !== $code ) {
    fwrite( STDERR, "zip command failed (exit $code)\n" );
    exit( 1 );
}

$size = round( filesize( $zip_path ) / 1024 );
echo "Created $zip_path ({$size} KB)\n";
