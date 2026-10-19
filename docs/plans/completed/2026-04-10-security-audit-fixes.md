# Security Audit Fixes for BalrogBlocker

## Overview

Address 12 findings from the security audit, grouped into logical tasks by severity and area. Focuses on DoS prevention via input length caps and token limits, data integrity validation for deserialized state, file locking hardening, and minor SQL/logging hygiene.

## Context

- Files involved: `includes/class-detector.php`, `includes/checks/class-multi-lang-check.php`, `includes/class-tokenizer.php`, `includes/class-cosine-similarity.php`, `includes/class-spam-log.php`, `includes/class-stats.php`, `includes/class-balrog-blocker.php`, `includes/checks/class-duplicate-check.php`, `includes/class-sample-repository.php`, `includes/class-training-data.php`, `includes/class-naive-bayes.php`
- Related patterns: fail-open design, WordPress coding standards, existing file-lock pattern
- Dependencies: none

## Development Approach

- **Testing approach**: Regular (code first, then tests)
- Complete each task fully before moving to the next
- **CRITICAL: every task MUST include new/updated tests**
- **CRITICAL: all tests must pass before starting next task**

## Implementation Steps

### Task 1: DoS prevention — input length cap and token limits (findings 1-3)

**Files:**
- Modify: `includes/class-detector.php`
- Modify: `includes/class-tokenizer.php`

- [x] Add a `MAX_COMMENT_LENGTH` constant (64KB) to `Balrog_Blocker_Detector` and add an early `mb_strlen()` guard in `filter_comment()` — truncate content before passing to checks
- [x] Add a `MAX_TOKENS` constant (10000) to `Balrog_Blocker_Tokenizer` and cap the output of both `tokenize_ngrams()` and `tokenize()` to that limit
- [x] Write tests: oversized input is truncated by detector, tokenizer output is capped at MAX_TOKENS
- [x] Run project test suite — must pass before task 2

### Task 2: Data integrity — validate deserialized state (findings 4-5)

**Files:**
- Modify: `includes/class-spam-log.php`
- Modify: `includes/class-stats.php`
- Modify: `includes/class-naive-bayes.php`

- [x] In `Balrog_Blocker_Spam_Log::get_entries()`, validate each entry has expected keys (`entry_id`, `date`, `text_excerpt`, `checks_triggered`, `ip_hash`) and filter out malformed entries
- [x] In `Balrog_Blocker_Stats::get_all()`, validate `total_blocked` is int and `per_check` is array, reset to defaults if corrupted
- [x] In `Balrog_Blocker_Naive_Bayes::set_state()`, validate that `token_counts` and `token_totals` and `doc_counts` have expected types (arrays/ints), reject corrupted state and leave classifier empty
- [x] Write tests for each: corrupted option data returns safe defaults, corrupted classifier state is rejected
- [x] Run project test suite — must pass before task 3

### Task 3: Duplicate check transient growth mitigation (finding 6)

**Files:**
- Modify: `includes/checks/class-duplicate-check.php`

- [x] Add a short TTL cap (e.g. ensure TTL doesn't exceed a reasonable bound, and document that transient cleanup relies on WordPress's built-in expiration). The current implementation already uses bounded lock file pool (256 buckets) which limits lock file growth. Verify the transient keys use the SHA-256 hash prefix (already 32 chars) and the TTL is reasonable — add a comment documenting that on sites without external object cache, expired transients are lazily cleaned by WordPress
- [x] Write test confirming transient TTL is bounded
- [x] Run project test suite — must pass before task 4

### Task 4: File locking hardening (finding 7)

**Files:**
- Modify: `includes/class-spam-log.php`
- Modify: `includes/class-stats.php`
- Modify: `includes/checks/class-duplicate-check.php`

- [x] Replace `@fopen()` with `fopen()` and log a warning via `error_log()` on failure in all three files
- [x] Change lock file location from `sys_get_temp_dir()` to WordPress uploads directory (`wp_upload_dir()['basedir']`) with a `.balrog-locks/` subdirectory — this is writable and not shared with other OS users. Create directory if needed.
- [x] Write tests for lock file failure path (fopen returns false)
- [x] Run project test suite — must pass before task 5

### Task 5: SQL identifier hygiene (findings 8-9)

**Files:**
- Modify: `includes/class-sample-repository.php`

- [x] Wrap interpolated table name in backticks in all SQL queries (lines ~80, 109, 166, 194 and CREATE TABLE)
- [x] Validate `$this->wpdb->charset` and `$this->wpdb->collate` against an allowlist of known MySQL charset/collate values before interpolation in `get_create_table_sql()`
- [x] Write tests for backtick-quoted table names in generated SQL
- [x] Run project test suite — must pass before task 6

### Task 6: Information disclosure and training data hardening (findings 10-12)

**Files:**
- Modify: `includes/class-detector.php`
- Modify: `includes/class-training-data.php`
- Modify: `includes/class-naive-bayes.php`

- [x] In `Balrog_Blocker_Detector::run()`, replace `get_class($e) . ': ' . $e->getMessage()` with a generic message that includes only the check name (class) but omits the exception message
- [x] In `Balrog_Blocker_Training_Data::load_samples()`, add a file size check (e.g. 2MB cap via `filesize()`) before `file_get_contents()`, return empty array if exceeded
- [x] In `Balrog_Blocker_Naive_Bayes::learn()`, add a cap on total dictionary size per category (e.g. 50000 unique tokens) — stop accumulating new tokens once the cap is reached
- [x] Write tests for each: exception logging doesn't leak messages, oversized training file is skipped, token dictionary cap is enforced
- [x] Run project test suite — must pass before task 7

### Task 7: Verify acceptance criteria

- [x] Run full test suite (`docker compose run --rm test`)
- [x] Verify all 12 audit findings are addressed
- [x] Move this plan to `docs/plans/completed/`
