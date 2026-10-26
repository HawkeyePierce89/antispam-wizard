<?php
/**
 * Database repository for user-added training samples.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Stores and retrieves user-added training samples in a custom database table.
 *
 * Seed corpus remains in data/*.txt files; this repository handles samples added
 * via the admin UI (mark as spam/ham). The classifier merges both sources.
 */
class ASW_Sample_Repository {

    /** @var string Option name for the revision counter. */
    const REVISION_OPTION = 'asw_samples_revision';

    /**
     * @var string Schema version of get_create_table_sql(). '1' is the layout
     * 1.0.0 ships: the table with both subject keys (comment_id, email_hash)
     * and their indexes. Bump whenever the DDL changes so
     * ASW::maybe_upgrade_schema() re-runs dbDelta() on sites that already have
     * the table.
     */
    const DB_VERSION = '1';

    /**
     * @var array<int, string> Columns schema_is_current() probes for: the two
     * subject keys, which are what the Privacy API depends on. A refused CREATE
     * on a fresh install fails every probe, and a table left over from a
     * pre-release layout without these columns is migrated in place. When a
     * future DB_VERSION adds a column, add it here.
     */
    const VERSIONED_COLUMNS = array( 'comment_id', 'email_hash' );

    /**
     * @var array<int, string> Indexes schema_is_current() probes for. dbDelta()
     * issues the column ALTER and the index ALTER as separate statements and
     * checks neither result, so a run that added the column while the index
     * failed must not be recorded as complete. When a future DB_VERSION adds an
     * index, add it here.
     */
    const VERSIONED_INDEXES = array( 'idx_comment_id', 'idx_email_hash' );

    /** @var string Option name holding the installed schema version. */
    const DB_VERSION_OPTION = 'asw_samples_db_version';

    /** @var string[] Allowed MySQL character sets. */
    const ALLOWED_CHARSETS = array(
        'armscii8', 'ascii', 'big5', 'binary', 'cp1250', 'cp1251', 'cp1256',
        'cp1257', 'cp850', 'cp852', 'cp866', 'cp932', 'dec8', 'eucjpms',
        'euckr', 'gb18030', 'gb2312', 'gbk', 'geostd8', 'greek', 'hebrew',
        'hp8', 'keybcs2', 'koi8r', 'koi8u', 'latin1', 'latin2', 'latin5',
        'latin7', 'macce', 'macroman', 'sjis', 'swe7', 'tis620', 'ucs2',
        'ujis', 'utf16', 'utf16le', 'utf32', 'utf8', 'utf8mb3', 'utf8mb4',
    );

    /** @var string Pattern for valid MySQL collation names (charset_language_modifier). */
    const COLLATE_PATTERN = '/^[a-z0-9]+_[a-z0-9_]+$/';

    /** @var object WordPress $wpdb instance. */
    private $wpdb;

    /**
     * @var bool Whether a find_all() read has failed since the flag was last
     * cleared. find_all() cannot report a failure through its return value: a
     * failed SELECT hands back the empty array wpdb::query()'s flush() left in
     * last_result, byte-identical to "this script has no rows" -- the same
     * indistinguishability script_fingerprint() reads last_error to resolve. The
     * flag is what lets ASW_Model_Store tell the two apart, because a unit built
     * while the sample read failed carries the corpus files alone while its
     * fingerprint asserts the rows are in it, and caching that would drop every
     * user-added sample out of the Bayesian and Similarity models for a day.
     */
    private bool $sample_read_failed = false;

    /**
     * @param object $wpdb WordPress database instance.
     */
    public function __construct( $wpdb ) {
        $this->wpdb = $wpdb;
    }

    /**
     * Clear the find_all() failure flag before a run of sample reads.
     */
    public function clear_sample_read_failure(): void {
        $this->sample_read_failed = false;
    }

    /**
     * Whether any find_all() call since the last clear reported a DB error.
     *
     * @return bool
     */
    public function sample_read_failed(): bool {
        return $this->sample_read_failed;
    }

    /**
     * Get the full table name including prefix.
     *
     * @return string
     */
    public function get_table_name(): string {
        return $this->wpdb->prefix . 'asw_samples';
    }

    /**
     * Get the CREATE TABLE DDL for dbDelta().
     *
     * @return string SQL statement.
     */
    public function get_create_table_sql(): string {
        $table   = $this->get_table_name();
        $charset = ! empty( $this->wpdb->charset ) ? $this->wpdb->charset : 'utf8mb4';
        $collate = ! empty( $this->wpdb->collate ) ? $this->wpdb->collate : '';

        // Validate charset against allowlist to prevent injection.
        if ( ! in_array( strtolower( $charset ), self::ALLOWED_CHARSETS, true ) ) {
            $charset = 'utf8mb4';
            $collate = 'utf8mb4_unicode_ci';
        }

        // Validate collate if provided. When WordPress leaves collate empty,
        // omit the COLLATE clause and let MySQL use its default for the charset.
        // This avoids coercing e.g. latin1 installs to utf8mb4.
        if ( '' !== $collate ) {
            if ( ! preg_match( self::COLLATE_PATTERN, strtolower( $collate ) ) ) {
                $collate = '';
            }
        }

        // Verify collation belongs to the chosen charset (when set). MySQL
        // collations are prefixed with their charset name (e.g.
        // utf8mb4_unicode_ci for utf8mb4). A mismatch produces invalid DDL.
        if ( '' !== $collate ) {
            $charset_lower = strtolower( $charset );
            $collate_lower = strtolower( $collate );
            if ( ! str_starts_with( $collate_lower, $charset_lower . '_' ) ) {
                // Mismatch — drop the collate rather than coercing the charset.
                $collate = '';
            }
        }

        $collate_clause = '' !== $collate ? " COLLATE {$collate}" : '';

        // No backticks on table name — dbDelta() extracts it with regex
        // CREATE TABLE ([^ ]*) and backticks break the match.
        // The unique key deduplicates on the text, not on the subject: training a
        // second comment whose normalised text is identical keeps the row created
        // first, together with the first comment's comment_id and email_hash.
        // Accepted edge case — the sample is still attributed to a real author of
        // that exact text, and erasing that first subject removes it.
        //
        // email_hash is a *secondary* subject key. comment_id alone only reaches a
        // sample for as long as its comment exists, and an administrator emptying
        // the spam queue hard-deletes the very comments samples are trained from —
        // after that the full comment text would live on here, undiscoverable by a
        // personal-data export or erasure request. The salted SHA-256 hash (see
        // ASW_Detector::hash_email()) survives that deletion; it is the same hash
        // the form-entry spam-log rows use, so no new kind of data is stored. It is
        // empty when the comment carried no author email, in which case comment_id
        // remains the only key.
        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            comment_id BIGINT UNSIGNED NOT NULL,
            email_hash CHAR(64) NOT NULL DEFAULT '',
            script VARCHAR(20) NOT NULL,
            category VARCHAR(10) NOT NULL,
            content TEXT NOT NULL,
            content_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_comment_id (comment_id),
            KEY idx_email_hash (email_hash),
            UNIQUE KEY idx_unique_sample (script, category, content_hash)
        ) DEFAULT CHARACTER SET {$charset}{$collate_clause};";
    }

    /**
     * Insert a training sample.
     *
     * @param string $script     Script group (e.g. 'latin', 'cyrillic').
     * @param string $category   'spam' or 'ham'.
     * @param string $content    The sample text.
     * @param int    $comment_id ID of the comment the sample was trained from. Required
     *                           subject key for the Privacy API exporter and eraser.
     * @param string $email_hash Salted SHA-256 hash of the comment author's email
     *                           (ASW_Detector::hash_email()); '' when the comment
     *                           carried no address. Secondary subject key that keeps
     *                           the sample reachable after the comment is deleted.
     * @return string|false 'inserted' when a new row is created, 'exists' when the
     *                      sample already exists (idempotent success), or false on error
     *                      -- including when $comment_id is not a positive ID, so an
     *                      unattributed sample can never be stored.
     */
    public function insert( string $script, string $category, string $content, int $comment_id, string $email_hash = '' ) {
        if ( $comment_id <= 0 ) {
            return false;
        }

        $content_hash = hash( 'sha256', $content );
        $table        = $this->get_table_name();

        // Check for duplicate — sample already exists, treat as success.
        $sql = $this->wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE content_hash = %s AND script = %s AND category = %s',
            $table,
            $content_hash,
            $script,
            $category
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WordPress.DB.PreparedSQL only recognises the literal $wpdb global, so it flags this injected $this->wpdb query in every form -- inlining the prepare() call here reports 6 findings instead of 1 (verified via dev/plugin-check.sh). $sql must therefore stay the return value of the bare $this->wpdb->prepare() call directly above: the suppression is anchored on this line and would also silence a query built any other way.
        $existing = $this->wpdb->get_var( $sql );

        if ( (int) $existing > 0 ) {
            return 'exists';
        }

        $result = $this->wpdb->insert(
            $table,
            array(
                'comment_id'   => $comment_id,
                'email_hash'   => $email_hash,
                'script'       => $script,
                'category'     => $category,
                'content'      => $content,
                'content_hash' => $content_hash,
                'created_at'   => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( false === $result ) {
            // A concurrent request may have inserted the same row between our
            // SELECT check and this INSERT, causing a duplicate-key failure.
            // Re-check and treat as idempotent success if the row now exists.
            $recheck_sql = $this->wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE content_hash = %s AND script = %s AND category = %s',
                $table,
                $content_hash,
                $script,
                $category
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WordPress.DB.PreparedSQL only recognises the literal $wpdb global, so it flags this injected $this->wpdb query in every form -- inlining the prepare() call here reports 6 findings instead of 1 (verified via dev/plugin-check.sh). $recheck_sql must therefore stay the return value of the bare $this->wpdb->prepare() call directly above: the suppression is anchored on this line and would also silence a query built any other way.
            $recheck = $this->wpdb->get_var( $recheck_sql );
            if ( (int) $recheck > 0 ) {
                return 'exists';
            }
            return false;
        }

        $this->bump_revision();
        return 'inserted';
    }

    /**
     * Delete a sample by its content hash, script, and category.
     *
     * @param string $script   Script group.
     * @param string $category 'spam' or 'ham'.
     * @param string $hash     SHA-256 hash of the content.
     * @return bool True on success.
     */
    public function delete_by_hash( string $script, string $category, string $hash ): bool {
        $result = $this->wpdb->delete(
            $this->get_table_name(),
            array(
                'content_hash' => $hash,
                'script'       => $script,
                'category'     => $category,
            ),
            array( '%s', '%s', '%s' )
        );

        if ( false === $result ) {
            return false;
        }

        if ( $result > 0 ) {
            $this->bump_revision();
        }

        return true;
    }

    /**
     * Which categories a sample with this script and content hash is stored under.
     *
     * The read-side counterpart of insert()/delete_by_hash(): those two mutate by
     * (script, category, content_hash) -- the unique key -- and never by
     * comment_id, because the content dedup keeps only the first comment's ID on
     * a row shared by every later comment with identical text. Anything that has
     * to decide whether a text is already trained, or trained as both categories,
     * therefore has to ask the same key they write, not the comment.
     *
     * One query answers for both categories, so a caller cannot see a half-failed
     * read: a query the database refused comes back as an empty array, exactly
     * like "nothing stored". Both callers are written so that reading is the safe
     * direction -- ASW_Moderation::training_is_settled() reports "not settled" and
     * costs a redundant insert(), which deduplicates, never a skipped one, and
     * ASW_Admin_Page::ajax_mark_comment() issues its flip delete only on the
     * positive evidence of both categories being present, so no row is ever
     * removed on the strength of a read that failed.
     *
     * @param string $script Script group.
     * @param string $hash   SHA-256 hash of the content.
     * @return array<int, string> Categories present for that sample identity.
     */
    public function find_categories_by_hash( string $script, string $hash ): array {
        $table = $this->get_table_name();
        $sql   = $this->wpdb->prepare(
            'SELECT category FROM %i WHERE script = %s AND content_hash = %s',
            $table,
            $script,
            $hash
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WordPress.DB.PreparedSQL only recognises the literal $wpdb global, so it flags this injected $this->wpdb query in every form -- inlining the prepare() call here reports 6 findings instead of 1 (verified via dev/plugin-check.sh). $sql must therefore stay the return value of the bare $this->wpdb->prepare() call directly above: the suppression is anchored on this line and would also silence a query built any other way.
        $rows = $this->wpdb->get_results( $sql );

        if ( ! is_array( $rows ) ) {
            return array();
        }

        $categories = array();
        foreach ( $rows as $row ) {
            $categories[] = isset( $row->category ) ? (string) $row->category : '';
        }
        return $categories;
    }

    /**
     * Find all sample contents for a given script and category.
     *
     * Returns an empty array both when the script has no rows and when the read
     * failed; a caller that must tell the two apart reads sample_read_failed().
     *
     * @param string $script   Script group.
     * @param string $category 'spam' or 'ham'.
     * @return array<int, string> Array of content strings.
     */
    public function find_all( string $script, string $category ): array {
        $table = $this->get_table_name();

        // Same bracket, and for the same reason, as script_fingerprint(): against
        // a table that was never created this SELECT is a hard MySQL error, and
        // wpdb::print_error() writes it to error_log() whether or not show_errors
        // is on, gated only by suppress_errors. A cold unit load calls this twice
        // per script, so unbracketed it would append twenty plugin-attributable
        // lines to the debug.log dev/smoke.sh scans per combined-unit build.
        $suppressed = $this->wpdb->suppress_errors( true );

        try {
            $sql = $this->wpdb->prepare(
                // ORDER BY id ASC: without it MySQL may hand the rows back in
                // any order, and ASW_Model_Store spends its learned-similarity
                // budget from the newest end of this list, so "newest" has to
                // mean something. The autoincrement is the only column that
                // gives it. created_at cannot: it is second-granular, so every
                // sample one moderation batch adds ties and ties have no
                // defined order, and it does not track a row's history either
                // -- content dedup keeps the first row of a repeated text
                // whatever is trained afterwards, while a sample trained again
                // after an erasure comes back with a fresh id.
                'SELECT content FROM %i WHERE script = %s AND category = %s ORDER BY id ASC',
                $table,
                $script,
                $category
            );
            // wpdb::query() clears last_error itself before every statement, but a
            // caller reading the flag afterwards cannot tell whose error it is, so
            // it is cleared here too -- the same bracket script_fingerprint() uses.
            $this->wpdb->last_error = '';
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WordPress.DB.PreparedSQL only recognises the literal $wpdb global, so it flags this injected $this->wpdb query in every form -- inlining the prepare() call here reports 6 findings instead of 1 (verified via dev/plugin-check.sh). $sql must therefore stay the return value of the bare $this->wpdb->prepare() call directly above: the suppression is anchored on this line and would also silence a query built any other way.
            $rows = $this->wpdb->get_results( $sql );

            $failed = ( '' !== (string) $this->wpdb->last_error );
        } finally {
            $this->wpdb->suppress_errors( $suppressed );
        }

        if ( $failed ) {
            $this->sample_read_failed = true;
            return array();
        }

        if ( ! is_array( $rows ) ) {
            return array();
        }

        $samples = array();
        foreach ( $rows as $row ) {
            $samples[] = $row->content;
        }
        return $samples;
    }

    /**
     * Normalise a list of comment IDs for use as a subject key.
     *
     * Casts to int, drops zeros and negatives (an unattributed sample can never
     * exist, so those can only be noise) and removes duplicates.
     *
     * @param array<int, mixed> $comment_ids Raw comment IDs.
     * @return array<int, int> Positive, unique comment IDs.
     */
    private function normalize_comment_ids( array $comment_ids ): array {
        $ids = array();
        foreach ( $comment_ids as $comment_id ) {
            $id = (int) $comment_id;
            if ( $id > 0 ) {
                $ids[] = $id;
            }
        }
        return array_values( array_unique( $ids ) );
    }

    /**
     * Find every sample trained from one of the given comments.
     *
     * The subject key for the Privacy API exporter: WordPress core resolves a
     * data subject's email to their comment IDs, and this returns the samples
     * those comments produced.
     *
     * The column list matches find_by_email_hash() exactly, including the
     * comment_id and email_hash keys the export itself never renders:
     * ASW_Privacy merges the rows from both lookups into one array keyed by row
     * ID, so a row fetched by either key must carry the same keys. (comment_id
     * is read as well -- ASW_Privacy::comment_ids_on_later_pages() decides the
     * page-1/later-page split from the hash rows' comment_id, never from the
     * row IDs another page's hash query returned.)
     *
     * @param array<int, mixed> $comment_ids Comment IDs to look up.
     * @return array<int, array<string, mixed>> Rows keyed by column name; empty
     *                                          when no usable ID was given.
     */
    public function find_by_comment_ids( array $comment_ids ): array {
        $ids = $this->normalize_comment_ids( $comment_ids );
        if ( empty( $ids ) ) {
            return array();
        }

        $table        = $this->get_table_name();
        $placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the sniff cannot count the %d tokens inside the interpolated {$placeholders} list, so it sees one placeholder against two arguments; at runtime the list holds exactly one %d per spread ID, matching %i plus the IDs.
        $sql = $this->wpdb->prepare(
            // ORDER BY id keeps the export deterministic: without it MySQL may
            // return the rows in any order, so a subject's export items would
            // shuffle between runs (ASW_Privacy::get_comment_ids() pins its own
            // ordering for the same reason). Keep this note above the
            // suppression below rather than between it and the SQL: a phpcs
            // suppression is anchored on the line that follows it, so anything
            // inserted in between un-silences the sniff.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a list of literal %d tokens built from the ID count alone; the IDs themselves are bound as prepare() arguments below.
            "SELECT id, comment_id, email_hash, script, category, content, created_at FROM %i WHERE comment_id IN ( {$placeholders} ) ORDER BY id ASC",
            $table,
            ...$ids
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WordPress.DB.PreparedSQL only recognises the literal $wpdb global, so it flags this injected $this->wpdb query in every form -- inlining the prepare() call here reports 6 findings instead of 1 (verified via dev/plugin-check.sh). $sql must therefore stay the return value of the bare $this->wpdb->prepare() call directly above: the suppression is anchored on this line and would also silence a query built any other way.
        $rows = $this->wpdb->get_results( $sql );

        if ( ! is_array( $rows ) ) {
            return array();
        }

        $samples = array();
        foreach ( $rows as $row ) {
            $samples[] = (array) $row;
        }
        return $samples;
    }

    /**
     * Delete every sample trained from one of the given comments.
     *
     * The subject key for the Privacy API eraser. Bumps the revision counter
     * when rows were actually removed, so the cached classifier is rebuilt
     * without the erased text.
     *
     * A failed query returns false rather than 0: the caller is the Privacy API
     * eraser, and reporting "nothing matched" for a DELETE the database refused
     * would tell the data subject their training samples were gone while the
     * full comment text was still stored.
     *
     * @param array<int, mixed> $comment_ids Comment IDs to erase samples for.
     * @return int|false Number of rows removed (0 when nothing matched or no
     *                   usable ID was given), or false when the query failed.
     */
    public function delete_by_comment_ids( array $comment_ids ) {
        $ids = $this->normalize_comment_ids( $comment_ids );
        if ( empty( $ids ) ) {
            return 0;
        }

        $table        = $this->get_table_name();
        $placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the sniff cannot count the %d tokens inside the interpolated {$placeholders} list, so it sees one placeholder against two arguments; at runtime the list holds exactly one %d per spread ID, matching %i plus the IDs.
        $sql = $this->wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a list of literal %d tokens built from the ID count alone; the IDs themselves are bound as prepare() arguments below.
            "DELETE FROM %i WHERE comment_id IN ( {$placeholders} )",
            $table,
            ...$ids
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WordPress.DB.PreparedSQL only recognises the literal $wpdb global, so it flags this injected $this->wpdb query in every form -- inlining the prepare() call here reports 6 findings instead of 1 (verified via dev/plugin-check.sh). $sql must therefore stay the return value of the bare $this->wpdb->prepare() call directly above: the suppression is anchored on this line and would also silence a query built any other way.
        $deleted = $this->wpdb->query( $sql );

        // query() returns false on error and an affected-row count otherwise.
        if ( false === $deleted ) {
            return false;
        }
        if ( ! is_numeric( $deleted ) || $deleted <= 0 ) {
            return 0;
        }

        $this->bump_revision();
        return (int) $deleted;
    }

    /**
     * Find every sample whose author email hash matches.
     *
     * The secondary subject key for the Privacy API exporter: unlike comment_id
     * this still resolves after the source comment has been hard-deleted (for
     * example by "Empty Spam"), which is when the sample text would otherwise
     * become undiscoverable.
     *
     * @param string $email_hash Salted SHA-256 hash from ASW_Detector::hash_email().
     * @return array<int, array<string, mixed>> Rows keyed by column name; empty
     *                                          for an empty hash.
     */
    public function find_by_email_hash( string $email_hash ): array {
        if ( '' === $email_hash ) {
            return array();
        }

        $table = $this->get_table_name();
        $sql   = $this->wpdb->prepare(
            // ORDER BY id keeps the export deterministic, as in find_by_comment_ids().
            // The column list matches find_by_comment_ids() exactly: ASW_Privacy
            // merges rows from both lookups into one array keyed by row ID, so a
            // row fetched by either key must carry the same keys.
            'SELECT id, comment_id, email_hash, script, category, content, created_at FROM %i WHERE email_hash = %s ORDER BY id ASC',
            $table,
            $email_hash
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WordPress.DB.PreparedSQL only recognises the literal $wpdb global, so it flags this injected $this->wpdb query in every form -- inlining the prepare() call here reports 6 findings instead of 1 (verified via dev/plugin-check.sh). $sql must therefore stay the return value of the bare $this->wpdb->prepare() call directly above: the suppression is anchored on this line and would also silence a query built any other way.
        $rows = $this->wpdb->get_results( $sql );

        if ( ! is_array( $rows ) ) {
            return array();
        }

        $samples = array();
        foreach ( $rows as $row ) {
            $samples[] = (array) $row;
        }
        return $samples;
    }

    /**
     * Delete every sample whose author email hash matches.
     *
     * The secondary subject key for the Privacy API eraser. Like
     * delete_by_comment_ids() it returns false rather than 0 when the query
     * failed, so the eraser can report the data as retained.
     *
     * @param string $email_hash Salted SHA-256 hash from ASW_Detector::hash_email().
     * @return int|false Number of rows removed (0 when nothing matched or the
     *                   hash was empty), or false when the query failed.
     */
    public function delete_by_email_hash( string $email_hash ) {
        if ( '' === $email_hash ) {
            return 0;
        }

        $table = $this->get_table_name();
        $sql   = $this->wpdb->prepare(
            'DELETE FROM %i WHERE email_hash = %s',
            $table,
            $email_hash
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WordPress.DB.PreparedSQL only recognises the literal $wpdb global, so it flags this injected $this->wpdb query in every form -- inlining the prepare() call here reports 6 findings instead of 1 (verified via dev/plugin-check.sh). $sql must therefore stay the return value of the bare $this->wpdb->prepare() call directly above: the suppression is anchored on this line and would also silence a query built any other way.
        $deleted = $this->wpdb->query( $sql );

        if ( false === $deleted ) {
            return false;
        }
        if ( ! is_numeric( $deleted ) || $deleted <= 0 ) {
            return 0;
        }

        $this->bump_revision();
        return (int) $deleted;
    }

    /**
     * Delete every sample no subject key can reach.
     *
     * A row with comment_id = 0 and an empty email_hash is unreachable by both
     * Privacy API lookups: normalize_comment_ids() drops non-positive IDs and
     * find_by_email_hash() / delete_by_email_hash() refuse an empty hash (it is
     * what every address-less comment stores, so matching on it would match them
     * all). insert() refuses to create such a row, so at runtime it can only come
     * from a table that predates the two subject keys: dbDelta() adds the columns
     * in place and existing rows take their defaults. Those rows still hold the
     * trained comment's full text, which readme.txt and the suggested privacy
     * policy text promise is exportable and erasable -- and it cannot be, ever, by
     * any request. Dropping them is the only way to keep that promise; the seed
     * corpus in data/*.txt is untouched, so detection quality survives it.
     *
     * Called from the two schema writers before they record DB_VERSION, so a
     * refused DELETE leaves the version unstamped and the next attempt retries,
     * exactly as a refused ALTER does.
     *
     * @return int|false Number of rows removed (0 when none matched), or false
     *                   when the query failed.
     */
    public function delete_unattributed() {
        $table = $this->get_table_name();
        $sql   = $this->wpdb->prepare(
            'DELETE FROM %i WHERE comment_id = %d AND email_hash = %s',
            $table,
            0,
            ''
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WordPress.DB.PreparedSQL only recognises the literal $wpdb global, so it flags this injected $this->wpdb query in every form -- inlining the prepare() call here reports 6 findings instead of 1 (verified via dev/plugin-check.sh). $sql must therefore stay the return value of the bare $this->wpdb->prepare() call directly above: the suppression is anchored on this line and would also silence a query built any other way.
        $deleted = $this->wpdb->query( $sql );

        if ( false === $deleted ) {
            return false;
        }
        if ( ! is_numeric( $deleted ) || $deleted <= 0 ) {
            return 0;
        }

        $this->bump_revision();
        return (int) $deleted;
    }

    /**
     * Whether the live table matches the DB_VERSION layout.
     *
     * dbDelta() cannot report failure: it builds its return value from the diff
     * it *intends* to apply and then fires each CREATE/ALTER through
     * $wpdb->query() without checking the result, so a migration the database
     * refused (no ALTER privilege on a managed host, a metadata lock, a full
     * disk) is indistinguishable from one that succeeded. Callers therefore ask
     * here before recording DB_VERSION — recording it optimistically would
     * short-circuit every later upgrade attempt and leave the table on the old
     * layout forever, which is exactly the breakage the version option exists
     * to prevent.
     *
     * Both the columns and the indexes are probed. dbDelta() emits the ADD
     * COLUMN and the ADD KEY as separate ALTER statements and checks neither
     * result, so a run in which the column landed and the index did not is
     * indistinguishable from a complete one; stamping the version on the column
     * alone would leave the index missing for good, since the version gate then
     * short-circuits every later upgrade attempt.
     *
     * Keep VERSIONED_COLUMNS / VERSIONED_INDEXES in step with DB_VERSION: when a
     * future DDL change bumps the version, probe whatever that version added.
     *
     * @return bool True when every column and index the current DB_VERSION adds
     *              is present on the live table.
     */
    public function schema_is_current(): bool {
        $table = $this->get_table_name();

        // SHOW COLUMNS / SHOW INDEX against a table that does not exist is a hard
        // MySQL error, and wpdb::print_error() writes "WordPress database error
        // ... doesn't exist" to error_log() whether or not show_errors is on --
        // gated only by suppress_errors. activate() probes right after dbDelta(),
        // so on a host whose DB user cannot CREATE the table every activation and
        // every later admin_init would append a plugin-attributable line to the
        // debug.log dev/smoke.sh scans, plus unexpected output during activation.
        // A missing column or index is not affected: those return an empty result
        // set, not an error.
        $suppressed = $this->wpdb->suppress_errors( true );

        try {
            return $this->probe_versioned_schema( $table );
        } finally {
            $this->wpdb->suppress_errors( $suppressed );
        }
    }

    /**
     * Probe the live table for every column and index the current DB_VERSION adds.
     *
     * @param string $table Table name.
     * @return bool
     */
    private function probe_versioned_schema( string $table ): bool {
        foreach ( self::VERSIONED_COLUMNS as $column ) {
            $sql = $this->wpdb->prepare(
                'SHOW COLUMNS FROM %i LIKE %s',
                $table,
                $this->wpdb->esc_like( $column )
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WordPress.DB.PreparedSQL only recognises the literal $wpdb global, so it flags this injected $this->wpdb query in every form -- inlining the prepare() call here reports 6 findings instead of 1 (verified via dev/plugin-check.sh). $sql must therefore stay the return value of the bare $this->wpdb->prepare() call directly above: the suppression is anchored on this line and would also silence a query built any other way.
            $found = $this->wpdb->get_var( $sql );

            if ( null === $found || '' === (string) $found ) {
                return false;
            }
        }

        foreach ( self::VERSIONED_INDEXES as $index ) {
            // SHOW INDEX has no LIKE form; its WHERE clause filters the result
            // set instead. get_var() yields the row's first column (the table
            // name), so any non-empty answer means the index exists.
            $sql = $this->wpdb->prepare(
                'SHOW INDEX FROM %i WHERE Key_name = %s',
                $table,
                $index
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WordPress.DB.PreparedSQL only recognises the literal $wpdb global, so it flags this injected $this->wpdb query in every form -- inlining the prepare() call here reports 6 findings instead of 1 (verified via dev/plugin-check.sh). $sql must therefore stay the return value of the bare $this->wpdb->prepare() call directly above: the suppression is anchored on this line and would also silence a query built any other way.
            $found = $this->wpdb->get_var( $sql );

            if ( null === $found || '' === (string) $found ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fingerprint one script's stored samples for the model cache key.
     *
     * Folds the single GROUP BY read into spam:<count>:<maxid>|ham:<count>:<maxid>,
     * with 0:0 for a category holding no rows. MAX(id) is what makes it exact:
     * a privacy erasure followed by a new training insert leaves COUNT(*) where
     * it was, so a count-only fingerprint would keep serving a cached model unit
     * trained on the erased text.
     *
     * @param string $script Script group.
     * @return string|null The fingerprint, or null when the read failed -- never
     *                     a zeroed fingerprint, which the caller could not tell
     *                     apart from a script with no samples.
     */
    public function script_fingerprint( string $script ): ?string {
        $table = $this->get_table_name();

        // Against a table that was never created -- the refused-CREATE case the
        // schema-version retry exists for -- this SELECT is a hard MySQL error,
        // and wpdb::print_error() writes it to error_log() whether or not
        // show_errors is on, gated only by suppress_errors. This query runs on
        // every comment, so unbracketed it would append a plugin-attributable
        // line to the debug.log dev/smoke.sh scans on every submission.
        $suppressed = $this->wpdb->suppress_errors( true );

        try {
            // A failed query is NOT reported by the return value: wpdb::query()
            // calls flush() -- which sets last_result to array() and clears
            // last_error -- before it runs, and on an error it returns false
            // leaving last_result at that empty array, which get_results() then
            // hands straight back. An empty array is therefore exactly what a
            // script with no rows returns, and last_error is the only thing that
            // tells the two apart. It is set regardless of suppress_errors,
            // which gates only print_error(). Clearing it first covers the one
            // path that returns before flush() -- a third-party 'query' filter
            // emptying the SQL -- so a previous statement's error cannot be read
            // as this one's.
            $this->wpdb->last_error = '';

            $sql = $this->wpdb->prepare(
                'SELECT category, COUNT(*) AS sample_count, MAX(id) AS max_id FROM %i WHERE script = %s GROUP BY category',
                $table,
                $script
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WordPress.DB.PreparedSQL only recognises the literal $wpdb global, so it flags this injected $this->wpdb query in every form -- inlining the prepare() call here reports 6 findings instead of 1 (verified via dev/plugin-check.sh). $sql must therefore stay the return value of the bare $this->wpdb->prepare() call directly above: the suppression is anchored on this line and would also silence a query built any other way.
            $rows = $this->wpdb->get_results( $sql );

            $failed = ( '' !== (string) $this->wpdb->last_error );
        } finally {
            $this->wpdb->suppress_errors( $suppressed );
        }

        if ( $failed || ! is_array( $rows ) ) {
            return null;
        }

        $counts = array(
            'spam' => '0:0',
            'ham'  => '0:0',
        );
        foreach ( $rows as $row ) {
            $row      = (array) $row;
            $category = (string) ( $row['category'] ?? '' );
            if ( ! isset( $counts[ $category ] ) ) {
                continue;
            }
            $counts[ $category ] = (int) ( $row['sample_count'] ?? 0 ) . ':' . (int) ( $row['max_id'] ?? 0 );
        }

        return 'spam:' . $counts['spam'] . '|ham:' . $counts['ham'];
    }

    /**
     * Fingerprint the whole table for the combined unit's cache key.
     *
     * The combined unit merges every script's rows, so its DB component has to
     * move on a write anywhere in the table. The global revision option does
     * that, but only as a *second* write: bump_revision() fires an UPDATE on
     * wp_options after the statement it follows and cannot report a refusal, so
     * a delete whose bump the database refused leaves the revision standing
     * still. ASW_Model_Store's post-write supersession check -- the guard that
     * stops a request which loaded its samples before an erasure from writing
     * them back after it -- is built on the fingerprint moving when rows go, so
     * it needs a component read from the table itself. COUNT(*) moves when rows
     * are erased and MAX(id) when rows are added, which is what makes an
     * erasure followed by a fresh training insert visible, exactly as in
     * script_fingerprint().
     *
     * @return string|null `<count>:<maxid>`, or null when the read failed --
     *                     never a zeroed fingerprint, which the caller could not
     *                     tell apart from an empty table.
     */
    public function table_fingerprint(): ?string {
        $table = $this->get_table_name();

        // The same bracket, for the same reason, as script_fingerprint():
        // against a table that was never created this SELECT is a hard MySQL
        // error, and wpdb::print_error() writes it to error_log() whether or not
        // show_errors is on, gated only by suppress_errors.
        $suppressed = $this->wpdb->suppress_errors( true );

        try {
            // A failed query is not reported by the return value -- get_results()
            // hands back the empty array flush() left behind, which is exactly
            // what an empty table returns. last_error is the only thing that
            // tells the two apart; see script_fingerprint().
            $this->wpdb->last_error = '';

            $sql = $this->wpdb->prepare(
                'SELECT COUNT(*) AS sample_count, MAX(id) AS max_id FROM %i',
                $table
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WordPress.DB.PreparedSQL only recognises the literal $wpdb global, so it flags this injected $this->wpdb query in every form -- inlining the prepare() call here reports 6 findings instead of 1 (verified via dev/plugin-check.sh). $sql must therefore stay the return value of the bare $this->wpdb->prepare() call directly above: the suppression is anchored on this line and would also silence a query built any other way.
            $rows = $this->wpdb->get_results( $sql );

            $failed = ( '' !== (string) $this->wpdb->last_error );
        } finally {
            $this->wpdb->suppress_errors( $suppressed );
        }

        // An ungrouped aggregate is one row even over an empty table, so no rows
        // at all is a failure however it was reported.
        if ( $failed || ! is_array( $rows ) || empty( $rows ) ) {
            return null;
        }

        $row = (array) $rows[0];

        return (int) ( $row['sample_count'] ?? 0 ) . ':' . (int) ( $row['max_id'] ?? 0 );
    }

    /**
     * Count samples for a given script and category.
     *
     * @param string $script   Script group.
     * @param string $category 'spam' or 'ham'.
     * @return int
     */
    public function count_by_script_category( string $script, string $category ): int {
        $table = $this->get_table_name();
        $sql   = $this->wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE script = %s AND category = %s',
            $table,
            $script,
            $category
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WordPress.DB.PreparedSQL only recognises the literal $wpdb global, so it flags this injected $this->wpdb query in every form -- inlining the prepare() call here reports 6 findings instead of 1 (verified via dev/plugin-check.sh). $sql must therefore stay the return value of the bare $this->wpdb->prepare() call directly above: the suppression is anchored on this line and would also silence a query built any other way.
        return (int) $this->wpdb->get_var( $sql );
    }

    /**
     * Get the current revision number.
     *
     * @return int
     */
    public function latest_revision(): int {
        return (int) get_option( self::REVISION_OPTION, 0 );
    }

    /**
     * Atomically increment the revision counter.
     */
    private function bump_revision(): void {
        // Ensure the option exists before attempting atomic increment.
        // Use add_option (no-op if exists) to avoid a race where two concurrent
        // requests both see "not exists" and both call update_option(…, 1).
        $current = get_option( self::REVISION_OPTION, false );
        if ( false === $current ) {
            add_option( self::REVISION_OPTION, 0 );
        }

        // Atomic increment via raw SQL to avoid race conditions.
        $sql = $this->wpdb->prepare(
            'UPDATE %i SET option_value = option_value + 1 WHERE option_name = %s',
            $this->wpdb->options,
            self::REVISION_OPTION
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WordPress.DB.PreparedSQL only recognises the literal $wpdb global, so it flags this injected $this->wpdb query in every form -- inlining the prepare() call here reports 6 findings instead of 1 (verified via dev/plugin-check.sh). $sql must therefore stay the return value of the bare $this->wpdb->prepare() call directly above: the suppression is anchored on this line and would also silence a query built any other way.
        $this->wpdb->query( $sql );

        // Invalidate object cache so get_option() returns the updated value.
        wp_cache_delete( self::REVISION_OPTION, 'options' );
        wp_cache_delete( 'alloptions', 'options' );
    }
}
