# Fix Unicode Script MVP Test Findings

## Overview

Address 4 defects found during manual testing of the Unicode script-based spam detection feature. Two are functional gaps (script_label not shown in UI, empty script groups hidden), one is a threshold issue for short CJK/Thai comments, and one is a UX bug (blocking alert).

## Context

- Files involved:
  - `balrog-blocker/admin/js/admin.js` (lines 295-305) - mark spam/ham UI + alert
  - `balrog-blocker/includes/class-training-data.php` (lines 270-272) - get_script_sample_counts filtering
  - `balrog-blocker/includes/checks/class-bayesian-check.php` (lines 86-88) - min_message_length check
  - `balrog-blocker/includes/checks/class-similarity-check.php` (lines 103-104) - min_message_length check
  - `balrog-blocker/admin/css/admin.css` - styles for new pill and notification elements
- Related patterns: existing pill rendering in admin.js, existing AJAX error handling
- Dependencies: none

## Development Approach

- **Testing approach**: Regular (code first, then tests)
- Complete each task fully before moving to the next
- **CRITICAL: every task MUST include new/updated tests**
- **CRITICAL: all tests must pass before starting next task**

## Implementation Steps

### Task 1: Show script_label in UI after mark as spam/ham (finding #1)

**Files:**
- Modify: `balrog-blocker/admin/js/admin.js`
- Modify: `balrog-blocker/admin/css/admin.css`

- [x] In admin.js lines 295-301, after creating the "Trained as" pill, check if `response.data.script_label` exists and append it as a second pill or inline label next to the trained pill
- [x] Add CSS styling for the script label pill (e.g. `bb-pill--script` class)
- [x] No JS test infrastructure exists; verified manually - backend already returns script_label and PHP tests cover the AJAX response

### Task 2: Show all 10 script groups in Loaded Models including empty ones (finding #2)

**Files:**
- Modify: `balrog-blocker/includes/class-training-data.php`

- [x] In `get_script_sample_counts()` (line 270-272), remove the `continue` that skips groups with 0 spam and 0 ham - always include all script groups in the returned array
- [x] Keep the `continue` for non-existent files (line 263-265) but ensure empty files still result in a 0/0 entry (create empty files if needed, or treat missing files as 0 counts)
- [x] Update existing tests for `get_script_sample_counts()` to verify that all 10 groups are returned even when some have no samples

### Task 3: Fix min_message_length for CJK/Thai short comments (finding #3)

**Files:**
- Modify: `balrog-blocker/includes/checks/class-bayesian-check.php`
- Modify: `balrog-blocker/includes/checks/class-similarity-check.php`

- [x] In both bayesian-check.php and similarity-check.php, detect the script of the incoming text using `Balrog_Blocker_Script_Detector`
- [x] For CJK and Thai scripts, apply a lower minimum length threshold (e.g. 10 characters instead of 50) since these scripts encode more meaning per character
- [x] Write tests: a 20-character CJK comment should pass the minimum length check and reach the classifier; a 20-character Latin comment should still be skipped as too short

### Task 4: Replace alert() with non-blocking notification (finding #4)

**Files:**
- Modify: `balrog-blocker/admin/js/admin.js`
- Modify: `balrog-blocker/admin/css/admin.css`

- [x] Replace `alert(msg)` on line 305 with a non-blocking notification: insert a temporary error message element near the button (e.g. a styled div with class `bb-notice bb-notice--error`) that auto-dismisses after a few seconds
- [x] Add CSS for `.bb-notice` and `.bb-notice--error` styling
- [x] Verify no other `alert()` calls exist in admin.js

### Task 5: Verify acceptance criteria

- [x] Run full test suite (`docker compose run --rm test`) - must pass
- [x] Verify all 4 findings are addressed

### Task 6: Update documentation

- [x] Update CLAUDE.md if any patterns changed (reviewed - no changes needed, architecture docs are accurate)
- [x] Move this plan to `docs/plans/completed/`
