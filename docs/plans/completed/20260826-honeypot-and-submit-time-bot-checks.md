# Honeypot and Submit-Time Bot Checks (checks #9 and #10)

## Overview

Add two pre-content bot checks to the free core: a honeypot decoy field and a submit-time (time-to-comment) check. Both are `ASW_Check` implementations, wired into `ASW::build_checks()` ahead of the eight existing checks so the detector's short-circuit-on-first-spam skips the CPU-heavier content checks on bot traffic. A new front-end helper class renders the two form fields into the WordPress comment form and enqueues a small stylesheet that hides the honeypot without relying on inline styles (survives a strict `style-src` CSP). Everything fails open: absent, malformed, tampered, or stale form data passes. No new storage, no external calls, no changes to `uninstall.php` or `ASW_Privacy`.

## Context

**Files involved**

- Create: `antispam-wizard/includes/class-comment-form.php` (`ASW_Comment_Form` — field rendering, HMAC token mint/verify, style enqueue)
- Create: `antispam-wizard/includes/checks/class-honeypot-check.php` (`ASW_Honeypot_Check`)
- Create: `antispam-wizard/includes/checks/class-submit-time-check.php` (`ASW_Submit_Time_Check`)
- Create: `antispam-wizard/public/css/comment-form.css` (first front-end asset; `public/` is a new directory)
- Modify: `antispam-wizard/antispam-wizard.php` (require the three new classes)
- Modify: `antispam-wizard/includes/class-antispam-wizard.php` (`init()` registers `ASW_Comment_Form`; `build_checks()` returns the bot checks first)
- Modify: `antispam-wizard/includes/class-settings.php` (defaults + integer bounds)
- Modify: `antispam-wizard/admin/class-admin-page.php` (`handle_save()` bool/int key lists)
- Modify: `antispam-wizard/admin/views/settings-general.php` (new "Bot Checks" section with two cards)
- Modify: `antispam-wizard/admin/views/settings-stats.php`, `antispam-wizard/admin/views/settings-log.php` (check label maps)
- Modify: `antispam-wizard/admin/css/admin.css` (two badge colour variables + two `.asw-badge--*` rules)
- Modify: `tests/bootstrap.php` (stubs: `is_singular()`, `comments_open()`)
- Modify: `tests/ASWTest.php`, `tests/DetectorTest.php`, `tests/SettingsTest.php`, `tests/AdminPageTest.php`, `tests/StatsDisplayTest.php`, `tests/SpamLogDisplayTest.php`
- Create: `tests/HoneypotCheckTest.php`, `tests/SubmitTimeCheckTest.php`, `tests/CommentFormTest.php`, `tests/I18nTest.php`
- Modify: `tests/smoke/detect-check.php`
- Modify: `antispam-wizard/languages/antispam-wizard.pot`, all nine `*.po` + recompiled `*.mo`
- Modify: `CLAUDE.md`, `README.md`, `antispam-wizard/readme.txt`
- Untouched (verified by `git diff --exit-code` in the final task): `antispam-wizard/uninstall.php`, `antispam-wizard/includes/class-privacy.php`

**Related patterns**

- Check shape: `includes/checks/class-emoji-check.php` — read `ASW_Settings::get_all()`, early-return a non-spam `ASW_Check_Result` when `*_enabled` is falsy with details `__( 'check disabled', … )`, return `ASW_Check_Result( true, '<name>', <details> )` on a match.
- Detector already skips non-`comment` types globally (`class-detector.php`) and hashes IPs with `wp_salt( 'auth' )` — the submit-time token reuses the same salt source.
- Admin toggle/param card markup and `$render_toggle` closure in `settings-general.php`; per-check labels duplicated in `settings-stats.php` and `settings-log.php`.
- Tests reset singletons/globals via reflection in `setUp()` (see `tests/ASWTest.php`).

**Design decisions**

- Field names live as class constants on `ASW_Comment_Form` so tests, smoke, and checks share one source: `HONEYPOT_FIELD = 'comment_url_confirm'`, `TIMESTAMP_FIELD = 'comment_form_token'`. Innocuous, no `asw_` prefix, no collision with WP core comment fields (`comment`, `author`, `email`, `url`, `comment_post_ID`, `comment_parent`).
- Token format `"<unix_ts>.<hmac>"` where `hmac = hash_hmac( 'sha256', (string) $ts, wp_salt( 'auth' ) )`, verified with `hash_equals()`. Replay of an old token only makes the elapsed interval larger, i.e. it passes — acceptable, because only "too fast" may flag.
- **CSP-safe hiding (per the answered question):** hiding lives in the enqueued external stylesheet `public/css/comment-form.css` (`.asw-hp-field { position:absolute; left:-10000px; width:1px; height:1px; overflow:hidden; }`), which survives `style-src 'self'`. Belt-and-braces: the same declarations also repeated as an inline `style` attribute (helps when the stylesheet is deferred/stripped, dropped under strict CSP without harm), plus `aria-hidden="true"`, `tabindex="-1"`, `autocomplete="off"`, and a visible "Leave this field empty" label as the last-resort fallback if all hiding fails. Trade-off documented in a file-level docblock: off-screen positioning (not `display:none`) keeps the fallback label readable for a human if CSS is lost, at the cost of being layout-visible to headless bots that compute geometry; and the plugin gains one extra front-end HTTP request on pages with an open comment form.
- Hook choice: `comment_form` action (fires inside `<form>` for both logged-in and anonymous visitors, unlike `comment_form_after_fields`). Stylesheet enqueued on `wp_enqueue_scripts` gated by `is_singular() && comments_open()`.
- Checks read the request array through an optional constructor argument (`?array $request = null`, falling back to `$_POST` at check time, values passed through `wp_unslash()`), which keeps them unit-testable without touching superglobals in production code.
- Version stays `1.0.0` (plugin is unreleased; `PluginMetadataTest` asserts header/readme consistency) — the two checks are added to the existing `= 1.0.0 =` changelog block rather than opening a new version.
- Note (not in scope): `ASW::build_checks()` loads and trains the corpus eagerly before any check runs, so ordering saves the classification cost but not the corpus-load cost. Making classifier construction lazy is a separate refactor; the plan does not attempt it.

**Dependencies:** none new. `msgfmt`/`xgettext`/`msgmerge` are available locally.

## Development Approach

- **Testing approach**: Regular (code first, then tests within the same task).
- Complete each task fully before moving to the next.
- **CRITICAL: every task MUST include new/updated tests**
- **CRITICAL: all tests must pass (`docker compose run --rm test`) before starting the next task**

## Implementation Steps

### Task 1: Settings defaults and bounds

**Files:**
- Modify: `antispam-wizard/includes/class-settings.php`
- Modify: `tests/SettingsTest.php`

- [x] add defaults `honeypot_enabled => true`, `submit_time_enabled => true`, `submit_time_min_seconds => 5` in `get_defaults()`, grouped with a comment block matching the existing per-check sections
- [x] add `'submit_time_min_seconds' => array( 1, 300 )` to `get_integer_bounds()`
- [x] update the `get_defaults()` docblock ("Default settings for all 10 checks")
- [x] write tests: defaults present with expected values; bounds entry present; `get_all()` merges a saved partial option without dropping the new keys
- [x] run `docker compose run --rm test` — must pass before Task 2

### Task 2: `ASW_Comment_Form` — field rendering, HMAC token, CSS asset

**Files:**
- Create: `antispam-wizard/includes/class-comment-form.php`
- Create: `antispam-wizard/public/css/comment-form.css`
- Modify: `antispam-wizard/antispam-wizard.php`
- Modify: `tests/bootstrap.php`
- Create: `tests/CommentFormTest.php`

- [x] implement `ASW_Comment_Form` with constants `HONEYPOT_FIELD`, `TIMESTAMP_FIELD`; `register_hooks()` (`comment_form` → `render_fields`, `wp_enqueue_scripts` → `enqueue_styles`); `render_fields()`; `enqueue_styles()`; `public static function mint_token( ?int $timestamp = null ): string`; `public static function parse_token( string $raw ): ?int` (returns null on malformed input or HMAC mismatch, using `hash_equals()`)
- [x] `render_fields()` prints the wrapper with class `asw-hp-field`, inline style fallback, `aria-hidden="true"`, the visible fallback label, the text input (`autocomplete="off"`, `tabindex="-1"`, empty value), and the hidden token input; all attributes escaped with `esc_attr()`, label via `esc_html_e()`
- [x] add `public/css/comment-form.css` with the off-screen hiding rule and a header comment stating the CSP rationale; document the trade-off in the class docblock
- [x] `require_once` the new class in `antispam-wizard.php`
- [x] add `is_singular()` and `comments_open()` stubs to `tests/bootstrap.php`, switchable via globals (`$wp_is_singular`, `$wp_comments_open`, both defaulting to true)
- [x] write tests: markup contains both field names, `aria-hidden`/`tabindex`/`autocomplete`/hidden-class attributes, and a well-formed token; `mint_token()`/`parse_token()` round-trip; `parse_token()` returns null for empty, garbage, missing-separator, non-numeric-timestamp, and tampered-HMAC input; stylesheet enqueued when singular + comments open, not enqueued otherwise; CSS file exists at the enqueued path
- [x] run `docker compose run --rm test` — must pass before Task 3

### Task 3: `ASW_Honeypot_Check`

**Files:**
- Create: `antispam-wizard/includes/checks/class-honeypot-check.php`
- Modify: `antispam-wizard/antispam-wizard.php`
- Create: `tests/HoneypotCheckTest.php`

- [x] implement the check (name `honeypot`): disabled → pass; `comment_type` set and not `comment` → pass; field absent from the request → pass (fail-open for custom/AJAX forms); present but empty/whitespace after `wp_unslash()` + `trim()` → pass; non-empty → spam
- [x] spam details must not contain the submitted value (nothing from the honeypot is ever persisted to the log or `_asw_reason`)
- [x] `require_once` in `antispam-wizard.php`
- [x] write tests: filled → spam; empty string → pass; whitespace-only → pass; field missing → pass; disabled → pass; `comment_type = 'trackback'`/`'pingback'` with a filled field → pass; details string contains no submitted value
- [x] run `docker compose run --rm test` — must pass before Task 4

### Task 4: `ASW_Submit_Time_Check`

**Files:**
- Create: `antispam-wizard/includes/checks/class-submit-time-check.php`
- Modify: `antispam-wizard/antispam-wizard.php`
- Create: `tests/SubmitTimeCheckTest.php`

- [x] implement the check (name `submit_time`): disabled → pass; non-`comment` type → pass; token absent → pass; `parse_token()` null (malformed or tampered) → pass; elapsed `< 0` (future timestamp / clock skew) → pass; elapsed `>= submit_time_min_seconds` → pass (covers cached pages serving stale timestamps); elapsed `< min` → spam with details naming elapsed and configured minimum
- [x] `require_once` in `antispam-wizard.php`
- [x] write tests: freshly minted token with min 10 → spam; token minted 120s ago → pass; missing token → pass; malformed token → pass; tampered HMAC → pass; future timestamp → pass; disabled → pass; non-comment type with a fresh token → pass
- [x] run `docker compose run --rm test` — must pass before Task 5

### Task 5: Pipeline wiring and ordering

**Files:**
- Modify: `antispam-wizard/includes/class-antispam-wizard.php`
- Modify: `tests/ASWTest.php`
- Modify: `tests/DetectorTest.php`

- [x] `ASW::init()` instantiates `ASW_Comment_Form` and calls `register_hooks()` (unconditionally; the hooks are inert in admin/cron contexts)
- [x] `build_checks()` returns `ASW_Honeypot_Check` and `ASW_Submit_Time_Check` first, ahead of the eight existing checks; update the method docblock with the ordering rationale and the note that corpus loading still happens up front
- [x] write/update tests: `build_checks()` returns 10 checks with the two bot checks at indices 0 and 1 (update the existing `assertCount( 8, … )` assertions); `init()` registers the `comment_form` and `wp_enqueue_scripts` hooks; a detector-level test proving a spam verdict from a bot check short-circuits so later (content) checks are never invoked
- [x] run `docker compose run --rm test` — must pass before Task 6

### Task 6: Admin UI, spam log, and statistics plumbing

**Files:**
- Modify: `antispam-wizard/admin/class-admin-page.php`
- Modify: `antispam-wizard/admin/views/settings-general.php`
- Modify: `antispam-wizard/admin/views/settings-stats.php`
- Modify: `antispam-wizard/admin/views/settings-log.php`
- Modify: `antispam-wizard/admin/css/admin.css`
- Modify: `tests/AdminPageTest.php`, `tests/StatsDisplayTest.php`, `tests/SpamLogDisplayTest.php`

- [x] add `honeypot_enabled` / `submit_time_enabled` to the bool key list and `submit_time_min_seconds` to the int key list in `handle_save()`
- [x] add a "Bot Checks" collapsible section at the top of `settings-general.php` with two cards following the existing markup: Honeypot (badge, title, description, toggle) and Submit Time (badge, title, description, "Min seconds" param with `min="1" max="300"` plus an `asw-param-hint` explaining the caching fail-open behaviour)
- [x] add `'honeypot' => 'Honeypot'` and `'submit_time' => 'Submit Time'` to the `$check_meta` maps in the stats and log views (log entries also need `'mod' => 'honeypot'` / `'submit-time'`)
- [x] add `--asw-badge-honeypot-bg/fg` and `--asw-badge-submit-time-bg/fg` variables and the two `.asw-badge--*` rules in `admin.css`
- [x] write tests: saving the General tab persists both toggles (checked and unchecked) and clamps `submit_time_min_seconds` below/above bounds; the General view renders both field names; stats view lists both checks (including zero-count when enabled); log view renders the labels for `honeypot`/`submit_time` entries
- [x] run `docker compose run --rm test` — must pass before Task 7

### Task 7: Internationalization

**Files:**
- Modify: `antispam-wizard/languages/antispam-wizard.pot`
- Modify: all nine `antispam-wizard/languages/antispam-wizard-*.po` and recompile `*.mo`
- Create: `tests/I18nTest.php`

- [x] regenerate/extend the POT with every new string (check `details` strings, admin card titles/descriptions/hints, fallback form label), keeping the file's existing alphabetical-by-msgid layout and source-reference comments
- [x] merge into all nine `.po` files (`msgmerge`) and translate every new `msgstr` (es_ES, de_DE, fr_FR, pt_BR, it_IT, ja, ru_RU, nl_NL, tr_TR)
- [x] compile each `.po` to `.mo` with `msgfmt --check`
- [x] write `tests/I18nTest.php`: every new msgid exists in the POT; every `.po` contains all new msgids with a non-empty `msgstr`; every `.mo` file exists and is non-empty
- [x] run `docker compose run --rm test` — must pass before Task 8

### Task 8: Runtime smoke coverage

**Files:**
- Modify: `tests/smoke/detect-check.php`

- [x] pin `honeypot_enabled`/`submit_time_enabled` explicitly in the existing settings block so the current links spam/ham cases stay deterministic
- [x] add cases driven through the real `pre_comment_approved` filter, resetting `$_POST` between them: honeypot filled → `'spam'`; honeypot empty → not spam; fresh token with a high `submit_time_min_seconds` → `'spam'`; token minted well in the past → not spam; missing/tampered token → not spam
- [x] assert `ASW_Comment_Form::render_fields()` output contains both field names and that `public/css/comment-form.css` exists in the deployed plugin
- [x] update the file docblock to describe the new assertions
- [x] run `bash dev/smoke.sh` — must pass before Task 9
- [x] run `docker compose run --rm test` — must pass before Task 9

### Task 9: Documentation and storage-invariance verification

**Files:**
- Modify: `CLAUDE.md`, `README.md`, `antispam-wizard/readme.txt`

- [x] CLAUDE.md: add `ASW_Comment_Form` to the key-classes list, extend the check-classes line to ten checks with the ordering note, describe the CSP-safe hiding trade-off and the fail-open rules, document the new `is_singular()`/`comments_open()` test stubs in the test section, and state that no new storage location is introduced (so `uninstall.php` and `ASW_Privacy` are unaffected)
- [x] README.md: add the two checks to the intro paragraph and note that no personal data is stored by them
- [x] readme.txt: add both checks to the feature list and to the `= 1.0.0 =` changelog block (version stays 1.0.0)
- [x] verify `git diff --exit-code -- antispam-wizard/uninstall.php antispam-wizard/includes/class-privacy.php` reports no changes
- [x] run `docker compose run --rm test` — must pass before Task 10

### Task 10: Verify acceptance criteria

- [x] run the full suite: `docker compose run --rm test`
- [x] run `bash dev/smoke.sh` on the default image
- [x] run `msgfmt --check -o /dev/null` over all nine `.po` files and confirm no errors
- [x] confirm every acceptance-criteria case from the ticket has a corresponding passing test (honeypot filled/empty/missing, too-fast/normal/missing/invalid/tampered timestamp, both checks disabled, non-comment types, ordering + short-circuit)
- [x] confirm the two new checks appear in the admin UI, spam log labels, and statistics label maps

## Post-Completion

- Manually confirm in the local dev environment (`bash dev/reset.sh`, http://localhost:8080) that the honeypot field is invisible on the front-end comment form in a default theme and that a normal human comment still posts.
