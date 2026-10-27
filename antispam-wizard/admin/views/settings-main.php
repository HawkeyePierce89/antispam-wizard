<?php
/**
 * Main settings page view.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$settings = ANTISPAMWIZARD_Settings::get_all();

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of the redirect flag core appends after options.php saves; nothing is written here.
$antispamwizard_settings_updated = isset( $_GET['settings-updated'] ) ? sanitize_text_field( wp_unslash( $_GET['settings-updated'] ) ) : '';

if ( 'true' === $antispamwizard_settings_updated ) {
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'antispam-wizard' ) . '</p></div>';
}
?>
<div class="antispamwizard-main-intro">
    <p><?php esc_html_e( 'AntiSpam Wizard is ready to protect your site out of the box. Fine-tune individual checks and thresholds on the other settings pages.', 'antispam-wizard' ); ?></p>
</div>

<form method="post" action="">
    <?php wp_nonce_field( ANTISPAMWIZARD_Admin_Page::NONCE_ACTION, ANTISPAMWIZARD_Admin_Page::NONCE_FIELD ); ?>

    <div class="antispamwizard-card antispamwizard-check-card">
        <div class="antispamwizard-check-head">
            <span class="antispamwizard-check-title"><?php esc_html_e( 'Spam Protection', 'antispam-wizard' ); ?></span>
        </div>
        <p class="antispamwizard-check-desc"><?php esc_html_e( 'Enable or disable all spam checks globally. When disabled, all comments pass through without filtering.', 'antispam-wizard' ); ?></p>
        <div class="antispamwizard-toggle-cell">
            <label class="antispamwizard-toggle">
                <input type="checkbox" name="enabled" value="1" <?php checked( (bool) $settings['enabled'] ); ?>>
                <span class="antispamwizard-toggle-track"></span>
            </label>
        </div>
    </div>

    <div class="antispamwizard-form-footer">
        <?php submit_button( __( 'Save Changes', 'antispam-wizard' ), 'primary', 'submit', false ); ?>
    </div>
</form>
