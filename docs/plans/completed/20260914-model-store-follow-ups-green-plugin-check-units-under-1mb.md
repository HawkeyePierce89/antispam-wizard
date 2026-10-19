# Model store follow-ups: green Plugin Check, units under 1 MB, deviations recorded

## Overview

Three closing findings from the acceptance review of PR #21 (`ade6733`):

1. **Plugin Check is red.** Two suppressions in `antispam-wizard/includes/class-model-store.php` do not cover what they were written for — one `phpcs:ignore` sits above a multi-line `$wpdb->query( $wpdb->prepare( … ) )` and covers only its first line, while `WordPress.DB.PreparedSQL.NotPrepared` is reported two lines lower on the `$sql` argument; the other's sniff list does not name `PluginCheck.Security.DirectDB.UnescapedDBParameter`. Both are fixed by re-anchoring to the repository's existing shape (prepare into a variable, bare query call on the ignored line) and naming the missing sniff.
2. **Two cached units exceed the 1 MB object-cache item limit.** The fix is representational plus the one further cut the ticket's Q&A settled on: every sample's token list is stored as one space-delimited string, every classifier category's `token_counts` map as one interleaved `"token count token count …"` string, and the combined unit's similarity corpus is narrowed to the legacy (`data/spam-samples.txt`) and `unknown`-script DB rows only.
3. **The completed plan does not record the four review-fix commits' additions.** It gains a Deviations section, and its stale "0 errors, 0 warnings" checkbox is corrected.

All sizes and timings below were measured in the test container against the corpus on master (empty excluded-token list, no DB rows; the site stack reads ~2% lower).

### Measured: why this representation, and what it achieves

Serialized bytes per unit, against the 1 048 576-byte limit:

| unit | on master | samples packed | samples + classifier packed |
|---|---|---|---|
| cyrillic | 1 071 274 | 807 249 | **665 073** |
| latin | 766 620 | 476 534 | **369 570** |
| thai | 411 998 | 194 295 | **169 241** |
| cjk | 327 547 | 230 475 | **151 525** |
| indic | 181 836 | 153 076 | **127 681** |
| bengali | 138 809 | 109 500 | **91 855** |
| devanagari | 130 429 | 100 087 | **83 019** |
| greek | 118 753 | 91 327 | **73 558** |
| arabic | 107 261 | 82 368 | **62 205** |
| hebrew | 94 635 | 72 712 | **54 737** |
| combined | 3 367 770 | — | 1 859 022 (still over) |
| combined, legacy + unknown corpus only | — | — | **766 696** |

Packing the classifier state is not optional for the combined unit: its state alone serialises to 1 225 068 bytes unpacked and 757 733 packed, i.e. over the limit even with every sample list removed. And no representation gets the combined unit under the limit while it still carries every script's similarity lists (1 859 022 bytes; the lists alone are 1 101 154 packed) — which is why the further cut below is taken.

Round-trip is exact: `unpack( pack( x ) ) === x` verified for both the sample lists and `token_counts` on the real Cyrillic unit, so verdicts, probabilities and similarity scores are unchanged for every script that has a unit.

Warm restore cost, Cyrillic unit, 10 runs: unpacking `token_counts` costs **+0.40 ms** over a plain `unserialize()` (0.97 ms vs 0.57 ms) and unpacking the sample lists **+0.03 ms** (0.60 ms vs 0.57 ms). A parallel-strings variant (`tokens`/`counts` restored with `array_combine`) measured 0.91 ms — 0.06 ms better for a more complex shape and an 84-byte-larger unit, so the simpler interleaved form is taken. Expect the site stack's warm figure to move from 2.44 ms to roughly 2.9 ms; that is the honest cost of the change and must be recorded, not hidden.

### Accepted deviation: the combined similarity corpus

Per the ticket's Q&A (answer recorded in the progress log), the combined unit keeps **one** transient holding the combined classifier plus **only** the legacy and `unknown`-script sample lists as its similarity corpus (~766 KB). The ten per-script lists are neither stored in it nor composed at read time.

Rationale: `ASW_Similarity_Check::resolve_from_store()` consults the combined corpus whenever the detected script's own list is empty, and on the shipped corpus every named script has a list — so what it serves is every body `ASW_Script_Detector::detect()` routes to `unknown`, which is three cases and not only the first: a body with **no letters at all** (a bare phone number, say), a body written wholly in a script outside the ten `SCRIPT_GROUPS` (Armenian, Georgian, Khmer, Lao …), and a body whose detection hit a PCRE limit — plus a named script on an install whose list came out empty; that list is built from the script's *spam* samples alone, so a populated ham corpus does not fill it: its spam corpus file missing, unreadable or empty with no learned spam samples under it, or every spam sample of it tokenising to nothing under the site's excluded tokens. How far the score moves depends on which of those routes brought the body here, and the four are **not** one case. **Small** for two of them: cosine similarity of a **letter-less** body against word samples is near zero except through the tokens it can share with them — chiefly the phone marker and bare numerals — and that marker is already the strongest single feature of the combined Bayesian classifier, which stays trained on every script's samples and cached in full; and for a **named script whose own list came out empty** the same argument holds one step removed, since its own samples are precisely the ones the fallback found missing, so they were absent from the monolithic combined corpus as well, and what the body loses is the *other* nine scripts' lists — other writing systems, low cosine against it except through those same shared tokens (phone marker, numerals, embedded Latin). Neither is bounded at zero: a body whose only matches were shared tokens scores lower than it did and can fall below the threshold it used to clear.

**Potentially substantial** for the other two, recorded as such rather than folded into the argument above. An **unsupported-script** body matches the ten lists near zero as far as the shipped corpus *files* go — but a learned sample is filed under its comment's *dominant* script and `ASW_Tokenizer::tokenize_for_script()` chooses a tokenisation strategy without filtering tokens by script, so a mostly-Latin comment quoting Armenian is stored in the `latin` list carrying its Armenian tokens; "the per-script lists hold no sample in its writing system" is therefore a property of the files, not a guarantee about the lists, and where it fails the removed list could have matched such a body at full strength. A body routed here by a **PCRE failure** in `ASW_Script_Detector::detect()` is the worse case: it is ordinary text in one of the ten scripts, and the list the combined unit no longer carries is precisely the one that would have matched it — a strong match before this change and near zero after, for any of the eight word-tokenised scripts (a CJK or Thai body loses little, since `unknown` word-tokenises it where those two lists hold bigrams). Both routes keep the combined classifier, which trains on every script's samples and runs ahead of the Similarity check, so the Bayesian verdict is untouched; the Similarity verdict for them can be lost outright, not merely shifted.

This **is** a deliberate verdict change for every body that falls back to the combined corpus — on any normal install every body detection routes to `unknown` (letter-less, written in an unsupported script, or detected through a PCRE failure), and a named script whose own list came out empty — and is stated as an accepted deviation from the ticket's byte-identical rule rather than glossed over. It is pinned by a test asserting that an `unknown`-script body's similarity corpus is exactly legacy + `unknown`-script rows, and that a Cyrillic/Latin body's corpus, probabilities and scores are unchanged; the named-script fallback is pinned at the check level by `SimilarityCheckTest::test_store_backed_check_falls_back_to_the_combined_unit_for_a_script_without_samples` and `…test_the_combined_unit_scores_a_fallback_body_against_the_legacy_corpus`, whose fixture gives Latin no corpus at all.

`combined_has_script_samples()` keeps answering from whether any per-script sample list *would have been* non-empty — it is computed at build time from the loaded per-script samples exactly as today (short-circuiting at the first non-empty tokenisation) and stored as the boolean it already is, so it cannot flip to false merely because the lists are no longer stored. That boolean decides the Similarity check's legacy `tokenize()` mode, so a flip would change tokenisation for every fallback body.

## Context

- Modify: `antispam-wizard/includes/class-model-store.php` — the two suppressions, `export_script_unit()`, `export_combined_unit()`, `restore_script_unit()`, `restore_combined_unit()`, `valid_sample_tokens()`, `build_combined_unit()`; new private pack/unpack helpers.
- Modify: `tests/ModelStoreTest.php` — new coverage for the packed representation and the narrowed combined corpus; existing tests that seed a *restorable* cached unit move to the packed shape (tests that seed corrupt or unrecognised values stay as they are and now also cover "old shape found in cache").
- Modify: `tests/PluginMetadataTest.php` — a source-text guard that a `phpcs:ignore` naming exactly `WordPress.DB.PreparedSQL.NotPrepared` is anchored on the single-line statement it covers.
- Modify: `CLAUDE.md`, `docs/plans/completed/20260913-load-one-writing-systems-models-per-submission.md`.
- Unchanged: `ASW_Naive_Bayes` (the store unpacks before calling `set_state()`, so the accepted state shape is exactly today's), `ASW_Tokenizer`, `ASW_Similarity_Check`, `ASW_Bayesian_Check`, `ASW_Privacy`, `uninstall.php`, `tests/UninstallTest.php`, `tests/CorpusQualityTest.php`, `site/checks/bench.php` (it already flags by exact byte count).
- Related patterns: the prepare-into-a-variable + bare-query shape used throughout `antispam-wizard/includes/class-sample-repository.php`; the source-text invariant tests in `tests/PluginMetadataTest.php`.
- Delimiter safety: `ASW_Tokenizer` splits on `/[^\p{L}\p{N}\p{M}]+/u` (word path) and strips the same class before emitting bigrams (n-gram path), so no token can ever contain a space. `explode( ' ', … )` is therefore an exact inverse of `implode( ' ', … )`.
- `token_counts` keys that look numeric are cast to `int` by PHP both before packing and after unpacking, so the round trip is identity; `implode()` casts them back to strings on the way out.

## Development Approach

- **Testing approach**: TDD — write the failing test against the stub bootstrap first, then the implementation.
- Tests run with `docker compose run --rm test`.
- Complete each task fully before moving to the next.
- **CRITICAL: every task MUST include new/updated tests**
- **CRITICAL: all tests must pass before starting the next task**
- Never `docker compose down` the persistent stacks (`asw-site`, `antispam-wizard_*`); never push, open a PR or merge.

## Implementation Steps

### Task 1: Plugin Check green

**Files:**
- Modify: `antispam-wizard/includes/class-model-store.php`
- Modify: `tests/PluginMetadataTest.php`

- [x] run `bash dev/plugin-check.sh`, record the exact rows it reports — baseline was exactly the two predicted: `includes/class-model-store.php,506,17,ERROR,WordPress.DB.PreparedSQL.NotPrepared,"Use placeholders and $wpdb->prepare(); found $sql"` (the `$sql,` argument line inside `delete_unit_rows()`'s multi-line query) and `includes/class-model-store.php,428,26,WARNING,PluginCheck.Security.DirectDB.UnescapedDBParameter,"Unescaped parameter $sql used in $wpdb->query()\n$sql assigned unsafely at line 424."` (`delete_stray_rows()`) — 1 error, 1 warning, exit 1
- [x] in `delete_unit_rows()`, assign `$wpdb->prepare( … )` to a `$prepared` variable and leave the `phpcs:ignore` directly above a **single-line** `$wpdb->query( $prepared );`, reason text kept and extended with why the call must stay one line. Two further steps were needed, and the harness was re-run after each: hoisting the prepare alone moved the finding from the query line to the `$sql,` argument line, because `NotPrepared` reports on a **variable** handed to `prepare()`, so the query string is now a **literal** passed straight to `prepare()` with the table name bound through the `%i` identifier placeholder instead of `"{$wpdb->options}"` interpolation. With that, the only remaining report is on `$wpdb->query( $prepared );` itself, which the previous-line suppression does cover. (`%i` is WP 6.2+; the floor is 6.4, and the `$wpdb` stub already models `%i` as a backtick-quoted identifier, so the rendered SQL is byte-identical.)
- [x] in `delete_stray_rows()`, added `PluginCheck.Security.DirectDB.UnescapedDBParameter` to the existing `phpcs:ignore` sniff list with its own clause in the reason. No re-anchoring was needed: the harness reports it on the `$result = $wpdb->query( $wpdb->prepare( $sql, ...$arguments ) );` line itself, which is already the single line the suppression covers
- [x] no blanket `phpcs:disable`; no sniff added that the harness does not report (`UnescapedDBParameter` was in the baseline rows, and `NotPrepared` is still reported on `delete_unit_rows()`'s query line — removing it from the list was tried and the run went red)
- [x] write the test: in `tests/PluginMetadataTest.php`, assert that in every shipped PHP file a `phpcs:ignore` line naming the **exact** sniff `WordPress.DB.PreparedSQL.NotPrepared` (matched so that `PreparedSQL.InterpolatedNotPrepared` and `PreparedSQLPlaceholders.*` are *not* matched — that sniff reports on the `$sql` variable at the query call, while the others report on an argument line and are correctly anchored above one) is followed by a next non-blank line that is a single-line statement ending in `;`, since a previous-line ignore covers exactly one line
- [x] verify the guard against master before the fix (done — it failed on exactly one site, `includes/class-model-store.php:503`, and passed over all four `class-sample-repository.php` suppressions): it must fail on exactly one site, `delete_unit_rows()` in `class-model-store.php`, and pass on every other suppression — including the four in `class-sample-repository.php` (`PreparedSQL.InterpolatedNotPrepared` at ~451/~497 above a string argument line ending in a comma, and `PreparedSQLPlaceholders.ReplacementsWrongNumber` at ~442/~495 above a multi-line `$sql = $this->wpdb->prepare(`), which the exact-name match must leave alone. Do not extend the guard to the other `PreparedSQL` sniffs.
- [x] run `docker compose run --rm test` — passes (1222 tests, 22587 assertions)
- [x] run `bash dev/plugin-check.sh` — reports 0 errors, 0 warnings, exit 0

### Task 2: Cache each sample's token list as one delimited string

**Files:**
- Modify: `antispam-wizard/includes/class-model-store.php`
- Modify: `tests/ModelStoreTest.php`

- [x] write the failing tests first in `tests/ModelStoreTest.php`: (a) a unit the store writes stores `samples` as a list of space-joined strings, not as nested arrays; (b) a write-then-read round trip returns token lists identical to the freshly built ones (`script_sample_tokens()` and `combined_sample_tokens()`); (c) a cached unit carrying the **old** nested-array shape is rebuilt, never served; (d) a member that is not a string, (e) an empty string member, and (f) a packed string containing an empty segment (a double space) each invalidate the unit and force a rebuild; (g) an empty outer array is still valid and still restores as a script with no samples
- [x] add a private `pack_sample_tokens( array $samples ): array` — `implode( ' ', $tokens )` per list — and call it from `export_script_unit()` and `export_combined_unit()`
- [x] replace `valid_sample_tokens()` with the unpacking validator (now `unpack_sample_tokens()`): reject the unit (return null) when a member is not a string, is `''`, or explodes to any empty segment; otherwise return the exploded token lists. Keep the docblock's reasoning about why a malformed list invalidates the whole unit rather than being filtered out of it, and update it to the new representation and the "old shape is simply an unrecognised shape" rule
- [x] update the existing `ModelStoreTest` cases that seed a *restorable* cached unit to the packed shape; leave the corrupt/unrecognised-value cases as they are
- [x] confirm `holds_training_text()`, `BLANKED_UNIT` and the erasure ladder need no change (a packed `samples` list is still a non-empty array, an empty one still empty) and add an assertion to the purge tests that a packed unit is recognised as holding text
- [x] run `docker compose run --rm test` — passes (1230 tests, 23027 assertions)

### Task 3: Cache each classifier category's token counts as one interleaved string

**Files:**
- Modify: `antispam-wizard/includes/class-model-store.php`
- Modify: `tests/ModelStoreTest.php`

- [x] write the failing tests first: (a) a unit the store writes stores `classifier['token_counts'][ $category ]` as one `"token count token count …"` string while `token_totals` and `doc_counts` stay native arrays; (b) a classifier restored from a packed cached unit returns a `get_state()` identical to the freshly built one **and** identical `classify()` probabilities for a fixed body; (c) a cached unit whose `token_counts` carries the **old** nested-array shape is rebuilt; (d) an odd number of segments, (e) a non-integer count segment, and (f) a non-string category member each invalidate the unit; (g) a category with an empty counts string restores as an empty map, and (h) `classifier => null` (a script with no samples at all) still caches as null and still restores
- [x] add private `pack_classifier_state( array $state ): array` and `unpack_classifier_state( array $state ): ?array`; the unpacker returns null on any of the malformed cases above, and treats `''` as the empty map (`explode( ' ', '' )` would otherwise yield one empty segment)
- [x] call the packer from `export_script_unit()`/`export_combined_unit()` and the unpacker from `restore_script_unit()`/`restore_combined_unit()` **before** `set_state()`, so `ASW_Naive_Bayes` is untouched and the state it accepts is exactly today's; a null from the unpacker makes the restore return null, i.e. the unit is deleted and rebuilt like any other unusable value
- [x] keep the existing per-unit `doc_counts` rules unchanged (one category is enough for a per-script unit, both are required for the combined one)
- [x] run `docker compose run --rm test` — passes (1237 tests, 23074 assertions)

### Task 4: Narrow the combined unit's similarity corpus to legacy + `unknown`-script rows

**Files:**
- Modify: `antispam-wizard/includes/class-model-store.php`
- Modify: `tests/ModelStoreTest.php`

- [x] write the failing tests first: (a) `combined_sample_tokens()` returns exactly the legacy-file lists plus the repository's `unknown`-script spam rows, and no per-script list; (b) `combined_has_script_samples()` is still true when a script has spam samples even though its lists are no longer stored, and false when no script has any; (c) the combined classifier's `classify()` output is unchanged (it still trains on every script's samples); (d) a Cyrillic body's similarity corpus and score are unchanged — it never touches the combined unit. Added as four tests plus an `ASW_Legacy_And_Script_Training_Data` fixture (a distinct legacy corpus, so the narrowed corpus is distinguishable from the per-script lists it no longer carries); (a) and (b) failed against master's builder, (c) and (d) passed from the start and are the regression guards. (c) is asserted as state identity against a classifier trained directly over legacy + every script + the `unknown` rows, which is stronger than comparing one `classify()` output
- [x] in `build_combined_unit()`, build `samples` from the legacy samples and the `unknown`-script rows only, and derive `has_script_samples` by tokenising per-script samples until the first non-empty result and stopping there (new private `any_script_sample_tokenises()`)
- [x] leave the combined classifier's training set and its sample order untouched (legacy, then each script in `SCRIPT_GROUPS` order, then the `unknown` rows), so Bayesian verdicts stay byte-identical
- [x] document the accepted verdict deviation in the method's docblock: which bodies reach this corpus and how far the score moves **per route** — small for a letter-less body and for a named script whose own list came out empty (shared tokens such as the phone marker and numerals still move the score, so not nil), potentially substantial for an unsupported-script body (a learned sample is filed under its comment's dominant script and is not token-filtered, so a per-script list *can* carry that writing system) and for one routed to `unknown` by a PCRE failure (ordinary text whose own list is exactly the one the combined unit no longer carries) — and that the combined classifier still carries the phone marker and every script's samples
- [x] run `docker compose run --rm test` — passes (1241 tests, 22568 assertions). Four existing combined-unit tests moved onto the new fixture: with no legacy corpus and no `unknown` rows the combined corpus is now legitimately empty, which would have made their round-trip and rebuild assertions vacuous rather than red. The suite's assertion count falls from 23074 to 22568 for the same reason — the per-token loops in those tests now walk one or two lists instead of twenty-one

### Task 5: Verify acceptance criteria

**Files:**
- No production changes expected; fix anything the harnesses report.

- [x] `docker compose run --rm test` — full suite green: 1241 tests, 22568 assertions, OK. `CorpusQualityTest`, `tests/UninstallTest.php`, `ScriptRoutingIntegrationTest`, `IntegrationTest`, `BayesianCheckTest` and `SimilarityCheckTest` are unchanged on this branch (`git diff master --stat` touches none of them) and green
- [x] `bash dev/plugin-check.sh` — `Success: Checks complete. No errors found.`, `0 errors, 0 warnings`, exit 0 on `wordpress:7.1-php8.3-apache`
- [x] `bash dev/smoke.sh` — SMOKE PASS on `wordpress:7.1-php8.3-apache`: debug.log clean of plugin problems, 6/6 admin pages rendered, comment and CF7 spam/ham verdicts correct, samples table matches `DB_VERSION`
- [x] `bash site/verify.sh` — SITE VERIFY PASS against the already-running `asw-site` stack (not rebuilt, never `down`ed): live detection 5/5, waitlist end to end, debug.log clean
- [x] run `site/checks/bench.php` on the `asw-site` stack — no unit flagged. The benchmark builds only the two units its own bodies reach (cyrillic 656 466 B, combined 765 095 B), so every unit was then built in one pass to record the full set; all eleven are under 1 048 576 B, the largest being combined at **765 095 B (73% of the limit)** and cyrillic at **656 466 B (63%)**:

  | unit | bytes | vs. 1 048 576 |
  |---|---|---|
  | combined | 765 095 | 73.0% |
  | cyrillic | 656 466 | 62.6% |
  | latin | 350 735 | 33.4% |
  | thai | 169 233 | 16.1% |
  | cjk | 151 517 | 14.5% |
  | indic | 127 673 | 12.2% |
  | bengali | 91 847 | 8.8% |
  | devanagari | 83 011 | 7.9% |
  | greek | 73 550 | 7.0% |
  | arabic | 62 197 | 5.9% |
  | hebrew | 54 729 | 5.2% |

  Total for all eleven: 2 586 053 B — itself smaller than the single 2 455 017-byte monolithic transient plus the Cyrillic unit alone. The figures sit 1–5% under the container predictions in the Overview table (combined 766 696 → 765 095; cyrillic 665 073 → 656 466; latin 369 570 → 350 735), as expected for the site stack.
- [x] record the warm and cold figures — 5 runs per case, `peak alloc` the comparable column:

  | case | merged | now | change |
  |---|---|---|---|
  | cold `build_checks()` | 0.01 ms / 0.03 MB | 0.03 ms / 0.03 MB | unchanged (it loads nothing) |
  | warm `build_checks()` | 0.01 ms / 0.03 MB | 0.01 ms / 0.03 MB | unchanged |
  | warm + one `evaluate()` | 2.44 ms / 4.80 MB | **2.68 ms / 4.72 MB** | **+0.24 ms (+9.8%)**, −0.08 MB |
  | one-script cold + `evaluate()` | 37.58 ms / 10.84 MB | **32.18 ms / 8.99 MB** | −5.40 ms (−14.4%), −1.85 MB |
  | `unknown`-script cold (combined unit) | 190.41 ms / 31.53 MB | **97.84 ms / 12.31 MB** | −92.57 ms (−48.6%), −19.22 MB |

  The warm path is the regression, and it is reported as such: **+0.24 ms per submission**, the unpack cost of the packed `token_counts` and sample lists, close to the ≈2.9 ms the container measurement predicted. Everything else improves; the `unknown`-script cold path is 1.95x faster on 2.6x less memory, which is the narrowed combined corpus plus the packed state. Verdicts from the same run are unchanged (`bayesian` spam for the Cyrillic body, not spam for the letter-less one)
- [x] confirm the core deprecation no longer appears — `debug.log` truncated to 0 bytes, all eleven units flushed, then one Cyrillic and one `unknown`-script `evaluate()` driven (both wrote their unit; `units written: 2`). `debug.log` is **0 bytes** afterwards: no `preg_replace(): Passing null to parameter #3` from `wp-includes/class-wpdb.php`, and nothing else. The packed units are small enough that `wpdb::get_table_from_query()` no longer overruns PCRE's backtrack limit on the `INSERT`

### Task 6: Update documentation

**Files:**
- Modify: `CLAUDE.md`
- Modify: `docs/plans/completed/20260913-load-one-writing-systems-models-per-submission.md`

- [x] `CLAUDE.md`, `ASW_Model_Store` bullet and the Data storage section: the cached representation (each sample one space-delimited string; each classifier category's `token_counts` one interleaved `token count` string; `token_totals`/`doc_counts` native), why a space is a safe delimiter (the tokenizer's character classes), that `ASW_Naive_Bayes::set_state()` is unchanged because the store unpacks first, that a unit in the pre-change shape is simply an unrecognised value and is rebuilt, and that the malformed-sample validation now rejects a non-string member, an empty string and an empty segment
- [x] `CLAUDE.md`: the size budget — every unit's measured size against the 1 048 576-byte limit, that the per-script units are independent so another script's corpus growth does not enlarge them, and that the **combined unit's classifier state is the growth axis** (757 733 packed today out of its 766 696 total), so the next corpus ticket must re-measure it
- [x] `CLAUDE.md`: the combined similarity corpus is now legacy + `unknown`-script rows only, which bodies reach it, the accepted verdict deviation for every body that falls back to it (on any normal install every body routed to `unknown` — letter-less, an unsupported script, or a PCRE failure — and a named script whose own list came out empty), and that `combined_has_script_samples()` is still computed from the loaded per-script samples
- [x] `docs/plans/completed/20260913-…md`: correct the Task 7 `bash dev/plugin-check.sh` checkbox to state what it actually measured — green when it was run, red after the later review-fix commits introduced the two findings — and note that this ticket restores it
- [x] `docs/plans/completed/20260913-…md`: add a Deviations section with one paragraph each (what was added, the finding that prompted it, where it is tested) for: the page-1 model-cache purge in `ASW_Privacy::erase()`; the `wp_options` prefix sweep by `DELETE … LIKE` for rows no key can name; the blank-then-delete-rows ladder for a unit whose transient delete was refused; the post-write fingerprint re-read that drops a unit written across an erasure; the `find_all()` failure flag that keeps a unit built through a failed sample read out of the cache; and the whole-table `COUNT(*)/MAX(id)` component of the combined fingerprint
- [x] `docs/plans/completed/20260913-…md`: mark the "two units are over 1 MB" open finding as closed by this plan, with a pointer to it and the achieved sizes
- [x] fill in this plan's Measurements section with the Task 5 figures
- [x] run `docker compose run --rm test` — passes (1241 tests, 22568 assertions, OK); the i18n and metadata guards ran with it

## Post-Completion (manual)

- Push the branch and open a PR only when asked; confirm the GitHub Actions Plugin Check job is green on the merge.
- The next ticket (growing the other eight scripts' corpora) is unblocked; it must re-measure the combined unit, whose classifier state is the one component that grows with every corpus.

## Measurements

Recorded from the Task 5 run on the `asw-site` stack (`wordpress:7.1-php8.3-apache`, PHP 8.3) with `site/checks/bench.php`, 5 runs per case, 285-character Cyrillic spam body and a letter-less (`unknown`-script) body. Verdicts from the same run are unchanged: `bayesian` spam for the Cyrillic body, not spam for the letter-less one.

### Unit sizes, against the 1 048 576-byte object-cache item limit

Every unit is under the bound. The benchmark builds only the two units its own bodies reach (cyrillic, combined), so all eleven were then built in one pass to record the full set.

| unit | bytes | vs. 1 048 576 | was (merged) |
|---|---|---|---|
| combined | 765 095 | 73.0% | 3 284 015 (313%) |
| cyrillic | 656 466 | 62.6% | 1 050 388 (100.2%) |
| latin | 350 735 | 33.4% | 766 620 |
| thai | 169 233 | 16.1% | 411 998 |
| cjk | 151 517 | 14.5% | 327 547 |
| indic | 127 673 | 12.2% | 181 836 |
| bengali | 91 847 | 8.8% | 138 809 |
| devanagari | 83 011 | 7.9% | 130 429 |
| greek | 73 550 | 7.0% | 118 753 |
| arabic | 62 197 | 5.9% | 107 261 |
| hebrew | 54 729 | 5.2% | 94 635 |

Total for all eleven: **2 586 053 bytes** — itself smaller than the single 2 455 017-byte monolithic transient plus the Cyrillic unit alone. The site-stack figures sit 1–5% under the container predictions in the Overview table (combined 766 696 → 765 095; cyrillic 665 073 → 656 466; latin 369 570 → 350 735), as expected.

The remaining growth axis is the **combined unit's classifier state** (757 733 of its 766 696 packed bytes), because it trains on every script's samples; the per-script units are independent, so another script's corpus growth cannot enlarge them. The next corpus ticket must re-measure the combined unit.

### Warm and cold figures (`peak alloc` is the comparable column)

| case | merged | now | change |
|---|---|---|---|
| cold `build_checks()` | 0.01 ms / 0.03 MB | 0.03 ms / 0.03 MB | unchanged (it loads nothing) |
| warm `build_checks()` | 0.01 ms / 0.03 MB | 0.01 ms / 0.03 MB | unchanged |
| warm + one `evaluate()` | 2.44 ms / 4.80 MB | **2.68 ms / 4.72 MB** | **+0.24 ms (+9.8%)**, −0.08 MB |
| one-script cold + `evaluate()` | 37.58 ms / 10.84 MB | **32.18 ms / 8.99 MB** | −5.40 ms (−14.4%), −1.85 MB |
| `unknown`-script cold (combined unit) | 190.41 ms / 31.53 MB | **97.84 ms / 12.31 MB** | −92.57 ms (−48.6%), −19.22 MB |

The warm path is the one regression and is reported as such: **+0.24 ms per submission**, the unpack cost of the packed `token_counts` and sample lists, close to the ≈2.9 ms the container measurement predicted. Everything else improves; the `unknown`-script cold path is 1.95x faster on 2.6x less memory, which is the narrowed combined corpus plus the packed state.

### Core deprecation

Gone. With `debug.log` truncated to 0 bytes and all eleven units flushed, one Cyrillic and one `unknown`-script `evaluate()` were driven — both wrote their unit (`units written: 2`) — and `debug.log` was **0 bytes** afterwards: no `preg_replace(): Passing null to parameter #3 ($subject) is deprecated` from `wp-includes/class-wpdb.php`, and nothing else. The packed units are small enough that `wpdb::get_table_from_query()` no longer overruns PCRE's backtrack limit on the `INSERT`.

### Open finding: learned samples can grow a unit back over the limit

**Closed** by `docs/plans/20260914-bound-every-cached-model-unit-under-the-object-cache-item-limit.md`:
`ASW_Model_Store::LEARNED_SIMILARITY_BUDGET = 131072` bounds the learned half of
every similarity corpus, and under an external object cache the stored value is
compressed into the `zlib` envelope. Neither of those bounds the classifier's
*bytes* — `MAX_DICTIONARY_SIZE` caps the token count, not the token length, and
a full dictionary of 10-character CJK tokens reaches 2 210 916 compressed bytes,
211% of the limit on the classifier alone — so the third measure is
`MAX_CACHED_UNIT_BYTES` with `storable_unit()`, which weighs the value it is
about to write and drops the least frequent half of each category's vocabulary
until the cache can hold it, handing the pruned model back to the live
classifier. Between the three the bound now holds by construction as the site
learns rather than by one-off measurement; the reachable worst case is pinned by
`CorpusQualityTest::test_a_dictionary_of_long_tokens_is_pruned_until_the_unit_fits()`.
`test_the_shipped_corpus_leaves_headroom_for_learned_samples()` — cited below as
the standing guard — was replaced by
`CorpusQualityTest::test_an_unbounded_learning_history_still_fits_every_unit()`
(1 300 learned Cyrillic samples of entirely novel vocabulary: cyrillic 533 375 B
stored / 50.9%, combined 469 322 / 44.8%) and
`…test_a_full_dictionary_worst_case_unit_fits_the_object_cache_item_limit()`
(874 205 B / 83.4%). Everything below is the pre-fix measurement, kept as the
record of why the budget exists.

Reported rather than silently accepted, and **not fixed by this plan** — the fix
is a design change this plan put out of scope (see below).

Every unit is the shipped corpus **plus** every training sample the site has
added, and nothing bounds the second half: `ASW_Training_Data::load_script_samples()`
merges `ASW_Sample_Repository::find_all()` into the script's corpus lines, so a
script's learned samples grow that script's unit and the combined unit trains on
all of them. The size guard added by this plan builds the store with **no**
repository, so it measures the shipped corpus alone and cannot see this.

Measured in the test container, shipped corpus plus N learned Cyrillic `spam`
samples of 40 words each whose vocabulary is entirely new — pessimistic **per
token** (a real learned comment reuses the corpus's tokens and the other
samples'), and the heavier of the two categories, since a spam sample enters the
script unit's similarity corpus as well as its classifier where a ham one enters
only the classifier:

| learned samples | combined | cyrillic |
|---|---|---|
| 0 | 765 095 (73.0%) | 657 004 (62.7%) |
| 200 | 900 784 (85.9%) | 915 380 (87.3%) |
| 300 | 968 818 (92.4%) | 1 044 948 (99.7%) |
| 400 | 1 036 448 (98.8%) | **1 173 708 (111.9%)** |

So the shipped corpus leaves room for roughly 300 such samples before the
*per-script* unit crosses first — which also qualifies the "per-script units are
independent" note above: independent of *another script's* corpus, not of their
own learned samples.

That number is **not** a comment count, and nothing here is a worst case in the
absolute sense. A sample is whatever `ASW_Moderation::train()` normalised out of
the moderated comment, bounded only by `ASW_Detector::MAX_COMMENT_LENGTH`
(65 536 characters), so what fills a unit is the volume of novel tokens rather
than the number of moderation decisions: at 100 words a sample, 200 of them
already put cyrillic at 1 299 512 B and combined at 1 104 750 B. A site whose
moderated spam runs long therefore exhausts the headroom in far fewer than 300
decisions. The 40-word row is a fixed measurement shape, chosen so a corpus edit
that eats the headroom is visible as a regression; it is not a bound on what a
site can store.

Impact when a unit does cross, on a site running a persistent object cache:
`set_transient()` is refused silently (its return value is ignored, and there is
nothing useful to do with it — without an external object cache the transient is
a `longtext` option row with no such limit), the next read misses, and every
submission routed to that unit pays a cold rebuild. Degradation, not breakage,
and confined to that one unit.

Held here rather than patched because every available fix changes behaviour or
layout: capping the learned samples a unit trains on changes verdicts, and
splitting a unit across several transients is explicitly out of scope below (it
moves the key list `uninstall.php`, `ASW_Privacy::erase()` and
`ASW_Model_Store::delete_units()` all depend on). What this plan does instead is
make the limit a measured, documented number rather than an unknown:
`CorpusQualityTest::test_the_shipped_corpus_leaves_headroom_for_learned_samples()`
pins that the shipped corpus plus 200 such learned samples still fits every
unit — asserting first that those samples actually reached the units, and that
every one of their 8 000 tokens is unique and absent from the shipped corpus
(so "pessimistic per token" is enforced rather than assumed, and cannot decay
as the corpus grows into the space a random Cyrillic word is drawn from), so a
corpus edit that eats the headroom goes red — and the user-facing claim in
`readme.txt` no longer says every model is unconditionally under the limit.

## Out of scope

- Growing the other eight scripts' corpora.
- Changing tokenisation, the classifier, thresholds, invalidation inputs or the erasure purge behaviour.
- Splitting the combined unit into more than one transient, or giving `unknown` a unit of its own.
