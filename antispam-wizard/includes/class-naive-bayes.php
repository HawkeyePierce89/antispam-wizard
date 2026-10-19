<?php
/**
 * Naive Bayes classifier for spam detection.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Standalone Naive Bayes text classifier with no WordPress dependencies.
 */
class ASW_Naive_Bayes {

    /**
     * Maximum unique tokens per category.
     * Once reached, new tokens are ignored (existing tokens still accumulate counts).
     */
    const MAX_DICTIONARY_SIZE = 50000;

    /**
     * Token counts per category.
     * Structure: [ 'category' => [ 'token' => count, ... ], ... ]
     *
     * @var array<string, array<string, int>>
     */
    private array $token_counts = array();

    /**
     * Total tokens per category.
     *
     * @var array<string, int>
     */
    private array $token_totals = array();

    /**
     * Document counts per category.
     *
     * @var array<string, int>
     */
    private array $doc_counts = array();

    /**
     * Tokenizer instance.
     *
     * @var ASW_Tokenizer
     */
    private ASW_Tokenizer $tokenizer;

    /**
     * Script group for script-aware tokenization.
     *
     * When set, the classifier uses `tokenize_for_script()` instead of
     * `tokenize()`, enabling n-gram tokenization for CJK/Thai scripts.
     *
     * @var string|null
     */
    private ?string $script;

    /**
     * Constructor.
     *
     * @param ASW_Tokenizer|null $tokenizer Optional tokenizer instance.
     * @param string|null                   $script    Optional script group for script-aware tokenization.
     */
    public function __construct( ?ASW_Tokenizer $tokenizer = null, ?string $script = null ) {
        $this->tokenizer = $tokenizer ?? new ASW_Tokenizer();
        $this->script    = $script;
    }

    /**
     * Learn from a text sample, associating it with a category.
     *
     * @param string $text     The text to learn from.
     * @param string $category The category (e.g. 'spam' or 'ham').
     */
    public function learn( string $text, string $category ): void {
        $tokens = $this->tokenize( $text );

        if ( ! isset( $this->doc_counts[ $category ] ) ) {
            $this->doc_counts[ $category ]  = 0;
            $this->token_counts[ $category ] = array();
            $this->token_totals[ $category ] = 0;
        }

        $this->doc_counts[ $category ]++;

        $dict_size = count( $this->token_counts[ $category ] );
        foreach ( $tokens as $token ) {
            if ( ! isset( $this->token_counts[ $category ][ $token ] ) ) {
                if ( $dict_size >= self::MAX_DICTIONARY_SIZE ) {
                    continue;
                }
                $this->token_counts[ $category ][ $token ] = 0;
                $dict_size++;
            }
            $this->token_counts[ $category ][ $token ]++;
            $this->token_totals[ $category ]++;
        }
    }

    /**
     * Classify text and return probabilities for each known category.
     *
     * @param string $text The text to classify.
     * @return array<string, float> Associative array of category => probability.
     */
    public function classify( string $text ): array {
        $tokens      = $this->tokenize( $text );
        $total_docs  = array_sum( $this->doc_counts );
        $categories  = array_keys( $this->doc_counts );

        if ( 0 === $total_docs || empty( $categories ) || empty( $tokens ) ) {
            $result = array();
            foreach ( $categories as $cat ) {
                $result[ $cat ] = 0 === count( $categories ) ? 0.0 : 1.0 / count( $categories );
            }
            return $result;
        }

        $vocab_size  = $this->get_vocabulary_size();
        $log_scores  = array();

        foreach ( $categories as $cat ) {
            // Prior probability (log).
            $log_scores[ $cat ] = log( $this->doc_counts[ $cat ] / $total_docs );

            // Token likelihoods with Laplace smoothing. The denominator is
            // floored at 1: a state whose categories carry no tokens at all --
            // every sample tokenised to nothing, or a cached unit pruned to
            // fit -- would otherwise be a DivisionByZeroError here, which
            // ASW_Detector::run()'s fail-open catch swallows while disabling
            // the check and writing to the debug.log the harnesses scan.
            $denominator = max( 1, $this->token_totals[ $cat ] + $vocab_size );
            foreach ( $tokens as $token ) {
                $token_count = $this->token_counts[ $cat ][ $token ] ?? 0;
                $log_scores[ $cat ] += log( ( $token_count + 1 ) / $denominator );
            }
        }

        // Convert log scores to probabilities using log-sum-exp for numerical stability.
        $max_log = max( $log_scores );
        $sum_exp = 0.0;

        foreach ( $log_scores as $score ) {
            $sum_exp += exp( $score - $max_log );
        }

        $probabilities = array();
        foreach ( $log_scores as $cat => $score ) {
            $probabilities[ $cat ] = exp( $score - $max_log ) / $sum_exp;
        }

        return $probabilities;
    }

    /**
     * Clear all learned data.
     */
    public function reset(): void {
        $this->token_counts = array();
        $this->token_totals = array();
        $this->doc_counts   = array();
    }

    /**
     * Get the total vocabulary size across all categories.
     *
     * @return int Number of unique tokens.
     */
    private function get_vocabulary_size(): int {
        $vocab = array();
        foreach ( $this->token_counts as $tokens ) {
            foreach ( array_keys( $tokens ) as $token ) {
                $vocab[ $token ] = true;
            }
        }
        return count( $vocab );
    }

    /**
     * Get internal state for serialization.
     *
     * @return array{ token_counts: array, token_totals: array, doc_counts: array }
     */
    public function get_state(): array {
        return array(
            'token_counts' => $this->token_counts,
            'token_totals' => $this->token_totals,
            'doc_counts'   => $this->doc_counts,
        );
    }

    /**
     * Restore internal state from serialized data.
     *
     * @param array $state Previously exported state.
     */
    public function set_state( array $state ): void {
        $token_counts = $state['token_counts'] ?? null;
        $token_totals = $state['token_totals'] ?? null;
        $doc_counts   = $state['doc_counts'] ?? null;

        // Reject corrupted state — all three fields must be arrays.
        if ( ! is_array( $token_counts ) || ! is_array( $token_totals ) || ! is_array( $doc_counts ) ) {
            $this->reset();
            return;
        }

        // Validate inner types: token_totals and doc_counts values must be ints,
        // token_counts values must be arrays of ints.
        foreach ( $doc_counts as $v ) {
            if ( ! is_int( $v ) ) {
                $this->reset();
                return;
            }
        }
        foreach ( $token_totals as $v ) {
            if ( ! is_int( $v ) ) {
                $this->reset();
                return;
            }
        }
        foreach ( $token_counts as $cat => $tokens ) {
            if ( ! is_array( $tokens ) ) {
                $this->reset();
                return;
            }
            foreach ( $tokens as $v ) {
                if ( ! is_int( $v ) ) {
                    $this->reset();
                    return;
                }
            }
        }

        // Verify all three arrays share the exact same category keys.
        $dc_keys = array_keys( $doc_counts );
        $tc_keys = array_keys( $token_counts );
        $tt_keys = array_keys( $token_totals );
        sort( $dc_keys );
        sort( $tc_keys );
        sort( $tt_keys );
        if ( $dc_keys !== $tc_keys || $dc_keys !== $tt_keys ) {
            $this->reset();
            return;
        }

        $this->token_counts = $token_counts;
        $this->token_totals = $token_totals;
        $this->doc_counts   = $doc_counts;
    }

    /**
     * Tokenize text into an array of normalized tokens.
     *
     * When a script is configured, delegates to `tokenize_for_script()`
     * which uses n-gram tokenization for CJK/Thai scripts.
     *
     * When no script is configured (combined/legacy mode), auto-detects the
     * script so that CJK/Thai text gets n-gram tokenization instead of the
     * generic word tokenizer which cannot split spaceless scripts.
     *
     * @param string $text The text to tokenize.
     * @return array<int, string> Array of tokens.
     */
    private function tokenize( string $text ): array {
        if ( null !== $this->script ) {
            return $this->tokenizer->tokenize_for_script( $text, $this->script );
        }

        // Auto-detect script for combined/legacy classifiers so that CJK/Thai
        // samples are tokenized with n-grams instead of the word tokenizer.
        $detected = ASW_Script_Detector::detect( $text );

        return $this->tokenizer->tokenize_for_script( $text, $detected );
    }
}
