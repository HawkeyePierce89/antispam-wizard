# Naive Bayes Classifier for Balrog Blocker

## Overview

Add a Naive Bayes spam/ham classifier check to the plugin. The classifier learns from sample files, integrates as a standard check via the existing interface, and supports dynamic retraining when admins mark comments as spam or ham.

## Context

- Files involved: `includes/checks/`, `includes/class-balrog-blocker.php`, `includes/class-settings.php`, `admin/class-admin-page.php`, `admin/views/`, `tests/`
- Related patterns: existing check classes (interface, result object, settings integration), admin tabs with AJAX handlers
- Dependencies: none (pure PHP implementation)

## Development Approach

- **Testing approach**: TDD where practical, regular for admin/UI integration
- Complete each task fully before moving to the next
- Follow existing class naming and file naming conventions (`Balrog_Blocker_*`, `class-*.php`)
- **CRITICAL: every task MUST include new/updated tests**
- **CRITICAL: all tests must pass before starting next task**

## Implementation Steps

### Task 1: Naive Bayes Classifier Core

The standalone classifier class with no WordPress dependencies.

**Files:**
- Create: `includes/class-naive-bayes.php`

- [x] Implement `Balrog_Blocker_Naive_Bayes` class with:
  - `learn(string $text, string $category)` — tokenize text, update token counts for category (spam/ham)
  - `unlearn(string $text, string $category)` — reverse a previous learn call
  - `classify(string $text): array` — return associative array of category probabilities
  - `reset()` — clear all learned data
  - Private tokenizer: lowercase, split on whitespace/punctuation, exclude short tokens (<=2 chars), exclude common stop-words (hardcoded list of ~50 English/Russian functional words)
  - Internal state: token counts per category, total documents per category
- [x] Write tests for classifier: learn spam/ham samples, verify classify returns correct probabilities, test unlearn decrements counts, test reset clears state, test tokenizer handles Unicode and edge cases
- [x] Run project test suite — must pass before task 2

### Task 2: Training Data Loading

Load spam/ham samples from text files bundled with the plugin.

**Files:**
- Create: `includes/class-training-data.php`
- Create: `data/spam-samples.txt` (one sample per line, ship ~20 examples)
- Create: `data/ham-samples.txt` (one sample per line, ship ~20 examples)

- [x] Implement `Balrog_Blocker_Training_Data` class with:
  - `load_samples(string $file_path): array` — read file, return array of lines
  - `save_sample(string $file_path, string $text): bool` — append a line to file
  - `train_classifier(Balrog_Blocker_Naive_Bayes $classifier): void` — load both files and call learn() for each sample
- [x] Create initial spam and ham sample files with representative examples
- [x] Write tests for training data loading and classifier training
- [x] Run project test suite — must pass before task 3

### Task 3: Bayesian Check Class

New check implementing the existing `Balrog_Blocker_Check` interface.

**Files:**
- Create: `includes/checks/class-bayesian-check.php`

- [x] Implement `Balrog_Blocker_Bayesian_Check` class:
  - Constructor accepts `Balrog_Blocker_Naive_Bayes` instance
  - `check(array $comment_data): Balrog_Blocker_Check_Result` — classify comment_content, return spam if spam probability exceeds configured threshold
  - Read `bayesian_enabled` and `bayesian_threshold` from settings
  - Return early (not spam) if check is disabled
  - Include spam probability in result details string
- [x] Write tests: verify spam detected above threshold, ham below threshold, disabled check returns not-spam, empty content handling
- [x] Run project test suite — must pass before task 4

### Task 4: Plugin Integration

Wire the classifier and check into the plugin bootstrap and settings.

**Files:**
- Modify: `includes/class-balrog-blocker.php`
- Modify: `includes/class-settings.php`
- Modify: `balrog-blocker.php` (add require for new files)

- [x] Add default settings: `bayesian_enabled` (true), `bayesian_threshold` (0.5)
- [x] Update `Balrog_Blocker::init()` to instantiate classifier, train it via Training_Data, and add Bayesian check to the checks array
- [x] Update `Balrog_Blocker::activate()` to trigger initial training
- [x] Add require_once for new class files in main plugin file
- [x] Write integration test: detector with bayesian check correctly flags spam comments
- [x] Run project test suite — must pass before task 5

### Task 5: Admin Settings UI

Add bayesian settings to admin general tab and handle save.

**Files:**
- Modify: `admin/views/settings-general.php`
- Modify: `admin/class-admin-page.php`

- [x] Add bayesian section to general settings tab: enable/disable toggle, threshold slider/input (0.0-1.0)
- [x] Update `handle_save()` to process `bayesian_enabled` (bool) and `bayesian_threshold` (float)
- [x] Write tests for settings save with new bayesian fields
- [x] Run project test suite — must pass before task 6

### Task 6: Dynamic Learning from Admin

Allow admins to mark comments as spam/ham, adding them to training data and retraining.

**Files:**
- Modify: `admin/class-admin-page.php`
- Modify: `admin/js/admin.js`

- [x] Add AJAX endpoint `balrog_blocker_mark_comment` that accepts comment_id and category (spam/ham)
  - Fetch comment content, append to appropriate sample file via Training_Data
  - Retrain classifier
  - If marking as ham and comment was spam, call `wp_unspam_comment`; if marking as spam, call `wp_spam_comment`
- [x] Add "Mark as Spam" / "Mark as Ham" buttons to spam log tab entries
- [x] Wire buttons to AJAX handler in admin.js
- [x] Write tests for the AJAX handler logic (mark spam, mark ham, nonce verification)
- [x] Run project test suite — must pass before task 7

### Task 7: Verify Acceptance Criteria

- [x] Run full test suite (`docker compose run --rm test`)
- [x] Verify all 6 requirements from the spec are implemented
- [x] Verify classifier correctly classifies with trained data in integration test

### Task 8: Update Documentation

- [x] Update CLAUDE.md: add Bayesian check to key classes list, mention data/ directory, update check count from 6 to 7
- [x] Move this plan to `docs/plans/completed/`
