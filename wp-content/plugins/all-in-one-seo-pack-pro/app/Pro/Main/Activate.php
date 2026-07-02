<?php
namespace AIOSEO\Plugin\Pro\Main;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Main as CommonMain;

/**
 * Activate class with methods that are called.
 *
 * @since 4.0.0
 */
class Activate extends CommonMain\Activate {
	/**
	 * Runs on activate.
	 *
	 * @since 4.0.0
	 *
	 * @param  bool $networkWide Whether or not this is a network wide activation.
	 * @return void
	 */
	public function activate( $networkWide ) {
		if ( is_multisite() && $networkWide ) {
			foreach ( aioseo()->helpers->getSites()['sites'] as $site ) {
				aioseo()->helpers->switchToBlog( $site->blog_id );
				aioseo()->access->addCapabilities();
				aioseo()->helpers->restoreCurrentBlog();
			}
		}

		// Check for a one-time-password (OTP) activation.
		$this->checkForOtpActivation();

		// Run the parent activate method.
		parent::activate( $networkWide );

		// Let's re-sync the license.
		if ( aioseo()->license->isActive() ) {
			aioseo()->license->activate();
		}
	}

	/**
	 * Check for OTP activation file and process it.
	 *
	 * @since 4.8.1
	 *
	 * @return void
	 */
	private function checkForOtpActivation() {
		$fs      = aioseo()->core->fs->noConflict();
		$otpFile = AIOSEO_DIR . '/otp.txt';

		// If the OTP file does not exist, return.
		if ( ! $fs->exists( $otpFile ) ) {
			return;
		}

		// Get the OTP contents of the file.
		$otpContents = sanitize_text_field( $fs->getContents( $otpFile ) );

		// Delete the OTP file.
		$fs->fs->delete( $otpFile );

		// If the license is already active, return early.
		if ( aioseo()->license->isActive() ) {
			return;
		}

		// If the OTP contents are empty, return.
		if ( empty( $otpContents ) ) {
			return;
		}

		// Activate the license with the OTP.
		aioseo()->license->activateWithOtp( $otpContents );
	}
}