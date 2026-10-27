# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

The long-form reasoning behind each subsystem lives in `docs/*.md` and is referenced from the
relevant section below. Those files are the record of *why* a rule exists and which test pins it;
read the one for the area you are touching before changing behaviour, and update it alongside
`CLAUDE.md` when a rule changes. `CLAUDE.md` itself must stay under 150 000 characters.

## Commands

### Run tests
```bash
docker compose run --rm test
```
Uses PHPUnit 9.6 via Docker Compose. `tests/bootstrap.php` provides WordPress function stubs — no
real WordPress instance needed. Many stubs are switchable through `$GLOBALS` (e.g.
`wp_is_singular`, `wp_using_ext_object_cache`, `wp_update_option_fails`, `wp_transient_delete_fails`,
`wp_transient_expired`, `wp_on_set_transient`, `wp_comment_status_change_fails`, `wp_delete_comment_meta_fails`, `wp_update_comment_meta_fails`, `wp_dbdelta_applies`,
`antispamwizard_zlib_unavailable`, `antispamwizard_gzcompress_fails`, `antispamwizard_max_cached_unit_bytes`,
`antispamwizard_max_inflated_unit_bytes`, `wp_current_commenter`, `wp_current_commenter_throws`,
`wp_current_user_id`, `wp_doing_ajax`, `wp_doing_cron`) and through injectable `$wpdb` results (`insert_result`,
`delete_result`, `query_result`, `get_results_result`, `on_get_var`, `on_get_results`,
`seed_table_rows()`), so DB failures, cache evictions, refused writes and expired transients can be
driven without a database. `wp_spam_comment()`/`wp_unspam_comment()` really fire
`transition_comment_status` (so every test that spams a comment also runs `ANTISPAMWIZARD_Moderation`) and,
with `wp_untrash_comment()`, follow core's `_wp_trash_meta_status` record/restore (fallback `'0'`), and
`wp_die()` **throws** `ANTISPAMWIZARD_Test_WP_Die_Exception`. A `WP_Comment_Query` stub follows core's
`get_comments()` order of operations (`pre_get_comments` → cache key from `query_var_defaults` →
`comments_clauses` → `the_comments` → `get_comment()`, core's child-ID cache, threaded re-fetch by ID), records keys and
final WHEREs in `$GLOBALS['wp_comment_query_cache_keys']` / `['wp_comment_query_log']`, keeps
`sql_clauses` **protected** as core does (tests use `antispamwizard_test_get_sql_clauses()` /
`antispamwizard_test_set_approved_clause()`), and its
matcher throws on an unknown SQL fragment; `get_comment()` runs the `get_comment` filter.
`wp_get_unapproved_comment_author_email()` follows core (moderation-hash pair in `$_GET`, else
the commenter cookie), so a test that sets `$_GET`/`$_POST` must reset them in `tearDown()`.
`tests/BootstrapStubsTest.php` pins the stubs
themselves. The full catalogue of stubs, switches and the test each one exists for is in
**`docs/test-stubs.md`** — read it before adding a stub or a switch.
`MultiLangCheckTest::test_long_single_word_is_classified_in_linear_time()` is the suite's only
wall-clock assertion (one 196 608-character word under 1.0 s, ~1000x headroom); if it goes red the
algorithm regressed, do not raise the bound.

### Local development
```bash
docker compose up -d wordpress   # Start WordPress + MySQL on localhost:8080
bash dev/setup.sh                # Initial WP setup (admin/admin)
bash dev/reset.sh                # Reset to fresh state (1 post, 1 comment, plugin reactivated)
docker compose down              # Stop
docker compose down -v           # Stop and wipe data (required after image version changes)
```

> **Note:** The WordPress image is pinned to `6.4-php8.1-apache`. If you previously ran
> with a different image (e.g. `wordpress:latest`), you must run `docker compose down -v`
> once to destroy existing volumes — the WordPress entrypoint does not downgrade core files
> in a populated volume.

The plugin is mounted at `/var/www/html/wp-content/plugins/antispam-wizard` inside the container.

### Runtime smoke test
```bash
bash dev/smoke.sh                                   # WP 7.1 (default)
WORDPRESS_IMAGE=6.4-php8.1-apache bash dev/smoke.sh # WP 6.4 floor (control)
ANTISPAMWIZARD_SMOKE_SKIP_CF7=1 bash dev/smoke.sh   # force the CF7 inert path
```
Boots a real, throwaway WordPress, activates the plugin, and asserts it **loads** (no
plugin-attributable PHP problems in `debug.log`; all 6 admin pages render), **detects**
(spam/ham verdicts through `pre_comment_approved` and, when Contact Form 7 is present, through
`wpcf7_spam` via `tests/smoke/cf7-check.php`), **shadows** (`tests/smoke/shadow-check.php`: a
flagged comment renders as held for its author only, through both `comments_template()` and
`do_blocks()`, with the approved comment asserted first so an empty list cannot pass, then again for a cookie-less
author landing on the `unapproved` + `moderation-hash` pair the plugin's `comment_post_redirect`
callback adds, with an invalid-hash and an expired-window negative) and carries the current **schema** (every
`ANTISPAMWIZARD_Sample_Repository::VERSIONED_COLUMNS` / `VERSIONED_INDEXES` entry present on the live table,
`schema_is_current()` true, `antispamwizard_samples_db_version` stamped) — first as activation left it, then
again after seeding a sample row, rolling the live table back to a pre-release layout (both subject
columns and both indexes dropped, version option `'0'`) and running `ANTISPAMWIZARD::maybe_upgrade_schema()`,
which must restore the layout **and drop the seeded row**. The SCHEMA assertion lives here because
the `$wpdb` stub cannot stand in for real `dbDelta()` + MySQL `SHOW COLUMNS`/`SHOW INDEX`, and
`register_activation_hook` never runs on an update. CF7 is installed via `wp plugin install`; a
failed install prints a SKIP line and continues (the integration must degrade to inert).
Each PHP context the harness controls forces `ini_set('log_errors','1')` and an explicit
`error_log` path, because `wp eval` does not honour `WP_DEBUG_LOG` the way the web SAPI does. A
core-version gate (`wp core version` must match the requested image's `major.minor`) fails fast on
a stale core tree. Fully isolated: per-run, per-image compose project
(`antispamwizard-smoke-<sanitized-image>-<pid>`) with ephemeral volumes and a teardown trap, so the
persistent dev env (`antispam-wizard_*`) is never touched. Exercised in CI
(`.github/workflows/ci.yml`) across a `{6.4, 7.0, 7.1}` matrix.

### Plugin Check
```bash
bash dev/plugin-check.sh                                   # WP 7.1 (default)
WORDPRESS_IMAGE=6.4-php8.1-apache bash dev/plugin-check.sh # WP 6.4 control
```
Boots a throwaway WordPress, installs the official `plugin-check` plugin (a failed install is a
**hard** failure, never a SKIP) and runs `wp plugin check antispam-wizard --categories=plugin_repo
--format=csv` against a **tracked-files-only export** (`git ls-files` into a temp dir, bind-mounted
read-only), so untracked junk can never produce a finding. WARNING rows are informational; the
script exits non-zero only on `type == ERROR`. Because that verdict is a row count, the harness
positively asserts the check completed: no `Error:`/`Fatal error`/`Allowed memory size` in the
output, a `Checks complete` notice or the CSV header present, and the check's exit status **0
unconditionally** (Plugin Check 2.1.0 exits 0 on every normal run, so any non-zero status means it
died and its truncated rows cannot be trusted). The memory limit is raised by invoking
`php -d memory_limit=... /usr/local/bin/wp` directly — `WP_CLI_PHP_ARGS` is a silent no-op on the
bare phar. Isolation mirrors `dev/smoke.sh` (`antispamwizard-pc-<image>-<pid>`, no published ports, `down -v`
before boot, trap teardown, same core-version gate). `tests/PluginCheckHarnessTest.php` pins these
invariants statically. Exercised in CI on the default image.

### Website
The marketing site antispamwizard.com lives in the private sibling repository
`HawkeyePierce89/antispam-wizard-site`, which bind-mounts this plugin from `../antispam-wizard/antispam-wizard`
and runs its own tests, build/verify and deployment. Its content states only facts present in
`antispam-wizard/readme.txt`, so a readme change that adds or removes a user-facing claim needs a
matching change there; its `verify.sh` posts real flagged comments through `wp-comments-post.php`
as a live shadow-display check (`docs/shadow-display.md`).

### Model-loading benchmark
```bash
docker compose up -d wordpress && bash dev/reset.sh
docker compose exec -T wordpress wp --allow-root eval-file /antispamwizard-dev/bench.php
```
`dev/bench.php` (`dev/` is mounted read-only at `/antispamwizard-dev` on the dev stack) times the cold and
warm `build_checks()` and evaluation paths and lists every cached unit's stored and object-cache
sizes against the pruning bound; `PluginMetadataTest` pins that it weighs the envelope
`ANTISPAMWIZARD_Model_Store` actually writes. The figures in `docs/data-storage.md` were measured with it on
PHP 8.3; on the dev stack's default `6.4-php8.1-apache` image `memory_reset_peak_usage()` is missing,
so only the cold memory figure is meaningful there (`WORDPRESS_IMAGE=7.1-php8.3-apache` for all of them).

## Architecture

**Spam detection plugin for WordPress comments** using a strategy pattern.

### Flow
1. `pre_comment_approved` filter → `ANTISPAMWIZARD_Detector` orchestrates checks; CF7 path `wpcf7_spam` (`wpcf7_form_elements` @100 + `wpcf7_enqueue_styles`) → `ANTISPAMWIZARD_CF7_Integration::collect_submission()` → `ANTISPAMWIZARD_Detector::evaluate()` → logged as `source=form` + `email_hash`
2. Each check class implements `ANTISPAMWIZARD_Check` interface, returns `ANTISPAMWIZARD_Check_Result`
3. If any check returns spam, comment is marked as spam; reason stored in comment meta `_antispamwizard_reason`
4. Training path: `transition_comment_status` → `ANTISPAMWIZARD_Moderation::on_transition()` → `ANTISPAMWIZARD_Spam_Log::claim_training()` (the once-per-comment-and-category lock) → `ANTISPAMWIZARD_Sample_Repository::insert()`, plus a `delete_by_hash()` of the opposite category. The front-end "Mark as spam" link (`comment_text` @100 → `admin_post[_nopriv]_antispamwizard_mark_spam`) only calls `wp_spam_comment()`; it feeds this same single training point rather than training itself
5. Shadow display: front-end `WP_Comment_Query` (`pre_get_comments` → `comments_clauses` → `the_comments`) → `ANTISPAMWIZARD_Shadow_Display` widens the visitor's own listing with their plugin-flagged `spam` comments → a lazy `get_comment` filter presents them as `'0'` (awaiting moderation) for that request only

### Key classes
- `ANTISPAMWIZARD` — singleton bootstrap, hook registration (entry point: `antispam-wizard/antispam-wizard.php`). `activate()` runs `dbDelta()` and `maybe_upgrade_schema()` (on `admin_init`, gated on `manage_options`) re-runs it whenever `antispamwizard_samples_db_version` differs — both stamp the version **only after `schema_is_current()` confirms the DDL landed** and after `delete_unattributed()` succeeded
- `ANTISPAMWIZARD_Detector` — orchestrates all 10 checks, hooks into `pre_comment_approved`; fail-open design (exceptions in individual checks are logged and skipped); respects upstream `spam`/`trash`/`WP_Error` status; short-circuits after first spam match; skips all checks when global `enabled` setting is false. `evaluate()` sanitizes `comment_content` through `sanitize_utf8()` **before** the `MAX_COMMENT_LENGTH` (65 536) truncation
- `ANTISPAMWIZARD_Comment_Form` — renders bot-check fields into the comment form, handles token HMAC mint/verify, and enqueues CSS. Employs a CSP-safe hiding trade-off (off-screen positioning via external stylesheet and inline fallback, leaving fallback label readable if CSS drops) and follows fail-open rules (missing/malformed/stale data passes).
- `ANTISPAMWIZARD_Settings` — manages config in `wp_options['antispamwizard_settings']` with defaults; includes global `enabled` toggle (default: true) and `cf7_enabled` (default: true, rendered/saved only when `ANTISPAMWIZARD_CF7_Integration::is_available()`)
- `ANTISPAMWIZARD_Spam_Log` — FIFO log (max 100 entries) with file locking; invalidates the `antispamwizard_spam_log` option cache on every read and write path. `claim_training( $comment_id, $category )` is the atomic once-per-comment-and-category training claim returning `'ok'` / `'flipped'` / `'already_trained'` / `'not_found'` / `'failed'` (a refused write — deliberately **not** collapsed into `'not_found'`, which would train unlocked). `restore_training()` is the unconditional rollback counterpart; it and `unmark_trained()` return `bool` so a refused rollback can be logged. Detail: **`docs/samples-and-privacy.md`**
- `ANTISPAMWIZARD_Stats` — per-check blocked counts with file locking; invalidates object cache before reads for coherency
- `ANTISPAMWIZARD_Script_Detector` — detects dominant Unicode script group (10 groups: latin, cyrillic, cjk, arabic, devanagari, greek, hebrew, thai, bengali, indic) using PCRE Unicode properties, consuming each group's matches from the subject rather than summing per-pattern counts (see Conventions); routes comments to per-script classifiers
- `ANTISPAMWIZARD_Tokenizer` — word-based tokenization for alphabetic scripts and character bigrams for CJK/Thai via `tokenize_for_script()`, 10 000-token cap. Both paths first fold every phone-number-like digit sequence (`PHONE_PATTERN`: 9–15 digits, optional `+`, pairs separated by at most three of space/dot/dash/parentheses) into the marker `PHONE_TOKEN` (`antispamwizardphonenumber`) via `normalize_phone_numbers()` — the strongest single feature of classified-ad spam. Accepted boundary: digit groups joined by spaces alone (`2019 2020 2021`) fold too; a comma or a word keeps them apart (`TokenizerTest` pins both sides)
- `ANTISPAMWIZARD_Cosine_Similarity` — computes cosine similarity between token arrays using TF vectors
- `ANTISPAMWIZARD_Naive_Bayes` — standalone Naive Bayes text classifier (tokenize, learn, classify); supports per-script mode. `classify()`'s Laplace denominator is floored at 1, so a category with no learned tokens (every sample tokenised to nothing, or a unit `ANTISPAMWIZARD_Model_Store::halve_dictionary()` pruned) classifies instead of raising a `DivisionByZeroError` that the fail-open catch would swallow for a whole day
- `ANTISPAMWIZARD_Sample_Repository` — user-added training samples in `{prefix}antispamwizard_samples`, deduplicated by SHA-256 of the content, revision counter `antispamwizard_samples_revision` for cache invalidation. Every row carries two subject keys: a required non-zero `comment_id` (`insert()` refuses `<= 0`) and a salted `email_hash` (`''` for an address-less comment, which matches nothing). `find_by_comment_ids()`/`delete_by_comment_ids()` and `find_by_email_hash()`/`delete_by_email_hash()` are the Privacy API paths; deleters return **`int|false`**, never collapsing a failed DELETE into 0. `find_all()` is `ORDER BY id ASC` (contractual — the budget spends from the newest end). `find_categories_by_hash()` answers "trained as which categories?". Schema versioning: `DB_VERSION`/`DB_VERSION_OPTION`, `VERSIONED_COLUMNS` + `VERSIONED_INDEXES` probed by `schema_is_current()` under `suppress_errors()` — bump the version and extend both lists on any DDL change (`SampleRepositoryTest::test_versioned_schema_probes_cover_every_key_the_ddl_adds` enforces it). `script_fingerprint()`/`table_fingerprint()` return `null` on a failed read (detected through `$wpdb->last_error`, never the return value) and `find_all()` sets a sticky `sample_read_failed()` flag. Detail: **`docs/samples-and-privacy.md`**
- `ANTISPAMWIZARD_Training_Data` — loads the seed corpus from `data/*.txt` and merges repository samples. `load_script_samples()` = `load_script_file_samples()` + `load_learned_script_samples()`; a test double standing in for the corpus must override `load_script_file_samples()`
- `ANTISPAMWIZARD_Model_Store` — **lazy per-writing-system model loader** handed to the Bayesian and Similarity checks; `build_checks()` reads no corpus and no transient, a submission loads the unit for the script it detects and nothing else. Eleven units (`antispamwizard_classifiers_<script>` + `antispamwizard_classifiers_combined`, keys fixed, fingerprint stored **inside** the value), each cached for a day in a **packed** representation, **compressed** (`array( 'zlib' => gzcompress(...) )`) only under an external object cache, bounded by `LEARNED_SIMILARITY_BUDGET` (128 KiB of learned spam, newest first) and by `storable_unit()`'s prune-until-it-fits admission control against `MAX_CACHED_UNIT_BYTES`. Never-cache rules: the fingerprint moved mid-load, or a sample read failed while the DB fingerprint succeeded. Erasure: `unit_keys()`/`delete_units()` purge all eleven, verified by read-back and backed by a blank-then-DELETE ladder plus a `_transient_antispamwizard_classifiers_%` stray-row sweep. The combined unit's similarity corpus is the legacy file + `unknown`-script rows only (an accepted verdict change for bodies that fall back to it). Every key must keep the `antispamwizard_classifiers_` prefix (`uninstall.php` relies on it). Full detail, every rule and the test pinning it: **`docs/model-store.md`**; layout, fingerprints and measurements: **`docs/data-storage.md`**
- `ANTISPAMWIZARD_Admin_Page` — top-level admin menu with 6 submenu pages (Main, General, Stop Words, Excluded Tokens, Spam Log, Statistics), AJAX handlers for stop-words, excluded tokens, and comment marking (`ajax_mark_comment()`, the Spam Log pill — the second writer of the sample table, serialised with `ANTISPAMWIZARD_Moderation` through `with_body_lock()`)
- `ANTISPAMWIZARD_CF7_Integration` — soft dependency on Contact Form 7; registered on `plugins_loaded` only when `WPCF7_ContactForm` and `WPCF7_Submission` exist (`is_available()`). Hooks `wpcf7_form_elements` @100 (bot-check fields), `wpcf7_enqueue_styles`, and `wpcf7_spam` (10, 2) to run the shared detector. The token is bound to the contact-form post ID so render/submit mismatch is impossible; `collect_submission()` builds `comment_content` from `text`/`textarea` fields joined with `"\n\n"`, `comment_author_email` from the first valid `email` field, `comment_author_IP` from `get_meta('remote_ip')`. All failure modes fail open; the whole `filter_spam()` body is `try/catch(\Throwable)`. Flagged submissions are logged with `source => 'form'` and a salted `email_hash`
- `ANTISPAMWIZARD_Privacy` — WordPress Privacy API exporter/eraser + suggested policy text. Reaches spam-log entries and `_antispamwizard_reason` meta by the subject's comments (`get_comments` with `status => 'any'`, 50 per page), form entries and samples by `email_hash` on page 1, and samples by `comment_id` on every page — the two keys are merged exactly-once by deciding per row whether the comment-keyed pass will claim it on a later page (`comment_ids_on_later_pages()`). `$page` is clamped to `>= 1`. A failed DELETE sets `items_retained`. `erase()` purges the model cache through `ANTISPAMWIZARD_Model_Store::delete_units()` on **page 1 of every request** (so the retry it asks for finds the cache purged too) and whenever a page removed a sample. The **eraser is registered at priority 5**, ahead of core's comment eraser, which blanks the email the lookup depends on. Detail: **`docs/samples-and-privacy.md`**
- `ANTISPAMWIZARD_Shadow_Display` — shows a comment the plugin flagged to its **own author** as awaiting moderation, to nobody else (setting `shadow_display_enabled`, default true, plus master `enabled`). Registered from `ANTISPAMWIZARD::init()` ungated; inert unless a front-end, non-count, non-`ids`, `status => 'approve'` query with `post_id > 0` already carries the visitor's own `include_unapproved` entry (user ID, or verbatim the `is_email()` email `wp_get_unapproved_comment_author_email()` resolves — moderation-hash pair, else commenter cookie), and never under `is_admin()`/AJAX/cron/REST/feeds. `comments_clauses` replaces the approved clause A (read from core's **protected** `sql_clauses` through reflection), only if it occurs **exactly once**, with `( A OR S )`, S prepared with `%i`: identity + `comment_approved = 'spam'` + `comment_type IN ( '', 'comment' )` + `EXISTS` `_antispamwizard_reason` (moderator-marked spam stays hidden). `query_var_defaults['antispamwizard_shadow']` puts the identity into the result-cache key; a `WeakMap` of approved queries is the only trust source (incoming `antispamwizard_shadow` is always unset). `the_comments` registers shadow IDs (and, for a threaded query, records the viewer, because `fill_descendants()` serves cached child IDs without re-running `the_comments`); a lazily added `get_comment` filter returns a **clone** with `'0'` for a registered ID or a threaded viewer's own flagged comment (block `core/comment-content` and `fill_descendants()` re-fetch by ID), never touching the row or the object cache. `transition_comment_status` deletes `_antispamwizard_reason` on `spam → approved|unapproved`, and the Spam Log pill deletes it through `forget_verdict()` after either verdict (an already-spam comment fires no transition; Ham on a trashed comment restores it `trash → unapproved`, never back to `spam`, via `ANTISPAMWIZARD_Admin_Page::move_out_of_spam()`), so moderator spam stays hidden. `comment_post_redirect` @100 adds that `unapproved` + `moderation-hash` pair (core adds it only for `'0'`) for a plugin-flagged spam comment posted logged out without cookie consent, unless the location already carries either key; core honours it for ten minutes. Flags written before this feature (which never dropped `_antispamwizard_reason`) are excluded by the cutoff option `antispamwizard_shadow_display_after` (`comment_ID >` it), recorded once by the detector's first flag through `record_flag()`; while unset nothing is widened. `is_shadowed( $id )` is the real-status answer — decide on it, never on the presented status (`append_moderation_link()` does). Every callback is `try/catch(\Throwable)` returning its input and logging `basename:line` only. Full detail, boundaries and tests: **`docs/shadow-display.md`**
- `ANTISPAMWIZARD_Moderation` — learns from moderation. Exactly **one** training point, `transition_comment_status` (not `is_admin()`-gated): spam on `→ spam`, ham on `spam → approved|unapproved` (the `unapproved` leg is how **Not Spam** on a plugin-flagged comment reaches the corpus). Only `comment_type` empty/`comment`. A spam verdict with no Spam Log entry creates a `manual` one first. The claim is `ANTISPAMWIZARD_Spam_Log::claim_training()`, verified against the table by `training_is_settled()` (`find_categories_by_hash()`: this category present, opposite absent) so a refused rollback never blocks a later retry; the flip deletes the opposite category **after** a successful insert; every failure past the claim rolls it back (`unmark_trained()` or `restore_training()`), and a `'failed'` claim touches nothing. Claim + write are serialised **per normalised body** through `with_body_lock()` (256 `flock` buckets, shared with the pill; never change a comment's status inside it — the hook re-enters and would deadlock). `transition_is_current()` re-reads the row inside the lock after `clean_comment_cache()` and stands down if superseded or deleted. `on_transition()` is `try/catch(\Throwable)` logging `basename(file):line`, **never `getMessage()`**. The front-end **"Mark as spam" link** (`comment_text` @100, `manage_options` only, skipped on `spam` and on `ANTISPAMWIZARD_Shadow_Display::is_shadowed()`, nonce bound to one comment, `admin_post[_nopriv]_antispamwizard_mark_spam`, capability → nonce → comment, 500 on a refused status change) only calls `wp_spam_comment()`. Full detail: **`docs/moderation.md`**

### Check classes (in `includes/checks/`)
Honeypot, Submit Time, Stop Words, Links, Emoji, Spacing, Multi-Language, Duplicate, Bayesian, Similarity — 10 checks total. The two bot checks (Honeypot and Submit Time) are ordered first to short-circuit earlier and save CPU; each check is independently toggleable with configurable thresholds.

### Conventions
- Class prefix: `ANTISPAMWIZARD_*` (WordPress style, no PHP namespaces)
- File naming: `class-{name}.php`, `interface-{name}.php`
- PHP 8.1+ with strict typing
- WordPress 6.4+ (tested up to 7.1)
- IP addresses are SHA-256 hashed with WordPress salt before use in plugin logic
- Check code runs on attacker-controlled text up to `ANTISPAMWIZARD_Detector::MAX_COMMENT_LENGTH` (65 536
  chars) that may be a **single unbroken word**. Per-character `mb_substr()` loops are O(n²) and a
  remote CPU-exhaustion vector; classify whole strings with `/u` character-class regexes instead.
  The only remaining per-character loop (the tokenizer's CJK/Thai n-gram path) is safe only because
  of its 10 000-token cap.
- From PCRE2 10.42 `\p{Latin}` etc. resolve against **Script_Extensions**, so one character can
  match several script properties. Code that buckets characters by script must *consume* each
  script's matches from the subject in list order (`ANTISPAMWIZARD_Multi_Lang_Check::is_multi_script_word()`
  and `ANTISPAMWIZARD_Script_Detector::detect()` both do) — testing each pattern against the whole subject
  double-counts and makes the verdict depend on the PCRE build.
- `ANTISPAMWIZARD_Multi_Lang_Check::SCRIPT_PATTERNS` keeps Han/Hiragana/Katakana/Hangul in **one** `CJK`
  bucket, and `ORTHOGRAPHIC_PAIRS` exempts exactly the two-bucket combinations `Latin+CJK` and
  `Latin+Thai` (space-less scripts routinely embed Latin without spaces). Do not split the bucket
  or widen the exemption; a third script always flags and Latin/Cyrillic homoglyphs are untouched.
- Fail-open diagnostics (`ANTISPAMWIZARD_Detector::run()`, `ANTISPAMWIZARD_CF7_Integration::filter_spam()`,
  `ANTISPAMWIZARD_Moderation::on_transition()`, `ANTISPAMWIZARD_Shadow_Display`'s six callbacks) log the class (or a fixed prefix) and `basename( $e->getFile() ) . ':' . $e->getLine()`
  **but never `$e->getMessage()`** or the full path — `debug.log` can be web-readable. Tests pin
  this on the catch block's source text by enumerating every use of `$e` (a name-only grep for
  `getMessage()` misses `. $e`). The `ANTISPAMWIZARD_Throwing_Check` fixture must stay a **named** class
  (an anonymous class name embeds a NUL byte that truncates what `error_log()` writes).
- A `/u` pattern returns `false`/`null` on malformed UTF-8, so bailing out on a PCRE failure is a
  one-byte evasion of the whole check. Sanitize first — always through
  `ANTISPAMWIZARD_Detector::sanitize_utf8()` (pins `mb_substitute_character` to `?` and restores it in a
  `finally`; never bare `mb_convert_encoding()`, never `wp_check_invalid_utf8( $s, true )`, which
  emits an iconv warning on PHP 8.1–8.5) — and classify the remainder. `evaluate()` sanitizes
  **before** it truncates; `ANTISPAMWIZARD_Multi_Lang_Check` and `ANTISPAMWIZARD_Duplicate_Check` keep their own guards
  because tests call them directly, and neither may fall back to `''`.
- Every Plugin Check deviation that is not fixed outright is carried as a `phpcs:ignore <exact
  sniff code>` with a one-line reason on the same line — bare suppressions are not allowed.
  A `phpcs:ignore` naming `WordPress.DB.PreparedSQL.NotPrepared` or
  `PluginCheck.Security.DirectDB.UnescapedDBParameter` must sit on the line **directly above a
  single-line statement** (`prepare()` into `$prepared`, then a bare one-line `$wpdb->query( $prepared );`,
  table name bound through `%i`); `PluginMetadataTest::test_not_prepared_suppressions_are_anchored_on_a_single_line_statement()`
  pins it.
- Every exclusive `flock` is released in a `finally`
  (`PluginMetadataTest::test_every_exclusive_lock_is_released_in_a_finally_block()` pins all sites).
- `wp eval-file` scripts (`dev/bench.php`, `tests/smoke/*.php`) carry no `declare( strict_types=1 )`:
  `eval()` cannot have a `declare()` as its first statement.
- Tests reset the singleton via reflection in setUp
- The full reasoning and the tests behind each of these rules: **`docs/conventions.md`**.

### Internationalization
All admin UI strings use `__()` / `esc_html__()` with text domain `antispam-wizard`. POT template at `antispam-wizard/languages/antispam-wizard.pot`. Nine translations shipped: es_ES, de_DE, fr_FR, pt_BR, it_IT, ja, ru_RU, nl_NL, tr_TR. To add a language: copy the .pot to a new .po, translate all msgstr entries, compile with `msgfmt`.

`tests/I18nTest.php` enforces the i18n contract, so adding, changing or removing any
translatable string is a multi-file change: regenerate the POT (its msgid set must equal the
set extracted from `antispam-wizard/**/*.php` **in both directions** — a new string without a
POT regen fails, and so does a stale POT msgid), `msgmerge` all nine `.po` files to zero
untranslated / zero `#, fuzzy` / zero obsolete `#~` entries, and recompile the `.mo`s.
Each `.po`'s msgid set must equal the POT's in both directions (an omitted entry has no
empty `msgstr` to flag, so nothing else catches a forgotten `msgmerge` on one file), and
each `.mo` is compared to its `.po` on **msgid and msgstr** — a msgstr-only edit left
uncompiled is invisible to a msgid-only check. Every
string containing a printf placeholder needs a `// translators:` comment naming each
placeholder on the line above the gettext call, multi-placeholder strings must be positional
(`%1$d`/`%2$d`), and each `msgstr` must carry exactly the placeholder set of its `msgid` — a
dropped or renumbered placeholder in a translation is an `ArgumentCountError` at runtime, not
a cosmetic defect. Prefer reusing an existing msgid over adding one.

### Data storage
Hybrid storage: seed corpus in git-tracked `data/*.txt` (read-only at runtime; `data/index.php` is the listing guard, no `.htaccess` by decision), user-added samples in `{prefix}antispamwizard_samples` (written by the Spam Log pill and by `ANTISPAMWIZARD_Moderation`, which is **not** admin-gated), settings / spam log / stats / the shadow-display cutoff in `wp_options`, duplicate counters and the eleven model units in transients, moderation and duplicate-check locks in `.antispamwizard-locks/` (bounded pools of 256 files). **`uninstall.php` removes all of it** — update it, `tests/UninstallTest.php` and `ANTISPAMWIZARD_Privacy` when adding a storage location.
- **Duplicate check**: without an external object cache, one transient per hashed IP (`antispamwizard_dup_<hash>`) holding a map of content hash → `{count, first_seen}` capped at `MAX_ENTRIES_PER_IP` (32), evicting the **lowest `count`** (ties: oldest `first_seen`), trimmed on every write, with the entry being written excluded from victim selection (otherwise a new text evicts itself and `increment_stored_count()` TypeErrors). Entries are validated and pruned on read (future-dated `first_seen` dropped). The lock bucket is derived from the **per-IP transient key**. Under an external object cache the path stays on per-IP+text `wp_cache_incr` counters with its documented fail-open eviction behaviour. Known boundaries (a never-repeated body can be flushed by 32 fillers; a count-2 wall holds a body at count 1 for one filler per repeat) are pinned as tests.
- **Classifier cache**: one transient per unit, `antispamwizard_classifiers_<script>` / `antispamwizard_classifiers_combined`, packed, compressed only under an external object cache, fingerprint inside the value. A per-script fingerprint is the two corpus files' `filemtime:filesize` + the excluded-tokens hash + `script_fingerprint()` (per-category `COUNT`/`MAX(id)`); the combined one is all 22 files + excluded tokens + `dbrev:` + `dbtable:` (the table component is what survives a refused `bump_revision()`). A failed fingerprint read falls back to `dbrev:` **and still caches**; a failed sample read with a successful fingerprint does **not** cache.
- **Corpus quality floor**: all ten per-script files are built from a genre matrix (classified ads, services, traffic/SEO, earn-from-home/crypto, loans/casino/phishing, pharmacy/beauty/dating, flattery-plus-own-site, documents/magic/courses/tours …) in each script's secondary languages, ≥40% of spam lines and ≥10% of ham lines under 50 chars, most lines without a phone number, praise words kept out of the spam flattery block and the site's own vocabulary covered in ham. Ham/spam token counts stay within the 0.85–1.20 band (`CorpusQualityTest::test_corpus_token_counts_stay_balanced_between_categories()`, cyrillic/latin exempt). `tests/CorpusQualityTest.php` requires recall ≥ 0.90 and FPR ≤ 0.04 on the held-out fixtures in `tests/fixtures/corpus/`, pins per-script canaries through the real check, and asserts every cached unit (shipped corpus, a 1 300-sample learning history, and the full-dictionary worst case) stays under the `MAX_CACHED_UNIT_BYTES - CACHE_ITEM_OVERHEAD_BYTES` pruning bound. Run `docker compose run --rm test php dev/corpus-eval.php [script …] [-v]` after any corpus edit; `dev/bench.php` re-measures timings and sizes on the dev stack. `min_message_length` defaults to 10. A form-specific corpus is explicit future work.
- **Measured** (shipped corpus, see `docs/data-storage.md` for the full table): warm path ~2.7–3 ms / 4.7 MB per submission; one-script cold path 31–48 ms for most scripts, up to ~206 ms for Thai; `unknown`-script cold path ~560 ms (builds the combined unit); cjk is the largest unit at 73% of the bound, so a cjk site's learned dictionary is pruned first.
- **Personal data**: spam-log fragment + IP hash (by `comment_id`), `_antispamwizard_reason` meta, form entries (by `email_hash`) and training samples (full comment text, by both keys) are all export- and erase-covered through `ANTISPAMWIZARD_Privacy`; the bot checks persist nothing.

Everything above in full — the eviction policy's rationale, every fingerprint input, the timing and
size tables, the two corpus-rebuild lessons, the held-out numbers — is in **`docs/data-storage.md`**.
