<?php
/**
 * Test runner. Usage from the repo root:
 *
 *     php tests/run.php
 *
 * @package autokeywordsai
 */

require_once __DIR__ . '/bootstrap.php';

$akai_files = glob( __DIR__ . '/*.test.php' );
sort( $akai_files );

foreach ( $akai_files as $akai_file ) {
	echo basename( $akai_file ) . "\n";
	require_once $akai_file;
}

printf(
	"\n%d assertions, %d failures\n",
	$GLOBALS['akai_assertions'],
	$GLOBALS['akai_failures']
);

exit( $GLOBALS['akai_failures'] > 0 ? 1 : 0 );
