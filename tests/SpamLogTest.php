<?php
/**
 * Tests for ASW_Spam_Log.
 */

use PHPUnit\Framework\TestCase;

class SpamLogTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        global $wp_options, $wp_cache_deletes;
        $wp_options       = array();
        $wp_cache_deletes = array();

        $GLOBALS['wp_update_option_fails'] = false;
    }

    public function test_add_entry_stores_entry(): void {
        ASW_Spam_Log::add_entry( 'Test comment', array( 'links' ), 'abc123hash' );

        $entries = ASW_Spam_Log::get_entries();
        $this->assertCount( 1, $entries );
        $this->assertSame( 'Test comment', $entries[0]['text_excerpt'] );
        $this->assertSame( array( 'links' ), $entries[0]['checks_triggered'] );
        $this->assertSame( 'abc123hash', $entries[0]['ip_hash'] );
        $this->assertArrayHasKey( 'date', $entries[0] );
    }

    public function test_add_entry_truncates_excerpt_to_100_chars(): void {
        $long_text = str_repeat( 'a', 200 );
        ASW_Spam_Log::add_entry( $long_text, array( 'stop_words' ), 'hash' );

        $entries = ASW_Spam_Log::get_entries();
        $this->assertSame( 100, mb_strlen( $entries[0]['text_excerpt'] ) );
    }

    public function test_fifo_cap_at_100_entries(): void {
        for ( $i = 1; $i <= 110; $i++ ) {
            ASW_Spam_Log::add_entry( "Comment $i", array( 'links' ), 'hash' );
        }

        $entries = ASW_Spam_Log::get_entries();
        $this->assertCount( 100, $entries );
        // Oldest entries (1-10) should be dropped; first remaining is 11.
        $this->assertSame( 'Comment 11', $entries[0]['text_excerpt'] );
        $this->assertSame( 'Comment 110', $entries[99]['text_excerpt'] );
    }

    public function test_clear_removes_all_entries(): void {
        ASW_Spam_Log::add_entry( 'spam', array( 'emoji' ), 'hash' );
        ASW_Spam_Log::add_entry( 'more spam', array( 'links' ), 'hash2' );

        ASW_Spam_Log::clear();

        $entries = ASW_Spam_Log::get_entries();
        $this->assertCount( 0, $entries );
    }

    public function test_get_entries_invalidates_object_cache_before_reading(): void {
        global $wp_cache_deletes;
        $wp_cache_deletes = array();

        ASW_Spam_Log::get_entries();

        $deleted = array_filter(
            $wp_cache_deletes,
            static function ( $d ) {
                return ASW_Spam_Log::OPTION_KEY === $d['key'] && 'options' === $d['group'];
            }
        );
        $this->assertNotEmpty( $deleted, 'get_entries() must invalidate the object cache before reading.' );
    }

    public function test_clear_invalidates_object_cache_after_removing_option(): void {
        ASW_Spam_Log::add_entry( 'spam', array( 'emoji' ), 'hash' );

        global $wp_cache_deletes;
        $wp_cache_deletes = array();

        ASW_Spam_Log::clear();

        $deleted = array_filter(
            $wp_cache_deletes,
            static function ( $d ) {
                return ASW_Spam_Log::OPTION_KEY === $d['key'] && 'options' === $d['group'];
            }
        );
        $this->assertNotEmpty( $deleted, 'clear() must invalidate the object cache after deleting the option.' );
    }

    public function test_get_entries_with_limit(): void {
        for ( $i = 1; $i <= 10; $i++ ) {
            ASW_Spam_Log::add_entry( "Comment $i", array( 'links' ), 'hash' );
        }

        $entries = ASW_Spam_Log::get_entries( 3 );
        $this->assertCount( 3, $entries );
        // Should return the 3 most recent (last 3).
        $this->assertSame( 'Comment 8', $entries[0]['text_excerpt'] );
        $this->assertSame( 'Comment 10', $entries[2]['text_excerpt'] );
    }

    public function test_entry_format_has_required_keys(): void {
        ASW_Spam_Log::add_entry( 'test', array( 'links', 'emoji' ), 'somehash' );

        $entries = ASW_Spam_Log::get_entries();
        $entry   = $entries[0];

        $this->assertArrayHasKey( 'date', $entry );
        $this->assertArrayHasKey( 'text_excerpt', $entry );
        $this->assertArrayHasKey( 'checks_triggered', $entry );
        $this->assertArrayHasKey( 'ip_hash', $entry );
    }

    public function test_multiple_checks_triggered(): void {
        ASW_Spam_Log::add_entry( 'bad comment', array( 'links', 'emoji', 'stop_words' ), 'hash' );

        $entries = ASW_Spam_Log::get_entries();
        $this->assertCount( 3, $entries[0]['checks_triggered'] );
        $this->assertSame( array( 'links', 'emoji', 'stop_words' ), $entries[0]['checks_triggered'] );
    }

    public function test_get_entries_returns_empty_array_when_no_entries(): void {
        $entries = ASW_Spam_Log::get_entries();
        $this->assertIsArray( $entries );
        $this->assertCount( 0, $entries );
    }

    public function test_get_entries_filters_out_malformed_entries(): void {
        global $wp_options;

        // Inject entries directly: one valid, one missing 'ip_hash', one non-array.
        $wp_options[ ASW_Spam_Log::OPTION_KEY ] = array(
            array(
                'entry_id'         => 'asw_valid',
                'date'             => '2024-01-01 00:00:00',
                'text_excerpt'     => 'good entry',
                'checks_triggered' => array( 'links' ),
                'ip_hash'          => 'hash123',
            ),
            array(
                'entry_id'         => 'asw_missing_ip',
                'date'             => '2024-01-01 00:00:00',
                'text_excerpt'     => 'bad entry',
                'checks_triggered' => array( 'links' ),
                // 'ip_hash' is missing.
            ),
            'not_an_array',
            array(
                'entry_id'         => 'asw_missing_date',
                // 'date' is missing.
                'text_excerpt'     => 'another bad entry',
                'checks_triggered' => array(),
                'ip_hash'          => 'hash456',
            ),
        );

        $entries = ASW_Spam_Log::get_entries();
        $this->assertCount( 1, $entries );
        $this->assertSame( 'asw_valid', $entries[0]['entry_id'] );
    }

    public function test_get_entries_returns_empty_for_non_array_option(): void {
        global $wp_options;
        $wp_options[ ASW_Spam_Log::OPTION_KEY ] = 'corrupted_string';

        $entries = ASW_Spam_Log::get_entries();
        $this->assertIsArray( $entries );
        $this->assertCount( 0, $entries );
    }

    public function test_add_entry_invalidates_object_cache_before_read(): void {
        global $wp_cache_deletes;

        ASW_Spam_Log::add_entry( 'cache test', array( 'links' ), 'hash' );

        $spam_log_deletes = array_filter( $wp_cache_deletes, function ( $d ) {
            return $d['key'] === 'asw_spam_log' && $d['group'] === 'options';
        } );
        $this->assertNotEmpty( $spam_log_deletes, 'add_entry should call wp_cache_delete for spam log option' );
    }

    public function test_lock_files_use_uploads_directory(): void {
        $upload_dir = wp_upload_dir();
        $lock_dir   = $upload_dir['basedir'] . '/.asw-locks';

        ASW_Spam_Log::add_entry( 'lock test', array( 'links' ), 'hash' );

        $this->assertDirectoryExists( $lock_dir );
        $lock_file = $lock_dir . '/asw_spam_log_' . md5( ABSPATH ) . '.lock';
        $this->assertFileExists( $lock_file );
    }

    public function test_add_entry_succeeds_when_lock_file_fails(): void {
        $upload_dir = wp_upload_dir();
        $lock_dir   = $upload_dir['basedir'] . '/.asw-locks';

        // Create the lock dir and make it unwritable so fopen fails.
        wp_mkdir_p( $lock_dir );
        chmod( $lock_dir, 0000 );

        try {
            // Should still succeed (fail-open design).
            $entry_id = @ASW_Spam_Log::add_entry( 'lock fail test', array( 'links' ), 'hash' );
            $entries  = ASW_Spam_Log::get_entries();
            $this->assertCount( 1, $entries );
            $this->assertSame( 'lock fail test', $entries[0]['text_excerpt'] );
        } finally {
            chmod( $lock_dir, 0755 );
        }
    }

    public function test_update_entry_comment_id_invalidates_object_cache_before_read(): void {
        global $wp_cache_deletes;

        $entry_id = ASW_Spam_Log::add_entry( 'test', array( 'links' ), 'hash' );
        $wp_cache_deletes = array(); // Reset after add_entry.

        ASW_Spam_Log::update_entry_comment_id( $entry_id, 42 );

        $spam_log_deletes = array_filter( $wp_cache_deletes, function ( $d ) {
            return $d['key'] === 'asw_spam_log' && $d['group'] === 'options';
        } );
        $this->assertNotEmpty( $spam_log_deletes, 'update_entry_comment_id should call wp_cache_delete for spam log option' );
    }

    public function test_is_trained_returns_false_for_unknown_comment_id(): void {
        ASW_Spam_Log::add_entry( 'Some comment', array( 'links' ), 'hash' );

        // No entry carries comment_id 999 — the loop completes without a match
        // and falls through to the final return false.
        $this->assertFalse( ASW_Spam_Log::is_trained( 999 ) );
    }

    public function test_get_entries_by_comment_ids_selects_matching_set(): void {
        ASW_Spam_Log::add_entry( 'first', array( 'links' ), 'hash1', 10 );
        ASW_Spam_Log::add_entry( 'second', array( 'emoji' ), 'hash2', 20 );
        ASW_Spam_Log::add_entry( 'third', array( 'links' ), 'hash3', 30 );

        $matched = ASW_Spam_Log::get_entries_by_comment_ids( array( 10, 30 ) );

        $this->assertCount( 2, $matched );
        $excerpts = array_column( $matched, 'text_excerpt' );
        $this->assertContains( 'first', $excerpts );
        $this->assertContains( 'third', $excerpts );
        $this->assertNotContains( 'second', $excerpts );
    }

    public function test_get_entries_by_comment_ids_ignores_zero_comment_id(): void {
        ASW_Spam_Log::add_entry( 'unlinked', array( 'links' ), 'hash', 0 );
        ASW_Spam_Log::add_entry( 'linked', array( 'links' ), 'hash', 5 );

        // Asking for comment_id 0 must never match unlinked entries.
        $this->assertCount( 0, ASW_Spam_Log::get_entries_by_comment_ids( array( 0 ) ) );

        $matched = ASW_Spam_Log::get_entries_by_comment_ids( array( 5 ) );
        $this->assertCount( 1, $matched );
        $this->assertSame( 'linked', $matched[0]['text_excerpt'] );
    }

    public function test_get_entries_by_comment_ids_empty_set_returns_empty(): void {
        ASW_Spam_Log::add_entry( 'entry', array( 'links' ), 'hash', 7 );

        $this->assertSame( array(), ASW_Spam_Log::get_entries_by_comment_ids( array() ) );
    }

    public function test_delete_entries_by_comment_ids_removes_subset_preserving_rest(): void {
        ASW_Spam_Log::add_entry( 'keep1', array( 'links' ), 'hash', 1 );
        ASW_Spam_Log::add_entry( 'drop', array( 'emoji' ), 'hash', 2 );
        ASW_Spam_Log::add_entry( 'keep2', array( 'links' ), 'hash', 3 );

        $deleted = ASW_Spam_Log::delete_entries_by_comment_ids( array( 2 ) );
        $this->assertSame( 1, $deleted );

        $remaining = ASW_Spam_Log::get_entries();
        $this->assertCount( 2, $remaining );
        $excerpts = array_column( $remaining, 'text_excerpt' );
        // FIFO order preserved among the survivors.
        $this->assertSame( array( 'keep1', 'keep2' ), $excerpts );
    }

    public function test_delete_entries_by_comment_ids_repeated_returns_zero(): void {
        ASW_Spam_Log::add_entry( 'drop', array( 'links' ), 'hash', 9 );

        $this->assertSame( 1, ASW_Spam_Log::delete_entries_by_comment_ids( array( 9 ) ) );
        // Nothing left to delete on a second pass (idempotent).
        $this->assertSame( 0, ASW_Spam_Log::delete_entries_by_comment_ids( array( 9 ) ) );
    }

    public function test_delete_entries_by_comment_ids_empty_set_returns_zero(): void {
        ASW_Spam_Log::add_entry( 'entry', array( 'links' ), 'hash', 4 );

        $this->assertSame( 0, ASW_Spam_Log::delete_entries_by_comment_ids( array() ) );
        $this->assertCount( 1, ASW_Spam_Log::get_entries() );
    }

    public function test_delete_entries_by_comment_ids_ignores_zero(): void {
        ASW_Spam_Log::add_entry( 'unlinked', array( 'links' ), 'hash', 0 );

        // comment_id 0 entries are never matched, even when 0 is requested.
        $this->assertSame( 0, ASW_Spam_Log::delete_entries_by_comment_ids( array( 0 ) ) );
        $this->assertCount( 1, ASW_Spam_Log::get_entries() );
    }

    public function test_delete_entries_by_comment_ids_invalidates_cache_on_delete(): void {
        global $wp_cache_deletes;

        ASW_Spam_Log::add_entry( 'drop', array( 'links' ), 'hash', 11 );
        $wp_cache_deletes = array();

        ASW_Spam_Log::delete_entries_by_comment_ids( array( 11 ) );

        $spam_log_deletes = array_filter( $wp_cache_deletes, function ( $d ) {
            return $d['key'] === ASW_Spam_Log::OPTION_KEY && $d['group'] === 'options';
        } );
        $this->assertNotEmpty( $spam_log_deletes, 'delete_entries_by_comment_ids should invalidate the object cache.' );
    }

    public function test_delete_entries_by_comment_ids_no_match_does_not_write_option(): void {
        ASW_Spam_Log::add_entry( 'entry', array( 'links' ), 'hash', 8 );

        global $wp_options;
        // Capture the stored value, then request a comment_id that does not exist.
        $before  = $wp_options[ ASW_Spam_Log::OPTION_KEY ];
        $deleted = ASW_Spam_Log::delete_entries_by_comment_ids( array( 999 ) );

        $this->assertSame( 0, $deleted );
        $this->assertSame( $before, $wp_options[ ASW_Spam_Log::OPTION_KEY ] );
    }

    public function test_fifo_cap_still_holds_after_deletion(): void {
        // Fill to the cap with linked comment ids, delete one, then keep adding.
        for ( $i = 1; $i <= 100; $i++ ) {
            ASW_Spam_Log::add_entry( "Comment $i", array( 'links' ), 'hash', $i );
        }
        ASW_Spam_Log::delete_entries_by_comment_ids( array( 50 ) );
        $this->assertCount( 99, ASW_Spam_Log::get_entries() );

        for ( $i = 101; $i <= 110; $i++ ) {
            ASW_Spam_Log::add_entry( "Comment $i", array( 'links' ), 'hash', $i );
        }

        $entries = ASW_Spam_Log::get_entries();
        $this->assertCount( 100, $entries );
        // The cap is still honored; comment 50 stays gone.
        $ids = array_column( $entries, 'comment_id' );
        $this->assertNotContains( 50, $ids );
    }

    public function test_add_entry_form_source_round_trips(): void {
        $hash = hash( 'sha256', 'test-email' );
        ASW_Spam_Log::add_entry( 'form spam', array( 'links' ), 'iphash', 0, 'form', $hash );

        $entries = ASW_Spam_Log::get_entries();
        $this->assertCount( 1, $entries );
        $this->assertSame( 'form', $entries[0]['source'] );
        $this->assertSame( $hash, $entries[0]['email_hash'] );
        $this->assertSame( 0, $entries[0]['comment_id'] );
    }

    public function test_add_entry_defaults_to_comment_source(): void {
        ASW_Spam_Log::add_entry( 'comment spam', array( 'links' ), 'iphash' );

        $entries = ASW_Spam_Log::get_entries();
        $this->assertSame( 'comment', $entries[0]['source'] );
        $this->assertSame( '', $entries[0]['email_hash'] );
    }

    public function test_legacy_entries_without_source_still_load(): void {
        global $wp_options;
        $wp_options[ ASW_Spam_Log::OPTION_KEY ] = array(
            array(
                'entry_id'         => 'asw_legacy',
                'date'             => '2024-01-01 00:00:00',
                'text_excerpt'     => 'legacy entry',
                'checks_triggered' => array( 'links' ),
                'ip_hash'          => 'hash123',
                'comment_id'       => 5,
                // No source or email_hash keys.
            ),
        );

        $entries = ASW_Spam_Log::get_entries();
        $this->assertCount( 1, $entries );
        $this->assertSame( 'asw_legacy', $entries[0]['entry_id'] );
        // Missing source should be treated as comment.
        $source = $entries[0]['source'] ?? 'comment';
        $this->assertSame( 'comment', $source );
    }

    public function test_get_entries_by_email_hash_finds_only_form_entries(): void {
        $hash_form = hash( 'sha256', 'form@example.com' );
        ASW_Spam_Log::add_entry( 'form entry', array( 'links' ), 'hash', 0, 'form', $hash_form );
        ASW_Spam_Log::add_entry( 'comment entry', array( 'links' ), 'hash', 10, 'comment', '' );
        ASW_Spam_Log::add_entry( 'another form same hash', array( 'emoji' ), 'hash2', 0, 'form', $hash_form );
        $other_hash = hash( 'sha256', 'other@example.com' );
        ASW_Spam_Log::add_entry( 'other form', array( 'links' ), 'hash', 0, 'form', $other_hash );

        $matched = ASW_Spam_Log::get_entries_by_email_hash( $hash_form );
        $this->assertCount( 2, $matched );
        foreach ( $matched as $entry ) {
            $this->assertSame( $hash_form, $entry['email_hash'] );
        }
    }

    public function test_get_entries_by_email_hash_empty_hash_returns_empty(): void {
        $hash = hash( 'sha256', 'test@example.com' );
        ASW_Spam_Log::add_entry( 'form entry', array( 'links' ), 'hash', 0, 'form', $hash );

        $this->assertSame( array(), ASW_Spam_Log::get_entries_by_email_hash( '' ) );
        $this->assertSame( array(), ASW_Spam_Log::get_entries_by_email_hash( '   ' ) );
        // Whitespace-only email hashes to '' via hash_email, so no match.
        $whitespace_hash = ASW_Detector::hash_email( '   ' );
        $this->assertSame( '', $whitespace_hash );
        $this->assertSame( array(), ASW_Spam_Log::get_entries_by_email_hash( $whitespace_hash ) );
    }

    public function test_delete_entries_by_email_hash_removes_only_matching(): void {
        $hash_a = hash( 'sha256', 'a@example.com' );
        $hash_b = hash( 'sha256', 'b@example.com' );
        ASW_Spam_Log::add_entry( 'form a1', array( 'links' ), 'hash', 0, 'form', $hash_a );
        ASW_Spam_Log::add_entry( 'form a2', array( 'links' ), 'hash', 0, 'form', $hash_a );
        ASW_Spam_Log::add_entry( 'form b', array( 'links' ), 'hash', 0, 'form', $hash_b );
        ASW_Spam_Log::add_entry( 'comment', array( 'links' ), 'hash', 5, 'comment', '' );

        $deleted = ASW_Spam_Log::delete_entries_by_email_hash( $hash_a );
        $this->assertSame( 2, $deleted );

        $remaining = ASW_Spam_Log::get_entries();
        $this->assertCount( 2, $remaining );
        $excerpts = array_column( $remaining, 'text_excerpt' );
        $this->assertContains( 'form b', $excerpts );
        $this->assertContains( 'comment', $excerpts );
    }

    public function test_delete_entries_by_email_hash_empty_hash_returns_zero(): void {
        $hash = hash( 'sha256', 'test@example.com' );
        ASW_Spam_Log::add_entry( 'form', array( 'links' ), 'hash', 0, 'form', $hash );

        $this->assertSame( 0, ASW_Spam_Log::delete_entries_by_email_hash( '' ) );
        $this->assertCount( 1, ASW_Spam_Log::get_entries() );
    }

    public function test_delete_entries_by_email_hash_no_match_does_not_write_option(): void {
        $hash = hash( 'sha256', 'exists@example.com' );
        ASW_Spam_Log::add_entry( 'form', array( 'links' ), 'hash', 0, 'form', $hash );

        global $wp_options;
        $before  = $wp_options[ ASW_Spam_Log::OPTION_KEY ];
        $deleted = ASW_Spam_Log::delete_entries_by_email_hash( hash( 'sha256', 'missing@example.com' ) );

        $this->assertSame( 0, $deleted );
        $this->assertSame( $before, $wp_options[ ASW_Spam_Log::OPTION_KEY ] );
    }

    public function test_comment_keyed_lookup_unaffected_by_form_entries(): void {
        $hash = hash( 'sha256', 'form@example.com' );
        ASW_Spam_Log::add_entry( 'form', array( 'links' ), 'hash', 0, 'form', $hash );
        ASW_Spam_Log::add_entry( 'comment', array( 'links' ), 'hash', 42, 'comment', '' );

        $by_comment = ASW_Spam_Log::get_entries_by_comment_ids( array( 42 ) );
        $this->assertCount( 1, $by_comment );
        $this->assertSame( 'comment', $by_comment[0]['text_excerpt'] );

        // Form entry with comment_id 0 never matched by comment lookup.
        $this->assertCount( 0, ASW_Spam_Log::get_entries_by_comment_ids( array( 0 ) ) );

        // Email hash lookup never returns comment entries.
        $this->assertCount( 1, ASW_Spam_Log::get_entries_by_email_hash( $hash ) );
        $this->assertSame( 'form', ASW_Spam_Log::get_entries_by_email_hash( $hash )[0]['text_excerpt'] );
    }

    public function test_comment_keyed_delete_unaffected_by_form_entries(): void {
        $hash = hash( 'sha256', 'form@example.com' );
        ASW_Spam_Log::add_entry( 'form', array( 'links' ), 'hash', 0, 'form', $hash );
        ASW_Spam_Log::add_entry( 'comment', array( 'links' ), 'hash', 7, 'comment', '' );

        $deleted = ASW_Spam_Log::delete_entries_by_comment_ids( array( 7 ) );
        $this->assertSame( 1, $deleted );
        $remaining = ASW_Spam_Log::get_entries();
        $this->assertCount( 1, $remaining );
        $this->assertSame( 'form', $remaining[0]['text_excerpt'] );
    }

    public function test_fifo_cap_applies_across_mixed_sources(): void {
        $hash = hash( 'sha256', 'form@example.com' );
        for ( $i = 1; $i <= 100; $i++ ) {
            $source = ( 0 === $i % 2 ) ? 'form' : 'comment';
            $email  = ( 'form' === $source ) ? $hash : '';
            ASW_Spam_Log::add_entry( "Entry $i", array( 'links' ), 'hash', 0, $source, $email );
        }
        $this->assertCount( 100, ASW_Spam_Log::get_entries() );
        $this->assertSame( 'Entry 1', ASW_Spam_Log::get_entries()[0]['text_excerpt'] );

        ASW_Spam_Log::add_entry( 'Entry 101', array( 'links' ), 'hash', 0, 'form', $hash );
        $entries = ASW_Spam_Log::get_entries();
        $this->assertCount( 100, $entries );
        $this->assertSame( 'Entry 2', $entries[0]['text_excerpt'] );
        $this->assertSame( 'Entry 101', $entries[99]['text_excerpt'] );
    }

    public function test_delete_by_email_hash_invalidates_cache(): void {
        global $wp_cache_deletes;
        $hash = hash( 'sha256', 'cache@example.com' );
        ASW_Spam_Log::add_entry( 'form', array( 'links' ), 'hash', 0, 'form', $hash );
        $wp_cache_deletes = array();

        ASW_Spam_Log::delete_entries_by_email_hash( $hash );

        $deletes = array_filter( $wp_cache_deletes, function ( $d ) {
            return $d['key'] === ASW_Spam_Log::OPTION_KEY && $d['group'] === 'options';
        } );
        $this->assertNotEmpty( $deletes, 'delete_entries_by_email_hash should invalidate the object cache.' );
    }

    /**
     * A lock file that cannot be opened must be reported by name only.
     *
     * ASW_Spam_Log::add_entry() runs on an unauthenticated comment submission and
     * debug.log is web-readable on misconfigured hosts, so neither the plugin's
     * own diagnostic nor PHP's fopen() warning may carry the absolute path --
     * it names the document root. Same rule as ASW_Detector::run()'s
     * basename(), and the same technique as
     * DuplicateCheckTest::test_duplicate_check_still_counts_when_the_lock_file_cannot_be_opened():
     * the tests run as root, so chmod 0000 does not make fopen() fail, but a
     * *directory* at the lock file's path does, for every user.
     */
    public function test_add_entry_reports_an_unopenable_lock_file_without_its_path(): void {
        $upload_dir = wp_upload_dir();
        $lock_dir   = $upload_dir['basedir'] . '/.asw-locks';
        $lock_file  = $lock_dir . '/asw_spam_log_' . md5( ABSPATH ) . '.lock';

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
            ASW_Spam_Log::add_entry( 'lock fail spam log', array( 'links' ), 'iphash' );
            $this->assertCount( 1, ASW_Spam_Log::get_entries(), 'the write must still land unserialised' );

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

    public function test_claim_training_returns_ok_and_records_the_category(): void {
        ASW_Spam_Log::add_entry( 'untrained', array( 'links' ), 'hash', 7 );

        $this->assertSame( 'ok', ASW_Spam_Log::claim_training( 7, 'spam' ) );

        $entries = ASW_Spam_Log::get_entries();
        $this->assertSame( 'spam', $entries[0]['trained_as'] );
        $this->assertTrue( ASW_Spam_Log::is_trained( 7 ) );
    }

    public function test_claim_training_returns_already_trained_and_leaves_the_option_untouched(): void {
        ASW_Spam_Log::add_entry( 'trained', array( 'links' ), 'hash', 7 );
        ASW_Spam_Log::claim_training( 7, 'spam' );

        global $wp_options;
        $before = serialize( $wp_options[ ASW_Spam_Log::OPTION_KEY ] );

        $this->assertSame( 'already_trained', ASW_Spam_Log::claim_training( 7, 'spam' ) );

        $this->assertSame(
            $before,
            serialize( $wp_options[ ASW_Spam_Log::OPTION_KEY ] ),
            'a second claim of the same category must write nothing'
        );
    }

    public function test_claim_training_returns_flipped_and_rewrites_only_trained_as(): void {
        ASW_Spam_Log::add_entry( 'flip me', array( 'links' ), 'hash', 7 );
        ASW_Spam_Log::claim_training( 7, 'spam' );

        global $wp_options;
        $before = $wp_options[ ASW_Spam_Log::OPTION_KEY ][0];

        $this->assertSame( 'flipped', ASW_Spam_Log::claim_training( 7, 'ham' ) );

        $after = $wp_options[ ASW_Spam_Log::OPTION_KEY ][0];
        $this->assertSame( 'ham', $after['trained_as'] );

        // Nothing but trained_as may change on the entry.
        unset( $before['trained_as'], $after['trained_as'] );
        $this->assertSame( $before, $after );
    }

    public function test_claim_training_returns_not_found_for_an_unknown_comment(): void {
        ASW_Spam_Log::add_entry( 'some other comment', array( 'links' ), 'hash', 7 );

        $this->assertSame( 'not_found', ASW_Spam_Log::claim_training( 999, 'spam' ) );
        $this->assertFalse( ASW_Spam_Log::is_trained( 999 ) );
    }

    public function test_claim_training_reports_failed_when_the_option_write_fails(): void {
        global $wp_update_option_fails;

        ASW_Spam_Log::add_entry( 'unwritable', array( 'links' ), 'hash', 7 );

        // The value handed to update_option() always differs from the stored one
        // here, so a false return is a refused write rather than the no-change
        // case. Reporting 'ok' would hand the caller a claim that was never
        // persisted -- and a rollback value to restore onto an entry that never
        // changed. 'not_found' would be wrong too: it means "no entry exists",
        // which the caller answers by training unlocked, and doing that here
        // would move the sample table under a log entry that still names the
        // other category.
        $wp_update_option_fails = array( ASW_Spam_Log::OPTION_KEY );
        $result                 = ASW_Spam_Log::claim_training( 7, 'spam' );
        $wp_update_option_fails = false;

        $this->assertSame( 'failed', $result );
        $this->assertFalse( ASW_Spam_Log::is_trained( 7 ) );
    }

    public function test_claim_training_reports_failed_rather_than_not_found_on_a_refused_flip(): void {
        global $wp_update_option_fails;

        ASW_Spam_Log::add_entry( 'unwritable flip', array( 'links' ), 'hash', 7 );
        $this->assertSame( 'ok', ASW_Spam_Log::claim_training( 7, 'spam' ) );

        $wp_update_option_fails = array( ASW_Spam_Log::OPTION_KEY );
        $result                 = ASW_Spam_Log::claim_training( 7, 'ham' );
        $wp_update_option_fails = false;

        $this->assertSame( 'failed', $result );

        // The stored category is untouched, which is exactly why the caller must
        // not go on to flip the sample table: the log still says 'spam'.
        $entries = ASW_Spam_Log::get_entries();
        $this->assertSame( 'spam', $entries[0]['trained_as'] );
    }

    public function test_claim_training_invalidates_the_object_cache_when_it_writes(): void {
        ASW_Spam_Log::add_entry( 'cache', array( 'links' ), 'hash', 7 );

        global $wp_cache_deletes;
        $wp_cache_deletes = array();

        ASW_Spam_Log::claim_training( 7, 'spam' );

        $deleted = array_filter(
            $wp_cache_deletes,
            static function ( $d ) {
                return ASW_Spam_Log::OPTION_KEY === $d['key'] && 'options' === $d['group'];
            }
        );
        $this->assertNotEmpty( $deleted, 'claim_training() must invalidate the option cache.' );
    }

    public function test_restore_training_overwrites_an_existing_category(): void {
        ASW_Spam_Log::add_entry( 'restore me', array( 'links' ), 'hash', 7 );
        ASW_Spam_Log::claim_training( 7, 'spam' );
        $this->assertSame( 'flipped', ASW_Spam_Log::claim_training( 7, 'ham' ) );

        // Unlike mark_trained(), restore_training() does not refuse an overwrite.
        ASW_Spam_Log::restore_training( 7, 'spam' );

        $entries = ASW_Spam_Log::get_entries();
        $this->assertSame( 'spam', $entries[0]['trained_as'] );
    }

    public function test_restore_training_sets_the_category_on_an_untrained_entry(): void {
        ASW_Spam_Log::add_entry( 'untrained', array( 'links' ), 'hash', 7 );

        ASW_Spam_Log::restore_training( 7, 'ham' );

        $entries = ASW_Spam_Log::get_entries();
        $this->assertSame( 'ham', $entries[0]['trained_as'] );
    }

    public function test_restore_training_is_a_no_op_when_the_comment_has_no_entry(): void {
        ASW_Spam_Log::add_entry( 'other', array( 'links' ), 'hash', 7 );

        global $wp_options;
        $before = serialize( $wp_options[ ASW_Spam_Log::OPTION_KEY ] );

        ASW_Spam_Log::restore_training( 999, 'spam' );

        $this->assertSame( $before, serialize( $wp_options[ ASW_Spam_Log::OPTION_KEY ] ) );
    }

    public function test_restore_training_invalidates_the_object_cache(): void {
        ASW_Spam_Log::add_entry( 'cache', array( 'links' ), 'hash', 7 );

        global $wp_cache_deletes;
        $wp_cache_deletes = array();

        ASW_Spam_Log::restore_training( 7, 'spam' );

        $deleted = array_filter(
            $wp_cache_deletes,
            static function ( $d ) {
                return ASW_Spam_Log::OPTION_KEY === $d['key'] && 'options' === $d['group'];
            }
        );
        $this->assertNotEmpty( $deleted, 'restore_training() must invalidate the option cache.' );
    }
    public function test_restore_training_reports_the_write(): void {
        global $wp_update_option_fails;

        ASW_Spam_Log::add_entry( 'restore', array( 'links' ), 'hash', 7 );
        ASW_Spam_Log::claim_training( 7, 'spam' );

        $this->assertTrue( ASW_Spam_Log::restore_training( 7, 'ham' ) );

        // Already the asked-for value: nothing is written, and update_option()'s
        // no-change false must not be reported as a refused write.
        $this->assertTrue( ASW_Spam_Log::restore_training( 7, 'ham' ) );

        // No entry at all: nothing to write onto, so nothing failed either.
        $this->assertTrue( ASW_Spam_Log::restore_training( 999, 'ham' ) );

        // A refused write is reported, because it leaves the log naming a
        // category the sample table does not hold and blocks every later
        // transition with 'already_trained'.
        $wp_update_option_fails = array( ASW_Spam_Log::OPTION_KEY );
        $result                 = ASW_Spam_Log::restore_training( 7, 'spam' );
        $wp_update_option_fails = false;

        $this->assertFalse( $result );
    }

    public function test_unmark_trained_reports_the_write(): void {
        global $wp_update_option_fails;

        ASW_Spam_Log::add_entry( 'unmark', array( 'links' ), 'hash', 7 );
        ASW_Spam_Log::claim_training( 7, 'spam' );

        $wp_update_option_fails = array( ASW_Spam_Log::OPTION_KEY );
        $refused                = ASW_Spam_Log::unmark_trained( 7 );
        $wp_update_option_fails = false;

        $this->assertFalse( $refused );
        $this->assertTrue( ASW_Spam_Log::is_trained( 7 ) );

        $this->assertTrue( ASW_Spam_Log::unmark_trained( 7 ) );
        $this->assertFalse( ASW_Spam_Log::is_trained( 7 ) );

        // An entry that carries no mark, and a comment with no entry, are both
        // already in the asked-for state.
        $this->assertTrue( ASW_Spam_Log::unmark_trained( 7 ) );
        $this->assertTrue( ASW_Spam_Log::unmark_trained( 999 ) );
    }
}
