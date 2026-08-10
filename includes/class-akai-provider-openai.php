<?php
/**
 * OpenAI-compatible provider.
 *
 * @package autokeywordsai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Talks to any endpoint speaking the OpenAI /chat/completions format.
 *
 * Deliberately targets /chat/completions rather than OpenAI's newer Responses API:
 * chat/completions is current and non-deprecated, and it is the format Groq, OpenRouter,
 * DeepSeek, Together, Ollama and LM Studio implement. Supporting them is the point of this
 * provider, so the compatibility format wins over the vendor-specific one.
 */
class AKAI_Provider_OpenAI implements AKAI_Provider {

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
	 * API base URL, without a trailing slash.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * HTTP transport: ( string $url, array $args ) => array|WP_Error.
	 *
	 * @var callable
	 */
	private $http;

	/**
	 * Constructor.
	 *
	 * @param string        $api_key  API key.
	 * @param string        $model    Model name.
	 * @param string        $base_url Base URL, e.g. 'https://api.openai.com/v1'.
	 * @param callable|null $http     Optional HTTP transport, injected by tests.
	 */
	public function __construct( string $api_key, string $model, string $base_url, ?callable $http = null ) {
		$this->api_key  = $api_key;
		$this->model    = $model;
		$this->base_url = rtrim( $base_url, '/' );
		$this->http     = $http ? $http : function ( $url, $args ) {
			return wp_remote_post( $url, $args );
		};
	}

	/**
	 * Builds the chat/completions request body.
	 *
	 * @param array $spec Prompt spec.
	 * @return array
	 */
	public function build_request_body( array $spec ): array {
		return array(
			'model'           => $this->model,
			'messages'        => array(
				array(
					'role'    => 'system',
					'content' => $spec['system'],
				),
				array(
					'role'    => 'user',
					'content' => $spec['user'],
				),
			),
			'response_format' => array(
				'type'        => 'json_schema',
				'json_schema' => array(
					'name'   => AKAI_Prompt::SCHEMA_NAME,
					'strict' => true,
					'schema' => $spec['schema'],
				),
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
			$this->base_url . '/chat/completions',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->api_key,
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
	 * Extracts the keyword array from a chat/completions response body.
	 *
	 * @param string $body Raw response body.
	 * @return array|WP_Error
	 */
	public function parse_response( string $body ) {
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'akai_bad_response', __( 'Provider response was not JSON.', 'autokeywordsai' ) );
		}

		$content = $decoded['choices'][0]['message']['content'] ?? null;
		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return new WP_Error( 'akai_bad_response', __( 'Provider response contained no message content.', 'autokeywordsai' ) );
		}

		return akai_decode_keyword_payload( $content );
	}
}
