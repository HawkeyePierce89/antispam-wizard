<?php
/**
 * Text tokenizer for spam detection.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Standalone text tokenizer that normalizes and splits text into tokens.
 */
class ANTISPAMWIZARD_Tokenizer {

    /**
     * Maximum number of tokens returned by any tokenization method.
     * Prevents memory exhaustion on very large inputs.
     */
    const MAX_TOKENS = 10000;

    /**
     * Token every phone-number-like digit sequence is normalised to before
     * tokenization.
     *
     * A phone number is a unique string per spammer, so the raw digits can
     * never be learned: "пишите 89139991205" and "пишите 89261234567" share
     * no token, and a classifier trained on the first knows nothing about the
     * second. Collapsing every such sequence to one marker turns "there is a
     * phone number in this comment" into a single learnable feature — the
     * strongest single signal of classified-ad spam ("продам гараж, пишите
     * 8913…", "call 555-0134 for quick loans"). Letters only, so the split in
     * tokenize() keeps it whole, and nothing a person would write.
     */
    const PHONE_TOKEN = 'antispamwizardphonenumber';

    /**
     * A phone-number-like sequence: 9 to 15 decimal digits, each pair of
     * digits separated by at most three of space, dot, dash or parentheses,
     * an optional leading "+", and no letter or digit touching either end
     * (so "unique1234" and "2024" are left alone). Covers "+7 (913) 999-12-05",
     * "8-913-999-12-05", "89139991205", "+1 415 555 0134" and "555.012.3456";
     * years, prices and short codes have too few digits to match.
     */
    private const PHONE_PATTERN = '/(?<![\p{L}\p{N}])\+?\p{Nd}(?:[\s().\-]{0,3}\p{Nd}){8,14}(?![\p{L}\p{N}])/u';

    /**
     * Excluded tokens (stop-words) lookup table.
     *
     * @var array<string, true>
     */
    private array $excluded_tokens;

    /**
     * @param array|null $excluded_tokens Optional list of tokens to exclude. Falls back to settings default.
     */
    public function __construct( ?array $excluded_tokens = null ) {
        if ( null === $excluded_tokens ) {
            $excluded_tokens = ANTISPAMWIZARD_Settings::get( 'excluded_tokens_list', array() );
        }
        $excluded_tokens       = array_map( function ( string $t ): string {
            return mb_strtolower( $t, 'UTF-8' );
        }, $excluded_tokens );
        $this->excluded_tokens = array_flip( $excluded_tokens );
    }

    /**
     * Tokenize text into an array of normalized tokens.
     *
     * Lowercases text, splits on non-letter/non-number characters, excludes tokens <= 2 chars
     * and excluded tokens.
     *
     * @param string $text The text to tokenize.
     * @return array<int, string> Array of tokens.
     */
    public function tokenize( string $text ): array {
        $text   = mb_strtolower( self::normalize_phone_numbers( $text ), 'UTF-8' );
        $parts  = preg_split( '/[^\p{L}\p{N}\p{M}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
        $tokens = array();

        if ( ! is_array( $parts ) ) {
            return $tokens;
        }

        foreach ( $parts as $part ) {
            if ( mb_strlen( $part, 'UTF-8' ) <= 2 ) {
                continue;
            }
            if ( isset( $this->excluded_tokens[ $part ] ) ) {
                continue;
            }
            $tokens[] = $part;
            if ( count( $tokens ) >= self::MAX_TOKENS ) {
                break;
            }
        }

        return $tokens;
    }

    /**
     * Tokenize text into character n-grams.
     *
     * Removes whitespace and punctuation, then generates overlapping n-grams
     * of the specified length. Useful for CJK and Thai scripts where words
     * are not separated by spaces.
     *
     * @param string $text The text to tokenize.
     * @param int    $n    N-gram size (default 2 for bigrams).
     * @return array<int, string> Array of n-gram tokens.
     */
    public function tokenize_ngrams( string $text, int $n = 2 ): array {
        $text = mb_strtolower( self::normalize_phone_numbers( $text ), 'UTF-8' );
        // Remove non-letter/non-number/non-mark characters.
        // \p{M} preserves combining marks (e.g. Thai vowels and tone marks).
        $text = preg_replace( '/[^\p{L}\p{N}\p{M}]+/u', '', $text );

        if ( null === $text || '' === $text ) {
            return array();
        }

        $length = mb_strlen( $text, 'UTF-8' );
        $tokens = array();

        for ( $i = 0; $i <= $length - $n; $i++ ) {
            $tokens[] = mb_substr( $text, $i, $n, 'UTF-8' );
            if ( count( $tokens ) >= self::MAX_TOKENS ) {
                break;
            }
        }

        return $tokens;
    }

    /**
     * Tokenize text using the appropriate strategy for the given script.
     *
     * CJK and Thai scripts use n-gram tokenization (bigrams); all other
     * scripts use word-based tokenization.
     *
     * @param string $text   The text to tokenize.
     * @param string $script The script group identifier.
     * @return array<int, string> Array of tokens.
     */
    public function tokenize_for_script( string $text, string $script ): array {
        if ( 'cjk' === $script || 'thai' === $script ) {
            return $this->tokenize_ngrams( $text );
        }

        return $this->tokenize( $text );
    }

    /**
     * Replace every phone-number-like digit sequence with PHONE_TOKEN.
     *
     * Runs before the split in both tokenization paths, so the marker is one
     * whole token for word-based scripts and a run of Latin bigrams for the
     * CJK/Thai n-gram path — distinctive either way. On malformed UTF-8
     * preg_replace() returns null; the text is then used unchanged rather than
     * blanked, since ANTISPAMWIZARD_Detector::evaluate() has already sanitised the body
     * upstream and a bare tokenizer caller must never lose its input.
     *
     * @param string $text Raw text.
     * @return string Text with phone-number-like sequences replaced.
     */
    public static function normalize_phone_numbers( string $text ): string {
        $normalized = preg_replace( self::PHONE_PATTERN, ' ' . self::PHONE_TOKEN . ' ', $text );

        return is_string( $normalized ) ? $normalized : $text;
    }
}
