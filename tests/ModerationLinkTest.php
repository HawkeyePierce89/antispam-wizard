<?php
/**
 * Tests for the "Mark as spam" link ANTISPAMWIZARD_Moderation renders under each comment
 * on the site, and for its admin-post handler.
 */

use PHPUnit\Framework\TestCase;

/**
 * Stands in for the $wp_nonces store and counts every read.
 *
 * wp_verify_nonce()'s stub reaches the store through isset() and an offset
 * read, both of which ArrayAccess serves, so swapping the array for this object
 * makes "the nonce store was never consulted" an assertable fact rather than an
 * inference from the wp_die() message.
 */
class ANTISPAMWIZARD_Nonce_Store_Spy implements ArrayAccess {

    public int $reads = 0;

    /** @var array<string,string> */
    private array $values = array();

    #[\ReturnTypeWillChange]
    public function offsetExists( $offset ) {
        ++$this->reads;
        return isset( $this->values[ $offset ] );
    }

    #[\ReturnTypeWillChange]
    public function offsetGet( $offset ) {
        ++$this->reads;
        return isset( $this->values[ $offset ] ) ? $this->values[ $offset ] : null;
    }

    #[\ReturnTypeWillChange]
    public function offsetSet( $offset, $value ) {
        $this->values[ $offset ] = $value;
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset( $offset ) {
        unset( $this->values[ $offset ] );
    }
}

class ModerationLinkTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();

        global $wp_options, $wp_actions, $wp_filters, $wp_transients, $wp_comments, $wp_nonces, $wp_current_user_caps, $wp_enqueued_styles, $wp_redirects, $wp_died, $wp_is_admin, $wp_is_feed, $wp_is_singular, $wpdb;
        $wp_options           = array();
        $wp_actions           = array();
        $wp_filters           = array();
        $wp_transients        = array();
        $wp_comments          = array();
        $wp_nonces            = array();
        $wp_current_user_caps = array( 'manage_options' => true );
        $wp_enqueued_styles   = array();
        $wp_redirects         = array();
        $wp_died              = array();
        $wp_is_admin          = false;
        $wp_is_feed           = false;
        $wp_is_singular       = true;
        $_GET                 = array();
        $_POST                = array();
        $_REQUEST             = array();

        $GLOBALS['wp_comment_status_change_fails'] = false;

        if ( ! defined( 'ANTISPAMWIZARD_PATH' ) ) {
            define( 'ANTISPAMWIZARD_PATH', dirname( __DIR__ ) . '/antispam-wizard/' );
        }

        $wpdb->reset_tables();

        $reflection = new ReflectionClass( ANTISPAMWIZARD::class );
        $instance   = $reflection->getProperty( 'instance' );
        $instance->setValue( null, null );

        ANTISPAMWIZARD::get_instance()->init();

        // ANTISPAMWIZARD_Shadow_Display's registry is static and outlives a test; a
        // neighbour's shadow row would otherwise hide the link here.
        ( new ReflectionProperty( ANTISPAMWIZARD_Shadow_Display::class, 'shadowed' ) )->setValue( null, array() );

        ANTISPAMWIZARD_Spam_Log::clear();
    }

    protected function tearDown(): void {
        global $wp_nonces;
        // One test swaps this global for an ArrayAccess spy; leaving it behind
        // would hand a later class an object where it expects an array.
        $wp_nonces = array();
        $_GET      = array();
        $_POST     = array();
        $_REQUEST  = array();
        parent::tearDown();
    }

    private function moderation(): ANTISPAMWIZARD_Moderation {
        return new ANTISPAMWIZARD_Moderation();
    }

    /**
     * Seed a comment fixture and return it as the object comment_text hands on.
     */
    private function seed_comment( int $id, string $approved = '1', string $content = 'Buy cheap pills online now.' ): object {
        global $wp_comments;
        $wp_comments[ $id ] = array(
            'comment_ID'           => $id,
            'comment_post_ID'      => 7,
            'comment_content'      => $content,
            'comment_approved'     => $approved,
            'comment_author_email' => 'author@example.com',
            'comment_author_IP'    => '203.0.113.9',
        );
        return (object) $wp_comments[ $id ];
    }

    /**
     * Drive the handler the way admin-post.php would, with the link's own query
     * string in $_GET and $_REQUEST.
     */
    private function request( int $comment_id, string $nonce ): void {
        $_GET     = array(
            'action'     => ANTISPAMWIZARD_Moderation::ACTION,
            'comment_id' => (string) $comment_id,
        );
        $_REQUEST = $_GET + array( '_wpnonce' => $nonce );
    }

    /**
     * Mint the nonce the link for $comment_id carries.
     */
    private function mint_nonce( int $comment_id ): string {
        global $wp_nonces;
        wp_create_nonce( ANTISPAMWIZARD_Moderation::ACTION . '_' . $comment_id );
        return $wp_nonces[ ANTISPAMWIZARD_Moderation::ACTION . '_' . $comment_id ];
    }

    // ---------------------------------------------------------------- Hooks.

    public function test_comment_text_filter_is_registered_at_priority_100(): void {
        global $wp_filters;

        $moderation = $this->moderation();
        $moderation->register_hooks();

        $this->assertArrayHasKey( 'comment_text', $wp_filters );

        $found = null;
        foreach ( $wp_filters['comment_text'] as $hook ) {
            if ( is_array( $hook['callback'] )
                && $hook['callback'][0] instanceof ANTISPAMWIZARD_Moderation
                && 'append_moderation_link' === $hook['callback'][1] ) {
                $found = $hook;
            }
        }

        $this->assertNotNull( $found, 'append_moderation_link is not registered on comment_text.' );
        // Core runs wpautop at 30 and capital_P_dangit at 31 on this filter; the
        // appended <p><a> block must be the last thing touched.
        $this->assertSame( 100, $found['priority'] );
        $this->assertSame( 2, $found['accepted_args'] );
    }

    public function test_the_admin_post_handler_is_registered_for_logged_in_and_logged_out_requests(): void {
        global $wp_actions;

        $moderation = $this->moderation();
        $moderation->register_hooks();

        foreach ( array( 'admin_post_', 'admin_post_nopriv_' ) as $prefix ) {
            $hook = $prefix . ANTISPAMWIZARD_Moderation::ACTION;
            $this->assertArrayHasKey( $hook, $wp_actions );

            $found = false;
            foreach ( $wp_actions[ $hook ] as $registered ) {
                if ( array( $moderation, 'handle_mark_spam' ) === $registered['callback'] ) {
                    $found = true;
                }
            }

            // A misspelled callback would leave the link 500-ing with the bare
            // assertArrayHasKey still green.
            $this->assertTrue( $found, $hook . ' is registered with the wrong callback.' );
        }
    }

    public function test_the_stylesheet_is_registered_on_wp_enqueue_scripts(): void {
        global $wp_actions;

        $moderation = $this->moderation();
        $moderation->register_hooks();

        $this->assertArrayHasKey( 'wp_enqueue_scripts', $wp_actions );

        $found = false;
        foreach ( $wp_actions['wp_enqueue_scripts'] as $hook ) {
            if ( array( $moderation, 'enqueue_styles' ) === $hook['callback'] ) {
                $found = true;
            }
        }

        // Without this registration the link ships on every site with no
        // stylesheet, and calling enqueue_styles() directly would not notice.
        $this->assertTrue( $found, 'enqueue_styles is not registered on wp_enqueue_scripts.' );
    }

    // ----------------------------------------------------------- The link.

    public function test_administrator_sees_the_link_under_a_non_spam_comment(): void {
        $comment = $this->seed_comment( 201 );

        $output = $this->moderation()->append_moderation_link( '<p>Nice post.</p>', $comment );

        $this->assertStringStartsWith( '<p>Nice post.</p>', $output );
        $this->assertStringContainsString( 'class="antispamwizard-moderate"', $output );
        $this->assertStringContainsString( 'class="antispamwizard-moderate-link"', $output );
        $this->assertStringContainsString( 'Mark as spam', $output );
    }

    public function test_the_href_carries_the_action_the_comment_id_and_a_nonce(): void {
        global $wp_nonces;
        $comment = $this->seed_comment( 202 );

        $output = $this->moderation()->append_moderation_link( 'body', $comment );

        $this->assertStringContainsString( 'admin-post.php', $output );
        $this->assertStringContainsString( 'action=' . ANTISPAMWIZARD_Moderation::ACTION, $output );
        $this->assertStringContainsString( 'comment_id=202', $output );

        // The nonce is bound to this one comment, so a harvested link cannot be
        // replayed against another.
        $action = ANTISPAMWIZARD_Moderation::ACTION . '_202';
        $this->assertArrayHasKey( $action, $wp_nonces );
        $this->assertStringContainsString( '_wpnonce=' . rawurlencode( $wp_nonces[ $action ] ), $output );
    }

    public function test_a_visitor_without_the_capability_gets_byte_identical_output(): void {
        global $wp_current_user_caps;
        $wp_current_user_caps = array();
        $comment              = $this->seed_comment( 203 );

        $this->assertSame( '<p>Nice post.</p>', $this->moderation()->append_moderation_link( '<p>Nice post.</p>', $comment ) );
    }

    public function test_an_editor_without_manage_options_gets_byte_identical_output(): void {
        global $wp_current_user_caps;
        $wp_current_user_caps = array( 'moderate_comments' => true, 'edit_posts' => true );
        $comment              = $this->seed_comment( 204 );

        $this->assertSame( 'body', $this->moderation()->append_moderation_link( 'body', $comment ) );
    }

    public function test_the_admin_screen_gets_byte_identical_output(): void {
        global $wp_is_admin;
        $wp_is_admin = true;
        $comment     = $this->seed_comment( 205 );

        $this->assertSame( 'body', $this->moderation()->append_moderation_link( 'body', $comment ) );
    }

    public function test_a_feed_gets_byte_identical_output(): void {
        global $wp_is_feed;
        $wp_is_feed = true;
        $comment    = $this->seed_comment( 206 );

        $this->assertSame( 'body', $this->moderation()->append_moderation_link( 'body', $comment ) );
    }

    /**
     * REST_REQUEST cannot be undefined again once set, so this case runs in a
     * child PHP process rather than poisoning every later
     * append_moderation_link() call in this run.
     *
     * A child process rather than PHPUnit's @runInSeparateProcess: that
     * annotation serializes global state into the child, and by the time this
     * class runs the suite's globals hold anonymous-class check fixtures, which
     * PHP refuses to serialize.
     */
    public function test_a_rest_request_gets_byte_identical_output(): void {
        $this->assertSame( 'body', $this->render_in_child_process( true ) );

        // The control: the same child without REST_REQUEST renders the link, so a
        // child that merely failed to boot cannot make this pass vacuously.
        $this->assertStringContainsString( 'antispamwizard-moderate-link', $this->render_in_child_process( false ) );
    }

    /**
     * Render the link in a child process, optionally under REST_REQUEST.
     */
    private function render_in_child_process( bool $rest ): string {
        $bootstrap = __DIR__ . '/bootstrap.php';
        $script    = "<?php\n"
            . ( $rest ? "define( 'REST_REQUEST', true );\n" : '' )
            . 'require ' . var_export( $bootstrap, true ) . ";\n"
            . "\$GLOBALS['wp_is_admin']          = false;\n"
            . "\$GLOBALS['wp_is_feed']           = false;\n"
            . "\$GLOBALS['wp_current_user_caps'] = array( 'manage_options' => true );\n"
            . "\$comment = (object) array( 'comment_ID' => 207, 'comment_post_ID' => 7, 'comment_approved' => '1' );\n"
            . "echo ( new ANTISPAMWIZARD_Moderation() )->append_moderation_link( 'body', \$comment );\n";

        $file = tempnam( sys_get_temp_dir(), 'antispamwizard-rest-' );
        file_put_contents( $file, $script );

        try {
            return (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ' 2>&1' );
        } finally {
            unlink( $file );
        }
    }

    public function test_no_link_under_a_comment_already_in_spam(): void {
        $comment = $this->seed_comment( 208, 'spam' );

        $this->assertSame( 'body', $this->moderation()->append_moderation_link( 'body', $comment ) );
    }

    public function test_no_link_under_a_shadow_displayed_comment(): void {
        // ANTISPAMWIZARD_Shadow_Display presents the author's flagged comment as held
        // ('0'), but its real status is spam: the link decides on that, never on
        // the presented copy.
        $comment = $this->seed_comment( 210, '0' );
        ( new ReflectionProperty( ANTISPAMWIZARD_Shadow_Display::class, 'shadowed' ) )->setValue( null, array( 210 => true ) );

        $this->assertSame( '<p>Nice post.</p>', $this->moderation()->append_moderation_link( '<p>Nice post.</p>', $comment ) );
    }

    public function test_the_link_still_renders_under_an_ordinary_comment_beside_a_shadow_one(): void {
        $comment = $this->seed_comment( 211 );
        ( new ReflectionProperty( ANTISPAMWIZARD_Shadow_Display::class, 'shadowed' ) )->setValue( null, array( 210 => true ) );

        $this->assertStringContainsString( 'antispamwizard-moderate-link', $this->moderation()->append_moderation_link( 'body', $comment ) );
    }

    public function test_a_non_string_body_is_handed_back_untouched(): void {
        $comment = $this->seed_comment( 209 );

        // An earlier comment_text filter returning null is an ordinary
        // third-party bug; a `string` parameter would turn it into an uncaught
        // TypeError that fatals the whole post page.
        $this->assertNull( $this->moderation()->append_moderation_link( null, $comment ) );
        $this->assertSame( array( 'x' ), $this->moderation()->append_moderation_link( array( 'x' ), $comment ) );
    }

    public function test_no_link_without_a_comment_object(): void {
        $this->assertSame( 'body', $this->moderation()->append_moderation_link( 'body', null ) );
        $this->assertSame( 'body', $this->moderation()->append_moderation_link( 'body', (object) array( 'comment_ID' => 0 ) ) );
    }

    // -------------------------------------------------------------- Styles.

    public function test_the_stylesheet_is_enqueued_for_an_administrator_on_a_singular_page(): void {
        global $wp_enqueued_styles;

        $this->moderation()->enqueue_styles();

        $this->assertArrayHasKey( 'antispamwizard-comment-form', $wp_enqueued_styles );
        $this->assertSame( ANTISPAMWIZARD_URL . 'public/css/comment-form.css', $wp_enqueued_styles['antispamwizard-comment-form']['src'] );
        $this->assertSame( ANTISPAMWIZARD_VERSION, $wp_enqueued_styles['antispamwizard-comment-form']['ver'] );
    }

    public function test_the_stylesheet_is_not_enqueued_for_a_visitor(): void {
        global $wp_current_user_caps, $wp_enqueued_styles;
        $wp_current_user_caps = array();

        $this->moderation()->enqueue_styles();

        $this->assertSame( array(), $wp_enqueued_styles );
    }

    public function test_the_stylesheet_is_not_enqueued_off_a_singular_page(): void {
        global $wp_is_singular, $wp_enqueued_styles;
        $wp_is_singular = false;

        $this->moderation()->enqueue_styles();

        $this->assertSame( array(), $wp_enqueued_styles );
    }

    // ------------------------------------------------------------- Handler.

    public function test_following_the_link_spams_the_comment_trains_it_and_redirects(): void {
        global $wp_comments, $wp_redirects;
        $this->moderation()->register_hooks();
        $this->seed_comment( 210, '1', 'Order cheap viagra from our online pharmacy today.' );
        $this->request( 210, $this->mint_nonce( 210 ) );

        $this->moderation()->handle_mark_spam();

        $this->assertSame( 'spam', $wp_comments[210]['comment_approved'] );
        $this->assertSame( array( array( 'location' => get_permalink( 7 ), 'status' => 302 ) ), $wp_redirects );

        // The link itself teaches nothing: the transition it fires does.
        $samples = ANTISPAMWIZARD::get_instance()->get_repository()->find_all( 'latin', 'spam' );
        $this->assertSame( array( 'Order cheap viagra from our online pharmacy today.' ), $samples );

        $entries = ANTISPAMWIZARD_Spam_Log::get_entries_by_comment_ids( array( 210 ) );
        $this->assertCount( 1, $entries );
        $this->assertSame( 'spam', $entries[0]['trained_as'] );
    }

    public function test_the_rendered_href_drives_the_handler_end_to_end(): void {
        global $wp_comments, $wp_redirects;

        $moderation = $this->moderation();
        $moderation->register_hooks();
        $comment = $this->seed_comment( 216, '1', 'Cheap replica watches, order today.' );

        // Parse the query string the link actually emitted rather than rebuilding
        // it, so a rename or an encoding change on either side is caught.
        $html = $moderation->append_moderation_link( 'body', $comment );
        $this->assertSame( 1, preg_match( '/href="([^"]+)"/', $html, $match ) );

        $query = parse_url( html_entity_decode( $match[1], ENT_QUOTES ), PHP_URL_QUERY );
        $this->assertIsString( $query );

        $args = array();
        parse_str( $query, $args );
        $this->assertSame( ANTISPAMWIZARD_Moderation::ACTION, $args['action'] );

        $_GET     = $args;
        $_REQUEST = $args;

        $moderation->handle_mark_spam();

        $this->assertSame( 'spam', $wp_comments[216]['comment_approved'] );
        $this->assertCount( 1, $wp_redirects );
        $this->assertSame(
            array( 'Cheap replica watches, order today.' ),
            ANTISPAMWIZARD::get_instance()->get_repository()->find_all( 'latin', 'spam' )
        );
    }

    public function test_a_comment_already_in_spam_is_left_alone_and_still_redirects(): void {
        global $wp_comments, $wp_redirects;
        $this->moderation()->register_hooks();
        $this->seed_comment( 211, 'spam', 'Already in the spam queue.' );
        $this->request( 211, $this->mint_nonce( 211 ) );

        $this->moderation()->handle_mark_spam();

        $this->assertSame( 'spam', $wp_comments[211]['comment_approved'] );
        $this->assertSame( array(), ANTISPAMWIZARD_Spam_Log::get_entries_by_comment_ids( array( 211 ) ) );
        $this->assertCount( 1, $wp_redirects );
    }

    public function test_the_redirect_falls_back_to_the_home_page_without_a_permalink(): void {
        global $wp_comments, $wp_redirects;
        $this->seed_comment( 212 );
        $wp_comments[212]['comment_post_ID'] = 0;
        $this->request( 212, $this->mint_nonce( 212 ) );

        $this->moderation()->handle_mark_spam();

        $this->assertSame( home_url( '/' ), $wp_redirects[0]['location'] );
    }

    public function test_a_nonce_minted_for_another_comment_is_refused(): void {
        global $wp_comments, $wp_died, $wp_redirects;
        $this->moderation()->register_hooks();
        $this->seed_comment( 213 );
        $this->seed_comment( 214 );

        // The link for comment 214, replayed against comment 213.
        $this->request( 213, $this->mint_nonce( 214 ) );

        $this->expectException( ANTISPAMWIZARD_Test_WP_Die_Exception::class );
        try {
            $this->moderation()->handle_mark_spam();
        } finally {
            $this->assertSame( '1', $wp_comments[213]['comment_approved'] );
            $this->assertSame( array(), $wp_redirects );
            $this->assertCount( 1, $wp_died );
            $this->assertSame( 403, $wp_died[0]['response'] );
        }
    }

    public function test_the_capability_is_refused_before_the_nonce_is_checked(): void {
        global $wp_current_user_caps, $wp_died, $wp_comments, $wp_nonces;
        $this->seed_comment( 215 );

        // A perfectly valid nonce, so only the ordering can decide the outcome.
        $nonce                = $this->mint_nonce( 215 );
        $spy                  = new ANTISPAMWIZARD_Nonce_Store_Spy();
        $spy[ ANTISPAMWIZARD_Moderation::ACTION . '_215' ] = $nonce;
        $wp_nonces            = $spy;
        $this->request( 215, $nonce );
        $wp_current_user_caps = array();

        $this->expectException( ANTISPAMWIZARD_Test_WP_Die_Exception::class );
        try {
            $this->moderation()->handle_mark_spam();
        } finally {
            $this->assertCount( 1, $wp_died );
            $this->assertSame( 403, $wp_died[0]['response'] );
            $this->assertSame( 'You do not have permission to moderate comments.', $wp_died[0]['message'] );
            $this->assertSame( 0, $spy->reads, 'The nonce store was consulted before the capability check.' );
            $this->assertSame( '1', $wp_comments[215]['comment_approved'] );
        }
    }

    public function test_a_failed_status_change_is_reported_instead_of_redirecting(): void {
        global $wp_comments, $wp_died, $wp_redirects, $wp_comment_status_change_fails;
        $this->moderation()->register_hooks();
        $this->seed_comment( 217, '1', 'Cheap pills, order now.' );
        $this->request( 217, $this->mint_nonce( 217 ) );

        // wp_update_comment() refuses the write, so wp_spam_comment() returns
        // false, no transition fires and nothing is trained. Redirecting anyway
        // would drop the moderator back on a page where the comment is still
        // visible with no sign that the click did nothing.
        $wp_comment_status_change_fails = true;

        $this->expectException( ANTISPAMWIZARD_Test_WP_Die_Exception::class );
        try {
            $this->moderation()->handle_mark_spam();
        } finally {
            $wp_comment_status_change_fails = false;
            $this->assertSame( '1', $wp_comments[217]['comment_approved'] );
            $this->assertSame( array(), $wp_redirects );
            $this->assertCount( 1, $wp_died );
            $this->assertSame( 500, $wp_died[0]['response'] );
            $this->assertSame( 'Comment status could not be changed. Please try again.', $wp_died[0]['message'] );
            $this->assertSame( array(), ANTISPAMWIZARD::get_instance()->get_repository()->find_all( 'latin', 'spam' ) );
        }
    }

    public function test_a_missing_comment_gives_404(): void {
        global $wp_died, $wp_redirects;
        $this->request( 999, $this->mint_nonce( 999 ) );

        $this->expectException( ANTISPAMWIZARD_Test_WP_Die_Exception::class );
        try {
            $this->moderation()->handle_mark_spam();
        } finally {
            $this->assertCount( 1, $wp_died );
            $this->assertSame( 404, $wp_died[0]['response'] );
            $this->assertSame( 'Comment not found.', $wp_died[0]['message'] );
            $this->assertSame( array(), $wp_redirects );
        }
    }
}
