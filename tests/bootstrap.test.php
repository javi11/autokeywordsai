<?php
/**
 * Tests for the plugin bootstrap's dependency guard.
 *
 * @package autokeywordsai
 */

require_once dirname( __DIR__ ) . '/autokeywordsai.php';

// Neither dependency present: both are reported missing.
akai_assert_same(
	'guard reports both dependencies missing',
	array( 'WooCommerce', 'Rank Math SEO' ),
	akai_dependencies_missing( false, false )
);

akai_assert_same(
	'guard reports only Rank Math missing',
	array( 'Rank Math SEO' ),
	akai_dependencies_missing( true, false )
);

akai_assert_same(
	'guard reports only WooCommerce missing',
	array( 'WooCommerce' ),
	akai_dependencies_missing( false, true )
);

akai_assert_same(
	'guard reports nothing missing when both present',
	array(),
	akai_dependencies_missing( true, true )
);
