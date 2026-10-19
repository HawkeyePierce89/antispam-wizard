# Fix smoke harness: isolate per WordPress image so stale/shared volumes can't poison the 6.4 run

## Overview
The CI failure `Failed opening required '.../wp-includes/blocks/accordion.php'` is not a plugin bug. It comes from a corrupted WordPress core tree: a WP 6.9/7.0-era `require-dynamic-blocks.php` (which references the 6.9 `accordion` block) sitting on top of a tree where `accordion.php` is absent. Verified: the real `wordpress:6.4-php8.1-apache` image is WP 6.4.3 and its `require-dynamic-blocks.php` does not reference `accordion.php`, so a pristine 6.4 boot cannot produce this error.

Root cause: the smoke harness hardcodes compose project `asw-smoke`, so the `6.4` and `7.0` matrix jobs share the same named volumes (`asw-smoke_wp_data`, `asw-smoke_db_data`), containers, and network. The WordPress entrypoint never downgrades core files in a populated volume, so when the runs collide (parallel matrix on a reused/self-hosted daemon, or sequential local runs where teardown didn't fully clear the volume) the 6.4 run boots against a half-7.0 tree. The existing `down -v` guard is not enough because the collision is on shared names.

Fix: make each WordPress image run fully isolated (unique compose project → unique volumes/containers/network), and add a fail-fast assertion that the booted WordPress core version matches the requested image — turning a confusing core fatal into an actionable "stale/mismatched core tree" error and guaranteeing the harness never silently tests the wrong version. WP 6.4 stays in the matrix (it is the documented floor/control).

## Context
- Files involved:
  - `dev/smoke.sh` — hardcoded `PROJECT="asw-smoke"` (line 28); startup `dc down -v` guard (line 77); install step (lines 111-118)
  - `docker-compose.yml` — named volumes `wp_data`, `db_data` (project-prefixed at runtime)
  - `.github/workflows/ci.yml` — `wp-smoke` matrix over `{6.4-php8.1-apache, 7.0-php8.3-apache}`, `fail-fast: false`
  - `CLAUDE.md` — "Runtime smoke test" section documents project `asw-smoke`
- Related patterns: existing `cleanup()` EXIT trap and `down -v` already in `dev/smoke.sh`; `fail()` helper for clear diagnostics
- Dependencies: Docker + docker compose (already required by the harness)

## Development Approach
- **Testing approach**: Regular — change the shell harness, then verify by running it for both images.
- This is shell/CI infrastructure; "tests" means running the smoke harness itself (the harness is the test) plus the existing PHPUnit suite to confirm no regressions.
- Complete each task fully before the next; keep the base `docker-compose.yml` unmutated (the harness uses an inline override, preserve that).
- **CRITICAL: every task MUST include new/updated tests**
- **CRITICAL: all tests must pass before starting next task**

## Implementation Steps

### Task 1: Isolate the smoke run per WordPress image

**Files:**
- Modify: `dev/smoke.sh`

- [x] Derive `PROJECT` from a sanitized `WORDPRESS_IMAGE` (lowercase, non-alphanumeric → `-`), e.g. `asw-smoke-6-4-php8-1-apache`, instead of the hardcoded `asw-smoke`, so the 6.4 and 7.0 runs never share volumes/containers/network even on the same Docker daemon.
- [x] Keep the existing startup `dc down -v --remove-orphans` guard and the `cleanup()` EXIT trap (both now scoped to the per-image project).
- [x] Update the `echo "=== ... (project ${PROJECT}) ==="` line so the log shows the per-image project name.
- [x] Verify: `WORDPRESS_IMAGE=6.4-php8.1-apache bash dev/smoke.sh` boots under the isolated project and reaches the same install/activate steps (it must no longer hit the `accordion.php` fatal during the admin render).

### Task 2: Add a WP core-version assertion gate

**Files:**
- Modify: `dev/smoke.sh`

- [x] Parse the expected `major.minor` from the leading token of `WORDPRESS_IMAGE` (e.g. `6.4` from `6.4-php8.1-apache`, `7.0` from `7.0-php8.3-apache`).
- [x] After `wp core install`, run `wp core version` and `fail()` with a clear "stale/mismatched WordPress core tree (expected X.Y, got Z) — volume not pristine" message when it does not match the expected `major.minor`.
- [x] Confirm the gate runs before the LOADS B admin render so a poisoned tree fails fast with a readable diagnostic instead of the raw `accordion.php` Throwable.
- [x] Verify: temporarily forcing a wrong expected version makes the gate fail with the new message (then revert the force). Confirmed: SMOKE FAIL with "stale/mismatched WordPress core tree (expected 9.9, got 6.4.3) — volume not pristine"; pristine 6.4 run passes with "core version 6.4.3 matches 6.4".

### Task 3: Verify both images end-to-end and update docs

**Files:**
- Modify: `CLAUDE.md` (Runtime smoke test section)

- [x] Run `WORDPRESS_IMAGE=6.4-php8.1-apache bash dev/smoke.sh` → expect `SMOKE PASS`. Confirmed: core version 6.4.3 matches 6.4, 6/6 admin pages, SMOKE PASS.
- [x] Run `bash dev/smoke.sh` (default 7.0) → expect `SMOKE PASS`. Confirmed: core version 7.0 matches 7.0, 6/6 admin pages, SMOKE PASS.
- [x] Run the existing unit suite `docker compose run --rm test` → must pass (no regressions). Confirmed: OK (555 tests, 1666 assertions).
- [x] Update the `CLAUDE.md` "Runtime smoke test" note: the harness now runs under a per-image compose project (e.g. `asw-smoke-<image>`) with ephemeral volumes, so concurrent matrix jobs cannot share `wp_data`.

### Task 4: Verify acceptance criteria

- [x] Re-run both smoke images and the unit suite; confirm all pass. Confirmed: 6.4 SMOKE PASS (core 6.4.3 matches 6.4, 6/6 pages), 7.0 SMOKE PASS (core 7.0 matches 7.0, 6/6 pages), unit suite OK (555 tests, 1666 assertions).
- [x] Confirm `docker-compose.yml` and `.github/workflows/ci.yml` were not modified (fix is contained to `dev/smoke.sh` + docs). Confirmed: `git diff 996d1f9..HEAD` touches only `CLAUDE.md` and `dev/smoke.sh`.
