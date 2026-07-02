<?php
namespace AIOSEO\Plugin\Pro\Models;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Models as CommonModels;

/**
 * The SEO Revision DB Model.
 *
 * @since 4.4.0
 */
class SeoRevision extends CommonModels\Model {
	/**
	 * Database table name with no prefix.
	 *
	 * @since 4.4.0
	 *
	 * @var string
	 */
	protected $table = 'aioseo_revisions';

	/**
	 * Fields that should be numeric values.
	 *
	 * @since 4.4.0
	 *
	 * @var array
	 */
	protected $integerFields = [ 'id', 'object_id', 'author_id' ];

	/**
	 * Fields that should be json encoded on save and decoded on get.
	 *
	 * @since 4.4.0
	 *
	 * @var array
	 */
	protected $jsonFields = [ 'revision_data' ];

	/**
	 * Fields that should be boolean values.
	 *
	 * @since 4.4.0
	 *
	 * @var array
	 */
	protected $booleanFields = [];

	/**
	 * Fields that should be hidden when serialized.
	 *
	 * @since 4.4.0
	 *
	 * @var array
	 */
	protected $hidden = [ 'id' ];

	/**
	 * Column: Object ID.
	 *
	 * @since 4.4.0
	 *
	 * @var int $object_id
	 */
	public $object_id;

	/**
	 * Column: Object Type. Allowed: 'post' or 'term'.
	 *
	 * @since 4.4.0
	 *
	 * @var string $object_type
	 */
	public $object_type;

	/**
	 * Column: Revision data.
	 *
	 * @since 4.4.0
	 *
	 * @var string $revision_data
	 */
	public $revision_data;

	/**
	 * Column: Author ID.
	 *
	 * @since 4.4.0
	 *
	 * @var int $author_id
	 */
	public $author_id;

	/**
	 * Column: Created.
	 *
	 * @since 4.4.0
	 *
	 * @var string $created
	 */
	public $created;

	/**
	 * Column: Updated.
	 *
	 * @since 4.4.0
	 *
	 * @var string $updated
	 */
	public $updated;

	/**
	 * Column: Note.
	 *
	 * @since 4.4.0
	 *
	 * @var string $note
	 */
	public $note;

	/**
	 * Transforms data as needed.
	 *
	 * @since 4.4.0
	 *
	 * @param  array $data The data array to transform.
	 * @return array       The transformed data.
	 */
	protected function transform( $data, $set = false ) {
		$data = parent::transform( $data, $set );

		foreach ( $this->getColumns() as $column => $null ) {
			if ( 'id' === $column ) {
				continue;
			}

			if ( isset( $data[ $column ] ) ) {
				$data[ $column ] = $this->sanitize( $column, $data[ $column ] );
			}
		}

		return $data;
	}

	/**
	 * Format a revision object.
	 *
	 * @since 4.4.0
	 *
	 * @return array Parsed row with manipulated data or an empty array.
	 */
	public function formatRevision() {
		if ( ! $this->exists() ) {
			return [];
		}

		$objectCommonData = $this->getObjectCommonData();
		$authorData       = aioseo()->helpers->getUserData( $this->author_id );
		$authorAvatarData = get_avatar_data( $this->author_id, [
			'size'    => 32,
			'default' => 'mystery'
		] );
		$dateFormat       = get_option( 'date_format', 'd M' );
		$timeFormat       = get_option( 'time_format', 'H:i' );
		$dateTimeFormat   = $dateFormat . ' @ ' . $timeFormat;

		return [
			'id'     => $this->id,
			'object' => [
				'id'    => $this->object_id,
				'type'  => $this->object_type,
				'title' => $objectCommonData['title']
			],
			'author' => [
				'avatar'       => [
					'size' => absint( $authorAvatarData['size'] ),
					'url'  => $authorAvatarData['found_avatar'] ? esc_url( $authorAvatarData['url'] ) : strval( get_avatar_url( 0, $authorAvatarData ) )
				],
				'display_name' => isset( $authorData->display_name ) ? trim( $authorData->display_name ) : '',
				'login'        => isset( $authorData->user_login ) ? trim( $authorData->user_login ) : '',
				'email'        => isset( $authorData->user_email ) ? trim( $authorData->user_email ) : ''
			],
			'date'   => [
				'created_formatted' => sprintf(
					// Translators: 1 - Time since the revision has been created, 2 - Exact date when the revision was created.
					esc_html__( '%1$s ago (%2$s)', 'aioseo-pro' ),
					human_time_diff( strtotime( $this->created ) ),
					date_i18n( $dateTimeFormat, strtotime( get_date_from_gmt( $this->created ) ) )
				),
			],
			'note'   => $this->note,
			'urls'   => [
				'edit_object' => $objectCommonData['edit_object_url']
			]
		];
	}

	/**
	 * Retrieve common info from an object no matter if it's a post or term.
	 *
	 * @since 4.4.0
	 *
	 * @return array An array with all extracted data from the object.
	 */
	public function getObjectCommonData() {
		$data = [
			'id'              => $this->object_id,
			'type'            => $this->object_type,
			'title'           => '',
			'edit_object_url' => ''
		];

		switch ( $this->object_type ) {
			case 'post':
				$data['title']           = get_the_title( $this->object_id );
				$data['edit_object_url'] = get_edit_post_link( $this->object_id, 'url' );
				break;
			case 'term':
				$term = get_term( $this->object_id ); // Don't use getTerm() helper here because we otherwise
				// will not get a valid URL for product attributes.

				$data['title']           = $term->name;
				$data['edit_object_url'] = get_edit_term_link( $this->object_id, $term->taxonomy );
				break;
			default:
				break;
		}

		return $data;
	}

	/**
	 * Returns the revision data as an array.
	 *
	 * @since 4.4.0
	 *
	 * @return array
	 */
	public function getRevisionData() {
		return json_decode( wp_json_encode( $this->revision_data ), true );
	}

	/**
	 * Sanitize Model field value.
	 *
	 * @since 4.4.0
	 *
	 * @param  string $field Which field to sanitize.
	 * @param  mixed  $value The value to be sanitized.
	 * @return mixed         The sanitized value.
	 */
	private function sanitize( $field, $value ) {
		switch ( $field ) {
			case 'author_id':
			case 'object_id':
				$value = absint( $value );
				break;
			case 'object_type':
				if ( 'term' !== $value ) {
					$value = 'post';
				}
				break;
			case 'note':
				$value = sanitize_text_field( $value );
				$value = aioseo()->helpers->truncate( $value, aioseo()->seoRevisions->options['note']['maxlength'], false );
				$value = $value ?: null;
				break;
			default:
				break;
		}

		return $value;
	}
}