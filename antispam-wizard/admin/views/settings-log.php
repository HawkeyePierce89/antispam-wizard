<?php
/**
 * Spam log tab view.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$entries = array_reverse( ASW_Spam_Log::get_entries() );

/**
 * Map raw check name (as stored by ASW_Check_Result) to a human label
 * and to a CSS modifier for badge color.
 */
$check_meta = array(
    'honeypot'   => array( 'label' => __( 'Honeypot', 'antispam-wizard' ),      'mod' => 'honeypot' ),
    'submit_time'=> array( 'label' => __( 'Submit Time', 'antispam-wizard' ),   'mod' => 'submit-time' ),
    'stop_words' => array( 'label' => __( 'Stop Words', 'antispam-wizard' ),    'mod' => 'stop-words' ),
    'links'      => array( 'label' => __( 'Links', 'antispam-wizard' ),         'mod' => 'links' ),
    'emoji'      => array( 'label' => __( 'Emoji', 'antispam-wizard' ),         'mod' => 'emoji' ),
    'spacing'    => array( 'label' => __( 'Spacing', 'antispam-wizard' ),       'mod' => 'spacing' ),
    'multi_lang' => array( 'label' => __( 'Mixed Scripts', 'antispam-wizard' ), 'mod' => 'mixed' ),
    'duplicate'  => array( 'label' => __( 'Duplicate', 'antispam-wizard' ),     'mod' => 'duplicates' ),
    'bayesian'   => array( 'label' => __( 'Bayesian', 'antispam-wizard' ),      'mod' => 'bayesian' ),
    'similarity' => array( 'label' => __( 'Similarity', 'antispam-wizard' ),    'mod' => 'similarity' ),
    'manual'     => array( 'label' => __( 'Marked by moderator', 'antispam-wizard' ), 'mod' => 'duplicates' ),
);
?>

<div class="asw-card">
    <h3 class="asw-card-title"><?php esc_html_e( 'Spam Log', 'antispam-wizard' ); ?></h3>
    <p class="asw-card-description"><?php esc_html_e( 'Recent comments and form submissions blocked by AntiSpam Wizard (newest first, max 100).', 'antispam-wizard' ); ?></p>

    <?php if ( ! empty( $entries ) ) : ?>
        <form method="post" style="margin-top:12px;">
            <?php wp_nonce_field( 'asw_clear_log', 'asw_clear_log_nonce' ); ?>
            <button type="submit" name="asw_clear_log" class="asw-button asw-button--secondary"><?php esc_html_e( 'Clear Log', 'antispam-wizard' ); ?></button>
        </form>
    <?php endif; ?>
</div>

<?php if ( ! empty( $entries ) ) : ?>
    <div class="asw-log-list">
        <?php foreach ( $entries as $entry ) :
            $source = $entry['source'] ?? 'comment';
            $date_str = isset( $entry['date'] ) ? (string) $entry['date'] : '';
            $date_day = $date_str;
            $date_time = '';
            if ( '' !== $date_str ) {
                $ts = strtotime( $date_str );
                if ( false !== $ts ) {
                    $date_day  = date_i18n( 'M j, Y', $ts );
                    $date_time = date_i18n( 'H:i', $ts );
                }
            }
        ?>
            <div class="asw-card asw-log-entry">
                <div class="asw-log-date">
                    <span class="asw-log-date-day"><?php echo esc_html( $date_day ); ?></span>
                    <?php if ( '' !== $date_time ) : ?><span class="asw-log-date-time"><?php echo esc_html( $date_time ); ?></span><?php endif; ?>
                </div>
                <div class="asw-log-body">
                    <div class="asw-log-excerpt"><?php echo esc_html( $entry['text_excerpt'] ?? '' ); ?></div>
                    <div class="asw-log-meta">
                        <?php if ( isset( $entry['checks_triggered'] ) && is_array( $entry['checks_triggered'] ) ) : ?>
                            <?php foreach ( $entry['checks_triggered'] as $check ) :
                                $meta = $check_meta[ $check ] ?? array( 'label' => $check, 'mod' => 'duplicates' );
                            ?>
                                <span class="asw-badge asw-badge--<?php echo esc_attr( $meta['mod'] ); ?>"><?php echo esc_html( $meta['label'] ); ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if ( ! empty( $entry['ip_hash'] ) ) : ?>
                            <span class="asw-log-ip"><?php echo esc_html( substr( $entry['ip_hash'], 0, 12 ) . '…' ); ?></span>
                        <?php endif; ?>
                        <?php if ( 'form' === $source ) : ?>
                            <span class="asw-log-source"><?php esc_html_e( 'Form', 'antispam-wizard' ); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="asw-log-actions">
                    <?php if ( 'comment' === $source && ! empty( $entry['comment_id'] ) ) : ?>
                        <?php if ( ! empty( $entry['trained_as'] ) ) : ?>
                            <span class="asw-pill asw-pill--trained"><?php
                                // translators: %s: Training category the entry was marked as (spam or ham).
                                echo esc_html( sprintf( __( 'Trained as %s', 'antispam-wizard' ), $entry['trained_as'] ) );
                            ?></span>
                        <?php else : ?>
                            <button type="button" class="asw-pill asw-pill--ham asw-mark-ham" data-comment-id="<?php echo esc_attr( $entry['comment_id'] ); ?>"><?php esc_html_e( 'Ham', 'antispam-wizard' ); ?></button>
                            <button type="button" class="asw-pill asw-pill--spam asw-mark-spam" data-comment-id="<?php echo esc_attr( $entry['comment_id'] ); ?>"><?php esc_html_e( 'Spam', 'antispam-wizard' ); ?></button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php else : ?>
    <div class="asw-card">
        <p class="asw-empty"><?php esc_html_e( 'No spam has been blocked yet.', 'antispam-wizard' ); ?></p>
    </div>
<?php endif; ?>
