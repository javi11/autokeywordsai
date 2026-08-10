<?php
/**
 * Settings storage and sanitization.
 *
 * @package autokeywordsai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Owns the plugin's single option, its defaults, and its sanitization rules.
 */
class AKAI_Settings {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION = 'akai_settings';

	/**
	 * Lowest allowed requests-per-minute.
	 *
	 * @var int
	 */
	const MIN_RPM = 1;

	/**
	 * Highest allowed requests-per-minute.
	 *
	 * @var int
	 */
	const MAX_RPM = 600;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'provider' => 'gemini',
			'api_key'  => '',
			'base_url' => 'https://api.openai.com/v1',
			'model'    => '',
			'language' => '',
			'rpm'      => 10,
		);
	}

	/**
	 * Valid provider ids.
	 *
	 * @return array<int, string>
	 */
	public static function providers(): array {
		return array( 'gemini', 'openai' );
	}

	/**
	 * Suggested model for a provider.
	 *
	 * Free text in the UI, because model names churn faster than plugin releases; this is
	 * only the placeholder and the fallback when the field is left blank.
	 *
	 * @param string $provider Provider id.
	 * @return string
	 */
	public static function default_model( string $provider ): string {
		return 'openai' === $provider ? 'gpt-5.6' : 'gemini-3.5-flash';
	}

	/**
	 * Sanitizes submitted settings.
	 *
	 * Pure: takes the existing settings rather than reading the option, so it is testable
	 * and so the write-only API key rule is explicit.
	 *
	 * @param array $input    Raw submitted values.
	 * @param array $existing Currently stored settings.
	 * @return array
	 */
	public static function sanitize( array $input, array $existing ): array {
		$existing = array_merge( self::defaults(), $existing );

		$provider = isset( $input['provider'] ) ? (string) $input['provider'] : $existing['provider'];
		if ( ! in_array( $provider, self::providers(), true ) ) {
			$provider = 'gemini';
		}

		// An empty submitted key means "leave it alone" — the field renders masked and never
		// echoes the stored value back to the browser.
		$submitted_key = isset( $input['api_key'] ) ? trim( (string) $input['api_key'] ) : '';
		$api_key       = '' === $submitted_key ? (string) $existing['api_key'] : $submitted_key;

		$base_url = isset( $input['base_url'] ) ? trim( (string) $input['base_url'] ) : $existing['base_url'];
		$base_url = rtrim( $base_url, '/' );
		if ( '' === $base_url ) {
			$base_url = self::defaults()['base_url'];
		}

		$rpm = isset( $input['rpm'] ) ? (int) $input['rpm'] : (int) $existing['rpm'];
		$rpm = max( self::MIN_RPM, min( self::MAX_RPM, $rpm ) );

		return array(
			'provider' => $provider,
			'api_key'  => $api_key,
			'base_url' => $base_url,
			'model'    => isset( $input['model'] ) ? trim( (string) $input['model'] ) : (string) $existing['model'],
			'language' => isset( $input['language'] ) ? trim( (string) $input['language'] ) : (string) $existing['language'],
			'rpm'      => $rpm,
		);
	}

	/**
	 * Resolves the effective API key, preferring a wp-config.php constant.
	 *
	 * @param array       $settings Settings array.
	 * @param string|null $constant Value of AKAI_API_KEY, or null when undefined.
	 * @return string
	 */
	public static function resolve_api_key( array $settings, ?string $constant ): string {
		if ( is_string( $constant ) && '' !== trim( $constant ) ) {
			return trim( $constant );
		}
		return (string) ( $settings['api_key'] ?? '' );
	}

	/**
	 * Reads the stored settings merged over the defaults.
	 *
	 * @return array
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * The effective target language, defaulting to the site locale.
	 *
	 * @param array $settings Settings array.
	 * @return string
	 */
	public static function language( array $settings ): string {
		$language = trim( (string) ( $settings['language'] ?? '' ) );
		return '' !== $language ? $language : get_locale();
	}
}
