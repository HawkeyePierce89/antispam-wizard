<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes all plugin data from the database.
 *
 * @package AntiSpamWizard
 */

// Abort if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Remove all plugin options.
delete_option( 'asw_settings' );
delete_option( 'asw_spam_log' );
delete_option( 'asw_stats' );
delete_option( 'asw_shadow_display_after' );

// Remove duplicate-check transients and classifier cache transients.
global $wpdb;
$like_dup              = $wpdb->esc_like( '_transient_asw_dup_' ) . '%';
$like_dup_timeout      = $wpdb->esc_like( '_transient_timeout_asw_dup_' ) . '%';
$like_classifiers      = $wpdb->esc_like( '_transient_asw_classifiers_' ) . '%';
$like_classifiers_timeout = $wpdb->esc_like( '_transient_timeout_asw_classifiers_' ) . '%';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot uninstall cleanup: there is no core API for deleting options by LIKE pattern, and caching a query whose entire purpose is to erase the rows it matches would be meaningless.
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM `{$wpdb->options}` WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
        $like_dup,
        $like_dup_timeout,
        $like_classifiers,
        $like_classifiers_timeout
    )
);

// Remove comment meta added by the plugin.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-shot uninstall cleanup: a single DELETE erases every row at once, where the core alternative (delete_metadata() with $delete_all) would additionally walk and invalidate per-comment meta caches that are about to be discarded anyway; the rows are being erased, so caching does not apply. The unindexed meta_key lookup runs exactly once, at uninstall, and is the only way to address the rows.
$wpdb->delete( $wpdb->commentmeta, array( 'meta_key' => '_asw_reason' ) );

// Drop the samples table.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-shot uninstall DDL on a table this plugin owns and created on activation; dropping a table has no cacheable result and no core API.
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}asw_samples`" );

// Remove the samples revision and schema version options.
delete_option( 'asw_samples_revision' );
delete_option( 'asw_samples_db_version' );

// Remove lock files directory.
$upload_dir = wp_upload_dir();
$lock_dir   = $upload_dir['basedir'] . '/.asw-locks';
if ( is_dir( $lock_dir ) ) {
    // Remove all files including dotfiles (.htaccess, index.php).
    foreach ( array( $lock_dir . '/*', $lock_dir . '/.*' ) as $pattern ) {
        $files = glob( $pattern );
        if ( is_array( $files ) ) {
            foreach ( $files as $file ) {
                if ( is_file( $file ) ) {
                    wp_delete_file( $file );
                }
            }
        }
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes an empty plugin-owned directory; WP_Filesystem is not bootstrapped during uninstall.
    rmdir( $lock_dir );
}
