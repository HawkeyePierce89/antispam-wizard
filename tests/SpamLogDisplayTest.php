<?php
/**
 * Tests for the spam log display view (settings-log.php).
 */

use PHPUnit\Framework\TestCase;

class SpamLogDisplayTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        global $wp_options, $wp_transients, $wp_nonces;
        $wp_options    = array();
        $wp_transients = array();
        $wp_nonces     = array();
    }

    private function render_log_view(): string {
        ob_start();
        include dirname( __DIR__ ) . '/antispam-wizard/admin/views/settings-log.php';
        return ob_get_clean();
    }

    public function test_empty_log_shows_empty_state(): void {
        $html = $this->render_log_view();

        $this->assertStringContainsString( 'asw-empty', $html );
        $this->assertStringContainsString( 'No spam has been blocked yet.', $html );
        // No clear-log form when there are no entries.
        $this->assertStringNotContainsString( 'asw_clear_log_nonce', $html );
        // No log list container.
        $this->assertStringNotContainsString( 'asw-log-list', $html );
    }

    public function test_populated_log_renders_entries(): void {
        ASW_Spam_Log::add_entry(
            'Buy cheap pills now',
            array( 'stop_words', 'links' ),
            str_repeat( 'a', 64 ),
            42
        );

        $html = $this->render_log_view();

        // List container and clear-log form present.
        $this->assertStringContainsString( 'asw-log-list', $html );
        $this->assertStringContainsString( 'asw_clear_log_nonce', $html );
        $this->assertStringContainsString( 'Clear Log', $html );

        // Excerpt rendered.
        $this->assertStringContainsString( 'Buy cheap pills now', $html );

        // Check badges with their human labels and CSS modifiers.
        $this->assertStringContainsString( 'asw-badge--stop-words', $html );
        $this->assertStringContainsString( 'Stop Words', $html );
        $this->assertStringContainsString( 'asw-badge--links', $html );

        // IP hash truncated to 12 chars + ellipsis.
        $this->assertStringContainsString( str_repeat( 'a', 12 ) . '…', $html );

        // Comment present but untrained → Ham/Spam action buttons.
        $this->assertStringContainsString( 'asw-mark-ham', $html );
        $this->assertStringContainsString( 'asw-mark-spam', $html );
        $this->assertStringContainsString( 'data-comment-id="42"', $html );

        // Empty state must not appear.
        $this->assertStringNotContainsString( 'No spam has been blocked yet.', $html );
    }

    public function test_bot_checks_render_labels(): void {
        ASW_Spam_Log::add_entry(
            'Bot stuff',
            array( 'honeypot', 'submit_time' ),
            str_repeat( 'x', 64 ),
            43
        );

        $html = $this->render_log_view();

        $this->assertStringContainsString( 'asw-badge--honeypot', $html );
        $this->assertStringContainsString( 'Honeypot', $html );
        $this->assertStringContainsString( 'asw-badge--submit-time', $html );
        $this->assertStringContainsString( 'Submit Time', $html );
    }

    public function test_trained_entry_shows_trained_pill_not_buttons(): void {
        ASW_Spam_Log::add_entry( 'Spammy text', array( 'bayesian' ), str_repeat( 'b', 64 ), 7 );
        ASW_Spam_Log::mark_trained( 7, 'spam' );

        $html = $this->render_log_view();

        // Trained pill present with category.
        $this->assertStringContainsString( 'asw-pill--trained', $html );
        $this->assertStringContainsString( 'Trained as spam', $html );

        // Action buttons must be suppressed for trained entries.
        $this->assertStringNotContainsString( 'asw-mark-ham', $html );
        $this->assertStringNotContainsString( 'asw-mark-spam', $html );
    }

    public function test_unknown_check_falls_back_to_raw_label(): void {
        ASW_Spam_Log::add_entry( 'Odd entry', array( 'mystery_check' ), str_repeat( 'c', 64 ), 0 );

        $html = $this->render_log_view();

        // Unknown check name renders verbatim as the label with the default modifier.
        $this->assertStringContainsString( 'mystery_check', $html );
        $this->assertStringContainsString( 'asw-badge--duplicates', $html );

        // comment_id 0 → no actions block content.
        $this->assertStringNotContainsString( 'asw-mark-ham', $html );
    }

    public function test_manual_entry_renders_the_moderator_badge(): void {
        ASW_Spam_Log::add_entry(
            'Marked by hand from the comments screen',
            array( ASW_Moderation::CHECK_NAME ),
            str_repeat( 'e', 64 ),
            77
        );

        $html = $this->render_log_view();

        // The 'manual' check name is a known label, not the raw-name fallback.
        $this->assertStringContainsString( 'Marked by moderator', $html );
        $this->assertStringNotContainsString( '>manual<', $html );
        $this->assertStringContainsString( 'asw-badge--duplicates', $html );

        // Still a comment entry: the training pills are offered.
        $this->assertStringContainsString( 'data-comment-id="77"', $html );
    }

    public function test_entry_date_is_formatted(): void {
        ASW_Spam_Log::add_entry( 'Dated entry', array( 'emoji' ), str_repeat( 'd', 64 ), 1 );

        $html = $this->render_log_view();

        // The date day/time spans are rendered (date_i18n via strtotime on the
        // current_time mysql string).
        $this->assertStringContainsString( 'asw-log-date-day', $html );
        $this->assertStringContainsString( 'asw-log-date-time', $html );
    }

    public function test_log_description_covers_comments_and_forms(): void {
        $html = $this->render_log_view();
        $this->assertStringContainsString( 'form submissions', strtolower( $html ) );
        $this->assertStringContainsString( 'comments', strtolower( $html ) );
    }

    public function test_form_entry_shows_source_badge_and_hides_pills(): void {
        ASW_Spam_Log::add_entry( 'Form spam text', array( 'stop_words' ), str_repeat( 'e', 64 ), 0, 'form', hash( 'sha256', 'test@example.com' ) );

        $html = $this->render_log_view();

        $this->assertStringContainsString( 'asw-log-source', $html );
        $this->assertStringContainsString( 'Form', $html );
        // Form entries never offer Ham/Spam pills even though comment_id is 0.
        $this->assertStringNotContainsString( 'asw-mark-ham', $html );
        $this->assertStringNotContainsString( 'asw-mark-spam', $html );
    }

    public function test_form_entry_with_comment_id_still_hides_pills(): void {
        // Even if a form entry somehow had a comment_id, it should not offer training pills.
        ASW_Spam_Log::add_entry( 'Form with id', array( 'links' ), str_repeat( 'f', 64 ), 99, 'form', hash( 'sha256', 'foo@example.com' ) );

        $html = $this->render_log_view();

        $this->assertStringContainsString( 'asw-log-source', $html );
        $this->assertStringNotContainsString( 'asw-mark-ham', $html );
        $this->assertStringNotContainsString( 'asw-mark-spam', $html );
        // Verify the excerpt is still rendered.
        $this->assertStringContainsString( 'Form with id', $html );
    }

    public function test_comment_entry_shows_pills_and_no_form_badge(): void {
        ASW_Spam_Log::add_entry( 'Comment spam', array( 'links' ), str_repeat( 'g', 64 ), 42, 'comment', '' );

        $html = $this->render_log_view();

        $this->assertStringNotContainsString( 'asw-log-source', $html );
        $this->assertStringContainsString( 'asw-mark-ham', $html );
        $this->assertStringContainsString( 'asw-mark-spam', $html );
        $this->assertStringContainsString( 'data-comment-id="42"', $html );
    }

    public function test_legacy_entry_without_source_renders_as_comment(): void {
        // Directly inject a legacy entry without source key.
        $legacy_entry = array(
            'entry_id'         => 'legacy-' . microtime( true ),
            'date'             => current_time( 'mysql' ),
            'text_excerpt'     => 'Legacy text',
            'checks_triggered' => array( 'stop_words' ),
            'ip_hash'          => str_repeat( 'h', 64 ),
            'comment_id'       => 55,
            // No source or email_hash keys.
        );
        $entries = array( $legacy_entry );
        update_option( ASW_Spam_Log::OPTION_KEY, $entries );
        // Invalidate cache so get_entries reads the raw option.
        wp_cache_delete( ASW_Spam_Log::OPTION_KEY, 'options' );

        $html = $this->render_log_view();

        // Should not have form badge.
        $this->assertStringNotContainsString( 'asw-log-source', $html );
        // Should still show comment training pills.
        $this->assertStringContainsString( 'asw-mark-ham', $html );
        $this->assertStringContainsString( 'data-comment-id="55"', $html );
        $this->assertStringContainsString( 'Legacy text', $html );
    }

    public function test_log_css_contains_source_rule(): void {
        $css = file_get_contents( dirname( __DIR__ ) . '/antispam-wizard/admin/css/admin.css' );
        $this->assertStringContainsString( '.asw-log-source', $css );
    }
}
