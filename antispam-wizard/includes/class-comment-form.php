<?php
/**
 * Anti-Spam Wizard comment form integrations.
 *
 * This class renders the honeypot and submit-time fields into the comment form.
 *
 * Trade-off: Off-screen positioning (not display:none) keeps the fallback label
 * readable for a human if CSS is lost, at the cost of being layout-visible to
 * headless bots that compute geometry; and the plugin gains one extra front-end
 * HTTP request on pages with an open comment form.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders bot-check fields into the comment form and mints/verifies the
 * submit-time HMAC token.
 */
class ANTISPAMWIZARD_Comment_Form {

    const HONEYPOT_FIELD  = 'comment_url_confirm';
    const TIMESTAMP_FIELD = 'comment_form_token';

    /**
     * Register front-end hooks.
     */
    public function register_hooks(): void {
        add_action( 'comment_form', array( $this, 'render_fields' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
    }

    /**
     * Enqueue the honeypot-hiding stylesheet on pages with an open comment form.
     */
    public function enqueue_styles(): void {
        $settings = ANTISPAMWIZARD_Settings::get_all();
        if ( ( ! empty( $settings['honeypot_enabled'] ) || ! empty( $settings['submit_time_enabled'] ) ) && is_singular() && comments_open() ) {
            wp_enqueue_style(
                'antispamwizard-comment-form',
                ANTISPAMWIZARD_URL . 'public/css/comment-form.css',
                array(),
                ANTISPAMWIZARD_VERSION
            );
        }
    }

    /**
     * Return the bot-check field markup for a given context ID.
     *
     * Shared by comment and CF7 render paths so markup and token minting
     * have a single source of truth. Returns an empty string when both
     * bot checks are disabled.
     *
     * @param int $context_id Post ID (comments) or contact-form ID (CF7) the token is bound to.
     * @return string
     */
    public static function render_bot_fields( int $context_id ): string {
        $settings = ANTISPAMWIZARD_Settings::get_all();

        if ( empty( $settings['honeypot_enabled'] ) && empty( $settings['submit_time_enabled'] ) ) {
            return '';
        }

        $unique_id = wp_rand();
        ob_start();
        ?>
            <div class="antispamwizard-hp-field" aria-hidden="true" style="position:absolute; left:-10000px; width:1px; height:1px; overflow:hidden;">
                <?php if ( ! empty( $settings['honeypot_enabled'] ) ) : ?>
                    <label for="<?php echo esc_attr( self::HONEYPOT_FIELD . '_' . $unique_id ); ?>"><?php esc_html_e( 'Leave this field empty', 'antispam-wizard' ); ?></label>
                    <input type="text" name="<?php echo esc_attr( self::HONEYPOT_FIELD ); ?>" id="<?php echo esc_attr( self::HONEYPOT_FIELD . '_' . $unique_id ); ?>" value="" tabindex="-1" autocomplete="off" />
                <?php endif; ?>
                <?php if ( ! empty( $settings['submit_time_enabled'] ) ) : ?>
                    <?php $token = self::mint_token( null, $context_id ); ?>
                    <input type="hidden" name="<?php echo esc_attr( self::TIMESTAMP_FIELD ); ?>" value="<?php echo esc_attr( $token ); ?>" />
                <?php endif; ?>
            </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Print the honeypot and submit-time fields inside the comment form.
     */
    public function render_fields(): void {
        echo self::render_bot_fields( (int) get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_bot_fields() is the single source of truth for the comment and CF7 render paths and assembles its markup from esc_attr()-escaped parts.
    }

    /**
     * Mint a tamper-evident "<timestamp>.<hmac>" token bound to a post ID.
     *
     * @param int|null $timestamp Unix timestamp; defaults to now.
     * @param int      $post_id   Post the comment form belongs to.
     * @return string
     */
    public static function mint_token( ?int $timestamp = null, int $post_id = 0 ): string {
        if ( null === $timestamp ) {
            $timestamp = time();
        }
        $hmac = hash_hmac( 'sha256', $timestamp . '|' . $post_id, wp_salt( 'auth' ) );
        return $timestamp . '.' . $hmac;
    }

    /**
     * Verify a token and return its timestamp, or null when invalid.
     *
     * @param string $raw     Raw token value from the request.
     * @param int    $post_id Post ID the comment is being submitted to.
     * @return int|null
     */
    public static function parse_token( string $raw, int $post_id = 0 ): ?int {
        if ( strlen( $raw ) > 100 ) {
            return null;
        }

        $parts = explode( '.', $raw, 2 );
        if ( 2 !== count( $parts ) ) {
            return null;
        }

        $timestamp = $parts[0];
        $provided_hmac = $parts[1];

        if ( ! is_numeric( $timestamp ) ) {
            return null;
        }

        $expected_hmac = hash_hmac( 'sha256', $timestamp . '|' . $post_id, wp_salt( 'auth' ) );

        if ( hash_equals( $expected_hmac, $provided_hmac ) ) {
            return (int) $timestamp;
        }

        return null;
    }
}
