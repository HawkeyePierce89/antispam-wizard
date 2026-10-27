<?php
/**
 * Links spam check.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Checks comment text for excessive links (http:// and https:// occurrences).
 */
class ANTISPAMWIZARD_Links_Check implements ANTISPAMWIZARD_Check {

    /**
     * Run the links check against comment data.
     *
     * @param array $comment_data WordPress comment data array.
     * @return ANTISPAMWIZARD_Check_Result
     */
    public function check( array $comment_data ): ANTISPAMWIZARD_Check_Result {
        $settings = ANTISPAMWIZARD_Settings::get_all();

        if ( empty( $settings['links_enabled'] ) ) {
            return new ANTISPAMWIZARD_Check_Result( false, 'links', __( 'check disabled', 'antispam-wizard' ) );
        }

        $text = isset( $comment_data['comment_content'] ) ? $comment_data['comment_content'] : '';
        if ( '' === $text ) {
            return new ANTISPAMWIZARD_Check_Result( false, 'links', __( 'empty comment', 'antispam-wizard' ) );
        }

        $max_links  = (int) $settings['links_max'];
        $text_lower = strtolower( $text );
        $count      = substr_count( $text_lower, 'http://' ) + substr_count( $text_lower, 'https://' );

        if ( $count > $max_links ) {
            return new ANTISPAMWIZARD_Check_Result(
                true,
                'links',
                // translators: 1: Number of links found, 2: Configured maximum.
                sprintf( __( 'too many links %1$d/%2$d', 'antispam-wizard' ), $count, $max_links )
            );
        }

        return new ANTISPAMWIZARD_Check_Result(
            false,
            'links',
            // translators: 1: Number of links found, 2: Configured maximum.
            sprintf( __( 'links %1$d/%2$d', 'antispam-wizard' ), $count, $max_links )
        );
    }
}
