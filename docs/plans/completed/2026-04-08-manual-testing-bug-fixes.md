# Bug Fixes from Manual Testing

## Overview

Fix 10 bugs identified during manual testing, ranging from input validation issues to UX gaps in the admin interface.

## Context

- Files involved: class-settings.php, class-detector.php, class-admin-page.php, class-stats.php, class-bayesian-check.php, class-multi-lang-check.php, class-duplicate-check.php, class-tokenizer.php, settings-general.php, settings-stats.php, settings-log.php
- All files under balrog-blocker/includes/ and balrog-blocker/admin/views/
- Training data: data/ham-samples.txt (21 lines), data/spam-samples.txt (20 lines)

## Important finding on bug 2 (Multi-Language)

The Multi-Lang check detects words that INTERNALLY mix Unicode scripts (homograph attack, e.g. "hеllo" with Cyrillic "е"). It does NOT detect comments mixing separate words from different languages. The test text "Привет hello мир world..." has separate single-script words, so multi_lang correctly returns 0 mixed-script words. This is by design for homograph detection, not a bug in ordering. The real issue is Bayesian being too aggressive (bug 3), which makes it appear to overshadow other checks.

## Important finding on bug 8 (Duplicate check)

The Duplicate check hashes raw content without normalization. WordPress catches exact duplicates natively before pre_comment_approved fires. BB's duplicate check can only catch near-duplicates if it normalizes text more aggressively than WP. Currently it doesn't, so exact-match duplicate detection is indeed unreachable. The threshold-based counter (default 3) means it catches repeated similar posting patterns, not single exact dupes - which partially works around WP's limitation.

## Development Approach

- **Testing approach**: Regular (code first, then tests)
- Complete each task fully before moving to the next
- **CRITICAL: every task MUST include new/updated tests**
- **CRITICAL: all tests must pass before starting next task**

## Implementation Steps

### Task 1: Fix numeric field validation - server-side and HTML (bugs 1, 4)

**Files:**
- Modify: `balrog-blocker/includes/class-settings.php`
- Modify: `balrog-blocker/admin/views/settings-general.php`

- [x] In class-settings.php sanitize(), replace `absint()` with `intval()` for integer fields, then clamp with both min and max: `max($min, min($max, intval($value)))`. Define per-field bounds map (e.g. links_max: 1-50, emoji_max: 1-100, spacing_min_words_count: 1-100, multi_lang_threshold: 1-50, duplicate_threshold: 1-100, duplicate_ttl: 60-86400, min_message_length: 1-1000). Negative values should clamp to min, not flip sign.
- [x] In settings-general.php, add max attributes to all integer number inputs matching the server-side bounds.
- [x] Write tests: negative values clamp to min, oversized values clamp to max, valid values pass through.
- [x] Run test suite - must pass before task 2.

### Task 2: Add "Settings saved" admin notice (bug 5)

**Files:**
- Modify: `balrog-blocker/admin/class-admin-page.php`
- Modify: `balrog-blocker/admin/views/settings-general.php`

- [x] After successful settings save in handle_save(), redirect with a query parameter (e.g. ?settings-updated=true).
- [x] In the General tab view, display a WordPress-style admin notice when that parameter is present.
- [x] Write tests for save handler setting the redirect parameter.
- [x] Run test suite - must pass before task 3.

### Task 3: Stop Words input validation (bugs 6, 9)

**Files:**
- Modify: `balrog-blocker/admin/class-admin-page.php`

- [x] In ajax_add_stop_word(), add max length validation (64 characters). Return error if exceeded.
- [x] In ajax_add_stop_word(), reject tokens containing emoji (use same regex pattern as excluded tokens: only allow \p{L}\p{N}). Return descriptive error message.
- [x] Write tests for both validations (too long, contains emoji, valid word).
- [x] Run test suite - must pass before task 4.

### Task 4: Statistics display improvements (bugs 7, 10)

**Files:**
- Modify: `balrog-blocker/admin/views/settings-stats.php`

- [x] Show all enabled checks in the breakdown, including those with count=0, so users can see that checks are active but haven't triggered.
- [x] Add a note/label clarifying that breakdown counts are "matches" (a single comment can trigger multiple checks) while Total Blocked counts unique comments. This resolves the total != sum confusion without changing the data model.
- [x] Write tests for stats display including zero-count checks.
- [x] Run test suite - must pass before task 5.

### Task 5: Bayesian classifier guardrails (bug 3)

**Files:**
- Modify: `balrog-blocker/includes/checks/class-bayesian-check.php`
- Modify: `balrog-blocker/data/ham-samples.txt`

- [x] Add a minimum training sample requirement: if ham or spam training set has fewer than N samples (e.g. 10), skip Bayesian check and return not-spam with detail "insufficient training data". This prevents false positives when the classifier is undertrained.
- [x] Raise default bayesian_threshold from 0.5 to 0.7 to reduce false positives. Update the default in class-settings.php.
- [x] Add more diverse ham samples to ham-samples.txt (at least 20-30 additional neutral/legitimate comment examples) to improve classifier balance.
- [x] Write tests for the minimum sample guard and threshold behavior.
- [x] Run test suite - must pass before task 6.

### Task 6: Duplicate check - normalize content before hashing (bug 8)

**Files:**
- Modify: `balrog-blocker/includes/checks/class-duplicate-check.php`

- [x] Before hashing, normalize content: lowercase, collapse whitespace, trim. This makes the duplicate check catch near-duplicates that differ only in case/spacing, which WP's native exact-match detection misses.
- [x] Write tests verifying that near-duplicate texts (differing in case, extra spaces, trailing whitespace) produce matching hashes.
- [x] Run test suite - must pass before task 7.

### Task 7: Verify acceptance criteria

- [x] Run full test suite: `docker compose run --rm test`
- [x] Verify all 10 reported bugs are addressed
- [x] Move this plan to docs/plans/completed/
