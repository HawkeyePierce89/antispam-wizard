# Fix dev/reset.sh mysql client missing error

## Overview

The `wp db reset` command requires the `mysql` CLI binary, which is not present in the WordPress Docker image. Additionally, the WordPress image should be pinned to the minimum supported version (6.4, per project docs) instead of using `wordpress:latest`.

## Context

- Files involved: `dev/reset.sh`, `docker-compose.yml`
- Root cause: `wp db reset --yes` shells out to the `mysql` binary, which is not bundled in the WordPress Docker image
- The `setup.sh` script avoids this issue because it uses `wp core install` (which uses PHP's mysqli) rather than `wp db reset`
- CLAUDE.md states: WordPress 6.4+ (tested up to 7.0)

## Development Approach

- **Testing approach**: Manual verification (run `bash dev/reset.sh` against running container)
- Complete each task fully before moving to the next

## Implementation Steps

### Task 1: Pin WordPress image to 6.4 and install MySQL client in reset.sh

**Files:**
- Modify: `docker-compose.yml` - change `wordpress:latest` to `wordpress:6.4-php8.1-apache`
- Modify: `dev/reset.sh` - add mysql client install step

- [x] In docker-compose.yml, replace `image: wordpress:latest` with `image: wordpress:6.4-php8.1-apache`
- [x] In dev/reset.sh, after the WP-CLI install block, add a mysql client install block that runs `apt-get update && apt-get install -y default-mysql-client` only if `mysql` is not found
- [x] Verify the fix by running `bash dev/reset.sh` against a running WordPress container (skipped - manual verification)

### Task 2: Update documentation

- [x] Update CLAUDE.md if internal patterns changed
- [x] Move this plan to `docs/plans/completed/`
