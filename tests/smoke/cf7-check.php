<?php
/**
 * Runtime CF7 smoke check for AntiSpam Wizard.
 *
 * Executed inside a real WordPress instance via `wp eval-file`. Drives the
 * plugin end-to-end through the `wpcf7_spam` filter (via WPCF7_ContactForm::submit)
 * and asserts:
 *   - classic spam text (3+ links) is flagged as spam via Links check;
 *   - neutral, link-free, stop-word-free text is NOT flagged as spam;
 *   - the rendered form markup (form_elements()) contains both bot-check field names;
 *   - the token in that markup verifies against the contact-form ID.
 *
 * Probabilistic checks (Bayesian, Similarity) are disabled and settings are
 * pinned for deterministic results. Links is the deterministic spam trigger.
 *
 * Prints expected-vs-actual on failure and exit(1); prints an OK line and
 * exit(0) when assertions pass.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "cf7-check: must run inside WordPress (wp eval-file)\n" );
    exit( 1 );
}

// Force debug logging routing like detect-check.php so LOADS A captures warnings.
error_reporting( E_ALL );
ini_set( 'log_errors', '1' );
ini_set( 'error_log', WP_CONTENT_DIR . '/debug.log' );

if ( ! class_exists( 'ASW_Settings' ) ) {
    fwrite( STDERR, "cf7-check: FAIL plugin not loaded (ASW_Settings missing)\n" );
    exit( 1 );
}

if ( ! class_exists( 'WPCF7_ContactForm' ) || ! class_exists( 'WPCF7_Submission' ) ) {
    fwrite( STDERR, "cf7-check: SKIP Contact Form 7 not active (WPCF7_ContactForm/WPCF7_Submission missing)\n" );
    exit( 0 );
}

if ( ! class_exists( 'ASW_CF7_Integration' ) || ! class_exists( 'ASW_Comment_Form' ) ) {
    fwrite( STDERR, "cf7-check: FAIL ASW_CF7_Integration or ASW_Comment_Form missing\n" );
    exit( 1 );
}

/*
 * Pin settings for deterministic run. Links is the spam trigger (spam when count > links_max=2).
 * All other checks except honeypot/submit_time are disabled so ham verdict depends only on Links.
 * Honeypot and submit_time remain enabled to test rendering and to ensure harmless values don't flag.
 */
ASW_Settings::update(
    array(
        'enabled'                 => true,
        'cf7_enabled'             => true,
        'links_enabled'           => true,
        'links_max'               => 2,
        'honeypot_enabled'        => true,
        'submit_time_enabled'     => true,
        'submit_time_min_seconds' => 5,
        'stop_words_enabled'      => false,
        'emoji_enabled'           => false,
        'spacing_enabled'         => false,
        'multi_lang_enabled'      => false,
        'duplicate_enabled'       => false,
        'bayesian_enabled'        => false,
        'similarity_enabled'      => false,
    )
);

$failures = array();

// --- Ensure deterministic server environment for CF7 spam() ---
$_SERVER['REMOTE_ADDR']     = '203.0.113.7';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; ASW Smoke)';
$_SERVER['REMOTE_PORT']     = '12345';

// Ensure not logged in so nonce check is skipped (verify_nonce returns true).
wp_set_current_user( 0 );

/**
 * Reset WPCF7_Submission singleton via reflection so multiple submissions can be driven in one process.
 */
function asw_cf7_reset_submission(): void {
    if ( ! class_exists( 'WPCF7_Submission' ) ) {
        return;
    }
    try {
        $ref = new ReflectionClass( 'WPCF7_Submission' );
        if ( $ref->hasProperty( 'instance' ) ) {
            $prop = $ref->getProperty( 'instance' );
            $prop->setAccessible( true );
            $prop->setValue( null, null );
        }
    } catch ( Throwable $e ) {
        // Ignore.
    }
}

/**
 * Create or reuse a deterministic contact form for smoke tests.
 *
 * @return WPCF7_ContactForm
 */
function asw_cf7_get_or_create_form() {
    // Try to find existing smoke form first to avoid duplicate creation on reruns.
    $existing = get_posts(
        array(
            'post_type'      => WPCF7_ContactForm::post_type,
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'title'          => 'ASW Smoke CF7',
        )
    );

    // Search by title via WPCF7 API if available.
    if ( ! empty( $existing ) ) {
        foreach ( $existing as $p ) {
            if ( 'ASW Smoke CF7' === $p->post_title ) {
                $form = WPCF7_ContactForm::get_instance( $p->ID );
                if ( $form ) {
                    return $form;
                }
            }
        }
    }

    // Also try find via WPCF7_ContactForm::find if available.
    if ( method_exists( 'WPCF7_ContactForm', 'find' ) ) {
        $forms = WPCF7_ContactForm::find( array( 'posts_per_page' => -1 ) );
        foreach ( $forms as $f ) {
            if ( 'ASW Smoke CF7' === $f->title() ) {
                return $f;
            }
        }
    }

    // Create from default template, then override with deterministic fields.
    $form = WPCF7_ContactForm::get_template( array( 'title' => 'ASW Smoke CF7' ) );

    // Deterministic template: known field names for content collection.
    $det_form = '<label> Your name [text* your-name] </label>' . "\n"
        . '<label> Your email [email* your-email] </label>' . "\n"
        . '<label> Subject [text your-subject] </label>' . "\n"
        . '<label> Your message [textarea your-message] </label>' . "\n"
        . '[submit "Submit"]';

    $props = $form->get_properties();
    $props['form'] = $det_form;

    // Ensure mail is not required to be valid; keep defaults but ensure active.
    $form->set_properties( $props );
    $form->set_title( 'ASW Smoke CF7' );
    $save_id = $form->save();

    if ( ! $save_id ) {
        fwrite( STDERR, "cf7-check: FAIL could not save contact form\n" );
        exit( 1 );
    }

    $saved = WPCF7_ContactForm::get_instance( $save_id );
    if ( ! $saved ) {
        fwrite( STDERR, "cf7-check: FAIL could not load saved contact form\n" );
        exit( 1 );
    }

    return $saved;
}

$contact_form = asw_cf7_get_or_create_form();
$form_id      = (int) $contact_form->id();

if ( $form_id <= 0 ) {
    $failures[] = 'Could not obtain contact form ID';
} else {
    fwrite( STDOUT, sprintf( "  - contact form ID %d (%s)\n", $form_id, $contact_form->title() ) );
}

// Ensure the form is current for render hooks.
WPCF7_ContactForm::get_instance( $form_id );
$contact_form = WPCF7_ContactForm::get_instance( $form_id );

// Generate unit_tag by rendering form_html (also exercises wpcf7_form_elements filter).
$unit_tag = '';
try {
    // form_html generates a new unit_tag each call.
    $html_for_tag = $contact_form->form_html();
    $unit_tag     = $contact_form->unit_tag();
} catch ( Throwable $e ) {
    $failures[] = 'form_html threw ' . get_class( $e ) . ': ' . $e->getMessage();
    $unit_tag   = sprintf( 'wpcf7-f%d-o1', $form_id );
}

if ( '' === $unit_tag ) {
    $unit_tag = sprintf( 'wpcf7-f%d-o1', $form_id );
}

// --- Render test: form_elements() contains both bot-check field names ---
$elements = '';
try {
    // Ensure current is set.
    WPCF7_ContactForm::get_instance( $form_id );
    // Use form_elements() which applies wpcf7_form_elements filter.
    if ( method_exists( $contact_form, 'form_elements' ) ) {
        $elements = $contact_form->form_elements();
    } elseif ( method_exists( $contact_form, 'replace_all_form_tags' ) ) {
        $elements = apply_filters( 'wpcf7_form_elements', $contact_form->replace_all_form_tags() );
    } else {
        $elements = apply_filters( 'wpcf7_form_elements', $contact_form->prop( 'form' ) );
    }
} catch ( Throwable $e ) {
    $failures[] = 'form_elements() threw ' . get_class( $e ) . ': ' . $e->getMessage();
    $elements   = '';
}

if ( false === strpos( $elements, ASW_Comment_Form::HONEYPOT_FIELD ) ) {
    $failures[] = 'form_elements() missing honeypot field name (' . ASW_Comment_Form::HONEYPOT_FIELD . ')';
} else {
    fwrite( STDOUT, "  - form_elements contains honeypot field\n" );
}

if ( false === strpos( $elements, ASW_Comment_Form::TIMESTAMP_FIELD ) ) {
    $failures[] = 'form_elements() missing timestamp field name (' . ASW_Comment_Form::TIMESTAMP_FIELD . ')';
} else {
    fwrite( STDOUT, "  - form_elements contains timestamp field\n" );
}

// Token verification: extract token and verify against form ID.
$token = '';
if ( preg_match( '/name="' . preg_quote( ASW_Comment_Form::TIMESTAMP_FIELD, '/' ) . '"[^>]*value="([^"]+)"/', $elements, $m ) ) {
    $token = $m[1];
} elseif ( preg_match( '/value="([^"]+)"[^>]*name="' . preg_quote( ASW_Comment_Form::TIMESTAMP_FIELD, '/' ) . '"/', $elements, $m ) ) {
    $token = $m[1];
}

if ( '' === $token ) {
    $failures[] = 'Could not extract timestamp token from form_elements()';
} else {
    $parsed = ASW_Comment_Form::parse_token( $token, $form_id );
    if ( null === $parsed ) {
        $failures[] = 'Token in form_elements() failed to verify against contact-form ID ' . $form_id . ' (token=' . substr( $token, 0, 20 ) . '...)';
    } else {
        fwrite( STDOUT, sprintf( "  - token verifies against form ID %d (ts %d)\n", $form_id, $parsed ) );
    }

    // Also assert it does NOT verify against a different ID.
    $wrong = ASW_Comment_Form::parse_token( $token, $form_id + 9999 );
    if ( null !== $wrong ) {
        $failures[] = 'Token incorrectly verified against wrong form ID';
    }
}

/**
 * Helper to drive a submission via WPCF7_ContactForm::submit() and return status.
 *
 * @param WPCF7_ContactForm $form
 * @param string            $unit_tag
 * @param string            $message_content Value for the textarea your-message.
 * @param string            $honeypot_value  Value for honeypot field (empty for legit).
 * @param string|null       $token_value     Value for timestamp field, null to omit.
 * @return string Status string (spam, mail_sent, validation_failed, etc.)
 */
function asw_cf7_submit( $form, string $unit_tag, string $message_content, string $honeypot_value = '', $token_value = null ): string {
    $form_id = (int) $form->id();

    asw_cf7_reset_submission();

    // Ensure current is our form.
    WPCF7_ContactForm::get_instance( $form_id );
    $form = WPCF7_ContactForm::get_instance( $form_id );

    // Regenerate unit_tag for this submission to avoid is_posted mismatch nuances.
    // Call form_html to get fresh unit_tag, but reuse provided one if not empty.
    if ( '' === $unit_tag ) {
        try {
            $form->form_html();
            $unit_tag = $form->unit_tag();
        } catch ( Throwable $e ) {
            $unit_tag = sprintf( 'wpcf7-f%d-o1', $form_id );
        }
    }

    // Prepare $_POST for WPCF7_Submission.
    $_POST = array();

    // CF7 hidden fields.
    $_POST['_wpcf7']              = (string) $form_id;
    $_POST['_wpcf7_version']      = defined( 'WPCF7_VERSION' ) ? WPCF7_VERSION : '6.0';
    $_POST['_wpcf7_locale']       = $form->locale() ? $form->locale() : 'en_US';
    $_POST['_wpcf7_unit_tag']     = $unit_tag;
    $_POST['_wpcf7_container_post'] = '0';
    $_POST['_wpcf7_posted_data_hash'] = '';

    // Form fields – keep required fields valid.
    $_POST['your-name']    = 'Smoke Tester';
    $_POST['your-email']   = 'smoke@example.com';
    $_POST['your-subject'] = 'Smoke test';
    $_POST['your-message'] = $message_content;

    // Bot fields – the integration pulls these from $_POST directly.
    // Honeypot: always set (even when empty) to test filled case; for normal cases empty.
    // We set the key even when empty to simulate browser submitting empty field.
    $_POST[ ASW_Comment_Form::HONEYPOT_FIELD ] = $honeypot_value;

    if ( null !== $token_value ) {
        $_POST[ ASW_Comment_Form::TIMESTAMP_FIELD ] = $token_value;
    }

    // Server vars.
    $_SERVER['REMOTE_ADDR']     = '203.0.113.7';
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; ASW Smoke)';
    $_SERVER['REMOTE_PORT']     = '12345';
    $_SERVER['REQUEST_URI']     = '/';

    // Ensure not logged in for nonce bypass.
    wp_set_current_user( 0 );

    // Submit via CF7 API (skip_mail to avoid mail delivery in smoke).
    try {
        $result = $form->submit( array( 'skip_mail' => true ) );
    } catch ( Throwable $e ) {
        return 'exception:' . $e->getMessage();
    }

    // Cleanup singleton for next call.
    // The next asw_cf7_reset_submission() will clear before next submit.

    if ( isset( $result['status'] ) ) {
        return (string) $result['status'];
    }

    // Fallback: try to get status via Submission instance.
    $submission = WPCF7_Submission::get_instance();
    if ( $submission && method_exists( $submission, 'get_status' ) ) {
        return (string) $submission->get_status();
    }

    return 'unknown';
}

// Only run submission assertions if form ID and elements were sane.
if ( $form_id > 0 && '' !== $unit_tag ) {
    // Generate a valid token bound to this form, minted in the past (so submit_time doesn't flag).
    $valid_token_old = ASW_Comment_Form::mint_token( time() - 30, $form_id );

    // --- Spam case: 3 links exceeds links_max (2) → must be spam ---
    $spam_content = 'Great deals here: http://example.com and https://example.net plus http://example.org today.';
    $spam_status  = asw_cf7_submit( $contact_form, $unit_tag, $spam_content, '', $valid_token_old );

    if ( 'spam' !== $spam_status ) {
        $failures[] = sprintf( "CF7 spam case: expected 'spam', got %s (content with 3 links)", var_export( $spam_status, true ) );
    } else {
        fwrite( STDOUT, "  - CF7 spam case OK (3 links -> spam)\n" );
    }

    // Regenerate unit_tag for next submission (WPCF7 regenerates each form_html call; reset and get fresh).
    asw_cf7_reset_submission();
    WPCF7_ContactForm::get_instance( $form_id );
    $contact_form->form_html();
    $unit_tag2 = $contact_form->unit_tag();

    $valid_token_old2 = ASW_Comment_Form::mint_token( time() - 30, $form_id );

    // --- Ham case: neutral, link-free, stop-word-free → must NOT be spam ---
    $ham_content = 'Thank you for this helpful article. I really enjoyed reading it this morning.';
    $ham_status  = asw_cf7_submit( $contact_form, $unit_tag2, $ham_content, '', $valid_token_old2 );

    if ( 'spam' === $ham_status ) {
        $failures[] = sprintf( "CF7 ham case: expected NOT 'spam', got %s", var_export( $ham_status, true ) );
    } else {
        fwrite( STDOUT, sprintf( "  - CF7 ham case OK (ham -> %s)\n", var_export( $ham_status, true ) ) );
    }
}

if ( ! empty( $failures ) ) {
    fwrite( STDERR, "cf7-check: FAIL\n" );
    foreach ( $failures as $failure ) {
        fwrite( STDERR, '  - ' . $failure . "\n" );
    }
    exit( 1 );
}

echo "cf7-check: OK — spam(3 links)='spam', ham(neutral) is not spam, form_elements contains bot fields, token verifies\n";
exit( 0 );
