# BalrogBlocker WordPress Plugin Implementation

## Overview
WordPress plugin for comment spam protection, porting detection algorithms from tg-spam (Go) to PHP. Six spam checks, admin panel with 4 tabs, full i18n support, clean uninstall.

## Context
- Reference code: tg-spam-master/lib/tgspam/detector.go (isStopWord, isManyEmojis, isMultiLang, isAbnormalSpacing), metachecks.go (LinksCheck), duplicate.go
- No existing WordPress code yet, greenfield implementation
- Target: PHP 8.1+, WordPress 6.4+, no Composer dependencies
- PHP is not installed locally; Docker (29.3.1) is available and will be used for all PHP operations (tests, linting, pot file generation)

## Development Approach
- **Testing approach**: Regular (code first, then tests). PHPUnit tests after each task.
- **PHP runtime**: PHP is not available locally. Use Docker for all PHP commands: `docker run --rm -v "$(pwd)":/app -w /app php:8.1-cli php ...`. For PHPUnit: install it via Docker and run tests through the container.
- Complete each task fully before moving to the next
- **CRITICAL: every task MUST include new/updated tests**
- **CRITICAL: all tests must pass before starting next task**

## Implementation Steps

### Task 1: Plugin skeleton, core interfaces, and test infrastructure

**Files:**
- Create: `balrog-blocker/balrog-blocker.php`
- Create: `balrog-blocker/uninstall.php`
- Create: `balrog-blocker/includes/interface-check.php`
- Create: `balrog-blocker/includes/class-check-result.php`
- Create: `balrog-blocker/includes/class-balrog-blocker.php`
- Create: `balrog-blocker/includes/class-settings.php`
- Create: `phpunit.xml`
- Create: `tests/bootstrap.php`
- Create: `docker-compose.yml` (PHP 8.1 + PHPUnit test runner)

- [x] Create docker-compose.yml with php:8.1-cli service for running tests (mounts project, includes PHPUnit via composer in container)
- [x] Create main plugin file with header, constants, activation/deactivation hooks, autoloading of includes
- [x] Create Balrog_Blocker_Check interface with method: check(array $comment_data): Balrog_Blocker_Check_Result
- [x] Create Balrog_Blocker_Check_Result value object (spam: bool, check_name: string, details: string)
- [x] Create Settings class: register_setting, get_option defaults for all 6 checks (enabled flags, thresholds)
- [x] Create main plugin class that registers hooks and bootstraps components
- [x] Create uninstall.php that removes all plugin options from wp_options
- [x] Set up PHPUnit with WordPress test framework bootstrap (runs via docker-compose)
- [x] Write tests for Check_Result value object, Settings defaults, uninstall cleanup
- [x] Run test suite via Docker - must pass

### Task 2: Detector and comment integration hook

**Files:**
- Create: `balrog-blocker/includes/class-detector.php`

- [x] Create Detector class that accepts array of Balrog_Blocker_Check instances
- [x] Implement run(array $comment_data): array of Check_Result - iterates all enabled checks, returns results
- [x] Hook into pre_comment_approved filter - on spam detection, return 'spam' and store reason in comment meta
- [x] Hash IP with SHA-256 before any storage
- [x] Write tests: Detector with mock checks (spam and ham), filter integration, IP hashing
- [x] Run test suite via Docker - must pass

### Task 3: Stop-words check

**Files:**
- Create: `balrog-blocker/includes/checks/class-stop-words-check.php`

- [x] Port isStopWord logic: lowercase text, clean emoji (preg_replace unicode emoji ranges), normalize spaces
- [x] Support "=" prefix for exact match, otherwise substring match (from matchStopWord in detector.go)
- [x] Check comment_content field against stop-words list from settings
- [x] Store default stop-words list (seed from tg-spam, adapted for WordPress comments)
- [x] Write tests: exact match, substring match, case insensitivity, emoji in text, no false positives on clean text
- [x] Run test suite via Docker - must pass

### Task 4: Links check

**Files:**
- Create: `balrog-blocker/includes/checks/class-links-check.php`

- [x] Port LinksCheck: count http:// and https:// occurrences in comment text
- [x] Compare against configurable max_links threshold (default: 2)
- [x] Return spam=true with details when limit exceeded
- [x] Write tests: under limit, at limit, over limit, no links
- [x] Run test suite via Docker - must pass

### Task 5: Emoji check

**Files:**
- Create: `balrog-blocker/includes/checks/class-emoji-check.php`

- [x] Port isManyEmojis: count emoji using preg_match_all with Unicode emoji pattern
- [x] Compare against configurable max_emoji threshold (default: 4)
- [x] Return spam=true with count details when exceeded
- [x] Write tests: no emoji, under limit, over limit, mixed text and emoji
- [x] Run test suite via Docker - must pass

### Task 6: Abnormal spacing check

**Files:**
- Create: `balrog-blocker/includes/checks/class-spacing-check.php`

- [x] Port isAbnormalSpacing: calculate space-to-letter ratio and short-word ratio
- [x] Configurable thresholds: min_words_count, short_word_len, short_word_ratio_threshold, space_ratio_threshold
- [x] Skip messages shorter than 10 chars or with fewer words than minimum
- [x] Write tests: normal text, obfuscated text like "п р и в е т", short text skip, edge cases
- [x] Run test suite via Docker - must pass

### Task 7: Mixed scripts (multi-lang) check

**Files:**
- Create: `balrog-blocker/includes/checks/class-multi-lang-check.php`

- [x] Port isMultiLang: for each word, detect Unicode script of each character using IntlChar::script() or manual Unicode range checks
- [x] Skip Common/Inherited scripts, skip 'i' character, skip numbers and whitespace
- [x] Count words containing characters from 2+ scripts
- [x] Compare against configurable threshold (default: 3)
- [x] Write tests: pure Latin, pure Cyrillic, mixed Latin+Cyrillic word, mathematical symbols, threshold boundary
- [x] Run test suite via Docker - must pass

### Task 8: Duplicate check

**Files:**
- Create: `balrog-blocker/includes/checks/class-duplicate-check.php`

- [x] Implement duplicate detection using WordPress transients for storage
- [x] Key: SHA-256 hash of (hashed_ip + comment_content_hash), value: count, TTL from settings (default: 1 hour)
- [x] Increment count on each matching comment, return spam when threshold reached (default: 3)
- [x] Write tests: first comment passes, duplicate increments, threshold triggers spam, different IP/content passes
- [x] Run test suite via Docker - must pass

### Task 9: Admin page - general settings tab

**Files:**
- Create: `balrog-blocker/admin/class-admin-page.php`
- Create: `balrog-blocker/admin/views/settings-general.php`
- Create: `balrog-blocker/admin/css/admin.css`

- [x] Register admin menu page under Settings > BalrogBlocker
- [x] Implement tabbed interface (General, Stop-words, Spam Log, Statistics)
- [x] General tab: toggle for each of 6 checks, threshold inputs for links/emoji/multi-lang/spacing/duplicates
- [x] Nonce verification on all form submissions
- [x] Capability check (manage_options) on page load
- [x] Enqueue admin CSS
- [x] Write tests: menu registration, settings save with nonce, capability check
- [x] Run test suite via Docker - must pass

### Task 10: Admin page - stop-words tab with AJAX

**Files:**
- Create: `balrog-blocker/admin/views/settings-stop-words.php`
- Create: `balrog-blocker/admin/js/admin.js`

- [x] Display current stop-words list
- [x] AJAX endpoint to add a stop-word (wp_ajax_ hook, nonce check, capability check)
- [x] AJAX endpoint to remove a stop-word
- [x] Import: file upload or textarea paste, parse one word per line
- [x] Export: download as text file
- [x] JavaScript for add/remove without page reload
- [x] Write tests: AJAX add/remove with nonce, import parsing, capability enforcement
- [x] Run test suite via Docker - must pass

### Task 11: Spam log tab

**Files:**
- Create: `balrog-blocker/includes/class-spam-log.php`
- Create: `balrog-blocker/admin/views/settings-log.php`

- [x] Spam_Log class: add_entry(date, text_excerpt, checks_triggered, ip_hash), get_entries(limit=100), clear()
- [x] Store log in wp_options as serialized array, cap at 100 entries (FIFO)
- [x] Log tab view: table with date, excerpt (first 100 chars), triggered checks, hashed IP
- [x] Clear log button with nonce verification
- [x] Hook log recording into Detector on spam detection
- [x] Write tests: add entry, FIFO cap at 100, clear, entry format
- [x] Run test suite via Docker - must pass

### Task 12: Statistics tab

**Files:**
- Create: `balrog-blocker/admin/views/settings-stats.php`

- [x] Track in wp_options: total_blocked count, per-check breakdown counts, last_blocked_date
- [x] Increment counters when spam detected (in Detector)
- [x] Stats tab view: total blocked, per-check table, last blocked date
- [x] Write tests: counter increment, per-check breakdown accuracy
- [x] Run test suite via Docker - must pass

### Task 13: i18n and final integration

**Files:**
- Create: `balrog-blocker/languages/balrog-blocker.pot`

- [x] Wrap all user-facing strings with __() and _e() using 'balrog-blocker' text domain
- [x] Load text domain in plugin init
- [x] Generate .pot file (via Docker: wp i18n make-pot or xgettext)
- [x] Wire all components together in main plugin class: register checks, instantiate Detector, connect admin
- [x] Write integration test: full flow from comment submission through detection to spam marking
- [x] Run test suite via Docker - must pass

### Task 14: Verify acceptance criteria

- [x] Run full test suite via Docker
- [x] Comment with >2 links marked as spam
- [x] Stop-words work (exact and substring)
- [x] Abnormal spacing detected
- [x] Mixed scripts (Latin + Cyrillic in same word) detected
- [x] 3+ identical comments from same IP marked as spam
- [x] Legitimate comments pass through unchanged
- [x] Admin tabs render and function (settings save, stop-words AJAX, log display, stats display)
- [x] Uninstall removes all plugin data from database
