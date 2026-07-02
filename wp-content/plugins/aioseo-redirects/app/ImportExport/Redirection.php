<?php
namespace AIOSEO\Plugin\Addon\Redirects\ImportExport;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\Redirects\Models;
use AIOSEO\Plugin\Addon\Redirects\Utils;

class Redirection extends Importer {
	/**
	 * A list of plugins to look for to import.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	public $plugins = [
		[
			'name'     => 'Redirection',
			'version'  => '5.0',
			'basename' => 'redirection/redirection.php',
			'slug'     => 'redirection'
		]
	];

	/**
	 * Import.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function doImport() {
		if ( ! aioseo()->core->db->tableExists( 'redirection_items' ) ) {
			return;
		}

		$rules = aioseo()->core->db->start( 'redirection_items' )
			->whereIn( 'action_type', [ 'url', 'pass', 'error' ] )
			->run()
			->result();
		foreach ( $rules as $rule ) {
			if ( 'url' === $rule->action_type && ! $this->validateStatusCode( $rule->action_code ) ) {
				continue;
			}

			if ( empty( $rule->action_data ) ) {
				$rule->action_data = '/';
			}

			if ( is_numeric( $rule->url ) ) {
				$rule->url = str_replace( aioseo()->helpers->getSiteUrl(), '', get_permalink( $rule->url ) );
			}

			// Codes higher than 400 don't have a target URL.
			if ( 400 <= $rule->action_code ) {
				$rule->action_data = '';
			}

			$matchData  = json_decode( $rule->match_data );
			$redirect   = Models\Redirect::getRedirectBySourceUrl( $rule->url );
			$redirect->set( [
				'source_url'   => $rule->url,
				'target_url'   => $rule->action_data,
				'type'         => $rule->action_code,
				'query_param'  => $this->getQueryParam( $matchData ),
				'group'        => 'manual',
				'regex'        => 1 === (int) $rule->regex,
				'ignore_slash' => ! isset( $matchData->source->flag_trailing ) || $matchData->source->flag_trailing,
				'ignore_case'  => ! isset( $matchData->source->flag_case ) || $matchData->source->flag_case,
				'enabled'      => 'enabled' === $rule->status
			] );
			$redirect->save();
		}
	}

	/**
	 * Get the proper query parameter.
	 *
	 * @since 1.0.0
	 *
	 * @param  Object $data The data to look through for the query param.
	 * @return string       A string for the query param type.
	 */
	private function getQueryParam( $data ) {
		$default = json_decode( aioseoRedirects()->options->redirectDefaults->queryParam )->value;
		if ( isset( $data->source->flag_query ) ) {
			return 'exactorder' === $data->source->flag_query ? 'exact' : $data->source->flag_query;
		}

		if ( ! function_exists( 'red_get_options' ) ) {
			return $default;
		}

		$settings = red_get_options();
		if ( empty( $settings['flag_query'] ) ) {
			return $default;
		}

		return 'exactorder' === $settings['flag_query'] ? 'exact' : $settings['flag_query'];
	}
}