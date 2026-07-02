<?php

namespace AIOSEO\Plugin\Pro\SeoAnalysis;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\SeoAnalysis as CommonSeoAnalysis;
use AIOSEO\Plugin\Pro\Models;
use AIOSEO\Plugin\Common\Models as CommonModels;
/**
 * Handles the SEO validation.
 *
 * @since 4.8.6
 */
class SeoAnalysis extends CommonSeoAnalysis\SeoAnalysis {
	/**
	 * The available groups.
	 *
	 * @since 4.8.9
	 *
	 * @var array
	 */
	private $groupsAvailable = [ 'basic', 'advanced', 'performance' ];

	/**
	 * The Basic codes by severity.
	 *
	 * @since 4.8.6
	 *
	 * @var array
	 */
	public $basicCodesStatus = [
		'passed'  => [
			[
				'code'         => 'title-ok',
				'capabilities' => []
			],
			[
				'code'         => 'description-ok',
				'capabilities' => []
			],
			[
				'code'         => 'h1-ok',
				'capabilities' => []
			],
			[
				'code'         => 'subheading-ok',
				'capabilities' => []
			],
			[
				'code'         => 'image-ok',
				'capabilities' => [ 'upload_files' ]
			],
			[
				'code'         => 'links-ratio-ok',
				'capabilities' => []
			],
			[
				'code'         => 'thumbnail-ok',
				'capabilities' => [ 'upload_files' ]
			],
			[
				'code'         => 'content-length-ok',
				'capabilities' => []
			],
			[
				'code'         => 'keyword-cannibalization-ok',
				'capabilities' => [ 'aioseo_page_general_settings' ]
			],
			[
				'code'         => 'first-paragraph-ok',
				'capabilities' => []
			],
			[
				'code'         => 'title-focus-keyword-ok',
				'capabilities' => []
			],
			[
				'code'         => 'description-focus-keyword-ok',
				'capabilities' => []
			],
			[
				'code'         => 'url-focus-keyword-ok',
				'capabilities' => []
			],
			[
				'code'         => 'url-length-ok',
				'capabilities' => []
			],
			[
				'code'         => 'product-schema-ok',
				'capabilities' => [ 'aioseo_page_schema_settings' ]
			]
		],
		'warning' => [
			[
				'code'         => 'title-too-short',
				'capabilities' => []
			],
			[
				'code'         => 'description-too-short',
				'capabilities' => []
			],
			[
				'code'         => 'subheading-missing',
				'capabilities' => []
			],
			[
				'code'         => 'subheading-missing-focus-keyword',
				'capabilities' => [ 'aioseo_page_analysis' ]
			],
			[
				'code'         => 'thumbnail-missing',
				'capabilities' => [ 'upload_files' ]
			],
			[
				'code'         => 'content-length-too-short',
				'capabilities' => []
			],
			[
				'code'         => 'url-length-too-long',
				'capabilities' => []
			]
		],
		'error'   => [
			[
				'code'         => 'title-missing',
				'capabilities' => []
			],
			[
				'code'         => 'title-too-long',
				'capabilities' => []
			],
			[
				'code'         => 'description-missing',
				'capabilities' => []
			],
			[
				'code'         => 'description-too-long',
				'capabilities' => []
			],
			[
				'code'         => 'h1-missing',
				'capabilities' => []
			],
			[
				'code'         => 'h1-too-many',
				'capabilities' => []
			],
			[
				'code'         => 'h1-missing-focus-keyword',
				'capabilities' => [ 'aioseo_page_analysis' ]
			],
			[
				'code'         => 'image-missing',
				'capabilities' => [ 'upload_files' ]
			],
			[
				'code'         => 'image-missing-alt',
				'capabilities' => [ 'upload_files' ]
			],
			[
				'code'         => 'internal-links-missing',
				'capabilities' => []
			],
			[
				'code'         => 'internal-links-too-few',
				'capabilities' => []
			],
			[
				'code'         => 'keyword-cannibalization',
				'capabilities' => [ 'aioseo_page_general_settings' ]
			],
			[
				'code'         => 'first-paragraph-missing-focus-keyword',
				'capabilities' => [ 'aioseo_page_analysis' ]
			],
			[
				'code'         => 'title-missing-focus-keyword',
				'capabilities' => [ 'aioseo_page_analysis' ]
			],
			[
				'code'         => 'description-missing-focus-keyword',
				'capabilities' => [ 'aioseo_page_analysis' ]
			],
			[
				'code'         => 'url-missing-focus-keyword',
				'capabilities' => [ 'aioseo_page_analysis' ]
			],
			[
				'code'         => 'product-schema-missing',
				'capabilities' => [ 'aioseo_page_schema_settings' ]
			]
		]
	];

	/**
	 * The Advanced codes by severity.
	 *
	 * @since 4.8.6
	 *
	 * @var array
	 */
	public $advancedCodesStatus = [
		'passed'  => [
			[
				'code'         => 'noindex-ok',
				'capabilities' => [ 'aioseo_page_advanced_settings' ]
			],
			[
				'code'         => 'ogp-ok',
				'capabilities' => [ 'aioseo_page_social_settings' ]
			],
			[
				'code'         => 'schema-ok',
				'capabilities' => [ 'aioseo_page_schema_settings' ]
			],
			[
				'code'         => 'canonical-ok',
				'capabilities' => [ 'aioseo_page_advanced_settings' ]
			],
			[
				'code'         => 'author-bio-ok',
				'capabilities' => [ 'edit_users' ]
			],
			[
				'code'         => 'main-keyword-ok',
				'capabilities' => [ 'aioseo_page_general_settings' ]
			],
			[
				'code'         => 'stale-content-ok',
				'capabilities' => []
			]
		],
		'warning' => [
			[
				'code'         => 'noindex',
				'capabilities' => [ 'aioseo_page_advanced_settings' ]
			],
			[
				'code'         => 'canonical-missing',
				'capabilities' => [ 'aioseo_page_advanced_settings' ]
			],
			[
				'code'         => 'author-bio-missing',
				'capabilities' => [ 'edit_users' ]
			],
			[
				'code'         => 'main-keyword-missing',
				'capabilities' => [ 'aioseo_page_analysis' ]
			],
			[
				'code'         => 'stale-content-too-old',
				'capabilities' => []
			]
		],
		'error'   => [
			[
				'code'         => 'ogp-missing',
				'capabilities' => [ 'aioseo_page_social_settings' ]
			],
			[
				'code'         => 'ogp-duplicates',
				'capabilities' => [ 'aioseo_page_social_settings' ]
			],
			[
				'code'         => 'schema-missing',
				'capabilities' => [ 'aioseo_page_schema_settings' ]
			]
		]
	];

	/**
	 * The Performance codes by severity.
	 *
	 * @since 4.8.6
	 *
	 * @var array
	 */
	public $performanceCodesStatus = [
		'passed'  => [
			[
				'code'         => 'requests',
				'capabilities' => []
			]
		],
		'warning' => [],
		'error'   => []
	];

	/**
	 * Constructor.
	 *
	 * @since 4.8.6
	 */
	public function __construct() {
		// Initialize the post and term scans.
		new ActionScheduler\Post();
		new ActionScheduler\Term();
	}

	/**
	 * Returns the data for Vue.
	 *
	 * @since 4.8.6
	 *
	 * @return array The data for Vue.
	 */
	public function getVueData() {
		return parent::getVueData();
	}

	/**
	 * Analyze the post.
	 *
	 * @since 4.8.6
	 *
	 * @param  int   $objectId The object ID.
	 * @return array
	 */
	public function analyzePost( $objectId ) {
		$post       = get_post( $objectId );
		$content    = $post && 'publish' !== $post->post_status ? apply_filters( 'the_content', $post->post_content ) : '';
		$isScraping = $post && 'publish' === $post->post_status;
		$pageParser = new PageParser( get_permalink( $objectId ), $content, $isScraping );
		if ( ! $pageParser->hasDocument() ) {
			return [
				'results' => [
					'basic'    => [],
					'advanced' => []
				],
			];
		}

		$results = [
			'basic'    => ( new Checkers\Post\Basic( $objectId, $pageParser ) )->get(),
			'advanced' => ( new Checkers\Post\Advanced( $objectId, $pageParser ) )->get()
		];

		return [
			'results' => $results,
		];
	}

	/**
	 * Analyze the term.
	 *
	 * @since 4.8.6
	 *
	 * @param  int   $objectId The object ID.
	 * @return array
	 */
	public function analyzeTerm( $objectId ) {
		$term = get_term( $objectId );
		$results = [
			'results' => [
				'basic'    => [],
				'advanced' => []
			],
		];

		if ( is_wp_error( $term ) || ! $term || empty( $term->taxonomy ) ) {
			return $results;
		}

		$publicTaxonomies = array_diff( aioseo()->helpers->getPublicTaxonomies( true ), [ 'product_attributes' ] );
		if ( ! in_array( $term->taxonomy, $publicTaxonomies, true ) ) {
			return $results;
		}

		$termLink = get_term_link( $term, $term->taxonomy );
		if ( is_wp_error( $termLink ) || ! $termLink ) {
			return $results;
		}

		$pageParser = new PageParser( $termLink );
		if ( ! $pageParser->hasDocument() ) {
			return $results;
		}

		$results = [
			'basic'    => ( new Checkers\Term\Basic( $objectId, $pageParser, $term->taxonomy ) )->get(),
			'advanced' => ( new Checkers\Term\Advanced( $objectId, $pageParser, $term->taxonomy ) )->get()
		];

		return [
			'results' => $results,
		];
	}

	/**
	 * Get all codes.
	 *
	 * @since 4.8.6
	 *
	 * @return array The list of codes.
	 */
	public function getAllCodes() {
		$codes = [];

		foreach ( $this->groupsAvailable as $type ) {
			$statusArray = $this->{$type . 'CodesStatus'};
			foreach ( $this->getStatusAvailable() as $severity ) {
				if ( ! empty( $statusArray[ $severity ] ) ) {
					foreach ( $statusArray[ $severity ] as $item ) {
						$codes[] = $item['code'];
					}
				}
			}
		}

		return $codes;
	}

	/**
	 * Get the codes by status.
	 *
	 * @since 4.8.6
	 *
	 * @param  string $status The status code (passed, warning, error).
	 * @return array          The list of codes.
	 */
	public function getCodesByStatus( $status ) {
		if ( ! in_array( $status, $this->getStatusAvailable(), true ) ) {
			return [];
		}

		$codes = [];
		foreach ( $this->groupsAvailable as $type ) {
			$statusArray = $this->{$type . 'CodesStatus'};
			if ( ! empty( $statusArray[ $status ] ) ) {
				foreach ( $statusArray[ $status ] as $item ) {
					$codes[] = $item['code'];
				}
			}
		}

		return $codes;
	}

	/**
	 * Get the group by code and status.
	 *
	 * @since 4.8.6
	 *
	 * @param  string      $code   The code.
	 * @param  string      $status The status code (passed, warning, error).
	 * @return string|null         The group name
	 */
	public function getGroupByCodeAndStatus( $code, $status ) {
		if ( ! in_array( $status, $this->getStatusAvailable(), true ) ) {
			return null;
		}

		foreach ( $this->groupsAvailable as $type ) {
			$statusArray = $this->{$type . 'CodesStatus'};
			if ( ! empty( $statusArray[ $status ] ) ) {
				foreach ( $statusArray[ $status ] as $item ) {
					if ( $item['code'] === $code ) {
						return $type;
					}
				}
			}
		}

		return null;
	}

	/**
	 * Get the available status.
	 *
	 * @since 4.8.6
	 *
	 * @return array
	 */
	private function getStatusAvailable() {
		return [ 'passed', 'warning', 'error' ];
	}

	/**
	 * Clear the homepage results.
	 *
	 * @since 4.8.6
	 *
	 * @param  int    $objectId   The object ID.
	 * @param  string $objectType The object type.
	 */
	public function clearHomepageResults( $objectId, $objectType ) {
		if ( ! aioseo()->helpers->isStaticHomePage( $objectId ) ) {
			return;
		}

		Models\Issue::deleteAll( $objectId, $objectType );

		aioseo()->internalOptions->internal->siteAnalysis->score = 0;
		CommonModels\SeoAnalyzerResult::deleteByUrl( null );

		aioseo()->core->cache->delete( 'analyze_site_code' );
		aioseo()->core->cache->delete( 'analyze_site_body' );
	}

	/**
	 * Get all codes sorted by group and status.
	 *
	 * @since 4.8.6
	 *
	 * @param  string|null $status The status code (passed, warning, error).
	 * @return array
	 */
	public function getAllCodesSortedByGroupAndStatus( $status = null ) {
		if ( ! empty( $status ) && ! in_array( $status, $this->getStatusAvailable(), true ) ) {
			return [];
		}

		$result = [];
		foreach ( $this->groupsAvailable as $type ) {
			foreach ( $this->{$type . 'CodesStatus'} as $st => $group ) {
				if ( ! empty( $status ) && $st !== $status ) {
					continue;
				}

				foreach ( $group as $key => $item ) {
					$result[ $type ][ $st ][ $item['code'] ] = $key;
				}
			}
		}

		return $result;
	}

	/**
	 * Get the objects scan percent.
	 *
	 * @since 4.8.6
	 *
	 * @return int The percent completed as an integer.
	 */
	public function getObjectsScanPercent() {
		$posts = $this->getPostsScanPercent();
		$terms = $this->getTermsScanPercent();

		// We need to divide by 2 because we are averaging the two percentages.
		return round( ( $posts + $terms ) / 2 );
	}

	/**
	 * Get the posts scan percent.
	 *
	 * @since 4.8.6
	 *
	 * @return int The percent completed as an integer.
	 */
	private function getPostsScanPercent() {
		$publicPostTypes    = aioseo()->helpers->getScannablePostTypes();
		$publicPostStatuses = aioseo()->helpers->getPublicPostStatuses( true );

		$aioseoPostsTableName = aioseo()->core->db->prefix . 'aioseo_posts';
		$postsTableName       = aioseo()->core->db->prefix . 'posts';

		$implodedPostTypes    = aioseo()->helpers->implodeWhereIn( $publicPostTypes, true );
		$implodedPostStatuses = aioseo()->helpers->implodeWhereIn( $publicPostStatuses, true );

		$totals = aioseo()->core->db->execute(
			aioseo()->core->db->db->prepare(
				"SELECT (
					SELECT count(*)
					FROM {$postsTableName}
					WHERE post_type IN ( $implodedPostTypes )
						AND post_status IN ( $implodedPostStatuses )
				) as totalPosts,
				(
					SELECT count(*)
					FROM {$postsTableName} as p
					LEFT JOIN {$aioseoPostsTableName} as ap ON ap.post_id = p.ID
					WHERE p.post_type IN ( $implodedPostTypes )
						AND p.post_status IN ( $implodedPostStatuses )
						AND ( ap.post_id IS NULL
							OR ap.seo_analyzer_scan_date IS NOT NULL
						)
				) as scannedPosts
				FROM {$postsTableName}
				LIMIT 1"
			),
			true
		)->result();

		if ( ! is_object( $totals[0] ) || 1 > $totals[0]->totalPosts ) {
			return 100;
		}

		return round( 100 * ( $totals[0]->scannedPosts / $totals[0]->totalPosts ) );
	}

	/**
	 * Get the terms scan percent.
	 *
	 * @since 4.8.6
	 *
	 * @return int The percent completed as an integer.
	 */
	private function getTermsScanPercent() {
		$publicTaxonomies = array_diff( aioseo()->helpers->getPublicTaxonomies( true ), [ 'product_attributes' ] );

		$aioseoTermsTableName   = aioseo()->core->db->prefix . 'aioseo_terms';
		$termsTableName         = aioseo()->core->db->prefix . 'terms';
		$termsTaxonomyTableName = aioseo()->core->db->prefix . 'term_taxonomy';

		$implodedPublicTaxonomies = aioseo()->helpers->implodeWhereIn( $publicTaxonomies, true );

		$totals = aioseo()->core->db->execute(
			aioseo()->core->db->db->prepare(
				"SELECT (
					SELECT count(*)
					FROM {$termsTableName} as t
					INNER JOIN {$termsTaxonomyTableName} as tt ON tt.term_id = t.term_id
					WHERE tt.taxonomy IN ( $implodedPublicTaxonomies )
				) as totalTerms,
				(
					SELECT count(*)
					FROM {$termsTableName} as t
					INNER JOIN {$termsTaxonomyTableName} as tt ON tt.term_id = t.term_id
					LEFT JOIN {$aioseoTermsTableName} as at ON at.term_id = t.term_id
					WHERE tt.taxonomy IN ( $implodedPublicTaxonomies )
						AND ( at.term_id IS NULL
							OR at.seo_analyzer_scan_date IS NOT NULL
						)
				) as scannedTerms
				FROM {$termsTableName}
				LIMIT 1"
			),
			true
		)->result();

		if ( ! is_object( $totals[0] ) || 1 > $totals[0]->totalTerms ) {
			return 100;
		}

		return round( 100 * ( $totals[0]->scannedTerms / $totals[0]->totalTerms ) );
	}

	/**
	 * Enqueues a post page to be scanned by the SEO Analyzer.
	 *
	 * @since 4.8.7
	 *
	 * @param  int  $postId The post id.
	 * @return void
	 */
	public function enqueuePostToScan( $postId ) {
		$postType           = get_post_type( (int) $postId );
		$postTypesToExclude = apply_filters( 'aioseo_seo_analyzer_scan_post_types_to_exclude', [ 'scheduled-action', 'revision', 'attachment' ] );
		if (
			in_array( $postType, $postTypesToExclude, true ) ||
			! aioseo()->helpers->isPostTypePublic( $postType )
		) {
			return;
		}

		$aioseoPost = CommonModels\Post::getPost( $postId );
		$aioseoPost->seo_analyzer_scan_date = null;
		$aioseoPost->save();

		// Delete issues
		Models\Issue::deleteAll( $postId, 'post' );
	}

	/**
	 * Checks if the current user can fix a specific issue code.
	 *
	 * @since 4.8.9
	 *
	 * @param  string $issueCode The issue code to check.
	 * @return bool              Whether the user can fix this issue.
	 */
	public function userCanFixIssue( $issueCode ) {
		$capabilities = $this->getIssueCapabilities( $issueCode );

		if ( empty( $capabilities ) ) {
			return false;
		}

		// Check each required capability
		foreach ( $capabilities as $capability ) {
			if ( false !== strpos( $capability, 'aioseo_' ) ) {
				if ( ! aioseo()->access->hasCapability( $capability ) ) {
					return false;
				}
			} else {
				if ( ! current_user_can( $capability ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Gets capabilities for a specific issue code.
	 *
	 * @since 4.8.9
	 *
	 * @param  string $issueCode The issue code.
	 * @return array             Array of capabilities or empty array.
	 */
	private function getIssueCapabilities( $issueCode ) {
		foreach ( $this->groupsAvailable as $type ) {
			$statusArray = $this->{$type . 'CodesStatus'};

			foreach ( $this->getStatusAvailable() as $severity ) {
				if ( ! empty( $statusArray[ $severity ] ) ) {
					foreach ( $statusArray[ $severity ] as $issue ) {
						if ( $issue['code'] === $issueCode ) {
							return $issue['capabilities'];
						}
					}
				}
			}
		}

		return [];
	}

	/**
	 * Gets the required capabilities for a specific issue code.
	 *
	 * @since 4.8.9
	 *
	 * @param  string $code The issue code to check capabilities for.
	 * @return array        Array of required capabilities or empty array if code not found.
	 */
	private function getCapabilitiesByCode( $code ) {
		// Search through all code status arrays
		foreach ( $this->groupsAvailable as $type ) {
			$statusArray = $this->{$type . 'CodesStatus'};

			// Search through all severity levels
			foreach ( $this->getStatusAvailable() as $severity ) {
				if ( ! empty( $statusArray[ $severity ] ) ) {
					foreach ( $statusArray[ $severity ] as $item ) {
						if ( isset( $item['code'] ) && $item['code'] === $code ) {
							return $item['capabilities'];
						}
					}
				}
			}
		}

		return [];
	}

	/**
	 * Checks if the current user has the required capabilities for an issue.
	 *
	 * @since 4.8.9
	 *
	 * @param  string $code The issue code to check capabilities for.
	 * @return bool         Whether the user has the required capabilities.
	 */
	public function userCanFixIssueByCode( $code ) {
		$capabilities = $this->getCapabilitiesByCode( $code );

		if ( empty( $capabilities ) ) {
			return true;
		}

		// Check each required capability
		foreach ( $capabilities as $capability ) {
			// For AIOSEO capabilities, use the access system
			if ( false !== strpos( $capability, 'aioseo_' ) ) {
				if ( ! aioseo()->access->hasCapability( $capability ) ) {
					return false;
				}
			} else {
				// For WordPress capabilities, use current_user_can
				if ( ! current_user_can( $capability ) ) {
					return false;
				}
			}
		}

		return true;
	}
}