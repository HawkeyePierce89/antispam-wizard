<?php
/**
 * Seed-corpus quality report.
 *
 * Usage (from the repository root, inside the test container):
 *   docker compose run --rm test php dev/corpus-eval.php [script ...] [-v]
 *
 * For each script it trains the real per-script classifier from the shipped
 * data files and reports, at the shipped bayesian_threshold:
 *   - held-out recall / false-positive rate on tests/fixtures/corpus/ (when
 *     fixtures exist for the script), the same numbers CorpusQualityTest pins;
 *   - 5-fold cross-validation on the corpus itself, which shows how well the
 *     samples generalise to each other rather than to the fixtures.
 * Misses and false positives are listed so a corpus edit can be judged.
 */

declare( strict_types=1 );

require dirname( __DIR__ ) . '/tests/bootstrap.php';

$verbose   = in_array( '--verbose', $argv, true ) || in_array( '-v', $argv, true );
$scripts   = array_values( array_diff( array_slice( $argv, 1 ), array( '-v', '--verbose' ) ) ) ?: array_keys( ANTISPAMWIZARD_Script_Detector::SCRIPT_GROUPS );
$threshold = (float) ANTISPAMWIZARD_Settings::get( 'bayesian_threshold' );
$data      = new ANTISPAMWIZARD_Training_Data();
$fixtures  = dirname( __DIR__ ) . '/tests/fixtures/corpus/';

/**
 * @param string[] $spam
 * @param string[] $ham
 * @return array{recall: float, fpr: float, missed: string[], flagged: string[]}
 */
$evaluate = static function ( ANTISPAMWIZARD_Naive_Bayes $classifier, array $spam, array $ham ) use ( $threshold ): array {
	$missed  = array();
	$flagged = array();
	foreach ( $spam as $text ) {
		$p = $classifier->classify( $text )['spam'] ?? 0.0;
		if ( $p <= $threshold ) {
			$missed[] = sprintf( '%.2f %s', $p, $text );
		}
	}
	foreach ( $ham as $text ) {
		$p = $classifier->classify( $text )['spam'] ?? 0.0;
		if ( $p > $threshold ) {
			$flagged[] = sprintf( '%.2f %s', $p, $text );
		}
	}
	return array(
		'recall'  => $spam ? 1 - count( $missed ) / count( $spam ) : 0.0,
		'fpr'     => $ham ? count( $flagged ) / count( $ham ) : 0.0,
		'missed'  => $missed,
		'flagged' => $flagged,
	);
};

foreach ( $scripts as $script ) {
	$spam = $data->load_script_samples( $script, 'spam' );
	$ham  = $data->load_script_samples( $script, 'ham' );
	printf( "== %s: %d spam / %d ham samples, threshold %.2f\n", $script, count( $spam ), count( $ham ), $threshold );

	$held_spam = $data->load_samples( $fixtures . 'spam-' . $script . '.txt' );
	$held_ham  = $data->load_samples( $fixtures . 'ham-' . $script . '.txt' );
	if ( $held_spam && $held_ham ) {
		$classifier = new ANTISPAMWIZARD_Naive_Bayes( new ANTISPAMWIZARD_Tokenizer(), $script );
		$data->train_script_classifier( $classifier, $script );
		$r = $evaluate( $classifier, $held_spam, $held_ham );
		printf( "   held-out (%d/%d): recall %.2f, false positives %.2f\n", count( $held_spam ), count( $held_ham ), $r['recall'], $r['fpr'] );
		foreach ( $r['missed'] as $m ) {
			echo "     missed  $m\n";
		}
		foreach ( $r['flagged'] as $f ) {
			echo "     flagged $f\n";
		}
	}

	$tokenizer   = new ANTISPAMWIZARD_Tokenizer( array() );
	$spam_tokens = 0;
	foreach ( $spam as $line ) {
		$spam_tokens += count( $tokenizer->tokenize_for_script( $line, $script ) );
	}
	$ham_tokens = 0;
	foreach ( $ham as $line ) {
		$ham_tokens += count( $tokenizer->tokenize_for_script( $line, $script ) );
	}
	$ratio = $spam_tokens ? $ham_tokens / $spam_tokens : 0.0;
	printf( "   tokens: %d spam / %d ham, ham/spam %.3f\n", $spam_tokens, $ham_tokens, $ratio );

	// 5-fold cross-validation, deterministic split so runs are comparable.
	$folds  = 5;
	$recall = 0.0;
	$fpr    = 0.0;
	$missed = array();
	$flagged = array();
	for ( $k = 0; $k < $folds; $k++ ) {
		$train_spam = $test_spam = $train_ham = $test_ham = array();
		foreach ( $spam as $i => $s ) {
			if ( $i % $folds === $k ) {
				$test_spam[] = $s;
			} else {
				$train_spam[] = $s;
			}
		}
		foreach ( $ham as $i => $h ) {
			if ( $i % $folds === $k ) {
				$test_ham[] = $h;
			} else {
				$train_ham[] = $h;
			}
		}
		$classifier = new ANTISPAMWIZARD_Naive_Bayes( new ANTISPAMWIZARD_Tokenizer(), $script );
		$data->train_classifier_with_samples( $classifier, $train_spam, $train_ham );
		$r        = $evaluate( $classifier, $test_spam, $test_ham );
		$recall  += $r['recall'] / $folds;
		$fpr     += $r['fpr'] / $folds;
		$missed   = array_merge( $missed, $r['missed'] );
		$flagged  = array_merge( $flagged, $r['flagged'] );
	}
	printf( "   5-fold CV: recall %.2f, false positives %.2f (%d missed, %d flagged)\n", $recall, $fpr, count( $missed ), count( $flagged ) );
	if ( $verbose ) {
		foreach ( $missed as $m ) {
			echo "     missed  $m\n";
		}
		foreach ( $flagged as $f ) {
			echo "     flagged $f\n";
		}
	}
}
