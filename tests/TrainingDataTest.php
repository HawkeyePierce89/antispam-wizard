<?php

use PHPUnit\Framework\TestCase;

class TrainingDataTest extends TestCase {

    private ASW_Training_Data $training_data;
    private string $temp_dir;

    /** @var array<string, string> Backup of bundled files modified during tests. */
    private array $file_backups = array();

    protected function setUp(): void {
        parent::setUp();
        $this->training_data = new ASW_Training_Data();
        $this->temp_dir      = sys_get_temp_dir() . '/asw_test_' . uniqid();
        mkdir( $this->temp_dir, 0755, true );

        // Reset wpdb tables and options for DB-backed tests.
        global $wpdb, $wp_options;
        $wpdb->reset_tables();
        $wp_options = array();
    }

    protected function tearDown(): void {
        // Restore any bundled files modified during the test.
        foreach ( $this->file_backups as $path => $content ) {
            file_put_contents( $path, $content );
        }
        $this->file_backups = array();

        // Clean up temp files.
        $files = glob( $this->temp_dir . '/*' );
        if ( is_array( $files ) ) {
            foreach ( $files as $file ) {
                unlink( $file );
            }
        }
        if ( is_dir( $this->temp_dir ) ) {
            rmdir( $this->temp_dir );
        }
        parent::tearDown();
    }

    /**
     * Back up a bundled file so it is restored in tearDown even if the test fails.
     */
    private function backup_file( string $path ): void {
        if ( ! isset( $this->file_backups[ $path ] ) ) {
            $this->file_backups[ $path ] = file_get_contents( $path );
        }
    }

    public function test_load_samples_reads_lines(): void {
        $file = $this->temp_dir . '/samples.txt';
        file_put_contents( $file, "line one\nline two\nline three\n" );

        $samples = $this->training_data->load_samples( $file );

        $this->assertCount( 3, $samples );
        $this->assertSame( 'line one', $samples[0] );
        $this->assertSame( 'line two', $samples[1] );
        $this->assertSame( 'line three', $samples[2] );
    }

    public function test_load_samples_skips_empty_lines(): void {
        $file = $this->temp_dir . '/samples.txt';
        file_put_contents( $file, "line one\n\n\nline two\n\n" );

        $samples = $this->training_data->load_samples( $file );

        $this->assertCount( 2, $samples );
        $this->assertSame( 'line one', $samples[0] );
        $this->assertSame( 'line two', $samples[1] );
    }

    public function test_load_samples_returns_empty_for_missing_file(): void {
        $samples = $this->training_data->load_samples( '/nonexistent/path/file.txt' );

        $this->assertSame( array(), $samples );
    }

    public function test_load_samples_returns_empty_for_empty_file(): void {
        $file = $this->temp_dir . '/empty.txt';
        file_put_contents( $file, '' );

        $samples = $this->training_data->load_samples( $file );

        $this->assertSame( array(), $samples );
    }

    public function test_load_samples_skips_oversized_file(): void {
        $file = $this->temp_dir . '/oversized.txt';
        // Create a file just over the 2 MB cap.
        $size = ASW_Training_Data::MAX_TRAINING_FILE_SIZE + 1;
        $fh   = fopen( $file, 'w' );
        // Write a line repeated to exceed the cap.
        $line = str_repeat( 'spam sample text here ', 50 ) . "\n";
        $written = 0;
        while ( $written < $size ) {
            fwrite( $fh, $line );
            $written += strlen( $line );
        }
        fclose( $fh );

        $samples = $this->training_data->load_samples( $file );
        $this->assertSame( array(), $samples );
    }

    public function test_load_samples_reads_file_under_size_limit(): void {
        $file = $this->temp_dir . '/normal.txt';
        file_put_contents( $file, "line one\nline two\n" );

        $samples = $this->training_data->load_samples( $file );
        $this->assertCount( 2, $samples );
    }

    public function test_train_classifier_with_samples_trains_correctly(): void {
        $spam_samples = array(
            'Buy cheap pills online free discount',
            'Free money casino bonus jackpot winner',
        );
        $ham_samples = array(
            'Great article about programming practices',
            'Thanks for the helpful code tutorial',
        );

        $classifier = new ASW_Naive_Bayes();
        $this->training_data->train_classifier_with_samples( $classifier, $spam_samples, $ham_samples );

        $state = $classifier->get_state();
        $this->assertSame( 2, $state['doc_counts']['spam'] );
        $this->assertSame( 2, $state['doc_counts']['ham'] );
    }

    public function test_train_classifier_with_samples_classifies_correctly(): void {
        $spam_samples = array(
            'Buy cheap pills online free discount offer',
            'Free money casino bonus jackpot winner',
            'Earn thousands working from home guaranteed',
        );
        $ham_samples = array(
            'Great article about programming best practices',
            'Thanks for sharing this helpful tutorial',
            'Interesting thoughts on software development',
        );

        $classifier = new ASW_Naive_Bayes();
        $this->training_data->train_classifier_with_samples( $classifier, $spam_samples, $ham_samples );

        $result = $classifier->classify( 'Buy cheap free pills discount' );
        $this->assertGreaterThan( $result['ham'], $result['spam'] );

        $result = $classifier->classify( 'Great article about programming development' );
        $this->assertGreaterThan( $result['spam'], $result['ham'] );
    }

    public function test_train_classifier_with_samples_resets_before_training(): void {
        $spam_samples = array( 'Buy cheap pills online free discount' );
        $ham_samples  = array( 'Great article about programming' );

        $classifier = new ASW_Naive_Bayes();

        // Train twice — counts should be identical to a single training, not doubled.
        $this->training_data->train_classifier_with_samples( $classifier, $spam_samples, $ham_samples );
        $state_first = $classifier->get_state();

        $this->training_data->train_classifier_with_samples( $classifier, $spam_samples, $ham_samples );
        $state_second = $classifier->get_state();

        $this->assertSame( $state_first['doc_counts'], $state_second['doc_counts'] );
        $this->assertSame( $state_first['token_totals'], $state_second['token_totals'] );
    }

    public function test_train_classifier_with_samples_handles_empty_arrays(): void {
        $classifier = new ASW_Naive_Bayes();
        $this->training_data->train_classifier_with_samples( $classifier, array(), array() );

        $state = $classifier->get_state();
        $this->assertEmpty( $state['doc_counts'] );
    }

    // --- Per-script training data tests ---

    public function test_get_script_file_path_returns_correct_path(): void {
        $path = $this->training_data->get_script_file_path( 'latin', 'spam' );
        $this->assertStringEndsWith( 'data/spam-samples-latin.txt', $path );

        $path = $this->training_data->get_script_file_path( 'cyrillic', 'ham' );
        $this->assertStringEndsWith( 'data/ham-samples-cyrillic.txt', $path );

        $path = $this->training_data->get_script_file_path( 'cjk', 'spam' );
        $this->assertStringEndsWith( 'data/spam-samples-cjk.txt', $path );
    }

    public function test_load_script_samples_reads_from_correct_file(): void {
        $samples = $this->training_data->load_script_samples( 'latin', 'spam' );
        $this->assertIsArray( $samples );
        // Bundled latin spam file has 100+ samples.
        $this->assertGreaterThanOrEqual( 100, count( $samples ) );
        foreach ( $samples as $sample ) {
            $this->assertNotEmpty( $sample, 'Each sample line should be non-empty' );
        }
    }

    public function test_get_script_sample_counts_returns_all_10_groups(): void {
        $counts = $this->training_data->get_script_sample_counts();

        $this->assertIsArray( $counts );
        $this->assertCount( 10, $counts, 'All 10 script groups must be returned' );

        $expected_scripts = array(
            'latin', 'cyrillic', 'cjk', 'arabic', 'devanagari',
            'greek', 'hebrew', 'thai', 'bengali', 'indic',
        );
        foreach ( $expected_scripts as $script ) {
            $this->assertArrayHasKey( $script, $counts, "Script group '$script' must be present" );
            $this->assertArrayHasKey( 'spam', $counts[ $script ] );
            $this->assertArrayHasKey( 'ham', $counts[ $script ] );
            $this->assertIsInt( $counts[ $script ]['spam'] );
            $this->assertIsInt( $counts[ $script ]['ham'] );
        }

        // Scripts with training data should have positive counts.
        $this->assertGreaterThan( 0, $counts['latin']['spam'] );
        $this->assertGreaterThan( 0, $counts['latin']['ham'] );

        // All script groups should now have positive counts.
        $this->assertGreaterThan( 0, $counts['greek']['spam'] );
        $this->assertGreaterThan( 0, $counts['greek']['ham'] );
    }

    public function test_train_script_classifier_trains_with_script_data(): void {
        // Write temp training data to script files.
        $spam_file = $this->training_data->get_script_file_path( 'cyrillic', 'spam' );
        $ham_file  = $this->training_data->get_script_file_path( 'cyrillic', 'ham' );

        // Back up originals so tearDown restores them even if the test fails.
        $this->backup_file( $spam_file );
        $this->backup_file( $ham_file );

        file_put_contents( $spam_file, "Купите дешевые таблетки онлайн\nБесплатные деньги казино бонус\n" );
        file_put_contents( $ham_file, "Отличная статья о программировании\nСпасибо за полезный урок\n" );

        $classifier = new ASW_Naive_Bayes();
        $this->training_data->train_script_classifier( $classifier, 'cyrillic' );

        $state = $classifier->get_state();
        $this->assertSame( 2, $state['doc_counts']['spam'] );
        $this->assertSame( 2, $state['doc_counts']['ham'] );
    }

    public function test_train_script_classifier_handles_empty_files(): void {
        // Create temp empty files for a script to test empty-file handling.
        $spam_file = $this->training_data->get_script_file_path( 'greek', 'spam' );
        $ham_file  = $this->training_data->get_script_file_path( 'greek', 'ham' );

        // Back up originals so tearDown restores them even if the test fails.
        $this->backup_file( $spam_file );
        $this->backup_file( $ham_file );

        file_put_contents( $spam_file, '' );
        file_put_contents( $ham_file, '' );

        $classifier = new ASW_Naive_Bayes();
        $this->training_data->train_script_classifier( $classifier, 'greek' );

        $state = $classifier->get_state();
        $this->assertEmpty( $state['doc_counts'] );
    }

    public function test_train_combined_classifier_includes_legacy_and_script_data(): void {
        $classifier = new ASW_Naive_Bayes();
        $this->training_data->train_combined_classifier( $classifier );

        $state = $classifier->get_state();
        // At minimum, legacy files have 20+ samples each.
        $total_docs = array_sum( $state['doc_counts'] );
        $this->assertGreaterThanOrEqual( 40, $total_docs );
    }

    public function test_combined_classifier_doc_counts_match_all_files(): void {
        $classifier = new ASW_Naive_Bayes();
        $this->training_data->train_combined_classifier( $classifier );

        $state = $classifier->get_state();

        // Count expected total by loading all files manually.
        $data_dir       = dirname( __DIR__ ) . '/antispam-wizard/data/';
        $expected_spam  = count( $this->training_data->load_samples( $data_dir . 'spam-samples.txt' ) );
        $expected_ham   = count( $this->training_data->load_samples( $data_dir . 'ham-samples.txt' ) );
        $script_groups  = array(
            'latin', 'cyrillic', 'cjk', 'arabic', 'devanagari',
            'greek', 'hebrew', 'thai', 'bengali', 'indic',
        );
        foreach ( $script_groups as $script ) {
            $expected_spam += count( $this->training_data->load_script_samples( $script, 'spam' ) );
            $expected_ham  += count( $this->training_data->load_script_samples( $script, 'ham' ) );
        }

        $this->assertSame( $expected_spam, $state['doc_counts']['spam'] );
        $this->assertSame( $expected_ham, $state['doc_counts']['ham'] );
        // Verify non-trivial counts (all 10 scripts + legacy should have data).
        $this->assertGreaterThan( 1000, $state['doc_counts']['spam'] );
        $this->assertGreaterThan( 1000, $state['doc_counts']['ham'] );
    }

    public function test_load_bundled_spam_samples(): void {
        $spam_file = dirname( __DIR__ ) . '/antispam-wizard/data/spam-samples.txt';
        $samples   = $this->training_data->load_samples( $spam_file );

        $this->assertGreaterThanOrEqual( 20, count( $samples ) );
        foreach ( $samples as $sample ) {
            $this->assertNotEmpty( $sample );
        }
    }

    public function test_load_bundled_ham_samples(): void {
        $ham_file = dirname( __DIR__ ) . '/antispam-wizard/data/ham-samples.txt';
        $samples  = $this->training_data->load_samples( $ham_file );

        $this->assertGreaterThanOrEqual( 20, count( $samples ) );
        foreach ( $samples as $sample ) {
            $this->assertNotEmpty( $sample );
        }
    }

    public function test_train_classifier_with_bundled_data(): void {
        $classifier = new ASW_Naive_Bayes();
        $data_dir   = dirname( __DIR__ ) . '/antispam-wizard/data/';

        $spam_samples = $this->training_data->load_samples( $data_dir . 'spam-samples.txt' );
        $ham_samples  = $this->training_data->load_samples( $data_dir . 'ham-samples.txt' );
        $this->training_data->train_classifier_with_samples( $classifier, $spam_samples, $ham_samples );

        $state = $classifier->get_state();
        $this->assertGreaterThanOrEqual( 20, $state['doc_counts']['spam'] );
        $this->assertGreaterThanOrEqual( 20, $state['doc_counts']['ham'] );

        // Verify classification works with bundled data.
        $result = $classifier->classify( 'Buy cheap pills free discount pharmacy' );
        $this->assertGreaterThan( $result['ham'], $result['spam'] );
    }

    // --- Constructor / repository integration tests ---

    public function test_constructor_defaults_to_null_repository(): void {
        $td = new ASW_Training_Data();
        // No repository — load_script_samples returns file-only data.
        $samples = $td->load_script_samples( 'latin', 'spam' );
        $this->assertIsArray( $samples );
        $this->assertGreaterThanOrEqual( 100, count( $samples ) );
    }

    public function test_constructor_accepts_repository(): void {
        global $wpdb;
        $repo = new ASW_Sample_Repository( $wpdb );
        $td   = new ASW_Training_Data( $repo );

        // Should work without errors.
        $samples = $td->load_script_samples( 'latin', 'spam' );
        $this->assertIsArray( $samples );
    }

    // --- Mixed file + DB sample tests ---

    public function test_load_script_samples_merges_db_samples_after_file(): void {
        global $wpdb;
        $repo = new ASW_Sample_Repository( $wpdb );
        $td   = new ASW_Training_Data( $repo );

        // Insert a DB sample for latin/spam.
        $repo->insert( 'latin', 'spam', 'DB spam sample for merge test', 137 );

        $file    = $td->load_script_file_samples( 'latin', 'spam' );
        $learned = $td->load_learned_script_samples( 'latin', 'spam' );
        $samples = $td->load_script_samples( 'latin', 'spam' );

        // Should include both file samples and the DB sample.
        $this->assertContains( 'DB spam sample for merge test', $samples );
        // File samples are still present (latin spam has 100+).
        $this->assertGreaterThan( 100, count( $samples ) );

        // The order is not cosmetic, so it is asserted positionally rather than
        // by membership. ASW_Naive_Bayes::learn() admits tokens
        // first-come-first-served until MAX_DICTIONARY_SIZE, so swapping the two
        // halves would let a site's learned vocabulary claim dictionary slots
        // ahead of the shipped corpus on any script at the cap -- and
        // ASW_Model_Store::build_script_unit() relies on this exact order for
        // its "byte-identical to the unsplit build" guarantee.
        $this->assertSame( array( 'DB spam sample for merge test' ), $learned );
        $this->assertSame( $file, array_slice( $samples, 0, count( $file ) ) );
        $this->assertSame( $learned, array_slice( $samples, count( $file ) ) );
    }

    public function test_load_learned_script_samples_without_repository_is_empty(): void {
        $td = new ASW_Training_Data();

        $this->assertSame( array(), $td->load_learned_script_samples( 'latin', 'spam' ) );
        $this->assertNotEmpty(
            $td->load_script_file_samples( 'latin', 'spam' ),
            'The shipped half is unaffected by the absence of a repository'
        );
    }

    public function test_load_script_samples_without_repository_returns_file_only(): void {
        $td_no_repo  = new ASW_Training_Data();
        $file_count  = count( $td_no_repo->load_script_samples( 'latin', 'spam' ) );

        global $wpdb;
        $repo = new ASW_Sample_Repository( $wpdb );
        $repo->insert( 'latin', 'spam', 'Extra DB sample', 138 );

        $td_with_repo = new ASW_Training_Data( $repo );
        $merged_count = count( $td_with_repo->load_script_samples( 'latin', 'spam' ) );

        // With repo should have 1 more than without.
        $this->assertSame( $file_count + 1, $merged_count );
    }

    public function test_get_script_sample_counts_includes_db_counts(): void {
        global $wpdb;
        $repo = new ASW_Sample_Repository( $wpdb );
        $td   = new ASW_Training_Data( $repo );

        // Get baseline counts (file-only).
        $td_no_repo    = new ASW_Training_Data();
        $baseline      = $td_no_repo->get_script_sample_counts();
        $baseline_spam = $baseline['latin']['spam'];
        $baseline_ham  = $baseline['latin']['ham'];

        // Add DB samples.
        $repo->insert( 'latin', 'spam', 'DB spam one', 139 );
        $repo->insert( 'latin', 'spam', 'DB spam two', 140 );
        $repo->insert( 'latin', 'ham', 'DB ham one', 141 );

        $counts = $td->get_script_sample_counts();

        $this->assertSame( $baseline_spam + 2, $counts['latin']['spam'] );
        $this->assertSame( $baseline_ham + 1, $counts['latin']['ham'] );
    }

    public function test_get_script_sample_counts_without_repository_unchanged(): void {
        $td     = new ASW_Training_Data();
        $counts = $td->get_script_sample_counts();

        // Without a repository, counts should only reflect files.
        $this->assertGreaterThan( 0, $counts['latin']['spam'] );
        $this->assertGreaterThan( 0, $counts['latin']['ham'] );
    }

    public function test_train_script_classifier_includes_db_samples(): void {
        global $wpdb;
        $repo = new ASW_Sample_Repository( $wpdb );
        $td   = new ASW_Training_Data( $repo );

        // Back up originals.
        $spam_file = $td->get_script_file_path( 'cyrillic', 'spam' );
        $ham_file  = $td->get_script_file_path( 'cyrillic', 'ham' );
        $this->backup_file( $spam_file );
        $this->backup_file( $ham_file );

        // Write minimal file data.
        file_put_contents( $spam_file, "Файл спам образец\n" );
        file_put_contents( $ham_file, "Файл хам образец\n" );

        // Add DB samples.
        $repo->insert( 'cyrillic', 'spam', 'БД спам образец', 142 );
        $repo->insert( 'cyrillic', 'ham', 'БД хам образец', 143 );

        $classifier = new ASW_Naive_Bayes();
        $td->train_script_classifier( $classifier, 'cyrillic' );

        $state = $classifier->get_state();
        // 1 file + 1 DB = 2 per category.
        $this->assertSame( 2, $state['doc_counts']['spam'] );
        $this->assertSame( 2, $state['doc_counts']['ham'] );
    }

    public function test_train_combined_classifier_includes_db_samples(): void {
        global $wpdb;
        $repo = new ASW_Sample_Repository( $wpdb );
        $td   = new ASW_Training_Data( $repo );

        // Get baseline doc count without DB samples.
        $td_no_repo = new ASW_Training_Data();
        $classifier_baseline = new ASW_Naive_Bayes();
        $td_no_repo->train_combined_classifier( $classifier_baseline );
        $baseline_state = $classifier_baseline->get_state();

        // Add DB samples to two different scripts.
        $repo->insert( 'latin', 'spam', 'Combined DB spam latin', 144 );
        $repo->insert( 'cyrillic', 'ham', 'Combined DB ham cyrillic', 145 );

        $classifier = new ASW_Naive_Bayes();
        $td->train_combined_classifier( $classifier );

        $state = $classifier->get_state();
        $this->assertSame( $baseline_state['doc_counts']['spam'] + 1, $state['doc_counts']['spam'] );
        $this->assertSame( $baseline_state['doc_counts']['ham'] + 1, $state['doc_counts']['ham'] );
    }

    public function test_classifier_trained_with_db_only_samples(): void {
        global $wpdb;
        $repo = new ASW_Sample_Repository( $wpdb );
        $td   = new ASW_Training_Data( $repo );

        // Back up and empty file data for a script.
        $spam_file = $td->get_script_file_path( 'greek', 'spam' );
        $ham_file  = $td->get_script_file_path( 'greek', 'ham' );
        $this->backup_file( $spam_file );
        $this->backup_file( $ham_file );

        file_put_contents( $spam_file, '' );
        file_put_contents( $ham_file, '' );

        // Add DB-only samples.
        $repo->insert( 'greek', 'spam', 'Αγοράστε φθηνά χάπια', 146 );
        $repo->insert( 'greek', 'spam', 'Δωρεάν χρήματα καζίνο', 147 );
        $repo->insert( 'greek', 'ham', 'Εξαιρετικό άρθρο προγραμματισμού', 148 );
        $repo->insert( 'greek', 'ham', 'Ευχαριστώ για το μάθημα', 149 );

        $classifier = new ASW_Naive_Bayes();
        $td->train_script_classifier( $classifier, 'greek' );

        $state = $classifier->get_state();
        $this->assertSame( 2, $state['doc_counts']['spam'] );
        $this->assertSame( 2, $state['doc_counts']['ham'] );
    }
}
