<?php
/**
 * Plugin settings management.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manages all plugin settings with sensible defaults.
 */
class ASW_Settings {

    const OPTION_KEY = 'asw_settings';

    /**
     * Default settings for all 10 checks.
     *
     * @return array
     */
    public static function get_defaults(): array {
        return array(
            // Global enable/disable.
            'enabled'             => true,
            'cf7_enabled'         => true,

            // Show a flagged comment to its own author as awaiting moderation.
            'shadow_display_enabled' => true,

            // Honeypot check.
            'honeypot_enabled'    => true,

            // Submit time check.
            'submit_time_enabled'       => true,
            'submit_time_min_seconds'   => 5,

            // Stop-words check.
            'stop_words_enabled'  => true,
            'stop_words_list'     => array(
                'viagra',
                'cialis',
                'casino',
                'poker',
                'lottery',
                'porn',
                'xxx',
                '=buy cheap',
                '=free money',
                '=click here',
            ),

            // Links check.
            'links_enabled'       => true,
            'links_max'           => 2,

            // Emoji check.
            'emoji_enabled'       => true,
            'emoji_max'           => 4,

            // Abnormal spacing check.
            'spacing_enabled'             => true,
            'spacing_min_words_count'     => 4,
            'spacing_short_word_len'      => 2,
            'spacing_short_word_ratio'    => 0.5,
            'spacing_space_ratio'         => 0.3,

            // Multi-lang (mixed scripts) check.
            'multi_lang_enabled'   => true,
            'multi_lang_threshold' => 3,

            // Duplicate check.
            'duplicate_enabled'   => true,
            'duplicate_threshold' => 3,
            'duplicate_ttl'       => 3600,

            // Minimum message length for heavy checks.
            'min_message_length'  => 10,

            // Bayesian (Naive Bayes) check.
            'bayesian_enabled'   => true,
            'bayesian_threshold' => 0.7,

            // Similarity (cosine) check.
            'similarity_enabled'   => true,
            'similarity_threshold' => 0.6,

            // Excluded tokens (stop-words for tokenizer).
            'excluded_tokens_list' => array(
                // English
                'the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'can', 'her',
                'was', 'one', 'our', 'out', 'has', 'had', 'its', 'his', 'how', 'who',
                'did', 'get', 'may', 'him', 'this', 'that', 'with', 'have', 'from',
                'they', 'been', 'said', 'each', 'will', 'than', 'them', 'then',
                'what', 'when', 'were', 'your', 'into', 'which', 'there', 'their',
                'about', 'would', 'could', 'other', 'these', 'those',
                // Russian
                'что', 'как', 'это', 'для', 'при', 'все', 'его', 'она', 'они', 'мне',
                'так', 'уже', 'или', 'мой', 'нет', 'тот', 'вот', 'еще', 'тоже',
                'был', 'вас', 'нас', 'ваш', 'наш', 'без', 'над', 'под',
            ),
        );
    }

    /**
     * Get all settings merged with defaults.
     *
     * @return array
     */
    public static function get_all(): array {
        $saved = get_option( self::OPTION_KEY, array() );
        return array_merge( self::get_defaults(), is_array( $saved ) ? $saved : array() );
    }

    /**
     * Get a single setting value.
     *
     * @param string $key     Setting key.
     * @param mixed  $default Fallback if key not found even in defaults.
     * @return mixed
     */
    public static function get( string $key, $default = null ) {
        $all = self::get_all();
        return isset( $all[ $key ] ) ? $all[ $key ] : $default;
    }

    /**
     * Update settings (merges with existing).
     *
     * @param array $values Key-value pairs to update.
     * @return bool
     */
    public static function update( array $values ): bool {
        $current = self::get_all();
        $merged  = array_merge( $current, $values );
        return update_option( self::OPTION_KEY, $merged );
    }

    /**
     * Per-field [min, max] bounds for integer settings.
     *
     * @return array<string, array{int, int}>
     */
    public static function get_integer_bounds(): array {
        return array(
            'submit_time_min_seconds' => array( 1, 300 ),
            'links_max'               => array( 1, 50 ),
            'emoji_max'               => array( 1, 100 ),
            'spacing_min_words_count' => array( 1, 100 ),
            'spacing_short_word_len'  => array( 1, 100 ),
            'multi_lang_threshold'    => array( 1, 50 ),
            'duplicate_threshold'     => array( 1, 100 ),
            'duplicate_ttl'           => array( 60, 86400 ),
            'min_message_length'      => array( 1, 1000 ),
        );
    }

    /**
     * Get default stop words for a specific Unicode script group.
     *
     * @param string $script Script group name (e.g. 'latin', 'cyrillic').
     * @return array Stop words for the given script.
     */
    public static function get_default_stop_words_for_script( string $script ): array {
        $script_stop_words = array(
            'latin'      => array(
                'viagra',
                'cialis',
                'casino',
                'poker',
                'lottery',
                'porn',
                'xxx',
                '=buy cheap',
                '=free money',
                '=click here',
            ),
            'cyrillic'   => array(
                'виагра',
                'казино',
                'порно',
                'заработок',
                'кредит без',
                'ставки',
                'выигрыш',
                'розыгрыш',
                'бесплатно',
                'заработай',
            ),
            'cjk'        => array(
                '赌场',
                '博彩',
                '色情',
                '代孕',
                '贷款',
                '카지노',
                '도박',
            ),
            'arabic'     => array(
                'كازينو',
                'قمار',
                'ربح مجاني',
                'قرض',
            ),
            'devanagari' => array(
                'कैसीनो',
                'जुआ',
                'मुफ्त',
                'लोन',
            ),
        );

        return $script_stop_words[ $script ] ?? array();
    }

    /**
     * Remove all plugin settings from the database.
     */
    public static function cleanup(): void {
        delete_option( self::OPTION_KEY );
    }
}
