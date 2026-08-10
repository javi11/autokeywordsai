<?php
/**
 * Tests for AKAI_Logger's pure list handling.
 *
 * @package autokeywordsai
 */

require_once dirname( __DIR__ ) . '/includes/class-akai-logger.php';

$one = AKAI_Logger::append_error(
	array(),
	array(
		'product_id' => 12,
		'message'    => 'boom',
		'time'       => 1000,
	)
);
akai_assert_same( 'first error is stored', 1, count( $one ) );
akai_assert_same( 'stored error keeps the product id', 12, $one[0]['product_id'] );

$two = AKAI_Logger::append_error(
	$one,
	array(
		'product_id' => 13,
		'message'    => 'bang',
		'time'       => 2000,
	)
);
akai_assert_same( 'newest error is first', 13, $two[0]['product_id'] );
akai_assert_same( 'older error is retained', 12, $two[1]['product_id'] );

// The cap keeps the option from growing without bound.
$many = array();
for ( $i = 0; $i < 60; $i++ ) {
	$many = AKAI_Logger::append_error(
		$many,
		array(
			'product_id' => $i,
			'message'    => 'e',
			'time'       => $i,
		)
	);
}
akai_assert_same( 'list is capped at MAX_ERRORS', AKAI_Logger::MAX_ERRORS, count( $many ) );
akai_assert_same( 'the newest entry survives the cap', 59, $many[0]['product_id'] );
akai_assert_same( 'the oldest entries are dropped', 10, $many[ AKAI_Logger::MAX_ERRORS - 1 ]['product_id'] );

// A corrupt option value must not crash the caller — get_option can return a string.
akai_assert_same(
	'a non-array existing list is treated as empty',
	1,
	count(
		AKAI_Logger::append_error(
			'not-an-array',
			array(
				'product_id' => 1,
				'message'    => 'x',
				'time'       => 1,
			)
		)
	)
);
