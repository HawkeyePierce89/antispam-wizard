<?php

use PHPUnit\Framework\TestCase;

class CheckResultTest extends TestCase {

    public function test_spam_result() {
        $result = new ANTISPAMWIZARD_Check_Result( true, 'links', 'Found 5 links' );

        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'links', $result->get_check_name() );
        $this->assertSame( 'Found 5 links', $result->get_details() );
    }

    public function test_ham_result() {
        $result = new ANTISPAMWIZARD_Check_Result( false, 'links', 'OK' );

        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'links', $result->get_check_name() );
        $this->assertSame( 'OK', $result->get_details() );
    }

    public function test_empty_details() {
        $result = new ANTISPAMWIZARD_Check_Result( false, 'emoji', '' );

        $this->assertFalse( $result->is_spam() );
        $this->assertSame( '', $result->get_details() );
    }
}
