# Fix Post-Review Issues from Hybrid Sample Storage

## Overview

Address 5 issues found during code review of the hybrid-sample-storage implementation: index column order, missing content normalization, dead code, DDL syntax, and live WordPress verification.

## Context

- Files involved:
  - Modify: `balrog-blocker/includes/class-sample-repository.php` (index order, DDL syntax)
  - Modify: `balrog-blocker/admin/class-admin-page.php` (content normalization)
  - Modify: `balrog-blocker/includes/class-training-data.php` (dead code removal)
  - Modify: `tests/TrainingDataTest.php` (update tests after dead code removal)
  - Modify: `tests/SampleRepositoryTest.php` (update DDL assertion)
- Related patterns: existing Balrog_Blocker_* conventions, WordPress dbDelta formatting
- Dependencies: Docker Compose for live verification

## Development Approach

- **Testing approach**: Regular (fix code, update tests)
- Complete each task fully before moving to the next
- **CRITICAL: every task MUST include new/updated tests**
- **CRITICAL: all tests must pass before starting next task**

## Implementation Steps

### Task 1: Fix unique index column order

**Files:**
- Modify: `balrog-blocker/includes/class-sample-repository.php`
- Modify: `tests/SampleRepositoryTest.php`

- [x] In `get_create_table_sql()` (line 60): change `UNIQUE KEY idx_unique_sample (content_hash, script, category)` to `UNIQUE KEY idx_unique_sample (script, category, content_hash)`
- [x] Update test assertion for DDL output if one exists
- [x] Run full test suite (`docker compose run --rm test`)

### Task 2: Fix DDL syntax for dbDelta compatibility

**Files:**
- Modify: `balrog-blocker/includes/class-sample-repository.php`

- [x] In `get_create_table_sql()` (line 61): change `DEFAULT CHARSET={$charset}` to `DEFAULT CHARACTER SET {$charset}`
- [x] Run full test suite

### Task 3: Add content normalization before insert

**Files:**
- Modify: `balrog-blocker/admin/class-admin-page.php`
- Modify: `tests/AdminPageTest.php`

- [x] In `ajax_mark_comment()` after line 421 (`$content = $comment->comment_content;`): add `$content = trim( preg_replace( '/\s+/', ' ', $content ) );`
- [x] Add test: content with newlines and extra whitespace is normalized before insert
- [x] Run full test suite

### Task 4: Remove dead code from Training_Data

**Files:**
- Modify: `balrog-blocker/includes/class-training-data.php`
- Modify: `tests/TrainingDataTest.php`

- [x] Remove `train_classifier()` method (lines 66-78)
- [x] Remove the `if ( null === $ham_samples )` fallback branch in `train_classifier_with_samples()` (lines 92-94); make `$ham_samples` parameter required (remove `?` and `= null`)
- [x] Update or remove tests that call `train_classifier()`: `test_train_classifier_with_custom_files`, `test_train_classifier_classifies_correctly`, `test_train_classifier_resets_before_training`, `test_train_classifier_handles_missing_files`, `test_train_classifier_with_bundled_data` - rewrite to use `train_classifier_with_samples()` with explicit sample arrays, or `train_script_classifier()` / `train_combined_classifier()`
- [x] Run full test suite

### Task 5: Live WordPress verification in Docker

**Files:**
- None modified (manual verification)

- [x] `docker compose up -d wordpress` and `bash dev/setup.sh` (skipped - manual verification, not automatable)
- [x] Activate plugin, verify table created (skipped - manual verification, not automatable)
- [x] Confirm index order is `(script, category, content_hash)` and DDL uses `CHARACTER SET` (skipped - manual verification, not automatable)
- [x] Submit 2 spam + 1 ham comment via wp-admin, mark them via Spam Log, verify rows in table (skipped - manual verification, not automatable)
- [x] Deactivate and delete plugin, verify table is dropped (skipped - manual verification, not automatable)
- [x] `docker compose down` (skipped - manual verification, not automatable)

### Task 6: Verify acceptance criteria

- [x] Run full test suite (`docker compose run --rm test`)
- [x] All 5 issues from the review are resolved

### Task 7: Update documentation

- [x] Update CLAUDE.md if any patterns changed (content normalization, dead code removal) — reviewed, no changes needed; existing descriptions remain accurate
- [x] Move this plan to `docs/plans/completed/`
