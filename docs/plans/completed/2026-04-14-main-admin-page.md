# Add "Main" admin page with global enable/disable toggle

## Overview

Add a new "Main" submenu page as the first item in the AntiSpam Wizard admin menu. It provides a brief plugin description ("works out of the box, tweak settings if you want") and a single toggle to globally enable/disable spam protection (default: enabled). When disabled, the detector skips all checks. The existing "General" page becomes the second submenu item.

## Context

- Files involved: `antispam-wizard/admin/class-admin-page.php`, `antispam-wizard/includes/class-settings.php`, `antispam-wizard/includes/class-detector.php`, `antispam-wizard/admin/views/settings-main.php` (new)
- Related patterns: existing submenu registration in `SUBMENU_SLUGS`, view files at `admin/views/settings-{tab}.php`, toggle rendering in `settings-general.php`, boolean settings handling in `handle_save()`
- Dependencies: none

## Development Approach

- **Testing approach**: Regular (code first, then tests)
- Complete each task fully before moving to the next
- **CRITICAL: every task MUST include new/updated tests**
- **CRITICAL: all tests must pass before starting next task**

## Implementation Steps

### Task 1: Add `enabled` setting and guard in detector

**Files:**
- Modify: `antispam-wizard/includes/class-settings.php`
- Modify: `antispam-wizard/includes/class-detector.php`

- [x] Add `'enabled' => true` to `ASW_Settings::get_defaults()`
- [x] In `ASW_Detector::filter_comment()`, add an early return (pass through `$approved`) when `ASW_Settings::get('enabled')` is false, right after the existing upstream-status guard
- [x] Write tests: detector passes comments through when `enabled` is false; detector runs checks when `enabled` is true
- [x] Run project test suite - must pass before task 2

### Task 2: Register "Main" submenu page and handle form save

**Files:**
- Modify: `antispam-wizard/admin/class-admin-page.php`

- [x] Add `'main' => 'antispam-wizard-main'` as the first entry in `SUBMENU_SLUGS`
- [x] Update `add_menu_page()` callback slug and the `$submenus` array so "Main" is first, followed by existing pages
- [x] In `handle_save()`, add `'enabled'` to the `$bool_keys` array
- [x] Update `get_current_tab()` default fallback to `'main'`
- [x] Write tests: verify SUBMENU_SLUGS order has 'main' first, verify 'enabled' is in bool_keys for save handling
- [x] Run project test suite - must pass before task 3

### Task 3: Create the Main page view

**Files:**
- Create: `antispam-wizard/admin/views/settings-main.php`

- [x] Create view file following the pattern of `settings-general.php` (reads settings, uses form with nonce, toggle helper)
- [x] Add brief plugin description text (translatable): plugin is ready out of the box, fine-tune via other settings pages
- [x] Add enable/disable toggle for the `enabled` setting using the same `asw-toggle` markup as General page
- [x] Add submit button
- [x] Write tests: view file renders without errors, contains the toggle input and nonce field
- [x] Run project test suite - must pass before task 4

### Task 4: Update translations (all 9 languages)

**Files:**
- Modify: `antispam-wizard/languages/antispam-wizard.pot`
- Modify: all 9 `.po` files in `antispam-wizard/languages/`

- [x] Regenerate POT file to pick up new translatable strings from the Main page
- [x] Translate all new msgid entries into all 9 supported languages (es_ES, de_DE, fr_FR, pt_BR, it_IT, ja, ru_RU, nl_NL, tr_TR) with actual translated msgstr values
- [x] Run project test suite - must pass before task 5

### Task 5: Verify acceptance criteria

- [x] Run full test suite (`docker compose run --rm test`)
- [x] Verify "Main" appears first in admin menu (manual or test)
- [x] Verify toggling the enable setting off causes detector to pass comments through
- [x] Verify default state is enabled (no settings saved yet = plugin active)

### Task 6: Update documentation

- [x] Update CLAUDE.md: add `enabled` to settings description, update submenu count from 5 to 6, mention Main page
- [x] Move this plan to `docs/plans/completed/`
