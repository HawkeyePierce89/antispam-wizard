# Rename Plugin to AntiSpam Wizard

## Overview

Rename the WordPress plugin from "Spam Shall Not Pass" (SSNP) to "AntiSpam Wizard" (ASW). All class prefixes, file names, directory names, constants, option keys, text domain, translation files, and references will be updated. No migration logic needed — plugin is pre-release with no live installs.

## Context

- Files involved: ~68 files across spam-shall-not-pass/, tests/, docker-compose.yml, dev/, docs/
- New naming convention:
  - Directory: `antispam-wizard`
  - Class prefix: `ASW_`
  - Text domain: `antispam-wizard`
  - Constants: `ASW_VERSION`, `ASW_FILE`, `ASW_PATH`, `ASW_URL`, `ASW_TESTING`
  - Option keys: `asw_settings`, `asw_stats`, `asw_spam_log`, `asw_samples_revision`
  - DB table: `{prefix}asw_samples`
  - Lock directory: `.asw-locks`
  - Transient prefix: `_transient_asw_dup_`
- Related patterns: WordPress class naming (no namespaces), file naming class-{name}.php

## Development Approach

- **Testing approach**: Regular (rename first, fix tests alongside)
- Complete each task fully before moving to the next
- **CRITICAL: every task MUST include new/updated tests**
- **CRITICAL: all tests must pass before starting next task**

## Implementation Steps

### Task 1: Rename plugin directory and entry point

**Files:**
- Rename: `spam-shall-not-pass/` -> `antispam-wizard/`
- Rename: `antispam-wizard/spam-shall-not-pass.php` -> `antispam-wizard/antispam-wizard.php`

- [x] Rename plugin directory from spam-shall-not-pass to antispam-wizard
- [x] Rename main plugin file to antispam-wizard.php
- [x] Update plugin header comment (Plugin Name, Description, Text Domain)
- [x] Update constants: SSNP_VERSION -> ASW_VERSION, SSNP_FILE -> ASW_FILE, SSNP_PATH -> ASW_PATH, SSNP_URL -> ASW_URL
- [x] Update text domain in load_plugin_textdomain call
- [x] Update docker-compose.yml volume mount path
- [x] Update dev/setup.sh and dev/reset.sh plugin path references
- [x] Update tests/bootstrap.php (SSNP_PATH, SSNP_TESTING -> ASW_TESTING, require path)
- [x] Run tests to verify bootstrap works

### Task 2: Rename core classes and interfaces

**Files:**
- Modify: all PHP files in `antispam-wizard/includes/`
- Rename: class files to match new ASW_ prefix in filenames

- [x] Rename interface file: interface-ssnp-check.php -> interface-asw-check.php
- [x] Rename all class-ssnp-*.php files to class-asw-*.php
- [x] Replace SSNP_ class prefix with ASW_ in all class definitions, type hints, and references
- [x] Update all require/include paths in main plugin file and class files
- [x] Update singleton bootstrap class name (SSNP -> ASW)
- [x] Run tests — fix any class-not-found errors

### Task 3: Rename option keys, DB table, transients, and lock directory

**Files:**
- Modify: `antispam-wizard/includes/class-asw-settings.php`
- Modify: `antispam-wizard/includes/class-asw-stats.php`
- Modify: `antispam-wizard/includes/class-asw-spam-log.php`
- Modify: `antispam-wizard/includes/class-asw-sample-repository.php`
- Modify: `antispam-wizard/includes/class-asw-detector.php`
- Modify: `antispam-wizard/includes/checks/class-asw-duplicate-check.php`

- [x] Update OPTION_KEY constants: ssnp_settings -> asw_settings, ssnp_stats -> asw_stats, ssnp_spam_log -> asw_spam_log
- [x] Update REVISION_OPTION: ssnp_samples_revision -> asw_samples_revision
- [x] Update DB table name: ssnp_samples -> asw_samples
- [x] Update lock directory: .ssnp-locks -> .asw-locks
- [x] Update transient key prefix for duplicate detection
- [x] Update comment meta key: _ssnp_reason -> _asw_reason
- [x] Run tests — fix any option/table name mismatches

### Task 4: Update admin page, AJAX handlers, and assets

**Files:**
- Modify: `antispam-wizard/includes/class-asw-admin-page.php`
- Modify: `antispam-wizard/assets/` (JS/CSS files if they reference old names)

- [x] Update admin page slug and menu references
- [x] Update AJAX action names if they contain ssnp prefix
- [x] Update nonce action names if they contain ssnp prefix
- [x] Update any CSS class or JS references using ssnp naming
- [x] Run tests — verify admin-related tests pass

### Task 5: Rename translation files and update references

**Files:**
- Rename: all files in `antispam-wizard/languages/`

- [x] Rename POT file: spam-shall-not-pass.pot -> antispam-wizard.pot
- [x] Rename all .po files: spam-shall-not-pass-{locale}.po -> antispam-wizard-{locale}.po
- [x] Rename all .mo files: spam-shall-not-pass-{locale}.mo -> antispam-wizard-{locale}.mo
- [x] Update text domain references in all PHP files (replace 'spam-shall-not-pass' with 'antispam-wizard')
- [x] Update POT file header metadata (Project-Id-Version, etc.)
- [x] Update .po file headers to reference new plugin name
- [x] Run tests

### Task 6: Update all test files

**Files:**
- Modify: all files in `tests/`

- [x] Rename test class names from SSNP* to ASW* (e.g., SSNPTest -> ASWTest)
- [x] Update all SSNP_ class references to ASW_ in test files
- [x] Update constant references (SSNP_TESTING -> ASW_TESTING, SSNP_PATH -> ASW_PATH)
- [x] Update option key references in test assertions
- [x] Update comment meta key references in tests
- [x] Run full test suite — all tests must pass

### Task 7: Update readme.txt and documentation

**Files:**
- Modify: `antispam-wizard/readme.txt`
- Modify: `README.md`
- Modify: `CLAUDE.md`

- [x] Update readme.txt plugin name, description, and all references
- [x] Update README.md with new plugin name and references
- [x] Update CLAUDE.md: class prefixes, file paths, option keys, table names, directory references
- [x] Run full test suite

### Task 8: Final verification

- [x] Run full test suite (docker compose run --rm test)
- [x] Grep for any remaining SSNP/ssnp references (except git history)
- [x] Grep for any remaining spam-shall-not-pass references (except git history)
- [x] Move this plan to docs/plans/completed/
