<?php
/**
 * PHPUnit bootstrap for AntiSpam Wizard tests.
 *
 * Provides WordPress function stubs so tests can run without a full WordPress installation.
 */

// Define WordPress constants used by the plugin.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
    define( 'WP_PLUGIN_DIR', ABSPATH . 'antispam-wizard/' );
}

if ( ! defined( 'AUTH_SALT' ) ) {
    define( 'AUTH_SALT', 'test-auth-salt-for-unit-tests' );
}

if ( ! defined( 'ANTISPAMWIZARD_TESTING' ) ) {
    define( 'ANTISPAMWIZARD_TESTING', true );
}

if ( ! defined( 'ANTISPAMWIZARD_PATH' ) ) {
    define( 'ANTISPAMWIZARD_PATH', dirname( __DIR__ ) . '/antispam-wizard/' );
}

if ( ! defined( 'ANTISPAMWIZARD_VERSION' ) ) {
    define( 'ANTISPAMWIZARD_VERSION', '1.0.0' );
}

if ( ! defined( 'ANTISPAMWIZARD_URL' ) ) {
    define( 'ANTISPAMWIZARD_URL', 'http://example.com/wp-content/plugins/antispam-wizard/' );
}

if ( ! defined( 'ANTISPAMWIZARD_FILE' ) ) {
    define( 'ANTISPAMWIZARD_FILE', ANTISPAMWIZARD_PATH . 'antispam-wizard.php' );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
    define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'OBJECT' ) ) {
    define( 'OBJECT', 'OBJECT' );
}

// Storage for stubbed WordPress functions.
global $wp_options, $wp_actions, $wp_filters, $wp_settings_fields, $wp_settings_sections, $wp_registered_settings, $wp_menu_pages, $wp_enqueued_styles, $wp_current_user_caps, $wp_nonces;
$wp_options            = array();
$wp_actions            = array();
$wp_filters            = array();
$wp_settings_fields    = array();
$wp_settings_sections  = array();
$wp_registered_settings = array();
$wp_menu_pages         = array();
$wp_enqueued_styles    = array();
$wp_current_user_caps  = array( 'manage_options' => true );
$wp_nonces             = array();

// Stub $wpdb for uninstall.php cleanup queries.
$wpdb = new class {
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public string $commentmeta = 'wp_commentmeta';
    public string $comments = 'wp_comments';
    public string $charset = 'utf8mb4';
    public string $collate = 'utf8mb4_unicode_ci';
    public int $insert_id = 0;
    public string $last_error = '';
    /** @var bool Mirrors wpdb::$suppress_errors, toggled by suppress_errors(). */
    public bool $suppress_errors = false;
    /**
     * @var callable|null Invoked with the query at the top of get_var(), so a
     * test can observe wpdb state (e.g. $suppress_errors) as a probe actually
     * runs rather than after it has been restored.
     */
    public $on_get_var = null;
    /**
     * @var callable|null Invoked with the query at the top of get_results(), the
     * on_get_var counterpart. ANTISPAMWIZARD_Sample_Repository::script_fingerprint() runs
     * its query inside a suppress_errors( true ) bracket restored in a finally,
     * so the flag can only be observed from inside the query itself.
     */
    public $on_get_results = null;
    /**
     * @var int|bool|null Injectable return value for insert(). null = normal
     * behavior (write the row, return 1). Set to exactly false to simulate a
     * failed insert (e.g. duplicate-key under concurrency) — the forced-failure
     * gate is a strict identity check (false === $insert_result, matching
     * delete()), so a falsy 0 does NOT force failure; it takes the write/success
     * path and returns 0. Lets failure be simulated without breaking other tests.
     */
    public $insert_result = null;
    /**
     * @var bool When an insert is forced to fail via $insert_result, controls
     * whether the row is still written to the in-memory table. true models a
     * concurrent request that won the race (the row now exists, so insert()'s
     * recheck finds it); false models a genuine DB error (no row written).
     */
    public bool $insert_writes_on_forced_failure = false;
    /**
     * @var int|bool|null Injectable return value for delete(). null = normal
     * behavior (filter matching rows, return the deleted count). Set to false to
     * simulate a DB error on delete.
     */
    public $delete_result = null;
    /**
     * @var int|bool|null Injectable return value for query(). null = normal
     * behavior (execute against the in-memory tables). Set to exactly false to
     * simulate a failed query, as the real wpdb::query() returns on error --
     * the gate is a strict identity check, matching insert()/delete(), so a
     * falsy 0 does NOT force failure.
     */
    public $query_result = null;
    /**
     * @var array|bool|null Injectable return value for get_results(). null =
     * normal behavior. Set to exactly false to simulate a DB error, which the
     * real wpdb::get_results() reports by returning the EMPTY ARRAY flush()
     * left in last_result and recording the message in last_error -- never by
     * returning null. The stub models that exactly, so a caller that must tell
     * a failure from an empty result set has to read last_error, as
     * ANTISPAMWIZARD_Sample_Repository::script_fingerprint() does.
     */
    public $get_results_result = null;
    /** @var string[] */
    public array $queries = array();
    /** @var array[] */
    public array $deletes = array();
    /** @var array<string, array<int, array<string, mixed>>> In-memory table rows keyed by table name. */
    private array $tables = array();
    /**
     * @var array<string, array<int, string>> Column names per table, as applied
     * by the dbDelta() stub. This is what SHOW COLUMNS reads, so a test can
     * model a DDL the database refused (dbDelta() reporting success while the
     * column never appeared) by keeping the columns unregistered.
     */
    private array $table_columns = array();
    /**
     * @var array<string, array<int, string>> Index names per table, as applied by
     * the dbDelta() stub. This is what SHOW INDEX reads. dbDelta() issues the ADD
     * COLUMN and the ADD KEY as separate, unchecked ALTERs, so a database can
     * accept one and refuse the other; $wp_dbdelta_index_applies models exactly
     * that half-applied migration.
     */
    private array $table_indexes = array();
    /** @var int Auto-increment counter for insert_id. */
    private int $auto_increment = 0;
    /**
     * Mirrors wpdb::suppress_errors(): sets the flag and returns the previous
     * value, so a caller can restore it. Real wpdb gates print_error()'s
     * error_log() write on this flag; the stub only records it, which is enough
     * for a test to assert the probes are bracketed.
     *
     * @param bool $suppress New value.
     * @return bool Previous value.
     */
    public function suppress_errors( $suppress = true ): bool {
        $previous              = $this->suppress_errors;
        $this->suppress_errors = (bool) $suppress;
        return $previous;
    }
    /**
     * @return int|bool Affected-row count for a DELETE executed against the
     * in-memory tables, true for every other query -- matching the real
     * wpdb::query(), which returns a row count rather than a bool.
     */
    public function query( string $sql ) {
        $this->queries[] = $sql;
        // Forced-failure path: simulate a DB error.
        if ( false === $this->query_result ) {
            return false;
        }
        // Handle UPDATE ... SET option_value = option_value + 1 for revision bumps.
        if ( preg_match( '/UPDATE\s+\S*wp_options\S*\s+SET\s+option_value\s*=\s*option_value\s*\+\s*1\s+WHERE\s+option_name\s*=\s*\'([^\']+)\'/i', $sql, $m ) ) {
            global $wp_options;
            $option_name = $m[1];
            if ( isset( $wp_options[ $option_name ] ) ) {
                $wp_options[ $option_name ] = (int) $wp_options[ $option_name ] + 1;
            }
            return true;
        }
        // Execute DELETE FROM <options> WHERE option_name LIKE '…' [OR …]
        // against the options array. wp_options is not one of the in-memory
        // tables -- it is the $wp_options global the option stubs read and
        // write -- so the generic DELETE branch below would report "no such
        // table" for it. Modelling this is what lets a test drive a caller that
        // deletes option rows by pattern (uninstall.php, and the model store's
        // sweep for unit rows no key can name).
        if ( preg_match( '/DELETE\s+FROM\s+`?' . preg_quote( $this->options, '/' ) . '`?\s/i', $sql )
            && preg_match_all( "/option_name\s+LIKE\s+'([^']*)'/i", $sql, $lm )
        ) {
            global $wp_options, $wp_transients;
            $excluded = array();
            if ( preg_match( "/option_name\s+NOT\s+IN\s*\(([^)]*)\)/i", $sql, $nm )
                && preg_match_all( "/'([^']*)'/", $nm[1], $em )
            ) {
                $excluded = $em[1];
            }
            $deleted = 0;
            foreach ( array_keys( $wp_options ) as $option_name ) {
                if ( in_array( (string) $option_name, $excluded, true ) ) {
                    continue;
                }
                foreach ( $lm[1] as $pattern ) {
                    if ( ! $this->like_matches( $pattern, (string) $option_name ) ) {
                        continue;
                    }
                    unset( $wp_options[ $option_name ] );
                    if ( str_starts_with( (string) $option_name, '_transient_' ) ) {
                        unset( $wp_transients[ substr( (string) $option_name, strlen( '_transient_' ) ) ] );
                    }
                    ++$deleted;
                    break;
                }
            }
            return $deleted;
        }
        // Execute DELETE FROM <table> WHERE ... against the in-memory table and
        // report how many rows went away, as the real wpdb::query() does.
        if ( preg_match( '/DELETE\s+FROM\s+(\S+)/i', $sql, $m ) ) {
            $table = trim( $m[1], '`\'"' );
            if ( ! isset( $this->tables[ $table ] ) ) {
                return 0;
            }
            $before   = count( $this->tables[ $table ] );
            $matching = $this->filter_rows_by_where( $this->tables[ $table ], $sql );
            $this->tables[ $table ] = array_values( array_diff_key( $this->tables[ $table ], $matching ) );
            return $before - count( $this->tables[ $table ] );
        }
        return true;
    }
    /**
     * Filter in-memory rows by a query's WHERE conditions.
     *
     * Understands `col = 'val'`, `col = 5`, `col IN ('a','b')` and
     * `col IN (5, 6)` -- bare numbers matter because prepare() renders %d
     * unquoted, exactly as WordPress does. IN lists are consumed from the query
     * first so their values can never be mistaken for equality conditions by
     * the `=` scan that follows.
     *
     * @param array<int, array<string, mixed>> $rows  Rows to filter.
     * @param string                           $query SQL to read conditions from.
     * @return array<int, array<string, mixed>> Matching rows, keys preserved.
     */
    private function filter_rows_by_where( array $rows, string $query ): array {
        $in_conditions = array();
        $remainder     = preg_replace_callback(
            "/(\w+)\s+IN\s*\(([^)]*)\)/i",
            function ( $m ) use ( &$in_conditions ) {
                preg_match_all( "/'([^']*)'|(-?\d+)/", $m[2], $vm, PREG_SET_ORDER );
                $values = array();
                foreach ( $vm as $value ) {
                    $values[] = '' !== $value[1] ? $value[1] : ( $value[2] ?? '' );
                }
                $in_conditions[] = array( $m[1], $values );
                return ' ';
            },
            $query
        );
        $eq_conditions = array();
        if ( preg_match_all( "/(\w+)\s*=\s*(?:'([^']*)'|(-?\d+))/", $remainder, $wm, PREG_SET_ORDER ) ) {
            foreach ( $wm as $cond ) {
                $eq_conditions[] = array( $cond[1], isset( $cond[3] ) ? $cond[3] : $cond[2] );
            }
        }
        if ( empty( $in_conditions ) && empty( $eq_conditions ) ) {
            return $rows;
        }
        return array_filter( $rows, function ( $row ) use ( $in_conditions, $eq_conditions ) {
            foreach ( $eq_conditions as $cond ) {
                if ( ! isset( $row[ $cond[0] ] ) || (string) $row[ $cond[0] ] !== $cond[1] ) {
                    return false;
                }
            }
            foreach ( $in_conditions as $cond ) {
                if ( ! isset( $row[ $cond[0] ] ) || ! in_array( (string) $row[ $cond[0] ], $cond[1], true ) ) {
                    return false;
                }
            }
            return true;
        } );
    }
    /**
     * Match an option name against a SQL LIKE pattern, as MySQL would.
     *
     * `%` and `_` are wildcards unless escaped by esc_like(), which backslashes
     * both -- so a pattern built the way WordPress builds one ('_transient_antispamwizard\_
     * classifiers\_%') matches the prefix literally and the suffix loosely.
     *
     * @param string $pattern LIKE pattern, esc_like() escapes included.
     * @param string $subject Option name to test.
     */
    private function like_matches( string $pattern, string $subject ): bool {
        $regex  = '';
        $length = strlen( $pattern );
        for ( $i = 0; $i < $length; $i++ ) {
            $char = $pattern[ $i ];
            if ( '\\' === $char && $i + 1 < $length ) {
                $regex .= preg_quote( $pattern[ ++$i ], '/' );
                continue;
            }
            if ( '%' === $char ) {
                $regex .= '.*';
                continue;
            }
            if ( '_' === $char ) {
                $regex .= '.';
                continue;
            }
            $regex .= preg_quote( $char, '/' );
        }
        return 1 === preg_match( '/^' . $regex . '$/s', $subject );
    }
    /** @var string[] Prepared query strings (for SQL inspection in tests). */
    public array $prepared_queries = array();
    public function prepare( string $query, ...$args ): string {
        $i      = 0;
        $result = preg_replace_callback( '/%s|%d|%i/', function ( $m ) use ( $args, &$i ) {
            $value = $args[ $i++ ] ?? '';
            if ( '%i' === $m[0] ) {
                // Identifier placeholder: WordPress renders it backtick-quoted
                // with any embedded backtick doubled.
                return '`' . str_replace( '`', '``', (string) $value ) . '`';
            }
            if ( '%d' === $m[0] ) {
                // Numeric placeholder: WordPress casts to int and emits it
                // unquoted. Rendering it quoted here would make a %s/%d mix-up
                // in plugin code invisible to every prepared_queries assertion.
                return (string) (int) $value;
            }
            return "'" . $value . "'";
        }, $query );
        $this->prepared_queries[] = $result;
        return $result;
    }
    public function esc_like( string $text ): string {
        return addcslashes( $text, '_%\\' );
    }
    public function insert( string $table, array $data, $format = null ) {
        // Forced-failure path: simulate a DB error / duplicate-key collision.
        if ( null !== $this->insert_result && false === $this->insert_result ) {
            if ( $this->insert_writes_on_forced_failure ) {
                // Model a concurrent winner: the row now exists even though our
                // own insert "failed", so the repository's recheck finds it.
                if ( ! isset( $this->tables[ $table ] ) ) {
                    $this->tables[ $table ] = array();
                }
                $this->auto_increment++;
                $this->insert_id = $this->auto_increment;
                $data['id']      = $this->insert_id;
                $this->tables[ $table ][] = $data;
            }
            return $this->insert_result;
        }
        if ( ! isset( $this->tables[ $table ] ) ) {
            $this->tables[ $table ] = array();
        }
        $this->auto_increment++;
        $this->insert_id = $this->auto_increment;
        $data['id']      = $this->insert_id;
        $this->tables[ $table ][] = $data;
        return null !== $this->insert_result ? $this->insert_result : 1;
    }
    public function delete( string $table, array $where, $format = null ) {
        $this->deletes[] = array( 'table' => $table, 'where' => $where );
        // Forced-failure path: simulate a DB error on delete.
        if ( null !== $this->delete_result && false === $this->delete_result ) {
            return false;
        }
        $deleted = 0;
        if ( isset( $this->tables[ $table ] ) ) {
            $before = count( $this->tables[ $table ] );
            $this->tables[ $table ] = array_values( array_filter(
                $this->tables[ $table ],
                function ( $row ) use ( $where ) {
                    foreach ( $where as $col => $val ) {
                        if ( ! isset( $row[ $col ] ) || $row[ $col ] !== $val ) {
                            return true; // Keep rows that don't match.
                        }
                    }
                    return false; // Remove rows that match all WHERE conditions.
                }
            ) );
            $deleted = $before - count( $this->tables[ $table ] );
        }
        return $deleted;
    }
    /**
     * Project one GROUP BY group through a SELECT list.
     *
     * Understands a plain column, COUNT(*) and MAX(<col>), each with an optional
     * AS alias -- exactly the shape ANTISPAMWIZARD_Sample_Repository::script_fingerprint()
     * emits. Counts and maxima come back as ints; real MySQL hands wpdb strings,
     * but every caller casts, so the difference is not observable.
     *
     * @param string                           $select_list Raw SELECT list.
     * @param array<int, array<string, mixed>> $rows        Rows in this group.
     * @return array<string, mixed> Column name (or alias) => value.
     */
    private function project_aggregates( string $select_list, array $rows ): array {
        $record = array();
        foreach ( explode( ',', $select_list ) as $field ) {
            $field = trim( $field );
            if ( preg_match( '/^COUNT\s*\(\s*\*\s*\)(?:\s+AS\s+(\w+))?$/i', $field, $fm ) ) {
                $record[ $fm[1] ?? 'COUNT(*)' ] = count( $rows );
                continue;
            }
            if ( preg_match( '/^MAX\s*\(\s*(\w+)\s*\)(?:\s+AS\s+(\w+))?$/i', $field, $fm ) ) {
                $values = array();
                foreach ( $rows as $row ) {
                    $values[] = (int) ( $row[ $fm[1] ] ?? 0 );
                }
                $record[ $fm[2] ?? ( 'MAX(' . $fm[1] . ')' ) ] = empty( $values ) ? null : max( $values );
                continue;
            }
            if ( preg_match( '/^(\w+)(?:\s+AS\s+(\w+))?$/', $field, $fm ) ) {
                $first = reset( $rows );
                $record[ $fm[2] ?? $fm[1] ] = is_array( $first ) ? ( $first[ $fm[1] ] ?? null ) : null;
            }
        }
        return $record;
    }
    /**
     * @return array|null Rows. A forced DB error returns an EMPTY ARRAY and sets
     * last_error, which is what the real wpdb does: query() flushes last_result
     * to array() before running and returns false on an error without touching
     * it again, and get_results() hands last_result straight back. Only the
     * no-query early return yields null, so the return type stays untyped.
     */
    public function get_results( string $query, string $output = OBJECT ) {
        if ( null !== $this->on_get_results ) {
            ( $this->on_get_results )( $query );
        }
        // Mirror wpdb::query()'s flush(): every statement clears the previous
        // one's error before it runs.
        $this->last_error = '';
        // Forced-failure path: an error is reported through last_error, and the
        // empty result set is indistinguishable from "no matching rows".
        if ( false === $this->get_results_result ) {
            $this->last_error = 'forced get_results() failure';
            return array();
        }
        // Aggregate queries: SELECT <col>, COUNT(*) AS <alias>, MAX(<col>) AS
        // <alias> FROM <table> WHERE ... [GROUP BY <col>]. Placed before the
        // generic SELECT branch, which would otherwise return the raw rows.
        // A GROUP BY group with no matching rows produces no row at all, as real
        // SQL does -- never a zero-count row -- while an *ungrouped* aggregate
        // (ANTISPAMWIZARD_Sample_Repository::table_fingerprint()) is always exactly one
        // row, zero-count included.
        if ( preg_match( '/SELECT\s+(.+?)\s+FROM\s+(\S+)/is', $query, $m )
            && preg_match( '/\b(?:COUNT|MAX)\s*\(/i', $m[1] ) ) {
            $table  = trim( $m[2], '`\'"' );
            $rows   = $this->filter_rows_by_where( $this->tables[ $table ] ?? array(), $query );
            $groups = array( $rows );
            if ( preg_match( '/\bGROUP\s+BY\s+(\w+)/i', $query, $gm ) ) {
                $groups = array();
                foreach ( $rows as $row ) {
                    $groups[ (string) ( $row[ $gm[1] ] ?? '' ) ][] = $row;
                }
            }
            $results = array();
            foreach ( $groups as $group_rows ) {
                $results[] = (object) $this->project_aggregates( $m[1], $group_rows );
            }
            return $results;
        }
        // Parse simple SELECT ... FROM table WHERE col = 'val' queries.
        if ( preg_match( '/SELECT\s+.+?\s+FROM\s+(\S+)/i', $query, $m ) ) {
            $table = trim( $m[1], '`\'"' );
            $rows  = $this->tables[ $table ] ?? array();
            // Apply WHERE conditions (equality and IN lists).
            $rows  = $this->filter_rows_by_where( $rows, $query );
            $rows  = $this->sort_rows_by_order_by( $rows, $query );
            return array_values( array_map( function ( $row ) {
                return (object) $row;
            }, $rows ) );
        }
        return array();
    }
    /**
     * Apply a trailing `ORDER BY <col> [ASC|DESC]` to a result set.
     *
     * Without this the generic SELECT branch hands rows back in insertion
     * order, which for a table built through insert() is already ascending id
     * order -- so a query's ORDER BY would look honoured whether the caller
     * wrote one or not, and ANTISPAMWIZARD_Sample_Repository::find_all()'s ordering could
     * not be tested at all. Numeric values compare as numbers (`id` is what
     * every caller orders by), everything else as strings.
     *
     * @param array<int|string, array<string, mixed>> $rows  Rows to sort.
     * @param string                                  $query SQL to read the clause from.
     * @return array<int, array<string, mixed>> Sorted rows.
     */
    private function sort_rows_by_order_by( array $rows, string $query ): array {
        if ( ! preg_match( '/\bORDER\s+BY\s+(\w+)(?:\s+(ASC|DESC))?/i', $query, $m ) ) {
            return $rows;
        }
        $column    = $m[1];
        $direction = ( isset( $m[2] ) && 0 === strcasecmp( $m[2], 'DESC' ) ) ? -1 : 1;
        $rows      = array_values( $rows );
        usort( $rows, function ( $a, $b ) use ( $column, $direction ) {
            $left  = $a[ $column ] ?? null;
            $right = $b[ $column ] ?? null;
            if ( is_numeric( $left ) && is_numeric( $right ) ) {
                return $direction * ( ( 0 + $left ) <=> ( 0 + $right ) );
            }
            return $direction * strcmp( (string) $left, (string) $right );
        } );
        return $rows;
    }
    /**
     * Replace a table's rows verbatim, ids included.
     *
     * insert() always appends with an ascending auto-increment, so a table
     * built through it can never hold rows that come back out of id order --
     * which is exactly what a SELECT carrying no ORDER BY may do on a real
     * server. Seeding the rows directly is the only way to express that shape,
     * and therefore the only way a test can prove an ORDER BY is doing work.
     *
     * The auto-increment is carried past every seeded id, as a real server's
     * is: without that a post-seed insert() would hand back an id a seeded row
     * already holds, and a test mixing the two would silently stop meaning what
     * it says.
     *
     * @param string                         $table Table name.
     * @param array<int, array<string,mixed>> $rows  Rows, in the order the table holds them.
     */
    public function seed_table_rows( string $table, array $rows ): void {
        $this->tables[ $table ] = array_values( $rows );

        foreach ( $this->tables[ $table ] as $row ) {
            if ( isset( $row['id'] ) ) {
                $this->auto_increment = max( $this->auto_increment, (int) $row['id'] );
            }
        }
    }
    /**
     * Record the columns a CREATE TABLE DDL applies, so SHOW COLUMNS can answer.
     *
     * @param string             $table   Table name.
     * @param array<int, string> $columns Column names.
     */
    public function register_table_columns( string $table, array $columns ): void {
        $existing = $this->table_columns[ $table ] ?? array();
        $this->table_columns[ $table ] = array_values( array_unique( array_merge( $existing, $columns ) ) );
    }
    /**
     * Record the index names a CREATE TABLE DDL applies, so SHOW INDEX can answer.
     *
     * @param string             $table   Table name.
     * @param array<int, string> $indexes Index (Key_name) values.
     */
    public function register_table_indexes( string $table, array $indexes ): void {
        $existing = $this->table_indexes[ $table ] ?? array();
        $this->table_indexes[ $table ] = array_values( array_unique( array_merge( $existing, $indexes ) ) );
    }
    public function get_var( string $query ) {
        if ( null !== $this->on_get_var ) {
            ( $this->on_get_var )( $query );
        }
        // Answer SHOW COLUMNS FROM <table> LIKE '<col>' from the columns the
        // dbDelta() stub applied -- the real MySQL returns the column's row (so
        // a scalar fetch yields its name) and nothing at all when it is absent.
        if ( preg_match( "/SHOW\s+COLUMNS\s+FROM\s+(\S+)\s+LIKE\s+'([^']*)'/i", $query, $m ) ) {
            $table  = trim( $m[1], '`\'"' );
            $column = str_replace( array( '\\_', '\\%' ), array( '_', '%' ), $m[2] );
            return in_array( $column, $this->table_columns[ $table ] ?? array(), true ) ? $column : null;
        }
        // Answer SHOW INDEX FROM <table> WHERE Key_name = '<index>' from the
        // indexes the dbDelta() stub applied. SHOW INDEX has no LIKE form; real
        // MySQL returns one row per index part whose first column is the table
        // name, and nothing at all when the index is absent.
        if ( preg_match( "/SHOW\s+INDEX\s+FROM\s+(\S+)\s+WHERE\s+Key_name\s*=\s*'([^']*)'/i", $query, $m ) ) {
            $table = trim( $m[1], '`\'"' );
            $index = $m[2];
            return in_array( $index, $this->table_indexes[ $table ] ?? array(), true ) ? $table : null;
        }
        // Parse SELECT COUNT(*) FROM table WHERE ... queries.
        if ( preg_match( '/SELECT\s+COUNT\s*\(\s*\*\s*\)\s+FROM\s+(\S+)/i', $query, $m ) ) {
            $table = trim( $m[1], '`\'"' );
            $rows  = $this->tables[ $table ] ?? array();
            $rows  = $this->filter_rows_by_where( $rows, $query );
            return count( $rows );
        }
        // Generic scalar: return first column of first row.
        $results = $this->get_results( $query );
        if ( ! empty( $results ) ) {
            $arr = (array) $results[0];
            return reset( $arr );
        }
        return null;
    }
    /** Reset in-memory tables (useful in test setUp). */
    public function reset_tables(): void {
        // Also clear the dbDelta() refusal switch, so a test that models a
        // database rejecting the DDL cannot leak that state into a later test
        // under reordering or --random-order.
        global $wp_dbdelta_applies, $wp_dbdelta_index_applies;
        $wp_dbdelta_applies       = true;
        $wp_dbdelta_index_applies = true;
        $this->tables         = array();
        $this->table_columns  = array();
        $this->table_indexes  = array();
        $this->auto_increment = 0;
        $this->insert_id      = 0;
        $this->last_error     = '';
        $this->insert_result  = null;
        $this->insert_writes_on_forced_failure = false;
        $this->delete_result  = null;
        $this->query_result       = null;
        $this->get_results_result = null;
        $this->suppress_errors    = false;
        $this->on_get_var         = null;
        $this->on_get_results     = null;
        $this->queries           = array();
        $this->deletes           = array();
        $this->prepared_queries  = array();
        $this->charset           = 'utf8mb4';
        $this->collate           = 'utf8mb4_unicode_ci';
    }
};

// dbDelta stub (used by plugin activation to create tables).
if ( ! function_exists( 'dbDelta' ) ) {
    function dbDelta( $queries = '', $execute = true ) {
        // Record every DDL handed to dbDelta so tests can assert that the
        // schema-upgrade path actually ran (and that it did not run twice).
        global $wp_dbdelta_queries, $wp_dbdelta_applies, $wp_dbdelta_index_applies, $wpdb;
        if ( ! is_array( $wp_dbdelta_queries ) ) {
            $wp_dbdelta_queries = array();
        }
        $wp_dbdelta_queries[] = $queries;

        // Apply the DDL's columns so SHOW COLUMNS can see them. Set
        // $wp_dbdelta_applies to false to model a database that refused the
        // statement: the real dbDelta() fires each CREATE/ALTER without
        // checking the result, so it returns just as it does here while the
        // column never appears.
        if ( false !== $wp_dbdelta_applies
            && is_string( $queries )
            && preg_match( '/CREATE TABLE\s+(\S+)\s*\((.*)\)[^)]*$/is', $queries, $m )
        ) {
            $columns = array();
            $indexes = array();
            foreach ( explode( ',', $m[2] ) as $line ) {
                // Named index definitions: [UNIQUE|FULLTEXT] KEY <name> (cols).
                // PRIMARY KEY has no name of its own and is never probed.
                if ( preg_match( '/^\s*(?:UNIQUE\s+|FULLTEXT\s+)?(?:KEY|INDEX)\s+(\w+)\s*\(/i', $line, $idx ) ) {
                    $indexes[] = $idx[1];
                    continue;
                }
                if ( preg_match( '/^\s*(\w+)\s+\w/', $line, $col )
                    && ! in_array( strtoupper( $col[1] ), array( 'PRIMARY', 'UNIQUE', 'KEY', 'INDEX', 'FULLTEXT' ), true )
                ) {
                    $columns[] = $col[1];
                }
            }
            $wpdb->register_table_columns( trim( $m[1], '`\'"' ), $columns );
            // A database can accept the ADD COLUMN and refuse the ADD KEY:
            // dbDelta() fires them as separate statements and checks neither.
            if ( false !== $wp_dbdelta_index_applies ) {
                $wpdb->register_table_indexes( trim( $m[1], '`\'"' ), $indexes );
            }
        }

        return array();
    }
}

// --- WordPress function stubs ---

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $option, $default = false ) {
        global $wp_options;
        return isset( $wp_options[ $option ] ) ? $wp_options[ $option ] : $default;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    /**
     * Set $wp_update_option_fails to true, or to an array of option names, to model
     * a refused options-table write: nothing is stored and false is returned, the
     * way real update_option() reports a $wpdb failure.
     */
    function update_option( $option, $value, $autoload = null ) {
        global $wp_options, $wp_update_option_fails;
        if ( ! empty( $wp_update_option_fails )
            && ( true === $wp_update_option_fails || in_array( $option, (array) $wp_update_option_fails, true ) )
        ) {
            return false;
        }
        $wp_options[ $option ] = $value;
        return true;
    }
}

if ( ! function_exists( 'add_option' ) ) {
    function add_option( $option, $value = '', $deprecated = '', $autoload = 'yes' ) {
        global $wp_options;
        if ( isset( $wp_options[ $option ] ) ) {
            return false;
        }
        $wp_options[ $option ] = $value;
        return true;
    }
}

if ( ! function_exists( 'delete_option' ) ) {
    function delete_option( $option ) {
        global $wp_options;
        unset( $wp_options[ $option ] );
        return true;
    }
}

if ( ! function_exists( 'is_admin' ) ) {
    function is_admin() {
        global $wp_is_admin;
        return isset( $wp_is_admin ) ? $wp_is_admin : true;
    }
}

global $wp_is_singular;
global $wp_comments_open;
global $wp_is_feed;
$wp_is_feed = false;

if ( ! function_exists( 'is_feed' ) ) {
    function is_feed() {
        global $wp_is_feed;
        return isset( $wp_is_feed ) ? (bool) $wp_is_feed : false;
    }
}

if ( ! function_exists( 'is_singular' ) ) {
    function is_singular() {
        global $wp_is_singular;
        return isset( $wp_is_singular ) ? $wp_is_singular : true;
    }
}

if ( ! function_exists( 'comments_open' ) ) {
    function comments_open() {
        global $wp_comments_open;
        return isset( $wp_comments_open ) ? $wp_comments_open : true;
    }
}

if ( ! function_exists( 'get_the_ID' ) ) {
    function get_the_ID() {
        global $wp_the_id;
        return isset( $wp_the_id ) ? $wp_the_id : 0;
    }
}

if ( ! function_exists( 'add_action' ) ) {
    function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
        global $wp_actions;
        $wp_actions[ $tag ][] = array(
            'callback'      => $callback,
            'priority'       => $priority,
            'accepted_args'  => $accepted_args,
        );
    }
}

if ( ! function_exists( 'remove_action' ) ) {
    function remove_action( $tag, $callback, $priority = 10 ) {
        global $wp_actions;
        if ( isset( $wp_actions[ $tag ] ) ) {
            foreach ( $wp_actions[ $tag ] as $index => $action ) {
                if ( $action['callback'] === $callback && $action['priority'] === $priority ) {
                    unset( $wp_actions[ $tag ][ $index ] );
                    return true;
                }
            }
        }
        return false;
    }
}

if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
        global $wp_filters;
        $wp_filters[ $tag ][] = array(
            'callback'      => $callback,
            'priority'       => $priority,
            'accepted_args'  => $accepted_args,
        );
    }
}

if ( ! function_exists( 'register_activation_hook' ) ) {
    function register_activation_hook( $file, $callback ) {
        add_action( 'activate_' . plugin_basename( $file ), $callback );
    }
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
    function register_deactivation_hook( $file, $callback ) {
        add_action( 'deactivate_' . plugin_basename( $file ), $callback );
    }
}

if ( ! function_exists( 'plugin_basename' ) ) {
    function plugin_basename( $file ) {
        return basename( dirname( $file ) ) . '/' . basename( $file );
    }
}

if ( ! function_exists( 'register_setting' ) ) {
    function register_setting( $option_group, $option_name, $args = array() ) {
        global $wp_registered_settings;
        $wp_registered_settings[ $option_name ] = array(
            'group' => $option_group,
            'args'  => $args,
        );
    }
}

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) {
        return $text;
    }
}

if ( ! function_exists( '_e' ) ) {
    function _e( $text, $domain = 'default' ) {
        echo $text;
    }
}

if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( $text, $domain = 'default' ) {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_html_e' ) ) {
    function esc_html_e( $text, $domain = 'default' ) {
        echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_attr_e' ) ) {
    function esc_attr_e( $text, $domain = 'default' ) {
        echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( $text ) {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) {
        return trim( strip_tags( $str ) );
    }
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $string ) {
        return trim( strip_tags( $string ) );
    }
}

if ( ! function_exists( 'wp_unslash' ) ) {
    function wp_unslash( $value ) {
        return is_string( $value ) ? stripslashes( $value ) : $value;
    }
}

if ( ! function_exists( 'nocache_headers' ) ) {
    function nocache_headers() {
        // Stub: no-op in tests.
    }
}

if ( ! function_exists( 'wp_hash' ) ) {
    /**
     * Core's wp_hash(): an MD5 HMAC of $data keyed with wp_salt( $scheme ).
     */
    function wp_hash( $data, $scheme = 'auth' ) {
        return hash_hmac( 'md5', $data, wp_salt( $scheme ) );
    }
}

if ( ! function_exists( 'absint' ) ) {
    function absint( $maybeint ) {
        return abs( (int) $maybeint );
    }
}

if ( ! function_exists( 'wp_salt' ) ) {
    function wp_salt( $scheme = 'auth' ) {
        $constants = array(
            'auth'        => 'AUTH_SALT',
            'secure_auth' => 'SECURE_AUTH_SALT',
            'logged_in'   => 'LOGGED_IN_SALT',
            'nonce'       => 'NONCE_SALT',
        );
        $key = isset( $constants[ $scheme ] ) ? $constants[ $scheme ] : 'AUTH_SALT';
        return defined( $key ) ? constant( $key ) : 'default-salt';
    }
}

if ( ! function_exists( 'is_admin' ) ) {
    function is_admin() {
        return true;
    }
}

// Comment meta storage.
global $wp_comment_meta;
$wp_comment_meta = array();

if ( ! function_exists( 'update_comment_meta' ) ) {
    function update_comment_meta( $comment_id, $meta_key, $meta_value ) {
        global $wp_comment_meta;
        // Models a refused write: the stored value stays and false is returned.
        // true refuses every write; a callable refuses the writes it returns true for.
        $fails = $GLOBALS['wp_update_comment_meta_fails'] ?? false;
        if ( is_callable( $fails ) ? $fails( $comment_id, $meta_key, $meta_value ) : ! empty( $fails ) ) {
            return false;
        }
        $wp_comment_meta[ $comment_id ][ $meta_key ] = $meta_value;
        return true;
    }
}

if ( ! function_exists( 'get_comment_meta' ) ) {
    function get_comment_meta( $comment_id, $meta_key = '', $single = false ) {
        global $wp_comment_meta;
        if ( empty( $meta_key ) ) {
            return isset( $wp_comment_meta[ $comment_id ] ) ? $wp_comment_meta[ $comment_id ] : array();
        }
        if ( ! isset( $wp_comment_meta[ $comment_id ][ $meta_key ] ) ) {
            return $single ? '' : array();
        }
        return $single ? $wp_comment_meta[ $comment_id ][ $meta_key ] : array( $wp_comment_meta[ $comment_id ][ $meta_key ] );
    }
}

if ( ! function_exists( 'delete_comment_meta' ) ) {
    function delete_comment_meta( $comment_id, $meta_key, $meta_value = '' ) {
        global $wp_comment_meta;
        // Models a refused DELETE: the row stays and false is returned -- the same
        // value core returns when there was nothing to delete.
        if ( ! empty( $GLOBALS['wp_delete_comment_meta_fails'] ) ) {
            return false;
        }
        if ( isset( $wp_comment_meta[ $comment_id ][ $meta_key ] ) ) {
            unset( $wp_comment_meta[ $comment_id ][ $meta_key ] );
            return true;
        }
        return false;
    }
}

if ( ! function_exists( 'do_action' ) ) {
    function do_action( $tag, ...$args ) {
        global $wp_actions;
        if ( isset( $wp_actions[ $tag ] ) ) {
            foreach ( $wp_actions[ $tag ] as $hook ) {
                call_user_func_array( $hook['callback'], $args );
            }
        }
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value, ...$args ) {
        global $wp_filters;
        if ( isset( $wp_filters[ $tag ] ) ) {
            foreach ( $wp_filters[ $tag ] as $hook ) {
                $all_args = array_merge( array( $value ), $args );
                $value    = call_user_func_array( $hook['callback'], $all_args );
            }
        }
        return $value;
    }
}

// Transient storage.
global $wp_transients, $wp_transient_reads, $wp_transient_delete_fails, $wp_on_set_transient;
global $wp_transient_expired;
$wp_transients = array();
/**
 * Every key get_transient() was asked for, in call order, on a hit and on a
 * miss alike. Initialised once here and reset per test -- it is what lets a
 * test assert which per-script model units a request loaded, and that it
 * loaded no other.
 *
 * @var string[]
 */
$wp_transient_reads = array();

/**
 * Makes delete_transient() refuse: true for every key, or an array of key
 * names. Initialised once here and reset per test.
 *
 * @var bool|string[]
 */
$wp_transient_delete_fails = false;

/**
 * Callable run by set_transient() just before the write lands, or null. The
 * seam a test uses to model a concurrent request committing in the window
 * between a writer's pre-write check and its own set_transient().
 *
 * @var callable|null
 */
$wp_on_set_transient = null;

/**
 * Transients get_transient() must treat as expired: true for every key, or an
 * array of names. Initialised once here and reset per test.
 *
 * Real WordPress cleans an expired database-backed transient up on read --
 * delete_option() on both the value and the timeout row -- and then reports a
 * miss whether or not those deletes landed. So a `false` from get_transient()
 * is not proof that the row is gone, and combined with
 * $wp_transient_delete_fails this is what lets a test drive a caller that must
 * tell the two apart (ANTISPAMWIZARD_Model_Store::delete_units(), which reads the option
 * row directly for exactly this reason).
 *
 * @var bool|string[]
 */
$wp_transient_expired = false;

if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( $transient ) {
        global $wp_transients, $wp_options, $wp_transient_reads, $wp_transient_expired;
        $wp_transient_reads[] = $transient;

        $expired = is_array( $wp_transient_expired )
            ? in_array( $transient, $wp_transient_expired, true )
            : ! empty( $wp_transient_expired );

        if ( $expired ) {
            // Mirror WordPress: an expired transient is cleaned up on read and
            // reported as a miss -- whether or not that cleanup landed, so the
            // option row can outlive the false this returns.
            delete_transient( $transient );
            return false;
        }

        // Mirror WordPress: with an external object cache a transient lives in
        // the cache and has no option row at all, so a `_transient_` row left
        // over from before that cache was installed is invisible here -- which
        // is exactly why ANTISPAMWIZARD_Model_Store sweeps those rows by pattern.
        $option_key = '_transient_' . $transient;
        if ( ! wp_using_ext_object_cache() && isset( $wp_options[ $option_key ] ) ) {
            return $wp_options[ $option_key ];
        }
        return isset( $wp_transients[ $transient ] ) ? $wp_transients[ $transient ] : false;
    }
}

if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( $transient, $value, $expiration = 0 ) {
        global $wp_transients, $wp_options, $wp_on_set_transient, $wp_update_option_fails;

        // Seam for the concurrency tests: a callable here runs just *before*
        // the write lands, which is the only way to model another request
        // committing in the window between a writer's pre-write check and its
        // own set_transient() -- the write then happens on top of whatever that
        // other request did.
        if ( is_callable( $wp_on_set_transient ) ) {
            call_user_func( $wp_on_set_transient, $transient, $value );
        }

        // Real set_transient() stores the value through add_option()/
        // update_option() on `_transient_<name>`, so the same refusal switch
        // models a backend that would not take the write: nothing is stored and
        // false comes back. Checked after the seam above, as the real write is:
        // that is what lets a seam callback arm the refusal for the *next*
        // write rather than the one it is standing in.
        $option_key = '_transient_' . $transient;
        if ( ! wp_using_ext_object_cache()
            && ! empty( $wp_update_option_fails )
            && ( true === $wp_update_option_fails || in_array( $option_key, (array) $wp_update_option_fails, true ) )
        ) {
            return false;
        }

        if ( is_array( $value ) ) {
            $value['__ttl'] = $expiration;
        }
        $wp_transients[ $transient ] = $value;
        // Mirror WordPress: without an external object cache the value *is* an
        // option row. With one it is written to the cache only, and no option
        // row is created or updated.
        if ( ! wp_using_ext_object_cache() ) {
            $wp_options[ $option_key ] = $value;
        }

        return true;
    }
}

if ( ! function_exists( 'delete_transient' ) ) {
    // $GLOBALS['wp_transient_delete_fails'] (default unset) models a backend
    // that refused the delete: true refuses every key, an array of names
    // refuses those. The value is left in place and false is returned, which
    // is also what real WordPress returns for a transient that was not there --
    // so a caller that must tell the two apart has to read the key back.
    function delete_transient( $transient ) {
        global $wp_transients, $wp_options, $wp_transient_delete_fails;

        $refused = is_array( $wp_transient_delete_fails )
            ? in_array( $transient, $wp_transient_delete_fails, true )
            : ! empty( $wp_transient_delete_fails );

        if ( $refused ) {
            return false;
        }

        $existed = isset( $wp_transients[ $transient ] );
        unset( $wp_transients[ $transient ] );
        // With an external object cache delete_transient() only touches the
        // cache; an option row of the same name is not a transient any more and
        // is left exactly where it is.
        if ( ! wp_using_ext_object_cache() ) {
            unset( $wp_options[ '_transient_' . $transient ] );
        }

        return $existed;
    }
}

// Object cache stubs.
if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
    // Switchable via the global $wp_using_ext_object_cache flag. Defaults to
    // false so existing single-server (file-locked transient) tests behave
    // exactly as before; tests that exercise the external-object-cache path
    // set $GLOBALS['wp_using_ext_object_cache'] = true.
    function wp_using_ext_object_cache() {
        global $wp_using_ext_object_cache;
        return ! empty( $wp_using_ext_object_cache );
    }
}

if ( ! function_exists( 'wp_cache_add' ) ) {
    function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
        global $wp_transients;
        $cache_key = $group . ':' . $key;
        if ( isset( $wp_transients[ $cache_key ] ) ) {
            return false;
        }
        $wp_transients[ $cache_key ] = $data;
        return true;
    }
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
    function wp_cache_delete( $key, $group = '' ) {
        global $wp_transients, $wp_cache_deletes;
        $cache_key = $group . ':' . $key;
        unset( $wp_transients[ $cache_key ] );
        if ( ! isset( $wp_cache_deletes ) ) {
            $wp_cache_deletes = array();
        }
        $wp_cache_deletes[] = array( 'key' => $key, 'group' => $group );
        return true;
    }
}

if ( ! function_exists( 'wp_cache_incr' ) ) {
    function wp_cache_incr( $key, $offset = 1, $group = '' ) {
        global $wp_transients, $wp_cache_incr_force_false, $wp_cache_incr_evict_on_force;
        $cache_key = $group . ':' . $key;
        // Test hook: when $wp_cache_incr_force_false > 0, simulate the key being
        // evicted between wp_cache_add() and wp_cache_incr() by returning false
        // once per pending count (decrements the counter). If
        // $wp_cache_incr_evict_on_force is truthy, the key is also removed so a
        // subsequent wp_cache_add() succeeds. Defaults to unset, so existing
        // tests are unaffected.
        if ( ! empty( $wp_cache_incr_force_false ) ) {
            $wp_cache_incr_force_false--;
            if ( ! empty( $wp_cache_incr_evict_on_force ) ) {
                unset( $wp_transients[ $cache_key ] );
            }
            return false;
        }
        if ( ! isset( $wp_transients[ $cache_key ] ) ) {
            return false;
        }
        $wp_transients[ $cache_key ] += $offset;
        return $wp_transients[ $cache_key ];
    }
}

// Admin function stubs.

if ( ! function_exists( 'sanitize_title' ) ) {
    function sanitize_title( $title, $fallback_title = '', $context = 'save' ) {
        $title = strtolower( (string) $title );
        $title = preg_replace( '/[^a-z0-9_\-\s]/u', '', $title );
        $title = preg_replace( '/[\s_]+/', '-', (string) $title );
        $title = trim( (string) $title, '-' );
        return '' === $title ? (string) $fallback_title : $title;
    }
}

if ( ! function_exists( 'add_options_page' ) ) {
    function add_options_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '' ) {
        global $wp_menu_pages;
        $wp_menu_pages[ $menu_slug ] = array(
            'page_title' => $page_title,
            'menu_title' => $menu_title,
            'capability' => $capability,
            'callback'   => $callback,
        );
        return 'settings_page_' . $menu_slug;
    }
}

if ( ! function_exists( 'add_menu_page' ) ) {
    function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null ) {
        global $wp_menu_pages;
        $wp_menu_pages[ $menu_slug ] = array(
            'page_title' => $page_title,
            'menu_title' => $menu_title,
            'capability' => $capability,
            'callback'   => $callback,
            'icon_url'   => $icon_url,
            'position'   => $position,
            'type'       => 'menu',
        );
        return 'toplevel_page_' . $menu_slug;
    }
}

if ( ! function_exists( 'add_submenu_page' ) ) {
    function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ) {
        global $wp_submenu_pages, $wp_menu_pages;
        if ( ! isset( $wp_submenu_pages ) ) {
            $wp_submenu_pages = array();
        }
        $wp_submenu_pages[ $menu_slug ] = array(
            'parent_slug' => $parent_slug,
            'page_title'  => $page_title,
            'menu_title'  => $menu_title,
            'capability'  => $capability,
            'callback'    => $callback,
            'position'    => $position,
        );
        // Mirror get_plugin_page_hookname(): the prefix is $admin_page_hooks[ $parent_slug ],
        // i.e. sanitize_title() of the PARENT MENU TITLE, not the parent slug.
        $prefix = isset( $wp_menu_pages[ $parent_slug ]['menu_title'] )
            ? sanitize_title( $wp_menu_pages[ $parent_slug ]['menu_title'] )
            : $parent_slug;
        return $prefix . '_page_' . $menu_slug;
    }
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
    function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
        global $wp_enqueued_styles;
        $wp_enqueued_styles[ $handle ] = array(
            'src'  => $src,
            'deps' => $deps,
            'ver'  => $ver,
        );
    }
}

if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( $capability ) {
        global $wp_current_user_caps;
        return isset( $wp_current_user_caps[ $capability ] ) && $wp_current_user_caps[ $capability ];
    }
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
    function wp_nonce_field( $action, $name, $referer = true, $echo = true ) {
        global $wp_nonces;
        $nonce = 'nonce_' . $action;
        $wp_nonces[ $action ] = $nonce;
        $field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $nonce ) . '">';
        if ( $echo ) {
            echo $field;
        }
        return $field;
    }
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
    function wp_verify_nonce( $nonce, $action ) {
        global $wp_nonces;
        return isset( $wp_nonces[ $action ] ) && $wp_nonces[ $action ] === $nonce;
    }
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
    /**
     * Create a nonce stub. The nonce value contains a single quote so that
     * addslashes (simulating WordPress magic-quotes on $_POST) produces a
     * different string.  wp_create_nonce() returns the *slashed* value —
     * callers must wp_unslash() before passing to wp_verify_nonce().
     */
    function wp_create_nonce( $action ) {
        global $wp_nonces;
        $nonce = "nonce'" . $action;       // Raw nonce (contains a single quote).
        $wp_nonces[ $action ] = $nonce;    // Store the raw value for verification.
        return addslashes( $nonce );       // Return slashed version (simulates $_POST).
    }
}

if ( ! function_exists( 'checked' ) ) {
    function checked( $checked, $current = true, $echo = true ) {
        $result = ( (string) $checked === (string) $current ) ? ' checked="checked"' : '';
        if ( $echo ) {
            echo $result;
        }
        return $result;
    }
}

if ( ! function_exists( 'submit_button' ) ) {
    function submit_button( $text = 'Save Changes', $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = '' ) {
        echo '<input type="submit" name="' . esc_attr( $name ) . '" class="button button-' . esc_attr( $type ) . '" value="' . esc_attr( $text ) . '">';
    }
}

if ( ! function_exists( 'admin_url' ) ) {
    function admin_url( $path = '' ) {
        return 'http://example.com/wp-admin/' . ltrim( $path, '/' );
    }
}

if ( ! function_exists( 'add_query_arg' ) ) {
    /**
     * Core's add_query_arg( array $args, string $url ) for the shapes the plugin
     * uses. A '#fragment' is split off first and re-attached last; an existing
     * query string is parsed, the new keys replace equal keys in place and are
     * appended otherwise, and the pairs are joined with '&'. A bare URL keeps the
     * stub's original 'url?k=v' output, so existing callers' expectations hold.
     * Values go through http_build_query() (encoded), unlike core's build_query();
     * the plugin only ever adds integers, slugs and hex hashes.
     */
    function add_query_arg( $args, $url = '' ) {
        $fragment = '';
        $hash_at  = strpos( $url, '#' );
        if ( false !== $hash_at ) {
            $fragment = substr( $url, $hash_at );
            $url      = substr( $url, 0, $hash_at );
        }
        if ( false === strpos( $url, '?' ) ) {
            return $url . '?' . http_build_query( $args ) . $fragment;
        }
        list( $base, $query ) = explode( '?', $url, 2 );
        parse_str( $query, $existing );
        foreach ( $args as $key => $value ) {
            $existing[ $key ] = $value;
        }
        return $base . '?' . http_build_query( $existing ) . $fragment;
    }
}

if ( ! function_exists( 'wp_parse_url' ) ) {
    /**
     * Core's wp_parse_url( $url, $component ): parse_url() for the absolute URLs
     * the plugin parses (core's protocol-relative and path-only fix-ups are not
     * needed here).
     */
    function wp_parse_url( $url, $component = -1 ) {
        return parse_url( $url, $component );
    }
}

if ( ! function_exists( 'wp_parse_str' ) ) {
    /**
     * Core's wp_parse_str( $input_string, &$result ): parse_str() followed by the
     * `wp_parse_str` filter.
     */
    function wp_parse_str( $input_string, &$result ) {
        parse_str( (string) $input_string, $result );
        $result = apply_filters( 'wp_parse_str', $result );
    }
}

if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( $url ) {
        return filter_var( $url, FILTER_SANITIZE_URL );
    }
}

if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ) {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
    function plugin_dir_url( $file ) {
        return 'http://example.com/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
    }
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( $file ) {
        return dirname( $file ) . '/';
    }
}

if ( ! function_exists( 'wp_delete_file' ) ) {
    /**
     * Records every deleted path into $wp_deleted_files, then delegates to unlink().
     */
    function wp_delete_file( $file ) {
        global $wp_deleted_files;
        if ( ! is_array( $wp_deleted_files ) ) {
            $wp_deleted_files = array();
        }
        $wp_deleted_files[] = $file;
        @unlink( $file );
    }
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
    function wp_upload_dir() {
        $basedir = sys_get_temp_dir() . '/wp-uploads-test-' . md5( ABSPATH );
        return array(
            'basedir' => $basedir,
            'error'   => false,
        );
    }
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
    function wp_mkdir_p( $target ) {
        if ( is_dir( $target ) ) {
            return true;
        }
        return @mkdir( $target, 0755, true );
    }
}

if ( ! function_exists( 'load_textdomain' ) ) {
    function load_textdomain( $domain, $mofile ) {
        return true;
    }
}

if ( ! function_exists( 'load_plugin_textdomain' ) ) {
    function load_plugin_textdomain( $domain, $deprecated = false, $plugin_rel_path = false ) {
        global $antispamwizard_loaded_textdomains;
        if ( is_array( $antispamwizard_loaded_textdomains ) ) {
            $antispamwizard_loaded_textdomains[] = $domain;
        }
        return true;
    }
}

if ( ! function_exists( 'determine_locale' ) ) {
    function determine_locale() {
        return 'en_US';
    }
}

// Script enqueue storage.
global $wp_enqueued_scripts, $wp_localized_scripts, $wp_json_responses;
$wp_enqueued_scripts  = array();
$wp_localized_scripts = array();
$wp_json_responses    = array();

if ( ! function_exists( 'wp_enqueue_script' ) ) {
    function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
        global $wp_enqueued_scripts;
        $wp_enqueued_scripts[ $handle ] = array(
            'src'       => $src,
            'deps'      => $deps,
            'ver'       => $ver,
            'in_footer' => $in_footer,
        );
    }
}

if ( ! function_exists( 'wp_localize_script' ) ) {
    function wp_localize_script( $handle, $object_name, $l10n ) {
        global $wp_localized_scripts;
        $wp_localized_scripts[ $handle ] = array(
            'object_name' => $object_name,
            'data'        => $l10n,
        );
        return true;
    }
}

if ( ! function_exists( 'wp_send_json_success' ) ) {
    function wp_send_json_success( $data = null ) {
        global $wp_json_responses;
        $wp_json_responses[] = array( 'success' => true, 'data' => $data );
    }
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
    function wp_send_json_error( $data = null ) {
        global $wp_json_responses;
        $wp_json_responses[] = array( 'success' => false, 'data' => $data );
    }
}

if ( ! function_exists( 'current_time' ) ) {
    function current_time( $type ) {
        if ( 'mysql' === $type ) {
            return gmdate( 'Y-m-d H:i:s' );
        }
        return time();
    }
}

if ( ! function_exists( 'esc_attr_e' ) ) {
    function esc_attr_e( $text, $domain = 'default' ) {
        echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'date_i18n' ) ) {
    function date_i18n( $format, $timestamp = null ) {
        if ( null === $timestamp ) {
            $timestamp = time();
        }
        return gmdate( $format, (int) $timestamp );
    }
}

if ( ! function_exists( 'number_format_i18n' ) ) {
    function number_format_i18n( $number, $decimals = 0 ) {
        return number_format( (float) $number, $decimals );
    }
}

// Redirect storage for wp_safe_redirect stub.
global $wp_redirects;
$wp_redirects = array();

if ( ! function_exists( 'wp_safe_redirect' ) ) {
    function wp_safe_redirect( $location, $status = 302, $x_redirect_by = 'WordPress' ) {
        global $wp_redirects;
        $wp_redirects[] = array( 'location' => $location, 'status' => $status );
    }
}

if ( ! function_exists( 'home_url' ) ) {
    function home_url( $path = '' ) {
        return 'http://example.com' . ( '' === $path ? '' : '/' . ltrim( (string) $path, '/' ) );
    }
}

if ( ! function_exists( 'get_permalink' ) ) {
    function get_permalink( $post = 0, $leavename = false ) {
        $post_id = (int) ( is_object( $post ) ? $post->ID : $post );
        if ( $post_id <= 0 ) {
            return false;
        }
        return 'http://example.com/?p=' . $post_id;
    }
}

if ( ! function_exists( 'wp_nonce_url' ) ) {
    /**
     * Append the nonce wp_create_nonce() mints for $action to $url.
     *
     * wp_create_nonce()'s stub returns the *slashed* value (it models magic quotes
     * on $_POST); a nonce travelling in a URL is never slashed, so this reads the
     * raw value back out of the nonce store and url-encodes it, the way WordPress
     * does.
     */
    function wp_nonce_url( $url, $action = -1, $name = '_wpnonce' ) {
        global $wp_nonces;
        wp_create_nonce( $action );
        $nonce     = $wp_nonces[ $action ];
        $separator = ( false === strpos( (string) $url, '?' ) ) ? '?' : '&';
        return $url . $separator . $name . '=' . rawurlencode( $nonce );
    }
}

// Records every wp_die() call: array( 'message' => …, 'response' => int ).
global $wp_died;
$wp_died = array();

// Set true to make antispamwizard_test_set_comment_status() -- and therefore
// wp_spam_comment()/wp_unspam_comment() -- fail the way wp_update_comment() does:
// no status written, no transition fired, false returned.
global $wp_comment_status_change_fails;
$wp_comment_status_change_fails = false;

// Set true to make delete_comment_meta() refuse the DELETE (returns false, row kept).
global $wp_delete_comment_meta_fails;
$wp_delete_comment_meta_fails = false;

// Set true, or to a callable( $comment_id, $meta_key, $meta_value ) returning true
// for the writes to refuse, to make update_comment_meta() refuse the write.
global $wp_update_comment_meta_fails;
$wp_update_comment_meta_fails = false;

// Set true, or to an array of option names, to make update_option() refuse the write.
global $wp_update_option_fails;
$wp_update_option_fails = false;

/**
 * Set true to make ANTISPAMWIZARD_Model_Store believe this host has no zlib.
 *
 * A unit is stored compressed wherever gzcompress()/gzuncompress() exist and
 * as a plain array where they do not, and the store is strict on read: the
 * stored form has to match the host's capability or the row is dropped and
 * rebuilt. Neither the plain write path nor either mismatch is reachable on
 * the test image, which has zlib, so the store reads this switch behind a
 * defined( 'ANTISPAMWIZARD_TESTING' ) guard. Initialised once here and reset per test.
 *
 * @var bool
 */
global $antispamwizard_zlib_unavailable;
$antispamwizard_zlib_unavailable = false;

/**
 * Set true to make ANTISPAMWIZARD_Model_Store::wrap_unit() see a refused gzcompress().
 *
 * A zlib that is present but will not take a particular payload is not
 * reachable otherwise, and the rule it triggers is load-bearing: the store
 * writes *nothing* rather than the plain array, because unwrap_for_read() is
 * strict in both directions and would refuse that array on every later read --
 * a cold rebuild on every submission plus a wasted write and delete each time.
 * Read behind the same defined( 'ANTISPAMWIZARD_TESTING' ) guard as $antispamwizard_zlib_unavailable.
 * Initialised once here and reset per test.
 *
 * @var bool
 */
global $antispamwizard_gzcompress_fails;
$antispamwizard_gzcompress_fails = false;

/**
 * Override the per-item size ANTISPAMWIZARD_Model_Store keeps a stored unit under.
 *
 * Null leaves ANTISPAMWIZARD_Model_Store::MAX_CACHED_UNIT_BYTES (1 048 576) in force.
 * Set to a small number to drive the prune-and-reweigh loop in
 * storable_unit() over an ordinary fixture: reaching the real limit takes
 * megabytes of novel vocabulary and seconds of wall clock, so pinning the
 * mechanism on it alone would put it behind the suite's slowest test.
 * Read behind the same defined( 'ANTISPAMWIZARD_TESTING' ) guard as the two switches
 * above. Initialised once here and reset per test.
 *
 * @var int|null
 */
global $antispamwizard_max_cached_unit_bytes;
$antispamwizard_max_cached_unit_bytes = null;

/**
 * Override the bytes ANTISPAMWIZARD_Model_Store lets a stored unit inflate back to.
 *
 * Null leaves ANTISPAMWIZARD_Model_Store::MAX_INFLATED_UNIT_BYTES (64x the item limit) in
 * force. It is one value for two sides -- the ceiling inflate_unit() hands
 * gzuncompress(), and the bound storable_unit() prunes against so a unit this
 * host writes is one it can read back. That second half is an axis of its own:
 * a dictionary of long, distinct, highly repetitive tokens compresses to well
 * under the item limit while serializing to far over the ceiling, and a unit
 * accepted that way is refused, deleted and rebuilt on every later read.
 * Reaching 64 MB honestly takes that much novel vocabulary, so this switch is
 * what makes the rule testable at all. Read behind the same
 * defined( 'ANTISPAMWIZARD_TESTING' ) guard as the three switches above. Initialised once
 * here and reset per test.
 *
 * @var int|null
 */
global $antispamwizard_max_inflated_unit_bytes;
$antispamwizard_max_inflated_unit_bytes = null;

if ( ! function_exists( 'antispamwizard_test_model_store_call' ) ) {
    /**
     * Call one of ANTISPAMWIZARD_Model_Store's private static envelope helpers.
     *
     * The two helpers below go through the store's own code rather than
     * reimplementing the envelope, so a test that seeds or reads a cached unit
     * cannot drift from the format the plugin actually writes -- which is the
     * one thing a hand-rolled copy of it in the bootstrap would be sure to do.
     *
     * @param string $method Private static method name.
     * @param array  $args   Positional arguments.
     * @return mixed
     */
    function antispamwizard_test_model_store_call( string $method, array $args ) {
        // No setAccessible() call: it has had no effect since PHP 8.1 -- the
        // plugin's floor -- and is deprecated from 8.5, where it would put a
        // notice into a suite that runs with failOnWarning.
        $reflected = new ReflectionMethod( ANTISPAMWIZARD_Model_Store::class, $method );

        return $reflected->invokeArgs( null, $args );
    }
}

if ( ! function_exists( 'antispamwizard_test_wrap_unit' ) ) {
    /**
     * The value ANTISPAMWIZARD_Model_Store would store the given unit as on this host.
     *
     * Null when this host cannot store one at all -- a refused gzcompress()
     * where the envelope applies, which is $antispamwizard_gzcompress_fails above.
     *
     * @param array $unit Unit payload.
     * @return array|null
     */
    function antispamwizard_test_wrap_unit( array $unit ): ?array {
        return antispamwizard_test_model_store_call( 'wrap_unit', array( $unit ) );
    }
}

if ( ! function_exists( 'antispamwizard_test_unwrap_unit' ) ) {
    /**
     * The unit inside a stored value, read the way the serving path reads it.
     *
     * @param mixed $value Stored value.
     * @return array|null
     */
    function antispamwizard_test_unwrap_unit( $value ): ?array {
        return antispamwizard_test_model_store_call( 'unwrap_for_read', array( $value ) );
    }
}

if ( ! class_exists( 'ANTISPAMWIZARD_Test_WP_Die_Exception' ) ) {
    /**
     * Thrown by the wp_die() stub so a handler cannot run past it, the way the real
     * wp_die() ends the request. Named (not anonymous) so tests can expectException().
     */
    class ANTISPAMWIZARD_Test_WP_Die_Exception extends RuntimeException {
        public int $response;

        public function __construct( string $message, int $response ) {
            parent::__construct( $message );
            $this->response = $response;
        }
    }
}

if ( ! function_exists( 'wp_die' ) ) {
    function wp_die( $message = '', $title = '', $args = array() ) {
        global $wp_died;
        $response = isset( $args['response'] ) ? (int) $args['response'] : 0;
        $wp_died[] = array(
            'message'  => (string) $message,
            'response' => $response,
        );
        throw new ANTISPAMWIZARD_Test_WP_Die_Exception( (string) $message, $response );
    }
}

if ( ! function_exists( 'check_admin_referer' ) ) {
    /**
     * Verify the nonce carried in $_REQUEST against the stub nonce store, and
     * wp_die() on a mismatch — the same shape as core, whose failure path is
     * wp_nonce_ays() and never returns.
     */
    function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
        $nonce = isset( $_REQUEST[ $query_arg ] ) ? (string) $_REQUEST[ $query_arg ] : '';
        if ( ! wp_verify_nonce( $nonce, $action ) ) {
            wp_die( 'The link you followed has expired.', '', array( 'response' => 403 ) );
        }
        return 1;
    }
}

// Comment storage for get_comment / wp_spam_comment / wp_unspam_comment stubs.
global $wp_comments;
$wp_comments = array();

// The object cache WP_Comment::get_instance() consults before it reads the row.
// WordPress's default cache is request-local, so an entry here is a snapshot the
// current request took before another process changed the row -- exactly what a
// callback re-reading a comment inside a lock has to get past. Nothing in these
// stubs populates it (there is no write path to cache from); a test seeds it to
// model that stale snapshot, and clean_comment_cache() drops it again.
global $wp_comment_cache;
$wp_comment_cache = array();

if ( ! function_exists( 'get_comment' ) ) {
    /**
     * Core's get_comment(): an object is passed through as-is (core hands a
     * WP_Comment back unchanged), an ID is resolved cache-first and returned as
     * a fresh object per call, and either way the result runs through the
     * 'get_comment' filter. Never writes $wp_comment_cache -- there is no write
     * path to cache from, which is what lets a test prove a presentation-only
     * filter left the cached row alone.
     *
     * @param object|int|string|null $comment Comment object or ID.
     * @return object|null
     */
    function get_comment( $comment ) {
        global $wp_comments, $wp_comment_cache;
        if ( is_object( $comment ) ) {
            $_comment = $comment;
        } elseif ( isset( $wp_comment_cache[ $comment ] ) ) {
            $_comment = (object) $wp_comment_cache[ $comment ];
        } elseif ( isset( $wp_comments[ $comment ] ) ) {
            $_comment = (object) $wp_comments[ $comment ];
        } else {
            return null;
        }
        return apply_filters( 'get_comment', $_comment );
    }
}

if ( ! function_exists( 'clean_comment_cache' ) ) {
    /**
     * Drop cached comment objects, as core's clean_comment_cache() does, so the
     * next get_comment() falls through to the $wp_comments fixture standing in
     * for the database.
     */
    function clean_comment_cache( $ids ): void {
        global $wp_comment_cache;
        foreach ( (array) $ids as $id ) {
            unset( $wp_comment_cache[ $id ] );
        }
    }
}

if ( ! function_exists( 'antispamwizard_test_normalize_comment_status' ) ) {
    /**
     * Normalize a raw comment_approved value the way wp_transition_comment_status()
     * does before it fires the action: 1/approve => approved, 0/hold => unapproved,
     * spam and trash unchanged.
     */
    function antispamwizard_test_normalize_comment_status( $approved ): string {
        switch ( (string) $approved ) {
            case '1':
            case 'approve':
            case 'approved':
                return 'approved';
            case '0':
            case 'hold':
            case 'unapproved':
                return 'unapproved';
            default:
                return (string) $approved;
        }
    }
}

if ( ! function_exists( 'antispamwizard_test_set_comment_status' ) ) {
    /**
     * Write a comment's new comment_approved value and fire the transition action.
     *
     * The single path every status change in the suite goes through, so a test can
     * drive any transition (approve => spam, unapproved => spam, trash => spam,
     * spam => approved, spam => trash, spam => spam) and not just the two
     * wp_spam_comment()/wp_unspam_comment() cover. Mirrors core: the statuses handed
     * to 'transition_comment_status' are normalized, and the action does not fire at
     * all when the new status equals the old one.
     *
     * @param int    $comment_id   Comment to move.
     * @param string $new_approved Raw comment_approved value to store (1, 0, spam, trash).
     * @return bool False when no such comment exists, or when
     *              $wp_comment_status_change_fails models a refused write.
     */
    function antispamwizard_test_set_comment_status( $comment_id, $new_approved ): bool {
        global $wp_comments, $wp_comment_status_change_fails;
        if ( ! isset( $wp_comments[ $comment_id ] ) ) {
            return false;
        }

        // Models wp_update_comment() failing inside wp_spam_comment(): the status
        // is not written and no transition fires, and the caller sees false.
        if ( ! empty( $wp_comment_status_change_fails ) ) {
            return false;
        }

        $old_approved = isset( $wp_comments[ $comment_id ]['comment_approved'] )
            ? $wp_comments[ $comment_id ]['comment_approved']
            : '1';

        $wp_comments[ $comment_id ]['comment_approved'] = $new_approved;
        // wp_update_comment() cleans the comment cache after it writes the row.
        clean_comment_cache( $comment_id );

        $new_status = antispamwizard_test_normalize_comment_status( $new_approved );
        $old_status = antispamwizard_test_normalize_comment_status( $old_approved );

        if ( $new_status === $old_status ) {
            return true;
        }

        do_action( 'transition_comment_status', $new_status, $old_status, (object) $wp_comments[ $comment_id ] );

        return true;
    }
}

// wp_spam_comment()/wp_unspam_comment()/wp_untrash_comment() follow core's
// stored-status contract: wp_spam_comment() records the previous comment_approved
// in the _wp_trash_meta_status meta, and the other two restore that value --
// falling back to '0' (unapproved) when it is absent, as it is for a comment the
// plugin flagged on submission -- and drop the meta once the status is written.
if ( ! function_exists( 'wp_spam_comment' ) ) {
    function wp_spam_comment( $comment_id ) {
        global $wp_comments, $wp_comment_meta;
        $previous = isset( $wp_comments[ $comment_id ]['comment_approved'] )
            ? (string) $wp_comments[ $comment_id ]['comment_approved']
            : '1';
        if ( ! antispamwizard_test_set_comment_status( $comment_id, 'spam' ) ) {
            return false;
        }
        $wp_comment_meta[ $comment_id ]['_wp_trash_meta_status'] = $previous;
        return true;
    }
}

if ( ! function_exists( 'antispamwizard_test_restore_stored_status' ) ) {
    function antispamwizard_test_restore_stored_status( $comment_id ): bool {
        global $wp_comment_meta;
        $status = (string) ( $wp_comment_meta[ $comment_id ]['_wp_trash_meta_status'] ?? '' );
        if ( '' === $status ) {
            $status = '0';
        }
        if ( ! antispamwizard_test_set_comment_status( $comment_id, $status ) ) {
            return false;
        }
        unset( $wp_comment_meta[ $comment_id ]['_wp_trash_meta_status'] );
        return true;
    }
}

if ( ! function_exists( 'wp_unspam_comment' ) ) {
    function wp_unspam_comment( $comment_id ) {
        return antispamwizard_test_restore_stored_status( $comment_id );
    }
}

if ( ! function_exists( 'wp_untrash_comment' ) ) {
    function wp_untrash_comment( $comment_id ) {
        return antispamwizard_test_restore_stored_status( $comment_id );
    }
}

if ( ! function_exists( 'wp_get_comment_status' ) ) {
    function wp_get_comment_status( $comment_id ) {
        global $wp_comments;
        if ( ! isset( $wp_comments[ $comment_id ] ) ) {
            return false;
        }
        $status = $wp_comments[ $comment_id ]['comment_approved'];
        if ( '1' === $status || 'approve' === $status ) {
            return 'approved';
        }
        if ( 'spam' === $status ) {
            return 'spam';
        }
        if ( 'trash' === $status ) {
            return 'trash';
        }
        return 'unapproved';
    }
}

if ( ! function_exists( 'antispamwizard_test_comment_matches_status' ) ) {
    /**
     * Model WP_Comment_Query's 'status' semantics for the get_comments() stub.
     *
     * Mirrors core: default 'all' matches only held (0) / approved (1) comments
     * and EXCLUDES 'spam'/'trash'; 'any' matches every status; a specific status
     * (spam, trash, hold, approve) matches exactly. A fixture comment without an
     * explicit comment_approved is treated as approved ('1').
     */
    function antispamwizard_test_comment_matches_status( array $comment, string $status ): bool {
        if ( 'any' === $status ) {
            return true;
        }
        $approved = isset( $comment['comment_approved'] ) ? (string) $comment['comment_approved'] : '1';
        switch ( $status ) {
            case '':
            case 'all':
                return '0' === $approved || '1' === $approved;
            case 'hold':
                return '0' === $approved;
            case 'approve':
                return '1' === $approved;
            default:
                return $approved === $status;
        }
    }
}

if ( ! function_exists( 'get_comments' ) ) {
    /**
     * Minimal get_comments stub: filters $wp_comments by author_email, honors the
     * 'status' arg (WP semantics, default 'all' excludes spam/trash) and supports
     * number/paged pagination and the fields => 'ids' shape.
     */
    function get_comments( $args = array() ) {
        global $wp_comments;
        $email  = isset( $args['author_email'] ) ? $args['author_email'] : '';
        $in     = isset( $args['comment__in'] ) ? array_map( 'intval', (array) $args['comment__in'] ) : null;
        $number = isset( $args['number'] ) ? (int) $args['number'] : 0;
        $paged  = isset( $args['paged'] ) ? max( 1, (int) $args['paged'] ) : 1;
        $fields = isset( $args['fields'] ) ? $args['fields'] : '';
        $status = isset( $args['status'] ) && '' !== $args['status'] ? (string) $args['status'] : 'all';

        $matched = array();
        foreach ( $wp_comments as $id => $comment ) {
            if ( '' !== $email && ( ! isset( $comment['comment_author_email'] ) || $comment['comment_author_email'] !== $email ) ) {
                continue;
            }
            if ( ! antispamwizard_test_comment_matches_status( $comment, $status ) ) {
                continue;
            }
            if ( null !== $in && ! in_array( (int) $id, $in, true ) ) {
                continue;
            }
            $matched[] = (int) $id;
        }

        if ( $number > 0 ) {
            $offset  = ( $paged - 1 ) * $number;
            $matched = array_slice( $matched, $offset, $number );
        }

        if ( 'ids' === $fields ) {
            return $matched;
        }
        return array_map(
            static function ( $id ) {
                return get_comment( $id );
            },
            $matched
        );
    }
}

if ( ! function_exists( 'apply_filters_ref_array' ) ) {
    /**
     * Core's apply_filters_ref_array() on the same $wp_filters registry as
     * apply_filters(): callbacks run in registration order, each receives the
     * first $accepted_args members of $args with $args[0] replaced by the value
     * returned so far. A member passed as a reference (`array( &$this )`) stays
     * one, which is how WP_Comment_Query hands itself to 'comments_clauses' and
     * 'the_comments'.
     */
    function apply_filters_ref_array( $tag, $args ) {
        global $wp_filters;
        $value = $args[0];
        if ( isset( $wp_filters[ $tag ] ) ) {
            foreach ( $wp_filters[ $tag ] as $hook ) {
                $args[0] = $value;
                $value   = call_user_func_array( $hook['callback'], array_slice( $args, 0, max( 1, (int) $hook['accepted_args'] ) ) );
            }
        }
        return $value;
    }
}

if ( ! function_exists( 'do_action_ref_array' ) ) {
    /**
     * Core's do_action_ref_array() on the same $wp_actions registry as
     * do_action(): registration order, $accepted_args honoured.
     */
    function do_action_ref_array( $tag, $args ) {
        global $wp_actions;
        if ( isset( $wp_actions[ $tag ] ) ) {
            foreach ( $wp_actions[ $tag ] as $hook ) {
                call_user_func_array( $hook['callback'], array_slice( $args, 0, max( 1, (int) $hook['accepted_args'] ) ) );
            }
        }
    }
}

// Visitor identity for the comment-query layer. Initialised once here and
// reset per test through antispamwizard_test_reset_comment_query_stubs().
global $wp_current_commenter, $wp_current_commenter_throws, $wp_current_user_id, $wp_doing_ajax, $wp_doing_cron;
global $wp_comment_query_cache, $wp_comment_query_cache_keys, $wp_comment_query_log;

/**
 * Commenter cookie values wp_get_current_commenter() reports: any of
 * comment_author / comment_author_email / comment_author_url, default empty.
 *
 * @var array<string,string>
 */
$wp_current_commenter = array();

/**
 * Makes wp_get_current_commenter() throw -- the seam ANTISPAMWIZARD_Shadow_Display's
 * fail-open tests use to raise inside a comment-query callback.
 *
 * @var bool
 */
$wp_current_commenter_throws = false;

/** @var int Current user ID; 0 = logged out. */
$wp_current_user_id = 0;

/** @var bool wp_doing_ajax() return value. */
$wp_doing_ajax = false;

/** @var bool wp_doing_cron() return value. */
$wp_doing_cron = false;

/**
 * WP_Comment_Query result cache (core: the 'comment-queries' cache group),
 * cache key => comment IDs, or the count for a `count` query. A miss stores
 * the result, as core's wp_cache_add() does, so a second query whose key does
 * not change is served without its clauses being built or filtered.
 *
 * @var array<string,int[]|int>
 */
$wp_comment_query_cache = array();

/** @var string[] Every cache key a WP_Comment_Query looked up, hit or miss, in order. */
$wp_comment_query_cache_keys = array();

/** @var string[] The final, post-'comments_clauses' WHERE of every query that missed the cache. */
$wp_comment_query_log = array();

if ( ! function_exists( 'antispamwizard_test_reset_comment_query_stubs' ) ) {
    /**
     * Put every comment-query-layer switch back to its default. Called from the
     * setUp of each test that drives these stubs.
     */
    function antispamwizard_test_reset_comment_query_stubs(): void {
        $GLOBALS['wp_current_commenter']        = array();
        $GLOBALS['wp_current_commenter_throws'] = false;
        $GLOBALS['wp_current_user_id']          = 0;
        $GLOBALS['wp_doing_ajax']               = false;
        $GLOBALS['wp_doing_cron']               = false;
        $GLOBALS['wp_comment_query_cache']      = array();
        $GLOBALS['wp_comment_query_cache_keys'] = array();
        $GLOBALS['wp_comment_query_log']        = array();
    }
}

if ( ! function_exists( 'antispamwizard_test_get_sql_clauses' ) ) {
    /**
     * Read a query's protected sql_clauses, as only reflection can in core.
     */
    function antispamwizard_test_get_sql_clauses( WP_Comment_Query $query ): array {
        return ( new ReflectionProperty( WP_Comment_Query::class, 'sql_clauses' ) )->getValue( $query );
    }
}

if ( ! function_exists( 'antispamwizard_test_set_approved_clause' ) ) {
    /**
     * Write (or, with null, remove) a query's protected
     * sql_clauses['where']['approved'].
     */
    function antispamwizard_test_set_approved_clause( WP_Comment_Query $query, ?string $approved ): void {
        $property = new ReflectionProperty( WP_Comment_Query::class, 'sql_clauses' );
        $clauses  = $property->getValue( $query );
        if ( null === $approved ) {
            unset( $clauses['where']['approved'] );
        } else {
            $clauses['where']['approved'] = $approved;
        }
        $property->setValue( $query, $clauses );
    }
}

if ( ! function_exists( 'wp_parse_list' ) ) {
    /**
     * Core's wp_parse_list(): a string split on commas and whitespace, an
     * array's scalar entries.
     */
    function wp_parse_list( $input_list ) {
        if ( ! is_array( $input_list ) ) {
            return preg_split( '/[\s,]+/', (string) $input_list, -1, PREG_SPLIT_NO_EMPTY );
        }
        return array_filter( $input_list, 'is_scalar' );
    }
}

if ( ! function_exists( 'wp_get_current_commenter' ) ) {
    function wp_get_current_commenter() {
        if ( ! empty( $GLOBALS['wp_current_commenter_throws'] ) ) {
            throw new RuntimeException( 'wp_get_current_commenter stub: secret failure detail' );
        }
        $commenter = is_array( $GLOBALS['wp_current_commenter'] ?? null ) ? $GLOBALS['wp_current_commenter'] : array();
        return array_merge(
            array(
                'comment_author'       => '',
                'comment_author_email' => '',
                'comment_author_url'   => '',
            ),
            $commenter
        );
    }
}

if ( ! function_exists( 'wp_get_unapproved_comment_author_email' ) ) {
    /**
     * Core's wp_get_unapproved_comment_author_email(), line for line: a
     * $_GET['unapproved'] + $_GET['moderation-hash'] pair whose hash equals
     * wp_hash( comment_date_gmt ) of the named comment resolves that comment's
     * author email while time() is inside the ten-minute window after
     * comment_date_gmt; otherwise the commenter cookie's email. The comment's
     * status is never checked, as in core. Tests drive the window through
     * comment_date_gmt relative to time(), so there is no switch.
     */
    function wp_get_unapproved_comment_author_email() {
        $commenter_email = '';

        if ( ! empty( $_GET['unapproved'] ) && ! empty( $_GET['moderation-hash'] ) ) {
            $comment_id = (int) $_GET['unapproved'];
            $comment    = get_comment( $comment_id );

            if ( $comment && hash_equals( $_GET['moderation-hash'], wp_hash( $comment->comment_date_gmt ) ) ) {
                $comment_preview_expires = strtotime( $comment->comment_date_gmt . '+10 minutes' );

                if ( time() < $comment_preview_expires ) {
                    $commenter_email = $comment->comment_author_email;
                }
            }
        }

        if ( ! $commenter_email ) {
            $commenter       = wp_get_current_commenter();
            $commenter_email = $commenter['comment_author_email'];
        }

        return $commenter_email;
    }
}

if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id() {
        return (int) ( $GLOBALS['wp_current_user_id'] ?? 0 );
    }
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
    function is_user_logged_in() {
        return get_current_user_id() > 0;
    }
}

if ( ! function_exists( 'wp_doing_ajax' ) ) {
    function wp_doing_ajax() {
        return ! empty( $GLOBALS['wp_doing_ajax'] );
    }
}

if ( ! function_exists( 'wp_doing_cron' ) ) {
    function wp_doing_cron() {
        return ! empty( $GLOBALS['wp_doing_cron'] );
    }
}

if ( ! class_exists( 'WP_Comment_Query' ) ) {
    /**
     * WP_Comment_Query modelled on core's get_comments() order of operations
     * (WordPress 6.4 to 7.1), over the $wp_comments fixture:
     *
     *  1. parse_query() merges the vars with query_var_defaults;
     *  2. 'pre_get_comments' (ref array, receives the query);
     *  3. the cache key: md5 of the vars limited to the keys of
     *     query_var_defaults, minus `fields` -- looked up in
     *     $wp_comment_query_cache and appended to $wp_comment_query_cache_keys;
     *  on a miss only:
     *  4. sql_clauses['where'] -- 'approved' first, with core's exact
     *     include_unapproved fragments, built through $wpdb->prepare();
     *  5. 'comments_clauses' (ref array, receives the query); the final WHERE
     *     is recorded in $wp_comment_query_log;
     *  6. rows selected by evaluating the post-filter
     *     sql_clauses['where']['approved'] against each row (the other pieces
     *     -- post, parent, type -- are applied from the query vars);
     *  then, hit or miss:
     *  7. `count` returns the count, `fields => 'ids'` the IDs;
     *  8. get_comment() per ID, 'the_comments' (ref array), array_map( 'get_comment' );
     *  9. a truthy `hierarchical` fills descendants level by level through a
     *     nested WP_Comment_Query with `parent__in`, then (for 'threaded')
     *     re-fetches every comment by ID through get_comment(), as core's
     *     fill_descendants() does. The result is returned flat.
     *
     * The approved-clause matcher knows only core's shapes and the plugin's
     * shadow shape; it throws LogicException on any other fragment, and when
     * the final WHERE does not contain the post-filter approved clause (a
     * filter that rewrote one without the other). Rows are ordered by ID.
     */
    class WP_Comment_Query {
        public $query_vars         = array();
        public $query_var_defaults = array();
        // Protected in core (since 4.4), so a plugin reading it from outside
        // fails here exactly as it does on a real site.
        protected $sql_clauses     = array(
            'select'  => '',
            'from'    => '',
            'where'   => array(),
            'groupby' => '',
            'orderby' => '',
            'limits'  => '',
        );
        public $request                  = '';
        protected $filtered_where_clause = '';
        public $comments              = array();

        public function __construct( $query = '' ) {
            $this->query_var_defaults = array(
                'post_id'            => 0,
                'status'             => 'all',
                'include_unapproved' => '',
                'count'              => false,
                'fields'             => '',
                'parent'             => '',
                'parent__in'         => '',
                'hierarchical'       => false,
                'number'             => '',
                'offset'             => '',
                'type'               => '',
            );
            if ( ! empty( $query ) ) {
                $this->query( $query );
            }
        }

        public function parse_query( $query = '' ) {
            if ( empty( $query ) ) {
                $query = $this->query_vars;
            }
            $this->query_vars = array_merge( $this->query_var_defaults, (array) $query );
        }

        public function query( $query ) {
            $this->query_vars = (array) $query;
            return $this->get_comments();
        }

        public function get_comments() {
            $this->parse_query();

            do_action_ref_array( 'pre_get_comments', array( &$this ) );

            $_args = array();
            foreach ( array_keys( $this->query_var_defaults ) as $key ) {
                if ( isset( $this->query_vars[ $key ] ) ) {
                    $_args[ $key ] = $this->query_vars[ $key ];
                }
            }
            unset( $_args['fields'] );
            $cache_key = 'get_comments:' . md5( serialize( $_args ) );

            $GLOBALS['wp_comment_query_cache_keys'][] = $cache_key;
            if ( array_key_exists( $cache_key, $GLOBALS['wp_comment_query_cache'] ) ) {
                $comment_ids = $GLOBALS['wp_comment_query_cache'][ $cache_key ];
            } else {
                $comment_ids = $this->get_comment_ids();
                $GLOBALS['wp_comment_query_cache'][ $cache_key ] = $comment_ids;
            }

            if ( $this->query_vars['count'] ) {
                return (int) $comment_ids;
            }
            $comment_ids = array_map( 'intval', $comment_ids );
            if ( 'ids' === $this->query_vars['fields'] ) {
                $this->comments = $comment_ids;
                return $this->comments;
            }

            $_comments = array();
            foreach ( $comment_ids as $comment_id ) {
                $_comment = get_comment( $comment_id );
                if ( $_comment ) {
                    $_comments[] = $_comment;
                }
            }
            $_comments = apply_filters_ref_array( 'the_comments', array( $_comments, &$this ) );
            $comments  = array_map( 'get_comment', $_comments );

            if ( $this->query_vars['hierarchical'] ) {
                $comments = $this->fill_descendants( $comments );
            }

            $this->comments = $comments;
            return $this->comments;
        }

        /**
         * Build the clauses, filter them, select the matching rows.
         *
         * @return int[]|int Comment IDs, or their count for a `count` query.
         */
        protected function get_comment_ids() {
            global $wpdb, $wp_comments;

            $this->sql_clauses['where'] = array();
            $approved_clauses           = array();

            $statuses = self::parse_list( $this->query_vars['status'] );
            if ( empty( $statuses ) ) {
                $statuses = array( 'all' );
            }
            if ( ! in_array( 'any', $statuses, true ) ) {
                $status_clauses = array();
                foreach ( $statuses as $status ) {
                    switch ( $status ) {
                        case 'hold':
                            $status_clauses[] = "comment_approved = '0'";
                            break;
                        case 'approve':
                            $status_clauses[] = "comment_approved = '1'";
                            break;
                        case 'all':
                        case '':
                            $status_clauses[] = "( comment_approved = '0' OR comment_approved = '1' )";
                            break;
                        default:
                            $status_clauses[] = $wpdb->prepare( 'comment_approved = %s', $status );
                            break;
                    }
                }
                $approved_clauses[] = '( ' . implode( ' OR ', $status_clauses ) . ' )';
            }

            foreach ( self::parse_list( $this->query_vars['include_unapproved'] ) as $identifier ) {
                if ( is_numeric( $identifier ) ) {
                    $approved_clauses[] = $wpdb->prepare( "( user_id = %d AND comment_approved = '0' )", $identifier );
                } else {
                    $approved_clauses[] = $wpdb->prepare( "( comment_author_email = %s AND comment_approved = '0' )", $identifier );
                }
            }

            if ( $approved_clauses ) {
                $this->sql_clauses['where']['approved'] = 1 === count( $approved_clauses )
                    ? $approved_clauses[0]
                    : '( ' . implode( ' OR ', $approved_clauses ) . ' )';
            }

            $post_id = absint( $this->query_vars['post_id'] );
            if ( $post_id ) {
                $this->sql_clauses['where']['post_id'] = $wpdb->prepare( 'comment_post_ID = %d', $post_id );
            }

            $parent = $this->query_vars['parent'];
            if ( $this->query_vars['hierarchical'] && ! $parent ) {
                $parent = 0;
            }
            if ( '' !== $parent ) {
                $this->sql_clauses['where']['parent'] = $wpdb->prepare( 'comment_parent = %d', $parent );
            }

            $parent__in = array_map( 'intval', self::parse_list( $this->query_vars['parent__in'] ) );
            if ( $parent__in ) {
                $this->sql_clauses['where']['parent__in'] = 'comment_parent IN ( ' . implode( ',', $parent__in ) . ' )';
            }

            $types = self::parse_list( $this->query_vars['type'] );
            if ( $types ) {
                $this->sql_clauses['where']['comment_type'] = "comment_type IN ( '" . implode( "', '", $types ) . "' )";
            }

            $clauses = apply_filters_ref_array(
                'comments_clauses',
                array(
                    array(
                        'fields'  => $this->query_vars['count'] ? 'COUNT(*)' : "{$wpdb->comments}.comment_ID",
                        'join'    => '',
                        'where'   => implode( ' AND ', $this->sql_clauses['where'] ),
                        'orderby' => '',
                        'limits'  => '',
                        'groupby' => '',
                    ),
                    &$this,
                )
            );

            $where                         = isset( $clauses['where'] ) ? (string) $clauses['where'] : '';
            $this->filtered_where_clause   = $where;
            $GLOBALS['wp_comment_query_log'][] = $where;
            $this->request                 = "SELECT {$clauses['fields']} FROM {$wpdb->comments}" . ( '' !== $where ? " WHERE $where" : '' );

            $approved = $this->sql_clauses['where']['approved'] ?? null;
            if ( null !== $approved && false === strpos( $where, $approved ) ) {
                throw new LogicException( 'WP_Comment_Query stub: the final WHERE does not contain the approved clause: ' . $where );
            }

            $matched = array();
            foreach ( $wp_comments as $id => $row ) {
                $row = array_merge(
                    array(
                        'comment_ID'           => $id,
                        'comment_post_ID'      => 0,
                        'comment_parent'       => 0,
                        'comment_approved'     => '1',
                        'comment_type'         => '',
                        'comment_author_email' => '',
                        'user_id'              => 0,
                    ),
                    $row
                );
                if ( $post_id && (int) $row['comment_post_ID'] !== $post_id ) {
                    continue;
                }
                if ( '' !== $parent && (int) $row['comment_parent'] !== (int) $parent ) {
                    continue;
                }
                if ( $parent__in && ! in_array( (int) $row['comment_parent'], $parent__in, true ) ) {
                    continue;
                }
                if ( $types && ! in_array( (string) $row['comment_type'], $types, true ) ) {
                    continue;
                }
                if ( null !== $approved && ! self::clause_matches( $approved, (int) $id, $row ) ) {
                    continue;
                }
                $matched[] = (int) $id;
            }
            sort( $matched );

            if ( $this->query_vars['count'] ) {
                return count( $matched );
            }
            $number = (int) $this->query_vars['number'];
            $offset = (int) $this->query_vars['offset'];
            if ( $number > 0 || $offset > 0 ) {
                $matched = array_slice( $matched, $offset, $number > 0 ? $number : null );
            }
            return $matched;
        }

        /**
         * Core's fill_descendants(): each level's child IDs from the
         * comment-queries cache ("get_comment_child_ids:<parent>:<key>" in
         * $wp_comment_query_cache, <key> the md5 of the vars limited to the keys
         * of query_var_defaults), one nested query for the parents that missed
         * it, then every descendant -- and, for 'threaded', every comment --
         * re-fetched by ID. A cached level runs no nested query, so its
         * comments never pass through 'the_comments'.
         *
         * @param object[] $comments Top-level comments.
         * @return object[] Flat list, top level first, then each level in turn.
         */
        protected function fill_descendants( $comments ) {
            $_args = array();
            foreach ( array_keys( $this->query_var_defaults ) as $var ) {
                if ( array_key_exists( $var, $this->query_vars ) ) {
                    $_args[ $var ] = $this->query_vars[ $var ];
                }
            }
            $key = md5( serialize( $_args ) );

            $parent_ids     = array_map( 'intval', wp_list_pluck_stub( $comments, 'comment_ID' ) );
            $descendant_ids = array();
            while ( $parent_ids ) {
                $child_ids = array();
                $uncached  = array();
                foreach ( $parent_ids as $parent_id ) {
                    $cache_key = "get_comment_child_ids:$parent_id:$key";
                    if ( array_key_exists( $cache_key, $GLOBALS['wp_comment_query_cache'] ) ) {
                        $child_ids = array_merge( $child_ids, $GLOBALS['wp_comment_query_cache'][ $cache_key ] );
                    } else {
                        $uncached[] = $parent_id;
                    }
                }

                if ( $uncached ) {
                    $args = $this->query_vars;
                    foreach ( array( 'parent', 'parent__in' ) as $exclude_key ) {
                        $args[ $exclude_key ] = '';
                    }
                    $args['parent__in']   = $uncached;
                    $args['hierarchical'] = false;
                    $args['offset']       = 0;
                    $args['number']       = 0;
                    $args['count']        = false;
                    // Core keeps the parent's `fields`, so each level is a full
                    // object query that runs 'the_comments' like any other.
                    $level      = new WP_Comment_Query();
                    $parent_map = array_fill_keys( $uncached, array() );
                    foreach ( $level->query( $args ) as $level_comment ) {
                        $parent_map[ (int) ( $level_comment->comment_parent ?? 0 ) ][] = (int) $level_comment->comment_ID;
                        $child_ids[] = (int) $level_comment->comment_ID;
                    }
                    foreach ( $parent_map as $parent_id => $children ) {
                        $GLOBALS['wp_comment_query_cache'][ "get_comment_child_ids:$parent_id:$key" ] = $children;
                    }
                }

                $descendant_ids = array_merge( $descendant_ids, $child_ids );
                $parent_ids     = $child_ids;
            }

            $all_comments = $comments;
            foreach ( $descendant_ids as $descendant_id ) {
                $descendant = get_comment( $descendant_id );
                if ( $descendant ) {
                    $all_comments[] = $descendant;
                }
            }

            if ( 'threaded' !== $this->query_vars['hierarchical'] ) {
                return $all_comments;
            }
            $threaded = array();
            foreach ( $all_comments as $comment ) {
                $refetched = get_comment( $comment->comment_ID );
                if ( $refetched ) {
                    $threaded[] = $refetched;
                }
            }
            return $threaded;
        }

        /**
         * wp_parse_list(): an array as-is, a string split on commas/whitespace.
         *
         * @param mixed $list Raw list.
         * @return array
         */
        private static function parse_list( $list ): array {
            if ( is_array( $list ) ) {
                return array_values( $list );
            }
            if ( '' === (string) $list || false === $list ) {
                return array();
            }
            return preg_split( '/[\s,]+/', (string) $list, -1, PREG_SPLIT_NO_EMPTY );
        }

        /**
         * Evaluate a WHERE fragment against one row: a balanced-parenthesis
         * OR/AND splitter over the fragment shapes listed in atom_matches().
         */
        private static function clause_matches( string $clause, int $id, array $row ): bool {
            $clause = trim( preg_replace( '/\s+/', ' ', $clause ) );
            while ( self::is_wrapped( $clause ) ) {
                $clause = trim( substr( $clause, 1, -1 ) );
            }
            $or = self::split_top_level( $clause, 'OR' );
            if ( count( $or ) > 1 ) {
                foreach ( $or as $part ) {
                    if ( self::clause_matches( $part, $id, $row ) ) {
                        return true;
                    }
                }
                return false;
            }
            $and = self::split_top_level( $clause, 'AND' );
            if ( count( $and ) > 1 ) {
                foreach ( $and as $part ) {
                    if ( ! self::clause_matches( $part, $id, $row ) ) {
                        return false;
                    }
                }
                return true;
            }
            return self::atom_matches( $clause, $id, $row );
        }

        /** True when the opening parenthesis at 0 closes at the last character. */
        private static function is_wrapped( string $clause ): bool {
            if ( '' === $clause || '(' !== $clause[0] || ')' !== substr( $clause, -1 ) ) {
                return false;
            }
            $depth    = 0;
            $in_quote = false;
            $length   = strlen( $clause );
            for ( $i = 0; $i < $length; $i++ ) {
                $char = $clause[ $i ];
                if ( "'" === $char ) {
                    $in_quote = ! $in_quote;
                } elseif ( ! $in_quote && '(' === $char ) {
                    $depth++;
                } elseif ( ! $in_quote && ')' === $char ) {
                    $depth--;
                    if ( 0 === $depth && $i < $length - 1 ) {
                        return false;
                    }
                }
            }
            return 0 === $depth;
        }

        /**
         * Split on a keyword at parenthesis depth 0, outside quotes.
         *
         * @return string[]
         */
        private static function split_top_level( string $clause, string $keyword ): array {
            $parts    = array();
            $depth    = 0;
            $in_quote = false;
            $start    = 0;
            $needle   = ' ' . $keyword . ' ';
            $width    = strlen( $needle );
            $length   = strlen( $clause );
            for ( $i = 0; $i < $length; $i++ ) {
                $char = $clause[ $i ];
                if ( "'" === $char ) {
                    $in_quote = ! $in_quote;
                } elseif ( ! $in_quote && '(' === $char ) {
                    $depth++;
                } elseif ( ! $in_quote && ')' === $char ) {
                    $depth--;
                } elseif ( ! $in_quote && 0 === $depth && 0 === strcasecmp( substr( $clause, $i, $width ), $needle ) ) {
                    $parts[] = trim( substr( $clause, $start, $i - $start ) );
                    $start   = $i + $width;
                    $i       = $start - 1;
                }
            }
            $parts[] = trim( substr( $clause, $start ) );
            return $parts;
        }

        /**
         * The fragment shapes core's approved clause and the plugin's shadow
         * branch are built from. Anything else is a LogicException, so a filter
         * that writes a shape this stub cannot evaluate fails loudly instead of
         * matching nothing.
         */
        private static function atom_matches( string $atom, int $id, array $row ): bool {
            global $wp_comment_meta;
            if ( preg_match( "/^comment_approved = '([^']*)'$/", $atom, $m ) ) {
                return (string) $row['comment_approved'] === $m[1];
            }
            if ( preg_match( "/^comment_author_email = '(.*)'$/", $atom, $m ) ) {
                return (string) $row['comment_author_email'] === $m[1];
            }
            if ( preg_match( '/^user_id = (\d+)$/', $atom, $m ) ) {
                return (int) $row['user_id'] === (int) $m[1];
            }
            if ( preg_match( '/^(?:`?\w+`?\.)?comment_ID = (\d+)$/', $atom, $m ) ) {
                return $id === (int) $m[1];
            }
            if ( preg_match( '/^(?:`?\w+`?\.)?comment_ID > (\d+)$/', $atom, $m ) ) {
                return $id > (int) $m[1];
            }
            if ( preg_match( '/^comment_type IN \( ?(.*?) ?\)$/', $atom, $m ) ) {
                preg_match_all( "/'([^']*)'/", $m[1], $values );
                return in_array( (string) $row['comment_type'], $values[1], true );
            }
            if ( preg_match( "/^EXISTS \( ?SELECT 1 FROM `?wp_commentmeta`? WHERE `?(?:wp_commentmeta`?\.`?)?comment_id`? = `?wp_comments`?\.`?comment_ID`? AND `?(?:wp_commentmeta`?\.`?)?meta_key`? = '([^']+)' ?\)$/", $atom, $m ) ) {
                return isset( $wp_comment_meta[ $id ][ $m[1] ] );
            }
            throw new LogicException( 'WP_Comment_Query stub: unknown approved-clause fragment: ' . $atom );
        }
    }
}

if ( ! function_exists( 'wp_list_pluck_stub' ) ) {
    /** wp_list_pluck() for objects, under a stub-only name so no plugin code can come to depend on it. */
    function wp_list_pluck_stub( array $list, string $field ): array {
        $values = array();
        foreach ( $list as $item ) {
            $values[] = is_object( $item ) ? $item->$field : $item[ $field ];
        }
        return $values;
    }
}

global $wp_privacy_policy_content;
$wp_privacy_policy_content = array();

if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
    function wp_add_privacy_policy_content( $plugin_name, $policy_text ) {
        global $wp_privacy_policy_content;
        $wp_privacy_policy_content[] = array(
            'plugin_name' => $plugin_name,
            'policy_text' => $policy_text,
        );
    }
}

if ( ! function_exists( 'wp_kses_post' ) ) {
    function wp_kses_post( $data ) {
        return $data;
    }
}

if ( ! function_exists( 'wpautop' ) ) {
    function wpautop( $text, $br = true ) {
        return '<p>' . trim( $text ) . '</p>';
    }
}

// Minimal WP_Error stub for tests.
if ( ! function_exists( 'is_email' ) ) {
    function is_email( $email ) {
        return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
    }
}

if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
    class WPCF7_ContactForm {
        public $id_override = 0;
        /** @var array */
        public $tags_override = array();
        public static function get_current() {
            global $wp_cf7_current_form;
            if ( isset( $wp_cf7_current_form ) ) {
                return $wp_cf7_current_form;
            }
            return null;
        }
        public function id() {
            global $wp_cf7_form_id;
            if ( isset( $this->id_override ) && 0 !== $this->id_override ) {
                return (int) $this->id_override;
            }
            if ( isset( $wp_cf7_form_id ) ) {
                return (int) $wp_cf7_form_id;
            }
            return 0;
        }
        public function scan_form_tags() {
            global $wp_cf7_form_tags;
            if ( isset( $wp_cf7_form_tags ) ) {
                return $wp_cf7_form_tags;
            }
            if ( ! empty( $this->tags_override ) ) {
                return $this->tags_override;
            }
            return array();
        }
    }
}

if ( ! class_exists( 'WPCF7_FormTag' ) ) {
    class WPCF7_FormTag {
        public $name = '';
        public $basetype = '';
        public function __construct( string $name = '', string $basetype = '' ) {
            $this->name     = $name;
            $this->basetype = $basetype;
        }
    }
}

if ( ! class_exists( 'WPCF7_Submission' ) ) {
    class WPCF7_Submission {
        public static function get_instance() {
            global $wp_cf7_submission;
            if ( isset( $wp_cf7_submission ) ) {
                return $wp_cf7_submission;
            }
            return null;
        }
        public function get_contact_form() {
            global $wp_cf7_submission_form;
            if ( isset( $wp_cf7_submission_form ) ) {
                return $wp_cf7_submission_form;
            }
            return WPCF7_ContactForm::get_current();
        }
        public function get_posted_data() {
            global $wp_cf7_posted_data;
            if ( isset( $wp_cf7_posted_data ) ) {
                return $wp_cf7_posted_data;
            }
            return array();
        }
        public function get_meta( $key ) {
            global $wp_cf7_meta;
            if ( isset( $wp_cf7_meta[ $key ] ) ) {
                return $wp_cf7_meta[ $key ];
            }
            if ( 'remote_ip' === $key && isset( $GLOBALS['wp_cf7_remote_ip'] ) ) {
                return $GLOBALS['wp_cf7_remote_ip'];
            }
            return null;
        }
        public function add_spam_log( array $args ) {
            global $wp_cf7_spam_log;
            if ( ! isset( $wp_cf7_spam_log ) ) {
                $wp_cf7_spam_log = array();
            }
            $wp_cf7_spam_log[] = $args;
        }
    }
}

if ( ! function_exists( 'wp_rand' ) ) {
    function wp_rand( $min = null, $max = null ) {
        if ( null === $min ) {
            $min = 0;
        }
        if ( null === $max ) {
            $max = mt_getrandmax();
        }
        return mt_rand( $min, $max );
    }
}

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        protected $code;
        protected $message;
        public function __construct( $code = '', $message = '' ) {
            $this->code    = $code;
            $this->message = $message;
        }
        public function get_error_code() {
            return $this->code;
        }
        public function get_error_message() {
            return $this->message;
        }
    }
}

// Autoload via Composer.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Load plugin files.
require_once dirname( __DIR__ ) . '/antispam-wizard/includes/interface-check.php';
require_once dirname( __DIR__ ) . '/antispam-wizard/includes/class-check-result.php';
require_once dirname( __DIR__ ) . '/antispam-wizard/includes/class-settings.php';
require_once dirname( __DIR__ ) . '/antispam-wizard/includes/class-detector.php';
require_once dirname( __DIR__ ) . '/antispam-wizard/includes/class-spam-log.php';
require_once dirname( __DIR__ ) . '/antispam-wizard/includes/class-stats.php';
require_once dirname( __DIR__ ) . '/antispam-wizard/includes/class-tokenizer.php';
require_once dirname( __DIR__ ) . '/antispam-wizard/includes/class-cosine-similarity.php';
require_once dirname( __DIR__ ) . '/antispam-wizard/includes/class-naive-bayes.php';
require_once dirname( __DIR__ ) . '/antispam-wizard/includes/class-training-data.php';
require_once dirname( __DIR__ ) . '/antispam-wizard/includes/class-sample-repository.php';
require_once dirname( __DIR__ ) . '/antispam-wizard/includes/class-script-detector.php';
require_once dirname( __DIR__ ) . '/antispam-wizard/includes/class-model-store.php';
require_once dirname( __DIR__ ) . '/antispam-wizard/includes/class-antispam-wizard.php';
require_once dirname( __DIR__ ) . '/antispam-wizard/includes/class-privacy.php';
require_once dirname( __DIR__ ) . '/antispam-wizard/includes/class-moderation.php';
require_once dirname( __DIR__ ) . '/antispam-wizard/includes/class-shadow-display.php';
require_once dirname( __DIR__ ) . '/antispam-wizard/includes/checks/class-stop-words-check.php';
require_once dirname( __DIR__ ) . '/antispam-wizard/includes/checks/class-links-check.php';
require_once dirname( __DIR__ ) . '/antispam-wizard/includes/checks/class-emoji-check.php';
require_once dirname( __DIR__ ) . '/antispam-wizard/includes/checks/class-spacing-check.php';
require_once dirname( __DIR__ ) . '/antispam-wizard/includes/checks/class-multi-lang-check.php';
require_once dirname( __DIR__ ) . '/antispam-wizard/includes/checks/class-duplicate-check.php';
require_once dirname( __DIR__ ) . '/antispam-wizard/includes/checks/class-bayesian-check.php';
require_once dirname( __DIR__ ) . '/antispam-wizard/includes/checks/class-similarity-check.php';
require_once dirname( __DIR__ ) . '/antispam-wizard/admin/class-admin-page.php';
