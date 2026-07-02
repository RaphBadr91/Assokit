<?php
namespace AIOSEO\Plugin\Pro\ImportExport\RankMath;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\ImportExport\RankMath as CommonRankMath;
use AIOSEO\Plugin\Pro\ImportExport;

// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

class RankMath extends CommonRankMath\RankMath {
	/**
	 * Class constructor.
	 *
	 * @since 4.0.0
	 *
	 * @param ImportExport\ImportExport $importer the ImportExport class.
	 */
	public function __construct( $importer ) {
		parent::__construct( $importer );

		// The Pro class will overwrite the Common class, so we need to remove it here.
		remove_action( $this->postMeta->postActionName, [ $this->postMeta, 'importPostMeta' ] );

		$this->postMeta = new PostMeta();
		$this->termMeta = new TermMeta();
	}

	/**
	 * Starts the import.
	 *
	 * @since 4.0.0
	 *
	 * @param  array $options What the user wants to import.
	 * @return void
	 */
	public function doImport( $options = [] ) {
		parent::doImport( $options );
		if ( empty( $options ) ) {
			$this->termMeta->scheduleImport();

			return;
		}

		foreach ( $options as $optionName ) {
			switch ( $optionName ) {
				case 'termMeta':
					$this->termMeta->scheduleImport();
					break;
				default:
					break;
			}
		}
	}

	/**
	 * Imports the settings.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	protected function importSettings() {
		parent::importSettings();

		new GeneralSettings();
		new TitleMeta();
	}
}