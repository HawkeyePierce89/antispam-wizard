<?php
/**
 * Learning from moderation decisions.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Trains the classifier from every WordPress comment status transition.
 *
 * There is exactly one training point: `transition_comment_status`. Whatever
 * moves a comment into or out of the spam queue -- the Comments-screen row
 * action, a bulk action, Akismet, the plugin's own Spam Log pill, or the
 * "Mark as spam" link this class renders on the site -- ends up firing it, so a
 * moderator's decision teaches the classifier no matter where it was made.
 *
 * Training is locked once per comment and category by the Spam Log's
 * `trained_as` flag (claimed atomically under the log's file lock), so the AJAX
 * pill and the transition it triggers together store exactly one sample, in
 * either order. The claim and the sample write together are serialised by a
 * second lock keyed on the comment body -- the sample table is addressed by
 * (script, category, content_hash), so the body is what a commit can collide
 * over -- and the comment's current status is re-read inside it, so of two
 * overlapping moderations -- a "Spam" click and the "Undo" that overtakes it --
 * the stale one stands down instead of writing its category over the newer
 * decision.
 */
class ANTISPAMWIZARD_Moderation {

    /**
     * Check name recorded on a Spam Log entry this class creates.
     *
     * A comment the plugin never blocked has no log entry, so marking it as spam
     * creates one; this name is what the Spam Log view badges as
     * "Marked by moderator".
     */
    const CHECK_NAME = 'manual';

    /**
     * admin-post.php action name behind the "Mark as spam" link.
     */
    const ACTION = 'antispamwizard_mark_spam';

    /**
     * Return the directory used for lock files, creating it if necessary.
     *
     * The same helper the plugin's other lock-taking classes carry: the uploads
     * directory rather than sys_get_temp_dir(), so the lock files live in a
     * directory owned by the web-server user and are not shared with other OS
     * accounts. uninstall.php removes the directory and everything in it.
     *
     * @return string Absolute path to the lock directory.
     */
    private static function get_lock_dir(): string {
        $upload_dir = wp_upload_dir();
        $lock_dir   = $upload_dir['basedir'] . '/.antispamwizard-locks';
        if ( ! is_dir( $lock_dir ) ) {
            wp_mkdir_p( $lock_dir );
            // Prevent direct web access to the lock directory.
            $htaccess = $lock_dir . '/.htaccess';
            if ( ! file_exists( $htaccess ) ) {
                @file_put_contents( $htaccess, "# Apache 2.4+\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n\n# Apache 2.2\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n" );
            }
            $index = $lock_dir . '/index.php';
            if ( ! file_exists( $index ) ) {
                @file_put_contents( $index, "<?php\n// Silence is golden.\n" );
            }
        }
        return $lock_dir;
    }

    /**
     * Run a critical section under the per-body moderation lock.
     *
     * Every writer of a training sample takes it -- this class's transition
     * handler and the Spam Log's AJAX pill, ANTISPAMWIZARD_Admin_Page::ajax_mark_comment()
     * -- because they all address the sample table by the same key.
     *
     * That key is the *content*, not the comment id: insert() and
     * delete_by_hash() are keyed by (script, category, content_hash) and the
     * script is a function of the content, so hashing the normalised body covers
     * exactly the rows one commit can touch. Keying by comment id instead left
     * two comments carrying identical text -- routine for a bot posting one body
     * from many addresses -- moderated in opposite directions under *different*
     * locks: both would insert their category and then each delete the other's
     * row, leaving the text trained as neither while both log claims stood. The
     * same comment still serialises against itself, since its two transitions
     * carry the same body.
     *
     * A caller must not change a comment's status inside $critical. WordPress
     * fires transition_comment_status synchronously from wp_update_comment(),
     * on_transition() takes this same lock through a second file handle, and
     * flock() associates a lock with the open file description rather than with
     * the process -- so the nested acquisition would block on the outer one for
     * the rest of the request.
     *
     * The bucket is a bounded pool of 256 files, as ANTISPAMWIZARD_Duplicate_Check's is, so
     * the lock directory cannot grow one file per comment. Bodies that share a
     * bucket only wait for each other.
     *
     * @param string   $content  The normalised comment body being trained.
     * @param callable $critical The section to run under the lock.
     * @return mixed Whatever $critical returns.
     */
    public static function with_body_lock( string $content, callable $critical ) {
        $lock_file = self::get_lock_dir() . '/antispamwizard_moderation_' . md5( ABSPATH ) . '_' . substr( md5( $content ), -2 ) . '.lock';
        $fp        = @fopen( $lock_file, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- flock() needs a real stream handle; WP_Filesystem exposes no locking primitive. The @ suppresses PHP's own "failed to open stream" warning, which carries the absolute path into the web-readable debug.log; the sanitized diagnostic below reports the failure instead.
        if ( false === $fp ) {
            // The file name only, never the absolute path: moderation fires from
            // front-end and cron paths as well as the admin screens, and
            // debug.log is web-readable on misconfigured hosts. Same rule as
            // ANTISPAMWIZARD_Detector::run().
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational diagnostic: the lock file could not be opened, so this commit proceeds unserialised. Silent failure would make concurrent-write corruption undebuggable; the log line carries no user input.
            error_log( 'ANTISPAMWIZARD: unable to open lock file: ' . basename( $lock_file ) );
        }

        // Fail open, like every other lock site in the plugin: a host without
        // flock() must keep learning from moderation rather than stop.
        $locked = $fp && flock( $fp, LOCK_EX );

        try {
            return $critical();
        } finally {
            // The release must sit in a finally: commit_training() rethrows after
            // rolling a claim back, and add_entry()/claim_training() run
            // third-party option filters that can throw, so a release placed
            // after the call would leave this bucket's exclusive lock held -- and
            // every other moderation of a comment in it blocked -- for the rest
            // of the request.
            if ( $locked ) {
                flock( $fp, LOCK_UN );
            }
            if ( $fp ) {
                fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the flock() stream handle opened above; WP_Filesystem exposes no locking primitive.
            }
        }
    }

    /**
     * Register the moderation hooks.
     */
    public function register_hooks(): void {
        add_action( 'transition_comment_status', array( $this, 'on_transition' ), 10, 3 );

        // Priority 100: core hangs convert_smilies (20), force_balance_tags (25),
        // wpautop (30) and capital_P_dangit (31) on comment_text, so a <p><a> block
        // appended at 10 would be re-processed by wpautop.
        add_filter( 'comment_text', array( $this, 'append_moderation_link' ), 100, 2 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
        add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_mark_spam' ) );
        // Registered for logged-out requests too, so an expired session gets this
        // handler's own 403 rather than WordPress's generic "invalid action".
        add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle_mark_spam' ) );
    }

    /**
     * Train the classifier from a comment status transition.
     *
     * @param string $new_status Normalized new status (approved, unapproved, spam, trash).
     * @param string $old_status Normalized previous status.
     * @param object $comment    The comment that moved.
     */
    public function on_transition( $new_status, $old_status, $comment ): void {
        try {
            $this->train( (string) $new_status, (string) $old_status, $comment );
        } catch ( \Throwable $e ) {
            // Fail open, as ANTISPAMWIZARD_Detector::run() and ANTISPAMWIZARD_CF7_Integration::filter_spam()
            // do: this runs inside wp_set_comment_status(), and add_entry() /
            // claim_training() call update_option(), so a third-party option filter
            // or a $wpdb failure throwing here would fatal the Comments screen in
            // the middle of a moderation action. The message is never logged -- it
            // can carry a query, an absolute path or a credential into a
            // debug.log that is web-readable on misconfigured hosts.
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational diagnostic for a swallowed exception; names the throw site only, never the message.
            error_log( 'ANTISPAMWIZARD: moderation training failed at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
        }
    }

    /**
     * The training itself, wrapped by on_transition()'s fail-open catch.
     *
     * @param string $new_status Normalized new status.
     * @param string $old_status Normalized previous status.
     * @param object $comment    The comment that moved.
     */
    private function train( string $new_status, string $old_status, $comment ): void {
        $category = $this->category_for_transition( $new_status, $old_status );
        if ( '' === $category ) {
            return;
        }

        if ( ! is_object( $comment ) ) {
            return;
        }

        $comment_id = isset( $comment->comment_ID ) ? (int) $comment->comment_ID : 0;
        if ( $comment_id <= 0 ) {
            return;
        }

        // Same scope as ANTISPAMWIZARD_Detector::filter_comment(), which refuses any type but
        // 'comment': a pingback or trackback body is a site title and a post
        // excerpt, is never classified by the plugin, and bulk-spamming a batch of
        // them would fill the corpus with text detection never sees.
        $type = isset( $comment->comment_type ) ? (string) $comment->comment_type : '';
        if ( '' !== $type && 'comment' !== $type ) {
            return;
        }

        $raw        = isset( $comment->comment_content ) ? (string) $comment->comment_content : '';
        $normalized = preg_replace( '/(*UCP)\s+/u', ' ', $raw );
        $content    = trim( null !== $normalized ? $normalized : $raw );
        if ( '' === $content ) {
            return;
        }

        // Serialise the whole claim-and-commit against every other moderation
        // request that could write the same sample. Two overlapping transitions
        // -- the Comments screen's "Spam" row action and the "Undo" click that
        // overtakes it -- otherwise interleave past the claim: each claims in
        // turn, and the one that claimed first can still insert its category and
        // delete the other's afterwards, leaving the comment and the log saying
        // one thing while the sample table says the other. The lock alone would
        // not settle it, which is why transition_is_current() runs inside it:
        // WordPress writes the comment row before firing this hook, so whichever
        // callback takes the lock second reads the newer status and stands down.
        self::with_body_lock(
            $content,
            function () use ( $comment_id, $new_status, $category, $content, $comment ): void {
                // A callback whose transition has already been superseded teaches
                // the opposite of what the site now says, so it stands down and
                // leaves the newer decision alone.
                if ( ! $this->transition_is_current( $comment_id, $new_status ) ) {
                    return;
                }

                $this->commit_training( $comment_id, $category, $content, $comment );
            }
        );
    }

    /**
     * Claim the comment and store the sample, inside the per-body lock.
     *
     * @param int    $comment_id The comment being trained from.
     * @param string $category   'spam' or 'ham'.
     * @param string $content    The normalised comment body.
     * @param object $comment    The comment that moved.
     */
    private function commit_training( int $comment_id, string $category, string $content, $comment ): void {
        // A comment that passed every check has no log entry -- the case that most
        // needs teaching. Record what the moderator did so the Spam Log shows it
        // and its pill offers a retry if the sample write below fails. Marking as
        // ham never creates an entry: the plugin has nothing to answer for there.
        if ( 'spam' === $category && array() === ANTISPAMWIZARD_Spam_Log::get_entries_by_comment_ids( array( $comment_id ) ) ) {
            $ip      = isset( $comment->comment_author_IP ) ? (string) $comment->comment_author_IP : '';
            $ip_hash = ( '' === $ip ) ? '' : ANTISPAMWIZARD_Detector::hash_ip( $ip );
            ANTISPAMWIZARD_Spam_Log::add_entry( $content, array( self::CHECK_NAME ), $ip_hash, $comment_id );
        }

        // The lock. 'already_trained' means the log says this comment is already
        // a sample in this category -- the AJAX pill claimed it and then fired
        // the very transition being handled, or the reverse.
        $claim = ANTISPAMWIZARD_Spam_Log::claim_training( $comment_id, $category );

        // The entry exists but the option write that would have claimed it was
        // refused, so the log still names the previous category. Training anyway
        // would flip the table -- insert this category, delete the other -- under
        // a log that contradicts it, and a later transition back would then read
        // 'already_trained' and store nothing, making the disagreement permanent.
        // Nothing was written, so there is nothing to roll back; the next
        // moderation retries.
        if ( 'failed' === $claim ) {
            return;
        }

        $opposite = ( 'spam' === $category ) ? 'ham' : 'spam';

        $repository = ANTISPAMWIZARD::get_instance()->get_repository();

        // A null repository claimed but can store nothing, so the claim is undone
        // exactly as a failed write undoes it. roll_back_claim() is a no-op for
        // 'already_trained' and 'not_found', neither of which wrote anything.
        if ( null === $repository ) {
            $this->roll_back_claim( $comment_id, $claim, $opposite );
            return;
        }

        $committed    = false;
        $content_hash = hash( 'sha256', $content );

        try {
            $script = ANTISPAMWIZARD_Script_Detector::detect( $content );

            // 'already_trained' is the lock, but it is believed only when the table
            // really agrees with it: the claimed category stored, and the opposite
            // one not. A rollback whose own option write was refused leaves the flag
            // standing over a training that never happened, and with the option store
            // refusing writes there is no way to clear it: every later transition
            // would read 'already_trained' and return, and the Spam Log pill refuses
            // it too (mark_trained() will not overwrite a flag that is already set),
            // so that comment could never be trained again. Requiring the opposite
            // row to be gone covers the other half: a flip whose delete_by_hash() was
            // refused rolled the log back to the old category while the new
            // category's row was already inserted, and the next teaching transition is
            // necessarily back to that old category -- so short-circuiting on the
            // claimed row alone would leave the text trained as both categories for
            // good, which is the state the flip delete exists to prevent. Falling
            // through re-runs an insert() that deduplicates to 'exists' and a
            // delete_by_hash() that finally clears the contradiction. Verifying costs
            // one indexed lookup on a path that only runs when a comment is moderated
            // twice in the same direction -- the pill and the transition it fires
            // being the usual pair -- and the failure direction is a redundant
            // insert(), never a lost one. Nothing was claimed here (the flag already
            // named this category), so roll_back_claim() has nothing to undo if the
            // write below then fails.
            if ( 'already_trained' === $claim && $this->training_is_settled( $repository, $script, $content_hash, $category, $opposite ) ) {
                return;
            }

            // Secondary subject key for the Privacy API: the comment a sample is
            // trained from is routinely hard-deleted (emptying the spam queue), and
            // comment_id alone would then no longer reach the stored text.
            $email      = isset( $comment->comment_author_email ) ? (string) $comment->comment_author_email : '';
            $email_hash = ANTISPAMWIZARD_Detector::hash_email( $email );

            $inserted = $repository->insert( $script, $category, $content, $comment_id, $email_hash );

            // A flip replaces the previous sample rather than leaving the same text
            // trained as both categories. Deleting only after a successful insert is
            // deliberate: a failed insert must never leave the table short a row.
            // Its false return is a failed DELETE, not "nothing matched", so it
            // counts as an uncommitted training: leaving the claim standing would
            // keep the text trained as both categories for good, since every later
            // transition would come back 'already_trained'.
            $committed = ( false !== $inserted )
                && $repository->delete_by_hash( $script, $opposite, $content_hash );
        } catch ( \Throwable $e ) {
            // A throw past the claim -- a $wpdb failure, a third-party filter --
            // would otherwise leave the entry marked while no sample was stored,
            // and every later transition would return 'already_trained', so the
            // loss would be permanent. Roll back, then rethrow so on_transition()
            // still writes its sanitized diagnostic.
            $this->roll_back_claim( $comment_id, $claim, $opposite );
            throw $e;
        }

        if ( ! $committed ) {
            $this->roll_back_claim( $comment_id, $claim, $opposite );
        }
    }

    /**
     * Does the table agree with an 'already_trained' claim?
     *
     * True only when this text is stored in the claimed category and not in the
     * opposite one -- a row in both is a flip whose delete was refused, and it
     * has to be cleaned up rather than skipped.
     *
     * The question is asked of the *sample identity* -- (script, content_hash),
     * the unique key insert() and delete_by_hash() write -- and not of the
     * comment, because the two are not the same row. Content dedup keeps only the
     * first comment's comment_id, so a second comment carrying identical text (a
     * bot posting one body from many addresses, routine) trains the very row the
     * first one created. Asking by comment_id there answered about a row this
     * commit does not write: the opposite-category row left by a refused flip
     * delete could belong to the other comment, be invisible to the lookup, and
     * the claim would be reported settled while the text stayed trained as both
     * categories for good -- exactly the state the flip delete exists to prevent.
     *
     * One query answers both questions; a failed read comes back as an empty
     * array, which reports "not settled" and costs a redundant training attempt
     * rather than skipping one -- insert() deduplicates by content hash, so the
     * retry is a no-op when the sample is in fact there. An opposite-category row
     * left from a body that has since been edited carries a different hash, so it
     * is neither seen here nor touched by the delete_by_hash() the fall through
     * runs.
     *
     * @param ANTISPAMWIZARD_Sample_Repository $repository   The sample store.
     * @param string                $script       Script group of the content.
     * @param string                $content_hash SHA-256 hash of the content.
     * @param string                $category     'spam' or 'ham'.
     * @param string                $opposite     The other category.
     * @return bool
     */
    private function training_is_settled( ANTISPAMWIZARD_Sample_Repository $repository, string $script, string $content_hash, string $category, string $opposite ): bool {
        $stored = false;

        foreach ( $repository->find_categories_by_hash( $script, $content_hash ) as $row ) {
            if ( $opposite === $row ) {
                return false;
            }

            if ( $category === $row ) {
                $stored = true;
            }
        }

        return $stored;
    }

    /**
     * Append a "Mark as spam" link under a comment on the site.
     *
     * Returns $text byte-identical unless every condition holds. The capability
     * is checked first so an anonymous page load pays one capability call and
     * nothing else. The link carries no JavaScript: it is a nonced GET to
     * admin-post.php.
     *
     * @param mixed  $text    The rendered comment text.
     * @param object $comment The comment being rendered.
     * @return mixed
     */
    public function append_moderation_link( $text, $comment = null ) {
        // The plugin does not declare strict_types, but a `string` parameter on a
        // userland function still TypeErrors on null -- and an earlier
        // comment_text filter returning null is an ordinary third-party bug. This
        // runs outside any fail-open catch, so that would fatal the whole post
        // page. Same rule as ANTISPAMWIZARD_Detector's is_string() guards.
        if ( ! is_string( $text ) ) {
            return $text;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return $text;
        }

        // The Comments screen renders comment_text too, and it has its own row
        // actions; a feed or a REST response is not a page anyone clicks a link on.
        if ( is_admin() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return $text;
        }

        if ( ! is_object( $comment ) ) {
            return $text;
        }

        $comment_id = isset( $comment->comment_ID ) ? (int) $comment->comment_ID : 0;
        if ( $comment_id <= 0 ) {
            return $text;
        }

        $approved = isset( $comment->comment_approved ) ? (string) $comment->comment_approved : '';
        // A shadow-displayed comment is presented as '0' but is really spam;
        // decide on the real status.
        if ( 'spam' === $approved || ANTISPAMWIZARD_Shadow_Display::is_shadowed( $comment_id ) ) {
            return $text;
        }

        // The nonce is bound to this one comment, so a link harvested from one
        // comment cannot be replayed against another.
        $url = wp_nonce_url(
            add_query_arg(
                array(
                    'action'     => self::ACTION,
                    'comment_id' => $comment_id,
                ),
                admin_url( 'admin-post.php' )
            ),
            self::ACTION . '_' . $comment_id
        );

        return $text . '<p class="antispamwizard-moderate"><a class="antispamwizard-moderate-link" href="' . esc_url( $url ) . '">'
            . esc_html__( 'Mark as spam', 'antispam-wizard' ) . '</a></p>';
    }

    /**
     * Enqueue the stylesheet that carries the link's rule.
     *
     * Shares the `antispamwizard-comment-form` handle with ANTISPAMWIZARD_Comment_Form, so a page that
     * renders both loads the file once.
     */
    public function enqueue_styles(): void {
        if ( ! current_user_can( 'manage_options' ) || ! is_singular() ) {
            return;
        }

        wp_enqueue_style(
            'antispamwizard-comment-form',
            ANTISPAMWIZARD_URL . 'public/css/comment-form.css',
            array(),
            ANTISPAMWIZARD_VERSION
        );
    }

    /**
     * Handle the "Mark as spam" link.
     *
     * Capability first, then the nonce, then the comment: an unauthorized
     * request is refused before the nonce store is consulted. The handler does
     * no training of its own -- wp_spam_comment() fires the transition, and
     * on_transition() above is the single training point.
     */
    public function handle_mark_spam(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to moderate comments.', 'antispam-wizard' ), '', array( 'response' => 403 ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The comment id is what the nonce action is built from, so it necessarily precedes the check_admin_referer() call on the next line; it is cast to int before any use.
        $comment_id = isset( $_GET['comment_id'] ) ? (int) $_GET['comment_id'] : 0;

        check_admin_referer( self::ACTION . '_' . $comment_id );

        $comment = get_comment( $comment_id );
        if ( ! $comment ) {
            wp_die( esc_html__( 'Comment not found.', 'antispam-wizard' ), '', array( 'response' => 404 ) );
        }

        if ( 'spam' !== (string) $comment->comment_approved && ! wp_spam_comment( $comment_id ) ) {
            // Redirecting anyway would send the moderator back to a page where the
            // comment is still visible and nothing was trained, with no sign that
            // the click did nothing. The msgid is the one ANTISPAMWIZARD_Admin_Page already
            // ships for the same failure on the Spam Log pill, so this adds no POT
            // entry.
            wp_die( esc_html__( 'Comment status could not be changed. Please try again.', 'antispam-wizard' ), '', array( 'response' => 500 ) );
        }

        $permalink = get_permalink( (int) $comment->comment_post_ID );
        wp_safe_redirect( $permalink ? $permalink : home_url( '/' ) );

        // @codeCoverageIgnoreStart
        if ( ! defined( 'ANTISPAMWIZARD_TESTING' ) ) {
            exit;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Is this transition still the site's current verdict on the comment?
     *
     * WordPress writes the comment row and then fires transition_comment_status,
     * so a callback that finds a different status on the row is working from a
     * decision another request has already replaced -- the "Spam" click whose
     * "Undo" overtook it. Read inside the per-comment lock, that makes the loser
     * of two overlapping moderations stand down instead of committing its
     * category over the winner's.
     *
     * A comment that can no longer be read at all -- hard-deleted between the
     * transition and this call, by a concurrent request or by an earlier hook on
     * this same action -- stands down too, rather than failing open. The Spam Log
     * entry this would create is keyed by comment_id alone (unlike a sample,
     * which also carries the salted email_hash, and unlike a form entry), and
     * ANTISPAMWIZARD_Privacy reaches those entries only by looking the subject's comments
     * up: an entry written for a row that no longer exists could never be
     * exported or erased, and its fragment and IP hash would sit in the log
     * beyond the reach of the requests readme.txt promises they answer. Its pill
     * would be dead as well -- ANTISPAMWIZARD_Admin_Page::ajax_mark_comment() refuses a
     * comment it cannot load. There is no moderator decision left to preserve
     * either way: the comment is gone.
     *
     * @param int    $comment_id The comment that moved.
     * @param string $new_status The normalized status this callback was fired for.
     * @return bool
     */
    private function transition_is_current( int $comment_id, string $new_status ): bool {
        // The read has to reach the database. get_comment() answers from the
        // object cache (WP_Comment::get_instance()), and WordPress's default
        // cache is request-local: wp_set_comment_status() repopulated this
        // request's copy from its own write just before firing the hook, so
        // without dropping it this method would compare $new_status against the
        // very row that produced it and always agree -- the concurrent request
        // whose newer decision it exists to notice ran in another process and
        // could not touch this cache. clean_comment_cache() is what core calls
        // for the same purpose after it writes the row; it costs one query on a
        // path that only runs for an actual spam/ham moderation.
        clean_comment_cache( $comment_id );

        $current = get_comment( $comment_id );
        if ( ! is_object( $current ) || ! isset( $current->comment_approved ) ) {
            return false;
        }

        return $this->normalize_status( (string) $current->comment_approved ) === $new_status;
    }

    /**
     * Normalize a stored comment_approved value the way WordPress does before it
     * fires transition_comment_status(): 1/approve => approved, 0/hold =>
     * unapproved, spam and trash unchanged.
     *
     * @param string $approved The raw comment_approved column value.
     * @return string
     */
    private function normalize_status( string $approved ): string {
        switch ( $approved ) {
            case '1':
            case 'approve':
            case 'approved':
                return 'approved';
            case '0':
            case 'hold':
            case 'unapproved':
                return 'unapproved';
            default:
                return $approved;
        }
    }

    /**
     * Map a status transition to a training category.
     *
     * @param string $new_status Normalized new status.
     * @param string $old_status Normalized previous status.
     * @return string 'spam', 'ham', or '' when this transition teaches nothing.
     */
    private function category_for_transition( string $new_status, string $old_status ): string {
        if ( 'spam' === $new_status && 'spam' !== $old_status ) {
            return 'spam';
        }
        // Leaving the spam queue for anything but the trash is a "Not Spam"
        // decision, and it does not always land on 'approved':
        // wp_unspam_comment() restores the status recorded in the
        // _wp_trash_meta_status comment meta and falls back to '0' (unapproved)
        // when that meta is absent. It is absent for exactly the comments this
        // plugin flagged -- detection returns 'spam' from pre_comment_approved,
        // so the row is inserted as spam and never passes through
        // wp_spam_comment(), which is what writes that meta. Matching only
        // 'approved' would therefore miss the most valuable ham signal there is:
        // a moderator correcting one of the plugin's own false positives.
        if ( 'spam' === $old_status && ( 'approved' === $new_status || 'unapproved' === $new_status ) ) {
            return 'ham';
        }
        return '';
    }

    /**
     * Undo a training claim after the sample write failed -- a refused insert, a
     * refused flip delete, or a throw past the claim -- so a failure always
     * leaves a retry open instead of a permanent 'already_trained'.
     *
     * A 'flipped' claim is restored to the previous category rather than
     * cleared: the opposite category's sample is only deleted after a successful
     * insert, so it is still stored, and an untrained-looking entry would
     * misreport the table. The next moderation retries in either case.
     *
     * @param int    $comment_id The comment whose claim is being undone.
     * @param string $claim      What claim_training() returned ('ok' or 'flipped';
     *                           anything else has nothing to undo).
     * @param string $opposite   The category the entry carried before a flip.
     */
    private function roll_back_claim( int $comment_id, string $claim, string $opposite ): void {
        if ( 'ok' === $claim ) {
            $rolled_back = ANTISPAMWIZARD_Spam_Log::unmark_trained( $comment_id );
        } elseif ( 'flipped' === $claim ) {
            $rolled_back = ANTISPAMWIZARD_Spam_Log::restore_training( $comment_id, $opposite );
        } else {
            return;
        }

        if ( ! $rolled_back ) {
            // The rollback write was refused, so the claim now stands over a
            // training that never happened. There is no second write to try --
            // the option store is what just refused one -- and the Spam Log pill
            // cannot clear it either, because mark_trained() refuses an entry
            // that already carries a category. What recovers it is the
            // verification in commit_training(): the next moderation of this
            // comment reads 'already_trained', finds no sample behind it and
            // trains anyway. Until then this comment is contributing nothing,
            // and no other signal on the site would show that, so it is logged.
            // The id is an int and carries no user input.
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational diagnostic: a refused rollback silently blocks all further training of one comment, which is otherwise invisible.
            error_log( 'ANTISPAMWIZARD: moderation training rollback failed for comment ' . $comment_id );
        }
    }
}
