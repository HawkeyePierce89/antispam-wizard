# Min Message Length for Bayesian and Similarity Checks

## Overview

Add a configurable minimum message length setting. When a comment's text is shorter than the threshold, Bayesian and Similarity checks are skipped (returning not-spam), preventing false positives on short comments. All other checks continue to run normally.

## Context

- Files involved:
  - `balrog-blocker/includes/class-settings.php` - defaults and sanitization
  - `balrog-blocker/includes/checks/class-bayesian-check.php` - length guard
  - `balrog-blocker/includes/checks/class-similarity-check.php` - length guard
  - `balrog-blocker/admin/views/settings-general.php` - admin UI field
  - `tests/BayesianCheckTest.php` - new test cases
  - `tests/SimilarityCheckTest.php` - new test cases
- Related patterns: each check reads settings via `Balrog_Blocker_Settings::get_all()` and has early-return guards (enabled check, empty text)
- Dependencies: none

## Development Approach

- **Testing approach**: TDD - write tests for short-message skip first, then implement
- Complete each task fully before moving to the next
- **CRITICAL: every task MUST include new/updated tests**
- **CRITICAL: all tests must pass before starting next task**

## Implementation Steps

### Task 1: Add setting to defaults and admin UI

**Files:**
- Modify: `balrog-blocker/includes/class-settings.php`
- Modify: `balrog-blocker/admin/views/settings-general.php`

- [x] Add `'min_message_length' => 50` to the defaults array in `get_defaults()`, placed before the Bayesian block with a comment "Minimum message length for heavy checks"
- [x] Add a new `<tr>` row in `settings-general.php` before the Bayesian Filter row, with label "Min Message Length" and a number input for `min_message_length` (min="1", step="1")
- [x] Sanitization is automatic - the value is `int` so the existing `is_int` branch in `sanitize()` handles it with `max(1, absint(...))`
- [x] Write a test verifying `get_defaults()` contains `min_message_length` with value 50, and that sanitization clamps values correctly
- [x] Run project test suite - must pass before task 2

### Task 2: Add length guard to Bayesian check

**Files:**
- Modify: `balrog-blocker/includes/checks/class-bayesian-check.php`
- Modify: `tests/BayesianCheckTest.php`

- [x] Write tests: short comment (below threshold) returns not-spam with detail "comment too short for bayesian"; comment at/above threshold proceeds normally
- [x] In `check()`, after the empty-text guard, read `min_message_length` from settings and return early with `Balrog_Blocker_Check_Result(false, 'bayesian', ...)` if `mb_strlen($text) < $min_message_length`
- [x] Run project test suite - must pass before task 3

### Task 3: Add length guard to Similarity check

**Files:**
- Modify: `balrog-blocker/includes/checks/class-similarity-check.php`
- Modify: `tests/SimilarityCheckTest.php`

- [x] Write tests: short comment (below threshold) returns not-spam with detail "comment too short for similarity"; comment at/above threshold proceeds normally
- [x] In `check()`, after the empty-text guard, read `min_message_length` from settings and return early with `Balrog_Blocker_Check_Result(false, 'similarity', ...)` if `mb_strlen($text) < $min_message_length`
- [x] Run project test suite - must pass before task 4

### Task 4: Verify acceptance criteria

- [x] Run full test suite (`docker compose run --rm test`)
- [x] Verify all new tests cover: short message skip, boundary (exact threshold length), normal operation above threshold
- [x] Verify other checks (stop-words, links, emoji, etc.) are unaffected by the new setting
