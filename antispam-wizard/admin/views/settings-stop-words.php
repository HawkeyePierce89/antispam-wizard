<?php
/**
 * Stop-words settings tab view.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$settings   = ANTISPAMWIZARD_Settings::get_all();
$stop_words = $settings['stop_words_list'];
?>

<div class="antispamwizard-card">
    <h3 class="antispamwizard-card-title"><?php esc_html_e( 'Add New Word', 'antispam-wizard' ); ?></h3>
    <p class="antispamwizard-card-description"><?php esc_html_e( 'Words prefixed with "=" require an exact match. Otherwise, substring matching is used.', 'antispam-wizard' ); ?></p>
    <div class="antispamwizard-add-row">
        <input type="text" id="antispamwizard-new-word" placeholder="<?php esc_attr_e( 'Type a word to block...', 'antispam-wizard' ); ?>">
        <button type="button" id="antispamwizard-add-word" class="antispamwizard-button"><?php esc_html_e( 'Add Word', 'antispam-wizard' ); ?></button>
    </div>
    <span id="antispamwizard-add-status" class="antispamwizard-status"></span>
</div>

<div class="antispamwizard-card">
    <h3 class="antispamwizard-card-title"><?php esc_html_e( 'Blocked Words', 'antispam-wizard' ); ?></h3>
    <div id="antispamwizard-words-list" class="antispamwizard-pill-cloud">
        <?php if ( empty( $stop_words ) ) : ?>
            <p class="antispamwizard-empty antispamwizard-no-words"><?php esc_html_e( 'No stop-words configured.', 'antispam-wizard' ); ?></p>
        <?php else : ?>
            <?php foreach ( $stop_words as $word ) : ?>
                <?php $is_exact = isset( $word[0] ) && '=' === $word[0]; ?>
                <span class="antispamwizard-word-tag antispamwizard-pill <?php echo $is_exact ? 'antispamwizard-pill--exact' : ''; ?>" data-word="<?php echo esc_attr( $word ); ?>">
                    <?php echo esc_html( $word ); ?>
                    <button type="button" class="antispamwizard-remove-word antispamwizard-pill-remove" title="<?php esc_attr_e( 'Remove', 'antispam-wizard' ); ?>">&times;</button>
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
                <?php wp_nonce_field( 'antispamwizard_import_stop_words', 'antispamwizard_import_nonce' ); ?>
                <div class="antispamwizard-form-row">
                    <label for="antispamwizard-import-file"><?php esc_html_e( 'Import from file (one word per line):', 'antispam-wizard' ); ?></label>
                    <input type="file" id="antispamwizard-import-file" name="stop_words_file" accept=".txt">
                </div>
                <div class="antispamwizard-form-row">
                    <label for="antispamwizard-import-textarea"><?php esc_html_e( 'Or paste words (one per line):', 'antispam-wizard' ); ?></label>
                    <textarea id="antispamwizard-import-textarea" name="stop_words_text" rows="5"></textarea>
                </div>
                <div class="antispamwizard-form-row">
                    <button type="submit" name="antispamwizard_import" class="antispamwizard-button antispamwizard-button--secondary"><?php esc_html_e( 'Import', 'antispam-wizard' ); ?></button>
                </div>
            </form>

            <form method="post" style="margin-top:16px;">
                <?php wp_nonce_field( 'antispamwizard_export_stop_words', 'antispamwizard_export_nonce' ); ?>
                <button type="submit" name="antispamwizard_export" class="antispamwizard-button antispamwizard-button--secondary"><?php esc_html_e( 'Export as Text File', 'antispam-wizard' ); ?></button>
            </form>
        </div>
    </div>
</section>
