<?php
/**
 * Tests for AKAI_Settings pure helpers and AKAI_Provider_Factory.
 *
 * @package autokeywordsai
 */

require_once dirname( __DIR__ ) . '/includes/interface-akai-provider.php';
require_once dirname( __DIR__ ) . '/includes/class-akai-provider-response.php';
require_once dirname( __DIR__ ) . '/includes/class-akai-provider-gemini.php';
require_once dirname( __DIR__ ) . '/includes/class-akai-provider-openai.php';
require_once dirname( __DIR__ ) . '/includes/class-akai-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-akai-provider-factory.php';

// --- Defaults ---------------------------------------------------------------
$defaults = AKAI_Settings::defaults();
akai_assert_same( 'default provider is gemini', 'gemini', $defaults['provider'] );
akai_assert_same( 'default base url is openai', 'https://api.openai.com/v1', $defaults['base_url'] );
akai_assert_same( 'default rpm is 10', 10, $defaults['rpm'] );

akai_assert_same( 'gemini default model', 'gemini-3.5-flash', AKAI_Settings::default_model( 'gemini' ) );
akai_assert_same( 'openai default model', 'gpt-5.6', AKAI_Settings::default_model( 'openai' ) );

// --- Sanitization -----------------------------------------------------------
$existing = array_merge( $defaults, array( 'api_key' => 'stored-key' ) );

$saved = AKAI_Settings::sanitize(
	array(
		'provider' => 'openai',
		'api_key'  => '',
		'base_url' => 'https://api.groq.com/openai/v1/',
		'model'    => ' llama-3.3-70b ',
		'language' => 'es_ES',
		'rpm'      => '25',
	),
	$existing
);

akai_assert_same( 'empty submitted key preserves the stored key', 'stored-key', $saved['api_key'] );
akai_assert_same( 'provider is accepted', 'openai', $saved['provider'] );
akai_assert_same( 'base url trailing slash is trimmed', 'https://api.groq.com/openai/v1', $saved['base_url'] );
akai_assert_same( 'model is trimmed', 'llama-3.3-70b', $saved['model'] );
akai_assert_same( 'rpm is cast to int', 25, $saved['rpm'] );

$replaced = AKAI_Settings::sanitize( array( 'api_key' => 'new-key' ), $existing );
akai_assert_same( 'non-empty submitted key replaces the stored key', 'new-key', $replaced['api_key'] );

$bogus = AKAI_Settings::sanitize( array( 'provider' => 'skynet' ), $existing );
akai_assert_same( 'unknown provider falls back to gemini', 'gemini', $bogus['provider'] );

$slow = AKAI_Settings::sanitize( array( 'rpm' => '0' ), $existing );
akai_assert_same( 'rpm below 1 is clamped to 1', 1, $slow['rpm'] );

$fast = AKAI_Settings::sanitize( array( 'rpm' => '9999' ), $existing );
akai_assert_same( 'rpm above 600 is clamped to 600', 600, $fast['rpm'] );

// --- API key resolution -----------------------------------------------------
akai_assert_same(
	'constant wins over the stored key',
	'from-constant',
	AKAI_Settings::resolve_api_key( array( 'api_key' => 'from-db' ), 'from-constant' )
);
akai_assert_same(
	'stored key is used when no constant is defined',
	'from-db',
	AKAI_Settings::resolve_api_key( array( 'api_key' => 'from-db' ), null )
);
akai_assert_same(
	'an empty constant does not shadow the stored key',
	'from-db',
	AKAI_Settings::resolve_api_key( array( 'api_key' => 'from-db' ), '' )
);

// --- Factory ----------------------------------------------------------------
$gemini = AKAI_Provider_Factory::make( array_merge( $defaults, array( 'provider' => 'gemini' ) ) );
akai_assert_true( 'gemini settings build a Gemini provider', $gemini instanceof AKAI_Provider_Gemini );

$openai = AKAI_Provider_Factory::make( array_merge( $defaults, array( 'provider' => 'openai' ) ) );
akai_assert_true( 'openai settings build an OpenAI provider', $openai instanceof AKAI_Provider_OpenAI );

$fallback = AKAI_Provider_Factory::make( array_merge( $defaults, array( 'provider' => 'nonsense' ) ) );
akai_assert_true( 'unknown provider falls back to Gemini', $fallback instanceof AKAI_Provider_Gemini );
