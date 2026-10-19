<?php
/**
 * Shadow display: a comment the plugin flagged is shown to its own author as
 * awaiting moderation, and to nobody else.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Widens the visitor's own front-end comment query with their auto-flagged spam.
 *
 * Hooks the WP_Comment_Query layer, so the block-theme path
 * (core/comment-template) and the classic path (comments_template() →
 * wp_list_comments()) are covered alike. A listing qualifies only when core
 * already put the current visitor's own identifier into `include_unapproved`;
 * the query's existing "approved" condition then gets one extra OR branch that
 * matches this visitor's `spam` comments carrying `_asw_reason`. The rows that
 * branch returned are presented to rendering as a per-request clone with
 * `comment_approved = '0'`; the stored row and the comment object cache keep
 * `spam`.
 */
class ASW_Shadow_Display {

    /**
     * Query var (and query_var_defaults key) that carries the visitor identity
     * into WP_Comment_Query's result-cache key.
     */
    const QUERY_VAR = 'asw_shadow';

    /**
     * Option holding the highest comment ID whose `_asw_reason` predates the
     * moderator rule: every flag above it was written by a version that drops
     * the meta once a moderator decides. Versions before this feature never
     * did, so their spam can carry the flag after a moderator confirmed it;
     * only comments above the cutoff are ever shown. Unset until the plugin
     * flags its first comment, and while unset nothing is shown.
     */
    const CUTOFF_OPTION = 'asw_shadow_display_after';

    /**
     * The queries pre_get_comments approved, keyed by the query object, each
     * mapped to its identity ( 'type' => 'email'|'user', 'value' => string|int,
     * 'after' => the CUTOFF_OPTION value ).
     * comments_clauses trusts only this map, never an incoming query var.
     *
     * @var WeakMap<object, array{type: string, value: string|int, after: int}>
     */
    private WeakMap $approved;

    /**
     * IDs of the comments this request presents as held, as keys. Their real
     * status is `spam`; is_shadowed() answers from here.
     *
     * @var array<int, true>
     */
    private static array $shadowed = array();

    /**
     * The visitors (identity + post_id) of this request's approved threaded
     * queries, keyed by "type:value:post_id". fill_descendants() may serve a
     * reply's ID from the comment-queries cache without running the nested
     * query, so such a reply reaches get_comment() without passing through
     * the_comments; on_get_comment() recognises it from here instead.
     *
     * @var array<string, array{type: string, value: string|int, after: int, post_id: int}>
     */
    private array $viewers = array();

    /**
     * Whether this instance has added its get_comment filter yet.
     */
    private bool $filter_added = false;

    public function __construct() {
        $this->approved = new WeakMap();
    }

    /**
     * Register the comment-query hooks. They gate themselves, so this is safe
     * to call on every request.
     */
    public function register_hooks(): void {
        add_action( 'pre_get_comments', array( $this, 'on_pre_get_comments' ), 10, 1 );
        add_filter( 'comments_clauses', array( $this, 'on_comments_clauses' ), 10, 2 );
        add_filter( 'the_comments', array( $this, 'on_the_comments' ), 10, 2 );
        add_action( 'transition_comment_status', array( $this, 'on_transition_comment_status' ), 10, 3 );
        // Late, so a filter that replaces the target runs first and the pair
        // lands on the URL the author is actually sent to.
        add_filter( 'comment_post_redirect', array( $this, 'on_comment_post_redirect' ), 100, 2 );
    }

    /**
     * Whether this request presents the comment as awaiting moderation while
     * its real status is `spam`. Decide on this, never on the presented
     * `comment_approved`.
     *
     * @param int $comment_id Comment ID.
     * @return bool
     */
    public static function is_shadowed( int $comment_id ): bool {
        return isset( self::$shadowed[ $comment_id ] );
    }

    /**
     * Decide whether this query is the current visitor's own front-end listing,
     * and if so, put the identity into the query's result-cache key.
     *
     * Whatever an earlier run or a caller left in `asw_shadow` is removed first,
     * so the marker only ever reflects this run's own decision.
     *
     * @param mixed $query The WP_Comment_Query about to run.
     */
    public function on_pre_get_comments( $query ): void {
        if ( ! $query instanceof WP_Comment_Query ) {
            return;
        }
        try {
            unset( $this->approved[ $query ] );
            if ( is_array( $query->query_vars ) ) {
                unset( $query->query_vars[ self::QUERY_VAR ] );
            }
            if ( is_array( $query->query_var_defaults ) ) {
                unset( $query->query_var_defaults[ self::QUERY_VAR ] );
            }

            $identity = $this->eligible_identity( $query );
            if ( null === $identity ) {
                return;
            }

            // A key of query_var_defaults is part of WP_Comment_Query's cache
            // key, so the widened result is never served to the plain query and
            // one visitor's widened result is never served to another.
            $query->query_var_defaults[ self::QUERY_VAR ] = '';
            $query->query_vars[ self::QUERY_VAR ]         = $identity['type'] . ':' . $identity['value'];
            $this->approved[ $query ]                     = $identity;
        } catch ( \Throwable $e ) {
            // The marker is written only after everything that can throw, so
            // dropping the approval is all that is left to undo.
            unset( $this->approved[ $query ] );
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational diagnostic for a swallowed exception; names the throw site only, never the message.
            error_log( 'ASW: shadow display failed at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
        }
    }

    /**
     * Widen an approved query's "approved" condition with the shadow branch.
     *
     * The condition is replaced only when it occurs exactly once in the WHERE;
     * anything else (absent, duplicated, rewritten by another filter) leaves the
     * clauses untouched.
     *
     * @param mixed $clauses The query's SQL clauses.
     * @param mixed $query   The WP_Comment_Query being built.
     * @return mixed
     */
    public function on_comments_clauses( $clauses, $query = null ) {
        if ( ! $query instanceof WP_Comment_Query || ! isset( $this->approved[ $query ] ) ) {
            return $clauses;
        }
        try {
            if ( ! is_array( $clauses ) || ! isset( $clauses['where'] ) || ! is_string( $clauses['where'] ) ) {
                return $clauses;
            }
            // Core keeps the approved condition it built in the protected
            // $sql_clauses, so it is reachable only through reflection.
            $property    = new ReflectionProperty( WP_Comment_Query::class, 'sql_clauses' );
            $sql_clauses = $property->getValue( $query );
            $approved    = is_array( $sql_clauses ) ? ( $sql_clauses['where']['approved'] ?? null ) : null;
            if ( ! is_string( $approved ) || '' === $approved ) {
                return $clauses;
            }
            if ( 1 !== substr_count( $clauses['where'], $approved ) ) {
                return $clauses;
            }

            $widened = '( ' . $approved . ' OR ' . $this->shadow_fragment( $this->approved[ $query ] ) . ' )';

            $clauses['where']                 = str_replace( $approved, $widened, $clauses['where'] );
            $sql_clauses['where']['approved'] = $widened;
            $property->setValue( $query, $sql_clauses );
            return $clauses;
        } catch ( \Throwable $e ) {
            unset( $this->approved[ $query ] );
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational diagnostic for a swallowed exception; names the throw site only, never the message.
            error_log( 'ASW: shadow display failed at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
            return $clauses;
        }
    }

    /**
     * Record which rows of an approved query are shadow rows.
     *
     * Only the shadow branch can return a `spam` row, so every `spam` row of
     * an approved query is one. The get_comment filter that presents them is
     * added the first time there is something to present -- or, for a
     * threaded query, straight away, because its replies may come from the
     * child-ID cache and never pass through here.
     *
     * @param mixed $comments The query's comment objects.
     * @param mixed $query    The WP_Comment_Query that fetched them.
     * @return mixed
     */
    public function on_the_comments( $comments, $query = null ) {
        if ( ! $query instanceof WP_Comment_Query || ! isset( $this->approved[ $query ] ) || ! is_array( $comments ) ) {
            return $comments;
        }
        try {
            foreach ( $comments as $comment ) {
                if ( is_object( $comment ) && isset( $comment->comment_ID, $comment->comment_approved ) && 'spam' === $comment->comment_approved ) {
                    self::$shadowed[ (int) $comment->comment_ID ] = true;
                }
            }
            $hierarchical = ! empty( $query->query_vars['hierarchical'] );
            if ( $hierarchical ) {
                $identity = $this->approved[ $query ];
                $post_id  = (int) $query->query_vars['post_id'];

                $this->viewers[ $identity['type'] . ':' . $identity['value'] . ':' . $post_id ] = $identity + array( 'post_id' => $post_id );
            }
            if ( ! $this->filter_added && ( $hierarchical || ! empty( self::$shadowed ) ) ) {
                // Both core/comment-content and fill_descendants() re-fetch
                // comments by ID, so only a get_comment filter reaches them.
                add_filter( 'get_comment', array( $this, 'on_get_comment' ), 10, 1 );
                $this->filter_added = true;
            }
            return $comments;
        } catch ( \Throwable $e ) {
            unset( $this->approved[ $query ] );
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational diagnostic for a swallowed exception; names the throw site only, never the message.
            error_log( 'ASW: shadow display failed at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
            return $comments;
        }
    }

    /**
     * Present a shadow row as awaiting moderation.
     *
     * Returns a clone with `comment_approved = '0'` for a `spam` comment that
     * is registered, or that is a threaded viewer's own flagged comment on
     * that post (registered here), and the input unchanged otherwise. The input
     * object -- which may be the one in the comment object cache -- is never
     * modified.
     *
     * @param mixed $comment The comment get_comment() resolved.
     * @return mixed
     */
    public function on_get_comment( $comment ) {
        try {
            if ( ! is_object( $comment ) || ! isset( $comment->comment_ID, $comment->comment_approved ) ) {
                return $comment;
            }
            if ( 'spam' !== $comment->comment_approved ) {
                return $comment;
            }
            $comment_id = (int) $comment->comment_ID;
            if ( ! self::is_shadowed( $comment_id ) ) {
                if ( ! $this->is_viewers_flagged_comment( $comment ) ) {
                    return $comment;
                }
                self::$shadowed[ $comment_id ] = true;
            }
            $presented                   = clone $comment;
            $presented->comment_approved = '0';
            return $presented;
        } catch ( \Throwable $e ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational diagnostic for a swallowed exception; names the throw site only, never the message.
            error_log( 'ASW: shadow display failed at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
            return $comment;
        }
    }

    /**
     * Send a cookie-less author of a plugin-flagged comment back with the
     * moderation pair core adds for a held one.
     *
     * wp-comments-post.php appends `unapproved=<ID>&moderation-hash=<hash>`
     * only for a `'0'` comment, and sets the commenter cookie only with
     * consent, so an author who left the box unticked would land on a page
     * that knows nothing about them. With the pair, core's
     * wp_get_unapproved_comment_author_email() resolves their email for ten
     * minutes and puts it into include_unapproved, and the query hooks take
     * over from there. A location that already carries either key is left
     * alone, so the pair is never duplicated or mismatched.
     *
     * @param mixed $location The redirect target.
     * @param mixed $comment  The comment just posted.
     * @return mixed
     */
    public function on_comment_post_redirect( $location, $comment = null ) {
        try {
            if ( ! is_string( $location ) || '' === $location || ! is_object( $comment ) ) {
                return $location;
            }
            if ( ! isset( $comment->comment_approved ) || 'spam' !== $comment->comment_approved ) {
                return $location;
            }
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Core's comment form carries no nonce; only the presence of the consent key is read, exactly as wp-comments-post.php reads it.
            if ( isset( $_POST['wp-comment-cookies-consent'] ) ) {
                return $location;
            }
            if ( 0 !== get_current_user_id() || 0 !== (int) ( $comment->user_id ?? 0 ) ) {
                return $location;
            }
            $email = (string) ( $comment->comment_author_email ?? '' );
            if ( '' === $email || ! is_email( $email ) ) {
                return $location;
            }
            $after = self::shadow_cutoff();
            if ( null === $after || ! self::is_plugin_flagged( $comment, $after ) ) {
                return $location;
            }
            wp_parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $existing );
            if ( isset( $existing['unapproved'] ) || isset( $existing['moderation-hash'] ) ) {
                return $location;
            }
            return add_query_arg(
                array(
                    'unapproved'      => (int) $comment->comment_ID,
                    'moderation-hash' => wp_hash( (string) $comment->comment_date_gmt ),
                ),
                $location
            );
        } catch ( \Throwable $e ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational diagnostic for a swallowed exception; names the throw site only, never the message.
            error_log( 'ASW: shadow display failed at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
            return $location;
        }
    }

    /**
     * Forget the plugin's verdict once a moderator overrules it.
     *
     * A comment leaving `spam` for approved or held no longer carries the
     * plugin's flag, so a moderator who later marks it spam again makes a
     * human decision that stays hidden from its author, like any other
     * moderator-marked spam.
     *
     * @param mixed $new_status New status.
     * @param mixed $old_status Old status.
     * @param mixed $comment    The comment.
     */
    public function on_transition_comment_status( $new_status, $old_status, $comment ): void {
        try {
            if ( 'spam' !== $old_status || ! in_array( $new_status, array( 'approved', 'unapproved' ), true ) ) {
                return;
            }
            if ( ! is_object( $comment ) || ! isset( $comment->comment_ID ) ) {
                return;
            }
            self::forget_verdict( (int) $comment->comment_ID );
        } catch ( \Throwable $e ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational diagnostic for a swallowed exception; names the throw site only, never the message.
            error_log( 'ASW: shadow display failed at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
        }
    }

    /**
     * Drop the plugin's flag from a comment a moderator has decided on, so it
     * is never shown to its author again. Called on an overruling transition
     * and by the Spam Log pill after either verdict: a comment confirmed as
     * spam while already in the spam queue fires no transition, nor does a Ham
     * verdict that restores a trashed comment, yet both are the same human
     * decision a moderator-marked spam or a Not Spam is.
     *
     * delete_comment_meta() returns false both for a refused DELETE and for a
     * flag that was already gone, so the outcome is read back: a flag still
     * present keeps the comment visible to its author and is logged.
     *
     * @param int $comment_id Comment ID.
     * @return bool False when the flag is still present.
     */
    public static function forget_verdict( int $comment_id ): bool {
        delete_comment_meta( $comment_id, '_asw_reason' );
        if ( empty( get_comment_meta( $comment_id, '_asw_reason' ) ) ) {
            return true;
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational diagnostic for a refused meta delete; names the comment ID only.
        error_log( 'ASW: shadow display flag could not be removed from comment ' . $comment_id );
        return false;
    }

    /**
     * Record the cutoff the first time this version flags a comment: every
     * `_asw_reason` on a lower ID was written before moderator decisions
     * started removing it (see CUTOFF_OPTION). add_option() never overwrites,
     * so later flags leave the first cutoff in place; a refused write is
     * retried on the next flag, and until then nothing is shown.
     *
     * @param int $comment_id The comment the plugin just flagged.
     */
    public static function record_flag( int $comment_id ): void {
        if ( $comment_id > 0 && false === get_option( self::CUTOFF_OPTION ) ) {
            add_option( self::CUTOFF_OPTION, $comment_id - 1 );
        }
    }

    /**
     * Whether a `spam` comment is the own plugin-flagged comment of a visitor
     * one of this request's threaded queries was approved for: the same test
     * the SQL branch applies (post, identity, type, cutoff, `_asw_reason`).
     *
     * @param object $comment The comment.
     * @return bool
     */
    private function is_viewers_flagged_comment( object $comment ): bool {
        if ( empty( $this->viewers ) ) {
            return false;
        }
        $post_id = (int) ( $comment->comment_post_ID ?? 0 );
        foreach ( $this->viewers as $viewer ) {
            if ( $viewer['post_id'] !== $post_id ) {
                continue;
            }
            $own = 'user' === $viewer['type']
                ? (int) ( $comment->user_id ?? 0 ) === (int) $viewer['value']
                : 0 === strcasecmp( (string) ( $comment->comment_author_email ?? '' ), (string) $viewer['value'] );
            if ( $own && self::is_plugin_flagged( $comment, $viewer['after'] ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The PHP mirror of shadow_fragment()'s comment conditions: a plain
     * comment (type `''` or `comment`) above the cutoff that still carries
     * the plugin's `_asw_reason` flag. Identity and status are the caller's.
     *
     * @param object $comment The comment.
     * @param int    $after   The CUTOFF_OPTION value.
     * @return bool
     */
    private static function is_plugin_flagged( object $comment, int $after ): bool {
        $type = (string) ( $comment->comment_type ?? '' );
        if ( '' !== $type && 'comment' !== $type ) {
            return false;
        }
        $comment_id = (int) ( $comment->comment_ID ?? 0 );
        if ( $comment_id <= $after ) {
            return false;
        }
        return ! empty( get_comment_meta( $comment_id, '_asw_reason' ) );
    }

    /**
     * The cutoff, when shadow display is on: the master `enabled` switch and
     * `shadow_display_enabled` both set and CUTOFF_OPTION recorded. Null
     * otherwise, and then nothing is shown.
     *
     * @return int|null
     */
    private static function shadow_cutoff(): ?int {
        $settings = ASW_Settings::get_all();
        if ( empty( $settings['enabled'] ) || empty( $settings['shadow_display_enabled'] ) ) {
            return null;
        }
        $after = get_option( self::CUTOFF_OPTION );
        return is_numeric( $after ) ? (int) $after : null;
    }

    /**
     * The visitor identity this query may be widened for, or null.
     *
     * Cheap query-var checks run first, so an anonymous visitor's query (empty
     * `include_unapproved`) returns before any option read.
     *
     * @param WP_Comment_Query $query The query.
     * @return array{type: string, value: string|int, after: int}|null
     */
    private function eligible_identity( WP_Comment_Query $query ): ?array {
        $vars = is_array( $query->query_vars ) ? $query->query_vars : array();

        if ( ! empty( $vars['count'] ) ) {
            return null;
        }
        if ( isset( $vars['fields'] ) && 'ids' === $vars['fields'] ) {
            return null;
        }
        if ( ! isset( $vars['status'] ) || 'approve' !== $vars['status'] ) {
            return null;
        }
        if ( ! isset( $vars['post_id'] ) || ! is_numeric( $vars['post_id'] ) || (int) $vars['post_id'] <= 0 ) {
            return null;
        }
        $include = wp_parse_list( $vars['include_unapproved'] ?? '' );
        if ( empty( $include ) ) {
            return null;
        }

        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() ) {
            return null;
        }

        $after = self::shadow_cutoff();
        if ( null === $after ) {
            return null;
        }

        // Mirror core's own include_unapproved entry: the user ID for a
        // logged-in visitor, otherwise the email core resolved for it (the
        // moderation-hash pair of the link it redirected to, else the
        // commenter cookie).
        $user_id = get_current_user_id();
        if ( $user_id > 0 ) {
            foreach ( $include as $identifier ) {
                if ( is_numeric( $identifier ) && (int) $identifier === $user_id ) {
                    return array(
                        'type'  => 'user',
                        'value' => $user_id,
                        'after' => $after,
                    );
                }
            }
            return null;
        }

        $email = wp_get_unapproved_comment_author_email();
        $email = is_string( $email ) ? $email : '';
        if ( '' === $email || ! is_email( $email ) || ! in_array( $email, $include, true ) ) {
            return null;
        }
        return array(
            'type'  => 'email',
            'value' => $email,
            'after' => $after,
        );
    }

    /**
     * The prepared OR branch: this visitor's auto-flagged spam comments above
     * the cutoff. is_plugin_flagged() is its PHP mirror; keep the two in step.
     *
     * @param array{type: string, value: string|int, after: int} $identity Visitor identity.
     * @return string
     */
    private function shadow_fragment( array $identity ): string {
        global $wpdb;

        if ( 'user' === $identity['type'] ) {
            return $wpdb->prepare(
                "( user_id = %d AND comment_approved = 'spam' AND comment_type IN ( '', 'comment' ) AND %i.comment_ID > %d AND EXISTS ( SELECT 1 FROM %i WHERE comment_id = %i.comment_ID AND meta_key = '_asw_reason' ) )",
                (int) $identity['value'],
                $wpdb->comments,
                (int) $identity['after'],
                $wpdb->commentmeta,
                $wpdb->comments
            );
        }
        return $wpdb->prepare(
            "( comment_author_email = %s AND comment_approved = 'spam' AND comment_type IN ( '', 'comment' ) AND %i.comment_ID > %d AND EXISTS ( SELECT 1 FROM %i WHERE comment_id = %i.comment_ID AND meta_key = '_asw_reason' ) )",
            (string) $identity['value'],
            $wpdb->comments,
            (int) $identity['after'],
            $wpdb->commentmeta,
            $wpdb->comments
        );
    }
}
