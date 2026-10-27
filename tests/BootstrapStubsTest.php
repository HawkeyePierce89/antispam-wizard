<?php

use PHPUnit\Framework\TestCase;

/**
 * Behaviour of the test-harness stubs the moderation work drives WordPress with.
 *
 * These stubs stand in for parts of WordPress no other test exercised before —
 * the comment status transition action, wp_die(), check_admin_referer() — so the
 * stubs themselves are pinned here. A stub that silently stopped firing the
 * transition would make every moderation test pass vacuously.
 */
class BootstrapStubsTest extends TestCase {

    protected function setUp(): void {
        global $wp_comments, $wp_comment_cache, $wp_comment_meta, $wp_actions, $wp_filters, $wp_nonces, $wp_died, $wp_is_feed;
        $wp_comments      = array();
        $wp_comment_cache = array();
        $wp_comment_meta  = array();
        $wp_actions       = array();
        $wp_filters       = array();
        $wp_nonces        = array();
        $wp_died          = array();
        $wp_is_feed       = false;
        $_REQUEST         = array();
        $_GET             = array();
        antispamwizard_test_reset_comment_query_stubs();
    }

    protected function tearDown(): void {
        global $wp_is_feed, $wp_filters, $wp_actions;
        $wp_is_feed = false;
        $wp_filters = array();
        $wp_actions = array();
        $_REQUEST   = array();
        $_GET       = array();
        antispamwizard_test_reset_comment_query_stubs();
    }

    /**
     * Record every transition_comment_status call into an array by reference.
     *
     * @param array $sink Collected transitions.
     */
    private function listen( array &$sink ): void {
        add_action(
            'transition_comment_status',
            static function ( $new, $old, $comment ) use ( &$sink ) {
                $sink[] = array( $new, $old, $comment );
            },
            10,
            3
        );
    }

    public function test_get_comment_answers_from_the_object_cache_until_it_is_cleaned(): void {
        // Core reads WP_Comment::get_instance(), which consults the 'comment'
        // object cache before the row, and WordPress's default cache is
        // request-local -- so a request can hold a snapshot another process has
        // already superseded. Code that must see the current row therefore has to
        // clean the cache first, and this stub is what lets a test prove it does.
        global $wp_comments, $wp_comment_cache;

        $wp_comments[11] = array(
            'comment_ID'       => 11,
            'comment_content'  => 'Hello there.',
            'comment_approved' => 'approved',
        );

        $wp_comment_cache[11] = array(
            'comment_ID'       => 11,
            'comment_content'  => 'Hello there.',
            'comment_approved' => 'spam',
        );

        $this->assertSame( 'spam', get_comment( 11 )->comment_approved );

        clean_comment_cache( 11 );

        $this->assertSame( 'approved', get_comment( 11 )->comment_approved );
    }

    public function test_set_comment_status_cleans_the_comment_cache_like_core_does(): void {
        global $wp_comments, $wp_comment_cache;

        $wp_comments[12] = array(
            'comment_ID'       => 12,
            'comment_content'  => 'Hello there.',
            'comment_approved' => '1',
        );
        $wp_comment_cache[12] = $wp_comments[12];

        antispamwizard_test_set_comment_status( 12, 'spam' );

        $this->assertSame( array(), $wp_comment_cache );
        $this->assertSame( 'spam', get_comment( 12 )->comment_approved );
    }

    public function test_set_comment_status_fires_the_transition_with_normalized_statuses(): void {
        global $wp_comments;

        $wp_comments[7] = array(
            'comment_ID'       => 7,
            'comment_content'  => 'Hello there.',
            'comment_approved' => '1',
        );

        $seen = array();
        $this->listen( $seen );

        $this->assertTrue( antispamwizard_test_set_comment_status( 7, 'spam' ) );

        $this->assertCount( 1, $seen );
        $this->assertSame( 'spam', $seen[0][0] );
        $this->assertSame( 'approved', $seen[0][1] );
        $this->assertIsObject( $seen[0][2] );
        $this->assertSame( 7, (int) $seen[0][2]->comment_ID );
        $this->assertSame( 'spam', $seen[0][2]->comment_approved );
        $this->assertSame( 'spam', $wp_comments[7]['comment_approved'] );
    }

    public function test_unapproved_and_trash_normalize_the_way_core_does(): void {
        global $wp_comments;

        $wp_comments[8] = array(
            'comment_ID'       => 8,
            'comment_approved' => '0',
        );

        $seen = array();
        $this->listen( $seen );

        antispamwizard_test_set_comment_status( 8, 'spam' );
        antispamwizard_test_set_comment_status( 8, 'trash' );
        antispamwizard_test_set_comment_status( 8, '1' );
        antispamwizard_test_set_comment_status( 8, '0' );

        $this->assertSame(
            array(
                array( 'spam', 'unapproved' ),
                array( 'trash', 'spam' ),
                array( 'approved', 'trash' ),
                array( 'unapproved', 'approved' ),
            ),
            array_map(
                static function ( $entry ) {
                    return array( $entry[0], $entry[1] );
                },
                $seen
            )
        );
    }

    public function test_no_transition_fires_when_the_status_does_not_change(): void {
        global $wp_comments;

        $wp_comments[9] = array(
            'comment_ID'       => 9,
            'comment_approved' => 'spam',
        );

        $seen = array();
        $this->listen( $seen );

        $this->assertTrue( antispamwizard_test_set_comment_status( 9, 'spam' ) );
        $this->assertSame( array(), $seen );

        // '1' and 'approve' both normalize to 'approved', so this is not a change either.
        antispamwizard_test_set_comment_status( 9, '1' );
        $seen = array();
        antispamwizard_test_set_comment_status( 9, 'approve' );
        $this->assertSame( array(), $seen );
    }

    public function test_wp_spam_comment_and_wp_unspam_comment_route_through_the_helper(): void {
        global $wp_comments;

        $wp_comments[10] = array(
            'comment_ID'       => 10,
            'comment_approved' => '1',
        );

        $seen = array();
        $this->listen( $seen );

        $this->assertTrue( wp_spam_comment( 10 ) );
        $this->assertSame( 'spam', $wp_comments[10]['comment_approved'] );
        $this->assertTrue( wp_unspam_comment( 10 ) );
        $this->assertSame( '1', $wp_comments[10]['comment_approved'] );

        $this->assertSame(
            array(
                array( 'spam', 'approved' ),
                array( 'approved', 'spam' ),
            ),
            array_map(
                static function ( $entry ) {
                    return array( $entry[0], $entry[1] );
                },
                $seen
            )
        );
    }

    public function test_unspam_and_untrash_restore_the_recorded_status_like_core(): void {
        global $wp_comments, $wp_comment_meta;

        // Flagged on submission: no recorded status, so Not Spam leaves it held.
        $wp_comments[11] = array( 'comment_ID' => 11, 'comment_approved' => 'spam' );
        $this->assertTrue( wp_unspam_comment( 11 ) );
        $this->assertSame( '0', $wp_comments[11]['comment_approved'] );

        // Spammed from approved, then trashed from spam: each records its prior status.
        $wp_comments[12] = array( 'comment_ID' => 12, 'comment_approved' => '1' );
        $this->assertTrue( wp_spam_comment( 12 ) );
        $this->assertSame( '1', $wp_comment_meta[12]['_wp_trash_meta_status'] );
        $wp_comments[12]['comment_approved']                 = 'trash';
        $wp_comment_meta[12]['_wp_trash_meta_status'] = 'spam';
        $this->assertTrue( wp_untrash_comment( 12 ) );
        $this->assertSame( 'spam', $wp_comments[12]['comment_approved'] );
        $this->assertArrayNotHasKey( '_wp_trash_meta_status', $wp_comment_meta[12] );
    }

    public function test_delete_comment_meta_can_be_made_to_refuse(): void {
        global $wp_comment_meta;

        $wp_comment_meta[13] = array( '_antispamwizard_reason' => 'links' );
        $GLOBALS['wp_delete_comment_meta_fails'] = true;
        try {
            $this->assertFalse( delete_comment_meta( 13, '_antispamwizard_reason' ) );
        } finally {
            $GLOBALS['wp_delete_comment_meta_fails'] = false;
        }
        $this->assertSame( 'links', $wp_comment_meta[13]['_antispamwizard_reason'] );
        $this->assertTrue( delete_comment_meta( 13, '_antispamwizard_reason' ) );
    }

    public function test_update_comment_meta_can_be_made_to_refuse(): void {
        global $wp_comment_meta;

        $wp_comment_meta[14] = array( '_wp_trash_meta_status' => 'spam' );
        $GLOBALS['wp_update_comment_meta_fails'] = true;
        try {
            $this->assertFalse( update_comment_meta( 14, '_wp_trash_meta_status', '0' ) );
            $this->assertSame( 'spam', $wp_comment_meta[14]['_wp_trash_meta_status'] );

            $GLOBALS['wp_update_comment_meta_fails'] = static function ( $comment_id, $meta_key, $meta_value ) {
                return 'spam' === $meta_value;
            };
            $this->assertTrue( update_comment_meta( 14, '_wp_trash_meta_status', '0' ) );
            $this->assertFalse( update_comment_meta( 14, '_wp_trash_meta_status', 'spam' ) );
            $this->assertSame( '0', $wp_comment_meta[14]['_wp_trash_meta_status'] );
        } finally {
            $GLOBALS['wp_update_comment_meta_fails'] = false;
        }
        $this->assertTrue( update_comment_meta( 14, '_wp_trash_meta_status', 'spam' ) );
    }

    public function test_status_change_on_a_missing_comment_is_false_and_silent(): void {
        $seen = array();
        $this->listen( $seen );

        $this->assertFalse( antispamwizard_test_set_comment_status( 4242, 'spam' ) );
        $this->assertFalse( wp_spam_comment( 4242 ) );
        $this->assertSame( array(), $seen );
    }

    public function test_wp_die_records_the_response_and_stops_the_caller(): void {
        global $wp_died;

        $reached_the_end = false;
        try {
            wp_die( 'Nope.', '', array( 'response' => 403 ) );
            $reached_the_end = true;
        } catch ( ANTISPAMWIZARD_Test_WP_Die_Exception $e ) {
            $this->assertSame( 403, $e->response );
            $this->assertSame( 'Nope.', $e->getMessage() );
        }

        $this->assertFalse( $reached_the_end );
        $this->assertSame( array( array( 'message' => 'Nope.', 'response' => 403 ) ), $wp_died );
    }

    public function test_check_admin_referer_accepts_a_matching_nonce_and_dies_otherwise(): void {
        global $wp_died, $wp_nonces;

        wp_create_nonce( 'antispamwizard_action_5' );
        $_REQUEST['_wpnonce'] = $wp_nonces['antispamwizard_action_5'];

        $this->assertSame( 1, check_admin_referer( 'antispamwizard_action_5' ) );
        $this->assertSame( array(), $wp_died );

        $_REQUEST['_wpnonce'] = 'not-the-nonce';
        $this->expectException( ANTISPAMWIZARD_Test_WP_Die_Exception::class );
        check_admin_referer( 'antispamwizard_action_5' );
    }

    public function test_wp_nonce_url_appends_a_nonce_check_admin_referer_accepts(): void {
        global $wp_nonces;

        $url = wp_nonce_url( 'http://example.com/wp-admin/admin-post.php?action=antispamwizard_mark_spam', 'antispamwizard_mark_spam_5' );

        $this->assertStringContainsString( '&_wpnonce=', $url );
        $this->assertArrayHasKey( 'antispamwizard_mark_spam_5', $wp_nonces );

        $query = array();
        parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
        $this->assertSame( 'antispamwizard_mark_spam', $query['action'] );

        $_REQUEST['_wpnonce'] = $query['_wpnonce'];
        $this->assertSame( 1, check_admin_referer( 'antispamwizard_mark_spam_5' ) );

        // A URL with no query string yet gets the '?' separator.
        $this->assertStringContainsString( '?_wpnonce=', wp_nonce_url( 'http://example.com/', 'antispamwizard_mark_spam_6' ) );
    }

    public function test_permalink_feed_and_home_url_stubs(): void {
        $this->assertSame( 'http://example.com/?p=12', get_permalink( 12 ) );
        $this->assertFalse( get_permalink( 0 ) );
        $this->assertSame( 'http://example.com/', home_url( '/' ) );
        $this->assertSame( 'http://example.com', home_url() );

        $this->assertFalse( is_feed() );
        $GLOBALS['wp_is_feed'] = true;
        $this->assertTrue( is_feed() );
    }

    public function test_get_transient_records_every_key_it_is_asked_for(): void {
        // The per-script model units are loaded lazily, so "which unit did this
        // request load?" is the assertion the work turns on. Recording the key
        // on a miss as well as a hit is what makes "read nothing at all" and
        // "read exactly one key" distinguishable.
        global $wp_transient_reads;
        $wp_transient_reads = array();

        set_transient( 'antispamwizard_classifiers_cyrillic_abc', array( 'classifier' => 'x' ) );

        try {
            $this->assertIsArray( get_transient( 'antispamwizard_classifiers_cyrillic_abc' ) );
            $this->assertFalse( get_transient( 'antispamwizard_classifiers_latin_def' ) );
            $this->assertIsArray( get_transient( 'antispamwizard_classifiers_cyrillic_abc' ) );

            $this->assertSame(
                array(
                    'antispamwizard_classifiers_cyrillic_abc',
                    'antispamwizard_classifiers_latin_def',
                    'antispamwizard_classifiers_cyrillic_abc',
                ),
                $wp_transient_reads
            );
        } finally {
            delete_transient( 'antispamwizard_classifiers_cyrillic_abc' );
        }
    }

    public function test_wpdb_answers_the_per_script_group_by_aggregate(): void {
        global $wpdb;
        $wpdb->reset_tables();

        // ids 1..3 cyrillic, 4 latin, 5 cyrillic.
        $wpdb->insert( 'wp_antispamwizard_samples', array( 'script' => 'cyrillic', 'category' => 'spam' ) );
        $wpdb->insert( 'wp_antispamwizard_samples', array( 'script' => 'cyrillic', 'category' => 'spam' ) );
        $wpdb->insert( 'wp_antispamwizard_samples', array( 'script' => 'cyrillic', 'category' => 'ham' ) );
        $wpdb->insert( 'wp_antispamwizard_samples', array( 'script' => 'latin', 'category' => 'spam' ) );
        $wpdb->insert( 'wp_antispamwizard_samples', array( 'script' => 'cyrillic', 'category' => 'spam' ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT category, COUNT(*) AS sample_count, MAX(id) AS max_id FROM %i WHERE script = %s GROUP BY category',
            'wp_antispamwizard_samples',
            'cyrillic'
        ) );

        $this->assertIsArray( $rows );
        $folded = array();
        foreach ( $rows as $row ) {
            $folded[ $row->category ] = array( (int) $row->sample_count, (int) $row->max_id );
        }
        $this->assertSame(
            array(
                'spam' => array( 3, 5 ),
                'ham'  => array( 1, 3 ),
            ),
            $folded
        );

        // A script with no rows groups nothing at all -- never a zero row.
        $this->assertSame( array(), $wpdb->get_results( $wpdb->prepare(
            'SELECT category, COUNT(*) AS sample_count, MAX(id) AS max_id FROM %i WHERE script = %s GROUP BY category',
            'wp_antispamwizard_samples',
            'greek'
        ) ) );

        // A forced DB error reports itself the way the real wpdb does: the empty
        // array flush() left in last_result, plus a non-empty last_error. The
        // return value alone cannot be told apart from the no-rows case above,
        // which is why ANTISPAMWIZARD_Sample_Repository::script_fingerprint() reads
        // last_error rather than guarding on is_array().
        $wpdb->get_results_result = false;
        $this->assertSame( array(), $wpdb->get_results( $wpdb->prepare(
            'SELECT category, COUNT(*) AS sample_count, MAX(id) AS max_id FROM %i WHERE script = %s GROUP BY category',
            'wp_antispamwizard_samples',
            'cyrillic'
        ) ) );
        $this->assertNotSame( '', $wpdb->last_error );

        // And the next statement clears it, as flush() does.
        $wpdb->get_results_result = null;
        $wpdb->get_results( $wpdb->prepare(
            'SELECT category, COUNT(*) AS sample_count, MAX(id) AS max_id FROM %i WHERE script = %s GROUP BY category',
            'wp_antispamwizard_samples',
            'cyrillic'
        ) );
        $this->assertSame( '', $wpdb->last_error );
        $wpdb->reset_tables();
    }

    public function test_on_get_results_observes_wpdb_state_while_the_query_runs(): void {
        // The counterpart of $wpdb->on_get_var: a fingerprint query bracketed in
        // suppress_errors( true ) restores the previous value in a finally, so
        // the flag can only be observed from inside the query itself.
        global $wpdb;
        $wpdb->reset_tables();

        $seen = array();
        $wpdb->on_get_results = static function ( $query ) use ( &$seen, $wpdb ) {
            $seen[] = array( $query, $wpdb->suppress_errors );
        };

        $previous = $wpdb->suppress_errors( true );
        try {
            $wpdb->get_results( 'SELECT id FROM `wp_antispamwizard_samples`' );
        } finally {
            $wpdb->suppress_errors( $previous );
        }

        $this->assertSame( array( array( 'SELECT id FROM `wp_antispamwizard_samples`', true ) ), $seen );
        $this->assertFalse( $wpdb->suppress_errors );

        // The hook is cleared with the rest of the injected state.
        $wpdb->reset_tables();
        $this->assertNull( $wpdb->on_get_results );
    }

    public function test_seeded_rows_come_back_in_the_order_the_table_holds_them(): void {
        // insert() always appends with an ascending auto-increment, so a table
        // built through it can never express rows that are not already in id
        // order -- which would make ANTISPAMWIZARD_Sample_Repository::find_all()'s ORDER BY
        // look honoured whether the clause were there or not.
        global $wpdb;
        $wpdb->reset_tables();

        $wpdb->seed_table_rows(
            'wp_antispamwizard_samples',
            array(
                array( 'id' => 30, 'content' => 'c' ),
                array( 'id' => 10, 'content' => 'a' ),
                array( 'id' => 20, 'content' => 'b' ),
            )
        );

        $unordered = $wpdb->get_results( 'SELECT id, content FROM `wp_antispamwizard_samples`' );
        $this->assertSame( array( 30, 10, 20 ), array_column( $unordered, 'id' ) );
    }

    public function test_an_insert_after_seeding_gets_an_id_past_every_seeded_row(): void {
        // The two row-writing paths have to agree about the auto-increment: an
        // insert() that handed back an id a seeded row already holds would give
        // a test two rows with the same id and no sign of it.
        global $wpdb;
        $wpdb->reset_tables();

        $wpdb->seed_table_rows(
            'wp_antispamwizard_samples',
            array(
                array( 'id' => 9, 'content' => 'c' ),
                array( 'id' => 2, 'content' => 'a' ),
                array( 'id' => 7, 'content' => 'b' ),
            )
        );

        $wpdb->insert( 'wp_antispamwizard_samples', array( 'content' => 'd' ) );

        $this->assertSame( 10, $wpdb->insert_id );
        $this->assertSame(
            array( 9, 2, 7, 10 ),
            array_column( $wpdb->get_results( 'SELECT id FROM `wp_antispamwizard_samples`' ), 'id' )
        );
    }

    public function test_order_by_sorts_ascending_and_descending_and_by_string(): void {
        global $wpdb;
        $wpdb->reset_tables();

        $wpdb->seed_table_rows(
            'wp_antispamwizard_samples',
            array(
                array( 'id' => 30, 'content' => 'c' ),
                array( 'id' => 10, 'content' => 'a' ),
                array( 'id' => 20, 'content' => 'b' ),
            )
        );

        // Numeric columns compare as numbers, not as strings -- `%d` renders
        // unquoted, so an id column arrives as a bare number either way.
        $this->assertSame(
            array( 10, 20, 30 ),
            array_column( $wpdb->get_results( 'SELECT id FROM `wp_antispamwizard_samples` ORDER BY id ASC' ), 'id' )
        );
        $this->assertSame(
            array( 30, 20, 10 ),
            array_column( $wpdb->get_results( 'SELECT id FROM `wp_antispamwizard_samples` ORDER BY id DESC' ), 'id' )
        );
        $this->assertSame(
            array( 'a', 'b', 'c' ),
            array_column(
                $wpdb->get_results( 'SELECT content FROM `wp_antispamwizard_samples` ORDER BY content' ),
                'content'
            )
        );
    }

    /**
     * Seed the comment fixture for the WP_Comment_Query tests: post 7 holds an
     * approved comment, the visitor's held comment, a stranger's held comment,
     * an approved reply and spam rows; post 8 holds one approved comment.
     */
    private function seed_comment_thread(): void {
        global $wp_comments;
        $wp_comments = array(
            1 => array( 'comment_ID' => 1, 'comment_post_ID' => 7, 'comment_approved' => '1', 'comment_author_email' => 'other@example.com' ),
            2 => array( 'comment_ID' => 2, 'comment_post_ID' => 7, 'comment_approved' => '0', 'comment_author_email' => 'me@example.com' ),
            3 => array( 'comment_ID' => 3, 'comment_post_ID' => 7, 'comment_approved' => '0', 'comment_author_email' => 'other@example.com' ),
            4 => array( 'comment_ID' => 4, 'comment_post_ID' => 7, 'comment_approved' => '1', 'comment_parent' => 1, 'comment_author_email' => 'other@example.com' ),
            5 => array( 'comment_ID' => 5, 'comment_post_ID' => 7, 'comment_approved' => 'spam', 'comment_author_email' => 'me@example.com' ),
            6 => array( 'comment_ID' => 6, 'comment_post_ID' => 8, 'comment_approved' => '1', 'comment_author_email' => 'me@example.com' ),
            7 => array( 'comment_ID' => 7, 'comment_post_ID' => 7, 'comment_approved' => '0', 'user_id' => 42, 'comment_author_email' => 'user@example.com' ),
        );
    }

    /**
     * Run a front-end style comment query and return the comment IDs.
     *
     * @param array $args Extra query vars.
     * @return int[]
     */
    private function query_ids( array $args ): array {
        $query = new WP_Comment_Query();
        return array_map(
            static function ( $comment ) {
                return (int) $comment->comment_ID;
            },
            $query->query( $args )
        );
    }

    public function test_ref_array_hooks_hand_callbacks_the_reference_and_honour_accepted_args(): void {
        $seen = array();
        add_action(
            'antispamwizard_ref_action',
            static function ( $first ) use ( &$seen ) {
                $seen[] = func_get_args();
            },
            10,
            1
        );
        add_filter(
            'antispamwizard_ref_filter',
            static function ( $value, $holder ) {
                $holder->touched = true;
                return $value . '-a';
            },
            10,
            2
        );
        add_filter(
            'antispamwizard_ref_filter',
            static function ( $value ) {
                return $value . '-b';
            }
        );

        $holder = new stdClass();
        do_action_ref_array( 'antispamwizard_ref_action', array( 'one', 'two' ) );
        $this->assertSame( array( array( 'one' ) ), $seen, 'accepted_args = 1 hands over the first argument only' );

        // Each callback receives the value returned so far, in registration order.
        $this->assertSame( 'v-a-b', apply_filters_ref_array( 'antispamwizard_ref_filter', array( 'v', &$holder ) ) );
        $this->assertTrue( $holder->touched );
        $this->assertSame( 'untouched', apply_filters_ref_array( 'antispamwizard_no_such_filter', array( 'untouched' ) ) );
    }

    public function test_identity_stubs_follow_their_switches(): void {
        $this->assertSame(
            array( 'comment_author' => '', 'comment_author_email' => '', 'comment_author_url' => '' ),
            wp_get_current_commenter()
        );
        $GLOBALS['wp_current_commenter'] = array( 'comment_author_email' => 'me@example.com' );
        $this->assertSame( 'me@example.com', wp_get_current_commenter()['comment_author_email'] );
        $this->assertSame( '', wp_get_current_commenter()['comment_author'] );

        $this->assertSame( 0, get_current_user_id() );
        $this->assertFalse( is_user_logged_in() );
        $GLOBALS['wp_current_user_id'] = 42;
        $this->assertSame( 42, get_current_user_id() );
        $this->assertTrue( is_user_logged_in() );

        $this->assertFalse( wp_doing_ajax() );
        $this->assertFalse( wp_doing_cron() );
        $GLOBALS['wp_doing_ajax'] = true;
        $GLOBALS['wp_doing_cron'] = true;
        $this->assertTrue( wp_doing_ajax() );
        $this->assertTrue( wp_doing_cron() );

        $GLOBALS['wp_current_commenter_throws'] = true;
        $this->expectException( RuntimeException::class );
        wp_get_current_commenter();
    }

    public function test_wp_hash_is_core_md5_hmac_keyed_with_the_salt(): void {
        $this->assertSame( hash_hmac( 'md5', '2026-10-08 12:00:00', wp_salt( 'auth' ) ), wp_hash( '2026-10-08 12:00:00' ) );
        $this->assertSame( hash_hmac( 'md5', 'x', wp_salt( 'nonce' ) ), wp_hash( 'x', 'nonce' ) );
        $this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', wp_hash( 'x' ) );
    }

    /**
     * Seed a comment written $minutes_ago minutes ago and return its moderation hash.
     */
    private function seed_unapproved_pair_comment( int $id, string $email, int $minutes_ago ): string {
        global $wp_comments;
        $date_gmt           = gmdate( 'Y-m-d H:i:s', time() - $minutes_ago * 60 );
        $wp_comments[ $id ] = array(
            'comment_ID'           => (string) $id,
            'comment_approved'     => 'spam',
            'comment_author_email' => $email,
            'comment_date_gmt'     => $date_gmt,
        );
        return wp_hash( $date_gmt );
    }

    public function test_unapproved_author_email_resolves_a_valid_pair(): void {
        $hash = $this->seed_unapproved_pair_comment( 7, 'author@example.com', 1 );
        $GLOBALS['wp_current_commenter'] = array( 'comment_author_email' => 'cookie@example.com' );
        $_GET = array( 'unapproved' => '7', 'moderation-hash' => $hash );

        // The pair wins over the cookie, and the comment's status is never checked (it is spam here).
        $this->assertSame( 'author@example.com', wp_get_unapproved_comment_author_email() );
    }

    public function test_unapproved_author_email_falls_back_to_the_cookie(): void {
        $hash         = $this->seed_unapproved_pair_comment( 7, 'author@example.com', 1 );
        $expired_hash = $this->seed_unapproved_pair_comment( 8, 'late@example.com', 11 );
        $GLOBALS['wp_current_commenter'] = array( 'comment_author_email' => 'cookie@example.com' );

        $cases = array(
            'wrong hash'        => array( 'unapproved' => '7', 'moderation-hash' => str_repeat( '0', 32 ) ),
            'expired window'    => array( 'unapproved' => '8', 'moderation-hash' => $expired_hash ),
            'unknown ID'        => array( 'unapproved' => '999', 'moderation-hash' => $hash ),
            'missing hash'      => array( 'unapproved' => '7' ),
            'missing ID'        => array( 'moderation-hash' => $hash ),
            'no pair at all'    => array(),
        );
        foreach ( $cases as $label => $get ) {
            $_GET = $get;
            $this->assertSame( 'cookie@example.com', wp_get_unapproved_comment_author_email(), $label );
        }

        $GLOBALS['wp_current_commenter'] = array();
        $_GET = array( 'unapproved' => '7', 'moderation-hash' => 'bad' );
        $this->assertSame( '', wp_get_unapproved_comment_author_email(), 'no pair and no cookie resolve nothing' );
    }

    public function test_unapproved_author_email_falls_back_to_the_throwing_commenter(): void {
        $GLOBALS['wp_current_commenter_throws'] = true;
        $this->expectException( RuntimeException::class );
        wp_get_unapproved_comment_author_email();
    }

    public function test_add_query_arg_keeps_the_existing_query_and_the_fragment(): void {
        // A bare URL keeps the stub's original output.
        $this->assertSame( 'http://example.com/wp-admin/admin.php?page=x', add_query_arg( array( 'page' => 'x' ), 'http://example.com/wp-admin/admin.php' ) );

        $this->assertSame(
            'http://example.com/post/?unapproved=5&moderation-hash=abc#comment-5',
            add_query_arg( array( 'unapproved' => 5, 'moderation-hash' => 'abc' ), 'http://example.com/post/#comment-5' )
        );
        $this->assertSame(
            'http://example.com/post/?p=3&unapproved=5#comment-5',
            add_query_arg( array( 'unapproved' => 5 ), 'http://example.com/post/?p=3#comment-5' ),
            'the existing query is joined with & and the fragment stays last'
        );
        $this->assertSame(
            'http://example.com/post/?p=3&unapproved=6',
            add_query_arg( array( 'unapproved' => 6 ), 'http://example.com/post/?p=3&unapproved=5' ),
            'an existing key is replaced in place, never duplicated'
        );
    }

    public function test_wp_parse_url_and_wp_parse_str_read_a_redirect_query(): void {
        $this->assertSame( 'p=3&unapproved=5', wp_parse_url( 'http://example.com/post/?p=3&unapproved=5#comment-5', PHP_URL_QUERY ) );
        $this->assertNull( wp_parse_url( 'http://example.com/post/#comment-5', PHP_URL_QUERY ) );

        wp_parse_str( 'p=3&moderation-hash=abc', $parsed );
        $this->assertSame( array( 'p' => '3', 'moderation-hash' => 'abc' ), $parsed );

        wp_parse_str( null, $empty );
        $this->assertSame( array(), $empty, 'a URL without a query parses to nothing' );

        add_filter(
            'wp_parse_str',
            static function ( $result ) {
                return $result + array( 'filtered' => '1' );
            }
        );
        try {
            wp_parse_str( 'a=1', $filtered );
        } finally {
            unset( $GLOBALS['wp_filters']['wp_parse_str'] );
        }
        $this->assertSame( array( 'a' => '1', 'filtered' => '1' ), $filtered, 'the wp_parse_str filter runs, as in core' );
    }

    public function test_reset_puts_every_comment_query_switch_back(): void {
        $GLOBALS['wp_current_commenter']        = array( 'comment_author_email' => 'me@example.com' );
        $GLOBALS['wp_current_commenter_throws'] = true;
        $GLOBALS['wp_current_user_id']          = 3;
        $GLOBALS['wp_doing_ajax']               = true;
        $GLOBALS['wp_doing_cron']               = true;
        $GLOBALS['wp_comment_query_cache']      = array( 'k' => array( 1 ) );
        $GLOBALS['wp_comment_query_cache_keys'] = array( 'k' );
        $GLOBALS['wp_comment_query_log']        = array( 'w' );

        antispamwizard_test_reset_comment_query_stubs();

        $this->assertSame( array(), $GLOBALS['wp_current_commenter'] );
        $this->assertFalse( $GLOBALS['wp_current_commenter_throws'] );
        $this->assertSame( 0, $GLOBALS['wp_current_user_id'] );
        $this->assertFalse( $GLOBALS['wp_doing_ajax'] );
        $this->assertFalse( $GLOBALS['wp_doing_cron'] );
        $this->assertSame( array(), $GLOBALS['wp_comment_query_cache'] );
        $this->assertSame( array(), $GLOBALS['wp_comment_query_cache_keys'] );
        $this->assertSame( array(), $GLOBALS['wp_comment_query_log'] );
    }

    public function test_get_comment_applies_its_filter_and_passes_objects_through(): void {
        // Core hands a WP_Comment back unchanged and runs the 'get_comment'
        // filter on every path -- which is what makes that filter the only
        // hook that reaches a comment re-fetched by ID.
        global $wp_comments, $wp_comment_cache;
        $wp_comments[20] = array( 'comment_ID' => 20, 'comment_approved' => 'spam' );

        $first  = get_comment( 20 );
        $second = get_comment( 20 );
        $this->assertNotSame( $first, $second, 'an ID resolves to a fresh object per call' );
        $this->assertSame( $first, get_comment( $first ), 'an object is passed through as-is' );
        $this->assertNull( get_comment( 999 ) );

        $calls = array();
        add_filter(
            'get_comment',
            static function ( $comment ) use ( &$calls ) {
                $calls[]                    = (int) $comment->comment_ID;
                $presented                  = clone $comment;
                $presented->comment_approved = '0';
                return $presented;
            }
        );

        $this->assertSame( '0', get_comment( 20 )->comment_approved );
        $this->assertSame( '0', get_comment( $first )->comment_approved );
        $this->assertSame( 'spam', $first->comment_approved, 'the filter returned a clone' );
        $this->assertSame( array( 20, 20 ), $calls );
        $this->assertSame( 'spam', $wp_comments[20]['comment_approved'] );
        $this->assertSame( array(), $wp_comment_cache, 'get_comment() never writes the object cache' );
    }

    public function test_comment_query_runs_core_hook_order(): void {
        $this->seed_comment_thread();
        $order = array();
        add_action(
            'pre_get_comments',
            static function ( $query ) use ( &$order ) {
                $order[] = 'pre_get_comments';
            }
        );
        add_filter(
            'comments_clauses',
            static function ( $clauses, $query ) use ( &$order ) {
                $order[] = 'comments_clauses';
                return $clauses;
            },
            10,
            2
        );
        add_filter(
            'the_comments',
            static function ( $comments, $query ) use ( &$order ) {
                $order[] = 'the_comments';
                return $comments;
            },
            10,
            2
        );
        add_filter(
            'get_comment',
            static function ( $comment ) use ( &$order ) {
                $order[] = 'get_comment:' . $comment->comment_ID;
                return $comment;
            }
        );

        $this->query_ids( array( 'post_id' => 8, 'status' => 'approve' ) );

        $this->assertSame(
            array( 'pre_get_comments', 'comments_clauses', 'get_comment:6', 'the_comments', 'get_comment:6' ),
            $order
        );
    }

    public function test_comment_query_hands_callbacks_the_query_object_itself(): void {
        $this->seed_comment_thread();
        $seen = array();
        foreach ( array( 'pre_get_comments' ) as $tag ) {
            add_action(
                $tag,
                static function ( $query ) use ( &$seen, $tag ) {
                    $seen[ $tag ] = $query;
                }
            );
        }
        foreach ( array( 'comments_clauses', 'the_comments' ) as $tag ) {
            add_filter(
                $tag,
                static function ( $value, $query ) use ( &$seen, $tag ) {
                    $seen[ $tag ] = $query;
                    return $value;
                },
                10,
                2
            );
        }

        $query = new WP_Comment_Query();
        $query->query( array( 'post_id' => 7, 'status' => 'approve' ) );

        $this->assertSame( $query, $seen['pre_get_comments'] );
        $this->assertSame( $query, $seen['comments_clauses'] );
        $this->assertSame( $query, $seen['the_comments'] );
    }

    public function test_include_unapproved_builds_core_approved_fragments(): void {
        $this->seed_comment_thread();

        $query = new WP_Comment_Query();
        $ids   = array_map(
            static function ( $c ) {
                return (int) $c->comment_ID;
            },
            $query->query(
                array(
                    'post_id'            => 7,
                    'status'             => 'approve',
                    'include_unapproved' => array( 'me@example.com' ),
                )
            )
        );

        $approved = "( ( comment_approved = '1' ) OR ( comment_author_email = 'me@example.com' AND comment_approved = '0' ) )";
        $this->assertSame( $approved, antispamwizard_test_get_sql_clauses( $query )['where']['approved'] );
        $this->assertSame( array( $approved . ' AND comment_post_ID = 7' ), $GLOBALS['wp_comment_query_log'] );
        // The visitor's held comment joins the approved ones; a stranger's held
        // comment, the visitor's spam and another post's comment stay out.
        $this->assertSame( array( 1, 2, 4 ), $ids );

        $by_user = $this->query_ids(
            array(
                'post_id'            => 7,
                'status'             => 'approve',
                'include_unapproved' => array( 42 ),
            )
        );
        $this->assertStringContainsString( "( user_id = 42 AND comment_approved = '0' )", $GLOBALS['wp_comment_query_log'][1] );
        $this->assertSame( array( 1, 4, 7 ), $by_user );

        $this->assertSame( array( 1, 4 ), $this->query_ids( array( 'post_id' => 7, 'status' => 'approve' ) ) );
    }

    public function test_count_and_ids_queries_return_their_shapes(): void {
        $this->seed_comment_thread();

        $query = new WP_Comment_Query();
        $this->assertSame( 2, $query->query( array( 'post_id' => 7, 'status' => 'approve', 'count' => true ) ) );
        $query = new WP_Comment_Query();
        $this->assertSame( array( 1, 4 ), $query->query( array( 'post_id' => 7, 'status' => 'approve', 'fields' => 'ids' ) ) );
    }

    public function test_cache_key_changes_when_a_query_var_default_is_added(): void {
        // Core keys the 'comment-queries' cache on the vars limited to the keys
        // of query_var_defaults, so a pre_get_comments callback can only make a
        // query var count by adding its key there. A hit skips the clauses.
        $this->seed_comment_thread();
        $args = array( 'post_id' => 7, 'status' => 'approve' );

        $this->query_ids( $args );
        $this->query_ids( $args + array( 'not_a_default' => 'x' ) );
        $keys = $GLOBALS['wp_comment_query_cache_keys'];
        $this->assertCount( 2, $keys );
        $this->assertSame( $keys[0], $keys[1], 'a var outside query_var_defaults does not reach the key' );
        $this->assertCount( 1, $GLOBALS['wp_comment_query_log'], 'the second query was a cache hit' );

        add_action(
            'pre_get_comments',
            static function ( $query ) {
                $query->query_var_defaults['antispamwizard_marker'] = '';
                $query->query_vars['antispamwizard_marker']         = 'on';
            }
        );
        $this->query_ids( $args );
        $this->assertNotSame( $keys[0], $GLOBALS['wp_comment_query_cache_keys'][2] );
        $this->assertCount( 2, $GLOBALS['wp_comment_query_log'], 'a new key misses the cache' );

        // `fields` is left out of the key, as in core.
        $GLOBALS['wp_comment_query_cache_keys'] = array();
        $GLOBALS['wp_actions']                  = array();
        ( new WP_Comment_Query() )->query( $args + array( 'fields' => 'ids' ) );
        $this->assertSame( $keys[0], $GLOBALS['wp_comment_query_cache_keys'][0] );
    }

    public function test_matcher_throws_on_an_unknown_fragment(): void {
        $this->seed_comment_thread();
        add_filter(
            'comments_clauses',
            static function ( $clauses, $query ) {
                $approved                               = antispamwizard_test_get_sql_clauses( $query )['where']['approved'];
                $widened                                = '( ' . $approved . ' OR comment_karma = 1 )';
                $clauses['where']                       = str_replace( $approved, $widened, $clauses['where'] );
                antispamwizard_test_set_approved_clause( $query, $widened );
                return $clauses;
            },
            10,
            2
        );

        $this->expectException( LogicException::class );
        $this->expectExceptionMessage( 'comment_karma = 1' );
        $this->query_ids( array( 'post_id' => 7, 'status' => 'approve' ) );
    }

    public function test_matcher_throws_when_the_where_and_the_approved_clause_disagree(): void {
        // A filter that wrote the widened clause back to sql_clauses['where']
        // ['approved'] but not into the final WHERE would otherwise be
        // evaluated on a clause the "database" never ran.
        $this->seed_comment_thread();
        add_filter(
            'comments_clauses',
            static function ( $clauses, $query ) {
                $approved                                = antispamwizard_test_get_sql_clauses( $query )['where']['approved'];
                antispamwizard_test_set_approved_clause( $query, '( ' . $approved . " OR comment_approved = 'spam' )" );
                return $clauses;
            },
            10,
            2
        );

        $this->expectException( LogicException::class );
        $this->expectExceptionMessage( 'does not contain the approved clause' );
        $this->query_ids( array( 'post_id' => 7, 'status' => 'approve' ) );
    }

    public function test_matcher_evaluates_the_shadow_shape(): void {
        global $wp_comments, $wp_comment_meta, $wpdb;
        $this->seed_comment_thread();
        $wp_comments[9]  = array( 'comment_ID' => 9, 'comment_post_ID' => 7, 'comment_approved' => 'spam', 'comment_type' => 'pingback', 'comment_author_email' => 'me@example.com' );
        $wp_comments[10] = array( 'comment_ID' => 10, 'comment_post_ID' => 7, 'comment_approved' => 'spam', 'comment_type' => 'comment', 'comment_author_email' => 'me@example.com' );
        $wp_comment_meta[5]['_antispamwizard_reason']  = 'bayesian';
        $wp_comment_meta[9]['_antispamwizard_reason']  = 'links';
        $wp_comment_meta[10]['_antispamwizard_reason'] = 'links';
        // 11: same author, spam, no _antispamwizard_reason -- a moderator's verdict.
        $wp_comments[11] = array( 'comment_ID' => 11, 'comment_post_ID' => 7, 'comment_approved' => 'spam', 'comment_author_email' => 'me@example.com' );

        add_filter(
            'comments_clauses',
            static function ( $clauses, $query ) use ( $wpdb ) {
                $approved = antispamwizard_test_get_sql_clauses( $query )['where']['approved'];
                $shadow   = $wpdb->prepare(
                    "( comment_author_email = %s AND comment_approved = 'spam' AND comment_type IN ( '', 'comment' ) AND %i.comment_ID > %d AND EXISTS ( SELECT 1 FROM %i WHERE comment_id = %i.comment_ID AND meta_key = '_antispamwizard_reason' ) )",
                    'me@example.com',
                    $wpdb->comments,
                    5,
                    $wpdb->commentmeta,
                    $wpdb->comments
                );
                $widened                                 = '( ' . $approved . ' OR ' . $shadow . ' )';
                $clauses['where']                        = str_replace( $approved, $widened, $clauses['where'] );
                antispamwizard_test_set_approved_clause( $query, $widened );
                return $clauses;
            },
            10,
            2
        );

        // 5 is a flagged comment at the cutoff.
        $this->assertSame(
            array( 1, 2, 4, 10 ),
            $this->query_ids(
                array(
                    'post_id'            => 7,
                    'status'             => 'approve',
                    'include_unapproved' => array( 'me@example.com' ),
                )
            )
        );
    }

    public function test_threaded_query_refetches_every_comment_through_get_comment(): void {
        // Core's fill_descendants() fetches each level through a nested query
        // and then re-reads every comment by ID, so whatever 'the_comments' did
        // to the objects is gone from a threaded result -- only a 'get_comment'
        // filter reaches it. A flat query keeps the_comments' objects.
        $this->seed_comment_thread();
        add_filter(
            'the_comments',
            static function ( $comments, $query ) {
                foreach ( $comments as $comment ) {
                    $comment->from_the_comments = true;
                }
                return $comments;
            },
            10,
            2
        );
        $nested = array();
        $fields = array();
        add_action(
            'pre_get_comments',
            static function ( $query ) use ( &$nested, &$fields ) {
                $nested[] = $query->query_vars['parent__in'];
                $fields[] = $query->query_vars['fields'];
            }
        );

        $flat = ( new WP_Comment_Query() )->query( array( 'post_id' => 7, 'status' => 'approve' ) );
        $this->assertTrue( $flat[0]->from_the_comments );

        $nested   = array();
        $threaded = ( new WP_Comment_Query() )->query(
            array(
                'post_id'      => 7,
                'status'       => 'approve',
                'hierarchical' => 'threaded',
            )
        );
        $this->assertSame( array( 1, 4 ), array_map( 'intval', wp_list_pluck_stub( $threaded, 'comment_ID' ) ), 'returned flat, top level first' );
        foreach ( $threaded as $comment ) {
            $this->assertFalse( property_exists( $comment, 'from_the_comments' ) );
        }
        $this->assertSame( array( '', array( 1 ), array( 4 ) ), $nested, 'one nested query per level, by parent__in' );
        // Core's fill_descendants() keeps the parent's `fields`: each level is an
        // object query, not an IDs-only one.
        $this->assertSame( array( '', '', '' ), array_slice( $fields, -3 ) );

        add_filter(
            'get_comment',
            static function ( $comment ) {
                $presented            = clone $comment;
                $presented->presented = true;
                return $presented;
            }
        );
        $threaded = ( new WP_Comment_Query() )->query(
            array(
                'post_id'      => 7,
                'status'       => 'approve',
                'hierarchical' => 'threaded',
            )
        );
        foreach ( $threaded as $comment ) {
            $this->assertTrue( $comment->presented );
        }
    }

    public function test_a_cached_descendant_level_runs_no_nested_query(): void {
        // Core's fill_descendants() caches each parent's child IDs under a key
        // built from the query vars, and on a hit skips the nested query --
        // so a cached reply never passes through 'pre_get_comments' or
        // 'the_comments' again, only through get_comment().
        $this->seed_comment_thread();
        $nested = array();
        add_action(
            'pre_get_comments',
            static function ( $query ) use ( &$nested ) {
                if ( '' !== $query->query_vars['parent__in'] ) {
                    $nested[] = $query->query_vars['parent__in'];
                }
            }
        );
        $args = array(
            'post_id'      => 7,
            'status'       => 'approve',
            'hierarchical' => 'threaded',
        );

        $cold = ( new WP_Comment_Query() )->query( $args );
        $this->assertSame( array( array( 1 ), array( 4 ) ), $nested, 'control: the cold run queried each level' );

        $nested = array();
        $warm   = ( new WP_Comment_Query() )->query( $args );
        $this->assertSame( array(), $nested, 'a warm run reads every level from the child-ID cache' );
        $this->assertSame(
            array_map( 'intval', wp_list_pluck_stub( $cold, 'comment_ID' ) ),
            array_map( 'intval', wp_list_pluck_stub( $warm, 'comment_ID' ) )
        );

        $nested                     = array();
        $args['include_unapproved'] = array( 'me@example.com' );
        ( new WP_Comment_Query() )->query( $args );
        $this->assertNotEmpty( $nested, 'different query vars, different child-ID key' );
    }

    public function test_comment_query_internals_are_protected_as_in_core(): void {
        // Core declares both protected: a plugin that reads them as public
        // properties passes against a public stub and does nothing on a real
        // site, so the stub must refuse that access too.
        foreach ( array( 'sql_clauses', 'filtered_where_clause' ) as $name ) {
            $this->assertTrue( ( new ReflectionProperty( WP_Comment_Query::class, $name ) )->isProtected(), $name );
        }
        foreach ( array( 'query_vars', 'query_var_defaults', 'request', 'comments' ) as $name ) {
            $this->assertTrue( ( new ReflectionProperty( WP_Comment_Query::class, $name ) )->isPublic(), $name );
        }
    }
}
