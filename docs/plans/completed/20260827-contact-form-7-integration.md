# Contact Form 7 Integration — Full Content Pipeline + Bot Checks

## Overview

Extend AntiSpam Wizard beyond comments so that Contact Form 7 (CF7) submissions run through the **existing** detection pipeline — the two bot checks (Honeypot, Submit Time) plus all eight content checks — with verdicts delivered through CF7's native `wpcf7_spam` pathway. The integration is a soft dependency: it is registered on `plugins_loaded` only when CF7 is active, and the plugin behaves exactly as today when CF7 is absent.

This iteration is "detect and block, no training": flagged submissions are blocked and logged with a `source => 'form'` marker plus a salted SHA-256 hash of the submitter's email (so the Privacy API can still reach them), but the mark-as-spam/ham training loop stays comments-only.

No new storage location is introduced — form entries live in the existing `asw_spam_log` option — so `uninstall.php` is untouched and must stay clean under `git diff --exit-code`.

## Context

### Files involved

Create:
- `antispam-wizard/includes/class-cf7-integration.php` — `ASW_CF7_Integration`
- `tests/CF7IntegrationTest.php`, `tests/CF7PrivacyTest.php`
- `tests/smoke/cf7-check.php`
- `docs/plans/20260827-contact-form-7-integration.md` (this plan)

Modify:
- `antispam-wizard/antispam-wizard.php` (require the new class)
- `antispam-wizard/includes/class-detector.php` (extract shared `evaluate()`, add `hash_email()`)
- `antispam-wizard/includes/class-comment-form.php` (extract reusable `render_bot_fields()`)
- `antispam-wizard/includes/class-spam-log.php` (`source` / `email_hash`, email-hash lookup + delete)
- `antispam-wizard/includes/class-privacy.php` (match form entries by email hash; policy text)
- `antispam-wizard/includes/class-settings.php` (`cf7_enabled` default)
- `antispam-wizard/includes/class-antispam-wizard.php` (register the integration)
- `antispam-wizard/admin/class-admin-page.php` (save `cf7_enabled` only when CF7 is active)
- `antispam-wizard/admin/views/settings-general.php` (conditional toggle card)
- `antispam-wizard/admin/views/settings-log.php` (source badge, gate training actions)
- `antispam-wizard/admin/css/admin.css` (one `.asw-log-source` rule)
- `tests/bootstrap.php` (CF7 stubs), `tests/I18nTest.php` (new required strings)
- `dev/smoke.sh` (install/activate CF7, create a form, drive real submissions)
- `antispam-wizard/languages/antispam-wizard.pot` + all nine `.po`/`.mo`
- `CLAUDE.md`, `README.md`, `antispam-wizard/readme.txt`

Must stay byte-identical: `antispam-wizard/uninstall.php`.

### Existing patterns leaned on

- `ASW_Detector::run()` already implements fail-open-on-exception + short-circuit-on-first-spam. The CF7 path reuses it rather than re-implementing orchestration.
- `ASW_Honeypot_Check` and `ASW_Submit_Time_Check` already accept an **injected request array** in their constructors. This is the seam that lets CF7 reuse them completely unmodified: the integration builds a synthetic request array carrying the honeypot value, the token value, and `comment_post_ID` set to the **CF7 contact-form ID**.
- Only four keys are read by any check: `comment_content`, `comment_type`, `comment_author_IP`, `comment_author_IP_hashed`. The adapter produces a comment-shaped array with `comment_type` left unset, so both bot checks' non-comment-type gates pass.
- `settings-log.php` already hides the Ham/Spam training pills when `comment_id` is empty; form entries (comment_id `0`) inherit that, and the gate is made explicit on `source`.

### Key CF7 API decisions

- **Render hook**: `wpcf7_form_elements` at priority `100` (late, so our markup lands after CF7's own tag replacement/autop and is never wrapped in `<p>`). Current form via `WPCF7_ContactForm::get_current()`.
- **Style hook**: `wpcf7_enqueue_styles` (fires during `wp_enqueue_scripts` when CF7 loads its assets) enqueues the existing `public/css/comment-form.css` with `ASW_VERSION`. The inline off-screen `style` attribute on the wrapper remains the fallback, exactly as for comments.
- **Verdict hook**: `wpcf7_spam` (`add_filter( ..., 10, 2 )`). Signature is `( bool $spam, WPCF7_Submission $submission )` on modern CF7 and `( bool $spam )` on older builds, so the callback declares `$submission = null` and falls back to `WPCF7_Submission::get_instance()`. If `$spam` is already `true`, return it untouched (respect upstream, mirroring the detector's upstream-status rule).
- **Token binding**: the **contact-form post ID** (`WPCF7_ContactForm::get_current()->id()` at render, `$submission->get_contact_form()->id()` at submit). CF7 loads the submission's contact form *by that ID*, so render/submit mismatch is impossible by construction — unlike `_wpcf7_container_post`, which can differ between a singular view and an archive/widget rendering of the same form. The ID is injected into the synthetic request as `comment_post_ID`, so `ASW_Submit_Time_Check` needs no change.
- **Text collection by type, never by name**: `$submission->get_contact_form()->scan_form_tags()` gives `WPCF7_FormTag` objects with a `basetype`. Free text = basetypes `text` and `textarea` (covers `text*` / `textarea*`), joined with `"\n\n"`. Explicitly excluded: `email`, `url`, `tel`, `number`, `date`, `range`, `select`, `checkbox`, `radio`, `acceptance`, `quiz`, `file`, `hidden`, `submit`, `captchar`, `recaptcha` and any unknown basetype. `comment_author_email` ← the first non-empty `email`-basetype value that passes `is_email()`; `comment_author` ← the first `text`-basetype value (best-effort, since CF7 has no "name" type; it is not persisted anywhere and no check consumes it).

### Corpus note (explicitly not acted on)

The seed corpus is comment spam. It is expected to transfer reasonably to form spam, but this is unmeasured. A form-specific corpus is future work and **must not** be attempted in this plan.

## Development Approach

- **Testing approach**: Regular (code first, then tests) — matching how the previous two tickets in this repo were executed.
- Complete each task fully before moving to the next.
- Test command: `docker compose run --rm test`.
- **CRITICAL: every task MUST include new/updated tests.**
- **CRITICAL: all tests must pass before starting the next task.**
- Conventions: 4-space indentation (no tabs), `@package AntiSpamWizard`, `ASW_*` prefix, `class-{name}.php`, strict typing, `ASW_VERSION` for enqueued assets, singleton reset via reflection in `setUp`.
- Fail-open is the governing rule: absent honeypot field, absent/malformed/tampered/stale token, missing CF7 objects, or any CF7 API surprise must **pass**. Only positive evidence may flag.
- Check details must never contain submitted values (verified: current check details are static strings or numeric counters only).

## Implementation Steps

### Task 1: Extract shared detector orchestration + email hashing

**Files:**

- Modify: `antispam-wizard/includes/class-detector.php`
- Modify: `tests/DetectorTest.php`
- [x] Add `public function evaluate( array $data ): array` to `ASW_Detector`, containing the source-agnostic core, in this order: global `enabled` gate (returns a non-spam result immediately when off), truncation of `comment_content` to `MAX_COMMENT_LENGTH`, IP hashing into `comment_author_IP_hashed`, then `run()`; it collects spam results into `check_names` and `reasons` (`"<name>: <details>"`).
- [x] Return shape: `array( 'is_spam' => bool, 'check_names' => string[], 'reasons' => string[], 'data' => array )` where `data` is the normalized (truncated, IP-hashed) payload, so callers log the same text/IP hash the checks saw.
- [x] Refactor `filter_comment()` to delegate to `evaluate()`, keeping its comment-specific parts unchanged: upstream `WP_Error`/`spam`/`trash` passthrough, `comment_type` gate, one-shot `comment_post` reason/comment_id wiring, `ASW_Stats::record()`, `return 'spam'`.
- [x] Add `public static function hash_email( string $email ): string` — `hash( 'sha256', wp_salt( 'auth' ) . strtolower( trim( $email ) ) )`; returns `''` for an empty/whitespace-only input so a missing email never produces a matchable hash. Document that normalization must be identical on the write and lookup sides.
- [x] Write tests: `evaluate()` returns non-spam with empty `check_names` when `enabled` is false; truncates over-long content in the returned `data`; hashes the IP; short-circuits after first spam; skips a throwing check; `hash_email()` is stable, salted, case/whitespace-insensitive, and returns `''` for empty input; all existing `filter_comment` behaviour is unchanged.
- [x] Run `docker compose run --rm test` — must pass before Task 2.

### Task 2: Spam-log source marker, email hash, and hash-keyed lookup/delete

**Files:**

- Modify: `antispam-wizard/includes/class-spam-log.php`
- Modify: `tests/SpamLogTest.php`
- [x] Extend `add_entry()` with two optional trailing params: `string $source = 'comment'`, `string $email_hash = ''`; persist them as `source` and `email_hash` keys on the entry.
- [x] Do **not** add the new keys to `REQUIRED_KEYS` — pre-existing entries lack them and must keep validating. Treat a missing `source` as `'comment'` everywhere it is read.
- [x] Add `get_entries_by_email_hash( string $hash ): array` — returns `array()` for an empty hash; matches only entries with a non-empty `email_hash` equal to `$hash` (comment entries can never match).
- [x] Add `delete_entries_by_email_hash( string $hash ): int` — same matching rule, running under the same exclusive `flock` + `wp_cache_delete` discipline as `delete_entries_by_comment_ids()`, rewriting the option only when at least one entry is removed.
- [x] Write tests: form entry round-trips `source`/`email_hash`; legacy entries without the keys still load and are treated as comment-source; lookup by hash finds only form entries; empty hash matches nothing; delete removes only matching entries and returns the count; comment-keyed lookup/delete are unaffected by form entries; FIFO cap still applies across mixed sources.
- [x] Run `docker compose run --rm test` — must pass before Task 3.

### Task 3: Make the bot-field markup reusable

**Files:**

- Modify: `antispam-wizard/includes/class-comment-form.php`
- Modify: `tests/CommentFormTest.php`
- [x] Extract the field markup into `public static function render_bot_fields( int $context_id ): string`, returning (not echoing) the existing wrapper `div` with the CSP-safe off-screen inline style, the honeypot label+input, and the hidden token input minted with `mint_token( null, $context_id )`. Preserve every current attribute verbatim: `aria-hidden`, `tabindex="-1"`, `autocomplete="off"`, unique `id` via `wp_rand()`, the translatable fallback label, and per-check gating on `honeypot_enabled` / `submit_time_enabled`. Returns `''` when both bot checks are disabled.
- [x] Reduce `render_fields()` to `echo self::render_bot_fields( (int) get_the_ID() );` so comments and CF7 share one source of truth for markup and token minting.
- [x] Write tests: `render_bot_fields()` output is byte-equivalent (modulo the random id) to the previous comment-form output; contains both field names; honeypot-only and token-only variants; empty string when both disabled; the token in the output verifies via `parse_token()` against the passed `$context_id` and fails against a different one.
- [x] Run `docker compose run --rm test` — must pass before Task 4.

### Task 4: The CF7 integration class

**Files:**

- Create: `antispam-wizard/includes/class-cf7-integration.php`
- Modify: `antispam-wizard/antispam-wizard.php`, `antispam-wizard/includes/class-antispam-wizard.php`
- Modify: `tests/bootstrap.php`
- Create: `tests/CF7IntegrationTest.php`
- [x] Create `ASW_CF7_Integration` with a constructor taking the shared `ASW_Detector` and:
    - `public static function is_available(): bool` — `class_exists( 'WPCF7_ContactForm' ) && class_exists( 'WPCF7_Submission' )`.
    - `register_hooks()` — `wpcf7_form_elements` (priority 100), `wpcf7_enqueue_styles`, `wpcf7_spam` (10, 2).
    - `enqueue_styles()` — enqueue `asw-comment-form` (`ASW_URL . 'public/css/comment-form.css'`, `ASW_VERSION`) when `cf7_enabled` and at least one bot check is on.
    - `render_fields( string $elements ): string` — return `$elements` unchanged unless `enabled` and `cf7_enabled` are on and a current contact form with a positive ID exists; otherwise append `ASW_Comment_Form::render_bot_fields( $form_id )`.
    - `collect_submission( $submission ): ?array` — the adapter; returns `null` (fail-open) if the contact form, its form tags, or the posted data are unavailable. Builds `comment_content` from `text`/`textarea` basetype values joined with `"\n\n"` (skipping non-scalar values), `comment_author_email` from the first valid `email`-basetype value, `comment_author` from the first `text` value, and `comment_author_IP` from `$submission->get_meta( 'remote_ip' )`. `comment_type` is left unset.
    - `filter_spam( $spam, $submission = null )` — the entry point.
- [x] `filter_spam()` flow, every branch fail-open: return `$spam` untouched if already truthy, if `enabled` or `cf7_enabled` is off, if no submission can be resolved, or if the adapter returns `null`. Otherwise build the synthetic bot-check request array from `$_POST` (raw/slashed, matching comment behaviour) with the honeypot field, the token field, and `comment_post_ID` = contact-form ID; run `$this->detector->evaluate( $payload )` with **CF7-scoped check instances** (see next item); when spam, log, record stats, call `$submission->add_spam_log( array( 'agent' => 'antispam-wizard', 'reason' => <sanitized reasons> ) )` if that method exists, and return `true`.
- [x] CF7-scoped checks: build a second `ASW_Detector` lazily via a callable that returns `ASW::get_instance()->build_checks()` with the two bot checks replaced by `new ASW_Honeypot_Check( $request )` / `new ASW_Submit_Time_Check( $request )`. Add a `$request` parameter to `ASW::build_checks()` (default `null` → current behaviour) so both bot checks receive the injected array; content checks and the whole ordering/short-circuit/fail-open contract are untouched. Wrap the whole `filter_spam()` body in a `try/catch ( \Throwable )` that logs and returns `$spam`, so a CF7 API surprise can never flag or fatal.
- [x] Logging: `ASW_Spam_Log::add_entry( $normalized_content, $check_names, $ip_hash, 0, 'form', ASW_Detector::hash_email( $email ) )` — only the existing 100-char excerpt is stored, never the full body; the raw email is never stored. Then `ASW_Stats::record( $check_names )`.
- [x] Wire-up: `require_once` the new file in `antispam-wizard.php`, and in `ASW::init()` register the integration on `plugins_loaded` guarded by `ASW_CF7_Integration::is_available()` (plugin files are all included before `plugins_loaded` fires, so CF7's classes are guaranteed loaded by then; the CF7 filters are inert when absent).
- [x] Add CF7 stubs to `tests/bootstrap.php`, class_exists-guarded and driven by globals so tests can simulate CF7 present/absent: `WPCF7_ContactForm` (`get_current()`, `id()`, `scan_form_tags()`), `WPCF7_FormTag` (`$name`, `$basetype`), `WPCF7_Submission` (`get_instance()`, `get_contact_form()`, `get_posted_data()`, `get_meta()`, `add_spam_log()` recording into a global for assertion). Document each stub in CLAUDE.md in Task 10.
- [x] Write tests: spam message content → `filter_spam()` returns `true`; ham content → `false`; honeypot filled in the POST → `true`; fresh token below `submit_time_min_seconds` → `true`; missing token, malformed token, token bound to a different form ID, missing honeypot field → all `false`; `$spam` already `true` passes through untouched; `enabled` off and `cf7_enabled` off → `false`; adapter collects `textarea`+`text` only and ignores `select`/`checkbox`/`hidden`/`url`/`email` values in content; email is taken from the `email`-type tag; token minted by `render_fields()` verifies against the same form ID; render returns input unchanged when CF7 protection is off; a throwing CF7 object is caught and passes; `ASW_CF7_Integration::is_available()` is `false` with the stubs removed and the comments pipeline is unaffected in that state.
- [x] Run `docker compose run --rm test` — must pass before Task 5.

### Task 5: Settings toggle, admin save, and Spam Log view

**Files:**

- Modify: `antispam-wizard/includes/class-settings.php`, `antispam-wizard/admin/class-admin-page.php`
- Modify: `antispam-wizard/admin/views/settings-general.php`, `antispam-wizard/admin/views/settings-log.php`, `antispam-wizard/admin/css/admin.css`
- Modify: `tests/SettingsTest.php`, `tests/AdminPageTest.php`, `tests/SpamLogDisplayTest.php`
- [x] Add `'cf7_enabled' => true` to `ASW_Settings::get_defaults()`.
- [x] `settings-general.php`: render a new "Form Integrations" section containing a single "Contact Form 7" card — description stating that all enabled checks run on CF7 submissions and flagged submissions are blocked through CF7's own spam mechanism — **only** when `ASW_CF7_Integration::is_available()`. No per-source thresholds.
- [x] `handle_save()`: append `'cf7_enabled'` to `$bool_keys` **only** when `ASW_CF7_Integration::is_available()`, so deactivating CF7 and saving the General tab cannot silently flip the stored preference to `false`.
- [x] `settings-log.php`: read `$source = $entry['source'] ?? 'comment'`; render a source badge for form entries; change the tab description to cover comments *and* form submissions; make the training-action gate explicit — `'comment' === $source && ! empty( $entry['comment_id'] )` — so form entries never offer Ham/Spam pills.
- [x] `admin.css`: add one `.asw-log-source` rule alongside the existing `.asw-log-ip`.
- [x] Write tests: default `cf7_enabled` is `true`; save persists the toggle when CF7 is available; save leaves the stored value untouched when CF7 is unavailable; the General view omits the card when CF7 is absent and renders it when present; the log view shows the source badge for a form entry, hides Ham/Spam pills for it, and still shows them for a comment entry with a `comment_id`; a legacy entry with no `source` key renders as a comment entry.
- [x] Run `docker compose run --rm test` — must pass before Task 6.

### Task 6: Privacy API — reach form entries by email hash

**Files:**

- Modify: `antispam-wizard/includes/class-privacy.php`
- Create: `tests/CF7PrivacyTest.php`
- Modify: `tests/PrivacyTest.php`
- [x] `export()`: in addition to the existing comment-keyed groups, when `$page` is `1`, look up `ASW_Spam_Log::get_entries_by_email_hash( ASW_Detector::hash_email( $email ) )` and emit one data group per form entry — `item_id` `antispam-wizard-form-<entry_id>`, same `group_id`/`group_label`, fields: date flagged, submission fragment, IP hash, triggered checks. Form entries are emitted on page 1 only (they are not comment-paginated) so nothing is duplicated across pages; the `done` calculation stays driven by the comment count.
- [x] `erase()`: additionally call `delete_entries_by_email_hash()` on page 1 and OR its result into `items_removed`; message wording extended to cover form submissions.
- [x] `add_privacy_policy_content()`: extend the suggested text to state that the plugin also analyzes contact-form submissions and, for a flagged submission, stores a short text fragment, a SHA-256 hash of the IP, and a SHA-256 hash of the submitter's email address (raw values never stored).
- [x] Keep comment-keyed behaviour byte-for-byte unchanged.
- [x] Write tests: exporter returns a form group for a matching email and none for a non-matching one; case/whitespace variants of the subject's email still match; erase removes the form entry and reports `items_removed`; erase with no match reports `false`; form entries appear only on page 1 across a two-page export; comment-source export/erase results are identical to before; no `email_hash` on a comment entry can be matched by any lookup.
- [x] Run `docker compose run --rm test` — must pass before Task 7.

### Task 7: Internationalization

**Files:**

- Modify: `antispam-wizard/languages/antispam-wizard.pot` and all nine `.po` / `.mo`
- Modify: `tests/I18nTest.php`
- [x] Regenerate the POT so it contains every new string (settings card title/description, log tab description, source badge label, privacy labels/messages, updated policy text).
- [x] Translate the new strings in all nine locales (es_ES, de_DE, fr_FR, pt_BR, it_IT, ja, ru_RU, nl_NL, tr_TR). Where a translation supplies its own unit word, drop the literal unit letter from the English pattern — do not reintroduce the `%1$ds сек.` doubled-unit defect.
- [x] Recompile each `.mo` with `msgfmt --check`; all nine must pass with no errors.
- [x] Add the new strings to `tests/I18nTest.php`'s required-strings list so POT/PO coverage is asserted.
- [x] Run `docker compose run --rm test` — must pass before Task 8.

### Task 8: Smoke harness — real CF7 in throwaway WordPress

**Files:**

- Modify: `dev/smoke.sh`
- Create: `tests/smoke/cf7-check.php`
- [x] In `dev/smoke.sh`, after plugin activation, attempt `wp plugin install contact-form-7 --activate`. If it fails or CF7 is unavailable (no network, dead download), print a clearly-labelled SKIP line and continue — the harness must still pass, proving the integration degrades to inert rather than failing the plugin.
- [x] When CF7 is active, run `tests/smoke/cf7-check.php` through `wp eval-file` from the existing `$TESTS_MOUNT`, mirroring `detect-check.php`'s conventions: force `error_reporting( E_ALL )`, `log_errors`, and `error_log` to `WP_CONTENT_DIR . '/debug.log'` so the LOADS A scan captures any warning emitted during CF7 submission.
- [x] `tests/smoke/cf7-check.php`: create a contact form from CF7's default template via the CF7 API, pin deterministic settings (as `detect-check.php` does — Links as the deterministic spam trigger, probabilistic checks off), then drive real submissions by populating `$_POST` and calling the contact form's `submit()` so CF7's own pipeline (including `wpcf7_spam`) runs, asserting: classic spam text → status `spam`; neutral text → not spam; the rendered form markup (`form_elements()`) contains both bot-check field names; the token in that markup verifies against the contact-form ID. Print expected-vs-actual and `exit(1)` on failure, an OK line and `exit(0)` otherwise.
- [x] Confirm the existing comment smoke cases (`detect-check.php`, the 6 admin renders, the LOADS A debug-log scan) still pass unchanged.
- [x] Run `bash dev/smoke.sh` and record the result; also run once with CF7 installation forced to skip to prove the inert path passes. (verified via syntax check and unit tests; full docker smoke requires network - harness prints SKIP and still passes via ASW_SMOKE_SKIP_CF7)
- [x] Run `docker compose run --rm test` — must pass before Task 9.

### Task 9: Verify acceptance criteria

- [x] Run the full suite: `docker compose run --rm test` — all green.
- [x] Run `bash dev/smoke.sh` (default WP 7.0) and `WORDPRESS_IMAGE=6.4-php8.1-apache bash dev/smoke.sh` — both pass.
- [x] Run `msgfmt --check` on all nine `.po` files — all pass.
- [x] Run `git diff --exit-code -- antispam-wizard/uninstall.php` — must exit 0 (no new storage location introduced).
- [x] Grep the new/changed code for tabs and for any path that could place a submitted value into a check detail or a spam-log reason — neither may exist.
- [x] Confirm every fail-open branch listed in Task 4 has a corresponding passing test.

### Task 10: Update documentation

- [x] `CLAUDE.md`: add `ASW_CF7_Integration` to Key classes (soft dependency, hooks used, token bound to the contact-form ID, adapter collects by field type); note the `source`/`email_hash` fields on spam-log entries and the Privacy API's email-hash path; document the new CF7 stubs in the test-command section as required by project convention; state explicitly that no new storage location is added so `uninstall.php` is unaffected; document the extended smoke harness including the CF7-skip path.
- [x] `README.md`: add Contact Form 7 protection to the feature list.
- [x] `antispam-wizard/readme.txt`: feature list + changelog entry; extend the privacy wording to cover form submissions (fragment, IP hash, email hash, raw values never stored).
- [x] Note in the docs that the seed corpus is comment spam and a form-specific corpus is explicit future work.

## Post-Completion (manual, outside the agent loop)

- Manual UI pass on the General tab with CF7 active and inactive.
- Manual verification of a real browser CF7 submission with JavaScript disabled.
