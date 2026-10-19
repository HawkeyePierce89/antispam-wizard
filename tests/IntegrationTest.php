<?php
/**
 * Integration tests: full comment submission through detection to spam marking.
 */

use PHPUnit\Framework\TestCase;

class IntegrationTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        // Reset all global state.
        global $wp_options, $wp_actions, $wp_filters, $wp_comment_meta, $wp_transients;
        $wp_options      = array();
        $wp_actions      = array();
        $wp_filters      = array();
        $wp_comment_meta = array();
        $wp_transients   = array();
    }

    /**
     * Build a Detector with all 8 real checks wired, matching the main plugin class.
     *
     * Uses per-script classifiers and a combined fallback, reproducing the model
     * composition ASW::build_checks() gets from ASW_Model_Store -- including the
     * combined similarity corpus, which is the legacy samples only -- through
     * the checks' array constructors.
     */
    private function build_detector(): ASW_Detector {
        $tokenizer     = new ASW_Tokenizer();
        $training_data = new ASW_Training_Data();

        // Build per-script classifiers and pre-tokenized samples.
        $script_classifiers   = array();
        $script_sample_tokens = array();

        foreach ( array_keys( ASW_Script_Detector::SCRIPT_GROUPS ) as $script ) {
            $spam_samples = $training_data->load_script_samples( $script, 'spam' );
            $ham_samples  = $training_data->load_script_samples( $script, 'ham' );

            if ( empty( $spam_samples ) && empty( $ham_samples ) ) {
                continue;
            }

            $script_classifier = new ASW_Naive_Bayes(
                new ASW_Tokenizer(),
                $script
            );
            $training_data->train_script_classifier( $script_classifier, $script );
            $script_classifiers[ $script ] = $script_classifier;

            $tokens_list = array();
            foreach ( $spam_samples as $sample ) {
                $tokens = $tokenizer->tokenize_for_script( $sample, $script );
                if ( ! empty( $tokens ) ) {
                    $tokens_list[] = $tokens;
                }
            }
            if ( ! empty( $tokens_list ) ) {
                $script_sample_tokens[ $script ] = $tokens_list;
            }
        }

        // Combined classifier for unknown script fallback.
        $combined_classifier = new ASW_Naive_Bayes( $tokenizer );
        $training_data->train_combined_classifier( $combined_classifier );

        // Pre-tokenize the combined spam samples for the similarity fallback,
        // exactly as ASW_Model_Store::build_combined_unit() does: the legacy
        // corpus only (plus the repository's unknown-script rows, and there is
        // no repository here), never the ten per-script lists, and tokenised
        // for each sample's own detected script. Merging the per-script lists
        // in here would build a corpus ~10x the shipped one and make every
        // fallback assertion below describe a configuration that cannot occur
        // at runtime.
        $combined_sample_tokens = array();
        $data_dir    = dirname( __DIR__ ) . '/antispam-wizard/data/';
        $legacy_spam = $training_data->load_samples( $data_dir . 'spam-samples.txt' );
        foreach ( $legacy_spam as $sample ) {
            $tokens = $tokenizer->tokenize_for_script( $sample, ASW_Script_Detector::detect( $sample ) );
            if ( ! empty( $tokens ) ) {
                $combined_sample_tokens[] = $tokens;
            }
        }

        $checks = array(
            new ASW_Stop_Words_Check(),
            new ASW_Links_Check(),
            new ASW_Emoji_Check(),
            new ASW_Spacing_Check(),
            new ASW_Multi_Lang_Check(),
            new ASW_Duplicate_Check(),
            new ASW_Bayesian_Check( $script_classifiers, $combined_classifier ),
            new ASW_Similarity_Check(
                $tokenizer,
                new ASW_Cosine_Similarity(),
                array(),
                $script_sample_tokens,
                $combined_sample_tokens
            ),
        );
        return new ASW_Detector( $checks );
    }

    public function test_legitimate_comment_passes_through(): void {
        $detector = $this->build_detector();
        $detector->register_hooks();

        $result = apply_filters( 'pre_comment_approved', 1, array(
            'comment_content'   => 'Great article, thanks for sharing your insights on this topic!',
            'comment_author_IP' => '192.168.1.100',
        ) );

        $this->assertEquals( 1, $result );
    }

    public function test_comment_with_too_many_links_marked_spam(): void {
        $detector = $this->build_detector();
        $detector->register_hooks();

        $result = apply_filters( 'pre_comment_approved', 1, array(
            'comment_content'   => 'Visit http://spam1.com and http://spam2.com and http://spam3.com now!',
            'comment_author_IP' => '10.0.0.1',
        ) );

        $this->assertEquals( 'spam', $result );

        // Verify spam log was recorded.
        $log = ASW_Spam_Log::get_entries();
        $this->assertCount( 1, $log );
        $this->assertContains( 'links', $log[0]['checks_triggered'] );
    }

    public function test_comment_with_stop_word_marked_spam(): void {
        // Configure stop words.
        ASW_Settings::update( array(
            'stop_words_list' => array( 'buy viagra' ),
        ) );

        $detector = $this->build_detector();
        $detector->register_hooks();

        $result = apply_filters( 'pre_comment_approved', 1, array(
            'comment_content'   => 'You should buy viagra online today!',
            'comment_author_IP' => '10.0.0.2',
        ) );

        $this->assertEquals( 'spam', $result );

        $log = ASW_Spam_Log::get_entries();
        $this->assertCount( 1, $log );
        $this->assertContains( 'stop_words', $log[0]['checks_triggered'] );
    }

    public function test_statistics_recorded_on_spam(): void {
        $detector = $this->build_detector();
        $detector->register_hooks();

        apply_filters( 'pre_comment_approved', 1, array(
            'comment_content'   => 'Click http://a.com http://b.com http://c.com now!',
            'comment_author_IP' => '10.0.0.3',
        ) );

        $stats = ASW_Stats::get_all();
        $this->assertEquals( 1, $stats['total_blocked'] );
        $this->assertArrayHasKey( 'links', $stats['per_check'] );
        $this->assertEquals( 1, $stats['per_check']['links'] );
        $this->assertNotEmpty( $stats['last_blocked_date'] );
    }

    public function test_comment_meta_stored_on_spam(): void {
        global $wp_actions;

        $detector = $this->build_detector();
        $detector->register_hooks();

        apply_filters( 'pre_comment_approved', 1, array(
            'comment_content'   => 'Go to http://a.com http://b.com http://c.com http://d.com',
            'comment_author_IP' => '10.0.0.4',
        ) );

        // Simulate WordPress calling comment_post after comment is inserted.
        $this->assertArrayHasKey( 'comment_post', $wp_actions );
        do_action( 'comment_post', 123 );

        $reason = get_comment_meta( 123, '_asw_reason', true );
        $this->assertStringContainsString( 'links', $reason );
    }

    public function test_ip_is_hashed_before_storage(): void {
        $detector = $this->build_detector();
        $detector->register_hooks();

        apply_filters( 'pre_comment_approved', 1, array(
            'comment_content'   => 'Visit http://a.com http://b.com http://c.com please!',
            'comment_author_IP' => '203.0.113.50',
        ) );

        $log = ASW_Spam_Log::get_entries();
        $this->assertCount( 1, $log );
        // IP should be hashed, not raw.
        $this->assertNotEquals( '203.0.113.50', $log[0]['ip_hash'] );
        $this->assertEquals( ASW_Detector::hash_ip( '203.0.113.50' ), $log[0]['ip_hash'] );
    }

    public function test_duplicate_comments_from_same_ip_marked_spam(): void {
        $detector = $this->build_detector();
        $detector->register_hooks();

        $comment = array(
            'comment_content'   => 'A perfectly normal comment with no spam indicators at all.',
            'comment_author_IP' => '10.0.0.5',
        );

        // Default threshold is 3, so first two pass, third is spam.
        $result1 = apply_filters( 'pre_comment_approved', 1, $comment );
        $this->assertEquals( 1, $result1 );

        // Need to re-register hooks because apply_filters uses flat array.
        global $wp_filters;
        $wp_filters = array();
        $detector->register_hooks();

        $result2 = apply_filters( 'pre_comment_approved', 1, $comment );
        $this->assertEquals( 1, $result2 );

        $wp_filters = array();
        $detector->register_hooks();

        $result3 = apply_filters( 'pre_comment_approved', 1, $comment );
        $this->assertEquals( 'spam', $result3 );
    }

    public function test_multiple_checks_can_trigger_simultaneously(): void {
        // Configure stop words to also match.
        ASW_Settings::update( array(
            'stop_words_list' => array( 'buy now' ),
        ) );

        $detector = $this->build_detector();
        $detector->register_hooks();

        $result = apply_filters( 'pre_comment_approved', 1, array(
            'comment_content'   => 'Buy now at http://spam1.com http://spam2.com http://spam3.com!',
            'comment_author_IP' => '10.0.0.6',
        ) );

        $this->assertEquals( 'spam', $result );

        $log = ASW_Spam_Log::get_entries();
        $this->assertCount( 1, $log );
        // With short-circuit, only the first matching check (stop_words) is reported.
        $this->assertContains( 'stop_words', $log[0]['checks_triggered'] );

        // Stats should reflect the first matching check.
        $stats = ASW_Stats::get_all();
        $this->assertArrayHasKey( 'stop_words', $stats['per_check'] );
    }

    public function test_comment_with_too_many_emoji_marked_spam(): void {
        $detector = $this->build_detector();
        $detector->register_hooks();

        $result = apply_filters( 'pre_comment_approved', 1, array(
            'comment_content'   => 'Great post! 😀😂🎉🔥💯',
            'comment_author_IP' => '10.0.0.20',
        ) );

        $this->assertEquals( 'spam', $result );

        $log = ASW_Spam_Log::get_entries();
        $this->assertNotEmpty( $log );
        $last = end( $log );
        $this->assertContains( 'emoji', $last['checks_triggered'] );
    }

    public function test_comment_with_spaced_out_text_marked_spam(): void {
        $detector = $this->build_detector();
        $detector->register_hooks();

        $result = apply_filters( 'pre_comment_approved', 1, array(
            'comment_content'   => 'B u y n o w a t o u r s t o r e',
            'comment_author_IP' => '10.0.0.21',
        ) );

        $this->assertEquals( 'spam', $result );

        $log = ASW_Spam_Log::get_entries();
        $this->assertNotEmpty( $log );
        $last = end( $log );
        $this->assertContains( 'spacing', $last['checks_triggered'] );
    }

    public function test_comment_with_mixed_scripts_marked_spam(): void {
        ASW_Settings::update( array(
            'multi_lang_threshold' => 2,
        ) );

        $detector = $this->build_detector();
        $detector->register_hooks();

        // Words that mix Latin and Cyrillic within the same word (homoglyph attack).
        $result = apply_filters( 'pre_comment_approved', 1, array(
            'comment_content'   => "V\xD0\xB8agra C\xD0\xB0sino are great deals",
            'comment_author_IP' => '10.0.0.22',
        ) );

        $this->assertEquals( 'spam', $result );

        $log = ASW_Spam_Log::get_entries();
        $this->assertNotEmpty( $log );
        $last = end( $log );
        $this->assertContains( 'multi_lang', $last['checks_triggered'] );
    }

    public function test_disabled_checks_do_not_trigger(): void {
        // Disable the links check.
        ASW_Settings::update( array(
            'links_enabled' => false,
        ) );

        $detector = $this->build_detector();
        $detector->register_hooks();

        $result = apply_filters( 'pre_comment_approved', 1, array(
            'comment_content'   => 'Check http://a.com http://b.com http://c.com please!',
            'comment_author_IP' => '10.0.0.7',
        ) );

        // Links check is disabled, so this should pass.
        $this->assertEquals( 1, $result );
    }

    public function test_bayesian_check_flags_spam_comment(): void {
        // Disable all other checks so only bayesian triggers.
        ASW_Settings::update( array(
            'stop_words_enabled'  => false,
            'links_enabled'       => false,
            'emoji_enabled'       => false,
            'spacing_enabled'     => false,
            'multi_lang_enabled'  => false,
            'duplicate_enabled'   => false,
            'similarity_enabled'  => false,
            'bayesian_enabled'    => true,
            'bayesian_threshold'  => 0.7,
        ) );

        $detector = $this->build_detector();
        $detector->register_hooks();

        $result = apply_filters( 'pre_comment_approved', 1, array(
            'comment_content'   => 'Buy cheap viagra online discount pharmacy pills free money',
            'comment_author_IP' => '10.0.0.30',
        ) );

        $this->assertEquals( 'spam', $result );

        $log = ASW_Spam_Log::get_entries();
        $this->assertNotEmpty( $log );
        $last = end( $log );
        $this->assertContains( 'bayesian', $last['checks_triggered'] );
    }

    public function test_bayesian_check_disabled_does_not_flag(): void {
        // Disable all checks including bayesian.
        ASW_Settings::update( array(
            'stop_words_enabled'  => false,
            'links_enabled'       => false,
            'emoji_enabled'       => false,
            'spacing_enabled'     => false,
            'multi_lang_enabled'  => false,
            'duplicate_enabled'   => false,
            'similarity_enabled'  => false,
            'bayesian_enabled'    => false,
        ) );

        $detector = $this->build_detector();
        $detector->register_hooks();

        $result = apply_filters( 'pre_comment_approved', 1, array(
            'comment_content'   => 'Buy cheap viagra online discount pharmacy pills free money',
            'comment_author_IP' => '10.0.0.31',
        ) );

        $this->assertEquals( 1, $result );
    }
}
