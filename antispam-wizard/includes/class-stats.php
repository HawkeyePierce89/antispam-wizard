<?php
/**
 * Statistics tracking for spam detection.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Tracks and retrieves spam detection statistics stored in wp_options.
 */
class ANTISPAMWIZARD_Stats {

    const OPTION_KEY = 'antispamwizard_stats';

    /**
     * Return the directory used for lock files, creating it if necessary.
     *
     * @return string Absolute path to the lock directory.
     */
    private static function get_lock_dir(): string {
        $upload_dir = wp_upload_dir();
        $lock_dir   = $upload_dir['basedir'] . '/.antispamwizard-locks';
        if ( ! is_dir( $lock_dir ) ) {
            wp_mkdir_p( $lock_dir );
            $htaccess = $lock_dir . '/.htaccess';
            if ( ! file_exists( $htaccess ) ) {
                @file_put_contents( $htaccess, "# Apache 2.4+\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n\n# Apache 2.2\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n" );
            }
            $index = $lock_dir . '/index.php';
            if ( ! file_exists( $index ) ) {
                @file_put_contents( $index, "<?php\n// Silence is golden.\n" );
            }
        }
        return $lock_dir;
    }

    /**
     * Get default stats structure.
     *
     * @return array
     */
    public static function get_defaults(): array {
        return array(
            'total_blocked'      => 0,
            'per_check'          => array(),
            'last_blocked_date'  => '',
        );
    }

    /**
     * Get current statistics.
     *
     * @return array
     */
    public static function get_all(): array {
        $stats = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $stats ) ) {
            return self::get_defaults();
        }

        $merged = array_merge( self::get_defaults(), $stats );

        // Validate types — reset to defaults if corrupted.
        if ( ! is_int( $merged['total_blocked'] ) || $merged['total_blocked'] < 0 ) {
            $merged['total_blocked'] = 0;
        }
        if ( ! is_string( $merged['last_blocked_date'] ) ) {
            $merged['last_blocked_date'] = '';
        }
        if ( ! is_array( $merged['per_check'] ) ) {
            $merged['per_check'] = array();
        } else {
            // Normalize inner values to non-negative integers — corrupted option
            // data could contain strings or negatives which break arithmetic and
            // sorting downstream.
            foreach ( $merged['per_check'] as $key => $value ) {
                $val = is_int( $value ) ? $value : (int) $value;
                $merged['per_check'][ $key ] = max( 0, $val );
            }
        }

        return $merged;
    }

    /**
     * Record a spam detection event.
     *
     * @param array $check_names List of check names that flagged the comment.
     */
    public static function record( array $check_names ): void {
        $lock_file = self::get_lock_dir() . '/antispamwizard_stats_' . md5( ABSPATH ) . '.lock';
        $fp        = @fopen( $lock_file, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- flock() needs a real stream handle; WP_Filesystem exposes no locking primitive. The @ suppresses PHP's own "failed to open stream" warning, which carries the absolute path into the web-readable debug.log; the sanitized diagnostic below reports the failure instead.
        if ( false === $fp ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational diagnostic: the lock file could not be opened, so this write proceeds unserialised. Silent failure would make concurrent-write corruption undebuggable; the log line carries no user input.
            error_log( 'ANTISPAMWIZARD: unable to open lock file: ' . basename( $lock_file ) );
        }
        $locked = $fp && flock( $fp, LOCK_EX );

        try {
            wp_cache_delete( self::OPTION_KEY, 'options' );
            $stats = self::get_all();

            $stats['total_blocked']++;
            $stats['last_blocked_date'] = current_time( 'mysql' );

            foreach ( $check_names as $name ) {
                if ( isset( $stats['per_check'][ $name ] ) ) {
                    $stats['per_check'][ $name ]++;
                } else {
                    $stats['per_check'][ $name ] = 1;
                }
            }

            update_option( self::OPTION_KEY, $stats, false );
        } finally {
            if ( $locked ) {
                flock( $fp, LOCK_UN );
            }
            if ( $fp ) {
                fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the flock() stream handle opened above; WP_Filesystem exposes no locking primitive.
            }
        }
    }

    /**
     * Reset all statistics.
     */
    public static function clear(): void {
        $lock_file = self::get_lock_dir() . '/antispamwizard_stats_' . md5( ABSPATH ) . '.lock';
        $fp        = @fopen( $lock_file, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- flock() needs a real stream handle; WP_Filesystem exposes no locking primitive. The @ suppresses PHP's own "failed to open stream" warning, which carries the absolute path into the web-readable debug.log; the sanitized diagnostic below reports the failure instead.
        if ( false === $fp ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational diagnostic: the lock file could not be opened, so this write proceeds unserialised. Silent failure would make concurrent-write corruption undebuggable; the log line carries no user input.
            error_log( 'ANTISPAMWIZARD: unable to open lock file: ' . basename( $lock_file ) );
        }
        $locked = $fp && flock( $fp, LOCK_EX );

        try {
            delete_option( self::OPTION_KEY );
        } finally {
            if ( $locked ) {
                flock( $fp, LOCK_UN );
            }
            if ( $fp ) {
                fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the flock() stream handle opened above; WP_Filesystem exposes no locking primitive.
            }
        }
    }
}
