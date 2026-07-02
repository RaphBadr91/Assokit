<?php
namespace AIOSEO\Plugin\Addon\Redirects\Main;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\Redirects\Models;
use AIOSEO\Plugin\Addon\Redirects\Utils;

/**
 * Main class to run our redirects.
 *
 * @since 1.0.0
 */
class Redirect {
	/**
	 * Matched redirect.
	 *
	 * @since 1.0.0
	 *
	 * @var boolean
	 */
	private $matched = false;

	/**
	 * The target redirect URL.
	 *
	 * @since 1.0.0
	 *
	 * @var string|null
	 */
	private $redirectUrl = null;

	/**
	 * The target redirect code.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	private $redirectCode = 0;

	/**
	 * Class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// Schedule clearing of logs.
		add_action( 'aioseo_run_updates', [ $this, 'scheduleClearing' ], 20 );

		if ( is_admin() || aioseo()->helpers->isAjaxCronRestRequest() || aioseo()->helpers->isDoingWpCli() ) {
			return;
		}

		// Log 404's.
		if ( apply_filters( 'aioseo_redirects_use_alternate_404_hook', false, Utils\Request::getRequestUrl() ) ) {
			add_filter( 'template_include', [ $this, 'templateInclude' ], 10 );
		} else {
			add_action( 'template_redirect', [ $this, 'templateRedirect' ], 5 );
		}

		// Log external redirect agents.
		add_filter( 'x_redirect_by', [ $this, 'logExternalRedirect' ], 1000 );

		// The main redirect loop.
		add_action( 'init', [ $this, 'init' ] );

		// Redirect HTTP headers and server-specific overrides.
		add_filter( 'wp_redirect', [ $this, 'wpRedirect' ], 1, 2 );
	}

	/**
	 * Redirection 'main loop'. Checks the currently requested URL against the database and perform a redirect, if necessary.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function init() {
		if (
			is_admin() ||
			$this->matched ||
			aioseo()->helpers->isAjaxCronRestRequest() ||
			$this->isPageBuilderPreviewRequest()
		) {
			return;
		}

		$requestUrl = Utils\Request::getRequestUrl();
		if ( ! $requestUrl || Utils\Request::isProtectedPath( $requestUrl ) ) {
			return;
		}

		// First try the regular path redirects.
		$redirects = $this->getRegularRedirects( $requestUrl );

		// Run through the list until one fires.
		foreach ( $redirects as $redirect ) {
			// Test custom rules.
			if ( ! $this->matchCustomRules( $redirect, $requestUrl ) ) {
				continue;
			}

			$this->doRedirect( $redirect, $requestUrl );
		}

		// Next try the regex redirects.
		$regexRedirects = $this->getRegexRedirects( $requestUrl );

		// Run through the list until one fires.
		foreach ( $regexRedirects as $regexRedirect ) {
			// Test custom rules.
			if ( ! $this->matchCustomRules( $regexRedirect, $requestUrl ) ) {
				continue;
			}

			$this->doRedirect( $regexRedirect, $requestUrl );
		}
	}

	/**
	 * Runs a redirect.
	 *
	 * @since 1.2.3
	 *
	 * @param  Models\Redirect $redirect   The redirect model.
	 * @param  string          $requestUrl The request url.
	 * @return void
	 */
	private function doRedirect( $redirect, $requestUrl ) {
		// Format the target URL accounting for relative URLs that may have a trailing slash.
		$targetUrl = Utils\Request::formatTargetUrl( trim( $redirect->target_url ) );

		if ( $redirect->regex ) {
			// Replace the target placeholders.
			if ( isset( $redirect->regexMatches ) ) {
				for ( $i = 1; $i < count( $redirect->regexMatches ); $i++ ) {
					$targetUrl = @str_replace( '$' . $i, $redirect->regexMatches[ $i ], $targetUrl );
				}
			}

			// Space encode the target after placeholders are replaced.
			$split     = explode( '?', $targetUrl );
			$targetUrl = str_replace( ' ', '%20', $targetUrl );
			if ( count( $split ) === 2 ) {
				$targetUrl = implode( '?', [
					str_replace( ' ', '%20', $split[0] ),
					str_replace( ' ', '+', $split[1] )
				] );
			}
		}

		// Let's add the query string if necessary.
		$queryString = wp_parse_url( $requestUrl, PHP_URL_QUERY );
		if ( $queryString && ( 'pass' === $redirect->query_param || 'utm' === $redirect->query_param ) ) {
			parse_str( $queryString, $queryStringArray );

			// If we're only passing utm_ let's remove all other params.
			if ( 'utm' === $redirect->query_param ) {
				foreach ( $queryStringArray as $queryStringArrayKey => $queryStringArrayValue ) {
					if ( ! preg_match( '/^utm_/', (string) $queryStringArrayKey ) ) {
						unset( $queryStringArray[ $queryStringArrayKey ] );
					}
				}
			}

			// Add encoded params to the target URL.
			$targetUrl = add_query_arg( array_map( 'urlencode', $queryStringArray ), $targetUrl );
		}

		$this->matched = $redirect->id;

		$this->redirectUrl = $targetUrl;
		// Cast to int before using.
		$this->redirectCode = (int) $redirect->type;

		// Log only if it's not a redirect test.
		if ( ! Utils\Request::getRequestHeader( 'X-AIOSEO-Redirect-Test' ) ) {
			$redirect->addHit();

			$this->logRedirect( $this->redirectCode, 'aioseo', $redirect->id );
		}

		// Pass through.
		if ( 0 === $this->redirectCode ) {
			$this->processPassThrough( $targetUrl );

			return;
		}

		// Pass through.
		if ( 404 === $this->redirectCode ) {
			$this->process404();

			return;
		}

		// wp_redirect will stop the execution if our redirect code is below 300 or over 399.
		if ( 300 <= $this->redirectCode && 399 >= $this->redirectCode ) {
			wp_redirect( $targetUrl, $this->redirectCode, 'AIOSEO' );
			die;
		} else {
			status_header( $redirect->type );
			exit( 0 );
		}
	}

	/**
	 * Returns if the query string matches for a redirect.
	 *
	 * @since 1.1.4
	 *
	 * @param  Models\Redirect $redirect   The redirect model.
	 * @param  string          $requestUrl The request url.
	 * @return bool                        Query string match.
	 */
	private function matchQueryStringRedirect( $redirect, $requestUrl ) {
		$sourceQueryString = wp_parse_url( trim( $redirect->source_url ), PHP_URL_QUERY );

		// If no query string is present in the source we can return early.
		if ( empty( $sourceQueryString ) ) {
			return true;
		}

		$queryString = wp_parse_url( $requestUrl, PHP_URL_QUERY ) ?: '';

		parse_str( $sourceQueryString, $sourceQueryStringArray );
		parse_str( $queryString, $queryStringArray );

		if ( 'exact' === $redirect->query_param ) {
			// If we can't find this query string inside the source query, we need to skip this redirect.
			foreach ( $queryStringArray as $key => $value ) {
				if ( ! isset( $sourceQueryStringArray[ $key ] ) || $sourceQueryStringArray[ $key ] !== $value ) {
					return false;
				}
			}

			// Same needs to happen the other way around.
			foreach ( $sourceQueryStringArray as $keySource => $valueSource ) {
				if ( ! isset( $queryStringArray[ $keySource ] ) || $queryStringArray[ $keySource ] !== $valueSource ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Is this source->target a redirect loop?
	 *
	 * @since 1.1.4
	 *
	 * @param  Models\Redirect  $redirect The redirect model.
	 * @return bool                       True if it's a loop.
	 */
	private function isRedirectLoop( $redirect ) {
		// Parse final source and target url.
		$parsedSourceUrl = wp_parse_url( Utils\Request::formatSourceUrl( trim( $redirect->source_url ) ) );
		$parsedTargetUrl = wp_parse_url( Utils\Request::formatTargetUrl( trim( $redirect->target_url ) ) );

		// Build comparable urls.
		$urlParams = [ 'scheme', 'host', 'path' ];
		if ( 'exact' === $redirect->query_param ) {
			$urlParams[] = 'query';
		}

		$sourceUrl = Utils\Request::buildUrl( $parsedSourceUrl, $urlParams );
		$targetUrl = Utils\Request::buildUrl( $parsedTargetUrl, $urlParams );
		if ( $sourceUrl === $targetUrl ) {
			return true;
		}

		// If we're ignoring the slash and this is a non-slash to slash redirect it could trigger a redirect loop.
		if (
			$redirect->ignore_slash &&
			untrailingslashit( $sourceUrl ) === untrailingslashit( $targetUrl )
		) {
			return true;
		}

		return false;
	}

	/**
	 * Returns if the path matches for a redirect.
	 *
	 * @since 1.1.4
	 *
	 * @param  Models\Redirect $redirect   The redirect data.
	 * @param  string          $requestUrl The URL to match against.
	 * @return bool                        Paths match.
	 */
	private function matchSourceRedirect( $redirect, $requestUrl ) {
		$sourceUrlPath     = wp_parse_url( aioseo()->helpers->decodeUrl( trim( $redirect->source_url ) ), PHP_URL_PATH );
		$requestUrlNoQuery = wp_parse_url( aioseo()->helpers->decodeUrl( $requestUrl ), PHP_URL_PATH ) ?: '/';
		// If we're ignoring the slash we already matched the redirect in que DB query.
		// Here we'll figure out the ignore_slash rule.
		if ( ! $redirect->ignore_slash ) {
			$sourceHasSlash  = Utils\Request::urlHasTrailingSlash( $sourceUrlPath );
			$requestHasSlash = Utils\Request::urlHasTrailingSlash( $requestUrlNoQuery );

			if ( ( $sourceHasSlash && ! $requestHasSlash ) || ( ! $sourceHasSlash && $requestHasSlash ) ) {
				return false;
			}
		}

		// If we're ignoring the case we already matched the redirect in que DB query.
		// Here we'll figure out the ignore_case rule.
		if (
			! $redirect->ignore_case &&
			untrailingslashit( $sourceUrlPath ) !== untrailingslashit( $requestUrlNoQuery )
		) {
			return false;
		}

		return true;
	}

	/**
	 * Returns matches for a regex.
	 *
	 * @since 1.1.4
	 *
	 * @param  Models\Redirect $redirect   A redirect object model.
	 * @param  string          $requestUrl The full request url.
	 * @return array                       An array of matches.
	 */
	public function matchRegexRedirect( $redirect, $requestUrl ) {
		$pattern = $this->formatPattern( trim( $redirect->source_url ), $redirect->ignore_case, $redirect->ignore_slash );

		preg_match( $pattern, (string) $requestUrl, $matches );

		return $matches;
	}

	/**
	 * Process a pass through.
	 *
	 * @since 4.1.2
	 *
	 * @param  string $to The url to.
	 * @return void
	 */
	private function processPassThrough( $to ) {
		if ( Utils\Request::isUrlExternal( $to ) ) {
			echo apply_filters( 'aioseo_redirects_pass_through', wp_remote_fopen( $to ), $to ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		$parsedTo = wp_parse_url( $to );

		$_SERVER['REQUEST_URI'] = ! empty( $parsedTo['path'] ) ? $parsedTo['path'] : '';

		if ( ! empty( $parsedTo['query'] ) ) {
			$_SERVER['QUERY_STRING'] = $parsedTo['query'];
			parse_str( $parsedTo['query'], $_GET ); // phpcs:ignore HM.Security.NonceVerification.Recommended
		}
	}

	/**
	 * Process a 404.
	 *
	 * @since 1.3.4
	 *
	 * @return void
	 */
	private function process404() {
		// Resetting the global query here will allow us to use the 404 template.
		// phpcs:disable Squiz.NamingConventions.ValidVariableName
		global $wp_query;
		$wp_query = new \WP_Query();
		$wp_query->set_404();
		// phpcs:enable Squiz.NamingConventions.ValidVariableName
		status_header( 404 );
	}

	/**
	 * Match the custom rules.
	 *
	 * @since 1.1.0
	 *
	 * @param  Models\Redirect $redirect          A redirect object model.
	 * @param  string          $compareUrlRequest The url being redirected.
	 * @return boolean                            True if all the custom rules apply.
	 */
	private function matchCustomRules( $redirect, $compareUrlRequest = '' ) {
		if ( empty( $redirect->custom_rules ) || ! is_array( $redirect->custom_rules ) ) {
			return true;
		}

		foreach ( $redirect->custom_rules as $customRule ) {
			// Let's skip rules without values.
			if ( 'schedule' !== $customRule->type && empty( $customRule->value ) ) {
				continue;
			}

			// Small sanitization.
			$customRule->value = is_array( $customRule->value ) ? array_map( 'trim', $customRule->value ) : trim( $customRule->value );

			// This is used for the default matching rule.
			$valueToMatch = null;

			// Define value to match for regex and straight matches.
			switch ( $customRule->type ) {
				case 'agent':
					$valueToMatch = Utils\Request::getUserAgent();
					break;
				case 'referrer':
					$valueToMatch = Utils\Request::getReferrer();
					break;
				case 'cookie':
					$valueToMatch = Utils\Request::getCookie( $customRule->key );
					break;
				case 'header':
					$valueToMatch = Utils\Request::getRequestHeader( $customRule->key );
					break;
				case 'ip':
					$valueToMatch = Utils\Request::getIp();
					break;
				case 'server':
					$valueToMatch = Utils\Request::getServerName();
					break;
				case 'locale':
					$valueToMatch = get_locale();
					break;
			}

			// Custom matches + default match for $valueToMatch.
			switch ( $customRule->type ) {
				case 'login':
					if ( 'loggedin' === $customRule->value && ! is_user_logged_in() ) {
						return false;
					}
					if ( 'loggedout' === $customRule->value && is_user_logged_in() ) {
						return false;
					}
					break;
				case 'role':
					$matchRole = false;
					foreach ( $customRule->value as $role ) {
						if ( current_user_can( $role ) ) {
							$matchRole = true;
							break;
						}
					}
					if ( ! $matchRole ) {
						return false;
					}
					break;
				case 'wp_filter':
					$matchFilter = false;
					foreach ( $customRule->value as $filter ) {
						if ( apply_filters( $filter, false, $compareUrlRequest, $redirect ) ) {
							$matchFilter = true;
							break;
						}
					}
					if ( ! $matchFilter ) {
						return false;
					}
					break;
				case 'agent':
					$matchAgent = false;
					// Custom values for matching.
					foreach ( $customRule->value as $agentItem ) {
						$regex = ! empty( $customRule->regex );
						switch ( $agentItem ) {
							case 'mobile':
								$agentItem = 'iPad|iPod|iPhone|Android|BlackBerry|SymbianOS|SCH-Md+|Opera Mini|Windows CE|Nokia|SonyEricsson|webOS|PalmOS';
								$regex     = true;
								break;
							case 'feeds':
								$agentItem = 'Bloglines|feed|rss';
								$regex     = true;
								break;
							case 'libraries':
								$agentItem = 'cURL|Java|libwww-perl|PHP|urllib';
								$regex     = true;
								break;
						}

						$matchAgent = $agentItem === $customRule->value;
						if ( $regex ) {
							$matchAgent = $this->matchRegexRule( $agentItem, $valueToMatch );
						}

						if ( $matchAgent ) {
							break;
						}
					}

					if ( ! $matchAgent ) {
						return false;
					}
					break;
				case 'schedule':
					if ( ! empty( $customRule->scheduleStart ) && time() < strtotime( $customRule->scheduleStart ) ) {
						return false;
					}

					if ( ! empty( $customRule->scheduleEnd ) && time() > strtotime( $customRule->scheduleEnd ) ) {
						return false;
					}
					break;
				default:
					$match = is_array( $customRule->value ) ? in_array( $valueToMatch, $customRule->value, true ) : $valueToMatch === $customRule->value;
					if ( ! empty( $customRule->regex ) ) {
						$match = $this->matchRegexRule( $customRule->value, $valueToMatch );
					}

					if ( ! $match ) {
						return false;
					}
					break;
			}
		}

		return true;
	}

	/**
	 * Helper to match a regex rule against a value.
	 *
	 * @since 1.1.0
	 *
	 * @param  array|string $regex The regex.
	 * @param  string       $value The value to match against.
	 * @return false|int           The match result.
	 */
	private function matchRegexRule( $regex, $value ) {
		if ( ! is_array( $regex ) ) {
			$regex = [ $regex ];
		}
		// Try each regex and return on the first one that is true.
		foreach ( $regex as $ex ) {
			if ( preg_match( sprintf( '/%s/', $ex ), (string) $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Schedule clearing of the logs.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function scheduleClearing() {
		$actions = [
			'log404'    => [
				'action' => 'aioseo_redirects_clear_404_logs',
				'method' => 'clear404Logs'
			],
			'redirects' => [
				'action' => 'aioseo_redirects_clear_logs',
				'method' => 'clearRedirectLogs'
			]
		];

		foreach ( $actions as $key => $data ) {
			if ( ! aioseoRedirects()->options->logs->{ $key }->enabled ) {
				aioseo()->actionScheduler->unschedule( $data['action'] );
				continue;
			}

			try {
				// Register the action handler.
				add_action( $data['action'], [ $this, $data['method'] ] );

				if ( ! as_next_scheduled_action( $data['action'] ) ) {
					// Independent of the recurrence we need to run the action every hour.
					// Then the action will check if it needs to run based on the amount of redirects.
					as_schedule_recurring_action( time() + 60, HOUR_IN_SECONDS, $data['action'], [], 'aioseo' );
				}
			} catch ( \Exception $e ) {
				// Do nothing.
			}
		}
	}

	/**
	 * Clears the 404 logs.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function clear404Logs() {
		$optionLength = json_decode( aioseoRedirects()->options->logs->log404->length )->value;
		if ( 'forever' === $optionLength ) {
			return;
		}

		$date = date( 'Y-m-d H:i:s', strtotime( '-1 ' . $optionLength ) );
		aioseo()->core->db
			->delete( 'aioseo_redirects_404_logs' )
			->where( 'created <', $date )
			->run();
	}

	/**
	 * Clears the redirect logs.
	 *
	 * @return void
	 */
	public function clearRedirectLogs() {
		$optionLength = json_decode( aioseoRedirects()->options->logs->redirects->length )->value;
		if ( 'forever' === $optionLength ) {
			return;
		}

		$date = date( 'Y-m-d H:i:s', strtotime( '-1 ' . $optionLength ) );
		aioseo()->core->db
			->delete( 'aioseo_redirects_logs' )
			->where( 'created <', $date )
			->run();
	}

	/**
	 * Perform any pre-redirect processing, such as logging and header fixing.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $url    The target URL.
	 * @param  int    $status HTTP status.
	 * @return string         The target URL.
	 */
	public function wpRedirect( $url, $status = 302 ) {
		// These are set early on so when other filters run, we have access to it.
		$this->redirectUrl  = $url;
		$this->redirectCode = $status;

		global $is_IIS; // phpcs:ignore Squiz.NamingConventions.ValidVariableName

		if ( $is_IIS ) { // phpcs:ignore Squiz.NamingConventions.ValidVariableName
			header( "Refresh: 0;url=$url" );
		}

		if ( 301 === $status && php_sapi_name() === 'cgi-fcgi' ) {
			$serversToCheck = [ 'lighttpd', 'nginx' ];

			foreach ( $serversToCheck as $name ) {
				if (
					isset( $_SERVER['SERVER_SOFTWARE'] ) &&
					false !== stripos( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ), $name ) // phpcs:ignore HM.Security.ValidatedSanitizedInput.InputNotSanitized
				) {
					status_header( $status );
					header( "Location: $url" );
					exit( 0 );
				}
			}
		}

		if ( 307 === intval( $status, 10 ) ) {
			status_header( $status );
			nocache_headers();

			return $url;
		}

		if ( ! aioseoRedirects()->options->cache->httpHeader->enabled ) {
			// No cache - just use WP function.
			nocache_headers();
		} else {
			if (
				! headers_sent() &&
				'forever' !== aioseoRedirects()->options->cache->httpHeader->length &&
				301 === intval( $status, 10 )
			) {
				// Custom cache.
				$cacheTime = 1;
				switch ( aioseoRedirects()->options->cache->httpHeader->length ) {
					case 'day':
						$cacheTime = 24;
					case 'week':
						$cacheTime = 168;
					case 'hour':
					default:
						break;
				}
				header( 'Expires: ' . gmdate( 'D, d M Y H:i:s T', time() + $cacheTime * 60 * 60 ) );
				header( 'Cache-Control: max-age=' . $cacheTime * 60 * 60 );
			}
		}

		status_header( $status );

		return $url;
	}

	/**
	 * Logs a redirect.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $agent Redirect agent.
	 * @return string        Redirect agent.
	 */
	public function logExternalRedirect( $agent ) {
		// Have we already redirected?
		if ( $this->matched || 'aioseo' === $agent ) {
			return $agent;
		}

		if (
			! aioseoRedirects()->options->logs->external ||
			! aioseoRedirects()->options->logs->redirects->enabled
		) {
			return $agent;
		}

		$redirectBy = $agent ? strtolower( substr( $agent, 0, 50 ) ) : 'wordpress'; // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText
		$this->logRedirect( $this->redirectCode, $redirectBy );

		return $agent;
	}

	/**
	 * Intercept the request and parse if needed.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function templateRedirect() {
		// Skip redirecting if it's a redirect test.
		if ( ! is_404() || Utils\Request::isRedirectTest() || 200 === http_response_code() ) {
			return;
		}

		// We don't want to log 404 urls that will be redirected by WordPress.
		if ( in_array( untrailingslashit( Utils\Request::getRequestUrl() ), Utils\WpUri::getRedirectAdminPaths(), true ) ) {
			return;
		}

		// Last check before logging a 404.
		if ( aioseoRedirects()->redirect404->avoid404Redirect() ) {
			return;
		}

		$this->log404();
	}

	/**
	 * Alternate hook for 404 logs.
	 *
	 * @since 4.7.9
	 *
	 * @param  string $template The template.
	 * @return string
	 */
	public function templateInclude( $template ) {
		$this->templateRedirect();

		return $template;
	}

	/**
	 * WordPress 'template_redirect' hook. Used to check for 404s.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function log404() {
		if ( $this->matched ) {
			return;
		}

		if ( ! aioseoRedirects()->options->logs->log404->enabled ) {
			return;
		}

		$this->logRedirect( 404 );
	}

	/**
	 * Logs the redirects.
	 *
	 * @since 1.0.0
	 *
	 * @param  int    $status     The HTTP status code.
	 * @param  string $redirectBy The redirect agent.
	 * @param  int    $redirectId The redirect ID.
	 * @return void
	 */
	private function logRedirect( $status, $redirectBy = null, $redirectId = null ) {
		$ip = null;
		if ( aioseoRedirects()->options->logs->ipAddress->enabled ) {
			$ip      = Utils\Request::getIp();
			$ipLevel = json_decode( aioseoRedirects()->options->logs->ipAddress->level )->value;
			if ( 'full' !== $ipLevel ) {
				$ip = Utils\Request::maskIp( $ip );
			}
		}

		$data = [
			'url'            => Utils\Request::getRequestUrl(),
			'domain'         => Utils\Request::getServer(),
			'sent_to'        => $this->redirectUrl,
			'agent'          => Utils\Request::getUserAgent(),
			'referrer'       => Utils\Request::getReferrer(),
			'http_code'      => $status,
			'request_method' => Utils\Request::getRequestMethod(),
			'ip'             => $ip,
			'request_data'   => [
				'source' => array_values( $this->getRedirectSource() )
			]
		];

		if ( $redirectId ) {
			$data['redirect_id'] = $redirectId;
		}

		if ( $redirectBy ) {
			$data['redirect_by'] = $redirectBy;
		}

		if ( aioseoRedirects()->options->logs->httpHeader ) {
			$data['request_data']['headers'] = Utils\Request::getRequestHeaders();
		}

		// Filter to maybe skip the redirect log.
		if ( apply_filters( 'aioseo_redirects_log_skip', false, $data ) ) {
			return;
		}

		$log = 404 === $status ? new Models\Redirects404Log() : new Models\RedirectsLog();
		$log->set( $data );
		$log->save();
	}

	/**
	 * Get a 'source' for a redirect by digging through the backtrace.
	 *
	 * @since 1.0.0
	 *
	 * @return string The redirect source.
	 */
	private function getRedirectSource() {
		$ignore = [
			'WP_Hook',
			'template-loader.php',
			'wp-blog-header.php',
		];

		// phpcs:ignore
		$source = wp_debug_backtrace_summary( null, 5, false );

		return array_filter( $source, function( $item ) use ( $ignore ) {
			foreach ( $ignore as $ignore_item ) { // phpcs:ignore Squiz.NamingConventions.ValidVariableName
				if ( strpos( $item, $ignore_item ) !== false ) { // phpcs:ignore Squiz.NamingConventions.ValidVariableName
					return false;
				}
			}

			return true;
		} );
	}

	/**
	 * Formats the regex pattern that we are using.
	 *
	 * @since 1.0.0
	 *
	 * @param  string  $pattern     The pattern to format.
	 * @param  boolean $ignoreCase  Whether to ignore case.
	 * @param  boolean $ignoreSlash Whether to ignore the slash.
	 * @return string               The formatted pattern.
	 */
	private function formatPattern( $pattern, $ignoreCase = false, $ignoreSlash = false ) {
		$pattern = str_replace( '.*', '*', $pattern );

		if ( $ignoreSlash ) {
			$pattern = Utils\Regex::ignoreSlash( $pattern );
		}

		$pattern = preg_replace_callback( '/[\\\\^$.[\\]|()?*+{}\\-\\/]/', function ( $matches ) {
			switch ( $matches[0] ) {
				case '*':
					return '.*';
				case '/':
					return '\/';
				default:
					return $matches[0];
			}
		}, $pattern );

		$pattern = str_replace( '\/^', '^', $pattern );
		$pattern = str_replace( '\\\'', '\'', $pattern );

		if ( preg_match( '/^\^/', (string) $pattern ) && ! preg_match( '/^\^\\\\\//', (string) $pattern ) ) {
			$pattern = str_replace( '^', '^\/', (string) $pattern );
		}

		$pattern = "/$pattern/";
		if ( $ignoreCase ) {
			$pattern .= 'i';
		}

		return $pattern;
	}

	/**
	 * Try matching a redirect to a URL.
	 *
	 * @since 1.2.3
	 *
	 * @param  Models\Redirect $redirect   A redirect object model.
	 * @param  string          $requestUrl The full request url.
	 * @return bool|array                  Whether the redirect matches the url or a regex match array.
	 */
	private function matchRedirect( $redirect, $requestUrl ) {
		// Regex first.
		if ( $redirect->regex ) {
			$matches = $this->matchRegexRedirect( $redirect, $requestUrl );
			if ( empty( $matches ) ) {
				return false;
			}

			return $matches;
		}

		// Prevent redirect loop.
		if ( $this->isRedirectLoop( $redirect ) ) {
			return false;
		}

		// Try matching the source.
		if ( ! $this->matchSourceRedirect( $redirect, $requestUrl ) ) {
			return false;
		}

		// Try matching the query string parameters.
		if ( ! $this->matchQueryStringRedirect( $redirect, $requestUrl ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Match and cache regular redirects for a URL.
	 *
	 * @since 1.2.3
	 *
	 * @param  string     $requestUrl The full request url.
	 * @param  int|string $enabled    Enabled redirects 1|0|all.
	 * @return array                  An array of matched redirects.
	 */
	public function getRegularRedirects( $requestUrl, $enabled = 1 ) {
		$requestUrl = Utils\WpUri::excludeHomeUrl( $requestUrl );
		$redirects  = aioseoRedirects()->cache->getRedirects( $requestUrl );
		if ( null === $redirects ) {
			$redirects = $this->matchRegularRedirects( $requestUrl, $enabled );
			aioseoRedirects()->cache->setRedirects( $requestUrl, $redirects );
		}

		return $redirects;
	}

	/**
	 * Matches redirects for a URL.
	 *
	 * @since 1.2.3
	 *
	 * @param  string     $requestUrl The full request url.
	 * @param  int|string $enabled    Enabled redirects 1|0|all.
	 * @return array                  An array of matched redirects.
	 */
	private function matchRegularRedirects( $requestUrl, $enabled = 1 ) {
		$requestUrlNoQuery = wp_parse_url( $requestUrl, PHP_URL_PATH ) ?: '/';
		$redirects         = $this->getRedirectsBySource( $requestUrlNoQuery, $enabled );
		foreach ( $redirects as $key => $redirect ) {
			if ( ! $this->matchRedirect( $redirect, $requestUrl ) ) {
				unset( $redirects[ $key ] );
			}
		}

		return $redirects ?: [];
	}

	/**
	 * Match and cache regex redirects for a URL.
	 *
	 * @since 1.2.3
	 *
	 * @param  string     $requestUrl The request url.
	 * @param  int|string $enabled    Enabled redirects 1|0|all.
	 * @return array                  The regex redirects.
	 */
	public function getRegexRedirects( $requestUrl, $enabled = 1 ) {
		$requestUrl = Utils\WpUri::excludeHomeUrl( $requestUrl );
		$redirects  = aioseoRedirects()->cache->getRedirects( 'regex:' . $requestUrl );
		if ( null === $redirects ) {
			$redirects = $this->matchRegexRedirects( $requestUrl, $enabled );
			aioseoRedirects()->cache->setRedirects( 'regex:' . $requestUrl, $redirects );
		}

		return $redirects;
	}

	/**
	 * Matches redirects for a URL.
	 *
	 * @since 1.2.3
	 *
	 * @param  string     $requestUrl The full request url.
	 * @param  int|string $enabled    Enabled redirects 1|0|all.
	 * @return array                  An array of matched redirects.
	 */
	public function matchRegexRedirects( $requestUrl, $enabled = 1 ) {
		$redirects = $this->getAllRegexRedirects( $enabled );
		foreach ( $redirects as $key => &$redirect ) {
			$matches = $this->matchRedirect( $redirect, $requestUrl );
			if ( empty( $matches ) ) {
				unset( $redirects[ $key ] );
				continue;
			}

			$redirect->regexMatches = $matches;
		}

		return $redirects ?: [];
	}

	/**
	 * Gets all redirects for a URL. This is not cached and used for the Redirects post metabox.
	 *
	 * @since 1.1.7
	 *
	 * @param  string     $requestUrl The full request url.
	 * @param  int|string $enabled    Enabled redirects 1|0|all.
	 * @return array                  An array of matched redirects.
	 */
	public function getRedirects( $requestUrl, $enabled = 1 ) {
		$requestUrl = Utils\WpUri::excludeHomeUrl( $requestUrl );

		return array_merge(
			$this->matchRegularRedirects( $requestUrl, $enabled ),
			$this->matchRegexRedirects( $requestUrl, $enabled )
		);
	}

	/**
	 * Return all redirects for a source.
	 *
	 * @since 1.1.7
	 *
	 * @param  string     $requestUrl The request url.
	 * @param  int|string $enabled    Enabled redirects 1|0|all.
	 * @return array                  The redirects.
	 */
	public function getRedirectsBySource( $requestUrl, $enabled = 1 ) {
		$requestUrlNoQuery = wp_parse_url( $requestUrl );
		$requestUrlNoQuery = ! empty( $requestUrlNoQuery['path'] ) ? $requestUrlNoQuery['path'] : '/';

		$query = aioseo()->core->db->start( 'aioseo_redirects' )
			->where( 'source_url_match_hash', Utils\Request::getMatchedUrlHash( $requestUrlNoQuery ) )
			->orderBy( 'id DESC' );

		if ( 'all' !== $enabled ) {
			$query->where( 'enabled', $enabled );
		}

		return $query->run()->models( 'AIOSEO\\Plugin\\Addon\\Redirects\\Models\\Redirect' );
	}

	/**
	 * Return all redirects for a target.
	 *
	 * @since 1.2.7
	 *
	 * @param  string     $targetUrl  The target url.
	 * @param  int|string $enabled    Enabled redirects 1|0|all.
	 * @return array                  The redirects.
	 */
	public function getRedirectsByTarget( $targetUrl, $enabled = 1 ) {
		$targetUrlNoQuery = wp_parse_url( $targetUrl );
		$targetUrlNoQuery = ! empty( $targetUrlNoQuery['path'] ) ? $targetUrlNoQuery['path'] : '/';

		$query = aioseo()->core->db->start( 'aioseo_redirects' )
			->where( 'target_url_hash', Utils\Request::getUrlHash( $targetUrlNoQuery ) )
			->orderBy( 'id DESC' );

		if ( 'all' !== $enabled ) {
			$query->where( 'enabled', $enabled );
		}

		return $query->run()->models( 'AIOSEO\\Plugin\\Addon\\Redirects\\Models\\Redirect' );
	}

	/**
	 * Return all regex redirects.
	 *
	 * @since 1.2.3
	 *
	 * @param  int|string $enabled Enabled redirects 1|0|all.
	 * @return array               The regex redirects.
	 */
	private function getAllRegexRedirects( $enabled ) {
		$query = aioseo()->core->db->start( 'aioseo_redirects' )
			->where( 'source_url_match_hash', Utils\Request::getRegexHash() )
			->orderBy( 'id DESC' );

		if ( 'all' !== $enabled ) {
			$query->where( 'enabled', $enabled );
		}

		return $query->run()->models( 'AIOSEO\\Plugin\\Addon\\Redirects\\Models\\Redirect' );
	}

	/**
	 * Checks whether the current request is for a page builder preview.
	 * If so, don't need to check for redirects because we'll otherwise break page builder.
	 *
	 * @since 1.3.3
	 *
	 * @return bool Whether the current request is for the page builder preview.
	 */
	public function isPageBuilderPreviewRequest() {
		$pageBuilderRequest = [
			'elementor'  => [ 'elementor-preview' ],
			'wpbakery'   => [ 'vc_editable' ],
			'avada'      => [ 'fb-edit', 'builder_id' ],
			'divi'       => [ 'et_fb' ],
			'siteorigin' => [ 'siteorigin_panels_live_editor' ],
			'thrive'     => [ 'tve', 'tcbf' ]
		];

		foreach ( $pageBuilderRequest as $pageBuilder => $possibleRequests ) {
			foreach ( $possibleRequests as $request ) {
				if ( ! empty( $_REQUEST[ $request ] ) ) { // phpcs:ignore HM.Security.NonceVerification.Recommended
					return isset( aioseo()->standalone->pageBuilderIntegrations[ $pageBuilder ] ) && aioseo()->standalone->pageBuilderIntegrations[ $pageBuilder ]->isActive();
				}
			}
		}

		return false;
	}

	/**
	 * Return a redirect for a post id.
	 *
	 * @since 1.2.7
	 *
	 * @param  int             $postId The post ID.
	 * @return Models\Redirect         The redirect tied to the post id.
	 */
	public function getRedirectByPostId( $postId ) {
		$query = aioseo()->core->db->start( 'aioseo_redirects' )->where( 'post_id', $postId )->orderBy( 'id DESC' );

		// This assumes there's only one tied redirect at any given time.
		return $query->run()->model( 'AIOSEO\\Plugin\\Addon\\Redirects\\Models\\Redirect' );
	}
}