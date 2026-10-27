<?php
/**
 * Runtime detection smoke check for AntiSpam Wizard.
 *
 * Executed inside a real WordPress instance via `wp eval-file`. Drives the
 * plugin end-to-end through the `pre_comment_approved` filter and asserts:
 *   - a spam comment (3+ links) is classified as 'spam' by the Links check;
 *   - a neutral, link-free, stop-word-free comment is NOT classified as spam;
 *   - honeypot filled is classified as 'spam';
 *   - honeypot empty is NOT classified as spam;
 *   - fresh token with a high `submit_time_min_seconds` is classified as 'spam';
 *   - token minted well in the past is NOT classified as spam;
 *   - missing/tampered token is NOT classified as spam;
 *   - ANTISPAMWIZARD_Comment_Form::render_fields() output contains both field names;
 *   - public/css/comment-form.css exists in the deployed plugin.
 *
 * Probabilistic checks (Bayesian, Similarity) are disabled and settings are
 * pinned for the run so the ham verdict cannot flake on corpus/cache state.
 * The deterministic Links check (spam when http(s):// count > links_max) is the
 * spam trigger, so the result is corpus-independent.
 *
 * Prints expected-vs-actual on failure and exit(1); prints an OK line and
 * exit(0) when both assertions pass.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "detect-check: must run inside WordPress (wp eval-file)\n" );
	exit( 1 );
}

// WP-CLI does not route PHP error_log()/automatic errors into wp-content/debug.log
// the way the web SAPI does, even with WP_DEBUG_LOG set. Force the destination so
// any problems during the pre_comment_approved runs below are captured by LOADS A.
error_reporting( E_ALL );
ini_set( 'log_errors', '1' );
ini_set( 'error_log', WP_CONTENT_DIR . '/debug.log' );

if ( ! class_exists( 'ANTISPAMWIZARD_Settings' ) ) {
	fwrite( STDERR, "detect-check: FAIL plugin not loaded (ANTISPAMWIZARD_Settings missing) — is antispam-wizard active?\n" );
	exit( 1 );
}

if ( false === has_filter( 'pre_comment_approved' ) ) {
	fwrite( STDERR, "detect-check: FAIL no callback registered on pre_comment_approved\n" );
	exit( 1 );
}

/*
 * Pin settings for a deterministic run. Enable the plugin and the Links check
 * (the spam trigger), keep the default links_max (2), and turn off every other
 * check so the ham verdict depends only on Links — not on training corpus /
 * cache state (Bayesian, Similarity) or default stop-word / threshold seeds
 * (Stop Words, Emoji, Spacing, Multi-Language, Duplicate).
 */
ANTISPAMWIZARD_Settings::update(
	array(
		'enabled'                 => true,
		'links_enabled'           => true,
		'links_max'               => 2,
		'honeypot_enabled'        => true,
		'submit_time_enabled'     => true,
		'submit_time_min_seconds' => 5,
		'stop_words_enabled'      => false,
		'emoji_enabled'           => false,
		'spacing_enabled'         => false,
		'multi_lang_enabled'      => false,
		'duplicate_enabled'       => false,
		'bayesian_enabled'        => false,
		'similarity_enabled'      => false,
	)
);

$failures = array();

/**
 * Run a comment through the live pre_comment_approved filter chain.
 *
 * @param string $content Comment body.
 * @return mixed Approval status returned by the filter.
 */
function antispamwizard_smoke_run_comment( string $content ) {
	$commentdata = array(
		'comment_type'         => 'comment',
		'comment_content'      => $content,
		'comment_author'       => 'Smoke Tester',
		'comment_author_email' => 'smoke@example.com',
		'comment_author_url'   => '',
		'comment_author_IP'    => '203.0.113.7',
		'comment_post_ID'      => 0,
	);

	// Start from 0 (unapproved/hold) — what WordPress passes for a new comment.
	return apply_filters( 'pre_comment_approved', 0, $commentdata );
}

// --- Spam case: 3 links exceeds links_max (2) → must be 'spam'. ------------
$spam_content = 'Great deals here: http://example.com and https://example.net plus http://example.org today.';
$spam_result  = antispamwizard_smoke_run_comment( $spam_content );

if ( 'spam' !== $spam_result ) {
	$failures[] = sprintf(
		"SPAM case: expected 'spam', got %s",
		var_export( $spam_result, true )
	);
}

// --- Ham case: neutral, link-free, stop-word-free → must NOT be 'spam'. -----
$ham_content = 'Thank you for this helpful article. I really enjoyed reading it this morning.';
$ham_result  = antispamwizard_smoke_run_comment( $ham_content );

if ( 'spam' === $ham_result ) {
	$failures[] = sprintf(
		"HAM case: expected NOT 'spam', got %s",
		var_export( $ham_result, true )
	);
}

// --- Bot checks -------------------------------------------------------------

// Honeypot filled: must be 'spam'
$_POST = array();
$_POST[ ANTISPAMWIZARD_Comment_Form::HONEYPOT_FIELD ] = 'http://spammer.com';
$hp_filled_result = antispamwizard_smoke_run_comment( 'Neutral comment' );
if ( 'spam' !== $hp_filled_result ) {
	$failures[] = sprintf( "Honeypot filled: expected 'spam', got %s", var_export( $hp_filled_result, true ) );
}

// Honeypot empty: must NOT be 'spam'
$_POST = array();
$_POST[ ANTISPAMWIZARD_Comment_Form::HONEYPOT_FIELD ] = '';
$hp_empty_result = antispamwizard_smoke_run_comment( 'Neutral comment' );
if ( 'spam' === $hp_empty_result ) {
	$failures[] = sprintf( "Honeypot empty: expected NOT 'spam', got %s", var_export( $hp_empty_result, true ) );
}

// Submit Time fast (fresh token with high limit): must be 'spam'
$_POST = array();
$_POST[ ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD ] = ANTISPAMWIZARD_Comment_Form::mint_token();
ANTISPAMWIZARD_Settings::update( array_merge( ANTISPAMWIZARD_Settings::get_all(), array( 'submit_time_min_seconds' => 300 ) ) );
$st_fast_result = antispamwizard_smoke_run_comment( 'Neutral comment' );
if ( 'spam' !== $st_fast_result ) {
	$failures[] = sprintf( "Submit Time fast: expected 'spam', got %s", var_export( $st_fast_result, true ) );
}
ANTISPAMWIZARD_Settings::update( array_merge( ANTISPAMWIZARD_Settings::get_all(), array( 'submit_time_min_seconds' => 5 ) ) ); // restore

// Submit Time normal (token well in past): must NOT be 'spam'
$_POST = array();
$_POST[ ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD ] = ANTISPAMWIZARD_Comment_Form::mint_token( time() - 30 );
$st_normal_result = antispamwizard_smoke_run_comment( 'Neutral comment' );
if ( 'spam' === $st_normal_result ) {
	$failures[] = sprintf( "Submit Time normal: expected NOT 'spam', got %s", var_export( $st_normal_result, true ) );
}

// Submit Time missing: must NOT be 'spam' (fail-open)
$_POST = array();
$st_missing_result = antispamwizard_smoke_run_comment( 'Neutral comment' );
if ( 'spam' === $st_missing_result ) {
	$failures[] = sprintf( "Submit Time missing: expected NOT 'spam', got %s", var_export( $st_missing_result, true ) );
}

// Submit Time tampered: must NOT be 'spam' (fail-open)
$_POST[ ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD ] = '1234567890.badhash';
$st_tampered_result = antispamwizard_smoke_run_comment( 'Neutral comment' );
if ( 'spam' === $st_tampered_result ) {
	$failures[] = sprintf( "Submit Time tampered: expected NOT 'spam', got %s", var_export( $st_tampered_result, true ) );
}

// --- Front-end assets -------------------------------------------------------

ob_start();
$form = new ANTISPAMWIZARD_Comment_Form();
$form->render_fields();
$render_output = ob_get_clean();

if ( false === strpos( $render_output, ANTISPAMWIZARD_Comment_Form::HONEYPOT_FIELD ) ) {
	$failures[] = "ANTISPAMWIZARD_Comment_Form::render_fields() missing honeypot field name";
}
if ( false === strpos( $render_output, ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD ) ) {
	$failures[] = "ANTISPAMWIZARD_Comment_Form::render_fields() missing timestamp field name";
}

if ( ! file_exists( ANTISPAMWIZARD_PATH . 'public/css/comment-form.css' ) ) {
	$failures[] = "public/css/comment-form.css is missing";
}



if ( ! empty( $failures ) ) {
	fwrite( STDERR, "detect-check: FAIL\n" );
	foreach ( $failures as $failure ) {
		fwrite( STDERR, '  - ' . $failure . "\n" );
	}
	exit( 1 );
}

echo "detect-check: OK — spam(3 links)='spam', ham(neutral)=" . var_export( $ham_result, true ) . "\n";
exit( 0 );
