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
	 * Settings page slug.
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
	 * The AKAI_API_KEY constant value, or null when undefined or blank.
	 *
	 * Public so every caller resolves the constant the same way.
	 *
	 * @return string|null
	 */
	public static function constant_key(): ?string {
		if ( ! defined( 'AKAI_API_KEY' ) ) {
			return null;
		}
		$value = (string) constant( 'AKAI_API_KEY' );
		return '' !== trim( $value ) ? $value : null;
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
			$input = isset( $_POST['akai'] ) ? wp_unslash( (array) $_POST['akai'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
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
		$provider = AKAI_Provider_Factory::make( $settings, self::constant_key() );

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

		$settings   = self::get();
		$key_locked = null !== self::constant_key();
		$has_key    = '' !== trim( self::resolve_api_key( $settings, self::constant_key() ) );
		$pending    = function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( AKAI_Queue::HOOK, null, AKAI_Queue::GROUP );
		$missing    = count( AKAI_Queue::missing_keyword_product_ids() );
		$errors     = AKAI_Logger::recent_errors();
		$notice     = isset( $_GET['akai_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['akai_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
								<a href="<?php echo esc_url( (string) get_edit_post_link( (int) $entry['product_id'] ) ); ?>">
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
}
