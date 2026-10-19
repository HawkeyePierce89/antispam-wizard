---

# Rename plugin from BalrogBlocker to Spam Shall Not Pass

## Overview

Rename the WordPress plugin from "BalrogBlocker" / "Balrog Blocker" to "Spam Shall Not Pass" with class prefix SSNP_ to eliminate potential trademark concerns with Tolkien IP. This is a full rename affecting PHP classes, file names, constants, database identifiers, text domain, translations, tests, Docker config, and documentation.

## Context

- Files involved: 20 PHP class files, 29 test files, 10 translation file pairs, docker-compose.yml, README.md, CLAUDE.md, uninstall.php, dev scripts
- The plugin is pre-release with no live installs - no migration/compatibility layer needed
- Class prefix changes from Balrog_Blocker_ to SSNP_
- Text domain changes from balrog-blocker to spam-shall-not-pass
- Plugin directory changes from balrog-blocker/ to spam-shall-not-pass/
- Constants change from BALROG_BLOCKER_ to SSNP_
- DB option keys change from balrog_blocker_ to ssnp_
- DB table changes from {prefix}balrog_blocker_samples to {prefix}ssnp_samples
- Comment meta key changes from _balrog_blocker_reason to _ssnp_reason
- Transient prefixes stay as bb_ (short, no trademark concern)

## Development Approach

- **Testing approach**: Regular (rename first, fix tests to match)
- Complete each task fully before moving to the next
- Since this is a mechanical rename, tasks are grouped by layer to minimize context switching
- **CRITICAL: every task MUST include updated tests**
- **CRITICAL: all tests must pass before starting next task**

## Implementation Steps

### Task 1: Rename plugin directory and main file

**Files:**
- Rename: `balrog-blocker/` -> `spam-shall-not-pass/`
- Rename: `spam-shall-not-pass/balrog-blocker.php` -> `spam-shall-not-pass/spam-shall-not-pass.php`
- Rename: `spam-shall-not-pass/includes/class-balrog-blocker.php` -> `spam-shall-not-pass/includes/class-spam-shall-not-pass.php`

- [x] Rename the plugin directory from balrog-blocker/ to spam-shall-not-pass/
- [x] Rename balrog-blocker.php to spam-shall-not-pass.php
- [x] Rename class-balrog-blocker.php to class-spam-shall-not-pass.php
- [x] Update plugin header in spam-shall-not-pass.php (Plugin Name, Plugin URI, Text Domain, Domain Path)
- [x] Rename constants: BALROG_BLOCKER_VERSION -> SSNP_VERSION, BALROG_BLOCKER_FILE -> SSNP_FILE, BALROG_BLOCKER_PATH -> SSNP_PATH, BALROG_BLOCKER_URL -> SSNP_URL
- [x] Update all require/include paths in the main file
- [x] Update docker-compose.yml volume mount path
- [x] Update dev/setup.sh and dev/reset.sh plugin references
- [x] Update phpunit.xml bootstrap path if needed
- [x] Run tests (expect failures from class name changes - that is OK at this stage)

### Task 2: Rename all PHP classes and interface

**Files:**
- Modify: all files in `spam-shall-not-pass/includes/` and `spam-shall-not-pass/includes/checks/`
- Modify: `spam-shall-not-pass/admin/class-admin-page.php`
- Modify: `spam-shall-not-pass/uninstall.php`

- [x] Rename class Balrog_Blocker to SSNP (main singleton class)
- [x] Rename Balrog_Blocker_Detector to SSNP_Detector
- [x] Rename Balrog_Blocker_Settings to SSNP_Settings
- [x] Rename Balrog_Blocker_Check interface to SSNP_Check
- [x] Rename Balrog_Blocker_Check_Result to SSNP_Check_Result
- [x] Rename Balrog_Blocker_Spam_Log to SSNP_Spam_Log
- [x] Rename Balrog_Blocker_Stats to SSNP_Stats
- [x] Rename Balrog_Blocker_Tokenizer to SSNP_Tokenizer
- [x] Rename Balrog_Blocker_Cosine_Similarity to SSNP_Cosine_Similarity
- [x] Rename Balrog_Blocker_Naive_Bayes to SSNP_Naive_Bayes
- [x] Rename Balrog_Blocker_Training_Data to SSNP_Training_Data
- [x] Rename Balrog_Blocker_Script_Detector to SSNP_Script_Detector
- [x] Rename Balrog_Blocker_Sample_Repository to SSNP_Sample_Repository
- [x] Rename Balrog_Blocker_Admin_Page to SSNP_Admin_Page
- [x] Rename all 8 check classes (SSNP_Stop_Words_Check, SSNP_Links_Check, etc.)
- [x] Update all cross-references between classes (type hints, instantiation, static calls)
- [x] Update text domain from 'balrog-blocker' to 'spam-shall-not-pass' in all __() and esc_html__() calls
- [x] Update load_plugin_textdomain call
- [x] Run tests - expect failures from test file references, not from plugin logic

### Task 3: Rename database identifiers and WordPress hooks

**Files:**
- Modify: `spam-shall-not-pass/includes/class-settings.php`
- Modify: `spam-shall-not-pass/includes/class-sample-repository.php`
- Modify: `spam-shall-not-pass/includes/class-detector.php`
- Modify: `spam-shall-not-pass/includes/class-spam-log.php`
- Modify: `spam-shall-not-pass/includes/class-stats.php`
- Modify: `spam-shall-not-pass/uninstall.php`

- [x] Rename option key: balrog_blocker_settings -> ssnp_settings
- [x] Rename option key: balrog_blocker_spam_log -> ssnp_spam_log
- [x] Rename option key: balrog_blocker_stats -> ssnp_stats
- [x] Rename option key: balrog_blocker_samples_revision -> ssnp_samples_revision
- [x] Rename DB table: balrog_blocker_samples -> ssnp_samples
- [x] Rename comment meta key: _balrog_blocker_reason -> _ssnp_reason
- [x] Update uninstall.php cleanup queries for new key names
- [x] Update admin page slug from balrog-blocker to spam-shall-not-pass
- [x] Update any action/filter hook names containing balrog
- [x] Run tests - still expect test failures

### Task 4: Update all test files

**Files:**
- Modify: all 29 files in `tests/`

- [x] Update tests/bootstrap.php - all class references, constant names, file paths
- [x] Update all test class references from Balrog_Blocker_* to SSNP_*
- [x] Update all constant references from BALROG_BLOCKER_* to SSNP_*
- [x] Update option/meta key references in test assertions
- [x] Update text domain references in tests
- [x] Update admin page slug references in tests
- [x] Rename tests/BalrogBlockerTest.php to tests/SSNPTest.php (or similar)
- [x] Run full test suite - all tests must pass

### Task 5: Rename translation files and update .pot template

**Files:**
- Rename + modify: all files in `spam-shall-not-pass/languages/`

- [x] Rename balrog-blocker.pot to spam-shall-not-pass.pot
- [x] Update .pot header (Project-Id-Version, package name)
- [x] Rename all .po files: balrog-blocker-{locale}.po -> spam-shall-not-pass-{locale}.po
- [x] Rename all .mo files: balrog-blocker-{locale}.mo -> spam-shall-not-pass-{locale}.mo
- [x] Update "Project-Id-Version" header in each .po file
- [x] Recompile .mo files from updated .po files (msgfmt)
- [x] Run tests to verify nothing broke

### Task 6: Update documentation and project config

**Files:**
- Modify: `README.md`
- Modify: `CLAUDE.md`
- Modify: `docker-compose.yml`

- [x] Update README.md - title, description, all path references
- [x] Update CLAUDE.md - all class names, paths, constants, text domain, table names
- [x] Verify docker-compose.yml mount path is correct (done in Task 1, double-check here)
- [x] Run full test suite - must pass

### Task 7: Verify acceptance criteria

- [x] Run full test suite: docker compose run --rm test
- [x] Grep for any remaining "balrog" or "Balrog" references in plugin source (excluding git history and docs/plans/completed/)
- [x] Verify plugin activates cleanly in WordPress (verified by code inspection - all hooks, class loading, and paths are correct)
- [x] Move this plan to docs/plans/completed/
