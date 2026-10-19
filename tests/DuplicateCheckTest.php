<?php

use PHPUnit\Framework\TestCase;

class DuplicateCheckTest extends TestCase {

    private ASW_Duplicate_Check $check;

    protected function setUp(): void {
        parent::setUp();
        global $wp_options, $wp_transients, $wp_using_ext_object_cache, $wp_cache_incr_force_false, $wp_cache_incr_evict_on_force;
        $wp_options                   = array();
        $wp_transients                = array();
        $wp_using_ext_object_cache    = false;
        $wp_cache_incr_force_false    = 0;
        $wp_cache_incr_evict_on_force = false;
        $this->check                  = new ASW_Duplicate_Check();
    }

    protected function tearDown(): void {
        global $wp_options, $wp_transients, $wp_using_ext_object_cache, $wp_cache_incr_force_false, $wp_cache_incr_evict_on_force;
        $wp_options                   = array();
        $wp_transients                = array();
        $wp_using_ext_object_cache    = false;
        $wp_cache_incr_force_false    = 0;
        $wp_cache_incr_evict_on_force = false;
        parent::tearDown();
    }

    /**
     * Every key in the shared $wp_transients global carrying the given prefix.
     *
     * Both storage layers land in that one global: the file-locked path writes
     * arrays under bare asw_dup_ keys, the object cache stubs write integers
     * under group-prefixed asw:asw_dup_ keys. The prefix is what tells them
     * apart, and 'asw_dup_' deliberately does not match 'asw:asw_dup_'.
     *
     * @return string[]
     */
    private function transient_keys( string $prefix ): array {
        global $wp_transients;
        $keys = array();
        foreach ( array_keys( $wp_transients ) as $key ) {
            if ( str_starts_with( $key, $prefix ) ) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /**
     * Locate the asw_dup_ transient key created by a prior check() call.
     */
    private function find_dup_transient_key(): ?string {
        return $this->transient_keys( 'asw_dup_' )[0] ?? null;
    }

    /**
     * Locate the group-prefixed cache counter key (asw:asw_dup_) written by the
     * external-object-cache path. The cache path stores an integer counter here,
     * whereas the file-locked path writes an array under the bare asw_dup_ key.
     */
    private function find_cache_counter_key(): ?string {
        return $this->transient_keys( 'asw:asw_dup_' )[0] ?? null;
    }

    private function make_comment( string $content, string $ip = '127.0.0.1' ): array {
        return array(
            'comment_content'          => $content,
            'comment_author_IP'        => $ip,
            'comment_author_IP_hashed' => ASW_Detector::hash_ip( $ip ),
        );
    }

    public function test_first_comment_passes(): void {
        $result = $this->check->check( $this->make_comment( 'Hello world' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'duplicate', $result->get_check_name() );
        $this->assertSame( 'duplicate count 1/3', $result->get_details() );
    }

    public function test_second_comment_passes(): void {
        $comment = $this->make_comment( 'Hello world' );
        $this->check->check( $comment );
        $result = $this->check->check( $comment );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'duplicate count 2/3', $result->get_details() );
    }

    public function test_threshold_triggers_spam(): void {
        $comment = $this->make_comment( 'Buy now!' );
        $this->check->check( $comment );
        $this->check->check( $comment );
        $result = $this->check->check( $comment );
        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'duplicate comment 3/3', $result->get_details() );
    }

    public function test_different_content_passes(): void {
        $ip = '10.0.0.1';
        $this->check->check( $this->make_comment( 'First comment', $ip ) );
        $this->check->check( $this->make_comment( 'First comment', $ip ) );
        $result = $this->check->check( $this->make_comment( 'Different comment', $ip ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'duplicate count 1/3', $result->get_details() );
    }

    public function test_different_ip_passes(): void {
        $this->check->check( $this->make_comment( 'Same text', '1.1.1.1' ) );
        $this->check->check( $this->make_comment( 'Same text', '1.1.1.1' ) );
        $result = $this->check->check( $this->make_comment( 'Same text', '2.2.2.2' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'duplicate count 1/3', $result->get_details() );
    }

    public function test_custom_threshold(): void {
        ASW_Settings::update( array( 'duplicate_threshold' => 2 ) );
        $comment = $this->make_comment( 'Spam spam' );
        $this->check->check( $comment );
        $result = $this->check->check( $comment );
        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'duplicate comment 2/2', $result->get_details() );
    }

    public function test_disabled_check_returns_not_spam(): void {
        ASW_Settings::update( array( 'duplicate_enabled' => false ) );
        $comment = $this->make_comment( 'Anything' );
        $this->check->check( $comment );
        $this->check->check( $comment );
        $result = $this->check->check( $comment );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'check disabled', $result->get_details() );
    }

    public function test_empty_comment_passes(): void {
        $result = $this->check->check( $this->make_comment( '' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'missing data', $result->get_details() );
    }

    public function test_missing_ip_passes(): void {
        $result = $this->check->check( array( 'comment_content' => 'Hello' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'missing data', $result->get_details() );
    }

    public function test_implements_check_interface(): void {
        $this->assertInstanceOf( ASW_Check::class, $this->check );
    }

    public function test_near_duplicate_case_difference_matches(): void {
        $ip      = '3.3.3.3';
        $this->check->check( $this->make_comment( 'Buy cheap stuff now', $ip ) );
        $this->check->check( $this->make_comment( 'BUY CHEAP STUFF NOW', $ip ) );
        $result = $this->check->check( $this->make_comment( 'buy Cheap Stuff Now', $ip ) );
        $this->assertTrue( $result->is_spam() );
        $this->assertStringContainsString( 'duplicate comment', $result->get_details() );
    }

    public function test_near_duplicate_extra_spaces_matches(): void {
        $ip      = '4.4.4.4';
        $this->check->check( $this->make_comment( 'Hello world', $ip ) );
        $this->check->check( $this->make_comment( 'Hello   world', $ip ) );
        $result = $this->check->check( $this->make_comment( "Hello\t\nworld", $ip ) );
        $this->assertTrue( $result->is_spam() );
    }

    public function test_near_duplicate_trailing_whitespace_matches(): void {
        $ip      = '6.6.6.6';
        $this->check->check( $this->make_comment( 'Spam message', $ip ) );
        $this->check->check( $this->make_comment( '  Spam message  ', $ip ) );
        $result = $this->check->check( $this->make_comment( 'spam message', $ip ) );
        $this->assertTrue( $result->is_spam() );
    }

    public function test_near_duplicate_cyrillic_case_difference_matches(): void {
        $ip = '7.7.7.7';
        $this->check->check( $this->make_comment( 'Привет мир', $ip ) );
        $this->check->check( $this->make_comment( 'ПРИВЕТ МИР', $ip ) );
        $result = $this->check->check( $this->make_comment( 'привет мир', $ip ) );
        $this->assertTrue( $result->is_spam() );
        $this->assertStringContainsString( 'duplicate comment', $result->get_details() );
    }

    public function test_near_duplicate_unicode_whitespace_matches(): void {
        $ip = '8.8.8.8';
        // Use NBSP (U+00A0) and ideographic space (U+3000) between words.
        $this->check->check( $this->make_comment( "Buy now", $ip ) );
        $this->check->check( $this->make_comment( "Buy\xC2\xA0now", $ip ) ); // NBSP
        $result = $this->check->check( $this->make_comment( "Buy\xE3\x80\x80now", $ip ) ); // Ideographic space
        $this->assertTrue( $result->is_spam() );
    }

    public function test_transient_ttl_is_capped_at_max(): void {
        // Set TTL higher than MAX_TTL (86400).
        ASW_Settings::update( array( 'duplicate_ttl' => 200000 ) );

        $comment = $this->make_comment( 'TTL test comment' );
        $this->check->check( $comment );

        // Inspect the transient that was set — its expiry should be capped.
        global $wp_transients;
        $found_ttl = null;
        foreach ( $wp_transients as $key => $data ) {
            if ( str_starts_with( $key, 'asw_dup_' ) && isset( $data['__ttl'] ) ) {
                $found_ttl = $data['__ttl'];
                break;
            }
        }

        $this->assertNotNull( $found_ttl, 'Expected a asw_dup_ transient to be set' );
        $this->assertLessThanOrEqual(
            ASW_Duplicate_Check::MAX_TTL,
            $found_ttl,
            'Transient TTL should be capped at MAX_TTL'
        );
    }

    public function test_max_ttl_constant_is_86400(): void {
        $this->assertSame( 86400, ASW_Duplicate_Check::MAX_TTL );
    }

    public function test_lock_files_use_uploads_directory(): void {
        $upload_dir = wp_upload_dir();
        $lock_dir   = $upload_dir['basedir'] . '/.asw-locks';

        $comment = $this->make_comment( 'lock dir test' );
        $this->check->check( $comment );

        $this->assertDirectoryExists( $lock_dir );
        // At least one asw_dup_*.lock file should exist.
        $lock_files = glob( $lock_dir . '/asw_dup_*.lock' );
        $this->assertNotEmpty( $lock_files );
    }

    /**
     * The unlocked fallback branch: when fopen() cannot produce a stream handle
     * the read-modify-write still has to run, or the Duplicate check silently
     * stops counting on any host where the lock directory is unusable.
     *
     * The tests run as root in the container, so chmod 0000 does not make
     * fopen() fail. Putting a *directory* at the lock file's path does, for
     * every user - which is why the lock path is derived here from the transient
     * key the first check() actually wrote, rather than by chmod.
     */
    public function test_duplicate_check_still_counts_when_the_lock_file_cannot_be_opened(): void {
        $upload_dir = wp_upload_dir();
        $lock_dir   = $upload_dir['basedir'] . '/.asw-locks';

        $ip      = '9.9.9.9';
        $comment = $this->make_comment( 'lock fail dup test', $ip );

        // First pass creates both the transient and the lock file, so the bucket
        // can be read off the key instead of re-deriving it from the IP hash.
        $this->check->check( $comment );

        $key = $this->find_dup_transient_key();
        $this->assertNotNull( $key );

        $lock_file = $lock_dir . '/asw_dup_' . substr( $key, -2 ) . '.lock';
        $this->assertFileExists( $lock_file, 'the locked path must have created this file' );
        unlink( $lock_file );
        mkdir( $lock_file );

        // The fallback logs the unopenable path through error_log(); route it to
        // a file so the expected diagnostic is not printed over the suite's own
        // output, and assert it was actually emitted.
        $log      = tempnam( sys_get_temp_dir(), 'asw-lock-log-' );
        $previous = ini_set( 'error_log', $log );

        try {
            $this->assertFalse( @fopen( $lock_file, 'c' ), 'the fallback branch must actually be reachable' );

            // No @ on these calls: fopen()'s own "failed to open stream"
            // warning names the absolute path, so it has to be suppressed at
            // the call site in production - suppressing it here instead would
            // let that leak back in unnoticed.
            $this->check->check( $comment );
            $result = $this->check->check( $comment );
            $this->assertTrue( $result->is_spam() );
            $this->assertSame( 'duplicate comment 3/3', $result->get_details() );
            $logged = (string) file_get_contents( $log );
            $this->assertStringContainsString( 'unable to open lock file', $logged );

            // Neither the plugin's diagnostic nor PHP's own fopen() warning may
            // name the absolute path: this runs on an unauthenticated comment
            // submission and debug.log is web-readable on misconfigured hosts,
            // so the path would leak the document root. Same rule as
            // ASW_Detector::run()'s basename().
            $this->assertStringContainsString( basename( $lock_file ), $logged );
            $this->assertStringNotContainsString( $lock_dir, $logged );
        } finally {
            ini_set( 'error_log', false === $previous ? '' : $previous );
            unlink( $log );
            rmdir( $lock_file );
        }
    }

    public function test_uses_hashed_ip_from_comment_data(): void {
        $comment_with_hash = array(
            'comment_content'          => 'Test content',
            'comment_author_IP_hashed' => ASW_Detector::hash_ip( '5.5.5.5' ),
        );
        $comment_with_raw = array(
            'comment_content'   => 'Test content',
            'comment_author_IP' => '5.5.5.5',
        );

        $this->check->check( $comment_with_hash );
        $this->check->check( $comment_with_hash );
        $result = $this->check->check( $comment_with_raw );
        $this->assertTrue( $result->is_spam() );
    }

    // --- External object cache path (wp_using_ext_object_cache() === true) ---

    public function test_cache_path_happy_path_threshold(): void {
        global $wp_using_ext_object_cache, $wp_transients;
        $wp_using_ext_object_cache = true;

        $comment = $this->make_comment( 'cache happy path', '11.11.11.11' );
        $first   = $this->check->check( $comment );
        $this->assertFalse( $first->is_spam() );
        $this->assertSame( 'duplicate count 1/3', $first->get_details() );

        $this->check->check( $comment );
        $result = $this->check->check( $comment );
        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'duplicate comment 3/3', $result->get_details() );

        // Discriminate the cache branch: the cache path stores an integer
        // counter under the group-prefixed asw:asw_dup_ key, and must NOT touch
        // the bare asw_dup_ file-locked transient. These fail if the file path
        // ran instead of the cache path.
        $cache_key = $this->find_cache_counter_key();
        $this->assertNotNull( $cache_key, 'Expected a cache counter (asw:asw_dup_) key' );
        $this->assertSame( 3, $wp_transients[ $cache_key ] );
        $this->assertNull( $this->find_dup_transient_key(), 'File-locked transient path must not run on the cache path' );
    }

    public function test_cache_path_eviction_recovery_resets_counter(): void {
        global $wp_using_ext_object_cache, $wp_cache_incr_force_false, $wp_cache_incr_evict_on_force;
        $wp_using_ext_object_cache = true;

        $comment = $this->make_comment( 'cache eviction recovery', '12.12.12.12' );
        // Seed the counter so the key already exists in the cache.
        $this->check->check( $comment );

        // Simulate the backend evicting the key between add() and incr():
        // incr returns false AND the key is removed, so the re-add() succeeds
        // and the counter restarts at 1 (deliberate fail-open behaviour).
        $wp_cache_incr_force_false    = 1;
        $wp_cache_incr_evict_on_force = true;

        $result = $this->check->check( $comment );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'duplicate count 1/3', $result->get_details() );
    }

    public function test_cache_path_eviction_retry_increments(): void {
        global $wp_using_ext_object_cache, $wp_cache_incr_force_false, $wp_cache_incr_evict_on_force;
        $wp_using_ext_object_cache = true;

        $comment = $this->make_comment( 'cache eviction retry', '13.13.13.13' );
        $this->check->check( $comment ); // count -> 1

        // incr() fails once but the key persists, so the re-add() also fails and
        // the retry incr() succeeds, counting this request (count -> 2).
        $wp_cache_incr_force_false    = 1;
        $wp_cache_incr_evict_on_force = false;

        $result = $this->check->check( $comment );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'duplicate count 2/3', $result->get_details() );
    }

    public function test_cache_path_eviction_retry_also_fails_counts_one(): void {
        global $wp_using_ext_object_cache, $wp_cache_incr_force_false, $wp_cache_incr_evict_on_force;
        $wp_using_ext_object_cache = true;

        $comment = $this->make_comment( 'cache double fail', '14.14.14.14' );
        $this->check->check( $comment ); // count -> 1

        // Both the incr() and the retry incr() fail; the request is still
        // counted as 1 rather than silently lost.
        $wp_cache_incr_force_false    = 2;
        $wp_cache_incr_evict_on_force = false;

        $result = $this->check->check( $comment );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'duplicate count 1/3', $result->get_details() );
    }

    // --- File-locked transient path window semantics ---

    public function test_window_reset_by_ttl_restarts_count(): void {
        $comment = $this->make_comment( 'window ttl reset', '15.15.15.15' );
        $this->check->check( $comment );
        $this->check->check( $comment );

        // Age this text's entry inside the per-IP map beyond the TTL (default
        // 3600s) so the next read prunes it and the counter restarts.
        $key = $this->find_dup_transient_key();
        $this->assertNotNull( $key );
        global $wp_transients;
        $data = $wp_transients[ $key ];
        foreach ( $data as $hash => $entry ) {
            if ( is_array( $entry ) ) {
                $data[ $hash ]['first_seen'] = time() - 4000;
            }
        }
        set_transient( $key, $data, 3600 );

        $result = $this->check->check( $comment );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'duplicate count 1/3', $result->get_details() );
    }

    public function test_corrupt_transient_resets_count(): void {
        $comment = $this->make_comment( 'corrupt transient', '16.16.16.16' );
        $this->check->check( $comment );

        // Overwrite the transient with a non-array (corrupt) value.
        $key = $this->find_dup_transient_key();
        $this->assertNotNull( $key );
        set_transient( $key, 'not-an-array', 3600 );

        $result = $this->check->check( $comment );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'duplicate count 1/3', $result->get_details() );
    }

    public function test_corrupt_transient_missing_keys_resets_count(): void {
        $comment = $this->make_comment( 'corrupt transient keys', '17.17.17.17' );
        $this->check->check( $comment );

        // Array missing the expected first_seen/count keys is treated as corrupt.
        $key = $this->find_dup_transient_key();
        $this->assertNotNull( $key );
        set_transient( $key, array( 'unexpected' => 'shape' ), 3600 );

        $result = $this->check->check( $comment );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'duplicate count 1/3', $result->get_details() );
    }

    public function test_lock_dir_creation_writes_guard_files(): void {
        $upload_dir = wp_upload_dir();
        $lock_dir   = $upload_dir['basedir'] . '/.asw-locks';

        // Remove the lock dir so the next check recreates it along with its
        // .htaccess/index.php guard files. scandir picks up dotfiles that a
        // plain glob('/*') would miss.
        if ( is_dir( $lock_dir ) ) {
            foreach ( scandir( $lock_dir ) as $entry ) {
                if ( '.' !== $entry && '..' !== $entry ) {
                    @unlink( $lock_dir . '/' . $entry );
                }
            }
            @rmdir( $lock_dir );
        }
        $this->assertDirectoryDoesNotExist( $lock_dir );

        $this->check->check( $this->make_comment( 'recreate lock dir', '18.18.18.18' ) );

        $this->assertDirectoryExists( $lock_dir );
        $this->assertFileExists( $lock_dir . '/.htaccess' );
        $this->assertFileExists( $lock_dir . '/index.php' );
    }

    /**
     * The whitespace-collapsing /u pattern returns null on a malformed subject.
     * Unguarded, trim( null ) emits a plugin-attributable deprecation - which
     * dev/smoke.sh's debug.log scan treats as a failure - on input any
     * unauthenticated commenter can send.
     */
    public function test_malformed_utf8_does_not_emit_a_deprecation(): void {
        $raised = null;
        set_error_handler(
            static function ( $errno, $errstr ) use ( &$raised ) {
                $raised = $errstr;
                return true;
            },
            E_ALL
        );

        try {
            $result = $this->check->check(
                $this->make_comment( "hello \xC3 world", '19.19.19.19' )
            );
        } finally {
            restore_error_handler();
        }

        $this->assertNull( $raised, 'malformed UTF-8 must not raise a PHP diagnostic' );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'duplicate count 1/3', $result->get_details() );
    }

    /**
     * With the collapse unguarded every malformed body normalizes to '', so two
     * unrelated comments from one IP share a hash bucket and cross-count as
     * duplicates of each other - a false positive, not a missed detection.
     */
    public function test_distinct_malformed_bodies_do_not_share_a_bucket(): void {
        $ip = '20.20.20.20';

        $first = $this->check->check(
            $this->make_comment( "first body \xC3 here", $ip )
        );
        $this->assertSame( 'duplicate count 1/3', $first->get_details() );

        $second = $this->check->check(
            $this->make_comment( "an entirely different body \xC3 here", $ip )
        );
        $this->assertSame( 'duplicate count 1/3', $second->get_details() );
        $this->assertFalse( $second->is_spam() );
    }

    /**
     * Sanitizing must not stop identical malformed bodies from counting as
     * duplicates of one another.
     */
    public function test_repeated_malformed_body_still_counts_as_duplicate(): void {
        $ip      = '21.21.21.21';
        $content = "buy now \xC3 cheap";

        $this->check->check( $this->make_comment( $content, $ip ) );
        $this->check->check( $this->make_comment( $content, $ip ) );
        $third = $this->check->check( $this->make_comment( $content, $ip ) );

        $this->assertTrue( $third->is_spam() );
        $this->assertSame( 'duplicate comment 3/3', $third->get_details() );
    }

    /**
     * ASW_Detector::evaluate() deliberately leaves comment_author_IP_hashed unset
     * when another plugin filtering pre_comment_approved hands in a non-string IP,
     * because hash_ip() is typed `string` and an array is a TypeError. This check's
     * own fallback must apply the same rule, or it re-raises that TypeError: the
     * comment still passes (run() catches it) but the Duplicate check is silently
     * disabled and every comment writes a plugin-attributable debug.log line, which
     * dev/smoke.sh's log scan treats as a failure.
     */
    public function test_non_string_ip_is_missing_data_instead_of_a_type_error(): void {
        $result = $this->check->check( array(
            'comment_content'   => 'hello world',
            'comment_author_IP' => array( '10.0.0.1' ),
        ) );

        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'missing data', $result->get_details() );
    }

    /**
     * The same rule on the pre-hashed field: a non-string there must not be used as
     * a transient key fragment.
     */
    public function test_non_string_hashed_ip_is_missing_data(): void {
        $result = $this->check->check( array(
            'comment_content'          => 'hello world',
            'comment_author_IP_hashed' => array( 'abc' ),
        ) );

        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'missing data', $result->get_details() );
    }

    // --- Per-IP transient storage shape ---

    /**
     * Every asw_dup_ transient key written by the file-locked path. The external
     * object cache path stores its integer counters under group-prefixed
     * asw:asw_dup_ keys, which do not start with asw_dup_ and are excluded here.
     *
     * @return string[]
     */
    private function dup_transient_keys(): array {
        return $this->transient_keys( 'asw_dup_' );
    }

    /**
     * The decoded content-hash => {count, first_seen} map of one per-IP
     * transient, without the bootstrap set_transient() stub's injected __ttl.
     *
     * @return array<string, array>
     */
    private function dup_entries( string $key ): array {
        global $wp_transients;
        $data = $wp_transients[ $key ];
        unset( $data['__ttl'] );
        return $data;
    }

    /**
     * Every group-prefixed cache counter key written by the object cache path.
     *
     * @return string[]
     */
    private function cache_counter_keys(): array {
        return $this->transient_keys( 'asw:asw_dup_' );
    }

    /**
     * The point of the rekey: distinct texts from one sender no longer add a
     * transient (two wp_options rows) each, they add an entry to that sender's
     * one map.
     */
    public function test_thirty_two_distinct_texts_share_one_transient(): void {
        $ip = '30.30.30.30';
        for ( $i = 0; $i < ASW_Duplicate_Check::MAX_ENTRIES_PER_IP; $i++ ) {
            $this->check->check( $this->make_comment( 'text number ' . $i, $ip ) );
        }

        $keys = $this->dup_transient_keys();
        $this->assertCount( 1, $keys, 'One hashed IP must own exactly one transient' );
        $this->assertCount( ASW_Duplicate_Check::MAX_ENTRIES_PER_IP, $this->dup_entries( $keys[0] ) );
    }

    /**
     * The cap bounds the storage, and the value it pins is load-bearing: raising
     * MAX_ENTRIES_PER_IP is what the fixed serialized size depends on, so a bump
     * must be a deliberate edit here too rather than a silent one.
     */
    public function test_entry_cap_is_thirty_two(): void {
        $this->assertSame( 32, ASW_Duplicate_Check::MAX_ENTRIES_PER_IP );
    }

    /**
     * A 33rd distinct text must not grow the map, and among equally repeated
     * entries the one evicted is the one with the oldest first_seen.
     */
    public function test_thirty_third_text_evicts_the_oldest_of_the_least_repeated(): void {
        $ip = '31.31.31.31';
        for ( $i = 0; $i < ASW_Duplicate_Check::MAX_ENTRIES_PER_IP; $i++ ) {
            $this->check->check( $this->make_comment( 'capped text ' . $i, $ip ) );
        }

        $key = $this->find_dup_transient_key();
        $this->assertNotNull( $key );

        // Age one entry in the middle of the map (still well inside the 3600s
        // TTL) so the eviction is decided by first_seen rather than by insertion
        // order alone. Every entry is at count 1, so the count comparison ties
        // and first_seen breaks it.
        global $wp_transients;
        $data     = $wp_transients[ $key ];
        $hashes   = array_keys( $this->dup_entries( $key ) );
        $oldest   = $hashes[5];
        $survivor = $hashes[0];
        $data[ $oldest ]['first_seen'] = time() - 100;
        set_transient( $key, $data, 3600 );

        $this->check->check( $this->make_comment( 'capped text overflow', $ip ) );

        $this->assertCount( 1, $this->dup_transient_keys() );
        $entries = $this->dup_entries( $key );
        $this->assertCount( ASW_Duplicate_Check::MAX_ENTRIES_PER_IP, $entries );
        $this->assertArrayNotHasKey( $oldest, $entries, 'The oldest of the equally-counted entries must be evicted' );
        $this->assertArrayHasKey( $survivor, $entries, 'A younger entry must survive the eviction' );
    }

    /**
     * The entry being written is excluded from victim selection, and that
     * exclusion is load-bearing rather than defensive: when every slot is held
     * by a text repeated more often than the newcomer, a brand-new text is the
     * strict minimum and would evict *itself*. increment_stored_count() then
     * reads a key that is no longer in the map - an undefined-index warning and
     * a null out of a `: int` method, i.e. a TypeError swallowed by
     * ASW_Detector::run()'s fail-open catch - so the check would silently stop
     * counting while appending a plugin-attributable line to the very debug.log
     * dev/smoke.sh scans. The input that reaches it is routine, not adversarial:
     * a busy or shared IP where 32 texts have each been seen twice.
     */
    public function test_a_new_text_survives_its_own_write_against_a_fully_repeated_map(): void {
        $ip = '52.52.52.52';
        for ( $i = 0; $i < ASW_Duplicate_Check::MAX_ENTRIES_PER_IP; $i++ ) {
            $this->check->check( $this->make_comment( 'repeated filler ' . $i, $ip ) );
            $this->check->check( $this->make_comment( 'repeated filler ' . $i, $ip ) );
        }

        $key = $this->find_dup_transient_key();
        $this->assertNotNull( $key );
        foreach ( $this->dup_entries( $key ) as $entry ) {
            $this->assertSame( 2, $entry['count'], 'Every slot must be held by a text repeated more often than the newcomer' );
        }

        $first = $this->check->check( $this->make_comment( 'the newcomer', $ip ) );
        $this->assertFalse( $first->is_spam() );
        $this->assertSame( 'duplicate count 1/3', $first->get_details() );

        // The count came back, so the entry existed at the end of its own write.
        // Assert it was also stored: a second submission must find it at 1 and
        // report 2, not seed a fresh entry and report 1 again.
        $entries = $this->dup_entries( $key );
        $this->assertCount( ASW_Duplicate_Check::MAX_ENTRIES_PER_IP, $entries );
        $this->assertCount( 1, array_filter( $entries, static fn( array $entry ): bool => 1 === $entry['count'] ), 'The newcomer must hold the only count-1 slot' );

        $second = $this->check->check( $this->make_comment( 'the newcomer', $ip ) );
        $this->assertSame( 'duplicate count 2/3', $second->get_details() );
    }

    /**
     * The sharp edge of that exclusion, pinned so it is a known boundary rather
     * than a surprise. The docblock's filler wording implies a repeat needs more
     * than MAX_ENTRIES_PER_IP distinct texts between it and the last one to stay
     * at count 1. Against a wall of texts already repeated twice it needs
     * exactly **one**: the newcomer cannot be its own victim, so a count-1 text
     * evicts a count-2 counter instead, and the body and the throwaway then take
     * turns being the only count-1 entry and evicting each other. Neither ever
     * reaches count 2, and the wall never moves.
     *
     * Cost to the sender: 62 comments to build the wall, then one throwaway per
     * repeat. Admitting the newcomer to victim selection closes this and opens
     * something worse - the wall would refuse every new text outright, so a body
     * repeated against it is never counted at all and the bypass costs nothing
     * per repeat. test_repeats_interleaved_with_a_full_map_of_fillers_are_flagged()
     * is the control: without the wall, plain repetition is still flagged.
     */
    public function test_one_filler_per_repeat_holds_a_body_at_count_one_against_a_repeated_wall(): void {
        $ip = '46.46.46.46';

        // The body under test goes in first, so it is also the oldest entry -
        // the tie-break eviction falls back on once every count is equal.
        $this->check->check( $this->make_comment( 'the spam body', $ip ) );
        $this->check->check( $this->make_comment( 'the spam body', $ip ) );
        for ( $i = 1; $i < ASW_Duplicate_Check::MAX_ENTRIES_PER_IP; $i++ ) {
            $this->check->check( $this->make_comment( 'wall text ' . $i, $ip ) );
            $this->check->check( $this->make_comment( 'wall text ' . $i, $ip ) );
        }

        for ( $round = 0; $round < 5; $round++ ) {
            $this->check->check( $this->make_comment( 'throwaway ' . $round, $ip ) );
            $result = $this->check->check( $this->make_comment( 'the spam body', $ip ) );
            $this->assertFalse( $result->is_spam(), 'Known boundary: one throwaway per repeat holds the body at count 1' );
            $this->assertSame( 'duplicate count 1/3', $result->get_details() );
        }

        // The wall is what the policy protects, and it is exactly what makes the
        // body and the throwaway the only spendable pair.
        $entries = $this->dup_entries( $this->find_dup_transient_key() );
        $this->assertCount( ASW_Duplicate_Check::MAX_ENTRIES_PER_IP, $entries );
        $this->assertCount(
            ASW_Duplicate_Check::MAX_ENTRIES_PER_IP - 1,
            array_filter( $entries, static fn( array $entry ): bool => 2 === $entry['count'] ),
            'Every count-2 wall entry must survive; only the count-1 slot churns'
        );
    }

    /**
     * Eviction is by count, not by age. first_seen is the window anchor and is
     * never refreshed, so the oldest entry is the longest-tracked one - the
     * counter closest to the threshold. Evicting by age would discard exactly
     * that, which is why the repeated text must outlive the seen-once fillers
     * even though it is the oldest entry in the map.
     */
    public function test_repeated_text_outlives_a_seen_once_entry_on_eviction(): void {
        $ip = '32.32.32.32';
        // The repeated text is inserted first, so it is the oldest entry.
        $this->check->check( $this->make_comment( 'repeated text', $ip ) );
        $this->check->check( $this->make_comment( 'repeated text', $ip ) );

        for ( $i = 1; $i < ASW_Duplicate_Check::MAX_ENTRIES_PER_IP; $i++ ) {
            $this->check->check( $this->make_comment( 'filler text ' . $i, $ip ) );
        }
        $this->check->check( $this->make_comment( 'the overflowing text', $ip ) );

        $result = $this->check->check( $this->make_comment( 'repeated text', $ip ) );
        $this->assertTrue( $result->is_spam(), 'The accumulating counter must survive eviction' );
        $this->assertSame( 'duplicate comment 3/3', $result->get_details() );
    }

    /**
     * A repeat that has already been seen twice survives a full map's worth of
     * interleaved fillers, because count outranks age. This is the half of the
     * interleaving attack that count-based eviction closes; the half it cannot
     * close is pinned by test_a_never_repeated_body_can_be_flushed_by_fillers().
     */
    public function test_repeats_interleaved_with_a_full_map_of_fillers_are_flagged(): void {
        $ip     = '42.42.42.42';
        $filler = 0;

        $this->check->check( $this->make_comment( 'the spam body', $ip ) );
        $this->check->check( $this->make_comment( 'the spam body', $ip ) );

        for ( $i = 0; $i <= ASW_Duplicate_Check::MAX_ENTRIES_PER_IP; $i++ ) {
            $this->check->check( $this->make_comment( 'unique filler ' . $filler++, $ip ) );
        }

        $result = $this->check->check( $this->make_comment( 'the spam body', $ip ) );
        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'duplicate comment 3/3', $result->get_details() );
    }

    /**
     * The residual limit of any fixed-size map, pinned so it is a known boundary
     * rather than a surprise: a body that has never yet been repeated is a
     * count-1 entry like every filler around it, so more than MAX_ENTRIES_PER_IP
     * distinct texts between every repeat flush it and keep it at 1. No bound
     * can prevent that - anything able to insert unlimited distinct keys can
     * flush any bounded map.
     *
     * The 33 fillers are driven from one sender here, but that is the *expensive*
     * way to reach this: on a shared comment_author_IP the same 33 distinct
     * texts are other people's ordinary comments, so the flush costs the sender
     * nothing and needs no evasion at all. Count-based eviction closes that
     * stream only from count 2 upward - see
     * test_other_traffic_on_a_shared_ip_does_not_reset_a_duplicate_count(),
     * which is the count-2 half. It costs nothing on the object cache path,
     * which is still keyed per IP + text.
     */
    public function test_a_never_repeated_body_can_be_flushed_by_fillers(): void {
        $ip     = '45.45.45.45';
        $filler = 0;

        for ( $round = 0; $round < 3; $round++ ) {
            $result = $this->check->check( $this->make_comment( 'the spam body', $ip ) );
            for ( $i = 0; $i <= ASW_Duplicate_Check::MAX_ENTRIES_PER_IP; $i++ ) {
                $this->check->check( $this->make_comment( 'unique filler ' . $filler++, $ip ) );
            }
        }

        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'duplicate count 1/3', $result->get_details() );
    }

    /**
     * The same defect seen from the other side: on a shared comment_author_IP
     * (a CDN that does not restore the real address, CGNAT, the CF7 remote_ip
     * meta) the evicting traffic is other people's ordinary comments, and the
     * spammer sends nothing but duplicates. Their count must survive it.
     *
     * The two repeats before the background traffic are deliberate: this covers
     * the count-2 case, which is the only one count-based eviction closes. The
     * count-1 case - a body posted once, then flushed by a full map of other
     * people's comments before it can repeat - is not closed and is pinned as a
     * known boundary by test_a_never_repeated_body_can_be_flushed_by_fillers().
     */
    public function test_other_traffic_on_a_shared_ip_does_not_reset_a_duplicate_count(): void {
        $ip = '43.43.43.43';
        $this->check->check( $this->make_comment( 'the spam body', $ip ) );
        $this->check->check( $this->make_comment( 'the spam body', $ip ) );

        for ( $i = 0; $i <= ASW_Duplicate_Check::MAX_ENTRIES_PER_IP; $i++ ) {
            $this->check->check( $this->make_comment( 'somebody else comment ' . $i, $ip ) );
        }

        $result = $this->check->check( $this->make_comment( 'the spam body', $ip ) );
        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'duplicate comment 3/3', $result->get_details() );
    }

    /**
     * The accepted residual trade-off, stated as behaviour: only once every slot
     * is held by a text repeated at least as often as the newcomer does an entry
     * carrying a verdict get dropped.
     */
    public function test_evicted_text_starts_over_when_every_entry_is_equally_repeated(): void {
        $ip = '33.33.33.33';
        // Every entry ends at count 1, so the first text is the oldest of the
        // least repeated and the 33rd distinct text evicts it.
        $this->check->check( $this->make_comment( 'evictable text', $ip ) );
        for ( $i = 1; $i < ASW_Duplicate_Check::MAX_ENTRIES_PER_IP; $i++ ) {
            $this->check->check( $this->make_comment( 'other text ' . $i, $ip ) );
        }
        $this->check->check( $this->make_comment( 'the overflowing text', $ip ) );

        $result = $this->check->check( $this->make_comment( 'evictable text', $ip ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'duplicate count 1/3', $result->get_details() );
    }

    /**
     * A map that somehow arrived oversized - a value written by a version with a
     * larger cap - must converge back to the bound rather than stay oversized
     * for good, which is why the eviction is a loop and not a single unset.
     */
    public function test_an_oversized_map_is_trimmed_back_to_the_cap(): void {
        $ip = '44.44.44.44';
        $this->check->check( $this->make_comment( 'seed entry', $ip ) );

        $key = $this->find_dup_transient_key();
        $this->assertNotNull( $key );

        $data = $this->dup_entries( $key );
        for ( $i = 0; $i < ASW_Duplicate_Check::MAX_ENTRIES_PER_IP + 10; $i++ ) {
            $data[ hash( 'sha256', 'legacy entry ' . $i ) ] = array(
                'count'      => 1,
                'first_seen' => time(),
            );
        }
        set_transient( $key, $data, 3600 );

        $this->check->check( $this->make_comment( 'a brand new text', $ip ) );

        $this->assertCount( ASW_Duplicate_Check::MAX_ENTRIES_PER_IP, $this->dup_entries( $key ) );
    }

    /**
     * Convergence must not depend on a *new* text arriving. Gating the trim on
     * the new-hash branch leaves a sender who only ever repeats texts already in
     * the map holding the oversized row for good - which is the one stream the
     * convergence exists for, since the bound is the whole point of the rekey.
     */
    public function test_an_oversized_map_is_trimmed_when_an_existing_text_repeats(): void {
        $ip = '45.45.45.45';
        $this->check->check( $this->make_comment( 'seed entry', $ip ) );

        $key = $this->find_dup_transient_key();
        $this->assertNotNull( $key );

        $data = $this->dup_entries( $key );
        for ( $i = 0; $i < ASW_Duplicate_Check::MAX_ENTRIES_PER_IP + 10; $i++ ) {
            $data[ hash( 'sha256', 'legacy entry ' . $i ) ] = array(
                'count'      => 1,
                'first_seen' => time(),
            );
        }
        set_transient( $key, $data, 3600 );

        // A repeat of a hash already in the map, not a new one.
        $result = $this->check->check( $this->make_comment( 'seed entry', $ip ) );

        $entries = $this->dup_entries( $key );
        $this->assertCount( ASW_Duplicate_Check::MAX_ENTRIES_PER_IP, $entries );

        // The repeated text is the entry being written, so it must survive its
        // own trim rather than be evicted as one more count-1 candidate.
        $this->assertArrayHasKey( hash( 'sha256', 'seed entry' ), $entries );
        $this->assertSame( 'duplicate count 2/3', $result->get_details() );
    }

    /**
     * A first_seen dated in the future - what a backwards clock step (an NTP
     * correction, a VM resume) leaves behind - is never expired by an age test,
     * so it would be kept for good. Because the row's lifetime is derived from
     * the newest first_seen, it would also stretch the transient past MAX_TTL and
     * immortalise every other counter in that IP's map.
     */
    public function test_a_future_dated_entry_is_dropped_and_does_not_extend_the_row(): void {
        $ip = '47.47.47.47';
        $this->check->check( $this->make_comment( 'clock skewed text', $ip ) );
        $this->check->check( $this->make_comment( 'clock skewed text', $ip ) );

        $key = $this->find_dup_transient_key();
        $this->assertNotNull( $key );

        global $wp_transients;
        $data                        = $wp_transients[ $key ];
        $hash                        = array_keys( $this->dup_entries( $key ) )[0];
        $data[ $hash ]['first_seen'] = time() + 31536000;
        set_transient( $key, $data, 3600 );

        $result = $this->check->check( $this->make_comment( 'clock skewed text', $ip ) );

        // The unusable anchor is discarded, so this text starts a fresh window
        // rather than carrying a count that can never expire.
        $this->assertSame( 'duplicate count 1/3', $result->get_details() );
        $this->assertLessThanOrEqual( 3600, $wp_transients[ $key ]['__ttl'] );
        $this->assertEqualsWithDelta( time(), $this->dup_entries( $key )[ $hash ]['first_seen'], 5 );
    }

    /**
     * The window is fixed, not sliding: first_seen is the anchor and must not be
     * refreshed on an increment. Refreshing it would let a commenter who repeats
     * a short body just inside the TTL be tracked forever and eventually
     * over-blocked, and it would also break the count-based eviction's tie-break.
     */
    public function test_incrementing_does_not_refresh_the_window_anchor(): void {
        $ip = '46.46.46.46';
        $this->check->check( $this->make_comment( 'anchored text', $ip ) );

        $key = $this->find_dup_transient_key();
        $this->assertNotNull( $key );

        global $wp_transients;
        $data                          = $wp_transients[ $key ];
        $hash                          = array_keys( $this->dup_entries( $key ) )[0];
        $anchor                        = time() - 1000;
        $data[ $hash ]['first_seen']   = $anchor;
        set_transient( $key, $data, 3600 );

        $this->check->check( $this->make_comment( 'anchored text', $ip ) );

        $entries = $this->dup_entries( $key );
        $this->assertSame( $anchor, $entries[ $hash ]['first_seen'], 'first_seen must stay the original anchor' );
        $this->assertSame( 2, $entries[ $hash ]['count'] );
    }

    /**
     * The prune is inclusive at the boundary: an entry exactly $ttl old has had
     * its whole window and must be dropped, not kept for one more second.
     */
    public function test_an_entry_exactly_ttl_old_is_dropped(): void {
        $ip = '47.47.47.47';
        $this->check->check( $this->make_comment( 'boundary text', $ip ) );
        $this->check->check( $this->make_comment( 'boundary text', $ip ) );

        $key = $this->find_dup_transient_key();
        $this->assertNotNull( $key );

        global $wp_transients;
        $data                        = $wp_transients[ $key ];
        $hash                        = array_keys( $this->dup_entries( $key ) )[0];
        $data[ $hash ]['first_seen'] = time() - 3600;
        set_transient( $key, $data, 3600 );

        $result = $this->check->check( $this->make_comment( 'boundary text', $ip ) );
        $this->assertSame( 'duplicate count 1/3', $result->get_details() );
    }

    /**
     * One second inside the window, the same entry must survive - otherwise the
     * assertion above would also hold for a prune that dropped everything.
     */
    public function test_an_entry_one_second_inside_the_window_survives(): void {
        $ip = '48.48.48.48';
        $this->check->check( $this->make_comment( 'boundary text', $ip ) );
        $this->check->check( $this->make_comment( 'boundary text', $ip ) );

        $key = $this->find_dup_transient_key();
        $this->assertNotNull( $key );

        global $wp_transients;
        $data                        = $wp_transients[ $key ];
        $hash                        = array_keys( $this->dup_entries( $key ) )[0];
        $data[ $hash ]['first_seen'] = time() - 3599;
        set_transient( $key, $data, 3600 );

        $result = $this->check->check( $this->make_comment( 'boundary text', $ip ) );
        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'duplicate comment 3/3', $result->get_details() );
    }

    /**
     * Each surviving entry is rebuilt to exactly {count, first_seen}, so a
     * stored entry carrying extra members cannot smuggle them into the next
     * write and grow the row without bound.
     */
    public function test_extra_members_inside_an_entry_are_not_written_back(): void {
        $ip = '49.49.49.49';
        $this->check->check( $this->make_comment( 'extra members', $ip ) );

        $key = $this->find_dup_transient_key();
        $this->assertNotNull( $key );

        global $wp_transients;
        $data                      = $wp_transients[ $key ];
        $hash                      = array_keys( $this->dup_entries( $key ) )[0];
        $data[ $hash ]['smuggled'] = str_repeat( 'x', 64 );
        set_transient( $key, $data, 3600 );

        $this->check->check( $this->make_comment( 'extra members', $ip ) );

        $entry = $this->dup_entries( $key )[ $hash ];
        $this->assertSame( array( 'count', 'first_seen' ), array_keys( $entry ) );
    }

    /**
     * A non-integer first_seen is as unusable as a non-integer count: the
     * arithmetic in the prune and in the eviction tie-break both need an int, so
     * such an entry is dropped rather than coerced.
     */
    public function test_an_entry_with_a_non_integer_first_seen_is_dropped(): void {
        $ip = '50.50.50.50';
        $this->check->check( $this->make_comment( 'bad anchor', $ip ) );
        $this->check->check( $this->make_comment( 'bad anchor', $ip ) );

        $key = $this->find_dup_transient_key();
        $this->assertNotNull( $key );

        global $wp_transients;
        $data                        = $wp_transients[ $key ];
        $hash                        = array_keys( $this->dup_entries( $key ) )[0];
        $data[ $hash ]['first_seen'] = 'yesterday';
        set_transient( $key, $data, 3600 );

        $raised = null;
        set_error_handler(
            static function ( $errno, $errstr ) use ( &$raised ) {
                $raised = $errstr;
                return true;
            },
            E_ALL
        );

        try {
            $result = $this->check->check( $this->make_comment( 'bad anchor', $ip ) );
        } finally {
            restore_error_handler();
        }

        $this->assertNull( $raised, 'a non-integer first_seen must not raise a PHP diagnostic' );
        $this->assertSame( 'duplicate count 1/3', $result->get_details() );
    }

    /**
     * The transient's own lifetime is recomputed from the newest entry, so the
     * row expires with its youngest window instead of being kept alive
     * indefinitely by repeats of one long-anchored text.
     */
    public function test_transient_lifetime_follows_the_newest_entry(): void {
        $ip = '51.51.51.51';
        $this->check->check( $this->make_comment( 'aged text', $ip ) );

        $key = $this->find_dup_transient_key();
        $this->assertNotNull( $key );

        global $wp_transients;
        $data                        = $wp_transients[ $key ];
        $hash                        = array_keys( $this->dup_entries( $key ) )[0];
        $data[ $hash ]['first_seen'] = time() - 3000;
        set_transient( $key, $data, 3600 );

        // Only the aged entry exists, so the rewritten lifetime must be the
        // remainder of its window, not a fresh full TTL.
        $this->check->check( $this->make_comment( 'aged text', $ip ) );

        $this->assertEqualsWithDelta( 600, $wp_transients[ $key ]['__ttl'], 5 );

        // A brand new entry is now the newest, so the row's life goes back to
        // the full TTL.
        $this->check->check( $this->make_comment( 'a fresh text', $ip ) );

        $this->assertEqualsWithDelta( 3600, $wp_transients[ $key ]['__ttl'], 5 );
    }

    public function test_two_ips_produce_two_transients(): void {
        $this->check->check( $this->make_comment( 'shared text', '34.34.34.34' ) );
        $this->check->check( $this->make_comment( 'shared text', '35.35.35.35' ) );

        $this->assertCount( 2, $this->dup_transient_keys() );
    }

    /**
     * Entries of one map must not interfere with one another: other texts from
     * the same IP between the repeats neither reset nor advance the count.
     */
    public function test_interleaved_texts_keep_the_duplicate_count(): void {
        $ip = '36.36.36.36';
        $this->check->check( $this->make_comment( 'repeat me', $ip ) );
        $this->check->check( $this->make_comment( 'filler one', $ip ) );
        $this->check->check( $this->make_comment( 'repeat me', $ip ) );
        $this->check->check( $this->make_comment( 'filler two', $ip ) );
        $result = $this->check->check( $this->make_comment( 'repeat me', $ip ) );

        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'duplicate comment 3/3', $result->get_details() );
    }

    /**
     * The window is per entry, not per transient: aging one text's entry past the
     * TTL restarts that text alone.
     */
    public function test_expired_entry_resets_while_a_younger_entry_keeps_its_count(): void {
        $ip = '37.37.37.37';
        $this->check->check( $this->make_comment( 'stale text', $ip ) );
        $this->check->check( $this->make_comment( 'stale text', $ip ) );
        $this->check->check( $this->make_comment( 'fresh text', $ip ) );

        $key = $this->find_dup_transient_key();
        $this->assertNotNull( $key );

        global $wp_transients;
        $data  = $wp_transients[ $key ];
        $stale = array_keys( $this->dup_entries( $key ) )[0];
        $data[ $stale ]['first_seen'] = time() - 4000;
        set_transient( $key, $data, 3600 );

        $restarted = $this->check->check( $this->make_comment( 'stale text', $ip ) );
        $this->assertSame( 'duplicate count 1/3', $restarted->get_details() );

        $kept = $this->check->check( $this->make_comment( 'fresh text', $ip ) );
        $this->assertSame( 'duplicate count 2/3', $kept->get_details() );
    }

    /**
     * A corrupt value must degrade to an empty map rather than a PHP diagnostic:
     * the transient is attacker-influenced state and dev/smoke.sh's debug.log
     * scan treats a plugin-attributable notice as a failure.
     */
    public function test_non_array_transient_value_emits_no_diagnostic(): void {
        $ip = '38.38.38.38';
        $this->check->check( $this->make_comment( 'corrupt value', $ip ) );

        $key = $this->find_dup_transient_key();
        $this->assertNotNull( $key );
        set_transient( $key, 'not-an-array', 3600 );

        $raised = null;
        set_error_handler(
            static function ( $errno, $errstr ) use ( &$raised ) {
                $raised = $errstr;
                return true;
            },
            E_ALL
        );

        try {
            $result = $this->check->check( $this->make_comment( 'corrupt value', $ip ) );
        } finally {
            restore_error_handler();
        }

        $this->assertNull( $raised, 'a corrupt transient must not raise a PHP diagnostic' );
        $this->assertSame( 'duplicate count 1/3', $result->get_details() );
    }

    /**
     * Validation is per entry, so one malformed entry costs only its own count -
     * the rest of that IP's map survives, silently.
     */
    public function test_malformed_entry_is_dropped_and_the_valid_entry_survives(): void {
        $ip = '39.39.39.39';
        $this->check->check( $this->make_comment( 'valid entry', $ip ) );
        $this->check->check( $this->make_comment( 'valid entry', $ip ) );
        $this->check->check( $this->make_comment( 'broken entry', $ip ) );

        $key = $this->find_dup_transient_key();
        $this->assertNotNull( $key );

        global $wp_transients;
        $data              = $wp_transients[ $key ];
        $hashes            = array_keys( $this->dup_entries( $key ) );
        $data[ $hashes[1] ] = 'not-an-entry';
        $data['stray']      = array( 'count' => 'two', 'first_seen' => time() );
        set_transient( $key, $data, 3600 );

        $raised = null;
        set_error_handler(
            static function ( $errno, $errstr ) use ( &$raised ) {
                $raised = $errstr;
                return true;
            },
            E_ALL
        );

        try {
            $valid  = $this->check->check( $this->make_comment( 'valid entry', $ip ) );
            $broken = $this->check->check( $this->make_comment( 'broken entry', $ip ) );
        } finally {
            restore_error_handler();
        }

        $this->assertNull( $raised, 'a malformed entry must not raise a PHP diagnostic' );
        $this->assertTrue( $valid->is_spam() );
        $this->assertSame( 'duplicate comment 3/3', $valid->get_details() );
        $this->assertSame( 'duplicate count 1/3', $broken->get_details() );
        $this->assertArrayNotHasKey( 'stray', $this->dup_entries( $key ) );
    }

    /**
     * uninstall.php deletes the transients with a
     * `_transient_asw_dup_%` LIKE, so the rekey must keep the prefix.
     */
    public function test_transient_key_keeps_the_uninstall_prefix(): void {
        $this->check->check( $this->make_comment( 'prefix contract', '40.40.40.40' ) );

        $key = $this->find_dup_transient_key();
        $this->assertNotNull( $key );
        $this->assertStringStartsWith( 'asw_dup_', $key );

        global $wp_options;
        $this->assertArrayHasKey( '_transient_' . $key, $wp_options );
    }

    /**
     * The object cache path is deliberately NOT rekeyed: wp_cache_incr() can only
     * bump an integer atomically, so it keeps one counter per IP + text and
     * writes no bare asw_dup_ transient at all.
     */
    public function test_cache_path_still_counts_per_ip_and_text(): void {
        global $wp_using_ext_object_cache, $wp_transients;
        $wp_using_ext_object_cache = true;

        $ip    = '41.41.41.41';
        $first = $this->check->check( $this->make_comment( 'cache text one', $ip ) );
        $this->assertSame( 'duplicate count 1/3', $first->get_details() );

        $second = $this->check->check( $this->make_comment( 'cache text two', $ip ) );
        $this->assertSame( 'duplicate count 1/3', $second->get_details() );

        $cache_keys = $this->cache_counter_keys();
        $this->assertCount( 2, $cache_keys, 'One counter per IP + text pair' );
        foreach ( $cache_keys as $cache_key ) {
            $this->assertSame( 1, $wp_transients[ $cache_key ] );
        }
        $this->assertSame( array(), $this->dup_transient_keys(), 'The cache path must write no per-IP transient' );
    }
}
