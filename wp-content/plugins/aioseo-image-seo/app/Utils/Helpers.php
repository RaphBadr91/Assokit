<?php
namespace AIOSEO\Plugin\Addon\ImageSeo\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contains helper functions for the Image SEO addon.
 *
 * @since 1.1.0
 */
class Helpers {
	/**
	 * Strips punctuation from the given image attribute value.
	 *
	 * @since 1.1.0
	 * @version 1.1.17 Added the $keepSpaces parameter.
	 *
	 * @param  string $string        The string.
	 * @param  string $attributeName The attribute name.
	 * @param  bool   $keepSpaces    Whether to keep spaces.
	 * @return string                The string value with punctuation replaced.
	 */
	public function stripPunctuation( $string, $attributeName, $keepSpaces = false ) {
		$charactersToConvert = $this->getCharactersToConvert( $attributeName );

		// Split the string into text and html tags.
		$textArray = preg_split( '/(<[^>]+>)|(<\/[^>]+>)/i', $string, -1, PREG_SPLIT_DELIM_CAPTURE );
		foreach ( $textArray as &$text ) {
			// If the string doesn't contain a html tag, strip punctuation.
			if ( 0 !== stripos( $text, '<' ) ) {
				// Convert the specified characters to spaces only if not within an html tag.
				if ( ! empty( $charactersToConvert ) && 'filename' !== $attributeName ) {
					$pattern = implode( '|', $charactersToConvert );
					$text  = preg_replace( "/({$pattern})/i", ' ', (string) $text );
				}
				$text = aioseo()->helpers->stripPunctuation( $text, $this->getCharactersToKeep( $attributeName ), $keepSpaces );
			}
		}

		$string = implode( '', $textArray );

		return $keepSpaces ? $string : preg_replace( '/[\s]+/u', ' ', trim( $string ) );
	}

	/**
	 * Returns the characters that shouldn't be stripped for a specific image attribute.
	 *
	 * @since 1.1.0
	 *
	 * @param  string $attributeName The image attribute name.
	 * @return array                 List of characters to keep.
	 */
	private function getCharactersToKeep( $attributeName ) {
		static $charactersToKeep = [];

		if ( isset( $charactersToKeep[ $attributeName ] ) ) {
			return $charactersToKeep[ $attributeName ];
		}

		$options = aioseo()->options->image->{$attributeName}->charactersToKeep->all();

		$mappedOptions = [
			'numbers'     => '\d',
			'apostrophe'  => "'",
			'ampersand'   => '&',
			'underscores' => '_',
			'plus'        => '+',
			'pound'       => '#',
			'dashes'      => '-',
		];

		foreach ( $options as $k => $enabled ) {
			if ( ! $enabled ) {
				unset( $mappedOptions[ $k ] );
			}
		}

		$charactersToKeep[ $attributeName ] = $mappedOptions;

		return $charactersToKeep[ $attributeName ];
	}

	/**
	 * Returns the characters that should be converted to spaces.
	 *
	 * @since 1.1.0
	 *
	 * @param  string $attributeName The image attribute name.
	 * @return array                 List of characters to convert to spaces.
	 */
	private function getCharactersToConvert( $attributeName ) {
		static $charactersToConvert = [
			'filename' => []
		];

		if ( isset( $charactersToConvert[ $attributeName ] ) ) {
			return $charactersToConvert[ $attributeName ];
		}

		if ( ! aioseo()->options->image->{$attributeName}->has( 'charactersToConvert' ) ) {
			return [];
		}

		$options = aioseo()->options->image->{$attributeName}->charactersToConvert->all();

		$mappedOptions = [
			'underscores' => '_',
			'dashes'      => '-',
		];

		foreach ( $options as $k => $enabled ) {
			if ( ! $enabled ) {
				unset( $mappedOptions[ $k ] );
			}
		}

		$charactersToConvert[ $attributeName ] = $mappedOptions;

		return $charactersToConvert[ $attributeName ];
	}
}