<?php

use PHPUnit\Framework\TestCase;

class TokenizerTest extends TestCase {

    private ANTISPAMWIZARD_Tokenizer $tokenizer;

    protected function setUp(): void {
        parent::setUp();
        $this->tokenizer = new ANTISPAMWIZARD_Tokenizer();
    }

    public function test_constructor_accepts_custom_excluded_tokens(): void {
        $custom_tokens = array( 'hello', 'world' );
        $tokenizer     = new ANTISPAMWIZARD_Tokenizer( $custom_tokens );
        $tokens        = $tokenizer->tokenize( 'hello world great test' );

        $this->assertNotContains( 'hello', $tokens );
        $this->assertNotContains( 'world', $tokens );
        $this->assertContains( 'great', $tokens );
        $this->assertContains( 'test', $tokens );
    }

    public function test_custom_excluded_tokens_replace_defaults(): void {
        // 'the' is a default excluded token; with custom list it should NOT be excluded.
        $custom_tokens = array( 'custom' );
        $tokenizer     = new ANTISPAMWIZARD_Tokenizer( $custom_tokens );
        $tokens        = $tokenizer->tokenize( 'the custom example' );

        $this->assertContains( 'the', $tokens );
        $this->assertNotContains( 'custom', $tokens );
        $this->assertContains( 'example', $tokens );
    }

    public function test_empty_excluded_tokens_excludes_nothing(): void {
        $tokenizer = new ANTISPAMWIZARD_Tokenizer( array() );
        $tokens    = $tokenizer->tokenize( 'the and for are but' );

        $this->assertContains( 'the', $tokens );
        $this->assertContains( 'and', $tokens );
        $this->assertContains( 'for', $tokens );
        $this->assertContains( 'are', $tokens );
        $this->assertContains( 'but', $tokens );
    }

    public function test_default_excluded_tokens_from_settings(): void {
        // When no custom list provided, should use defaults from settings.
        $defaults  = ANTISPAMWIZARD_Settings::get_defaults();
        $tokenizer = new ANTISPAMWIZARD_Tokenizer();
        $tokens    = $tokenizer->tokenize( 'the quick brown fox' );

        // 'the' is in the default excluded tokens list.
        $this->assertNotContains( 'the', $tokens );
        $this->assertContains( 'quick', $tokens );
    }

    public function test_basic_tokenization(): void {
        $tokens = $this->tokenizer->tokenize( 'Hello world this great test' );

        $this->assertContains( 'hello', $tokens );
        $this->assertContains( 'world', $tokens );
        $this->assertContains( 'great', $tokens );
        $this->assertContains( 'test', $tokens );
    }

    public function test_excludes_short_tokens(): void {
        $tokens = $this->tokenizer->tokenize( 'a is big' );

        $this->assertNotContains( 'a', $tokens );
        $this->assertNotContains( 'is', $tokens );
        $this->assertContains( 'big', $tokens );
    }

    public function test_excludes_english_stop_words(): void {
        $tokens = $this->tokenizer->tokenize( 'the quick brown fox for jumping' );

        $this->assertNotContains( 'the', $tokens );
        $this->assertNotContains( 'for', $tokens );
        $this->assertContains( 'quick', $tokens );
        $this->assertContains( 'brown', $tokens );
        $this->assertContains( 'jumping', $tokens );
    }

    public function test_excludes_russian_stop_words(): void {
        $tokens = $this->tokenizer->tokenize( 'что это как результат работы программы' );

        $this->assertNotContains( 'что', $tokens );
        $this->assertNotContains( 'как', $tokens );
        $this->assertNotContains( 'это', $tokens );
        $this->assertContains( 'результат', $tokens );
    }

    public function test_handles_unicode(): void {
        $tokens = $this->tokenizer->tokenize( 'программирование отличная статья' );

        $this->assertContains( 'программирование', $tokens );
        $this->assertContains( 'отличная', $tokens );
        $this->assertContains( 'статья', $tokens );
    }

    public function test_handles_mixed_punctuation(): void {
        $tokens = $this->tokenizer->tokenize( 'hello, world! great...stuff (here)' );

        $this->assertContains( 'hello', $tokens );
        $this->assertContains( 'world', $tokens );
        $this->assertContains( 'great', $tokens );
        $this->assertContains( 'stuff', $tokens );
        $this->assertContains( 'here', $tokens );
    }

    public function test_lowercases_text(): void {
        $tokens = $this->tokenizer->tokenize( 'HELLO World MiXeD' );

        $this->assertContains( 'hello', $tokens );
        $this->assertContains( 'world', $tokens );
        $this->assertContains( 'mixed', $tokens );
    }

    public function test_empty_string_returns_empty_array(): void {
        $tokens = $this->tokenizer->tokenize( '' );

        $this->assertIsArray( $tokens );
        $this->assertEmpty( $tokens );
    }

    public function test_only_stop_words_returns_empty_array(): void {
        $tokens = $this->tokenizer->tokenize( 'the and for are but' );

        $this->assertEmpty( $tokens );
    }

    public function test_only_short_tokens_returns_empty_array(): void {
        $tokens = $this->tokenizer->tokenize( 'a b c d e' );

        $this->assertEmpty( $tokens );
    }

    public function test_numbers_in_tokens(): void {
        $tokens = $this->tokenizer->tokenize( 'unique1234 unique5678' );

        $this->assertContains( 'unique1234', $tokens );
        $this->assertContains( 'unique5678', $tokens );
    }

    // --- phone-number normalisation ---

    /**
     * @dataProvider phone_numbers
     */
    public function test_phone_number_like_sequences_become_the_phone_token( string $text ): void {
        $tokens = $this->tokenizer->tokenize( $text );

        $this->assertContains( ANTISPAMWIZARD_Tokenizer::PHONE_TOKEN, $tokens, $text );
        foreach ( $tokens as $token ) {
            $this->assertFalse( ctype_digit( $token ) && strlen( $token ) >= 9, "raw digits survived in $text: $token" );
        }
    }

    public function phone_numbers(): array {
        return array(
            array( 'пишите 89139991205' ),
            array( 'звоните +7 (913) 999-12-05' ),
            array( 'тел 8-913-999-12-05' ),
            array( 'call +1 415 555 0134 now' ),
            array( 'text 202.555.0199' ),
            array( 'whatsapp +44 7700 900123' ),
            array( 'contact +2348012345678 today' ),
        );
    }

    /**
     * @dataProvider not_phone_numbers
     */
    public function test_ordinary_numbers_are_left_alone( string $text, string $expected_token ): void {
        $tokens = $this->tokenizer->tokenize( $text );

        $this->assertNotContains( ANTISPAMWIZARD_Tokenizer::PHONE_TOKEN, $tokens, $text );
        $this->assertContains( $expected_token, $tokens, $text );
    }

    public function not_phone_numbers(): array {
        return array(
            array( 'released in 2024', '2024' ),
            array( 'costs 15000 rub', '15000' ),
            array( 'order unique1234 shipped', 'unique1234' ),
            array( 'error 0x80070005 again', '0x80070005' ),
            array( 'version 1.0.0 is out', 'version' ),
            array( 'population 1 000 000 people', '000' ),
        );
    }

    public function test_space_separated_digit_groups_are_one_phone_number(): void {
        // "+7 913 999 12 05" is how phone numbers are written, so groups joined
        // by spaces count as one sequence. The accepted boundary: a list of
        // plain numbers joined by spaces alone ("2019 2020 2021") is folded too,
        // while any other delimiter — a comma, a word — keeps them apart.
        $this->assertContains( ANTISPAMWIZARD_Tokenizer::PHONE_TOKEN, $this->tokenizer->tokenize( '2019 2020 2021' ) );
        $this->assertNotContains( ANTISPAMWIZARD_Tokenizer::PHONE_TOKEN, $this->tokenizer->tokenize( '2019, 2020, 2021' ) );
        $this->assertNotContains( ANTISPAMWIZARD_Tokenizer::PHONE_TOKEN, $this->tokenizer->tokenize( '2019 and 2020 and 2021' ) );
    }

    public function test_phone_token_is_one_token_of_the_ngram_path(): void {
        $tokens = $this->tokenizer->tokenize_ngrams( '电话 13812345678' );

        $this->assertContains( mb_substr( ANTISPAMWIZARD_Tokenizer::PHONE_TOKEN, 0, 2 ), $tokens );
        $this->assertContains( 'ph', $tokens );
        $this->assertNotContains( '81', $tokens );
    }

    public function test_normalize_phone_numbers_returns_input_on_malformed_utf8(): void {
        $bad = "call 5551234567 \xff";

        $this->assertSame( $bad, ANTISPAMWIZARD_Tokenizer::normalize_phone_numbers( $bad ) );
    }

    // --- tokenize_ngrams tests ---

    public function test_ngrams_cjk_bigrams(): void {
        $tokenizer = new ANTISPAMWIZARD_Tokenizer( array() );
        // Chinese: 这是测试 -> 这是, 是测, 测试
        $tokens = $tokenizer->tokenize_ngrams( '这是测试' );

        $this->assertCount( 3, $tokens );
        $this->assertSame( '这是', $tokens[0] );
        $this->assertSame( '是测', $tokens[1] );
        $this->assertSame( '测试', $tokens[2] );
    }

    public function test_ngrams_japanese_bigrams(): void {
        $tokenizer = new ANTISPAMWIZARD_Tokenizer( array() );
        // Japanese: すごい -> す ご, ごい
        $tokens = $tokenizer->tokenize_ngrams( 'すごい' );

        $this->assertCount( 2, $tokens );
        $this->assertSame( 'すご', $tokens[0] );
        $this->assertSame( 'ごい', $tokens[1] );
    }

    public function test_ngrams_thai_bigrams(): void {
        $tokenizer = new ANTISPAMWIZARD_Tokenizer( array() );
        // Thai: สวัสดี (6 chars) -> 5 bigrams
        $tokens = $tokenizer->tokenize_ngrams( 'สวัสดี' );

        $this->assertCount( 5, $tokens );
        $this->assertSame( 'สว', $tokens[0] );
    }

    public function test_ngrams_strips_whitespace_and_punctuation(): void {
        $tokenizer = new ANTISPAMWIZARD_Tokenizer( array() );
        $tokens    = $tokenizer->tokenize_ngrams( '这 是，测试！' );

        // After removing whitespace/punctuation: 这是测试 -> 3 bigrams
        $this->assertCount( 3, $tokens );
        $this->assertSame( '这是', $tokens[0] );
        $this->assertSame( '是测', $tokens[1] );
        $this->assertSame( '测试', $tokens[2] );
    }

    public function test_ngrams_empty_string(): void {
        $tokenizer = new ANTISPAMWIZARD_Tokenizer( array() );
        $tokens    = $tokenizer->tokenize_ngrams( '' );

        $this->assertIsArray( $tokens );
        $this->assertEmpty( $tokens );
    }

    public function test_ngrams_single_character_returns_empty(): void {
        $tokenizer = new ANTISPAMWIZARD_Tokenizer( array() );
        $tokens    = $tokenizer->tokenize_ngrams( '字' );

        $this->assertEmpty( $tokens );
    }

    public function test_ngrams_custom_n_trigrams(): void {
        $tokenizer = new ANTISPAMWIZARD_Tokenizer( array() );
        $tokens    = $tokenizer->tokenize_ngrams( '这是测试文', 3 );

        $this->assertCount( 3, $tokens );
        $this->assertSame( '这是测', $tokens[0] );
        $this->assertSame( '是测试', $tokens[1] );
        $this->assertSame( '测试文', $tokens[2] );
    }

    public function test_ngrams_lowercases_text(): void {
        $tokenizer = new ANTISPAMWIZARD_Tokenizer( array() );
        $tokens    = $tokenizer->tokenize_ngrams( 'ABCD' );

        $this->assertSame( 'ab', $tokens[0] );
        $this->assertSame( 'bc', $tokens[1] );
        $this->assertSame( 'cd', $tokens[2] );
    }

    // --- tokenize_for_script tests ---

    public function test_tokenize_for_script_cjk_uses_ngrams(): void {
        $tokenizer = new ANTISPAMWIZARD_Tokenizer( array() );
        $tokens    = $tokenizer->tokenize_for_script( '这是测试', 'cjk' );

        // Should produce bigrams, not word tokens
        $this->assertCount( 3, $tokens );
        $this->assertSame( '这是', $tokens[0] );
    }

    public function test_tokenize_for_script_thai_uses_ngrams(): void {
        $tokenizer = new ANTISPAMWIZARD_Tokenizer( array() );
        $tokens    = $tokenizer->tokenize_for_script( 'สวัสดี', 'thai' );

        // Should produce bigrams
        $this->assertCount( 5, $tokens );
    }

    public function test_tokenize_for_script_latin_uses_word_tokenization(): void {
        $tokenizer = new ANTISPAMWIZARD_Tokenizer( array() );
        $tokens    = $tokenizer->tokenize_for_script( 'hello world great test', 'latin' );

        $this->assertContains( 'hello', $tokens );
        $this->assertContains( 'world', $tokens );
        $this->assertContains( 'great', $tokens );
        $this->assertContains( 'test', $tokens );
    }

    public function test_tokenize_for_script_cyrillic_uses_word_tokenization(): void {
        $tokenizer = new ANTISPAMWIZARD_Tokenizer( array() );
        $tokens    = $tokenizer->tokenize_for_script( 'отличная статья программирование', 'cyrillic' );

        $this->assertContains( 'отличная', $tokens );
        $this->assertContains( 'статья', $tokens );
    }

    public function test_tokenize_for_script_arabic_uses_word_tokenization(): void {
        $tokenizer = new ANTISPAMWIZARD_Tokenizer( array() );
        $tokens    = $tokenizer->tokenize_for_script( 'مرحبا بالعالم', 'arabic' );

        $this->assertContains( 'مرحبا', $tokens );
        $this->assertContains( 'بالعالم', $tokens );
    }

    public function test_tokenize_caps_at_max_tokens(): void {
        $tokenizer = new ANTISPAMWIZARD_Tokenizer( array() );
        // Generate a string with more than MAX_TOKENS words (each word > 2 chars).
        $words = array();
        for ( $i = 0; $i < ANTISPAMWIZARD_Tokenizer::MAX_TOKENS + 500; $i++ ) {
            $words[] = 'word' . $i;
        }
        $text   = implode( ' ', $words );
        $tokens = $tokenizer->tokenize( $text );

        $this->assertCount( ANTISPAMWIZARD_Tokenizer::MAX_TOKENS, $tokens );
    }

    public function test_tokenize_ngrams_caps_at_max_tokens(): void {
        $tokenizer = new ANTISPAMWIZARD_Tokenizer( array() );
        // Generate a string that produces more than MAX_TOKENS bigrams.
        // Each unique character adds 1 bigram, so we need MAX_TOKENS + 1 characters.
        $text   = str_repeat( 'a', ANTISPAMWIZARD_Tokenizer::MAX_TOKENS + 500 );
        $tokens = $tokenizer->tokenize_ngrams( $text );

        $this->assertCount( ANTISPAMWIZARD_Tokenizer::MAX_TOKENS, $tokens );
    }

    public function test_tokenize_for_script_excluded_tokens_apply_to_word_tokenization(): void {
        $tokenizer = new ANTISPAMWIZARD_Tokenizer( array( 'hello' ) );
        $tokens    = $tokenizer->tokenize_for_script( 'hello world great', 'latin' );

        $this->assertNotContains( 'hello', $tokens );
        $this->assertContains( 'world', $tokens );
        $this->assertContains( 'great', $tokens );
    }
}
