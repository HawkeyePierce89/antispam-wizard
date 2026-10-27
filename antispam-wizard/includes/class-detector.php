<?php
/**
 * Spam detector that orchestrates all checks.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Runs registered spam checks against comment data and integrates with WordPress comment flow.
 */
class ANTISPAMWIZARD_Detector {

    /**
     * Maximum comment length in characters (64 KB).
     * Comments exceeding this are truncated before checks run to prevent DoS.
     */
    const MAX_COMMENT_LENGTH = 65536;

    /** @var ANTISPAMWIZARD_Check[]|null */
    private $checks;

    /** @var callable|null */
    private $checks_builder;

    /**
     * @param ANTISPAMWIZARD_Check[]|callable $checks Array of check instances or a callable that returns them.
     */
    public function __construct( $checks ) {
        if ( is_callable( $checks ) ) {
            $this->checks_builder = $checks;
            $this->checks         = null;
        } else {
            $this->checks = $checks;
        }
    }

    /**
     * Get checks, building them lazily if a builder was provided.
     *
     * @return ANTISPAMWIZARD_Check[]
     */
    private function get_checks(): array {
        if ( null === $this->checks ) {
            $this->checks = ( $this->checks_builder )();
            $this->checks_builder = null;
        }
        return $this->checks;
    }

    /**
     * Run all checks against comment data.
     *
     * Stops after the first spam result (short-circuit). If a check throws,
     * it is logged and skipped (fail-open).
     *
     * @param array $comment_data WordPress comment data array.
     * @return ANTISPAMWIZARD_Check_Result[] Array of results from all checks.
     */
    public function run( array $comment_data ): array {
        $results = array();
        foreach ( $this->get_checks() as $check ) {
            try {
                $result = $check->check( $comment_data );
            } catch ( \Throwable $e ) {
                // The exception MESSAGE is deliberately omitted: a check that wraps
                // $wpdb, file I/O or an HTTP client can carry queries, credentials or
                // arbitrary filesystem paths in it, and debug.log is web-readable on
                // misconfigured hosts. The class name plus the throw site is enough to
                // attribute a broken check without disclosing its payload. The throw
                // site is reduced to basename(): the exception need not originate in
                // this plugin - a $wpdb failure reports wp-includes/class-wpdb.php and
                // a throw from another plugin's filter reports its own tree - so the
                // full path would leak the document root into the same log the message
                // was dropped to protect. The file name alone still attributes it.
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fail-open diagnostic: an individual check threw and was skipped. Silently swallowing it would hide a broken check on a live site; only the check class name and the throw site are recorded.
                error_log( 'AntiSpam Wizard: check ' . get_class( $check ) . ' failed at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
                continue;
            }
            $results[] = $result;
            if ( $result->is_spam() ) {
                break;
            }
        }
        return $results;
    }

    /**
     * Hash an IP address with SHA-256.
     *
     * @param string $ip Raw IP address.
     * @return string Hashed IP.
     */
    public static function hash_ip( string $ip ): string {
        $salt = wp_salt( 'auth' );
        return hash( 'sha256', $salt . $ip );
    }

    /**
     * Hash an email address with SHA-256 using the WordPress auth salt.
     *
     * Normalization (trim + strtolower) must be identical on the write and
     * lookup sides so that the same email always produces the same hash
     * regardless of case or surrounding whitespace. Returns an empty string
     * for an empty or whitespace-only input so a missing email never produces
     * a matchable hash.
     *
     * @param string $email Raw email address.
     * @return string SHA-256 hash or empty string for empty input.
     */
    public static function hash_email( string $email ): string {
        $normalized = strtolower( trim( $email ) );
        if ( '' === $normalized ) {
            return '';
        }
        return hash( 'sha256', wp_salt( 'auth' ) . $normalized );
    }

    /**
     * Drop malformed UTF-8, replacing each invalid sequence with '?'.
     *
     * mb_convert_encoding( $s, 'UTF-8', 'UTF-8' ) substitutes invalid sequences
     * with mbstring's configured substitute character, and the callers here rely
     * on that substitute being Common so script bucketing ignores it. The
     * compiled default is '?', but mbstring.substitute_character is a
     * process-wide runtime setting any other plugin can change with
     * mb_substitute_character(): given a letter codepoint (e.g. 65) a stray byte
     * in a Cyrillic body becomes 'A', which makes ANTISPAMWIZARD_Multi_Lang_Check see
     * Latin + Cyrillic and flag a legitimate single-script comment. (The named
     * settings are all safe: 'none' drops the invalid bytes outright, and
     * 'long' and 'entity' both fall back to '?' for this conversion; only a
     * codepoint value is a hazard.) Pin the substitute for the duration of the
     * conversion and restore whatever the site had, so the invariant holds
     * regardless of what else is loaded.
     *
     * @param string $text Possibly malformed UTF-8.
     * @return string Valid UTF-8.
     */
    public static function sanitize_utf8( string $text ): string {
        $previous = mb_substitute_character();
        mb_substitute_character( 0x3F );

        try {
            return mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
        } finally {
            mb_substitute_character( $previous );
        }
    }

    /**
     * Evaluate data through the source-agnostic detection pipeline.
     *
     * Order: global enabled gate, UTF-8 sanitization, truncation, IP hashing,
     * then run(). Collects spam results into check_names and reasons
     * ("<name>: <details>").
     *
     * @param array $data Comment-shaped data array.
     * @return array{is_spam: bool, check_names: string[], reasons: string[], data: array}
     */
    public function evaluate( array $data ): array {
        // Global kill switch — skip all checks when plugin is disabled.
        if ( ! ANTISPAMWIZARD_Settings::get( 'enabled' ) ) {
            return array(
                'is_spam'     => false,
                'check_names' => array(),
                'reasons'     => array(),
                'data'        => $data,
            );
        }

        // Drop malformed UTF-8 before any check sees the body. Every /u pattern
        // returns false/null on an invalid subject, and PCRE validates the whole
        // subject, so one stray byte anywhere in attacker-controlled text (the
        // comment body, or a Contact Form 7 field joined into it) would otherwise
        // switch off Emoji, Spacing, Bayesian and Similarity at once, blank the
        // Stop Words obfuscation stripping and make ANTISPAMWIZARD_Script_Detector::detect()
        // return 'unknown'. This is the single choke point both the comment and
        // the CF7 path pass through, so sanitizing here covers every check.
        // sanitize_utf8() substitutes the invalid sequences with '?', which
        // is Common and therefore ignored by script bucketing; do not swap in
        // wp_check_invalid_utf8( $s, true ), whose iconv() strip both fails and
        // writes a plugin-attributable warning into debug.log. Runs before the
        // truncation below so mb_strlen()/mb_substr() see valid UTF-8.
        // The is_string() guard also covers the truncation below: run() catches a
        // Throwable from an individual check, but this method's own mb_strlen()
        // does not run under that catch, so handing it a non-string
        // comment_content would fatal the whole comment route instead of failing
        // open. A non-string body simply skips both steps and fails open per
        // check.
        if ( isset( $data['comment_content'] ) && is_string( $data['comment_content'] ) ) {
            $data['comment_content'] = self::sanitize_utf8( $data['comment_content'] );

            // Truncate oversized content to prevent DoS via CPU-intensive checks.
            if ( mb_strlen( $data['comment_content'], 'UTF-8' ) > self::MAX_COMMENT_LENGTH ) {
                $data['comment_content'] = mb_substr( $data['comment_content'], 0, self::MAX_COMMENT_LENGTH, 'UTF-8' );
            }
        }

        // Hash the IP for checks; keep the original so other consumers still see it.
        // Same is_string() rule as the body above: hash_ip() is typed `string`, the
        // plugin does not declare strict_types, and this line runs outside run()'s
        // per-check catch - so an array handed in by another plugin filtering
        // pre_comment_approved would be an uncaught TypeError fataling the whole
        // comment route instead of failing open.
        if ( ! empty( $data['comment_author_IP'] ) && is_string( $data['comment_author_IP'] ) ) {
            $data['comment_author_IP_hashed'] = self::hash_ip( $data['comment_author_IP'] );
        }

        $results     = $this->run( $data );
        $check_names = array();
        $reasons     = array();

        foreach ( $results as $result ) {
            if ( $result->is_spam() ) {
                $check_names[] = $result->get_check_name();
                $reasons[]     = $result->get_check_name() . ': ' . $result->get_details();
            }
        }

        return array(
            'is_spam'     => ! empty( $check_names ),
            'check_names' => $check_names,
            'reasons'     => $reasons,
            'data'        => $data,
        );
    }

    /**
     * Register the pre_comment_approved filter.
     */
    public function register_hooks(): void {
        add_filter( 'pre_comment_approved', array( $this, 'filter_comment' ), 10, 2 );
    }

    /**
     * Filter callback for pre_comment_approved.
     *
     * @param string|int $approved    Current approval status.
     * @param array      $commentdata Comment data array.
     * @return string|int 'spam' if spam detected, otherwise pass through.
     */
    public function filter_comment( $approved, array $commentdata ) {
        // Respect upstream decisions — don't override spam, trash, or error status.
        if ( $approved instanceof \WP_Error || 'spam' === $approved || 'trash' === $approved ) {
            return $approved;
        }

        // Only check regular comments — skip pingbacks, trackbacks, and other types.
        if ( ! empty( $commentdata['comment_type'] ) && 'comment' !== $commentdata['comment_type'] ) {
            return $approved;
        }

        $evaluation = $this->evaluate( $commentdata );

        if ( ! $evaluation['is_spam'] ) {
            return $approved;
        }

        $check_names = $evaluation['check_names'];
        $reasons     = $evaluation['reasons'];
        $data        = $evaluation['data'];

        $ip_hash = isset( $data['comment_author_IP_hashed'] )
            ? $data['comment_author_IP_hashed']
            : '';
        // Same non-string body as evaluate()'s guard: a content-independent check
        // (honeypot, submit time) can flag spam without comment_content ever being
        // read, so the excerpt must be normalised here too — add_entry() takes a
        // string parameter and a TypeError here runs outside run()'s per-check catch.
        $excerpt = isset( $data['comment_content'] ) && is_string( $data['comment_content'] )
            ? $data['comment_content']
            : '';
        $entry_id = ANTISPAMWIZARD_Spam_Log::add_entry(
            $excerpt,
            $check_names,
            $ip_hash
        );

        // Store the spam reason and comment_id after the comment is created.
        // Use a one-shot pattern: remove the action after it fires to prevent attaching to unrelated comments.
        // Capture $entry_id so the correct log row is updated even under concurrent requests.
        $sanitized_reasons = array_map( 'wp_strip_all_tags', $reasons );
        $save_reason = function ( $comment_id ) use ( $sanitized_reasons, $entry_id, &$save_reason ) {
            update_comment_meta( $comment_id, '_antispamwizard_reason', implode( '; ', $sanitized_reasons ) );
            ANTISPAMWIZARD_Shadow_Display::record_flag( (int) $comment_id );
            ANTISPAMWIZARD_Spam_Log::update_entry_comment_id( $entry_id, (int) $comment_id );
            remove_action( 'comment_post', $save_reason );
        };
        add_action( 'comment_post', $save_reason );

        // Record statistics.
        ANTISPAMWIZARD_Stats::record( $check_names );

        return 'spam';
    }
}
