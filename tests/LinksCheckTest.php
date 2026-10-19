<?php

use PHPUnit\Framework\TestCase;

class LinksCheckTest extends TestCase {

    private ASW_Links_Check $check;

    protected function setUp(): void {
        parent::setUp();
        global $wp_options;
        $wp_options = array();
        $this->check = new ASW_Links_Check();
    }

    protected function tearDown(): void {
        global $wp_options;
        $wp_options = array();
        parent::tearDown();
    }

    private function make_comment( string $content ): array {
        return array( 'comment_content' => $content );
    }

    public function test_no_links_passes(): void {
        $result = $this->check->check( $this->make_comment( 'This is a normal comment without any links.' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'links', $result->get_check_name() );
        $this->assertSame( 'links 0/2', $result->get_details() );
    }

    public function test_under_limit_passes(): void {
        $result = $this->check->check( $this->make_comment( 'Check http://example.com for details.' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'links 1/2', $result->get_details() );
    }

    public function test_at_limit_passes(): void {
        $result = $this->check->check( $this->make_comment(
            'Visit http://example.com and https://example.org for info.'
        ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'links 2/2', $result->get_details() );
    }

    public function test_over_limit_is_spam(): void {
        $result = $this->check->check( $this->make_comment(
            'Check http://a.com and https://b.com and http://c.com now!'
        ) );
        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'links', $result->get_check_name() );
        $this->assertSame( 'too many links 3/2', $result->get_details() );
    }

    public function test_mixed_protocols_counted(): void {
        $result = $this->check->check( $this->make_comment(
            'http://a.com https://b.com http://c.com https://d.com'
        ) );
        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'too many links 4/2', $result->get_details() );
    }

    public function test_custom_threshold(): void {
        ASW_Settings::update( array( 'links_max' => 5 ) );
        $result = $this->check->check( $this->make_comment(
            'http://a.com https://b.com http://c.com https://d.com'
        ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'links 4/5', $result->get_details() );
    }

    public function test_disabled_check_returns_not_spam(): void {
        ASW_Settings::update( array( 'links_enabled' => false ) );
        $result = $this->check->check( $this->make_comment(
            'http://a.com https://b.com http://c.com'
        ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'check disabled', $result->get_details() );
    }

    public function test_empty_comment(): void {
        $result = $this->check->check( $this->make_comment( '' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'empty comment', $result->get_details() );
    }

    public function test_implements_check_interface(): void {
        $this->assertInstanceOf( ASW_Check::class, $this->check );
    }
}
