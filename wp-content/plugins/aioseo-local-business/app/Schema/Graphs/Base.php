<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Schema\Graphs;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Schema\Graphs as CommonGraphs;

/**
 * Base graph class.
 *
 * @since 1.3.3
 */
abstract class Base extends CommonGraphs\Graph {
	/**
	 *The data for the CPT or the global options.
	 *
	 * @since 1.3.3
	 *
	 * @var object|null
	 */
	protected $dataObject = null;

	/**
	 * Returns the address.
	 *
	 * @since   1.0.0
	 * @version 1.3.3 Moved to abstract class.
	 *
	 * @return array The address.
	 */
	protected function address() {
		$streetLine1 = $this->dataObject->locations->business->address->streetLine1 ?? '';
		$streetLine2 = $this->dataObject->locations->business->address->streetLine2 ?? '';
		if ( ! $streetLine1 && ! $streetLine2 ) {
			return [];
		}

		return [
			'@id'             => trailingslashit( home_url() ) . '#postaladdress',
			'@type'           => 'PostalAddress',
			'streetAddress'   => sprintf(
				'%1$s, %2$s',
				$streetLine1,
				$streetLine2
			),
			'postalCode'      => $this->dataObject->locations->business->address->zipCode ?? '',
			'addressLocality' => $this->dataObject->locations->business->address->city ?? '',
			'addressRegion'   => $this->dataObject->locations->business->address->state ?? '',
			'addressCountry'  => $this->dataObject->locations->business->address->country ?? ''
		];
	}

	/**
	 * Returns the IDs.
	 *
	 * @since 1.3.3
	 *
	 * @return array The ids.
	 */
	protected function ids() {
		$ids = [
			'taxID'                => $this->dataObject->locations->business->ids->tax ?? '',
			'vatID'                => $this->dataObject->locations->business->ids->vat ?? '',
			'iso6523Code'          => $this->dataObject->locations->business->ids->iso6523 ?? '',
			'leiCode'              => $this->dataObject->locations->business->ids->lei ?? '',
			'duns'                 => $this->dataObject->locations->business->ids->duns ?? '',
			'naics'                => $this->dataObject->locations->business->ids->naics ?? '',
			'globalLocationNumber' => $this->dataObject->locations->business->ids->gs1 ?? ''
		];

		if ( empty( $ids['iso6523Code'] ) && ! empty( $ids['duns'] ) ) {
			$ids['iso6523Code'] = '0060:' . $ids['duns'];
		}

		if ( empty( $ids['iso6523Code'] ) && ! empty( $ids['globalLocationNumber'] ) ) {
			$ids['iso6523Code'] = '0088:' . $ids['globalLocationNumber'];
		}

		if ( empty( $ids['iso6523Code'] ) && ! empty( $ids['leiCode'] ) ) {
			$ids['iso6523Code'] = '0199:' . $ids['leiCode'];
		}

		return array_filter( $ids );
	}
}