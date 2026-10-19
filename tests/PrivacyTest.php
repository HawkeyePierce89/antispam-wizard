<?php
/**
 * Tests for ASW_Privacy (WordPress Privacy API integration).
 */

use PHPUnit\Framework\TestCase;

/**
 * A repository whose hash lookup always comes back empty.
 *
 * find_by_email_hash() collapses a failed read (get_results() returning null)
 * into an empty array, so this is what a database hiccup looks like to the
 * exporter while the comment-keyed query still succeeds.
 */
class ASW_Hash_Blind_Sample_Repository extends ASW_Sample_Repository {

    public function find_by_email_hash( string $email_hash ): array {
        return array();
    }
}

class PrivacyTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        global $wpdb, $wp_options, $wp_actions, $wp_filters, $wp_cache_deletes,
            $wp_comments, $wp_comment_meta, $wp_privacy_policy_content;

        $wpdb->reset_tables();
        $wp_options                = array();
        $wp_actions                = array();
        $wp_filters                = array();
        $wp_cache_deletes          = array();
        $wp_comments               = array();
        $wp_comment_meta           = array();
        $wp_privacy_policy_content = array();

        if ( ! defined( 'ASW_PATH' ) ) {
            define( 'ASW_PATH', dirname( __DIR__ ) . '/antispam-wizard/' );
        }

        // Reset the ASW singleton between tests, mirroring the other suites.
        $reflection = new ReflectionClass( ASW::class );
        $instance   = $reflection->getProperty( 'instance' );
        $instance->setValue( null, null );
    }

    /**
     * Register a fake comment with an author email.
     *
     * @param string $approved comment_approved value ('1' approved, '0' held,
     *                         'spam', 'trash'). Defaults to approved.
     */
    private function add_comment( int $id, string $email, string $approved = '1' ): void {
        global $wp_comments;
        $wp_comments[ $id ] = array(
            'comment_ID'           => $id,
            'comment_author_email' => $email,
            'comment_approved'     => $approved,
        );
    }

    /**
     * Boot the plugin so ASW::get_repository() returns a live repository.
     *
     * setUp() nulls the singleton, so without this the exporter takes its
     * "no repository" path.
     */
    private function boot_repository(): ASW_Sample_Repository {
        ASW::get_instance()->init();
        return ASW::get_instance()->get_repository();
    }

    // -- Hook registration ---------------------------------------------------

    public function test_register_hooks_registers_filters_and_action(): void {
        global $wp_filters, $wp_actions;

        $privacy = new ASW_Privacy();
        $privacy->register_hooks();

        $this->assertArrayHasKey( 'wp_privacy_personal_data_exporters', $wp_filters );
        $this->assertArrayHasKey( 'wp_privacy_personal_data_erasers', $wp_filters );
        $this->assertArrayHasKey( 'admin_init', $wp_actions );
    }

    public function test_eraser_is_registered_ahead_of_cores_comment_eraser(): void {
        global $wp_filters;

        $privacy = new ASW_Privacy();
        $privacy->register_hooks();

        $eraser = null;
        foreach ( $wp_filters['wp_privacy_personal_data_erasers'] as $hook ) {
            if ( is_array( $hook['callback'] ) && 'register_eraser' === $hook['callback'][1] ) {
                $eraser = $hook;
            }
        }

        $this->assertNotNull( $eraser );
        // WordPress runs erasers in array order and core's 'wordpress-comments'
        // eraser (priority 10) blanks comment_author_email as it anonymizes.
        // Registering later than 10 would leave this eraser unable to resolve
        // the subject to any comment, so it would remove nothing while still
        // reporting the erasure as complete.
        $this->assertLessThan( 10, $eraser['priority'] );
    }

    public function test_register_exporter_adds_item_with_expected_key(): void {
        $privacy   = new ASW_Privacy();
        $exporters = $privacy->register_exporter( array() );

        $this->assertArrayHasKey( ASW_Privacy::PRIVACY_KEY, $exporters );
        $this->assertSame(
            'AntiSpam Wizard',
            $exporters[ ASW_Privacy::PRIVACY_KEY ]['exporter_friendly_name']
        );
        $this->assertSame(
            array( $privacy, 'export' ),
            $exporters[ ASW_Privacy::PRIVACY_KEY ]['callback']
        );
    }

    public function test_register_eraser_adds_item_with_expected_key(): void {
        $privacy = new ASW_Privacy();
        $erasers = $privacy->register_eraser( array() );

        $this->assertArrayHasKey( ASW_Privacy::PRIVACY_KEY, $erasers );
        $this->assertSame(
            'AntiSpam Wizard',
            $erasers[ ASW_Privacy::PRIVACY_KEY ]['eraser_friendly_name']
        );
        $this->assertSame(
            array( $privacy, 'erase' ),
            $erasers[ ASW_Privacy::PRIVACY_KEY ]['callback']
        );
    }

    public function test_init_wires_privacy_hooks(): void {
        global $wp_filters, $wp_actions;

        ASW::get_instance()->init();

        $this->assertArrayHasKey( 'wp_privacy_personal_data_exporters', $wp_filters );
        $this->assertArrayHasKey( 'wp_privacy_personal_data_erasers', $wp_filters );
    }

    // -- Export --------------------------------------------------------------

    public function test_export_returns_log_entries_and_meta_for_subject(): void {
        global $wp_comment_meta;

        $this->add_comment( 10, 'subject@example.com' );
        ASW_Spam_Log::add_entry( 'buy cheap pills', array( 'links' ), 'hashed-ip', 10 );
        $wp_comment_meta[10]['_asw_reason'] = 'Too many links';

        $privacy = new ASW_Privacy();
        $result  = $privacy->export( 'subject@example.com' );

        $this->assertTrue( $result['done'] );
        $this->assertCount( 1, $result['data'] );

        $group = $result['data'][0];
        $this->assertSame( ASW_Privacy::PRIVACY_KEY, $group['group_id'] );
        $this->assertStringStartsWith( ASW_Privacy::PRIVACY_KEY . '-comment-10', $group['item_id'] );

        $values = array_column( $group['data'], 'value', 'name' );
        $this->assertSame( 'buy cheap pills', $values['Comment fragment'] );
        $this->assertSame( 'hashed-ip', $values['IP address (SHA-256 hash)'] );
        $this->assertSame( 'links', $values['Triggered checks'] );
        $this->assertSame( 'Too many links', $values['Reason flagged'] );
    }

    public function test_export_includes_spam_status_comments(): void {
        // The plugin flags spam by returning 'spam' from pre_comment_approved, so a
        // flagged comment is stored with comment_approved = 'spam'. WP_Comment_Query's
        // default 'all' status would exclude it; the exporter must query 'any' so the
        // personal data attached to spam-queue comments is still exportable.
        $this->add_comment( 30, 'subject@example.com', 'spam' );
        ASW_Spam_Log::add_entry( 'buy pills', array( 'links' ), 'hashed-ip', 30 );

        $privacy = new ASW_Privacy();
        $result  = $privacy->export( 'subject@example.com' );

        $this->assertCount( 1, $result['data'] );
        $values = array_column( $result['data'][0]['data'], 'value', 'name' );
        $this->assertSame( 'buy pills', $values['Comment fragment'] );
    }

    public function test_export_excludes_other_subjects(): void {
        $this->add_comment( 1, 'me@example.com' );
        $this->add_comment( 2, 'other@example.com' );
        ASW_Spam_Log::add_entry( 'mine', array( 'links' ), 'h1', 1 );
        ASW_Spam_Log::add_entry( 'theirs', array( 'emoji' ), 'h2', 2 );

        $privacy = new ASW_Privacy();
        $result  = $privacy->export( 'me@example.com' );

        $this->assertCount( 1, $result['data'] );
        $values = array_column( $result['data'][0]['data'], 'value', 'name' );
        $this->assertSame( 'mine', $values['Comment fragment'] );
    }

    public function test_export_empty_for_unknown_email(): void {
        $this->add_comment( 1, 'known@example.com' );
        ASW_Spam_Log::add_entry( 'spam', array( 'links' ), 'h1', 1 );

        $privacy = new ASW_Privacy();
        $result  = $privacy->export( 'nobody@example.com' );

        $this->assertSame( array(), $result['data'] );
        $this->assertTrue( $result['done'] );
    }

    public function test_export_exports_reason_meta_without_log_entry(): void {
        global $wp_comment_meta;

        $this->add_comment( 7, 'subject@example.com' );
        $wp_comment_meta[7]['_asw_reason'] = 'Bayesian classifier';

        $privacy = new ASW_Privacy();
        $result  = $privacy->export( 'subject@example.com' );

        $this->assertCount( 1, $result['data'] );
        $values = array_column( $result['data'][0]['data'], 'value', 'name' );
        $this->assertSame( 'Bayesian classifier', $values['Reason flagged'] );
        $this->assertArrayNotHasKey( 'Comment fragment', $values );
    }

    public function test_export_pagination_not_done_when_page_full(): void {
        // Fill a full page of comments so done => false.
        for ( $i = 1; $i <= ASW_Privacy::COMMENTS_PER_PAGE; $i++ ) {
            $this->add_comment( $i, 'subject@example.com' );
            ASW_Spam_Log::add_entry( "spam $i", array( 'links' ), 'h', $i );
        }

        $privacy = new ASW_Privacy();
        $result  = $privacy->export( 'subject@example.com', 1 );

        $this->assertFalse( $result['done'] );
        $this->assertCount( ASW_Privacy::COMMENTS_PER_PAGE, $result['data'] );
    }

    public function test_export_second_page_returns_overflow_comments(): void {
        // One full page plus 10 overflow comments (51..60).
        $total = ASW_Privacy::COMMENTS_PER_PAGE + 10;
        for ( $i = 1; $i <= $total; $i++ ) {
            $this->add_comment( $i, 'subject@example.com' );
            ASW_Spam_Log::add_entry( "spam $i", array( 'links' ), 'h', $i );
        }

        $privacy = new ASW_Privacy();
        $result  = $privacy->export( 'subject@example.com', 2 );

        // Page 2 holds only the 10 overflow comments and is the final page.
        $this->assertTrue( $result['done'] );
        $this->assertCount( 10, $result['data'] );

        $item_ids = array_column( $result['data'], 'item_id' );
        $found_overflow = false;
        $found_first = false;
        foreach ( $item_ids as $id ) {
            if ( 0 === strpos( $id, ASW_Privacy::PRIVACY_KEY . '-comment-' . ( ASW_Privacy::COMMENTS_PER_PAGE + 1 ) ) ) {
                $found_overflow = true;
            }
            if ( 0 === strpos( $id, ASW_Privacy::PRIVACY_KEY . '-comment-1-' ) || $id === ASW_Privacy::PRIVACY_KEY . '-comment-1' ) {
                $found_first = true;
            }
        }
        $this->assertTrue( $found_overflow );
        $this->assertFalse( $found_first );
    }

    // -- Export: training samples --------------------------------------------

    public function test_export_includes_training_samples_for_subject(): void {
        $this->add_comment( 10, 'subject@example.com', 'spam' );
        $repository = $this->boot_repository();
        $repository->insert( 'latin', 'spam', 'Buy cheap pills now', 10 );

        $privacy = new ASW_Privacy();
        $result  = $privacy->export( 'subject@example.com' );

        $samples = array_values(
            array_filter(
                $result['data'],
                static function ( array $group ): bool {
                    return 0 === strpos( $group['item_id'], ASW_Privacy::PRIVACY_KEY . '-sample-' );
                }
            )
        );

        $this->assertCount( 1, $samples );
        $this->assertSame( ASW_Privacy::PRIVACY_KEY, $samples[0]['group_id'] );

        $values = array_column( $samples[0]['data'], 'value', 'name' );
        // The sample stores the comment text in full, not a fragment.
        $this->assertSame( 'Buy cheap pills now', $values['Training sample text'] );
        $this->assertSame( 'Spam', $values['Training category'] );
        $this->assertNotEmpty( $values['Date added to training data'] );
    }

    public function test_export_renders_the_ham_training_category(): void {
        $this->add_comment( 11, 'subject@example.com' );
        $repository = $this->boot_repository();
        $repository->insert( 'latin', 'ham', 'A perfectly normal comment', 11 );

        $privacy = new ASW_Privacy();
        $result  = $privacy->export( 'subject@example.com' );

        $this->assertCount( 1, $result['data'] );
        $values = array_column( $result['data'][0]['data'], 'value', 'name' );
        $this->assertSame( 'Ham', $values['Training category'] );
    }

    public function test_export_excludes_training_samples_of_other_subjects(): void {
        $this->add_comment( 10, 'me@example.com' );
        $this->add_comment( 20, 'other@example.com' );
        $repository = $this->boot_repository();
        $repository->insert( 'latin', 'spam', 'Mine', 10 );
        $repository->insert( 'latin', 'spam', 'Theirs', 20 );

        $privacy = new ASW_Privacy();
        $result  = $privacy->export( 'me@example.com' );

        $texts = array();
        foreach ( $result['data'] as $group ) {
            $values = array_column( $group['data'], 'value', 'name' );
            if ( isset( $values['Training sample text'] ) ) {
                $texts[] = $values['Training sample text'];
            }
        }

        $this->assertSame( array( 'Mine' ), $texts );
    }

    public function test_export_has_no_sample_item_for_an_untrained_subject(): void {
        $this->add_comment( 10, 'subject@example.com' );
        ASW_Spam_Log::add_entry( 'buy pills', array( 'links' ), 'hashed-ip', 10 );
        $this->boot_repository();

        $privacy = new ASW_Privacy();
        $result  = $privacy->export( 'subject@example.com' );

        $this->assertCount( 1, $result['data'] );
        $this->assertSame(
            ASW_Privacy::PRIVACY_KEY . '-comment-10',
            substr( $result['data'][0]['item_id'], 0, strlen( ASW_Privacy::PRIVACY_KEY . '-comment-10' ) )
        );
    }

    public function test_export_sample_item_ids_do_not_collide_with_comment_or_form_items(): void {
        $email = 'subject@example.com';
        $this->add_comment( 10, $email, 'spam' );
        ASW_Spam_Log::add_entry( 'buy pills', array( 'links' ), 'hashed-ip', 10 );
        ASW_Spam_Log::add_entry(
            'form spam',
            array( 'links' ),
            'hashed-ip',
            0,
            'form',
            ASW_Detector::hash_email( $email )
        );
        $repository = $this->boot_repository();
        $repository->insert( 'latin', 'spam', 'Buy cheap pills now', 10 );

        $privacy = new ASW_Privacy();
        $result  = $privacy->export( $email );

        $item_ids = array_column( $result['data'], 'item_id' );
        $this->assertCount( 3, $item_ids );
        $this->assertSame( $item_ids, array_unique( $item_ids ) );

        $prefixed = static function ( string $prefix ) use ( $item_ids ): array {
            return array_values(
                array_filter(
                    $item_ids,
                    static function ( string $id ) use ( $prefix ): bool {
                        return 0 === strpos( $id, $prefix );
                    }
                )
            );
        };

        $this->assertCount( 1, $prefixed( ASW_Privacy::PRIVACY_KEY . '-comment-' ) );
        $this->assertCount( 1, $prefixed( ASW_Privacy::PRIVACY_KEY . '-form-' ) );
        $this->assertCount( 1, $prefixed( ASW_Privacy::PRIVACY_KEY . '-sample-' ) );
    }

    public function test_export_gives_each_sample_its_own_item_id_from_the_row_id(): void {
        $email = 'subject@example.com';
        $this->add_comment( 10, $email, 'spam' );
        $repository = $this->boot_repository();
        $repository->insert( 'latin', 'spam', 'First trained text', 10 );
        $repository->insert( 'latin', 'ham', 'Second trained text', 10 );

        $rows = $repository->find_by_comment_ids( array( 10 ) );
        $this->assertCount( 2, $rows );

        $privacy = new ASW_Privacy();
        $result  = $privacy->export( $email );

        $item_ids = array_column( $result['data'], 'item_id' );
        $this->assertCount( 2, $item_ids );
        // Distinct ids matter: WordPress merges export groups that share an
        // item_id, so a constant suffix would silently drop every sample but one.
        $this->assertSame( $item_ids, array_unique( $item_ids ) );
        $this->assertSame(
            array(
                ASW_Privacy::PRIVACY_KEY . '-sample-' . (int) $rows[0]['id'],
                ASW_Privacy::PRIVACY_KEY . '-sample-' . (int) $rows[1]['id'],
            ),
            $item_ids
        );

        $texts = array();
        foreach ( $result['data'] as $group ) {
            $values  = array_column( $group['data'], 'value', 'name' );
            $texts[] = $values['Training sample text'];
        }
        $this->assertSame( array( 'First trained text', 'Second trained text' ), $texts );
    }

    public function test_export_works_when_the_repository_is_null(): void {
        // The singleton is reset in setUp() and never initialized here, so
        // get_repository() returns null; the exporter must skip samples silently.
        $this->add_comment( 10, 'subject@example.com' );
        ASW_Spam_Log::add_entry( 'buy pills', array( 'links' ), 'hashed-ip', 10 );

        $this->assertNull( ASW::get_instance()->get_repository() );

        $privacy = new ASW_Privacy();
        $result  = $privacy->export( 'subject@example.com' );

        $this->assertTrue( $result['done'] );
        $this->assertCount( 1, $result['data'] );
        $values = array_column( $result['data'][0]['data'], 'value', 'name' );
        $this->assertSame( 'buy pills', $values['Comment fragment'] );
    }

    public function test_export_second_page_carries_only_that_pages_samples(): void {
        $total      = ASW_Privacy::COMMENTS_PER_PAGE + 10;
        $repository = $this->boot_repository();
        for ( $i = 1; $i <= $total; $i++ ) {
            $this->add_comment( $i, 'subject@example.com' );
            $repository->insert( 'latin', 'spam', "sample $i", $i );
        }

        $privacy = new ASW_Privacy();
        $result  = $privacy->export( 'subject@example.com', 2 );

        $texts = array();
        foreach ( $result['data'] as $group ) {
            $values = array_column( $group['data'], 'value', 'name' );
            $texts[] = $values['Training sample text'];
        }

        $this->assertCount( 10, $texts );
        $this->assertContains( 'sample ' . ( ASW_Privacy::COMMENTS_PER_PAGE + 1 ), $texts );
        $this->assertNotContains( 'sample 1', $texts );
    }

    // -- Erase ---------------------------------------------------------------

    public function test_erase_removes_log_entries_and_meta_for_subject(): void {
        global $wp_comment_meta;

        $this->add_comment( 10, 'subject@example.com' );
        $this->add_comment( 20, 'other@example.com' );
        ASW_Spam_Log::add_entry( 'mine', array( 'links' ), 'h1', 10 );
        ASW_Spam_Log::add_entry( 'theirs', array( 'emoji' ), 'h2', 20 );
        $wp_comment_meta[10]['_asw_reason'] = 'Too many links';
        $wp_comment_meta[20]['_asw_reason'] = 'Emoji spam';

        $privacy = new ASW_Privacy();
        $result  = $privacy->erase( 'subject@example.com' );

        $this->assertTrue( $result['items_removed'] );
        $this->assertFalse( $result['items_retained'] );
        $this->assertTrue( $result['done'] );
        $this->assertNotEmpty( $result['messages'] );

        // Subject's data gone.
        $this->assertSame( array(), ASW_Spam_Log::get_entries_by_comment_ids( array( 10 ) ) );
        $this->assertArrayNotHasKey( '_asw_reason', $wp_comment_meta[10] ?? array() );

        // Other subject untouched.
        $this->assertCount( 1, ASW_Spam_Log::get_entries_by_comment_ids( array( 20 ) ) );
        $this->assertSame( 'Emoji spam', $wp_comment_meta[20]['_asw_reason'] );
    }

    public function test_erase_includes_spam_status_comments(): void {
        // A flagged comment lives in the spam queue (comment_approved = 'spam');
        // the eraser must still reach and remove its personal data.
        $this->add_comment( 30, 'subject@example.com', 'spam' );
        ASW_Spam_Log::add_entry( 'buy pills', array( 'links' ), 'hashed-ip', 30 );

        $privacy = new ASW_Privacy();
        $result  = $privacy->erase( 'subject@example.com' );

        $this->assertTrue( $result['items_removed'] );
        $this->assertSame( array(), ASW_Spam_Log::get_entries_by_comment_ids( array( 30 ) ) );
    }

    public function test_erase_is_idempotent(): void {
        $this->add_comment( 10, 'subject@example.com' );
        ASW_Spam_Log::add_entry( 'mine', array( 'links' ), 'h1', 10 );

        $privacy = new ASW_Privacy();
        $first   = $privacy->erase( 'subject@example.com' );
        $this->assertTrue( $first['items_removed'] );

        $second = $privacy->erase( 'subject@example.com' );
        $this->assertFalse( $second['items_removed'] );
        $this->assertSame( array(), $second['messages'] );
        $this->assertTrue( $second['done'] );
    }

    public function test_erase_second_page_only_removes_overflow_comments(): void {
        $total = ASW_Privacy::COMMENTS_PER_PAGE + 10;
        for ( $i = 1; $i <= $total; $i++ ) {
            $this->add_comment( $i, 'subject@example.com' );
            ASW_Spam_Log::add_entry( "spam $i", array( 'links' ), 'h', $i );
        }

        $privacy = new ASW_Privacy();
        $result  = $privacy->erase( 'subject@example.com', 2 );

        $this->assertTrue( $result['items_removed'] );
        $this->assertTrue( $result['done'] );

        // An overflow comment (page 2) is erased; a first-page comment is retained.
        $overflow = ASW_Privacy::COMMENTS_PER_PAGE + 1;
        $this->assertSame( array(), ASW_Spam_Log::get_entries_by_comment_ids( array( $overflow ) ) );
        $this->assertCount( 1, ASW_Spam_Log::get_entries_by_comment_ids( array( 1 ) ) );
    }

    public function test_erase_unknown_email_removes_nothing(): void {
        $this->add_comment( 1, 'known@example.com' );
        ASW_Spam_Log::add_entry( 'spam', array( 'links' ), 'h1', 1 );

        $privacy = new ASW_Privacy();
        $result  = $privacy->erase( 'nobody@example.com' );

        $this->assertFalse( $result['items_removed'] );
        $this->assertCount( 1, ASW_Spam_Log::get_entries_by_comment_ids( array( 1 ) ) );
    }

    // -- Erase: training samples ---------------------------------------------

    public function test_erase_removes_training_samples_for_subject(): void {
        $this->add_comment( 10, 'subject@example.com', 'spam' );
        $this->add_comment( 20, 'other@example.com', 'spam' );
        $repository = $this->boot_repository();
        $repository->insert( 'latin', 'spam', 'Buy cheap pills now', 10 );
        $repository->insert( 'latin', 'spam', 'Someone else', 20 );

        $privacy = new ASW_Privacy();
        $result  = $privacy->erase( 'subject@example.com' );

        $this->assertTrue( $result['items_removed'] );
        $this->assertFalse( $result['items_retained'] );
        $this->assertTrue( $result['done'] );
        $this->assertSame(
            array( 'AntiSpam Wizard spam-log entries, flagging reasons, form submissions, and training samples were removed.' ),
            $result['messages']
        );

        $this->assertSame( array(), $repository->find_by_comment_ids( array( 10 ) ) );
        // Another subject's sample is untouched.
        $this->assertCount( 1, $repository->find_by_comment_ids( array( 20 ) ) );
    }

    public function test_erase_counts_a_sample_as_removed_on_its_own(): void {
        // No log entry and no reason meta: the sample must be what flips
        // items_removed, not a side effect of the other two removals.
        $this->add_comment( 10, 'subject@example.com', 'spam' );
        $repository = $this->boot_repository();
        $repository->insert( 'latin', 'spam', 'Buy cheap pills now', 10 );

        $privacy = new ASW_Privacy();
        $result  = $privacy->erase( 'subject@example.com' );

        $this->assertTrue( $result['items_removed'] );
        $this->assertNotEmpty( $result['messages'] );
        $this->assertSame( array(), $repository->find_all( 'latin', 'spam' ) );
    }

    public function test_erase_bumps_the_sample_revision_when_a_sample_is_removed(): void {
        $this->add_comment( 10, 'subject@example.com', 'spam' );
        $repository = $this->boot_repository();
        $repository->insert( 'latin', 'spam', 'Buy cheap pills now', 10 );
        $before = $repository->latest_revision();

        $privacy = new ASW_Privacy();
        $privacy->erase( 'subject@example.com' );

        // The cached classifier is keyed on the revision, so it must be rebuilt
        // without the erased text.
        $this->assertGreaterThan( $before, $repository->latest_revision() );
    }

    public function test_erase_purges_the_model_units_cached_from_the_erased_sample(): void {
        global $wp_transients, $wp_options;

        $this->add_comment( 10, 'subject@example.com', 'spam' );
        $repository = $this->boot_repository();
        $repository->insert( 'latin', 'spam', 'Buy cheap pills now', 10 );

        // A cached model unit carries the trained comment's text as a token
        // list, so an erasure that reported success while the cache still held
        // it would leave a readable copy in wp_options for the rest of that
        // unit's 24-hour life.
        $wp_transients = array();
        $stale         = ASW_Model_Store::unit_keys();
        foreach ( $stale as $key ) {
            set_transient(
                $key,
                array(
                    'fingerprint' => 'whatever',
                    'classifier'  => null,
                    'samples'     => array( array( 'buy', 'cheap', 'pills' ) ),
                ),
                DAY_IN_SECONDS
            );
        }

        $privacy = new ASW_Privacy();
        $privacy->erase( 'subject@example.com' );

        foreach ( $stale as $key ) {
            $this->assertFalse( get_transient( $key ), "A model unit built from the erased sample survived the erasure: {$key}" );
            $this->assertArrayNotHasKey( '_transient_' . $key, $wp_options, "The erased unit's option row survived: {$key}" );
        }

        $wp_transients = array();
    }

    public function test_erase_purges_a_unit_cached_before_an_unrelated_training_write(): void {
        global $wp_transients;

        // The unit is cached first, and an unrelated sample is trained
        // afterwards -- so by the time the erasure runs, the invalidation
        // fingerprint has moved on from the one the cached unit was built
        // under. While the fingerprint lived in the *key*, that rebuild wrote a
        // second row beside the first instead of overwriting it, and the eraser
        // -- which can only name the keys it can compute -- purged the newer row
        // while the older one went on holding the subject's comment text as a
        // token list until its TTL ran out. The key names the unit now, so there
        // is exactly one row per unit and no generation to miss.
        $this->add_comment( 10, 'subject@example.com', 'spam' );
        $repository = $this->boot_repository();
        $repository->insert( 'latin', 'spam', 'Buy cheap pills now', 10 );

        $store = $this->make_model_store( $repository );
        $store->script_classifier( 'latin' );
        $store->combined_classifier();

        $cached = $this->cached_unit_option_keys();
        $this->assertNotEmpty( $cached, 'Premise: the subject\'s sample must be in a cached unit' );

        // Somebody else's comment is trained, moving every invalidation input
        // this unit is stamped with.
        $repository->insert( 'latin', 'spam', 'Totally unrelated other body', 99 );
        $this->make_model_store( $repository )->script_classifier( 'latin' );

        // The rebuilt unit replaced the one it invalidated rather than being
        // written beside it: one row per unit, always.
        $this->assertSame(
            $cached,
            $this->cached_unit_option_keys(),
            'The rebuild orphaned the invalidated unit instead of overwriting it'
        );

        $privacy = new ASW_Privacy();
        $privacy->erase( 'subject@example.com' );

        $this->assertSame(
            array(),
            $this->cached_unit_option_keys(),
            'A model unit cached before an unrelated training write survived the erasure'
        );

        $wp_transients = array();
    }

    /**
     * A model store over the shipped corpus and the given repository, built the
     * way ASW::build_checks() builds it.
     *
     * @param ASW_Sample_Repository $repository Sample repository.
     */
    private function make_model_store( ASW_Sample_Repository $repository ): ASW_Model_Store {
        return new ASW_Model_Store(
            new ASW_Tokenizer( array() ),
            array(),
            new ASW_Training_Data( $repository ),
            $repository
        );
    }

    /**
     * Every `_transient_asw_classifiers_*` option row currently stored.
     *
     * Read off the option rows rather than the keys the store would compute, so
     * a generation the store can no longer name still shows up.
     *
     * @return string[]
     */
    private function cached_unit_option_keys(): array {
        global $wp_options;

        $keys = array_filter(
            array_keys( $wp_options ),
            static function ( $key ) {
                return str_starts_with( $key, '_transient_' . ASW_Model_Store::TRANSIENT_PREFIX );
            }
        );
        sort( $keys );

        return $keys;
    }

    public function test_erase_retains_items_when_the_model_cache_purge_fails(): void {
        global $wpdb, $wp_transients;

        // Training text the store could not remove stays readable in
        // wp_options. Closing the request over that is the same loss as a
        // refused DELETE, so it is reported the same way. No repository is
        // booted here, so the purge is the only thing that can set the flag --
        // and the database refusing the store's prefix sweep is what makes it
        // report a retention.
        $this->add_comment( 10, 'subject@example.com', 'spam' );

        $wp_transients = array();
        set_transient(
            ASW_Model_Store::unit_keys()[0],
            array(
                'fingerprint' => 'whatever',
                'classifier'  => null,
                'samples'     => array( array( 'buy', 'cheap', 'pills' ) ),
            ),
            DAY_IN_SECONDS
        );

        $wpdb->query_result = false;

        $log = $this->capture_error_log(
            function () {
                $privacy = new ASW_Privacy();
                $result  = $privacy->erase( 'subject@example.com' );

                $this->assertTrue( $result['items_retained'], 'A refused cache purge must not close the erasure request' );
                $this->assertNotEmpty( $result['messages'] );
            }
        );

        $wpdb->query_result = null;
        $wp_transients      = array();

        $this->assertStringContainsString( 'model cache purge retained a unit', $log );
    }

    /**
     * Run a callback with error_log() redirected to a temporary file.
     *
     * @param callable $callback The code under test.
     * @return string Whatever it logged.
     */
    private function capture_error_log( callable $callback ): string {
        $tmp     = tempnam( sys_get_temp_dir(), 'asw_test_' );
        $old_log = ini_set( 'error_log', $tmp );

        try {
            $callback();
        } finally {
            ini_set( 'error_log', $old_log );
        }

        $logged = (string) file_get_contents( $tmp );
        unlink( $tmp );

        return $logged;
    }

    public function test_a_retried_erasure_purges_units_the_first_attempt_could_not_drop(): void {
        global $wp_transients, $wp_transient_delete_fails;

        // The message a refused purge emits asks the site to retry the request.
        // That retry deletes no sample -- the rows went the first time -- so a
        // purge gated on this request's own deletions would be skipped and the
        // retry would close clean over a unit the first attempt could not drop.
        // Page 1 therefore purges whatever it deleted, which needs no durable
        // record of the first failure -- and so cannot be defeated by the same
        // option store refusing to write one.
        $this->add_comment( 10, 'subject@example.com', 'spam' );
        $repository = $this->boot_repository();
        $repository->insert( 'latin', 'spam', 'Buy cheap pills now', 10 );

        $wp_transients = array();
        $key           = ASW_Model_Store::unit_keys()[0];
        set_transient(
            $key,
            array(
                'fingerprint' => 'whatever',
                'classifier'  => null,
                'samples'     => array( array( 'buy', 'cheap', 'pills' ) ),
            ),
            DAY_IN_SECONDS
        );

        $wp_transient_delete_fails = array( $key );

        try {
            $privacy = new ASW_Privacy();
            $privacy->erase( 'subject@example.com' );
        } finally {
            $wp_transient_delete_fails = false;
        }

        $this->assertIsArray( get_transient( $key ), 'Premise: the unit outlived the first request' );
        $this->assertSame(
            array(),
            $repository->find_by_comment_ids( array( 10 ) ),
            'Premise: the retry has no sample row left to delete'
        );

        $retry = ( new ASW_Privacy() )->erase( 'subject@example.com' );

        $this->assertFalse( get_transient( $key ), 'The retry must finish the purge the first request could not' );
        $this->assertFalse( $retry['items_retained'] );

        $wp_transients = array();
    }

    public function test_erase_does_not_retain_items_when_the_cache_purge_succeeds(): void {
        global $wp_transients;

        // Ten of the eleven keys are absent on any real request, and
        // delete_transient() answers false for every one of them -- reading that
        // as a failed purge would mark every erasure as retaining data.
        $this->add_comment( 10, 'subject@example.com', 'spam' );
        $repository = $this->boot_repository();
        $repository->insert( 'latin', 'spam', 'Buy cheap pills now', 10 );

        $wp_transients = array();
        set_transient(
            ASW_Model_Store::unit_keys()[0],
            array( 'fingerprint' => 'whatever', 'classifier' => null, 'samples' => array() ),
            DAY_IN_SECONDS
        );

        $privacy = new ASW_Privacy();
        $result  = $privacy->erase( 'subject@example.com' );

        $this->assertTrue( $result['items_removed'] );
        $this->assertFalse( $result['items_retained'] );

        $wp_transients = array();
    }

    public function test_erase_purges_the_model_cache_on_page_one_whatever_it_removed(): void {
        global $wp_transients;

        // Page 1 purges even when this request removed no sample, because that
        // is exactly what the retry a refused purge asks for looks like: its
        // rows went with the first attempt. The cost is one model-cache flush
        // per erasure request -- a rare, admin-approved event -- paid by the
        // next comment in each script as a cold rebuild. Later pages leave the
        // cache alone unless they deleted something, so one paginated request
        // flushes once.
        $this->add_comment( 10, 'subject@example.com', 'spam' );
        $this->boot_repository();

        $wp_transients = array();
        $key           = ASW_Model_Store::unit_keys()[0];
        $unit          = array( 'fingerprint' => 'whatever', 'classifier' => null, 'samples' => array() );

        set_transient( $key, $unit, DAY_IN_SECONDS );
        ( new ASW_Privacy() )->erase( 'subject@example.com', 2 );
        $this->assertIsArray( get_transient( $key ), 'A later page that removed nothing must keep the cached units' );

        ( new ASW_Privacy() )->erase( 'subject@example.com' );
        $this->assertFalse( get_transient( $key ), 'Page 1 must purge so the retry the message asks for reaches the cache' );

        $wp_transients = array();
    }

    public function test_erase_without_samples_behaves_as_before(): void {
        global $wp_comment_meta;

        $this->add_comment( 10, 'subject@example.com' );
        ASW_Spam_Log::add_entry( 'mine', array( 'links' ), 'h1', 10 );
        $wp_comment_meta[10]['_asw_reason'] = 'Too many links';
        $repository = $this->boot_repository();
        $before     = $repository->latest_revision();

        $privacy = new ASW_Privacy();
        $result  = $privacy->erase( 'subject@example.com' );

        $this->assertTrue( $result['items_removed'] );
        $this->assertFalse( $result['items_retained'] );
        $this->assertTrue( $result['done'] );
        $this->assertSame( array(), ASW_Spam_Log::get_entries_by_comment_ids( array( 10 ) ) );
        $this->assertArrayNotHasKey( '_asw_reason', $wp_comment_meta[10] ?? array() );
        // Nothing was deleted from the samples table, so the revision is untouched.
        $this->assertSame( $before, $repository->latest_revision() );
    }

    public function test_erase_of_samples_is_idempotent(): void {
        $this->add_comment( 10, 'subject@example.com', 'spam' );
        $repository = $this->boot_repository();
        $repository->insert( 'latin', 'spam', 'Buy cheap pills now', 10 );

        $privacy = new ASW_Privacy();
        $first   = $privacy->erase( 'subject@example.com' );
        $this->assertTrue( $first['items_removed'] );

        $second = $privacy->erase( 'subject@example.com' );
        $this->assertFalse( $second['items_removed'] );
        $this->assertSame( array(), $second['messages'] );
        $this->assertTrue( $second['done'] );
    }

    public function test_erase_works_when_the_repository_is_null(): void {
        // The singleton is reset in setUp() and never initialized here, so
        // get_repository() returns null; the eraser must skip samples silently.
        $this->add_comment( 10, 'subject@example.com' );
        ASW_Spam_Log::add_entry( 'buy pills', array( 'links' ), 'hashed-ip', 10 );

        $this->assertNull( ASW::get_instance()->get_repository() );

        $privacy = new ASW_Privacy();
        $result  = $privacy->erase( 'subject@example.com' );

        $this->assertTrue( $result['items_removed'] );
        $this->assertTrue( $result['done'] );
        $this->assertSame( array(), ASW_Spam_Log::get_entries_by_comment_ids( array( 10 ) ) );
    }

    public function test_erase_second_page_only_removes_overflow_samples(): void {
        $total      = ASW_Privacy::COMMENTS_PER_PAGE + 10;
        $repository = $this->boot_repository();
        for ( $i = 1; $i <= $total; $i++ ) {
            $this->add_comment( $i, 'subject@example.com' );
            $repository->insert( 'latin', 'spam', "sample $i", $i );
        }

        $privacy = new ASW_Privacy();
        $result  = $privacy->erase( 'subject@example.com', 2 );

        $this->assertTrue( $result['items_removed'] );
        $overflow = ASW_Privacy::COMMENTS_PER_PAGE + 1;
        $this->assertSame( array(), $repository->find_by_comment_ids( array( $overflow ) ) );
        $this->assertCount( 1, $repository->find_by_comment_ids( array( 1 ) ) );
    }

    // -- Samples reachable after the comment is gone --------------------------

    public function test_export_includes_a_sample_whose_comment_was_deleted(): void {
        // Emptying the spam queue hard-deletes the comment the sample was trained
        // from. comment_id then resolves to nothing, while the row still holds the
        // full comment text -- the email hash is what keeps it discoverable.
        $repository = $this->boot_repository();
        $repository->insert( 'latin', 'spam', 'Buy cheap pills now', 10, ASW_Detector::hash_email( 'subject@example.com' ) );

        // No comment fixture at all: core finds none for this address.
        $privacy = new ASW_Privacy();
        $result  = $privacy->export( 'subject@example.com' );

        $this->assertCount( 1, $result['data'] );
        $values = array_column( $result['data'][0]['data'], 'value', 'name' );
        $this->assertSame( 'Buy cheap pills now', $values['Training sample text'] );
    }

    public function test_export_does_not_duplicate_a_sample_reachable_by_both_keys(): void {
        $this->add_comment( 10, 'subject@example.com', 'spam' );
        $repository = $this->boot_repository();
        $repository->insert( 'latin', 'spam', 'Buy cheap pills now', 10, ASW_Detector::hash_email( 'subject@example.com' ) );

        $privacy = new ASW_Privacy();
        $result  = $privacy->export( 'subject@example.com' );

        $samples = array_filter(
            $result['data'],
            static function ( $group ) {
                return 0 === strpos( $group['item_id'], ASW_Privacy::PRIVACY_KEY . '-sample-' );
            }
        );
        $this->assertCount( 1, $samples );
    }

    public function test_export_excludes_a_deleted_comments_sample_of_another_subject(): void {
        $repository = $this->boot_repository();
        $repository->insert( 'latin', 'spam', 'Mine', 10, ASW_Detector::hash_email( 'subject@example.com' ) );
        $repository->insert( 'latin', 'spam', 'Theirs', 20, ASW_Detector::hash_email( 'other@example.com' ) );

        $privacy = new ASW_Privacy();
        $result  = $privacy->export( 'subject@example.com' );

        $texts = array();
        foreach ( $result['data'] as $group ) {
            $values  = array_column( $group['data'], 'value', 'name' );
            $texts[] = $values['Training sample text'];
        }
        $this->assertSame( array( 'Mine' ), $texts );
    }

    public function test_export_queries_samples_by_hash_on_page_one_only(): void {
        // Hash-keyed rows are not paginated, so emitting them on every page would
        // repeat them once per page of comments.
        $repository = $this->boot_repository();
        $repository->insert( 'latin', 'spam', 'Orphaned sample', 10, ASW_Detector::hash_email( 'subject@example.com' ) );

        $privacy = new ASW_Privacy();

        $this->assertCount( 1, $privacy->export( 'subject@example.com', 1 )['data'] );
        $this->assertSame( array(), $privacy->export( 'subject@example.com', 2 )['data'] );
    }

    public function test_export_does_not_repeat_a_hash_matched_sample_on_a_later_comment_page(): void {
        // The two sample lookups paginate differently: comment_id follows the
        // subject's comment pages, the hash runs once on page 1. A sample trained
        // from a comment on page 2 is therefore returned by the hash query on
        // page 1 and by the comment query on page 2 -- and WordPress merges export
        // groups sharing an item_id, so the full comment text would appear twice
        // inside one item. Every real sample carries the hash (ajax_mark_comment()
        // always passes it), so this is the ordinary case, not an edge case.
        $total      = ASW_Privacy::COMMENTS_PER_PAGE + 10;
        $hash       = ASW_Detector::hash_email( 'subject@example.com' );
        $repository = $this->boot_repository();
        for ( $i = 1; $i <= $total; $i++ ) {
            $this->add_comment( $i, 'subject@example.com' );
        }
        $overflow = ASW_Privacy::COMMENTS_PER_PAGE + 1;
        $repository->insert( 'latin', 'spam', 'first page sample', 1, $hash );
        $repository->insert( 'latin', 'spam', 'overflow sample', $overflow, $hash );
        // A row that predates the email_hash column: reachable by comment_id only,
        // so it must still be exported on the page its comment falls on.
        $repository->insert( 'latin', 'spam', 'legacy sample', $overflow + 1 );

        $privacy = new ASW_Privacy();

        $texts = function ( int $page ) use ( $privacy ) {
            $out = array();
            foreach ( $privacy->export( 'subject@example.com', $page )['data'] as $group ) {
                if ( 0 !== strpos( $group['item_id'], ASW_Privacy::PRIVACY_KEY . '-sample-' ) ) {
                    continue;
                }
                $values = array_column( $group['data'], 'value', 'name' );
                $out[]  = $values['Training sample text'];
            }
            return $out;
        };

        // Each row is emitted on exactly one page: the hash pass withholds a row
        // whose comment the comment-keyed pass will claim on a later page.
        $this->assertSame( array( 'first page sample' ), $texts( 1 ) );
        $this->assertSame( array( 'overflow sample', 'legacy sample' ), $texts( 2 ) );
        $across = array_merge( $texts( 1 ), $texts( 2 ) );
        $this->assertSame( $across, array_unique( $across ) );
        sort( $across );
        $this->assertSame( array( 'first page sample', 'legacy sample', 'overflow sample' ), $across );
    }

    public function test_export_emits_a_hash_matched_sample_on_a_later_page_when_the_page_one_hash_lookup_failed(): void {
        // The cross-page split must not depend on what another page's hash query
        // returned. A hash read that fails on page 1 and succeeds on page 2 is
        // the case a row-ID-based skip cannot survive: page 2 would recognise the
        // row as hash-matched and skip it, while page 1 -- whose hash query came
        // back empty -- never emitted it, so the full comment text would appear
        // on no page at all in a report still marked done.
        $total      = ASW_Privacy::COMMENTS_PER_PAGE + 10;
        $hash       = ASW_Detector::hash_email( 'subject@example.com' );
        $repository = $this->boot_repository();
        for ( $i = 1; $i <= $total; $i++ ) {
            $this->add_comment( $i, 'subject@example.com' );
        }
        $overflow = ASW_Privacy::COMMENTS_PER_PAGE + 1;
        $repository->insert( 'latin', 'spam', 'overflow sample', $overflow, $hash );

        $privacy = new ASW_Privacy();
        $texts   = function ( int $page ) use ( $privacy ) {
            $out = array();
            foreach ( $privacy->export( 'subject@example.com', $page )['data'] as $group ) {
                if ( 0 !== strpos( $group['item_id'], ASW_Privacy::PRIVACY_KEY . '-sample-' ) ) {
                    continue;
                }
                $values = array_column( $group['data'], 'value', 'name' );
                $out[]  = $values['Training sample text'];
            }
            return $out;
        };

        // Page 1 with a hash query that comes back empty, as find_by_email_hash()
        // reports a failed read.
        $slot = new ReflectionProperty( ASW::class, 'repository' );
        $slot->setValue( ASW::get_instance(), new ASW_Hash_Blind_Sample_Repository( $GLOBALS['wpdb'] ) );
        $this->assertSame( array(), $texts( 1 ) );

        // Page 2 with the hash query working again.
        $slot->setValue( ASW::get_instance(), $repository );
        $this->assertSame( array( 'overflow sample' ), $texts( 2 ) );
    }

    public function test_export_still_emits_a_hash_matched_sample_when_the_hash_lookup_returns_nothing(): void {
        // On page 1 both sample lookups run and the row index already collapses
        // the duplicate, so the comment-keyed pass must not skip a hash-matched
        // row there. If it did, the row's presence in the export would hang on
        // the hash query succeeding -- and find_by_email_hash() reports a failed
        // read as an empty array, so a database hiccup would silently drop every
        // hash-carrying sample from a report still marked done.
        $hash       = ASW_Detector::hash_email( 'subject@example.com' );
        $repository = $this->boot_repository();
        $this->add_comment( 10, 'subject@example.com', 'spam' );
        $repository->insert( 'latin', 'spam', 'Buy cheap pills now', 10, $hash );

        // Stand in for a hash query that comes back empty while the comment query
        // succeeds -- what find_by_email_hash() does on a failed read, since it
        // collapses get_results()' null into an empty array.
        $blind = new ASW_Hash_Blind_Sample_Repository( $GLOBALS['wpdb'] );
        $slot  = new ReflectionProperty( ASW::class, 'repository' );
        $slot->setValue( ASW::get_instance(), $blind );

        $privacy = new ASW_Privacy();
        $texts   = array();
        foreach ( $privacy->export( 'subject@example.com', 1 )['data'] as $group ) {
            if ( 0 !== strpos( $group['item_id'], ASW_Privacy::PRIVACY_KEY . '-sample-' ) ) {
                continue;
            }
            $values  = array_column( $group['data'], 'value', 'name' );
            $texts[] = $values['Training sample text'];
        }

        $this->assertSame( array( 'Buy cheap pills now' ), $texts );
    }

    public function test_export_still_emits_a_hash_matched_sample_on_a_later_page_when_the_hash_lookup_returns_nothing(): void {
        // The page-2 skip must be driven by the row IDs the hash query actually
        // returned, not by comparing the row's email_hash column. A hash
        // comparison would skip the row here while page 1 -- whose hash query
        // came back empty -- never emitted it, so the full comment text would
        // appear on no page at all while the report still says done. Losing the
        // duplicate suppression on a failed read is the cheaper failure:
        // WordPress merges groups sharing an item_id.
        $total      = ASW_Privacy::COMMENTS_PER_PAGE + 10;
        $hash       = ASW_Detector::hash_email( 'subject@example.com' );
        $repository = $this->boot_repository();
        for ( $i = 1; $i <= $total; $i++ ) {
            $this->add_comment( $i, 'subject@example.com' );
        }
        $overflow = ASW_Privacy::COMMENTS_PER_PAGE + 1;
        $repository->insert( 'latin', 'spam', 'overflow sample', $overflow, $hash );

        $blind = new ASW_Hash_Blind_Sample_Repository( $GLOBALS['wpdb'] );
        $slot  = new ReflectionProperty( ASW::class, 'repository' );
        $slot->setValue( ASW::get_instance(), $blind );

        $privacy = new ASW_Privacy();
        $texts   = function ( int $page ) use ( $privacy ) {
            $out = array();
            foreach ( $privacy->export( 'subject@example.com', $page )['data'] as $group ) {
                if ( 0 !== strpos( $group['item_id'], ASW_Privacy::PRIVACY_KEY . '-sample-' ) ) {
                    continue;
                }
                $values = array_column( $group['data'], 'value', 'name' );
                $out[]  = $values['Training sample text'];
            }
            return $out;
        };

        $this->assertSame( array(), $texts( 1 ) );
        $this->assertSame( array( 'overflow sample' ), $texts( 2 ) );
    }

    public function test_export_treats_page_zero_as_page_one_for_hash_keyed_data(): void {
        // get_comment_ids() clamps the page with max( 1, $page ) while the
        // hash-keyed lookups run on page 1 only. The two notions of "page" must
        // agree, or a page of 0 exports the comment-keyed data while silently
        // dropping every hash-keyed row -- and still reports the request done.
        $hash       = ASW_Detector::hash_email( 'subject@example.com' );
        $repository = $this->boot_repository();
        $repository->insert( 'latin', 'spam', 'orphaned sample', 10, $hash );

        $privacy = new ASW_Privacy();
        $texts   = array();
        foreach ( $privacy->export( 'subject@example.com', 0 )['data'] as $group ) {
            if ( 0 !== strpos( $group['item_id'], ASW_Privacy::PRIVACY_KEY . '-sample-' ) ) {
                continue;
            }
            $values  = array_column( $group['data'], 'value', 'name' );
            $texts[] = $values['Training sample text'];
        }

        $this->assertSame( array( 'orphaned sample' ), $texts );
    }

    public function test_erase_treats_page_zero_as_page_one_for_hash_keyed_data(): void {
        $hash       = ASW_Detector::hash_email( 'subject@example.com' );
        $repository = $this->boot_repository();
        $repository->insert( 'latin', 'spam', 'orphaned sample', 10, $hash );

        $privacy = new ASW_Privacy();
        $result  = $privacy->erase( 'subject@example.com', 0 );

        $this->assertTrue( $result['items_removed'] );
        $this->assertSame( array(), $repository->find_by_email_hash( $hash ) );
    }

    public function test_erase_removes_a_sample_whose_comment_was_deleted(): void {
        $repository = $this->boot_repository();
        $hash       = ASW_Detector::hash_email( 'subject@example.com' );
        $repository->insert( 'latin', 'spam', 'Buy cheap pills now', 10, $hash );
        $repository->insert( 'latin', 'spam', 'Someone else', 20, ASW_Detector::hash_email( 'other@example.com' ) );

        $privacy = new ASW_Privacy();
        $result  = $privacy->erase( 'subject@example.com' );

        $this->assertTrue( $result['items_removed'] );
        $this->assertFalse( $result['items_retained'] );
        $this->assertSame( array(), $repository->find_by_email_hash( $hash ) );
        $this->assertCount( 1, $repository->find_by_email_hash( ASW_Detector::hash_email( 'other@example.com' ) ) );
    }

    // -- Erase: failed sample deletion ---------------------------------------

    public function test_erase_retains_items_when_the_sample_delete_fails(): void {
        global $wpdb;

        $this->add_comment( 10, 'subject@example.com', 'spam' );
        $repository = $this->boot_repository();
        $repository->insert( 'latin', 'spam', 'Buy cheap pills now', 10, ASW_Detector::hash_email( 'subject@example.com' ) );

        // wpdb::query() returns false on a DB error. The rows still hold the full
        // comment text, so reporting the request complete would end an erasure
        // request with the personal data still stored.
        $wpdb->query_result = false;

        // The same refusal reaches the model-cache purge page 1 always runs,
        // whose prefix sweep is a statement of its own; capture its diagnostic
        // rather than letting it print through the test run.
        $this->capture_error_log(
            function () {
                $privacy = new ASW_Privacy();
                $result  = $privacy->erase( 'subject@example.com' );

                $this->assertTrue( $result['items_retained'] );
                $this->assertContains(
                    'AntiSpam Wizard training samples could not be removed because of a database error. Please retry this erasure request.',
                    $result['messages']
                );
            }
        );

        $wpdb->query_result = null;
        $this->assertCount( 1, $repository->find_by_comment_ids( array( 10 ) ) );
    }

    public function test_erase_does_not_retain_items_when_nothing_matched(): void {
        // "Nothing to delete" must stay distinct from "the DELETE failed".
        $this->add_comment( 10, 'subject@example.com', 'spam' );
        $this->boot_repository();

        $privacy = new ASW_Privacy();
        $result  = $privacy->erase( 'subject@example.com' );

        $this->assertFalse( $result['items_retained'] );
        $this->assertSame( array(), $result['messages'] );
    }

    // -- Policy content ------------------------------------------------------

    public function test_add_privacy_policy_content_registers_non_empty_text(): void {
        global $wp_privacy_policy_content;

        $privacy = new ASW_Privacy();
        $privacy->add_privacy_policy_content();

        $this->assertCount( 1, $wp_privacy_policy_content );
        $this->assertSame( 'AntiSpam Wizard', $wp_privacy_policy_content[0]['plugin_name'] );
        $this->assertNotEmpty( trim( $wp_privacy_policy_content[0]['policy_text'] ) );
        $this->assertStringContainsString( 'SHA-256', $wp_privacy_policy_content[0]['policy_text'] );
    }

    /**
     * ASW_Moderation stores on any Spam / Not Spam decision, not only on a check's
     * verdict: a comment the plugin never flagged gets a spam-log entry (fragment +
     * hashed IP) and a training sample holding its full text. The suggested policy
     * text is what a site owner publishes as their Art. 13 notice, so a wording that
     * still says "when a comment is flagged" / "uses a flagged comment to train"
     * understates what is actually stored.
     */
    public function test_policy_text_covers_data_stored_by_a_moderation_decision(): void {
        global $wp_privacy_policy_content;

        $privacy = new ASW_Privacy();
        $privacy->add_privacy_policy_content();

        $text = $wp_privacy_policy_content[0]['policy_text'];

        $this->assertStringContainsString( 'while moderating', $text );
        $this->assertStringContainsString( 'moderation decision', $text );
        $this->assertStringNotContainsString( 'the site administrator uses a flagged comment', $text );
    }
}
