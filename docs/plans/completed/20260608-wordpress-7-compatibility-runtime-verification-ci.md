# WordPress 7 Compatibility — Runtime Verification & CI Guard

## Overview

Substantiate the `Tested up to: 7.0` claim with real execution. Build one shared, isolated
Docker smoke harness that boots a real WordPress, activates the plugin, asserts it **loads**
clean (no plugin-attributable PHP errors, 6 admin pages render) and **detects** (spam/ham
verdicts through `pre_comment_approved`). Use it both as a one-time local WP 7 check and as a
GitHub Actions CI guard across a `{6.4, 7.0}` matrix. No new toolchain — pure Docker + bash +
WP-CLI + one PHP eval-file.

## Context

- Files involved:
  - Modify: `docker-compose.yml` (parameterize `wordpress` image)
  - Create: `dev/smoke.sh` (isolated loads+detects runner)
  - Create: `tests/smoke/detect-check.php` (verdict assertions via `wp eval-file`)
  - Create: `.github/workflows/ci.yml` (`unit` + `wp-smoke` matrix)
  - Modify: `CLAUDE.md` (document smoke command + `WORDPRESS_IMAGE`)
- Related patterns:
  - `dev/setup.sh` — readiness wait-loops (PHP `echo ok`, mysqli connect), in-container WP-CLI
    download/install, `wp core install` flags, `wp plugin activate antispam-wizard`. Reuse
    verbatim where possible.
  - `antispam-wizard/admin/class-admin-page.php` — `add_menu_page` on `admin_menu`; single
    `render_page()` callback switched on `$_GET['page']`; 6 slugs in
    `ASW_Admin_Page::SUBMENU_SLUGS` (main, general, stop-words, excluded-tokens, log, stats).
    Views include `submit_button()` → require `wp-admin/includes/template.php`.
  - `antispam-wizard/includes/class-detector.php` — `add_filter('pre_comment_approved', ..., 10, 2)`;
    gates on `ASW_Settings::get('enabled')` and `comment_type === 'comment'`.
  - `antispam-wizard/includes/checks/class-links-check.php` — spam when `http:// + https://`
    count `> links_max` (default 2). Deterministic, corpus-independent spam trigger.
- Dependencies: confirmed-available images `wordpress:6.4-php8.1-apache`,
  `wordpress:7.0-php8.3-apache`. GitHub-hosted Ubuntu runners (Docker + Compose v2 preinstalled).

## Development Approach

- **Testing approach**: Regular — for this infra work the smoke harness *is* the test; each
  task's verification is running the harness and observing PASS / exit 0. The existing 555 stub
  tests must remain green and unchanged.
- Complete each task fully before moving to the next.
- Default image in `docker-compose.yml` stays `6.4-php8.1-apache` so `dev/setup.sh`/`dev/reset.sh`/
  `docker compose up` are unaffected.
- Isolation is non-negotiable: smoke runs under compose project `asw-smoke` with ephemeral
  volumes and a `trap … down -v` so the user's persistent dev env is never read or destroyed.
- **CRITICAL: every task MUST include new/updated verification.**
- **CRITICAL: all verification must pass before starting the next task.**

## Implementation Steps

### Task 1: Parameterize the WordPress image

**Files:**
- Modify: `docker-compose.yml`

- [x] Change `wordpress` service `image:` to `wordpress:${WORDPRESS_IMAGE:-6.4-php8.1-apache}`
      (default preserves current behavior).
- [x] Leave `db`, `test`, ports, volumes, healthcheck untouched.
- [x] Verify default unchanged: `docker compose config` resolves `wordpress` image to
      `wordpress:6.4-php8.1-apache` with no `WORDPRESS_IMAGE` set;
      `WORDPRESS_IMAGE=7.0-php8.3-apache docker compose config` resolves to the 7.0 tag.

### Task 2: Detection check script

**Files:**
- Create: `tests/smoke/detect-check.php`

- [x] Script runs inside real WP via `wp eval-file`. Establish preconditions: force
      `ASW_Settings` `enabled = true`; pin settings for determinism — disable probabilistic
      `bayesian_enabled` and `similarity_enabled` for the run (in-memory/option override) so the
      ham verdict cannot flake on corpus state.
- [x] Spam case: build `$commentdata` with `comment_type = 'comment'` and content containing 3+
      `http(s)://` links; call `apply_filters('pre_comment_approved', 0, $commentdata)`; assert
      result === `'spam'`.
- [x] Ham case: build neutral, link-free, stop-word-free `comment` content; assert
      `apply_filters('pre_comment_approved', 0, $commentdata)` result is NOT `'spam'`.
- [x] On any failed assertion, print expected-vs-actual and `exit(1)`; on all pass print a clear
      OK line and `exit(0)`.
- [x] Verify standalone once a WP 7 stack is up (covered end-to-end in Task 3):
      `wp eval-file tests/smoke/detect-check.php` exits 0. (Content choices verified now against
      the real check classes via a temporary test mirroring this script's settings/logic — both
      spam and ham verdicts confirmed; PHP syntax linted; full runtime run is in Task 3.)

### Task 3: Shared smoke runner

**Files:**
- Create: `dev/smoke.sh`

- [x] `set -euo pipefail`; `WORDPRESS_IMAGE` defaults to `7.0-php8.3-apache`; all compose calls
      use `-p asw-smoke` with `WORDPRESS_IMAGE` exported.
- [x] Start the smoke `wordpress` service with `WORDPRESS_DEBUG=1` and `WORDPRESS_CONFIG_EXTRA`
      enabling `WP_DEBUG_LOG` (debug.log under `wp-content/`). Use an override env/inline so the
      base `docker-compose.yml` is not mutated. (Implemented via a `mktemp` inline override merged
      with `-f`; also drops published ports via `ports: !override []` for true isolation and mounts
      `tests/smoke` read-only.)
- [x] `trap 'docker compose -p asw-smoke down -v' EXIT` registered before bringing the stack up —
      guarantees teardown of ephemeral volumes on success or failure.
- [x] Boot `db` + `wordpress`; reuse `setup.sh` readiness wait-loops (PHP-ready, DB-ready) and
      in-container WP-CLI install.
- [x] `wp core install` (throwaway site) then `wp plugin activate antispam-wizard`; assert exit 0
      (activation didn't fatal).
- [x] LOADS assertion A (debug.log): read `wp-content/debug.log`; fail if any
      `Fatal`/`Deprecated`/`Warning`/`Notice` line references `wp-content/plugins/antispam-wizard`.
      Filter out core/other-plugin noise so only plugin-attributable problems fail.
- [x] LOADS assertion B (admin pages): via `wp eval`, `require_once` needed
      `wp-admin/includes/*.php` (`template.php` for `submit_button`, plus `plugin.php`/`screen.php`
      as required), set a `manage_options` admin user as current, fire `do_action('admin_menu')`,
      then for each of the 6 slugs set `$_GET['page']` and output-buffer the registered ASW render
      callback. Assert each runs without `Throwable` and emits non-empty markup. Fallback per the
      spec's risk note: if a callback proves un-runnable headlessly, assert page registered +
      callback callable and log reduced coverage. (Full render coverage achieved — all 6 pages
      rendered non-empty markup on both 6.4 and 7.0; fallback not needed.)
- [x] DETECTS assertion: `wp eval-file tests/smoke/detect-check.php`; propagate its exit code.
- [x] Print a readable PASS/FAIL summary (expected-vs-actual on failure); script exit code
      reflects overall result.
- [x] Verify: `WORDPRESS_IMAGE=7.0-php8.3-apache bash dev/smoke.sh` exits 0 with PASS;
      `WORDPRESS_IMAGE=6.4-php8.1-apache bash dev/smoke.sh` exits 0 (known-good control). Confirm
      no `asw-smoke` volumes remain and the user's default `antispam-wizard_*` volumes are
      untouched (`docker volume ls`). (Both legs exit 0 with PASS; no `asw-smoke_*` volumes remain
      after teardown.)

### Task 4: CI workflow

**Files:**
- Create: `.github/workflows/ci.yml`

- [x] Triggers: `push` and `pull_request`.
- [x] Job `unit`: checkout + `docker compose run --rm test` (existing stub suite; DRY with local).
- [x] Job `wp-smoke`: `strategy.fail-fast: false`, `matrix.wp: [6.4-php8.1-apache, 7.0-php8.3-apache]`;
      each leg runs `WORDPRESS_IMAGE=${{ matrix.wp }} bash dev/smoke.sh`. Both legs always report.
- [x] Verify YAML validity (lint/parse the file); CI green is confirmed in Post-Completion after
      push. (Parsed with Ruby YAML: 2 jobs `unit`/`wp-smoke`, matrix `[6.4-php8.1-apache,
      7.0-php8.3-apache]`, `fail-fast: false`.)

### Task 5: Documentation

**Files:**
- Modify: `CLAUDE.md`

- [x] Under Commands, document `bash dev/smoke.sh` (runtime smoke: loads + detects on a real WP)
      and the `WORDPRESS_IMAGE` override (default `7.0-php8.3-apache`; floor `6.4-php8.1-apache`);
      note isolation via the `asw-smoke` project.
- [x] Confirm no version-string change needed (`Tested up to: 7.0` already present; now
      substantiated). `uninstall.php` and `.gitignore` untouched.

### Task 6: Verify acceptance criteria

- [x] `WORDPRESS_IMAGE=7.0-php8.3-apache bash dev/smoke.sh` exits 0 with PASS output.
- [x] `WORDPRESS_IMAGE=6.4-php8.1-apache bash dev/smoke.sh` exits 0 (control).
- [x] `docker compose run --rm test` — existing 555 stub tests still pass unchanged.
- [x] `docker compose up -d wordpress` + `dev/setup.sh`/`dev/reset.sh` unaffected (default image
      preserved); user's persistent volumes intact after a smoke run.

## Post-Completion (manual / external)

- Push the branch / open a PR and confirm the new CI workflow runs: both `wp-smoke` matrix legs
  (6.4 and 7.0) and the `unit` job pass.
