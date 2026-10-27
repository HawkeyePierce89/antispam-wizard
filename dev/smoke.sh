#!/usr/bin/env bash
#
# Runtime smoke harness for AntiSpam Wizard.
#
# Boots a real, throwaway WordPress (default image: 7.1-php8.3-apache), activates
# the plugin and asserts it:
#   LOADS    A) no plugin-attributable PHP problems in wp-content/debug.log;
#            B) all 6 admin pages render without a Throwable and emit markup.
#   DETECTS  runs tests/smoke/detect-check.php through pre_comment_approved;
#   SHADOW   runs tests/smoke/shadow-check.php: a flagged comment renders as held
#            for its author only, on the classic and the block rendering path;
#   SCHEMA   the live samples table carries every column/index of DB_VERSION.
#
# Fully isolated: runs under a per-run, per-image compose project
# (antispamwizard-smoke-<image>-<pid>) with ephemeral volumes (prefixed
# antispamwizard-smoke-<image>-<pid>_*), no published host ports, and a teardown trap that
# runs `down -v` on success or failure — the user's persistent dev env (project
# antispam-wizard_*) is never read or destroyed. Deriving the project from the
# image *and* this process's PID means no two runs — different images, or two
# concurrent invocations of the same image — ever share volumes, containers, or a
# network on the same Docker daemon, so one run can never boot against (or tear
# down) another's populated (and possibly wrong-version) wp_data volume.
#
# Usage:
#   bash dev/smoke.sh                                   # WP 7.1 (default)
#   WORDPRESS_IMAGE=6.4-php8.1-apache bash dev/smoke.sh # WP 6.4 control
#
set -euo pipefail

export WORDPRESS_IMAGE="${WORDPRESS_IMAGE:-7.1-php8.3-apache}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
cd "$ROOT_DIR"

# Per-run, per-image compose project: sanitize the image ref (lowercase, any run
# of non-alphanumeric chars → a single "-") and append this process's PID so each
# WORDPRESS_IMAGE — and each concurrent invocation of it — gets its own isolated
# volumes/containers/network, e.g. antispamwizard-smoke-6-4-php8-1-apache-12345. The PID
# component stops two same-image runs on one Docker daemon (parallel CI jobs, or
# two local runs) from sharing a project and tearing down each other's stack.
PROJECT_SLUG="$(printf '%s' "$WORDPRESS_IMAGE" | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9]+/-/g; s/^-+//; s/-+$//')"
PROJECT="antispamwizard-smoke-${PROJECT_SLUG}-$$"

# Expected core major.minor: the leading token of WORDPRESS_IMAGE before the
# first "-", reduced to its first two dot-components (e.g. "6.4" from
# "6.4-php8.1-apache" and from "6.4.3-php8.1-apache", "7.1" from
# "7.1-php8.3-apache"). Compared against the major.minor that `wp core version`
# is normalized to below, so a patch-pinned image still matches. Used to fail fast
# on a stale/mismatched core tree instead of letting a poisoned volume surface as
# a raw core Throwable during the admin render.
EXPECTED_WP_TOKEN="${WORDPRESS_IMAGE%%-*}"
EXPECTED_WP_VERSION="$(printf '%s' "$EXPECTED_WP_TOKEN" | cut -d. -f1,2)"
SERVICE="wordpress"
DEBUG_LOG="/var/www/html/wp-content/debug.log"
TESTS_MOUNT="/antispamwizard-smoke-tests"
PLUGIN_PATH_NEEDLE="wp-content/plugins/antispam-wizard"

# Inline override: enable debug logging, drop published ports (exec-only access),
# and mount the smoke test scripts into the container. The base docker-compose.yml
# is never mutated.
OVERRIDE="$(mktemp -t antispamwizard-smoke-override.XXXXXX.yml)"
cat > "$OVERRIDE" <<YAML
services:
  wordpress:
    ports: !override []
    environment:
      WORDPRESS_DEBUG: "1"
      WORDPRESS_CONFIG_EXTRA: |
        define('WP_DEBUG_LOG', true);
        define('WP_DEBUG_DISPLAY', false);
        // Pin the throwaway core: forbid WordPress from rewriting its own files.
        // The floor image (6.4.3) is old enough that api.wordpress.org marks newer
        // releases (incl. 7.0) as auto-update offers, so once wp-cron fires during
        // the run the background automatic updater overwrites wp-includes in place
        // toward 7.0. A swap caught mid-flight leaves a 7.0 deprecated.php (which
        // carries the deprecated wp_get_global_styles_custom_css() wrapper) beside
        // the still-old global-styles-and-settings.php (which still declares it
        // live) — a "Cannot redeclare wp_get_global_styles_custom_css()" fatal that
        // fails the smoke run intermittently. Latest images (7.0) have no offer and
        // never tripped it. AUTOMATIC_UPDATER_DISABLED makes
        // WP_Automatic_Updater::is_disabled() return true (no core/plugin/theme/
        // translation auto-updates); DISALLOW_FILE_MODS belt-and-suspenders blocks
        // any in-WP file write. The smoke harness never relies on WP installing or
        // updating anything (the plugin is bind-mounted, activated via wp-cli), so
        // pinning the tree is purely protective and keeps the run deterministic.
        define('AUTOMATIC_UPDATER_DISABLED', true);
        define('DISALLOW_FILE_MODS', true);
    volumes:
      - ./tests/smoke:${TESTS_MOUNT}:ro
YAML

COMPOSE=(docker compose -p "$PROJECT" -f docker-compose.yml -f "$OVERRIDE")

dc() { "${COMPOSE[@]}" "$@"; }
dc_exec() { dc exec -T "$SERVICE" "$@"; }
wpcli() { dc_exec wp --allow-root "$@"; }

cleanup() {
  echo "Tearing down smoke stack (down -v)..."
  dc down -v --remove-orphans >/dev/null 2>&1 || true
  rm -f "$OVERRIDE"
}
trap cleanup EXIT

fail() {
  echo ""
  echo "SMOKE FAIL ($WORDPRESS_IMAGE): $*"
  exit 1
}

echo "=== AntiSpam Wizard smoke: wordpress:${WORDPRESS_IMAGE} (project ${PROJECT}) ==="

echo "Bringing up db + wordpress..."
# Clean slate (defensive): the per-run PID in ${PROJECT} means this name is not
# shared with a live concurrent run, so there is normally nothing to remove. But
# PIDs are reused over time: if an earlier run that happened to share this PID was
# hard-killed (SIGKILL, CI cancellation, crash) before its EXIT trap fired, its
# ${PROJECT}_wp_data volume could linger. The WordPress entrypoint does not
# downgrade core files in a populated volume, so wipe any leftover before booting
# rather than risk silently testing a stale tree.
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
  --title="AntiSpam Wizard Smoke" \
  --admin_user="admin" \
  --admin_password="admin" \
  --admin_email="admin@example.com" \
  --skip-email

# --- Core-version gate: fail fast on a stale/mismatched core tree ------------
# The WordPress entrypoint never downgrades core files in a populated volume, so
# a leftover volume from another image would boot the wrong WP version. Assert
# the booted core's major.minor matches the requested image BEFORE the admin
# render, turning a confusing core fatal (e.g. the missing accordion.php
# Throwable from a half-7.0 tree under 6.4) into an actionable diagnostic.
echo "Asserting WP core version matches the requested image (expected ${EXPECTED_WP_VERSION})..."
# Wrap the read in an explicit failure path: under `set -euo pipefail` a failing
# `wp core version` (e.g. a corrupted core tree that fatals) would otherwise abort
# the command substitution and exit the script silently, bypassing the actionable
# diagnostic below. Capture stderr to a temp file so the underlying error is
# surfaced in the failure message instead of discarded.
CORE_VERSION_ERR="$(mktemp -t antispamwizard-smoke-coreversion.XXXXXX)"
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

echo "Activating antispam-wizard plugin..."
if ! wpcli plugin activate antispam-wizard; then
  fail "plugin activation returned non-zero (activation fataled)."
fi
echo "  - activation exit 0"

# --- CF7 setup: install/activate Contact Form 7 (soft dependency) -----------
echo "CF7: attempting to install Contact Form 7..."
CF7_AVAILABLE=0
CF7_SKIP="${ANTISPAMWIZARD_SMOKE_SKIP_CF7:-}"
if [ -n "$CF7_SKIP" ] && [ "$CF7_SKIP" != "0" ]; then
  echo "SKIP: CF7 smoke skipped via ANTISPAMWIZARD_SMOKE_SKIP_CF7 — proving inert path."
else
  if wpcli plugin is-installed contact-form-7 >/dev/null 2>&1; then
    if wpcli plugin is-active contact-form-7 >/dev/null 2>&1; then
      echo "  - CF7 already active"
      CF7_AVAILABLE=1
    else
      if wpcli plugin activate contact-form-7 >/dev/null 2>&1; then
        echo "  - CF7 activated (was installed)"
        CF7_AVAILABLE=1
      else
        echo "SKIP: Contact Form 7 installed but activation failed — CF7 smoke skipped, plugin still passes."
      fi
    fi
  else
    if wpcli plugin install contact-form-7 --activate >/dev/null 2>&1; then
      echo "  - CF7 installed and activated"
      CF7_AVAILABLE=1
    else
      echo "SKIP: Contact Form 7 not available (install failed — no network?); CF7 smoke skipped, plugin still passes."
    fi
  fi

  if [ "$CF7_AVAILABLE" -eq 1 ]; then
    if ! wpcli eval "if (class_exists('WPCF7_ContactForm') && class_exists('WPCF7_Submission')) exit(0); else exit(1);" >/dev/null 2>&1; then
      echo "SKIP: CF7 classes not loaded after install/activate — CF7 smoke skipped."
      CF7_AVAILABLE=0
    else
      echo "  - CF7 classes loaded"
    fi
  fi
fi

# --- LOADS assertion B: render all 6 admin pages -----------------------------
echo "LOADS B: rendering 6 admin pages..."
read -r -d '' ADMIN_CHECK_PHP <<'PHP' || true
// WP-CLI does not route PHP error_log()/automatic errors into wp-content/debug.log
// the way the web SAPI does, even with WP_DEBUG_LOG set. Force the destination
// here so warnings/notices/deprecations from the 6 admin-page renders below land
// in debug.log for the LOADS A scan.
error_reporting( E_ALL );
ini_set( 'log_errors', '1' );
ini_set( 'error_log', WP_CONTENT_DIR . '/debug.log' );

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';

if ( ! class_exists( 'ANTISPAMWIZARD_Admin_Page' ) ) {
    fwrite( STDERR, "admin-check: FAIL ANTISPAMWIZARD_Admin_Page missing — plugin not loaded?\n" );
    exit( 1 );
}

// Derive the page slugs from the plugin's canonical constant so the smoke test
// can never drift from the real submenu set.
$slugs = array_values( ANTISPAMWIZARD_Admin_Page::SUBMENU_SLUGS );

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
$uid    = $admins ? (int) $admins[0]->ID : 1;
wp_set_current_user( $uid );
if ( ! current_user_can( 'manage_options' ) ) {
    fwrite( STDERR, "admin-check: FAIL current user lacks manage_options\n" );
    exit( 1 );
}

$admin_page = new ANTISPAMWIZARD_Admin_Page();
$admin_page->init();
do_action( 'admin_menu' );

$failures = array();
foreach ( $slugs as $slug ) {
    $_GET['page'] = $slug;
    ob_start();
    try {
        $admin_page->render_page();
        $html = (string) ob_get_clean();
    } catch ( Throwable $e ) {
        while ( ob_get_level() > 0 ) { ob_end_clean(); }
        $failures[] = sprintf( '%s: threw %s — %s', $slug, get_class( $e ), $e->getMessage() );
        continue;
    }
    if ( '' === trim( $html ) ) {
        $failures[] = sprintf( '%s: rendered empty markup', $slug );
    } else {
        fwrite( STDOUT, sprintf( "  - %s OK (%d bytes)\n", $slug, strlen( $html ) ) );
    }
}

if ( ! empty( $failures ) ) {
    fwrite( STDERR, "admin-check: FAIL\n" );
    foreach ( $failures as $f ) {
        fwrite( STDERR, '  - ' . $f . "\n" );
    }
    exit( 1 );
}

echo "admin-check: OK — 6 pages rendered\n";
exit( 0 );
PHP

if ! wpcli eval "$ADMIN_CHECK_PHP"; then
  fail "LOADS B — one or more admin pages failed to render (see above)."
fi

# --- DETECTS assertion: spam/ham verdicts via pre_comment_approved -----------
echo "DETECTS: running detect-check.php..."
if ! wpcli eval-file "${TESTS_MOUNT}/detect-check.php"; then
  fail "DETECTS — detect-check.php reported a bad verdict (see above)."
fi

# --- CF7 DETECTS: spam/ham via wpcf7_spam (soft dependency) ------------------
if [ "$CF7_AVAILABLE" -eq 1 ]; then
  echo "CF7 DETECTS: running cf7-check.php..."
  if ! wpcli eval-file "${TESTS_MOUNT}/cf7-check.php"; then
    fail "CF7 DETECTS — cf7-check.php reported a bad verdict (see above)."
  fi
else
  echo "CF7 DETECTS: SKIPPED (CF7 not available) — proving inert degradation."
fi

# --- SHADOW assertion: a flagged comment shown to its own author as held -----
# The unit suite drives a WP_Comment_Query stub; only a real core can prove the
# widened query, the result-cache key and the get_comment presentation survive
# comments_template() and the block renderer's re-fetch by ID on every image.
echo "SHADOW: running shadow-check.php..."
if ! wpcli eval-file "${TESTS_MOUNT}/shadow-check.php"; then
  fail "SHADOW — shadow-check.php reported a wrong rendering (see above)."
fi

# --- SCHEMA assertion: the samples-table DDL really landed --------------------
# Activation stamps antispamwizard_samples_db_version only after schema_is_current() confirms
# the layout against the live database, and that probe is the one part of the
# plugin that cannot be exercised by the unit suite's $wpdb stub: dbDelta() has to
# produce the real ALTERs and MySQL has to answer the real SHOW COLUMNS/SHOW INDEX.
# A probe that silently never matches would leave the version unstamped and re-run
# a full dbDelta() on every admin request forever, which no other assertion here
# would notice.
echo "SCHEMA: verifying the samples table matches DB_VERSION..."
read -r -d '' SCHEMA_CHECK_PHP <<'PHP' || true
error_reporting( E_ALL );
ini_set( 'log_errors', '1' );
ini_set( 'error_log', WP_CONTENT_DIR . '/debug.log' );

global $wpdb;

if ( ! class_exists( 'ANTISPAMWIZARD_Sample_Repository' ) ) {
    fwrite( STDERR, "schema-check: FAIL ANTISPAMWIZARD_Sample_Repository missing — plugin not loaded?\n" );
    exit( 1 );
}

$repo     = new ANTISPAMWIZARD_Sample_Repository( $wpdb );
$table    = $repo->get_table_name();
$failures = array();

// These two loops exist only to name *which* key is missing -- the
// schema_is_current() call below is the authoritative verdict. Keep the
// "absent" test identical to ANTISPAMWIZARD_Sample_Repository::probe_versioned_schema(),
// or a divergence here reports a passing column against a failing verdict.
foreach ( ANTISPAMWIZARD_Sample_Repository::VERSIONED_COLUMNS as $column ) {
    $found = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $wpdb->esc_like( $column ) ) );
    if ( null === $found || '' === (string) $found ) {
        $failures[] = 'missing column ' . $column;
    }
}
foreach ( ANTISPAMWIZARD_Sample_Repository::VERSIONED_INDEXES as $index ) {
    $found = $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, $index ) );
    if ( null === $found || '' === (string) $found ) {
        $failures[] = 'missing index ' . $index;
    }
}
if ( ! $repo->schema_is_current() ) {
    $failures[] = 'schema_is_current() returned false';
}

$stored = (string) get_option( ANTISPAMWIZARD_Sample_Repository::DB_VERSION_OPTION, '' );
if ( ANTISPAMWIZARD_Sample_Repository::DB_VERSION !== $stored ) {
    $failures[] = sprintf( 'stored version %s != DB_VERSION %s', '' === $stored ? '(unset)' : $stored, ANTISPAMWIZARD_Sample_Repository::DB_VERSION );
}

if ( ! empty( $failures ) ) {
    fwrite( STDERR, "schema-check: FAIL\n" );
    foreach ( $failures as $f ) {
        fwrite( STDERR, '  - ' . $f . "\n" );
    }
    exit( 1 );
}

// Now the upgrade path, which activation never exercises: register_activation_hook
// does not fire on a plugin update, so ANTISPAMWIZARD::maybe_upgrade_schema() is what has to
// migrate an existing table -- through a real dbDelta() ALTER, not the unit
// suite's stub. Roll the live table back to a pre-release layout that carried
// neither subject key, mark the stored version as behind, and seed a row first:
// adding comment_id (BIGINT UNSIGNED NOT NULL) to a *populated* table is the one
// ALTER whose acceptance depends on the host's sql_mode. dbDelta() never reports
// a refusal, so nothing but this assertion would notice.
$wpdb->query( "ALTER TABLE {$table} DROP INDEX idx_email_hash" );
$wpdb->query( "ALTER TABLE {$table} DROP COLUMN email_hash" );
$wpdb->query( "ALTER TABLE {$table} DROP INDEX idx_comment_id" );
$wpdb->query( "ALTER TABLE {$table} DROP COLUMN comment_id" );

$legacy_text = 'Legacy smoke sample ' . wp_generate_password( 12, false );
$legacy_hash = hash( 'sha256', $legacy_text );
$wpdb->insert(
    $table,
    array(
        'script'       => 'latin',
        'category'     => 'spam',
        'content'      => $legacy_text,
        'content_hash' => $legacy_hash,
        'created_at'   => current_time( 'mysql' ),
    )
);
update_option( ANTISPAMWIZARD_Sample_Repository::DB_VERSION_OPTION, '0' );

if ( $repo->schema_is_current() ) {
    fwrite( STDERR, "schema-check: FAIL rollback did not take — the probe cannot be trusted\n" );
    exit( 1 );
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins ? (int) $admins[0]->ID : 1 );
ANTISPAMWIZARD::get_instance()->maybe_upgrade_schema();

if ( ! $repo->schema_is_current() ) {
    fwrite( STDERR, "schema-check: FAIL dbDelta() did not restore the DB_VERSION layout on an existing table\n" );
    exit( 1 );
}
$stored = (string) get_option( ANTISPAMWIZARD_Sample_Repository::DB_VERSION_OPTION, '' );
if ( ANTISPAMWIZARD_Sample_Repository::DB_VERSION !== $stored ) {
    fwrite( STDERR, sprintf( "schema-check: FAIL upgrade left the version at %s\n", '' === $stored ? '(unset)' : $stored ) );
    exit( 1 );
}

// The seeded row took the new columns' defaults (comment_id = 0, email_hash =
// ''), which neither Privacy API lookup can match -- it holds the trained
// comment's full text that readme.txt promises is exportable and erasable, so
// the migration has to drop it.
$survivors = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE content_hash = %s', $table, $legacy_hash ) );
if ( 0 !== $survivors ) {
    fwrite( STDERR, "schema-check: FAIL the upgrade kept a sample no personal-data request can reach\n" );
    exit( 1 );
}

echo "schema-check: OK — DB_VERSION " . ANTISPAMWIZARD_Sample_Repository::DB_VERSION . " confirmed on the live table, on activation and after an upgrade from a table without the subject keys\n";
exit( 0 );
PHP

if ! wpcli eval "$SCHEMA_CHECK_PHP"; then
  fail "SCHEMA — the samples table does not match DB_VERSION (see above)."
fi

# --- LOADS assertion A: scan debug.log for plugin-attributable problems ------
# Read last, so warnings/notices/deprecations emitted during the admin renders
# and detection above (the contexts that force error_log routing) are captured.
# Activation-time warnings run in a separate WP-CLI process without that forced
# routing and are out of scope here — the activation-fatal case is covered by the
# `wp plugin activate` exit-code check above.
echo "LOADS A: scanning debug.log for plugin-attributable problems..."

# Emit a sentinel so we can tell a genuinely clean boot apart from one where
# debug logging never engaged — otherwise an empty/absent debug.log would let
# LOADS A pass vacuously, defeating the whole point of the scan.
SENTINEL="ANTISPAMWIZARD_SMOKE_DEBUG_SENTINEL"
wpcli eval "ini_set('log_errors','1'); ini_set('error_log', WP_CONTENT_DIR . '/debug.log'); error_log('${SENTINEL}');" >/dev/null 2>&1 || true

DEBUG_CONTENT="$(dc_exec sh -c "cat '$DEBUG_LOG' 2>/dev/null" || true)"

if ! printf '%s\n' "$DEBUG_CONTENT" | grep -qF "$SENTINEL"; then
  fail "LOADS A — debug logging is not active (sentinel missing); a clean scan would be vacuous."
fi

# The detector is fail-open: it logs check Throwables and continues rather than
# re-raising, and that log line carries no "PHP <level>" prefix, so the scan
# below would miss it. Catch the marker directly so a check fataling at runtime
# (exactly the WP-7-compat regression this harness exists to find) cannot pass.
SWALLOWED="$(printf '%s\n' "$DEBUG_CONTENT" | grep -F 'AntiSpam Wizard: check ' || true)"
if [ -n "$SWALLOWED" ]; then
  echo "  swallowed check exceptions in debug.log:"
  printf '%s\n' "$SWALLOWED" | sed 's/^/    /'
  fail "LOADS A — a check threw at runtime (fail-open swallowed exception)."
fi

PLUGIN_PROBLEMS="$(printf '%s\n' "$DEBUG_CONTENT" \
  | grep -E 'PHP (Fatal error|Parse error|Warning|Deprecated|Notice|Recoverable fatal error)' \
  | grep -F "$PLUGIN_PATH_NEEDLE" || true)"

if [ -n "$PLUGIN_PROBLEMS" ]; then
  echo "  plugin-attributable lines in debug.log:"
  printf '%s\n' "$PLUGIN_PROBLEMS" | sed 's/^/    /'
  fail "LOADS A — plugin-attributable PHP problems found in debug.log."
fi
echo "  - debug logging active; no plugin-attributable problems in debug.log"

echo ""
echo "=== SMOKE PASS (wordpress:${WORDPRESS_IMAGE}) ==="
echo "  LOADS A: debug.log clean of plugin problems"
echo "  LOADS B: 6/6 admin pages rendered"
echo "  DETECTS: spam + ham verdicts correct"
echo "  SHADOW:  flagged comment held for its author only (classic + block)"
echo "  SCHEMA:  samples table matches DB_VERSION"
exit 0
