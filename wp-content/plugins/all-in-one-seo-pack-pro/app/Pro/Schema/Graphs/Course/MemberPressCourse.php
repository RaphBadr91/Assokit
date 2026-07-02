<?php
namespace AIOSEO\Plugin\Pro\Schema\Graphs\Course;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use memberpress\courses\models as CourseModels;

/**
 * MemberPressCourse class
 *
 * @since 4.6.4
 */
class MemberPressCourse extends Course {
	/**
	 * Returns the graph data.
	 *
	 * @since 4.6.4
	 *
	 * @param  Object $graphData The graph data.
	 * @return array             The parsed graph data.
	 */
	public function get( $graphData = null ) {
		$data = parent::getGraphData( $graphData );
		if ( ! aioseo()->helpers->isMemberPressCoursesActive() ) {
			return [];
		}

		$courseId            = get_the_ID();
		$certificatesEnabled = get_post_meta( $courseId, '_mmcs_course_certificates_enable', true );
		if ( is_string( $certificatesEnabled ) && 'enabled' === strtolower( $certificatesEnabled ) ) {
			$data['hasCourseCertificate'] = [
				'@type' => 'EducationalOccupationalCredential',
				'name'  => sprintf(
					// Translators: 1 - The site name.
					__( '%1$s Certificate of Completion', 'aioseo-pro' ),
					get_bloginfo( 'name' )
				)
			];
		}

		if ( isset( $graphData->properties->autogenerate ) && ! $graphData->properties->autogenerate ) {
			return $data;
		}

		$sections = CourseModels\Section::find_all_by_course( $courseId );
		if ( ! empty( $sections ) ) {
			$sectionData = [];
			foreach ( $sections as $section ) {
				$sectionData[] = [
					'@type'       => 'Syllabus',
					'name'        => $section->title,
					'description' => $section->description
				];
			}

			if ( ! empty( $sectionData ) ) {
				$data['syllabusSections'] = $sectionData;
			}
		}

		include_once MEPR_MODELS_PATH . '/MeprOptions.php';
		include_once MEPR_MODELS_PATH . '/MeprProduct.php';
		include_once MEPR_MODELS_PATH . '/MeprRule.php';

		$post       = get_post( $courseId );
		$accessList = \MeprRule::get_access_list( $post );

		if ( ! empty( $accessList['membership'] ) && is_array( $accessList['membership'] ) ) {
			$offers             = [];
			$memberPressOptions = \MeprOptions::fetch();

			foreach ( $accessList['membership'] as $membership ) {
				$product = new \MeprProduct( $membership );
				if ( 0 === absint( $product->price ) ) {
					$offers[] = [
						'@type'    => 'Offer',
						'category' => 'Free'
					];
					continue;
				}

				$offers[] = [
					'@type'         => 'Offer',
					'category'      => 'lifetime' !== $product->period_type ? 'Subscription' : 'Paid',
					'price'         => $product->price,
					'priceCurrency' => $memberPressOptions->currency_code
				];
			}

			$data['offers'] = $offers;
		}

		return $data;
	}
}