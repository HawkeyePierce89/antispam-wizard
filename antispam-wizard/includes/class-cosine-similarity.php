<?php
/**
 * Cosine similarity calculator for token arrays.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Computes cosine similarity between two token arrays using term frequency vectors.
 */
class ASW_Cosine_Similarity {

    /**
     * Compare two token arrays and return their cosine similarity.
     *
     * Builds term frequency vectors from the token arrays and computes
     * the cosine of the angle between them. Returns a float between 0.0
     * (completely different) and 1.0 (identical).
     *
     * @param array<int, string> $tokens_a First token array.
     * @param array<int, string> $tokens_b Second token array.
     * @return float Cosine similarity score between 0.0 and 1.0.
     */
    public function compare( array $tokens_a, array $tokens_b ): float {
        if ( empty( $tokens_a ) || empty( $tokens_b ) ) {
            return 0.0;
        }

        $tf_a = $this->build_tf_vector( $tokens_a );
        $tf_b = $this->build_tf_vector( $tokens_b );

        $dot_product  = 0.0;
        $magnitude_a  = 0.0;
        $magnitude_b  = 0.0;

        $all_terms = array_unique( array_merge( array_keys( $tf_a ), array_keys( $tf_b ) ) );

        foreach ( $all_terms as $term ) {
            $a = $tf_a[ $term ] ?? 0;
            $b = $tf_b[ $term ] ?? 0;

            $dot_product += $a * $b;
            $magnitude_a += $a * $a;
            $magnitude_b += $b * $b;
        }

        $magnitude = sqrt( $magnitude_a ) * sqrt( $magnitude_b );

        if ( $magnitude === 0.0 ) {
            return 0.0;
        }

        return $dot_product / $magnitude;
    }

    /**
     * Build a term frequency vector from a token array.
     *
     * @param array<int, string> $tokens Token array.
     * @return array<string, int> Map of token to frequency count.
     */
    private function build_tf_vector( array $tokens ): array {
        $tf = array();

        foreach ( $tokens as $token ) {
            if ( isset( $tf[ $token ] ) ) {
                ++$tf[ $token ];
            } else {
                $tf[ $token ] = 1;
            }
        }

        return $tf;
    }
}
