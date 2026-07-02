<?php
namespace AIOSEO\Plugin\Addon\Redirects\Main\Server;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main class to work with server redirects.
 *
 * @since 1.0.0
 */
class Unknown extends Server {
	/**
	 * Formats a redirect for use in the export.
	 *
	 * @param  object $redirect The redirect to format.
	 * @return string           The formatted redirect.
	 */
	public function format( $redirect ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return;
	}

	/**
	 * Formats a relocation.
	 *
	 * @since 1.1.0
	 *
	 * @param  string $relocationAddress The redirect to format.
	 * @return string                    The formatted redirect.
	 */
	public function formatRelocation( $relocationAddress, $protectedPaths = '' ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return;
	}

	/**
	 * Formats an alias.
	 *
	 * @since 1.1.0
	 *
	 * @param  string $alias The alias to format.
	 * @return string        The formatted alias.
	 */
	public function formatAlias( $alias ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return;
	}

	/**
	 * Formats a canonical redirect.
	 *
	 * @since 1.1.0
	 *
	 * @return void|string The formatted redirect.
	 */
	public function formatCanonical( $url, $https = false, $preferredDomain = '' ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return;
	}

	/**
	 * Adds an X-Redirect-By header if allowed by the filter.
	 *
	 * @since 1.0.0
	 *
	 * @return string The redirect header.
	 */
	private function addRedirectHeader() {
		return '';
	}

	/**
	 * Get the test redirect.
	 *
	 * @since 1.1.4
	 *
	 * @return string The test redirect.
	 */
	protected function getAioseoRedirectTest() {
		return;
	}
}