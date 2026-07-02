<?php
namespace AIOSEO\Plugin\Addon\Redirects\Main\Server;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\Redirects\Models;
use AIOSEO\Plugin\Addon\Redirects\Utils;

/**
 * Main class to work with server redirects.
 *
 * @since 1.0.0
 */
abstract class Server {
	/**
	 * The URL format.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $urlFormat = '';

	/**
	 * The regex format.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $regexFormat = '';

	/**
	 * The relocation format.
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	protected $relocationFormat = '';

	/**
	 * The alias format.
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	protected $aliasFormat = '';

	/**
	 * File path for the redirects file.
	 *
	 * @since 1.0.0
	 *
	 * @var string|null
	 */
	protected $filePath = null;

	/**
	 * The start of the server code.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $preContent = '';

	/**
	 * The end of the server code.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $postContent = '';

	/**
	 * The Test class.
	 *
	 * @since 1.1.4
	 *
	 * @var Test
	 */
	public $test = null;

	/**
	 * Class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$uploadDirectory    = wp_upload_dir();
		$redirectsDirectory = $uploadDirectory['basedir'] . '/aioseo/redirects/';
		$this->filePath     = $redirectsDirectory . '.redirects';
		if ( wp_mkdir_p( $redirectsDirectory ) ) {
			$fs = aioseo()->core->fs;
			if ( ! $fs->exists( $this->filePath ) ) {
				$fs->touch( $this->filePath );
			}

			if ( ! $fs->exists( $redirectsDirectory . 'index.php' ) ) {
				$fs->touch( $redirectsDirectory . 'index.php' );
				$fs->putContents( $redirectsDirectory . 'index.php', '<?php' . PHP_EOL . '// Silence is golden.' );
			}

			if ( ! $fs->exists( $redirectsDirectory . '.htaccess' ) ) {
				$fs->touch( $redirectsDirectory . '.htaccess' );
				$fs->putContents( $redirectsDirectory . '.htaccess', "Options -Indexes\ndeny from all" );
			}
		}

		$this->test = new Test();
	}

	/**
	 * Exports the server config file content.
	 *
	 * @since 1.1.1
	 *
	 * @return string
	 */
	public function getConfigFileContent() {
		$content = $this->getAioseoRedirectTest();

		// If there's a relocation we don't need any more rules.
		$relocation = aioseoRedirects()->fullSiteRedirects->shouldRelocate();
		if ( $relocation ) {
			$content .= $this->formatRelocation( $relocation, implode( '|', Utils\Request::getProtectedPaths() ) ) . PHP_EOL;

			return $content;
		}

		// Aliases.
		$aliases = aioseoRedirects()->fullSiteRedirects->getAliases();
		if ( ! empty( $aliases ) ) {
			foreach ( $aliases as $alias ) {
				$content .= $this->formatAlias( $alias ) . PHP_EOL;
			}
		}

		// Canonical.
		$canonical = aioseoRedirects()->fullSiteRedirects->shouldCanonical();
		if ( $canonical ) {
			$content .= $this->formatCanonical(
				$canonical,
				aioseoRedirects()->options->fullSite->canonical->httpToHttps,
				aioseoRedirects()->options->fullSite->canonical->preferredDomain
			) . PHP_EOL;
		}

		$redirects = aioseo()->core->db->start( 'aioseo_redirects' )
			->where( 'enabled', 1 )
			->groupBy( 'source_url' )
			->run()
			->result();

		if ( ! empty( $redirects ) ) {
			foreach ( $redirects as $redirect ) {
				$formattedRedirect = $this->format( $redirect );
				if ( ! empty( $formattedRedirect ) ) {
					$content .= $formattedRedirect . PHP_EOL;
				}
			}
		}

		return $content;
	}

	/**
	 * Exports an array of redirects.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function export() {
		// Empty file if server redirects are disabled.
		if ( ! $this->valid() ) {
			$this->save( $this->getAioseoRedirectTest() );

			return;
		}

		// Regenerate the server config test, so we can make sure the configuration works.
		$this->test->regenerateTestRedirect();

		// Save the content.
		$this->save( $this->getConfigFileContent() );
	}

	/**
	 * Save the redirect file.
	 *
	 * @since 1.0.0
	 *
	 * @param  string  $content The file content that will be saved.
	 * @return void
	 */
	protected function save( $content ) {
		$content = $this->preContent . $content . $this->postContent;

		// Save the actual file.
		if ( aioseo()->core->fs->isWritable( $this->filePath ) ) {
			aioseo()->core->fs->putContents( $this->filePath, $content );
		}
	}

	/**
	 * Formats a redirect for use in the export.
	 *
	 * @since 1.0.0
	 *
	 * @param  object $redirect The redirect to format.
	 * @return mixed            The formatted redirect.
	 */
	abstract public function format( $redirect );

	/**
	 * Get's a test redirect to use when ensuring users have set it up correctly.
	 *
	 * @since 1.1.4
	 *
	 * @return string The test redirect.
	 */
	abstract protected function getAioseoRedirectTest();

	/**
	 * Formats a relocation.
	 *
	 * @since 1.1.0
	 *
	 * @param  string $relocationAddress The redirect to format.
	 * @return string                    The formatted redirect.
	 */
	abstract public function formatRelocation( $relocationAddress, $protectedPaths = '' );

	/**
	 * Formats an alias.
	 *
	 * @since 1.1.0
	 *
	 * @param  string $alias The alias to format.
	 * @return string        The formatted alias.
	 */
	abstract public function formatAlias( $alias );

	/**
	 * Formats a canonical redirect.
	 *
	 * @since 1.1.0
	 *
	 * @return void|string The formatted redirect.
	 */
	abstract public function formatCanonical( $url, $https = false, $preferredDomain = '' );

	/**
	 * Returns the needed format for the redirect.
	 *
	 * @param  boolean $regex Whether or not to use regex.
	 * @return string         The format to use.
	 */
	protected function getFormat( $regex = false ) {
		return $regex ? $this->regexFormat : $this->urlFormat;
	}

	/**
	 * Reset the redirects.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function rewrite() {
		$this->export();
	}

	/**
	 * Checks if server redirects are valid.
	 *
	 * @since 1.0.3
	 *
	 * @return boolean True if valid, false otherwise.
	 */
	public function valid() {
		if ( 'server' !== aioseoRedirects()->options->main->method ) {
			return false;
		}

		if (
			aioseo()->helpers->isApache() ||
			aioseo()->helpers->isNginx() ||
			aioseo()->helpers->isLiteSpeed()
		) {
			return true;
		}

		return false;
	}

	/**
	 * Returns the server name (e.g. Apache, Nginx, Litespeed).
	 *
	 * @since   1.1.4
	 * @version 1.3.4
	 *
	 * @return string The server name.
	 */
	public function getName() {
		return aioseo()->helpers->getServerName();
	}

	/**
	 * Returns an instance for the current server.
	 *
	 * @since 1.3.4
	 *
	 * @return Apache|Nginx|Unknown
	 */
	public static function getServer() {
		if ( aioseo()->helpers->isApache() || aioseo()->helpers->isLiteSpeed() ) {
			return new Apache();
		}
		if ( aioseo()->helpers->isNginx() ) {
			return new Nginx();
		}

		return new Unknown();
	}

	/**
	 * Checks if a redirect should be handled by the server.
	 *
	 * @since 1.3.6
	 *
	 * @param  Models\Redirect $redirect The redirect to check.
	 * @return bool                      Whether or not the redirect should be handled by the server.
	 */
	public function shouldRedirect( $redirect ) {
		// Pass-through.
		if ( 0 === (int) $redirect->type ) {
			return false;
		}

		// Custom rules are handled in PHP.
		if ( ! empty( $redirect->custom_rules ) ) {
			return false;
		}

		return true;
	}
}