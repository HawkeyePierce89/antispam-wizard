<?php
/**
 * Similarity spam check using cosine similarity against known spam samples.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Checks comment text by comparing cosine similarity against known spam samples.
 *
 * Supports per-script routing: when constructed with script-keyed sample sets,
 * the check detects the dominant script of the comment and compares against
 * the matching script's samples. Falls back to combined samples for unknown scripts.
 */
class ASW_Similarity_Check implements ASW_Check {

    /**
     * Scripts that encode more meaning per character and need a lower
     * minimum message length threshold.
     */
    private const DENSE_SCRIPTS = array( 'cjk', 'thai' );

    /**
     * Minimum message length for dense scripts (CJK, Thai).
     */
    private const DENSE_SCRIPT_MIN_LENGTH = 10;

    /**
     * @var ASW_Tokenizer
     */
    private ASW_Tokenizer $tokenizer;

    /**
     * @var ASW_Cosine_Similarity
     */
    private ASW_Cosine_Similarity $cosine;

    /**
     * Pre-tokenized spam samples for the combined/legacy fallback.
     *
     * @var array<int, array<int, string>>
     */
    private array $spam_sample_tokens;

    /**
     * Per-script pre-tokenized spam samples keyed by script group.
     *
     * @var array<string, array<int, array<int, string>>>
     */
    private array $script_sample_tokens;

    /**
     * Lazy per-writing-system model loader, or null in the array forms.
     *
     * @var ASW_Model_Store|null
     */
    private ?ASW_Model_Store $store = null;

    /**
     * Construct the Similarity check.
     *
     * Accepts either:
     * - Legacy: new Similarity_Check( $tokenizer, $cosine, $spam_samples )
     * - Script-aware: new Similarity_Check( $tokenizer, $cosine, $spam_samples,
     *       $script_samples, $combined_samples )
     * - Store-backed: new Similarity_Check( $tokenizer, $cosine, $model_store )
     *
     * The store form is what the plugin ships: it applies the same branch, but
     * the combined sample corpus is loaded only when the detected script turns
     * out to have no samples of its own.
     *
     * @param ASW_Tokenizer        $tokenizer        Tokenizer instance.
     * @param ASW_Cosine_Similarity $cosine           Cosine similarity calculator.
     * @param array<int, string>|ASW_Model_Store $spam_samples
     *     Legacy spam text samples (used as combined fallback), or a model store.
     * @param array<string, array<int, array<int, string>>>|null $script_samples
     *     Per-script pre-tokenized sample arrays keyed by script group.
     * @param array<int, array<int, string>>|null $combined_samples
     *     Pre-tokenized combined fallback samples. If provided, overrides legacy tokenization.
     */
    public function __construct(
        ASW_Tokenizer $tokenizer,
        ASW_Cosine_Similarity $cosine,
        array|ASW_Model_Store $spam_samples,
        ?array $script_samples = null,
        ?array $combined_samples = null
    ) {
        $this->tokenizer            = $tokenizer;
        $this->cosine               = $cosine;
        $this->script_sample_tokens = $script_samples ?? array();

        if ( $spam_samples instanceof ASW_Model_Store ) {
            $this->store              = $spam_samples;
            $this->spam_sample_tokens = array();
        } elseif ( null !== $combined_samples ) {
            $this->spam_sample_tokens = $combined_samples;
        } else {
            $this->spam_sample_tokens = array();
            foreach ( $spam_samples as $sample ) {
                $tokens = $tokenizer->tokenize( $sample );
                if ( ! empty( $tokens ) ) {
                    $this->spam_sample_tokens[] = $tokens;
                }
            }
        }
    }

    /**
     * Run the similarity check against comment data.
     *
     * @param array $comment_data WordPress comment data array.
     * @return ASW_Check_Result
     */
    public function check( array $comment_data ): ASW_Check_Result {
        $settings = ASW_Settings::get_all();

        if ( empty( $settings['similarity_enabled'] ) ) {
            return new ASW_Check_Result( false, 'similarity', __( 'check disabled', 'antispam-wizard' ) );
        }

        $text = isset( $comment_data['comment_content'] ) ? $comment_data['comment_content'] : '';
        if ( '' === $text ) {
            return new ASW_Check_Result( false, 'similarity', __( 'empty comment', 'antispam-wizard' ) );
        }

        $min_length = isset( $settings['min_message_length'] ) ? (int) $settings['min_message_length'] : 50;
        // In store mode the script is detected once per request and shared with
        // the Bayesian check, which evaluates the same body.
        $detected_script = null !== $this->store
            ? $this->store->script_for( $text )
            : ASW_Script_Detector::detect( $text );
        if ( in_array( $detected_script, self::DENSE_SCRIPTS, true ) ) {
            $min_length = min( $min_length, self::DENSE_SCRIPT_MIN_LENGTH );
        }
        if ( mb_strlen( $text, 'UTF-8' ) < $min_length ) {
            return new ASW_Check_Result( false, 'similarity', __( 'comment too short for similarity', 'antispam-wizard' ) );
        }

        $threshold = isset( $settings['similarity_threshold'] ) ? (float) $settings['similarity_threshold'] : 0.6;

        if ( null !== $this->store ) {
            list( $sample_tokens, $script ) = $this->resolve_from_store( $detected_script );
        } else {
            $script        = empty( $this->script_sample_tokens ) ? null : $detected_script;
            $sample_tokens = $this->resolve_samples( $script );
        }

        $comment_tokens = $this->tokenize_for_comment( $text, $script );

        if ( empty( $comment_tokens ) ) {
            return new ASW_Check_Result( false, 'similarity', sprintf( 'similarity 0.00/%.2f', $threshold ) );
        }

        $max_score = 0.0;

        foreach ( $sample_tokens as $s_tokens ) {
            $score = $this->cosine->compare( $comment_tokens, $s_tokens );
            if ( $score > $max_score ) {
                $max_score = $score;
                if ( $max_score > $threshold ) {
                    break;
                }
            }
        }

        $details = sprintf( 'similarity %.2f/%.2f', $max_score, $threshold );

        if ( $max_score > $threshold ) {
            return new ASW_Check_Result( true, 'similarity', $details );
        }

        return new ASW_Check_Result( false, 'similarity', $details );
    }

    /**
     * Resolve the sample corpus and tokenisation mode from the model store.
     *
     * Reproduces the array form's branch exactly. The detected script's own
     * token list is used whenever it is non-empty -- which is the same test
     * build_checks() applied, since it only recorded a script whose list came
     * out non-empty. Otherwise the combined unit answers, and whether any
     * script had samples at all is what decides between script-aware and legacy
     * word tokenisation; the combined unit is the only place that answer lives,
     * and it is also the only case in which the unit is loaded.
     *
     * @param string $detected_script The script group detected for this body.
     * @return array{0: array<int, array<int, string>>, 1: string|null}
     *     The sample token lists, and the script to tokenize the comment for
     *     (null for legacy mode).
     */
    private function resolve_from_store( string $detected_script ): array {
        $script_tokens = $this->store->script_sample_tokens( $detected_script );

        if ( ! empty( $script_tokens ) ) {
            return array( $script_tokens, $detected_script );
        }

        return array(
            $this->store->combined_sample_tokens(),
            $this->store->combined_has_script_samples() ? $detected_script : null,
        );
    }

    /**
     * Select the appropriate pre-tokenized sample set for the detected script.
     *
     * @param string|null $script The detected script group, or null for legacy mode.
     * @return array<int, array<int, string>> Pre-tokenized samples.
     */
    private function resolve_samples( ?string $script ): array {
        if ( null === $script ) {
            return $this->spam_sample_tokens;
        }

        if ( 'unknown' !== $script && isset( $this->script_sample_tokens[ $script ] ) ) {
            return $this->script_sample_tokens[ $script ];
        }

        return $this->spam_sample_tokens;
    }

    /**
     * Tokenize comment text using the appropriate strategy for its script.
     *
     * @param string      $text   The comment text.
     * @param string|null $script The detected script group, or null for legacy mode.
     * @return array<int, string> Tokens.
     */
    private function tokenize_for_comment( string $text, ?string $script ): array {
        if ( null === $script ) {
            return $this->tokenizer->tokenize( $text );
        }

        return $this->tokenizer->tokenize_for_script( $text, $script );
    }
}
