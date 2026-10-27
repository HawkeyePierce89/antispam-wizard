<?php

use PHPUnit\Framework\TestCase;

class MultiLangCheckTest extends TestCase {

    private ANTISPAMWIZARD_Multi_Lang_Check $check;

    protected function setUp(): void {
        parent::setUp();
        global $wp_options;
        $wp_options = array();
        $this->check = new ANTISPAMWIZARD_Multi_Lang_Check();
    }

    protected function tearDown(): void {
        global $wp_options;
        $wp_options = array();
        parent::tearDown();
    }

    private function make_comment( string $content ): array {
        return array( 'comment_content' => $content );
    }

    public function test_pure_latin_passes(): void {
        $result = $this->check->check( $this->make_comment( 'This is a normal English comment about coding.' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'multi_lang', $result->get_check_name() );
    }

    public function test_pure_cyrillic_passes(): void {
        $result = $this->check->check( $this->make_comment( 'Это нормальный русский комментарий о программировании.' ) );
        $this->assertFalse( $result->is_spam() );
    }

    public function test_mixed_latin_cyrillic_word_detected(): void {
        // Words mixing Latin and Cyrillic characters (e.g., 'а' Cyrillic looks like 'a' Latin).
        // "hеllо wоrld tеst" with Cyrillic е, о mixed into Latin words.
        $result = $this->check->check( $this->make_comment( "h\xD0\xB5ll\xD0\xBE w\xD0\xBErld t\xD0\xB5st" ) );
        $this->assertTrue( $result->is_spam() );
        $this->assertStringContainsString( 'mixed scripts', $result->get_details() );
    }

    public function test_below_threshold_passes(): void {
        // Only one mixed-script word, threshold is 3.
        $result = $this->check->check( $this->make_comment( "normal text h\xD0\xB5llo world" ) );
        $this->assertFalse( $result->is_spam() );
    }

    public function test_at_threshold_is_spam(): void {
        // Exactly 3 mixed-script words (threshold default = 3).
        $result = $this->check->check( $this->make_comment( "h\xD0\xB5llo w\xD0\xBErld t\xD0\xB5st normal" ) );
        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'mixed scripts 3/3', $result->get_details() );
    }

    public function test_custom_threshold(): void {
        ANTISPAMWIZARD_Settings::update( array( 'multi_lang_threshold' => 5 ) );
        // 3 mixed words, threshold 5 - should pass.
        $result = $this->check->check( $this->make_comment( "h\xD0\xB5llo w\xD0\xBErld t\xD0\xB5st" ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'mixed scripts 3/5', $result->get_details() );
    }

    public function test_disabled_check_returns_not_spam(): void {
        ANTISPAMWIZARD_Settings::update( array( 'multi_lang_enabled' => false ) );
        $result = $this->check->check( $this->make_comment( "h\xD0\xB5llo w\xD0\xBErld t\xD0\xB5st" ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'check disabled', $result->get_details() );
    }

    public function test_empty_comment(): void {
        $result = $this->check->check( $this->make_comment( '' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'empty comment', $result->get_details() );
    }

    public function test_i_character_skipped(): void {
        // The letter 'i' should be skipped per Go reference implementation.
        // A word like "iПривет" - 'i' is skipped, rest is Cyrillic, so only one script.
        $result = $this->check->check( $this->make_comment( "i\xD0\x9F\xD1\x80\xD0\xB8\xD0\xB2\xD0\xB5\xD1\x82 normal text here" ) );
        $this->assertFalse( $result->is_spam() );
        // Pin the count, not just the verdict: without the 'i' skip the word would
        // be Latin + Cyrillic, and 1/3 is still below the default threshold.
        $this->assertSame( 'mixed scripts 0/3', $result->get_details() );
    }

    public function test_numbers_and_punctuation_ignored(): void {
        // Numbers and punctuation should not count as script mixing.
        $result = $this->check->check( $this->make_comment( 'Hello123! World456? Test789.' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'mixed scripts 0/3', $result->get_details() );
    }

    public function test_separate_script_words_not_mixed(): void {
        // Words in different scripts but each word uses one script - not mixed.
        $result = $this->check->check( $this->make_comment( "Hello world Привет мир Bonjour monde" ) );
        $this->assertFalse( $result->is_spam() );
    }

    public function test_mathematical_symbols(): void {
        // Mathematical alphanumeric symbols mixed with regular text.
        // U+1D400 = 𝐀 (Mathematical Bold Capital A).
        $math_a = "\xF0\x9D\x90\x80";
        $result = $this->check->check( $this->make_comment(
            "test{$math_a}word test{$math_a}again test{$math_a}more"
        ) );
        // Mathematical symbols belong to Common script and should be skipped,
        // not counted as a separate script. No false positive here.
        $this->assertFalse( $result->is_spam() );
    }

    public function test_hyphenated_words_split(): void {
        // Hyphens should split words, so "hello-мир" is two single-script words.
        $result = $this->check->check( $this->make_comment( "hello-\xD0\xBC\xD0\xB8\xD1\x80 test-\xD1\x82\xD0\xB5\xD1\x81\xD1\x82 good-\xD1\x85\xD0\xBE\xD1\x80\xD0\xBE\xD1\x88\xD0\xBE" ) );
        $this->assertFalse( $result->is_spam() );
    }

    public function test_long_single_word_is_classified_in_linear_time(): void {
        // One unbroken word three times the detector's truncation cap, with a single
        // Cyrillic 'е' appended LAST: the trailing position denies a per-character
        // implementation its "two scripts seen" short circuit, so the whole word is
        // walked. Quadratic per-character scanning costs ~6.4 s here; a linear
        // whole-word scan costs ~0.001 s.
        $word = str_repeat( 'a', ANTISPAMWIZARD_Detector::MAX_COMMENT_LENGTH * 3 ) . "\xD0\xB5";

        ANTISPAMWIZARD_Settings::update( array( 'multi_lang_threshold' => 1 ) );

        $started  = microtime( true );
        $result   = $this->check->check( $this->make_comment( $word ) );
        $elapsed  = microtime( true ) - $started;

        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'mixed scripts 1/1', $result->get_details() );
        $this->assertLessThan(
            1.0,
            $elapsed,
            sprintf( 'Multi-language check took %.3f s on a single long word.', $elapsed )
        );
    }

    public function test_long_single_script_word_is_not_flagged(): void {
        // The same word without the Cyrillic character: a single script, so no verdict
        // change is allowed on long input.
        $word = str_repeat( 'a', ANTISPAMWIZARD_Detector::MAX_COMMENT_LENGTH * 3 );

        $result = $this->check->check( $this->make_comment( $word ) );

        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'mixed scripts 0/3', $result->get_details() );
    }

    public function test_script_extensions_character_counts_as_one_script(): void {
        // U+A8F1 COMBINING DEVANAGARI SIGN AVAGRAHA matches both \p{Devanagari} and
        // \p{Bengali}, because PCRE resolves \p{Script} against Script_Extensions.
        // It is neither Common nor Inherited, so it survives the ignore pass and must
        // still be claimed by exactly one script - the first that matches.
        //
        // Script_Extensions resolution only arrived in PCRE2 10.42; on 10.39 (PHP 8.1)
        // U+A8F1 matches \p{Devanagari} alone, so this case cannot tell a consuming
        // implementation from a test-only one. Skip loudly rather than pass vacuously.
        if ( 1 !== preg_match( '/\p{Bengali}/u', "\u{A8F1}" ) ) {
            $this->markTestSkipped( 'PCRE ' . PCRE_VERSION . ' predates Script_Extensions resolution.' );
        }

        $word = "\u{0915}\u{A8F1}\u{0930}";

        ANTISPAMWIZARD_Settings::update( array( 'multi_lang_threshold' => 1 ) );
        $result = $this->check->check( $this->make_comment( "{$word} {$word} {$word}" ) );

        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'mixed scripts 0/1', $result->get_details() );
    }

    public function test_combining_mark_ignored(): void {
        // U+0951 DEVANAGARI STRESS SIGN UDATTA is Inherited and matches four of the
        // script patterns; the Inherited skip is what keeps ordinary Vedic Devanagari
        // and Arabic diacritics from being read as multi-script.
        $devanagari = "\u{0915}\u{0951}\u{0930}";
        $arabic     = "\u{0645}\u{064B}\u{0631}";

        ANTISPAMWIZARD_Settings::update( array( 'multi_lang_threshold' => 1 ) );

        $result = $this->check->check( $this->make_comment( $devanagari ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'mixed scripts 0/1', $result->get_details() );

        $result = $this->check->check( $this->make_comment( $arabic ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'mixed scripts 0/1', $result->get_details() );
    }

    public function test_non_ascii_digit_ignored(): void {
        // U+0966 DEVANAGARI DIGIT ZERO has Script=Devanagari, not Common, so only the
        // \d term of the ignore pattern keeps it out of the Latin word's script set.
        ANTISPAMWIZARD_Settings::update( array( 'multi_lang_threshold' => 1 ) );
        $result = $this->check->check( $this->make_comment( "hello\u{0966}" ) );

        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'mixed scripts 0/1', $result->get_details() );
    }

    public function test_script_specific_punctuation_belongs_to_its_script(): void {
        // U+05BE HEBREW PUNCTUATION MAQAF is punctuation, but its script is Hebrew:
        // it must be claimed by \p{Hebrew} and never fall through to the Other bucket.
        ANTISPAMWIZARD_Settings::update( array( 'multi_lang_threshold' => 1 ) );
        $result = $this->check->check( $this->make_comment( "\u{05E9}\u{05BE}\u{05DC}" ) );

        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'mixed scripts 0/1', $result->get_details() );
    }

    public function test_unlisted_scripts_share_the_other_bucket(): void {
        // Ethiopic and Cherokee are both outside SCRIPT_PATTERNS, so they collapse
        // into the single Other bucket and a word using both is not mixed-script.
        ANTISPAMWIZARD_Settings::update( array( 'multi_lang_threshold' => 1 ) );
        $result = $this->check->check( $this->make_comment( "\u{1200}\u{13A0}" ) );

        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'mixed scripts 0/1', $result->get_details() );
    }

    public function test_listed_script_mixed_with_unlisted_script_flagged(): void {
        // Latin plus Ethiopic: the Other bucket is the second script. This is the
        // guard that the thirteenth bucket still exists at all.
        ANTISPAMWIZARD_Settings::update( array( 'multi_lang_threshold' => 1 ) );
        $result = $this->check->check( $this->make_comment( "hello\u{1200}" ) );

        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'mixed scripts 1/1', $result->get_details() );
    }

    public function test_unlisted_punctuation_and_symbols_are_not_a_bucket(): void {
        // U+1361 ETHIOPIC WORDSPACE (\p{P}) and U+0F13 TIBETAN MARK CARET (\p{S})
        // belong to no listed script and are neither Common nor Inherited, so they
        // survive the ignore pass and are the only characters that ever reach the
        // Other bucket's \p{P}/\p{S} exclusions. Neither may count as a script.
        ANTISPAMWIZARD_Settings::update( array( 'multi_lang_threshold' => 1 ) );
        $result = $this->check->check( $this->make_comment( "hello\u{1361}\u{0F13}" ) );

        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'mixed scripts 0/1', $result->get_details() );
    }

    public function test_invalid_utf8_does_not_disable_the_check(): void {
        // preg_split() returns false on a malformed subject, so a single stray byte
        // would switch the whole check off for the submission - a one-byte evasion of
        // an attacker-controlled field. The malformed sequences must be dropped and
        // the rest classified: "wоrld" here is Latin + Cyrillic and must still count.
        ANTISPAMWIZARD_Settings::update( array( 'multi_lang_threshold' => 1 ) );
        $result = $this->check->check( $this->make_comment( "hello \xC3\x28 \xD0 w\xD0\xBErld" ) );

        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'mixed scripts 1/1', $result->get_details() );
    }

    public function test_invalid_utf8_alone_is_not_flagged(): void {
        // The substitution character mb_convert_encoding() leaves behind is Common, so
        // a body of nothing but malformed bytes must not become a script bucket.
        ANTISPAMWIZARD_Settings::update( array( 'multi_lang_threshold' => 1 ) );
        $result = $this->check->check( $this->make_comment( "\xC3\x28 \xD0 \xF0\x28\x8C\x28" ) );

        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'mixed scripts 0/1', $result->get_details() );
    }

    /**
     * mbstring.substitute_character is a process-wide runtime setting any other
     * plugin can change. Given a letter codepoint, the bytes dropped from a
     * malformed body would come back as Latin letters and turn a legitimate
     * single-script Cyrillic comment into a Latin+Cyrillic "mixed script" hit.
     * ANTISPAMWIZARD_Detector::sanitize_utf8() pins the substitute, so the verdict must not
     * depend on the ambient setting - and the setting must be restored.
     */
    public function test_verdict_does_not_depend_on_mbstring_substitute_character(): void {
        $previous = mb_substitute_character();
        mb_substitute_character( 0x41 ); // 'A' - Latin.

        try {
            ANTISPAMWIZARD_Settings::update( array( 'multi_lang_threshold' => 1 ) );
            $result = $this->check->check( $this->make_comment( "\xD0\xBF\xD1\x80\xD0\xB8\xD0\xB2\xC3\xD0\xB5\xD1\x82" ) );

            $this->assertFalse( $result->is_spam() );
            $this->assertSame( 'mixed scripts 0/1', $result->get_details() );
            $this->assertSame( 0x41, mb_substitute_character() );
        } finally {
            mb_substitute_character( $previous );
        }
    }

    /**
     * Japanese has no inter-word spaces, so this check's whitespace/hyphen split
     * makes each sentence one "word" that routinely mixes kanji with both kana.
     * With Han, Hiragana and Katakana as separate buckets an ordinary
     * three-paragraph Japanese comment scored 3 mixed-script words and was
     * flagged at the shipped default threshold.
     */
    public function test_ordinary_japanese_comment_is_not_flagged(): void {
        $result = $this->check->check( $this->make_comment(
            "この記事はとても参考になりました。\n\nコーヒーメーカーを買いました。\n\n私も同じ問題がありました。"
        ) );

        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'mixed scripts 0/3', $result->get_details() );
    }

    public function test_korean_with_hanja_is_not_flagged(): void {
        // Hangul and Han share the CJK bucket for the same reason.
        ANTISPAMWIZARD_Settings::update( array( 'multi_lang_threshold' => 1 ) );
        $result = $this->check->check( $this->make_comment( '韓國語댓글입니다' ) );

        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'mixed scripts 0/1', $result->get_details() );
    }

    /**
     * The CJK bucket merge closed the intra-CJK case but not the root cause:
     * embedded Latin (product names, brands, romaji) is routine in Japanese and
     * Chinese and is written without spaces around it, so three ordinary lines
     * still scored 3 and were flagged at the shipped default threshold of 3.
     */
    public function test_ordinary_japanese_comment_with_embedded_latin_is_not_flagged(): void {
        $result = $this->check->check( $this->make_comment(
            "はじめまして。WordPressでブログを始めました。\n記事はGoogleの検索で見つけました。\niPhoneからでも読みやすいですね。"
        ) );

        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'mixed scripts 0/3', $result->get_details() );
    }

    public function test_ordinary_chinese_comment_with_embedded_latin_is_not_flagged(): void {
        $result = $this->check->check( $this->make_comment(
            "我用WordPress架設網站。\n從Google搜尋過來的。\n用iPhone看也很方便。"
        ) );

        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'mixed scripts 0/3', $result->get_details() );
    }

    /**
     * Thai is the other space-less script in SCRIPT_PATTERNS and has the
     * identical false positive; the exemption must cover it too.
     */
    public function test_ordinary_thai_comment_with_embedded_latin_is_not_flagged(): void {
        ANTISPAMWIZARD_Settings::update( array( 'multi_lang_threshold' => 1 ) );
        $result = $this->check->check( $this->make_comment( 'ผมใช้WordPressครับ' ) );

        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'mixed scripts 0/1', $result->get_details() );
    }

    /**
     * The exemption is exactly two buckets wide. A third script in the same
     * word is script mixing again, so embedded Latin must not become a way to
     * smuggle a homoglyph word past the check.
     */
    public function test_space_less_script_with_latin_and_a_third_script_is_flagged(): void {
        ANTISPAMWIZARD_Settings::update( array( 'multi_lang_threshold' => 1 ) );
        $result = $this->check->check( $this->make_comment( "WordPressで日本\xD0\xBFароль" ) );

        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'mixed scripts 1/1', $result->get_details() );
    }

    /**
     * The attack this check exists to catch is unaffected by the exemption.
     */
    public function test_latin_cyrillic_homoglyph_word_is_still_flagged(): void {
        ANTISPAMWIZARD_Settings::update( array( 'multi_lang_threshold' => 1 ) );
        $result = $this->check->check( $this->make_comment( "p\xD0\xB0ypal" ) );

        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'mixed scripts 1/1', $result->get_details() );
    }

    /**
     * Only Latin is exempted alongside a space-less script; a space-less script
     * paired with any other script is still two buckets and still flagged.
     * Collapsing the CJK writing systems into one bucket must not stop a CJK
     * word mixed with an unrelated script from counting as two buckets.
     */
    public function test_cjk_mixed_with_another_script_is_still_flagged(): void {
        ANTISPAMWIZARD_Settings::update( array( 'multi_lang_threshold' => 1 ) );
        $result = $this->check->check( $this->make_comment( "\xD0\xBFассворд日本" ) );

        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'mixed scripts 1/1', $result->get_details() );
    }

    public function test_implements_check_interface(): void {
        $this->assertInstanceOf( ANTISPAMWIZARD_Check::class, $this->check );
    }
}
