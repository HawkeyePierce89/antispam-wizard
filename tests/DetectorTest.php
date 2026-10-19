<?php
/**
 * Tests for ASW_Detector.
 */

use PHPUnit\Framework\TestCase;

class DetectorTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        // Reset global state.
        global $wp_actions, $wp_filters, $wp_comment_meta, $wp_options;
        $wp_actions      = array();
        $wp_filters      = array();
        $wp_comment_meta = array();
        $wp_options      = array();
    }

    /**
     * Helper: create a mock check that returns a fixed result.
     */
    private function make_check( bool $spam, string $name = 'test', string $details = '' ): ASW_Check {
        return new class( $spam, $name, $details ) implements ASW_Check {
            private $spam;
            private $name;
            private $details;

            public function __construct( bool $spam, string $name, string $details ) {
                $this->spam    = $spam;
                $this->name    = $name;
                $this->details = $details;
            }

            public function check( array $comment_data ): ASW_Check_Result {
                return new ASW_Check_Result( $this->spam, $this->name, $this->details );
            }
        };
    }

    public function test_run_returns_results_from_all_checks(): void {
        $detector = new ASW_Detector( array(
            $this->make_check( false, 'check_a' ),
            $this->make_check( true, 'check_b', 'found spam' ),
        ) );

        $results = $detector->run( array( 'comment_content' => 'hello' ) );

        $this->assertCount( 2, $results );
        $this->assertFalse( $results[0]->is_spam() );
        $this->assertTrue( $results[1]->is_spam() );
        $this->assertEquals( 'check_a', $results[0]->get_check_name() );
        $this->assertEquals( 'check_b', $results[1]->get_check_name() );
    }

    public function test_run_with_no_checks_returns_empty(): void {
        $detector = new ASW_Detector( array() );
        $results  = $detector->run( array( 'comment_content' => 'hello' ) );

        $this->assertCount( 0, $results );
    }

    public function test_run_all_ham(): void {
        $detector = new ASW_Detector( array(
            $this->make_check( false, 'check_a' ),
            $this->make_check( false, 'check_b' ),
        ) );

        $results = $detector->run( array( 'comment_content' => 'legit comment' ) );

        foreach ( $results as $result ) {
            $this->assertFalse( $result->is_spam() );
        }
    }

    public function test_filter_comment_returns_spam_when_check_detects_spam(): void {
        $detector = new ASW_Detector( array(
            $this->make_check( true, 'links', 'too many links' ),
        ) );

        $commentdata = array(
            'comment_content'   => 'buy now http://spam.com http://spam2.com http://spam3.com',
            'comment_author_IP' => '192.168.1.1',
        );

        $result = $detector->filter_comment( 1, $commentdata );

        $this->assertEquals( 'spam', $result );
    }

    public function test_filter_comment_passes_through_for_ham(): void {
        $detector = new ASW_Detector( array(
            $this->make_check( false, 'links' ),
        ) );

        $commentdata = array(
            'comment_content'   => 'great post!',
            'comment_author_IP' => '192.168.1.1',
        );

        $result = $detector->filter_comment( 1, $commentdata );

        $this->assertEquals( 1, $result );
    }

    public function test_filter_comment_stores_reason_in_comment_meta(): void {
        global $wp_actions;

        // With short-circuit, only the first spam check's reason is recorded.
        $detector = new ASW_Detector( array(
            $this->make_check( true, 'links', 'too many links' ),
            $this->make_check( true, 'emoji', '5 emoji found' ),
        ) );

        $commentdata = array(
            'comment_content'   => 'spam content',
            'comment_author_IP' => '10.0.0.1',
        );

        $detector->filter_comment( 1, $commentdata );

        // Simulate WordPress calling comment_post action.
        $this->assertArrayHasKey( 'comment_post', $wp_actions );
        do_action( 'comment_post', 42 );

        $meta = get_comment_meta( 42, '_asw_reason', true );
        $this->assertStringContainsString( 'links: too many links', $meta );
    }

    public function test_the_first_flag_records_the_shadow_display_cutoff(): void {
        $detector = new ASW_Detector( array( $this->make_check( true, 'links', 'too many links' ) ) );

        $detector->filter_comment( 1, array( 'comment_content' => 'spam content', 'comment_author_IP' => '10.0.0.1' ) );
        do_action( 'comment_post', 42 );
        $this->assertSame( 41, get_option( ASW_Shadow_Display::CUTOFF_OPTION ), 'flags above 41 are this version\'s' );

        $detector->filter_comment( 1, array( 'comment_content' => 'spam content', 'comment_author_IP' => '10.0.0.1' ) );
        do_action( 'comment_post', 50 );
        $this->assertSame( 41, get_option( ASW_Shadow_Display::CUTOFF_OPTION ), 'a later flag keeps the first cutoff' );
    }

    public function test_filter_comment_skips_pingbacks(): void {
        $detector = new ASW_Detector( array(
            $this->make_check( true, 'links', 'too many links' ),
        ) );

        $commentdata = array(
            'comment_content'   => 'http://spam.com http://spam2.com http://spam3.com',
            'comment_author_IP' => '192.168.1.1',
            'comment_type'      => 'pingback',
        );

        $result = $detector->filter_comment( 1, $commentdata );
        $this->assertEquals( 1, $result );
    }

    public function test_filter_comment_skips_trackbacks(): void {
        $detector = new ASW_Detector( array(
            $this->make_check( true, 'links', 'too many links' ),
        ) );

        $commentdata = array(
            'comment_content'   => 'http://spam.com http://spam2.com http://spam3.com',
            'comment_author_IP' => '192.168.1.1',
            'comment_type'      => 'trackback',
        );

        $result = $detector->filter_comment( 1, $commentdata );
        $this->assertEquals( 1, $result );
    }

    public function test_filter_comment_processes_regular_comments(): void {
        $detector = new ASW_Detector( array(
            $this->make_check( true, 'links', 'too many links' ),
        ) );

        // Explicit 'comment' type.
        $result = $detector->filter_comment( 1, array(
            'comment_content'   => 'spam',
            'comment_author_IP' => '192.168.1.1',
            'comment_type'      => 'comment',
        ) );
        $this->assertEquals( 'spam', $result );

        // Empty comment_type (default for regular comments).
        $result2 = $detector->filter_comment( 1, array(
            'comment_content'   => 'spam',
            'comment_author_IP' => '192.168.1.1',
            'comment_type'      => '',
        ) );
        $this->assertEquals( 'spam', $result2 );
    }

    public function test_ip_hashing_uses_sha256(): void {
        $ip   = '192.168.1.1';
        $hash = ASW_Detector::hash_ip( $ip );

        $salt = wp_salt( 'auth' );
        $this->assertEquals( hash( 'sha256', $salt . $ip ), $hash );
        $this->assertNotEquals( $ip, $hash );
        $this->assertEquals( 64, strlen( $hash ) ); // SHA-256 produces 64 hex chars.
    }

    public function test_ip_hashing_is_deterministic(): void {
        $ip = '10.0.0.1';
        $this->assertEquals(
            ASW_Detector::hash_ip( $ip ),
            ASW_Detector::hash_ip( $ip )
        );
    }

    public function test_ip_hashing_different_ips_produce_different_hashes(): void {
        $this->assertNotEquals(
            ASW_Detector::hash_ip( '192.168.1.1' ),
            ASW_Detector::hash_ip( '192.168.1.2' )
        );
    }

    public function test_filter_comment_hashes_ip_in_comment_data(): void {
        // Use a check that captures the comment data to verify hashed IP is present.
        $captured = null;
        $check    = new class( $captured ) implements ASW_Check {
            private $captured;

            public function __construct( &$captured ) {
                $this->captured = &$captured;
            }

            public function check( array $comment_data ): ASW_Check_Result {
                $this->captured = $comment_data;
                return new ASW_Check_Result( false, 'test', '' );
            }
        };

        $detector = new ASW_Detector( array( $check ) );
        $detector->filter_comment( 1, array(
            'comment_content'   => 'hello',
            'comment_author_IP' => '203.0.113.50',
        ) );

        $this->assertArrayHasKey( 'comment_author_IP_hashed', $captured );
        $this->assertEquals(
            ASW_Detector::hash_ip( '203.0.113.50' ),
            $captured['comment_author_IP_hashed']
        );
        // Raw IP is kept so other filters can still access it.
        $this->assertArrayHasKey( 'comment_author_IP', $captured );
        $this->assertEquals( '203.0.113.50', $captured['comment_author_IP'] );
    }

    public function test_register_hooks_adds_filter(): void {
        global $wp_filters;

        $detector = new ASW_Detector( array() );
        $detector->register_hooks();

        $this->assertArrayHasKey( 'pre_comment_approved', $wp_filters );
    }

    public function test_filter_integration_via_apply_filters(): void {
        global $wp_filters;

        $detector = new ASW_Detector( array(
            $this->make_check( true, 'spam_check', 'detected' ),
        ) );
        $detector->register_hooks();

        $result = apply_filters( 'pre_comment_approved', 1, array(
            'comment_content'   => 'spam',
            'comment_author_IP' => '1.2.3.4',
        ) );

        $this->assertEquals( 'spam', $result );
    }

    public function test_filter_integration_passes_through_ham(): void {
        $detector = new ASW_Detector( array(
            $this->make_check( false, 'clean' ),
        ) );
        $detector->register_hooks();

        $result = apply_filters( 'pre_comment_approved', 1, array(
            'comment_content'   => 'nice post',
            'comment_author_IP' => '1.2.3.4',
        ) );

        $this->assertEquals( 1, $result );
    }

    public function test_multiple_spam_checks_all_reported(): void {
        $detector = new ASW_Detector( array(
            $this->make_check( true, 'check_1', 'reason 1' ),
            $this->make_check( false, 'check_2' ),
            $this->make_check( true, 'check_3', 'reason 3' ),
        ) );

        $result = $detector->filter_comment( 1, array(
            'comment_content'   => 'spam',
            'comment_author_IP' => '1.2.3.4',
        ) );

        $this->assertEquals( 'spam', $result );

        // With short-circuit, only check_1 is reported (stops after first spam).
        do_action( 'comment_post', 99 );
        $meta = get_comment_meta( 99, '_asw_reason', true );
        $this->assertStringContainsString( 'check_1: reason 1', $meta );
    }

    /**
     * Helper: create a check that throws an exception.
     */
    private function make_throwing_check( string $class_name = 'RuntimeException' ): ASW_Check {
        return new class() implements ASW_Check {
            public function check( array $comment_data ): ASW_Check_Result {
                throw new \RuntimeException( 'Unexpected failure' );
            }
        };
    }

    // --- C1: Fail-open on exception ---

    public function test_run_skips_check_on_exception_fail_open(): void {
        $detector = new ASW_Detector( array(
            $this->make_throwing_check(),
            $this->make_check( false, 'clean', '' ),
        ) );

        $results = $detector->run( array( 'comment_content' => 'hello' ) );

        // The throwing check is skipped, so only the clean check result remains.
        $this->assertCount( 1, $results );
        $this->assertFalse( $results[0]->is_spam() );
    }

    public function test_filter_comment_does_not_mark_spam_when_only_check_throws(): void {
        $detector = new ASW_Detector( array(
            $this->make_throwing_check(),
        ) );

        $result = $detector->filter_comment( 1, array(
            'comment_content'   => 'legit comment',
            'comment_author_IP' => '10.0.0.1',
        ) );

        // Fail-open: comment passes through.
        $this->assertEquals( 1, $result );
    }

    // --- C2: Upstream respect ---

    public function test_filter_comment_preserves_upstream_spam(): void {
        $detector = new ASW_Detector( array(
            $this->make_check( false, 'clean' ),
        ) );

        $result = $detector->filter_comment( 'spam', array(
            'comment_content'   => 'anything',
            'comment_author_IP' => '10.0.0.1',
        ) );

        $this->assertEquals( 'spam', $result );
    }

    public function test_filter_comment_preserves_upstream_trash(): void {
        $detector = new ASW_Detector( array(
            $this->make_check( false, 'clean' ),
        ) );

        $result = $detector->filter_comment( 'trash', array(
            'comment_content'   => 'anything',
            'comment_author_IP' => '10.0.0.1',
        ) );

        $this->assertEquals( 'trash', $result );
    }

    public function test_filter_comment_preserves_upstream_wp_error(): void {
        $detector = new ASW_Detector( array(
            $this->make_check( false, 'clean' ),
        ) );

        $error  = new \WP_Error( 'comment_flood', 'Too many comments' );
        $result = $detector->filter_comment( $error, array(
            'comment_content'   => 'anything',
            'comment_author_IP' => '10.0.0.1',
        ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( $error, $result );
    }

    // --- Short-circuit: stop after first spam ---

    public function test_run_short_circuits_after_first_spam(): void {
        $call_count = 0;
        $counting_check = new class( $call_count ) implements ASW_Check {
            private $count;
            public function __construct( &$count ) {
                $this->count = &$count;
            }
            public function check( array $comment_data ): ASW_Check_Result {
                $this->count++;
                return new ASW_Check_Result( false, 'after_spam', '' );
            }
        };

        $detector = new ASW_Detector( array(
            $this->make_check( true, 'spam_check', 'detected' ),
            $counting_check,
        ) );

        $results = $detector->run( array( 'comment_content' => 'test' ) );

        $this->assertCount( 1, $results );
        $this->assertTrue( $results[0]->is_spam() );
        $this->assertEquals( 0, $call_count, 'Check after spam should not be called' );
    }

    public function test_bot_check_spam_verdict_short_circuits_content_checks(): void {
        $call_count = 0;
        $counting_content_check = new class( $call_count ) implements ASW_Check {
            private $count;
            public function __construct( &$count ) {
                $this->count = &$count;
            }
            public function check( array $comment_data ): ASW_Check_Result {
                $this->count++;
                return new ASW_Check_Result( false, 'content_check', '' );
            }
        };

        $detector = new ASW_Detector( array(
            $this->make_check( true, 'honeypot', 'spam detected' ), // bot check
            $counting_content_check, // later content check
        ) );

        $results = $detector->run( array( 'comment_content' => 'test' ) );

        $this->assertCount( 1, $results );
        $this->assertTrue( $results[0]->is_spam() );
        $this->assertEquals( 'honeypot', $results[0]->get_check_name() );
        $this->assertEquals( 0, $call_count, 'Content check must not be called when bot check detects spam' );
    }

    // --- Minor: sanitize meta values ---

    public function test_filter_comment_truncates_oversized_content(): void {
        $captured = null;
        $check    = new class( $captured ) implements ASW_Check {
            private $captured;

            public function __construct( &$captured ) {
                $this->captured = &$captured;
            }

            public function check( array $comment_data ): ASW_Check_Result {
                $this->captured = $comment_data;
                return new ASW_Check_Result( false, 'test', '' );
            }
        };

        $detector = new ASW_Detector( array( $check ) );

        // Create content larger than MAX_COMMENT_LENGTH (64 KB).
        $oversized = str_repeat( 'a', ASW_Detector::MAX_COMMENT_LENGTH + 1000 );

        $detector->filter_comment( 1, array(
            'comment_content'   => $oversized,
            'comment_author_IP' => '10.0.0.1',
        ) );

        $this->assertNotNull( $captured );
        $this->assertEquals(
            ASW_Detector::MAX_COMMENT_LENGTH,
            mb_strlen( $captured['comment_content'], 'UTF-8' )
        );
    }

    public function test_filter_comment_does_not_truncate_normal_content(): void {
        $captured = null;
        $check    = new class( $captured ) implements ASW_Check {
            private $captured;

            public function __construct( &$captured ) {
                $this->captured = &$captured;
            }

            public function check( array $comment_data ): ASW_Check_Result {
                $this->captured = $comment_data;
                return new ASW_Check_Result( false, 'test', '' );
            }
        };

        $detector = new ASW_Detector( array( $check ) );
        $content  = 'This is a normal comment.';

        $detector->filter_comment( 1, array(
            'comment_content'   => $content,
            'comment_author_IP' => '10.0.0.1',
        ) );

        $this->assertNotNull( $captured );
        $this->assertEquals( $content, $captured['comment_content'] );
    }

    public function test_evaluate_strips_malformed_utf8_before_checks_run(): void {
        $captured = null;
        $check    = new class( $captured ) implements ASW_Check {
            private $captured;

            public function __construct( &$captured ) {
                $this->captured = &$captured;
            }

            public function check( array $comment_data ): ASW_Check_Result {
                $this->captured = $comment_data;
                return new ASW_Check_Result( false, 'test', '' );
            }
        };

        $detector = new ASW_Detector( array( $check ) );

        // PCRE validates the whole subject, so one stray byte anywhere makes every
        // /u pattern in every check return false/null.
        $detector->filter_comment( 1, array(
            'comment_content'   => "Купите виагра дешево\xC3",
            'comment_author_IP' => '10.0.0.1',
        ) );

        $this->assertNotNull( $captured );
        $this->assertSame(
            1,
            preg_match( '//u', $captured['comment_content'] ),
            'Checks must never be handed a subject that fails PCRE UTF-8 validation'
        );
        $this->assertStringContainsString( 'виагра', $captured['comment_content'] );
    }

    public function test_malformed_utf8_is_sanitized_before_the_length_cap_is_applied(): void {
        $captured = null;
        $check    = new class( $captured ) implements ASW_Check {
            private $captured;

            public function __construct( &$captured ) {
                $this->captured = &$captured;
            }

            public function check( array $comment_data ): ASW_Check_Result {
                $this->captured = $comment_data;
                return new ASW_Check_Result( false, 'test', '' );
            }
        };

        $detector = new ASW_Detector( array( $check ) );

        // Sanitizing before truncating is what makes the cap mean what it says.
        // On PHP 8.1 and 8.2 - the plugin's floor, and the smoke harness's
        // 6.4-php8.1 image - mb_strlen() counts each unpaired-surrogate sequence as ONE
        // character, so the raw body measures exactly MAX_COMMENT_LENGTH and the
        // truncation does not fire at all; sanitization then expands every sequence
        // to its own '?' and hands the checks 3 x MAX_COMMENT_LENGTH characters,
        // re-opening the CPU-exhaustion vector the cap exists to close. Swapping the
        // two statements makes this assertion fail on 8.1/8.2 (196 608 vs 65 536).
        //
        // Note for whoever runs this on a newer PHP: from 8.3 on mb_strlen() counts
        // each invalid BYTE as a character, so sanitization can no longer expand the
        // count and the swap is unobservable - the assertion then holds trivially.
        // That is a property of the runtime, not a reason to drop the ordering.
        $body = str_repeat( "\xED\xA0\x80", ASW_Detector::MAX_COMMENT_LENGTH );

        $detector->filter_comment( 1, array(
            'comment_content'   => $body,
            'comment_author_IP' => '10.0.0.1',
        ) );

        $this->assertNotNull( $captured );
        $this->assertSame(
            1,
            preg_match( '//u', $captured['comment_content'] ),
            'Checks must never be handed a subject that fails PCRE UTF-8 validation'
        );
        $this->assertLessThanOrEqual(
            ASW_Detector::MAX_COMMENT_LENGTH,
            mb_strlen( $captured['comment_content'], 'UTF-8' ),
            'Sanitization must run before the truncation, or malformed bytes expand past the cap'
        );
    }

    public function test_evaluate_sanitizes_before_it_truncates_in_source_order(): void {
        // The behavioural test above can only observe this ordering on PHP 8.1/8.2.
        // From 8.3 on, mb_strlen() counts each invalid BYTE as a character, so
        // sanitization can no longer expand the count and swapping the two
        // statements is unobservable at runtime - including on the composer:2
        // image the unit CI job runs. This structural assertion fails on every
        // PHP version if they are swapped, so the ordering that keeps
        // MAX_COMMENT_LENGTH meaningful on the plugin's declared floor stays
        // guarded where CI can see it.
        $source = file_get_contents( dirname( __DIR__ ) . '/antispam-wizard/includes/class-detector.php' );
        $this->assertIsString( $source );

        $start = strpos( $source, 'public function evaluate(' );
        $this->assertNotFalse( $start, 'ASW_Detector::evaluate() not found' );
        $end  = strpos( $source, "\n    public function", $start + 1 );
        $body = false === $end ? substr( $source, $start ) : substr( $source, $start, $end - $start );

        $sanitize = strpos( $body, "self::sanitize_utf8( \$data['comment_content'] )" );
        $truncate = strpos( $body, "mb_substr( \$data['comment_content']" );
        $this->assertNotFalse( $sanitize, 'evaluate() must sanitize comment_content' );
        $this->assertNotFalse( $truncate, 'evaluate() must truncate comment_content' );
        $this->assertLessThan(
            $truncate,
            $sanitize,
            'evaluate() must sanitize comment_content BEFORE truncating it, or malformed bytes expand past the cap on PHP 8.1/8.2'
        );
    }

    public function test_non_string_comment_content_fails_open_instead_of_fataling(): void {
        $detector = new ASW_Detector( array( new ASW_Emoji_Check() ) );

        // run() catches a Throwable per check, but evaluate()'s own mb_strlen()
        // does not run under that catch, so a non-string body must skip the
        // sanitize/truncate block entirely rather than fatal the comment route.
        $this->assertSame(
            1,
            $detector->filter_comment( 1, array(
                'comment_content'   => array( 'not', 'a', 'string' ),
                'comment_author_IP' => '10.0.0.1',
            ) )
        );
    }

    public function test_non_string_comment_author_ip_fails_open_instead_of_fataling(): void {
        $detector = new ASW_Detector( array( new ASW_Emoji_Check() ) );

        // hash_ip() is typed `string` and the plugin declares no strict_types, so an
        // array from another pre_comment_approved filter is a TypeError - raised by
        // evaluate() itself, outside run()'s per-check catch.
        $this->assertSame(
            1,
            $detector->filter_comment( 1, array(
                'comment_content'   => 'hello',
                'comment_author_IP' => array( '10.0.0.1' ),
            ) )
        );
    }

    /**
     * The guard above only stops evaluate() from hashing a non-string IP; it leaves
     * comment_author_IP_hashed unset, so any check that falls back to hashing the raw
     * value itself re-raises the same TypeError one frame later. That one is caught by
     * run() - so the comment still passes - but the check is silently skipped and a
     * plugin-attributable line lands in the debug.log dev/smoke.sh scans, on input any
     * unauthenticated commenter can trigger through another plugin. Exercise the real
     * ASW_Duplicate_Check, which owns that fallback.
     */
    public function test_non_string_ip_does_not_break_checks_that_hash_it_themselves(): void {
        ASW_Settings::update( array(
            'enabled'           => true,
            'duplicate_enabled' => true,
        ) );

        $detector = new ASW_Detector( array( new ASW_Duplicate_Check() ) );

        $tmp     = tempnam( sys_get_temp_dir(), 'asw_test_' );
        $old_log = ini_set( 'error_log', $tmp );

        $status = $detector->filter_comment( 1, array(
            'comment_content'   => 'hello',
            'comment_author_IP' => array( '10.0.0.1' ),
        ) );

        ini_set( 'error_log', $old_log );
        $logged = file_get_contents( $tmp );
        unlink( $tmp );

        $this->assertSame( 1, $status );
        $this->assertStringNotContainsString(
            'AntiSpam Wizard',
            $logged,
            'a non-string IP must not make a check throw and log a fail-open diagnostic'
        );
    }

    public function test_non_string_comment_content_is_logged_when_a_content_independent_check_flags(): void {
        ASW_Settings::update( array(
            'enabled'          => true,
            'honeypot_enabled' => true,
        ) );

        // Honeypot never reads comment_content, so it reaches the spam branch with
        // the body still non-string. ASW_Spam_Log::add_entry() takes a string and
        // its TypeError would land outside run()'s per-check catch.
        $detector = new ASW_Detector( array(
            new ASW_Honeypot_Check( array( ASW_Comment_Form::HONEYPOT_FIELD => 'filled by bot' ) ),
        ) );

        $this->assertSame(
            'spam',
            $detector->filter_comment( 1, array(
                'comment_content'   => array( 'not', 'a', 'string' ),
                'comment_author_IP' => '10.0.0.1',
            ) )
        );

        $entries = ASW_Spam_Log::get_entries();
        $this->assertCount( 1, $entries );
        $this->assertSame( '', $entries[0]['text_excerpt'] );
        $this->assertSame( array( 'honeypot' ), $entries[0]['checks_triggered'] );
    }

    public function test_one_stray_byte_does_not_disable_a_check(): void {
        ASW_Settings::update( array(
            'enabled'       => true,
            'emoji_enabled' => true,
            'emoji_max'     => 4,
        ) );

        $detector = new ASW_Detector( array( new ASW_Emoji_Check() ) );
        $emoji    = str_repeat( '😀', 10 );

        $this->assertSame(
            'spam',
            $detector->filter_comment( 1, array(
                'comment_content'   => $emoji,
                'comment_author_IP' => '10.0.0.1',
            ) ),
            'Baseline: the emoji check flags this body'
        );

        $this->assertSame(
            'spam',
            $detector->filter_comment( 1, array(
                'comment_content'   => $emoji . "\xC3",
                'comment_author_IP' => '10.0.0.2',
            ) ),
            'Appending one invalid byte must not evade the check'
        );
    }

    public function test_run_logs_a_diagnostic_naming_the_failing_check(): void {
        // Must be a NAMED class. An anonymous class name embeds a NUL byte
        // ("class@anonymous\0/path/file.php:12$0") and error_log() writes a C
        // string, so everything after the NUL - including the message this test
        // inspects - is silently dropped from the log. An anonymous throwing
        // check therefore makes every assertion below pass vacuously.
        $check = new ASW_Throwing_Check();

        $detector = new ASW_Detector( array(
            $check,
            $this->make_check( false, 'clean' ),
        ) );

        // Capture error_log output.
        $tmp     = tempnam( sys_get_temp_dir(), 'asw_test_' );
        $old_log = ini_set( 'error_log', $tmp );

        $results = $detector->run( array( 'comment_content' => 'hello' ) );

        ini_set( 'error_log', $old_log );
        $logged = file_get_contents( $tmp );
        unlink( $tmp );

        // The diagnostic must name the check that failed, or a broken check on a
        // live site is unattributable.
        $this->assertStringContainsString( 'AntiSpam Wizard', $logged );
        $this->assertStringContainsString( 'ASW_Throwing_Check', $logged );
        // ...and it must NOT carry the exception message: a check wrapping $wpdb,
        // file I/O or an HTTP client can put a query, a path or a credential in
        // there, and debug.log is web-readable on misconfigured hosts. The named
        // fixture above is what makes this assertion mean something - with an
        // anonymous class the NUL in its name truncated the line and this passed
        // no matter what run() logged.
        $this->assertStringNotContainsString( 'secret database credentials', $logged );
        // The exception class name is not part of the diagnostic.
        $this->assertStringNotContainsString( 'RuntimeException', $logged );
        // Fail-open: the throwing check is skipped, not fatal, and the run
        // continues into the checks after it.
        $this->assertCount( 1, $results );
        $this->assertSame( 'clean', $results[0]->get_check_name() );
    }

    public function test_filter_comment_sanitizes_spam_reason_meta(): void {
        $detector = new ASW_Detector( array(
            $this->make_check( true, 'xss', '<script>alert("xss")</script>bad' ),
        ) );

        $detector->filter_comment( 1, array(
            'comment_content'   => 'spam',
            'comment_author_IP' => '10.0.0.1',
        ) );

        do_action( 'comment_post', 200 );
        $meta = get_comment_meta( 200, '_asw_reason', true );
        $this->assertStringNotContainsString( '<script>', $meta );
        $this->assertStringContainsString( 'xss', $meta );
    }

    // --- Global enabled setting ---

    public function test_filter_comment_passes_through_when_disabled(): void {
        ASW_Settings::update( array( 'enabled' => false ) );

        $detector = new ASW_Detector( array(
            $this->make_check( true, 'links', 'too many links' ),
        ) );

        $result = $detector->filter_comment( 1, array(
            'comment_content'   => 'buy now http://spam.com http://spam2.com http://spam3.com',
            'comment_author_IP' => '192.168.1.1',
        ) );

        $this->assertEquals( 1, $result );
    }

    public function test_filter_comment_runs_checks_when_enabled(): void {
        ASW_Settings::update( array( 'enabled' => true ) );

        $detector = new ASW_Detector( array(
            $this->make_check( true, 'links', 'too many links' ),
        ) );

        $result = $detector->filter_comment( 1, array(
            'comment_content'   => 'buy now http://spam.com http://spam2.com http://spam3.com',
            'comment_author_IP' => '192.168.1.1',
        ) );

        $this->assertEquals( 'spam', $result );
    }

    public function test_filter_comment_runs_checks_with_default_enabled(): void {
        // No settings saved — default should be enabled.
        $detector = new ASW_Detector( array(
            $this->make_check( true, 'links', 'too many links' ),
        ) );

        $result = $detector->filter_comment( 1, array(
            'comment_content'   => 'spam content',
            'comment_author_IP' => '192.168.1.1',
        ) );

        $this->assertEquals( 'spam', $result );
    }
}

/**
 * Named throwing check fixture.
 *
 * Deliberately not an anonymous class: anonymous class names embed a NUL byte,
 * which truncates the error_log() line and would make the log assertions in
 * test_run_logs_a_diagnostic_naming_the_failing_check pass vacuously - including
 * the one asserting the exception message never reaches the log.
 */
class ASW_Throwing_Check implements ASW_Check {
    public function check( array $comment_data ): ASW_Check_Result {
        throw new \RuntimeException( 'secret database credentials' );
    }
}
