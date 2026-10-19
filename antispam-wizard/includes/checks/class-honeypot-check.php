<?php
/**
 * Honeypot spam check.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Checks if the honeypot field was filled.
 */
class ASW_Honeypot_Check implements ASW_Check {

    /**
     * @var array|null
     */
    private ?array $request;

    /**
     * Constructor.
     *
     * @param array|null $request Request data array, defaults to $_POST.
     */
    public function __construct( ?array $request = null ) {
        $this->request = $request;
    }

    /**
     * Run the honeypot check against comment data.
     *
     * @param array $comment_data WordPress comment data array.
     * @return ASW_Check_Result
     */
    public function check( array $comment_data ): ASW_Check_Result {
        $settings = ASW_Settings::get_all();

        if ( empty( $settings['honeypot_enabled'] ) ) {
            return new ASW_Check_Result( false, 'honeypot', __( 'check disabled', 'antispam-wizard' ) );
        }

        // Pass if not a standard comment (e.g. pingback, trackback).
        $comment_type = isset( $comment_data['comment_type'] ) ? $comment_data['comment_type'] : '';
        if ( '' !== $comment_type && 'comment' !== $comment_type ) {
            return new ASW_Check_Result( false, 'honeypot', __( 'skipped non-comment type', 'antispam-wizard' ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress issues no nonce on the pre_comment_approved path, and adding one to the comment form would flag legitimate visitors on cached pages. The single field read below is individually wp_unslash()-ed and the check fails open on anything missing or malformed. Deliberately not sanitize_text_field()-ed: a honeypot filled with only HTML tags would sanitize to empty and pass.
        $request_data = null !== $this->request ? $this->request : $_POST;

        $field_name = ASW_Comment_Form::HONEYPOT_FIELD;

        if ( ! isset( $request_data[ $field_name ] ) ) {
            return new ASW_Check_Result( false, 'honeypot', __( 'field absent', 'antispam-wizard' ) );
        }

        if ( is_array( $request_data[ $field_name ] ) ) {
            return new ASW_Check_Result( true, 'honeypot', __( 'honeypot filled', 'antispam-wizard' ) );
        }

        $value = trim( wp_unslash( (string) $request_data[ $field_name ] ) );

        if ( '' === $value ) {
            return new ASW_Check_Result( false, 'honeypot', __( 'field empty', 'antispam-wizard' ) );
        }

        return new ASW_Check_Result(
            true,
            'honeypot',
            __( 'honeypot filled', 'antispam-wizard' )
        );
    }
}
