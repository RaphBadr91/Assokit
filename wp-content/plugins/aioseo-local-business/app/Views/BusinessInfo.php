<?php
/**
 * This is the output for Local Business Information on the frontend.
 *
 * @since 1.1.0
 * @version 1.2.1
 */

// phpcs:disable VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div class="aioseo-location-box aioseo-location-<?php echo absint( $postId ); ?> <?php echo esc_attr( $instance['class'] ); ?>"> <?php // phpcs:ignore Generic.Files.LineLength.MaxExceeded ?>
	<?php
	$svgLocationPin = apply_filters( 'aioseo_local_business_info_location_icon', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="26" height="26" fill="currentColor"><rect x="0" fill="none" width="20" height="20"/><g><path fill="currentColor" d="M10 2C6.69 2 4 4.69 4 8c0 2.02 1.17 3.71 2.53 4.89.43.37 1.18.96 1.85 1.83.74.97 1.41 2.01 1.62 2.71.21-.7.88-1.74 1.62-2.71.67-.87 1.42-1.46 1.85-1.83C14.83 11.71 16 10.02 16 8c0-3.31-2.69-6-6-6zm0 2.56c1.9 0 3.44 1.54 3.44 3.44S11.9 11.44 10 11.44 6.56 9.9 6.56 8 8.1 4.56 10 4.56z"/></g></svg>' ); // phpcs:ignore Generic.Files.LineLength.MaxExceeded
	$svgPhone       = apply_filters( 'aioseo_local_business_info_phone_icon', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="20" height="20" fill="currentColor"><rect x="0" fill="none" width="20" height="20"/><g><path fill="currentColor" d="M12.06 6l-.21-.2c-.52-.54-.43-.79.08-1.3l2.72-2.75c.81-.82.96-1.21 1.73-.48l.21.2zm.53.45l4.4-4.4c.7.94 2.34 3.47 1.53 5.34-.73 1.67-1.09 1.75-2 3-1.85 2.11-4.18 4.37-6 6.07-1.26.91-1.31 1.33-3 2-1.8.71-4.4-.89-5.38-1.56l4.4-4.4 1.18 1.62c.34.46 1.2-.06 1.8-.66 1.04-1.05 3.18-3.18 4-4.07.59-.59 1.12-1.45.66-1.8zM1.57 16.5l-.21-.21c-.68-.74-.29-.9.52-1.7l2.74-2.72c.51-.49.75-.6 1.27-.11l.2.21z"/></g></svg>' ); // phpcs:ignore Generic.Files.LineLength.MaxExceeded
	$svgFax         = apply_filters( 'aioseo_local_business_info_fax_icon', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="20" height="20" fill="currentColor"><rect x="0" fill="none" width="20" height="20"/><g><path d="M12 11H7v1h5v-1zm1 4H7v1h6v-1zm-3-2H7v1h3v-1zm7-7h-2V2H5v4H3c-.6 0-1 .4-1 1v5c0 .6.4 1 1 1h2v5h10v-5h2c.6 0 1-.4 1-1V7c0-.6-.4-1-1-1zm-3 11H6v-7h8v7zm0-11H6V3h8v3zm2 3h-1V8h1v1z"/></g></svg>' ); // phpcs:ignore Generic.Files.LineLength.MaxExceeded
	$svgEmail       = apply_filters( 'aioseo_local_business_info_email_icon', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="23" height="23" fill="currentColor"><rect x="0" fill="none" width="20" height="20"/><g><path fill="currentColor" d="M3.87 4h13.25C18.37 4 19 4.59 19 5.79v8.42c0 1.19-.63 1.79-1.88 1.79H3.87c-1.25 0-1.88-.6-1.88-1.79V5.79c0-1.2.63-1.79 1.88-1.79zm6.62 8.6l6.74-5.53c.24-.2.43-.66.13-1.07-.29-.41-.82-.42-1.17-.17l-5.7 3.86L4.8 5.83c-.35-.25-.88-.24-1.17.17-.3.41-.11.87.13 1.07z"/></g></svg>' ); // phpcs:ignore Generic.Files.LineLength.MaxExceeded
	$phoneHasLink   = apply_filters( 'aioseo_local_business_info_phone_has_link', true );
	$emailHasLink   = apply_filters( 'aioseo_local_business_info_email_has_link', true );
	?>
	<?php if ( $instance['showAddress'] || $instance['showVat'] || $instance['showTax'] || $instance['showChamberId'] || $instance['showName'] ) { ?>
		<div class="col">
			<div class="d-flex">
				<?php if ( $instance['showIcons'] ) : ?>
					<div class="col-auto">
						<span class="icon location"><?php echo aioseo()->helpers->escSvg( $svgLocationPin ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					</div>
				<?php endif; ?>
				<div class="col">

					<?php if ( ! $instance['showAddress'] && $instance['showName'] && ! empty( $locationData->name ) ) { ?>
						<div class="aioseo-name-details">
							<strong><?php echo esc_html( $locationData->name ); ?></strong>
						</div>
					<?php } ?>

					<?php if ( $instance['showAddress'] ) { ?>


						<?php if ( $instance['showLabels'] ) : ?>
							<div class="label">
								<?php echo esc_html( $instance['addressLabel'] ); ?>
							</div>
						<?php endif; ?>

						<div class="aioseo-address-details">
							<?php if ( $instance['showName'] && ! empty( $locationData->name ) ) { ?>
								<div class="aioseo-name-details">
									<strong><?php echo esc_html( $locationData->name ); ?></strong>
								</div>
							<?php } ?>
							<?php
							$haveAddress = array_filter( (array) $locationData->address );
							unset( $haveAddress['addressFormat'] );
							if ( ! empty( $haveAddress ) && isset( $locationData->address->addressFormat ) ) {
								$value = aioseoLocalBusiness()->tags->replaceTags( aioseo()->helpers->decodeHtmlEntities( $locationData->address->addressFormat ), $postId, $locationData->address );
								echo nl2br( wp_kses_post( $value ) );
							}
							?>
						</div>
					<?php } ?>

					<div class="aioseo-tax-details">
						<?php if ( $instance['showVat'] && ! empty( $locationData->ids->vat ) ) { ?>
							<div>

								<?php if ( $instance['showLabels'] ) : ?>
									<div class="label">
										<?php echo esc_html( $instance['vatIdLabel'] ); ?>
									</div>
								<?php endif; ?>

								<span class="item">
									<?php echo esc_html( $locationData->ids->vat ); ?>
								</span>
							</div>
						<?php } ?>
						<?php if ( $instance['showTax'] && ! empty( $locationData->ids->tax ) ) { ?>
							<div>
								<?php if ( $instance['showLabels'] ) : ?>
									<div class="label">
										<?php echo esc_html( $instance['taxIdLabel'] ); ?>
									</div>
								<?php endif; ?>
								<span class="item">
									<?php echo esc_html( $locationData->ids->tax ); ?>
								</span>
							</div>
						<?php } ?>
						<?php if ( $instance['showChamberId'] && ! empty( $locationData->ids->chamberID ) ) { ?>
							<div>
								<?php if ( $instance['showLabels'] ) : ?>
									<div class="label">
										<?php esc_html_e( 'Chamber ID:', 'aioseo-local-business' ); ?>
									</div>
								<?php endif; ?>
								<span class="item">
									<?php echo esc_html( $locationData->ids->chamberID ); ?>
								</span>
							</div>
						<?php } ?>
					</div>
				</div>
			</div>
		</div>
	<?php } ?>

	<div class="col-auto">
		<div class="aioseo-contact-details">
			<?php if ( $instance['showPhone'] && ! empty( $locationData->contact->phoneFormatted ) ) { ?>
				<div class="d-flex">
					<?php if ( $instance['showIcons'] ) : ?>
						<div class="col-auto">
							<span class="icon"><?php echo aioseo()->helpers->escSvg( $svgPhone ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						</div>
					<?php endif; ?>

					<div class="col">
						<?php if ( $instance['showLabels'] ) : ?>
							<div class="label">
								<?php echo esc_html( $instance['phoneLabel'] ); ?>
							</div>
						<?php endif; ?>
						<?php if ( ! empty( $locationData->contact->phone ) && ! empty( $locationData->contact->phoneFormatted ) ) : ?>
							<span class="item">
								<?php if ( $phoneHasLink ) : ?>
									<a href="tel:<?php echo esc_attr( $locationData->contact->phone ) ?>">
								<?php endif; ?>
									<?php echo esc_html( aioseoLocalBusiness()->locations->formatPhone( $locationData->contact->phoneFormatted, $instance['showCountryCode'] ) ); ?>
								<?php if ( $phoneHasLink ) : ?>
									</a>
								<?php endif; ?>
							</span>
						<?php endif; ?>
					</div>
				</div>
			<?php } ?>

			<?php if ( $instance['showFax'] && ! empty( $locationData->contact->faxFormatted ) ) { ?>
				<div class="d-flex">
					<?php if ( $instance['showIcons'] ) : ?>
						<div class="col-auto">
							<span class="icon"><?php echo $svgFax; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						</div>
					<?php endif; ?>

					<div class="col">
						<?php if ( $instance['showLabels'] ) : ?>
							<div class="label">
								<?php echo esc_html( $instance['faxLabel'] ); ?>
							</div>
						<?php endif; ?>
						<span class="item">
							<?php echo esc_html( aioseoLocalBusiness()->locations->formatPhone( $locationData->contact->faxFormatted, $instance['showCountryCode'] ) ); ?>
						</span>
					</div>
				</div>
			<?php } ?>

			<?php if ( $instance['showEmail'] && ! empty( $locationData->contact->email ) ) { ?>
				<div class="d-flex">
					<?php if ( $instance['showIcons'] ) : ?>
						<div class="col-auto">
							<span class="icon"><?php echo aioseo()->helpers->escSvg( $svgEmail ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						</div>
					<?php endif; ?>

					<div class="col">
						<?php if ( $instance['showLabels'] ) : ?>
							<div class="label">
								<?php echo esc_html( $instance['emailLabel'] ); ?>
							</div>
						<?php endif; ?>
						<?php if ( ! empty( $locationData->contact->email ) ) : ?>
							<span class="item">
								<?php if ( $emailHasLink && is_email( $locationData->contact->email ) ) : ?>
									<a href="mailto:<?php echo esc_attr( $locationData->contact->email ) ?>">
								<?php endif; ?>
								<?php echo esc_html( $locationData->contact->email ); ?>
								<?php if ( $emailHasLink && is_email( $locationData->contact->email ) ) : ?>
									</a>
								<?php endif; ?>
							</span>
						<?php endif; ?>
					</div>
				</div>
			<?php } ?>
		</div>
	</div>
</div>