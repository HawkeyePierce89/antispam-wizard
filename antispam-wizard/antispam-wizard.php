<?php
/**
 * Plugin Name: AntiSpam Wizard
 * Plugin URI:  https://antispamwizard.com/
 * Description: Offline-first comment spam protection for WordPress with multiple configurable detection checks.
 * Version:     1.0.0
 * Author:      Anton Karmanov
 * Author URI:  https://github.com/HawkeyePierce89
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: antispam-wizard
 * Domain Path: /languages
 * Requires PHP: 8.1
 * Requires at least: 6.4
 * Tested up to: 7.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants.
define( 'ASW_VERSION', '1.0.0' );
define( 'ASW_FILE', __FILE__ );
define( 'ASW_PATH', plugin_dir_path( __FILE__ ) );
define( 'ASW_URL', plugin_dir_url( __FILE__ ) );

// Autoload includes.
require_once ASW_PATH . 'includes/interface-check.php';
require_once ASW_PATH . 'includes/class-check-result.php';
require_once ASW_PATH . 'includes/class-settings.php';
require_once ASW_PATH . 'includes/class-detector.php';
require_once ASW_PATH . 'includes/class-spam-log.php';
require_once ASW_PATH . 'includes/class-stats.php';
require_once ASW_PATH . 'includes/class-tokenizer.php';
require_once ASW_PATH . 'includes/class-cosine-similarity.php';
require_once ASW_PATH . 'includes/class-naive-bayes.php';
require_once ASW_PATH . 'includes/class-training-data.php';
require_once ASW_PATH . 'includes/class-script-detector.php';
require_once ASW_PATH . 'includes/class-sample-repository.php';
require_once ASW_PATH . 'includes/class-model-store.php';
require_once ASW_PATH . 'includes/class-privacy.php';
require_once ASW_PATH . 'includes/class-moderation.php';
require_once ASW_PATH . 'includes/class-shadow-display.php';
require_once ASW_PATH . 'includes/class-comment-form.php';
require_once ASW_PATH . 'includes/class-cf7-integration.php';
require_once ASW_PATH . 'includes/class-antispam-wizard.php';
require_once ASW_PATH . 'includes/checks/class-honeypot-check.php';
require_once ASW_PATH . 'includes/checks/class-submit-time-check.php';
require_once ASW_PATH . 'includes/checks/class-stop-words-check.php';
require_once ASW_PATH . 'includes/checks/class-links-check.php';
require_once ASW_PATH . 'includes/checks/class-emoji-check.php';
require_once ASW_PATH . 'includes/checks/class-spacing-check.php';
require_once ASW_PATH . 'includes/checks/class-multi-lang-check.php';
require_once ASW_PATH . 'includes/checks/class-duplicate-check.php';
require_once ASW_PATH . 'includes/checks/class-bayesian-check.php';
require_once ASW_PATH . 'includes/checks/class-similarity-check.php';
require_once ASW_PATH . 'admin/class-admin-page.php';

// Activation and deactivation hooks.
register_activation_hook( __FILE__, array( 'ASW', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ASW', 'deactivate' ) );

// Initialize the plugin.
ASW::get_instance()->init();
