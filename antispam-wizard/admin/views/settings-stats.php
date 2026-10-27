<?php
/**
 * Statistics tab view.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$stats = ANTISPAMWIZARD_Stats::get_all();

$check_meta = array(
    'honeypot'   => __( 'Honeypot', 'antispam-wizard' ),
    'submit_time'=> __( 'Submit Time', 'antispam-wizard' ),
    'stop_words' => __( 'Stop Words', 'antispam-wizard' ),
    'links'      => __( 'Links', 'antispam-wizard' ),
    'emoji'      => __( 'Emoji', 'antispam-wizard' ),
    'spacing'    => __( 'Spacing', 'antispam-wizard' ),
    'multi_lang' => __( 'Mixed Scripts', 'antispam-wizard' ),
    'duplicate'  => __( 'Duplicates', 'antispam-wizard' ),
    'bayesian'   => __( 'Bayesian', 'antispam-wizard' ),
    'similarity' => __( 'Similarity', 'antispam-wizard' ),
);

$settings  = ANTISPAMWIZARD_Settings::get_all();
$per_check = isset( $stats['per_check'] ) && is_array( $stats['per_check'] ) ? $stats['per_check'] : array();

// Include all enabled checks, even those with zero matches.
foreach ( $check_meta as $key => $label ) {
    if ( ! empty( $settings[ $key . '_enabled' ] ) && ! isset( $per_check[ $key ] ) ) {
        $per_check[ $key ] = 0;
    }
}

arsort( $per_check );
$max_count   = ! empty( $per_check ) ? max( array_values( $per_check ) ) : 0;
$total_check = array_sum( $per_check );
?>

<div class="antispamwizard-kpi-row">
    <div class="antispamwizard-card antispamwizard-kpi">
        <div class="antispamwizard-kpi-icon" aria-hidden="true">⊘</div>
        <div>
            <div class="antispamwizard-kpi-value"><?php echo esc_html( number_format_i18n( (int) ( $stats['total_blocked'] ?? 0 ) ) ); ?></div>
            <div class="antispamwizard-kpi-label"><?php esc_html_e( 'Total Blocked', 'antispam-wizard' ); ?></div>
        </div>
    </div>
    <div class="antispamwizard-card antispamwizard-kpi">
        <div class="antispamwizard-kpi-icon antispamwizard-kpi-icon--last" aria-hidden="true">⏱</div>
        <div>
            <div class="antispamwizard-kpi-value">
                <?php
                if ( ! empty( $stats['last_blocked_date'] ) ) {
                    echo esc_html( $stats['last_blocked_date'] );
                } else {
                    esc_html_e( 'Never', 'antispam-wizard' );
                }
                ?>
            </div>
            <div class="antispamwizard-kpi-label"><?php esc_html_e( 'Last Blocked', 'antispam-wizard' ); ?></div>
        </div>
    </div>
</div>

<div class="antispamwizard-card">
    <h3 class="antispamwizard-card-title"><?php esc_html_e( 'Per-Check Breakdown', 'antispam-wizard' ); ?></h3>
    <p class="antispamwizard-stats-note"><?php esc_html_e( 'Each blocked comment can trigger multiple checks. These counts show individual check matches, so the sum may exceed Total Blocked.', 'antispam-wizard' ); ?></p>
    <?php if ( ! empty( $per_check ) ) : ?>
        <div class="antispamwizard-bar-list">
            <?php foreach ( $per_check as $check_name => $count ) :
                $label = $check_meta[ $check_name ] ?? $check_name;
                $width = $max_count > 0 ? round( ( $count / $max_count ) * 100, 1 ) : 0;
                $pct   = $total_check > 0 ? round( ( $count / $total_check ) * 100, 1 ) : 0;
            ?>
                <div class="antispamwizard-bar-row" data-check="<?php echo esc_attr( $check_name ); ?>">
                    <div class="antispamwizard-bar-name">
                        <span class="antispamwizard-bar-dot" aria-hidden="true"></span>
                        <?php echo esc_html( $label ); ?>
                    </div>
                    <div class="antispamwizard-bar-track">
                        <div class="antispamwizard-bar-fill" style="width:<?php echo esc_attr( $width ); ?>%"></div>
                    </div>
                    <div class="antispamwizard-bar-count"><?php echo esc_html( number_format_i18n( (int) $count ) ); ?></div>
                    <div class="antispamwizard-bar-pct"><?php echo esc_html( $pct ); ?>%</div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else : ?>
        <p class="antispamwizard-empty"><?php esc_html_e( 'No check-level statistics recorded yet.', 'antispam-wizard' ); ?></p>
    <?php endif; ?>
</div>
