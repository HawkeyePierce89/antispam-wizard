<?php

use PHPUnit\Framework\TestCase;

class SubmitTimeCheckTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        global $wp_options;
        $wp_options = array();
        $_POST      = array();
        ANTISPAMWIZARD_Settings::update( array(
            'submit_time_enabled' => true,
            'submit_time_min_seconds' => 10,
        ) );
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

    private function get_check_with_request( array $request ): ANTISPAMWIZARD_Submit_Time_Check {
        return new ANTISPAMWIZARD_Submit_Time_Check( $request );
    }

    public function test_freshly_minted_token_with_min_10_is_spam(): void {
        $token = ANTISPAMWIZARD_Comment_Form::mint_token();
        $check = $this->get_check_with_request( array(
            ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD => $token
        ) );

        $result = $check->check( array( 'comment_type' => 'comment' ) );
        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'submit_time', $result->get_check_name() );
        $this->assertMatchesRegularExpression( '/submitted in \d+s \(min 10s\)/', $result->get_details() );
    }

    public function test_token_minted_120s_ago_passes(): void {
        $token = ANTISPAMWIZARD_Comment_Form::mint_token( time() - 120 );
        $check = $this->get_check_with_request( array(
            ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD => $token
        ) );

        $result = $check->check( array( 'comment_type' => 'comment' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'elapsed time acceptable', $result->get_details() );
    }

    public function test_missing_token_passes(): void {
        $check = $this->get_check_with_request( array() );

        $result = $check->check( array( 'comment_type' => 'comment' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'token absent', $result->get_details() );
    }

    public function test_malformed_token_passes(): void {
        // Fail-open: a malformed token can come from a plugin/theme mangling
        // hidden fields or a cached page from before activation — never spam.
        $check = $this->get_check_with_request( array(
            ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD => 'not_a_token'
        ) );

        $result = $check->check( array( 'comment_type' => 'comment' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'token malformed or tampered', $result->get_details() );
    }

    public function test_tampered_hmac_passes(): void {
        // Fail-open: an HMAC mismatch also occurs for every legitimate visitor
        // after a WordPress salt rotation while pages are still cached.
        $token = ANTISPAMWIZARD_Comment_Form::mint_token( time() - 120 );
        $token .= 'tamper';
        $check = $this->get_check_with_request( array(
            ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD => $token
        ) );

        $result = $check->check( array( 'comment_type' => 'comment' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'token malformed or tampered', $result->get_details() );
    }

    public function test_array_token_passes(): void {
        $check = $this->get_check_with_request( array(
            ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD => array( 'tamper' => 'yes' )
        ) );

        $result = $check->check( array( 'comment_type' => 'comment' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'token malformed or tampered', $result->get_details() );
    }

    public function test_future_timestamp_passes(): void {
        $token = ANTISPAMWIZARD_Comment_Form::mint_token( time() + 120 );
        $check = $this->get_check_with_request( array(
            ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD => $token
        ) );

        $result = $check->check( array( 'comment_type' => 'comment' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'future timestamp', $result->get_details() );
    }

    public function test_disabled_passes(): void {
        ANTISPAMWIZARD_Settings::update( array( 'submit_time_enabled' => false ) );

        $token = ANTISPAMWIZARD_Comment_Form::mint_token();
        $check = $this->get_check_with_request( array(
            ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD => $token
        ) );

        $result = $check->check( array( 'comment_type' => 'comment' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'check disabled', $result->get_details() );
    }

    public function test_non_comment_type_with_fresh_token_passes(): void {
        $token = ANTISPAMWIZARD_Comment_Form::mint_token();
        $check = $this->get_check_with_request( array(
            ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD => $token
        ) );

        $result = $check->check( array( 'comment_type' => 'trackback' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'skipped non-comment type', $result->get_details() );
    }

    /**
     * Regression guard for the phpcs annotation on the $_POST fallback: with no
     * injected request the check reads $_POST directly and the HMAC token still
     * verifies. WordPress delivers $_POST slashed; the token alphabet (digits,
     * a dot and lowercase hex) is untouched by slashing, and wp_unslash() keeps
     * it that way, so verification must not have been weakened by a sanitizer.
     */
    public function test_post_fallback_verifies_token(): void {
        $_POST = array(
            ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD => ANTISPAMWIZARD_Comment_Form::mint_token( time() - 120 ),
        );

        $check  = new ANTISPAMWIZARD_Submit_Time_Check();
        $result = $check->check( array( 'comment_type' => 'comment' ) );

        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'elapsed time acceptable', $result->get_details() );
    }

    /**
     * The same fallback path flags a freshly minted token, proving it is wired
     * up rather than failing open on everything.
     */
    public function test_post_fallback_flags_fresh_token(): void {
        $_POST = array(
            ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD => ANTISPAMWIZARD_Comment_Form::mint_token(),
        );

        $check  = new ANTISPAMWIZARD_Submit_Time_Check();
        $result = $check->check( array( 'comment_type' => 'comment' ) );

        $this->assertTrue( $result->is_spam() );
        $this->assertMatchesRegularExpression( '/submitted in \\d+s \\(min 10s\\)/', $result->get_details() );
    }

    /**
     * A token that arrives slashed (as WordPress hands $_POST over) is unslashed
     * before the HMAC comparison, so a tampered/escaped token fails closed to
     * "malformed" rather than being accepted.
     */
    public function test_post_fallback_rejects_slashed_tampered_token(): void {
        $_POST = array(
            ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD => addslashes( '123456789.\'deadbeef\'' ),
        );

        $check  = new ANTISPAMWIZARD_Submit_Time_Check();
        $result = $check->check( array( 'comment_type' => 'comment' ) );

        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'token malformed or tampered', $result->get_details() );
    }
}
