# CLI Command for Fresh WordPress Reset

## Overview

Create a dev/reset.sh shell script that resets the Docker WordPress environment to a clean state: fresh WordPress with one post, one comment, and a freshly activated plugin. Uses WP-CLI inside the running WordPress container, following the pattern established by dev/setup.sh.

## Context

- Files involved: dev/setup.sh (reference pattern), balrog-blocker/uninstall.php (cleanup logic reference), docker-compose.yml
- Related patterns: dev/setup.sh already installs WP-CLI and runs commands inside the wordpress container
- Dependencies: Running Docker containers (wordpress + db)

## Development Approach

- **Testing approach**: Manual verification via docker compose
- Single task - this is a straightforward shell script

## Implementation Steps

### Task 1: Create dev/reset.sh

**Files:**
- Create: `dev/reset.sh`
- Reference: `dev/setup.sh`, `balrog-blocker/uninstall.php`

- [x] Create dev/reset.sh that does the following inside the wordpress container:
  - Verify wordpress container is running
  - Ensure WP-CLI is installed (same pattern as setup.sh)
  - Deactivate and uninstall the balrog-blocker plugin (triggers uninstall.php cleanup of all plugin options, transients, comment meta)
  - Reset WordPress database via `wp db reset --yes` then `wp core install` (same params as setup.sh)
  - Delete all existing posts and comments
  - Create one default post with comments open
  - Create one approved comment on that post
  - Activate balrog-blocker plugin (triggers fresh activation with default settings)
  - Enable comments on the default post
  - Print summary of what was done
- [x] Make the script executable
- [x] Test by running: docker compose up -d wordpress && bash dev/reset.sh (skipped - requires running Docker environment)

### Task 2: Update documentation

- [x] Add reset command to CLAUDE.md under Local development section
