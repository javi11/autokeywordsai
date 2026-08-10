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
