<?php
namespace AIOSEO\Plugin\Pro\Schema\Graphs\Course;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Schema\Graphs as CommonGraphs;

/**
 * Course class
 *
 * @since   4.2.5
 * @version 4.6.4 Moved to its own folder.
 */
class Course extends CommonGraphs\Graph {
	/**
	 * Returns the graph data.
	 *
	 * @since 4.2.5
	 *
	 * @param  Object $graphData The graph data.
	 * @return array             The parsed graph data.
	 */
	public function get( $graphData = null ) {
		$post = aioseo()->helpers->getPost();
		if ( ! is_a( $post, 'WP_Post' ) ) {
			return [];
		}

		if ( 'mmcs-course' === $post->post_type && aioseo()->helpers->isMemberMouseCoursesActive() ) {
			return ( new MemberMouseCourse() )->get( $graphData );
		}

		if ( 'mpcs-course' === $post->post_type && aioseo()->helpers->isMemberPressCoursesActive() ) {
			return ( new MemberPressCourse() )->get( $graphData );
		}

		return $this->getGraphData( $graphData );
	}

	/**
	 * Returns the graph data.
	 * Helper method for the get() method. We need this so child classes can get the parent data without running into a loop.
	 *
	 * @since 4.6.4
	 *
	 * @param  Object $graphData The graph data.
	 * @return array             The parsed graph data.
	 */
	protected function getGraphData( $graphData = null ) {
		$post = aioseo()->helpers->getPost();
		if ( ! is_a( $post, 'WP_Post' ) ) {
			return [];
		}

		$data = [
			'@type'               => 'Course',
			'@id'                 => ! empty( $graphData->id ) ? aioseo()->schema->context['url'] . $graphData->id : aioseo()->schema->context['url'] . '#course',
			'name'                => ! empty( $graphData->properties->name ) ? $graphData->properties->name : $post->post_title,
			'description'         => ! empty( $graphData->properties->description ) ? $graphData->properties->description : aioseo()->schema->context['description'],
			'datePublished'       => ! empty( $graphData->properties->publishDate )
				? mysql2date( 'Y-m-d', $graphData->properties->publishDate, false )
				: mysql2date( 'Y-m-d', $post->post_date, false ),
			'about'               => ! empty( $graphData->properties->about ) ? $this->extractMultiselectTags( $graphData->properties->about ) : [],
			'coursePrerequisites' => ! empty( $graphData->properties->prerequisites ) ? $this->extractMultiselectTags( $graphData->properties->prerequisites ) : [],
			'teaches'             => ! empty( $graphData->properties->teaches ) ? $this->extractMultiselectTags( $graphData->properties->teaches ) : [],
			'image'               => ! empty( $graphData->properties->image ) ? $this->image( $graphData->properties->image ) : '',
			'provider'            => [
				'@type'  => 'Organization',
				'name'   => ! empty( $graphData->properties->provider->name ) ? $graphData->properties->provider->name : '',
				'sameAs' => ! empty( $graphData->properties->provider->url ) ? $graphData->properties->provider->url : '',
				'image'  => ! empty( $graphData->properties->provider->image ) ? $this->image( $graphData->properties->provider->image ) : ''
			]
		];

		if (
			empty( $data['provider']['name'] ) &&
			'organization' === aioseo()->options->searchAppearance->global->schema->siteRepresents
		) {
			if ( empty( $graphData->properties->provider->name ) ) {
				$homeUrl          = trailingslashit( home_url() );
				$data['provider'] = [
					'@type' => 'Organization',
					'@id'   => $homeUrl . '#organization',
				];
			} else {
				unset( $data['provider'] );
			}
		}

		if ( ! empty( $graphData->properties->offers ) ) {
			$offers = [];
			foreach ( $graphData->properties->offers as $offer ) {
				if ( empty( $offer->category ) ) {
					continue;
				}

				$offerData = [
					'@type'    => 'Offer',
					'category' => $offer->category
				];

				if ( 'free' !== strtolower( $offer->category ) ) {
					$offerData['price']         = ! empty( $offer->price ) ? $offer->price : '';
					$offerData['priceCurrency'] = ! empty( $offer->currency ) ? $offer->currency : 'USD';
				}

				$offers[] = $offerData;
			}

			if ( ! empty( $offers ) ) {
				$data['offers'] = $offers;
			}
		}

		if ( ! empty( $graphData->properties->courseInstances ) ) {
			$courseInstances = [];
			foreach ( $graphData->properties->courseInstances as $courseInstance ) {
				if (
					empty( $courseInstance->mode ) ||
					empty( $courseInstance->workload ) ||
					empty( $courseInstance->schedule->repeatFrequency )
				) {
					continue;
				}

				$courseInstanceData = [
					'courseMode'     => ! empty( $courseInstance->mode ) ? $courseInstance->mode : '',
					'courseWorkload' => ! empty( $courseInstance->workload ) ? aioseo()->helpers->timeToIso8601DurationFormat( 0, 0, $courseInstance->workload ) : '',
					'courseSchedule' => [
						'@type'           => 'Schedule',
						'duration'        => ! empty( $courseInstance->schedule->duration ) ? aioseo()->helpers->timeToIso8601DurationFormat( 0, 0, $courseInstance->schedule->duration ) : '', // phpcs:ignore Generic.Files.LineLength.MaxExceeded
						'repeatCount'     => ! empty( $courseInstance->schedule->repeatCount ) ? (int) $courseInstance->schedule->repeatCount : 0,
						'repeatFrequency' => ! empty( $courseInstance->schedule->repeatFrequency ) ? $courseInstance->schedule->repeatFrequency : '',
						'startDate'       => ! empty( $courseInstance->schedule->startDate ) ? $courseInstance->schedule->startDate : '',
						'endDate'         => ! empty( $courseInstance->schedule->endDate ) ? $courseInstance->schedule->endDate : '',
					]
				];

				if ( 'online' !== strtolower( $courseInstance->mode ) ) {
					$courseInstanceData['location'] = ! empty( $courseInstance->location ) ? $courseInstance->location : '';
				}

				if ( ! empty( $courseInstance->instructor->name ) ) {
					$courseInstanceData['instructor'] = [
						'@type'       => 'Person',
						'name'        => $courseInstance->instructor->name,
						'description' => ! empty( $courseInstance->instructor->description ) ? $courseInstance->instructor->description : '',
						'image'       => ! empty( $courseInstance->instructor->image ) ? $this->image( $courseInstance->instructor->image ) : ''
					];
				}

				$courseInstances[] = $courseInstanceData;
			}

			if ( ! empty( $courseInstances ) ) {
				$data['hasCourseInstance'] = $courseInstances;
			}
		}

		$reviews     = [];
		$reviewTotal = 0;
		if ( ! empty( $graphData->properties->reviews ) ) {
			foreach ( $graphData->properties->reviews as $review ) {
				if (
					empty( $review->rating ) ||
					empty( $review->author ) ||
					! isset( $graphData->properties->rating->minimum ) ||
					! isset( $graphData->properties->rating->maximum )
				) {
					continue;
				}

				$reviews[] = [
					'@type'        => 'Review',
					'headline'     => $review->headline,
					'reviewBody'   => $review->content,
					'reviewRating' => [
						'@type'       => 'Rating',
						'ratingValue' => (float) $review->rating,
						'worstRating' => (float) $graphData->properties->rating->minimum,
						'bestRating'  => (float) $graphData->properties->rating->maximum
					],
					'author'       => [
						'@type' => 'Person',
						'name'  => $review->author,
					],
				];

				$reviewTotal += (float) $review->rating;
			}
		}

		$reviewCount = count( $reviews );
		if ( count( $reviews ) ) {
			$data['review'] = $reviews;

			$data['aggregateRating'] = [
				'@type'       => 'AggregateRating',
				'ratingValue' => round( $reviewTotal / $reviewCount, 2 ),
				'worstRating' => (float) $graphData->properties->rating->minimum,
				'bestRating'  => (float) $graphData->properties->rating->maximum,
				'reviewCount' => $reviewCount
			];
		}

		if ( ! empty( $graphData->properties->syllabusSections ) ) {
			$syllabusSections = [];
			foreach ( $graphData->properties->syllabusSections as $syllabusSection ) {
				if ( empty( $syllabusSection->name ) ) {
					continue;
				}

				$syllabusSections[] = [
					'@type'        => 'Syllabus',
					'name'         => $syllabusSection->name,
					'description'  => ! empty( $syllabusSection->description ) ? $syllabusSection->description : '',
					'timeRequired' => ! empty( $syllabusSection->timeRequired ) ? aioseo()->helpers->timeToIso8601DurationFormat( 0, 0, $syllabusSection->timeRequired ) : ''
				];
			}

			if ( ! empty( $syllabusSections ) ) {
				$data['syllabusSections'] = $syllabusSections;
			}
		}

		return $data;
	}
}