<?php
/**
 * Tests for ANTISPAMWIZARD_Moderation: training the classifier from comment status
 * transitions.
 */

use PHPUnit\Framework\TestCase;

/**
 * A repository whose insert() throws, standing in for a $wpdb failure or a
 * third-party option filter that throws inside the training path.
 *
 * A named class rather than an anonymous one: an anonymous class name embeds a
 * NUL byte, and error_log() writes a C string, so the diagnostic under test
 * would be truncated at the name.
 */
class ANTISPAMWIZARD_Throwing_Sample_Repository extends ANTISPAMWIZARD_Sample_Repository {

    public function insert( string $script, string $category, string $content, int $comment_id, string $email_hash = '' ) {
        throw new RuntimeException( 'database is on fire: SELECT * FROM wp_antispamwizard_samples' );
    }
}

/**
 * A repository whose failed insert() also takes the options store down with it,
 * so the rollback that follows is refused too.
 *
 * Named rather than anonymous for the same reason as the class above: the
 * diagnostic under test goes through error_log().
 */
class ANTISPAMWIZARD_Option_Breaking_Sample_Repository extends ANTISPAMWIZARD_Sample_Repository {

    public function insert( string $script, string $category, string $content, int $comment_id, string $email_hash = '' ) {
        $GLOBALS['wp_update_option_fails'] = array( ANTISPAMWIZARD_Spam_Log::OPTION_KEY );
        return false;
    }
}

class ModerationTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();

        global $wp_options, $wp_actions, $wp_filters, $wp_transients, $wp_comments, $wp_comment_cache, $wp_nonces, $wp_json_responses, $wp_current_user_caps, $wpdb;
        $wp_options           = array();
        $wp_actions           = array();
        $wp_filters           = array();
        $wp_transients        = array();
        $wp_comments          = array();
        $wp_comment_cache     = array();
        $wp_nonces            = array();
        $wp_json_responses    = array();
        $wp_current_user_caps = array( 'manage_options' => true );
        $_POST                = array();

        $GLOBALS['wp_update_option_fails']         = false;
        $GLOBALS['wp_comment_status_change_fails'] = false;

        if ( ! defined( 'ANTISPAMWIZARD_PATH' ) ) {
            define( 'ANTISPAMWIZARD_PATH', dirname( __DIR__ ) . '/antispam-wizard/' );
        }

        $wpdb->reset_tables();

        $reflection = new ReflectionClass( ANTISPAMWIZARD::class );
        $instance   = $reflection->getProperty( 'instance' );
        $instance->setValue( null, null );

        // init() registers the transition hook alongside everything else.
        ANTISPAMWIZARD::get_instance()->init();

        ANTISPAMWIZARD_Spam_Log::clear();
    }

    protected function tearDown(): void {
        $_POST = array();
        parent::tearDown();
    }

    /**
     * Seed a comment fixture and return its ID.
     */
    private function seed_comment( int $id, string $content, string $approved = '1', string $email = 'author@example.com', string $ip = '203.0.113.9' ): int {
        global $wp_comments;
        $wp_comments[ $id ] = array(
            'comment_ID'           => $id,
            'comment_post_ID'      => 5,
            'comment_content'      => $content,
            'comment_approved'     => $approved,
            'comment_author_email' => $email,
            'comment_author_IP'    => $ip,
        );
        return $id;
    }

    private function repository(): ANTISPAMWIZARD_Sample_Repository {
        return ANTISPAMWIZARD::get_instance()->get_repository();
    }

    /**
     * The trained_as value recorded on the log entry for a comment, or '' when
     * the entry exists but is untrained and null when there is no entry.
     */
    private function trained_as( int $comment_id ): ?string {
        $entries = ANTISPAMWIZARD_Spam_Log::get_entries_by_comment_ids( array( $comment_id ) );
        if ( empty( $entries ) ) {
            return null;
        }
        return isset( $entries[0]['trained_as'] ) ? (string) $entries[0]['trained_as'] : '';
    }

    public function test_approved_to_spam_stores_one_sample_and_one_manual_entry(): void {
        $id = $this->seed_comment( 101, 'Buy cheap pills from our online pharmacy now.' );

        antispamwizard_test_set_comment_status( $id, 'spam' );

        $samples = $this->repository()->find_all( 'latin', 'spam' );
        $this->assertSame( array( 'Buy cheap pills from our online pharmacy now.' ), $samples );

        $entries = ANTISPAMWIZARD_Spam_Log::get_entries_by_comment_ids( array( $id ) );
        $this->assertCount( 1, $entries );
        $this->assertSame( array( ANTISPAMWIZARD_Moderation::CHECK_NAME ), $entries[0]['checks_triggered'] );
        $this->assertSame( 'spam', $entries[0]['trained_as'] );

        // The IP is stored hashed and the raw address never appears anywhere in
        // the entry; neither does the author's email address.
        $this->assertSame( ANTISPAMWIZARD_Detector::hash_ip( '203.0.113.9' ), $entries[0]['ip_hash'] );
        $serialized = serialize( $entries[0] );
        $this->assertStringNotContainsString( '203.0.113.9', $serialized );
        $this->assertStringNotContainsString( 'author@example.com', $serialized );
    }

    public function test_a_comment_without_an_ip_records_an_empty_hash(): void {
        $id = $this->seed_comment( 102, 'Cheap loans approved instantly, write to us.', '1', 'a@example.com', '' );

        antispamwizard_test_set_comment_status( $id, 'spam' );

        $entries = ANTISPAMWIZARD_Spam_Log::get_entries_by_comment_ids( array( $id ) );
        $this->assertCount( 1, $entries );
        $this->assertSame( '', $entries[0]['ip_hash'] );
    }

    public function test_repeating_the_transition_stores_nothing_more(): void {
        $id = $this->seed_comment( 103, 'Earn money from home fast, click here.' );

        // Out of spam and back in: the ham leg trains ham, the spam leg trains
        // spam again, and neither ever duplicates a sample or a log entry. The
        // second spam leg must be a real approved -> spam move, not spam -> spam,
        // which core (and the stub) short-circuits before the action fires.
        antispamwizard_test_set_comment_status( $id, 'spam' );
        antispamwizard_test_set_comment_status( $id, '1' );
        antispamwizard_test_set_comment_status( $id, 'spam' );

        $this->assertCount( 1, $this->repository()->find_all( 'latin', 'spam' ) );
        $this->assertSame( array(), $this->repository()->find_all( 'latin', 'ham' ) );
        $this->assertCount( 1, ANTISPAMWIZARD_Spam_Log::get_entries_by_comment_ids( array( $id ) ) );
        $this->assertSame( 'spam', $this->trained_as( $id ) );
    }

    public function test_not_spam_on_a_comment_the_plugin_blocked_trains_ham(): void {
        // The plugin's own detection inserts the row as spam through
        // pre_comment_approved, so it never passes through wp_spam_comment() and
        // carries no _wp_trash_meta_status meta. WordPress's "Not Spam" therefore
        // restores it to '0' -- unapproved, not approved -- and that transition is
        // the single most valuable ham signal there is.
        $id = $this->seed_comment( 104, 'A perfectly ordinary reader comment.', 'spam' );
        ANTISPAMWIZARD_Spam_Log::add_entry( 'A perfectly ordinary reader comment.', array( 'bayesian' ), 'iphash', $id );

        antispamwizard_test_set_comment_status( $id, '0' );

        $this->assertSame(
            array( 'A perfectly ordinary reader comment.' ),
            $this->repository()->find_all( 'latin', 'ham' )
        );
        $this->assertSame( 'ham', $this->trained_as( $id ) );
    }

    public function test_not_spam_to_unapproved_replaces_the_spam_sample(): void {
        $id = $this->seed_comment( 105, 'Thanks, that solved my caching problem.' );

        antispamwizard_test_set_comment_status( $id, 'spam' );
        $this->assertCount( 1, $this->repository()->find_all( 'latin', 'spam' ) );

        antispamwizard_test_set_comment_status( $id, '0' );

        $this->assertSame( array(), $this->repository()->find_all( 'latin', 'spam' ) );
        $this->assertCount( 1, $this->repository()->find_all( 'latin', 'ham' ) );
        $this->assertSame( 'ham', $this->trained_as( $id ) );
    }

    public function test_pingbacks_and_trackbacks_are_never_trained(): void {
        global $wp_comments;

        foreach ( array( 'pingback', 'trackback' ) as $index => $type ) {
            $id                            = $this->seed_comment( 106 + $index, 'An excerpt lifted from another blog post.' );
            $wp_comments[ $id ]['comment_type'] = $type;

            antispamwizard_test_set_comment_status( $id, 'spam' );

            $this->assertSame( array(), $this->repository()->find_all( 'latin', 'spam' ), $type );
            $this->assertSame( array(), ANTISPAMWIZARD_Spam_Log::get_entries_by_comment_ids( array( $id ) ), $type );
        }

        // The control: an explicit 'comment' type still trains, so the guard
        // cannot pass by refusing everything.
        $id                                 = $this->seed_comment( 108, 'Ordinary comment spam about pills.' );
        $wp_comments[ $id ]['comment_type'] = 'comment';
        antispamwizard_test_set_comment_status( $id, 'spam' );

        $this->assertCount( 1, $this->repository()->find_all( 'latin', 'spam' ) );
    }

    public function test_a_bulk_run_stores_a_sample_for_each_comment(): void {
        $first  = $this->seed_comment( 111, 'Best casino bonuses available today.' );
        $second = $this->seed_comment( 112, 'Order fake passports and driver licences.' );
        $third  = $this->seed_comment( 113, 'Followers and likes for sale, cheapest rates.' );

        foreach ( array( $first, $second, $third ) as $id ) {
            antispamwizard_test_set_comment_status( $id, 'spam' );
        }

        $this->assertCount( 3, $this->repository()->find_all( 'latin', 'spam' ) );
        foreach ( array( $first, $second, $third ) as $id ) {
            $this->assertSame( 'spam', $this->trained_as( $id ), 'comment ' . $id );
        }
    }

    public function test_spam_to_approved_replaces_the_spam_sample_with_a_ham_sample(): void {
        $id = $this->seed_comment( 121, 'Thanks for the write-up, this cleared things up.' );

        antispamwizard_test_set_comment_status( $id, 'spam' );
        $this->assertCount( 1, $this->repository()->find_all( 'latin', 'spam' ) );

        antispamwizard_test_set_comment_status( $id, '1' );

        $this->assertSame( array(), $this->repository()->find_all( 'latin', 'spam' ) );
        $this->assertSame(
            array( 'Thanks for the write-up, this cleared things up.' ),
            $this->repository()->find_all( 'latin', 'ham' )
        );
        $this->assertSame( 'ham', $this->trained_as( $id ) );
    }

    public function test_spam_to_approved_on_a_blocked_comment_creates_no_new_entry(): void {
        $id = $this->seed_comment( 131, 'A perfectly ordinary reader comment.', 'spam' );

        // The plugin itself blocked this one, so the entry already exists.
        $entry_id = ANTISPAMWIZARD_Spam_Log::add_entry( 'A perfectly ordinary reader comment.', array( 'bayesian' ), 'iphash', $id );
        $this->assertNotSame( '', $entry_id );

        antispamwizard_test_set_comment_status( $id, '1' );

        $entries = ANTISPAMWIZARD_Spam_Log::get_entries_by_comment_ids( array( $id ) );
        $this->assertCount( 1, $entries );
        $this->assertSame( array( 'bayesian' ), $entries[0]['checks_triggered'] );
        $this->assertSame( 'ham', $entries[0]['trained_as'] );
        $this->assertCount( 1, $this->repository()->find_all( 'latin', 'ham' ) );
    }

    public function test_marking_as_ham_never_creates_a_log_entry(): void {
        $id = $this->seed_comment( 132, 'Just a friendly hello from a reader.', 'spam' );

        antispamwizard_test_set_comment_status( $id, '1' );

        $this->assertSame( array(), ANTISPAMWIZARD_Spam_Log::get_entries_by_comment_ids( array( $id ) ) );
        // The sample is still stored: 'not_found' proceeds unlocked.
        $this->assertCount( 1, $this->repository()->find_all( 'latin', 'ham' ) );
    }

    public function test_transitions_that_teach_nothing_store_nothing(): void {
        $trashed     = $this->seed_comment( 141, 'Moved to the trash, not judged as spam.' );
        $unapproved  = $this->seed_comment( 142, 'Held for moderation, not judged as spam.' );
        $out_of_spam = $this->seed_comment( 143, 'Spam moved to the trash.', 'spam' );

        // Routine approvals: a held comment and one coming back from the trash.
        // Neither is a Not Spam decision, and training on them would flood the ham
        // corpus with every comment a moderator ever waves through.
        $held      = $this->seed_comment( 144, 'Held for moderation and then approved.', '0' );
        $untrashed = $this->seed_comment( 145, 'Trashed and then approved.', 'trash' );

        antispamwizard_test_set_comment_status( $trashed, 'trash' );
        antispamwizard_test_set_comment_status( $unapproved, '0' );
        antispamwizard_test_set_comment_status( $out_of_spam, 'trash' );
        antispamwizard_test_set_comment_status( $held, '1' );
        antispamwizard_test_set_comment_status( $untrashed, '1' );

        $this->assertSame( array(), $this->repository()->find_all( 'latin', 'spam' ) );
        $this->assertSame( array(), $this->repository()->find_all( 'latin', 'ham' ) );
        $this->assertSame( array(), ANTISPAMWIZARD_Spam_Log::get_entries() );
    }

    public function test_spam_to_spam_teaches_nothing(): void {
        global $wp_comments;

        $id = $this->seed_comment( 146, 'Already in the spam queue.', 'spam' );

        // The stub mirrors core and does not fire the action at all when the
        // status is unchanged...
        antispamwizard_test_set_comment_status( $id, 'spam' );
        $this->assertSame( array(), ANTISPAMWIZARD_Spam_Log::get_entries() );

        // ...and the handler itself refuses the transition when called directly.
        $moderation = new ANTISPAMWIZARD_Moderation();
        $moderation->on_transition( 'spam', 'spam', (object) $wp_comments[ $id ] );

        $this->assertSame( array(), ANTISPAMWIZARD_Spam_Log::get_entries() );
        $this->assertSame( array(), $this->repository()->find_all( 'latin', 'spam' ) );
    }

    public function test_an_empty_body_teaches_nothing_and_creates_no_entry(): void {
        $id = $this->seed_comment( 151, "   \n\t  " );

        antispamwizard_test_set_comment_status( $id, 'spam' );

        $this->assertSame( array(), ANTISPAMWIZARD_Spam_Log::get_entries() );
        $this->assertSame( array(), $this->repository()->find_all( 'latin', 'spam' ) );
        $this->assertSame( array(), $this->repository()->find_all( 'unknown', 'spam' ) );
    }

    public function test_a_non_comment_payload_teaches_nothing(): void {
        $moderation = new ANTISPAMWIZARD_Moderation();

        $moderation->on_transition( 'spam', 'approved', null );
        $moderation->on_transition( 'spam', 'approved', 'not a comment' );
        $moderation->on_transition( 'spam', 'approved', (object) array( 'comment_content' => 'No ID here.' ) );
        $moderation->on_transition( 'spam', 'approved', (object) array( 'comment_ID' => 0, 'comment_content' => 'Zero ID.' ) );

        $this->assertSame( array(), ANTISPAMWIZARD_Spam_Log::get_entries() );
        $this->assertSame( array(), $this->repository()->find_all( 'latin', 'spam' ) );
    }

    public function test_the_content_is_whitespace_normalised(): void {
        $id = $this->seed_comment( 152, "  Buy   cheap\npills\tnow.  " );

        antispamwizard_test_set_comment_status( $id, 'spam' );

        $this->assertSame( array( 'Buy cheap pills now.' ), $this->repository()->find_all( 'latin', 'spam' ) );
    }

    public function test_the_stored_sample_carries_both_privacy_subject_keys(): void {
        $id = $this->seed_comment( 161, 'Cheap watches, best replicas online.', '1', 'Subject@Example.COM' );

        antispamwizard_test_set_comment_status( $id, 'spam' );

        $by_comment = $this->repository()->find_by_comment_ids( array( $id ) );
        $this->assertCount( 1, $by_comment );
        $this->assertSame( 'Cheap watches, best replicas online.', $by_comment[0]['content'] );

        $by_hash = $this->repository()->find_by_email_hash( ANTISPAMWIZARD_Detector::hash_email( 'subject@example.com' ) );
        $this->assertCount( 1, $by_hash );
        $this->assertSame( (int) $by_comment[0]['id'], (int) $by_hash[0]['id'] );
    }

    public function test_a_failed_insert_after_an_ok_claim_clears_the_flag_and_stores_nothing(): void {
        global $wpdb;

        $id = $this->seed_comment( 171, 'Cheap pills, no prescription needed.' );

        $wpdb->insert_result = false;
        antispamwizard_test_set_comment_status( $id, 'spam' );
        $wpdb->insert_result = null;

        $this->assertSame( array(), $this->repository()->find_all( 'latin', 'spam' ) );
        // The manual entry is kept -- it records what the moderator did -- but
        // the flag is back to untrained so the next moderation retries.
        $this->assertSame( '', $this->trained_as( $id ) );

        // Out of spam and back in; the retry succeeds.
        antispamwizard_test_set_comment_status( $id, '1' );
        antispamwizard_test_set_comment_status( $id, 'spam' );

        $this->assertSame( array( 'Cheap pills, no prescription needed.' ), $this->repository()->find_all( 'latin', 'spam' ) );
        $this->assertSame( 'spam', $this->trained_as( $id ) );
    }

    public function test_a_failed_insert_after_a_flipped_claim_restores_the_old_category(): void {
        global $wpdb;

        $id = $this->seed_comment( 181, 'Genuinely useful comment about caching.' );

        antispamwizard_test_set_comment_status( $id, 'spam' );
        $this->assertSame( 'spam', $this->trained_as( $id ) );
        $this->assertCount( 1, $this->repository()->find_all( 'latin', 'spam' ) );

        // Now flip it to ham with the insert forced to fail.
        $wpdb->insert_result = false;
        antispamwizard_test_set_comment_status( $id, '1' );
        $wpdb->insert_result = null;

        // The flag must read 'spam', not untrained: the spam sample is still in
        // the table (the delete only runs after a successful insert), so an
        // untrained entry would misreport what is stored.
        $this->assertSame( 'spam', $this->trained_as( $id ) );
        $this->assertCount( 1, $this->repository()->find_all( 'latin', 'spam' ) );
        $this->assertSame( array(), $this->repository()->find_all( 'latin', 'ham' ) );

        // The retry then flips cleanly.
        antispamwizard_test_set_comment_status( $id, 'spam' );
        antispamwizard_test_set_comment_status( $id, '1' );

        $this->assertSame( 'ham', $this->trained_as( $id ) );
        $this->assertSame( array(), $this->repository()->find_all( 'latin', 'spam' ) );
        $this->assertCount( 1, $this->repository()->find_all( 'latin', 'ham' ) );
    }

    public function test_a_null_repository_rolls_the_claim_back(): void {
        $id = $this->seed_comment( 191, 'Cheap tours to anywhere, call now.' );

        $reflection = new ReflectionClass( ANTISPAMWIZARD::class );
        $repo       = $reflection->getProperty( 'repository' );
        $repo->setValue( ANTISPAMWIZARD::get_instance(), null );

        antispamwizard_test_set_comment_status( $id, 'spam' );

        $this->assertSame( '', $this->trained_as( $id ) );
    }

    public function test_the_pill_followed_by_its_transition_stores_exactly_one_sample(): void {
        global $wp_json_responses;

        $id = $this->seed_comment( 201, 'Promotional junk about crypto doubling.' );
        ANTISPAMWIZARD_Spam_Log::add_entry( 'Promotional junk about crypto doubling.', array( 'bayesian' ), 'iphash', $id );

        $admin_page          = new ANTISPAMWIZARD_Admin_Page();
        $_POST['nonce']      = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['comment_id'] = (string) $id;
        $_POST['category']   = 'spam';

        // ajax_mark_comment() claims the slot, inserts, then calls
        // wp_spam_comment() -- which fires the transition this class handles.
        $admin_page->ajax_mark_comment();

        $this->assertTrue( $wp_json_responses[0]['success'] );
        $this->assertCount( 1, $this->repository()->find_all( 'latin', 'spam' ) );
        $this->assertCount( 1, ANTISPAMWIZARD_Spam_Log::get_entries_by_comment_ids( array( $id ) ) );
        $this->assertSame( 'spam', $this->trained_as( $id ) );
    }

    public function test_a_transition_followed_by_the_pill_stores_exactly_one_sample(): void {
        global $wp_json_responses;

        $id = $this->seed_comment( 202, 'Promotional junk about loan offers.' );

        antispamwizard_test_set_comment_status( $id, 'spam' );
        $this->assertCount( 1, $this->repository()->find_all( 'latin', 'spam' ) );

        $admin_page          = new ANTISPAMWIZARD_Admin_Page();
        $_POST['nonce']      = wp_create_nonce( ANTISPAMWIZARD_Admin_Page::MARK_NONCE );
        $_POST['comment_id'] = (string) $id;
        $_POST['category']   = 'spam';

        $admin_page->ajax_mark_comment();

        // The pill refuses -- mark_trained() still declines a trained entry --
        // and, either way, exactly one sample is stored.
        $this->assertFalse( $wp_json_responses[0]['success'] );
        $this->assertCount( 1, $this->repository()->find_all( 'latin', 'spam' ) );
        $this->assertSame( 'spam', $this->trained_as( $id ) );
    }

    public function test_a_throwing_repository_does_not_fatal_the_moderation_action(): void {
        $id = $this->seed_comment( 205, 'Cheap pills and cheaper watches.' );

        $reflection = new ReflectionClass( ANTISPAMWIZARD::class );
        $repo       = $reflection->getProperty( 'repository' );
        $repo->setValue( ANTISPAMWIZARD::get_instance(), new ANTISPAMWIZARD_Throwing_Sample_Repository( $GLOBALS['wpdb'] ) );

        // The hook runs inside wp_set_comment_status(); an uncaught throw would
        // fatal the Comments screen mid-moderation. It must fail open instead.
        antispamwizard_test_set_comment_status( $id, 'spam' );

        $this->assertSame( 'spam', $GLOBALS['wp_comments'][ $id ]['comment_approved'] );
        // The entry still records what the moderator did, and the claim is rolled
        // back: a claim left standing past a throw would come back
        // 'already_trained' on every later transition, so no sample would ever be
        // stored for this comment again.
        $this->assertCount( 1, ANTISPAMWIZARD_Spam_Log::get_entries_by_comment_ids( array( $id ) ) );
        $this->assertSame( '', $this->trained_as( $id ) );

        // With a working repository the next moderation stores the sample.
        $repo->setValue( ANTISPAMWIZARD::get_instance(), new ANTISPAMWIZARD_Sample_Repository( $GLOBALS['wpdb'] ) );
        antispamwizard_test_set_comment_status( $id, '1' );
        antispamwizard_test_set_comment_status( $id, 'spam' );

        $this->assertSame( array( 'Cheap pills and cheaper watches.' ), $this->repository()->find_all( 'latin', 'spam' ) );
        $this->assertSame( 'spam', $this->trained_as( $id ) );
    }

    public function test_a_stale_opposite_sample_left_by_a_failed_flip_delete_is_cleaned_up(): void {
        global $wpdb;

        $id = $this->seed_comment( 210, 'Thoughtful note about caching headers.' );

        // Trained as spam first, so the flip below has a real row to delete.
        antispamwizard_test_set_comment_status( $id, 'spam' );
        $this->assertSame( 'spam', $this->trained_as( $id ) );

        // Not Spam: the ham insert lands and the DELETE that should clear the
        // spam row is refused, so the rollback restores 'spam' on the log while
        // the table holds the text in both categories.
        $wpdb->delete_result = false;
        antispamwizard_test_set_comment_status( $id, '0' );
        $wpdb->delete_result = null;

        $this->assertSame( 'spam', $this->trained_as( $id ) );
        $this->assertCount( 1, $this->repository()->find_all( 'latin', 'ham' ) );
        $this->assertCount( 1, $this->repository()->find_all( 'latin', 'spam' ) );

        // The only teaching transition left is back to spam -- the category the
        // log already names -- so the 'already_trained' claim must not
        // short-circuit while the contradicting row is still stored, or the text
        // stays trained as both categories for good.
        antispamwizard_test_set_comment_status( $id, 'spam' );

        $this->assertSame( 'spam', $this->trained_as( $id ) );
        $this->assertSame( array( 'Thoughtful note about caching headers.' ), $this->repository()->find_all( 'latin', 'spam' ) );
        $this->assertSame( array(), $this->repository()->find_all( 'latin', 'ham' ) );
    }

    /**
     * The same cleanup, when the contradicting row belongs to a *different*
     * comment carrying identical text.
     *
     * insert() deduplicates by content, so the row a second comment trains is the
     * row the first one created and keeps the first comment's comment_id. A
     * verification that asked "does this comment have a sample in the opposite
     * category" therefore could not see the contradiction at all: it looked up a
     * row this commit does not write. The claim would be reported settled and the
     * text would stay trained as both categories for good, which is the state the
     * flip delete exists to prevent -- so the question has to be asked of the
     * sample identity (script + content hash), the key insert() and
     * delete_by_hash() actually write.
     */
    public function test_an_opposite_sample_owned_by_another_comment_with_the_same_text_is_cleaned_up(): void {
        $text = 'One body posted from two addresses.';

        // The spam row is trained from one comment ...
        $other = $this->seed_comment( 220, $text );
        antispamwizard_test_set_comment_status( $other, 'spam' );
        $this->assertSame( array( $text ), $this->repository()->find_all( 'latin', 'spam' ) );

        // ... and the ham row from another comment carrying the identical body,
        // whose own claim was left naming 'ham' while the flip delete that should
        // have cleared the spam row never landed. Seeded directly: the point is
        // the stuck state, not the sequence of failures that reaches it.
        $id = $this->seed_comment( 221, $text, 'spam' );
        $this->repository()->insert( 'latin', 'ham', $text, $id );
        ANTISPAMWIZARD_Spam_Log::add_entry( $text, array( 'manual' ), '', $id );
        ANTISPAMWIZARD_Spam_Log::mark_trained( $id, 'ham' );

        $this->assertCount( 1, $this->repository()->find_all( 'latin', 'spam' ) );
        $this->assertCount( 1, $this->repository()->find_all( 'latin', 'ham' ) );

        // Not Spam again: the claim comes back 'already_trained', and the
        // contradicting spam row -- owned by the other comment -- must still be
        // cleaned up rather than skipped.
        antispamwizard_test_set_comment_status( $id, '0' );

        $this->assertSame( 'ham', $this->trained_as( $id ) );
        $this->assertSame( array( $text ), $this->repository()->find_all( 'latin', 'ham' ) );
        $this->assertSame( array(), $this->repository()->find_all( 'latin', 'spam' ) );
    }

    /**
     * The lock is keyed by the body, not the comment, because the body is what
     * the commit writes: insert() and delete_by_hash() address rows by (script,
     * category, content_hash). Two comments carrying identical text -- one bot
     * body sent from many addresses -- moderated in opposite directions under
     * different locks would each insert their category and then each delete the
     * other's row, leaving the text trained as neither while both log claims
     * stood.
     */
    public function test_comments_with_identical_text_take_the_same_moderation_lock(): void {
        $upload_dir = wp_upload_dir();
        $lock_dir   = $upload_dir['basedir'] . '/.antispamwizard-locks';

        foreach ( glob( $lock_dir . '/antispamwizard_moderation_*.lock' ) ?: array() as $stale ) {
            unlink( $stale );
        }

        $text = 'Identical body from two commenters.';

        // The two ids fall in different buckets under the old comment-id keying,
        // so a single lock file can only mean the key is the body.
        $this->assertNotSame( substr( md5( '230' ), -2 ), substr( md5( '231' ), -2 ) );

        antispamwizard_test_set_comment_status( $this->seed_comment( 230, $text ), 'spam' );
        antispamwizard_test_set_comment_status( $this->seed_comment( 231, $text ), 'spam' );

        $locks = glob( $lock_dir . '/antispamwizard_moderation_*.lock' ) ?: array();
        $this->assertCount( 1, $locks );
        $this->assertSame(
            $lock_dir . '/antispamwizard_moderation_' . md5( ABSPATH ) . '_' . substr( md5( $text ), -2 ) . '.lock',
            $locks[0]
        );
    }

    public function test_a_failed_flip_delete_rolls_the_claim_back_so_the_next_moderation_retries(): void {
        global $wpdb;

        $id = $this->seed_comment( 206, 'Helpful note about template caching.' );

        // The DELETE that clears the opposite category is refused on a first
        // training, so the claim being rolled back is 'ok' and there is no
        // opposite row (the test above covers the real flip). Its false return is
        // a failed query, not "nothing matched": leaving the claim standing would
        // keep this text trained as both categories for good, because every later
        // transition would return 'already_trained'.
        $wpdb->delete_result = false;
        antispamwizard_test_set_comment_status( $id, 'spam' );
        $wpdb->delete_result = null;

        $this->assertCount( 1, $this->repository()->find_all( 'latin', 'spam' ) );
        $this->assertSame( '', $this->trained_as( $id ) );

        // The retry re-runs the whole sequence: the insert dedups to 'exists' and
        // the delete succeeds, so the claim finally stands.
        antispamwizard_test_set_comment_status( $id, '1' );
        antispamwizard_test_set_comment_status( $id, 'spam' );

        $this->assertSame( 'spam', $this->trained_as( $id ) );
        $this->assertCount( 1, $this->repository()->find_all( 'latin', 'spam' ) );
        $this->assertSame( array(), $this->repository()->find_all( 'latin', 'ham' ) );
    }

    public function test_a_claim_whose_option_write_failed_leaves_the_table_alone(): void {
        global $wp_update_option_fails;

        $id = $this->seed_comment( 207, 'Another comment about cheap watches.' );
        ANTISPAMWIZARD_Spam_Log::add_entry( 'Another comment about cheap watches.', array( 'links' ), 'iphash', $id );

        // A refused options write means the entry exists but was never claimed,
        // so claim_training() reports 'failed' and nothing is stored: writing the
        // sample anyway would move the table under a log entry that does not
        // record it. Nothing was written, so there is nothing to roll back
        // either.
        $wp_update_option_fails = array( 'antispamwizard_spam_log' );
        antispamwizard_test_set_comment_status( $id, 'spam' );
        $wp_update_option_fails = false;

        $this->assertSame( array(), $this->repository()->find_all( 'latin', 'spam' ) );
        $this->assertSame( '', $this->trained_as( $id ) );

        // The next moderation retries and stores the sample.
        antispamwizard_test_set_comment_status( $id, '1' );
        antispamwizard_test_set_comment_status( $id, 'spam' );

        $this->assertSame( array( 'Another comment about cheap watches.' ), $this->repository()->find_all( 'latin', 'spam' ) );
        $this->assertSame( 'spam', $this->trained_as( $id ) );
    }

    public function test_a_refused_flip_claim_does_not_move_the_table_under_the_stored_category(): void {
        global $wp_update_option_fails;

        $id = $this->seed_comment( 208, 'A note about caching plugins.' );
        antispamwizard_test_set_comment_status( $id, 'spam' );
        $this->assertSame( 'spam', $this->trained_as( $id ) );

        // Not Spam, with the log write refused. Training unlocked here would
        // insert the ham sample and delete the spam one while the entry on disk
        // still says 'spam' -- and the next spam transition would then come back
        // 'already_trained' and store nothing, leaving the corpus holding ham for
        // a comment the log calls spam, for good.
        $wp_update_option_fails = array( 'antispamwizard_spam_log' );
        antispamwizard_test_set_comment_status( $id, '1' );
        $wp_update_option_fails = false;

        $this->assertSame( array( 'A note about caching plugins.' ), $this->repository()->find_all( 'latin', 'spam' ) );
        $this->assertSame( array(), $this->repository()->find_all( 'latin', 'ham' ) );
        $this->assertSame( 'spam', $this->trained_as( $id ) );

        // The next Not Spam retries the flip in full.
        antispamwizard_test_set_comment_status( $id, 'spam' );
        antispamwizard_test_set_comment_status( $id, '1' );

        $this->assertSame( array(), $this->repository()->find_all( 'latin', 'spam' ) );
        $this->assertSame( array( 'A note about caching plugins.' ), $this->repository()->find_all( 'latin', 'ham' ) );
        $this->assertSame( 'ham', $this->trained_as( $id ) );
    }

    public function test_a_superseded_transition_does_not_train_over_the_newer_decision(): void {
        // Two overlapping moderations: "Spam" is clicked, the "Undo" that follows
        // overtakes it, and the spam callback only gets to its sample write after
        // the comment is already back out of the spam queue. WordPress writes the
        // comment row before it fires the hook, so the stale callback can see
        // that its decision was superseded -- and must stand down rather than
        // insert spam and delete the ham sample the newer transition stored.
        $id = $this->seed_comment( 209, 'Thanks, this fixed my sidebar.', 'spam' );
        antispamwizard_test_set_comment_status( $id, '1' );

        $this->assertSame( array( 'Thanks, this fixed my sidebar.' ), $this->repository()->find_all( 'latin', 'ham' ) );

        $moderation = new ANTISPAMWIZARD_Moderation();
        $moderation->on_transition( 'spam', 'approved', (object) array(
            'comment_ID'           => $id,
            'comment_content'      => 'Thanks, this fixed my sidebar.',
            'comment_approved'     => 'spam',
            'comment_author_email' => 'author@example.com',
            'comment_author_IP'    => '203.0.113.9',
        ) );

        $this->assertSame( array(), $this->repository()->find_all( 'latin', 'spam' ) );
        $this->assertSame( array( 'Thanks, this fixed my sidebar.' ), $this->repository()->find_all( 'latin', 'ham' ) );
        // Marking as ham never creates a log entry -- the plugin has nothing to
        // answer for there -- so the stale callback must not leave one either.
        $this->assertNull( $this->trained_as( $id ) );

        // The same call once the row really does say spam trains normally, so the
        // guard is reading the status rather than refusing every replay.
        antispamwizard_test_set_comment_status( $id, 'spam' );

        $this->assertSame( array( 'Thanks, this fixed my sidebar.' ), $this->repository()->find_all( 'latin', 'spam' ) );
        $this->assertSame( 'spam', $this->trained_as( $id ) );
    }

    public function test_a_superseded_transition_is_not_hidden_by_a_cached_comment(): void {
        // The same two overlapping moderations, but seen the way a real site sees
        // them: get_comment() answers from the object cache, and WordPress's
        // default cache is request-local, so wp_set_comment_status() left this
        // request holding the row as its own write made it -- 'spam' -- while the
        // "Undo" that overtook it committed 'approved' from another process,
        // where it could not touch this cache. Reading through that snapshot, the
        // guard would compare the status against the very write that fired it,
        // always agree, and overwrite the newer decision.
        global $wp_comment_cache;

        $id = $this->seed_comment( 211, 'Thanks, this fixed my sidebar.', 'spam' );
        antispamwizard_test_set_comment_status( $id, '1' );

        $this->assertSame( array( 'Thanks, this fixed my sidebar.' ), $this->repository()->find_all( 'latin', 'ham' ) );

        // The stale snapshot this request took before the other one committed.
        $wp_comment_cache[ $id ] = array(
            'comment_ID'           => $id,
            'comment_post_ID'      => 5,
            'comment_content'      => 'Thanks, this fixed my sidebar.',
            'comment_approved'     => 'spam',
            'comment_author_email' => 'author@example.com',
            'comment_author_IP'    => '203.0.113.9',
        );

        $moderation = new ANTISPAMWIZARD_Moderation();
        $moderation->on_transition( 'spam', 'approved', (object) array(
            'comment_ID'           => $id,
            'comment_content'      => 'Thanks, this fixed my sidebar.',
            'comment_approved'     => 'spam',
            'comment_author_email' => 'author@example.com',
            'comment_author_IP'    => '203.0.113.9',
        ) );

        $this->assertSame( array(), $this->repository()->find_all( 'latin', 'spam' ) );
        $this->assertSame( array( 'Thanks, this fixed my sidebar.' ), $this->repository()->find_all( 'latin', 'ham' ) );
        $this->assertNull( $this->trained_as( $id ) );
    }

    public function test_a_comment_that_no_longer_exists_creates_no_record(): void {
        // The comment was hard-deleted between the status write and this
        // callback -- by a concurrent request, or by an earlier hook on this same
        // action. A Spam Log entry is keyed by comment_id alone (a sample also
        // carries the salted email_hash, a form entry too), and ANTISPAMWIZARD_Privacy
        // reaches those entries only through the subject's existing comments, so
        // an entry written here could never be exported or erased and its
        // fragment and IP hash would outlive every request that could reach them.
        // Its Spam Log pill would be dead as well. Nothing is persisted.
        $moderation = new ANTISPAMWIZARD_Moderation();
        $moderation->on_transition( 'spam', 'approved', (object) array(
            'comment_ID'           => 4242,
            'comment_content'      => 'Buy cheap pills from our online pharmacy now.',
            'comment_approved'     => 'spam',
            'comment_author_email' => 'author@example.com',
            'comment_author_IP'    => '203.0.113.9',
        ) );

        $this->assertSame( array(), $this->repository()->find_all( 'latin', 'spam' ) );
        $this->assertSame( array(), ANTISPAMWIZARD_Spam_Log::get_entries_by_comment_ids( array( 4242 ) ) );
        $this->assertNull( $this->trained_as( 4242 ) );

        // The same payload once the row really is readable trains normally, so
        // the guard is reading the comment rather than refusing every replay.
        $this->seed_comment( 4242, 'Buy cheap pills from our online pharmacy now.', 'spam' );
        $moderation->on_transition( 'spam', 'approved', (object) array(
            'comment_ID'           => 4242,
            'comment_content'      => 'Buy cheap pills from our online pharmacy now.',
            'comment_approved'     => 'spam',
            'comment_author_email' => 'author@example.com',
            'comment_author_IP'    => '203.0.113.9',
        ) );

        $this->assertSame(
            array( 'Buy cheap pills from our online pharmacy now.' ),
            $this->repository()->find_all( 'latin', 'spam' )
        );
        $this->assertSame( 'spam', $this->trained_as( 4242 ) );
    }

    public function test_a_refused_rollback_is_reported_because_it_blocks_every_later_retry(): void {
        $id = $this->seed_comment( 210, 'Buy followers cheap today.' );

        $reflection = new ReflectionClass( ANTISPAMWIZARD::class );
        $repo       = $reflection->getProperty( 'repository' );
        $repo->setValue( ANTISPAMWIZARD::get_instance(), new ANTISPAMWIZARD_Option_Breaking_Sample_Repository( $GLOBALS['wpdb'] ) );

        $tmp     = tempnam( sys_get_temp_dir(), 'antispamwizard_test_' );
        $old_log = ini_set( 'error_log', $tmp );

        // The claim is written, the insert then fails, and the rollback write is
        // refused: the entry stays claimed over a training that never happened,
        // so every later transition reads 'already_trained' and stores nothing.
        // Nothing else on the site would show that, so it is logged.
        antispamwizard_test_set_comment_status( $id, 'spam' );

        ini_set( 'error_log', $old_log );
        $logged                            = file_get_contents( $tmp );
        $GLOBALS['wp_update_option_fails'] = false;
        unlink( $tmp );

        $this->assertStringContainsString( 'rollback failed for comment ' . $id, $logged );
        $this->assertSame( array(), $this->repository()->find_all( 'latin', 'spam' ) );
    }

    public function test_a_claim_left_by_a_refused_rollback_does_not_block_the_next_moderation(): void {
        // The state a refused rollback leaves: the entry says the comment is
        // trained as spam, the table holds no sample for it, and the flag cannot
        // be cleared -- the option store is what refused the write, and the Spam
        // Log pill will not overwrite a flag that is already set. Believing the
        // claim would make that comment untrainable for as long as its entry sits
        // in the log, so the claim is verified against the table instead.
        $id = $this->seed_comment( 213, 'Buy followers cheap today.' );

        $reflection = new ReflectionClass( ANTISPAMWIZARD::class );
        $repo       = $reflection->getProperty( 'repository' );
        $repo->setValue( ANTISPAMWIZARD::get_instance(), new ANTISPAMWIZARD_Option_Breaking_Sample_Repository( $GLOBALS['wpdb'] ) );

        $tmp     = tempnam( sys_get_temp_dir(), 'antispamwizard_test_' );
        $old_log = ini_set( 'error_log', $tmp );

        antispamwizard_test_set_comment_status( $id, 'spam' );

        ini_set( 'error_log', $old_log );
        $GLOBALS['wp_update_option_fails'] = false;
        unlink( $tmp );

        $repo->setValue( ANTISPAMWIZARD::get_instance(), new ANTISPAMWIZARD_Sample_Repository( $GLOBALS['wpdb'] ) );

        $this->assertSame( 'spam', $this->trained_as( $id ), 'The refused rollback should have left the claim standing.' );
        $this->assertSame( array(), $this->repository()->find_all( 'latin', 'spam' ) );

        // The database recovers and the comment is moderated again.
        $moderation = new ANTISPAMWIZARD_Moderation();
        $moderation->on_transition( 'spam', 'approved', (object) array(
            'comment_ID'           => $id,
            'comment_content'      => 'Buy followers cheap today.',
            'comment_approved'     => 'spam',
            'comment_author_email' => 'author@example.com',
            'comment_author_IP'    => '203.0.113.9',
        ) );

        $this->assertSame( array( 'Buy followers cheap today.' ), $this->repository()->find_all( 'latin', 'spam' ) );
        $this->assertSame( 'spam', $this->trained_as( $id ) );
    }

    public function test_a_stale_claim_is_verified_against_the_category_the_table_holds(): void {
        // The other half of the same failure: the claim was a flip. The entry was
        // trained as ham, the spam claim was written, the insert failed and the
        // restore back to ham was refused -- so the log says spam while the table
        // still holds the ham sample. A verification that only asked "is there a
        // sample for this comment" would find the ham row and stand down, leaving
        // the two disagreeing for good.
        $id = $this->seed_comment( 214, 'Thanks, this fixed my sidebar.', 'spam' );
        antispamwizard_test_set_comment_status( $id, '1' );

        $this->assertSame( array( 'Thanks, this fixed my sidebar.' ), $this->repository()->find_all( 'latin', 'ham' ) );

        $reflection = new ReflectionClass( ANTISPAMWIZARD::class );
        $repo       = $reflection->getProperty( 'repository' );
        $repo->setValue( ANTISPAMWIZARD::get_instance(), new ANTISPAMWIZARD_Option_Breaking_Sample_Repository( $GLOBALS['wpdb'] ) );

        $tmp     = tempnam( sys_get_temp_dir(), 'antispamwizard_test_' );
        $old_log = ini_set( 'error_log', $tmp );

        antispamwizard_test_set_comment_status( $id, 'spam' );

        ini_set( 'error_log', $old_log );
        $GLOBALS['wp_update_option_fails'] = false;
        unlink( $tmp );

        $repo->setValue( ANTISPAMWIZARD::get_instance(), new ANTISPAMWIZARD_Sample_Repository( $GLOBALS['wpdb'] ) );

        $this->assertSame( 'spam', $this->trained_as( $id ) );
        $this->assertSame( array( 'Thanks, this fixed my sidebar.' ), $this->repository()->find_all( 'latin', 'ham' ) );

        $moderation = new ANTISPAMWIZARD_Moderation();
        $moderation->on_transition( 'spam', 'approved', (object) array(
            'comment_ID'           => $id,
            'comment_content'      => 'Thanks, this fixed my sidebar.',
            'comment_approved'     => 'spam',
            'comment_author_email' => 'author@example.com',
            'comment_author_IP'    => '203.0.113.9',
        ) );

        $this->assertSame( array( 'Thanks, this fixed my sidebar.' ), $this->repository()->find_all( 'latin', 'spam' ) );
        $this->assertSame( array(), $this->repository()->find_all( 'latin', 'ham' ) );
        $this->assertSame( 'spam', $this->trained_as( $id ) );
    }

    public function test_the_fail_open_diagnostic_never_logs_the_exception_message(): void {
        // Same rule as ANTISPAMWIZARD_Detector::run() and ANTISPAMWIZARD_CF7_Integration::filter_spam():
        // a $wpdb or file-I/O exception can carry a query, an absolute path or a
        // credential, and debug.log is web-readable on misconfigured hosts. A
        // name-only grep for getMessage() would be passed unchanged by `. $e` or
        // "{$e}", both of which stringify the message and the whole stack trace,
        // so every use of the caught $e inside the catch block is enumerated and
        // only getFile()/getLine() are allowed.
        $source = file_get_contents( dirname( __DIR__ ) . '/antispam-wizard/includes/class-moderation.php' );
        $this->assertIsString( $source );

        $needle = 'catch ( \Throwable $e ) {';
        $start  = strpos( $source, $needle );
        $this->assertNotFalse( $start, 'on_transition() has no Throwable catch.' );

        $start += strlen( $needle );
        $end    = strpos( $source, 'private function train(', $start );
        $this->assertNotFalse( $end );

        $body = substr( $source, $start, $end - $start );
        $this->assertStringContainsString( 'error_log(', $body, 'The catch block logs nothing at all.' );

        preg_match_all( '/\$e\s*(?:->\s*([A-Za-z_]+))?/', $body, $matches, PREG_SET_ORDER );
        $this->assertNotEmpty( $matches );
        foreach ( $matches as $match ) {
            $method = isset( $match[1] ) ? $match[1] : '';
            $this->assertContains(
                $method,
                array( 'getFile', 'getLine' ),
                'The catch block uses $e in a way that can leak the exception message: ' . $match[0]
            );
        }
    }

    public function test_the_transition_hook_is_registered_with_three_arguments(): void {
        global $wp_actions;

        $this->assertArrayHasKey( 'transition_comment_status', $wp_actions );

        $found = null;
        foreach ( $wp_actions['transition_comment_status'] as $hook ) {
            if ( is_array( $hook['callback'] ) && $hook['callback'][0] instanceof ANTISPAMWIZARD_Moderation ) {
                $found = $hook;
                break;
            }
        }

        $this->assertNotNull( $found, 'ANTISPAMWIZARD_Moderation is not hooked on transition_comment_status.' );
        $this->assertSame( 10, $found['priority'] );
        $this->assertSame( 3, $found['accepted_args'] );
        $this->assertSame( 'on_transition', $found['callback'][1] );
    }
}
