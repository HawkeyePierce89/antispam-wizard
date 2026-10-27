<?php

use PHPUnit\Framework\TestCase;

class UninstallTest extends TestCase {

    protected function setUp(): void {
        global $wp_options, $wpdb, $wp_deleted_files;
        $wp_options       = array();
        $wp_deleted_files = array();
        $wpdb->reset_tables();
        $this->remove_lock_dir();
    }

    protected function tearDown(): void {
        $this->remove_lock_dir();
    }

    /**
     * Absolute path of the runtime lock directory the uninstaller cleans up.
     */
    private function lock_dir(): string {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/.antispamwizard-locks';
    }

    /**
     * Deletes the lock directory (including dotfiles) so tests start from a known state.
     */
    private function remove_lock_dir(): void {
        $dir = $this->lock_dir();
        if ( ! is_dir( $dir ) ) {
            return;
        }
        foreach ( array( $dir . '/*', $dir . '/.*' ) as $pattern ) {
            $files = glob( $pattern );
            if ( is_array( $files ) ) {
                foreach ( $files as $file ) {
                    if ( is_file( $file ) ) {
                        unlink( $file );
                    }
                }
            }
        }
        rmdir( $dir );
    }

    /**
     * Runs the uninstaller exactly as WordPress would.
     */
    private function run_uninstall(): void {
        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }
        require dirname( __DIR__ ) . '/antispam-wizard/uninstall.php';
    }

    /**
     * Asserts the full removal set: options, transients, comment meta, samples table.
     */
    private function assertRemovalSetUnchanged(): void {
        global $wp_options, $wpdb;

        $this->assertArrayNotHasKey( 'antispamwizard_settings', $wp_options );
        $this->assertArrayNotHasKey( 'antispamwizard_spam_log', $wp_options );
        $this->assertArrayNotHasKey( 'antispamwizard_stats', $wp_options );
        $this->assertArrayNotHasKey( 'antispamwizard_samples_revision', $wp_options );
        $this->assertArrayNotHasKey( 'antispamwizard_samples_db_version', $wp_options );
        $this->assertArrayNotHasKey( 'antispamwizard_shadow_display_after', $wp_options );

        $this->assertNotEmpty( $wpdb->queries );
        $this->assertStringContainsString( 'antispamwizard\\_dup\\_', $wpdb->queries[0] );
        $this->assertStringContainsString( 'antispamwizard\\_classifiers\\_', $wpdb->queries[0] );

        $this->assertNotEmpty( $wpdb->deletes );
        $this->assertSame( 'wp_commentmeta', $wpdb->deletes[0]['table'] );
        $this->assertSame( '_antispamwizard_reason', $wpdb->deletes[0]['where']['meta_key'] );

        $drop_found = false;
        foreach ( $wpdb->queries as $q ) {
            if ( strpos( $q, 'DROP TABLE IF EXISTS `wp_antispamwizard_samples`' ) !== false ) {
                $drop_found = true;
                break;
            }
        }
        $this->assertTrue( $drop_found, 'DROP TABLE query for samples table should be issued during uninstall' );
    }

    public function test_uninstall_removes_all_plugin_options() {
        // Populate options that the plugin creates.
        update_option( 'antispamwizard_settings', array( 'links_max' => 5 ) );
        update_option( 'antispamwizard_spam_log', array( 'entry1' ) );
        update_option( 'antispamwizard_stats', array( 'total' => 10 ) );
        update_option( 'antispamwizard_samples_revision', 5 );
        update_option( 'antispamwizard_samples_db_version', '2' );
        update_option( 'antispamwizard_shadow_display_after', 41 );

        // Verify they exist.
        global $wp_options;
        $this->assertArrayHasKey( 'antispamwizard_settings', $wp_options );
        $this->assertArrayHasKey( 'antispamwizard_spam_log', $wp_options );
        $this->assertArrayHasKey( 'antispamwizard_stats', $wp_options );
        $this->assertArrayHasKey( 'antispamwizard_samples_revision', $wp_options );
        $this->assertArrayHasKey( 'antispamwizard_samples_db_version', $wp_options );

        // Simulate uninstall (define the guard constant, then include uninstall.php).
        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }
        require dirname( __DIR__ ) . '/antispam-wizard/uninstall.php';

        // All plugin options should be gone.
        $this->assertArrayNotHasKey( 'antispamwizard_settings', $wp_options );
        $this->assertArrayNotHasKey( 'antispamwizard_spam_log', $wp_options );
        $this->assertArrayNotHasKey( 'antispamwizard_stats', $wp_options );
        $this->assertArrayNotHasKey( 'antispamwizard_samples_revision', $wp_options );
        $this->assertArrayNotHasKey( 'antispamwizard_samples_db_version', $wp_options );

        // Verify transient cleanup query was issued.
        global $wpdb;
        $this->assertNotEmpty( $wpdb->queries );
        $this->assertStringContainsString( 'antispamwizard\_dup\_', $wpdb->queries[0] );

        // Verify comment meta cleanup was issued.
        $this->assertNotEmpty( $wpdb->deletes );
        $this->assertSame( 'wp_commentmeta', $wpdb->deletes[0]['table'] );
        $this->assertSame( '_antispamwizard_reason', $wpdb->deletes[0]['where']['meta_key'] );

        // Verify samples table DROP query was issued.
        $drop_found = false;
        foreach ( $wpdb->queries as $q ) {
            if ( strpos( $q, 'DROP TABLE IF EXISTS `wp_antispamwizard_samples`' ) !== false ) {
                $drop_found = true;
                break;
            }
        }
        $this->assertTrue( $drop_found, 'DROP TABLE query for samples table should be issued during uninstall' );
    }

    public function test_uninstall_removes_lock_directory_including_dotfiles() {
        $dir = $this->lock_dir();
        $this->assertTrue( wp_mkdir_p( $dir ) );

        $lock     = $dir . '/antispamwizard_spam_log_' . md5( ABSPATH ) . '.lock';
        $index    = $dir . '/index.php';
        $htaccess = $dir . '/.htaccess';
        file_put_contents( $lock, '' );
        file_put_contents( $index, '<?php // Silence is golden.' );
        file_put_contents( $htaccess, 'Deny from all' );

        $this->assertFileExists( $lock );
        $this->assertFileExists( $index );
        $this->assertFileExists( $htaccess );

        // The rest of the removal set must still be removed alongside the files.
        update_option( 'antispamwizard_settings', array( 'links_max' => 5 ) );
        update_option( 'antispamwizard_spam_log', array( 'entry1' ) );
        update_option( 'antispamwizard_stats', array( 'total' => 10 ) );
        update_option( 'antispamwizard_samples_revision', 5 );
        update_option( 'antispamwizard_samples_db_version', '2' );

        $this->run_uninstall();

        $this->assertFileDoesNotExist( $lock );
        $this->assertFileDoesNotExist( $index );
        $this->assertFileDoesNotExist( $htaccess, 'The dotfile guard must be removed by the dotfile-aware glob' );
        $this->assertDirectoryDoesNotExist( $dir );

        $this->assertRemovalSetUnchanged();
    }

    public function test_uninstall_deletes_files_through_wp_delete_file() {
        global $wp_deleted_files;

        $dir = $this->lock_dir();
        $this->assertTrue( wp_mkdir_p( $dir ) );
        $lock     = $dir . '/antispamwizard_dup_ab.lock';
        $htaccess = $dir . '/.htaccess';
        file_put_contents( $lock, '' );
        file_put_contents( $htaccess, 'Deny from all' );

        $this->run_uninstall();

        // wp_delete_file() is the WordPress API; unlink() must not be used directly.
        $this->assertContains( $lock, $wp_deleted_files );
        $this->assertContains( $htaccess, $wp_deleted_files );
        $this->assertStringNotContainsString(
            'unlink(',
            file_get_contents( dirname( __DIR__ ) . '/antispam-wizard/uninstall.php' ),
            'uninstall.php must use wp_delete_file() rather than unlink()'
        );
    }

    public function test_uninstall_is_a_noop_when_lock_directory_is_absent() {
        global $wp_deleted_files;

        $this->assertDirectoryDoesNotExist( $this->lock_dir() );

        update_option( 'antispamwizard_settings', array( 'links_max' => 5 ) );
        update_option( 'antispamwizard_spam_log', array( 'entry1' ) );
        update_option( 'antispamwizard_stats', array( 'total' => 10 ) );
        update_option( 'antispamwizard_samples_revision', 5 );
        update_option( 'antispamwizard_samples_db_version', '2' );

        $this->run_uninstall();

        $this->assertSame( array(), $wp_deleted_files );
        $this->assertRemovalSetUnchanged();
    }

    public function test_lock_file_operations_carry_annotated_phpcs_ignores() {
        $files = array(
            'antispam-wizard/includes/class-spam-log.php',
            'antispam-wizard/includes/class-stats.php',
            'antispam-wizard/includes/checks/class-duplicate-check.php',
        );

        foreach ( $files as $relative ) {
            $lines = file( dirname( __DIR__ ) . '/' . $relative );
            foreach ( $lines as $i => $line ) {
                foreach ( array( 'fopen', 'fclose' ) as $fn ) {
                    if ( strpos( $line, $fn . '(' ) === false ) {
                        continue;
                    }
                    $expected = 'phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_' . $fn;
                    $this->assertStringContainsString(
                        $expected,
                        $line,
                        sprintf( '%s:%d must carry an annotated %s() suppression', $relative, $i + 1, $fn )
                    );
                    $this->assertStringContainsString(
                        ' -- ',
                        $line,
                        sprintf( '%s:%d suppression must carry a reason', $relative, $i + 1 )
                    );
                }
            }
        }
    }

    public function test_uninstall_rmdir_carries_an_annotated_phpcs_ignore() {
        $source = file_get_contents( dirname( __DIR__ ) . '/antispam-wizard/uninstall.php' );
        $this->assertMatchesRegularExpression(
            '/phpcs:ignore WordPress\\.WP\\.AlternativeFunctions\\.file_system_operations_rmdir -- \\S.*\\n\\s*rmdir\\( \\$lock_dir \\);/',
            $source
        );
    }
}
