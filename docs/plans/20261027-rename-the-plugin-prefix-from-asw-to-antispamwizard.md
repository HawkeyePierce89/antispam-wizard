# Rename the plugin prefix from `asw` to `antispamwizard`

## Overview

The WordPress.org review (`R antispam-wizard/hawkeyepierce89/26Oct26/T4 27Oct26/4.3`) rejects the
three-letter prefix `asw`. Every plugin-owned identifier is renamed:

- `antispamwizard` for lowercase keys, handles, CSS and the JS object.
- `ANTISPAMWIZARD` for classes and constants.

A scripted pass makes the change, and a residual grep plus a read of the prose confirms it.
Human-readable labels use the plugin's real name, AntiSpam Wizard. There is no installed base, so
there is no migration, no alias, no shim and no `DB_VERSION` bump.

## Context

- Scope: every tracked text file, with three exceptions:
  - `docs/plans/`: historical records, left as they are by decision.
  - `antispam-wizard/data/`: the seed corpus. It is text, not identifiers, and must not change.
  - binaries (`*.mo`, `*.png`).
- About 90 files are affected:
  - `antispam-wizard/**`: PHP, `admin/js/admin.js`, `admin/css/admin.css`,
    `public/css/comment-form.css`, views, `uninstall.php`.
  - `tests/**`, including `tests/bootstrap.php` and `tests/smoke/*.php`.
  - `dev/bench.php`, `dev/smoke.sh`, `dev/plugin-check.sh`.
  - `docker-compose.yml` (the `/asw-dev` mount), `composer.json` (the vendor
    `asw/antispam-wizard-tests`), `.gitignore` (`.asw-pc-export.*`).
  - `CLAUDE.md`, `README.md`, `docs/*.md`.
- Facts checked during exploration:
  - Outside the excluded paths, every occurrence of the token is preceded by a non-alphanumeric
    character or by `_`. No mixed-case `Asw` exists. A case-sensitive replace of
    `ASW`→`ANTISPAMWIZARD` and `asw`→`antispamwizard` is therefore exact.
  - Underscore-joined forms are included: `_asw_reason`, `wp_asw_samples`,
    `_transient_asw_classifiers_`, `wp_ajax_asw_*`, `admin_post_asw_mark_spam`. A plain `\basw`
    grep misses them, so the residual check uses `(?<![A-Za-z0-9])asw`.
  - Outside `docs/plans/` and `antispam-wizard/data/`, the token is followed by a letter in exactly
    two places. Both are renamed:
    - `aswphonenumber`, the tokenizer's `PHONE_TOKEN`.
    - `aswshadow`, the random-token prefix in `tests/smoke/shadow-check.php`.
  - `antispam-wizard/data/spam-samples-thai.txt` has 8 spam lines with made-up Line IDs
    (`aswshop31`, `aswshop55`, `aswshop29`, …). They are preceded by a space, so a "glued to a
    preceding character" check cannot see them. They are corpus text and stay as they are.
  - The one tracked file whose name carries the token is `tests/ASWTest.php`, which declares
    `class ASWTest`.
    - It becomes `tests/AntiSpamWizardTest.php` with `class AntiSpamWizardTest`, spelled like the
      plugin name in the Plugin Name header and readme.txt.
    - The two other references follow: `docs/shadow-display.md` and a comment in
      `tests/ModelStoreTest.php`.
    - Plugin file names are unchanged.
  - Bare `ASW` appears in a few places:
    - as the bootstrap class (`ASW::`, `'ASW'` callbacks, docblocks, "the ASW singleton" comments);
    - as the fixed `error_log( 'ASW: …' )` diagnostic prefix, which ShadowDisplayTest,
      MarkCommentTest and `docs/conventions.md` / `docs/shadow-display.md` pin verbatim;
    - in three human-readable labels: the smoke and Plugin Check `--title` values, and the CF7 form
      title and user agent in `tests/smoke/cf7-check.php`.

    The class references and the log prefix take the mechanical result (`ANTISPAMWIZARD`,
    `'ANTISPAMWIZARD: …'`), in lockstep with the tests that pin them. The three labels are fixed by
    hand to "AntiSpam Wizard …".
  - The phone marker `aswphonenumber` becomes `antispamwizardphonenumber`. The CJK/Thai path splits
    it into character bigrams.
    - Measured in a throwaway worktree with only this constant changed: CorpusQualityTest,
      ModelStoreTest, SimilarityCheckTest, BayesianCheckTest and ScriptRoutingIntegrationTest all
      pass.
    - The only failure is `TokenizerTest::test_phone_token_is_one_token_of_the_ngram_path`, which
      hard-codes the marker's first bigram `'as'`.
  - No `.pot`/`.po` msgid contains the token, and neither does `antispam-wizard/readme.txt` or
    `.github/workflows/ci.yml`. Translations, the readme and CI stay untouched.
  - Length limits are fine:
    - The longest new key, `_transient_timeout_antispamwizard_dup_<64 hex>`, is 102 characters,
      under the 191-character option limit.
    - `wp_antispamwizard_samples` is under MySQL's 64-character identifier limit.
    - The compose project `antispamwizard-smoke-<image>-<pid>` is a valid project name.
  - CLAUDE.md is 33 020 characters with 94 occurrences. It grows by about 1 KB and stays far below
    150 000.
- Related patterns:
  - CLAUDE.md conventions: WordPress-style class prefix, no namespaces.
  - `phpcs:ignore` lines keep their sniff code and reason; only identifiers inside them change.
- Dependencies: none.

## Development Approach

- **Testing approach**: Regular. The rename is mechanical, and the existing suite already pins names
  statically: UninstallTest, PluginCheckHarnessTest, PluginMetadataTest, I18nTest,
  BootstrapStubsTest, StopWordsTabTest (localized object), and ShadowDisplayTest (log prefix). One
  new guard test stops the old prefix from coming back.
- Make the rename in a single atomic pass. A partial rename (code without tests) cannot be green, so
  code, tests and dev scripts change together in Task 1.
- The rename script is a throwaway tool and is not committed. Its exact patterns and exclusions go
  in the commit message so the diff can be reviewed against them.
- Assertions may not be loosened to make a test pass. Each one keeps its strength under the new
  name.
- Every human-readable label and every prose mention of the plugin uses the spelling AntiSpam Wizard
  (capital S).
- Commit messages carry no attribution footers.
- **CRITICAL: every task MUST include new/updated tests**
- **CRITICAL: all tests must pass before starting next task**

## Implementation Steps

### Task 1: Mechanical rename of code, tests, dev scripts and config

**Files:**
- Modify: every tracked text file that contains `asw`/`ASW`, except:
  - `docs/plans/`, `antispam-wizard/data/`, `*.mo`, `*.png`;
  - `CLAUDE.md`, `README.md` and `docs/*.md`, which Task 2 handles.

  In practice: `antispam-wizard/**` minus `data/`, `tests/**`, `dev/*`, `docker-compose.yml`,
  `composer.json`, `.gitignore`.
- Rename: `tests/ASWTest.php` → `tests/AntiSpamWizardTest.php`

- [x] Run three precondition checks before replacing anything. If any check gives a different
  result, stop and report:
  - `git grep -nE '[A-Za-z0-9](asw|ASW)' -- . ':!docs/plans' ':!antispam-wizard/data'` must be
    empty. This proves no token is glued to a preceding letter or digit.
  - `git grep -niE 'asw' -- . ':!docs/plans' ':!antispam-wizard/data' | grep -vE 'asw|ASW'` must be
    empty. This proves there is no mixed case.
  - `git grep -nohP '(?<![A-Za-z0-9])asw[a-z]+' -- . ':!docs/plans' ':!antispam-wizard/data' | sort -u`
    must output exactly two lines, `aswphonenumber` and `aswshadow`. Anything else stops the run.
    This lists every token glued to a following letter; `[a-z]+` rather than `[a-z]` shows the full
    token.
- [x] `git mv tests/ASWTest.php tests/AntiSpamWizardTest.php`
- [x] Over the file set above, apply these replacements in order, case-sensitive, with perl
  in-place:
  1. `ASWTest` → `AntiSpamWizardTest`
  2. `ASW` → `ANTISPAMWIZARD`
  3. `asw` → `antispamwizard`

  Build the file list with `git ls-files` and exclude `docs/plans/`, `antispam-wizard/data/`,
  `*.mo`, `*.png` and the Task 2 docs. Afterwards, `git status --short antispam-wizard/data` must be
  empty.
- [x] Update `tests/TokenizerTest.php::test_phone_token_is_one_token_of_the_ngram_path`:
  - Replace the hard-coded first bigram `'as'` with the marker's actual first bigram,
    `mb_substr( ANTISPAMWIZARD_Tokenizer::PHONE_TOKEN, 0, 2 )`.
  - Keep the `'ph'` assertion and the `'81'` negative, so the test still proves the digits were
    folded.
- [x] Hand-fix the human-readable labels that the pass turned into an all-caps identifier, using the
  spelling AntiSpam Wizard:
  - `dev/smoke.sh`: `--title="AntiSpam Wizard Smoke"`.
  - `dev/plugin-check.sh`: `--title="AntiSpam Wizard Plugin Check"`.
  - `tests/smoke/cf7-check.php`:
    - The form title `'AntiSpam Wizard Smoke CF7'` changes in all five occurrences. The check looks
      the form up by that title, so every occurrence must stay identical.
    - The user agent `'Mozilla/5.0 (compatible; AntiSpam Wizard Smoke)'` changes in both
      occurrences.
- [x] Spot-check that each `phpcs:ignore` line still carries its exact sniff code and reason.
  `git diff --stat` must not touch any `.po`/`.pot`/`.mo` file, `antispam-wizard/readme.txt` or
  anything under `antispam-wizard/data/`.
- [x] Add `PluginMetadataTest::test_no_plugin_file_carries_the_retired_three_letter_prefix()`:
  - It scans every file under `antispam-wizard/` with extension `php`, `js` or `css` for
    `/(?<![A-Za-z0-9])a[s]w/i` and asserts no match. The corpus `.txt` files fall outside these
    extensions. The bracketed form keeps the test file itself out of the residual grep.
  - It asserts that `admin/js/admin.js` reads `antispamwizardAdmin`, so the localized-object
    contract is pinned on both sides. StopWordsTabTest already pins the PHP side.
- [x] Run `docker compose run --rm test`. It must be green with the same test count as before plus
  the new test, and nothing skipped.

### Task 2: Rename and review the documentation

**Files:**
- Modify: `CLAUDE.md`, `README.md`, `docs/conventions.md`, `docs/data-storage.md`,
  `docs/model-store.md`, `docs/moderation.md`, `docs/samples-and-privacy.md`,
  `docs/shadow-display.md`, `docs/test-stubs.md`, plus any other `docs/*.md` outside `docs/plans/`
  that contains the token.

- [x] Apply the same three ordered replacements from Task 1 to these files. `ASWTest` →
  `AntiSpamWizardTest` goes first.
- [x] Read the diff of every prose file:
  - Anywhere the text names the plugin by its old abbreviation instead of an identifier, use
    "AntiSpam Wizard" in place of the all-caps class name.
  - If any prose explains the prefix itself, it now says `antispamwizard`/`ANTISPAMWIZARD`.
  - Identifiers inside backticks keep the mechanical result, including the
    `'ANTISPAMWIZARD: shadow display failed at '` log prefix quoted in `docs/conventions.md` and
    `docs/shadow-display.md`.
  - Text that describes the seed corpus content is not altered.
- [x] Cross-check the switch list in CLAUDE.md's Commands section and in `docs/test-stubs.md`. Every
  renamed `$GLOBALS` switch there must exist verbatim in `tests/bootstrap.php` or in the plugin code
  that reads it, for example `antispamwizard_zlib_unavailable`, `antispamwizard_gzcompress_fails`,
  `antispamwizard_max_cached_unit_bytes`, `antispamwizard_max_inflated_unit_bytes`.
- [x] Confirm that the storage names listed in CLAUDE.md's Data storage section match what
  `antispam-wizard/uninstall.php` deletes:
  - the table `{prefix}antispamwizard_samples`;
  - the options `antispamwizard_settings`, `_spam_log`, `_stats`, `_shadow_display_after`,
    `_samples_revision`, `_samples_db_version`;
  - the `_transient_antispamwizard_dup_%` and `_transient_antispamwizard_classifiers_%` sweeps;
  - the comment meta `_antispamwizard_reason`;
  - the lock directory `.antispamwizard-locks`.
- [x] `wc -c CLAUDE.md` must be under 150 000.
- [x] Run `docker compose run --rm test`. It must be green; PluginMetadataTest and
  PluginCheckHarnessTest read some docs and harness text.

### Task 3: Verify acceptance criteria

- [x] Run the residual greps:
  - `git grep -nP '(?<![A-Za-z0-9])(asw|ASW)' -- . ':!docs/plans' ':!antispam-wizard/data' ':!*.mo' ':!*.png'`
    must return nothing.
  - The ticket's own `git grep -inE '\basw' -- . ':!*.mo' ':!*.png' ':!docs/plans' ':!antispam-wizard/data'`
    must also return nothing.

  The final report lists the two allowed residuals:
  - `docs/plans/`: historical plans plus this plan file.
  - `antispam-wizard/data/`: seed corpus text, the 8 `aswshop…` Line IDs in
    `spam-samples-thai.txt`.
- [x] `git diff master --stat -- antispam-wizard/data` is empty.
- [x] UninstallTest passes and pins:
  - the renamed table and options;
  - the transients, including the `_transient_antispamwizard_classifiers_%` stray-row sweep and its
    timeout twin;
  - the `_antispamwizard_reason` meta;
  - the `.antispamwizard-locks` directory.
- [x] Cross-reference the DOM hooks with grep:
  - Every CSS class and `data-antispamwizard-*` attribute that `admin/js/admin.js` queries appears
    in the admin views or `class-admin-page.php`.
  - The hidden-field class that the comment form renders exists in `public/css/comment-form.css`
    and in its inline fallback.
- [x] Confirm that `grep -rn 'AntiSpam Wizard' dev/smoke.sh dev/plugin-check.sh tests/smoke/cf7-check.php`
  shows the three hand-fixed labels with the capital S.
- [x] Run the full suite with `docker compose run --rm test`. It must be green, with none skipped and
  none weakened.
- [x] There is no separate linter command in this repository; Plugin Check runs in CI. Coverage is
  unchanged by a rename, and the new guard test is the only added logic.

### Task 4: Update documentation

- [x] Task 2 has already updated CLAUDE.md and `docs/*.md`. Confirm two things:
  - CLAUDE.md names `tests/AntiSpamWizardTest.php` wherever it referred to the old file.
  - CLAUDE.md and `docs/model-store.md` both say that every model unit key keeps the
    `antispamwizard_classifiers_` prefix that `uninstall.php` relies on.
- [x] README.md already has its compose project examples (`antispamwizard-smoke-…`,
  `antispamwizard-pc-…`) and storage names updated. No `readme.txt` change: it contains no
  identifier.

## Post-Completion

- CI on the pull request runs `dev/smoke.sh` across {6.4, 7.0, 7.1} and `dev/plugin-check.sh`.
  These confirm that:
  - activation creates the renamed table;
  - the schema probes pass;
  - Plugin Check reports no ERROR.
- The private site repository `HawkeyePierce89/antispam-wizard-site` bind-mounts this plugin. Its
  `checks/detect.php` calls `ASW_Comment_Form::HONEYPOT_FIELD` and other plugin classes directly, so
  it will break against the renamed plugin and needs a follow-up change there. That change is out of
  scope here, and the site's own `asw-site` / `asw-waitlist` names are unrelated.
- Reply to the WordPress.org review once the release is rebuilt from the merged branch.
