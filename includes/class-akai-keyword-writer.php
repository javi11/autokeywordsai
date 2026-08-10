<?php
/**
 * Rank Math focus keyword writing.
 *
 * @package autokeywordsai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Owns the only update_post_meta call in the plugin.
 *
 * The skip rule and the meta-value construction are pure static methods so they can be
 * tested without WordPress; write() is the thin WordPress-touching wrapper.
 */
class AKAI_Keyword_Writer {

	/**
	 * Rank Math's focus keyword meta key.
	 *
	 * @var string
	 */
	const META_KEY = 'rank_math_focus_keyword';

	/**
	 * Whether a product with this existing meta value should be written to.
	 *
	 * @param mixed $existing Current meta value (string, null, or false).
	 * @return bool
	 */
	public static function should_write( $existing ): bool {
		return '' === trim( (string) $existing );
	}

	/**
	 * Builds the comma-separated meta value.
	 *
	 * Rank Math treats the value as a comma-separated list whose first entry is primary, so
	 * commas inside an individual keyword are stripped rather than escaped.
	 *
	 * @param array $result Provider result: primary (string), secondary (string[]).
	 * @return string|WP_Error
	 */
	public static function to_meta_value( array $result ) {
		// The primary is validated on its own rather than as the first of a merged list. If
		// the model produced no primary, promoting a secondary into that slot would silently
		// make Rank Math score the page against a keyword the model ranked as secondary.
		$primary = self::clean_keyword( (string) ( $result['primary'] ?? '' ) );

		if ( '' === $primary ) {
			return new WP_Error(
				'akai_bad_response',
				__( 'Model returned no usable primary keyword.', 'autokeywordsai' )
			);
		}

		$keywords = array( $primary );
		$seen     = array( self::fingerprint( $primary ) => true );

		foreach ( (array) ( $result['secondary'] ?? array() ) as $candidate ) {
			if ( count( $keywords ) >= AKAI_Prompt::MAX_KEYWORDS ) {
				break;
			}

			$keyword = self::clean_keyword( (string) $candidate );
			if ( '' === $keyword ) {
				continue;
			}

			$fingerprint = self::fingerprint( $keyword );
			if ( isset( $seen[ $fingerprint ] ) ) {
				continue;
			}

			$seen[ $fingerprint ] = true;
			$keywords[]           = $keyword;
		}

		return implode( ', ', $keywords );
	}

	/**
	 * Normalises one keyword: commas removed, whitespace collapsed, trimmed.
	 *
	 * @param string $keyword Raw keyword.
	 * @return string Empty string when nothing usable remains.
	 */
	private static function clean_keyword( string $keyword ): string {
		$keyword = str_replace( ',', ' ', $keyword );
		return trim( preg_replace( '/\s+/', ' ', $keyword ) );
	}

	/**
	 * Case-insensitive comparison key used for deduplication.
	 *
	 * @param string $keyword Cleaned keyword.
	 * @return string
	 */
	private static function fingerprint( string $keyword ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $keyword ) : strtolower( $keyword );
	}

	/**
	 * Writes the keyword for a product, honouring the skip rule.
	 *
	 * @param int   $product_id Product post ID.
	 * @param array $result     Provider result.
	 * @return bool|WP_Error True on write, false when skipped, WP_Error when unusable.
	 */
	public static function write( int $product_id, array $result ) {
		$existing = get_post_meta( $product_id, self::META_KEY, true );
		if ( ! self::should_write( $existing ) ) {
			return false;
		}

		$value = self::to_meta_value( $result );
		if ( is_wp_error( $value ) ) {
			return $value;
		}

		update_post_meta( $product_id, self::META_KEY, $value );
		return true;
	}
}
