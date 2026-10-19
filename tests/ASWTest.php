<?php

use PHPUnit\Framework\TestCase;

class ASWTest extends TestCase {

    /**
     * An ordinary Cyrillic comment: long enough for the Bayesian and
     * Similarity checks, and benign enough that no earlier check
     * short-circuits the detector before they run.
     */
    private const CYRILLIC_HAM = 'Отличная статья, спасибо за подробное объяснение. Давно искал такой разбор темы.';

    /**
     * The first real spam comment antispamwizard.com received, and the canary
     * tests/CorpusQualityTest.php holds the Cyrillic corpus to. Used here to
     * assert a real spam verdict through the shipped store wiring, rather than
     * only which transient keys it read.
     */
    private const CYRILLIC_SPAM = 'продам гараж, пишите 89139991205';

    protected function setUp(): void {
        global $wp_options, $wp_actions, $wp_filters, $wp_transients, $wp_transient_reads,
            $asw_loaded_textdomains, $wp_dbdelta_queries, $wpdb, $wp_current_user_caps;
        $wp_options             = array();
        $wp_actions             = array();
        $wp_filters             = array();
        $wp_transients          = array();
        $wp_transient_reads     = array();
        $asw_loaded_textdomains = array();
        $wp_dbdelta_queries     = array();
        // maybe_upgrade_schema() gates on manage_options, and other suites leave
        // the capability global emptied. Restore the bootstrap default so these
        // tests model a site administrator loading wp-admin.
        $wp_current_user_caps = array( 'manage_options' => true );
        // Drop the columns any earlier test's dbDelta() applied (and restore
        // $wp_dbdelta_applies), so the schema-version assertions below read
        // this test's DDL and not a neighbour's.
        $wpdb->reset_tables();

        if ( ! defined( 'ASW_PATH' ) ) {
            define( 'ASW_PATH', dirname( __DIR__ ) . '/antispam-wizard/' );
        }

        // Reset singleton for each test.
        $reflection = new ReflectionClass( ASW::class );
        $instance   = $reflection->getProperty( 'instance' );
        $instance->setValue( null, null );
    }

    public function test_singleton_returns_same_instance() {
        $a = ASW::get_instance();
        $b = ASW::get_instance();

        $this->assertSame( $a, $b );
    }

    public function test_init_registers_actions() {
        global $wp_actions;

        ASW::get_instance()->init();

        // Settings::register() was removed as dead code — verify it is NOT registered.
        $admin_init_callbacks = array_column( $wp_actions['admin_init'] ?? array(), 'callback' );
        $this->assertNotContains(
            array( 'ASW_Settings', 'register' ),
            $admin_init_callbacks
        );

        // Verify text domain loading is registered.
        $init_callbacks = array_column( $wp_actions['init'] ?? array(), 'callback' );
        $this->assertNotEmpty( $init_callbacks );

        // Verify bot check form hooks are registered unconditionally.
        $this->assertNotEmpty( $wp_actions['comment_form'] ?? array() );
    }

    public function test_init_registers_the_shadow_display_hooks(): void {
        global $wp_actions, $wp_filters;

        ASW::get_instance()->init();

        $expected = array(
            array( $wp_actions, 'pre_get_comments', 'on_pre_get_comments', 1 ),
            array( $wp_filters, 'comments_clauses', 'on_comments_clauses', 2 ),
            array( $wp_filters, 'the_comments', 'on_the_comments', 2 ),
            array( $wp_actions, 'transition_comment_status', 'on_transition_comment_status', 3 ),
        );
        foreach ( $expected as list( $registry, $hook, $method, $args ) ) {
            $found = false;
            foreach ( $registry[ $hook ] ?? array() as $registered ) {
                $callback = $registered['callback'];
                if ( is_array( $callback ) && $callback[0] instanceof ASW_Shadow_Display && $method === $callback[1] ) {
                    $found = true;
                    $this->assertSame( $args, $registered['accepted_args'], "{$hook} must take {$args} argument(s)." );
                }
            }
            $this->assertTrue( $found, "init() must register ASW_Shadow_Display::{$method} on {$hook}." );
        }
    }

    public function test_activate_creates_default_settings() {
        global $wp_options;

        ASW::activate();

        $this->assertArrayHasKey( ASW_Settings::OPTION_KEY, $wp_options );
        $this->assertSame(
            ASW_Settings::get_defaults(),
            $wp_options[ ASW_Settings::OPTION_KEY ]
        );
    }

    public function test_activate_does_not_overwrite_existing_settings() {
        global $wp_options;
        $custom = array( 'links_max' => 99 );
        $wp_options[ ASW_Settings::OPTION_KEY ] = $custom;

        ASW::activate();

        $this->assertSame( $custom, $wp_options[ ASW_Settings::OPTION_KEY ] );
    }

    public function test_activate_default_settings_include_bayesian() {
        global $wp_options;

        ASW::activate();

        $settings = $wp_options[ ASW_Settings::OPTION_KEY ];
        $this->assertArrayHasKey( 'bayesian_enabled', $settings );
        $this->assertTrue( $settings['bayesian_enabled'] );
        $this->assertArrayHasKey( 'bayesian_threshold', $settings );
        $this->assertEquals( 0.7, $settings['bayesian_threshold'] );
    }

    // --- samples schema version ---

    public function test_activate_records_the_samples_schema_version(): void {
        ASW::activate();

        $this->assertSame(
            ASW_Sample_Repository::DB_VERSION,
            get_option( ASW_Sample_Repository::DB_VERSION_OPTION )
        );
    }

    public function test_init_registers_the_schema_upgrade_check(): void {
        global $wp_actions;

        ASW::get_instance()->init();

        $admin_init_callbacks = array_column( $wp_actions['admin_init'] ?? array(), 'callback' );
        $registered = false;
        foreach ( $admin_init_callbacks as $callback ) {
            if ( is_array( $callback ) && 'maybe_upgrade_schema' === ( $callback[1] ?? '' ) ) {
                $registered = true;
            }
        }

        // Without this, a site that updates the plugin in place never re-runs the
        // DDL -- register_activation_hook() does not fire on an update -- so the
        // samples table keeps the old layout and every insert() fails.
        $this->assertTrue( $registered, 'init() must schedule the schema upgrade check.' );
    }

    public function test_maybe_upgrade_schema_reruns_the_ddl_when_the_stored_version_is_behind(): void {
        global $wp_dbdelta_queries;

        // Model a site whose stored schema version is behind DB_VERSION.
        update_option( ASW_Sample_Repository::DB_VERSION_OPTION, '0' );

        $asw = ASW::get_instance();
        $asw->init();
        $wp_dbdelta_queries = array();

        $asw->maybe_upgrade_schema();

        $this->assertCount( 1, $wp_dbdelta_queries );
        $this->assertStringContainsString( 'comment_id', $wp_dbdelta_queries[0] );
        $this->assertSame(
            ASW_Sample_Repository::DB_VERSION,
            get_option( ASW_Sample_Repository::DB_VERSION_OPTION )
        );
    }

    public function test_maybe_upgrade_schema_reruns_the_ddl_when_no_version_is_stored(): void {
        global $wp_dbdelta_queries;

        // A table created by 1.0.0 predates the version option entirely.
        $asw = ASW::get_instance();
        $asw->init();
        $wp_dbdelta_queries = array();

        $asw->maybe_upgrade_schema();

        $this->assertCount( 1, $wp_dbdelta_queries );
        $this->assertSame(
            ASW_Sample_Repository::DB_VERSION,
            get_option( ASW_Sample_Repository::DB_VERSION_OPTION )
        );
    }

    public function test_maybe_upgrade_schema_is_a_no_op_when_the_version_matches(): void {
        global $wp_dbdelta_queries;

        update_option( ASW_Sample_Repository::DB_VERSION_OPTION, ASW_Sample_Repository::DB_VERSION );

        $asw = ASW::get_instance();
        $asw->init();
        $wp_dbdelta_queries = array();

        $asw->maybe_upgrade_schema();

        // dbDelta() is expensive (it reads the live schema); it must not run on
        // every admin request once the table is current.
        $this->assertSame( array(), $wp_dbdelta_queries );
    }

    public function test_maybe_upgrade_schema_does_not_record_the_version_when_the_ddl_failed(): void {
        global $wp_dbdelta_applies;

        // dbDelta() cannot report failure -- it fires each ALTER without
        // checking the result -- so a database that refused the statement (no
        // ALTER privilege, a metadata lock, a full disk) looks exactly like a
        // success. Recording the version anyway would short-circuit every later
        // attempt and leave the table on the old layout for good.
        $wp_dbdelta_applies = false;
        update_option( ASW_Sample_Repository::DB_VERSION_OPTION, '0' );

        $asw = ASW::get_instance();
        $asw->init();
        $asw->maybe_upgrade_schema();

        $this->assertSame( '0', get_option( ASW_Sample_Repository::DB_VERSION_OPTION ) );
    }

    public function test_maybe_upgrade_schema_retries_after_a_failed_ddl(): void {
        global $wp_dbdelta_applies, $wp_dbdelta_queries;

        $wp_dbdelta_applies = false;
        $asw                = ASW::get_instance();
        $asw->init();
        $asw->maybe_upgrade_schema();

        // The refusal was transient: the next admin request must run the DDL
        // again rather than treat the site as migrated.
        $wp_dbdelta_applies = true;
        $wp_dbdelta_queries = array();
        $asw->maybe_upgrade_schema();

        $this->assertCount( 1, $wp_dbdelta_queries );
        $this->assertSame(
            ASW_Sample_Repository::DB_VERSION,
            get_option( ASW_Sample_Repository::DB_VERSION_OPTION )
        );
    }

    public function test_maybe_upgrade_schema_does_not_record_the_version_when_only_the_index_ddl_failed(): void {
        global $wp_dbdelta_index_applies;

        // dbDelta() emits the ADD COLUMN and the ADD KEY as separate ALTERs and
        // checks neither, so a database can accept one and refuse the other. If
        // the version were stamped on the column alone, the version gate would
        // short-circuit every later attempt and the index would stay missing for
        // good -- putting the Privacy API's lookups on a permanent table scan.
        $wp_dbdelta_index_applies = false;
        update_option( ASW_Sample_Repository::DB_VERSION_OPTION, '0' );

        $asw = ASW::get_instance();
        $asw->init();
        $asw->maybe_upgrade_schema();

        $this->assertSame( '0', get_option( ASW_Sample_Repository::DB_VERSION_OPTION ) );

        // And the retry lands once the database accepts the index.
        $wp_dbdelta_index_applies = true;
        $asw->maybe_upgrade_schema();

        $this->assertSame(
            ASW_Sample_Repository::DB_VERSION,
            get_option( ASW_Sample_Repository::DB_VERSION_OPTION )
        );
    }

    public function test_maybe_upgrade_schema_is_skipped_without_the_manage_options_capability(): void {
        global $wp_dbdelta_queries, $wp_current_user_caps;

        // admin_init is not admin-only: admin-ajax.php and admin-post.php fire it
        // before their is_user_logged_in() check, so an anonymous request reaches
        // the handler. When the DDL keeps being refused the version option is
        // deliberately left behind to force a retry, so an ungated handler would
        // re-run a full dbDelta() on every unauthenticated hit.
        update_option( ASW_Sample_Repository::DB_VERSION_OPTION, '0' );

        $asw = ASW::get_instance();
        $asw->init();
        $wp_dbdelta_queries   = array();
        $wp_current_user_caps = array();

        $asw->maybe_upgrade_schema();

        $this->assertSame( array(), $wp_dbdelta_queries );
        // The migration is only deferred, never abandoned: the stored version
        // must stay behind so the next capable admin request still applies it.
        $this->assertSame( '0', get_option( ASW_Sample_Repository::DB_VERSION_OPTION ) );
    }

    public function test_maybe_upgrade_schema_runs_for_a_capable_user_after_an_anonymous_request(): void {
        global $wp_dbdelta_queries, $wp_current_user_caps;

        update_option( ASW_Sample_Repository::DB_VERSION_OPTION, '0' );

        $asw = ASW::get_instance();
        $asw->init();
        $wp_dbdelta_queries   = array();
        $wp_current_user_caps = array();
        $asw->maybe_upgrade_schema();

        $wp_current_user_caps = array( 'manage_options' => true );
        $asw->maybe_upgrade_schema();

        $this->assertCount( 1, $wp_dbdelta_queries );
        $this->assertSame(
            ASW_Sample_Repository::DB_VERSION,
            get_option( ASW_Sample_Repository::DB_VERSION_OPTION )
        );
    }

    public function test_activate_does_not_record_the_version_when_the_ddl_failed(): void {
        global $wp_dbdelta_applies;

        $wp_dbdelta_applies = false;

        ASW::activate();

        $this->assertFalse( get_option( ASW_Sample_Repository::DB_VERSION_OPTION ) );
    }

    /**
     * Seed a row as a table predating the two subject keys would hold it.
     *
     * @return void
     */
    private function seed_legacy_sample(): void {
        global $wpdb;
        $wpdb->insert(
            'wp_asw_samples',
            array(
                'comment_id'   => 0,
                'email_hash'   => '',
                'script'       => 'latin',
                'category'     => 'spam',
                'content'      => 'Legacy sample no request can reach',
                'content_hash' => hash( 'sha256', 'Legacy sample no request can reach' ),
                'created_at'   => '2026-01-01 00:00:00',
            )
        );
    }

    public function test_maybe_upgrade_schema_drops_samples_no_subject_key_can_reach(): void {
        global $wpdb;

        // A sample written before comment_id/email_hash existed takes their
        // defaults when dbDelta() adds the columns, so neither Privacy API
        // lookup can ever match it -- while it still holds the trained comment's
        // full text that readme.txt promises is exportable and erasable.
        $this->seed_legacy_sample();
        update_option( ASW_Sample_Repository::DB_VERSION_OPTION, '0' );

        $asw = ASW::get_instance();
        $asw->init();
        $asw->maybe_upgrade_schema();

        $this->assertSame( array(), $asw->get_repository()->find_all( 'latin', 'spam' ) );
        $this->assertSame(
            ASW_Sample_Repository::DB_VERSION,
            get_option( ASW_Sample_Repository::DB_VERSION_OPTION )
        );
    }

    public function test_maybe_upgrade_schema_does_not_record_the_version_when_the_purge_failed(): void {
        global $wpdb;

        $this->seed_legacy_sample();
        update_option( ASW_Sample_Repository::DB_VERSION_OPTION, '0' );

        $asw = ASW::get_instance();
        $asw->init();

        // A refused DELETE must leave the version behind so the next admin
        // request retries: stamping it would strand the unreachable rows for
        // good, exactly as stamping over a refused ALTER strands the layout.
        $wpdb->query_result = false;
        $asw->maybe_upgrade_schema();
        $wpdb->query_result = null;

        $this->assertSame( '0', get_option( ASW_Sample_Repository::DB_VERSION_OPTION ) );
        $this->assertCount( 1, $asw->get_repository()->find_all( 'latin', 'spam' ) );

        // The retry succeeds and completes the migration.
        $asw->maybe_upgrade_schema();

        $this->assertSame( array(), $asw->get_repository()->find_all( 'latin', 'spam' ) );
        $this->assertSame(
            ASW_Sample_Repository::DB_VERSION,
            get_option( ASW_Sample_Repository::DB_VERSION_OPTION )
        );
    }

    public function test_activate_drops_samples_no_subject_key_can_reach(): void {
        global $wpdb;

        // Reactivating after an in-place update stamps the version from
        // activate(), so the purge has to run here too or maybe_upgrade_schema()
        // never gets the chance.
        $this->seed_legacy_sample();

        ASW::activate();

        $this->assertSame( array(), ( new ASW_Sample_Repository( $wpdb ) )->find_all( 'latin', 'spam' ) );
        $this->assertSame(
            ASW_Sample_Repository::DB_VERSION,
            get_option( ASW_Sample_Repository::DB_VERSION_OPTION )
        );
    }

    public function test_activate_does_not_record_the_version_when_the_purge_failed(): void {
        global $wpdb;

        $this->seed_legacy_sample();
        $wpdb->query_result = false;

        ASW::activate();

        $wpdb->query_result = null;
        $this->assertFalse( get_option( ASW_Sample_Repository::DB_VERSION_OPTION ) );
    }

    public function test_init_registers_bayesian_check(): void {
        global $wp_filters;

        ASW::get_instance()->init();

        // Verify that the pre_comment_approved filter is registered (which means detector with bayesian check is wired).
        $this->assertArrayHasKey( 'pre_comment_approved', $wp_filters );
    }

    public function test_build_checks_returns_bot_checks_first(): void {
        $checks = ASW::get_instance()->build_checks();
        $this->assertCount( 10, $checks );
        $this->assertInstanceOf( ASW_Honeypot_Check::class, $checks[0] );
        $this->assertInstanceOf( ASW_Submit_Time_Check::class, $checks[1] );
    }

    /**
     * Evaluate a body through a detector wired to the real build_checks().
     *
     * @param string $body Comment body.
     * @return array Detector verdict.
     */
    private function evaluate_body( string $body ): array {
        $detector = new ASW_Detector( array( ASW::get_instance(), 'build_checks' ) );

        return $detector->evaluate( array( 'comment_content' => $body ) );
    }

    /**
     * The model-unit transient keys read so far, in call order.
     *
     * The Duplicate check reads transients of its own, so the raw read log is
     * filtered down to the model store's prefix.
     *
     * @return string[]
     */
    private function unit_reads(): array {
        global $wp_transient_reads;

        return array_values(
            array_filter(
                $wp_transient_reads,
                static function ( $key ) {
                    return str_starts_with( $key, ASW_Model_Store::TRANSIENT_PREFIX );
                }
            )
        );
    }

    /**
     * Every cached model-unit key currently stored.
     *
     * @return string[]
     */
    private function cached_unit_keys(): array {
        global $wp_transients;

        return array_values(
            array_filter(
                array_keys( $wp_transients ),
                static function ( $key ) {
                    return str_starts_with( $key, ASW_Model_Store::TRANSIENT_PREFIX );
                }
            )
        );
    }

    public function test_build_checks_loads_no_model_and_reads_no_transient(): void {
        global $wp_transient_reads;

        $checks = ASW::get_instance()->build_checks();

        $this->assertCount( 10, $checks );
        // Models are now loaded by the store, on the submission that needs them,
        // so building the checks must not touch a model transient. (That it also
        // reads no corpus file is what the ~0 ms cold figure in the plan's
        // Measurements table shows; ModelStoreTest::test_building_a_script_unit_
        // reads_only_that_scripts_corpus_files pins the file side, on the store.)
        $this->assertSame( array(), $wp_transient_reads, 'build_checks() read a transient' );
        $this->assertSame( array(), $this->cached_unit_keys(), 'build_checks() wrote a model unit' );
    }

    public function test_a_cyrillic_spam_body_is_flagged_through_the_store_backed_checks(): void {
        // Every other store test asserts which unit was read and discards the
        // verdict, which a store handing back empty or undertrained models would
        // also satisfy -- an empty classifier answers "insufficient training
        // data" and an empty sample list scores 0.00, both of them not-spam. This
        // is the one assertion that fails if the store loads the wrong unit, or
        // loads a usable unit and resolves past it.
        $result = $this->evaluate_body( self::CYRILLIC_SPAM );

        $this->assertTrue( $result['is_spam'], 'The Cyrillic spam canary must be flagged through build_checks()' );
        $this->assertNotEmpty(
            array_intersect( array( 'bayesian', 'similarity' ), $result['check_names'] ),
            'It must be one of the two model-backed checks that flagged it, not an incidental check: '
                . implode( ',', $result['check_names'] )
        );

        // And it must be the Cyrillic unit that produced it, not the combined
        // fallback: a store that failed to load the per-script unit would still
        // reach this verdict through the combined one, so the verdict alone does
        // not pin the routing. Exactly one unit read, and it is the Cyrillic one.
        $reads = $this->unit_reads();
        $this->assertCount( 1, $reads, 'The combined unit must not be touched: ' . implode( ',', $reads ) );
        $this->assertSame( ASW_Model_Store::TRANSIENT_PREFIX . 'cyrillic', $reads[0] );
    }

    public function test_a_cyrillic_ham_body_is_not_flagged_through_the_store_backed_checks(): void {
        // The other side of the canary: the same wiring must leave ordinary
        // praise alone, so the test above cannot be satisfied by a model that
        // flags everything.
        $result = $this->evaluate_body( self::CYRILLIC_HAM );

        $this->assertFalse( $result['is_spam'], 'Ordinary Cyrillic praise must not be flagged' );
    }

    public function test_a_warm_cyrillic_evaluation_reads_only_the_cyrillic_unit(): void {
        global $wp_transient_reads;

        $this->evaluate_body( self::CYRILLIC_HAM );

        $cold_reads = $this->unit_reads();
        $this->assertCount( 1, $cold_reads );
        $this->assertSame( ASW_Model_Store::TRANSIENT_PREFIX . 'cyrillic', $cold_reads[0] );

        $wp_transient_reads = array();
        $this->evaluate_body( self::CYRILLIC_HAM );

        $warm_reads = $this->unit_reads();
        $this->assertSame( $cold_reads, $warm_reads,
            'A warm Cyrillic evaluation must read exactly the Cyrillic unit and nothing else' );
        $this->assertSame( array( $cold_reads[0] ), $this->cached_unit_keys(),
            'No unit other than the Cyrillic one may be cached' );
    }

    public function test_an_unknown_script_body_reads_only_the_combined_unit(): void {
        // Digits delimited by commas: no letters, so ASW_Script_Detector::detect()
        // returns 'unknown', which has no unit of its own. (Commas matter - the
        // tokenizer folds space-separated digit runs into one phone number.)
        $this->evaluate_body( '12345, 67890, 13579, 24680, 11223, 44556, 77889' );

        $reads = $this->unit_reads();
        $this->assertCount( 1, $reads );
        $this->assertSame( ASW_Model_Store::TRANSIENT_PREFIX . 'combined', $reads[0] );
    }

    public function test_disabled_bayesian_and_similarity_read_no_model_unit(): void {
        global $wp_options;

        $settings                       = ASW_Settings::get_defaults();
        $settings['bayesian_enabled']   = false;
        $settings['similarity_enabled'] = false;
        $wp_options[ ASW_Settings::OPTION_KEY ] = $settings;

        $this->evaluate_body( self::CYRILLIC_HAM );

        $this->assertSame( array(), $this->unit_reads(),
            'With both model-backed checks off nothing may load a model unit' );
        $this->assertSame( array(), $this->cached_unit_keys() );
    }

    public function test_an_evaluation_caches_the_scripts_unit_in_a_transient(): void {
        global $wp_transients;

        $this->evaluate_body( self::CYRILLIC_HAM );

        $keys = $this->cached_unit_keys();
        $this->assertCount( 1, $keys, 'Expected exactly one cached model unit' );
        $this->assertSame( ASW_Model_Store::TRANSIENT_PREFIX . 'cyrillic', $keys[0] );

        $cached = asw_test_unwrap_unit( $wp_transients[ $keys[0] ] );
        $this->assertIsArray( $cached );
        $this->assertArrayHasKey( 'classifier', $cached );
        $this->assertArrayHasKey( 'samples', $cached );
        $this->assertArrayHasKey( 'token_counts', $cached['classifier'] );
        $this->assertNotEmpty( $cached['samples'] );
    }

    public function test_a_second_evaluation_restores_the_unit_from_cache(): void {
        global $wp_transients, $wp_options;

        $this->evaluate_body( self::CYRILLIC_HAM );

        $keys = $this->cached_unit_keys();
        $this->assertCount( 1, $keys );
        $key = $keys[0];

        // A marker inside the cached unit survives a restore and is lost on a
        // rebuild, so it tells the two apart without timing anything.
        $wp_transients[ $key ]['_test_marker']                = true;
        $wp_options[ '_transient_' . $key ]['_test_marker']   = true;

        $this->evaluate_body( self::CYRILLIC_HAM );

        $this->assertArrayHasKey( '_test_marker', $wp_transients[ $key ],
            'Unit was rebuilt instead of restored - the marker was lost' );
    }

    public function test_a_corrupted_cached_unit_is_rebuilt(): void {
        global $wp_transients, $wp_options;

        $this->evaluate_body( self::CYRILLIC_HAM );

        $keys = $this->cached_unit_keys();
        $this->assertCount( 1, $keys );
        $key = $keys[0];

        // Still an array, so is_array() passes, but set_state() rejects the
        // classifier state: neither doc count survives and the unit is unusable.
        $corrupted = array(
            // Carried forward, or the unit is rebuilt for being stale rather
            // than for being corrupt and the test would pass vacuously.
            'fingerprint' => asw_test_unwrap_unit( $wp_transients[ $key ] )['fingerprint'],
            'classifier'  => array( 'token_counts' => 'bad' ),
            'samples'     => array(),
        );
        // Stored in the form this host writes: a unit is compressed behind an
        // explicit envelope wherever zlib is present, and the serving path
        // rejects the other form outright -- which would rebuild this unit for
        // the wrong reason and pass the test vacuously.
        $corrupted                          = asw_test_wrap_unit( $corrupted );
        $wp_transients[ $key ]              = $corrupted;
        $wp_options[ '_transient_' . $key ] = $corrupted;

        $this->evaluate_body( self::CYRILLIC_HAM );

        $rebuilt = asw_test_unwrap_unit( $wp_transients[ $key ] );
        $this->assertIsArray( $rebuilt['classifier']['token_counts'],
            'A corrupted unit must be replaced with freshly trained state' );
        $this->assertNotEmpty( $rebuilt['samples'] );
    }

    public function test_a_units_key_is_stable_across_evaluations(): void {
        $this->evaluate_body( self::CYRILLIC_HAM );
        $first = $this->cached_unit_keys();

        $this->evaluate_body( self::CYRILLIC_HAM );
        $second = $this->cached_unit_keys();

        $this->assertSame( $first, $second,
            'Unchanged corpus, settings and samples must resolve to the same unit key' );
        $this->assertStringStartsWith( ASW_Model_Store::TRANSIENT_PREFIX, $first[0],
            'uninstall.php removes the cache by LIKE over this prefix' );
    }

    public function test_init_action_loads_textdomain(): void {
        // setUp() resets $asw_loaded_textdomains for every test; declare it here
        // only to read the accumulated callback result in the assertion below.
        global $asw_loaded_textdomains;

        ASW::get_instance()->init();

        // Firing the init action must invoke the registered load_textdomain
        // callback, which delegates to load_plugin_textdomain().
        do_action( 'init' );

        $this->assertContains( 'antispam-wizard', $asw_loaded_textdomains,
            'do_action( init ) should trigger load_plugin_textdomain for the text domain' );
    }
}
