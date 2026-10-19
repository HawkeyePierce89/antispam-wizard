<?php

use PHPUnit\Framework\TestCase;

/**
 * Corpus loader with one well-trained script (Cyrillic), one under-trained
 * script (Latin) and nothing else, so a store built over it resolves each of
 * ASW_Bayesian_Check's branches from a different writing system.
 */
class ASW_Bayesian_Store_Corpus extends ASW_Training_Data {

    public function load_samples( string $file_path ): array {
        return array();
    }

    public function load_script_file_samples( string $script, string $category ): array {
        if ( 'cyrillic' === $script ) {
            $line = 'spam' === $category
                ? 'Купить дешевые таблетки онлайн скидка аптека доставка бесплатно'
                : 'Отличная статья о программировании и архитектуре приложений спасибо';

            $samples = array();
            for ( $i = 1; $i <= 12; $i++ ) {
                $samples[] = $line . ' ' . $i;
            }
            return $samples;
        }

        if ( 'latin' === $script ) {
            // Two samples per category: below MIN_TRAINING_SAMPLES, so this
            // script's own unit can never answer.
            return 'spam' === $category
                ? array(
                    'buy cheap pills online discount pharmacy shipping',
                    'cheap discount pills online pharmacy offer today',
                )
                : array(
                    'great article about software architecture and design',
                    'thanks for the detailed writeup it helped me a lot',
                );
        }

        return array();
    }
}

class BayesianCheckTest extends TestCase {

    private ASW_Naive_Bayes $classifier;
    private ASW_Bayesian_Check $check;

    protected function setUp(): void {
        parent::setUp();
        global $wp_options, $wp_transients, $wp_transient_reads;
        $wp_options         = array();
        $wp_transients      = array();
        $wp_transient_reads = array();

        ASW_Settings::update( array(
            'bayesian_enabled'   => true,
            'bayesian_threshold' => 0.7,
        ) );

        $this->classifier = new ASW_Naive_Bayes();

        // Train with enough samples to meet MIN_TRAINING_SAMPLES (10).
        $spam_samples = array(
            'Buy cheap viagra online free shipping discount',
            'Click here for amazing casino bonus free money',
            'Win lottery millions guaranteed free prize winner',
            'Cheap pills online pharmacy discount offer',
            'Make money fast guaranteed income work from home',
            'Free casino bonus slots jackpot win big prizes now',
            'Cheap medications online pharmacy no prescription needed',
            'Earn thousands per week from home easy money system',
            'Best weight loss pills guaranteed results fast diet',
            'Hot singles in your area looking for dates tonight',
        );
        $ham_samples = array(
            'Great article about programming patterns and design',
            'Thanks for sharing this helpful tutorial on databases',
            'I learned a lot from your explanation of algorithms',
            'This guide on software testing is very informative',
            'Interesting perspective on clean code architecture',
            'Has anyone else experienced this issue with the latest update',
            'I bookmarked this page for future reference thank you so much',
            'My team has been using this approach and it works really well',
            'Just started learning Python and this was very helpful for me',
            'The documentation could use more examples for edge cases here',
        );

        foreach ( $spam_samples as $sample ) {
            $this->classifier->learn( $sample, 'spam' );
        }
        foreach ( $ham_samples as $sample ) {
            $this->classifier->learn( $sample, 'ham' );
        }

        $this->check = new ASW_Bayesian_Check( $this->classifier );
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
        $this->assertInstanceOf( ASW_Check::class, $this->check );
    }

    public function test_spam_detected_above_threshold(): void {
        $result = $this->check->check( $this->make_comment(
            'Buy cheap viagra pills online free discount pharmacy'
        ) );
        $this->assertTrue( $result->is_spam() );
        $this->assertSame( 'bayesian', $result->get_check_name() );
        $this->assertStringContainsString( 'spam probability', $result->get_details() );
    }

    public function test_ham_below_threshold(): void {
        $result = $this->check->check( $this->make_comment(
            'This is a great article about software architecture and design patterns'
        ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'bayesian', $result->get_check_name() );
        $this->assertStringContainsString( 'spam probability', $result->get_details() );
    }

    public function test_disabled_check_returns_not_spam(): void {
        ASW_Settings::update( array( 'bayesian_enabled' => false ) );
        $result = $this->check->check( $this->make_comment(
            'Buy cheap viagra pills online free discount'
        ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'check disabled', $result->get_details() );
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

    public function test_custom_threshold(): void {
        // A spammy comment should be flagged at default threshold 0.7.
        $spam_comment = 'Buy cheap viagra pills online free discount pharmacy';
        $result_default = $this->check->check( $this->make_comment( $spam_comment ) );
        $this->assertTrue( $result_default->is_spam(), 'Should be spam at default threshold 0.7' );

        // Same comment should NOT be flagged when threshold is set to 1.0 (nothing can exceed 1.0).
        ASW_Settings::update( array( 'bayesian_threshold' => 1.0 ) );
        $result_high = $this->check->check( $this->make_comment( $spam_comment ) );
        $this->assertFalse( $result_high->is_spam(), 'Should not be spam at threshold 1.0' );
        $this->assertStringContainsString( '/1.00', $result_high->get_details() );
    }

    public function test_details_include_probability_and_threshold(): void {
        $result = $this->check->check( $this->make_comment(
            'Some comment about software development and testing'
        ) );
        // Details should match format: "spam probability X.XX/Y.YY"
        $this->assertMatchesRegularExpression(
            '/^spam probability \d+\.\d{2}\/\d+\.\d{2}$/',
            $result->get_details()
        );
    }

    public function test_short_comment_skips_bayesian_check(): void {
        ASW_Settings::update( array(
            'bayesian_enabled'   => true,
            'bayesian_threshold' => 0.7,
            'min_message_length' => 50,
        ) );
        // 49 chars — below threshold.
        $result = $this->check->check( $this->make_comment( str_repeat( 'a', 49 ) ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'bayesian', $result->get_check_name() );
        $this->assertSame( 'comment too short for bayesian', $result->get_details() );
    }

    public function test_comment_at_threshold_length_proceeds_normally(): void {
        ASW_Settings::update( array(
            'bayesian_enabled'   => true,
            'bayesian_threshold' => 0.7,
            'min_message_length' => 50,
        ) );
        // Exactly 50 chars — should proceed to classification.
        $result = $this->check->check( $this->make_comment( str_repeat( 'a', 50 ) ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'bayesian', $result->get_check_name() );
        $this->assertStringContainsString( 'spam probability', $result->get_details() );
    }

    public function test_comment_above_threshold_length_proceeds_normally(): void {
        ASW_Settings::update( array(
            'bayesian_enabled'   => true,
            'bayesian_threshold' => 0.7,
            'min_message_length' => 50,
        ) );
        $result = $this->check->check( $this->make_comment(
            'Buy cheap viagra pills online free discount pharmacy shipping now'
        ) );
        $this->assertTrue( $result->is_spam() );
        $this->assertStringContainsString( 'spam probability', $result->get_details() );
    }

    public function test_untrained_classifier_returns_insufficient_data(): void {
        ASW_Settings::update( array(
            'bayesian_enabled'   => true,
            'min_message_length' => 1,
        ) );
        $empty_classifier = new ASW_Naive_Bayes();
        $check            = new ASW_Bayesian_Check( $empty_classifier );
        $result           = $check->check( $this->make_comment( 'Some random comment text here' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'insufficient training data', $result->get_details() );
    }

    public function test_insufficient_spam_samples_skips_check(): void {
        ASW_Settings::update( array(
            'bayesian_enabled'   => true,
            'min_message_length' => 1,
        ) );
        $classifier = new ASW_Naive_Bayes();
        // Train with 5 spam (below 10) and 10 ham.
        for ( $i = 0; $i < 5; $i++ ) {
            $classifier->learn( "spam sample number $i buy cheap pills", 'spam' );
        }
        for ( $i = 0; $i < 10; $i++ ) {
            $classifier->learn( "ham sample number $i about programming", 'ham' );
        }
        $check  = new ASW_Bayesian_Check( $classifier );
        $result = $check->check( $this->make_comment( 'Buy cheap pills online discount pharmacy' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'insufficient training data', $result->get_details() );
    }

    public function test_insufficient_ham_samples_skips_check(): void {
        ASW_Settings::update( array(
            'bayesian_enabled'   => true,
            'min_message_length' => 1,
        ) );
        $classifier = new ASW_Naive_Bayes();
        // Train with 10 spam and 5 ham (below 10).
        for ( $i = 0; $i < 10; $i++ ) {
            $classifier->learn( "spam sample number $i buy cheap pills", 'spam' );
        }
        for ( $i = 0; $i < 5; $i++ ) {
            $classifier->learn( "ham sample number $i about programming", 'ham' );
        }
        $check  = new ASW_Bayesian_Check( $classifier );
        $result = $check->check( $this->make_comment( 'Buy cheap pills online discount pharmacy' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'insufficient training data', $result->get_details() );
    }

    public function test_exactly_min_samples_proceeds_to_classify(): void {
        ASW_Settings::update( array(
            'bayesian_enabled'   => true,
            'bayesian_threshold' => 0.7,
            'min_message_length' => 1,
        ) );
        $classifier = new ASW_Naive_Bayes();
        // Train with exactly 10 of each (meets MIN_TRAINING_SAMPLES).
        for ( $i = 0; $i < 10; $i++ ) {
            $classifier->learn( "spam sample number $i buy cheap pills", 'spam' );
        }
        for ( $i = 0; $i < 10; $i++ ) {
            $classifier->learn( "ham sample number $i about programming", 'ham' );
        }
        $check  = new ASW_Bayesian_Check( $classifier );
        $result = $check->check( $this->make_comment( 'Buy cheap pills online discount pharmacy' ) );
        // Should proceed to classification (not return insufficient data).
        $this->assertStringContainsString( 'spam probability', $result->get_details() );
    }

    public function test_default_threshold_is_0_7(): void {
        $defaults = ASW_Settings::get_defaults();
        $this->assertSame( 0.7, $defaults['bayesian_threshold'] );
    }

    // --- Script-aware routing tests ---

    private function make_trained_classifier( array $spam_texts, array $ham_texts ): ASW_Naive_Bayes {
        $classifier = new ASW_Naive_Bayes();
        foreach ( $spam_texts as $text ) {
            $classifier->learn( $text, 'spam' );
        }
        foreach ( $ham_texts as $text ) {
            $classifier->learn( $text, 'ham' );
        }
        return $classifier;
    }

    public function test_script_routing_uses_latin_classifier(): void {
        ASW_Settings::update( array(
            'bayesian_enabled'   => true,
            'bayesian_threshold' => 0.7,
            'min_message_length' => 1,
        ) );

        $latin_spam = array_fill( 0, 10, 'Buy cheap viagra pills online free discount pharmacy' );
        $latin_ham  = array_fill( 0, 10, 'Great article about programming patterns and design' );
        $latin_classifier = $this->make_trained_classifier( $latin_spam, $latin_ham );

        // Combined classifier with no real data — should return insufficient if used.
        $combined = new ASW_Naive_Bayes();

        $check  = new ASW_Bayesian_Check(
            array( 'latin' => $latin_classifier ),
            $combined
        );

        $result = $check->check( $this->make_comment(
            'Buy cheap viagra pills online free discount pharmacy'
        ) );
        // Should route to latin classifier (which has data) rather than combined (which has none).
        $this->assertTrue( $result->is_spam() );
        $this->assertStringContainsString( 'spam probability', $result->get_details() );
    }

    public function test_script_routing_uses_cyrillic_classifier(): void {
        ASW_Settings::update( array(
            'bayesian_enabled'   => true,
            'bayesian_threshold' => 0.7,
            'min_message_length' => 1,
        ) );

        $cyrillic_spam = array_fill( 0, 10, 'Купить дешевые таблетки онлайн скидка доставка бесплатно' );
        $cyrillic_ham  = array_fill( 0, 10, 'Отличная статья о программировании и архитектуре приложений' );
        $cyrillic_classifier = $this->make_trained_classifier( $cyrillic_spam, $cyrillic_ham );

        $combined = new ASW_Naive_Bayes();

        $check = new ASW_Bayesian_Check(
            array( 'cyrillic' => $cyrillic_classifier ),
            $combined
        );

        $result = $check->check( $this->make_comment(
            'Купить дешевые таблетки онлайн скидка доставка бесплатно быстро'
        ) );
        $this->assertTrue( $result->is_spam() );
    }

    public function test_script_routing_falls_back_to_combined_for_unknown(): void {
        ASW_Settings::update( array(
            'bayesian_enabled'   => true,
            'bayesian_threshold' => 0.7,
            'min_message_length' => 1,
        ) );

        $latin_spam = array_fill( 0, 10, 'Buy cheap viagra pills online' );
        $latin_ham  = array_fill( 0, 10, 'Great article about programming' );
        $latin_classifier = $this->make_trained_classifier( $latin_spam, $latin_ham );

        $combined_spam = array_fill( 0, 10, '12345 67890 99999 discount pills' );
        $combined_ham  = array_fill( 0, 10, '12345 67890 99999 great article' );
        $combined = $this->make_trained_classifier( $combined_spam, $combined_ham );

        $check = new ASW_Bayesian_Check(
            array( 'latin' => $latin_classifier ),
            $combined
        );

        // Numbers-only text — script detection returns 'unknown', should use combined.
        $result = $check->check( $this->make_comment(
            '12345 67890 99999 88888 77777 66666 55555 44444 33333 22222'
        ) );
        // Numbers-only produces no letter tokens, so combined classifier lacks signal.
        // Verify it reaches the classifier (not rejected early) and returns a valid result.
        $this->assertStringContainsString( 'spam probability', $result->get_details() );
    }

    public function test_script_routing_falls_back_when_no_script_classifier(): void {
        ASW_Settings::update( array(
            'bayesian_enabled'   => true,
            'bayesian_threshold' => 0.7,
            'min_message_length' => 1,
        ) );

        // Only Latin classifier exists, but comment is Cyrillic.
        $latin_spam = array_fill( 0, 10, 'Buy cheap viagra pills online' );
        $latin_ham  = array_fill( 0, 10, 'Great article about programming' );
        $latin_classifier = $this->make_trained_classifier( $latin_spam, $latin_ham );

        $combined_spam = array_fill( 0, 10, 'Купить дешевые таблетки онлайн скидка доставка бесплатно' );
        $combined_ham  = array_fill( 0, 10, 'Отличная статья о программировании и архитектуре приложений' );
        $combined = $this->make_trained_classifier( $combined_spam, $combined_ham );

        $check = new ASW_Bayesian_Check(
            array( 'latin' => $latin_classifier ),
            $combined
        );

        // Cyrillic comment — no cyrillic classifier, should fall back to combined.
        $result = $check->check( $this->make_comment(
            'Купить дешевые таблетки онлайн скидка доставка бесплатно быстро'
        ) );
        $this->assertTrue( $result->is_spam() );
    }

    public function test_legacy_constructor_still_works(): void {
        // Single classifier passed directly — original API.
        $check = new ASW_Bayesian_Check( $this->classifier );
        $result = $check->check( $this->make_comment(
            'Buy cheap viagra pills online free discount pharmacy'
        ) );
        $this->assertTrue( $result->is_spam() );
    }

    // --- CJK/Thai short comment min-length tests ---

    public function test_short_cjk_comment_passes_min_length_check(): void {
        ASW_Settings::update( array(
            'bayesian_enabled'   => true,
            'bayesian_threshold' => 0.7,
            'min_message_length' => 50,
        ) );

        // 20-char CJK comment — above dense script threshold (10), below default (50).
        // Should reach the classifier, not be skipped as too short.
        $result = $this->check->check( $this->make_comment(
            '今すぐ無料でお金を稼ぐ方法をクリックしてくだ'
        ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'bayesian', $result->get_check_name() );
        // Should reach classifier (insufficient data or spam probability), NOT "too short".
        $this->assertStringNotContainsString( 'too short', $result->get_details() );
    }

    public function test_short_thai_comment_passes_min_length_check(): void {
        ASW_Settings::update( array(
            'bayesian_enabled'   => true,
            'bayesian_threshold' => 0.7,
            'min_message_length' => 50,
        ) );

        // 20-char Thai comment — above dense script threshold (10), below default (50).
        $result = $this->check->check( $this->make_comment(
            'สวัสดีครับวันนี้อากาศดีมาก'
        ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'bayesian', $result->get_check_name() );
        $this->assertStringNotContainsString( 'too short', $result->get_details() );
    }

    public function test_short_latin_comment_still_skipped(): void {
        ASW_Settings::update( array(
            'bayesian_enabled'   => true,
            'bayesian_threshold' => 0.7,
            'min_message_length' => 50,
        ) );

        // 20-char Latin comment — below default threshold (50), no dense script exception.
        $result = $this->check->check( $this->make_comment( str_repeat( 'a', 20 ) ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'comment too short for bayesian', $result->get_details() );
    }

    // --- Fallback behavior tests ---

    public function test_empty_script_data_falls_back_to_combined(): void {
        ASW_Settings::update( array(
            'bayesian_enabled'   => true,
            'bayesian_threshold' => 0.7,
            'min_message_length' => 1,
        ) );

        // Greek classifier exists but has no training data.
        $greek_classifier = new ASW_Naive_Bayes();

        // Combined classifier is trained and should be used as fallback.
        $combined_spam = array_fill( 0, 10, 'Αγοράστε φθηνά χάπια δωρεάν έκπτωση φαρμακείο' );
        $combined_ham  = array_fill( 0, 10, 'Εξαιρετικό άρθρο για τον προγραμματισμό και τη σχεδίαση' );
        $combined = $this->make_trained_classifier( $combined_spam, $combined_ham );

        $check = new ASW_Bayesian_Check(
            array( 'greek' => $greek_classifier ),
            $combined
        );

        // Greek comment — greek classifier is empty, should fall back to combined.
        $result = $check->check( $this->make_comment(
            'Αγοράστε φθηνά χάπια δωρεάν έκπτωση φαρμακείο τώρα'
        ) );
        // Combined has data, so should classify (not return insufficient data).
        $this->assertStringContainsString( 'spam probability', $result->get_details() );
    }

    public function test_combined_below_min_samples_returns_insufficient(): void {
        ASW_Settings::update( array(
            'bayesian_enabled'   => true,
            'bayesian_threshold' => 0.7,
            'min_message_length' => 1,
        ) );

        // No script classifiers; combined has fewer than MIN_TRAINING_SAMPLES.
        $combined = new ASW_Naive_Bayes();
        for ( $i = 0; $i < 5; $i++ ) {
            $combined->learn( "spam sample $i buy cheap pills", 'spam' );
        }
        for ( $i = 0; $i < 5; $i++ ) {
            $combined->learn( "ham sample $i great article", 'ham' );
        }

        $check = new ASW_Bayesian_Check( array(), $combined );

        $result = $check->check( $this->make_comment( 'Buy cheap pills online discount pharmacy' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'insufficient training data', $result->get_details() );
    }

    public function test_imbalanced_data_biases_toward_majority_class(): void {
        ASW_Settings::update( array(
            'bayesian_enabled'   => true,
            'bayesian_threshold' => 0.7,
            'min_message_length' => 1,
        ) );

        $classifier = new ASW_Naive_Bayes();
        // 10 spam samples (minimum).
        for ( $i = 0; $i < 10; $i++ ) {
            $classifier->learn( "spam sample $i buy cheap pills discount offer", 'spam' );
        }
        // 90 ham samples (heavily imbalanced toward ham).
        for ( $i = 0; $i < 90; $i++ ) {
            $classifier->learn( "ham sample $i great article about programming design patterns", 'ham' );
        }

        $check = new ASW_Bayesian_Check( $classifier );

        // An ambiguous comment should lean toward ham due to class imbalance.
        $result = $check->check( $this->make_comment(
            'This is a sample about patterns and design in general'
        ) );
        $this->assertFalse( $result->is_spam() );
    }

    public function test_per_script_exactly_min_samples_activates(): void {
        ASW_Settings::update( array(
            'bayesian_enabled'   => true,
            'bayesian_threshold' => 0.7,
            'min_message_length' => 1,
        ) );

        // Per-script classifier with exactly MIN_TRAINING_SAMPLES (10) in each category.
        $cyrillic_spam = array_fill( 0, 10, 'Купить дешевые таблетки онлайн скидка доставка бесплатно' );
        $cyrillic_ham  = array_fill( 0, 10, 'Отличная статья о программировании и архитектуре приложений' );
        $cyrillic_classifier = $this->make_trained_classifier( $cyrillic_spam, $cyrillic_ham );

        // Combined classifier is untrained — if fallback is used, it would return insufficient.
        $combined = new ASW_Naive_Bayes();

        $check = new ASW_Bayesian_Check(
            array( 'cyrillic' => $cyrillic_classifier ),
            $combined
        );

        $result = $check->check( $this->make_comment(
            'Купить дешевые таблетки онлайн скидка доставка бесплатно быстро'
        ) );
        // Should use cyrillic classifier (not fall back to empty combined).
        $this->assertTrue( $result->is_spam() );
        $this->assertStringContainsString( 'spam probability', $result->get_details() );
    }

    public function test_script_below_min_samples_falls_back_to_combined(): void {
        ASW_Settings::update( array(
            'bayesian_enabled'   => true,
            'bayesian_threshold' => 0.7,
            'min_message_length' => 1,
        ) );

        // Cyrillic classifier exists but has only 5 samples per category (below MIN).
        $cyrillic_classifier = new ASW_Naive_Bayes();
        for ( $i = 0; $i < 5; $i++ ) {
            $cyrillic_classifier->learn( "Купить дешевые таблетки $i", 'spam' );
        }
        for ( $i = 0; $i < 5; $i++ ) {
            $cyrillic_classifier->learn( "Отличная статья $i", 'ham' );
        }

        // Combined classifier has sufficient data.
        $combined_spam = array_fill( 0, 10, 'Купить дешевые таблетки онлайн скидка доставка бесплатно' );
        $combined_ham  = array_fill( 0, 10, 'Отличная статья о программировании и архитектуре приложений' );
        $combined = $this->make_trained_classifier( $combined_spam, $combined_ham );

        $check = new ASW_Bayesian_Check(
            array( 'cyrillic' => $cyrillic_classifier ),
            $combined
        );

        // Cyrillic comment — cyrillic classifier is under-trained, should fall back to combined.
        $result = $check->check( $this->make_comment(
            'Купить дешевые таблетки онлайн скидка доставка бесплатно быстро'
        ) );
        $this->assertTrue( $result->is_spam() );
        $this->assertStringContainsString( 'spam probability', $result->get_details() );
    }

    public function test_very_short_cjk_comment_still_skipped(): void {
        ASW_Settings::update( array(
            'bayesian_enabled'   => true,
            'bayesian_threshold' => 0.7,
            'min_message_length' => 50,
        ) );

        // 5-char CJK comment — below even the dense script threshold (10).
        $result = $this->check->check( $this->make_comment( '今すぐ無料' ) );
        $this->assertFalse( $result->is_spam() );
        $this->assertSame( 'comment too short for bayesian', $result->get_details() );
    }

    // --- ASW_Model_Store construction form ---

    /**
     * Build a store-backed check over the two-script fixture corpus.
     */
    private function make_store_check(): ASW_Bayesian_Check {
        ASW_Settings::update( array(
            'bayesian_enabled'   => true,
            'bayesian_threshold' => 0.7,
            'min_message_length' => 20,
        ) );

        $store = new ASW_Model_Store(
            new ASW_Tokenizer( array() ),
            array(),
            new ASW_Bayesian_Store_Corpus(),
            null
        );

        return new ASW_Bayesian_Check( $store );
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
        $this->assertCount( 1, $reads, 'A Cyrillic body must load the Cyrillic unit and nothing else' );
        $this->assertSame( ASW_Model_Store::TRANSIENT_PREFIX . 'cyrillic', $reads[0] );
    }

    public function test_store_backed_check_falls_back_to_the_combined_unit_for_an_under_trained_script(): void {
        $check = $this->make_store_check();

        $result = $check->check( $this->make_comment(
            'buy cheap pills online discount pharmacy shipping today'
        ) );

        // The Latin unit holds two samples per category, so it can only have
        // been the combined unit that answered: its own would be undertrained.
        $this->assertStringContainsString( 'spam probability', $result->get_details() );

        $reads = $this->transient_reads();
        $this->assertCount( 2, $reads );
        $this->assertSame( ASW_Model_Store::TRANSIENT_PREFIX . 'latin', $reads[0] );
        $this->assertSame( ASW_Model_Store::TRANSIENT_PREFIX . 'combined', $reads[1] );
    }

    public function test_store_backed_check_goes_straight_to_the_combined_unit_for_an_unknown_script(): void {
        $check = $this->make_store_check();

        // No letter characters at all: ASW_Script_Detector answers 'unknown',
        // which has no per-script unit to load.
        $result = $check->check( $this->make_comment(
            '1234, 5678, 9012, 3456, 7890, 2345, 6789, 0123, 4567'
        ) );

        $this->assertStringContainsString( 'spam probability', $result->get_details() );

        $reads = $this->transient_reads();
        $this->assertCount( 1, $reads );
        $this->assertSame( ASW_Model_Store::TRANSIENT_PREFIX . 'combined', $reads[0] );
    }

    public function test_store_backed_check_loads_nothing_for_a_body_below_the_minimum_length(): void {
        $check = $this->make_store_check();

        $result = $check->check( $this->make_comment( 'Спасибо' ) );

        $this->assertSame( 'comment too short for bayesian', $result->get_details() );
        $this->assertSame( array(), $this->transient_reads(), 'The length gate must return before any unit is loaded' );
    }

    public function test_a_disabled_store_backed_check_loads_nothing(): void {
        $check = $this->make_store_check();
        ASW_Settings::update( array( 'bayesian_enabled' => false ) );

        $result = $check->check( $this->make_comment(
            'Купить дешевые таблетки онлайн скидка аптека доставка бесплатно'
        ) );

        $this->assertSame( 'check disabled', $result->get_details() );
        $this->assertSame( array(), $this->transient_reads() );
    }
}
