<?php

use PHPUnit\Framework\TestCase;

class I18nTest extends TestCase {
    private $pot_file;
    private $po_files;
    private $mo_files;
    private $new_strings = array(
        'Bot Checks',
        'Honeypot',
        'Submit Time',
        'Min seconds',
        'Adds a hidden decoy field to the comment form. Real users cannot see it, but automated bots that fill in all fields will trip it and be blocked.',
        'Measures how long the user took to submit the form. Bots typically submit forms instantly.',
        'Pages served from cache may use stale tokens, but they fail open and allow the comment.',
        'Leave this field empty',
        'field absent',
        'field empty',
        'honeypot filled',
        'skipped non-comment type',
        'token absent',
        'token malformed or tampered',
        'future timestamp',
        'submitted in %1$ds (min %2$ds)',
        'elapsed time acceptable',
        'token expired',
        'Form Integrations',
        'Contact Form 7',
        'All enabled checks run on Contact Form 7 submissions and flagged submissions are blocked through Contact Form 7\'s own spam mechanism.',
        'Recent comments and form submissions blocked by AntiSpam Wizard (newest first, max 100).',
        'Form',
        'Submission fragment',
        'Date added to training data',
        'Training category',
        'Training sample text',
        'AntiSpam Wizard spam-log entries, flagging reasons, form submissions, and training samples were removed.',
        'AntiSpam Wizard training samples could not be removed because of a database error. Please retry this erasure request.',
        'AntiSpam Wizard analyzes comments and contact-form submissions to detect spam. When a comment is flagged, either by one of the plugin\'s checks or by a spam decision you make while moderating, the plugin stores a short fragment of the comment text, a SHA-256 hash of the commenter\'s IP address (the raw IP is never stored), and the reason the comment was flagged. When a contact-form submission is flagged, the plugin stores a short fragment of the submitted text, a SHA-256 hash of the submitter\'s IP address, and a SHA-256 hash of the submitter\'s email address (raw values are never stored). When a comment is used to train the spam filter, either from the plugin\'s Spam Log or by any Spam / Not Spam moderation decision made elsewhere in WordPress, the full text of that comment is stored as a training sample, together with a SHA-256 hash of the commenter\'s email address, until it is erased through a personal-data erasure request or the plugin is uninstalled, and it is included in personal-data exports. This data is used solely for spam detection and statistics. It is removed in response to a personal-data erasure request and when the plugin is uninstalled.',
    );

    /**
     * Positional (numbered) placeholders required by WordPress.WP.I18n.MissingTranslatorsComment
     * / UnorderedPlaceholders, mapped to the arguments and the rendering they must still produce.
     */
    private $positional_strings = array(
        array( 'mixed scripts %1$d/%2$d', array( 3, 2 ), 'mixed scripts 3/2' ),
        array( 'too many emoji %1$d/%2$d', array( 9, 4 ), 'too many emoji 9/4' ),
        array( 'emoji %1$d/%2$d', array( 1, 4 ), 'emoji 1/4' ),
        array( 'duplicate comment %1$d/%2$d', array( 5, 3 ), 'duplicate comment 5/3' ),
        array( 'duplicate count %1$d/%2$d', array( 2, 3 ), 'duplicate count 2/3' ),
        array( 'too many links %1$d/%2$d', array( 3, 2 ), 'too many links 3/2' ),
        array( 'links %1$d/%2$d', array( 1, 2 ), 'links 1/2' ),
        array( 'abnormal (ratio: %1$.2f, short: %2$.0f%%)', array( 0.05, 80 ), 'abnormal (ratio: 0.05, short: 80%)' ),
        array( 'normal (ratio: %1$.2f, short: %2$.0f%%)', array( 0.05, 80 ), 'normal (ratio: 0.05, short: 80%)' ),
        array( 'submitted in %1$ds (min %2$ds)', array( 1, 5 ), 'submitted in 1s (min 5s)' ),
    );

    public function setUp(): void {
        parent::setUp();
        $lang_dir = dirname( __DIR__ ) . '/antispam-wizard/languages';
        $this->pot_file = $lang_dir . '/antispam-wizard.pot';
        $this->po_files = glob( $lang_dir . '/*.po' );
        $this->mo_files = glob( $lang_dir . '/*.mo' );
    }

    private function clean_po_content( $content ) {
        return str_replace( array( "\n", "\r", '"' ), '', $content );
    }

    public function test_pot_contains_new_strings() {
        $this->assertFileExists( $this->pot_file );
        $pot_content = $this->clean_po_content( file_get_contents( $this->pot_file ) );
        foreach ( $this->new_strings as $str ) {
            $clean_str = str_replace( '"', '', $str );
            $this->assertStringContainsString( 'msgid ' . $clean_str, $pot_content, "POT missing: $str" );
        }
    }

    public function test_po_files_contain_translations() {
        $this->assertNotEmpty( $this->po_files );
        foreach ( $this->po_files as $po_file ) {
            $raw_content = file_get_contents( $po_file );
            $po_content = $this->clean_po_content( $raw_content );
            foreach ( $this->new_strings as $str ) {
                $clean_str = str_replace( '"', '', $str );
                $this->assertStringContainsString( 'msgid ' . $clean_str, $po_content, basename( $po_file ) . " missing msgid: $str" );

                // Ensure msgstr is not empty for these new strings
                // If empty, it's followed immediately by '#', 'msgid', or end of string
                $this->assertDoesNotMatchRegularExpression( '/msgid ' . preg_quote( $clean_str, '/' ) . 'msgstr(#|msgid|$)/', $po_content, basename( $po_file ) . " has untranslated string: $str" );

                // Ensure there is no #, fuzzy for these strings
                $this->assertDoesNotMatchRegularExpression( '/#, fuzzymsgid ' . preg_quote( $clean_str, '/' ) . '/', $po_content, basename( $po_file ) . " has fuzzy string: $str" );
            }
        }
    }

    public function test_mo_files_exist_and_not_empty() {
        $this->assertCount( count( $this->po_files ), $this->mo_files );
        foreach ( $this->mo_files as $mo_file ) {
            $this->assertFileExists( $mo_file );
            $this->assertGreaterThan( 0, filesize( $mo_file ), basename( $mo_file ) . " is empty" );
        }
    }

    /**
     * Mirrors WordPressCS' sprintf placeholder regex: a bare space is only a padding
     * specifier together with a width, so prose such as "60% or more" is not a placeholder.
     */
    const PLACEHOLDER_REGEX = '/(?<!%)%(?:(\d+)\$)?[+-]?(?:(?:0|\\\'.)?-?\d*(?:\.\d+)?)[bcdeEfFgGosuxX]/';

    /**
     * Every plugin PHP file, so the POT can be checked against the real source.
     */
    private function plugin_php_files() {
        $root  = dirname( __DIR__ ) . '/antispam-wizard';
        $files = array();
        $it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $file ) {
            if ( 'php' === strtolower( $file->getExtension() ) ) {
                $files[] = $file->getPathname();
            }
        }
        sort( $files );
        return $files;
    }

    /**
     * Extract the msgid of every gettext call in a PHP file using the tokenizer,
     * so string contents are read exactly as PHP sees them.
     *
     * @return array List of array( 'msgid' => string, 'file' => string, 'line' => int, 'has_comment' => bool ).
     */
    private function extract_gettext_calls( $file ) {
        // Keyword => index (0-based) of the msgid arguments.
        $keywords = array(
            '__'          => array( 0 ),
            '_e'          => array( 0 ),
            'esc_html__'  => array( 0 ),
            'esc_attr__'  => array( 0 ),
            'esc_html_e'  => array( 0 ),
            'esc_attr_e'  => array( 0 ),
            '_x'          => array( 0 ),
            'esc_html_x'  => array( 0 ),
            'esc_attr_x'  => array( 0 ),
            '_n'          => array( 0, 1 ),
            '_nx'         => array( 0, 1 ),
        );

        $tokens = token_get_all( file_get_contents( $file ) );
        $count  = count( $tokens );
        $found  = array();

        for ( $i = 0; $i < $count; $i++ ) {
            $token = $tokens[ $i ];
            if ( ! is_array( $token ) || T_STRING !== $token[0] || ! isset( $keywords[ $token[1] ] ) ) {
                continue;
            }

            // Skip method / static calls and declarations: ->__(), ::__(), function __().
            $prev = $this->previous_significant( $tokens, $i );
            if ( null !== $prev && is_array( $prev ) && in_array( $prev[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
                continue;
            }

            $open = $i + 1;
            while ( $open < $count && is_array( $tokens[ $open ] ) && T_WHITESPACE === $tokens[ $open ][0] ) {
                $open++;
            }
            if ( $open >= $count || '(' !== $tokens[ $open ] ) {
                continue;
            }

            $args = $this->literal_arguments( $tokens, $open );
            foreach ( $keywords[ $token[1] ] as $arg_index ) {
                if ( ! isset( $args[ $arg_index ] ) || null === $args[ $arg_index ] ) {
                    continue;
                }
                $found[] = array(
                    'msgid'       => $args[ $arg_index ],
                    'file'        => $file,
                    'line'        => $token[2],
                    'has_comment' => $this->has_translators_comment( $tokens, $i ),
                );
            }
        }

        return $found;
    }

    private function previous_significant( $tokens, $i ) {
        for ( $j = $i - 1; $j >= 0; $j-- ) {
            if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
                continue;
            }
            return $tokens[ $j ];
        }
        return null;
    }

    /**
     * A `translators:` comment must sit on the gettext call's line or the line above it.
     */
    private function has_translators_comment( $tokens, $i ) {
        $line = $tokens[ $i ][2];
        for ( $j = $i - 1; $j >= 0; $j-- ) {
            $token = $tokens[ $j ];
            if ( ! is_array( $token ) ) {
                continue;
            }
            if ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
                $end = $token[2] + substr_count( $token[1], "\n" );
                if ( $end < $line - 1 ) {
                    return false;
                }
                if ( false !== stripos( $token[1], 'translators:' ) ) {
                    return true;
                }
            }
            if ( $token[2] < $line - 1 ) {
                return false;
            }
        }
        return false;
    }

    /**
     * Collect top-level call arguments, returning the literal single-part string value
     * of each or null when the argument is not a plain string literal.
     */
    private function literal_arguments( $tokens, $open ) {
        $count = count( $tokens );
        $depth = 0;
        $args  = array();
        $parts = array();

        for ( $i = $open; $i < $count; $i++ ) {
            $token = $tokens[ $i ];
            if ( ! is_array( $token ) ) {
                if ( '(' === $token || '[' === $token ) {
                    $depth++;
                    if ( 1 === $depth ) {
                        continue;
                    }
                } elseif ( ')' === $token || ']' === $token ) {
                    $depth--;
                    if ( 0 === $depth ) {
                        $args[] = $this->collapse_parts( $parts );
                        return $args;
                    }
                } elseif ( ',' === $token && 1 === $depth ) {
                    $args[] = $this->collapse_parts( $parts );
                    $parts  = array();
                    continue;
                }
            }
            if ( $depth >= 1 ) {
                if ( is_array( $token ) && T_WHITESPACE === $token[0] ) {
                    continue;
                }
                $parts[] = $token;
            }
        }

        return $args;
    }

    private function collapse_parts( $parts ) {
        if ( 1 !== count( $parts ) ) {
            return null;
        }
        $token = $parts[0];
        if ( ! is_array( $token ) || T_CONSTANT_ENCAPSED_STRING !== $token[0] ) {
            return null;
        }
        $raw  = $token[1];
        $body = substr( $raw, 1, -1 );
        if ( "'" === $raw[0] ) {
            // Single quotes only escape \' and \\.
            return preg_replace( '/\\\\([\\\\\'])/', '$1', $body );
        }
        return stripcslashes( $body );
    }

    /**
     * Parse the msgids out of a .pot/.po file (header and obsolete entries excluded).
     */
    private function parse_catalogue_msgids( $file ) {
        $msgids  = array();
        $current = null;
        $lines   = file( $file, FILE_IGNORE_NEW_LINES );

        foreach ( $lines as $line ) {
            if ( 0 === strpos( $line, '#~' ) ) {
                $current = null;
                continue;
            }
            if ( preg_match( '/^msgid\s+"(.*)"$/', $line, $m ) ) {
                $current = $this->unescape_po_string( $m[1] );
                continue;
            }
            if ( null !== $current && preg_match( '/^"(.*)"$/', $line, $m ) ) {
                $current .= $this->unescape_po_string( $m[1] );
                continue;
            }
            if ( null !== $current ) {
                if ( '' !== $current ) {
                    $msgids[] = $current;
                }
                $current = null;
            }
        }
        if ( null !== $current && '' !== $current ) {
            $msgids[] = $current;
        }

        return $msgids;
    }

    private function unescape_po_string( $value ) {
        return stripcslashes( $value );
    }

    /**
     * Split a .po file into its entry blocks, skipping the header.
     */
    private function po_entry_blocks( $file ) {
        $blocks = preg_split( '/\n\s*\n/', trim( file_get_contents( $file ) ) );
        return array_slice( $blocks, 1 );
    }

    public function test_pot_matches_source_strings_in_both_directions() {
        $source = array();
        foreach ( $this->plugin_php_files() as $file ) {
            foreach ( $this->extract_gettext_calls( $file ) as $call ) {
                $source[ $call['msgid'] ] = true;
            }
        }
        $source = array_keys( $source );
        sort( $source );
        $this->assertNotEmpty( $source, 'No gettext calls extracted from the plugin source.' );

        $pot = array_values( array_unique( $this->parse_catalogue_msgids( $this->pot_file ) ) );
        sort( $pot );

        $this->assertSame(
            array(),
            array_values( array_diff( $source, $pot ) ),
            'Strings present in the source but missing from the POT.'
        );
        $this->assertSame(
            array(),
            array_values( array_diff( $pot, $source ) ),
            'Stale strings present in the POT but no longer in the source.'
        );
    }

    public function test_placeholder_strings_have_translators_comments() {
        $missing = array();
        foreach ( $this->plugin_php_files() as $file ) {
            foreach ( $this->extract_gettext_calls( $file ) as $call ) {
                if ( ! preg_match( self::PLACEHOLDER_REGEX, $call['msgid'] ) ) {
                    continue;
                }
                if ( ! $call['has_comment'] ) {
                    $missing[] = basename( $file ) . ':' . $call['line'] . ' — ' . $call['msgid'];
                }
            }
        }
        $this->assertSame( array(), $missing, "Placeholder strings without a translators: comment:\n" . implode( "\n", $missing ) );
    }

    public function test_placeholder_strings_are_positional() {
        $unordered = array();
        foreach ( $this->plugin_php_files() as $file ) {
            foreach ( $this->extract_gettext_calls( $file ) as $call ) {
                preg_match_all( self::PLACEHOLDER_REGEX, $call['msgid'], $m, PREG_SET_ORDER );
                if ( count( $m ) < 2 ) {
                    continue;
                }
                foreach ( $m as $match ) {
                    if ( '' === ( $match[1] ?? '' ) ) {
                        $unordered[] = basename( $file ) . ':' . $call['line'] . ' — ' . $call['msgid'];
                        break;
                    }
                }
            }
        }
        $this->assertSame( array(), $unordered, "Multi-placeholder strings must use positional placeholders:\n" . implode( "\n", $unordered ) );
    }

    public function test_positional_strings_are_in_pot_and_render_unchanged() {
        $pot = $this->parse_catalogue_msgids( $this->pot_file );
        foreach ( $this->positional_strings as $case ) {
            list( $msgid, $args, $expected ) = $case;
            $this->assertContains( $msgid, $pot, "POT missing positional msgid: $msgid" );
            $this->assertSame( $expected, vsprintf( $msgid, $args ), "Rendering changed for: $msgid" );
        }
    }

    public function test_po_files_have_no_untranslated_or_fuzzy_entries() {
        $this->assertNotEmpty( $this->po_files );
        foreach ( $this->po_files as $po_file ) {
            $name = basename( $po_file );
            foreach ( $this->po_entry_blocks( $po_file ) as $block ) {
                if ( '' === trim( $block ) ) {
                    continue;
                }
                $this->assertStringNotContainsString( '#, fuzzy', $block, "$name has a fuzzy entry:\n$block" );
                $this->assertStringNotContainsString( '#~', $block, "$name still carries obsolete entries:\n$block" );
                if ( preg_match( '/^msgstr\s+"(.*)"$/m', $block, $m ) && '' === $m[1] ) {
                    $lines = explode( "\n", $block );
                    $index = null;
                    foreach ( $lines as $i => $line ) {
                        if ( preg_match( '/^msgstr\s+""$/', $line ) ) {
                            $index = $i;
                            break;
                        }
                    }
                    $continued = null !== $index && isset( $lines[ $index + 1 ] ) && preg_match( '/^"/', $lines[ $index + 1 ] );
                    $this->assertTrue( $continued, "$name has an untranslated entry:\n$block" );
                }
            }
        }
    }

    /**
     * "Zero untranslated" only constrains the entries a catalogue actually
     * carries: a .po that simply omits a POT msgid has no empty msgstr to flag,
     * no obsolete entry, and a .mo that matches it perfectly — so every other
     * test passes while that string silently falls back to English in that
     * locale. A forgotten msgmerge on one of the nine files produces exactly
     * this state, so compare the sets in both directions.
     */
    public function test_po_files_cover_exactly_the_pot_msgids() {
        $this->assertNotEmpty( $this->po_files );
        $pot = $this->parse_catalogue_msgids( $this->pot_file );
        sort( $pot );

        foreach ( $this->po_files as $po_file ) {
            $name = basename( $po_file );
            $po   = $this->parse_catalogue_msgids( $po_file );
            sort( $po );

            $this->assertSame(
                array(),
                array_values( array_diff( $pot, $po ) ),
                "$name is missing POT msgids — run msgmerge against the current POT."
            );
            $this->assertSame(
                array(),
                array_values( array_diff( $po, $pot ) ),
                "$name carries msgids the POT does not — run msgmerge to drop them."
            );
        }
    }

    /**
     * Pair up msgid/msgstr per entry, handling continuation lines. Obsolete
     * ("#~") and comment lines are skipped; msgid_plural entries are ignored
     * because the plugin ships no plural forms.
     */
    private function po_entry_pairs( $file ) {
        $pairs = array();
        foreach ( $this->po_entry_blocks( $file ) as $block ) {
            $entry = array( 'msgid' => '', 'msgstr' => '' );
            $field = null;
            $seen  = false;
            foreach ( explode( "\n", $block ) as $line ) {
                $line = trim( $line );
                if ( '' === $line || 0 === strpos( $line, '#' ) ) {
                    continue;
                }
                if ( preg_match( '/^msgid\s+"(.*)"$/', $line, $m ) ) {
                    $field          = 'msgid';
                    $entry['msgid'] = $this->unescape_po_string( $m[1] );
                    $seen           = true;
                    continue;
                }
                if ( preg_match( '/^msgstr\s+"(.*)"$/', $line, $m ) ) {
                    $field           = 'msgstr';
                    $entry['msgstr'] = $this->unescape_po_string( $m[1] );
                    continue;
                }
                if ( preg_match( '/^msgid_plural\s/', $line ) || preg_match( '/^msgstr\[/', $line ) ) {
                    $field = null;
                    continue;
                }
                if ( null !== $field && preg_match( '/^"(.*)"$/', $line, $m ) ) {
                    $entry[ $field ] .= $this->unescape_po_string( $m[1] );
                }
            }
            if ( $seen && '' !== $entry['msgid'] ) {
                $pairs[] = $entry;
            }
        }

        return $pairs;
    }

    /**
     * Placeholders in a string, as {position, conversion type} pairs. Position 0
     * means the placeholder is non-positional (a bare %s).
     */
    private function placeholder_specs( $string ) {
        preg_match_all( self::PLACEHOLDER_REGEX, $string, $matches, PREG_SET_ORDER );
        $specs = array();
        foreach ( $matches as $match ) {
            $specs[] = array(
                'position' => ( isset( $match[1] ) && '' !== $match[1] ) ? (int) $match[1] : 0,
                'type'     => substr( $match[0], -1 ),
            );
        }

        return $specs;
    }

    /**
     * A translation that drops, adds or renumbers a placeholder is not a cosmetic
     * defect: sprintf() renders the wrong text, and a msgstr referencing an
     * argument the msgid never supplies is an ArgumentCountError at runtime. This
     * branch converted every multi-placeholder string to positional form across
     * nine catalogues, so nothing else guards that conversion.
     */
    public function test_po_translations_preserve_msgid_placeholders() {
        $this->assertNotEmpty( $this->po_files );
        foreach ( $this->po_files as $po_file ) {
            $name = basename( $po_file );
            foreach ( $this->po_entry_pairs( $po_file ) as $entry ) {
                $source = $this->placeholder_specs( $entry['msgid'] );
                if ( array() === $source || '' === $entry['msgstr'] ) {
                    // Untranslated entries are covered by their own test.
                    continue;
                }
                $translated = $this->placeholder_specs( $entry['msgstr'] );
                $context    = "$name\n  msgid:  {$entry['msgid']}\n  msgstr: {$entry['msgstr']}";

                $this->assertCount(
                    count( $source ),
                    $translated,
                    "Translation has a different number of placeholders than its msgid.\n$context"
                );

                $source_types     = array_column( $source, 'type' );
                $translated_types = array_column( $translated, 'type' );
                sort( $source_types );
                sort( $translated_types );
                $this->assertSame(
                    $source_types,
                    $translated_types,
                    "Translation uses different conversion types than its msgid.\n$context"
                );

                // sprintf() is handed exactly count($source) arguments, so a
                // higher position number is a fatal, not a rendering glitch.
                foreach ( $translated as $spec ) {
                    $this->assertLessThanOrEqual(
                        count( $source ),
                        $spec['position'],
                        "Translation references argument {$spec['position']} but only " . count( $source ) . " are supplied.\n$context"
                    );
                }

                // When the msgid is fully positional the translation must use each
                // argument exactly once — that is the entire point of positional form.
                $source_positions = array_column( $source, 'position' );
                if ( ! in_array( 0, $source_positions, true ) ) {
                    $translated_positions = array_column( $translated, 'position' );
                    sort( $translated_positions );
                    $this->assertSame(
                        range( 1, count( $source ) ),
                        $translated_positions,
                        "Positional msgid: the translation must reference each argument exactly once.\n$context"
                    );
                }
            }
        }
    }

    /**
     * The msgid => msgstr table of a compiled .mo file. Minimal reader for the
     * documented gettext binary format — enough to prove the .mo was compiled
     * from the current .po rather than left stale by a forgotten msgfmt run.
     * The translation table is read too, not just the originals: this branch
     * rewrote msgstr values across all nine catalogues without touching a single
     * msgid, which is exactly the edit a msgid-only comparison cannot see.
     */
    private function mo_entries( $file ) {
        $data = file_get_contents( $file );
        $this->assertGreaterThan( 28, strlen( $data ), basename( $file ) . ' is too short to be a .mo file.' );

        $magic = substr( $data, 0, 4 );
        if ( "\xde\x12\x04\x95" === $magic ) {
            $format = 'V'; // little-endian
        } elseif ( "\x95\x04\x12\xde" === $magic ) {
            $format = 'N'; // big-endian
        } else {
            $this->fail( basename( $file ) . ' does not carry a gettext .mo magic number.' );
        }

        $header = unpack( $format . 'revision/' . $format . 'count/' . $format . 'originals/' . $format . 'translations', substr( $data, 4, 16 ) );

        $entries = array();
        for ( $i = 0; $i < $header['count']; $i++ ) {
            $original = unpack( $format . 'length/' . $format . 'offset', substr( $data, $header['originals'] + ( $i * 8 ), 8 ) );
            $msgid    = substr( $data, $original['offset'], $original['length'] );
            if ( '' === $msgid ) {
                continue; // the header entry
            }
            $translation      = unpack( $format . 'length/' . $format . 'offset', substr( $data, $header['translations'] + ( $i * 8 ), 8 ) );
            $entries[ $msgid ] = substr( $data, $translation['offset'], $translation['length'] );
        }

        return $entries;
    }

    /**
     * filesize() > 0 cannot tell a current .mo from one compiled three string
     * changes ago — and a stale .mo means the translation silently does not apply
     * at runtime while every .po test still passes.
     */
    public function test_mo_files_are_compiled_from_the_current_po() {
        $this->assertNotEmpty( $this->po_files );
        foreach ( $this->po_files as $po_file ) {
            $mo_file = preg_replace( '/\.po$/', '.mo', $po_file );
            $this->assertFileExists( $mo_file );

            $po_entries = array();
            foreach ( $this->po_entry_pairs( $po_file ) as $entry ) {
                $po_entries[ $entry['msgid'] ] = $entry['msgstr'];
            }
            $mo_entries = $this->mo_entries( $mo_file );
            ksort( $po_entries );
            ksort( $mo_entries );

            $this->assertSame(
                $po_entries,
                $mo_entries,
                basename( $mo_file ) . ' is out of sync with its .po — recompile it with msgfmt.'
            );
        }
    }

    public function test_po_files_carry_the_positional_msgids() {
        foreach ( $this->po_files as $po_file ) {
            $msgids = $this->parse_catalogue_msgids( $po_file );
            $name   = basename( $po_file );
            foreach ( $this->positional_strings as $case ) {
                $this->assertContains( $case[0], $msgids, "$name missing positional msgid: {$case[0]}" );
            }
        }
    }
}
