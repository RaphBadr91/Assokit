<?php
namespace AIOSEO\Plugin\Pro\Standalone\Blocks;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Standalone\Blocks as CommonBlocks;

/**
 * Recipe Block.
 *
 * @since 4.9.0
 */
class Recipe extends CommonBlocks\Blocks {
	/**
	 * Register the block.
	 *
	 * @since 4.9.0
	 *
	 * @return void
	 */
	public function register() {
		aioseo()->blocks->registerBlock( 'pro/recipe', [
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
		$image        = $attributes['image'] ?? '';
		$nutrition    = ! empty( $attributes['nutrition'] ) ? ( is_array( $attributes['nutrition'] ) ? $attributes['nutrition'] : json_decode( $attributes['nutrition'], true ) ) : '';
		$ingredients  = ! empty( $attributes['ingredients'] ) ? ( is_array( $attributes['ingredients'] ) ? $attributes['ingredients'] : json_decode( $attributes['ingredients'], true ) ) : [];
		$keywords     = ! empty( $attributes['keywords'] ) ? ( is_array( $attributes['keywords'] ) ? $attributes['keywords'] : json_decode( $attributes['keywords'], true ) ) : '';
		$timeRequired = ! empty( $attributes['timeRequired'] ) ? ( is_array( $attributes['timeRequired'] ) ? $attributes['timeRequired'] : json_decode( $attributes['timeRequired'], true ) ) : [];
		$instructions = ! empty( $attributes['instructions'] ) ? ( is_array( $attributes['instructions'] ) ? $attributes['instructions'] : json_decode( $attributes['instructions'], true ) ) : [];
		$rating       = ! empty( $attributes['rating'] ) ? ( is_array( $attributes['rating'] ) ? $attributes['rating'] : json_decode( $attributes['rating'], true ) ) : [];
		$reviews      = ! empty( $attributes['reviews'] ) ? ( is_array( $attributes['reviews'] ) ? $attributes['reviews'] : json_decode( $attributes['reviews'], true ) ) : [];
		$dishType     = $attributes['dishType'] ?? '';
		$cuisineType  = $attributes['cuisineType'] ?? '';
		$maximum      = ! empty( $rating['maximum'] ) ? $rating['maximum'] : 5;

		$result = '<div class="aioseo-recipe-block">';

		if ( ! empty( $name ) || ! empty( $description ) || ! empty( $image ) ) {
				$result .= '<div class="aioseo-recipe-header-area">
					<div>
						<h2>' . esc_html( aioseo()->tags->replaceTags( $name ) ) . '</h2>';

			if ( ! empty( $description ) ) {
				$result .= '<div class="aioseo-recipe-description">
					<p>' . esc_html( aioseo()->tags->replaceTags( $description ) ) . '</p>
				</div>';
			}

			$result .= '</div>';

			if ( ! empty( $image ) ) {
				$result .= '<div class="aioseo-recipe-image">
					<img src="' . esc_url( $image ) . '" alt="' . esc_attr( $name ) . '" />
				</div>';
			}

			$result .= '</div>';
		}

		if (
			! empty( $nutrition['servings'] ) ||
			! empty( $timeRequired['preparation'] ) ||
			! empty( $timeRequired['cooking'] ) ||
			! empty( $nutrition['calories'] ) ||
			! empty( $cuisineType ) ||
			! empty( $dishType )
		) {
			$result .= '<div class="aioseo-recipe-general-info">
						<div class="aioseo-recipe-general-info__row">';

			if ( ! empty( $nutrition['servings'] ) ) {
				$result .= '<div class="aioseo-recipe-general-info__row-item">
					<span aria-label="Serves">' . __( 'Serves', 'aioseo-pro' ) . '</span>
					<span class="value">' . esc_html( $nutrition['servings'] ) . '</span>
				</div>';
			}

			if ( ! empty( $timeRequired['preparation'] ) ) {
				$result .= '<div class="aioseo-recipe-general-info__row-item">
					<span aria-label="' . __( 'Preparation Time', 'aioseo-pro' ) . '">' . __( 'Prep Time', 'aioseo-pro' ) . '</span>
					<span class="value">' . esc_html( $timeRequired['preparation'] ) . ' ' . __( 'Minutes', 'aioseo-pro' ) . '</span>
				</div>';
			}

			if ( ! empty( $timeRequired['cooking'] ) ) {
				$result .= '<div class="aioseo-recipe-general-info__row-item">
					<span aria-label="' . __( 'Cooking Time', 'aioseo-pro' ) . '">' . __( 'Cooks in', 'aioseo-pro' ) . '</span>
					<span class="value">' . esc_html( $timeRequired['cooking'] ) . ' ' . __( 'Minutes', 'aioseo-pro' ) . '</span>
				</div>';
			}

			$result .= '</div>';

			$result .= '<div class="aioseo-recipe-general-info__row">';

			if ( ! empty( $nutrition['calories'] ) ) {
				$result .= '<div class="aioseo-recipe-general-info__row-item">
					<span aria-label="' . __( 'Calories', 'aioseo-pro' ) . '">' . __( 'Calories', 'aioseo-pro' ) . '</span>
					<span class="value">' . esc_html( $nutrition['calories'] ) . '</span>
				</div>';
			}

			if ( ! empty( $cuisineType ) ) {
				$result .= ' <div class="aioseo-recipe-general-info__row-item">
					<span aria-label="' . __( 'Cuisine', 'aioseo-pro' ) . '">' . __( 'Cuisine', 'aioseo-pro' ) . '</span>
					<span class="value">' . esc_html( $cuisineType ) . '</span>
				</div>';
			}

			if ( ! empty( $dishType ) ) {
				$result .= '<div class="aioseo-recipe-general-info__row-item">
					<span aria-label="' . __( 'Dish type', 'aioseo-pro' ) . '">' . __( 'Dish type', 'aioseo-pro' ) . '</span>
					<span class="value">' . esc_html( $dishType ) . '</span>
				</div>';
			}

				$result .= '</div>
			</div>';
		}

		if ( ! empty( $keywords ) ) {
			$result .= '<div class="aioseo-recipe-tags">
				<span class="screen-reader-text">' . __( 'Recipe Tags', 'aioseo-pro' ) . ': </span>';

			foreach ( $keywords as $keyword ) {
				$result .= '<span>' . esc_html( $keyword['label'] ) . '</span>';
			}

			$result .= '</div>';
		}

		if ( ! empty( $ingredients ) ) {
			$result .= '<div class="aioseo-recipe-ingredients">
				<h3>' . __( 'Ingredients', 'aioseo-pro' ) . '</h3>
				<ul>';

			foreach ( $ingredients as $ingredient ) {
				$result .= '<li><span>' . esc_html( $ingredient['label'] ) . '</span></li>';
			}

			$result .= '</ul>
			</div>';
		}

		$filteredInstructions = empty( $instructions ) ? [] : array_filter( $instructions, function ( $instruction ) {
			return ! empty( $instruction['name'] ) || ! empty( $instruction['text'] ) || ! empty( $instruction['image'] );
		} );
		if ( ! empty( $filteredInstructions ) ) {
			$result .= '<div class="aioseo-recipe-instructions">
				<h3>' . __( 'Instructions', 'aioseo-pro' ) . '</h3>
				<ol>';

			foreach ( $filteredInstructions as $instruction ) {
				$result .= '<li>
					<h4>' . esc_html( $instruction['name'] ) . '</h4>
					<p>' . esc_html( $instruction['text'] ) . '</p>';
				if ( ! empty( $instruction['image'] ) ) {
					$result .= '<div><img src="' . esc_url( $instruction['image'] ) . '" alt="' . esc_attr( $instruction['name'] ) . '" /></div>';
				}

				$result .= '</li>';
			}

			$result .= '</ol>
			</div>';
		}

		if ( ! empty( $reviews ) ) {
			$result .= '<div class="aioseo-recipe-reviews">
				<span class="screen-reader-text">' . __( 'Reviews', 'aioseo-pro' ) . ': </span>';

			$filteredReviews = array_filter($reviews, function ( $review ) {
				return ! empty( $review['headline'] ) && ! empty( $review['rating'] ) && ! empty( $review['author'] );
			});
			foreach ( $filteredReviews as $review ) {
				$result .= '<div class="review">
					<div class="content">
						<h3 class="headline">
							<span class="screen-reader-text">' . __( 'Review Headline', 'aioseo-pro' ) . ': </span>
							' . esc_html( $review['headline'] ) . '
						</h3>

						<div class="description">
							<span class="screen-reader-text">' . __( 'Review Description', 'aioseo-pro' ) . ': </span>
							' . esc_html( $review['content'] ) . '
						</div>
					</div>

					<div class="author-rating">
						<div class="author">
							<span class="screen-reader-text">' . __( 'Reviewed by', 'aioseo-pro' ) . ': </span>
							- ' . esc_html( $review['author'] ) . '
						</div>

						<div class="rating">
							<span class="screen-reader-text">' . __( 'Total Rating', 'aioseo-pro' ) . ': '
							. esc_html( $review['rating'] ) . ' ' . __( 'out of ', 'aioseo-pro' ) . ' ' . esc_html( $maximum ) . '</span>';

				foreach ( range( 1, $maximum ) as $i ) { //phpcs:ignore
					$result .= '<span class="' . ( $i <= $review['rating'] ? 'filled' : 'empty' ) . '">' . __( '★', 'aioseo-pro' ) . '</span>';
				}

						$result .= '</div>
					</div>
				</div>';
			}

			$result .= '</div>';
		}

		$result .= '</div>';

		return $result;
	}
}