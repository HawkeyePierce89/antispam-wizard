<?php
/**
 * Tests for ANTISPAMWIZARD_CF7_Integration.
 */

use PHPUnit\Framework\TestCase;

class CF7IntegrationTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        global $wp_options, $wp_actions, $wp_filters, $wp_enqueued_styles, $wp_transients;
        global $wp_cf7_current_form, $wp_cf7_form_id, $wp_cf7_form_tags, $wp_cf7_submission, $wp_cf7_submission_form, $wp_cf7_posted_data, $wp_cf7_meta, $wp_cf7_spam_log, $wp_cf7_is_available, $wp_cf7_remote_ip;
        $wp_options = array();
        $wp_actions = array();
        $wp_filters = array();
        $wp_enqueued_styles = array();
        $wp_transients = array();
        $wp_cf7_current_form = null;
        $wp_cf7_form_id = null;
        $wp_cf7_form_tags = null;
        $wp_cf7_submission = null;
        $wp_cf7_submission_form = null;
        $wp_cf7_posted_data = null;
        $wp_cf7_meta = array();
        $wp_cf7_spam_log = array();
        $wp_cf7_is_available = null;
        $wp_cf7_remote_ip = null;
        $_POST = array();
        ANTISPAMWIZARD_Spam_Log::clear();
        ANTISPAMWIZARD_Stats::clear();
        // Reset singleton.
        $reflection = new ReflectionClass( ANTISPAMWIZARD::class );
        $prop = $reflection->getProperty( 'instance' );
        $prop->setValue( null, null );
        // Ensure CF7 is considered available.
        unset( $GLOBALS['wp_cf7_is_available'] );
    }

    protected function tearDown(): void {
        global $wp_options, $wp_cf7_current_form, $wp_cf7_form_id, $wp_cf7_form_tags, $wp_cf7_submission, $wp_cf7_submission_form, $wp_cf7_posted_data, $wp_cf7_meta, $wp_cf7_spam_log;
        $wp_options = array();
        $wp_cf7_current_form = null;
        $wp_cf7_form_id = null;
        $wp_cf7_form_tags = null;
        $wp_cf7_submission = null;
        $wp_cf7_submission_form = null;
        $wp_cf7_posted_data = null;
        $wp_cf7_meta = array();
        $wp_cf7_spam_log = array();
        $_POST = array();
        ANTISPAMWIZARD_Spam_Log::clear();
        parent::tearDown();
    }

    private function make_form( int $id, array $tags ): WPCF7_ContactForm {
        $form = new WPCF7_ContactForm();
        $form->id_override = $id;
        $form->tags_override = $tags;
        $GLOBALS['wp_cf7_current_form'] = $form;
        $GLOBALS['wp_cf7_submission_form'] = $form;
        $GLOBALS['wp_cf7_form_id'] = $id;
        $GLOBALS['wp_cf7_form_tags'] = $tags;
        return $form;
    }

    private function make_submission( WPCF7_ContactForm $form, array $posted_data, string $remote_ip = '1.2.3.4' ): WPCF7_Submission {
        $sub = new WPCF7_Submission();
        $GLOBALS['wp_cf7_submission'] = $sub;
        $GLOBALS['wp_cf7_submission_form'] = $form;
        $GLOBALS['wp_cf7_posted_data'] = $posted_data;
        $GLOBALS['wp_cf7_meta'] = array( 'remote_ip' => $remote_ip );
        $GLOBALS['wp_cf7_spam_log'] = array();
        return $sub;
    }

    private function make_integration(): ANTISPAMWIZARD_CF7_Integration {
        ANTISPAMWIZARD::get_instance()->init();
        // filter_spam builds its own request-scoped detector via ANTISPAMWIZARD::build_checks.
        return new ANTISPAMWIZARD_CF7_Integration();
    }

    private function base_tags(): array {
        return array(
            new WPCF7_FormTag( 'your-message', 'textarea' ),
            new WPCF7_FormTag( 'your-name', 'text' ),
            new WPCF7_FormTag( 'your-email', 'email' ),
        );
    }

    public function test_spam_content_returns_true(): void {
        $form = $this->make_form( 10, $this->base_tags() );
        // Use stop-words spam: viagra triggers.
        $posted = array(
            'your-message' => 'buy viagra cheap',
            'your-name'    => 'spammer',
            'your-email'   => 'spam@example.com',
        );
        $sub = $this->make_submission( $form, $posted );
        // Provide valid token that is not too fresh (30s ago) and empty honeypot.
        $token = ANTISPAMWIZARD_Comment_Form::mint_token( time() - 30, 10 );
        $_POST[ ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD ] = $token;
        $_POST[ ANTISPAMWIZARD_Comment_Form::HONEYPOT_FIELD ] = '';
        $_POST['comment_post_ID'] = 10;

        $integration = $this->make_integration();
        $result = $integration->filter_spam( false, $sub );
        $this->assertTrue( $result );
        // Verify logging.
        $entries = ANTISPAMWIZARD_Spam_Log::get_entries();
        $this->assertCount( 1, $entries );
        $this->assertSame( 'form', $entries[0]['source'] );
        $this->assertSame( ANTISPAMWIZARD_Detector::hash_email( 'spam@example.com' ), $entries[0]['email_hash'] );
        global $wp_cf7_spam_log;
        $this->assertNotEmpty( $wp_cf7_spam_log );
        $this->assertSame( 'antispam-wizard', $wp_cf7_spam_log[0]['agent'] );
    }

    public function test_ham_content_returns_false(): void {
        $form = $this->make_form( 11, $this->base_tags() );
        $posted = array(
            'your-message' => 'Hello, I really enjoy reading your blog, great work!',
            'your-name'    => 'Alice',
            'your-email'   => 'alice@example.com',
        );
        $sub = $this->make_submission( $form, $posted );
        $token = ANTISPAMWIZARD_Comment_Form::mint_token( time() - 30, 11 );
        $_POST[ ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD ] = $token;
        $_POST[ ANTISPAMWIZARD_Comment_Form::HONEYPOT_FIELD ] = '';
        $_POST['comment_post_ID'] = 11;

        $integration = $this->make_integration();
        $result = $integration->filter_spam( false, $sub );
        $this->assertFalse( $result );
        $this->assertCount( 0, ANTISPAMWIZARD_Spam_Log::get_entries() );
    }

    public function test_honeypot_filled_returns_true(): void {
        $form = $this->make_form( 12, $this->base_tags() );
        $posted = array(
            'your-message' => 'Hello nice post',
            'your-name'    => 'Bob',
            'your-email'   => 'bob@example.com',
        );
        $sub = $this->make_submission( $form, $posted );
        $token = ANTISPAMWIZARD_Comment_Form::mint_token( time() - 30, 12 );
        $_POST[ ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD ] = $token;
        $_POST[ ANTISPAMWIZARD_Comment_Form::HONEYPOT_FIELD ] = 'filled by bot';
        $_POST['comment_post_ID'] = 12;

        $integration = $this->make_integration();
        $this->assertTrue( $integration->filter_spam( false, $sub ) );
    }

    public function test_fresh_token_below_threshold_returns_true(): void {
        $form = $this->make_form( 13, $this->base_tags() );
        $posted = array(
            'your-message' => 'Hello nice post',
            'your-name'    => 'Carol',
            'your-email'   => 'carol@example.com',
        );
        $sub = $this->make_submission( $form, $posted );
        // Fresh token (now)
        $token = ANTISPAMWIZARD_Comment_Form::mint_token( time(), 13 );
        $_POST[ ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD ] = $token;
        $_POST[ ANTISPAMWIZARD_Comment_Form::HONEYPOT_FIELD ] = '';
        $_POST['comment_post_ID'] = 13;

        $integration = $this->make_integration();
        $this->assertTrue( $integration->filter_spam( false, $sub ) );
    }

    public function test_missing_token_returns_false(): void {
        $form = $this->make_form( 14, $this->base_tags() );
        $posted = array(
            'your-message' => 'Hello nice post',
            'your-name'    => 'Dave',
            'your-email'   => 'dave@example.com',
        );
        $sub = $this->make_submission( $form, $posted );
        $_POST[ ANTISPAMWIZARD_Comment_Form::HONEYPOT_FIELD ] = '';
        // Do not set token.
        unset( $_POST[ ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD ] );
        $_POST['comment_post_ID'] = 14;

        $integration = $this->make_integration();
        $this->assertFalse( $integration->filter_spam( false, $sub ) );
    }

    public function test_malformed_token_returns_false(): void {
        $form = $this->make_form( 15, $this->base_tags() );
        $posted = array(
            'your-message' => 'Hello nice post',
            'your-name'    => 'Eve',
            'your-email'   => 'eve@example.com',
        );
        $sub = $this->make_submission( $form, $posted );
        $_POST[ ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD ] = 'malformed.token.value';
        $_POST[ ANTISPAMWIZARD_Comment_Form::HONEYPOT_FIELD ] = '';
        $_POST['comment_post_ID'] = 15;

        $integration = $this->make_integration();
        $this->assertFalse( $integration->filter_spam( false, $sub ) );
    }

    public function test_token_bound_to_different_form_returns_false(): void {
        $form = $this->make_form( 16, $this->base_tags() );
        $posted = array(
            'your-message' => 'Hello nice post',
            'your-name'    => 'Frank',
            'your-email'   => 'frank@example.com',
        );
        $sub = $this->make_submission( $form, $posted );
        // Token minted for different form ID 999
        $token = ANTISPAMWIZARD_Comment_Form::mint_token( time(), 999 );
        $_POST[ ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD ] = $token;
        $_POST[ ANTISPAMWIZARD_Comment_Form::HONEYPOT_FIELD ] = '';
        $_POST['comment_post_ID'] = 16;

        $integration = $this->make_integration();
        $this->assertFalse( $integration->filter_spam( false, $sub ) );
    }

    public function test_missing_honeypot_field_returns_false(): void {
        $form = $this->make_form( 17, $this->base_tags() );
        $posted = array(
            'your-message' => 'Hello ham',
            'your-name'    => 'Grace',
            'your-email'   => 'grace@example.com',
        );
        $sub = $this->make_submission( $form, $posted );
        $token = ANTISPAMWIZARD_Comment_Form::mint_token( time() - 30, 17 );
        $_POST[ ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD ] = $token;
        // Honeypot key not set at all.
        unset( $_POST[ ANTISPAMWIZARD_Comment_Form::HONEYPOT_FIELD ] );
        $_POST['comment_post_ID'] = 17;

        $integration = $this->make_integration();
        $this->assertFalse( $integration->filter_spam( false, $sub ) );
    }

    public function test_spam_already_true_passes_through(): void {
        $form = $this->make_form( 18, $this->base_tags() );
        $posted = array(
            'your-message' => 'viagra spam content',
            'your-name'    => 'spam',
            'your-email'   => 'spam@example.com',
        );
        $sub = $this->make_submission( $form, $posted );
        $_POST[ ANTISPAMWIZARD_Comment_Form::HONEYPOT_FIELD ] = 'filled';
        $_POST[ ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD ] = ANTISPAMWIZARD_Comment_Form::mint_token( time(), 18 );

        $integration = $this->make_integration();
        $this->assertTrue( $integration->filter_spam( true, $sub ) );
        $this->assertTrue( $integration->filter_spam( true, null ) );
        // No logging when already spam? Should not add new entry because early return.
        // But our honeypot-spam would have been true, but early return prevents logging.
        // So ensure no double log? Currently filter_spam returns true immediately without logging when $spam is true.
        // That's correct per spec.
    }

    public function test_enabled_off_returns_false(): void {
        ANTISPAMWIZARD_Settings::update( array( 'enabled' => false ) );
        $form = $this->make_form( 19, $this->base_tags() );
        $posted = array(
            'your-message' => 'viagra spam',
            'your-name'    => 'spam',
            'your-email'   => 'spam@example.com',
        );
        $sub = $this->make_submission( $form, $posted );
        $_POST[ ANTISPAMWIZARD_Comment_Form::HONEYPOT_FIELD ] = 'filled';
        $_POST[ ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD ] = ANTISPAMWIZARD_Comment_Form::mint_token( time(), 19 );

        $integration = $this->make_integration();
        $this->assertFalse( $integration->filter_spam( false, $sub ) );
    }

    public function test_cf7_enabled_off_returns_false(): void {
        ANTISPAMWIZARD_Settings::update( array( 'cf7_enabled' => false ) );
        $form = $this->make_form( 20, $this->base_tags() );
        $posted = array(
            'your-message' => 'viagra spam',
            'your-name'    => 'spam',
            'your-email'   => 'spam@example.com',
        );
        $sub = $this->make_submission( $form, $posted );
        $_POST[ ANTISPAMWIZARD_Comment_Form::HONEYPOT_FIELD ] = 'filled';
        $_POST[ ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD ] = ANTISPAMWIZARD_Comment_Form::mint_token( time(), 20 );

        $integration = $this->make_integration();
        $this->assertFalse( $integration->filter_spam( false, $sub ) );
    }

    public function test_adapter_collects_textarea_text_only(): void {
        $tags = array(
            new WPCF7_FormTag( 'your-message', 'textarea' ),
            new WPCF7_FormTag( 'your-name', 'text' ),
            new WPCF7_FormTag( 'your-email', 'email' ),
            new WPCF7_FormTag( 'your-url', 'url' ),
            new WPCF7_FormTag( 'my-select', 'select' ),
            new WPCF7_FormTag( 'my-checkbox', 'checkbox' ),
            new WPCF7_FormTag( 'my-hidden', 'hidden' ),
        );
        $form = $this->make_form( 21, $tags );
        $posted = array(
            'your-message' => 'Hello world',
            'your-name'    => 'John Doe',
            'your-email'   => 'john@example.com',
            'your-url'     => 'http://example.com',
            'my-select'    => 'option1',
            'my-checkbox'  => array( 'a', 'b' ),
            'my-hidden'    => 'secret',
        );
        $sub = $this->make_submission( $form, $posted );
        $integration = $this->make_integration();
        $data = $integration->collect_submission( $sub );
        $this->assertNotNull( $data );
        // Should contain only textarea+text joined.
        $this->assertSame( "Hello world\n\nJohn Doe", $data['comment_content'] );
        // Ensure excluded values not present.
        $this->assertStringNotContainsString( 'http://example.com', $data['comment_content'] );
        $this->assertStringNotContainsString( 'option1', $data['comment_content'] );
        $this->assertStringNotContainsString( 'secret', $data['comment_content'] );
        $this->assertSame( 'john@example.com', $data['comment_author_email'] );
        $this->assertSame( 'John Doe', $data['comment_author'] );
    }

    public function test_adapter_ignores_non_scalar_values_in_content(): void {
        $tags = array(
            new WPCF7_FormTag( 'message', 'textarea' ),
            new WPCF7_FormTag( 'name', 'text' ),
        );
        $form = $this->make_form( 22, $tags );
        $posted = array(
            'message' => array( 'bad' ),
            'name'    => 'Alice',
        );
        $sub = $this->make_submission( $form, $posted );
        $integration = $this->make_integration();
        $data = $integration->collect_submission( $sub );
        // Array values are flattened and joined so spam cannot bypass by sending text[].
        $this->assertSame( "bad\n\nAlice", $data['comment_content'] );
    }

    public function test_email_taken_from_email_tag(): void {
        $tags = array(
            new WPCF7_FormTag( 'my-email', 'email' ),
            new WPCF7_FormTag( 'other-email', 'email' ),
            new WPCF7_FormTag( 'message', 'textarea' ),
        );
        $form = $this->make_form( 23, $tags );
        $posted = array(
            'my-email'    => 'first@example.com',
            'other-email' => 'second@example.com',
            'message'     => 'hi',
        );
        $sub = $this->make_submission( $form, $posted );
        $integration = $this->make_integration();
        $data = $integration->collect_submission( $sub );
        $this->assertSame( 'first@example.com', $data['comment_author_email'] );
        // Invalid email should be skipped.
        $posted2 = array(
            'my-email'    => 'not-an-email',
            'other-email' => 'valid@example.com',
            'message'     => 'hi',
        );
        $GLOBALS['wp_cf7_posted_data'] = $posted2;
        $data2 = $integration->collect_submission( $sub );
        $this->assertSame( 'valid@example.com', $data2['comment_author_email'] );
    }

    public function test_token_minted_by_render_fields_verifies(): void {
        $form = $this->make_form( 24, $this->base_tags() );
        $integration = $this->make_integration();
        $elements = '<p>form html</p>';
        $result = $integration->render_fields( $elements );
        $this->assertStringContainsString( $elements, $result );
        $this->assertStringContainsString( ANTISPAMWIZARD_Comment_Form::HONEYPOT_FIELD, $result );
        $this->assertStringContainsString( ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD, $result );
        preg_match( '/value="([0-9]+\.[a-f0-9]+)"/', $result, $m );
        $this->assertNotEmpty( $m );
        $token = $m[1];
        $this->assertNotNull( ANTISPAMWIZARD_Comment_Form::parse_token( $token, 24 ) );
        $this->assertNull( ANTISPAMWIZARD_Comment_Form::parse_token( $token, 999 ) );
    }

    public function test_render_returns_unchanged_when_protection_off(): void {
        // Global disabled.
        ANTISPAMWIZARD_Settings::update( array( 'enabled' => false ) );
        $form = $this->make_form( 25, $this->base_tags() );
        $integration = $this->make_integration();
        $elements = '<p>original</p>';
        $this->assertSame( $elements, $integration->render_fields( $elements ) );

        // Reset and test cf7_enabled off.
        ANTISPAMWIZARD_Settings::update( array( 'enabled' => true, 'cf7_enabled' => false ) );
        $this->assertSame( $elements, $integration->render_fields( $elements ) );

        // No current form.
        ANTISPAMWIZARD_Settings::update( array( 'cf7_enabled' => true ) );
        $GLOBALS['wp_cf7_current_form'] = null;
        $GLOBALS['wp_cf7_form_id'] = 0;
        $this->assertSame( $elements, $integration->render_fields( $elements ) );
    }

    public function test_throwing_cf7_object_is_caught_and_passes(): void {
        // Create a submission whose get_contact_form throws.
        $throwing_sub = new class {
            public function get_contact_form() {
                throw new RuntimeException( 'boom' );
            }
            public function get_posted_data() { return array(); }
            public function get_meta( $k ) { return ''; }
            public function add_spam_log( $a ) {}
        };
        $integration = $this->make_integration();
        // Should not fatal, return false (fail-open).
        $this->assertFalse( $integration->filter_spam( false, $throwing_sub ) );

        // Also test collect_submission with throwing tags.
        $throwing_form = new class {
            public function id() { return 1; }
            public function scan_form_tags() { throw new RuntimeException( 'tag boom' ); }
        };
        $throwing_sub2 = new class( $throwing_form ) {
            private $form;
            public function __construct( $f ) { $this->form = $f; }
            public function get_contact_form() { return $this->form; }
            public function get_posted_data() { return array( 'a' => 'b' ); }
            public function get_meta( $k ) { return ''; }
        };
        $this->assertNull( $integration->collect_submission( $throwing_sub2 ) );
        $this->assertFalse( $integration->filter_spam( false, $throwing_sub2 ) );
    }

    public function test_is_available_true_when_stubs_present(): void {
        $this->assertTrue( ANTISPAMWIZARD_CF7_Integration::is_available() );
    }

    public function test_is_available_false_when_forced(): void {
        $GLOBALS['wp_cf7_is_available'] = false;
        $this->assertFalse( ANTISPAMWIZARD_CF7_Integration::is_available() );
        unset( $GLOBALS['wp_cf7_is_available'] );
        // Comments pipeline unaffected when CF7 absent: detector still works.
        $detector = new ANTISPAMWIZARD_Detector( array() );
        $result = $detector->filter_comment( 1, array( 'comment_content' => 'hello', 'comment_author_IP' => '1.2.3.4' ) );
        $this->assertSame( 1, $result );
    }

    public function test_collect_submission_returns_null_when_missing_form(): void {
        $integration = $this->make_integration();
        $sub_no_form = new class {
            public function get_contact_form() { return null; }
            public function get_posted_data() { return array( 'a' => 'b' ); }
            public function get_meta( $k ) { return ''; }
        };
        $this->assertNull( $integration->collect_submission( $sub_no_form ) );
        // filter_spam should fail-open.
        $this->assertFalse( $integration->filter_spam( false, $sub_no_form ) );
    }

    public function test_collect_submission_returns_null_when_tags_empty(): void {
        $form = $this->make_form( 26, array() );
        $GLOBALS['wp_cf7_form_tags'] = array();
        $posted = array( 'a' => 'b' );
        $sub = $this->make_submission( $form, $posted );
        $integration = $this->make_integration();
        $this->assertNull( $integration->collect_submission( $sub ) );
        $this->assertFalse( $integration->filter_spam( false, $sub ) );
    }

    public function test_collect_submission_returns_null_when_posted_data_not_array(): void {
        $form = $this->make_form( 27, $this->base_tags() );
        $sub = new class( $form ) {
            private $f;
            public function __construct( $f ) { $this->f = $f; }
            public function get_contact_form() { return $this->f; }
            public function get_posted_data() { return null; }
            public function get_meta( $k ) { return ''; }
        };
        $integration = $this->make_integration();
        $this->assertNull( $integration->collect_submission( $sub ) );
    }

    public function test_filter_spam_resolves_submission_via_get_instance_when_null(): void {
        $form = $this->make_form( 28, $this->base_tags() );
        $posted = array(
            'your-message' => 'viagra spam',
            'your-name'    => 'spam',
            'your-email'   => 'spam@example.com',
        );
        $sub = $this->make_submission( $form, $posted );
        $GLOBALS['wp_cf7_submission'] = $sub;
        $token = ANTISPAMWIZARD_Comment_Form::mint_token( time() - 30, 28 );
        $_POST[ ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD ] = $token;
        $_POST[ ANTISPAMWIZARD_Comment_Form::HONEYPOT_FIELD ] = '';
        $_POST['comment_post_ID'] = 28;

        $integration = $this->make_integration();
        // Pass null as second arg, it should resolve via get_instance().
        $this->assertTrue( $integration->filter_spam( false, null ) );
    }

    public function test_filter_spam_returns_false_when_no_submission_resolvable(): void {
        $GLOBALS['wp_cf7_submission'] = null;
        $integration = $this->make_integration();
        $this->assertFalse( $integration->filter_spam( false, null ) );
    }

    public function test_enqueue_styles(): void {
        global $wp_enqueued_styles;
        $integration = $this->make_integration();
        $this->assertTrue( $integration->enqueue_styles( true ) );
        $this->assertArrayHasKey( 'antispamwizard-comment-form', $wp_enqueued_styles );
        // Filter passthrough: false stays false but still enqueues when enabled.
        $wp_enqueued_styles = array();
        $this->assertFalse( $integration->enqueue_styles( false ) );
        $this->assertArrayHasKey( 'antispamwizard-comment-form', $wp_enqueued_styles );

        // Both bot checks disabled → not enqueued.
        ANTISPAMWIZARD_Settings::update( array( 'honeypot_enabled' => false, 'submit_time_enabled' => false ) );
        $wp_enqueued_styles = array();
        $this->assertTrue( $integration->enqueue_styles( true ) );
        $this->assertArrayNotHasKey( 'antispamwizard-comment-form', $wp_enqueued_styles );

        // cf7_enabled off → not enqueued.
        ANTISPAMWIZARD_Settings::update( array( 'honeypot_enabled' => true, 'submit_time_enabled' => true, 'cf7_enabled' => false ) );
        $wp_enqueued_styles = array();
        $this->assertTrue( $integration->enqueue_styles( true ) );
        $this->assertArrayNotHasKey( 'antispamwizard-comment-form', $wp_enqueued_styles );

        // Global enabled off → not enqueued.
        ANTISPAMWIZARD_Settings::update( array( 'enabled' => false, 'cf7_enabled' => true ) );
        $wp_enqueued_styles = array();
        $this->assertTrue( $integration->enqueue_styles( true ) );
        $this->assertArrayNotHasKey( 'antispamwizard-comment-form', $wp_enqueued_styles );
    }

    public function test_register_hooks(): void {
        global $wp_filters, $wp_actions;
        $integration = $this->make_integration();
        $integration->register_hooks();
        $this->assertArrayHasKey( 'wpcf7_form_elements', $wp_filters );
        $this->assertArrayHasKey( 'wpcf7_enqueue_styles', $wp_filters );
        $this->assertArrayHasKey( 'wpcf7_spam', $wp_filters );
        // The stylesheet is registered only through CF7's own enqueue filter —
        // the integration never hooks wp_enqueue_scripts site-wide. (That action
        // may still carry ANTISPAMWIZARD_Comment_Form's own callback, which is unrelated.)
        foreach ( ( $wp_actions['wp_enqueue_scripts'] ?? array() ) as $a ) {
            $this->assertNotSame( array( $integration, 'enqueue_styles' ), $a['callback'] );
        }
        // Check priority 100 for form_elements.
        $found = false;
        foreach ( $wp_filters['wpcf7_form_elements'] as $f ) {
            if ( $f['callback'] === array( $integration, 'render_fields' ) && 100 === $f['priority'] ) {
                $found = true;
            }
        }
        $this->assertTrue( $found );
    }

    public function test_logging_uses_normalized_content_and_hashed_ip(): void {
        $form = $this->make_form( 29, $this->base_tags() );
        $long_content = str_repeat( 'a', 70000 );
        $posted = array(
            'your-message' => $long_content,
            'your-name'    => 'test',
            'your-email'   => 'test@example.com',
        );
        $sub = $this->make_submission( $form, $posted, '9.9.9.9' );
        // Force spam via links? Use long content truncated but still ham? Let's force via honeypot.
        $_POST[ ANTISPAMWIZARD_Comment_Form::HONEYPOT_FIELD ] = 'bot';
        $_POST[ ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD ] = ANTISPAMWIZARD_Comment_Form::mint_token( time() - 30, 29 );
        $_POST['comment_post_ID'] = 29;

        $integration = $this->make_integration();
        $this->assertTrue( $integration->filter_spam( false, $sub ) );
        $entries = ANTISPAMWIZARD_Spam_Log::get_entries();
        $this->assertCount( 1, $entries );
        // Normalized content should be truncated to MAX_COMMENT_LENGTH (65536) then excerpt 100, but we store excerpt only.
        // Check that stored text_excerpt is 100 chars, not full 70k.
        $this->assertSame( 100, mb_strlen( $entries[0]['text_excerpt'] ) );
        // IP hash should be SHA-256 of salt+IP.
        $this->assertSame( ANTISPAMWIZARD_Detector::hash_ip( '9.9.9.9' ), $entries[0]['ip_hash'] );
        $this->assertSame( 'form', $entries[0]['source'] );
        $this->assertSame( ANTISPAMWIZARD_Detector::hash_email( 'test@example.com' ), $entries[0]['email_hash'] );
        $this->assertSame( 0, $entries[0]['comment_id'] );
    }

    public function test_build_checks_request_param_injects_into_bot_checks(): void {
        $req = array(
            ANTISPAMWIZARD_Comment_Form::HONEYPOT_FIELD => 'some',
            ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD => ANTISPAMWIZARD_Comment_Form::mint_token( time(), 99 ),
            'comment_post_ID' => 99,
        );
        $checks = ANTISPAMWIZARD::get_instance()->build_checks( $req );
        $this->assertInstanceOf( ANTISPAMWIZARD_Honeypot_Check::class, $checks[0] );
        $this->assertInstanceOf( ANTISPAMWIZARD_Submit_Time_Check::class, $checks[1] );
        // Verify that the injected request causes honeypot to flag.
        $result = $checks[0]->check( array( 'comment_content' => 'hi' ) );
        $this->assertTrue( $result->is_spam() );
        // Also verify submit_time flags fresh token.
        $result2 = $checks[1]->check( array( 'comment_content' => 'hi', 'comment_post_ID' => 99 ) );
        // Actually submit_time check reads from request array's comment_post_ID, not comment_data.
        // But we passed request with comment_post_ID 99, token minted for 99, elapsed 0 <5 → spam.
        $this->assertTrue( $result2->is_spam() );

        // Without request, both should be fail-open (empty request missing fields).
        $checks2 = ANTISPAMWIZARD::get_instance()->build_checks();
        $result3 = $checks2[0]->check( array( 'comment_content' => 'hi' ) );
        // Honeypot absent → not spam
        $this->assertFalse( $result3->is_spam() );
    }

    public function test_filter_spam_diagnostic_omits_the_exception_message(): void {
        // Same rule as ANTISPAMWIZARD_Detector::run(): filter_spam()'s try wraps evaluate(),
        // ANTISPAMWIZARD_Spam_Log::add_entry() and ANTISPAMWIZARD_Stats::record(), so an exception
        // message there can carry a $wpdb query or a lock-file path, and
        // debug.log is web-readable on misconfigured hosts. Forcing a throw past
        // the inner fail-open catches is not reachable through the stubs, so the
        // rule is pinned structurally instead of vacuously.
        $source = file_get_contents( dirname( __DIR__ ) . '/antispam-wizard/includes/class-cf7-integration.php' );
        $this->assertIsString( $source );

        $start = strpos( $source, 'public function filter_spam(' );
        $this->assertNotFalse( $start, 'ANTISPAMWIZARD_CF7_Integration::filter_spam() not found' );
        $end  = strpos( $source, "\n    public function", $start + 1 );
        $body = false === $end ? substr( $source, $start ) : substr( $source, $start, $end - $start );

        $this->assertStringContainsString( 'CF7 filter_spam failed', $body );
        $this->assertStringNotContainsString(
            'getMessage()',
            $body,
            'filter_spam() must log only the throw site, never the exception message'
        );

        // Grepping for getMessage() alone is not enough: `. $e` or "{$e}" stringifies
        // the exception, which carries the message AND the full stack trace, and would
        // pass a name-only check unchanged. Enumerate every use of the caught variable
        // instead and require each one to be an allowed accessor. The catch clause
        // itself is stripped first - its bare $e is the declaration, not a use.
        $scan = str_replace( 'catch ( \Throwable $e )', '', $body );
        preg_match_all( '/\$e\b(?:\s*->\s*\w+\s*\(\s*\))?/', $scan, $uses );
        $this->assertNotEmpty( $uses[0], 'expected filter_spam() to reference the caught exception' );
        foreach ( $uses[0] as $use ) {
            $this->assertContains(
                $use,
                array( '$e->getFile()', '$e->getLine()' ),
                'filter_spam() may only read getFile()/getLine() off the exception; ' . $use . ' can disclose the message or stack trace'
            );
        }
    }
}
