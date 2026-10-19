# WordPress Privacy API Integration: personal data export/erasure and privacy policy text

## Overview

The plugin stores pseudonymous personal data (a fragment of the spam comment + SHA-256 hash of the IP + comment_id in the spam-log; the reason in the `_asw_reason` meta), but it is not integrated with the WordPress "Export/Erase Personal Data" mechanism. This plan registers an exporter and an eraser (GDPR Art. 15/17) and adds suggested text for the privacy policy via `wp_add_privacy_policy_content()` (Art. 13).

Plugin data is keyed by comment_id. Given the subject's email, WordPress core finds the comments (`get_comments(['author_email' => ...])`), and the plugin uses their comment_id to export/erase the corresponding spam-log entries and the `_asw_reason` meta.

## Context

- Files to modify:
  - `antispam-wizard/antispam-wizard.php` — include the new class (require_once)
  - `antispam-wizard/includes/class-antispam-wizard.php` — register hooks in `init()`
  - `antispam-wizard/includes/class-spam-log.php` — methods to select/delete entries by comment_id
  - `antispam-wizard/uninstall.php` — no data changes, but verify (no new storage)
  - `tests/bootstrap.php` — stubs for `get_comments()`, `wp_add_privacy_policy_content()`, `wp_specialchars_decode()` as needed
  - `CLAUDE.md`, `README.md`, `antispam-wizard/languages/antispam-wizard.pot`
- Files to create:
  - `antispam-wizard/includes/class-privacy.php` — `ASW_Privacy` class
  - `tests/PrivacyTest.php`
- Existing patterns:
  - Class prefix `ASW_*`, files `class-{name}.php`, PHP 8.1 strict types, no namespaces
  - Hooks registered from `ASW::init()` (see `ASW_Detector::register_hooks()`)
  - IP hash: `ASW_Detector::hash_ip()` (SHA-256 + `wp_salt('auth')`)
  - All UI/strings via `__()`/`esc_html__()` with the `antispam-wizard` text domain
  - Tests reset the singleton via reflection in setUp; WP function stubs in `tests/bootstrap.php`
- WordPress Privacy API contract:
  - Filters `wp_privacy_personal_data_exporters` and `wp_privacy_personal_data_erasers` (per item: `['exporter_friendly_name', 'callback']` / `['eraser_friendly_name', 'callback']`)
  - Exporter callback `($email, $page)` → `['data' => [groups], 'done' => bool]` (pagination)
  - Eraser callback `($email, $page)` → `['items_removed' => bool, 'items_retained' => bool, 'messages' => [], 'done' => bool]`
  - `wp_add_privacy_policy_content($plugin_name, $content)` is called on `admin_init`

## Development Approach

- **Testing approach**: Regular (code first, then tests) — matches the existing project style (PHPUnit 9.6 via `docker compose run --rm test`)
- Complete each task fully before moving to the next
- **CRITICAL: every task includes new/updated tests**
- **CRITICAL: all tests must pass before starting the next task**

## Implementation Steps

### Task 1: Spam-log methods to select and delete by comment_id

**Files:**
- Modify: `antispam-wizard/includes/class-spam-log.php`
- Modify: `tests/SpamLogTest.php`

- [x] Add `get_entries_by_comment_ids(array $comment_ids): array` — returns log entries whose `comment_id` is in the given set (normalize to int, read through the existing `get_entries()` with cache invalidation)
- [x] Add `delete_entries_by_comment_ids(array $comment_ids): int` — under the same `flock` lock as the other mutators; filters out matching entries, saves the remainder via `update_option(..., false)`, invalidates the object cache (`wp_cache_delete`); returns the number of deleted entries; does not write the option if nothing was deleted
- [x] Ignore `comment_id === 0` (unlinked entries do not belong to the subject)
- [x] Tests: selection by a set of ids; deleting a subset while preserving the rest; repeated deletion returns 0; behavior on an empty set; FIFO/cache integrity after deletion
- [x] Run `docker compose run --rm test` — must pass before Task 2

### Task 2: ASW_Privacy class (exporter, eraser, policy text)

**Files:**
- Create: `antispam-wizard/includes/class-privacy.php`
- Modify: `tests/bootstrap.php`

- [x] Create the `ASW_Privacy` class (strict types, `ABSPATH` guard) with a `register_hooks()` method:
  - `add_filter('wp_privacy_personal_data_exporters', [$this, 'register_exporter'])`
  - `add_filter('wp_privacy_personal_data_erasers', [$this, 'register_eraser'])`
  - `add_action('admin_init', [$this, 'add_privacy_policy_content'])`
- [x] `register_exporter(array $exporters): array` — adds an item with key `antispam-wizard`, `exporter_friendly_name => __('AntiSpam Wizard', 'antispam-wizard')`, callback `export`
- [x] `register_eraser(array $erasers): array` — analogous for `erase`
- [x] `export(string $email, int $page = 1): array` — via `get_comments(['author_email' => $email, 'number' => N, 'paged' => $page, 'fields' => 'ids'])` collect comment_ids; using `ASW_Spam_Log::get_entries_by_comment_ids()` and `get_comment_meta($id, '_asw_reason', true)` build data groups (fields: date, text fragment, IP hash, triggered checks, reason); `done => (number of comments on the page < N)`
- [x] `erase(string $email, int $page = 1): array` — find the same comment_ids, delete log entries (`delete_entries_by_comment_ids`) and meta (`delete_comment_meta($id, '_asw_reason')`); return `items_removed`, `done`, localized `messages`
- [x] `add_privacy_policy_content(): void` — guard on `function_exists('wp_add_privacy_policy_content')`; suggested text (in English, wrapped in `wp_kses_post`/`__`) explaining: for spam detection the plugin stores a fragment of flagged comments, a SHA-256 hash of the IP address, and the reason for flagging; data is erased on subject request and on uninstall
- [x] Stubs in `tests/bootstrap.php` (under `function_exists`): `get_comments()`, `get_comment_meta()`/`delete_comment_meta()` (if not present yet), `wp_add_privacy_policy_content()` (record into a global for assertion), `wp_kses_post()`, `wp_specialchars_decode()` as needed
- [x] Run `docker compose run --rm test` — must pass before Task 3

### Task 3: Plugin bootstrap wiring and registration + tests

**Files:**
- Modify: `antispam-wizard/antispam-wizard.php`
- Modify: `antispam-wizard/includes/class-antispam-wizard.php`
- Create: `tests/PrivacyTest.php`

- [x] `require_once ASW_PATH . 'includes/class-privacy.php'` in the main plugin file
- [x] In `ASW::init()` create `new ASW_Privacy()` and call `register_hooks()` (without an `is_admin()` condition, since privacy requests and policy content run in the admin/cron context; the exporter/eraser filters are harmless outside it)
- [x] `tests/PrivacyTest.php`: reset the singleton via reflection in setUp following the existing test pattern
  - filter registration (items added to the array with the expected keys)
  - export: returns log entries and meta for comments of the given email; does not return others'; pagination/`done`; empty result for an unknown email
  - erase: deletes log entries and meta for the relevant comments, leaves others untouched; `items_removed` is correct; idempotency (repeat call — nothing to delete)
  - policy content: `add_privacy_policy_content()` calls `wp_add_privacy_policy_content` with non-empty text
- [x] Run `docker compose run --rm test` — must pass before Task 4

### Task 4: Documentation and i18n

**Files:**
- Modify: `antispam-wizard/languages/antispam-wizard.pot`
- Modify: `CLAUDE.md`
- Modify: `README.md`

- [x] Add new msgids (friendly names, eraser messages, policy text) to the `.pot`
- [x] CLAUDE.md: add `ASW_Privacy` to the key classes list; note the WP Privacy API integration in the Architecture/Data storage sections; if needed, document the new stubs in the test bootstrap section
- [x] README.md: a short note about personal data export/erasure support and the suggested policy text (if a relevant user-facing section exists)
- [x] Run `docker compose run --rm test` — must pass

### Task 5: Verify acceptance criteria

- [x] Run the full test suite: `docker compose run --rm test` (612 tests, 1813 assertions, all pass)
- [x] Check coverage of the new classes/methods (target 80%+) — no coverage driver in the test image; verified by inspection that all public methods and branches of ASW_Privacy and the two new ASW_Spam_Log comment_id methods are exercised by the 13 PrivacyTest cases and SpamLogTest, well above 80%
- [x] Confirm the smoke test (`bash dev/smoke.sh`) still renders all admin pages without plugin-attributable errors (SMOKE PASS: 6/6 admin pages rendered, debug.log clean, spam/ham detection correct)

## Post-Completion (manual verification)

- In a live WP: Tools → Export Personal Data by the email of a flagged spam comment — plugin data is present in the report
- Tools → Erase Personal Data for the same email — spam-log entries and the `_asw_reason` meta disappear
- Settings → Privacy — the plugin's suggested text is available to insert
