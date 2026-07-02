<?php

namespace AIOSEO\Plugin\Pro\SeoAnalysis;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the HTML parser for the Seo Analysis.
 *
 * @since 4.8.6
 */
class PageParser {
	/**
	 * The DOM document instance.
	 *
	 * @since 4.8.6
	 *
	 * @var \DOMDocument
	 */
	private $document;

	/**
	 * The page URL/permalink.
	 *
	 * @since 4.8.6
	 *
	 * @var string
	 */
	private $url;

	/**
	 * The URL util instance.
	 *
	 * @since 4.8.6
	 *
	 * @var Utils\Url
	 */
	private $urlUtil;

	/**
	 * The content of the page.
	 *
	 * @since 4.8.6
	 *
	 * @var string
	 */
	private $content;

	/**
	 * Whether the page is being scraped.
	 *
	 * @since 4.8.6
	 *
	 * @var boolean
	 */
	public $isScraping;

	/**
	 * Class constructor.
	 *
	 * @since 4.8.6
	 *
	 * @param string $url Url of the page.
	 */
	public function __construct( $url, $content = null, $isScraping = true ) {
		$this->url        = $url;
		$this->content    = $content;
		$this->urlUtil    = new Utils\Url( trim( $this->url ) );
		$this->isScraping = $isScraping;

		$this->loadDocument();
	}

	/**
	 * Load the document parsing the HTML.
	 *
	 * @since 4.8.6
	 *
	 * @return void
	 */
	private function loadDocument() {
		if ( ! $this->isScraping && empty( $this->content ) ) {
			$this->document = null;

			return;
		}

		$this->document = new \DOMDocument();

		// Store the current libxml error handling state
		$previousUseInternalErrors = libxml_use_internal_errors( true );

		if ( ! $this->isScraping ) {
			@$this->document->loadHTML( $this->content );  // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			libxml_clear_errors();
			libxml_use_internal_errors( $previousUseInternalErrors );

			return;
		}

		// Use WordPress HTTP API
		$response = wp_remote_get($this->url, [
			'timeout'    => 10,
			'user-agent' => 'Mozilla/5.0 (compatible; AIOSEO/1.0)'
		]);

		$httpCode = wp_remote_retrieve_response_code( $response );
		if ( is_wp_error( $response ) || 200 !== $httpCode ) {
			$this->document = null;

			return;
		}

		$html = wp_remote_retrieve_body( $response );
		@$this->document->loadHTML( $html );  // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		libxml_clear_errors();
		libxml_use_internal_errors( $previousUseInternalErrors );
	}

	/**
	 * Returns the document.
	 *
	 * @since 4.8.6
	 *
	 * @return \DOMDocument|null
	 */
	public function hasDocument() {
		return (bool) $this->document;
	}

	/**
	 * Returns the head from the document.
	 *
	 * @since 4.8.6
	 *
	 * @return \DOMElement|null
	 */
	public function getHead() {
		if ( ! $this->hasDocument() ) {
			return null;
		}

		$head = $this->document->getElementsByTagName( 'head' )->item( 0 );

		if ( ! $head ) {
			return null;
		}

		return $head;
	}

	/**
	 * Returns the body from the document.
	 *
	 * @since 4.8.6
	 *
	 * @return \DOMElement|null
	 */
	public function getBody() {
		if ( ! $this->hasDocument() ) {
			return null;
		}

		$body = $this->isScraping
			? $this->document->getElementsByTagName( 'body' )->item( 0 )
			: $this->document;

		if ( ! $body ) {
			return null;
		}

		return $body;
	}

	/**
	 * Returns the main from the document.
	 *
	 * @since 4.8.6
	 *
	 * @return \DOMElement|null
	 */
	public function getMain() {
		if ( ! $this->hasDocument() ) {
			return null;
		}

		$main = @$this->document->getElementsByTagName( 'main' )->item( 0 );

		if ( ! $main ) {
			return $this->getBody();
		}

		return $main;
	}

	/**
	 * Returns the elements from a specific section and tags.
	 *
	 * @since 4.8.6
	 *
	 * @param  string        $section The section to get the elements from (body, head)
	 * @param  array         $tags    List of tags to get the elements from. (link, script, img, source)
	 * @return \DOMElement[]
	 */
	public function getSectionElements( $section, $tags ) {
		if ( ! $this->hasDocument() ) {
			return [];
		}

		switch ( $section ) {
			case 'body':
				$section = $this->getBody();
				break;
			case 'head':
				$section = $this->getHead();
				break;
			case 'main':
				$section = $this->getMain();
				break;
			default:
				$section = $this->document;
				break;
		}

		if ( ! $section ) {
			return [];
		}

		$elements = [];
		foreach ( $tags as $tag ) {
			$obj = $section->getElementsByTagName( $tag );

			if ( ! $obj instanceof \DOMNodeList ) {
				continue;
			}

			for ( $i = 0; $i < $obj->length; $i++ ) {
				$content = $obj->item( $i );

				if ( ! $content instanceof \DOMElement ) {
					continue;
				}

				array_push( $elements, $content );
			}
		}

		return $elements;
	}

	/**
	 * Returns the content from a specific HTML tag.
	 *
	 * @since 4.8.6
	 *
	 * @param  string $tag HTML tag.
	 * @return array       Array of content from the tag.
	 */
	public function getElementsByTagName( $tag ) {
		$result = [];

		if ( ! $this->hasDocument() ) {
			return $result;
		}

		foreach ( $this->document->getElementsByTagName( $tag ) as $item ) {
			array_push( $result, trim( $item->nodeValue ) );
		}

		return $result;
	}

	/**
	 * Returns the content from a specific HTML meta tag.
	 *
	 * @since 4.8.6
	 *
	 * @param  string $name Meta tag name attribute.
	 * @return array        Array of content from the meta tags.
	 */
	public function getMetaTag( $name ) {
		$result = [];

		if ( ! $this->hasDocument() ) {
			return $result;
		}

		foreach ( $this->document->getElementsByTagName( 'meta' ) as $item ) {
			if ( $item instanceof \DOMElement && $item->getAttribute( 'name' ) === $name ) {
				array_push( $result, $item->getAttribute( 'content' ) );
			}
		}

		return $result;
	}

	/**
	 * Returns the images from the document body.
	 *
	 * @since 4.8.6
	 *
	 * @return array
	 */
	public function getBodyImages() {
		if ( ! $this->hasDocument() ) {
			return [];
		}

		// Just consider the body since we're not interested in images in the head.
		$body = $this->getBody();
		if ( ! $body ) {
			return [];
		}

		$result = [
			'imagesWithoutAltText' => [],
			'imageUrls'            => [],
		];

		foreach ( $body->getElementsByTagName( 'img' ) as $item ) {
			if ( ! $item instanceof \DOMElement ) {
				continue;
			}

			// If any of this image's parents are a noscript tag, let's skip it.
			$parentTag = $item->parentNode;
			while ( $parentTag ) {
				if ( $parentTag instanceof \DOMElement && ! empty( $parentTag->tagName ) && 'noscript' === $parentTag->tagName ) {
					continue 2;
				}

				$parentTag = $parentTag->parentNode;
			}

			if ( ! $item->hasAttribute( 'src' ) ) {
				continue;
			}

			// If this is a lazy-loaded data URI image, let's use the data-src if possible.
			$imageSrcUrl = $item->getAttribute( 'src' );
			if ( false !== strpos( $imageSrcUrl, 'data:image' ) && $item->hasAttribute( 'data-src' ) ) {
				$imageSrcUrl = $item->getAttribute( 'data-src' );
			}

			// If this is a relative link, let's append our domain to it.
			$imageUrlUtil = new Utils\Url( $imageSrcUrl );
			if ( empty( $imageUrlUtil->getTld() ) ) {
				$imageUrlUtil = $this->urlUtil->relativeToAbsolute( $imageSrcUrl );
			}

			if ( ! $item->hasAttribute( 'alt' ) || empty( $item->getAttribute( 'alt' ) ) ) {
				array_push( $result['imagesWithoutAltText'], $imageUrlUtil->getUrl() );
			}

			array_push( $result['imageUrls'], $imageUrlUtil->getUrl() );
		}

		return $result;
	}

	/**
	 * Returns the links from the document.
	 *
	 * @since 4.8.6
	 *
	 * @return array
	 */
	public function getLinks() {
		$result = [
			'internalLinks' => [],
			'externalLinks' => [],
		];

		if ( ! $this->hasDocument() ) {
			return $result;
		}

		foreach ( $this->document->getElementsByTagName( 'a' ) as $item ) {
			/** @var \DOMElement $item */
			$href        = $item->getAttribute( 'href' );
			$hrefUrlUtil = new Utils\Url( $href );

			// If this is a relative link, let's append our domain to it.
			if ( empty( $hrefUrlUtil->getTld() ) ) {
				$hrefUrlUtil = $this->urlUtil->relativeToAbsolute( $href );
			}

			if ( $hrefUrlUtil->getTld() === $this->urlUtil->getTld() ) {
				array_push( $result['internalLinks'], rtrim( $hrefUrlUtil->getUrl(), '/' ) );
				continue;
			}

			array_push( $result['externalLinks'], rtrim( $hrefUrlUtil->getUrl(), '/' ) );
		}

		return $result;
	}

	/**
	 * Returns the canonical URL from the document.
	 *
	 * @since 4.8.6
	 *
	 * @return string The canonical URL.
	 */
	public function getCanonicalUrl() {
		if ( ! $this->hasDocument() ) {
			return '';
		}

		foreach ( $this->document->getElementsByTagName( 'link' ) as $item ) {
			if ( $item instanceof \DOMElement && $item->getAttribute( 'rel' ) === 'canonical' ) {
				return $item->getAttribute( 'href' );
			}
		}

		return '';
	}

	/**
	 * Returns the social tags (open graph, twitter) from the document.
	 *
	 * @since 4.8.6
	 *
	 * @return array
	 */
	private function getSocialTags() {
		$result = [];

		if ( ! $this->hasDocument() ) {
			return $result;
		}

		$tags = [
			'og:title'       => '',
			'og:description' => '',
			'og:type'        => '',
			'og:image'       => '',
			'og:url'         => '',
			'twitter:title'  => '',
			'twitter:image'  => '',
		];

		foreach ( $this->document->getElementsByTagName( 'meta' ) as $item ) {
			/** @var \DOMElement $item */
			$property = $item->getAttribute( 'property' );
			$name     = $item->getAttribute( 'name' );
			$content  = $item->getAttribute( 'content' );

			if ( ! empty( $property ) && array_key_exists( $property, $tags ) ) {
				array_push( $result, [
					'key'   => $property,
					'value' => $content
				] );
			}

			if ( ! empty( $name ) && array_key_exists( $name, $tags ) ) {
				array_push( $result, [
					'key'   => $name,
					'value' => $content
				] );
			}
		}

		return $result;
	}

	/**
	 * Returns the open graph tags from the document.
	 *
	 * @since 4.8.6
	 *
	 * @return array
	 */
	public function getOpenGraphTags() {
		$socialTags = $this->getSocialTags();

		return array_filter( $socialTags, function( $tag ) {
			return 'og:' === substr( $tag['key'], 0, 3 );
		} );
	}

	/**
	 * Returns the twitter tags from the document.
	 *
	 * @since 4.8.6
	 *
	 * @return array
	 */
	public function getTwitterTags() {
		$socialTags = $this->getSocialTags();

		return array_filter( $socialTags, function( $tag ) {
			return 'twitter:' === substr( $tag['key'], 0, 8 );
		} );
	}

	/**
	 * Returns the schema.org rules from the document.
	 *
	 * @since 4.8.6
	 *
	 * @return string The schema.org rules.
	 */
	public function getSchemas() {
		$result = '';

		if ( ! $this->hasDocument() ) {
			return $result;
		}

		foreach ( $this->document->getElementsByTagName( 'script' ) as $item ) {
			if ( $item instanceof \DOMElement && 'application/ld+json' === $item->getAttribute( 'type' ) && 'aioseo-schema' === $item->getAttribute( 'class' ) ) {
				$result = $item->nodeValue;
			}
		}

		return $result;
	}
}