<?php
/**
 * Tests for AKAI_Prompt — pure, no WordPress needed beyond the bootstrap stubs.
 *
 * @package autokeywordsai
 */

require_once dirname( __DIR__ ) . '/includes/class-akai-prompt.php';

$spec = AKAI_Prompt::build_spec(
	array(
		'title'             => 'Vela aromática de lavanda',
		'categories'        => array( 'Velas', 'Regalos' ),
		'short_description' => 'Vela de cera de soja con aceite esencial de lavanda.',
	),
	'es_ES'
);

akai_assert_same( 'spec has exactly the three expected keys', array( 'system', 'user', 'schema' ), array_keys( $spec ) );

akai_assert_true( 'system instruction names the target language', str_contains( $spec['system'], 'es_ES' ) );
akai_assert_true( 'system instruction mentions SEO', stripos( $spec['system'], 'SEO' ) !== false );

akai_assert_true( 'user text includes the product title', str_contains( $spec['user'], 'Vela aromática de lavanda' ) );
akai_assert_true( 'user text includes categories', str_contains( $spec['user'], 'Velas, Regalos' ) );
akai_assert_true( 'user text includes the short description', str_contains( $spec['user'], 'cera de soja' ) );

// Schema must be strict-mode compatible for both providers.
akai_assert_same( 'schema is an object type', 'object', $spec['schema']['type'] );
akai_assert_same( 'schema forbids extra properties', false, $spec['schema']['additionalProperties'] );
akai_assert_same( 'schema requires both properties', array( 'primary', 'secondary' ), $spec['schema']['required'] );
akai_assert_same( 'primary is a string', 'string', $spec['schema']['properties']['primary']['type'] );
akai_assert_same( 'secondary is an array', 'array', $spec['schema']['properties']['secondary']['type'] );
akai_assert_same( 'secondary items are strings', 'string', $spec['schema']['properties']['secondary']['items']['type'] );

// Optional fields absent: must not emit empty labels.
$minimal = AKAI_Prompt::build_spec( array( 'title' => 'Jabón artesanal' ), 'en_US' );
akai_assert_true( 'minimal user text includes the title', str_contains( $minimal['user'], 'Jabón artesanal' ) );
akai_assert_true( 'minimal user text omits the categories label', ! str_contains( $minimal['user'], 'Categories:' ) );
akai_assert_true( 'minimal user text omits the description label', ! str_contains( $minimal['user'], 'Description:' ) );

// HTML in the description must be stripped — product short descriptions contain markup.
$html = AKAI_Prompt::build_spec(
	array(
		'title'             => 'Taza',
		'short_description' => '<p>Taza de <strong>cerámica</strong></p>',
	),
	'es_ES'
);
akai_assert_true( 'description HTML is stripped', ! str_contains( $html['user'], '<strong>' ) );
akai_assert_true( 'description text survives stripping', str_contains( $html['user'], 'cerámica' ) );
