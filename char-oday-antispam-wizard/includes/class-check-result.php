<?php
/**
 * Check result value object.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Immutable value object representing the result of a spam check.
 */
class ANTISPAMWIZARD_Check_Result {

    /** @var bool */
    private $spam;

    /** @var string */
    private $check_name;

    /** @var string */
    private $details;

    /**
     * @param bool   $spam       Whether the comment is spam.
     * @param string $check_name Name of the check that produced this result.
     * @param string $details    Human-readable details about the result.
     */
    public function __construct( bool $spam, string $check_name, string $details ) {
        $this->spam       = $spam;
        $this->check_name = $check_name;
        $this->details    = $details;
    }

    public function is_spam(): bool {
        return $this->spam;
    }

    public function get_check_name(): string {
        return $this->check_name;
    }

    public function get_details(): string {
        return $this->details;
    }
}
