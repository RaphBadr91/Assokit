<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Import;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Models;

class Notifications {
	/**
	 * The notifications.
	 *
	 * @since 1.3.0
	 *
	 * @var array
	 */
	private static $notifications = [];

	/**
	 * Class constructor.
	 *
	 * @since 1.3.0
	 */
	public function __construct() {
		add_action( 'aioseo_local_seo_imported', [ $this, 'pushNotifications' ] );
	}

	/**
	 * Adds a notification.
	 *
	 * @since 1.3.0
	 *
	 * @param  array $args The notification arguments from Models\Notification.
	 * @return void
	 */
	public function addNotification( $args ) {
		$notificationName = 'local-business-' . md5( $args['title'] );
		if ( ! empty( self::$notifications[ $notificationName ] ) ) {
			return;
		}

		self::$notifications[ $notificationName ] = array_merge( [
			'slug'              => uniqid(),
			'notification_name' => $notificationName,
			'type'              => 'warning',
			'level'             => [ 'all' ],
			'start'             => gmdate( 'Y-m-d H:i:s' )
		], $args );
	}

	/**
	 * Adds a notification for the user to re-enter the business type.
	 *
	 * @since 1.3.0
	 *
	 * @param  string $businessType The business type.
	 * @return void
	 */
	public function businessTypeNotSupported( $businessType ) {
		$this->addNotification( [
			'title'          => __( 'Re-Enter Business Type in Local Business', 'aioseo-local-business' ),
			'content'        => sprintf(
			// Translators: 1 - The country.
				__( 'For technical reasons, we were unable to migrate the business type you entered for your Local Business schema markup.
				Please enter it (%1$s) again by using the dropdown menu.', 'aioseo-local-business' ),
				"<strong>$businessType</strong>"
			),
			'button1_label'  => __( 'Fix Now', 'aioseo-local-business' ),
			'button1_action' => 'http://route#aioseo-local-seo&aioseo-scroll=info-business-type&aioseo-highlight=info-business-type:locations',
			'button2_label'  => __( 'Remind Me Later', 'aioseo-local-business' ),
			'button2_action' => 'http://action#notification/import-local-business-type-reminder'
		] );
	}

	/**
	 * Adds a notification for the user to re-enter the country.
	 *
	 * @since 1.3.0
	 *
	 * @param  string $country The country.
	 * @return void
	 */
	public function countryNotSupported( $country ) {
		$this->addNotification( [
			'title'          => __( 'Re-Enter Country in Local Business', 'aioseo-local-business' ),
			'content'        => sprintf(
			// Translators: 1 - The country.
				__( 'For technical reasons, we were unable to migrate the country you entered for your Local Business schema markup.
				Please enter it (%1$s) again by using the dropdown menu.', 'aioseo-local-business' ),
				"<strong>$country</strong>"
			),
			'button1_label'  => __( 'Fix Now', 'aioseo-local-business' ),
			'button1_action' => 'http://route#aioseo-local-seo&aioseo-scroll=info-business-address-row&aioseo-highlight=aioseo-local-business-business-country:business-info',
			'button2_label'  => __( 'Remind Me Later', 'aioseo-local-business' ),
			'button2_action' => 'http://action#notification/import-local-business-country-reminder'
		] );
	}

	/**
	 * Adds a notification for the user to re-enter the phone number.
	 *
	 * @since 1.3.0
	 *
	 * @param  string $phoneNumber The phone number.
	 * @return void
	 */
	public function phoneNumberNotSupported( $phoneNumber ) {
		$this->addNotification( [
			'title'          => __( 'Invalid Phone Number for Local SEO', 'aioseo-local-business' ),
			'content'        => sprintf(
			// Translators: 1 - The phone number.
				__( 'The phone number that you previously entered for your Local Business schema markup is invalid.
					As it needs to be internationally formatted, please enter it (%1$s) again with the country code, e.g. +1 (555) 555-1234.', 'aioseo-local-business' ),
				"<strong>$phoneNumber</strong>"
			),
			'button1_label'  => __( 'Fix Now', 'aioseo-local-business' ),
			'button1_action' => 'http://route#aioseo-local-seo&aioseo-scroll=info-business-contact-row&aioseo-highlight=aioseo-local-business-phone-number:business-info',
			'button2_label'  => __( 'Remind Me Later', 'aioseo-local-business' ),
			'button2_action' => 'http://action#notification/import-local-business-number-reminder'
		] );
	}

	/**
	 * Adds a notification for the user to re-enter the fax number.
	 *
	 * @since 1.3.0
	 *
	 * @param  string $faxNumber The fax number.
	 * @return void
	 */
	public function faxNumberNotSupported( $faxNumber ) {
		$this->addNotification( [
			'title'          => __( 'Invalid Fax Number for Local SEO', 'aioseo-local-business' ),
			'content'        => sprintf(
			// Translators: 1 - The fax number.
				__( 'The fax number that you previously entered for your Local Business schema markup is invalid.
					As it needs to be internationally formatted, please enter it (%1$s) again with the country code, e.g. +1 (555) 555-1234.', 'aioseo-local-business' ),
				"<strong>$faxNumber</strong>"
			),
			'button1_label'  => __( 'Fix Now', 'aioseo-local-business' ),
			'button1_action' => 'http://route#aioseo-local-seo&aioseo-scroll=info-business-contact-row&aioseo-highlight=aioseo-local-business-fax-number:business-info',
			'button2_label'  => __( 'Remind Me Later', 'aioseo-local-business' ),
			'button2_action' => 'http://action#notification/import-local-business-fax-reminder'
		] );
	}

	/**
	 * Adds a notification for the user to re-enter the currencies.
	 *
	 * @since 1.3.0
	 *
	 * @param  array $currencies The currencies.
	 * @return void
	 */
	public function currenciesNotSupported( $currencies = [] ) {
		$currenciesList = '<ul>';
		foreach ( $currencies as $currency ) {
			$currenciesList .= '<li>' . esc_html( $currency ) . '<li>';
		}
		$currenciesList .= '</ul>';

		$this->addNotification( [
			'title'          => __( 'Invalid Currencies for Local SEO', 'aioseo-local-business' ),
			'content'        => sprintf(
			// Translators: 1 - The phone number.
				__( 'One or more currencies that you previously entered for your Local Business schema markup are invalid.
					Please select these again using our dropdown menu.</br>%1$s', 'aioseo-local-business' ),
				"<strong>$currenciesList</strong>"
			),
			'button1_label'  => __( 'Fix Now', 'aioseo-local-business' ),
			'button1_action' => 'http://route#aioseo-local-seo&aioseo-scroll=info-payment-info-row&aioseo-highlight=aioseo-local-business-currencies-accepted:business-info',
			'button2_label'  => __( 'Remind Me Later', 'aioseo-local-business' ),
			'button2_action' => 'http://action#notification/import-local-business-currencies-reminder'
		] );
	}

	/**
	 * Pushes notifications to AIOSEO notifications.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function pushNotifications() {
		foreach ( self::$notifications as $notification ) {
			$exists = Models\Notification::getNotificationByName( $notification['notification_name'] );
			if ( $exists->exists() ) {
				return;
			}

			Models\Notification::addNotification( $notification );
		}

		self::$notifications = [];
	}
}