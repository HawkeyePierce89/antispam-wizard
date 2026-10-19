# Training samples covered by the WordPress Privacy API

## Overview

User-added training samples (`{prefix}asw_samples`) currently hold the full text of a trained
comment with no link to its author, invisible to the plugin's personal-data exporter and
unreachable by its eraser — contradicting both the suggested privacy-policy text and the readme.
This plan adds a required `comment_id` subject key to the samples table and to
`ASW_Sample_Repository::insert()`, adds fetch/delete-by-comment-IDs repository methods (delete
bumps `asw_samples_revision` so the cached classifier is rebuilt without the erased text), wires
both into `ASW_Privacy::export()` / `erase()`, and updates the policy string, the readme and the
nine translations. There are no existing installs, so the schema change goes straight into the
table definition: no version bump, no `dbDelta` upgrade path, no migration wording.

Baseline before any change: `docker compose run --rm test` = **875 tests, 17152 assertions, OK**.

## Context

- Files involved:
  - `antispam-wizard/includes/class-sample-repository.php` — DDL, `insert()`, new
    `find_by_comment_ids()` / `delete_by_comment_ids()`, `bump_revision()`
  - `antispam-wizard/admin/class-admin-page.php` — `ajax_mark_comment()` (line ~506 insert call)
  - `antispam-wizard/includes/class-privacy.php` — `export()`, `erase()`,
    `add_privacy_policy_content()`
  - `antispam-wizard/readme.txt` — `= Privacy =` (line 54) and the "What personal data does it
    store?" FAQ (line 72)
  - `antispam-wizard/languages/antispam-wizard.pot` + nine `.po`/`.mo` pairs
  - `tests/bootstrap.php` — `$wpdb` stub (`get_results`, `get_var`, `query`, `reset_tables`)
  - `tests/SampleRepositoryTest.php`, `tests/TrainingDataTest.php`, `tests/MarkCommentTest.php`,
    `tests/PrivacyTest.php`, `tests/I18nTest.php`, `tests/UninstallTest.php` (unchanged asserts)
  - `CLAUDE.md`

- Related patterns:
  - Every repository query is `$this->wpdb->prepare()` with `%i` for the table, assigned to a
    local `$sql`, with the exact existing one-line
    `phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- …` reason on the `$this->wpdb->…( $sql )`
    line.
  - `ASW_Spam_Log::get_entries_by_comment_ids()` / `delete_entries_by_comment_ids()` show the
    established "normalise IDs, drop zeros, bail on empty" shape.
  - `ASW_Privacy` builds one group per item with `item_id` `antispam-wizard-comment-…` /
    `antispam-wizard-form-…`; the new items use an `antispam-wizard-sample-…` prefix, which cannot
    collide.
  - The repository is reachable from `ASW::get_instance()->get_repository()` (created in `init()`,
    nullable).

- Dependencies: host `msgmerge` / `msgfmt` (present); Docker for the test, plugin-check and smoke
  harnesses.

## Development Approach

- **Testing approach**: Regular (code first, then tests within the same task)
- Complete each task fully before moving to the next; the suite must be green at every task
  boundary, so the `insert()` signature change and all its call sites land in one task.
- Work on a feature branch off `master`; never push; never `docker compose down` the persistent
  `asw-site` / `antispam-wizard_*` stacks.
- Every commit ends with, in this order and verbatim:
  `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`
  `Claude-Session: https://claude.ai/code/session_013XfBiJjvJkx2k5Smb9Tqej`
- **CRITICAL: every task MUST include new/updated tests**
- **CRITICAL: all tests must pass before starting the next task**

## Implementation Steps

### Task 1: Subject key on the samples table and on insert()

**Files:**
- Modify: `antispam-wizard/includes/class-sample-repository.php`
- Modify: `antispam-wizard/admin/class-admin-page.php`
- Modify: `tests/SampleRepositoryTest.php`, `tests/TrainingDataTest.php`, `tests/MarkCommentTest.php`

- [x] Create the feature branch off `master` (e.g. `feature/privacy-training-samples`); do not push. (branch `privacy-api-training-samples`)
- [x] In `get_create_table_sql()` add `comment_id BIGINT UNSIGNED NOT NULL` after `id` and a
      `KEY idx_comment_id (comment_id)` alongside the existing unique key. No schema-version bump,
      no `dbDelta` upgrade path.
- [x] Above `UNIQUE KEY idx_unique_sample (script, category, content_hash)` add a code comment
      stating the accepted edge case: dedup is on text, so identical text trained from a second
      comment keeps the row created first, together with the first comment's `comment_id`.
- [x] Change the signature to
      `insert( string $script, string $category, string $content, int $comment_id )` — required
      4th parameter. Return `false` immediately when `$comment_id <= 0`, so an unattributed sample
      is impossible at runtime; document it in the docblock's `@return`.
- [x] Write `comment_id` in the `$wpdb->insert()` data array with format `%d`.
- [x] Update `ASW_Admin_Page::ajax_mark_comment()` to pass the already-validated `$comment_id`;
      the existing `false === $insert_result` branch (unmark + error message) already covers the
      new rejection path, so no new UI string.
- [x] Update every existing `->insert(` call site in `tests/SampleRepositoryTest.php` and
      `tests/TrainingDataTest.php` (~49 calls) to pass a non-zero comment ID.
- [x] Tests (repository level): DDL contains `comment_id` and `KEY idx_comment_id (comment_id)`; a
      successful insert stores the given `comment_id`; `insert()` with `0` (and with a negative
      value) returns `false` and writes no row and does not bump the revision.
- [x] Tests (handler level, `tests/MarkCommentTest.php`): after a successful
      `ajax_mark_comment()` the sample row in the `$wpdb` stub's in-memory samples table carries
      the trained comment's ID — assert on the stored row, not only on `find_all()` text. All
      existing `MarkCommentTest` cases must keep passing unchanged with the new required
      `insert()` parameter (the handler supplies it; no test-side call sites change there).
- [x] Run `docker compose run --rm test` — must pass before Task 2.

### Task 2: Repository fetch and delete by comment IDs

**Files:**
- Modify: `antispam-wizard/includes/class-sample-repository.php`
- Modify: `tests/bootstrap.php`
- Modify: `tests/SampleRepositoryTest.php`

- [x] Add `find_by_comment_ids( array $comment_ids ): array` — normalise to ints, drop zeros and
      negatives, return `array()` on an empty list; one `$wpdb->prepare()` query selecting
      `id, comment_id, script, category, content, created_at FROM %i WHERE comment_id IN (…)` with
      a `%d` placeholder list built by `implode()`/`array_fill()` from the ID count only, passed as
      spread arguments; return the rows as arrays keyed by column.
- [x] Add `delete_by_comment_ids( array $comment_ids ): int` — same normalisation, one prepared
      `DELETE FROM %i WHERE comment_id IN (…)` through `$wpdb->query()`; return the row count
      (`0` when the query fails or nothing matched) and call `bump_revision()` only when the count
      is greater than zero.
- [x] Carry the exact existing `phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- …` one-line
      reason on the `$this->wpdb->get_results( $sql )` / `$this->wpdb->query( $sql )` lines, and
      add a reason-bearing suppression for the interpolated placeholder list on the `prepare()`
      line if PHPCS flags it (verified in Task 7 by `dev/plugin-check.sh`).
- [x] Extend the `$wpdb` stub in `tests/bootstrap.php`: teach the WHERE parser used by
      `get_results()`/`get_var()` to match `col IN ('a','b')` in addition to `col = 'val'`, and
      teach `query()` to execute `DELETE FROM <table> WHERE col IN (…)` against the in-memory
      table, returning the number of removed rows while every other query keeps returning `true`
      (the revision-bump `UPDATE` branch is unchanged). This means the stub's `query()` return
      type must widen from `bool` to `int|bool`, matching the real `wpdb::query()`. Reset any new
      state in `reset_tables()`.
- [x] Tests: fetch returns only the requested subjects' rows with all required columns and
      `array()` for an empty/zero-only list; delete removes exactly the matching rows and returns
      the count; delete bumps `asw_samples_revision` when rows were removed and leaves it untouched
      when none matched; both queries backtick-quote the table name via `%i` (assert on
      `$wpdb->prepared_queries`).
- [x] Run `docker compose run --rm test` — must pass before Task 3.

### Task 3: Exporter emits training samples

**Files:**
- Modify: `antispam-wizard/includes/class-privacy.php`
- Modify: `tests/PrivacyTest.php`

- [x] In `export()`, after the comment groups are built and for the same page's comment IDs,
      obtain the repository from `ASW::get_instance()->get_repository()` (skip silently when null)
      and call `find_by_comment_ids()`.
- [x] Add a `build_sample_data_group()` emitting one item per sample into the existing
      `antispam-wizard` group with `item_id` `antispam-wizard-sample-<sample id>` (no collision
      with `-comment-` / `-form-`), carrying: date added, category rendered through the existing
      `Spam` / `Ham` msgids, and the full stored text. New label strings go through `__()` with the
      `antispam-wizard` text domain.
- [x] Leave the `done` calculation and the page-1-only form-entry rule untouched.
- [x] Tests: a subject with a trained comment gets a sample item carrying the full text and the
      right category; a subject without one gets no sample item; sample item IDs are distinct from
      the comment/form item IDs; export still works when the repository is null.
- [x] Run `docker compose run --rm test` — must pass before Task 4.

### Task 4: Eraser removes training samples

**Files:**
- Modify: `antispam-wizard/includes/class-privacy.php`
- Modify: `tests/PrivacyTest.php`

- [x] In `erase()`, for the page's comment IDs call `delete_by_comment_ids()` on the repository
      (skip when null), set `items_removed` when it returned more than zero.
- [x] Extend the single removal message so it also names training samples; keep
      `items_retained => false` and the 50-comments-per-page / page-1 form semantics unchanged.
- [x] Tests: the sample is deleted, counted into `items_removed` and named in the message;
      `items_retained` stays `false`; erasing a subject with no samples behaves exactly as before;
      erase remains idempotent; update the existing assertions that pin the old message text.
- [x] Run `docker compose run --rm test` — must pass before Task 5.

### Task 5: Privacy statements, readme and the nine translations

**Files:**
- Modify: `antispam-wizard/includes/class-privacy.php`, `antispam-wizard/readme.txt`
- Modify: `antispam-wizard/languages/antispam-wizard.pot` and the nine `.po`/`.mo` pairs
- Modify: `tests/I18nTest.php`

- [x] Extend the suggested privacy-policy text so it states that when the site administrator uses
      a flagged comment to train the spam filter, the full text of that comment is stored as a
      training sample until it is erased through a personal-data erasure request or the plugin is
      uninstalled, and that it is included in personal-data exports. Keep the existing
      fragment/hash sentences.
- [x] Make the same statement in the readme's `= Privacy =` section and the "What personal data
      does it store?" FAQ.
- [x] Update the POT: add the new msgids (export labels) and replace the changed ones (removal
      message, policy text) with accurate source references; the POT msgid set must equal the set
      extracted from `antispam-wizard/**/*.php` in both directions.
- [x] `msgmerge` all nine `.po` files, write real translations for the new/changed entries, and
      remove every `#, fuzzy` flag and obsolete `#~` entry; recompile each `.mo` with `msgfmt`.
- [x] Keep any printf placeholders positional with a `// translators:` comment naming each
      placeholder on the line above the call (the plan's new strings are expected to be
      placeholder-free; if one is added, this rule applies).
- [x] Update `I18nTest::$new_strings` to the new removal message and the new policy text, and add
      the new export label strings.
- [x] Run `docker compose run --rm test` — must pass before Task 6.

### Task 6: Documentation

**Files:**
- Modify: `CLAUDE.md`

- [x] Update the `ASW_Sample_Repository` bullet: samples carry a required non-zero `comment_id`
      subject key (insert rejects `0`), plus `find_by_comment_ids()` / `delete_by_comment_ids()`
      and the fact that a delete that removed rows bumps `asw_samples_revision` so the cached
      classifier is rebuilt without the erased text.
- [x] Update the `ASW_Privacy` bullet: training samples are exported (one
      `antispam-wizard-sample-<id>` item per sample: date added, category, full text) and erased
      per subject by comment ID.
- [x] Update the "Data storage" paragraph: samples are keyed by `comment_id`, are export/erase
      covered, and `uninstall.php` is unaffected because the table is dropped whole.
- [x] Extend the test-stub paragraph with the new `$wpdb` stub capabilities (IN-list WHERE parsing
      in `get_results()`/`get_var()`, and `query()` widened to `int|bool` so a
      `DELETE … WHERE col IN (…)` executes against the in-memory table and returns the removed-row
      count while other queries still return `true`), in the same style as the existing stub notes.
- [x] Run `docker compose run --rm test` — must pass before Task 7.

### Task 7: Verify acceptance criteria

- [x] `docker compose run --rm test` — green: **910 tests, 17406 assertions, OK** (baseline 875
      tests / 17152 assertions — +35 tests, +254 assertions).
- [x] Confirm `tests/UninstallTest.php` still asserts the identical removed-data set (unchanged
      file, unchanged expectations) — `git diff master...HEAD -- tests/UninstallTest.php` is empty
      and the file passes 6 tests / 93 assertions.
- [x] `bash dev/plugin-check.sh` — **0 errors, 0 warnings**. The first run reported two
      `WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber` warnings on the new
      `find_by_comment_ids()` / `delete_by_comment_ids()` `prepare()` calls (the sniff cannot count
      the `%d` tokens inside the interpolated `{$placeholders}` list); both now carry an
      exact-sniff-code `phpcs:ignore` with a one-line reason.
- [x] `bash dev/smoke.sh` — SMOKE PASS on `wordpress:7.1-php8.3-apache` (debug.log clean, 6/6 admin
      pages, comment + CF7 spam/ham verdicts correct).
- [x] `git status` / `git diff --stat` confirm no change under `site/`, none to `data/*.txt`, and
      none to any class under `includes/checks/` (all three path filters return an empty diff).
- [x] Commit the work on the feature branch with the two required trailer lines; do not push.

## Post-Completion (manual, not agent-automatable)

- Review the nine translations for wording quality before any release.
