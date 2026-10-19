<?php

use PHPUnit\Framework\TestCase;

class HoneypotCheckTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        global $wp_options;
        $wp_options = array();
        $_POST      = array();
    }

    protected function tearDown(): void {
        global $wp_options;
        $wp_options = array();
        // Reset here rather than at the end of each test: an assertion failure
        // throws before a trailing statement runs, leaking the value into every
        // later test in the run.
        $_POST = array();
        parent::tearDown();
    }

    private function get_check_with_request( array $request ): ASW_Honeypot_Check {
        return new ASW_Honeypot_Check( $request );
    }

    public function test_filled_is_spam(): void {
        $check = $this->get_check_with_request( array(
            ASW_Comment_Form::HONEYPOT_FIELD => 'bot_value'
        ) );

        $result = $check->check( array( 'comment_type' => 'comment' ) );
        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'honeypot', $result->get_check_name() );
        $this->assertSame( 'honeypot filled', $result->get_details() );
    }

    public function test_empty_string_passes(): void {
        $check = $this->get_check_with_request( array(
            ASW_Comment_Form::HONEYPOT_FIELD => ''
        ) );

        $result = $check->check( array( 'comment_type' => 'comment' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'field empty', $result->get_details() );
    }

    public function test_array_value_is_spam(): void {
        $check = $this->get_check_with_request( array(
            ASW_Comment_Form::HONEYPOT_FIELD => array( 'bot' => 'value' )
        ) );

        $result = $check->check( array( 'comment_type' => 'comment' ) );
        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'honeypot', $result->get_check_name() );
        $this->assertSame( 'honeypot filled', $result->get_details() );
    }

    public function test_whitespace_only_passes(): void {
        // Whitespace can be injected by autofill or clipboard quirks; only a
        // real value counts as a filled honeypot.
        $check = $this->get_check_with_request( array(
            ASW_Comment_Form::HONEYPOT_FIELD => "   \t \n "
        ) );

        $result = $check->check( array( 'comment_type' => 'comment' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'field empty', $result->get_details() );
    }

    public function test_field_missing_passes(): void {
        $check = $this->get_check_with_request( array() );

        $result = $check->check( array( 'comment_type' => 'comment' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'field absent', $result->get_details() );
    }

    public function test_disabled_passes(): void {
        ASW_Settings::update( array( 'honeypot_enabled' => false ) );

        $check = $this->get_check_with_request( array(
            ASW_Comment_Form::HONEYPOT_FIELD => 'bot_value'
        ) );

        $result = $check->check( array( 'comment_type' => 'comment' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'check disabled', $result->get_details() );
    }

    public function test_trackback_passes(): void {
        $check = $this->get_check_with_request( array(
            ASW_Comment_Form::HONEYPOT_FIELD => 'bot_value'
        ) );

        $result = $check->check( array( 'comment_type' => 'trackback' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'skipped non-comment type', $result->get_details() );
    }

    public function test_pingback_passes(): void {
        $check = $this->get_check_with_request( array(
            ASW_Comment_Form::HONEYPOT_FIELD => 'bot_value'
        ) );

        $result = $check->check( array( 'comment_type' => 'pingback' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'skipped non-comment type', $result->get_details() );
    }

    public function test_details_string_contains_no_submitted_value(): void {
        $secret = 'this_is_a_secret_submitted_value';
        $check = $this->get_check_with_request( array(
            ASW_Comment_Form::HONEYPOT_FIELD => $secret
        ) );

        $result = $check->check( array( 'comment_type' => 'comment' ) );
        $this->assertTrue( $result->is_spam() );

        $this->assertStringNotContainsString( $secret, $result->get_details() );
    }

    public function test_falls_back_to_post(): void {
        $_POST[ASW_Comment_Form::HONEYPOT_FIELD] = 'global_value';
        $check = new ASW_Honeypot_Check();

        $result = $check->check( array( 'comment_type' => 'comment' ) );

        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'honeypot filled', $result->get_details() );

        unset( $_POST[ASW_Comment_Form::HONEYPOT_FIELD] );
    }

    public function test_empty_comment_type_treated_as_comment(): void {
        $check = $this->get_check_with_request( array(
            ASW_Comment_Form::HONEYPOT_FIELD => 'value'
        ) );

        $result = $check->check( array() ); // comment_type not set
        $this->assertTrue( $result->is_spam() );

        $result = $check->check( array( 'comment_type' => '' ) );
        $this->assertTrue( $result->is_spam() );
    }

    /**
     * Regression guard for the phpcs annotation on the $_POST fallback: the
     * honeypot value is deliberately NOT run through sanitize_text_field().
     * A bot that fills the field with nothing but HTML tags would sanitize to
     * an empty string and slip through as "field empty".
     */
    public function test_tags_only_value_is_spam(): void {
        $check = $this->get_check_with_request( array(
            ASW_Comment_Form::HONEYPOT_FIELD => '<a href="http://spam.example"></a><br>'
        ) );

        $result = $check->check( array( 'comment_type' => 'comment' ) );
        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'honeypot filled', $result->get_details() );
    }

    /**
     * With no injected request the check falls back to $_POST, which WordPress
     * delivers slashed, so wp_unslash() must still be applied per field. A lone
     * backslash unslashes to the empty string: without the unslash it would read
     * as a filled honeypot and flag a legitimate commenter.
     */
    public function test_post_fallback_unslashes_value(): void {
        $_POST = array( ASW_Comment_Form::HONEYPOT_FIELD => '\\' );

        $check  = new ASW_Honeypot_Check();
        $result = $check->check( array( 'comment_type' => 'comment' ) );

        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'field empty', $result->get_details() );
    }

    /**
     * The $_POST fallback flags a genuinely filled honeypot, proving the path
     * is wired up and not just failing open on everything.
     */
    public function test_post_fallback_filled_value_is_spam(): void {
        $_POST = array( ASW_Comment_Form::HONEYPOT_FIELD => 'bot_value' );

        $check  = new ASW_Honeypot_Check();
        $result = $check->check( array( 'comment_type' => 'comment' ) );

        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'honeypot filled', $result->get_details() );
    }
}
