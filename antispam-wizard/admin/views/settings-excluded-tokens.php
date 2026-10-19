<?php
/**
 * Excluded tokens settings tab view.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$settings        = ASW_Settings::get_all();
$excluded_tokens = $settings['excluded_tokens_list'];
?>

<div class="asw-card">
    <h3 class="asw-card-title"><?php esc_html_e( 'Add Token', 'antispam-wizard' ); ?></h3>
    <p class="asw-card-description"><?php esc_html_e( 'Tokens listed here are excluded during tokenization for Bayesian and Similarity checks. These are typically common words (stop-words) that do not contribute to spam detection.', 'antispam-wizard' ); ?></p>
    <div class="asw-add-row">
        <input type="text" id="asw-new-token" placeholder="<?php esc_attr_e( 'Add a token to exclude...', 'antispam-wizard' ); ?>">
        <button type="button" id="asw-add-token" class="asw-button"><?php esc_html_e( 'Add Token', 'antispam-wizard' ); ?></button>
    </div>
    <span id="asw-token-status" class="asw-status"></span>
</div>

<div class="asw-card">
    <h3 class="asw-card-title"><?php esc_html_e( 'Token Cloud', 'antispam-wizard' ); ?></h3>
    <div id="asw-tokens-list" class="asw-pill-cloud">
        <?php if ( empty( $excluded_tokens ) ) : ?>
            <p class="asw-empty asw-no-tokens"><?php esc_html_e( 'No excluded tokens configured.', 'antispam-wizard' ); ?></p>
        <?php else : ?>
            <?php foreach ( $excluded_tokens as $token ) : ?>
                <span class="asw-word-tag asw-pill asw-pill--token" data-word="<?php echo esc_attr( $token ); ?>">
                    <?php echo esc_html( $token ); ?>
                    <button type="button" class="asw-remove-token asw-pill-remove" title="<?php esc_attr_e( 'Remove', 'antispam-wizard' ); ?>">&times;</button>
                </span>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<section class="asw-section asw-import-export">
    <button type="button" class="asw-section-header" data-asw-collapsible aria-expanded="true">
        <span class="asw-section-chevron" aria-hidden="true">▾</span>
        <span class="asw-section-title"><?php esc_html_e( 'Import / Export', 'antispam-wizard' ); ?></span>
    </button>
    <div class="asw-section-body">
        <div class="asw-card">
            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field( 'asw_import_excluded_tokens', 'asw_import_excluded_tokens_nonce' ); ?>
                <div class="asw-form-row">
                    <label for="asw-import-tokens-file"><?php esc_html_e( 'Import from file (one token per line):', 'antispam-wizard' ); ?></label>
                    <input type="file" id="asw-import-tokens-file" name="excluded_tokens_file" accept=".txt">
                </div>
                <div class="asw-form-row">
                    <label for="asw-import-tokens-textarea"><?php esc_html_e( 'Or paste tokens (one per line):', 'antispam-wizard' ); ?></label>
                    <textarea id="asw-import-tokens-textarea" name="excluded_tokens_text" rows="5"></textarea>
                </div>
                <div class="asw-form-row">
                    <button type="submit" name="asw_import_excluded_tokens" class="asw-button asw-button--secondary"><?php esc_html_e( 'Import', 'antispam-wizard' ); ?></button>
                </div>
            </form>

            <form method="post" style="margin-top:16px;">
                <?php wp_nonce_field( 'asw_export_excluded_tokens', 'asw_export_excluded_tokens_nonce' ); ?>
                <button type="submit" name="asw_export_excluded_tokens" class="asw-button asw-button--secondary"><?php esc_html_e( 'Export as Text File', 'antispam-wizard' ); ?></button>
            </form>
        </div>
    </div>
</section>
