<?php
/**
 * Test bootstrap: WordPress stubs and assertion helpers.
 *
 * The plugin's pure units must run with no WordPress and no network. Anything a unit
 * under test touches gets a minimal stub here.
 *
 * @package autokeywordsai
 */

if ( 'cli' !== php_sapi_name() ) {
	exit; // Tests never run over HTTP.
}

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

$GLOBALS['akai_failures']   = 0;
$GLOBALS['akai_assertions'] = 0;

// --- WordPress function stubs ------------------------------------------------
foreach ( array( 'add_action', 'add_filter', 'load_plugin_textdomain' ) as $akai_stub ) {
	if ( ! function_exists( $akai_stub ) ) {
		eval( "function {$akai_stub}() { return true; }" ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Translation stub — returns the string unchanged.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function __( $text ) {
		return $text;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Approximates WordPress sanitize_text_field for tests.
	 *
	 * @param string $str Input.
	 * @return string
	 */
	function sanitize_text_field( $str ) {
		$str = strip_tags( (string) $str ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$str = preg_replace( '/[\r\n\t]+/', ' ', $str );
		return trim( preg_replace( '/\s+/', ' ', $str ) );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * JSON encode stub.
	 *
	 * @param mixed $data Data.
	 * @return string
	 */
	function wp_json_encode( $data ) {
		return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * WP_Error check stub.
	 *
	 * @param mixed $thing Value.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error stub.
	 */
	class WP_Error {

		/**
		 * Error code.
		 *
		 * @var string
		 */
		private $code;

		/**
		 * Error message.
		 *
		 * @var string
		 */
		private $message;

		/**
		 * Arbitrary error data.
		 *
		 * @var mixed
		 */
		private $data;

		/**
		 * Constructor.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Optional data.
		 */
		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		/**
		 * Error code accessor.
		 *
		 * @return string
		 */
		public function get_error_code() {
			return $this->code;
		}

		/**
		 * Error message accessor.
		 *
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}

		/**
		 * Error data accessor.
		 *
		 * @return mixed
		 */
		public function get_error_data() {
			return $this->data;
		}
	}
}

// --- Option storage stub -----------------------------------------------------
$GLOBALS['akai_options'] = array();

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * In-memory get_option stub.
	 *
	 * @param string $name    Option name.
	 * @param mixed  $default Default when unset.
	 * @return mixed
	 */
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['akai_options'] ) ? $GLOBALS['akai_options'][ $name ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * In-memory update_option stub.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Option value.
	 * @param mixed  $autoload Ignored.
	 * @return bool
	 */
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['akai_options'][ $name ] = $value;
		return true;
	}
}

// --- Action Scheduler stub ---------------------------------------------------
/*
 * This fake reproduces the one Action Scheduler behaviour the retry policy depends on:
 * with $unique = true it REFUSES to schedule (returning 0) when an action with the same
 * hook, args and group is already pending *or in-progress*. The in-progress half is the
 * subtle one — a callback that reschedules itself with unchanged args is silently ignored,
 * because its own action is in-progress while it runs. Verified against Action Scheduler
 * 3.x on a live install.
 */
$GLOBALS['akai_actions'] = array();

/**
 * Clears the fake scheduler between test groups.
 *
 * @return void
 */
function akai_fake_scheduler_reset(): void {
	$GLOBALS['akai_actions'] = array();
}

/**
 * Registers an action already claimed and executing, as Action Scheduler holds the action
 * whose callback is currently running.
 *
 * @param string $hook  Hook name.
 * @param array  $args  Action args.
 * @param string $group Action group.
 * @return void
 */
function akai_fake_scheduler_add_in_progress( string $hook, array $args, string $group ): void {
	$GLOBALS['akai_actions'][] = array(
		'hook'      => $hook,
		'args'      => $args,
		'group'     => $group,
		'timestamp' => time(),
		'status'    => 'in-progress',
	);
}

/**
 * The pending actions the fake scheduler holds.
 *
 * @return array<int, array>
 */
function akai_fake_scheduler_pending(): array {
	return array_values(
		array_filter(
			$GLOBALS['akai_actions'],
			static function ( $action ) {
				return 'pending' === $action['status'];
			}
		)
	);
}

if ( ! function_exists( 'as_schedule_single_action' ) ) {
	/**
	 * Action Scheduler stub honouring the real uniqueness rule.
	 *
	 * @param int    $timestamp When to run.
	 * @param string $hook      Hook name.
	 * @param array  $args      Action args.
	 * @param string $group     Action group.
	 * @param bool   $unique    Whether to refuse duplicates.
	 * @return int Action id, or 0 when refused.
	 */
	function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '', $unique = false ) {
		if ( $unique ) {
			foreach ( $GLOBALS['akai_actions'] as $action ) {
				if ( $action['hook'] === $hook
					&& $action['args'] === $args
					&& $action['group'] === $group
					&& in_array( $action['status'], array( 'pending', 'in-progress' ), true ) ) {
					return 0;
				}
			}
		}

		$GLOBALS['akai_actions'][] = array(
			'hook'      => $hook,
			'args'      => $args,
			'group'     => $group,
			'timestamp' => (int) $timestamp,
			'status'    => 'pending',
		);

		return count( $GLOBALS['akai_actions'] );
	}
}

// --- Assertion helpers -------------------------------------------------------
/**
 * Asserts strict equality and reports a pass/fail line.
 *
 * @param string $name     Case name.
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @return void
 */
function akai_assert_same( $name, $expected, $actual ) {
	++$GLOBALS['akai_assertions'];
	if ( $expected === $actual ) {
		echo "  ok   {$name}\n";
		return;
	}
	++$GLOBALS['akai_failures'];
	echo "  FAIL {$name}\n";
	echo '       expected: ' . var_export( $expected, true ) . "\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	echo '       actual:   ' . var_export( $actual, true ) . "\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions
}

/**
 * Asserts a truthy value.
 *
 * @param string $name   Case name.
 * @param mixed  $actual Actual value.
 * @return void
 */
function akai_assert_true( $name, $actual ) {
	akai_assert_same( $name, true, (bool) $actual );
}
