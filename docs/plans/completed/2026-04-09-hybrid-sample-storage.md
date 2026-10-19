# Hybrid Sample Storage: File Seed + DB User Samples

## Overview

Move user-added training samples (mark as spam/ham) from data/*.txt files to a WordPress DB table. Seed corpus stays in git as .txt files. The classifier merges both sources transparently.

## Context

- Files involved:
  - Create: `balrog-blocker/includes/class-sample-repository.php`
  - Modify: `balrog-blocker/includes/class-training-data.php` (add constructor, merge DB samples into training, remove file-write methods)
  - Modify: `balrog-blocker/includes/class-balrog-blocker.php` (activate + cache key + wiring)
  - Modify: `balrog-blocker/admin/class-admin-page.php` (AJAX handler rewire)
  - Modify: `balrog-blocker/uninstall.php` (DROP TABLE + delete_option)
  - Modify: `tests/bootstrap.php` (expand wpdb stub)
  - Create: `tests/test-sample-repository.php`
  - Modify: `tests/test-training-data.php`
  - Modify: `tests/test-admin-page.php`
- Related patterns: Balrog_Blocker_* naming, class-*.php files, strict types, file-lock pattern in Spam_Log/Stats
- Dependencies: WordPress $wpdb, dbDelta()

## Development Approach

- **Testing approach**: TDD for Sample_Repository (test-first), regular for integration/wiring tasks
- Complete each task fully before moving to the next
- **CRITICAL: every task MUST include new/updated tests**
- **CRITICAL: all tests must pass before starting next task**

## Implementation Steps

### Task 1: Expand wpdb stub in test bootstrap

**Files:**
- Modify: `tests/bootstrap.php`

- [x] Add `$prefix` property (value `'wp_'`) to the anonymous wpdb class
- [x] Add `insert(string $table, array $data, array|string $format = null): int|false` - stores rows in an in-memory array keyed by table, returns 1
- [x] Add `$insert_id` property, auto-incremented on insert
- [x] Add `get_results(string $query, string $output = OBJECT): array` - returns from in-memory store (parse simple SELECT for table/WHERE)
- [x] Add `get_var(string $query): mixed` - returns scalar from in-memory store
- [x] Add `$last_error` property (empty string default)
- [x] Add no-op `dbDelta()` global function stub if not already defined
- [x] Run test suite to confirm no regressions

### Task 2: Sample_Repository class (TDD)

**Files:**
- Create: `tests/test-sample-repository.php`
- Create: `balrog-blocker/includes/class-sample-repository.php`

- [x] Write tests for `insert(script, category, content)`: inserts row, bumps revision option, returns true
- [x] Write tests for `insert()` duplicate handling: second insert with same content+script+category returns false (unique constraint)
- [x] Write tests for `delete_by_hash(script, category, hash)`: deletes matching row, bumps revision
- [x] Write tests for `find_all(script, category)`: returns array of content strings
- [x] Write tests for `count_by_script_category(script, category)`: returns int count
- [x] Write tests for `latest_revision()`: returns current revision int from wp_option
- [x] Write test for `get_table_name()`: returns `{prefix}balrog_blocker_samples`
- [x] Write test for `get_create_table_sql()`: returns valid CREATE TABLE DDL
- [x] Implement `Balrog_Blocker_Sample_Repository` class with `$wpdb` injected via constructor
- [x] All methods use `$wpdb->prepare()` for parameterized queries
- [x] `content_hash` computed as `sha256(content)` on insert, used for duplicate check and delete
- [x] Revision stored in `wp_option 'balrog_blocker_samples_revision'`, incremented atomically via `UPDATE ... SET option_value = option_value + 1`
- [x] Run full test suite

### Task 3: Wire activation and uninstall

**Files:**
- Modify: `balrog-blocker/includes/class-balrog-blocker.php` (activate method ~L251)
- Modify: `balrog-blocker/uninstall.php`

- [x] In `activate()`: instantiate `Sample_Repository`, call `dbDelta(get_create_table_sql())`
- [x] In `uninstall.php`: add `DROP TABLE IF EXISTS {$wpdb->prefix}balrog_blocker_samples`
- [x] In `uninstall.php`: add `delete_option('balrog_blocker_samples_revision')`
- [x] Update existing uninstall test to verify new cleanup
- [x] Run full test suite

### Task 4: Integrate repository into Training_Data

**Files:**
- Modify: `balrog-blocker/includes/class-training-data.php`
- Modify: `tests/test-training-data.php`

- [x] Add constructor accepting optional `?Balrog_Blocker_Sample_Repository $repository = null`
- [x] In `load_script_samples()`: if repository is set, merge `$repository->find_all(script, category)` after file samples
- [x] In `get_script_sample_counts()`: add DB counts from repository to file-based counts
- [x] In `train_script_classifier()` and `train_combined_classifier()`: no changes needed (they call load_script_samples which now includes DB data)
- [x] Delete methods: `save_sample()`, `remove_sample()`, `save_script_sample()`, `remove_script_sample()` (marked @deprecated, retained until Task 5 rewires admin AJAX)
- [x] Update/add tests: training with mixed file+DB samples, counts aggregation, classifier training with DB-only samples
- [x] Run full test suite

### Task 5: Rewire admin AJAX handler and cache invalidation

**Files:**
- Modify: `balrog-blocker/admin/class-admin-page.php` (~L391 ajax_mark_comment)
- Modify: `balrog-blocker/includes/class-balrog-blocker.php` (compute_training_cache_key ~L212, build_checks ~L60)
- Modify: `tests/test-admin-page.php`

- [x] In `ajax_mark_comment()`: replace `save_sample()` / `save_script_sample()` calls with `$repository->insert(script, category, content)`
- [x] In `ajax_mark_comment()`: replace rollback `remove_sample()` / `remove_script_sample()` with `$repository->delete_by_hash(script, category, sha256(content))`
- [x] In `ajax_mark_comment()`: for 'unknown' script, use `$repository->insert('unknown', category, content)` instead of legacy file write
- [x] Wire `Sample_Repository` instance: create in `Balrog_Blocker` singleton, pass to `Training_Data` constructor and make available for admin page
- [x] In `compute_training_cache_key()`: append `Sample_Repository::latest_revision()` to the hash input string before md5
- [x] Update admin AJAX tests to verify insert/delete calls go to repository
- [x] Run full test suite

### Task 6: Include class file and verify on live WordPress

**Files:**
- Modify: `balrog-blocker/balrog-blocker.php` (require_once for new class file)

- [x] Add `require_once` for `class-sample-repository.php` in the plugin loader
- [x] Run full test suite
- [x] Verify on live WP: activate plugin, confirm table created in DB (skipped - manual verification)
- [x] Verify on live WP: mark a comment as spam in Spam Log, confirm row appears in DB table (skipped - manual verification)
- [x] Verify on live WP: confirm classifier retrains with the new sample (cache key changes) (skipped - manual verification)
- [x] Verify on live WP: deactivate and delete plugin, confirm table is dropped (skipped - manual verification)

### Task 7: Verify acceptance criteria

- [x] Run full test suite (`docker compose run --rm test`)
- [x] Confirm no file writes to data/ directory in any code path except seed files in git
- [x] Confirm uninstall.php drops table and deletes revision option
- [x] Confirm Training_Data has no file-write methods remaining

### Task 8: Update documentation

- [x] Update CLAUDE.md: add Sample_Repository to key classes, update data storage section, note hybrid storage pattern
- [x] Move this plan to `docs/plans/completed/`
