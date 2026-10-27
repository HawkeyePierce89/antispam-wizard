<?php

use PHPUnit\Framework\TestCase;

class CommentFormTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        global $wp_actions, $wp_enqueued_styles, $wp_is_singular, $wp_comments_open, $wp_options;
        $wp_actions = array();
        $wp_enqueued_styles = array();
        $wp_is_singular = true;
        $wp_comments_open = true;
        $wp_options = array();
    }

    protected function tearDown(): void {
        global $wp_options;
        $wp_options = array();
        parent::tearDown();
    }

    public function test_render_fields_markup(): void {
        $form = new ANTISPAMWIZARD_Comment_Form();

        ob_start();
        $form->render_fields();
        $markup = ob_get_clean();

        $this->assertStringContainsString( ANTISPAMWIZARD_Comment_Form::HONEYPOT_FIELD, $markup );
        $this->assertStringContainsString( ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD, $markup );
        $this->assertStringContainsString( 'aria-hidden="true"', $markup );
        $this->assertStringContainsString( 'tabindex="-1"', $markup );
        $this->assertStringContainsString( 'autocomplete="off"', $markup );
        $this->assertStringContainsString( 'class="antispamwizard-hp-field"', $markup );
        $this->assertStringContainsString( 'style="position:absolute; left:-10000px; width:1px; height:1px; overflow:hidden;"', $markup );

        // Extract token to verify it's well-formed
        preg_match( '/value="([0-9]+\.[a-f0-9]+)"/', $markup, $matches );
        $this->assertNotEmpty( $matches );
        $this->assertNotNull( ANTISPAMWIZARD_Comment_Form::parse_token( $matches[1], 0 ) ); // get_the_ID() stub returns 0 by default
    }

    public function test_enqueue_styles(): void {
        global $wp_enqueued_styles;
        $form = new ANTISPAMWIZARD_Comment_Form();
        $form->enqueue_styles();
        $this->assertTrue( array_key_exists( 'antispamwizard-comment-form', $wp_enqueued_styles ) );
    }

    public function test_enqueue_skipped_when_not_singular(): void {
        global $wp_enqueued_styles, $wp_is_singular;
        $wp_is_singular = false;

        ( new ANTISPAMWIZARD_Comment_Form() )->enqueue_styles();
        $this->assertArrayNotHasKey( 'antispamwizard-comment-form', $wp_enqueued_styles );
    }

    public function test_enqueue_skipped_when_comments_closed(): void {
        global $wp_enqueued_styles, $wp_comments_open;
        $wp_comments_open = false;

        ( new ANTISPAMWIZARD_Comment_Form() )->enqueue_styles();
        $this->assertArrayNotHasKey( 'antispamwizard-comment-form', $wp_enqueued_styles );
    }

    public function test_enqueue_skipped_when_both_checks_disabled(): void {
        global $wp_enqueued_styles;
        ANTISPAMWIZARD_Settings::update( array(
            'honeypot_enabled'    => false,
            'submit_time_enabled' => false,
        ) );

        ( new ANTISPAMWIZARD_Comment_Form() )->enqueue_styles();
        $this->assertArrayNotHasKey( 'antispamwizard-comment-form', $wp_enqueued_styles );
    }

    public function test_stylesheet_file_exists_at_enqueued_path(): void {
        $this->assertFileExists( ANTISPAMWIZARD_PATH . 'public/css/comment-form.css' );
    }

    public function test_mint_and_parse_token(): void {
        $token = ANTISPAMWIZARD_Comment_Form::mint_token( null, 123 );
        $parsed = ANTISPAMWIZARD_Comment_Form::parse_token( $token, 123 );

        $this->assertIsInt( $parsed );
        $this->assertGreaterThan( 0, $parsed );

        // Test mismatch post ID
        $this->assertNull( ANTISPAMWIZARD_Comment_Form::parse_token( $token, 456 ) );

        // Test specific timestamp
        $ts = 1600000000;
        $token_specific = ANTISPAMWIZARD_Comment_Form::mint_token( $ts, 99 );
        $parsed_specific = ANTISPAMWIZARD_Comment_Form::parse_token( $token_specific, 99 );
        $this->assertEquals( $ts, $parsed_specific );
    }

    public function test_parse_token_invalid_inputs(): void {
        $this->assertNull( ANTISPAMWIZARD_Comment_Form::parse_token( '', 0 ) );
        $this->assertNull( ANTISPAMWIZARD_Comment_Form::parse_token( 'garbage', 0 ) );
        $this->assertNull( ANTISPAMWIZARD_Comment_Form::parse_token( '12345', 0 ) ); // missing separator
        $this->assertNull( ANTISPAMWIZARD_Comment_Form::parse_token( 'abc.123', 0 ) ); // non-numeric ts

        $token = ANTISPAMWIZARD_Comment_Form::mint_token( null, 0 );
        $this->assertNull( ANTISPAMWIZARD_Comment_Form::parse_token( $token . 'a', 0 ) ); // tampered hmac

        $parts = explode( '.', $token );
        $this->assertNull( ANTISPAMWIZARD_Comment_Form::parse_token( ( $parts[0] + 1 ) . '.' . $parts[1], 0 ) ); // tampered timestamp
    }

    public function test_register_hooks(): void {
        global $wp_actions;

        $form = new ANTISPAMWIZARD_Comment_Form();
        $form->register_hooks();

        $has_comment_form = false;
        foreach ( $wp_actions['comment_form'] as $action ) {
            if ( $action['callback'] === array( $form, 'render_fields' ) ) {
                $has_comment_form = true;
            }
        }

        $has_enqueue_scripts = false;
        foreach ( $wp_actions['wp_enqueue_scripts'] as $action ) {
            if ( $action['callback'] === array( $form, 'enqueue_styles' ) ) {
                $has_enqueue_scripts = true;
            }
        }

        $this->assertTrue( $has_comment_form );
        $this->assertTrue( $has_enqueue_scripts );
    }

    public function test_render_bot_fields_returns_string_with_both_fields(): void {
        $markup = ANTISPAMWIZARD_Comment_Form::render_bot_fields( 42 );

        $this->assertStringContainsString( ANTISPAMWIZARD_Comment_Form::HONEYPOT_FIELD, $markup );
        $this->assertStringContainsString( ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD, $markup );
        $this->assertStringContainsString( 'aria-hidden="true"', $markup );
        $this->assertStringContainsString( 'tabindex="-1"', $markup );
        $this->assertStringContainsString( 'autocomplete="off"', $markup );
        $this->assertStringContainsString( 'class="antispamwizard-hp-field"', $markup );
        $this->assertStringContainsString( 'style="position:absolute; left:-10000px; width:1px; height:1px; overflow:hidden;"', $markup );
        $this->assertStringContainsString( 'Leave this field empty', $markup );
    }

    public function test_render_bot_fields_byte_equivalent_to_render_fields(): void {
        global $wp_the_id;
        $wp_the_id = 99;

        $form = new ANTISPAMWIZARD_Comment_Form();
        ob_start();
        $form->render_fields();
        $via_render_fields = ob_get_clean();

        $via_static = ANTISPAMWIZARD_Comment_Form::render_bot_fields( 99 );

        // Normalize the random id (honeypot field id suffix) before comparing.
        $normalize = static function ( string $html ): string {
            return preg_replace( '/comment_url_confirm_\d+/', 'comment_url_confirm_X', $html );
        };

        $this->assertSame( $normalize( $via_render_fields ), $normalize( $via_static ) );

        $wp_the_id = 0;
    }

    /**
     * Snapshot of the exact bot-field markup. render_fields() echoes this string
     * raw (annotated phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped),
     * so this test pins every byte that reaches the browser: the dynamic parts are
     * the wp_rand() id suffix and the minted token, and everything else is a
     * literal or an esc_attr()-escaped constant assembled inside render_bot_fields().
     */
    public function test_render_bot_fields_markup_snapshot(): void {
        $markup = ANTISPAMWIZARD_Comment_Form::render_bot_fields( 42 );

        // Normalize only the two intentionally-dynamic values.
        $normalized = preg_replace( '/(comment_url_confirm)_\d+/', '$1_ID', $markup );
        $normalized = preg_replace( '/value="\d+\.[a-f0-9]{64}"/', 'value="TOKEN"', $normalized );

        $expected = implode( "\n", array(
            '            <div class="antispamwizard-hp-field" aria-hidden="true" style="position:absolute; left:-10000px; width:1px; height:1px; overflow:hidden;">',
            '                                    <label for="comment_url_confirm_ID">Leave this field empty</label>',
            '                    <input type="text" name="comment_url_confirm" id="comment_url_confirm_ID" value="" tabindex="-1" autocomplete="off" />',
            '                                                                        <input type="hidden" name="comment_form_token" value="TOKEN" />',
            '                            </div>',
            '        ',
        ) );

        $this->assertSame( $expected, $normalized );

        // No HTML entities: the markup is emitted as live tags, not escaped text.
        $this->assertStringNotContainsString( '&lt;', $markup );
        $this->assertStringNotContainsString( '&quot;', $markup );
    }

    public function test_render_fields_echoes_snapshot_markup_unescaped(): void {
        $form = new ANTISPAMWIZARD_Comment_Form();

        ob_start();
        $form->render_fields();
        $echoed = ob_get_clean();

        $normalize = static function ( string $m ): string {
            $m = preg_replace( '/(comment_url_confirm)_\d+/', '$1_ID', $m );
            return preg_replace( '/value="\d+\.[a-f0-9]{64}"/', 'value="TOKEN"', $m );
        };

        $this->assertSame(
            $normalize( ANTISPAMWIZARD_Comment_Form::render_bot_fields( 0 ) ),
            $normalize( $echoed )
        );
        $this->assertStringContainsString( '<input type="text" name="comment_url_confirm"', $echoed );
        $this->assertStringNotContainsString( '&lt;input', $echoed );
    }

    public function test_render_bot_fields_honeypot_only(): void {
        ANTISPAMWIZARD_Settings::update( array(
            'honeypot_enabled'    => true,
            'submit_time_enabled' => false,
        ) );

        $markup = ANTISPAMWIZARD_Comment_Form::render_bot_fields( 10 );

        $this->assertStringContainsString( ANTISPAMWIZARD_Comment_Form::HONEYPOT_FIELD, $markup );
        $this->assertStringNotContainsString( ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD, $markup );
        $this->assertStringContainsString( 'class="antispamwizard-hp-field"', $markup );
    }

    public function test_render_bot_fields_token_only(): void {
        ANTISPAMWIZARD_Settings::update( array(
            'honeypot_enabled'    => false,
            'submit_time_enabled' => true,
        ) );

        $markup = ANTISPAMWIZARD_Comment_Form::render_bot_fields( 10 );

        $this->assertStringNotContainsString( ANTISPAMWIZARD_Comment_Form::HONEYPOT_FIELD, $markup );
        $this->assertStringContainsString( ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD, $markup );
        $this->assertStringContainsString( 'class="antispamwizard-hp-field"', $markup );
    }

    public function test_render_bot_fields_empty_when_both_disabled(): void {
        ANTISPAMWIZARD_Settings::update( array(
            'honeypot_enabled'    => false,
            'submit_time_enabled' => false,
        ) );

        $this->assertSame( '', ANTISPAMWIZARD_Comment_Form::render_bot_fields( 10 ) );

        // render_fields should also echo nothing.
        ob_start();
        ( new ANTISPAMWIZARD_Comment_Form() )->render_fields();
        $this->assertSame( '', ob_get_clean() );
    }

    public function test_render_bot_fields_token_verifies_against_context_id(): void {
        $context_id = 12345;
        $markup = ANTISPAMWIZARD_Comment_Form::render_bot_fields( $context_id );

        preg_match( '/value="([0-9]+\.[a-f0-9]+)"/', $markup, $matches );
        $this->assertNotEmpty( $matches, 'Token not found in markup' );
        $token = $matches[1];

        $this->assertNotNull( ANTISPAMWIZARD_Comment_Form::parse_token( $token, $context_id ) );
        $this->assertNull( ANTISPAMWIZARD_Comment_Form::parse_token( $token, 99999 ) );
        $this->assertNull( ANTISPAMWIZARD_Comment_Form::parse_token( $token, $context_id + 1 ) );
    }

    public function test_render_fields_delegates_to_render_bot_fields(): void {
        global $wp_the_id;
        $wp_the_id = 77;

        ob_start();
        ( new ANTISPAMWIZARD_Comment_Form() )->render_fields();
        $a = ob_get_clean();

        $b = ANTISPAMWIZARD_Comment_Form::render_bot_fields( 77 );

        // Same markup modulo random id, as above.
        $normalize = static function ( string $html ): string {
            return preg_replace( '/comment_url_confirm_\d+/', 'comment_url_confirm_X', $html );
        };
        $this->assertSame( $normalize( $a ), $normalize( $b ) );

        $wp_the_id = 0;
    }
}
