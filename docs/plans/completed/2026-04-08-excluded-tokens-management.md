# Excluded Tokens Management

## Overview

Move the hardcoded EN/RU stop-words list from the tokenizer into plugin settings, add admin UI for managing excluded tokens (view/add/remove, import/export), and retrain the Bayesian classifier when the list changes.

## Context

- Files involved: `balrog-blocker/includes/class-tokenizer.php`, `balrog-blocker/includes/class-settings.php`, `balrog-blocker/admin/class-admin-page.php`, `balrog-blocker/admin/js/admin.js`, `balrog-blocker/admin/views/settings-stop-words.php`, `balrog-blocker/includes/class-balrog-blocker.php`, `tests/TokenizerTest.php`
- Related patterns: stop-words management (AJAX add/remove, import/export) already implemented and serves as the template
- Dependencies: none

## Development Approach

- **Testing approach**: TDD (test first)
- Complete each task fully before moving to the next
- Follow existing stop-words management patterns for admin UI and AJAX handlers
- **CRITICAL: every task MUST include new/updated tests**
- **CRITICAL: all tests must pass before starting next task**

## Implementation Steps

### Task 1: Move excluded tokens list into settings with default values

**Files:**
- Modify: `balrog-blocker/includes/class-settings.php`
- Modify: `balrog-blocker/includes/class-tokenizer.php`
- Modify: `tests/TokenizerTest.php`

- [x] Write tests: tokenizer accepts excluded tokens list via constructor; when custom list provided, those tokens are excluded instead of the hardcoded defaults
- [x] Add `excluded_tokens_list` to `Balrog_Blocker_Settings::get_defaults()` with current hardcoded EN/RU stop-words as default value
- [x] Modify `Balrog_Blocker_Tokenizer` to accept an optional `array $excluded_tokens` parameter in the constructor, falling back to `Balrog_Blocker_Settings::get('excluded_tokens_list')` if not provided
- [x] Remove the hardcoded `STOP_WORDS` constant from the tokenizer
- [x] Update existing tokenizer tests and add new tests for custom excluded tokens
- [x] Run project test suite - must pass before task 2

### Task 2: Update bootstrap to pass excluded tokens from settings to tokenizer

**Files:**
- Modify: `balrog-blocker/includes/class-balrog-blocker.php`

- [x] In `Balrog_Blocker::init()`, read `excluded_tokens_list` from settings and pass it when creating `Balrog_Blocker_Tokenizer` and `Balrog_Blocker_Naive_Bayes` instances
- [x] Ensure both Bayesian and Similarity checks receive the same tokenizer instance with current excluded tokens
- [x] Run project test suite - must pass before task 3

### Task 3: Add admin UI tab section for excluded tokens management

**Files:**
- Modify: `balrog-blocker/admin/class-admin-page.php`
- Create: `balrog-blocker/admin/views/settings-excluded-tokens.php`
- Modify: `balrog-blocker/admin/js/admin.js`

- [x] Add "Excluded Tokens" tab to the admin page tabs array (in `get_current_tab()` and `render_page()`)
- [x] Create `settings-excluded-tokens.php` view following the stop-words view pattern (tag cloud with add/remove, import/export forms)
- [x] Add AJAX nonce constant and localized strings for excluded tokens
- [x] Add `ajax_add_excluded_token` and `ajax_remove_excluded_token` AJAX handlers following stop-words pattern
- [x] Add JS handlers for excluded tokens add/remove AJAX in `admin.js`, following existing stop-words JS pattern
- [x] Write tests for AJAX add/remove handlers
- [x] Run project test suite - must pass before task 4

### Task 4: Add import/export for excluded tokens

**Files:**
- Modify: `balrog-blocker/admin/class-admin-page.php`

- [x] Add `handle_excluded_tokens_import()` method following `handle_stop_words_import()` pattern
- [x] Add `handle_excluded_tokens_export()` method following `handle_stop_words_export()` pattern
- [x] Register both handlers in `init()` via `add_action('admin_init', ...)`
- [x] Write tests for import handler
- [x] Run project test suite - must pass before task 5

### Task 5: Retrain Bayesian classifier when excluded tokens change

**Files:**
- Modify: `balrog-blocker/admin/class-admin-page.php`

- [x] After any excluded tokens change (add, remove, import), trigger Bayesian classifier retraining: load training data, create new tokenizer with updated list, retrain classifier
- [x] Extract retraining logic into a private helper method to avoid duplication across add/remove/import handlers
- [x] Write tests verifying retraining is triggered on excluded token changes
- [x] Run project test suite - must pass before task 6

### Task 6: Verify acceptance criteria

- [x] Run full test suite (`docker compose run --rm test`)
- [x] Verify all 5 requirements are covered: settings migration, admin UI, import/export, runtime application, tests
- [x] Move this plan to `docs/plans/completed/`
