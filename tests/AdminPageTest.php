<?php

use PHPUnit\Framework\TestCase;

class AdminPageTest extends TestCase {

    private ASW_Admin_Page $admin_page;

    protected function setUp(): void {
        global $wp_options, $wp_actions, $wp_filters, $wp_menu_pages, $wp_submenu_pages, $wp_enqueued_styles, $wp_enqueued_scripts, $wp_localized_scripts, $wp_current_user_caps, $wp_nonces, $wp_redirects;
        $wp_options         = array();
        $wp_actions         = array();
        $wp_filters         = array();
        $wp_menu_pages      = array();
        $wp_submenu_pages   = array();
        $wp_enqueued_styles = array();
        $wp_enqueued_scripts = array();
        $wp_localized_scripts = array();
        $wp_current_user_caps = array( 'manage_options' => true );
        $wp_nonces          = array();
        $wp_redirects       = array();
        $_POST              = array();
        $_GET               = array();

        if ( ! defined( 'ASW_PATH' ) ) {
            define( 'ASW_PATH', dirname( __DIR__ ) . '/antispam-wizard/' );
        }
        if ( ! defined( 'ASW_URL' ) ) {
            define( 'ASW_URL', 'http://example.com/wp-content/plugins/antispam-wizard/' );
        }
        if ( ! defined( 'ASW_VERSION' ) ) {
            define( 'ASW_VERSION', '1.0.0' );
        }

        $this->admin_page = new ASW_Admin_Page();
    }

    protected function tearDown(): void {
        $_POST = array();
        $_GET  = array();
    }

    public function test_init_registers_hooks() {
        global $wp_actions;
        $wp_actions = array();

        $this->admin_page->init();

        $this->assertArrayHasKey( 'admin_menu', $wp_actions );
        $this->assertArrayHasKey( 'admin_init', $wp_actions );
        $this->assertArrayHasKey( 'admin_enqueue_scripts', $wp_actions );
    }

    public function test_add_menu_page_registers_top_level_menu() {
        global $wp_menu_pages;

        $this->admin_page->add_menu_page();

        $this->assertArrayHasKey( 'antispam-wizard-main', $wp_menu_pages );
        $this->assertSame( 'manage_options', $wp_menu_pages['antispam-wizard-main']['capability'] );
        $this->assertSame( 'AntiSpam Wizard', $wp_menu_pages['antispam-wizard-main']['page_title'] );
        $this->assertSame( 'AntiSpam Wizard', $wp_menu_pages['antispam-wizard-main']['menu_title'] );
        $this->assertSame( 'dashicons-shield-alt', $wp_menu_pages['antispam-wizard-main']['icon_url'] );
        $this->assertSame( 80, $wp_menu_pages['antispam-wizard-main']['position'] );
        $this->assertSame( 'menu', $wp_menu_pages['antispam-wizard-main']['type'] );
    }

    public function test_submenu_slugs_has_main_first() {
        $keys = array_keys( ASW_Admin_Page::SUBMENU_SLUGS );
        $this->assertSame( 'main', $keys[0] );
    }

    public function test_add_menu_page_registers_all_submenu_pages() {
        global $wp_submenu_pages;

        $this->admin_page->add_menu_page();

        $expected_slugs = array(
            'antispam-wizard-main',
            'antispam-wizard',
            'antispam-wizard-stop-words',
            'antispam-wizard-excluded-tokens',
            'antispam-wizard-log',
            'antispam-wizard-stats',
        );

        foreach ( $expected_slugs as $slug ) {
            $this->assertArrayHasKey( $slug, $wp_submenu_pages, "Missing submenu: $slug" );
            $this->assertSame( 'antispam-wizard-main', $wp_submenu_pages[ $slug ]['parent_slug'] );
            $this->assertSame( 'manage_options', $wp_submenu_pages[ $slug ]['capability'] );
        }
    }

    public function test_get_submenu_url_returns_admin_php_urls() {
        $url = ASW_Admin_Page::get_submenu_url( 'general' );
        $this->assertStringContainsString( 'admin.php', $url );
        $this->assertStringContainsString( 'page=antispam-wizard', $url );

        $url = ASW_Admin_Page::get_submenu_url( 'stop-words' );
        $this->assertStringContainsString( 'page=antispam-wizard-stop-words', $url );

        $url = ASW_Admin_Page::get_submenu_url( 'log' );
        $this->assertStringContainsString( 'page=antispam-wizard-log', $url );
    }

    public function test_get_submenu_url_falls_back_for_unknown_tab() {
        $url = ASW_Admin_Page::get_submenu_url( 'nonexistent' );
        $this->assertStringContainsString( 'page=antispam-wizard-main', $url );
    }

    public function test_render_page_detects_tab_from_page_param() {
        $_GET['page'] = 'antispam-wizard-stats';

        ob_start();
        $this->admin_page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'asw-kpi-row', $output );
    }

    public function test_render_page_defaults_to_main_for_unknown_page() {
        $_GET['page'] = 'antispam-wizard-unknown';

        ob_start();
        $this->admin_page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'asw-admin', $output );
    }

    public function test_enqueue_assets_on_toplevel_page() {
        global $wp_enqueued_styles;

        $this->admin_page->add_menu_page();
        $this->admin_page->enqueue_assets( 'toplevel_page_antispam-wizard-main' );

        $this->assertArrayHasKey( 'asw-admin', $wp_enqueued_styles );
        $this->assertStringContainsString( 'admin/css/admin.css', $wp_enqueued_styles['asw-admin']['src'] );
    }

    /**
     * The submenu hook suffix prefix is sanitize_title() of the top-level MENU
     * TITLE ("AntiSpam Wizard" => "antispam-wizard"), not the parent slug.
     *
     * @dataProvider provide_submenu_hook_suffixes
     *
     * @param string $hook_suffix Real WordPress hook suffix for the submenu page.
     */
    public function test_enqueue_assets_on_submenu_page( string $hook_suffix ) {
        global $wp_enqueued_styles, $wp_enqueued_scripts;

        $this->admin_page->add_menu_page();
        $this->admin_page->enqueue_assets( $hook_suffix );

        $this->assertArrayHasKey( 'asw-admin', $wp_enqueued_styles, "Styles missing on: $hook_suffix" );
        $this->assertArrayHasKey( 'asw-admin', $wp_enqueued_scripts, "Scripts missing on: $hook_suffix" );
    }

    /**
     * Real hook suffixes generated by WordPress for the six plugin pages.
     *
     * @return array<string, array{0: string}>
     */
    public function provide_submenu_hook_suffixes(): array {
        return array(
            'main'            => array( 'antispam-wizard_page_antispam-wizard-main' ),
            'general'         => array( 'antispam-wizard_page_antispam-wizard' ),
            'stop-words'      => array( 'antispam-wizard_page_antispam-wizard-stop-words' ),
            'excluded-tokens' => array( 'antispam-wizard_page_antispam-wizard-excluded-tokens' ),
            'log'             => array( 'antispam-wizard_page_antispam-wizard-log' ),
            'stats'           => array( 'antispam-wizard_page_antispam-wizard-stats' ),
        );
    }

    public function test_add_menu_page_returns_expected_hook_suffixes() {
        $this->assertSame(
            'toplevel_page_antispam-wizard-main',
            add_menu_page( 'AntiSpam Wizard', 'AntiSpam Wizard', 'manage_options', 'antispam-wizard-main' )
        );
        $this->assertSame(
            'antispam-wizard_page_antispam-wizard-log',
            add_submenu_page( 'antispam-wizard-main', 'Spam Log', 'Spam Log', 'manage_options', 'antispam-wizard-log' )
        );
    }

    /**
     * @dataProvider provide_unrelated_hook_suffixes
     *
     * @param string $hook_suffix Hook suffix that must not trigger enqueueing.
     */
    public function test_enqueue_assets_skips_other_pages( string $hook_suffix ) {
        global $wp_enqueued_styles, $wp_enqueued_scripts;

        $this->admin_page->add_menu_page();
        $this->admin_page->enqueue_assets( $hook_suffix );

        $this->assertEmpty( $wp_enqueued_styles, "Unexpected styles on: $hook_suffix" );
        $this->assertEmpty( $wp_enqueued_scripts, "Unexpected scripts on: $hook_suffix" );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function provide_unrelated_hook_suffixes(): array {
        return array(
            'edit.php'             => array( 'edit.php' ),
            'index.php'            => array( 'index.php' ),
            'other plugin page'    => array( 'toplevel_page_other-plugin' ),
            // The slug-derived prefix WordPress never generates (regression guard).
            'slug-derived prefix'  => array( 'antispam-wizard-main_page_antispam-wizard-log' ),
        );
    }

    public function test_handle_save_with_valid_nonce() {
        global $wp_nonces;
        $_GET['page'] = 'antispam-wizard';
        $nonce = wp_create_nonce( ASW_Admin_Page::NONCE_ACTION );

        $_POST[ ASW_Admin_Page::NONCE_FIELD ] = $nonce;
        $_POST['links_enabled'] = '1';
        $_POST['links_max']     = '5';
        $_POST['emoji_enabled'] = '1';
        $_POST['emoji_max']     = '8';
        $_POST['spacing_short_word_ratio'] = '0.6';
        $_POST['min_message_length'] = '30';

        $this->admin_page->handle_save();

        $settings = ASW_Settings::get_all();
        $this->assertTrue( $settings['links_enabled'] );
        $this->assertSame( 5, $settings['links_max'] );
        $this->assertTrue( $settings['emoji_enabled'] );
        $this->assertSame( 8, $settings['emoji_max'] );
        $this->assertSame( 0.6, $settings['spacing_short_word_ratio'] );
        $this->assertSame( 30, $settings['min_message_length'] );
        // Unchecked checkboxes should be false.
        $this->assertFalse( $settings['stop_words_enabled'] );
        $this->assertFalse( $settings['spacing_enabled'] );
        $this->assertFalse( $settings['multi_lang_enabled'] );
        $this->assertFalse( $settings['duplicate_enabled'] );
        $this->assertFalse( $settings['bayesian_enabled'] );
    }

    public function test_handle_save_saves_enabled_toggle() {
        $_GET['page'] = 'antispam-wizard-main';
        $nonce = wp_create_nonce( ASW_Admin_Page::NONCE_ACTION );
        $_POST[ ASW_Admin_Page::NONCE_FIELD ] = $nonce;
        $_POST['enabled'] = '1';

        $this->admin_page->handle_save();

        $this->assertTrue( ASW_Settings::get( 'enabled' ) );
    }

    public function test_handle_save_from_main_page_preserves_general_settings() {
        ASW_Settings::update( array(
            'links_enabled' => true,
            'links_max'     => 5,
            'bayesian_threshold' => 0.85,
        ) );

        $_GET['page'] = 'antispam-wizard-main';
        $nonce = wp_create_nonce( ASW_Admin_Page::NONCE_ACTION );
        $_POST[ ASW_Admin_Page::NONCE_FIELD ] = $nonce;
        $_POST['enabled'] = '1';

        $this->admin_page->handle_save();

        $this->assertTrue( ASW_Settings::get( 'enabled' ) );
        $this->assertTrue( ASW_Settings::get( 'links_enabled' ) );
        $this->assertSame( 5, ASW_Settings::get( 'links_max' ) );
        $this->assertSame( 0.85, ASW_Settings::get( 'bayesian_threshold' ) );
    }

    public function test_handle_save_from_main_page_disables_enabled() {
        ASW_Settings::update( array( 'enabled' => true, 'links_enabled' => true ) );

        $_GET['page'] = 'antispam-wizard-main';
        $nonce = wp_create_nonce( ASW_Admin_Page::NONCE_ACTION );
        $_POST[ ASW_Admin_Page::NONCE_FIELD ] = $nonce;

        $this->admin_page->handle_save();

        $this->assertFalse( ASW_Settings::get( 'enabled' ) );
        $this->assertTrue( ASW_Settings::get( 'links_enabled' ) );
    }

    public function test_handle_save_without_nonce_does_nothing() {
        // No nonce in POST.
        $_POST['links_max'] = '99';

        $this->admin_page->handle_save();

        // Settings should still be defaults.
        $this->assertSame( 2, ASW_Settings::get( 'links_max' ) );
    }

    public function test_handle_save_with_invalid_nonce_does_nothing() {
        $_POST[ ASW_Admin_Page::NONCE_FIELD ] = 'invalid_nonce';
        $_POST['links_max'] = '99';

        $this->admin_page->handle_save();

        $this->assertSame( 2, ASW_Settings::get( 'links_max' ) );
    }

    public function test_handle_save_without_capability_does_nothing() {
        global $wp_current_user_caps, $wp_nonces;
        $wp_current_user_caps = array(); // No capabilities.

        $nonce = wp_create_nonce( ASW_Admin_Page::NONCE_ACTION );
        $_POST[ ASW_Admin_Page::NONCE_FIELD ] = $nonce;
        $_POST['links_max'] = '99';

        $this->admin_page->handle_save();

        $this->assertSame( 2, ASW_Settings::get( 'links_max' ) );
    }

    public function test_render_page_requires_capability() {
        global $wp_current_user_caps;
        $wp_current_user_caps = array(); // No capabilities.

        ob_start();
        $this->admin_page->render_page();
        $output = ob_get_clean();

        // Should output nothing when user lacks capability.
        $this->assertEmpty( $output );
    }

    /**
     * Golden test for the raw brand-icon echo in render_page(), which carries an
     * annotated phpcs:ignore for WordPress.Security.EscapeOutput.OutputNotEscaped.
     * The icon is a hard-coded inline SVG literal with no dynamic input; escaping
     * it would render the markup as visible text instead of an icon.
     */
    public function test_render_page_emits_brand_svg_unescaped() {
        $expected_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.963 2.286a.75.75 0 0 0-1.071-.136 9.742 9.742 0 0 0-3.539 6.176 7.547 7.547 0 0 1-1.705-1.715.75.75 0 0 0-1.152-.082A9 9 0 1 0 15.68 4.534a7.46 7.46 0 0 1-2.717-2.248zM12 18.75a3.75 3.75 0 0 0 1.875-7.001c.21.166.42.34.628.523a.75.75 0 0 0 1.012-.022 6.726 6.726 0 0 0 1.852-2.41 6.752 6.752 0 0 1-7.367 8.91z"/></svg>';

        ob_start();
        $this->admin_page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString(
            '<span class="asw-brand-icon">' . $expected_svg . '</span>',
            $output
        );
        $this->assertStringNotContainsString( '&lt;svg', $output );
        $this->assertStringNotContainsString( '&lt;path', $output );
        $this->assertStringNotContainsString( '&quot;currentColor&quot;', $output );

        // The literal in the source file is what gets echoed, byte for byte.
        $source = file_get_contents( ASW_PATH . 'admin/class-admin-page.php' );
        $this->assertStringContainsString( '$brand_icon = \'' . $expected_svg . '\';', $source );
    }

    /**
     * The website and source addresses live in two constants, and readme.txt,
     * the Plugin URI header and the Plugins-screen row all carry the same ones,
     * so none of them can drift. The Author headers name the maintainer and
     * their own site: the directory's ownership check relates the submitting
     * account to the author it finds in the header, and it rejects a Plugin
     * URI equal to the Author URI, so the two must stay different.
     */
    public function test_website_and_source_addresses_agree_across_readme_header_and_row() {
        $this->assertSame( 'https://antispamwizard.com/', ASW_Admin_Page::WEBSITE_URL );
        $this->assertSame( 'https://github.com/HawkeyePierce89/antispam-wizard', ASW_Admin_Page::SOURCE_URL );

        $header = file_get_contents( ASW_PATH . 'antispam-wizard.php' );
        $this->assertMatchesRegularExpression( '/^ \* Plugin URI:\s+' . preg_quote( ASW_Admin_Page::WEBSITE_URL, '/' ) . '$/m', $header );
        $this->assertMatchesRegularExpression( '/^ \* Author:\s+Anton Karmanov$/m', $header );
        $this->assertMatchesRegularExpression( '/^ \* Author URI:\s+https:\/\/karmanov\.ws\/$/m', $header );
        $this->assertStringNotContainsString( ASW_Admin_Page::WEBSITE_URL, substr( $header, strpos( $header, 'Author URI:' ), 60 ), 'Author URI must differ from Plugin URI' );

        $readme = file_get_contents( ASW_PATH . 'readme.txt' );
        $this->assertStringContainsString( '* Website: ' . ASW_Admin_Page::WEBSITE_URL, $readme );
        $this->assertStringContainsString( '* Source code and issue tracker: ' . ASW_Admin_Page::SOURCE_URL, $readme );
    }

    /**
     * The Plugins screen row carries a "Source code" link through
     * plugin_row_meta, registered with two arguments; the website is already
     * the "Visit plugin site" link core builds from the Plugin URI header, so
     * it is not repeated. Any other plugin's row is returned untouched.
     */
    public function test_plugin_row_meta_adds_the_source_link_to_our_row_only() {
        global $wp_filters;
        $wp_filters = array();
        $this->admin_page->init();

        $registered = array_filter(
            $wp_filters['plugin_row_meta'] ?? array(),
            static function ( $hook ) {
                return 2 === $hook['accepted_args'] && 'plugin_row_meta' === ( $hook['callback'][1] ?? null );
            }
        );
        $this->assertCount( 1, $registered, 'plugin_row_meta must be registered once, with two accepted arguments' );

        $ours = $this->admin_page->plugin_row_meta( array( '<a href="#">View details</a>' ), plugin_basename( ASW_FILE ) );
        $this->assertSame(
            array(
                '<a href="#">View details</a>',
                '<a href="https://github.com/HawkeyePierce89/antispam-wizard" target="_blank" rel="noopener">Source code</a>',
            ),
            $ours
        );

        $other = array( '<a href="#">View details</a>' );
        $this->assertSame( $other, $this->admin_page->plugin_row_meta( $other, 'akismet/akismet.php' ) );
        $this->assertSame( 'not-an-array', $this->admin_page->plugin_row_meta( 'not-an-array', plugin_basename( ASW_FILE ) ) );
    }

    public function test_render_page_has_no_tab_navigation() {
        ob_start();
        $this->admin_page->render_page();
        $output = ob_get_clean();

        $this->assertStringNotContainsString( 'asw-tabs', $output );
        $this->assertStringNotContainsString( 'role="tablist"', $output );
        $this->assertStringContainsString( 'asw-page-content', $output );
    }

    public function test_admin_css_has_no_tab_styles() {
        $css = file_get_contents( ASW_PATH . 'admin/css/admin.css' );
        $this->assertStringNotContainsString( '.asw-tabs', $css );
        $this->assertStringNotContainsString( '.asw-tab ', $css );
        $this->assertStringNotContainsString( '.asw-tab.', $css );
        $this->assertStringNotContainsString( '.asw-tab:', $css );
        $this->assertStringContainsString( '.asw-page-content', $css );
    }

    public function test_admin_js_has_no_tab_switching() {
        $js = file_get_contents( ASW_PATH . 'admin/js/admin.js' );
        $this->assertStringNotContainsString( 'asw-tabs', $js );
        $this->assertStringNotContainsString( 'tab-switch', $js );
        $this->assertStringContainsString( 'asw_add_stop_word', $js );
        $this->assertStringContainsString( 'asw_mark_comment', $js );
    }

    public function test_render_page_general_tab_has_form() {
        $_GET['page'] = 'antispam-wizard';
        ob_start();
        $this->admin_page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( '<form', $output );
        $this->assertStringContainsString( 'honeypot_enabled', $output );
        $this->assertStringContainsString( 'submit_time_min_seconds', $output );
        $this->assertStringContainsString( 'links_max', $output );
        $this->assertStringContainsString( 'emoji_max', $output );
        $this->assertStringContainsString( 'duplicate_threshold', $output );
        $this->assertStringContainsString( 'min_message_length', $output );
    }

    public function test_handle_save_persists_bot_checks() {
        $_GET['page'] = 'antispam-wizard';
        $nonce = wp_create_nonce( ASW_Admin_Page::NONCE_ACTION );
        $_POST[ ASW_Admin_Page::NONCE_FIELD ] = $nonce;

        // Enabled and clamped values.
        $_POST['honeypot_enabled'] = '1';
        $_POST['submit_time_enabled'] = '1';
        $_POST['submit_time_min_seconds'] = '999'; // Above max

        // Satisfy other keys to avoid warnings, though defaults are loaded.
        $_POST['links_max'] = '2';
        $_POST['emoji_max'] = '4';
        $_POST['multi_lang_threshold'] = '3';
        $_POST['duplicate_threshold'] = '3';
        $_POST['duplicate_ttl'] = '3600';
        $_POST['spacing_min_words_count'] = '4';
        $_POST['spacing_short_word_len'] = '2';
        $_POST['min_message_length'] = '50';

        $this->admin_page->handle_save();

        $settings = ASW_Settings::get_all();
        $this->assertTrue( $settings['honeypot_enabled'] );
        $this->assertTrue( $settings['submit_time_enabled'] );
        $this->assertSame( 300, $settings['submit_time_min_seconds'] );

        // Uncheck toggles and clamp below min
        unset( $_POST['honeypot_enabled'] );
        unset( $_POST['submit_time_enabled'] );
        $_POST['submit_time_min_seconds'] = '-5';

        $this->admin_page->handle_save();
        $settings = ASW_Settings::get_all();
        $this->assertFalse( $settings['honeypot_enabled'] );
        $this->assertFalse( $settings['submit_time_enabled'] );
        $this->assertSame( 1, $settings['submit_time_min_seconds'] );
    }

    public function test_handle_save_bayesian_enabled_and_threshold() {
        global $wp_nonces;
        $_GET['page'] = 'antispam-wizard';
        $nonce = wp_create_nonce( ASW_Admin_Page::NONCE_ACTION );

        $_POST[ ASW_Admin_Page::NONCE_FIELD ] = $nonce;
        $_POST['bayesian_enabled']   = '1';
        $_POST['bayesian_threshold'] = '0.7';
        $_POST['links_max']     = '2';
        $_POST['emoji_max']     = '4';
        $_POST['multi_lang_threshold']  = '3';
        $_POST['duplicate_threshold']   = '3';
        $_POST['duplicate_ttl']         = '3600';
        $_POST['spacing_min_words_count'] = '4';
        $_POST['spacing_short_word_len']  = '2';
        $_POST['spacing_short_word_ratio'] = '0.5';
        $_POST['spacing_space_ratio']      = '0.3';
        $_POST['min_message_length']       = '40';

        $this->admin_page->handle_save();

        $settings = ASW_Settings::get_all();
        $this->assertTrue( $settings['bayesian_enabled'] );
        $this->assertSame( 0.7, $settings['bayesian_threshold'] );
        $this->assertSame( 40, $settings['min_message_length'] );
    }

    public function test_handle_save_bayesian_threshold_clamped_to_range() {
        global $wp_nonces;
        $_GET['page'] = 'antispam-wizard';
        $nonce = wp_create_nonce( ASW_Admin_Page::NONCE_ACTION );

        $_POST[ ASW_Admin_Page::NONCE_FIELD ] = $nonce;
        $_POST['bayesian_threshold'] = '0.01';
        $_POST['links_max']     = '2';
        $_POST['emoji_max']     = '4';
        $_POST['multi_lang_threshold']  = '3';
        $_POST['duplicate_threshold']   = '3';
        $_POST['duplicate_ttl']         = '3600';
        $_POST['spacing_min_words_count'] = '4';
        $_POST['spacing_short_word_len']  = '2';
        $_POST['spacing_short_word_ratio'] = '0.5';
        $_POST['spacing_space_ratio']      = '0.3';
        $_POST['min_message_length']       = '50';

        $this->admin_page->handle_save();

        $settings = ASW_Settings::get_all();
        $this->assertSame( 0.1, $settings['bayesian_threshold'] );
    }

    public function test_render_page_general_tab_has_bayesian_fields() {
        $_GET['page'] = 'antispam-wizard';
        ob_start();
        $this->admin_page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'bayesian_enabled', $output );
        $this->assertStringContainsString( 'bayesian_threshold', $output );
        $this->assertStringContainsString( 'Bayesian Filter', $output );
    }

    // --- Excluded Tokens tab tests ---

    public function test_render_page_outputs_excluded_tokens_page() {
        $_GET['page'] = 'antispam-wizard-excluded-tokens';

        ob_start();
        $this->admin_page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'asw-tokens-list', $output );
        $this->assertStringContainsString( 'asw-add-token', $output );
    }

    public function test_render_page_stop_words_page() {
        $_GET['page'] = 'antispam-wizard-stop-words';

        ob_start();
        $this->admin_page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'asw-words-list', $output );
        $this->assertStringContainsString( 'asw-add-word', $output );
    }

    public function test_ajax_add_excluded_token_success() {
        global $wp_json_responses;
        $wp_json_responses = array();

        $nonce = wp_create_nonce( ASW_Admin_Page::EXCLUDED_TOKENS_NONCE );
        $_POST['nonce'] = $nonce;
        $_POST['token'] = 'customtoken';

        $this->admin_page->ajax_add_excluded_token();

        $this->assertCount( 1, $wp_json_responses );
        $this->assertTrue( $wp_json_responses[0]['success'] );
        $this->assertContains( 'customtoken', ASW_Settings::get( 'excluded_tokens_list' ) );
    }

    public function test_ajax_add_excluded_token_duplicate() {
        global $wp_json_responses;
        $wp_json_responses = array();

        ASW_Settings::update( array( 'excluded_tokens_list' => array( 'mytoken' ) ) );

        $nonce = wp_create_nonce( ASW_Admin_Page::EXCLUDED_TOKENS_NONCE );
        $_POST['nonce'] = $nonce;
        $_POST['token'] = 'mytoken';

        $this->admin_page->ajax_add_excluded_token();

        $this->assertCount( 1, $wp_json_responses );
        $this->assertFalse( $wp_json_responses[0]['success'] );
        $this->assertStringContainsString( 'already exists', $wp_json_responses[0]['data']['message'] );
    }

    public function test_ajax_add_excluded_token_empty() {
        global $wp_json_responses;
        $wp_json_responses = array();

        $nonce = wp_create_nonce( ASW_Admin_Page::EXCLUDED_TOKENS_NONCE );
        $_POST['nonce'] = $nonce;
        $_POST['token'] = '';

        $this->admin_page->ajax_add_excluded_token();

        $this->assertCount( 1, $wp_json_responses );
        $this->assertFalse( $wp_json_responses[0]['success'] );
        $this->assertStringContainsString( 'cannot be empty', $wp_json_responses[0]['data']['message'] );
    }

    public function test_ajax_add_excluded_token_bad_nonce() {
        global $wp_json_responses;
        $wp_json_responses = array();

        $_POST['nonce'] = 'invalid';
        $_POST['token'] = 'the';

        $this->admin_page->ajax_add_excluded_token();

        $this->assertCount( 1, $wp_json_responses );
        $this->assertFalse( $wp_json_responses[0]['success'] );
        $this->assertStringContainsString( 'Security check', $wp_json_responses[0]['data']['message'] );
    }

    public function test_ajax_add_excluded_token_no_capability() {
        global $wp_json_responses, $wp_current_user_caps;
        $wp_json_responses = array();
        $wp_current_user_caps = array();

        $nonce = wp_create_nonce( ASW_Admin_Page::EXCLUDED_TOKENS_NONCE );
        $_POST['nonce'] = $nonce;
        $_POST['token'] = 'the';

        $this->admin_page->ajax_add_excluded_token();

        $this->assertCount( 1, $wp_json_responses );
        $this->assertFalse( $wp_json_responses[0]['success'] );
        $this->assertStringContainsString( 'Permission denied', $wp_json_responses[0]['data']['message'] );
    }

    public function test_ajax_remove_excluded_token_success() {
        global $wp_json_responses;
        $wp_json_responses = array();

        ASW_Settings::update( array( 'excluded_tokens_list' => array( 'foo', 'bar' ) ) );

        $nonce = wp_create_nonce( ASW_Admin_Page::EXCLUDED_TOKENS_NONCE );
        $_POST['nonce'] = $nonce;
        $_POST['token'] = 'foo';

        $this->admin_page->ajax_remove_excluded_token();

        $this->assertCount( 1, $wp_json_responses );
        $this->assertTrue( $wp_json_responses[0]['success'] );
        $list = ASW_Settings::get( 'excluded_tokens_list' );
        $this->assertNotContains( 'foo', $list );
        $this->assertContains( 'bar', $list );
    }

    public function test_ajax_remove_excluded_token_not_found() {
        global $wp_json_responses;
        $wp_json_responses = array();

        ASW_Settings::update( array( 'excluded_tokens_list' => array( 'foo' ) ) );

        $nonce = wp_create_nonce( ASW_Admin_Page::EXCLUDED_TOKENS_NONCE );
        $_POST['nonce'] = $nonce;
        $_POST['token'] = 'nonexistent';

        $this->admin_page->ajax_remove_excluded_token();

        $this->assertCount( 1, $wp_json_responses );
        $this->assertFalse( $wp_json_responses[0]['success'] );
        $this->assertStringContainsString( 'not found', $wp_json_responses[0]['data']['message'] );
    }

    public function test_ajax_remove_excluded_token_bad_nonce() {
        global $wp_json_responses;
        $wp_json_responses = array();

        $_POST['nonce'] = 'invalid';
        $_POST['token'] = 'the';

        $this->admin_page->ajax_remove_excluded_token();

        $this->assertCount( 1, $wp_json_responses );
        $this->assertFalse( $wp_json_responses[0]['success'] );
    }

    public function test_init_registers_excluded_token_ajax_hooks() {
        global $wp_actions;
        $wp_actions = array();

        $this->admin_page->init();

        $this->assertArrayHasKey( 'wp_ajax_asw_add_excluded_token', $wp_actions );
        $this->assertArrayHasKey( 'wp_ajax_asw_remove_excluded_token', $wp_actions );
    }

    public function test_enqueue_assets_includes_excluded_tokens_nonce() {
        global $wp_localized_scripts;

        $this->admin_page->add_menu_page();
        $this->admin_page->enqueue_assets( 'toplevel_page_antispam-wizard-main' );

        $this->assertArrayHasKey( 'asw-admin', $wp_localized_scripts );
        $data = $wp_localized_scripts['asw-admin']['data'];
        $this->assertArrayHasKey( 'excluded_tokens_nonce', $data );
        $this->assertArrayHasKey( 'no_tokens_text', $data );
    }

    // --- End excluded tokens AJAX tests ---

    // --- Excluded tokens import tests ---

    public function test_excluded_tokens_import_from_textarea() {
        ASW_Settings::update( array( 'excluded_tokens_list' => array() ) );
        $nonce = wp_create_nonce( 'asw_import_excluded_tokens' );

        $_POST['asw_import_excluded_tokens']       = '1';
        $_POST['asw_import_excluded_tokens_nonce'] = $nonce;
        $_POST['excluded_tokens_text']                        = "token1\ntoken2\ntoken3";

        $this->admin_page->handle_excluded_tokens_import();

        $list = ASW_Settings::get( 'excluded_tokens_list', array() );
        $this->assertCount( 3, $list );
        $this->assertContains( 'token1', $list );
        $this->assertContains( 'token2', $list );
        $this->assertContains( 'token3', $list );
    }

    public function test_excluded_tokens_import_merges_with_existing() {
        ASW_Settings::update( array( 'excluded_tokens_list' => array( 'existing' ) ) );

        $nonce = wp_create_nonce( 'asw_import_excluded_tokens' );
        $_POST['asw_import_excluded_tokens']       = '1';
        $_POST['asw_import_excluded_tokens_nonce'] = $nonce;
        $_POST['excluded_tokens_text']                        = "existing\nnewtoken";

        $this->admin_page->handle_excluded_tokens_import();

        $list = ASW_Settings::get( 'excluded_tokens_list', array() );
        $this->assertCount( 2, $list );
        $this->assertContains( 'existing', $list );
        $this->assertContains( 'newtoken', $list );
    }

    public function test_excluded_tokens_import_skips_empty_lines() {
        ASW_Settings::update( array( 'excluded_tokens_list' => array() ) );
        $nonce = wp_create_nonce( 'asw_import_excluded_tokens' );
        $_POST['asw_import_excluded_tokens']       = '1';
        $_POST['asw_import_excluded_tokens_nonce'] = $nonce;
        $_POST['excluded_tokens_text']                        = "token1\n\n\ntoken2\n  \n";

        $this->admin_page->handle_excluded_tokens_import();

        $list = ASW_Settings::get( 'excluded_tokens_list', array() );
        $this->assertCount( 2, $list );
    }

    public function test_excluded_tokens_import_without_nonce_does_nothing() {
        $defaults = ASW_Settings::get_defaults();
        $_POST['asw_import_excluded_tokens'] = '1';
        $_POST['excluded_tokens_text']                  = "token1\ntoken2";

        $this->admin_page->handle_excluded_tokens_import();

        $list = ASW_Settings::get( 'excluded_tokens_list', array() );
        $this->assertSame( $defaults['excluded_tokens_list'], $list );
    }

    public function test_excluded_tokens_import_without_capability_does_nothing() {
        global $wp_current_user_caps;
        $wp_current_user_caps = array();
        $defaults = ASW_Settings::get_defaults();

        $nonce = wp_create_nonce( 'asw_import_excluded_tokens' );
        $_POST['asw_import_excluded_tokens']       = '1';
        $_POST['asw_import_excluded_tokens_nonce'] = $nonce;
        $_POST['excluded_tokens_text']                        = "token1";

        $this->admin_page->handle_excluded_tokens_import();

        $list = ASW_Settings::get( 'excluded_tokens_list', array() );
        $this->assertSame( $defaults['excluded_tokens_list'], $list );
    }

    public function test_init_registers_excluded_tokens_import_export_hooks() {
        global $wp_actions;
        $wp_actions = array();

        $this->admin_page->init();

        $this->assertArrayHasKey( 'admin_init', $wp_actions );
    }

    // --- End excluded tokens import tests ---


    public function test_render_page_general_tab_has_help_descriptions() {
        $_GET['page'] = 'antispam-wizard';
        ob_start();
        $this->admin_page->render_page();
        $output = ob_get_clean();

        // All 9 setting cards should display their help text inline as the
        // card description (the text was previously inside collapsible details).
        $help_descriptions = array(
            'Adds a hidden decoy field to the comment form',
            'Measures how long the user took to submit the form',
            'Blocks comments containing words from your blacklist',
            'Flags comments with too many URLs',
            'Catches comments overloaded with emoji',
            'Detects obfuscated text where characters are separated by spaces',
            'Flags words that mix characters from different Unicode scripts',
            'Blocks repeated identical comments from the same IP',
            'Comments shorter than this skip the heavier Bayesian and Similarity checks',
            'Classifies comments using a Naive Bayes model',
            'Compares each comment against known spam samples using cosine similarity',
            'A comment the plugin flags as spam stays visible to the person who wrote it',
        );

        foreach ( $help_descriptions as $description ) {
            $this->assertStringContainsString( $description, $output, "Missing help description: $description" );
        }

        // All descriptions render as visible asw-check-desc elements in the cards
        // (11 check cards + 1 shadow display card + 1 Loaded Models description + 1 Form Integrations
        // description = 14 when CF7 is available). When CF7 is unavailable the Form Integrations card
        // is omitted, leaving 13.
        $count = substr_count( $output, 'class="asw-check-desc"' );
        if ( ASW_CF7_Integration::is_available() ) {
            $this->assertSame( 14, $count );
        } else {
            $this->assertSame( 13, $count );
        }
    }

    public function test_handle_save_redirects_with_settings_updated() {
        global $wp_redirects;

        $_GET['page'] = 'antispam-wizard';
        $nonce = wp_create_nonce( ASW_Admin_Page::NONCE_ACTION );
        $_POST[ ASW_Admin_Page::NONCE_FIELD ] = $nonce;
        $_POST['links_max']     = '2';
        $_POST['emoji_max']     = '4';
        $_POST['multi_lang_threshold']  = '3';
        $_POST['duplicate_threshold']   = '3';
        $_POST['duplicate_ttl']         = '3600';
        $_POST['spacing_min_words_count'] = '4';
        $_POST['spacing_short_word_len']  = '2';
        $_POST['spacing_short_word_ratio'] = '0.5';
        $_POST['spacing_space_ratio']      = '0.3';
        $_POST['min_message_length']       = '50';

        $this->admin_page->handle_save();

        $this->assertCount( 1, $wp_redirects );
        $this->assertStringContainsString( 'settings-updated=true', $wp_redirects[0]['location'] );
        $this->assertStringContainsString( 'page=antispam-wizard', $wp_redirects[0]['location'] );
        $this->assertStringContainsString( 'admin.php', $wp_redirects[0]['location'] );
    }

    public function test_handle_save_no_redirect_without_nonce() {
        global $wp_redirects;

        $_POST['links_max'] = '99';

        $this->admin_page->handle_save();

        $this->assertEmpty( $wp_redirects );
    }

    public function test_general_tab_shows_settings_saved_notice() {
        $_GET['page'] = 'antispam-wizard';
        $_GET['settings-updated'] = 'true';

        ob_start();
        $this->admin_page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'notice notice-success', $output );
        $this->assertStringContainsString( 'Settings saved.', $output );
    }

    public function test_general_tab_no_notice_without_parameter() {
        $_GET['page'] = 'antispam-wizard';
        ob_start();
        $this->admin_page->render_page();
        $output = ob_get_clean();

        $this->assertStringNotContainsString( 'notice notice-success', $output );
    }

    // --- Main page view tests ---

    public function test_render_main_page_contains_toggle_input() {
        $_GET['page'] = 'antispam-wizard-main';

        ob_start();
        $this->admin_page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'name="enabled"', $output );
        $this->assertStringContainsString( 'asw-toggle', $output );
    }

    public function test_render_main_page_contains_nonce_field() {
        $_GET['page'] = 'antispam-wizard-main';

        ob_start();
        $this->admin_page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( ASW_Admin_Page::NONCE_FIELD, $output );
    }

    public function test_render_main_page_contains_intro_text() {
        $_GET['page'] = 'antispam-wizard-main';

        ob_start();
        $this->admin_page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'asw-main-intro', $output );
        $this->assertStringContainsString( 'out of the box', $output );
    }

    public function test_render_main_page_contains_form_and_submit() {
        $_GET['page'] = 'antispam-wizard-main';

        ob_start();
        $this->admin_page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( '<form', $output );
        $this->assertStringContainsString( 'submit', $output );
    }

    public function test_render_main_page_shows_saved_notice() {
        $_GET['page'] = 'antispam-wizard-main';
        $_GET['settings-updated'] = 'true';

        ob_start();
        $this->admin_page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'notice notice-success', $output );
        $this->assertStringContainsString( 'Settings saved.', $output );
    }

    /**
     * The settings-updated flag is a core-supplied redirect marker. A hostile
     * value must neither trigger the notice nor reach the page unescaped: the
     * view unslashes and sanitizes it before the strict comparison.
     */
    public function test_render_main_page_hostile_settings_updated_shows_no_notice() {
        $_GET['page'] = 'antispam-wizard-main';
        $_GET['settings-updated'] = '"><script>alert(1)</script>';

        ob_start();
        $this->admin_page->render_page();
        $output = ob_get_clean();

        $this->assertStringNotContainsString( 'notice notice-success', $output );
        $this->assertStringNotContainsString( 'Settings saved.', $output );
        $this->assertStringNotContainsString( '<script>alert(1)</script>', $output );
    }

    public function test_render_general_page_shows_saved_notice() {
        $_GET['page'] = 'antispam-wizard';
        $_GET['settings-updated'] = 'true';

        ob_start();
        $this->admin_page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'notice notice-success', $output );
        $this->assertStringContainsString( 'Settings saved.', $output );
    }

    public function test_render_general_page_hostile_settings_updated_shows_no_notice() {
        $_GET['page'] = 'antispam-wizard';
        $_GET['settings-updated'] = '"><script>alert(1)</script>';

        ob_start();
        $this->admin_page->render_page();
        $output = ob_get_clean();

        $this->assertStringNotContainsString( 'notice notice-success', $output );
        $this->assertStringNotContainsString( 'Settings saved.', $output );
        $this->assertStringNotContainsString( '<script>alert(1)</script>', $output );
    }

    /**
     * WordPress hands $_GET over slashed; the view unslashes before comparing,
     * so a slashed flag value still fails the strict 'true' comparison.
     */
    public function test_render_main_page_slashed_settings_updated_shows_no_notice() {
        $_GET['page'] = 'antispam-wizard-main';
        $_GET['settings-updated'] = "\\'true\\'";

        ob_start();
        $this->admin_page->render_page();
        $output = ob_get_clean();

        $this->assertStringNotContainsString( 'notice notice-success', $output );
    }

    public function test_render_main_page_toggle_checked_when_enabled() {
        $_GET['page'] = 'antispam-wizard-main';
        ASW_Settings::update( array( 'enabled' => true ) );

        ob_start();
        $this->admin_page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'checked="checked"', $output );
    }

    public function test_render_main_page_toggle_unchecked_when_disabled() {
        $_GET['page'] = 'antispam-wizard-main';
        ASW_Settings::update( array( 'enabled' => false ) );

        ob_start();
        $this->admin_page->render_page();
        $output = ob_get_clean();

        $this->assertStringNotContainsString( 'checked="checked"', $output );
    }

    // --- Stop word validation tests ---

    public function test_ajax_add_stop_word_success() {
        global $wp_json_responses;
        $wp_json_responses = array();

        $nonce = wp_create_nonce( ASW_Admin_Page::AJAX_NONCE );
        $_POST['nonce'] = $nonce;
        $_POST['word']  = 'badword';

        $this->admin_page->ajax_add_stop_word();

        $this->assertCount( 1, $wp_json_responses );
        $this->assertTrue( $wp_json_responses[0]['success'] );
        $this->assertContains( 'badword', ASW_Settings::get( 'stop_words_list' ) );
    }

    public function test_ajax_add_stop_word_too_long() {
        global $wp_json_responses;
        $wp_json_responses = array();

        $nonce = wp_create_nonce( ASW_Admin_Page::AJAX_NONCE );
        $_POST['nonce'] = $nonce;
        $_POST['word']  = str_repeat( 'a', 65 );

        $this->admin_page->ajax_add_stop_word();

        $this->assertCount( 1, $wp_json_responses );
        $this->assertFalse( $wp_json_responses[0]['success'] );
        $this->assertStringContainsString( '64 characters', $wp_json_responses[0]['data']['message'] );
    }

    public function test_ajax_add_stop_word_exactly_64_chars_accepted() {
        global $wp_json_responses;
        $wp_json_responses = array();

        $nonce = wp_create_nonce( ASW_Admin_Page::AJAX_NONCE );
        $_POST['nonce'] = $nonce;
        $_POST['word']  = str_repeat( 'b', 64 );

        $this->admin_page->ajax_add_stop_word();

        $this->assertCount( 1, $wp_json_responses );
        $this->assertTrue( $wp_json_responses[0]['success'] );
    }

    public function test_ajax_add_stop_word_rejects_emoji() {
        global $wp_json_responses;
        $wp_json_responses = array();

        $nonce = wp_create_nonce( ASW_Admin_Page::AJAX_NONCE );
        $_POST['nonce'] = $nonce;
        $_POST['word']  = 'hello🔥';

        $this->admin_page->ajax_add_stop_word();

        $this->assertCount( 1, $wp_json_responses );
        $this->assertFalse( $wp_json_responses[0]['success'] );
        $this->assertStringContainsString( 'letters', $wp_json_responses[0]['data']['message'] );
    }

    public function test_ajax_add_stop_word_rejects_bare_spaces() {
        global $wp_json_responses;
        $wp_json_responses = array();

        $nonce = wp_create_nonce( ASW_Admin_Page::AJAX_NONCE );
        $_POST['nonce'] = $nonce;
        $_POST['word']  = 'two words';

        $this->admin_page->ajax_add_stop_word();

        $this->assertCount( 1, $wp_json_responses );
        $this->assertFalse( $wp_json_responses[0]['success'] );
        $this->assertStringContainsString( 'letters', $wp_json_responses[0]['data']['message'] );
    }

    public function test_ajax_add_stop_word_accepts_exact_match_prefix() {
        global $wp_json_responses;
        $wp_json_responses = array();

        $nonce = wp_create_nonce( ASW_Admin_Page::AJAX_NONCE );
        $_POST['nonce'] = $nonce;
        $_POST['word']  = '=test phrase';

        $this->admin_page->ajax_add_stop_word();

        $this->assertCount( 1, $wp_json_responses );
        $this->assertTrue( $wp_json_responses[0]['success'] );
        $this->assertContains( '=test phrase', ASW_Settings::get( 'stop_words_list' ) );
    }

    public function test_ajax_add_stop_word_rejects_emoji_in_exact_match() {
        global $wp_json_responses;
        $wp_json_responses = array();

        $nonce = wp_create_nonce( ASW_Admin_Page::AJAX_NONCE );
        $_POST['nonce'] = $nonce;
        $_POST['word']  = "=buy\xF0\x9F\x94\xA5cheap";

        $this->admin_page->ajax_add_stop_word();

        $this->assertCount( 1, $wp_json_responses );
        $this->assertFalse( $wp_json_responses[0]['success'] );
    }

    public function test_ajax_add_stop_word_accepts_unicode_letters() {
        global $wp_json_responses;
        $wp_json_responses = array();

        $nonce = wp_create_nonce( ASW_Admin_Page::AJAX_NONCE );
        $_POST['nonce'] = $nonce;
        $_POST['word']  = 'слово';

        $this->admin_page->ajax_add_stop_word();

        $this->assertCount( 1, $wp_json_responses );
        $this->assertTrue( $wp_json_responses[0]['success'] );
        $this->assertContains( 'слово', ASW_Settings::get( 'stop_words_list' ) );
    }

    // --- End stop word validation tests ---

    public function test_handle_save_clamps_duplicate_ttl_to_minimum_60() {
        global $wp_nonces;
        $_GET['page'] = 'antispam-wizard';
        $nonce = wp_create_nonce( ASW_Admin_Page::NONCE_ACTION );

        $_POST[ ASW_Admin_Page::NONCE_FIELD ] = $nonce;
        $_POST['duplicate_ttl'] = '10';
        $_POST['links_max']     = '2';
        $_POST['emoji_max']     = '4';
        $_POST['multi_lang_threshold']  = '3';
        $_POST['duplicate_threshold']   = '3';
        $_POST['spacing_min_words_count'] = '4';
        $_POST['spacing_short_word_len']  = '2';
        $_POST['min_message_length']      = '50';
        $_POST['spacing_short_word_ratio'] = '0.5';
        $_POST['spacing_space_ratio']      = '0.3';

        $this->admin_page->handle_save();

        $settings = ASW_Settings::get_all();
        $this->assertSame( 60, $settings['duplicate_ttl'] );
    }

    public function test_handle_save_with_slashed_nonce_succeeds() {
        $_GET['page'] = 'antispam-wizard';
        $slashed_nonce = wp_create_nonce( ASW_Admin_Page::NONCE_ACTION );

        $this->assertStringContainsString( '\\', $slashed_nonce );

        $_POST[ ASW_Admin_Page::NONCE_FIELD ] = $slashed_nonce;
        $_POST['links_max']     = '10';
        $_POST['emoji_max']     = '4';
        $_POST['multi_lang_threshold']  = '3';
        $_POST['duplicate_threshold']   = '3';
        $_POST['duplicate_ttl']         = '3600';
        $_POST['spacing_min_words_count'] = '4';
        $_POST['spacing_short_word_len']  = '2';
        $_POST['spacing_short_word_ratio'] = '0.5';
        $_POST['spacing_space_ratio']      = '0.3';
        $_POST['min_message_length']       = '50';

        $this->admin_page->handle_save();

        $settings = ASW_Settings::get_all();
        $this->assertSame( 10, $settings['links_max'] );
    }

    public function test_ajax_add_stop_word_with_slashed_nonce_succeeds() {
        global $wp_json_responses;
        $wp_json_responses = array();

        $slashed_nonce = wp_create_nonce( ASW_Admin_Page::AJAX_NONCE );
        $this->assertStringContainsString( '\\', $slashed_nonce );

        $_POST['nonce'] = $slashed_nonce;
        $_POST['word']  = 'testword';

        $this->admin_page->ajax_add_stop_word();

        $this->assertCount( 1, $wp_json_responses );
        $this->assertTrue( $wp_json_responses[0]['success'] );
    }

    public function test_ajax_add_excluded_token_with_slashed_nonce_succeeds() {
        global $wp_json_responses;
        $wp_json_responses = array();

        $slashed_nonce = wp_create_nonce( ASW_Admin_Page::EXCLUDED_TOKENS_NONCE );
        $this->assertStringContainsString( '\\', $slashed_nonce );

        $_POST['nonce'] = $slashed_nonce;
        $_POST['token'] = 'testtoken';

        $this->admin_page->ajax_add_excluded_token();

        $this->assertCount( 1, $wp_json_responses );
        $this->assertTrue( $wp_json_responses[0]['success'] );
    }

    public function test_handle_clear_log_with_slashed_nonce_succeeds() {
        // Seed the spam log so we can verify it gets cleared.
        ASW_Spam_Log::add_entry( 'test', array( 'stop_words' ), '127.0.0.1' );
        $this->assertNotEmpty( ASW_Spam_Log::get_entries() );

        $slashed_nonce = wp_create_nonce( 'asw_clear_log' );
        $this->assertStringContainsString( '\\', $slashed_nonce );

        $_POST['asw_clear_log']       = '1';
        $_POST['asw_clear_log_nonce'] = $slashed_nonce;

        $this->admin_page->handle_clear_log();

        $this->assertEmpty( ASW_Spam_Log::get_entries() );
    }

    public function test_handle_save_clamps_negative_to_min() {
        $_GET['page'] = 'antispam-wizard';
        $nonce = wp_create_nonce( ASW_Admin_Page::NONCE_ACTION );
        $_POST[ ASW_Admin_Page::NONCE_FIELD ] = $nonce;
        $_POST['links_max']     = '-5';
        $_POST['emoji_max']     = '4';
        $_POST['multi_lang_threshold']  = '3';
        $_POST['duplicate_threshold']   = '3';
        $_POST['duplicate_ttl'] = '3600';
        $_POST['spacing_min_words_count'] = '4';
        $_POST['spacing_short_word_len']  = '2';
        $_POST['min_message_length']      = '50';
        $_POST['spacing_short_word_ratio'] = '0.5';
        $_POST['spacing_space_ratio']      = '0.3';

        $this->admin_page->handle_save();

        $settings = ASW_Settings::get_all();
        $this->assertSame( 1, $settings['links_max'] );
    }

    public function test_stop_words_import_caps_at_max_entries() {
        ASW_Settings::update( array( 'stop_words_list' => array() ) );
        $nonce = wp_create_nonce( 'asw_import_stop_words' );

        // Generate 10005 unique words.
        $words = array();
        for ( $i = 0; $i < 10005; $i++ ) {
            $words[] = 'word' . $i;
        }

        $_POST['asw_import']       = '1';
        $_POST['asw_import_nonce'] = $nonce;
        $_POST['stop_words_text']             = implode( "\n", $words );

        $this->admin_page->handle_stop_words_import();

        $list = ASW_Settings::get( 'stop_words_list', array() );
        $this->assertCount( ASW_Admin_Page::IMPORT_MAX_ENTRIES, $list );
    }

    public function test_excluded_tokens_import_caps_at_max_entries() {
        ASW_Settings::update( array( 'excluded_tokens_list' => array() ) );
        $nonce = wp_create_nonce( 'asw_import_excluded_tokens' );

        // Generate 10005 unique tokens.
        $tokens = array();
        for ( $i = 0; $i < 10005; $i++ ) {
            $tokens[] = 'token' . $i;
        }

        $_POST['asw_import_excluded_tokens']       = '1';
        $_POST['asw_import_excluded_tokens_nonce'] = $nonce;
        $_POST['excluded_tokens_text']                        = implode( "\n", $tokens );

        $this->admin_page->handle_excluded_tokens_import();

        $list = ASW_Settings::get( 'excluded_tokens_list', array() );
        $this->assertCount( ASW_Admin_Page::IMPORT_MAX_ENTRIES, $list );
    }

    public function test_stop_words_import_under_limit_not_truncated() {
        ASW_Settings::update( array( 'stop_words_list' => array() ) );
        $nonce = wp_create_nonce( 'asw_import_stop_words' );

        $_POST['asw_import']       = '1';
        $_POST['asw_import_nonce'] = $nonce;
        $_POST['stop_words_text']             = "alpha\nbeta\ngamma";

        $this->admin_page->handle_stop_words_import();

        $list = ASW_Settings::get( 'stop_words_list', array() );
        $this->assertCount( 3, $list );
    }

    // --- Export handler tests ---

    public function test_stop_words_export_without_trigger_does_nothing() {
        // No asw_export in POST.
        ob_start();
        $this->admin_page->handle_stop_words_export();
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }

    public function test_stop_words_export_with_bad_nonce_does_nothing() {
        $_POST['asw_export']       = '1';
        $_POST['asw_export_nonce'] = 'invalid';

        ob_start();
        $this->admin_page->handle_stop_words_export();
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }

    public function test_stop_words_export_without_capability_does_nothing() {
        global $wp_current_user_caps;
        $wp_current_user_caps = array();

        $_POST['asw_export']       = '1';
        $_POST['asw_export_nonce'] = wp_create_nonce( 'asw_export_stop_words' );

        ob_start();
        $this->admin_page->handle_stop_words_export();
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }

    public function test_stop_words_export_outputs_list() {
        ASW_Settings::update( array( 'stop_words_list' => array( 'alpha', 'beta', 'gamma' ) ) );

        $_POST['asw_export']       = '1';
        $_POST['asw_export_nonce'] = wp_create_nonce( 'asw_export_stop_words' );

        // The handler calls the real header() function; under the PHPUnit CLI
        // SAPI headers are already sent, so swallow that one warning while still
        // exercising the body output.
        $output = $this->capture_export( array( $this->admin_page, 'handle_stop_words_export' ) );

        $this->assertSame( "alpha\nbeta\ngamma", $output );
    }

    public function test_excluded_tokens_export_without_trigger_does_nothing() {
        ob_start();
        $this->admin_page->handle_excluded_tokens_export();
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }

    public function test_excluded_tokens_export_with_bad_nonce_does_nothing() {
        $_POST['asw_export_excluded_tokens']       = '1';
        $_POST['asw_export_excluded_tokens_nonce'] = 'invalid';

        ob_start();
        $this->admin_page->handle_excluded_tokens_export();
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }

    public function test_excluded_tokens_export_without_capability_does_nothing() {
        global $wp_current_user_caps;
        $wp_current_user_caps = array();

        $_POST['asw_export_excluded_tokens']       = '1';
        $_POST['asw_export_excluded_tokens_nonce'] = wp_create_nonce( 'asw_export_excluded_tokens' );

        ob_start();
        $this->admin_page->handle_excluded_tokens_export();
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }

    public function test_excluded_tokens_export_outputs_list() {
        ASW_Settings::update( array( 'excluded_tokens_list' => array( 'foo', 'bar' ) ) );

        $_POST['asw_export_excluded_tokens']       = '1';
        $_POST['asw_export_excluded_tokens_nonce'] = wp_create_nonce( 'asw_export_excluded_tokens' );

        $output = $this->capture_export( array( $this->admin_page, 'handle_excluded_tokens_export' ) );

        $this->assertSame( "foo\nbar", $output );
    }

    /**
     * Invoke an export handler, capturing its echoed body while suppressing the
     * "headers already sent" warning the real header() call emits under the
     * PHPUnit CLI SAPI (where output has already started).
     *
     * @param callable $handler The export handler to call.
     * @return string Captured output body.
     */
    private function capture_export( callable $handler ): string {
        set_error_handler( function ( $errno, $errstr ) {
            if ( false !== strpos( $errstr, 'headers already sent' )
                || false !== strpos( $errstr, 'Cannot modify header information' ) ) {
                return true;
            }
            return false;
        } );

        ob_start();
        try {
            $handler();
        } finally {
            $output = ob_get_clean();
            restore_error_handler();
        }

        return $output;
    }

    // --- End export handler tests ---

    public function test_handle_save_clamps_oversized_to_max() {
        $_GET['page'] = 'antispam-wizard';
        $nonce = wp_create_nonce( ASW_Admin_Page::NONCE_ACTION );
        $_POST[ ASW_Admin_Page::NONCE_FIELD ] = $nonce;
        $_POST['links_max']     = '99999';
        $_POST['emoji_max']     = '99999';
        $_POST['multi_lang_threshold']  = '3';
        $_POST['duplicate_threshold']   = '3';
        $_POST['duplicate_ttl'] = '3600';
        $_POST['spacing_min_words_count'] = '4';
        $_POST['spacing_short_word_len']  = '2';
        $_POST['min_message_length']      = '50';
        $_POST['spacing_short_word_ratio'] = '0.5';
        $_POST['spacing_space_ratio']      = '0.3';

        $this->admin_page->handle_save();

        $settings = ASW_Settings::get_all();
        $this->assertSame( 50, $settings['links_max'] );
        $this->assertSame( 100, $settings['emoji_max'] );
    }

    // --- CF7 integration admin tests ---

    public function test_handle_save_persists_cf7_enabled_when_available(): void {
        // Ensure CF7 is available (default).
        unset( $GLOBALS['wp_cf7_is_available'] );
        $this->assertTrue( ASW_CF7_Integration::is_available() );

        $_GET['page'] = 'antispam-wizard';
        $nonce = wp_create_nonce( ASW_Admin_Page::NONCE_ACTION );
        $_POST[ ASW_Admin_Page::NONCE_FIELD ] = $nonce;
        $_POST['cf7_enabled'] = '1';
        $_POST['links_max']     = '2';
        $_POST['emoji_max']     = '4';
        $_POST['multi_lang_threshold']  = '3';
        $_POST['duplicate_threshold']   = '3';
        $_POST['duplicate_ttl']         = '3600';
        $_POST['spacing_min_words_count'] = '4';
        $_POST['spacing_short_word_len']  = '2';
        $_POST['spacing_short_word_ratio'] = '0.5';
        $_POST['spacing_space_ratio']      = '0.3';
        $_POST['min_message_length']       = '50';

        $this->admin_page->handle_save();
        $this->assertTrue( ASW_Settings::get( 'cf7_enabled' ) );

        // Unchecked should become false.
        $_GET['page'] = 'antispam-wizard';
        $nonce = wp_create_nonce( ASW_Admin_Page::NONCE_ACTION );
        $_POST = array();
        $_POST[ ASW_Admin_Page::NONCE_FIELD ] = $nonce;
        $_POST['links_max']     = '2';
        $_POST['emoji_max']     = '4';
        $_POST['multi_lang_threshold']  = '3';
        $_POST['duplicate_threshold']   = '3';
        $_POST['duplicate_ttl']         = '3600';
        $_POST['spacing_min_words_count'] = '4';
        $_POST['spacing_short_word_len']  = '2';
        $_POST['spacing_short_word_ratio'] = '0.5';
        $_POST['spacing_space_ratio']      = '0.3';
        $_POST['min_message_length']       = '50';

        $this->admin_page->handle_save();
        $this->assertFalse( ASW_Settings::get( 'cf7_enabled' ) );
    }

    public function test_handle_save_leaves_cf7_enabled_untouched_when_unavailable(): void {
        // Start with known value true.
        ASW_Settings::update( array( 'cf7_enabled' => true ) );
        $GLOBALS['wp_cf7_is_available'] = false;
        $this->assertFalse( ASW_CF7_Integration::is_available() );

        $_GET['page'] = 'antispam-wizard';
        $nonce = wp_create_nonce( ASW_Admin_Page::NONCE_ACTION );
        $_POST = array();
        $_POST[ ASW_Admin_Page::NONCE_FIELD ] = $nonce;
        // Do not send cf7_enabled (would be absent from form when CF7 unavailable)
        $_POST['links_max']     = '2';
        $_POST['emoji_max']     = '4';
        $_POST['multi_lang_threshold']  = '3';
        $_POST['duplicate_threshold']   = '3';
        $_POST['duplicate_ttl']         = '3600';
        $_POST['spacing_min_words_count'] = '4';
        $_POST['spacing_short_word_len']  = '2';
        $_POST['spacing_short_word_ratio'] = '0.5';
        $_POST['spacing_space_ratio']      = '0.3';
        $_POST['min_message_length']       = '50';

        $this->admin_page->handle_save();
        // Should remain true because the toggle was not part of bool_keys.
        $this->assertTrue( ASW_Settings::get( 'cf7_enabled' ) );

        // Also verify that even if attacker injects cf7_enabled, it does not flip to false
        // when CF7 is unavailable (the key is not processed).
        ASW_Settings::update( array( 'cf7_enabled' => true ) );
        $_POST['cf7_enabled'] = '1';
        $nonce = wp_create_nonce( ASW_Admin_Page::NONCE_ACTION );
        $_POST[ ASW_Admin_Page::NONCE_FIELD ] = $nonce;
        $this->admin_page->handle_save();
        $this->assertTrue( ASW_Settings::get( 'cf7_enabled' ) );

        unset( $GLOBALS['wp_cf7_is_available'] );
    }

    public function test_general_view_omits_cf7_card_when_unavailable(): void {
        $GLOBALS['wp_cf7_is_available'] = false;
        $this->assertFalse( ASW_CF7_Integration::is_available() );

        $_GET['page'] = 'antispam-wizard';
        ob_start();
        $this->admin_page->render_page();
        $output = ob_get_clean();

        $this->assertStringNotContainsString( 'Form Integrations', $output );
        $this->assertStringNotContainsString( 'cf7_enabled', $output );

        unset( $GLOBALS['wp_cf7_is_available'] );
    }

    public function test_general_view_renders_cf7_card_when_available(): void {
        unset( $GLOBALS['wp_cf7_is_available'] );
        $this->assertTrue( ASW_CF7_Integration::is_available() );

        $_GET['page'] = 'antispam-wizard';
        ob_start();
        $this->admin_page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Form Integrations', $output );
        $this->assertStringContainsString( 'Contact Form 7', $output );
        $this->assertStringContainsString( 'cf7_enabled', $output );
        $this->assertStringContainsString( 'All enabled checks run on Contact Form 7 submissions', $output );
    }

    // --- Shadow display toggle ---

    public function test_general_view_renders_shadow_display_toggle_checked_by_default(): void {
        $_GET['page'] = 'antispam-wizard';
        ob_start();
        $this->admin_page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Show flagged comments to their author', $output );
        $this->assertMatchesRegularExpression(
            '/<input type="checkbox" name="shadow_display_enabled" value="1"\s+checked="checked">/',
            $output
        );
    }

    public function test_general_view_renders_shadow_display_toggle_unchecked_when_off(): void {
        ASW_Settings::update( array( 'shadow_display_enabled' => false ) );

        $_GET['page'] = 'antispam-wizard';
        ob_start();
        $this->admin_page->render_page();
        $output = ob_get_clean();

        $this->assertMatchesRegularExpression(
            '/<input type="checkbox" name="shadow_display_enabled" value="1"\s*>/',
            $output
        );
    }

    public function test_handle_save_persists_shadow_display_enabled(): void {
        $post = array(
            'links_max'                => '2',
            'emoji_max'                => '4',
            'multi_lang_threshold'     => '3',
            'duplicate_threshold'      => '3',
            'duplicate_ttl'            => '3600',
            'spacing_min_words_count'  => '4',
            'spacing_short_word_len'   => '2',
            'spacing_short_word_ratio' => '0.5',
            'spacing_space_ratio'      => '0.3',
            'min_message_length'       => '50',
        );

        // A save without the field (checkbox unticked) stores false.
        $_GET['page'] = 'antispam-wizard';
        $_POST        = $post;
        $_POST[ ASW_Admin_Page::NONCE_FIELD ] = wp_create_nonce( ASW_Admin_Page::NONCE_ACTION );
        $this->admin_page->handle_save();
        $this->assertFalse( ASW_Settings::get( 'shadow_display_enabled' ) );

        // A save with it stores true.
        $_GET['page'] = 'antispam-wizard';
        $_POST        = $post;
        $_POST['shadow_display_enabled']      = '1';
        $_POST[ ASW_Admin_Page::NONCE_FIELD ] = wp_create_nonce( ASW_Admin_Page::NONCE_ACTION );
        $this->admin_page->handle_save();
        $this->assertTrue( ASW_Settings::get( 'shadow_display_enabled' ) );
    }
}
