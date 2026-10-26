<?php
/**
 * Admin settings page.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers and renders the AntiSpam Wizard admin settings page.
 */
class ASW_Admin_Page {

    /** The plugin's website; the Plugin URI header and readme.txt carry the same address. */
    public const WEBSITE_URL = 'https://antispamwizard.com/';

    /** The public source repository and issue tracker, linked from the Plugins screen row. */
    public const SOURCE_URL = 'https://github.com/HawkeyePierce89/antispam-wizard';

    const NONCE_ACTION = 'asw_save_settings';
    const NONCE_FIELD  = 'asw_nonce';
    const AJAX_NONCE   = 'asw_stop_words';
    const EXCLUDED_TOKENS_NONCE = 'asw_excluded_tokens';
    const MARK_NONCE   = 'asw_mark_comment';
    const IMPORT_MAX_ENTRIES = 10000;

    const SUBMENU_SLUGS = array(
        'main'            => 'antispam-wizard-main',
        'general'         => 'antispam-wizard',
        'stop-words'      => 'antispam-wizard-stop-words',
        'excluded-tokens' => 'antispam-wizard-excluded-tokens',
        'log'             => 'antispam-wizard-log',
        'stats'           => 'antispam-wizard-stats',
    );

    /**
     * Admin page hook suffixes returned by add_menu_page()/add_submenu_page().
     *
     * Populated in add_menu_page() and used to gate asset enqueueing. WordPress
     * derives submenu hook suffixes from sanitize_title() of the parent menu
     * *title* (which is translatable), not from the parent slug, so the suffixes
     * must never be hand-constructed.
     *
     * @var string[]
     */
    private array $page_hooks = array();

    /**
     * Register hooks.
     */
    public function init(): void {
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_init', array( $this, 'handle_save' ) );
        add_action( 'admin_init', array( $this, 'handle_stop_words_import' ) );
        add_action( 'admin_init', array( $this, 'handle_stop_words_export' ) );
        add_action( 'admin_init', array( $this, 'handle_excluded_tokens_import' ) );
        add_action( 'admin_init', array( $this, 'handle_excluded_tokens_export' ) );
        add_action( 'admin_init', array( $this, 'handle_clear_log' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );
        add_action( 'wp_ajax_asw_add_stop_word', array( $this, 'ajax_add_stop_word' ) );
        add_action( 'wp_ajax_asw_remove_stop_word', array( $this, 'ajax_remove_stop_word' ) );
        add_action( 'wp_ajax_asw_add_excluded_token', array( $this, 'ajax_add_excluded_token' ) );
        add_action( 'wp_ajax_asw_remove_excluded_token', array( $this, 'ajax_remove_excluded_token' ) );
        add_action( 'wp_ajax_asw_mark_comment', array( $this, 'ajax_mark_comment' ) );
    }

    /**
     * Register the top-level menu and submenu pages.
     */
    public function add_menu_page(): void {
        $this->page_hooks = array();

        $hook = add_menu_page(
            __( 'AntiSpam Wizard', 'antispam-wizard' ),
            __( 'AntiSpam Wizard', 'antispam-wizard' ),
            'manage_options',
            self::SUBMENU_SLUGS['main'],
            array( $this, 'render_page' ),
            'dashicons-shield-alt',
            80
        );

        if ( is_string( $hook ) && '' !== $hook ) {
            $this->page_hooks[] = $hook;
        }

        $submenus = array(
            'main'            => __( 'Main', 'antispam-wizard' ),
            'general'         => __( 'General', 'antispam-wizard' ),
            'stop-words'      => __( 'Stop Words', 'antispam-wizard' ),
            'excluded-tokens' => __( 'Excluded Tokens', 'antispam-wizard' ),
            'log'             => __( 'Spam Log', 'antispam-wizard' ),
            'stats'           => __( 'Statistics', 'antispam-wizard' ),
        );

        foreach ( $submenus as $tab => $label ) {
            $hook = add_submenu_page(
                self::SUBMENU_SLUGS['main'],
                // translators: %s: Submenu page label.
                sprintf( __( 'AntiSpam Wizard - %s', 'antispam-wizard' ), $label ),
                $label,
                'manage_options',
                self::SUBMENU_SLUGS[ $tab ],
                array( $this, 'render_page' )
            );

            if ( is_string( $hook ) && '' !== $hook ) {
                $this->page_hooks[] = $hook;
            }
        }
    }

    /**
     * Enqueue admin CSS and JS on the plugin settings page only.
     *
     * @param string $hook_suffix The current admin page hook suffix.
     */
    public function enqueue_assets( string $hook_suffix ): void {
        if ( ! in_array( $hook_suffix, $this->page_hooks, true ) ) {
            return;
        }
        wp_enqueue_style(
            'asw-admin',
            ASW_URL . 'admin/css/admin.css',
            array( 'dashicons' ),
            ASW_VERSION
        );
        wp_enqueue_script(
            'asw-admin',
            ASW_URL . 'admin/js/admin.js',
            array(),
            ASW_VERSION,
            true
        );
        wp_localize_script( 'asw-admin', 'aswAdmin', array(
            'ajax_url'             => admin_url( 'admin-ajax.php' ),
            'nonce'                => wp_create_nonce( self::AJAX_NONCE ),
            'excluded_tokens_nonce' => wp_create_nonce( self::EXCLUDED_TOKENS_NONCE ),
            'mark_nonce'           => wp_create_nonce( self::MARK_NONCE ),
            'remove_text'          => __( 'Remove', 'antispam-wizard' ),
            'no_words_text'        => __( 'No stop-words configured.', 'antispam-wizard' ),
            'no_tokens_text'       => __( 'No excluded tokens configured.', 'antispam-wizard' ),
            'error_text'           => __( 'An error occurred.', 'antispam-wizard' ),
            'trained_as_text'      => __( 'Trained as ', 'antispam-wizard' ),
        ) );
    }

    /**
     * Get the current active tab based on the page query parameter.
     *
     * @return string
     */
    private function get_current_tab(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection for display; the value is unslashed, sanitized and matched against a fixed slug map.
        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : self::SUBMENU_SLUGS['main'];
        $slug_to_tab = array_flip( self::SUBMENU_SLUGS );
        return isset( $slug_to_tab[ $page ] ) ? $slug_to_tab[ $page ] : 'main';
    }

    /**
     * Get the admin URL for a submenu page.
     *
     * @param string $tab Tab key (e.g. 'general', 'stop-words').
     * @return string
     */
    public static function get_submenu_url( string $tab ): string {
        $slug = isset( self::SUBMENU_SLUGS[ $tab ] ) ? self::SUBMENU_SLUGS[ $tab ] : self::SUBMENU_SLUGS['main'];
        return add_query_arg( array( 'page' => $slug ), admin_url( 'admin.php' ) );
    }

    /**
     * Adds a "Source code" link to the plugin's row on the Plugins screen, next
     * to core's "View details" and the "Visit plugin site" link (which core
     * builds from the Plugin URI header, so the website needs no link of
     * its own here). Other plugins' rows are returned untouched.
     *
     * @param array  $links       The row meta links WordPress collected so far.
     * @param string $plugin_file Plugin basename of the row being rendered.
     * @return array
     */
    public function plugin_row_meta( $links, $plugin_file ) {
        if ( ! is_array( $links ) || plugin_basename( ASW_FILE ) !== $plugin_file ) {
            return $links;
        }

        $links[] = '<a href="' . esc_url( self::SOURCE_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'Source code', 'antispam-wizard' ) . '</a>';

        return $links;
    }

    /**
     * Render the admin page for the current submenu.
     */
    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $current_tab = $this->get_current_tab();

        $brand_icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.963 2.286a.75.75 0 0 0-1.071-.136 9.742 9.742 0 0 0-3.539 6.176 7.547 7.547 0 0 1-1.705-1.715.75.75 0 0 0-1.152-.082A9 9 0 1 0 15.68 4.534a7.46 7.46 0 0 1-2.717-2.248zM12 18.75a3.75 3.75 0 0 0 1.875-7.001c.21.166.42.34.628.523a.75.75 0 0 0 1.012-.022 6.726 6.726 0 0 0 1.852-2.41 6.752 6.752 0 0 1-7.367 8.91z"/></svg>';

        echo '<div class="wrap asw-admin">';
        echo '<header class="asw-header"><div class="asw-brand">';
        echo '<span class="asw-brand-icon">' . $brand_icon . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $brand_icon is the hard-coded inline SVG literal defined above with no dynamic input; escaping would render the markup as visible text.
        echo '<h1 class="asw-brand-title">' . esc_html__( 'AntiSpam Wizard Settings', 'antispam-wizard' ) . '</h1>';
        echo '<span class="asw-version">v' . esc_html( ASW_VERSION ) . '</span>';
        echo '</div></header>';

        echo '<main class="asw-page-content">';
        $view_file = ASW_PATH . 'admin/views/settings-' . $current_tab . '.php';
        if ( file_exists( $view_file ) ) {
            include $view_file;
        }
        echo '</main>';

        echo '</div>';
    }

    /**
     * Handle form submission for the general settings tab.
     */
    public function handle_save(): void {
        if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $tab = $this->get_current_tab();

        if ( 'main' === $tab ) {
            $settings = array( 'enabled' => isset( $_POST['enabled'] ) );
            ASW_Settings::update( $settings );
        } else {
            $settings = array();
            $defaults = ASW_Settings::get_defaults();

            // Boolean toggles - unchecked checkboxes won't appear in POST.
            $bool_keys = array(
                'honeypot_enabled',
                'submit_time_enabled',
                'stop_words_enabled',
                'links_enabled',
                'emoji_enabled',
                'spacing_enabled',
                'multi_lang_enabled',
                'duplicate_enabled',
                'bayesian_enabled',
                'similarity_enabled',
                'shadow_display_enabled',
            );
            if ( class_exists( 'ASW_CF7_Integration' ) && ASW_CF7_Integration::is_available() ) {
                $bool_keys[] = 'cf7_enabled';
            }
            foreach ( $bool_keys as $key ) {
                $settings[ $key ] = isset( $_POST[ $key ] );
            }

            // Integer thresholds.
            $int_keys = array(
                'submit_time_min_seconds',
                'links_max',
                'emoji_max',
                'multi_lang_threshold',
                'duplicate_threshold',
                'duplicate_ttl',
                'spacing_min_words_count',
                'spacing_short_word_len',
                'min_message_length',
            );
            $int_bounds = ASW_Settings::get_integer_bounds();
            foreach ( $int_keys as $key ) {
                $raw     = isset( $_POST[ $key ] ) ? intval( $_POST[ $key ] ) : $defaults[ $key ];
                $min     = isset( $int_bounds[ $key ] ) ? $int_bounds[ $key ][0] : 1;
                $max     = isset( $int_bounds[ $key ] ) ? $int_bounds[ $key ][1] : PHP_INT_MAX;
                $settings[ $key ] = max( $min, min( $max, $raw ) );
            }

            // Float thresholds.
            $float_keys = array(
                'spacing_short_word_ratio',
                'spacing_space_ratio',
                'bayesian_threshold',
                'similarity_threshold',
            );
            $float_minimums = array( 'similarity_threshold' => 0.1 );
            $float_maximums = array( 'bayesian_threshold' => 0.99, 'similarity_threshold' => 0.99 );
            foreach ( $float_keys as $key ) {
                $minimum          = isset( $float_minimums[ $key ] ) ? $float_minimums[ $key ] : 0.1;
                $maximum          = isset( $float_maximums[ $key ] ) ? $float_maximums[ $key ] : 1.0;
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Numeric threshold: gated by is_numeric() and clamped to [$minimum, $maximum] before use, so slashes and text sanitization are irrelevant. Nonce is verified at the top of this handler.
                $raw_value        = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? trim( $_POST[ $key ] ) : '';
                $settings[ $key ] = ( '' !== $raw_value && is_numeric( $raw_value ) )
                    ? max( $minimum, min( $maximum, (float) $raw_value ) )
                    : $defaults[ $key ];
            }

            ASW_Settings::update( $settings );
        }

        $redirect_url = add_query_arg(
            array( 'settings-updated' => 'true' ),
            self::get_submenu_url( $tab )
        );
        wp_safe_redirect( $redirect_url );

        // @codeCoverageIgnoreStart
        if ( ! defined( 'ASW_TESTING' ) ) {
            exit;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * AJAX handler to add a stop-word.
     */
    public function ajax_add_stop_word(): void {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), self::AJAX_NONCE ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'antispam-wizard' ) ) );
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'antispam-wizard' ) ) );
            return;
        }

        $word = isset( $_POST['word'] ) ? sanitize_text_field( wp_unslash( $_POST['word'] ) ) : '';
        if ( '' === $word ) {
            wp_send_json_error( array( 'message' => __( 'Word cannot be empty.', 'antispam-wizard' ) ) );
            return;
        }

        if ( mb_strlen( $word, 'UTF-8' ) > 64 ) {
            wp_send_json_error( array( 'message' => __( 'Word must be 64 characters or fewer.', 'antispam-wizard' ) ) );
            return;
        }

        // Allow "=" prefix for exact-match phrases (may contain spaces).
        if ( 0 === strpos( $word, '=' ) ) {
            $phrase = substr( $word, 1 );
            if ( '' === $phrase ) {
                wp_send_json_error( array( 'message' => __( 'Exact-match phrase cannot be empty.', 'antispam-wizard' ) ) );
                return;
            }
            if ( preg_match( '/[^\p{L}\p{N}\s]/u', $phrase ) ) {
                wp_send_json_error( array( 'message' => __( 'Exact-match phrase must contain only letters, numbers, and spaces.', 'antispam-wizard' ) ) );
                return;
            }
        } elseif ( preg_match( '/[^\p{L}\p{N}]/u', $word ) ) {
            wp_send_json_error( array( 'message' => __( 'Word must contain only letters and numbers.', 'antispam-wizard' ) ) );
            return;
        }

        $list = ASW_Settings::get( 'stop_words_list', array() );
        if ( in_array( $word, $list, true ) ) {
            wp_send_json_error( array( 'message' => __( 'Word already exists.', 'antispam-wizard' ) ) );
            return;
        }

        $list[] = $word;
        ASW_Settings::update( array( 'stop_words_list' => $list ) );

        wp_send_json_success( array( 'message' => __( 'Word added.', 'antispam-wizard' ) ) );
    }

    /**
     * AJAX handler to remove a stop-word.
     */
    public function ajax_remove_stop_word(): void {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), self::AJAX_NONCE ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'antispam-wizard' ) ) );
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'antispam-wizard' ) ) );
            return;
        }

        $word = isset( $_POST['word'] ) ? sanitize_text_field( wp_unslash( $_POST['word'] ) ) : '';
        $list = ASW_Settings::get( 'stop_words_list', array() );
        $key  = array_search( $word, $list, true );

        if ( false === $key ) {
            wp_send_json_error( array( 'message' => __( 'Word not found.', 'antispam-wizard' ) ) );
            return;
        }

        array_splice( $list, $key, 1 );
        ASW_Settings::update( array( 'stop_words_list' => $list ) );

        wp_send_json_success( array( 'message' => __( 'Word removed.', 'antispam-wizard' ) ) );
    }

    /**
     * AJAX handler to add an excluded token.
     */
    public function ajax_add_excluded_token(): void {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), self::EXCLUDED_TOKENS_NONCE ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'antispam-wizard' ) ) );
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'antispam-wizard' ) ) );
            return;
        }

        $token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
        $token = mb_strtolower( $token, 'UTF-8' );
        if ( '' === $token ) {
            wp_send_json_error( array( 'message' => __( 'Token cannot be empty.', 'antispam-wizard' ) ) );
            return;
        }

        if ( preg_match( '/[^\p{L}\p{N}]/u', $token ) ) {
            wp_send_json_error( array( 'message' => __( 'Token must be a single word (letters and numbers only).', 'antispam-wizard' ) ) );
            return;
        }

        $list = ASW_Settings::get( 'excluded_tokens_list', array() );
        if ( in_array( $token, $list, true ) ) {
            wp_send_json_error( array( 'message' => __( 'Token already exists.', 'antispam-wizard' ) ) );
            return;
        }

        $list[] = $token;
        ASW_Settings::update( array( 'excluded_tokens_list' => $list ) );

        wp_send_json_success( array( 'message' => __( 'Token added.', 'antispam-wizard' ) ) );
    }

    /**
     * AJAX handler to remove an excluded token.
     */
    public function ajax_remove_excluded_token(): void {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), self::EXCLUDED_TOKENS_NONCE ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'antispam-wizard' ) ) );
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'antispam-wizard' ) ) );
            return;
        }

        $token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
        $token = mb_strtolower( $token, 'UTF-8' );
        $list  = ASW_Settings::get( 'excluded_tokens_list', array() );
        $key   = array_search( $token, $list, true );

        if ( false === $key ) {
            wp_send_json_error( array( 'message' => __( 'Token not found.', 'antispam-wizard' ) ) );
            return;
        }

        array_splice( $list, $key, 1 );
        ASW_Settings::update( array( 'excluded_tokens_list' => $list ) );

        wp_send_json_success( array( 'message' => __( 'Token removed.', 'antispam-wizard' ) ) );
    }

    /**
     * AJAX handler to mark a comment as spam or ham for Bayesian training.
     */
    public function ajax_mark_comment(): void {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), self::MARK_NONCE ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'antispam-wizard' ) ) );
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'antispam-wizard' ) ) );
            return;
        }

        $comment_id = isset( $_POST['comment_id'] ) ? absint( $_POST['comment_id'] ) : 0;
        $category   = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';

        if ( 0 === $comment_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid comment ID.', 'antispam-wizard' ) ) );
            return;
        }

        if ( ! in_array( $category, array( 'spam', 'ham' ), true ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid category.', 'antispam-wizard' ) ) );
            return;
        }

        $comment = get_comment( $comment_id );
        if ( ! $comment ) {
            wp_send_json_error( array( 'message' => __( 'Comment not found.', 'antispam-wizard' ) ) );
            return;
        }

        $content = $comment->comment_content;
        $normalized = preg_replace( '/(*UCP)\s+/u', ' ', $content );
        $content = trim( null !== $normalized ? $normalized : $content );

        // Detect the script group of the comment content.
        $script       = ASW_Script_Detector::detect( $content );
        $content_hash = hash( 'sha256', $content );
        $opposite     = ( 'spam' === $category ) ? 'ham' : 'spam';

        // Store sample in the database via Sample_Repository.
        $repository = ASW::get_instance()->get_repository();

        // Serialise the claim and the sample write against ASW_Moderation's
        // transition writer and against any other request training the same text:
        // insert() and delete_by_hash() address rows by (script, category,
        // content_hash), so two requests carrying identical bodies -- routine for
        // one bot body posted from many addresses -- would otherwise each insert
        // their category and then delete the other's.
        //
        // The status change further down must stay *outside* the lock: it fires
        // transition_comment_status, ASW_Moderation's handler takes this same lock
        // through a second file handle, and flock() locks an open file description
        // rather than a process, so holding it across the call would deadlock.
        $claim = ASW_Moderation::with_body_lock(
            $content,
            function () use ( $comment, $comment_id, $category, $content, $script, $repository ) {
                // Atomically claim the training slot under lock to prevent duplicate training.
                $mark_result = ASW_Spam_Log::mark_trained( $comment_id, $category );
                if ( 'ok' !== $mark_result ) {
                    return $mark_result;
                }

                if ( null === $repository ) {
                    if ( ! self::clear_training_claim( $comment_id ) ) {
                        return 'rollback_failed';
                    }
                    return 'no_repository';
                }

                // Secondary subject key for the Privacy API: the comment this sample is
                // trained from is routinely hard-deleted (emptying the spam queue), and
                // comment_id alone would then no longer reach the stored text. Never the
                // raw address -- the same salted hash the form spam-log entries carry.
                $author_email = isset( $comment->comment_author_email ) ? (string) $comment->comment_author_email : '';
                $email_hash   = ASW_Detector::hash_email( $author_email );

                $insert_result = $repository->insert( $script, $category, $content, $comment_id, $email_hash );

                if ( false === $insert_result ) {
                    // Roll back the training mark so the admin can retry. A refused
                    // rollback leaves the claim standing over a training that never
                    // happened, and this screen cannot clear it -- mark_trained()
                    // refuses an entry that already carries a category -- so it is
                    // reported as the inconsistency it is rather than as a retry the
                    // pill would answer 'already_trained'.
                    if ( ! self::clear_training_claim( $comment_id ) ) {
                        return 'rollback_failed';
                    }
                    return 'insert_failed';
                }

                return $insert_result;
            }
        );

        if ( 'inserted' !== $claim && 'exists' !== $claim ) {
            if ( 'rollback_failed' === $claim ) {
                wp_send_json_error( array( 'message' => self::training_inconsistent_message() ) );
                return;
            }

            if ( 'no_repository' === $claim ) {
                wp_send_json_error( array( 'message' => __( 'Plugin not fully initialized.', 'antispam-wizard' ) ) );
                return;
            }

            if ( 'insert_failed' === $claim ) {
                wp_send_json_error( array( 'message' => __( 'Failed to save training data.', 'antispam-wizard' ) ) );
                return;
            }

            $msg = 'already_trained' === $claim
                ? __( 'This comment has already been used for training.', 'antispam-wizard' )
                : __( 'Comment not found in spam log. It may have been removed.', 'antispam-wizard' );
            wp_send_json_error( array( 'message' => $msg ) );
            return;
        }

        // Update the comment status in WordPress.
        // Skip the status call when the comment is already at the target status
        // to avoid a false failure (wp_update_comment returns 0 for no-change).
        $current_status = wp_get_comment_status( $comment_id );

        if ( 'ham' === $category ) {
            $status_changed = self::move_out_of_spam( $comment_id, $current_status );
        } elseif ( 'spam' === $category && 'spam' !== $current_status ) {
            $status_changed = wp_spam_comment( $comment_id );
        } else {
            $status_changed = true;
        }

        // Either verdict here is a moderator's decision, so shadow display stops
        // presenting the comment to its author -- including the common case of
        // confirming a comment the plugin already put in the spam queue, and a
        // Ham verdict that restores a trashed comment, neither of which fires the
        // overruling transition ASW_Shadow_Display watches.
        if ( $status_changed ) {
            ASW_Shadow_Display::forget_verdict( $comment_id );
        }

        // Leave the text stored under exactly one category, and release the claim
        // if the status change failed. Both run under the body lock again, which
        // could not be held across the status change above.
        $outcome = ASW_Moderation::with_body_lock(
            $content,
            function () use ( $status_changed, $repository, $script, $category, $opposite, $content_hash, $comment_id ) {
                // The same text must never be stored as both spam and ham: the
                // classifier would then count it as evidence for and against at
                // once, which is the state ASW_Moderation's flip delete exists to
                // prevent. The transition the status change above fires reconciles
                // that itself, but it does not fire when the comment is already at
                // the target status -- the common case on this screen, where the
                // pill confirms a comment the plugin already moved to the spam
                // queue.
                //
                // This runs whether or not the status change succeeded, because the
                // row this request stored is kept either way (see below): a kept row
                // plus an untouched opposite row is the contradiction, so skipping
                // the reconciliation on the failed-status path would leave it behind
                // and only a later moderation would clear it.
                //
                // The lock could not be held across the status change, so this
                // section is a second acquisition: another moderation of the same
                // text -- ASW_Moderation handling a transition, or a second pill --
                // can have committed the opposite category in between, inserting
                // its row and deleting with its own flip the row this request
                // stored. Deleting the opposite unconditionally would then leave
                // the text trained as *neither*, with both log claims standing.
                // The table is therefore re-read inside the lock and the delete is
                // issued only on positive evidence that the contradiction is real
                // and this request's decision still stands -- both categories
                // present. Anything else leaves the table alone: our category gone
                // and the opposite one there is a newer decision this request
                // stands down from, the same rule transition_is_current() applies to
                // two overlapping transitions; no opposite row is nothing to delete;
                // and an empty result the database refused (find_categories_by_hash()
                // reports a failed read as no rows) is an unknown state, where the
                // non-destructive direction is the only safe one -- deleting on it
                // would re-open the very "trained as neither" race this re-read
                // exists to close. That leaves at most a contradiction standing, and
                // the next moderation of either comment clears it, since
                // ASW_Moderation::training_is_settled() refuses a claim whose
                // opposite row is present.
                $stored  = $repository->find_categories_by_hash( $script, $content_hash );
                $flip_ok = true;

                if ( in_array( $category, $stored, true ) && in_array( $opposite, $stored, true ) ) {
                    // A false return is a refused DELETE, not "nothing matched".
                    $flip_ok = (bool) $repository->delete_by_hash( $script, $opposite, $content_hash );
                }

                if ( ! $status_changed ) {
                    // The claim goes back so the moderator's retry can take it
                    // again, but the stored sample is deliberately *kept* -- the
                    // same rule ASW_Moderation::roll_back_claim() follows, which
                    // never deletes the row its own commit inserted either.
                    //
                    // The sample table is deduplicated by content, so that row is
                    // shared state rather than this request's property: the lock
                    // could not be held across the status change, and another
                    // moderation of the same body -- one bot body posted from many
                    // addresses is routine -- can have trained the same category in
                    // that window, been answered 'exists' and committed against
                    // this very row. Deleting it would then pull the sample out
                    // from under that request's claim, which every later transition
                    // of its comment reads as 'already_trained' -- a silent loss
                    // on a comment this request has nothing to do with.
                    //
                    // A refused rollback write leaves the claim standing, and the
                    // pill cannot clear it -- mark_trained() refuses an entry that
                    // already carries a category -- so it is reported as the failed
                    // rollback it is rather than as a plain retry. A reconciliation
                    // that was refused as well needs no separate report: the retry
                    // this outcome asks for re-runs it.
                    if ( ! self::clear_training_claim( $comment_id ) ) {
                        return 'status_rollback_failed';
                    }

                    return 'status_failed';
                }

                if ( ! $flip_ok ) {
                    // The claim is rolled back: the inserted row stays, and the
                    // retry the pill then offers re-runs an insert() that
                    // deduplicates and a delete that finally clears the
                    // contradiction.
                    if ( ! self::clear_training_claim( $comment_id ) ) {
                        return 'rollback_failed';
                    }

                    return 'flip_failed';
                }

                return 'ok';
            }
        );

        if ( 'status_failed' === $outcome ) {
            wp_send_json_error( array(
                'message' => __( 'Comment status could not be changed. Please try again.', 'antispam-wizard' ),
            ) );
            return;
        }

        if ( 'status_rollback_failed' === $outcome ) {
            wp_send_json_error( array(
                'message' => __( 'Comment status could not be changed and rollback failed. Please check training data manually.', 'antispam-wizard' ),
            ) );
            return;
        }

        if ( 'rollback_failed' === $outcome ) {
            wp_send_json_error( array( 'message' => self::training_inconsistent_message() ) );
            return;
        }

        if ( 'flip_failed' === $outcome ) {
            wp_send_json_error( array( 'message' => __( 'Failed to save training data.', 'antispam-wizard' ) ) );
            return;
        }

        $script_label = ( 'unknown' !== $script && isset( ASW_Script_Detector::SCRIPT_GROUPS[ $script ] ) )
            ? ASW_Script_Detector::SCRIPT_GROUPS[ $script ]
            : __( 'Legacy', 'antispam-wizard' );

        wp_send_json_success( array(
            'message' => sprintf(
                // translators: %s: Training category the comment was saved as (spam or ham).
                __( 'Comment marked as %s. Training data updated.', 'antispam-wizard' ),
                $category
            ),
            'script'       => $script,
            'script_label' => sprintf(
                // translators: %s: Human-readable name of the script model the sample was saved to.
                __( 'Saved to: %s model', 'antispam-wizard' ),
                $script_label
            ),
        ) );
    }

    /**
     * Carry out a Ham verdict on the comment's status.
     *
     * wp_unspam_comment() and wp_untrash_comment() both restore the status
     * recorded in the _wp_trash_meta_status meta, falling back to '0'. For a
     * spam comment that is never 'spam' again, but trashing a comment from the
     * spam queue records exactly 'spam', so restoring it would put the comment
     * back in the queue -- training spam through ASW_Moderation over the ham this
     * request just stored. A trashed comment whose recorded status is 'spam' is
     * therefore restored as unapproved, and the recorded status is put back if
     * the restore is refused. A comment already out of the spam queue is left
     * as it is: '0' is where Not Spam leaves a flagged comment, and core refuses
     * a status write that changes nothing. A comment with no status at all was
     * deleted after this request looked it up, which is a failed change.
     *
     * @param int    $comment_id     The comment.
     * @param string $current_status wp_get_comment_status() before the change.
     * @return bool False when the status write was refused or the comment is gone.
     */
    private static function move_out_of_spam( int $comment_id, $current_status ): bool {
        if ( 'spam' === $current_status ) {
            return (bool) wp_unspam_comment( $comment_id );
        }
        if ( 'approved' === $current_status || 'unapproved' === $current_status ) {
            return true;
        }
        if ( 'trash' !== $current_status ) {
            // false: the comment was deleted after this request looked it up.
            return false;
        }

        $stored = (string) get_comment_meta( $comment_id, '_wp_trash_meta_status', true );
        if ( 'spam' === $stored && ! update_comment_meta( $comment_id, '_wp_trash_meta_status', '0' ) ) {
            return false;
        }
        if ( wp_untrash_comment( $comment_id ) ) {
            return true;
        }
        if ( 'spam' === $stored ) {
            update_comment_meta( $comment_id, '_wp_trash_meta_status', $stored );
            // Read back rather than trust the return value, which is also false for
            // a write that changed nothing. A refused restore leaves the comment's
            // own Restore pointing at the moderation queue instead of the spam queue.
            if ( 'spam' !== (string) get_comment_meta( $comment_id, '_wp_trash_meta_status', true ) ) {
                // The id is an int and carries no user input.
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational diagnostic: a refused rollback silently changes where the comment's own Restore puts it.
                error_log( 'ASW: trash status rollback failed for comment ' . $comment_id );
            }
        }
        return false;
    }

    /**
     * Clear the training claim this request made, reporting a refused write.
     *
     * unmark_trained() returns false only when the option write itself was
     * refused -- an entry that carries no mark, or no entry at all, is not a
     * failure -- and a refused one leaves the claim standing over a training
     * that never happened. That is invisible and, for this screen, final: the
     * pill offers a retry, but mark_trained() refuses an entry that already
     * carries a category, so every later click answers 'already_trained'. There
     * is no second write to attempt either, the option store being what just
     * refused one, so it is logged exactly as ASW_Moderation::roll_back_claim()
     * logs its own. What recovers it is the verification in
     * ASW_Moderation::commit_training(): the next moderation of this comment
     * reads 'already_trained', finds no sample behind the flag and trains anyway.
     *
     * @param int $comment_id The comment whose claim is being cleared.
     * @return bool False when the write was refused.
     */
    private static function clear_training_claim( int $comment_id ): bool {
        if ( ASW_Spam_Log::unmark_trained( $comment_id ) ) {
            return true;
        }

        // The id is an int and carries no user input.
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational diagnostic: a refused rollback silently blocks all further training of one comment from this screen, which is otherwise invisible.
        error_log( 'ASW: training rollback failed for comment ' . $comment_id );
        return false;
    }

    /**
     * What the moderator is told when a claim rollback was refused.
     *
     * Every path that could not undo its own claim reports this rather than the
     * plain "try again" of its own failure: the claim stands over a training that
     * did not take, and the pill answers every later click 'already_trained', so
     * a retry is exactly what will not help. The failed-status path has its own
     * message naming the status write, which this one deliberately does not
     * repeat -- on the reconciliation path the status did change.
     *
     * @return string
     */
    private static function training_inconsistent_message(): string {
        return __( 'Training data may be inconsistent. Please check training data manually.', 'antispam-wizard' );
    }

    /**
     * Handle stop-words import from file upload or textarea.
     */
    public function handle_stop_words_import(): void {
        if ( ! isset( $_POST['asw_import'] ) ) {
            return;
        }

        if ( ! isset( $_POST['asw_import_nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['asw_import_nonce'] ) ), 'asw_import_stop_words' ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $words_text = '';

        // Check file upload first.
        if ( ! empty( $_FILES['stop_words_file']['tmp_name'] )
            && isset( $_FILES['stop_words_file']['error'] )
            && UPLOAD_ERR_OK === $_FILES['stop_words_file']['error']
            && isset( $_FILES['stop_words_file']['size'] )
            && $_FILES['stop_words_file']['size'] <= 1048576
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PHP-generated upload path, validated by is_uploaded_file(); sanitizing it would corrupt the tmp path. Nonce is verified at the top of this handler.
            && is_uploaded_file( $_FILES['stop_words_file']['tmp_name'] )
        ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Same PHP-generated upload path, already proven by is_uploaded_file() above.
            $file_contents = file_get_contents( $_FILES['stop_words_file']['tmp_name'] );
            if ( false !== $file_contents ) {
                $words_text = $file_contents;
            }
        } elseif ( ! empty( $_POST['stop_words_text'] ) && is_string( $_POST['stop_words_text'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Multi-line word list: unslashed here, then split on newlines and each word individually sanitized by parse_stop_words_text(); sanitize_text_field() at this point would collapse the newlines the parser needs. Nonce is verified at the top of this handler.
            $words_text = wp_unslash( $_POST['stop_words_text'] );
        }

        if ( '' === $words_text ) {
            return;
        }

        $new_words = self::parse_stop_words_text( $words_text );
        if ( empty( $new_words ) ) {
            return;
        }

        $list   = ASW_Settings::get( 'stop_words_list', array() );
        $merged = array_values( array_unique( array_merge( $list, $new_words ) ) );

        if ( count( $merged ) > self::IMPORT_MAX_ENTRIES ) {
            $merged = array_slice( $merged, 0, self::IMPORT_MAX_ENTRIES );
            add_action( 'admin_notices', function () {
                echo '<div class="notice notice-warning is-dismissible"><p>';
                echo esc_html(
                    sprintf(
                        // translators: %d: Maximum number of stop-word entries kept on import.
                        __( 'Import truncated: stop-words list capped at %d entries.', 'antispam-wizard' ),
                        self::IMPORT_MAX_ENTRIES
                    )
                );
                echo '</p></div>';
            } );
        }

        ASW_Settings::update( array( 'stop_words_list' => $merged ) );
    }

    /**
     * Handle stop-words export as text file download.
     */
    public function handle_stop_words_export(): void {
        if ( ! isset( $_POST['asw_export'] ) ) {
            return;
        }

        if ( ! isset( $_POST['asw_export_nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['asw_export_nonce'] ) ), 'asw_export_stop_words' ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $list = ASW_Settings::get( 'stop_words_list', array() );

        nocache_headers();
        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="asw-stop-words.txt"' );
        echo implode( "\n", $list ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Body of a text/plain Content-Disposition: attachment download; entries are sanitized on save and wp_kses() would corrupt the exported bytes.

        // @codeCoverageIgnoreStart
        if ( ! defined( 'ASW_TESTING' ) ) {
            exit;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Handle excluded tokens import from file upload or textarea.
     */
    public function handle_excluded_tokens_import(): void {
        if ( ! isset( $_POST['asw_import_excluded_tokens'] ) ) {
            return;
        }

        if ( ! isset( $_POST['asw_import_excluded_tokens_nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['asw_import_excluded_tokens_nonce'] ) ), 'asw_import_excluded_tokens' ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $tokens_text = '';

        // Check file upload first.
        if ( ! empty( $_FILES['excluded_tokens_file']['tmp_name'] )
            && isset( $_FILES['excluded_tokens_file']['error'] )
            && UPLOAD_ERR_OK === $_FILES['excluded_tokens_file']['error']
            && isset( $_FILES['excluded_tokens_file']['size'] )
            && $_FILES['excluded_tokens_file']['size'] <= 1048576
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PHP-generated upload path, validated by is_uploaded_file(); sanitizing it would corrupt the tmp path. Nonce is verified at the top of this handler.
            && is_uploaded_file( $_FILES['excluded_tokens_file']['tmp_name'] )
        ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Same PHP-generated upload path, already proven by is_uploaded_file() above.
            $file_contents = file_get_contents( $_FILES['excluded_tokens_file']['tmp_name'] );
            if ( false !== $file_contents ) {
                $tokens_text = $file_contents;
            }
        } elseif ( ! empty( $_POST['excluded_tokens_text'] ) && is_string( $_POST['excluded_tokens_text'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Multi-line token list: unslashed here, then split on newlines and each token individually run through sanitize_text_field() below; sanitizing the whole blob would collapse the newlines the split needs. Nonce is verified at the top of this handler.
            $tokens_text = wp_unslash( $_POST['excluded_tokens_text'] );
        }

        if ( '' === $tokens_text ) {
            return;
        }

        $lines      = preg_split( '/\r\n|\r|\n/', $tokens_text );
        $new_tokens = array_filter( array_map( function ( string $line ): string {
            return sanitize_text_field( trim( $line ) );
        }, $lines ), function ( string $s ): bool {
            return '' !== $s;
        } );
        $new_tokens = array_map( function ( string $t ): string {
            return mb_strtolower( $t, 'UTF-8' );
        }, $new_tokens );
        $new_tokens = array_filter( $new_tokens, function ( string $t ): bool {
            return ! preg_match( '/[^\p{L}\p{N}]/u', $t );
        } );
        if ( empty( $new_tokens ) ) {
            return;
        }

        $list   = ASW_Settings::get( 'excluded_tokens_list', array() );
        $merged = array_values( array_unique( array_merge( $list, $new_tokens ) ) );

        if ( count( $merged ) > self::IMPORT_MAX_ENTRIES ) {
            $merged = array_slice( $merged, 0, self::IMPORT_MAX_ENTRIES );
            add_action( 'admin_notices', function () {
                echo '<div class="notice notice-warning is-dismissible"><p>';
                echo esc_html(
                    sprintf(
                        // translators: %d: Maximum number of excluded-token entries kept on import.
                        __( 'Import truncated: excluded tokens list capped at %d entries.', 'antispam-wizard' ),
                        self::IMPORT_MAX_ENTRIES
                    )
                );
                echo '</p></div>';
            } );
        }

        ASW_Settings::update( array( 'excluded_tokens_list' => $merged ) );
    }

    /**
     * Handle excluded tokens export as text file download.
     */
    public function handle_excluded_tokens_export(): void {
        if ( ! isset( $_POST['asw_export_excluded_tokens'] ) ) {
            return;
        }

        if ( ! isset( $_POST['asw_export_excluded_tokens_nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['asw_export_excluded_tokens_nonce'] ) ), 'asw_export_excluded_tokens' ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $list = ASW_Settings::get( 'excluded_tokens_list', array() );

        nocache_headers();
        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="asw-excluded-tokens.txt"' );
        echo implode( "\n", $list ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Body of a text/plain Content-Disposition: attachment download; entries are sanitized on save and wp_kses() would corrupt the exported bytes.

        // @codeCoverageIgnoreStart
        if ( ! defined( 'ASW_TESTING' ) ) {
            exit;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Handle clearing the spam log.
     */
    public function handle_clear_log(): void {
        if ( ! isset( $_POST['asw_clear_log'] ) ) {
            return;
        }

        if ( ! isset( $_POST['asw_clear_log_nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['asw_clear_log_nonce'] ) ), 'asw_clear_log' ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        ASW_Spam_Log::clear();

        wp_safe_redirect( self::get_submenu_url( 'log' ) );

        // @codeCoverageIgnoreStart
        if ( ! defined( 'ASW_TESTING' ) ) {
            exit;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Parse stop-words text into an array (one word per line).
     *
     * @param string $text Raw text with words separated by newlines.
     * @return array Cleaned words.
     */
    public static function parse_stop_words_text( string $text ): array {
        $lines = preg_split( '/\r\n|\r|\n/', $text );
        $words = array();
        foreach ( $lines as $line ) {
            $word = sanitize_text_field( trim( $line ) );
            if ( '' === $word ) {
                continue;
            }
            if ( mb_strlen( $word, 'UTF-8' ) > 64 ) {
                continue;
            }
            if ( 0 === strpos( $word, '=' ) ) {
                $phrase = substr( $word, 1 );
                if ( '' === $phrase || preg_match( '/[^\p{L}\p{N}\s]/u', $phrase ) ) {
                    continue;
                }
            } elseif ( preg_match( '/[^\p{L}\p{N}]/u', $word ) ) {
                continue;
            }
            $words[] = $word;
        }
        return $words;
    }
}
