<div class="listing-added-notice">
	<div class="booking-confirmation-page alert alert-info mb-0">
		<h4 class="margin-top-30"><?php esc_html_e('Thanks for your submission!','truelysell_core') ?></h4>
		<p class="mb-0"><?php // Successful

		switch ( get_post_status( $data->id ) ) {
			case 'publish' :
				esc_html_e( 'Your service has been published.', 'truelysell_core' );
			break;
			case 'pending_payment' :
				esc_html_e( 'Your service has been saved and is pending payment. It will be published once the order is completed', 'truelysell_core' );
			break;
			case 'pending' :
			case 'draft' :
				esc_html_e( 'Your service has been saved and is awaiting admin approval', 'truelysell_core' );
			break;
			default :
				esc_html_e( 'Your changes have been saved.', 'truelysell_core' );
			break;
		} ?>
		</p>
		<p class="mb-0 mt-2"><?php esc_html_e( 'Please go to My Services and review your availability — by default every day is shown as available, so update it now if that\'s not correct.', 'truelysell_core' ); ?></p>

	</div>

	<?php if(get_post_status( $data->id ) == 'publish') : ?>
			<a class="button btn btn-primary mt-4" href="<?php echo get_permalink( $data->id ); ?>"><?php  esc_html_e( 'View &rarr;', 'truelysell_core' );  ?></a>
		<?php endif; ?>
</div>
