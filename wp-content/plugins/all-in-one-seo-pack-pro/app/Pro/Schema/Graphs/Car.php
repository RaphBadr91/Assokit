<?php
namespace AIOSEO\Plugin\Pro\Schema\Graphs;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Car graph class.
 *
 * @since 4.6.8
 */
class Car extends Product\Product {
	/**
	 * Returns the graph data.
	 *
	 * @since 4.6.8
	 *
	 * @param  object $graphData The graph data.
	 * @return array             The parsed graph data.
	 */
	public function get( $graphData = null ) {
		$this->graphData = $graphData;

		$data = [
			'@type'                       => [ 'Product', 'Car' ],
			'@id'                         => ! empty( $graphData->id ) ? aioseo()->schema->context['url'] . $graphData->id : aioseo()->schema->context['url'] . '#product',
			'name'                        => ! empty( $graphData->properties->name ) ? $graphData->properties->name : get_the_title(),
			'description'                 => ! empty( $graphData->properties->description ) ? $graphData->properties->description : '',
			'url'                         => aioseo()->schema->context['url'],
			'brand'                       => '',
			'vehicleIdentificationNumber' => ! empty( $graphData->properties->identifiers->vehicleIdentificationNumber ) ? $graphData->properties->identifiers->vehicleIdentificationNumber : '',
			'model'                       => ! empty( $graphData->properties->identifiers->model ) ? $graphData->properties->identifiers->model : '',
			'vehicleConfiguration'        => ! empty( $graphData->properties->identifiers->vehicleConfiguration ) ? $graphData->properties->identifiers->vehicleConfiguration : '',
			'vehicleModelDate'            => ! empty( $graphData->properties->identifiers->vehicleModelDate ) ? $graphData->properties->identifiers->vehicleModelDate : '',
			'color'                       => ! empty( $graphData->properties->attributes->color ) ? $graphData->properties->attributes->color : '',
			'vehicleInteriorColor'        => ! empty( $graphData->properties->attributes->vehicleInteriorColor ) ? $graphData->properties->attributes->vehicleInteriorColor : '',
			'vehicleInteriorType'         => ! empty( $graphData->properties->attributes->vehicleInteriorType ) ? $graphData->properties->attributes->vehicleInteriorType : '',
			'bodyType'                    => ! empty( $graphData->properties->attributes->bodyType ) ? $graphData->properties->attributes->bodyType : '',
			'driveWheelConfiguration'     => ! empty( $graphData->properties->attributes->driveWheelConfiguration ) ? $graphData->properties->attributes->driveWheelConfiguration : '',
			'vehicleTransmission'         => ! empty( $graphData->properties->attributes->vehicleTransmission ) ? $graphData->properties->attributes->vehicleTransmission : '',
			'vehicleSeatingCapacity'      => ! empty( $graphData->properties->attributes->vehicleSeatingCapacity ) ? (int) $graphData->properties->attributes->vehicleSeatingCapacity : '',
			'itemCondition'               => ! empty( $graphData->properties->attributes->itemCondition ) ? $graphData->properties->attributes->itemCondition : '',
			'numberOfDoors'               => ! empty( $graphData->properties->attributes->numberOfDoors ) ? (int) $graphData->properties->attributes->numberOfDoors : '',
			'vehicleEngine'               => '',
			'image'                       => ! empty( $graphData->properties->image ) ? $this->image( $graphData->properties->image ) : $this->getFeaturedImage()
		];

		if ( ! empty( $graphData->properties->brand ) ) {
			$data['brand'] = [
				'@type' => 'Brand',
				'name'  => $graphData->properties->brand
			];
		}

		if (
			! empty( $graphData->properties->attributes->itemCondition ) &&
			'https://schema.org/UsedCondition' === $graphData->properties->attributes->itemCondition &&
			! empty( $graphData->properties->mileageFromOdometer->value ) &&
			! empty( $graphData->properties->mileageFromOdometer->unitCode )
		) {
			$data['mileageFromOdometer'] = [
				'@type'    => 'QuantitativeValue',
				'value'    => (float) $graphData->properties->mileageFromOdometer->value,
				'unitCode' => $graphData->properties->mileageFromOdometer->unitCode,
			];
		}

		if ( ! empty( $graphData->properties->attributes->vehicleEngineFuelType ) ) {
			$data['vehicleEngine'] = [
				'@type'    => 'EngineSpecification',
				'fuelType' => $graphData->properties->attributes->vehicleEngineFuelType,
			];
		}

		return $this->getDataWithOfferPriceData( $data, $graphData );
	}
}