<?php
/**
 * Stop-words spam check.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Checks comment text against a configurable list of stop-words.
 *
 * Supports two matching modes:
 * - Prefix "=" for exact match (entire text must equal the word).
 * - Default substring match (text must contain the word).
 */
class ANTISPAMWIZARD_Stop_Words_Check implements ANTISPAMWIZARD_Check {

    /**
     * Whether to use script-aware stop words.
     *
     * @var bool
     */
    private bool $script_aware;

    /**
     * Constructor.
     *
     * @param bool $script_aware Whether to detect script and merge script-specific default stop words.
     */
    public function __construct( bool $script_aware = false ) {
        $this->script_aware = $script_aware;
    }

    /**
     * Run the stop-words check against comment data.
     *
     * @param array $comment_data WordPress comment data array.
     * @return ANTISPAMWIZARD_Check_Result
     */
    public function check( array $comment_data ): ANTISPAMWIZARD_Check_Result {
        $settings = ANTISPAMWIZARD_Settings::get_all();

        if ( empty( $settings['stop_words_enabled'] ) ) {
            return new ANTISPAMWIZARD_Check_Result( false, 'stop_words', __( 'check disabled', 'antispam-wizard' ) );
        }

        $stop_words = $settings['stop_words_list'];
        if ( ! is_array( $stop_words ) ) {
            $stop_words = array();
        }

        $text = isset( $comment_data['comment_content'] ) ? $comment_data['comment_content'] : '';
        if ( '' === $text ) {
            return new ANTISPAMWIZARD_Check_Result( false, 'stop_words', __( 'empty comment', 'antispam-wizard' ) );
        }

        if ( $this->script_aware ) {
            $script            = ANTISPAMWIZARD_Script_Detector::detect( $text );
            $script_stop_words = ANTISPAMWIZARD_Settings::get_default_stop_words_for_script( $script );
            $global_defaults   = ANTISPAMWIZARD_Settings::get_defaults()['stop_words_list'];
            $new_words         = array_diff( $script_stop_words, $global_defaults );
            $stop_words        = array_unique( array_merge( $stop_words, $new_words ) );
        }

        if ( empty( $stop_words ) ) {
            return new ANTISPAMWIZARD_Check_Result( false, 'stop_words', __( 'no stop words configured', 'antispam-wizard' ) );
        }

        $clean_text = self::normalize( self::clean_emoji( mb_strtolower( $text, 'UTF-8' ) ) );

        foreach ( $stop_words as $word ) {
            $word = mb_strtolower( trim( $word ), 'UTF-8' );
            if ( '' === $word ) {
                continue;
            }
            if ( self::match_stop_word( $clean_text, $word ) ) {
                $display_word = ltrim( $word, '=' );
                return new ANTISPAMWIZARD_Check_Result( true, 'stop_words', $display_word );
            }
        }

        return new ANTISPAMWIZARD_Check_Result( false, 'stop_words', __( 'not found', 'antispam-wizard' ) );
    }

    /**
     * Check if text matches a stop word.
     *
     * @param string $text Normalized, lowercased text.
     * @param string $word Stop word (already lowercased).
     * @return bool
     */
    private static function match_stop_word( string $text, string $word ): bool {
        if ( str_starts_with( $word, '=' ) ) {
            $check_word = substr( $word, 1 );
            if ( '' === $check_word ) {
                return false;
            }
            $normalized_word = self::normalize( $check_word );
            return $text === $normalized_word;
        }

        $normalized_word = self::normalize( $word );
        return str_contains( $text, $normalized_word );
    }

    /**
     * Remove emoji characters from text.
     *
     * @param string $text Input text.
     * @return string Text without emoji.
     */
    private static function clean_emoji( string $text ): string {
        // Remove common emoji Unicode ranges.
        $result = preg_replace(
            '/[\x{1F600}-\x{1F64F}' .   // Emoticons
            '\x{1F300}-\x{1F5FF}' .      // Misc Symbols and Pictographs
            '\x{1F680}-\x{1F6FF}' .      // Transport and Map
            '\x{1F1E0}-\x{1F1FF}' .      // Flags
            '\x{2600}-\x{26FF}' .        // Misc symbols
            '\x{2700}-\x{27BF}' .        // Dingbats
            '\x{FE00}-\x{FE0F}' .        // Variation Selectors
            '\x{1F900}-\x{1F9FF}' .      // Supplemental Symbols
            '\x{1FA00}-\x{1FA6F}' .      // Chess Symbols
            '\x{1FA70}-\x{1FAFF}' .      // Symbols and Pictographs Extended-A
            '\x{200D}' .                 // Zero Width Joiner
            '\x{20E3}' .                 // Combining Enclosing Keycap
            '\x{E0020}-\x{E007F}' .      // Tags
            ']/u', '', $text
        );

        return $result !== null ? $result : $text;
    }

    /**
     * Normalize spaces: collapse multiple whitespace into single space and trim.
     *
     * @param string $text Input text.
     * @return string Normalized text.
     */
    private static function normalize( string $text ): string {
        $result = preg_replace( '/\s+/u', ' ', $text );
        return trim( $result !== null ? $result : $text );
    }
}
