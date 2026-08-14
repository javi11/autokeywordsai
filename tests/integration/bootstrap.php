<?php
/**
 * PHPUnit bootstrap for the integration suite.
 *
 * Runs inside wp-env against a real WordPress, WooCommerce and Rank Math. The
 * unit suite in tests/*.test.php runs the pure logic against hand-written stubs
 * with the bespoke runner in tests/run.php; this covers the parts that cannot
 * work without a database and a real `product` post type.
 *
 * Both WooCommerce and Rank Math must be present, and not merely for realism:
 * akai_bootstrap() returns early without loading a single one of the plugin's
 * classes unless class_exists('WooCommerce') and class_exists('RankMath') are
 * both true. A suite missing either would quietly exercise nothing at all.
 *
 * @package autokeywordsai
 */

$akai_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $akai_tests_dir ) {
	$akai_tests_dir = '/wordpress-phpunit';
}

if ( ! file_exists( $akai_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find the WordPress test suite at {$akai_tests_dir}.\n";
	echo "Run this suite through wp-env, for example:\n";
	echo "  npx wp-env run tests-cli --env-cwd=wp-content/plugins/\$(basename \"\$PWD\") vendor/bin/phpunit -c phpunit.integration.xml.dist\n";
	exit( 1 );
}

require_once $akai_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () {
		$plugin_dir   = dirname( __DIR__, 2 );
		$plugins_root = dirname( $plugin_dir );

		/*
		 * wp-env names each plugin directory after the source it was installed
		 * from, so a zip URL yields `woocommerce.latest-stable` rather than
		 * `woocommerce`. Locate entry files by glob instead of assuming names.
		 */
		$akai_require_first = function ( $pattern ) {
			foreach ( (array) glob( $pattern ) as $candidate ) {
				require_once $candidate;
				return true;
			}

			return false;
		};

		if ( ! $akai_require_first( $plugins_root . '/woocommerce*/woocommerce.php' ) ) {
			echo "Could not locate WooCommerce in {$plugins_root}.\n";
			exit( 1 );
		}

		if ( ! $akai_require_first( $plugins_root . '/seo-by-rank-math*/rank-math.php' ) ) {
			echo "Could not locate Rank Math in {$plugins_root}.\n";
			exit( 1 );
		}

		add_filter(
			'pre_option_active_plugins',
			function () {
				return array(
					'woocommerce/woocommerce.php',
					'seo-by-rank-math/rank-math.php',
				);
			}
		);

		require_once $plugin_dir . '/autokeywordsai.php';
	}
);

/*
 * Install WooCommerce's schema before the tests run. Products are ordinary
 * custom post types — WooCommerce's High-Performance Order Storage moved only
 * orders — so unlike the order-based plugins there is no storage engine to
 * select here.
 */
tests_add_filter(
	'setup_theme',
	function () {
		if ( class_exists( 'WC_Install' ) ) {
			WC_Install::install();
		}
	}
);

require $akai_tests_dir . '/includes/bootstrap.php';
