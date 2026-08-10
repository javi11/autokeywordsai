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
