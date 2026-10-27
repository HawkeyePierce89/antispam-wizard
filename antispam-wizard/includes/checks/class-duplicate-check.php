<?php
/**
 * Duplicate comment spam check.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Detects duplicate comments from the same IP within a configurable time window.
 */
class ANTISPAMWIZARD_Duplicate_Check implements ANTISPAMWIZARD_Check {

    /**
     * Return the directory used for lock files, creating it if necessary.
     *
     * @return string Absolute path to the lock directory.
     */
    private static function get_lock_dir(): string {
        $upload_dir = wp_upload_dir();
        $lock_dir   = $upload_dir['basedir'] . '/.antispamwizard-locks';
        if ( ! is_dir( $lock_dir ) ) {
            wp_mkdir_p( $lock_dir );
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
     * Maximum allowed TTL in seconds (24 hours).
     *
     * Caps the user-configured duplicate_ttl to prevent unbounded transient
     * lifetimes. On sites without an external object cache (Redis/Memcached),
     * WordPress stores transients in the wp_options table and only deletes
     * expired ones lazily (on next read). A reasonable TTL cap limits how long
     * stale transients can linger before cleanup.
     */
    const MAX_TTL = 86400;

    /**
     * Maximum number of content-hash entries kept per hashed IP.
     *
     * Without an external object cache the file-locked path stores one transient
     * (two wp_options rows) per hashed IP holding a map of content hash =>
     * {count, first_seen}, so a sender posting unique texts can no longer add a
     * row per request. The map is capped, and a 33rd distinct text evicts the
     * entry with the lowest count (see increment_entry() for why lowest count
     * and not oldest, and for the residual limit the bound leaves: a body not
     * yet repeated is a count-1 entry and is shed like any other, so a repeat
     * spaced by more than 32 distinct texts from the same address never
     * accumulates). Above count 1 a counter carrying a verdict is dropped only
     * once an IP holds 32 texts that have *all* been repeated at least as often
     * as the newcomer — a state ordinary traffic does not reach, but one a
     * sender can build on purpose for 62 comments and then hold a body at
     * count 1 for one throwaway text per repeat; see increment_entry() for why
     * that residual is preferred to the alternative. That is what the fixed
     * storage cost buys — 32 entries (a 64-char hash plus two integers each)
     * keep the serialized value under a few kilobytes.
     */
    const MAX_ENTRIES_PER_IP = 32;

    /**
     * Run the duplicate check against comment data.
     *
     * @param array $comment_data WordPress comment data array.
     * @return ANTISPAMWIZARD_Check_Result
     */
    public function check( array $comment_data ): ANTISPAMWIZARD_Check_Result {
        $settings = ANTISPAMWIZARD_Settings::get_all();

        if ( empty( $settings['duplicate_enabled'] ) ) {
            return new ANTISPAMWIZARD_Check_Result( false, 'duplicate', __( 'check disabled', 'antispam-wizard' ) );
        }

        $content = isset( $comment_data['comment_content'] ) ? $comment_data['comment_content'] : '';
        // Same is_string() rule as ANTISPAMWIZARD_Detector::evaluate(): hash_ip() is typed
        // `string`, this file does not declare strict_types, and an array is a
        // TypeError rather than a coercion. evaluate() deliberately leaves
        // comment_author_IP_hashed unset for exactly that input, so an unguarded
        // fallback here would re-raise the TypeError the upstream guard exists to
        // prevent - run()'s catch would turn it into a debug.log line on every
        // comment and silently disable this check site-wide. Treat a non-string
        // IP as missing data instead.
        $ip_hash = isset( $comment_data['comment_author_IP_hashed'] ) && is_string( $comment_data['comment_author_IP_hashed'] )
            ? $comment_data['comment_author_IP_hashed']
            : ( isset( $comment_data['comment_author_IP'] ) && is_string( $comment_data['comment_author_IP'] )
                ? ANTISPAMWIZARD_Detector::hash_ip( $comment_data['comment_author_IP'] )
                : '' );

        if ( '' === $content || '' === $ip_hash ) {
            return new ANTISPAMWIZARD_Check_Result( false, 'duplicate', __( 'missing data', 'antispam-wizard' ) );
        }

        // A /u pattern returns null on a malformed subject and the comment body is
        // attacker-controlled, so an unguarded collapse has two remotely triggerable
        // effects: trim( null ) writes a plugin-attributable deprecation into debug.log,
        // and the resulting '' normalizes every malformed body from one IP into a single
        // hash bucket, so unrelated comments cross-count as duplicates of each other.
        // Sanitize first instead, as ANTISPAMWIZARD_Multi_Lang_Check::count_multi_script_words() does.
        $safe      = $content;
        $collapsed = preg_replace( '/[\s\p{Z}]+/u', ' ', $content );
        if ( null === $collapsed ) {
            $safe      = ANTISPAMWIZARD_Detector::sanitize_utf8( $content );
            $collapsed = preg_replace( '/[\s\p{Z}]+/u', ' ', $safe );
        }

        // A second failure can no longer be an encoding one, but a PCRE limit/JIT
        // failure would still yield null - and casting that to '' would re-create
        // the very collision described above. Fall back to the uncollapsed body
        // instead, as ANTISPAMWIZARD_Stop_Words_Check::normalize() does. Fall back to the
        // sanitized copy rather than the raw one: mb_strtolower() on malformed
        // UTF-8 substitutes bytes using the ambient, process-wide
        // mbstring.substitute_character, which is the very hazard sanitize_utf8()
        // exists to remove.
        $normalized   = mb_strtolower( trim( null === $collapsed ? $safe : $collapsed ), 'UTF-8' );
        $content_hash = hash( 'sha256', $normalized );

        $threshold = (int) $settings['duplicate_threshold'];
        $ttl       = min( (int) $settings['duplicate_ttl'], self::MAX_TTL );

        $count = self::atomic_increment( $ip_hash, $content_hash, $ttl );

        if ( $count >= $threshold ) {
            return new ANTISPAMWIZARD_Check_Result(
                true,
                'duplicate',
                // translators: 1: Number of identical submissions seen, 2: Configured threshold.
                sprintf( __( 'duplicate comment %1$d/%2$d', 'antispam-wizard' ), $count, $threshold )
            );
        }

        return new ANTISPAMWIZARD_Check_Result(
            false,
            'duplicate',
            // translators: 1: Number of identical submissions seen, 2: Configured threshold.
            sprintf( __( 'duplicate count %1$d/%2$d', 'antispam-wizard' ), $count, $threshold )
        );
    }

    /**
     * Atomically increment the counter for one IP + text pair.
     *
     * The two storage paths are keyed differently, so each derives its own key
     * here rather than having the caller compute both and throw one away. The
     * file-locked transient path is keyed per hashed IP only and keeps a capped
     * map of content hashes, so one sender costs one transient however many
     * distinct texts they send. The external object cache path stays keyed per
     * IP + text: its counter is an integer wp_cache_incr() can bump atomically.
     *
     * @param string $ip_hash      Hashed commenter IP.
     * @param string $content_hash Hash of the normalized comment text.
     * @param int    $ttl          Time-to-live in seconds.
     * @return int The incremented count.
     */
    private static function atomic_increment( string $ip_hash, string $content_hash, int $ttl ): int {
        if ( wp_using_ext_object_cache() ) {
            return self::cache_increment( 'antispamwizard_dup_' . substr( hash( 'sha256', $ip_hash . $content_hash ), 0, 32 ), $ttl );
        }
        return self::file_locked_increment( 'antispamwizard_dup_' . substr( hash( 'sha256', $ip_hash ), 0, 32 ), $content_hash, $ttl );
    }

    /**
     * Increment using wp_cache_add/wp_cache_incr (atomic on external object caches).
     *
     * Deliberately still keyed per IP + text, holding a bare integer counter.
     * This path is not converted to the per-IP map the transient path uses:
     * wp_cache_incr() can only bump an integer atomically, so a map would have to
     * be read, modified and written back — a race the object cache offers no
     * primitive to serialise, and the reason this path exists at all. Row growth,
     * the concern the map addresses, is not a concern here either: an external
     * cache evicts under its own memory bound rather than accumulating
     * wp_options rows.
     *
     * @param string $cache_key Cache key.
     * @param int    $ttl       Time-to-live in seconds.
     * @return int The incremented count.
     */
    private static function cache_increment( string $cache_key, int $ttl ): int {
        $group = 'antispamwizard';

        // wp_cache_add returns false if the key already exists, true if set.
        if ( wp_cache_add( $cache_key, 1, $group, $ttl ) ) {
            return 1;
        }

        // Key exists, atomically increment. wp_cache_incr on Redis/Memcached
        // does not alter the key's TTL, so the original expiry from
        // wp_cache_add is preserved — matching the Go reference which counts
        // entries within a fixed [now-window, now] range rather than sliding
        // the window forward on each hit.
        $result = wp_cache_incr( $cache_key, 1, $group );
        if ( false === $result ) {
            // Deliberate window-semantics trade-off (fail-open): on an external
            // object cache the only state we keep is the integer counter under
            // $cache_key — there is no persisted first_seen anchor (unlike the
            // file-locked transient path). When the backend evicts the key under
            // memory pressure, or its TTL lapses, the count resets to 1 and a
            // fresh window starts from "now". We accept this: a defender that
            // occasionally undercounts (lets a borderline duplicate through after
            // an eviction) is strictly safer than one that overcounts and marks
            // legitimate comments as spam. The reset is the same event the Go
            // reference models as the window expiring, so the behaviour is
            // intentional rather than a bug to be worked around.
            //
            // Re-add so subsequent comments are tracked instead of silently
            // losing the count.
            if ( wp_cache_add( $cache_key, 1, $group, $ttl ) ) {
                return 1;
            }
            // Another request recreated the key before our add(). Increment
            // so this request is still counted instead of being silently lost.
            $retry = wp_cache_incr( $cache_key, 1, $group );
            return ( false !== $retry ) ? (int) $retry : 1;
        }

        return (int) $result;
    }

    /**
     * Increment using file locking and transients (single-server fallback).
     *
     * @param string $transient_key Per-IP transient key.
     * @param string $content_hash  Hash of the normalized comment text.
     * @param int    $ttl           Time-to-live in seconds.
     * @return int The incremented count.
     */
    private static function file_locked_increment( string $transient_key, string $content_hash, int $ttl ): int {
        // Use a bounded pool of 256 lock files (2 hex chars) instead of one
        // per transient key. This prevents attacker-controlled temp-file growth
        // while still providing sufficient concurrency granularity.
        //
        // The bucket must be derived from the *per-IP transient key*, because
        // that is what makes every writer of one IP's map take the same lock -
        // the only thing serialising the whole-map read-modify-write below.
        // Deriving it from the content hash (or the object cache's per-IP + text
        // key) would let two of one sender's requests interleave and each write
        // back a map missing the other's increments.
        //
        // The cost is coarser locking than the per-IP + text keying this
        // replaced: one address's concurrent submissions now queue on one lock
        // instead of spreading over the pool, and where every comment_author_IP
        // is the same value — a CDN or proxy that does not restore the real
        // address, CGNAT, the CF7 remote_ip meta — that is one lock for the
        // whole site. Accepted rather than mitigated: the section it guards is
        // two option queries, and LOCK_EX must stay blocking. LOCK_NB with a
        // fall-through would hand exactly the contended case — many concurrent
        // writers on one IP — the unserialised path, losing the increments this
        // keying exists to preserve.
        $bucket    = substr( $transient_key, -2 );
        $lock_file = self::get_lock_dir() . '/antispamwizard_dup_' . $bucket . '.lock';
        $fp        = @fopen( $lock_file, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- flock() needs a real stream handle; WP_Filesystem exposes no locking primitive. The @ suppresses PHP's own "failed to open stream" warning, which carries the absolute path into the web-readable debug.log; the sanitized diagnostic below reports the failure instead.
        if ( false === $fp ) {
            // The file name only, never the absolute path: this runs on an
            // unauthenticated comment submission, debug.log is web-readable on
            // misconfigured hosts, and the path carries the document root. Same
            // rule as ANTISPAMWIZARD_Detector::run() and ANTISPAMWIZARD_CF7_Integration::filter_spam(),
            // which log basename( $e->getFile() ) for that reason; the bucket
            // name still identifies which of the 256 lock files failed.
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational diagnostic: the lock file could not be opened, so this write proceeds unserialised. Silent failure would make concurrent-write corruption undebuggable; the log line carries no user input.
            error_log( 'ANTISPAMWIZARD: unable to open lock file: ' . basename( $lock_file ) );
        }

        // Without the lock the read-modify-write proceeds unserialised, and
        // because the value is now one IP's whole map a lost update costs that
        // IP's other counters too, not just this text's. Still fail open: a host
        // without flock() must keep detecting, not stop commenting.
        $locked = $fp && flock( $fp, LOCK_EX );

        // The release must sit in a finally, as every other lock site in the
        // plugin does: increment_stored_count() calls get_transient() and
        // set_transient(), which fire pre_transient_*, option_* and
        // update_option filters, so third-party code runs inside the critical
        // section and can throw. ANTISPAMWIZARD_Detector::run() catches that Throwable and
        // carries on to the next check, so a plain release after the call would
        // leave this exclusive lock held for the rest of the request - and
        // because the bucket is now per IP rather than per IP + text, on a
        // shared comment_author_IP that blocks every other submitter site-wide
        // until the request ends.
        try {
            $count = self::increment_stored_count( $transient_key, $content_hash, $ttl );
        } finally {
            if ( $locked ) {
                flock( $fp, LOCK_UN );
            }
            if ( $fp ) {
                fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the flock() stream handle opened above; WP_Filesystem exposes no locking primitive.
            }
        }

        // Do not unlink the lock file: if request B is blocked in flock() on
        // this inode and we unlink, request C can create a new file at the same
        // path, acquire a lock on the new inode, and race with B. The bounded
        // pool (256 files max) keeps this safe — files are empty and persist
        // in the uploads/.antispamwizard-locks/ directory.

        return $count;
    }

    /**
     * Read, increment and write back one entry of a hashed IP's map.
     *
     * This is the read-modify-write critical section file_locked_increment()
     * brackets with flock(): the read, the increment and the write back must all
     * fall inside one hold of the per-IP lock, or two of one sender's requests
     * interleave and each writes back a map missing the other's increments. It
     * is called unconditionally — the lock is best effort, and a host without
     * flock() runs the same body unserialised rather than stopping detection —
     * so keeping it a separate method is what makes the extent of the section a
     * single call rather than a span of statements the next edit can widen.
     *
     * @param string $transient_key Per-IP transient key.
     * @param string $content_hash  Hash of the normalized comment text.
     * @param int    $ttl           Time-to-live in seconds.
     * @return int The incremented count for this content hash.
     */
    private static function increment_stored_count( string $transient_key, string $content_hash, int $ttl ): int {
        $entries = self::read_transient_data( $transient_key, $ttl );
        $entries = self::increment_entry( $entries, $content_hash );
        self::write_transient_data( $transient_key, $entries, $ttl );

        return $entries[ $content_hash ]['count'];
    }

    /**
     * Increment one content hash's entry, evicting the least repeated when full.
     *
     * A hash absent from the map is seeded at count 0 first. If the map then
     * exceeds MAX_ENTRIES_PER_IP entries, room is made by dropping the entry
     * with the **lowest count**, ties broken by the oldest first_seen (first
     * match wins on a further tie) — see the constant's docblock for the
     * trade-off that bound buys. The entry just incremented is never a
     * candidate: this request's own text must survive its own write.
     *
     * Evicting by count rather than by age is load-bearing, not a preference.
     * first_seen is the window anchor and is never refreshed, so the oldest
     * entry is by construction the *longest-tracked* text — the one that has
     * been repeated over the widest span and sits closest to the threshold.
     * Age-based eviction therefore discards exactly the counter this check
     * exists to accumulate, and two reachable streams exploit it: a bot that
     * interleaves its repeated body with MAX_ENTRIES_PER_IP unique fillers
     * (so the body never reaches the threshold), and any shared
     * comment_author_IP — a CDN or proxy that does not restore the real
     * address, CGNAT, the CF7 remote_ip meta — where *other people's* ordinary
     * comments would evict a spammer's accumulating count. Count-based
     * eviction spends the *least* repeated slot instead, so ordinary
     * single-shot traffic is what gets shed while a counter accumulates.
     *
     * It closes those two streams only from count 2 upward, and that limit is
     * sharper on a shared IP than the filler wording suggests: a body posted
     * for the first time *is* a count-1 entry, so it is shed like any filler,
     * and on a shared address the 32 distinct texts that shed it are other
     * people's ordinary comments rather than anything the sender has to send.
     * A repeat spaced by more than a full map's worth of unrelated traffic
     * therefore never reaches count 2 and never accumulates - the object cache
     * path, still keyed per IP + text, is unaffected. What the policy
     * guarantees is that no *stored* counter is evicted while a less repeated
     * stored one is available to spend. It is not that a repeated counter is
     * never dropped, and the exception is not only the shared-IP accident it
     * looks like: the entry being written is excluded from victim selection, so
     * once every other slot holds a text repeated at least as often — a map
     * wholly at count 2 or above — a brand-new count-1 text is the one thing
     * that *cannot* be spent, and a counter carrying a verdict goes instead.
     * A sender can build that state deliberately (32 texts sent twice each, 62
     * comments once the body under test is one of them) and then hold the body
     * at count 1 indefinitely for **one** throwaway text per repeat, not the
     * MAX_ENTRIES_PER_IP-per-repeat the filler wording above implies: the
     * throwaway evicts the body, the body comes back and evicts the throwaway,
     * and the wall of count-2 entries makes those two the only minimum forever.
     * test_one_filler_per_repeat_holds_a_body_at_count_one_against_a_repeated_wall()
     * pins it as a known boundary.
     *
     * Admitting the newcomer to victim selection — dropping it rather than a
     * counter when it is the strict minimum — closes that and opens something
     * strictly worse, which is why it is not done: the same wall would then
     * refuse every new text outright, so a body repeated against it is never
     * counted at all and the bypass costs nothing per repeat instead of one
     * comment. Both residuals are inherent to a fixed bound; this is the one
     * that keeps the sender paying per spam comment. It is also what makes the
     * exclusion safe for increment_stored_count()'s `: int` return, since the
     * entry just written is always still in the map (see
     * test_a_new_text_survives_its_own_write_against_a_fully_repeated_map()).
     *
     * The trim runs on *every* write rather than only when a new hash arrives,
     * and loops rather than evicting once, so a map that somehow arrived
     * oversized — a value written by an older version with a larger cap —
     * converges back to the bound instead of staying oversized for good. Gating
     * it on the new-hash branch would leave a sender who only ever repeats texts
     * already in the map holding the oversized row forever, which is the one
     * case the convergence exists for.
     *
     * @param array<string, array{count: int, first_seen: int}> $entries      Pruned entry map.
     * @param string                                            $content_hash Hash of the normalized comment text.
     * @return array<string, array{count: int, first_seen: int}> The updated map.
     */
    private static function increment_entry( array $entries, string $content_hash ): array {
        if ( ! isset( $entries[ $content_hash ] ) ) {
            $entries[ $content_hash ] = array(
                'count'      => 0,
                'first_seen' => time(),
            );
        }

        ++$entries[ $content_hash ]['count'];

        while ( count( $entries ) > self::MAX_ENTRIES_PER_IP ) {
            $victim_hash  = null;
            $victim_count = null;
            $victim_seen  = null;
            foreach ( $entries as $hash => $entry ) {
                if ( $hash === $content_hash ) {
                    continue;
                }
                if ( null === $victim_count
                    || $entry['count'] < $victim_count
                    || ( $entry['count'] === $victim_count && $entry['first_seen'] < $victim_seen )
                ) {
                    $victim_count = $entry['count'];
                    $victim_seen  = $entry['first_seen'];
                    $victim_hash  = $hash;
                }
            }
            if ( null === $victim_hash ) {
                break;
            }
            unset( $entries[ $victim_hash ] );
        }

        return $entries;
    }

    /**
     * Read a hashed IP's entry map, dropping corrupt and expired entries.
     *
     * The transient holds one map per hashed IP: content hash => {count,
     * first_seen}. Anything else is dropped rather than trusted, so a corrupted
     * value degrades to an empty map instead of a PHP diagnostic: a non-array
     * value yields an empty map, and a top-level member that is not an array —
     * which is what the bootstrap set_transient() stub's injected __ttl scalar
     * is — or that is missing either integer field is skipped. Each surviving
     * entry is then rebuilt to exactly {count, first_seen}, so a stored entry
     * carrying extra members cannot smuggle them back into the next write. An
     * entry whose first_seen is at least $ttl old is dropped too: that is the
     * per-entry window reset, and it is per entry because each text has its own
     * window. So is one dated in the future, which no age test can ever expire.
     *
     * @param string $transient_key Per-IP transient key.
     * @param int    $ttl           Time-to-live in seconds.
     * @return array<string, array{count: int, first_seen: int}>
     */
    private static function read_transient_data( string $transient_key, int $ttl ): array {
        $raw = get_transient( $transient_key );

        if ( ! is_array( $raw ) ) {
            return array();
        }

        $now     = time();
        $entries = array();

        foreach ( $raw as $hash => $entry ) {
            if ( ! is_string( $hash ) || ! is_array( $entry ) ) {
                continue;
            }

            if ( ! isset( $entry['count'], $entry['first_seen'] )
                || ! is_int( $entry['count'] )
                || ! is_int( $entry['first_seen'] )
            ) {
                continue;
            }

            // A first_seen in the future is dropped as well, not just one older
            // than the window. The clock can step backwards (an NTP correction, a
            // VM or container resume, a host that boots wrong and then syncs),
            // which leaves entries written before the step dated ahead of now.
            // Such an entry never satisfies the age test - the difference is
            // negative - so it would be kept for good, and because
            // write_transient_data() derives the row's lifetime from the newest
            // first_seen, one future-dated entry would also stretch the whole
            // map's lifetime past MAX_TTL and immortalise every *other* counter
            // in that IP's map, so a shared address could be over-blocked
            // indefinitely. Dropping it restarts that one text's window instead,
            // which is the fail-open direction this check prefers everywhere.
            if ( $entry['first_seen'] > $now || ( $now - $entry['first_seen'] ) >= $ttl ) {
                continue;
            }

            $entries[ $hash ] = array(
                'count'      => $entry['count'],
                'first_seen' => $entry['first_seen'],
            );
        }

        return $entries;
    }

    /**
     * Write a hashed IP's entry map with the lifetime of its youngest entry.
     *
     * The remaining lifetime is measured from the newest first_seen, so the
     * transient survives exactly as long as its youngest entry's window; older
     * entries inside it are dropped on read once their own window has passed.
     *
     * @param string $transient_key Per-IP transient key.
     * @param array  $entries       Map of content hash => {count, first_seen}.
     * @param int    $ttl           Original time-to-live in seconds.
     */
    private static function write_transient_data( string $transient_key, array $entries, int $ttl ): void {
        $newest = 0;
        foreach ( $entries as $entry ) {
            if ( $entry['first_seen'] > $newest ) {
                $newest = $entry['first_seen'];
            }
        }

        $remaining = max( 1, $ttl - ( time() - $newest ) );
        set_transient( $transient_key, $entries, $remaining );
    }
}
