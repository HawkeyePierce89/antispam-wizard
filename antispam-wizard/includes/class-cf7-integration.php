<?php
/**
 * Contact Form 7 integration.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Bridges Contact Form 7 submissions into the existing AntiSpam Wizard detection pipeline.
 *
 * Verdicts are delivered through CF7's native wpcf7_spam filter. The integration
 * is a soft dependency: it is registered on plugins_loaded only when CF7 is active.
 */
class ANTISPAMWIZARD_CF7_Integration {

    /**
     * Whether CF7 is available.
     *
     * @return bool
     */
    public static function is_available(): bool {
        // Test-only seam: the PHPUnit bootstrap defines the WPCF7 classes and
        // cannot undefine them, so tests force unavailability through this
        // global. It is never set in production.
        if ( isset( $GLOBALS['wp_cf7_is_available'] ) ) {
            if ( ! $GLOBALS['wp_cf7_is_available'] ) {
                return false;
            }
        }
        return class_exists( 'WPCF7_ContactForm' ) && class_exists( 'WPCF7_Submission' );
    }

    /**
     * Register CF7 hooks.
     */
    public function register_hooks(): void {
        add_filter( 'wpcf7_form_elements', array( $this, 'render_fields' ), 100, 1 );
        add_filter( 'wpcf7_enqueue_styles', array( $this, 'enqueue_styles' ) );
        add_filter( 'wpcf7_spam', array( $this, 'filter_spam' ), 10, 2 );
    }

    /**
     * Enqueue the bot-check stylesheet when CF7 protection is on.
     *
     * Hooked to wpcf7_enqueue_styles (CF7 filter, bool passthrough) so the
     * stylesheet only loads on pages that actually render a CF7 form. Returns
     * the filter value unchanged. If a site filters CF7's asset loading off,
     * the honeypot still stays hidden via the inline style fallback on the
     * wrapper emitted by ANTISPAMWIZARD_Comment_Form::render_bot_fields(), so only
     * strict-CSP sites that also suppress CF7 assets would see the fallback
     * label.
     *
     * @param mixed $value Filter value when called as wpcf7_enqueue_styles filter.
     * @return mixed
     */
    public function enqueue_styles( $value = true ) {
        $settings = ANTISPAMWIZARD_Settings::get_all();
        $enabled = ! empty( $settings['enabled'] );
        $cf7_enabled = isset( $settings['cf7_enabled'] ) ? $settings['cf7_enabled'] : true;
        $bot_on      = ! empty( $settings['honeypot_enabled'] ) || ! empty( $settings['submit_time_enabled'] );
        if ( $enabled && $cf7_enabled && $bot_on ) {
            wp_enqueue_style(
                'antispamwizard-comment-form',
                ANTISPAMWIZARD_URL . 'public/css/comment-form.css',
                array(),
                ANTISPAMWIZARD_VERSION
            );
        }
        return $value;
    }

    /**
     * Append bot-check fields to the CF7 form markup.
     *
     * @param string $elements CF7 form HTML.
     * @return string
     */
    public function render_fields( string $elements ): string {
        $settings = ANTISPAMWIZARD_Settings::get_all();
        $enabled = ! empty( $settings['enabled'] );
        $cf7_enabled = isset( $settings['cf7_enabled'] ) ? $settings['cf7_enabled'] : true;
        if ( ! $enabled || ! $cf7_enabled ) {
            return $elements;
        }
        if ( ! class_exists( 'WPCF7_ContactForm' ) || ! method_exists( 'WPCF7_ContactForm', 'get_current' ) ) {
            return $elements;
        }
        $form = WPCF7_ContactForm::get_current();
        if ( ! $form || ! method_exists( $form, 'id' ) ) {
            return $elements;
        }
        $form_id = (int) $form->id();
        if ( $form_id <= 0 ) {
            return $elements;
        }
        return $elements . ANTISPAMWIZARD_Comment_Form::render_bot_fields( $form_id );
    }

    /**
     * Build a comment-shaped payload from a CF7 submission.
     *
     * Returns null (fail-open) if the contact form, its form tags, or the
     * posted data are unavailable.
     *
     * @param mixed $submission WPCF7_Submission instance.
     * @return array|null
     */
    public function collect_submission( $submission ): ?array {
        try {
            if ( ! is_object( $submission ) || ! method_exists( $submission, 'get_contact_form' ) ) {
                return null;
            }
            $contact_form = $submission->get_contact_form();
            if ( ! $contact_form || ! method_exists( $contact_form, 'scan_form_tags' ) ) {
                return null;
            }
            $tags = $contact_form->scan_form_tags();
            if ( ! is_array( $tags ) || empty( $tags ) ) {
                return null;
            }
            if ( ! method_exists( $submission, 'get_posted_data' ) ) {
                return null;
            }
            $posted_data = $submission->get_posted_data();
            if ( ! is_array( $posted_data ) ) {
                return null;
            }
            // Empty posted_data is allowed — produces empty content and passes fail-open.
            $content_parts = array();
            $email_value   = '';
            $author_value  = '';
            $found_email   = false;
            $found_author  = false;

            foreach ( $tags as $tag ) {
                // Support both object and array shapes.
                $name     = '';
                $basetype = '';
                if ( is_object( $tag ) ) {
                    $name     = isset( $tag->name ) ? (string) $tag->name : '';
                    $basetype = isset( $tag->basetype ) ? (string) $tag->basetype : '';
                } elseif ( is_array( $tag ) ) {
                    $name     = isset( $tag['name'] ) ? (string) $tag['name'] : '';
                    $basetype = isset( $tag['basetype'] ) ? (string) $tag['basetype'] : '';
                } else {
                    continue;
                }
                if ( '' === $name ) {
                    continue;
                }
                if ( ! array_key_exists( $name, $posted_data ) ) {
                    continue;
                }
                $raw_value = $posted_data[ $name ];
                if ( null === $raw_value ) {
                    continue;
                }
                // Handle array values (e.g. attacker sending text[] or CF7 multi-value).
                // Join scalar elements so content checks still see the payload instead
                // of bypassing via empty content. Non-scalar nesting is flattened.
                $is_array_value = is_array( $raw_value );
                $flat = array();
                if ( $is_array_value ) {
                    array_walk_recursive(
                        $raw_value,
                        static function ( $v ) use ( &$flat ) {
                            if ( is_scalar( $v ) && null !== $v ) {
                                $flat[] = (string) $v;
                            }
                        }
                    );
                    $flat = array_values( array_filter( array_map( 'trim', $flat ), static function ( $s ) { return '' !== $s; } ) );
                    if ( empty( $flat ) ) {
                        continue;
                    }
                    // For email basetype, pick first valid email from the array.
                    if ( 'email' === $basetype ) {
                        if ( ! $found_email ) {
                            foreach ( $flat as $candidate ) {
                                $candidate = trim( $candidate );
                                $valid = false;
                                if ( function_exists( 'is_email' ) ) {
                                    $valid = (bool) is_email( $candidate );
                                } elseif ( false !== filter_var( $candidate, FILTER_VALIDATE_EMAIL ) ) {
                                    $valid = true;
                                }
                                if ( $valid ) {
                                    $email_value = $candidate;
                                    $found_email = true;
                                    break;
                                }
                            }
                        }
                        continue;
                    }
                    $value_str = implode( "\n", $flat );
                } else {
                    $value_str = (string) $raw_value;
                    if ( 'email' === $basetype ) {
                        if ( ! $found_email && '' !== trim( $value_str ) ) {
                            $candidate = trim( $value_str );
                            $valid = false;
                            if ( function_exists( 'is_email' ) ) {
                                $valid = (bool) is_email( $candidate );
                            } elseif ( false !== filter_var( $candidate, FILTER_VALIDATE_EMAIL ) ) {
                                $valid = true;
                            }
                            if ( $valid ) {
                                $email_value = $candidate;
                                $found_email = true;
                            }
                        }
                        continue;
                    }
                }

                if ( in_array( $basetype, array( 'text', 'textarea' ), true ) ) {
                    // For author: first text basetype value.
                    if ( ! $found_author && 'text' === $basetype && '' !== trim( $value_str ) ) {
                        $author_value = $value_str;
                        $found_author = true;
                    }
                    if ( '' !== trim( $value_str ) ) {
                        $content_parts[] = $value_str;
                    }
                } else {
                    // Explicitly excluded basetypes: url, tel, number, date, range, select, checkbox, radio, acceptance, quiz, file, hidden, submit, captchar, recaptcha and any unknown.
                    continue;
                }
            }

            $comment_content = implode( "\n\n", $content_parts );

            $payload = array(
                'comment_content'      => $comment_content,
                'comment_author_email' => $email_value,
                'comment_author'       => $author_value,
            );

            // IP.
            if ( method_exists( $submission, 'get_meta' ) ) {
                $remote_ip = $submission->get_meta( 'remote_ip' );
                if ( is_string( $remote_ip ) && '' !== $remote_ip && false !== filter_var( $remote_ip, FILTER_VALIDATE_IP ) ) {
                    $payload['comment_author_IP'] = $remote_ip;
                }
            }

            // comment_type is left unset.

            return $payload;
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    /**
     * Filter callback for wpcf7_spam.
     *
     * @param bool  $spam       Whether already flagged as spam.
     * @param mixed $submission WPCF7_Submission instance or null.
     * @return bool
     */
    public function filter_spam( $spam, $submission = null ) {
        try {
            if ( $spam ) {
                return $spam;
            }

            $settings = ANTISPAMWIZARD_Settings::get_all();
            $enabled = ! empty( $settings['enabled'] );
            $cf7_enabled = isset( $settings['cf7_enabled'] ) ? $settings['cf7_enabled'] : true;
            if ( ! $enabled || ! $cf7_enabled ) {
                return $spam;
            }

            // Resolve submission if not provided.
            if ( null === $submission ) {
                if ( class_exists( 'WPCF7_Submission' ) && method_exists( 'WPCF7_Submission', 'get_instance' ) ) {
                    $submission = call_user_func( array( 'WPCF7_Submission', 'get_instance' ) );
                }
            }
            if ( ! $submission ) {
                return $spam;
            }

            $payload = $this->collect_submission( $submission );
            if ( null === $payload ) {
                return $spam;
            }

            // Build synthetic bot-check request array from $_POST (raw/slashed, matching comment behaviour)
            // with the honeypot field, the token field, and comment_post_ID = contact-form ID.
            // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Contact Form 7 owns nonce handling for its own submissions; these two values are deliberately copied raw and still slashed so the shared bot checks see byte-identical input on the CF7 and comment paths (they unslash and validate each field themselves). Sanitizing here would let a tags-only honeypot fill through.
            $request = array();
            if ( isset( $_POST[ ANTISPAMWIZARD_Comment_Form::HONEYPOT_FIELD ] ) ) {
                $request[ ANTISPAMWIZARD_Comment_Form::HONEYPOT_FIELD ] = $_POST[ ANTISPAMWIZARD_Comment_Form::HONEYPOT_FIELD ];
            }
            if ( isset( $_POST[ ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD ] ) ) {
                $request[ ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD ] = $_POST[ ANTISPAMWIZARD_Comment_Form::TIMESTAMP_FIELD ];
            }
            // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            // Contact-form ID for token binding.
            $form_id = 0;
            try {
                $cf = null;
                if ( method_exists( $submission, 'get_contact_form' ) ) {
                    $cf = $submission->get_contact_form();
                }
                if ( $cf && method_exists( $cf, 'id' ) ) {
                    $form_id = (int) $cf->id();
                }
            } catch ( \Throwable $e ) {
                $form_id = 0;
            }
            $request['comment_post_ID'] = $form_id;

            // Build CF7-scoped detector lazily via callable that returns build_checks with injected request.
            $cf7_detector = new ANTISPAMWIZARD_Detector(
                function () use ( $request ) {
                    $checks = ANTISPAMWIZARD::get_instance()->build_checks( $request );
                    // The build_checks already injects request into the two bot checks when called with $request.
                    // Ensure ordering/short-circuit/fail-open contract untouched.
                    return $checks;
                }
            );

            $evaluation = $cf7_detector->evaluate( $payload );

            if ( ! $evaluation['is_spam'] ) {
                return $spam;
            }

            $check_names = $evaluation['check_names'];
            $reasons     = $evaluation['reasons'];
            $data        = $evaluation['data'];

            $normalized_content = isset( $data['comment_content'] ) ? $data['comment_content'] : ( $payload['comment_content'] ?? '' );
            $ip_hash = isset( $data['comment_author_IP_hashed'] ) ? $data['comment_author_IP_hashed'] : '';
            $email   = isset( $payload['comment_author_email'] ) ? $payload['comment_author_email'] : '';
            $email_hash = ANTISPAMWIZARD_Detector::hash_email( (string) $email );

            ANTISPAMWIZARD_Spam_Log::add_entry( $normalized_content, $check_names, $ip_hash, 0, 'form', $email_hash );
            ANTISPAMWIZARD_Stats::record( $check_names );

            $sanitized_reasons = array_map( 'wp_strip_all_tags', $reasons );
            if ( is_object( $submission ) && method_exists( $submission, 'add_spam_log' ) ) {
                try {
                    $submission->add_spam_log(
                        array(
                            'agent'  => 'antispam-wizard',
                            'reason' => implode( '; ', $sanitized_reasons ),
                        )
                    );
                } catch ( \Throwable $e ) {
                    // Fail-open: ignore add_spam_log failures.
                }
            }

            return true;
        } catch ( \Throwable $e ) {
            // Same rule as ANTISPAMWIZARD_Detector::run(): the exception MESSAGE is deliberately
            // omitted. This try wraps evaluate(), ANTISPAMWIZARD_Spam_Log::add_entry() and
            // ANTISPAMWIZARD_Stats::record(), so a $wpdb or lock-file failure would write a query
            // or a filesystem path into debug.log, which is web-readable on
            // misconfigured hosts. The throw site alone attributes the break, and it
            // is reduced to basename() for the same reason: the throw can come from
            // wp-includes or from CF7's own tree, so the full path would leak the
            // document root into the log the message was dropped to protect.
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fail-open diagnostic: a CF7 API surprise was caught and the submission passed through unflagged. Silently swallowing it would hide an integration break; only the throw site is recorded.
            error_log( 'AntiSpam Wizard: CF7 filter_spam failed at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
            return $spam;
        }
    }
}
