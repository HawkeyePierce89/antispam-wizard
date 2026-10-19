# Load one writing system's models per submission, not all ten

## Overview

`ASW::build_checks()` today reads all 22 corpus files, unserialises one transient holding all ten per-script classifiers plus the combined one, and re-tokenises every spam line of every script — on every comment and every CF7 submission. This plan replaces that with **per-script model units**: one transient per writing system holding that script's classifier state *and* its pre-tokenised spam sample list, plus one combined unit, all loaded lazily by a new `ASW_Model_Store` that the Bayesian and Similarity checks consult. A request loads the unit for the script it detects and nothing else; a warm request reads no corpus file at all.

Verdicts stay byte-identical: the store reproduces today's resolution rules exactly (per-script classifier only when both categories reach `MIN_TRAINING_SAMPLES`, combined otherwise; per-script samples when that script's token list is non-empty, combined otherwise; legacy `tokenize()` mode only when *no* script has samples at all).

## Context

- Files involved:
  - `antispam-wizard/includes/class-antispam-wizard.php` — `build_checks()` (lines 186–450) and `compute_training_cache_key()`; both are gutted and replaced by store construction.
  - `antispam-wizard/includes/class-model-store.php` — **new**: the lazy per-script model loader.
  - `antispam-wizard/includes/checks/class-bayesian-check.php` — accept a store as a third construction form.
  - `antispam-wizard/includes/checks/class-similarity-check.php` — same.
  - `antispam-wizard/includes/class-sample-repository.php` — **new** `script_fingerprint()`.
  - `antispam-wizard/includes/class-training-data.php` — unchanged API; the store calls `load_script_samples()` / `load_samples()` / `train_classifier_with_samples()`.
  - `antispam-wizard/antispam-wizard.php` — `require_once` the new class.
  - `tests/bootstrap.php` — record `get_transient()` reads; add a `GROUP BY` branch and an `on_get_results` hook to the `$wpdb` stub.
  - `tests/BootstrapStubsTest.php`, `tests/ASWTest.php`, `tests/SampleRepositoryTest.php`, `tests/PluginMetadataTest.php`, and a new `tests/ModelStoreTest.php`.
  - `site/checks/bench.php` — **new** measurement script (the `site/checks` dir is already bind-mounted read-only at `/asw-site/checks`).
  - `CLAUDE.md`, `antispam-wizard/readme.txt`.
- Related patterns:
  - `ASW_*` prefix, `class-{name}.php`, no namespaces, strict types, PHP 8.1.
  - Existing invalidation logic in `compute_training_cache_key()` (mtime+size per file, `dbrev:` from `latest_revision()`) and the before/after re-check + `key_changed` no-cache rule in `build_checks()` — both are preserved, per unit.
  - `ASW_Sample_Repository::schema_is_current()`'s `$wpdb->suppress_errors( true )` bracket restored in a `finally` — the new `script_fingerprint()` follows it verbatim.
  - `uninstall.php` deletes `_transient_asw_classifiers_%` and `_transient_timeout_asw_classifiers_%` by LIKE. **Every new key must therefore keep the literal `asw_classifiers_` prefix** so `uninstall.php` and `tests/UninstallTest.php` (which asserts `asw\_classifiers\_` in the query) stay unchanged.
  - Repository queries use `$wpdb->prepare()` with `%i`/`%s` and the anchored `phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared` comment.
- Dependencies: none new.

### Key layout

| Unit | Transient key | Contents |
|---|---|---|
| per script | `asw_classifiers_<script>` | `fingerprint` (md5 of the key inputs below), `classifier` (NB state, or `null` when the script has no samples), `samples` (pre-tokenised spam token lists) |
| combined | `asw_classifiers_combined` | `fingerprint`, `classifier` (NB state), `samples`, `has_script_samples` (bool) |

`combined` is not a script-group name, so no collision is possible.

**Revised after review.** The keys were originally fingerprinted
(`asw_classifiers_<script>_<md5>`). That made the key list a moving target: a
rebuild wrote a new row *beside* the row it invalidated rather than overwriting
it, so every corpus edit and every training write left a full orphaned unit in
`wp_options` until its TTL ran out — and `ASW_Privacy::erase()`, which can only
purge keys it can compute, left an older unit holding the erased comment's text
as a token list whenever an unrelated training write had moved the fingerprint
since that unit was cached. Taking the keys before the delete does not help,
because the fingerprint had already moved for a reason that has nothing to do
with the erasure. The key now names the unit and never moves; the fingerprint is
stored inside the value and checked on read, so a rebuild overwrites the unit it
replaces and `ASW_Model_Store::unit_keys()` (now static) is exhaustive.
`PrivacyTest::test_erase_purges_a_unit_cached_before_an_unrelated_training_write`
pins it.

**Revised again after review.** Three more erasure-durability gaps in the same
area, all fixed:

1. **Post-write supersession.** The pre-write fingerprint check leaves a window:
   `ASW_Privacy::erase()` deletes the sample rows and *then* purges every unit
   key, so an erasure landing between that check and the `set_transient()` was
   followed by the in-flight request writing its already-loaded, pre-erasure
   tokens back into the row the eraser had just emptied. Both `script_unit()`
   and `combined_unit()` now re-read the fingerprint **after** the write and
   `delete_transient()` on a mismatch, which closes it in both orders: an
   erasure that already committed has moved the fingerprint, and one that
   commits later purges the row itself. Cost: one extra fingerprint read on the
   cold path. `ModelStoreTest::test_a_unit_superseded_between_the_check_and_the_write_is_dropped`
   and its `combined` twin pin it, driven by the new `$wp_on_set_transient`
   bootstrap seam, which fires just *before* the write lands.
2. **Purge failures were silent.** `delete_units()` discarded every
   `delete_transient()` result, so a refused delete left the erased text cached
   while `erase()` reported `items_retained => false`. It now returns `bool`,
   and — because `delete_transient()` answers false for a key that was not there
   as well as for a refused delete, and ten of the eleven keys are normally
   absent — it tells the two apart by reading the key back. `erase()` sets
   `items_retained` on a false, reusing the existing "could not be removed
   because of a database error" msgid so the i18n contract is untouched.
3. **A refused purge was not durable, and deletion was taken on trust.**
   `delete_units()` now reads the key back through `stored_unit()` rather than
   `get_transient()` — core cleans an *expired* database-backed transient up on
   read and returns false whether or not those option deletes landed, so an
   expired unit whose deletion the backend refused read back as purged while the
   row, and the erased comment's text in it, stayed in `wp_options`. And the
   post-write supersession guard verifies its own `delete_transient()`: the
   erasure is over by then, so a refused delete left the pre-erasure tokens the
   request had just written as the only copy; the row is read back and, when it
   survived, overwritten with a value carrying neither `samples` nor classifier
   state (a write this request is demonstrably able to make, since its own
   `set_transient()` has just landed), and a row the options API refuses twice is
   deleted by prepared statement — without an external object cache a transient
   *is* those rows. A unit that survives that too is logged; there is nothing
   left to attempt.

   Two intermediate rounds built a durable "purge pending" record for this — a
   counter option, written and compare-and-cleared under an exclusive lock — and
   a later round **removed it again**. Every one of its remaining holes was the
   same hole: the record is a write through the option store whose refusal is the
   failure being recorded, its lock fails open on a host without working
   `flock()` and does not span web nodes, and `drop_superseded_unit()` could not
   report a record it failed to make. `ASW_Privacy::erase()` now purges on
   **page 1 of every erasure request**, whatever that page deleted, which is
   exactly what the retry its "please retry this erasure request" message asks
   for — the sample rows went with the first attempt — and which no refused
   write, unavailable lock or unshared filesystem can defeat. It costs one
   model-cache flush per erasure request, a rare admin-approved event, paid by
   the next comment in each script as a cold rebuild; a later page still leaves
   the cache alone unless it deleted a sample, so one paginated request flushes
   once. A retention nothing ever comes back for is bounded anyway: every unit
   carries a one-day TTL.

   In the same round `delete_units()` stopped reporting a *key* that survived and
   started reporting **training text that is still stored**. A unit the transient
   API refused to delete but whose value was blanked holds no personal data, and
   answering an erasure request with a database-error message and a retry request
   over it reported a retention that had not happened.
   `ModelStoreTest::…_sees_an_expired_row_a_refused_cleanup_left_behind`,
   `…_a_refused_delete_whose_text_was_stripped_is_not_reported_as_retained`,
   `…_a_unit_no_write_could_reach_has_its_rows_deleted`,
   `…_a_unit_that_survives_every_lever_is_reported_and_logged`,
   `…_a_superseded_unit_the_backend_would_not_drop_is_stripped_of_its_samples`,
   `…_a_superseded_unit_that_survives_every_lever_is_logged`,
   `PrivacyTest::test_erase_purges_the_model_cache_on_page_one_whatever_it_removed`
   and `…test_a_retried_erasure_purges_units_the_first_attempt_could_not_drop`
   pin them, driven by two bootstrap seams: `$wp_transient_expired` and
   `set_transient()` honouring `$wp_update_option_fails` on `_transient_<name>`.
4. **Malformed sample lists were filtered, not rebuilt.** A cached list holding
   a non-list or a non-string token was dropped from the corpus and the unit
   served anyway, so the Similarity check ran against a corpus silently short of
   its samples — in the limit empty, i.e. off — out of the same corrupted row
   for a full day. A malformed list now invalidates the unit, like every other
   validation failure here. An **empty** token list is rejected too: the
   builders only ever append non-empty lists, and one empty list is enough to
   make `ASW_Similarity_Check`'s `! empty( $script_tokens )` test pass and
   suppress the fall back to the combined corpus.

### Key inputs

- Per-script unit: `filemtime:filesize` of `data/{spam,ham}-samples-<script>.txt` (`0:0` when absent) + the excluded-tokens fingerprint + that script's DB component.
- Combined unit: all 22 files' `filemtime:filesize` + the excluded-tokens fingerprint + `dbrev:<latest_revision()>` + `dbtable:<table_fingerprint()>`.
- **Combined DB component** (added during review): `ASW_Sample_Repository::table_fingerprint()` — one prepared `SELECT COUNT(*) AS sample_count, MAX(id) AS max_id FROM %i`, folded as `<count>:<maxid>`, same `suppress_errors`/`last_error` handling as `script_fingerprint()`, omitted on a failed read so the key falls back to the revision alone. `dbrev:` cannot carry the erasure race guard by itself: `bump_revision()` is a second write (an `UPDATE` on `wp_options` after the `DELETE` it follows) that cannot report a refusal, so a delete whose bump was refused leaves the revision static and the post-write supersession check would accept a pre-erasure payload.
- **Per-script DB component**: `ASW_Sample_Repository::script_fingerprint( $script )` — one prepared query per loaded unit, `SELECT category, COUNT(*) AS sample_count, MAX(id) AS max_id FROM %i WHERE script = %s GROUP BY category` — folded as `spam:<count>:<maxid>|ham:<count>:<maxid>`, `0:0` for a missing category. `MAX(id)` is what makes it exact: a privacy erasure followed by a new training insert can leave `COUNT` unchanged.
- **Failed fingerprint read** (`get_results()` returns a non-array, i.e. `null`): the method returns `null` and the store substitutes `dbrev:<latest_revision()>` for that unit's DB component and **caches normally**. It is never read as "zero rows", and it is never a reason to skip caching: on a host whose samples table was never created — the refused-`CREATE` case the schema-version retry exists for — the query fails on *every* request, so a never-cache rule would put that site permanently on the cold path (~200 ms, ~35 MB per comment) where today's `latest_revision()` option read still caches. The global revision moves on every sample write, so invalidation stays correct, merely coarser.
- **Error suppression**: `script_fingerprint()` runs its query inside a `$wpdb->suppress_errors( true )` bracket restored in a `finally`, exactly as `schema_is_current()` does. Against a missing table the `SELECT` is a hard MySQL error and `wpdb::print_error()` writes to `error_log()` regardless of `show_errors`, gated only by `suppress_errors` — without the bracket every comment on such a host would append a plugin-attributable line to the very `debug.log` `dev/smoke.sh` scans.
- Only the **mid-load fingerprint race** (the fingerprint moving between the pre-load and post-load computation) keeps the never-cache rule.
- The **excluded-tokens fingerprint** is `md5( serialize( $excluded_tokens ) )` — a new invalidation input, since tokenisation moves off the request path into the cached unit.

## Development Approach

- **Testing approach**: TDD. Each task writes its failing test first, then the code.
- Complete each task fully before moving to the next.
- Tests run with `docker compose run --rm test` (PHPUnit 9.6 against the stub bootstrap).
- **CRITICAL: every task MUST include new/updated tests**
- **CRITICAL: all tests must pass before starting the next task**
- Existing array-based constructors of `ASW_Bayesian_Check` / `ASW_Similarity_Check` are not touched, so `BayesianCheckTest`, `SimilarityCheckTest`, `ScriptRoutingIntegrationTest`, `IntegrationTest` and `CorpusQualityTest` must pass **unchanged**.

## Implementation Steps

### Task 1: Baseline measurement harness and "before" numbers

**Files:**
- Create: `site/checks/bench.php`
- Modify: `tests/PluginMetadataTest.php`

- [x] write `site/checks/bench.php` for `wp eval-file` (no `declare( strict_types=1 )` — `eval-file` runs the file through `eval()`, where a `declare()` can never be the first statement, the same rule `site/checks/detect.php` and `site/checks/waitlist.php` already follow in practice)
- [x] the script measures, for a fixed ~300-character Cyrillic spam body: (a) cold `ASW::build_checks()` — every `asw_classifiers_*` transient deleted first, (b) warm `build_checks()`, (c) warm `build_checks()` plus one `ASW_Detector::evaluate()`; each reporting wall-clock ms and `memory_get_peak_usage( true )` delta over the baseline captured immediately before the call, averaged over 5 runs, plus the serialized byte size of every `asw_classifiers_*` transient it finds
- [x] add a **new** `PluginMetadataTest` assertion (none exists today) that every script in `site/checks/` — `detect.php`, `waitlist.php` and `bench.php` — exists and contains no `declare( strict_types=1 )`, with the `eval-file` reason as a one-line comment
- [x] bring the site stack up without destroying it: `docker compose -p asw-site -f site/docker-compose.yml ps`, and only `up -d` if it is not running — **never** `down`/`down -v`
- [x] run `docker compose -p asw-site -f site/docker-compose.yml exec -T wordpress wp --allow-root eval-file /asw-site/checks/bench.php` and record the "before" numbers verbatim into the plan file's Measurements section
- [x] run `docker compose run --rm test` — must pass before Task 2

### Task 2: Test-harness support — transient read recording, a `GROUP BY` `$wpdb` branch and an `on_get_results` hook

**Files:**
- Modify: `tests/bootstrap.php`, `tests/BootstrapStubsTest.php`

- [x] write the `BootstrapStubsTest` cases first: a `get_transient()` call appends its key to `$wp_transient_reads` (on both a hit and a miss, in call order); the `$wpdb` stub answers ``SELECT category, COUNT(*) AS sample_count, MAX(id) AS max_id FROM `wp_asw_samples` WHERE script = 'cyrillic' GROUP BY category`` with one row per present category carrying the right count and max id, and an empty array when no row matches; and `$wpdb->on_get_results` is invoked at the top of `get_results()` with the query, so a test can observe `$wpdb->suppress_errors` *while* the query runs (the `on_get_var` counterpart, cleared by `reset_tables()` alongside it)
- [x] add the `$wp_transient_reads` global (initialised at bootstrap, documented as "reset per test") and the recording line in the `get_transient()` stub
- [x] add the `on_get_results` hook and the `GROUP BY` branch to the `$wpdb` stub's `get_results()`, the branch placed **before** the generic `SELECT … FROM` branch and reusing `filter_rows_by_where()` for the `WHERE` clause; `$wpdb->get_results_result = false` must still make it return `null` so the failed-read path is reachable
- [x] run `docker compose run --rm test` — must pass before Task 3

### Task 3: Per-script DB fingerprint on the repository

**Files:**
- Modify: `antispam-wizard/includes/class-sample-repository.php`, `tests/SampleRepositoryTest.php`

- [x] write `SampleRepositoryTest` cases first: the fingerprint of a script with spam and ham rows is `spam:<n>:<maxid>|ham:<n>:<maxid>`; a script with no rows is `spam:0:0|ham:0:0`; a category with no rows renders `0:0`; deleting a row and inserting a new one changes the fingerprint even though the count is unchanged; another script's rows never move it; a forced `get_results_result = false` returns `null` (never a zero fingerprint); the emitted query is `prepare()`d with `%i` for the table and `%s` for the script (asserted off `$wpdb->prepared_queries`); and — through the new `on_get_results` hook — the query runs with `$wpdb->suppress_errors` **true**, with the previous value restored afterwards (the `schema_is_current()` bracket test's shape)
- [x] add `public function script_fingerprint( string $script ): ?string` following the file's existing `prepare()` + anchored `phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared` shape, with the `suppress_errors( true )` / `finally` restore bracket and a one-line reason comment pointing at the missing-table case
- [x] run `docker compose run --rm test` — must pass before Task 4

### Task 4: `ASW_Model_Store` — lazy per-script units

**Files:**
- Create: `antispam-wizard/includes/class-model-store.php`, `tests/ModelStoreTest.php`
- Modify: `antispam-wizard/antispam-wizard.php` (add the `require_once`)

- [x] write `ModelStoreTest` first, covering:
  - `script_for( $text )` detects once and memoises: two calls on the same text return the same value and only one detection happens
  - asking for the Cyrillic classifier reads exactly one transient key (`$wp_transient_reads`), and the key is `asw_classifiers_cyrillic`
  - a second ask for the same script reads no further transient (per-request memoisation)
  - the combined unit is only read when `combined_classifier()` / `combined_sample_tokens()` is called, and its key is `asw_classifiers_combined`
  - a warm unit is served without any corpus file read — pinned with an `ASW_Training_Data` subclass that counts `load_samples()` calls, injected through the store's constructor
  - a unit missing `classifier`, missing `samples`, or whose classifier state `set_state()` rejects (leaving neither `doc_counts['spam']` nor `doc_counts['ham']`) is `delete_transient()`d and rebuilt, never served; for the combined unit the rule is the stricter existing one — **both** `spam` and `ham` doc counts required
  - touching `data/spam-samples-cyrillic.txt`'s mtime/size changes the Cyrillic key and leaves the Latin key untouched; bumping the samples revision changes only the combined key; changing `excluded_tokens_list` changes every key
  - changing the underlying fingerprint between the pre-load and post-load key computation (the race) rebuilds from the samples just loaded and writes **no** transient — the one remaining never-cache rule
  - **null fingerprint, both halves**: with `get_results_result = false` forced, the Cyrillic unit's key carries `dbrev:<latest_revision()>` in place of the per-script component **and the transient IS written** (the site keeps caching); and a subsequent `bump_revision()` moves that key so the unit is rebuilt
  - building the Cyrillic unit reads only the two Cyrillic corpus files (cold path stays bounded)
- [x] implement `ASW_Model_Store` with constructor `( ASW_Tokenizer $tokenizer, array $excluded_tokens, ASW_Training_Data $training_data, ?ASW_Sample_Repository $repository )` and the public API: `script_for()`, `script_classifier( string $script ): ?ASW_Naive_Bayes`, `script_sample_tokens( string $script ): array`, `combined_classifier(): ASW_Naive_Bayes`, `combined_sample_tokens(): array`, `combined_has_script_samples(): bool`
- [x] per-unit load sequence, mirroring today's logic exactly: compute key (per-script DB component from `script_fingerprint()`, falling back to `dbrev:<latest_revision()>` when it is `null`) → `get_transient()` → validate → on hit, restore and memoise → on miss/invalid, `delete_transient()` if invalid, load that unit's samples, `clearstatcache( true )`, recompute the key, rebuild, and `set_transient( …, DAY_IN_SECONDS )` unless the key moved
- [x] per-script unit build: `load_script_samples( $script, 'spam'|'ham' )`, train one `ASW_Naive_Bayes( new ASW_Tokenizer( $excluded ), $script )`, and tokenise each spam sample with `tokenize_for_script( $sample, $script )`, dropping empty token lists — identical to today's construction; `classifier` is `null` when both sample arrays are empty
- [x] combined unit build: legacy `data/{spam,ham}-samples.txt` + every script's samples + the repository's `unknown`-script rows, trained into one classifier; combined sample tokens tokenised with the *per-sample detected* script for legacy and `unknown` rows and with the file's own script for per-script rows — byte-identical to the current ordering (legacy first, then each script in `SCRIPT_GROUPS` order, then `unknown` DB rows); `has_script_samples` records whether any script produced a non-empty token list
- [x] run `docker compose run --rm test` — must pass before Task 5

### Task 5: Wire the two checks to the store

**Files:**
- Modify: `antispam-wizard/includes/checks/class-bayesian-check.php`, `antispam-wizard/includes/checks/class-similarity-check.php`
- Modify: `tests/BayesianCheckTest.php`, `tests/SimilarityCheckTest.php`

- [x] write the new test cases first: with a store, a long Cyrillic body resolves the Cyrillic classifier/samples and never touches another script's; an under-trained script falls back to the combined unit; an `unknown` script goes straight to combined; a body shorter than `min_message_length` returns before any unit is loaded; and `bayesian_enabled`/`similarity_enabled` false loads nothing
- [x] `ASW_Bayesian_Check::__construct( ASW_Naive_Bayes|array|ASW_Model_Store $classifiers, ?ASW_Naive_Bayes $combined_classifier = null )` — when a store is given, `resolve_classifier_for_script()` asks the store for the script's classifier and uses it only when both doc counts reach `MIN_TRAINING_SAMPLES`, else `combined_classifier()`; the existing array and single-classifier forms are untouched
- [x] `ASW_Similarity_Check::__construct( ASW_Tokenizer, ASW_Cosine_Similarity, array|ASW_Model_Store $spam_samples, ?array $script_samples = null, ?array $combined_samples = null )` — when a store is given: take the script's token list; if it is non-empty use it and `tokenize_for_script()`; otherwise use `combined_sample_tokens()` and choose `tokenize_for_script()` vs the legacy `tokenize()` from `combined_has_script_samples()`. This reproduces today's `empty( $script_sample_tokens ) ? null : $detected` branch exactly, including the degenerate empty-corpus case, and the combined unit is the only place that answer can come from — which is also the only case in which it is loaded
- [x] both checks obtain the script through `$store->script_for( $text )` so it is detected once per evaluation and shared
- [x] run `docker compose run --rm test` — must pass before Task 6

### Task 6: Rewire `build_checks()` and retire the monolithic cache

**Files:**
- Modify: `antispam-wizard/includes/class-antispam-wizard.php`
- Modify: `tests/ASWTest.php`, `tests/CF7IntegrationTest.php` (only if a cache assertion needs the new key shape)

- [x] write the new `ASWTest` cases first:
  - `build_checks()` alone reads **no** transient and opens **no** corpus file (assert `$wp_transient_reads` is empty after the call)
  - a warm Cyrillic evaluation through `ASW_Detector::evaluate()` reads exactly one `asw_classifiers_*` key and it is the Cyrillic one — no other per-script key, no combined key
  - an `unknown`-script body reads that script's unit (if any) plus the combined unit and nothing else
  - `bayesian_enabled` and `similarity_enabled` both false reads no `asw_classifiers_*` key at all
  - `build_checks()` still returns the same 10 checks in the same order with the bot checks first, and `build_checks( $request )` still injects the request into both bot checks (the existing CF7 assertion)
- [x] replace the old four `test_build_checks_*` cache tests and `test_compute_training_cache_key_returns_consistent_value` with their per-unit equivalents (caching, restore-from-cache, rebuild-on-corruption, key stability) driven through the store
- [x] rewrite `build_checks()` to: read `excluded_tokens_list`, build the tokenizer, construct `ASW_Model_Store`, and return the 10 checks with the store handed to the Bayesian and Similarity checks. Delete the eager corpus loading, the monolithic transient handling and `compute_training_cache_key()`
- [x] confirm `uninstall.php` is untouched and `tests/UninstallTest.php` passes unchanged (every new key keeps the `asw_classifiers_` prefix)
- [x] run `docker compose run --rm test` — must pass before Task 7

### Task 7: Verify acceptance criteria

- [x] `docker compose run --rm test` — full suite green, `CorpusQualityTest` and `MultiLangCheckTest`'s wall-clock guard unchanged and green
- [x] `bash dev/plugin-check.sh` — 0 errors, 0 warnings **as the harness was run at this point**. That result did not survive the plan: the four review-fix commits landed after it (see Deviations) introduced two new direct-`$wpdb` call sites in `class-model-store.php`, and the harness was not re-run over them, so the branch merged red — 1 error (`WordPress.DB.PreparedSQL.NotPrepared` on `delete_unit_rows()`'s multi-line query) and 1 warning (`PluginCheck.Security.DirectDB.UnescapedDBParameter` on `delete_stray_rows()`). Restored to a genuine 0/0 by `docs/plans/20260914-model-store-follow-ups-green-plugin-check-units-under-1mb.md`, Task 1, which also adds `PluginMetadataTest::test_not_prepared_suppressions_are_anchored_on_a_single_line_statement` so a previous-line suppression that does not cover its statement fails in the unit suite rather than only in the Docker harness
- [x] `bash dev/smoke.sh`
- [x] `bash site/verify.sh`
- [x] re-run `site/checks/bench.php` on the `asw-site` stack and record the "after" numbers; confirm warm `build_checks()` + one evaluation peaks under 10 MB above baseline and completes in under 15 ms for a Cyrillic comment, and that the one-script cold path is under the current cold figure (~200 ms / ~35 MB)
- [x] record every `asw_classifiers_*` unit's serialized size; note the largest in the plan (today's single transient is ~2.4 MB, already past the 1 MB object-cache item limit — per-script units are strictly an improvement, and any unit found over 1 MB is reported rather than silently accepted)

### Task 8: Update documentation

- [x] update `CLAUDE.md`: the per-script unit layout and key shapes; the four invalidation inputs (per-script corpus mtime/size, excluded-tokens fingerprint, per-script DB fingerprint via the single `GROUP BY` query with `MAX(id)`, and `dbrev` for the combined unit only); the `asw_classifiers_` prefix constraint that keeps `uninstall.php` unchanged; the `suppress_errors( true )` bracket on `script_fingerprint()` and **why a null fingerprint falls back to `dbrev:` and still caches** (a host whose samples table was never created fails that query on every request, and a never-cache rule would strand it on the cold path forever, while the global revision keeps invalidation correct if coarser — the mid-load key race is the only never-cache case left); the new `$wp_transient_reads` stub global, the `$wpdb` `GROUP BY` branch and the `on_get_results` hook; and the measured before/after numbers
- [x] update `antispam-wizard/readme.txt`'s "Will it slow down my site?" FAQ so it stays truthful about what is loaded per submission
- [x] fill in the plan file's Measurements section with the recorded before/after figures

## Measurements

Measured with `site/checks/bench.php` on the `asw-site` stack
(`wordpress:7.1-php8.3-apache`, PHP 8.3), 5 runs per case, 285-character
Cyrillic spam body, verdict `bayesian` in every run. Two consecutive
invocations agreed to within 2 ms and 0 bytes.

`peak alloc` (`memory_get_peak_usage( false )`) is the comparable column:
`peak real` is the arena PHP holds from the OS, so a warm run that allocates
inside what the cold run already grew reads as 0 — which is exactly what the
warm + evaluation row shows.

| | before (`bd26503`) | after (`1efd816` + Task 7) |
|---|---|---|
| warm `build_checks()` time | 43.40 / 44.82 ms | **0.01 ms** |
| warm `build_checks()` peak memory | 26.17 MB alloc (1.60 MB real) | **0.03 MB alloc (0.00 MB real)** |
| warm `build_checks()` + 1 evaluation | 45.66–47.59 ms, 26.18 MB alloc (0.00 MB real) | **2.44 ms, 4.80 MB alloc (0.00 MB real)** |
| cold `build_checks()` time | 192.56 / 194.97 ms | **0.01 ms** (loads nothing — see below) |
| cold `build_checks()` peak memory | 35.43 MB alloc (21.52 MB real) | **0.03 MB alloc (0.00 MB real)** |
| cold `build_checks()` + 1 evaluation (one-script cold path) | n/a — the cold cost was all in `build_checks()` | **37.58 ms, 10.84 MB alloc (8.00 MB real)** |
| cold `build_checks()` + 1 evaluation of an `unknown`-script body (combined unit) | n/a | **190.41 ms, 31.53 MB alloc (21.05 MB real)** |
| largest cached unit (serialized) | 2.34 MB / 2 455 017 bytes (`asw_classifiers_38f1a595…`, the single transient — 1 unit total) | **3.13 MB / 3 284 015 bytes** (`asw_classifiers_combined`); the Cyrillic unit is 1.00 MB / 1 050 388 bytes |

Targets met **for a body whose script has a unit**: warm build + evaluation is
2.44 ms / 4.80 MB against the 15 ms / 10 MB targets, and the one-script cold path
is 37.58 ms / 10.84 MB against the ~193 ms / 35.43 MB it replaces — 5.1x faster
and 3.3x less memory. A body that detects as `unknown` has no unit of its own and
falls through to the combined one, where the cold path is **unchanged** at 190.41
ms / 31.53 MB; see the open finding below.

Two measurement notes, both consequences of the store being lazy:

- `build_checks()` now touches **no** model, so the cold and warm `build_checks()`
  rows are both ~0 and no longer measure anything interesting. The cold cost moved
  to the first check that asks for a unit, which is why Task 7 added the
  `cold build_checks() + eval` case to `site/checks/bench.php`.
- The same change made the old warm-up line (`$asw->build_checks()` between cases
  (a) and (b)) stop warming anything, which folded one cold rebuild into the
  warm + evaluation average — that is the 9.90 ms an intermediate run reported.
  The harness now warms with a real evaluation, giving the 2.44 ms above.

### Closed finding: two units were over 1 MB, and the combined unit was larger than the cache it replaced

Reported here rather than silently accepted, per this task's acceptance
criterion. `bench.php` flags any unit over the 1 MB (1 048 576) object-cache item
limit by exact byte count:

| unit | serialized |
|---|---|
| `asw_classifiers_combined` | **3 284 015 bytes** |
| `asw_classifiers_cyrillic` | **1 050 388 bytes** |
| the single transient this replaced | 2 455 017 bytes |

The first revision of this measurement missed the combined unit entirely, and
the miss was in the harness, not the reading: every case in `bench.php` drove the
same Cyrillic body, and a Cyrillic body never loads the combined unit, so the
"any unit over 1 MB is reported" criterion was being checked against a set that
could not contain the largest one. `bench.php` now carries a fifth case driving
an `unknown`-script body, and evaluates both bodies once more before reading the
sizes (every measured case flushes, so only the last one's unit would still be on
disk).

The combined unit is **larger than the whole monolithic transient it replaces**
because it also carries the pre-tokenised Similarity corpus, which the old
transient never cached — that corpus was re-tokenised from the files on every
single request, and removing that is most of the warm win. So the trade is real
but narrower than the headline numbers suggest: a comment written in a script
that has a unit gets 2.44 ms warm / 37.58 ms cold, while a body that detects as
`unknown` — no letters at all, e.g. a bare phone number, the shape of the first
live miss this corpus work started from — falls through to the combined unit and
measures **190.41 ms / 31.53 MB cold**, i.e. no improvement over the ~193 ms /
~35 MB before. On the default object cache (transients in `wp_options`) that is
paid once per invalidation; on a site with memcached both units are refused
outright, so those comments pay it every time.

Shrinking them means touching the corpus or tokenisation, both explicitly out of
scope here, so it is left as follow-up work alongside growing the other eight
corpora. Two obvious candidates for that follow-up: not caching the Similarity
token lists in the combined unit at all (they are the bigger half), and giving
`unknown` a unit of its own.

**Closed by
`docs/plans/20260914-model-store-follow-ups-green-plugin-check-units-under-1mb.md`,
Tasks 2–4**, and without touching the corpus, the tokenizer or the classifier:
each sample's token list is cached as one space-delimited string, each classifier
category's `token_counts` as one interleaved `"token count token count …"`
string, and the combined unit's similarity corpus is narrowed to the legacy and
`unknown`-script lists (the first of the two candidates above; the second was not
needed). All eleven units are now under the bound on the same stack —
`asw_classifiers_combined` **765 095 bytes** (73.0% of 1 048 576) and
`asw_classifiers_cyrillic` **656 466** (62.6%), down from 3 284 015 and
1 050 388; the whole set totals 2 586 053 bytes, less than this plan's single
2 455 017-byte transient plus the Cyrillic unit alone. The warm path pays
+0.24 ms per submission for the unpack (2.44 → 2.68 ms); the one-script cold path
improves to 32.18 ms / 8.99 MB and the `unknown`-script one — the case this
finding said was unimproved — to **97.84 ms / 12.31 MB**, 1.95x faster on 2.6x
less memory. Narrowing the combined similarity corpus is an accepted verdict
change for **every** body that falls back to that corpus — the three routes
`ASW_Script_Detector::detect()` takes to `unknown` (no letters at all, a script
outside `SCRIPT_GROUPS`, a PCRE failure) and a named script whose own list came
out empty — small for the letter-less and empty-list routes, potentially
substantial for the unsupported-script and PCRE-failure ones; recorded per route
in that plan, in `build_combined_unit()`'s docblock and in `CLAUDE.md`.

The same overage keeps the core deprecation the "before" note predicted would
disappear: writing a unit still makes WordPress emit `preg_replace(): Passing
null to parameter #3 ($subject) is deprecated` from
`wp-includes/class-wpdb.php:3872`, because `wpdb::get_table_from_query()` still
overruns PCRE's backtrack limit on an `INSERT` that size. It is confirmed to
fire **only on the write**: a warm evaluation emits nothing. It is core-side and
not plugin-attributable — `dev/smoke.sh` and `site/verify.sh` both scan
`debug.log` and both pass — and it would go away with the unit size.

It did. With the packed units in place, `debug.log` truncated to 0 bytes and all
eleven units flushed, one Cyrillic and one `unknown`-script evaluation each wrote
their unit and left `debug.log` at **0 bytes**: the `INSERT` is now small enough
that `get_table_from_query()` no longer overruns the backtrack limit.

## Deviations

Six behaviours were added to `ASW_Model_Store`, `ASW_Privacy` and
`ASW_Sample_Repository` after Task 8 closed, in four review-fix commits that are
part of this plan's branch but not of its Implementation Steps. They are recorded
here because each changes what the store does at runtime, and because the last of
them is what made the Plugin Check result above go stale.

**Page-1 model-cache purge in `ASW_Privacy::erase()`.** The eraser now purges all
eleven unit keys on page 1 of *every* erasure request, whatever that page
deleted, and on any later page that actually removed a sample row. The finding:
gating the purge on the request's own deletions meant the retry its "please retry
this erasure request" message asks for found the sample rows already gone, so the
second request skipped the purge and closed clean over a unit the first could not
drop — and recording the first failure durably was no fix, since the only store
available is the option store whose refusal is the failure being recorded. A
purge the store reports as incomplete sets `items_retained`. Tested by
`PrivacyTest::test_erase_purges_the_model_cache_on_page_one_whatever_it_removed`,
`…test_a_retried_erasure_purges_units_the_first_attempt_could_not_drop` and
`…test_erase_retains_items_when_the_model_cache_purge_fails`.

**`wp_options` prefix sweep for rows no key can name.** `delete_units()` follows
the key loop with one prepared `DELETE` over `_transient_asw_classifiers_%` and
`_transient_timeout_asw_classifiers_%` (`delete_stray_rows()`). The finding: two
kinds of row carry a unit's token lists under a name this version cannot compute
— the monolithic `asw_classifiers_<md5>` transient earlier releases wrote, which
an upgrade leaves behind, and any unit row stranded when an object cache was
dropped into a site that had none, which core's own `delete_expired_transients()`
never collects. Without an external cache the eleven current keys are excluded by
name, so a refused per-key delete cannot be papered over by the sweep; with one,
every matching row is by definition a leftover and all of them go. Tested by
`ModelStoreTest::test_delete_units_removes_a_unit_row_no_key_can_name`,
`…test_delete_units_removes_unit_rows_an_object_cache_stranded` and
`…test_delete_units_reports_a_stray_row_sweep_the_database_refused`.

**The blank-then-delete-rows ladder for a refused delete.** When a unit survives
`delete_transient()`, `stop_storing_text()` overwrites it with `BLANKED_UNIT`
(`classifier => null`, `samples => array()`) and, if that write is refused too,
deletes the unit's own `_transient_…`/`_transient_timeout_…` rows by prepared
statement; each rung is verified by reading the row back, and a unit that
survives every rung is logged. The finding, reproduced in review: with every
write through the options API refused at once, the erased comment's text stayed
readable in `wp_options` for the rest of the unit's day with no lever left — yet
without an external object cache a transient *is* those rows, so a `DELETE`
reaches it. What `delete_units()` reports is whether training **text** is still
stored, not whether every key is gone. Tested by
`ModelStoreTest::test_a_refused_delete_whose_text_was_stripped_is_not_reported_as_retained`
and `…test_a_unit_no_write_could_reach_has_its_rows_deleted`.

**Post-write fingerprint re-read.** After `set_transient()` a unit's loader calls
`clearstatcache( true )`, recomputes the fingerprint and drops the row it just
wrote when the fingerprint has moved (`drop_superseded_unit()`, itself verified
through the same blank-then-delete ladder). The finding: `ASW_Privacy::erase()`
deletes the sample rows and *then* purges the unit keys, so an erasure landing
between a request's pre-write fingerprint check and its own `set_transient()` was
followed by that request writing its already-loaded, pre-erasure tokens back into
the row the eraser had just emptied — erased personal data restored after the
request reported success, with nothing left to purge it. The re-read closes it in
both orders and costs one fingerprint read on the cold path only. Tested by
`ModelStoreTest::test_a_unit_superseded_between_the_check_and_the_write_is_dropped`,
its `combined` twin, and the three `…superseded_unit…` ladder cases.

**`find_all()`'s sticky failed-read flag.** The repository sets a flag on a
`SELECT content` the database refused and the store checks it after building a
unit, leaving that unit uncached. The finding: `wpdb::query()` flushes
`last_result` to `array()` before running, so a refused read is byte-identical to
"this script has no rows" by return value — and a unit built through one carries
the corpus files alone while its fingerprint, computed from an aggregate query
that *did* answer, asserts the DB rows are in it. Caching that drops every
user-added sample out of both models for a full day with no signal anywhere. The
rule is conditioned on the DB fingerprint component having been read
successfully: when the aggregate read failed as well the component falls back to
`dbrev:` and asserts nothing about the rows, so withholding the write would
strand a host whose samples table was never created on the cold path forever.
Tested by
`SampleRepositoryTest::test_find_all_reports_a_failed_read_through_the_flag_not_the_return_value`,
`ModelStoreTest::test_a_script_unit_built_while_the_sample_read_failed_is_not_cached`
(and its `combined` twin),
`…test_a_host_whose_samples_table_is_missing_still_caches_its_units` and
`…test_a_unit_cached_on_a_fallback_fingerprint_is_rebuilt_once_the_table_answers`.

**Whole-table `COUNT(*)`/`MAX(id)` in the combined fingerprint.** The combined
unit's fingerprint gained `dbtable:<ASW_Sample_Repository::table_fingerprint()>`
alongside `dbrev:`. The finding: `bump_revision()` is a *second* write, an
`UPDATE` fired after the `DELETE` it follows and unable to report a refusal, so
an erasure whose bump the database refused removes rows while the revision stands
still — and the post-write supersession check above is built on the fingerprint
moving when rows go. A component read from the table itself cannot be defeated
that way; it is omitted (leaving the revision alone, as the key carried before)
when that read fails. Tested by
`ModelStoreTest::test_a_row_that_left_without_a_revision_bump_moves_the_combined_fingerprint`.

## Post-Completion (manual)

- Decide whether the other eight scripts' corpora can now be grown (the follow-up this work unblocks).

## Out of scope

Growing the other eight corpora; changing tokenisation, the classifier, thresholds or `MIN_TRAINING_SAMPLES`; moving models out of transients; any Spam Log / training / moderation change beyond invalidation.
