<?php
/**
 * Tests for ASW_Stats.
 */

use PHPUnit\Framework\TestCase;

class StatsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        global $wp_options, $wp_cache_deletes;
        $wp_options       = array();
        $wp_cache_deletes = array();
    }

    public function test_get_all_returns_defaults_when_no_data(): void {
        $stats = ASW_Stats::get_all();

        $this->assertSame( 0, $stats['total_blocked'] );
        $this->assertSame( array(), $stats['per_check'] );
        $this->assertSame( '', $stats['last_blocked_date'] );
    }

    public function test_record_increments_total_blocked(): void {
        ASW_Stats::record( array( 'links' ) );

        $stats = ASW_Stats::get_all();
        $this->assertSame( 1, $stats['total_blocked'] );
    }

    public function test_record_increments_per_check_counts(): void {
        ASW_Stats::record( array( 'links', 'emoji' ) );

        $stats = ASW_Stats::get_all();
        $this->assertSame( 1, $stats['per_check']['links'] );
        $this->assertSame( 1, $stats['per_check']['emoji'] );
    }

    public function test_record_accumulates_per_check_over_multiple_calls(): void {
        ASW_Stats::record( array( 'links' ) );
        ASW_Stats::record( array( 'links', 'stop_words' ) );
        ASW_Stats::record( array( 'emoji' ) );

        $stats = ASW_Stats::get_all();
        $this->assertSame( 3, $stats['total_blocked'] );
        $this->assertSame( 2, $stats['per_check']['links'] );
        $this->assertSame( 1, $stats['per_check']['stop_words'] );
        $this->assertSame( 1, $stats['per_check']['emoji'] );
    }

    public function test_record_sets_last_blocked_date(): void {
        ASW_Stats::record( array( 'links' ) );

        $stats = ASW_Stats::get_all();
        $this->assertNotEmpty( $stats['last_blocked_date'] );
        // Should be a MySQL-formatted date.
        $this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $stats['last_blocked_date'] );
    }

    public function test_clear_resets_all_stats(): void {
        ASW_Stats::record( array( 'links', 'emoji' ) );
        ASW_Stats::record( array( 'stop_words' ) );

        ASW_Stats::clear();

        $stats = ASW_Stats::get_all();
        $this->assertSame( 0, $stats['total_blocked'] );
        $this->assertSame( array(), $stats['per_check'] );
        $this->assertSame( '', $stats['last_blocked_date'] );
    }

    public function test_per_check_breakdown_accuracy_with_many_checks(): void {
        // Simulate various spam detections.
        for ( $i = 0; $i < 5; $i++ ) {
            ASW_Stats::record( array( 'links' ) );
        }
        for ( $i = 0; $i < 3; $i++ ) {
            ASW_Stats::record( array( 'emoji', 'multi_lang' ) );
        }
        ASW_Stats::record( array( 'duplicate' ) );

        $stats = ASW_Stats::get_all();
        $this->assertSame( 9, $stats['total_blocked'] );
        $this->assertSame( 5, $stats['per_check']['links'] );
        $this->assertSame( 3, $stats['per_check']['emoji'] );
        $this->assertSame( 3, $stats['per_check']['multi_lang'] );
        $this->assertSame( 1, $stats['per_check']['duplicate'] );
    }

    public function test_detector_records_stats_on_spam(): void {
        // Create a mock check that returns spam.
        $spam_check = new class implements ASW_Check {
            public function check( array $comment_data ): ASW_Check_Result {
                return new ASW_Check_Result( true, 'test_check', 'test details' );
            }
        };

        $detector = new ASW_Detector( array( $spam_check ) );
        $detector->filter_comment( 1, array(
            'comment_content'   => 'spam comment',
            'comment_author_IP' => '127.0.0.1',
        ) );

        $stats = ASW_Stats::get_all();
        $this->assertSame( 1, $stats['total_blocked'] );
        $this->assertSame( 1, $stats['per_check']['test_check'] );
    }

    public function test_get_all_resets_corrupted_total_blocked_to_default(): void {
        global $wp_options;
        $wp_options[ ASW_Stats::OPTION_KEY ] = array(
            'total_blocked' => 'not_an_int',
            'per_check'     => array( 'links' => 2 ),
        );

        $stats = ASW_Stats::get_all();
        $this->assertSame( 0, $stats['total_blocked'] );
        $this->assertSame( array( 'links' => 2 ), $stats['per_check'] );
    }

    public function test_get_all_resets_corrupted_per_check_to_default(): void {
        global $wp_options;
        $wp_options[ ASW_Stats::OPTION_KEY ] = array(
            'total_blocked' => 5,
            'per_check'     => 'not_an_array',
        );

        $stats = ASW_Stats::get_all();
        $this->assertSame( 5, $stats['total_blocked'] );
        $this->assertSame( array(), $stats['per_check'] );
    }

    public function test_get_all_normalizes_corrupted_per_check_values_to_integers(): void {
        global $wp_options;
        $wp_options[ ASW_Stats::OPTION_KEY ] = array(
            'total_blocked' => 3,
            'per_check'     => array( 'links' => 'bad', 'emoji' => 2.5, 'spacing' => 7 ),
        );

        $stats = ASW_Stats::get_all();
        $this->assertSame( 0, $stats['per_check']['links'] );
        $this->assertSame( 2, $stats['per_check']['emoji'] );
        $this->assertSame( 7, $stats['per_check']['spacing'] );
    }

    public function test_get_all_resets_corrupted_last_blocked_date_to_default(): void {
        global $wp_options;
        $wp_options[ ASW_Stats::OPTION_KEY ] = array(
            'total_blocked'     => 1,
            'per_check'         => array(),
            'last_blocked_date' => array( 'not', 'a', 'string' ),
        );

        $stats = ASW_Stats::get_all();
        $this->assertSame( '', $stats['last_blocked_date'] );
        $this->assertSame( 1, $stats['total_blocked'] );
    }

    public function test_get_all_clamps_negative_total_blocked_to_zero(): void {
        global $wp_options;
        $wp_options[ ASW_Stats::OPTION_KEY ] = array(
            'total_blocked' => -5,
            'per_check'     => array(),
        );

        $stats = ASW_Stats::get_all();
        $this->assertSame( 0, $stats['total_blocked'] );
    }

    public function test_get_all_clamps_negative_per_check_values_to_zero(): void {
        global $wp_options;
        $wp_options[ ASW_Stats::OPTION_KEY ] = array(
            'total_blocked' => 3,
            'per_check'     => array( 'links' => -10, 'emoji' => 5 ),
        );

        $stats = ASW_Stats::get_all();
        $this->assertSame( 0, $stats['per_check']['links'] );
        $this->assertSame( 5, $stats['per_check']['emoji'] );
    }

    public function test_get_all_returns_defaults_for_non_array_option(): void {
        global $wp_options;
        $wp_options[ ASW_Stats::OPTION_KEY ] = 'corrupted';

        $stats = ASW_Stats::get_all();
        $this->assertSame( 0, $stats['total_blocked'] );
        $this->assertSame( array(), $stats['per_check'] );
        $this->assertSame( '', $stats['last_blocked_date'] );
    }

    public function test_lock_files_use_uploads_directory(): void {
        $upload_dir = wp_upload_dir();
        $lock_dir   = $upload_dir['basedir'] . '/.asw-locks';

        ASW_Stats::record( array( 'links' ) );

        $this->assertDirectoryExists( $lock_dir );
        $lock_file = $lock_dir . '/asw_stats_' . md5( ABSPATH ) . '.lock';
        $this->assertFileExists( $lock_file );
    }

    public function test_record_succeeds_when_lock_file_fails(): void {
        $upload_dir = wp_upload_dir();
        $lock_dir   = $upload_dir['basedir'] . '/.asw-locks';

        wp_mkdir_p( $lock_dir );
        chmod( $lock_dir, 0000 );

        try {
            @ASW_Stats::record( array( 'links' ) );
            $stats = ASW_Stats::get_all();
            $this->assertSame( 1, $stats['total_blocked'] );
        } finally {
            chmod( $lock_dir, 0755 );
        }
    }

    public function test_record_invalidates_object_cache_before_read(): void {
        global $wp_cache_deletes;

        ASW_Stats::record( array( 'links' ) );

        $stats_deletes = array_filter( $wp_cache_deletes, function ( $d ) {
            return $d['key'] === 'asw_stats' && $d['group'] === 'options';
        } );
        $this->assertNotEmpty( $stats_deletes, 'record should call wp_cache_delete for stats option' );
    }

    public function test_detector_does_not_record_stats_on_ham(): void {
        $ham_check = new class implements ASW_Check {
            public function check( array $comment_data ): ASW_Check_Result {
                return new ASW_Check_Result( false, 'test_check', '' );
            }
        };

        $detector = new ASW_Detector( array( $ham_check ) );
        $detector->filter_comment( 1, array(
            'comment_content'   => 'legit comment',
            'comment_author_IP' => '127.0.0.1',
        ) );

        $stats = ASW_Stats::get_all();
        $this->assertSame( 0, $stats['total_blocked'] );
    }

    public function test_record_creates_lock_dir_with_hardening_files_on_first_run(): void {
        $upload   = wp_upload_dir();
        $lock_dir = $upload['basedir'] . '/.asw-locks';

        // Remove the lock dir so record() hits the first-run creation branch that
        // also writes the .htaccess and index.php hardening files. scandir picks
        // up dotfiles (.htaccess) that a plain glob('/*') would miss.
        if ( is_dir( $lock_dir ) ) {
            foreach ( scandir( $lock_dir ) as $entry ) {
                if ( '.' !== $entry && '..' !== $entry ) {
                    @unlink( $lock_dir . '/' . $entry );
                }
            }
            @rmdir( $lock_dir );
        }
        $this->assertDirectoryDoesNotExist( $lock_dir );

        ASW_Stats::record( array( 'links' ) );

        $this->assertDirectoryExists( $lock_dir );
        $this->assertFileExists( $lock_dir . '/.htaccess' );
        $this->assertFileExists( $lock_dir . '/index.php' );
        $this->assertStringContainsString( 'Require all denied', file_get_contents( $lock_dir . '/.htaccess' ) );
    }

    /**
     * A lock file that cannot be opened must be reported by name only.
     *
     * ASW_Stats::record() runs on an unauthenticated comment submission and
     * debug.log is web-readable on misconfigured hosts, so neither the plugin's
     * own diagnostic nor PHP's fopen() warning may carry the absolute path --
     * it names the document root. Same rule as ASW_Detector::run()'s
     * basename(), and the same technique as
     * DuplicateCheckTest::test_duplicate_check_still_counts_when_the_lock_file_cannot_be_opened():
     * the tests run as root, so chmod 0000 does not make fopen() fail, but a
     * *directory* at the lock file's path does, for every user.
     */
    public function test_record_reports_an_unopenable_lock_file_without_its_path(): void {
        $upload_dir = wp_upload_dir();
        $lock_dir   = $upload_dir['basedir'] . '/.asw-locks';
        $lock_file  = $lock_dir . '/asw_stats_' . md5( ABSPATH ) . '.lock';

        if ( ! is_dir( $lock_dir ) ) {
            mkdir( $lock_dir, 0777, true );
        }
        if ( is_file( $lock_file ) ) {
            unlink( $lock_file );
        }
        mkdir( $lock_file );

        $log      = tempnam( sys_get_temp_dir(), 'asw-lock-log-' );
        $previous = ini_set( 'error_log', $log );

        try {
            $this->assertFalse( @fopen( $lock_file, 'c' ), 'the fallback branch must actually be reachable' );

            // No @ on this call: fopen()'s own "failed to open stream" warning
            // names the absolute path, so it has to stay suppressed at the
            // production call site -- suppressing it here would let the leak
            // back in unnoticed.
            ASW_Stats::record( array( 'links' ) );
            $this->assertSame( 1, ASW_Stats::get_all()['total_blocked'], 'the write must still land unserialised' );

            $logged = (string) file_get_contents( $log );
            $this->assertStringContainsString( 'unable to open lock file', $logged );
            $this->assertStringContainsString( basename( $lock_file ), $logged );
            $this->assertStringNotContainsString( $lock_dir, $logged );
        } finally {
            ini_set( 'error_log', false === $previous ? '' : $previous );
            unlink( $log );
            rmdir( $lock_file );
        }
    }
}
