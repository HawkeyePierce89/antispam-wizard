#!/usr/bin/env bash
#
# WordPress.org Plugin Check harness for AntiSpam Wizard.
#
# Boots a real, throwaway WordPress (default image: 7.1-php8.3-apache), installs
# the official `plugin-check` plugin from WordPress.org, activates AntiSpam
# Wizard and runs:
#
#   wp plugin check antispam-wizard --categories=plugin_repo --format=csv
#
# against a *tracked-files-only export* of the plugin. The export is built from
# `git ls-files antispam-wizard`, so untracked junk in the working tree
# (.DS_Store, editor backups, a locally built zip) can never produce a finding —
# what is checked is exactly what would be shipped.
#
# Exit status: 0 when the run reports no ERROR rows, 1 otherwise. WARNING rows
# are always printed for information but never fail the run.
#
# Fully isolated, on the same model as dev/smoke.sh: a per-run, per-image compose
# project (asw-pc-<image>-<pid>) with ephemeral volumes, no published host ports,
# and a teardown trap that runs `down -v` on success or failure — the user's
# persistent dev env (project antispam-wizard_*) is never read or destroyed.
# Deriving the project from the image *and* this process's PID means no two runs
# — different images, or two concurrent invocations of the same image — ever
# share volumes, containers, or a network on the same Docker daemon.
#
# Usage:
#   bash dev/plugin-check.sh                                   # WP 7.1 (default)
#   WORDPRESS_IMAGE=6.4-php8.1-apache bash dev/plugin-check.sh # WP 6.4 control
#
set -euo pipefail

export WORDPRESS_IMAGE="${WORDPRESS_IMAGE:-7.1-php8.3-apache}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
cd "$ROOT_DIR"

# Per-run, per-image compose project: sanitize the image ref (lowercase, any run
# of non-alphanumeric chars → a single "-") and append this process's PID, e.g.
# asw-pc-6-4-php8-1-apache-12345. Same rationale as dev/smoke.sh: two runs on one
# Docker daemon must never share a project and tear down each other's stack.
PROJECT_SLUG="$(printf '%s' "$WORDPRESS_IMAGE" | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9]+/-/g; s/^-+//; s/-+$//')"
PROJECT="asw-pc-${PROJECT_SLUG}-$$"

# Expected core major.minor: the leading token of WORDPRESS_IMAGE before the
# first "-", reduced to its first two dot-components (e.g. "6.4" from
# "6.4.3-php8.1-apache"). Used to fail fast on a stale/mismatched core tree.
EXPECTED_WP_TOKEN="${WORDPRESS_IMAGE%%-*}"
EXPECTED_WP_VERSION="$(printf '%s' "$EXPECTED_WP_TOKEN" | cut -d. -f1,2)"
SERVICE="wordpress"
PLUGIN_SLUG="antispam-wizard"
PLUGIN_MOUNT="/var/www/html/wp-content/plugins/${PLUGIN_SLUG}"
# Plugin Check parses large files through PHPCS; the container's default PHP
# memory limit is not enough for a full plugin_repo run.
PHP_MEMORY_LIMIT="1024M"

# --- Teardown ----------------------------------------------------------------
# The trap is installed before the temp files exist so that a failure part-way
# through creating them still cleans up whatever was created; cleanup() therefore
# guards on each path being set.
EXPORT_DIR=""
OVERRIDE=""
RESULTS=""

cleanup() {
  if [ -n "$OVERRIDE" ]; then
    echo "Tearing down plugin-check stack (down -v)..."
    docker compose -p "$PROJECT" -f docker-compose.yml -f "$OVERRIDE" down -v --remove-orphans >/dev/null 2>&1 || true
    rm -f "$OVERRIDE"
  fi
  [ -n "$RESULTS" ] && rm -f "$RESULTS"
  [ -n "$EXPORT_DIR" ] && rm -rf "$EXPORT_DIR"
  return 0
}
trap cleanup EXIT

# --- Tracked-files-only export ----------------------------------------------
# Built under the repo root (rather than $TMPDIR) so the bind mount works on
# Docker Desktop without depending on which host paths the user has shared.
# Removed by the cleanup trap; .gitignore covers it if a run is hard-killed.
EXPORT_DIR="$(mktemp -d "${ROOT_DIR}/.asw-pc-export.XXXXXX")"
EXPORT_ROOT="${EXPORT_DIR}/${PLUGIN_SLUG}"

OVERRIDE="$(mktemp -t asw-pc-override.XXXXXX.yml)"

fail() {
  echo ""
  echo "PLUGIN CHECK FAIL ($WORDPRESS_IMAGE): $*"
  exit 1
}

echo "=== AntiSpam Wizard plugin check: wordpress:${WORDPRESS_IMAGE} (project ${PROJECT}) ==="

echo "Exporting tracked plugin files to ${EXPORT_ROOT}..."
mkdir -p "$EXPORT_ROOT"
EXPORTED=0
while IFS= read -r -d '' tracked; do
  # $tracked is repo-relative and always begins with "antispam-wizard/".
  # A tracked file missing from the working tree would silently shrink the
  # checked tree below the shipped tree, so it is a hard failure, not a skip.
  [ -f "$tracked" ] || fail "tracked file ${tracked} is missing from the working tree — the export would not match the shipped tree."
  rel="${tracked#${PLUGIN_SLUG}/}"
  dest="${EXPORT_ROOT}/${rel}"
  mkdir -p "$(dirname "$dest")"
  cp "$tracked" "$dest"
  EXPORTED=$(( EXPORTED + 1 ))
done < <(git ls-files -z "$PLUGIN_SLUG")

[ "$EXPORTED" -gt 0 ] || fail "git ls-files exported no plugin files — is this a git checkout?"
echo "  - exported ${EXPORTED} tracked files"

# Inline override: drop published ports (exec-only access) and replace the base
# `./antispam-wizard` bind mount with the read-only tracked-files export. The
# base docker-compose.yml is never mutated.
cat > "$OVERRIDE" <<YAML
services:
  wordpress:
    ports: !override []
    environment:
      WORDPRESS_CONFIG_EXTRA: |
        // Pin the throwaway core: forbid WordPress from rewriting its own files
        // mid-run (see dev/smoke.sh for the full rationale).
        define('AUTOMATIC_UPDATER_DISABLED', true);
    volumes: !override
      - wp_data:/var/www/html
      - ${EXPORT_ROOT}:${PLUGIN_MOUNT}:ro
YAML

COMPOSE=(docker compose -p "$PROJECT" -f docker-compose.yml -f "$OVERRIDE")

dc() { "${COMPOSE[@]}" "$@"; }
dc_exec() { dc exec -T "$SERVICE" "$@"; }
wpcli() { dc_exec wp --allow-root "$@"; }
# Plugin Check itself needs the raised memory limit; keep it off the other calls.
# WP-CLI is installed as a bare phar, and the phar reads WP_CLI_PHP_ARGS only in
# its Behat test framework — the documented env var is honoured by the `wp` shell
# wrapper, which is not what is installed here. Invoke PHP directly so the -d flag
# actually applies.
wpcli_big() { dc_exec php -d memory_limit="${PHP_MEMORY_LIMIT}" /usr/local/bin/wp --allow-root "$@"; }

echo "Bringing up db + wordpress..."
# Clean slate (defensive): PIDs are reused over time, so a run hard-killed before
# its EXIT trap fired could have left a ${PROJECT}_wp_data volume behind. The
# WordPress entrypoint does not downgrade core files in a populated volume.
dc down -v --remove-orphans >/dev/null 2>&1 || true
dc up -d db "$SERVICE"

echo "Waiting for the WordPress container to be ready..."
for i in $(seq 1 60); do
  if dc_exec php -r "echo 'ok';" 2>/dev/null | grep -q ok; then
    break
  fi
  [ "$i" -eq 60 ] && fail "WordPress container did not become ready in time."
  sleep 2
done

echo "Installing WP-CLI in the container..."
dc_exec bash -c '
  if ! command -v wp &>/dev/null; then
    curl -fsSO https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar || { echo "Failed to download WP-CLI"; exit 1; }
    chmod +x wp-cli.phar
    mv wp-cli.phar /usr/local/bin/wp
  fi
'

echo "Waiting for the database connection..."
for i in $(seq 1 30); do
  if dc_exec php -r "
    \$c = @new mysqli('db', 'wpuser', 'wppass', 'wordpress', 3306);
    if (\$c->connect_error) exit(1);
    echo 'ok';
  " 2>/dev/null | grep -q ok; then
    break
  fi
  [ "$i" -eq 30 ] && fail "Could not connect to the database."
  sleep 2
done

echo "Installing throwaway WordPress site..."
wpcli core install \
  --url="http://localhost" \
  --title="ASW Plugin Check" \
  --admin_user="admin" \
  --admin_password="admin" \
  --admin_email="admin@example.com" \
  --skip-email

# --- Core-version gate: fail fast on a stale/mismatched core tree ------------
echo "Asserting WP core version matches the requested image (expected ${EXPECTED_WP_VERSION})..."
CORE_VERSION_ERR="$(mktemp -t asw-pc-coreversion.XXXXXX)"
if ! ACTUAL_WP_VERSION="$(wpcli core version 2>"$CORE_VERSION_ERR" | tr -d '\r' | tr -d '[:space:]')"; then
  CORE_VERSION_DIAG="$(sed 's/^/    /' "$CORE_VERSION_ERR")"
  rm -f "$CORE_VERSION_ERR"
  fail "could not determine WP core version (\`wp core version\` failed) — core tree may be corrupted; run \`docker compose -p ${PROJECT} down -v\` and retry.
${CORE_VERSION_DIAG}"
fi
rm -f "$CORE_VERSION_ERR"
ACTUAL_WP_MAJOR_MINOR="$(printf '%s' "$ACTUAL_WP_VERSION" | cut -d. -f1,2)"
if [ "$ACTUAL_WP_MAJOR_MINOR" != "$EXPECTED_WP_VERSION" ]; then
  fail "stale/mismatched WordPress core tree (expected ${EXPECTED_WP_VERSION}, got ${ACTUAL_WP_VERSION:-<none>}) — volume not pristine; run \`docker compose -p ${PROJECT} down -v\` and retry."
fi
echo "  - core version ${ACTUAL_WP_VERSION} matches ${EXPECTED_WP_VERSION}"

# --- Plugin Check setup ------------------------------------------------------
# Unlike the CF7 soft dependency in dev/smoke.sh, plugin-check is the entire point
# of this harness: a failed install is a hard failure, never a SKIP.
echo "Installing the Plugin Check plugin..."
if ! wpcli plugin install plugin-check --activate; then
  fail "could not install/activate the plugin-check plugin (no network?) — the check cannot run."
fi

echo "Activating ${PLUGIN_SLUG}..."
if ! wpcli plugin activate "$PLUGIN_SLUG"; then
  fail "plugin activation returned non-zero (activation fataled)."
fi
echo "  - activation exit 0"

# --- Run the check -----------------------------------------------------------
echo "Running: wp plugin check ${PLUGIN_SLUG} --categories=plugin_repo"
RESULTS="$(mktemp -t asw-pc-results.XXXXXX.csv)"
# `wp plugin check` exits 0 on every *normal* run, findings or not: the clean case
# ends in `WP_CLI::success( 'Checks complete. No errors found.' )` and the findings
# case prints `FILE: ...` plus CSV rows and simply returns (Plugin Check 2.1.0,
# Plugin_Check_Command::check()). Its only non-zero paths are `WP_CLI::error`.
# So the exit status is a liveness signal, never the verdict — the ERROR/WARNING
# split below decides pass/fail, and warnings must not fail the run.
set +e
wpcli_big plugin check "$PLUGIN_SLUG" \
  --categories=plugin_repo \
  --format=csv \
  --fields=file,line,column,type,code,message 2>&1 | tee "$RESULTS"
CHECK_STATUS="${PIPESTATUS[0]}"
set -e

# A run that never happened produces zero CSV rows, which the counters below would
# otherwise render as a clean pass: an unregistered subcommand, a WP-CLI bootstrap
# error or a PHPCS OOM would all print "0 errors, 0 warnings" and exit 0, turning
# the CI gate green without the check ever executing. Positively assert the run
# terminated normally before trusting the row counts (same reasoning as dev/smoke.sh
# forcing error_log routing so its LOADS scan cannot pass on an empty log).
if grep -qE '^(Error:|PHP Fatal error|Fatal error)|Allowed memory size' "$RESULTS"; then
  echo ""
  echo "  Output:"
  sed 's/^/    /' "$RESULTS"
  fail "the check did not complete (WP-CLI/PHP error above, exit ${CHECK_STATUS}) — the row counts are not trustworthy."
fi
if ! grep -q 'Checks complete' "$RESULTS" && ! grep -q '^file,line,column,type,code,message' "$RESULTS"; then
  echo ""
  echo "  Output:"
  sed 's/^/    /' "$RESULTS"
  fail "wp plugin check emitted neither a completion notice nor a CSV header (exit ${CHECK_STATUS}) — the check did not run."
fi

# The first three CSV columns (file, line, column) never contain a comma, so
# field 4 is a safe naive-split target; header rows have $4 == "type" and are
# ignored by the equality tests below.
ERROR_COUNT="$(awk -F, '$4 == "ERROR" { n++ } END { print n + 0 }' "$RESULTS")"
WARNING_COUNT="$(awk -F, '$4 == "WARNING" { n++ } END { print n + 0 }' "$RESULTS")"
ROW_COUNT="$(awk -F, '$4 == "ERROR" || $4 == "WARNING" { n++ } END { print n + 0 }' "$RESULTS")"

# The two gates above are text scans, so a run killed *after* it emitted the CSV
# header — a container OOM-kill or a segfault, neither of which prints a PHP
# error — slips past them, and the row counts cannot tell "completed with
# warnings" from "killed after the first warning row": both have rows > 0 and no
# ERROR row, and the latter hides every ERROR row the run never got to emit.
# The exit status *can* tell them apart, because a normal run always ends in 0
# (see the invocation comment above), so it is checked unconditionally rather
# than only when no rows were printed: 137 (OOM-kill), 139 (segfault), 255 (PHP
# fatal) and 1 (WP_CLI::error) are all states normal operation cannot produce.
if [ "$CHECK_STATUS" -ne 0 ]; then
  echo ""
  echo "  Output:"
  sed 's/^/    /' "$RESULTS"
  fail "wp plugin check exited ${CHECK_STATUS} — a normal run (clean or with findings) exits 0, so the run terminated abnormally and the ${ROW_COUNT} row(s) it printed are not trustworthy."
fi

echo ""
echo "=== Plugin Check summary (wordpress:${WORDPRESS_IMAGE}) ==="
echo "  ${ERROR_COUNT} errors, ${WARNING_COUNT} warnings"

if [ "$WARNING_COUNT" -gt 0 ]; then
  echo ""
  echo "  WARNING rows (informational, do not fail the run):"
  awk -F, '$4 == "WARNING"' "$RESULTS" | sed 's/^/    /'
fi

if [ "$ERROR_COUNT" -gt 0 ]; then
  echo ""
  echo "  ERROR rows:"
  awk -F, '$4 == "ERROR"' "$RESULTS" | sed 's/^/    /'
  fail "${ERROR_COUNT} error(s) reported by Plugin Check."
fi

echo ""
echo "=== PLUGIN CHECK PASS (wordpress:${WORDPRESS_IMAGE}) — 0 errors, ${WARNING_COUNT} warnings ==="
exit 0
