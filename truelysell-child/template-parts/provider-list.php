<?php
/**
 * Template Name: Provider List Page
 *
 * Child theme override of the parent's template-parts/provider-list.php.
 * Fixes, on top of the original:
 * 1. Multiple selected categories were joined into a single
 *    comma-separated string and sent as one query param — WP_Query's
 *    tax_query treats that as one literal (non-matching) slug rather
 *    than multiple terms, so filtering broke as soon as more than one
 *    category was checked.
 * 2. Providers were found via matching listings' post_author — but this
 *    site's whole architecture has the public catalog authored by Admin
 *    only; real technicians/providers are LINKED to listings (see
 *    custom_truelysell_get_listing_linked_provider_ids() in functions.php),
 *    never the post_author. That made this page miss almost every real
 *    provider regardless of filters.
 * 3. The keyword search only ever matched a technician's own display
 *    name, never the service/listing title — searching "tv" found
 *    nothing even though matching services existed. It now also matches
 *    listing titles/content (and still matches technician names too).
 * 4. Removed the Location filter entirely — providers don't fill in a
 *    postal code, so it never matched anything.
 *
 * @package Truelysell
 */

get_header();
if (isset($_GET['author_id'])) {
    $author_id = $_GET['author_id'];
} else {
    $author_id = null;
}
?>

<div class="page-wrapper">
    <div class="content">
        <div class="container">
            <div class="row align-items-start">
                <div class="col-xl-3 col-lg-4 theiaStickySidebar">
                    <div class="card">
                        <div class="card-body">

  <div class="d-flex align-items-center justify-content-between mb-3 pb-3 border-bottom">
    <h5><i class="ti ti-filter-check me-2"></i><?php echo esc_html__('Filters', 'truelysell'); ?></h5>
    <a href="javascript:void(0);" id="reset-filter" class="reset-link-new"><?php echo esc_html__('Reset Filter', 'truelysell'); ?></a>
  </div>
  <form id="filter-form" method="get">
  <div class="mb-3 pb-3 border-bottom">
    <label class="form-label"><?php echo esc_html__('Search By Keyword', 'truelysell'); ?></label>
    <input type="text" name="search_keyword" class="form-control" placeholder="<?php echo esc_attr('What are you looking for?', 'truelysell'); ?>" value="<?php echo esc_attr( sanitize_text_field( $_GET['search_keyword'] ?? '' ) ); ?>">
  </div>

  <div class="accordion">
        <div class="accordion-item mb-3">
            <div class="accordion-header" id="accordion-headingThree">
                <div class="accordion-button p-0 mb-3" data-bs-toggle="collapse" data-bs-target="#accordion-collapseThree" aria-expanded="true" aria-controls="accordion-collapseThree" role="button">
                <?php echo esc_html__('Categories', 'truelysell'); ?>
                </div>
            </div>
            <?php
            $args = array(
                'taxonomy'   => 'listing_category',
                'post_type'  => 'listing',
                'hide_empty' => false,
            );
            $categories = get_terms($args); ?>
            <div id="accordion-collapseThree" class="accordion-collapse collapse show" aria-labelledby="accordion-headingThree">
                <div class="mb-3">
                    <?php foreach ($categories as $category) { ?>
                        <div class="form-check mb-2">
                            <label class="form-check-label">
                                <input class="form-check-input" type="checkbox" name="provider_category[]" value="<?php echo esc_attr($category->slug); ?>">
                                <?php echo esc_html($category->name); ?>
                            </label>
                        </div>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>


<button type="submit" class="btn btn-dark w-100"><?php echo esc_html__('Search', 'truelysell'); ?></button>
</form>
                          </div>
                    </div>
                </div>

<div class="col-xl-9 col-lg-8">
  <div class="listings-container">
    <?php
  $search_keyword  = sanitize_text_field($_GET['search_keyword'] ?? '');
  $category_slugs  = isset( $_GET['provider_category'] ) ? (array) $_GET['provider_category'] : array();
  $category_slugs  = array_filter( array_map( 'sanitize_text_field', $category_slugs ) );

  $listing_query_args = array(
      'post_type'      => 'listing',
      'post_status'    => 'publish',
      'posts_per_page'  => -1,
      'fields'         => 'ids',
  );

  if ( ! empty( $category_slugs ) ) {
      $listing_query_args['tax_query'] = array(
          array(
              'taxonomy' => 'listing_category',
              'field'    => 'slug',
              'terms'    => $category_slugs,
          ),
      );
  }

  $category_listing_ids = get_posts( $listing_query_args );

  /*
   * The real technicians/providers offering these services are the ones
   * LINKED to each listing (custom_truelysell_get_listing_linked_provider_ids()),
   * not the listing's post_author — every listing in the public catalog
   * is authored by Admin. Collect the union of eligible linked providers
   * for a given set of listing IDs.
   */
  $get_eligible_linked_providers = function ( $listing_ids ) {
      $ids = array();
      foreach ( $listing_ids as $listing_id ) {
          if ( ! function_exists( 'custom_truelysell_get_listing_linked_provider_ids' ) ) {
              break;
          }
          foreach ( custom_truelysell_get_listing_linked_provider_ids( $listing_id ) as $linked_id ) {
              if ( function_exists( 'custom_truelysell_is_restricted_provider' ) && custom_truelysell_is_restricted_provider( $linked_id ) ) {
                  $ids[] = $linked_id;
              }
          }
      }
      return $ids;
  };

  if ( empty( $search_keyword ) ) {
      $provider_ids = $get_eligible_linked_providers( $category_listing_ids );
  } else {
      /*
       * The keyword box searches for a SERVICE ("What are you looking for?"),
       * so match it against listing titles/content too — not just a
       * technician's own display name. A technician linked to a matching
       * listing should show up even if their name has nothing to do with
       * the keyword. Still allow a direct name match for anyone searching
       * a technician by name.
       */
      $keyword_listing_ids  = get_posts( array_merge( $listing_query_args, array( 's' => $search_keyword ) ) );
      $keyword_provider_ids = $get_eligible_linked_providers( $keyword_listing_ids );

      $name_matched_user_ids = get_users( array(
          'search'         => '*' . $search_keyword . '*',
          'search_columns' => array( 'display_name' ),
          'fields'         => 'ID',
      ) );
      $category_provider_ids = $get_eligible_linked_providers( $category_listing_ids );
      $name_provider_ids     = array_intersect( $category_provider_ids, $name_matched_user_ids );

      $provider_ids = array_merge( $keyword_provider_ids, $name_provider_ids );
  }
  $provider_ids = array_values( array_unique( $provider_ids ) );

  $args = array(
    'include' => ! empty( $provider_ids ) ? $provider_ids : array( 0 ),
);

$users = new WP_User_Query($args);


    if ($users->get_results()) { ?>
      <div class="row">
        <?php foreach ($users->get_results() as $user) { ?>
          <div class="col-xl-4 col-md-6">
            <div class="card">
              <div class="card-body">
                <div class="card-img card-img-hover mb-3">
                  <?php //echo get_avatar($user->ID, 100);
                   echo get_avatar( $user->ID, '', '', '', [ 'class' => 'img-fluid avatar_full' ] );
                  ?>
                </div>
                <div>
                  <div class="d-flex align-items-center justify-content-between mb-0">
                    <div>
                      <h5 class="d-flex align-items-center mb-1">
                      <?php
    $provider_details_page = truelysell_fl_framework_getoptions('provider_details_page');
    $provider_details_page = get_permalink($provider_details_page);
    $author_id = $user->ID; // Get the author ID
    $provider_details_page_with_author_id = add_query_arg('author_id', $author_id, $provider_details_page);
?>
<a href="<?php echo esc_url($provider_details_page_with_author_id); ?>"><?php echo esc_html($user->display_name); ?></a>
<input type="hidden" name="author_id"  id="author_id" value="<?php echo esc_html($author_id); ?>"/>


 </h5>
 <?php $profile_designation = get_the_author_meta( 'profile_designation' , $author_id); ?>
<?php if($profile_designation) { ?>
<span><?php echo esc_html($profile_designation); ?></span>
<?php }  else { ?>
    <span><?php echo esc_html($user->user_email); ?></span>
<?php }?>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        <?php } ?>
      </div>
    <?php } else { ?>
        <section id="listings-not-found" class="mb-4 col-md-12 alert alert-info">
        <h2><?php esc_html_e('Nothing found','truelysell'); ?></h2>
	<p class="mb-0"><?php _e( 'We&rsquo;re sorry but we do not have any providers matching your search, try to change you search settings', 'truelysell' ); ?></p>
</section>
    <?php } ?>
  </div>
</div>
            </div>
        </div>
    </div>
</div>
<?php
get_footer();
?>

<script>
    $(document).ready(function() {
        const urlParams = new URLSearchParams(window.location.search);
        const categoryValues = urlParams.getAll('provider_category[]');

        $('input[name="provider_category[]"]').each(function() {
            const checkboxValue = $(this).val();
            if (categoryValues.includes(checkboxValue)) {
                $(this).prop('checked', true);
            } else {
                $(this).prop('checked', false);
            }
        });

        $('#reset-filter').on('click', function() {
            window.location.href = '<?php echo esc_url(get_permalink()); ?>';
        });

        $('#filter-form').submit(function(event) {
            event.preventDefault();
            var params = new URLSearchParams();
            params.set('search_keyword', $('input[name="search_keyword"]').val());
            $('input[name="provider_category[]"]:checked').each(function() {
                params.append('provider_category[]', $(this).val());
            });
            window.location.href = '<?php echo esc_url(get_permalink()); ?>' + '?' + params.toString();
        });
    });
</script>
