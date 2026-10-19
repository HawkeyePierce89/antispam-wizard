# Similarity Check - Cosine Similarity for Spam Detection

## Overview

Add a new spam check that compares incoming comments against known spam samples using cosine similarity. If the similarity score with any spam sample exceeds a configurable threshold, the comment is flagged as spam. Uses the same spam sample database as the Bayesian check.

## Context

- Files involved: includes/checks/, includes/class-naive-bayes.php, includes/class-balrog-blocker.php, includes/class-settings.php, admin/class-admin-page.php
- Related patterns: existing check classes (strategy pattern), Bayesian check as closest analog
- WordPress: 6.4+, PHP 8.1+

## Development Approach

- **Testing approach**: TDD (test first)
- Complete each task fully before moving to the next
- Follow existing check class conventions (constructor injection, settings-based toggle)
- **CRITICAL: every task MUST include new/updated tests**
- **CRITICAL: all tests must pass before starting next task**

## Implementation Steps

### Task 1: Extract tokenizer into a shared component

Currently tokenization lives as a private method inside Balrog_Blocker_Naive_Bayes. Extract it into a standalone Balrog_Blocker_Tokenizer class so both the Naive Bayes classifier and the new similarity check can reuse it.

**Files:**
- Create: `balrog-blocker/includes/class-tokenizer.php`
- Modify: `balrog-blocker/includes/class-naive-bayes.php` (delegate to new tokenizer)
- Modify: `balrog-blocker/balrog-blocker.php` (require new file)

- [x] Write tests for Balrog_Blocker_Tokenizer (same behavior as current tokenize method)
- [x] Create Balrog_Blocker_Tokenizer with a public tokenize() method
- [x] Refactor Balrog_Blocker_Naive_Bayes to use Balrog_Blocker_Tokenizer internally
- [x] Run test suite - all existing tests must still pass

### Task 2: Implement cosine similarity calculation

Create a utility class that computes cosine similarity between two token arrays using TF vectors.

**Files:**
- Create: `balrog-blocker/includes/class-cosine-similarity.php`
- Modify: `balrog-blocker/balrog-blocker.php` (require new file)

- [x] Write tests for cosine similarity: identical texts = 1.0, completely different texts = 0.0, partially overlapping texts between 0 and 1, empty input handling
- [x] Implement Balrog_Blocker_Cosine_Similarity with a compare(array $tokens_a, array $tokens_b): float method
- [x] Run test suite

### Task 3: Create the Similarity Check class

New check that loads spam samples, tokenizes them and the incoming comment, computes cosine similarity against each sample, and flags spam if any score exceeds the threshold.

**Files:**
- Create: `balrog-blocker/includes/checks/class-similarity-check.php`
- Modify: `balrog-blocker/balrog-blocker.php` (require new file)

- [x] Write tests: spam detected above threshold, not spam below threshold, disabled check returns not-spam, threshold boundary behavior
- [x] Implement Balrog_Blocker_Similarity_Check implementing Balrog_Blocker_Check interface
- [x] Constructor receives Balrog_Blocker_Tokenizer, Balrog_Blocker_Cosine_Similarity, and spam samples array
- [x] Run test suite

### Task 4: Add settings and register the check

Add similarity check settings (enabled toggle, threshold) and wire the check into the detector pipeline.

**Files:**
- Modify: `balrog-blocker/includes/class-settings.php` (add similarity_enabled, similarity_threshold defaults and sanitization)
- Modify: `balrog-blocker/includes/class-balrog-blocker.php` (instantiate and register the check)
- Modify: `balrog-blocker/admin/class-admin-page.php` (add UI controls on settings page)

- [x] Write tests for settings defaults and sanitization
- [x] Add similarity_enabled (default: true) and similarity_threshold (default: 0.6, range 0.0-1.0) to settings
- [x] Instantiate Balrog_Blocker_Similarity_Check in the main plugin class and pass to detector
- [x] Add settings UI controls for the similarity check tab/section
- [x] Run test suite

### Task 5: Verify acceptance criteria

- [x] Run full test suite: docker compose run --rm test
- [x] Verify similarity check works with existing spam/ham sample files
- [x] Verify "mark as spam" dynamically updates samples used by similarity check (same data source as Bayesian)

### Task 6: Update documentation

- [x] Update CLAUDE.md: add Similarity check to the list of check classes (8 checks total), mention Tokenizer and Cosine_Similarity classes
