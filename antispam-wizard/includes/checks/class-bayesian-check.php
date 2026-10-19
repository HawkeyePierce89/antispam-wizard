<?php
/**
 * Bayesian spam check using Naive Bayes classifier.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Checks comment text using a trained Naive Bayes classifier.
 *
 * Supports per-script routing: when constructed with script-keyed classifiers,
 * the check detects the dominant script of the comment and routes to the
 * matching classifier. Falls back to a combined classifier for unknown scripts.
 */
class ASW_Bayesian_Check implements ASW_Check {

    /**
     * Per-script classifiers keyed by script group name.
     *
     * @var array<string, ASW_Naive_Bayes>
     */
    private array $classifiers;

    /**
     * Combined (fallback) classifier trained on all scripts.
     *
     * Null in store mode only: the combined unit is the most expensive one to
     * load, so it is resolved from the store on the request that needs it
     * rather than constructed up front.
     *
     * @var ASW_Naive_Bayes|null
     */
    private ?ASW_Naive_Bayes $combined_classifier;

    /**
     * Lazy per-writing-system model loader, or null in the array/single forms.
     *
     * @var ASW_Model_Store|null
     */
    private ?ASW_Model_Store $store = null;

    /**
     * Minimum number of training samples required per category before the
     * Bayesian check will run. Below this threshold the classifier is
     * considered undertrained and the check returns not-spam.
     */
    const MIN_TRAINING_SAMPLES = 10;

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
     * Construct the Bayesian check.
     *
     * Accepts either:
     * - Legacy single classifier: new Bayesian_Check( $classifier )
     * - Script-aware: new Bayesian_Check( $script_classifiers, $combined_classifier )
     * - Store-backed: new Bayesian_Check( $model_store )
     *
     * The store form is what the plugin ships: it resolves the same two
     * classifiers by the same rules, but loads only the writing system the
     * submission actually turns out to be in.
     *
     * @param ASW_Naive_Bayes|array<string, ASW_Naive_Bayes>|ASW_Model_Store $classifiers
     *     Single classifier (legacy), array of per-script classifiers, or a model store.
     * @param ASW_Naive_Bayes|null $combined_classifier
     *     Combined fallback classifier (required when $classifiers is an array).
     */
    public function __construct(
        ASW_Naive_Bayes|array|ASW_Model_Store $classifiers,
        ?ASW_Naive_Bayes $combined_classifier = null
    ) {
        if ( $classifiers instanceof ASW_Model_Store ) {
            $this->store               = $classifiers;
            $this->classifiers         = array();
            $this->combined_classifier = null;
        } elseif ( $classifiers instanceof ASW_Naive_Bayes ) {
            // Legacy single-classifier mode.
            $this->classifiers       = array();
            $this->combined_classifier = $classifiers;
        } else {
            $this->classifiers       = $classifiers;
            $this->combined_classifier = $combined_classifier ?? new ASW_Naive_Bayes();
        }
    }

    /**
     * Run the Bayesian check against comment data.
     *
     * @param array $comment_data WordPress comment data array.
     * @return ASW_Check_Result
     */
    public function check( array $comment_data ): ASW_Check_Result {
        $settings = ASW_Settings::get_all();

        if ( empty( $settings['bayesian_enabled'] ) ) {
            return new ASW_Check_Result( false, 'bayesian', __( 'check disabled', 'antispam-wizard' ) );
        }

        $text = isset( $comment_data['comment_content'] ) ? $comment_data['comment_content'] : '';
        if ( '' === $text ) {
            return new ASW_Check_Result( false, 'bayesian', __( 'empty comment', 'antispam-wizard' ) );
        }

        $min_length = isset( $settings['min_message_length'] ) ? (int) $settings['min_message_length'] : 50;
        // In store mode the script is detected once per request and shared with
        // the Similarity check, which evaluates the same body.
        $script = null !== $this->store
            ? $this->store->script_for( $text )
            : ASW_Script_Detector::detect( $text );
        if ( in_array( $script, self::DENSE_SCRIPTS, true ) ) {
            $min_length = min( $min_length, self::DENSE_SCRIPT_MIN_LENGTH );
        }
        if ( mb_strlen( $text, 'UTF-8' ) < $min_length ) {
            return new ASW_Check_Result( false, 'bayesian', __( 'comment too short for bayesian', 'antispam-wizard' ) );
        }

        $classifier = $this->resolve_classifier_for_script( $script );

        if ( ! $this->is_sufficiently_trained( $classifier ) ) {
            return new ASW_Check_Result( false, 'bayesian', __( 'insufficient training data', 'antispam-wizard' ) );
        }

        $threshold     = isset( $settings['bayesian_threshold'] ) ? (float) $settings['bayesian_threshold'] : 0.7;
        $probabilities = $classifier->classify( $text );
        $spam_prob     = isset( $probabilities['spam'] ) ? $probabilities['spam'] : 0.0;

        $details = sprintf( 'spam probability %.2f/%.2f', $spam_prob, $threshold );

        if ( $spam_prob > $threshold ) {
            return new ASW_Check_Result( true, 'bayesian', $details );
        }

        return new ASW_Check_Result( false, 'bayesian', $details );
    }

    /**
     * Select the appropriate classifier for the given script.
     *
     * Returns the matching per-script classifier, or the combined fallback
     * if no script-specific one exists.
     *
     * The declared return type no longer enforces itself: $combined_classifier
     * is nullable so store mode can leave it unset, and it is null in exactly
     * that mode. The store branch below must therefore keep returning
     * unconditionally -- the two `return $this->combined_classifier` statements
     * after it are reachable only when $store is null, which is precisely when
     * the constructor guaranteed a real classifier.
     *
     * @param string $script The detected script group.
     * @return ASW_Naive_Bayes
     */
    private function resolve_classifier_for_script( string $script ): ASW_Naive_Bayes {
        if ( null !== $this->store ) {
            // No 'unknown' special case: ASW_Model_Store::script_unit() already
            // answers null for anything that is not a SCRIPT_GROUPS key, and
            // ASW_Similarity_Check::resolve_from_store() relies on the same
            // rule -- duplicating it here would make the two store paths
            // disagree about who owns it.
            $classifier = $this->store->script_classifier( $script );
            if ( null !== $classifier && $this->is_sufficiently_trained( $classifier ) ) {
                return $classifier;
            }

            // Loading the combined unit is the only thing that reads every
            // script's corpus, so it happens here and nowhere earlier.
            return $this->store->combined_classifier();
        }

        if ( empty( $this->classifiers ) ) {
            return $this->combined_classifier;
        }

        if ( 'unknown' !== $script && isset( $this->classifiers[ $script ] ) ) {
            // Only use the per-script classifier if it has sufficient training data;
            // otherwise fall back to the combined model which may still catch spam.
            if ( $this->is_sufficiently_trained( $this->classifiers[ $script ] ) ) {
                return $this->classifiers[ $script ];
            }
        }

        return $this->combined_classifier;
    }

    /**
     * Whether a classifier has MIN_TRAINING_SAMPLES documents in both categories.
     *
     * @param ASW_Naive_Bayes $classifier The classifier to inspect.
     * @return bool
     */
    private function is_sufficiently_trained( ASW_Naive_Bayes $classifier ): bool {
        $state      = $classifier->get_state();
        $doc_counts = $state['doc_counts'];
        $spam_count = $doc_counts['spam'] ?? 0;
        $ham_count  = $doc_counts['ham'] ?? 0;

        return $spam_count >= self::MIN_TRAINING_SAMPLES && $ham_count >= self::MIN_TRAINING_SAMPLES;
    }
}
