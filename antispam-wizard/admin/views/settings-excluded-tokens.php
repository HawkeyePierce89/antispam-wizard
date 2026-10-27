<?php
/**
 * Excluded tokens settings tab view.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$settings        = ANTISPAMWIZARD_Settings::get_all();
$excluded_tokens = $settings['excluded_tokens_list'];
?>

<div class="antispamwizard-card">
    <h3 class="antispamwizard-card-title"><?php esc_html_e( 'Add Token', 'antispam-wizard' ); ?></h3>
    <p class="antispamwizard-card-description"><?php esc_html_e( 'Tokens listed here are excluded during tokenization for Bayesian and Similarity checks. These are typically common words (stop-words) that do not contribute to spam detection.', 'antispam-wizard' ); ?></p>
    <div class="antispamwizard-add-row">
        <input type="text" id="antispamwizard-new-token" placeholder="<?php esc_attr_e( 'Add a token to exclude...', 'antispam-wizard' ); ?>">
        <button type="button" id="antispamwizard-add-token" class="antispamwizard-button"><?php esc_html_e( 'Add Token', 'antispam-wizard' ); ?></button>
    </div>
    <span id="antispamwizard-token-status" class="antispamwizard-status"></span>
</div>

<div class="antispamwizard-card">
    <h3 class="antispamwizard-card-title"><?php esc_html_e( 'Token Cloud', 'antispam-wizard' ); ?></h3>
    <div id="antispamwizard-tokens-list" class="antispamwizard-pill-cloud">
        <?php if ( empty( $excluded_tokens ) ) : ?>
            <p class="antispamwizard-empty antispamwizard-no-tokens"><?php esc_html_e( 'No excluded tokens configured.', 'antispam-wizard' ); ?></p>
        <?php else : ?>
            <?php foreach ( $excluded_tokens as $token ) : ?>
                <span class="antispamwizard-word-tag antispamwizard-pill antispamwizard-pill--token" data-word="<?php echo esc_attr( $token ); ?>">
                    <?php echo esc_html( $token ); ?>
                    <button type="button" class="antispamwizard-remove-token antispamwizard-pill-remove" title="<?php esc_attr_e( 'Remove', 'antispam-wizard' ); ?>">&times;</button>
                </span>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<section class="antispamwizard-section antispamwizard-import-export">
    <button type="button" class="antispamwizard-section-header" data-antispamwizard-collapsible aria-expanded="true">
        <span class="antispamwizard-section-chevron" aria-hidden="true">▾</span>
        <span class="antispamwizard-section-title"><?php esc_html_e( 'Import / Export', 'antispam-wizard' ); ?></span>
    </button>
    <div class="antispamwizard-section-body">
        <div class="antispamwizard-card">
            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field( 'antispamwizard_import_excluded_tokens', 'antispamwizard_import_excluded_tokens_nonce' ); ?>
                <div class="antispamwizard-form-row">
                    <label for="antispamwizard-import-tokens-file"><?php esc_html_e( 'Import from file (one token per line):', 'antispam-wizard' ); ?></label>
                    <input type="file" id="antispamwizard-import-tokens-file" name="excluded_tokens_file" accept=".txt">
                </div>
                <div class="antispamwizard-form-row">
                    <label for="antispamwizard-import-tokens-textarea"><?php esc_html_e( 'Or paste tokens (one per line):', 'antispam-wizard' ); ?></label>
                    <textarea id="antispamwizard-import-tokens-textarea" name="excluded_tokens_text" rows="5"></textarea>
                </div>
                <div class="antispamwizard-form-row">
                    <button type="submit" name="antispamwizard_import_excluded_tokens" class="antispamwizard-button antispamwizard-button--secondary"><?php esc_html_e( 'Import', 'antispam-wizard' ); ?></button>
                </div>
            </form>

            <form method="post" style="margin-top:16px;">
                <?php wp_nonce_field( 'antispamwizard_export_excluded_tokens', 'antispamwizard_export_excluded_tokens_nonce' ); ?>
                <button type="submit" name="antispamwizard_export_excluded_tokens" class="antispamwizard-button antispamwizard-button--secondary"><?php esc_html_e( 'Export as Text File', 'antispam-wizard' ); ?></button>
            </form>
        </div>
    </div>
</section>
