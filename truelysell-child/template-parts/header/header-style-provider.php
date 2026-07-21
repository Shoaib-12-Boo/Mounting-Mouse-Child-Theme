<?php 



 $header_logo =  truelysell_fl_framework_getoptions('logo_image');
if(isset($header_logo) && $header_logo != '')
{
	$header_logo_url = $header_logo['url'];
}
else
{
	$header_logo_url = get_theme_file_uri().'/assets/images/logo.svg';
}
 
$headerm_logo =  truelysell_fl_framework_getoptions('logo_image_mobile');
if(isset($headerm_logo) && $headerm_logo != '')
{
$headerm_logo_url = $headerm_logo['url'];
}
else
{
$headerm_logo_url = get_theme_file_uri().'/assets/images/logo.svg';
}
 

$user_id = get_current_user_id();
$current_user = wp_get_current_user();
$roles = $current_user->roles;
$role = array_shift($roles);
if (!empty($current_user->user_firstname)) {
    $name = $current_user->user_firstname;
} else {
    $name =  $current_user->display_name;
}
?>

<!-- Header -->
<div class="header provider-header">
<!-- Logo -->
<div class="header-left active">
	<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="logo logo-normal">
		<img src="<?php echo esc_url($header_logo_url); ?>" alt="Logo">
	</a>
	<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="logo-small">
		<img src="<?php echo esc_url($headerm_logo_url); ?>" alt="Logo">
	</a>
	<a id="toggle_btn" href="javascript:void(0);">
		<i class="ti ti-menu-deep"></i>
	</a>
</div>
<!-- /Logo -->

<a id="mobile_btns" class="mobile_btn" href="#sidebar">
	<span class="bar-icon">
		<span></span>
		<span></span>
		<span></span>
	</span>
</a>

<div class="header-user">
	<div class="nav user-menu">
  	   <div class="d-flex align-items-center">
			<div class="me-2 site-link">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="d-flex align-items-center justify-content-center me-2"><i class="feather-globe me-1"></i><?php echo esc_html('Visit Website','truelysell'); ?></a>
			</div>
		 
  
			<div class="dropdown">
				<a href="javascript:void(0);" data-bs-toggle="dropdown">
					<div class="booking-user d-flex align-items-center test2">
						<span class="user-img">
  						<?php  
							//$default="36";
						   // $alt= "";
                           //echo get_avatar($current_user->user_email, 36, $default, $alt, array( 'class' => array( 'rounded-circle' ) )); 
 							echo get_avatar( $user_id, '', '', '', [ 'class' => 'rounded-circle' ] ); 
 						?>
						</span>
					</div>
				</a>
				<ul class="dropdown-menu p-2">
					<li><a class="dropdown-item d-flex align-items-center" href="<?php echo wp_logout_url(home_url()); ?>"><i class="ti ti-logout me-1"></i><?php echo esc_html('Logout','truelysell'); ?></a></li>
				</ul>
			</div>
	   </div>

	</div>
</div>

<!-- Mobile Menu -->
<div class="dropdown mobile-user-menu">
	<a href="javascript:void(0);" class="nav-link dropdown-toggle" data-bs-toggle="dropdown"
		aria-expanded="false"><i class="fa fa-ellipsis-v"></i></a>
	<div class="dropdown-menu dropdown-menu-end">
 
 <?php if (in_array($role, array('administrator', 'admin', 'owner', 'seller'))) : ?>
  <?php $profile_page = truelysell_fl_framework_getoptions('profile_page');
if ($profile_page) : ?>
		<a class="dropdown-item" href="<?php echo esc_url(get_permalink($profile_page)); ?>"><?php esc_html_e('Settings', 'truelysell'); ?></a>

 <?php endif; ?>
 <?php endif; ?>

		<!-- <a class="dropdown-item" href="#">Settings</a> -->
 		<a class="dropdown-item" href="<?php echo wp_logout_url(home_url()); ?>"><?php echo esc_html('Logout','truelysell'); ?></a>
	</div>
</div>
<!-- /Mobile Menu -->

</div>
<!-- /Header -->
 

 
<!-- Sidebar -->
<div class="sidebar" id="sidebar">
            <div class="sidebar-inner slimscroll">
                <div id="sidebar-menu" class="sidebar-menu">
			 
                    <ul>
 					<?php if (in_array($role, array('administrator', 'admin', 'owner', 'seller'))) : ?>
						<?php $dashboard_page = truelysell_fl_framework_getoptions('dashboard_page');
						if ($dashboard_page) : ?>
							<li <?php if ($post->ID == $dashboard_page) : ?>class="active" <?php endif; ?> class=""><a href="<?php echo esc_url(get_permalink($dashboard_page)); ?>" class=""><i class="ti ti-layout-grid"></i><span><?php esc_html_e('Dashboard', 'truelysell'); ?></span></a></li>
						<?php endif; ?>
					<?php endif; ?>

 
 

 
					<!-- My Services -->
					<?php if (in_array($role, array('administrator', 'admin', 'owner', 'seller'))) : ?>
						<?php $submit_page = truelysell_fl_framework_getoptions('submit_page');
						if ($submit_page) : ?>
							<li <?php if ($post->ID == $submit_page) : ?> class="active" <?php endif; ?> class="" ><a href="<?php echo esc_url(get_permalink($submit_page)); ?>" class=""><i class="ti ti-circle-plus me-2"></i> <span><?php esc_html_e('Add Service', 'truelysell'); ?></span></a></li>
						<?php endif; ?>
						<?php endif; ?>

   					 
						<?php if (in_array($role, array('administrator', 'admin', 'owner', 'seller'))) : ?>
						<?php $listings_page = truelysell_fl_framework_getoptions('listings_page');
						if ($listings_page) : ?>
							<li <?php if ($post->ID == $listings_page) : ?> class="active" <?php endif; ?> class="" ><a href="<?php echo esc_url(get_permalink($listings_page)); ?>" class=""><i class="ti ti-briefcase me-2"></i> <span><?php esc_html_e('My Services', 'truelysell'); ?></span></a></li>
						<?php endif; ?>
						<?php endif; ?>
 
						
					<!-- My Services End -->


					 <?php if (in_array($role, array('administrator', 'admin', 'owner', 'seller'))) : ?>
						<?php $calendar_bookings_page = truelysell_fl_framework_getoptions('calendar_bookings_page');
						if ($calendar_bookings_page) : ?>
							<li class="<?php if ($post->ID == $calendar_bookings_page) : ?> active <?php endif; ?>" ><a href="<?php echo esc_url(get_permalink($calendar_bookings_page)); ?>" ><i class="ti ti-calendar-month me-2"></i><span><?php esc_html_e('Bookings Calendar', 'truelysell'); ?></span></a>
							</li>
						<?php endif; ?>
							<?php endif; ?>

					<?php if (in_array($role, array('administrator', 'admin', 'owner', 'seller'))) : ?>
						<?php $bookings_page = truelysell_fl_framework_getoptions('bookings_page');
						if ($bookings_page) : ?>
							<li class="<?php if ($post->ID == $bookings_page) : ?> active <?php endif; ?>" ><a href="<?php echo esc_url(get_permalink($bookings_page)); ?>" ><i class="ti ti-calendar-month me-2"></i><span><?php esc_html_e('Booking List', 'truelysell'); ?></span></a>
							</li>
						<?php endif; ?>
					<?php endif; ?>
 					<?php if (in_array($role, array('administrator', 'admin', 'owner', 'seller'))) : ?>
						<?php $payout_page = truelysell_fl_framework_getoptions('payout_page');
						if ($payout_page) : ?>
							<li class="<?php if ($post->ID == $payout_page) : ?> active <?php endif; ?>"><a href="<?php echo esc_url(get_permalink($payout_page)); ?>" ><i class="ti ti-wallet me-2"></i><span><?php esc_html_e('Payout', 'truelysell'); ?></span></a>
							</li>
						<?php endif; ?>
					<?php endif; ?>

					<!-- wallet -->
					<?php if (in_array($role, array('administrator', 'admin', 'owner', 'seller'))) : ?>
						<?php $wallet_page = truelysell_fl_framework_getoptions('wallet_page');
						if ($wallet_page) : ?>
							<li class=" <?php if ($post->ID == $wallet_page) : ?> active<?php endif; ?>" ><a href="<?php echo esc_url(get_permalink($wallet_page)); ?>" ><i class="ti ti-cash-banknote me-2"></i><span><?php esc_html_e('Earnings', 'truelysell'); ?></span></a>
							</li>
						<?php endif; ?>
					<?php endif; ?>


					<!-- Staff -->

					<?php
					$enable_staff_module = truelysell_fl_framework_getoptions('enable_staff_module');

					if($enable_staff_module === '1') {

					?>

					<li class="submenu">
							<a href="javascript:void(0);" class="active subdrop"><i class="ti ti-user"></i><span>Staff</span><span class="menu-arrow"></span></a>
							<ul style="display: block;">

							<?php if (in_array($role, array('administrator', 'admin', 'owner', 'seller'))) : ?>
						<?php $addnew_staff_page = truelysell_fl_framework_getoptions('addnew_staff_page');
						if ($addnew_staff_page) : ?>
							<li class=" <?php if ($post->ID == $addnew_staff_page) : ?> active<?php endif; ?>" ><a href="<?php echo esc_url(get_permalink($addnew_staff_page)); ?>" ><i class="ti ti-chevrons-right me-2"></i><span><?php esc_html_e('Add Staff', 'truelysell'); ?></span></a>
							</li>
						<?php endif; ?>

						<?php $list_staff_page = truelysell_fl_framework_getoptions('list_staff_page');
						if ($list_staff_page) : ?>
							<li class=" <?php if ($post->ID == $list_staff_page) : ?> active<?php endif; ?>" ><a href="<?php echo esc_url(get_permalink($list_staff_page)); ?>" ><i class="ti ti-chevrons-right me-2"></i><span><?php esc_html_e('List Staff', 'truelysell'); ?></span></a>
							</li>
						<?php endif; ?>

					<?php endif; ?>

								
								
								
							</ul>
						</li>

					<?php
					}
					?>


					     <!-- Reviews -->
						  
						 <?php $reviews_page = truelysell_fl_framework_getoptions('reviews_page');
					if ($reviews_page) : ?>
						<li id="<?php echo esc_html($role);?>"  class="<?php if ($post->ID == $reviews_page) : ?> active <?php endif; ?> "><a   href="<?php echo esc_url(get_permalink($reviews_page)); ?>"><i class="ti ti-star me-2"></i><span><?php esc_html_e('Reviews', 'truelysell'); ?></span></a></li>
					<?php endif; ?>



						<!-- Messages -->
						<?php $messages_page = truelysell_fl_framework_getoptions('messages_page');
					if ($messages_page) : ?>
						<li id="<?php echo esc_html($role);?>"  class="  <?php if ($post->ID == $messages_page) : ?>  active <?php endif; ?>"><a href="<?php echo esc_url(get_permalink($messages_page)); ?>" ><i class="ti ti-message-circle me-2"></i><span><?php esc_html_e('Chat ', 'truelysell'); ?></span>
								<?php
								$counter = truelysell_get_unread_counter();
								if ($counter) { ?>
									<span class="nav-tag messages"> (<?php echo esc_html($counter); ?>)</span>
								<?php } ?>
							</a>
						</li>
				<?php endif; ?>

				<?php $enquiry_list_page = truelysell_fl_framework_getoptions('enquiry_list_page');
					if ($enquiry_list_page) : ?>
						<li class=" <?php if ($post->ID == $enquiry_list_page) : ?> active <?php endif; ?> "><a href="<?php echo esc_url(get_permalink($enquiry_list_page)); ?>" ><i class="ti ti-message me-2"></i><span><?php esc_html_e('Enquiry List', 'truelysell'); ?></span></a></li>
					<?php endif; ?>


				<!-- Contact Providers: hidden for technicians/providers (owner/seller role) -->
				<?php if ( ! in_array( $role, array( 'owner', 'seller' ), true ) ) : ?>
				<?php $contact_provider_page = truelysell_fl_framework_getoptions('contact_provider_page');
					if ($contact_provider_page) : ?>
						<li class=" <?php if ($post->ID == $contact_provider_page) : ?> active <?php endif; ?> "><a href="<?php echo esc_url(get_permalink($contact_provider_page)); ?>" ><i class="ti ti-message me-2"></i><span><?php esc_html_e('Contact List', 'truelysell'); ?></span></a></li>
					<?php endif; ?>
				<?php endif; ?>


				<!-- Profile settings -->
				<?php $profile_page = truelysell_fl_framework_getoptions('profile_page');
					if ($profile_page) : ?>
						<li class=" <?php if ($post->ID == $profile_page) : ?> active <?php endif; ?> "><a  href="<?php echo esc_url(get_permalink($profile_page)); ?>" ><i class="ti ti-settings me-2"></i><span><?php esc_html_e('Settings', 'truelysell'); ?></span></a></li>
					<?php endif; ?>
			 

					<!-- Logout -->
					<li class="mb-0"><a href="<?php echo wp_logout_url(home_url()); ?>"><i class="ti ti-logout me-2"></i><span><?php esc_html_e('Logout', 'truelysell'); ?></span></a></li>

                    </ul>
                </div>
            </div>
        </div>
        <!-- /Sidebar -->
