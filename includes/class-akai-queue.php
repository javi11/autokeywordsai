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
		$api_key  = AKAI_Settings::resolve_api_key( $settings, self::constant_key() );

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
		$provider = AKAI_Provider_Factory::make( $settings, self::constant_key() );

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
	 * The AKAI_API_KEY constant value, or null when undefined.
	 *
	 * @return string|null
	 */
	private static function constant_key(): ?string {
		return defined( 'AKAI_API_KEY' ) ? (string) constant( 'AKAI_API_KEY' ) : null;
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
