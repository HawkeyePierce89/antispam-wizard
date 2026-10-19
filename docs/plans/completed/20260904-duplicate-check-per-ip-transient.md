# Duplicate check: one transient per IP with a capped map of content hashes

## Overview

`ASW_Duplicate_Check` currently writes one transient (two `wp_options` rows) per unique
IP + text pair on sites without an external object cache, so a Contact Form 7 bot sending
unique texts adds rows proportionally to its request rate. Rekey the file-locked transient
path to one transient per hashed IP whose value is a bounded map (max 32) of content hash →
`{count, first_seen}`, evicting an entry when full. The external object cache path
keeps its per-pair integer counters unchanged. Verdicts are unchanged for any IP with at
most 32 distinct texts inside one window.

> **Deviation applied in code review (post-implementation).** The plan specified eviction by
> the **oldest `first_seen`**, and Tasks 2–3 below were implemented and checked off exactly
> as written. Review found that policy inverts the check: `first_seen` is the window anchor
> and is never refreshed, so the oldest entry is the *longest-tracked* text — the counter
> closest to the threshold. Age-based eviction therefore discarded exactly what the check
> accumulates, letting a bot interleave its body with 32 fillers and, worse, letting other
> people's ordinary comments on a shared `comment_author_IP` (CDN without real-IP
> restoration, CGNAT, the CF7 `remote_ip` meta) silently reset a spammer's count — while
> the object cache path still flagged the same input. Eviction is now by **lowest `count`**,
> ties broken by the oldest `first_seen`, and runs in a `while` loop so an oversized map
> converges back to the bound. The residual limit (a body never yet repeated is a count-1
> entry, so >32 unique texts between every repeat keeps it at 1) is inherent to any fixed
> bound and is pinned as a known boundary rather than left silent. Task 2's eviction bullet,
> Task 3's two eviction bullets and the docblock/CLAUDE.md wording were updated to match.

## Context

- Files involved:
  - `antispam-wizard/includes/checks/class-duplicate-check.php` (the whole change)
  - `tests/DuplicateCheckTest.php` (33 cases today; one body updated, new cases added)
  - `CLAUDE.md` (Data storage paragraph)
  - `uninstall.php`, POT and the nine translations: untouched
  - `README.md`: verified it does not describe the per-pair transient — no change needed
- Related patterns:
  - `phpcs:ignore <exact sniff code> -- reason` on the same line (the existing
    `fopen`/`fclose`/`error_log` suppressions in this file are the model)
  - Test fixture globals `$wp_transients` / `$wp_options`, reset in `setUp`/`tearDown`
  - `ASW_Detector::sanitize_utf8()` for malformed UTF-8, never a fallback to `''`
- Verified facts that shape the design:
  - The bootstrap's `set_transient` stub injects `$value['__ttl'] = $expiration` into any
    array value, so the stored map always carries a top-level `__ttl` integer. Entry-wise
    validation (an entry must be an array with int `count` and int `first_seen`) drops it
    naturally; no bootstrap change is expected.
  - `test_window_reset_by_ttl_restarts_count` pokes a top-level `first_seen` and is the
    single existing case that must be updated (decided in Q&A); the other 32 pass
    byte-unchanged, including both corruption tests, the `MAX_TTL` cap test and all four
    cache-path tests.
  - `find_dup_transient_key()` matches `asw_dup_` and `find_cache_counter_key()` matches
    `asw:asw_dup_`, so the two paths stay discriminable.
- Dependencies: none new.

## Development Approach

- **Testing approach**: Regular (code first, then tests) — the change is a data-shape
  refactor whose target behaviour is already pinned by 32 existing cases, which act as the
  regression net while the new shape lands.
- Complete each task fully before moving to the next.
- **CRITICAL: every task MUST include new/updated tests**
- **CRITICAL: all tests must pass before starting the next task**
- Work on a feature branch off `master`; never push; never `docker compose down` the
  persistent `asw-site` stack.
- Every commit ends with, in this order and verbatim:
  `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`
  `Claude-Session: https://claude.ai/code/session_013XfBiJjvJkx2k5Smb9Tqej`

## Implementation Steps

### Task 1: Branch and baseline

**Files:**
- No source changes

- [x] feature branch off `master` — already on `duplicate-check-per-ip-transient` (branched
      from `master` at `ab06f45`, one commit ahead: the plan). Kept this branch rather than
      creating `feature/duplicate-per-ip-transient`, which would have orphaned the plan commit.
- [x] run `docker compose run --rm test` and record the total test count and the
      `DuplicateCheckTest` count as the "before" baseline (must be green; 33 duplicate cases)
      — **before baseline: OK (967 tests, 17609 assertions)** total, and
      **33 `DuplicateCheckTest` tests, 73 assertions**, all green.

### Task 2: Rekey the transient path to one entry map per IP

**Files:**
- Modify: `antispam-wizard/includes/checks/class-duplicate-check.php`
- Modify: `tests/DuplicateCheckTest.php`

- [x] add class constant `MAX_ENTRIES_PER_IP = 32` with a docblock stating the trade-off,
      **as revised in review**: below a full map of equally-repeated texts only seen-once
      entries are shed, and those carry no verdict; a counter carrying one is dropped only
      once every slot is held by a text repeated at least as often as the newcomer, which is
      exactly what the storage bound buys; note the value stays under a few kilobytes
      (32 × a 64-char hash plus two integers)
- [x] in `check()`, keep `$content_hash` as today and derive two keys: the per-IP transient
      key `'asw_dup_' . substr( hash( 'sha256', $ip_hash ), 0, 32 )` and the unchanged
      per-pair cache key `'asw_dup_' . substr( hash( 'sha256', $ip_hash . $content_hash ), 0, 32 )`;
      leave normalisation, the `is_string` IP guards, the `missing data` result, the
      `MAX_TTL` cap, the threshold comparison and both translatable detail strings
      byte-identical
- [x] change `atomic_increment()` to take the transient key, the cache key, the content hash
      and the TTL, dispatching to `cache_increment( $cache_key, $ttl )` (body unchanged,
      still per IP + text) or to the file-locked path
- [x] change `file_locked_increment()` to take the content hash as well; keep the lock-file
      bucket as the last two hex characters of the transient key, keep the
      `fopen`/`flock`/`fclose` structure, the `error_log` diagnostic and the no-unlink
      comment, and factor the read-modify-write body shared by the locked and unlocked
      branches into one private helper so it is not written twice
- [x] rewrite `read_transient_data()` to return a validated map: a non-array value yields an
      empty map; each entry is kept only when its key is a string and its value is an array
      with int `count` and int `first_seen`, rebuilt to exactly those two keys (this drops
      the test stub's `__ttl` integer and any foreign key); entries whose `first_seen` is
      `>= $ttl` old are dropped, which is the per-entry window reset
- [x] add the increment helper: if the content hash is absent from the pruned map and the map
      already holds `MAX_ENTRIES_PER_IP` entries, evict — **revised in review** — the entry
      with the lowest `count`, ties broken by the oldest `first_seen` (first match wins on a
      further tie), looping until the map is under the bound, then seed the hash at
      `count => 0, first_seen => time()`; increment its count and return it
- [x] rewrite `write_transient_data()` to compute the remaining lifetime from the newest
      entry's `first_seen` (`max( 1, $ttl - ( time() - $newest ) )`) so the transient expires
      with its youngest entry's window
- [x] update the inline comments in the file: transients are keyed per IP and hold a capped
      map of content hashes; the cap and its eviction trade-off; the external cache path
      deliberately keeps per-pair integer counters and is not converted to a
      read-modify-write list because `wp_cache_incr` cannot update a list atomically
- [x] update `test_window_reset_by_ttl_restarts_count` to age the entry inside the per-IP map
      instead of a top-level `first_seen`
- [x] run `docker compose run --rm test` — all 33 `DuplicateCheckTest` cases and the rest of
      the suite must be green

### Task 3: Tests for the new storage shape

**Files:**
- Modify: `tests/DuplicateCheckTest.php`
- Modify (only if a stub genuinely needs extending): `tests/bootstrap.php`
- Modify (only if a stub was extended): `CLAUDE.md`

- [x] add a private helper counting `asw_dup_`-prefixed keys in `$wp_transients` (excluding
      the `asw:` group-prefixed cache keys) and, where useful, one returning the decoded
      entry map
- [x] 32 distinct texts from one IP produce exactly one `asw_dup_` transient holding 32
      entries
- [x] a 33rd distinct text keeps the transient count at one, keeps the entry count at 32, and
      evicts the oldest of the least-repeated entries (**revised in review**)
- [x] the evicted text, submitted again, starts over at `duplicate count 1/3` — now scoped to
      the case where every entry is equally repeated (**revised in review**)
- [x] added in review: a repeated text outlives seen-once entries on eviction; a repeat
      interleaved with a full map of fillers is still flagged; other traffic on a shared IP
      does not reset a duplicate count; an oversized map is trimmed back to the cap; the
      residual flush-by-fillers boundary is pinned
- [x] two different IPs produce two `asw_dup_` transients
- [x] the third identical text from one IP is still flagged `duplicate comment 3/3` when other
      texts from the same IP are interleaved between the repeats
- [x] an entry aged past the TTL resets to `duplicate count 1/3` while a younger entry of the
      same IP keeps its count
- [x] a corrupt transient value is treated as empty with no PHP diagnostic (assert via
      `set_error_handler`, as `test_malformed_utf8_does_not_emit_a_deprecation` does), for
      both a non-array value and an array containing a malformed entry alongside a valid one
      — the valid entry survives, the malformed one is dropped
- [x] the transient key still starts with `asw_dup_` (the `uninstall.php` `LIKE` contract)
- [x] the external cache path (`$GLOBALS['wp_using_ext_object_cache'] = true`) still counts
      per IP + text: two different texts from one IP each stay at count 1 and produce two
      distinct `asw:asw_dup_` counters, and no bare `asw_dup_` transient is written
- [x] if any stub is extended, document it in `CLAUDE.md`'s stub paragraph — no stub
      needed extending (the `set_transient` stub's `__ttl` injection is dropped by the
      per-entry validation as predicted), so `CLAUDE.md` and `tests/bootstrap.php` are
      unchanged
- [x] run `docker compose run --rm test` — green: **977 tests, 17642 assertions** total
      (up from 967), **43 `DuplicateCheckTest` tests, 106 assertions**

### Task 4: Documentation

**Files:**
- Modify: `CLAUDE.md`

- [x] update the "Data storage" paragraph: duplicate-detection transients are keyed per
      hashed IP and hold a capped map (32) of content hashes with per-entry
      `count`/`first_seen`; state the eviction trade-off and that the external object cache
      path keeps per-IP + text integer counters with its existing fail-open eviction
      behaviour
- [x] confirm again that `README.md` and `antispam-wizard/readme.txt` do not describe the
      per-pair transient (verified: neither mentions transients — `grep -rn -i transient`
      over both files returns nothing) and leave both unchanged
- [x] run `docker compose run --rm test` — green: **977 tests, 17642 assertions**

### Task 5: Verify acceptance criteria

**Files:**
- No source changes expected; fix any finding in the file it belongs to

- [x] `docker compose run --rm test` green — **before: 967 tests, 17609 assertions**
      (33 `DuplicateCheckTest` cases); **after: 992 tests, 17721 assertions**
      (58 `DuplicateCheckTest` cases), counted after the review-finding commits
- [x] `bash dev/smoke.sh` passes on the default image — SMOKE PASS on
      `wordpress:7.1-php8.3-apache`: debug.log clean, 6/6 admin pages, spam/ham verdicts on
      both the comment and the CF7 route, SCHEMA DB_VERSION 3 confirmed on activation and
      after the 1.0.0-layout upgrade
- [x] `bash dev/plugin-check.sh` reports zero ERROR rows — PLUGIN CHECK PASS,
      **0 errors, 0 warnings**, `Checks complete. No errors found.`, exit 0
- [x] `git diff --stat master` touches only
      `antispam-wizard/includes/checks/class-duplicate-check.php`,
      `tests/DuplicateCheckTest.php`, `CLAUDE.md` (plus this plan file) — extended
      while addressing review findings to `antispam-wizard/includes/class-spam-log.php`
      and `antispam-wizard/includes/class-stats.php`, which open their lock files the
      same way and leaked the absolute path into `debug.log` through both PHP's own
      `fopen()` warning and their unsanitized `error_log()` diagnostic;
      `tests/bootstrap.php` needed no stub extension, and `uninstall.php`, the POT and the
      nine translations are untouched (`git diff --stat master --` over them is empty)
- [x] `grep -rn "phpcs:ignore" antispam-wizard/includes/checks/class-duplicate-check.php`
      shows an exact sniff code and a reason on every hit — 3 hits, one `fopen`
      (two sniff codes, since the call is now `@fopen`), one `error_log` and one
      `fclose`, each with its exact sniff codes and a `-- reason`
      (the two `fclose` branches were merged into one shared exit path while
      addressing review findings)
- [x] commit on the feature branch with the two required trailer lines; do not push
