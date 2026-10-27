<?php
/**
 * Mixed scripts (multi-language) spam check.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Detects words containing characters from multiple Unicode scripts,
 * a common spam/phishing technique (e.g. mixing Latin and Cyrillic).
 */
class ANTISPAMWIZARD_Multi_Lang_Check implements ANTISPAMWIZARD_Check {

    /**
     * Unicode script patterns for detection.
     * Each key is a bucket name, value is a PCRE Unicode property pattern.
     *
     * Han, Hiragana, Katakana and Hangul share one bucket, exactly as
     * ANTISPAMWIZARD_Script_Detector::SCRIPT_PATTERNS groups them under 'cjk'. Splitting
     * them made ordinary Japanese a false positive: this check splits on
     * whitespace and hyphens, and Japanese has no inter-word spaces, so a whole
     * sentence is a single "word" that routinely mixes kanji with both kana -
     * a three-line Japanese comment scored 3 mixed-script words and was flagged
     * at the shipped default threshold. Mixing writing systems within the CJK
     * group is normal orthography, not the Latin/Cyrillic homoglyph spoofing
     * this check exists to catch; a CJK word mixed with any other script still
     * counts as two buckets and is still flagged, except for the embedded-Latin
     * case exempted by ORTHOGRAPHIC_PAIRS.
     */
    private const SCRIPT_PATTERNS = array(
        'Latin'      => '/\p{Latin}/u',
        'Cyrillic'   => '/\p{Cyrillic}/u',
        'Greek'      => '/\p{Greek}/u',
        'Arabic'     => '/\p{Arabic}/u',
        'Armenian'   => '/\p{Armenian}/u',
        'Georgian'   => '/\p{Georgian}/u',
        'Hebrew'     => '/\p{Hebrew}/u',
        'Devanagari' => '/\p{Devanagari}/u',
        'Thai'       => '/\p{Thai}/u',
        'CJK'        => '/(?:\p{Han}|\p{Hiragana}|\p{Katakana}|\p{Hangul})/u',
        'Bengali'    => '/\p{Bengali}/u',
        'Tamil'      => '/\p{Tamil}/u',
    );

    /**
     * Characters the per-word script scan ignores, collapsed into one pass.
     *
     * Four skip rules in one pass: the literal letter 'i' (common to many
     * languages, matching the Go reference), whitespace, digits, and the
     * Common and Inherited scripts (punctuation, symbols, mathematical
     * notation, combining marks). Under the /u modifier PHP enables PCRE2_UCP,
     * so \s and \d are Unicode-aware - \d also covers digits such as U+0966
     * DEVANAGARI DIGIT ZERO, whose script is Devanagari rather than Common.
     */
    private const IGNORED_PATTERN = '/[i\s\d\p{Common}\p{Inherited}]+/u';

    /**
     * The thirteenth bucket: anything belonging to none of the scripts above.
     *
     * Tested only against the residue left after every SCRIPT_PATTERNS entry
     * has been consumed, so it never needs to restate those twelve buckets.
     * Punctuation and symbols are excluded because a character claimed by no
     * script is only a bucket of its own when it is neither: a script-specific
     * mark such as U+05BE HEBREW PUNCTUATION MAQAF has already been consumed
     * by \p{Hebrew} and never reaches here.
     */
    private const OTHER_PATTERN = '/[^\p{P}\p{S}]/u';

    /**
     * Two-bucket combinations that are normal orthography, not script mixing.
     *
     * The CJK bucket merge closed the *intra*-CJK false positive but not the
     * root cause behind it: CJK and Thai are written without inter-word spaces,
     * so this check's whitespace/hyphen split makes a whole sentence one
     * "word" - and embedded Latin (product names, brands, URLs, romaji) is
     * routine in both, written without spaces around it. Three ordinary lines
     * such as "はじめまして。WordPressでブログを始めました。" therefore scored 3
     * and were flagged at the shipped default threshold of 3.
     *
     * Exempting the pair rather than splitting the word at the script boundary
     * keeps every other verdict intact: Latin/Cyrillic homoglyph spoofing - the
     * attack this check exists to catch - is untouched, and so is a space-less
     * script mixed with anything else ("пассворд日本" is still Cyrillic + CJK).
     * Only these exact two-bucket pairs pass; a third bucket always flags, so
     * "WordPressで日本пароль" is still spam.
     *
     * Each entry is sorted by SCRIPT_PATTERNS order and joined with '+', which
     * is the form is_multi_script_word() builds its lookup key in.
     */
    private const ORTHOGRAPHIC_PAIRS = array(
        'Latin+Thai' => true,
        'Latin+CJK'  => true,
    );

    /**
     * Run the multi-lang check against comment data.
     *
     * @param array $comment_data WordPress comment data array.
     * @return ANTISPAMWIZARD_Check_Result
     */
    public function check( array $comment_data ): ANTISPAMWIZARD_Check_Result {
        $settings = ANTISPAMWIZARD_Settings::get_all();

        if ( empty( $settings['multi_lang_enabled'] ) ) {
            return new ANTISPAMWIZARD_Check_Result( false, 'multi_lang', __( 'check disabled', 'antispam-wizard' ) );
        }

        $text = isset( $comment_data['comment_content'] ) ? $comment_data['comment_content'] : '';
        if ( '' === $text ) {
            return new ANTISPAMWIZARD_Check_Result( false, 'multi_lang', __( 'empty comment', 'antispam-wizard' ) );
        }

        $threshold = (int) $settings['multi_lang_threshold'];
        $count     = self::count_multi_script_words( $text );

        if ( $count >= $threshold ) {
            return new ANTISPAMWIZARD_Check_Result(
                true,
                'multi_lang',
                // translators: 1: Number of distinct scripts detected, 2: Configured threshold.
                sprintf( __( 'mixed scripts %1$d/%2$d', 'antispam-wizard' ), $count, $threshold )
            );
        }

        return new ANTISPAMWIZARD_Check_Result(
            false,
            'multi_lang',
            // translators: 1: Number of distinct scripts detected, 2: Configured threshold.
            sprintf( __( 'mixed scripts %1$d/%2$d', 'antispam-wizard' ), $count, $threshold )
        );
    }

    /**
     * Count words that contain characters from multiple Unicode scripts.
     *
     * @param string $text Text to analyze.
     * @return int Number of multi-script words.
     */
    private static function count_multi_script_words( string $text ): int {
        // Split on whitespace and hyphens, matching the Go reference implementation.
        $words = preg_split( '/[\s\-]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

        if ( false === $words ) {
            // Every /u pattern - the split above and each script scan below - fails on
            // a malformed subject, so a single stray byte would otherwise switch this
            // check off for the whole submission. That is attacker-controlled input
            // (the comment body, or a Contact Form 7 field joined into it), so drop the
            // invalid sequences and classify what remains instead. sanitize_utf8()
            // substitutes them with '?', which is Common and therefore ignored below.
            $text  = ANTISPAMWIZARD_Detector::sanitize_utf8( $text );
            $words = preg_split( '/[\s\-]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
        }

        if ( ! $words ) {
            return 0;
        }

        $count = 0;
        foreach ( $words as $word ) {
            if ( self::is_multi_script_word( $word ) ) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Check if a single word contains characters from multiple Unicode scripts.
     *
     * Classifies the whole word at once: one pass strips every ignored
     * character, then each script in turn consumes the characters it claims
     * from the residue, short-circuiting as soon as a second script does.
     * Consuming rather than merely testing is what assigns every character to
     * exactly one bucket - the first script in SCRIPT_PATTERNS order that
     * claims it. From PCRE2 10.42 on, \p{Latin} and friends resolve against
     * Script_Extensions, where one character can belong to several scripts
     * (U+A8F1 COMBINING DEVANAGARI SIGN AVAGRAHA matches both \p{Devanagari}
     * and \p{Bengali} on 10.44, but only \p{Devanagari} on 10.39), so a
     * test-only loop would count such a character as two scripts and flag
     * single-script words on any current build. Every pattern is a plain
     * character-class scan, so the cost stays linear in the word length.
     *
     * @param string $word Word to check.
     * @return bool True if the word mixes scripts.
     */
    private static function is_multi_script_word( string $word ): bool {
        $residue = preg_replace( self::IGNORED_PATTERN, '', $word );

        // Malformed UTF-8 cannot reach here: count_multi_script_words() only reaches
        // this loop once preg_split() has succeeded on the whole subject, which proves
        // the subject - and therefore every word - is valid UTF-8. Do not weaken that
        // guard; returning here on malformed input would restore the one-byte evasion
        // of the whole check. A null below can only be a PCRE limit/JIT failure.
        if ( null === $residue || '' === $residue ) {
            return false;
        }

        $scripts = array();
        foreach ( self::SCRIPT_PATTERNS as $name => $pattern ) {
            $rest = preg_replace( $pattern, '', $residue, -1, $matched );
            if ( null === $rest ) {
                return false;
            }
            if ( 0 === $matched ) {
                continue;
            }

            $scripts[] = $name;
            // Three buckets is beyond every ORTHOGRAPHIC_PAIRS exemption, so the
            // verdict can no longer change - stop scanning. Two buckets cannot
            // short-circuit any more, but the remaining passes run over a residue
            // the matched scripts have already been consumed from, so the whole
            // classification stays linear in the word length.
            if ( count( $scripts ) > 2 ) {
                return true;
            }

            $residue = $rest;
            if ( '' === $residue ) {
                break;
            }
        }

        // Anything left belongs to no known script; it is one further bucket
        // unless it is nothing but punctuation and symbols.
        if ( '' !== $residue && preg_match( self::OTHER_PATTERN, $residue ) ) {
            $scripts[] = 'Other';
        }

        if ( count( $scripts ) < 2 ) {
            return false;
        }

        // A space-less script abutting Latin inside one whitespace-delimited
        // token is ordinary writing, not script mixing - see ORTHOGRAPHIC_PAIRS.
        return count( $scripts ) > 2 || ! isset( self::ORTHOGRAPHIC_PAIRS[ implode( '+', $scripts ) ] );
    }
}
