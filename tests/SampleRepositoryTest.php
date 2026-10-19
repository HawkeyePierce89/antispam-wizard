<?php
/**
 * Tests for ASW_Sample_Repository.
 */

use PHPUnit\Framework\TestCase;

class SampleRepositoryTest extends TestCase {

    private ASW_Sample_Repository $repo;

    protected function setUp(): void {
        parent::setUp();
        global $wpdb, $wp_options;
        $wpdb->reset_tables();
        $wp_options = array();
        $this->repo = new ASW_Sample_Repository( $wpdb );
    }

    protected function tearDown(): void {
        // reset_tables() also clears injected stub state
        // (insert_result/delete_result/insert_writes_on_forced_failure), so a
        // forced-failure test cannot leak that state into a later test under
        // reordering or --random-order.
        global $wpdb, $wp_options;
        $wpdb->reset_tables();
        $wp_options = array();
        parent::tearDown();
    }

    // --- insert() tests ---

    public function test_insert_stores_row_and_returns_inserted(): void {
        $result = $this->repo->insert( 'latin', 'spam', 'Buy cheap pills now', 101 );

        $this->assertSame( 'inserted', $result );

        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}asw_samples WHERE script = %s AND category = %s",
                'latin',
                'spam'
            )
        );
        $this->assertCount( 1, $rows );
        $this->assertSame( 'Buy cheap pills now', $rows[0]->content );
        $this->assertSame( hash( 'sha256', 'Buy cheap pills now' ), $rows[0]->content_hash );
    }

    public function test_insert_stores_the_comment_id_subject_key(): void {
        $this->assertSame( 'inserted', $this->repo->insert( 'latin', 'spam', 'Attributed sample', 4242 ) );

        global $wpdb;
        $rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}asw_samples" );

        $this->assertCount( 1, $rows );
        $this->assertSame( 4242, (int) $rows[0]->comment_id );
    }

    public function test_insert_rejects_a_zero_comment_id(): void {
        update_option( 'asw_samples_revision', 5 );

        $this->assertFalse( $this->repo->insert( 'latin', 'spam', 'Unattributed sample', 0 ) );

        global $wpdb;
        $this->assertCount( 0, $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}asw_samples" ) );
        $this->assertSame( 5, (int) get_option( 'asw_samples_revision' ) );
    }

    public function test_insert_rejects_a_negative_comment_id(): void {
        update_option( 'asw_samples_revision', 5 );

        $this->assertFalse( $this->repo->insert( 'latin', 'spam', 'Unattributed sample', -1 ) );

        global $wpdb;
        $this->assertCount( 0, $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}asw_samples" ) );
        $this->assertSame( 5, (int) get_option( 'asw_samples_revision' ) );
    }

    public function test_insert_bumps_revision_option(): void {
        update_option( 'asw_samples_revision', 5 );

        $this->repo->insert( 'latin', 'spam', 'Test sample', 102 );

        $this->assertSame( 6, (int) get_option( 'asw_samples_revision' ) );
    }

    public function test_insert_initializes_revision_when_missing(): void {
        $this->repo->insert( 'latin', 'ham', 'Good comment', 103 );

        $revision = get_option( 'asw_samples_revision' );
        $this->assertNotFalse( $revision );
        $this->assertGreaterThanOrEqual( 1, (int) $revision );
    }

    // --- insert() duplicate handling ---

    public function test_insert_duplicate_returns_exists(): void {
        $this->repo->insert( 'latin', 'spam', 'Duplicate text', 104 );
        $result = $this->repo->insert( 'latin', 'spam', 'Duplicate text', 105 );

        $this->assertSame( 'exists', $result );
    }

    public function test_insert_duplicate_does_not_bump_revision(): void {
        $this->repo->insert( 'latin', 'spam', 'Duplicate text', 106 );
        $rev_after_first = (int) get_option( 'asw_samples_revision' );
        $this->repo->insert( 'latin', 'spam', 'Duplicate text', 107 );
        $rev_after_second = (int) get_option( 'asw_samples_revision' );

        $this->assertSame( $rev_after_first, $rev_after_second );
    }

    public function test_insert_duplicate_keeps_the_first_comments_subject_key(): void {
        // The documented, accepted trade-off of deduplicating on the text rather
        // than on the subject: the surviving row stays attributed to the comment
        // that was trained first, so only that subject can export or erase it.
        // Pinned here because it is the one privacy edge case of the subject key.
        $this->repo->insert( 'latin', 'spam', 'Duplicate text', 110 );
        $this->assertSame( 'exists', $this->repo->insert( 'latin', 'spam', 'Duplicate text', 111 ) );

        $rows = $this->repo->find_by_comment_ids( array( 110, 111 ) );
        $this->assertCount( 1, $rows );
        $this->assertSame( 110, (int) $rows[0]['comment_id'] );
        $this->assertSame( array(), $this->repo->find_by_comment_ids( array( 111 ) ) );
    }

    public function test_insert_duplicate_does_not_create_second_row(): void {
        $this->repo->insert( 'latin', 'spam', 'Duplicate text', 108 );
        $this->repo->insert( 'latin', 'spam', 'Duplicate text', 109 );

        $this->assertSame( 1, $this->repo->count_by_script_category( 'latin', 'spam' ) );
    }

    public function test_insert_same_content_different_script_succeeds(): void {
        $this->repo->insert( 'latin', 'spam', 'Same text', 110 );
        $result = $this->repo->insert( 'cyrillic', 'spam', 'Same text', 111 );

        $this->assertSame( 'inserted', $result );
    }

    public function test_insert_same_content_different_category_succeeds(): void {
        $this->repo->insert( 'latin', 'spam', 'Same text', 112 );
        $result = $this->repo->insert( 'latin', 'ham', 'Same text', 113 );

        $this->assertSame( 'inserted', $result );
    }

    // --- insert() concurrency / DB-error handling ---

    public function test_insert_recovers_when_concurrent_request_wins_race(): void {
        global $wpdb;
        // Simulate a concurrent request inserting the same row between our
        // SELECT check and our INSERT: the insert "fails" (false) but the row
        // now exists, so the recheck finds it and we treat it as idempotent.
        $wpdb->insert_result                  = false;
        $wpdb->insert_writes_on_forced_failure = true;

        $result = $this->repo->insert( 'latin', 'spam', 'Raced sample', 114 );

        $this->assertSame( 'exists', $result );
    }

    public function test_insert_recovery_does_not_bump_revision(): void {
        global $wpdb;
        update_option( 'asw_samples_revision', 7 );
        $wpdb->insert_result                  = false;
        $wpdb->insert_writes_on_forced_failure = true;

        $result = $this->repo->insert( 'latin', 'spam', 'Raced sample', 115 );

        // Assert we took the recovery path (returns 'exists'), not the
        // genuine-DB-error path (returns false) — otherwise the revision
        // assertion below would pass for either outcome.
        $this->assertSame( 'exists', $result );
        // 'exists' recovery returns without bumping the revision.
        $this->assertSame( 7, (int) get_option( 'asw_samples_revision' ) );
    }

    public function test_insert_returns_false_on_db_error(): void {
        global $wpdb;
        // Insert fails and the row is NOT written (genuine DB error): the
        // recheck finds nothing, so insert() reports failure.
        $wpdb->insert_result                  = false;
        $wpdb->insert_writes_on_forced_failure = false;

        $result = $this->repo->insert( 'latin', 'spam', 'Doomed sample', 116 );

        $this->assertFalse( $result );
    }

    public function test_insert_db_error_does_not_bump_revision(): void {
        global $wpdb;
        update_option( 'asw_samples_revision', 3 );
        $wpdb->insert_result                  = false;
        $wpdb->insert_writes_on_forced_failure = false;

        $this->repo->insert( 'latin', 'spam', 'Doomed sample', 117 );

        $this->assertSame( 3, (int) get_option( 'asw_samples_revision' ) );
    }

    // --- delete_by_hash() tests ---

    public function test_delete_by_hash_removes_matching_row(): void {
        $this->repo->insert( 'latin', 'spam', 'Delete me', 118 );
        $hash = hash( 'sha256', 'Delete me' );

        $result = $this->repo->delete_by_hash( 'latin', 'spam', $hash );

        $this->assertTrue( $result );
        $rows = $this->repo->find_all( 'latin', 'spam' );
        $this->assertCount( 0, $rows );
    }

    public function test_delete_by_hash_bumps_revision(): void {
        update_option( 'asw_samples_revision', 10 );
        $this->repo->insert( 'latin', 'spam', 'To delete', 119 );
        $hash = hash( 'sha256', 'To delete' );

        // Revision was bumped by insert (to 11), now delete should bump to 12.
        $this->repo->delete_by_hash( 'latin', 'spam', $hash );

        $this->assertSame( 12, (int) get_option( 'asw_samples_revision' ) );
    }

    public function test_delete_by_hash_does_not_affect_other_rows(): void {
        $this->repo->insert( 'latin', 'spam', 'Keep me', 120 );
        $this->repo->insert( 'latin', 'spam', 'Delete me', 121 );
        $hash = hash( 'sha256', 'Delete me' );

        $this->repo->delete_by_hash( 'latin', 'spam', $hash );

        $rows = $this->repo->find_all( 'latin', 'spam' );
        $this->assertCount( 1, $rows );
        $this->assertSame( 'Keep me', $rows[0] );
    }

    public function test_delete_by_hash_returns_false_on_db_error(): void {
        global $wpdb;
        $this->repo->insert( 'latin', 'spam', 'To delete', 122 );
        $hash = hash( 'sha256', 'To delete' );

        // Simulate a DB error on the DELETE.
        $wpdb->delete_result = false;

        $result = $this->repo->delete_by_hash( 'latin', 'spam', $hash );

        $this->assertFalse( $result );
    }

    public function test_delete_by_hash_db_error_does_not_bump_revision(): void {
        global $wpdb;
        $this->repo->insert( 'latin', 'spam', 'To delete', 123 );
        $rev_before = (int) get_option( 'asw_samples_revision' );
        $hash       = hash( 'sha256', 'To delete' );

        $wpdb->delete_result = false;
        $this->repo->delete_by_hash( 'latin', 'spam', $hash );

        $this->assertSame( $rev_before, (int) get_option( 'asw_samples_revision' ) );
    }

    public function test_delete_by_hash_returns_true_when_no_rows_match(): void {
        // No matching row: delete returns 0 (not false) → success, no bump.
        update_option( 'asw_samples_revision', 4 );

        $result = $this->repo->delete_by_hash( 'latin', 'spam', hash( 'sha256', 'absent' ) );

        $this->assertTrue( $result );
        $this->assertSame( 4, (int) get_option( 'asw_samples_revision' ) );
    }

    // --- find_categories_by_hash() tests ---

    public function test_find_categories_by_hash_reports_both_categories_of_one_sample_identity(): void {
        $text = 'One body trained twice.';
        $this->repo->insert( 'latin', 'spam', $text, 160 );
        // A second comment carrying the identical body: the row it trains keeps
        // the first comment's ID, which is exactly why the lookup is by hash.
        $this->repo->insert( 'latin', 'ham', $text, 161 );

        $categories = $this->repo->find_categories_by_hash( 'latin', hash( 'sha256', $text ) );

        sort( $categories );
        $this->assertSame( array( 'ham', 'spam' ), $categories );
    }

    public function test_find_categories_by_hash_is_keyed_by_script_and_hash(): void {
        $this->repo->insert( 'latin', 'spam', 'Body one', 162 );
        $this->repo->insert( 'cyrillic', 'ham', 'Body one', 163 );

        $this->assertSame( array( 'spam' ), $this->repo->find_categories_by_hash( 'latin', hash( 'sha256', 'Body one' ) ) );
        $this->assertSame( array(), $this->repo->find_categories_by_hash( 'latin', hash( 'sha256', 'Body two' ) ) );
    }

    public function test_find_categories_by_hash_returns_empty_when_the_query_fails(): void {
        global $wpdb;

        // A refused SELECT must read as "nothing stored", the direction that
        // costs ASW_Moderation a redundant insert() rather than a skipped one.
        $this->repo->insert( 'latin', 'spam', 'Body one', 164 );
        $wpdb->get_results_result = false;

        $this->assertSame( array(), $this->repo->find_categories_by_hash( 'latin', hash( 'sha256', 'Body one' ) ) );
    }

    public function test_find_categories_by_hash_backtick_quotes_table_name(): void {
        global $wpdb;
        $wpdb->prepared_queries = array();
        $this->repo->find_categories_by_hash( 'latin', hash( 'sha256', 'Body one' ) );

        $this->assertSame(
            array( "SELECT category FROM `wp_asw_samples` WHERE script = 'latin' AND content_hash = '" . hash( 'sha256', 'Body one' ) . "'" ),
            $wpdb->prepared_queries
        );
    }

    // --- find_all() tests ---

    public function test_find_all_returns_content_strings(): void {
        $this->repo->insert( 'latin', 'spam', 'Spam one', 124 );
        $this->repo->insert( 'latin', 'spam', 'Spam two', 125 );

        $results = $this->repo->find_all( 'latin', 'spam' );

        $this->assertCount( 2, $results );
        $this->assertContains( 'Spam one', $results );
        $this->assertContains( 'Spam two', $results );
    }

    public function test_find_all_filters_by_script_and_category(): void {
        $this->repo->insert( 'latin', 'spam', 'Latin spam', 126 );
        $this->repo->insert( 'latin', 'ham', 'Latin ham', 127 );
        $this->repo->insert( 'cyrillic', 'spam', 'Cyrillic spam', 128 );

        $results = $this->repo->find_all( 'latin', 'spam' );

        $this->assertCount( 1, $results );
        $this->assertSame( 'Latin spam', $results[0] );
    }

    public function test_find_all_returns_empty_when_no_matches(): void {
        $results = $this->repo->find_all( 'latin', 'spam' );
        $this->assertSame( array(), $results );
    }

    // --- count_by_script_category() tests ---

    public function test_count_by_script_category_returns_correct_count(): void {
        $this->repo->insert( 'latin', 'spam', 'One', 129 );
        $this->repo->insert( 'latin', 'spam', 'Two', 130 );
        $this->repo->insert( 'latin', 'ham', 'Three', 131 );

        $this->assertSame( 2, $this->repo->count_by_script_category( 'latin', 'spam' ) );
        $this->assertSame( 1, $this->repo->count_by_script_category( 'latin', 'ham' ) );
    }

    public function test_count_by_script_category_returns_zero_when_empty(): void {
        $this->assertSame( 0, $this->repo->count_by_script_category( 'latin', 'spam' ) );
    }

    // --- find_by_comment_ids() tests ---

    public function test_find_by_comment_ids_returns_only_the_requested_subjects_rows(): void {
        $this->repo->insert( 'latin', 'spam', 'Subject one spam', 501 );
        $this->repo->insert( 'latin', 'ham', 'Subject one ham', 501 );
        $this->repo->insert( 'cyrillic', 'spam', 'Someone else', 502 );

        $rows = $this->repo->find_by_comment_ids( array( 501 ) );

        $this->assertCount( 2, $rows );
        $contents = array_column( $rows, 'content' );
        $this->assertContains( 'Subject one spam', $contents );
        $this->assertContains( 'Subject one ham', $contents );
        $this->assertNotContains( 'Someone else', $contents );
    }

    public function test_find_by_comment_ids_returns_rows_keyed_by_column(): void {
        $this->repo->insert( 'latin', 'spam', 'Exported text', 503 );

        $rows = $this->repo->find_by_comment_ids( array( 503 ) );

        $this->assertCount( 1, $rows );
        $this->assertIsArray( $rows[0] );
        foreach ( array( 'id', 'comment_id', 'script', 'category', 'content', 'created_at' ) as $column ) {
            $this->assertArrayHasKey( $column, $rows[0], "Row should carry the {$column} column." );
        }
        $this->assertSame( 503, (int) $rows[0]['comment_id'] );
        $this->assertSame( 'Exported text', $rows[0]['content'] );
        $this->assertSame( 'spam', $rows[0]['category'] );
        $this->assertSame( 'latin', $rows[0]['script'] );
    }

    public function test_find_by_comment_ids_selects_the_email_hash_column(): void {
        global $wpdb;
        $wpdb->prepared_queries = array();

        $this->repo->insert( 'latin', 'spam', 'Hashed text', 520, 'hash-520' );

        $rows = $this->repo->find_by_comment_ids( array( 520 ) );

        $this->assertCount( 1, $rows );
        $this->assertSame( 'hash-520', $rows[0]['email_hash'] );
        // The $wpdb stub hands back whole rows whatever the SELECT list names, so
        // the returned key alone would pass even if the column were dropped from
        // the query -- against a real database ASW_Privacy would then see no hash
        // on any row and export hash-covered samples twice. Assert the column is
        // actually selected.
        $this->assertNotEmpty( $wpdb->prepared_queries );
        $this->assertStringContainsString( 'email_hash', end( $wpdb->prepared_queries ) );
    }

    public function test_find_by_comment_ids_matches_several_subjects_at_once(): void {
        $this->repo->insert( 'latin', 'spam', 'From 504', 504 );
        $this->repo->insert( 'latin', 'spam', 'From 505', 505 );
        $this->repo->insert( 'latin', 'spam', 'From 506', 506 );

        $rows = $this->repo->find_by_comment_ids( array( 504, 506 ) );

        $this->assertCount( 2, $rows );
        $this->assertSame( array( 'From 504', 'From 506' ), array_column( $rows, 'content' ) );
    }

    public function test_find_by_comment_ids_returns_empty_for_an_empty_list(): void {
        $this->repo->insert( 'latin', 'spam', 'Present', 507 );

        $this->assertSame( array(), $this->repo->find_by_comment_ids( array() ) );
    }

    public function test_find_by_comment_ids_returns_empty_for_a_zero_only_list(): void {
        $this->repo->insert( 'latin', 'spam', 'Present', 508 );

        global $wpdb;
        $wpdb->prepared_queries = array();

        // Zero and negative IDs are dropped; with nothing left the method must
        // bail before querying rather than emit an unfiltered SELECT.
        $this->assertSame( array(), $this->repo->find_by_comment_ids( array( 0, -1, '0' ) ) );
        $this->assertSame( array(), $wpdb->prepared_queries );
    }

    public function test_find_by_comment_ids_ignores_unusable_ids_among_valid_ones(): void {
        $this->repo->insert( 'latin', 'spam', 'Valid subject', 509 );

        $rows = $this->repo->find_by_comment_ids( array( 0, 509, -7 ) );

        $this->assertCount( 1, $rows );
        $this->assertSame( 'Valid subject', $rows[0]['content'] );
    }

    public function test_find_by_comment_ids_returns_empty_when_nothing_matches(): void {
        $this->repo->insert( 'latin', 'spam', 'Present', 510 );

        $this->assertSame( array(), $this->repo->find_by_comment_ids( array( 999 ) ) );
    }

    public function test_find_by_comment_ids_backtick_quotes_table_name(): void {
        global $wpdb;
        $wpdb->prepared_queries = array();

        $this->repo->find_by_comment_ids( array( 511 ) );

        $this->assertNotEmpty( $wpdb->prepared_queries );
        foreach ( $wpdb->prepared_queries as $q ) {
            $this->assertStringContainsString( '`wp_asw_samples`', $q );
            $this->assertStringNotContainsString( "'wp_asw_samples'", $q );
        }
    }

    public function test_find_by_comment_ids_binds_every_id_in_order(): void {
        global $wpdb;
        $wpdb->prepared_queries = array();

        $this->repo->find_by_comment_ids( array( 512, 513 ) );

        $this->assertSame(
            array( 'SELECT id, comment_id, email_hash, script, category, content, created_at FROM `wp_asw_samples` WHERE comment_id IN ( 512, 513 ) ORDER BY id ASC' ),
            $wpdb->prepared_queries
        );
    }

    public function test_find_by_comment_ids_returns_empty_when_the_select_fails(): void {
        $this->repo->insert( 'latin', 'spam', 'Present', 514 );

        global $wpdb;
        // A refused SELECT must hand back an empty array rather than anything
        // the exporter could not foreach over.
        $wpdb->get_results_result = false;

        $this->assertSame( array(), $this->repo->find_by_comment_ids( array( 514 ) ) );
    }

    // --- delete_by_comment_ids() tests ---

    public function test_delete_by_comment_ids_removes_exactly_the_matching_rows(): void {
        $this->repo->insert( 'latin', 'spam', 'Erase me', 601 );
        $this->repo->insert( 'latin', 'ham', 'Erase me too', 601 );
        $this->repo->insert( 'latin', 'spam', 'Keep me', 602 );

        $deleted = $this->repo->delete_by_comment_ids( array( 601 ) );

        $this->assertSame( 2, $deleted );
        $this->assertSame( array(), $this->repo->find_by_comment_ids( array( 601 ) ) );

        $remaining = $this->repo->find_by_comment_ids( array( 602 ) );
        $this->assertCount( 1, $remaining );
        $this->assertSame( 'Keep me', $remaining[0]['content'] );
    }

    public function test_delete_by_comment_ids_returns_zero_when_nothing_matches(): void {
        $this->repo->insert( 'latin', 'spam', 'Untouched', 603 );

        $this->assertSame( 0, $this->repo->delete_by_comment_ids( array( 999 ) ) );
        $this->assertCount( 1, $this->repo->find_by_comment_ids( array( 603 ) ) );
    }

    public function test_delete_by_comment_ids_returns_zero_for_an_empty_or_zero_only_list(): void {
        $this->repo->insert( 'latin', 'spam', 'Untouched', 604 );

        global $wpdb;
        $wpdb->prepared_queries = array();

        $this->assertSame( 0, $this->repo->delete_by_comment_ids( array() ) );
        $this->assertSame( 0, $this->repo->delete_by_comment_ids( array( 0, -3 ) ) );
        // No usable subject key means no query at all -- an unfiltered DELETE
        // here would wipe the whole table.
        $this->assertSame( array(), $wpdb->prepared_queries );
        $this->assertCount( 1, $this->repo->find_by_comment_ids( array( 604 ) ) );
    }

    public function test_delete_by_comment_ids_bumps_the_revision_when_rows_were_removed(): void {
        $this->repo->insert( 'latin', 'spam', 'Erase me', 605 );
        update_option( 'asw_samples_revision', 7 );

        $this->assertSame( 1, $this->repo->delete_by_comment_ids( array( 605 ) ) );

        // The cached classifier must be rebuilt without the erased text.
        $this->assertSame( 8, (int) get_option( 'asw_samples_revision' ) );
    }

    public function test_delete_by_comment_ids_leaves_the_revision_untouched_when_nothing_matched(): void {
        $this->repo->insert( 'latin', 'spam', 'Untouched', 606 );
        update_option( 'asw_samples_revision', 7 );

        $this->assertSame( 0, $this->repo->delete_by_comment_ids( array( 999 ) ) );

        $this->assertSame( 7, (int) get_option( 'asw_samples_revision' ) );
    }

    public function test_delete_by_comment_ids_returns_false_and_keeps_rows_when_the_query_fails(): void {
        $this->repo->insert( 'latin', 'spam', 'Erase me', 610 );
        update_option( 'asw_samples_revision', 7 );

        global $wpdb;
        // wpdb::query() returns false on a DB error.
        $wpdb->query_result = false;

        // false, never 0: the Privacy API eraser distinguishes "nothing matched"
        // from "the DELETE failed", and reporting the latter as the former would
        // close the erasure request while the full comment text is still stored.
        $this->assertFalse( $this->repo->delete_by_comment_ids( array( 610 ) ) );

        // A failed DELETE must not bump the revision: nothing left the table, so
        // rebuilding the cached classifier would be pure waste on every failure.
        $wpdb->query_result = null;
        $this->assertSame( 7, (int) get_option( 'asw_samples_revision' ) );
        $this->assertCount( 1, $this->repo->find_by_comment_ids( array( 610 ) ) );
    }

    public function test_delete_by_comment_ids_backtick_quotes_table_name(): void {
        global $wpdb;
        $wpdb->prepared_queries = array();

        $this->repo->delete_by_comment_ids( array( 607 ) );

        $this->assertNotEmpty( $wpdb->prepared_queries );
        $this->assertStringContainsString( '`wp_asw_samples`', $wpdb->prepared_queries[0] );
        $this->assertStringNotContainsString( "'wp_asw_samples'", $wpdb->prepared_queries[0] );
    }

    public function test_delete_by_comment_ids_binds_every_id_in_order(): void {
        global $wpdb;
        $wpdb->prepared_queries = array();

        $this->repo->delete_by_comment_ids( array( 608, 609 ) );

        $this->assertSame(
            array( 'DELETE FROM `wp_asw_samples` WHERE comment_id IN ( 608, 609 )' ),
            $wpdb->prepared_queries
        );
    }

    // --- latest_revision() tests ---

    public function test_latest_revision_returns_current_value(): void {
        update_option( 'asw_samples_revision', 42 );

        $this->assertSame( 42, $this->repo->latest_revision() );
    }

    public function test_latest_revision_returns_zero_when_unset(): void {
        $this->assertSame( 0, $this->repo->latest_revision() );
    }

    // --- get_table_name() tests ---

    public function test_get_table_name_returns_prefixed_name(): void {
        $this->assertSame( 'wp_asw_samples', $this->repo->get_table_name() );
    }

    // --- get_create_table_sql() tests ---

    public function test_get_create_table_sql_returns_valid_ddl(): void {
        $sql = $this->repo->get_create_table_sql();

        $this->assertStringContainsString( 'CREATE TABLE', $sql );
        $this->assertStringContainsString( 'wp_asw_samples', $sql );
        $this->assertStringContainsString( 'content_hash', $sql );
        $this->assertStringContainsString( 'script', $sql );
        $this->assertStringContainsString( 'category', $sql );
        $this->assertStringContainsString( 'content', $sql );
        $this->assertStringContainsString( 'UNIQUE KEY idx_unique_sample (script, category, content_hash)', $sql );
        $this->assertStringContainsString( 'DEFAULT CHARACTER SET', $sql );
    }

    public function test_get_create_table_sql_carries_the_comment_id_subject_key(): void {
        $sql = $this->repo->get_create_table_sql();

        $this->assertStringContainsString( 'comment_id BIGINT UNSIGNED NOT NULL', $sql );
        $this->assertStringContainsString( 'KEY idx_comment_id (comment_id)', $sql );
    }

    public function test_get_create_table_sql_carries_the_email_hash_subject_key(): void {
        $sql = $this->repo->get_create_table_sql();

        // DEFAULT '' so an ALTER on an existing table cannot fail, and so a
        // comment with no author email stores an empty hash rather than NULL.
        $this->assertStringContainsString( "email_hash CHAR(64) NOT NULL DEFAULT ''", $sql );
        $this->assertStringContainsString( 'KEY idx_email_hash (email_hash)', $sql );
    }

    public function test_schema_probes_run_with_wpdb_errors_suppressed(): void {
        // SHOW COLUMNS / SHOW INDEX against a table that does not exist is a hard
        // MySQL error, and wpdb::print_error() writes it to error_log() whether
        // or not show_errors is on -- suppress_errors is the only gate. activate()
        // probes straight after dbDelta(), so on a host that cannot CREATE the
        // table an unbracketed probe would append a plugin-attributable line to
        // the debug.log dev/smoke.sh scans on every capable admin_init.
        global $wpdb;

        $repository = new ASW_Sample_Repository( $wpdb );

        $observed = null;
        $wpdb->on_get_var = static function () use ( $wpdb, &$observed ) {
            $observed = $wpdb->suppress_errors;
        };

        try {
            $repository->schema_is_current();
        } finally {
            $wpdb->on_get_var = null;
        }

        $this->assertTrue( $observed, 'The schema probes must run with wpdb errors suppressed.' );
        $this->assertFalse( $wpdb->suppress_errors, 'schema_is_current() must restore the previous suppress_errors value.' );
    }

    public function test_versioned_schema_probes_cover_every_key_the_ddl_adds(): void {
        $sql = $this->repo->get_create_table_sql();

        // schema_is_current() is what decides whether DB_VERSION may be stamped,
        // so anything the DDL adds must be probed or a half-applied migration is
        // recorded as complete and never retried.
        foreach ( ASW_Sample_Repository::VERSIONED_COLUMNS as $column ) {
            $this->assertStringContainsString( $column . ' ', $sql );
        }
        foreach ( ASW_Sample_Repository::VERSIONED_INDEXES as $index ) {
            $this->assertStringContainsString( 'KEY ' . $index . ' (', $sql );
        }
        // Every named index in the DDL must be probed, except idx_unique_sample,
        // the dedup key of the original CREATE, which exists on every table the
        // plugin ever created. A future DDL that adds an index without extending
        // VERSIONED_INDEXES turns this red.
        preg_match_all( '/\bKEY\s+(idx_\w+)\s*\(/', $sql, $m );
        $this->assertSame(
            array( 'idx_unique_sample' ),
            array_values( array_diff( $m[1], ASW_Sample_Repository::VERSIONED_INDEXES ) )
        );

        // The same guard for the columns. An unprobed column is the identical
        // failure: dbDelta() fires its ADD COLUMN without checking the result, so
        // a refused statement is recorded as a complete migration and the version
        // gate short-circuits every later attempt. A future DDL that adds a column
        // without extending VERSIONED_COLUMNS turns this red.
        preg_match_all( '/^\s{12}(\w+)\s+(?:BIGINT|CHAR|VARCHAR|TEXT|DATETIME|INT)/mi', $sql, $c );
        $this->assertSame(
            // Columns of the original CREATE, which exist on every table the
            // plugin ever created and need no probe.
            array( 'id', 'script', 'category', 'content', 'content_hash', 'created_at' ),
            array_values( array_diff( $c[1], ASW_Sample_Repository::VERSIONED_COLUMNS ) )
        );
    }

    // --- SQL identifier hygiene tests ---

    public function test_create_table_sql_backtick_quotes_table_name(): void {
        $sql = $this->repo->get_create_table_sql();

        // No backticks on table name — dbDelta() requires unquoted name.
        $this->assertStringContainsString( 'CREATE TABLE wp_asw_samples', $sql );
        $this->assertStringNotContainsString( 'CREATE TABLE `', $sql );
    }

    public function test_insert_select_backtick_quotes_table_name(): void {
        global $wpdb;
        $this->repo->insert( 'latin', 'spam', 'Test backtick query', 132 );

        // The duplicate-check SELECT in insert() goes through prepare().
        $found = false;
        foreach ( $wpdb->prepared_queries as $q ) {
            if ( stripos( $q, 'SELECT' ) === 0 && strpos( $q, '`wp_asw_samples`' ) !== false ) {
                $found = true;
                break;
            }
        }
        $this->assertTrue( $found, 'insert() SELECT queries should backtick-quote the table name' );
    }

    public function test_find_all_backtick_quotes_table_name(): void {
        global $wpdb;
        $wpdb->prepared_queries = array();
        $this->repo->find_all( 'latin', 'spam' );

        $found = false;
        foreach ( $wpdb->prepared_queries as $q ) {
            if ( strpos( $q, '`wp_asw_samples`' ) !== false ) {
                $found = true;
                break;
            }
        }
        $this->assertTrue( $found, 'find_all() should backtick-quote the table name' );
    }

    public function test_count_query_backtick_quotes_table_name(): void {
        global $wpdb;
        $wpdb->prepared_queries = array();
        $this->repo->count_by_script_category( 'latin', 'spam' );

        $found = false;
        foreach ( $wpdb->prepared_queries as $q ) {
            if ( strpos( $q, '`wp_asw_samples`' ) !== false ) {
                $found = true;
                break;
            }
        }
        $this->assertTrue( $found, 'count_by_script_category() should backtick-quote the table name' );
    }

    public function test_bump_revision_backtick_quotes_options_table(): void {
        global $wpdb;
        $this->repo->insert( 'latin', 'spam', 'Trigger revision bump', 133 );

        $found = false;
        foreach ( $wpdb->prepared_queries as $q ) {
            if ( stripos( $q, 'UPDATE' ) !== false && strpos( $q, '`wp_options`' ) !== false ) {
                $found = true;
                break;
            }
        }
        $this->assertTrue( $found, 'bump_revision() should backtick-quote the options table name' );
    }

    // --- Charset/collate validation tests ---

    public function test_create_table_sql_rejects_invalid_charset(): void {
        global $wpdb;
        $wpdb->charset = 'evil; DROP TABLE users';
        $repo = new ASW_Sample_Repository( $wpdb );

        $sql = $repo->get_create_table_sql();

        $this->assertStringContainsString( 'CHARACTER SET utf8mb4', $sql );
        $this->assertStringNotContainsString( 'evil', $sql );
    }

    public function test_create_table_sql_rejects_invalid_collate(): void {
        global $wpdb;
        $wpdb->collate = 'evil; DROP TABLE users';
        $repo = new ASW_Sample_Repository( $wpdb );

        $sql = $repo->get_create_table_sql();

        // Invalid collate is dropped — no COLLATE clause emitted.
        $this->assertStringNotContainsString( 'COLLATE', $sql );
        $this->assertStringNotContainsString( 'evil', $sql );
    }

    public function test_create_table_sql_forces_matching_collate_when_charset_invalid(): void {
        global $wpdb;
        $wpdb->charset = 'evil';
        $wpdb->collate = 'latin1_swedish_ci';
        $repo = new ASW_Sample_Repository( $wpdb );

        $sql = $repo->get_create_table_sql();

        // Both charset and collate should fall back to utf8mb4 defaults.
        $this->assertStringContainsString( 'CHARACTER SET utf8mb4', $sql );
        $this->assertStringContainsString( 'COLLATE utf8mb4_unicode_ci', $sql );
    }

    public function test_create_table_sql_resets_mismatched_charset_collate(): void {
        global $wpdb;
        $wpdb->charset = 'utf8mb4';
        $wpdb->collate = 'latin1_swedish_ci';
        $repo = new ASW_Sample_Repository( $wpdb );

        $sql = $repo->get_create_table_sql();

        // Collation belongs to latin1, not utf8mb4 — collate is dropped, charset preserved.
        $this->assertStringContainsString( 'CHARACTER SET utf8mb4', $sql );
        $this->assertStringNotContainsString( 'COLLATE', $sql );
        $this->assertStringNotContainsString( 'latin1', $sql );
    }

    public function test_create_table_sql_empty_collate_preserves_charset(): void {
        global $wpdb;
        $wpdb->charset = 'latin1';
        $wpdb->collate = '';
        $repo = new ASW_Sample_Repository( $wpdb );

        $sql = $repo->get_create_table_sql();

        // Empty collate should NOT coerce charset to utf8mb4 — charset is preserved,
        // COLLATE clause is omitted so MySQL uses its default for the charset.
        $this->assertStringContainsString( 'CHARACTER SET latin1', $sql );
        $this->assertStringNotContainsString( 'COLLATE', $sql );
    }

    public function test_create_table_sql_accepts_valid_charset(): void {
        global $wpdb;
        $wpdb->charset = 'latin1';
        $wpdb->collate = 'latin1_swedish_ci';
        $repo = new ASW_Sample_Repository( $wpdb );

        $sql = $repo->get_create_table_sql();

        $this->assertStringContainsString( 'CHARACTER SET latin1', $sql );
        $this->assertStringContainsString( 'COLLATE latin1_swedish_ci', $sql );
    }

    // --- %i identifier placeholder tests ---

    public function test_prepare_renders_identifier_placeholder_backtick_quoted(): void {
        global $wpdb;

        $this->assertSame(
            "SELECT * FROM `wp_asw_samples` WHERE script = 'latin'",
            $wpdb->prepare( 'SELECT * FROM %i WHERE script = %s', 'wp_asw_samples', 'latin' )
        );
    }

    public function test_prepare_doubles_embedded_backticks_in_identifier(): void {
        global $wpdb;

        // Real WordPress escapes an embedded backtick by doubling it; the stub
        // must model that so tests exercise the same rendering.
        $this->assertSame(
            'SELECT * FROM `we``ird`',
            $wpdb->prepare( 'SELECT * FROM %i', 'we`ird' )
        );
    }

    public function test_insert_duplicate_check_binds_values_in_order(): void {
        global $wpdb;
        $wpdb->prepared_queries = array();
        $this->repo->insert( 'latin', 'spam', 'Ordering probe', 134 );

        $hash = hash( 'sha256', 'Ordering probe' );
        $this->assertContains(
            "SELECT COUNT(*) FROM `wp_asw_samples` WHERE content_hash = '{$hash}' AND script = 'latin' AND category = 'spam'",
            $wpdb->prepared_queries
        );
    }

    public function test_insert_recheck_query_binds_values_in_order(): void {
        global $wpdb;
        // Model a concurrent winner: insert() fails but the row now exists, so
        // the post-insert recheck SELECT runs.
        $wpdb->insert_result                   = false;
        $wpdb->insert_writes_on_forced_failure = true;
        $wpdb->prepared_queries                = array();

        $this->assertSame( 'exists', $this->repo->insert( 'cyrillic', 'ham', 'Recheck probe', 135 ) );

        $hash = hash( 'sha256', 'Recheck probe' );
        $expected = "SELECT COUNT(*) FROM `wp_asw_samples` WHERE content_hash = '{$hash}' AND script = 'cyrillic' AND category = 'ham'";
        // Both the pre-insert duplicate check and the recheck use this SQL.
        $this->assertSame( 2, count( array_keys( $wpdb->prepared_queries, $expected, true ) ) );
    }

    public function test_find_all_binds_values_in_order(): void {
        global $wpdb;
        $wpdb->prepared_queries = array();
        $this->repo->find_all( 'cyrillic', 'ham' );

        $this->assertSame(
            array( "SELECT content FROM `wp_asw_samples` WHERE script = 'cyrillic' AND category = 'ham' ORDER BY id ASC" ),
            $wpdb->prepared_queries
        );
    }

    public function test_find_all_returns_samples_in_ascending_id_order(): void {
        global $wpdb;
        // Seeded rather than inserted: insert() only ever appends with an
        // ascending id, so a table built through it would come back in id
        // order whether the query asked for it or not.
        $wpdb->seed_table_rows(
            'wp_asw_samples',
            array(
                array( 'id' => 9, 'script' => 'cyrillic', 'category' => 'spam', 'content' => 'newest' ),
                array( 'id' => 2, 'script' => 'cyrillic', 'category' => 'spam', 'content' => 'oldest' ),
                array( 'id' => 7, 'script' => 'cyrillic', 'category' => 'spam', 'content' => 'middle' ),
                array( 'id' => 4, 'script' => 'latin', 'category' => 'spam', 'content' => 'other script' ),
            )
        );

        $this->assertSame(
            array( 'oldest', 'middle', 'newest' ),
            $this->repo->find_all( 'cyrillic', 'spam' )
        );
    }

    public function test_count_by_script_category_binds_values_in_order(): void {
        global $wpdb;
        $wpdb->prepared_queries = array();
        $this->repo->count_by_script_category( 'cjk', 'spam' );

        $this->assertSame(
            array( "SELECT COUNT(*) FROM `wp_asw_samples` WHERE script = 'cjk' AND category = 'spam'" ),
            $wpdb->prepared_queries
        );
    }

    public function test_bump_revision_update_targets_options_table(): void {
        global $wpdb;
        $wpdb->prepared_queries = array();
        $this->repo->insert( 'latin', 'spam', 'Revision probe', 136 );

        $this->assertContains(
            "UPDATE `wp_options` SET option_value = option_value + 1 WHERE option_name = 'asw_samples_revision'",
            $wpdb->prepared_queries
        );
    }

    public function test_source_uses_identifier_placeholders_not_interpolation(): void {
        $source = file_get_contents( dirname( __DIR__ ) . '/antispam-wizard/includes/class-sample-repository.php' );

        // Fifteen prepared queries name their table with a %i identifier placeholder.
        // Matching the SQL keyword that precedes it, rather than counting a bare
        // '%i', keeps the assertion anchored to the query strings: a docblock that
        // merely mentions %i must not satisfy it.
        $this->assertSame(
            15,
            preg_match_all( '/\\b(?:FROM|UPDATE|INTO|JOIN)\\s+%i\\b/', $source ),
            'Every prepared query should use a %i identifier placeholder.'
        );
        // No table name is interpolated into a prepared query any more.
        $this->assertStringNotContainsString( '`{$table}`', $source );
        $this->assertStringNotContainsString( '`{$this->wpdb->options}`', $source );
    }

    /**
     * Each PreparedSQL.NotPrepared suppression silences the whole line it is
     * anchored to, so the invariant its own reason states -- that the variable
     * handed to $this->wpdb is the direct return value of $this->wpdb->prepare()
     * -- is load-bearing: a future `$sql = "... {$value} ...";` assigned above
     * one of these lines would be silently unchecked by PHPCS. Enforce it here.
     */
    public function test_every_prepared_sql_suppression_wraps_a_prepare_call(): void {
        $path  = dirname( __DIR__ ) . '/antispam-wizard/includes/class-sample-repository.php';
        $lines = file( $path, FILE_IGNORE_NEW_LINES );

        $checked = 0;
        foreach ( $lines as $index => $line ) {
            if ( false === strpos( $line, 'phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared' ) ) {
                continue;
            }

            $suppressed = $lines[ $index + 1 ] ?? '';
            $this->assertMatchesRegularExpression(
                '/\$this->wpdb->(get_var|get_results|get_col|get_row|query)\(\s*\$(\w+)\s*\)/',
                $suppressed,
                'Suppression on line ' . ( $index + 1 ) . ' must sit directly above a $this->wpdb query call.'
            );
            preg_match( '/\$this->wpdb->\w+\(\s*\$(\w+)\s*\)/', $suppressed, $m );
            $variable = $m[1];

            // Walk back to the nearest assignment of that variable and require it
            // to be a bare prepare() call, not a string built any other way.
            $assignment = null;
            for ( $i = $index; $i >= 0; $i-- ) {
                if ( preg_match( '/\$' . $variable . '\s*=[^=]/', $lines[ $i ] ) ) {
                    $assignment = $lines[ $i ];
                    break;
                }
            }

            $this->assertNotNull( $assignment, 'No assignment found for $' . $variable . '.' );
            $this->assertMatchesRegularExpression(
                '/\$' . $variable . '\s*=\s*\$this->wpdb->prepare\($/',
                trim( $assignment ),
                '$' . $variable . ' (used on line ' . ( $index + 2 ) . ') must be assigned directly from $this->wpdb->prepare().'
            );
            ++$checked;
        }

        $this->assertSame( 15, $checked, 'Expected fifteen PreparedSQL.NotPrepared suppressions.' );
    }

    // --- schema_is_current() ---

    public function test_schema_is_current_is_false_before_the_ddl_runs(): void {
        // No dbDelta() has applied the layout, so the comment_id subject key is
        // absent -- which is what ASW::maybe_upgrade_schema() must see when the
        // database refuses the migration.
        $this->assertFalse( $this->repo->schema_is_current() );
    }

    public function test_schema_is_current_is_true_once_the_ddl_applied(): void {
        dbDelta( $this->repo->get_create_table_sql() );

        $this->assertTrue( $this->repo->schema_is_current() );
    }

    public function test_schema_is_current_probes_the_comment_id_column(): void {
        dbDelta( $this->repo->get_create_table_sql() );

        global $wpdb;
        $wpdb->prepared_queries = array();
        $this->repo->schema_is_current();

        $probes = implode( "\n", $wpdb->prepared_queries );
        $this->assertStringContainsString( 'SHOW COLUMNS', $probes );
        // esc_like() escapes the underscore, which is a LIKE wildcard -- without
        // it the probe would also match a column merely shaped like comment_id.
        $this->assertStringContainsString( 'comment\\_id', $probes );
        $this->assertStringContainsString( 'email\\_hash', $probes );
    }

    public function test_schema_is_current_probes_every_versioned_index(): void {
        dbDelta( $this->repo->get_create_table_sql() );

        global $wpdb;
        $wpdb->prepared_queries = array();
        $this->repo->schema_is_current();

        $probes = implode( "\n", $wpdb->prepared_queries );
        foreach ( ASW_Sample_Repository::VERSIONED_INDEXES as $index ) {
            $this->assertStringContainsString(
                "SHOW INDEX FROM `wp_asw_samples` WHERE Key_name = '{$index}'",
                $probes
            );
        }
    }

    public function test_schema_is_current_is_false_when_the_index_ddl_was_refused(): void {
        // dbDelta() emits the ADD COLUMN and the ADD KEY as separate ALTERs and
        // checks neither result, so a database can accept the column and refuse
        // the index. Stamping DB_VERSION on the column alone would leave the
        // index missing for good, because the version gate then short-circuits
        // every later upgrade attempt.
        global $wp_dbdelta_index_applies;
        $wp_dbdelta_index_applies = false;

        dbDelta( $this->repo->get_create_table_sql() );

        $this->assertFalse( $this->repo->schema_is_current() );
    }

    // --- find_by_email_hash() / delete_by_email_hash() ---

    public function test_find_by_email_hash_returns_only_the_matching_subjects_rows(): void {
        $subject = ASW_Detector::hash_email( 'subject@example.com' );
        $other   = ASW_Detector::hash_email( 'other@example.com' );
        $this->repo->insert( 'latin', 'spam', 'Subject sample one', 701, $subject );
        $this->repo->insert( 'latin', 'spam', 'Subject sample two', 702, $subject );
        $this->repo->insert( 'latin', 'spam', 'Someone else', 703, $other );

        $rows = $this->repo->find_by_email_hash( $subject );

        $this->assertCount( 2, $rows );
        $this->assertSame(
            array( 'Subject sample one', 'Subject sample two' ),
            array_column( $rows, 'content' )
        );
    }

    public function test_find_by_email_hash_reaches_a_sample_whose_comment_is_gone(): void {
        // The whole point of the secondary key: emptying the spam queue
        // hard-deletes the comment, and comment_id alone would then never resolve
        // to this row again while it still holds the full comment text.
        $subject = ASW_Detector::hash_email( 'subject@example.com' );
        $this->repo->insert( 'latin', 'spam', 'Orphaned sample', 704, $subject );

        // No comment IDs at all -- as WordPress core reports for a deleted comment.
        $this->assertSame( array(), $this->repo->find_by_comment_ids( array() ) );
        $this->assertCount( 1, $this->repo->find_by_email_hash( $subject ) );
    }

    public function test_find_by_email_hash_returns_nothing_for_an_empty_hash(): void {
        global $wpdb;
        $this->repo->insert( 'latin', 'spam', 'No address on this one', 705 );
        $wpdb->prepared_queries = array();

        // An empty hash means "the comment had no address", which every such
        // sample shares -- it must never match them all.
        $this->assertSame( array(), $this->repo->find_by_email_hash( '' ) );
        $this->assertSame( array(), $wpdb->prepared_queries );
    }

    public function test_delete_by_email_hash_removes_the_rows_and_bumps_the_revision(): void {
        $subject = ASW_Detector::hash_email( 'subject@example.com' );
        $other   = ASW_Detector::hash_email( 'other@example.com' );
        $this->repo->insert( 'latin', 'spam', 'Erase this one', 706, $subject );
        $this->repo->insert( 'latin', 'spam', 'Keep this one', 707, $other );
        update_option( 'asw_samples_revision', 7 );

        $this->assertSame( 1, $this->repo->delete_by_email_hash( $subject ) );

        $this->assertSame( array(), $this->repo->find_by_email_hash( $subject ) );
        $this->assertCount( 1, $this->repo->find_by_email_hash( $other ) );
        $this->assertSame( 8, (int) get_option( 'asw_samples_revision' ) );
    }

    public function test_delete_by_email_hash_leaves_the_revision_untouched_when_nothing_matched(): void {
        $this->repo->insert( 'latin', 'spam', 'Untouched', 708, ASW_Detector::hash_email( 'subject@example.com' ) );
        update_option( 'asw_samples_revision', 7 );

        $this->assertSame( 0, $this->repo->delete_by_email_hash( ASW_Detector::hash_email( 'nobody@example.com' ) ) );

        $this->assertSame( 7, (int) get_option( 'asw_samples_revision' ) );
    }

    public function test_delete_by_email_hash_deletes_nothing_for_an_empty_hash(): void {
        global $wpdb;
        $this->repo->insert( 'latin', 'spam', 'No address on this one', 709 );
        $wpdb->prepared_queries = array();

        $this->assertSame( 0, $this->repo->delete_by_email_hash( '' ) );

        $this->assertSame( array(), $wpdb->prepared_queries );
        $this->assertCount( 1, $this->repo->find_by_comment_ids( array( 709 ) ) );
    }

    public function test_delete_by_email_hash_returns_false_and_keeps_rows_when_the_query_fails(): void {
        $subject = ASW_Detector::hash_email( 'subject@example.com' );
        $this->repo->insert( 'latin', 'spam', 'Erase me', 710, $subject );
        update_option( 'asw_samples_revision', 7 );

        global $wpdb;
        $wpdb->query_result = false;

        $this->assertFalse( $this->repo->delete_by_email_hash( $subject ) );

        $wpdb->query_result = null;
        $this->assertSame( 7, (int) get_option( 'asw_samples_revision' ) );
        $this->assertCount( 1, $this->repo->find_by_email_hash( $subject ) );
    }

    // --- delete_unattributed() tests ---

    /**
     * Seed a row as a pre-subject-key table would hold it.
     *
     * insert() refuses a non-positive comment_id, so the only way such a row can
     * exist is dbDelta() adding the two columns to an existing table and the old
     * rows taking their defaults. Write it through $wpdb directly to model that.
     *
     * @param string $content Sample text.
     * @return void
     */
    private function seed_legacy_row( string $content ): void {
        global $wpdb;
        $wpdb->insert(
            'wp_asw_samples',
            array(
                'comment_id'   => 0,
                'email_hash'   => '',
                'script'       => 'latin',
                'category'     => 'spam',
                'content'      => $content,
                'content_hash' => hash( 'sha256', $content ),
                'created_at'   => '2026-01-01 00:00:00',
            )
        );
    }

    public function test_delete_unattributed_removes_rows_no_subject_key_can_reach(): void {
        $this->seed_legacy_row( 'Legacy sample no request can reach' );
        $this->repo->insert( 'latin', 'spam', 'Attributed sample', 801, ASW_Detector::hash_email( 'subject@example.com' ) );
        update_option( 'asw_samples_revision', 4 );

        $this->assertSame( 1, $this->repo->delete_unattributed() );

        // Only the unreachable row went away, and the classifier cache is
        // invalidated so the erased text stops influencing verdicts.
        $remaining = $this->repo->find_all( 'latin', 'spam' );
        $this->assertSame( array( 'Attributed sample' ), $remaining );
        $this->assertSame( 5, (int) get_option( 'asw_samples_revision' ) );
    }

    public function test_delete_unattributed_keeps_a_row_that_only_the_hash_key_reaches(): void {
        // comment_id = 0 alone is not unattributed: a sample whose source comment
        // was hard-deleted keeps its email_hash, and that is the whole point of
        // the secondary key.
        global $wpdb;
        $wpdb->insert(
            'wp_asw_samples',
            array(
                'comment_id'   => 0,
                'email_hash'   => ASW_Detector::hash_email( 'subject@example.com' ),
                'script'       => 'latin',
                'category'     => 'spam',
                'content'      => 'Reachable by hash only',
                'content_hash' => hash( 'sha256', 'Reachable by hash only' ),
                'created_at'   => '2026-01-01 00:00:00',
            )
        );

        $this->assertSame( 0, $this->repo->delete_unattributed() );
        $this->assertCount( 1, $this->repo->find_by_email_hash( ASW_Detector::hash_email( 'subject@example.com' ) ) );
    }

    public function test_delete_unattributed_returns_zero_without_bumping_the_revision(): void {
        $this->repo->insert( 'latin', 'spam', 'Attributed sample', 802 );
        update_option( 'asw_samples_revision', 4 );

        $this->assertSame( 0, $this->repo->delete_unattributed() );
        $this->assertSame( 4, (int) get_option( 'asw_samples_revision' ) );
    }

    public function test_delete_unattributed_returns_false_and_keeps_rows_when_the_query_fails(): void {
        $this->seed_legacy_row( 'Legacy sample no request can reach' );
        update_option( 'asw_samples_revision', 4 );

        global $wpdb;
        $wpdb->query_result = false;

        // false, never 0: the schema writers must not stamp DB_VERSION on a
        // refused DELETE, or the rows stay for good.
        $this->assertFalse( $this->repo->delete_unattributed() );

        $wpdb->query_result = null;
        $this->assertSame( 4, (int) get_option( 'asw_samples_revision' ) );
        $this->assertSame( array( 'Legacy sample no request can reach' ), $this->repo->find_all( 'latin', 'spam' ) );
    }

    public function test_delete_unattributed_query_backtick_quotes_the_table_name(): void {
        global $wpdb;
        $wpdb->prepared_queries = array();

        $this->repo->delete_unattributed();

        $this->assertSame(
            array( "DELETE FROM `wp_asw_samples` WHERE comment_id = 0 AND email_hash = ''" ),
            $wpdb->prepared_queries
        );
    }

    public function test_email_hash_queries_backtick_quote_the_table_name(): void {
        global $wpdb;
        $wpdb->prepared_queries = array();

        $this->repo->find_by_email_hash( 'abc' );
        $this->repo->delete_by_email_hash( 'abc' );

        $this->assertSame(
            array(
                "SELECT id, comment_id, email_hash, script, category, content, created_at FROM `wp_asw_samples` WHERE email_hash = 'abc' ORDER BY id ASC",
                "DELETE FROM `wp_asw_samples` WHERE email_hash = 'abc'",
            ),
            $wpdb->prepared_queries
        );
    }

    // --- script_fingerprint() tests ---

    public function test_script_fingerprint_folds_both_categories_with_counts_and_max_ids(): void {
        $this->repo->insert( 'cyrillic', 'spam', 'Spam one', 601 );
        $this->repo->insert( 'cyrillic', 'spam', 'Spam two', 602 );
        $this->repo->insert( 'cyrillic', 'ham', 'Ham one', 603 );

        global $wpdb;
        $max = $wpdb->insert_id;

        $this->assertSame(
            'spam:2:' . ( $max - 1 ) . '|ham:1:' . $max,
            $this->repo->script_fingerprint( 'cyrillic' )
        );
    }

    public function test_script_fingerprint_of_a_script_with_no_rows_is_zeroed(): void {
        $this->assertSame( 'spam:0:0|ham:0:0', $this->repo->script_fingerprint( 'cyrillic' ) );
    }

    public function test_script_fingerprint_renders_a_missing_category_as_zero(): void {
        $this->repo->insert( 'cyrillic', 'spam', 'Only spam', 610 );

        global $wpdb;
        $this->assertSame(
            'spam:1:' . $wpdb->insert_id . '|ham:0:0',
            $this->repo->script_fingerprint( 'cyrillic' )
        );
    }

    public function test_script_fingerprint_moves_when_a_row_is_replaced_at_an_unchanged_count(): void {
        // MAX(id) is what makes the fingerprint exact: a privacy erasure followed
        // by a new training insert leaves COUNT(*) where it was, so a count-only
        // fingerprint would serve a cached unit trained on the erased text.
        $this->repo->insert( 'cyrillic', 'spam', 'Erased later', 620 );
        $before = $this->repo->script_fingerprint( 'cyrillic' );

        $this->assertTrue( $this->repo->delete_by_hash( 'cyrillic', 'spam', hash( 'sha256', 'Erased later' ) ) );
        $this->repo->insert( 'cyrillic', 'spam', 'Trained after the erasure', 621 );

        $after = $this->repo->script_fingerprint( 'cyrillic' );

        $this->assertStringStartsWith( 'spam:1:', (string) $before );
        $this->assertStringStartsWith( 'spam:1:', (string) $after );
        $this->assertNotSame( $before, $after );
    }

    public function test_script_fingerprint_ignores_another_scripts_rows(): void {
        $this->repo->insert( 'cyrillic', 'spam', 'Cyrillic sample', 630 );
        $before = $this->repo->script_fingerprint( 'cyrillic' );

        $this->repo->insert( 'latin', 'spam', 'Latin sample', 631 );
        $this->repo->insert( 'latin', 'ham', 'Latin ham', 632 );

        $this->assertSame( $before, $this->repo->script_fingerprint( 'cyrillic' ) );
    }

    public function test_script_fingerprint_returns_null_when_the_read_fails(): void {
        // A failed read must never be readable as "zero rows": the store would
        // then cache a unit under 'spam:0:0|ham:0:0' -- the very fingerprint a
        // later privacy erasure of every row produces -- and serve the erased
        // text back out of the transient. Null is what lets it fall back to
        // dbrev: instead. The failure is invisible in the return value, which
        // wpdb leaves as the empty array flush() set, so the method has to read
        // last_error; the assertion below pins that it does, and the empty-script
        // case above pins the value it must not be confused with.
        $this->repo->insert( 'cyrillic', 'spam', 'Present row', 640 );

        global $wpdb;
        $wpdb->get_results_result = false;

        $this->assertNull( $this->repo->script_fingerprint( 'cyrillic' ) );
    }

    public function test_script_fingerprint_tells_a_failed_read_apart_from_an_empty_script(): void {
        // The two states the return value cannot distinguish, asserted side by
        // side: an empty script is a real, cacheable fingerprint; a refused read
        // is not.
        global $wpdb;

        $this->assertSame( 'spam:0:0|ham:0:0', $this->repo->script_fingerprint( 'greek' ) );

        $wpdb->get_results_result = false;
        $this->assertNull( $this->repo->script_fingerprint( 'greek' ) );
    }

    public function test_find_all_reports_a_failed_read_through_the_flag_not_the_return_value(): void {
        // find_all() answers the empty array either way -- a failed SELECT hands
        // back the array() wpdb::query()'s flush() left in last_result, which is
        // byte-identical to "this script has no rows". The flag is the only way
        // ASW_Model_Store can tell a unit built from no samples apart from one
        // built from samples it could not read, and caching the latter under a
        // fingerprint that says the rows are in it would drop every user-added
        // sample out of the models for the unit's whole day.
        global $wpdb;

        $this->repo->insert( 'greek', 'spam', 'αγοράστε φθηνά χάπια', 5501 );

        $this->repo->clear_sample_read_failure();
        $this->assertCount( 1, $this->repo->find_all( 'greek', 'spam' ) );
        $this->assertFalse( $this->repo->sample_read_failed(), 'A read that answered must not set the flag' );

        $this->assertSame( array(), $this->repo->find_all( 'greek', 'ham' ) );
        $this->assertFalse( $this->repo->sample_read_failed(), 'An empty category is not a failure' );

        $wpdb->get_results_result = false;

        $this->assertSame( array(), $this->repo->find_all( 'greek', 'spam' ) );
        $this->assertTrue( $this->repo->sample_read_failed(), 'A refused read must be reported through the flag' );

        // The flag is sticky until cleared: one failed read among many must not
        // be washed out by the reads that followed it.
        $wpdb->get_results_result = null;
        $this->assertCount( 1, $this->repo->find_all( 'greek', 'spam' ) );
        $this->assertTrue( $this->repo->sample_read_failed(), 'A later successful read must not clear the flag' );

        $this->repo->clear_sample_read_failure();
        $this->assertFalse( $this->repo->sample_read_failed() );
    }

    public function test_script_fingerprint_query_is_prepared_with_identifier_and_string_placeholders(): void {
        global $wpdb;
        $wpdb->prepared_queries = array();

        $this->repo->script_fingerprint( 'cyrillic' );

        $this->assertSame(
            array(
                'SELECT category, COUNT(*) AS sample_count, MAX(id) AS max_id FROM `wp_asw_samples` WHERE script = \'cyrillic\' GROUP BY category',
            ),
            $wpdb->prepared_queries
        );
    }

    public function test_script_fingerprint_runs_with_wpdb_errors_suppressed(): void {
        // Against a table that was never created the SELECT is a hard MySQL
        // error, and wpdb::print_error() writes it to error_log() whatever
        // show_errors says -- suppress_errors is the only gate. Unbracketed,
        // every comment on such a host would append a plugin-attributable line
        // to the debug.log dev/smoke.sh scans.
        global $wpdb;

        $observed = null;
        $wpdb->on_get_results = static function () use ( $wpdb, &$observed ) {
            $observed = $wpdb->suppress_errors;
        };

        try {
            $this->repo->script_fingerprint( 'cyrillic' );
        } finally {
            $wpdb->on_get_results = null;
        }

        $this->assertTrue( $observed, 'The fingerprint query must run with wpdb errors suppressed.' );
        $this->assertFalse( $wpdb->suppress_errors, 'script_fingerprint() must restore the previous suppress_errors value.' );
    }

    // --- table_fingerprint() tests ---

    public function test_table_fingerprint_counts_every_script(): void {
        $this->repo->insert( 'cyrillic', 'spam', 'Cyrillic sample', 701 );
        $this->repo->insert( 'latin', 'ham', 'Latin sample', 702 );

        global $wpdb;

        $this->assertSame( '2:' . $wpdb->insert_id, $this->repo->table_fingerprint() );
    }

    public function test_table_fingerprint_of_an_empty_table_is_zeroed(): void {
        $this->assertSame( '0:0', $this->repo->table_fingerprint() );
    }

    public function test_table_fingerprint_moves_when_a_row_is_replaced_at_an_unchanged_count(): void {
        // MAX(id) does here what it does per script: an erasure followed by a
        // fresh training insert leaves COUNT(*) where it was.
        $this->repo->insert( 'cyrillic', 'spam', 'Erased later', 710 );
        $before = $this->repo->table_fingerprint();

        $this->assertTrue( $this->repo->delete_by_hash( 'cyrillic', 'spam', hash( 'sha256', 'Erased later' ) ) );
        $this->repo->insert( 'cyrillic', 'spam', 'Trained after the erasure', 711 );

        $after = $this->repo->table_fingerprint();

        $this->assertStringStartsWith( '1:', (string) $before );
        $this->assertStringStartsWith( '1:', (string) $after );
        $this->assertNotSame( $before, $after );
    }

    public function test_table_fingerprint_tells_a_failed_read_apart_from_an_empty_table(): void {
        // The distinction the combined unit's key rests on: an empty table is a
        // real, cacheable fingerprint; a refused read is not, and must fall back
        // to the global revision rather than read as "no rows".
        global $wpdb;

        $this->assertSame( '0:0', $this->repo->table_fingerprint() );

        $wpdb->get_results_result = false;
        $this->assertNull( $this->repo->table_fingerprint() );
    }

    public function test_table_fingerprint_query_is_prepared_with_an_identifier_placeholder(): void {
        global $wpdb;
        $wpdb->prepared_queries = array();

        $this->repo->table_fingerprint();

        $this->assertSame(
            array( 'SELECT COUNT(*) AS sample_count, MAX(id) AS max_id FROM `wp_asw_samples`' ),
            $wpdb->prepared_queries
        );
    }

    public function test_table_fingerprint_runs_with_wpdb_errors_suppressed(): void {
        // The same bracket, and the same reason, as script_fingerprint(): this
        // query runs on every submission that loads the combined unit.
        global $wpdb;

        $observed = null;
        $wpdb->on_get_results = static function () use ( $wpdb, &$observed ) {
            $observed = $wpdb->suppress_errors;
        };

        try {
            $this->repo->table_fingerprint();
        } finally {
            $wpdb->on_get_results = null;
        }

        $this->assertTrue( $observed, 'The fingerprint query must run with wpdb errors suppressed.' );
        $this->assertFalse( $wpdb->suppress_errors, 'table_fingerprint() must restore the previous suppress_errors value.' );
    }
}
