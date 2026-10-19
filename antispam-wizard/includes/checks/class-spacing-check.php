<?php
/**
 * Abnormal spacing spam check.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Detects obfuscated text with abnormal spacing patterns (e.g. "п р и в е т").
 */
class ASW_Spacing_Check implements ASW_Check {

    /**
     * Run the spacing check against comment data.
     *
     * @param array $comment_data WordPress comment data array.
     * @return ASW_Check_Result
     */
    public function check( array $comment_data ): ASW_Check_Result {
        $settings = ASW_Settings::get_all();

        if ( empty( $settings['spacing_enabled'] ) ) {
            return new ASW_Check_Result( false, 'spacing', __( 'check disabled', 'antispam-wizard' ) );
        }

        $text = isset( $comment_data['comment_content'] ) ? $comment_data['comment_content'] : '';

        if ( mb_strlen( $text, 'UTF-8' ) < 10 ) {
            return new ASW_Check_Result( false, 'spacing', __( 'too short', 'antispam-wizard' ) );
        }

        $text = mb_strtoupper( $text, 'UTF-8' );
        $words = preg_split( '/(*UCP)\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $words ) ) {
            $words = array();
        }

        $min_words = (int) $settings['spacing_min_words_count'];
        if ( count( $words ) < $min_words ) {
            return new ASW_Check_Result(
                false,
                'spacing',
                // translators: %d: Number of words found in the comment.
                sprintf( __( 'too few words (%d)', 'antispam-wizard' ), count( $words ) )
            );
        }

        // Count letters and spaces using Unicode-aware regex.
        $letters = preg_match_all( '/\pL/u', $text );
        $spaces  = preg_match_all( '/(*UCP)\s/u', $text );

        if ( false === $spaces || false === $letters || 0 === $spaces || 0 === $letters ) {
            return new ASW_Check_Result( false, 'spacing', __( 'no spaces or letters', 'antispam-wizard' ) );
        }

        $short_word_len = (int) $settings['spacing_short_word_len'];
        $short_words    = 0;

        if ( $short_word_len > 0 ) {
            foreach ( $words as $word ) {
                $word_len = mb_strlen( $word, 'UTF-8' );
                if ( $word_len <= $short_word_len && $word_len > 0 ) {
                    $short_words++;
                }
            }
        }

        $space_ratio      = (float) $spaces / (float) $letters;
        $short_word_ratio = (float) $short_words / (float) count( $words );

        $space_threshold      = (float) $settings['spacing_space_ratio'];
        $short_word_threshold = (float) $settings['spacing_short_word_ratio'];

        if ( $short_word_ratio > $short_word_threshold && $space_ratio > $space_threshold ) {
            return new ASW_Check_Result(
                true,
                'spacing',
                // translators: 1: Whitespace-to-length ratio, 2: Percentage of short words.
                sprintf( __( 'abnormal (ratio: %1$.2f, short: %2$.0f%%)', 'antispam-wizard' ), $space_ratio, $short_word_ratio * 100 )
            );
        }

        return new ASW_Check_Result(
            false,
            'spacing',
            // translators: 1: Whitespace-to-length ratio, 2: Percentage of short words.
            sprintf( __( 'normal (ratio: %1$.2f, short: %2$.0f%%)', 'antispam-wizard' ), $space_ratio, $short_word_ratio * 100 )
        );
    }
}
