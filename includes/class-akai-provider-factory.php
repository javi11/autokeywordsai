<?php
/**
 * Provider factory.
 *
 * @package autokeywordsai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Turns settings into a configured provider instance.
 */
class AKAI_Provider_Factory {

	/**
	 * Builds the configured provider.
	 *
	 * @param array         $settings     Settings array.
	 * @param string|null   $constant_key Value of AKAI_API_KEY, or null when undefined.
	 * @param callable|null $http         Optional HTTP transport, injected by tests.
	 * @return AKAI_Provider
	 */
	public static function make( array $settings, ?string $constant_key = null, ?callable $http = null ): AKAI_Provider {
		$provider = (string) ( $settings['provider'] ?? 'gemini' );
		$api_key  = AKAI_Settings::resolve_api_key( $settings, $constant_key );

		$model = trim( (string) ( $settings['model'] ?? '' ) );
		if ( '' === $model ) {
			$model = AKAI_Settings::default_model( $provider );
		}

		if ( 'openai' === $provider ) {
			return new AKAI_Provider_OpenAI(
				$api_key,
				$model,
				(string) ( $settings['base_url'] ?? 'https://api.openai.com/v1' ),
				$http
			);
		}

		return new AKAI_Provider_Gemini( $api_key, $model, $http );
	}
}
