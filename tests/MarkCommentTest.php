<?php

use PHPUnit\Framework\TestCase;

/**
 * A repository that lets the option store start refusing writes once the sample
 * has been stored -- the only way to reach the pill's rollback with a claim that
 * was written successfully and can no longer be cleared.
 */
class ANTISPAMWIZARD_Late_Option_Breaking_Sample_Repository extends ANTISPAMWIZARD_Sample_Repository {

    public function insert( string $script, string $category, string $content, int $comment_id, string $email_hash = '' ) {
        $result                            = parent::insert( $script, $category, $content, $comment_id, $email_hash );
        $GLOBALS['wp_update_option_fails'] = array( ANTISPAMWIZARD_Spam_Log::OPTION_KEY );
        return $result;
    }
}

/**
 * The same, for a sample write that is refused: the claim was taken, nothing was
 * stored, and the rollback that would release it is refused too.
 */
class ANTISPAMWIZARD_Refusing_Late_Option_Breaking_Sample_Repository extends ANTISPAMWIZARD_Sample_Repository {

    public function insert( string $script, string $category, string $content, int $comment_id, string $email_hash = '' ) {
        $GLOBALS['wp_update_option_fails'] = array( ANTISPAMWIZARD_Spam_Log::OPTION_KEY );
        return false;
    }
}

class MarkCommentTest extends TestCase {

    private ANTISPAMWIZARD_Admin_Page $admin_page;

    protected function setUp(): void {
        global $wp_options, $wp_actions, $wp_filters, $wp_menu_pages, $wp_enqueued_styles, $wp_current_user_caps, $wp_nonces, $wp_json_responses, $wp_comments, $wpdb;
        $wp_options            = array();
        $wp_actions            = array();
        $wp_filters            = array();
        $wp_menu_pages         = array();
        $wp_enqueued_styles    = array();
        $wp_current_user_caps  = array( 'manage_options' => true );
        $wp_nonces             = array();
        $wp_json_responses     = array();
        $wp_comments           = array();
        $_POST                 = array();

        $GLOBALS['wp_update_option_fails']         = false;
        $GLOBALS['wp_comment_status_change_fails'] = false;
        $GLOBALS['wp_update_comment_meta_fails']   = false;

        if ( ! defined( 'ANTISPAMWIZARD_PATH' ) ) {
            define( 'ANTISPAMWIZARD_PATH', dirname( __DIR__ ) . '/antispam-wizard/' );
        }

        // Reset wpdb in-memory tables.
        $wpdb->reset_tables();

        // Reset the ANTISPAMWIZARD singleton and initialize it so the repository is available.
        $reflection = new ReflectionClass( ANTISPAMWIZARD::class );
        $instance   = $reflection->getProperty( 'instance' );
        $instance->setValue( null, null );

        ANTISPAMWIZARD::get_instance()->init();

        $this->admin_page = new ANTISPAMWIZARD_Admin_Page();
    }

    protected function tearDown(): void {
        global $wpdb;
        $_POST = array();
        $GLOBALS['wp_update_option_fails']         = false;
        $GLOBALS['wp_comment_status_change_fails'] = false;
        $GLOBALS['wp_update_comment_meta_fails']   = false;
        $wpdb->delete_result                       = null;
    }

    public function test_mark_comment_as_ham(): void {
        global $wp_json_responses, $wp_comments;

        $wp_comments[42] = array(
            'comment_ID'       => 42,
            'comment_content'  => 'This is a perfectly good comment about programming.',
            'comment_approved' => 'spam',
        );

        ANTISPAMWIZARD_Spam_Log::clear();
        $entry_id = ANTISPAMWIZARD_Spam_Log::add_entry( 'perfectly good comment', array( 'bayesian' ), 'hash' );
        ANTISPAMWIZARD_Spam_Log::update_entry_comment_id( $entry_id, 42 );

        $nonce = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['nonce']      = $nonce;
        $_POST['comment_id'] = '42';
        $_POST['category']   = 'ham';

        $this->admin_page->ajax_mark_comment();

        $this->assertCount( 1, $wp_json_responses );
        $this->assertTrue( $wp_json_responses[0]['success'] );
        $this->assertStringContainsString( 'ham', $wp_json_responses[0]['data']['message'] );
        $this->assertSame( 'latin', $wp_json_responses[0]['data']['script'] );
        $this->assertStringContainsString( 'Latin', $wp_json_responses[0]['data']['script_label'] );
        // Comment should be unspammed. Core restores _wp_trash_meta_status, which a
        // comment flagged on submission never carries, so it lands unapproved.
        $this->assertSame( '0', $wp_comments[42]['comment_approved'] );
        // Sample should be in the DB repository, not in files.
        $repository = ANTISPAMWIZARD::get_instance()->get_repository();
        $samples    = $repository->find_all( 'latin', 'ham' );
        $this->assertContains( 'This is a perfectly good comment about programming.', $samples );
    }

    public function test_mark_comment_stores_the_trained_comment_id_on_the_sample(): void {
        global $wp_json_responses, $wp_comments, $wpdb;

        $wp_comments[77] = array(
            'comment_ID'       => 77,
            'comment_content'  => 'Buy cheap pills from our online store now.',
            'comment_approved' => '1',
        );

        ANTISPAMWIZARD_Spam_Log::clear();
        $entry_id = ANTISPAMWIZARD_Spam_Log::add_entry( 'Buy cheap pills', array( 'bayesian' ), 'hash' );
        ANTISPAMWIZARD_Spam_Log::update_entry_comment_id( $entry_id, 77 );

        $_POST['nonce']      = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['comment_id'] = '77';
        $_POST['category']   = 'spam';

        $this->admin_page->ajax_mark_comment();

        $this->assertTrue( $wp_json_responses[0]['success'] );

        $rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}antispamwizard_samples" );
        $this->assertCount( 1, $rows );
        $this->assertSame( 77, (int) $rows[0]->comment_id );
        $this->assertSame( 'Buy cheap pills from our online store now.', $rows[0]->content );
    }

    public function test_mark_comment_stores_the_author_email_hash_on_the_sample(): void {
        global $wp_json_responses, $wp_comments, $wpdb;

        $wp_comments[78] = array(
            'comment_ID'           => 78,
            'comment_content'      => 'Buy cheap watches from our online store now.',
            'comment_approved'     => '1',
            'comment_author_email' => 'Subject@Example.COM',
        );

        ANTISPAMWIZARD_Spam_Log::clear();
        $entry_id = ANTISPAMWIZARD_Spam_Log::add_entry( 'Buy cheap watches', array( 'bayesian' ), 'hash' );
        ANTISPAMWIZARD_Spam_Log::update_entry_comment_id( $entry_id, 78 );

        $_POST['nonce']      = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['comment_id'] = '78';
        $_POST['category']   = 'spam';

        $this->admin_page->ajax_mark_comment();

        $this->assertTrue( $wp_json_responses[0]['success'] );

        $rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}antispamwizard_samples" );
        $this->assertCount( 1, $rows );
        // The secondary subject key: hashed and case/whitespace-normalised, never
        // the raw address, so the sample stays reachable by a personal-data
        // request once the comment itself is hard-deleted.
        $this->assertSame( ANTISPAMWIZARD_Detector::hash_email( 'subject@example.com' ), $rows[0]->email_hash );
        $this->assertStringNotContainsString( 'Subject@Example.COM', implode( '|', array_map( 'strval', (array) $rows[0] ) ) );
    }

    public function test_mark_comment_stores_an_empty_email_hash_when_the_comment_has_no_address(): void {
        global $wp_json_responses, $wp_comments, $wpdb;

        $wp_comments[79] = array(
            'comment_ID'       => 79,
            'comment_content'  => 'Buy cheap shoes from our online store now.',
            'comment_approved' => '1',
        );

        ANTISPAMWIZARD_Spam_Log::clear();
        $entry_id = ANTISPAMWIZARD_Spam_Log::add_entry( 'Buy cheap shoes', array( 'bayesian' ), 'hash' );
        ANTISPAMWIZARD_Spam_Log::update_entry_comment_id( $entry_id, 79 );

        $_POST['nonce']      = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['comment_id'] = '79';
        $_POST['category']   = 'spam';

        $this->admin_page->ajax_mark_comment();

        $this->assertTrue( $wp_json_responses[0]['success'] );

        $rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}antispamwizard_samples" );
        $this->assertCount( 1, $rows );
        $this->assertSame( '', $rows[0]->email_hash );
        $this->assertSame( 79, (int) $rows[0]->comment_id );
    }

    public function test_mark_comment_normalizes_whitespace_before_insert(): void {
        global $wp_json_responses, $wp_comments;

        $wp_comments[99] = array(
            'comment_ID'       => 99,
            'comment_content'  => "  This  has\n\nnewlines\tand   extra   spaces  ",
            'comment_approved' => 'spam',
        );

        ANTISPAMWIZARD_Spam_Log::clear();
        $entry_id = ANTISPAMWIZARD_Spam_Log::add_entry( 'whitespace test', array( 'bayesian' ), 'hash' );
        ANTISPAMWIZARD_Spam_Log::update_entry_comment_id( $entry_id, 99 );

        $nonce = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['nonce']      = $nonce;
        $_POST['comment_id'] = '99';
        $_POST['category']   = 'ham';

        $this->admin_page->ajax_mark_comment();

        $this->assertCount( 1, $wp_json_responses );
        $this->assertTrue( $wp_json_responses[0]['success'] );

        // Verify that stored content is normalized (no extra whitespace, no newlines, trimmed).
        $repository = ANTISPAMWIZARD::get_instance()->get_repository();
        $samples    = $repository->find_all( 'latin', 'ham' );
        $this->assertContains( 'This has newlines and extra spaces', $samples );
        // Original un-normalized content should NOT be present.
        $this->assertNotContains( "  This  has\n\nnewlines\tand   extra   spaces  ", $samples );
    }

    public function test_mark_comment_normalizes_unicode_whitespace_before_insert(): void {
        global $wp_json_responses, $wp_comments;

        // Use NBSP (\xC2\xA0) and ideographic space (\xE3\x80\x80) between words.
        $wp_comments[100] = array(
            'comment_ID'       => 100,
            'comment_content'  => "Hello\xC2\xA0world\xE3\x80\x80test",
            'comment_approved' => 'spam',
        );

        ANTISPAMWIZARD_Spam_Log::clear();
        $entry_id = ANTISPAMWIZARD_Spam_Log::add_entry( 'unicode ws test', array( 'bayesian' ), 'hash' );
        ANTISPAMWIZARD_Spam_Log::update_entry_comment_id( $entry_id, 100 );

        $nonce = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['nonce']      = $nonce;
        $_POST['comment_id'] = '100';
        $_POST['category']   = 'ham';

        $wp_json_responses = array();
        $this->admin_page->ajax_mark_comment();

        $this->assertCount( 1, $wp_json_responses );
        $this->assertTrue( $wp_json_responses[0]['success'] );

        // NBSP and ideographic space should be normalized to regular spaces.
        $repository = ANTISPAMWIZARD::get_instance()->get_repository();
        $samples    = $repository->find_all( 'latin', 'ham' );
        $this->assertContains( 'Hello world test', $samples );
    }

    public function test_mark_comment_as_spam(): void {
        global $wp_json_responses, $wp_comments;

        $wp_comments[10] = array(
            'comment_ID'       => 10,
            'comment_content'  => 'Buy cheap pills now!!!',
            'comment_approved' => '1',
        );

        ANTISPAMWIZARD_Spam_Log::clear();
        $entry_id = ANTISPAMWIZARD_Spam_Log::add_entry( 'Buy cheap pills now', array( 'links' ), 'hash' );
        ANTISPAMWIZARD_Spam_Log::update_entry_comment_id( $entry_id, 10 );

        $nonce = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['nonce']      = $nonce;
        $_POST['comment_id'] = '10';
        $_POST['category']   = 'spam';

        $this->admin_page->ajax_mark_comment();

        $this->assertCount( 1, $wp_json_responses );
        $this->assertTrue( $wp_json_responses[0]['success'] );
        $this->assertStringContainsString( 'spam', $wp_json_responses[0]['data']['message'] );
        $this->assertSame( 'latin', $wp_json_responses[0]['data']['script'] );
        $this->assertStringContainsString( 'Latin', $wp_json_responses[0]['data']['script_label'] );
        // Comment should be spammed.
        $this->assertSame( 'spam', $wp_comments[10]['comment_approved'] );
        // Sample should be in the DB repository.
        $repository = ANTISPAMWIZARD::get_instance()->get_repository();
        $samples    = $repository->find_all( 'latin', 'spam' );
        $this->assertContains( 'Buy cheap pills now!!!', $samples );
    }

    public function test_mark_comment_fails_without_nonce(): void {
        global $wp_json_responses;

        $_POST['comment_id'] = '42';
        $_POST['category']   = 'ham';

        $this->admin_page->ajax_mark_comment();

        $this->assertCount( 1, $wp_json_responses );
        $this->assertFalse( $wp_json_responses[0]['success'] );
        $this->assertStringContainsString( 'Security', $wp_json_responses[0]['data']['message'] );
    }

    public function test_mark_comment_fails_with_invalid_nonce(): void {
        global $wp_json_responses;

        $_POST['nonce']      = 'bad_nonce';
        $_POST['comment_id'] = '42';
        $_POST['category']   = 'ham';

        $this->admin_page->ajax_mark_comment();

        $this->assertCount( 1, $wp_json_responses );
        $this->assertFalse( $wp_json_responses[0]['success'] );
        $this->assertStringContainsString( 'Security', $wp_json_responses[0]['data']['message'] );
    }

    public function test_mark_comment_fails_without_capability(): void {
        global $wp_json_responses, $wp_current_user_caps;
        $wp_current_user_caps = array();

        $nonce = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['nonce']      = $nonce;
        $_POST['comment_id'] = '42';
        $_POST['category']   = 'ham';

        $this->admin_page->ajax_mark_comment();

        $this->assertCount( 1, $wp_json_responses );
        $this->assertFalse( $wp_json_responses[0]['success'] );
        $this->assertStringContainsString( 'Permission', $wp_json_responses[0]['data']['message'] );
    }

    public function test_mark_comment_fails_with_invalid_comment_id(): void {
        global $wp_json_responses;

        $nonce = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['nonce']      = $nonce;
        $_POST['comment_id'] = '0';
        $_POST['category']   = 'ham';

        $this->admin_page->ajax_mark_comment();

        $this->assertCount( 1, $wp_json_responses );
        $this->assertFalse( $wp_json_responses[0]['success'] );
        $this->assertStringContainsString( 'Invalid comment', $wp_json_responses[0]['data']['message'] );
    }

    public function test_mark_comment_fails_with_invalid_category(): void {
        global $wp_json_responses;

        $nonce = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['nonce']      = $nonce;
        $_POST['comment_id'] = '42';
        $_POST['category']   = 'invalid';

        $this->admin_page->ajax_mark_comment();

        $this->assertCount( 1, $wp_json_responses );
        $this->assertFalse( $wp_json_responses[0]['success'] );
        $this->assertStringContainsString( 'Invalid category', $wp_json_responses[0]['data']['message'] );
    }

    public function test_mark_comment_fails_when_comment_not_found(): void {
        global $wp_json_responses;

        $nonce = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['nonce']      = $nonce;
        $_POST['comment_id'] = '999';
        $_POST['category']   = 'ham';

        $this->admin_page->ajax_mark_comment();

        $this->assertCount( 1, $wp_json_responses );
        $this->assertFalse( $wp_json_responses[0]['success'] );
        $this->assertStringContainsString( 'not found', $wp_json_responses[0]['data']['message'] );
    }

    public function test_spam_log_stores_comment_id(): void {
        ANTISPAMWIZARD_Spam_Log::clear();

        ANTISPAMWIZARD_Spam_Log::add_entry( 'test text', array( 'test' ), 'hash123', 42 );
        $entries = ANTISPAMWIZARD_Spam_Log::get_entries();

        $this->assertCount( 1, $entries );
        $this->assertSame( 42, $entries[0]['comment_id'] );
    }

    public function test_spam_log_default_comment_id_is_zero(): void {
        ANTISPAMWIZARD_Spam_Log::clear();

        ANTISPAMWIZARD_Spam_Log::add_entry( 'test text', array( 'test' ), 'hash123' );
        $entries = ANTISPAMWIZARD_Spam_Log::get_entries();

        $this->assertCount( 1, $entries );
        $this->assertSame( 0, $entries[0]['comment_id'] );
    }

    public function test_spam_log_update_entry_comment_id(): void {
        ANTISPAMWIZARD_Spam_Log::clear();

        $entry_id = ANTISPAMWIZARD_Spam_Log::add_entry( 'test text', array( 'test' ), 'hash123' );
        ANTISPAMWIZARD_Spam_Log::update_entry_comment_id( $entry_id, 77 );

        $entries = ANTISPAMWIZARD_Spam_Log::get_entries();
        $this->assertSame( 77, $entries[0]['comment_id'] );
    }

    public function test_spam_log_update_targets_correct_entry_under_concurrency(): void {
        ANTISPAMWIZARD_Spam_Log::clear();

        $entry_id_1 = ANTISPAMWIZARD_Spam_Log::add_entry( 'first spam', array( 'test' ), 'hash1' );
        $entry_id_2 = ANTISPAMWIZARD_Spam_Log::add_entry( 'second spam', array( 'test' ), 'hash2' );

        ANTISPAMWIZARD_Spam_Log::update_entry_comment_id( $entry_id_1, 100 );

        $entries = ANTISPAMWIZARD_Spam_Log::get_entries();
        $this->assertSame( 100, $entries[0]['comment_id'] );
        $this->assertSame( 0, $entries[1]['comment_id'] );
    }

    public function test_mark_comment_rejects_duplicate_training(): void {
        global $wp_json_responses, $wp_comments;

        $wp_comments[50] = array(
            'comment_ID'       => 50,
            'comment_content'  => 'Some comment text for training.',
            'comment_approved' => 'spam',
        );

        ANTISPAMWIZARD_Spam_Log::clear();
        $entry_id = ANTISPAMWIZARD_Spam_Log::add_entry( 'Some comment text', array( 'links' ), 'hash' );
        ANTISPAMWIZARD_Spam_Log::update_entry_comment_id( $entry_id, 50 );

        $nonce = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['nonce']      = $nonce;
        $_POST['comment_id'] = '50';
        $_POST['category']   = 'spam';

        // First call should succeed.
        $this->admin_page->ajax_mark_comment();
        $this->assertCount( 1, $wp_json_responses );
        $this->assertTrue( $wp_json_responses[0]['success'] );

        // Second call should be rejected (already trained in spam log).
        $wp_json_responses = array();
        $this->admin_page->ajax_mark_comment();
        $this->assertCount( 1, $wp_json_responses );
        $this->assertFalse( $wp_json_responses[0]['success'] );
        $this->assertStringContainsString( 'already been used', $wp_json_responses[0]['data']['message'] );
    }

    public function test_unmark_trained_allows_retry(): void {
        ANTISPAMWIZARD_Spam_Log::clear();
        $entry_id = ANTISPAMWIZARD_Spam_Log::add_entry( 'test text', array( 'test' ), 'hash' );
        ANTISPAMWIZARD_Spam_Log::update_entry_comment_id( $entry_id, 99 );

        // Mark trained.
        $this->assertSame( 'ok', ANTISPAMWIZARD_Spam_Log::mark_trained( 99, 'spam' ) );
        $this->assertTrue( ANTISPAMWIZARD_Spam_Log::is_trained( 99 ) );

        // Unmark trained.
        ANTISPAMWIZARD_Spam_Log::unmark_trained( 99 );
        $this->assertFalse( ANTISPAMWIZARD_Spam_Log::is_trained( 99 ) );

        // Can be re-trained now.
        $this->assertSame( 'ok', ANTISPAMWIZARD_Spam_Log::mark_trained( 99, 'ham' ) );
        $this->assertTrue( ANTISPAMWIZARD_Spam_Log::is_trained( 99 ) );
    }

    public function test_init_registers_mark_comment_ajax_hook(): void {
        global $wp_actions;
        $wp_actions = array();

        $this->admin_page->init();

        $this->assertArrayHasKey( 'wp_ajax_antispamwizard_mark_comment', $wp_actions );
    }

    public function test_mark_cyrillic_comment_routes_to_cyrillic_in_db(): void {
        global $wp_json_responses, $wp_comments;

        $wp_comments[20] = array(
            'comment_ID'       => 20,
            'comment_content'  => 'Купите дешевые таблетки прямо сейчас!',
            'comment_approved' => '1',
        );

        ANTISPAMWIZARD_Spam_Log::clear();
        $entry_id = ANTISPAMWIZARD_Spam_Log::add_entry( 'Купите дешевые таблетки', array( 'stop_words' ), 'hash' );
        ANTISPAMWIZARD_Spam_Log::update_entry_comment_id( $entry_id, 20 );

        $nonce = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['nonce']      = $nonce;
        $_POST['comment_id'] = '20';
        $_POST['category']   = 'spam';

        $this->admin_page->ajax_mark_comment();

        $this->assertCount( 1, $wp_json_responses );
        $this->assertTrue( $wp_json_responses[0]['success'] );
        $this->assertSame( 'cyrillic', $wp_json_responses[0]['data']['script'] );
        $this->assertStringContainsString( 'Cyrillic', $wp_json_responses[0]['data']['script_label'] );

        // Verify it was saved to DB with cyrillic script.
        $repository = ANTISPAMWIZARD::get_instance()->get_repository();
        $samples    = $repository->find_all( 'cyrillic', 'spam' );
        $this->assertContains( 'Купите дешевые таблетки прямо сейчас!', $samples );

        // Verify it was NOT saved to latin.
        $latin_samples = $repository->find_all( 'latin', 'spam' );
        $this->assertNotContains( 'Купите дешевые таблетки прямо сейчас!', $latin_samples );
    }

    public function test_mark_unknown_script_comment_routes_to_unknown_in_db(): void {
        global $wp_json_responses, $wp_comments;

        $wp_comments[30] = array(
            'comment_ID'       => 30,
            'comment_content'  => '12345 67890 !!!',
            'comment_approved' => '1',
        );

        ANTISPAMWIZARD_Spam_Log::clear();
        $entry_id = ANTISPAMWIZARD_Spam_Log::add_entry( '12345 67890', array( 'links' ), 'hash' );
        ANTISPAMWIZARD_Spam_Log::update_entry_comment_id( $entry_id, 30 );

        $nonce = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['nonce']      = $nonce;
        $_POST['comment_id'] = '30';
        $_POST['category']   = 'spam';

        $this->admin_page->ajax_mark_comment();

        $this->assertCount( 1, $wp_json_responses );
        $this->assertTrue( $wp_json_responses[0]['success'] );
        $this->assertSame( 'unknown', $wp_json_responses[0]['data']['script'] );
        $this->assertStringContainsString( 'Legacy', $wp_json_responses[0]['data']['script_label'] );

        // Verify it was saved to DB with 'unknown' script.
        $repository = ANTISPAMWIZARD::get_instance()->get_repository();
        $samples    = $repository->find_all( 'unknown', 'spam' );
        $this->assertContains( '12345 67890 !!!', $samples );
    }

    public function test_mark_comment_inserts_to_repository_and_bumps_revision(): void {
        global $wp_json_responses, $wp_comments;

        $wp_comments[60] = array(
            'comment_ID'       => 60,
            'comment_content'  => 'A test comment for revision tracking.',
            'comment_approved' => '1',
        );

        ANTISPAMWIZARD_Spam_Log::clear();
        $entry_id = ANTISPAMWIZARD_Spam_Log::add_entry( 'test comment', array( 'test' ), 'hash' );
        ANTISPAMWIZARD_Spam_Log::update_entry_comment_id( $entry_id, 60 );

        $repository     = ANTISPAMWIZARD::get_instance()->get_repository();
        $rev_before     = $repository->latest_revision();

        $nonce = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['nonce']      = $nonce;
        $_POST['comment_id'] = '60';
        $_POST['category']   = 'spam';

        $this->admin_page->ajax_mark_comment();

        $this->assertTrue( $wp_json_responses[0]['success'] );
        // Revision should have been bumped.
        $this->assertGreaterThan( $rev_before, $repository->latest_revision() );
    }

    /**
     * Confirming a comment the plugin already put in the spam queue fires no
     * transition, so the pill drops the plugin's flag itself: a moderator's spam
     * verdict is never shown to the comment's author (ANTISPAMWIZARD_Shadow_Display).
     */
    public function test_confirming_a_flagged_comment_as_spam_stops_its_shadow_display(): void {
        global $wp_json_responses, $wp_comments, $wp_comment_meta;

        $wp_comments[11] = array(
            'comment_ID'       => 11,
            'comment_content'  => 'Cheap watches in our shop, visit today.',
            'comment_approved' => 'spam',
        );
        $wp_comments[12] = array(
            'comment_ID'       => 12,
            'comment_content'  => 'Cheap shoes in our shop, visit today.',
            'comment_approved' => 'trash',
        );
        $wp_comment_meta[11] = array( '_antispamwizard_reason' => 'links' );
        $wp_comment_meta[12] = array( '_antispamwizard_reason' => 'links' );

        ANTISPAMWIZARD_Spam_Log::clear();
        foreach ( array( 11, 12 ) as $comment_id ) {
            $entry_id = ANTISPAMWIZARD_Spam_Log::add_entry( 'Cheap goods', array( 'links' ), 'hash' );
            ANTISPAMWIZARD_Spam_Log::update_entry_comment_id( $entry_id, $comment_id );

            $_POST['nonce']      = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
            $_POST['comment_id'] = (string) $comment_id;
            $_POST['category']   = 'spam';
            $this->admin_page->ajax_mark_comment();
        }

        $this->assertTrue( $wp_json_responses[0]['success'] );
        $this->assertTrue( $wp_json_responses[1]['success'] );
        $this->assertSame( 'spam', $wp_comments[11]['comment_approved'] );
        $this->assertSame( 'spam', $wp_comments[12]['comment_approved'] );
        $this->assertArrayNotHasKey( '_antispamwizard_reason', $wp_comment_meta[11], 'already spam: no transition fired' );
        $this->assertArrayNotHasKey( '_antispamwizard_reason', $wp_comment_meta[12], 'trash → spam keeps the flag, the pill does not' );
    }

    public function test_a_failed_spam_confirmation_keeps_the_flag(): void {
        global $wp_json_responses, $wp_comments, $wp_comment_meta;

        $wp_comments[13] = array(
            'comment_ID'       => 13,
            'comment_content'  => 'Cheap bags in our shop, visit today.',
            'comment_approved' => 'trash',
        );
        $wp_comment_meta[13] = array( '_antispamwizard_reason' => 'links' );

        ANTISPAMWIZARD_Spam_Log::clear();
        $entry_id = ANTISPAMWIZARD_Spam_Log::add_entry( 'Cheap bags', array( 'links' ), 'hash' );
        ANTISPAMWIZARD_Spam_Log::update_entry_comment_id( $entry_id, 13 );

        $_POST['nonce']      = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['comment_id'] = '13';
        $_POST['category']   = 'spam';

        $GLOBALS['wp_comment_status_change_fails'] = true;
        $this->admin_page->ajax_mark_comment();
        $GLOBALS['wp_comment_status_change_fails'] = false;

        $this->assertFalse( $wp_json_responses[0]['success'] );
        $this->assertSame( 'links', $wp_comment_meta[13]['_antispamwizard_reason'], 'no verdict was recorded' );
    }

    /**
     * Trashing a comment from the spam queue records 'spam' as the status
     * wp_untrash_comment()/wp_unspam_comment() restore, so a Ham verdict on it
     * must not hand the comment back to the spam queue (which would train spam
     * through ANTISPAMWIZARD_Moderation and keep it shadow-displayed): it lands unapproved,
     * trained as ham only, with the plugin's flag dropped.
     */
    public function test_a_ham_verdict_on_a_comment_trashed_from_the_spam_queue_does_not_restore_it_as_spam(): void {
        global $wp_json_responses, $wp_comments, $wp_comment_meta;

        $text            = 'A genuine question about the plugin settings page.';
        $wp_comments[14] = array(
            'comment_ID'       => 14,
            'comment_content'  => $text,
            'comment_approved' => 'trash',
        );
        $wp_comment_meta[14] = array(
            '_antispamwizard_reason'           => 'bayesian',
            '_wp_trash_meta_status' => 'spam',
        );

        ANTISPAMWIZARD_Spam_Log::clear();
        $entry_id = ANTISPAMWIZARD_Spam_Log::add_entry( 'A genuine question', array( 'bayesian' ), 'hash' );
        ANTISPAMWIZARD_Spam_Log::update_entry_comment_id( $entry_id, 14 );

        $_POST['nonce']      = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['comment_id'] = '14';
        $_POST['category']   = 'ham';
        $this->admin_page->ajax_mark_comment();

        $repository = ANTISPAMWIZARD::get_instance()->get_repository();
        $this->assertTrue( $wp_json_responses[0]['success'] );
        $this->assertSame( '0', $wp_comments[14]['comment_approved'] );
        $this->assertSame( array( $text ), $repository->find_all( 'latin', 'ham' ) );
        $this->assertSame( array(), $repository->find_all( 'latin', 'spam' ) );
        $this->assertArrayNotHasKey( '_antispamwizard_reason', $wp_comment_meta[14] );
        $this->assertArrayNotHasKey( '_wp_trash_meta_status', $wp_comment_meta[14] );
    }

    /**
     * A refused untrash puts the recorded 'spam' back, so the comment's own
     * Restore still returns it where the moderator trashed it from.
     */
    public function test_a_refused_ham_untrash_keeps_the_recorded_status_and_the_flag(): void {
        global $wp_json_responses, $wp_comments, $wp_comment_meta;

        $wp_comments[15] = array(
            'comment_ID'       => 15,
            'comment_content'  => 'Another genuine question about the settings.',
            'comment_approved' => 'trash',
        );
        $wp_comment_meta[15] = array(
            '_antispamwizard_reason'           => 'bayesian',
            '_wp_trash_meta_status' => 'spam',
        );

        ANTISPAMWIZARD_Spam_Log::clear();
        $entry_id = ANTISPAMWIZARD_Spam_Log::add_entry( 'Another genuine question', array( 'bayesian' ), 'hash' );
        ANTISPAMWIZARD_Spam_Log::update_entry_comment_id( $entry_id, 15 );

        $_POST['nonce']      = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['comment_id'] = '15';
        $_POST['category']   = 'ham';

        $GLOBALS['wp_comment_status_change_fails'] = true;
        $this->admin_page->ajax_mark_comment();
        $GLOBALS['wp_comment_status_change_fails'] = false;

        $this->assertFalse( $wp_json_responses[0]['success'] );
        $this->assertSame( 'trash', $wp_comments[15]['comment_approved'] );
        $this->assertSame( 'spam', $wp_comment_meta[15]['_wp_trash_meta_status'] );
        $this->assertSame( 'bayesian', $wp_comment_meta[15]['_antispamwizard_reason'] );
    }

    /**
     * When the refused untrash is followed by a refused restore of the recorded
     * 'spam', the comment's own Restore would put it in the moderation queue, so
     * the rollback is read back and its failure logged.
     */
    public function test_a_refused_trash_status_rollback_is_logged(): void {
        global $wp_json_responses, $wp_comments, $wp_comment_meta;

        $wp_comments[18] = array(
            'comment_ID'       => 18,
            'comment_content'  => 'A third genuine question about the settings.',
            'comment_approved' => 'trash',
        );
        $wp_comment_meta[18] = array(
            '_antispamwizard_reason'           => 'bayesian',
            '_wp_trash_meta_status' => 'spam',
        );

        ANTISPAMWIZARD_Spam_Log::clear();
        $entry_id = ANTISPAMWIZARD_Spam_Log::add_entry( 'A third genuine question', array( 'bayesian' ), 'hash' );
        ANTISPAMWIZARD_Spam_Log::update_entry_comment_id( $entry_id, 18 );

        $_POST['nonce']      = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['comment_id'] = '18';
        $_POST['category']   = 'ham';

        $tmp     = tempnam( sys_get_temp_dir(), 'antispamwizard_test_' );
        $old_log = ini_set( 'error_log', $tmp );

        $GLOBALS['wp_comment_status_change_fails'] = true;
        $GLOBALS['wp_update_comment_meta_fails']   = static function ( $comment_id, $meta_key, $meta_value ) {
            return '_wp_trash_meta_status' === $meta_key && 'spam' === $meta_value;
        };
        $this->admin_page->ajax_mark_comment();
        $GLOBALS['wp_comment_status_change_fails'] = false;
        $GLOBALS['wp_update_comment_meta_fails']   = false;

        ini_set( 'error_log', $old_log );
        $logged = file_get_contents( $tmp );
        unlink( $tmp );

        $this->assertFalse( $wp_json_responses[0]['success'] );
        $this->assertSame( 'trash', $wp_comments[18]['comment_approved'] );
        $this->assertSame( '0', $wp_comment_meta[18]['_wp_trash_meta_status'] );
        $this->assertStringContainsString( 'ANTISPAMWIZARD: trash status rollback failed for comment 18', $logged );
    }

    /**
     * A comment deleted between the lookup and the status read has no status to
     * leave alone: the Ham verdict is a failed status change, so the claim goes
     * back and the moderator is not told the comment was marked.
     */
    public function test_a_ham_verdict_on_a_comment_deleted_mid_request_is_a_failed_status_change(): void {
        global $wp_json_responses, $wp_comments, $wp_comment_cache;

        // Only the request's own cached copy is left: the row itself is gone.
        unset( $wp_comments[19] );
        $wp_comment_cache[19] = array(
            'comment_ID'       => 19,
            'comment_content'  => 'A comment another moderator deletes meanwhile.',
            'comment_approved' => 'spam',
        );

        ANTISPAMWIZARD_Spam_Log::clear();
        $entry_id = ANTISPAMWIZARD_Spam_Log::add_entry( 'A comment another moderator', array( 'bayesian' ), 'hash' );
        ANTISPAMWIZARD_Spam_Log::update_entry_comment_id( $entry_id, 19 );

        $_POST['nonce']      = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['comment_id'] = '19';
        $_POST['category']   = 'ham';

        try {
            $this->admin_page->ajax_mark_comment();
        } finally {
            unset( $wp_comment_cache[19] );
        }

        $this->assertFalse( $wp_json_responses[0]['success'] );
        $this->assertStringContainsString( 'status could not be changed', $wp_json_responses[0]['data']['message'] );
        $this->assertFalse( ANTISPAMWIZARD_Spam_Log::is_trained( 19 ) );
    }

    /**
     * Ham on a comment already out of the spam queue writes no status: core
     * refuses a write that changes nothing, which would report a false failure.
     */
    public function test_a_ham_verdict_on_a_held_comment_leaves_its_status_alone(): void {
        global $wp_json_responses, $wp_comments;

        $wp_comments[16] = array(
            'comment_ID'       => 16,
            'comment_content'  => 'A held comment the moderator confirms as fine.',
            'comment_approved' => '0',
        );

        ANTISPAMWIZARD_Spam_Log::clear();
        $entry_id = ANTISPAMWIZARD_Spam_Log::add_entry( 'A held comment', array( 'bayesian' ), 'hash' );
        ANTISPAMWIZARD_Spam_Log::update_entry_comment_id( $entry_id, 16 );

        $_POST['nonce']      = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['comment_id'] = '16';
        $_POST['category']   = 'ham';

        // Any status write would fail here, so success proves none was attempted.
        $GLOBALS['wp_comment_status_change_fails'] = true;
        $this->admin_page->ajax_mark_comment();
        $GLOBALS['wp_comment_status_change_fails'] = false;

        $this->assertTrue( $wp_json_responses[0]['success'] );
        $this->assertSame( '0', $wp_comments[16]['comment_approved'] );
    }

    /**
     * A failed status change releases the claim but keeps the stored sample.
     *
     * The sample table is deduplicated by content, so the row the pill inserted
     * is shared state rather than this request's property: the body lock cannot
     * be held across the status change, and another moderation of the same body
     * -- one bot body posted from many addresses is routine -- can be answered
     * 'exists' in that window and commit against this very row. Deleting it on
     * the way out pulled the sample out from under that request's claim, which
     * every later transition of its comment then reads as 'already_trained' --
     * a silent loss on a comment this request has nothing to do with.
     *
     * Keeping the row is why the reconciliation runs on this path too: a kept row
     * plus an untouched opposite row is the text trained as both categories, so
     * the flip delete is issued here exactly as it is on the success path, and the
     * only thing the failed status change changes is that the claim goes back and
     * the moderator is told to retry. All three halves are asserted here.
     */
    public function test_a_failed_status_change_releases_the_claim_and_keeps_the_sample(): void {
        global $wp_json_responses, $wp_comments;

        $text       = 'A body whose status write the database refuses.';
        $repository = ANTISPAMWIZARD::get_instance()->get_repository();

        // The same text, already trained as ham from another comment.
        $repository->insert( 'latin', 'ham', $text, 45, '' );

        $wp_comments[70] = array(
            'comment_ID'       => 70,
            'comment_content'  => $text,
            'comment_approved' => '1',
        );

        ANTISPAMWIZARD_Spam_Log::clear();
        $entry_id = ANTISPAMWIZARD_Spam_Log::add_entry( $text, array( 'test' ), 'hash' );
        ANTISPAMWIZARD_Spam_Log::update_entry_comment_id( $entry_id, 70 );

        $_POST['nonce']      = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['comment_id'] = '70';
        $_POST['category']   = 'spam';

        $GLOBALS['wp_comment_status_change_fails'] = true;
        $this->admin_page->ajax_mark_comment();
        $GLOBALS['wp_comment_status_change_fails'] = false;

        $this->assertFalse( $wp_json_responses[0]['success'] );
        $this->assertStringContainsString( 'status could not be changed', $wp_json_responses[0]['data']['message'] );
        // The status never moved, and the row another request may already have
        // committed against is still there.
        $this->assertSame( '1', $wp_comments[70]['comment_approved'] );
        $this->assertSame( array( $text ), $repository->find_all( 'latin', 'spam' ) );
        // The row is kept, so the contradiction it would otherwise leave behind is
        // reconciled here rather than waiting for a later moderation.
        $this->assertSame( array(), $repository->find_all( 'latin', 'ham' ) );
        // The claim is gone, so the retry the error asks for can take it again.
        $this->assertFalse( ANTISPAMWIZARD_Spam_Log::is_trained( 70 ) );

        $wp_json_responses = array();
        $this->admin_page->ajax_mark_comment();

        $this->assertTrue( $wp_json_responses[0]['success'] );
        $this->assertSame( 'spam', $wp_comments[70]['comment_approved'] );
        // insert() deduplicates, so the retry stores no second copy.
        $this->assertSame( array( $text ), $repository->find_all( 'latin', 'spam' ) );
        $this->assertSame( array(), $repository->find_all( 'latin', 'ham' ) );
        $this->assertTrue( ANTISPAMWIZARD_Spam_Log::is_trained( 70 ) );
    }

    /**
     * A read the database refused comes back from find_categories_by_hash() as no
     * rows, indistinguishable from "nothing stored", so the flip delete is issued
     * only on the positive evidence of *both* categories being present. Deleting
     * on an empty result instead would re-open the race the re-read exists to
     * close: a concurrent moderation that committed the opposite category and
     * removed this request's row would have its own row deleted here, leaving the
     * text trained as neither with both log claims standing.
     *
     * Not deleting leaves at most the contradiction, which the next moderation of
     * either comment clears -- training_is_settled() refuses a claim whose
     * opposite row is present.
     */
    public function test_a_failed_supersession_read_deletes_nothing(): void {
        global $wp_json_responses, $wp_comments, $wpdb;

        $text       = 'A body whose supersession read the database refuses.';
        $repository = ANTISPAMWIZARD::get_instance()->get_repository();

        // Stands in for the newer decision's row: another moderation committed the
        // opposite category for this text and removed this request's row.
        $repository->insert( 'latin', 'ham', $text, 47, '' );

        // Already in the spam queue, so no status change and no transition: the
        // reconciliation below is the only writer.
        $wp_comments[67] = array(
            'comment_ID'       => 67,
            'comment_content'  => $text,
            'comment_approved' => 'spam',
        );

        ANTISPAMWIZARD_Spam_Log::clear();
        $entry_id = ANTISPAMWIZARD_Spam_Log::add_entry( $text, array( 'links' ), 'hash' );
        ANTISPAMWIZARD_Spam_Log::update_entry_comment_id( $entry_id, 67 );

        $_POST['nonce']      = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['comment_id'] = '67';
        $_POST['category']   = 'spam';

        $wpdb->get_results_result = false;
        $this->admin_page->ajax_mark_comment();
        $wpdb->get_results_result = null;

        $this->assertTrue( $wp_json_responses[0]['success'] );
        // Nothing was deleted on the strength of a read that failed.
        $this->assertSame( array( $text ), $repository->find_all( 'latin', 'ham' ) );
        $this->assertSame( array( $text ), $repository->find_all( 'latin', 'spam' ) );
    }

    /**
     * A refused sample write leaves nothing stored, and a refused rollback leaves
     * the claim standing over it -- the pill answers every later click
     * 'already_trained', so "try again" is exactly what will not help. The
     * moderator is told to check the training data instead.
     */
    public function test_a_refused_claim_rollback_after_a_refused_insert_is_reported(): void {
        global $wp_json_responses, $wp_comments, $wpdb;

        $text = 'A body whose sample write the database refuses.';

        $wp_comments[68] = array(
            'comment_ID'       => 68,
            'comment_content'  => $text,
            'comment_approved' => 'spam',
        );

        ANTISPAMWIZARD_Spam_Log::clear();
        $entry_id = ANTISPAMWIZARD_Spam_Log::add_entry( $text, array( 'links' ), 'hash' );
        ANTISPAMWIZARD_Spam_Log::update_entry_comment_id( $entry_id, 68 );

        $reflection = new ReflectionClass( ANTISPAMWIZARD::class );
        $repo       = $reflection->getProperty( 'repository' );
        $repo->setValue( ANTISPAMWIZARD::get_instance(), new ANTISPAMWIZARD_Refusing_Late_Option_Breaking_Sample_Repository( $wpdb ) );

        $_POST['nonce']      = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['comment_id'] = '68';
        $_POST['category']   = 'spam';

        $tmp     = tempnam( sys_get_temp_dir(), 'antispamwizard_test_' );
        $old_log = ini_set( 'error_log', $tmp );

        $this->admin_page->ajax_mark_comment();

        ini_set( 'error_log', $old_log );
        $logged = file_get_contents( $tmp );
        unlink( $tmp );

        $this->assertFalse( $wp_json_responses[0]['success'] );
        $this->assertStringContainsString( 'may be inconsistent', $wp_json_responses[0]['data']['message'] );
        $this->assertStringContainsString( 'check training data manually', $wp_json_responses[0]['data']['message'] );
        $this->assertStringContainsString( 'rollback failed for comment 68', $logged );
    }

    /**
     * The pill is the second writer of the sample table, and it has to keep the
     * same invariant ANTISPAMWIZARD_Moderation's flip delete keeps: a text is stored under
     * exactly one category, never both. The gap this pins is the common case on
     * the Spam Log screen -- confirming a comment the plugin already moved to the
     * spam queue. No status change is needed there, so no
     * transition_comment_status fires and ANTISPAMWIZARD_Moderation never runs; without the
     * pill's own reconciliation the text stayed trained as ham *and* spam for
     * good, since the claim then refuses every retry.
     */
    public function test_marking_a_comment_already_at_the_target_status_deletes_the_opposite_sample(): void {
        global $wp_json_responses, $wp_comments;

        $text       = 'Identical body trained from two different comments.';
        $repository = ANTISPAMWIZARD::get_instance()->get_repository();

        // The same text, already trained as ham from another comment.
        $this->assertSame( 'inserted', $repository->insert( 'latin', 'ham', $text, 41, '' ) );

        // Already in the spam queue, so the handler changes no status at all.
        $wp_comments[60] = array(
            'comment_ID'       => 60,
            'comment_content'  => $text,
            'comment_approved' => 'spam',
        );

        ANTISPAMWIZARD_Spam_Log::clear();
        $entry_id = ANTISPAMWIZARD_Spam_Log::add_entry( $text, array( 'links' ), 'hash' );
        ANTISPAMWIZARD_Spam_Log::update_entry_comment_id( $entry_id, 60 );

        $_POST['nonce']      = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['comment_id'] = '60';
        $_POST['category']   = 'spam';

        $this->admin_page->ajax_mark_comment();

        $this->assertTrue( $wp_json_responses[0]['success'] );
        // No transition could have reconciled this: the status never moved.
        $this->assertSame( 'spam', $wp_comments[60]['comment_approved'] );
        $this->assertSame( array( $text ), $repository->find_all( 'latin', 'spam' ) );
        $this->assertSame( array(), $repository->find_all( 'latin', 'ham' ) );
    }

    /**
     * A refused DELETE is not "nothing matched": the text is left in both
     * categories, so the claim is rolled back and the pill offers the retry that
     * clears it. Leaving the claim standing would make the contradiction
     * permanent -- mark_trained() refuses an entry that already carries a
     * category.
     */
    public function test_a_refused_opposite_delete_rolls_the_claim_back_so_the_pill_can_retry(): void {
        global $wp_json_responses, $wp_comments, $wpdb;

        $text       = 'Another identical body trained twice over.';
        $repository = ANTISPAMWIZARD::get_instance()->get_repository();
        $repository->insert( 'latin', 'ham', $text, 43, '' );

        $wp_comments[61] = array(
            'comment_ID'       => 61,
            'comment_content'  => $text,
            'comment_approved' => 'spam',
        );

        ANTISPAMWIZARD_Spam_Log::clear();
        $entry_id = ANTISPAMWIZARD_Spam_Log::add_entry( $text, array( 'links' ), 'hash' );
        ANTISPAMWIZARD_Spam_Log::update_entry_comment_id( $entry_id, 61 );

        $_POST['nonce']      = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['comment_id'] = '61';
        $_POST['category']   = 'spam';

        $wpdb->delete_result = false;
        $this->admin_page->ajax_mark_comment();
        $wpdb->delete_result = null;

        $this->assertFalse( $wp_json_responses[0]['success'] );
        // The claim is gone, so the moderator can click the pill again.
        $this->assertFalse( ANTISPAMWIZARD_Spam_Log::is_trained( 61 ) );

        $wp_json_responses = array();
        $this->admin_page->ajax_mark_comment();

        $this->assertTrue( $wp_json_responses[0]['success'] );
        $this->assertSame( array( $text ), $repository->find_all( 'latin', 'spam' ) );
        $this->assertSame( array(), $repository->find_all( 'latin', 'ham' ) );
    }

    /**
     * The pill cannot hold the body lock across its status change -- the
     * transition it fires would re-enter the lock through a second file handle
     * and block for the rest of the request -- so its reconciliation runs in a
     * second acquisition. Another moderation of the same text can commit the
     * opposite category in that window, inserting its row and deleting with its
     * own flip the row this request stored. Deleting the opposite blindly then
     * left the text trained as neither, with both log claims standing; the
     * superseded request stands down instead, exactly as a superseded transition
     * does.
     */
    public function test_a_superseded_pill_leaves_the_newer_decisions_sample_alone(): void {
        global $wp_json_responses, $wp_comments;

        $text       = 'One body two moderators disagree about.';
        $repository = ANTISPAMWIZARD::get_instance()->get_repository();

        $wp_comments[63] = array(
            'comment_ID'       => 63,
            'comment_content'  => $text,
            'comment_approved' => 'spam',
        );

        ANTISPAMWIZARD_Spam_Log::clear();
        $entry_id = ANTISPAMWIZARD_Spam_Log::add_entry( $text, array( 'links' ), 'hash' );
        ANTISPAMWIZARD_Spam_Log::update_entry_comment_id( $entry_id, 63 );

        // Fires from inside wp_unspam_comment(), i.e. between the pill's two lock
        // sections: another request trains the same body as spam from a different
        // comment and its flip delete removes the ham row the pill just stored.
        add_action(
            'transition_comment_status',
            static function () use ( $repository, $text ): void {
                $repository->insert( 'latin', 'spam', $text, 64, '' );
                $repository->delete_by_hash( 'latin', 'ham', hash( 'sha256', $text ) );
            }
        );

        $_POST['nonce']      = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['comment_id'] = '63';
        $_POST['category']   = 'ham';

        $this->admin_page->ajax_mark_comment();

        $this->assertTrue( $wp_json_responses[0]['success'] );
        // The newer decision survives; the text is still trained as exactly one
        // category rather than as neither.
        $this->assertSame( array( $text ), $repository->find_all( 'latin', 'spam' ) );
        $this->assertSame( array(), $repository->find_all( 'latin', 'ham' ) );
    }

    /**
     * A refused rollback write leaves the claim standing over a training that
     * was undone, and the pill cannot clear it -- mark_trained() refuses an entry
     * that already carries a category -- so the moderator is told to check the
     * training data rather than to try again, and the otherwise invisible loss is
     * logged the way ANTISPAMWIZARD_Moderation::roll_back_claim() logs its own.
     */
    public function test_a_refused_claim_rollback_on_a_failed_status_change_is_reported(): void {
        global $wp_json_responses, $wp_comments, $wpdb;

        $text = 'A body whose rollback the option store refuses.';

        $wp_comments[65] = array(
            'comment_ID'       => 65,
            'comment_content'  => $text,
            'comment_approved' => '1',
        );

        ANTISPAMWIZARD_Spam_Log::clear();
        $entry_id = ANTISPAMWIZARD_Spam_Log::add_entry( $text, array( 'links' ), 'hash' );
        ANTISPAMWIZARD_Spam_Log::update_entry_comment_id( $entry_id, 65 );

        $reflection = new ReflectionClass( ANTISPAMWIZARD::class );
        $repo       = $reflection->getProperty( 'repository' );
        $repo->setValue( ANTISPAMWIZARD::get_instance(), new ANTISPAMWIZARD_Late_Option_Breaking_Sample_Repository( $wpdb ) );

        $_POST['nonce']      = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['comment_id'] = '65';
        $_POST['category']   = 'spam';

        $GLOBALS['wp_comment_status_change_fails'] = true;

        $tmp     = tempnam( sys_get_temp_dir(), 'antispamwizard_test_' );
        $old_log = ini_set( 'error_log', $tmp );

        $this->admin_page->ajax_mark_comment();

        ini_set( 'error_log', $old_log );
        $logged = file_get_contents( $tmp );
        unlink( $tmp );

        $this->assertFalse( $wp_json_responses[0]['success'] );
        $this->assertStringContainsString( 'check training data manually', $wp_json_responses[0]['data']['message'] );
        $this->assertStringContainsString( 'rollback failed for comment 65', $logged );
    }

    /**
     * The same rule on the reconciliation path: the flip delete was refused, so
     * the text is stored as both categories, and the claim that would let the
     * pill clear it cannot be rolled back either. The status did change here, so
     * the message is the one that names neither the status nor a retry.
     */
    public function test_a_refused_rollback_after_a_refused_flip_delete_is_reported(): void {
        global $wp_json_responses, $wp_comments, $wpdb;

        $text = 'A body left trained as both categories at once.';

        // Already trained as ham from another comment, and already in the spam
        // queue, so the pill reconciles without changing any status.
        ANTISPAMWIZARD::get_instance()->get_repository()->insert( 'latin', 'ham', $text, 44, '' );

        $wp_comments[66] = array(
            'comment_ID'       => 66,
            'comment_content'  => $text,
            'comment_approved' => 'spam',
        );

        ANTISPAMWIZARD_Spam_Log::clear();
        $entry_id = ANTISPAMWIZARD_Spam_Log::add_entry( $text, array( 'links' ), 'hash' );
        ANTISPAMWIZARD_Spam_Log::update_entry_comment_id( $entry_id, 66 );

        $reflection = new ReflectionClass( ANTISPAMWIZARD::class );
        $repo       = $reflection->getProperty( 'repository' );
        $repo->setValue( ANTISPAMWIZARD::get_instance(), new ANTISPAMWIZARD_Late_Option_Breaking_Sample_Repository( $wpdb ) );

        $_POST['nonce']      = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['comment_id'] = '66';
        $_POST['category']   = 'spam';

        $tmp     = tempnam( sys_get_temp_dir(), 'antispamwizard_test_' );
        $old_log = ini_set( 'error_log', $tmp );

        $wpdb->delete_result = false;
        $this->admin_page->ajax_mark_comment();
        $wpdb->delete_result = null;

        ini_set( 'error_log', $old_log );
        $logged = file_get_contents( $tmp );
        unlink( $tmp );

        $this->assertFalse( $wp_json_responses[0]['success'] );
        $this->assertStringContainsString( 'may be inconsistent', $wp_json_responses[0]['data']['message'] );
        $this->assertStringContainsString( 'check training data manually', $wp_json_responses[0]['data']['message'] );
        $this->assertStringNotContainsString( 'status could not be changed', $wp_json_responses[0]['data']['message'] );
        $this->assertStringContainsString( 'rollback failed for comment 66', $logged );
    }

    /**
     * The pill and ANTISPAMWIZARD_Moderation's transition handler write the same rows, so
     * they have to serialise on the same per-body lock. Asserted on a comment
     * already at the target status, where no transition fires: the only writer
     * that can have created the file is the AJAX handler itself.
     */
    public function test_the_pill_takes_the_per_body_moderation_lock(): void {
        global $wp_comments;

        $upload_dir = wp_upload_dir();
        $lock_dir   = $upload_dir['basedir'] . '/.antispamwizard-locks';
        foreach ( glob( $lock_dir . '/antispamwizard_moderation_*.lock' ) ?: array() as $stale ) {
            unlink( $stale );
        }

        $text = 'A body the pill has to lock before it writes.';

        $wp_comments[62] = array(
            'comment_ID'       => 62,
            'comment_content'  => $text,
            'comment_approved' => 'spam',
        );

        ANTISPAMWIZARD_Spam_Log::clear();
        $entry_id = ANTISPAMWIZARD_Spam_Log::add_entry( $text, array( 'links' ), 'hash' );
        ANTISPAMWIZARD_Spam_Log::update_entry_comment_id( $entry_id, 62 );

        $_POST['nonce']      = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['comment_id'] = '62';
        $_POST['category']   = 'spam';

        $this->admin_page->ajax_mark_comment();

        $locks = glob( $lock_dir . '/antispamwizard_moderation_*.lock' ) ?: array();
        $this->assertSame(
            array( $lock_dir . '/antispamwizard_moderation_' . md5( ABSPATH ) . '_' . substr( md5( $text ), -2 ) . '.lock' ),
            $locks
        );
    }

    public function test_get_script_sample_counts_returns_correct_data(): void {
        $td     = new ANTISPAMWIZARD_Training_Data();
        $counts = $td->get_script_sample_counts();

        $this->assertIsArray( $counts );
        foreach ( $counts as $script => $data ) {
            $this->assertArrayHasKey( 'spam', $data );
            $this->assertArrayHasKey( 'ham', $data );
            $this->assertIsInt( $data['spam'] );
            $this->assertIsInt( $data['ham'] );
        }
    }

    public function test_render_general_tab_shows_loaded_models_section(): void {
        $_GET['page'] = 'antispam-wizard';
        ob_start();
        $this->admin_page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Loaded Models', $output );
        $this->assertStringContainsString( 'antispamwizard-models-table', $output );
        $this->assertStringContainsString( 'Script Group', $output );
        $this->assertStringContainsString( 'Spam Samples', $output );
        $this->assertStringContainsString( 'Ham Samples', $output );
    }
}
