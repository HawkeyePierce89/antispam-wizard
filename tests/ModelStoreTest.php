<?php
/**
 * Tests for ASW_Model_Store, the lazy per-writing-system model loader.
 */

use PHPUnit\Framework\TestCase;

/**
 * Records which corpus files were asked for and returns nothing, so a key or
 * laziness assertion costs no corpus training.
 */
class ASW_Recording_Training_Data extends ASW_Training_Data {

    /** @var string[] */
    public array $load_samples_calls = array();

    /** @var string[] */
    public array $load_script_calls = array();

    public function load_samples( string $file_path ): array {
        $this->load_samples_calls[] = $file_path;
        return array();
    }

    public function load_script_file_samples( string $script, string $category ): array {
        $this->load_script_calls[] = $script . ':' . $category;
        return array();
    }
}

/**
 * Returns a fixed two-line corpus for every script, so a unit has a real
 * classifier and real sample tokens without reading the shipped corpus.
 */
class ASW_Fixed_Training_Data extends ASW_Training_Data {

    /** @var int Number of corpus loads performed. */
    public int $loads = 0;

    public function load_samples( string $file_path ): array {
        ++$this->loads;
        return array();
    }

    public function load_script_file_samples( string $script, string $category ): array {
        ++$this->loads;

        return 'spam' === $category
            ? array( 'buy cheap pills online now', 'cheap pills discount pharmacy' )
            : array( 'great article thanks for sharing', 'thanks for the detailed post' );
    }
}

/**
 * A corpus large enough that the unit built from it can be driven over a
 * lowered item limit and pruned back under it in several rounds.
 *
 * Every line is novel vocabulary, so the dictionary is the bulk of the unit --
 * which is the shape the real overflow has: ASW_Naive_Bayes caps the token
 * count, not the token length, so a site's learned history decides the
 * classifier's bytes.
 */
class ASW_Bulk_Training_Data extends ASW_Training_Data {

    public function load_samples( string $file_path ): array {
        return array();
    }

    public function load_script_file_samples( string $script, string $category ): array {
        if ( 'cyrillic' !== $script ) {
            return array();
        }

        // A local LCG rather than mt_srand(), which would perturb the
        // process-global sequence every later test draws from.
        $seed  = 'spam' === $category ? 8675309 : 5551212;
        $lines = array();
        // The two categories are deliberately different sizes. With equal
        // dictionaries and equal document counts every body of unknown words
        // classifies 0.5/0.5 whichever model scores it, and the test that pins
        // the prune being handed back to the live classifier could not fail.
        $count = 'spam' === $category ? 140 : 110;

        for ( $line = 0; $line < $count; $line++ ) {
            $words = array();

            for ( $word = 0; $word < 14; $word++ ) {
                $seed    = ( $seed * 1103515245 + 12345 ) % 2147483648;
                $words[] = 'zt' . base_convert( (string) $seed, 10, 36 );
            }

            // A band of repeated tokens over the novel ones, so the dictionary
            // carries a real frequency spread rather than one flat tie at
            // count 1 -- which is what makes "the least frequent half is what
            // halving spends" an assertion that can fail.
            $words[] = 'ztband' . ( $line % 7 );
            $words[] = 'ztband' . ( $line % 3 );

            $lines[] = implode( ' ', $words );
        }

        return $lines;
    }
}

/**
 * Corpus whose tokens stress the two shapes the packed representation reasons
 * about but ASW_Fixed_Training_Data never produces: a **bare numeric token**,
 * whose `token_counts` key PHP canonicalises to an int, and **CJK bigrams**,
 * which come off the n-gram path rather than the word path.
 */
class ASW_Token_Shape_Training_Data extends ASW_Training_Data {

    public function load_samples( string $file_path ): array {
        return array();
    }

    public function load_script_file_samples( string $script, string $category ): array {
        if ( 'cjk' === $script ) {
            return 'spam' === $category
                ? array( '便宜药品在线购买', '在线购买折扣药品' )
                : array( '感谢分享这篇文章', '这篇文章写得很好' );
        }

        if ( 'cyrillic' !== $script ) {
            return array();
        }

        // 2024 is four digits, so ASW_Tokenizer's PHONE_PATTERN (9-15) leaves
        // it alone and it reaches token_counts as a numeric array key.
        return 'spam' === $category
            ? array( 'скидка 2024 акция купить', 'акция 2024 дешево купить' )
            : array( 'отличная статья спасибо автору', 'спасибо за подробный разбор' );
    }
}

/**
 * Writes a training sample while the store is loading, which is the mid-load
 * key race the never-cache rule exists for.
 */
class ASW_Racing_Training_Data extends ASW_Training_Data {

    /** @var ASW_Sample_Repository|null */
    public ?ASW_Sample_Repository $racing_repository = null;

    /** @var bool */
    private bool $raced = false;

    public function load_samples( string $file_path ): array {
        return array();
    }

    public function load_script_file_samples( string $script, string $category ): array {
        if ( ! $this->raced && null !== $this->racing_repository ) {
            $this->raced = true;
            $this->racing_repository->insert( $script, 'spam', 'a training write that landed mid-load', 9001 );
        }

        return 'spam' === $category ? array( 'mid load spam sample' ) : array( 'mid load ham sample' );
    }
}

/**
 * Reads no corpus file but keeps the parent's repository merge, so a unit is
 * built from the sample table alone -- which is what makes a failed sample read
 * observable in the unit that comes out.
 */
class ASW_File_Free_Training_Data extends ASW_Training_Data {

    public function load_samples( string $file_path ): array {
        return array();
    }
}

/**
 * Returns a distinct legacy corpus as well as the per-script one, so the
 * combined unit's narrowed similarity corpus is distinguishable from the
 * per-script lists it no longer carries.
 */
class ASW_Legacy_And_Script_Training_Data extends ASW_Training_Data {

    const LEGACY_SPAM = 'legacy spam corpus line';
    const LEGACY_HAM  = 'legacy ham corpus line';

    /** @var int Number of corpus loads performed. */
    public int $loads = 0;

    public function load_samples( string $file_path ): array {
        ++$this->loads;

        return str_contains( $file_path, 'spam-samples.txt' )
            ? array( self::LEGACY_SPAM )
            : array( self::LEGACY_HAM );
    }

    public function load_script_file_samples( string $script, string $category ): array {
        ++$this->loads;

        return 'spam' === $category
            ? array( 'buy cheap pills online now', 'cheap pills discount pharmacy' )
            : array( 'great article thanks for sharing', 'thanks for the detailed post' );
    }
}

/**
 * Counts script detections so the per-request memoisation is observable.
 */
class ASW_Counting_Model_Store extends ASW_Model_Store {

    /** @var int */
    public int $detections = 0;

    protected function detect_script( string $text ): string {
        ++$this->detections;
        return parent::detect_script( $text );
    }
}

class ModelStoreTest extends TestCase {

    /** @var array<string, int> Original mtimes of corpus files this test moved. */
    private array $restore_mtimes = array();

    protected function setUp(): void {
        parent::setUp();
        global $wpdb, $wp_options, $wp_transients, $wp_transient_reads, $wp_transient_delete_fails,
            $wp_on_set_transient, $wp_transient_expired, $wp_update_option_fails, $asw_zlib_unavailable,
            $asw_gzcompress_fails, $wp_using_ext_object_cache, $asw_max_cached_unit_bytes,
            $asw_max_inflated_unit_bytes;
        $wpdb->reset_tables();
        $wp_options                = array();
        $wp_transients             = array();
        $wp_transient_reads        = array();
        $wp_transient_delete_fails = false;
        $wp_on_set_transient       = null;
        $wp_transient_expired      = false;
        $wp_update_option_fails    = false;
        $asw_zlib_unavailable      = false;
        $asw_gzcompress_fails      = false;
        $wp_using_ext_object_cache = false;
        $asw_max_cached_unit_bytes   = null;
        $asw_max_inflated_unit_bytes = null;
    }

    protected function tearDown(): void {
        global $wpdb, $wp_options, $wp_transients, $wp_transient_reads, $wp_transient_delete_fails,
            $wp_on_set_transient, $wp_transient_expired, $wp_update_option_fails, $asw_zlib_unavailable,
            $asw_gzcompress_fails, $wp_using_ext_object_cache, $asw_max_cached_unit_bytes,
            $asw_max_inflated_unit_bytes;

        foreach ( $this->restore_mtimes as $path => $mtime ) {
            touch( $path, $mtime );
        }
        $this->restore_mtimes = array();
        clearstatcache( true );

        $wpdb->reset_tables();
        $wp_options                = array();
        $wp_transients             = array();
        $wp_transient_reads        = array();
        $wp_transient_delete_fails = false;
        $wp_on_set_transient       = null;
        $wp_transient_expired      = false;
        $wp_update_option_fails    = false;
        $asw_zlib_unavailable      = false;
        $asw_gzcompress_fails      = false;
        $wp_using_ext_object_cache = false;
        $asw_max_cached_unit_bytes   = null;
        $asw_max_inflated_unit_bytes = null;
        parent::tearDown();
    }

    /**
     * Build a store over the given corpus loader.
     *
     * @param ASW_Training_Data|null $training_data   Corpus loader, default a recording one.
     * @param array<int, string>     $excluded_tokens Excluded tokens.
     * @param bool                   $with_repository Whether to hand it a repository.
     */
    private function make_store(
        ?ASW_Training_Data $training_data = null,
        array $excluded_tokens = array(),
        bool $with_repository = true
    ): ASW_Model_Store {
        global $wpdb;

        return new ASW_Model_Store(
            new ASW_Tokenizer( $excluded_tokens ),
            $excluded_tokens,
            $training_data ?? new ASW_Recording_Training_Data(),
            $with_repository ? new ASW_Sample_Repository( $wpdb ) : null
        );
    }

    /**
     * The transient keys read so far, in call order.
     *
     * @return string[]
     */
    private function reads(): array {
        global $wp_transient_reads;
        return $wp_transient_reads;
    }

    /**
     * Every cached unit key currently stored.
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

    /**
     * Store a hand-made payload under a warmed unit's key, carrying forward the
     * fingerprint the store wrote there.
     *
     * A unit is validated against the fingerprint inside its value, so a payload
     * without one is rebuilt rather than served -- which is the point of the
     * scheme, and would make every crafted-payload test below pass vacuously.
     *
     * @param string $key     Unit key.
     * @param array  $payload Payload to store.
     */
    private function store_payload_with_fingerprint( string $key, array $payload ): void {
        global $wp_transients, $wp_options;

        $payload['fingerprint'] = $this->stored_unit( $key )['fingerprint'];

        // Stored in whichever form this host writes, so the serving path's
        // strict read rule does not reject the payload before the thing under
        // test has had a chance to.
        $wrapped                            = asw_test_wrap_unit( $payload );
        $wp_transients[ $key ]              = $wrapped;
        $wp_options[ '_transient_' . $key ] = $wrapped;
    }

    /**
     * The raw value stored under a key, as the cache would be handed it.
     *
     * The bootstrap's set_transient() injects a `__ttl` member into any array
     * value, which is the stub's own bookkeeping and not part of what the store
     * wrote -- so it is dropped before the value is weighed against the item
     * limit.
     *
     * @param string $key Unit key.
     * @return array
     */
    private function stored_value( string $key ): array {
        global $wp_transients;

        $this->assertArrayHasKey( $key, $wp_transients, "unit $key was never cached" );

        $value = $wp_transients[ $key ];
        unset( $value['__ttl'] );

        return $value;
    }

    /**
     * The unit stored under a key, read the way the serving path reads it.
     *
     * A unit goes into the cache compressed behind an explicit envelope member
     * wherever zlib is present, so a test that inspects what was written has to
     * go through the store's own decoder rather than indexing the raw value.
     *
     * @param string $key Unit key.
     * @return array
     */
    private function stored_unit( string $key ): array {
        global $wp_transients;

        $this->assertArrayHasKey( $key, $wp_transients, "unit $key was never cached" );

        $unit = asw_test_unwrap_unit( $wp_transients[ $key ] );
        $this->assertIsArray( $unit, "the value stored under $key is not readable as a unit" );

        return $unit;
    }

    /**
     * Move a corpus file's mtime forward and remember how to put it back.
     *
     * @param string $path Absolute path.
     */
    private function move_mtime( string $path ): void {
        $original = filemtime( $path );
        $this->restore_mtimes[ $path ] = $original;
        touch( $path, $original + 60 );
        clearstatcache( true );
    }

    // --- script_for() ---

    public function test_script_for_detects_once_and_memoises(): void {
        global $wpdb;

        $store = new ASW_Counting_Model_Store(
            new ASW_Tokenizer( array() ),
            array(),
            new ASW_Recording_Training_Data(),
            new ASW_Sample_Repository( $wpdb )
        );

        $text = 'Продам гараж, пишите на почту';

        $this->assertSame( 'cyrillic', $store->script_for( $text ) );
        $this->assertSame( 'cyrillic', $store->script_for( $text ) );
        $this->assertSame( 1, $store->detections, 'The script must be detected once per text, not per ask' );

        // A different text is a different memo entry.
        $this->assertSame( 'latin', $store->script_for( 'buy cheap pills now' ) );
        $this->assertSame( 2, $store->detections );
    }

    // --- laziness ---

    public function test_asking_for_a_script_classifier_reads_exactly_one_transient(): void {
        $store = $this->make_store();

        $store->script_classifier( 'cyrillic' );

        $reads = $this->reads();
        $this->assertCount( 1, $reads, 'A per-script unit must cost exactly one transient read' );
        $this->assertSame( ASW_Model_Store::TRANSIENT_PREFIX . 'cyrillic', $reads[0] );
    }

    public function test_a_second_ask_for_the_same_script_reads_no_further_transient(): void {
        $store = $this->make_store();

        $store->script_classifier( 'cyrillic' );
        $store->script_sample_tokens( 'cyrillic' );
        $store->script_classifier( 'cyrillic' );

        $this->assertCount( 1, $this->reads(), 'A loaded unit must be memoised for the rest of the request' );
    }

    public function test_the_combined_unit_is_only_read_when_it_is_asked_for(): void {
        $store = $this->make_store();

        $store->script_classifier( 'cyrillic' );
        $this->assertCount( 1, $this->reads() );
        $this->assertNotSame(
            ASW_Model_Store::TRANSIENT_PREFIX . 'combined',
            $this->reads()[0]
        );

        $store->combined_classifier();

        $reads = $this->reads();
        $this->assertCount( 2, $reads );
        $this->assertSame( ASW_Model_Store::TRANSIENT_PREFIX . 'combined', $reads[1] );

        // And it, too, is memoised.
        $store->combined_sample_tokens();
        $store->combined_has_script_samples();
        $this->assertCount( 2, $this->reads() );
    }

    public function test_an_unknown_script_loads_no_per_script_unit(): void {
        $store = $this->make_store();

        $this->assertNull( $store->script_classifier( 'unknown' ) );
        $this->assertSame( array(), $store->script_sample_tokens( 'unknown' ) );
        $this->assertSame( array(), $this->reads(), 'unknown is not a script group and has no unit' );
    }

    public function test_a_warm_unit_is_served_without_reading_a_corpus_file(): void {
        $cold = new ASW_Fixed_Training_Data();
        $this->make_store( $cold )->script_classifier( 'cyrillic' );
        $this->assertGreaterThan( 0, $cold->loads, 'The cold path must read the corpus' );

        $warm = new ASW_Fixed_Training_Data();
        $classifier = $this->make_store( $warm )->script_classifier( 'cyrillic' );

        $this->assertSame( 0, $warm->loads, 'A warm unit must be served from the transient alone' );
        $this->assertInstanceOf( ASW_Naive_Bayes::class, $classifier );
        $state = $classifier->get_state();
        $this->assertSame( 2, $state['doc_counts']['spam'] );
        $this->assertSame( 2, $state['doc_counts']['ham'] );
    }

    public function test_building_a_script_unit_reads_only_that_scripts_corpus_files(): void {
        $recorder = new ASW_Recording_Training_Data();

        $this->make_store( $recorder )->script_classifier( 'cyrillic' );

        $this->assertSame( array( 'cyrillic:spam', 'cyrillic:ham' ), $recorder->load_script_calls );
        $this->assertSame( array(), $recorder->load_samples_calls, 'The legacy files belong to the combined unit only' );
    }

    public function test_a_warm_unit_still_carries_its_pre_tokenised_samples(): void {
        $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );

        $warm   = new ASW_Fixed_Training_Data();
        $tokens = $this->make_store( $warm )->script_sample_tokens( 'cyrillic' );

        $this->assertSame( 0, $warm->loads );
        $this->assertCount( 2, $tokens );
        $this->assertContains( 'cheap', $tokens[0] );
    }

    // --- validation and rebuild ---

    public function test_a_unit_missing_its_classifier_is_deleted_and_rebuilt(): void {
        $this->assert_corrupted_script_unit_is_rebuilt( array( 'samples' => array() ) );
    }

    public function test_a_unit_missing_its_samples_is_deleted_and_rebuilt(): void {
        $this->assert_corrupted_script_unit_is_rebuilt(
            array(
                'classifier' => array(
                    'token_counts' => array( 'spam' => 'x 1' ),
                    'token_totals' => array( 'spam' => 1 ),
                    'doc_counts'   => array( 'spam' => 1 ),
                ),
            )
        );
    }

    public function test_a_unit_whose_classifier_state_is_rejected_is_deleted_and_rebuilt(): void {
        $this->assert_corrupted_script_unit_is_rebuilt(
            array(
                // token_counts is a map of category => packed counts string, so
                // a scalar in its place is a value this class did not write.
                'classifier' => array( 'token_counts' => 'bad' ),
                'samples'    => array(),
            )
        );
    }

    /**
     * Warm a per-script unit, replace it with the given unusable payload, and
     * assert the next store rebuilds and re-caches it.
     *
     * @param array $corrupted The payload to store under the unit's key.
     */
    private function assert_corrupted_script_unit_is_rebuilt( array $corrupted ): void {
        global $wp_transients, $wp_options, $wp_transient_reads;

        $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );
        $keys = $this->cached_unit_keys();
        $this->assertCount( 1, $keys );
        $key = $keys[0];

        $this->store_payload_with_fingerprint( $key, $corrupted );
        $wp_transient_reads = array();

        $rebuilt = new ASW_Fixed_Training_Data();
        $classifier = $this->make_store( $rebuilt )->script_classifier( 'cyrillic' );

        $this->assertGreaterThan( 0, $rebuilt->loads, 'An unusable unit must be rebuilt from the corpus' );
        $this->assertInstanceOf( ASW_Naive_Bayes::class, $classifier );
        $this->assertSame( 2, $classifier->get_state()['doc_counts']['spam'] );

        // The rebuild is re-cached under the same key, so the next request is warm.
        $recached = $this->stored_unit( $key );
        $this->assertIsArray( $recached['classifier'] );
        $this->assertIsArray( $recached['samples'] );
    }

    public function test_a_one_sided_script_unit_is_served_rather_than_rebuilt(): void {
        global $wp_transients, $wp_options;

        $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );
        $key = $this->cached_unit_keys()[0];

        // A script whose corpus is spam-only is legitimate; requiring both doc
        // counts here would rebuild it on every request.
        $one_sided = array(
            'classifier' => array(
                'token_counts' => array( 'spam' => 'pills 2' ),
                'token_totals' => array( 'spam' => 2 ),
                'doc_counts'   => array( 'spam' => 2 ),
            ),
            'samples'    => array( 'pills' ),
        );
        $this->store_payload_with_fingerprint( $key, $one_sided );

        $warm       = new ASW_Fixed_Training_Data();
        $classifier = $this->make_store( $warm )->script_classifier( 'cyrillic' );

        $this->assertSame( 0, $warm->loads, 'A one-sided script unit is valid and must be served' );
        $this->assertSame( array( 'spam' => 2 ), $classifier->get_state()['doc_counts'] );
    }

    public function test_a_cached_null_classifier_is_served_for_a_script_with_no_corpus(): void {
        global $wp_transients;

        // The recording loader returns nothing, so the unit legitimately has no
        // classifier at all -- and must still be cached and served.
        $store = $this->make_store();
        $this->assertNull( $store->script_classifier( 'thai' ) );

        $key    = $this->cached_unit_keys()[0];
        $cached = $this->stored_unit( $key );
        $this->assertArrayHasKey( 'classifier', $cached );
        $this->assertNull(
            $cached['classifier'],
            'A script with no samples at all caches a null classifier, which the packer leaves alone'
        );

        $warm     = new ASW_Recording_Training_Data();
        $reloaded = $this->make_store( $warm );
        $this->assertNull( $reloaded->script_classifier( 'thai' ) );
        $this->assertSame( array(), $warm->load_script_calls, 'A cached empty unit must not be rebuilt' );
    }

    public function test_a_combined_unit_missing_a_doc_count_is_deleted_and_rebuilt(): void {
        global $wp_transients, $wp_options;

        $this->make_store( new ASW_Fixed_Training_Data() )->combined_classifier();

        $keys = array_values(
            array_filter(
                $this->cached_unit_keys(),
                static function ( $key ) {
                    return ASW_Model_Store::TRANSIENT_PREFIX . 'combined' === $key;
                }
            )
        );
        $this->assertCount( 1, $keys );
        $key = $keys[0];

        // Valid state as far as set_state() is concerned, but spam-only. The
        // combined model is the fallback for every under-trained script, so a
        // half-trained one would disable the Bayesian check until the TTL ran out.
        $half = array(
            'classifier'         => array(
                'token_counts' => array( 'spam' => 'pills 2' ),
                'token_totals' => array( 'spam' => 2 ),
                'doc_counts'   => array( 'spam' => 2 ),
            ),
            'samples'            => array(),
            'has_script_samples' => true,
        );
        $this->store_payload_with_fingerprint( $key, $half );

        $rebuilt    = new ASW_Fixed_Training_Data();
        $classifier = $this->make_store( $rebuilt )->combined_classifier();

        $this->assertGreaterThan( 0, $rebuilt->loads );
        $doc_counts = $classifier->get_state()['doc_counts'];
        $this->assertArrayHasKey( 'spam', $doc_counts );
        $this->assertArrayHasKey( 'ham', $doc_counts );
    }

    public function test_the_combined_unit_records_whether_any_script_had_samples(): void {
        $with_samples = $this->make_store( new ASW_Fixed_Training_Data() );
        $this->assertTrue( $with_samples->combined_has_script_samples() );

        global $wp_transients, $wp_options, $wp_transient_reads;
        $wp_transients      = array();
        $wp_options         = array();
        $wp_transient_reads = array();

        $without = $this->make_store( new ASW_Recording_Training_Data() );
        $this->assertFalse(
            $without->combined_has_script_samples(),
            'With no script producing tokens the Similarity check must fall back to legacy tokenize()'
        );
    }

    public function test_a_cached_combined_unit_is_restored_rather_than_rebuilt(): void {
        // The per-script twin of this is
        // ASWTest::test_a_second_evaluation_restores_the_unit_from_cache. The
        // combined unit needs its own, because has_script_samples is round-tripped
        // through the cache and is the ONLY input to
        // ASW_Similarity_Check::resolve_from_store()'s legacy-vs-script
        // tokenisation branch: a restore that lost it would silently put every
        // warm CJK/Thai request on word-based tokenize() instead of bigrams.
        // The legacy-and-script loader, because the combined similarity corpus
        // is now the legacy lists and the unknown-script rows alone -- a loader
        // with no legacy corpus caches an empty one, which would make the
        // restore assertion below vacuous.
        $this->assertTrue( $this->make_combined_store()->combined_has_script_samples() );

        // The rebuild loader would answer false, so a true here can only have
        // come out of the transient.
        $warm  = new ASW_Recording_Training_Data();
        $store = $this->make_store( $warm );

        $this->assertTrue( $store->combined_has_script_samples(), 'has_script_samples must survive the cache round trip' );
        $this->assertNotEmpty( $store->combined_sample_tokens(), 'The cached similarity corpus must be restored too' );
        $doc_counts = $store->combined_classifier()->get_state()['doc_counts'];
        $this->assertSame( 21, $doc_counts['spam'], 'One legacy line plus ten scripts of two' );
        $this->assertSame( 21, $doc_counts['ham'] );
        $this->assertSame( array(), $warm->load_script_calls, 'A cached combined unit must not be rebuilt' );
        $this->assertSame( array(), $warm->load_samples_calls, 'A cached combined unit must not read the legacy files' );
    }

    public function test_a_corrupted_sample_list_is_rebuilt_rather_than_filtered(): void {
        // A cached unit whose sample list picked up non-lists -- what a
        // third-party pre_transient_* filter or a truncated option can leave
        // behind. They must never reach ASW_Cosine_Similarity::compare(), and
        // filtering them out instead would serve a corpus silently short of the
        // samples the unit was built from, out of the same corrupted row, for a
        // full day.
        $this->assert_corrupted_script_unit_is_rebuilt(
            array(
                'classifier' => array(
                    'token_counts' => array( 'spam' => 'pills 1', 'ham' => 'thanks 1' ),
                    'token_totals' => array( 'spam' => 1, 'ham' => 1 ),
                    'doc_counts'   => array( 'spam' => 1, 'ham' => 1 ),
                ),
                'samples'    => array( array( 'pills' ), 'junk', 7, array( 'cheap' ) ),
            )
        );

        $this->assert_rebuilt_samples_are_usable_token_lists();
    }

    public function test_a_sample_list_holding_a_non_string_token_rebuilds_the_unit(): void {
        // One level of validation is not enough: ASW_Cosine_Similarity uses
        // each token as an array key, so an array among them is an illegal
        // offset -- a TypeError ASW_Detector::run() swallows, leaving the
        // Similarity check silently off for the unit's whole 24-hour life.
        $this->assert_corrupted_script_unit_is_rebuilt(
            array(
                'classifier' => array(
                    'token_counts' => array( 'spam' => 'pills 1', 'ham' => 'thanks 1' ),
                    'token_totals' => array( 'spam' => 1, 'ham' => 1 ),
                    'doc_counts'   => array( 'spam' => 1, 'ham' => 1 ),
                ),
                'samples'    => array( array( 'pills' ), array( 'cheap', array( 'nested' ) ), array( 'online', 7 ) ),
            )
        );

        $this->assert_rebuilt_samples_are_usable_token_lists();
    }

    public function test_a_cached_empty_token_list_rebuilds_the_unit(): void {
        // build_script_unit() only ever appends a non-empty token list, so an
        // empty one is not something this class wrote -- and one of them is
        // enough to make ASW_Similarity_Check's `! empty( $script_tokens )`
        // test pass, which suppresses the fall back to the combined corpus a
        // script with no samples of its own is supposed to take.
        $this->assert_corrupted_script_unit_is_rebuilt(
            array(
                'classifier' => array(
                    'token_counts' => array( 'spam' => 'pills 1', 'ham' => 'thanks 1' ),
                    'token_totals' => array( 'spam' => 1, 'ham' => 1 ),
                    'doc_counts'   => array( 'spam' => 1, 'ham' => 1 ),
                ),
                'samples'    => array( array() ),
            )
        );
    }

    public function test_a_combined_unit_with_a_malformed_sample_list_is_rebuilt(): void {
        global $wp_transients;

        // The combined unit is the fallback for every script without one of its
        // own, so serving it with a filtered-down corpus disables Similarity for
        // exactly the comments that have nothing else to fall back on.
        $this->make_combined_store()->combined_classifier();
        $key = ASW_Model_Store::TRANSIENT_PREFIX . 'combined';

        $cached            = $this->stored_unit( $key );
        $cached['samples'] = array( array( 'pills' ), array( 'cheap', array( 'nested' ) ) );
        $this->store_payload_with_fingerprint( $key, $cached );

        $rebuilt = new ASW_Legacy_And_Script_Training_Data();
        $samples = $this->make_combined_store( null, $rebuilt )->combined_sample_tokens();

        $this->assertGreaterThan( 0, $rebuilt->loads, 'A malformed combined corpus must be rebuilt' );
        foreach ( $samples as $tokens ) {
            $this->assertNotSame( array(), $tokens );
            foreach ( $tokens as $token ) {
                $this->assertIsString( $token );
            }
        }
    }

    /**
     * Every token list the rebuilt cyrillic unit now carries is real
     * term-frequency input.
     */
    private function assert_rebuilt_samples_are_usable_token_lists(): void {
        $samples    = $this->make_store( new ASW_Fixed_Training_Data() )->script_sample_tokens( 'cyrillic' );
        $similarity = new ASW_Cosine_Similarity();

        $this->assertNotSame( array(), $samples );
        foreach ( $samples as $tokens ) {
            foreach ( $tokens as $token ) {
                $this->assertIsString( $token );
            }
            $this->assertIsFloat( $similarity->compare( $tokens, array( 'pills' ) ) );
        }
    }

    // --- the packed sample representation ---

    public function test_a_written_unit_stores_each_sample_as_one_delimited_string(): void {
        global $wp_transients;

        // The cached unit is what the 1 MB object-cache item limit applies to,
        // and a nested array of one-token-per-entry is the most expensive way
        // PHP can serialise a word list. No token can contain a space --
        // ASW_Tokenizer splits on /[^\p{L}\p{N}\p{M}]+/u and strips the same
        // class before emitting bigrams -- so a space-joined string is an exact,
        // reversible encoding of the list.
        $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );

        $samples = $this->stored_unit( $this->cached_unit_keys()[0] )['samples'];

        $this->assertNotSame( array(), $samples );
        foreach ( $samples as $packed ) {
            $this->assertIsString( $packed, 'Each sample is stored as one delimited string' );
            $this->assertStringContainsString( ' ', $packed );
        }
        $this->assertContains( 'buy cheap pills online now', $samples );
    }

    public function test_a_packed_sample_list_round_trips_to_the_freshly_built_tokens(): void {
        global $wp_transients, $wp_options;

        $cold = $this->make_store( new ASW_Fixed_Training_Data() );
        $this->assertNotSame( array(), $cold->script_sample_tokens( 'cyrillic' ) );
        $fresh_script = $cold->script_sample_tokens( 'cyrillic' );

        $warm = $this->make_store( new ASW_Recording_Training_Data() );
        $this->assertSame( $fresh_script, $warm->script_sample_tokens( 'cyrillic' ), 'Packing must be lossless' );

        $wp_transients = array();
        $wp_options    = array();

        $cold_combined  = $this->make_combined_store();
        $fresh_combined = $cold_combined->combined_sample_tokens();
        $this->assertNotSame( array(), $fresh_combined );

        $warm_combined = $this->make_store( new ASW_Recording_Training_Data() );
        $this->assertSame( $fresh_combined, $warm_combined->combined_sample_tokens(), 'Packing must be lossless' );
    }

    public function test_a_unit_cached_in_the_old_nested_shape_is_rebuilt(): void {
        // A unit written by the previous release is simply a value in a shape
        // this version does not recognise: serving it would hand
        // ASW_Cosine_Similarity an array where a token string is expected --
        // an illegal array offset, i.e. the TypeError the whole-unit rejection
        // rule exists for.
        $this->assert_corrupted_script_unit_is_rebuilt(
            array(
                'classifier' => array(
                    'token_counts' => array( 'spam' => 'pills 1', 'ham' => 'thanks 1' ),
                    'token_totals' => array( 'spam' => 1, 'ham' => 1 ),
                    'doc_counts'   => array( 'spam' => 1, 'ham' => 1 ),
                ),
                'samples'    => array( array( 'buy', 'cheap', 'pills' ) ),
            )
        );

        $this->assert_rebuilt_samples_are_usable_token_lists();
    }

    public function test_a_non_string_packed_sample_rebuilds_the_unit(): void {
        $this->assert_corrupted_script_unit_is_rebuilt( $this->unit_with_packed_samples( array( 'buy cheap pills', 7 ) ) );
        $this->assert_rebuilt_samples_are_usable_token_lists();
    }

    public function test_an_empty_packed_sample_rebuilds_the_unit(): void {
        // build_script_unit() only ever appends a non-empty token list, so an
        // empty string is not something this class wrote -- and one of them is
        // enough to make ASW_Similarity_Check's `! empty( $script_tokens )`
        // test pass, suppressing the fall back to the combined corpus a script
        // with no samples of its own is supposed to take.
        $this->assert_corrupted_script_unit_is_rebuilt( $this->unit_with_packed_samples( array( 'buy cheap pills', '' ) ) );
        $this->assert_rebuilt_samples_are_usable_token_lists();
    }

    public function test_a_packed_sample_with_an_empty_segment_rebuilds_the_unit(): void {
        // A double space explodes to an empty token, which is a term-frequency
        // key that matches nothing and skews every vector it appears in. No
        // tokenizer output can contain one, so the value was damaged in store.
        $this->assert_corrupted_script_unit_is_rebuilt( $this->unit_with_packed_samples( array( 'buy  pills' ) ) );
        $this->assert_rebuilt_samples_are_usable_token_lists();
    }

    public function test_an_empty_outer_sample_array_is_still_served(): void {
        global $wp_transients;

        // What a script with no spam samples legitimately caches.
        $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );
        $key = $this->cached_unit_keys()[0];

        $cached            = $this->stored_unit( $key );
        $cached['samples'] = array();
        $this->store_payload_with_fingerprint( $key, $cached );

        $warm  = new ASW_Fixed_Training_Data();
        $store = $this->make_store( $warm );

        $this->assertSame( array(), $store->script_sample_tokens( 'cyrillic' ) );
        $this->assertSame( 0, $warm->loads, 'An empty outer array is valid and must be served' );
    }

    public function test_a_combined_unit_with_a_malformed_packed_sample_is_rebuilt(): void {
        global $wp_transients;

        $this->make_combined_store()->combined_classifier();
        $key = ASW_Model_Store::TRANSIENT_PREFIX . 'combined';

        $cached            = $this->stored_unit( $key );
        $cached['samples'] = array( 'buy cheap pills', '' );
        $this->store_payload_with_fingerprint( $key, $cached );

        $rebuilt = new ASW_Legacy_And_Script_Training_Data();
        $samples = $this->make_combined_store( null, $rebuilt )->combined_sample_tokens();

        $this->assertGreaterThan( 0, $rebuilt->loads, 'A malformed combined corpus must be rebuilt' );
        $this->assertNotSame( array(), $samples );
        foreach ( $samples as $tokens ) {
            $this->assertNotSame( array(), $tokens );
            foreach ( $tokens as $token ) {
                $this->assertIsString( $token );
                $this->assertNotSame( '', $token );
            }
        }
    }

    /**
     * A per-script payload whose classifier state is valid and whose samples
     * are exactly the given (packed) list.
     *
     * @param array $samples Packed sample member.
     * @return array
     */
    private function unit_with_packed_samples( array $samples ): array {
        return array(
            'classifier' => array(
                'token_counts' => array( 'spam' => 'pills 1', 'ham' => 'thanks 1' ),
                'token_totals' => array( 'spam' => 1, 'ham' => 1 ),
                'doc_counts'   => array( 'spam' => 1, 'ham' => 1 ),
            ),
            'samples'    => $samples,
        );
    }

    // --- the packed classifier representation ---

    public function test_a_written_unit_stores_each_categorys_token_counts_as_one_interleaved_string(): void {
        global $wp_transients;

        // token_counts is the larger half of a classifier's state and the one
        // part of it that grows with the corpus, so it pays the same array
        // overhead per entry the sample lists did. Interleaving token and count
        // into one string is what brings the two units that were over the 1 MB
        // object-cache item limit under it. token_totals and doc_counts are one
        // entry per category and stay native.
        $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );

        $state = $this->stored_unit( $this->cached_unit_keys()[0] )['classifier'];

        $this->assertNotSame( array(), $state['token_counts'] );
        foreach ( $state['token_counts'] as $packed ) {
            $this->assertIsString( $packed, "Each category's token counts are stored as one string" );
        }

        $this->assertSame( array( 'spam' => 2, 'ham' => 2 ), $state['doc_counts'] );
        $this->assertIsInt( $state['token_totals']['spam'] );
        $this->assertIsInt( $state['token_totals']['ham'] );

        $segments = explode( ' ', $state['token_counts']['spam'] );
        $this->assertSame( 0, count( $segments ) % 2, 'The segments pair up as token, count' );

        $map = array();
        for ( $i = 0; $i < count( $segments ); $i += 2 ) {
            $map[ $segments[ $i ] ] = $segments[ $i + 1 ];
        }
        $this->assertSame( '2', $map['pills'], 'pills appears in both fixture spam lines' );
        $this->assertSame( '1', $map['online'] );
    }

    public function test_a_packed_classifier_round_trips_to_the_freshly_built_state(): void {
        global $wp_transients, $wp_options;

        $body = 'cheap pills online now';

        $fresh       = $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );
        $fresh_state = $fresh->get_state();
        $fresh_probs = $fresh->classify( $body );

        $warm_loader = new ASW_Recording_Training_Data();
        $warm        = $this->make_store( $warm_loader )->script_classifier( 'cyrillic' );

        $this->assertSame( array(), $warm_loader->load_script_calls, 'Premise: the unit is served from the cache' );
        $this->assertSame( $fresh_state, $warm->get_state(), 'Packing the token counts must be lossless' );
        $this->assertSame( $fresh_probs, $warm->classify( $body ), 'Probabilities must be unchanged' );

        $wp_transients = array();
        $wp_options    = array();

        $fresh_combined = $this->make_store( new ASW_Fixed_Training_Data() )->combined_classifier();
        $combined_state = $fresh_combined->get_state();
        $combined_probs = $fresh_combined->classify( $body );

        $warm_combined_loader = new ASW_Recording_Training_Data();
        $warm_combined        = $this->make_store( $warm_combined_loader )->combined_classifier();

        $this->assertSame( array(), $warm_combined_loader->load_script_calls, 'Premise: served from the cache' );
        $this->assertSame( $combined_state, $warm_combined->get_state() );
        $this->assertSame( $combined_probs, $warm_combined->classify( $body ) );
    }

    public function test_a_numeric_token_round_trips_through_the_packed_representation(): void {
        // PHP canonicalises an array key that looks like an integer, so
        // token_counts carries 2024 as an int both before implode() casts it
        // back to a string and after explode() hands it back. Nothing else in
        // the corpus fixtures produces one, so without this the identity claim
        // the packing rests on is argued in a comment and asserted nowhere.
        $fresh = $this->make_store( new ASW_Token_Shape_Training_Data() )->script_classifier( 'cyrillic' );
        $this->assertArrayHasKey( 2024, $fresh->get_state()['token_counts']['spam'], 'Premise: a numeric token' );

        $warm_loader = new ASW_Recording_Training_Data();
        $warm        = $this->make_store( $warm_loader )->script_classifier( 'cyrillic' );

        $this->assertSame( array(), $warm_loader->load_script_calls, 'Premise: the unit is served from the cache' );
        $this->assertSame( $fresh->get_state(), $warm->get_state(), 'A numeric token must round trip identically' );
    }

    public function test_a_cjk_units_bigrams_round_trip_through_the_packed_representation(): void {
        // The n-gram path emits multi-byte bigrams rather than words. It strips
        // the same character class before emitting them, so no bigram can carry
        // a space either -- but that is a separate code path from the word
        // split, and it is the one every CJK and Thai unit is packed from.
        $cold         = $this->make_store( new ASW_Token_Shape_Training_Data() );
        $fresh_state  = $cold->script_classifier( 'cjk' )->get_state();
        $fresh_tokens = $cold->script_sample_tokens( 'cjk' );

        $this->assertNotSame( array(), $fresh_tokens, 'Premise: the CJK unit has sample tokens' );
        $this->assertGreaterThan( 1, mb_strlen( $fresh_tokens[0][0] ), 'Premise: bigrams, not single characters' );

        $warm_loader = new ASW_Recording_Training_Data();
        $warm        = $this->make_store( $warm_loader );

        $this->assertSame( $fresh_tokens, $warm->script_sample_tokens( 'cjk' ) );
        $this->assertSame( array(), $warm_loader->load_script_calls, 'Premise: the unit is served from the cache' );
        $this->assertSame( $fresh_state, $warm->script_classifier( 'cjk' )->get_state() );
    }

    public function test_a_classifier_cached_in_the_old_nested_shape_is_rebuilt(): void {
        // A unit written before this change carries token_counts as a nested
        // map. That is simply a shape this version does not recognise, and the
        // rule for one of those is the rule for every other unusable value:
        // delete and rebuild, never serve.
        $this->assert_corrupted_script_unit_is_rebuilt(
            array(
                'classifier' => array(
                    'token_counts' => array( 'spam' => array( 'pills' => 1 ), 'ham' => array( 'thanks' => 1 ) ),
                    'token_totals' => array( 'spam' => 1, 'ham' => 1 ),
                    'doc_counts'   => array( 'spam' => 1, 'ham' => 1 ),
                ),
                'samples'    => array( 'buy cheap pills' ),
            )
        );
    }

    public function test_an_odd_number_of_count_segments_rebuilds_the_unit(): void {
        // The segments are pairs by construction, so an odd count is a value
        // that was damaged in store -- and reading it would silently drop or
        // shift every count after the break.
        $this->assert_corrupted_script_unit_is_rebuilt(
            $this->unit_with_packed_classifier( array( 'spam' => 'pills 2 online', 'ham' => 'thanks 1' ) )
        );
    }

    public function test_a_non_integer_count_segment_rebuilds_the_unit(): void {
        // A count is what every probability is computed from; casting a
        // non-numeric segment to int would quietly turn it into 0.
        $this->assert_corrupted_script_unit_is_rebuilt(
            $this->unit_with_packed_classifier( array( 'spam' => 'pills lots', 'ham' => 'thanks 1' ) )
        );
    }

    public function test_an_empty_token_segment_rebuilds_the_unit(): void {
        // The pair count is even here, so the odd-segment guard never sees it:
        // a doubled space between a count and the next token pairs '' with the
        // following count. No tokenizer output contains a space, so the value
        // was damaged in store -- and reading it would install a '' key in
        // token_counts, inflating get_vocabulary_size() and shifting every
        // Laplace-smoothed probability the category serves for a whole day.
        $this->assert_corrupted_script_unit_is_rebuilt(
            $this->unit_with_packed_classifier( array( 'spam' => 'pills 1  2', 'ham' => 'thanks 1' ) )
        );
    }

    public function test_a_non_string_token_counts_member_rebuilds_the_unit(): void {
        $this->assert_corrupted_script_unit_is_rebuilt(
            $this->unit_with_packed_classifier( array( 'spam' => 7, 'ham' => 'thanks 1' ) )
        );
    }

    public function test_a_category_with_an_empty_counts_string_restores_as_an_empty_map(): void {
        global $wp_transients;

        // explode( ' ', '' ) yields one empty segment, so the empty map has to
        // be handled before the pair walk rather than falling into it. A
        // category that learned a document of nothing but excluded tokens is
        // exactly this value.
        $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );
        $key = $this->cached_unit_keys()[0];

        $payload                                 = $this->unit_with_packed_classifier(
            array(
                'spam' => '',
                'ham'  => 'thanks 1',
            )
        );
        $payload['classifier']['token_totals']   = array( 'spam' => 0, 'ham' => 1 );
        $this->store_payload_with_fingerprint( $key, $payload );

        $warm       = new ASW_Fixed_Training_Data();
        $classifier = $this->make_store( $warm )->script_classifier( 'cyrillic' );

        $this->assertSame( 0, $warm->loads, 'An empty counts string is valid and must be served' );
        $state = $classifier->get_state();
        $this->assertSame( array(), $state['token_counts']['spam'] );
        $this->assertSame( array( 'thanks' => 1 ), $state['token_counts']['ham'] );
    }

    /**
     * A per-script payload whose samples are valid and whose classifier carries
     * exactly the given (packed) token_counts member.
     *
     * @param array $token_counts Packed token_counts member.
     * @return array
     */
    private function unit_with_packed_classifier( array $token_counts ): array {
        return array(
            'classifier' => array(
                'token_counts' => $token_counts,
                'token_totals' => array( 'spam' => 1, 'ham' => 1 ),
                'doc_counts'   => array( 'spam' => 1, 'ham' => 1 ),
            ),
            'samples'    => array( 'buy cheap pills' ),
        );
    }

    // --- the compressed envelope ---

    /**
     * Put this host on an external object cache, which is where a unit is
     * stored compressed.
     *
     * The envelope is confined to the cache because that is both the only
     * place the 1 048 576-byte per-item limit exists and the only place a
     * deflate stream can be stored at all -- an option row is a `longtext` on
     * a utf8mb4 connection and wpdb refuses a value that is not valid UTF-8.
     */
    private function with_object_cache(): void {
        global $wp_using_ext_object_cache;

        $wp_using_ext_object_cache = true;
    }

    public function test_a_written_unit_is_stored_compressed(): void {
        global $wp_transients;

        $this->with_object_cache();

        // The 1 048 576-byte object-cache item limit applies to the stored
        // value, and a site that keeps learning grows a unit without bound, so
        // the value goes in deflated behind an explicit member rather than as
        // the bare array. The member is what makes the two stored forms tellable
        // apart without guessing at the payload.
        $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );
        $key = $this->cached_unit_keys()[0];

        $stored = $wp_transients[ $key ];
        unset( $stored['__ttl'] );

        $this->assertSame(
            array( 'zlib' ),
            array_keys( $stored ),
            'A compressed unit carries its payload under one explicit member and nothing else'
        );
        $this->assertIsString( $stored['zlib'] );

        $unit = $this->stored_unit( $key );
        $this->assertLessThan(
            strlen( serialize( $unit ) ),
            strlen( serialize( $stored ) ),
            'The stored form must be smaller than the unit it encodes'
        );
    }

    public function test_a_unit_is_stored_plain_without_an_external_object_cache(): void {
        global $wp_transients, $wp_options;

        // Without an external object cache a transient *is* the
        // `_transient_<name>` option row, a `longtext` on a utf8mb4 connection.
        // wpdb::process_fields() puts every value through strip_invalid_text(),
        // a deflate stream is not valid UTF-8, and the write is refused outright
        // with "Could not perform query because it contains invalid data." An
        // unconditional envelope therefore cached *nothing* on every site
        // without a persistent object cache -- the default -- and put every
        // submission on the cold path for good. There is no per-item limit on
        // that row to respect either, so the plain array costs it nothing.
        $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );
        $key = $this->cached_unit_keys()[0];

        $this->assertArrayNotHasKey(
            'zlib',
            $wp_transients[ $key ],
            'The option-backed path stores the plain array'
        );
        $this->assertArrayHasKey( '_transient_' . $key, $wp_options, 'Premise: the value really is an option row' );
        $this->assertIsArray( $wp_options[ '_transient_' . $key ]['classifier'] );
        $this->assertNotSame( array(), $wp_options[ '_transient_' . $key ]['samples'] );

        // Every member of the row is a string, an int, a float, a bool or an
        // array of them, so maybe_serialize() produces valid UTF-8 and wpdb
        // takes the write -- the property the envelope broke.
        $this->assertTrue(
            $this->is_utf8_serializable( $wp_options[ '_transient_' . $key ] ),
            'A stored unit must serialize to valid UTF-8 or wpdb refuses the option write'
        );

        $warm_loader = new ASW_Recording_Training_Data();
        $this->assertNotSame(
            array(),
            $this->make_store( $warm_loader )->script_sample_tokens( 'cyrillic' )
        );
        $this->assertSame( array(), $warm_loader->load_script_calls, 'The plain form must be served, not rebuilt' );
    }

    public function test_a_compressed_unit_is_rebuilt_without_an_external_object_cache(): void {
        global $wp_transients, $wp_options;

        // The mismatch in the other direction: a compressed row is one an
        // object cache stranded when it was removed again. This host cannot put
        // one there, so it is not a value this version wrote under this key and
        // the row is dropped and rebuilt in the form the option path can hold.
        $this->with_object_cache();
        $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );
        $key      = $this->cached_unit_keys()[0];
        $stranded = $wp_transients[ $key ];
        unset( $stranded['__ttl'] );
        $this->assertArrayHasKey( 'zlib', $stranded, 'Premise: the cache holds the unit compressed' );

        $GLOBALS['wp_using_ext_object_cache'] = false;
        $wp_transients[ $key ]                = $stranded;
        $wp_options[ '_transient_' . $key ]   = $stranded;

        $this->assert_unusable_stored_value_is_rebuilt( $key );
    }

    /**
     * Whether maybe_serialize() of this value is valid UTF-8, i.e. whether wpdb
     * would take it into a `longtext` option column rather than refuse the row.
     *
     * @param mixed $value Value to check.
     * @return bool
     */
    private function is_utf8_serializable( $value ): bool {
        return (bool) preg_match( '//u', serialize( $value ) );
    }

    public function test_a_compressed_unit_round_trips_to_the_uncompressed_build(): void {
        global $wp_transients, $wp_options, $asw_zlib_unavailable;

        $this->with_object_cache();
        $body = 'cheap pills online now';

        // The plain form first, on a host the store believes has no zlib: that
        // is the build every assertion below is measured against.
        $asw_zlib_unavailable = true;

        $plain            = $this->make_store( new ASW_Fixed_Training_Data() );
        $plain_state      = $plain->script_classifier( 'cyrillic' )->get_state();
        $plain_probs      = $plain->script_classifier( 'cyrillic' )->classify( $body );
        $plain_samples    = $plain->script_sample_tokens( 'cyrillic' );
        $plain_combined   = $this->make_store( new ASW_Fixed_Training_Data() )->combined_sample_tokens();

        $wp_transients        = array();
        $wp_options           = array();
        $asw_zlib_unavailable = false;

        $cold = $this->make_store( new ASW_Fixed_Training_Data() );
        $this->assertSame( $plain_state, $cold->script_classifier( 'cyrillic' )->get_state() );

        // The warm read is the one that matters: it is the only path that goes
        // through gzuncompress()/unserialize().
        $warm_loader = new ASW_Recording_Training_Data();
        $warm        = $this->make_store( $warm_loader );

        $this->assertSame( $plain_state, $warm->script_classifier( 'cyrillic' )->get_state() );
        $this->assertSame( $plain_probs, $warm->script_classifier( 'cyrillic' )->classify( $body ) );
        $this->assertSame( $plain_samples, $warm->script_sample_tokens( 'cyrillic' ) );
        $this->assertSame(
            array(),
            $warm_loader->load_script_calls,
            'Premise: the unit came out of the cache, so the envelope really was decoded'
        );

        $wp_transients = array();
        $wp_options    = array();

        $this->make_store( new ASW_Fixed_Training_Data() )->combined_sample_tokens();
        $warm_combined = $this->make_store( new ASW_Recording_Training_Data() );
        $this->assertSame( $plain_combined, $warm_combined->combined_sample_tokens() );
    }

    public function test_a_host_without_zlib_writes_and_reads_the_plain_form(): void {
        global $wp_transients, $asw_zlib_unavailable;

        $this->with_object_cache();
        // Every PHP build WordPress supports ships zlib, but a host that had it
        // compiled out must keep caching rather than rebuild on every
        // submission -- which is what an unconditional envelope would cost it.
        $asw_zlib_unavailable = true;

        $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );
        $key = $this->cached_unit_keys()[0];

        $stored = $wp_transients[ $key ];
        $this->assertArrayNotHasKey( 'zlib', $stored, 'Without zlib the unit is stored as the plain array' );
        $this->assertIsArray( $stored['classifier'] );
        $this->assertIsArray( $stored['samples'] );

        $warm_loader = new ASW_Recording_Training_Data();
        $warm        = $this->make_store( $warm_loader );

        $this->assertNotSame( array(), $warm->script_sample_tokens( 'cyrillic' ) );
        $this->assertSame( array(), $warm_loader->load_script_calls, 'The plain form must be served, not rebuilt' );
    }

    public function test_a_compressed_unit_is_rebuilt_on_a_host_without_zlib(): void {
        global $asw_zlib_unavailable;

        $this->with_object_cache();
        // The store is strict on read: the stored form has to match what this
        // host can produce. A unit written while zlib was present cannot be
        // inflated once it is gone, and serving the envelope's raw members to
        // ASW_Cosine_Similarity is exactly the illegal-offset TypeError the
        // whole-unit rejection rule exists for.
        $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );
        $key = $this->cached_unit_keys()[0];

        $asw_zlib_unavailable = true;

        $this->assert_unusable_stored_value_is_rebuilt( $key );
    }

    public function test_a_plain_unit_is_rebuilt_on_a_host_with_zlib(): void {
        global $wp_transients, $wp_options, $asw_zlib_unavailable;

        $this->with_object_cache();
        // The mirror image, and the reason the rule is stated as a match rather
        // than as "inflate what you can": a plain array under this key on a host
        // that compresses is not a value this version wrote there.
        $asw_zlib_unavailable = true;
        $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );
        $key = $this->cached_unit_keys()[0];
        $this->assertArrayNotHasKey( 'zlib', $wp_transients[ $key ], 'Premise: the plain form is what is stored' );

        $asw_zlib_unavailable = false;

        $this->assert_unusable_stored_value_is_rebuilt( $key );
    }

    public function test_a_refused_gzcompress_caches_nothing_rather_than_an_unservable_value(): void {
        global $asw_gzcompress_fails;

        $this->with_object_cache();
        // A zlib that is present but will not take this payload. Falling back to
        // the plain array is the one thing that must not happen: unwrap_for_read()
        // is strict in both directions, so this host would refuse that array on
        // every later read, delete the row and rebuild -- and the rebuild would
        // write the same unservable value again. That is a cold rebuild on every
        // submission *plus* a wasted write and delete each time, where writing
        // nothing costs the rebuild alone.
        $asw_gzcompress_fails = true;

        $loader = new ASW_Fixed_Training_Data();
        $store  = $this->make_store( $loader );

        $this->assertInstanceOf( ASW_Naive_Bayes::class, $store->script_classifier( 'cyrillic' ) );
        $this->assertSame(
            array(),
            $this->cached_unit_keys(),
            'A unit this host cannot store must leave no row behind at all'
        );
        $this->assertNotSame( array(), $store->script_sample_tokens( 'cyrillic' ), 'The unit still serves this request' );
    }

    public function test_a_unit_is_cached_again_once_gzcompress_takes_the_payload(): void {
        global $asw_gzcompress_fails;

        $this->with_object_cache();
        // The refusal is per request, not a latch: the next request retries, in
        // the same shape as the mid-load-race and failed-sample-read rules.
        $asw_gzcompress_fails = true;
        $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );
        $this->assertSame( array(), $this->cached_unit_keys(), 'Premise: nothing was cached' );

        $asw_gzcompress_fails = false;
        $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );

        $key = $this->cached_unit_keys()[0];
        $this->assertArrayHasKey( 'zlib', $GLOBALS['wp_transients'][ $key ], 'The retry stores the compressed form' );

        $warm_loader = new ASW_Recording_Training_Data();
        $this->assertNotSame(
            array(),
            $this->make_store( $warm_loader )->script_sample_tokens( 'cyrillic' )
        );
        $this->assertSame( array(), $warm_loader->load_script_calls, 'The retried write must be servable' );
    }

    /**
     * The vocabulary a unit carries is the one axis LEARNED_SIMILARITY_BUDGET
     * and the envelope cannot bound.
     *
     * ASW_Tokenizer caps a sample at 10 000 tokens and puts no cap on a token's
     * *length*, and ASW_Naive_Bayes::learn() admits new tokens
     * first-come-first-served until its *count* cap, so a site's learned
     * history decides the dictionary's bytes -- a full two-category dictionary
     * of 10-character CJK tokens compresses to 2 210 916 bytes, 211 % of the
     * limit on the classifier alone. memcached refuses such an item silently,
     * after which every submission in that writing system pays a cold rebuild
     * for good.
     *
     * storable_unit() therefore weighs the value it is about to write and,
     * while it is too big, drops the least frequent half of each category's
     * vocabulary and weighs it again. The limit is lowered here rather than
     * reached: driving 1 MB of genuinely novel vocabulary through the tokenizer
     * takes seconds, and a mechanism pinned only by the suite's slowest test is
     * a mechanism that stops being run.
     */
    public function test_a_unit_over_the_item_limit_is_pruned_until_the_cache_can_hold_it(): void {
        global $wp_transients, $asw_max_cached_unit_bytes;

        $this->with_object_cache();

        $whole = $this->make_store( new ASW_Bulk_Training_Data() )->script_classifier( 'cyrillic' )->get_state();
        $key   = $this->cached_unit_keys()[0];
        $full  = strlen( serialize( $this->stored_value( $key ) ) );

        $limit = intdiv( $full, 2 );
        // The premise stated as the thing it claims: the fixture has to carry
        // enough vocabulary for the halving loop to run more than one round. A
        // trimmed fixture would otherwise quietly narrow this test instead of
        // failing. (That the unit is over the lowered limit needs no assertion
        // -- the limit is half its measured size.)
        foreach ( array( 'spam', 'ham' ) as $category ) {
            $this->assertGreaterThan(
                4,
                count( $whole['token_counts'][ $category ] ),
                "Premise: the $category dictionary has enough vocabulary to halve more than once"
            );
        }

        $wp_transients             = array();
        $GLOBALS['wp_options']     = array();
        $asw_max_cached_unit_bytes = $limit;

        $store  = $this->make_store( new ASW_Bulk_Training_Data() );
        $pruned = $store->script_classifier( 'cyrillic' )->get_state();

        $this->assertSame( array( $key ), $this->cached_unit_keys(), 'The pruned unit must still be cached' );
        $this->assertLessThan(
            $limit,
            strlen( serialize( $this->stored_value( $key ) ) ),
            'A cached unit must be under what the cache will hold'
        );

        foreach ( array( 'spam', 'ham' ) as $category ) {
            $this->assertLessThan(
                count( $whole['token_counts'][ $category ] ),
                count( $pruned['token_counts'][ $category ] ),
                "The $category vocabulary was not pruned, so the unit fitted for some other reason"
            );
            $this->assertNotSame(
                array(),
                $pruned['token_counts'][ $category ],
                "The $category vocabulary was spent entirely, where halving should have stopped at the first fit"
            );

            // Frequency is what is spent: what survives is the head of the
            // distribution, which is what a classification actually turns on.
            $dropped = array_diff_key( $whole['token_counts'][ $category ], $pruned['token_counts'][ $category ] );
            $this->assertGreaterThanOrEqual(
                max( $dropped ),
                min( $pruned['token_counts'][ $category ] ),
                "A more frequent $category token was dropped than one that was kept"
            );

            // The dropped occurrences leave token_totals with them, exactly as
            // learn() never counts a token it refuses to admit.
            $this->assertSame(
                array_sum( $pruned['token_counts'][ $category ] ),
                $pruned['token_totals'][ $category ],
                "The $category smoothing denominator still remembers tokens the model no longer has"
            );
        }

        // And what was written is servable, not merely small.
        $warm_loader = new ASW_Recording_Training_Data();
        $warm        = $this->make_store( $warm_loader );

        $this->assertSame( $pruned, $warm->script_classifier( 'cyrillic' )->get_state() );
        $this->assertSame( array(), $warm_loader->load_script_calls, 'The pruned unit must be served, not rebuilt' );
    }

    public function test_the_request_that_prunes_a_unit_classifies_with_the_pruned_model(): void {
        global $asw_max_cached_unit_bytes;

        $this->with_object_cache();
        // A cache holding a model the request that built it did not use would
        // make a verdict depend on whether the unit happened to be warm. The
        // prune is therefore handed back to the live classifier before the
        // write, so the cold request and every warm one after it score alike.
        $this->make_store( new ASW_Bulk_Training_Data() )->script_classifier( 'cyrillic' );
        $key  = $this->cached_unit_keys()[0];
        $full = strlen( serialize( $this->stored_value( $key ) ) );

        $GLOBALS['wp_transients'] = array();
        $GLOBALS['wp_options']    = array();
        $asw_max_cached_unit_bytes = intdiv( $full, 2 );

        $body  = 'zt0 cheap pills online now';
        $cold  = $this->make_store( new ASW_Bulk_Training_Data() );
        $probs = $cold->script_classifier( 'cyrillic' )->classify( $body );

        $warm = $this->make_store( new ASW_Recording_Training_Data() );

        $this->assertSame(
            $probs,
            $warm->script_classifier( 'cyrillic' )->classify( $body ),
            'The request that pruned the unit must classify with the model it cached'
        );
    }

    public function test_a_unit_over_the_limit_is_left_whole_without_an_external_object_cache(): void {
        global $asw_max_cached_unit_bytes;

        // The per-item limit is the object cache's and only its. Without one a
        // transient is a `_transient_<name>` option row -- a longtext that holds
        // 4 GB -- so pruning a model there would spend detection quality on a
        // bound that does not exist.
        $this->with_object_cache();
        $whole = $this->make_store( new ASW_Bulk_Training_Data() )->script_classifier( 'cyrillic' )->get_state();
        $key   = $this->cached_unit_keys()[0];
        $full  = strlen( serialize( $this->stored_value( $key ) ) );

        $GLOBALS['wp_transients']             = array();
        $GLOBALS['wp_options']                = array();
        $GLOBALS['wp_using_ext_object_cache'] = false;
        $asw_max_cached_unit_bytes            = intdiv( $full, 2 );

        $store = $this->make_store( new ASW_Bulk_Training_Data() );

        $this->assertSame( $whole, $store->script_classifier( 'cyrillic' )->get_state() );
        $this->assertSame( array( $key ), $this->cached_unit_keys(), 'The whole unit must still be cached' );
    }

    public function test_a_unit_that_cannot_fit_at_all_caches_nothing_and_keeps_its_model(): void {
        global $asw_max_cached_unit_bytes;

        $this->with_object_cache();
        // The floor of the walk: a limit no unit can meet even with an empty
        // dictionary. Nothing is written -- the same answer a refused
        // gzcompress() gets -- and the live model is left alone, because
        // spending its vocabulary would buy nothing at all here.
        $whole = $this->make_store( new ASW_Bulk_Training_Data() )->script_classifier( 'cyrillic' )->get_state();

        $GLOBALS['wp_transients']  = array();
        $GLOBALS['wp_options']     = array();
        $asw_max_cached_unit_bytes = 64;

        $store = $this->make_store( new ASW_Bulk_Training_Data() );

        $this->assertSame( $whole, $store->script_classifier( 'cyrillic' )->get_state() );
        $this->assertSame( array(), $this->cached_unit_keys(), 'A unit that cannot fit must leave no row behind' );
        $this->assertNotSame( array(), $store->script_sample_tokens( 'cyrillic' ), 'The unit still serves this request' );
    }

    public function test_a_unit_with_no_classifier_that_cannot_fit_caches_nothing(): void {
        global $asw_max_cached_unit_bytes;

        // A script with no samples at all still caches a real unit --
        // `classifier => null`, `samples => []`. There is no vocabulary to
        // spend on one, so a limit it cannot meet has to leave it uncached
        // rather than reach for get_state() on null.
        $this->with_object_cache();
        $asw_max_cached_unit_bytes = 4;

        $store = $this->make_store( new ASW_Recording_Training_Data() );

        $this->assertNull( $store->script_classifier( 'cyrillic' ), 'Premise: this unit has no classifier to prune' );
        $this->assertSame(
            array(),
            $this->cached_unit_keys(),
            'A unit with nothing left to spend must leave no row behind'
        );
    }

    public function test_the_item_limit_holds_back_an_allowance_for_the_cache_items_overhead(): void {
        // stored_size() can only weigh the value, where memcached weighs the
        // whole item -- struct, key, flags suffix and value. Without the
        // holdback there is a sliver in which a unit passes here and the
        // backend still refuses it silently, which is the permanent
        // cold-rebuild failure the mechanism exists to prevent. Every other
        // prune test lowers the limit through $asw_max_cached_unit_bytes, which
        // size_limit() returns verbatim, so this is the only place the
        // allowance is observable at all.
        unset( $GLOBALS['asw_max_cached_unit_bytes'] );

        $max      = ( new ReflectionClassConstant( ASW_Model_Store::class, 'MAX_CACHED_UNIT_BYTES' ) )->getValue();
        $overhead = ( new ReflectionClassConstant( ASW_Model_Store::class, 'CACHE_ITEM_OVERHEAD_BYTES' ) )->getValue();

        $this->assertGreaterThan( 0, $overhead, 'An allowance of zero is no allowance' );
        $this->assertSame(
            $max - $overhead,
            ( new ReflectionMethod( ASW_Model_Store::class, 'size_limit' ) )->invoke( null )
        );
    }

    public function test_a_script_units_classifier_trains_on_the_corpus_before_the_learned_tail(): void {
        global $wpdb;

        // build_script_unit() takes the spam half split, so it re-merges the
        // two itself -- and ASW_Naive_Bayes::learn() admits tokens
        // first-come-first-served until MAX_DICTIONARY_SIZE, so on a script at
        // the cap that order decides whose vocabulary the site keeps. The state
        // it caches must be the one load_script_samples()'s own order produces:
        // the shipped corpus first, this site's learned samples after it.
        $repo    = new ASW_Sample_Repository( $wpdb );
        $learned = array( 'zlearned discount pills online', 'zlearned second body for training' );
        foreach ( $learned as $index => $content ) {
            $this->assertNotSame( false, $repo->insert( 'cyrillic', 'spam', $content, 7100 + $index ) );
        }

        $expected = new ASW_Naive_Bayes( new ASW_Tokenizer( array() ), 'cyrillic' );
        ( new ASW_Fixed_Training_Data( $repo ) )->train_script_classifier( $expected, 'cyrillic' );

        $store = new ASW_Model_Store(
            new ASW_Tokenizer( array() ),
            array(),
            new ASW_Fixed_Training_Data( $repo ),
            $repo
        );

        $state = $store->script_classifier( 'cyrillic' )->get_state();

        // Premise: the learned half really did reach the unit, or this compares
        // two corpus-only classifiers and holds whatever the order is.
        $this->assertArrayHasKey( 'zlearned', $state['token_counts']['spam'] );
        // assertSame compares key order too, which is where a reversed merge
        // shows itself: learn() inserts tokens in encounter order.
        $this->assertSame( $expected->get_state(), $state );
    }

    public function test_the_halving_walk_bottoms_out_at_an_empty_vocabulary(): void {
        $halve = new ReflectionMethod( ASW_Model_Store::class, 'halve_dictionary' );

        // The terminal state storable_unit() is written against is an *empty*
        // dictionary -- a unit that will not fit even without one caches
        // nothing. Stopping a token short would leave a unit that fits only
        // with an empty vocabulary uncached, a band one token wide on ordinary
        // text and a whole comment wide on the long-token history the byte
        // bounds exist for.
        $state = array(
            'token_counts' => array(
                'spam' => array( 'a' => 9, 'b' => 5, 'c' => 1 ),
                'ham'  => array( 'd' => 7, 'e' => 2 ),
            ),
            'token_totals' => array( 'spam' => 15, 'ham' => 9 ),
            'doc_counts'   => array( 'spam' => 3, 'ham' => 2 ),
        );

        $once = $halve->invokeArgs( null, array( $state ) );
        $this->assertSame( array( 'a' => 9 ), $once['token_counts']['spam'] );
        $this->assertSame( array( 'd' => 7 ), $once['token_counts']['ham'] );
        $this->assertSame( array( 'spam' => 9, 'ham' => 7 ), $once['token_totals'] );

        // The last token goes too, and the occupancy leaves token_totals with
        // it, exactly as every earlier round does.
        $empty = $halve->invokeArgs( null, array( $once ) );
        $this->assertSame( array(), $empty['token_counts']['spam'] );
        $this->assertSame( array(), $empty['token_counts']['ham'] );
        $this->assertSame( array( 'spam' => 0, 'ham' => 0 ), $empty['token_totals'] );

        // And only then is there nothing left to spend, which storable_unit()
        // reads as "cache nothing at all".
        $this->assertNull( $halve->invokeArgs( null, array( $empty ) ) );

        // The emptied model is still one ASW_Naive_Bayes can classify with:
        // its Laplace denominator is floored at 1, which is the change that
        // makes this floor a valid terminal state rather than a
        // DivisionByZeroError swallowed by ASW_Detector::run()'s fail-open
        // catch while a plugin-attributable line goes into the debug.log
        // dev/smoke.sh scans.
        $classifier = new ASW_Naive_Bayes();
        $classifier->set_state( $empty );
        $probabilities = $classifier->classify( 'zzz unseen words only' );
        $this->assertEqualsWithDelta( 1.0, array_sum( $probabilities ), 0.000001 );
    }

    public function test_a_unit_that_would_inflate_past_the_readers_ceiling_is_pruned(): void {
        global $asw_max_inflated_unit_bytes, $wp_transients;

        // The two bounds are independent axes. inflate_unit() hands
        // gzuncompress() a maximum length, and a dictionary of long, distinct,
        // highly repetitive tokens -- ASW_Tokenizer caps a sample's token
        // *count*, never a token's length -- compresses to far under the item
        // limit while serializing to far over that ceiling. Weighing the
        // compressed value alone would accept such a unit, whereupon every
        // later read refuses it, deletes the row and rebuilds: a cold rebuild
        // plus a wasted write on every submission for good.
        $this->with_object_cache();
        if ( ! function_exists( 'gzcompress' ) ) {
            $this->markTestSkipped( 'zlib is absent, so nothing is ever inflated on this host' );
        }

        $key   = ASW_Model_Store::TRANSIENT_PREFIX . 'cyrillic';
        $whole = $this->make_store( new ASW_Bulk_Training_Data() )->script_classifier( 'cyrillic' )->get_state();

        // The bytes the whole unit inflates back to, read off the value the
        // store itself wrote, so the ceiling below is stated against the form
        // this host stores rather than against a reconstruction of it.
        $unpruned = strlen( serialize( asw_test_unwrap_unit( $wp_transients[ $key ] ) ) );

        $GLOBALS['wp_transients'] = array();
        $GLOBALS['wp_options']    = array();

        // A ceiling the *uncompressed* unit is over while its compressed form
        // sits comfortably inside the item limit, which is untouched here.
        $asw_max_inflated_unit_bytes = intdiv( $unpruned, 2 );

        $store = $this->make_store( new ASW_Bulk_Training_Data() );
        $state = $store->script_classifier( 'cyrillic' )->get_state();

        $this->assertSame( array( $key ), $this->cached_unit_keys(), 'The unit must be cached, not refused' );
        $this->assertNotSame(
            $whole['token_counts']['spam'],
            $state['token_counts']['spam'],
            'The vocabulary must have been spent to bring the payload under the ceiling'
        );

        // What was written is what a reader can decode: the ceiling the writer
        // measured against is the one inflate_unit() enforces.
        $decoded = asw_test_unwrap_unit( $wp_transients[ $key ] );
        $this->assertIsArray( $decoded, 'A value written under the ceiling must inflate back' );
        $this->assertLessThan(
            $asw_max_inflated_unit_bytes,
            strlen( serialize( $decoded ) ),
            'The stored unit must inflate to less than the ceiling its reader allows'
        );
    }

    public function test_a_unit_the_reader_could_never_decode_is_not_written_at_all(): void {
        global $asw_max_inflated_unit_bytes;

        // The floor of the same walk: a ceiling no unit can meet even with an
        // empty dictionary caches nothing, exactly as an unmeetable item limit
        // does, rather than writing a value every read then refuses.
        $this->with_object_cache();
        if ( ! function_exists( 'gzcompress' ) ) {
            $this->markTestSkipped( 'zlib is absent, so nothing is ever inflated on this host' );
        }

        $whole = $this->make_store( new ASW_Bulk_Training_Data() )->script_classifier( 'cyrillic' )->get_state();

        $GLOBALS['wp_transients']    = array();
        $GLOBALS['wp_options']       = array();
        $asw_max_inflated_unit_bytes = 64;

        $store = $this->make_store( new ASW_Bulk_Training_Data() );

        $this->assertSame( $whole, $store->script_classifier( 'cyrillic' )->get_state(), 'The live model is left alone' );
        $this->assertSame( array(), $this->cached_unit_keys(), 'A unit no read could decode must leave no row behind' );
    }

    public function test_the_budget_bounds_what_a_corpus_serializes_to_not_only_its_tokens(): void {
        // A budget counted in joined token bytes alone bounds the strings and
        // not the array holding them, so the number of admitted lists is capped
        // only by how short a sample can tokenise to. On the cache-backed host
        // without zlib -- where the plain packed array is what the item limit
        // weighs -- a history of three-byte tokenisations then serializes to
        // several times the budget, and nothing can prune it back:
        // storable_unit() spends vocabulary, not corpus.
        $learned = array();
        for ( $i = 0; $i < 20000; $i++ ) {
            $learned[] = 'zq' . base_convert( (string) $i, 10, 36 );
        }

        // Premise: counted in token bytes alone every one of them fits, so a
        // walk that admits fewer did so for the overhead and nothing else.
        $this->assertLessThan(
            ASW_Model_Store::LEARNED_SIMILARITY_BUDGET,
            strlen( implode( '', $learned ) ),
            'Premise: the fixture is under the budget by the token-bytes reckoning'
        );

        $admitted = ( new ReflectionMethod( ASW_Model_Store::class, 'admit_within_budget' ) )
            ->invokeArgs( $this->make_store(), array( $learned, 'latin' ) );

        $this->assertLessThanOrEqual(
            intdiv( ASW_Model_Store::LEARNED_SIMILARITY_BUDGET, ASW_Model_Store::LEARNED_SAMPLE_OVERHEAD_BYTES ),
            count( $admitted ),
            'The overhead is what bounds the number of lists a corpus can hold'
        );
        $packed = array();
        foreach ( $admitted as $tokens ) {
            $packed[] = implode( ' ', $tokens );
        }

        $this->assertLessThan(
            2 * ASW_Model_Store::LEARNED_SIMILARITY_BUDGET,
            strlen( serialize( $packed ) ),
            'The admitted corpus must be bounded in the bytes it serializes to, not only in its tokens'
        );
    }

    public function test_a_unit_is_pruned_on_a_cache_backed_host_without_zlib(): void {
        global $asw_max_cached_unit_bytes, $asw_zlib_unavailable;

        // size_limit_applies() is compression_applies() minus its zlib half,
        // and that difference is load-bearing: a host with a persistent object
        // cache but no zlib stores the *plain* packed array and still has the
        // per-item limit to respect. Collapsing the two predicates would leave
        // exactly that host writing the unbounded worst case and cold-rebuilding
        // for ever, which is the failure the mechanism exists to prevent.
        $this->with_object_cache();
        $asw_zlib_unavailable = true;

        $whole = $this->make_store( new ASW_Bulk_Training_Data() )->script_classifier( 'cyrillic' )->get_state();
        $key   = $this->cached_unit_keys()[0];
        $full  = strlen( serialize( $this->stored_value( $key ) ) );

        $GLOBALS['wp_transients']  = array();
        $GLOBALS['wp_options']     = array();
        $asw_max_cached_unit_bytes = intdiv( $full, 2 );

        $pruned = $this->make_store( new ASW_Bulk_Training_Data() )->script_classifier( 'cyrillic' )->get_state();
        $stored = $this->stored_value( $key );

        $this->assertArrayNotHasKey( 'zlib', $stored, 'A host without zlib stores the plain packed array' );
        $this->assertLessThan(
            $asw_max_cached_unit_bytes,
            strlen( serialize( $stored ) ),
            'The limit applies to a cache-backed host whether or not it can compress'
        );
        $this->assertLessThan(
            count( $whole['token_counts']['spam'] ),
            count( $pruned['token_counts']['spam'] ),
            'The vocabulary was not pruned, so the unit fitted for some other reason'
        );
    }

    public function test_the_combined_unit_is_pruned_to_fit_as_well(): void {
        global $asw_max_cached_unit_bytes;

        $this->with_object_cache();
        // The combined unit trains on every script's samples, so it is the one
        // with the most vocabulary to spend and the one a corpus edit grows.
        $whole = $this->make_store( new ASW_Bulk_Training_Data() )->combined_classifier()->get_state();
        $key   = ASW_Model_Store::TRANSIENT_PREFIX . 'combined';
        $full  = strlen( serialize( $this->stored_value( $key ) ) );

        $GLOBALS['wp_transients']  = array();
        $GLOBALS['wp_options']     = array();
        $asw_max_cached_unit_bytes = intdiv( $full, 2 );

        $pruned = $this->make_store( new ASW_Bulk_Training_Data() )->combined_classifier()->get_state();

        $this->assertLessThan(
            $asw_max_cached_unit_bytes,
            strlen( serialize( $this->stored_value( $key ) ) ),
            'A cached combined unit must be under what the cache will hold'
        );
        $this->assertLessThan(
            count( $whole['token_counts']['spam'] ),
            count( $pruned['token_counts']['spam'] ),
            'The combined unit was not pruned, so it fitted for some other reason'
        );
    }

    public function test_the_blanking_write_goes_in_uncompressed_on_a_compressing_host(): void {
        global $wp_transient_delete_fails;

        $this->with_object_cache();
        // The blanking write is the one write this class makes that skips the
        // envelope, and the rule only has teeth on a host that *would* compress.
        // Routed through wrap_unit() instead, the blanked value would go in
        // deflated -- and a later reader whose zlib has gone would inflate-fail
        // and, by holds_training_text()'s deliberately lenient rule, report the
        // now-empty unit as still holding the erased comment's text, turning a
        // clean erasure into a permanent items_retained / "please retry" loop.
        $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );
        $key = $this->cached_unit_keys()[0];
        $this->assertArrayHasKey( 'zlib', $GLOBALS['wp_transients'][ $key ], 'Premise: this host compresses' );

        $wp_transient_delete_fails = array( $key );

        try {
            $this->assertTrue(
                ASW_Model_Store::delete_units( array( $key ) ),
                'The blanking write landed, so nothing is retained'
            );

            $blanked = get_transient( $key );
            $this->assertIsArray( $blanked );
            $this->assertArrayNotHasKey(
                'zlib',
                $blanked,
                'The blanking write goes in uncompressed even where the envelope applies'
            );
            $this->assertEmpty( $blanked['samples'] );
            $this->assertNull( $blanked['classifier'] );

            // And it reads as text-free to a host that has since lost zlib,
            // which is the whole reason it skips the envelope.
            $GLOBALS['asw_zlib_unavailable'] = true;
            $this->assertTrue(
                ASW_Model_Store::delete_units( array( $key ) ),
                'A blanked unit is not retention, with or without zlib'
            );
        } finally {
            $wp_transient_delete_fails       = false;
            $GLOBALS['asw_zlib_unavailable'] = false;
        }
    }

    public function test_an_envelope_whose_payload_is_not_a_string_is_rebuilt(): void {
        global $wp_transients, $wp_options;

        $this->with_object_cache();
        // A half-written cache item or a pre_transient_* filter can leave the
        // member carrying something that is not a deflate stream at all. Without
        // the guard that value reaches gzuncompress(), which is a TypeError
        // under PHP 8 -- swallowed by ASW_Detector::run()'s fail-open catch
        // while appending a plugin-attributable line to the debug.log
        // dev/smoke.sh scans on every submission that reads it.
        $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );
        $key = $this->cached_unit_keys()[0];

        $bogus                              = array( 'zlib' => 123 );
        $wp_transients[ $key ]              = $bogus;
        $wp_options[ '_transient_' . $key ] = $bogus;

        $this->assert_unusable_stored_value_is_rebuilt( $key );
    }

    public function test_an_envelope_with_an_empty_payload_is_rebuilt(): void {
        global $wp_transients, $wp_options;

        $this->with_object_cache();
        // gzuncompress( '' ) warns and returns false; the empty member is
        // rejected ahead of it for the same reason the truncated payload is.
        $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );
        $key = $this->cached_unit_keys()[0];

        $bogus                              = array( 'zlib' => '' );
        $wp_transients[ $key ]              = $bogus;
        $wp_options[ '_transient_' . $key ] = $bogus;

        $this->assert_unusable_stored_value_is_rebuilt( $key );
    }

    public function test_a_stored_value_that_is_not_an_array_is_dropped_and_rebuilt(): void {
        global $wp_transients, $wp_options;

        // Everything this class writes is an array, so a string under a unit key
        // is a truncated or foreign value. It must be dropped rather than left
        // standing: handed on, it would reach fingerprint_matches() and
        // restore_script_unit() as a string.
        $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );
        $key = $this->cached_unit_keys()[0];

        $wp_transients[ $key ]              = 'a:2:{s:7:"samples";a:1:{i:0;';
        $wp_options[ '_transient_' . $key ] = 'a:2:{s:7:"samples";a:1:{i:0;';

        $this->assert_unusable_stored_value_is_rebuilt( $key );
    }

    public function test_a_truncated_compressed_payload_is_rebuilt_without_a_warning(): void {
        global $wp_transients, $wp_options;

        $this->with_object_cache();
        // gzuncompress() on a damaged payload emits a warning and returns false.
        // A row half-written by an object cache or a `longtext` column that
        // truncated it must rebuild the unit, not append a plugin-attributable
        // line to the debug.log dev/smoke.sh scans on every submission that
        // reads it -- phpunit.xml sets failOnWarning, so a missed suppression
        // fails here.
        $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );
        $key = $this->cached_unit_keys()[0];

        $truncated                          = array(
            'zlib' => substr( $wp_transients[ $key ]['zlib'], 0, 20 ),
        );
        $wp_transients[ $key ]              = $truncated;
        $wp_options[ '_transient_' . $key ] = $truncated;

        $this->assert_unusable_stored_value_is_rebuilt( $key );
    }

    public function test_an_envelope_holding_something_other_than_a_unit_is_rebuilt(): void {
        global $wp_transients, $wp_options;

        $this->with_object_cache();
        // A payload that inflates but is not a serialized array is a foreign
        // value under this key, and is rebuilt like any other one. The
        // unserialize() forbids classes, so it cannot be an object either.
        $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );
        $key = $this->cached_unit_keys()[0];

        $foreign                            = array( 'zlib' => gzcompress( serialize( 'buy cheap pills' ), 6 ) );
        $wp_transients[ $key ]              = $foreign;
        $wp_options[ '_transient_' . $key ] = $foreign;

        $this->assert_unusable_stored_value_is_rebuilt( $key );
    }

    /**
     * The value stored under a unit key cannot be served: the next store must
     * rebuild the unit from the corpus and re-cache it in this host's form.
     *
     * @param string $key Unit key.
     */
    private function assert_unusable_stored_value_is_rebuilt( string $key ): void {
        global $wp_transient_reads;
        $wp_transient_reads = array();

        $rebuilt_loader = new ASW_Fixed_Training_Data();
        $store          = $this->make_store( $rebuilt_loader );
        $classifier     = $store->script_classifier( 'cyrillic' );

        $this->assertGreaterThan( 0, $rebuilt_loader->loads, 'An unusable stored value must be rebuilt' );
        $this->assertInstanceOf( ASW_Naive_Bayes::class, $classifier );
        $this->assertSame( 2, $classifier->get_state()['doc_counts']['spam'] );

        // Re-cached in the form this host reads, so the next request is warm.
        $recached = $this->stored_unit( $key );
        $this->assertIsArray( $recached['classifier'] );
        $this->assertNotSame( array(), $recached['samples'] );

        $warm_loader = new ASW_Recording_Training_Data();
        $this->assertNotSame(
            array(),
            $this->make_store( $warm_loader )->script_sample_tokens( 'cyrillic' )
        );
        $this->assertSame( array(), $warm_loader->load_script_calls, 'The rebuild must be served from the cache' );
    }

    public function test_a_compressed_unit_holding_text_is_reported_as_retention(): void {
        global $wp_transients, $wp_options, $wp_transient_delete_fails, $wp_update_option_fails, $wpdb,
            $wp_using_ext_object_cache;

        // holds_training_text() is lenient where the serving path is strict: a
        // compressed value is inflated and judged by its two text members, so a
        // unit the erasure cannot drop is reported as retention rather than
        // passed over as a shape nothing recognises.
        //
        // A compressed value is only ever written into an external object
        // cache, so one sitting in a `_transient_` option row is a row stranded
        // when that cache was removed again -- and the only shape in which a
        // compressed unit can meet the option-API levers this test refuses.
        $this->with_object_cache();
        $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );
        $key      = $this->cached_unit_keys()[0];
        $stranded = $wp_transients[ $key ];
        unset( $stranded['__ttl'] );
        $this->assertArrayHasKey( 'zlib', $stranded, 'Premise: the cache holds the unit compressed' );

        $wp_using_ext_object_cache          = false;
        $wp_transients[ $key ]              = $stranded;
        $wp_options[ '_transient_' . $key ] = $stranded;

        $wp_transient_delete_fails = array( $key );
        $wp_update_option_fails    = true;
        $wpdb->query_result        = false;

        // A unit nothing could drop is logged, so the diagnostic goes to a file
        // of this test's own rather than to the suite's output.
        $tmp     = tempnam( sys_get_temp_dir(), 'asw_test_' );
        $old_log = ini_set( 'error_log', $tmp );

        try {
            $this->assertFalse(
                ASW_Model_Store::delete_units( array( $key ) ),
                'A compressed unit no lever could drop still holds the erased comment as tokens'
            );
        } finally {
            ini_set( 'error_log', $old_log );
            $wp_transient_delete_fails = false;
            $wp_update_option_fails    = false;
            $wpdb->query_result        = null;
        }

        unlink( $tmp );

        // Read leniently, the way the eraser reads it: the serving path would
        // reject this value outright, which is what makes it retention.
        $survivor = $wp_options[ '_transient_' . $key ];
        $this->assertArrayHasKey( 'zlib', $survivor, 'Premise: the compressed value really did survive' );
        $inflated = unserialize( gzuncompress( $survivor['zlib'] ), array( 'allowed_classes' => false ) );
        $this->assertNotSame( array(), $inflated['samples'], 'Premise: the text really did survive' );
    }

    public function test_a_compressed_envelope_that_will_not_inflate_is_reported_as_holding_text(): void {
        global $wp_transients, $wp_options, $wp_transient_delete_fails, $asw_zlib_unavailable;

        $this->with_object_cache();
        // The conservative reading an erasure has to take: a payload this host
        // cannot inflate -- zlib gone since the unit was written, or a row cut
        // short -- is still the erased comment's token lists, so it is blanked
        // rather than passed over as text-free.
        $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );
        $key = $this->cached_unit_keys()[0];

        $asw_zlib_unavailable      = true;
        $wp_transient_delete_fails = array( $key );

        try {
            $this->assertTrue(
                ASW_Model_Store::delete_units( array( $key ) ),
                'The blanking write landed, so nothing is retained'
            );

            $blanked = get_transient( $key );
            $this->assertIsArray( $blanked );
            $this->assertArrayNotHasKey(
                'zlib',
                $blanked,
                'The blanking write goes in uncompressed, readable without zlib'
            );
            $this->assertEmpty( $blanked['samples'] );
            $this->assertNull( $blanked['classifier'] );
        } finally {
            $wp_transient_delete_fails = false;
        }
    }

    public function test_a_blanked_unit_is_not_reported_as_retention(): void {
        global $wp_transient_delete_fails;

        // The other side: the blanking write is plain, and a second purge over
        // it must read it as the text-free unit it is rather than as an
        // unrecognised shape -- an erasure that answered with a database-error
        // message over a value carrying nothing would ask for a retry for
        // nothing.
        $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );
        $key = $this->cached_unit_keys()[0];

        $wp_transient_delete_fails = array( $key );

        try {
            $this->assertTrue( ASW_Model_Store::delete_units( array( $key ) ) );
            $this->assertTrue(
                ASW_Model_Store::delete_units( array( $key ) ),
                'A unit already blanked holds no text and is not retention'
            );
        } finally {
            $wp_transient_delete_fails = false;
        }
    }

    public function test_a_compressed_text_free_unit_is_not_reported_as_retention(): void {
        global $wp_transients, $wp_transient_delete_fails;

        // The compressed twin of the case above, and the one that tells the
        // lenient rule apart from "any envelope holds text". A script with no
        // samples at all caches a real unit carrying `classifier => null` and
        // `samples => []`, and on a cache-backed host with zlib that goes in
        // deflated. Reading it without inflating would answer an erasure with a
        // database-error message and a retry request over a unit holding
        // nothing.
        $this->with_object_cache();
        $this->make_store( new ASW_Recording_Training_Data() )->script_classifier( 'cyrillic' );
        $key = $this->cached_unit_keys()[0];

        $stored = $wp_transients[ $key ];
        $this->assertArrayHasKey( 'zlib', $stored, 'Premise: the text-free unit really is stored compressed' );

        $wp_transient_delete_fails = array( $key );

        try {
            $this->assertTrue(
                ASW_Model_Store::delete_units( array( $key ) ),
                'A compressed unit carrying no text is not retention, however refused the delete was'
            );
        } finally {
            $wp_transient_delete_fails = false;
        }

        // And nothing was spent on it: a value holding no text needs no lever.
        $this->assertSame( $stored, $wp_transients[ $key ], 'A text-free unit must be left exactly as it was' );
    }

    // --- the narrowed combined similarity corpus ---

    /**
     * A store over the legacy-and-script corpus.
     *
     * Same repository as make_store(), since the combined fingerprint reads the
     * sample table -- a store built without one would not match a unit written
     * by a store that had one.
     *
     * @param ASW_Sample_Repository|null $repository    Repository, default a fresh one.
     * @param ASW_Training_Data|null     $training_data Loader, default the legacy-and-script one.
     */
    private function make_combined_store(
        ?ASW_Sample_Repository $repository = null,
        ?ASW_Training_Data $training_data = null
    ): ASW_Model_Store {
        global $wpdb;

        return new ASW_Model_Store(
            new ASW_Tokenizer( array() ),
            array(),
            $training_data ?? new ASW_Legacy_And_Script_Training_Data(),
            $repository ?? new ASW_Sample_Repository( $wpdb )
        );
    }

    /**
     * Tokenise a sample the way build_combined_unit() does.
     *
     * @param string $sample Sample text.
     * @return array<int, string>
     */
    private function combined_tokens_for( string $sample ): array {
        return ( new ASW_Tokenizer( array() ) )
            ->tokenize_for_script( $sample, ASW_Script_Detector::detect( $sample ) );
    }

    public function test_the_combined_similarity_corpus_holds_only_the_legacy_and_unknown_script_lists(): void {
        global $wpdb;

        // No representation gets the combined unit under the 1 MB
        // object-cache item limit while it also carries every script's
        // similarity lists. It is consulted for a body whose own script has
        // no list, which is four routes and not one: on the shipped corpus
        // every named script has a list, so what it serves is every body
        // ASW_Script_Detector::detect() sends to unknown -- one with no
        // letters at all, one written in a script outside SCRIPT_GROUPS, and
        // one whose detection hit a PCRE limit -- plus a named script whose
        // own (spam-only) list came out empty. The score moves little for the
        // first and the last, and can be lost outright for the middle two;
        // the deviation is accepted and recorded in the docblock.
        $repo = new ASW_Sample_Repository( $wpdb );
        $repo->insert( 'unknown', 'spam', '89139991205, 89001234567, 89005553535', 9101 );

        $samples = $this->make_combined_store( $repo )->combined_sample_tokens();

        $this->assertSame(
            array(
                $this->combined_tokens_for( ASW_Legacy_And_Script_Training_Data::LEGACY_SPAM ),
                $this->combined_tokens_for( '89139991205, 89001234567, 89005553535' ),
            ),
            $samples,
            'The combined corpus is exactly the legacy lists plus the unknown-script rows'
        );
        $this->assertNotContains(
            $this->combined_tokens_for( 'buy cheap pills online now' ),
            $samples,
            'No per-script list may be stored in the combined unit'
        );
    }

    public function test_the_combined_unit_still_reports_script_samples_it_no_longer_stores(): void {
        // has_script_samples is the ONLY input to
        // ASW_Similarity_Check::resolve_from_store()'s legacy-vs-script
        // tokenisation branch, so it must stay a fact about the loaded
        // per-script samples rather than about what the unit carries -- a flip
        // to false would put every fallback body on word tokenisation.
        $with = $this->make_combined_store();

        $this->assertTrue( $with->combined_has_script_samples() );
        foreach ( $with->combined_sample_tokens() as $tokens ) {
            $this->assertNotContains( 'pills', $tokens, 'Premise: the per-script lists really are gone' );
        }

        global $wp_transients, $wp_options, $wp_transient_reads;
        $wp_transients      = array();
        $wp_options         = array();
        $wp_transient_reads = array();

        $without = $this->make_combined_store( null, new ASW_Recording_Training_Data() );
        $this->assertFalse(
            $without->combined_has_script_samples(),
            'With no script producing tokens the Similarity check must fall back to legacy tokenize()'
        );
    }

    public function test_the_combined_classifier_still_trains_on_every_scripts_samples(): void {
        global $wpdb;

        // Only the similarity corpus is narrowed. The Bayesian side keeps its
        // whole training set and its order -- legacy, then each script in
        // SCRIPT_GROUPS order, then the unknown-script rows -- so its verdicts
        // are byte-identical.
        $unknown_spam = '89139991205, 89001234567, 89005553535';
        $unknown_ham  = '2019, 2020, 2021, 2022';

        $repo = new ASW_Sample_Repository( $wpdb );
        $repo->insert( 'unknown', 'spam', $unknown_spam, 9102 );
        $repo->insert( 'unknown', 'ham', $unknown_ham, 9103 );

        $loader = new ASW_Legacy_And_Script_Training_Data();
        $state  = $this->make_combined_store( $repo, $loader )->combined_classifier()->get_state();

        $spam = array( ASW_Legacy_And_Script_Training_Data::LEGACY_SPAM );
        $ham  = array( ASW_Legacy_And_Script_Training_Data::LEGACY_HAM );
        foreach ( array_keys( ASW_Script_Detector::SCRIPT_GROUPS ) as $script ) {
            $spam = array_merge( $spam, $loader->load_script_samples( $script, 'spam' ) );
            $ham  = array_merge( $ham, $loader->load_script_samples( $script, 'ham' ) );
        }
        $spam[] = $unknown_spam;
        $ham[]  = $unknown_ham;

        $expected = new ASW_Naive_Bayes( new ASW_Tokenizer( array() ) );
        ( new ASW_Training_Data() )->train_classifier_with_samples( $expected, $spam, $ham );

        $this->assertSame( $expected->get_state(), $state );
        $this->assertSame( 22, $state['doc_counts']['spam'], 'Every script still trains the combined classifier' );
        $this->assertSame( 22, $state['doc_counts']['ham'] );
    }

    public function test_a_cyrillic_bodys_similarity_corpus_and_score_are_unchanged(): void {
        // A script with a unit of its own never reads the combined one, so the
        // narrowing cannot reach it.
        $store   = $this->make_combined_store();
        $samples = $store->script_sample_tokens( 'cyrillic' );

        $tokenizer = new ASW_Tokenizer( array() );
        $expected  = array(
            $tokenizer->tokenize_for_script( 'buy cheap pills online now', 'cyrillic' ),
            $tokenizer->tokenize_for_script( 'cheap pills discount pharmacy', 'cyrillic' ),
        );

        $this->assertSame( $expected, $samples );
        $this->assertSame(
            array( ASW_Model_Store::TRANSIENT_PREFIX . 'cyrillic' ),
            $this->reads(),
            'A script with its own samples must not load the combined unit'
        );

        $similarity = new ASW_Cosine_Similarity();
        $body       = $tokenizer->tokenize_for_script( 'cheap pills discount pharmacy', 'cyrillic' );
        $this->assertSame(
            $similarity->compare( $expected[1], $body ),
            $similarity->compare( $samples[1], $body ),
            'The per-script similarity score is untouched'
        );
        $this->assertGreaterThan( 0.9, $similarity->compare( $samples[1], $body ) );
    }

    public function test_unit_keys_names_every_unit_and_delete_units_removes_them(): void {
        // ASW_Privacy purges these after erasing samples. The list has to be
        // exhaustive, which is why a unit's key names the unit and carries no
        // fingerprint: a rebuild overwrites the unit it replaces, so there is
        // never a generation the eraser cannot name.
        $store = $this->make_store( new ASW_Fixed_Training_Data() );
        $keys  = ASW_Model_Store::unit_keys();

        $this->assertCount( count( ASW_Script_Detector::SCRIPT_GROUPS ) + 1, $keys );
        $this->assertSame( $keys, array_unique( $keys ) );
        foreach ( $keys as $key ) {
            $this->assertStringStartsWith( ASW_Model_Store::TRANSIENT_PREFIX, $key );
        }

        $store->script_classifier( 'cyrillic' );
        $store->combined_classifier();
        $this->assertNotEmpty( $this->cached_unit_keys() );

        ASW_Model_Store::delete_units( $keys );

        $this->assertSame( array(), $this->cached_unit_keys() );
    }

    public function test_delete_units_ignores_keys_it_does_not_own(): void {
        set_transient( 'asw_spam_log_unrelated', 'keep me', DAY_IN_SECONDS );

        ASW_Model_Store::delete_units( array( 'asw_spam_log_unrelated', 42, null ) );

        $this->assertSame( 'keep me', get_transient( 'asw_spam_log_unrelated' ) );
    }

    public function test_a_refused_delete_whose_text_was_stripped_is_not_reported_as_retained(): void {
        global $wp_transient_delete_fails;

        // What ASW_Privacy::erase() must hear about is training text that is
        // still stored, not a key that is still there. A unit the backend would
        // not delete but whose value was blanked holds neither samples nor
        // classifier state, so no personal data survived it -- answering the
        // erasure request with a database-error message and a "please retry"
        // over that would report a retention that did not happen.
        $store = $this->make_store( new ASW_Fixed_Training_Data() );
        $store->script_classifier( 'cyrillic' );
        $key = $this->cached_unit_keys()[0];

        // Premise: the unit stores its samples packed, one space-delimited
        // string per list. holds_training_text() reads `samples` for emptiness
        // alone, so the packed representation is still recognised as text --
        // which is what the blanking write below can only have followed from.
        $packed = asw_test_unwrap_unit( get_transient( $key ) )['samples'];
        $this->assertNotSame( array(), $packed );
        $this->assertIsString( $packed[0] );

        $wp_transient_delete_fails = array( $key );

        try {
            $this->assertTrue( ASW_Model_Store::delete_units( ASW_Model_Store::unit_keys() ) );

            $stored = get_transient( $key );
            $this->assertIsArray( $stored, 'Premise: the key itself outlived the refused delete' );
            $this->assertEmpty( $stored['samples'] ?? array() );
            $this->assertEmpty( $stored['classifier'] ?? array() );
        } finally {
            $wp_transient_delete_fails = false;
        }
    }

    public function test_delete_units_reports_success_for_units_that_were_not_cached(): void {
        // Ten of the eleven keys are normally absent, and delete_transient()
        // returns false for every one of them. Reading that as a failed purge
        // would make every erasure report data retained.
        $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );

        $this->assertTrue( ASW_Model_Store::delete_units( ASW_Model_Store::unit_keys() ) );
        $this->assertSame( array(), $this->cached_unit_keys() );
    }

    public function test_delete_units_sees_an_expired_row_a_refused_cleanup_left_behind(): void {
        global $wp_options, $wp_transient_delete_fails, $wp_transient_expired;

        // `false === get_transient()` is not proof of deletion. For an expired
        // database-backed transient WordPress deletes both option rows on read
        // and returns false whether or not those deletes landed, so a unit that
        // expired before the erasure request arrived would read back as purged
        // while the row -- and the erased comment's text in it -- stays in
        // wp_options.
        $key = ASW_Model_Store::unit_keys()[0];
        set_transient(
            $key,
            array(
                'fingerprint' => 'whatever',
                'classifier'  => null,
                'samples'     => array( array( 'buy', 'cheap', 'pills' ) ),
            ),
            DAY_IN_SECONDS
        );

        $wp_transient_expired      = array( $key );
        $wp_transient_delete_fails = array( $key );

        try {
            $this->assertFalse( get_transient( $key ), 'Premise: the transient API reports a miss' );
            $this->assertArrayHasKey(
                '_transient_' . $key,
                $wp_options,
                'Premise: the refused cleanup left the row behind'
            );

            $this->assertTrue( ASW_Model_Store::delete_units( ASW_Model_Store::unit_keys() ) );

            // Only a purge that consulted the row could have emptied it: had it
            // trusted get_transient()'s miss, the samples would still be there.
            $this->assertEmpty(
                $wp_options[ '_transient_' . $key ]['samples'] ?? array(),
                'The row the transient API reported as gone must stop holding the erased text'
            );
        } finally {
            $wp_transient_expired      = false;
            $wp_transient_delete_fails = false;
        }
    }

    public function test_delete_units_removes_a_unit_row_no_key_can_name(): void {
        global $wp_options;

        // Earlier versions cached one monolithic model under
        // `asw_classifiers_<md5>`, and the md5 folded in corpus mtimes and the
        // sample revision -- so after an upgrade that row is in wp_options
        // holding the trained comments' text as a token list, under a key this
        // version cannot compute. An erasure that only named its own eleven
        // keys would report success over it.
        $legacy = ASW_Model_Store::TRANSIENT_PREFIX . md5( 'a previous version of this plugin' );
        set_transient(
            $legacy,
            array(
                'classifiers' => array( 'cyrillic' => array( 'doc_counts' => array( 'spam' => 1 ) ) ),
                'samples'     => array( array( 'buy', 'cheap', 'pills' ) ),
            ),
            DAY_IN_SECONDS
        );
        $wp_options[ '_transient_timeout_' . $legacy ] = time() + DAY_IN_SECONDS;

        $this->assertTrue( ASW_Model_Store::delete_units( ASW_Model_Store::unit_keys() ) );

        $this->assertSame( array(), $this->cached_unit_keys() );
        $this->assertArrayNotHasKey( '_transient_' . $legacy, $wp_options );
        $this->assertArrayNotHasKey( '_transient_timeout_' . $legacy, $wp_options );
    }

    public function test_delete_units_removes_unit_rows_an_object_cache_stranded(): void {
        global $wp_options, $wp_using_ext_object_cache;

        // Dropping an object cache into a site that had none leaves every unit
        // row it had already written in wp_options: from then on WordPress
        // reads and writes transients in the cache, and core's expired-transient
        // cleanup returns early under an external cache, so nothing ever
        // collects them. The prefix sweep is what reaches them.
        $key = ASW_Model_Store::unit_keys()[0];
        set_transient(
            $key,
            array(
                'fingerprint' => 'from before the object cache',
                'classifier'  => null,
                'samples'     => array( array( 'buy', 'cheap', 'pills' ) ),
            ),
            DAY_IN_SECONDS
        );

        $wp_using_ext_object_cache = true;

        try {
            $this->assertTrue( ASW_Model_Store::delete_units( ASW_Model_Store::unit_keys() ) );
            $this->assertArrayNotHasKey( '_transient_' . $key, $wp_options );
        } finally {
            $wp_using_ext_object_cache = false;
        }
    }

    public function test_delete_units_reports_a_stray_row_sweep_the_database_refused(): void {
        global $wpdb;

        // The sweep is part of the purge, so a DELETE the database refused is
        // the same loss as a refused delete_transient(): rows holding the
        // erased text are still there and the erasure must say so.
        $wpdb->query_result = false;

        $tmp     = tempnam( sys_get_temp_dir(), 'asw_test_' );
        $old_log = ini_set( 'error_log', $tmp );

        try {
            $this->assertFalse( ASW_Model_Store::delete_units( ASW_Model_Store::unit_keys() ) );
        } finally {
            ini_set( 'error_log', $old_log );
            $wpdb->query_result = null;
        }

        $logged = (string) file_get_contents( $tmp );
        unlink( $tmp );

        $this->assertStringContainsString( 'model cache purge retained a unit', $logged );
    }

    public function test_a_unit_no_write_could_reach_has_its_rows_deleted(): void {
        global $wp_options, $wp_transient_delete_fails, $wp_update_option_fails;

        // Every write through the options API refused at once -- the delete and
        // the blanking write -- which is the state in which the erased text
        // would otherwise stay readable for the rest of the unit's day. One
        // lever is left that is not such a write: the unit's own wp_options
        // rows. Without an external object cache a transient *is* those rows,
        // so deleting them stops the erased text being stored even though the
        // transient API refused.
        $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );
        $key = $this->cached_unit_keys()[0];

        $wp_transient_delete_fails = true;
        $wp_update_option_fails    = true;

        try {
            $this->assertTrue(
                ASW_Model_Store::delete_units( ASW_Model_Store::unit_keys() ),
                'The rows are gone, so no training text was retained'
            );
        } finally {
            $wp_transient_delete_fails = false;
            $wp_update_option_fails    = false;
        }

        $this->assertArrayNotHasKey( '_transient_' . $key, $wp_options );
        $this->assertArrayNotHasKey( '_transient_timeout_' . $key, $wp_options );
        $this->assertFalse( get_transient( $key ) );
    }

    public function test_a_unit_that_survives_every_lever_is_reported_and_logged(): void {
        global $wpdb, $wp_options, $wp_transient_delete_fails, $wp_update_option_fails;

        // And when the database refuses the statement too, the erased text is
        // still stored and no lever is left to remove it, so the loss is
        // logged: no other signal on the site would show it. The next erasure
        // request purges every key again -- it does not have to be told to --
        // but nothing guarantees one ever arrives.
        $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );
        $key = $this->cached_unit_keys()[0];

        $wp_transient_delete_fails = true;
        $wp_update_option_fails    = true;
        $wpdb->query_result        = false;

        $tmp     = tempnam( sys_get_temp_dir(), 'asw_test_' );
        $old_log = ini_set( 'error_log', $tmp );

        try {
            $this->assertFalse( ASW_Model_Store::delete_units( ASW_Model_Store::unit_keys() ) );
        } finally {
            ini_set( 'error_log', $old_log );
            $wp_transient_delete_fails = false;
            $wp_update_option_fails    = false;
            $wpdb->query_result        = null;
        }

        $logged = file_get_contents( $tmp );
        unlink( $tmp );

        $stored = asw_test_unwrap_unit( $wp_options[ '_transient_' . $key ] ?? false );
        $this->assertIsArray( $stored, 'Premise: the row survived every lever' );
        $this->assertNotEmpty( $stored['samples'], 'Premise: the erased text is still stored' );
        $this->assertStringContainsString( 'model cache purge retained a unit', $logged );
    }

    public function test_a_purge_that_removed_the_text_logs_nothing(): void {
        global $wp_transient_delete_fails;

        // The counterpart: the delete was refused but the blanking write took
        // the text out, so there is nothing left to remove and nothing to
        // report.
        $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );

        $wp_transient_delete_fails = true;

        $tmp     = tempnam( sys_get_temp_dir(), 'asw_test_' );
        $old_log = ini_set( 'error_log', $tmp );

        try {
            $this->assertTrue( ASW_Model_Store::delete_units( ASW_Model_Store::unit_keys() ) );
        } finally {
            ini_set( 'error_log', $old_log );
            $wp_transient_delete_fails = false;
        }

        $logged = file_get_contents( $tmp );
        unlink( $tmp );

        $this->assertSame( '', $logged );
    }

    public function test_a_unit_value_that_is_not_an_array_is_stripped_rather_than_trusted(): void {
        global $wp_transients, $wp_options, $wp_transient_delete_fails;

        // Every value this class writes is an array, so a string under a unit
        // key is something else -- a truncated or otherwise damaged
        // serialization, which maybe_unserialize() hands back as the raw string
        // it is. That string *is* the unit's token lists, so reading "not an
        // array" as "holds no personal data" would close the erasure over the
        // erased comment's text and leave it readable for the rest of the day.
        $key     = ASW_Model_Store::unit_keys()[0];
        $corrupt = 'a:2:{s:7:"samples";a:1:{i:0;a:3:{i:0;s:3:"buy";i:1;s:5:"cheap";i:2;s:5:"pills';

        $wp_transients[ $key ]              = $corrupt;
        $wp_options[ '_transient_' . $key ] = $corrupt;

        $wp_transient_delete_fails = array( $key );

        try {
            $this->assertSame( $corrupt, get_transient( $key ), 'Premise: the damaged value is what is stored' );

            $this->assertTrue(
                ASW_Model_Store::delete_units( ASW_Model_Store::unit_keys() ),
                'The blanking write landed, so nothing is retained'
            );

            $this->assertNotSame(
                $corrupt,
                get_transient( $key ),
                'A value that cannot be inspected member by member must still be blanked'
            );
            $this->assertStringNotContainsString(
                'pills',
                var_export( get_transient( $key ), true ),
                'No part of the erased text may survive the purge'
            );
        } finally {
            $wp_transient_delete_fails = false;
        }
    }

    public function test_a_unit_array_of_an_unknown_shape_is_stripped_rather_than_trusted(): void {
        global $wp_transients, $wp_options, $wp_transient_delete_fails;

        // Being an array is not evidence that a value came from this class, and
        // "no `samples` member, therefore no personal data" is an inference
        // about a shape nothing here defines. An array carrying its token lists
        // under some other member would pass that test while holding exactly the
        // text the erasure is meant to remove, so an unrecognised non-empty
        // member is read the same way a non-array value is.
        $key     = ASW_Model_Store::unit_keys()[0];
        $corrupt = array( 'payload' => array( array( 'buy', 'cheap', 'pills' ) ) );

        $wp_transients[ $key ]              = $corrupt;
        $wp_options[ '_transient_' . $key ] = $corrupt;

        $wp_transient_delete_fails = array( $key );

        try {
            $this->assertSame( $corrupt, get_transient( $key ), 'Premise: the unrecognised value is what is stored' );

            $this->assertTrue(
                ASW_Model_Store::delete_units( ASW_Model_Store::unit_keys() ),
                'The blanking write landed, so nothing is retained'
            );

            $this->assertStringNotContainsString(
                'pills',
                var_export( get_transient( $key ), true ),
                'No part of the erased text may survive the purge'
            );
        } finally {
            $wp_transient_delete_fails = false;
        }
    }

    public function test_a_unit_shaped_array_missing_a_text_member_is_stripped_rather_than_trusted(): void {
        global $wp_transients, $wp_options, $wp_transient_delete_fails;

        // One member this class knows the name of is not evidence that the
        // value came from here: recognition asks for the whole shape every
        // write produces. A value carrying a `fingerprint` and its token lists
        // under some other member is the unknown-shape case one member further
        // in -- read as a text-free unit it would be neither blanked nor row
        // deleted, and excluded from the stray-row sweep as an owned key, while
        // the erasure reported success over the tokens still in it.
        $key     = ASW_Model_Store::unit_keys()[0];
        $corrupt = array(
            'fingerprint' => 'abc123',
            'payload'     => array( array( 'buy', 'cheap', 'pills' ) ),
        );

        $wp_transients[ $key ]              = $corrupt;
        $wp_options[ '_transient_' . $key ] = $corrupt;

        $wp_transient_delete_fails = array( $key );

        try {
            $this->assertSame( $corrupt, get_transient( $key ), 'Premise: the unrecognised value is what is stored' );

            $this->assertTrue(
                ASW_Model_Store::delete_units( ASW_Model_Store::unit_keys() ),
                'The blanking write landed, so nothing is retained'
            );

            $this->assertStringNotContainsString(
                'pills',
                var_export( get_transient( $key ), true ),
                'No part of the erased text may survive the purge'
            );
        } finally {
            $wp_transient_delete_fails = false;
        }
    }

    public function test_a_unit_carrying_only_members_this_class_writes_is_not_stripped(): void {
        global $wp_transients, $wp_options, $wp_transient_delete_fails;

        // The other side of the same rule: a unit for a script with no samples
        // at all is a value this class does write, and it holds no text. A
        // refused delete over it must not be reported as retention, or an
        // erasure request answers with a database-error message about a unit
        // that carries nothing.
        $key   = ASW_Model_Store::unit_keys()[0];
        $empty = array(
            'fingerprint'        => 'abc123',
            'classifier'         => null,
            'samples'            => array(),
            'has_script_samples' => false,
        );

        $wp_transients[ $key ]              = $empty;
        $wp_options[ '_transient_' . $key ] = $empty;

        $wp_transient_delete_fails = array( $key );

        try {
            $this->assertTrue(
                ASW_Model_Store::delete_units( ASW_Model_Store::unit_keys() ),
                'A unit holding no text is not retention'
            );

            $this->assertSame(
                $empty,
                get_transient( $key ),
                'A text-free unit needs no blanking write'
            );
        } finally {
            $wp_transient_delete_fails = false;
        }
    }

    public function test_a_text_free_unit_carrying_a_foreign_member_is_not_stripped(): void {
        global $wp_transients, $wp_options, $wp_transient_delete_fails;

        // The other half of requiring the whole shape: a value that has it is
        // read by its two text members alone, whatever else it carries.
        // `pre_set_transient_*`/`option_*` filters are ordinary WordPress, and a
        // member another plugin added would otherwise make every erasure blank a
        // unit that holds nothing -- and then report retention, since the
        // blanking write carries that member straight back.
        $key      = ASW_Model_Store::unit_keys()[0];
        $filtered = array(
            'fingerprint'        => 'abc123',
            'classifier'         => null,
            'samples'            => array(),
            'has_script_samples' => false,
            'some_other_plugin'  => 'metadata',
        );

        $wp_transients[ $key ]              = $filtered;
        $wp_options[ '_transient_' . $key ] = $filtered;

        $wp_transient_delete_fails = array( $key );

        try {
            $this->assertTrue(
                ASW_Model_Store::delete_units( ASW_Model_Store::unit_keys() ),
                'A unit holding no text is not retention, whatever else it carries'
            );

            $this->assertSame(
                $filtered,
                get_transient( $key ),
                'A text-free unit needs no blanking write'
            );
        } finally {
            $wp_transient_delete_fails = false;
        }
    }

    public function test_a_superseded_unit_the_backend_would_not_drop_is_stripped_of_its_samples(): void {
        global $wpdb, $wp_options, $wp_on_set_transient, $wp_transient_delete_fails;

        // The post-write guard cannot fire its delete and walk away: the erasure
        // that moved the fingerprint has already purged this key and closed its
        // request, so a refused delete leaves the pre-erasure tokens this
        // request just wrote as the only copy, with nothing left to remove them.
        // Deleting is not the only way to stop storing them -- the row is
        // overwritten with a value carrying neither samples nor classifier
        // state, which this request can do because its own write has just
        // landed.
        $repository = new ASW_Sample_Repository( $wpdb );
        $repository->insert( 'cyrillic', 'spam', 'продам гараж, пишите', 4242 );

        $erased              = false;
        $wp_on_set_transient = static function ( $key ) use ( $repository, &$erased ): void {
            if ( $erased || ! str_starts_with( (string) $key, ASW_Model_Store::TRANSIENT_PREFIX ) ) {
                return;
            }
            $erased = true;
            $repository->delete_by_comment_ids( array( 4242 ) );
        };
        $wp_transient_delete_fails = true;

        try {
            $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );
        } finally {
            $wp_on_set_transient       = null;
            $wp_transient_delete_fails = false;
        }

        $this->assertTrue( $erased, 'Premise: the erasure must land inside the write window' );

        $stored = $wp_options[ '_transient_' . ASW_Model_Store::TRANSIENT_PREFIX . 'cyrillic' ] ?? false;
        $this->assertIsArray( $stored, 'Premise: the refused delete leaves the row behind' );
        $this->assertEmpty( $stored['samples'] ?? array(), 'The tokens this request wrote are gone' );
        $this->assertEmpty( $stored['classifier'] ?? array(), 'And so are the word counts built from them' );
        $this->assertArrayNotHasKey( 'fingerprint', $stored, 'A value with no fingerprint is rebuilt rather than served' );
    }

    public function test_a_superseded_unit_the_options_api_would_not_drop_has_its_rows_deleted(): void {
        global $wpdb, $wp_options, $wp_on_set_transient, $wp_transient_delete_fails, $wp_update_option_fails;

        // And when the backend refuses that write as well, there is one lever
        // left that does not go through the options API at all: the unit's own
        // wp_options rows, deleted by statement. Without an external object
        // cache a transient *is* those rows, so taking them stops the erased
        // text being stored.
        $repository = new ASW_Sample_Repository( $wpdb );
        $repository->insert( 'cyrillic', 'spam', 'продам гараж, пишите', 4242 );

        $writes              = 0;
        $wp_on_set_transient = static function ( $key ) use ( $repository, &$writes ): void {
            if ( ! str_starts_with( (string) $key, ASW_Model_Store::TRANSIENT_PREFIX ) ) {
                return;
            }
            ++$writes;
            if ( 1 === $writes ) {
                // The erasure lands in the window between the pre-write check
                // and this write.
                $repository->delete_by_comment_ids( array( 4242 ) );
                return;
            }
            // The guard's blanking write, refused.
            $GLOBALS['wp_update_option_fails'] = array( '_transient_' . $key );
        };
        $wp_transient_delete_fails = true;

        try {
            $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );
        } finally {
            $wp_on_set_transient       = null;
            $wp_transient_delete_fails = false;
            $wp_update_option_fails    = false;
        }

        $this->assertSame( 2, $writes, 'Premise: the guard must attempt the blanking write' );

        $key = ASW_Model_Store::TRANSIENT_PREFIX . 'cyrillic';
        $this->assertArrayNotHasKey( '_transient_' . $key, $wp_options );
        $this->assertArrayNotHasKey( '_transient_timeout_' . $key, $wp_options );
    }

    public function test_a_superseded_unit_that_survives_every_lever_is_logged(): void {
        global $wpdb, $wp_options, $wp_on_set_transient, $wp_transient_delete_fails, $wp_update_option_fails;

        // The delete refused, the blanking write refused and the database
        // refusing the statement too: the unit is still holding the erased text
        // and there is no lever left, so the loss is logged. Nothing on the site
        // would otherwise show it -- and the erasure this superseded is already
        // over, so there is no response left to report it in.
        $repository = new ASW_Sample_Repository( $wpdb );
        $repository->insert( 'cyrillic', 'spam', 'продам гараж, пишите', 4242 );

        $writes              = 0;
        $wp_on_set_transient = static function ( $key ) use ( $repository, &$writes ): void {
            if ( ! str_starts_with( (string) $key, ASW_Model_Store::TRANSIENT_PREFIX ) ) {
                return;
            }
            ++$writes;
            if ( 1 === $writes ) {
                $repository->delete_by_comment_ids( array( 4242 ) );
                return;
            }
            $GLOBALS['wp_update_option_fails'] = array( '_transient_' . $key );
            // Armed only now: the erasure above is a DELETE of its own.
            $GLOBALS['wpdb']->query_result = false;
        };
        $wp_transient_delete_fails = true;

        $tmp     = tempnam( sys_get_temp_dir(), 'asw_test_' );
        $old_log = ini_set( 'error_log', $tmp );

        try {
            $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );
        } finally {
            ini_set( 'error_log', $old_log );
            $wp_on_set_transient       = null;
            $wp_transient_delete_fails = false;
            $wp_update_option_fails    = false;
            $wpdb->query_result        = null;
        }

        $logged = (string) file_get_contents( $tmp );
        unlink( $tmp );

        $this->assertSame( 2, $writes, 'Premise: the guard must attempt the blanking write' );

        $stored = asw_test_unwrap_unit(
            $wp_options[ '_transient_' . ASW_Model_Store::TRANSIENT_PREFIX . 'cyrillic' ] ?? false
        );
        $this->assertIsArray( $stored );
        $this->assertNotEmpty( $stored['samples'], 'Premise: the erased text is still stored' );
        $this->assertStringContainsString( 'model cache purge retained a unit', $logged );
    }

    public function test_a_unit_superseded_between_the_check_and_the_write_is_dropped(): void {
        global $wpdb, $wp_on_set_transient;

        // ASW_Privacy::erase() deletes the subject's sample rows and then purges
        // every unit key. When that whole erasure lands in the window between
        // this store's pre-write check and its set_transient(), the write that
        // follows puts the pre-erasure tokens straight back into the row the
        // eraser had just emptied -- erased personal data restored, readable for
        // the rest of the unit's day, with nothing left to purge it. The seam
        // fires before the write, which is exactly that ordering.
        $repository = new ASW_Sample_Repository( $wpdb );
        $repository->insert( 'cyrillic', 'spam', 'продам гараж, пишите', 4242 );

        $erased = false;
        $wp_on_set_transient = static function ( $key ) use ( $repository, &$erased ): void {
            if ( $erased || ! str_starts_with( (string) $key, ASW_Model_Store::TRANSIENT_PREFIX ) ) {
                return;
            }
            $erased = true;
            $repository->delete_by_comment_ids( array( 4242 ) );
            ASW_Model_Store::delete_units( ASW_Model_Store::unit_keys() );
        };

        try {
            $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );
        } finally {
            $wp_on_set_transient = null;
        }

        $this->assertTrue( $erased, 'Premise: the erasure must land inside the write window' );
        $this->assertSame(
            array(),
            $this->cached_unit_keys(),
            'A unit written over a concurrent erasure\'s purge must not survive it'
        );
    }

    public function test_a_combined_unit_superseded_between_the_check_and_the_write_is_dropped(): void {
        global $wpdb, $wp_on_set_transient;

        // Same race, duplicated in combined_unit() -- and worse there, since the
        // combined unit carries every script's samples.
        $repository = new ASW_Sample_Repository( $wpdb );
        $repository->insert( 'unknown', 'spam', '89139991205, 89001234567', 4243 );

        $erased = false;
        $wp_on_set_transient = static function ( $key ) use ( $repository, &$erased ): void {
            if ( $erased || ! str_starts_with( (string) $key, ASW_Model_Store::TRANSIENT_PREFIX ) ) {
                return;
            }
            $erased = true;
            $repository->delete_by_comment_ids( array( 4243 ) );
            ASW_Model_Store::delete_units( ASW_Model_Store::unit_keys() );
        };

        try {
            $this->make_store( new ASW_Fixed_Training_Data() )->combined_classifier();
        } finally {
            $wp_on_set_transient = null;
        }

        $this->assertTrue( $erased, 'Premise: the erasure must land inside the write window' );
        $this->assertSame( array(), $this->cached_unit_keys() );
    }

    public function test_an_undisturbed_write_keeps_its_unit(): void {
        // The guard above must not cost the ordinary case its cache.
        $this->make_store( new ASW_Fixed_Training_Data() )->script_classifier( 'cyrillic' );

        $keys = $this->cached_unit_keys();
        $this->assertCount( 1, $keys );

        $warm = new ASW_Fixed_Training_Data();
        $this->make_store( $warm )->script_classifier( 'cyrillic' );
        $this->assertSame( 0, $warm->loads, 'The unit written by an undisturbed request must still be warm' );
    }

    public function test_unknown_script_samples_from_the_repository_reach_the_combined_unit(): void {
        global $wpdb;

        // The combined unit is the only place a sample trained from a comment
        // whose script could not be identified is ever used -- 'unknown' has no
        // unit of its own.
        $repo = new ASW_Sample_Repository( $wpdb );
        $repo->insert( 'unknown', 'spam', '89139991205, 89001234567, 89005553535', 7001 );
        $repo->insert( 'unknown', 'ham', '2019, 2020, 2021, 2022', 7002 );

        $store      = $this->make_store( new ASW_Recording_Training_Data() );
        $doc_counts = $store->combined_classifier()->get_state()['doc_counts'];

        $this->assertSame( 1, $doc_counts['spam'] );
        $this->assertSame( 1, $doc_counts['ham'] );
        $this->assertNotEmpty( $store->combined_sample_tokens(), 'An unknown-script spam row must join the combined similarity corpus' );
    }

    public function test_a_combined_fingerprint_that_moves_during_loading_writes_no_transient(): void {
        global $wpdb;

        // The same never-cache rule as the per-script case above. Duplicated in
        // combined_unit(), so it needs its own test: caching a combined unit
        // under the post-race key would hide the just-written sample from the
        // fallback model for a full DAY_IN_SECONDS.
        $racer                    = new ASW_Racing_Training_Data();
        $racer->racing_repository = new ASW_Sample_Repository( $wpdb );

        $classifier = $this->make_store( $racer )->combined_classifier();

        $this->assertInstanceOf( ASW_Naive_Bayes::class, $classifier );
        $this->assertSame(
            array(),
            $this->cached_unit_keys(),
            'A mid-load key change must not cache the combined unit built under the old key'
        );
    }

    // --- a failed sample read ---

    /**
     * Make the `SELECT content` read fail while every aggregate query succeeds.
     *
     * That split is the whole hazard: the fingerprint is computed from an
     * aggregate that still answers, so a unit built from a failed sample read
     * would be cached under a fingerprint asserting the rows are in it.
     */
    private function fail_only_the_sample_read(): void {
        global $wpdb;

        $wpdb->on_get_results = static function ( $query ) use ( $wpdb ) {
            $wpdb->get_results_result = str_contains( $query, 'SELECT content ' ) ? false : null;
        };
    }

    public function test_a_script_unit_built_while_the_sample_read_failed_is_not_cached(): void {
        global $wpdb;

        $repo = new ASW_Sample_Repository( $wpdb );
        $repo->insert( 'cyrillic', 'spam', 'продам гараж, пишите', 8101 );

        // Control: the same load with a healthy table caches the unit.
        $warm = new ASW_Model_Store(
            new ASW_Tokenizer( array() ),
            array(),
            new ASW_File_Free_Training_Data( $repo ),
            $repo
        );
        $this->assertNotEmpty( $warm->script_sample_tokens( 'cyrillic' ), 'The control load must see the stored sample' );
        $this->assertSame(
            array( ASW_Model_Store::TRANSIENT_PREFIX . 'cyrillic' ),
            $this->cached_unit_keys(),
            'A healthy load must cache the unit'
        );

        global $wp_transients, $wp_options;
        $wp_transients = array();
        $wp_options    = array();

        $this->fail_only_the_sample_read();

        $cold = new ASW_Model_Store(
            new ASW_Tokenizer( array() ),
            array(),
            new ASW_File_Free_Training_Data( $repo ),
            $repo
        );

        // Detection still runs -- the unit is used for this request.
        $this->assertSame( array(), $cold->script_sample_tokens( 'cyrillic' ) );
        $this->assertSame(
            array(),
            $this->cached_unit_keys(),
            'A unit built while the sample read failed must not be cached: its fingerprint says the rows are in it, so every user-added sample would drop out of the model for a day'
        );
    }

    public function test_a_combined_unit_built_while_the_sample_read_failed_is_not_cached(): void {
        global $wpdb;

        $repo = new ASW_Sample_Repository( $wpdb );
        $repo->insert( 'unknown', 'spam', '+7 913 999 12 05', 8102 );
        $repo->insert( 'unknown', 'ham', 'thanks for the detailed post', 8103 );

        $this->fail_only_the_sample_read();

        $store = new ASW_Model_Store(
            new ASW_Tokenizer( array() ),
            array(),
            new ASW_File_Free_Training_Data( $repo ),
            $repo
        );

        $this->assertInstanceOf( ASW_Naive_Bayes::class, $store->combined_classifier() );
        $this->assertSame(
            array(),
            $this->cached_unit_keys(),
            'The unknown-script rows are read inside build_combined_unit(), so the flag must be checked after it returns'
        );
    }

    // --- invalidation inputs ---

    public function test_touching_a_scripts_corpus_file_moves_only_that_scripts_fingerprint(): void {
        $before_cyrillic = $this->fingerprint_for_script( 'cyrillic' );
        $before_latin    = $this->fingerprint_for_script( 'latin' );

        $this->move_mtime( ASW_PATH . 'data/spam-samples-cyrillic.txt' );

        $after_cyrillic = $this->fingerprint_for_script( 'cyrillic' );
        $after_latin    = $this->fingerprint_for_script( 'latin' );

        $this->assertNotSame( $before_cyrillic, $after_cyrillic, 'A corpus edit must move that script\'s key' );
        $this->assertSame( $before_latin, $after_latin, 'Another script\'s unit must not be invalidated' );

        // Both categories are stamped, so a ham-only edit must move the key too.
        $this->move_mtime( ASW_PATH . 'data/ham-samples-cyrillic.txt' );

        $this->assertNotSame( $after_cyrillic, $this->fingerprint_for_script( 'cyrillic' ), 'A ham corpus edit must move the key as well' );
        $this->assertSame( $before_latin, $this->fingerprint_for_script( 'latin' ), 'Another script\'s unit must still not be invalidated' );
    }

    public function test_touching_a_legacy_corpus_file_moves_the_combined_fingerprint(): void {
        // The combined key stamps all 22 corpus files; the legacy pair is the
        // only input no other test moves.
        $before = $this->fingerprint_for_combined();

        $this->move_mtime( ASW_PATH . 'data/ham-samples.txt' );

        $this->assertNotSame( $before, $this->fingerprint_for_combined(), 'A legacy corpus edit must move the combined key' );
    }

    public function test_bumping_the_samples_revision_moves_only_the_combined_fingerprint(): void {
        $before_cyrillic = $this->fingerprint_for_script( 'cyrillic' );
        $before_combined = $this->fingerprint_for_combined();

        update_option( ASW_Sample_Repository::REVISION_OPTION, 7 );

        $this->assertSame(
            $before_cyrillic,
            $this->fingerprint_for_script( 'cyrillic' ),
            'A per-script unit is keyed on its own DB fingerprint, not the global revision'
        );
        $this->assertNotSame( $before_combined, $this->fingerprint_for_combined() );
    }

    public function test_a_row_that_left_without_a_revision_bump_moves_the_combined_fingerprint(): void {
        global $wpdb;

        // The combined unit's DB component may not rest on the revision option
        // alone: bump_revision() is a second write, fired on wp_options after
        // the DELETE it follows, and it cannot report a refusal. A privacy
        // erasure whose bump the database refused therefore removes rows while
        // the revision stands still -- and the post-write supersession check in
        // combined_unit() is what stops a request that loaded its samples before
        // that erasure from writing them back afterwards.
        ( new ASW_Sample_Repository( $wpdb ) )->insert( 'unknown', 'spam', 'продам гараж пишите', 4242 );

        $before   = $this->fingerprint_for_combined();
        $revision = get_option( ASW_Sample_Repository::REVISION_OPTION, 0 );

        // The erasure's DELETE landed; its revision bump did not.
        $this->assertSame(
            1,
            $wpdb->query( 'DELETE FROM `' . $wpdb->prefix . 'asw_samples` WHERE comment_id = 4242' ),
            'Premise: the row is gone'
        );

        $this->assertSame(
            $revision,
            get_option( ASW_Sample_Repository::REVISION_OPTION, 0 ),
            'Premise: the revision has not moved, so only the table can report the erasure'
        );
        $this->assertNotSame( $before, $this->fingerprint_for_combined() );
    }

    public function test_a_sample_written_for_a_script_moves_only_that_scripts_fingerprint(): void {
        global $wpdb;

        $before_cyrillic = $this->fingerprint_for_script( 'cyrillic' );
        $before_latin    = $this->fingerprint_for_script( 'latin' );

        ( new ASW_Sample_Repository( $wpdb ) )->insert( 'cyrillic', 'spam', 'продам гараж пишите', 4242 );

        $this->assertNotSame( $before_cyrillic, $this->fingerprint_for_script( 'cyrillic' ) );
        $this->assertSame( $before_latin, $this->fingerprint_for_script( 'latin' ) );
    }

    public function test_changing_the_excluded_tokens_moves_every_fingerprint(): void {
        $before_cyrillic = $this->fingerprint_for_script( 'cyrillic' );
        $before_combined = $this->fingerprint_for_combined();

        $after_cyrillic = $this->fingerprint_for_script( 'cyrillic', array( 'thanks' ) );
        $after_combined = $this->fingerprint_for_combined( array( 'thanks' ) );

        $this->assertNotSame( $before_cyrillic, $after_cyrillic );
        $this->assertNotSame( $before_combined, $after_combined );
    }

    /**
     * The fingerprint a fresh store stamps into a script's cached unit.
     *
     * The invalidation inputs used to be read off the transient key. They live
     * inside the value now -- the key names the unit and never moves, so that a
     * rebuild overwrites the unit it replaces rather than orphaning it out of
     * an eraser's reach -- so these assertions read the stamp instead.
     *
     * @param string             $script          Script group name.
     * @param array<int, string> $excluded_tokens Excluded tokens.
     */
    private function fingerprint_for_script( string $script, array $excluded_tokens = array() ): string {
        global $wp_transients, $wp_transient_reads;
        $wp_transient_reads = array();

        $this->make_store( new ASW_Recording_Training_Data(), $excluded_tokens )->script_classifier( $script );

        $key = ASW_Model_Store::TRANSIENT_PREFIX . $script;
        $this->assertSame( array( $key ), $this->reads(), 'The unit key must name the unit and nothing else' );

        return $this->stored_unit( $key )['fingerprint'];
    }

    /**
     * The fingerprint a fresh store stamps into the combined unit.
     *
     * @param array<int, string> $excluded_tokens Excluded tokens.
     */
    private function fingerprint_for_combined( array $excluded_tokens = array() ): string {
        global $wp_transients, $wp_transient_reads;
        $wp_transient_reads = array();

        $this->make_store( new ASW_Recording_Training_Data(), $excluded_tokens )->combined_classifier();

        $key = ASW_Model_Store::TRANSIENT_PREFIX . 'combined';
        $this->assertSame( array( $key ), $this->reads() );

        return $this->stored_unit( $key )['fingerprint'];
    }

    // --- the mid-load key race ---

    public function test_a_fingerprint_that_moves_during_loading_writes_no_transient(): void {
        global $wpdb;

        $racer                    = new ASW_Racing_Training_Data();
        $racer->racing_repository = new ASW_Sample_Repository( $wpdb );

        $classifier = $this->make_store( $racer )->script_classifier( 'cyrillic' );

        // The samples just loaded are used...
        $this->assertInstanceOf( ASW_Naive_Bayes::class, $classifier );
        $this->assertSame( 1, $classifier->get_state()['doc_counts']['spam'] );

        // ...but nothing is cached, because the key moved under us and the
        // samples in hand are stale relative to it.
        $this->assertSame(
            array(),
            $this->cached_unit_keys(),
            'A mid-load key change must not cache the samples loaded under the old key'
        );
    }

    // --- a failed fingerprint read ---

    public function test_a_null_fingerprint_falls_back_to_the_global_revision_and_still_caches(): void {
        global $wpdb;

        $wpdb->get_results_result = false;

        $this->assertNull(
            ( new ASW_Sample_Repository( $wpdb ) )->script_fingerprint( 'cyrillic' ),
            'Premise: the fingerprint query must be failing for this test to mean anything'
        );

        $this->assertNotSame( '', $this->fingerprint_for_script( 'cyrillic' ) );

        // A host whose samples table was never created fails that query on every
        // request, so refusing to cache would strand it on the cold path for good.
        $this->assertSame(
            array( ASW_Model_Store::TRANSIENT_PREFIX . 'cyrillic' ),
            $this->cached_unit_keys(),
            'A failed fingerprint read must not stop the unit being cached'
        );
    }

    /**
     * A store whose corpus files are stubbed out but whose sample merge goes
     * through the real repository, which is what a missing samples table has to
     * be driven through: ASW_Recording_Training_Data never calls find_all(), so
     * the sample-read failure flag is never set and the cache gate is never
     * exercised.
     */
    private function make_repository_reading_store( ASW_Sample_Repository $repo ): ASW_Model_Store {
        return new ASW_Model_Store(
            new ASW_Tokenizer( array() ),
            array(),
            new ASW_File_Free_Training_Data( $repo ),
            $repo
        );
    }

    public function test_a_host_whose_samples_table_is_missing_still_caches_its_units(): void {
        global $wpdb;

        // Every SELECT against the table errors: the refused-CREATE case the
        // schema-version retry exists for. Both the fingerprint read and the
        // sample read fail, which is the pair the two never-cache rules used to
        // disagree about -- script_db_component() falls back and keeps caching,
        // while the sample-read flag refused the write, so the site paid a full
        // cold rebuild on every submission for good.
        $wpdb->get_results_result = false;

        $repo = new ASW_Sample_Repository( $wpdb );

        $this->assertNull(
            $repo->script_fingerprint( 'cyrillic' ),
            'Premise: the fingerprint read must be failing'
        );

        $store = $this->make_repository_reading_store( $repo );
        $store->script_classifier( 'cyrillic' );
        $store->combined_classifier();

        $this->assertTrue(
            $repo->sample_read_failed(),
            'Premise: the sample read must be failing too, or this is the already-tested single-failure case'
        );

        $this->assertSame(
            array(
                ASW_Model_Store::TRANSIENT_PREFIX . 'cyrillic',
                ASW_Model_Store::TRANSIENT_PREFIX . 'combined',
            ),
            $this->cached_unit_keys(),
            'With the fingerprint read failing as well, the fingerprint asserts nothing about the rows, so withholding the write would only strand the site on the cold path'
        );
    }

    public function test_a_unit_cached_on_a_fallback_fingerprint_is_rebuilt_once_the_table_answers(): void {
        global $wpdb;

        $wpdb->get_results_result = false;

        $repo = new ASW_Sample_Repository( $wpdb );
        $this->make_repository_reading_store( $repo )->script_classifier( 'cyrillic' );

        $key = ASW_Model_Store::TRANSIENT_PREFIX . 'cyrillic';
        $this->assertSame( array( $key ), $this->cached_unit_keys(), 'Premise: the unit was cached on the fallback fingerprint' );

        // What makes caching on the fallback safe: the component it carries
        // ('dbrev:') is a different string from the one a successful read
        // produces ('db:'), so the first healthy request cannot match it.
        $wpdb->get_results_result = null;
        $repo->insert( 'cyrillic', 'spam', 'продам гараж, пишите', 8301 );

        $healthy = $this->make_repository_reading_store( new ASW_Sample_Repository( $wpdb ) );

        $this->assertNotEmpty(
            $healthy->script_sample_tokens( 'cyrillic' ),
            'A unit cached while the table was unreadable must not be served once it answers'
        );
    }

    public function test_a_revision_bump_moves_a_fingerprint_built_on_a_null_read(): void {
        global $wpdb;

        $wpdb->get_results_result = false;

        $before = $this->fingerprint_for_script( 'cyrillic' );

        update_option( ASW_Sample_Repository::REVISION_OPTION, 11 );

        $this->assertNotSame(
            $before,
            $this->fingerprint_for_script( 'cyrillic' ),
            'With no fingerprint the global revision is what keeps invalidation correct'
        );
    }

    public function test_a_store_without_a_repository_still_fingerprints_and_caches_units(): void {
        $store = $this->make_store( new ASW_Recording_Training_Data(), array(), false );

        $store->script_classifier( 'cyrillic' );

        $reads = $this->reads();
        $this->assertSame( array( ASW_Model_Store::TRANSIENT_PREFIX . 'cyrillic' ), $reads );
        $this->assertSame( array( $reads[0] ), $this->cached_unit_keys() );
    }

    // --- every key keeps the uninstall-visible prefix ---

    public function test_every_unit_key_carries_the_uninstall_prefix(): void {
        $store = $this->make_store();
        $store->script_classifier( 'cyrillic' );
        $store->combined_classifier();

        $this->assertSame( 'asw_classifiers_', ASW_Model_Store::TRANSIENT_PREFIX );
        foreach ( $this->reads() as $key ) {
            $this->assertStringStartsWith(
                'asw_classifiers_',
                $key,
                'uninstall.php removes the cache by LIKE on this prefix'
            );
        }
    }

    // --- the learned similarity corpus is bounded by a byte budget ---

    /**
     * A learned sample of a known packed size, built from vocabulary nothing
     * else in the suite or the shipped corpus can produce.
     *
     * Every token is `zq` plus a base-36 counter left-padded with `a`, so the
     * packed length is exactly $words * $length + ( $words - 1 ) and no two
     * samples share a token -- which is what lets a test name the newest and
     * the oldest sample by a token of their own.
     *
     * @param int $index  Sample index, unique per sample.
     * @param int $words  Tokens in the sample.
     * @param int $length Characters per token.
     */
    private function learned_sample( int $index, int $words = 100, int $length = 10 ): string {
        $tokens = array();

        for ( $word = 0; $word < $words; $word++ ) {
            $tokens[] = 'zq' . str_pad(
                base_convert( (string) ( $index * 100000 + $word ), 10, 36 ),
                $length - 2,
                'a',
                STR_PAD_LEFT
            );
        }

        return implode( ' ', $tokens );
    }

    /**
     * Packed bytes a similarity corpus occupies, the way the store measures it.
     *
     * @param array<int, array<int, string>> $samples Token lists.
     */
    private function packed_bytes( array $samples ): int {
        $bytes = 0;

        foreach ( $samples as $tokens ) {
            $bytes += strlen( implode( ' ', $tokens ) );
        }

        return $bytes;
    }

    /**
     * Budget a similarity corpus spends: its packed bytes plus the per-sample
     * array overhead admit_within_budget() charges for the element each list is
     * stored in.
     *
     * @param array<int, array<int, string>> $samples Token lists.
     */
    private function budget_spent( array $samples ): int {
        return $this->packed_bytes( $samples )
            + count( $samples ) * ASW_Model_Store::LEARNED_SAMPLE_OVERHEAD_BYTES;
    }

    /**
     * Store over a repository alone -- no corpus file, so every similarity list
     * in the unit that comes out is a learned one.
     *
     * @param ASW_Sample_Repository $repo Repository.
     */
    private function learned_only_store( ASW_Sample_Repository $repo ): ASW_Model_Store {
        return new ASW_Model_Store(
            new ASW_Tokenizer( array() ),
            array(),
            new ASW_File_Free_Training_Data( $repo ),
            $repo
        );
    }

    public function test_the_learned_similarity_corpus_never_exceeds_the_budget(): void {
        global $wpdb;

        $repo = new ASW_Sample_Repository( $wpdb );
        for ( $i = 0; $i < 150; $i++ ) {
            $this->assertNotSame( false, $repo->insert( 'latin', 'spam', $this->learned_sample( $i ), 9200 + $i ) );
        }

        $samples = $this->learned_only_store( $repo )->script_sample_tokens( 'latin' );

        // 150 samples of 1 099 packed bytes is 164 850 -- comfortably over the
        // budget, so the assertion below cannot pass because nothing bound.
        $this->assertNotEmpty( $samples, 'Premise: the learned samples reached the unit' );
        $this->assertLessThan( 150, count( $samples ), 'Premise: the budget actually bound this corpus' );
        $this->assertLessThanOrEqual(
            ASW_Model_Store::LEARNED_SIMILARITY_BUDGET,
            $this->packed_bytes( $samples ),
            'The learned half of a similarity corpus is what nothing else bounds'
        );
    }

    public function test_the_budget_keeps_the_newest_learned_spam_and_drops_the_oldest(): void {
        global $wpdb;

        $repo = new ASW_Sample_Repository( $wpdb );
        for ( $i = 0; $i < 150; $i++ ) {
            $repo->insert( 'latin', 'spam', $this->learned_sample( $i ), 9400 + $i );
        }

        $store   = $this->learned_only_store( $repo );
        $samples = $store->script_sample_tokens( 'latin' );

        $corpus  = ' ' . implode( ' ', array_map(
            static function ( array $tokens ): string {
                return implode( ' ', $tokens );
            },
            $samples
        ) ) . ' ';

        $newest = strtok( $this->learned_sample( 149 ), ' ' );
        $oldest = strtok( $this->learned_sample( 0 ), ' ' );

        $this->assertStringContainsString( " $newest ", $corpus, 'The budget is spent from the newest sample downwards' );
        $this->assertStringNotContainsString( " $oldest ", $corpus, 'The oldest learned spam is what falls out of the corpus' );

        // The other half of the deliberate verdict change: only the Similarity
        // check's corpus is capped. The classifier still learned every sample.
        $classifier = $store->script_classifier( 'latin' );
        $this->assertInstanceOf( ASW_Naive_Bayes::class, $classifier );
        $this->assertArrayHasKey(
            $oldest,
            $classifier->get_state()['token_counts']['spam'],
            'The Bayesian check must still carry the vocabulary of a sample the similarity corpus dropped'
        );
    }

    public function test_a_corpus_landing_exactly_on_the_budget_is_admitted_whole(): void {
        global $wpdb;

        // learned_sample( $i, $words, $length ) packs to $words * ( $length + 1 ) - 1
        // bytes and costs LEARNED_SAMPLE_OVERHEAD_BYTES more, so a corpus can be
        // composed to spend the budget exactly:
        // 117 * ( 1099 + 16 ) + ( 601 + 16 ) === LEARNED_SIMILARITY_BUDGET.
        $repo = new ASW_Sample_Repository( $wpdb );
        $repo->insert( 'latin', 'spam', $this->learned_sample( 700, 14, 42 ), 9700 );
        for ( $i = 0; $i < 117; $i++ ) {
            $repo->insert( 'latin', 'spam', $this->learned_sample( 701 + $i, 100, 10 ), 9701 + $i );
        }

        $samples = $this->learned_only_store( $repo )->script_sample_tokens( 'latin' );

        // The boundary is `>` and not `>=`: a corpus that lands exactly on the
        // budget is inside it. Nothing else in the suite distinguishes the two,
        // and the arithmetic the object-cache worst case is computed from
        // assumes the budget is spendable in full.
        $this->assertSame(
            ASW_Model_Store::LEARNED_SIMILARITY_BUDGET,
            $this->budget_spent( $samples ),
            'Premise: the fixture composes a corpus that spends exactly the budget'
        );
        $this->assertCount( 118, $samples, 'A corpus landing exactly on the budget is admitted whole' );
    }

    public function test_a_corpus_one_byte_over_the_budget_drops_its_oldest_sample(): void {
        global $wpdb;

        // The same corpus as above with one more byte in the *oldest* sample,
        // which is where the newest-first walk stops.
        $repo = new ASW_Sample_Repository( $wpdb );
        $repo->insert( 'latin', 'spam', $this->learned_sample( 700, 14, 42 ) . 'z', 9700 );
        for ( $i = 0; $i < 117; $i++ ) {
            $repo->insert( 'latin', 'spam', $this->learned_sample( 701 + $i, 100, 10 ), 9701 + $i );
        }

        $samples = $this->learned_only_store( $repo )->script_sample_tokens( 'latin' );

        $this->assertCount( 117, $samples, 'One byte past the budget stops the walk at the sample that overflows it' );
        $this->assertLessThan( ASW_Model_Store::LEARNED_SIMILARITY_BUDGET, $this->budget_spent( $samples ) );
    }

    public function test_a_max_token_sample_is_admitted_whole_and_older_samples_still_fit(): void {
        global $wpdb;

        $repo = new ASW_Sample_Repository( $wpdb );
        for ( $i = 0; $i < 40; $i++ ) {
            $repo->insert( 'latin', 'spam', $this->learned_sample( $i ), 9600 + $i );
        }
        // ASW_Tokenizer::MAX_TOKENS worth of ten-character tokens: 109 999
        // packed bytes, the largest list realistic text can produce.
        $repo->insert( 'latin', 'spam', $this->learned_sample( 900, ASW_Tokenizer::MAX_TOKENS ), 9700 );

        $samples = $this->learned_only_store( $repo )->script_sample_tokens( 'latin' );
        $bytes   = $this->packed_bytes( $samples );

        $this->assertSame(
            ASW_Tokenizer::MAX_TOKENS,
            count( end( $samples ) ),
            'A sample inside the budget is admitted whole, never trimmed to fit'
        );
        $this->assertLessThanOrEqual( ASW_Model_Store::LEARNED_SIMILARITY_BUDGET, $bytes );
        $this->assertGreaterThan(
            ASW_Tokenizer::MAX_TOKENS * 11 - 1,
            $bytes,
            'Premise: older samples were still admitted alongside it'
        );
    }

    public function test_a_sample_that_tokenises_to_nothing_is_passed_over_without_stopping_the_walk(): void {
        global $wpdb;

        $repo = new ASW_Sample_Repository( $wpdb );
        // A moderated body that is punctuation alone -- or one whose every token
        // the site has excluded -- reaches find_all() and tokenises to nothing.
        // It must cost no budget and, above all, must not stop the walk: a break
        // there would truncate every learned corpus at the first such sample,
        // and appending the empty list instead packs to '' -- which
        // restore_script_unit() rejects, so the unit would be deleted and
        // rebuilt on every submission in that script for good.
        $repo->insert( 'latin', 'spam', $this->learned_sample( 1 ), 10600 );
        $repo->insert( 'latin', 'spam', '... --- !!! ???', 10601 );
        $repo->insert( 'latin', 'spam', $this->learned_sample( 2 ), 10602 );

        $samples = $this->learned_only_store( $repo )->script_sample_tokens( 'latin' );

        $this->assertCount( 2, $samples, 'The empty sample is passed over, and the walk reaches past it' );
        foreach ( $samples as $tokens ) {
            $this->assertNotSame( array(), $tokens, 'An empty token list must never enter a similarity corpus' );
        }

        $flat = array_merge( ...$samples );
        foreach ( array( 1, 2 ) as $index ) {
            $this->assertContains(
                explode( ' ', $this->learned_sample( $index ) )[0],
                $flat,
                "The sample on either side of the empty one is still admitted ($index)"
            );
        }
    }

    public function test_a_sample_over_the_budget_is_skipped_and_nothing_older_follows_it(): void {
        global $wpdb;

        $repo = new ASW_Sample_Repository( $wpdb );
        for ( $i = 0; $i < 10; $i++ ) {
            $repo->insert( 'latin', 'spam', $this->learned_sample( $i ), 9800 + $i );
        }
        // 10 000 twenty-character tokens: 209 999 packed bytes, past the budget
        // on its own. The walk stops there and admits nothing older.
        $repo->insert( 'latin', 'spam', $this->learned_sample( 901, ASW_Tokenizer::MAX_TOKENS, 20 ), 9900 );

        $this->assertSame(
            array(),
            $this->learned_only_store( $repo )->script_sample_tokens( 'latin' ),
            'A single sample is admitted whole or not at all, and the walk stops at the first that does not fit'
        );
    }

    public function test_the_combined_units_learned_rows_take_the_budget_and_its_legacy_lists_do_not(): void {
        global $wpdb;

        $repo = new ASW_Sample_Repository( $wpdb );
        for ( $i = 0; $i < 150; $i++ ) {
            $repo->insert( 'unknown', 'spam', $this->learned_sample( $i ), 10000 + $i );
        }

        $loader = new ASW_Legacy_And_Script_Training_Data( $repo );
        $store  = new ASW_Model_Store( new ASW_Tokenizer( array() ), array(), $loader, $repo );

        $samples = $store->combined_sample_tokens();
        $legacy  = $this->tokenizer_lists( array( ASW_Legacy_And_Script_Training_Data::LEGACY_SPAM ) );

        $this->assertSame( $legacy, array_slice( $samples, 0, count( $legacy ) ), 'The shipped legacy lists are never capped' );

        $learned = array_slice( $samples, count( $legacy ) );
        $this->assertNotEmpty( $learned, 'Premise: the unknown-script rows reached the combined unit' );
        $this->assertLessThan( 150, count( $learned ), 'Premise: the budget actually bound them' );
        $this->assertLessThanOrEqual(
            ASW_Model_Store::LEARNED_SIMILARITY_BUDGET,
            $this->packed_bytes( $learned ),
            'The combined unit spends the same budget on the rows this site learned'
        );
    }

    /**
     * Tokenise sample strings the way a unit's similarity corpus does.
     *
     * @param array<int, string> $samples Sample strings.
     * @return array<int, array<int, string>>
     */
    private function tokenizer_lists( array $samples ): array {
        $tokenizer = new ASW_Tokenizer( array() );
        $lists     = array();

        foreach ( $samples as $sample ) {
            $tokens = $tokenizer->tokenize_for_script( $sample, ASW_Script_Detector::detect( $sample ) );
            if ( ! empty( $tokens ) ) {
                $lists[] = $tokens;
            }
        }

        return $lists;
    }

    public function test_learned_ham_never_enters_a_similarity_corpus(): void {
        global $wpdb;

        $repo = new ASW_Sample_Repository( $wpdb );
        $repo->insert( 'latin', 'spam', $this->learned_sample( 1, 5 ), 10500 );
        $repo->insert( 'latin', 'ham', $this->learned_sample( 2, 5 ), 10501 );
        $repo->insert( 'unknown', 'spam', $this->learned_sample( 3, 5 ), 10502 );
        $repo->insert( 'unknown', 'ham', $this->learned_sample( 4, 5 ), 10503 );

        $store = $this->learned_only_store( $repo );

        $ham_script   = strtok( $this->learned_sample( 2, 5 ), ' ' );
        $ham_combined = strtok( $this->learned_sample( 4, 5 ), ' ' );

        $script_corpus   = $store->script_sample_tokens( 'latin' );
        $combined_corpus = $store->combined_sample_tokens();

        $this->assertNotEmpty( $script_corpus, 'Premise: the spam sample reached the unit' );
        $this->assertNotEmpty( $combined_corpus, 'Premise: the unknown-script spam row reached the combined unit' );

        foreach ( array_merge( $script_corpus, $combined_corpus ) as $tokens ) {
            $this->assertNotContains( $ham_script, $tokens, 'A similarity corpus is built from spam alone' );
            $this->assertNotContains( $ham_combined, $tokens, 'A similarity corpus is built from spam alone' );
        }

        // The other half of "never enters a similarity corpus": it must still
        // enter the classifier. script_unit() loads the spam half split so the
        // budget can be spent on the learned tail alone, while ham keeps going
        // through the merged loader -- so nothing but this asserts that a
        // learned ham row reaches a named script's Bayesian model at all.
        $script_state = $store->script_classifier( 'latin' )->get_state();
        $this->assertSame( 1, $script_state['doc_counts']['ham'], 'The learned ham row is a training document' );
        $this->assertArrayHasKey(
            $ham_script,
            $script_state['token_counts']['ham'],
            'A learned ham sample must reach its script classifier even though no similarity corpus carries it'
        );

        $combined_state = $store->combined_classifier()->get_state();
        $this->assertArrayHasKey(
            $ham_combined,
            $combined_state['token_counts']['ham'],
            'The combined classifier trains on the unknown-script ham rows too'
        );
        $this->assertArrayHasKey(
            $ham_script,
            $combined_state['token_counts']['ham'],
            'The combined classifier trains on every script\'s ham samples'
        );
    }

    public function test_a_sample_the_budget_skips_still_invalidates_the_cached_unit(): void {
        global $wpdb;

        $repo = new ASW_Sample_Repository( $wpdb );
        $repo->insert( 'latin', 'spam', $this->learned_sample( 5, 20 ), 10600 );

        $warm = $this->learned_only_store( $repo );
        $this->assertCount( 1, $warm->script_sample_tokens( 'latin' ) );
        $this->assertSame( array( ASW_Model_Store::TRANSIENT_PREFIX . 'latin' ), $this->cached_unit_keys() );

        // A sample past the budget: the similarity corpus cannot carry it and
        // stops the walk before the older one, but the classifier learns it, so
        // the fingerprint must move and the cached unit must not be served.
        $oversized = $this->learned_sample( 902, ASW_Tokenizer::MAX_TOKENS, 20 );
        $repo->insert( 'latin', 'spam', $oversized, 10601 );

        $cold       = $this->learned_only_store( $repo );
        $samples    = $cold->script_sample_tokens( 'latin' );
        $classifier = $cold->script_classifier( 'latin' );

        $this->assertSame( array(), $samples, 'The oversized sample stops the walk and nothing older follows it' );
        $this->assertInstanceOf( ASW_Naive_Bayes::class, $classifier );
        $this->assertArrayHasKey(
            strtok( $oversized, ' ' ),
            $classifier->get_state()['token_counts']['spam'],
            'A sample the similarity corpus cannot carry must still move the fingerprint, because the classifier depends on it'
        );
    }
}
