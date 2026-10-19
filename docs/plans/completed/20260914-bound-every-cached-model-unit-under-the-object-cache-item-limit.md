# Bound every cached model unit under the object-cache item limit, for any site history

## Overview

`ASW_Model_Store` caches eleven units (ten writing systems plus `combined`) as transients under the `asw_classifiers_` prefix. Each is the shipped corpus **plus every sample the site has learned**, and nothing bounds the second half: the open finding left by `docs/plans/completed/20260914-model-store-follow-ups-green-plugin-check-units-under-1mb.md`. When a unit crosses memcached's 1 048 576-byte per-item limit a persistent object cache refuses it silently and every submission in that writing system pays a cold rebuild forever.

Two measures, together, make the bound hold by construction rather than by measurement:

1. **Compress the cached value** — `gzcompress`/`gzuncompress` at level 6 behind an explicit envelope member, with an uncompressed fallback on a host without zlib. *(Corrected in Task 6: the envelope is confined to an external object cache, which is both the only backend with the limit and the only one that can hold a deflate stream — see that task's notes.)*
2. **Bound the learned similarity corpus by a packed-byte budget** — `ASW_Model_Store::LEARNED_SIMILARITY_BUDGET = 131072`, spent from the most recent sample id downwards, whole samples only. The classifier keeps learning from every sample; only the Similarity check's corpus is capped. That is a documented verdict change.

The classifier half is already bounded in *count* by `ASW_Naive_Bayes::MAX_DICTIONARY_SIZE` (50 000 tokens per category), which is out of scope to change. *(Task 7, added after review: its **bytes** are bounded too, by weighing the value before it is written rather than by changing that constant's job.)*

### Measurements taken for this plan

Run on the `composer:2` test image on `17d1c59` (workstation figures; Task 6 re-measures on the `asw-site` stack through `site/checks/bench.php`).

Shipped corpus, serialized bytes → `gzcompress`:

| unit | raw | L1 | L4 | L6 | L9 | `gzuncompress` | `gzcompress` L6 |
|---|---|---|---|---|---|---|---|
| cyrillic | 657 004 | 247 363 | 209 090 | **184 533** | 180 296 | 1.19–1.42 ms | 29.1 ms |
| combined | 765 095 | 333 603 | 298 750 | **283 546** | 280 330 | 1.70–1.90 ms | 33.1 ms |
| latin | 351 504 | 158 958 | 144 710 | **138 624** | 137 918 | 0.83 ms | 10.9 ms |

Decompression cost is essentially level-independent; compression cost is paid on the cold path only.

Worst case per unit — a full 50 000-token dictionary in **both** categories (synthesised from 4–10-character novel tokens) plus that script's whole shipped similarity corpus:

| unit | raw | L4 | L6 | L9 |
|---|---|---|---|---|
| cyrillic | 2 085 461 | 836 889 | **771 963** | 755 307 |
| latin | 1 247 007 | — | **589 456** | — |
| combined | 1 709 774 | 729 992 | **680 101** | — |

Deflate never expands its input by more than ~0.03 %, so `B` packed bytes of learned tokens add at most `B` compressed even if they do not compress at all. With `B = 131 072`:

| unit | worst case, compressed | % of 1 048 576 | margin |
|---|---|---|---|
| **cyrillic (binding)** | 903 035 | 86.1 % | 145 541 B |
| combined | 811 173 | 77.4 % | 237 403 B |
| latin | 720 528 | 68.7 % | 328 048 B |

At level 4 the binding case is 967 961 (92.3 %, margin 80 615) — level 6 buys 64 926 B of margin, 45 % of what is left, for ~20 ms more on a cold rebuild. Level 6 is the pick; level 4 is recorded as the alternative.

Shipped packed similarity lists, for reference: latin 242 052 B / 3 795 lists, cyrillic 387 185 / 3 527, thai 141 596 / 551, cjk 63 442 / 428, indic 59 057, bengali 50 532, devanagari 47 048, greek 39 154, arabic 30 114, hebrew 26 640, legacy 8 828 / 103.

### What 128 KiB of budget buys

617 packed bytes for one 40-word novel-vocabulary sample (the pessimistic `CorpusQualityTest` shape) → ~212 of them; ~300 packed bytes for a typical 300-character comment → ~430 of them. Against a shipped Cyrillic corpus of 3 527 spam lists. A single sample can never exceed `ASW_Detector::MAX_COMMENT_LENGTH` (65 536 chars, so ≤ 262 144 packed bytes) and `ASW_Tokenizer::MAX_TOKENS` (10 000 tokens, ~110 000 packed bytes for real text), so it is either admitted whole inside the budget or skipped — the learned portion is **never** above 131 072 bytes.

### Residuals stated, not papered over

*(The first of these is no longer a residual: Task 7 closes it, and with it the no-zlib one. The measurements below are kept because they are what the fix was chosen against.)*

- **The classifier bound is a measurement, not a proof.** `ASW_Tokenizer` caps a sample at 10 000 tokens but puts no cap on a token's *length*, and `ASW_Naive_Bayes::learn()` admits new tokens first-come-first-served until the *count* cap, so a site's learned history decides the dictionary's bytes. Over realistic 4–10-character tokens a full two-category dictionary packs to 1 698 179 B and the whole unit compresses to 771 963 B. The reachable bad case is not exotic: a Cyrillic-dominant sample is word-tokenised, so a run of 10 CJK characters survives as one 30-byte token, and a full two-category dictionary of those packs to 3 300 304 B and compresses to **2 210 916 B — 211% of the limit, on the classifier alone**, before a single similarity sample. Neither remedy that stays inside this plan's scope closes it, and both were measured rather than argued:
  - A **token-length cap** does not. The same dictionary at 8 / 6 / 4-character CJK tokens stores 1 744 832 / 1 361 368 / 903 976 B, so the cap would have to sit at or under 12 bytes — 4 CJK characters, 6 Cyrillic ones — to leave room for the samples. Only 52% of the shipped corpus's own tokens are that short, and its longest is a 174-byte Thai run (Thai has no inter-word spaces, so a word-tokenised Thai line is one token). The cap would truncate the shipped vocabulary long before it bounded an adversarial one.
  - A **compression-independent packed-byte budget** on the dictionary does not either. The shipped Cyrillic similarity corpus plus `LEARNED_SIMILARITY_BUDGET` is ~515 000 packed bytes, so the classifier would have to stay under ~533 000 B to fit 1 048 576 B at a 1.0x ratio — less than the shipped **combined** unit's own classifier, i.e. the shipped model would be the first thing truncated.
  What would close it is a **compression-dependent** packed budget of roughly 950 000 B (1.49x is the worst ratio measured on adversarial content, and the design already leans on compression — see the next residual), i.e. bounding `ASW_Naive_Bayes`'s dictionary by bytes as well as by count. That is a change to `MAX_DICTIONARY_SIZE`'s job, which this plan puts out of scope above, and it trades learned vocabulary on every site against a corner case that only bites behind memcached (Redis and APCu have no 1 MiB per-item limit). **Follow-up ticket, not this branch.**
- **Without zlib the raw worst case (2 085 461 B) is over the limit.** A host with no zlib, a full dictionary and a persistent object cache still meets the silent refusal. Every PHP build WordPress supports ships zlib, and the plugin behaves exactly as on `17d1c59` there.
- **Verdict change.** The Similarity check stops matching a body against learned spam older than the budget's reach for that script. The Bayesian check still carries every learned sample's vocabulary.

## Context

- Files involved:
  - `antispam-wizard/includes/class-model-store.php` — envelope, budget, build/restore/export
  - `antispam-wizard/includes/class-training-data.php` — split file/learned sample accessors
  - `antispam-wizard/includes/class-sample-repository.php` — `find_all()` gets `ORDER BY id ASC`
  - `tests/bootstrap.php` — zlib switch, `ORDER BY` in the `$wpdb` stub, a wrap/unwrap test helper
  - `antispam-wizard/includes/class-naive-bayes.php` — `classify()`'s Laplace denominator floored at 1 (added in Task 7)
  - `tests/ModelStoreTest.php`, `tests/CorpusQualityTest.php`, `tests/SampleRepositoryTest.php`, `tests/NaiveBayesTest.php`, `tests/BootstrapStubsTest.php`, `tests/TrainingDataTest.php`, `tests/BayesianCheckTest.php`, `tests/SimilarityCheckTest.php`, `tests/ASWTest.php`, `tests/PluginMetadataTest.php`
  - `README.md`
  - `site/checks/bench.php` — report the `wp_options` row size and the would-be object-cache item size
  - `CLAUDE.md`, `antispam-wizard/readme.txt`
- Related patterns: annotated `phpcs:ignore <exact sniff>` on a single-line statement; `defined( 'ASW_TESTING' )` guards; fail-open reads that rebuild rather than throw; conservative "this may hold text" reading in `holds_training_text()`.
- Unchanged by design: `uninstall.php` (same eleven keys, same prefix), `ASW_Privacy::erase()`, the fingerprint inputs, `ASW_Naive_Bayes::learn()`/`get_state()`/`set_state()`, the two checks' public behaviour, `ASW_Model_Store`'s public API. *(Corrected during Task 7: `ASW_Naive_Bayes::classify()` does take one change — its Laplace denominator is floored at 1. See that task's note.)*

## Development Approach

- **Testing approach**: TDD — write the failing test first, then the implementation.
- Complete each task fully before moving to the next.
- Tests run with `docker compose run --rm test`. Never `docker compose down` on `asw-site` or `antispam-wizard_*`.
- **CRITICAL: every task MUST include new/updated tests**
- **CRITICAL: all tests must pass before starting next task**

## Implementation Steps

### Task 1: Store every cached unit compressed, with an explicit uncompressed fallback

**Files:**
- Modify: `antispam-wizard/includes/class-model-store.php`
- Modify: `tests/bootstrap.php`
- Modify: `tests/ModelStoreTest.php`

- [x] add `private const COMPRESSED_MEMBER = 'zlib';` and `private const COMPRESSION_LEVEL = 6;` with a doc block carrying the level measurement and why level 6 over level 4
- [x] add `private static function zlib_available(): bool` — `function_exists( 'gzcompress' ) && function_exists( 'gzuncompress' )`, with a `defined( 'ASW_TESTING' )`-gated read of `$GLOBALS['asw_zlib_unavailable']` so a test can make the store believe the extension is absent
- [x] add `private static function wrap_unit( array $unit )` — with zlib, `array( self::COMPRESSED_MEMBER => gzcompress( serialize( $unit ), self::COMPRESSION_LEVEL ) )`; without it, `$unit` unchanged. A `gzcompress()` that returns `false` caches **nothing at all** — the caller skips its `set_transient()`, exactly as the mid-load-race and failed-sample-read rules do. *(Corrected after review: the plain-array fallback this bullet originally specified would be refused by `unwrap_for_read()` on every later read, i.e. a cold rebuild plus a wasted write and delete on every submission, where writing nothing costs the rebuild alone. Pinned by `ModelStoreTest::test_a_refused_gzcompress_caches_nothing_rather_than_an_unservable_value` and `…test_a_unit_is_cached_again_once_gzcompress_takes_the_payload`.)*
- [x] add `private static function inflate_unit( array $value ): ?array` — the shared decoder: returns the inner array for a recognised envelope (non-empty string under the member, `@gzuncompress`, `@unserialize( …, array( 'allowed_classes' => false ) )`, result must be an array), `null` when the envelope is unusable, and the value itself when no envelope member is present. Both suppressions carry an annotated `phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged` on their own single-line statement: a truncated row must rebuild, not write a warning into the `debug.log` `dev/smoke.sh` scans
- [x] add `private static function unwrap_for_read( $value ): ?array` — the **strict** rule the serving path uses: the stored form must match the host's capability. Compressed value + no zlib → null; plain array + zlib → null; anything else goes through `inflate_unit()`. An unrecognised or foreign value is rebuilt, never served
- [x] `script_unit()` and `combined_unit()`: pass `get_transient()`'s value through `unwrap_for_read()` before the `is_array()`/`fingerprint_matches()`/`restore_*` chain, so everything downstream sees exactly the array shape it sees today; wrap through `wrap_unit()` in both `set_transient()` calls
- [x] `holds_training_text()`: the **lenient** rule — a recognised envelope is inflated and judged by the existing member rules; an envelope that will not inflate (no zlib on this host, truncated payload) is treated as holding text, which is the conservative answer an erasure must take. Extend the doc block with the reason
- [x] `stop_storing_text()` keeps writing `BLANKED_UNIT` uncompressed — recognisably a unit and recognisably text-free without needing zlib to read it
- [x] `tests/bootstrap.php`: initialise `$GLOBALS['asw_zlib_unavailable'] = false`, and export a test helper (`asw_test_wrap_unit()` / `asw_test_unwrap_unit()`) so a test can seed and read a cached unit in whichever form the host writes
- [x] update the 18 `ModelStoreTest` sites that read or seed `$wp_transients[...]` directly to go through that helper
- [x] tests: a written unit is stored compressed and is smaller than its plain serialization; it round-trips to a unit whose classifier state and sample tokens are identical to the uncompressed build; with the zlib switch off the store writes and reads the plain form end to end; a compressed row on a no-zlib host, a plain row on a zlib host, a foreign value and a **truncated** compressed payload each delete the row and rebuild, with no PHP warning (`phpunit.xml` already sets `failOnWarning="true"`); `holds_training_text()` reports a compressed unit as holding text, and a compressed unit that no lever can drop is reported as retention while a blanked one is not
- [x] run `docker compose run --rm test` — must pass before Task 2

### Task 2: Give the samples table a deterministic, id-ordered read

**Files:**
- Modify: `antispam-wizard/includes/class-sample-repository.php`
- Modify: `tests/bootstrap.php`
- Modify: `tests/SampleRepositoryTest.php`

- [x] add `ORDER BY id ASC` to `find_all()`'s prepared statement, with a comment that the autoincrement is the only ordering that survives content dedup and re-insertion (`created_at` is not) and that the budget in Task 3 spends it from the newest end; keep the existing `phpcs:ignore` anchored on its single-line `get_results()` call
- [x] teach the `$wpdb` stub's generic `SELECT … FROM` branch to honour a trailing `ORDER BY <col> ASC|DESC`, so the ordering is actually exercised rather than inherited from insertion order
- [x] tests: `find_all()` returns samples in ascending id order when the stub's rows are not in id order, and its prepared query carries the `ORDER BY`; update the existing `prepared_queries` assertions the new clause changes
- [x] run `docker compose run --rm test` — must pass before Task 3

### Task 3: Bound the learned similarity corpus by a packed-byte budget

**Files:**
- Modify: `antispam-wizard/includes/class-training-data.php`
- Modify: `antispam-wizard/includes/class-model-store.php`
- Modify: `tests/ModelStoreTest.php`

- [x] `ASW_Training_Data`: add `load_script_file_samples()` and `load_learned_script_samples()`, and reimplement `load_script_samples()` as their merge, so every existing caller is byte-identical and the store can see the two halves apart without a second `find_all()` — the cold path keeps exactly the two reads per script it makes today, and the warm path still reads nothing
- [x] `ASW_Model_Store`: add `public const LEARNED_SIMILARITY_BUDGET = 131072;` with the justification in its doc block — the binding worst case (cyrillic 771 963 B compressed with a full two-category dictionary and the whole shipped list), the deflate no-expansion argument, the resulting 903 035 B / 86.1 % / 145 541 B margin, and what 128 KiB buys in samples
- [x] add `private function admit_within_budget( array $learned, ?string $script ): array` — walk from the last element (the highest id) downwards, tokenise each sample (`tokenize_for_script()`, detecting per sample when `$script` is null, which is the combined unit's rule), skip one that tokenises to nothing, **stop** at the first whose packed length would take the running total past the budget and admit nothing older, and return the admitted lists back in ascending-id order. Document that a single oversized sample therefore stops the walk and that the corpus self-heals as newer samples arrive
- [x] `script_unit()`: load the spam half split, train the classifier on `file + learned` in today's order (so the classifier is byte-identical), and build the similarity corpus from the file samples plus `admit_within_budget( $learned, $script )`; adjust `build_script_unit()`'s signature accordingly
- [x] `build_combined_unit()`: put `$unknown_spam` through `admit_within_budget( $unknown_spam, null )` for the similarity corpus while the classifier still trains on all of it; the legacy file lists are shipped and are never capped
- [x] confirm no change to `script_fingerprint()`, `combined_fingerprint()`, `any_script_sample_tokenises()` or `combined_has_script_samples()` — a sample beyond the cap must still move the fingerprint, because the classifier depends on it
- [x] tests: the learned portion of a script unit's similarity corpus never exceeds the budget; with enough learned spam to bind it, the newest samples are present and the oldest is absent while `script_classifier()`'s state still carries a token unique to that oldest sample (both halves of the verdict change, pinned); a learned sample of 10 000 tokens cannot take a unit over the limit — it is admitted whole or skipped, and nothing older follows it either way; the combined unit's unknown-script rows take the same budget while its legacy lists do not; learned **ham** never enters any similarity corpus; adding a sample beyond the cap still moves the script fingerprint and invalidates the unit
- [x] run `docker compose run --rm test` — must pass before Task 4

### Task 4: Pin the bound the design promises

**Files:**
- Modify: `tests/CorpusQualityTest.php`

- [x] replace `test_the_shipped_corpus_leaves_headroom_for_learned_samples()` with `test_an_unbounded_learning_history_still_fits_every_unit()`: seed enough pessimistic 40-word novel-vocabulary Cyrillic spam samples to overrun the budget several times over (and to drive the spam dictionary toward `MAX_DICTIONARY_SIZE`), then assert every stored unit fits. Keep the existing anti-vacuity guards — every insert lands, every token is novel and absent from the shipped corpus, the samples really reached the cyrillic and combined units — and add one more: the learned portion of the cyrillic similarity corpus is exactly at or under `LEARNED_SIMILARITY_BUDGET`, so the test cannot pass because the cap silently did nothing
- [x] add `test_a_full_dictionary_worst_case_unit_fits_the_object_cache_item_limit()`: build a synthetic 50 000-token-per-category classifier state and the real shipped Cyrillic similarity lists, pack them through `ASW_Model_Store`'s own packers by reflection (so the test cannot drift from the stored format), add `LEARNED_SIMILARITY_BUDGET` bytes of learned lists, compress at the store's level and assert the result is under 1 048 576 — printing the byte count and the margin. This is the plan's arithmetic as an executable assertion
- [x] `test_every_cached_model_unit_fits_the_object_cache_item_limit()` keeps measuring the shipped corpus, now against the compressed stored form; update its doc block
- [x] confirm the held-out recall (≥ 0.90) and false-positive (≤ 0.04) gates and the short-spam/short-ham canaries are untouched and green
- [x] run `docker compose run --rm test` — must pass before Task 5

### Task 5: Report the stored size, and document the deal

**Files:**
- Modify: `site/checks/bench.php`
- Modify: `CLAUDE.md`
- Modify: `antispam-wizard/readme.txt`
- Modify: `tests/PluginMetadataTest.php` (only if a new assertion is warranted)

- [x] `bench.php`: report each unit's **stored** (compressed) size as the number checked against 1 048 576 by exact byte count, and print the uncompressed size and the ratio alongside it, so a future corpus edit can see which half moved
- [x] `CLAUDE.md`: document the compressed envelope and its explicit member, the strict-on-read / lenient-in-`holds_training_text()` split and why they differ, the zlib fallback rule and the `ASW_TESTING`-gated switch that pins it, `LEARNED_SIMILARITY_BUDGET` with its justification and the stop-at-first-overflow walk, the verdict deviation for the Similarity check, the worst-case arithmetic with its margin, and both residuals (uncapped token length; no zlib) — in the `ASW_Model_Store` bullet and the Data storage section
- [x] `readme.txt`: rewrite the "Will it slow down my site?" answer — a warm submission is now a few milliseconds rather than a couple, the cold rebuild costs more because the model is compressed on write, and the "can eventually cross that limit" sentence is replaced by what is now true: the models stay under the limit as the site learns, because the Similarity corpus keeps the most recent learned spam within a fixed budget and the classifier's vocabulary is capped
- [x] tests: `I18nTest` stays green (no new translatable strings are expected — if any appear, regenerate the POT, `msgmerge` all nine `.po` files and recompile the `.mo`s); `UninstallTest` unchanged and green
- [x] run `docker compose run --rm test` — must pass before Task 6

### Task 6: Verify acceptance criteria

- [x] run the full suite: `docker compose run --rm test` — 1268 tests, 188 516 assertions, green
- [x] run `bash dev/plugin-check.sh` — 0 errors and 0 warnings; add an annotated `phpcs:ignore <exact sniff>` with a one-line reason, anchored on a single-line statement, for anything the error-suppression or serialization sniffs report (nothing was reported)
- [x] run `bash dev/smoke.sh` — LOADS / DETECTS / SCHEMA green and a clean `debug.log`
- [x] run `bash site/verify.sh` against the running `asw-site` stack (never `down`), then `docker compose -p asw-site -f site/docker-compose.yml exec -T wordpress wp --allow-root eval-file /asw-site/checks/bench.php` and record the fresh unit sizes and the warm / one-script-cold / unknown-cold figures against `17d1c59`'s 2.68 ms / 4.72 MB, 32.18 ms / 8.99 MB and 97.84 ms / 12.31 MB — reporting the warm-path regression the decompression costs, not hiding it
- [x] fold those site-stack numbers back into `CLAUDE.md` and this plan file, replacing the workstation estimates

#### What the site-stack run found, and the fix it forced

The first `bench.php` run reported **`cached units: 0`** with the warm path at **59.25 ms**,
identical to cold. The envelope written in Task 1 is a deflate stream, and without an
external object cache a transient **is** the `_transient_<name>` option row — a `longtext`
on a utf8mb4 connection. `wpdb::process_fields()` puts every value through
`strip_invalid_text()`, a deflate stream is not valid UTF-8, and the write is refused
outright: `WordPress database error: Could not perform query because it contains invalid
data.` (reproduced directly through `set_transient()` in the container). So the
unconditional envelope cached **nothing** on every site without a persistent object cache —
the default — and put every submission on the cold path for good: the exact failure the
envelope exists to prevent, made universal. Neither the unit suite (the `$wpdb` stub models
no charset validation) nor `dev/smoke.sh` (it never reads a unit back on a second request)
could see it.

The fix confines the envelope to the backend that has the limit:
`ASW_Model_Store::compression_applies()` is `zlib_available() && wp_using_ext_object_cache()`,
and `unwrap_for_read()`'s strict match uses the same predicate, so a compressed row an
object cache stranded when it was removed again is rebuilt rather than served. The
`wp_options` row has no per-item limit to respect (a `longtext` holds 4 GB against a combined
unit's 765 095 packed bytes), so the plain array costs it nothing. `holds_training_text()`
is unchanged — it stays lenient and still inflates a stranded envelope, which is now the
only shape in which a compressed unit can meet the option-API levers.

`LEARNED_SIMILARITY_BUDGET` is unaffected: it bounds packed bytes on both backends.

New tests: `ModelStoreTest::test_a_unit_is_stored_plain_without_an_external_object_cache`
(which also asserts the row serializes to valid UTF-8 — the property the unconditional
envelope broke) and `…test_a_compressed_unit_is_rebuilt_without_an_external_object_cache`.
The existing envelope tests run under the bootstrap's `$wp_using_ext_object_cache` switch,
and `CorpusQualityTest` turns it on class-wide, since every size it measures is of what a
cache would be handed. `bench.php` now prints both sizes per unit — the `wp_options` row and
what a cache would be handed — and checks the latter against 1 048 576.

#### Site-stack figures (`asw-site`, `wordpress:7.1-php8.3-apache`, PHP 8.3, 5 runs per case)

| case | this branch | `17d1c59` |
|---|---|---|
| cold / warm `build_checks()` | 0.01–0.02 ms / 0.03 MB | 0.01–0.03 ms / 0.03 MB |
| warm `build_checks()` + `evaluate()` | **2.72–2.78 ms / 4.71 MB** | 2.68 ms / 4.72 MB |
| one-script cold path | **31.80–32.07 ms / 8.99 MB** | 32.18 ms / 8.99 MB |
| `unknown`-script cold path | **96.08–96.55 ms / 12.31 MB** | 97.84 ms / 12.31 MB |

All three are within run-to-run noise of the pre-branch baseline, which is the expected
result now that the envelope is confined to the object cache: this stack runs without one,
so its units are the plain packed arrays they already were and neither the budget nor the
envelope costs it anything measurable. The **+0.24 ms warm-path regression** recorded in
`CLAUDE.md` remains the packed representation's, not this branch's; a site **with** an
object cache additionally pays the 1.2–1.9 ms `gzuncompress` per unit on the warm path,
measured in the plan's table above and not re-measurable on this stack.

Unit sizes, over the two units the benchmark's bodies build:

| unit | `wp_options` row | as a cache item | ratio | % of 1 048 576 |
|---|---|---|---|---|
| combined | 765 095 B | 283 575 B | 2.70x | 27.0 % |
| cyrillic | 656 466 B | 184 277 B | 3.56x | 17.6 % |

These match `CorpusQualityTest`'s in-suite measurement (combined 283 577, cyrillic 184 559;
the small delta is the site's own learned samples). `debug.log` stayed at 0 bytes across the
benchmark's flush-and-rebuild cycles, so the core `preg_replace()` deprecation from
`wpdb::get_table_from_query()` remains retired.

### Task 7: Bound the classifier too, by weighing the unit instead of predicting it (review follow-up)

External review would not take the first residual above as closed by documentation, and it was
right not to: the plan's own title says *for any site history*, and `readme.txt` promises a user
that the models "stay under the 1 MB per-item limit … however long your site has been learning".
The 2 210 916-byte cache item is reachable, so the promise was not true.

What closes it is admission control rather than another budget, because the two
compression-independent budgets that were measured for the residual both fail in the same way: a
ratio has to be assumed, and assuming 1.0x prunes ordinary sites whose units compress 2.5-3.5x
and are nowhere near the limit. (The residual's arithmetic against a *global* packed budget was
also unfair to a *per-unit* one, as the review pointed out: the Cyrillic unit's similarity
allowance and the combined unit's classifier never share a unit, and a per-unit budget does
preserve the shipped models. It still spends real vocabulary on hosts that never needed it to.)

- [x] `ASW_Model_Store::MAX_CACHED_UNIT_BYTES = 1048576`, and `size_limit_applies()` =
      `wp_using_ext_object_cache()` -- the limit is the cache's and only its, so nothing is
      weighed on the option-backed path, where a `longtext` holds 4 GB
- [x] `storable_unit( ?ASW_Naive_Bayes $classifier, array $export )` replaces the bare
      `wrap_unit()` call in `script_unit()` and `combined_unit()`: it weighs
      `strlen( serialize( … ) )` of the wrapped value and, while that is at or over the limit,
      calls `halve_dictionary()` and re-wraps. A unit that will not fit with an empty dictionary
      caches nothing, the answer a refused `gzcompress()` already gets, and leaves the live model
      alone
- [x] `halve_dictionary()` drops the least frequent half of each category's vocabulary and takes
      the dropped occurrences out of `token_totals` with them -- what `ASW_Naive_Bayes::learn()`
      already does with a token it refuses past `MAX_DICTIONARY_SIZE`, so the pruned state reads
      as a model that never saw them. `ASW_Naive_Bayes::learn()` and `get_state()`/`set_state()` are untouched; `classify()` takes one change — its Laplace denominator is floored at 1, so a state pruned (or never populated) to an empty vocabulary is a valid model rather than a `DivisionByZeroError` that `ASW_Detector::run()`'s fail-open catch would swallow while writing into the `debug.log` the harnesses scan (`NaiveBayesTest::test_classify_survives_a_trained_model_with_no_vocabulary` pins it)
- [x] the prune is handed back to the live classifier through `set_state()` before the write, so
      the cold request and every warm one after it classify with the same model
- [x] `$GLOBALS['asw_max_cached_unit_bytes']` (bootstrap, behind the `ASW_TESTING` gate the zlib
      switches use) lowers the limit for tests, so the loop is pinned by fast fixtures rather than
      by the suite's slowest test alone
- [x] tests: `ModelStoreTest::test_a_unit_over_the_item_limit_is_pruned_until_the_cache_can_hold_it`
      (pruned, cached, servable, frequency-ordered, `token_totals` consistent),
      `…test_the_request_that_prunes_a_unit_classifies_with_the_pruned_model`,
      `…test_a_unit_over_the_limit_is_left_whole_without_an_external_object_cache`,
      `…test_a_unit_that_cannot_fit_at_all_caches_nothing_and_keeps_its_model`,
      `…test_the_combined_unit_is_pruned_to_fit_as_well`; and
      `CorpusQualityTest::test_a_dictionary_of_long_tokens_is_pruned_until_the_unit_fits`, which
      builds the reachable 100 000 x 30-byte CJK dictionary, asserts it really is over the limit
      unpruned, and asserts the stored value is under it and equals the model the classifier kept
- [x] `readme.txt`'s guarantee now says what the code does -- the rarest words are dropped until
      the model fits -- and `CLAUDE.md` records the mechanism, the alternatives measured against
      it, and the retirement of both former residuals (the no-zlib worst case is pruned like any
      other, since the gate is the cache, not zlib)
- [x] `docker compose run --rm test` -- 1283 tests, 288 609 assertions, green

## Post-Completion

- Commit on a branch; do not push, open a PR or merge without being asked; no attribution lines.
- Next ticket (out of scope here): growing the other eight scripts' corpora — it must re-measure the combined unit, whose classifier state is the growth axis the corpus still has.
