<?php
/**
 * Tests for the statistics display view (settings-stats.php).
 */

use PHPUnit\Framework\TestCase;

class StatsDisplayTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        global $wp_options;
        $wp_options = array();
    }

    private function render_stats_view(): string {
        ob_start();
        include dirname( __DIR__ ) . '/antispam-wizard/admin/views/settings-stats.php';
        return ob_get_clean();
    }

    public function test_enabled_checks_with_zero_counts_appear_in_breakdown(): void {
        // Record stats for only one check.
        ASW_Stats::record( array( 'links' ) );

        // All checks enabled by default — render should show all 8.
        $html = $this->render_stats_view();

        // Links has a count of 1.
        $this->assertStringContainsString( 'data-check="links"', $html );

        // These have zero counts but are enabled, so they should appear.
        $this->assertStringContainsString( 'data-check="honeypot"', $html );
        $this->assertStringContainsString( 'data-check="submit_time"', $html );
        $this->assertStringContainsString( 'data-check="stop_words"', $html );
        $this->assertStringContainsString( 'data-check="emoji"', $html );
        $this->assertStringContainsString( 'data-check="spacing"', $html );
        $this->assertStringContainsString( 'data-check="multi_lang"', $html );
        $this->assertStringContainsString( 'data-check="duplicate"', $html );
        $this->assertStringContainsString( 'data-check="bayesian"', $html );
        $this->assertStringContainsString( 'data-check="similarity"', $html );
    }

    public function test_disabled_checks_with_zero_counts_do_not_appear(): void {
        // Disable all checks except links.
        $settings = ASW_Settings::get_all();
        $settings['stop_words_enabled'] = false;
        $settings['emoji_enabled']      = false;
        $settings['spacing_enabled']    = false;
        $settings['multi_lang_enabled'] = false;
        $settings['duplicate_enabled']  = false;
        $settings['bayesian_enabled']   = false;
        $settings['similarity_enabled'] = false;
        update_option( ASW_Settings::OPTION_KEY, $settings );

        ASW_Stats::record( array( 'links' ) );

        $html = $this->render_stats_view();

        $this->assertStringContainsString( 'data-check="links"', $html );
        // Disabled checks with no recorded stats should not appear.
        $this->assertStringNotContainsString( 'data-check="stop_words"', $html );
        $this->assertStringNotContainsString( 'data-check="emoji"', $html );
    }

    public function test_disabled_checks_with_existing_counts_still_appear(): void {
        // Record stats, then disable the check.
        ASW_Stats::record( array( 'links', 'emoji' ) );

        $settings = ASW_Settings::get_all();
        $settings['emoji_enabled'] = false;
        update_option( ASW_Settings::OPTION_KEY, $settings );

        $html = $this->render_stats_view();

        // Emoji has recorded data, so it should still appear even though disabled.
        $this->assertStringContainsString( 'data-check="emoji"', $html );
    }

    public function test_matches_clarification_note_is_displayed(): void {
        $html = $this->render_stats_view();

        $this->assertStringContainsString( 'asw-stats-note', $html );
        $this->assertStringContainsString( 'can trigger multiple checks', $html );
    }

    public function test_zero_count_checks_show_zero_value(): void {
        ASW_Stats::record( array( 'links' ) );

        $html = $this->render_stats_view();

        // Find the stop_words row — it should have count 0.
        preg_match( '/data-check="stop_words".*?asw-bar-count">(.*?)<\/div>/s', $html, $matches );
        $this->assertNotEmpty( $matches, 'stop_words row should exist' );
        $this->assertSame( '0', trim( $matches[1] ) );
    }

    public function test_no_stats_with_all_enabled_shows_all_checks(): void {
        // No recorded stats at all, all checks enabled by default.
        $html = $this->render_stats_view();

        $this->assertStringContainsString( 'data-check="honeypot"', $html );
        $this->assertStringContainsString( 'data-check="submit_time"', $html );
        $this->assertStringContainsString( 'data-check="stop_words"', $html );
        $this->assertStringContainsString( 'data-check="links"', $html );
        $this->assertStringContainsString( 'data-check="emoji"', $html );
        $this->assertStringContainsString( 'data-check="spacing"', $html );
        $this->assertStringContainsString( 'data-check="multi_lang"', $html );
        $this->assertStringContainsString( 'data-check="duplicate"', $html );
        $this->assertStringContainsString( 'data-check="bayesian"', $html );
        $this->assertStringContainsString( 'data-check="similarity"', $html );
    }
}
