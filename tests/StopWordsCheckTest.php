<?php

use PHPUnit\Framework\TestCase;

class StopWordsCheckTest extends TestCase {

    private ANTISPAMWIZARD_Stop_Words_Check $check;

    protected function setUp(): void {
        parent::setUp();
        global $wp_options;
        $wp_options = array();
        $this->check = new ANTISPAMWIZARD_Stop_Words_Check();
    }

    protected function tearDown(): void {
        global $wp_options;
        $wp_options = array();
        parent::tearDown();
    }

    private function set_stop_words( array $words, bool $enabled = true ): void {
        ANTISPAMWIZARD_Settings::update( array(
            'stop_words_enabled' => $enabled,
            'stop_words_list'    => $words,
        ) );
    }

    private function make_comment( string $content ): array {
        return array( 'comment_content' => $content );
    }

    public function test_substring_match_detects_spam(): void {
        $this->set_stop_words( array( 'viagra' ) );
        $result = $this->check->check( $this->make_comment( 'Buy cheap viagra now!' ) );
        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'stop_words', $result->get_check_name() );
        $this->assertSame( 'viagra', $result->get_details() );
    }

    public function test_substring_match_case_insensitive(): void {
        $this->set_stop_words( array( 'viagra' ) );
        $result = $this->check->check( $this->make_comment( 'Buy VIAGRA Today' ) );
        $this->assertTrue( $result->is_spam() );
    }

    public function test_substring_match_word_in_middle(): void {
        $this->set_stop_words( array( 'casino' ) );
        $result = $this->check->check( $this->make_comment( 'Visit our online casino today' ) );
        $this->assertTrue( $result->is_spam() );
    }

    public function test_exact_match_full_text(): void {
        $this->set_stop_words( array( '=buy now' ) );
        $result = $this->check->check( $this->make_comment( 'buy now' ) );
        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'buy now', $result->get_details() );
    }

    public function test_exact_match_rejects_partial(): void {
        $this->set_stop_words( array( '=buy now' ) );
        $result = $this->check->check( $this->make_comment( 'please buy now ok' ) );
        $this->assertFalse( $result->is_spam() );
    }

    public function test_exact_match_case_insensitive(): void {
        $this->set_stop_words( array( '=spam message' ) );
        $result = $this->check->check( $this->make_comment( 'SPAM MESSAGE' ) );
        $this->assertTrue( $result->is_spam() );
    }

    public function test_equals_only_pattern_is_skipped(): void {
        $this->set_stop_words( array( '=' ) );
        $result = $this->check->check( $this->make_comment( 'any text here' ) );
        $this->assertFalse( $result->is_spam() );
    }

    public function test_clean_text_passes(): void {
        $this->set_stop_words( array( 'viagra', 'casino' ) );
        $result = $this->check->check( $this->make_comment( 'This is a perfectly normal comment about gardening.' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'not found', $result->get_details() );
    }

    public function test_emoji_in_text_stripped_before_match(): void {
        $this->set_stop_words( array( 'free money' ) );
        // Emoji between words should be stripped, allowing substring match.
        $result = $this->check->check( $this->make_comment( "free \u{1F4B0} money" ) );
        $this->assertTrue( $result->is_spam() );
    }

    public function test_multiple_spaces_normalized(): void {
        $this->set_stop_words( array( 'buy now' ) );
        $result = $this->check->check( $this->make_comment( 'buy    now' ) );
        $this->assertTrue( $result->is_spam() );
    }

    public function test_disabled_check_returns_not_spam(): void {
        $this->set_stop_words( array( 'viagra' ), false );
        $result = $this->check->check( $this->make_comment( 'Buy viagra now' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'check disabled', $result->get_details() );
    }

    public function test_empty_stop_words_list(): void {
        $this->set_stop_words( array() );
        $result = $this->check->check( $this->make_comment( 'anything' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'no stop words configured', $result->get_details() );
    }

    public function test_empty_comment(): void {
        $this->set_stop_words( array( 'spam' ) );
        $result = $this->check->check( $this->make_comment( '' ) );
        $this->assertFalse( $result->is_spam() );
    }

    public function test_multiple_stop_words_first_match_wins(): void {
        $this->set_stop_words( array( 'alpha', 'beta', 'gamma' ) );
        $result = $this->check->check( $this->make_comment( 'this has beta and gamma' ) );
        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'beta', $result->get_details() );
    }

    public function test_unicode_stop_word(): void {
        $this->set_stop_words( array( 'спам' ) );
        $result = $this->check->check( $this->make_comment( 'Это СПАМ сообщение' ) );
        $this->assertTrue( $result->is_spam() );
    }

    public function test_implements_check_interface(): void {
        $this->assertInstanceOf( ANTISPAMWIZARD_Check::class, $this->check );
    }

    // --- Script-aware stop words tests ---

    public function test_script_aware_detects_cyrillic_stop_word(): void {
        // User has no custom stop words, but script-aware mode adds Cyrillic defaults.
        $this->set_stop_words( array() );
        $check  = new ANTISPAMWIZARD_Stop_Words_Check( true );
        $result = $check->check( $this->make_comment( 'Играйте в виагра онлайн' ) );
        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'виагра', $result->get_details() );
    }

    public function test_script_aware_detects_cjk_stop_word(): void {
        $this->set_stop_words( array() );
        $check  = new ANTISPAMWIZARD_Stop_Words_Check( true );
        $result = $check->check( $this->make_comment( '欢迎来到赌场游戏' ) );
        $this->assertTrue( $result->is_spam() );
        $this->assertSame( '赌场', $result->get_details() );
    }

    public function test_script_aware_detects_arabic_stop_word(): void {
        $this->set_stop_words( array() );
        $check  = new ANTISPAMWIZARD_Stop_Words_Check( true );
        $result = $check->check( $this->make_comment( 'العب في كازينو اليوم' ) );
        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'كازينو', $result->get_details() );
    }

    public function test_script_aware_detects_devanagari_stop_word(): void {
        $this->set_stop_words( array() );
        $check  = new ANTISPAMWIZARD_Stop_Words_Check( true );
        $result = $check->check( $this->make_comment( 'यहां मुफ्त में खेलो' ) );
        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'मुफ्त', $result->get_details() );
    }

    public function test_script_aware_merges_user_custom_words(): void {
        // User has custom word, script-aware also adds defaults.
        $this->set_stop_words( array( 'my_custom_spam' ) );
        $check  = new ANTISPAMWIZARD_Stop_Words_Check( true );
        $result = $check->check( $this->make_comment( 'This contains my_custom_spam text' ) );
        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'my_custom_spam', $result->get_details() );
    }

    public function test_script_aware_user_words_apply_to_all_scripts(): void {
        // Custom stop word should still match even for non-Latin text.
        $this->set_stop_words( array( 'спам' ) );
        $check  = new ANTISPAMWIZARD_Stop_Words_Check( true );
        $result = $check->check( $this->make_comment( 'Это СПАМ сообщение' ) );
        $this->assertTrue( $result->is_spam() );
    }

    public function test_script_aware_no_script_words_fallback(): void {
        // Greek has no script-specific defaults, but user words still apply.
        $this->set_stop_words( array( 'spam' ) );
        $check  = new ANTISPAMWIZARD_Stop_Words_Check( true );
        $result = $check->check( $this->make_comment( 'Ελληνικά spam κείμενο' ) );
        $this->assertTrue( $result->is_spam() );
    }

    public function test_script_aware_clean_text_passes(): void {
        $this->set_stop_words( array() );
        $check  = new ANTISPAMWIZARD_Stop_Words_Check( true );
        $result = $check->check( $this->make_comment( 'Обычный нормальный комментарий без спама' ) );
        $this->assertFalse( $result->is_spam() );
    }

    public function test_non_script_aware_mode_unchanged(): void {
        // Without script_aware, Cyrillic defaults should NOT be added.
        $this->set_stop_words( array() );
        $check  = new ANTISPAMWIZARD_Stop_Words_Check( false );
        $result = $check->check( $this->make_comment( 'Играйте в виагра онлайн' ) );
        $this->assertFalse( $result->is_spam() );
    }
}
