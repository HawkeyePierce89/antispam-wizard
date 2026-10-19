# Fix External Code Review Findings

## Overview

Address critical, important, and select minor findings from an external code review of the Balrog Blocker plugin. Critical fixes ensure the plugin cannot break WordPress comment submission. Important fixes address performance, data integrity, security, and correctness issues.

## Context

- Files involved: `class-detector.php`, `class-balrog-blocker.php`, `class-naive-bayes.php`, `class-spam-log.php`, `class-stats.php`, `class-settings.php`, `class-training-data.php`, `admin/class-admin-page.php`
- All files under `balrog-blocker/includes/` and `balrog-blocker/admin/`
- Tests run via `docker compose run --rm test`

## Development Approach

- **Testing approach**: Regular (code first, then tests)
- Complete each task fully before moving to the next
- **CRITICAL: every task MUST include new/updated tests**
- **CRITICAL: all tests must pass before starting next task**

## Implementation Steps

### Task 1: C1 + C2 — Detector fail-open and upstream respect

**Files:**
- Modify: `balrog-blocker/includes/class-detector.php`
- Modify: `tests/DetectorTest.php`

- [x] C1: Wrap each `$check->check()` call in `run()` with `try/catch (\Throwable)` — on exception, log via `error_log()` and skip the check (fail-open, treat as not-spam)
- [x] C2: At the top of `filter_comment()`, short-circuit if `$approved` is already `WP_Error`, `'spam'`, or `'trash'` — return `$approved` unchanged
- [x] Minor: Add short-circuit in `run()` loop — once a spam result is found, skip remaining checks (return early)
- [x] Minor: Sanitize `$spam_checks` values with `wp_strip_all_tags()` before passing to `update_comment_meta`
- [x] Write tests: exception in check triggers fail-open; upstream spam/trash/WP_Error values are preserved; short-circuit stops after first spam
- [x] Run project test suite — must pass before task 2

### Task 2: I6 — wp_unslash nonce values before verification

**Files:**
- Modify: `balrog-blocker/admin/class-admin-page.php`
- Modify: `tests/bootstrap.php` (update `wp_verify_nonce` stub to simulate slashed input)
- Modify: `tests/AdminPageTest.php`

- [x] Wrap all 11 `$_POST[...nonce...]` values with `wp_unslash()` before passing to `wp_verify_nonce()`
- [x] Update `wp_verify_nonce` stub in test bootstrap to add backslash-escaping to nonce globals, so tests actually verify the unslash behavior
- [x] Run project test suite — must pass before task 3

### Task 3: I2 — Object cache coherency for spam log and stats

**Files:**
- Modify: `balrog-blocker/includes/class-spam-log.php`
- Modify: `balrog-blocker/includes/class-stats.php`
- Modify: `tests/SpamLogTest.php`
- Modify: `tests/StatsTest.php`

- [x] In `Spam_Log::add_entry()` and `Spam_Log::update_entry_comment_id()`, call `wp_cache_delete('balrog_blocker_spam_log', 'options')` immediately after acquiring the lock, before reading via `get_option()`
- [x] Same pattern in `Stats::record()` — `wp_cache_delete('balrog_blocker_stats', 'options')` after lock acquisition
- [x] Add `wp_cache_delete` stub to test bootstrap if not present; write tests verifying cache invalidation occurs before option read
- [x] Run project test suite — must pass before task 4

### Task 4: I1 — Cache classifier state in transients

**Files:**
- Modify: `balrog-blocker/includes/class-balrog-blocker.php`
- Modify: `tests/BalrogBlockerTest.php` (or relevant test file)

- [x] Compute a cache key from the `filemtime()` of all training data files (legacy + per-script)
- [x] Before building classifiers, check for a transient keyed by this hash; if found, use `set_state()` to restore each classifier from cached state
- [x] After building classifiers from scratch, serialize their `get_state()` into the transient
- [x] I7 fix: refactor `build_checks()` to load each script's samples once (store in local array), then pass the same data to both per-script classifier training and combined classifier training — eliminates double `load_script_samples()` calls
- [x] Write test verifying classifier state is cached and restored from transient on second call
- [x] Run project test suite — must pass before task 5

### Task 5: I3 — Size limits on imported data and training samples

**Files:**
- Modify: `balrog-blocker/admin/class-admin-page.php`
- Modify: `balrog-blocker/includes/class-training-data.php`
- Modify: `tests/AdminPageTest.php`
- Modify: `tests/TrainingDataTest.php`

- [x] In `handle_stop_words_import()` and `handle_excluded_tokens_import()`, after parsing imported lines, cap the total count (e.g., 10000 entries) and add admin notice if truncated
- [x] In `Training_Data::save_sample()`, reject samples longer than 16 KB (return false)
- [x] Write tests for: import count cap enforced; oversized training sample rejected
- [x] Run project test suite — must pass before task 6

### Task 6: I4 + I5 — Dead code cleanup

**Files:**
- Modify: `balrog-blocker/includes/class-settings.php`
- Modify: `balrog-blocker/includes/class-naive-bayes.php`
- Modify: `tests/NaiveBayesTest.php`

- [x] I4: Remove `Settings::register()` method and its `sanitize()` callback — they are never called and create false security impression
- [x] I5: Remove `Naive_Bayes::unlearn()` method — it is unused externally and has a bug where mismatched unlearn leaves orphaned tokens causing -INF in classify. If future need arises, a correct implementation can be written with tests.
- [x] Update tests to remove any references to removed methods; add test confirming `unlearn` and `register` no longer exist if appropriate
- [x] Run project test suite — must pass before task 7

### Task 7: Minor fixes batch

**Files:**
- Modify: `balrog-blocker/includes/checks/class-bayesian-check.php`

- [x] Add explicit `'UTF-8'` parameter to the `mb_strlen()` call (line ~102)
- [x] Run project test suite — must pass before task 8

### Task 8: Verify acceptance criteria

- [x] Run full test suite (`docker compose run --rm test`)
- [x] Verify all Critical (C1, C2) and Important (I1-I7) findings are addressed
- [x] Verify no regressions in existing 468 tests

### Task 9: Update documentation

- [x] Update CLAUDE.md if internal patterns changed (e.g., transient caching for classifiers)
- [x] Move this plan to `docs/plans/completed/`
