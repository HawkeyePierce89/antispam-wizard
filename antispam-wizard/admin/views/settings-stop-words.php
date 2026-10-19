<?php
/**
 * Stop-words settings tab view.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$settings   = ASW_Settings::get_all();
$stop_words = $settings['stop_words_list'];
?>

<div class="asw-card">
    <h3 class="asw-card-title"><?php esc_html_e( 'Add New Word', 'antispam-wizard' ); ?></h3>
    <p class="asw-card-description"><?php esc_html_e( 'Words prefixed with "=" require an exact match. Otherwise, substring matching is used.', 'antispam-wizard' ); ?></p>
    <div class="asw-add-row">
        <input type="text" id="asw-new-word" placeholder="<?php esc_attr_e( 'Type a word to block...', 'antispam-wizard' ); ?>">
        <button type="button" id="asw-add-word" class="asw-button"><?php esc_html_e( 'Add Word', 'antispam-wizard' ); ?></button>
    </div>
    <span id="asw-add-status" class="asw-status"></span>
</div>

<div class="asw-card">
    <h3 class="asw-card-title"><?php esc_html_e( 'Blocked Words', 'antispam-wizard' ); ?></h3>
    <div id="asw-words-list" class="asw-pill-cloud">
        <?php if ( empty( $stop_words ) ) : ?>
            <p class="asw-empty asw-no-words"><?php esc_html_e( 'No stop-words configured.', 'antispam-wizard' ); ?></p>
        <?php else : ?>
            <?php foreach ( $stop_words as $word ) : ?>
                <?php $is_exact = isset( $word[0] ) && '=' === $word[0]; ?>
                <span class="asw-word-tag asw-pill <?php echo $is_exact ? 'asw-pill--exact' : ''; ?>" data-word="<?php echo esc_attr( $word ); ?>">
                    <?php echo esc_html( $word ); ?>
                    <button type="button" class="asw-remove-word asw-pill-remove" title="<?php esc_attr_e( 'Remove', 'antispam-wizard' ); ?>">&times;</button>
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
                <?php wp_nonce_field( 'asw_import_stop_words', 'asw_import_nonce' ); ?>
                <div class="asw-form-row">
                    <label for="asw-import-file"><?php esc_html_e( 'Import from file (one word per line):', 'antispam-wizard' ); ?></label>
                    <input type="file" id="asw-import-file" name="stop_words_file" accept=".txt">
                </div>
                <div class="asw-form-row">
                    <label for="asw-import-textarea"><?php esc_html_e( 'Or paste words (one per line):', 'antispam-wizard' ); ?></label>
                    <textarea id="asw-import-textarea" name="stop_words_text" rows="5"></textarea>
                </div>
                <div class="asw-form-row">
                    <button type="submit" name="asw_import" class="asw-button asw-button--secondary"><?php esc_html_e( 'Import', 'antispam-wizard' ); ?></button>
                </div>
            </form>

            <form method="post" style="margin-top:16px;">
                <?php wp_nonce_field( 'asw_export_stop_words', 'asw_export_nonce' ); ?>
                <button type="submit" name="asw_export" class="asw-button asw-button--secondary"><?php esc_html_e( 'Export as Text File', 'antispam-wizard' ); ?></button>
            </form>
        </div>
    </div>
</section>
