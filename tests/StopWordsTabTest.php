<?php

use PHPUnit\Framework\TestCase;

class StopWordsTabTest extends TestCase {

    private ASW_Admin_Page $admin_page;

    protected function setUp(): void {
        global $wp_options, $wp_actions, $wp_filters, $wp_menu_pages, $wp_enqueued_styles,
               $wp_enqueued_scripts, $wp_localized_scripts, $wp_current_user_caps, $wp_nonces,
               $wp_json_responses;

        $wp_options            = array();
        $wp_actions            = array();
        $wp_filters            = array();
        $wp_menu_pages         = array();
        $wp_enqueued_styles    = array();
        $wp_enqueued_scripts   = array();
        $wp_localized_scripts  = array();
        $wp_current_user_caps  = array( 'manage_options' => true );
        $wp_nonces             = array();
        $wp_json_responses     = array();
        $_POST                 = array();
        $_GET                  = array();
        $_FILES                = array();

        if ( ! defined( 'ASW_PATH' ) ) {
            define( 'ASW_PATH', dirname( __DIR__ ) . '/antispam-wizard/' );
        }
        if ( ! defined( 'ASW_URL' ) ) {
            define( 'ASW_URL', 'http://example.com/wp-content/plugins/antispam-wizard/' );
        }
        if ( ! defined( 'ASW_VERSION' ) ) {
            define( 'ASW_VERSION', '1.0.0' );
        }

        $this->admin_page = new ASW_Admin_Page();
    }

    protected function tearDown(): void {
        $_POST  = array();
        $_GET   = array();
        $_FILES = array();
    }

    // --- AJAX add stop-word tests ---

    public function test_ajax_add_stop_word_success() {
        global $wp_json_responses;

        $nonce = wp_create_nonce( ASW_Admin_Page::AJAX_NONCE );
        $_POST['nonce'] = $nonce;
        $_POST['word']  = 'badword';

        $this->admin_page->ajax_add_stop_word();

        $this->assertCount( 1, $wp_json_responses );
        $this->assertTrue( $wp_json_responses[0]['success'] );

        $list = ASW_Settings::get( 'stop_words_list', array() );
        $this->assertContains( 'badword', $list );
    }

    public function test_ajax_add_stop_word_duplicate() {
        global $wp_json_responses;

        ASW_Settings::update( array( 'stop_words_list' => array( 'existing' ) ) );

        $nonce = wp_create_nonce( ASW_Admin_Page::AJAX_NONCE );
        $_POST['nonce'] = $nonce;
        $_POST['word']  = 'existing';

        $this->admin_page->ajax_add_stop_word();

        $this->assertFalse( $wp_json_responses[0]['success'] );
        $this->assertStringContainsString( 'already exists', $wp_json_responses[0]['data']['message'] );
    }

    public function test_ajax_add_stop_word_empty() {
        global $wp_json_responses;

        $nonce = wp_create_nonce( ASW_Admin_Page::AJAX_NONCE );
        $_POST['nonce'] = $nonce;
        $_POST['word']  = '';

        $this->admin_page->ajax_add_stop_word();

        $this->assertFalse( $wp_json_responses[0]['success'] );
    }

    public function test_ajax_add_stop_word_invalid_nonce() {
        global $wp_json_responses;

        $_POST['nonce'] = 'bad_nonce';
        $_POST['word']  = 'badword';

        $this->admin_page->ajax_add_stop_word();

        $this->assertFalse( $wp_json_responses[0]['success'] );
        $this->assertStringContainsString( 'Security', $wp_json_responses[0]['data']['message'] );
    }

    public function test_ajax_add_stop_word_no_capability() {
        global $wp_current_user_caps, $wp_json_responses;
        $wp_current_user_caps = array();

        $nonce = wp_create_nonce( ASW_Admin_Page::AJAX_NONCE );
        $_POST['nonce'] = $nonce;
        $_POST['word']  = 'badword';

        $this->admin_page->ajax_add_stop_word();

        $this->assertFalse( $wp_json_responses[0]['success'] );
        $this->assertStringContainsString( 'Permission', $wp_json_responses[0]['data']['message'] );
    }

    public function test_ajax_add_stop_word_bare_equals_rejected() {
        global $wp_json_responses;

        $nonce = wp_create_nonce( ASW_Admin_Page::AJAX_NONCE );
        $_POST['nonce'] = $nonce;
        $_POST['word']  = '=';

        $this->admin_page->ajax_add_stop_word();

        $this->assertFalse( $wp_json_responses[0]['success'] );
        $this->assertStringContainsString( 'empty', $wp_json_responses[0]['data']['message'] );
    }

    // --- AJAX remove stop-word tests ---

    public function test_ajax_remove_stop_word_success() {
        global $wp_json_responses;

        ASW_Settings::update( array( 'stop_words_list' => array( 'word1', 'word2', 'word3' ) ) );

        $nonce = wp_create_nonce( ASW_Admin_Page::AJAX_NONCE );
        $_POST['nonce'] = $nonce;
        $_POST['word']  = 'word2';

        $this->admin_page->ajax_remove_stop_word();

        $this->assertTrue( $wp_json_responses[0]['success'] );

        $list = ASW_Settings::get( 'stop_words_list', array() );
        $this->assertNotContains( 'word2', $list );
        $this->assertContains( 'word1', $list );
        $this->assertContains( 'word3', $list );
    }

    public function test_ajax_remove_stop_word_not_found() {
        global $wp_json_responses;

        ASW_Settings::update( array( 'stop_words_list' => array( 'word1' ) ) );

        $nonce = wp_create_nonce( ASW_Admin_Page::AJAX_NONCE );
        $_POST['nonce'] = $nonce;
        $_POST['word']  = 'nonexistent';

        $this->admin_page->ajax_remove_stop_word();

        $this->assertFalse( $wp_json_responses[0]['success'] );
    }

    public function test_ajax_remove_stop_word_invalid_nonce() {
        global $wp_json_responses;

        $_POST['nonce'] = 'bad';
        $_POST['word']  = 'word1';

        $this->admin_page->ajax_remove_stop_word();

        $this->assertFalse( $wp_json_responses[0]['success'] );
    }

    public function test_ajax_remove_stop_word_no_capability() {
        global $wp_current_user_caps, $wp_json_responses;
        $wp_current_user_caps = array();

        $nonce = wp_create_nonce( ASW_Admin_Page::AJAX_NONCE );
        $_POST['nonce'] = $nonce;
        $_POST['word']  = 'word1';

        $this->admin_page->ajax_remove_stop_word();

        $this->assertFalse( $wp_json_responses[0]['success'] );
    }

    // --- Import tests ---

    public function test_import_from_textarea() {
        ASW_Settings::update( array( 'stop_words_list' => array() ) );
        $nonce = wp_create_nonce( 'asw_import_stop_words' );

        $_POST['asw_import']       = '1';
        $_POST['asw_import_nonce'] = $nonce;
        $_POST['stop_words_text']             = "word1\nword2\nword3";

        $this->admin_page->handle_stop_words_import();

        $list = ASW_Settings::get( 'stop_words_list', array() );
        $this->assertCount( 3, $list );
        $this->assertContains( 'word1', $list );
        $this->assertContains( 'word2', $list );
        $this->assertContains( 'word3', $list );
    }

    public function test_import_merges_with_existing() {
        ASW_Settings::update( array( 'stop_words_list' => array( 'existing' ) ) );

        $nonce = wp_create_nonce( 'asw_import_stop_words' );
        $_POST['asw_import']       = '1';
        $_POST['asw_import_nonce'] = $nonce;
        $_POST['stop_words_text']             = "existing\nnewword";

        $this->admin_page->handle_stop_words_import();

        $list = ASW_Settings::get( 'stop_words_list', array() );
        $this->assertCount( 2, $list );
        $this->assertContains( 'existing', $list );
        $this->assertContains( 'newword', $list );
    }

    public function test_import_skips_empty_lines() {
        ASW_Settings::update( array( 'stop_words_list' => array() ) );
        $nonce = wp_create_nonce( 'asw_import_stop_words' );
        $_POST['asw_import']       = '1';
        $_POST['asw_import_nonce'] = $nonce;
        $_POST['stop_words_text']             = "word1\n\n\nword2\n  \n";

        $this->admin_page->handle_stop_words_import();

        $list = ASW_Settings::get( 'stop_words_list', array() );
        $this->assertCount( 2, $list );
    }

    public function test_import_without_nonce_does_nothing() {
        $defaults = ASW_Settings::get_defaults();
        $_POST['asw_import'] = '1';
        $_POST['stop_words_text']       = "word1\nword2";

        $this->admin_page->handle_stop_words_import();

        $list = ASW_Settings::get( 'stop_words_list', array() );
        $this->assertSame( $defaults['stop_words_list'], $list );
    }

    public function test_import_without_capability_does_nothing() {
        global $wp_current_user_caps;
        $wp_current_user_caps = array();
        $defaults = ASW_Settings::get_defaults();

        $nonce = wp_create_nonce( 'asw_import_stop_words' );
        $_POST['asw_import']       = '1';
        $_POST['asw_import_nonce'] = $nonce;
        $_POST['stop_words_text']             = "word1";

        $this->admin_page->handle_stop_words_import();

        $list = ASW_Settings::get( 'stop_words_list', array() );
        $this->assertSame( $defaults['stop_words_list'], $list );
    }

    // --- Parse stop-words text ---

    public function test_parse_stop_words_text_basic() {
        $result = ASW_Admin_Page::parse_stop_words_text( "one\ntwo\nthree" );
        $this->assertSame( array( 'one', 'two', 'three' ), $result );
    }

    public function test_parse_stop_words_text_windows_line_endings() {
        $result = ASW_Admin_Page::parse_stop_words_text( "one\r\ntwo\r\nthree" );
        $this->assertSame( array( 'one', 'two', 'three' ), $result );
    }

    public function test_parse_stop_words_text_with_exact_match_prefix() {
        $result = ASW_Admin_Page::parse_stop_words_text( "=exact\nsubstring" );
        $this->assertSame( array( '=exact', 'substring' ), $result );
    }

    public function test_parse_stop_words_text_empty() {
        $result = ASW_Admin_Page::parse_stop_words_text( '' );
        $this->assertSame( array(), $result );
    }

    public function test_parse_stop_words_text_filters_too_long() {
        $long_word = str_repeat( 'a', 65 );
        $result = ASW_Admin_Page::parse_stop_words_text( "valid\n{$long_word}\nother" );
        $this->assertSame( array( 'valid', 'other' ), $result );
    }

    public function test_parse_stop_words_text_filters_emoji() {
        $result = ASW_Admin_Page::parse_stop_words_text( "good\nbad😀word\nfine" );
        $this->assertSame( array( 'good', 'fine' ), $result );
    }

    public function test_parse_stop_words_text_filters_bare_equals() {
        $result = ASW_Admin_Page::parse_stop_words_text( "=\n=valid phrase\nword" );
        $this->assertSame( array( '=valid phrase', 'word' ), $result );
    }

    public function test_parse_stop_words_text_filters_exact_match_with_special_chars() {
        $result = ASW_Admin_Page::parse_stop_words_text( "=hello!\n=good phrase" );
        $this->assertSame( array( '=good phrase' ), $result );
    }

    // --- Enqueue assets ---

    public function test_enqueue_assets_includes_js() {
        global $wp_enqueued_scripts, $wp_localized_scripts;

        $this->admin_page->add_menu_page();
        $this->admin_page->enqueue_assets( 'antispam-wizard_page_antispam-wizard-stop-words' );

        $this->assertArrayHasKey( 'asw-admin', $wp_enqueued_scripts );
        $this->assertStringContainsString( 'admin/js/admin.js', $wp_enqueued_scripts['asw-admin']['src'] );
        $this->assertArrayHasKey( 'asw-admin', $wp_localized_scripts );
        $this->assertSame( 'aswAdmin', $wp_localized_scripts['asw-admin']['object_name'] );
    }

    public function test_enqueue_assets_skips_other_pages() {
        global $wp_enqueued_scripts, $wp_enqueued_styles;

        $this->admin_page->add_menu_page();
        $this->admin_page->enqueue_assets( 'edit.php' );

        $this->assertEmpty( $wp_enqueued_styles );
        $this->assertEmpty( $wp_enqueued_scripts );
    }

    // --- Init registers AJAX hooks ---

    public function test_init_registers_ajax_hooks() {
        global $wp_actions;
        $wp_actions = array();

        $this->admin_page->init();

        $this->assertArrayHasKey( 'wp_ajax_asw_add_stop_word', $wp_actions );
        $this->assertArrayHasKey( 'wp_ajax_asw_remove_stop_word', $wp_actions );
    }

    // --- Stop-words view renders ---

    public function test_stop_words_tab_renders() {
        $_GET['page'] = 'antispam-wizard-stop-words';

        ob_start();
        $this->admin_page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Blocked Words', $output );
        $this->assertStringContainsString( 'asw-new-word', $output );
        $this->assertStringContainsString( 'asw-add-word', $output );
        $this->assertStringContainsString( 'Import', $output );
        $this->assertStringContainsString( 'Export', $output );
    }

    // --- Export byte-fidelity tests ---
    //
    // The two export handlers echo `implode( "\n", $list )` raw, carrying an
    // annotated phpcs:ignore for WordPress.Security.EscapeOutput.OutputNotEscaped.
    // These tests pin that decision: the download is text/plain with a
    // Content-Disposition: attachment header, so any escaping (wp_kses(),
    // esc_html()) would change the bytes the user receives.

    public function test_stop_words_export_body_is_byte_identical_to_the_list() {
        $list = array( 'alpha & beta', '<script>', 'quote"inside', "single'quote", 'plain' );
        ASW_Settings::update( array( 'stop_words_list' => $list ) );

        $_POST['asw_export']       = '1';
        $_POST['asw_export_nonce'] = wp_create_nonce( 'asw_export_stop_words' );

        $output = $this->capture_export( array( $this->admin_page, 'handle_stop_words_export' ) );

        $this->assertSame( implode( "\n", $list ), $output );
        $this->assertStringNotContainsString( '&amp;', $output );
        $this->assertStringNotContainsString( '&lt;', $output );
        $this->assertStringNotContainsString( '&quot;', $output );
        $this->assertStringContainsString( '<script>', $output );
    }

    public function test_excluded_tokens_export_body_is_byte_identical_to_the_list() {
        $list = array( 'a&b', '<em>', 'has"quote', "it's", 'token' );
        ASW_Settings::update( array( 'excluded_tokens_list' => $list ) );

        $_POST['asw_export_excluded_tokens']       = '1';
        $_POST['asw_export_excluded_tokens_nonce'] = wp_create_nonce( 'asw_export_excluded_tokens' );

        $output = $this->capture_export( array( $this->admin_page, 'handle_excluded_tokens_export' ) );

        $this->assertSame( implode( "\n", $list ), $output );
        $this->assertStringNotContainsString( '&amp;', $output );
        $this->assertStringNotContainsString( '&lt;', $output );
        $this->assertStringNotContainsString( '&#039;', $output );
        $this->assertStringContainsString( '<em>', $output );
    }

    /**
     * Invoke an export handler, capturing its echoed body while suppressing the
     * "headers already sent" warning the real header() call emits under the
     * PHPUnit CLI SAPI (where output has already started).
     *
     * @param callable $handler The export handler to call.
     * @return string Captured output body.
     */
    private function capture_export( callable $handler ): string {
        set_error_handler( function ( $errno, $errstr ) {
            if ( false !== strpos( $errstr, 'headers already sent' )
                || false !== strpos( $errstr, 'Cannot modify header information' ) ) {
                return true;
            }
            return false;
        } );

        ob_start();
        try {
            $handler();
        } finally {
            $output = ob_get_clean();
            restore_error_handler();
        }

        return $output;
    }

    public function test_stop_words_tab_renders_existing_words() {
        ASW_Settings::update( array( 'stop_words_list' => array( 'spam', '=exact' ) ) );
        $_GET['page'] = 'antispam-wizard-stop-words';

        ob_start();
        $this->admin_page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'spam', $output );
        $this->assertStringContainsString( '=exact', $output );
        $this->assertStringContainsString( 'asw-word-tag', $output );
    }
}
