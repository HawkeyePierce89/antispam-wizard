<?php
/**
 * Model-loading benchmark for the plugin, run on the dev stack.
 *
 * Run against a dev stack that is up (docker compose up -d wordpress; bash dev/reset.sh):
 *   docker compose exec -T wordpress wp --allow-root eval-file /antispamwizard-dev/bench.php
 *
 * Measures what one comment costs the plugin, for a fixed ~300-character
 * Cyrillic spam body:
 *   (a) cold  ANTISPAMWIZARD::build_checks()                — every antispamwizard_classifiers_*
 *             transient deleted immediately before the call;
 *   (b) warm  ANTISPAMWIZARD::build_checks()                — the transients left in place;
 *   (c) warm  ANTISPAMWIZARD::build_checks() + one ANTISPAMWIZARD_Detector::evaluate();
 *   (d) cold  ANTISPAMWIZARD::build_checks() + one ANTISPAMWIZARD_Detector::evaluate() — the one-script
 *             cold path, which is where the cold cost lives now that
 *             ANTISPAMWIZARD_Model_Store made loading lazy and (a) and (b) read ~0;
 *   (e) the same cold path for a body whose script is 'unknown', which has no
 *             unit of its own and therefore loads the combined unit — the
 *             largest one, and the only one cases (a)–(d) never build.
 *
 * Each case reports wall-clock milliseconds and the memory_get_peak_usage()
 * delta over the baseline captured immediately before the call, averaged over
 * five runs. The script then evaluates both bodies once more — the measured
 * cases flush, so whichever ran last would otherwise be the only unit on disk —
 * and lists every antispamwizard_classifiers_* transient's size.
 *
 * Two sizes are printed per unit, because a unit has two stored forms and only
 * one of them has a limit. `row` is the bytes in wp_options, where this stack
 * keeps its transients: a `longtext` column with no per-item limit, holding the
 * plain packed unit — ANTISPAMWIZARD_Model_Store does not compress there, since wpdb
 * refuses a deflate stream into a utf8mb4 column outright. `cached` is what a
 * persistent object cache would be handed for the same unit — the compressed
 * envelope the store writes when one is installed — and is the number checked
 * against the limit a unit is pruned at (the 1 048 576-byte per-item limit less
 * ANTISPAMWIZARD_Model_Store::CACHE_ITEM_OVERHEAD_BYTES), with the ratio between the two
 * alongside so a future corpus edit can see which half moved: more corpus (the
 * row column grows) or worse-compressing content (the ratio moves). On a host
 * with no zlib the store writes the plain array into the cache too, so the two
 * columns are the same number with no ratio, and the limit applies to `row`.
 *
 * The peak is reset with memory_reset_peak_usage() (PHP 8.2+) before every run,
 * because memory_get_peak_usage() is monotonic for the life of the process: a
 * cold run that peaked at ~35 MB would otherwise report every later warm run as
 * a 0-byte delta. Without that function the script says so and the memory
 * column is only meaningful for the first, largest case.
 *
 * Both peaks are reported. `real` is memory_get_peak_usage( true ), the size of
 * the arenas PHP holds from the OS; once a cold run has grown them, a warm run
 * that allocates the same amount inside them reads as 0, so `real` understates
 * every case after the first. `alloc` is memory_get_peak_usage( false ), what
 * the request actually allocated, and is the figure to compare between runs.
 *
 * Read-only with respect to site content: build_checks() writes only its own
 * classifier transients and evaluate() classifies without inserting a comment.
 * Each evaluation uses a fresh commenter IP, as the site repository's checks/detect.php does, so
 * the Duplicate check's per-IP counters cannot make a re-run diverge.
 *
 * No declare(strict_types=1): wp eval-file runs the file through eval(), where a
 * declare() can never be the first statement.
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "bench: must run inside WordPress (wp eval-file)\n" );
	exit( 1 );
}

error_reporting( E_ALL );
ini_set( 'log_errors', '1' );
ini_set( 'error_log', WP_CONTENT_DIR . '/debug.log' );

if ( ! class_exists( 'ANTISPAMWIZARD' ) || ! class_exists( 'ANTISPAMWIZARD_Settings' ) || ! class_exists( 'ANTISPAMWIZARD_Detector' ) ) {
	fwrite( STDERR, "bench: FAIL antispam-wizard is not active\n" );
	exit( 1 );
}

$settings = ANTISPAMWIZARD_Settings::get_all();
if ( empty( $settings['enabled'] ) || empty( $settings['bayesian_enabled'] ) || empty( $settings['similarity_enabled'] ) ) {
	fwrite( STDERR, "bench: FAIL plugin, Bayesian or Similarity check disabled — the numbers would not measure model loading\n" );
	exit( 1 );
}

$runs = 5;

// A ~300-character Cyrillic classified-ad spam body: long enough to clear
// min_message_length, so the Bayesian and Similarity checks really run.
$body = 'Продам гараж недорого, срочно, пишите или звоните 89139991205. '
	. 'Также есть услуги грузчиков и вывоз мусора по городу, работаем без выходных, цены ниже рынка. '
	. 'Ремонт квартир под ключ, натяжные потолки, сантехника, электрика. '
	. 'Заказывайте прямо сейчас, скидка 20 процентов первым клиентам!';

// A body with no letters at all: ANTISPAMWIZARD_Script_Detector::detect() returns
// 'unknown', which is not a key of SCRIPT_GROUPS, so ANTISPAMWIZARD_Model_Store has no
// per-script unit for it and both model checks fall through to the combined
// one. Comma-delimited so ANTISPAMWIZARD_Tokenizer's PHONE_PATTERN does not fold the whole
// string into a single marker token.
$unknown_body = '2019, 2020, 2021, 3005, 4007, 5008, 6009, 7010, 8011, 9012, '
	. '1013, 1014, 1015, 1016, 1017, 1018, 1019, 1020, 1021, 1022, 1023, 1024.';

/**
 * Delete every antispamwizard_classifiers_* transient, so the next build is cold.
 *
 * @return int Number of transients removed.
 */
function antispamwizard_bench_flush_models() {
	global $wpdb;

	$like  = $wpdb->esc_like( '_transient_antispamwizard_classifiers_' ) . '%';
	$names = $wpdb->get_col(
		$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
	);

	$removed = 0;
	foreach ( (array) $names as $name ) {
		delete_transient( substr( $name, strlen( '_transient_' ) ) );
		++$removed;
	}

	return $removed;
}

/**
 * Every antispamwizard_classifiers_* transient with its option-row and would-be-cached sizes.
 *
 * `row` is the byte count of the value as it sits in wp_options, which on a
 * stack with no external object cache is the plain packed unit. `cached` is the
 * same unit in the form ANTISPAMWIZARD_Model_Store would hand a persistent object cache —
 * `serialize( array( 'zlib' => gzcompress( …, 6 ) ) )`, the store's own level —
 * and is the figure the object cache's per-item limit applies to. With no zlib
 * on this host the two are equal and the limit applies to the row.
 *
 * The envelope is encoded here rather than through ANTISPAMWIZARD_Model_Store, whose
 * helpers are private: the member name and the level are stable parts of the
 * stored format, and reading the row directly keeps the benchmark a pure
 * observer of it. A row that already carries an envelope is a contradiction of
 * where the envelope applies and fails the run rather than being decoded, so a
 * future change there cannot make these numbers silently mean something else.
 *
 * @return array<string,array{row:int,cached:int,compressed:bool}> Transient key => sizes.
 */
function antispamwizard_bench_model_sizes() {
	global $wpdb;

	$like = $wpdb->esc_like( '_transient_antispamwizard_classifiers_' ) . '%';
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name",
			$like
		),
		ARRAY_A
	);

	$sizes = array();
	foreach ( (array) $rows as $row ) {
		// The row always holds the plain packed unit: the envelope is confined
		// to the object cache, and with one installed set_transient() writes no
		// option row at all, so no version of this plugin can put an envelope
		// here. Asserted rather than recovered from — a future change to where
		// the envelope applies must make this loud rather than make the numbers
		// below silently mean something else.
		$key        = substr( $row['option_name'], strlen( '_transient_' ) );
		$serialized = $row['option_value'];
		$value      = maybe_unserialize( $serialized );

		if ( is_array( $value ) && isset( $value['zlib'] ) ) {
			fwrite( STDERR, "bench: FAIL unit $key carries a compressed envelope in its option row — the sizes below would not mean what they say\n" );
			exit( 1 );
		}

		$row_bytes  = strlen( $serialized );
		$compressed = false;
		$cached     = $row_bytes;

		if ( function_exists( 'gzcompress' ) ) {
			$deflated = gzcompress( $serialized, 6 );
			if ( is_string( $deflated ) && '' !== $deflated ) {
				$cached     = strlen( serialize( array( 'zlib' => $deflated ) ) );
				$compressed = true;
			}
		}

		$sizes[ $key ] = array(
			'row'        => $row_bytes,
			'cached'     => $cached,
			'compressed' => $compressed,
		);
	}

	return $sizes;
}

/**
 * Run one measured case $runs times and average it.
 *
 * @param callable $before Prepares the case (e.g. flushes the models); not measured.
 * @param callable $work   The measured call.
 * @param int      $runs   Repetitions.
 * @return array{ms: float, real: float, alloc: float}
 */
function antispamwizard_bench_measure( callable $before, callable $work, $runs ) {
	$total_ms    = 0.0;
	$total_real  = 0.0;
	$total_alloc = 0.0;

	for ( $i = 0; $i < $runs; $i++ ) {
		$before();
		gc_collect_cycles();

		if ( function_exists( 'memory_reset_peak_usage' ) ) {
			memory_reset_peak_usage();
		}
		$base_real  = memory_get_usage( true );
		$base_alloc = memory_get_usage( false );
		$start      = microtime( true );

		$result = $work();

		$total_ms    += ( microtime( true ) - $start ) * 1000;
		$total_real  += max( 0, memory_get_peak_usage( true ) - $base_real );
		$total_alloc += max( 0, memory_get_peak_usage( false ) - $base_alloc );

		unset( $result );
		gc_collect_cycles();
	}

	return array(
		'ms'    => $total_ms / $runs,
		'real'  => $total_real / $runs,
		'alloc' => $total_alloc / $runs,
	);
}

/**
 * @param float $bytes Byte count.
 * @return string Human-readable megabytes.
 */
function antispamwizard_bench_mb( $bytes ) {
	return number_format( $bytes / 1048576, 2 ) . ' MB';
}

$antispamwizard   = ANTISPAMWIZARD::get_instance();
$noop  = static function () {};
$flush = static function () {
	antispamwizard_bench_flush_models();
};

$verdicts = array();

/**
 * One full evaluation of a body through a freshly built check set.
 *
 * Declared before the cases because both the warm and the cold evaluation
 * measure it; the only difference between them is whether the models are on
 * disk when it runs.
 *
 * @param string $text The comment body to classify.
 * @return array The detector result.
 */
$evaluate_body = static function ( $text ) use ( $antispamwizard, &$verdicts ) {
	$detector = new ANTISPAMWIZARD_Detector( $antispamwizard->build_checks() );
	$result   = $detector->evaluate(
		array(
			'comment_content'      => $text,
			'comment_author'       => 'bench',
			'comment_author_email' => 'bench@example.com',
			'comment_author_url'   => '',
			// RFC 3849 documentation prefix; unique per run, so the
			// Duplicate check's per-IP counters never carry over.
			'comment_author_IP'    => '2001:db8:' . bin2hex( random_bytes( 2 ) ) . ':' . bin2hex( random_bytes( 2 ) ) . '::1',
			'comment_type'         => '',
		)
	);
	$verdicts[] = $result['is_spam'] ? implode( ',', $result['check_names'] ) : 'not spam';
	return $result;
};

$evaluate_once         = static function () use ( $evaluate_body, $body ) {
	return $evaluate_body( $body );
};
$evaluate_unknown_once = static function () use ( $evaluate_body, $unknown_body ) {
	return $evaluate_body( $unknown_body );
};

// (a) cold build_checks(): every classifier transient deleted first.
//
// Since ANTISPAMWIZARD_Model_Store made loading lazy this measures only the construction
// of the ten checks — no model is touched until a check asks for one — so it is
// expected to be ~0. The cold cost now lives in case (d).
$cold = antispamwizard_bench_measure(
	$flush,
	static function () use ( $antispamwizard ) {
		return $antispamwizard->build_checks();
	},
	$runs
);

// Warm the models so (b) and (c) never pay for a rebuild. This has to be a real
// evaluation, not a build_checks() call: build_checks() no longer reads a model,
// so warming with it would fold one cold rebuild into the (c) average.
$evaluate_once();

// (b) warm build_checks().
$warm = antispamwizard_bench_measure(
	$noop,
	static function () use ( $antispamwizard ) {
		return $antispamwizard->build_checks();
	},
	$runs
);

// (c) warm build_checks() plus one full evaluation of the Cyrillic body.
$evaluate = antispamwizard_bench_measure( $noop, $evaluate_once, $runs );

// (d) cold build_checks() plus one evaluation: every classifier transient
// deleted first, so the run pays for building the one writing system's unit.
// This is the one-script cold path — what a single comment costs on a site
// whose models have just been invalidated.
$cold_evaluate = antispamwizard_bench_measure( $flush, $evaluate_once, $runs );

// (e) the same cold path for an 'unknown'-script body. 'unknown' is not a key of
// ANTISPAMWIZARD_Script_Detector::SCRIPT_GROUPS, so ANTISPAMWIZARD_Model_Store::script_unit() has
// nothing to hand back and both model checks load the combined unit instead --
// the largest unit in the system, and the one no Cyrillic body ever builds.
// Without this case the size listing below could never see it, and the 1 MB
// flag would be reporting only on units that are not the worst one.
$cold_combined = antispamwizard_bench_measure( $flush, $evaluate_unknown_once, $runs );

// Every measured case flushes, so only the last one's unit is still on disk.
// Build both before sizing, so the listing covers the per-script and the
// combined unit together.
$evaluate_body( $body );
$evaluate_body( $unknown_body );

$sizes = antispamwizard_bench_model_sizes();
// The limit production prunes at, not the raw per-item limit: ANTISPAMWIZARD_Model_Store
// holds back CACHE_ITEM_OVERHEAD_BYTES because memcached weighs the whole item
// and stored_size() can only weigh the value. Flagging at the raw figure left a
// kilobyte-wide band in which a unit passed this benchmark and was pruned on a
// live site.
$unit_limit   = ANTISPAMWIZARD_Model_Store::MAX_CACHED_UNIT_BYTES - ANTISPAMWIZARD_Model_Store::CACHE_ITEM_OVERHEAD_BYTES;
$total_row    = 0;
$total_cached = 0;
foreach ( $sizes as $unit_sizes ) {
	$total_row    += $unit_sizes['row'];
	$total_cached += $unit_sizes['cached'];
}

fwrite( STDOUT, "bench: " . $runs . " runs per case, bodies " . mb_strlen( $body, 'UTF-8' ) . " chars (cyrillic) and " . mb_strlen( $unknown_body, 'UTF-8' ) . " chars (unknown script)\n" );
if ( ! function_exists( 'memory_reset_peak_usage' ) ) {
	fwrite( STDOUT, "  ! memory_reset_peak_usage() missing (PHP < 8.2): memory is cumulative, only the cold figure is meaningful\n" );
}
fwrite( STDOUT, sprintf( "  %-28s %11s %12s %12s\n", '', 'time', 'peak real', 'peak alloc' ) );
fwrite( STDOUT, sprintf( "  %-28s %8.2f ms %12s %12s\n", 'cold build_checks()', $cold['ms'], antispamwizard_bench_mb( $cold['real'] ), antispamwizard_bench_mb( $cold['alloc'] ) ) );
fwrite( STDOUT, sprintf( "  %-28s %8.2f ms %12s %12s\n", 'warm build_checks()', $warm['ms'], antispamwizard_bench_mb( $warm['real'] ), antispamwizard_bench_mb( $warm['alloc'] ) ) );
fwrite( STDOUT, sprintf( "  %-28s %8.2f ms %12s %12s\n", 'warm build_checks() + eval', $evaluate['ms'], antispamwizard_bench_mb( $evaluate['real'] ), antispamwizard_bench_mb( $evaluate['alloc'] ) ) );
fwrite( STDOUT, sprintf( "  %-28s %8.2f ms %12s %12s\n", 'cold build_checks() + eval', $cold_evaluate['ms'], antispamwizard_bench_mb( $cold_evaluate['real'] ), antispamwizard_bench_mb( $cold_evaluate['alloc'] ) ) );
fwrite( STDOUT, sprintf( "  %-28s %8.2f ms %12s %12s\n", 'cold + eval (combined unit)', $cold_combined['ms'], antispamwizard_bench_mb( $cold_combined['real'] ), antispamwizard_bench_mb( $cold_combined['alloc'] ) ) );
fwrite( STDOUT, "  verdicts: " . implode( ' | ', array_unique( $verdicts ) ) . "\n" );
fwrite( STDOUT, "  cached units: " . count( $sizes ) . ", total " . antispamwizard_bench_mb( $total_row ) . " in wp_options (" . antispamwizard_bench_mb( $total_cached ) . " as object-cache items)\n" );
fwrite( STDOUT, sprintf( "    %-32s %14s %14s %8s\n", '', 'row', 'cached', 'ratio' ) );
foreach ( $sizes as $key => $unit_sizes ) {
	// The limit belongs to the object cache, so it is checked against the bytes
	// a cache would be handed. A unit over it is reported, never accepted
	// silently: memcached drops the write and the model is rebuilt on every
	// request. The wp_options row has no per-item limit of its own.
	$flag  = $unit_sizes['cached'] >= $unit_limit ? '  ** at or over the ' . $unit_limit . '-byte limit a unit is pruned at' : '';
	$ratio = $unit_sizes['compressed'] && $unit_sizes['cached'] > 0
		? sprintf( '%.2fx', $unit_sizes['row'] / $unit_sizes['cached'] )
		: 'plain';
	fwrite(
		STDOUT,
		sprintf(
			"    %-32s %9d B %12d B %8s  (%.1f%% of the limit)%s\n",
			$key,
			$unit_sizes['row'],
			$unit_sizes['cached'],
			$ratio,
			$unit_sizes['cached'] / $unit_limit * 100,
			$flag
		)
	);
}

exit( 0 );
