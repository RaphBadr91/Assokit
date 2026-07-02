<?php
namespace AIOSEO\Plugin\Addon\LinkAssistant\Options;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Traits;

/**
 * Handles all options.
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
		// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing
		'main' => [
			'postTypes'       => [
				'all'      => [ 'type' => 'boolean', 'default' => true ],
				'included' => [ 'type' => 'array', 'default' => [ 'post', 'page' ] ],
			],
			'postStatuses'    => [
				'all'      => [ 'type' => 'boolean', 'default' => false ],
				'included' => [ 'type' => 'array', 'default' => [ 'publish', 'draft', 'pending', 'future', 'private' ] ]
			],
			'skipSentences'   => [ 'type' => 'number', 'default' => 3 ],
			'affiliatePrefix' => [ 'type' => 'string', 'default' => '' ],
			'excludePosts'    => [ 'type' => 'array', 'default' => [] ],
			'wordsToIgnore'   => [
				'type'    => 'html',
				'default' => "a\r\na's\r\nable\r\nabout\r\nabove\r\naccording\r\naccordingly\r\nacross\r\nactually\r\nafter\r\nafterwards\r\nagainst\r\nago\r\nain't\r\nall\r\nallow\r\nallows\r\nalmost\r\nalone\r\nalong\r\nalready\r\nalso\r\nalthough\r\nalways\r\nam\r\namong\r\namongst\r\nan\r\nand\r\nanother\r\nany\r\nanybody\r\nanyhow\r\nanyone\r\nanything\r\nanyway\r\nanyways\r\nanywhere\r\napart\r\nappear\r\nappreciate\r\nappropriate\r\nare\r\naren't\r\naround\r\nas\r\naside\r\nask\r\nasking\r\nassociated\r\nat\r\navailable\r\naway\r\nawful\r\nawfully\r\nbase\r\nbe\r\nbecame\r\nbecause\r\nbecome\r\nbecomes\r\nbecoming\r\nbeen\r\nbefore\r\nbeforehand\r\nbehind\r\nbeing\r\nbelieve\r\nbelow\r\nbeside\r\nbesides\r\nbest\r\nbetter\r\nbetween\r\nbeyond\r\nboth\r\nbrief\r\nbut\r\nby\r\nc'mon\r\nc's\r\ncame\r\ncan\r\ncan't\r\ncannot\r\ncant\r\ncause\r\ncauses\r\ncertain\r\ncertainly\r\nchanges\r\nclearly\r\nco\r\ncom\r\ncome\r\ncomes\r\nconcerning\r\ncons\r\nconsequently\r\nconsider\r\nconsidering\r\ncontain\r\ncontaining\r\ncontains\r\ncorresponding\r\ncould\r\ncouldn't\r\ncourse\r\ncurrently\r\ndefinitly\r\ndescribed\r\ndespite\r\ndid\r\ndidn't\r\ndifference\r\ndifferent\r\ndo\r\ndoes\r\ndoesn't\r\ndoing\r\ndon't\r\ndone\r\ndown\r\ndownwards\r\nduring\r\ne.g.\r\neach\r\neasier\r\neasy\r\nedu\r\neg\r\neither\r\nelse\r\nelsewhere\r\nenough\r\nentirely\r\nespecially\r\net\r\netc\r\neven\r\never\r\nevery\r\neverybody\r\neveryone\r\neverything\r\neverywhere\r\nex\r\nexactly\r\nexample\r\nexcept\r\nfar\r\nfew\r\nfind\r\nfirst\r\nfollowed\r\nfollowing\r\nfollows\r\nfor\r\nformer\r\nformerly\r\nforth\r\nfree\r\nfrom\r\nfurther\r\nfurthermore\r\ngeneral\r\nget\r\ngets\r\ngetting\r\ngiven\r\ngives\r\ngo\r\ngoes\r\ngoing\r\ngone\r\ngood\r\ngot\r\ngotten\r\ngreat\r\nhad\r\nhadn't\r\nhappens\r\nhardly\r\nhas\r\nhasn't\r\nhave\r\nhaven't\r\nhaving\r\nhe\r\nhe'd\r\nhe'll\r\nhe's\r\nhelp\r\nhence\r\nher\r\nhere\r\nhere's\r\nhereafter\r\nhereby\r\nherein\r\nhereupon\r\nhers\r\nherself\r\nhigh\r\nhim\r\nhimself\r\nhis\r\nhither\r\nhopefully\r\nhow\r\nhow's\r\nhowbeit\r\nhowever\r\ni\r\ni'd\r\ni'll\r\ni'm\r\ni've\r\nie\r\nif\r\nignored\r\nimmediate\r\nin\r\ninasumuch\r\ninc\r\nindeed\r\nindicate\r\nindicated\r\nindicates\r\ninner\r\ninsofar\r\ninstead\r\ninto\r\ninward\r\nis\r\nisn't\r\nit\r\nit's\r\nits\r\nitself\r\njust\r\nkeep\r\nkeeps\r\nkept\r\nknow\r\nknown\r\nknows\r\nlast\r\nlately\r\nlater\r\nlatter\r\nlatterly\r\nleast\r\nless\r\nlest\r\nlet\r\nlet's\r\nlike\r\nliked\r\nlikely\r\nlittle\r\nlong\r\nlook\r\nlove\r\nltd\r\nmainly\r\nmake\r\nmany\r\nmatter\r\nmay\r\nmaybe\r\nme\r\nmean\r\nmeanwhile\r\nmerely\r\nmight\r\nmore\r\nmoreover\r\nmost\r\nmostly\r\nmuch\r\nmust\r\nmustn't\r\nmy\r\nmyself\r\nname\r\nnamely\r\nnear\r\nnearly\r\nnecessary\r\nneed\r\nneeds\r\nnever\r\nnevertheless\r\nnew\r\nnext\r\nno\r\nnobody\r\nnon\r\nnone\r\nnoone\r\nnor\r\nnormally\r\nnot\r\nnothing\r\nnow\r\nnowhere\r\nobviously\r\nof\r\noff\r\noften\r\noh\r\nok\r\nokay\r\nold\r\non\r\nonce\r\none\r\nones\r\nonly\r\nor\r\nother\r\nothers\r\notherwise\r\nought\r\nour\r\nours\r\nourselves\r\nout\r\noutside\r\nover\r\noverall\r\nown\r\nparticular\r\nparticularly\r\nper\r\nperhaps\r\nplaced\r\nplease\r\nplus\r\npossible\r\npresumably\r\npropbably\r\nprovided\r\nprovides\r\nque\r\nquite\r\nrather\r\nreally\r\nreasonably\r\nregarding\r\nregardless\r\nregards\r\nrelatively\r\nrespectively\r\nright\r\nsaid\r\nsame\r\nsaw\r\nsay\r\nsaying\r\nsays\r\nsecondly\r\nsee\r\nseeing\r\nseem\r\nseemed\r\nseeming\r\nseems\r\nself\r\nselves\r\nsensible\r\nsent\r\nserious\r\nseriously\r\nseveral\r\nshall\r\nshan't\r\nshe\r\nshe'd\r\nshe'll\r\nshe's\r\nshock\r\nshocking\r\nshould\r\nshouldn't\r\nshow\r\nsimple\r\nsince\r\nso\r\nsome\r\nsomebody\r\nsomehow\r\nsomeone\r\nsomething\r\nsometime\r\nsometimes\r\nsomewhat\r\nsomewhere\r\nsoon\r\nsorry\r\nspecified\r\nspecify\r\nspecifying\r\nstart\r\nstill\r\nsub\r\nsuch\r\nsup\r\nsupposing\r\nsure\r\ntake\r\ntaken\r\ntell\r\ntends\r\nthan\r\nthank\r\nthanks\r\nthat\r\nthat's\r\nthats\r\nthe\r\ntheir\r\ntheirs\r\nthem\r\nthemselves\r\nthen\r\nthere\r\nthere's\r\nthereafter\r\nthereby\r\ntherefore\r\ntherein\r\nthereupon\r\nthese\r\nthey\r\nthey'd\r\nthey'll\r\nthey're\r\nthey've\r\nthink\r\nthis\r\nthose\r\nthough\r\nthrough\r\nthroughout\r\nthru\r\nthus\r\ntill\r\ntime\r\nto\r\ntoo\r\ntook\r\ntoward\r\ntowards\r\ntried\r\ntries\r\ntruly\r\ntry\r\ntrying\r\ntwice\r\nunder\r\nunfortunately\r\nunless\r\nunlikely\r\nuntil\r\nunto\r\nup\r\nupon\r\nus\r\nuse\r\nused\r\nuseful\r\nusing\r\nusually\r\nvalue\r\nvarious\r\nvery\r\nvia\r\nvs\r\nwant\r\nwants\r\nwas\r\nwasn't\r\nway\r\nwe\r\nwe'd\r\nwe'll\r\nwe're\r\nwe've\r\nwere\r\nweren't\r\nwhat\r\nwhat's\r\nwhen\r\nwhen's\r\nwhenever\r\nwhere\r\nwhere's\r\nwhereafter\r\nwhereas\r\nwhereby\r\nwherein\r\nwhereupon\r\nwherever\r\nwhether\r\nwhich\r\nwhile\r\nwho\r\nwho's\r\nwhoever\r\nwhom\r\nwhose\r\nwhy\r\nwhy's\r\nwill\r\nwilling\r\nwish\r\nwith\r\nwithin\r\nwithout\r\nwon't\r\nwonder\r\nwould\r\nwouldn't\r\nyes\r\nyet\r\nyou\r\nyou'd\r\nyou'll\r\nyou're\r\nyou've\r\nyour\r\nyours\r\nyourself\r\nyourselves" // phpcs:ignore Generic.Files.LineLength.MaxExceeded
			]
		]
		// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing
	];

	/**
	 * Class constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $optionsName The options name.
	 */
	public function __construct( $optionsName = 'aioseo_link_assistant_options' ) {
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
		$options = $this->getLinkAssistantDbOptions();
		aioseo()->core->optionsCache->setOptions( $this->optionsName, apply_filters( 'aioseo_get_link_assistant_options', $options ) );
	}

	/**
	 * Returns the DB options.
	 *
	 * @since 1.0.0
	 *
	 * @return array The options.
	 */
	public function getLinkAssistantDbOptions() {
		$dbOptions = $this->getDbOptions( $this->optionsName );

		$this->defaultsMerged = array_replace_recursive( $this->defaults, $this->defaultsMerged );

		return array_replace_recursive(
			$this->defaultsMerged,
			$this->addValueToValuesArray( $this->defaultsMerged, $dbOptions )
		);
	}

	/**
	 * Sanitizes, then saves the options to the database.
	 *
	 * @since 1.0.0
	 *
	 * @param  array $newOptions The new options to sanitize, then save.
	 * @return void
	 */
	public function sanitizeAndSave( $newOptions ) {
		$this->init();

		if ( ! is_array( $newOptions ) ) {
			return;
		}

		// First, recursively replace the new options into the cached state.
		// It's important we use the helper method since we want to replace populated arrays with empty ones if needed (when a setting was cleared out).
		$cachedOptions = aioseo()->core->optionsCache->getOptions( $this->optionsName );
		$dbOptions     = aioseo()->helpers->arrayReplaceRecursive(
			$cachedOptions,
			$this->addValueToValuesArray( $cachedOptions, $newOptions, [], true )
		);

		// Now, we must also intersect both arrays to delete any individual keys that were unset.
		// We must do this because, while arrayReplaceRecursive will update the values for keys or empty them out,
		// it will keys that aren't present in the replacement array unaffected in the target array.
		$dbOptions = aioseo()->helpers->arrayIntersectRecursive(
			$dbOptions,
			$this->addValueToValuesArray( $cachedOptions, $newOptions, [], true ),
			'value'
		);

		if ( isset( $newOptions['main']['wordsToIgnore'] ) ) {
			$dbOptions['main']['wordsToIgnore'] = preg_replace( '/\h/', "\n", $newOptions['main']['wordsToIgnore'] );
		}

		// Update the cache state.
		aioseo()->core->optionsCache->setOptions( $this->optionsName, $dbOptions );

		// Finally, save the new values to the DB.
		$this->save( true );

		$this->maybeForceRescan( $newOptions );

		// Clear the cache each time the options are updated.
		aioseoLinkAssistant()->cache->clear();
	}

	/**
	 * Checks if we need to force a rescan of all posts because the affiliate prefix changed.
	 *
	 * @since 1.0.0
	 *
	 * @param  array $newOptions The new options.
	 * @return void
	 */
	private function maybeForceRescan( $newOptions ) {
		if ( isset( $newOptions['main']['affiliatePrefix'] ) && aioseoLinkAssistant()->options->main->affiliatePrefix !== $newOptions['main']['affiliatePrefix'] ) {
			$this->reindexAffiliateLinks( $newOptions['main']['affiliatePrefix'] );
		}

		if ( isset( $newOptions['main']['wordsToIgnore'] ) && aioseoLinkAssistant()->options->main->wordsToIgnore !== $newOptions['main']['wordsToIgnore'] ) {
			aioseoLinkAssistant()->internalOptions->internal->minimumSuggestionScanDate = date( 'Y-m-d H:i:s', time() );
		}
	}

	/**
	 * Reindexes the affiliate links on the site after the setting has changed.
	 *
	 * @since 1.0.1
	 *
	 * @param  string $rawAffiliatePrefixes The affiliate prefixes (JSON encoded).
	 * @return void
	 */
	private function reindexAffiliateLinks( $rawAffiliatePrefixes ) {
		$hostname = wp_parse_url( get_site_url(), PHP_URL_HOST );

		// First, reset all internal affiliate links to internal links.
		aioseo()->core->db->update( 'aioseo_links' )
			->where( 'affiliate', 1 )
			->where( 'hostname', $hostname )
			->set( [
				'affiliate' => 0,
				'internal'  => 1
			] )
			->run();

		// Then all other affiliate links can be marked as external links.
		aioseo()->core->db->update( 'aioseo_links' )
			->where( 'affiliate', 1 )
			->set( [
				'affiliate' => 0,
				'external'  => 1
			] )
			->run();

		// Finally, reindex the links based on the updated prefixes.
		$query = aioseo()->core->db->update( 'aioseo_links' )
			->set( [
				'affiliate' => 1,
				'internal'  => 0,
				'external'  => 0
			] );

		$affiliatePrefixes = json_decode( $rawAffiliatePrefixes );

		$whereClauses = [];
		foreach ( $affiliatePrefixes as $affiliatePrefix ) {
			$prefix         = esc_sql( sanitize_text_field( $affiliatePrefix->value ) );
			$whereClauses[] = "`url` LIKE '%$prefix%'";
		}

		$whereClauses = implode( ' OR ', $whereClauses );

		$query->whereRaw( "($whereClauses)" )
			->run();
	}
}