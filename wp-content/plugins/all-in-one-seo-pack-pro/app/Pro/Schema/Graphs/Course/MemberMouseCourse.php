<?php
namespace AIOSEO\Plugin\Pro\Schema\Graphs\Course;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MemberMouseCourse class
 *
 * @since 4.6.4
 */
class MemberMouseCourse extends Course {
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
		if ( ! aioseo()->helpers->isMemberMouseCoursesActive() ) {
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

		$sectionTableName = aioseo()->core->db->prefix . 'mmcs_sections';
		$sections         = aioseo()->core->db->execute(
			aioseo()->core->db->db->prepare(
				"SELECT * FROM {$sectionTableName} WHERE course_id = $courseId ORDER BY section_order ASC",
				get_the_ID()
			),
			true
		)->result();

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

		// Get all memberships that have access to this course.
		$postAccessTableName = aioseo()->core->db->prefix . 'mm_posts_access';
		$memberships         = aioseo()->core->db->execute(
			aioseo()->core->db->db->prepare(
				"SELECT access_id FROM {$postAccessTableName} WHERE post_id = %d",
				$courseId
			),
			true
		)->result();

		if ( ! empty( $memberships ) ) {
			$currency = get_option( 'mm-option-currency', 'USD' );

			$offers = [];
			foreach ( $memberships as $membership ) {
				$membershipLevelTableName = aioseo()->core->db->prefix . 'mm_membership_levels';
				$membershipLevels         = aioseo()->core->db->execute(
					aioseo()->core->db->db->prepare(
						"SELECT * FROM {$membershipLevelTableName} WHERE id = %d",
						$membership->access_id
					),
					true
				)->result();

				foreach ( $membershipLevels as $membershipLevel ) {
					if ( '1' === $membershipLevel->is_free ) {
						$offers[] = [
							'@type'    => 'Offer',
							'category' => 'Free'
						];

						continue;
					}

					// Get the product IDs for the membership level.
					$membershipProductTableName = aioseo()->core->db->prefix . 'mm_membership_level_products';
					$membershipProducts         = aioseo()->core->db->execute(
						aioseo()->core->db->db->prepare(
							"SELECT * FROM {$membershipProductTableName} WHERE membership_id = %d",
							$membershipLevel->id
						),
						true
					)->result();

					foreach ( $membershipProducts as $membershipProduct ) {
						$productTableName = aioseo()->core->db->prefix . 'mm_products';
						$product          = aioseo()->core->db->execute(
							aioseo()->core->db->db->prepare(
								"SELECT * FROM {$productTableName} WHERE id = %d",
								$membershipProduct->product_id
							),
							true
						)->result();

						foreach ( $product as $productData ) {
							$category = ! empty( $productData->rebill_frequency ) ? 'Subscription' : 'Paid';

							$offers[] = [
								'@type'         => 'Offer',
								'category'      => $category,
								'price'         => number_format( $productData->price, 2 ),
								'priceCurrency' => $currency
							];
						}
					}
				}
			}

			if ( ! empty( $offers ) ) {
				$data['offers'] = $offers;
			}
		}

		return $data;
	}
}