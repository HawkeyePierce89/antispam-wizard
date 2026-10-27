<?php
/**
 * Integration tests for the full Unicode script-based routing pipeline.
 *
 * Verifies that comments in various scripts are routed to the correct
 * per-script classifiers and that unknown scripts fall back to the combined model.
 */

use PHPUnit\Framework\TestCase;

class ScriptRoutingIntegrationTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        global $wp_options, $wp_actions, $wp_filters, $wp_comment_meta, $wp_transients;
        $wp_options      = array();
        $wp_actions      = array();
        $wp_filters      = array();
        $wp_comment_meta = array();
        $wp_transients   = array();

        // Disable all checks except bayesian so we can isolate routing behavior.
        ANTISPAMWIZARD_Settings::update( array(
            'stop_words_enabled'  => false,
            'links_enabled'       => false,
            'emoji_enabled'       => false,
            'spacing_enabled'     => false,
            'multi_lang_enabled'  => false,
            'duplicate_enabled'   => false,
            'similarity_enabled'  => false,
            'bayesian_enabled'    => true,
            'bayesian_threshold'  => 0.7,
            'min_message_length'  => 1,
        ) );
    }

    /**
     * Build per-script classifiers and a combined fallback from training data files,
     * reproducing the model composition ANTISPAMWIZARD::build_checks() gets from
     * ANTISPAMWIZARD_Model_Store -- same per-script sets, same combined classifier and the
     * same narrowed combined similarity corpus -- through the checks' array
     * constructors, so a routing verdict can be asserted without the cache.
     */
    private function build_detector( bool $enable_similarity = false ): ANTISPAMWIZARD_Detector {
        $tokenizer     = new ANTISPAMWIZARD_Tokenizer();
        $training_data = new ANTISPAMWIZARD_Training_Data();

        $script_classifiers   = array();
        $script_sample_tokens = array();

        foreach ( array_keys( ANTISPAMWIZARD_Script_Detector::SCRIPT_GROUPS ) as $script ) {
            $spam_samples = $training_data->load_script_samples( $script, 'spam' );
            $ham_samples  = $training_data->load_script_samples( $script, 'ham' );

            if ( empty( $spam_samples ) && empty( $ham_samples ) ) {
                continue;
            }

            $script_tokenizer  = new ANTISPAMWIZARD_Tokenizer();
            $script_classifier = new ANTISPAMWIZARD_Naive_Bayes( $script_tokenizer, $script );
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
        $combined_classifier = new ANTISPAMWIZARD_Naive_Bayes( $tokenizer );
        $training_data->train_combined_classifier( $combined_classifier );

        // Pre-tokenize the combined spam samples for the similarity fallback,
        // exactly as ANTISPAMWIZARD_Model_Store::build_combined_unit() does: the legacy
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
            $tokens = $tokenizer->tokenize_for_script( $sample, ANTISPAMWIZARD_Script_Detector::detect( $sample ) );
            if ( ! empty( $tokens ) ) {
                $combined_sample_tokens[] = $tokens;
            }
        }

        if ( $enable_similarity ) {
            ANTISPAMWIZARD_Settings::update( array_merge(
                ANTISPAMWIZARD_Settings::get_all(),
                array( 'similarity_enabled' => true )
            ) );
        }

        $checks = array(
            new ANTISPAMWIZARD_Stop_Words_Check(),
            new ANTISPAMWIZARD_Links_Check(),
            new ANTISPAMWIZARD_Emoji_Check(),
            new ANTISPAMWIZARD_Spacing_Check(),
            new ANTISPAMWIZARD_Multi_Lang_Check(),
            new ANTISPAMWIZARD_Duplicate_Check(),
            new ANTISPAMWIZARD_Bayesian_Check( $script_classifiers, $combined_classifier ),
            new ANTISPAMWIZARD_Similarity_Check(
                $tokenizer,
                new ANTISPAMWIZARD_Cosine_Similarity(),
                array(),
                $script_sample_tokens,
                $combined_sample_tokens
            ),
        );
        return new ANTISPAMWIZARD_Detector( $checks );
    }

    private function run_comment( ANTISPAMWIZARD_Detector $detector, string $content ): mixed {
        $detector->register_hooks();
        return apply_filters( 'pre_comment_approved', 1, array(
            'comment_content'   => $content,
            'comment_author_IP' => '10.0.0.100',
        ) );
    }

    // --- Routing tests per script ---

    public function test_latin_comment_routed_to_latin_classifier(): void {
        $detector = $this->build_detector();
        $result   = $this->run_comment( $detector,
            'Buy cheap viagra pills online free discount pharmacy shipping worldwide best deal'
        );

        $this->assertEquals( 'spam', $result );

        $log  = ANTISPAMWIZARD_Spam_Log::get_entries();
        $this->assertNotEmpty( $log );
        $last = end( $log );
        $this->assertContains( 'bayesian', $last['checks_triggered'] );
    }

    public function test_cyrillic_comment_routed_to_cyrillic_classifier(): void {
        $detector = $this->build_detector();
        $result   = $this->run_comment( $detector,
            'Купить дешевые таблетки онлайн скидка бесплатная доставка акция лучшая цена заказать сейчас'
        );

        $this->assertEquals( 'spam', $result );

        $log  = ANTISPAMWIZARD_Spam_Log::get_entries();
        $this->assertNotEmpty( $log );
        $last = end( $log );
        $this->assertContains( 'bayesian', $last['checks_triggered'] );
    }

    public function test_cjk_comment_routed_to_cjk_classifier(): void {
        $detector = $this->build_detector();
        $result   = $this->run_comment( $detector,
            '免费赚钱在线赌场优惠折扣立即购买最低价格快速致富免费送货全网最低'
        );

        $this->assertEquals( 'spam', $result );

        $log  = ANTISPAMWIZARD_Spam_Log::get_entries();
        $this->assertNotEmpty( $log );
        $last = end( $log );
        $this->assertContains( 'bayesian', $last['checks_triggered'] );
    }

    public function test_arabic_comment_routed_to_arabic_classifier(): void {
        $detector = $this->build_detector();
        $result   = $this->run_comment( $detector,
            'اشتري الآن عرض خاص خصم كبير أفضل سعر توصيل مجاني اطلب الآن ربح سريع أموال مجانية'
        );

        $this->assertEquals( 'spam', $result );

        $log  = ANTISPAMWIZARD_Spam_Log::get_entries();
        $this->assertNotEmpty( $log );
        $last = end( $log );
        $this->assertContains( 'bayesian', $last['checks_triggered'] );
    }

    public function test_devanagari_comment_routed_to_devanagari_classifier(): void {
        $detector = $this->build_detector();
        $result   = $this->run_comment( $detector,
            'अभी खरीदें सस्ता ऑनलाइन छूट मुफ्त डिलीवरी पैसे कमाएं तुरंत ऑर्डर करें बेस्ट डील'
        );

        $this->assertEquals( 'spam', $result );

        $log  = ANTISPAMWIZARD_Spam_Log::get_entries();
        $this->assertNotEmpty( $log );
        $last = end( $log );
        $this->assertContains( 'bayesian', $last['checks_triggered'] );
    }

    public function test_unknown_script_does_not_crash(): void {
        $detector = $this->build_detector();

        // Numbers and punctuation only - no letter characters, script = unknown.
        // The combined model should handle this via fallback. Comma-separated on
        // purpose: digit groups joined by spaces, dots, dashes or parentheses
        // are how phone numbers are written, so the tokenizer folds nine or more
        // such digits into its phone-number token — a letter token and a spam
        // signal — and this test is about the no-signal path.
        $result = $this->run_comment( $detector,
            '12345, 0000, 99999, 11111, 22222, 33333, 44444, 55555, 66666, 77777'
        );

        // Numbers-only text produces no letter tokens, so classifiers lack signal.
        // The comment should be approved (not flagged as spam) since there is
        // nothing to match against.
        $this->assertSame( 1, $result );
    }

    public function test_mixed_russian_english_routes_to_cyrillic(): void {
        $detector = $this->build_detector();

        // Russian text with some English tech terms - should route to Cyrillic
        // because Cyrillic characters dominate.
        $result = $this->run_comment( $detector,
            'Купить дешевые таблетки online скидка доставка бесплатно акция лучшая цена заказать'
        );

        $this->assertEquals( 'spam', $result );

        $log  = ANTISPAMWIZARD_Spam_Log::get_entries();
        $this->assertNotEmpty( $log );
        $last = end( $log );
        $this->assertContains( 'bayesian', $last['checks_triggered'] );
    }

    public function test_end_to_end_cyrillic_spam_detected_through_detector(): void {
        // Re-enable all checks for a full end-to-end test.
        ANTISPAMWIZARD_Settings::update( array(
            'stop_words_enabled'  => true,
            'links_enabled'       => true,
            'emoji_enabled'       => true,
            'spacing_enabled'     => true,
            'multi_lang_enabled'  => true,
            'duplicate_enabled'   => true,
            'similarity_enabled'  => true,
            'bayesian_enabled'    => true,
            'bayesian_threshold'  => 0.7,
            'min_message_length'  => 1,
        ) );

        $detector = $this->build_detector( enable_similarity: true );
        $result   = $this->run_comment( $detector,
            'Купить дешевые таблетки онлайн скидка бесплатная доставка акция лучшая цена заказать сейчас быстро'
        );

        $this->assertEquals( 'spam', $result );

        // Verify spam was logged.
        $log = ANTISPAMWIZARD_Spam_Log::get_entries();
        $this->assertNotEmpty( $log );

        // Verify stats were recorded.
        $stats = ANTISPAMWIZARD_Stats::get_all();
        $this->assertEquals( 1, $stats['total_blocked'] );
    }

    public function test_legitimate_latin_comment_not_flagged(): void {
        $detector = $this->build_detector();
        $result   = $this->run_comment( $detector,
            'This is a thoughtful and well-written article about software architecture and design patterns in modern applications'
        );

        $this->assertEquals( 1, $result );
    }

    public function test_legitimate_cyrillic_comment_not_flagged(): void {
        $detector = $this->build_detector();
        $result   = $this->run_comment( $detector,
            'Отличная статья о программировании и архитектуре приложений, очень полезно для начинающих разработчиков'
        );

        $this->assertEquals( 1, $result );
    }
}
