<?php
/**
 * LLM exception type.
 *
 * @package SCP
 */

namespace SCP\LLM;

/**
 * Thrown on unrecoverable LLM API failures.
 */
class LLM_Exception extends \Exception {

	/**
	 * Provider id.
	 *
	 * @var string
	 */
	private $provider;

	/**
	 * Constructor.
	 *
	 * @param string $message  Error message.
	 * @param string $provider Provider id.
	 * @param int    $code     HTTP-ish status code.
	 */
	public function __construct( string $message, string $provider = '', int $code = 0 ) {
		parent::__construct( $message, $code );
		$this->provider = $provider;
	}

	/**
	 * Provider accessor.
	 *
	 * @return string
	 */
	public function get_provider(): string {
		return $this->provider;
	}
}
