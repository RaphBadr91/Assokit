<?php
namespace AIOSEO\Plugin\Pro\Standalone\Blocks;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Standalone\Blocks as CommonBlocks;

/**
 * Product Block.
 *
 * @since 4.9.0
 */
class Product extends CommonBlocks\Blocks {
	/**
	 * Register the block.
	 *
	 * @since 4.9.0
	 *
	 * @return void
	 */
	public function register() {
		aioseo()->blocks->registerBlock( 'pro/product', [
			'render_callback' => [ $this, 'render' ]
		] );
	}

	/**
	 * Render the block.
	 *
	 * @since 4.9.0
	 *
	 * @param array $attributes The attributes for the block.
	 *
	 * @return string
	 */
	public function render( $attributes ) {
		$name         = $attributes['name'] ?? '';
		$description  = $attributes['description'] ?? '';
		$brand        = $attributes['brand'] ?? '';
		$image        = $attributes['image'] ?? '';
		$identifiers  = ! empty( $attributes['identifiers'] ) ? ( is_array( $attributes['identifiers'] ) ? $attributes['identifiers'] : json_decode( $attributes['identifiers'], true ) ) : [];
		$offer        = ! empty( $attributes['offer'] ) ? ( is_array( $attributes['offer'] ) ? $attributes['offer'] : json_decode( $attributes['offer'], true ) ) : [];
		$rating       = ! empty( $attributes['rating'] ) ? ( is_array( $attributes['rating'] ) ? $attributes['rating'] : json_decode( $attributes['rating'], true ) ) : [];
		$reviews      = ! empty( $attributes['reviews'] ) ? ( is_array( $attributes['reviews'] ) ? $attributes['reviews'] : json_decode( $attributes['reviews'], true ) ) : [];
		$availability = ! empty( $offer['availability'] ) ? substr( $offer['availability'], strrpos( $offer['availability'], '/' ) + 1 ) : '';
		$maximum      = ! empty( $rating['maximum'] ) ? $rating['maximum'] : 5;
		$price        = ! empty( $offer['price'] ) ? number_format_i18n( $offer['price'], 2 ) : '';
		$price        = ! empty( $price ) ? preg_replace( '/[^0-9.]/', '', $price ) : '';
		$salePrice    = ! empty( $offer['salePrice'] ) ? number_format_i18n( $offer['salePrice'], 2 ) : '';
		$salePrice    = ! empty( $salePrice ) ? preg_replace( '/[^0-9.]/', '', $salePrice ) : '';

		$numberFormatter = new \NumberFormatter( 'en-US', \NumberFormatter::CURRENCY );
		$price           = ! empty( $price ) ? $numberFormatter->formatCurrency( (float) $price, ! empty( $offer['currency'] ) ? $offer['currency'] : 'USD' ) : '';
		$salePrice       = ! empty( $salePrice ) ? $numberFormatter->formatCurrency( (float) $salePrice, ! empty( $offer['currency'] ) ? $offer['currency'] : 'USD' ) : '';

		$availabilityMap = [
			'https://schema.org/InStock'             => __( 'In Stock', 'aioseo-pro' ),
			'https://schema.org/OutOfStock'          => __( 'Out of Stock', 'aioseo-pro' ),
			'https://schema.org/SoldOut'             => __( 'Sold Out', 'aioseo-pro' ),
			'https://schema.org/PreOrder'            => __( 'Pre-order', 'aioseo-pro' ),
			'https://schema.org/Discontinued'        => __( 'Discontinued', 'aioseo-pro' ),
			'https://schema.org/LimitedAvailability' => __( 'Limited Availability', 'aioseo-pro' ),
			'https://schema.org/OnlineOnly'          => __( 'Online Only', 'aioseo-pro' ),
			'https://schema.org/InStoreOnly'         => __( 'In Store Only', 'aioseo-pro' )
		];

		$formattedAvailability = ! empty( $offer['availability'] ) ? $availabilityMap[ $offer['availability'] ] : '';

		$result = '<div class="aioseo-product-block">';
		if (
			! empty( $brand ) ||
			! empty( $name ) ||
			! empty( $identifiers['sku'] ) ||
			! empty( $description ) ||
			! empty( $price ) ||
			! empty( $salePrice ) ||
			! empty( $availability ) ||
			! empty( $image )
		) {
			$result .= '<div class="aioseo-product-header">
				<div>
					<h4 class="brand-name">' . esc_html( $brand ) . '</h4>

					<h2 class="product-name">
						<span class="screen-reader-text">' . __( 'Product Name', 'aioseo-pro' ) . ':</span>
						' . esc_html( aioseo()->tags->replaceTags( $name ) ) . '
					</h2>';

			if ( ! empty( $identifiers['sku'] ) ) {
				$result .= '<p class="product-sku">' . __( 'SKU', 'aioseo-pro' ) . ': ' . esc_html( $identifiers['sku'] ) . '</p>';
			}

			if ( ! empty( $description ) ) {
				$result .= '<div class="product-description">' . esc_html( aioseo()->tags->replaceTags( trim( $description ) ) ) . '</div>';
			}

			$result .= '<div class="offer-price">';

			if ( ! empty( $price ) || ! empty( $salePrice ) ) {
				$result .= '<div class="offer-prices-container">';
				if ( ! empty( $price ) ) {
					$result .= '<div class="' . ( ! empty( $salePrice ) ? 'price has-sale-price' : 'price' ) . '">
						<span class="screen-reader-text">' . __( 'Price', 'aioseo-pro' ) . ':</span>
						' . esc_html( $price ) . '
					</div>';
				}

				if ( ! empty( $salePrice ) ) {
					$result .= '<div class="sale-price">
						<span class="screen-reader-text">' . __( 'Sale Price', 'aioseo-pro' ) . ':</span>
						' . esc_html( $salePrice ) . '
					</div>';
				}

				$result .= '</div>';
			}

			if ( ! empty( $availability ) ) {
				$result .= '<div class="availability">
					<span class="screen-reader-text">' . __( 'Stock Availability', 'aioseo-pro' ) . ':</span>
					<span class="color ' . esc_html( $availability ) . '"></span>
					<span class="text">' . esc_html( $formattedAvailability ) . '</span>
				</div>';
			}

			$result .= '</div></div>';

			if ( ! empty( $image ) ) {
				$result .= '<div>
					<img src="' . esc_url( $image ) . '" alt="' . esc_attr( aioseo()->tags->replaceTags( $name ) ) . '" />
				</div>';
			}

			$result .= '</div>';
		}

		$filteredReviews = ! empty( $reviews ) ? array_filter( $reviews, function ( $review ) {
			return ! empty( $review['headline'] ) && ! empty( $review['rating'] ) && ! empty( $review['author'] );
		}) : [];
		if ( ! empty( $filteredReviews ) ) {
			$result .= '<div class="aioseo-product-reviews">
				<span class="screen-reader-text">' . __( 'Reviews', 'aioseo-pro' ) . ':</span>';

			foreach ( $filteredReviews as $review ) {
				$result .= '<div class="review">
					<div class="content">
						<h3 class="headline">
							<span class="screen-reader-text">' . __( 'Review Headline', 'aioseo-pro' ) . ':</span>
							' . esc_html( $review['headline'] ) . '
						</h3>

						<div class="description">
							<span class="screen-reader-text">' . __( 'Review Description', 'aioseo-pro' ) . ':</span>
							' . esc_html( $review['content'] ) . '
						</div>
					</div>

					<div class="author-rating">
						<div class="author">
							<span class="screen-reader-text">' . __( 'Reviewed by', 'aioseo-pro' ) . ':</span>
							- ' . esc_html( $review['author'] ) . '
						</div>';

				$result .= '<div class="rating">
					<span class="screen-reader-text">' . __( 'Total Rating', 'aioseo-pro' ) . ':
					' . esc_html( $review['rating'] ) . ' ' . __( 'out of ', 'aioseo-pro' ) . ' ' . esc_html( $maximum ) . '</span>';

				foreach ( range( 1, $maximum ) as $i ) { //phpcs:ignore
					$result .= '<span class="' . ( $i <= $review['rating'] ? 'filled' : 'empty' ) . '">' . __( '★', 'aioseo-pro' ) . '</span>';
				}

				$result .= '</div>';

				$result .= '</div>
				</div>';
			}

			$result .= '</div>';
		}

		$result .= '</div>';

		return $result;
	}
}