<?php
/**
 * Customer Bookings Display Template
 * Shows all bookings made by the current user from the database
 *
 * @package truelysell
 */

$user_id = get_current_user_id();
$status_filter = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : null;
$per_page = 6;
$current_page = max( 1, absint( get_query_var( 'paged' ) ? get_query_var( 'paged' ) : ( $_GET['booking-page'] ?? 1 ) ) );
$offset = ( $current_page - 1 ) * $per_page;

// Get bookings from database (FIXED: Now using database instead of SESSION)
if ( ! function_exists( 'truelysell_get_customer_bookings' ) ) {
    echo '<div class="alert alert-danger">Error: Booking function not found. Please check template-tags.php is loaded.</div>';
    $bookings = [];
} else {
    $total_bookings = function_exists( 'truelysell_count_customer_bookings' ) ? truelysell_count_customer_bookings( $user_id, $status_filter ) : 0;
    $total_pages = max( 1, (int) ceil( $total_bookings / $per_page ) );
    $bookings = truelysell_get_customer_bookings( $user_id, $status_filter, $per_page, $offset );
}

$status_labels = [
    'just-booked' => 'Pending',
    'waiting' => 'Pending',
    'confirmed' => 'Approved',
    'paid' => 'Approved',
    'approved' => 'Approved',
    'cancelled' => 'Cancelled',
    'expired' => 'Expired',
    'completed' => 'Completed'
];

$status_colors = [
    'just-booked' => 'warning',
    'waiting' => 'warning',
    'confirmed' => 'success',
    'paid' => 'success',
    'approved' => 'success',
    'cancelled' => 'danger',
    'expired' => 'secondary',
    'completed' => 'info'
];
?>

<div class="card">
    <div class="card-body">
        <?php if ( empty( $bookings ) ) : ?>
            <div class="alert alert-info" role="alert">
                <p><?php esc_html_e( 'No bookings found.', 'truelysell' ); ?></p>
            </div>
        <?php else : ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Service', 'truelysell' ); ?></th>
                            <th><?php esc_html_e( 'Technician', 'truelysell' ); ?></th>
                            <th><?php esc_html_e( 'Booking Date', 'truelysell' ); ?></th>
                            <th><?php esc_html_e( 'Time', 'truelysell' ); ?></th>
                            <th><?php esc_html_e( 'Price', 'truelysell' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'truelysell' ); ?></th>
                            <th><?php esc_html_e( 'Action', 'truelysell' ); ?></th>
                            <th><?php esc_html_e( 'Remaining Balance', 'truelysell' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $bookings as $booking ) :
                            $listing = get_post( $booking->listing_id );
                            $technician = ! empty( $booking->owner_id ) ? get_userdata( $booking->owner_id ) : false;
                            $booking_date = date( 'M d, Y', strtotime( $booking->date_start ) );
                            $start_time = date( 'H:i', strtotime( $booking->date_start ) );
                            $end_time = date( 'H:i', strtotime( $booking->date_end ) );
                            $status_label = $status_labels[ $booking->status ] ?? ucfirst( str_replace( '-', ' ', $booking->status ) );
                            $status_color = $status_colors[ $booking->status ] ?? 'secondary';
                            $currency_symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$';
                            $completion_state = function_exists( 'truelysell_get_booking_job_completion_state' ) ? truelysell_get_booking_job_completion_state( $booking ) : null;
                        ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html( $listing ? $listing->post_title : 'N/A' ); ?></strong>
                                </td>
                                <td><?php echo esc_html( $technician ? $technician->display_name : 'N/A' ); ?></td>
                                <td><?php echo esc_html( $booking_date ); ?></td>
                                <td><?php echo esc_html( $start_time . ' - ' . $end_time ); ?></td>
                                <td>
                                    <?php echo esc_html( $currency_symbol . number_format( $booking->price, 2 ) ); ?>
                                    <?php if ( null !== $completion_state ) : ?>
                                        <br><span class="fs-12 text-muted"><?php echo esc_html( sprintf( __( 'Deposit paid: %s of %s', 'truelysell' ), $currency_symbol . number_format( $booking->price - $completion_state['remaining_amount'], 2 ), $currency_symbol . number_format( $booking->price, 2 ) ) ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo esc_attr( $status_color ); ?>">
                                        <?php echo esc_html( $status_label ); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ( $listing ) : ?>
                                        <a href="<?php echo esc_url( get_permalink( $listing ) ); ?>" class="btn btn-sm btn-outline-primary"><?php esc_html_e( 'View', 'truelysell' ); ?></a>
                                    <?php else : ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ( null === $completion_state ) : ?>
                                        <span class="text-muted">&mdash;</span>
                                    <?php elseif ( $completion_state['remaining_settled'] ) : ?>
                                        <span class="badge bg-success"><?php esc_html_e( 'Fully Paid', 'truelysell' ); ?></span>
                                    <?php elseif ( $completion_state['both_confirmed'] ) : ?>
                                        <button type="button" class="btn btn-sm btn-primary" data-truelysell-job-action="truelysell_pay_remaining_balance" data-booking-id="<?php echo esc_attr( $booking->ID ); ?>">
                                            <?php echo esc_html( sprintf( __( 'Pay Remaining %s', 'truelysell' ), $currency_symbol . number_format( $completion_state['remaining_amount'], 2 ) ) ); ?>
                                        </button>
                                    <?php elseif ( $completion_state['technician_marked_complete'] ) : ?>
                                        <button type="button" class="btn btn-sm btn-outline-success" data-truelysell-job-action="truelysell_confirm_job_complete" data-booking-id="<?php echo esc_attr( $booking->ID ); ?>">
                                            <?php esc_html_e( 'Confirm Job Complete', 'truelysell' ); ?>
                                        </button>
                                    <?php else : ?>
                                        <span class="text-muted fs-12"><?php esc_html_e( 'In progress', 'truelysell' ); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ( ! empty( $total_pages ) && $total_pages > 1 ) : ?>
                <nav class="pagination-container mt-4" aria-label="<?php esc_attr_e( 'Bookings pagination', 'truelysell' ); ?>">
                    <div class="pagination justify-content-center">
                        <?php
                        echo paginate_links( array(
                            'base'      => esc_url_raw( add_query_arg( 'booking-page', '%#%' ) ),
                            'format'    => '',
                            'current'   => $current_page,
                            'total'     => $total_pages,
                            'prev_text' => esc_html__( 'Previous', 'truelysell' ),
                            'next_text' => esc_html__( 'Next', 'truelysell' ),
                            'type'      => 'list',
                        ) );
                        ?>
                    </div>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
