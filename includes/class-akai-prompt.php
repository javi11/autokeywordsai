<?php
/**
 * Provider-neutral prompt construction.
 *
 * @package autokeywordsai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Builds the provider-neutral request spec.
 *
 * Pure by design: no WordPress calls beyond sanitize_text_field, no HTTP, no options.
 * All prompt-quality iteration happens here, once, for every provider.
 */
class AKAI_Prompt {

	/**
	 * Schema name sent to providers that require one.
	 *
	 * @var string
	 */
	const SCHEMA_NAME = 'akai_keywords';

	/**
	 * Maximum keywords requested from the model.
	 *
	 * @var int
	 */
	const MAX_KEYWORDS = 3;

	/**
	 * Builds the spec for one product.
	 *
	 * @param array  $product  Keys: title (required), categories (string[]), short_description (string).
	 * @param string $language Target language, normally the site locale (e.g. 'es_ES').
	 * @return array{system: string, user: string, schema: array}
	 */
	public static function build_spec( array $product, string $language ): array {
		return array(
			'system' => self::system_instruction( $language ),
			'user'   => self::user_text( $product ),
			'schema' => self::schema(),
		);
	}

	/**
	 * The system instruction.
	 *
	 * @param string $language Target language tag.
	 * @return string
	 */
	private static function system_instruction( string $language ): string {
		return implode(
			' ',
			array(
				'You are an e-commerce SEO specialist choosing Rank Math focus keywords for a product page.',
				sprintf( 'Write all keywords in the language identified by the locale %s.', $language ),
				'Return one primary keyword and up to two secondary keywords.',
				'Use the words a shopper would actually type into Google with buying intent.',
				'Prefer two to four word phrases. Never use the brand or shop name.',
				'Never include commas inside a single keyword. Return lowercase keywords only.',
			)
		);
	}

	/**
	 * The user-facing product context.
	 *
	 * Labels for absent optional fields are omitted entirely rather than left empty, so the
	 * model never sees a dangling "Categories:" with nothing after it.
	 *
	 * @param array $product Product data.
	 * @return string
	 */
	private static function user_text( array $product ): string {
		$lines = array( 'Product: ' . sanitize_text_field( $product['title'] ?? '' ) );

		if ( ! empty( $product['categories'] ) ) {
			$lines[] = 'Categories: ' . sanitize_text_field( implode( ', ', (array) $product['categories'] ) );
		}

		if ( ! empty( $product['short_description'] ) ) {
			$lines[] = 'Description: ' . sanitize_text_field( $product['short_description'] );
		}

		return implode( "\n", $lines );
	}

	/**
	 * The JSON schema, shaped to satisfy strict mode on both providers.
	 *
	 * @return array
	 */
	private static function schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'primary'   => array(
					'type'        => 'string',
					'description' => 'The single best focus keyword.',
				),
				'secondary' => array(
					'type'        => 'array',
					'description' => 'Up to two supporting keywords.',
					'items'       => array( 'type' => 'string' ),
				),
			),
			'required'             => array( 'primary', 'secondary' ),
			'additionalProperties' => false,
		);
	}
}
