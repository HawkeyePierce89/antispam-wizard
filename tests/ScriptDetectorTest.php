<?php

use PHPUnit\Framework\TestCase;

class ScriptDetectorTest extends TestCase {

    public function test_detect_latin(): void {
        $this->assertSame( 'latin', ASW_Script_Detector::detect( 'Hello world this is a great article' ) );
    }

    public function test_detect_cyrillic(): void {
        $this->assertSame( 'cyrillic', ASW_Script_Detector::detect( 'Привет мир это отличная статья' ) );
    }

    public function test_detect_cjk_chinese(): void {
        $this->assertSame( 'cjk', ASW_Script_Detector::detect( '你好世界这是一篇好文章' ) );
    }

    public function test_detect_cjk_japanese(): void {
        $this->assertSame( 'cjk', ASW_Script_Detector::detect( 'こんにちは世界これは素晴らしい記事です' ) );
    }

    public function test_detect_cjk_korean(): void {
        $this->assertSame( 'cjk', ASW_Script_Detector::detect( '안녕하세요 세계 이것은 좋은 기사입니다' ) );
    }

    public function test_detect_arabic(): void {
        $this->assertSame( 'arabic', ASW_Script_Detector::detect( 'مرحبا بالعالم هذا مقال رائع' ) );
    }

    public function test_detect_devanagari(): void {
        $this->assertSame( 'devanagari', ASW_Script_Detector::detect( 'नमस्ते दुनिया यह एक बहुत अच्छा लेख है' ) );
    }

    public function test_detect_greek(): void {
        $this->assertSame( 'greek', ASW_Script_Detector::detect( 'Γεια σου κόσμε αυτό είναι ένα υπέροχο άρθρο' ) );
    }

    public function test_detect_hebrew(): void {
        $this->assertSame( 'hebrew', ASW_Script_Detector::detect( 'שלום עולם זהו מאמר נהדר' ) );
    }

    public function test_detect_thai(): void {
        $this->assertSame( 'thai', ASW_Script_Detector::detect( 'สวัสดีชาวโลกนี่เป็นบทความที่ดี' ) );
    }

    public function test_detect_bengali(): void {
        $this->assertSame( 'bengali', ASW_Script_Detector::detect( 'হ্যালো বিশ্ব এটি একটি দুর্দান্ত নিবন্ধ' ) );
    }

    public function test_detect_indic_tamil(): void {
        $this->assertSame( 'indic', ASW_Script_Detector::detect( 'வணக்கம் உலகம் இது ஒரு சிறந்த கட்டுரை' ) );
    }

    public function test_detect_indic_telugu(): void {
        $this->assertSame( 'indic', ASW_Script_Detector::detect( 'హలో ప్రపంచం ఇది గొప్ప వ్యాసం' ) );
    }

    public function test_mixed_text_returns_dominant_script(): void {
        // Russian text with English tech terms - Cyrillic should dominate.
        $text = 'Отличная статья про программирование на Python и JavaScript очень полезно для разработчиков';
        $this->assertSame( 'cyrillic', ASW_Script_Detector::detect( $text ) );
    }

    public function test_mixed_latin_and_cjk_returns_dominant(): void {
        // Mostly Chinese with a few English words.
        $text = '这篇关于PHP编程的文章非常好读一下推荐给大家';
        $this->assertSame( 'cjk', ASW_Script_Detector::detect( $text ) );
    }

    public function test_empty_string_returns_unknown(): void {
        $this->assertSame( 'unknown', ASW_Script_Detector::detect( '' ) );
    }

    public function test_numbers_only_returns_unknown(): void {
        $this->assertSame( 'unknown', ASW_Script_Detector::detect( '12345 67890' ) );
    }

    public function test_emoji_only_returns_unknown(): void {
        $this->assertSame( 'unknown', ASW_Script_Detector::detect( '😀🎉🔥💯' ) );
    }

    public function test_punctuation_only_returns_unknown(): void {
        $this->assertSame( 'unknown', ASW_Script_Detector::detect( '!@#$%^&*()' ) );
    }

    public function test_short_latin_text(): void {
        $this->assertSame( 'latin', ASW_Script_Detector::detect( 'Hi' ) );
    }

    public function test_short_cyrillic_text(): void {
        $this->assertSame( 'cyrillic', ASW_Script_Detector::detect( 'Да' ) );
    }

    public function test_single_character(): void {
        $this->assertSame( 'latin', ASW_Script_Detector::detect( 'A' ) );
    }

    public function test_script_groups_constant_has_all_groups(): void {
        $expected = array( 'latin', 'cyrillic', 'cjk', 'arabic', 'devanagari', 'greek', 'hebrew', 'thai', 'bengali', 'indic' );
        $this->assertSame( $expected, array_keys( ASW_Script_Detector::SCRIPT_GROUPS ) );
    }

    public function test_script_groups_values_are_strings(): void {
        foreach ( ASW_Script_Detector::SCRIPT_GROUPS as $key => $label ) {
            $this->assertIsString( $label, "Label for '$key' should be a string" );
            $this->assertNotEmpty( $label, "Label for '$key' should not be empty" );
        }
    }

    public function test_whitespace_only_returns_unknown(): void {
        $this->assertSame( 'unknown', ASW_Script_Detector::detect( "   \t\n  " ) );
    }

    public function test_letters_outside_modeled_scripts_return_unknown(): void {
        // Georgian is a valid Unicode letter script (\p{L}) but is not one of the
        // 10 modeled groups, so it survives the letter pre-filter yet matches no
        // pattern — exercising the empty-counts fall-through.
        $this->assertSame( 'unknown', ASW_Script_Detector::detect( 'ქართული' ) );
    }

    /**
     * U+30FC KATAKANA-HIRAGANA PROLONGED SOUND MARK matches both \p{Hiragana}
     * and \p{Katakana} once PCRE resolves script properties against
     * Script_Extensions (10.42+), and being Lm it survives the \p{L} pre-filter.
     * Summing per-pattern counts scored it twice, so 4 CJK letters beat 5 Latin
     * ones (cjk=6) and the comment was routed to the wrong classifier.
     */
    public function test_script_extensions_character_is_counted_once(): void {
        // 5 Latin letters vs 4 CJK letters — Latin dominates on any PCRE build.
        $this->assertSame( 'latin', ASW_Script_Detector::detect( 'abcde コーヒー' ) );
    }

    public function test_prolonged_sound_mark_word_still_detects_as_cjk(): void {
        // Same character, but now CJK genuinely dominates: counting it once must
        // not swing the verdict the other way.
        $this->assertSame( 'cjk', ASW_Script_Detector::detect( 'ab コーヒーメーカー' ) );
    }
}
