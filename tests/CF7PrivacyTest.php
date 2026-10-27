<?php
/**
 * Tests for ANTISPAMWIZARD_Privacy CF7 form-entry path (email-hash lookup).
 *
 * @package AntiSpamWizard
 */

use PHPUnit\Framework\TestCase;

class CF7PrivacyTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        global $wp_options, $wp_actions, $wp_filters, $wp_cache_deletes,
            $wp_comments, $wp_comment_meta, $wp_privacy_policy_content;

        $wp_options                = array();
        $wp_actions                = array();
        $wp_filters                = array();
        $wp_cache_deletes          = array();
        $wp_comments               = array();
        $wp_comment_meta           = array();
        $wp_privacy_policy_content = array();

        if ( ! defined( 'ANTISPAMWIZARD_PATH' ) ) {
            define( 'ANTISPAMWIZARD_PATH', dirname( __DIR__ ) . '/antispam-wizard/' );
        }

        $reflection = new ReflectionClass( ANTISPAMWIZARD::class );
        $instance   = $reflection->getProperty( 'instance' );
        $instance->setValue( null, null );
    }

    private function add_comment( int $id, string $email, string $approved = '1' ): void {
        global $wp_comments;
        $wp_comments[ $id ] = array(
            'comment_ID'           => $id,
            'comment_author_email' => $email,
            'comment_approved'     => $approved,
        );
    }

    // -- Export: form entries by email hash -----------------------------------

    public function test_export_returns_form_group_for_matching_email(): void {
        $email = 'formuser@example.com';
        $hash  = ANTISPAMWIZARD_Detector::hash_email( $email );
        ANTISPAMWIZARD_Spam_Log::add_entry( 'form spam fragment', array( 'links' ), 'hashed-ip', 0, 'form', $hash );

        $privacy = new ANTISPAMWIZARD_Privacy();
        $result  = $privacy->export( $email, 1 );

        $this->assertTrue( $result['done'] );
        $this->assertCount( 1, $result['data'] );
        $group = $result['data'][0];
        $this->assertSame( ANTISPAMWIZARD_Privacy::PRIVACY_KEY, $group['group_id'] );
        $this->assertStringStartsWith( ANTISPAMWIZARD_Privacy::PRIVACY_KEY . '-form-', $group['item_id'] );
        $values = array_column( $group['data'], 'value', 'name' );
        $this->assertSame( 'form spam fragment', $values['Submission fragment'] );
        $this->assertSame( 'hashed-ip', $values['IP address (SHA-256 hash)'] );
        $this->assertSame( 'links', $values['Triggered checks'] );
        $this->assertArrayHasKey( 'Date flagged', $values );
    }

    public function test_export_returns_no_form_group_for_non_matching_email(): void {
        $hash = ANTISPAMWIZARD_Detector::hash_email( 'owner@example.com' );
        ANTISPAMWIZARD_Spam_Log::add_entry( 'form spam', array( 'emoji' ), 'h', 0, 'form', $hash );

        $privacy = new ANTISPAMWIZARD_Privacy();
        $result  = $privacy->export( 'other@example.com', 1 );

        $this->assertSame( array(), $result['data'] );
        $this->assertTrue( $result['done'] );
    }

    public function test_export_case_and_whitespace_variants_still_match(): void {
        $hash = ANTISPAMWIZARD_Detector::hash_email( 'FORMUSER@example.com' );
        ANTISPAMWIZARD_Spam_Log::add_entry( 'form spam', array( 'links' ), 'h', 0, 'form', $hash );

        $privacy = new ANTISPAMWIZARD_Privacy();
        $result  = $privacy->export( '  formuser@EXAMPLE.com  ', 1 );

        $this->assertCount( 1, $result['data'] );
    }

    public function test_export_form_entries_appear_only_on_page_one(): void {
        $email = 'paged@example.com';
        $hash  = ANTISPAMWIZARD_Detector::hash_email( $email );
        ANTISPAMWIZARD_Spam_Log::add_entry( 'form spam', array( 'links' ), 'h', 0, 'form', $hash );

        // Fill a full page of comments for this email so pagination matters.
        for ( $i = 1; $i <= ANTISPAMWIZARD_Privacy::COMMENTS_PER_PAGE; $i++ ) {
            $this->add_comment( $i, $email );
            ANTISPAMWIZARD_Spam_Log::add_entry( "comment $i", array( 'emoji' ), 'h2', $i );
        }

        $privacy = new ANTISPAMWIZARD_Privacy();

        $page1 = $privacy->export( $email, 1 );
        // Page 1 contains 50 comment groups + 1 form group, and is not done.
        $this->assertFalse( $page1['done'] );
        $this->assertCount( ANTISPAMWIZARD_Privacy::COMMENTS_PER_PAGE + 1, $page1['data'] );
        $item_ids = array_column( $page1['data'], 'item_id' );
        $has_form = false;
        foreach ( $item_ids as $id ) {
            if ( 0 === strpos( $id, ANTISPAMWIZARD_Privacy::PRIVACY_KEY . '-form-' ) ) {
                $has_form = true;
                break;
            }
        }
        $this->assertTrue( $has_form, 'Form entry should appear on page 1' );

        $page2 = $privacy->export( $email, 2 );
        // Page 2 holds overflow comments only, no form entry, and is done.
        $this->assertTrue( $page2['done'] );
        foreach ( $page2['data'] as $group ) {
            $this->assertStringStartsWith( ANTISPAMWIZARD_Privacy::PRIVACY_KEY . '-comment-', $group['item_id'] );
        }
    }

    public function test_export_comment_source_unchanged(): void {
        global $wp_comment_meta;
        $this->add_comment( 10, 'subject@example.com' );
        ANTISPAMWIZARD_Spam_Log::add_entry( 'buy cheap pills', array( 'links' ), 'hashed-ip', 10 );
        $wp_comment_meta[10]['_antispamwizard_reason'] = 'Too many links';

        $privacy = new ANTISPAMWIZARD_Privacy();
        $result  = $privacy->export( 'subject@example.com' );

        $this->assertCount( 1, $result['data'] );
        $values = array_column( $result['data'][0]['data'], 'value', 'name' );
        $this->assertSame( 'buy cheap pills', $values['Comment fragment'] );
        $this->assertSame( 'Too many links', $values['Reason flagged'] );
    }

    public function test_comment_entry_without_email_hash_never_matched(): void {
        // Comment entries have empty email_hash; no email should match them.
        ANTISPAMWIZARD_Spam_Log::add_entry( 'comment spam', array( 'links' ), 'h', 10 );
        $this->add_comment( 10, 'someone@example.com' );

        $privacy = new ANTISPAMWIZARD_Privacy();
        // Export for a form-style lookup: should not return comment entry via hash path.
        $result = $privacy->export( 'someone@example.com', 1 );
        // The comment entry is reachable via comment_id path, but the hash lookup
        // alone (without matching comment) should not inject it as a form group.
        // Verify that all returned groups are comment-typed.
        foreach ( $result['data'] as $group ) {
            $this->assertStringStartsWith( ANTISPAMWIZARD_Privacy::PRIVACY_KEY . '-comment-', $group['item_id'] );
        }

        // Direct hash lookup with any email should not find comment entries.
        $hash    = ANTISPAMWIZARD_Detector::hash_email( 'someone@example.com' );
        $by_hash = ANTISPAMWIZARD_Spam_Log::get_entries_by_email_hash( $hash );
        $this->assertSame( array(), $by_hash );
    }

    // -- Erase: form entries by email hash ------------------------------------

    public function test_erase_removes_form_entry_and_reports_items_removed(): void {
        $email = 'erase-me@example.com';
        $hash  = ANTISPAMWIZARD_Detector::hash_email( $email );
        ANTISPAMWIZARD_Spam_Log::add_entry( 'form spam', array( 'links' ), 'h', 0, 'form', $hash );

        $privacy = new ANTISPAMWIZARD_Privacy();
        $result  = $privacy->erase( $email, 1 );

        $this->assertTrue( $result['items_removed'] );
        $this->assertFalse( $result['items_retained'] );
        $this->assertNotEmpty( $result['messages'] );
        $this->assertTrue( $result['done'] );
        $this->assertSame( array(), ANTISPAMWIZARD_Spam_Log::get_entries_by_email_hash( $hash ) );
    }

    public function test_erase_no_match_reports_false(): void {
        $hash = ANTISPAMWIZARD_Detector::hash_email( 'exists@example.com' );
        ANTISPAMWIZARD_Spam_Log::add_entry( 'form spam', array( 'links' ), 'h', 0, 'form', $hash );

        $privacy = new ANTISPAMWIZARD_Privacy();
        $result  = $privacy->erase( 'nobody@example.com', 1 );

        $this->assertFalse( $result['items_removed'] );
        $this->assertSame( array(), $result['messages'] );
        $this->assertCount( 1, ANTISPAMWIZARD_Spam_Log::get_entries_by_email_hash( $hash ) );
    }

    public function test_erase_form_entry_only_on_page_one(): void {
        $email = 'paged-erase@example.com';
        $hash  = ANTISPAMWIZARD_Detector::hash_email( $email );
        ANTISPAMWIZARD_Spam_Log::add_entry( 'form spam', array( 'links' ), 'h', 0, 'form', $hash );

        // Page 2 erase should not touch form entry.
        $privacy = new ANTISPAMWIZARD_Privacy();
        $result2 = $privacy->erase( $email, 2 );
        $this->assertFalse( $result2['items_removed'] );
        $this->assertCount( 1, ANTISPAMWIZARD_Spam_Log::get_entries_by_email_hash( $hash ) );

        // Page 1 erase should remove it.
        $result1 = $privacy->erase( $email, 1 );
        $this->assertTrue( $result1['items_removed'] );
        $this->assertSame( array(), ANTISPAMWIZARD_Spam_Log::get_entries_by_email_hash( $hash ) );
    }

    public function test_erase_comment_source_unchanged(): void {
        global $wp_comment_meta;
        $this->add_comment( 10, 'subject@example.com' );
        ANTISPAMWIZARD_Spam_Log::add_entry( 'mine', array( 'links' ), 'h1', 10 );
        $wp_comment_meta[10]['_antispamwizard_reason'] = 'Too many links';

        $privacy = new ANTISPAMWIZARD_Privacy();
        $result  = $privacy->erase( 'subject@example.com' );

        $this->assertTrue( $result['items_removed'] );
        $this->assertSame( array(), ANTISPAMWIZARD_Spam_Log::get_entries_by_comment_ids( array( 10 ) ) );
        $this->assertArrayNotHasKey( '_antispamwizard_reason', $wp_comment_meta[10] ?? array() );
    }

    public function test_erase_combined_comment_and_form(): void {
        global $wp_comment_meta;
        $email = 'both@example.com';
        $hash  = ANTISPAMWIZARD_Detector::hash_email( $email );
        $this->add_comment( 5, $email );
        ANTISPAMWIZARD_Spam_Log::add_entry( 'comment spam', array( 'links' ), 'h1', 5 );
        $wp_comment_meta[5]['_antispamwizard_reason'] = 'links';
        ANTISPAMWIZARD_Spam_Log::add_entry( 'form spam', array( 'emoji' ), 'h2', 0, 'form', $hash );

        $privacy = new ANTISPAMWIZARD_Privacy();
        $result  = $privacy->erase( $email, 1 );

        $this->assertTrue( $result['items_removed'] );
        $this->assertSame( array(), ANTISPAMWIZARD_Spam_Log::get_entries_by_comment_ids( array( 5 ) ) );
        $this->assertSame( array(), ANTISPAMWIZARD_Spam_Log::get_entries_by_email_hash( $hash ) );
    }

    // -- Policy content -------------------------------------------------------

    public function test_add_privacy_policy_content_covers_form_submissions(): void {
        global $wp_privacy_policy_content;
        $privacy = new ANTISPAMWIZARD_Privacy();
        $privacy->add_privacy_policy_content();

        $this->assertCount( 1, $wp_privacy_policy_content );
        $text = $wp_privacy_policy_content[0]['policy_text'];
        $this->assertStringContainsString( 'contact-form', strtolower( $text ) );
        $this->assertStringContainsString( 'SHA-256', $text );
        $this->assertStringContainsString( 'email', strtolower( $text ) );
    }
}
