<?php
/**
 * Check interface for AntiSpam Wizard spam detection.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Interface for all spam check implementations.
 */
interface ASW_Check {

    /**
     * Run the spam check against comment data.
     *
     * @param array $comment_data WordPress comment data array.
     * @return ASW_Check_Result Result of the check.
     */
    public function check( array $comment_data ): ASW_Check_Result;
}
