<?php

use PHPUnit\Framework\TestCase;

class NaiveBayesTest extends TestCase {

    private ANTISPAMWIZARD_Naive_Bayes $classifier;

    protected function setUp(): void {
        parent::setUp();
        $this->classifier = new ANTISPAMWIZARD_Naive_Bayes();
    }

    private function train_basic(): void {
        // Train with clear spam and ham samples.
        $spam_samples = array(
            'Buy cheap viagra pills now free discount offer',
            'Click here for free money casino bonus jackpot',
            'Earn thousands working from home guaranteed income',
            'Free pills discount pharmacy cheap medications online',
            'Winner lottery prize claim your free money today',
        );
        $ham_samples = array(
            'Great article about programming best practices',
            'Thanks for sharing this helpful tutorial code',
            'Really enjoyed reading about software development today',
            'Interesting thoughts on database optimization techniques',
            'Good explanation of algorithms and data structures',
        );

        foreach ( $spam_samples as $sample ) {
            $this->classifier->learn( $sample, 'spam' );
        }
        foreach ( $ham_samples as $sample ) {
            $this->classifier->learn( $sample, 'ham' );
        }
    }

    public function test_classify_spam(): void {
        $this->train_basic();

        $result = $this->classifier->classify( 'Buy free cheap pills discount offer' );

        $this->assertArrayHasKey( 'spam', $result );
        $this->assertArrayHasKey( 'ham', $result );
        $this->assertGreaterThan( $result['ham'], $result['spam'] );
    }

    public function test_classify_ham(): void {
        $this->train_basic();

        $result = $this->classifier->classify( 'Great article about programming and software development' );

        $this->assertArrayHasKey( 'spam', $result );
        $this->assertArrayHasKey( 'ham', $result );
        $this->assertGreaterThan( $result['spam'], $result['ham'] );
    }

    public function test_probabilities_sum_to_one(): void {
        $this->train_basic();

        $result = $this->classifier->classify( 'Some random text for testing classification' );
        $sum    = array_sum( $result );

        $this->assertEqualsWithDelta( 1.0, $sum, 0.0001 );
    }

    public function test_unlearn_method_removed(): void {
        $this->assertFalse(
            method_exists( ANTISPAMWIZARD_Naive_Bayes::class, 'unlearn' ),
            'Naive_Bayes::unlearn() should have been removed (dead code with orphaned-token bug)'
        );
    }

    public function test_reset_clears_state(): void {
        $this->train_basic();

        $state = $this->classifier->get_state();
        $this->assertNotEmpty( $state['doc_counts'] );

        $this->classifier->reset();

        $state = $this->classifier->get_state();
        $this->assertEmpty( $state['token_counts'] );
        $this->assertEmpty( $state['token_totals'] );
        $this->assertEmpty( $state['doc_counts'] );
    }

    public function test_classify_empty_text(): void {
        $this->train_basic();

        $result = $this->classifier->classify( '' );

        // With no tokens, should return equal probabilities.
        $this->assertEqualsWithDelta( $result['spam'], $result['ham'], 0.0001 );
    }

    public function test_classify_untrained(): void {
        $result = $this->classifier->classify( 'Some text' );
        $this->assertEmpty( $result );
    }

    public function test_classify_survives_a_trained_model_with_no_vocabulary(): void {
        // Documents but no tokens in any category: every training sample
        // tokenised to nothing, or a cached unit ANTISPAMWIZARD_Model_Store pruned. The
        // Laplace denominator (token_totals + vocabulary_size) is then 0, and
        // without the floor this is a DivisionByZeroError that
        // ANTISPAMWIZARD_Detector::run()'s fail-open catch swallows -- disabling the
        // Bayesian check for the cached unit's whole day while appending a
        // plugin-attributable line to the debug.log dev/smoke.sh scans on every
        // submission.
        $this->classifier->set_state( array(
            'token_counts' => array( 'spam' => array(), 'ham' => array() ),
            'token_totals' => array( 'spam' => 0, 'ham' => 0 ),
            'doc_counts'   => array( 'spam' => 3, 'ham' => 2 ),
        ) );

        // The state has to have been accepted, or this passes on the empty
        // early return instead of on the floor.
        $this->assertSame( array( 'spam' => 3, 'ham' => 2 ), $this->classifier->get_state()['doc_counts'] );

        $result = $this->classifier->classify( 'hello world unseen' );

        $this->assertEqualsWithDelta( 1.0, array_sum( $result ), 0.000001 );
        // Every token is equally unknown to both categories, so the priors are
        // all that is left.
        $this->assertEqualsWithDelta( 0.6, $result['spam'], 0.000001 );
        $this->assertEqualsWithDelta( 0.4, $result['ham'], 0.000001 );
    }

    public function test_tokenizer_excludes_short_tokens(): void {
        // Words "a" and "is" are <= 2 chars and should be excluded.
        $this->classifier->learn( 'a is big', 'test' );
        $state = $this->classifier->get_state();

        $this->assertArrayNotHasKey( 'a', $state['token_counts']['test'] ?? array() );
        $this->assertArrayNotHasKey( 'is', $state['token_counts']['test'] ?? array() );
        $this->assertArrayHasKey( 'big', $state['token_counts']['test'] );
    }

    public function test_tokenizer_excludes_stop_words(): void {
        // "the" and "for" are stop-words.
        $this->classifier->learn( 'the quick brown fox for jumping', 'test' );
        $state = $this->classifier->get_state();

        $this->assertArrayNotHasKey( 'the', $state['token_counts']['test'] ?? array() );
        $this->assertArrayNotHasKey( 'for', $state['token_counts']['test'] ?? array() );
        $this->assertArrayHasKey( 'quick', $state['token_counts']['test'] );
        $this->assertArrayHasKey( 'brown', $state['token_counts']['test'] );
    }

    public function test_tokenizer_handles_unicode(): void {
        $this->classifier->learn( 'программирование отличная статья', 'test' );
        $state = $this->classifier->get_state();

        $this->assertArrayHasKey( 'программирование', $state['token_counts']['test'] );
        $this->assertArrayHasKey( 'отличная', $state['token_counts']['test'] );
        $this->assertArrayHasKey( 'статья', $state['token_counts']['test'] );
    }

    public function test_tokenizer_handles_mixed_punctuation(): void {
        $this->classifier->learn( 'hello, world! great...stuff (here)', 'test' );
        $state = $this->classifier->get_state();

        $this->assertArrayHasKey( 'hello', $state['token_counts']['test'] );
        $this->assertArrayHasKey( 'world', $state['token_counts']['test'] );
        $this->assertArrayHasKey( 'great', $state['token_counts']['test'] );
        $this->assertArrayHasKey( 'stuff', $state['token_counts']['test'] );
        $this->assertArrayHasKey( 'here', $state['token_counts']['test'] );
    }

    public function test_tokenizer_lowercases(): void {
        $this->classifier->learn( 'HELLO World MiXeD', 'test' );
        $state = $this->classifier->get_state();

        $this->assertArrayHasKey( 'hello', $state['token_counts']['test'] );
        $this->assertArrayHasKey( 'world', $state['token_counts']['test'] );
        $this->assertArrayHasKey( 'mixed', $state['token_counts']['test'] );
    }

    public function test_get_and_set_state(): void {
        $this->train_basic();

        $state = $this->classifier->get_state();

        $new_classifier = new ANTISPAMWIZARD_Naive_Bayes();
        $new_classifier->set_state( $state );

        $text     = 'Buy cheap pills free offer';
        $original = $this->classifier->classify( $text );
        $restored = $new_classifier->classify( $text );

        $this->assertEqualsWithDelta( $original['spam'], $restored['spam'], 0.0001 );
        $this->assertEqualsWithDelta( $original['ham'], $restored['ham'], 0.0001 );
    }

    public function test_set_state_rejects_non_array_token_counts(): void {
        $this->train_basic();

        $this->classifier->set_state( array(
            'token_counts' => 'corrupted',
            'token_totals' => array( 'spam' => 10 ),
            'doc_counts'   => array( 'spam' => 1 ),
        ) );

        $state = $this->classifier->get_state();
        $this->assertEmpty( $state['token_counts'] );
        $this->assertEmpty( $state['token_totals'] );
        $this->assertEmpty( $state['doc_counts'] );
    }

    public function test_set_state_rejects_non_int_doc_counts(): void {
        $this->classifier->set_state( array(
            'token_counts' => array( 'spam' => array( 'buy' => 1 ) ),
            'token_totals' => array( 'spam' => 1 ),
            'doc_counts'   => array( 'spam' => 'not_an_int' ),
        ) );

        $state = $this->classifier->get_state();
        $this->assertEmpty( $state['doc_counts'] );
    }

    public function test_set_state_rejects_non_int_token_totals(): void {
        $this->classifier->set_state( array(
            'token_counts' => array( 'spam' => array( 'buy' => 1 ) ),
            'token_totals' => array( 'spam' => 'bad' ),
            'doc_counts'   => array( 'spam' => 1 ),
        ) );

        $state = $this->classifier->get_state();
        $this->assertEmpty( $state['token_totals'] );
    }

    public function test_set_state_rejects_non_array_inner_token_counts(): void {
        $this->classifier->set_state( array(
            'token_counts' => array( 'spam' => 'not_an_array' ),
            'token_totals' => array( 'spam' => 1 ),
            'doc_counts'   => array( 'spam' => 1 ),
        ) );

        $state = $this->classifier->get_state();
        $this->assertEmpty( $state['token_counts'] );
    }

    public function test_set_state_rejects_non_int_inner_token_count_values(): void {
        $this->classifier->set_state( array(
            'token_counts' => array( 'spam' => array( 'buy' => 'not_an_int' ) ),
            'token_totals' => array( 'spam' => 1 ),
            'doc_counts'   => array( 'spam' => 1 ),
        ) );

        $state = $this->classifier->get_state();
        $this->assertEmpty( $state['token_counts'] );
        $this->assertEmpty( $state['token_totals'] );
        $this->assertEmpty( $state['doc_counts'] );
    }

    public function test_set_state_accepts_valid_state(): void {
        $valid_state = array(
            'token_counts' => array( 'spam' => array( 'buy' => 3, 'cheap' => 2 ) ),
            'token_totals' => array( 'spam' => 5 ),
            'doc_counts'   => array( 'spam' => 2 ),
        );

        $this->classifier->set_state( $valid_state );

        $state = $this->classifier->get_state();
        $this->assertSame( $valid_state['token_counts'], $state['token_counts'] );
        $this->assertSame( $valid_state['token_totals'], $state['token_totals'] );
        $this->assertSame( $valid_state['doc_counts'], $state['doc_counts'] );
    }

    public function test_set_state_rejects_missing_fields(): void {
        $this->classifier->set_state( array(
            'token_counts' => array( 'spam' => array( 'buy' => 1 ) ),
            // token_totals and doc_counts missing — null fallback triggers rejection.
        ) );

        $state = $this->classifier->get_state();
        $this->assertEmpty( $state['token_counts'] );
    }

    public function test_learn_caps_dictionary_size_per_category(): void {
        // Use reflection to temporarily lower the cap for testing.
        $ref = new \ReflectionClass( ANTISPAMWIZARD_Naive_Bayes::class );

        // We can't change a const, so we test by learning enough unique tokens
        // to hit the cap. Instead, let's verify the cap mechanism by learning
        // MAX_DICTIONARY_SIZE unique tokens and then one more.
        // For a practical test, create a classifier and learn tokens up to the cap.

        // Generate unique tokens up to the cap + extra.
        $cap   = ANTISPAMWIZARD_Naive_Bayes::MAX_DICTIONARY_SIZE;
        $batch = $cap + 100;

        // Build a huge text with unique tokens.
        $tokens_arr = array();
        for ( $i = 0; $i < $batch; $i++ ) {
            $tokens_arr[] = 'uniquetoken' . $i;
        }

        // Learn in batches to avoid a single massive string.
        $chunk_size = 500;
        for ( $offset = 0; $offset < $batch; $offset += $chunk_size ) {
            $chunk = array_slice( $tokens_arr, $offset, $chunk_size );
            $this->classifier->learn( implode( ' ', $chunk ), 'spam' );
        }

        $state = $this->classifier->get_state();
        $dict  = $state['token_counts']['spam'];

        // Dictionary should be capped at MAX_DICTIONARY_SIZE.
        $this->assertLessThanOrEqual( $cap, count( $dict ) );
        $this->assertGreaterThanOrEqual( $cap, count( $dict ), 'Dictionary should reach the cap' );
    }

    public function test_learn_existing_tokens_still_counted_after_cap(): void {
        // Learn enough unique tokens to fill the dictionary.
        $cap        = ANTISPAMWIZARD_Naive_Bayes::MAX_DICTIONARY_SIZE;
        $tokens_arr = array();
        for ( $i = 0; $i < $cap; $i++ ) {
            $tokens_arr[] = 'token' . $i;
        }

        $chunk_size = 500;
        for ( $offset = 0; $offset < $cap; $offset += $chunk_size ) {
            $chunk = array_slice( $tokens_arr, $offset, $chunk_size );
            $this->classifier->learn( implode( ' ', $chunk ), 'spam' );
        }

        // Now learn text that includes an existing token and a new one.
        $this->classifier->learn( 'token0 brandnewtoken', 'spam' );

        $state = $this->classifier->get_state();
        // Existing token should have count incremented.
        $this->assertSame( 2, $state['token_counts']['spam']['token0'] );
        // New token should NOT be added (cap reached).
        $this->assertArrayNotHasKey( 'brandnewtoken', $state['token_counts']['spam'] );
    }

    public function test_multiple_categories(): void {
        $this->classifier->learn( 'programming code software developer', 'tech' );
        $this->classifier->learn( 'cooking recipe kitchen food', 'food' );
        $this->classifier->learn( 'running marathon exercise fitness', 'sports' );

        $result = $this->classifier->classify( 'programming software code' );

        $this->assertCount( 3, $result );
        $this->assertGreaterThan( $result['food'], $result['tech'] );
        $this->assertGreaterThan( $result['sports'], $result['tech'] );
    }

    public function test_set_state_rejects_inconsistent_category_keys(): void {
        $this->classifier->learn( 'hello world', 'spam' );

        // Category exists in doc_counts but not in token_counts.
        $state = array(
            'token_counts' => array(),
            'token_totals' => array( 'spam' => 5 ),
            'doc_counts'   => array( 'spam' => 1 ),
        );
        $this->classifier->set_state( $state );
        $exported = $this->classifier->get_state();
        $this->assertEmpty( $exported['doc_counts'] );

        // Category exists in doc_counts but not in token_totals.
        $state2 = array(
            'token_counts' => array( 'spam' => array( 'hello' => 1 ) ),
            'token_totals' => array(),
            'doc_counts'   => array( 'spam' => 1 ),
        );
        $this->classifier->set_state( $state2 );
        $exported2 = $this->classifier->get_state();
        $this->assertEmpty( $exported2['doc_counts'] );

        // Orphan category in token_counts not present in doc_counts.
        $state3 = array(
            'token_counts' => array(
                'spam' => array( 'hello' => 1 ),
                'orphan' => array( 'world' => 1 ),
            ),
            'token_totals' => array( 'spam' => 1 ),
            'doc_counts'   => array( 'spam' => 1 ),
        );
        $this->classifier->set_state( $state3 );
        $exported3 = $this->classifier->get_state();
        $this->assertEmpty( $exported3['doc_counts'] );

        // Orphan category in token_totals not present in doc_counts.
        $state4 = array(
            'token_counts' => array( 'spam' => array( 'hello' => 1 ) ),
            'token_totals' => array( 'spam' => 1, 'orphan' => 5 ),
            'doc_counts'   => array( 'spam' => 1 ),
        );
        $this->classifier->set_state( $state4 );
        $exported4 = $this->classifier->get_state();
        $this->assertEmpty( $exported4['doc_counts'] );
    }

    public function test_russian_stop_words_excluded(): void {
        // "что", "как", "это" are Russian stop-words.
        $this->classifier->learn( 'что это как результат работы программы', 'test' );
        $state = $this->classifier->get_state();

        $this->assertArrayNotHasKey( 'что', $state['token_counts']['test'] ?? array() );
        $this->assertArrayNotHasKey( 'как', $state['token_counts']['test'] ?? array() );
        $this->assertArrayNotHasKey( 'это', $state['token_counts']['test'] ?? array() );
        $this->assertArrayHasKey( 'результат', $state['token_counts']['test'] );
    }
}
