<?php
/**
 * Error logging.
 *
 * @package autokeywordsai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Logs failures to WooCommerce's logger and keeps a short list for the settings screen.
 *
 * Failures nobody can see are how a tool like this rots, so every error lands in two
 * places: WooCommerce's log viewer for detail, and a capped option for at-a-glance status.
 */
class AKAI_Logger {

	/**
	 * Option holding the recent-errors list.
	 *
	 * @var string
	 */
	const ERRORS_OPTION = 'akai_recent_errors';

	/**
	 * How many recent errors to retain.
	 *
	 * @var int
	 */
	const MAX_ERRORS = 50;

	/**
	 * WooCommerce log source.
	 *
	 * @var string
	 */
	const SOURCE = 'autokeywordsai';

	/**
	 * Prepends an entry and enforces the cap.
	 *
	 * @param mixed $existing Existing list; a non-array (corrupt option) is treated as empty.
	 * @param array $entry    Entry with keys product_id, message, time.
	 * @return array
	 */
	public static function append_error( $existing, array $entry ): array {
		$list = is_array( $existing ) ? $existing : array();
		array_unshift( $list, $entry );
		return array_slice( $list, 0, self::MAX_ERRORS );
	}

	/**
	 * Records a failure for one product.
	 *
	 * @param int    $product_id Product post ID.
	 * @param string $message    Failure message.
	 * @return void
	 */
	public static function error( int $product_id, string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error(
				sprintf( 'Product %d: %s', $product_id, $message ),
				array( 'source' => self::SOURCE )
			);
		}

		update_option(
			self::ERRORS_OPTION,
			self::append_error(
				get_option( self::ERRORS_OPTION, array() ),
				array(
					'product_id' => $product_id,
					'message'    => $message,
					'time'       => time(),
				)
			),
			false
		);
	}

	/**
	 * Returns the recent-errors list.
	 *
	 * @return array
	 */
	public static function recent_errors(): array {
		$stored = get_option( self::ERRORS_OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Clears the recent-errors list.
	 *
	 * @return void
	 */
	public static function clear(): void {
		update_option( self::ERRORS_OPTION, array(), false );
	}
}
