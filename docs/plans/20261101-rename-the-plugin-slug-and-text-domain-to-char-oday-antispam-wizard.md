# Rename the plugin slug and text domain to `char-oday-antispam-wizard`

## Overview

The WordPress.org review (`R char-oday-antispam-wizard/hawkeyepierce89/26Oct26/T17 30Oct26/4.3`) has
moved the submission to the slug `char-oday-antispam-wizard`. One finding is left: the gettext text
domain must equal that slug. This plan changes the following in one mechanical pass:

- the display name becomes `Char O'Day – AntiSpam Wizard`;
- the text domain becomes `char-oday-antispam-wizard`;
- the plugin directory, the main file and the bundled language files take the slug;
- the admin menu slugs, the Privacy API exporter/eraser key and the CF7 spam-log `agent` value take
  the slug;
- every harness, test and document that names those paths is updated.

A new `PluginMetadataTest` guard stops the retired literal from coming back. There is no installed
base, so there is no migration, no alias and no `DB_VERSION` bump. The code prefix `antispamwizard` /
`ANTISPAMWIZARD` is not touched.

### Residue set (decided during exploration; the ticket's "exactly three" is too narrow)

After the rename, the retired literal `antispam-wizard` (not preceded by `char-oday-`) is allowed
only in these places:

1. The GitHub repository URL `https://github.com/HawkeyePierce89/antispam-wizard`.
2. The file name `class-antispam-wizard.php`, including its `includes/` path.
3. `docs/plans/`, which is excluded from the scan.
4. Names that come from the unrenamed repository, which the ticket itself requires or keeps:
   - the checkout directory `../antispam-wizard/` (in the Website section text the ticket asks for);
   - `~/git/antispam-wizard-release/` (in the ticket's zip recipe);
   - the site repository `HawkeyePierce89/antispam-wizard-site`;
   - the dev compose project `antispam-wizard_*`, which Docker derives from the checkout directory
     name.
5. The `composer.json` package name `antispamwizard/antispam-wizard-tests`. The ticket puts it out of
   scope.
6. The WordPress screen-ID prefix `antispam-wizard_page_`. WordPress builds it as `sanitize_title()`
   of the menu title "AntiSpam Wizard", and that title is the brand, which stays. Real submenu hook
   suffixes therefore become `antispam-wizard_page_char-oday-antispam-wizard-log` and so on.

Items 4–6 cannot be avoided without breaking an explicit requirement or an out-of-scope rule. The
guard test lists every item with a one-line reason.

## Context

- Files involved (about 60 tracked files plus 19 renames):
  - `antispam-wizard/` → `char-oday-antispam-wizard/` (`git mv`), including:
    - `antispam-wizard.php` → `char-oday-antispam-wizard.php`;
    - `languages/antispam-wizard.pot` and `antispam-wizard-<locale>.{po,mo}` →
      `char-oday-antispam-wizard*`;
    - `readme.txt`: the title and installation step 1;
    - `includes/class-antispam-wizard.php`: `load_plugin_textdomain()`;
    - `admin/class-admin-page.php`: `SUBMENU_SLUGS`;
    - `includes/class-privacy.php`: `PRIVACY_KEY` and the second literal at about line 566;
    - `includes/class-cf7-integration.php`: `agent`;
    - all admin views and checks: the gettext domain in 266 calls.
  - `tests/`:
    - `bootstrap.php`: `WP_PLUGIN_DIR`, `ANTISPAMWIZARD_PATH`/`_URL`/`_FILE` and the `require_once`
      list;
    - `AdminPageTest`, `AntiSpamWizardTest`, `CF7IntegrationTest`, `CF7PrivacyTest`, `DetectorTest`,
      `I18nTest`, `IntegrationTest`, `MarkCommentTest`, `ModerationLinkTest`, `ModerationTest`,
      `PluginCheckHarnessTest`, `PluginMetadataTest`, `PrivacyTest`, `SampleRepositoryTest`,
      `ScriptRoutingIntegrationTest`, `ShadowDisplayTest`, `SpamLogDisplayTest`, `StatsDisplayTest`,
      `StopWordsTabTest`, `TrainingDataTest`, `UninstallTest`;
    - `tests/smoke/detect-check.php` and `tests/smoke/shadow-check.php`.
  - `dev/`: `smoke.sh`, `plugin-check.sh`, `setup.sh`, `reset.sh`, `bench.php`.
  - Root config: `docker-compose.yml` (the plugin bind mount) and `composer.json` (classmap paths
    only).
  - Documentation: `CLAUDE.md`, `README.md`, `CONTRIBUTING.md`, `docs/conventions.md`,
    `docs/data-storage.md`, `docs/samples-and-privacy.md`, `docs/shadow-display.md`,
    `docs/test-stubs.md`.
- Facts checked during exploration:
  - The `.po`/`.pot` files contain no slug literal. Their `#:` references are relative to the plugin
    directory.
  - `I18nTest` finds catalogues by `glob( '*.po' )`. Only the directory and the POT name are
    hard-coded.
  - No translatable msgid changes, so no `msgmerge` is needed. The
    `Project-Id-Version: AntiSpam Wizard 1.0.0` headers stay as they are (the brand is unchanged), so
    the `.mo` files need no recompile and stay byte-identical renames.
  - `.github/` and `phpunit.xml` contain no slug literal. The test service mounts the repository root
    (`.:/app`), so it is unaffected.
  - The `composer install` run by the test service regenerates the classmap from the edited
    `composer.json` paths.
  - `AdminPageTest` pins real hook suffixes (`antispam-wizard_page_…`) and a regression row for the
    slug-derived prefix WordPress never generates. Both stay with the new slug after `_page_`.
  - `CLAUDE.md` has no release-zip recipe yet. A short one is added.
- Related patterns:
  - The completed plan
    `docs/plans/completed/20261027-rename-the-plugin-prefix-from-asw-to-antispamwizard.md`.
  - The guard `PluginMetadataTest::test_no_plugin_file_carries_the_retired_three_letter_prefix()`.
    The new guard reuses its path set.
- Dependencies: none.

## Development Approach

- **Testing approach**: Regular. Code, tests and dev scripts move in one pass so the suite can go
  green. The new guard test follows once the documents are clean.
- Use `git mv` for the directory, the main file and every language file, so history follows.
- Do the literal replacement with one scripted pass that protects the residue set. The script is a
  throwaway and is not committed. For example:

  ```bash
  git grep -lz 'antispam-wizard' -- ':!docs/plans' ':!*.mo' ':!char-oday-antispam-wizard/data' \
    | xargs -0 perl -pi -e 's{(?<!char-oday-)(?<!class-)(?<!HawkeyePierce89/)(?<!antispamwizard/)(?<!\.\./)(?<!git/)antispam-wizard(?!-site|-release|_page_|_\*|-tests)}{char-oday-antispam-wizard}g'
  ```

  After the pass, read every hit of `git grep -nP '(?<!char-oday-)antispam-wizard'` and every changed
  prose line by hand.
- The pass replaces the literal only: no line is added or removed in any PHP file. Every
  `phpcs:ignore` line and every `// translators:` comment stays where it is.
- No assertion is loosened. Each one keeps its strength under the new slug.
- "AntiSpam Wizard" stays the brand everywhere except the `Plugin Name` header and the readme title.
- Commit messages and the PR body carry no attribution footers.
- **CRITICAL: every task MUST include new/updated tests**
- **CRITICAL: all tests must pass before starting next task**

## Implementation Steps

### Task 1: Move the plugin to the new slug: files, code, tests, harnesses and config

**Files:**
- Rename: `antispam-wizard/` → `char-oday-antispam-wizard/`
- Rename: `char-oday-antispam-wizard/antispam-wizard.php` →
  `char-oday-antispam-wizard/char-oday-antispam-wizard.php`
- Rename: `char-oday-antispam-wizard/languages/antispam-wizard.pot` and the 18
  `antispam-wizard-<locale>.{po,mo}` files → the `char-oday-antispam-wizard` names
- Modify: the plugin PHP files listed in Context, plus `readme.txt`
- Modify: `tests/bootstrap.php`, the tests listed in Context, `tests/smoke/*.php`
- Modify: `dev/smoke.sh`, `dev/plugin-check.sh`, `dev/setup.sh`, `dev/reset.sh`, `dev/bench.php`,
  `docker-compose.yml`, `composer.json`

- [x] `git mv` the directory, then the main file, then each of the 19 language files
  (`char-oday-antispam-wizard.pot` and `char-oday-antispam-wizard-<locale>.po/.mo`).
- [x] Run the protected literal replacement over the code, test, dev and config files, which are
  everything outside `docs/`, `CLAUDE.md`, `README.md` and `CONTRIBUTING.md`. This covers:
  - the gettext domain in all 266 calls and `load_plugin_textdomain()` (its `phpcs:ignore` line stays
    untouched);
  - `SUBMENU_SLUGS`, `PRIVACY_KEY` and the second Privacy literal, and the CF7 `agent` value;
  - the `require`, `define` and `file_get_contents` paths in tests;
  - `$_GET['page']` fixtures;
  - the `docker-compose.yml` mount (both sides);
  - `composer.json` classmap paths;
  - `PLUGIN_SLUG` in `dev/plugin-check.sh`;
  - `PLUGIN_PATH_NEEDLE`, `wpcli plugin activate` and the messages in `dev/smoke.sh`;
  - `wp plugin activate` and `git checkout HEAD -- …/data/` in `dev/reset.sh`; `dev/setup.sh`;
  - the messages in `dev/bench.php` and `tests/smoke/*.php`.
- [x] Set the header by hand to `Plugin Name: Char O'Day – AntiSpam Wizard` (straight apostrophe, en
  dash). Check that `Text Domain: char-oday-antispam-wizard` came out of the pass.
- [x] Set the readme title to `=== Char O'Day – AntiSpam Wizard ===`. Installation step 1 should now
  name the `char-oday-antispam-wizard` folder. Leave all other readme prose unchanged.
- [x] Hand-check the residues the pass must keep:
  - `SOURCE_URL` and the readme's GitHub line;
  - `includes/class-antispam-wizard.php` and its `require_once` sites;
  - the `antispam-wizard_page_` hook-suffix prefixes in `AdminPageTest`/`StopWordsTabTest`;
  - the `antispam-wizard_*` dev-project comments in `dev/smoke.sh`, `dev/plugin-check.sh` and
    `PluginCheckHarnessTest`;
  - the `composer.json` package name.
- [x] Fix the `AdminPageTest` docblock that explains the prefix (`"AntiSpam Wizard" =>
  "antispam-wizard"`) so it still describes the menu-title derivation correctly. Update the
  "slug-derived prefix" regression row to
  `char-oday-antispam-wizard-main_page_char-oday-antispam-wizard-log`.
- [x] Update the existing tests that hard-code paths, slugs or the domain. Do not delete any of them:
  - `I18nTest`: the language dir and `char-oday-antispam-wizard.pot`;
  - `AntiSpamWizardTest`: the loaded text domain;
  - `CF7IntegrationTest`: `agent`;
  - `AdminPageTest`: menu and submenu slugs, URLs, and the header path
    `char-oday-antispam-wizard.php`;
  - `PluginMetadataTest`: `plugin_file`, `readme_file`, the `git ls-files` path and the `substr`
    prefix, the retired-prefix guard's scan dir and `char-oday-antispam-wizard/data/` exclusion.
- [x] Add a pin to `PluginCheckHarnessTest`: `dev/plugin-check.sh` declares
  `PLUGIN_SLUG="char-oday-antispam-wizard"` and mounts at
  `/var/www/html/wp-content/plugins/${PLUGIN_SLUG}`.
- [x] Run `docker compose run --rm test`. It must be green, including `I18nTest`,
  `PluginMetadataTest` (with the retired-prefix guard) and `PluginCheckHarnessTest`, before Task 2.

### Task 2: Rename the slug in the documentation

**Files:**
- Modify: `CLAUDE.md`, `README.md`, `CONTRIBUTING.md`, `docs/conventions.md`, `docs/data-storage.md`,
  `docs/samples-and-privacy.md`, `docs/shadow-display.md`, `docs/test-stubs.md`

- [x] Run the same protected replacement over these files. Then read every changed line by hand:
  prose may name the repository checkout rather than the plugin directory.
- [x] `CLAUDE.md` Commands:
  - the dev mount path `/var/www/html/wp-content/plugins/char-oday-antispam-wizard`;
  - `wp plugin check char-oday-antispam-wizard`;
  - the smoke text that says the plugin is activated as `char-oday-antispam-wizard`;
  - a new short "Release zip" subsection with
    `git archive --format=zip --prefix=char-oday-antispam-wizard/ -o ~/git/antispam-wizard-release/char-oday-antispam-wizard-1.0.0.zip master:char-oday-antispam-wizard`.
- [x] `CLAUDE.md` Website: the site repository's mount source and export path now point at
  `../antispam-wizard/char-oday-antispam-wizard` and `char-oday-antispam-wizard.php`, and this
  two-line change in `HawkeyePierce89/antispam-wizard-site` is pending. Also point the readme
  reference at `char-oday-antispam-wizard/readme.txt`.
- [x] `CLAUDE.md` Key classes: the entry point is
  `char-oday-antispam-wizard/char-oday-antispam-wizard.php`, with the bootstrap still in
  `includes/class-antispam-wizard.php`.
- [x] `CLAUDE.md` Conventions: the retired-prefix guard's excluded path becomes
  `char-oday-antispam-wizard/data/`.
- [x] `CLAUDE.md` Internationalization:
  - the text domain is `char-oday-antispam-wizard`;
  - the POT is `char-oday-antispam-wizard/languages/char-oday-antispam-wizard.pot`;
  - catalogues are `char-oday-antispam-wizard-<locale>.{po,mo}`, because WordPress resolves
    `{domain}-{locale}.mo`;
  - the extraction path is `char-oday-antispam-wizard/**/*.php`.
- [x] `README.md`: the translation dir, the mounted directory, `wp plugin check
  char-oday-antispam-wizard`, and `git ls-files char-oday-antispam-wizard`. `CONTRIBUTING.md`: the
  corpus path. `docs/*.md`: the directory paths, including `grep -rn "phpcs:ignore"
  char-oday-antispam-wizard` in `docs/conventions.md`.
- [x] Check that `CLAUDE.md` stays under 150 000 characters (`wc -c`).
- [x] Run `docker compose run --rm test`. It must stay green, and the retired-prefix guard must still
  pass over the edited docs.

### Task 3: Guard the slug in `PluginMetadataTest`

**Files:**
- Modify: `tests/PluginMetadataTest.php`

- [x] Extract the retired-prefix guard's path collection into a private helper, so both guards scan
  exactly the same tracked text set. The set is:
  - the plugin dir, `dev`, `tests`, `docs` and `.github`;
  - the root files;
  - excluding `char-oday-antispam-wizard/data/` and `docs/plans/`.

  The `> 100 files scanned` assertion stays.
- [x] Add `test_no_file_carries_the_retired_slug_outside_the_allowed_residues()`:
  - for each line and each path, strip the allowed residue patterns (the residue set in the Overview,
    one commented regex per entry);
  - fail on any remaining `(?<!char-oday-)antispam-wizard`;
  - report offences as `path:line: text`;
  - build the needle so that this test file does not trip itself.
- [x] Add `test_slug_agrees_across_header_readme_and_file_names()`. Against a
  `SLUG = 'char-oday-antispam-wizard'` constant, assert that:
  - the `Text Domain` header equals the slug;
  - the `Plugin Name` header and the readme title both equal `Char O'Day – AntiSpam Wizard`;
  - the main file is `<slug>/<slug>.php`;
  - `languages/<slug>.pot` exists;
  - every `.po`/`.mo` under `languages/` is named `<slug>-<locale>.{po,mo}`, there are nine of each,
    and no `antispam-wizard*` file remains.
- [x] In the same test, pin that the harnesses address the slug:
  - `docker-compose.yml` mounts `./<slug>:/var/www/html/wp-content/plugins/<slug>`;
  - `dev/smoke.sh` activates `<slug>` and sets `PLUGIN_PATH_NEEDLE="wp-content/plugins/<slug>"`;
  - `dev/setup.sh` and `dev/reset.sh` run `wp plugin activate <slug>`;
  - `load_plugin_textdomain( '<slug>'` appears in `includes/class-antispam-wizard.php`.
- [x] Confirm the new guard is not vacuous: temporarily reintroduce one old literal (for example in a
  doc), watch the test fail, then revert.
- [x] Run `docker compose run --rm test`. It must be green.

### Task 4: Verify acceptance criteria

- [x] Run `docker compose run --rm test`. It must be fully green, including `I18nTest`,
  `PluginMetadataTest` and `PluginCheckHarnessTest`.
- [x] Run `git grep -nP '(?<!char-oday-)antispam-wizard' -- ':!docs/plans'`. Every hit must belong to
  the residue set.
- [x] Run `git ls-files char-oday-antispam-wizard/languages`. It must list
  `char-oday-antispam-wizard.pot` and nine `char-oday-antispam-wizard-<locale>.po` + `.mo` pairs, and
  nothing named `antispam-wizard-*`. Run `git ls-files antispam-wizard`. It must be empty.
- [x] Run `git diff --stat -M master`. The language files, `data/*.txt` and every file whose content
  did not change must show as pure renames (100% similarity).
- [x] Run `php -l` on every changed PHP file inside the test container. There is no separate linter
  beyond the PHPUnit suite and Plugin Check, which the acceptance reviewer runs.
- [x] Confirm that no `phpcs:ignore` or `// translators:` line moved: the diff of every PHP file under
  the plugin dir changes only the literal on existing lines.

### Task 5: Update documentation

- [ ] Add one line to `CLAUDE.md` Conventions naming the new slug guard:
  `PluginMetadataTest::test_no_file_carries_the_retired_slug_outside_the_allowed_residues()` and its
  residue set. Add the same to `docs/conventions.md` with the reasoning, in particular why
  `antispam-wizard_page_` and the repository-derived names are allowed.
- [ ] `README.md`: only the path changes made in Task 2. No user-facing behaviour changes.

## Post-Completion

- The acceptance reviewer runs `bash dev/plugin-check.sh`, which must exit 0 with zero ERROR rows and
  no text-domain finding. They also run `bash dev/smoke.sh` on WP 7.1, with the plugin activated as
  `char-oday-antispam-wizard`. CI runs the smoke matrix {6.4, 7.0, 7.1} and Plugin Check on the PR.
- Existing dev stack: the bind-mount path changed, so the old `antispam-wizard/antispam-wizard.php`
  activation goes stale. Run `docker compose up -d wordpress && bash dev/reset.sh` once to reactivate
  under the new slug.
- Follow-up (pending): the private site repository `HawkeyePierce89/antispam-wizard-site` needs a
  two-line change. Its plugin mount source and its export path must point at
  `../antispam-wizard/char-oday-antispam-wizard` and `char-oday-antispam-wizard.php`. The old-prefix
  follow-up from PR #9 is still open there too.
- Update the `wporg-submission` memory: slug `char-oday-antispam-wizard`, display name, and the new
  zip recipe
  `git archive --format=zip --prefix=char-oday-antispam-wizard/ -o ~/git/antispam-wizard-release/char-oday-antispam-wizard-1.0.0.zip master:char-oday-antispam-wizard`.
- Rebuild the zip from merged master, move tag `v1.0.0` (a force-push needs the user), and reply to
  the review email with the new zip.
