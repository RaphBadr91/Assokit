<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces tags with their respective values.
 *
 * @since 1.1.0
 */
class Tags {
	/**
	 * The tag values that we support.
	 *
	 * @since 1.1.0
	 *
	 * @var array
	 */
	private $tags = [];

	/**
	 * The contexts to separate tags.
	 *
	 * @since 1.1.0
	 *
	 * @var array
	 */
	private $context = [
		'addressFormat' => [
			'streetLineOne',
			'streetLineTwo',
			'zipCode',
			'city',
			'state',
			'country',
			'newLine'
		]
	];

	/**
	 * The address data object.
	 *
	 * @since 1.1.0
	 *
	 * @var object
	 */
	private $address;

	/**
	 * Class constructor.
	 *
	 * @since 1.1.0
	 */
	public function __construct() {
		add_action( 'init', [ $this, 'init' ] );
	}

	/**
	 * Init the tags later to allow filters to be registered and run.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function init() {
		aioseo()->tags->addContext( $this->context );

		$this->tags = [
			[
				'id'          => 'streetLineOne',
				'name'        => __( 'Address Line 1', 'aioseo-local-business' ),
				'description' => __( 'Your Address Line 1', 'aioseo-local-business' ),
				'context'     => [ 'addressFormat' ]
			],
			[
				'id'          => 'streetLineTwo',
				'name'        => __( 'Address Line 2', 'aioseo-local-business' ),
				'description' => __( 'Your Address Line 2', 'aioseo-local-business' ),
				'context'     => [ 'addressFormat' ]
			],
			[
				'id'          => 'zipCode',
				'name'        => __( 'Zip Code', 'aioseo-local-business' ),
				'description' => __( 'Your Zip Code', 'aioseo-local-business' ),
				'context'     => [ 'addressFormat' ]
			],
			[
				'id'          => 'city',
				'name'        => __( 'City', 'aioseo-local-business' ),
				'description' => __( 'Your City', 'aioseo-local-business' ),
				'context'     => [ 'addressFormat' ]
			],
			[
				'id'          => 'state',
				'name'        => __( 'State', 'aioseo-local-business' ),
				'description' => __( 'Your State', 'aioseo-local-business' ),
				'context'     => [ 'addressFormat' ]
			],
			[
				'id'          => 'country',
				'name'        => __( 'Country', 'aioseo-local-business' ),
				'description' => __( 'Your Country', 'aioseo-local-business' ),
				'context'     => [ 'addressFormat' ]
			]
		];

		$this->tags = apply_filters( 'aioseo_local_business_address_tags', $this->tags );

		aioseo()->tags->addTags( $this->tags );
	}

	/**
	 * Replace the tags in the string provided.
	 *
	 * @since 1.1.0
	 *
	 * @param  string $string        The string with tags.
	 * @param  int    $id            The page or post ID.
	 * @param  object $addressObject A data object to run the tags against.
	 * @return string                The string with tags replaced.
	 */
	public function replaceTags( $string, $id, $addressObject = null ) {
		if ( ! $string || ! preg_match( '/#/', (string) $string ) ) {
			return $string;
		}

		if ( $addressObject ) {
			$this->address = $addressObject;
		} else {
			if ( 'global' === $id ) {
				$this->address = aioseoLocalBusiness()->helpers->getLocalBusinessOptions();
			} else {
				$this->address = aioseoLocalBusiness()->locations->getLocation( $id );
			}

			if ( empty( $this->address->locations->business->address ) ) {
				return $string;
			}

			$this->address = $this->address->locations->business->address;
		}

		$lines = preg_split( '/\r\n|\n|\r/', (string) $string );

		foreach ( $lines as $lineKey => &$line ) {
			foreach ( $this->tags as $tag ) {
				$tagId   = aioseo()->tags->denotationChar . $tag['id'];
				$pattern = "/$tagId(?![a-zA-Z0-9_])/im";
				if ( preg_match( $pattern, (string) $line ) ) {
					$line = trim( preg_replace( $pattern, $this->getTagValue( $tag, $id ), (string) $line ) );
					if ( empty( $line ) ) {
						unset( $lines[ $lineKey ] );
					}
				}
			}
		}

		$string = implode( PHP_EOL, $lines );

		return aioseo()->tags->replaceTags( $string, $id );
	}

	/**
	 * Get the value of the tag to replace.
	 *
	 * @since 1.1.0
	 *
	 * @param  array  $tag The tag to look for.
	 * @param  int    $id  The page or post ID.
	 * @return string      The value of the tag.
	 */
	public function getTagValue( $tag, $id ) {
		$tagValue = '';

		if ( ! empty( $this->address->{$tag['id']} ) ) {
			$tagValue = $this->address->{$tag['id']};
			if ( 'country' === $tag['id'] ) {
				$tagValue = aioseo()->helpers->getCountryName( $this->address->{$tag['id']} );
			}
		}

		if ( empty( $tagValue ) ) {
			switch ( $tag['id'] ) {
				case 'streetLineOne':
					$tagValue = $this->address->streetLine1;
					break;
				case 'streetLineTwo':
					$tagValue = $this->address->streetLine2;
					break;
				default:
					$tagValue = aioseo()->tags->getTagValue( $tag, $id );
					break;
			}
		}

		return apply_filters( 'aioseo_local_business_address_tag_value', $tagValue, $tag, $id );
	}
}