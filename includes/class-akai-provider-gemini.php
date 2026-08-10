<?php
/**
 * Google Gemini provider, using the Interactions API.
 *
 * @package autokeywordsai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Talks to the Gemini Interactions API.
 *
 * Google recommends the Interactions API over generateContent for new development, so this
 * targets /v1beta/interactions and pins Api-Revision to keep the response shape stable.
 */
class AKAI_Provider_Gemini implements AKAI_Provider {

	/**
	 * Interactions endpoint.
	 *
	 * @var string
	 */
	const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/interactions';

	/**
	 * Pinned API revision, so a server-side change cannot silently reshape responses.
	 *
	 * @var string
	 */
	const API_REVISION = '2026-05-20';

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	const TIMEOUT = 30;

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Model name.
	 *
	 * @var string
	 */
	private $model;

	/**
	 * HTTP transport: ( string $url, array $args ) => array|WP_Error.
	 *
	 * @var callable
	 */
	private $http;

	/**
	 * Constructor.
	 *
	 * @param string        $api_key API key.
	 * @param string        $model   Model name, e.g. 'gemini-3.5-flash'.
	 * @param callable|null $http    Optional HTTP transport, injected by tests.
	 */
	public function __construct( string $api_key, string $model, ?callable $http = null ) {
		$this->api_key = $api_key;
		$this->model   = $model;
		$this->http    = $http ? $http : function ( $url, $args ) {
			return wp_remote_post( $url, $args );
		};
	}

	/**
	 * Builds the Interactions request body.
	 *
	 * The system instruction and product text are concatenated into a single input string,
	 * which is what the Interactions API accepts.
	 *
	 * @param array $spec Prompt spec.
	 * @return array
	 */
	public function build_request_body( array $spec ): array {
		return array(
			'model'           => $this->model,
			'input'           => $spec['system'] . "\n\n" . $spec['user'],
			'response_format' => array(
				'type'      => 'text',
				'mime_type' => 'application/json',
				'schema'    => $spec['schema'],
			),
		);
	}

	/**
	 * Generates keywords.
	 *
	 * @param array $spec Prompt spec.
	 * @return array|WP_Error
	 */
	public function generate( array $spec ) {
		if ( '' === trim( $this->api_key ) ) {
			return new WP_Error( 'akai_no_key', __( 'No API key configured.', 'autokeywordsai' ) );
		}

		$response = call_user_func(
			$this->http,
			self::ENDPOINT,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $this->api_key,
					'Api-Revision'   => self::API_REVISION,
				),
				'body'    => wp_json_encode( $this->build_request_body( $spec ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'akai_http_error', $response->get_error_message() );
		}

		$status = (int) ( $response['response']['code'] ?? 0 );
		$body   = (string) ( $response['body'] ?? '' );

		if ( 429 === $status ) {
			return new WP_Error( 'akai_rate_limited', __( 'Provider rate limit reached.', 'autokeywordsai' ) );
		}

		if ( 200 !== $status ) {
			return new WP_Error(
				'akai_http_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: response body excerpt. */
					__( 'Provider returned HTTP %1$d: %2$s', 'autokeywordsai' ),
					$status,
					substr( $body, 0, 300 )
				)
			);
		}

		return $this->parse_response( $body );
	}

	/**
	 * Extracts the keyword array from an Interactions response body.
	 *
	 * @param string $body Raw response body.
	 * @return array|WP_Error
	 */
	public function parse_response( string $body ) {
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'akai_bad_response', __( 'Provider response was not JSON.', 'autokeywordsai' ) );
		}

		$text = null;
		foreach ( (array) ( $decoded['steps'] ?? array() ) as $step ) {
			if ( 'model_output' !== ( $step['type'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $step['content'] ?? array() ) as $part ) {
				if ( 'text' === ( $part['type'] ?? '' ) && isset( $part['text'] ) ) {
					$text = (string) $part['text'];
					break 2;
				}
			}
		}

		if ( null === $text ) {
			return new WP_Error( 'akai_bad_response', __( 'Provider response contained no model output.', 'autokeywordsai' ) );
		}

		return akai_decode_keyword_payload( $text );
	}
}
