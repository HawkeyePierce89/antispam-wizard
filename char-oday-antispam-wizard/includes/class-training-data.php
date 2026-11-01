<?php
/**
 * Training data loader for the Naive Bayes classifier.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Loads and manages training data from text files for the Naive Bayes classifier.
 *
 * When a Sample_Repository is provided, DB-stored user samples are merged with
 * the seed corpus loaded from data/*.txt files.
 */
class ANTISPAMWIZARD_Training_Data {

    /** @var ANTISPAMWIZARD_Sample_Repository|null */
    private $repository;

    /**
     * @param ANTISPAMWIZARD_Sample_Repository|null $repository Optional DB repository for user-added samples.
     */
    public function __construct( ?ANTISPAMWIZARD_Sample_Repository $repository = null ) {
        $this->repository = $repository;
    }

    /**
     * Load samples from a text file, one sample per line.
     *
     * @param string $file_path Absolute path to the samples file.
     * @return array<int, string> Array of sample strings.
     */
    /**
     * Maximum training file size in bytes (2 MB).
     */
    const MAX_TRAINING_FILE_SIZE = 2 * 1024 * 1024;

    public function load_samples( string $file_path ): array {
        if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            return array();
        }

        $size = filesize( $file_path );
        if ( false === $size || $size > self::MAX_TRAINING_FILE_SIZE ) {
            return array();
        }

        $contents = file_get_contents( $file_path );
        if ( false === $contents ) {
            return array();
        }

        $lines   = explode( "\n", $contents );
        $samples = array();

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( '' !== $line ) {
                $samples[] = $line;
            }
        }

        return $samples;
    }

    /**
     * Train the classifier using pre-loaded sample arrays.
     *
     * @param ANTISPAMWIZARD_Naive_Bayes $classifier  Classifier instance.
     * @param array<int, string>         $spam_samples Spam text samples.
     * @param array<int, string>         $ham_samples  Ham text samples.
     */
    public function train_classifier_with_samples(
        ANTISPAMWIZARD_Naive_Bayes $classifier,
        array $spam_samples,
        array $ham_samples
    ): void {
        // Reset before replaying the full corpus to avoid double-counting
        // if the classifier already has learned data.
        $classifier->reset();

        foreach ( $spam_samples as $sample ) {
            $classifier->learn( $sample, 'spam' );
        }

        foreach ( $ham_samples as $sample ) {
            $classifier->learn( $sample, 'ham' );
        }
    }

    /**
     * Get the file path for a script-specific samples file.
     *
     * @param string $script  The script group name (e.g. 'latin', 'cyrillic').
     * @param string $category The category ('spam' or 'ham').
     * @return string Absolute path to the samples file.
     */
    public function get_script_file_path( string $script, string $category ): string {
        $valid_scripts    = array_keys( ANTISPAMWIZARD_Script_Detector::SCRIPT_GROUPS );
        $valid_categories = array( 'spam', 'ham' );

        if ( ! in_array( $script, $valid_scripts, true ) || ! in_array( $category, $valid_categories, true ) ) {
            return '';
        }

        return dirname( __DIR__ ) . '/data/' . $category . '-samples-' . $script . '.txt';
    }

    /**
     * Load the *shipped* samples for a script group and category.
     *
     * The file half of load_script_samples(), split out so ANTISPAMWIZARD_Model_Store can
     * tell the two apart without reading the sample table a second time. The
     * shipped corpus is fixed and measured, so it is never subject to the
     * learned-sample budget that bounds a cached unit's similarity corpus.
     *
     * @param string $script  The script group name.
     * @param string $category The category ('spam' or 'ham').
     * @return array<int, string> Array of sample strings.
     */
    public function load_script_file_samples( string $script, string $category ): array {
        return $this->load_samples( $this->get_script_file_path( $script, $category ) );
    }

    /**
     * Load the samples this site has *learned* for a script group and category.
     *
     * The repository half of load_script_samples(). ANTISPAMWIZARD_Sample_Repository::find_all()
     * orders by ascending id, so the newest sample is last -- which is the end
     * ANTISPAMWIZARD_Model_Store::admit_within_budget() spends its byte budget from.
     *
     * @param string $script  The script group name.
     * @param string $category The category ('spam' or 'ham').
     * @return array<int, string> Array of sample strings.
     */
    public function load_learned_script_samples( string $script, string $category ): array {
        if ( null === $this->repository ) {
            return array();
        }

        return $this->repository->find_all( $script, $category );
    }

    /**
     * Load samples for a specific script group and category.
     *
     * The merge of the two halves above, in the order every caller has always
     * seen: the shipped corpus first, this site's learned samples after it.
     *
     * @param string $script  The script group name.
     * @param string $category The category ('spam' or 'ham').
     * @return array<int, string> Array of sample strings.
     */
    public function load_script_samples( string $script, string $category ): array {
        return array_merge(
            $this->load_script_file_samples( $script, $category ),
            $this->load_learned_script_samples( $script, $category )
        );
    }

    /**
     * Get sample counts for all script groups.
     *
     * Returns all 10 script groups even when they have no training data,
     * so the UI can display every group in the Loaded Models section.
     *
     * @return array<string, array{spam: int, ham: int}> Counts keyed by script group.
     */
    public function get_script_sample_counts(): array {
        $counts   = array();
        $data_dir = dirname( __DIR__ ) . '/data/';

        foreach ( array_keys( ANTISPAMWIZARD_Script_Detector::SCRIPT_GROUPS ) as $script ) {
            $spam_file = $data_dir . 'spam-samples-' . $script . '.txt';
            $ham_file  = $data_dir . 'ham-samples-' . $script . '.txt';

            $spam_count = file_exists( $spam_file ) ? count( $this->load_samples( $spam_file ) ) : 0;
            $ham_count  = file_exists( $ham_file ) ? count( $this->load_samples( $ham_file ) ) : 0;

            if ( null !== $this->repository ) {
                $spam_count += $this->repository->count_by_script_category( $script, 'spam' );
                $ham_count  += $this->repository->count_by_script_category( $script, 'ham' );
            }

            $counts[ $script ] = array(
                'spam' => $spam_count,
                'ham'  => $ham_count,
            );
        }

        return $counts;
    }

    /**
     * Train a classifier using script-specific samples.
     *
     * @param ANTISPAMWIZARD_Naive_Bayes $classifier The classifier to train.
     * @param string                     $script     The script group name.
     */
    public function train_script_classifier(
        ANTISPAMWIZARD_Naive_Bayes $classifier,
        string $script
    ): void {
        $spam_samples = $this->load_script_samples( $script, 'spam' );
        $ham_samples  = $this->load_script_samples( $script, 'ham' );
        $this->train_classifier_with_samples( $classifier, $spam_samples, $ham_samples );
    }

    /**
     * Train a combined classifier using ALL script samples plus legacy files.
     *
     * Used as a fallback for unknown scripts.
     *
     * @param ANTISPAMWIZARD_Naive_Bayes $classifier The classifier to train.
     */
    public function train_combined_classifier( ANTISPAMWIZARD_Naive_Bayes $classifier ): void {
        $data_dir    = dirname( __DIR__ ) . '/data/';
        $all_spam    = array();
        $all_ham     = array();

        // Load legacy files.
        $all_spam = array_merge( $all_spam, $this->load_samples( $data_dir . 'spam-samples.txt' ) );
        $all_ham  = array_merge( $all_ham, $this->load_samples( $data_dir . 'ham-samples.txt' ) );

        // Load all script-specific files.
        foreach ( array_keys( ANTISPAMWIZARD_Script_Detector::SCRIPT_GROUPS ) as $script ) {
            $all_spam = array_merge( $all_spam, $this->load_script_samples( $script, 'spam' ) );
            $all_ham  = array_merge( $all_ham, $this->load_script_samples( $script, 'ham' ) );
        }

        // Include DB samples stored with script='unknown' (comments whose script
        // could not be identified). These have no seed file but still matter for
        // the combined classifier.
        if ( null !== $this->repository ) {
            $all_spam = array_merge( $all_spam, $this->repository->find_all( 'unknown', 'spam' ) );
            $all_ham  = array_merge( $all_ham, $this->repository->find_all( 'unknown', 'ham' ) );
        }

        $this->train_classifier_with_samples( $classifier, $all_spam, $all_ham );
    }
}
