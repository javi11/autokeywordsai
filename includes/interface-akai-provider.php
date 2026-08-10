<?php
/**
 * Provider contract.
 *
 * @package autokeywordsai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * A keyword-generating AI provider.
 *
 * Implementations translate the provider-neutral spec from AKAI_Prompt into their own wire
 * format and the response back into a plain keyword array. They contain no prompt text.
 */
interface AKAI_Provider {

	/**
	 * Generates keywords for one product.
	 *
	 * @param array $spec Spec from AKAI_Prompt::build_spec().
	 * @return array{primary: string, secondary: array<int, string>}|WP_Error
	 *         WP_Error codes: akai_no_key, akai_rate_limited, akai_http_error, akai_bad_response.
	 */
	public function generate( array $spec );
}

/**
 * Decodes the model-authored JSON payload into a keyword array.
 *
 * Shared by every provider: the wire formats differ, but the payload the model writes is
 * identical because both providers send the same schema.
 *
 * @param string $text JSON text produced by the model.
 * @return array{primary: string, secondary: array<int, string>}|WP_Error
 */
function akai_decode_keyword_payload( string $text ) {
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
