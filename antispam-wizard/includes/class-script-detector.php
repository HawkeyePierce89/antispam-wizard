<?php
/**
 * Unicode script detector for comment language routing.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Detects the dominant Unicode script group in a text string.
 */
class ANTISPAMWIZARD_Script_Detector {

    /**
     * Script groups with display labels.
     */
    public const SCRIPT_GROUPS = array(
        'latin'      => 'Latin',
        'cyrillic'   => 'Cyrillic',
        'cjk'        => 'CJK (Chinese/Japanese/Korean)',
        'arabic'     => 'Arabic',
        'devanagari' => 'Devanagari',
        'greek'      => 'Greek',
        'hebrew'     => 'Hebrew',
        'thai'       => 'Thai',
        'bengali'    => 'Bengali',
        'indic'      => 'Indic (Tamil/Telugu/Kannada/Malayalam)',
    );

    /**
     * PCRE patterns for individual scripts mapped to their group.
     */
    private const SCRIPT_PATTERNS = array(
        'latin'      => array( '\p{Latin}' ),
        'cyrillic'   => array( '\p{Cyrillic}' ),
        'cjk'        => array( '\p{Han}', '\p{Hiragana}', '\p{Katakana}', '\p{Hangul}' ),
        'arabic'     => array( '\p{Arabic}' ),
        'devanagari' => array( '\p{Devanagari}' ),
        'greek'      => array( '\p{Greek}' ),
        'hebrew'     => array( '\p{Hebrew}' ),
        'thai'       => array( '\p{Thai}' ),
        'bengali'    => array( '\p{Bengali}' ),
        'indic'      => array( '\p{Tamil}', '\p{Telugu}', '\p{Kannada}', '\p{Malayalam}' ),
    );

    /**
     * Detect the dominant script group in the given text.
     *
     * @param string $text The text to analyze.
     * @return string The script group name, or 'unknown' if no letter characters found.
     */
    public static function detect( string $text ): string {
        if ( '' === $text ) {
            return 'unknown';
        }

        // Pre-filter to letter characters only so that script-specific digits
        // and marks do not influence detection (e.g. Arabic-Indic digits should
        // not cause a digit-only comment to be routed as Arabic).
        $letters_only = preg_replace( '/[^\p{L}]+/u', '', $text );
        if ( null === $letters_only || '' === $letters_only ) {
            return 'unknown';
        }

        $counts = array();

        // Consume each group's characters from the subject instead of testing every
        // pattern against the whole string, so each character is counted in exactly
        // one group - the first in SCRIPT_PATTERNS order that claims it. From PCRE2
        // 10.42 on, \p{Han} and friends resolve against Script_Extensions rather than
        // Script, so one character can satisfy several script properties: U+30FC
        // KATAKANA-HIRAGANA PROLONGED SOUND MARK matches both \p{Hiragana} and
        // \p{Katakana}, and being Lm it survives the letter pre-filter above. Summing
        // per-pattern counts therefore counted it twice and inflated 'cjk' - "abcde
        // コーヒー" (5 Latin letters, 4 CJK) scored cjk=6 vs latin=5 and was routed to
        // the CJK classifier and the bigram tokenizer. The set of such characters
        // widens with every PCRE/Unicode upgrade, which also made the verdict depend
        // on the PCRE build; consuming keeps it stable across them. Same rule and
        // rationale as ANTISPAMWIZARD_Multi_Lang_Check::is_multi_script_word().
        $residue = $letters_only;

        foreach ( self::SCRIPT_PATTERNS as $group => $patterns ) {
            $rest = preg_replace( '/(?:' . implode( '|', $patterns ) . ')/u', '', $residue, -1, $matched );
            if ( null === $rest ) {
                // $letters_only already proved the subject is valid UTF-8, so this can
                // only be a PCRE limit/JIT failure. Bail out rather than continue:
                // leaving this group's characters in the residue would hand them to
                // whichever later group claims them, silently routing the comment to
                // the wrong classifier and tokenizer. 'unknown' is the honest answer,
                // and it matches ANTISPAMWIZARD_Multi_Lang_Check::is_multi_script_word().
                return 'unknown';
            }

            if ( $matched > 0 ) {
                $counts[ $group ] = $matched;
            }

            $residue = $rest;
            if ( '' === $residue ) {
                break;
            }
        }

        if ( empty( $counts ) ) {
            return 'unknown';
        }

        arsort( $counts );

        return array_key_first( $counts );
    }
}
