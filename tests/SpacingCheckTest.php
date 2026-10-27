<?php

use PHPUnit\Framework\TestCase;

class SpacingCheckTest extends TestCase {

    private ANTISPAMWIZARD_Spacing_Check $check;

    protected function setUp(): void {
        parent::setUp();
        global $wp_options;
        $wp_options = array();
        $this->check = new ANTISPAMWIZARD_Spacing_Check();
    }

    protected function tearDown(): void {
        global $wp_options;
        $wp_options = array();
        parent::tearDown();
    }

    private function make_comment( string $content ): array {
        return array( 'comment_content' => $content );
    }

    public function test_normal_text_passes(): void {
        $result = $this->check->check( $this->make_comment(
            'This is a perfectly normal comment about the blog post.'
        ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'spacing', $result->get_check_name() );
    }

    public function test_obfuscated_spaced_text_detected(): void {
        // Classic obfuscation: every character separated by spaces.
        $result = $this->check->check( $this->make_comment( 'п р и в е т к а к д е л а' ) );
        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'spacing', $result->get_check_name() );
        $this->assertStringContainsString( 'abnormal', $result->get_details() );
    }

    public function test_short_text_skipped(): void {
        $result = $this->check->check( $this->make_comment( 'Hi there' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'too short', $result->get_details() );
    }

    public function test_too_few_words_skipped(): void {
        // Default min_words_count is 4, send only 3 words (but >= 10 chars).
        $result = $this->check->check( $this->make_comment( 'Hello world today' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertStringContainsString( 'too few words', $result->get_details() );
    }

    public function test_disabled_check(): void {
        ANTISPAMWIZARD_Settings::update( array( 'spacing_enabled' => false ) );
        $result = $this->check->check( $this->make_comment( 'п р и в е т к а к д е л а' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'check disabled', $result->get_details() );
    }

    public function test_empty_comment(): void {
        $result = $this->check->check( $this->make_comment( '' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'too short', $result->get_details() );
    }

    public function test_latin_spaced_out_text(): void {
        $result = $this->check->check( $this->make_comment( 'b u y n o w c h e a p p i l l s' ) );
        $this->assertTrue( $result->is_spam() );
        $this->assertStringContainsString( 'abnormal', $result->get_details() );
    }

    public function test_high_space_ratio_detected(): void {
        // Text where spaces vastly outnumber letters.
        $result = $this->check->check( $this->make_comment( 'a  b  c  d  e  f  g  h' ) );
        $this->assertTrue( $result->is_spam() );
    }

    public function test_custom_thresholds(): void {
        // Set very permissive thresholds so spaced text passes.
        ANTISPAMWIZARD_Settings::update( array(
            'spacing_space_ratio'      => 5.0,
            'spacing_short_word_ratio' => 1.0,
        ) );
        $result = $this->check->check( $this->make_comment( 'п р и в е т к а к д е л а' ) );
        $this->assertFalse( $result->is_spam() );
    }

    public function test_implements_check_interface(): void {
        $this->assertInstanceOf( ANTISPAMWIZARD_Check::class, $this->check );
    }

    public function test_edge_case_no_letters(): void {
        // Only numbers and spaces, enough chars to pass length check.
        $result = $this->check->check( $this->make_comment( '123 456 789 012 345' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'no spaces or letters', $result->get_details() );
    }

    public function test_details_format_on_normal(): void {
        $result = $this->check->check( $this->make_comment(
            'This is a perfectly normal comment about the blog post.'
        ) );
        $this->assertMatchesRegularExpression(
            '/normal \(ratio: \d+\.\d+, short: \d+%\)/',
            $result->get_details()
        );
    }
}
