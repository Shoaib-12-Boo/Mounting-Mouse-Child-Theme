<?php
/**
 * Template Name: Register Page
 *
 * @package Truelysell
 */

if ( ! is_user_logged_in() ) {
    $truelysell_registration_enabled = truelysell_fl_framework_getoptions( 'registration_enable' );
    $errors = array();

    if ( isset( $_REQUEST['login'] ) ) {
        $error_codes = explode( ',', $_REQUEST['login'] );
        foreach ( $error_codes as $code ) {
            switch ( $code ) {
                case 'empty_username':
                    $errors[] = esc_html__( 'You do have an email address, right?', 'truelysell' );
                    break;
                case 'empty_password':
                    $errors[] = esc_html__( 'You need to enter a password to login.', 'truelysell' );
                    break;
                case 'username_exists':
                    $errors[] = esc_html__( 'This username already exists.', 'truelysell' );
                    break;
                case 'authentication_failed':
                case 'invalid_username':
                    $errors[] = esc_html__( "We don't have any users with that email address. Maybe you used a different one when signing up?", 'truelysell' );
                    break;
                case 'invalid_email':
                    $errors[] = esc_html__( "Please check email or username correct?", 'truelysell' );
                    break;
                case 'incorrect_password':
                    $err = __( 'The password you entered wasn\'t quite right.', 'truelysell' );
                    $errors[] = sprintf( $err, wp_lostpassword_url() );
                    break;
                default:
                    break;
            }
        }
    }

    if ( isset( $_REQUEST['register-errors'] ) ) {
        $error_codes = explode( ',', $_REQUEST['register-errors'] );
        foreach ( $error_codes as $error_code ) {
            switch ( $error_code ) {
                case 'email':
                    $errors[] = esc_html__( 'The email address you entered is not valid.', 'truelysell' );
                    break;
                case 'email_exists':
                    $errors[] = esc_html__( 'An account exists with this email address.', 'truelysell' );
                    break;
                case 'closed':
                    $errors[] = esc_html__( 'Registering new users is currently not allowed.', 'truelysell' );
                    break;
                case 'captcha-no':
                    $errors[] = esc_html__( 'Please check reCAPTCHA checbox to register.', 'truelysell' );
                    break;
                case 'username_exists':
                    $errors[] = esc_html__( 'This username already exists.', 'truelysell' );
                    break;
                case 'captcha-fail':
                    $errors[] = esc_html__( "You're a bot, aren't you?", 'truelysell' );
                    break;
                case 'policy-fail':
                    $errors[] = esc_html__( 'Please accept the Privacy Policy to register account.', 'truelysell' );
                    break;
                case 'terms-fail':
                    $errors[] = esc_html__( 'Please accept the Terms and Conditions to register account.', 'truelysell' );
                    break;
                case 'first_name':
                    $errors[] = esc_html__( 'Please provide your first name', 'truelysell' );
                    break;
                case 'last_name':
                    $errors[] = esc_html__( 'Please provide your last name', 'truelysell' );
                    break;
                case 'empty_user_login':
                    $errors[] = esc_html__( 'Please provide your user login', 'truelysell' );
                    break;
                case 'password-no':
                    $errors[] = esc_html__( 'You have forgot about password.', 'truelysell' );
                    break;
                case 'incorrect_password':
                    $err = __( 'The password you entered wasn\'t quite right.', 'truelysell' );
                    $errors[] = sprintf( $err, wp_lostpassword_url() );
                    break;
                default:
                    break;
            }
        }
    }

    get_header();

    $page_top = get_post_meta( $post->ID, 'truelysell_page_top', true );
    switch ( $page_top ) {
        case 'titlebar':
            get_template_part( 'template-parts/header', 'titlebar' );
            break;
        case 'parallax':
            get_template_part( 'template-parts/header', 'parallax' );
            break;
        case 'off':
            break;
        default:
            get_template_part( 'template-parts/header', 'titlebar' );
            break;
    }

    $layout = get_post_meta( $post->ID, 'truelysell_page_layout', true );
    if ( empty( $layout ) ) {
        $layout = 'right-sidebar';
    }
    ?>
    <div class="content">
        <div class="container <?php echo esc_attr( $layout ); ?>">
            <div class="row">
                <div class="col-md-12 col-lg-12">
                    <div class="login-wrap" style="max-width: 600px; margin: 0 auto;">
                        <?php if ( count( $errors ) > 0 ) : ?>
                            <?php foreach ( $errors as $error ) : ?>
                                <div class="notification error closeable">
                                    <div class="badge text-bg-danger closeable"><p><?php echo esc_html( $error ); ?></p></div>
                                    <a class="close"></a>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if ( isset( $_REQUEST['registered'] ) ) : ?>
                            <div class="notification success closeable">
                                <div class="badge text-bg-success closeable"><p>
                                    <?php printf( esc_html__( 'You have successfully registered to %s.', 'truelysell' ), '<strong>' . get_bloginfo( 'name' ) . '</strong>' ); ?>
                                </p></div>
                            </div>
                        <?php endif; ?>

                        <?php if ( ! get_option( 'users_can_register' ) || ! $truelysell_registration_enabled ) : ?>
                            <div class="notification error closeable" style="display: block">
                                <p><?php esc_html_e( 'Registration is disabled', 'truelysell' ); ?></p>
                            </div>
                        <?php else : ?>
                            <div class="wpforms-registration-wrapper">
                                <?php echo do_shortcode( '[wpforms id="8203"]' ); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="clearfix"></div>

    <?php
    get_footer();
}
