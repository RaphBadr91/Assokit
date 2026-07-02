<?php
namespace AIOSEO\Plugin\Pro\SeoRevisions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keep helper methods for SEO Revisions.
 *
 * @since 4.4.0
 */
class Helpers {
	/**
	 * Reduce the 'keyphrases' field to an array containing the 'focus' and 'additional' keys.
	 * Get rid of other array keys as e.g. 'score' and 'analysis'.
	 *
	 * @since 4.4.0
	 *
	 * @param  array $revisionData The Revision Data (passed by reference).
	 * @return void
	 */
	public function reduceKeyphrases( &$revisionData ) {
		if ( array_key_exists( 'keyphrases', $revisionData ) ) {
			$reduced = [
				'focus'      => '',
				'additional' => [],
			];
			foreach ( (array) $revisionData['keyphrases'] as $k => $keyphrase ) {
				$keyphrase = (array) $keyphrase;

				if ( 'focus' === $k ) {
					$reduced['focus'] = $keyphrase['keyphrase'] ?? '';
				} elseif ( 'additional' === $k ) {
					$additionalPhrases = array_map( 'trim', array_column( $keyphrase, 'keyphrase' ) );

					sort( $additionalPhrases );

					$reduced['additional'] = $additionalPhrases;
				}
			}

			$revisionData['keyphrases'] = $reduced;
		}
	}

	/**
	 * Reduce a field to an array containing only the `$column` key values.
	 *
	 * @since 4.6.5
	 *
	 * @param  array $revisionData The Revision Data (passed by reference).
	 * @return void
	 */
	public function reduceToColumn( &$revisionData, $field, $column ) {
		if ( array_key_exists( $field, $revisionData ) ) {
			$reduced = [];
			foreach ( (array) $revisionData[ $field ] as $value ) {
				if ( is_object( $value ) && isset( $value->$column ) ) {
					$reduced[] = $value->$column;
				} elseif ( is_array( $value ) && isset( $value[ $column ] ) ) {
					$reduced[] = $value[ $column ];
				} elseif ( is_string( $value ) || is_numeric( $value ) ) {
					// If the value is already a scalar, use it directly.
					$reduced[] = $value;
				}
			}

			sort( $reduced );

			$revisionData[ $field ] = array_filter( array_map( 'trim', $reduced ) );
		}
	}

	/**
	 * Reduce the 'schema' field to an array containing the 'customGraphs', 'default' and 'graphs' keys.
	 * Get rid of other keys as e.g. 'blockGraphs'.
	 *
	 * @since 4.4.0
	 *
	 * @param  array $revisionData The Revision Data (passed by reference).
	 * @return void
	 */
	public function reduceSchema( &$revisionData ) {
		if ( array_key_exists( 'schema', $revisionData ) ) {
			$reduced = [
				'default'      => [],
				'graphs'       => [],
				'customGraphs' => []
			];
			foreach ( (array) $revisionData['schema'] as $k => $data ) {
				if ( 'default' === $k ) {
					// Prevent `$data['graphName']` from being watched.
					$reduced[ $k ] = array_diff_key( $data, [ 'graphName' => '' ] );
				}

				if ( in_array( $k, [ 'graphs', 'customGraphs' ], true ) ) {
					$reduced[ $k ] = $data;
				}
			}

			aioseo()->helpers->arrayRecursiveKsort( $reduced );

			$revisionData['schema'] = $reduced;
		}
	}

	/**
	 * Retrieve the formatted Revision Data array for comparison.
	 * This was first created, so we have the Focus/Additional Keyphrases fields separate on the UI.
	 *
	 * @since 4.4.0
	 *
	 * @param  array           $data            The Revision Data.
	 * @param  ObjectRevisions $objectRevisions An instance of the ObjectRevisions class.
	 * @return array                            The formatted Revision Data.
	 */
	public function formatRevisionData( $data, $objectRevisions ) {
		$wpObject = $objectRevisions->getWpObject();

		// 1. Before filling the fake columns 'focus' and 'additional' check if 'keyphrases' is present otherwise they'd show for Terms.
		if ( isset( $data['keyphrases'] ) ) {
			$data['focus']      = isset( $data['keyphrases']['focus'] ) ? $data['keyphrases']['focus'] : '';
			$data['additional'] = isset( $data['keyphrases']['additional'] ) ? $data['keyphrases']['additional'] : '';
		}

		// 2. Fill the fake column 'robots_all_settings'.
		$data['robots_all_settings'] = [
			'default' => ! empty( $data['robots_default'] )
		];

		// 3. If 'robots_default' is not true, then show the chosen custom settings.
		if ( empty( $data['robots_default'] ) ) {
			$data['robots_all_settings'] = [
				'default'         => ! empty( $data['robots_default'] ),
				'noindex'         => ! empty( $data['robots_noindex'] ),
				'nofollow'        => ! empty( $data['robots_nofollow'] ),
				'noarchive'       => ! empty( $data['robots_noarchive'] ),
				'notranslate'     => ! empty( $data['robots_notranslate'] ),
				'noimageindex'    => ! empty( $data['robots_noimageindex'] ),
				'nosnippet'       => ! empty( $data['robots_nosnippet'] ),
				'noodp'           => ! empty( $data['robots_noodp'] ),
				'maxsnippet'      => isset( $data['robots_max_snippet'] ) ? $data['robots_max_snippet'] : '',
				'maxvideopreview' => isset( $data['robots_max_videopreview'] ) ? $data['robots_max_videopreview'] : '',
				'maximagepreview' => isset( $data['robots_max_imagepreview'] ) ? $data['robots_max_imagepreview'] : ''
			];
		}

		// 4. Don't ever show these fields alone on the UI.
		unset( $data['keyphrases'] );
		unset( $data['robots_default'] );
		unset( $data['robots_noindex'] );
		unset( $data['robots_noarchive'] );
		unset( $data['robots_nosnippet'] );
		unset( $data['robots_nofollow'] );
		unset( $data['robots_noimageindex'] );
		unset( $data['robots_noodp'] );
		unset( $data['robots_notranslate'] );
		unset( $data['robots_max_snippet'] );
		unset( $data['robots_max_videopreview'] );
		unset( $data['robots_max_imagepreview'] );

		// 5. Don't show certain fields for attachments.
		if ( 'attachment' === ( $wpObject->post_type ?? '' ) ) {
			unset( $data['pillar_content'] );
		}

		// 6. Prepare 'breadcrumb_settings' for the UI.
		if ( array_key_exists( 'breadcrumb_settings', $data ) ) {
			// If 'breadcrumb_settings' is set to the default value, its value is actually `null`, and the `default` key is not present,
			// so we need this workaround for making sure {@see $this->prepareRevisionDataFieldValue()} does its job.
			$data['breadcrumb_settings'] = is_null( $data['breadcrumb_settings'] )
				? [ 'default' => true ]
				: $data['breadcrumb_settings'];
		}

		return $data;
	}

	/**
	 * Prepare the Revision Data field value for comparison rendering in Vue.
	 * Useful so the user sees something similar to what is displayed while editing a post/term.
	 *
	 * @since 4.4.0
	 *
	 * @param  string          $field           The Revision Data field. E.g. 'title' or 'description'.
	 * @param  string|array    $value           The field value.
	 * @param  ObjectRevisions $objectRevisions An instance of the ObjectRevisions class.
	 * @return string                           The formatted value.
	 */
	public function prepareRevisionDataFieldValue( $field, $value, $objectRevisions ) {
		$wpObject = $objectRevisions->getWpObject();
		if ( aioseo()->helpers->isWooCommerceProductAttribute( $wpObject ) ) {
			$wpObject->taxonomy = 'product_attributes';
		}

		switch ( $field ) {
			case 'title':
			case 'og_title':
			case 'twitter_title':
				if ( empty( $value ) ) {
					$value = 'term' === $objectRevisions->objectType
						? aioseo()->meta->title->getTaxonomyTitle( $wpObject->taxonomy )
						: aioseo()->meta->title->getPostTitle( $objectRevisions->objectId, true );
				}
				$value = aioseo()->helpers->decodeHtmlEntities( $value );
				break;
			case 'description':
			case 'og_description':
			case 'twitter_description':
				if ( empty( $value ) ) {
					$value = 'term' === $objectRevisions->objectType
						? aioseo()->meta->description->getTaxonomyDescription( $wpObject->taxonomy )
						: aioseo()->meta->description->getPostDescription( $objectRevisions->objectId, true );
				}
				$value = aioseo()->helpers->decodeHtmlEntities( $value );
				break;
			case 'og_object_type':
			case 'twitter_card':
				if ( ! empty( $value ) ) {
					$value = "{optionValue|$value}"; // Is replaced with option label in Vue.
				}
				break;
			case 'og_image_type':
			case 'twitter_image_type':
				if ( ! empty( $value ) ) {
					aioseo()->social->image->useCache = false;

					$type  = strpos( $field, 'twitter' ) !== false ? 'twitter' : 'facebook';
					$image = aioseo()->social->image->getImage( $type, $value, $wpObject );
					$url   = is_array( $image ) ? $image[0] : $image;

					$value = "{optionValue|$value}"; // Is replaced with the option label in Vue.
					$value .= "{imageUrl|$url}"; // Is replaced with the image preview in Vue.
				}
				break;
			case 'og_image_custom_url':
			case 'twitter_image_custom_url':
				if ( ! empty( $value ) ) {
					$value = "$value{imageUrl|$value}"; // Is replaced with the image preview in Vue.
				}
				break;
			case 'canonical_url':
				if ( empty( $value ) ) {
					// Translators: 1 - The string 'Post' or 'Term'.
					$value = sprintf( esc_html__( 'Default (the %s URL)', 'aioseo-pro' ), ucfirst( $objectRevisions->objectType ) );
				}
				break;
			case 'focus':
				$value = empty( $value['keyphrase'] ) ? '' : $value['keyphrase'];
				break;
			case 'additional':
			case 'og_article_tags':
			case 'keywords':
				$searchKey = 'additional' === $field ? 'keyphrase' : 'label';
				$items     = ! empty( $value[0][ $searchKey ] ) ? array_map( 'trim', array_column( $value, $searchKey ) ) : [];

				sort( $items );

				$value = implode( "\n", $items );
				$value = esc_html( $value );
				break;
			case 'schema':
				aioseo()->schema->reset();

				$value = aioseo()->schema->getUserDefinedSchemaOutput( $objectRevisions->objectId, $value );
				break;
			case 'twitter_use_og':
			case 'pillar_content':
				if ( is_bool( $value ) ) {
					$value = true === $value
						? esc_html__( 'Yes', 'aioseo-pro' )
						: esc_html__( 'No', 'aioseo-pro' );
				}

				break;
			case 'robots_all_settings':
				$settings = [];
				foreach ( $value as $optionName => $optionValue ) {
					$settings[] = '{' . "$optionName|$optionValue" . '}';
				}

				$value = implode( "\n", $settings );
				break;
			case 'priority':
				$value = is_null( $value ) ? 'default' : strval( $value );
				break;
			case 'primary_term':
				if ( is_array( $value ) ) {
					ksort( $value );

					$items = [];
					foreach ( $value as $taxonomy => $termId ) {
						// Fallback to the raw term ID and taxonomy key if the term object or taxonomy object is not available anymore.
						$items[ $taxonomy ] = $taxonomy . ': ' . $termId;

						$termObject     = get_term( $termId, $taxonomy );
						$taxonomyObject = get_taxonomy( $taxonomy );

						// If the term object and taxonomy object are available, use the term name and taxonomy singular name.
						if ( is_object( $termObject ) && is_object( $taxonomyObject ) ) {
							$items[ $taxonomy ] = ( $taxonomyObject->labels->singular_name ?? $termObject->taxonomy ) . ': ' . $termObject->name;
						}
					}

					$value = implode( "\n", $items );
					$value = esc_html( $value );
				}
				break;
			case 'breadcrumb_settings':
				if ( is_array( $value ) ) {
					$settings = [];
					foreach ( $value as $optionName => $optionValue ) {
						if ( 'taxonomy' === $optionName ) {
							$wpTaxonomy = get_taxonomy( $optionValue );
							$optionValue = is_object( $wpTaxonomy ) ? ( $wpTaxonomy->labels->singular_name ?? $optionValue ) : $optionValue;
						}

						$settings[] = '{' . "$optionName|$optionValue" . '}';
					}

					$value = implode( "\n", $settings );
					$value = esc_html( $value );
				}
				break;
			default:
				if ( is_null( $value ) ) {
					$value = '';
				}
				break;
		}

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Retrieve a human-readable HTML representation of the difference.
	 *
	 * @since 4.4.0
	 *
	 * @param  string $leftString  "old" (left) version.
	 * @param  string $rightString "new" (right) version.
	 * @param  string $field       The Revision Data field.
	 * @return string              Empty string if parameters are equivalent or HTML with differences.
	 */
	public function renderFieldDiff( $leftString, $rightString, $field ) {
		$args = [
			'show_split_view' => true,
		];

		switch ( $field ) {
			case 'additional':
			case 'og_article_tags':
			case 'keywords':
			case 'primary_term':
				$diff = $this->getTagsDiff( $leftString, $rightString );
				break;
			case 'schema':
				$diff = $this->getSchemaDiff( $leftString, $rightString );
				break;
			case 'robots_all_settings':
			case 'breadcrumb_settings':
				$diff = $this->getSettingsDiff( $leftString, $rightString );
				break;
			default:
				$leftString  = normalize_whitespace( $leftString );
				$rightString = normalize_whitespace( $rightString );

				$leftLines  = explode( "\n", $leftString );
				$rightLines = explode( "\n", $rightString );

				$textDiff = new \Text_Diff( $leftLines, $rightLines );
				$renderer = new TextDiffRendererTable( $args );
				$diff     = $renderer->render( $textDiff );
				break;
		}

		if ( ! $diff ) {
			return '';
		}

		$isSplitView      = ! empty( $args['show_split_view'] );
		$isSplitViewClass = $isSplitView ? ' is-split-view' : '';

		$output = "<table class='diff$isSplitViewClass'>";
		$output .= "<tbody>$diff</tbody>";
		$output .= '</table>';

		// Replace e.g. `#<del>site_title</del>` with `<del>#site_title</del>`.
		return preg_replace( "/#<([a-z]+?)>([a-zA-Z0-9_ -]{3,}?)<\/\\1>/", '<$1>#$2</$1>', (string) $output );
	}

	/**
	 * Parse the new Revision Data before using it under other operations.
	 *
	 * @since 4.4.0
	 *
	 * @param  array $newData The new Revision Data (passed by reference).
	 * @return void
	 */
	public function parseNewRevisionData( &$newData ) {
		foreach ( $newData as $key => &$value ) {
			switch ( $key ) {
				case 'schema':
				case 'primary_term':
				case 'breadcrumb_settings':
					$value = json_decode( wp_json_encode( $value ), true );
					break;
				default:
					break;
			}
		}
	}

	/**
	 * Retrieve the inner `<tbody>` HTML representation of the difference.
	 *
	 * @since 4.4.0
	 *
	 * @param  string $leftString  "old" (left) version.
	 * @param  string $rightString "new" (right) version.
	 * @return string              Empty string if parameters are equivalent or HTML with differences.
	 */
	private function getTagsDiff( $leftString, $rightString ) {
		$leftHash  = trim( serialize( $leftString ) );
		$rightHash = trim( serialize( $rightString ) );
		if ( $leftHash === $rightHash ) {
			return '';
		}

		$leftAdditional  = explode( "\n", $leftString );
		$rightAdditional = explode( "\n", $rightString );

		$html = '<tr>';
		$html .= "<td class='diff-deletedline'>";
		$html .= "<span class='dashicons dashicons-minus'></span>";

		$innerData = [];
		foreach ( $leftAdditional as $leftPhrase ) {
			if ( ! in_array( $leftPhrase, $rightAdditional, true ) ) {
				$innerData[] = "<del><span>$leftPhrase</span></del>";
			} else {
				$innerData[] = "<span>$leftPhrase</span>";
			}
		}

		$html .= implode( "\n", $innerData );
		$html .= '</td>';
		$html .= "<td class='diff-addedline'>";
		$html .= "<span class='dashicons dashicons-plus'></span>";

		$innerData = [];
		foreach ( $rightAdditional as $rightPhrase ) {
			if ( ! in_array( $rightPhrase, $leftAdditional, true ) ) {
				$innerData[] = "<ins><span>$rightPhrase</span></ins>";
			} else {
				$innerData[] = "<span>$rightPhrase</span>";
			}
		}

		$html .= implode( "\n", $innerData );
		$html .= '</td>';

		return $html;
	}

	/**
	 * Retrieve the inner `<tbody>` HTML representation of the difference.
	 *
	 * @since   4.4.0
	 * @version 4.8.7 Renamed from getRobotsSettingDiff to getSettingsDiff.
	 *
	 * @param  string $leftString  "old" (left) version.
	 * @param  string $rightString "new" (right) version.
	 * @return string              Empty string if parameters are equivalent or HTML with differences.
	 */
	private function getSettingsDiff( $leftString, $rightString ) {
		$leftHash  = trim( serialize( $leftString ) );
		$rightHash = trim( serialize( $rightString ) );
		if ( $leftHash === $rightHash ) {
			return '';
		}

		$html = '<tr>';
		$html .= "<td class='diff-deletedline'>";
		$html .= "<span class='dashicons dashicons-minus'></span>";
		$html .= $leftString;
		$html .= '</td>';
		$html .= "<td class='diff-addedline'>";
		$html .= "<span class='dashicons dashicons-plus'></span>";
		$html .= $rightString;
		$html .= '</td>';

		return $html;
	}

	/**
	 * Retrieve the inner `<tbody>` HTML representation of the difference.
	 *
	 * @since 4.4.0
	 *
	 * @param  string $leftString  "old" (left) version.
	 * @param  string $rightString "new" (right) version.
	 * @return string              Empty string if parameters are equivalent or HTML with differences.
	 */
	private function getSchemaDiff( $leftString, $rightString ) {
		$leftHash  = trim( serialize( $leftString ) );
		$rightHash = trim( serialize( $rightString ) );
		if ( $leftHash === $rightHash ) {
			return '';
		}

		$leftJson    = json_decode( $leftString, true );
		$rightJson   = json_decode( $rightString, true );
		$leftSchema  = '';
		$rightSchema = '';

		if ( ! empty( $leftJson['@graph'] ) ) {
			$leftJson   = $this->schemaDiff( $leftJson, $rightJson, 'del' );
			$leftSchema = wp_json_encode( $leftJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		if ( ! empty( $rightJson['@graph'] ) ) {
			$rightJson   = $this->schemaDiff( $rightJson, $leftJson );
			$rightSchema = wp_json_encode( $rightJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		$html = '<tr>';
		$html .= "<td class='diff-deletedline'>";
		$html .= "<span class='dashicons dashicons-minus'></span>";
		$html .= "<pre>$leftSchema</pre>";
		$html .= '</td>';
		$html .= "<td class='diff-addedline'>";
		$html .= "<span class='dashicons dashicons-plus'></span>";
		$html .= "<pre>$rightSchema</pre>";
		$html .= '</td>';

		return $html;
	}

	/**
	 * Compares schemaFrom to schemaTo and adds appropriate ins or del to schemaFrom.
	 *
	 * @since 4.4.0
	 *
	 * @param  array  $schemaFrom The schema array from.
	 * @param  array  $schemaTo   The schema array to.
	 * @param  string $insOrDel   The ins or del.
	 * @return array              The schema from with added ins or del tags to values.
	 */
	private function schemaDiff( $schemaFrom, $schemaTo, $insOrDel = 'ins' ) {
		$schemaToTypes = array_column( ! empty( $schemaTo['@graph'] ) ? (array) $schemaTo['@graph'] : [], '@type' );

		foreach ( $schemaFrom['@graph'] as &$graph ) {
			if ( ! in_array( $graph['@type'], $schemaToTypes, true ) ) {
				$this->addInsDelToSchemaGraph( $graph, $insOrDel );
			} else {
				$toGraphItems = $schemaTo['@graph'][ array_search( $graph['@type'], $schemaToTypes, true ) ];
				foreach ( $graph as $key => &$value ) {
					if (
						! isset( $toGraphItems[ $key ] ) ||
						$toGraphItems[ $key ] !== $value
					) {
						$this->addInsDelToSchemaGraph( $value, $insOrDel );
					}
				}
			}
		}

		return $schemaFrom;
	}

	/**
	 * Add `<ins>` or `<del>` tag to the given graph values.
	 *
	 * @since 4.4.0
	 *
	 * @param  array|string $graph    The graph to add the `<del>` tag to.
	 * @param  string       $insOrDel The ins or del.
	 * @return void
	 */
	private function addInsDelToSchemaGraph( &$graph, $insOrDel = 'ins' ) {
		if ( ! is_array( $graph ) ) {
			$graph = 'ins' === $insOrDel ? "<ins>$graph</ins>" : "<del>$graph</del>";
		} else {
			foreach ( $graph as $key => &$value ) {
				if ( is_array( $value ) ) {
					$this->addInsDelToSchemaGraph( $value, $insOrDel );
				} else {
					$graph[ $key ] = 'ins' === $insOrDel ? "<ins>$value</ins>" : "<del>$value</del>";
				}
			}
		}
	}
}