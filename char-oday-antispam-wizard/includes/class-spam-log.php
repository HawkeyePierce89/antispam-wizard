<?php
/**
 * Spam log storage.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Records and retrieves spam detection log entries stored in wp_options.
 */
class ANTISPAMWIZARD_Spam_Log {

    const OPTION_KEY  = 'antispamwizard_spam_log';
    const MAX_ENTRIES = 100;

    /**
     * Return the directory used for lock files, creating it if necessary.
     *
     * Uses the WordPress uploads directory instead of sys_get_temp_dir() so
     * lock files live in a directory owned by the web-server user and are not
     * shared with other OS accounts.
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
     * Add a log entry. Oldest entries are removed when the cap is exceeded (FIFO).
     *
     * @param string $text_excerpt     First 100 characters of the comment text.
     * @param array  $checks_triggered List of check names that flagged the comment.
     * @param string $ip_hash          SHA-256 hashed IP address.
     * @param int    $comment_id       WordPress comment ID (0 if not yet known).
     * @return string The unique entry ID for this log row.
     */
    public static function add_entry( string $text_excerpt, array $checks_triggered, string $ip_hash, int $comment_id = 0, string $source = 'comment', string $email_hash = '' ): string {
        $source = ( 'form' === $source ) ? 'form' : 'comment';
        if ( 'comment' === $source ) {
            $email_hash = '';
        }
        $lock_file = self::get_lock_dir() . '/antispamwizard_spam_log_' . md5( ABSPATH ) . '.lock';
        $fp        = @fopen( $lock_file, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- flock() needs a real stream handle; WP_Filesystem exposes no locking primitive. The @ suppresses PHP's own "failed to open stream" warning, which carries the absolute path into the web-readable debug.log; the sanitized diagnostic below reports the failure instead.
        if ( false === $fp ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational diagnostic: the lock file could not be opened, so this write proceeds unserialised. Silent failure would make concurrent-write corruption undebuggable; the log line carries no user input.
            error_log( 'ANTISPAMWIZARD: unable to open lock file: ' . basename( $lock_file ) );
        }
        $locked = $fp && flock( $fp, LOCK_EX );

        $entry_id = uniqid( 'antispamwizard_', true );

        try {
            wp_cache_delete( self::OPTION_KEY, 'options' );
            $entries   = self::get_entries();
            $entries[] = array(
                'entry_id'         => $entry_id,
                'date'             => current_time( 'mysql' ),
                'text_excerpt'     => wp_strip_all_tags( mb_substr( $text_excerpt, 0, 100 ) ),
                'checks_triggered' => $checks_triggered,
                'ip_hash'          => $ip_hash,
                'comment_id'       => $comment_id,
                'source'           => $source,
                'email_hash'       => $email_hash,
            );

            // FIFO: keep only the newest MAX_ENTRIES.
            if ( count( $entries ) > self::MAX_ENTRIES ) {
                $entries = array_slice( $entries, -self::MAX_ENTRIES );
            }

            update_option( self::OPTION_KEY, $entries, false );
        } finally {
            if ( $locked ) {
                flock( $fp, LOCK_UN );
            }
            if ( $fp ) {
                fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the flock() stream handle opened above; WP_Filesystem exposes no locking primitive.
            }
        }

        return $entry_id;
    }

    /**
     * Required keys for a valid log entry.
     */
    private const REQUIRED_KEYS = array( 'entry_id', 'date', 'text_excerpt', 'checks_triggered', 'ip_hash' );

    /**
     * Retrieve log entries.
     *
     * @param int $limit Maximum number of entries to return (0 = all).
     * @return array
     */
    public static function get_entries( int $limit = 0 ): array {
        // Invalidate any stale object-cache copy before reading so callers that
        // do not first delete the cache themselves still observe a coherent value.
        wp_cache_delete( self::OPTION_KEY, 'options' );
        $entries = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $entries ) ) {
            return array();
        }

        // Filter out malformed entries missing required keys.
        $entries = array_values(
            array_filter(
                $entries,
                static function ( $entry ) {
                    if ( ! is_array( $entry ) ) {
                        return false;
                    }
                    foreach ( self::REQUIRED_KEYS as $key ) {
                        if ( ! array_key_exists( $key, $entry ) ) {
                            return false;
                        }
                    }
                    return true;
                }
            )
        );

        if ( $limit > 0 ) {
            return array_slice( $entries, -$limit );
        }
        return $entries;
    }

    /**
     * Return log entries whose comment_id is in the given set.
     *
     * Entries with comment_id 0 (not yet linked to a stored comment) never
     * belong to a data subject and are always excluded.
     *
     * @param array $comment_ids List of WordPress comment IDs.
     * @return array Matching log entries.
     */
    public static function get_entries_by_comment_ids( array $comment_ids ): array {
        $wanted = array_filter(
            array_map( 'intval', $comment_ids ),
            static function ( $id ) {
                return 0 !== $id;
            }
        );
        if ( empty( $wanted ) ) {
            return array();
        }
        $wanted = array_flip( $wanted );

        $entries = self::get_entries();
        return array_values(
            array_filter(
                $entries,
                static function ( $entry ) use ( $wanted ) {
                    return isset( $entry['comment_id'] )
                        && 0 !== (int) $entry['comment_id']
                        && isset( $wanted[ (int) $entry['comment_id'] ] );
                }
            )
        );
    }

    /**
     * Delete log entries whose comment_id is in the given set.
     *
     * Runs under the same exclusive lock as the other mutators. Entries with
     * comment_id 0 are never matched. The option is only rewritten when at
     * least one entry is removed.
     *
     * @param array $comment_ids List of WordPress comment IDs.
     * @return int Number of entries deleted.
     */
    public static function delete_entries_by_comment_ids( array $comment_ids ): int {
        $wanted = array_filter(
            array_map( 'intval', $comment_ids ),
            static function ( $id ) {
                return 0 !== $id;
            }
        );
        if ( empty( $wanted ) ) {
            return 0;
        }
        $wanted = array_flip( $wanted );

        $lock_file = self::get_lock_dir() . '/antispamwizard_spam_log_' . md5( ABSPATH ) . '.lock';
        $fp        = @fopen( $lock_file, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- flock() needs a real stream handle; WP_Filesystem exposes no locking primitive. The @ suppresses PHP's own "failed to open stream" warning, which carries the absolute path into the web-readable debug.log; the sanitized diagnostic below reports the failure instead.
        if ( false === $fp ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational diagnostic: the lock file could not be opened, so this write proceeds unserialised. Silent failure would make concurrent-write corruption undebuggable; the log line carries no user input.
            error_log( 'ANTISPAMWIZARD: unable to open lock file: ' . basename( $lock_file ) );
        }
        $locked = $fp && flock( $fp, LOCK_EX );

        $deleted = 0;

        try {
            wp_cache_delete( self::OPTION_KEY, 'options' );
            $entries   = self::get_entries();
            $remaining = array_values(
                array_filter(
                    $entries,
                    static function ( $entry ) use ( $wanted ) {
                        return ! (
                            isset( $entry['comment_id'] )
                            && 0 !== (int) $entry['comment_id']
                            && isset( $wanted[ (int) $entry['comment_id'] ] )
                        );
                    }
                )
            );

            $deleted = count( $entries ) - count( $remaining );
            if ( $deleted > 0 ) {
                update_option( self::OPTION_KEY, $remaining, false );
                wp_cache_delete( self::OPTION_KEY, 'options' );
            }
        } finally {
            if ( $locked ) {
                flock( $fp, LOCK_UN );
            }
            if ( $fp ) {
                fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the flock() stream handle opened above; WP_Filesystem exposes no locking primitive.
            }
        }

        return $deleted;
    }

    /**
     * Return log entries whose email_hash matches the given hash.
     *
     * Returns an empty array for an empty hash. Only entries with a non-empty
     * email_hash equal to the hash are matched; comment entries (empty hash)
     * can never match.
     *
     * @param string $hash SHA-256 email hash.
     * @return array Matching log entries.
     */
    public static function get_entries_by_email_hash( string $hash ): array {
        if ( '' === $hash ) {
            return array();
        }
        $entries = self::get_entries();
        return array_values(
            array_filter(
                $entries,
                static function ( $entry ) use ( $hash ) {
                    return isset( $entry['email_hash'] )
                        && '' !== $entry['email_hash']
                        && $entry['email_hash'] === $hash;
                }
            )
        );
    }

    /**
     * Delete log entries whose email_hash matches the given hash.
     *
     * Runs under the same exclusive lock as the other mutators. Only entries
     * with a non-empty email_hash equal to the hash are removed. The option is
     * only rewritten when at least one entry is removed.
     *
     * @param string $hash SHA-256 email hash.
     * @return int Number of entries deleted.
     */
    public static function delete_entries_by_email_hash( string $hash ): int {
        if ( '' === $hash ) {
            return 0;
        }

        $lock_file = self::get_lock_dir() . '/antispamwizard_spam_log_' . md5( ABSPATH ) . '.lock';
        $fp        = @fopen( $lock_file, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- flock() needs a real stream handle; WP_Filesystem exposes no locking primitive. The @ suppresses PHP's own "failed to open stream" warning, which carries the absolute path into the web-readable debug.log; the sanitized diagnostic below reports the failure instead.
        if ( false === $fp ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational diagnostic: the lock file could not be opened, so this write proceeds unserialised. Silent failure would make concurrent-write corruption undebuggable; the log line carries no user input.
            error_log( 'ANTISPAMWIZARD: unable to open lock file: ' . basename( $lock_file ) );
        }
        $locked = $fp && flock( $fp, LOCK_EX );

        $deleted = 0;

        try {
            wp_cache_delete( self::OPTION_KEY, 'options' );
            $entries   = self::get_entries();
            $remaining = array_values(
                array_filter(
                    $entries,
                    static function ( $entry ) use ( $hash ) {
                        return ! (
                            isset( $entry['email_hash'] )
                            && '' !== $entry['email_hash']
                            && $entry['email_hash'] === $hash
                        );
                    }
                )
            );

            $deleted = count( $entries ) - count( $remaining );
            if ( $deleted > 0 ) {
                update_option( self::OPTION_KEY, $remaining, false );
                wp_cache_delete( self::OPTION_KEY, 'options' );
            }
        } finally {
            if ( $locked ) {
                flock( $fp, LOCK_UN );
            }
            if ( $fp ) {
                fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the flock() stream handle opened above; WP_Filesystem exposes no locking primitive.
            }
        }

        return $deleted;
    }

    /**
     * Update the comment_id on a specific log entry identified by its entry_id.
     *
     * @param string $entry_id   The unique entry identifier returned by add_entry().
     * @param int    $comment_id WordPress comment ID.
     */
    public static function update_entry_comment_id( string $entry_id, int $comment_id ): void {
        $lock_file = self::get_lock_dir() . '/antispamwizard_spam_log_' . md5( ABSPATH ) . '.lock';
        $fp        = @fopen( $lock_file, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- flock() needs a real stream handle; WP_Filesystem exposes no locking primitive. The @ suppresses PHP's own "failed to open stream" warning, which carries the absolute path into the web-readable debug.log; the sanitized diagnostic below reports the failure instead.
        if ( false === $fp ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational diagnostic: the lock file could not be opened, so this write proceeds unserialised. Silent failure would make concurrent-write corruption undebuggable; the log line carries no user input.
            error_log( 'ANTISPAMWIZARD: unable to open lock file: ' . basename( $lock_file ) );
        }
        $locked = $fp && flock( $fp, LOCK_EX );

        try {
            wp_cache_delete( self::OPTION_KEY, 'options' );
            $entries = self::get_entries();
            foreach ( $entries as $key => $entry ) {
                if ( isset( $entry['entry_id'] ) && $entry['entry_id'] === $entry_id ) {
                    $entries[ $key ]['comment_id'] = $comment_id;
                    update_option( self::OPTION_KEY, $entries, false );
                    wp_cache_delete( self::OPTION_KEY, 'options' );
                    break;
                }
            }
        } finally {
            if ( $locked ) {
                flock( $fp, LOCK_UN );
            }
            if ( $fp ) {
                fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the flock() stream handle opened above; WP_Filesystem exposes no locking primitive.
            }
        }
    }

    /**
     * Check if a comment has already been used for training.
     *
     * @param int $comment_id The WordPress comment ID to look up.
     * @return bool True if the entry has a trained_as value.
     */
    public static function is_trained( int $comment_id ): bool {
        wp_cache_delete( self::OPTION_KEY, 'options' );
        $entries = self::get_entries();
        foreach ( $entries as $entry ) {
            if ( isset( $entry['comment_id'] ) && $entry['comment_id'] === $comment_id ) {
                return ! empty( $entry['trained_as'] );
            }
        }
        return false;
    }

    /**
     * Mark a log entry as trained. Returns 'ok' on success, or an error string.
     *
     * @param int    $comment_id The WordPress comment ID to look up.
     * @param string $category   The training category ('spam' or 'ham').
     * @return string 'ok' on success, 'already_trained' or 'not_found' on failure.
     */
    public static function mark_trained( int $comment_id, string $category ): string {
        $lock_file = self::get_lock_dir() . '/antispamwizard_spam_log_' . md5( ABSPATH ) . '.lock';
        $fp        = @fopen( $lock_file, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- flock() needs a real stream handle; WP_Filesystem exposes no locking primitive. The @ suppresses PHP's own "failed to open stream" warning, which carries the absolute path into the web-readable debug.log; the sanitized diagnostic below reports the failure instead.
        if ( false === $fp ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational diagnostic: the lock file could not be opened, so this write proceeds unserialised. Silent failure would make concurrent-write corruption undebuggable; the log line carries no user input.
            error_log( 'ANTISPAMWIZARD: unable to open lock file: ' . basename( $lock_file ) );
        }
        $locked = $fp && flock( $fp, LOCK_EX );

        $result = 'not_found';

        try {
            wp_cache_delete( self::OPTION_KEY, 'options' );
            $entries = self::get_entries();
            foreach ( $entries as $key => $entry ) {
                if ( isset( $entry['comment_id'] ) && $entry['comment_id'] === $comment_id ) {
                    if ( ! empty( $entry['trained_as'] ) ) {
                        $result = 'already_trained';
                        break;
                    }
                    $entries[ $key ]['trained_as'] = $category;
                    update_option( self::OPTION_KEY, $entries, false );
                    $result = 'ok';
                    break;
                }
            }
        } finally {
            if ( $locked ) {
                flock( $fp, LOCK_UN );
            }
            if ( $fp ) {
                fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the flock() stream handle opened above; WP_Filesystem exposes no locking primitive.
            }
        }

        return $result;
    }

    /**
     * Atomically claim a log entry for training in a given category.
     *
     * Unlike mark_trained(), which refuses to touch an entry that already
     * carries a category, this both claims an untrained entry and flips one
     * trained as the other category, reporting which happened so the caller
     * can roll the right value back if the sample write then fails.
     *
     * @param int    $comment_id The WordPress comment ID to look up.
     * @param string $category   The training category ('spam' or 'ham').
     * @return string 'ok' when the entry was untrained, 'flipped' when it carried
     *                the other category, 'already_trained' when it already carried
     *                this one (nothing is written), 'not_found' when there is no
     *                entry for this comment, and 'failed' when an entry was found
     *                but the option write that would have claimed it was refused.
     */
    public static function claim_training( int $comment_id, string $category ): string {
        $lock_file = self::get_lock_dir() . '/antispamwizard_spam_log_' . md5( ABSPATH ) . '.lock';
        $fp        = @fopen( $lock_file, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- flock() needs a real stream handle; WP_Filesystem exposes no locking primitive. The @ suppresses PHP's own "failed to open stream" warning, which carries the absolute path into the web-readable debug.log; the sanitized diagnostic below reports the failure instead.
        if ( false === $fp ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational diagnostic: the lock file could not be opened, so this write proceeds unserialised. Silent failure would make concurrent-write corruption undebuggable; the log line carries no user input.
            error_log( 'ANTISPAMWIZARD: unable to open lock file: ' . basename( $lock_file ) );
        }
        $locked = $fp && flock( $fp, LOCK_EX );

        $result = 'not_found';

        try {
            wp_cache_delete( self::OPTION_KEY, 'options' );
            $entries = self::get_entries();
            foreach ( $entries as $key => $entry ) {
                if ( ! isset( $entry['comment_id'] ) || $entry['comment_id'] !== $comment_id ) {
                    continue;
                }
                $current = isset( $entry['trained_as'] ) ? (string) $entry['trained_as'] : '';
                if ( $current === $category ) {
                    $result = 'already_trained';
                    break;
                }
                // There are exactly two categories, so a non-empty value that is
                // not $category is the other one: 'flipped' tells the caller what
                // the previous value was without a second read.
                $result                        = ( '' === $current ) ? 'ok' : 'flipped';
                $entries[ $key ]['trained_as'] = $category;
                $written                       = update_option( self::OPTION_KEY, $entries, false );
                wp_cache_delete( self::OPTION_KEY, 'options' );
                // The value always differs from the stored one here, so a false
                // return is a failed write, not update_option()'s no-change case.
                // Reporting the claim anyway would hand the caller a lock that
                // does not exist and a rollback value to restore onto an entry
                // that never changed. 'not_found' would be wrong too: that value
                // means "no entry exists", and the caller trains unlocked on it.
                // Doing that here would flip the sample table -- inserting the new
                // category and deleting the old one -- while the entry on disk
                // still names the old category, and a later transition back would
                // then come back 'already_trained' and never store anything. The
                // distinct value tells the caller to leave the table alone and let
                // the next moderation retry.
                if ( false === $written ) {
                    $result = 'failed';
                }
                break;
            }
        } finally {
            if ( $locked ) {
                flock( $fp, LOCK_UN );
            }
            if ( $fp ) {
                fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the flock() stream handle opened above; WP_Filesystem exposes no locking primitive.
            }
        }

        return $result;
    }

    /**
     * Write a training category onto a log entry unconditionally.
     *
     * The rollback counterpart of a 'flipped' claim: where mark_trained()
     * refuses to overwrite an existing value, this restores the category the
     * entry carried before the claim, so the log never reports a comment as
     * untrained while the previous category's sample is still stored.
     *
     * Reports whether the value is on disk, so a caller whose rollback was
     * refused can say so: a lost rollback leaves the entry naming a category the
     * sample table does not hold, and every later transition then comes back
     * 'already_trained', so the training is blocked for good rather than retried.
     *
     * @param int    $comment_id The WordPress comment ID to look up.
     * @param string $category   The training category to write ('spam' or 'ham').
     * @return bool True when the entry already carried this category, when it was
     *              written, or when there is no entry to write onto; false only
     *              when the option write was refused.
     */
    public static function restore_training( int $comment_id, string $category ): bool {
        $lock_file = self::get_lock_dir() . '/antispamwizard_spam_log_' . md5( ABSPATH ) . '.lock';
        $fp        = @fopen( $lock_file, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- flock() needs a real stream handle; WP_Filesystem exposes no locking primitive. The @ suppresses PHP's own "failed to open stream" warning, which carries the absolute path into the web-readable debug.log; the sanitized diagnostic below reports the failure instead.
        if ( false === $fp ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational diagnostic: the lock file could not be opened, so this write proceeds unserialised. Silent failure would make concurrent-write corruption undebuggable; the log line carries no user input.
            error_log( 'ANTISPAMWIZARD: unable to open lock file: ' . basename( $lock_file ) );
        }
        $locked = $fp && flock( $fp, LOCK_EX );

        // Nothing to write is not a failed write: an entry that already carries
        // this category, and a comment with no entry at all, both leave the log
        // saying what the caller wanted it to say.
        $written = true;

        try {
            wp_cache_delete( self::OPTION_KEY, 'options' );
            $entries = self::get_entries();
            foreach ( $entries as $key => $entry ) {
                if ( isset( $entry['comment_id'] ) && $entry['comment_id'] === $comment_id ) {
                    $current = isset( $entry['trained_as'] ) ? (string) $entry['trained_as'] : '';
                    if ( $current === $category ) {
                        break;
                    }
                    $entries[ $key ]['trained_as'] = $category;
                    $written                       = false !== update_option( self::OPTION_KEY, $entries, false );
                    wp_cache_delete( self::OPTION_KEY, 'options' );
                    break;
                }
            }
        } finally {
            if ( $locked ) {
                flock( $fp, LOCK_UN );
            }
            if ( $fp ) {
                fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the flock() stream handle opened above; WP_Filesystem exposes no locking primitive.
            }
        }

        return $written;
    }

    /**
     * Remove the trained_as mark from a log entry. Used to roll back if post-training steps fail.
     *
     * Like restore_training(), reports whether the log is in the state the
     * caller asked for: a rollback that was refused leaves the entry claimed
     * while no sample was stored, which every later transition then reads as
     * 'already_trained'.
     *
     * @param int $comment_id The WordPress comment ID to look up.
     * @return bool True when the mark is gone (or was never there, or there is no
     *              entry); false only when the option write was refused.
     */
    public static function unmark_trained( int $comment_id ): bool {
        $lock_file = self::get_lock_dir() . '/antispamwizard_spam_log_' . md5( ABSPATH ) . '.lock';
        $fp        = @fopen( $lock_file, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- flock() needs a real stream handle; WP_Filesystem exposes no locking primitive. The @ suppresses PHP's own "failed to open stream" warning, which carries the absolute path into the web-readable debug.log; the sanitized diagnostic below reports the failure instead.
        if ( false === $fp ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational diagnostic: the lock file could not be opened, so this write proceeds unserialised. Silent failure would make concurrent-write corruption undebuggable; the log line carries no user input.
            error_log( 'ANTISPAMWIZARD: unable to open lock file: ' . basename( $lock_file ) );
        }
        $locked = $fp && flock( $fp, LOCK_EX );

        // An entry that carries no mark is already in the asked-for state, and
        // update_option() would return false for the unchanged value.
        $written = true;

        try {
            wp_cache_delete( self::OPTION_KEY, 'options' );
            $entries = self::get_entries();
            foreach ( $entries as $key => $entry ) {
                if ( isset( $entry['comment_id'] ) && $entry['comment_id'] === $comment_id ) {
                    if ( ! isset( $entry['trained_as'] ) ) {
                        break;
                    }
                    unset( $entries[ $key ]['trained_as'] );
                    $written = false !== update_option( self::OPTION_KEY, $entries, false );
                    break;
                }
            }
        } finally {
            if ( $locked ) {
                flock( $fp, LOCK_UN );
            }
            if ( $fp ) {
                fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the flock() stream handle opened above; WP_Filesystem exposes no locking primitive.
            }
        }

        return $written;
    }

    /**
     * Clear all log entries.
     */
    public static function clear(): void {
        $lock_file = self::get_lock_dir() . '/antispamwizard_spam_log_' . md5( ABSPATH ) . '.lock';
        $fp        = @fopen( $lock_file, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- flock() needs a real stream handle; WP_Filesystem exposes no locking primitive. The @ suppresses PHP's own "failed to open stream" warning, which carries the absolute path into the web-readable debug.log; the sanitized diagnostic below reports the failure instead.
        if ( false === $fp ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational diagnostic: the lock file could not be opened, so this write proceeds unserialised. Silent failure would make concurrent-write corruption undebuggable; the log line carries no user input.
            error_log( 'ANTISPAMWIZARD: unable to open lock file: ' . basename( $lock_file ) );
        }
        $locked = $fp && flock( $fp, LOCK_EX );

        try {
            delete_option( self::OPTION_KEY );
            // Drop any cached copy so subsequent reads do not resurrect the cleared log.
            wp_cache_delete( self::OPTION_KEY, 'options' );
        } finally {
            if ( $locked ) {
                flock( $fp, LOCK_UN );
            }
            if ( $fp ) {
                fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the flock() stream handle opened above; WP_Filesystem exposes no locking primitive.
            }
        }
    }
}
