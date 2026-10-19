# WordPress.org Plugin Check compliance for the 1.0.0 submission

## Overview

Make AntiSpam Wizard pass `wp plugin check antispam-wizard --categories=plugin_repo` with zero errors, and turn every remaining warning into an explicitly justified `phpcs:ignore`. Two of the finding groups are real defects and get real fixes (unordered i18n placeholders; `unlink()` in the uninstaller); the rest are resolved by using the proper WordPress API (`%i` identifier placeholders) or by annotated suppressions carrying a one-line reason.

A new isolated verification harness (`dev/plugin-check.sh`) is built **first**, so every subsequent task works against the real check output rather than guessed sniff codes.

## Context

Baseline established by exploration (WP 7.0 run: 84 errors / 54 warnings):

- **Files involved**
  - Harness / CI: `dev/plugin-check.sh` (new), `.github/workflows/ci.yml`, `docker-compose.yml` (read-only reference), `dev/smoke.sh` (isolation model to copy).
  - Hidden files: `antispam-wizard/data/.htaccess` (tracked, must go). `antispam-wizard/data/index.php` **already exists** with `// Silence is golden.`, so §1 is a deletion plus a guard test. `.gitignore` already lists `.DS_Store` — confirmed, no change needed.
  - Prepared SQL: `antispam-wizard/includes/class-sample-repository.php` (5 `prepare()` calls interpolating `` `{$table}` ``, plus `bump_revision()` interpolating `` `{$this->wpdb->options}` ``), `antispam-wizard/uninstall.php` (options-table `DELETE`, `DROP TABLE`), `antispam-wizard/includes/class-antispam-wizard.php:391` (`dbDelta`).
  - i18n unordered placeholders (exactly 10): `class-spacing-check.php:81,88` (`%.2f` + `%.0f%%`), `class-multi-lang-check.php:65,72`, `class-emoji-check.php:57,64`, `class-duplicate-check.php:86,93`, `class-links-check.php:43,50`. Single-placeholder strings needing `translators:` comments: `class-spacing-check.php:47`, `class-admin-page.php:96,555,560,619,725`, `admin/views/settings-log.php:85`. Only `class-submit-time-check.php:94` already has a `translators:` comment and positional form.
  - i18n catalogues: `antispam-wizard/languages/antispam-wizard.pot` (180 msgids) + nine `.po`/`.mo`. The two "missing" strings (`Type a word to block...`, `Add a token to exclude...`) are present in every `.po` as obsolete `#~` entries — their translations can be revived rather than retranslated.
  - Filesystem: `class-spam-log.php` (8 `fopen`/`fclose` pairs), `class-stats.php` (2), `checks/class-duplicate-check.php` (1); `antispam-wizard/uninstall.php` (`unlink()` → `wp_delete_file()`, `rmdir()` annotated).
  - Escaping: `admin/class-admin-page.php:180` (`$brand_icon` SVG), `:653` and `:759` (`echo implode( "\n", $list )` — **plain-text file downloads**, see decision below), `includes/class-comment-form.php:89` (`echo self::render_bot_fields(...)`).
  - Warnings: `$_POST` reads in `checks/class-honeypot-check.php:50`, `checks/class-submit-time-check.php:62`, `class-cf7-integration.php:295-302`; `$_GET` reads in `admin/class-admin-page.php:150` and **unsanitized** `$_GET['settings-updated']` in `admin/views/settings-general.php:14` and `admin/views/settings-main.php:14`; 12 `error_log()` calls; `load_plugin_textdomain` at `class-antispam-wizard.php:373`; `slow_db_query_meta_key` on `uninstall.php:37` (`array( 'meta_key' => '_asw_reason' )`) — note the ticket names the Privacy eraser, but the eraser uses `get_comment_meta()`/`delete_comment_meta()`, which the sniff does not flag; the annotation goes wherever the run actually reports it.
  - Tests: `tests/bootstrap.php` (anonymous-class `$wpdb` stub, `prepare()` currently handles only `%s|%d`), `tests/SampleRepositoryTest.php`, `tests/UninstallTest.php` (1 test), `tests/I18nTest.php`, `tests/CommentFormTest.php` (already has `test_render_bot_fields_byte_equivalent_to_render_fields` with normalization), `tests/AdminPageTest.php`, `tests/StopWordsTabTest.php`.

- **Related patterns**
  - `dev/smoke.sh`: per-run per-image compose project (`asw-smoke-<image>-<pid>`), inline `mktemp` override yaml using `!override` for `ports`, ephemeral volumes, `trap cleanup EXIT`, `WORDPRESS_IMAGE` default `7.1-php8.3-apache`.
  - `tests/StatsTest.php` / `tests/DuplicateCheckTest.php` assert a `.htaccess` inside the **uploads lock directory** (`.asw-locks`) — that file is created at runtime outside the plugin folder, is not shipped, and stays exactly as is.

- **Dependencies**: Docker, network access inside the throwaway container for `wp plugin install plugin-check`. `msgfmt`/`msgmerge`/`xgettext` are available on the host; the PHPUnit container (`composer:2`) has no gettext, so catalogue parity is enforced by a pure-PHP test.

## Decisions taken up front

1. **The two `echo implode( "\n", $list )` calls are NOT wrapped in `wp_kses()`** (confirmed with the user). They are the stop-words / excluded-tokens **exports**: `Content-Type: text/plain` + `Content-Disposition: attachment`. `wp_kses()` would change the downloaded bytes (`&` → `&amp;`, angle-bracketed entries stripped). They keep the raw `echo` plus `phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped` with the reason (plain-text download, entries sanitized on save), backed by a test asserting the exported bytes are unchanged.
2. **`load_plugin_textdomain` is kept and annotated**, not removed or gated. The bundled `.mo` files are the only translation source for self-hosted installs and the smoke harness; WP.org's automatic loading only covers directory-hosted installs. Rationale goes in the annotation.
3. **Sanitization is applied where it is semantically free, and annotated where it is not.** `settings-general.php` / `settings-main.php` `$_GET['settings-updated']` reads get real `wp_unslash()` + `sanitize_text_field()`. The honeypot / submit-time `$_POST` reads already do `wp_unslash()` per field; adding `sanitize_text_field()` there would *weaken detection* (an honeypot filled with only HTML tags would sanitize to empty and pass), so they get an annotation with that reason instead of a behaviour change. The CF7 `$_POST` copies stay raw/slashed by design (they must match the comment path) and are annotated.
4. **`uninstall.php` is no longer byte-identical.** The ticket authorises the `unlink()` → `wp_delete_file()` change; if the check also reports its SQL (`{$wpdb->options}` interpolation inside `prepare()`, `DROP TABLE` interpolation), those are fixed in the same task, since the goal is zero errors and the byte-identical rule is being retired. CLAUDE.md is updated to the new baseline: "uninstall.php must remove exactly the same set of options, transients, comment meta, table and lock files; changes are allowed but must keep that set identical and be covered by `UninstallTest`."
5. **Sniff codes are copied from the real run, never guessed.** Task 1 produces the baseline; each fix task quotes the exact `code` column from the CSV output in its `phpcs:ignore`.
6. **Residual non-PHPCS warnings** (readme/plugin-header style checks that a `phpcs:ignore` cannot suppress) are enumerated in the final task and either fixed, if genuine, or reported explicitly — they are not silently accepted.

## Development Approach

- **Testing approach**: Regular (code first, then tests). Most changes are annotations whose behavioural contract is "output/behaviour is unchanged", so the tests are characterization/golden tests written against the code as changed and verified to also pass against the pre-change behaviour where practical.
- Complete each task fully before moving to the next.
- **CRITICAL: every task MUST include new/updated tests.**
- **CRITICAL: all tests must pass (`docker compose run --rm test`) before starting the next task.**
- Small focused commit per task; no drive-by refactors; detection logic, thresholds, storage locations and the Privacy API surface are untouched.

## Implementation Steps

### Task 1: Reproducible Plugin Check harness (`dev/plugin-check.sh`)

**Files:**
- Create: `dev/plugin-check.sh`
- Create: `tests/PluginCheckHarnessTest.php`

- [x] Write `dev/plugin-check.sh` modelled on `dev/smoke.sh`: `set -euo pipefail`, `WORDPRESS_IMAGE` default `7.1-php8.3-apache`, per-run per-image compose project `asw-pc-<sanitized-image>-<pid>`, defensive `down -v` before boot, `trap cleanup EXIT` running `down -v --remove-orphans`, and an inline `mktemp` override yaml.
- [x] In the override, use `ports: !override []` and `volumes: !override [...]` so the base `./antispam-wizard` bind mount is replaced by a **tracked-files-only export** mounted read-only at `/var/www/html/wp-content/plugins/antispam-wizard`.
- [x] Build that export into a `mktemp -d` directory by iterating `git ls-files -z antispam-wizard`, recreating directories and copying each still-existing file (working-tree content, tracked paths only); remove the directory in the cleanup trap. This guarantees `.DS_Store` and any other untracked junk can never produce a finding.
- [x] Install WP-CLI, wait for the container and the DB, and `wp core install` exactly as the smoke harness does; reuse its core-version gate so a stale volume fails fast with an actionable message.
- [x] `wp plugin install plugin-check --activate` and `wp plugin activate antispam-wizard`; fail the run (non-zero) if either fails — unlike the CF7 soft dependency, plugin-check is the point of the harness.
- [x] Run `wp plugin check antispam-wizard --categories=plugin_repo --format=csv --fields=file,line,column,type,code,message` with a raised PHP memory limit for WP-CLI; tee the output so it is visible in CI logs.
- [x] Print a summary (`N errors, M warnings`), print all WARNING rows for information, and `exit 1` when any row has `type == ERROR`; `exit 0` otherwise.
- [x] Make the script executable and confirm it never touches the persistent dev env (project `antispam-wizard_*`) and publishes no host ports.
- [x] Run `bash dev/plugin-check.sh` and save the baseline CSV output to a scratch location (not committed) as the ground truth for sniff codes used in Tasks 2-7.
- [x] Write `tests/PluginCheckHarnessTest.php` asserting the script exists, is executable, honours `WORDPRESS_IMAGE` with the smoke default, derives a per-run project name including `$$`, contains a `trap ... EXIT` teardown with `down -v`, uses `git ls-files` for the export, and passes `--categories=plugin_repo` (static assertions over the script text, no Docker required).
- [x] Run `docker compose run --rm test` — must pass before Task 2.

### Task 2: Hidden files — drop the shipped `.htaccess`

**Files:**
- Delete: `antispam-wizard/data/.htaccess`
- Modify: `tests/PluginMetadataTest.php`

- [x] `git rm antispam-wizard/data/.htaccess`; confirm `antispam-wizard/data/index.php` already carries the `// Silence is golden.` guard (it does) so the corpus stays unlistable.
- [x] Grep the plugin and `uninstall.php` for `.htaccess`: the only remaining references are the **runtime** lock-directory guard written into `wp-content/uploads/.asw-locks` by `class-spam-log.php`, `class-stats.php` and `checks/class-duplicate-check.php`, plus `uninstall.php`'s dotfile glob that removes it. Those are outside the shipped plugin folder and stay unchanged.
- [x] Confirm `.gitignore` already covers `.DS_Store` (it does) — no change.
- [x] Add tests to `tests/PluginMetadataTest.php`: no `antispam-wizard/data/.htaccess` exists, `antispam-wizard/data/index.php` exists and contains the silence guard.
- [x] Run `docker compose run --rm test` — must pass before Task 3.

### Task 3: Prepared SQL — `%i` identifier placeholders

**Files:**
- Modify: `antispam-wizard/includes/class-sample-repository.php`
- Modify: `antispam-wizard/includes/class-antispam-wizard.php`
- Modify: `tests/bootstrap.php`
- Modify: `tests/SampleRepositoryTest.php`

- [x] In `ASW_Sample_Repository`, replace every `` `{$table}` `` inside `prepare()` with `%i` and pass the table name as the first argument: the duplicate `SELECT COUNT(*)` in `insert()`, the post-insert recheck `SELECT COUNT(*)`, `find_all()`'s `SELECT content`, `count_by_script_category()`'s `SELECT COUNT(*)`, and `bump_revision()`'s `UPDATE` on `{$this->wpdb->options}`. Drop the now-redundant manual backticks. No `phpcs:ignore` where `%i` solves it.
- [x] Leave `get_create_table_sql()` interpolation as is (dbDelta requires an unbackticked name — the existing inline comment already explains why) and annotate the reported `dbDelta`/schema-change and `DirectDatabaseQuery` findings at their actual sites (`class-antispam-wizard.php:391` and the repository's `get_var`/`get_results`/`query` calls) with `phpcs:ignore <exact code from Task 1 output>` plus the reason: the plugin owns this table, results are not cacheable across the revision counter, and DDL runs once on activation.
  - **Outcome:** `get_create_table_sql()` left unchanged. Per Decision 5 (codes come from the real run, never guessed), **no** `dbDelta`/`SchemaChange`/`DirectDatabaseQuery` finding is reported at `class-antispam-wizard.php:391` or on the repository's query calls — those sniffs only fire on a literal `$wpdb` variable, and this class uses the injected `$this->wpdb`. Nothing to annotate there; a guessed suppression would itself be an unjustified ignore. The findings that *do* survive `%i` are 5 × `WordPress.DB.PreparedSQL.NotPrepared` on the `$sql`/`$recheck_sql` arguments (the sniff does not trace the assignment from `$this->wpdb->prepare()`); each carries an annotated `phpcs:ignore` with that reason, verified load-bearing by re-running the harness with the annotations stripped. Result: `includes/class-sample-repository.php` now reports **zero** findings (was 27 errors + 10 warnings); run total 82 → 54 errors, 54 → 44 warnings.
- [x] Extend the `$wpdb` stub's `prepare()` in `tests/bootstrap.php` to model `%i`: match `%s|%d|%i`, and for `%i` emit a backtick-quoted identifier with embedded backticks doubled (matching real WP), while `%s`/`%d` keep their current single-quoted rendering. Verify the existing `get_var`/`get_results` table extraction (which already trims backticks) and the `query()` revision-bump regex still match.
- [x] Add tests to `tests/SampleRepositoryTest.php`: the recorded `prepared_queries` for a `find_all()` / `count_by_script_category()` / `insert()` duplicate-check still target `` `wp_asw_samples` ``, the value placeholders are still bound in the right order, and `bump_revision()`'s UPDATE targets `` `wp_options` ``; plus a direct `$wpdb->prepare()` stub test covering `%i` backtick-escaping.
- [x] Run `docker compose run --rm test` — all 38 existing SampleRepository tests plus the new ones must pass before Task 4.

### Task 4: Internationalization — positional placeholders, `translators:` comments, catalogue regeneration

**Files:**
- Modify: `antispam-wizard/includes/checks/class-spacing-check.php`, `class-multi-lang-check.php`, `class-emoji-check.php`, `class-duplicate-check.php`, `class-links-check.php`
- Modify: `antispam-wizard/admin/class-admin-page.php`, `antispam-wizard/admin/views/settings-log.php`
- Modify: `antispam-wizard/languages/antispam-wizard.pot` and all nine `.po`/`.mo`
- Modify: `tests/I18nTest.php`

- [x] Convert all 10 unordered strings to positional form, preserving the rendered output exactly: `'mixed scripts %1$d/%2$d'`, `'too many emoji %1$d/%2$d'`, `'emoji %1$d/%2$d'`, `'duplicate comment %1$d/%2$d'`, `'duplicate count %1$d/%2$d'`, `'too many links %1$d/%2$d'`, `'links %1$d/%2$d'`, and the two spacing strings `'abnormal (ratio: %1$.2f, short: %2$.0f%%)'` / `'normal (ratio: %1$.2f, short: %2$.0f%%)'` (keep the literal `%%`).
- [x] Add a `/* translators: … */` comment on the line directly above every `__()`/`esc_html__()`/`esc_attr__()`/`esc_html_e()` call whose string contains a placeholder, naming each placeholder: the 10 above plus `class-spacing-check.php:47`, `class-admin-page.php:96` (`AntiSpam Wizard - %s`), `:555` (`Comment marked as %s…`), `:560` (`Saved to: %s model`), `:619` and `:725` (`Import truncated: … %d entries.`), and `admin/views/settings-log.php:85` (`Trained as %s`).
- [x] Regenerate `antispam-wizard.pot` keeping the existing header intact: fold in the two currently-missing placeholder-free strings `Type a word to block...` (`settings-stop-words.php:20`) and `Add a token to exclude...` (`settings-excluded-tokens.php:20`), remove the three stale msgids no longer in source (pre-CF7 privacy-policy paragraph, pre-CF7 erasure message, old spam-log caption), and carry over the reworded placeholder msgids.
- [x] Update all nine `.po` files with `msgmerge`: revive the two obsolete `#~` translations for the reintroduced strings (present in every catalogue — no retranslation needed), carry each old `%d/%d`-style translation onto its positional msgid and clear the resulting fuzzy marks, and drop the three removed msgids. Recompile every `.mo` with `msgfmt`.
- [x] Verify with `msgfmt --statistics` on all nine `.po` files: zero untranslated, zero fuzzy.
- [x] Extend `tests/I18nTest.php` with (a) a pure-PHP POT↔source parity test — extract the first argument of every `__()/_e()/esc_html__()/esc_attr__()/esc_html_e()/esc_attr_e()/_x()/_n()` call across `antispam-wizard/**/*.php` using `token_get_all()`, and assert the msgid set in the POT equals the source set in both directions; (b) a per-`.po` test asserting no empty `msgstr` and no `#, fuzzy` outside the header (gettext-free, runs in the `composer:2` container); (c) assertions that the 10 reason msgids are positional and that `sprintf()` on each still renders the same text as before (e.g. `links 3/2`, `abnormal (ratio: 0.05, short: 80%)`).
- [x] Run `docker compose run --rm test` — must pass before Task 5.

### Task 5: Filesystem operations — annotate `fopen`/`fclose`, fix the uninstaller

**Files:**
- Modify: `antispam-wizard/includes/class-spam-log.php`, `antispam-wizard/includes/class-stats.php`, `antispam-wizard/includes/checks/class-duplicate-check.php`
- Modify: `antispam-wizard/uninstall.php`
- Modify: `tests/UninstallTest.php`

- [x] Annotate all 11 `fopen()` and 11 `fclose()` lock-file calls with `phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen` / `_fclose` plus a one-line reason: `flock()` needs a real stream handle and `WP_Filesystem` exposes no locking primitive. The locking itself is not rewritten.
- [x] In `uninstall.php`, replace `unlink( $file )` with `wp_delete_file( $file )`; keep the dotfile-aware glob loop (it must still remove the runtime `.htaccess` and `index.php` guards inside `.asw-locks`) and keep `rmdir( $lock_dir )` with `phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir` and the reason (removing an empty plugin-owned directory; `WP_Filesystem` is not bootstrapped during uninstall).
- [x] If Task 1's baseline also reports `PreparedSQL`/`InterpolatedNotPrepared` on `uninstall.php`'s options-table `DELETE` or the `DROP TABLE`, convert them to `%i` in the same commit (the byte-identical rule is retired by this ticket); the removed data set must stay exactly the same.
  - **Outcome:** Per Decision 5, checked against the real Task 1 baseline: `uninstall.php` reports **no** `PreparedSQL.NotPrepared` and **no** `PreparedSQL.InterpolatedNotPrepared` finding. Its only findings are the two filesystem errors fixed here plus `DirectDatabaseQuery.DirectQuery`/`NoCaching`/`SchemaChange` warnings (lines 26, 37, 40). The `DELETE` already binds its four `LIKE` values via `prepare()` and `{$wpdb->options}` / `{$wpdb->prefix}asw_samples` are core-derived identifiers the sniff does not flag. No SQL change made — a speculative `%i` conversion would be an unrequested behaviour risk in the uninstaller for zero check benefit.
- [x] Add a `wp_delete_file()` stub to `tests/bootstrap.php` if absent (delegating to `unlink()`), and record deleted paths so the test can assert them.
- [x] Extend `tests/UninstallTest.php` (currently a single test): create a fake `.asw-locks` directory with a lock file, an `index.php` and a `.htaccess`, run the uninstaller, and assert every file is removed, the directory is gone, and the option/transient/comment-meta/DROP-TABLE assertions from the existing test still hold — i.e. the removed set is unchanged.
- [x] Run `docker compose run --rm test` — must pass before Task 6.
  - **Outcome (task):** 21 `fopen`/`fclose` sites annotated (10 `fopen`, 11 `fclose` — the plan's "11 and 11" was an estimate; the real count from the baseline is 10 `fopen`). Run total after this task: **54 → 4 errors**, warnings unchanged at 44. The 4 remaining errors are all `EscapeOutput.OutputNotEscaped`, handled by Task 6.

### Task 6: Output escaping — annotations backed by golden-output tests

**Files:**
- Modify: `antispam-wizard/admin/class-admin-page.php`, `antispam-wizard/includes/class-comment-form.php`
- Modify: `tests/AdminPageTest.php`, `tests/CommentFormTest.php`, `tests/StopWordsTabTest.php`

- [x] Annotate `class-admin-page.php:180` (`echo '<span class="asw-brand-icon">' . $brand_icon . '</span>'`) with `phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped` and the reason: a hard-coded inline SVG literal with no dynamic input; escaping would render it as text.
- [x] Annotate the two export `echo implode( "\n", $list )` calls (`:653`, `:759`) with the same sniff and the reason from the decision above: plain-text `Content-Disposition: attachment` download, entries already sanitized on save, `wp_kses()` would corrupt the exported bytes.
- [x] Annotate `class-comment-form.php:89` (`echo self::render_bot_fields( ... )`) with the same sniff and the reason: the markup is assembled from `esc_attr()`-escaped parts inside `render_bot_fields()`, which is the single source of truth for both the comment and CF7 render paths.
- [x] Add golden-output tests: `tests/AdminPageTest.php` asserts the rendered admin header contains the SVG literal byte-for-byte (unescaped `<svg …/></svg>`, no `&lt;`); `tests/CommentFormTest.php` adds a snapshot assertion pinning the exact bot-field markup (normalizing only the `wp_rand()` id suffix and the minted token), complementing the existing `test_render_bot_fields_byte_equivalent_to_render_fields`.
- [x] Add export byte-fidelity tests in `tests/StopWordsTabTest.php` (and its excluded-tokens counterpart): with a list containing `&`, `<`, `>` and a quote, the exported body equals `implode( "\n", $list )` exactly.
- [x] Run `docker compose run --rm test` — must pass before Task 7.
  - **Outcome:** All four `WordPress.Security.EscapeOutput.OutputNotEscaped` errors annotated; the harness now reports **0 errors, 44 warnings** on the default image. The excluded-tokens export byte-fidelity test lives in `tests/StopWordsTabTest.php` alongside the stop-words one (there is no separate excluded-tokens test file; the handler's other tests are in `AdminPageTest`). Test count 771 → 776.

### Task 7: Justify the remaining warnings

**Files:**
- Modify: `antispam-wizard/includes/checks/class-honeypot-check.php`, `class-submit-time-check.php`
- Modify: `antispam-wizard/includes/class-cf7-integration.php`
- Modify: `antispam-wizard/admin/class-admin-page.php`, `antispam-wizard/admin/views/settings-general.php`, `antispam-wizard/admin/views/settings-main.php`
- Modify: `antispam-wizard/includes/class-detector.php`, `class-spam-log.php`, `class-stats.php`, `checks/class-duplicate-check.php`
- Modify: `antispam-wizard/includes/class-antispam-wizard.php`, `antispam-wizard/uninstall.php`
- Modify: `tests/AdminPageTest.php`, `tests/HoneypotCheckTest.php`, `tests/SubmitTimeCheckTest.php`

- [x] Fix the two genuine unsanitized reads: in `settings-general.php:14` and `settings-main.php:14` route `$_GET['settings-updated']` through `wp_unslash()` + `sanitize_text_field()` before the comparison, and annotate the nonce sniff (read-only display of a core-supplied redirect flag).
- [x] Annotate `class-admin-page.php:150`'s `$_GET['page']` read (already unslashed and sanitized) with the nonce sniff and the reason: read-only tab selection for display.
- [x] Annotate the `$_POST` fallbacks in `class-honeypot-check.php:50` and `class-submit-time-check.php:62` with the exact `NonceVerification.Missing` / `ValidatedSanitizedInput.*` codes plus the reason: WordPress issues no nonce on the `pre_comment_approved` path, every field is individually `wp_unslash()`-ed before use, and the checks fail open on missing/malformed values. Per the decision above, do **not** add `sanitize_text_field()` to the honeypot value — it would let a tags-only honeypot fill through. No nonce is added to the comment form (cached pages would flag legitimate visitors).
- [x] Annotate the raw `$_POST` copies in `class-cf7-integration.php:295-302` with the reason: values are deliberately kept raw/slashed so the shared checks see byte-identical input on the CF7 and comment paths.
- [x] Annotate all 12 `error_log()` calls (`class-detector.php:69`, `class-cf7-integration.php:363`, nine lock-file failures in `class-spam-log.php`/`class-stats.php`, one in `checks/class-duplicate-check.php`) with `phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log` and a per-site reason. The logging stays.
- [x] Keep `load_plugin_textdomain` at `class-antispam-wizard.php:373` and annotate it with the exact reported code plus the reason from the decision above (bundled `.mo` files are the only translation source off WP.org and for the smoke harness).
- [x] Annotate the `slow_db_query_meta_key` finding at the site the run actually reports (`uninstall.php:37`'s `array( 'meta_key' => '_asw_reason' )`, and the Privacy eraser if it is reported there too) with the reason: a one-shot uninstall cleanup / a lookup bounded by at most 50 comment IDs per page.
  - **Outcome:** Per Decision 5, checked against the real run. `ASW_Privacy` reports nothing (the eraser uses `get_comment_meta()`/`delete_comment_meta()`, which the sniff does not flag). `uninstall.php:37` reports `DirectDatabaseQuery.DirectQuery` + `NoCaching`, annotated here with the one-shot-uninstall-cleanup reason, together with the same pair on lines 26 and 40 plus `SchemaChange` on line 40. **Correction (review pass 2):** the initial "no `slow_db_query_meta_key` anywhere" conclusion held only for `--categories=plugin_repo`, which is what `dev/plugin-check.sh` and CI run; re-running with `--categories=general,plugin_repo,security,performance` does report it, once, at the predicted site. It is now annotated on the same ignore line as the `DirectDatabaseQuery` pair (a separate comment line would have detached that ignore from the query), and the broader four-category run is clean: 0 errors, 0 warnings.
- [x] Add regression tests: the honeypot still flags a value consisting only of HTML tags (proving no sanitizer was introduced), the submit-time token still verifies against a slashed `$_POST` value, and `AdminPageTest` covers the `settings-updated` notice rendering for both `true` and a hostile value.
- [x] Verify with `grep -rn "phpcs:ignore" antispam-wizard` that every annotation carries a reason and none is a bare suppression.
- [x] Run `docker compose run --rm test` — must pass before Task 8.
  - **Outcome (task):** Harness now reports **0 errors, 0 warnings** (was 0 errors / 44 warnings). Two genuine fixes (`$_GET['settings-updated']` unslashed + sanitized in both settings views); 59 `phpcs:ignore` + 1 `phpcs:disable`/`phpcs:enable` pair, every one carrying a ` -- ` reason. Beyond the plan's enumerated sites, the run also reported `ValidatedSanitizedInput` warnings on `class-admin-page.php`'s numeric threshold read (`:273`, clamped by `is_numeric()` + `max`/`min`) and the two import handlers' `$_FILES['…']['tmp_name']` / multi-line textarea reads (`:595-602`, `:691-698`); those are annotated in place with their own reasons rather than sanitized, since sanitizing would corrupt the PHP-generated tmp path and collapse the newlines the parsers split on. The grep verification is now a permanent regression guard: `PluginMetadataTest::test_every_phpcs_suppression_carries_a_reason`. Test count 776 → 787.

### Task 8: Verify acceptance criteria

- [x] Run `bash dev/plugin-check.sh` on the default image — must report **0 errors**.
  - **Outcome:** `wordpress:7.1-php8.3-apache`, Plugin Check (PCP) 2.1.0, `--categories=plugin_repo`: `Success: Checks complete. No errors found.` — **0 errors, 0 warnings**, harness exit 0. Down from the 84 errors / 54 warnings baseline.
- [x] Enumerate any remaining warnings: PHPCS-based ones must already be annotated (and therefore absent); list any non-PHPCS residual (readme/plugin-header style checks) and fix it if genuine, otherwise document why it is acceptable for the submission.
  - **Outcome:** Nothing to enumerate — the run reports **zero** warnings, not merely zero errors. No PHPCS residual (all 59 `phpcs:ignore` annotations suppress their sites) and no non-PHPCS residual either: the readme and plugin-header checks in `plugin_repo` pass clean, so no acceptability argument has to be made for the submission.
- [x] Run `WORDPRESS_IMAGE=6.4-php8.1-apache bash dev/plugin-check.sh` once as a control and note any image-specific difference.
  - **Outcome:** `wordpress:6.4-php8.1-apache` (core 6.4.3) reports **0 errors, 0 warnings** as well, exit 0. **No image-specific difference** — the two runs are identical, so the compliance result is not an artifact of the newer core/PHP pair.
- [x] Run `docker compose run --rm test` and confirm the test count has not gone down relative to the pre-change baseline.
  - **Outcome:** `OK (787 tests, 6688 assertions)` on PHP 8.5.7 / PHPUnit 9.6.34. Baseline before this branch was 776; Task 7 raised it to 787. **No regression** — the count is up 11, never down.
- [x] Run `bash dev/smoke.sh` and `WORDPRESS_IMAGE=6.4-php8.1-apache bash dev/smoke.sh` — both must pass, proving detection, admin rendering and CF7 behaviour are unchanged.
  - **Outcome:** Both **PASS** (exit 0). On `7.1-php8.3-apache`: debug.log clean of plugin-attributable problems, 6/6 admin pages rendered, `spam(3 links)='spam'` / `ham(neutral)=0`, and the full CF7 path — bot-check fields present in `form_elements`, token verifies against contact-form ID 6, spam → `spam`, ham → `mail_sent`. On `6.4-php8.1-apache` (core 6.4.3): debug.log clean, 6/6 admin pages, same spam/ham verdicts; **CF7 was SKIPped** because `wp plugin install contact-form-7` failed against the 6.4 floor, which exercises the harness's documented inert-degradation path instead. That is pre-existing harness behaviour on the floor image, not a regression from this branch — CF7 itself is covered on the default image above.
- [x] Run `msgfmt --statistics` on all nine `.po` files — zero untranslated, zero fuzzy.
  - **Outcome:** All nine (`de_DE`, `es_ES`, `fr_FR`, `it_IT`, `ja`, `nl_NL`, `pt_BR`, `ru_RU`, `tr_TR`) report exactly `178 translated messages.` — no untranslated and no fuzzy entries reported for any catalogue, and the counts agree across all nine after the Task 4 regeneration.
- [x] Confirm `git ls-files antispam-wizard | grep -E '(^|/)\.'` returns nothing, and that no `antispam-wizard-1.0.0.zip` was created, modified or committed.
  - **Outcome:** The `git ls-files` grep returns **no matches** — the shipped `.htaccess` is gone and `antispam-wizard/data/index.php` is the tracked guard in its place. No `.zip` is tracked anywhere, and `find` locates no `antispam-wizard-1.0.0.zip` in the working tree; `git status --short` is empty after all four container runs, so the harnesses left nothing behind.

### Task 9: Update documentation and wire CI

**Files:**
- Modify: `.github/workflows/ci.yml`, `README.md`, `CLAUDE.md`

- [x] Add a `plugin-check` job to `.github/workflows/ci.yml` running `bash dev/plugin-check.sh` on the default image (`7.1-php8.3-apache`) for push and pull_request, alongside `unit` and `wp-smoke`. Wired last so CI is never knowingly red mid-branch.
- [x] Document `dev/plugin-check.sh` in `README.md` next to the smoke harness: what it does, the `WORDPRESS_IMAGE` override, that it checks tracked files only, and that it exits non-zero on any error.
- [x] Update `CLAUDE.md`: add a "Plugin Check" subsection under Commands describing the harness and its isolation model; retire the "uninstall.php must stay byte-identical" rule and replace it with the new baseline (the removed data set must stay identical and be asserted by `UninstallTest`); replace the `data/.htaccess` mention with the `data/index.php` guard; document the new bootstrap stubs (`%i` handling in the `$wpdb` stub's `prepare()`, `wp_delete_file()`) in the test-bootstrap paragraph in the same style as the existing entries; note the convention that every Plugin Check deviation is an annotated `phpcs:ignore` with a reason.
- [x] Run `docker compose run --rm test` one final time.

## Post-Completion (manual)

- Review the single PR with per-finding-group commits.
- Optionally run the full (all-categories) Plugin Check for information before submitting to WP.org.
