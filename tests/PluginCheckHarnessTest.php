<?php
/**
 * Tests for the WordPress.org Plugin Check harness (dev/plugin-check.sh).
 *
 * Purely static assertions over the script text — no Docker required — pinning
 * the properties that make the harness safe and reproducible: isolation from the
 * persistent dev env, tracked-files-only export, and the exact check invocation
 * whose output Tasks 2-7 quote sniff codes from.
 */

use PHPUnit\Framework\TestCase;

class PluginCheckHarnessTest extends TestCase {

    private string $script_path;
    private string $script;

    protected function setUp(): void {
        parent::setUp();
        $this->script_path = dirname( __DIR__ ) . '/dev/plugin-check.sh';
        $this->script      = (string) file_get_contents( $this->script_path );
    }

    public function test_script_exists() {
        $this->assertFileExists( $this->script_path );
        $this->assertNotSame( '', trim( $this->script ), 'plugin-check.sh must not be empty.' );
    }

    public function test_script_is_executable() {
        $this->assertTrue(
            is_executable( $this->script_path ),
            'dev/plugin-check.sh must have the executable bit set.'
        );
    }

    public function test_script_has_bash_shebang_and_strict_mode() {
        $this->assertStringStartsWith( '#!/usr/bin/env bash', $this->script );
        $this->assertStringContainsString( 'set -euo pipefail', $this->script );
    }

    /**
     * The rest of this class matches text against the script, which would happily
     * pass on a script bash cannot parse. Parse it for real.
     */
    public function test_script_parses() {
        $output = array();
        $status = 1;
        exec( 'bash -n ' . escapeshellarg( $this->script_path ) . ' 2>&1', $output, $status );
        $this->assertSame( 0, $status, "dev/plugin-check.sh is not valid bash:\n" . implode( "\n", $output ) );
    }

    public function test_honours_wordpress_image_with_smoke_default() {
        $this->assertMatchesRegularExpression(
            '/WORDPRESS_IMAGE="\$\{WORDPRESS_IMAGE:-7\.1-php8\.3-apache\}"/',
            $this->script,
            'WORDPRESS_IMAGE must be overridable and default to the smoke harness default image.'
        );
    }

    public function test_derives_per_run_per_image_compose_project() {
        $this->assertStringContainsString( 'PROJECT="antispamwizard-pc-${PROJECT_SLUG}-$$"', $this->script );
        $this->assertStringContainsString( 'PROJECT_SLUG=', $this->script );
        // Every compose invocation must be pinned to that project, so the user's
        // persistent dev env (project antispam-wizard_*) is never touched.
        $this->assertMatchesRegularExpression(
            '/COMPOSE=\(docker compose -p "\$PROJECT"/',
            $this->script
        );
    }

    public function test_teardown_trap_runs_down_v() {
        $this->assertMatchesRegularExpression(
            '/trap\s+cleanup\s+EXIT/',
            $this->script,
            'A cleanup trap on EXIT is required so a failed run still tears down.'
        );
        $this->assertStringContainsString( 'down -v --remove-orphans', $this->script );
        // Defensive wipe before boot, plus the trap itself: at least twice.
        $this->assertGreaterThanOrEqual(
            2,
            substr_count( $this->script, 'down -v --remove-orphans' ),
            'Expected both a defensive pre-boot wipe and a teardown running down -v.'
        );
    }

    public function test_cleanup_removes_export_directory_and_override() {
        $this->assertMatchesRegularExpression( '/rm -rf "\$EXPORT_DIR"/', $this->script );
        $this->assertMatchesRegularExpression( '/rm -f "\$OVERRIDE"/', $this->script );
    }

    public function test_uses_git_ls_files_for_the_export() {
        $this->assertStringContainsString( 'git ls-files -z', $this->script );
        $this->assertMatchesRegularExpression(
            '/git ls-files -z "\$PLUGIN_SLUG"/',
            $this->script,
            'The export must be built from tracked paths only.'
        );
        $this->assertStringContainsString( 'EXPORT_DIR="$(mktemp -d', $this->script );
    }

    public function test_override_replaces_ports_and_plugin_bind_mount() {
        $this->assertStringContainsString( 'ports: !override []', $this->script );
        $this->assertStringContainsString( 'volumes: !override', $this->script );
        // The export is mounted read-only in place of ./antispam-wizard.
        $this->assertMatchesRegularExpression(
            '/\$\{EXPORT_ROOT\}:\$\{PLUGIN_MOUNT\}:ro/',
            $this->script
        );
        $this->assertStringContainsString( 'wp_data:/var/www/html', $this->script );
    }

    public function test_publishes_no_host_ports() {
        $this->assertStringNotContainsString( 'WP_HOST_PORT', $this->script );
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*-\s*"?\d+:\d+"?\s*$/m',
            $this->script,
            'The harness must not publish any host port.'
        );
    }

    public function test_installs_plugin_check_as_a_hard_dependency() {
        $this->assertStringContainsString( 'plugin install plugin-check --activate', $this->script );
        // Unlike the CF7 soft dependency in smoke.sh, a failure here must abort.
        $this->assertMatchesRegularExpression(
            '/if ! wpcli plugin install plugin-check --activate; then\s*\n\s*fail /',
            $this->script
        );
        $this->assertMatchesRegularExpression(
            '/if ! wpcli plugin activate "\$PLUGIN_SLUG"; then\s*\n\s*fail /',
            $this->script
        );
    }

    public function test_runs_plugin_repo_category_as_csv_with_the_expected_fields() {
        $this->assertStringContainsString( 'plugin check "$PLUGIN_SLUG"', $this->script );
        $this->assertStringContainsString( '--categories=plugin_repo', $this->script );
        $this->assertStringContainsString( '--format=csv', $this->script );
        $this->assertStringContainsString( '--fields=file,line,column,type,code,message', $this->script );
    }

    /**
     * The raised limit must be applied by invoking PHP directly. WP_CLI_PHP_ARGS
     * is honoured only by the `wp` shell wrapper, and the harness installs the
     * bare phar — passing that env var would be a silent no-op.
     */
    public function test_raises_php_memory_limit_for_the_check_run() {
        $this->assertMatchesRegularExpression( '/PHP_MEMORY_LIMIT="\d+M"/', $this->script );
        $this->assertMatchesRegularExpression(
            '/wpcli_big\(\)\s*\{[^}]*php -d memory_limit="\$\{PHP_MEMORY_LIMIT\}" \/usr\/local\/bin\/wp /',
            $this->script,
            'wpcli_big must invoke php -d directly; WP_CLI_PHP_ARGS is inert for a phar install.'
        );
        // The name may appear in the comment explaining why it is not used; it
        // must never appear as an actual env-var assignment.
        $this->assertDoesNotMatchRegularExpression(
            '/(-e\s+|export\s+)?WP_CLI_PHP_ARGS=/',
            $this->script,
            'WP_CLI_PHP_ARGS is inert for a phar install — it must not be relied on.'
        );
    }

    /**
     * The verdict is derived from CSV row counts, so a run that never happened
     * (unregistered subcommand, WP-CLI bootstrap error, PHPCS OOM) would count
     * zero errors and zero warnings and exit 0. The harness must positively
     * assert the check completed before trusting those counts.
     */
    public function test_fails_when_the_check_did_not_actually_run() {
        $this->assertStringContainsString( 'CHECK_STATUS="${PIPESTATUS[0]}"', $this->script );
        $this->assertMatchesRegularExpression(
            '/grep -qE \'\^\(Error:\|PHP Fatal error\|Fatal error\)\|Allowed memory size\' "\$RESULTS"/',
            $this->script,
            'The harness must abort when the output carries a WP-CLI or PHP error.'
        );
        $this->assertMatchesRegularExpression(
            '/if ! grep -q \'Checks complete\' "\$RESULTS" && ! grep -q \'\^file,line,column,type,code,message\' "\$RESULTS"; then/',
            $this->script,
            'The harness must require a completion notice or a CSV header before counting rows.'
        );
        // Both gates above are text scans: a run killed after emitting the CSV
        // header prints no PHP error and satisfies the header gate. The captured
        // exit status is the only signal left, so it must be asserted on, not
        // merely interpolated into failure messages.
        $this->assertMatchesRegularExpression(
            '/if \[ "\$CHECK_STATUS" -ne 0 \]; then/',
            $this->script,
            'The harness must fail on any non-zero check exit status.'
        );
    }

    /**
     * A normal `wp plugin check` run exits 0 whether or not it reports findings
     * (Plugin Check 2.1.0: the clean case is a WP_CLI::success, the findings case
     * prints rows and returns), so the status gate must not be conditioned on the
     * row counts. Row counts cannot distinguish "completed with warnings" from
     * "killed after emitting the first warning row" — the latter has rows > 0 and
     * no ERROR row, and would otherwise fall through to PLUGIN CHECK PASS while
     * hiding every ERROR row the truncated run never emitted.
     */
    public function test_status_gate_is_not_conditioned_on_row_counts() {
        $this->assertDoesNotMatchRegularExpression(
            '/\$CHECK_STATUS" -ne 0 \] && \[ "\$ROW_COUNT"/',
            $this->script,
            'A truncated run with warning rows must still fail: the status gate cannot require ROW_COUNT == 0.'
        );
        $this->assertMatchesRegularExpression(
            '/if \[ "\$CHECK_STATUS" -ne 0 \]; then\s*\n(.*\n)*?\s*fail "wp plugin check exited /',
            $this->script,
            'The unconditional status gate must abort the run.'
        );
        // The invocation comment must not re-assert the retired (and wrong)
        // claim that findings make the command exit non-zero — that premise is
        // what made the gate conditional in the first place.
        $this->assertStringNotContainsString(
            'exits non-zero when it reports findings',
            $this->script,
            'Plugin Check 2.1.0 exits 0 on findings; the harness must not document otherwise.'
        );
    }

    public function test_tees_results_and_counts_errors_and_warnings() {
        $this->assertStringContainsString( 'tee "$RESULTS"', $this->script );
        $this->assertStringContainsString( 'ERROR_COUNT=', $this->script );
        $this->assertStringContainsString( 'WARNING_COUNT=', $this->script );
        $this->assertMatchesRegularExpression(
            '/echo "  \$\{ERROR_COUNT\} errors, \$\{WARNING_COUNT\} warnings"/',
            $this->script
        );
    }

    public function test_fails_only_on_error_rows() {
        $this->assertMatchesRegularExpression(
            '/if \[ "\$ERROR_COUNT" -gt 0 \]; then/',
            $this->script,
            'The run must exit non-zero when any row has type ERROR.'
        );
        $this->assertStringContainsString( 'exit 0', $this->script );
        $this->assertStringContainsString(
            'do not fail the run',
            $this->script,
            'Warnings are printed for information only.'
        );
    }

    public function test_includes_the_core_version_gate() {
        $this->assertStringContainsString( 'EXPECTED_WP_VERSION=', $this->script );
        $this->assertStringContainsString( 'wpcli core version', $this->script );
        $this->assertStringContainsString( 'stale/mismatched WordPress core tree', $this->script );
    }

    public function test_never_references_the_persistent_dev_compose_project() {
        // A bare `docker compose` without -p would inherit the directory-derived
        // project name (antispam-wizard_*) and clobber the user's dev env.
        preg_match_all( '/^\s*docker compose (?!-p )/m', $this->script, $m );
        $this->assertSame(
            array(),
            $m[0],
            'Every docker compose invocation must be pinned with -p "$PROJECT".'
        );
    }

    public function test_export_directory_is_gitignored() {
        // Both sides of the contract: the template the script creates the
        // export under, and the .gitignore pattern that covers it.
        $this->assertStringContainsString(
            'EXPORT_DIR="$(mktemp -d "${ROOT_DIR}/.antispamwizard-pc-export.XXXXXX")"',
            $this->script
        );
        $gitignore = (string) file_get_contents( dirname( __DIR__ ) . '/.gitignore' );
        $this->assertMatchesRegularExpression(
            '/^\.antispamwizard-pc-export\.\*$/m',
            $gitignore,
            'A hard-killed run must not leave untracked junk that git status reports.'
        );
    }
}
