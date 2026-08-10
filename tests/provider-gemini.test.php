<?php
/**
 * Tests for AKAI_Provider_Gemini. No network: HTTP is injected.
 *
 * @package autokeywordsai
 */

require_once dirname( __DIR__ ) . '/includes/class-akai-prompt.php';
require_once dirname( __DIR__ ) . '/includes/interface-akai-provider.php';
require_once dirname( __DIR__ ) . '/includes/class-akai-provider-response.php';
require_once dirname( __DIR__ ) . '/includes/class-akai-provider-gemini.php';

/**
 * Returns an HTTP double that always answers with the given status and body.
 *
 * @param int    $status HTTP status code.
 * @param string $body   Response body.
 * @param array  $seen   Captured call, by reference.
 * @return callable
 */
function akai_http_double( $status, $body, &$seen ) {
	return function ( $url, $args ) use ( $status, $body, &$seen ) {
		$seen = array(
			'url'  => $url,
			'args' => $args,
		);
		return array(
			'response' => array( 'code' => $status ),
			'body'     => $body,
		);
	};
}

$spec = AKAI_Prompt::build_spec( array( 'title' => 'Vela de lavanda' ), 'es_ES' );

// --- Request body ------------------------------------------------------------
$provider = new AKAI_Provider_Gemini( 'test-key', 'gemini-3.5-flash' );
$body     = $provider->build_request_body( $spec );

akai_assert_same( 'request carries the model', 'gemini-3.5-flash', $body['model'] );
akai_assert_true( 'request input carries the system instruction', str_contains( $body['input'], $spec['system'] ) );
akai_assert_true( 'request input carries the product', str_contains( $body['input'], 'Vela de lavanda' ) );
akai_assert_same( 'response_format asks for JSON', 'application/json', $body['response_format']['mime_type'] );
akai_assert_same( 'response_format carries the schema', $spec['schema'], $body['response_format']['schema'] );

// --- Successful call ---------------------------------------------------------
$seen     = array();
$provider = new AKAI_Provider_Gemini(
	'test-key',
	'gemini-3.5-flash',
	akai_http_double( 200, file_get_contents( __DIR__ . '/fixtures/gemini-success.json' ), $seen )
);
$result = $provider->generate( $spec );

akai_assert_same( 'parses the primary keyword', 'vela de lavanda', $result['primary'] );
akai_assert_same( 'parses the secondary keywords', array( 'vela aromática soja', 'vela relajante' ), $result['secondary'] );
akai_assert_same( 'posts to the interactions endpoint', AKAI_Provider_Gemini::ENDPOINT, $seen['url'] );
akai_assert_same( 'sends the key in the x-goog-api-key header', 'test-key', $seen['args']['headers']['x-goog-api-key'] );
akai_assert_same( 'pins the api revision', AKAI_Provider_Gemini::API_REVISION, $seen['args']['headers']['Api-Revision'] );

// --- Error mapping -----------------------------------------------------------
$seen     = array();
$provider = new AKAI_Provider_Gemini( 'test-key', 'gemini-3.5-flash', akai_http_double( 429, '{"error":"quota"}', $seen ) );
$rate     = $provider->generate( $spec );
akai_assert_same( '429 maps to akai_rate_limited', 'akai_rate_limited', $rate->get_error_code() );

$seen     = array();
$provider = new AKAI_Provider_Gemini( 'test-key', 'gemini-3.5-flash', akai_http_double( 503, 'upstream down', $seen ) );
$http     = $provider->generate( $spec );
akai_assert_same( '503 maps to akai_http_error', 'akai_http_error', $http->get_error_code() );

$seen     = array();
$provider = new AKAI_Provider_Gemini(
	'test-key',
	'gemini-3.5-flash',
	akai_http_double( 200, file_get_contents( __DIR__ . '/fixtures/gemini-malformed.json' ), $seen )
);
$bad = $provider->generate( $spec );
akai_assert_same( 'non-JSON model output maps to akai_bad_response', 'akai_bad_response', $bad->get_error_code() );

$seen     = array();
$provider = new AKAI_Provider_Gemini( 'test-key', 'gemini-3.5-flash', akai_http_double( 200, '{"steps":[]}', $seen ) );
$empty    = $provider->generate( $spec );
akai_assert_same( 'missing model_output maps to akai_bad_response', 'akai_bad_response', $empty->get_error_code() );

$provider = new AKAI_Provider_Gemini( '', 'gemini-3.5-flash', akai_http_double( 200, '{}', $seen ) );
$no_key   = $provider->generate( $spec );
akai_assert_same( 'empty key maps to akai_no_key', 'akai_no_key', $no_key->get_error_code() );

// A transport-level WP_Error must pass through as akai_http_error, not crash.
$provider = new AKAI_Provider_Gemini(
	'test-key',
	'gemini-3.5-flash',
	function () {
		return new WP_Error( 'http_request_failed', 'cURL error 28' );
	}
);
$transport = $provider->generate( $spec );
akai_assert_same( 'transport failure maps to akai_http_error', 'akai_http_error', $transport->get_error_code() );
