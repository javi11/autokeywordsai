# AutoKeywordsAI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A general-purpose WordPress plugin that fills empty Rank Math focus keywords on WooCommerce products using a configurable LLM provider (Gemini or any OpenAI-compatible endpoint).

**Architecture:** A pure prompt builder produces a provider-neutral spec; one provider interface with two implementations translates that spec to and from vendor wire formats; a writer owns the single `update_post_meta` call; Action Scheduler drives all execution via one staggered action per product, shared by the bulk button and the publish hook.

**Tech Stack:** PHP 8.1+, WordPress, WooCommerce (products + bundled Action Scheduler), Rank Math SEO, `wp_remote_post`. Dev-only: PHPCS + WordPress Coding Standards via Composer. Tests are dependency-free CLI PHP.

## Global Constraints

- PHP 8.1+. No runtime Composer dependencies — only dev dependencies.
- Function/class prefix `akai_` / `AKAI_`. Slug and text domain: `autokeywordsai`.
- WordPress Coding Standards. Tabs for indentation. Docblock on every class and method.
- Every user-facing string wrapped in `__()` / `esc_html__()` with text domain `autokeywordsai`.
- Every file starts with `if ( ! defined( 'ABSPATH' ) ) { exit; }` except `tests/**` and `bin/**`.
- Rank Math meta key is exactly `rank_math_focus_keyword`. Multiple keywords are comma-separated; the first is primary.
- Action Scheduler hook is exactly `akai_generate_keyword`, group exactly `autokeywordsai`.
- Never write an empty or partial keyword value. An empty write would mark the product "done" and permanently exclude it from future runs.
- Never overwrite a non-empty `rank_math_focus_keyword`.
- Providers must accept an injectable HTTP callable so all tests run without network.
- Tests run with `php tests/run.php` and exit non-zero on failure.

## Provider wire formats (verified 2026-08-10)

**Gemini — Interactions API** (recommended by Google for new development; `generateContent` still works but is not the recommended path):

```
POST https://generativelanguage.googleapis.com/v1beta/interactions
Headers: x-goog-api-key: <key>
         Content-Type: application/json
         Api-Revision: 2026-05-20
Body:   { "model": "...", "input": "...", "response_format": {
            "type": "text", "mime_type": "application/json", "schema": { ... } } }
Response: { "steps": [ { "type": "model_output",
                         "content": [ { "type": "text", "text": "<json>" } ] } ] }
```

**OpenAI-compatible — `/chat/completions`** (deliberately NOT the newer Responses API: `/chat/completions` is current and non-deprecated, and it is the format Groq, OpenRouter, DeepSeek, Together, Ollama and LM Studio implement, which is the entire value of this provider):

```
POST {base_url}/chat/completions
Headers: Authorization: Bearer <key>
         Content-Type: application/json
Body:   { "model": "...", "messages": [ {role:system}, {role:user} ],
          "response_format": { "type": "json_schema", "json_schema": {
            "name": "akai_keywords", "strict": true, "schema": { ... } } } }
Response: { "choices": [ { "message": { "content": "<json>" } } ] }
```

Both schemas require `additionalProperties: false` and a complete `required` array for strict mode.

## File Structure

| File | Responsibility |
|---|---|
| `autokeywordsai.php` | Plugin header, constants, dependency guard, requires, bootstrap. |
| `includes/class-akai-prompt.php` | PURE. Product data + language → neutral spec (system, user, schema). |
| `includes/interface-akai-provider.php` | `generate( array $spec ): array\|WP_Error`. |
| `includes/class-akai-provider-gemini.php` | Neutral spec ↔ Gemini Interactions wire format. |
| `includes/class-akai-provider-openai.php` | Neutral spec ↔ OpenAI-compatible chat/completions. |
| `includes/class-akai-provider-factory.php` | Settings → configured provider instance. |
| `includes/class-akai-settings.php` | Option storage, defaults, API-key constant precedence, settings page. |
| `includes/class-akai-keyword-writer.php` | Skip rule, sanitization, the one meta write. |
| `includes/class-akai-logger.php` | `wc_get_logger()` wrapper + capped recent-errors option. |
| `includes/class-akai-queue.php` | Action Scheduler enqueue, stagger maths, handler, retry policy, publish hook. |
| `tests/bootstrap.php` | WordPress stubs + assertion helpers. |
| `tests/run.php` | Test runner. |
| `tests/*.test.php` | One file per unit. |
| `tests/fixtures/*.json` | Canned provider responses. |
| `bin/build-zip.sh` | Builds installable zip. |
| `.github/workflows/ci.yml` | Tests on PHP 8.1/8.2/8.3 + PHPCS. |
| `.github/workflows/release.yml` | Tag → attach zip. |

---

### Task 1: Repo scaffolding, plugin bootstrap, test harness

**Files:**
- Create: `autokeywordsai.php`
- Create: `tests/bootstrap.php`
- Create: `tests/run.php`
- Create: `tests/bootstrap.test.php`
- Create: `.gitignore`
- Create: `README.md`

**Interfaces:**
- Consumes: nothing.
- Produces: constants `AKAI_VERSION` (string `'0.1.0'`), `AKAI_PLUGIN_FILE` (string), `AKAI_PLUGIN_DIR` (string, trailing slash); function `akai_dependencies_missing(): array` returning a list of missing dependency names; test helpers `akai_assert_same( string $name, mixed $expected, mixed $actual ): void` and `akai_assert_true( string $name, mixed $actual ): void`; stub class `WP_Error` with `get_error_code()`, `get_error_message()`, `get_error_data()`.

- [ ] **Step 1: Create `.gitignore`**

```
vendor/
build/
*.zip
.DS_Store
```

- [ ] **Step 2: Write the test harness bootstrap**

Create `tests/bootstrap.php`:

```php
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

$GLOBALS['akai_failures'] = 0;
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
		$str = strip_tags( (string) $str );
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

		/** @var string */
		private $code;

		/** @var string */
		private $message;

		/** @var mixed */
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

		/** @return string */
		public function get_error_code() {
			return $this->code;
		}

		/** @return string */
		public function get_error_message() {
			return $this->message;
		}

		/** @return mixed */
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
	echo '       expected: ' . var_export( $expected, true ) . "\n";
	echo '       actual:   ' . var_export( $actual, true ) . "\n";
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
```

- [ ] **Step 3: Write the test runner**

Create `tests/run.php`:

```php
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
```

- [ ] **Step 4: Write the failing test for the dependency guard**

Create `tests/bootstrap.test.php`:

```php
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
```

- [ ] **Step 5: Run the test to verify it fails**

Run: `php tests/run.php`
Expected: FAIL — `require`d file `autokeywordsai.php` does not exist (PHP fatal error: Failed opening required).

- [ ] **Step 6: Write the plugin bootstrap**

Create `autokeywordsai.php`. Note the guard takes its two facts as injectable parameters defaulting to `null`, which is what makes it testable without WordPress:

```php
<?php
/**
 * Plugin Name:       AutoKeywordsAI
 * Description:       Fills empty Rank Math focus keywords on WooCommerce products using a configurable AI provider (Gemini or any OpenAI-compatible endpoint).
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Javier Blanco
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       autokeywordsai
 *
 * @package autokeywordsai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access. (tests/bootstrap.php defines ABSPATH.)
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

	// Requires are added by later tasks as each class lands.
}
add_action( 'plugins_loaded', 'akai_bootstrap' );
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `php tests/run.php`
Expected: PASS — `4 assertions, 0 failures`.

- [ ] **Step 8: Write the README**

Create `README.md`:

```markdown
# AutoKeywordsAI

Fills empty [Rank Math](https://rankmath.com) focus keywords on WooCommerce products
using an AI provider you configure.

- **Providers:** Google Gemini, or any OpenAI-compatible endpoint (OpenAI, Groq,
  OpenRouter, DeepSeek, Together, Ollama, LM Studio) via a configurable base URL.
- **Never overwrites** a keyword that already has a value, so hand-tuned keywords are safe.
- **Bulk fill** for the existing catalogue, plus automatic fill when a new product is
  published.
- Runs on Action Scheduler (bundled with WooCommerce), one staggered job per product, so
  free-tier rate limits are respected and nothing blocks a page load.

## Requirements

WordPress 6.4+, PHP 8.1+, WooCommerce, Rank Math SEO.

## Install

Download the zip from the releases page and upload it under
**Plugins → Add New → Upload Plugin**. Then configure
**Products → AutoKeywordsAI**.

The API key can be kept out of the database by defining it in `wp-config.php`:

```php
define( 'AKAI_API_KEY', 'your-key-here' );
```

## Known limitation

An LLM generates *plausible* keywords; it has no search-volume data. Reviewing the primary
keyword on your top sellers against Google autocomplete is worthwhile.

## Development

```bash
php tests/run.php        # unit tests, no WordPress or network required
composer install         # dev only
vendor/bin/phpcs         # WordPress Coding Standards
```
```

- [ ] **Step 9: Commit**

```bash
git add .gitignore README.md autokeywordsai.php tests/
git commit -m "feat: add plugin bootstrap, dependency guard and test harness"
```

---

### Task 2: Prompt builder (pure)

**Files:**
- Create: `includes/class-akai-prompt.php`
- Create: `tests/prompt.test.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `AKAI_Prompt::build_spec( array $product, string $language ): array` returning exactly the keys `system` (string), `user` (string), `schema` (array). `$product` accepts keys `title` (string), `categories` (array of string), `short_description` (string); all optional except `title`. Also produces `AKAI_Prompt::SCHEMA_NAME` (string `'akai_keywords'`) and `AKAI_Prompt::MAX_KEYWORDS` (int `3`, consumed by Task 6).

- [ ] **Step 1: Write the failing test**

Create `tests/prompt.test.php`:

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/run.php`
Expected: FAIL — fatal error, `includes/class-akai-prompt.php` does not exist.

- [ ] **Step 3: Write the implementation**

Create `includes/class-akai-prompt.php`:

```php
<?php
/**
 * Provider-neutral prompt construction.
 *
 * @package autokeywordsai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Builds the provider-neutral request spec.
 *
 * Pure by design: no WordPress calls beyond sanitize_text_field, no HTTP, no options.
 * All prompt-quality iteration happens here, once, for every provider.
 */
class AKAI_Prompt {

	/**
	 * Schema name sent to providers that require one.
	 *
	 * @var string
	 */
	const SCHEMA_NAME = 'akai_keywords';

	/**
	 * Maximum keywords requested from the model.
	 *
	 * @var int
	 */
	const MAX_KEYWORDS = 3;

	/**
	 * Builds the spec for one product.
	 *
	 * @param array  $product  Keys: title (required), categories (string[]), short_description (string).
	 * @param string $language Target language, normally the site locale (e.g. 'es_ES').
	 * @return array{system: string, user: string, schema: array}
	 */
	public static function build_spec( array $product, string $language ): array {
		return array(
			'system' => self::system_instruction( $language ),
			'user'   => self::user_text( $product ),
			'schema' => self::schema(),
		);
	}

	/**
	 * The system instruction.
	 *
	 * @param string $language Target language tag.
	 * @return string
	 */
	private static function system_instruction( string $language ): string {
		return implode(
			' ',
			array(
				'You are an e-commerce SEO specialist choosing Rank Math focus keywords for a product page.',
				sprintf( 'Write all keywords in the language identified by the locale %s.', $language ),
				'Return one primary keyword and up to two secondary keywords.',
				'Use the words a shopper would actually type into Google with buying intent.',
				'Prefer two to four word phrases. Never use the brand or shop name.',
				'Never include commas inside a single keyword. Return lowercase keywords only.',
			)
		);
	}

	/**
	 * The user-facing product context.
	 *
	 * Labels for absent optional fields are omitted entirely rather than left empty, so the
	 * model never sees a dangling "Categories:" with nothing after it.
	 *
	 * @param array $product Product data.
	 * @return string
	 */
	private static function user_text( array $product ): string {
		$lines = array( 'Product: ' . sanitize_text_field( $product['title'] ?? '' ) );

		if ( ! empty( $product['categories'] ) ) {
			$lines[] = 'Categories: ' . sanitize_text_field( implode( ', ', (array) $product['categories'] ) );
		}

		if ( ! empty( $product['short_description'] ) ) {
			$lines[] = 'Description: ' . sanitize_text_field( $product['short_description'] );
		}

		return implode( "\n", $lines );
	}

	/**
	 * The JSON schema, shaped to satisfy strict mode on both providers.
	 *
	 * @return array
	 */
	private static function schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'primary'   => array(
					'type'        => 'string',
					'description' => 'The single best focus keyword.',
				),
				'secondary' => array(
					'type'        => 'array',
					'description' => 'Up to two supporting keywords.',
					'items'       => array( 'type' => 'string' ),
				),
			),
			'required'             => array( 'primary', 'secondary' ),
			'additionalProperties' => false,
		);
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/run.php`
Expected: PASS — all prompt assertions `ok`, `0 failures`.

- [ ] **Step 5: Commit**

```bash
git add includes/class-akai-prompt.php tests/prompt.test.php
git commit -m "feat: add pure provider-neutral prompt builder"
```

---

### Task 3: Provider interface and Gemini provider

**Files:**
- Create: `includes/interface-akai-provider.php`
- Create: `includes/class-akai-provider-gemini.php`
- Create: `tests/fixtures/gemini-success.json`
- Create: `tests/fixtures/gemini-malformed.json`
- Create: `tests/provider-gemini.test.php`

**Interfaces:**
- Consumes: `AKAI_Prompt::SCHEMA_NAME` from Task 2.
- Produces:
  - `interface AKAI_Provider { public function generate( array $spec ): array|WP_Error; }` — success returns `array{primary: string, secondary: array<int, string>}`.
  - `AKAI_Provider_Gemini::__construct( string $api_key, string $model, ?callable $http = null )`. The callable receives `( string $url, array $args )` and must return either a `WP_Error` or an array shaped like `wp_remote_post`'s return: `array{ response: array{ code: int }, body: string }`.
  - `AKAI_Provider_Gemini::build_request_body( array $spec ): array` (public for testing).
  - `AKAI_Provider_Gemini::parse_response( string $body ): array|WP_Error` (public for testing).
  - `AKAI_Provider_Gemini::ENDPOINT` (string), `AKAI_Provider_Gemini::API_REVISION` (string `'2026-05-20'`).
  - `akai_decode_keyword_payload( string $text ): array|WP_Error` — a plain function in `interface-akai-provider.php`, shared by every provider (Task 4 depends on it).
- Error codes every provider must use: `akai_rate_limited` (HTTP 429), `akai_http_error` (network failure or any other non-200), `akai_bad_response` (200 but unparseable or schema-violating body), `akai_no_key` (empty API key).

- [ ] **Step 1: Create the fixtures**

Create `tests/fixtures/gemini-success.json` — the real Interactions API response shape, with the model's JSON payload as a string inside the text content:

```json
{
  "id": "v1_ChdpQUFvYXI",
  "status": "completed",
  "steps": [
    { "type": "thought", "signature": "EvEFCu4FAQw" },
    {
      "type": "model_output",
      "content": [
        { "type": "text", "text": "{\"primary\":\"vela de lavanda\",\"secondary\":[\"vela aromática soja\",\"vela relajante\"]}" }
      ]
    }
  ]
}
```

Create `tests/fixtures/gemini-malformed.json` — a 200 response whose text is not valid JSON:

```json
{
  "id": "v1_bad",
  "status": "completed",
  "steps": [
    {
      "type": "model_output",
      "content": [ { "type": "text", "text": "Sure! Here are some keywords:" } ]
    }
  ]
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/provider-gemini.test.php`:

```php
<?php
/**
 * Tests for AKAI_Provider_Gemini. No network: HTTP is injected.
 *
 * @package autokeywordsai
 */

require_once dirname( __DIR__ ) . '/includes/class-akai-prompt.php';
require_once dirname( __DIR__ ) . '/includes/interface-akai-provider.php';
require_once dirname( __DIR__ ) . '/includes/class-akai-provider-gemini.php';

/**
 * Returns an HTTP double that always answers with the given status and body.
 *
 * @param int    $status HTTP status code.
 * @param string $body   Response body.
 * @param array  $seen   Captured call, by reference.
 * @return callable
 */
function akai_http_double( $status, $body, &$seen ) {
	return function ( $url, $args ) use ( $status, $body, &$seen ) {
		$seen = array(
			'url'  => $url,
			'args' => $args,
		);
		return array(
			'response' => array( 'code' => $status ),
			'body'     => $body,
		);
	};
}

$spec = AKAI_Prompt::build_spec( array( 'title' => 'Vela de lavanda' ), 'es_ES' );

// --- Request body ------------------------------------------------------------
$provider = new AKAI_Provider_Gemini( 'test-key', 'gemini-3.5-flash' );
$body     = $provider->build_request_body( $spec );

akai_assert_same( 'request carries the model', 'gemini-3.5-flash', $body['model'] );
akai_assert_true( 'request input carries the system instruction', str_contains( $body['input'], $spec['system'] ) );
akai_assert_true( 'request input carries the product', str_contains( $body['input'], 'Vela de lavanda' ) );
akai_assert_same( 'response_format asks for JSON', 'application/json', $body['response_format']['mime_type'] );
akai_assert_same( 'response_format carries the schema', $spec['schema'], $body['response_format']['schema'] );

// --- Successful call ---------------------------------------------------------
$seen     = array();
$provider = new AKAI_Provider_Gemini(
	'test-key',
	'gemini-3.5-flash',
	akai_http_double( 200, file_get_contents( __DIR__ . '/fixtures/gemini-success.json' ), $seen )
);
$result = $provider->generate( $spec );

akai_assert_same( 'parses the primary keyword', 'vela de lavanda', $result['primary'] );
akai_assert_same( 'parses the secondary keywords', array( 'vela aromática soja', 'vela relajante' ), $result['secondary'] );
akai_assert_same( 'posts to the interactions endpoint', AKAI_Provider_Gemini::ENDPOINT, $seen['url'] );
akai_assert_same( 'sends the key in the x-goog-api-key header', 'test-key', $seen['args']['headers']['x-goog-api-key'] );
akai_assert_same( 'pins the api revision', AKAI_Provider_Gemini::API_REVISION, $seen['args']['headers']['Api-Revision'] );

// --- Error mapping -----------------------------------------------------------
$seen     = array();
$provider = new AKAI_Provider_Gemini( 'test-key', 'gemini-3.5-flash', akai_http_double( 429, '{"error":"quota"}', $seen ) );
$rate     = $provider->generate( $spec );
akai_assert_same( '429 maps to akai_rate_limited', 'akai_rate_limited', $rate->get_error_code() );

$seen     = array();
$provider = new AKAI_Provider_Gemini( 'test-key', 'gemini-3.5-flash', akai_http_double( 503, 'upstream down', $seen ) );
$http     = $provider->generate( $spec );
akai_assert_same( '503 maps to akai_http_error', 'akai_http_error', $http->get_error_code() );

$seen     = array();
$provider = new AKAI_Provider_Gemini(
	'test-key',
	'gemini-3.5-flash',
	akai_http_double( 200, file_get_contents( __DIR__ . '/fixtures/gemini-malformed.json' ), $seen )
);
$bad = $provider->generate( $spec );
akai_assert_same( 'non-JSON model output maps to akai_bad_response', 'akai_bad_response', $bad->get_error_code() );

$seen     = array();
$provider = new AKAI_Provider_Gemini( 'test-key', 'gemini-3.5-flash', akai_http_double( 200, '{"steps":[]}', $seen ) );
$empty    = $provider->generate( $spec );
akai_assert_same( 'missing model_output maps to akai_bad_response', 'akai_bad_response', $empty->get_error_code() );

$provider = new AKAI_Provider_Gemini( '', 'gemini-3.5-flash', akai_http_double( 200, '{}', $seen ) );
$no_key   = $provider->generate( $spec );
akai_assert_same( 'empty key maps to akai_no_key', 'akai_no_key', $no_key->get_error_code() );

// A transport-level WP_Error must pass through as akai_http_error, not crash.
$provider = new AKAI_Provider_Gemini(
	'test-key',
	'gemini-3.5-flash',
	function () {
		return new WP_Error( 'http_request_failed', 'cURL error 28' );
	}
);
$transport = $provider->generate( $spec );
akai_assert_same( 'transport failure maps to akai_http_error', 'akai_http_error', $transport->get_error_code() );
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php tests/run.php`
Expected: FAIL — fatal error, `includes/interface-akai-provider.php` does not exist.

- [ ] **Step 4: Write the interface**

Create `includes/interface-akai-provider.php`:

```php
<?php
/**
 * Provider contract.
 *
 * @package autokeywordsai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * A keyword-generating AI provider.
 *
 * Implementations translate the provider-neutral spec from AKAI_Prompt into their own wire
 * format and the response back into a plain keyword array. They contain no prompt text.
 */
interface AKAI_Provider {

	/**
	 * Generates keywords for one product.
	 *
	 * @param array $spec Spec from AKAI_Prompt::build_spec().
	 * @return array{primary: string, secondary: array<int, string>}|WP_Error
	 *         WP_Error codes: akai_no_key, akai_rate_limited, akai_http_error, akai_bad_response.
	 */
	public function generate( array $spec );
}
```

- [ ] **Step 5: Write the Gemini provider**

Create `includes/class-akai-provider-gemini.php`:

```php
<?php
/**
 * Google Gemini provider, using the Interactions API.
 *
 * @package autokeywordsai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Talks to the Gemini Interactions API.
 *
 * Google recommends the Interactions API over generateContent for new development, so this
 * targets /v1beta/interactions and pins Api-Revision to keep the response shape stable.
 */
class AKAI_Provider_Gemini implements AKAI_Provider {

	/**
	 * Interactions endpoint.
	 *
	 * @var string
	 */
	const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/interactions';

	/**
	 * Pinned API revision, so a server-side change cannot silently reshape responses.
	 *
	 * @var string
	 */
	const API_REVISION = '2026-05-20';

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	const TIMEOUT = 30;

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Model name.
	 *
	 * @var string
	 */
	private $model;

	/**
	 * HTTP transport: ( string $url, array $args ) => array|WP_Error.
	 *
	 * @var callable
	 */
	private $http;

	/**
	 * Constructor.
	 *
	 * @param string        $api_key API key.
	 * @param string        $model   Model name, e.g. 'gemini-3.5-flash'.
	 * @param callable|null $http    Optional HTTP transport, injected by tests.
	 */
	public function __construct( string $api_key, string $model, ?callable $http = null ) {
		$this->api_key = $api_key;
		$this->model   = $model;
		$this->http    = $http ? $http : function ( $url, $args ) {
			return wp_remote_post( $url, $args );
		};
	}

	/**
	 * Builds the Interactions request body.
	 *
	 * The system instruction and product text are concatenated into a single input string,
	 * which is what the Interactions API accepts.
	 *
	 * @param array $spec Prompt spec.
	 * @return array
	 */
	public function build_request_body( array $spec ): array {
		return array(
			'model'           => $this->model,
			'input'           => $spec['system'] . "\n\n" . $spec['user'],
			'response_format' => array(
				'type'      => 'text',
				'mime_type' => 'application/json',
				'schema'    => $spec['schema'],
			),
		);
	}

	/**
	 * Generates keywords.
	 *
	 * @param array $spec Prompt spec.
	 * @return array|WP_Error
	 */
	public function generate( array $spec ) {
		if ( '' === trim( $this->api_key ) ) {
			return new WP_Error( 'akai_no_key', __( 'No API key configured.', 'autokeywordsai' ) );
		}

		$response = call_user_func(
			$this->http,
			self::ENDPOINT,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Content-Type'    => 'application/json',
					'x-goog-api-key'  => $this->api_key,
					'Api-Revision'    => self::API_REVISION,
				),
				'body'    => wp_json_encode( $this->build_request_body( $spec ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'akai_http_error', $response->get_error_message() );
		}

		$status = (int) ( $response['response']['code'] ?? 0 );
		$body   = (string) ( $response['body'] ?? '' );

		if ( 429 === $status ) {
			return new WP_Error( 'akai_rate_limited', __( 'Provider rate limit reached.', 'autokeywordsai' ) );
		}

		if ( 200 !== $status ) {
			return new WP_Error(
				'akai_http_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: response body excerpt. */
					__( 'Provider returned HTTP %1$d: %2$s', 'autokeywordsai' ),
					$status,
					substr( $body, 0, 300 )
				)
			);
		}

		return $this->parse_response( $body );
	}

	/**
	 * Extracts the keyword array from an Interactions response body.
	 *
	 * @param string $body Raw response body.
	 * @return array|WP_Error
	 */
	public function parse_response( string $body ) {
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'akai_bad_response', __( 'Provider response was not JSON.', 'autokeywordsai' ) );
		}

		$text = null;
		foreach ( (array) ( $decoded['steps'] ?? array() ) as $step ) {
			if ( 'model_output' !== ( $step['type'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $step['content'] ?? array() ) as $part ) {
				if ( 'text' === ( $part['type'] ?? '' ) && isset( $part['text'] ) ) {
					$text = (string) $part['text'];
					break 2;
				}
			}
		}

		if ( null === $text ) {
			return new WP_Error( 'akai_bad_response', __( 'Provider response contained no model output.', 'autokeywordsai' ) );
		}

		return akai_decode_keyword_payload( $text );
	}
}
```

- [ ] **Step 6: Add the shared payload decoder to the interface file**

Both providers decode the same model-authored JSON string, so the decoder lives once,
appended to `includes/interface-akai-provider.php` (below the interface):

```php
/**
 * Decodes the model-authored JSON payload into a keyword array.
 *
 * Shared by every provider: the wire formats differ, but the payload the model writes is
 * identical because both providers send the same schema.
 *
 * @param string $text JSON text produced by the model.
 * @return array{primary: string, secondary: array<int, string>}|WP_Error
 */
function akai_decode_keyword_payload( string $text ) {
	$payload = json_decode( trim( $text ), true );

	if ( ! is_array( $payload ) || ! isset( $payload['primary'] ) || ! is_string( $payload['primary'] ) ) {
		return new WP_Error(
			'akai_bad_response',
			__( 'Model output did not match the requested schema.', 'autokeywordsai' )
		);
	}

	$secondary = array();
	foreach ( (array) ( $payload['secondary'] ?? array() ) as $keyword ) {
		if ( is_string( $keyword ) ) {
			$secondary[] = $keyword;
		}
	}

	return array(
		'primary'   => $payload['primary'],
		'secondary' => $secondary,
	);
}
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `php tests/run.php`
Expected: PASS — all Gemini assertions `ok`, `0 failures`.

- [ ] **Step 8: Commit**

```bash
git add includes/interface-akai-provider.php includes/class-akai-provider-gemini.php tests/provider-gemini.test.php tests/fixtures/
git commit -m "feat: add provider interface and Gemini Interactions provider"
```

---

### Task 4: OpenAI-compatible provider

**Files:**
- Create: `includes/class-akai-provider-openai.php`
- Create: `tests/fixtures/openai-success.json`
- Create: `tests/provider-openai.test.php`

**Interfaces:**
- Consumes: `AKAI_Provider` interface, `akai_decode_keyword_payload()`, `AKAI_Prompt::SCHEMA_NAME` (all from Tasks 2–3).
- Produces: `AKAI_Provider_OpenAI::__construct( string $api_key, string $model, string $base_url, ?callable $http = null )`; `build_request_body( array $spec ): array`; `parse_response( string $body ): array|WP_Error`. Same four error codes as Gemini.

- [ ] **Step 1: Create the fixture**

Create `tests/fixtures/openai-success.json`:

```json
{
  "id": "chatcmpl-123",
  "object": "chat.completion",
  "choices": [
    {
      "index": 0,
      "message": {
        "role": "assistant",
        "content": "{\"primary\":\"jabón artesanal natural\",\"secondary\":[\"jabón de aceite de oliva\",\"jabón hecho a mano\"]}"
      },
      "finish_reason": "stop"
    }
  ]
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/provider-openai.test.php`:

```php
<?php
/**
 * Tests for AKAI_Provider_OpenAI. No network: HTTP is injected.
 *
 * Reuses akai_http_double() from tests/provider-gemini.test.php, which the runner loads
 * first because test files are sorted alphabetically (provider-gemini < provider-openai).
 *
 * @package autokeywordsai
 */

require_once dirname( __DIR__ ) . '/includes/class-akai-prompt.php';
require_once dirname( __DIR__ ) . '/includes/interface-akai-provider.php';
require_once dirname( __DIR__ ) . '/includes/class-akai-provider-openai.php';

$spec = AKAI_Prompt::build_spec( array( 'title' => 'Jabón artesanal' ), 'es_ES' );

// --- Request body ------------------------------------------------------------
$provider = new AKAI_Provider_OpenAI( 'sk-test', 'gpt-5.6', 'https://api.openai.com/v1' );
$body     = $provider->build_request_body( $spec );

akai_assert_same( 'request carries the model', 'gpt-5.6', $body['model'] );
akai_assert_same( 'first message is the system instruction', 'system', $body['messages'][0]['role'] );
akai_assert_same( 'system content is the spec system text', $spec['system'], $body['messages'][0]['content'] );
akai_assert_same( 'second message is the user text', 'user', $body['messages'][1]['role'] );
akai_assert_same( 'user content is the spec user text', $spec['user'], $body['messages'][1]['content'] );
akai_assert_same( 'response_format type is json_schema', 'json_schema', $body['response_format']['type'] );
akai_assert_same( 'schema name is set', AKAI_Prompt::SCHEMA_NAME, $body['response_format']['json_schema']['name'] );
akai_assert_same( 'strict mode is on', true, $body['response_format']['json_schema']['strict'] );
akai_assert_same( 'schema is passed through', $spec['schema'], $body['response_format']['json_schema']['schema'] );

// --- Successful call ---------------------------------------------------------
$seen     = array();
$provider = new AKAI_Provider_OpenAI(
	'sk-test',
	'gpt-5.6',
	'https://api.openai.com/v1',
	akai_http_double( 200, file_get_contents( __DIR__ . '/fixtures/openai-success.json' ), $seen )
);
$result = $provider->generate( $spec );

akai_assert_same( 'parses the primary keyword', 'jabón artesanal natural', $result['primary'] );
akai_assert_same(
	'parses the secondary keywords',
	array( 'jabón de aceite de oliva', 'jabón hecho a mano' ),
	$result['secondary']
);
akai_assert_same( 'posts to chat/completions', 'https://api.openai.com/v1/chat/completions', $seen['url'] );
akai_assert_same( 'sends a bearer token', 'Bearer sk-test', $seen['args']['headers']['Authorization'] );

// A trailing slash on the base URL must not produce a double slash.
$seen     = array();
$provider = new AKAI_Provider_OpenAI(
	'sk-test',
	'llama3',
	'http://localhost:11434/v1/',
	akai_http_double( 200, file_get_contents( __DIR__ . '/fixtures/openai-success.json' ), $seen )
);
$provider->generate( $spec );
akai_assert_same( 'trailing slash in base URL is normalised', 'http://localhost:11434/v1/chat/completions', $seen['url'] );

// --- Error mapping -----------------------------------------------------------
$seen     = array();
$provider = new AKAI_Provider_OpenAI( 'sk-test', 'gpt-5.6', 'https://api.openai.com/v1', akai_http_double( 429, '{}', $seen ) );
akai_assert_same( '429 maps to akai_rate_limited', 'akai_rate_limited', $provider->generate( $spec )->get_error_code() );

$seen     = array();
$provider = new AKAI_Provider_OpenAI( 'sk-test', 'gpt-5.6', 'https://api.openai.com/v1', akai_http_double( 401, 'bad key', $seen ) );
akai_assert_same( '401 maps to akai_http_error', 'akai_http_error', $provider->generate( $spec )->get_error_code() );

$seen     = array();
$provider = new AKAI_Provider_OpenAI( 'sk-test', 'gpt-5.6', 'https://api.openai.com/v1', akai_http_double( 200, '{"choices":[]}', $seen ) );
akai_assert_same( 'no choices maps to akai_bad_response', 'akai_bad_response', $provider->generate( $spec )->get_error_code() );

$provider = new AKAI_Provider_OpenAI( '', 'gpt-5.6', 'https://api.openai.com/v1', akai_http_double( 200, '{}', $seen ) );
akai_assert_same( 'empty key maps to akai_no_key', 'akai_no_key', $provider->generate( $spec )->get_error_code() );
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php tests/run.php`
Expected: FAIL — fatal error, `includes/class-akai-provider-openai.php` does not exist.

- [ ] **Step 4: Write the implementation**

Create `includes/class-akai-provider-openai.php`:

```php
<?php
/**
 * OpenAI-compatible provider.
 *
 * @package autokeywordsai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Talks to any endpoint speaking the OpenAI /chat/completions format.
 *
 * Deliberately targets /chat/completions rather than OpenAI's newer Responses API:
 * chat/completions is current and non-deprecated, and it is the format Groq, OpenRouter,
 * DeepSeek, Together, Ollama and LM Studio implement. Supporting them is the point of this
 * provider, so the compatibility format wins over the vendor-specific one.
 */
class AKAI_Provider_OpenAI implements AKAI_Provider {

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	const TIMEOUT = 30;

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Model name.
	 *
	 * @var string
	 */
	private $model;

	/**
	 * API base URL, without a trailing slash.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * HTTP transport: ( string $url, array $args ) => array|WP_Error.
	 *
	 * @var callable
	 */
	private $http;

	/**
	 * Constructor.
	 *
	 * @param string        $api_key  API key.
	 * @param string        $model    Model name.
	 * @param string        $base_url Base URL, e.g. 'https://api.openai.com/v1'.
	 * @param callable|null $http     Optional HTTP transport, injected by tests.
	 */
	public function __construct( string $api_key, string $model, string $base_url, ?callable $http = null ) {
		$this->api_key  = $api_key;
		$this->model    = $model;
		$this->base_url = rtrim( $base_url, '/' );
		$this->http     = $http ? $http : function ( $url, $args ) {
			return wp_remote_post( $url, $args );
		};
	}

	/**
	 * Builds the chat/completions request body.
	 *
	 * @param array $spec Prompt spec.
	 * @return array
	 */
	public function build_request_body( array $spec ): array {
		return array(
			'model'           => $this->model,
			'messages'        => array(
				array(
					'role'    => 'system',
					'content' => $spec['system'],
				),
				array(
					'role'    => 'user',
					'content' => $spec['user'],
				),
			),
			'response_format' => array(
				'type'        => 'json_schema',
				'json_schema' => array(
					'name'   => AKAI_Prompt::SCHEMA_NAME,
					'strict' => true,
					'schema' => $spec['schema'],
				),
			),
		);
	}

	/**
	 * Generates keywords.
	 *
	 * @param array $spec Prompt spec.
	 * @return array|WP_Error
	 */
	public function generate( array $spec ) {
		if ( '' === trim( $this->api_key ) ) {
			return new WP_Error( 'akai_no_key', __( 'No API key configured.', 'autokeywordsai' ) );
		}

		$response = call_user_func(
			$this->http,
			$this->base_url . '/chat/completions',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->api_key,
				),
				'body'    => wp_json_encode( $this->build_request_body( $spec ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'akai_http_error', $response->get_error_message() );
		}

		$status = (int) ( $response['response']['code'] ?? 0 );
		$body   = (string) ( $response['body'] ?? '' );

		if ( 429 === $status ) {
			return new WP_Error( 'akai_rate_limited', __( 'Provider rate limit reached.', 'autokeywordsai' ) );
		}

		if ( 200 !== $status ) {
			return new WP_Error(
				'akai_http_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: response body excerpt. */
					__( 'Provider returned HTTP %1$d: %2$s', 'autokeywordsai' ),
					$status,
					substr( $body, 0, 300 )
				)
			);
		}

		return $this->parse_response( $body );
	}

	/**
	 * Extracts the keyword array from a chat/completions response body.
	 *
	 * @param string $body Raw response body.
	 * @return array|WP_Error
	 */
	public function parse_response( string $body ) {
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'akai_bad_response', __( 'Provider response was not JSON.', 'autokeywordsai' ) );
		}

		$content = $decoded['choices'][0]['message']['content'] ?? null;
		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return new WP_Error( 'akai_bad_response', __( 'Provider response contained no message content.', 'autokeywordsai' ) );
		}

		return akai_decode_keyword_payload( $content );
	}
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php tests/run.php`
Expected: PASS — all OpenAI assertions `ok`, `0 failures`.

- [ ] **Step 6: Commit**

```bash
git add includes/class-akai-provider-openai.php tests/provider-openai.test.php tests/fixtures/openai-success.json
git commit -m "feat: add OpenAI-compatible provider"
```

---

### Task 5: Settings storage and provider factory

**Files:**
- Create: `includes/class-akai-settings.php`
- Create: `includes/class-akai-provider-factory.php`
- Create: `tests/settings.test.php`
- Modify: `autokeywordsai.php` (add requires inside `akai_bootstrap()`)

**Interfaces:**
- Consumes: both provider classes from Tasks 3–4.
- Produces:
  - `AKAI_Settings::OPTION` (string `'akai_settings'`).
  - `AKAI_Settings::defaults(): array` with keys `provider` (`'gemini'`), `api_key` (`''`), `base_url` (`'https://api.openai.com/v1'`), `model` (`''`), `language` (`''`), `rpm` (`10`).
  - `AKAI_Settings::sanitize( array $input, array $existing ): array` — pure; preserves the stored key when the submitted key is empty.
  - `AKAI_Settings::default_model( string $provider ): string` → `'gemini-3.5-flash'` or `'gpt-5.6'`.
  - `AKAI_Settings::resolve_api_key( array $settings, ?string $constant ): string` — pure; the constant wins.
  - `AKAI_Settings::get(): array` — merged stored settings (WordPress-dependent, not unit-tested).
  - `AKAI_Settings::language( array $settings ): string` — configured language, falling back to `get_locale()` (consumed by Tasks 8–9).
  - `AKAI_Settings::providers(): array<int, string>`.
  - `AKAI_Provider_Factory::make( array $settings, ?string $constant_key = null, ?callable $http = null ): AKAI_Provider`.

- [ ] **Step 1: Write the failing test**

Create `tests/settings.test.php`:

```php
<?php
/**
 * Tests for AKAI_Settings pure helpers and AKAI_Provider_Factory.
 *
 * @package autokeywordsai
 */

require_once dirname( __DIR__ ) . '/includes/interface-akai-provider.php';
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/run.php`
Expected: FAIL — fatal error, `includes/class-akai-settings.php` does not exist.

- [ ] **Step 3: Write the settings class**

Create `includes/class-akai-settings.php`. Only the pure helpers are written in this task; the settings *page* is Task 9:

```php
<?php
/**
 * Settings storage and sanitization.
 *
 * @package autokeywordsai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Owns the plugin's single option, its defaults, and its sanitization rules.
 */
class AKAI_Settings {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION = 'akai_settings';

	/**
	 * Lowest allowed requests-per-minute.
	 *
	 * @var int
	 */
	const MIN_RPM = 1;

	/**
	 * Highest allowed requests-per-minute.
	 *
	 * @var int
	 */
	const MAX_RPM = 600;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'provider' => 'gemini',
			'api_key'  => '',
			'base_url' => 'https://api.openai.com/v1',
			'model'    => '',
			'language' => '',
			'rpm'      => 10,
		);
	}

	/**
	 * Valid provider ids.
	 *
	 * @return array<int, string>
	 */
	public static function providers(): array {
		return array( 'gemini', 'openai' );
	}

	/**
	 * Suggested model for a provider.
	 *
	 * Free text in the UI, because model names churn faster than plugin releases; this is
	 * only the placeholder and the fallback when the field is left blank.
	 *
	 * @param string $provider Provider id.
	 * @return string
	 */
	public static function default_model( string $provider ): string {
		return 'openai' === $provider ? 'gpt-5.6' : 'gemini-3.5-flash';
	}

	/**
	 * Sanitizes submitted settings.
	 *
	 * Pure: takes the existing settings rather than reading the option, so it is testable
	 * and so the write-only API key rule is explicit.
	 *
	 * @param array $input    Raw submitted values.
	 * @param array $existing Currently stored settings.
	 * @return array
	 */
	public static function sanitize( array $input, array $existing ): array {
		$existing = array_merge( self::defaults(), $existing );

		$provider = isset( $input['provider'] ) ? (string) $input['provider'] : $existing['provider'];
		if ( ! in_array( $provider, self::providers(), true ) ) {
			$provider = 'gemini';
		}

		// An empty submitted key means "leave it alone" — the field renders masked and never
		// echoes the stored value back to the browser.
		$submitted_key = isset( $input['api_key'] ) ? trim( (string) $input['api_key'] ) : '';
		$api_key       = '' === $submitted_key ? (string) $existing['api_key'] : $submitted_key;

		$base_url = isset( $input['base_url'] ) ? trim( (string) $input['base_url'] ) : $existing['base_url'];
		$base_url = rtrim( $base_url, '/' );
		if ( '' === $base_url ) {
			$base_url = self::defaults()['base_url'];
		}

		$rpm = isset( $input['rpm'] ) ? (int) $input['rpm'] : (int) $existing['rpm'];
		$rpm = max( self::MIN_RPM, min( self::MAX_RPM, $rpm ) );

		return array(
			'provider' => $provider,
			'api_key'  => $api_key,
			'base_url' => $base_url,
			'model'    => isset( $input['model'] ) ? trim( (string) $input['model'] ) : (string) $existing['model'],
			'language' => isset( $input['language'] ) ? trim( (string) $input['language'] ) : (string) $existing['language'],
			'rpm'      => $rpm,
		);
	}

	/**
	 * Resolves the effective API key, preferring a wp-config.php constant.
	 *
	 * @param array       $settings Settings array.
	 * @param string|null $constant Value of AKAI_API_KEY, or null when undefined.
	 * @return string
	 */
	public static function resolve_api_key( array $settings, ?string $constant ): string {
		if ( is_string( $constant ) && '' !== trim( $constant ) ) {
			return trim( $constant );
		}
		return (string) ( $settings['api_key'] ?? '' );
	}

	/**
	 * Reads the stored settings merged over the defaults.
	 *
	 * @return array
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * The effective target language, defaulting to the site locale.
	 *
	 * @param array $settings Settings array.
	 * @return string
	 */
	public static function language( array $settings ): string {
		$language = trim( (string) ( $settings['language'] ?? '' ) );
		return '' !== $language ? $language : get_locale();
	}
}
```

- [ ] **Step 4: Write the factory**

Create `includes/class-akai-provider-factory.php`:

```php
<?php
/**
 * Provider factory.
 *
 * @package autokeywordsai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Turns settings into a configured provider instance.
 */
class AKAI_Provider_Factory {

	/**
	 * Builds the configured provider.
	 *
	 * @param array         $settings     Settings array.
	 * @param string|null   $constant_key Value of AKAI_API_KEY, or null when undefined.
	 * @param callable|null $http         Optional HTTP transport, injected by tests.
	 * @return AKAI_Provider
	 */
	public static function make( array $settings, ?string $constant_key = null, ?callable $http = null ): AKAI_Provider {
		$provider = (string) ( $settings['provider'] ?? 'gemini' );
		$api_key  = AKAI_Settings::resolve_api_key( $settings, $constant_key );

		$model = trim( (string) ( $settings['model'] ?? '' ) );
		if ( '' === $model ) {
			$model = AKAI_Settings::default_model( $provider );
		}

		if ( 'openai' === $provider ) {
			return new AKAI_Provider_OpenAI(
				$api_key,
				$model,
				(string) ( $settings['base_url'] ?? 'https://api.openai.com/v1' ),
				$http
			);
		}

		return new AKAI_Provider_Gemini( $api_key, $model, $http );
	}
}
```

- [ ] **Step 5: Wire the requires into the bootstrap**

In `autokeywordsai.php`, replace the placeholder comment inside `akai_bootstrap()`:

```php
	require_once AKAI_PLUGIN_DIR . 'includes/class-akai-prompt.php';
	require_once AKAI_PLUGIN_DIR . 'includes/interface-akai-provider.php';
	require_once AKAI_PLUGIN_DIR . 'includes/class-akai-provider-gemini.php';
	require_once AKAI_PLUGIN_DIR . 'includes/class-akai-provider-openai.php';
	require_once AKAI_PLUGIN_DIR . 'includes/class-akai-settings.php';
	require_once AKAI_PLUGIN_DIR . 'includes/class-akai-provider-factory.php';
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php tests/run.php`
Expected: PASS — all settings and factory assertions `ok`, `0 failures`.

- [ ] **Step 7: Commit**

```bash
git add includes/class-akai-settings.php includes/class-akai-provider-factory.php tests/settings.test.php autokeywordsai.php
git commit -m "feat: add settings storage, sanitization and provider factory"
```

---

### Task 6: Keyword writer

**Files:**
- Create: `includes/class-akai-keyword-writer.php`
- Create: `tests/keyword-writer.test.php`
- Modify: `autokeywordsai.php` (add the require)

**Interfaces:**
- Consumes: `AKAI_Prompt::MAX_KEYWORDS` from Task 2.
- Produces:
  - `AKAI_Keyword_Writer::META_KEY` (string `'rank_math_focus_keyword'`).
  - `AKAI_Keyword_Writer::should_write( $existing ): bool` — pure; true only when the existing value is empty.
  - `AKAI_Keyword_Writer::to_meta_value( array $result ): string|WP_Error` — pure; returns the comma-separated value or a `WP_Error` with code `akai_bad_response`.
  - `AKAI_Keyword_Writer::write( int $product_id, array $result ): bool|WP_Error` — performs the guarded meta write.

- [ ] **Step 1: Write the failing test**

Create `tests/keyword-writer.test.php`:

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/run.php`
Expected: FAIL — fatal error, `includes/class-akai-keyword-writer.php` does not exist.

- [ ] **Step 3: Write the implementation**

Create `includes/class-akai-keyword-writer.php`:

```php
<?php
/**
 * Rank Math focus keyword writing.
 *
 * @package autokeywordsai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Owns the only update_post_meta call in the plugin.
 *
 * The skip rule and the meta-value construction are pure static methods so they can be
 * tested without WordPress; write() is the thin WordPress-touching wrapper.
 */
class AKAI_Keyword_Writer {

	/**
	 * Rank Math's focus keyword meta key.
	 *
	 * @var string
	 */
	const META_KEY = 'rank_math_focus_keyword';

	/**
	 * Whether a product with this existing meta value should be written to.
	 *
	 * @param mixed $existing Current meta value (string, null, or false).
	 * @return bool
	 */
	public static function should_write( $existing ): bool {
		return '' === trim( (string) $existing );
	}

	/**
	 * Builds the comma-separated meta value.
	 *
	 * Rank Math treats the value as a comma-separated list whose first entry is primary, so
	 * commas inside an individual keyword are stripped rather than escaped.
	 *
	 * @param array $result Provider result: primary (string), secondary (string[]).
	 * @return string|WP_Error
	 */
	public static function to_meta_value( array $result ) {
		$candidates = array_merge(
			array( (string) ( $result['primary'] ?? '' ) ),
			array_map( 'strval', (array) ( $result['secondary'] ?? array() ) )
		);

		$keywords = array();
		$seen     = array();
		foreach ( $candidates as $candidate ) {
			$keyword = trim( str_replace( ',', ' ', $candidate ) );
			$keyword = preg_replace( '/\s+/', ' ', $keyword );

			if ( '' === $keyword ) {
				continue;
			}

			$fingerprint = function_exists( 'mb_strtolower' ) ? mb_strtolower( $keyword ) : strtolower( $keyword );
			if ( isset( $seen[ $fingerprint ] ) ) {
				continue;
			}

			$seen[ $fingerprint ] = true;
			$keywords[]           = $keyword;

			if ( count( $keywords ) >= AKAI_Prompt::MAX_KEYWORDS ) {
				break;
			}
		}

		// Writing an empty value would set the field to "not empty enough to regenerate but
		// useless to Rank Math", permanently excluding the product from future runs.
		if ( empty( $keywords ) ) {
			return new WP_Error(
				'akai_bad_response',
				__( 'Model returned no usable keyword.', 'autokeywordsai' )
			);
		}

		return implode( ', ', $keywords );
	}

	/**
	 * Writes the keyword for a product, honouring the skip rule.
	 *
	 * @param int   $product_id Product post ID.
	 * @param array $result     Provider result.
	 * @return bool|WP_Error True on write, false when skipped, WP_Error when unusable.
	 */
	public static function write( int $product_id, array $result ) {
		$existing = get_post_meta( $product_id, self::META_KEY, true );
		if ( ! self::should_write( $existing ) ) {
			return false;
		}

		$value = self::to_meta_value( $result );
		if ( is_wp_error( $value ) ) {
			return $value;
		}

		update_post_meta( $product_id, self::META_KEY, $value );
		return true;
	}
}
```

- [ ] **Step 4: Add the require to the bootstrap**

In `autokeywordsai.php`, inside `akai_bootstrap()`, after the factory require:

```php
	require_once AKAI_PLUGIN_DIR . 'includes/class-akai-keyword-writer.php';
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php tests/run.php`
Expected: PASS — all writer assertions `ok`, `0 failures`.

- [ ] **Step 6: Commit**

```bash
git add includes/class-akai-keyword-writer.php tests/keyword-writer.test.php autokeywordsai.php
git commit -m "feat: add guarded Rank Math keyword writer"
```

---

### Task 7: Logger

**Files:**
- Create: `includes/class-akai-logger.php`
- Create: `tests/logger.test.php`
- Modify: `autokeywordsai.php` (add the require)

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `AKAI_Logger::ERRORS_OPTION` (string `'akai_recent_errors'`), `AKAI_Logger::MAX_ERRORS` (int `50`), `AKAI_Logger::SOURCE` (string `'autokeywordsai'`).
  - `AKAI_Logger::append_error( array $existing, array $entry ): array` — pure; newest first, capped at `MAX_ERRORS`.
  - `AKAI_Logger::error( int $product_id, string $message ): void` — logs via `wc_get_logger()` and appends to the option.
  - `AKAI_Logger::recent_errors(): array`, `AKAI_Logger::clear(): void`.

- [ ] **Step 1: Write the failing test**

Create `tests/logger.test.php`:

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/run.php`
Expected: FAIL — fatal error, `includes/class-akai-logger.php` does not exist.

- [ ] **Step 3: Write the implementation**

Create `includes/class-akai-logger.php`:

```php
<?php
/**
 * Error logging.
 *
 * @package autokeywordsai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Logs failures to WooCommerce's logger and keeps a short list for the settings screen.
 *
 * Failures nobody can see are how a tool like this rots, so every error lands in two
 * places: WooCommerce's log viewer for detail, and a capped option for at-a-glance status.
 */
class AKAI_Logger {

	/**
	 * Option holding the recent-errors list.
	 *
	 * @var string
	 */
	const ERRORS_OPTION = 'akai_recent_errors';

	/**
	 * How many recent errors to retain.
	 *
	 * @var int
	 */
	const MAX_ERRORS = 50;

	/**
	 * WooCommerce log source.
	 *
	 * @var string
	 */
	const SOURCE = 'autokeywordsai';

	/**
	 * Prepends an entry and enforces the cap.
	 *
	 * @param array $existing Existing list.
	 * @param array $entry    Entry with keys product_id, message, time.
	 * @return array
	 */
	public static function append_error( $existing, array $entry ): array {
		$list = is_array( $existing ) ? $existing : array();
		array_unshift( $list, $entry );
		return array_slice( $list, 0, self::MAX_ERRORS );
	}

	/**
	 * Records a failure for one product.
	 *
	 * @param int    $product_id Product post ID.
	 * @param string $message    Failure message.
	 * @return void
	 */
	public static function error( int $product_id, string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error(
				sprintf( 'Product %d: %s', $product_id, $message ),
				array( 'source' => self::SOURCE )
			);
		}

		update_option(
			self::ERRORS_OPTION,
			self::append_error(
				get_option( self::ERRORS_OPTION, array() ),
				array(
					'product_id' => $product_id,
					'message'    => $message,
					'time'       => time(),
				)
			),
			false
		);
	}

	/**
	 * Returns the recent-errors list.
	 *
	 * @return array
	 */
	public static function recent_errors(): array {
		$stored = get_option( self::ERRORS_OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Clears the recent-errors list.
	 *
	 * @return void
	 */
	public static function clear(): void {
		update_option( self::ERRORS_OPTION, array(), false );
	}
}
```

- [ ] **Step 4: Add the require to the bootstrap**

In `autokeywordsai.php`, inside `akai_bootstrap()`:

```php
	require_once AKAI_PLUGIN_DIR . 'includes/class-akai-logger.php';
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php tests/run.php`
Expected: PASS — all logger assertions `ok`, `0 failures`.

- [ ] **Step 6: Commit**

```bash
git add includes/class-akai-logger.php tests/logger.test.php autokeywordsai.php
git commit -m "feat: add logger with capped recent-errors list"
```

---

### Task 8: Queue — enqueue, stagger, handler, retry policy, publish hook

**Files:**
- Create: `includes/class-akai-queue.php`
- Create: `tests/queue.test.php`
- Modify: `autokeywordsai.php` (add the require and register hooks)

**Interfaces:**
- Consumes: `AKAI_Settings`, `AKAI_Provider_Factory`, `AKAI_Keyword_Writer`, `AKAI_Logger`, `AKAI_Prompt`, and the provider error codes from Tasks 2–7.
- Produces:
  - `AKAI_Queue::HOOK` (string `'akai_generate_keyword'`), `AKAI_Queue::GROUP` (string `'autokeywordsai'`), `AKAI_Queue::RATE_LIMIT_DELAY` (int `300`), `AKAI_Queue::RETRY_DELAY` (int `60`), `AKAI_Queue::MAX_ATTEMPTS` (int `2`).
  - `AKAI_Queue::stagger_timestamp( int $index, int $rpm, int $now ): int` — pure.
  - `AKAI_Queue::decide_retry( string $error_code, int $attempt ): array` — pure; returns `array{ action: 'retry'|'give_up', delay: int, attempt: int }`.
  - `AKAI_Queue::missing_keyword_product_ids(): array<int, int>`.
  - `AKAI_Queue::enqueue_missing(): int` — returns how many actions were scheduled.
  - `AKAI_Queue::handle( int $product_id, int $attempt = 1 ): void` — the Action Scheduler callback.
  - `AKAI_Queue::on_transition( string $new_status, string $old_status, WP_Post $post ): void`.
  - `AKAI_Queue::register(): void` — attaches both hooks.

- [ ] **Step 1: Write the failing test**

Create `tests/queue.test.php`. Only the pure policy functions are unit-tested; the
Action-Scheduler-touching methods are verified manually in Step 7:

```php
<?php
/**
 * Tests for AKAI_Queue's pure scheduling and retry policy.
 *
 * @package autokeywordsai
 */

require_once dirname( __DIR__ ) . '/includes/class-akai-queue.php';

// --- Stagger maths ----------------------------------------------------------
// At 10 rpm the spacing is 6 seconds, so the Nth product runs 6N seconds out.
akai_assert_same( 'the first product runs immediately', 1000, AKAI_Queue::stagger_timestamp( 0, 10, 1000 ) );
akai_assert_same( 'the second product is spaced by 6s at 10rpm', 1006, AKAI_Queue::stagger_timestamp( 1, 10, 1000 ) );
akai_assert_same( 'the tenth product lands at +54s at 10rpm', 1054, AKAI_Queue::stagger_timestamp( 9, 10, 1000 ) );
akai_assert_same( 'at 60rpm the spacing is 1s', 1005, AKAI_Queue::stagger_timestamp( 5, 60, 1000 ) );
akai_assert_same( 'at 1rpm the spacing is 60s', 1120, AKAI_Queue::stagger_timestamp( 2, 1, 1000 ) );
// A nonsense rpm must not divide by zero or schedule everything at once.
akai_assert_same( 'rpm of 0 is treated as 1', 1060, AKAI_Queue::stagger_timestamp( 1, 0, 1000 ) );
// Above 60rpm the spacing floors at 1s rather than collapsing to 0.
akai_assert_same( 'spacing never drops below 1s', 1003, AKAI_Queue::stagger_timestamp( 3, 600, 1000 ) );

// --- Retry policy -----------------------------------------------------------
$limited = AKAI_Queue::decide_retry( 'akai_rate_limited', 1 );
akai_assert_same( 'a rate limit retries', 'retry', $limited['action'] );
akai_assert_same( 'a rate limit waits RATE_LIMIT_DELAY', AKAI_Queue::RATE_LIMIT_DELAY, $limited['delay'] );
akai_assert_same( 'a rate limit does not consume an attempt', 1, $limited['attempt'] );

// Rate limiting is expected on free tiers, so it must never exhaust the attempt budget.
$limited_late = AKAI_Queue::decide_retry( 'akai_rate_limited', 9 );
akai_assert_same( 'a rate limit still retries after many attempts', 'retry', $limited_late['action'] );

$http_first = AKAI_Queue::decide_retry( 'akai_http_error', 1 );
akai_assert_same( 'a first http error retries', 'retry', $http_first['action'] );
akai_assert_same( 'a retried http error waits RETRY_DELAY', AKAI_Queue::RETRY_DELAY, $http_first['delay'] );
akai_assert_same( 'a retried http error consumes an attempt', 2, $http_first['attempt'] );

$http_last = AKAI_Queue::decide_retry( 'akai_http_error', AKAI_Queue::MAX_ATTEMPTS );
akai_assert_same( 'http errors give up at MAX_ATTEMPTS', 'give_up', $http_last['action'] );

// A malformed response is deterministic — retrying it just burns quota.
akai_assert_same( 'a bad response gives up immediately', 'give_up', AKAI_Queue::decide_retry( 'akai_bad_response', 1 )['action'] );
akai_assert_same( 'a missing key gives up immediately', 'give_up', AKAI_Queue::decide_retry( 'akai_no_key', 1 )['action'] );
akai_assert_same( 'an unknown code gives up', 'give_up', AKAI_Queue::decide_retry( 'something_else', 1 )['action'] );
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/run.php`
Expected: FAIL — fatal error, `includes/class-akai-queue.php` does not exist.

- [ ] **Step 3: Write the implementation**

Create `includes/class-akai-queue.php`:

```php
<?php
/**
 * Action Scheduler queue.
 *
 * @package autokeywordsai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Schedules and runs one keyword generation per product.
 *
 * One action per product, staggered by the configured requests-per-minute. This respects
 * free-tier rate limits by construction: no sleep() inside a request, no PHP timeout risk,
 * per-product retries, and a run that survives closing the browser tab.
 */
class AKAI_Queue {

	/**
	 * Action Scheduler hook.
	 *
	 * @var string
	 */
	const HOOK = 'akai_generate_keyword';

	/**
	 * Action Scheduler group.
	 *
	 * @var string
	 */
	const GROUP = 'autokeywordsai';

	/**
	 * Seconds to wait after a rate-limit response.
	 *
	 * @var int
	 */
	const RATE_LIMIT_DELAY = 300;

	/**
	 * Seconds to wait before retrying a transport failure.
	 *
	 * @var int
	 */
	const RETRY_DELAY = 60;

	/**
	 * Maximum attempts for a retryable transport failure.
	 *
	 * @var int
	 */
	const MAX_ATTEMPTS = 2;

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'handle' ), 10, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 10, 3 );
	}

	/**
	 * When the Nth product should run.
	 *
	 * @param int $index Zero-based position in the run.
	 * @param int $rpm   Requests per minute.
	 * @param int $now   Current unix timestamp.
	 * @return int Unix timestamp.
	 */
	public static function stagger_timestamp( int $index, int $rpm, int $now ): int {
		$rpm     = max( 1, $rpm );
		$spacing = max( 1, (int) floor( 60 / $rpm ) );
		return $now + ( $index * $spacing );
	}

	/**
	 * Decides what to do after a failure.
	 *
	 * Rate limiting is expected on free tiers, so it reschedules indefinitely without
	 * consuming the attempt budget. A malformed response is deterministic, so retrying it
	 * would only burn quota.
	 *
	 * @param string $error_code Provider error code.
	 * @param int    $attempt    Current attempt number, 1-based.
	 * @return array{action: string, delay: int, attempt: int}
	 */
	public static function decide_retry( string $error_code, int $attempt ): array {
		if ( 'akai_rate_limited' === $error_code ) {
			return array(
				'action'  => 'retry',
				'delay'   => self::RATE_LIMIT_DELAY,
				'attempt' => $attempt,
			);
		}

		if ( 'akai_http_error' === $error_code && $attempt < self::MAX_ATTEMPTS ) {
			return array(
				'action'  => 'retry',
				'delay'   => self::RETRY_DELAY,
				'attempt' => $attempt + 1,
			);
		}

		return array(
			'action'  => 'give_up',
			'delay'   => 0,
			'attempt' => $attempt,
		);
	}

	/**
	 * Product IDs whose focus keyword is missing or empty.
	 *
	 * @return array<int, int>
	 */
	public static function missing_keyword_product_ids(): array {
		return get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'     => AKAI_Keyword_Writer::META_KEY,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => AKAI_Keyword_Writer::META_KEY,
						'value'   => '',
						'compare' => '=',
					),
				),
			)
		);
	}

	/**
	 * Schedules one staggered action per product missing a keyword.
	 *
	 * @return int Number of actions scheduled.
	 */
	public static function enqueue_missing(): int {
		$settings = AKAI_Settings::get();
		$api_key  = AKAI_Settings::resolve_api_key(
			$settings,
			defined( 'AKAI_API_KEY' ) ? (string) constant( 'AKAI_API_KEY' ) : null
		);

		// Without a key every action would fail immediately, so enqueue nothing.
		if ( '' === trim( $api_key ) ) {
			return 0;
		}

		$ids = self::missing_keyword_product_ids();
		$now = time();
		$rpm = (int) $settings['rpm'];

		foreach ( $ids as $index => $product_id ) {
			as_schedule_single_action(
				self::stagger_timestamp( $index, $rpm, $now ),
				self::HOOK,
				array( (int) $product_id, 1 ),
				self::GROUP,
				true
			);
		}

		return count( $ids );
	}

	/**
	 * Generates and writes the keyword for one product.
	 *
	 * @param int $product_id Product post ID.
	 * @param int $attempt    Attempt number, 1-based.
	 * @return void
	 */
	public static function handle( int $product_id, int $attempt = 1 ): void {
		$product = get_post( $product_id );
		if ( ! $product || 'product' !== $product->post_type ) {
			return;
		}

		// Re-check here as well as at enqueue time: the owner may have filled the field by
		// hand in the minutes between scheduling and running.
		if ( ! AKAI_Keyword_Writer::should_write( get_post_meta( $product_id, AKAI_Keyword_Writer::META_KEY, true ) ) ) {
			return;
		}

		$settings = AKAI_Settings::get();
		$provider = AKAI_Provider_Factory::make(
			$settings,
			defined( 'AKAI_API_KEY' ) ? (string) constant( 'AKAI_API_KEY' ) : null
		);

		$spec = AKAI_Prompt::build_spec(
			self::product_context( $product_id ),
			AKAI_Settings::language( $settings )
		);

		$result = $provider->generate( $spec );

		if ( is_wp_error( $result ) ) {
			self::handle_failure( $product_id, $attempt, $result );
			return;
		}

		$written = AKAI_Keyword_Writer::write( $product_id, $result );
		if ( is_wp_error( $written ) ) {
			self::handle_failure( $product_id, $attempt, $written );
		}
	}

	/**
	 * Applies the retry policy to a failure.
	 *
	 * @param int      $product_id Product post ID.
	 * @param int      $attempt    Attempt number.
	 * @param WP_Error $error      The failure.
	 * @return void
	 */
	private static function handle_failure( int $product_id, int $attempt, WP_Error $error ): void {
		$decision = self::decide_retry( $error->get_error_code(), $attempt );

		if ( 'retry' === $decision['action'] ) {
			as_schedule_single_action(
				time() + $decision['delay'],
				self::HOOK,
				array( $product_id, $decision['attempt'] ),
				self::GROUP,
				true
			);
			return;
		}

		AKAI_Logger::error( $product_id, $error->get_error_message() );
	}

	/**
	 * Collects the product context the prompt needs.
	 *
	 * @param int $product_id Product post ID.
	 * @return array
	 */
	private static function product_context( int $product_id ): array {
		$terms = get_the_terms( $product_id, 'product_cat' );

		return array(
			'title'             => get_the_title( $product_id ),
			'categories'        => is_array( $terms ) ? wp_list_pluck( $terms, 'name' ) : array(),
			'short_description' => get_post_field( 'post_excerpt', $product_id ),
		);
	}

	/**
	 * Queues a keyword for a product that has just been published.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Previous post status.
	 * @param WP_Post $post       The post.
	 * @return void
	 */
	public static function on_transition( string $new_status, string $old_status, $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}

		if ( ! $post || 'product' !== $post->post_type ) {
			return;
		}

		if ( ! AKAI_Keyword_Writer::should_write( get_post_meta( $post->ID, AKAI_Keyword_Writer::META_KEY, true ) ) ) {
			return;
		}

		as_enqueue_async_action( self::HOOK, array( (int) $post->ID, 1 ), self::GROUP, true );
	}
}
```

- [ ] **Step 4: Wire it into the bootstrap**

In `autokeywordsai.php`, inside `akai_bootstrap()`, add the require and register the hooks:

```php
	require_once AKAI_PLUGIN_DIR . 'includes/class-akai-queue.php';

	AKAI_Queue::register();
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php tests/run.php`
Expected: PASS — all queue assertions `ok`, `0 failures`.

- [ ] **Step 6: Commit**

```bash
git add includes/class-akai-queue.php tests/queue.test.php autokeywordsai.php
git commit -m "feat: add Action Scheduler queue with stagger and retry policy"
```

- [ ] **Step 7: Manual verification against a real WordPress**

The Action-Scheduler-touching paths cannot be unit-tested, so verify them once by hand on a
local WooCommerce install with the plugin symlinked in and a real API key configured:

```bash
wp eval 'var_dump( count( AKAI_Queue::missing_keyword_product_ids() ) );'
wp eval 'var_dump( AKAI_Queue::enqueue_missing() );'
wp action-scheduler run --group=autokeywordsai
wp post meta get <product_id> rank_math_focus_keyword
```

Expected: the count matches the number of products with an empty keyword; `enqueue_missing()`
returns that same number; after running the queue the meta value is a comma-separated list of
up to three keywords. Then confirm the publish path by publishing a new draft product and
checking that a `akai_generate_keyword` action appears under
**WooCommerce → Status → Scheduled Actions**.

---

### Task 9: Settings page, bulk button, status and test connection

**Files:**
- Modify: `includes/class-akai-settings.php` (append the admin UI methods)
- Modify: `autokeywordsai.php` (register the admin hooks)

**Interfaces:**
- Consumes: everything from Tasks 2–8.
- Produces: `AKAI_Settings::register_admin(): void`; `AKAI_Settings::add_menu(): void`; `AKAI_Settings::render_page(): void`; `AKAI_Settings::handle_post(): void`. Page slug `autokeywordsai`, capability `manage_woocommerce`, nonce actions `akai_save_settings`, `akai_run_bulk`, `akai_test_connection`.

- [ ] **Step 1: Append the admin methods to `AKAI_Settings`**

Add these methods inside the existing `AKAI_Settings` class in
`includes/class-akai-settings.php`:

```php
	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE = 'autokeywordsai';

	/**
	 * Capability required to view and run anything on the settings page.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Registers admin hooks.
	 *
	 * @return void
	 */
	public static function register_admin(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_akai_settings', array( __CLASS__, 'handle_post' ) );
	}

	/**
	 * Adds the submenu under Products.
	 *
	 * @return void
	 */
	public static function add_menu(): void {
		add_submenu_page(
			'edit.php?post_type=product',
			__( 'AutoKeywordsAI', 'autokeywordsai' ),
			__( 'AutoKeywordsAI', 'autokeywordsai' ),
			self::CAPABILITY,
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Handles all three form submissions: save, bulk run, and test connection.
	 *
	 * @return void
	 */
	public static function handle_post(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'autokeywordsai' ) );
		}

		$action = isset( $_POST['akai_action'] ) ? sanitize_text_field( wp_unslash( $_POST['akai_action'] ) ) : '';
		check_admin_referer( 'akai_' . $action );

		$notice = '';

		if ( 'save_settings' === $action ) {
			$input = isset( $_POST['akai'] ) ? wp_unslash( (array) $_POST['akai'] ) : array();
			update_option( self::OPTION, self::sanitize( $input, self::get() ) );
			$notice = 'saved';
		}

		if ( 'run_bulk' === $action ) {
			$count  = AKAI_Queue::enqueue_missing();
			$notice = 0 === $count ? 'nothing' : 'queued-' . $count;
		}

		if ( 'test_connection' === $action ) {
			$notice = 'test-' . rawurlencode( self::run_test() );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'   => 'product',
					'page'        => self::PAGE,
					'akai_notice' => $notice,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Runs one throwaway request through the full chain and describes the outcome.
	 *
	 * @return string
	 */
	private static function run_test(): string {
		$settings = self::get();
		$provider = AKAI_Provider_Factory::make(
			$settings,
			defined( 'AKAI_API_KEY' ) ? (string) constant( 'AKAI_API_KEY' ) : null
		);

		$result = $provider->generate(
			AKAI_Prompt::build_spec(
				array(
					'title'             => 'Test product',
					'categories'        => array( 'Test' ),
					'short_description' => 'A sample product used only to verify the connection.',
				),
				self::language( $settings )
			)
		);

		if ( is_wp_error( $result ) ) {
			return sprintf( '%s: %s', $result->get_error_code(), $result->get_error_message() );
		}

		$value = AKAI_Keyword_Writer::to_meta_value( $result );
		return is_wp_error( $value ) ? $value->get_error_message() : $value;
	}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$settings     = self::get();
		$key_locked   = defined( 'AKAI_API_KEY' ) && '' !== trim( (string) constant( 'AKAI_API_KEY' ) );
		$has_key      = '' !== trim( self::resolve_api_key( $settings, $key_locked ? (string) constant( 'AKAI_API_KEY' ) : null ) );
		$pending      = function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( AKAI_Queue::HOOK, null, AKAI_Queue::GROUP );
		$missing      = count( AKAI_Queue::missing_keyword_product_ids() );
		$errors       = AKAI_Logger::recent_errors();
		$notice       = isset( $_GET['akai_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['akai_notice'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AutoKeywordsAI', 'autokeywordsai' ); ?></h1>

			<?php if ( '' !== $notice ) : ?>
				<div class="notice notice-info"><p><?php echo esc_html( self::notice_text( $notice ) ); ?></p></div>
			<?php endif; ?>

			<p>
				<?php
				printf(
					/* translators: %d: number of products with no focus keyword. */
					esc_html( _n( '%d product has no focus keyword.', '%d products have no focus keyword.', $missing, 'autokeywordsai' ) ),
					(int) $missing
				);
				?>
				<?php if ( $pending ) : ?>
					<strong><?php esc_html_e( 'A run is in progress.', 'autokeywordsai' ); ?></strong>
				<?php endif; ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="akai_settings" />
				<input type="hidden" name="akai_action" value="save_settings" />
				<?php wp_nonce_field( 'akai_save_settings' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Provider', 'autokeywordsai' ); ?></th>
						<td>
							<label>
								<input type="radio" name="akai[provider]" value="gemini" <?php checked( 'gemini', $settings['provider'] ); ?> />
								<?php esc_html_e( 'Google Gemini', 'autokeywordsai' ); ?>
							</label><br />
							<label>
								<input type="radio" name="akai[provider]" value="openai" <?php checked( 'openai', $settings['provider'] ); ?> />
								<?php esc_html_e( 'OpenAI-compatible', 'autokeywordsai' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'OpenAI-compatible covers OpenAI, Groq, OpenRouter, DeepSeek, Together, Ollama and LM Studio — set the base URL below.', 'autokeywordsai' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="akai-api-key"><?php esc_html_e( 'API key', 'autokeywordsai' ); ?></label></th>
						<td>
							<?php if ( $key_locked ) : ?>
								<p><code>AKAI_API_KEY</code> <?php esc_html_e( 'is defined in wp-config.php and takes precedence.', 'autokeywordsai' ); ?></p>
							<?php else : ?>
								<input type="password" id="akai-api-key" name="akai[api_key]" value="" class="regular-text" autocomplete="off"
									placeholder="<?php echo esc_attr( $has_key ? __( 'Saved — leave blank to keep it', 'autokeywordsai' ) : __( 'Paste your key', 'autokeywordsai' ) ); ?>" />
								<p class="description"><?php esc_html_e( 'Never displayed once saved. Leave blank to keep the stored key.', 'autokeywordsai' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="akai-base-url"><?php esc_html_e( 'Base URL', 'autokeywordsai' ); ?></label></th>
						<td>
							<input type="url" id="akai-base-url" name="akai[base_url]" value="<?php echo esc_attr( $settings['base_url'] ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'OpenAI-compatible only. Ignored for Gemini.', 'autokeywordsai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="akai-model"><?php esc_html_e( 'Model', 'autokeywordsai' ); ?></label></th>
						<td>
							<input type="text" id="akai-model" name="akai[model]" value="<?php echo esc_attr( $settings['model'] ); ?>" class="regular-text"
								placeholder="<?php echo esc_attr( self::default_model( $settings['provider'] ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave blank to use the suggested model shown.', 'autokeywordsai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="akai-language"><?php esc_html_e( 'Keyword language', 'autokeywordsai' ); ?></label></th>
						<td>
							<input type="text" id="akai-language" name="akai[language]" value="<?php echo esc_attr( $settings['language'] ); ?>" class="regular-text"
								placeholder="<?php echo esc_attr( get_locale() ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave blank to use the site language.', 'autokeywordsai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="akai-rpm"><?php esc_html_e( 'Requests per minute', 'autokeywordsai' ); ?></label></th>
						<td>
							<input type="number" id="akai-rpm" name="akai[rpm]" value="<?php echo esc_attr( (string) $settings['rpm'] ); ?>" min="1" max="600" />
							<p class="description"><?php esc_html_e( 'Keep this at or below your provider free-tier limit. Products are spaced out accordingly.', 'autokeywordsai' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'autokeywordsai' ) ); ?>
			</form>

			<hr />

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:1em;">
				<input type="hidden" name="action" value="akai_settings" />
				<input type="hidden" name="akai_action" value="run_bulk" />
				<?php wp_nonce_field( 'akai_run_bulk' ); ?>
				<?php submit_button( __( 'Generate missing keywords', 'autokeywordsai' ), 'primary', 'submit', false, $has_key ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
				<input type="hidden" name="action" value="akai_settings" />
				<input type="hidden" name="akai_action" value="test_connection" />
				<?php wp_nonce_field( 'akai_test_connection' ); ?>
				<?php submit_button( __( 'Test connection', 'autokeywordsai' ), 'secondary', 'submit', false, $has_key ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>

			<?php if ( ! $has_key ) : ?>
				<p class="description"><?php esc_html_e( 'Save an API key to enable these buttons.', 'autokeywordsai' ); ?></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Recent errors', 'autokeywordsai' ); ?></h2>
			<?php if ( empty( $errors ) ) : ?>
				<p><?php esc_html_e( 'No errors recorded.', 'autokeywordsai' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Product', 'autokeywordsai' ); ?></th>
							<th><?php esc_html_e( 'When', 'autokeywordsai' ); ?></th>
							<th><?php esc_html_e( 'Error', 'autokeywordsai' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $errors as $entry ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( get_edit_post_link( (int) $entry['product_id'] ) ); ?>">
									<?php echo esc_html( get_the_title( (int) $entry['product_id'] ) ); ?>
								</a>
							</td>
							<td><?php echo esc_html( gmdate( 'Y-m-d H:i', (int) $entry['time'] ) ); ?></td>
							<td><?php echo esc_html( $entry['message'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Turns a notice slug into human text.
	 *
	 * @param string $notice Notice slug.
	 * @return string
	 */
	private static function notice_text( string $notice ): string {
		if ( 'saved' === $notice ) {
			return __( 'Settings saved.', 'autokeywordsai' );
		}
		if ( 'nothing' === $notice ) {
			return __( 'Nothing to do: every product already has a focus keyword, or no API key is configured.', 'autokeywordsai' );
		}
		if ( str_starts_with( $notice, 'queued-' ) ) {
			return sprintf(
				/* translators: %d: number of products queued. */
				__( 'Queued %d products. They will be processed in the background at your configured rate.', 'autokeywordsai' ),
				(int) substr( $notice, 7 )
			);
		}
		if ( str_starts_with( $notice, 'test-' ) ) {
			return sprintf(
				/* translators: %s: provider result or error message. */
				__( 'Test result: %s', 'autokeywordsai' ),
				rawurldecode( substr( $notice, 5 ) )
			);
		}
		return '';
	}
```

- [ ] **Step 2: Register the admin hooks in the bootstrap**

In `autokeywordsai.php`, inside `akai_bootstrap()`, after `AKAI_Queue::register();`:

```php
	if ( is_admin() ) {
		AKAI_Settings::register_admin();
	}
```

- [ ] **Step 3: Run the tests to confirm nothing regressed**

Run: `php tests/run.php`
Expected: PASS — same assertion count as after Task 8, `0 failures`. (The admin UI is
verified by hand in the next step; it is markup and WordPress calls, not logic worth
stubbing.)

- [ ] **Step 4: Manual verification in wp-admin**

On the local WooCommerce install, confirm each of these:

1. **Products → AutoKeywordsAI** loads and shows the missing-keyword count.
2. Saving with the key field blank keeps the stored key (save twice, then use **Test
   connection** — it must still succeed).
3. **Test connection** returns a comma-separated keyword string for the sample product.
4. With no key configured, both buttons render disabled.
5. **Generate missing keywords** reports the queued count, and actions appear under
   **WooCommerce → Status → Scheduled Actions** in group `autokeywordsai`.
6. Visiting the page as an Editor (no `manage_woocommerce`) is refused.

- [ ] **Step 5: Commit**

```bash
git add includes/class-akai-settings.php autokeywordsai.php
git commit -m "feat: add settings page with bulk run, test connection and error list"
```

---

### Task 10: CI, coding standards, and release packaging

**Files:**
- Create: `composer.json`
- Create: `.phpcs.xml.dist`
- Create: `bin/build-zip.sh`
- Create: `.github/workflows/ci.yml`
- Create: `.github/workflows/release.yml`

**Interfaces:**
- Consumes: `tests/run.php` from Task 1.
- Produces: `composer.json` with dev-only `wp-coding-standards/wpcs`; `bin/build-zip.sh` writing `build/autokeywordsai.zip` whose contents are nested under an `autokeywordsai/` directory.

- [ ] **Step 1: Create `composer.json`**

```json
{
  "name": "javierblanco/autokeywordsai",
  "description": "Fills empty Rank Math focus keywords on WooCommerce products using a configurable AI provider.",
  "type": "wordpress-plugin",
  "license": "GPL-2.0-or-later",
  "require": {
    "php": ">=8.1"
  },
  "require-dev": {
    "wp-coding-standards/wpcs": "^3.1",
    "dealerdirect/phpcodesniffer-composer-installer": "^1.0"
  },
  "config": {
    "allow-plugins": {
      "dealerdirect/phpcodesniffer-composer-installer": true
    }
  },
  "scripts": {
    "test": "php tests/run.php",
    "lint": "phpcs"
  }
}
```

- [ ] **Step 2: Create `.phpcs.xml.dist`**

```xml
<?xml version="1.0"?>
<ruleset name="AutoKeywordsAI">
	<description>WordPress Coding Standards for AutoKeywordsAI.</description>

	<file>.</file>
	<exclude-pattern>/vendor/*</exclude-pattern>
	<exclude-pattern>/build/*</exclude-pattern>
	<exclude-pattern>/tests/*</exclude-pattern>

	<arg name="extensions" value="php"/>
	<arg value="ps"/>

	<rule ref="WordPress">
		<!-- The plugin ships no build step, so short array syntax rules stay as WP defaults. -->
	</rule>

	<config name="minimum_wp_version" value="6.4"/>

	<rule ref="WordPress.WP.I18n">
		<properties>
			<property name="text_domain" type="array">
				<element value="autokeywordsai"/>
			</property>
		</properties>
	</rule>

	<rule ref="WordPress.NamingConventions.PrefixAllGlobals">
		<properties>
			<property name="prefixes" type="array">
				<element value="akai"/>
				<element value="AKAI"/>
			</property>
		</properties>
	</rule>
</ruleset>
```

- [ ] **Step 3: Create `bin/build-zip.sh`**

```bash
#!/usr/bin/env bash
#
# Builds an installable plugin zip at build/autokeywordsai.zip.
#
# WordPress expects the zip to contain a single top-level directory matching the plugin
# slug, so the files are staged under build/autokeywordsai/ before zipping.

set -euo pipefail

SLUG="autokeywordsai"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BUILD="$ROOT/build"
STAGE="$BUILD/$SLUG"

rm -rf "$BUILD"
mkdir -p "$STAGE"

cp "$ROOT/$SLUG.php" "$STAGE/"
cp "$ROOT/README.md" "$STAGE/"
cp -R "$ROOT/includes" "$STAGE/"

cd "$BUILD"
zip -qr "$SLUG.zip" "$SLUG"

echo "built $BUILD/$SLUG.zip"
```

Then make it executable:

```bash
chmod +x bin/build-zip.sh
```

- [ ] **Step 4: Verify the zip builds and contains the right layout**

Run: `bin/build-zip.sh && unzip -l build/autokeywordsai.zip`
Expected: every path begins with `autokeywordsai/`, `autokeywordsai/autokeywordsai.php` is
present, `includes/` is present, and no `tests/`, `vendor/` or `bin/` entries appear.

- [ ] **Step 5: Create the CI workflow**

Create `.github/workflows/ci.yml`:

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:
  workflow_dispatch: {}

jobs:
  tests:
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        php: ["8.1", "8.2", "8.3"]
    steps:
      - uses: actions/checkout@v4

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          coverage: none

      - name: Run unit tests
        run: php tests/run.php

  lint:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: "8.3"
          coverage: none
          tools: composer

      - name: Install dev dependencies
        run: composer install --no-interaction --no-progress

      - name: Check coding standards
        run: vendor/bin/phpcs
```

- [ ] **Step 6: Create the release workflow**

Create `.github/workflows/release.yml`:

```yaml
name: Release

on:
  push:
    tags: ["v*"]

permissions:
  contents: write

jobs:
  zip:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Build the plugin zip
        run: bin/build-zip.sh

      - name: Attach the zip to the release
        uses: softprops/action-gh-release@v2
        with:
          files: build/autokeywordsai.zip
```

- [ ] **Step 7: Run the full check locally**

```bash
php tests/run.php
composer install --no-interaction
vendor/bin/phpcs
```

Expected: tests report `0 failures`; PHPCS reports no errors. Fix any PHPCS findings in the
plugin files before committing — spacing and docblock findings are the usual ones.

- [ ] **Step 8: Commit**

```bash
git add composer.json .phpcs.xml.dist bin/build-zip.sh .github/workflows/
git commit -m "ci: add tests, coding standards and release packaging"
```

---

## Deviations from the spec

- The spec described the status area as "pending and failed counts read from Action
  Scheduler". Task 9 instead shows the **missing-keyword count**, a boolean **run in
  progress** flag from `as_has_scheduled_action()`, and the recent-error table. Action
  Scheduler has no cheap public API for a per-group failed count, and the missing-keyword
  count is the number the owner actually acts on. WooCommerce → Status → Scheduled Actions
  remains the place to see per-action state.
- The spec left provider wire formats unfrozen pending verification. Verified 2026-08-10:
  Gemini targets the **Interactions API** (Google's recommended path for new development;
  `generateContent` still works but is no longer recommended), and the OpenAI-compatible
  provider deliberately stays on **`/chat/completions`** rather than OpenAI's newer
  Responses API, because chat/completions is the format the third-party endpoints implement.
- Added to the design: providers accept an **injectable HTTP callable**, so every provider
  test runs against fixtures with no network.

## Deferred to a future plan

These were consciously excluded from this plan and should not be built as part of it:

- `rank_math_title` and `rank_math_description` generation.
- A review/approval queue before writing.
- Regenerating or overwriting existing keywords.
- A Yoast writer.
- wordpress.org submission assets (`readme.txt`, screenshots, review-guideline conformance).
