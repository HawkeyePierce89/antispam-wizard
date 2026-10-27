<?php
/**
 * Tests for ANTISPAMWIZARD_Shadow_Display: which comment queries are widened with the
 * visitor's own auto-flagged spam, how, and which are left byte-identical.
 *
 * Everything is driven through the WP_Comment_Query stub, which runs core's
 * order of operations (pre_get_comments → cache key → approved clause →
 * comments_clauses) and records every final WHERE and cache key.
 */

use PHPUnit\Framework\TestCase;

/**
 * Stands in for the $wp_options store and counts every read, so "returned
 * before any option read" is an assertable fact.
 */
class ANTISPAMWIZARD_Option_Store_Spy implements ArrayAccess {

    public int $reads = 0;

    /** @var array<string,mixed> */
    private array $values = array();

    #[\ReturnTypeWillChange]
    public function offsetExists( $offset ) {
        ++$this->reads;
        return isset( $this->values[ $offset ] );
    }

    #[\ReturnTypeWillChange]
    public function offsetGet( $offset ) {
        ++$this->reads;
        return $this->values[ $offset ] ?? null;
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

/**
 * A comment object, and a clauses array, whose every read throws: drives the
 * fail-open catch of the callbacks that read them.
 */
class ANTISPAMWIZARD_Throwing_Shadow_Input implements ArrayAccess {

    public function __isset( $name ) {
        throw new RuntimeException( 'shadow input stub: secret failure detail' );
    }

    public function __get( $name ) {
        throw new RuntimeException( 'shadow input stub: secret failure detail' );
    }

    #[\ReturnTypeWillChange]
    public function offsetExists( $offset ) {
        throw new RuntimeException( 'shadow input stub: secret failure detail' );
    }

    #[\ReturnTypeWillChange]
    public function offsetGet( $offset ) {
        throw new RuntimeException( 'shadow input stub: secret failure detail' );
    }

    #[\ReturnTypeWillChange]
    public function offsetSet( $offset, $value ) {
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset( $offset ) {
    }
}

class ShadowDisplayTest extends TestCase {

    private const CORE_EMAIL_APPROVED = "( ( comment_approved = '1' ) OR ( comment_author_email = 'me@example.com' AND comment_approved = '0' ) )";

    private const EMAIL_SHADOW = "( comment_author_email = 'me@example.com' AND comment_approved = 'spam' AND comment_type IN ( '', 'comment' ) AND `wp_comments`.comment_ID > 0 AND EXISTS ( SELECT 1 FROM `wp_commentmeta` WHERE comment_id = `wp_comments`.comment_ID AND meta_key = '_antispamwizard_reason' ) )";

    private const USER_SHADOW = "( user_id = 42 AND comment_approved = 'spam' AND comment_type IN ( '', 'comment' ) AND `wp_comments`.comment_ID > 0 AND EXISTS ( SELECT 1 FROM `wp_commentmeta` WHERE comment_id = `wp_comments`.comment_ID AND meta_key = '_antispamwizard_reason' ) )";

    protected function setUp(): void {
        parent::setUp();
        global $wp_options, $wp_actions, $wp_filters, $wp_comments, $wp_comment_meta, $wp_comment_cache, $wp_is_admin, $wp_is_feed, $wpdb;
        // The plugin flagged its first comment while every seeded ID existed.
        $wp_options       = array( ANTISPAMWIZARD_Shadow_Display::CUTOFF_OPTION => 0 );
        $wp_actions       = array();
        $wp_filters       = array();
        $wp_comment_cache = array();
        $wp_is_admin      = false;
        $wp_is_feed       = false;
        $_GET             = array();
        $_POST            = array();
        $wpdb->reset_tables();
        antispamwizard_test_reset_comment_query_stubs();
        self::reset_registry();

        $wp_comments = array(
            1 => array( 'comment_ID' => 1, 'comment_post_ID' => 7, 'comment_approved' => '1', 'comment_author_email' => 'other@example.com' ),
            2 => array( 'comment_ID' => 2, 'comment_post_ID' => 7, 'comment_approved' => 'spam', 'comment_author_email' => 'me@example.com' ),
            3 => array( 'comment_ID' => 3, 'comment_post_ID' => 7, 'comment_approved' => 'spam', 'comment_author_email' => 'me@example.com', 'user_id' => 42 ),
            4 => array( 'comment_ID' => 4, 'comment_post_ID' => 7, 'comment_approved' => '0', 'comment_author_email' => 'me@example.com' ),
        );
        $wp_comment_meta = array(
            2 => array( '_antispamwizard_reason' => 'links' ),
            3 => array( '_antispamwizard_reason' => 'links' ),
        );
    }

    protected function tearDown(): void {
        global $wp_options, $wp_actions, $wp_filters, $wp_is_admin, $wp_is_feed;
        $wp_options  = array();
        $wp_actions  = array();
        $wp_filters  = array();
        $wp_is_admin = false;
        $wp_is_feed  = false;
        $_GET        = array();
        $_POST       = array();
        antispamwizard_test_reset_comment_query_stubs();
        self::reset_registry();
        parent::tearDown();
    }

    // ------------------------------------------------------------- Helpers.

    /** Empty ANTISPAMWIZARD_Shadow_Display's static registry of shadow-displayed IDs. */
    private static function reset_registry(): void {
        ( new ReflectionProperty( ANTISPAMWIZARD_Shadow_Display::class, 'shadowed' ) )->setValue( null, array() );
    }

    private function register(): ANTISPAMWIZARD_Shadow_Display {
        $shadow = new ANTISPAMWIZARD_Shadow_Display();
        $shadow->register_hooks();
        return $shadow;
    }

    /**
     * Start a new request against a warm comment-queries cache: no hooks, an
     * empty registry, a fresh instance.
     */
    private function new_request(): ANTISPAMWIZARD_Shadow_Display {
        $GLOBALS['wp_actions'] = array();
        $GLOBALS['wp_filters'] = array();
        self::reset_registry();
        return $this->register();
    }

    /** Core's comments_template() / block query vars for one visitor identifier. */
    private function front_end_args( $identifier ): array {
        return array(
            'post_id'            => 7,
            'status'             => 'approve',
            'include_unapproved' => array( $identifier ),
        );
    }

    private function as_cookie_author( string $email ): void {
        $GLOBALS['wp_current_commenter'] = array( 'comment_author_email' => $email );
    }

    /**
     * Land on the link core redirects a held comment's author to: date the
     * comment and put its unapproved / moderation-hash pair into $_GET.
     *
     * @param int         $comment_id  The comment the pair names.
     * @param int         $age_seconds How long ago the comment was posted.
     * @param string|null $hash        The hash to send; the valid one when null.
     */
    private function land_with_moderation_pair( int $comment_id, int $age_seconds = 60, ?string $hash = null ): void {
        $date_gmt = gmdate( 'Y-m-d H:i:s', time() - $age_seconds );

        $GLOBALS['wp_comments'][ $comment_id ]['comment_date_gmt'] = $date_gmt;
        $_GET['unapproved']      = (string) $comment_id;
        $_GET['moderation-hash'] = $hash ?? wp_hash( $date_gmt );
    }

    /**
     * Run one query with an empty result cache and report what it did.
     *
     * @return array{where: ?string, key: string, prepared: string[], queries: int, ids: mixed, query: WP_Comment_Query}
     */
    private function run_query( array $args, ?WP_Comment_Query $query = null ): array {
        global $wpdb;
        $GLOBALS['wp_comment_query_cache'] = array();
        $log_before      = count( $GLOBALS['wp_comment_query_log'] );
        $prepared_before = count( $wpdb->prepared_queries );
        $queries_before  = count( $wpdb->queries );

        $query  = $query ?? new WP_Comment_Query();
        $result = $query->query( $args );

        $log  = $GLOBALS['wp_comment_query_log'];
        $keys = $GLOBALS['wp_comment_query_cache_keys'];
        return array(
            'where'    => count( $log ) > $log_before ? end( $log ) : null,
            'key'      => (string) end( $keys ),
            'prepared' => array_slice( $wpdb->prepared_queries, $prepared_before ),
            'queries'  => count( $wpdb->queries ) - $queries_before,
            'ids'      => is_array( $result )
                ? array_map(
                    static function ( $row ) {
                        return is_object( $row ) ? (int) $row->comment_ID : (int) $row;
                    },
                    $result
                )
                : $result,
            'query'    => $query,
        );
    }

    /**
     * Run the query once without the plugin's hooks, then with them, and
     * assert the plugin changed nothing: same WHERE, same cache key, same
     * $wpdb traffic, same result.
     */
    private function assert_untouched( array $args ): void {
        list( $core, $hooked ) = $this->assert_where_untouched( $args );

        $this->assertSame( $core['key'], $hooked['key'], 'cache key must be unchanged' );
        $this->assertArrayNotHasKey( ANTISPAMWIZARD_Shadow_Display::QUERY_VAR, $hooked['query']->query_var_defaults );
        $this->assertArrayNotHasKey( ANTISPAMWIZARD_Shadow_Display::QUERY_VAR, $hooked['query']->query_vars );
    }

    /**
     * The WHERE half of assert_untouched(), for a query that is eligible (so
     * its cache key does carry the marker) but must still not be widened.
     *
     * @return array{0: array, 1: array} The unhooked and the hooked run.
     */
    private function assert_where_untouched( array $args ): array {
        $core   = $this->run_query( $args );
        $this->register();
        $hooked = $this->run_query( $args );

        $this->assertNotNull( $core['where'], 'the query missed the cache and built its clauses' );
        $this->assertSame( $core['where'], $hooked['where'], 'WHERE must be byte-identical' );
        $this->assertSame( $core['prepared'], $hooked['prepared'], 'no extra $wpdb->prepare()' );
        $this->assertSame( $core['queries'], $hooked['queries'], 'no extra $wpdb query' );
        $this->assertSame( $core['ids'], $hooked['ids'] );
        return array( $core, $hooked );
    }

    // ------------------------------------------------------------- Hooks.

    public function test_register_hooks_adds_the_query_hooks_and_the_transition_hook(): void {
        global $wp_actions, $wp_filters;
        $shadow = $this->register();

        $this->assertSame( array( $shadow, 'on_pre_get_comments' ), $wp_actions['pre_get_comments'][0]['callback'] );
        $this->assertSame( 1, $wp_actions['pre_get_comments'][0]['accepted_args'] );
        $this->assertSame( array( $shadow, 'on_comments_clauses' ), $wp_filters['comments_clauses'][0]['callback'] );
        $this->assertSame( 2, $wp_filters['comments_clauses'][0]['accepted_args'] );
        $this->assertSame( array( $shadow, 'on_the_comments' ), $wp_filters['the_comments'][0]['callback'] );
        $this->assertSame( 2, $wp_filters['the_comments'][0]['accepted_args'] );
        $this->assertSame( array( $shadow, 'on_transition_comment_status' ), $wp_actions['transition_comment_status'][0]['callback'] );
        $this->assertSame( 3, $wp_actions['transition_comment_status'][0]['accepted_args'] );
        $this->assertSame( array( $shadow, 'on_comment_post_redirect' ), $wp_filters['comment_post_redirect'][0]['callback'] );
        $this->assertSame( 100, $wp_filters['comment_post_redirect'][0]['priority'] );
        $this->assertSame( 2, $wp_filters['comment_post_redirect'][0]['accepted_args'] );
        $this->assertArrayNotHasKey( 'get_comment', $wp_filters, 'the get_comment filter is added lazily' );
    }

    // -------------------------------------------------------- Widening.

    public function test_cookie_author_gets_the_widened_approved_clause(): void {
        $this->as_cookie_author( 'me@example.com' );
        $this->register();

        $run = $this->run_query( $this->front_end_args( 'me@example.com' ) );

        $this->assertSame(
            '( ' . self::CORE_EMAIL_APPROVED . ' OR ' . self::EMAIL_SHADOW . ' ) AND comment_post_ID = 7',
            $run['where']
        );
        $this->assertSame( '( ' . self::CORE_EMAIL_APPROVED . ' OR ' . self::EMAIL_SHADOW . ' )', antispamwizard_test_get_sql_clauses( $run['query'] )['where']['approved'], 'written back' );
        $this->assertContains( self::EMAIL_SHADOW, $run['prepared'], 'the email is bound through $wpdb->prepare()' );
        $this->assertSame( 0, $run['queries'], 'no extra query: the branch is part of the one SELECT' );
        $this->assertSame( array( 1, 2, 3, 4 ), $run['ids'] );
    }

    public function test_logged_in_author_gets_the_user_id_variant(): void {
        $GLOBALS['wp_current_user_id'] = 42;
        // A commenter cookie left over from a logged-out comment is ignored:
        // core keys a logged-in visitor's listing on the user ID.
        $this->as_cookie_author( 'me@example.com' );
        $this->register();

        $run = $this->run_query( $this->front_end_args( 42 ) );

        $core = "( ( comment_approved = '1' ) OR ( user_id = 42 AND comment_approved = '0' ) )";
        $this->assertSame( '( ' . $core . ' OR ' . self::USER_SHADOW . ' ) AND comment_post_ID = 7', $run['where'] );
        $this->assertStringNotContainsString( 'comment_author_email', self::USER_SHADOW );
        $this->assertSame( array( 1, 3 ), $run['ids'] );
        $this->assertSame( 'user:42', $run['query']->query_vars[ ANTISPAMWIZARD_Shadow_Display::QUERY_VAR ] );
    }

    public function test_include_unapproved_as_a_comma_string_is_understood(): void {
        $GLOBALS['wp_current_user_id'] = 42;
        $this->register();

        $args                       = $this->front_end_args( 42 );
        $args['include_unapproved'] = '42';
        $run                        = $this->run_query( $args );

        $this->assertStringContainsString( self::USER_SHADOW, (string) $run['where'] );
    }

    // ---------------------------------------- Cookie-less author: the pair.

    public function test_a_valid_moderation_pair_without_a_cookie_gets_the_widened_clause_and_the_held_row(): void {
        // The author left the cookie-consent box unticked: core's
        // include_unapproved entry comes from the pair alone.
        $this->land_with_moderation_pair( 2 );
        $this->register();

        $run = $this->run_query( $this->front_end_args( 'me@example.com' ) );

        $this->assertSame(
            '( ' . self::CORE_EMAIL_APPROVED . ' OR ' . self::EMAIL_SHADOW . ' ) AND comment_post_ID = 7',
            $run['where']
        );
        $this->assertSame( 'email:me@example.com', $run['query']->query_vars[ ANTISPAMWIZARD_Shadow_Display::QUERY_VAR ] );
        $this->assertSame( array( 1, 2, 3, 4 ), $run['ids'] );
        $this->assertSame( '0', get_comment( 2 )->comment_approved );
        $this->assertSame( 'spam', $GLOBALS['wp_comments'][2]['comment_approved'], 'the stored row keeps spam' );
        $this->assertTrue( ANTISPAMWIZARD_Shadow_Display::is_shadowed( 2 ) );
    }

    public function test_a_wrong_moderation_hash_is_untouched(): void {
        $this->land_with_moderation_pair( 2, 60, str_repeat( '0', 32 ) );
        $this->assert_untouched( $this->front_end_args( 'me@example.com' ) );
    }

    public function test_an_expired_moderation_pair_is_untouched(): void {
        // A valid hash, eleven minutes after posting: only the window rejects it.
        $this->land_with_moderation_pair( 2, 11 * MINUTE_IN_SECONDS );
        $this->assert_untouched( $this->front_end_args( 'me@example.com' ) );
    }

    public function test_a_moderation_pair_naming_another_authors_comment_is_untouched(): void {
        $this->seed_non_shadow_spam();
        // Comment 9 is you@'s: the pair resolves you@, the listing carries me@.
        $this->land_with_moderation_pair( 9 );
        $this->assert_untouched( $this->front_end_args( 'me@example.com' ) );
    }

    public function test_a_moderation_pair_wins_over_a_different_cookie_email(): void {
        // Core resolves the pair first and falls back to the cookie only
        // without one, so the listing core builds carries the pair's email.
        $this->as_cookie_author( 'you@example.com' );
        $this->land_with_moderation_pair( 2 );
        $this->register();

        $run = $this->run_query( $this->front_end_args( 'me@example.com' ) );

        $this->assertStringContainsString( self::EMAIL_SHADOW, (string) $run['where'] );
        $this->assertSame( 'email:me@example.com', $run['query']->query_vars[ ANTISPAMWIZARD_Shadow_Display::QUERY_VAR ] );
    }

    // ------------------------------------------------- Inert: who is asking.

    public function test_anonymous_visitor_is_untouched(): void {
        $this->assert_untouched(
            array(
                'post_id' => 7,
                'status'  => 'approve',
            )
        );
    }

    public function test_anonymous_visitor_returns_before_any_option_read(): void {
        $spy                   = new ANTISPAMWIZARD_Option_Store_Spy();
        $GLOBALS['wp_options'] = $spy;
        $this->register();

        $this->run_query(
            array(
                'post_id'            => 7,
                'status'             => 'approve',
                'include_unapproved' => array(),
            )
        );

        $this->assertSame( 0, $spy->reads );

        // Control: an eligible query does read the settings.
        $this->as_cookie_author( 'me@example.com' );
        $this->run_query( $this->front_end_args( 'me@example.com' ) );
        $this->assertGreaterThan( 0, $spy->reads );
    }

    public function test_a_different_visitor_is_untouched(): void {
        // The listing carries me@'s identifier, but the cookie says someone else.
        $this->as_cookie_author( 'you@example.com' );
        $this->assert_untouched( $this->front_end_args( 'me@example.com' ) );
    }

    public function test_an_empty_commenter_email_is_untouched(): void {
        $this->as_cookie_author( '' );
        $this->assert_untouched( $this->front_end_args( 'me@example.com' ) );
    }

    public function test_an_invalid_commenter_email_is_untouched(): void {
        $this->as_cookie_author( 'not-an-email' );
        $this->assert_untouched( $this->front_end_args( 'not-an-email' ) );
    }

    public function test_a_logged_in_visitor_whose_id_is_not_listed_is_untouched(): void {
        $GLOBALS['wp_current_user_id'] = 42;
        $this->as_cookie_author( 'me@example.com' );
        $this->assert_untouched( $this->front_end_args( 'me@example.com' ) );
    }

    // ------------------------------------------------ Inert: request context.

    public function test_a_feed_is_untouched(): void {
        $GLOBALS['wp_is_feed'] = true;
        $this->as_cookie_author( 'me@example.com' );
        $this->assert_untouched( $this->front_end_args( 'me@example.com' ) );
    }

    public function test_an_admin_screen_is_untouched(): void {
        $GLOBALS['wp_is_admin'] = true;
        $this->as_cookie_author( 'me@example.com' );
        $this->assert_untouched( $this->front_end_args( 'me@example.com' ) );
    }

    public function test_an_ajax_request_is_untouched(): void {
        $GLOBALS['wp_doing_ajax'] = true;
        $this->as_cookie_author( 'me@example.com' );
        $this->assert_untouched( $this->front_end_args( 'me@example.com' ) );
    }

    public function test_a_cron_run_is_untouched(): void {
        $GLOBALS['wp_doing_cron'] = true;
        $this->as_cookie_author( 'me@example.com' );
        $this->assert_untouched( $this->front_end_args( 'me@example.com' ) );
    }

    public function test_a_rest_request_is_untouched(): void {
        $rest = $this->where_in_child_process( true );
        $this->assertSame( self::CORE_EMAIL_APPROVED . ' AND comment_post_ID = 7', $rest );

        // The control: the same child without REST_REQUEST is widened, so a
        // child that merely failed to boot cannot make this pass vacuously.
        $this->assertSame(
            '( ' . self::CORE_EMAIL_APPROVED . ' OR ' . self::EMAIL_SHADOW . ' ) AND comment_post_ID = 7',
            $this->where_in_child_process( false )
        );
    }

    /**
     * Run the author's query in a child process, optionally under REST_REQUEST,
     * and return its final WHERE.
     */
    private function where_in_child_process( bool $rest ): string {
        $bootstrap = __DIR__ . '/bootstrap.php';
        $script    = "<?php\n"
            . ( $rest ? "define( 'REST_REQUEST', true );\n" : '' )
            . 'require ' . var_export( $bootstrap, true ) . ";\n"
            . "\$GLOBALS['wp_is_admin']          = false;\n"
            . "\$GLOBALS['wp_is_feed']           = false;\n"
            . "\$GLOBALS['wp_current_commenter'] = array( 'comment_author_email' => 'me@example.com' );\n"
            . "\$GLOBALS['wp_options']           = array( ANTISPAMWIZARD_Shadow_Display::CUTOFF_OPTION => 0 );\n"
            . "( new ANTISPAMWIZARD_Shadow_Display() )->register_hooks();\n"
            . "( new WP_Comment_Query() )->query( array( 'post_id' => 7, 'status' => 'approve', 'include_unapproved' => array( 'me@example.com' ) ) );\n"
            . "echo end( \$GLOBALS['wp_comment_query_log'] );\n";

        $file = tempnam( sys_get_temp_dir(), 'antispamwizard-rest-' );
        file_put_contents( $file, $script );

        try {
            return (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ' 2>&1' );
        } finally {
            unlink( $file );
        }
    }

    // --------------------------------------------------- Inert: query shape.

    public function test_a_count_query_is_untouched(): void {
        $this->as_cookie_author( 'me@example.com' );
        $this->assert_untouched( $this->front_end_args( 'me@example.com' ) + array( 'count' => true ) );
    }

    public function test_an_ids_query_is_untouched(): void {
        $this->as_cookie_author( 'me@example.com' );
        $this->assert_untouched( $this->front_end_args( 'me@example.com' ) + array( 'fields' => 'ids' ) );
    }

    public function test_a_status_other_than_approve_is_untouched(): void {
        $this->as_cookie_author( 'me@example.com' );
        foreach ( array( 'all', 'hold', 'spam', 'any' ) as $status ) {
            $GLOBALS['wp_actions'] = array();
            $GLOBALS['wp_filters'] = array();
            $args                  = $this->front_end_args( 'me@example.com' );
            $args['status']        = $status;
            $this->assert_untouched( $args );
        }
    }

    public function test_a_query_without_post_id_is_untouched(): void {
        $this->as_cookie_author( 'me@example.com' );
        $args = $this->front_end_args( 'me@example.com' );
        unset( $args['post_id'] );
        $this->assert_untouched( $args );
    }

    // ------------------------------------------------------ Inert: settings.

    public function test_shadow_display_setting_off_is_untouched(): void {
        $GLOBALS['wp_options']['antispamwizard_settings'] = array( 'shadow_display_enabled' => false );
        $this->as_cookie_author( 'me@example.com' );
        $this->assert_untouched( $this->front_end_args( 'me@example.com' ) );
    }

    public function test_master_switch_off_is_untouched(): void {
        $GLOBALS['wp_options']['antispamwizard_settings'] = array( 'enabled' => false );
        $this->as_cookie_author( 'me@example.com' );
        $this->assert_untouched( $this->front_end_args( 'me@example.com' ) );
    }

    // ----------------------------------------------------------- Cache key.

    public function test_cache_key_differs_from_the_same_query_with_the_setting_off(): void {
        $this->as_cookie_author( 'me@example.com' );
        $this->register();

        $widened = $this->run_query( $this->front_end_args( 'me@example.com' ) );
        $GLOBALS['wp_options']['antispamwizard_settings'] = array( 'shadow_display_enabled' => false );
        $plain = $this->run_query( $this->front_end_args( 'me@example.com' ) );

        $this->assertStringContainsString( 'spam', (string) $widened['where'] );
        $this->assertStringNotContainsString( 'spam', (string) $plain['where'] );
        $this->assertNotSame( $widened['key'], $plain['key'] );
        $this->assertSame( '', $widened['query']->query_var_defaults[ ANTISPAMWIZARD_Shadow_Display::QUERY_VAR ] );
        $this->assertSame( 'email:me@example.com', $widened['query']->query_vars[ ANTISPAMWIZARD_Shadow_Display::QUERY_VAR ] );
    }

    public function test_a_cached_widened_result_is_never_served_to_the_plain_query(): void {
        // Without the cache-key marker, the plain query would hit the widened
        // query's cache entry and return the spam row.
        $this->as_cookie_author( 'me@example.com' );
        $this->register();
        $GLOBALS['wp_comment_query_cache'] = array();

        $widened = ( new WP_Comment_Query() )->query( $this->front_end_args( 'me@example.com' ) );
        $GLOBALS['wp_options']['antispamwizard_settings'] = array( 'shadow_display_enabled' => false );
        $plain = ( new WP_Comment_Query() )->query( $this->front_end_args( 'me@example.com' ) );

        $this->assertSame( array( 1, 2, 3, 4 ), array_map( 'intval', wp_list_pluck_stub( $widened, 'comment_ID' ) ) );
        $this->assertSame( array( 1, 4 ), array_map( 'intval', wp_list_pluck_stub( $plain, 'comment_ID' ) ) );
    }

    public function test_cache_key_differs_between_two_visitors(): void {
        $this->register();

        $this->as_cookie_author( 'me@example.com' );
        $me = $this->run_query( $this->front_end_args( 'me@example.com' ) );

        $this->as_cookie_author( 'you@example.com' );
        $you = $this->run_query( $this->front_end_args( 'you@example.com' ) );

        // Same query vars, different visitor: me@ is widened, you@ (whose
        // cookie does not match the listing) is not.
        $this->as_cookie_author( 'you@example.com' );
        $other = $this->run_query( $this->front_end_args( 'me@example.com' ) );

        $this->assertNotSame( $me['key'], $you['key'] );
        $this->assertNotSame( $me['key'], $other['key'] );
        $this->assertStringNotContainsString( 'spam', (string) $other['where'] );
    }

    // ------------------------------------------- An incoming antispamwizard_shadow arg.

    public function test_an_incoming_antispamwizard_shadow_arg_is_unset_for_an_ineligible_query(): void {
        $args = array(
            'post_id'                      => 7,
            'status'                       => 'approve',
            ANTISPAMWIZARD_Shadow_Display::QUERY_VAR => 'email:me@example.com',
        );
        $this->assert_untouched( $args );
    }

    public function test_an_incoming_antispamwizard_shadow_arg_is_overwritten_for_an_eligible_query(): void {
        $this->as_cookie_author( 'me@example.com' );
        $this->register();

        $args                                   = $this->front_end_args( 'me@example.com' );
        $args[ ANTISPAMWIZARD_Shadow_Display::QUERY_VAR ] = 'email:victim@example.com';
        $run                                    = $this->run_query( $args );

        $this->assertSame( 'email:me@example.com', $run['query']->query_vars[ ANTISPAMWIZARD_Shadow_Display::QUERY_VAR ] );
        $this->assertStringNotContainsString( 'victim', (string) $run['where'] );
        $this->assertStringContainsString( self::EMAIL_SHADOW, (string) $run['where'] );
    }

    public function test_a_reused_query_object_does_not_carry_an_earlier_approval(): void {
        $this->register();
        $query = new WP_Comment_Query();

        $this->as_cookie_author( 'me@example.com' );
        $first = $this->run_query( $this->front_end_args( 'me@example.com' ), $query );
        $this->assertStringContainsString( 'spam', (string) $first['where'] );

        $this->as_cookie_author( 'you@example.com' );
        $second = $this->run_query( $this->front_end_args( 'me@example.com' ), $query );

        $this->assertSame( self::CORE_EMAIL_APPROVED . ' AND comment_post_ID = 7', $second['where'] );
        $this->assertArrayNotHasKey( ANTISPAMWIZARD_Shadow_Display::QUERY_VAR, $query->query_var_defaults );
        $this->assertArrayNotHasKey( ANTISPAMWIZARD_Shadow_Display::QUERY_VAR, $query->query_vars );
    }

    public function test_comments_clauses_ignores_a_query_pre_get_comments_never_approved(): void {
        // A query object that arrives at comments_clauses already carrying the
        // marker -- but was never approved by this instance -- is not widened.
        $this->as_cookie_author( 'me@example.com' );
        $shadow = new ANTISPAMWIZARD_Shadow_Display();
        add_filter( 'comments_clauses', array( $shadow, 'on_comments_clauses' ), 10, 2 );

        $args                                   = $this->front_end_args( 'me@example.com' );
        $args[ ANTISPAMWIZARD_Shadow_Display::QUERY_VAR ] = 'email:me@example.com';
        $run                                    = $this->run_query( $args );

        $this->assertSame( self::CORE_EMAIL_APPROVED . ' AND comment_post_ID = 7', $run['where'] );
    }

    // ------------------------------------------- The exactly-once replacement.

    public function test_an_absent_approved_clause_leaves_the_where_untouched(): void {
        add_filter(
            'comments_clauses',
            static function ( $clauses, $query ) {
                antispamwizard_test_set_approved_clause( $query, null );
                $clauses['where'] = 'comment_post_ID = 7';
                return $clauses;
            },
            10,
            2
        );
        $this->as_cookie_author( 'me@example.com' );

        $this->assert_where_untouched( $this->front_end_args( 'me@example.com' ) );
    }

    public function test_an_approved_clause_that_occurs_twice_leaves_the_where_untouched(): void {
        add_filter(
            'comments_clauses',
            static function ( $clauses, $query ) {
                $clauses['where'] .= ' AND ' . antispamwizard_test_get_sql_clauses( $query )['where']['approved'];
                return $clauses;
            },
            10,
            2
        );
        $this->as_cookie_author( 'me@example.com' );

        $core = $this->run_query( $this->front_end_args( 'me@example.com' ) );
        $this->assertSame( 2, substr_count( (string) $core['where'], self::CORE_EMAIL_APPROVED ), 'the fixture filter really duplicated it' );

        $this->assert_where_untouched( $this->front_end_args( 'me@example.com' ) );
    }

    // --------------------------------------------- Presentation as status '0'.

    /** Status of each returned comment, keyed by ID, as rendering sees it. */
    private static function statuses( array $comments ): array {
        $statuses = array();
        foreach ( $comments as $comment ) {
            $statuses[ (int) $comment->comment_ID ] = $comment->comment_approved;
        }
        return $statuses;
    }

    /** Add the moderation-path and pingback fixtures next to setUp()'s rows. */
    private function seed_non_shadow_spam(): void {
        global $wp_comments, $wp_comment_meta;
        // Marked spam by a moderator: no _antispamwizard_reason.
        $wp_comments[6] = array( 'comment_ID' => 6, 'comment_post_ID' => 7, 'comment_approved' => 'spam', 'comment_author_email' => 'me@example.com' );
        // A pingback the plugin flagged: carries _antispamwizard_reason, wrong type.
        $wp_comments[8]     = array( 'comment_ID' => 8, 'comment_post_ID' => 7, 'comment_approved' => 'spam', 'comment_author_email' => 'me@example.com', 'comment_type' => 'pingback' );
        $wp_comment_meta[8] = array( '_antispamwizard_reason' => 'links' );
        // Another visitor's auto-flagged comment.
        $wp_comments[9]     = array( 'comment_ID' => 9, 'comment_post_ID' => 7, 'comment_approved' => 'spam', 'comment_author_email' => 'you@example.com' );
        $wp_comment_meta[9] = array( '_antispamwizard_reason' => 'links' );
    }

    public function test_author_sees_their_flagged_comment_as_awaiting_moderation_in_thread_order(): void {
        $this->as_cookie_author( 'me@example.com' );
        $this->register();

        $comments = ( new WP_Comment_Query() )->query( $this->front_end_args( 'me@example.com' ) );

        $this->assertSame(
            array(
                1 => '1',
                2 => '0',
                3 => '0',
                4 => '0',
            ),
            self::statuses( $comments )
        );
    }

    public function test_get_comment_after_the_query_presents_the_shadow_row_as_held(): void {
        // The block theme's core/comment-content re-fetches every comment by ID
        // and prints the moderation note only for '0'.
        $this->as_cookie_author( 'me@example.com' );
        $this->register();

        ( new WP_Comment_Query() )->query( $this->front_end_args( 'me@example.com' ) );

        $this->assertSame( '0', get_comment( 2 )->comment_approved );
        $this->assertSame( '0', get_comment( 3 )->comment_approved );
    }

    public function test_a_threaded_query_presents_a_shadow_reply_as_held(): void {
        global $wp_comments, $wp_comment_meta;
        $wp_comments[5]     = array( 'comment_ID' => 5, 'comment_post_ID' => 7, 'comment_parent' => 1, 'comment_approved' => 'spam', 'comment_author_email' => 'me@example.com' );
        $wp_comment_meta[5] = array( '_antispamwizard_reason' => 'links' );
        $this->as_cookie_author( 'me@example.com' );
        $this->register();

        $args                 = $this->front_end_args( 'me@example.com' );
        $args['hierarchical'] = 'threaded';
        $comments             = ( new WP_Comment_Query() )->query( $args );

        $statuses = self::statuses( $comments );
        $this->assertArrayHasKey( 5, $statuses, 'the descendant pass found the reply' );
        $this->assertSame( '0', $statuses[5] );
        $this->assertSame( '1', $statuses[1] );
        $this->assertTrue( ANTISPAMWIZARD_Shadow_Display::is_shadowed( 5 ) );
    }

    public function test_moderator_marked_spam_and_a_flagged_pingback_are_not_shown(): void {
        $this->seed_non_shadow_spam();
        $this->as_cookie_author( 'me@example.com' );
        $this->register();

        $statuses = self::statuses( ( new WP_Comment_Query() )->query( $this->front_end_args( 'me@example.com' ) ) );

        $this->assertArrayHasKey( 2, $statuses, 'control: the auto-flagged comment is shown' );
        $this->assertArrayNotHasKey( 6, $statuses, 'moderator-marked spam has no _antispamwizard_reason' );
        $this->assertArrayNotHasKey( 8, $statuses, 'a pingback is never shadow-displayed' );
        $this->assertArrayNotHasKey( 9, $statuses, "another visitor's flagged comment" );
        $this->assertFalse( ANTISPAMWIZARD_Shadow_Display::is_shadowed( 6 ) );
        $this->assertFalse( ANTISPAMWIZARD_Shadow_Display::is_shadowed( 8 ) );
    }

    public function test_a_flag_written_before_the_cutoff_is_never_shown(): void {
        // Versions before shadow display never dropped _antispamwizard_reason when a
        // moderator decided, so a flag at or below the cutoff may be one a
        // moderator already confirmed as spam.
        global $wp_comments, $wp_comment_meta;
        $wp_comments[5]     = array( 'comment_ID' => 5, 'comment_post_ID' => 7, 'comment_parent' => 1, 'comment_approved' => 'spam', 'comment_author_email' => 'me@example.com' );
        $wp_comment_meta[5] = array( '_antispamwizard_reason' => 'links' );
        $GLOBALS['wp_options'][ ANTISPAMWIZARD_Shadow_Display::CUTOFF_OPTION ] = 2;
        $this->as_cookie_author( 'me@example.com' );
        $this->register();

        $statuses = self::statuses( ( new WP_Comment_Query() )->query( $this->front_end_args( 'me@example.com' ) ) );
        $this->assertArrayNotHasKey( 2, $statuses, 'flagged at the cutoff' );
        $this->assertSame( '0', $statuses[3], 'control: flagged above the cutoff' );
        $this->assertFalse( ANTISPAMWIZARD_Shadow_Display::is_shadowed( 2 ) );

        // The threaded get_comment-side recognition applies the same cutoff.
        $args                 = $this->front_end_args( 'me@example.com' );
        $args['hierarchical'] = 'threaded';
        $this->run_query( $args );
        self::reset_registry();
        $this->assertSame( 'spam', get_comment( 2 )->comment_approved );
        $this->assertSame( '0', get_comment( 5 )->comment_approved, 'control: a reply above the cutoff' );
    }

    public function test_nothing_is_shown_until_the_plugin_has_flagged_a_comment(): void {
        unset( $GLOBALS['wp_options'][ ANTISPAMWIZARD_Shadow_Display::CUTOFF_OPTION ] );
        $this->as_cookie_author( 'me@example.com' );
        $this->assert_untouched( $this->front_end_args( 'me@example.com' ) );
    }

    public function test_record_flag_keeps_the_first_cutoff(): void {
        unset( $GLOBALS['wp_options'][ ANTISPAMWIZARD_Shadow_Display::CUTOFF_OPTION ] );

        ANTISPAMWIZARD_Shadow_Display::record_flag( 0 );
        $this->assertFalse( get_option( ANTISPAMWIZARD_Shadow_Display::CUTOFF_OPTION ) );

        ANTISPAMWIZARD_Shadow_Display::record_flag( 12 );
        ANTISPAMWIZARD_Shadow_Display::record_flag( 20 );
        $this->assertSame( 11, get_option( ANTISPAMWIZARD_Shadow_Display::CUTOFF_OPTION ) );
    }

    public function test_another_visitor_and_an_anonymous_visitor_get_no_shadow_row(): void {
        $this->seed_non_shadow_spam();
        $this->register();

        $this->as_cookie_author( 'other@example.com' );
        $other = self::statuses( ( new WP_Comment_Query() )->query( $this->front_end_args( 'other@example.com' ) ) );
        $this->assertSame( array( 1 => '1' ), $other );

        $this->as_cookie_author( '' );
        $anonymous = self::statuses(
            ( new WP_Comment_Query() )->query(
                array(
                    'post_id' => 7,
                    'status'  => 'approve',
                )
            )
        );
        $this->assertSame( array( 1 => '1' ), $anonymous );
        $this->assertArrayNotHasKey( 'get_comment', $GLOBALS['wp_filters'] );
    }

    public function test_the_stored_row_and_the_object_cache_keep_spam(): void {
        global $wp_comments, $wp_comment_cache;
        $wp_comment_cache[2] = $wp_comments[2];
        $this->as_cookie_author( 'me@example.com' );
        $this->register();

        $comments = ( new WP_Comment_Query() )->query( $this->front_end_args( 'me@example.com' ) );
        $this->assertSame( '0', self::statuses( $comments )[2], 'control: it was presented as held' );

        $this->assertSame( 'spam', $wp_comments[2]['comment_approved'] );
        $this->assertSame( 'spam', $wp_comment_cache[2]['comment_approved'] );
        $this->assertTrue( ANTISPAMWIZARD_Shadow_Display::is_shadowed( 2 ) );
        $this->assertFalse( ANTISPAMWIZARD_Shadow_Display::is_shadowed( 1 ) );
        $this->assertFalse( ANTISPAMWIZARD_Shadow_Display::is_shadowed( 4 ), 'a genuinely held comment is not a shadow row' );
    }

    public function test_an_unrelated_comment_passes_through_get_comment_unmodified(): void {
        $this->seed_non_shadow_spam();
        $this->as_cookie_author( 'me@example.com' );
        $this->register();
        ( new WP_Comment_Query() )->query( $this->front_end_args( 'me@example.com' ) );
        $this->assertArrayHasKey( 'get_comment', $GLOBALS['wp_filters'], 'control: the filter is live' );

        $this->assertSame( '1', get_comment( 1 )->comment_approved );
        $this->assertSame( 'spam', get_comment( 6 )->comment_approved );
        $this->assertSame( 'spam', get_comment( 9 )->comment_approved );

        $object = (object) array(
            'comment_ID'       => 1,
            'comment_approved' => '1',
        );
        $this->assertSame( $object, get_comment( $object ), 'the very same object, not a copy' );
    }

    public function test_the_presented_copy_is_a_clone_not_the_input(): void {
        $this->as_cookie_author( 'me@example.com' );
        $shadow = $this->register();
        ( new WP_Comment_Query() )->query( $this->front_end_args( 'me@example.com' ) );

        $input     = (object) array(
            'comment_ID'       => 2,
            'comment_approved' => 'spam',
        );
        $presented = $shadow->on_get_comment( $input );

        $this->assertNotSame( $input, $presented );
        $this->assertSame( '0', $presented->comment_approved );
        $this->assertSame( 'spam', $input->comment_approved );
    }

    public function test_with_the_setting_off_no_get_comment_filter_is_registered(): void {
        $GLOBALS['wp_options']['antispamwizard_settings'] = array( 'shadow_display_enabled' => false );
        $this->as_cookie_author( 'me@example.com' );
        $this->register();

        $comments = ( new WP_Comment_Query() )->query( $this->front_end_args( 'me@example.com' ) );

        $this->assertSame( array( 1 => '1', 4 => '0' ), self::statuses( $comments ) );
        $this->assertArrayNotHasKey( 'get_comment', $GLOBALS['wp_filters'] );
        $this->assertFalse( ANTISPAMWIZARD_Shadow_Display::is_shadowed( 2 ) );
    }

    public function test_an_eligible_query_with_no_shadow_row_registers_no_filter(): void {
        global $wp_comments;
        $wp_comments[2]['comment_approved'] = '1';
        $wp_comments[3]['comment_approved'] = '1';
        $this->as_cookie_author( 'me@example.com' );
        $this->register();

        $run = $this->run_query( $this->front_end_args( 'me@example.com' ) );

        $this->assertStringContainsString( self::EMAIL_SHADOW, (string) $run['where'], 'control: the query was widened' );
        $this->assertArrayNotHasKey( 'get_comment', $GLOBALS['wp_filters'] );
    }

    public function test_the_get_comment_filter_is_added_once(): void {
        $this->as_cookie_author( 'me@example.com' );
        $this->register();

        ( new WP_Comment_Query() )->query( $this->front_end_args( 'me@example.com' ) );
        ( new WP_Comment_Query() )->query( $this->front_end_args( 'me@example.com' ) );

        $this->assertCount( 1, $GLOBALS['wp_filters']['get_comment'] );
    }

    public function test_a_rejected_spam_listing_registers_and_presents_nothing(): void {
        // The hooks run on every request, admin included: the moderator's Spam
        // screen returns spam rows 2 and 3, and none of them may be presented
        // as held or answer is_shadowed().
        $this->as_cookie_author( 'me@example.com' );
        $this->register();
        foreach ( array( true, false ) as $admin ) {
            $GLOBALS['wp_is_admin'] = $admin;
            $args                   = $this->front_end_args( 'me@example.com' );
            $args['status']         = 'spam';

            $run = $this->run_query( $args );

            $this->assertSame( array( 2, 3, 4 ), $run['ids'], 'control: the spam rows came back' );
            $this->assertFalse( ANTISPAMWIZARD_Shadow_Display::is_shadowed( 2 ) );
            $this->assertFalse( ANTISPAMWIZARD_Shadow_Display::is_shadowed( 3 ) );
            $this->assertArrayNotHasKey( 'get_comment', $GLOBALS['wp_filters'] );
            $this->assertSame( 'spam', get_comment( 2 )->comment_approved );
        }
    }

    public function test_a_cached_widened_result_in_a_new_request_is_still_presented_as_held(): void {
        // Under an external object cache the author's next page view is a
        // result-cache hit that skips comments_clauses; the_comments still
        // has to register the rows.
        $this->as_cookie_author( 'me@example.com' );
        $this->register();
        $this->run_query( $this->front_end_args( 'me@example.com' ) );

        $this->new_request();
        $log_before = count( $GLOBALS['wp_comment_query_log'] );
        $comments   = ( new WP_Comment_Query() )->query( $this->front_end_args( 'me@example.com' ) );

        $this->assertCount( $log_before, $GLOBALS['wp_comment_query_log'], 'control: a cache hit, no clauses built' );
        $this->assertSame( '0', self::statuses( $comments )[2] );
        $this->assertSame( '0', self::statuses( $comments )[3] );
        $this->assertTrue( ANTISPAMWIZARD_Shadow_Display::is_shadowed( 2 ) );
    }

    public function test_a_shadow_reply_from_the_child_id_cache_is_still_presented_as_held(): void {
        // fill_descendants() serves a cached level's IDs without its nested
        // query, so on the author's next view the reply never passes through
        // the_comments -- here, with no flagged top-level comment, nothing
        // else would even add the get_comment filter.
        global $wp_comments, $wp_comment_meta;
        $wp_comments[2]['comment_approved'] = '1';
        $wp_comments[3]['comment_approved'] = '1';
        $wp_comments[5]                     = array( 'comment_ID' => 5, 'comment_post_ID' => 7, 'comment_parent' => 1, 'comment_approved' => 'spam', 'comment_author_email' => 'me@example.com' );
        $wp_comment_meta[5]                 = array( '_antispamwizard_reason' => 'links' );
        $this->as_cookie_author( 'me@example.com' );
        $this->register();
        $args                 = $this->front_end_args( 'me@example.com' );
        $args['hierarchical'] = 'threaded';
        $this->run_query( $args );

        $this->new_request();
        $log_before = count( $GLOBALS['wp_comment_query_log'] );
        $statuses   = self::statuses( ( new WP_Comment_Query() )->query( $args ) );

        $this->assertCount( $log_before, $GLOBALS['wp_comment_query_log'], 'control: neither the query nor its descendant level ran' );
        $this->assertArrayHasKey( 5, $statuses, 'the cached level still yields the reply' );
        $this->assertSame( '0', $statuses[5] );
        $this->assertTrue( ANTISPAMWIZARD_Shadow_Display::is_shadowed( 5 ) );
        $this->assertSame( '0', get_comment( 5 )->comment_approved );
    }

    public function test_a_threaded_viewer_is_presented_only_their_own_flagged_comments_on_that_post(): void {
        // The get_comment-side recognition mirrors the SQL branch: post,
        // identity, type and _antispamwizard_reason.
        global $wp_comments, $wp_comment_meta;
        $this->seed_non_shadow_spam();
        // Their own auto-flagged comment on another post.
        $wp_comments[10]     = array( 'comment_ID' => 10, 'comment_post_ID' => 8, 'comment_approved' => 'spam', 'comment_author_email' => 'me@example.com' );
        $wp_comment_meta[10] = array( '_antispamwizard_reason' => 'links' );
        // Their own auto-flagged comment, address in another case.
        $wp_comments[11]     = array( 'comment_ID' => 11, 'comment_post_ID' => 7, 'comment_parent' => 1, 'comment_approved' => 'spam', 'comment_author_email' => 'Me@Example.com' );
        $wp_comment_meta[11] = array( '_antispamwizard_reason' => 'links' );
        $this->as_cookie_author( 'me@example.com' );
        $this->register();
        $args                 = $this->front_end_args( 'me@example.com' );
        $args['hierarchical'] = 'threaded';
        $this->run_query( $args );
        self::reset_registry();

        $this->assertSame( 'spam', get_comment( 6 )->comment_approved, 'moderator-marked spam' );
        $this->assertSame( 'spam', get_comment( 8 )->comment_approved, 'a flagged pingback' );
        $this->assertSame( 'spam', get_comment( 9 )->comment_approved, "another visitor's flagged comment" );
        $this->assertSame( 'spam', get_comment( 10 )->comment_approved, 'another post' );
        $this->assertFalse( ANTISPAMWIZARD_Shadow_Display::is_shadowed( 6 ) );
        $this->assertSame( '0', get_comment( 11 )->comment_approved, 'control: their own, as the collation compares it' );
        $this->assertSame( '0', get_comment( 2 )->comment_approved, 'control: recognised without the registry' );
        $this->assertTrue( ANTISPAMWIZARD_Shadow_Display::is_shadowed( 2 ) );
    }

    public function test_a_shadow_row_whose_real_status_changed_is_no_longer_presented_as_held(): void {
        global $wp_comments;
        $this->as_cookie_author( 'me@example.com' );
        $this->register();
        ( new WP_Comment_Query() )->query( $this->front_end_args( 'me@example.com' ) );
        $this->assertSame( '0', get_comment( 2 )->comment_approved, 'control: presented as held' );

        $wp_comments[2]['comment_approved'] = '1';
        $wp_comments[3]['comment_approved'] = 'trash';

        $this->assertSame( '1', get_comment( 2 )->comment_approved );
        $this->assertSame( 'trash', get_comment( 3 )->comment_approved );
    }

    public function test_forget_verdict_reads_back_a_refused_delete_and_logs_it(): void {
        global $wp_comment_meta;

        $this->assertTrue( ANTISPAMWIZARD_Shadow_Display::forget_verdict( 2 ) );
        $this->assertArrayNotHasKey( '_antispamwizard_reason', $wp_comment_meta[2] );
        $this->assertTrue( ANTISPAMWIZARD_Shadow_Display::forget_verdict( 2 ), 'an already absent flag is the goal state' );

        $log      = tempnam( sys_get_temp_dir(), 'antispamwizard-shadow-log-' );
        $previous = ini_set( 'error_log', $log );
        $GLOBALS['wp_delete_comment_meta_fails'] = true;
        try {
            $forgotten = ANTISPAMWIZARD_Shadow_Display::forget_verdict( 3 );
            $written   = (string) file_get_contents( $log );
        } finally {
            $GLOBALS['wp_delete_comment_meta_fails'] = false;
            ini_set( 'error_log', false === $previous ? '' : $previous );
            unlink( $log );
        }

        $this->assertFalse( $forgotten );
        $this->assertArrayHasKey( '_antispamwizard_reason', $wp_comment_meta[3] );
        $this->assertStringContainsString( 'ANTISPAMWIZARD: shadow display flag could not be removed from comment 3', $written );
    }

    public function test_overruling_the_verdict_drops_the_flag_so_a_later_moderator_spam_stays_hidden(): void {
        global $wp_comment_meta;
        $this->register();

        // Not Spam (approved) on comment 2, then a moderator marks it spam again.
        wp_unspam_comment( 2 );
        $this->assertArrayNotHasKey( '_antispamwizard_reason', $wp_comment_meta[2] );
        wp_spam_comment( 2 );

        // Not Spam that leaves comment 3 held.
        antispamwizard_test_set_comment_status( 3, '0' );
        $this->assertArrayNotHasKey( '_antispamwizard_reason', $wp_comment_meta[3] );
        wp_spam_comment( 3 );

        $this->as_cookie_author( 'me@example.com' );
        $statuses = self::statuses( ( new WP_Comment_Query() )->query( $this->front_end_args( 'me@example.com' ) ) );
        $this->assertArrayNotHasKey( 2, $statuses, 'a moderator decision now' );
        $this->assertArrayNotHasKey( 3, $statuses );
    }

    public function test_a_flag_survives_every_transition_that_does_not_overrule_it(): void {
        global $wp_comments, $wp_comment_meta;
        $this->register();

        antispamwizard_test_set_comment_status( 2, 'trash' );
        $wp_comments[4]['comment_approved'] = '1';
        $wp_comment_meta[4]                 = array( '_antispamwizard_reason' => 'links' );
        wp_spam_comment( 4 );

        $this->assertSame( 'links', $wp_comment_meta[2]['_antispamwizard_reason'], 'spam → trash keeps the verdict' );
        $this->assertSame( 'links', $wp_comment_meta[4]['_antispamwizard_reason'], 'only leaving spam drops it' );
    }

    // ------------------------------------------- Redirect: the pair for a cookie-less author.

    private const POST_URL = 'http://example.com/post/#comment-2';

    /**
     * The comment wp-comments-post.php hands to comment_post_redirect: comment 2
     * (plugin-flagged spam by me@) posted a minute ago, with the overrides
     * applied to the stored row first.
     */
    private function posted( int $comment_id = 2, array $overrides = array() ): object {
        $GLOBALS['wp_comments'][ $comment_id ] = array_merge(
            $GLOBALS['wp_comments'][ $comment_id ],
            array( 'comment_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
            $overrides
        );
        return (object) $GLOBALS['wp_comments'][ $comment_id ];
    }

    /** Run the redirect filter the way wp-comments-post.php does. */
    private function redirect( object $comment, string $location = self::POST_URL ) {
        $this->register();
        return apply_filters( 'comment_post_redirect', $location, $comment );
    }

    private function assert_redirect_untouched( object $comment, string $location = self::POST_URL ): void {
        $this->assertSame( $location, $this->redirect( $comment, $location ) );
    }

    public function test_redirect_adds_the_moderation_pair_for_a_flagged_comment_posted_without_consent(): void {
        $comment = $this->posted();

        $location = $this->redirect( $comment );

        $this->assertSame(
            'http://example.com/post/?unapproved=2&moderation-hash=' . wp_hash( $comment->comment_date_gmt ) . '#comment-2',
            $location
        );
        parse_str( (string) parse_url( $location, PHP_URL_QUERY ), $query );
        $this->assertSame( '2', $query['unapproved'] );
        $this->assertSame( wp_hash( $comment->comment_date_gmt ), $query['moderation-hash'] );
        $this->assertSame( 'spam', $GLOBALS['wp_comments'][2]['comment_approved'], 'the row is never touched' );
        $this->assertSame( 'links', $GLOBALS['wp_comment_meta'][2]['_antispamwizard_reason'] );
    }

    public function test_redirect_ignores_a_held_comment(): void {
        // Core already added the pair for a '0' comment.
        $this->assert_redirect_untouched( $this->posted( 2, array( 'comment_approved' => '0' ) ) );
    }

    public function test_redirect_ignores_an_approved_comment(): void {
        $this->assert_redirect_untouched( $this->posted( 2, array( 'comment_approved' => '1' ) ) );
    }

    public function test_redirect_ignores_a_trashed_comment(): void {
        $this->assert_redirect_untouched( $this->posted( 2, array( 'comment_approved' => 'trash' ) ) );
    }

    public function test_redirect_ignores_spam_without_the_plugin_flag(): void {
        unset( $GLOBALS['wp_comment_meta'][2]['_antispamwizard_reason'] );
        $this->assert_redirect_untouched( $this->posted() );
    }

    public function test_redirect_ignores_a_flag_at_or_below_the_cutoff(): void {
        $GLOBALS['wp_options'][ ANTISPAMWIZARD_Shadow_Display::CUTOFF_OPTION ] = 2;
        $this->assert_redirect_untouched( $this->posted() );
    }

    public function test_redirect_ignores_everything_until_the_cutoff_is_recorded(): void {
        unset( $GLOBALS['wp_options'][ ANTISPAMWIZARD_Shadow_Display::CUTOFF_OPTION ] );
        $this->assert_redirect_untouched( $this->posted() );
    }

    public function test_redirect_ignores_a_comment_without_an_author_email(): void {
        $this->assert_redirect_untouched( $this->posted( 2, array( 'comment_author_email' => '' ) ) );
    }

    public function test_redirect_ignores_a_comment_with_an_invalid_author_email(): void {
        $this->assert_redirect_untouched( $this->posted( 2, array( 'comment_author_email' => 'not-an-email' ) ) );
    }

    public function test_redirect_ignores_a_logged_in_poster(): void {
        // A logged-in visitor's listing is keyed on the user ID, not the pair.
        $GLOBALS['wp_current_user_id'] = 42;
        $this->assert_redirect_untouched( $this->posted() );
    }

    public function test_redirect_ignores_a_comment_owned_by_a_user(): void {
        $this->assert_redirect_untouched( $this->posted( 3 ) );
    }

    public function test_redirect_ignores_a_poster_who_consented_to_cookies(): void {
        // The cookie already identifies them.
        $_POST['wp-comment-cookies-consent'] = 'yes';
        $this->assert_redirect_untouched( $this->posted() );
    }

    public function test_redirect_ignores_a_pingback(): void {
        $this->assert_redirect_untouched( $this->posted( 2, array( 'comment_type' => 'pingback' ) ) );
    }

    public function test_redirect_ignores_a_comment_with_the_setting_off(): void {
        $GLOBALS['wp_options']['antispamwizard_settings'] = array( 'shadow_display_enabled' => false );
        $this->assert_redirect_untouched( $this->posted() );
    }

    public function test_redirect_ignores_a_comment_with_the_master_switch_off(): void {
        $GLOBALS['wp_options']['antispamwizard_settings'] = array( 'enabled' => false );
        $this->assert_redirect_untouched( $this->posted() );
    }

    public function test_redirect_keeps_an_existing_query_argument_and_the_fragment_last(): void {
        $comment = $this->posted();

        $this->assertSame(
            'http://example.com/post/?p=7&unapproved=2&moderation-hash=' . wp_hash( $comment->comment_date_gmt ) . '#comment-2',
            $this->redirect( $comment, 'http://example.com/post/?p=7#comment-2' )
        );
    }

    public function test_redirect_leaves_a_location_that_already_carries_either_key_byte_identical(): void {
        $comment = $this->posted();

        $this->assert_redirect_untouched( $comment, 'http://example.com/post/?unapproved=5#comment-2' );
        $this->assert_redirect_untouched( $comment, 'http://example.com/post/?moderation-hash=abc#comment-2' );
    }

    public function test_redirect_returns_a_non_string_location_and_a_missing_comment_unchanged(): void {
        $this->register();
        $this->assertNull( apply_filters( 'comment_post_redirect', null, $this->posted() ) );
        $this->assertSame( '', apply_filters( 'comment_post_redirect', '', $this->posted() ) );
        $this->assertSame( self::POST_URL, apply_filters( 'comment_post_redirect', self::POST_URL, null ) );
    }

    public function test_a_throw_in_the_redirect_returns_the_location_and_logs_only_the_site(): void {
        $shadow = $this->register();

        $log      = tempnam( sys_get_temp_dir(), 'antispamwizard-shadow-log-' );
        $previous = ini_set( 'error_log', $log );
        try {
            $location = $shadow->on_comment_post_redirect( self::POST_URL, new ANTISPAMWIZARD_Throwing_Shadow_Input() );
            $written  = (string) file_get_contents( $log );
        } finally {
            ini_set( 'error_log', false === $previous ? '' : $previous );
            unlink( $log );
        }

        $this->assertSame( self::POST_URL, $location );
        // The throw site is the fixture's __isset(), named by basename only.
        $this->assertMatchesRegularExpression( '/ANTISPAMWIZARD: shadow display failed at ShadowDisplayTest\.php:\d+$/m', $written );
        $this->assertSame( 1, substr_count( $written, 'ANTISPAMWIZARD: shadow display failed at' ) );
        $this->assertStringNotContainsString( 'secret failure detail', $written );
        $this->assertStringNotContainsString( '/tests/', $written, 'never the full path' );
    }

    public function test_the_redirect_pair_reaches_the_landing_listing_without_a_cookie(): void {
        // 1. The redirect after posting, consent box unticked.
        $comment  = $this->posted();
        $location = $this->redirect( $comment, 'http://example.com/post/#comment-2' );
        $this->assertStringContainsString( 'moderation-hash=', $location );

        // 2. The landing request: only the redirect's query, no cookie.
        $this->new_request();
        $GLOBALS['wp_current_commenter'] = array( 'comment_author_email' => '' );
        parse_str( (string) parse_url( $location, PHP_URL_QUERY ), $_GET );

        // 3. include_unapproved as comments_template() builds it.
        $email = wp_get_unapproved_comment_author_email();
        $this->assertSame( 'me@example.com', $email, 'core resolves the email from the pair' );
        $args = array(
            'post_id'            => 7,
            'status'             => 'approve',
            'include_unapproved' => array( $email ),
        );

        // 4. The front-end query.
        $run = $this->run_query( $args );

        $this->assertSame(
            '( ' . self::CORE_EMAIL_APPROVED . ' OR ' . self::EMAIL_SHADOW . ' ) AND comment_post_ID = 7',
            $run['where']
        );
        $this->assertContains( 2, $run['ids'] );
        $this->assertSame( '0', get_comment( 2 )->comment_approved );
        $this->assertSame( 'spam', $GLOBALS['wp_comments'][2]['comment_approved'], 'the stored row keeps spam' );
    }

    // ------------------------------------------------------------- Fail-open.

    public function test_a_throw_inside_the_hooks_leaves_the_core_result_and_logs_only_the_site(): void {
        $GLOBALS['wp_current_commenter_throws'] = true;
        $args                                   = $this->front_end_args( 'me@example.com' );

        $GLOBALS['wp_comment_query_cache'] = array();
        $core                              = self::statuses( ( new WP_Comment_Query() )->query( $args ) );

        $this->register();
        $log      = tempnam( sys_get_temp_dir(), 'antispamwizard-shadow-log-' );
        $previous = ini_set( 'error_log', $log );
        try {
            $GLOBALS['wp_comment_query_cache'] = array();
            $query                             = new WP_Comment_Query();
            $hooked                            = self::statuses( $query->query( $args ) );
            $written                           = (string) file_get_contents( $log );
        } finally {
            ini_set( 'error_log', false === $previous ? '' : $previous );
            unlink( $log );
        }

        $this->assertSame( array( 1 => '1', 4 => '0' ), $core );
        $this->assertSame( $core, $hooked );
        $this->assertSame( self::CORE_EMAIL_APPROVED . ' AND comment_post_ID = 7', end( $GLOBALS['wp_comment_query_log'] ) );
        $this->assertArrayNotHasKey( ANTISPAMWIZARD_Shadow_Display::QUERY_VAR, $query->query_var_defaults );
        $this->assertArrayNotHasKey( 'get_comment', $GLOBALS['wp_filters'] );
        // The throw site is the commenter stub, named by basename only.
        $this->assertMatchesRegularExpression( '/ANTISPAMWIZARD: shadow display failed at bootstrap\.php:\d+$/m', $written );
        $this->assertStringNotContainsString( 'secret failure detail', $written );
        $this->assertStringNotContainsString( '/includes/', $written, 'never the full path' );
    }

    public function test_a_throw_in_the_later_callbacks_returns_their_input_and_drops_the_approval(): void {
        $this->as_cookie_author( 'me@example.com' );
        $shadow            = $this->register();
        $query             = new WP_Comment_Query();
        $query->query_vars = array_merge( $query->query_var_defaults, $this->front_end_args( 'me@example.com' ) );

        $log      = tempnam( sys_get_temp_dir(), 'antispamwizard-shadow-log-' );
        $previous = ini_set( 'error_log', $log );
        try {
            $input = new ANTISPAMWIZARD_Throwing_Shadow_Input();

            $shadow->on_pre_get_comments( $query );
            $this->assertSame( array( $input ), $shadow->on_the_comments( array( $input ), $query ) );
            $plain = array( 'where' => self::CORE_EMAIL_APPROVED );
            antispamwizard_test_set_approved_clause( $query, self::CORE_EMAIL_APPROVED );
            $this->assertSame( $plain, $shadow->on_comments_clauses( $plain, $query ), 'the_comments dropped the approval' );

            // A $wpdb whose every read throws: the shadow fragment cannot be built.
            $shadow->on_pre_get_comments( $query );
            $real_wpdb       = $GLOBALS['wpdb'];
            $GLOBALS['wpdb'] = $input;
            try {
                $this->assertSame( $plain, $shadow->on_comments_clauses( $plain, $query ) );
            } finally {
                $GLOBALS['wpdb'] = $real_wpdb;
            }
            $this->assertSame( self::CORE_EMAIL_APPROVED, antispamwizard_test_get_sql_clauses( $query )['where']['approved'], 'nothing written back' );
            $this->assertSame( array(), $shadow->on_the_comments( array(), $query ), 'comments_clauses dropped the approval' );
            $this->assertArrayNotHasKey( 'get_comment', $GLOBALS['wp_filters'] );

            $this->assertSame( $input, $shadow->on_get_comment( $input ) );
            $shadow->on_transition_comment_status( 'approved', 'spam', $input );
            $written = (string) file_get_contents( $log );
        } finally {
            ini_set( 'error_log', false === $previous ? '' : $previous );
            unlink( $log );
        }

        // Four catches, each naming a bare file name: the fixture's, or the
        // plugin's own call site for the missing $wpdb->prepare().
        $this->assertSame( 4, preg_match_all( '/ANTISPAMWIZARD: shadow display failed at [\w.-]+\.php:\d+$/m', $written ) );
        $this->assertStringNotContainsString( 'secret failure detail', $written );
    }

    public function test_every_catch_block_logs_only_the_throw_site(): void {
        // A comment query runs on every front-end page view and debug.log can be
        // web-readable, so a catch must never write the message (it can carry
        // SQL with the visitor's email) or the full path. Enumerate every use of
        // the caught $e inside each catch block; a name-only grep for
        // getMessage() would miss `. $e`, which stringifies the whole exception.
        $source = file_get_contents( dirname( __DIR__ ) . '/antispam-wizard/includes/class-shadow-display.php' );
        $this->assertIsString( $source );

        preg_match_all( '/catch \( \\\\Throwable \$e \) \{(.*?)\n        \}/s', $source, $blocks );
        $callbacks = array( 'on_pre_get_comments', 'on_comments_clauses', 'on_the_comments', 'on_get_comment', 'on_transition_comment_status', 'on_comment_post_redirect' );
        $this->assertSame( substr_count( $source, 'catch (' ), count( $blocks[1] ), 'every catch block was parsed' );
        $this->assertCount( count( $callbacks ), $blocks[1], 'one catch block per callback, no uncovered one' );
        foreach ( $callbacks as $callback ) {
            $this->assertStringContainsString( 'public function ' . $callback . '(', $source );
        }

        foreach ( $blocks[1] as $body ) {
            $this->assertStringContainsString(
                "error_log( 'ANTISPAMWIZARD: shadow display failed at ' . basename( \$e->getFile() ) . ':' . \$e->getLine() );",
                $body
            );
            preg_match_all( '/(basename\(\s*)?\$e\b(?:\s*->\s*\w+\s*\(\s*\))?/', $body, $uses );
            $this->assertNotEmpty( $uses[0] );
            foreach ( $uses[0] as $use ) {
                $this->assertContains(
                    $use,
                    array( 'basename( $e->getFile()', '$e->getLine()' ),
                    'a catch block may read only basename( getFile() ) and getLine(); ' . $use . ' can disclose the message, the path or the trace'
                );
            }
        }
    }
}
