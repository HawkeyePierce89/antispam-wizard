<?php
/**
 * Submit Time bot check.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Checks if the comment was submitted too quickly.
 *
 * Fail-open by design: only a verifiably fresh token ("too fast") may flag as
 * spam. A missing, malformed, tampered, stale, or future-dated token passes,
 * so cached pages, salt rotation, or plugins that mangle hidden form fields
 * can never cause a legitimate comment to be flagged by this check.
 */
class ANTISPAMWIZARD_Submit_Time_Check implements ANTISPAMWIZARD_Check {

    /**
     * Maximum accepted token age in seconds (one day). Tokens older than this
     * are treated as stale (e.g. long-lived page caches) and pass.
     */
    const MAX_TOKEN_AGE = 86400;

    /**
     * Request array (typically $_POST).
     *
     * @var array|null
     */
    private ?array $request;

    /**
     * Constructor.
     *
     * @param array|null $request Optional request array. Defaults to $_POST.
     */
    public function __construct( ?array $request = null ) {
        $this->request = $request;
    }

    /**
     * Run the check.
     *
     * @param array $commentdata The parsed comment data.
     * @return ANTISPAMWIZARD_Check_Result
     */
    public function check( array $commentdata ): ANTISPAMWIZARD_Check_Result {
        $settings = ANTISPAMWIZARD_Settings::get_all();

        if ( empty( $settings['submit_time_enabled'] ) ) {
            return new ANTISPAMWIZARD_Check_Result( false, 'submit_time', __( 'check disabled', 'antispam-wizard' ) );
        }

        $comment_type = isset( $commentdata['comment_type'] ) ? $commentdata['comment_type'] : '';
        if ( '' !== $comment_type && 'comment' !== $comment_type ) {
            return new ANTISPAMWIZARD_Check_Result( false, 'submit_time', __( 'skipped non-comment type', 'antispam-wizard' ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress issues no nonce on the pre_comment_approved path, and adding one to the comment form would flag legitimate visitors on cached pages. The token read below is wp_unslash()-ed and HMAC-verified against the post ID, and the check fails open on anything missing or malformed.
        $request_data = null !== $this->request ? $this->request : $_POST;

        if ( ! isset( $request_data[ ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD ] ) ) {
            return new ANTISPAMWIZARD_Check_Result( false, 'submit_time', __( 'token absent', 'antispam-wizard' ) );
        }

        $raw_token_data = wp_unslash( $request_data[ ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD ] );
        if ( is_array( $raw_token_data ) ) {
            return new ANTISPAMWIZARD_Check_Result( false, 'submit_time', __( 'token malformed or tampered', 'antispam-wizard' ) );
        }

        $raw_token = (string) $raw_token_data;
        $post_id = isset( $request_data['comment_post_ID'] ) ? (int) $request_data['comment_post_ID'] : 0;
        $timestamp = ANTISPAMWIZARD_Comment_Form::parse_token( $raw_token, $post_id );

        if ( null === $timestamp ) {
            return new ANTISPAMWIZARD_Check_Result( false, 'submit_time', __( 'token malformed or tampered', 'antispam-wizard' ) );
        }

        $elapsed = time() - $timestamp;

        if ( $elapsed < 0 ) {
            return new ANTISPAMWIZARD_Check_Result( false, 'submit_time', __( 'future timestamp', 'antispam-wizard' ) );
        }

        if ( $elapsed > self::MAX_TOKEN_AGE ) {
            return new ANTISPAMWIZARD_Check_Result( false, 'submit_time', __( 'token expired', 'antispam-wizard' ) );
        }

        $min_seconds = isset( $settings['submit_time_min_seconds'] ) ? (int) $settings['submit_time_min_seconds'] : 5;

        if ( $elapsed < $min_seconds ) {
            // translators: 1: Elapsed seconds, 2: Minimum configured seconds.
            $details = sprintf( __( 'submitted in %1$ds (min %2$ds)', 'antispam-wizard' ), $elapsed, $min_seconds );
            return new ANTISPAMWIZARD_Check_Result( true, 'submit_time', $details );
        }

        return new ANTISPAMWIZARD_Check_Result( false, 'submit_time', __( 'elapsed time acceptable', 'antispam-wizard' ) );
    }
}
