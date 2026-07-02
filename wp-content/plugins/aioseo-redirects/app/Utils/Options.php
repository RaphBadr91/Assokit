<?php
namespace AIOSEO\Plugin\Addon\Redirects\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Traits;

/**
 * Class that holds all options for AIOSEO.
 *
 * @since 1.0.0
 */
class Options {
	use Traits\Options;

	/**
	 * All the default options.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $defaults = [
		// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		'main'             => [
			'method' => [ 'type' => 'string', 'default' => 'php' ]
		],
		'server'           => [
			'autoWriteHtaccess' => [ 'type' => 'boolean', 'default' => false ]
		],
		'logs'             => [
			'log404'     => [
				'enabled' => [ 'type' => 'boolean', 'default' => true ],
				'length'  => [ 'type' => 'string', 'default' => '{"label":"1 week","value":"week"}' ]
			],
			'redirects'  => [
				'enabled' => [ 'type' => 'boolean', 'default' => true ],
				'length'  => [ 'type' => 'string', 'default' => '{"label":"1 week","value":"week"}' ]
			],
			'external'   => [ 'type' => 'boolean', 'default' => false ],
			'httpHeader' => [ 'type' => 'boolean', 'default' => false ],
			'ipAddress'  => [
				'enabled' => [ 'type' => 'boolean', 'default' => true ],
				'level'   => [ 'type' => 'string', 'default' => '{"label":"Full Logging","value":"full"}' ]
			]
		],
		'monitor'          => [
			'postTypes' => [
				'all'      => [ 'type' => 'boolean', 'default' => false ],
				'included' => [ 'type' => 'array', 'default' => [] ]
			],
			'trash'     => [ 'type' => 'boolean', 'default' => false ]
		],
		'cache'            => [
			'httpHeader'  => [
				'enabled' => [ 'type' => 'boolean', 'default' => true ],
				'length'  => [ 'type' => 'string', 'default' => '{"label":"1 hour","value":"hour"}' ]
			],
			'objectCache' => [ 'type' => 'boolean', 'default' => false ],
		],
		'redirectDefaults' => [
			'ignoreCase'   => [ 'type' => 'boolean', 'default' => true ],
			'ignoreSlash'  => [ 'type' => 'boolean', 'default' => true ],
			'redirectType' => [ 'type' => 'string', 'default' => '{"label":"301 Moved Permanently","value":301}' ],
			'queryParam'   => [
				'type'    => 'string',
				'default' => '{"label":"Exact match all parameters in any order","value":"exact"}'
			]
		],
		'fullSite'         => [
			'relocate'    => [
				'enabled'   => [ 'type' => 'boolean', 'default' => false ],
				'newDomain' => [ 'type' => 'string', 'default' => '' ]
			],
			'aliases'     => [ 'type' => 'array', 'default' => [] ],
			'canonical'   => [
				'enabled'         => [ 'type' => 'boolean', 'default' => false ],
				'httpToHttps'     => [ 'type' => 'boolean', 'default' => false ],
				'preferredDomain' => [ 'type' => 'string', 'default' => '' ]
			],
			'httpHeaders' => [ 'type' => 'array', 'default' => [] ]
		],
		'advanced404s'     => [
			'enabled'                     => [ 'type' => 'boolean', 'default' => false ],
			'redirectDefaultEnabled'      => [ 'type' => 'boolean', 'default' => false ],
			'redirectDefault'             => [ 'type' => 'string', 'default' => 'home' ],
			'redirectToParent'            => [ 'type' => 'boolean', 'default' => false ],
			'redirectToParentWoocommerce' => [ 'type' => 'boolean', 'default' => false ],
			'redirectToCustomUrl'         => [ 'type' => 'string', 'default' => '' ],
			'redirectToSmart'             => [ 'type' => 'boolean', 'default' => false ]
		]
		// phpcs:enable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	];

	/**
	 * The Construct method.
	 *
	 * @since 1.0.0
	 *
	 * @param string $optionsName An array of options.
	 */
	public function __construct( $optionsName = 'aioseo_redirect_options' ) {
		$this->optionsName = $optionsName;

		$this->init();

		add_action( 'shutdown', [ $this, 'save' ] );
	}

	/**
	 * Initializes the options.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function init() {
		add_action( 'init', [ $this, 'translateDefaults' ] );

		$options = $this->getRedirectDbOptions();

		aioseo()->core->optionsCache->setOptions( $this->optionsName, apply_filters( 'aioseo_get_redirect_options', $options ) );
	}

	/**
	 * Get the DB options.
	 *
	 * @since 1.0.0
	 *
	 * @return array An array of options.
	 */
	public function getRedirectDbOptions() {
		// Options from the DB.
		$dbOptions = $this->getDbOptions( $this->optionsName );

		// Refactor options.
		$this->defaultsMerged = array_replace_recursive( $this->defaults, $this->defaultsMerged );

		return array_replace_recursive(
			$this->defaultsMerged,
			$this->addValueToValuesArray( $this->defaultsMerged, $dbOptions )
		);
	}

	/**
	 * For our defaults array, some options need to be translated, so we do that here.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function translateDefaults() {
		$this->defaults['logs']['log404']['length']['default']       = sprintf( '{"label":"%1$s","value":"week"}', __( '1 week', 'aioseo-redirects' ) );
		$this->defaults['logs']['redirects']['length']['default']    = sprintf( '{"label":"%1$s","value":"week"}', __( '1 week', 'aioseo-redirects' ) );
		$this->defaults['logs']['ipAddress']['level']['default']     = sprintf( '{"label":"%1$s","value":"full"}', __( 'Full Logging', 'aioseo-redirects' ) );
		$this->defaults['cache']['httpHeader']['length']['default']  = sprintf( '{"label":"%1$s","value":"hour"}', __( '1 hour', 'aioseo-redirects' ) );
		$this->defaults['redirectDefaults']['queryParam']['default'] = sprintf( '{"label":"%1$s","value":"exact"}', __( 'Exact match all parameters in any order', 'aioseo-redirects' ) );
	}

	/**
	 * Sanitizes, then saves the options to the database.
	 *
	 * @since 1.0.0
	 *
	 * @param  array $options An array of options to sanitize, then save.
	 * @return void
	 */
	public function sanitizeAndSave( $options ) {
		$this->init();

		if ( ! is_array( $options ) ) {
			return;
		}

		$mainOptions            = isset( $options['main'] ) ? $options['main'] : null;
		$oldMainOptions         = aioseoRedirects()->options->main->all();
		$logs404Options         = isset( $options['logs']['log404'] ) ? $options['logs']['log404'] : null;
		$oldLogs404Options      = aioseoRedirects()->options->logs->log404->all();
		$redirectLogsOptions    = isset( $options['logs']['redirects'] ) ? $options['logs']['redirects'] : null;
		$oldRedirectLogsOptions = aioseoRedirects()->options->logs->redirects->all();
		$fullSiteRedirectOptions    = isset( $options['fullSite'] ) ? $options['fullSite'] : null;
		$oldFullSiteRedirectOptions = aioseoRedirects()->options->fullSite->all();

		// First, recursively replace the new options into the cached state.
		// It's important we use the helper method since we want to replace populated arrays with empty ones if needed (when a setting was cleared out).
		$cachedOptions = aioseo()->core->optionsCache->getOptions( $this->optionsName );
		$dbOptions     = aioseo()->helpers->arrayReplaceRecursive(
			$cachedOptions,
			$this->addValueToValuesArray( $cachedOptions, $options, [], true )
		);

		// Now, we must also intersect both arrays to delete any individual keys that were unset.
		// We must do this because, while arrayReplaceRecursive will update the values for keys or empty them out,
		// it will keys that aren't present in the replacement array unaffected in the target array.
		$dbOptions = aioseo()->helpers->arrayIntersectRecursive(
			$dbOptions,
			$this->addValueToValuesArray( $cachedOptions, $options, [], true ),
			'value'
		);

		// Update the cache state.
		aioseo()->core->optionsCache->setOptions( $this->optionsName, $dbOptions );

		// Finally, save the new values to the DB.
		$this->save( true );

		// If log options have changed, let's regenerate.
		if ( ! empty( $logs404Options ) ) {
			if ( aioseo()->helpers->arraysDifferent( $oldLogs404Options, $logs404Options ) ) {
				aioseo()->actionScheduler->unschedule( 'aioseo_redirects_clear_404_logs' );
			}
		}

		if ( ! empty( $redirectLogsOptions ) ) {
			if ( aioseo()->helpers->arraysDifferent( $oldRedirectLogsOptions, $redirectLogsOptions ) ) {
				aioseo()->actionScheduler->unschedule( 'aioseo_redirects_clear_logs' );
			}
		}

		if ( ! empty( $mainOptions ) ) {
			if ( aioseo()->helpers->arraysDifferent( $oldMainOptions, $mainOptions ) ) {
				aioseoRedirects()->server->rewrite();
			}
		}

		if ( ! empty( $fullSiteRedirectOptions ) ) {
			if ( aioseo()->helpers->arraysDifferent( $oldFullSiteRedirectOptions, $fullSiteRedirectOptions ) ) {
				aioseoRedirects()->server->rewrite();
			}
		}
	}
}