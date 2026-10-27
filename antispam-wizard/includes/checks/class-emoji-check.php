<?php
/**
 * Emoji spam check.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Checks comment text for excessive emoji usage.
 */
class ANTISPAMWIZARD_Emoji_Check implements ANTISPAMWIZARD_Check {

    /**
     * Unicode emoji regex pattern.
     */
    private const EMOJI_PATTERN = '/[\x{1F600}-\x{1F64F}' .
        '\x{1F300}-\x{1F5FF}' .
        '\x{1F680}-\x{1F6FF}' .
        '\x{1F1E0}-\x{1F1FF}' .
        '\x{2600}-\x{26FF}' .
        '\x{2700}-\x{27BF}' .
        '\x{1F900}-\x{1F9FF}' .
        '\x{1FA00}-\x{1FA6F}' .
        '\x{1FA70}-\x{1FAFF}' .
        '\x{20E3}' .
        ']/u';

    /**
     * Run the emoji check against comment data.
     *
     * @param array $comment_data WordPress comment data array.
     * @return ANTISPAMWIZARD_Check_Result
     */
    public function check( array $comment_data ): ANTISPAMWIZARD_Check_Result {
        $settings = ANTISPAMWIZARD_Settings::get_all();

        if ( empty( $settings['emoji_enabled'] ) ) {
            return new ANTISPAMWIZARD_Check_Result( false, 'emoji', __( 'check disabled', 'antispam-wizard' ) );
        }

        $text = isset( $comment_data['comment_content'] ) ? $comment_data['comment_content'] : '';
        if ( '' === $text ) {
            return new ANTISPAMWIZARD_Check_Result( false, 'emoji', __( 'empty comment', 'antispam-wizard' ) );
        }

        $max_emoji = (int) $settings['emoji_max'];
        $count     = self::count_emoji( $text );

        if ( $count > $max_emoji ) {
            return new ANTISPAMWIZARD_Check_Result(
                true,
                'emoji',
                // translators: 1: Number of emoji found, 2: Configured maximum.
                sprintf( __( 'too many emoji %1$d/%2$d', 'antispam-wizard' ), $count, $max_emoji )
            );
        }

        return new ANTISPAMWIZARD_Check_Result(
            false,
            'emoji',
            // translators: 1: Number of emoji found, 2: Configured maximum.
            sprintf( __( 'emoji %1$d/%2$d', 'antispam-wizard' ), $count, $max_emoji )
        );
    }

    /**
     * Count emoji characters in a string.
     *
     * @param string $text Text to scan.
     * @return int Number of emoji found.
     */
    private static function count_emoji( string $text ): int {
        $matches = preg_match_all( self::EMOJI_PATTERN, $text );
        return $matches !== false ? $matches : 0;
    }
}
