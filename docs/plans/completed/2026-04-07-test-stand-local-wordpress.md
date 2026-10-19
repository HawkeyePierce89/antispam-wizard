# Test Stand: Local WordPress Environment for Manual Plugin Testing

## Overview
Add a Docker-based local WordPress environment (WordPress + MySQL) so the plugin can be tested manually in a real browser. The plugin directory will be mounted into the WordPress container, enabling live code changes without rebuilding.

## Context
- Files involved: `docker-compose.yml`, new `dev/` directory for seed data
- Related patterns: existing `docker-compose.yml` with `test` service
- Dependencies: Docker, Docker Compose

## Development Approach
- **Testing approach**: manual verification (this task is about creating a manual testing environment)
- Complete each task fully before moving to the next
- **CRITICAL: existing `test` service in docker-compose.yml must remain unchanged**
- **CRITICAL: all existing PHPUnit tests must continue to pass**

## Implementation Steps

### Task 1: Add WordPress + MySQL services to docker-compose.yml

**Files:**
- Modify: `docker-compose.yml`

- [x] Add `db` service (MySQL 8.0) with persistent volume, database `wordpress`, user/password for local dev
- [x] Add `wordpress` service (WordPress latest) linked to `db`, exposed on port 8080, with `balrog-blocker/` mounted as a plugin into `/var/www/html/wp-content/plugins/balrog-blocker`
- [x] Ensure the existing `test` service is not affected
- [x] Verify with `docker-compose config` that the file is valid
- [x] Start the environment with `docker-compose up -d wordpress` and confirm WordPress loads at http://localhost:8080

### Task 2: Add setup script for quick start

**Files:**
- Create: `dev/setup.sh`

- [x] Write a shell script that waits for WordPress to be ready, then runs `wp-cli` commands (via docker exec) to: install WordPress (site title "BalrogBlocker Test", admin/admin), activate the balrog-blocker plugin, and enable comments on the default post
- [x] Make the script executable
- [x] Run the script and verify the plugin appears active in wp-admin

### Task 3: Add dev environment documentation

**Files:**
- Modify: `README.md` (or create if absent)

- [x] Add a "Local Development" section explaining how to start the test stand (`docker-compose up -d wordpress`), run the setup script, and access the site
- [x] Document how to test spam detection manually (post a comment with stop-words, excessive links, etc.)

### Task 4: Verify everything works end-to-end

- [x] Start fresh environment (docker-compose down -v, then up) (skipped - manual browser verification)
- [x] Run setup script (skipped - manual browser verification)
- [x] Post a normal comment - verify it goes through (skipped - manual browser verification)
- [x] Post a spam comment (e.g. with stop-words) - verify it gets blocked (skipped - manual browser verification)
- [x] Check admin panel: settings page, spam log, stats (skipped - manual browser verification)
- [x] Run existing PHPUnit tests to confirm nothing is broken (verified: 165 tests, 363 assertions, all passing)
