<?php
/**
 * Lazy per-writing-system model loader.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Loads one writing system's models per submission instead of all ten.
 *
 * The plugin used to read all 22 corpus files, unserialise a single transient
 * holding every per-script classifier plus the combined one, and re-tokenise
 * every spam line of every script on every comment and every Contact Form 7
 * submission. This store splits that into independently cached units -- one per
 * script group plus one combined -- and loads a unit only when something
 * actually asks for it. A request loads the unit for the script it detects and
 * nothing else; a warm request reads no corpus file at all.
 *
 * The resolution rules the Bayesian and Similarity checks apply to a unit are
 * the ones build_checks() used to apply to the monolithic cache, so verdicts
 * are unchanged with one deliberate exception, documented in full on
 * build_combined_unit(): the combined unit's *similarity corpus* holds the
 * legacy samples and the unknown-script DB rows only, never the ten per-script
 * lists, which is what brings that unit under the object cache's per-item
 * limit. ANTISPAMWIZARD_Similarity_Check reaches that corpus for *any* body whose
 * detected script has no similarity list of its own, and every such body can
 * score differently than it did against the monolithic cache. On the shipped
 * corpus that is the bodies ANTISPAMWIZARD_Script_Detector::detect() calls 'unknown',
 * which have no unit at all; one of the ten named scripts reaches it only when
 * its own list comes out empty. That list is built from the script's *spam*
 * samples alone, so a populated ham corpus does not fill it: it is empty when
 * the script's spam corpus file is missing, unreadable or empty with no
 * learned spam samples under that script, or when every spam sample of it
 * tokenises to nothing under the site's excluded tokens.
 *
 * A unit's size is a standing constraint, not a one-off measurement: a
 * persistent object cache refuses an item of 1 048 576 bytes or more silently,
 * after which every submission routed to that unit pays a cold rebuild.
 * Four measures together keep it, and between them they bound the site's own
 * learned history as well as the shipped corpus: the packed representation,
 * the compressed envelope compression_applies() confines to the cache,
 * LEARNED_SIMILARITY_BUDGET on the learned half of a similarity corpus, and
 * storable_unit(), which weighs the value it is about to write against
 * MAX_CACHED_UNIT_BYTES and prunes the dictionary until the cache can hold it.
 * CorpusQualityTest pins all of it: the shipped corpus against the bound, an
 * unbounded learning history against it, and the full-dictionary worst case.
 *
 * Every transient key keeps the literal `antispamwizard_classifiers_` prefix, because
 * uninstall.php removes the cache with a LIKE over that prefix.
 *
 * A unit's key names the unit (`antispamwizard_classifiers_cyrillic`, `antispamwizard_classifiers_combined`)
 * and nothing else; the fingerprint of the samples it was built from is stored
 * *inside* the value and checked on read. That is deliberate: with the
 * fingerprint in the key, a rebuild wrote a new row beside the one it replaced
 * instead of overwriting it, so every corpus edit and every training write left
 * a full orphaned unit in wp_options until its TTL ran out -- and a personal-data
 * erasure, which can only name the keys it can compute, purged the current
 * generation while an older unit kept the erased comment's text readable as a
 * token list. With the key fixed there is no older generation to find.
 */
class ANTISPAMWIZARD_Model_Store {

    /**
     * Shared prefix of every unit's transient key.
     *
     * uninstall.php deletes `_transient_antispamwizard_classifiers_%` by LIKE, so a unit
     * key that dropped this prefix would leak past uninstall.
     */
    public const TRANSIENT_PREFIX = 'antispamwizard_classifiers_';

    /**
     * Key suffix of the combined unit. Not a script group name, so it can
     * never collide with a per-script unit's key.
     */
    private const COMBINED_UNIT = 'combined';

    /**
     * The two members every value this class writes carries, and the only two
     * that carry text.
     *
     * Every write goes through export_script_unit(), export_combined_unit() or
     * BLANKED_UNIT, and all three set both keys -- the other two members a unit
     * may carry (`fingerprint`, a hash, and `has_script_samples`, a boolean) are
     * absent from at least one of them and hold no text in any case.
     *
     * holds_training_text() uses the list to recognise a unit: a value missing
     * either member is not one this class wrote, so nothing can be concluded
     * from what its members do or do not hold.
     */
    private const UNIT_TEXT_MEMBERS = array( 'classifier', 'samples' );

    /**
     * The value a unit is blanked to when its delete was refused.
     *
     * Recognisably a unit -- so a later read of it concludes "no training text"
     * rather than "shape I do not know" -- and recognisably a *text-free* one.
     * It deliberately carries no `fingerprint`, which is what makes the row
     * inert as well as empty: fingerprint_matches() rebuilds a value without one
     * rather than serving it.
     */
    private const BLANKED_UNIT = array(
        'classifier' => null,
        'samples'    => array(),
    );

    /**
     * Envelope member a compressed unit is stored under.
     *
     * Under an external object cache a unit is written as
     * `array( 'zlib' => gzcompress( serialize( $unit ) ) )` rather than as the
     * bare array, because the value has to stay under memcached's
     * 1 048 576-byte per-item limit for the whole life of a site that keeps
     * learning: a persistent object cache refuses an item over it silently,
     * after which every submission in that writing system pays a cold rebuild
     * for good. Deflate takes the shipped Cyrillic unit from 657 004 bytes to
     * 184 533 and the combined one from 765 095 to 283 546.
     *
     * The member is explicit rather than inferred so the stored form can be
     * recognised without guessing: a value carrying it is this class's
     * compressed envelope, and one that does not is either the plain array the
     * option-backed path writes or something this class did not write at all.
     *
     * See compression_applies() for why the envelope is confined to the cache.
     */
    private const COMPRESSED_MEMBER = 'zlib';

    /**
     * gzcompress() level every unit is written at.
     *
     * Measured over the two units that bind, with a full 50 000-token
     * dictionary in both categories plus that script's whole shipped similarity
     * corpus: cyrillic 2 085 461 raw -> 836 889 at level 4, 771 963 at level 6,
     * 755 307 at level 9. Level 6 buys 64 926 bytes of margin over level 4 --
     * 45 % of what is left under the limit once the learned-sample budget is
     * spent -- for roughly 20 ms more on a cold rebuild, which is paid once per
     * unit per day. Level 9 buys 16 656 more for several times that again, so
     * level 6 is the pick and level 4 the recorded alternative.
     *
     * Decompression is essentially level-independent (1.2-1.9 ms per unit), so
     * the warm path pays the same whichever level is chosen.
     */
    private const COMPRESSION_LEVEL = 6;

    /**
     * Packed bytes of *learned* spam a unit's similarity corpus may carry.
     *
     * The shipped corpus is fixed and measured; a site's learned samples are
     * not, and nothing else in the plugin bounds them. Without a cap a unit
     * grows with the site's whole moderation history until it crosses
     * memcached's 1 048 576-byte per-item limit, after which a persistent
     * object cache refuses the write silently and every submission in that
     * writing system pays a cold rebuild for good.
     *
     * The number is what is left once the worst case is paid for. With a full
     * ANTISPAMWIZARD_Naive_Bayes::MAX_DICTIONARY_SIZE dictionary in *both* categories
     * (synthesised from 4-10-character novel tokens) plus that script's whole
     * shipped similarity corpus, the binding unit -- cyrillic -- is 2 085 461
     * bytes raw and 771 963 compressed at COMPRESSION_LEVEL; combined is
     * 680 101 and latin 589 456. Deflate never expands its input by more than
     * ~0.03 %, so B packed bytes of learned lists add at most B compressed even
     * if they do not compress at all. At B = 131 072 the binding worst case is
     * 903 035 bytes, 86.1 % of the limit, leaving 145 541 bytes of margin
     * (combined 811 173 / 77.4 %, latin 720 528 / 68.7 %).
     *
     * What it buys: ~212 samples of the pessimistic CorpusQualityTest shape
     * (40 words of entirely novel vocabulary, 617 packed bytes each), or ~430
     * typical 300-character comments at ~300 packed bytes -- against a shipped
     * Cyrillic corpus of 3 527 spam lists.
     *
     * Only the Similarity check's corpus is capped. The classifier keeps
     * learning from every sample, and its own bounds are
     * ANTISPAMWIZARD_Naive_Bayes::MAX_DICTIONARY_SIZE (the token count) and, on a
     * cache-backed host, MAX_CACHED_UNIT_BYTES through storable_unit() (the
     * bytes). That is a deliberate verdict change, documented on
     * admit_within_budget().
     */
    public const LEARNED_SIMILARITY_BUDGET = 131072;

    /**
     * Bytes of LEARNED_SIMILARITY_BUDGET each admitted sample costs on top of
     * its own packed length.
     *
     * A budget counted in joined token bytes alone bounds the *strings* and not
     * the array that holds them, where every element also costs its index and
     * its string header -- `i:12345;s:617:"…";`, about 16 bytes before the
     * payload. That is noise against a 617-byte sample and is not noise against
     * a short one: without the allowance the number of admitted samples is
     * bounded only by how short a sample can tokenise to, so a history of
     * three-byte tokenisations admits some 43 000 lists that the budget scores
     * at 131 072 bytes and that serialize to roughly 775 000 -- on the
     * cache-backed host without zlib, where the plain packed array is what the
     * item limit weighs, enough for the samples alone to cross it. Nothing can
     * be pruned back there: storable_unit() spends vocabulary, not corpus, so
     * the unit would simply never be cached again.
     *
     * Charging the overhead makes the admitted corpus bounded in serialized
     * bytes rather than only in token bytes -- at most
     * LEARNED_SIMILARITY_BUDGET / this many lists, whatever they hold. It costs
     * a realistic corpus nothing: at ~300 packed bytes a comment it is 5 % of
     * the budget, ~430 samples becoming ~408.
     */
    public const LEARNED_SAMPLE_OVERHEAD_BYTES = 16;

    /**
     * Bytes a stored unit may occupy in an external object cache.
     *
     * memcached refuses an item at or over 1 048 576 bytes *silently*, so a
     * unit that crosses it is never cached at all and every submission in that
     * writing system pays a cold rebuild for good. LEARNED_SIMILARITY_BUDGET
     * and the compressed envelope bound the two halves this class controls by
     * construction, but they cannot bound the third: ANTISPAMWIZARD_Tokenizer caps a
     * sample at 10 000 tokens and puts no cap on a token's *length*, and
     * ANTISPAMWIZARD_Naive_Bayes::learn() admits new tokens first-come-first-served until
     * its *count* cap, so the dictionary's bytes are decided by what the site
     * has learned. A full two-category dictionary of 10-character CJK tokens --
     * reachable, since a Cyrillic-dominant sample is word-tokenised and keeps a
     * CJK run whole as one 30-byte token -- packs to 3 300 304 bytes and
     * compresses to 2 210 916, 211 % of the limit on the classifier alone.
     *
     * This is therefore measured rather than predicted: storable_unit() weighs
     * the value it is about to write and, when it is over, drops the least
     * frequent half of each category's vocabulary and weighs it again. See
     * that method for why measuring beats every compression-independent budget
     * that was considered instead.
     *
     * The limit applies to the object cache alone. Without one a transient is
     * a `_transient_<name>` option row -- a longtext with no per-item limit --
     * and pruning a model there would be a quality regression bought for
     * nothing, so size_limit_applies() gates the whole mechanism on
     * wp_using_ext_object_cache().
     */
    public const MAX_CACHED_UNIT_BYTES = 1048576;

    /**
     * Bytes of MAX_CACHED_UNIT_BYTES held back for the cache item's own overhead.
     *
     * What memcached weighs against `-I` is the whole *item*, not the value:
     * `item_make_header()` counts the item struct, the key, the flags suffix
     * and the value. stored_size() can only measure the value, so weighing it
     * against the raw 1 048 576 leaves a sliver in which a unit passes here and
     * is still refused by the backend -- which is the silent
     * refuse-then-cold-rebuild-for-ever failure this whole mechanism exists to
     * prevent, and the one nothing in the plugin or the harnesses would notice.
     * A kilobyte covers the struct, a unit key (`antispamwizard_classifiers_devanagari`)
     * and any blog/salt prefix a drop-in adds, and costs a site nothing it
     * would otherwise have kept: a unit within a kilobyte of the limit is one
     * halving away either way.
     *
     * Note the limit itself is memcached's default. Redis and APCu have no
     * comparable per-item cap, and a memcached started with a raised `-I` has a
     * larger one, but no drop-in exposes its ceiling, so the tightest common
     * backend is assumed for all of them.
     *
     * Public because the guards outside the plugin -- CorpusQualityTest and
     * dev/bench.php -- must weigh a unit against the limit production
     * actually prunes at. Flagging only at the raw MAX_CACHED_UNIT_BYTES left a
     * kilobyte-wide band in which a unit passed every guard and was still
     * pruned on a live site.
     */
    public const CACHE_ITEM_OVERHEAD_BYTES = 1024;

    /**
     * Bytes a stored unit may inflate back to when it is read.
     *
     * inflate_unit() hands this to gzuncompress() as its maximum length rather
     * than inflating unbounded: deflate reaches ~1000:1, so a damaged row that
     * is still valid deflate would otherwise allocate hundreds of megabytes and
     * fatal the submission path -- the one failure ANTISPAMWIZARD_Detector::run()'s
     * fail-open catch cannot absorb.
     *
     * It is a bound on the *writer* as well, which is why it is a constant
     * rather than an expression inside the reader. The compressed size and the
     * inflated size are independent axes: ANTISPAMWIZARD_Tokenizer puts no cap on a
     * token's length, so a history of long, distinct, highly repetitive tokens
     * (one 65 536-character comment per token, the run compressing ~1000:1)
     * serializes far past this ceiling while deflating to well under
     * MAX_CACHED_UNIT_BYTES. Weighing the compressed value alone would accept
     * such a unit, whereupon every read refuses it, deletes the row and
     * rebuilds -- a cold rebuild plus a wasted multi-megabyte write on every
     * submission for good, the exact failure admission control exists to
     * prevent. storable_unit() therefore prunes against this as well as against
     * the item limit.
     *
     * 64x the item limit is far past anything this class writes: the
     * best-compressing shipped unit runs 5.73x and the worst case measured for
     * COMPRESSION_LEVEL 2.53x.
     */
    public const MAX_INFLATED_UNIT_BYTES = self::MAX_CACHED_UNIT_BYTES * 64;

    /**
     * Tokenizer used for similarity sample tokenisation and the combined
     * classifier -- the same instance build_checks() hands the checks.
     *
     * @var ANTISPAMWIZARD_Tokenizer
     */
    private ANTISPAMWIZARD_Tokenizer $tokenizer;

    /**
     * Excluded tokens from settings. Part of every unit's cache key, because
     * tokenisation now happens inside the cached unit rather than per request.
     *
     * @var array<int, string>
     */
    private array $excluded_tokens;

    /**
     * Corpus loader.
     *
     * @var ANTISPAMWIZARD_Training_Data
     */
    private ANTISPAMWIZARD_Training_Data $training_data;

    /**
     * Sample repository, or null when the table is unavailable.
     *
     * @var ANTISPAMWIZARD_Sample_Repository|null
     */
    private ?ANTISPAMWIZARD_Sample_Repository $repository;

    /**
     * Detected script per text, keyed by md5 of the text.
     *
     * @var array<string, string>
     */
    private array $script_memo = array();

    /**
     * Loaded per-script units, keyed by script group.
     *
     * @var array<string, array{classifier: ANTISPAMWIZARD_Naive_Bayes|null, samples: array<int, array<int, string>>}>
     */
    private array $script_units = array();

    /**
     * Loaded combined unit, or null when it has not been asked for.
     *
     * @var array{classifier: ANTISPAMWIZARD_Naive_Bayes, samples: array<int, array<int, string>>, has_script_samples: bool}|null
     */
    private ?array $combined_unit = null;

    /**
     * Memoised excluded-tokens fingerprint.
     *
     * @var string|null
     */
    private ?string $excluded_fingerprint = null;

    /**
     * Whether the last fingerprint computation had to fall back for its DB
     * component because the aggregate read failed.
     *
     * Set by script_fingerprint()/combined_fingerprint() on every call, so the
     * value a cache gate reads is the one the fingerprint it is gating on was
     * built with.
     *
     * @var bool
     */
    private bool $db_fingerprint_unavailable = false;

    /**
     * Constructor.
     *
     * @param ANTISPAMWIZARD_Tokenizer              $tokenizer       Shared tokenizer.
     * @param array<int, string>         $excluded_tokens Excluded tokens from settings.
     * @param ANTISPAMWIZARD_Training_Data          $training_data   Corpus loader.
     * @param ANTISPAMWIZARD_Sample_Repository|null $repository      Sample repository, or null.
     */
    public function __construct(
        ANTISPAMWIZARD_Tokenizer $tokenizer,
        array $excluded_tokens,
        ANTISPAMWIZARD_Training_Data $training_data,
        ?ANTISPAMWIZARD_Sample_Repository $repository
    ) {
        $this->tokenizer       = $tokenizer;
        $this->excluded_tokens = $excluded_tokens;
        $this->training_data   = $training_data;
        $this->repository      = $repository;
    }

    /**
     * Detect the script of a text, once per text per request.
     *
     * Both checks that consult the store ask for the same body, so memoising
     * here is what keeps detection to a single pass over the comment.
     *
     * @param string $text The text to classify.
     * @return string Script group name, or 'unknown'.
     */
    public function script_for( string $text ): string {
        $memo_key = md5( $text );

        if ( ! isset( $this->script_memo[ $memo_key ] ) ) {
            $this->script_memo[ $memo_key ] = $this->detect_script( $text );
        }

        return $this->script_memo[ $memo_key ];
    }

    /**
     * Get a script group's classifier, loading its unit on first ask.
     *
     * @param string $script Script group name.
     * @return ANTISPAMWIZARD_Naive_Bayes|null Null when the script has no training data at all.
     */
    public function script_classifier( string $script ): ?ANTISPAMWIZARD_Naive_Bayes {
        $unit = $this->script_unit( $script );

        return null === $unit ? null : $unit['classifier'];
    }

    /**
     * Get a script group's pre-tokenised spam samples.
     *
     * @param string $script Script group name.
     * @return array<int, array<int, string>> Token lists, empty when the script has none.
     */
    public function script_sample_tokens( string $script ): array {
        $unit = $this->script_unit( $script );

        return null === $unit ? array() : $unit['samples'];
    }

    /**
     * Get the combined classifier, loading the combined unit on first ask.
     *
     * @return ANTISPAMWIZARD_Naive_Bayes
     */
    public function combined_classifier(): ANTISPAMWIZARD_Naive_Bayes {
        return $this->combined_unit()['classifier'];
    }

    /**
     * Get the combined pre-tokenised spam samples.
     *
     * @return array<int, array<int, string>>
     */
    public function combined_sample_tokens(): array {
        return $this->combined_unit()['samples'];
    }

    /**
     * Whether any script group produced spam sample tokens.
     *
     * This is what decides the Similarity check's legacy tokenize() mode: it
     * reproduces the old `empty( $script_sample_tokens )` test, which was false
     * only when no script had any samples at all.
     *
     * @return bool
     */
    public function combined_has_script_samples(): bool {
        return $this->combined_unit()['has_script_samples'];
    }

    /**
     * Every unit key this class can ever write: ten scripts plus combined.
     *
     * The list is exhaustive and constant, which is the whole point of keying a
     * unit by name and carrying its fingerprint *inside* the value. Fingerprinted
     * keys made this list a moving target: a unit written under one fingerprint
     * was never overwritten when the fingerprint moved, only orphaned, so a
     * caller erasing personal data could purge the current generation and still
     * leave an older unit -- holding the erased comment's text as a token list --
     * readable in wp_options until its TTL ran out. A rebuild now overwrites the
     * row it read, so there is no older generation to find.
     *
     * Static, so an eraser can name the keys without building a store.
     *
     * @return array<int, string>
     */
    public static function unit_keys(): array {
        $keys = array();

        foreach ( array_keys( ANTISPAMWIZARD_Script_Detector::SCRIPT_GROUPS ) as $script ) {
            $keys[] = self::TRANSIENT_PREFIX . $script;
        }

        $keys[] = self::TRANSIENT_PREFIX . self::COMBINED_UNIT;

        return $keys;
    }

    /**
     * Delete cached units by key, reporting whether the erased text is gone.
     *
     * Static, and prefix-guarded, so a caller holding keys taken before a
     * delete can purge them without a live store and can never be talked into
     * deleting a transient this class does not own.
     *
     * The return value is load-bearing for ANTISPAMWIZARD_Privacy::erase(): a unit holds
     * the trained comment's text as a token list, so text this call could not
     * remove stays readable while the erasure request reports success. It
     * cannot be read off delete_transient(), which answers false both for a
     * transient that was not there -- the ordinary case, since an erasure
     * purges all eleven keys and a request has usually loaded one -- and for a
     * delete the option or object-cache backend refused. The key is therefore
     * read back -- through stored_unit(), not get_transient(), which is not
     * proof of deletion on its own -- and anything other than false means it
     * survived.
     *
     * A unit that survived is not left holding the erased text either: the
     * value is blanked, and a row the options API refuses twice is deleted by
     * statement -- see stop_storing_text(). What this reports is whether any
     * training text is *still stored*, not whether every key is gone: a key
     * carrying neither samples nor classifier state is no longer personal data,
     * and reporting it as retained would answer an erasure request with a
     * database-error message over a unit that holds nothing.
     *
     * There is no durable record of a purge that did not finish, and none is
     * needed: ANTISPAMWIZARD_Privacy::erase() runs this on page 1 of *every* erasure
     * request, so the retry its message asks for -- and any later erasure --
     * attempts the purge again whatever that page deleted. A marker would have
     * to be written by the very option store that has just refused a write, and
     * a purge nothing ever retries is bounded anyway, since every unit is
     * written with a one-day TTL.
     *
     * @param array<int, string> $keys Keys from unit_keys().
     * @return bool False when training text is still stored after the purge.
     */
    public static function delete_units( array $keys ): bool {
        $is_full_purge = ( array() === array_diff( self::unit_keys(), $keys ) );
        $text_retained = false;

        foreach ( $keys as $key ) {
            if ( ! is_string( $key ) || ! str_starts_with( $key, self::TRANSIENT_PREFIX ) ) {
                continue;
            }

            if ( delete_transient( $key ) ) {
                continue;
            }

            $stored = self::stored_unit( $key );
            // The key survived the delete. The erased comment's text inside it
            // does not have to survive with it: every remaining lever is tried,
            // and whether any of them worked is verified rather than assumed.
            if ( false !== $stored && ! self::stop_storing_text( $key, $stored ) ) {
                $text_retained = true;
            }
        }

        // Rows the key list cannot name: the monolithic `antispamwizard_classifiers_<md5>`
        // transient earlier versions wrote, and any unit row stranded in
        // wp_options by an object cache that was dropped in afterwards (from
        // then on WordPress reads and writes transients in the cache, and core's
        // own expired-transient cleanup returns early, so nothing ever collects
        // those rows). Both hold the erased comment's text as a token list and
        // neither is reachable through the transient API, so an erasure sweeps
        // the prefix as well. What cannot be swept is a legacy key inside an
        // external object cache -- its name is not computable and the cache
        // cannot be enumerated -- but that copy expires with its own 24-hour TTL
        // rather than sitting in wp_options for good.
        //
        // A refused sweep is the one retention with no lever behind it: those
        // rows are exactly the ones no key can name, so they cannot be blanked
        // or deleted individually either.
        if ( $is_full_purge && ! self::delete_stray_rows() ) {
            $text_retained = true;
        }

        if ( $text_retained ) {
            self::report_retained_text();
        }

        return ! $text_retained;
    }

    /**
     * Log that a cached unit still holds training text after every lever.
     *
     * The caller has already tried the transient API, a blanking write and a
     * direct row delete, so there is nothing left to attempt and no site signal
     * that would otherwise show the loss. Sanitized and without user input,
     * like every other diagnostic in the plugin.
     */
    private static function report_retained_text(): void {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational diagnostic: a personal-data purge left a unit holding the erased text and no lever could remove it, which nothing else on the site would ever surface. The line carries no user input.
        error_log( 'ANTISPAMWIZARD: model cache purge retained a unit still holding training text' );
    }

    /**
     * Delete every `_transient_antispamwizard_classifiers_%` option row, whatever its key.
     *
     * There is no core API for deleting options by pattern, and no way to reach
     * these rows through the transient API: an eraser can only name the keys it
     * can compute, and these are exactly the ones it cannot -- a fingerprinted
     * key from an earlier version, or a row left behind when the site moved to
     * an external object cache. Deleting the row is the whole of the cleanup:
     * without a persistent object cache the options cache is request-local, and
     * with one these rows are not read at all.
     *
     * Which rows count as unreachable depends on where transients live. Without
     * an external object cache they live in these very rows, so the eleven keys
     * this version owns are excluded by name: the key loop has already handled
     * each of them through the transient API and, where that was refused, through
     * its own verified blanking write and row delete, so a blind DELETE here
     * would only duplicate -- or silently paper over -- work whose result the
     * caller has already read back. With an external object cache no unit is
     * stored in wp_options at all, so *every* matching row is a leftover of the
     * era before that cache was installed -- rows core's own expired-transient
     * cleanup skips for the same reason -- and all of them go.
     *
     * @return bool False when the DELETE failed, which retains the rows.
     */
    private static function delete_stray_rows(): bool {
        global $wpdb;

        if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
            return true;
        }

        $arguments = array(
            $wpdb->esc_like( '_transient_' . self::TRANSIENT_PREFIX ) . '%',
            $wpdb->esc_like( '_transient_timeout_' . self::TRANSIENT_PREFIX ) . '%',
        );

        $owned_placeholders = array();
        if ( ! wp_using_ext_object_cache() ) {
            foreach ( self::unit_keys() as $key ) {
                $owned_placeholders[] = '%s';
                $arguments[]          = '_transient_' . $key;
                $owned_placeholders[] = '%s';
                $arguments[]          = '_transient_timeout_' . $key;
            }
        }

        $sql = "DELETE FROM `{$wpdb->options}` WHERE ( option_name LIKE %s OR option_name LIKE %s )";
        if ( ! empty( $owned_placeholders ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The interpolated fragment is a generated list of %s placeholders, never data; every value is passed to prepare() below.
            $sql .= ' AND option_name NOT IN ( ' . implode( ', ', $owned_placeholders ) . ' )';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Personal-data erasure cleanup: there is no core API for deleting options by LIKE pattern, and caching a query whose entire purpose is to erase the rows it matches would be meaningless. Every value goes through prepare(); only a generated placeholder list is interpolated, which is what UnescapedDBParameter sees when it traces $sql back to the .= above. The call must stay on this one line: a previous-line suppression covers exactly one line, and all four sniffs report here.
        $result = $wpdb->query( $wpdb->prepare( $sql, ...$arguments ) );

        return false !== $result;
    }

    /**
     * Stop a unit that survived its delete from storing the erased text.
     *
     * Two levers, tried in order and each verified rather than fired and
     * forgotten -- the caller's whole problem is that the obvious one has
     * already been refused, so assuming the next one landed would report an
     * erasure the site did not perform.
     *
     * 1. Overwrite the value with one carrying neither `samples` nor classifier
     *    state. A value with no `fingerprint` member is one this class rebuilds
     *    rather than serves, so the row is inert as well as empty.
     * 2. Delete the unit's wp_options rows outright. Without an external object
     *    cache a transient *is* those rows, and the DELETE runs against the
     *    database rather than through the options API that just refused twice,
     *    so it fails independently of the two writes above.
     *
     * A unit either lever emptied is reported as purged, not as retained: a key
     * carrying neither `samples` nor classifier state is no longer personal
     * data, and answering an erasure request with a database-error message over
     * a unit that holds nothing would ask the admin to retry for nothing. Only a
     * unit that still holds text after both levers is retention, which is what
     * the return value says.
     *
     * The blanking write goes in *uncompressed*, alone among this class's
     * writes: it is recognisably a unit and recognisably a text-free one to any
     * reader, including one on a host whose zlib went away since the unit was
     * written -- which is one of the states that brought the row here. Nothing
     * is lost by it, because the value carries no fingerprint and is rebuilt on
     * the next read rather than served.
     *
     * @param string $key    Unit transient key.
     * @param mixed  $stored The value the purge just read back.
     * @return bool Whether the unit has stopped holding training text.
     */
    private static function stop_storing_text( string $key, $stored ): bool {
        if ( ! self::holds_training_text( $stored ) ) {
            return true;
        }

        set_transient( $key, self::BLANKED_UNIT, MINUTE_IN_SECONDS );

        if ( ! self::holds_training_text( self::stored_unit( $key ) ) ) {
            return true;
        }

        self::delete_unit_rows( $key );

        return ! self::holds_training_text( self::stored_unit( $key ) );
    }

    /**
     * Delete one unit's option rows directly, bypassing the options API.
     *
     * The last lever for a unit whose delete and blanking write were both
     * refused: those go through delete_option()/update_option() and their
     * filters, while this is a statement the database either runs or does not.
     * Nothing is masked by it -- the row it names has already been read back as
     * surviving, and is read back again afterwards.
     *
     * With an external object cache the unit is not in wp_options at all, so
     * there is no row to take; the stray-row sweep covers the ones such a cache
     * stranded there earlier.
     *
     * @param string $key Unit transient key.
     */
    private static function delete_unit_rows( string $key ): void {
        global $wpdb;

        if ( wp_using_ext_object_cache() || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
            return;
        }

        // A LIKE with no wildcard left in it is an equality match: esc_like()
        // escapes the underscores of `_transient_`, which would otherwise each
        // match any single character.
        //
        // The query string is a literal passed straight to prepare(), and the
        // table name goes through the %i identifier placeholder rather than
        // string interpolation. Both are what keep
        // WordPress.DB.PreparedSQL.NotPrepared quiet without a suppression: it
        // reports on a *variable* handed to prepare(), i.e. on an argument line
        // inside a multi-line call, where a previous-line ignore above the query
        // cannot reach it.
        $prepared = $wpdb->prepare(
            'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
            $wpdb->options,
            $wpdb->esc_like( '_transient_' . $key ),
            $wpdb->esc_like( '_transient_timeout_' . $key )
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Personal-data erasure cleanup for a row the options API refused to delete; going through that API again is exactly what failed. Caching a query whose purpose is to erase the rows it matches would be meaningless. Every value goes through prepare(): $prepared is the return value of the bare prepare() call directly above. NotPrepared reports on the query argument here, and a previous-line suppression covers exactly one line, so this call must stay on one line -- the shape class-sample-repository.php uses.
        $wpdb->query( $prepared );

        // The rows are gone but the options cache still holds them, as it would
        // after any direct DELETE; core's delete_option() drops the same two
        // entries. Without this the read-back above -- and every get_transient()
        // for the rest of the request -- would still be served the erased text.
        wp_cache_delete( '_transient_' . $key, 'options' );
        wp_cache_delete( '_transient_timeout_' . $key, 'options' );
    }

    /**
     * Whether this host can compress and decompress a unit.
     *
     * Every PHP build WordPress supports ships zlib, so the fallback below is
     * for a host that has had it compiled out. The ANTISPAMWIZARD_TESTING-gated switch is
     * what lets the suite drive that host without one: the two forms a unit can
     * be stored in have to be pinned against both capabilities, and neither the
     * strict read rule nor the lenient one in holds_training_text() is
     * reachable otherwise.
     *
     * @return bool
     */
    private static function zlib_available(): bool {
        if ( defined( 'ANTISPAMWIZARD_TESTING' ) && ! empty( $GLOBALS['antispamwizard_zlib_unavailable'] ) ) {
            return false;
        }

        return function_exists( 'gzcompress' ) && function_exists( 'gzuncompress' );
    }

    /**
     * Whether a unit is stored compressed on this host.
     *
     * The envelope is confined to the external object cache, because that is
     * both the only place the size limit exists and the only place a deflate
     * stream can be stored at all. Without an external cache a transient *is*
     * the `_transient_<name>` option row, a `longtext` column on a utf8mb4
     * connection: wpdb::process_fields() puts every value through
     * strip_invalid_text(), a deflate stream is not valid UTF-8, and the write
     * is refused outright with "Could not perform query because it contains
     * invalid data." An unconditional envelope therefore cached *nothing* on
     * every site without a persistent object cache -- the default -- and put
     * every submission on the cold path for good, which is the exact failure
     * the envelope exists to prevent, made universal. That row has no per-item
     * limit to respect either (a longtext holds 4 GB against a combined unit's
     * 765 095 packed bytes), so the plain array costs it nothing.
     *
     * @return bool
     */
    private static function compression_applies(): bool {
        return self::zlib_available() && wp_using_ext_object_cache();
    }

    /**
     * The form a unit is stored in on this host, or null when it must not be stored.
     *
     * A gzcompress() that comes back false -- a zlib that is present but would
     * not take this payload -- writes nothing at all. Falling back to the plain
     * array is what must not happen here, and it is worse than caching nothing
     * rather than better: unwrap_for_read() is strict in both directions, so on
     * a host where compression_applies() is true a plain value under a unit key
     * is refused, the row is deleted and the unit is rebuilt -- and the rebuild
     * runs gzcompress() again, fails again and writes the same unservable value
     * again. That is a cold rebuild on every submission *plus* a wasted write
     * and delete each time, where writing nothing costs the rebuild alone. The
     * caller therefore skips its set_transient() and the next request retries,
     * exactly as the mid-load-race and failed-sample-read rules do.
     *
     * The serialized length of the payload is handed back through $inflated on
     * the compressed path -- null on the plain one, where nothing is ever
     * inflated -- because that is the size the *reader* is bounded by, and it
     * is free here: the serialize() it measures has already been paid for.
     *
     * @param array    $unit     Exported unit.
     * @param int|null $inflated Set to the bytes this value inflates back to,
     *                           or null when it is stored plain.
     * @return array|null The value to store, or null when this host cannot store one.
     */
    private static function wrap_unit( array $unit, ?int &$inflated = null ): ?array {
        $inflated = null;

        if ( ! self::compression_applies() ) {
            return $unit;
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Packing a local model unit into the compressed envelope this class reads back itself; unserialized only through inflate_unit(), with allowed_classes false.
        $payload    = serialize( $unit );
        $inflated   = strlen( $payload );
        $compressed = self::compress_payload( $payload );

        if ( ! is_string( $compressed ) || '' === $compressed ) {
            return null;
        }

        return array( self::COMPRESSED_MEMBER => $compressed );
    }

    /**
     * Deflate a serialized unit, or false when this host's zlib refuses it.
     *
     * Split out for the same reason zlib_available() carries a switch: a zlib
     * that is present but will not take a particular payload is not reachable
     * from a test otherwise, and the rule it triggers -- write nothing rather
     * than a value unwrap_for_read() would refuse -- is the difference between
     * one cold rebuild and a cold rebuild on every submission for good.
     *
     * @param string $payload Serialized unit.
     * @return string|false Deflate stream, or false.
     */
    private static function compress_payload( string $payload ) {
        if ( defined( 'ANTISPAMWIZARD_TESTING' ) && ! empty( $GLOBALS['antispamwizard_gzcompress_fails'] ) ) {
            return false;
        }

        return gzcompress( $payload, self::COMPRESSION_LEVEL );
    }

    /**
     * Whether this host has a per-item size limit to keep a unit under.
     *
     * The 1 048 576-byte limit is the external object cache's, and only its.
     * Without one a transient *is* the `_transient_<name>` option row, a
     * longtext that holds 4 GB, so weighing a unit there -- and pruning a
     * model to fit a bound that does not exist -- would cost detection quality
     * for nothing. Same predicate as compression_applies() minus the zlib
     * half: a host with a cache but no zlib stores the plain packed array and
     * still has the limit to respect.
     *
     * @return bool
     */
    private static function size_limit_applies(): bool {
        return (bool) wp_using_ext_object_cache();
    }

    /**
     * The per-item limit a stored unit is weighed against.
     *
     * MAX_CACHED_UNIT_BYTES less CACHE_ITEM_OVERHEAD_BYTES: stored_size() can
     * only weigh the value, where memcached weighs the whole item.
     *
     * Behind the ANTISPAMWIZARD_TESTING gate every other capability switch here uses, so
     * a test can drive the prune-and-reweigh loop over a small fixture instead
     * of having to build a genuinely oversized unit -- which takes megabytes of
     * novel vocabulary and seconds of wall clock, and would make the mechanism
     * pinned only by the suite's slowest test. The switch is returned verbatim,
     * without the allowance, so a test states the bound it means.
     *
     * @return int
     */
    private static function size_limit(): int {
        if ( defined( 'ANTISPAMWIZARD_TESTING' ) && isset( $GLOBALS['antispamwizard_max_cached_unit_bytes'] ) ) {
            return (int) $GLOBALS['antispamwizard_max_cached_unit_bytes'];
        }

        return self::MAX_CACHED_UNIT_BYTES - self::CACHE_ITEM_OVERHEAD_BYTES;
    }

    /**
     * The bytes a stored value costs the cache.
     *
     * What memcached weighs is the serialized item, which is what an object
     * cache backend hands it, so that is what is measured here rather than the
     * length of the compressed payload alone.
     *
     * @param array $stored Value about to be written.
     * @return int
     */
    private static function stored_size( array $stored ): int {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Weighing the value the object cache will serialize itself; the string is measured and thrown away.
        return strlen( serialize( $stored ) );
    }

    /**
     * The value to cache for a unit, pruned until the cache can hold it.
     *
     * This is the third and last of the measures that keep a unit inside
     * MAX_CACHED_UNIT_BYTES, and the only one that bounds the classifier: the
     * packed representation and the compressed envelope shrink whatever they
     * are given, and LEARNED_SIMILARITY_BUDGET bounds the similarity corpus,
     * but the dictionary's *bytes* are a function of what the site has learned
     * (ANTISPAMWIZARD_Naive_Bayes::MAX_DICTIONARY_SIZE caps the token count, not the token
     * length) and can reach 211 % of the limit on their own.
     *
     * It is admission control, not a refusal. Declining to write a unit that
     * is over the limit would be exactly what memcached already does silently,
     * i.e. a cold rebuild on every submission in that writing system for good.
     * Instead the value is weighed, and while it is too big the least frequent
     * half of each category's vocabulary is dropped and it is weighed again --
     * so what is written is the largest model this cache can actually hold.
     * Halving converges in at most ~16 rounds from a full dictionary, each
     * cheaper than the last, and only on a host that reached the limit at all.
     * A unit that will not fit even with an empty dictionary caches nothing,
     * the same answer a refused gzcompress() gets.
     *
     * Measuring is what makes it exact, and the alternatives were measured
     * before it was chosen. A **token-length cap** would have to sit at or
     * under 12 bytes -- 4 CJK characters, 6 Cyrillic ones -- to bound the same
     * dictionary, where only 52 % of the shipped corpus's own tokens survive.
     * A **compression-independent packed-byte budget** has to assume a 1.0x
     * ratio, so it would prune ordinary sites whose units compress 2.5-3.5x and
     * are nowhere near the limit; per unit rather than global it preserves the
     * shipped models, but it still spends real vocabulary on hosts that never
     * needed to. Weighing the value that is actually about to be written costs
     * one serialize() on the cold path and prunes only what genuinely does not
     * fit.
     *
     * Two sizes are weighed, not one, because the cache's limit and the
     * reader's are independent: the stored value must fit the item limit, and
     * -- on a compressing host -- it must also inflate back inside
     * MAX_INFLATED_UNIT_BYTES, the ceiling inflate_unit() hands gzuncompress().
     * A dictionary of long, distinct, highly repetitive tokens passes the first
     * and fails the second, and a unit written that way is refused, deleted and
     * rebuilt on every read for good. The same halving pays for both.
     *
     * The pruned model is the one this request classifies with as well, not
     * only the one that goes into the cache: the classifier is handed back
     * through set_state() before this returns. A cache holding a model the
     * request that built it did not use would make a verdict depend on whether
     * the unit happened to be warm, which is a far worse bargain than the
     * vocabulary the prune costs.
     *
     * @param ANTISPAMWIZARD_Naive_Bayes|null $classifier The unit's live classifier, pruned in place when it must be.
     * @param array                $export     The exported, packed unit.
     * @return array|null The value to store, or null when this host cannot store one.
     */
    private function storable_unit( ?ANTISPAMWIZARD_Naive_Bayes $classifier, array $export ): ?array {
        $stored = self::wrap_unit( $export, $inflated );

        if ( null === $stored || ! self::size_limit_applies() ) {
            return $stored;
        }

        $limit = self::size_limit();
        // Carried through the loop rather than recomputed by its condition:
        // serialize() over a multi-megabyte packed unit is the expensive part
        // of this method, and weighing the same unchanged value twice would
        // pay for it twice on every cold build.
        $size = self::stored_size( $stored );

        if ( $size < $limit && ! self::exceeds_inflate_ceiling( $inflated ) ) {
            return $stored;
        }

        // Nothing left to spend: a unit with no classifier is its similarity
        // corpus alone, which LEARNED_SIMILARITY_BUDGET already bounds -- on
        // both axes, since a corpus that small cannot reach either bound.
        if ( null === $classifier ) {
            return null;
        }

        $state = $classifier->get_state();

        while ( $size >= $limit || self::exceeds_inflate_ceiling( $inflated ) ) {
            $pruned = self::halve_dictionary( $state );

            if ( null === $pruned ) {
                return null;
            }

            $state                = $pruned;
            $export['classifier'] = $this->pack_classifier_state( $state );
            $stored               = self::wrap_unit( $export, $inflated );

            if ( null === $stored ) {
                return null;
            }

            $size = self::stored_size( $stored );
        }

        $classifier->set_state( $state );

        return $stored;
    }

    /**
     * Whether a value would inflate past the ceiling its reader allows.
     *
     * The second axis storable_unit() prunes against, and an independent one:
     * a compressed value is weighed against the cache's item limit, while
     * inflate_unit() bounds what it will expand back to. A history of long,
     * distinct, highly repetitive tokens clears the first and fails the
     * second, and such a unit is written once and then refused, deleted and
     * rebuilt on every read for good. Null is the plain path, where nothing is
     * inflated and the ceiling does not apply.
     *
     * The test is `>=` rather than `>` so the writer stays a byte clear of
     * gzuncompress()'s own boundary handling instead of depending on it.
     *
     * @param int|null $inflated Bytes the stored value inflates back to.
     * @return bool
     */
    private static function exceeds_inflate_ceiling( ?int $inflated ): bool {
        return null !== $inflated && $inflated >= self::inflate_ceiling();
    }

    /**
     * The ceiling a stored payload may inflate back to.
     *
     * One value for both sides, because the whole point of the writer's bound
     * is that it is the reader's: a writer that measured against anything else
     * would cache values inflate_unit() then refuses on every read.
     *
     * Behind the ANTISPAMWIZARD_TESTING gate the other capability switches use, for the
     * reason size_limit() carries one -- reaching MAX_INFLATED_UNIT_BYTES
     * honestly takes 64 MB of novel vocabulary, and a rule pinned only by the
     * suite's slowest test is one that stops being run.
     *
     * @return int
     */
    private static function inflate_ceiling(): int {
        if ( defined( 'ANTISPAMWIZARD_TESTING' ) && isset( $GLOBALS['antispamwizard_max_inflated_unit_bytes'] ) ) {
            return (int) $GLOBALS['antispamwizard_max_inflated_unit_bytes'];
        }

        return self::MAX_INFLATED_UNIT_BYTES;
    }

    /**
     * Drop the least frequent half of every category's vocabulary.
     *
     * Frequency is the right thing to spend: a token seen once contributes one
     * Laplace-smoothed likelihood to one training document, where the head of
     * the distribution is what every classification actually turns on. Halving
     * rather than trimming to a computed size is what keeps the loop honest --
     * the relation between packed bytes and the deflated item is not one this
     * class can predict, so it reweighs instead of aiming.
     *
     * The dropped occurrences leave `token_totals` with them, which is exactly
     * what ANTISPAMWIZARD_Naive_Bayes::learn() does with a token it refuses to admit once
     * MAX_DICTIONARY_SIZE is reached: it counts neither the token nor its
     * occurrences. The pruned state therefore reads as a model that never saw
     * those tokens, rather than one whose smoothing denominators remember them.
     *
     * The walk bottoms out at an *empty* vocabulary, which is the terminal
     * state storable_unit() is written against -- a unit that will not fit even
     * with no dictionary at all caches nothing. That is only a valid state
     * because ANTISPAMWIZARD_Naive_Bayes::classify() floors its Laplace denominator at 1:
     * an emptied category would otherwise leave it dividing by
     * `token_totals + vocabulary_size`, i.e. by 0, a DivisionByZeroError that
     * ANTISPAMWIZARD_Detector::run()'s fail-open catch swallows while silently disabling
     * the Bayesian check for the unit's whole day and appending a
     * plugin-attributable line to the debug.log dev/smoke.sh scans on every
     * submission. Stopping a token short of empty instead would leave a unit
     * that fits only without a dictionary uncached -- which is a band a single
     * token wide on ordinary text, but a whole comment wide on the long-token
     * history the byte bounds exist for. Once every category is already empty
     * there is nothing left to drop and this returns null.
     *
     * @param array $state Classifier state from ANTISPAMWIZARD_Naive_Bayes::get_state().
     * @return array|null The pruned state, or null when there was nothing left to drop.
     */
    private static function halve_dictionary( array $state ): ?array {
        if ( ! is_array( $state['token_counts'] ?? null ) ) {
            return null;
        }

        $dropped = false;

        foreach ( $state['token_counts'] as $category => $counts ) {
            $size = count( $counts );

            // intdiv( 1, 2 ) is 0, so a single-token category is emptied
            // outright, which is the intended floor. An already-empty one has
            // nothing left to drop and must not report that it did.
            if ( $size < 1 ) {
                continue;
            }

            arsort( $counts );
            $keep = array_slice( $counts, 0, intdiv( $size, 2 ), true );

            $state['token_counts'][ $category ] = $keep;
            $state['token_totals'][ $category ] = max(
                0,
                (int) ( $state['token_totals'][ $category ] ?? 0 ) - ( array_sum( $counts ) - array_sum( $keep ) )
            );
            $dropped                            = true;
        }

        return $dropped ? $state : null;
    }

    /**
     * Decode a stored value, whichever of the two forms it is in.
     *
     * The shared decoder both the serving path and holds_training_text() read
     * through, so the two can never disagree about what a stored value holds.
     * A value with no envelope member is already the unit (or whatever else was
     * written under the key) and is returned as it is; an envelope that will not
     * inflate -- a host with no zlib, a truncated payload, a payload that is not
     * a serialized array -- is null, and what null means is the caller's to
     * decide: the serving path rebuilds, holds_training_text() assumes text.
     *
     * @param array $value Stored value.
     * @return array|null The inner unit, or null when an envelope will not inflate.
     */
    private static function inflate_unit( array $value ): ?array {
        if ( ! array_key_exists( self::COMPRESSED_MEMBER, $value ) ) {
            return $value;
        }

        $payload = $value[ self::COMPRESSED_MEMBER ];

        if ( ! self::zlib_available() || ! is_string( $payload ) || '' === $payload ) {
            return null;
        }

        // Inflated under a ceiling rather than unbounded: deflate reaches
        // ~1000:1, so a damaged row that is still valid deflate would otherwise
        // allocate hundreds of megabytes and fatal the submission path -- the
        // one failure mode ANTISPAMWIZARD_Detector::run()'s fail-open catch cannot absorb,
        // and the opposite of this class's rebuild-rather-than-break rule. The
        // ceiling is MAX_INFLATED_UNIT_BYTES, which storable_unit() prunes
        // against as well, so no unit this class writes can reach it; past it
        // gzuncompress() returns false, which both callers already read as
        // "will not inflate".
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A truncated or foreign payload must rebuild the unit, not append a plugin-attributable warning to the debug.log dev/smoke.sh scans on every submission that reads it.
        $serialized = @gzuncompress( $payload, self::inflate_ceiling() );

        if ( ! is_string( $serialized ) || '' === $serialized ) {
            return null;
        }

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Same rule for a payload that inflated but is not a serialized unit; the value is this class's own envelope and allowed_classes false keeps the result to plain data.
        $unit = @unserialize( $serialized, array( 'allowed_classes' => false ) );

        return is_array( $unit ) ? $unit : null;
    }

    /**
     * Decode a stored value for *serving*, strictly.
     *
     * The stored form must match what this host writes: a compressed value
     * where this host would not compress -- a unit written before zlib was
     * compiled out, or one an external object cache still holds while
     * wp_using_ext_object_cache() has stopped reporting it -- and a plain array
     * where it would, which is the form the previous release wrote and the form
     * an object cache installed after the fact strands, are each not something
     * this version writes under this key. Either way the
     * caller deletes the row and rebuilds, which costs one cold load and leaves
     * the unit in the form this host can serve from then on -- and because
     * wrap_unit() returns null rather than a plain array when gzcompress()
     * refuses the payload, a host that cannot compress never writes a value this
     * rule would then refuse on every read. Being strict here
     * is what keeps everything downstream -- fingerprint_matches(),
     * restore_script_unit(), restore_combined_unit() -- seeing exactly the array
     * shape it saw before there was an envelope.
     *
     * @param mixed $value Stored value.
     * @return array|null The unit, or null when it must not be served.
     */
    private static function unwrap_for_read( $value ): ?array {
        if ( ! is_array( $value ) ) {
            return null;
        }

        if ( array_key_exists( self::COMPRESSED_MEMBER, $value ) !== self::compression_applies() ) {
            return null;
        }

        return self::inflate_unit( $value );
    }

    /**
     * Read a unit's stored value, seeing rows the transient API reports as a miss.
     *
     * `false === get_transient( $key )` is not proof that the row is gone. For
     * an expired database-backed transient WordPress deletes both option rows
     * on read and returns false whether or not those deletes landed, so a
     * refused cleanup of an expired unit reads back as purged while the row --
     * and the erased comment's text in it -- stays in wp_options. The option row
     * is therefore consulted directly when the transient API reports a miss.
     * With an external object cache there is no such row and get_transient() is
     * the whole truth.
     *
     * @param string $key Unit transient key.
     * @return mixed Stored value, or false when the unit really is not there.
     */
    private static function stored_unit( string $key ) {
        $value = get_transient( $key );

        if ( false !== $value ) {
            return $value;
        }

        if ( wp_using_ext_object_cache() ) {
            return false;
        }

        return get_option( '_transient_' . $key, false );
    }

    /**
     * Whether a stored value still carries text trained from a comment.
     *
     * Both members are personal data: `samples` is the pre-tokenised comment
     * text itself, and the classifier state carries its word counts.
     *
     * Reading those two members is only sound for a value this class wrote, so
     * anything else non-empty under this key is treated as holding text rather
     * than as holding none -- whether or not it is an array. A string or an
     * object here is a truncated or otherwise damaged serialization, which
     * maybe_unserialize() hands back as the raw string it is, and that string is
     * the unit's token lists. An array carrying none of the members this class
     * writes is the same thing one level in: "it has no `samples` member,
     * therefore it holds no text" is then an inference about a shape nothing
     * here defines, and `array( 'payload' => array( 'buy', 'pills' ) )` would
     * pass it while holding exactly the tokens the erasure is meant to remove.
     * The conservative reading costs a blanking write on a row that may have
     * held nothing, while the permissive one would report an erasure the site
     * did not perform -- and, because the key is one this version owns, exclude
     * the row from the stray-row sweep as well -- leaving the value in place for
     * the rest of the unit's day. An empty value -- `array()`, `''`, `0` --
     * carries nothing either way.
     *
     * Recognition therefore asks for the whole shape every write here produces,
     * both members of UNIT_TEXT_MEMBERS, not merely some member this class knows
     * the name of: with one known member enough,
     * `array( 'fingerprint' => 'abc', 'payload' => array( 'buy', 'pills' ) )`
     * would be read as a text-free unit and passed over for exactly the reason
     * above, which is the permissive reading again one member further in.
     *
     * A value that *is* recognisable as a unit is read by those two members
     * alone, extra members and all: `pre_set_transient_*`/`option_*` filters are
     * ordinary WordPress, and a member some other plugin added is not grounds to
     * flush a unit on every erasure -- nor to answer one with a database-error
     * message, since a blanked value carries that member back and would then
     * read as retention no lever could clear.
     *
     * A compressed unit is inflated first and then judged by exactly those
     * rules, and the rule for an envelope that will *not* inflate is the
     * lenient one this method takes everywhere else: it holds text. That is the
     * opposite of unwrap_for_read()'s strict rule, and deliberately so -- the
     * two answer different questions. Serving asks "can this be used?", where
     * anything doubtful is cheaply rebuilt; an erasure asks "might this still
     * hold the subject's comment?", where the same doubt is the whole reason to
     * blank the row. A payload this host cannot inflate -- zlib compiled out
     * after the unit was written, a truncated row -- is still the erased
     * comment's token lists, and reading it as text-free would close the
     * erasure over them.
     *
     * @param mixed $value Stored value from stored_unit().
     * @return bool
     */
    private static function holds_training_text( $value ): bool {
        if ( ! is_array( $value ) ) {
            return ! empty( $value );
        }

        if ( empty( $value ) ) {
            return false;
        }

        $value = self::inflate_unit( $value );

        if ( null === $value ) {
            return true;
        }

        foreach ( self::UNIT_TEXT_MEMBERS as $member ) {
            if ( ! array_key_exists( $member, $value ) ) {
                return true;
            }
        }

        return ! empty( $value['samples'] ) || ! empty( $value['classifier'] );
    }

    /**
     * Drop a unit this request wrote and then found superseded.
     *
     * delete_transient()'s result cannot be ignored here: the erasure that moved
     * the fingerprint has already purged this key and closed its request, so a
     * delete the backend refuses leaves the pre-erasure tokens this request just
     * wrote cached for the rest of the unit's day with nothing left to remove
     * them. Deleting is not the only way to stop storing them, though: the same
     * ladder a refused purge climbs -- blank the value, then take the option
     * rows by statement -- applies here, and its first rung is all but certain
     * to land, since this request's own set_transient() has just succeeded and
     * only the delete was refused.
     *
     * A unit that survives even that is logged, like a purge that could not
     * remove one: there is nothing left to attempt here, and the next erasure
     * request purges every key again in any case.
     *
     * @param string $key Unit transient key.
     */
    private static function drop_superseded_unit( string $key ): void {
        delete_transient( $key );

        $stored = self::stored_unit( $key );

        if ( false === $stored || self::stop_storing_text( $key, $stored ) ) {
            return;
        }

        self::report_retained_text();
    }

    /**
     * Detect a text's script. Seam for tests, which count the calls.
     *
     * @param string $text The text to classify.
     * @return string
     */
    protected function detect_script( string $text ): string {
        return ANTISPAMWIZARD_Script_Detector::detect( $text );
    }

    /**
     * Arm the repository's sample-read failure flag for a unit load.
     */
    private function clear_sample_read_failure(): void {
        if ( null !== $this->repository ) {
            $this->repository->clear_sample_read_failure();
        }
    }

    /**
     * Whether a sample read failed while this unit's samples were being loaded.
     *
     * A failed SELECT is indistinguishable from an empty one by return value, so
     * a unit built through one carries the corpus files alone while its
     * fingerprint -- computed from an aggregate query that did succeed -- asserts
     * the sample rows are in it. Caching that would drop every user-added sample
     * out of the Bayesian and Similarity models for the unit's whole day, with no
     * signal anywhere; the unit is used for this request and left uncached, the
     * same treatment the mid-load fingerprint race gets.
     *
     * @return bool
     */
    private function sample_read_failed(): bool {
        return null !== $this->repository && $this->repository->sample_read_failed();
    }

    /**
     * Whether the unit just built may be cached.
     *
     * A failed sample read only argues against caching when the unit's DB
     * fingerprint component was itself read successfully: that component is
     * what asserts which rows are in the unit, so a unit the rows never
     * reached, cached under it, serves a sample-free model for the whole day.
     *
     * When the aggregate read failed as well the component falls back to the
     * global revision and asserts nothing about the rows, and withholding the
     * write is the one thing that must not happen: that is what a samples table
     * which was never created looks like -- the refused-CREATE case the
     * schema-version retry exists for -- and it fails on every request, so the
     * site would pay a full cold rebuild on every submission for good, where
     * the monolithic transient this store replaced still cached. Caching is
     * safe because the fallback component is a *different* string from the one
     * a successful read produces, so the first request whose read succeeds
     * computes a fingerprint this unit cannot match and rebuilds it.
     *
     * @return bool
     */
    private function unit_is_cacheable(): bool {
        return ! $this->sample_read_failed() || $this->db_fingerprint_unavailable;
    }

    /**
     * Load a per-script unit, from cache when possible.
     *
     * @param string $script Script group name.
     * @return array{classifier: ANTISPAMWIZARD_Naive_Bayes|null, samples: array<int, array<int, string>>}|null
     *         Null when $script is not a script group ('unknown' has no unit).
     */
    private function script_unit( string $script ): ?array {
        if ( ! isset( ANTISPAMWIZARD_Script_Detector::SCRIPT_GROUPS[ $script ] ) ) {
            return null;
        }

        if ( isset( $this->script_units[ $script ] ) ) {
            return $this->script_units[ $script ];
        }

        $key         = self::TRANSIENT_PREFIX . $script;
        $fingerprint = $this->script_fingerprint( $script );
        $stored      = get_transient( $key );

        if ( false !== $stored ) {
            $cached   = self::unwrap_for_read( $stored );
            $restored = ( null !== $cached && $this->fingerprint_matches( $cached, $fingerprint ) )
                ? $this->restore_script_unit( $cached, $script )
                : null;
            if ( null !== $restored ) {
                $this->script_units[ $script ] = $restored;
                return $restored;
            }
            // Stale, corrupted, incomplete, or stored in a form this host
            // cannot serve from: drop it now rather than leave it standing if
            // the race below refuses the write, so a unit built from samples
            // that have since been erased cannot outlive them.
            delete_transient( $key );
        }

        $this->clear_sample_read_failure();

        // The spam half is loaded split: the classifier trains on all of it, in
        // the order it always has, while the similarity corpus takes the
        // shipped lists whole and only as much of the learned tail as
        // LEARNED_SIMILARITY_BUDGET pays for.
        $spam_file    = $this->training_data->load_script_file_samples( $script, 'spam' );
        $spam_learned = $this->training_data->load_learned_script_samples( $script, 'spam' );
        $ham          = $this->training_data->load_script_samples( $script, 'ham' );

        // Recompute the fingerprint after loading. A training write that landed
        // while we were reading leaves the samples in hand stale relative to it,
        // so the rebuild is used but never cached -- the one remaining
        // never-cache rule.
        clearstatcache( true );
        $post_load_fingerprint = $this->script_fingerprint( $script );

        $unit = $this->build_script_unit( $script, $spam_file, $spam_learned, $ham );

        // storable_unit() prunes the unit's vocabulary until the cache can
        // hold it, so a null here is one of the two values that must not be
        // written at all: a gzcompress() this host refused (the only value it
        // could write instead is one its own reader would refuse on every
        // read), or a unit that will not fit even with an empty dictionary.
        // Either way it writes nothing and the next request retries.
        $stored = $post_load_fingerprint === $fingerprint && $this->unit_is_cacheable()
            ? $this->storable_unit( $unit['classifier'], $this->export_script_unit( $unit, $fingerprint ) )
            : null;

        if ( null !== $stored ) {
            set_transient( $key, $stored, DAY_IN_SECONDS );

            // The pre-write check above closes the mid-load race, but not the
            // one that matters most: ANTISPAMWIZARD_Privacy::erase() deletes a subject's
            // sample rows and *then* purges every unit key, so an erasure
            // landing in the window between that check and this write is
            // followed by the already-loaded, pre-erasure tokens going straight
            // back into the row the eraser had just emptied -- personal data
            // restored after the request reported success, readable for the rest
            // of the unit's day, with nothing left to purge it.
            //
            // Re-reading the fingerprint after the write closes it in both
            // orders. An erasure that has already committed has moved the
            // fingerprint, so the payload just written goes here; one that
            // commits later purges this row itself. The cost is one extra
            // fingerprint read, on the cold path only. The drop is verified
            // rather than fired and forgotten -- the erasure is over, so a
            // refused delete would leave this row the only copy of the erased
            // text with nothing left to remove it.
            clearstatcache( true );
            if ( $this->script_fingerprint( $script ) !== $fingerprint ) {
                self::drop_superseded_unit( $key );
            }
        }

        $this->script_units[ $script ] = $unit;

        return $unit;
    }

    /**
     * Load the combined unit, from cache when possible.
     *
     * @return array{classifier: ANTISPAMWIZARD_Naive_Bayes, samples: array<int, array<int, string>>, has_script_samples: bool}
     */
    private function combined_unit(): array {
        if ( null !== $this->combined_unit ) {
            return $this->combined_unit;
        }

        $key         = self::TRANSIENT_PREFIX . self::COMBINED_UNIT;
        $fingerprint = $this->combined_fingerprint();
        $stored      = get_transient( $key );

        if ( false !== $stored ) {
            $cached   = self::unwrap_for_read( $stored );
            $restored = ( null !== $cached && $this->fingerprint_matches( $cached, $fingerprint ) )
                ? $this->restore_combined_unit( $cached )
                : null;
            if ( null !== $restored ) {
                $this->combined_unit = $restored;
                return $restored;
            }
            delete_transient( $key );
        }

        $this->clear_sample_read_failure();

        $legacy_spam = $this->training_data->load_samples( ANTISPAMWIZARD_PATH . 'data/spam-samples.txt' );
        $legacy_ham  = $this->training_data->load_samples( ANTISPAMWIZARD_PATH . 'data/ham-samples.txt' );

        $script_spam = array();
        $script_ham  = array();
        foreach ( array_keys( ANTISPAMWIZARD_Script_Detector::SCRIPT_GROUPS ) as $script ) {
            $script_spam[ $script ] = $this->training_data->load_script_samples( $script, 'spam' );
            $script_ham[ $script ]  = $this->training_data->load_script_samples( $script, 'ham' );
        }

        clearstatcache( true );
        $post_load_fingerprint = $this->combined_fingerprint();

        // build_combined_unit() reads the unknown-script rows itself, so the flag
        // is only final once it has returned.
        $unit = $this->build_combined_unit( $legacy_spam, $legacy_ham, $script_spam, $script_ham );

        // Same rule as script_unit(): the unit is pruned to what the cache can
        // hold, and a refused gzcompress() -- or a unit that will not fit at
        // all -- writes nothing rather than a value this host's own reader
        // would refuse.
        $stored = $post_load_fingerprint === $fingerprint && $this->unit_is_cacheable()
            ? $this->storable_unit( $unit['classifier'], $this->export_combined_unit( $unit, $fingerprint ) )
            : null;

        if ( null !== $stored ) {
            set_transient( $key, $stored, DAY_IN_SECONDS );

            // The same post-write supersession check as script_unit(), and it
            // matters more here: the combined unit carries every script's
            // samples, so a stale one restores the erased text whatever script
            // it was written in.
            clearstatcache( true );
            if ( $this->combined_fingerprint() !== $fingerprint ) {
                self::drop_superseded_unit( $key );
            }
        }

        $this->combined_unit = $unit;

        return $unit;
    }

    /**
     * Tokenise as much of a learned sample list as the byte budget pays for.
     *
     * The walk runs from the **last** element -- ANTISPAMWIZARD_Sample_Repository::find_all()
     * orders by ascending id, so that is the newest sample -- downwards, and
     * **stops at the first sample whose packed length would take the running
     * total past LEARNED_SIMILARITY_BUDGET**, admitting nothing older. It does
     * not skip that sample and carry on looking for a smaller one further back:
     * the corpus is meant to be the site's recent spam, and a corpus that
     * silently reached past a large sample to a small old one would be neither
     * bounded by recency nor easy to reason about. A sample that tokenises to
     * nothing costs no budget and is passed over without stopping the walk,
     * exactly as build_script_unit() has always dropped it.
     *
     * A single sample is therefore admitted **whole or not at all**. One can
     * never be over the budget by construction on realistic text -- a comment is
     * bounded by ANTISPAMWIZARD_Detector::MAX_COMMENT_LENGTH and a tokenisation by
     * ANTISPAMWIZARD_Tokenizer's 10 000-token cap, ~110 000 packed bytes for real text --
     * but a pathological one that is over it stops the walk and leaves the
     * corpus at whatever it had admitted. That state self-heals: the next sample the site
     * learns is newer still, so the walk reaches it first and the oversized one
     * moves further out of budget rather than blocking a growing tail forever.
     *
     * This method's deliberate verdict change: the Similarity check stops
     * matching a body against learned spam older than the budget's reach for
     * that script. The Bayesian check is untouched *by the budget* -- the
     * classifier trains on every learned sample, capped in vocabulary by
     * ANTISPAMWIZARD_Naive_Bayes::MAX_DICTIONARY_SIZE and, on a cache-backed host whose
     * unit reaches the item limit, by storable_unit()'s prune -- and the
     * shipped corpus is never subject to the budget.
     *
     * What a sample costs is its packed length plus
     * LEARNED_SAMPLE_OVERHEAD_BYTES for the array element that carries it, so
     * the admitted corpus is bounded in the bytes it actually serializes to
     * rather than only in the bytes its tokens hold.
     *
     * @param array<int, string> $learned Learned samples, ascending id.
     * @param string|null        $script  Script to tokenise for, or null to
     *                                    detect per sample (the combined unit's
     *                                    rule, since its rows are 'unknown').
     * @return array<int, array<int, string>> Admitted token lists, ascending id.
     */
    private function admit_within_budget( array $learned, ?string $script ): array {
        $admitted = array();
        $spent    = 0;

        for ( $i = count( $learned ) - 1; $i >= 0; $i-- ) {
            $sample = $learned[ $i ];
            $tokens = $this->tokenizer->tokenize_for_script(
                $sample,
                null === $script ? ANTISPAMWIZARD_Script_Detector::detect( $sample ) : $script
            );

            if ( empty( $tokens ) ) {
                continue;
            }

            // The array element the list is stored in costs its index and its
            // string header as well as its bytes; see the constant.
            $cost = strlen( implode( ' ', $tokens ) ) + self::LEARNED_SAMPLE_OVERHEAD_BYTES;
            if ( $spent + $cost > self::LEARNED_SIMILARITY_BUDGET ) {
                break;
            }

            $spent     += $cost;
            $admitted[] = $tokens;
        }

        return array_reverse( $admitted );
    }

    /**
     * Build a per-script unit from that script's samples.
     *
     * The spam half arrives split. The classifier trains on the shipped lists
     * followed by every learned sample, which is the order and the set
     * build_checks() used, so its state is byte-identical to the unsplit build.
     * The similarity corpus takes the shipped lists whole and only as much of
     * the learned tail as admit_within_budget() pays for.
     *
     * @param string             $script       Script group name.
     * @param array<int, string> $spam_file    Shipped spam samples.
     * @param array<int, string> $spam_learned Learned spam samples, ascending id.
     * @param array<int, string> $ham          Ham samples.
     * @return array{classifier: ANTISPAMWIZARD_Naive_Bayes|null, samples: array<int, array<int, string>>}
     */
    private function build_script_unit( string $script, array $spam_file, array $spam_learned, array $ham ): array {
        $spam = array_merge( $spam_file, $spam_learned );

        $classifier = null;
        if ( ! empty( $spam ) || ! empty( $ham ) ) {
            $classifier = new ANTISPAMWIZARD_Naive_Bayes( new ANTISPAMWIZARD_Tokenizer( $this->excluded_tokens ), $script );
            $this->training_data->train_classifier_with_samples( $classifier, $spam, $ham );
        }

        $samples = array();
        foreach ( $spam_file as $sample ) {
            $tokens = $this->tokenizer->tokenize_for_script( $sample, $script );
            if ( ! empty( $tokens ) ) {
                $samples[] = $tokens;
            }
        }

        foreach ( $this->admit_within_budget( $spam_learned, $script ) as $tokens ) {
            $samples[] = $tokens;
        }

        return array(
            'classifier' => $classifier,
            'samples'    => $samples,
        );
    }

    /**
     * Build the combined unit.
     *
     * The classifier's training set and its order are the ones build_checks()
     * used -- legacy files first, then each script in SCRIPT_GROUPS order, then
     * the repository's unknown-script rows -- so its verdicts are byte-identical
     * to the ones the monolithic cache produced.
     *
     * The similarity corpus is NOT: it holds the legacy samples and the
     * unknown-script rows only, never the ten per-script lists. That is an
     * accepted verdict deviation, taken because no representation gets this
     * unit under the 1 048 576-byte object-cache item limit while it carries
     * them (1 859 022 bytes packed, of which the per-script lists are
     * 1 101 154; without them, 766 696). The deviation applies to every body
     * that falls back here -- ANTISPAMWIZARD_Similarity_Check consults this corpus
     * whenever the detected script's own list is empty, not only for
     * 'unknown'. On the shipped corpus that is the 'unknown' bodies, which
     * have no unit of their own: a body with no letters at all (a bare phone
     * number, say), one written wholly in a script outside the ten
     * SCRIPT_GROUPS (Armenian, Georgian, Khmer, Lao and the rest), or one
     * whose detection hit a PCRE limit. One of the ten named scripts lands
     * here too when its own list comes out empty. build_script_unit() fills
     * that list from the script's *spam* samples alone, so a populated ham
     * corpus does not keep it off this path: it is empty when the script's
     * spam corpus file is missing, unreadable or empty with no learned spam
     * samples under that script, or when every spam sample of it tokenises to
     * nothing under the site's excluded tokens -- which
     * ANTISPAMWIZARD_Similarity_Check's store-backed tests pin through a fixture whose
     * Latin corpus is empty. The score such a body gets does change, and how
     * far it changes depends on which of those routes brought it here; the
     * four are not one case.
     *
     * Small for two of them. A body with **no letters at all** scores near
     * zero against the ten word lists whether they are here or not, except
     * through the tokens it can share with them -- chiefly
     * ANTISPAMWIZARD_Tokenizer::PHONE_TOKEN and bare numerals -- and that marker is
     * already the strongest single feature of the combined classifier, which
     * stays trained on every script's samples and cached in full. A **named
     * script whose own list came out empty** is the same argument one step
     * removed: that script's samples are exactly the ones the fallback found
     * missing, so they were absent from the monolithic combined corpus too,
     * and what the body loses is the *other* nine scripts' lists -- other
     * writing systems, low cosine against it except through those same
     * shared tokens (the phone marker, numerals, embedded Latin). Neither is
     * bounded at zero: a body whose only matches were shared tokens scores
     * lower than it did and can fall below the threshold it used to clear.
     *
     * Potentially substantial for the other two, which is recorded here
     * rather than folded into the argument above. A body written wholly in a
     * script **outside** SCRIPT_GROUPS matches the ten lists near zero as
     * far as the shipped corpus *files* go -- but a learned sample is filed
     * under its comment's dominant script and
     * ANTISPAMWIZARD_Tokenizer::tokenize_for_script() picks a tokenisation strategy
     * without filtering tokens by script, so a mostly-Latin comment quoting
     * Armenian is stored in the latin list carrying its Armenian tokens.
     * Where that has happened the removed list could have matched such a body
     * on its own writing system, so "no sample in its script" is a property
     * of the files, not a guarantee about the lists. A body routed here by a
     * **PCRE failure** in ANTISPAMWIZARD_Script_Detector::detect() is the worse case: it
     * is ordinary text in one of the ten scripts, and the list this unit no
     * longer carries is precisely the one that would have matched it -- a
     * strong match before this change and near zero after, for any of the
     * eight word-tokenised scripts (a CJK or Thai body loses little, because
     * 'unknown' word-tokenises it where those two lists hold bigrams). Both
     * routes keep the combined classifier, which trains on every script's
     * samples and runs ahead of this check, so the Bayesian verdict is
     * untouched; the Similarity verdict for them is not merely shifted but
     * can be lost outright.
     *
     * has_script_samples is therefore computed from the loaded per-script
     * samples rather than from what the corpus ended up holding, and stops at
     * the first non-empty tokenisation. It is the only input to
     * ANTISPAMWIZARD_Similarity_Check::resolve_from_store()'s legacy-vs-script
     * tokenisation branch, so a flip to false would change tokenisation for
     * every fallback body.
     *
     * @param array<int, string>               $legacy_spam Legacy spam samples.
     * @param array<int, string>               $legacy_ham  Legacy ham samples.
     * @param array<string, array<int, string>> $script_spam Per-script spam samples.
     * @param array<string, array<int, string>> $script_ham  Per-script ham samples.
     * @return array{classifier: ANTISPAMWIZARD_Naive_Bayes, samples: array<int, array<int, string>>, has_script_samples: bool}
     */
    private function build_combined_unit(
        array $legacy_spam,
        array $legacy_ham,
        array $script_spam,
        array $script_ham
    ): array {
        $all_spam = $legacy_spam;
        $all_ham  = $legacy_ham;
        foreach ( array_keys( ANTISPAMWIZARD_Script_Detector::SCRIPT_GROUPS ) as $script ) {
            $all_spam = array_merge( $all_spam, $script_spam[ $script ] );
            $all_ham  = array_merge( $all_ham, $script_ham[ $script ] );
        }

        // Include DB samples with script='unknown' (comments whose script could
        // not be identified). These have no seed file but still matter.
        $unknown_spam = array();
        if ( null !== $this->repository ) {
            $unknown_spam = $this->repository->find_all( 'unknown', 'spam' );
            $all_spam     = array_merge( $all_spam, $unknown_spam );
            $all_ham      = array_merge( $all_ham, $this->repository->find_all( 'unknown', 'ham' ) );
        }

        $classifier = new ANTISPAMWIZARD_Naive_Bayes( $this->tokenizer );
        $this->training_data->train_classifier_with_samples( $classifier, $all_spam, $all_ham );

        $samples = array();

        foreach ( $legacy_spam as $sample ) {
            $tokens = $this->tokenizer->tokenize_for_script( $sample, ANTISPAMWIZARD_Script_Detector::detect( $sample ) );
            if ( ! empty( $tokens ) ) {
                $samples[] = $tokens;
            }
        }
        // The legacy lists are shipped and fixed, so they are never capped. The
        // unknown-script rows are this site's learning history and take the
        // same LEARNED_SIMILARITY_BUDGET the per-script units spend; the
        // classifier above still trains on all of them.
        foreach ( $this->admit_within_budget( $unknown_spam, null ) as $tokens ) {
            $samples[] = $tokens;
        }

        $has_script_samples = $this->any_script_sample_tokenises( $script_spam );

        return array(
            'classifier'         => $classifier,
            'samples'            => $samples,
            'has_script_samples' => $has_script_samples,
        );
    }

    /**
     * Whether any script's spam samples tokenise to a non-empty list.
     *
     * The per-script lists are no longer stored in the combined unit, so this
     * answers from the samples that were loaded to train the combined
     * classifier. It stops at the first non-empty result: the question is
     * whether ANTISPAMWIZARD_Similarity_Check may tokenise a fallback body for its own
     * script, not how many lists there would have been.
     *
     * @param array<string, array<int, string>> $script_spam Per-script spam samples.
     */
    private function any_script_sample_tokenises( array $script_spam ): bool {
        foreach ( array_keys( ANTISPAMWIZARD_Script_Detector::SCRIPT_GROUPS ) as $script ) {
            foreach ( $script_spam[ $script ] as $sample ) {
                if ( ! empty( $this->tokenizer->tokenize_for_script( $sample, $script ) ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Restore a per-script unit from its cached form.
     *
     * @param array $cached Cached unit.
     * @param string $script Script group name.
     * @return array{classifier: ANTISPAMWIZARD_Naive_Bayes|null, samples: array<int, array<int, string>>}|null
     *         Null when the cached unit is unusable and must be rebuilt.
     */
    private function restore_script_unit( array $cached, string $script ): ?array {
        if ( ! array_key_exists( 'classifier', $cached ) || ! isset( $cached['samples'] ) || ! is_array( $cached['samples'] ) ) {
            return null;
        }

        $state      = $cached['classifier'];
        $classifier = null;

        if ( null !== $state ) {
            if ( ! is_array( $state ) ) {
                return null;
            }
            $state = $this->unpack_classifier_state( $state );
            if ( null === $state ) {
                return null;
            }
            $classifier = new ANTISPAMWIZARD_Naive_Bayes( new ANTISPAMWIZARD_Tokenizer( $this->excluded_tokens ), $script );
            $classifier->set_state( $state );

            // A script's corpus may legitimately be one-sided, so one category
            // is enough; neither means set_state() rejected the state. Requiring
            // both would rebuild on every request for a one-sided script.
            $restored = $classifier->get_state();
            if ( ! isset( $restored['doc_counts']['spam'] ) && ! isset( $restored['doc_counts']['ham'] ) ) {
                return null;
            }
        }

        $samples = $this->unpack_sample_tokens( $cached['samples'] );
        if ( null === $samples ) {
            return null;
        }

        return array(
            'classifier' => $classifier,
            'samples'    => $samples,
        );
    }

    /**
     * Restore the combined unit from its cached form.
     *
     * @param array $cached Cached unit.
     * @return array{classifier: ANTISPAMWIZARD_Naive_Bayes, samples: array<int, array<int, string>>, has_script_samples: bool}|null
     *         Null when the cached unit is unusable and must be rebuilt.
     */
    private function restore_combined_unit( array $cached ): ?array {
        if ( ! isset( $cached['classifier'] ) || ! is_array( $cached['classifier'] )
            || ! isset( $cached['samples'] ) || ! is_array( $cached['samples'] )
            || ! array_key_exists( 'has_script_samples', $cached ) ) {
            return null;
        }

        $state = $this->unpack_classifier_state( $cached['classifier'] );
        if ( null === $state ) {
            return null;
        }

        $classifier = new ANTISPAMWIZARD_Naive_Bayes( $this->tokenizer );
        $classifier->set_state( $state );

        // Both categories are required here: a partial combined model would
        // report "insufficient training data" and disable the Bayesian check
        // until the transient expired, instead of rebuilding now.
        $restored = $classifier->get_state();
        if ( ! isset( $restored['doc_counts']['spam'] ) || ! isset( $restored['doc_counts']['ham'] ) ) {
            return null;
        }

        $samples = $this->unpack_sample_tokens( $cached['samples'] );
        if ( null === $samples ) {
            return null;
        }

        return array(
            'classifier'         => $classifier,
            'samples'            => $samples,
            'has_script_samples' => (bool) $cached['has_script_samples'],
        );
    }

    /**
     * Export a per-script unit for caching.
     *
     * @param array{classifier: ANTISPAMWIZARD_Naive_Bayes|null, samples: array} $unit        Unit.
     * @param string                                                 $fingerprint Fingerprint of the samples it was built from.
     * @return array{fingerprint: string, classifier: array|null, samples: array<int, string>}
     */
    private function export_script_unit( array $unit, string $fingerprint ): array {
        return array(
            'fingerprint' => $fingerprint,
            'classifier'  => null === $unit['classifier'] ? null : $this->pack_classifier_state( $unit['classifier']->get_state() ),
            'samples'     => $this->pack_sample_tokens( $unit['samples'] ),
        );
    }

    /**
     * Export the combined unit for caching.
     *
     * @param array{classifier: ANTISPAMWIZARD_Naive_Bayes, samples: array, has_script_samples: bool} $unit        Unit.
     * @param string                                                                       $fingerprint Fingerprint of the samples it was built from.
     * @return array{fingerprint: string, classifier: array, samples: array<int, string>, has_script_samples: bool}
     */
    private function export_combined_unit( array $unit, string $fingerprint ): array {
        return array(
            'fingerprint'        => $fingerprint,
            'classifier'         => $this->pack_classifier_state( $unit['classifier']->get_state() ),
            'samples'            => $this->pack_sample_tokens( $unit['samples'] ),
            'has_script_samples' => $unit['has_script_samples'],
        );
    }

    /**
     * Whether a cached unit was built from the samples the store sees now.
     *
     * This is the invalidation test that used to live in the transient key.
     * Moving it into the value is what makes a rebuild *overwrite* the unit it
     * replaces: with the fingerprint in the key, an invalidated unit kept its
     * own row and the new one was written beside it, so a personal-data erasure
     * could purge every current key and still leave an older unit holding the
     * erased comment's text as a token list readable for the rest of its day.
     *
     * A cached value with no fingerprint member is one this class did not write
     * and is rebuilt rather than served.
     *
     * @param array  $cached      Cached unit.
     * @param string $fingerprint Fingerprint the store computed for it.
     * @return bool
     */
    private function fingerprint_matches( array $cached, string $fingerprint ): bool {
        return isset( $cached['fingerprint'] ) && $cached['fingerprint'] === $fingerprint;
    }

    /**
     * Pack sample token lists for caching: one space-delimited string per list.
     *
     * A cached unit is one serialized value measured against the object cache's
     * 1 MB per-item limit, and a nested array holding one token per entry is the
     * most expensive way PHP can store a word list -- every token pays an array
     * element's overhead on top of its own bytes. Joining each list into a
     * single string removed ~25% of every unit's serialized size, and the two
     * units that were over the limit are what made that worth doing.
     *
     * A space is an exact delimiter rather than a convenient one: ANTISPAMWIZARD_Tokenizer
     * splits the word path on /[^\p{L}\p{N}\p{M}]+/u and strips the same class
     * before emitting bigrams on the n-gram path, so no token it emits can ever
     * contain a space. explode( ' ', … ) is therefore the exact inverse of this.
     *
     * @param array<int, array<int, string>> $samples Token lists.
     * @return array<int, string> One packed string per list.
     */
    private function pack_sample_tokens( array $samples ): array {
        $packed = array();

        foreach ( $samples as $tokens ) {
            $packed[] = implode( ' ', $tokens );
        }

        return $packed;
    }

    /**
     * Unpack a cached sample list, or reject the unit that carries it.
     *
     * Validated to both levels, not just the outer one: ANTISPAMWIZARD_Cosine_Similarity
     * builds its term-frequency vector by using each token as an array key, so
     * an array or object among them is an illegal offset -- a TypeError that
     * ANTISPAMWIZARD_Detector::run()'s fail-open catch swallows, silently disabling the
     * Similarity check for the rest of the unit's 24-hour life while writing a
     * plugin-attributable line into debug.log on every submission.
     *
     * A malformed list makes the whole unit unusable rather than being filtered
     * out of it, for the same reason every other validation failure here is:
     * dropping it would serve a similarity corpus that is silently short of the
     * samples it was built from -- in the limit an empty one, which disables the
     * check just as thoroughly -- and re-serve it from the same row for a full
     * day, while rebuilding costs one cold load and overwrites the corrupted
     * row.
     *
     * Three cases are rejected, and the first of them is what a unit written by
     * the previous release looks like: a member that is **not a string** -- the
     * old representation stored each list as a nested array, so a unit cached
     * before this change is simply a value in a shape this version does not
     * recognise and is rebuilt like any other; an **empty string**, because
     * build_script_unit() and build_combined_unit() only ever append a non-empty
     * list, and one empty list is enough to make a script's corpus test
     * non-empty and suppress the fall back to the combined unit that a script
     * with no samples is supposed to take; and a string carrying an **empty
     * segment** (a leading, trailing or doubled space), which explodes to an
     * empty token -- a term-frequency key no tokenizer output can produce and
     * that skews every vector it appears in.
     *
     * An empty *outer* array is valid: it is what a script with no spam samples
     * legitimately caches.
     *
     * @param array $samples Cached packed sample strings.
     * @return array<int, array<int, string>>|null Null when the unit must be rebuilt.
     */
    private function unpack_sample_tokens( array $samples ): ?array {
        $clean = array();

        foreach ( $samples as $packed ) {
            if ( ! is_string( $packed ) || '' === $packed ) {
                return null;
            }

            $tokens = explode( ' ', $packed );

            foreach ( $tokens as $token ) {
                if ( '' === $token ) {
                    return null;
                }
            }

            $clean[] = $tokens;
        }

        return $clean;
    }

    /**
     * Pack a classifier's state for caching: one interleaved
     * "token count token count …" string per category.
     *
     * `token_counts` is the larger half of a classifier's state and the only
     * part of it that grows with the corpus -- one array entry per learned
     * token, each paying an element's overhead on top of the token and the
     * count themselves. Interleaving the pairs into a single string per
     * category is what brings the two units that were over the object cache's
     * 1 MB per-item limit under it; the combined unit's state alone serialised
     * to 1 225 068 bytes unpacked against 757 733 packed, i.e. it was over the
     * limit even with every sample list removed.
     *
     * `token_totals` and `doc_counts` are one entry per category and are left
     * native. The delimiter is safe for the same reason it is in
     * pack_sample_tokens(): the keys here are tokenizer output, and no token
     * ANTISPAMWIZARD_Tokenizer emits can contain a space.
     *
     * ANTISPAMWIZARD_Naive_Bayes is untouched by any of this -- the store unpacks before
     * calling set_state(), so the state that class accepts is exactly today's.
     *
     * @param array $state Classifier state from ANTISPAMWIZARD_Naive_Bayes::get_state().
     * @return array The same state with token_counts packed.
     */
    private function pack_classifier_state( array $state ): array {
        $packed = array();

        foreach ( $state['token_counts'] as $category => $counts ) {
            $flat = array();

            foreach ( $counts as $token => $count ) {
                $flat[] = $token;
                $flat[] = $count;
            }

            $packed[ $category ] = implode( ' ', $flat );
        }

        $state['token_counts'] = $packed;

        return $state;
    }

    /**
     * Unpack a cached classifier state, or reject the unit that carries it.
     *
     * Null means the unit is unusable and must be deleted and rebuilt, the same
     * answer every other unrecognised value here gets. A unit written before
     * this change carries `token_counts` as a nested map, so it is simply a
     * value in a shape this version does not recognise and is rebuilt like any
     * other -- there is no migration to perform and none is wanted: a rebuild
     * costs one cold load and overwrites the row.
     *
     * Five cases are rejected. A `token_counts` member that is not an array at
     * all, and a category whose value is not a **string** -- which is both the
     * old nested shape and anything a third-party `option_*` filter or a
     * truncated option row can leave behind. An **odd** number of segments,
     * which cannot come from a packer that always appends a pair, and reading
     * it would shift every count after the break onto the wrong token. A
     * **non-integer count** segment, which `(int)` would quietly turn into 0 --
     * a token silently dropped out of every probability the category computes.
     * And an **empty token** segment, which an even-length damaged string can
     * carry (a doubled space between a count and the next token) and which no
     * tokenizer output can produce -- it would install a `''` key in
     * `token_counts`, inflating the vocabulary size every Laplace-smoothed
     * probability the category serves is computed from.
     *
     * An **empty string** is the one value that is not a failure: it is what a
     * category with no learned tokens packs to, and it is handled before the
     * pair walk because `explode( ' ', '' )` yields one empty segment rather
     * than none.
     *
     * @param array $state Cached classifier state.
     * @return array|null Null when the unit must be rebuilt.
     */
    private function unpack_classifier_state( array $state ): ?array {
        $counts = $state['token_counts'] ?? null;

        if ( ! is_array( $counts ) ) {
            return null;
        }

        $unpacked = array();

        foreach ( $counts as $category => $packed ) {
            if ( ! is_string( $packed ) ) {
                return null;
            }

            $map = array();

            if ( '' !== $packed ) {
                $segments = explode( ' ', $packed );
                $length   = count( $segments );

                if ( 0 !== $length % 2 ) {
                    return null;
                }

                for ( $i = 0; $i < $length; $i += 2 ) {
                    $token = $segments[ $i ];
                    $count = $segments[ $i + 1 ];

                    if ( '' === $token || ! ctype_digit( $count ) ) {
                        return null;
                    }

                    $map[ $token ] = (int) $count;
                }
            }

            $unpacked[ $category ] = $map;
        }

        $state['token_counts'] = $unpacked;

        return $state;
    }

    /**
     * Compute a per-script unit's fingerprint.
     *
     * Inputs: that script's two corpus files' mtime and size, the
     * excluded-tokens fingerprint, and that script's DB fingerprint. The global
     * sample revision is deliberately absent -- a write to another script must
     * not invalidate this unit.
     *
     * @param string $script Script group name.
     * @return string
     */
    private function script_fingerprint( string $script ): string {
        $this->db_fingerprint_unavailable = false;

        $parts = array();

        foreach ( array( 'spam', 'ham' ) as $category ) {
            $parts[] = $this->file_stamp( ANTISPAMWIZARD_PATH . 'data/' . $category . '-samples-' . $script . '.txt' );
        }

        $parts[] = 'excl:' . $this->excluded_fingerprint();

        $db = $this->script_db_component( $script );
        if ( null !== $db ) {
            $parts[] = $db;
        }

        return md5( implode( '|', $parts ) );
    }

    /**
     * Compute the combined unit's fingerprint.
     *
     * The combined unit merges every script, so every corpus file and the
     * global sample revision are all inputs.
     *
     * @return string
     */
    private function combined_fingerprint(): string {
        $this->db_fingerprint_unavailable = false;

        $parts = array();

        foreach ( array( 'spam-samples.txt', 'ham-samples.txt' ) as $file ) {
            $parts[] = $this->file_stamp( ANTISPAMWIZARD_PATH . 'data/' . $file );
        }
        foreach ( array_keys( ANTISPAMWIZARD_Script_Detector::SCRIPT_GROUPS ) as $script ) {
            foreach ( array( 'spam', 'ham' ) as $category ) {
                $parts[] = $this->file_stamp( ANTISPAMWIZARD_PATH . 'data/' . $category . '-samples-' . $script . '.txt' );
            }
        }

        $parts[] = 'excl:' . $this->excluded_fingerprint();

        if ( null !== $this->repository ) {
            $parts[] = 'dbrev:' . $this->repository->latest_revision();

            // The revision alone is not enough to carry the erasure race guard.
            // It is a second write -- bump_revision() fires an UPDATE on
            // wp_options after the DELETE it follows and cannot report a
            // refusal -- so a delete whose bump the database refused leaves it
            // exactly where it was, and the post-write supersession check in
            // combined_unit() would then accept the pre-erasure payload this
            // request is holding. The table's own COUNT(*)/MAX(id) cannot be
            // defeated that way. A read that failed contributes nothing and the
            // key falls back to the revision alone, which is what it carried
            // before: on a host whose samples table was never created the query
            // fails on every request, and there are no stored samples to erase.
            $table = $this->repository->table_fingerprint();
            if ( null !== $table ) {
                $parts[] = 'dbtable:' . $table;
            } else {
                $this->db_fingerprint_unavailable = true;
            }
        }

        return md5( implode( '|', $parts ) );
    }

    /**
     * A script's DB cache-key component.
     *
     * @param string $script Script group name.
     * @return string|null Null when there is no repository to ask.
     */
    private function script_db_component( string $script ): ?string {
        if ( null === $this->repository ) {
            return null;
        }

        $fingerprint = $this->repository->script_fingerprint( $script );
        if ( null === $fingerprint ) {
            // The fingerprint query failed -- on a host whose samples table was
            // never created it fails on every request. Fall back to the global
            // revision and keep caching: a never-cache rule would strand such a
            // site on the cold path for good, while the revision still moves on
            // every sample write, so invalidation stays correct, merely coarser.
            $this->db_fingerprint_unavailable = true;

            return 'dbrev:' . $this->repository->latest_revision();
        }

        return 'db:' . $fingerprint;
    }

    /**
     * Fingerprint of the excluded-tokens setting.
     *
     * A new invalidation input: tokenisation moved off the request path into
     * the cached unit, so a unit built under a different exclusion list must
     * not be served.
     *
     * @return string
     */
    private function excluded_fingerprint(): string {
        if ( null === $this->excluded_fingerprint ) {
            $this->excluded_fingerprint = md5( serialize( $this->excluded_tokens ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Fingerprinting a local settings array for a cache key, never unserialized.
        }

        return $this->excluded_fingerprint;
    }

    /**
     * Stamp a corpus file by modification time and size.
     *
     * @param string $path Absolute path.
     * @return string `mtime:size`, or `0:0` when the file is absent.
     */
    private function file_stamp( string $path ): string {
        if ( ! file_exists( $path ) ) {
            return '0:0';
        }

        return filemtime( $path ) . ':' . filesize( $path );
    }
}
