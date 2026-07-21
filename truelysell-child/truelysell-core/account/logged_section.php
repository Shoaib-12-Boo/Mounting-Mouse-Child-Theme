<?php
$my_account_display = get_option('truelysell_my_account_display', true);
$submit_display = get_option('truelysell_submit_display', true);
if (true == $my_account_display) : ?>

  <?php if (is_user_logged_in()) {
    $user_id = get_current_user_id();
    $current_user = wp_get_current_user();
    $roles = $current_user->roles;
    $role = array_shift($roles);
    if (!empty($current_user->user_firstname)) {
      $name = $current_user->user_firstname;
    } else {
      $name =  $current_user->display_name;
    }

    if (true == $submit_display) : ?>
      <?php if (is_user_logged_in()) {
        $user_id = get_current_user_id();
        $current_user = wp_get_current_user();
        $roles = $current_user->roles;
        $role = array_shift($roles);
      ?>


        <?php $submit_page = truelysell_fl_framework_getoptions('submit_page');
        if ($submit_page) :
          if ($role == "owner") {
        ?>
            <li class="nav-item desc-list">
              <a href="<?php echo esc_url(get_permalink($submit_page)); ?>" class="button bsp_service with-icon header-login"> <i class="feather-plus-circle me-1"></i> <?php esc_html_e('Post a Service', 'truelysell_core'); ?></a>
            </li>
        <?php }
        endif; ?>
      <?php } ?>

    <?php endif; ?>



    <li class="nav-item dropdown has-arrow account-item">
      <a href="#" class="dropdown-toggle nav-link" data-bs-toggle="dropdown" aria-expanded="false">
        <div class="user-infos">
          <span class="user-img">

            <?php echo get_avatar($current_user->user_email, 40, '', '', array('class' => 'img-fluid rounded-circle')); ?>

          </span>
          <div class="user-info">
            <h6><?php echo esc_html($name, 'truelysell_core'); ?></h6>
            <p><?php
                if ($role == "owner") { ?>
                <?php echo esc_html_e('Provider', 'truelysell_core'); ?>

              <?php } else if ($role == "guest" || $role == "customer") { ?>
                <?php echo esc_html_e('Customer', 'truelysell_core'); ?>

              <?php } else { ?>
                <?php echo esc_html_e('Admin', 'truelysell_core'); ?>
              <?php  }    ?></p>
          </div>
        </div>
      </a>
      <div class="dropdown-menu dropdown-menu-end emp">

        <?php if (in_array($role, array('administrator', 'admin', 'owner', 'seller'))) : ?>

          <a class="dropdown-item ssp_service" href="<?php echo esc_url(get_permalink($submit_page)); ?>"><i class="feather-plus-circle me-2"></i><?php esc_html_e('Post a Service', 'truelysell_core'); ?></a>

          <?php $dashboard_page = truelysell_fl_framework_getoptions('dashboard_page');
          if ($dashboard_page) : ?>
            <a class="dropdown-item" href="<?php echo esc_url(get_permalink($dashboard_page)); ?>"><i class="feather-grid me-2"></i> <?php esc_html_e('Dashboard', 'truelysell_core'); ?></a>
          <?php endif; ?>
        <?php endif; ?>
        <?php
        if (in_array($role, array('administrator', 'admin', 'owner', 'seller'))) :
          if (class_exists('WeDevs_Dokan')) :  ?>
            <?php $store_page = get_option('dokan_pages');
            if (isset($store_page['dashboard'])) : ?>
              <a class="dropdown-item" href="<?php echo esc_url(get_permalink($store_page['dashboard'])); ?>"><i class="feather-user me-2"></i> <?php esc_html_e('Store Dashboard', 'truelysell_core'); ?></a>
        <?php
            endif;
          endif;
        endif; ?>
        <?php if (!in_array($role, array('owner', 'seller'))) : ?>
          <?php $user_bookings_page = truelysell_fl_framework_getoptions('user_bookings_page');
          if ($user_bookings_page) : ?>
            <a class="dropdown-item" href="<?php echo esc_url(get_permalink($user_bookings_page)); ?>"><i class="feather-box me-2"></i><?php esc_html_e('My Bookings', 'truelysell_core'); ?></a>
          <?php endif; ?>
        <?php endif; ?>
        <?php if (in_array($role, array('administrator', 'admin', 'owner', 'seller'))) : ?>
          <?php $listings_page = truelysell_fl_framework_getoptions('listings_page');
          if ($listings_page) : ?>
            <a class="dropdown-item" href="<?php echo esc_url(get_permalink($listings_page)); ?>"><i class="feather-cpu me-2"></i><?php esc_html_e('My Services', 'truelysell_core'); ?></a>
          <?php endif; ?>
        <?php endif; ?>
        <?php if (!in_array($role, array('owner', 'seller'))) : ?>
          <?php $reviews_page = truelysell_fl_framework_getoptions('reviews_page');
          if ($reviews_page) : ?>
            <a class="dropdown-item" href="<?php echo esc_url(get_permalink($reviews_page)); ?>"><i class="feather-award me-2"></i><?php esc_html_e('Reviews', 'truelysell_core'); ?></a>
          <?php endif; ?>
        <?php endif; ?>

        <?php if (!in_array($role, array('owner', 'seller'))) : ?>
          <?php $bookmarks_page = truelysell_fl_framework_getoptions('bookmarks_page');
          if ($bookmarks_page) : ?>
            <a class="dropdown-item" href="<?php echo esc_url(get_permalink($bookmarks_page)); ?>"><i class="feather-heart me-2"></i> <?php esc_html_e('Favorites', 'truelysell_core'); ?></a>
          <?php endif; ?>
        <?php endif; ?>

        <?php $messages_page = truelysell_fl_framework_getoptions('messages_page');
        if ($messages_page) : ?>
          <a class="dropdown-item" href="<?php echo esc_url(get_permalink($messages_page)); ?>"><i class="feather-message-square me-2"></i><?php esc_html_e('Messages', 'truelysell_core'); ?>

          </a>
        <?php endif; ?>

        <?php if (in_array($role, array('administrator', 'admin', 'owner', 'seller'))) : ?>
          <?php $bookings_page = truelysell_fl_framework_getoptions('bookings_page');
          if ($bookings_page) : ?>
            <a class="dropdown-item" href="<?php echo esc_url(get_permalink($bookings_page)); ?>/?status=waiting"><i class="feather-calendar me-2"></i><?php esc_html_e('Bookings', 'truelysell_core'); ?>

            <?php endif; ?>
          <?php endif; ?>


          <?php $profile_page = truelysell_fl_framework_getoptions('profile_page');
          if ($profile_page) : ?>
            <a class="dropdown-item" href="<?php echo esc_url(get_permalink($profile_page)); ?>"><i class="feather-user me-2"></i><?php esc_html_e('My Profile', 'truelysell_core'); ?></a>
          <?php endif; ?>



          <a class="dropdown-item" href="<?php echo wp_logout_url(home_url()); ?>"><i class="feather-log-out me-2"></i> <?php esc_html_e('Logout', 'truelysell_core'); ?></a>

      </div>

      <?php } else {

      $popup_login = truelysell_fl_framework_getoptions('popup_login');
      $submit_page = truelysell_fl_framework_getoptions('submit_page');
      if (function_exists('Truelysell_Core')) :


        if ($popup_login == 'ajax' && !is_page_template('template-dashboard.php')) { ?>
          <ul class="nav header-navbar-rht">
            <li class="nav-item">
              <a href="#sign-in-dialog" class="sign-in popup-with-zoom-anim nav-link header-login"><?php esc_html_e('Login', 'truelysell_core'); ?></a>
            </li>

            <li class="nav-item">
              <a href="#register-dialog" class="sign-in popup-with-zoom-anim nav-link header-login"><?php esc_html_e('Register', 'truelysell_core'); ?></a>
            </li>


          </ul>
        <?php } else {
          $login_page = truelysell_fl_framework_getoptions('profile_page');
          $loginnew_page = truelysell_fl_framework_getoptions('login_page');
          $register_page = truelysell_fl_framework_getoptions('register_page'); ?>
    <li class="nav-item">
      <a href="<?php echo esc_url(get_permalink($register_page)); ?>" class="nav-link header-reg"><?php esc_html_e('Register', 'truelysell_core'); ?></a>
    </li>

    <li class="nav-item">
      <a class="nav-link header-login" href="<?php echo esc_url(get_permalink($loginnew_page)); ?>"><i class="fa-regular fa-circle-user me-2"></i><?php esc_html_e('Login', 'truelysell_core'); ?></a>
    </li>

<?php }
      endif; ?>
<?php } ?>
</li>




<?php endif; ?>