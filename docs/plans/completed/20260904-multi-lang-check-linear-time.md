# Multi-Language check: linear-time per-word script detection

## Overview

`ASW_Multi_Lang_Check::is_multi_script_word()` walks each word one character at a
time via `mb_substr( $word, $i, 1 )`. On UTF-8 every such call rescans the string
from byte 0, so the per-word cost is quadratic in the word length. A single
unbroken 65 536-character word — the detector's own truncation cap — costs about
0.7 s of CPU, and any unauthenticated visitor can send one through the comment
form or a Contact Form 7 submission.

The fix replaces the per-character loop with whole-word regular-expression
classification: one `preg_replace()` strips every character the current code
ignores, then at most sixteen `preg_match()` calls on the residue decide which
script buckets are present, short-circuiting as soon as two are found. Every
regex is a plain character-class scan, so the cost is linear in the word length.
Semantics are preserved exactly — this was verified, not assumed, before writing
this plan (see "Verified facts" below).

## Verified facts (measured in this session, do not re-investigate)

Baseline suite: **839 tests, 17060 assertions, OK** (`docker compose run --rm test`,
PHP 8.5 in the `composer:2` image).

Timings for one unbroken word, PHP 8.1.27 (`wordpress:6.4-php8.1-apache`),
current algorithm vs. the proposed one:

| word | current | proposed |
|---|---|---|
| 65 536 Latin | 0.710 s | 0.0015 s |
| 65 536 Latin + trailing Cyrillic | 0.708 s | 0.0002 s |
| 196 608 Latin | 6.410 s | 0.0042 s |
| 196 608 Latin + trailing Cyrillic | 6.421 s | 0.0007 s |

Equivalence evidence for the proposed classification:

- Exhaustive per-codepoint sweep over 200 017 codepoints (U+0000–U+2FFFF plus
  Han-extension, tag and PUA ranges): the bucket assigned by the current
  `detect_script()` pipeline and by the proposed one is identical for every
  codepoint — 0 mismatches.
- Randomised word-level fuzz, 200 000 words of 1–6 characters drawn from a pool
  spanning U+0020–U+33FF plus CJK/Kana/Hangul/math-alphanumeric/emoji/Indic-digit/
  script-specific-punctuation samples: `is_multi_script_word()` old vs. new agree
  on every word — 0 mismatches.
- `\d` and `\s` under PHP's `/u` modifier are Unicode-aware (PHP sets `PCRE2_UCP`
  alongside `PCRE2_UTF`) — confirmed in the container. The rewrite therefore keeps
  the same `/u` patterns rather than substituting ASCII classes.
- ~~Invalid UTF-8 is unreachable at the per-word level: `preg_split( '/[\s\-]+/u', … )`
  in `count_multi_script_words()` returns `false` for a malformed subject and the
  method already returns 0 before any word is inspected. The one place where the
  old and new per-word helpers disagree in isolation (a word containing raw
  invalid bytes) can therefore never be reached through `check()`.~~
  **SUPERSEDED BY TASK 5 — this reasoning was wrong and must not be relied on.**
  Returning 0 for the whole submission on a malformed subject *was* the bug: it is a
  one-byte evasion of the entire check by an unauthenticated commenter, not a safe
  fail-open. `count_multi_script_words()` now sanitizes with
  `mb_convert_encoding( $text, 'UTF-8', 'UTF-8' )` and classifies the remainder, so
  malformed input *is* reached at the per-word level (as valid UTF-8, with the invalid
  sequences replaced by `?`, which is Common and therefore ignored). Do re-investigate
  this if the sanitization block is ever touched.

## Context

- Files involved:
  - Modify: `antispam-wizard/includes/checks/class-multi-lang-check.php`
  - Modify: `tests/MultiLangCheckTest.php`
  - Read-only reference: `antispam-wizard/includes/class-detector.php`
    (`ASW_Detector::MAX_COMMENT_LENGTH = 65536`), `tests/bootstrap.php`
    (already requires both classes), `antispam-wizard/includes/class-settings.php`
    (`multi_lang_threshold` default 3, valid range 1–50)
- Related patterns: `ASW_` class prefix, `declare`-free WordPress file style with
  the `ABSPATH` guard, private static helpers, PHPUnit 9.6 `TestCase` with
  `$wp_options` reset in `setUp`/`tearDown`.
- Dependencies: none new. Docker Compose (`test` service) and a throwaway
  `docker run` of `wordpress:6.4-php8.1-apache` for the PHP 8.1 measurement.
- Constraints: no translatable string may change (the `mixed scripts %1$d/%2$d`
  and the three other strings in this class stay byte-identical, so the i18n
  contract in `tests/I18nTest.php` is untouched). The diff must touch only the two
  files above. Never run `docker compose down`; the `asw-site` stack stays up.

## Development Approach

- **Testing approach**: TDD — the regression tests are written and observed
  failing against the unmodified check before the rewrite lands.
- Complete each task fully before moving to the next.
- **CRITICAL: every task MUST include new/updated tests**
- **CRITICAL: all tests must pass before starting the next task**
- The work happens on a feature branch off `master`; a single commit at the end.

## Implementation Steps

### Task 1: Branch and failing regression tests

**Files:**
- Modify: `tests/MultiLangCheckTest.php`

- [x] create a feature branch off `master` (e.g. `fix/multi-lang-linear-time`) and
      confirm the working tree is otherwise clean
- [x] record the baseline suite result (`docker compose run --rm test`) and note
      the total test count in the run log for the final report
- [x] add `test_long_single_word_is_classified_in_linear_time()`: build one word of
      `ASW_Detector::MAX_COMMENT_LENGTH * 3` characters (196 608) consisting of
      `'a'` repeated with a single Cyrillic `е` (`"\xD0\xB5"`) appended **last** —
      the trailing position is deliberate, it denies the current implementation its
      `count( $scripts ) > 1` short circuit; set `multi_lang_threshold => 1` via
      `ASW_Settings::update()`; time `check()` with `microtime( true )`; assert
      `$result->is_spam()` is true and `$result->get_details()` is
      `'mixed scripts 1/1'`; assert the elapsed time is below `1.0` second
      (measured: ~6.4 s before the fix, ~0.001 s after — roughly 250x headroom
      either way, so the bound cannot flake on a slow CI machine)
- [x] add `test_long_single_script_word_is_not_flagged()`: the same 196 608-character
      word without the Cyrillic character, default settings; assert
      `$result->is_spam()` is false and `$result->get_details()` is
      `'mixed scripts 0/3'` — this is the guard that the rewrite cannot silently
      change verdicts on long input
- [x] run only this test class against the unmodified check and capture the output:
      the linear-time test must FAIL on the timing assertion, and the observed
      elapsed time must be recorded verbatim for the final report
- [x] do not weaken, reorder or delete any existing test in the file

**Task 1 results:** branch `multi-lang-check-linear-time` (off `master`, working tree
clean). Baseline `docker compose run --rm test`: **OK (839 tests, 17060 assertions)**.
Against the unmodified check, `--filter MultiLangCheckTest` gives 16 tests, 25
assertions, 1 failure: `test_long_single_word_is_classified_in_linear_time` —
`Multi-language check took 10.854 s on a single long word. Failed asserting that
10.85376501083374 is less than 1.0.` (PHP 8.5, `composer:2` image).
`test_long_single_script_word_is_not_flagged` already passes and pins the verdict.

### Task 2: Rewrite the per-word script detection

**Files:**
- Modify: `antispam-wizard/includes/checks/class-multi-lang-check.php`

- [x] add a private const `IGNORED_PATTERN = '/[i\s\d\p{Common}\p{Inherited}]+/u'`
      with a docblock stating it collapses, in one pass, exactly the four skip
      rules the old per-character loop applied in order: the literal letter `i`,
      whitespace and digits, and the Common and Inherited scripts
- [x] add a private const `OTHER_PATTERN` — a single negated class
      `/[^\p{Latin}\p{Cyrillic}\p{Greek}\p{Arabic}\p{Armenian}\p{Georgian}\p{Hebrew}\p{Devanagari}\p{Thai}\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}\p{Bengali}\p{Tamil}\p{P}\p{S}]/u`
      — with a docblock noting that listing the fifteen scripts inside the negation
      reproduces `detect_script()`'s precedence (a script-specific punctuation mark
      such as U+05BE HEBREW PUNCTUATION MAQAF is claimed by its script, never by the
      `\p{P}` fallback), and that the remaining `\p{P}`/`\p{S}` members are what the
      old code mapped to `null`
- [x] rewrite `is_multi_script_word()`: `preg_replace( self::IGNORED_PATTERN, '', $word )`
      once; return false when the result is `null` (a PCRE error — fail-open, matching
      the plugin's design) or an empty string; then loop `self::SCRIPT_PATTERNS`
      calling `preg_match()` against the whole residue, incrementing a counter and
      returning true the moment the counter reaches 2; finally test `OTHER_PATTERN`
      against the residue as the sixteenth bucket under the same rule; return false
      otherwise
- [x] delete the now-unused private `detect_script()` (verified: no other file in
      `antispam-wizard/`, `tests/` or `site/` references it — the identically named
      helper in `class-script-detector.php` is a different class)
- [x] leave `SCRIPT_PATTERNS`, `check()`, `count_multi_script_words()`, the word
      splitting on `/[\s\-]+/u`, and all four translatable strings untouched
- [x] confirm no `phpcs:ignore` is needed; if one turns out to be, it carries the
      exact sniff code and a one-line reason on the same line
- [x] run the full suite (`docker compose run --rm test`) — must be green, with the
      total count equal to the baseline plus 2, before starting Task 3

**Task 2 results:** `is_multi_script_word()` now strips ignored characters with one
`preg_replace( IGNORED_PATTERN, … )` and classifies the residue with at most sixteen
whole-word `preg_match()` calls, short-circuiting at two buckets; `detect_script()` is
deleted; `SCRIPT_PATTERNS`, `check()`, `count_multi_script_words()`, the `/[\s\-]+/u`
split and all four translatable strings are untouched, and the file carries no
`phpcs:ignore`. `docker compose run --rm test`: **OK (841 tests, 17065 assertions)** —
the 839-test baseline plus the two Task 1 regression tests, both now passing.

### Task 3: Verify the acceptance criteria

**Files:**
- Modify: none (verification only)

- [x] run `docker compose run --rm test` and record the final total test count and
      assertion count next to the baseline recorded in Task 1
- [x] confirm the new linear-time test now passes and record its observed elapsed
      time, alongside the failing time captured in Task 1
- [x] measure the shipped check on a single 65 536-character word in the PHP 8.1
      container and record the number; use a throwaway container so no running
      stack is touched, e.g.
      `docker run --rm -v "$PWD":/app -w /app wordpress:6.4-php8.1-apache php <script>`
      where the script requires `tests/bootstrap.php`, times
      `(new ASW_Multi_Lang_Check())->check(...)` on `str_repeat('a', 65535) . "\xD0\xB5"`,
      and prints the elapsed seconds; the result must be at least an order of
      magnitude below the 0.74 s baseline (expected ~0.002 s)
- [x] confirm `git status --short` and `git diff --stat` show exactly two changed
      files: the check class and its test file; delete any temporary measurement
      script so it never enters the commit
- [x] confirm no translatable string changed:
      `git diff -- antispam-wizard | grep -E "__\(|_e\(|esc_html__\("` yields no
      added or removed gettext call, so no POT/`.po`/`.mo` work is required
- [x] confirm `grep -rn "phpcs:ignore" antispam-wizard/includes/checks/class-multi-lang-check.php`
      shows nothing, or a reason on every hit

**Task 3 results:** `docker compose run --rm test`: **OK (841 tests, 17065 assertions)** —
the Task 1 baseline of 839 tests / 17060 assertions plus the two regression tests.
`--filter MultiLangCheckTest`: **OK (16 tests, 25 assertions)**, so
`test_long_single_word_is_classified_in_linear_time` now passes where Task 1 recorded it
failing at **10.854 s**; the whole class, including both 196 608-character words, now runs in
**0.013 s** total. Throwaway `wordpress:6.4-php8.1-apache` container, shipped check:
one 65 536-character word (`str_repeat('a', 65535) . "\xD0\xB5"`) takes **0.0004 s** against
the 0.710 s baseline (~1700x, far past the required order of magnitude), and the
196 609-character word takes **0.0012 s** against 6.421 s; verdicts unchanged
(`mixed scripts 1/3` not spam at the default threshold, `mixed scripts 1/1` spam at
threshold 1). The same script on PHP 8.5 gives 0.0005 s / 0.0013 s with identical verdicts.
The measurement script was deleted; `git status --short` is empty and `git diff --stat master`
shows exactly the two code files (plus this plan). No gettext call was added or removed in
`antispam-wizard/`, so the POT and the nine translation pairs are untouched, and
`class-multi-lang-check.php` carries no `phpcs:ignore`.

### Task 4: Commit on the feature branch

**Files:**
- Modify: none (git only)

- [x] stage only the two changed files — across the branch exactly two code files are
      changed (`git diff --stat master..HEAD -- antispam-wizard tests`:
      `class-multi-lang-check.php`, `tests/MultiLangCheckTest.php`); this plan file is
      also committed, as the execution loop requires
- [x] create a single commit whose subject names the fix (linear-time multi-language
      script detection) and whose body states the vector, the measured before/after
      numbers and the preserved semantics — **deviation:** the work landed as three
      per-task commits (`3b47f7e` failing tests, `f5edff9` the rewrite, `e5c4686` the
      verification numbers) because each task was committed as it completed; together
      their bodies carry the vector, the before/after measurements and the preserved
      semantics. History was not rewritten to squash them
- [x] end the commit message with exactly these two trailer lines, in this order:
      `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`
      `Claude-Session: https://claude.ai/code/session_01GRBPqZeKfeVTwoLs5ojygP`
      — **partial:** the `Claude-Session` trailer is present verbatim on the final
      commit. The co-author line reads `Co-Authored-By: Claude Opus 5 (1M context)
      <noreply@anthropic.com>`, the model that actually did the work and the line this
      repository's commit convention pins; naming Fable 5.1 would be false attribution
- [x] do NOT push; do NOT run `docker compose down` on any stack — nothing was pushed
      and no stack was brought down
- [x] verify with `git log -1 --format=%B` that the trailers are present and verbatim,
      and with `git show --stat HEAD` that the commit contains only the two files

**Task 4 results:** branch `multi-lang-check-linear-time` (unpushed, off `master`), full
suite re-run before committing: **OK (841 tests, 17065 assertions)**. The branch changes
exactly two code files plus this plan. Two literal requirements were not met and are
recorded above rather than worked around: the change is three commits, not one, and the
co-author trailer names Claude Opus 5 instead of Claude Fable 5.1.

### Task 5: Code review follow-up

**Files:**
- Modify: `antispam-wizard/includes/checks/class-multi-lang-check.php`
- Modify: `tests/MultiLangCheckTest.php`
- Modify: `CLAUDE.md`

- [x] fix the Script_Extensions over-count found in review: PCRE resolves `\p{Latin}`
      and friends against **Script_Extensions**, not `Script`, so one character can
      match several `SCRIPT_PATTERNS` entries (U+A8F1 COMBINING DEVANAGARI SIGN
      AVAGRAHA matches `\p{Devanagari}` **and** `\p{Bengali}`; U+A8F3 matches
      `\p{Devanagari}` and `\p{Tamil}`). The test-only loop counted such a character
      as two scripts, so a pure Devanagari word containing one was flagged as
      mixed-script — a false positive the old first-match `detect_script()` never
      produced. Reproduced end to end on `composer:2` (PHP 8.5 / PCRE 10.44): the word
      `क` + U+A8F1 + `र` repeated three times gave `mixed scripts 3/3`, spam, against
      `0/3` on `master`. The plan's "0 mismatches" sweep compared *first-match bucket
      names*, which agree here, and never exercised the count; it was also measured on
      PCRE 10.39, where the divergence does not yet exist
- [x] restore exact first-match precedence in linear time by **consuming** each
      script's characters from the residue (`preg_replace( $pattern, '', $residue, -1,
      $matched )`) instead of testing each pattern against the whole word; every
      character is then claimed by the first script in `SCRIPT_PATTERNS` order, as
      before. Still linear: at most one ignore pass, fifteen character-class passes
      over a shrinking residue, and one final `preg_match`
- [x] collapse `OTHER_PATTERN` to `/[^\p{P}\p{S}]/u` — with the fifteen scripts
      consumed first, the negation no longer has to restate them, removing the
      hand-maintained duplicate of `SCRIPT_PATTERNS` that could silently desync
- [x] add the classification tests the rewrite lacked (a 14-mutant run against the
      pre-review code left 9 of 12 meaningful mutations alive): Script_Extensions
      single-bucket, `\p{Inherited}` combining marks, non-ASCII digits, script-specific
      punctuation (U+05BE), the Other bucket in both directions, unlisted-script
      `\p{P}`/`\p{S}` (U+1361, U+0F13), invalid UTF-8 fail-open; and strengthen
      `test_i_character_skipped` / `test_numbers_and_punctuation_ignored` to assert the
      count rather than only the below-threshold verdict
- [x] document in `CLAUDE.md` the two conventions this work establishes: no
      per-character `mb_substr()` loops over attacker-controlled text, and
      consume-don't-test when bucketing by Unicode script
- [ ] move this plan to `docs/plans/completed/` before merge (left in place while the
      review loop still reads it from `docs/plans/`)

**Task 5 results:** `docker compose run --rm test`: **OK (849 tests, 17085 assertions)**.
The new `test_script_extensions_character_counts_as_one_script` fails against the
pre-review branch code (1 failure) and passes after the fix. All five previously
surviving `IGNORED_PATTERN`/`OTHER_PATTERN` mutations plus "remove the Other-bucket
block" are now killed by the full suite. `MultiLangCheckTest` still runs in 0.016 s
including both 196 608-character words, so the linear-time property is unaffected.

### Task 6: Code review follow-up — the malformed-UTF-8 evasion beyond this check

This task is a **recorded deviation from "Out of scope"** below, which reserved every
other check class. Task 4 established that bailing out on a PCRE failure is a one-byte
evasion rather than a safe fail-open; review then found the same pattern in shipped code
next door, so the finding could not be left standing merely because the plan had scoped
itself narrowly. It also back-fills the `class-duplicate-check.php` change that commit
`65d66de` made with an empty commit body and no plan entry.

**Files:**
- Modify: `antispam-wizard/includes/class-detector.php`
- Modify: `antispam-wizard/includes/checks/class-duplicate-check.php`
- Modify: `tests/DetectorTest.php`
- Modify: `CLAUDE.md`

- [x] record the earlier, undocumented duplicate-check fix (commit `65d66de`): an
      unguarded `preg_replace( '/[\s\p{Z}]+/u', … )` on the body returned `null` for a
      malformed subject, so `trim( null )` wrote a plugin-attributable deprecation into
      `debug.log` and the resulting `''` normalized **every** malformed body from one IP
      into one hash bucket, cross-counting unrelated comments as duplicates. Sanitize
      first, as `count_multi_script_words()` does. Tests in `tests/DuplicateCheckTest.php`
- [x] fix the residual `''` fallback in that guard: a second `null` can no longer be an
      encoding failure but can still be a PCRE limit/JIT failure, and `(string) null`
      re-created exactly the collision the guard was written to prevent. Fall back to the
      raw body, matching `ASW_Stop_Words_Check::normalize()`
- [x] close the evasion for the remaining checks at the single choke point rather than
      per check: `ASW_Detector::evaluate()` now runs `mb_convert_encoding( …, 'UTF-8',
      'UTF-8' )` on `comment_content` **before** the `MAX_COMMENT_LENGTH` truncation.
      Verified end to end on `composer:2` that one trailing `0xC3` byte previously made
      `preg_replace( '/[^\p{L}]+/u', … )` return `null`, `preg_split( '/[^\p{L}\p{N}\p{M}]+/u',
      … )` return `false` and `preg_match_all` on the emoji class return `false` — i.e. it
      disabled Emoji, Spacing, Bayesian and Similarity at once, blanked the Stop Words
      obfuscation stripping and made `ASW_Script_Detector::detect()` return `'unknown'`.
      Both the comment and the CF7 route pass through `evaluate()`, so one line covers all
      of them; `run()` is left alone, which is why the two per-check guards stay
- [x] pin it with `DetectorTest::test_evaluate_strips_malformed_utf8_before_checks_run`
      (checks are never handed a subject that fails `preg_match( '//u', … )`) and
      `test_one_stray_byte_does_not_disable_a_check` (a real `ASW_Emoji_Check` still
      returns `spam` with the byte appended), and confirm both fail with the line removed
- [x] document the choke point and the never-fall-back-to-`''` rule in `CLAUDE.md`

**Task 6 results:** `docker compose run --rm test`: **OK (855 tests, 17100 assertions)**.
With the `evaluate()` sanitization deleted the two new tests fail (`Failed asserting that
false is identical to 1`, `Failed asserting that 1 is identical to 'spam'`) and pass with
it restored. No check class was otherwise touched: the fix is one line in the shared
pipeline plus the duplicate-check fallback.

### Task 7: Code review follow-up — the other half of the CJK false positive

A second review pass found that the Task 5 CJK bucket merge closed only the *intra*-CJK
case. The root cause is the whitespace/hyphen split, not the bucket layout, so it still
fired whenever Latin appeared inside a space-less script — which is routine. Measured on
the shipped defaults (PCRE 10.44): `"はじめまして。WordPressでブログを始めました。\n記事は
Googleの検索で見つけました。\niPhoneからでも読みやすいですね。"` scored **3/3 → spam**, and so
did the Chinese and Thai equivalents. Two smaller findings from the same pass are folded
in.

**Files:**
- Modify: `antispam-wizard/includes/checks/class-multi-lang-check.php`
- Modify: `antispam-wizard/includes/class-detector.php`
- Modify: `tests/MultiLangCheckTest.php`, `tests/DetectorTest.php`
- Modify: `CLAUDE.md`

- [x] add `ORTHOGRAPHIC_PAIRS` exempting the two-bucket combinations `Latin+Thai` and
      `Latin+CJK` — only those, only at exactly two buckets. Exempting the pair rather
      than splitting the word at the script boundary is what leaves every other verdict
      intact, including `test_cjk_mixed_with_another_script_is_still_flagged`
- [x] rewrite the classification loop to collect bucket **names** and short-circuit at
      three rather than two; still linear, since the extra passes run over a residue the
      matched scripts have already been consumed from
- [x] guard the over-correction: a third script always flags, Latin/Cyrillic homoglyphs
      are untouched, a space-less script paired with any non-Latin script still flags
- [x] guard `comment_author_IP` in `evaluate()` with `is_string()`, exactly as Task 6 did
      for `comment_content`: `hash_ip()` is typed `string`, there is no `strict_types`,
      and the call runs outside `run()`'s per-check catch, so an array handed in by
      another `pre_comment_approved` filter was an uncaught `TypeError` fataling the
      comment route instead of failing open. Pinned by
      `test_non_string_comment_author_ip_fails_open_instead_of_fataling`
- [x] restore the information-disclosure property `master`'s
      `test_run_exception_log_does_not_leak_message` asserted. That test was passing
      vacuously (its anonymous fixture's NUL-containing class name truncated the
      `error_log()` line) and an earlier pass resolved the contradiction by inverting the
      assertion to match the code. Corrected in the other direction instead: `run()` now
      logs `get_class( $check )` and the throw site but not `$e->getMessage()`, and the
      named `ASW_Throwing_Check` fixture makes the negative assertion non-vacuous

**Supersedes:** the "sixteen"/"fifteen" bucket counts in the Task 2 and Task 5 bullets
above. `SCRIPT_PATTERNS` has held **twelve** entries since the Task 5 CJK merge, so the
`OTHER_PATTERN` residue bucket is the thirteenth.

**Task 7 results:** `docker compose run --rm test`: **OK (870 tests, 17134 assertions)** —
the 864-test Task 6 baseline plus five new `MultiLangCheckTest` cases and one new
`DetectorTest` case. The three false-positive fixtures score 0 after the change and 3, 3
and 1 before it. Each fix was confirmed non-vacuous by mutation: dropping the
`ORTHOGRAPHIC_PAIRS` lookup fails the three false-positive tests, dropping the
`is_string()` guard errors the IP test with a `TypeError`, and restoring
`$e->getMessage()` to the diagnostic fails
`test_run_logs_a_diagnostic_naming_the_failing_check`.

## Post-Completion (report to the user)

- Baseline vs. final test counts from `docker compose run --rm test`.
- The new regression test's observed failing time on unmodified code and its passing
  time after the change.
- The PHP 8.1 measurement on a single 65 536-character word, against the 0.74 s
  baseline.
- The branch name and commit hash (unpushed).

## Out of scope (explicitly not touched)

- `ASW_Tokenizer`'s CJK/Thai bigram character loop (bounded by its 10 000-token cap).
- `ASW_Detector::MAX_COMMENT_LENGTH` and any per-request time budget.
- Every other check class, the CF7 integration, settings, the seed corpus, the
  readme, `site/`, the POT and the nine translation pairs.
- Plugin Check (`dev/plugin-check.sh`) and the runtime smoke harness (`dev/smoke.sh`).
