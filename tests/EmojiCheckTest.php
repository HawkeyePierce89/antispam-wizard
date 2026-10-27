<?php

use PHPUnit\Framework\TestCase;

class EmojiCheckTest extends TestCase {

    private ANTISPAMWIZARD_Emoji_Check $check;

    protected function setUp(): void {
        parent::setUp();
        global $wp_options;
        $wp_options = array();
        $this->check = new ANTISPAMWIZARD_Emoji_Check();
    }

    protected function tearDown(): void {
        global $wp_options;
        $wp_options = array();
        parent::tearDown();
    }

    private function make_comment( string $content ): array {
        return array( 'comment_content' => $content );
    }

    public function test_no_emoji_passes(): void {
        $result = $this->check->check( $this->make_comment( 'This is a normal comment without emoji.' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'emoji', $result->get_check_name() );
        $this->assertSame( 'emoji 0/4', $result->get_details() );
    }

    public function test_under_limit_passes(): void {
        $result = $this->check->check( $this->make_comment( 'Great post! 😀👍' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertStringContainsString( '/4', $result->get_details() );
    }

    public function test_at_limit_passes(): void {
        $result = $this->check->check( $this->make_comment( 'Nice! 😀👍🎉🔥' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'emoji 4/4', $result->get_details() );
    }

    public function test_over_limit_is_spam(): void {
        $result = $this->check->check( $this->make_comment( 'Wow! 😀👍🎉🔥💯' ) );
        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'emoji', $result->get_check_name() );
        $this->assertSame( 'too many emoji 5/4', $result->get_details() );
    }

    public function test_mixed_text_and_emoji(): void {
        $result = $this->check->check( $this->make_comment(
            'Hello 😀 world 👍 this 🎉 is 🔥 a 💯 test 🚀'
        ) );
        $this->assertTrue( $result->is_spam() );
        $this->assertStringContainsString( 'too many emoji', $result->get_details() );
    }

    public function test_custom_threshold(): void {
        ANTISPAMWIZARD_Settings::update( array( 'emoji_max' => 10 ) );
        $result = $this->check->check( $this->make_comment( 'Wow! 😀👍🎉🔥💯' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'emoji 5/10', $result->get_details() );
    }

    public function test_disabled_check_returns_not_spam(): void {
        ANTISPAMWIZARD_Settings::update( array( 'emoji_enabled' => false ) );
        $result = $this->check->check( $this->make_comment( '😀😀😀😀😀😀😀😀😀😀' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'check disabled', $result->get_details() );
    }

    public function test_empty_comment(): void {
        $result = $this->check->check( $this->make_comment( '' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'empty comment', $result->get_details() );
    }

    public function test_implements_check_interface(): void {
        $this->assertInstanceOf( ANTISPAMWIZARD_Check::class, $this->check );
    }

    public function test_misc_symbols_counted(): void {
        // Characters in the Misc Symbols range (U+2600-U+26FF).
        $result = $this->check->check( $this->make_comment( '☀☁☂☃☄' ) );
        $this->assertTrue( $result->is_spam() );
    }

    public function test_flag_emoji_counted(): void {
        // Regional indicator symbols (U+1F1E0-U+1F1FF) used for flag emoji.
        $result = $this->check->check( $this->make_comment( '🇺🇸🇬🇧🇫🇷' ) );
        $this->assertTrue( $result->is_spam() );
    }
}
