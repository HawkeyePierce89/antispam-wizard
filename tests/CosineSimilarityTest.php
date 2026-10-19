<?php

use PHPUnit\Framework\TestCase;

class CosineSimilarityTest extends TestCase {

    private ASW_Cosine_Similarity $similarity;

    protected function setUp(): void {
        parent::setUp();
        $this->similarity = new ASW_Cosine_Similarity();
    }

    public function test_identical_texts_return_one(): void {
        $tokens = array( 'hello', 'world', 'great', 'test' );

        $score = $this->similarity->compare( $tokens, $tokens );

        $this->assertEqualsWithDelta( 1.0, $score, 0.0001 );
    }

    public function test_completely_different_texts_return_zero(): void {
        $tokens_a = array( 'hello', 'world' );
        $tokens_b = array( 'foo', 'bar' );

        $score = $this->similarity->compare( $tokens_a, $tokens_b );

        $this->assertEqualsWithDelta( 0.0, $score, 0.0001 );
    }

    public function test_partially_overlapping_texts_return_between_zero_and_one(): void {
        $tokens_a = array( 'hello', 'world', 'great' );
        $tokens_b = array( 'hello', 'world', 'different' );

        $score = $this->similarity->compare( $tokens_a, $tokens_b );

        $this->assertGreaterThan( 0.0, $score );
        $this->assertLessThan( 1.0, $score );
        // Expected: dot=2, |a|=sqrt(3), |b|=sqrt(3) → 2/3 ≈ 0.6667
        $this->assertEqualsWithDelta( 2.0 / 3.0, $score, 0.0001 );
    }

    public function test_empty_first_array_returns_zero(): void {
        $score = $this->similarity->compare( array(), array( 'hello', 'world' ) );

        $this->assertEqualsWithDelta( 0.0, $score, 0.0001 );
    }

    public function test_empty_second_array_returns_zero(): void {
        $score = $this->similarity->compare( array( 'hello', 'world' ), array() );

        $this->assertEqualsWithDelta( 0.0, $score, 0.0001 );
    }

    public function test_both_empty_arrays_return_zero(): void {
        $score = $this->similarity->compare( array(), array() );

        $this->assertEqualsWithDelta( 0.0, $score, 0.0001 );
    }

    public function test_repeated_tokens_affect_score(): void {
        $tokens_a = array( 'spam', 'spam', 'spam', 'buy' );
        $tokens_b = array( 'spam', 'buy', 'now', 'cheap' );

        $score_high = $this->similarity->compare( $tokens_a, $tokens_b );

        $tokens_c = array( 'hello', 'world', 'nice', 'day' );

        $score_low = $this->similarity->compare( $tokens_c, $tokens_b );

        $this->assertGreaterThan( $score_low, $score_high );
    }

    public function test_order_does_not_matter(): void {
        $tokens_a = array( 'hello', 'world', 'great' );
        $tokens_b = array( 'great', 'hello', 'world' );

        $score = $this->similarity->compare( $tokens_a, $tokens_b );

        $this->assertEqualsWithDelta( 1.0, $score, 0.0001 );
    }

    public function test_single_token_match(): void {
        $tokens_a = array( 'hello' );
        $tokens_b = array( 'hello' );

        $score = $this->similarity->compare( $tokens_a, $tokens_b );

        $this->assertEqualsWithDelta( 1.0, $score, 0.0001 );
    }

    public function test_single_token_no_match(): void {
        $tokens_a = array( 'hello' );
        $tokens_b = array( 'world' );

        $score = $this->similarity->compare( $tokens_a, $tokens_b );

        $this->assertEqualsWithDelta( 0.0, $score, 0.0001 );
    }
}
