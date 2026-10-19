<?php
/**
 * General settings tab view.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$settings = ASW_Settings::get_all();

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of the redirect flag core appends after options.php saves; nothing is written here.
$asw_settings_updated = isset( $_GET['settings-updated'] ) ? sanitize_text_field( wp_unslash( $_GET['settings-updated'] ) ) : '';

if ( 'true' === $asw_settings_updated ) {
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'antispam-wizard' ) . '</p></div>';
}

/**
 * Render a styled toggle (checkbox) cell.
 */
$render_toggle = function ( string $name, bool $checked ): void {
    ?>
    <label class="asw-toggle">
        <input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $checked ); ?>>
        <span class="asw-toggle-track"></span>
    </label>
    <?php
};
?>
<form method="post" action="">
    <?php wp_nonce_field( ASW_Admin_Page::NONCE_ACTION, ASW_Admin_Page::NONCE_FIELD ); ?>

    <section class="asw-section is-open" data-asw-section>
        <button type="button" class="asw-section-header" data-asw-collapsible aria-expanded="true">
            <span class="asw-section-chevron" aria-hidden="true">▾</span>
            <span class="asw-section-title"><?php esc_html_e( 'Bot Checks', 'antispam-wizard' ); ?></span>
        </button>
        <div class="asw-section-body">

            <!-- Honeypot -->
            <div class="asw-card asw-check-card">
                <div class="asw-check-head">
                    <span class="asw-badge asw-badge--honeypot"><?php esc_html_e( 'Honeypot', 'antispam-wizard' ); ?></span>
                    <span class="asw-check-title"><?php esc_html_e( 'Honeypot', 'antispam-wizard' ); ?></span>
                </div>
                <p class="asw-check-desc"><?php esc_html_e( 'Adds a hidden decoy field to the comment form. Real users cannot see it, but automated bots that fill in all fields will trip it and be blocked.', 'antispam-wizard' ); ?></p>
                <div class="asw-toggle-cell"><?php $render_toggle( 'honeypot_enabled', (bool) $settings['honeypot_enabled'] ); ?></div>
            </div>

            <!-- Submit Time -->
            <div class="asw-card asw-check-card has-desc-and-params">
                <div class="asw-check-head">
                    <span class="asw-badge asw-badge--submit-time"><?php esc_html_e( 'Submit Time', 'antispam-wizard' ); ?></span>
                    <span class="asw-check-title"><?php esc_html_e( 'Submit Time', 'antispam-wizard' ); ?></span>
                </div>
                <p class="asw-check-desc"><?php esc_html_e( 'Measures how long the user took to submit the form. Bots typically submit forms instantly.', 'antispam-wizard' ); ?></p>
                <div class="asw-check-params">
                    <div class="asw-param">
                        <span class="asw-param-label"><?php esc_html_e( 'Min seconds', 'antispam-wizard' ); ?></span>
                        <input type="number" name="submit_time_min_seconds" value="<?php echo esc_attr( $settings['submit_time_min_seconds'] ); ?>" min="1" max="300" step="1">
                        <p class="asw-param-hint"><?php esc_html_e( 'Pages served from cache may use stale tokens, but they fail open and allow the comment.', 'antispam-wizard' ); ?></p>
                    </div>
                </div>
                <div class="asw-toggle-cell"><?php $render_toggle( 'submit_time_enabled', (bool) $settings['submit_time_enabled'] ); ?></div>
            </div>

        </div>
    </section>

    <section class="asw-section is-open" data-asw-section>
        <button type="button" class="asw-section-header" data-asw-collapsible aria-expanded="true">
            <span class="asw-section-chevron" aria-hidden="true">▾</span>
            <span class="asw-section-title"><?php esc_html_e( 'Rule-Based Checks', 'antispam-wizard' ); ?></span>
        </button>
        <div class="asw-section-body">

            <!-- Stop Words -->
            <div class="asw-card asw-check-card">
                <div class="asw-check-head">
                    <span class="asw-badge asw-badge--stop-words"><?php esc_html_e( 'Stop Words', 'antispam-wizard' ); ?></span>
                    <span class="asw-check-title"><?php esc_html_e( 'Stop Words Filter', 'antispam-wizard' ); ?></span>
                </div>
                <p class="asw-check-desc"><?php esc_html_e( 'Blocks comments containing words from your blacklist. Supports exact match (prefix "=") and substring modes. Configure the list on the Stop Words tab.', 'antispam-wizard' ); ?></p>
                <div class="asw-toggle-cell"><?php $render_toggle( 'stop_words_enabled', (bool) $settings['stop_words_enabled'] ); ?></div>
            </div>

            <!-- Links -->
            <div class="asw-card asw-check-card has-desc-and-params">
                <div class="asw-check-head">
                    <span class="asw-badge asw-badge--links"><?php esc_html_e( 'Links', 'antispam-wizard' ); ?></span>
                    <span class="asw-check-title"><?php esc_html_e( 'Link Counter', 'antispam-wizard' ); ?></span>
                </div>
                <p class="asw-check-desc"><?php esc_html_e( 'Flags comments with too many URLs. Spammers often stuff comments with links to boost SEO. Adjust the max to match your site\'s typical comment style.', 'antispam-wizard' ); ?></p>
                <div class="asw-check-params">
                    <div class="asw-param">
                        <span class="asw-param-label"><?php esc_html_e( 'Max links', 'antispam-wizard' ); ?></span>
                        <input type="number" name="links_max" value="<?php echo esc_attr( $settings['links_max'] ); ?>" min="1" max="50" step="1">
                    </div>
                </div>
                <div class="asw-toggle-cell"><?php $render_toggle( 'links_enabled', (bool) $settings['links_enabled'] ); ?></div>
            </div>

            <!-- Emoji -->
            <div class="asw-card asw-check-card has-desc-and-params">
                <div class="asw-check-head">
                    <span class="asw-badge asw-badge--emoji"><?php esc_html_e( 'Emoji', 'antispam-wizard' ); ?></span>
                    <span class="asw-check-title"><?php esc_html_e( 'Emoji Limiter', 'antispam-wizard' ); ?></span>
                </div>
                <p class="asw-check-desc"><?php esc_html_e( 'Catches comments overloaded with emoji, a common pattern in automated spam.', 'antispam-wizard' ); ?></p>
                <div class="asw-check-params">
                    <div class="asw-param">
                        <span class="asw-param-label"><?php esc_html_e( 'Max emoji', 'antispam-wizard' ); ?></span>
                        <input type="number" name="emoji_max" value="<?php echo esc_attr( $settings['emoji_max'] ); ?>" min="1" max="100" step="1">
                    </div>
                </div>
                <div class="asw-toggle-cell"><?php $render_toggle( 'emoji_enabled', (bool) $settings['emoji_enabled'] ); ?></div>
            </div>

            <!-- Spacing -->
            <div class="asw-card asw-check-card has-desc-and-params">
                <div class="asw-check-head">
                    <span class="asw-badge asw-badge--spacing"><?php esc_html_e( 'Spacing', 'antispam-wizard' ); ?></span>
                    <span class="asw-check-title"><?php esc_html_e( 'Abnormal Spacing', 'antispam-wizard' ); ?></span>
                </div>
                <p class="asw-check-desc"><?php esc_html_e( 'Detects obfuscated text where characters are separated by spaces (e.g. "h e l l o w o r l d") to bypass word-based filters. Parameters control sensitivity: min words count sets how long a comment must be before this check applies, short word length/ratio detect single-character "words", space ratio catches excessive whitespace.', 'antispam-wizard' ); ?></p>
                <div class="asw-check-params">
                    <div class="asw-param">
                        <span class="asw-param-label"><?php esc_html_e( 'Min words', 'antispam-wizard' ); ?></span>
                        <input type="number" name="spacing_min_words_count" value="<?php echo esc_attr( $settings['spacing_min_words_count'] ); ?>" min="1" max="100" step="1">
                        <span class="asw-param-hint"><?php esc_html_e( 'Minimum number of words a comment must contain before this check is applied. Shorter comments are skipped.', 'antispam-wizard' ); ?></span>
                    </div>
                    <div class="asw-param">
                        <span class="asw-param-label"><?php esc_html_e( 'Short word len', 'antispam-wizard' ); ?></span>
                        <input type="number" name="spacing_short_word_len" value="<?php echo esc_attr( $settings['spacing_short_word_len'] ); ?>" min="1" max="100" step="1">
                        <span class="asw-param-hint"><?php esc_html_e( 'Maximum character length for a word to be considered "short" (e.g. set to 1 to count only single-character tokens like "h", "e", "l").', 'antispam-wizard' ); ?></span>
                    </div>
                    <div class="asw-param">
                        <span class="asw-param-label"><?php esc_html_e( 'Short word ratio', 'antispam-wizard' ); ?></span>
                        <input type="number" name="spacing_short_word_ratio" value="<?php echo esc_attr( $settings['spacing_short_word_ratio'] ); ?>" min="0.1" max="1" step="0.1">
                        <span class="asw-param-hint"><?php esc_html_e( 'Fraction of short words (0.1–1.0) that triggers the check. Example: 0.6 means the comment is flagged when 60% or more of its words are short.', 'antispam-wizard' ); ?></span>
                    </div>
                    <div class="asw-param">
                        <span class="asw-param-label"><?php esc_html_e( 'Space ratio', 'antispam-wizard' ); ?></span>
                        <input type="number" name="spacing_space_ratio" value="<?php echo esc_attr( $settings['spacing_space_ratio'] ); ?>" min="0.1" max="1" step="0.1">
                        <span class="asw-param-hint"><?php esc_html_e( 'Fraction of whitespace characters relative to total length (0.1–1.0) that triggers the check. Example: 0.4 means the comment is flagged when 40% or more of its characters are spaces.', 'antispam-wizard' ); ?></span>
                    </div>
                </div>
                <div class="asw-toggle-cell"><?php $render_toggle( 'spacing_enabled', (bool) $settings['spacing_enabled'] ); ?></div>
            </div>

            <!-- Mixed Scripts -->
            <div class="asw-card asw-check-card has-desc-and-params">
                <div class="asw-check-head">
                    <span class="asw-badge asw-badge--mixed"><?php esc_html_e( 'Mixed Scripts', 'antispam-wizard' ); ?></span>
                    <span class="asw-check-title"><?php esc_html_e( 'Mixed Scripts Detector', 'antispam-wizard' ); ?></span>
                </div>
                <p class="asw-check-desc"><?php esc_html_e( 'Flags words that mix characters from different Unicode scripts (e.g. Latin + Cyrillic) in a single word, a technique used to visually mimic legitimate text. Threshold sets how many such words trigger blocking.', 'antispam-wizard' ); ?></p>
                <div class="asw-check-params">
                    <div class="asw-param">
                        <span class="asw-param-label"><?php esc_html_e( 'Threshold', 'antispam-wizard' ); ?></span>
                        <input type="number" name="multi_lang_threshold" value="<?php echo esc_attr( $settings['multi_lang_threshold'] ); ?>" min="1" max="50" step="1">
                    </div>
                </div>
                <div class="asw-toggle-cell"><?php $render_toggle( 'multi_lang_enabled', (bool) $settings['multi_lang_enabled'] ); ?></div>
            </div>

            <!-- Duplicates -->
            <div class="asw-card asw-check-card has-desc-and-params">
                <div class="asw-check-head">
                    <span class="asw-badge asw-badge--duplicates"><?php esc_html_e( 'Duplicates', 'antispam-wizard' ); ?></span>
                    <span class="asw-check-title"><?php esc_html_e( 'Duplicate Detector', 'antispam-wizard' ); ?></span>
                </div>
                <p class="asw-check-desc"><?php esc_html_e( 'Blocks repeated identical comments from the same IP within a time window. Threshold sets how many duplicates are allowed, TTL sets the window duration.', 'antispam-wizard' ); ?></p>
                <div class="asw-check-params">
                    <div class="asw-param">
                        <span class="asw-param-label"><?php esc_html_e( 'Threshold', 'antispam-wizard' ); ?></span>
                        <input type="number" name="duplicate_threshold" value="<?php echo esc_attr( $settings['duplicate_threshold'] ); ?>" min="1" max="100" step="1">
                    </div>
                    <div class="asw-param">
                        <span class="asw-param-label"><?php esc_html_e( 'TTL (sec)', 'antispam-wizard' ); ?></span>
                        <input type="number" name="duplicate_ttl" value="<?php echo esc_attr( $settings['duplicate_ttl'] ); ?>" min="60" max="86400" step="60">
                    </div>
                </div>
                <div class="asw-toggle-cell"><?php $render_toggle( 'duplicate_enabled', (bool) $settings['duplicate_enabled'] ); ?></div>
            </div>

        </div>
    </section>

    <section class="asw-section is-open" data-asw-section>
        <button type="button" class="asw-section-header" data-asw-collapsible aria-expanded="true">
            <span class="asw-section-chevron" aria-hidden="true">▾</span>
            <span class="asw-section-title"><?php esc_html_e( 'ML-Based Checks', 'antispam-wizard' ); ?></span>
        </button>
        <div class="asw-section-body">

            <!-- Bayesian -->
            <div class="asw-card asw-check-card has-desc-and-params">
                <div class="asw-check-head">
                    <span class="asw-badge asw-badge--bayesian"><?php esc_html_e( 'Bayesian', 'antispam-wizard' ); ?></span>
                    <span class="asw-check-title"><?php esc_html_e( 'Bayesian Filter', 'antispam-wizard' ); ?></span>
                </div>
                <p class="asw-check-desc"><?php esc_html_e( 'Classifies comments using a Naive Bayes model trained on your spam/ham samples. Higher threshold means stricter filtering (fewer false positives but may miss some spam).', 'antispam-wizard' ); ?></p>
                <div class="asw-check-params">
                    <div class="asw-param" style="flex:1;">
                        <span class="asw-param-label"><?php esc_html_e( 'Spam probability threshold', 'antispam-wizard' ); ?></span>
                        <div class="asw-slider">
                            <input type="range" name="bayesian_threshold" min="0.10" max="0.99" step="0.01" value="<?php echo esc_attr( $settings['bayesian_threshold'] ); ?>" data-asw-slider>
                            <span class="asw-slider-value" data-asw-slider-value><?php echo esc_html( number_format( (float) $settings['bayesian_threshold'], 2 ) ); ?></span>
                        </div>
                    </div>
                </div>
                <div class="asw-toggle-cell"><?php $render_toggle( 'bayesian_enabled', (bool) $settings['bayesian_enabled'] ); ?></div>
            </div>

            <!-- Similarity -->
            <div class="asw-card asw-check-card has-desc-and-params">
                <div class="asw-check-head">
                    <span class="asw-badge asw-badge--similarity"><?php esc_html_e( 'Similarity', 'antispam-wizard' ); ?></span>
                    <span class="asw-check-title"><?php esc_html_e( 'Similarity Filter', 'antispam-wizard' ); ?></span>
                </div>
                <p class="asw-check-desc"><?php esc_html_e( 'Compares each comment against known spam samples using cosine similarity on tokenized text. Higher threshold means the comment must be more similar to known spam to be blocked.', 'antispam-wizard' ); ?></p>
                <div class="asw-check-params">
                    <div class="asw-param" style="flex:1;">
                        <span class="asw-param-label"><?php esc_html_e( 'Similarity threshold', 'antispam-wizard' ); ?></span>
                        <div class="asw-slider">
                            <input type="range" name="similarity_threshold" min="0.10" max="0.99" step="0.01" value="<?php echo esc_attr( $settings['similarity_threshold'] ); ?>" data-asw-slider>
                            <span class="asw-slider-value" data-asw-slider-value><?php echo esc_html( number_format( (float) $settings['similarity_threshold'], 2 ) ); ?></span>
                        </div>
                    </div>
                </div>
                <div class="asw-toggle-cell"><?php $render_toggle( 'similarity_enabled', (bool) $settings['similarity_enabled'] ); ?></div>
            </div>

        </div>
    </section>

    <section class="asw-section is-open" data-asw-section>
        <button type="button" class="asw-section-header" data-asw-collapsible aria-expanded="true">
            <span class="asw-section-chevron" aria-hidden="true">▾</span>
            <span class="asw-section-title"><?php esc_html_e( 'Loaded Models', 'antispam-wizard' ); ?></span>
        </button>
        <div class="asw-section-body">
            <div class="asw-card">
                <p class="asw-check-desc"><?php esc_html_e( 'Training data available per script group for Bayesian and Similarity checks. Mark comments as spam or ham in the Spam Log to grow each model.', 'antispam-wizard' ); ?></p>
                <?php
                $training_data  = new ASW_Training_Data( ASW::get_instance()->get_repository() );
                $script_counts  = $training_data->get_script_sample_counts();
                $script_groups  = ASW_Script_Detector::SCRIPT_GROUPS;
                ?>
                <?php if ( ! empty( $script_counts ) ) : ?>
                    <table class="asw-models-table widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Script Group', 'antispam-wizard' ); ?></th>
                                <th><?php esc_html_e( 'Spam Samples', 'antispam-wizard' ); ?></th>
                                <th><?php esc_html_e( 'Ham Samples', 'antispam-wizard' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $script_counts as $script => $counts ) :
                                $label = $script_groups[ $script ] ?? $script;
                            ?>
                                <tr>
                                    <td><?php echo esc_html( $label ); ?></td>
                                    <td><?php echo esc_html( number_format_i18n( $counts['spam'] ) ); ?></td>
                                    <td><?php echo esc_html( number_format_i18n( $counts['ham'] ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p class="asw-empty"><?php esc_html_e( 'No script-specific training data found. Mark comments as spam or ham to start building models.', 'antispam-wizard' ); ?></p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="asw-section is-open" data-asw-section>
        <button type="button" class="asw-section-header" data-asw-collapsible aria-expanded="true">
            <span class="asw-section-chevron" aria-hidden="true">▾</span>
            <span class="asw-section-title"><?php esc_html_e( 'Global Settings', 'antispam-wizard' ); ?></span>
        </button>
        <div class="asw-section-body">

            <div class="asw-card asw-check-card has-desc-and-params">
                <div class="asw-check-head">
                    <span class="asw-check-title"><?php esc_html_e( 'Minimum Message Length', 'antispam-wizard' ); ?></span>
                </div>
                <p class="asw-check-desc"><?php esc_html_e( 'Comments shorter than this skip the heavier Bayesian and Similarity checks, since very short messages rarely provide enough signal for statistical analysis.', 'antispam-wizard' ); ?></p>
                <div class="asw-check-params">
                    <div class="asw-param">
                        <span class="asw-param-label"><?php esc_html_e( 'Characters', 'antispam-wizard' ); ?></span>
                        <input type="number" name="min_message_length" value="<?php echo esc_attr( $settings['min_message_length'] ); ?>" min="1" max="1000" step="1">
                    </div>
                </div>
                <div class="asw-toggle-cell"></div>
            </div>

            <div class="asw-card asw-check-card">
                <div class="asw-check-head">
                    <span class="asw-check-title"><?php esc_html_e( 'Show flagged comments to their author', 'antispam-wizard' ); ?></span>
                </div>
                <p class="asw-check-desc"><?php esc_html_e( 'A comment the plugin flags as spam stays visible to the person who wrote it, marked as awaiting moderation, so a real visitor caught by mistake does not post it again. Nobody else sees it.', 'antispam-wizard' ); ?></p>
                <div class="asw-toggle-cell"><?php $render_toggle( 'shadow_display_enabled', (bool) $settings['shadow_display_enabled'] ); ?></div>
            </div>

        </div>
    </section>

    <?php if ( class_exists( 'ASW_CF7_Integration' ) && ASW_CF7_Integration::is_available() ) : ?>
    <section class="asw-section is-open" data-asw-section>
        <button type="button" class="asw-section-header" data-asw-collapsible aria-expanded="true">
            <span class="asw-section-chevron" aria-hidden="true">▾</span>
            <span class="asw-section-title"><?php esc_html_e( 'Form Integrations', 'antispam-wizard' ); ?></span>
        </button>
        <div class="asw-section-body">
            <div class="asw-card asw-check-card">
                <div class="asw-check-head">
                    <span class="asw-badge"><?php esc_html_e( 'Contact Form 7', 'antispam-wizard' ); ?></span>
                    <span class="asw-check-title"><?php esc_html_e( 'Contact Form 7', 'antispam-wizard' ); ?></span>
                </div>
                <p class="asw-check-desc"><?php esc_html_e( 'All enabled checks run on Contact Form 7 submissions and flagged submissions are blocked through Contact Form 7\'s own spam mechanism.', 'antispam-wizard' ); ?></p>
                <div class="asw-toggle-cell"><?php $render_toggle( 'cf7_enabled', (bool) $settings['cf7_enabled'] ); ?></div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <div class="asw-form-footer">
        <?php submit_button( __( 'Save Changes', 'antispam-wizard' ), 'primary', 'submit', false ); ?>
    </div>
</form>