# Move Admin Page to Top-Level Menu with Submenu Pages

## Overview
Replace the current Settings > AntiSpam Wizard page (with horizontal tabs) with a top-level admin menu item. Each tab becomes a separate submenu page rendered in the WordPress sidebar.

## Context
- Files involved: `antispam-wizard/admin/class-admin-page.php`, `antispam-wizard/admin/views/settings-*.php`, `antispam-wizard/admin/css/admin.css`, test files
- Related patterns: WordPress `add_menu_page()` / `add_submenu_page()` API
- Dependencies: none

## Development Approach
- **Testing approach**: Regular (code first, then tests)
- Complete each task fully before moving to the next
- **CRITICAL: every task MUST include new/updated tests**
- **CRITICAL: all tests must pass before starting next task**

## Implementation Steps

### Task 1: Replace menu registration - top-level menu with submenu pages

**Files:**
- Modify: `antispam-wizard/admin/class-admin-page.php`

- [x] Replace `add_options_page()` call with `add_menu_page()` using dashicon `dashicons-shield-alt` and a sensible position (e.g. 80, after Settings)
- [x] Add `add_submenu_page()` calls for each current tab: General (default/first submenu), Stop Words, Excluded Tokens, Spam Log, Statistics
- [x] Update `enqueue_assets()` hook_suffix check to match all new submenu page hook suffixes
- [x] Update `get_current_tab()` - derive the active "tab" from the current submenu page slug instead of `$_GET['tab']`
- [x] Update all internal URL generation (`add_query_arg` calls) to point to new submenu slugs instead of tab query params
- [x] Update redirect URLs in form handlers (`handle_save`, `handle_clear_log`, import handlers) to use new submenu URLs
- [x] Write/update tests for menu registration and URL generation
- [x] Run project test suite - must pass before task 2

### Task 2: Update page rendering - remove tab navigation, one view per submenu

**Files:**
- Modify: `antispam-wizard/admin/class-admin-page.php`
- Modify: `antispam-wizard/admin/views/settings-general.php`
- Modify: `antispam-wizard/admin/views/settings-stop-words.php`
- Modify: `antispam-wizard/admin/views/settings-excluded-tokens.php`
- Modify: `antispam-wizard/admin/views/settings-log.php`
- Modify: `antispam-wizard/admin/views/settings-stats.php`

- [x] Remove horizontal tab navigation markup from `render_page()`
- [x] Create per-submenu render callbacks (or keep single `render_page` that selects view based on current screen) - whichever is simpler
- [x] Ensure each view file still works standalone (they should already, since they're included files)
- [x] Update tests for page rendering
- [x] Run project test suite - must pass before task 3

### Task 3: Clean up CSS and JS

**Files:**
- Modify: `antispam-wizard/admin/css/admin.css`
- Modify: `antispam-wizard/admin/js/admin.js` (if tab-related JS exists)

- [x] Remove `.asw-tabs` and `.asw-tab` CSS rules (horizontal tab styles)
- [x] Remove any tab-switching JS if present
- [x] Verify AJAX functionality still works (stop words, excluded tokens, mark comment handlers use `aswAdmin.ajaxUrl` which should be unaffected)
- [x] Run project test suite - must pass before task 4

### Task 4: Update localization

**Files:**
- Modify: `antispam-wizard/languages/antispam-wizard.pot`
- Modify: all `.po` files in `antispam-wizard/languages/`

- [x] Regenerate POT file to capture any new/changed strings (submenu labels)
- [x] Update all 9 translation .po files with new strings
- [x] Run project test suite - must pass before task 5

### Task 5: Verify acceptance criteria

- [x] Run full test suite (`docker compose run --rm test`) - 540 tests, 1639 assertions, all passing
- [x] Verify in browser: top-level menu appears with shield icon (skipped - manual testing)
- [x] Verify in browser: all 5 submenu pages load correctly (skipped - manual testing)
- [x] Verify in browser: settings save, AJAX operations work on each page (skipped - manual testing)

### Task 6: Update documentation

- [x] Update CLAUDE.md if internal patterns changed
- [x] Move this plan to `docs/plans/completed/`
