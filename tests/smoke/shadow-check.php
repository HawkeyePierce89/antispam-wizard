<?php
/**
 * Runtime shadow-display smoke check for AntiSpam Wizard.
 *
 * Executed inside a real WordPress instance via `wp eval-file`. Builds a
 * throwaway post with five comments and renders its comment list through both
 * paths ASW_Shadow_Display hooks into, as the comments' author (commenter
 * cookie set), as an anonymous visitor (cookie cleared) and as a cookie-less
 * author landing on the unapproved=<ID>&moderation-hash=<wp_hash(date)> pair
 * core's comment_post_redirect target carries:
 *   - classic: comments_template() (theme-compat comments.php on a block theme)
 *     → wp_list_comments();
 *   - block:   do_blocks() with core/comments + core/comment-template +
 *     core/comment-content.
 * It asserts:
 *   - the approved comment renders on every pass (anti-vacuity: without it an
 *     empty list would satisfy every "absent" assertion below);
 *   - for the author, their auto-flagged comment and their auto-flagged reply
 *     each render with core's "Your comment is awaiting moderation." note (one
 *     note per flagged comment, so the top-level one cannot stand in for the
 *     reply), while
 *     their moderator-marked spam (no _asw_reason) and another address's
 *     auto-flagged spam do not;
 *   - for the anonymous visitor, no spam text renders at all;
 *   - the plugin's comment_post_redirect callback, run through the real
 *     filter for the flagged comment re-dated to now, adds exactly that pair;
 *   - for the cookie-less author (that pair in $_GET, no cookie), the same
 *     two flagged comments render held, matched on
 *     the note's shared prefix (core appends "This is a preview; …" when there
 *     is no cookie), while the other two spam comments stay absent;
 *   - an invalid hash and an expired window (date moved 11 minutes back, hash
 *     recomputed for that date, so only the window rejects it) render no spam;
 *   - the stored rows and the comment object cache still read 'spam'.
 * Deletes everything it created, on success and on failure.
 *
 * No declare(strict_types=1): wp eval-file runs this through eval(), where a
 * declare() can never be the first statement.
 *
 * Prints every failure and exit(1); prints an OK line and exit(0) on success.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "shadow-check: must run inside WordPress (wp eval-file)\n" );
	exit( 1 );
}

// Force debug logging routing like detect-check.php so LOADS A captures warnings.
error_reporting( E_ALL );
ini_set( 'log_errors', '1' );
ini_set( 'error_log', WP_CONTENT_DIR . '/debug.log' );

if ( ! class_exists( 'ASW_Shadow_Display' ) || false === has_filter( 'comments_clauses' ) ) {
	fwrite( STDERR, "shadow-check: FAIL ASW_Shadow_Display missing or not hooked — is antispam-wizard active?\n" );
	exit( 1 );
}

ASW_Settings::update(
	array(
		'enabled'                => true,
		'shadow_display_enabled' => true,
	)
);

// The smoke site runs a block theme with no comments.php, so comments_template()
// falls back to theme-compat, which raises a core deprecation notice that has
// nothing to do with the plugin. Keep it out of the LOADS A log.
add_filter( 'deprecated_file_trigger_error', '__return_false' );

global $wpdb;

$token        = 'aswshadow' . wp_generate_password( 10, false, false );
$author_email = 'shadow-author-' . strtolower( $token ) . '@example.com';
$other_email  = 'shadow-other-' . strtolower( $token ) . '@example.com';
$texts        = array(
	'approved'    => 'Shadow smoke approved ' . $token,
	'flagged'     => 'Shadow smoke flagged ' . $token,
	'reply'       => 'Shadow smoke flagged reply ' . $token,
	'moderator'   => 'Shadow smoke moderator marked ' . $token,
	'other_email' => 'Shadow smoke other address ' . $token,
);
$spam_keys    = array( 'flagged', 'reply', 'moderator', 'other_email' );
$failures     = array();
$post_id      = 0;
$ids          = array();
$cookie_email = 'comment_author_email_' . COOKIEHASH;
$cookie_name  = 'comment_author_' . COOKIEHASH;

/**
 * Insert one comment on the throwaway post.
 *
 * @param int        $post_id  Post.
 * @param string     $text     Comment body.
 * @param string     $email    Author email.
 * @param int|string $approved 1 or 'spam'.
 * @param int        $parent   Parent comment ID.
 * @param int        $offset   Seconds after the first comment, for a stable order.
 * @param bool       $flagged  Whether to carry _asw_reason (auto-flagged).
 * @return int Comment ID, 0 on failure.
 */
function asw_shadow_smoke_insert( int $post_id, string $text, string $email, $approved, int $parent, int $offset, bool $flagged ): int {
	$gmt = gmdate( 'Y-m-d H:i:s', time() - 3600 + $offset );
	$id  = (int) wp_insert_comment(
		array(
			'comment_post_ID'      => $post_id,
			'comment_author'       => 'Shadow Smoke',
			'comment_author_email' => $email,
			'comment_author_url'   => '',
			'comment_author_IP'    => '192.0.2.10',
			'comment_content'      => $text,
			'comment_type'         => 'comment',
			'comment_parent'       => $parent,
			'comment_approved'     => $approved,
			'comment_date_gmt'     => $gmt,
			'comment_date'         => get_date_from_gmt( $gmt ),
		)
	);
	if ( $id > 0 && $flagged ) {
		add_comment_meta( $id, '_asw_reason', 'links' );
		ASW_Shadow_Display::record_flag( $id ); // As the detector does on its first flag.
	}
	return $id;
}

/**
 * Move a comment's date, as if it had just been posted at $gmt.
 *
 * @param int    $id  Comment ID.
 * @param string $gmt New comment_date_gmt.
 * @return void
 */
function asw_shadow_smoke_set_date( int $id, string $gmt ): void {
	global $wpdb;
	$wpdb->update(
		$wpdb->comments,
		array(
			'comment_date_gmt' => $gmt,
			'comment_date'     => get_date_from_gmt( $gmt ),
		),
		array( 'comment_ID' => $id )
	);
	clean_comment_cache( $id );
}

/**
 * Render the post's comments through the classic theme path.
 *
 * @return string
 */
function asw_shadow_smoke_render_classic(): string {
	ob_start();
	comments_template();
	return (string) ob_get_clean();
}

/**
 * Render the post's comments through the block path.
 *
 * @return string
 */
function asw_shadow_smoke_render_blocks(): string {
	return do_blocks( '<!-- wp:comments --><!-- wp:comment-template --><!-- wp:comment-content /--><!-- /wp:comment-template --><!-- /wp:comments -->' );
}

/**
 * Assert one render: the approved text first (anti-vacuity), then the
 * expected presence and absence of every other text.
 *
 * @param string $label      Pass label for messages.
 * @param string $html       Rendered output.
 * @param array  $texts      All comment texts by key.
 * @param array  $present    Keys that must render.
 * @param array  $absent     Keys that must not render.
 * @param int    $held_notes How many awaiting-moderation notes must render.
 * @return array Failure messages.
 */
function asw_shadow_smoke_assert( string $label, string $html, array $texts, array $present, array $absent, int $held_notes ): array {
	if ( false === strpos( $html, $texts['approved'] ) ) {
		return array( $label . ': the approved comment did not render — the comment list is empty, so nothing else here can be judged' );
	}
	$failures = array();
	foreach ( $present as $key ) {
		if ( false === strpos( $html, $texts[ $key ] ) ) {
			$failures[] = $label . ': expected the ' . $key . ' comment to render';
		}
	}
	foreach ( $absent as $key ) {
		if ( false !== strpos( $html, $texts[ $key ] ) ) {
			$failures[] = $label . ': the ' . $key . ' comment rendered but must not';
		}
	}
	$notes = substr_count( $html, 'Your comment is awaiting moderation.' );
	if ( $notes !== $held_notes ) {
		$failures[] = sprintf( '%s: %d awaiting-moderation note(s) rendered, expected %d (one per flagged comment)', $label, $notes, $held_notes );
	}
	if ( $held_notes > 0 && false === strpos( $html, 'comment-awaiting-moderation' ) ) {
		$failures[] = $label . ': the awaiting-moderation note lacks its comment-awaiting-moderation markup';
	}
	return $failures;
}

try {
	$post_id = (int) wp_insert_post(
		array(
			'post_title'     => 'Shadow smoke ' . $token,
			'post_content'   => 'Shadow smoke post.',
			'post_status'    => 'publish',
			'post_type'      => 'post',
			'comment_status' => 'open',
			'post_author'    => 1,
		)
	);
	if ( $post_id <= 0 ) {
		throw new RuntimeException( 'could not create the throwaway post' );
	}

	$ids['approved']    = asw_shadow_smoke_insert( $post_id, $texts['approved'], 'someone-' . strtolower( $token ) . '@example.com', 1, 0, 0, false );
	$ids['flagged']     = asw_shadow_smoke_insert( $post_id, $texts['flagged'], $author_email, 'spam', 0, 60, true );
	$ids['reply']       = asw_shadow_smoke_insert( $post_id, $texts['reply'], $author_email, 'spam', $ids['approved'], 120, true );
	$ids['moderator']   = asw_shadow_smoke_insert( $post_id, $texts['moderator'], $author_email, 'spam', 0, 180, false );
	$ids['other_email'] = asw_shadow_smoke_insert( $post_id, $texts['other_email'], $other_email, 'spam', 0, 240, true );
	foreach ( $ids as $key => $id ) {
		if ( $id <= 0 ) {
			throw new RuntimeException( 'could not insert the ' . $key . ' comment' );
		}
	}

	// comments_template() needs a singular main query; render_block() seeds the
	// postId/postType context of core/comments and core/comment-template from
	// the global $post, so without it the block path renders an empty list.
	$GLOBALS['wp_query']     = new WP_Query( array( 'p' => $post_id ) );
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
	$GLOBALS['post']         = get_post( $post_id );
	setup_postdata( $GLOBALS['post'] );

	// --- As the author: the commenter cookie core reads in include_unapproved.
	$_COOKIE[ $cookie_name ]  = 'Shadow Smoke';
	$_COOKIE[ $cookie_email ] = $author_email;

	$author_present = array( 'flagged', 'reply' );
	$author_absent  = array( 'moderator', 'other_email' );
	$failures       = array_merge(
		$failures,
		asw_shadow_smoke_assert( 'classic, author', asw_shadow_smoke_render_classic(), $texts, $author_present, $author_absent, count( $author_present ) ),
		asw_shadow_smoke_assert( 'block, author', asw_shadow_smoke_render_blocks(), $texts, $author_present, $author_absent, count( $author_present ) )
	);

	// --- As an anonymous visitor.
	unset( $_COOKIE[ $cookie_name ], $_COOKIE[ $cookie_email ] );

	$failures = array_merge(
		$failures,
		asw_shadow_smoke_assert( 'classic, anonymous', asw_shadow_smoke_render_classic(), $texts, array(), $spam_keys, 0 ),
		asw_shadow_smoke_assert( 'block, anonymous', asw_shadow_smoke_render_blocks(), $texts, array(), $spam_keys, 0 )
	);

	// --- As a cookie-less author: only the pair the redirect carries, within
	// core's ten-minute window, so the flagged comment is re-dated to now.
	// The pair comes from the plugin's own comment_post_redirect callback,
	// run through the real filter with no consent key posted, so this pass
	// fails if the callback is unhooked or hashes what core does not check.
	// The row is read past the get_comment filter: the author pass above
	// registered the flagged ID, so get_comment() would present it as '0',
	// whereas wp-comments-post.php hands the hook the real spam row.
	$flagged_gmt = gmdate( 'Y-m-d H:i:s' );
	asw_shadow_smoke_set_date( $ids['flagged'], $flagged_gmt );
	unset( $_POST['wp-comment-cookies-consent'] );
	$redirect = (string) apply_filters( 'comment_post_redirect', get_comment_link( $ids['flagged'] ), WP_Comment::get_instance( $ids['flagged'] ) );
	wp_parse_str( (string) wp_parse_url( $redirect, PHP_URL_QUERY ), $redirect_args );
	if ( (string) $ids['flagged'] !== (string) ( $redirect_args['unapproved'] ?? '' ) || wp_hash( $flagged_gmt ) !== (string) ( $redirect_args['moderation-hash'] ?? '' ) ) {
		$failures[] = 'comment_post_redirect did not add the unapproved + moderation-hash pair for the flagged comment: ' . $redirect;
	}
	$_GET['unapproved']      = (string) ( $redirect_args['unapproved'] ?? '' );
	$_GET['moderation-hash'] = (string) ( $redirect_args['moderation-hash'] ?? '' );

	$failures = array_merge(
		$failures,
		asw_shadow_smoke_assert( 'classic, cookie-less author', asw_shadow_smoke_render_classic(), $texts, $author_present, $author_absent, count( $author_present ) ),
		asw_shadow_smoke_assert( 'block, cookie-less author', asw_shadow_smoke_render_blocks(), $texts, $author_present, $author_absent, count( $author_present ) )
	);

	// --- An invalid hash resolves no email.
	$_GET['moderation-hash'] = wp_hash( $flagged_gmt . ' tampered' );

	$failures = array_merge(
		$failures,
		asw_shadow_smoke_assert( 'classic, invalid hash', asw_shadow_smoke_render_classic(), $texts, array(), $spam_keys, 0 ),
		asw_shadow_smoke_assert( 'block, invalid hash', asw_shadow_smoke_render_blocks(), $texts, array(), $spam_keys, 0 )
	);

	// --- An expired window: the hash is valid for the moved date, so only the
	// ten-minute window can reject it.
	$expired_gmt = gmdate( 'Y-m-d H:i:s', time() - 11 * MINUTE_IN_SECONDS );
	asw_shadow_smoke_set_date( $ids['flagged'], $expired_gmt );
	$_GET['moderation-hash'] = wp_hash( $expired_gmt );

	$failures = array_merge(
		$failures,
		asw_shadow_smoke_assert( 'classic, expired window', asw_shadow_smoke_render_classic(), $texts, array(), $spam_keys, 0 ),
		asw_shadow_smoke_assert( 'block, expired window', asw_shadow_smoke_render_blocks(), $texts, array(), $spam_keys, 0 )
	);

	unset( $_GET['unapproved'], $_GET['moderation-hash'] );

	// --- The presentation never reached storage.
	foreach ( $spam_keys as $key ) {
		$id     = $ids[ $key ];
		$stored = $wpdb->get_var( $wpdb->prepare( 'SELECT comment_approved FROM %i WHERE comment_ID = %d', $wpdb->comments, $id ) );
		if ( 'spam' !== $stored ) {
			$failures[] = sprintf( 'the %s comment reads %s in the database, expected spam', $key, var_export( $stored, true ) );
		}
		WP_Comment::get_instance( $id );
		$cached = wp_cache_get( $id, 'comment' );
		if ( ! is_object( $cached ) || 'spam' !== $cached->comment_approved ) {
			$failures[] = sprintf( 'the %s comment does not read spam in the comment object cache', $key );
		}
	}
} catch ( Throwable $e ) {
	$failures[] = 'threw ' . get_class( $e ) . ': ' . $e->getMessage();
} finally {
	unset( $_COOKIE[ $cookie_name ], $_COOKIE[ $cookie_email ], $_GET['unapproved'], $_GET['moderation-hash'] );
	foreach ( array_reverse( $ids ) as $id ) {
		if ( $id > 0 ) {
			wp_delete_comment( $id, true );
		}
	}
	if ( $post_id > 0 ) {
		wp_delete_post( $post_id, true );
	}
	wp_reset_postdata();
}

if ( ! empty( $failures ) ) {
	fwrite( STDERR, "shadow-check: FAIL\n" );
	foreach ( $failures as $f ) {
		fwrite( STDERR, '  - ' . $f . "\n" );
	}
	exit( 1 );
}

echo "shadow-check: OK — the author sees both flagged comments as held on the classic and block paths, by cookie and by a valid moderation-hash pair, nobody else (nor an invalid or expired pair) sees any spam, storage still reads spam\n";
exit( 0 );
