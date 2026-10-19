# Add collapsible help descriptions to General settings tab

## Overview

Add inline help text for each setting on the General tab, hidden behind a collapsible toggle (HTML details/summary). Each description explains what the check does and when to adjust its parameters.

## Context

- Files involved: `balrog-blocker/admin/views/settings-general.php`, `balrog-blocker/admin/css/admin.css`
- Related patterns: the Min Message Length row already has a `<p class="description">` hint -- the new collapsible descriptions will complement this pattern
- Dependencies: none

## Development Approach

- **Testing approach**: Regular (code first, then tests)
- Complete each task fully before moving to the next
- Use native HTML `<details><summary>` elements -- no JavaScript needed, accessible out of the box
- **CRITICAL: every task MUST include new/updated tests**
- **CRITICAL: all tests must pass before starting next task**

## Implementation Steps

### Task 1: Add CSS styles for collapsible help blocks

**Files:**
- Modify: `balrog-blocker/admin/css/admin.css`

- [x] Add styles for `.balrog-blocker-help` details/summary elements: subtle styling, question-mark or info icon via CSS, muted text color for the description body
- [x] Ensure styles look consistent with WordPress admin UI

### Task 2: Add collapsible descriptions to each setting row

**Files:**
- Modify: `balrog-blocker/admin/views/settings-general.php`

- [x] Add a `<details class="balrog-blocker-help"><summary>` block to each of the 9 setting rows with the following descriptions:
  - Stop-words: blocks comments containing words from your blacklist; supports exact match (prefix "=") and substring modes; configure the list on the Stop-words tab
  - Links: flags comments with too many URLs; spammers often stuff comments with links to boost SEO; adjust the max to match your site's typical comment style
  - Emoji: catches comments overloaded with emoji, a common pattern in automated spam
  - Abnormal Spacing: detects obfuscated text where characters are separated by spaces (e.g. "п р и в е т") to bypass word-based filters; parameters control sensitivity: min words count sets how long a comment must be before this check applies, short word length/ratio detect single-character "words", space ratio catches excessive whitespace
  - Mixed Scripts: flags words that mix characters from different Unicode scripts (e.g. Latin + Cyrillic) in a single word, a technique used to visually mimic legitimate text; threshold sets how many such words trigger blocking
  - Duplicates: blocks repeated identical comments from the same IP within a time window; threshold sets how many duplicates are allowed, TTL sets the window duration
  - Min Message Length: comments shorter than this skip the heavier Bayesian and Similarity checks, since very short messages rarely provide enough signal for statistical analysis
  - Bayesian Filter: classifies comments using a Naive Bayes model trained on your spam/ham samples; higher threshold means stricter filtering (fewer false positives but may miss some spam)
  - Similarity Filter: compares each comment against known spam samples using cosine similarity on tokenized text; higher threshold means the comment must be more similar to known spam to be blocked
- [x] Write tests verifying the help descriptions render in the General tab output
- [x] Run project test suite -- must pass

### Task 3: Verify acceptance criteria

- [x] Run full test suite (`docker compose run --rm test`)
- [x] Verify all 9 settings have collapsible descriptions in the rendered HTML

### Task 4: Update documentation

- [x] Update CLAUDE.md if internal patterns changed (no changes needed - no new patterns introduced)
- [x] Move this plan to `docs/plans/completed/`
