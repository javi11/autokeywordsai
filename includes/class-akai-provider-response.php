<?php
/**
 * Shared provider response decoding.
 *
 * @package autokeywordsai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Decodes the model-authored JSON payload every provider receives.
 *
 * The wire formats differ per vendor, but the payload the model writes is identical
 * because both providers send the same schema, so this decoding lives in one place.
 */
class AKAI_Provider_Response {

	/**
	 * Decodes the model-authored JSON payload into a keyword array.
	 *
	 * @param string $text JSON text produced by the model.
	 * @return array{primary: string, secondary: array<int, string>}|WP_Error
	 */
	public static function decode_keywords( string $text ) {
		$payload = json_decode( trim( $text ), true );

		if ( ! is_array( $payload ) || ! isset( $payload['primary'] ) || ! is_string( $payload['primary'] ) ) {
			return new WP_Error(
				'akai_bad_response',
				__( 'Model output did not match the requested schema.', 'autokeywordsai' )
			);
		}

		$secondary = array();
		foreach ( (array) ( $payload['secondary'] ?? array() ) as $keyword ) {
			if ( is_string( $keyword ) ) {
				$secondary[] = $keyword;
			}
		}

		return array(
			'primary'   => $payload['primary'],
			'secondary' => $secondary,
		);
	}
}
