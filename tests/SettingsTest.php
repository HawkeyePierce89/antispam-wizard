<?php

use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase {

    protected function setUp(): void {
        // Reset wp_options between tests.
        global $wp_options;
        $wp_options = array();
    }

    public function test_integer_bounds_contains_submit_time() {
        $bounds = ASW_Settings::get_integer_bounds();
        $this->assertArrayHasKey( 'submit_time_min_seconds', $bounds );
        $this->assertSame( array( 1, 300 ), $bounds['submit_time_min_seconds'] );
    }

    public function test_defaults_contain_enabled_key() {
        $defaults = ASW_Settings::get_defaults();
        $this->assertArrayHasKey( 'enabled', $defaults );
        $this->assertTrue( $defaults['enabled'] );
    }

    public function test_defaults_contain_all_check_keys() {
        $defaults = ASW_Settings::get_defaults();

        $this->assertArrayHasKey( 'honeypot_enabled', $defaults );
        $this->assertArrayHasKey( 'submit_time_enabled', $defaults );
        $this->assertArrayHasKey( 'submit_time_min_seconds', $defaults );
        $this->assertArrayHasKey( 'stop_words_enabled', $defaults );
        $this->assertArrayHasKey( 'stop_words_list', $defaults );
        $this->assertArrayHasKey( 'links_enabled', $defaults );
        $this->assertArrayHasKey( 'links_max', $defaults );
        $this->assertArrayHasKey( 'emoji_enabled', $defaults );
        $this->assertArrayHasKey( 'emoji_max', $defaults );
        $this->assertArrayHasKey( 'spacing_enabled', $defaults );
        $this->assertArrayHasKey( 'spacing_min_words_count', $defaults );
        $this->assertArrayHasKey( 'spacing_short_word_len', $defaults );
        $this->assertArrayHasKey( 'spacing_short_word_ratio', $defaults );
        $this->assertArrayHasKey( 'spacing_space_ratio', $defaults );
        $this->assertArrayHasKey( 'multi_lang_enabled', $defaults );
        $this->assertArrayHasKey( 'multi_lang_threshold', $defaults );
        $this->assertArrayHasKey( 'duplicate_enabled', $defaults );
        $this->assertArrayHasKey( 'duplicate_threshold', $defaults );
        $this->assertArrayHasKey( 'duplicate_ttl', $defaults );
        $this->assertArrayHasKey( 'bayesian_enabled', $defaults );
        $this->assertArrayHasKey( 'bayesian_threshold', $defaults );
        $this->assertArrayHasKey( 'similarity_enabled', $defaults );
        $this->assertArrayHasKey( 'similarity_threshold', $defaults );
        $this->assertArrayHasKey( 'min_message_length', $defaults );
    }

    public function test_default_values() {
        $defaults = ASW_Settings::get_defaults();

        $this->assertTrue( $defaults['honeypot_enabled'] );
        $this->assertTrue( $defaults['submit_time_enabled'] );
        $this->assertSame( 5, $defaults['submit_time_min_seconds'] );
        $this->assertTrue( $defaults['links_enabled'] );
        $this->assertSame( 2, $defaults['links_max'] );
        $this->assertSame( 4, $defaults['emoji_max'] );
        $this->assertSame( 3, $defaults['multi_lang_threshold'] );
        $this->assertSame( 3, $defaults['duplicate_threshold'] );
        $this->assertSame( 3600, $defaults['duplicate_ttl'] );
    }

    public function test_get_all_returns_defaults_when_no_saved() {
        $all = ASW_Settings::get_all();

        $this->assertSame( ASW_Settings::get_defaults(), $all );
    }

    public function test_get_all_merges_saved_with_defaults() {
        update_option( ASW_Settings::OPTION_KEY, array(
            'links_max' => 5,
        ) );

        $all = ASW_Settings::get_all();

        $this->assertSame( 5, $all['links_max'] );
        // Other defaults still present.
        $this->assertTrue( $all['links_enabled'] );
        $this->assertSame( 4, $all['emoji_max'] );
        $this->assertTrue( $all['honeypot_enabled'] );
        $this->assertTrue( $all['submit_time_enabled'] );
        $this->assertSame( 5, $all['submit_time_min_seconds'] );
    }

    public function test_get_single_setting() {
        $this->assertSame( 2, ASW_Settings::get( 'links_max' ) );
    }

    public function test_get_unknown_key_returns_default() {
        $this->assertNull( ASW_Settings::get( 'nonexistent' ) );
        $this->assertSame( 'fallback', ASW_Settings::get( 'nonexistent', 'fallback' ) );
    }

    public function test_update_merges_values() {
        ASW_Settings::update( array( 'links_max' => 10 ) );

        $this->assertSame( 10, ASW_Settings::get( 'links_max' ) );
        // Other settings unchanged.
        $this->assertSame( 4, ASW_Settings::get( 'emoji_max' ) );
    }

    public function test_cleanup_removes_option() {
        update_option( ASW_Settings::OPTION_KEY, array( 'links_max' => 10 ) );

        ASW_Settings::cleanup();

        // After cleanup, get_all returns defaults again.
        $this->assertSame( ASW_Settings::get_defaults(), ASW_Settings::get_all() );
    }

    public function test_register_method_removed(): void {
        $this->assertFalse(
            method_exists( ASW_Settings::class, 'register' ),
            'Settings::register() should have been removed (dead code)'
        );
    }

    public function test_sanitize_method_removed(): void {
        $this->assertFalse(
            method_exists( ASW_Settings::class, 'sanitize' ),
            'Settings::sanitize() should have been removed (dead code, was only the register callback)'
        );
    }

    public function test_defaults_contain_similarity_keys() {
        $defaults = ASW_Settings::get_defaults();

        $this->assertArrayHasKey( 'similarity_enabled', $defaults );
        $this->assertArrayHasKey( 'similarity_threshold', $defaults );
    }

    public function test_similarity_default_values() {
        $defaults = ASW_Settings::get_defaults();

        $this->assertTrue( $defaults['similarity_enabled'] );
        $this->assertSame( 0.6, $defaults['similarity_threshold'] );
    }

    public function test_defaults_contain_min_message_length() {
        $defaults = ASW_Settings::get_defaults();

        $this->assertArrayHasKey( 'min_message_length', $defaults );
        $this->assertSame( 10, $defaults['min_message_length'] );
    }

    // --- Script stop words tests ---

    public function test_get_default_stop_words_for_latin(): void {
        $words = ASW_Settings::get_default_stop_words_for_script( 'latin' );
        $this->assertNotEmpty( $words );
        $this->assertContains( 'viagra', $words );
        $this->assertContains( 'casino', $words );
    }

    public function test_get_default_stop_words_for_cyrillic(): void {
        $words = ASW_Settings::get_default_stop_words_for_script( 'cyrillic' );
        $this->assertNotEmpty( $words );
        $this->assertContains( 'виагра', $words );
        $this->assertContains( 'казино', $words );
    }

    public function test_get_default_stop_words_for_cjk(): void {
        $words = ASW_Settings::get_default_stop_words_for_script( 'cjk' );
        $this->assertNotEmpty( $words );
        $this->assertContains( '赌场', $words );
    }

    public function test_get_default_stop_words_for_arabic(): void {
        $words = ASW_Settings::get_default_stop_words_for_script( 'arabic' );
        $this->assertNotEmpty( $words );
        $this->assertContains( 'كازينو', $words );
    }

    public function test_get_default_stop_words_for_devanagari(): void {
        $words = ASW_Settings::get_default_stop_words_for_script( 'devanagari' );
        $this->assertNotEmpty( $words );
        $this->assertContains( 'मुफ्त', $words );
    }

    public function test_get_default_stop_words_for_unknown_script_returns_empty(): void {
        $words = ASW_Settings::get_default_stop_words_for_script( 'greek' );
        $this->assertSame( array(), $words );
    }

    public function test_get_default_stop_words_for_nonexistent_script_returns_empty(): void {
        $words = ASW_Settings::get_default_stop_words_for_script( 'klingon' );
        $this->assertSame( array(), $words );
    }

    public function test_defaults_contain_cf7_enabled(): void {
        $defaults = ASW_Settings::get_defaults();
        $this->assertArrayHasKey( 'cf7_enabled', $defaults );
        $this->assertTrue( $defaults['cf7_enabled'] );
    }

    public function test_defaults_contain_shadow_display_enabled(): void {
        $defaults = ASW_Settings::get_defaults();
        $this->assertArrayHasKey( 'shadow_display_enabled', $defaults );
        $this->assertTrue( $defaults['shadow_display_enabled'] );
        $this->assertTrue( ASW_Settings::get( 'shadow_display_enabled' ) );
    }
}
