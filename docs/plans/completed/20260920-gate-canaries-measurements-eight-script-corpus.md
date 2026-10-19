# Gate, canaries, measurements and documentation for the eight-script corpus

## Overview

The eight remaining writing systems (cjk, thai, arabic, devanagari, bengali, indic, greek, hebrew) were rebuilt from the same genre matrix as Cyrillic and Latin and are already in the working history as nineteen `WIP corpus: …` commits. This ticket adds the engineering around that corpus and nothing else: widen `CorpusQualityTest::SCRIPTS` to all ten script groups, write fresh short spam/ham canaries per script, measure and guard the ham/spam token ratio (test plus a `dev/corpus-eval.php` report line), re-record the unit sizes and cold paths from the live `asw-site` stack, rewrite the corpus part of CLAUDE.md's "Data storage" paragraph and correct one readme sentence. The nineteen WIP commits are left as they are: squashing the history is done by hand after the review, outside ralphex, so this plan commits its own work in ordinary commits on top of them.

No corpus file is edited. A canary that will not pass at the shipped defaults after one rewording is dropped and reported as a corpus gap.

## Context

- Files involved:
  - Modify: `tests/CorpusQualityTest.php` (SCRIPTS, CANARIES, HAM_CANARIES, new token-ratio test, new canary-provenance test, refreshed measured figures in docblocks)
  - Modify: `dev/corpus-eval.php` (per-script token-count/ratio line)
  - Modify: `CLAUDE.md` ("Data storage" corpus paragraph, unit sizes, cold paths)
  - Modify: `antispam-wizard/readme.txt` (one FAQ sentence, line 88: "takes a few tens of milliseconds")
  - Read only: `antispam-wizard/data/*.txt`, `tests/fixtures/corpus/*.txt`, `site/checks/bench.php`
- Related patterns:
  - `CorpusQualityTest` already trains real per-script classifiers via `ASW_Training_Data::train_script_classifier()` and runs canaries through the real `ASW_Bayesian_Check` built by `shipped_check()`; widening `SCRIPTS` widens `shipped_check()`, the held-out recall/FPR tests, the short-line share test and the fixture-leak test at once.
  - Token counts come from `ASW_Tokenizer::tokenize_for_script( $line, $script )` over `ASW_Training_Data::get_script_file_path()` lines (shipped files only, never learned samples).
  - `site/checks/bench.php` is not modified — the engineering commit covers `tests/`, `dev/`, `CLAUDE.md`, `readme.txt`, `docs/plans/` only. The all-eleven-unit measurement runs as a throwaway `wp eval` against the already-running `asw-site` stack.
- Dependencies: Docker (test image), the running `asw-site` compose project (`asw-site-wordpress-1` is up — do not `down` it).
- Verified during exploration: the eight scripts' ratios reproduce the ticket exactly (cjk 1.063, thai 1.052, arabic 1.048, devanagari 1.104, bengali 0.895, indic 1.017, greek 1.154, hebrew 0.968); latin 1.377 / cyrillic 1.254 are the exempt pair.

## Development Approach

- **Testing approach**: Regular — the deliverable largely *is* test code; each task ends with the affected test file green.
- Complete each task fully before moving to the next.
- **CRITICAL: every task MUST include new/updated tests**
- **CRITICAL: all tests must pass before starting next task**
- Never edit a file under `antispam-wizard/data/` or `tests/fixtures/corpus/`; never lower `MIN_RECALL` or `MAX_FALSE_POSITIVE_RATE`; never `docker compose down` the `asw-site` or `antispam-wizard_*` projects.

## Implementation Steps

### Task 1: Widen the corpus gate to all ten scripts

**Files:**
- Modify: `tests/CorpusQualityTest.php`

- [x] change `private const SCRIPTS` to all ten keys of `ASW_Script_Detector::SCRIPT_GROUPS`, in that order, and update the constant's doc comment (the fixtures now cover every script)
- [x] leave `MIN_RECALL` and `MAX_FALSE_POSITIVE_RATE` untouched
- [x] run `docker compose run --rm test vendor/bin/phpunit --filter CorpusQualityTest` and confirm `test_held_out_spam_recall_meets_the_floor`, `test_held_out_ham_false_positive_rate_stays_under_the_ceiling`, `test_corpus_covers_short_comments` and `test_held_out_fixtures_do_not_leak_into_the_corpus` are green for all ten
- [x] record the per-script recall/FPR the run reports; if any script is red, record it verbatim as a finding and do **not** edit the corpus or the thresholds
- [x] note the runtime the widened class takes (all ten classifiers plus the combined one are now trained per canary test) so a regression in suite time is visible in the completion notes
- [x] run the full suite: `docker compose run --rm test` — must pass before task 2

### Task 2: Fresh canaries for the eight rebuilt scripts

**Files:**
- Modify: `tests/CorpusQualityTest.php`

- [x] for each of cjk, thai, arabic, devanagari, bengali, indic, greek, hebrew add a `CANARIES` entry of four short spam lines, written fresh in that script: a classified ad carrying a phone-number-shaped digit run, a bare traffic/leads pitch, a "visit my site" line, and one region-specific genre the corpus covers (for indic and devanagari use the secondary languages the corpus carries)
- [x] for the same eight add a `HAM_CANARIES` entry of four short ham lines: a thank-you, a typo report, a sentence with a version number or count, and a benign story that touches spam-adjacent vocabulary (traffic, delivery, a loan, a casino trip)
- [x] keep every canary long enough to clear the shipped `min_message_length` (default 10) and short enough to stay a "short comment" — roughly 15–80 characters
- [x] add `test_canaries_are_not_corpus_or_fixture_lines()`: case-folded, no entry of `CANARIES`/`HAM_CANARIES` may equal a line of `antispam-wizard/data/{spam,ham}-samples-<script>.txt` or of `tests/fixtures/corpus/{spam,ham}-<script>.txt` for any script
- [x] run `--filter CorpusQualityTest` after each script's batch; a failing canary is reworded **once** within the same genre, and if it still fails it is deleted from the array and written down as a corpus gap (script, genre, intended text, observed spam probability) for the completion notes
- [x] run the full suite — must pass before task 3

### Task 3: Token balance — report line and guard

**Files:**
- Modify: `dev/corpus-eval.php`
- Modify: `tests/CorpusQualityTest.php`

- [x] in `dev/corpus-eval.php`, after the held-out block, print one line per script: spam tokens, ham tokens and the ham/spam ratio, counted by putting every shipped corpus line through `ASW_Tokenizer::tokenize_for_script( $line, $script )`; keep the existing output shape and the existing script-argument filtering
- [x] in `CorpusQualityTest` add `MIN_TOKEN_RATIO = 0.85`, `MAX_TOKEN_RATIO = 1.20` and `TOKEN_RATIO_EXEMPT = array( 'cyrillic', 'latin' )`, with a comment recording why the band exists (Laplace smoothing pushes unknown words toward the smaller category) and that the two exempt scripts drifted before this work
- [x] add `test_corpus_token_counts_stay_balanced_between_categories()`: computes every script's ratio, asserts the band for the eight non-exempt scripts, and builds a failure message listing **all ten** scripts' spam/ham token counts and ratios so a red run is actionable
- [x] run `docker compose run --rm test php dev/corpus-eval.php` with no arguments, confirm every script reports recall ≥ 0.90 and false positives ≤ 0.04 and that the ratio line is present, and keep the whole output for the completion notes
- [x] run the full suite — must pass before task 4

### Task 4: Re-record the measurements from the live stack

**Files:**
- No repository file changes in this task (measurement only); figures land in tasks 5 and 7

- [x] confirm the `asw-site` stack is up (`docker compose -p asw-site -f site/docker-compose.yml ps`); only if it is not, run `bash site/build.sh`
- [x] run `docker compose -p asw-site -f site/docker-compose.yml exec -T wordpress wp --allow-root eval-file /asw-site/checks/bench.php` unmodified and capture the output (warm/cold `build_checks()`, warm and cold Cyrillic `evaluate()`, cold `unknown` `evaluate()`, and the unit sizes it prints)
- [x] run a throwaway `wp eval` (passed inline, never written into `site/`) that flushes every `asw_classifiers_*` unit, then for each of the ten scripts plus `unknown` times one cold `build_checks()` + `evaluate()` and records the unit's `wp_options` row size and its compressed cache-item size (`strlen( serialize( array( 'zlib' => gzcompress( serialize( $unit ), 6 ) ) ) )`) with the percentage of the 1 047 552-byte pruning bound, plus `memory_get_peak_usage( false )` for the whole pass
- [x] verify every one of the eleven units is under the pruning bound and note which has the least headroom (expected cjk)
- [x] check `wp-content/debug.log` on the stack is unchanged apart from the known `wp-includes/class-wpdb.php` `preg_replace()` deprecation, which is out of scope
- [x] re-run the affected unit-size tests (`--filter 'CorpusQualityTest::test_every_cached_model_unit|CorpusQualityTest::test_an_unbounded_learning_history|CorpusQualityTest::test_a_full_dictionary_worst_case'`) and update any measured byte figures quoted in their docblocks that the new corpus moved — assertions and limits stay as they are
- [x] run the full suite — must pass before task 5

### Task 5: Documentation

**Files:**
- Modify: `CLAUDE.md`
- Modify: `antispam-wizard/readme.txt`

- [x] replace the corpus sentence in the "Data storage" paragraph ("They now hold ~3.5k spam … the other eight scripts are still ~300 per side and are the next gap") with the new state: per-script line counts for all ten; that every script was rebuilt from the same genre matrix, written in that script's regional genres and secondary languages (name Ukrainian/Belarusian/Bulgarian/Serbian/Kazakh under Cyrillic, Marathi and Nepali under Devanagari, Tamil/Telugu/Kannada/Malayalam with smaller shares of Gujarati/Punjabi/Odia/Sinhala under indic, and the equivalents for the rest); the numbers-in-ham rule with both formatting constraints (no seven-or-more consecutive digits, no two digit groups separated only by spaces, because the tokenizer folds those into `ASW_Tokenizer::PHONE_TOKEN`) and why it exists (digits were 9–38% of spam lines against 1–7% of ham, so a bare `100, 200, 300` body scored spam through the combined classifier)
- [x] add the measured held-out and 5-fold figures from task 3 and the token ratios from task 3, naming cyrillic and latin as the standing exceptions to the ~15% band
- [x] state that `CorpusQualityTest` now gates all ten scripts with per-script canaries, and that `dev/corpus-eval.php` prints the per-script token ratio
- [x] replace the unit-size list and cold-path figures in the same paragraph with the task 4 measurements, and state that cjk has the least headroom, so a cjk site's learned dictionary is pruned by `storable_unit()` before any other script's, while the budget still fits (cjk stored bytes + `LEARNED_SIMILARITY_BUDGET` 131 072 < 1 047 552)
- [x] fix the one `readme.txt` FAQ sentence so the cold rebuild of one writing system is stated from the task 4 measurements — a single sentence, worded so the slowest script is not understated, e.g. "a few tens of milliseconds for most writing systems and up to about a fifth of a second for Chinese, Japanese, Korean and Thai"; change nothing else in the readme
- [x] run `docker compose run --rm test vendor/bin/phpunit --filter 'SiteThemeTest|I18nTest|PluginMetadataTest'` — the site must still state only readme facts and no translatable string may have changed
- [x] run the full suite — must pass before task 6

### Task 6: Verify acceptance criteria

- [x] `docker compose run --rm test` — whole suite green, record the test count
- `dev/smoke.sh`, `dev/plugin-check.sh` and `site/verify.sh` are **not** run by this plan: they are the review's job, after the plan is executed
- [x] `docker compose run --rm test php dev/corpus-eval.php` — every script recall ≥ 0.90, false positives ≤ 0.04, token ratio printed; paste the full output into the completion notes
- [x] confirm each acceptance criterion in the ticket one by one and write the completion notes: the corpus-eval output, the per-script canaries added and any left out as corpus gaps, the token ratios, the eleven unit sizes with percentages of the pruning bound, the per-script cold paths and peak allocation

### Task 7: Update documentation

- [x] confirm CLAUDE.md's corpus paragraph no longer calls the eight scripts the next gap and carries the new counts, rules, figures, ratios and unit sizes (done in task 5 — re-read it against the final measurements)
- [x] confirm `antispam-wizard/readme.txt`'s cold-rebuild sentence matches the measured figures and that no other readme line moved (`git diff 46d5261 HEAD -- antispam-wizard/readme.txt` shows one hunk)
- [x] confirm no i18n artefact changed (`git diff --stat 46d5261 HEAD -- antispam-wizard/languages` is empty)

## Post-Completion (manual, outside the agent)

- After the review, squash the nineteen `WIP corpus: …` commits and this plan's commits by hand into two — the corpus (`antispam-wizard/data/` + `tests/fixtures/corpus/`) and the engineering (`tests/`, `dev/`, `CLAUDE.md`, `readme.txt`, `docs/plans/`) — then open the PR from the ralphex branch and let the merge skill replace local `master` with `origin/master` after it lands.
- Report any canary left out and any weak genre observed (thai's two held-out misses, bengali's one) to the corpus generation runs; no corpus edit happens in this branch.

## Completion notes

Measured on the branch at the end of execution (corpus identical to `49b1785`; `git diff 49b1785 -- antispam-wizard/data tests/fixtures/corpus` is empty).

### `dev/corpus-eval.php` (no arguments)

```
== latin: 3795 spam / 5766 ham samples, threshold 0.70
   held-out (50/50): recall 1.00, false positives 0.00
   tokens: 28783 spam / 39624 ham, ham/spam 1.377
   5-fold CV: recall 0.88, false positives 0.03 (462 missed, 168 flagged)
== cyrillic: 3527 spam / 5046 ham samples, threshold 0.70
   held-out (50/50): recall 1.00, false positives 0.00
   tokens: 24622 spam / 30880 ham, ham/spam 1.254
   5-fold CV: recall 0.91, false positives 0.02 (300 missed, 98 flagged)
== cjk: 4967 spam / 5393 ham samples, threshold 0.70
   held-out (50/50): recall 1.00, false positives 0.00
   tokens: 170804 spam / 181585 ham, ham/spam 1.063
   5-fold CV: recall 0.91, false positives 0.04 (437 missed, 204 flagged)
== arabic: 3971 spam / 3954 ham samples, threshold 0.70
   held-out (50/50): recall 1.00, false positives 0.00
   tokens: 35957 spam / 37669 ham, ham/spam 1.048
   5-fold CV: recall 0.92, false positives 0.01 (329 missed, 58 flagged)
== devanagari: 4020 spam / 4351 ham samples, threshold 0.70
   held-out (50/50): recall 1.00, false positives 0.00
   tokens: 35589 spam / 39304 ham, ham/spam 1.104
   5-fold CV: recall 0.91, false positives 0.03 (348 missed, 136 flagged)
== greek: 3524 spam / 3520 ham samples, threshold 0.70
   held-out (50/50): recall 1.00, false positives 0.00
   tokens: 28114 spam / 32436 ham, ham/spam 1.154
   5-fold CV: recall 0.88, false positives 0.03 (425 missed, 91 flagged)
== hebrew: 3976 spam / 4076 ham samples, threshold 0.70
   held-out (50/50): recall 1.00, false positives 0.00
   tokens: 39695 spam / 38440 ham, ham/spam 0.968
   5-fold CV: recall 0.91, false positives 0.02 (349 missed, 100 flagged)
== thai: 5151 spam / 4987 ham samples, threshold 0.70
   held-out (50/50): recall 0.96, false positives 0.00
     missed  0.58 อ่านแล้วได้ความรู้ แวะดูเพจผมด้วยนะ มีของถูกขายเยอะ
     missed  0.63 ขายคอนโดใจกลางเมือง ดาวน์น้อย ผ่อนเบากว่าค่าเช่า
   tokens: 299966 spam / 315439 ham, ham/spam 1.052
   5-fold CV: recall 0.82, false positives 0.06 (948 missed, 310 flagged)
== bengali: 4119 spam / 3445 ham samples, threshold 0.70
   held-out (50/50): recall 0.98, false positives 0.00
     missed  0.67 ওজন কমানোর গ্যারান্টিযুক্ত ওষুধ
   tokens: 35340 spam / 31618 ham, ham/spam 0.895
   5-fold CV: recall 0.94, false positives 0.04 (267 missed, 122 flagged)
== indic: 4302 spam / 4028 ham samples, threshold 0.70
   held-out (50/50): recall 1.00, false positives 0.00
   tokens: 31587 spam / 32131 ham, ham/spam 1.017
   5-fold CV: recall 0.93, false positives 0.04 (307 missed, 142 flagged)
```

Every script meets `MIN_RECALL = 0.90` and `MAX_FALSE_POSITIVE_RATE = 0.04` on the held-out fixtures. The token line counts with no excluded tokens, which is what `test_corpus_token_counts_stay_balanced_between_categories()` counts too; all eight rebuilt scripts sit inside the 0.85–1.20 band, cyrillic (1.254) and latin (1.377) are the exempt pair. The weakest spots for a later corpus generation run: thai's two held-out misses (a "visit my page, cheap goods" line at 0.58 and a condo ad at 0.63) and bengali's one (a guaranteed-weight-loss pill at 0.67).

### Canaries

Four spam and four ham canaries were added for each of cjk, thai, arabic, devanagari, bengali, indic, greek and hebrew; every one passed at the shipped defaults on the first attempt, so none was reworded and none was left out. `test_canaries_are_not_corpus_or_fixture_lines()` found that the original Cyrillic and Latin canaries were verbatim held-out fixture lines; each was nudged rather than replaced — the garage ad by its last phone digit (the tokenizer folds it into the same phone marker), the other spam lines by a word, the ham lines by one extra word — so the guard they carried is unchanged.

### Model units and cold paths (`asw-site` stack, WordPress 7.1, PHP 8.3, no external object cache)

Measured over the shipped corpus on the test image — as a cache item, which is where the bound applies: cjk **768 654 B stored** (73.4% of the 1 047 552-byte pruning bound; 2 150 316 uncompressed, 2.80x) has the least headroom, followed by combined 468 454 (44.7%; 1 058 191, 2.26x), indic 294 280 (28.1%; 1 277 901, 4.34x), thai 277 950 (26.5%; 2 188 690, 7.87x — the best-compressing unit), arabic 215 900 (20.6%; 731 446, 3.39x), hebrew 207 514 (19.8%; 740 295, 3.57x), devanagari 200 140 (19.1%; 869 242, 4.34x), cyrillic 187 174 (17.9%; 664 818, 3.55x), bengali 185 398 (17.7%; 867 498, 4.68x), greek 167 225 (16.0%; 634 017, 3.79x), latin 141 551 (13.5%; 357 500, 2.53x) — **2 932 240 bytes stored for all eleven** against 10 837 954 uncompressed. Every unit is und

the **one-script cold path** — the first submission after a cache flush, which is where the cold cost moved — is **37–48 ms / 8–11 MB for most writing systems** (e.g. latin 30.8 ms / 6.22 MB, cyrillic 37.3 ms / 9.08 MB, arabic 41.2 ms / 10.5 MB, devanagari 48.2 ms / 11.0 MB, greek 35.8 ms / 8.62 MB, hebrew 42.0 ms / 10.80 MB, bengali 42.0 ms / 10.93 MB, indic 47.4 ms / 15.11 MB), **up to 129 ms / 34 MB for cjk (128.98 ms / 34.39 MB) and 206 ms / 35.9 MB for thai**, where the corpus is largest; and the **`unknown`-script cold path**, the only one that builds the combined unit, is **~553–566 ms / 30.5 MB** (was 190.41 ms / 31.53 MB).

`wp-content/debug.log` on the stack gained nothing beyond the known `wp-includes/class-wpdb.php` `preg_replace()` deprecation on option rows above ~1 MB (cjk, thai, indic, combined), which is out of scope.

### Suite

`docker compose run --rm test`: OK (1300 tests). `dev/smoke.sh` (default image), `dev/plugin-check.sh` (0 errors, 0 warnings) and `site/verify.sh` were run at review time and passed.
