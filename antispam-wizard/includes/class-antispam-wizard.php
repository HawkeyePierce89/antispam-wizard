<?php
/**
 * Main plugin class.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Bootstraps the AntiSpam Wizard plugin: registers hooks and initializes components.
 */
class ASW {

    /** @var ASW|null Singleton instance. */
    private static $instance = null;

    /** @var ASW_Sample_Repository|null */
    private $repository = null;

    /**
     * Get the singleton instance.
     *
     * @return ASW
     */
    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize the plugin.
     */
    public function init(): void {
        // Load text domain for i18n.
        add_action( 'init', array( $this, 'load_textdomain' ) );

        // Create shared Sample_Repository for DB-stored user samples.
        global $wpdb;
        $this->repository = new ASW_Sample_Repository( $wpdb );

        // Apply pending schema changes. register_activation_hook() does not fire
        // on a plugin update, so activate()'s dbDelta() alone would leave an
        // already-installed site on the old table layout -- every insert() would
        // then fail with "Unknown column". The handler gates itself on
        // manage_options: admin_init is not an admin-only hook (see
        // maybe_upgrade_schema()).
        add_action( 'admin_init', array( $this, 'maybe_upgrade_schema' ) );

        // Register form hooks for bot checks.
        $comment_form = new ASW_Comment_Form();
        $comment_form->register_hooks();

        // Register spam detection with lazy check initialization.
        // Classifiers and training data are only loaded when a comment is actually being checked,
        // avoiding heavy I/O and computation on every page request.
        $detector = new ASW_Detector( array( $this, 'build_checks' ) );
        $detector->register_hooks();

        // Register CF7 integration on plugins_loaded when CF7 is active.
        // Plugin files are all included before plugins_loaded fires, so CF7's classes are guaranteed loaded by then.
        add_action(
            'plugins_loaded',
            function () {
                if ( ASW_CF7_Integration::is_available() ) {
                    $cf7 = new ASW_CF7_Integration();
                    $cf7->register_hooks();
                }
            }
        );

        // Register WordPress Privacy API integration (exporter, eraser, policy
        // text). Not gated on is_admin(): privacy requests and policy content
        // run in admin/cron contexts and the exporter/eraser filters are
        // harmless elsewhere.
        $privacy = new ASW_Privacy();
        $privacy->register_hooks();

        // Learn from moderation decisions. Not gated on is_admin(): the comment
        // status transition it listens on is fired from the front end too (the
        // "Mark as spam" link, and any plugin that moderates outside wp-admin).
        $moderation = new ASW_Moderation();
        $moderation->register_hooks();

        // Show a flagged comment to its own author as held. Not gated on
        // is_admin(): the hooks gate themselves (front-end listings only).
        ( new ASW_Shadow_Display() )->register_hooks();

        // Initialize admin page (only on admin requests).
        if ( is_admin() ) {
            $admin_page = new ASW_Admin_Page();
            $admin_page->init();
        }
    }

    /**
     * Re-run the samples-table DDL when the stored schema version is behind.
     *
     * dbDelta() adds any missing column or index in place, so an existing table
     * is migrated without losing rows. Rows that predate a new column get its
     * default (comment_id = 0, email_hash = ''), which no Privacy API lookup can
     * match, so delete_unattributed() removes them once the layout is confirmed.
     */
    public function maybe_upgrade_schema(): void {
        if ( null === $this->repository ) {
            return;
        }

        // admin_init is not an admin-only hook: wp-admin/admin-ajax.php and
        // wp-admin/admin-post.php both fire it *before* their
        // is_user_logged_in() check, so an unauthenticated request to either
        // endpoint reaches this method. That matters only on a site whose DDL
        // the database keeps refusing, where the version option is deliberately
        // left behind so the next request retries: without this gate every
        // anonymous admin-ajax hit would re-run a full dbDelta() (which reads
        // the live schema and fires ALTERs) plus the SHOW COLUMNS probe, turning
        // a broken migration into an unauthenticated database-load amplifier.
        // manage_options is the right gate rather than a bare wp_doing_ajax()
        // check: it covers admin-post.php too, it holds on multisite subsites
        // where activate_plugins does not, and it is the capability the Spam Log
        // pill (ASW_Admin_Page::ajax_mark_comment()) already requires, so the
        // migration runs before an admin can train anything. ASW_Moderation's
        // transition hook is a second caller of insert() and is not gated on it:
        // a status change driven by cron or another plugin before any capable
        // admin_init has run will simply see insert() fail and roll its claim
        // back, and the next moderation retries once the migration has landed.
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $installed = (string) get_option( ASW_Sample_Repository::DB_VERSION_OPTION, '' );
        if ( ASW_Sample_Repository::DB_VERSION === $installed ) {
            return;
        }

        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta( $this->repository->get_create_table_sql() );

        // Only record the version once the DDL is confirmed to have landed.
        // dbDelta() swallows a failed ALTER, so stamping unconditionally would
        // turn a transient refusal (metadata lock, full disk, a host that
        // revoked ALTER) into permanent breakage: the gate above would
        // short-circuit for good while the table stayed on the old layout, so
        // every insert() would fail with `Unknown column` and the Privacy API
        // would silently export and erase nothing. Leaving the option behind
        // makes the next admin request retry.
        if ( ! $this->repository->schema_is_current() ) {
            return;
        }

        // Drop the rows the migration cannot attribute. dbDelta() adds
        // comment_id and email_hash in place, and every row written before them
        // takes their defaults (0 and ''), which neither Privacy API lookup can
        // match -- so the trained comment's full text would stay stored while an
        // export omitted it and an erasure request reported success. A refused
        // DELETE leaves the version unstamped so the next request retries,
        // exactly as a refused ALTER does.
        if ( false === $this->repository->delete_unattributed() ) {
            return;
        }

        update_option( ASW_Sample_Repository::DB_VERSION_OPTION, ASW_Sample_Repository::DB_VERSION );
    }

    /**
     * Get the Sample_Repository instance.
     *
     * @return ASW_Sample_Repository|null
     */
    public function get_repository(): ?ASW_Sample_Repository {
        return $this->repository;
    }

    /**
     * Build all spam detection checks. Called lazily on first comment check.
     *
     * Note: bot checks (Honeypot, Submit Time) are placed first so the detector
     * short-circuits on bot traffic before running CPU-heavy content checks.
     * Nothing here loads a corpus file or reads a model transient: the Bayesian
     * and Similarity checks are handed an ASW_Model_Store, which loads the one
     * writing system's unit the submission turns out to need -- and only if
     * those checks are enabled and the body is long enough to reach them.
     *
     * @param array|null $request Optional request array for bot checks (CF7 path).
     * @return ASW_Check[]
     */
    public function build_checks( ?array $request = null ): array {
        $excluded_tokens = ASW_Settings::get( 'excluded_tokens_list', array() );
        $tokenizer       = new ASW_Tokenizer( $excluded_tokens );

        $store = new ASW_Model_Store(
            $tokenizer,
            $excluded_tokens,
            new ASW_Training_Data( $this->repository ),
            $this->repository
        );

        return array(
            new ASW_Honeypot_Check( $request ),
            new ASW_Submit_Time_Check( $request ),
            new ASW_Stop_Words_Check( true ),
            new ASW_Links_Check(),
            new ASW_Emoji_Check(),
            new ASW_Spacing_Check(),
            new ASW_Multi_Lang_Check(),
            new ASW_Duplicate_Check(),
            new ASW_Bayesian_Check( $store ),
            new ASW_Similarity_Check( $tokenizer, new ASW_Cosine_Similarity(), $store ),
        );
    }

    /**
     * Load the plugin text domain for translations.
     */
    public function load_textdomain(): void {
        // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Kept deliberately: WordPress.org's automatic loading only covers directory-hosted installs, and the bundled .mo files in languages/ are the only translation source for self-hosted copies and for the dev smoke harness. Removing this call would silently drop all nine shipped translations there.
        load_plugin_textdomain( 'antispam-wizard', false, dirname( plugin_basename( ASW_FILE ) ) . '/languages' );
    }

    /**
     * Run on plugin activation.
     */
    public static function activate(): void {
        // Ensure default settings exist.
        if ( false === get_option( ASW_Settings::OPTION_KEY ) ) {
            update_option( ASW_Settings::OPTION_KEY, ASW_Settings::get_defaults() );
        }

        // Create or update the samples table.
        global $wpdb;
        $repository = new ASW_Sample_Repository( $wpdb );
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta( $repository->get_create_table_sql() );

        // Record the schema version so maybe_upgrade_schema() knows this table
        // is current and does not re-run dbDelta() on every admin request --
        // but only once the table is confirmed to carry the current layout,
        // since dbDelta() reports success even when the database refused the
        // DDL. Leaving the option unset hands the retry to
        // maybe_upgrade_schema() on the next admin request. Written as a
        // positive condition rather than an early return so a future activation
        // step added below is not silently skipped on exactly the sites whose
        // DDL was refused.
        // delete_unattributed() runs here too, not only in
        // maybe_upgrade_schema(): reactivating the plugin after an update stamps
        // the version from this path, which would otherwise leave the rows that
        // predate the two subject keys in place for good. It is a no-op on a
        // fresh install, where the table was just created empty.
        if ( $repository->schema_is_current() && false !== $repository->delete_unattributed() ) {
            update_option( ASW_Sample_Repository::DB_VERSION_OPTION, ASW_Sample_Repository::DB_VERSION );
        }
    }

    /**
     * Run on plugin deactivation.
     */
    public static function deactivate(): void {
        // Nothing to do on deactivation; cleanup happens in uninstall.php.
    }
}
