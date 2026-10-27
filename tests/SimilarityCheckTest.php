<?php

use PHPUnit\Framework\TestCase;

/**
 * Corpus loader with spam samples for Cyrillic only, so a store built over it
 * has one script with a sample list and nine without -- the two sides of
 * ANTISPAMWIZARD_Similarity_Check's per-script/combined branch.
 */
class ANTISPAMWIZARD_Similarity_Store_Corpus extends ANTISPAMWIZARD_Training_Data {

    public function load_samples( string $file_path ): array {
        return array();
    }

    public function load_script_file_samples( string $script, string $category ): array {
        if ( 'cyrillic' !== $script ) {
            return array();
        }

        return 'spam' === $category
            ? array(
                'Купить дешевые таблетки онлайн скидка аптека доставка бесплатно',
                'Заработок в интернете без вложений пишите в личные сообщения',
            )
            : array( 'Отличная статья о программировании спасибо автору за труд' );
    }
}

/**
 * Corpus loader with a **legacy** spam corpus alongside the Cyrillic per-script
 * one, which is what the combined unit's similarity corpus is actually built
 * from now that the ten per-script lists are no longer stored in it.
 *
 * ANTISPAMWIZARD_Similarity_Store_Corpus has no legacy corpus, so a store built over it
 * caches an empty combined corpus -- against which "not spam" is true whatever
 * the corpus holds, and the deviation this branch took has nothing pinning it
 * at the check level.
 */
class ANTISPAMWIZARD_Legacy_Similarity_Store_Corpus extends ANTISPAMWIZARD_Similarity_Store_Corpus {

    public function load_samples( string $file_path ): array {
        return array(
            'Buy cheap viagra online free shipping discount today',
            'Click here for amazing casino bonus free money now',
        );
    }
}

/**
 * Corpus loader with nothing at all, which is the degenerate case that keeps
 * ANTISPAMWIZARD_Similarity_Check in its legacy word-tokenisation mode.
 */
class ANTISPAMWIZARD_Empty_Similarity_Store_Corpus extends ANTISPAMWIZARD_Training_Data {

    public function load_samples( string $file_path ): array {
        return array();
    }

    public function load_script_file_samples( string $script, string $category ): array {
        return array();
    }
}

/**
 * Records which tokenisation strategy the check chose for the comment body.
 */
class ANTISPAMWIZARD_Similarity_Recording_Tokenizer extends ANTISPAMWIZARD_Tokenizer {

    /** @var string[] */
    public array $calls = array();

    public function tokenize( string $text ): array {
        $this->calls[] = 'tokenize';
        return parent::tokenize( $text );
    }

    public function tokenize_for_script( string $text, string $script ): array {
        $this->calls[] = 'tokenize_for_script:' . $script;
        return parent::tokenize_for_script( $text, $script );
    }
}

class SimilarityCheckTest extends TestCase {

    private ANTISPAMWIZARD_Tokenizer $tokenizer;
    private ANTISPAMWIZARD_Cosine_Similarity $cosine;
    private ANTISPAMWIZARD_Similarity_Check $check;

    /** @var array<int, string> */
    private array $spam_samples;

    protected function setUp(): void {
        parent::setUp();
        global $wp_options, $wp_transients, $wp_transient_reads;
        $wp_options         = array();
        $wp_transients      = array();
        $wp_transient_reads = array();

        ANTISPAMWIZARD_Settings::update( array(
            'similarity_enabled'   => true,
            'similarity_threshold' => 0.6,
        ) );

        $this->tokenizer = new ANTISPAMWIZARD_Tokenizer();
        $this->cosine    = new ANTISPAMWIZARD_Cosine_Similarity();

        $this->spam_samples = array(
            'Buy cheap viagra online free shipping discount',
            'Click here for amazing casino bonus free money',
            'Win lottery millions guaranteed free prize winner',
            'Cheap pills online pharmacy discount offer',
            'Make money fast guaranteed income work from home',
        );

        $this->check = new ANTISPAMWIZARD_Similarity_Check(
            $this->tokenizer,
            $this->cosine,
            $this->spam_samples
        );
    }

    protected function tearDown(): void {
        global $wp_options, $wp_transients, $wp_transient_reads;
        $wp_options         = array();
        $wp_transients      = array();
        $wp_transient_reads = array();
        parent::tearDown();
    }

    private function make_comment( string $content ): array {
        return array( 'comment_content' => $content );
    }

    public function test_implements_check_interface(): void {
        $this->assertInstanceOf( ANTISPAMWIZARD_Check::class, $this->check );
    }

    public function test_spam_detected_above_threshold(): void {
        $result = $this->check->check( $this->make_comment(
            'Buy cheap viagra online free shipping discount pharmacy'
        ) );
        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'similarity', $result->get_check_name() );
        $this->assertStringContainsString( 'similarity', $result->get_details() );
    }

    public function test_not_spam_below_threshold(): void {
        $result = $this->check->check( $this->make_comment(
            'This is a great article about software architecture and design patterns'
        ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'similarity', $result->get_check_name() );
    }

    public function test_disabled_check_returns_not_spam(): void {
        ANTISPAMWIZARD_Settings::update( array( 'similarity_enabled' => false ) );
        $result = $this->check->check( $this->make_comment(
            'Buy cheap viagra online free shipping discount'
        ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'check disabled', $result->get_details() );
    }

    public function test_threshold_boundary_high(): void {
        // With threshold at 1.0, only perfect similarity matches get flagged.
        ANTISPAMWIZARD_Settings::update( array( 'similarity_threshold' => 1.0 ) );
        $result = $this->check->check( $this->make_comment(
            'I enjoyed reading this article about online shopping trends'
        ) );
        $this->assertFalse( $result->is_spam(), 'Should not flag at threshold 1.0 when similarity is below that' );
    }

    public function test_threshold_boundary_low(): void {
        // With threshold at 0.0, any non-zero similarity should be flagged.
        ANTISPAMWIZARD_Settings::update( array( 'similarity_threshold' => 0.0, 'min_message_length' => 1 ) );
        $result = $this->check->check( $this->make_comment(
            'cheap online free'
        ) );
        $this->assertTrue( $result->is_spam(), 'Should flag at threshold 0.0 when any similarity exists' );
    }

    public function test_empty_content_returns_not_spam(): void {
        $result = $this->check->check( $this->make_comment( '' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'empty comment', $result->get_details() );
    }

    public function test_missing_content_returns_not_spam(): void {
        $result = $this->check->check( array() );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'empty comment', $result->get_details() );
    }

    public function test_empty_spam_samples_returns_not_spam(): void {
        ANTISPAMWIZARD_Settings::update( array(
            'similarity_enabled' => true,
            'min_message_length' => 1,
        ) );
        $check  = new ANTISPAMWIZARD_Similarity_Check(
            $this->tokenizer,
            $this->cosine,
            array()
        );
        $result = $check->check( $this->make_comment(
            'Buy cheap viagra online free shipping discount'
        ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertStringContainsString( 'similarity', $result->get_details() );
        $this->assertStringNotContainsString( 'too short', $result->get_details() );
    }

    public function test_short_comment_below_min_length_returns_not_spam(): void {
        ANTISPAMWIZARD_Settings::update( array(
            'similarity_enabled'   => true,
            'similarity_threshold' => 0.6,
            'min_message_length'   => 50,
        ) );
        // 49 chars — below threshold.
        $result = $this->check->check( $this->make_comment( str_repeat( 'a', 49 ) ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'similarity', $result->get_check_name() );
        $this->assertSame( 'comment too short for similarity', $result->get_details() );
    }

    public function test_comment_at_threshold_length_proceeds_normally(): void {
        ANTISPAMWIZARD_Settings::update( array(
            'similarity_enabled'   => true,
            'similarity_threshold' => 0.6,
            'min_message_length'   => 50,
        ) );
        // Exactly 50 chars — should proceed to similarity comparison.
        $result = $this->check->check( $this->make_comment( str_repeat( 'a', 50 ) ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'similarity', $result->get_check_name() );
        $this->assertStringContainsString( 'similarity', $result->get_details() );
        $this->assertStringNotContainsString( 'too short', $result->get_details() );
    }

    public function test_details_include_score_and_threshold(): void {
        $result = $this->check->check( $this->make_comment(
            'Buy cheap viagra online free shipping discount pharmacy'
        ) );
        // Details should match format: "similarity X.XX/Y.YY"
        $this->assertMatchesRegularExpression(
            '/^similarity \d+\.\d{2}\/\d+\.\d{2}$/',
            $result->get_details()
        );
    }

    // --- Script-aware routing tests ---

    public function test_script_routing_uses_latin_samples(): void {
        ANTISPAMWIZARD_Settings::update( array(
            'similarity_enabled'   => true,
            'similarity_threshold' => 0.6,
            'min_message_length'   => 1,
        ) );

        $tokenizer = new ANTISPAMWIZARD_Tokenizer();

        // Pre-tokenize Latin spam samples.
        $latin_tokens = array(
            $tokenizer->tokenize( 'Buy cheap viagra online free shipping discount' ),
            $tokenizer->tokenize( 'Click here for amazing casino bonus free money' ),
        );

        // Empty combined — if routing works, it should use Latin samples.
        $check = new ANTISPAMWIZARD_Similarity_Check(
            $tokenizer,
            new ANTISPAMWIZARD_Cosine_Similarity(),
            array(), // no legacy samples
            array( 'latin' => $latin_tokens ),
            array()  // empty combined
        );

        $result = $check->check( $this->make_comment(
            'Buy cheap viagra online free shipping discount pharmacy'
        ) );
        $this->assertTrue( $result->is_spam() );
    }

    public function test_script_routing_falls_back_to_combined_for_unknown(): void {
        ANTISPAMWIZARD_Settings::update( array(
            'similarity_enabled'   => true,
            'similarity_threshold' => 0.0,
            'min_message_length'   => 1,
        ) );

        $tokenizer = new ANTISPAMWIZARD_Tokenizer();

        // Combined samples contain the matching text.
        $combined_tokens = array(
            $tokenizer->tokenize( 'discount pills cheap buy free shipping online fast' ),
        );

        $check = new ANTISPAMWIZARD_Similarity_Check(
            $tokenizer,
            new ANTISPAMWIZARD_Cosine_Similarity(),
            array(),
            array( 'latin' => array() ), // script_samples exist but no match for unknown
            $combined_tokens
        );

        // Numbers-only text — no letter characters, script = 'unknown'.
        // Zero tokens produced so similarity is 0.00, but verify the check ran
        // (not short-circuited) and returned a similarity score.
        $result = $check->check( $this->make_comment(
            '12345 67890 99999 88888 77777 66666 55555 44444 33333 22222'
        ) );
        $this->assertStringContainsString( 'similarity 0.00', $result->get_details() );
        $this->assertFalse( $result->is_spam() );
    }

    public function test_script_routing_falls_back_when_no_script_samples(): void {
        ANTISPAMWIZARD_Settings::update( array(
            'similarity_enabled'   => true,
            'similarity_threshold' => 0.6,
            'min_message_length'   => 1,
        ) );

        $tokenizer = new ANTISPAMWIZARD_Tokenizer();

        // Only Latin samples exist, Cyrillic comment should fall back to combined.
        $latin_tokens = array(
            $tokenizer->tokenize( 'Buy cheap viagra online free shipping discount' ),
        );
        $combined_tokens = array(
            $tokenizer->tokenize( 'Купить дешевые таблетки онлайн скидка доставка бесплатно' ),
        );

        $check = new ANTISPAMWIZARD_Similarity_Check(
            $tokenizer,
            new ANTISPAMWIZARD_Cosine_Similarity(),
            array(),
            array( 'latin' => $latin_tokens ),
            $combined_tokens
        );

        $result = $check->check( $this->make_comment(
            'Купить дешевые таблетки онлайн скидка доставка бесплатно быстро'
        ) );
        $this->assertTrue( $result->is_spam() );
    }

    public function test_script_routing_uses_ngram_tokenization_for_cjk(): void {
        ANTISPAMWIZARD_Settings::update( array(
            'similarity_enabled'   => true,
            'similarity_threshold' => 0.3,
            'min_message_length'   => 1,
        ) );

        $tokenizer = new ANTISPAMWIZARD_Tokenizer();

        // Pre-tokenize CJK samples with n-grams.
        $cjk_tokens = array(
            $tokenizer->tokenize_ngrams( '今すぐ無料でお金を稼ぐ方法をクリックしてください' ),
        );

        $check = new ANTISPAMWIZARD_Similarity_Check(
            $tokenizer,
            new ANTISPAMWIZARD_Cosine_Similarity(),
            array(),
            array( 'cjk' => $cjk_tokens ),
            array()
        );

        // Similar CJK text — should use n-gram tokenization and find similarity.
        $result = $check->check( $this->make_comment(
            '今すぐ無料でお金を稼ぐ方法をクリックしてください今すぐ'
        ) );
        $this->assertTrue( $result->is_spam() );
    }

    public function test_legacy_constructor_still_works(): void {
        // Original 3-arg constructor should still work.
        $check = new ANTISPAMWIZARD_Similarity_Check(
            $this->tokenizer,
            $this->cosine,
            $this->spam_samples
        );
        $result = $check->check( $this->make_comment(
            'Buy cheap viagra online free shipping discount pharmacy'
        ) );
        $this->assertTrue( $result->is_spam() );
    }

    // --- CJK/Thai short comment min-length tests ---

    public function test_short_cjk_comment_passes_min_length_check(): void {
        ANTISPAMWIZARD_Settings::update( array(
            'similarity_enabled'   => true,
            'similarity_threshold' => 0.3,
            'min_message_length'   => 50,
        ) );

        $tokenizer = new ANTISPAMWIZARD_Tokenizer();

        $cjk_tokens = array(
            $tokenizer->tokenize_ngrams( '今すぐ無料でお金を稼ぐ方法をクリックしてください' ),
        );

        $check = new ANTISPAMWIZARD_Similarity_Check(
            $tokenizer,
            new ANTISPAMWIZARD_Cosine_Similarity(),
            array(),
            array( 'cjk' => $cjk_tokens ),
            array()
        );

        // 20-char CJK comment — above dense script threshold (10), below default (50).
        // Should reach the classifier, not be skipped as too short.
        $result = $check->check( $this->make_comment(
            '今すぐ無料でお金を稼ぐ方法をクリックしてくだ'
        ) );
        $this->assertSame( 'similarity', $result->get_check_name() );
        $this->assertStringNotContainsString( 'too short', $result->get_details() );
        $this->assertStringContainsString( 'similarity', $result->get_details() );
    }

    public function test_short_thai_comment_passes_min_length_check(): void {
        ANTISPAMWIZARD_Settings::update( array(
            'similarity_enabled'   => true,
            'similarity_threshold' => 0.3,
            'min_message_length'   => 50,
        ) );

        $tokenizer = new ANTISPAMWIZARD_Tokenizer();

        $thai_tokens = array(
            $tokenizer->tokenize_ngrams( 'สวัสดีครับวันนี้อากาศดีมากเลยนะ' ),
        );

        $check = new ANTISPAMWIZARD_Similarity_Check(
            $tokenizer,
            new ANTISPAMWIZARD_Cosine_Similarity(),
            array(),
            array( 'thai' => $thai_tokens ),
            array()
        );

        // Thai comment — above dense script threshold (10), below default (50).
        $result = $check->check( $this->make_comment(
            'สวัสดีครับวันนี้อากาศดีมาก'
        ) );
        $this->assertSame( 'similarity', $result->get_check_name() );
        $this->assertStringNotContainsString( 'too short', $result->get_details() );
        $this->assertStringContainsString( 'similarity', $result->get_details() );
    }

    public function test_short_latin_comment_still_skipped_with_script_routing(): void {
        ANTISPAMWIZARD_Settings::update( array(
            'similarity_enabled'   => true,
            'similarity_threshold' => 0.6,
            'min_message_length'   => 50,
        ) );

        $tokenizer    = new ANTISPAMWIZARD_Tokenizer();
        $latin_tokens = array(
            $tokenizer->tokenize( 'Buy cheap viagra online free shipping discount' ),
        );

        $check = new ANTISPAMWIZARD_Similarity_Check(
            $tokenizer,
            new ANTISPAMWIZARD_Cosine_Similarity(),
            array(),
            array( 'latin' => $latin_tokens ),
            array()
        );

        // 20-char Latin comment — below default threshold (50), no dense script exception.
        $result = $check->check( $this->make_comment( str_repeat( 'a', 20 ) ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'comment too short for similarity', $result->get_details() );
    }

    public function test_very_short_cjk_comment_still_skipped(): void {
        ANTISPAMWIZARD_Settings::update( array(
            'similarity_enabled'   => true,
            'similarity_threshold' => 0.3,
            'min_message_length'   => 50,
        ) );

        $tokenizer = new ANTISPAMWIZARD_Tokenizer();

        $cjk_tokens = array(
            $tokenizer->tokenize_ngrams( '今すぐ無料でお金を稼ぐ方法をクリックしてください' ),
        );

        $check = new ANTISPAMWIZARD_Similarity_Check(
            $tokenizer,
            new ANTISPAMWIZARD_Cosine_Similarity(),
            array(),
            array( 'cjk' => $cjk_tokens ),
            array()
        );

        // 5-char CJK comment — below even the dense script threshold (10).
        $result = $check->check( $this->make_comment( '今すぐ無料' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'comment too short for similarity', $result->get_details() );
    }

    // --- ANTISPAMWIZARD_Model_Store construction form ---

    /**
     * Build a store-backed check over the Cyrillic-only fixture corpus.
     */
    private function make_store_check(): ANTISPAMWIZARD_Similarity_Check {
        ANTISPAMWIZARD_Settings::update( array(
            'similarity_enabled'   => true,
            'similarity_threshold' => 0.6,
            'min_message_length'   => 20,
        ) );

        $store = new ANTISPAMWIZARD_Model_Store(
            new ANTISPAMWIZARD_Tokenizer( array() ),
            array(),
            new ANTISPAMWIZARD_Similarity_Store_Corpus(),
            null
        );

        return new ANTISPAMWIZARD_Similarity_Check(
            new ANTISPAMWIZARD_Tokenizer( array() ),
            new ANTISPAMWIZARD_Cosine_Similarity(),
            $store
        );
    }

    /**
     * Transient keys read so far, in call order.
     *
     * @return string[]
     */
    private function transient_reads(): array {
        global $wp_transient_reads;
        return $wp_transient_reads;
    }

    public function test_store_backed_check_loads_only_the_detected_scripts_unit(): void {
        $check = $this->make_store_check();

        $result = $check->check( $this->make_comment(
            'Купить дешевые таблетки онлайн скидка аптека доставка бесплатно'
        ) );

        $this->assertTrue( $result->is_spam() );

        $reads = $this->transient_reads();
        $this->assertCount( 1, $reads, 'A script with its own samples must not load the combined unit' );
        $this->assertSame( ANTISPAMWIZARD_Model_Store::TRANSIENT_PREFIX . 'cyrillic', $reads[0] );
    }

    public function test_store_backed_check_falls_back_to_the_combined_unit_for_a_script_without_samples(): void {
        // This is the named-script half of the narrowed combined corpus'
        // accepted verdict deviation: the fallback is not reserved for bodies
        // ANTISPAMWIZARD_Script_Detector::detect() calls 'unknown'. Any of the ten named
        // scripts reaches the combined unit once its own list comes out empty
        // -- as Latin's does on this fixture, which is what a missing,
        // unreadable or emptied corpus file looks like at this seam.
        $check = $this->make_store_check();

        $result = $check->check( $this->make_comment(
            'buy cheap pills online discount pharmacy shipping today'
        ) );

        $this->assertFalse( $result->is_spam() );

        $reads = $this->transient_reads();
        $this->assertCount( 2, $reads );
        $this->assertSame( ANTISPAMWIZARD_Model_Store::TRANSIENT_PREFIX . 'latin', $reads[0] );
        $this->assertSame( ANTISPAMWIZARD_Model_Store::TRANSIENT_PREFIX . 'combined', $reads[1] );
    }

    public function test_store_backed_check_tokenises_the_comment_for_its_script_when_any_script_has_samples(): void {
        ANTISPAMWIZARD_Settings::update( array(
            'similarity_enabled'   => true,
            'similarity_threshold' => 0.6,
            'min_message_length'   => 20,
        ) );

        $tokenizer = new ANTISPAMWIZARD_Similarity_Recording_Tokenizer();
        $check     = new ANTISPAMWIZARD_Similarity_Check(
            $tokenizer,
            new ANTISPAMWIZARD_Cosine_Similarity(),
            new ANTISPAMWIZARD_Model_Store(
                new ANTISPAMWIZARD_Tokenizer( array() ),
                array(),
                new ANTISPAMWIZARD_Similarity_Store_Corpus(),
                null
            )
        );

        $check->check( $this->make_comment(
            'buy cheap pills online discount pharmacy shipping today'
        ) );

        // Cyrillic has samples, so the combined unit reports has_script_samples
        // and the Latin body is tokenised for its own script, not legacy-mode.
        // Only the entry point is asserted: tokenize_for_script() delegates to
        // tokenize() for every word-based script, so it records both.
        $this->assertSame( 'tokenize_for_script:latin', $tokenizer->calls[0] );
    }

    public function test_store_backed_check_falls_back_to_legacy_tokenisation_when_no_script_has_samples(): void {
        ANTISPAMWIZARD_Settings::update( array(
            'similarity_enabled'   => true,
            'similarity_threshold' => 0.6,
            'min_message_length'   => 20,
        ) );

        $tokenizer = new ANTISPAMWIZARD_Similarity_Recording_Tokenizer();
        $check     = new ANTISPAMWIZARD_Similarity_Check(
            $tokenizer,
            new ANTISPAMWIZARD_Cosine_Similarity(),
            new ANTISPAMWIZARD_Model_Store(
                new ANTISPAMWIZARD_Tokenizer( array() ),
                array(),
                new ANTISPAMWIZARD_Empty_Similarity_Store_Corpus(),
                null
            )
        );

        $check->check( $this->make_comment(
            'buy cheap pills online discount pharmacy shipping today'
        ) );

        // The degenerate empty-corpus case: no script produced a token list, so
        // the check stays in the legacy word tokenisation build_checks() used --
        // tokenize() is entered directly, never through tokenize_for_script().
        $this->assertSame( array( 'tokenize' ), $tokenizer->calls );
    }

    public function test_store_backed_check_goes_straight_to_the_combined_unit_for_an_unknown_script(): void {
        $check = $this->make_store_check();

        // No letter characters at all: 'unknown' has no per-script unit.
        $result = $check->check( $this->make_comment(
            '1234, 5678, 9012, 3456, 7890, 2345, 6789, 0123, 4567'
        ) );

        $this->assertFalse( $result->is_spam() );

        $reads = $this->transient_reads();
        $this->assertCount( 1, $reads );
        $this->assertSame( ANTISPAMWIZARD_Model_Store::TRANSIENT_PREFIX . 'combined', $reads[0] );
    }

    public function test_the_combined_unit_scores_a_fallback_body_against_the_legacy_corpus(): void {
        // The narrowed combined corpus is legacy samples plus the unknown-script
        // DB rows. A Latin body -- a *named* script with no per-script list on
        // this fixture, so it falls through to the combined unit exactly as a
        // script whose corpus file went missing would -- must still be scored
        // against those legacy samples and flagged. Without a positive case the
        // two fallback tests above assert "not spam" against a corpus that is
        // empty on their fixture, which holds just as well if the combined unit
        // stopped carrying a similarity corpus at all.
        ANTISPAMWIZARD_Settings::update( array(
            'similarity_enabled'   => true,
            'similarity_threshold' => 0.6,
            'min_message_length'   => 20,
        ) );

        $check = new ANTISPAMWIZARD_Similarity_Check(
            new ANTISPAMWIZARD_Tokenizer( array() ),
            new ANTISPAMWIZARD_Cosine_Similarity(),
            new ANTISPAMWIZARD_Model_Store(
                new ANTISPAMWIZARD_Tokenizer( array() ),
                array(),
                new ANTISPAMWIZARD_Legacy_Similarity_Store_Corpus(),
                null
            )
        );

        $result = $check->check( $this->make_comment(
            'Buy cheap viagra online free shipping discount today'
        ) );

        $this->assertTrue( $result->is_spam(), 'The legacy corpus must still score a fallback body' );
        $this->assertStringContainsString( 'similarity', $result->get_details() );

        $reads = $this->transient_reads();
        $this->assertSame( ANTISPAMWIZARD_Model_Store::TRANSIENT_PREFIX . 'combined', $reads[1] );
    }

    public function test_store_backed_check_loads_nothing_for_a_body_below_the_minimum_length(): void {
        $check = $this->make_store_check();

        $result = $check->check( $this->make_comment( 'Спасибо' ) );

        $this->assertSame( 'comment too short for similarity', $result->get_details() );
        $this->assertSame( array(), $this->transient_reads(), 'The length gate must return before any unit is loaded' );
    }

    public function test_a_disabled_store_backed_check_loads_nothing(): void {
        $check = $this->make_store_check();
        ANTISPAMWIZARD_Settings::update( array( 'similarity_enabled' => false ) );

        $result = $check->check( $this->make_comment(
            'Купить дешевые таблетки онлайн скидка аптека доставка бесплатно'
        ) );

        $this->assertSame( 'check disabled', $result->get_details() );
        $this->assertSame( array(), $this->transient_reads() );
    }
}
