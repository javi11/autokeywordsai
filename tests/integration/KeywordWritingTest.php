<?php
/**
 * Integration tests for the parts of the plugin that need a real WordPress.
 *
 * The pure logic — prompt building, provider parsing, keyword cleaning — is
 * covered by the stub-based unit suite. What cannot be covered there is the
 * plugin's contact with the database: writing Rank Math's focus keyword meta on
 * a real product, and finding the products that still need one.
 *
 * @package autokeywordsai
 */

/**
 * @covers AKAI_Keyword_Writer
 * @covers AKAI_Queue
 */
final class KeywordWritingTest extends WP_UnitTestCase {

	/**
	 * Creates a published product.
	 *
	 * @param string $title   Product title.
	 * @param string $keyword Focus keyword to seed, or '' for none at all.
	 * @return int Product post ID.
	 */
	private function make_product( string $title = 'Camiseta azul', string $keyword = '' ): int {
		$product_id = $this->factory->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);

		if ( '' !== $keyword ) {
			update_post_meta( $product_id, AKAI_Keyword_Writer::META_KEY, $keyword );
		}

		return $product_id;
	}

	/**
	 * The whole suite is meaningless if the plugin never loaded.
	 *
	 * akai_bootstrap() bails without loading any class when WooCommerce or Rank
	 * Math is missing, so without this assertion a broken environment would look
	 * like a passing test run.
	 */
	public function test_the_plugin_actually_booted_with_both_dependencies() {
		$this->assertTrue( class_exists( 'WooCommerce' ), 'WooCommerce is not active.' );
		$this->assertTrue( class_exists( 'RankMath' ), 'Rank Math is not active.' );
		$this->assertSame( array(), akai_dependencies_missing() );
		$this->assertTrue( class_exists( 'AKAI_Keyword_Writer' ), 'The plugin did not load its classes.' );
		$this->assertTrue( class_exists( 'AKAI_Queue' ), 'The plugin did not load its classes.' );
	}

	public function test_the_product_post_type_is_registered() {
		// AKAI_Queue::missing_keyword_product_ids() queries post_type=product,
		// which only exists because WooCommerce registered it.
		$this->assertTrue( post_type_exists( 'product' ) );
	}

	public function test_writing_stores_the_keyword_on_the_product() {
		$product_id = $this->make_product();

		$written = AKAI_Keyword_Writer::write(
			$product_id,
			array(
				'primary'   => 'camiseta azul',
				'secondary' => array( 'camiseta de algodón' ),
			)
		);

		$this->assertTrue( $written );
		$this->assertSame(
			'camiseta azul, camiseta de algodón',
			get_post_meta( $product_id, AKAI_Keyword_Writer::META_KEY, true )
		);
	}

	public function test_writing_never_overwrites_an_existing_keyword() {
		$product_id = $this->make_product( 'Camiseta azul', 'palabra existente' );

		$written = AKAI_Keyword_Writer::write(
			$product_id,
			array(
				'primary'   => 'camiseta azul',
				'secondary' => array(),
			)
		);

		$this->assertFalse( $written );
		$this->assertSame(
			'palabra existente',
			get_post_meta( $product_id, AKAI_Keyword_Writer::META_KEY, true )
		);
	}

	public function test_writing_an_unusable_result_leaves_the_product_untouched() {
		$product_id = $this->make_product();

		$written = AKAI_Keyword_Writer::write(
			$product_id,
			array(
				'primary'   => '',
				'secondary' => array( 'algo' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $written );
		$this->assertSame( '', get_post_meta( $product_id, AKAI_Keyword_Writer::META_KEY, true ) );
	}

	public function test_products_with_no_keyword_meta_are_queued() {
		$product_id = $this->make_product();

		$this->assertContains( $product_id, AKAI_Queue::missing_keyword_product_ids() );
	}

	public function test_products_with_an_empty_keyword_are_queued() {
		$product_id = $this->make_product();
		update_post_meta( $product_id, AKAI_Keyword_Writer::META_KEY, '' );

		$this->assertContains( $product_id, AKAI_Queue::missing_keyword_product_ids() );
	}

	public function test_products_that_already_have_a_keyword_are_left_alone() {
		$done_id    = $this->make_product( 'Con palabra', 'ya tiene' );
		$pending_id = $this->make_product( 'Sin palabra' );

		$ids = AKAI_Queue::missing_keyword_product_ids();

		$this->assertNotContains( $done_id, $ids );
		$this->assertContains( $pending_id, $ids );
	}

	public function test_unpublished_products_are_not_queued() {
		$draft_id = $this->factory->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => 'draft',
				'post_title'  => 'Borrador',
			)
		);

		$this->assertNotContains( $draft_id, AKAI_Queue::missing_keyword_product_ids() );
	}

	public function test_ordinary_posts_are_never_queued() {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Una entrada',
			)
		);

		$this->assertNotContains( $post_id, AKAI_Queue::missing_keyword_product_ids() );
	}

	public function test_a_written_keyword_takes_the_product_out_of_the_queue() {
		$product_id = $this->make_product();

		$this->assertContains( $product_id, AKAI_Queue::missing_keyword_product_ids() );

		AKAI_Keyword_Writer::write(
			$product_id,
			array(
				'primary'   => 'camiseta azul',
				'secondary' => array(),
			)
		);

		$this->assertNotContains( $product_id, AKAI_Queue::missing_keyword_product_ids() );
	}
}
