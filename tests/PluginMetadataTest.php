<?php
/**
 * Tests for plugin metadata: header fields and readme.txt consistency.
 */

use PHPUnit\Framework\TestCase;

class PluginMetadataTest extends TestCase {

    private string $plugin_file;
    private string $readme_file;

    protected function setUp(): void {
        parent::setUp();
        $this->plugin_file = dirname( __DIR__ ) . '/antispam-wizard/antispam-wizard.php';
        $this->readme_file = dirname( __DIR__ ) . '/antispam-wizard/readme.txt';
    }

    /**
     * Parse WordPress plugin header fields from the main PHP file.
     */
    private function parse_plugin_header(): array {
        $content = file_get_contents( $this->plugin_file );
        $headers = array();

        $patterns = array(
            'Plugin Name'    => '/\*\s*Plugin Name:\s*(.+)/',
            'Version'        => '/\*\s*Version:\s*(.+)/',
            'Requires PHP'   => '/\*\s*Requires PHP:\s*(.+)/',
            'Requires at least' => '/\*\s*Requires at least:\s*(.+)/',
            'Tested up to'   => '/\*\s*Tested up to:\s*(.+)/',
            'License'        => '/\*\s*License:\s*(.+)/',
        );

        foreach ( $patterns as $key => $pattern ) {
            if ( preg_match( $pattern, $content, $m ) ) {
                $headers[ $key ] = trim( $m[1] );
            }
        }

        return $headers;
    }

    /**
     * Parse readme.txt fields.
     */
    private function parse_readme(): array {
        $content = file_get_contents( $this->readme_file );
        $fields  = array();

        if ( preg_match( '/^===\s*(.+?)\s*===$/m', $content, $m ) ) {
            $fields['Plugin Name'] = $m[1];
        }

        $patterns = array(
            'Requires at least' => '/^Requires at least:\s*(.+)$/m',
            'Tested up to'      => '/^Tested up to:\s*(.+)$/m',
            'Requires PHP'      => '/^Requires PHP:\s*(.+)$/m',
            'Stable tag'        => '/^Stable tag:\s*(.+)$/m',
            'License'           => '/^License:\s*(.+)$/m',
        );

        foreach ( $patterns as $key => $pattern ) {
            if ( preg_match( $pattern, $content, $m ) ) {
                $fields[ $key ] = trim( $m[1] );
            }
        }

        return $fields;
    }

    public function test_plugin_header_has_tested_up_to(): void {
        $headers = $this->parse_plugin_header();
        $this->assertArrayHasKey( 'Tested up to', $headers );
        $this->assertEquals( '7.1', $headers['Tested up to'] );
    }

    public function test_readme_exists(): void {
        $this->assertFileExists( $this->readme_file );
    }

    public function test_readme_has_required_fields(): void {
        $readme = $this->parse_readme();

        $required = array( 'Plugin Name', 'Requires at least', 'Tested up to', 'Requires PHP', 'Stable tag', 'License' );
        foreach ( $required as $field ) {
            $this->assertArrayHasKey( $field, $readme, "readme.txt missing field: $field" );
            $this->assertNotEmpty( $readme[ $field ], "readme.txt field is empty: $field" );
        }
    }

    public function test_readme_has_required_sections(): void {
        $content  = file_get_contents( $this->readme_file );
        $sections = array( '== Description ==', '== Installation ==', '== Changelog ==' );

        foreach ( $sections as $section ) {
            $this->assertStringContainsString( $section, $content, "readme.txt missing section: $section" );
        }
    }

    public function test_header_and_readme_version_match(): void {
        $headers = $this->parse_plugin_header();
        $readme  = $this->parse_readme();

        $this->assertEquals(
            $headers['Version'],
            $readme['Stable tag'],
            'Plugin header Version and readme.txt Stable tag must match'
        );
    }

    public function test_header_and_readme_requires_at_least_match(): void {
        $headers = $this->parse_plugin_header();
        $readme  = $this->parse_readme();

        $this->assertEquals(
            $headers['Requires at least'],
            $readme['Requires at least'],
            'Plugin header and readme.txt Requires at least must match'
        );
    }

    public function test_header_and_readme_tested_up_to_match(): void {
        $headers = $this->parse_plugin_header();
        $readme  = $this->parse_readme();

        $this->assertEquals(
            $headers['Tested up to'],
            $readme['Tested up to'],
            'Plugin header and readme.txt Tested up to must match'
        );
    }

    public function test_header_and_readme_requires_php_match(): void {
        $headers = $this->parse_plugin_header();
        $readme  = $this->parse_readme();

        $this->assertEquals(
            $headers['Requires PHP'],
            $readme['Requires PHP'],
            'Plugin header and readme.txt Requires PHP must match'
        );
    }

    public function test_header_and_readme_plugin_name_match(): void {
        $headers = $this->parse_plugin_header();
        $readme  = $this->parse_readme();

        $this->assertEquals(
            $headers['Plugin Name'],
            $readme['Plugin Name'],
            'Plugin header Plugin Name and readme.txt title must match'
        );
    }

    /**
     * Plugin Check (plugin_repo) rejects hidden files shipped inside a plugin.
     * The training-data directory is guarded by index.php instead.
     */
    public function test_no_hidden_files_are_shipped(): void {
        $plugin_dir = dirname( __DIR__ ) . '/antispam-wizard';

        $this->assertFileDoesNotExist(
            $plugin_dir . '/data/.htaccess',
            'antispam-wizard/data/.htaccess must not ship: Plugin Check flags hidden files'
        );

        // Assert over the tracked set, not the working tree: dev/plugin-check.sh
        // exports `git ls-files` and the release zip ships the same set, so a
        // tracked hidden file is the only kind that can reach WordPress.org.
        // Walking the filesystem instead would fail the whole suite on local
        // never-shipped junk (.DS_Store, .swp, a merge's .orig) — the hard-coded
        // .DS_Store exemption this replaces patched one instance of that class.
        $tracked = array();
        $status  = 1;
        // `-c safe.directory=*` because the test container runs as root while the
        // checkout is owned by the CI runner's uid: without it git aborts with
        // "detected dubious ownership" (exit 128) and this test fails in CI for a
        // reason unrelated to the shipped file set. `-c` counts as protected
        // config for safe.directory in git >= 2.38.
        exec(
            'git -c ' . escapeshellarg( 'safe.directory=*' )
                . ' -C ' . escapeshellarg( dirname( __DIR__ ) ) . ' ls-files antispam-wizard 2>/dev/null',
            $tracked,
            $status
        );
        $this->assertSame( 0, $status, 'git ls-files failed — the shipped file set cannot be determined.' );
        $this->assertNotEmpty( $tracked, 'git ls-files reported no plugin files.' );

        $hidden = array();
        foreach ( $tracked as $file ) {
            $relative = substr( $file, strlen( 'antispam-wizard/' ) );
            foreach ( explode( '/', $relative ) as $segment ) {
                if ( str_starts_with( $segment, '.' ) ) {
                    $hidden[] = $relative;
                    break;
                }
            }
        }

        $this->assertSame(
            array(),
            $hidden,
            'No hidden files may be tracked inside the shipped plugin directory'
        );
    }

    public function test_data_directory_has_silence_guard(): void {
        $index = dirname( __DIR__ ) . '/antispam-wizard/data/index.php';

        $this->assertFileExists(
            $index,
            'antispam-wizard/data/index.php must exist to keep the training corpus unlistable'
        );
        $this->assertStringContainsString(
            'Silence is golden.',
            file_get_contents( $index ),
            'antispam-wizard/data/index.php must carry the silence guard'
        );
    }

    /**
     * dev/bench.php is run with `wp eval-file`, which passes the file through
     * eval() — where a `declare()` can never be the first statement, so
     * `declare( strict_types=1 )` is a fatal there rather than a style choice.
     * It is the only eval-file script under dev/; the others run under `php`.
     */
    public function test_dev_bench_script_carries_no_strict_types_declare(): void {
        $path = dirname( __DIR__ ) . '/dev/bench.php';
        $this->assertFileExists( $path );

        // Tokenize rather than grep: the script documents the rule in a
        // comment that says "No declare(strict_types=1)", which any text
        // pattern loose enough to catch the real statement also matches.
        $declares = array_filter(
            token_get_all( file_get_contents( $path ) ),
            static function ( $token ): bool {
                return is_array( $token ) && T_DECLARE === $token[0];
            }
        );

        $this->assertSame(
            array(),
            array_values( $declares ),
            'dev/bench.php must carry no declare(): wp eval-file runs it through eval(), where a declare() can never be the first statement'
        );
    }

    /**
     * bench.php reports what an object cache would be handed for each unit, and
     * checks *that* figure against the 1 048 576-byte item limit. It cannot ask
     * ASW_Model_Store for the envelope — both constants are private — so it
     * spells them out, and a change to either would make its `cached` column
     * and its limit flag silently disagree with what the plugin really stores
     * and with the numbers CorpusQualityTest reports off the store's own
     * packers.
     */
    public function test_bench_measures_the_envelope_the_store_actually_writes(): void {
        $source = file_get_contents( dirname( __DIR__ ) . '/dev/bench.php' );
        $member = ( new ReflectionClassConstant( ASW_Model_Store::class, 'COMPRESSED_MEMBER' ) )->getValue();
        $level  = ( new ReflectionClassConstant( ASW_Model_Store::class, 'COMPRESSION_LEVEL' ) )->getValue();

        $this->assertStringContainsString(
            'gzcompress( $serialized, ' . $level . ' )',
            $source,
            'bench.php must deflate at the level ASW_Model_Store::COMPRESSION_LEVEL writes at'
        );
        $this->assertStringContainsString(
            "array( '" . $member . "' => \$deflated )",
            $source,
            'bench.php must weigh the envelope member ASW_Model_Store::COMPRESSED_MEMBER writes'
        );
    }

    /**
     * The smoke scripts under tests/smoke/ run through `wp eval-file` too, so
     * the same no-declare() rule applies. The list is spelled out so a new
     * script has to be added here deliberately.
     */
    public function test_smoke_check_scripts_carry_no_strict_types_declare(): void {
        $dir      = dirname( __DIR__ ) . '/tests/smoke';
        $expected = array( 'cf7-check.php', 'detect-check.php', 'shadow-check.php' );

        $found = glob( $dir . '/*.php' );
        sort( $found );
        $this->assertSame( $expected, array_map( 'basename', $found ), 'tests/smoke/ must hold exactly these wp eval-file scripts' );

        foreach ( $expected as $name ) {
            $declares = array_filter(
                token_get_all( file_get_contents( $dir . '/' . $name ) ),
                static function ( $token ): bool {
                    return is_array( $token ) && T_DECLARE === $token[0];
                }
            );
            $this->assertSame(
                array(),
                array_values( $declares ),
                $name . ' must carry no declare(): wp eval-file runs it through eval(), where a declare() can never be the first statement'
            );
        }
    }

    /**
     * dev/smoke.sh runs the SHADOW step on every image of the CI matrix: after
     * DETECTS, and before the LOADS A debug.log scan so anything the shadow
     * renders log is scanned too.
     */
    public function test_smoke_harness_runs_the_shadow_check_before_the_log_scan(): void {
        $script = (string) file_get_contents( dirname( __DIR__ ) . '/dev/smoke.sh' );

        $output = array();
        $status = 1;
        exec( 'bash -n ' . escapeshellarg( dirname( __DIR__ ) . '/dev/smoke.sh' ) . ' 2>&1', $output, $status );
        $this->assertSame( 0, $status, "dev/smoke.sh is not valid bash:\n" . implode( "\n", $output ) );

        $shadow = strpos( $script, 'wpcli eval-file "${TESTS_MOUNT}/shadow-check.php"' );
        $this->assertNotFalse( $shadow, 'dev/smoke.sh must run shadow-check.php through wp eval-file' );
        $this->assertMatchesRegularExpression(
            '/if ! wpcli eval-file "\$\{TESTS_MOUNT\}\/shadow-check\.php"; then\s+fail /',
            $script,
            'a failing shadow-check.php must fail the smoke run'
        );
        $this->assertGreaterThan( strpos( $script, 'wpcli eval-file "${TESTS_MOUNT}/detect-check.php"' ), $shadow, 'SHADOW runs after DETECTS' );
        $this->assertLessThan( strpos( $script, 'echo "LOADS A: scanning debug.log' ), $shadow, 'SHADOW runs before the LOADS A debug.log scan' );
    }

    /**
     * Without the global $post, render_block() seeds no postId context, the
     * block path renders an empty comment list and every "absent" assertion
     * passes vacuously. The approved comment's text is therefore asserted
     * first, on every pass, before anything else is judged.
     */
    public function test_shadow_smoke_check_cannot_pass_vacuously(): void {
        $source = (string) file_get_contents( dirname( __DIR__ ) . '/tests/smoke/shadow-check.php' );

        $this->assertMatchesRegularExpression( '/\$GLOBALS\[\s*\'post\'\s*\]\s*=\s*get_post\(\s*\$post_id\s*\)/', $source, 'shadow-check.php must set the global $post' );
        $this->assertStringContainsString( "setup_postdata( \$GLOBALS['post'] )", $source );
        $this->assertStringContainsString( "COOKIEHASH", $source, 'the author pass must set core\'s commenter cookie' );

        // The helper's first statement is the approved-text guard.
        $this->assertMatchesRegularExpression(
            '/function asw_shadow_smoke_assert\([^)]*\): array \{\s*if \( false === strpos\( \$html, \$texts\[\'approved\'\] \) \) \{\s*return array\(/',
            $source,
            'asw_shadow_smoke_assert() must bail out with a failure when the approved comment did not render'
        );
        foreach ( array( 'classic, author', 'block, author', 'classic, anonymous', 'block, anonymous' ) as $pass ) {
            $render = 0 === strpos( $pass, 'block' ) ? 'asw_shadow_smoke_render_blocks()' : 'asw_shadow_smoke_render_classic()';
            $this->assertStringContainsString( "asw_shadow_smoke_assert( '" . $pass . "', " . $render, $source, "the $pass pass must go through the guarded helper" );
        }
        $this->assertStringContainsString( '<!-- wp:comments --><!-- wp:comment-template --><!-- wp:comment-content /-->', $source, 'the block path must render core/comments + comment-template + comment-content' );
        $this->assertStringContainsString( 'comments_template();', $source, 'the classic path must render through comments_template()' );
    }

    /**
     * The smoke check must also reach the author who posted without cookie
     * consent: a pass carrying only core's unapproved + moderation-hash pair,
     * taken from the plugin's comment_post_redirect callback run through the
     * real filter and checked against the real wp_hash(), plus the two pairs core must
     * reject (a wrong hash; a valid hash outside the ten-minute window). Each
     * goes through the guarded helper on both render paths, and $_GET is
     * cleared afterwards so the pair cannot leak into the storage checks.
     */
    public function test_shadow_smoke_check_carries_the_cookie_less_pass(): void {
        $source = (string) file_get_contents( dirname( __DIR__ ) . '/tests/smoke/shadow-check.php' );

        $this->assertStringContainsString( "apply_filters( 'comment_post_redirect', get_comment_link( \$ids['flagged'] ), WP_Comment::get_instance( \$ids['flagged'] ) )", $source, 'the cookie-less pass must take its pair from the real comment_post_redirect filter, fed the unfiltered spam row' );
        $this->assertStringContainsString( "unset( \$_POST['wp-comment-cookies-consent'] );", $source, 'the redirect must run without the consent key' );
        $this->assertStringContainsString( "wp_hash( \$flagged_gmt ) !== (string) ( \$redirect_args['moderation-hash'] ?? '' )", $source, 'the redirect pair must carry core\'s hash of the comment date' );
        $this->assertMatchesRegularExpression( '/\$_GET\[\'unapproved\'\]\s*=\s*\(string\) \( \$redirect_args\[\'unapproved\'\] \?\? \'\' \);/', $source, 'the cookie-less pass must land on the redirect\'s unapproved' );
        $this->assertMatchesRegularExpression( '/\$_GET\[\'moderation-hash\'\]\s*=\s*\(string\) \( \$redirect_args\[\'moderation-hash\'\] \?\? \'\' \);/', $source, 'the cookie-less pass must land on the redirect\'s moderation-hash' );
        $this->assertMatchesRegularExpression( '/\$_GET\[\'moderation-hash\'\]\s*=\s*wp_hash\( \$expired_gmt \);/', $source, 'the expired-window case must recompute the hash for the moved date' );
        $this->assertStringContainsString( 'time() - 11 * MINUTE_IN_SECONDS', $source, 'the expired-window case must move the date 11 minutes back' );
        $this->assertStringContainsString( 'clean_comment_cache( $id );', $source, 'a re-dated comment must leave the object cache' );

        $passes = array(
            'cookie-less author' => 'count( $author_present )',
            'invalid hash'       => '0',
            'expired window'     => '0',
        );
        foreach ( $passes as $pass => $notes ) {
            foreach ( array( 'classic' => 'asw_shadow_smoke_render_classic()', 'block' => 'asw_shadow_smoke_render_blocks()' ) as $path => $render ) {
                $call = "asw_shadow_smoke_assert( '" . $path . ', ' . $pass . "', " . $render;
                $this->assertStringContainsString( $call, $source, "the $path $pass pass must go through the guarded helper" );
                $at   = strpos( $source, $call );
                $line = substr( $source, $at, strpos( $source, "\n", $at ) - $at );
                $this->assertMatchesRegularExpression( '/, ' . preg_quote( $notes, '/' ) . ' \),?$/', $line, "the $path $pass pass must expect $notes awaiting-moderation note(s)" );
            }
        }

        // The cookie-less pass runs without the cookie the author pass set.
        $this->assertLessThan(
            strpos( $source, "'classic, cookie-less author'" ),
            strpos( $source, 'unset( $_COOKIE[ $cookie_name ], $_COOKIE[ $cookie_email ] );' ),
            'the cookie must be cleared before the cookie-less pass'
        );
        // The pair is cleared before the storage checks and in the finally.
        $this->assertLessThan(
            strpos( $source, '// --- The presentation never reached storage.' ),
            strpos( $source, "unset( \$_GET['unapproved'], \$_GET['moderation-hash'] );" )
        );
        $this->assertMatchesRegularExpression( '/\} finally \{\s*unset\([^;]*\$_GET\[\'unapproved\'\], \$_GET\[\'moderation-hash\'\] \);/', $source );
    }

    /**
     * Every Plugin Check deviation must be an annotated suppression carrying a
     * reason after ` -- `, never a bare `phpcs:ignore`. A bare suppression hides
     * a finding without recording why it is acceptable, which is exactly what
     * the WordPress.org review looks for. `phpcs:enable` is exempt: it merely
     * closes a `phpcs:disable` whose reason was already stated.
     */
    public function test_every_phpcs_suppression_carries_a_reason(): void {
        $plugin_dir = dirname( __DIR__ ) . '/antispam-wizard';
        $iter       = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $plugin_dir, FilesystemIterator::SKIP_DOTS )
        );

        $bare  = array();
        $found = 0;
        foreach ( $iter as $path => $info ) {
            if ( 'php' !== strtolower( $info->getExtension() ) ) {
                continue;
            }
            $relative = substr( $path, strlen( $plugin_dir ) + 1 );
            foreach ( file( $path ) as $number => $line ) {
                // `ignoreFile` must be listed before `ignore`: \b does not fire
                // between "ignore" and "F", so a plain /phpcs:ignore\b/ never
                // sees a whole-file suppression — the broadest silencer of all.
                // The legacy @codingStandardsIgnore* spellings are caught too.
                if ( ! preg_match( '/phpcs:(ignoreFile|ignore|disable)\\b|@codingStandardsIgnore/', $line ) ) {
                    continue;
                }
                ++$found;
                // A suppression must name the exact sniff(s) it silences and then
                // give a reason: ` -- ` prose alone would still let a blanket
                // `phpcs:ignore -- because` through, silencing every sniff on the
                // next line including ones nobody evaluated.
                if ( ! preg_match( '/(?:phpcs:(?:ignoreFile|ignore|disable)|@codingStandardsIgnore\\w*)\\s+[A-Z]\\w*(?:\\.\\w+)+[^\\r\\n]*? -- \\S/', $line ) ) {
                    $bare[] = $relative . ':' . ( $number + 1 );
                }
            }
        }

        $this->assertSame(
            array(),
            $bare,
            'Every phpcs:ignore/phpcs:disable must be followed by " -- <reason>"'
        );
        $this->assertGreaterThan(
            0,
            $found,
            'Expected the plugin to contain annotated phpcs suppressions'
        );
    }

    /**
     * `phpcs:disable` silences its sniffs for the rest of the file unless a
     * matching `phpcs:enable` closes the region. A disable whose enable is
     * deleted or moved during a refactor would leave security sniffs off across
     * everything below it, with nothing else in the suite noticing -- which
     * would break the invariant that every deviation is narrowly scoped.
     */
    public function test_every_phpcs_disable_is_closed_by_a_matching_enable(): void {
        $plugin_dir = dirname( __DIR__ ) . '/antispam-wizard';
        $iter       = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $plugin_dir, FilesystemIterator::SKIP_DOTS )
        );

        $unbalanced = array();
        foreach ( $iter as $path => $info ) {
            if ( 'php' !== strtolower( $info->getExtension() ) ) {
                continue;
            }
            $relative = substr( $path, strlen( $plugin_dir ) + 1 );

            // Sniff code => line number of the currently open disable, if any.
            $open = array();
            foreach ( file( $path ) as $number => $line ) {
                if ( preg_match( '/phpcs:(disable|enable)\\s+([^\\r\\n]*)/', $line, $m ) ) {
                    $codes = preg_split( '/\\s*,\\s*/', trim( preg_replace( '/ --.*$/', '', $m[2] ) ) );
                    foreach ( array_filter( $codes ) as $code ) {
                        if ( 'disable' === $m[1] ) {
                            $open[ $code ] = $number + 1;
                        } else {
                            unset( $open[ $code ] );
                        }
                    }
                }
            }

            foreach ( $open as $code => $line_number ) {
                $unbalanced[] = $relative . ':' . $line_number . ' (' . $code . ')';
            }
        }

        $this->assertSame(
            array(),
            $unbalanced,
            'Every phpcs:disable must be closed by a phpcs:enable for the same sniff code'
        );
    }

    /**
     * A `phpcs:ignore` naming `WordPress.DB.PreparedSQL.NotPrepared` must sit
     * directly above a single-line statement.
     *
     * A previous-line suppression covers exactly one line. That sniff reports
     * on the query argument at the `$wpdb->query( … )` call itself, so when the
     * call is written across several lines -- `$wpdb->query(` on one, the
     * `$wpdb->prepare( … )` argument two lines below -- the ignore silences the
     * opening line and the finding is still reported against the argument. The
     * plugin's shape is therefore the one `class-sample-repository.php` uses:
     * prepare into a variable, then a bare one-line query call under the
     * suppression.
     *
     * `PluginCheck.Security.DirectDB.UnescapedDBParameter` is held to the same
     * rule: it too reports at the `$wpdb->query( … )` call, which is why
     * `ASW_Model_Store::delete_stray_rows()`'s suppression names it and its
     * reason says the call must stay on one line.
     *
     * The match is deliberately exact. `WordPress.DB.PreparedSQL.InterpolatedNotPrepared`
     * and `WordPress.DB.PreparedSQLPlaceholders.*` report on the interpolated
     * string argument rather than the query call, so they are correctly
     * anchored above a multi-line `prepare()` and must not be caught here.
     */
    public function test_not_prepared_suppressions_are_anchored_on_a_single_line_statement(): void {
        $plugin_dir = dirname( __DIR__ ) . '/antispam-wizard';
        $iter       = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $plugin_dir, FilesystemIterator::SKIP_DOTS )
        );

        $misanchored = array();
        $found       = 0;
        foreach ( $iter as $path => $info ) {
            if ( 'php' !== strtolower( $info->getExtension() ) ) {
                continue;
            }
            $relative = substr( $path, strlen( $plugin_dir ) + 1 );
            $lines    = file( $path );

            foreach ( $lines as $number => $line ) {
                if ( ! preg_match( '/phpcs:ignore\\b/', $line ) ) {
                    continue;
                }
                // Exact sniff names: the surrounding character classes keep
                // InterpolatedNotPrepared and PreparedSQLPlaceholders.* out.
                if ( ! preg_match(
                    '/(?<![\\w.])(?:WordPress\\.DB\\.PreparedSQL\\.NotPrepared|PluginCheck\\.Security\\.DirectDB\\.UnescapedDBParameter)(?![\\w.])/',
                    $line
                ) ) {
                    continue;
                }

                // A trailing suppression -- code, then the comment on the same
                // line -- anchors itself on the statement it sits on, so the
                // next line says nothing about it.
                if ( ! preg_match( '#^\\s*(?://|/\\*|\\*)#', $line ) ) {
                    continue;
                }
                ++$found;

                // Exactly the next line, with no blank skipped: PHPCS applies a
                // previous-line ignore to the literal following line, so a
                // suppression separated from its statement by a blank line does
                // not reach it -- which is the failure this test exists to catch.
                $covered = isset( $lines[ $number + 1 ] ) ? trim( $lines[ $number + 1 ] ) : null;

                if ( null === $covered
                    || '' === $covered
                    || ';' !== substr( $covered, -1 )
                    || 0 === strpos( $covered, '//' )
                    || 0 === strpos( $covered, '*' )
                    || 0 === strpos( $covered, '/*' )
                ) {
                    $misanchored[] = $relative . ':' . ( $number + 1 );
                }
            }
        }

        $this->assertSame(
            array(),
            $misanchored,
            'A NotPrepared / UnescapedDBParameter ignore must sit on the line directly above a single-line statement ending in ";" -- a previous-line suppression covers one line only'
        );
        $this->assertGreaterThan(
            0,
            $found,
            'Expected the plugin to carry NotPrepared / UnescapedDBParameter suppressions'
        );
    }

    /**
     * Every lock-file fopen() must be silenced and every lock-failure
     * diagnostic must name only the file, never the absolute path.
     *
     * The four lock-taking classes (ASW_Duplicate_Check, ASW_Spam_Log,
     * ASW_Stats, ASW_Moderation) all run on an unauthenticated request and debug.log is
     * web-readable on misconfigured hosts, so either half of the pair leaks the
     * document root on its own: an unsuppressed fopen() emits PHP's own "failed
     * to open stream" warning naming the absolute path, and the plugin's own
     * diagnostic names it too without basename(). Same rule as
     * ASW_Detector::run() and ASW_CF7_Integration::filter_spam().
     *
     * DuplicateCheckTest::test_duplicate_check_still_counts_when_the_lock_file_cannot_be_opened()
     * pins one of the fourteen sites behaviourally, but the branch is reachable only
     * when the uploads directory is unwritable, so on a healthy host neither the
     * suite nor dev/smoke.sh's debug.log scan would notice the other thirteen
     * regressing. Asserting the rule on the source text covers all fourteen, and any
     * lock site a later class adds.
     *
     * The open/diagnostic pairing is counted **per file**, not site-wide: a
     * plugin-wide total is satisfied by the wrong pairing, so a new lock site
     * that forgot its diagnostic would pass as long as some other file carried
     * a stray one. The patterns match any $…lock… handle rather than the
     * literal $lock_file, so a site that names its variable differently is
     * still covered instead of silently falling outside the rule.
     *
     * Naming alone is not enough to make that complete, though: an open written
     * as fopen( self::lock_path(), 'c' ) or fopen( $path, 'c' ) matches neither
     * pattern, so it would be counted by neither branch and silently escape both
     * assertions. The counted opens are therefore reconciled against the
     * LOCK_EX acquisitions in the same file - every acquisition needs a handle,
     * and the only way to get one here is fopen() - so a lock site this file's
     * patterns cannot see turns into a count mismatch rather than a pass.
     */
    public function test_lock_file_failures_never_log_an_absolute_path(): void {
        $plugin_dir = dirname( __DIR__ ) . '/antispam-wizard';
        $iter       = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $plugin_dir, FilesystemIterator::SKIP_DOTS )
        );

        $unsilenced  = array();
        $unsanitized = array();
        $opens       = array();
        $diagnostics = array();
        $acquisitions = array();
        foreach ( $iter as $path => $info ) {
            if ( 'php' !== strtolower( $info->getExtension() ) ) {
                continue;
            }
            $relative = substr( $path, strlen( $plugin_dir ) + 1 );
            foreach ( file( $path ) as $number => $line ) {
                if ( preg_match( '/\bflock\s*\(\s*\$\w+\s*,\s*LOCK_EX\s*\)/', $line ) ) {
                    $acquisitions[ $relative ] = ( $acquisitions[ $relative ] ?? 0 ) + 1;
                }
                if ( preg_match( '/(?<!@)\bfopen\s*\(\s*\$\w*lock\w*/i', $line ) ) {
                    $opens[ $relative ] = ( $opens[ $relative ] ?? 0 ) + 1;
                    $unsilenced[]       = $relative . ':' . ( $number + 1 );
                } elseif ( preg_match( '/@fopen\s*\(\s*\$\w*lock\w*/i', $line ) ) {
                    $opens[ $relative ] = ( $opens[ $relative ] ?? 0 ) + 1;
                }

                if ( false !== strpos( $line, 'unable to open lock file' ) ) {
                    $diagnostics[ $relative ] = ( $diagnostics[ $relative ] ?? 0 ) + 1;
                    // The path may reach the log only through basename(). Strip
                    // every basename( $var ) call and flag any lock handle that
                    // survives, rather than matching the expected concatenation:
                    // a pattern anchored at the *start* of the concatenation is
                    // satisfied by `… . basename( $lock_file ) . ' in ' .
                    // $lock_file` and by dirname( $lock_file ), both of which
                    // restore the exact leak this test exists to prevent.
                    $stripped = preg_replace( '/basename\(\s*\$\w+\s*\)/', '', $line );
                    if ( preg_match( '/\$\w*lock\w*/i', $stripped ) ) {
                        $unsanitized[] = $relative . ':' . ( $number + 1 );
                    }
                }
            }
        }

        ksort( $opens );
        ksort( $diagnostics );
        ksort( $acquisitions );

        $this->assertSame(
            array(),
            $unsilenced,
            "fopen() on a lock file must be @-suppressed; PHP's own warning names the absolute path"
        );
        $this->assertSame(
            array(),
            $unsanitized,
            'The lock-failure diagnostic must log basename( $lock_file ), never the path itself'
        );
        $this->assertNotSame( array(), $opens, 'Expected the plugin to open lock files' );
        $this->assertSame(
            $opens,
            $diagnostics,
            'Every lock-file fopen() must report its failure through the sanitized diagnostic, in the same file'
        );
        $this->assertSame(
            $acquisitions,
            $opens,
            'Every LOCK_EX acquisition needs a handle, and fopen() is the only source of one: a mismatch means a lock site this test cannot see, not a passing one'
        );
    }

    /**
     * Every exclusive lock must be released in a finally block.
     *
     * The critical sections these locks bracket all run third-party code:
     * update_option()/get_transient() fire the option_*, update_option and
     * pre_transient_* filters, so any plugin on the site can throw from inside
     * the section. ASW_Detector::run() catches that Throwable and continues to
     * the next check, and the CF7 integration catches it too, so the request
     * does *not* end there -- a release placed after the call instead of in a
     * finally would leave the exclusive lock held and the descriptor open for
     * the rest of the request. For ASW_Duplicate_Check that is now one lock per
     * hashed IP rather than per IP + text, so on a shared comment_author_IP (a
     * CDN that does not restore the real address, CGNAT, the CF7 remote_ip
     * meta) it blocks every other submitter site-wide, and LOCK_EX is
     * deliberately blocking with no LOCK_NB fall-through.
     *
     * Nothing in the unit suite can reach that branch -- it needs a filter that
     * throws from inside get_transient() -- so the rule is asserted on the
     * source text, per acquisition, for every lock site the plugin has.
     */
    public function test_every_exclusive_lock_is_released_in_a_finally_block(): void {
        $plugin_dir = dirname( __DIR__ ) . '/antispam-wizard';
        $iter       = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $plugin_dir, FilesystemIterator::SKIP_DOTS )
        );

        $unprotected  = array();
        $acquisitions = 0;
        foreach ( $iter as $path => $info ) {
            if ( 'php' !== strtolower( $info->getExtension() ) ) {
                continue;
            }
            $relative = substr( $path, strlen( $plugin_dir ) + 1 );
            $lines    = file( $path );
            foreach ( $lines as $number => $line ) {
                if ( ! preg_match( '/\bflock\s*\(\s*\$\w+\s*,\s*LOCK_EX\s*\)/', $line ) ) {
                    continue;
                }
                ++$acquisitions;

                // Walk forward to this acquisition's release and to the finally
                // that must contain it. Requiring only that a `finally {` appear
                // somewhere before the release is not enough: it is satisfied by
                //
                //     try { ... } finally { fclose( $fp ); }
                //     if ( $locked ) { flock( $fp, LOCK_UN ); }
                //
                // which restores the exact held-lock leak this test exists to
                // prevent. Track brace depth from the `finally {` instead and
                // require the release to still be inside that block.
                $released  = false;
                $protected = false;
                $depth     = null;
                $total     = count( $lines );
                for ( $i = $number + 1; $i < $total; $i++ ) {
                    // Strip quoted strings and then line comments before counting
                    // braces, so a brace inside either cannot skew the depth.
                    // Strings go first: a '//' inside one would otherwise make
                    // the comment stripper eat the rest of the line.
                    $code = preg_replace( array( '/\'[^\']*\'|"[^"]*"/', '{//.*$}' ), '', $lines[ $i ] );

                    if ( null === $depth && preg_match( '/\bfinally\s*\{(.*)$/', $code, $match ) ) {
                        // Depth 1 for the brace the `finally` just opened, plus
                        // whatever the rest of the line opens or closes.
                        $depth = 1 + substr_count( $match[1], '{' ) - substr_count( $match[1], '}' );
                    } elseif ( null !== $depth ) {
                        $depth += substr_count( $code, '{' ) - substr_count( $code, '}' );
                    }

                    if ( preg_match( '/\bflock\s*\(\s*\$\w+\s*,\s*LOCK_UN\s*\)/', $lines[ $i ] ) ) {
                        $released = true;
                        // $depth is the depth *after* this line, so a release on
                        // the closing line of the block still counts as inside.
                        $protected = null !== $depth && $depth >= 0;
                        break;
                    }

                    if ( null !== $depth && $depth <= 0 ) {
                        // The finally block closed without releasing.
                        break;
                    }
                }

                if ( ! $released || ! $protected ) {
                    $unprotected[] = $relative . ':' . ( $number + 1 );
                }
            }
        }

        $this->assertSame(
            array(),
            $unprotected,
            'Every flock( LOCK_EX ) must be released by a flock( LOCK_UN ) inside a finally block'
        );
        $this->assertGreaterThan( 0, $acquisitions, 'Expected the plugin to take exclusive locks' );
    }
}
