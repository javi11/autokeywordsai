<?php
/**
 * Tests for AKAI_Provider_OpenAI. No network: HTTP is injected.
 *
 * Reuses akai_http_double() from tests/provider-gemini.test.php, which the runner loads
 * first because test files are sorted alphabetically (provider-gemini < provider-openai).
 *
 * @package autokeywordsai
 */

require_once dirname( __DIR__ ) . '/includes/class-akai-prompt.php';
require_once dirname( __DIR__ ) . '/includes/interface-akai-provider.php';
require_once dirname( __DIR__ ) . '/includes/class-akai-provider-response.php';
require_once dirname( __DIR__ ) . '/includes/class-akai-provider-openai.php';

$spec = AKAI_Prompt::build_spec( array( 'title' => 'Jabón artesanal' ), 'es_ES' );

// --- Request body ------------------------------------------------------------
$provider = new AKAI_Provider_OpenAI( 'sk-test', 'gpt-5.6', 'https://api.openai.com/v1' );
$body     = $provider->build_request_body( $spec );

akai_assert_same( 'request carries the model', 'gpt-5.6', $body['model'] );
akai_assert_same( 'first message is the system instruction', 'system', $body['messages'][0]['role'] );
akai_assert_same( 'system content is the spec system text', $spec['system'], $body['messages'][0]['content'] );
akai_assert_same( 'second message is the user text', 'user', $body['messages'][1]['role'] );
akai_assert_same( 'user content is the spec user text', $spec['user'], $body['messages'][1]['content'] );
akai_assert_same( 'response_format type is json_schema', 'json_schema', $body['response_format']['type'] );
akai_assert_same( 'schema name is set', AKAI_Prompt::SCHEMA_NAME, $body['response_format']['json_schema']['name'] );
akai_assert_same( 'strict mode is on', true, $body['response_format']['json_schema']['strict'] );
akai_assert_same( 'schema is passed through', $spec['schema'], $body['response_format']['json_schema']['schema'] );

// --- Successful call ---------------------------------------------------------
$seen     = array();
$provider = new AKAI_Provider_OpenAI(
	'sk-test',
	'gpt-5.6',
	'https://api.openai.com/v1',
	akai_http_double( 200, file_get_contents( __DIR__ . '/fixtures/openai-success.json' ), $seen )
);
$result = $provider->generate( $spec );

akai_assert_same( 'parses the primary keyword', 'jabón artesanal natural', $result['primary'] );
akai_assert_same(
	'parses the secondary keywords',
	array( 'jabón de aceite de oliva', 'jabón hecho a mano' ),
	$result['secondary']
);
akai_assert_same( 'posts to chat/completions', 'https://api.openai.com/v1/chat/completions', $seen['url'] );
akai_assert_same( 'sends a bearer token', 'Bearer sk-test', $seen['args']['headers']['Authorization'] );

// A trailing slash on the base URL must not produce a double slash.
$seen     = array();
$provider = new AKAI_Provider_OpenAI(
	'sk-test',
	'llama3',
	'http://localhost:11434/v1/',
	akai_http_double( 200, file_get_contents( __DIR__ . '/fixtures/openai-success.json' ), $seen )
);
$provider->generate( $spec );
akai_assert_same( 'trailing slash in base URL is normalised', 'http://localhost:11434/v1/chat/completions', $seen['url'] );

// --- Error mapping -----------------------------------------------------------
$seen     = array();
$provider = new AKAI_Provider_OpenAI( 'sk-test', 'gpt-5.6', 'https://api.openai.com/v1', akai_http_double( 429, '{}', $seen ) );
akai_assert_same( '429 maps to akai_rate_limited', 'akai_rate_limited', $provider->generate( $spec )->get_error_code() );

$seen     = array();
$provider = new AKAI_Provider_OpenAI( 'sk-test', 'gpt-5.6', 'https://api.openai.com/v1', akai_http_double( 401, 'bad key', $seen ) );
akai_assert_same( '401 maps to akai_http_error', 'akai_http_error', $provider->generate( $spec )->get_error_code() );

$seen     = array();
$provider = new AKAI_Provider_OpenAI( 'sk-test', 'gpt-5.6', 'https://api.openai.com/v1', akai_http_double( 200, '{"choices":[]}', $seen ) );
akai_assert_same( 'no choices maps to akai_bad_response', 'akai_bad_response', $provider->generate( $spec )->get_error_code() );

$provider = new AKAI_Provider_OpenAI( '', 'gpt-5.6', 'https://api.openai.com/v1', akai_http_double( 200, '{}', $seen ) );
akai_assert_same( 'empty key maps to akai_no_key', 'akai_no_key', $provider->generate( $spec )->get_error_code() );
