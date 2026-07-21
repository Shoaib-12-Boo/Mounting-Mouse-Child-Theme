<?php

/**
 * Template Name: Login Page
 *
 * This is the template that displays all pages by default.
 * Please note that this is the WordPress construct of pages
 * and that other 'pages' on your WordPress site will use a
 * different template.
 *
 * @package Truelysell
 */

if (!is_user_logged_in()) {
    $not_verified = isset($_GET['not_verified']) ? sanitize_text_field($_GET['not_verified']) : '';

    $errors = array();

    if (isset($_REQUEST['login'])) {
        $error_codes = explode(',', $_REQUEST['login']);

        foreach ($error_codes as $code) {
            switch ($code) {
                case 'empty_username':
                    $errors[] = esc_html__('You do have an email address, right?', 'truelysell');
                    break;
                case 'empty_password':
                    $errors[] = esc_html__('You need to enter a password to login.', 'truelysell');
                    break;
                case 'username_exists':
                    $errors[] = esc_html__('This username already exists.', 'truelysell');
                    break;
                case 'authentication_failed':
                case 'invalid_username':
                    $errors[] = esc_html__(
                        "We don't have any users with that email address. Maybe you used a different one when signing up?",
                        'truelysell'
                    );
                    break;
                case 'invalid_email':
                    $errors[] = esc_html__("Please check email or username correct?", 'truelysell');
                    break;
                case 'incorrect_password':
                    $err = __(
                        "The password you entered wasn't quite right.",
                        'truelysell'
                    );
                    $errors[] = sprintf($err, wp_lostpassword_url());
                    break;
                default:
                    break;
            }
        }
    }

    if (isset($_REQUEST['register-errors'])) {
        $error_codes = explode(',', $_REQUEST['register-errors']);

        foreach ($error_codes as $error_code) {
            switch ($error_code) {
                case 'email':
                    $errors[] = esc_html__('The email address you entered is not valid.', 'truelysell');
                    break;
                case 'email_exists':
                    $errors[] = esc_html__('An account exists with this email address.', 'truelysell');
                    break;
                case 'closed':
                    $errors[] = esc_html__('Registering new users is currently not allowed.', 'truelysell');
                    break;
                case 'captcha-no':
                    $errors[] = esc_html__('Please check reCAPTCHA checbox to register.', 'truelysell');
                    break;
                case 'username_exists':
                    $errors[] = esc_html__('This username already exists.', 'truelysell');
                    break;
                case 'captcha-fail':
                    $errors[] = esc_html__("You're a bot, aren't you?", 'truelysell');
                    break;
                case 'policy-fail':
                    $errors[] = esc_html__('Please accept the Privacy Policy to register account.', 'truelysell');
                    break;
                case 'terms-fail':
                    $errors[] = esc_html__('Please accept the Terms and Conditions to register account.', 'truelysell');
                    break;
                case 'first_name':
                    $errors[] = esc_html__('Please provide your first name', 'truelysell');
                    break;
                case 'last_name':
                    $errors[] = esc_html__('Please provide your last name', 'truelysell');
                    break;
                case 'empty_user_login':
                    $errors[] = esc_html__('Please provide your user login', 'truelysell');
                    break;
                case 'password-no':
                    $errors[] = esc_html__('You have forgot about password.', 'truelysell');
                    break;
                case 'incorrect_password':
                    $err = __(
                        "The password you entered wasn't quite right.",
                        'truelysell'
                    );
                    $errors[] = sprintf($err, wp_lostpassword_url());
                    break;
                default:
                    break;
            }
        }
    }

    get_header();

    $page_top = get_post_meta($post->ID, 'truelysell_page_top', TRUE);

    switch ($page_top) {
        case 'titlebar':
            get_template_part('template-parts/header', 'titlebar');
            break;

        case 'parallax':
            get_template_part('template-parts/header', 'parallax');
            break;

        case 'off':
            break;

        default:
            get_template_part('template-parts/header', 'titlebar');
            break;
    }

    $layout = get_post_meta($post->ID, 'truelysell_page_layout', true);

    if (empty($layout)) {
        $layout = 'right-sidebar';
    }
    ?>
    <div class="content">
        <div class="container <?php echo esc_attr($layout); ?>">
            <div class="login-wrap">
                <?php if ($not_verified) : ?>
                    <div class="alert alert-danger">
                        <?php esc_html_e('Your account was not yet activated. We will keep you posted', 'truelysell'); ?>
                    </div>
                <?php endif; ?>

                <?php if (count($errors) > 0) : ?>
                    <?php foreach ($errors as $error) : ?>
                        <div class="notification error closeable">
                            <div class="alert alert-danger"><?php echo esc_html($error); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if (isset($_REQUEST['registered_verify'])) : ?>
                    <div class="notification success closeable">
                        <div class="badge w-100 text-bg-success closeable">
                            <p class="mb-2 text-transform-none"><?php esc_html_e('You have successfully registered.', 'truelysell'); ?></p>
                            <p class="text-transform-none"><?php esc_html_e('You can login once admin approval.', 'truelysell'); ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (isset($_REQUEST['registered'])) : ?>
                    <div class="notification success closeable">
                        <div class="badge text-bg-success closeable"><p>
                            <?php printf(
                                esc_html__('You have successfully registered to %s.', 'truelysell'),
                                '<strong>' . get_bloginfo('name') . '</strong>'
                            ); ?>
                        </p></div>
                    </div>
                <?php endif; ?>

                <div class="wpforms-login-wrapper">
                    <?php echo do_shortcode('[wpforms id="8304" title="false"]'); ?>
                </div>

            </div>
        </div>
    </div>

    <?php
    get_footer();
}
