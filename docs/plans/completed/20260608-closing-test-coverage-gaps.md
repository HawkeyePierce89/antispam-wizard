# Closing Test Coverage Gaps — antispam-wizard

## Overview
Surgically close the defects and uncovered branches identified by the audit. Coverage is already mature (86% lines, 555 tests), so this is precision work: 2 real fixes plus coverage top-ups in 6 places via additive test-harness changes. Goal — raise line coverage toward ~92%+ and lock in regressions for the cache/concurrency paths.

## Context
- Files involved:
  - Real defects: `antispam-wizard/admin/class-admin-page.php` (export handlers call unconditional `exit`), `antispam-wizard/includes/class-spam-log.php` (incomplete object-cache invalidation on read paths)
  - Uncovered branches: `antispam-wizard/admin/views/settings-log.php` (0%), `antispam-wizard/includes/checks/class-duplicate-check.php` (63%), `antispam-wizard/includes/class-script-detector.php`, `antispam-wizard/includes/class-sample-repository.php`, `antispam-wizard/includes/class-stats.php`, `antispam-wizard/antispam-wizard.php` (load_textdomain on init)
  - Test infrastructure: `tests/bootstrap.php` — needs 3 additive changes (stub `date_i18n`, switchable `wp_using_ext_object_cache()`, injectable `$wpdb->insert/delete` result)
- Related patterns: the `if (!defined('ASW_TESTING')) exit;` guard is already used in `handle_save` (265) and `handle_clear_log` (757); view tests (StatsDisplayTest) prove the harness can render view files
- Dependencies: running tests — `docker compose run --rm test`; real coverage — `phpdbg -qrr ... --coverage-clover`

## Development Approach
- **Testing approach**: Regular for the defect fixes (code, then regression test); for coverage top-ups — test-first where practical
- Each `bootstrap.php` change is additive and backward-compatible (stubs keep current behavior by default)
- Every task ends with a green `docker compose run --rm test`
- **CRITICAL: every task MUST include new/updated tests**
- **CRITICAL: all tests must pass before starting next task**
- Out of scope (deliberately): the phpdbg OOM artifact on script-detector (instrumentation, not a vulnerability); error/permission branches lock-fopen-fail, import via file-upload, rollback in mark-comment — impractical under the current harness, left uncovered

## Implementation Steps

### Task 1: Fix defect A1 — guard the exit in export handlers

**Files:**
- Modify: `antispam-wizard/admin/class-admin-page.php`
- Modify: `tests/AdminPageTest.php` (or a new `tests/ExportHandlersTest.php`)

- [x] Wrap `exit;` in `handle_stop_words_export()` and `handle_excluded_tokens_export()` in `if (!defined('ASW_TESTING')) exit;` + `// @codeCoverageIgnore` — following the handle_save/handle_clear_log pattern
- [x] Negative tests: no trigger / bad nonce / no permission (early return before exit)
- [x] Happy-path tests: correct export of stop-words and excluded-tokens (now reachable without killing PHPUnit) — assert CSV/output body
- [x] run `docker compose run --rm test` — must pass before task 2

### Task 2: Fix defect A2 — object-cache invalidation on spam-log read paths

**Files:**
- Modify: `antispam-wizard/includes/class-spam-log.php`
- Modify: `tests/SpamLogTest.php`

- [x] Add `wp_cache_delete` at the start of `get_entries()` and after `delete_option` in `clear()`
- [x] Test: `get_entries()` invalidates the cache before reading (via a wp_cache_delete stub counter)
- [x] Test: `clear()` invalidates the cache after removing the option
- [x] run `docker compose run --rm test` — must pass before task 3

### Task 3: Cover the Spam Log view (settings-log.php 0% → near-full)

**Files:**
- Modify: `tests/bootstrap.php` (add `date_i18n` stub)
- Create: `tests/SpamLogDisplayTest.php`

- [x] Add a `date_i18n` stub in bootstrap (delegate to `date()`/`gmdate` so a populated log does not fatal)
- [x] SpamLogDisplayTest: render the view in empty / populated / trained-entry states
- [x] Assert key markup elements in each state
- [x] run `docker compose run --rm test` — must pass before task 4

### Task 4: Cover the duplicate-check cache path (63% → ~95%) + document fail-open

**Files:**
- Modify: `tests/bootstrap.php` (switchable `wp_using_ext_object_cache()`)
- Modify: `antispam-wizard/includes/checks/class-duplicate-check.php` (documentation comment only — the window trade-off)
- Modify: `tests/DuplicateCheckTest.php`

- [x] Make the `wp_using_ext_object_cache()` stub switchable via a test flag (default false — behavior unchanged)
- [x] Document in code the deliberate window-semantics trade-off (cache-backend resets the counter/TTL on eviction without a first_seen anchor — fail-open)
- [x] Cache-path tests: happy-path threshold, eviction-recovery (counter reset), window reset by TTL, corrupt transient, lock-dir creation
- [x] run `docker compose run --rm test` — must pass before task 5

### Task 5: Cover concurrency/db-error in sample-repository

**Files:**
- Modify: `tests/bootstrap.php` (inject `$wpdb->insert`/`delete` result in the stub)
- Modify: `tests/SampleRepositoryTest.php`

- [x] Extend the `$wpdb` stub: allow injecting the return of `insert` (0/false for failure) and `delete`, without breaking current tests (default — prior behavior)
- [x] Test: recovery after a failed insert → 'exists' branch (duplicate by hash)
- [x] Test: DB error on insert/delete → correct false/handling
- [x] run `docker compose run --rm test` — must pass before task 6

### Task 6: Cheap one-off tests for the remaining branches

**Files:**
- Modify: `tests/ScriptDetectorTest.php`, `tests/SpamLogTest.php`, `tests/ASWTest.php`, `tests/StatsTest.php`

- [x] script-detector: `detect('ქართული') === 'unknown'` (letter outside the 10 modeled scripts, L83)
- [x] spam-log: `is_trained()` for a nonexistent comment_id → false (L185 fall-through)
- [x] antispam-wizard: a test that actually fires `do_action('init')` → triggers `load_textdomain` (L342)
- [x] stats: first-run lock-dir creation (.htaccess/index.php) — pre-delete the lock-dir + "parent is a file" trick instead of chmod 0000 (works under root)
- [x] run `docker compose run --rm test` — must pass before task 7

### Task 7: Verify acceptance criteria

- [x] run full test suite: `docker compose run --rm test` — all green (589 tests, 1740 assertions)
- [x] measure coverage via `phpdbg -qrr ... --coverage-clover`; confirm line growth toward ~92%+ and closure of export handlers, settings-log.php, duplicate-check cache path — 91.50% lines (1872/2046); settings-log.php 0%→98.4%, class-duplicate-check.php 63%→90.5%, export handlers (class-admin-page.php) 90.4%
- [x] confirm no regressions in the 555 existing tests — all 589 tests pass (555 prior + new coverage tests)

### Task 8: Update documentation

- [x] CLAUDE.md: clarify spam-log object-cache invalidation (read paths now invalidate too); note the documented duplicate-check fail-open trade-off
- [x] CLAUDE.md: if needed, note the new test stubs (date_i18n, switchable ext-object-cache, wpdb-result injection) in the harness section
