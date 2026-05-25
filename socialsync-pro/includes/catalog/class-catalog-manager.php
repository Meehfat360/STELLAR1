<?php
/**
 * SocialSync Pro - Catalog Manager
 *
 * @package SSP\Catalog
 */

namespace SSP\Catalog;

use SSP\Utils\Logger;

/**
 * Catalog Manager - manages WooCommerce product synchronization
 *
 * @class Catalog_Manager
 */
class Catalog_Manager {

	/**
	 * Sync all published WooCommerce products to catalog
	 *
	 * @return int Number of synced products.
	 */
	public function sync_catalog() {
		global $wpdb;

		try {
			// Query all published WC products
			$args = [
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			];

			$product_ids = get_posts( $args );

			$synced_count = 0;

			foreach ( $product_ids as $product_id ) {
				$product = wc_get_product( $product_id );

				if ( ! $product ) {
					continue;
				}

				// Extract product data
				$data = [
					'product_id'  => $product_id,
					'name'        => $product->get_name(),
					'price'       => $product->get_price(),
					'image_url'   => $this->get_product_image_url( $product ),
					'permalink'   => $product->get_permalink(),
					'is_featured' => $product->get_featured() ? 1 : 0,
					'total_sales' => $product->get_total_sales() ?: 0,
					'synced_at'   => current_time( 'mysql' ),
				];

				// Upsert into catalog
				$wpdb->replace(
					"{$wpdb->prefix}ssp_catalog",
					$data,
					[ '%d', '%s', '%f', '%s', '%s', '%d', '%d', '%s' ]
				);

				$synced_count++;
			}

			Logger::log( 'info', 'catalog_sync', "Synced {$synced_count} products to catalog" );

			return $synced_count;

		} catch ( \Exception $e ) {
			Logger::log( 'error', 'catalog_sync', 'Failed to sync catalog: ' . $e->getMessage() );
			return 0;
		}
	}

	/**
	 * Get featured products from catalog
	 *
	 * @param int $limit Number of products to retrieve.
	 *
	 * @return array Array of featured products.
	 */
	public function get_featured_products( $limit = 5 ) {
		global $wpdb;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}ssp_catalog WHERE is_featured = 1 ORDER BY synced_at DESC LIMIT %d",
				$limit
			)
		);

		return $results ?: [];
	}

	/**
	 * Get best-selling products from catalog
	 *
	 * @param int $limit Number of products to retrieve.
	 *
	 * @return array Array of best-selling products.
	 */
	public function get_best_sellers( $limit = 5 ) {
		global $wpdb;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}ssp_catalog ORDER BY total_sales DESC LIMIT %d",
				$limit
			)
		);

		return $results ?: [];
	}

	/**
	 * Get daily picks - combination of featured and best sellers
	 *
	 * @return array Array of combined products with pick_reason.
	 */
	public function get_daily_picks() {
		$featured     = $this->get_featured_products( 3 );
		$best_sellers = $this->get_best_sellers( 2 );

		// Add pick reason
		foreach ( $featured as $product ) {
			$product->pick_reason = 'featured';
		}

		foreach ( $best_sellers as $product ) {
			$product->pick_reason = 'best_seller';
		}

		// Merge and deduplicate by product_id
		$all_picks = array_merge( $featured, $best_sellers );
		$seen_ids  = [];
		$deduped   = [];

		foreach ( $all_picks as $product ) {
			if ( ! isset( $seen_ids[ $product->product_id ] ) ) {
				$seen_ids[ $product->product_id ] = true;
				$deduped[]                         = $product;
			}
		}

		return $deduped;
	}

	/**
	 * Get product image URL
	 *
	 * @param \WC_Product $product WooCommerce product object.
	 *
	 * @return string Product image URL or empty string.
	 */
	private function get_product_image_url( $product ) {
		$image_id = $product->get_image_id();

		if ( $image_id ) {
			$image_url = wp_get_attachment_image_url( $image_id, 'full' );
			return $image_url ?: '';
		}

		return '';
	}
}
