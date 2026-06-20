<?php
/**
 * GitHub release update integration.
 *
 * @package CaffeOnline_Feed_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

final class COFS_Update_Checker {
    const REPOSITORY_URL = 'https://github.com/webjungle/caffeonline-feed-sync/';
    const RELEASE_ASSET  = '/caffeonline-feed-sync\.zip($|[?&#])/i';
    const SLUG           = 'caffeonline-feed-sync';

    /**
     * Tracks whether the update checker has already been initialized.
     *
     * @var bool
     */
    private static $initialized = false;

    /**
     * Initialize Plugin Update Checker if the production dependency exists.
     *
     * @param string $main_plugin_file Absolute path to the main plugin file.
     * @return void
     */
    public static function init( $main_plugin_file ) {
        if ( self::$initialized ) {
            return;
        }

        self::$initialized = true;

        if ( ! class_exists( PucFactory::class ) ) {
            return;
        }

        try {
            $update_checker = PucFactory::buildUpdateChecker(
                self::REPOSITORY_URL,
                $main_plugin_file,
                self::SLUG
            );

            $vcs_api = $update_checker->getVcsApi();
            if ( is_object( $vcs_api ) && method_exists( $vcs_api, 'enableReleaseAssets' ) ) {
                $vcs_api->enableReleaseAssets( self::RELEASE_ASSET );
            }
        } catch ( Throwable $exception ) {
            self::maybe_log_error( $exception );
        }
    }

    /**
     * Log update-checker failures only for debug environments.
     *
     * @param Throwable $exception Caught update-checker exception.
     * @return void
     */
    private static function maybe_log_error( Throwable $exception ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
            error_log( 'CaffeOnline Feed Sync update checker failed: ' . $exception->getMessage() );
        }
    }
}
