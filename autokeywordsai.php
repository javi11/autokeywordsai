<?php
/**
 * Plugin Name:       AutoKeywordsAI
 * Description:       Fills empty Rank Math focus keywords on WooCommerce products using a configurable AI provider (Gemini or any OpenAI-compatible endpoint).
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * WC tested up to:   11.0
 * Author:            Javier Blanco
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       autokeywordsai
 *
 * @package autokeywordsai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access; tests/bootstrap.php defines ABSPATH.
}

define( 'AKAI_VERSION', '0.1.0' );
define( 'AKAI_PLUGIN_FILE', __FILE__ );
define( 'AKAI_PLUGIN_DIR', __DIR__ . '/' );

/**
 * Returns the human-readable names of any missing hard dependencies.
 *
 * Both facts are injectable so this is testable without WordPress. In production both
 * arguments are omitted and detected from the environment.
 *
 * @param bool|null $has_woocommerce Whether WooCommerce is active. Null to detect.
 * @param bool|null $has_rank_math   Whether Rank Math is active. Null to detect.
 * @return array<int, string> Missing dependency names, in a stable order.
 */
function akai_dependencies_missing( $has_woocommerce = null, $has_rank_math = null ) {
	if ( null === $has_woocommerce ) {
		$has_woocommerce = class_exists( 'WooCommerce' );
	}
	if ( null === $has_rank_math ) {
		$has_rank_math = class_exists( 'RankMath' );
	}

	$missing = array();
	if ( ! $has_woocommerce ) {
		$missing[] = 'WooCommerce';
	}
	if ( ! $has_rank_math ) {
		$missing[] = 'Rank Math SEO';
	}

	return $missing;
}

/**
 * Renders an admin notice naming every missing dependency.
 *
 * @return void
 */
function akai_render_dependency_notice() {
	$missing = akai_dependencies_missing();
	if ( empty( $missing ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p><strong>AutoKeywordsAI</strong>: %s</p></div>',
		esc_html(
			sprintf(
				/* translators: %s: comma-separated list of missing plugin names. */
				__( 'inactive because these required plugins are not active: %s', 'autokeywordsai' ),
				implode( ', ', $missing )
			)
		)
	);
}

/**
 * Loads the plugin once dependencies are confirmed present.
 *
 * @return void
 */
function akai_bootstrap() {
	if ( ! empty( akai_dependencies_missing() ) ) {
		add_action( 'admin_notices', 'akai_render_dependency_notice' );
		return;
	}

	require_once AKAI_PLUGIN_DIR . 'includes/class-akai-prompt.php';
	require_once AKAI_PLUGIN_DIR . 'includes/interface-akai-provider.php';
	require_once AKAI_PLUGIN_DIR . 'includes/class-akai-provider-response.php';
	require_once AKAI_PLUGIN_DIR . 'includes/class-akai-provider-gemini.php';
	require_once AKAI_PLUGIN_DIR . 'includes/class-akai-provider-openai.php';
	require_once AKAI_PLUGIN_DIR . 'includes/class-akai-settings.php';
	require_once AKAI_PLUGIN_DIR . 'includes/class-akai-provider-factory.php';
	require_once AKAI_PLUGIN_DIR . 'includes/class-akai-keyword-writer.php';
	require_once AKAI_PLUGIN_DIR . 'includes/class-akai-logger.php';
	require_once AKAI_PLUGIN_DIR . 'includes/class-akai-queue.php';

	AKAI_Queue::register();

	if ( is_admin() ) {
		AKAI_Settings::register_admin();
	}
}
add_action( 'plugins_loaded', 'akai_bootstrap' );
