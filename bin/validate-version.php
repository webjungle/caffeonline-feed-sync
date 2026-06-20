<?php
/**
 * Validate release version consistency.
 *
 * @package CaffeOnline_Feed_Sync
 */

$root        = dirname( __DIR__ );
$plugin_file = $root . '/caffeonline-feed-sync.php';
$errors      = array();
$versions    = array();

function cofs_release_read_file( $file ) {
    if ( ! is_readable( $file ) ) {
        fwrite( STDERR, "Required file is not readable: {$file}\n" );
        exit( 1 );
    }

    return file_get_contents( $file );
}

function cofs_release_normalize_version( $version ) {
    return preg_replace( '/^v/i', '', trim( (string) $version ) );
}

function cofs_release_add_version( &$versions, $label, $version ) {
    $version = trim( (string) $version );
    if ( '' !== $version && 'trunk' !== strtolower( $version ) ) {
        $versions[ $label ] = cofs_release_normalize_version( $version );
    }
}

$plugin_source = cofs_release_read_file( $plugin_file );

if ( preg_match( '/^\s*\*\s*Version:\s*(.+)$/mi', $plugin_source, $match ) ) {
    cofs_release_add_version( $versions, 'Plugin header', $match[1] );
} else {
    $errors[] = 'Plugin header Version field is missing.';
}

if ( preg_match( "/define\(\s*['\"]COFS_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $plugin_source, $match ) ) {
    cofs_release_add_version( $versions, 'COFS_VERSION', $match[1] );
} else {
    $errors[] = 'COFS_VERSION constant is missing.';
}

if ( ! preg_match( '/^\s*\*\s*Update URI:\s*https:\/\/github\.com\/webjungle\/caffeonline-feed-sync\s*$/mi', $plugin_source ) ) {
    $errors[] = 'Plugin header Update URI is missing or unexpected.';
}

$readme_md = $root . '/README.md';
if ( is_readable( $readme_md ) ) {
    $readme_source = file_get_contents( $readme_md );
    if ( preg_match( '/Aktuelle Plugin-Version:\*\*\s*`([^`]+)`/', $readme_source, $match ) ) {
        cofs_release_add_version( $versions, 'README.md', $match[1] );
    }
}

$readme_txt = $root . '/readme.txt';
if ( is_readable( $readme_txt ) ) {
    $readme_txt_source = file_get_contents( $readme_txt );
    if ( preg_match( '/^Stable tag:\s*(.+)$/mi', $readme_txt_source, $match ) ) {
        cofs_release_add_version( $versions, 'readme.txt Stable tag', $match[1] );
    }
}

$package_json = $root . '/package.json';
if ( is_readable( $package_json ) ) {
    $package = json_decode( file_get_contents( $package_json ), true );
    if ( is_array( $package ) && isset( $package['version'] ) ) {
        cofs_release_add_version( $versions, 'package.json', $package['version'] );
    }
}

$expected_tag = null;
foreach ( array_slice( $argv, 1 ) as $index => $arg ) {
    if ( 0 === strpos( $arg, '--tag=' ) ) {
        $expected_tag = substr( $arg, 6 );
        break;
    }

    if ( '--tag' === $arg && isset( $argv[ $index + 2 ] ) ) {
        $expected_tag = $argv[ $index + 2 ];
        break;
    }
}

if ( null !== $expected_tag ) {
    if ( ! preg_match( '/^v?\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $expected_tag ) ) {
        $errors[] = "Release tag '{$expected_tag}' is not a semantic version tag.";
    } else {
        cofs_release_add_version( $versions, 'Release tag', $expected_tag );
    }
}

if ( empty( $versions['Plugin header'] ) ) {
    $errors[] = 'Cannot compare versions without the plugin header version.';
} else {
    $expected = $versions['Plugin header'];
    foreach ( $versions as $label => $version ) {
        if ( $version !== $expected ) {
            $errors[] = "{$label} version '{$version}' does not match plugin header version '{$expected}'.";
        }
    }
}

if ( ! empty( $errors ) ) {
    fwrite( STDERR, "Version validation failed:\n- " . implode( "\n- ", $errors ) . "\n" );
    exit( 1 );
}

echo 'Version validation passed: ' . $versions['Plugin header'] . PHP_EOL;
