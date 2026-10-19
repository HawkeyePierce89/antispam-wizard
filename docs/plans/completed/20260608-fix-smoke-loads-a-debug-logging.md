# Fix smoke.sh LOADS A failure: force WP-CLI error logging into debug.log

## Overview
The runtime smoke harness fails at "LOADS A" with `debug logging is not active (sentinel missing)`. Root cause: the harness exercises the plugin entirely through `wp-cli` (`wp eval` / `wp eval-file`), and WP-CLI does not route PHP `error_log()` and automatic PHP errors into `wp-content/debug.log` the way the web SAPI does — even though `WP_DEBUG`/`WP_DEBUG_LOG` are set via `WORDPRESS_CONFIG_EXTRA`. So `debug.log` stays empty, the sentinel guard trips, and CI fails on both the 6.4 and 7.0 matrix legs.

Fix: in each PHP context the harness controls, explicitly force the error-log destination to `WP_CONTENT_DIR . '/debug.log'` and enable `log_errors` before emitting the sentinel and before the render/detect runs. Because the eval'd code runs last in the process, its `ini_set()` is the final word and deterministically overrides WP-CLI's routing. This makes the sentinel land AND makes the LOADS A scan capture real plugin warnings/notices/deprecations during admin render and comment detection (preserving the test's intent rather than papering over it).

## Context
- Files involved:
  - `dev/smoke.sh` — the sentinel write step and the inline `ADMIN_CHECK_PHP` heredoc
  - `tests/smoke/detect-check.php` — runs via `wp eval-file`; needs logging forced before it drives `pre_comment_approved`
  - `.github/workflows/ci.yml` — runs the harness across the `{6.4, 7.0}` matrix (no change expected; used for final verification)
- Related patterns: WordPress is fully loaded inside every `wp eval`/`eval-file` context, so `WP_CONTENT_DIR` is available and resolves to `/var/www/html/wp-content` — matches the bash `DEBUG_LOG` variable already used for the `cat` read.
- Why in-eval `ini_set` (not `WORDPRESS_CONFIG_EXTRA`): the override already sets `WP_DEBUG_LOG` in config-extra and it does NOT take effect under WP-CLI — that is exactly the current broken state. Forcing the ini directive inside the eval body is the reliable path.
- Scope note: load-time warnings emitted during plugin bootstrap/activation (before the eval body runs) are out of scope — the meaningful runtime exercises (admin render, detection, and the fail-open "swallowed check exception" marker) all run after the `ini_set` and are captured. The activation-fatal case remains covered by the existing activation exit-code check.

## Development Approach
- **Testing approach**: Regular (fix first, then verify by running the harness). There is no PHPUnit coverage for the shell/smoke layer; the project's runtime test for this code IS `dev/smoke.sh`, so verification = running the harness for both matrix images and confirming a real (non-vacuous) PASS.
- Complete each task fully before moving to the next.
- Requires Docker; the harness pulls WordPress images and boots a throwaway stack (a few minutes per image).

## Implementation Steps

### Task 1: Force error-log routing in the WP-CLI PHP contexts

**Files:**
- Modify: `dev/smoke.sh`
- Modify: `tests/smoke/detect-check.php`

- [x] In `dev/smoke.sh`, update the sentinel write so the eval forces logging before emitting the sentinel, e.g. `wpcli eval "ini_set('log_errors','1'); ini_set('error_log', WP_CONTENT_DIR . '/debug.log'); error_log('${SENTINEL}');"` (keep the existing `>/dev/null 2>&1 || true`).
- [x] In `dev/smoke.sh`, at the very top of the `ADMIN_CHECK_PHP` heredoc body (before the `require_once` lines), add: `error_reporting( E_ALL );`, `ini_set( 'log_errors', '1' );`, `ini_set( 'error_log', WP_CONTENT_DIR . '/debug.log' );` so warnings/notices/deprecations emitted during the 6 admin-page renders are written to debug.log.
- [x] In `tests/smoke/detect-check.php`, immediately after the `ABSPATH` guard, add the same three lines (`error_reporting( E_ALL )`, `ini_set( 'log_errors', '1' )`, `ini_set( 'error_log', WP_CONTENT_DIR . '/debug.log' )`) so problems during the `pre_comment_approved` runs are captured.
- [x] Keep the existing sentinel guard, the swallowed-check marker scan (`AntiSpam Wizard: check `), and the `PHP (Fatal|Warning|...)` + plugin-path scan unchanged — the fix makes them meaningful rather than vacuous.
- [x] Run `WORDPRESS_IMAGE=6.4-php8.1-apache bash dev/smoke.sh` and confirm it reaches `=== SMOKE PASS ===` with `LOADS A: debug logging active; no plugin-attributable problems in debug.log` (sentinel now present).

### Task 2: Verify both CI matrix images pass a non-vacuous smoke

**Files:**
- (verification only; no code changes expected)

- [x] Run `WORDPRESS_IMAGE=6.4-php8.1-apache bash dev/smoke.sh` — must end in SMOKE PASS. (Confirmed: `=== SMOKE PASS (wordpress:6.4-php8.1-apache) ===`.)
- [x] Run `WORDPRESS_IMAGE=7.0-php8.3-apache bash dev/smoke.sh` (default image) — must end in SMOKE PASS. (Confirmed: `=== SMOKE PASS (wordpress:7.0-php8.3-apache) ===`.)
- [x] Confirm the LOADS A line in both runs reports the sentinel as active (the failure message `debug logging is not active (sentinel missing)` no longer appears). (Both runs print `debug logging active; no plugin-attributable problems in debug.log`.)
- [x] If either run surfaces genuine plugin-attributable lines now that logging works, capture them — that is a real regression to address, not a harness bug. (No plugin-attributable lines surfaced in either run; no regression.)

### Task 3: Update documentation

- [x] Update `CLAUDE.md` Runtime smoke test section to note that LOADS A logging is forced to `WP_CONTENT_DIR/debug.log` inside the WP-CLI eval contexts (since WP-CLI does not honor `WP_DEBUG_LOG` routing the way the web SAPI does), so the scan is non-vacuous — only if the existing description is now inaccurate.
