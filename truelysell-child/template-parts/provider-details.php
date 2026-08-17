<?php
/**
 * Template Name: Provider Details Page
 *
 * This is the template that displays all pages by default.
 * Please note that this is the WordPress construct of pages
 * and that other 'pages' on your WordPress site will use a
 * different template.
 *
 * @package Truelysell
 */

get_header();

// Ensure provider id is sanitized and provider variable always initialized
$provider_id = filter_input(INPUT_GET, 'author_id', FILTER_SANITIZE_NUMBER_INT);
$provider_id = $provider_id ? intval($provider_id) : 0;
$provider = null;
if ($provider_id) {
  $provider = get_userdata($provider_id);
}
?>


<div class="page-wrapper">
		<div class="content">
			<div class="container">
				<div class="row">
					<div class="col-md-12">
						<div class="card">
							<div class="card-body">
								<div class="row gy-3">
									<div class="col-xl-5">
										<div class="provider-detail d-flex align-items-center flex-wrap row-gap-2">
                                            <span class="avatar provider-pic flex-shrink-0 me-3">
                                            <?php
                                            // Avatar can be shown even if provider is null (uses ID)
                                            echo get_avatar( $provider_id, '', '', '', [ 'class' => 'img-fluid avatar_full' ] );
                                            ?>
                                            </span>
    <div>
    <?php
    $display_name = ($provider && !empty($provider->display_name)) ? $provider->display_name : __('Unknown Provider', 'truelysell');
    ?>
    <h5 class="d-flex align-items-center mb-1"><?php echo esc_html($display_name); ?><span class="text-success ms-2"><i class="fa fa-check-circle fs-14"></i></span></h5>
    <?php $user_description = get_user_meta($provider_id, 'description', true);  ?>
	<p class="mb-2"><?php echo esc_html($user_description, 'truelysell'); ?></p>
		 <div class="d-flex align-items-center flex-wrap row-gap-2">
            <?php
                          $args = array(
                              'author' => $provider_id,
                              'post_type' => 'listing',
                              'posts_per_page' => -1,
                          );
                            $posts = get_posts($args);
                            $categories = array();
                            if ( ! empty( $posts ) && is_array( $posts ) ) {
                              foreach ($posts as $post) {
                              $terms = wp_get_post_terms($post->ID, 'listing_category');
                              if (!empty($terms) && ! is_wp_error( $terms )) {
                                foreach ($terms as $term) {
                                  if ( isset( $term->name ) ) {
                                    $categories[] = $term->name;
                                  }
                                }
                              }
                            }
                            }
                        $category_counts = array_count_values($categories);
                        arsort($category_counts);
                        $most_frequent_category = key($category_counts);
                        if($categories){ 
                        echo '<p class="mb-0 fs-14 me-2"><i class="feather feather-grid me-1"></i>' . $most_frequent_category . '</p>';
                        }
                        // Guard user_registered
                        if ( $provider && ! empty( $provider->user_registered ) ) {
                          $user_registered = $provider->user_registered;
                          $date_format = 'd M Y';
                          $formatted_date = date_i18n($date_format, strtotime($user_registered));
                        } else {
                          $formatted_date = __('N/A', 'truelysell');
                        }
                        ?>
                          <p class="mb-0 fs-14"><i class="ti ti-calendar me-1"></i><?php echo esc_html('Member Since', 'truelysell'); ?> <?php echo esc_html($formatted_date); ?></p>
												</div>
											</div>
										</div>
									</div>
									<div class="col-xl-7">
										<div class="row">
											<div class="col-md-4">
												<div class="provider-bio-info mb-3">
                          <?php
                          // Guard user_email
                          $user_email = ($provider && !empty($provider->user_email)) ? $provider->user_email : '';
                          ?>
													<h6><i></i><?php echo esc_html('Email', 'truelysell'); ?></h6>
													<p><?php echo esc_html($user_email); ?></p>
												</div>

                      
												<div class="provider-bio-info">
													<h6><i></i><?php echo esc_html('Phone Number', 'truelysell'); ?></h6>
                          <?php
                        $user_phone = get_user_meta($provider_id, 'phone', true);
                        if($user_phone) {
                        ?>
													<p class="mb-0"><?php echo esc_html($user_phone); ?></p>
                          <?php }  else {
    echo '<p  class="mb-0">No phone available.</p>';
    } ?>
												</div>
                      

											</div>
 <div class="col-md-4">
 <div class="provider-bio-info mb-3">
  <h6><i></i><?php echo esc_html('Language Known', 'truelysell'); ?></h6>
  <?php
  $user_specialist = get_user_meta($provider_id, 'profile-specialist', true);
  if (!is_array($user_specialist)) {
    $user_specialist = maybe_unserialize($user_specialist);
  }
  if (is_array($user_specialist) && count($user_specialist) > 0) {
    $first_two = array_slice($user_specialist, 0, 2);
    $remaining = array_slice($user_specialist, 2);
    echo '<p>' . esc_html(implode(', ', array_map('ucfirst', $first_two)));
    if (!empty($remaining)) {
      echo ' <a class="text-primary" id="toggle-specialists" href="javascript:void(0);" data-bs-toggle="modal" data-bs-target="#user_specialists">+' . count($remaining) . ' More</a>';
    }
    echo '</p>';
  } else {
    echo '<p>No Language available.</p>';
  }
  ?>
 </div>

 <div class="modal fade custom-modal" id="user_specialists">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header d-flex align-items-center justify-content-between border-bottom">
          <h5 class="modal-title"><?php echo esc_html('Language Known', 'truelysell'); ?></h5>
          <a href="javascript:void(0);" data-bs-dismiss="modal" aria-label="Close"><i class="ti ti-circle-x-filled fs-20"></i></a>
        </div>
    <div class="modal-body">
    <ul class="mb-0 pl-0">
     <?php
    if (is_array($user_specialist)) {
      foreach ($user_specialist as $specialist) {
        echo '<li>' . esc_html($specialist) . '</li>';
      }
    }
    ?>
    </ul>
    </div>
          <div class="modal-footer">
            <a href="javascript:void(0);" class="btn btn-light me-2" data-bs-dismiss="modal"><?php echo esc_html('Cancel', 'truelysell'); ?></a>
          </div>
      </div>
    </div>
  </div>

 <?php /* Technician home address is private — used only internally for service-area matching, never shown to customers or other technicians. See functions.php. */ ?>
 </div>


			

											<div class="col-md-4">
												<div>

 <?php 

$user_facebook = get_user_meta($provider_id, 'profile-facebook', true);
$user_instagram = get_user_meta($provider_id, 'profile-instagram', true);
$user_x = get_user_meta($provider_id, 'profile-x', true);
$user_whatsapp = get_user_meta($provider_id, 'profile-whatsapp', true);
$user_youtube = get_user_meta($provider_id, 'profile-youtube', true);
$user_linkedin = get_user_meta($provider_id, 'profile-linkedin', true);

if ( !empty($user_facebook) || !empty($user_instagram) || !empty($user_x) || 
     !empty($user_whatsapp) || !empty($user_youtube) || !empty($user_linkedin) ) {

?>
													<h6 class="fw-medium mb-2"><?php echo esc_html__('Social Profiles', 'truelysell'); ?></h6>
													<ul class="social-icon flex-wrap row-gap-2 mb-3">
                            <?php
                           

                            if($user_facebook) {
                               ?>
                               <li>
                              <a target="_blank" href="<?php echo esc_url($user_facebook); ?>"><img src="<?php echo esc_url(get_template_directory_uri()); ?>/assets/images/icon/fb.svg" class="img" alt="icon"></a>
                          </li>
                          <?php 
                            }
                            
                            if($user_instagram) {
                              ?>
                              <li>
                             <a target="_blank" href="<?php echo esc_url($user_instagram); ?>"><img src="<?php echo esc_url(get_template_directory_uri()); ?>/assets/images/icon/instagram.svg" class="img" alt="icon"></a>
                         </li>
                         <?php 
                           }

                           if($user_x) {
                            ?>
                            <li>
                           <a target="_blank" href="<?php echo esc_url($user_x); ?>"><img src="<?php echo esc_url(get_template_directory_uri()); ?>/assets/images/icon/twitter.svg" class="img" alt="icon"></a>
                       </li>
                       <?php 
                         }

                         if($user_whatsapp) {
                          ?>
                          <li>
                         <a target="_blank" href="<?php echo esc_url($user_whatsapp); ?>"><img src="<?php echo esc_url(get_template_directory_uri()); ?>/assets/images/icon/whatsapp.svg" class="img" alt="icon"></a>
                     </li>
                     <?php 
                       }

                       if($user_youtube) {
                        ?>
                        <li>
                       <a target="_blank" href="<?php echo esc_url($user_youtube); ?>"><img src="<?php echo esc_url(get_template_directory_uri()); ?>/assets/images/icon/youtube.svg" class="img" alt="icon"></a>
                   </li>
                   <?php 
                     }

                     if($user_linkedin) {
                      ?>
                      <li>
                     <a target="_blank" href="<?php echo esc_url($user_linkedin); ?>"><img src="<?php echo esc_url(get_template_directory_uri()); ?>/assets/images/icon/linkedin.svg" class="img" alt="icon"></a>
                 </li>
                 <?php 
                   }
                   ?>
													</ul>
 <?php } ?>
												</div>
											</div>

										</div>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
				<div class="row">
					<div class="col-lg-12">
						<div class="card">
							<div class="card-body">
								<div class="accordion" id="accordionPanelsStayOpenExample">

                <?php
                        $profile_overview = get_user_meta($provider_id, 'profile-overview', true);
                        if($profile_overview) {
                        ?>

									<div class="accordion-item mb-3">
										<div class="accordion-header" id="accordion-headingOne">
											<div class="accordion-button p-0" data-bs-toggle="collapse" data-bs-target="#accordion-collapseOne" aria-expanded="true" aria-controls="accordion-collapseOne" role="button">
												<?php echo esc_html__('Overview', 'truelysell'); ?>
											</div>
										</div>
										<div id="accordion-collapseOne" class="accordion-collapse collapse show" aria-labelledby="accordion-headingOne">
										<div class="accordion-body p-0 mt-3 pb-1">
  												<p><?php echo esc_html($profile_overview); ?> </p>
 										</div>
										</div>
									</div>
<?php } ?>
                  <?php
                        $profile_aoe = get_user_meta($provider_id, 'profile-aoe', true);
                        if($profile_aoe) { 
                        ?>
									<div class="accordion-item mb-3">
										<div class="accordion-header" id="accordion-headingTwo">
											<div class="accordion-button p-0" data-bs-toggle="collapse" data-bs-target="#accordion-collapseTwo" aria-expanded="true" aria-controls="accordion-collapseTwo" role="button">
                      <?php echo esc_html__('Area Of Expertise', 'truelysell'); ?>
                      
											</div>
										</div>
										<div id="accordion-collapseTwo" class="accordion-collapse collapse show" aria-labelledby="accordion-headingTwo">
										<div class="accordion-body p-0 mt-3 pb-1">
 												<p><?php echo esc_html($profile_aoe); ?></p>
                        <?php 
                        $args = array(
                          'post_type' => 'listing',
                          'author' => $provider_id  
                        );
                        $posts = get_posts($args);
                        
                        if ($posts) {
                          ?>
											<div>
												<div class="row g-3">
													<div class="col-xl-12">
														<div class="area-expert-slider owl-carousel mb-3">
                             <?php
 
   $displayed_terms = array();

  foreach ($posts as $post) {
 
     $terms = get_the_terms($post->ID, 'listing_category');

    if ($terms && !is_wp_error($terms)) {
      foreach ($terms as $term) {
         if (!in_array($term->term_id, $displayed_terms)) {
           $cover_idicon = get_term_meta($term->term_id, '_covericon', true);
 
           ?>
          <div class="text-center area-expert">
            <span class="d-block mb-2">
              <?php
               $cover_icon_img = wp_get_attachment_image_src($cover_idicon, 'truelysell-blog-post');
              if ($cover_icon_img) {
                echo '<img src="' . esc_url($cover_icon_img[0]) . '" class="w-auto m-auto" alt="Img">';
              }
              ?>
            </span>
            <p class="fw-medium fs-14 mb-0"><?php echo esc_html($term->name); ?></p>
          </div>
          <?php
 
           $displayed_terms[] = $term->term_id;
        }
      }
    }
  }

?>

 														</div>
													</div>
												</div>
											</div>	
                      <?php } ?>					
										</div>
										</div>
									</div>

<?php } ?>
<?php
  /*
   * A registration-selected service is linked to the real, Admin-owned
   * catalog listing (see custom_truelysell_get_provider_real_listing_ids()
   * in functions.php) rather than authored by this provider directly, so
   * querying by 'author' alone missed it. Use the same shared, live-only
   * lookup the "My Services" dashboard uses (own listings + Admin-linked
   * ones, minus stray junk) so both always show identical data.
   */
  $provider_listing_ids = function_exists( 'custom_truelysell_get_provider_real_listing_ids' ) ? custom_truelysell_get_provider_real_listing_ids( $provider_id ) : array();
  $posts = ! empty( $provider_listing_ids ) ? get_posts( array(
    'post_type'      => 'listing',
    'post__in'       => $provider_listing_ids,
    'orderby'        => 'post__in',
    'posts_per_page' => -1,
 ) ) : array();
if ($posts) { ?>

									<div class="accordion-item mb-3">
										<div class="accordion-header" id="accordion-headingFour">
											<div class="accordion-button p-0" data-bs-toggle="collapse" data-bs-target="#accordion-collapseFour" aria-expanded="true" aria-controls="accordion-collapseFour" role="button">
                      <?php echo esc_html__('Our Services', 'truelysell'); ?>
											</div>
										</div>
										<div id="accordion-collapseFour" class="accordion-collapse collapse show" aria-labelledby="accordion-headingFour">
										<div class="accordion-body p-0 mt-3 pb-1">
											<div class="row">
												<div class="col-md-12">
													<div class="our-services-slider custom-owl-dot owl-carousel">
                                 <?php
                            
                                  foreach ($posts as $post) { 
									?>

<div class="card">
															<div class="card-body">
																<div class="img-sec w-100 mb-3">
																	
<a href="<?php echo esc_url( add_query_arg( 'author_id', $provider_id, get_permalink() ) ); ?>">


 <?php
						
						if(has_post_thumbnail($post->ID)){ 
							echo get_the_post_thumbnail($post->ID, 'truelysell-listing-grid-small', array('class' => 'img-fluid rounded-top w-100'));
 						} else {
							$gallery = maybe_unserialize( get_post_meta($post->ID, '_gallery', true) );

                                                if (!empty($gallery) && is_array($gallery)) {

														// _gallery is array( attachment_id => url ) - resolve by ID, not the stored value
														$first_attachment_id = array_key_first($gallery);
														$attachment_src = $first_attachment_id ? wp_get_attachment_image_src($first_attachment_id, 'truelysell-listing-grid-small') : false;
														$final_image_url = $attachment_src ? $attachment_src[0] : get_truelysell_core_placeholder_image();

														echo '<img src="' . esc_url($final_image_url) . '" class="img-fluid rounded-top w-100">';

                                                } else {
                                                    $image_url = get_truelysell_core_placeholder_image();
                                                    ?>
                                                    <img src="<?php echo esc_url($image_url); ?>" class="img-fluid rounded-top w-100">
                                                    <?php
                                                }
						} ?>
 
                                            
</a>																</div>
																<div>
 <h5 class="mb-2 text-truncate">
 <a href="<?php echo esc_url( add_query_arg( 'author_id', $provider_id, get_permalink() ) ); ?>"><?php the_title(); ?></a></h5>
 <div class="d-flex justify-content-between align-items-center mb-2">
 
 
 <?php if(get_the_listing_address()) { ?>
 <p class="fs-14 mb-0 "><i class="ti ti-map-pin me-2"></i><?php the_listing_address(); ?></p>
 <?php } ?>


<?php  $rating_value = get_post_meta($post->ID, 'truelysell-avg-rating', true);
 if($rating_value){ ?>
        <span class="rating text-gray fs-14"><i class="fa fa-star filled me-1"></i> 
          <?php echo esc_html( number_format( round( $rating_value, 1 ), 1 ) ); ?></span>
<?php }  else { ?>
    
   
   <span class="rating  mb-0 text-gray fs-14"><i class="fa fa-star filled me-1"></i>0</span>

    <?php } ?>

 </div>
 <div>
 <span><?php echo esc_html('Starts From', 'truelysell'); ?></span>
 <h6 class="text-primary fs-16 mt-1 mb-0"><?php 
$currency_abbr = truelysell_fl_framework_getoptions('currency');
$currency_symbol = Truelysell_Core_Listing::get_currency_symbol($currency_abbr);
$currency_postion = truelysell_fl_framework_getoptions('currency_postion');
// Ensure prices have two decimal places
$normal_price_value = (float) get_post_meta($post->ID, '_normal_price', true);
$initial_price_value = (float) get_post_meta($post->ID, '_initial_price', true);
if($currency_postion == 'after') {
    $normal_price = number_format($normal_price_value, 2) . $currency_symbol;
    $initial_price = number_format($initial_price_value, 2) . $currency_symbol;
} else {
    $normal_price = $currency_symbol . number_format($normal_price_value, 2);
    $initial_price = $currency_symbol . number_format($initial_price_value, 2);
}
echo esc_html($normal_price);
?>
 <?php if($initial_price_value) { ?>
     <span class="fs-13 text-gray"><del><?php echo esc_html($initial_price); ?></del></span>
 <?php } ?></h6>
 </div>
 </div>
 </div>
</div>
<?php 
 } 
  ?>
<?php
 }
?>
<?php
 $benefit_get= get_user_meta($provider_id, 'profilebenefits_new', true);
 if ( ! empty( $benefit_get ) ) {
  $benefits_new = maybe_unserialize( $benefit_get );
  ?>
<div class="accordion-item mb-3">
										<div class="accordion-header" id="accordion-headingFive">
											<div class="accordion-button p-0" data-bs-toggle="collapse" data-bs-target="#accordion-collapseFive" aria-expanded="true" aria-controls="accordion-collapseFive" role="button">
											<?php echo esc_html('Benefits', 'truelysell'); ?>
											</div>
										</div>
										<div id="accordion-collapseFive" class="accordion-collapse collapse show" aria-labelledby="accordion-headingFive">
										<div class="accordion-body p-0 mt-3 pb-1">
                 <?php
 
// $benefits = unserialize($user_benefits);

$count = 0;
$row_open = false;
if ( is_array( $benefits_new ) && count( $benefits_new ) > 0 ) {
  foreach ($benefits_new as $benefit) {
    if ($count % 3 == 0 && $count != 0) {
        echo '</div>'; // close the previous row
        echo '<div class="row gx-3">';
    }
    if (!$row_open) {
        echo '<div class="row gx-3">'; // open a new row
        $row_open = true;
    }
    echo '<div class="col-xl-4 col-sm-6">';
    echo '<div class="d-flex align-items-center benifits-item">';
    echo '<span class="text-dark me-2"><i class="fa fa-check-circle"></i></span>';
    echo '<p class="mb-0">' . $benefit . '</p>';
    echo '</div>';
    echo '</div>';
    $count++;
  }
  if ($row_open) {
    echo '</div>'; // close the last row
}
}

?>
										</div>
									</div>
</div>

<?php } ?>

 			</div>
			</div>
		</div>
	</div>
</div>
</div>
</div>
</div>

<div class="row mt-4">
<div class="col-xl-4 theiaStickySidebar mx-auto">
<div class="card shadow-none"><div class="card-body">
<h4 class="mb-3"><?php echo esc_html('Contact Provider','truelysell'); ?></h4>
<form id="contact-provider-form" method="POST">
<?php wp_nonce_field('contact_provider_nonce','contact_provider_nonce_field'); ?>
<input type="hidden" value="<?php echo esc_attr($provider_id); ?>" name="provider_id">
<div class="input-group mb-3"><input type="text" class="form-control border-end-0" placeholder="Name" name="name"><span class="input-group-text bg-white"><i class="ti ti-user"></i></span></div>
<div class="input-group mb-3"><input type="text" class="form-control border-end-0" placeholder="Phone Number" name="phone"><span class="input-group-text bg-white"><i class="ti ti-phone"></i></span></div>
<div class="input-group mb-3"><input type="text" class="form-control border-end-0" placeholder="Email Address" name="email"><span class="input-group-text bg-white"><i class="ti ti-mail"></i></span></div>
<div class="mb-3"><textarea class="form-control" rows="3" placeholder="Write a Message" name="comment"></textarea></div>
<button type="submit" class="btn btn-dark btn-lg w-100" name="submit_contact_form">Submit</button>
</form>
</div></div></div>
</div>

<div class="modal fade custom-modal" id="successModal">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
<div class="modal-header d-flex align-items-center justify-content-between border-bottom">
<h5 class="modal-title"><?php echo esc_html('Success','truelysell'); ?></h5>
<a href="javascript:void(0);" data-bs-dismiss="modal" aria-label="Close"><i class="ti ti-circle-x-filled fs-20"></i></a>
</div>
<div class="modal-body"><div class="alert alert-success mt-3 mb-3"><p class="mb-0"><?php echo esc_html('Your message has been successfully submitted!','truelysell'); ?></p></div></div>
<div class="modal-footer"><a href="javascript:void(0);" class="btn btn-light me-2" data-bs-dismiss="modal"><?php echo esc_html('Cancel','truelysell'); ?></a></div>
</div></div></div>

</div>
</div>
</div>
<?php get_footer(); ?>