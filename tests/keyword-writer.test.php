<?php
/**
 * Tests for AKAI_Keyword_Writer's pure decisions.
 *
 * @package autokeywordsai
 */

require_once dirname( __DIR__ ) . '/includes/class-akai-prompt.php';
require_once dirname( __DIR__ ) . '/includes/class-akai-keyword-writer.php';

// --- The skip rule ----------------------------------------------------------
akai_assert_same( 'writes when the field is an empty string', true, AKAI_Keyword_Writer::should_write( '' ) );
akai_assert_same( 'writes when the field is whitespace', true, AKAI_Keyword_Writer::should_write( '   ' ) );
akai_assert_same( 'writes when the field is absent', true, AKAI_Keyword_Writer::should_write( null ) );
akai_assert_same( 'writes when get_post_meta returned false', true, AKAI_Keyword_Writer::should_write( false ) );
akai_assert_same( 'skips when the field has a value', false, AKAI_Keyword_Writer::should_write( 'vela de lavanda' ) );
akai_assert_same( 'skips a value that is only a comma', false, AKAI_Keyword_Writer::should_write( 'a,b' ) );

// --- Building the meta value ------------------------------------------------
akai_assert_same(
	'joins primary and secondary with commas',
	'vela de lavanda, vela de soja, vela relajante',
	AKAI_Keyword_Writer::to_meta_value(
		array(
			'primary'   => 'vela de lavanda',
			'secondary' => array( 'vela de soja', 'vela relajante' ),
		)
	)
);

akai_assert_same(
	'caps the total at MAX_KEYWORDS',
	'a, b, c',
	AKAI_Keyword_Writer::to_meta_value(
		array(
			'primary'   => 'a',
			'secondary' => array( 'b', 'c', 'd', 'e' ),
		)
	)
);

akai_assert_same(
	'strips commas inside a single keyword',
	'vela lavanda, vela soja',
	AKAI_Keyword_Writer::to_meta_value(
		array(
			'primary'   => 'vela, lavanda',
			'secondary' => array( 'vela, soja' ),
		)
	)
);

akai_assert_same(
	'trims whitespace and drops empty secondaries',
	'jabón artesanal, jabón de oliva',
	AKAI_Keyword_Writer::to_meta_value(
		array(
			'primary'   => '  jabón artesanal  ',
			'secondary' => array( '', '   ', 'jabón de oliva' ),
		)
	)
);

akai_assert_same(
	'deduplicates case-insensitively',
	'vela de lavanda',
	AKAI_Keyword_Writer::to_meta_value(
		array(
			'primary'   => 'vela de lavanda',
			'secondary' => array( 'Vela De Lavanda' ),
		)
	)
);

// --- Rejections: an empty write would permanently mark the product "done" ----
$empty_primary = AKAI_Keyword_Writer::to_meta_value(
	array(
		'primary'   => '   ',
		'secondary' => array( 'algo' ),
	)
);
akai_assert_same( 'an empty primary is rejected', 'akai_bad_response', $empty_primary->get_error_code() );

$comma_only = AKAI_Keyword_Writer::to_meta_value(
	array(
		'primary'   => ',,,',
		'secondary' => array(),
	)
);
akai_assert_same( 'a comma-only primary is rejected', 'akai_bad_response', $comma_only->get_error_code() );

$missing = AKAI_Keyword_Writer::to_meta_value( array( 'secondary' => array( 'algo' ) ) );
akai_assert_same( 'a missing primary is rejected', 'akai_bad_response', $missing->get_error_code() );
