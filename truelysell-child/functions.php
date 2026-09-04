<?php
/**
 * =========================================================================
 * CUSTOM TRUELYSELL WPFORMS REGISTRATION & SERVICE RESTRICT INTEGRATION
 * (FIXED: ab 'user_register' hook use ho raha hai — ye hamesha fire hota
 *  hai, chahe WPForms addon ka custom hook exist kare ya na kare)
 * =========================================================================
 * FTP se child theme folder mein upload karein: truelysell-custom.php
 * functions.php mein aakhir mein add karein:
 * require_once get_stylesheet_directory() . '/truelysell-custom.php';
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registration form 8203's "Services Offered" checkboxes (field 77) are
 * WPForms Dynamic Choices sourced from the "listing" post type — every
 * matching post becomes a choice regardless of status, which is how junk
 * draft/incomplete listings ended up as selectable "services" during
 * signup. Restrict this one field's dynamic choices to published listings
 * only, via WPForms' own documented hook for this.
 */
add_filter( 'wpforms_dynamic_choice_post_type_args', 'custom_truelysell_registration_services_field_publish_only', 10, 3 );
function custom_truelysell_registration_services_field_publish_only( $args, $field, $form_id ) {
	if ( absint( $form_id ) === 8203 && isset( $field['id'] ) && absint( $field['id'] ) === 77 ) {
		$args['post_status'] = 'publish';
	}
	return $args;
}

/**
 * Companion fix for the SAME field, for once its "Dynamic Choices" source
 * is switched from "Post Type" to "Taxonomy" (Service Categories) in the
 * WPForms builder — Post Type source can only ever list one choice per
 * individual listing post (duplicate category names, wrong/inflated count);
 * Taxonomy source lists one per distinct category, which is what actually
 * matches the public catalog. Once switched, restrict it to only the
 * categories that have an Admin-authored published listing, same rule as
 * everywhere else.
 */
add_filter( 'wpforms_dynamic_choice_taxonomy_args', 'custom_truelysell_registration_services_field_admin_categories_only', 10, 3 );
function custom_truelysell_registration_services_field_admin_categories_only( $args, $field, $form_id ) {
	if ( absint( $form_id ) !== 8203 || ! isset( $field['id'] ) || absint( $field['id'] ) !== 77 ) {
		return $args;
	}

	$admin_ids = get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) );
	if ( empty( $admin_ids ) ) {
		return $args;
	}

	$allowed_term_ids = array();
	$categories       = get_terms( array( 'taxonomy' => 'listing_category', 'hide_empty' => false ) );
	if ( ! is_wp_error( $categories ) ) {
		foreach ( $categories as $category ) {
			$has_admin_listing = get_posts( array(
				'post_type'              => 'listing',
				'post_status'            => 'publish',
				'author__in'             => $admin_ids,
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'tax_query'              => array(
					array(
						'taxonomy' => 'listing_category',
						'field'    => 'term_id',
						'terms'    => array( $category->term_id ),
					),
				),
			) );
			if ( ! empty( $has_admin_listing ) ) {
				$allowed_term_ids[] = $category->term_id;
			}
		}
	}

	$args['include'] = $allowed_term_ids;

	return $args;
}

/**
 * Same rule, applied to wp-admin: the "Listings" post list (Posts →
 * Listing) should only show listings the Administrator created — a
 * technician/owner's own services stay off this screen too, same as the
 * public catalog. They're managed from the provider's own dashboard instead.
 */
add_action( 'pre_get_posts', 'custom_truelysell_restrict_admin_listings_list_to_admins' );
function custom_truelysell_restrict_admin_listings_list_to_admins( $query ) {
	global $pagenow;

	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}
	if ( 'edit.php' !== $pagenow || 'listing' !== $query->get( 'post_type' ) ) {
		return;
	}

	$admin_ids = get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) );
	if ( ! empty( $admin_ids ) ) {
		$query->set( 'author__in', $admin_ids );
	}
}

/**
 * The "All (X)" / "Published (X)" tab counts above the Listings list are
 * generated natively by WordPress (wp_count_posts) and always count every
 * author's listings, unaffected by the author__in restriction above — so a
 * technician adding/deleting their own listings still moved that number,
 * even though the actual rows shown were already correctly Admin-only. Make
 * the tab labels agree with what the list actually shows.
 */
add_filter( 'views_edit-listing', 'custom_truelysell_fix_admin_listing_view_counts' );
function custom_truelysell_fix_admin_listing_view_counts( $views ) {
	$admin_ids = get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) );
	if ( empty( $admin_ids ) ) {
		return $views;
	}

	unset( $views['mine'] ); // redundant once "All" already means "Admin's own"

	$status_counts = array();
	foreach ( array( 'publish', 'pending', 'draft', 'future', 'private' ) as $status ) {
		$query = new WP_Query( array(
			'post_type'              => 'listing',
			'post_status'            => $status,
			'author__in'             => $admin_ids,
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );
		$status_counts[ $status ] = (int) $query->found_posts;
	}

	if ( isset( $views['all'] ) ) {
		$views['all'] = preg_replace( '/\(\d+\)/', '(' . array_sum( $status_counts ) . ')', $views['all'] );
	}
	if ( isset( $views['publish'] ) ) {
		$views['publish'] = preg_replace( '/\(\d+\)/', '(' . $status_counts['publish'] . ')', $views['publish'] );
	}
	if ( isset( $views['draft'] ) ) {
		$views['draft'] = preg_replace( '/\(\d+\)/', '(' . $status_counts['draft'] . ')', $views['draft'] );
	}
	if ( isset( $views['pending'] ) ) {
		$views['pending'] = preg_replace( '/\(\d+\)/', '(' . $status_counts['pending'] . ')', $views['pending'] );
	}

	return $views;
}

/**
 * The public "/services/" browse page is the official, curated catalog —
 * its "Found X Services" count and results should only reflect listings the
 * Administrator created. A technician/owner adding a service to their own
 * account should grow THEIR OWN "My Services" count (already handled
 * elsewhere) without ever inflating this public total or appearing in this
 * public browse/search results — their service still lives on their own
 * profile page and dashboard, just not in the general public catalog.
 *
 * This page's grid is NOT the main query — it's built by
 * Truelysell_Core_Listing::get_real_listings() (truelysell-core-listing.php),
 * a shortcode-driven WP_Query of its own, which is why a pre_get_posts hook
 * (only fires for the main query, or requires guessing at every secondary
 * query) had no effect. That method applies the 'realto_get_listings' filter
 * to its $query_args right before running the query — hook there instead,
 * which only ever touches this specific catalog query.
 */
add_filter( 'realto_get_listings', 'custom_truelysell_restrict_public_archive_to_admins', 10, 2 );
function custom_truelysell_restrict_public_archive_to_admins( $query_args, $args ) {
	$query_args['posts_per_page'] = 9;

	$admin_ids = get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) );
	if ( ! empty( $admin_ids ) ) {
		$query_args['author__in'] = $admin_ids;
	}

	return $query_args;
}

add_action( 'wp_footer', 'custom_truelysell_make_pagination_real_links', 100 );
function custom_truelysell_make_pagination_real_links() {
	$current_page = max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
	?>
	<script type="text/javascript">
	window.addEventListener('load', function() {
		var currentPage = <?php echo (int) $current_page; ?>;

		document.querySelectorAll('.pagination-container.ajax-search .pagination li[data-paged]').forEach(function(li) {
			var paged = li.getAttribute('data-paged');

			// Highlight whichever li actually matches the page we're really on,
			// instead of whatever "current" the plugin's static markup shipped with.
			if ( paged !== 'next' && paged !== 'prev' ) {
				li.classList.toggle( 'current', parseInt( paged, 10 ) === currentPage );
			}

			var link = li.querySelector('a');
			if (!link) {
				return;
			}
			link.addEventListener('click', function(e) {
				e.preventDefault();
				e.stopImmediatePropagation();

				var target = paged;
				if (target === 'next') {
					target = currentPage + 1;
				} else if (target === 'prev') {
					target = currentPage - 1;
				}

				var url = new URL(window.location.href);
				url.searchParams.set('paged', target);
				window.location.href = url.toString();
			}, true);
		});
	});
	</script>
	<?php
}

/**
 * Pagination on listing search results throws "truelysell_core is not
 * defined" (ajax.search.min.js references truelysell_core.ajax_url) because
 * the plugin registers the 'ajaxsearch' script with only 'jquery' as a
 * dependency — it never declares a dependency on 'truelysell_core-frontend',
 * the script wp_localize_script() attaches the truelysell_core object to.
 * WordPress has no reason to print that script first, so the moment
 * anything (caching/JS-optimization, etc.) reorders script output, pagination
 * breaks. Add the missing dependency so load order is actually guaranteed.
 */
add_action( 'wp_enqueue_scripts', 'custom_truelysell_fix_ajaxsearch_script_dependency', 20 );
function custom_truelysell_fix_ajaxsearch_script_dependency() {
	global $wp_scripts;

	if ( ! $wp_scripts instanceof WP_Scripts ) {
		return;
	}

	if ( ! isset( $wp_scripts->registered['ajaxsearch'] ) ) {
		return;
	}

	if ( ! in_array( 'truelysell_core-frontend', $wp_scripts->registered['ajaxsearch']->deps, true ) ) {
		$wp_scripts->registered['ajaxsearch']->deps[] = 'truelysell_core-frontend';
	}
}

/**
 * Pagination AND sorting both go through the same path: they trigger a
 * custom 'update_results' event on #truelysell-listings-container, which
 * ajax.search.min.js listens for (bound once, on $(document).ready()) to
 * fire the actual AJAX call. On this site that binding silently never
 * attaches — clicking pagination logs the page number fine (that handler
 * IS bound) but nothing happens after, no request, no error — which is
 * exactly what you'd see if the container didn't exist yet at the moment
 * the plugin's script ran (e.g. a JS-delay/optimization feature on this
 * host executing scripts out of their normal document-ready order).
 * Re-bind the same listener ourselves at window "load" (strictly later than
 * any $(document).ready()), replacing whatever the plugin's own script did
 * or didn't manage to attach, so pagination/sorting work regardless of that
 * timing race.
 */
add_action( 'wp_footer', 'custom_truelysell_rebind_listings_update_results', 100 );
function custom_truelysell_rebind_listings_update_results() {
	?>
	<script type="text/javascript">
	window.addEventListener('load', function() {
		if (!window.jQuery) {
			return;
		}
		var jq = jQuery;
		var container = jq('#truelysell-listings-container');
		if (!container.length) {
			return;
		}

		container.off('update_results').on('update_results', function(event, page, append, loading_previous) {
			var results = jq(this);
			var filter  = jq('#truelysell_core-search-form');
			var data    = filter.serializeArray();

			data.push({name: 'action', value: 'truelysell_get_listings'});
			data.push({name: 'page', value: page});
			data.push({name: 'style', value: results.data('style')});
			data.push({name: 'grid_columns', value: results.data('grid_columns')});
			data.push({name: 'per_page', value: results.data('per_page')});
			data.push({name: 'custom_class', value: results.data('custom_class')});
			data.push({name: 'order', value: results.data('orderby')});

			jq.ajax({
				type: 'post',
				dataType: 'json',
				url: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
				data: data,
				beforeSend: function() {
					results.addClass('loading');
				},
				success: function(response) {
					results.removeClass('loading');
					results.html(response.html);
					jq('div.pagination-container').html(response.pagination);
					if (jq.fn.numericalRating) {
						jq('.numerical-rating').numericalRating();
					}
					if (jq.fn.starRating) {
						jq('.star-rating').starRating();
					}
					container.triggerHandler('update_results_success');
				},
				error: function() {
					results.removeClass('loading');
				}
			});
		});
	});
	</script>
	<?php
}

/**
 * The "Search Listings" widget's search form is hardcoded (in its saved
 * widget settings) to submit to a page — .../grid-with-sidebar — that
 * doesn't exist on this site (the real listings archive lives elsewhere,
 * e.g. /grid-full-width/), so every filter search 404s. Rather than depend
 * on the widget's saved target page, rewrite the form's submit target at
 * render time to the listing post type's real archive URL, wherever the
 * widget happens to be placed (sidebar, footer, etc.).
 */
add_action( 'wp_footer', 'custom_truelysell_fix_search_listings_form_action', 100 );
function custom_truelysell_fix_search_listings_form_action() {
	$archive_link = get_post_type_archive_link( 'listing' );
	if ( ! $archive_link ) {
		return;
	}
	?>
	<script type="text/javascript">
	document.addEventListener('DOMContentLoaded', function() {
		var correctUrl = <?php echo wp_json_encode( $archive_link ); ?>;
		document.querySelectorAll('form').forEach(function(form) {
			var actionField = form.querySelector('input[name="action"][value="truelysell_get_listings"]');
			if (actionField && form.getAttribute('action') !== correctUrl) {
				form.setAttribute('action', correctUrl);
			}
		});
	});
	</script>
	<?php
}

/**
 * New/edited listings should publish immediately, not sit in "pending"
 * waiting for manual admin approval — a pending listing is invisible on
 * public pages (provider profile, search, etc.) to every visitor, including
 * guests. This overrides the "New listing requires approval" theme option
 * at the point WordPress reads it, regardless of what's saved in the
 * Truelysell Options screen.
 */
add_filter( 'option_truelysell_theme_options', 'custom_truelysell_force_no_listing_approval' );
function custom_truelysell_force_no_listing_approval( $value ) {
	if ( is_array( $value ) ) {
		$value['new_listing_requires_approval'] = false;
	}
	return $value;
}

/**
 * The customer booking form's Phone field already has a built-in
 * "required" toggle (truelysell-core/templates/booking.php reads the
 * 'booking_phone_required' theme option) — force it on regardless of
 * what's saved in the Truelysell Options screen, same pattern as the
 * listing-approval override above.
 */
add_filter( 'option_truelysell_theme_options', 'custom_truelysell_force_booking_phone_required' );
function custom_truelysell_force_booking_phone_required( $value ) {
	if ( is_array( $value ) ) {
		$value['booking_phone_required'] = 'on';
	}
	return $value;
}

/**
 * Price and featured image on a "listing" are set once by the Administrator
 * and must stay that way — the earlier CSS-based hiding on the front-end
 * Add/Edit Service form only stopped technicians from seeing those fields
 * THERE; it did nothing to stop an "Owner"-role user from changing them via
 * wp-admin's native post editor, which uses the exact same update_post_meta()
 * plumbing. Short-circuit that plumbing itself for these specific keys on
 * "listing" posts so no code path — front-end form, wp-admin, anything —
 * can change them unless the current user is an actual Administrator.
 */
add_filter( 'update_post_metadata', 'custom_truelysell_lock_admin_only_listing_fields', 10, 4 );
/*
 * update_post_meta() only fires the 'update_post_metadata' filter when a
 * meta row already exists — for a BRAND NEW post there's nothing to
 * update yet, so the very first save goes through add_post_meta()
 * instead, which fires the separate 'add_post_metadata' filter. Without
 * this second hook, a new listing's price/gallery could be saved once,
 * completely bypassing the lock, before any later edit attempt would
 * ever get blocked.
 */
add_filter( 'add_post_metadata', 'custom_truelysell_lock_admin_only_listing_fields', 10, 4 );
function custom_truelysell_lock_admin_only_listing_fields( $check, $object_id, $meta_key, $meta_value ) {
	static $locked_keys = array(
		'_normal_price',
		'_initial_price',
		'_discount_type',
		'_price_discount_value',
		'_free_service',
		'_gallery',
		'_gallery_style',
		'_thumbnail_id',
	);

	if ( ! in_array( $meta_key, $locked_keys, true ) ) {
		return $check;
	}

	if ( current_user_can( 'administrator' ) ) {
		return $check;
	}

	if ( get_post_type( $object_id ) !== 'listing' ) {
		return $check;
	}

	// Non-null short-circuits update_metadata() — the write is silently dropped.
	return true;
}

/**
 * The Dashboard's "Services" stat card uses WordPress's native
 * count_user_posts() (truelysell-core/templates/account/dashboard.php),
 * which only counts listings this provider directly authored — it has no
 * idea about services linked via a registration category (see
 * custom_truelysell_get_provider_real_listing_ids()), so it can show a
 * different, wrong number than the "My Services" card on the very same
 * page. Overwrite it with the same real count everything else uses.
 */
add_action( 'wp_footer', 'custom_truelysell_fix_dashboard_services_stat_count' );
function custom_truelysell_fix_dashboard_services_stat_count() {
	$user_id = get_current_user_id();
	if ( ! $user_id || ! custom_truelysell_is_restricted_provider( $user_id ) ) {
		return;
	}

	$dashboard_page = function_exists( 'truelysell_fl_framework_getoptions' ) ? truelysell_fl_framework_getoptions( 'dashboard_page' ) : 0;
	if ( ! $dashboard_page || absint( $dashboard_page ) !== absint( get_queried_object_id() ) ) {
		return;
	}

	/*
	 * Don't rely on the "My Services" card elsewhere on this same page
	 * having already warmed the provisioned-services cache first — make
	 * that guarantee explicit here too (cheap no-op if it's already been
	 * refreshed this request).
	 */
	custom_truelysell_ensure_provider_service_listings( $user_id );

	$real_count = count( custom_truelysell_get_provider_real_listing_ids( $user_id ) );
	?>
	<script type="text/javascript">
	document.addEventListener('DOMContentLoaded', function() {
		var labels = document.querySelectorAll('.fs-14');
		for (var i = 0; i < labels.length; i++) {
			if (labels[i].textContent.trim() === 'Services') {
				var counter = labels[i].parentElement.querySelector('.counter');
				if (counter) {
					counter.textContent = <?php echo (int) $real_count; ?>;
				}
				break;
			}
		}
	});
	</script>
	<?php
}

/**
 * The plugin's own "Pricing" tab (meta_boxes_prices() in
 * truelysell-core-meta-boxes.php) is commented out of the wp-admin tab
 * array, so wp-admin's native "Listings" edit screen has no pricing fields
 * at all — Admin could only ever set a price through the front-end
 * Add/Edit Service form. Add a plain, Administrator-only meta box exposing
 * the same meta keys the rest of the site already reads, so price can be
 * set directly from wp-admin.
 */
add_action( 'add_meta_boxes', 'custom_truelysell_add_listing_pricing_metabox' );
function custom_truelysell_add_listing_pricing_metabox() {
	if ( ! current_user_can( 'administrator' ) ) {
		return;
	}

	add_meta_box(
		'custom_truelysell_pricing',
		__( 'Pricing', 'truelysell' ),
		'custom_truelysell_render_listing_pricing_metabox',
		'listing',
		'normal',
		'high'
	);
}

function custom_truelysell_render_listing_pricing_metabox( $post ) {
	wp_nonce_field( 'custom_truelysell_save_listing_pricing', 'custom_truelysell_listing_pricing_nonce' );

	$free_service   = get_post_meta( $post->ID, '_free_service', true );
	$initial_price  = get_post_meta( $post->ID, '_initial_price', true );
	$discount_type  = get_post_meta( $post->ID, '_discount_type', true );
	$discount_value = get_post_meta( $post->ID, '_price_discount_value', true );
	$normal_price   = get_post_meta( $post->ID, '_normal_price', true );
	?>
	<p>
		<label for="custom_truelysell_normal_price"><strong><?php esc_html_e( 'Price (this is what shows everywhere — dashboard, provider profile, listing page)', 'truelysell' ); ?></strong></label><br>
		<input type="number" step="any" id="custom_truelysell_normal_price" name="custom_truelysell_normal_price" value="<?php echo esc_attr( $normal_price ); ?>">
	</p>
	<p>
		<label>
			<input type="checkbox" name="custom_truelysell_free_service" value="on" <?php checked( $free_service, 'on' ); ?>>
			<?php esc_html_e( 'Enable Free Service', 'truelysell' ); ?>
		</label>
	</p>
	<hr>
	<p><em><?php esc_html_e( 'Optional — only needed if this service is on sale (shows a struck-through "was" price):', 'truelysell' ); ?></em></p>
	<p>
		<label for="custom_truelysell_initial_price"><?php esc_html_e( 'Original "Was" Price', 'truelysell' ); ?></label><br>
		<input type="number" step="any" id="custom_truelysell_initial_price" name="custom_truelysell_initial_price" value="<?php echo esc_attr( $initial_price ); ?>">
	</p>
	<p>
		<label for="custom_truelysell_discount_type"><?php esc_html_e( 'Discount Type', 'truelysell' ); ?></label><br>
		<select id="custom_truelysell_discount_type" name="custom_truelysell_discount_type">
			<option value="nodiscount" <?php selected( $discount_type, 'nodiscount' ); ?>><?php esc_html_e( 'No Discount', 'truelysell' ); ?></option>
			<option value="fixed" <?php selected( $discount_type, 'fixed' ); ?>><?php esc_html_e( 'Fixed Price', 'truelysell' ); ?></option>
			<option value="percentage" <?php selected( $discount_type, 'percentage' ); ?>><?php esc_html_e( 'Percentage', 'truelysell' ); ?></option>
		</select>
	</p>
	<p>
		<label for="custom_truelysell_discount_value"><?php esc_html_e( 'Offer Price', 'truelysell' ); ?></label><br>
		<input type="number" step="any" id="custom_truelysell_discount_value" name="custom_truelysell_discount_value" value="<?php echo esc_attr( $discount_value ); ?>">
	</p>
	<?php
}

add_action( 'save_post_listing', 'custom_truelysell_save_listing_pricing_metabox' );
function custom_truelysell_save_listing_pricing_metabox( $post_id ) {
	if ( ! isset( $_POST['custom_truelysell_listing_pricing_nonce'] ) || ! wp_verify_nonce( $_POST['custom_truelysell_listing_pricing_nonce'], 'custom_truelysell_save_listing_pricing' ) ) {
		return;
	}

	if ( ! current_user_can( 'administrator' ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
		return;
	}

	update_post_meta( $post_id, '_free_service', isset( $_POST['custom_truelysell_free_service'] ) ? 'on' : 'off' );
	update_post_meta( $post_id, '_initial_price', sanitize_text_field( $_POST['custom_truelysell_initial_price'] ?? '' ) );
	update_post_meta( $post_id, '_discount_type', sanitize_text_field( $_POST['custom_truelysell_discount_type'] ?? 'nodiscount' ) );
	update_post_meta( $post_id, '_price_discount_value', sanitize_text_field( $_POST['custom_truelysell_discount_value'] ?? '' ) );
	update_post_meta( $post_id, '_normal_price', sanitize_text_field( $_POST['custom_truelysell_normal_price'] ?? '' ) );
}

/**
 * Only actual Administrators should ever see the WordPress admin bar/toolbar —
 * not providers/technicians/customers — whether it's the bar shown at the top
 * of the front-end (site header, including our custom Dashboard page) or the
 * one shown inside wp-admin.
 */
add_filter( 'show_admin_bar', 'custom_truelysell_hide_admin_bar_for_non_admins' );
function custom_truelysell_hide_admin_bar_for_non_admins( $show ) {
	return current_user_can( 'administrator' );
}

/**
 * Pricing, Gallery/photo, FAQ, Includes, Additional Service, Details and
 * Availability (time slots) are set centrally by the business owner, not by
 * individual technicians, so hide those accordion sections on the Add/Edit
 * Service form (submit_page) for anyone who isn't an Administrator. The
 * underlying fields stay in the form (just hidden), so editing other fields
 * and re-saving does not wipe out the existing values.
 */
add_action( 'wp_head', 'custom_truelysell_hide_price_gallery_for_non_admins' );
function custom_truelysell_hide_price_gallery_for_non_admins() {
	if ( current_user_can( 'administrator' ) ) {
		return;
	}

	$submit_page = function_exists( 'truelysell_fl_framework_getoptions' ) ? truelysell_fl_framework_getoptions( 'submit_page' ) : 0;
	if ( ! $submit_page || absint( $submit_page ) !== absint( get_queried_object_id() ) ) {
		return;
	}

	$hidden_sections = array(
		'basic_prices',
		'gallery',
		'faq_section',
		'includes_title',
		'additional_services',
		'details',
		'location',
		'slots_new',
	);
	?>
	<style>
		<?php foreach ( $hidden_sections as $section ) : ?>
		.accordion-item:has(#accordion-heading<?php echo esc_attr( 'One_' . $section ); ?>),
		#accordion-heading<?php echo esc_attr( 'One_' . $section ); ?>,
		#accordion-collapse<?php echo esc_attr( 'One_' . $section ); ?> {
			display: none !important;
		}
		<?php endforeach; ?>
	</style>
	<?php
}

/**
 * The Category field is now redundant on the Add/Edit Service form:
 * picking a service from the "Service Title" dropdown already syncs the
 * matching Category behind the scenes (see
 * custom_truelysell_convert_listing_title_to_dropdown()) — Category still
 * gets set and saved exactly as before, just without a second, duplicate
 * control for the user to look at.
 */
add_action( 'wp_head', 'custom_truelysell_hide_redundant_category_field' );
function custom_truelysell_hide_redundant_category_field() {
	if ( current_user_can( 'administrator' ) ) {
		return;
	}

	$submit_page = function_exists( 'truelysell_fl_framework_getoptions' ) ? truelysell_fl_framework_getoptions( 'submit_page' ) : 0;
	if ( ! $submit_page || absint( $submit_page ) !== absint( get_queried_object_id() ) ) {
		return;
	}
	?>
	<style>
		.form-field-listing_category-container {
			display: none !important;
		}
	</style>
	<?php
}

/**
 * The single-listing page's "Related Services" carousel
 * (truelysell-core/templates/single-listing.php) runs its own query with
 * no author restriction at all — unlike the main catalog, which is
 * correctly filtered to Admin-authored listings (see
 * custom_truelysell_restrict_public_archive_to_admins()). That means it
 * can surface a technician's own junk/duplicate posts (no price, no
 * image) right alongside real services, and there's no filter hook on
 * that query to fix it cleanly. Hide it rather than risk it showing
 * broken-looking entries.
 */
add_action( 'wp_head', 'custom_truelysell_hide_related_services_section' );
function custom_truelysell_hide_related_services_section() {
	if ( ! is_singular( 'listing' ) ) {
		return;
	}
	?>
	<style>
		.accordion-item:has(#rservices) {
			display: none !important;
		}
	</style>
	<?php
}

/**
 * "Mark As Paid" (truelysell-core/templates/booking/content-booking.php,
 * provider's own Booking List rows only) exists for the plugin's native
 * manual-invoicing workflow — a booking starts "confirmed, awaiting
 * payment" and a provider marks it paid once they've collected it
 * themselves. On this site every booking is instead created already
 * status "paid" the moment the customer's 20% deposit clears (see
 * custom_truelysell_finalize_deposit_booking()) — there's no "awaiting
 * payment" state for this button to ever act on. Clicking it hits the
 * plugin's own "don't process a status change to the same status" guard
 * (Truelysell_Core_Bookings_Calendar::set_booking_status(), the
 * `if ($booking_data['status'] == $status) return;` check) and silently
 * no-ops — technically correct, but looks like a broken button. Hiding it
 * site-wide since the class only ever appears on this one row template.
 */
add_action( 'wp_head', 'custom_truelysell_hide_mark_as_paid_button' );
function custom_truelysell_hide_mark_as_paid_button() {
	?>
	<style>
		.mark-as-paid {
			display: none !important;
		}
	</style>
	<?php
}

/**
 * The decorative "breadcrumb-bg-01.png" / "breadcrumb-bg-02.png" images in
 * the hero/breadcrumb section aren't in any editable theme template — hide
 * them site-wide via CSS instead.
 */
add_action( 'wp_head', 'custom_truelysell_hide_breadcrumb_bg_images' );
function custom_truelysell_hide_breadcrumb_bg_images() {
	?>
	<style>
		.breadcrumb-bg,
		.breadcrumb-bg-1,
		.breadcrumb-bg-2 {
			display: none !important;
		}
	</style>
	<?php
}

/**
 * CSS alone isn't enough for the sections hidden above: a hidden field that
 * still has the `required` attribute (e.g. Pricing's "Enter Amount") makes
 * the browser refuse to submit the form at all — "An invalid form control
 * ... is not focusable" — silently blocking every non-admin from ever
 * saving a service. Strip `required` from anything inside those sections
 * once they're hidden.
 */
add_action( 'wp_footer', 'custom_truelysell_unrequire_hidden_service_fields_for_non_admins', 5 );
function custom_truelysell_unrequire_hidden_service_fields_for_non_admins() {
	if ( current_user_can( 'administrator' ) ) {
		return;
	}

	$submit_page = function_exists( 'truelysell_fl_framework_getoptions' ) ? truelysell_fl_framework_getoptions( 'submit_page' ) : 0;
	if ( ! $submit_page || absint( $submit_page ) !== absint( get_queried_object_id() ) ) {
		return;
	}
	?>
	<script type="text/javascript">
	document.addEventListener('DOMContentLoaded', function() {
		var hiddenSectionIds = ['basic_prices', 'gallery', 'faq_section', 'includes_title', 'additional_services', 'details', 'location'];
		hiddenSectionIds.forEach(function(section) {
			var collapse = document.getElementById('accordion-collapseOne_' + section);
			if (!collapse) {
				return;
			}
			collapse.querySelectorAll('[required]').forEach(function(field) {
				field.removeAttribute('required');
			});
		});
	});
	</script>
	<?php
}

/**
 * The Add/Edit Service form's Phone field (in the Details section, only
 * ever visible to Admin since Details is hidden for everyone else — see
 * custom_truelysell_hide_price_gallery_for_non_admins()) has no
 * "required" attribute in the plugin's own template at all. Add one so
 * it can't be left blank when Admin creates/edits a listing.
 */
add_action( 'wp_footer', 'custom_truelysell_require_listing_phone_field' );
function custom_truelysell_require_listing_phone_field() {
	/*
	 * Must stay Admin-only: the Details section (which contains this
	 * field) is CSS-hidden for everyone else
	 * (custom_truelysell_hide_price_gallery_for_non_admins()), and a
	 * required field inside a hidden section blocks form submission
	 * entirely with "An invalid form control ... is not focusable" — the
	 * exact bug custom_truelysell_unrequire_hidden_service_fields_for_non_admins()
	 * exists to prevent. Forcing required back on here for non-admins
	 * would silently undo that fix.
	 */
	if ( ! current_user_can( 'administrator' ) ) {
		return;
	}

	$submit_page = function_exists( 'truelysell_fl_framework_getoptions' ) ? truelysell_fl_framework_getoptions( 'submit_page' ) : 0;
	if ( ! $submit_page || absint( $submit_page ) !== absint( get_queried_object_id() ) ) {
		return;
	}
	?>
	<script type="text/javascript">
	document.addEventListener('DOMContentLoaded', function() {
		var phoneField = document.getElementById('_phone');
		if (phoneField) {
			phoneField.setAttribute('required', 'required');
		}
	});
	</script>
	<?php
}

/**
 * The Add/Edit Service form's "Service Title" is a free-text input, so
 * technicians can (and did) type anything. Replace it with a dropdown of
 * real services only — the same Admin-authored, published catalog the
 * public "/services/" page and "Found X Services" count show (see
 * custom_truelysell_restrict_public_archive_to_admins()) — not every
 * category, and not categories only fulfilled by a technician's own
 * listing. Picking a title also selects the matching Category to keep the
 * two in sync.
 */
add_action( 'wp_footer', 'custom_truelysell_convert_listing_title_to_dropdown' );
function custom_truelysell_convert_listing_title_to_dropdown() {
	/*
	 * Admin must keep the free-text title field — this dropdown only ever
	 * lists categories that already have a published Admin listing, so
	 * converting it for Admin too would make it impossible to ever create
	 * a listing in a genuinely new category from this front-end form
	 * (only wp-admin's native editor would still allow it).
	 */
	if ( current_user_can( 'administrator' ) ) {
		return;
	}

	$submit_page = function_exists( 'truelysell_fl_framework_getoptions' ) ? truelysell_fl_framework_getoptions( 'submit_page' ) : 0;
	if ( ! $submit_page || absint( $submit_page ) !== absint( get_queried_object_id() ) ) {
		return;
	}

	$admin_ids = get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) );

	$published_service_names = array();
	$categories              = get_terms( array( 'taxonomy' => 'listing_category', 'hide_empty' => false ) );
	if ( ! is_wp_error( $categories ) && ! empty( $admin_ids ) ) {
		foreach ( $categories as $category ) {
			if ( preg_match( '/^\d+$/', $category->name ) ) {
				continue; // skip numeric/junk taxonomy terms
			}
			$has_published = get_posts( array(
				'post_type'              => 'listing',
				'post_status'            => 'publish',
				'author__in'             => $admin_ids,
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'tax_query'              => array(
					array(
						'taxonomy' => 'listing_category',
						'field'    => 'term_id',
						'terms'    => array( $category->term_id ),
					),
				),
			) );
			if ( ! empty( $has_published ) ) {
				$published_service_names[] = $category->name;
			}
		}
	}
	?>
	<script type="text/javascript">
	document.addEventListener('DOMContentLoaded', function() {
		var titleInput     = document.getElementById('listing_title');
		var categorySelect = document.getElementById('listing_category');
		if (!titleInput || !categorySelect || titleInput.tagName === 'SELECT') {
			return;
		}

		var publishedServiceNames = <?php echo wp_json_encode( $published_service_names ); ?>;
		var currentTitle = (titleInput.value || '').trim();

		var titleSelect = document.createElement('select');
		titleSelect.id        = 'listing_title';
		titleSelect.name      = 'listing_title';
		titleSelect.className = titleInput.className || 'form-control';
		if (titleInput.hasAttribute('required')) {
			titleSelect.setAttribute('required', 'required');
		}

		var placeholder = document.createElement('option');
		placeholder.value       = '';
		placeholder.textContent = 'Choose Service';
		titleSelect.appendChild(placeholder);

		var matched = false;
		publishedServiceNames.forEach(function(label) {
			var newOpt = document.createElement('option');
			newOpt.value       = label;
			newOpt.textContent = label;
			if (currentTitle && label.toLowerCase() === currentTitle.toLowerCase()) {
				newOpt.selected = true;
				matched = true;
			}
			titleSelect.appendChild(newOpt);
		});

		if (!matched && currentTitle) {
			// Preserve an existing title that doesn't match any current service name
			var customOpt = document.createElement('option');
			customOpt.value       = currentTitle;
			customOpt.textContent = currentTitle;
			customOpt.selected    = true;
			titleSelect.insertBefore(customOpt, titleSelect.children[1]);
		}

		titleInput.parentNode.replaceChild(titleSelect, titleInput);

		titleSelect.addEventListener('change', function() {
			var chosen = titleSelect.value;
			var matchOption = Array.prototype.find.call(categorySelect.querySelectorAll('option'), function(opt) {
				return (opt.textContent || '').trim().toLowerCase() === chosen.toLowerCase();
			});
			if (matchOption) {
				categorySelect.value = matchOption.value;
				if (window.jQuery) {
					jQuery(categorySelect).trigger('change');
				} else {
					categorySelect.dispatchEvent(new Event('change'));
				}
			}
		});

		if (window.jQuery && jQuery.fn && jQuery.fn.select2) {
			jQuery(titleSelect).select2({
				placeholder: 'Choose Service',
				width: '100%'
			});
		}
	});
	</script>
	<?php
}

/*
 * Safe fallbacks: prevent fatal "call to undefined function" errors
 * on sites where the Truelysell helpers are not present.
 */

add_action( 'init', 'custom_truelysell_ensure_customer_role' );
function custom_truelysell_ensure_customer_role() {
	$customer_caps = array(
		'read'                  => true,
		'level_0'               => true,
		'read_listing'          => true,
		'read_listings'         => true,
		'read_private_listings' => true,
	);

	if ( ! wp_roles()->is_role( 'customer' ) ) {
		add_role(
			'customer',
			'Customer',
			$customer_caps
		);
	} else {
		$role = get_role( 'customer' );
		if ( $role ) {
			foreach ( $customer_caps as $cap => $grant ) {
				if ( $grant && ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	if ( ! wp_roles()->is_role( 'guest' ) ) {
		add_role(
			'guest',
			'Guest',
			$customer_caps
		);
	} else {
		$role = get_role( 'guest' );
		if ( $role ) {
			foreach ( $customer_caps as $cap => $grant ) {
				if ( $grant && ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}
	}
}

function custom_truelysell_is_customer_user( $user_id = 0 ) {
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}

	if ( ! $user_id ) {
		return false;
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return false;
	}

	return in_array( 'guest', (array) $user->roles, true ) || in_array( 'customer', (array) $user->roles, true ) || get_user_meta( $user_id, 'custom_truelysell_registration_type', true ) === 'customer';
}

/**
 * HELPER: AUTO-APPROVE & AUTO-VERIFY A PROVIDER/OWNER
 */
function custom_truelysell_auto_approve_verify_user( $user_id = 0 ) {
	if ( ! $user_id ) {
		return;
	}

	$user = new WP_User( $user_id );
	if ( ! $user->exists() ) {
		return;
	}

	$meta_keys = array(
		'verify_status', 'is_verified', 'verified', 'verify', 'provider_verify',
		'email_verified', 'status', 'provider_status', 'is_approved', 'approval',
		'approve', 'email_verify_status', 'provider_verify_status', 'document_verified',
		'provider_verify_request',
	);

	foreach ( $meta_keys as $key ) {
		update_user_meta( $user_id, $key, '1' );
	}

	update_user_meta( $user_id, 'verify_email', 'yes' );
	update_user_meta( $user_id, 'verify_code', '' );
	update_user_meta( $user_id, 'verification_code', '' );

	global $wpdb;
	$provider_table = $wpdb->prefix . 'truelysell_providers';

	if ( $wpdb->get_var( "SHOW TABLES LIKE '$provider_table'" ) === $provider_table ) {
		$provider_exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $provider_table WHERE user_id = %d", $user_id ) );
		$columns         = $wpdb->get_col( "DESCRIBE $provider_table" );
		$prov_data       = array();

		if ( in_array( 'user_id', $columns, true ) ) {
			$prov_data['user_id'] = $user_id;
		}
		if ( in_array( 'name', $columns, true ) ) {
			$prov_data['name'] = $user->display_name ? $user->display_name : $user->user_login;
		}
		if ( in_array( 'provider_name', $columns, true ) ) {
			$prov_data['provider_name'] = $user->display_name ? $user->display_name : $user->user_login;
		}

		$status_cols = array( 'status', 'verify', 'verified', 'is_verified', 'verify_status', 'is_approved', 'approval_status' );
		foreach ( $status_cols as $col ) {
			if ( in_array( $col, $columns, true ) ) {
				$prov_data[ $col ] = 1;
			}
		}

		if ( in_array( 'created_at', $columns, true ) ) {
			$prov_data['created_at'] = current_time( 'mysql' );
		}

		if ( $provider_exists ) {
			$wpdb->update( $provider_table, $prov_data, array( 'user_id' => $user_id ) );
		} else {
			$wpdb->insert( $provider_table, $prov_data );
		}
	}
}

function custom_truelysell_is_restricted_provider( $user_id = 0 ) {
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}

	if ( ! $user_id ) {
		return false;
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return false;
	}

	/*
	 * An Administrator is never a "restricted provider" — even if leftover
	 * `is_technician` meta or an 'owner'/'provider' role is also on the
	 * account from earlier testing. Without this, the auto-provisioning
	 * system treated the admin account as a technician needing its own
	 * placeholder listings, creating duplicates (e.g. a second "Economy
	 * Package: TV Mounting") of services the admin already has in the
	 * real catalog.
	 */
	if ( in_array( 'administrator', (array) $user->roles, true ) ) {
		return false;
	}

	if ( get_user_meta( $user_id, 'is_technician', true ) ) {
		return true;
	}

	$restricted_roles = array( 'owner', 'provider' );
	foreach ( $restricted_roles as $role ) {
		if ( in_array( $role, (array) $user->roles, true ) ) {
			return true;
		}
	}

	return false;
}

/**
 * 'provider' is a leftover/orphan role (see custom_truelysell_is_restricted_provider()
 * above — it was never registered via add_role(), only left on some
 * accounts from earlier testing) that should be treated identically to
 * 'owner' everywhere the dashboard template decides provider-vs-customer
 * UI. Without this, a 'provider'-role technician fails every role
 * whitelist check in template-dashboard.php and gets silently routed to
 * the plain customer view — which is why they'd see zero bookings even
 * though bookings are correctly stored against them.
 */
function custom_truelysell_normalize_dashboard_role( $role ) {
	return ( 'provider' === $role ) ? 'owner' : $role;
}

/**
 * One-time cleanup: convert any existing 'provider'-role accounts to the
 * real, registered 'owner' role, so the codebase only has to reason about
 * one "real technician" role going forward. Gated to run once, on an
 * actual admin's wp-admin page load (no WP-CLI access assumed).
 */
add_action( 'admin_init', 'custom_truelysell_migrate_provider_role_to_owner_once' );
function custom_truelysell_migrate_provider_role_to_owner_once() {
	if ( ! current_user_can( 'manage_options' ) || get_option( '_truelysell_provider_role_migrated' ) ) {
		return;
	}

	$provider_user_ids = get_users( array( 'role' => 'provider', 'fields' => 'ID' ) );
	foreach ( $provider_user_ids as $uid ) {
		$user = get_userdata( $uid );
		if ( $user && in_array( 'provider', (array) $user->roles, true ) ) {
			$user->remove_role( 'provider' );
			$user->add_role( 'owner' );
		}
	}

	update_option( '_truelysell_provider_role_migrated', array(
		'ran_at'   => current_time( 'mysql' ),
		'user_ids' => $provider_user_ids,
	) );

	if ( ! empty( $provider_user_ids ) ) {
		set_transient( '_truelysell_provider_role_migration_notice', count( $provider_user_ids ), 60 );
	}
}

add_action( 'admin_notices', 'custom_truelysell_show_provider_role_migration_notice' );
function custom_truelysell_show_provider_role_migration_notice() {
	$count = get_transient( '_truelysell_provider_role_migration_notice' );
	if ( ! $count ) {
		return;
	}
	delete_transient( '_truelysell_provider_role_migration_notice' );
	printf(
		'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
		esc_html( sprintf(
			/* translators: %d: number of migrated accounts */
			_n( 'Truelysell: migrated %d user from the legacy "provider" role to "owner".', 'Truelysell: migrated %d users from the legacy "provider" role to "owner".', $count, 'truelysell' ),
			$count
		) )
	);
}

function custom_truelysell_cleanup_customer_provider_data( $user_id = 0 ) {
	if ( ! $user_id ) {
		return;
	}

	update_user_meta( $user_id, 'is_technician', '0' );
	update_user_meta( $user_id, 'custom_truelysell_registration_type', 'customer' );
	delete_user_meta( $user_id, 'provider_services' );

	global $wpdb;
	$provider_table = $wpdb->prefix . 'truelysell_providers';

	if ( $wpdb->get_var( "SHOW TABLES LIKE '$provider_table'" ) === $provider_table ) {
		$wpdb->delete( $provider_table, array( 'user_id' => $user_id ), array( '%d' ) );
	}
}

// ⛔ REDIRECT DISABLED — was causing infinite redirect loops
// Customers can access all dashboard pages normally.
// Content visibility is controlled by templates, not redirects.
// add_action( 'template_redirect', 'custom_truelysell_protect_customer_from_provider_pages' );
function custom_truelysell_protect_customer_from_provider_pages() {
	// DISABLED — do nothing, no redirects
	return;
}


/**
 * truelysell_count_customer_bookings() / truelysell_get_customer_bookings() are
 * intentionally NOT defined here. The correct implementations (querying
 * {$wpdb->prefix}bookings_calendar by the bookings_author column) already live in
 * the parent theme's inc/template-tags.php and are picked up automatically since
 * this child theme no longer shadows them with a broken table/column guess.
 */

/**
 * FALLBACK: AUTO-APPROVE/VERIFY EXISTING TECHNICIANS ON LOGIN & DASHBOARD ACCESS
 */
add_action( 'wp_login', 'custom_truelysell_auto_verify_on_login', 10, 2 );
function custom_truelysell_auto_verify_on_login( $user_login = '', $user = null ) {
	if ( ! $user ) {
		return;
	}
	if ( custom_truelysell_is_customer_user( $user->ID ) ) {
		$user->set_role( 'customer' );
		custom_truelysell_cleanup_customer_provider_data( $user->ID );
		return;
	}
	if ( custom_truelysell_is_restricted_provider( $user->ID ) ) {
		custom_truelysell_auto_approve_verify_user( $user->ID );
	}
}

add_action( 'admin_init', 'custom_truelysell_auto_verify_on_admin_init' );
function custom_truelysell_auto_verify_on_admin_init() {
	if ( is_user_logged_in() ) {
		$user = wp_get_current_user();
		if ( $user && custom_truelysell_is_restricted_provider( $user->ID ) ) {
			custom_truelysell_auto_approve_verify_user( $user->ID );
		}
	}
}

/**
 * 1. HANDLE USER REGISTRATION VIA WPFORMS (FORM ID: 8203)
 * ---- FIXED HOOK: 'user_register' (WordPress core, hamesha fire hota hai) ----
 */
add_action( 'user_register', 'custom_truelysell_registration_handler', 999, 1 );
function custom_truelysell_registration_handler( $user_id ) {

	// Sirf hamare form (ID 8203) ke submission par chalao
	if ( ! isset( $_POST['wpforms']['id'] ) || absint( $_POST['wpforms']['id'] ) !== 8203 ) {
		return;
	}

	$user = new WP_User( $user_id );
	if ( ! $user->exists() ) {
		return;
	}

	$raw_fields = isset( $_POST['wpforms']['fields'] ) ? (array) $_POST['wpforms']['fields'] : array();

	// --- A. Detect user registration type from WPForms fields ---
	$user_type = custom_truelysell_wpforms_get_field_value( $raw_fields, 1 );

	if ( empty( $user_type ) ) {
		$user_type = custom_truelysell_wpforms_get_field_value_by_label_keywords( $raw_fields, array(
		'user role',
		'role',
		'account type',
		'register as',
		'register as a',
		'select user',
		'user type',
		'registration type',
		'profile type',
	) );
	}

	$user_type = strtolower( trim( $user_type ) );

	$nationality = custom_truelysell_wpforms_get_field_value_by_label_keywords( $raw_fields, array( 'nationality', 'country' ) );
	if ( ! empty( $nationality ) ) {
		update_user_meta( $user_id, 'nationality', $nationality );
	}

	$is_technician = false;
	if ( in_array( $user_type, array( 'technician', 'provider', 'owner', 'service provider' ), true ) ) {
		$is_technician = true;
	}

	if ( $is_technician ) {
		if ( in_array( $user_type, array( 'owner', 'technician', 'provider', 'service provider' ), true ) ) {
			$user->set_role( 'owner' );
		} else {
			$user->set_role( 'subscriber' );
		}

		update_user_meta( $user_id, 'is_technician', '1' );
		update_user_meta( $user_id, 'custom_truelysell_registration_type', 'technician' );
	} else {
		$user->set_role( 'customer' );
		custom_truelysell_cleanup_customer_provider_data( $user_id );
	}

	if ( $is_technician ) {
		custom_truelysell_auto_approve_verify_user( $user_id );

		$service_field_id = 77; // WPForms services checkbox field ID.
		$services = custom_truelysell_wpforms_get_field_value( $raw_fields, $service_field_id );

		if ( empty( $services ) ) {
			$services = custom_truelysell_wpforms_get_field_value( $raw_fields );
		}

		if ( ! empty( $services ) ) {
		$services = custom_truelysell_wpforms_map_field_values_to_labels( 8203, 77, $services );
		update_user_meta( $user_id, 'provider_services', $services );
		custom_truelysell_ensure_provider_service_listings( $user_id );
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( sprintf( 'custom_truelysell: saved provider_services="%s" for user_id=%d user_type=%s', $services, $user_id, $user_type ) );
		}
	} else {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( sprintf( 'custom_truelysell: no provider_services found for user_id=%d user_type=%s', $user_id, $user_type ) );
			}
		}

		/*
		 * The 20% platform fee notice + required checkbox is injected onto
		 * the WPForms registration form client-side (see
		 * custom_truelysell_render_technician_fee_agreement_notice()) — it
		 * isn't a real WPForms field, just a plain checkbox placed inside
		 * the same <form>, so it posts as a normal top-level $_POST key
		 * rather than through $_POST['wpforms']['fields']. Record whether
		 * it was actually checked, same pattern as every other custom
		 * registration field this handler already stores.
		 */
		update_user_meta( $user_id, 'custom_truelysell_fee_agreement_accepted', ! empty( $_POST['custom_truelysell_fee_agreement'] ) ? '1' : '0' );
		update_user_meta( $user_id, 'custom_truelysell_fee_agreement_accepted_time', current_time( 'mysql' ) );
	}

	$ghl_user_data = get_userdata( $user_id );
	$ghl_phone     = get_user_meta( $user_id, 'phone', true );
	if ( ! $ghl_phone ) {
		$ghl_phone = get_user_meta( $user_id, 'phone_number', true );
	}
	custom_truelysell_send_ghl_webhook(
		$is_technician ? TRUELYSELL_CHILD_GHL_WEBHOOK_TECHNICIAN_URL : TRUELYSELL_CHILD_GHL_WEBHOOK_CUSTOMER_URL,
		$is_technician ? 'new_technician' : 'new_customer',
		array(
			'user_id'    => $user_id,
			'first_name' => $ghl_user_data ? $ghl_user_data->first_name : '',
			'last_name'  => $ghl_user_data ? $ghl_user_data->last_name : '',
			'name'       => $ghl_user_data ? $ghl_user_data->display_name : '',
			'email'      => $ghl_user_data ? $ghl_user_data->user_email : '',
			'phone'      => $ghl_phone,
		)
	);

	/*
	 * The theme's own "Registration/Welcome email for new users" settings
	 * (Theme Options → Emails — welcome_email_disable / listing_welcome_
	 * email_subject / listing_welcome_email_content) exist and are fully
	 * configurable, but nothing ever actually sends using them on this
	 * site — the plugin's own native code that normally would is tied to
	 * its own native registration form/flow, which this site doesn't use
	 * (registration goes through WPForms form 8203 instead, handled by
	 * this function). Send it here instead, using the exact same
	 * admin-configured subject/content, so customers and technicians
	 * both get it regardless of which form created the account.
	 */
	custom_truelysell_send_theme_welcome_email( $user_id, $raw_fields );

	/**
	 * IMPORTANT FIX: Agar form submit karte waqt browser mein pehle se
	 * koi dusra user (masalan Admin) login hai, to WordPress apne aap
	 * naye register hue user ko login nahi karta — purani session
	 * (Admin) hi active reh jati hai, isi wajah se header mein "Admin"
	 * dikhta reh jata hai. Ye block purani session clear karke
	 * naye register hue user ko turant login kar deta hai.
	 */
	wp_clear_auth_cookie();
	wp_set_current_user( $user_id );
	wp_set_auth_cookie( $user_id, true );

	if ( ! $is_technician ) {
		return;
	}
}

/**
 * Sends the theme's own configurable "Welcome Email" (Theme Options —
 * see inc/options-init.php, section "Registration/Welcome email for new
 * users") to a newly registered user, since nothing else on this site
 * actually does. Supports the same {user_mail}/{user_name}/{site_name}/
 * {password}/{login} tags the options screen itself documents.
 */
function custom_truelysell_send_theme_welcome_email( $user_id, $raw_fields = array() ) {
	if ( ! function_exists( 'truelysell_fl_framework_getoptions' ) ) {
		return;
	}

	if ( truelysell_fl_framework_getoptions( 'welcome_email_disable' ) ) {
		return;
	}

	$user = get_userdata( $user_id );
	if ( ! $user || ! is_email( $user->user_email ) ) {
		return;
	}

	$subject = truelysell_fl_framework_getoptions( 'listing_welcome_email_subject' );
	$content = truelysell_fl_framework_getoptions( 'listing_welcome_email_content' );

	if ( ! $subject ) {
		$subject = __( 'Welcome to {site_name}', 'truelysell' );
	}
	if ( ! $content ) {
		$content = __( 'Hi {user_name},<br>Welcome to our website.<br>Username: {login}<br>Thank you.', 'truelysell' );
	}

	/*
	 * The plain password only exists in $_POST at all while this request
	 * is still handling the original form submission — by the time
	 * wp_insert_user() ran, it's already hashed in the database. WPForms'
	 * own Password field is the only place it's still available here.
	 * If this form/submission doesn't have one (e.g. WPForms generated a
	 * random password instead), fall back to a safe, sensible phrase
	 * rather than leaving a blank in the email.
	 */
	$plain_password = custom_truelysell_wpforms_get_field_value_by_label_keywords( $raw_fields, array( 'password' ) );
	$password_display = $plain_password ? $plain_password : __( '(the password you set during registration)', 'truelysell' );

	$tags = array(
		'{user_mail}' => $user->user_email,
		'{user_name}' => $user->display_name ? $user->display_name : $user->user_login,
		'{site_name}' => get_bloginfo( 'name' ),
		'{password}'  => $password_display,
		'{login}'     => $user->user_login,
	);

	$subject = strtr( $subject, $tags );
	$body    = strtr( $content, $tags );

	wp_mail( $user->user_email, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
}

/**
 * HELPER: Retrieve WPForms field value by ID or fallback from field labels/names.
 */
function custom_truelysell_wpforms_get_field_value( $fields, $field_id = 0 ) {
	if ( ! is_array( $fields ) ) {
		return '';
	}

	if ( $field_id && isset( $fields[ $field_id ] ) ) {
		$field = $fields[ $field_id ];
		if ( is_array( $field ) && isset( $field['value'] ) ) {
			$field = $field['value'];
		}
		if ( is_array( $field ) ) {
			$field = implode( ', ', array_filter( array_map( 'sanitize_text_field', wp_unslash( $field ) ) ) );
		} else {
			$field = sanitize_text_field( wp_unslash( $field ) );
		}
		return trim( $field );
	}

	foreach ( $fields as $field ) {
		if ( ! is_array( $field ) ) {
			continue;
		}

		$value = '';
		if ( isset( $field['value'] ) ) {
			$value = $field['value'];
		} elseif ( isset( $field[0] ) ) {
			$value = $field[0];
		}

		if ( empty( $value ) ) {
			continue;
		}

		$label = '';
		if ( isset( $field['label'] ) ) {
			$label = $field['label'];
		} elseif ( isset( $field['name'] ) ) {
			$label = $field['name'];
		}

		if ( stripos( $label, 'service' ) !== false ) {
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_filter( array_map( 'sanitize_text_field', wp_unslash( $value ) ) ) );
			} else {
				$value = sanitize_text_field( wp_unslash( $value ) );
			}
			return trim( $value );
		}
	}

	return '';
}

/**
 * Retrieve WPForms field value by label/name keyword matching.
 */
function custom_truelysell_wpforms_get_field_value_by_label_keywords( $fields, $keywords = array() ) {
	if ( ! is_array( $fields ) ) {
		return '';
	}

	$keywords = array_map( 'strtolower', $keywords );

	foreach ( $fields as $field_id => $field ) {
		if ( absint( $field_id ) === 1 && ! is_array( $field ) ) {
			$field = array(
				'label' => 'Select User',
				'value' => $field,
			);
		}

		if ( ! is_array( $field ) ) {
			continue;
		}

		$value = '';
		if ( isset( $field['value'] ) ) {
			$value = $field['value'];
		} elseif ( isset( $field[0] ) ) {
			$value = $field[0];
		}

		$label = '';
		if ( isset( $field['label'] ) ) {
			$label = $field['label'];
		} elseif ( isset( $field['name'] ) ) {
			$label = $field['name'];
		}

		$label = strtolower( $label );

		foreach ( $keywords as $keyword ) {
			if ( $keyword !== '' && strpos( $label, $keyword ) !== false ) {
				if ( is_array( $value ) ) {
					$value = array_map( 'sanitize_text_field', wp_unslash( $value ) );
					$value = implode( ', ', array_filter( $value ) );
				} else {
					$value = sanitize_text_field( wp_unslash( $value ) );
				}

				return trim( $value );
			}
		}
	}

	return '';
}

/**
 * Convert WPForms checkbox values from the registration form into service names.
 */
function custom_truelysell_wpforms_map_field_values_to_labels( $form_id, $field_id, $raw_values ) {
	$values = is_array( $raw_values ) ? $raw_values : explode( ',', (string) $raw_values );
	$values = array_filter( array_map( 'trim', $values ) );

	if ( empty( $values ) ) {
		return '';
	}

	$choices = array(
		'1332' => 'Ceiling TV Mounting',
		'8298' => 'Economy: Above Fireplace',
		'8055' => 'Conference Room Display Installation',
		'1319' => 'Corner TV Mounting',
		'5793' => 'Fireplace TV Mounting',
		'5791' => 'Multi-Room TV Mounting',
		'5789' => 'Soundbar Mounting',
		'8113' => 'TV Uninstallation Service',
		'5743' => 'Wire Concealment',
	);

	$wpforms = function_exists( 'wpforms' ) ? wpforms() : null;
	if ( $wpforms && isset( $wpforms->form ) ) {
		$form = $wpforms->form->get( absint( $form_id ) );
		if ( $form && ! empty( $form->post_content ) ) {
			$form_data = json_decode( $form->post_content, true );
			if ( ! empty( $form_data['fields'][ $field_id ]['choices'] ) && is_array( $form_data['fields'][ $field_id ]['choices'] ) ) {
				foreach ( $form_data['fields'][ $field_id ]['choices'] as $choice ) {
					if ( empty( $choice['label'] ) ) {
						continue;
					}

					$value = isset( $choice['value'] ) && $choice['value'] !== '' ? $choice['value'] : $choice['label'];
					$label = preg_replace( '/^[\s\-\x{2014}]+/u', '', $choice['label'] );

					$choices[ trim( (string) $value ) ]       = $label;
					$choices[ trim( (string) $choice['label'] ) ] = $label;
				}
			}
		}
	}

	$labels = array();
	foreach ( $values as $value ) {
		$value = sanitize_text_field( wp_unslash( $value ) );
		$label = isset( $choices[ $value ] ) ? $choices[ $value ] : $value;
		$label = trim( preg_replace( '/^[\s\-\x{2014}]+/u', '', $label ) );

		if ( $label !== '' && ! in_array( $label, $labels, true ) ) {
			$labels[] = $label;
		}
	}

	return implode( ', ', $labels );
}

/**
 * Every choice (value + label) defined for one WPForms field, straight
 * from the form's own saved definition — same data source already used
 * by custom_truelysell_wpforms_map_field_values_to_labels() above.
 */
function custom_truelysell_get_wpforms_field_choices( $form_id, $field_id ) {
	if ( ! function_exists( 'wpforms' ) ) {
		return array();
	}

	$wpforms = wpforms();
	if ( ! isset( $wpforms->form ) ) {
		return array();
	}

	$form = $wpforms->form->get( absint( $form_id ) );
	if ( ! $form || empty( $form->post_content ) ) {
		return array();
	}

	$form_data = json_decode( $form->post_content, true );
	if ( empty( $form_data['fields'][ $field_id ]['choices'] ) || ! is_array( $form_data['fields'][ $field_id ]['choices'] ) ) {
		return array();
	}

	$choices = array();
	foreach ( $form_data['fields'][ $field_id ]['choices'] as $choice ) {
		$label = isset( $choice['label'] ) ? (string) $choice['label'] : '';
		$value = isset( $choice['value'] ) && '' !== $choice['value'] ? (string) $choice['value'] : $label;
		$choices[] = array( 'value' => $value, 'label' => $label );
	}

	return $choices;
}

/**
 * Which of this field's choice VALUES actually mean "registering as a
 * technician" — same keyword list custom_truelysell_registration_handler()
 * already compares the submitted value against, so this always agrees
 * with how registrations actually get classified server-side.
 */
function custom_truelysell_get_wpforms_technician_choice_values( $form_id, $field_id ) {
	$technician_keywords = array( 'technician', 'provider', 'owner', 'service provider' );
	$matches = array();

	foreach ( custom_truelysell_get_wpforms_field_choices( $form_id, $field_id ) as $choice ) {
		$value_normalized = strtolower( trim( $choice['value'] ) );
		$label_normalized  = strtolower( trim( $choice['label'] ) );

		if ( in_array( $value_normalized, $technician_keywords, true ) || in_array( $label_normalized, $technician_keywords, true ) ) {
			$matches[] = $choice['value'];
		}
	}

	return $matches;
}

/**
 * The 20% platform fee notice + required "I agree" checkbox for the
 * technician side of the registration form (WPForms form 8203 — see
 * custom_truelysell_registration_handler()). This form isn't a theme
 * template (WPForms renders it from its own saved definition, not any
 * file in this theme), so it can't be edited directly — the notice and
 * checkbox are added as plain HTML inside the existing <form> via JS,
 * which is enough for them to submit as normal $_POST fields alongside
 * WPForms' own fields.
 *
 * Field 1 is the same "register as a technician/customer" field
 * custom_truelysell_registration_handler() already reads — its actual
 * choice values (fetched from the form's own saved definition, not
 * guessed) tell us exactly which selection means "technician", so the
 * checkbox only appears/becomes required for that choice, never for a
 * customer registering on the same form.
 */
add_action( 'wp_footer', 'custom_truelysell_render_technician_fee_agreement_notice' );
function custom_truelysell_render_technician_fee_agreement_notice() {
	$technician_values = custom_truelysell_get_wpforms_technician_choice_values( 8203, 1 );
	?>
	<script type="text/javascript">
	document.addEventListener('DOMContentLoaded', function () {
		var form = document.getElementById('wpforms-form-8203');
		if (!form) {
			return;
		}

		var technicianValues = <?php echo wp_json_encode( array_map( 'strval', $technician_values ) ); ?>;
		var roleInputs = form.querySelectorAll('input[name="wpforms[fields][1]"], select[name="wpforms[fields][1]"]');
		if (!roleInputs.length) {
			// This site's form doesn't expose field 1 the way this was built
			// against — bail out rather than showing the notice to everyone
			// regardless of role.
			return;
		}

		var wrapper = document.createElement('div');
		wrapper.className = 'wpforms-field truelysell-fee-agreement-field';
		wrapper.style.display = 'none';
		wrapper.innerHTML =
			'<div class="alert alert-info" style="margin-bottom:10px;"><?php echo esc_js( __( 'A 20% platform fee is deducted from each completed service.', 'truelysell' ) ); ?></div>' +
			'<label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;">' +
				'<input type="checkbox" name="custom_truelysell_fee_agreement" value="1" id="custom-truelysell-fee-agreement-checkbox">' +
				'<span><?php echo esc_js( __( 'I understand and agree that a 20% platform fee will be deducted from each completed service.', 'truelysell' ) ); ?></span>' +
			'</label>';

		var submitButton = form.querySelector('button[type="submit"], input[type="submit"]');
		if (submitButton && submitButton.parentNode) {
			submitButton.parentNode.insertBefore(wrapper, submitButton);
		} else {
			form.appendChild(wrapper);
		}

		var checkbox = wrapper.querySelector('#custom-truelysell-fee-agreement-checkbox');

		function isTechnicianSelected() {
			for (var i = 0; i < roleInputs.length; i++) {
				var input = roleInputs[i];
				// A <select> always has exactly one current value; a radio/
				// checkbox only counts while actually checked.
				var isActive = (input.tagName === 'SELECT') || input.checked;
				if (isActive && technicianValues.indexOf(input.value) !== -1) {
					return true;
				}
			}
			return false;
		}

		function syncFeeAgreementVisibility() {
			var show = isTechnicianSelected();
			wrapper.style.display = show ? 'block' : 'none';
			if (checkbox) {
				checkbox.required = show;
				if (!show) {
					checkbox.checked = false;
				}
			}
		}

		roleInputs.forEach(function (input) {
			input.addEventListener('change', syncFeeAgreementVisibility);
		});
		syncFeeAgreementVisibility();

		// Defensive backstop alongside the checkbox's own native
		// `required` attribute — matches the pattern used elsewhere in
		// this theme for forms whose own JS submission handling can't be
		// fully relied on to respect standard HTML5 validation.
		form.addEventListener('submit', function (e) {
			if (checkbox && checkbox.required && !checkbox.checked) {
				e.preventDefault();
				e.stopImmediatePropagation();
				checkbox.focus();
			}
		}, true);
	});
	</script>
	<?php
}

/**
 * Return the current provider's selected services as a clean array.
 */
function custom_truelysell_get_provider_service_items( $user_id = 0 ) {
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}

	if ( ! $user_id ) {
		return array();
	}

	$services = get_user_meta( $user_id, 'provider_services', true );
	if ( empty( $services ) ) {
		return array();
	}

	if ( is_array( $services ) ) {
		$items = $services;
	} else {
		$services = custom_truelysell_wpforms_map_field_values_to_labels( 8203, 77, $services );
		$items = explode( ',', (string) $services );
	}

	$items = array_map( 'trim', $items );
	$items = array_filter( $items );

	return array_values( array_unique( $items ) );
}

/**
 * 1.a SHOW SELECTED PROVIDER SERVICES FROM WPFORMS REGISTRATION
 */
function custom_truelysell_provider_services_shortcode( $atts = array() ) {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return '<p>No services selected.</p>';
	}

	$items = custom_truelysell_get_provider_service_items( $user_id );
	if ( empty( $items ) ) {
		return '<p>No services selected.</p>';
	}

	$output = '<ul class="provider-services-list">';
	foreach ( $items as $service ) {
		$output .= '<li>' . esc_html( $service ) . '</li>';
	}
	$output .= '</ul>';

	return $output;
}
add_shortcode( 'provider_services', 'custom_truelysell_provider_services_shortcode' );

/**
 * Guarantee the provider owns a real, published listing for every service
 * category they picked at registration, so "My Services" always reflects
 * real, editable posts the provider can manage themselves instead of a
 * "coming soon" placeholder or another provider's catalog listing.
 */
function custom_truelysell_ensure_provider_service_listings( $user_id ) {
	/*
	 * This runs on every registration and every dashboard view, so a single
	 * bad category name or an unexpected WP_Query/term/post result must never
	 * be able to take down the whole page (registration/login previously
	 * white-screened when something in this loop threw). Fail safe: log and
	 * skip provisioning for this pageview rather than fatal.
	 */
	try {
		custom_truelysell_ensure_provider_service_listings_inner( $user_id );
	} catch ( \Throwable $e ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( sprintf( 'custom_truelysell_ensure_provider_service_listings failed for user_id=%d: %s', $user_id, $e->getMessage() ) );
		}
	}
}

function custom_truelysell_ensure_provider_service_listings_inner( $user_id ) {
	if ( ! $user_id || ! taxonomy_exists( 'listing_category' ) ) {
		return;
	}

	$service_names = custom_truelysell_get_provider_service_items( $user_id );
	if ( empty( $service_names ) ) {
		return;
	}

	$admin_ids = get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) );
	if ( empty( $admin_ids ) ) {
		return;
	}

	$provisioned = get_user_meta( $user_id, '_custom_truelysell_provisioned_services', true );
	if ( ! is_array( $provisioned ) ) {
		$provisioned = array();
	}

	/*
	 * A provider can remove an Admin-managed listing from their own "My
	 * Services" (see custom_truelysell_handle_remove_linked_service()) —
	 * that only unlinks it from THEIR account, the shared listing itself
	 * is untouched. Respect that choice here too, otherwise this function
	 * would just re-link the same listing again on the very next page
	 * load, undoing the removal immediately.
	 */
	$removed = get_user_meta( $user_id, '_custom_truelysell_removed_linked_services', true );
	if ( ! is_array( $removed ) ) {
		$removed = array();
	}

	$changed = false;

	foreach ( $service_names as $service_name ) {
		if ( isset( $provisioned[ $service_name ] ) ) {
			$cached_post = get_post( $provisioned[ $service_name ] );
			/*
			 * Trust the cache only while the post still exists, is still
			 * published, AND is still authored by an Administrator — i.e.
			 * it's still the real, fully-detailed catalog listing (correct
			 * price/image/gallery), not a stray empty post or one Admin
			 * has since trashed/replaced. This also self-heals any
			 * pre-existing technician-owned duplicate created before this
			 * fix: it fails this check and gets re-resolved below.
			 */
			if ( $cached_post && 'publish' === $cached_post->post_status && in_array( absint( $cached_post->post_author ), $admin_ids, true ) ) {
				continue;
			}
		}

		/*
		 * A provider selecting a category at registration is only ever
		 * associated with the real, Administrator-authored catalog listing
		 * for that category — which already has the correct price/image
		 * set by Admin — never a new post owned by the provider. This is
		 * what keeps the public catalog and wp-admin "Listings" count fixed
		 * at whatever Admin has actually created, and is why a provider's
		 * dashboard card for a registration-selected service now shows the
		 * real price/image instead of $0.00/a placeholder.
		 *
		 * If Admin hasn't created a listing for this category yet, do
		 * nothing — per the site's rule, only Admin creates catalog
		 * listings, from wp-admin.
		 */
		$existing = new WP_Query( array(
			'post_type'              => 'listing',
			'author__in'             => $admin_ids,
			'post_status'            => array( 'publish', 'pending', 'pending_payment', 'draft', 'expired' ),
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'tax_query'              => array(
				array(
					'taxonomy' => 'listing_category',
					'field'    => 'name',
					'terms'    => array( $service_name ),
				),
			),
		) );

		if ( ! empty( $existing->posts ) && ! in_array( absint( $existing->posts[0] ), $removed, true ) ) {
			$provisioned[ $service_name ] = absint( $existing->posts[0] );
			$changed = true;
			custom_truelysell_maybe_assign_technician_to_listing( absint( $existing->posts[0] ), $user_id );
		}
	}

	if ( $changed ) {
		update_user_meta( $user_id, '_custom_truelysell_provisioned_services', $provisioned );
	}
}

/**
 * When a listing gets linked to a provider's own account (via
 * registration category selection, or via the Add-Service duplicate fix
 * in custom_truelysell_prevent_duplicate_service_creation()), make that
 * provider its assigned technician too — but only if nobody else is
 * already assigned — so bookings on it are credited to them and they're
 * shown as the Service Provider on the public page, the same as any
 * listing Admin explicitly assigned them to by hand.
 */
function custom_truelysell_maybe_assign_technician_to_listing( $listing_id, $user_id ) {
	$current_assignee = absint( get_post_meta( $listing_id, '_assigned_technician_id', true ) );
	if ( ! $current_assignee ) {
		update_post_meta( $listing_id, '_assigned_technician_id', absint( $user_id ) );
	}

	custom_truelysell_add_linked_provider( $listing_id, $user_id );
}

/**
 * Reverse-index of every provider who has ever linked to a shared listing —
 * separate from _assigned_technician_id (which only ever holds the single
 * "first to link" provider used for booking-credit/public-page purposes).
 * Used to show the customer a location-based list of every provider who
 * actually offers this service, not just the one that happened to link
 * first.
 */
function custom_truelysell_add_linked_provider( $listing_id, $user_id ) {
	$user_id = absint( $user_id );
	if ( ! $user_id ) {
		return;
	}

	$linked = get_post_meta( $listing_id, '_linked_provider_ids', true );
	$linked = is_array( $linked ) ? $linked : array();

	if ( ! in_array( $user_id, $linked, true ) ) {
		$linked[] = $user_id;
		update_post_meta( $listing_id, '_linked_provider_ids', $linked );
	}
}

function custom_truelysell_remove_linked_provider( $listing_id, $user_id ) {
	$user_id = absint( $user_id );
	$linked  = get_post_meta( $listing_id, '_linked_provider_ids', true );
	if ( ! is_array( $linked ) || ! $user_id ) {
		return;
	}

	$linked = array_values( array_diff( $linked, array( $user_id ) ) );
	update_post_meta( $listing_id, '_linked_provider_ids', $linked );
}

/**
 * All restricted-provider user IDs actually offering a given listing —
 * reads the reverse-index above, falling back to _assigned_technician_id
 * alone for listings linked before that index existed.
 */
function custom_truelysell_get_listing_linked_provider_ids( $listing_id ) {
	$linked = get_post_meta( $listing_id, '_linked_provider_ids', true );
	$linked = is_array( $linked ) ? array_map( 'absint', $linked ) : array();

	$assigned = absint( get_post_meta( $listing_id, '_assigned_technician_id', true ) );
	if ( $assigned && ! in_array( $assigned, $linked, true ) ) {
		$linked[] = $assigned;
	}

	return array_values( array_unique( array_filter( $linked ) ) );
}

/**
 * When a provider's WordPress account is deleted entirely, nothing
 * otherwise removes their ID from _linked_provider_ids/
 * _assigned_technician_id on the listings they were linked to — those
 * stale IDs then inflate "N providers available" counts, show up (or
 * silently vanish, depending on the reader) in provider lists, etc.
 * Clean them out properly the moment the account is actually deleted.
 */
add_action( 'delete_user', 'custom_truelysell_cleanup_linked_provider_on_user_delete' );
function custom_truelysell_cleanup_linked_provider_on_user_delete( $user_id ) {
	$user_id = absint( $user_id );
	if ( ! $user_id ) {
		return;
	}

	$listing_ids = get_posts( array(
		'post_type'      => 'listing',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	) );

	foreach ( $listing_ids as $listing_id ) {
		$linked = get_post_meta( $listing_id, '_linked_provider_ids', true );
		if ( is_array( $linked ) && in_array( $user_id, array_map( 'absint', $linked ), true ) ) {
			custom_truelysell_remove_linked_provider( $listing_id, $user_id );
		}

		if ( $user_id === absint( get_post_meta( $listing_id, '_assigned_technician_id', true ) ) ) {
			delete_post_meta( $listing_id, '_assigned_technician_id' );
		}
	}
}

/**
 * The plugin computes who a booking belongs to purely from the listing's
 * post_author at the moment of booking
 * (Truelysell_Core_Bookings_Calendar::truelysell_core_booking(), which
 * feeds `get_post_field('post_author', $listing_id)` straight into every
 * insert_booking() call as owner_id) — it has no idea our
 * _assigned_technician_id meta exists, so a booking for a shared Admin
 * listing always credits Admin, even once a specific provider is clearly
 * the one handling it. There's no hook anywhere in that code path to
 * intercept this at insert time, so this self-heals it instead: whenever
 * an assigned provider visits any page, their assigned listings' bookings
 * get corrected in the database to credit them instead of Admin — after
 * which truelysell_get_provider_bookings() and everything built on it
 * (their own "Booking List", dashboard, etc.) sees the booking as theirs.
 */
add_action( 'template_redirect', 'custom_truelysell_reassign_bookings_to_technician' );
function custom_truelysell_reassign_bookings_to_technician() {
	$user_id = get_current_user_id();
	if ( ! $user_id || ! custom_truelysell_is_restricted_provider( $user_id ) ) {
		return;
	}

	$assigned_listing_ids = get_posts( array(
		'post_type'              => 'listing',
		'post_status'            => 'any',
		'posts_per_page'         => -1,
		'fields'                 => 'ids',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'meta_key'               => '_assigned_technician_id',
		'meta_value'             => $user_id,
	) );

	if ( empty( $assigned_listing_ids ) ) {
		return;
	}

	$admin_ids = get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) );
	if ( empty( $admin_ids ) ) {
		return;
	}

	global $wpdb;
	$bookings_table  = $wpdb->prefix . 'bookings_calendar';
	$admin_ids_sql   = implode( ',', array_map( 'absint', $admin_ids ) );

	foreach ( $assigned_listing_ids as $listing_id ) {
		/*
		 * Only correct a booking that's still credited to Admin (the
		 * plugin's default, since it has no idea an assigned technician
		 * exists) — never overwrite one already credited to a DIFFERENT
		 * technician, which represents a real prior assignment (e.g. Admin
		 * later reassigned this listing from one technician to another).
		 * Rewriting that would silently erase which technician actually
		 * handled a past booking.
		 */
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$bookings_table} SET owner_id = %d WHERE listing_id = %d AND owner_id IN ({$admin_ids_sql})",
			$user_id,
			$listing_id
		) );
	}
}

/**
 * The single listing page's "Service Provider" sidebar box always showed
 * ONE specific technician's name/photo/contact info — but which
 * technician actually gets assigned only gets decided at booking time, by
 * distance from whatever address the customer enters (see
 * custom_truelysell_find_nearest_eligible_provider()). Showing one fixed
 * person here was misleading (implies "this is your technician" when it
 * might not be) and was also the source of a confusing duplicate-context
 * issue when a customer arrived via that same technician's own profile
 * page. Replaced entirely with a neutral "How It Works" explainer that
 * doesn't reference any specific person — accurate regardless of who
 * ends up assigned, and consistent no matter how the customer got here.
 */
add_action( 'wp_footer', 'custom_truelysell_replace_provider_box_with_how_it_works' );
function custom_truelysell_replace_provider_box_with_how_it_works() {
	if ( ! is_singular( 'listing' ) ) {
		return;
	}

	$listing_id   = get_the_ID();
	$provider_ids = array_values( array_filter( custom_truelysell_get_listing_linked_provider_ids( $listing_id ), 'custom_truelysell_is_restricted_provider' ) );

	if ( empty( $provider_ids ) ) {
		/*
		 * Genuinely nobody to fulfill this service — say so plainly and
		 * hide the booking button, rather than show "How It Works" copy
		 * that would wrongly imply booking is possible.
		 */
		?>
		<script type="text/javascript">
		document.addEventListener('DOMContentLoaded', function () {
			var box = document.querySelector('.provider-info');
			var notice = '<div class="alert alert-warning mb-0">' +
				'<i class="ti ti-alert-triangle me-1"></i>' +
				<?php echo wp_json_encode( esc_html__( 'No provider is currently available for this service. Please check back later or contact us directly.', 'truelysell' ) ); ?> +
				'</div>';
			if (box) {
				box.innerHTML = notice;
			}
			document.querySelectorAll('[data-bs-target="#user-account"]').forEach(function (btn) {
				var text = (btn.textContent || '').trim().toLowerCase();
				if (text.indexOf('book') !== -1) {
					btn.style.display = 'none';
				}
			});
		});
		</script>
		<?php
		return;
	}
	?>
	<script type="text/javascript">
	document.addEventListener('DOMContentLoaded', function () {
		var providerInfo = document.querySelector('.provider-info');
		var card         = providerInfo ? providerInfo.closest('.card') : null;
		var cardBody     = card ? card.querySelector('.card-body') : null;
		if (!cardBody) {
			return;
		}

		cardBody.innerHTML =
			'<h4 class="mb-3"><?php echo esc_js( __( 'How It Works', 'truelysell' ) ); ?></h4>' +
			'<div class="d-flex align-items-start mb-3">' +
				'<span class="avatar avatar-sm bg-primary-transparent text-primary rounded-circle me-2 flex-shrink-0 fw-bold d-flex align-items-center justify-content-center" style="line-height:1;">1</span>' +
				'<div>' +
					'<h6 class="fs-14 fw-medium mb-1"><?php echo esc_js( __( 'Get Matched', 'truelysell' ) ); ?></h6>' +
					'<p class="fs-13 text-muted mb-0"><?php echo esc_js( __( 'We connect you with the nearest available technician in your area.', 'truelysell' ) ); ?></p>' +
				'</div>' +
			'</div>' +
			'<div class="d-flex align-items-start mb-3">' +
				'<span class="avatar avatar-sm bg-primary-transparent text-primary rounded-circle me-2 flex-shrink-0 fw-bold d-flex align-items-center justify-content-center" style="line-height:1;">2</span>' +
				'<div>' +
					'<h6 class="fs-14 fw-medium mb-1"><?php echo esc_js( __( 'Book', 'truelysell' ) ); ?></h6>' +
					'<p class="fs-13 text-muted mb-0"><?php echo esc_js( __( 'Fill in your details and preferred date/time.', 'truelysell' ) ); ?></p>' +
				'</div>' +
			'</div>' +
			'<div class="d-flex align-items-start mb-3">' +
				'<span class="avatar avatar-sm bg-primary-transparent text-primary rounded-circle me-2 flex-shrink-0 fw-bold d-flex align-items-center justify-content-center" style="line-height:1;">3</span>' +
				'<div>' +
					'<h6 class="fs-14 fw-medium mb-1"><?php echo esc_js( __( 'Pay Deposit', 'truelysell' ) ); ?></h6>' +
					'<p class="fs-13 text-muted mb-0"><?php echo esc_js( __( 'Secure your slot with a 20% deposit.', 'truelysell' ) ); ?></p>' +
				'</div>' +
			'</div>' +
			'<div class="d-flex align-items-start mb-3">' +
				'<span class="avatar avatar-sm bg-primary-transparent text-primary rounded-circle me-2 flex-shrink-0 fw-bold d-flex align-items-center justify-content-center" style="line-height:1;">4</span>' +
				'<div>' +
					'<h6 class="fs-14 fw-medium mb-1"><?php echo esc_js( __( 'Confirm &amp; Complete', 'truelysell' ) ); ?></h6>' +
					'<p class="fs-13 text-muted mb-0"><?php echo esc_js( __( 'Your technician confirms the time and completes the job.', 'truelysell' ) ); ?></p>' +
				'</div>' +
			'</div>' +
			'<div class="d-flex flex-wrap gap-2 pt-3 border-top">' +
				'<span class="badge bg-light text-dark border fw-medium"><i class="ti ti-shield-check me-1"></i><?php echo esc_js( __( 'Licensed &amp; Insured', 'truelysell' ) ); ?></span>' +
				'<span class="badge bg-light text-dark border fw-medium"><i class="ti ti-user-check me-1"></i><?php echo esc_js( __( 'Background-Checked', 'truelysell' ) ); ?></span>' +
			'</div>';
	});
	</script>
	<?php
}

/**
 * The plugin's native "Chat Now" / "Enquiry Us" buttons on a single
 * listing page (truelysell-core/templates/single-listing.php — plugin
 * file, not overridden here) have two bugs, same root cause as everything
 * else on this site: the plugin assumes the listing's post_author IS the
 * provider, and that a signed-up customer's role is literally "guest".
 * Neither holds here — every listing is admin-authored (real technicians
 * are LINKED, see custom_truelysell_get_listing_linked_provider_ids()),
 * and real sign-ups get role "customer", not "guest". Concretely:
 * 1. The chat/enquiry recipient is hardcoded to $post->post_author
 *    (Admin) — so any message a customer manages to send goes to Admin,
 *    never the actual assigned technician.
 * 2. The "Chat Now" button itself only renders for role "guest"
 *    (`in_array($role, array('guest'))`) — a real, logged-in "customer"
 *    account sees no button at all.
 * Patched via JS injection rather than a full single-listing.php child-
 * theme override (that template is large; duplicating all of it to change
 * a few lines would make it much harder to pick up future plugin fixes).
 */
add_action( 'wp_footer', 'custom_truelysell_fix_chat_now_recipient_and_visibility' );
function custom_truelysell_fix_chat_now_recipient_and_visibility() {
	if ( ! is_singular( 'listing' ) ) {
		return;
	}

	$listing_id = get_the_ID();

	// Same "who's the real technician" resolution used elsewhere: prefer
	// the explicitly assigned technician, else the first eligible linked
	// provider, else fall back to post_author (Admin) as a last resort —
	// never worse than the plugin's own original (always-Admin) behavior.
	$recipient_id = absint( get_post_meta( $listing_id, '_assigned_technician_id', true ) );
	if ( ! $recipient_id ) {
		foreach ( custom_truelysell_get_listing_linked_provider_ids( $listing_id ) as $linked_id ) {
			if ( custom_truelysell_is_restricted_provider( $linked_id ) ) {
				$recipient_id = $linked_id;
				break;
			}
		}
	}
	if ( ! $recipient_id ) {
		$recipient_id = absint( get_post_field( 'post_author', $listing_id ) );
	}

	$current_user_id    = get_current_user_id();
	$show_inject_button = $current_user_id && ! custom_truelysell_is_restricted_provider( $current_user_id );
	?>
	<script>
	document.addEventListener('DOMContentLoaded', function () {
		var recipientId = <?php echo wp_json_encode( (string) $recipient_id ); ?>;
		var listingId    = <?php echo wp_json_encode( (string) $listing_id ); ?>;

		// Fix #1: repoint any chat/enquiry trigger that DID render at the
		// real technician instead of post_author (Admin).
		document.querySelectorAll('.booking-message').forEach(function (el) {
			el.setAttribute('data-recipient', recipientId);
		});
		var enquiryProviderField = document.querySelector('#contact-provider-form input[name="provider_id"]');
		if (enquiryProviderField) enquiryProviderField.value = recipientId;

		<?php if ( $show_inject_button ) : ?>
		// Fix #2: the plugin's own template rendered NO button at all for
		// this (real, logged-in, non-technician) account — inject one,
		// matching the plugin's own markup/classes, next to the existing
		// "Book Service" trigger so it doesn't depend on guessing at
		// unrelated page structure.
		if (!document.querySelector('.booking-message')) {
			var bookBtn = null;
			document.querySelectorAll('a, button').forEach(function (el) {
				if (bookBtn) return;
				var text = (el.textContent || '').trim().toLowerCase();
				if (text.indexOf('book') !== -1 && el.closest('.col-sm-12, .row')) {
					bookBtn = el;
				}
			});
			if (bookBtn) {
				var chatBtn = document.createElement('a');
				chatBtn.setAttribute('data-bs-toggle', 'modal');
				chatBtn.setAttribute('data-bs-target', '#booking_messages');
				chatBtn.setAttribute('data-recipient', recipientId);
				chatBtn.setAttribute('data-booking_id', 'booking_' + listingId);
				chatBtn.className = 'btn btn-light btn-lg fs-14 px-1 w-100 booking-message rate-review popup-with-zoom-anim mt-2';
				chatBtn.innerHTML = '<i class="ti ti-user me-2"></i><?php echo esc_js( __( 'Chat Now', 'truelysell' ) ); ?>';
				bookBtn.insertAdjacentElement('afterend', chatBtn);
			}
		}
		<?php endif; ?>
	});
	</script>
	<?php
}

/**
 * The "Contact Provider" form on a technician's own profile page
 * (#contact-provider-form, truelysell-child/template-parts/provider-
 * details.php — this is the actual "customer selects a technician and
 * sends them a message" entry point) posts to itself (action="",
 * method="POST") with no PHP anywhere in this theme processing
 * name="submit_contact_form" — and whatever the plugin's own frontend.js
 * does with it (if it intercepts it at all) does not write into the same
 * conversation table the technician's real Inbox reads from. That's why
 * a message "sent" from a technician's profile page never shows up in
 * their Inbox: it's a completely separate, disconnected form from the
 * "Chat Now" widget's #booking_messages modal fixed above. Submit it
 * instead through the plugin's own confirmed-working truelysell_send_message
 * AJAX action (recipient + message) — the same one already used by the
 * "Chat Now" fixes elsewhere on this site — so a message sent from this
 * form lands in the exact same inbox conversation as any other chat
 * message. Only handles the logged-in case (this form's the customer↔
 * technician messaging system, which requires a real account on both
 * ends); a logged-out visitor submitting it is a pre-existing no-op,
 * unchanged here.
 */
add_action( 'wp_footer', 'custom_truelysell_fix_contact_provider_form' );
function custom_truelysell_fix_contact_provider_form() {
	if ( ! is_user_logged_in() ) {
		return;
	}
	?>
	<script type="text/javascript">
	document.addEventListener('DOMContentLoaded', function () {
		var form = document.getElementById('contact-provider-form');
		if ( ! form ) {
			return;
		}

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			e.stopImmediatePropagation();

			var providerField = form.querySelector('input[name="provider_id"]');
			var messageField  = form.querySelector('textarea[name="comment"]');
			var button        = form.querySelector('button[type="submit"]');
			var recipient     = providerField ? providerField.value : '';
			var message       = messageField ? messageField.value : '';

			if ( ! recipient || ! message ) {
				return;
			}

			var formData = new FormData();
			formData.append('action', 'truelysell_send_message');
			formData.append('recipient', recipient);
			formData.append('referral', '');
			formData.append('message', message);

			if ( button ) {
				button.setAttribute('disabled', 'disabled');
			}

			fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', body: formData } )
				.then(function (r) { return r.json(); })
				.then(function (data) {
					if ( button ) {
						button.removeAttribute('disabled');
					}
					if ( data && data.type === 'success' ) {
						if ( messageField ) {
							messageField.value = '';
						}
						var successModalEl = document.getElementById('successModal');
						if ( successModalEl && window.bootstrap && window.bootstrap.Modal ) {
							new window.bootstrap.Modal( successModalEl ).show();
						} else if ( successModalEl ) {
							successModalEl.classList.add('show');
							successModalEl.style.display = 'block';
						}
					} else {
						window.alert( ( data && data.message ) ? data.message : <?php echo wp_json_encode( __( 'Could not send your message. Please try again.', 'truelysell' ) ); ?> );
					}
				})
				.catch(function () {
					if ( button ) {
						button.removeAttribute('disabled');
					}
					window.alert( <?php echo wp_json_encode( __( 'Network error. Please try again.', 'truelysell' ) ); ?> );
				});
		}, true );
	});
	</script>
	<?php
}

/**
 * Lets a provider remove an Admin-managed listing from their own "My
 * Services" — e.g. they no longer want to offer that service — WITHOUT
 * touching the shared listing post itself, which stays exactly as-is for
 * Admin and for any other provider linked to it. Only their own
 * provisioned-services mapping is edited.
 */
add_action( 'template_redirect', 'custom_truelysell_handle_remove_linked_service' );
function custom_truelysell_handle_remove_linked_service() {
	if ( empty( $_GET['custom_truelysell_action'] ) || 'remove_linked_service' !== $_GET['custom_truelysell_action'] ) {
		return;
	}

	$user_id = get_current_user_id();
	if ( ! $user_id || ! custom_truelysell_is_restricted_provider( $user_id ) ) {
		return;
	}

	$listing_id = isset( $_GET['listing_id'] ) ? absint( $_GET['listing_id'] ) : 0;
	if ( ! $listing_id || empty( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'custom_truelysell_remove_linked_service_' . $listing_id ) ) {
		return;
	}

	/*
	 * Only ever unlink a listing this user doesn't actually author — their
	 * own listings already have a real Delete action that trashes the
	 * post itself, which is correct for something they own.
	 */
	if ( absint( get_post_field( 'post_author', $listing_id ) ) === absint( $user_id ) ) {
		return;
	}

	$provisioned = get_user_meta( $user_id, '_custom_truelysell_provisioned_services', true );
	if ( is_array( $provisioned ) ) {
		foreach ( $provisioned as $service_name => $mapped_listing_id ) {
			if ( absint( $mapped_listing_id ) === $listing_id ) {
				unset( $provisioned[ $service_name ] );
			}
		}
		update_user_meta( $user_id, '_custom_truelysell_provisioned_services', $provisioned );
	}

	$removed = get_user_meta( $user_id, '_custom_truelysell_removed_linked_services', true );
	if ( ! is_array( $removed ) ) {
		$removed = array();
	}
	if ( ! in_array( $listing_id, $removed, true ) ) {
		$removed[] = $listing_id;
		update_user_meta( $user_id, '_custom_truelysell_removed_linked_services', $removed );
	}

	custom_truelysell_remove_linked_provider( $listing_id, $user_id );

	wp_safe_redirect( remove_query_arg( array( 'custom_truelysell_action', 'listing_id', '_wpnonce' ) ) );
	exit;
}

/**
 * "Add Service" always creates a brand new post, even when the provider
 * picks a Service Title that already matches an existing, real,
 * Admin-authored catalog listing — this has repeatedly created unwanted
 * duplicates ("Economy Package: TV Mounting", "Quad TV Mount", each
 * getting a "-2" slug). Catch this the moment the new post is created: if
 * its title exactly matches an existing, different, published,
 * Admin-authored listing, trash the duplicate immediately and link this
 * provider to the real one instead — exactly like registration-based
 * category selection already does, so "Add Service" and "select at
 * registration" both end up at the same real listing instead of forking
 * into separate copies.
 */
add_action( 'save_post_listing', 'custom_truelysell_prevent_duplicate_service_creation', 20, 3 );
function custom_truelysell_prevent_duplicate_service_creation( $post_id, $post, $update ) {
	static $handling = false;

	if ( $handling || $update ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	$author_id = absint( $post->post_author );
	if ( ! $author_id || ! custom_truelysell_is_restricted_provider( $author_id ) ) {
		return;
	}

	$admin_ids = get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) );
	if ( empty( $admin_ids ) ) {
		return;
	}

	$existing = get_posts( array(
		'post_type'              => 'listing',
		'post_status'            => 'publish',
		'author__in'             => $admin_ids,
		'posts_per_page'         => 1,
		'fields'                 => 'ids',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
		'post__not_in'           => array( $post_id ),
		'title'                  => $post->post_title,
	) );

	if ( empty( $existing ) ) {
		return;
	}

	$real_listing_id = absint( $existing[0] );

	$handling = true;
	wp_trash_post( $post_id );
	$handling = false;

	$provisioned = get_user_meta( $author_id, '_custom_truelysell_provisioned_services', true );
	if ( ! is_array( $provisioned ) ) {
		$provisioned = array();
	}
	$provisioned[ $post->post_title ] = $real_listing_id;
	update_user_meta( $author_id, '_custom_truelysell_provisioned_services', $provisioned );
	custom_truelysell_maybe_assign_technician_to_listing( $real_listing_id, $author_id );
}

/**
 * Real, current listing IDs this provider actually owns right now — the
 * single source of truth for "how many services does this provider have",
 * used by their own dashboard AND by admin-side displays (Users list column,
 * profile screen), so every one of them always agrees instead of admin
 * seeing a stale count frozen at registration time.
 */
function custom_truelysell_get_provider_real_listing_ids( $user_id ) {
	$query = new WP_Query( array(
		'post_type'              => 'listing',
		'author'                 => $user_id,
		'post_status'            => array( 'publish' ),
		'posts_per_page'         => -1,
		'fields'                 => 'ids',
		'orderby'                => 'ID',
		'order'                  => 'ASC',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
	) );

	$listing_ids = array_map( 'absint', $query->posts );

	/*
	 * Registration-selected categories are associated with the real,
	 * Admin-authored catalog listing for that category (see
	 * custom_truelysell_ensure_provider_service_listings_inner()), not a
	 * new post owned by this provider — so pull those in too, otherwise a
	 * provider who only selected categories at registration (and never
	 * used "Add Service" themselves) would show 0 services here.
	 */
	$provisioned = get_user_meta( $user_id, '_custom_truelysell_provisioned_services', true );
	if ( is_array( $provisioned ) ) {
		foreach ( $provisioned as $provisioned_listing_id ) {
			$provisioned_listing_id = absint( $provisioned_listing_id );
			/*
			 * get_post() returns trashed/deleted posts too, so without this
			 * status check, a catalog listing Admin later trashed would
			 * keep showing here forever via the stale cached mapping.
			 */
			if ( $provisioned_listing_id && ! in_array( $provisioned_listing_id, $listing_ids, true ) && 'publish' === get_post_status( $provisioned_listing_id ) ) {
				$listing_ids[] = $provisioned_listing_id;
			}
		}
	}

	/*
	 * A leftover bug (fixed earlier) used to create empty placeholder
	 * listings titled after a raw internal category ID (e.g. "8467") when
	 * it couldn't resolve a real category name. That's never a real
	 * service — filter it out here so any such stray post, wherever it
	 * came from, never shows as if it were one of this provider's actual
	 * services.
	 */
	return array_values( array_filter( $listing_ids, function( $listing_id ) {
		return ! preg_match( '/^\d+$/', get_the_title( $listing_id ) );
	} ) );
}

/**
 * The plugin's own native "My Listings" table (the [truelysell_my_listings]
 * shortcode, placed in the "My Services" page content by the page builder)
 * runs its own independent query with no way to filter it down to only
 * Admin-managed listings without touching that query directly — which we
 * already tried once and it broke the whole section, since we have no way
 * to test PHP changes against the live site before uploading. Short-circuit
 * the shortcode itself instead: this runs before the plugin's callback (and
 * its query) ever executes, so nothing in the plugin is touched at all —
 * just skip it entirely for restricted providers, since our own "My
 * Services" card above it already shows the correct, complete list.
 */
add_filter( 'pre_do_shortcode_tag', 'custom_truelysell_suppress_native_my_listings_shortcode', 10, 2 );
function custom_truelysell_suppress_native_my_listings_shortcode( $output, $tag ) {
	if ( 'truelysell_my_listings' !== $tag ) {
		return $output;
	}

	if ( ! custom_truelysell_is_restricted_provider( get_current_user_id() ) ) {
		return $output;
	}

	return '';
}

function custom_truelysell_format_listing_price( $listing_id ) {
	$price = get_post_meta( $listing_id, '_normal_price', true );
	if ( $price === '' ) {
		$price = get_post_meta( $listing_id, '_price', true );
	}

	$price = (float) $price;

	$currency_symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$';
	if ( function_exists( 'truelysell_fl_framework_getoptions' ) && class_exists( 'Truelysell_Core_Listing' ) ) {
		$currency_abbr = truelysell_fl_framework_getoptions( 'currency' );
		if ( ! empty( $currency_abbr ) ) {
			$currency_symbol = Truelysell_Core_Listing::get_currency_symbol( $currency_abbr );
		}
	}

	$currency_position = function_exists( 'truelysell_fl_framework_getoptions' ) ? truelysell_fl_framework_getoptions( 'currency_postion' ) : '';
	$formatted_price   = number_format( $price, 2 );

	return $currency_position === 'after' ? $formatted_price . $currency_symbol : $currency_symbol . $formatted_price;
}

/**
 * The struck-through "was" price shown next to the real price everywhere
 * else on the site (public catalog cards, the provider profile page) when
 * a discount is set — the "My Services" card was missing this, showing
 * only a plain price with no indication of the "was" price like the rest
 * of the site.
 */
function custom_truelysell_get_listing_was_price_html( $listing_id ) {
	$initial_price = (float) get_post_meta( $listing_id, '_initial_price', true );
	if ( ! $initial_price ) {
		return '';
	}

	$currency_symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$';
	if ( function_exists( 'truelysell_fl_framework_getoptions' ) && class_exists( 'Truelysell_Core_Listing' ) ) {
		$currency_abbr = truelysell_fl_framework_getoptions( 'currency' );
		if ( ! empty( $currency_abbr ) ) {
			$currency_symbol = Truelysell_Core_Listing::get_currency_symbol( $currency_abbr );
		}
	}

	$currency_position = function_exists( 'truelysell_fl_framework_getoptions' ) ? truelysell_fl_framework_getoptions( 'currency_postion' ) : '';
	$formatted_price    = number_format( $initial_price, 2 );
	$was_price          = $currency_position === 'after' ? $formatted_price . $currency_symbol : $currency_symbol . $formatted_price;

	return '<span class="fs-13 text-gray"><del>' . esc_html( $was_price ) . '</del></span>';
}

function custom_truelysell_get_listing_location_text( $listing_id ) {
	$location_meta_keys = array( '_address', 'address', '_friendly_address', 'friendly_address', 'geolocation_formatted_address' );

	foreach ( $location_meta_keys as $meta_key ) {
		$location = get_post_meta( $listing_id, $meta_key, true );
		if ( ! empty( $location ) ) {
			return $location;
		}
	}

	if ( function_exists( 'get_the_listing_address' ) ) {
		global $post;

		$old_post = $post;
		$post     = get_post( $listing_id );
		if ( ! $post ) {
			$post = $old_post;
			return '';
		}
		setup_postdata( $post );
		$location = get_the_listing_address();
		wp_reset_postdata();
		$post = $old_post;

		if ( ! empty( $location ) ) {
			return $location;
		}
	}

	return '';
}

function custom_truelysell_get_listing_primary_category_name( $listing_id ) {
	$terms = get_the_terms( $listing_id, 'listing_category' );
	if ( empty( $terms ) || is_wp_error( $terms ) ) {
		return '';
	}

	return $terms[0]->name;
}

function custom_truelysell_get_listing_rating_text( $listing_id ) {
	if ( function_exists( 'truelysell_get_average_rating' ) ) {
		$rating = truelysell_get_average_rating( $listing_id );
		if ( $rating !== '' && $rating !== null ) {
			return number_format( (float) $rating, 1 );
		}
	}

	$rating = get_post_meta( $listing_id, 'truelysell-avg-rating', true );
	if ( $rating === '' ) {
		$rating = get_post_meta( $listing_id, '_average_rating', true );
	}

	return number_format( (float) $rating, 1 );
}

function custom_truelysell_render_provider_services_card( $user_id = 0 ) {
	/*
	 * Called directly from the Dashboard/My Services templates with nothing
	 * around it to catch a failure — an exception in here previously took
	 * down the whole page (white screen right after login). Fail safe:
	 * log and render nothing rather than fatal.
	 */
	try {
		custom_truelysell_render_provider_services_card_inner( $user_id );
	} catch ( \Throwable $e ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( sprintf( 'custom_truelysell_render_provider_services_card failed: %s', $e->getMessage() ) );
		}
	}
}

function custom_truelysell_render_provider_services_card_inner( $user_id = 0 ) {
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}

	if ( ! $user_id || ! custom_truelysell_is_restricted_provider( $user_id ) ) {
		return;
	}

	/*
	 * Render on BOTH the Dashboard page and the "My Services" (listings) page so the
	 * two pages always agree on the same service count/list instead of showing
	 * different numbers.
	 */
	$dashboard_page = function_exists( 'truelysell_fl_framework_getoptions' ) ? truelysell_fl_framework_getoptions( 'dashboard_page' ) : 0;
	$listings_page  = function_exists( 'truelysell_fl_framework_getoptions' ) ? truelysell_fl_framework_getoptions( 'listings_page' ) : 0;
	$current_page   = get_queried_object_id();
	$is_dashboard   = $dashboard_page && absint( $dashboard_page ) === absint( $current_page );
	$is_listings    = $listings_page && absint( $listings_page ) === absint( $current_page );

	if ( ( $dashboard_page || $listings_page ) && ! $is_dashboard && ! $is_listings ) {
		return;
	}

	custom_truelysell_ensure_provider_service_listings( $user_id );

	$listing_ids = custom_truelysell_get_provider_real_listing_ids( $user_id );

	if ( $is_listings ) {
		$GLOBALS['custom_truelysell_listings_page_has_matched_services'] = ! empty( $listing_ids );
	}

	$listings_page_url = truelysell_fl_framework_getoptions( 'listings_page' ) ? get_permalink( truelysell_fl_framework_getoptions( 'listings_page' ) ) : '';
	$submit_page_url   = truelysell_fl_framework_getoptions( 'submit_page' ) ? get_permalink( truelysell_fl_framework_getoptions( 'submit_page' ) ) : '';
	?>
	<div class="provider-dashboard-services mb-4">
		<div class="d-flex align-items-center justify-content-between flex-wrap row-gap-3 mb-3">
			<h5 class="mb-0"><?php esc_html_e( 'My Services', 'truelysell' ); ?></h5>
			<span class="badge bg-light text-dark"><?php echo esc_html( count( $listing_ids ) ); ?></span>
		</div>

		<?php if ( ! empty( $listing_ids ) ) : ?>
			<div class="row g-4">
				<?php foreach ( $listing_ids as $listing_id ) : ?>
					<?php
					$category_name = custom_truelysell_get_listing_primary_category_name( $listing_id );
					$location      = custom_truelysell_get_listing_location_text( $listing_id );
					$rating        = custom_truelysell_get_listing_rating_text( $listing_id );
					/*
					 * A registration-selected service points at the shared,
					 * Admin-owned catalog listing (see
					 * custom_truelysell_get_provider_real_listing_ids()).
					 * Never offer Edit/Delete for one of those here: the
					 * plugin's own submit form reassigns post_author to
					 * whoever saves the edit, which would silently steal
					 * the real catalog listing away from Admin. Only a
					 * listing this provider genuinely authored themselves
					 * (via "Add Service") gets those actions.
					 */
					$is_own_listing = absint( get_post_field( 'post_author', $listing_id ) ) === absint( $user_id );
					$edit_url       = ( $is_own_listing && $submit_page_url ) ? wp_nonce_url( add_query_arg( array( 'action' => 'edit', 'listing_id' => $listing_id ), $submit_page_url ), 'truelysell_core_my_listings_actions' ) : '';
					$delete_url     = $is_own_listing ? wp_nonce_url( add_query_arg( array( 'action' => 'delete', 'listing_id' => $listing_id ), $listings_page_url ? $listings_page_url : false ), 'truelysell_core_my_listings_actions' ) : '';
					?>
					<div class="col-xl-3 col-md-6">
						<div class="card p-0 provider-service-card h-100">
							<div class="card-body p-0">
								<div class="img-sec w-100">
									<a href="<?php echo esc_url( get_permalink( $listing_id ) ); ?>">
										<?php if ( has_post_thumbnail( $listing_id ) ) : ?>
											<?php echo get_the_post_thumbnail( $listing_id, 'truelysell-listing-grid-small', array( 'class' => 'img-fluid rounded-top w-100' ) ); ?>
										<?php else :
											/*
											 * _gallery is a CMB2 file_list field: array( attachment_id => url ).
											 * Resolve by attachment ID via wp_get_attachment_image_src() — the
											 * same reliable approach the plugin's own gallery template uses —
											 * instead of trusting the stored URL value directly, since that
											 * silently failed for images added from the wp-admin Gallery field.
											 */
											$gallery_images     = maybe_unserialize( get_post_meta( $listing_id, '_gallery', true ) );
											$fallback_image_url = '';
											if ( ! empty( $gallery_images ) && is_array( $gallery_images ) ) {
												$first_attachment_id = array_key_first( $gallery_images );
												$attachment_src      = $first_attachment_id ? wp_get_attachment_image_src( $first_attachment_id, 'truelysell-listing-grid-small' ) : false;
												if ( $attachment_src ) {
													$fallback_image_url = $attachment_src[0];
												}
											}
											?>
											<?php if ( $fallback_image_url ) : ?>
												<img src="<?php echo esc_url( $fallback_image_url ); ?>" class="img-fluid rounded-top w-100">
											<?php else : ?>
												<span class="provider-service-card-placeholder"><?php echo esc_html( substr( get_the_title( $listing_id ), 0, 1 ) ); ?></span>
											<?php endif; ?>
										<?php endif; ?>
									</a>
									<div class="image-tag d-flex justify-content-between align-items-center">
										<?php if ( $category_name ) : ?>
											<span class="trend-tag"><?php echo esc_html( $category_name ); ?></span>
										<?php endif; ?>
										<span class="trend-tag-2 d-flex justify-content-center align-items-center rating text-gray">
											<i class="fa fa-star filled me-1"></i>
											<?php echo esc_html( $rating ); ?>
										</span>
									</div>
								</div>
								<div class="p-3">
									<h6 class="provider-service-title mb-2 text-truncate">
										<a href="<?php echo esc_url( get_permalink( $listing_id ) ); ?>"><?php echo esc_html( get_the_title( $listing_id ) ); ?></a>
									</h6>
									<p class="mb-1 fs-12 text-muted">Listing ID: <?php echo esc_html( $listing_id ); ?></p>
									<div class="d-flex justify-content-between align-items-center gap-3 mb-0">
										<p class="provider-service-location fs-12 mb-0">
											<i class="ti ti-map-pin me-2"></i><?php echo $location ? esc_html( $location ) : esc_html__( 'Location not added', 'truelysell' ); ?>
										</p>
										<h6 class="provider-service-price mb-0"><?php echo esc_html( custom_truelysell_format_listing_price( $listing_id ) ); ?> <?php echo custom_truelysell_get_listing_was_price_html( $listing_id ); ?></h6>
										<span class="fs-12 text-muted d-block text-end"><?php echo esc_html( sprintf( __( 'TVs up to %d"', 'truelysell' ), TRUELYSELL_CHILD_OVERSIZE_TV_THRESHOLD_INCHES ) ); ?></span>
									</div>
									<?php if ( $is_own_listing ) : ?>
										<div class="d-flex justify-content-between align-items-center gap-2 mt-3">
											<?php if ( $edit_url ) : ?>
												<a href="<?php echo esc_url( $edit_url ); ?>" class="btn btn-outline-primary btn-sm w-100 truelysell_core-dashboard-action-edit"><i class="ti ti-edit me-1"></i><?php esc_html_e( 'Edit', 'truelysell' ); ?></a>
											<?php endif; ?>
											<a href="<?php echo esc_url( $delete_url ); ?>" class="btn btn-outline-danger btn-sm w-100 truelysell_core-dashboard-action-delete" onclick="return confirm('<?php echo esc_js( __( 'Delete this service?', 'truelysell' ) ); ?>');"><i class="ti ti-trash me-1"></i><?php esc_html_e( 'Delete', 'truelysell' ); ?></a>
										</div>
									<?php else : ?>
										<div class="d-flex justify-content-between align-items-center mt-3">
											<p class="mb-0 fs-12 text-muted"><?php esc_html_e( 'Managed by Admin', 'truelysell' ); ?></p>
											<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'custom_truelysell_action' => 'remove_linked_service', 'listing_id' => $listing_id ) ), 'custom_truelysell_remove_linked_service_' . $listing_id ) ); ?>" class="btn btn-outline-danger btn-sm truelysell_core-dashboard-action-remove-link" onclick="return confirm('<?php echo esc_js( __( 'Remove this service from your account? It stays available to other providers and on the public site.', 'truelysell' ) ); ?>');"><i class="ti ti-x me-1"></i><?php esc_html_e( 'Remove', 'truelysell' ); ?></a>
										</div>
									<?php endif; ?>

									<?php
									$listing_available_days = custom_truelysell_get_provider_listing_available_days( $user_id, $listing_id );
									$week_days               = array(
										'mon' => __( 'Mon', 'truelysell' ),
										'tue' => __( 'Tue', 'truelysell' ),
										'wed' => __( 'Wed', 'truelysell' ),
										'thu' => __( 'Thu', 'truelysell' ),
										'fri' => __( 'Fri', 'truelysell' ),
										'sat' => __( 'Sat', 'truelysell' ),
										'sun' => __( 'Sun', 'truelysell' ),
									);
									?>
									<div class="mt-3 pt-3 border-top truelysell-listing-availability" data-listing-id="<?php echo esc_attr( $listing_id ); ?>">
										<p class="mb-2 fs-12 text-muted"><?php esc_html_e( 'Available for this service on:', 'truelysell' ); ?></p>
										<div class="d-flex flex-column gap-2 mb-2">
											<?php foreach ( $week_days as $day_key => $day_label ) :
												$is_checked  = in_array( $day_key, $listing_available_days, true );
												$day_hours   = custom_truelysell_get_provider_listing_hours_for_day( $user_id, $listing_id, $day_key );
												$has_range   = '00:00' !== $day_hours['start'] || '23:59' !== $day_hours['end'];
												$start_value = $has_range ? $day_hours['start'] : '09:00';
												$end_value   = $has_range ? $day_hours['end'] : '17:00';
											?>
												<div class="truelysell-availability-day-row border rounded p-2">
													<label class="form-check d-flex align-items-center gap-2 mb-2">
														<input type="checkbox" class="form-check-input truelysell-availability-day" value="<?php echo esc_attr( $day_key ); ?>" <?php checked( $is_checked ); ?>>
														<span class="form-check-label fw-medium fs-12"><?php echo esc_html( $day_label ); ?></span>
													</label>
													<div class="d-flex align-items-center gap-2">
														<select class="form-select form-select-sm truelysell-availability-start" <?php disabled( ! $is_checked ); ?>>
															<?php echo custom_truelysell_time_select_options( $start_value ); ?>
														</select>
														<span class="fs-12 text-muted"><?php esc_html_e( 'to', 'truelysell' ); ?></span>
														<select class="form-select form-select-sm truelysell-availability-end" <?php disabled( ! $is_checked ); ?>>
															<?php echo custom_truelysell_time_select_options( $end_value ); ?>
														</select>
													</div>
												</div>
											<?php endforeach; ?>
										</div>
										<button type="button" class="btn btn-outline-secondary btn-sm truelysell-save-availability"><?php esc_html_e( 'Save Availability', 'truelysell' ); ?></button>
										<span class="fs-12 text-success ms-2 truelysell-availability-saved-msg" style="display:none;"><?php esc_html_e( 'Saved!', 'truelysell' ); ?></span>
									</div>
								</div>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php else : ?>
			<div class="card">
				<div class="card-body">
				<p class="mb-0 text-muted"><?php esc_html_e( 'No services selected yet.', 'truelysell' ); ?></p>
				</div>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

add_filter( 'the_content', 'custom_truelysell_append_provider_services_to_listings_page', 20 );


function custom_truelysell_append_provider_services_to_listings_page( $content ) {
	if ( ! is_page() || ! is_user_logged_in() ) {
		return $content;
	}

	$listings_page = truelysell_fl_framework_getoptions( 'listings_page' );
	if ( empty( $listings_page ) || absint( $listings_page ) !== get_queried_object_id() ) {
		return $content;
	}

	if ( ! custom_truelysell_is_restricted_provider( get_current_user_id() ) ) {
		return $content;
	}

	/*
	 * The "My Services" page already shows the provider's real, matching services
	 * via custom_truelysell_render_provider_services_card() above the native content
	 * (same block used on the Dashboard). Re-listing the raw "Selected Services"
	 * category tags here duplicated that with a different (and confusing) count,
	 * so it is no longer prepended. When we did find real matches, also drop the
	 * theme's native "you don't have any services" empty-state text so the page
	 * doesn't show contradictory messages.
	 */
	if ( ! empty( $GLOBALS['custom_truelysell_listings_page_has_matched_services'] ) ) {
		$content = preg_replace(
			'/<[^>]+>[^<]*don\'t have any services here[^<]*<\/[^>]+>/i',
			'',
			$content
		);
	}

	return $content;
}

/**
 * 1.b BOOKING: FIND PROVIDERS BY SERVICE AND NOTIFY THEM
 */
function custom_truelysell_notify_providers_on_booking( $fields, $entry, $form_data, $entry_id ) {
	$booking_form_id = 9001; // Update to your booking form ID.
	$service_field_id = 6; // Update to your booking service field ID.

	if ( empty( $form_data['id'] ) || absint( $form_data['id'] ) !== $booking_form_id ) {
		return;
	}

	$selected_service = '';
	if ( isset( $fields[ $service_field_id ] ) ) {
		$selected_service = $fields[ $service_field_id ];
		if ( is_array( $selected_service ) && isset( $selected_service['value'] ) ) {
			$selected_service = $selected_service['value'];
		}
		$selected_service = sanitize_text_field( wp_unslash( $selected_service ) );
	}

	if ( empty( $selected_service ) ) {
		return;
	}

	$providers = get_users( array(
		'meta_query' => array(
			array(
				'key'     => 'provider_services',
				'value'   => $selected_service,
				'compare' => 'LIKE',
			),
		),
	) );

	if ( empty( $providers ) ) {
		return;
	}

	foreach ( $providers as $provider ) {
		if ( empty( $provider->user_email ) || ! is_email( $provider->user_email ) ) {
			continue;
		}

		$subject = 'New Service Booking';
		$message = sprintf(
			"A customer has requested your service: %s\n\nPlease login to your dashboard for booking details.",
			esc_html( $selected_service )
		);

		wp_mail( $provider->user_email, $subject, $message );
	}
}
add_action( 'wpforms_process_complete', 'custom_truelysell_notify_providers_on_booking', 10, 4 );

/**
 * 2 & 4. [REMOVED] Technicians/providers used to be blocked from Add/Edit/Delete
 * Service (both via a `user_has_cap` filter and CSS/JS hiding those buttons
 * site-wide). That's been reversed on request: technicians can now use
 * "Add Service" and edit/delete the listings they create themselves, same as
 * the theme's native behavior for the 'owner'/'seller' roles.
 */


// ============================================================
// CUSTOMER BOOKING FIX - Child Theme
// Problem: Customer booking kar raha hai lekin "My Bookings"
// mein records nahi dikh rahe.
// Root Cause: Theme sirf owner_id se count karta tha,
// lekin customer bookings bookings_calendar.bookings_author mein hai.
// ============================================================

if ( ! function_exists( 'custom_truelysell_count_customer_bookings_from_calendar' ) ) {
    function custom_truelysell_count_customer_bookings_from_calendar( $user_id, $status = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bookings_calendar';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return 0;
        }
        if ( ! empty( $status ) ) {
            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE bookings_author = %d AND status = %s",
                absint( $user_id ), sanitize_text_field( $status )
            ) );
        }
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE bookings_author = %d",
            absint( $user_id )
        ) );
    }
}

if ( ! function_exists( 'custom_truelysell_get_customer_bookings_from_calendar' ) ) {
    function custom_truelysell_get_customer_bookings_from_calendar( $user_id, $status = '', $per_page = 10, $offset = 0 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bookings_calendar';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return array();
        }
        if ( ! empty( $status ) ) {
            $results = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE bookings_author = %d AND status = %s ORDER BY created DESC LIMIT %d OFFSET %d",
                absint( $user_id ), sanitize_text_field( $status ), absint( $per_page ), absint( $offset )
            ) );
        } else {
            $results = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE bookings_author = %d ORDER BY created DESC LIMIT %d OFFSET %d",
                absint( $user_id ), absint( $per_page ), absint( $offset )
            ) );
        }
        return $results ? $results : array();
    }
}

// Shortcode: [customer_my_bookings]
// Ise WordPress page editor mein "My Bookings" page pe add karo
add_shortcode( 'customer_my_bookings', 'custom_truelysell_customer_my_bookings_shortcode' );
function custom_truelysell_customer_my_bookings_shortcode( $atts ) {
    if ( ! is_user_logged_in() ) {
        return '<p>' . esc_html__( 'Please login to see your bookings.', 'truelysell' ) . '</p>';
    }
    $user_id  = get_current_user_id();
    $status   = isset( $_GET['booking_status'] ) ? sanitize_text_field( $_GET['booking_status'] ) : '';
    $per_page = 10;
    $page     = max( 1, isset( $_GET['bpage'] ) ? absint( $_GET['bpage'] ) : 1 );
    $offset   = ( $page - 1 ) * $per_page;
    $bookings = custom_truelysell_get_customer_bookings_from_calendar( $user_id, $status, $per_page, $offset );
    $total    = custom_truelysell_count_customer_bookings_from_calendar( $user_id, $status );
    $currency_symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$';
    ob_start();
    $current_url = strtok( $_SERVER['REQUEST_URI'], '?' );
    $statuses = array(
        '' => 'All', 'just-booked' => 'Just Booked', 'waiting' => 'Pending',
        'confirmed' => 'Confirmed', 'approved' => 'Approved', 'cancelled' => 'Cancelled',
    );
    echo '<div class="customer-bookings-wrap">';
    echo '<div class="d-flex flex-wrap gap-2 mb-4">';
    foreach ( $statuses as $sk => $sl ) {
        $is_a = ( $status === $sk );
        $url  = $sk === '' ? $current_url : add_query_arg( 'booking_status', $sk, $current_url );
        echo '<a href="' . esc_url( $url ) . '" class="btn btn-sm ' . ( $is_a ? 'btn-primary' : 'btn-outline-secondary' ) . '">' . esc_html( $sl ) . '</a>';
    }
    echo '</div>';
    if ( empty( $bookings ) ) {
        echo '<div class="alert alert-info">' . esc_html__( 'No bookings found.', 'truelysell' ) . '</div>';
    } else {
        echo '<div class="table-responsive"><table class="table table-hover align-middle">';
        echo '<thead class="table-light"><tr><th>#</th><th>Service</th><th>Date & Time</th><th>Price</th><th>Status</th><th>Action</th></tr></thead><tbody>';
        $badge_map = array(
            'just-booked' => 'bg-secondary', 'waiting' => 'bg-warning text-dark',
            'confirmed' => 'bg-success', 'approved' => 'bg-success',
            'cancelled' => 'bg-danger', 'paid' => 'bg-success', 'expired' => 'bg-dark',
        );
        foreach ( $bookings as $i => $booking ) {
            $lid    = isset( $booking->listing_id ) ? absint( $booking->listing_id ) : 0;
            $ltitle = $lid ? get_the_title( $lid ) : 'Service Deleted';
            $lurl   = $lid ? get_permalink( $lid ) : '#';
            $ds     = isset( $booking->date_start ) ? $booking->date_start : '';
            $de     = isset( $booking->date_end ) ? $booking->date_end : '';
            $price  = isset( $booking->price ) ? floatval( $booking->price ) : 0;
            $bst    = isset( $booking->status ) ? $booking->status : 'waiting';
            $oid    = isset( $booking->order_id ) ? absint( $booking->order_id ) : 0;
            $bc     = isset( $badge_map[ $bst ] ) ? $badge_map[ $bst ] : 'bg-secondary';
            $date_display = $ds ? date_i18n( 'd M Y, h:i A', strtotime( $ds ) ) : '—';
            $end_display  = ( $de && $de !== $ds ) ? ' to ' . date_i18n( 'd M Y, h:i A', strtotime( $de ) ) : '';
            $action = '—';
            if ( $oid && function_exists( 'wc_get_order' ) ) {
                $order = wc_get_order( $oid );
                if ( $order ) {
                    $action = '';
                    if ( $order->needs_payment() ) {
                        $action .= '<a href="' . esc_url( $order->get_checkout_payment_url() ) . '" class="btn btn-sm btn-warning me-1">Pay Now</a>';
                    }
                    $action .= '<a href="' . esc_url( wc_get_endpoint_url( 'view-order', $oid, wc_get_page_permalink( 'myaccount' ) ) ) . '" class="btn btn-sm btn-outline-primary" target="_blank">Order</a>';
                }
            }
            echo '<tr>';
            echo '<td><strong>#' . esc_html( $offset + $i + 1 ) . '</strong></td>';
            echo '<td><a href="' . esc_url( $lurl ) . '" target="_blank">' . esc_html( $ltitle ) . '</a></td>';
            echo '<td>' . esc_html( $date_display . $end_display ) . '</td>';
            echo '<td><strong>' . esc_html( $currency_symbol . number_format( $price, 2 ) ) . '</strong></td>';
            echo '<td><span class="badge ' . esc_attr( $bc ) . '">' . esc_html( ucwords( str_replace( '-', ' ', $bst ) ) ) . '</span></td>';
            echo '<td>' . $action . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        if ( $total > $per_page ) {
            $tp = ceil( $total / $per_page );
            echo '<div class="d-flex justify-content-center mt-3">';
            echo paginate_links( array( 'base' => add_query_arg( 'bpage', '%#%' ), 'format' => '', 'current' => $page, 'total' => $tp, 'type' => 'list', 'prev_text' => '&laquo;', 'next_text' => '&raquo;' ) );
            echo '</div>';
        }
    }
    echo '</div>';
    return ob_get_clean();
}

// Auto inject bookings on user_bookings_page if shortcode not present
add_filter( 'the_content', 'custom_truelysell_auto_inject_customer_bookings', 30 );
function custom_truelysell_auto_inject_customer_bookings( $content ) {
    if ( ! is_user_logged_in() || ! is_page() ) {
        return $content;
    }
    $user_id = get_current_user_id();
    if ( ! custom_truelysell_is_customer_user( $user_id ) ) {
        return $content;
    }
    if ( ! function_exists( 'truelysell_fl_framework_getoptions' ) ) {
        return $content;
    }
    $user_bookings_page = absint( truelysell_fl_framework_getoptions( 'user_bookings_page' ) );
    if ( ! $user_bookings_page || absint( get_queried_object_id() ) !== $user_bookings_page ) {
        return $content;
    }
    if ( has_shortcode( $content, 'customer_my_bookings' ) ) {
        return $content;
    }
    return $content . do_shortcode( '[customer_my_bookings]' );
}

// ============================================================
// CUSTOMER BOOKING FIX #2
// Problem: "Book Service" button sirf login modal open karta hai
// (data-bs-target="#user-account") even when customer IS logged in.
// Fix: JS se logged-in customer ke liye booking redirect karo.
// ============================================================

add_action( 'wp_footer', 'custom_truelysell_fix_book_service_button', 99 );
function custom_truelysell_fix_book_service_button() {
    if ( ! is_user_logged_in() ) {
        return;
    }

    $user_id = get_current_user_id();
    if ( ! custom_truelysell_is_customer_user( $user_id ) ) {
        return;
    }

    // User bookings page URL
    $user_bookings_url = '';
    if ( function_exists( 'truelysell_fl_framework_getoptions' ) ) {
        $ubp = truelysell_fl_framework_getoptions( 'user_bookings_page' );
        if ( $ubp ) {
            $user_bookings_url = get_permalink( $ubp );
        }
    }
    ?>
    <script type="text/javascript">
    (function($) {
        'use strict';
        document.addEventListener('DOMContentLoaded', function() {

            var isLoggedIn = true; // PHP confirmed user is logged in

            // Fix "Book Service" buttons that open login modal
            document.querySelectorAll('[data-bs-target="#user-account"]').forEach(function(btn) {
                var btnText = (btn.textContent || btn.innerText || '').trim().toLowerCase();

                // Only target booking buttons (not other buttons using this modal)
                if (btnText.indexOf('book') === -1) return;

                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();

                    // Check if booking modal exists on this page
                    var bookingModal = document.getElementById('book-service-modal-custom');
                    if (bookingModal) {
                        var bsModal = new bootstrap.Modal(bookingModal);
                        bsModal.show();
                        return;
                    }

                    // Get listing info from nearby form
                    var card = btn.closest('.card, .card-body, form, .theiaStickySidebar') || btn.parentElement;
                    var postIdInput = card ? card.querySelector('input[name="post_id"]') : null;
                    if (!postIdInput) {
                        postIdInput = document.querySelector('input[name="post_id"]');
                    }
                    var postId = postIdInput ? postIdInput.value : '';

                    // Build booking page URL
                    var bookingPageUrl = btn.closest('form') ? btn.closest('form').getAttribute('action') : '';

                    if (bookingPageUrl && postId) {
                        window.location.href = bookingPageUrl + '?post_id=' + postId + '&customer_booking=1';
                    } else {
                        var ubUrl = '<?php echo esc_js( $user_bookings_url ); ?>';
                        if (ubUrl) {
                            window.location.href = ubUrl;
                        } else {
                            alert('Please contact support to complete your booking.');
                        }
                    }
                }, true);

                // Visual fix: change button appearance for logged-in customers
                btn.removeAttribute('data-bs-toggle');
                btn.removeAttribute('data-bs-target');
                btn.style.cursor = 'pointer';
            });
        });
    })(typeof jQuery !== 'undefined' ? jQuery : { fn: {} });
    </script>
    <?php
}

// ============================================================
// BOOKING PAGE HANDLER: DISABLED - was causing redirect loops
// ============================================================
// add_action( 'template_redirect', 'custom_truelysell_handle_customer_booking_page' );
function custom_truelysell_handle_customer_booking_page() {
    // DISABLED - no redirects
    return;
}

/**
 * Multiple independent features on this site each want the Google Maps
 * Places JS library (the customer booking widget, the provider profile
 * address field, ...) — each used to print its OWN <script src=...maps...>
 * tag with its own callback=. If more than one ever fires on the SAME
 * page (e.g. a test account that's both a customer and, from earlier
 * testing, also flagged as a technician), the library gets loaded twice,
 * which is a well-documented source of silent breakage across EVERY
 * Maps-dependent script on that page — not just the duplicate. Every
 * feature now calls this to REGISTER its init function name instead of
 * printing its own script tag; custom_truelysell_print_google_maps_bootstrap()
 * (hooked very late in wp_footer, after everything has had a chance to
 * register) prints exactly one <script src> for the whole page.
 */
function custom_truelysell_enqueue_google_maps_once( $api_key, $callback_fn_name ) {
	global $custom_truelysell_maps_callbacks, $custom_truelysell_maps_api_key;
	if ( ! $api_key || ! $callback_fn_name ) {
		return;
	}
	if ( ! isset( $custom_truelysell_maps_callbacks ) ) {
		$custom_truelysell_maps_callbacks = array();
	}
	$custom_truelysell_maps_api_key     = $api_key;
	$custom_truelysell_maps_callbacks[] = $callback_fn_name;
}

add_action( 'wp_footer', 'custom_truelysell_print_google_maps_bootstrap', 999 );
function custom_truelysell_print_google_maps_bootstrap() {
	global $custom_truelysell_maps_callbacks, $custom_truelysell_maps_api_key;
	if ( empty( $custom_truelysell_maps_callbacks ) || empty( $custom_truelysell_maps_api_key ) ) {
		return;
	}
	$callbacks = array_unique( $custom_truelysell_maps_callbacks );
	?>
	<script>
	function customTruelysellGoogleMapsReady() {
		<?php foreach ( $callbacks as $fn ) : ?>
		if ( typeof <?php echo esc_js( $fn ); ?> === 'function' ) { <?php echo esc_js( $fn ); ?>(); }
		<?php endforeach; ?>
	}
	</script>
	<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo esc_attr( $custom_truelysell_maps_api_key ); ?>&libraries=places&loading=async&callback=customTruelysellGoogleMapsReady" async defer></script>
	<?php
}

// ============================================================
// INJECT BOOKING FORM on single listing page for logged-in customers
// Hook into listing single page bottom area
// ============================================================
add_action( 'wp_footer', 'custom_truelysell_inject_customer_booking_modal', 50 );
function custom_truelysell_inject_customer_booking_modal() {
    if ( ! is_user_logged_in() ) {
        return;
    }
    $user_id = get_current_user_id();
    if ( ! custom_truelysell_is_customer_user( $user_id ) ) {
        return;
    }

    // Only on single listing or pages with "Book Service" button
    global $post;
    $listing_id = 0;
    if ( $post && $post->post_type === 'listing' ) {
        $listing_id = $post->ID;
    }

    // Get current user data for pre-filling form
    $current_user = wp_get_current_user();
    $first_name   = $current_user->first_name;
    $last_name    = $current_user->last_name;
    $email        = $current_user->user_email;
    $phone        = get_user_meta( $user_id, 'phone', true );

    // Currency
    $currency_symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$';
    $listing_base_price = $listing_id ? custom_truelysell_get_listing_base_price( $listing_id ) : 0;
    ?>
    <style>
        /*
         * Google's autocomplete suggestions dropdown (.pac-container) is
         * appended directly to <body> with its own default z-index, which
         * is LOWER than Bootstrap's modal (1055+) — inside a modal, the
         * suggestions still work but render invisibly behind it without
         * this. Very common gotcha with Google Places + Bootstrap modals.
         */
        .pac-container {
            z-index: 99999 !important;
        }
    </style>
    <!-- Customer Booking Modal - Injected by Child Theme -->
    <div class="modal fade custom-modal" id="book-service-modal-custom" tabindex="-1" aria-labelledby="bookServiceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header border-bottom">
                    <h5 class="modal-title fw-bold" id="bookServiceModalLabel">
                        <i class="ti ti-calendar-check me-2 text-primary"></i>
                        <?php esc_html_e( 'Book Service', 'truelysell' ); ?>
                    </h5>
                    <a href="javascript:void(0);" data-bs-dismiss="modal" aria-label="Close">
                        <i class="ti ti-circle-x-filled fs-20 text-muted"></i>
                    </a>
                </div>
                <div class="modal-body p-4">

                    <!-- Step 1: Booking Info -->
                    <div id="booking-step-1">
                        <div class="alert alert-info d-flex align-items-center mb-4" role="alert">
                            <i class="ti ti-info-circle me-2 fs-18"></i>
                            <div><?php esc_html_e( 'Fill in your details to book this service. A 20% deposit is required to confirm your booking — you\'ll be taken to a secure payment page next. The provider will contact you to confirm the time, and collects the remaining balance directly.', 'truelysell' ); ?></div>
                        </div>

                        <form id="customer-booking-form" method="post">
                            <?php wp_nonce_field( 'customer_booking_nonce', 'customer_booking_nonce_field' ); ?>
                            <input type="hidden" name="listing_id" id="booking-listing-id" value="<?php echo esc_attr( $listing_id ); ?>">
                            <input type="hidden" name="action" value="customer_book_service">

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-medium"><?php esc_html_e( 'First Name', 'truelysell' ); ?> <span class="text-danger">*</span></label>
                                    <input type="text" name="firstname" class="form-control" value="<?php echo esc_attr( $first_name ); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-medium"><?php esc_html_e( 'Last Name', 'truelysell' ); ?> <span class="text-danger">*</span></label>
                                    <input type="text" name="lastname" class="form-control" value="<?php echo esc_attr( $last_name ); ?>" required>
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-medium"><?php esc_html_e( 'Email', 'truelysell' ); ?> <span class="text-danger">*</span></label>
                                    <input type="email" name="email" class="form-control" value="<?php echo esc_attr( $email ); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-medium"><?php esc_html_e( 'Phone', 'truelysell' ); ?> <span class="text-danger">*</span></label>
                                    <input type="text" name="phone" class="form-control" value="<?php echo esc_attr( $phone ); ?>" required>
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-12">
                                    <label class="form-label fw-medium"><?php esc_html_e( 'Your Service Address', 'truelysell' ); ?> <span class="text-danger">*</span></label>
                                    <input type="text" name="customer_address" id="customer-booking-address" class="form-control" placeholder="<?php esc_attr_e( 'Start typing your address...', 'truelysell' ); ?>" autocomplete="off" required>
                                    <div class="fs-12 text-muted mt-1"><?php esc_html_e( 'We use this to match you with the nearest available technician who serves your area.', 'truelysell' ); ?></div>
                                    <div id="customer-booking-map" style="display:none; height:220px; margin-top:10px; border-radius:8px; overflow:hidden;"></div>
                                    <input type="hidden" name="customer_lat" id="customer-booking-lat">
                                    <input type="hidden" name="customer_lng" id="customer-booking-lng">
                                    <input type="hidden" name="selected_provider_id" id="customer-selected-provider-id">
                                </div>
                            </div>

                            <div class="row g-3 mb-3" id="service-providers-wrapper" style="display:none;">
                                <div class="col-md-12" id="service-providers-list"></div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-12">
                                    <label class="form-label fw-medium"><?php esc_html_e( 'How many TVs need to be mounted?', 'truelysell' ); ?> <span class="text-danger">*</span></label>
                                    <input type="number" name="tv_quantity" id="customer-tv-quantity" class="form-control" min="1" max="20" step="1" value="1" required>
                                </div>
                                <div class="col-md-12" id="customer-tv-sizes-container">
                                    <!-- One "TV #N Size" field per TV, generated by JS below to match the quantity above — each TV can be a different size. -->
                                </div>
                                <div class="col-md-12">
                                    <div class="fs-12 text-muted mt-1"><?php echo esc_html( sprintf( __( 'The listed price covers TVs up to %d". A %s%d surcharge is automatically added for each TV larger than that.', 'truelysell' ), TRUELYSELL_CHILD_OVERSIZE_TV_THRESHOLD_INCHES, $currency_symbol, TRUELYSELL_CHILD_OVERSIZE_TV_SURCHARGE ) ); ?></div>
                                </div>
                            </div>

                            <?php if ( $listing_base_price > 0 ) : ?>
                            <div class="row g-3 mb-3">
                                <div class="col-md-12">
                                    <div class="alert alert-light border mb-0" id="customer-booking-price-summary">
                                        <div class="d-flex justify-content-between">
                                            <span><?php echo esc_html( sprintf( __( 'Service price (%s per TV)', 'truelysell' ), $currency_symbol . number_format( $listing_base_price, 2 ) ) ); ?></span>
                                            <span id="customer-booking-price-base"><?php echo esc_html( $currency_symbol . number_format( $listing_base_price, 2 ) ); ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between" id="customer-booking-price-surcharge-row" style="display:none !important;">
                                            <span><?php echo esc_html( sprintf( __( 'Oversized TV surcharge (over %d")', 'truelysell' ), TRUELYSELL_CHILD_OVERSIZE_TV_THRESHOLD_INCHES ) ); ?></span>
                                            <span id="customer-booking-price-surcharge">+<?php echo esc_html( $currency_symbol . number_format( TRUELYSELL_CHILD_OVERSIZE_TV_SURCHARGE, 2 ) ); ?></span>
                                        </div>
                                        <hr class="my-2">
                                        <div class="d-flex justify-content-between fw-medium">
                                            <span><?php esc_html_e( 'Total service price', 'truelysell' ); ?></span>
                                            <span id="customer-booking-price-total"><?php echo esc_html( $currency_symbol . number_format( $listing_base_price, 2 ) ); ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between text-primary fw-bold">
                                            <span><?php esc_html_e( 'Deposit due today (20%)', 'truelysell' ); ?></span>
                                            <span id="customer-booking-price-deposit"><?php echo esc_html( $currency_symbol . number_format( $listing_base_price * 0.2, 2 ) ); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-medium"><?php esc_html_e( 'Preferred Date', 'truelysell' ); ?> <span class="text-danger">*</span></label>
                                    <input type="date" name="preferred_date" id="customer-preferred-date" class="form-control" min="<?php echo esc_attr( date( 'Y-m-d' ) ); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-medium"><?php esc_html_e( 'Preferred Time', 'truelysell' ); ?> <span class="text-danger">*</span></label>
                                    <input type="time" name="preferred_time" id="customer-preferred-time" class="form-control" required>
                                </div>
                                <div class="col-md-12">
                                    <div id="preferred-date-availability-warning" style="display:none;" class="alert alert-warning py-2 mb-2"></div>
                                    <div class="fs-12 text-muted mt-1"><?php esc_html_e( 'The provider will contact you to confirm or suggest an alternative if needed.', 'truelysell' ); ?></div>
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-12">
                                    <label class="form-label fw-medium"><?php esc_html_e( 'Message / Notes', 'truelysell' ); ?></label>
                                    <textarea name="message" class="form-control" rows="2" placeholder="<?php esc_attr_e( 'Any special instructions...', 'truelysell' ); ?>"></textarea>
                                </div>
                            </div>

                            <div id="booking-response-msg"></div>

                            <div class="d-flex gap-3 mt-4">
                                <button type="button" class="btn btn-light flex-grow-1" data-bs-dismiss="modal">
                                    <?php esc_html_e( 'Cancel', 'truelysell' ); ?>
                                </button>
                                <button type="submit" class="btn btn-primary flex-grow-1" id="submit-customer-booking">
                                    <i class="ti ti-calendar-plus me-2"></i>
                                    <?php esc_html_e( 'Proceed to Pay 20% Deposit', 'truelysell' ); ?>
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Step 2: Redirecting to payment -->
                    <div id="booking-step-success" style="display:none;" class="text-center py-4">
                        <div class="mb-4">
                            <div class="d-flex justify-content-center align-items-center rounded-circle bg-success bg-opacity-10 mx-auto mb-3" style="width:80px;height:80px;">
                                <span class="spinner-border text-success"></span>
                            </div>
                            <h4 class="fw-bold text-success"><?php esc_html_e( 'Redirecting to Payment...', 'truelysell' ); ?></h4>
                            <p class="text-muted"><?php esc_html_e( 'Please wait while we take you to a secure page to pay your 20% deposit.', 'truelysell' ); ?></p>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <?php
    $customer_booking_maps_api_key = get_option( 'truelysell_maps_api_server' );
    if ( ! $customer_booking_maps_api_key && defined( 'TRUELYSELL_CHILD_GOOGLE_MAPS_API_KEY' ) ) {
        $customer_booking_maps_api_key = TRUELYSELL_CHILD_GOOGLE_MAPS_API_KEY;
    }
    custom_truelysell_enqueue_google_maps_once( $customer_booking_maps_api_key, 'customTruelysellInitBookingAddressAutocomplete' );
    ?>
    <script type="text/javascript">
    /*
     * Declared as plain global function declarations (not inside
     * DOMContentLoaded) because the Google Maps script tag below loads
     * async and calls this callback the moment it's ready — which can
     * happen before DOMContentLoaded fires. Function declarations are
     * hoisted, and the modal HTML they reference is already in the DOM
     * regardless (it's output earlier in the page than this script), so
     * it's safe to call this at any time.
     */
    var customTruelysellAssignedProviderDays = null;
    var customTruelysellAssignedProviderHours = null;
    var customTruelysellProvidersData = null;

    /*
     * If the customer arrived on this listing page via a specific
     * technician's own profile page (provider-details.php's service links
     * carry ?author_id=), they've effectively already chosen — default to
     * that technician instead of silently overriding their choice with
     * "nearest." Falls back to the first (nearest, or first-listed in the
     * manual-pick case) provider when there's no such context, or when
     * that specific technician isn't actually in today's eligible list
     * (e.g. out of their travel radius for this address).
     */
    function customTruelysellPickDefaultProvider(providers) {
        var preferredId = new URLSearchParams(window.location.search).get('author_id');
        if (preferredId) {
            for (var i = 0; i < providers.length; i++) {
                if (String(providers[i].id) === String(preferredId)) return providers[i];
            }
        }
        return providers[0];
    }

    function customTruelysellSelectProvider(providerId) {
        var list = document.getElementById('service-providers-list');
        if (!list || !customTruelysellProvidersData) return;

        var picked = null;
        for (var i = 0; i < customTruelysellProvidersData.length; i++) {
            if (String(customTruelysellProvidersData[i].id) === String(providerId)) {
                picked = customTruelysellProvidersData[i];
                break;
            }
        }
        if (!picked) return;

        document.getElementById('customer-selected-provider-id').value = picked.id;
        customTruelysellAssignedProviderDays = picked.available_days || null;
        customTruelysellAssignedProviderHours = picked.available_hours || null;

        list.querySelectorAll('.truelysell-provider-card').forEach(function (card) {
            var isSelected = String(card.getAttribute('data-provider-id')) === String(picked.id);
            card.classList.toggle('border-primary', isSelected);
            var check = card.querySelector('.truelysell-provider-check');
            if (check) check.style.visibility = isSelected ? 'visible' : 'hidden';
        });

        customTruelysellCheckPreferredDateAvailability();
    }

    function customTruelysellRenderProviderCards(providers, showDistance) {
        var list = document.getElementById('service-providers-list');
        if (!list) return;

        var selected = customTruelysellPickDefaultProvider(providers);

        var html = '<div class="fs-13 text-muted mb-2">Choose your technician:</div>';
        providers.forEach(function (p, idx) {
            var isSelected = String(p.id) === String(selected.id);
            var hasDistance = (typeof p.distance_miles !== 'undefined' && p.distance_miles !== null);
            // "Nearest provider" always reflects true sorted order (idx === 0),
            // independent of which card is pre-selected — a referred
            // technician who isn't actually nearest shouldn't be mislabeled.
            var badge = (showDistance && idx === 0) ? ' <span class="badge bg-success ms-1"><i class="ti ti-map-pin-filled me-1"></i>Nearest provider</span>' : '';
            var distanceLine = hasDistance ? ('<div class="fs-12 text-muted">about ' + p.distance_miles + ' miles away</div>') : '';
            html += '<div class="card mb-2 truelysell-provider-card' + (isSelected ? ' border-primary' : '') + '" data-provider-id="' + p.id + '" style="cursor:pointer;">' +
                '<div class="card-body d-flex align-items-center gap-2 py-2">' +
                    '<img src="' + p.avatar + '" alt="" style="width:36px;height:36px;border-radius:50%;object-fit:cover;">' +
                    '<div class="flex-grow-1">' +
                        '<div><strong>' + p.name + '</strong>' + badge + '</div>' +
                        distanceLine +
                    '</div>' +
                    '<i class="ti ti-circle-check-filled text-primary truelysell-provider-check" style="' + (isSelected ? '' : 'visibility:hidden;') + '"></i>' +
                '</div>' +
            '</div>';
        });
        list.innerHTML = html;

        list.querySelectorAll('.truelysell-provider-card').forEach(function (card) {
            card.addEventListener('click', function () {
                customTruelysellSelectProvider(card.getAttribute('data-provider-id'));
            });
        });
    }

    function customTruelysellFindNearestProvider(lat, lng) {
        var listingId = document.getElementById('booking-listing-id').value;
        var wrap = document.getElementById('service-providers-wrapper');
        var list = document.getElementById('service-providers-list');
        var submitBtn = document.getElementById('submit-customer-booking');
        if (!wrap || !list || !listingId) return;

        document.getElementById('customer-selected-provider-id').value = '';
        customTruelysellAssignedProviderDays = null;
        customTruelysellAssignedProviderHours = null;
        customTruelysellProvidersData = null;
        wrap.style.display = 'block';
        wrap.setAttribute('data-out-of-area', '0');
        wrap.setAttribute('data-out-of-hours', '0');
        list.innerHTML = '<div class="text-muted fs-13"><span class="spinner-border spinner-border-sm me-2"></span>Finding a technician near you...</div>';
        if (submitBtn) submitBtn.disabled = true;

        var formData = new FormData();
        formData.append('action', 'custom_truelysell_get_service_providers');
        formData.append('listing_id', listingId);
        formData.append('lat', lat);
        formData.append('lng', lng);

        fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', {
            method: 'POST',
            body: formData
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            var payload = data.success && data.data ? data.data : {};
            if (submitBtn) submitBtn.disabled = false;

            if (payload.assigned && payload.providers && payload.providers.length) {
                customTruelysellProvidersData = payload.providers;
                var defaultProvider = customTruelysellPickDefaultProvider(payload.providers);
                document.getElementById('customer-selected-provider-id').value = defaultProvider.id;
                customTruelysellAssignedProviderDays = defaultProvider.available_days || null;
                customTruelysellAssignedProviderHours = defaultProvider.available_hours || null;
                wrap.setAttribute('data-out-of-area', '0');
                customTruelysellRenderProviderCards(payload.providers, true);
                customTruelysellCheckPreferredDateAvailability();
                return;
            }

            var debugHtml = '';
            if (payload.debug && payload.debug.length) {
                debugHtml = '<div class="alert alert-warning mt-2 mb-0 fs-12"><strong>Admin only — why no technician matched:</strong><ul class="mb-0 ps-3">';
                payload.debug.forEach(function (d) {
                    debugHtml += '<li>' + d.name + ': ' + d.issue + '</li>';
                });
                debugHtml += '</ul></div>';
            }

            if (payload.fallback && payload.providers && payload.providers.length) {
                // Nobody linked to this service has finished their
                // address/radius setup, so we can't say who's "nearest" —
                // but there ARE real technicians linked to it, so let the
                // customer pick one directly instead of a vague message.
                customTruelysellProvidersData = payload.providers;
                var defaultManualProvider = customTruelysellPickDefaultProvider(payload.providers);
                document.getElementById('customer-selected-provider-id').value = defaultManualProvider.id;
                customTruelysellAssignedProviderDays = defaultManualProvider.available_days || null;
                customTruelysellAssignedProviderHours = defaultManualProvider.available_hours || null;
                wrap.setAttribute('data-out-of-area', '0');
                customTruelysellRenderProviderCards(payload.providers, false);
                customTruelysellCheckPreferredDateAvailability();
                if (debugHtml) list.innerHTML += debugHtml;
                return;
            }

            if (payload.fallback) {
                // Truly nobody real linked to this listing at all — let
                // the booking through (server-side falls back to the
                // listing's default owner), but still say something
                // instead of silently showing nothing.
                wrap.style.display = 'block';
                list.innerHTML = '<div class="alert alert-secondary mb-0"><i class="ti ti-info-circle me-1"></i>We\'ll assign the best available technician for your area and confirm shortly.</div>' + debugHtml;
                return;
            }

            // Genuinely out of area — the spec's exact required message,
            // and this DOES block booking since there's truly nobody to
            // assign it to.
            wrap.setAttribute('data-out-of-area', '1');
            list.innerHTML = '<div class="alert alert-danger mb-0"><i class="ti ti-alert-triangle me-1"></i>' + (payload.message || 'Sorry, we do not currently service your area.') + '</div>' + debugHtml;
            if (submitBtn) submitBtn.disabled = true;
        })
        .catch(function () {
            if (submitBtn) submitBtn.disabled = false;
            list.innerHTML = '<div class="alert alert-danger mb-0">Could not check availability. Please try again.</div>';
        });
    }

    var customTruelysellBookingMap = null;
    var customTruelysellBookingMarker = null;

    function customTruelysellCheckPreferredDateAvailability() {
        var warningEl = document.getElementById('preferred-date-availability-warning');
        var dateInput = document.getElementById('customer-preferred-date');
        var timeInput = document.getElementById('customer-preferred-time');
        var wrap = document.getElementById('service-providers-wrapper');
        if (!warningEl || !dateInput) return;

        var days = customTruelysellAssignedProviderDays;
        var hours = customTruelysellAssignedProviderHours;

        if (wrap) wrap.setAttribute('data-out-of-hours', '0');

        if (!dateInput.value || !days) {
            warningEl.style.display = 'none';
            return;
        }

        var dayKeys = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
        // new Date('YYYY-MM-DD') parses as UTC midnight — use noon to
        // avoid the date shifting a day back in timezones behind UTC.
        var picked = new Date(dateInput.value + 'T12:00:00');
        var dayKey = dayKeys[picked.getDay()];
        var dayLabel = picked.toLocaleDateString(undefined, { weekday: 'long' });

        if (days.indexOf(dayKey) === -1) {
            warningEl.innerHTML = '<i class="ti ti-alert-triangle me-1"></i>Sorry, your selected technician isn’t available on ' + dayLabel + 's. Please choose a different date, or pick a different technician above.';
            warningEl.style.display = 'block';
            if (wrap) wrap.setAttribute('data-out-of-hours', '1');
            return;
        }

        var timeVal = timeInput ? timeInput.value : '';
        var range = hours && hours[dayKey] ? hours[dayKey] : null;
        if (timeVal && range && (timeVal < range.start || timeVal >= range.end)) {
            warningEl.innerHTML = '<i class="ti ti-alert-triangle me-1"></i>Your selected technician is only available on ' + dayLabel + 's between ' + range.start + ' and ' + range.end + '. Please pick a time in that range.';
            warningEl.style.display = 'block';
            if (wrap) wrap.setAttribute('data-out-of-hours', '1');
            return;
        }

        warningEl.style.display = 'none';
    }

    function customTruelysellInitBookingAddressAutocomplete() {
        var input = document.getElementById('customer-booking-address');
        if (!input || !window.google || !google.maps || !google.maps.places) return;

        /*
         * place_changed only fires (and only then updates lat/lng) when a
         * dropdown suggestion is actually picked — editing the text
         * afterward without reselecting would otherwise leave the OLD
         * lat/lng in place while the visible address shows something
         * completely different, silently matching technicians against the
         * wrong location. Clearing on every manual edit forces a fresh
         * selection, and the existing submit check ("Please select your
         * service address from the suggestions") already catches this.
         */
        input.addEventListener('input', function () {
            document.getElementById('customer-booking-lat').value = '';
            document.getElementById('customer-booking-lng').value = '';
            document.getElementById('customer-selected-provider-id').value = '';
            customTruelysellAssignedProviderDays = null;
            customTruelysellAssignedProviderHours = null;
            customTruelysellProvidersData = null;
            var providersWrap = document.getElementById('service-providers-wrapper');
            var providersList = document.getElementById('service-providers-list');
            if (providersWrap) {
                providersWrap.style.display = 'none';
                providersWrap.setAttribute('data-out-of-area', '0');
                providersWrap.setAttribute('data-out-of-hours', '0');
            }
            if (providersList) providersList.innerHTML = '';
        });

        var autocomplete = new google.maps.places.Autocomplete(input, { types: ['address'] });

        // Selecting a real address is what drives eligibility matching
        // now — this is the customer's service address, geocoded and
        // checked against every linked technician's home-address +
        // travel-radius to find (and auto-assign) the nearest one who
        // actually covers this location.
        autocomplete.addListener('place_changed', function () {
            var place = autocomplete.getPlace();
            if (!place || !place.geometry) return;

            var lat = place.geometry.location.lat();
            var lng = place.geometry.location.lng();

            document.getElementById('customer-booking-lat').value = lat;
            document.getElementById('customer-booking-lng').value = lng;

            var mapEl = document.getElementById('customer-booking-map');
            if (mapEl) {
                mapEl.style.display = 'block';

                if (!customTruelysellBookingMap) {
                    customTruelysellBookingMap = new google.maps.Map(mapEl, {
                        center: place.geometry.location,
                        zoom: 15
                    });
                    customTruelysellBookingMarker = new google.maps.Marker({
                        position: place.geometry.location,
                        map: customTruelysellBookingMap
                    });
                } else {
                    customTruelysellBookingMap.setCenter(place.geometry.location);
                    customTruelysellBookingMarker.setPosition(place.geometry.location);
                }

                // Modal display can hide the map on first paint (0 width
                // at creation time) — nudge Maps to recalculate its size
                // now that it's actually visible.
                google.maps.event.trigger(customTruelysellBookingMap, 'resize');
                customTruelysellBookingMap.setCenter(place.geometry.location);
            }

            customTruelysellFindNearestProvider(lat, lng);
        });
    }

    document.addEventListener('DOMContentLoaded', function() {

        var bookingForm   = document.getElementById('customer-booking-form');
        var responseMsg   = document.getElementById('booking-response-msg');
        var step1         = document.getElementById('booking-step-1');
        var stepSuccess   = document.getElementById('booking-step-success');

        // When "Book Service" btn is clicked, set the listing ID in the form
        document.querySelectorAll('[data-bs-target="#user-account"]').forEach(function(btn) {
            var btnText = (btn.textContent || btn.innerText || '').trim().toLowerCase();
            if (btnText.indexOf('book') === -1) return;

            btn.setAttribute('data-bs-toggle', 'modal');
            btn.setAttribute('data-bs-target', '#book-service-modal-custom');

            btn.addEventListener('click', function() {
                // Get post_id from nearby hidden input
                var card = btn.closest('form') || btn.closest('.card-body') || btn.parentElement;
                var postInput = card ? card.querySelector('input[name="post_id"]') : null;
                if (!postInput) postInput = document.querySelector('input[name="post_id"]');
                if (postInput) {
                    document.getElementById('booking-listing-id').value = postInput.value;
                }
                // Reset form state
                if (step1) step1.style.display = 'block';
                if (stepSuccess) stepSuccess.style.display = 'none';
                if (responseMsg) responseMsg.innerHTML = '';

                var addrInput = document.getElementById('customer-booking-address');
                if (addrInput) addrInput.value = '';
                document.getElementById('customer-booking-lat').value = '';
                document.getElementById('customer-booking-lng').value = '';
                document.getElementById('customer-selected-provider-id').value = '';
                customTruelysellAssignedProviderDays = null;
                customTruelysellAssignedProviderHours = null;
                customTruelysellProvidersData = null;
                var mapEl = document.getElementById('customer-booking-map');
                if (mapEl) mapEl.style.display = 'none';
                var providersWrap = document.getElementById('service-providers-wrapper');
                if (providersWrap) {
                    providersWrap.style.display = 'none';
                    providersWrap.setAttribute('data-out-of-area', '0');
                    providersWrap.setAttribute('data-out-of-hours', '0');
                }
                var providersList = document.getElementById('service-providers-list');
                if (providersList) providersList.innerHTML = '';
                var preferredDateReset = document.getElementById('customer-preferred-date');
                if (preferredDateReset) preferredDateReset.value = '';
                var availabilityWarningReset = document.getElementById('preferred-date-availability-warning');
                if (availabilityWarningReset) availabilityWarningReset.style.display = 'none';
            });
        });

        // If the Maps script already finished loading before this ran
        // (e.g. cached), its callback= already fired and did nothing
        // because the input didn't exist yet at that point — so try again.
        if (window.google && window.google.maps && window.google.maps.places) {
            customTruelysellInitBookingAddressAutocomplete();
        }

        var preferredDateInput = document.getElementById('customer-preferred-date');
        if (preferredDateInput) {
            preferredDateInput.addEventListener('change', customTruelysellCheckPreferredDateAvailability);
        }
        var preferredTimeInput = document.getElementById('customer-preferred-time');
        if (preferredTimeInput) {
            preferredTimeInput.addEventListener('change', customTruelysellCheckPreferredDateAvailability);
        }

        // Live price summary — regenerates one size field per TV as the
        // quantity changes, and recalculates the total/deposit from
        // however many of those individual TVs are actually oversized,
        // so the total shown always matches what the server will charge
        // (each TV is priced on its own size, not one size for all of them).
        (function () {
            var basePrice        = <?php echo wp_json_encode( $listing_base_price ); ?>;
            var threshold        = <?php echo wp_json_encode( TRUELYSELL_CHILD_OVERSIZE_TV_THRESHOLD_INCHES ); ?>;
            var surchargePerTv   = <?php echo wp_json_encode( TRUELYSELL_CHILD_OVERSIZE_TV_SURCHARGE ); ?>;
            /*
             * get_woocommerce_currency_symbol() returns an HTML entity
             * string (e.g. "&#36;" for USD), meant for direct HTML output
             * (where it correctly renders as "$") — but assigned as-is
             * into JS and written via .textContent (which never parses
             * HTML), it shows up as the literal text "&#36;". Decode it
             * server-side first so the JS-side value is a plain character.
             */
            var currency         = <?php echo wp_json_encode( html_entity_decode( $currency_symbol, ENT_QUOTES ) ); ?>;
            var tvQuantityInput  = document.getElementById('customer-tv-quantity');
            var sizesContainer   = document.getElementById('customer-tv-sizes-container');
            var surchargeRow     = document.getElementById('customer-booking-price-surcharge-row');
            var surchargeLabelEl = surchargeRow ? surchargeRow.querySelector('span:first-child') : null;
            var surchargeEl      = document.getElementById('customer-booking-price-surcharge');
            var baseEl           = document.getElementById('customer-booking-price-base');
            var totalEl          = document.getElementById('customer-booking-price-total');
            var depositEl        = document.getElementById('customer-booking-price-deposit');

            if (!tvQuantityInput || !sizesContainer || !totalEl || !depositEl || !basePrice) {
                return;
            }

            function formatMoney(amount) {
                return currency + amount.toFixed(2);
            }

            // Keeps whatever the customer already typed for TVs #1..N when
            // the count changes — only adds/removes rows at the end,
            // rather than wiping every field on every quantity change.
            function renderSizeInputs() {
                var quantity = parseInt(tvQuantityInput.value, 10);
                if (isNaN(quantity) || quantity < 1) {
                    quantity = 1;
                }
                quantity = Math.min(quantity, 20);

                var existing = sizesContainer.querySelectorAll('.truelysell-tv-size-row');
                for (var i = existing.length; i < quantity; i++) {
                    var row = document.createElement('div');
                    row.className = 'mb-2 truelysell-tv-size-row';
                    row.innerHTML =
                        '<label class="form-label fw-medium">' + <?php echo wp_json_encode( __( 'TV #', 'truelysell' ) ); ?> + (i + 1) + ' ' + <?php echo wp_json_encode( __( 'Size (inches)', 'truelysell' ) ); ?> + ' <span class="text-danger">*</span></label>' +
                        '<input type="number" name="tv_sizes[]" class="form-control truelysell-tv-size-input" min="1" max="200" step="1" placeholder="<?php echo esc_js( __( 'e.g. 55', 'truelysell' ) ); ?>" required>';
                    sizesContainer.appendChild(row);
                    row.querySelector('.truelysell-tv-size-input').addEventListener('input', recalcPrice);
                }
                for (var j = existing.length - 1; j >= quantity; j--) {
                    existing[j].remove();
                }
            }

            function recalcPrice() {
                var quantity = parseInt(tvQuantityInput.value, 10);
                if (isNaN(quantity) || quantity < 1) {
                    quantity = 1;
                }

                var oversizedCount = 0;
                sizesContainer.querySelectorAll('.truelysell-tv-size-input').forEach(function (input) {
                    var size = parseFloat(input.value);
                    if (!isNaN(size) && size > threshold) {
                        oversizedCount++;
                    }
                });

                var surchargeTotal = oversizedCount * surchargePerTv;
                var total = (basePrice * quantity) + surchargeTotal;

                /*
                 * A plain `.style.display = 'none'` here was silently
                 * losing to this theme's `.d-flex` utility class, which
                 * is itself !important-styled — so the row never actually
                 * hid despite the JS logic running correctly. Setting it
                 * with 'important' via setProperty() guarantees this wins
                 * regardless of what any stylesheet rule does.
                 */
                if (surchargeRow) {
                    surchargeRow.style.setProperty('display', oversizedCount > 0 ? 'flex' : 'none', 'important');
                }
                if (surchargeLabelEl) {
                    surchargeLabelEl.textContent = oversizedCount + <?php echo wp_json_encode( ' ' . __( 'oversized TV(s) (over', 'truelysell' ) . ' ' ); ?> + threshold + '")';
                }
                if (surchargeEl) {
                    surchargeEl.textContent = '+' + formatMoney(surchargeTotal);
                }
                if (baseEl) {
                    baseEl.textContent = formatMoney(basePrice * quantity);
                }
                totalEl.textContent = formatMoney(total);
                depositEl.textContent = formatMoney(total * 0.2);
            }

            tvQuantityInput.addEventListener('input', function () {
                renderSizeInputs();
                recalcPrice();
            });

            renderSizeInputs();
            recalcPrice();
        })();

        // Form submission
        if (bookingForm) {
            bookingForm.addEventListener('submit', function(e) {
                e.preventDefault();

                var submitBtn = document.getElementById('submit-customer-booking');
                var listingId = document.getElementById('booking-listing-id').value;

                if (!listingId || listingId === '0') {
                    if (responseMsg) responseMsg.innerHTML = '<div class="alert alert-danger mt-2">Could not identify the service. Please refresh and try again.</div>';
                    return;
                }

                var tvQuantityVal = document.getElementById('customer-tv-quantity').value;
                var tvSizeInputs  = document.querySelectorAll('.truelysell-tv-size-input');
                var allSizesValid = tvSizeInputs.length > 0;
                tvSizeInputs.forEach(function (input) {
                    if (!input.value || parseFloat(input.value) <= 0) {
                        allSizesValid = false;
                    }
                });
                if (!tvQuantityVal || parseInt(tvQuantityVal, 10) < 1 || tvSizeInputs.length !== parseInt(tvQuantityVal, 10) || !allSizesValid) {
                    if (responseMsg) responseMsg.innerHTML = '<div class="alert alert-warning mt-2">Please enter how many TVs need to be mounted and the size of each one.</div>';
                    return;
                }

                var addressVal = document.getElementById('customer-booking-address').value;
                var customerLatVal = document.getElementById('customer-booking-lat').value;
                if (!addressVal || !customerLatVal) {
                    if (responseMsg) responseMsg.innerHTML = '<div class="alert alert-warning mt-2">Please select your service address from the suggestions.</div>';
                    return;
                }

                var providersWrapEl = document.getElementById('service-providers-wrapper');
                if (providersWrapEl && providersWrapEl.getAttribute('data-out-of-area') === '1') {
                    if (responseMsg) responseMsg.innerHTML = '<div class="alert alert-danger mt-2">Sorry, we do not currently service your area.</div>';
                    return;
                }

                var preferredDateVal = document.getElementById('customer-preferred-date').value;
                var preferredTimeVal = document.getElementById('customer-preferred-time').value;
                if (!preferredDateVal || !preferredTimeVal) {
                    if (responseMsg) responseMsg.innerHTML = '<div class="alert alert-warning mt-2">Please select a preferred date and time.</div>';
                    return;
                }

                if (providersWrapEl && providersWrapEl.getAttribute('data-out-of-hours') === '1') {
                    if (responseMsg) responseMsg.innerHTML = '<div class="alert alert-danger mt-2">The selected technician is not available at that day/time. Please choose a different date/time, or pick a different technician above.</div>';
                    return;
                }

                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';

                var formData = new FormData(bookingForm);
                formData.set('action', 'customer_book_service');
                formData.set('listing_id', listingId);

                fetch('<?php echo esc_js( admin_url('admin-ajax.php') ); ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success && data.data && data.data.redirect_url) {
                        step1.style.display = 'none';
                        if (stepSuccess) stepSuccess.style.display = 'block';
                        window.location.href = data.data.redirect_url;
                    } else {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="ti ti-calendar-plus me-2"></i>Proceed to Pay 20% Deposit';
                        if (responseMsg) responseMsg.innerHTML = '<div class="alert alert-danger mt-2">' + (data.data || 'Booking failed. Please try again.') + '</div>';
                    }
                })
                .catch(function() {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="ti ti-calendar-plus me-2"></i>Proceed to Pay 20% Deposit';
                    if (responseMsg) responseMsg.innerHTML = '<div class="alert alert-danger mt-2">Network error. Please try again.</div>';
                });
            });
        }
    });
    </script>
    <?php
}

// ============================================================
// AUTO-ASSIGNMENT ENGINE — every eligible technician within their own
// travel radius, per the formal spec: technician home address is entered
// once in their profile and NEVER shown to anyone; only used server-side
// to compute eligibility. All eligible technicians are shown to the
// customer (nearest pre-selected/badged); the customer may pick any of
// them, but the server always re-validates the choice is still eligible.
// ============================================================

function custom_truelysell_all_week_days() {
	return array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );
}

/**
 * Great-circle distance between two lat/lng points, in miles (the spec's
 * example radius is given in miles).
 */
function custom_truelysell_haversine_miles( $lat1, $lng1, $lat2, $lng2 ) {
	$earth_radius_miles = 3958.8;

	$lat1 = deg2rad( (float) $lat1 );
	$lng1 = deg2rad( (float) $lng1 );
	$lat2 = deg2rad( (float) $lat2 );
	$lng2 = deg2rad( (float) $lng2 );

	$delta_lat = $lat2 - $lat1;
	$delta_lng = $lng2 - $lng1;

	$a = sin( $delta_lat / 2 ) ** 2 + cos( $lat1 ) * cos( $lat2 ) * sin( $delta_lng / 2 ) ** 2;
	$c = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );

	return $earth_radius_miles * $c;
}

/**
 * Every technician linked to $listing_id whose own travel radius (from
 * their home address, set once in their profile and never exposed here or
 * anywhere customer-facing) actually covers the customer's given lat/lng,
 * sorted nearest-first (ties broken by provider ID, so exact/near ties
 * render in a stable order instead of "whoever linked first").
 *
 * Returns array( 'providers' => array( array(id, name, avatar,
 * available_days, available_hours, distance_miles), ... ) ) sorted
 * nearest-first, or array( 'no_technicians_configured' => true ) if
 * nobody's set up their address/radius yet (caller should fall back to
 * the pre-existing default owner rather than block every booking
 * site-wide before providers have had a chance to fill this in), or
 * array( 'out_of_area' => true ) if at least one technician IS configured
 * but none of their radii reach this customer — the spec's actual "we do
 * not currently service your area" case.
 */
function custom_truelysell_find_nearest_eligible_provider( $listing_id, $customer_lat, $customer_lng ) {
	$provider_ids  = custom_truelysell_get_listing_linked_provider_ids( $listing_id );
	$configured    = array(); // providers who HAVE set address + radius
	$eligible      = array(); // of those, ones whose radius covers this customer
	$diagnostics   = array(); // admin-only: why each linked provider didn't qualify

	if ( empty( $provider_ids ) ) {
		return array(
			'no_technicians_configured' => true,
			'diagnostics'               => array( array( 'name' => '—', 'issue' => 'No technicians are linked to this listing at all.' ) ),
		);
	}

	foreach ( $provider_ids as $provider_id ) {
		$provider_user = get_userdata( $provider_id );
		$provider_name = $provider_user ? $provider_user->display_name : ( 'User #' . $provider_id );

		if ( ! custom_truelysell_is_restricted_provider( $provider_id ) ) {
			$diagnostics[] = array( 'name' => $provider_name, 'issue' => 'Not recognized as an active technician account (role/is_technician check failed).' );
			continue;
		}

		$provider_lat    = get_user_meta( $provider_id, 'profile-lat', true );
		$provider_lng    = get_user_meta( $provider_id, 'profile-lng', true );
		$provider_radius = get_user_meta( $provider_id, 'profile-travel-radius-miles', true );

		$missing = array();
		if ( '' === $provider_lat || '' === $provider_lng ) {
			$missing[] = 'home address';
		}
		if ( ! $provider_radius ) {
			$missing[] = 'travel radius';
		}
		if ( ! empty( $missing ) ) {
			$diagnostics[] = array( 'name' => $provider_name, 'issue' => 'Missing ' . implode( ' and ', $missing ) . ' on their profile.' );
			continue; // hasn't finished setting up their profile yet
		}

		$configured[] = $provider_id;

		$distance = custom_truelysell_haversine_miles( $customer_lat, $customer_lng, $provider_lat, $provider_lng );
		if ( $distance > absint( $provider_radius ) ) {
			$diagnostics[] = array( 'name' => $provider_name, 'issue' => sprintf( 'Profile is configured, but this address is %s miles away — outside their %d-mile travel radius.', round( $distance, 1 ), absint( $provider_radius ) ) );
			continue; // outside THIS technician's own radius
		}

		$eligible[] = array( 'id' => $provider_id, 'distance' => $distance );
	}

	if ( empty( $configured ) ) {
		/*
		 * Nobody linked to this listing has both address AND radius set,
		 * so we can't do real eligibility filtering yet — but there ARE
		 * real technicians linked to this service, so let the customer
		 * pick one directly instead of leaving them with no named option
		 * at all. Still show a distance on each card whenever that
		 * technician's home address IS geocoded (radius or no radius) —
		 * no "nearest" badge though, since without every radius confirmed
		 * we can't honestly claim who's truly closest-and-eligible.
		 */
		$manual_pick_providers = array();
		foreach ( $provider_ids as $provider_id ) {
			if ( ! custom_truelysell_is_restricted_provider( $provider_id ) ) {
				continue;
			}
			$user_info = get_userdata( $provider_id );
			if ( ! $user_info ) {
				continue;
			}
			$entry = array(
				'id'              => $provider_id,
				'name'            => $user_info->display_name,
				'avatar'          => get_avatar_url( $provider_id, array( 'size' => 60 ) ),
				'available_days'  => custom_truelysell_get_provider_listing_available_days( $provider_id, $listing_id ),
				'available_hours' => custom_truelysell_get_provider_listing_hours_map( $provider_id, $listing_id ),
			);

			$provider_lat = get_user_meta( $provider_id, 'profile-lat', true );
			$provider_lng = get_user_meta( $provider_id, 'profile-lng', true );
			if ( '' !== $provider_lat && '' !== $provider_lng ) {
				$entry['distance_miles'] = round( custom_truelysell_haversine_miles( $customer_lat, $customer_lng, $provider_lat, $provider_lng ), 1 );
			}

			$manual_pick_providers[] = $entry;
		}

		// Known distances first (closest first), unknown-distance ones last.
		usort( $manual_pick_providers, function ( $a, $b ) {
			$a_has = isset( $a['distance_miles'] );
			$b_has = isset( $b['distance_miles'] );
			if ( $a_has && $b_has ) {
				return $a['distance_miles'] <=> $b['distance_miles'];
			}
			return $a_has === $b_has ? 0 : ( $a_has ? -1 : 1 );
		} );

		// Same cap as the fully-eligible list below — keep this a real,
		// pickable list rather than showing every linked technician.
		$manual_pick_providers = array_slice( $manual_pick_providers, 0, apply_filters( 'truelysell_max_shown_providers', 5 ) );

		return array(
			'no_technicians_configured' => true,
			'manual_pick_providers'     => $manual_pick_providers,
			'diagnostics'               => $diagnostics,
		);
	}

	if ( empty( $eligible ) ) {
		return array( 'out_of_area' => true, 'diagnostics' => $diagnostics );
	}

	usort( $eligible, function ( $a, $b ) {
		$cmp = $a['distance'] <=> $b['distance'];
		return 0 !== $cmp ? $cmp : $a['id'] <=> $b['id'];
	} );

	$providers = array();
	foreach ( $eligible as $entry ) {
		$user_info = get_userdata( $entry['id'] );
		if ( ! $user_info ) {
			continue;
		}
		$providers[] = array(
			'id'              => $entry['id'],
			'name'            => $user_info->display_name,
			'avatar'          => get_avatar_url( $entry['id'], array( 'size' => 60 ) ),
			'available_days'  => custom_truelysell_get_provider_listing_available_days( $entry['id'], $listing_id ),
			'available_hours' => custom_truelysell_get_provider_listing_hours_map( $entry['id'], $listing_id ),
			'distance_miles'  => round( $entry['distance'], 1 ),
		);
	}

	if ( empty( $providers ) ) {
		return array( 'out_of_area' => true );
	}

	/*
	 * Cap how many technicians the customer sees, even though more may be
	 * genuinely eligible — this stays a real, pickable list as the
	 * business grows to dozens of technicians in one area, instead of an
	 * overwhelming wall of cards. Already sorted nearest-first, so this
	 * simply keeps the closest N and drops the rest for THIS customer's
	 * view; it has no effect on actual eligibility/assignment logic.
	 */
	$providers = array_slice( $providers, 0, apply_filters( 'truelysell_max_shown_providers', 5 ) );

	return array( 'providers' => $providers );
}

add_action( 'wp_ajax_custom_truelysell_get_service_providers', 'custom_truelysell_ajax_get_service_providers' );
add_action( 'wp_ajax_nopriv_custom_truelysell_get_service_providers', 'custom_truelysell_ajax_get_service_providers' );
function custom_truelysell_ajax_get_service_providers() {
	$listing_id = isset( $_POST['listing_id'] ) ? absint( $_POST['listing_id'] ) : 0;
	$lat        = isset( $_POST['lat'] ) ? (float) $_POST['lat'] : null;
	$lng        = isset( $_POST['lng'] ) ? (float) $_POST['lng'] : null;

	if ( ! $listing_id || null === $lat || null === $lng ) {
		wp_send_json_error( 'Missing listing or address.' );
	}

	$result       = custom_truelysell_find_nearest_eligible_provider( $listing_id, $lat, $lng );
	$admin_debug  = current_user_can( 'manage_options' ) ? ( $result['diagnostics'] ?? array() ) : array();

	if ( ! empty( $result['out_of_area'] ) ) {
		wp_send_json_success( array(
			'assigned' => false,
			'message'  => __( 'Sorry, we do not currently service your area.', 'truelysell' ),
			'debug'    => $admin_debug,
		) );
	}

	if ( ! empty( $result['no_technicians_configured'] ) ) {
		// Nobody linked to this service has finished their address/radius
		// setup, so real distance matching isn't possible yet — but if
		// there are actual technicians linked, let the customer pick one
		// directly rather than leaving it a mystery. Only falls all the
		// way back to a silent default (in the submission handler) if
		// there's truly nobody real to choose from either.
		wp_send_json_success( array(
			'assigned' => false,
			'fallback' => true,
			'providers' => $result['manual_pick_providers'] ?? array(),
			'debug'    => $admin_debug,
		) );
	}

	wp_send_json_success( array(
		'assigned'  => true,
		'providers' => $result['providers'],
	) );
}

// ============================================================
// AJAX HANDLER: customer_book_service
// ============================================================
/**
 * Hard server-side gate: rejects (via wp_send_json_error, which halts
 * execution) if $owner_id isn't available on the listing's $preferred_date
 * (day-of-week) or that day's saved time range. Shared by both the
 * geo-matched and manual-pick assignment paths in
 * custom_truelysell_ajax_book_service() below.
 */
function custom_truelysell_enforce_booking_time_or_fail( $owner_id, $listing_id, $preferred_date, $preferred_time ) {
	$weekday_map = array( 'Mon' => 'mon', 'Tue' => 'tue', 'Wed' => 'wed', 'Thu' => 'thu', 'Fri' => 'fri', 'Sat' => 'sat', 'Sun' => 'sun' );
	$day_key     = $weekday_map[ date( 'D', strtotime( $preferred_date ) ) ] ?? '';
	$avail_days  = custom_truelysell_get_provider_listing_available_days( $owner_id, $listing_id );

	if ( ! $day_key || ! in_array( $day_key, $avail_days, true ) ) {
		wp_send_json_error( __( 'Sorry, the selected technician is not available on that day. Please choose a different date, or pick a different technician.', 'truelysell' ) );
	}

	$hours = custom_truelysell_get_provider_listing_hours_for_day( $owner_id, $listing_id, $day_key );
	if ( $preferred_time < $hours['start'] || $preferred_time >= $hours['end'] ) {
		wp_send_json_error( __( 'Sorry, the selected technician is not available at that time. Please choose a different time, or pick a different technician.', 'truelysell' ) );
	}
}

add_action( 'wp_ajax_customer_book_service', 'custom_truelysell_ajax_book_service' );
function custom_truelysell_ajax_book_service() {

    // Security
    if ( ! check_ajax_referer( 'customer_booking_nonce', 'customer_booking_nonce_field', false ) ) {
        wp_send_json_error( 'Security check failed.' );
    }

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( 'Please login to book a service.' );
    }

    $user_id = get_current_user_id();

    // Sanitize inputs
    $listing_id   = absint( $_POST['listing_id'] ?? 0 );
    $first_name   = sanitize_text_field( $_POST['firstname'] ?? '' );
    $last_name    = sanitize_text_field( $_POST['lastname'] ?? '' );
    $email        = sanitize_email( $_POST['email'] ?? '' );
    $phone        = sanitize_text_field( $_POST['phone'] ?? '' );
    $message      = sanitize_textarea_field( $_POST['message'] ?? '' );
    $customer_address = sanitize_text_field( $_POST['customer_address'] ?? '' );
    $tv_quantity  = absint( $_POST['tv_quantity'] ?? 0 );
    // One size per TV — array_map/array_filter here rather than trusting
    // the client's array shape or length as-is.
    $tv_sizes_raw = isset( $_POST['tv_sizes'] ) && is_array( $_POST['tv_sizes'] ) ? $_POST['tv_sizes'] : array();
    $tv_sizes     = array_values( array_filter( array_map( 'floatval', $tv_sizes_raw ), function( $size ) {
        return $size > 0;
    } ) );

    /*
     * Required so the provider always has a starting point to plan
     * around — not checked against any real calendar/availability (that
     * whole slot system was removed), just a mandatory preference. The
     * provider still contacts the customer to confirm or adjust it.
     */
    $preferred_date = sanitize_text_field( $_POST['preferred_date'] ?? '' );
    $preferred_time = sanitize_text_field( $_POST['preferred_time'] ?? '' );

    if ( ! $listing_id ) {
        wp_send_json_error( 'Invalid service.' );
    }
    if ( ! $email || ! is_email( $email ) ) {
        wp_send_json_error( 'Please enter a valid email.' );
    }
    if ( ! $phone ) {
        wp_send_json_error( 'Please enter a phone number.' );
    }
    if ( ! $preferred_date || ! $preferred_time ) {
        wp_send_json_error( 'Please select a preferred date and time.' );
    }
    if ( $tv_quantity < 1 ) {
        wp_send_json_error( 'Please enter how many TVs need to be mounted.' );
    }
    if ( count( $tv_sizes ) !== $tv_quantity ) {
        wp_send_json_error( 'Please enter a size for each TV.' );
    }

    $listing_post = get_post( $listing_id );
    /*
     * Without this, any logged-in user could pass ANY post ID on the
     * site as listing_id — the booking would still insert, and the
     * notification email below would go to THAT post's author (any
     * WordPress user, not just providers) with attacker-controlled
     * message content. Require it to actually be a real, published
     * listing.
     */
    if ( ! $listing_post || 'listing' !== $listing_post->post_type || 'publish' !== $listing_post->post_status ) {
        wp_send_json_error( 'Service not found.' );
    }

    /*
     * The system auto-assigns the nearest technician whose own travel
     * radius (from their private home address) covers the customer's
     * service address — never the customer's own choice. Recompute this
     * ourselves server-side from the submitted lat/lng (never trust the
     * client's selected_provider_id alone).
     *
     * Fallback: if nobody linked to this listing has finished setting up
     * their address/radius yet, don't hard-block every booking on the
     * site over that — fall back to the pre-existing default
     * (_assigned_technician_id, else the listing's post_author).
     */
    $customer_lat = isset( $_POST['customer_lat'] ) && '' !== $_POST['customer_lat'] ? (float) $_POST['customer_lat'] : null;
    $customer_lng = isset( $_POST['customer_lng'] ) && '' !== $_POST['customer_lng'] ? (float) $_POST['customer_lng'] : null;

    if ( null === $customer_lat || null === $customer_lng ) {
        wp_send_json_error( 'Please select your service address from the suggestions.' );
    }

    $match = custom_truelysell_find_nearest_eligible_provider( $listing_id, $customer_lat, $customer_lng );

    if ( ! empty( $match['out_of_area'] ) ) {
        wp_send_json_error( __( 'Sorry, we do not currently service your area.', 'truelysell' ) );
    }

    if ( ! empty( $match['no_technicians_configured'] ) ) {
        /*
         * Nobody linked to this listing has finished their address/radius
         * setup, so real distance matching wasn't possible — but if there
         * ARE real technicians linked (manual_pick_providers), the
         * customer picked one directly from that list client-side. Trust
         * it only if it's genuinely one of those linked technicians.
         */
        $selected_provider_id = absint( $_POST['selected_provider_id'] ?? 0 );
        $manual_provider_ids  = wp_list_pluck( $match['manual_pick_providers'] ?? array(), 'id' );

        if ( $selected_provider_id && in_array( $selected_provider_id, $manual_provider_ids, true ) ) {
            $owner_id = $selected_provider_id;
            custom_truelysell_enforce_booking_time_or_fail( $owner_id, $listing_id, $preferred_date, $preferred_time );
        } else {
            // Truly nobody real to pick from — last-resort legacy default.
            $assigned_technician_id = absint( get_post_meta( $listing_id, '_assigned_technician_id', true ) );
            $owner_id               = $assigned_technician_id ? $assigned_technician_id : $listing_post->post_author;
        }
    } else {
        /*
         * The customer can pick any technician shown in the eligible list
         * (nearest is pre-selected/badged client-side) — trust that choice
         * as long as it's actually one of the technicians we just computed
         * as eligible ourselves. Never trust the client's name/distance,
         * only the ID, and only if it's still in today's eligible set.
         */
        $selected_provider_id  = absint( $_POST['selected_provider_id'] ?? 0 );
        $eligible_provider_ids = wp_list_pluck( $match['providers'], 'id' );
        if ( $selected_provider_id && in_array( $selected_provider_id, $eligible_provider_ids, true ) ) {
            $owner_id = $selected_provider_id;
        } else {
            $owner_id = $match['providers'][0]['id']; // nearest — already sorted
        }

        custom_truelysell_enforce_booking_time_or_fail( $owner_id, $listing_id, $preferred_date, $preferred_time );
    }

    // Get product ID linked to this listing
    $product_id = get_post_meta( $listing_id, '_product_id', true );

    // Base price × quantity, plus one surcharge for every individual TV
    // that's actually oversized — each TV is priced on its own size, not
    // a single size applied to all of them.
    $base_price       = custom_truelysell_get_listing_base_price( $listing_id );
    $oversized_count  = count( array_filter( $tv_sizes, function( $size ) {
        return $size > TRUELYSELL_CHILD_OVERSIZE_TV_THRESHOLD_INCHES;
    } ) );
    $normal_price     = ( $base_price * $tv_quantity ) + ( $oversized_count * TRUELYSELL_CHILD_OVERSIZE_TV_SURCHARGE );

    if ( ! $product_id || ! $normal_price || ! function_exists( 'wc_create_order' ) ) {
        wp_send_json_error( 'Online payment is not available for this service yet. Please contact us directly.' );
    }

    /*
     * A 20% deposit secures the booking; the remaining balance is collected
     * by the provider directly once the service is complete. The booking
     * itself is NOT created here — only once the deposit actually clears
     * (see custom_truelysell_create_booking_after_deposit_paid(), hooked to
     * woocommerce_payment_complete) — so nothing shows up as "booked" that
     * was never actually paid for.
     */
    $deposit_amount = round( $normal_price * 0.2, 2 );

    $args = array();
    $args['totals']['subtotal'] = $deposit_amount;
    $args['totals']['total']    = $deposit_amount;

    $order   = wc_create_order();
    /*
     * Quantity is the actual TV count, not hardcoded 1 — $args['totals']
     * above already carries the correct, already-multiplied deposit
     * amount for that quantity (WooCommerce uses the explicit totals
     * as-is here, it doesn't multiply them again by quantity), so this
     * only fixes the displayed "Qty" column to match what the customer
     * actually entered — it doesn't change the amount charged.
     */
    $item_id = $order->add_product( wc_get_product( $product_id ), $tv_quantity, $args );
    $item    = $item_id ? $order->get_item( $item_id ) : null;
    if ( $item ) {
        /*
         * Deliberately NOT using $item->get_name() (the underlying
         * WooCommerce product's own name) here — that product's name can
         * go stale and mismatch the listing's actual current title (e.g.
         * a listing whose linked product's name was set once during an
         * old duplicate-listing bug and never re-synced), which showed up
         * as checkout displaying a completely unrelated service's name.
         * The listing's own title is always correct, so use that instead.
         */
        /*
         * Kept short and on one line deliberately — the checkout/pay page
         * template (woocommerce/checkout/form-pay.php) has no word-wrap or
         * horizontal scroll on its items table, so an overly long name
         * here forces the table wider than the viewport and pushes the
         * Subtotal/Total column off-screen.
         */
        $item_name = get_the_title( $listing_id ) . ' — 20% Deposit';
        $details   = array();
        if ( $tv_quantity > 1 ) {
            $details[] = $tv_quantity . ' TVs';
        }
        if ( $oversized_count > 0 ) {
            $details[] = sprintf( '%d oversized (+%s ea)', $oversized_count, wp_strip_all_tags( wc_price( TRUELYSELL_CHILD_OVERSIZE_TV_SURCHARGE ) ) );
        }
        if ( $details ) {
            $item_name .= ' (' . implode( ', ', $details ) . ')';
        }
        $item->set_name( $item_name );
        $item->save();
    }

    $default_country = get_option( 'woocommerce_default_country' );
    $split_country    = explode( ':', $default_country );
    $country          = $split_country[0];

    $address = array(
        'first_name' => $first_name,
        'last_name'  => $last_name,
        'email'      => $email,
        'phone'      => $phone,
        'country'    => $country,
        'address_1'  => $customer_address,
    );
    $order->set_address( $address, 'billing' );
    $order->set_address( $address, 'shipping' );
    $order->set_customer_id( $user_id );
    $order->set_billing_email( $email );

    // Stash everything needed to create the real booking once the deposit
    // is actually paid.
    $order->update_meta_data( '_truelysell_pending_booking', 1 );
    $order->update_meta_data( '_truelysell_listing_id', $listing_id );
    $order->update_meta_data( '_truelysell_owner_id', $owner_id );
    $order->update_meta_data( '_truelysell_customer_id', $user_id );
    $order->update_meta_data( '_truelysell_first_name', $first_name );
    $order->update_meta_data( '_truelysell_last_name', $last_name );
    $order->update_meta_data( '_truelysell_email', $email );
    $order->update_meta_data( '_truelysell_phone', $phone );
    $order->update_meta_data( '_truelysell_message', $message );
    $order->update_meta_data( '_truelysell_customer_address', $customer_address );
    $order->update_meta_data( '_truelysell_customer_lat', $customer_lat );
    $order->update_meta_data( '_truelysell_customer_lng', $customer_lng );
    $order->update_meta_data( '_truelysell_preferred_date', $preferred_date );
    $order->update_meta_data( '_truelysell_preferred_time', $preferred_time );
    $order->update_meta_data( '_truelysell_full_price', $normal_price );
    $order->update_meta_data( '_truelysell_deposit_amount', $deposit_amount );
    $order->update_meta_data( '_truelysell_tv_quantity', $tv_quantity );
    $order->update_meta_data( '_truelysell_tv_sizes', implode( ',', $tv_sizes ) );
    $order->update_meta_data( '_truelysell_tv_oversized_count', $oversized_count );
    $order->update_meta_data( '_truelysell_oversize_tv_surcharge', $oversized_count * TRUELYSELL_CHILD_OVERSIZE_TV_SURCHARGE );

    $order->calculate_totals();
    $order->save();

    wp_send_json_success( array(
        'order_id'     => $order->get_id(),
        'redirect_url' => $order->get_checkout_payment_url(),
        'message'      => 'Redirecting to payment...',
    ) );
}

/**
 * Fires when a customer's 20% deposit payment actually clears. Hooked to
 * BOTH woocommerce_payment_complete (what most gateways, incl. Stripe, call
 * on success) AND woocommerce_order_status_changed (fires on every status
 * transition regardless of how the gateway itself is implemented) as a
 * safety net — custom_truelysell_finalize_deposit_booking() is idempotent
 * (guarded by _truelysell_booking_created) so being called from two hooks
 * for the same order is harmless.
 *
 * IMPORTANT: the order_status_changed hook below is deliberately given
 * priority 5, EARLIER than the plugin's own Truelysell_Core_Commissions::
 * order_status_change() (registered at default priority 10 when the
 * plugin loads, i.e. before this file even runs). Both listen to the same
 * event — if the plugin's callback ran first, it would read owner_id/
 * booking_id/listing_id from order meta that this code hasn't written yet
 * (this function writes it), register a broken zero-value commission, AND
 * mark the order as "commissions processed" — permanently blocking this
 * function's own, correct commission registration. Running earlier avoids
 * that entirely: by the time the plugin's callback runs in the same
 * dispatch, the correct commission is already registered and the plugin
 * sees "already processed" and skips.
 */
add_action( 'woocommerce_payment_complete', 'custom_truelysell_create_booking_after_deposit_paid' );
function custom_truelysell_create_booking_after_deposit_paid( $order_id ) {
    custom_truelysell_finalize_deposit_booking( $order_id, 'woocommerce_payment_complete' );
}

add_action( 'woocommerce_order_status_changed', 'custom_truelysell_create_booking_on_status_change', 5, 3 );
function custom_truelysell_create_booking_on_status_change( $order_id, $old_status, $new_status ) {
    if ( in_array( $new_status, array( 'processing', 'completed' ), true ) ) {
        custom_truelysell_finalize_deposit_booking( $order_id, 'woocommerce_order_status_changed (' . $old_status . ' -> ' . $new_status . ')' );
    }
}

function custom_truelysell_finalize_deposit_booking( $order_id, $trigger ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    // Always leave a trace so this is debuggable straight from the order
    // screen — this note appears even for orders that turn out not to be
    // ours, which is exactly what tells us whether the hook is firing at
    // all vs. the pending-booking meta never having been saved.
    $order->add_order_note( sprintf(
        'Truelysell: finalize check triggered by %s (pending_booking meta = %s, booking_created meta = %s).',
        $trigger,
        $order->get_meta( '_truelysell_pending_booking' ) ? 'yes' : 'no',
        $order->get_meta( '_truelysell_booking_created' ) ? 'yes' : 'no'
    ) );

    if ( ! $order->get_meta( '_truelysell_pending_booking' ) ) {
        // Not one of ours (e.g. a normal WooCommerce/native TruelySell order) — ignore.
        return;
    }

    if ( $order->get_meta( '_truelysell_booking_created' ) ) {
        return;
    }

    $listing_id     = absint( $order->get_meta( '_truelysell_listing_id' ) );
    $owner_id       = absint( $order->get_meta( '_truelysell_owner_id' ) );
    $user_id        = absint( $order->get_meta( '_truelysell_customer_id' ) );
    $first_name     = $order->get_meta( '_truelysell_first_name' );
    $last_name      = $order->get_meta( '_truelysell_last_name' );
    $email          = $order->get_meta( '_truelysell_email' );
    $phone          = $order->get_meta( '_truelysell_phone' );
    $message        = $order->get_meta( '_truelysell_message' );
    $customer_address = $order->get_meta( '_truelysell_customer_address' );
    $preferred_date = $order->get_meta( '_truelysell_preferred_date' );
    $preferred_time = $order->get_meta( '_truelysell_preferred_time' );
    $full_price     = (float) $order->get_meta( '_truelysell_full_price' );
    $deposit_amount = (float) $order->get_meta( '_truelysell_deposit_amount' );
    $tv_quantity    = absint( $order->get_meta( '_truelysell_tv_quantity' ) );
    $tv_sizes       = array_filter( array_map( 'floatval', explode( ',', (string) $order->get_meta( '_truelysell_tv_sizes' ) ) ) );
    $oversized_count = absint( $order->get_meta( '_truelysell_tv_oversized_count' ) );
    $oversize_surcharge = (float) $order->get_meta( '_truelysell_oversize_tv_surcharge' );

    if ( ! $listing_id || ! $owner_id || ! $user_id ) {
        $order->add_order_note( sprintf(
            'Truelysell: deposit paid but booking NOT created — missing required data (listing_id=%d, owner_id=%d, user_id=%d).',
            $listing_id, $owner_id, $user_id
        ) );
        return;
    }

    $comment_data = serialize( array(
        'services'         => array(),
        'customer_details' => array(
            'first_name'     => $first_name,
            'last_name'      => $last_name,
            'email'          => $email,
            'phone'          => $phone,
            'message'        => $message,
            'preferred_date' => $preferred_date,
            'preferred_time' => $preferred_time,
            'deposit_paid'   => $deposit_amount,
            'total_price'    => $full_price,
            'tv_quantity'    => $tv_quantity,
            'tv_sizes'       => $tv_sizes,
            'oversized_count' => $oversized_count,
            'oversize_tv_surcharge' => $oversize_surcharge,
            'address'        => array(
                'billing_address_1' => $customer_address,
                'billing_city'      => '',
                'billing_state'     => '',
                'billing_postcode'  => '',
            ),
        ),
    ) );

    $now = current_time( 'mysql' );

    /*
     * Just a preference, not a real validated slot (that system was
     * removed) — but use it for date_start/date_end when given, so the
     * "Booking Date" column in dashboards shows something meaningful
     * instead of the moment the request came in.
     */
    $booking_datetime = $now;
    if ( $preferred_date && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $preferred_date ) ) {
        $time_part        = ( $preferred_time && preg_match( '/^\d{2}:\d{2}$/', $preferred_time ) ) ? $preferred_time . ':00' : '00:00:00';
        $booking_datetime = $preferred_date . ' ' . $time_part;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'bookings_calendar';

    $inserted = $wpdb->insert(
        $table,
        array(
            'bookings_author' => $user_id,
            'owner_id'        => $owner_id,
            'listing_id'      => $listing_id,
            'staff_id'        => 0,
            'date_start'      => $booking_datetime,
            'date_end'        => $booking_datetime,
            'comment'         => $comment_data,
            /*
             * By the time this runs, the 20% deposit has already cleared
             * (this only fires from woocommerce_payment_complete), so the
             * booking should never show up as "just-booked"/pending —
             * that status is even filtered out of the native provider
             * booking list entirely. 'paid' is what the native plugin
             * template (and this theme's own booking-list views) render
             * as a green "Approved"/"Paid" badge.
             */
            'status'          => 'paid',
            'type'            => 'reservation',
            'created'         => $now,
            'price'           => $full_price,
            'order_id'        => $order_id,
        ),
        array( '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%d' )
    );

    if ( ! $inserted ) {
        $order->add_order_note( 'Truelysell: deposit paid but booking INSERT into bookings_calendar failed — DB error: ' . $wpdb->last_error );
        return;
    }

    $booking_id = $wpdb->insert_id;
    $order->add_order_note( sprintf( 'Truelysell: booking #%d created after 20%% deposit payment (listing #%d, owner #%d).', $booking_id, $listing_id, $owner_id ) );

    $order->update_meta_data( '_truelysell_booking_created', 1 );
    $order->update_meta_data( 'booking_id', $booking_id );
    $order->update_meta_data( 'owner_id', $owner_id );
    $order->update_meta_data( 'listing_id', $listing_id );
    $order->save_meta_data();

    custom_truelysell_register_deposit_commission( $order, $owner_id, $booking_id, $listing_id, $deposit_amount );

    $ghl_owner_info = get_userdata( $owner_id );
    custom_truelysell_send_ghl_webhook(
        TRUELYSELL_CHILD_GHL_WEBHOOK_BOOKING_URL,
        'new_booking',
        array(
            'booking_id'       => $booking_id,
            'order_id'         => $order_id,
            'listing_id'       => $listing_id,
            'service_name'     => get_the_title( $listing_id ),
            'customer_name'    => trim( $first_name . ' ' . $last_name ),
            'customer_email'   => $email,
            'customer_phone'   => $phone,
            'customer_address' => $customer_address,
            'technician_name'  => $ghl_owner_info ? $ghl_owner_info->display_name : '',
            'technician_email' => $ghl_owner_info ? $ghl_owner_info->user_email : '',
            'preferred_date'   => $preferred_date,
            'preferred_time'   => $preferred_time,
            'full_price'       => $full_price,
            'deposit_amount'   => $deposit_amount,
            'tv_quantity'      => $tv_quantity,
            'tv_sizes'         => implode( ',', $tv_sizes ),
        )
    );

    $remaining_balance = $full_price - $deposit_amount;

    $preferred_text = '';
    if ( $preferred_date ) {
        $preferred_text = date_i18n( 'M j, Y', strtotime( $preferred_date ) );
        if ( $preferred_time ) {
            $preferred_text .= ' ' . date_i18n( 'g:i A', strtotime( $preferred_date . ' ' . $preferred_time ) );
        }
    } else {
        $preferred_text = 'Not specified';
    }

    $tv_sizes_display = implode( '", ', array_map( function( $size ) {
        return rtrim( rtrim( number_format( $size, 1 ), '0' ), '.' );
    }, $tv_sizes ) ) . '"';
    $tv_details_text = sprintf( '%d TV(s): %s', $tv_quantity, $tv_sizes_display );
    if ( $oversize_surcharge > 0 ) {
        $tv_details_text .= sprintf( ' (%d oversized, includes %s surcharge)', $oversized_count, wp_strip_all_tags( wc_price( $oversize_surcharge ) ) );
    }

    /*
     * The theme has fully configurable "Booking paid notification to
     * owner" / "Booking paid confirmation to user" email templates
     * (Theme Options — see inc/options-init.php), but like the Welcome
     * Email, nothing wires them up on this site since bookings are
     * created by this custom deposit flow, not the plugin's own native
     * booking code. If the admin has enabled + written one of these,
     * use it (with its own documented tags); otherwise fall back to the
     * plain-text email this already sent before, so nothing regresses
     * for an admin who's never touched these settings.
     */
    $email_tags = array(
        '{user_mail}'      => $email,
        '{user_name}'      => trim( $first_name . ' ' . $last_name ),
        '{booking_date}'   => $preferred_text,
        '{dates}'          => $preferred_text,
        '{listing_name}'   => get_the_title( $listing_id ),
        '{listing_url}'    => get_permalink( $listing_id ),
        '{listing_address}' => function_exists( 'custom_truelysell_get_listing_location_text' ) ? custom_truelysell_get_listing_location_text( $listing_id ) : '',
        '{site_name}'      => get_bloginfo( 'name' ),
        '{site_link}'      => home_url(),
        '{details}'        => $tv_details_text,
        '{payment_url}'    => '',
        '{expiration}'     => '',
    );

    // Notify the owner/technician
    $owner_info = get_userdata( $owner_id );
    if ( $owner_info && is_email( $owner_info->user_email ) ) {
        $owner_tags = $email_tags;
        $owner_tags['{listing_phone}'] = get_user_meta( $owner_id, 'phone', true );
        $owner_tags['{listing_email}'] = $owner_info->user_email;

        $fallback_subject = sprintf( __( 'New Booking (Deposit Paid): %s', 'truelysell' ), get_the_title( $listing_id ) );
        $fallback_body    = sprintf(
            "A new booking has been made and the 20%% deposit has been paid.\n\nService: %s\nTVs: %s\nCustomer: %s %s (%s)\nPhone: %s\nService Address: %s\nPreferred Date/Time: %s\nMessage: %s\n\nDeposit Paid: %s\nRemaining Balance (collect from customer): %s\n\nPlease contact the customer to confirm or adjust the time.\n\nBooking ID: #%d",
            get_the_title( $listing_id ),
            $tv_details_text,
            $first_name, $last_name, $email,
            $phone, ( $customer_address ?: 'Not provided' ), $preferred_text, $message,
            wp_strip_all_tags( wc_price( $deposit_amount ) ), wp_strip_all_tags( wc_price( $remaining_balance ) ),
            $booking_id
        );

        custom_truelysell_send_booking_email_with_theme_override(
            $owner_info->user_email,
            'paid_booking_confirmation',
            'paid_booking_confirmation_email_subject',
            'paid_booking_confirmation_email_content',
            $owner_tags,
            $fallback_subject,
            $fallback_body
        );
    }

    // Confirmation to the customer
    if ( is_email( $email ) ) {
        $technician_name = $owner_info ? $owner_info->display_name : __( 'a technician', 'truelysell' );

        $customer_tags = $email_tags;
        $customer_tags['{listing_phone}'] = $owner_info ? get_user_meta( $owner_id, 'phone', true ) : '';
        $customer_tags['{listing_email}'] = $owner_info ? $owner_info->user_email : '';

        $fallback_subject = sprintf( __( 'Booking Confirmation: %s', 'truelysell' ), get_the_title( $listing_id ) );
        $fallback_body    = sprintf(
            "Thank you for your booking!\n\nService: %s\nTechnician: %s\nPreferred Date/Time: %s\nDeposit Paid: %s\nRemaining Balance Due: %s\n\nBooking ID: #%d\n\n%s will contact you shortly to confirm the time.",
            get_the_title( $listing_id ),
            $technician_name,
            $preferred_text,
            wp_strip_all_tags( wc_price( $deposit_amount ) ), wp_strip_all_tags( wc_price( $remaining_balance ) ),
            $booking_id,
            $technician_name
        );

        custom_truelysell_send_booking_email_with_theme_override(
            $email,
            'user_paid_booking_confirmation',
            'user_paid_booking_confirmation_email_subject',
            'user_paid_booking_confirmation_email_content',
            $customer_tags,
            $fallback_subject,
            $fallback_body
        );
    }
}

/**
 * Sends to $to using the theme's own configured Theme Options email
 * template (identified by its enable/subject/content option IDs — see
 * inc/options-init.php) when the admin has actually turned it on and
 * written both a subject and content; otherwise sends the given plain
 * fallback instead, so a booking notification is always sent even if
 * the admin has never touched these settings.
 */
function custom_truelysell_send_booking_email_with_theme_override( $to, $enable_option_id, $subject_option_id, $content_option_id, $tags, $fallback_subject, $fallback_body ) {
    $enabled            = function_exists( 'truelysell_fl_framework_getoptions' ) && truelysell_fl_framework_getoptions( $enable_option_id );
    $configured_subject = $enabled ? truelysell_fl_framework_getoptions( $subject_option_id ) : '';
    $configured_content = $enabled ? truelysell_fl_framework_getoptions( $content_option_id ) : '';

    if ( $configured_subject && $configured_content ) {
        wp_mail(
            $to,
            strtr( $configured_subject, $tags ),
            strtr( $configured_content, $tags ),
            array( 'Content-Type: text/html; charset=UTF-8' )
        );
        return;
    }

    wp_mail( $to, $fallback_subject, $fallback_body );
}

/**
 * Registers this deposit payment in the plugin's own commission/payout
 * ledger (wp_truelysell_core_commissions — what [truelysell_wallet] /
 * [truelysell_payout] and the admin Payouts screen read from), instead of
 * relying on the plugin's own Truelysell_Core_Commissions::order_status_change()
 * hook to do it natively.
 *
 * Why this is necessary: that native hook also listens on
 * woocommerce_order_status_changed, registered when the plugin loads —
 * i.e. BEFORE this theme's own hook on the same action, since plugins load
 * before the theme. WordPress fires same-priority callbacks in
 * registration order, so on the same status-change event the plugin's
 * register_commission() runs and reads get_post_meta($order_id,'owner_id'
 * /'booking_id'/'listing_id') BEFORE this function has had a chance to
 * write that meta — so it registers a commission with a blank/0 user_id
 * and the provider's earning never shows up. Registering it directly here,
 * with the values already in hand, avoids that race entirely (and also
 * sidesteps register_commission() reading via get_post_meta(), which
 * would silently find nothing at all on a site using WooCommerce's
 * newer HPOS custom order-tables storage).
 *
 * The commission is based on the 20% deposit only (not the full listing
 * price) — Payout represents money that actually moved through the
 * platform; the remaining balance is cash the provider collects directly
 * and was never platform money.
 */
function custom_truelysell_register_deposit_commission( $order, $owner_id, $booking_id, $listing_id, $deposit_amount ) {
	if ( ! class_exists( 'Truelysell_Core_Commissions' ) ) {
		return;
	}

	if ( 'yes' === $order->get_meta( '_truelysell_commissions_processed' ) ) {
		return;
	}

	$rate = function_exists( 'truelysell_fl_framework_getoptions' ) ? (float) truelysell_fl_framework_getoptions( 'commission_rate' ) / 100 : 0;

	$commission_id = Truelysell_Core_Commissions::instance()->insert_commission( array(
		'order_id'   => $order->get_id(),
		'user_id'    => $owner_id,
		'booking_id' => $booking_id,
		'listing_id' => $listing_id,
		'rate'       => $rate,
		'status'     => 'unpaid',
		'amount'     => (float) $deposit_amount * $rate,
		'type'       => 'percentage',
	) );

	if ( $commission_id ) {
		$order->update_meta_data( '_truelysell_commissions_id', $commission_id );
		$order->update_meta_data( '_truelysell_commissions_processed', 'yes' );
		$order->save_meta_data();
		$order->add_order_note( sprintf( 'Truelysell: commission #%d registered (rate %s%%, on 20%% deposit of %s).', $commission_id, $rate * 100, wp_strip_all_tags( wc_price( $deposit_amount ) ) ) );
	} else {
		$order->add_order_note( 'Truelysell: FAILED to register commission — insert_commission() did not return an ID.' );
	}
}

// ============================================================
// REMAINING BALANCE (80%) — MUTUAL JOB-COMPLETE CONFIRMATION + SETTLEMENT
// ------------------------------------------------------------------
// The 20% deposit is the only amount that flows through the platform's
// own PayPal at booking time — the remaining balance was, until now,
// always cash the provider collects directly (see
// custom_truelysell_register_deposit_commission() above). This adds an
// online path for that remaining balance, gated behind a mutual
// confirmation step so neither side can unilaterally force it:
//   1. Technician clicks "Mark Job Complete" on their booking row.
//   2. Customer is notified and must separately click "Confirm Job
//      Complete" themselves.
//   3. Only once BOTH have confirmed can the customer click "Pay
//      Remaining Balance" — a second, full-value WooCommerce order is
//      created for the exact remaining amount and they're sent to
//      PayPal to pay it themselves (no cash option — per the site
//      owner, customers essentially never pay cash in practice).
// That payment marks the booking fully settled. Per the site owner's
// explicit choice, the platform takes NO commission on this remaining
// amount either way — same as cash always was, the full amount is
// registered as a 100%-rate commission so it lands in the technician's
// own payout balance (the existing admin Payout screen pays it out),
// it just also happens to have moved through the platform's PayPal
// this time instead of being handed over in person.
// ============================================================

/**
 * All of this feature's state lives as meta on the ORIGINAL deposit
 * order (found via the booking row's own `order_id` column) — never a
 * new custom table, so it reuses the exact same WC order meta pattern
 * (and HPOS-safety) as custom_truelysell_finalize_deposit_booking().
 */
function custom_truelysell_get_job_completion_state( $order ) {
	$technician_marked = (bool) $order->get_meta( '_truelysell_job_marked_complete_by_technician' );
	$customer_confirmed = (bool) $order->get_meta( '_truelysell_job_confirmed_complete_by_customer' );
	$remaining_settled  = (bool) $order->get_meta( '_truelysell_remaining_settled' );

	return array(
		'technician_marked_complete' => $technician_marked,
		'customer_confirmed_complete' => $customer_confirmed,
		'both_confirmed'             => $technician_marked && $customer_confirmed,
		'remaining_settled'          => $remaining_settled,
		'remaining_payment_method'   => $order->get_meta( '_truelysell_remaining_payment_method' ),
		'remaining_order_id'         => absint( $order->get_meta( '_truelysell_remaining_order_id' ) ),
		'remaining_amount'           => (float) $order->get_meta( '_truelysell_full_price' ) - (float) $order->get_meta( '_truelysell_deposit_amount' ),
	);
}

/**
 * Bridges a `bookings_calendar` row to the state above. Deliberately
 * defined here in the child theme (not inc/template-tags.php, even
 * though that's where the customer/provider bookings shortcodes that
 * call it live) so this whole feature only ever depends on ONE file
 * being uploaded — this one. Splitting it across the parent theme's
 * inc/template-tags.php and this file caused real deployment confusion:
 * this file could be fully up to date while template-tags.php lagged
 * behind, silently making every "Remaining Balance" column show "—"
 * with no visible error.
 */
if ( ! function_exists( 'truelysell_get_booking_job_completion_state' ) ) {
	function truelysell_get_booking_job_completion_state( $booking ) {
		// Only ever relevant while the booking is actually in force — a
		// cancelled/expired booking has no remaining balance to settle.
		if ( ! in_array( $booking->status, array( 'paid', 'confirmed' ), true ) ) {
			return null;
		}

		if ( empty( $booking->order_id ) || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( absint( $booking->order_id ) );
		if ( ! $order || ! $order->get_meta( '_truelysell_booking_created' ) ) {
			return null;
		}

		return custom_truelysell_get_job_completion_state( $order );
	}
}

/**
 * The booking's `comment` column is a serialize()'d array written by
 * custom_truelysell_finalize_deposit_booking() above, with shape
 * ['customer_details' => ['message' => ..., 'deposit_paid' => ...,
 * 'tv_quantity' => ..., 'tv_sizes' => [...], ...]]. Decode it defensively —
 * older/plugin-native rows may have a different shape or not be
 * serialized at all. Defined here (not inc/template-tags.php, even
 * though the provider/customer bookings shortcodes that call it live
 * there) for the same one-file-to-upload reason as the function above.
 */
if ( ! function_exists( 'truelysell_get_booking_customer_details' ) ) {
	function truelysell_get_booking_customer_details( $booking ) {
		if ( empty( $booking->comment ) || ! is_serialized( $booking->comment ) ) {
			return array();
		}

		$data = @unserialize( $booking->comment, array( 'allowed_classes' => false ) );

		return ( is_array( $data ) && ! empty( $data['customer_details'] ) && is_array( $data['customer_details'] ) )
			? $data['customer_details']
			: array();
	}
}

/**
 * "2 TV(s): 50", 75"" — or '' if this booking has no per-TV size data
 * (e.g. it predates this feature, or isn't a deposit-flow booking at
 * all). Shared by every place that displays a booking's TV details.
 */
if ( ! function_exists( 'truelysell_format_booking_tv_details' ) ) {
	function truelysell_format_booking_tv_details( $customer_details ) {
		$quantity = ! empty( $customer_details['tv_quantity'] ) ? absint( $customer_details['tv_quantity'] ) : 0;
		$sizes    = ! empty( $customer_details['tv_sizes'] ) && is_array( $customer_details['tv_sizes'] ) ? $customer_details['tv_sizes'] : array();

		if ( ! $quantity || empty( $sizes ) ) {
			return '';
		}

		$sizes_text = implode( '", ', array_map( function( $size ) {
			return rtrim( rtrim( number_format( (float) $size, 1 ), '0' ), '.' );
		}, $sizes ) ) . '"';

		return sprintf( '%d TV(s): %s', $quantity, $sizes_text );
	}
}

/**
 * Same booking-row -> order resolution every one of these handlers
 * needs: load the booking, confirm the deposit was actually finalized
 * (booking_created meta), and load the order it belongs to.
 */
function custom_truelysell_load_booking_and_order_for_completion( $booking_id ) {
	if ( ! function_exists( 'custom_truelysell_get_booking_by_id' ) ) {
		return array( null, null );
	}

	$booking = custom_truelysell_get_booking_by_id( $booking_id );
	if ( ! $booking || empty( $booking->order_id ) ) {
		return array( null, null );
	}

	$order = wc_get_order( absint( $booking->order_id ) );
	if ( ! $order || ! $order->get_meta( '_truelysell_booking_created' ) ) {
		return array( null, null );
	}

	return array( $booking, $order );
}

add_action( 'wp_ajax_truelysell_mark_job_complete', 'custom_truelysell_ajax_mark_job_complete' );
function custom_truelysell_ajax_mark_job_complete() {
	if ( ! check_ajax_referer( 'truelysell_job_completion_nonce', 'nonce', false ) ) {
		wp_send_json_error( 'Security check failed.' );
	}
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( 'Please log in.' );
	}

	$booking_id = absint( $_POST['booking_id'] ?? 0 );
	list( $booking, $order ) = custom_truelysell_load_booking_and_order_for_completion( $booking_id );
	if ( ! $booking || ! $order ) {
		wp_send_json_error( 'Booking not found.' );
	}

	// Only the assigned technician for this exact booking may mark it complete.
	if ( absint( $booking->owner_id ) !== get_current_user_id() ) {
		wp_send_json_error( 'You are not the assigned technician for this booking.' );
	}

	$state = custom_truelysell_get_job_completion_state( $order );
	if ( $state['remaining_settled'] ) {
		wp_send_json_error( 'This booking is already fully settled.' );
	}
	if ( $state['technician_marked_complete'] ) {
		wp_send_json_success( array( 'message' => 'Already marked complete.' ) );
	}

	$order->update_meta_data( '_truelysell_job_marked_complete_by_technician', 1 );
	$order->update_meta_data( '_truelysell_job_marked_complete_by_technician_time', current_time( 'mysql' ) );
	$order->save_meta_data();
	$order->add_order_note( 'Truelysell: technician marked the job complete — awaiting customer confirmation.' );

	$customer_email = $order->get_meta( '_truelysell_email' );
	if ( is_email( $customer_email ) ) {
		$technician_name = get_userdata( $booking->owner_id );
		wp_mail(
			$customer_email,
			sprintf( __( '%s says your service is complete', 'truelysell' ), $technician_name ? $technician_name->display_name : __( 'Your technician', 'truelysell' ) ),
			sprintf(
				"%s has marked booking #%d as complete.\n\nIf the work is done to your satisfaction, please log in and confirm completion so you can settle the remaining balance.\n\nBooking ID: #%d",
				$technician_name ? $technician_name->display_name : __( 'Your technician', 'truelysell' ),
				$booking_id, $booking_id
			)
		);
	}

	wp_send_json_success( array( 'message' => __( 'Marked complete. The customer has been notified to confirm.', 'truelysell' ) ) );
}

add_action( 'wp_ajax_truelysell_confirm_job_complete', 'custom_truelysell_ajax_confirm_job_complete' );
function custom_truelysell_ajax_confirm_job_complete() {
	if ( ! check_ajax_referer( 'truelysell_job_completion_nonce', 'nonce', false ) ) {
		wp_send_json_error( 'Security check failed.' );
	}
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( 'Please log in.' );
	}

	$booking_id = absint( $_POST['booking_id'] ?? 0 );
	list( $booking, $order ) = custom_truelysell_load_booking_and_order_for_completion( $booking_id );
	if ( ! $booking || ! $order ) {
		wp_send_json_error( 'Booking not found.' );
	}

	// Only the customer who made this exact booking may confirm it.
	if ( absint( $booking->bookings_author ) !== get_current_user_id() ) {
		wp_send_json_error( 'This is not your booking.' );
	}

	$state = custom_truelysell_get_job_completion_state( $order );
	if ( $state['remaining_settled'] ) {
		wp_send_json_error( 'This booking is already fully settled.' );
	}
	if ( ! $state['technician_marked_complete'] ) {
		wp_send_json_error( 'The technician has not marked this job complete yet.' );
	}
	if ( $state['customer_confirmed_complete'] ) {
		wp_send_json_success( array( 'message' => 'Already confirmed.', 'remaining_amount' => $state['remaining_amount'] ) );
	}

	$order->update_meta_data( '_truelysell_job_confirmed_complete_by_customer', 1 );
	$order->update_meta_data( '_truelysell_job_confirmed_complete_by_customer_time', current_time( 'mysql' ) );
	$order->save_meta_data();
	$order->add_order_note( 'Truelysell: customer confirmed the job is complete — remaining balance can now be settled.' );

	$owner_info = get_userdata( $booking->owner_id );
	if ( $owner_info && is_email( $owner_info->user_email ) ) {
		wp_mail(
			$owner_info->user_email,
			sprintf( __( 'Customer confirmed booking #%d is complete', 'truelysell' ), $booking_id ),
			sprintf( "The customer confirmed booking #%d is complete. They can now pay the remaining balance online.\n\nBooking ID: #%d", $booking_id, $booking_id )
		);
	}

	wp_send_json_success( array(
		'message'          => __( 'Confirmed. You can now pay the remaining balance.', 'truelysell' ),
		'remaining_amount' => $state['remaining_amount'],
	) );
}

/**
 * Creates (or reuses an existing unpaid) remaining-balance order for a
 * booking whose deposit order + booking row are already known — shared
 * by the customer-facing "Pay Remaining Balance" AJAX handler below AND
 * the admin testing tool further down, so there's exactly one place that
 * knows how to build this order correctly.
 *
 * @param WC_Order $order       The original deposit order.
 * @param object   $booking     The bookings_calendar row.
 * @param int      $customer_id Who the WC order's customer should be —
 *                               the real customer normally, but the admin
 *                               tool passes its own ID since it's building
 *                               this on the customer's behalf for testing.
 * @return WC_Order|WP_Error
 */
function custom_truelysell_create_or_reuse_remaining_balance_order( $order, $booking, $customer_id ) {
	$state = custom_truelysell_get_job_completion_state( $order );

	if ( $state['remaining_order_id'] ) {
		$existing_remaining_order = wc_get_order( $state['remaining_order_id'] );
		if ( $existing_remaining_order && ! $existing_remaining_order->is_paid() && 'cancelled' !== $existing_remaining_order->get_status() ) {
			return $existing_remaining_order;
		}
	}

	$listing_id       = absint( $order->get_meta( '_truelysell_listing_id' ) );
	$product_id       = get_post_meta( $listing_id, '_product_id', true );
	$remaining_amount = $state['remaining_amount'];

	if ( ! $product_id || $remaining_amount <= 0 || ! function_exists( 'wc_create_order' ) ) {
		return new WP_Error( 'truelysell_remaining_order_failed', __( 'Unable to create the remaining-balance payment right now.', 'truelysell' ) );
	}

	$args                       = array();
	$args['totals']['subtotal'] = $remaining_amount;
	$args['totals']['total']    = $remaining_amount;

	// Same TV quantity as the original deposit order, for a consistent
	// "Qty" display — $args['totals'] above is already the correct
	// already-multiplied remaining amount, so this doesn't change it.
	$tv_quantity_for_line = max( 1, absint( $order->get_meta( '_truelysell_tv_quantity' ) ) );

	$remaining_order = wc_create_order();
	$item_id         = $remaining_order->add_product( wc_get_product( $product_id ), $tv_quantity_for_line, $args );
	$item            = $item_id ? $remaining_order->get_item( $item_id ) : null;
	if ( $item ) {
		$item->set_name( get_the_title( $listing_id ) . ' — ' . __( 'Remaining Balance', 'truelysell' ) );
		$item->save();
	}

	$address = array(
		'first_name' => $order->get_meta( '_truelysell_first_name' ),
		'last_name'  => $order->get_meta( '_truelysell_last_name' ),
		'email'      => $order->get_meta( '_truelysell_email' ),
		'phone'      => $order->get_meta( '_truelysell_phone' ),
		'country'    => $order->get_billing_country(),
		'address_1'  => $order->get_meta( '_truelysell_customer_address' ),
	);
	$remaining_order->set_address( $address, 'billing' );
	$remaining_order->set_address( $address, 'shipping' );
	$remaining_order->set_customer_id( $customer_id );
	$remaining_order->set_billing_email( $address['email'] );

	// Links back to the original deposit order/booking so the payment-
	// complete hook below knows exactly what this order is settling.
	$remaining_order->update_meta_data( '_truelysell_remaining_of_order_id', $order->get_id() );
	$remaining_order->update_meta_data( '_truelysell_booking_id', $booking->ID );
	$remaining_order->update_meta_data( '_truelysell_listing_id', $listing_id );
	$remaining_order->update_meta_data( '_truelysell_owner_id', $booking->owner_id );
	$remaining_order->calculate_totals();
	$remaining_order->save();

	$order->update_meta_data( '_truelysell_remaining_order_id', $remaining_order->get_id() );
	$order->save_meta_data();
	$order->add_order_note( sprintf( 'Truelysell: remaining-balance order #%d created.', $remaining_order->get_id() ) );

	return $remaining_order;
}

add_action( 'wp_ajax_truelysell_pay_remaining_balance', 'custom_truelysell_ajax_pay_remaining_balance' );
function custom_truelysell_ajax_pay_remaining_balance() {
	if ( ! check_ajax_referer( 'truelysell_job_completion_nonce', 'nonce', false ) ) {
		wp_send_json_error( 'Security check failed.' );
	}
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( 'Please log in.' );
	}

	$booking_id = absint( $_POST['booking_id'] ?? 0 );
	list( $booking, $order ) = custom_truelysell_load_booking_and_order_for_completion( $booking_id );
	if ( ! $booking || ! $order ) {
		wp_send_json_error( 'Booking not found.' );
	}

	if ( absint( $booking->bookings_author ) !== get_current_user_id() ) {
		wp_send_json_error( 'This is not your booking.' );
	}

	$state = custom_truelysell_get_job_completion_state( $order );
	if ( ! $state['both_confirmed'] ) {
		wp_send_json_error( 'Both you and the technician need to confirm the job is complete first.' );
	}
	if ( $state['remaining_settled'] ) {
		wp_send_json_error( 'This booking is already fully settled.' );
	}

	$remaining_order = custom_truelysell_create_or_reuse_remaining_balance_order( $order, $booking, get_current_user_id() );
	if ( is_wp_error( $remaining_order ) ) {
		wp_send_json_error( $remaining_order->get_error_message() );
	}

	wp_send_json_success( array( 'redirect_url' => $remaining_order->get_checkout_payment_url() ) );
}

// ============================================================
// ADMIN TESTING TOOL — force-test the remaining-balance payment flow on
// a past/already-finalized booking, without needing two real accounts
// (technician + customer) to actually click "Mark Job Complete" /
// "Confirm Job Complete" themselves first.
// ------------------------------------------------------------------
// The normal flow (mutual technician+customer confirmation, see the
// REMAINING BALANCE section above) has no date restriction at all — a
// past appointment already works exactly the same as a future one for
// this purpose, there's nothing to "unlock". What's actually missing
// for testing is a way for admin to trigger both confirmations AND get
// a payable link themselves, without waiting on two other real people.
// This adds exactly that, scoped to administrators only, as a WooCommerce
// order action (Orders → open the deposit order → Order actions dropdown)
// — never exposed anywhere a technician or customer could reach it, and
// it doesn't change how the real flow behaves for them.
// ============================================================

add_filter( 'woocommerce_order_actions', 'custom_truelysell_add_admin_test_remaining_payment_action' );
function custom_truelysell_add_admin_test_remaining_payment_action( $actions ) {
	global $theorder;

	if ( ! current_user_can( 'manage_options' ) || ! $theorder || ! $theorder->get_meta( '_truelysell_booking_created' ) ) {
		return $actions;
	}

	$booking_id = absint( $theorder->get_meta( 'booking_id' ) );
	$booking    = $booking_id && function_exists( 'custom_truelysell_get_booking_by_id' ) ? custom_truelysell_get_booking_by_id( $booking_id ) : null;
	if ( ! $booking ) {
		return $actions;
	}

	$state = custom_truelysell_get_job_completion_state( $theorder );
	if ( $state['remaining_settled'] ) {
		return $actions;
	}

	$actions['truelysell_admin_test_remaining_payment'] = __( 'Truelysell: force-confirm & get remaining-balance payment link (testing)', 'truelysell' );

	return $actions;
}

add_action( 'woocommerce_order_action_truelysell_admin_test_remaining_payment', 'custom_truelysell_handle_admin_test_remaining_payment' );
function custom_truelysell_handle_admin_test_remaining_payment( $order ) {
	if ( ! current_user_can( 'manage_options' ) || ! $order->get_meta( '_truelysell_booking_created' ) ) {
		return;
	}

	$booking_id = absint( $order->get_meta( 'booking_id' ) );
	$booking    = $booking_id && function_exists( 'custom_truelysell_get_booking_by_id' ) ? custom_truelysell_get_booking_by_id( $booking_id ) : null;
	if ( ! $booking ) {
		$order->add_order_note( 'Truelysell (admin test): could not find this order\'s booking row — nothing to do.' );
		return;
	}

	// Force both confirmation flags — this is the ONLY place in the
	// codebase that sets them without the technician/customer actually
	// clicking their own button, and it's gated to manage_options above.
	$order->update_meta_data( '_truelysell_job_marked_complete_by_technician', 1 );
	$order->update_meta_data( '_truelysell_job_marked_complete_by_technician_time', current_time( 'mysql' ) );
	$order->update_meta_data( '_truelysell_job_confirmed_complete_by_customer', 1 );
	$order->update_meta_data( '_truelysell_job_confirmed_complete_by_customer_time', current_time( 'mysql' ) );
	$order->save_meta_data();
	$order->add_order_note( 'Truelysell (admin test): force-confirmed both sides so the remaining-balance payment can be tested.' );

	// Build the order as the actual customer (so its billing details and
	// "who owns this order" stay correct) — admin just opens the link
	// themselves to test checkout, they don't have to actually be that
	// customer's account.
	$customer_id     = absint( $order->get_meta( '_truelysell_customer_id' ) );
	$remaining_order = custom_truelysell_create_or_reuse_remaining_balance_order( $order, $booking, $customer_id ?: get_current_user_id() );

	if ( is_wp_error( $remaining_order ) ) {
		$order->add_order_note( 'Truelysell (admin test): failed to create remaining-balance order — ' . $remaining_order->get_error_message() );
		return;
	}

	$order->add_order_note( sprintf(
		'Truelysell (admin test): remaining-balance payment link ready — %s',
		esc_url( $remaining_order->get_checkout_payment_url() )
	) );
}

/**
 * Fires when the SEPARATE remaining-balance order (created above) actually
 * clears — distinguished from the original deposit order purely by the
 * presence of _truelysell_remaining_of_order_id, which only that order
 * ever has. Registers the full amount as a 100%-rate commission (per the
 * site owner's choice: platform takes nothing extra here, same as cash
 * always was) so it lands in the technician's payout balance exactly like
 * any other commission, then notifies both sides.
 */
add_action( 'woocommerce_payment_complete', 'custom_truelysell_settle_remaining_balance_online' );
function custom_truelysell_settle_remaining_balance_online( $remaining_order_id ) {
	$remaining_order = wc_get_order( $remaining_order_id );
	if ( ! $remaining_order ) {
		return;
	}

	$original_order_id = absint( $remaining_order->get_meta( '_truelysell_remaining_of_order_id' ) );
	if ( ! $original_order_id ) {
		return; // Not a remaining-balance order — nothing to do here.
	}

	$order = wc_get_order( $original_order_id );
	if ( ! $order || $order->get_meta( '_truelysell_remaining_settled' ) ) {
		return; // Already settled — don't double-register (e.g. duplicate payment_complete + order_status_changed firing for the same order).
	}

	$booking_id = absint( $remaining_order->get_meta( '_truelysell_booking_id' ) );
	$owner_id   = absint( $remaining_order->get_meta( '_truelysell_owner_id' ) );
	$listing_id = absint( $remaining_order->get_meta( '_truelysell_listing_id' ) );
	$amount     = (float) $remaining_order->get_total();

	$order->update_meta_data( '_truelysell_remaining_settled', 1 );
	$order->update_meta_data( '_truelysell_remaining_payment_method', 'online' );
	$order->save_meta_data();
	$order->add_order_note( sprintf( 'Truelysell: remaining balance of %s paid online via order #%d — booking fully settled.', wp_strip_all_tags( wc_price( $amount ) ), $remaining_order_id ) );

	if ( class_exists( 'Truelysell_Core_Commissions' ) ) {
		$commission_id = Truelysell_Core_Commissions::instance()->insert_commission( array(
			'order_id'   => $remaining_order_id,
			'user_id'    => $owner_id,
			'booking_id' => $booking_id,
			'listing_id' => $listing_id,
			'rate'       => 1, // No platform commission on the remaining balance — same as cash always was.
			'status'     => 'unpaid',
			'amount'     => $amount,
			'type'       => 'percentage',
		) );
		if ( $commission_id ) {
			$remaining_order->update_meta_data( '_truelysell_commissions_id', $commission_id );
			$remaining_order->update_meta_data( '_truelysell_commissions_processed', 'yes' );
			$remaining_order->save_meta_data();
		}
	}

	$owner_info = get_userdata( $owner_id );
	if ( $owner_info && is_email( $owner_info->user_email ) ) {
		wp_mail(
			$owner_info->user_email,
			sprintf( __( 'Remaining balance paid online — booking #%d', 'truelysell' ), $booking_id ),
			sprintf( "The customer paid the remaining balance of %s online for booking #%d. The full amount has been added to your payout balance.\n\nBooking ID: #%d", wp_strip_all_tags( wc_price( $amount ) ), $booking_id, $booking_id )
		);
	}

	$customer_email = $order->get_meta( '_truelysell_email' );
	if ( is_email( $customer_email ) ) {
		wp_mail(
			$customer_email,
			sprintf( __( 'Payment received — booking #%d fully settled', 'truelysell' ), $booking_id ),
			sprintf( "Thank you! Your payment of %s has been received and booking #%d is now fully settled.\n\nBooking ID: #%d", wp_strip_all_tags( wc_price( $amount ) ), $booking_id, $booking_id )
		);
	}
}

/**
 * Same safety net as custom_truelysell_create_booking_on_status_change()
 * above, for the same reason: not every gateway reliably fires
 * woocommerce_payment_complete, but woocommerce_order_status_changed
 * always fires on any status transition. custom_truelysell_settle_
 * remaining_balance_online() is idempotent (bails immediately if this
 * isn't a remaining-balance order, or it's already settled), so calling
 * it from both hooks for the same order is harmless.
 */
add_action( 'woocommerce_order_status_changed', 'custom_truelysell_settle_remaining_balance_on_status_change', 5, 3 );
function custom_truelysell_settle_remaining_balance_on_status_change( $order_id, $old_status, $new_status ) {
	if ( in_array( $new_status, array( 'processing', 'completed' ), true ) ) {
		custom_truelysell_settle_remaining_balance_online( $order_id );
	}
}

/**
 * Buttons for the job-completion flow above render inside the provider/
 * customer bookings shortcodes (inc/template-tags.php) with
 * data-truelysell-job-action attributes — bind one shared click handler
 * here rather than duplicating fetch/AJAX boilerplate per button.
 */
add_action( 'wp_footer', 'custom_truelysell_render_job_completion_js' );
function custom_truelysell_render_job_completion_js() {
	if ( ! is_user_logged_in() ) {
		return;
	}
	?>
	<script type="text/javascript">
	document.addEventListener('DOMContentLoaded', function () {
		document.addEventListener('click', function (e) {
			var btn = e.target.closest('[data-truelysell-job-action]');
			if (!btn) {
				return;
			}
			e.preventDefault();

			var action    = btn.getAttribute('data-truelysell-job-action');
			var bookingId = btn.getAttribute('data-booking-id');
			if (!action || !bookingId || btn.disabled) {
				return;
			}

			btn.disabled = true;
			var originalText = btn.textContent;
			btn.textContent = <?php echo wp_json_encode( __( 'Please wait…', 'truelysell' ) ); ?>;

			var formData = new FormData();
			formData.append('action', action);
			formData.append('booking_id', bookingId);
			formData.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'truelysell_job_completion_nonce' ) ); ?>);

			fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', body: formData } )
				.then(function (r) { return r.json(); })
				.then(function (data) {
					if (data && data.success) {
						if (data.data && data.data.redirect_url) {
							window.location.href = data.data.redirect_url;
							return;
						}
						window.location.reload();
					} else {
						btn.disabled = false;
						btn.textContent = originalText;
						window.alert( ( data && data.data && data.data.message ) ? data.data.message : ( ( data && data.data ) || <?php echo wp_json_encode( __( 'Something went wrong. Please try again.', 'truelysell' ) ); ?> ) );
					}
				})
				.catch(function () {
					btn.disabled = false;
					btn.textContent = originalText;
					window.alert( <?php echo wp_json_encode( __( 'Network error. Please try again.', 'truelysell' ) ); ?> );
				});
		});
	});
	</script>
	<?php
}

/**
 * The job-completion buttons built into the [truelysell_provider_bookings]/
 * [truelysell_customer_bookings] shortcodes (inc/template-tags.php) only
 * ever render if the_content actually got replaced by those shortcodes on
 * a given page. In practice, the real Booking List / My Bookings /
 * Dashboard "Recent Bookings" widget all render the plugin's own native,
 * unreplaced booking-row template instead — same #booking-list-{ID} row
 * markup, same actions-row selector already used by the "Rate Customer"
 * injection above (custom_truelysell_render_rate_customer_ui()), confirmed
 * by that exact button showing up on the live Booking List page. Inject
 * the same job-completion controls into every one of those pages the same
 * way, so they show up wherever the technician/customer actually lands —
 * this is a pure addition to the existing native row, so it never changes
 * that page's existing look.
 */
add_action( 'wp_footer', 'custom_truelysell_render_job_completion_dashboard_widget_buttons' );
function custom_truelysell_render_job_completion_dashboard_widget_buttons() {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return;
	}

	$dashboard_page     = function_exists( 'truelysell_fl_framework_getoptions' ) ? truelysell_fl_framework_getoptions( 'dashboard_page' ) : 0;
	$bookings_page      = function_exists( 'truelysell_fl_framework_getoptions' ) ? truelysell_fl_framework_getoptions( 'bookings_page' ) : 0;
	$user_bookings_page = function_exists( 'truelysell_fl_framework_getoptions' ) ? truelysell_fl_framework_getoptions( 'user_bookings_page' ) : 0;
	$current_page       = get_queried_object_id();

	$on_relevant_page = ( $dashboard_page && absint( $dashboard_page ) === absint( $current_page ) )
		|| ( $bookings_page && absint( $bookings_page ) === absint( $current_page ) )
		|| ( $user_bookings_page && absint( $user_bookings_page ) === absint( $current_page ) );

	if ( ! $on_relevant_page ) {
		return;
	}

	if ( ! function_exists( 'truelysell_get_booking_job_completion_state' ) ) {
		return;
	}

	$is_provider = custom_truelysell_is_restricted_provider( $user_id );

	if ( $is_provider ) {
		if ( ! function_exists( 'truelysell_get_provider_bookings' ) ) {
			return;
		}
		$bookings = truelysell_get_provider_bookings( $user_id );
	} else {
		if ( ! function_exists( 'truelysell_get_customer_bookings' ) ) {
			return;
		}
		$bookings = truelysell_get_customer_bookings( $user_id );
	}

	$rows = array();
	foreach ( $bookings as $booking ) {
		$state = truelysell_get_booking_job_completion_state( $booking );
		if ( null === $state || $state['remaining_settled'] ) {
			continue; // Nothing actionable to inject for this row.
		}
		$customer_details = function_exists( 'truelysell_get_booking_customer_details' ) ? truelysell_get_booking_customer_details( $booking ) : array();
		$rows[ absint( $booking->ID ) ] = array(
			'technician_marked_complete' => $state['technician_marked_complete'],
			'both_confirmed'             => $state['both_confirmed'],
			'remaining_amount'           => round( $state['remaining_amount'], 2 ),
			// The native row's own "Amount" line always shows the full listing
			// price (e.g. "$120.00") with no indication that only the 20%
			// deposit has actually cleared — easy to misread as fully paid.
			'deposit_amount'             => round( (float) $booking->price - $state['remaining_amount'], 2 ),
			'tv_quantity'                => ! empty( $customer_details['tv_quantity'] ) ? absint( $customer_details['tv_quantity'] ) : 0,
			'tv_sizes'                   => ! empty( $customer_details['tv_sizes'] ) && is_array( $customer_details['tv_sizes'] ) ? implode( '", ', array_map( 'floatval', $customer_details['tv_sizes'] ) ) . '"' : '',
		);
	}

	if ( empty( $rows ) ) {
		return;
	}
	?>
	<script type="text/javascript">
	document.addEventListener('DOMContentLoaded', function () {
		var rows          = <?php echo wp_json_encode( $rows ); ?>;
		var isProvider    = <?php echo wp_json_encode( $is_provider ); ?>;
		// html_entity_decode() because get_woocommerce_currency_symbol() returns
		// an HTML entity string (e.g. "&#36;") meant for direct HTML output —
		// used as plain JS text via .textContent, it would show up literally.
		var currencySymbol = <?php echo wp_json_encode( html_entity_decode( function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$', ENT_QUOTES ) ); ?>;

		Object.keys(rows).forEach(function (bookingId) {
			var info = rows[bookingId];
			var row  = document.getElementById('booking-list-' + bookingId);
			if (!row) {
				return;
			}

			// Clarify the "Amount" line (which always shows the full price,
			// e.g. "$120.00", regardless of how much has actually cleared) so
			// it doesn't read as if the whole amount had been paid.
			var amountLi = null;
			row.querySelectorAll('.booking-details li').forEach(function (li) {
				var label = li.querySelector('.book-item');
				if (label && label.textContent.trim() === 'Amount') {
					amountLi = li;
				}
			});
			if (amountLi && !amountLi.querySelector('.truelysell-deposit-note')) {
				var note = document.createElement('span');
				note.className = 'truelysell-deposit-note fs-12 text-muted ms-2';
				note.textContent = '(' + <?php echo wp_json_encode( __( 'Deposit paid:', 'truelysell' ) ); ?> + ' ' + currencySymbol + info.deposit_amount.toFixed(2) + <?php echo wp_json_encode( __( ' • Remaining:', 'truelysell' ) ); ?> + ' ' + currencySymbol + info.remaining_amount.toFixed(2) + ')';
				amountLi.appendChild(note);
			}

			if (amountLi && info.tv_quantity && info.tv_sizes && !row.querySelector('.truelysell-tv-details-li')) {
				var tvLi = document.createElement('li');
				tvLi.className = 'd-flex align-items-center mb-2 truelysell-tv-details-li';
				var tvLabel = document.createElement('span');
				tvLabel.className = 'book-item';
				tvLabel.textContent = <?php echo wp_json_encode( __( 'TVs', 'truelysell' ) ); ?>;
				var tvSep = document.createElement('small');
				tvSep.className = 'me-2';
				tvSep.textContent = ': ';
				var tvText = document.createTextNode(info.tv_quantity + ' (' + info.tv_sizes + ')');
				tvLi.appendChild(tvLabel);
				tvLi.appendChild(tvSep);
				tvLi.appendChild(tvText);
				amountLi.parentNode.insertBefore(tvLi, amountLi.nextSibling);
			}

			var actionsRow = row.querySelector('.d-flex.align-items-center.flex-wrap.row-gap-2');
			if (!actionsRow || !actionsRow.parentNode) {
				return;
			}

			var wrapper = document.createElement('div');
			wrapper.className = 'd-flex align-items-center flex-wrap row-gap-2 mt-2';

			function addButton(label, action, classes) {
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'btn w-100 mb-2 ' + classes;
				btn.textContent = label;
				btn.setAttribute('data-truelysell-job-action', action);
				btn.setAttribute('data-booking-id', bookingId);
				wrapper.appendChild(btn);
			}

			function addStatusText(text) {
				var span = document.createElement('span');
				span.className = 'fs-12 text-muted mb-2';
				span.textContent = text;
				wrapper.appendChild(span);
			}

			if (info.both_confirmed) {
				if (isProvider) {
					addStatusText(<?php echo wp_json_encode( __( 'Waiting for customer to pay online', 'truelysell' ) ); ?>);
				} else {
					addButton(<?php echo wp_json_encode( __( 'Pay Remaining', 'truelysell' ) ); ?> + ' ' + currencySymbol + info.remaining_amount.toFixed(2), 'truelysell_pay_remaining_balance', 'btn-primary');
				}
			} else if (info.technician_marked_complete) {
				if (!isProvider) {
					addButton(<?php echo wp_json_encode( __( 'Confirm Job Complete', 'truelysell' ) ); ?>, 'truelysell_confirm_job_complete', 'btn-outline-success');
				}
			} else if (isProvider) {
				addButton(<?php echo wp_json_encode( __( 'Mark Job Complete', 'truelysell' ) ); ?>, 'truelysell_mark_job_complete', 'btn-outline-success');
			}

			if (wrapper.children.length) {
				actionsRow.parentNode.insertBefore(wrapper, actionsRow.nextSibling);
			}
		});
	});
	</script>
	<?php
}

// ============================================================
// FAVORITES / BOOKMARKS COMPLETE FIX - Child Theme
// Problem 1: Heart icon click karne pe "Login to Favourite"
//            modal show hota hai even when logged in
// Problem 2: Bookmarks page pe navigate nahi ho raha
// Problem 3: Saved listings My Favourites mein nahi dikh rahe
// ============================================================

// --- STEP 1: Bookmarks AJAX handlers ---
// Save/remove bookmark via AJAX (user_meta mein store hogi list)

add_action( 'wp_ajax_custom_toggle_bookmark', 'custom_truelysell_toggle_bookmark' );
function custom_truelysell_toggle_bookmark() {
    if ( ! check_ajax_referer( 'custom_bookmark_nonce', 'nonce', false ) ) {
        wp_send_json_error( 'Security check failed.' );
    }
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( 'not_logged_in' );
    }
    $user_id    = get_current_user_id();
    $post_id    = absint( $_POST['post_id'] ?? 0 );
    if ( ! $post_id ) {
        wp_send_json_error( 'invalid_post' );
    }

    $bookmarks = get_user_meta( $user_id, 'truelysell_bookmarks', true );
    if ( ! is_array( $bookmarks ) ) {
        $bookmarks = array();
    }

    if ( in_array( $post_id, $bookmarks, true ) ) {
        // Remove bookmark
        $bookmarks = array_values( array_diff( $bookmarks, array( $post_id ) ) );
        update_user_meta( $user_id, 'truelysell_bookmarks', $bookmarks );
        wp_send_json_success( array( 'action' => 'removed', 'post_id' => $post_id ) );
    } else {
        // Add bookmark
        $bookmarks[] = $post_id;
        update_user_meta( $user_id, 'truelysell_bookmarks', $bookmarks );
        wp_send_json_success( array( 'action' => 'added', 'post_id' => $post_id ) );
    }
}

// --- STEP 2: Enqueue JS for bookmark toggle ---
add_action( 'wp_footer', 'custom_truelysell_bookmark_script', 10 );
function custom_truelysell_bookmark_script() {
    if ( ! is_user_logged_in() ) {
        // Guest user: just ensure login modal shows (already done by theme)
        return;
    }

    $user_id   = get_current_user_id();
    $bookmarks = get_user_meta( $user_id, 'truelysell_bookmarks', true );
    if ( ! is_array( $bookmarks ) ) {
        $bookmarks = array();
    }

    // Removing a favourite here should drop it off the list immediately —
    // this page only ever shows favourited items, so just reload it rather
    // than trying to track which card belongs to which grid/layout markup.
    $is_favourites_page = is_singular() && has_shortcode( get_post()->post_content, 'customer_my_favourites' );
    ?>
    <script type="text/javascript">
    (function() {
        'use strict';

        var userBookmarks = <?php echo wp_json_encode( array_map( 'intval', $bookmarks ) ); ?>;
        var ajaxUrl   = '<?php echo esc_js( admin_url('admin-ajax.php') ); ?>';
        var nonce     = '<?php echo esc_js( wp_create_nonce('custom_bookmark_nonce') ); ?>';
        var isLoggedIn = true;
        var isFavouritesPage = <?php echo $is_favourites_page ? 'true' : 'false'; ?>;

        document.addEventListener('DOMContentLoaded', function() {

            // Mark already-bookmarked items on page load
            document.querySelectorAll('.fav-icon.truelysell_core_bookmark_it, .fav-icon[data-post_id]').forEach(function(btn) {
                var postId = parseInt(btn.getAttribute('data-post_id') || btn.dataset.post_id, 10);
                if ( userBookmarks.indexOf(postId) !== -1 ) {
                    btn.classList.add('selected', 'active');
                    var icon = btn.querySelector('i');
                    if (icon) icon.style.color = '#e74c3c';
                }

                // Override click - prevent login modal from showing for logged-in users
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();

                    if (!isLoggedIn) {
                        // Should not reach here but just in case
                        return;
                    }

                    var self = this;
                    var pid  = parseInt(self.getAttribute('data-post_id') || self.dataset.post_id, 10);
                    if (!pid) return;

                    // Visual feedback
                    self.style.opacity = '0.5';

                    var formData = new FormData();
                    formData.append('action', 'custom_toggle_bookmark');
                    formData.append('post_id', pid);
                    formData.append('nonce', nonce);

                    fetch(ajaxUrl, { method: 'POST', body: formData })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        self.style.opacity = '1';
                        if (data.success) {
                            var icon = self.querySelector('i');
                            if (data.data.action === 'added') {
                                self.classList.add('selected', 'active');
                                if (icon) {
                                    icon.style.color = '#e74c3c';
                                    icon.style.transform = 'scale(1.3)';
                                    setTimeout(function() { icon.style.transform = 'scale(1)'; }, 300);
                                }
                                userBookmarks.push(pid);
                                showToast('Added to Favourites!', 'success');
                            } else {
                                self.classList.remove('selected', 'active');
                                if (icon) {
                                    icon.style.color = '';
                                }
                                userBookmarks = userBookmarks.filter(function(id) { return id !== pid; });
                                showToast('Removed from Favourites!', 'info');

                                if (isFavouritesPage) {
                                    setTimeout(function() { window.location.reload(); }, 400);
                                }
                            }
                        } else {
                            showToast('Error: ' + (data.data || 'Please try again.'), 'danger');
                        }
                    })
                    .catch(function() {
                        self.style.opacity = '1';
                        showToast('Network error. Please try again.', 'danger');
                    });
                }, true);
            });

            // Close login-to-favourite modals for logged-in users
            document.querySelectorAll('[id^="bookmark_"]').forEach(function(modal) {
                // Hide these modals for logged-in users - they should not show
                modal.style.display = 'none';
            });
        });

        // Simple toast notification
        function showToast(msg, type) {
            var existing = document.getElementById('custom-bookmark-toast');
            if (existing) existing.remove();

            var toast = document.createElement('div');
            toast.id = 'custom-bookmark-toast';
            toast.style.cssText = 'position:fixed;bottom:30px;right:30px;z-index:9999;padding:12px 20px;border-radius:8px;font-weight:600;font-size:14px;color:#fff;box-shadow:0 4px 15px rgba(0,0,0,0.2);transition:opacity 0.3s;opacity:1;';
            var colors = { success: '#2ecc71', danger: '#e74c3c', info: '#3498db' };
            toast.style.background = colors[type] || colors.info;
            toast.textContent = msg;
            document.body.appendChild(toast);
            setTimeout(function() { toast.style.opacity = '0'; setTimeout(function() { toast.remove(); }, 300); }, 2500);
        }
    })();
    </script>
    <?php
}

// --- STEP 3: Shortcode for My Favourites page ---
// Add [customer_my_favourites] to the Favourites page in WordPress
add_shortcode( 'customer_my_favourites', 'custom_truelysell_my_favourites_shortcode' );
function custom_truelysell_my_favourites_shortcode( $atts ) {
    if ( ! is_user_logged_in() ) {
        return '<div class="alert alert-warning">' . esc_html__( 'Please login to see your favourites.', 'truelysell' ) . '</div>';
    }

    $user_id   = get_current_user_id();
    $bookmarks = get_user_meta( $user_id, 'truelysell_bookmarks', true );

    ob_start();

    if ( empty( $bookmarks ) || ! is_array( $bookmarks ) ) {
        echo '<div class="alert alert-info"><i class="ti ti-heart me-2"></i>' . esc_html__( 'You have no favourites yet. Browse services and click the heart icon to save them here.', 'truelysell' ) . '</div>';
        return ob_get_clean();
    }

    $currency_symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$';

    echo '<div class="row g-4">';

    foreach ( $bookmarks as $post_id ) {
        $post_id = absint( $post_id );
        $post    = get_post( $post_id );
        if ( ! $post || $post->post_status !== 'publish' ) {
            continue;
        }

        $title        = get_the_title( $post_id );
        $permalink    = get_permalink( $post_id );
        $thumbnail    = get_the_post_thumbnail_url( $post_id, 'medium' );
        $normal_price = (float) get_post_meta( $post_id, '_normal_price', true );
        if ( ! $normal_price ) {
            $normal_price = (float) get_post_meta( $post_id, '_price', true );
        }
        $price_str = $currency_symbol . number_format( $normal_price, 2 );

        $terms     = get_the_terms( $post_id, 'listing_category' );
        $category  = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : '';

        $rating    = get_post_meta( $post_id, 'truelysell-avg-rating', true );
        $rating    = $rating ? number_format( (float) $rating, 1 ) : '0';

        $author_id = $post->post_author;
        $author    = get_userdata( $author_id );
        ?>
        <div class="col-xl-4 col-md-6">
            <div class="card p-0 listing-grid">
                <div class="card-body p-0">
                    <div class="img-sec w-100" style="position:relative;">
                        <a href="<?php echo esc_url( $permalink ); ?>">
                            <?php if ( $thumbnail ) : ?>
                                <img src="<?php echo esc_url( $thumbnail ); ?>" class="img-fluid rounded-top w-100" alt="<?php echo esc_attr( $title ); ?>" style="height:200px;object-fit:cover;">
                            <?php else : ?>
                                <div class="bg-light d-flex align-items-center justify-content-center rounded-top" style="height:200px;">
                                    <i class="ti ti-photo fs-1 text-muted"></i>
                                </div>
                            <?php endif; ?>
                        </a>
                        <?php if ( $category ) : ?>
                        <div class="image-tag d-flex justify-content-end align-items-center">
                            <span class="trend-tag"><?php echo esc_html( $category ); ?></span>
                        </div>
                        <?php endif; ?>

                        <!-- Remove from favourites button -->
                        <a href="javascript:void(0);"
                           data-post_id="<?php echo esc_attr( $post_id ); ?>"
                           class="fav-icon truelysell_core_bookmark_it like-icon selected"
                           style="position:absolute;top:10px;right:10px;background:#fff;border-radius:50%;width:36px;height:36px;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(0,0,0,0.15);">
                            <i class="ti ti-heart" style="color:#e74c3c;"></i>
                        </a>

                        <?php if ( $author ) : ?>
                        <span class="image-logo avatar avatar-md border rounded-circle" style="position:absolute;bottom:-18px;right:15px;">
                            <?php echo get_avatar( $author->user_email, 56, '', '', array('class' => 'img-fluid rounded-circle') ); ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <div class="p-3 pt-4">
                        <h5 class="mb-2 text-truncate">
                            <a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a>
                        </h5>
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="rating text-gray fs-14">
                                <i class="fa fa-star filled me-1" style="color:#f59e0b;"></i>
                                <?php echo esc_html( $rating ); ?>
                            </span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><?php echo esc_html( $price_str ); ?></h5>
                            <a href="<?php echo esc_url( $permalink ); ?>" class="btn bg-primary-transparent">
                                <?php esc_html_e( 'Book Now', 'truelysell' ); ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    echo '</div>';

    return ob_get_clean();
}

// --- STEP 4: Auto inject favourites on bookmarks_page ---
// Theme ki purani content ko REPLACE karo (append nahi) taake
// "You don't have any favourites yet" aur services dono ek saath na dikhen.
add_filter( 'the_content', 'custom_truelysell_auto_inject_favourites', 30 );
function custom_truelysell_auto_inject_favourites( $content ) {
    if ( ! is_user_logged_in() || ! is_page() ) {
        return $content;
    }
    if ( ! function_exists( 'truelysell_fl_framework_getoptions' ) ) {
        return $content;
    }
    $bookmarks_page = absint( truelysell_fl_framework_getoptions( 'bookmarks_page' ) );
    if ( ! $bookmarks_page || absint( get_queried_object_id() ) !== $bookmarks_page ) {
        return $content;
    }
    // Agar content mein already hamara shortcode hai to kuch mat karo
    if ( has_shortcode( $content, 'customer_my_favourites' ) ) {
        return $content;
    }
    // Theme ki purani content ko REPLACE karo — sirf hamara shortcode output dikhe
    // (Agar page editor mein [truelysell_bookmarks] ya koi aur shortcode tha, woh replace ho jayega)
    return do_shortcode( '[customer_my_favourites]' );
}

// --- STEP 5: Fix customer dashboard navigation ---
// Customer dashboard page pe hain aur bookmarks/bookings links work nahi kar rahe?
// Issue: template-dashboard-customer.php uses $post->ID comparison but
// customer page uses different template. Fix the redirect.
// DASHBOARD NAV FIX: DISABLED - was causing redirect loops
// add_action( 'template_redirect', 'custom_truelysell_fix_customer_dashboard_nav', 5 );
function custom_truelysell_fix_customer_dashboard_nav() {
    // DISABLED - no redirects
    return;
    // Just ensure we're not blocking the customer from reaching these pages
}

// --- STEP 6: Ensure existing bookmarks from theme are also migrated ---
// Some themes store bookmarks in 'truelysell_bookmarks' or 'listing_bookmarks'
// Run a one-time migration check
add_action( 'init', 'custom_truelysell_migrate_existing_bookmarks' );
function custom_truelysell_migrate_existing_bookmarks() {
    if ( ! is_user_logged_in() ) {
        return;
    }
    // Already done?
    $user_id = get_current_user_id();
    if ( get_user_meta( $user_id, '_bookmark_migration_done', true ) ) {
        return;
    }

    // Check other possible keys the theme plugin might use
    $other_keys = array( 'listing_bookmarks', 'truelysell_wishlist', 'user_bookmarks', 'saved_listings' );
    $existing   = get_user_meta( $user_id, 'truelysell_bookmarks', true );
    if ( ! is_array( $existing ) ) {
        $existing = array();
    }

    foreach ( $other_keys as $key ) {
        $val = get_user_meta( $user_id, $key, true );
        if ( is_array( $val ) && ! empty( $val ) ) {
            $existing = array_unique( array_merge( $existing, array_map( 'absint', $val ) ) );
        }
    }

    if ( ! empty( $existing ) ) {
        update_user_meta( $user_id, 'truelysell_bookmarks', array_values( $existing ) );
    }

    update_user_meta( $user_id, '_bookmark_migration_done', 1 );
}

// ============================================================
// FIX 1: "Favorite" → "Favorites" — Bookmarks/Favourites page title fix
// ============================================================
add_filter( 'the_title', 'custom_fix_favorite_page_title', 10, 2 );
function custom_fix_favorite_page_title( $title, $id = null ) {
    $bookmarks_page = truelysell_fl_framework_getoptions( 'bookmarks_page' );
    if ( $bookmarks_page && absint( $id ) === absint( $bookmarks_page ) ) {
        return 'Favorites';
    }
    // Also catch any page whose title is exactly "Favorite" (typo fix)
    if ( $title === 'Favorite' ) {
        return 'Favorites';
    }
    return $title;
}

// Fix breadcrumbs / document title as well
add_filter( 'wp_title', 'custom_fix_favorite_wp_title', 10, 1 );
function custom_fix_favorite_wp_title( $title ) {
    if ( strpos( $title, 'Favorite' ) !== false && strpos( $title, 'Favorites' ) === false ) {
        $title = str_replace( 'Favorite', 'Favorites', $title );
    }
    return $title;
}

// ============================================================
// FIX 2: Phone Number Mandatory for Customers — Profile Form
// ============================================================

// Server-side: block profile save if phone number is empty
add_action( 'init', 'custom_enforce_phone_number_on_profile_save', 5 );
function custom_enforce_phone_number_on_profile_save() {
    if ( ! isset( $_POST['my-account-submission'] ) ) {
        return;
    }
    if ( ! is_user_logged_in() ) {
        return;
    }
    /*
     * The plugin's "My Account" page has several distinct tabs/forms
     * (Edit Profile, Payout Settings, Password, ...) that ALL post with
     * the same shared my-account-submission=1 flag — it's not unique to
     * the profile-edit form. Only the profile-edit form actually includes
     * a phone_number field at all; the Payout Settings form (e.g. saving
     * a PayPal payout email) does not, so isset() below was always false
     * for it and this hook redirected every payout save back with a
     * "Phone Number is required" error even though the technician wasn't
     * touching their phone number. Bail out here — before validating
     * anything — for any submission that isn't actually the profile form,
     * exactly like the other my-account-submission hooks in this file
     * (custom_truelysell_save_profile_travel_radius,
     * custom_truelysell_save_profile_lat_lng) already scope themselves to
     * only the fields that exist on the tab they care about.
     */
    if ( ! isset( $_POST['phone_number'] ) ) {
        return;
    }
    $phone = trim( sanitize_text_field( $_POST['phone_number'] ) );
    if ( empty( $phone ) ) {
        // Redirect back with an error query string so the theme shows an error
        $profile_page = truelysell_fl_framework_getoptions( 'profile_page' );
        $redirect_url = $profile_page ? get_permalink( $profile_page ) : home_url( '/profile/' );
        $redirect_url = add_query_arg( 'profile_error', 'phone_required', $redirect_url );
        wp_safe_redirect( $redirect_url );
        exit;
    }
}

// Display the phone-required error on the profile page
add_action( 'wp_footer', 'custom_show_phone_required_error' );
function custom_show_phone_required_error() {
    if ( ! isset( $_GET['profile_error'] ) || $_GET['profile_error'] !== 'phone_required' ) {
        return;
    }
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-danger';
        alertDiv.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;min-width:300px;';
        alertDiv.innerHTML = '<strong>Error:</strong> Phone Number is required. Please enter your phone number.';
        document.body.appendChild(alertDiv);
        setTimeout(function () { alertDiv.remove(); }, 5000);
    });
    </script>
    <?php
}

// Client-side: ensure phone field always has required attribute on profile page
add_action( 'wp_footer', 'custom_enforce_phone_required_js' );
function custom_enforce_phone_required_js() {
    $profile_page = truelysell_fl_framework_getoptions( 'profile_page' );
    if ( ! $profile_page || absint( get_queried_object_id() ) !== absint( $profile_page ) ) {
        return;
    }
    if ( ! is_user_logged_in() ) {
        return;
    }
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        // Make phone_number field required
        var phoneField = document.getElementById('phone_number');
        if ( phoneField ) {
            phoneField.setAttribute('required', 'required');

            // Add visual asterisk to label if not already there
            var label = document.querySelector('label[for="phone_number"]');
            if ( label && ! label.querySelector('.text-danger') ) {
                var star = document.createElement('span');
                star.className = 'text-danger';
                star.textContent = ' *';
                label.appendChild(star);
            }

            // Block form submit if phone is empty
            var form = document.getElementById('edit_user');
            if ( form ) {
                form.addEventListener('submit', function (e) {
                    if ( phoneField.value.trim() === '' ) {
                        e.preventDefault();
                        phoneField.classList.add('is-invalid');
                        phoneField.focus();
                        // Show inline error
                        var err = document.getElementById('phone-error-msg');
                        if ( ! err ) {
                            err = document.createElement('div');
                            err.id = 'phone-error-msg';
                            err.className = 'invalid-feedback d-block';
                            err.textContent = 'Phone Number is required.';
                            phoneField.parentNode.appendChild(err);
                        }
                        return false;
                    } else {
                        phoneField.classList.remove('is-invalid');
                        var err = document.getElementById('phone-error-msg');
                        if ( err ) err.remove();
                    }
                });
            }
        }
    });
    </script>
    <?php
}

// ============================================================
// PAYOUT METHOD SYSTEM — Bank Account, Stripe & PayPal
// ------------------------------------------------------------------
// Full payout method integration: status tracking, secure masked
// display, change-method confirmation flow, admin columns, payout
// guard, and dedicated admin management page.
//
// Data stored per technician (user meta):
//   truelysell_payout_method        — 'paypal' | 'stripe' | 'bank'
//   truelysell_payout_status        — 'connected' | 'pending' | 'not_connected' | 'disconnected'
//   truelysell_payout_connected_date — Unix timestamp of first connection
//   truelysell_payout_account_label — Masked safe-display label (never raw credentials)
//   truelysell_paypal_payout_email  — PayPal email (managed by plugin's own handler)
//   truelysell_stripe_email         — Stripe email / account ID
//   truelysell_bank_account_holder  — Bank account holder name
//   truelysell_bank_name            — Bank name
//   truelysell_bank_account_number  — Account number (masked on display)
//   truelysell_bank_routing_number  — Routing number (masked on display)
// ============================================================

/**
 * Mask an email address for safe display.
 * Shows first 2 chars + ●●● + @domain — never the full address.
 */
function custom_truelysell_mask_payout_email( $email ) {
	if ( ! $email ) {
		return '';
	}
	$parts        = explode( '@', $email, 2 );
	$local        = $parts[0];
	$domain       = isset( $parts[1] ) ? $parts[1] : '';
	$masked_local = strlen( $local ) > 2 ? substr( $local, 0, 2 ) . '●●●' : '●●●';
	return $masked_local . ( $domain ? '@' . $domain : '' );
}

/**
 * Mask a bank account or routing number for safe display.
 * Shows ●●●● + last 4 digits only.
 */
function custom_truelysell_mask_account_number( $number ) {
	$digits = preg_replace( '/\D/', '', (string) $number );
	if ( ! $digits ) {
		return '';
	}
	if ( strlen( $digits ) < 4 ) {
		return '●●●●';
	}
	return '●●●●' . substr( $digits, -4 );
}

/**
 * Returns a human-readable, masked label for a user's connected payout account.
 * Safe to display in UI and admin screens — never exposes raw credentials.
 */
function custom_truelysell_get_payout_account_label( $user_id, $method ) {
	switch ( $method ) {
		case 'paypal':
			$email = get_user_meta( $user_id, 'truelysell_paypal_payout_email', true );
			return $email ? custom_truelysell_mask_payout_email( $email ) : '';
		case 'stripe':
			$email = get_user_meta( $user_id, 'truelysell_stripe_email', true );
			return $email ? custom_truelysell_mask_payout_email( $email ) : '';
		case 'bank':
			$acct   = get_user_meta( $user_id, 'truelysell_bank_account_number', true );
			$bank   = get_user_meta( $user_id, 'truelysell_bank_name', true );
			$masked = custom_truelysell_mask_account_number( $acct );
			return trim( $bank . ( $masked ? ' (' . $masked . ')' : '' ) );
	}
	return '';
}

/**
 * Returns the human-readable label for a payout method key.
 */
function custom_truelysell_payout_method_label( $method ) {
	$labels = array(
		'paypal' => __( 'PayPal', 'truelysell' ),
		'stripe' => __( 'Stripe', 'truelysell' ),
		'bank'   => __( 'Bank Account', 'truelysell' ),
	);
	return isset( $labels[ $method ] ) ? $labels[ $method ] : __( 'Not set', 'truelysell' );
}

/**
 * Returns the current payout connection status for a technician user.
 *
 * 'connected'     — method chosen and all required fields saved.
 * 'pending'       — method chosen but details are incomplete.
 * 'not_connected' — no method set at all.
 * 'disconnected'  — previously connected, explicitly deactivated.
 *
 * Back-compat: infers and back-fills status from existing field data
 * for technicians who configured their payout before status tracking
 * was added, so legacy users are not wrongly shown as "not connected."
 */
function custom_truelysell_get_payout_status( $user_id ) {
	$status = get_user_meta( $user_id, 'truelysell_payout_status', true );
	if ( $status ) {
		return $status;
	}

	$method = get_user_meta( $user_id, 'truelysell_payout_method', true );
	if ( ! $method ) {
		// Check legacy PayPal-only setup saved by the plugin's own handler.
		$legacy_paypal = get_user_meta( $user_id, 'truelysell_paypal_payout_email', true );
		if ( $legacy_paypal ) {
			update_user_meta( $user_id, 'truelysell_payout_method', 'paypal' );
			update_user_meta( $user_id, 'truelysell_payout_status', 'connected' );
			return 'connected';
		}
		return 'not_connected';
	}

	// Infer from saved field data.
	$is_complete = false;
	switch ( $method ) {
		case 'paypal':
			$is_complete = (bool) get_user_meta( $user_id, 'truelysell_paypal_payout_email', true );
			break;
		case 'stripe':
			$is_complete = (bool) get_user_meta( $user_id, 'truelysell_stripe_email', true );
			break;
		case 'bank':
			$is_complete = get_user_meta( $user_id, 'truelysell_bank_account_holder', true )
				&& get_user_meta( $user_id, 'truelysell_bank_account_number', true );
			break;
	}

	$inferred = $is_complete ? 'connected' : 'pending';
	update_user_meta( $user_id, 'truelysell_payout_status', $inferred );
	return $inferred;
}

/**
 * Guard function — returns true when a technician's payout account is
 * properly connected and they are cleared to receive payouts.
 * Returns true unconditionally for non-technician users.
 */
function custom_truelysell_is_payout_account_ready( $user_id ) {
	if ( ! custom_truelysell_is_restricted_provider( $user_id ) ) {
		return true;
	}
	return 'connected' === custom_truelysell_get_payout_status( $user_id );
}

add_action( 'wp_footer', 'custom_truelysell_render_additional_payout_methods' );
function custom_truelysell_render_additional_payout_methods() {
	$user_id = get_current_user_id();
	if ( ! $user_id || ! custom_truelysell_is_restricted_provider( $user_id ) ) {
		return;
	}

	$payout_page  = function_exists( 'truelysell_fl_framework_getoptions' ) ? truelysell_fl_framework_getoptions( 'payout_page' ) : 0;
	$current_page = get_queried_object_id();
	if ( ! $payout_page || absint( $payout_page ) !== absint( $current_page ) ) {
		return;
	}

	$saved_method          = get_user_meta( $user_id, 'truelysell_payout_method', true );
	$saved_status          = custom_truelysell_get_payout_status( $user_id );
	$saved_stripe_email    = get_user_meta( $user_id, 'truelysell_stripe_email', true );
	$saved_bank_holder     = get_user_meta( $user_id, 'truelysell_bank_account_holder', true );
	$saved_bank_name       = get_user_meta( $user_id, 'truelysell_bank_name', true );
	$saved_bank_account_no = get_user_meta( $user_id, 'truelysell_bank_account_number', true );
	$saved_bank_routing_no = get_user_meta( $user_id, 'truelysell_bank_routing_number', true );
	$connected_date        = get_user_meta( $user_id, 'truelysell_payout_connected_date', true );
	$account_label         = custom_truelysell_get_payout_account_label( $user_id, $saved_method );
	$method_label          = custom_truelysell_payout_method_label( $saved_method );
	$payout_saved          = isset( $_GET['payout_saved'] ) && '1' === $_GET['payout_saved'];
	$is_connected          = $saved_method && 'not_connected' !== $saved_status;

	// Build the status card HTML in PHP so we can pass it as a JS string
	// and inject it BEFORE the tabs container (in the correct DOM position).
	ob_start();
	if ( $is_connected ) {
		?>
		<div id="custom-payout-status-card" class="border bg-light mb-4" style="border-radius:8px; padding:16px 20px;">
			<div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
				<div>
					<div class="text-muted small mb-1"><?php esc_html_e( 'Current Payout Method', 'truelysell' ); ?></div>
					<div class="d-flex align-items-center gap-2 flex-wrap">
						<strong style="font-size:16px;"><?php echo esc_html( $method_label ); ?></strong>
						<span class="custom-payout-badge custom-payout-badge-<?php echo esc_attr( $saved_status ); ?>">
							<?php if ( 'connected' === $saved_status ) : ?>
								<i class="ti ti-check"></i> <?php esc_html_e( 'Connected', 'truelysell' ); ?>
							<?php elseif ( 'pending' === $saved_status ) : ?>
								<i class="ti ti-clock"></i> <?php esc_html_e( 'Pending', 'truelysell' ); ?>
							<?php else : ?>
								<i class="ti ti-x"></i> <?php echo esc_html( ucfirst( str_replace( '_', ' ', $saved_status ) ) ); ?>
							<?php endif; ?>
						</span>
					</div>
					<?php if ( $account_label ) : ?>
					<div class="text-muted small mt-1">
						<?php esc_html_e( 'Account:', 'truelysell' ); ?> <strong><?php echo esc_html( $account_label ); ?></strong>
						<span style="color:#aaa; font-size:11px;">(<?php esc_html_e( 'masked', 'truelysell' ); ?>)</span>
					</div>
					<?php endif; ?>
					<?php if ( $connected_date ) : ?>
					<div class="text-muted small">
						<?php esc_html_e( 'Connected:', 'truelysell' ); ?> <?php echo esc_html( date_i18n( get_option( 'date_format' ), $connected_date ) ); ?>
					</div>
					<?php endif; ?>
				</div>
				<div>
					<button type="button" class="btn btn-outline-primary btn-sm" id="custom-payout-manage-btn">
						<i class="ti ti-settings me-1"></i><?php esc_html_e( 'Manage Payout Account', 'truelysell' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	} else {
		?>
		<div id="custom-payout-status-card" class="border border-warning bg-light mb-4 py-3 px-4" style="border-radius:8px;">
			<div class="d-flex align-items-center gap-2">
				<i class="ti ti-alert-triangle text-warning fs-20"></i>
				<div>
					<strong><?php esc_html_e( 'No payout method connected', 'truelysell' ); ?></strong>
					<div class="text-muted small"><?php esc_html_e( 'Select a payout method below to receive payments for completed jobs.', 'truelysell' ); ?></div>
				</div>
			</div>
		</div>
		<?php
	}
	$status_card_html = trim( ob_get_clean() );

	$success_html = '';
	if ( $payout_saved ) {
		$success_html = '<div class="custom-payout-success-notice"><i class="ti ti-circle-check-filled fs-18"></i> '
			. esc_html__( 'Payout account connected successfully! You will receive future payouts through your selected method.', 'truelysell' )
			. '</div>';
	}

	$confirm_html = '<div id="custom-payout-confirm-overlay" role="dialog" aria-modal="true">'
		. '<div id="custom-payout-confirm-box">'
		. '<h5>' . esc_html__( 'Change Payout Method?', 'truelysell' ) . '</h5>'
		. '<p id="custom-payout-confirm-msg"></p>'
		. '<div class="d-flex gap-2 justify-content-end">'
		. '<button type="button" class="btn btn-light" id="custom-payout-confirm-cancel">' . esc_html__( 'Keep Current', 'truelysell' ) . '</button>'
		. '<button type="button" class="btn btn-primary" id="custom-payout-confirm-proceed">' . esc_html__( 'Yes, Change Method', 'truelysell' ) . '</button>'
		. '</div></div></div>';
	?>
	<style>
	.custom-payout-badge { border-radius: 20px; padding: 3px 12px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; }
	.custom-payout-badge-connected    { color: #1a7f37; background: #d4f7dc; border: 1px solid #a3e6b3; }
	.custom-payout-badge-pending      { color: #9a6700; background: #fff3cd; border: 1px solid #ffe08a; }
	.custom-payout-badge-not_connected,.custom-payout-badge-disconnected { color: #842029; background: #f8d7da; border: 1px solid #f5c2c7; }
	.custom-payout-success-notice { background: #d4f7dc; border: 1px solid #a3e6b3; color: #1a7f37; border-radius: 6px; padding: 12px 16px; margin-bottom: 18px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
	#custom-payout-confirm-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.55); z-index: 99999; align-items: center; justify-content: center; }
	#custom-payout-confirm-overlay.is-open { display: flex; }
	#custom-payout-confirm-box { background: #fff; border-radius: 10px; padding: 28px 32px; max-width: 440px; width: 90%; box-shadow: 0 8px 32px rgba(0,0,0,0.18); }
	#custom-payout-confirm-box h5 { margin: 0 0 12px; font-size: 18px; font-weight: 700; }
	#custom-payout-confirm-box p  { margin: 0 0 20px; color: #555; line-height: 1.5; }
	</style>
	<script type="text/javascript">
	document.addEventListener('DOMContentLoaded', function () {
		var tabsContainer = document.querySelector('.payment.payout-method-tabs');
		var paypalRadio   = document.getElementById('paypal');
		if (!tabsContainer || !paypalRadio) {
			return;
		}

		var savedMethod  = <?php echo wp_json_encode( $saved_method ); ?>;
		var savedStatus  = <?php echo wp_json_encode( $saved_status ); ?>;
		var methodLabel  = <?php echo wp_json_encode( $method_label ); ?>;
		var isConnected  = <?php echo wp_json_encode( $is_connected ); ?>;

		// ── Step 1: Inject the success notice + status card BEFORE the
		//    tabs container. This puts them inline inside the plugin's
		//    form, not as a separate floating footer element.
		var successHtml = <?php echo wp_json_encode( $success_html ); ?>;
		var statusCardHtml = <?php echo wp_json_encode( $status_card_html ); ?>;
		var confirmHtml    = <?php echo wp_json_encode( $confirm_html ); ?>;

		if (successHtml) {
			tabsContainer.insertAdjacentHTML('beforebegin', successHtml);
		}
		tabsContainer.insertAdjacentHTML('beforebegin', statusCardHtml);

		// Confirmation overlay is position:fixed so it lives in <body>.
		document.body.insertAdjacentHTML('beforeend', confirmHtml);

		var manageBtn      = document.getElementById('custom-payout-manage-btn');
		var confirmOvl     = document.getElementById('custom-payout-confirm-overlay');
		var confirmMsg     = document.getElementById('custom-payout-confirm-msg');
		var confirmCancel  = document.getElementById('custom-payout-confirm-cancel');
		var confirmProceed = document.getElementById('custom-payout-confirm-proceed');
		var pendingRadio   = null;

		// ── Step 2: Add Stripe and Bank Account tabs to the existing tabs container.
		var stripeTabHtml =
			'<div class="payment-tab" id="truelysell-stripe-tab">' +
				'<div class="payment-tab-trigger">' +
					'<input id="stripe" name="payment_type" type="radio" value="stripe">' +
					'<label for="stripe"><?php echo esc_js( __( 'Stripe', 'truelysell' ) ); ?></label>' +
				'</div>' +
				'<div class="payment-tab-content" style="display:none;">' +
					'<div class="form-group mb-3">' +
						'<label for="stripe_email" class="form-label"><?php echo esc_js( __( 'Stripe Email / Account ID', 'truelysell' ) ); ?></label>' +
						'<input id="stripe_email" class="form-control" name="stripe_email" type="text" value="<?php echo esc_js( $saved_stripe_email ); ?>" placeholder="<?php echo esc_js( __( 'your@stripe-email.com or acct_XXXXXXXXX', 'truelysell' ) ); ?>">' +
						'<small class="text-muted"><?php echo esc_js( __( 'Enter your Stripe account email or Connected Account ID. Your Stripe login credentials are never stored here.', 'truelysell' ) ); ?></small>' +
					'</div>' +
				'</div>' +
			'</div>';

		var bankTabHtml =
			'<div class="payment-tab" id="truelysell-bank-tab">' +
				'<div class="payment-tab-trigger">' +
					'<input id="bank_account" name="payment_type" type="radio" value="bank">' +
					'<label for="bank_account"><?php echo esc_js( __( 'Bank Account', 'truelysell' ) ); ?></label>' +
				'</div>' +
				'<div class="payment-tab-content" style="display:none;">' +
					'<div class="alert alert-info py-2 px-3 mb-3" style="font-size:13px;">' +
						'<i class="ti ti-lock me-1"></i><?php echo esc_js( __( 'Your banking details are stored securely and are never displayed publicly.', 'truelysell' ) ); ?>' +
					'</div>' +
					'<div class="form-group mb-3">' +
						'<label for="bank_account_holder" class="form-label"><?php echo esc_js( __( 'Account Holder Name', 'truelysell' ) ); ?></label>' +
						'<input id="bank_account_holder" class="form-control" name="bank_account_holder" type="text" value="<?php echo esc_js( $saved_bank_holder ); ?>">' +
					'</div>' +
					'<div class="form-group mb-3">' +
						'<label for="bank_name" class="form-label"><?php echo esc_js( __( 'Bank Name', 'truelysell' ) ); ?></label>' +
						'<input id="bank_name" class="form-control" name="bank_name" type="text" value="<?php echo esc_js( $saved_bank_name ); ?>">' +
					'</div>' +
					'<div class="form-group mb-3">' +
						'<label for="bank_account_number" class="form-label"><?php echo esc_js( __( 'Account Number', 'truelysell' ) ); ?></label>' +
						'<input id="bank_account_number" class="form-control" name="bank_account_number" type="text" value="<?php echo esc_js( $saved_bank_account_no ); ?>" autocomplete="off">' +
					'</div>' +
					'<div class="form-group mb-3">' +
						'<label for="bank_routing_number" class="form-label"><?php echo esc_js( __( 'Routing Number', 'truelysell' ) ); ?></label>' +
						'<input id="bank_routing_number" class="form-control" name="bank_routing_number" type="text" value="<?php echo esc_js( $saved_bank_routing_no ); ?>" autocomplete="off">' +
					'</div>' +
				'</div>' +
			'</div>';

		var paypalTab = paypalRadio.closest('.payment-tab');
		if (paypalTab && paypalTab.parentNode) {
			paypalTab.insertAdjacentHTML('afterend', stripeTabHtml + bankTabHtml);
		} else {
			tabsContainer.insertAdjacentHTML('beforeend', stripeTabHtml + bankTabHtml);
		}

		// ── Step 3: Sync tab content panels based on selected radio.
		function syncActiveTab() {
			tabsContainer.querySelectorAll('input[name="payment_type"]').forEach(function (radio) {
				var tab     = radio.closest('.payment-tab');
				var content = tab ? tab.querySelector('.payment-tab-content') : null;
				var active  = radio.checked;
				if (tab)     { tab.classList.toggle('payment-tab-active', active); }
				if (content) { content.style.display = active ? 'block' : 'none'; }
			});
		}

		// ── Step 4: Confirmation dialog when switching from an already-connected method.
		function openConfirm( radio ) {
			pendingRadio = radio;
			var newLabel = radio.value === 'paypal' ? 'PayPal' : (radio.value === 'stripe' ? 'Stripe' : '<?php echo esc_js( __( 'Bank Account', 'truelysell' ) ); ?>');
			if (confirmMsg) {
				confirmMsg.textContent = '<?php echo esc_js( __( 'You are currently receiving payouts via', 'truelysell' ) ); ?> '
					+ methodLabel + '. <?php echo esc_js( __( 'Switching to', 'truelysell' ) ); ?> '
					+ newLabel + ' <?php echo esc_js( __( 'will replace your current payout connection. Continue?', 'truelysell' ) ); ?>';
			}
			if (confirmOvl) { confirmOvl.classList.add('is-open'); }
		}

		function closeConfirm( proceed ) {
			if (confirmOvl) { confirmOvl.classList.remove('is-open'); }
			if (proceed && pendingRadio) {
				pendingRadio.checked = true;
				syncActiveTab();
			}
			pendingRadio = null;
		}

		if (confirmCancel)  { confirmCancel.addEventListener('click',  function () { closeConfirm(false); }); }
		if (confirmProceed) { confirmProceed.addEventListener('click', function () { closeConfirm(true);  }); }
		if (confirmOvl) {
			confirmOvl.addEventListener('click', function (e) {
				if (e.target === confirmOvl) { closeConfirm(false); }
			});
		}

		tabsContainer.querySelectorAll('input[name="payment_type"]').forEach(function (radio) {
			radio.addEventListener('change', function () {
				if (savedStatus === 'connected' && savedMethod && radio.value !== savedMethod) {
					radio.checked = false;
					openConfirm(radio);
				} else {
					syncActiveTab();
				}
			});
		});

		// Pre-select the saved method.
		if (savedMethod && savedMethod !== 'paypal') {
			var savedRadio = tabsContainer.querySelector('input[name="payment_type"][value="' + savedMethod + '"]');
			if (savedRadio) {
				paypalRadio.checked = false;
				savedRadio.checked  = true;
			}
		}
		syncActiveTab();

		// ── Step 5: "Manage Payout Account" toggle — hides/shows the tabs
		//    container ITSELF (no separate wrapper div needed).
		if (isConnected) {
			tabsContainer.style.display = 'none';
		}

		if (manageBtn) {
			manageBtn.addEventListener('click', function () {
				var hidden = (tabsContainer.style.display === 'none');
				tabsContainer.style.display = hidden ? '' : 'none';
				manageBtn.innerHTML = hidden
					? '<i class="ti ti-x me-1"></i><?php echo esc_js( __( 'Cancel', 'truelysell' ) ); ?>'
					: '<i class="ti ti-settings me-1"></i><?php echo esc_js( __( 'Manage Payout Account', 'truelysell' ) ); ?>';
			});
		}

		// ── Step 6: Override the plugin's AJAX submit so our fields reach the server.
		var payoutForm = document.getElementById('edit_user');
		if (payoutForm) {
			payoutForm.addEventListener('submit', function (e) {
				e.preventDefault();
				e.stopImmediatePropagation();

				var submitBtn = payoutForm.querySelector('button[type="submit"], button:not([type])') || payoutForm.querySelector('button');
				if (submitBtn) { submitBtn.disabled = true; }

				var dest = new URL(payoutForm.action || window.location.href, window.location.href);
				fetch(dest.toString(), { method: 'POST', body: new FormData(payoutForm) })
					.then(function () {
						var redir = new URL(window.location.href);
						redir.searchParams.set('payout_saved', '1');
						window.location.href = redir.toString();
					})
					.catch(function () {
						if (submitBtn) { submitBtn.disabled = false; }
						window.alert(<?php echo wp_json_encode( __( 'Could not save. Please try again.', 'truelysell' ) ); ?>);
					});
			}, true);
		}
	});
	</script>
	<?php
}
/**
 * Saves the payout method + all related fields submitted through the
 * Payout page form. Also sets connection status, date, and masked label.
 *
 * Priority 5 — BEFORE the plugin's own my-account save handler (priority 10)
 * so our fields are read before the plugin can silently discard them.
 * When switching methods, the previous method's data is cleared to prevent
 * stale credentials from lingering.
 */
add_action( 'init', 'custom_truelysell_save_additional_payout_methods', 5 );
function custom_truelysell_save_additional_payout_methods() {
	if ( ! isset( $_POST['my-account-submission'] ) || ! isset( $_POST['payment_type'] ) ) {
		return;
	}

	$user_id = get_current_user_id();
	if ( ! $user_id || ! custom_truelysell_is_restricted_provider( $user_id ) ) {
		return;
	}

	$payment_type = sanitize_text_field( wp_unslash( $_POST['payment_type'] ) );
	if ( ! in_array( $payment_type, array( 'paypal', 'stripe', 'bank' ), true ) ) {
		return;
	}

	$prev_method = get_user_meta( $user_id, 'truelysell_payout_method', true );

	// When switching methods, clear the previous method's stored fields
	// so stale data from the old method doesn't persist or mislead admin.
	if ( $prev_method && $prev_method !== $payment_type ) {
		if ( 'stripe' === $prev_method ) {
			delete_user_meta( $user_id, 'truelysell_stripe_email' );
		}
		if ( 'bank' === $prev_method ) {
			delete_user_meta( $user_id, 'truelysell_bank_account_holder' );
			delete_user_meta( $user_id, 'truelysell_bank_name' );
			delete_user_meta( $user_id, 'truelysell_bank_account_number' );
			delete_user_meta( $user_id, 'truelysell_bank_routing_number' );
		}
		// PayPal email is managed by the plugin's own handler; don't clear
		// it here to avoid conflicting with native plugin behaviour.
		// Reset connected date when switching methods — a new connection starts fresh.
		delete_user_meta( $user_id, 'truelysell_payout_connected_date' );
	}

	update_user_meta( $user_id, 'truelysell_payout_method', $payment_type );

	// Save method-specific fields and determine if the setup is complete.
	$is_complete = false;

	if ( 'paypal' === $payment_type ) {
		// PayPal email is saved by the plugin's own handler via 'ppemail'.
		// Read it now (before the plugin processes it) or fall back to what's stored.
		$paypal_email = isset( $_POST['ppemail'] ) ? sanitize_email( wp_unslash( $_POST['ppemail'] ) ) : get_user_meta( $user_id, 'truelysell_paypal_payout_email', true );
		$is_complete  = ! empty( $paypal_email );
	}

	if ( 'stripe' === $payment_type && isset( $_POST['stripe_email'] ) ) {
		$stripe_val = sanitize_text_field( wp_unslash( $_POST['stripe_email'] ) );
		update_user_meta( $user_id, 'truelysell_stripe_email', $stripe_val );
		$is_complete = ! empty( $stripe_val );
	}

	if ( 'bank' === $payment_type ) {
		$holder  = isset( $_POST['bank_account_holder'] ) ? sanitize_text_field( wp_unslash( $_POST['bank_account_holder'] ) ) : '';
		$bank_nm = isset( $_POST['bank_name'] )           ? sanitize_text_field( wp_unslash( $_POST['bank_name'] ) )           : '';
		$acct_no = isset( $_POST['bank_account_number'] ) ? sanitize_text_field( wp_unslash( $_POST['bank_account_number'] ) ) : '';
		$rout_no = isset( $_POST['bank_routing_number'] ) ? sanitize_text_field( wp_unslash( $_POST['bank_routing_number'] ) ) : '';

		if ( $holder  ) { update_user_meta( $user_id, 'truelysell_bank_account_holder',  $holder  ); }
		if ( $bank_nm ) { update_user_meta( $user_id, 'truelysell_bank_name',             $bank_nm ); }
		if ( $acct_no ) { update_user_meta( $user_id, 'truelysell_bank_account_number',   $acct_no ); }
		if ( $rout_no ) { update_user_meta( $user_id, 'truelysell_bank_routing_number',   $rout_no ); }

		$is_complete = $holder && $acct_no;
	}

	// Set connection status.
	// We no longer auto-connect. When a technician submits details, it goes to 'pending'
	// so the admin can verify the details.
	$new_status = $is_complete ? 'pending' : 'not_connected';
	update_user_meta( $user_id, 'truelysell_payout_status', $new_status );

	// Record the first connection date if somehow marked connected (never overwrite once set).
	if ( 'connected' === $new_status && ! get_user_meta( $user_id, 'truelysell_payout_connected_date', true ) ) {
		update_user_meta( $user_id, 'truelysell_payout_connected_date', current_time( 'timestamp' ) );
	}

	// Store the masked account label for quick display without re-reading raw fields.
	$label = custom_truelysell_get_payout_account_label( $user_id, $payment_type );
	update_user_meta( $user_id, 'truelysell_payout_account_label', $label );
}

/**
 * Handle admin updating the payout status from the profile screen
 */
add_action( 'edit_user_profile_update', 'custom_truelysell_admin_update_payout_status' );
function custom_truelysell_admin_update_payout_status( $user_id ) {
	if ( ! current_user_can( 'edit_user', $user_id ) || ! isset( $_POST['admin_payout_status'] ) ) {
		return;
	}
	
	$new_status = sanitize_text_field( $_POST['admin_payout_status'] );
	update_user_meta( $user_id, 'truelysell_payout_status', $new_status );
	
	if ( 'connected' === $new_status && ! get_user_meta( $user_id, 'truelysell_payout_connected_date', true ) ) {
		update_user_meta( $user_id, 'truelysell_payout_connected_date', current_time( 'timestamp' ) );
	}
}

/**
 * Show the technician's payout method, status, date, and FULL account details
 * on their WP Admin profile screen. Admins need full visibility to transfer funds.
 */
add_action( 'show_user_profile', 'custom_truelysell_show_payout_method_on_profile' );
add_action( 'edit_user_profile', 'custom_truelysell_show_payout_method_on_profile' );
function custom_truelysell_show_payout_method_on_profile( $user ) {
	if ( ! custom_truelysell_is_restricted_provider( $user->ID ) ) {
		return;
	}

	$method         = get_user_meta( $user->ID, 'truelysell_payout_method', true );
	$status         = custom_truelysell_get_payout_status( $user->ID );
	$connected_date = get_user_meta( $user->ID, 'truelysell_payout_connected_date', true );

	if ( ! $method ) {
		$legacy = get_user_meta( $user->ID, 'truelysell_paypal_payout_email', true );
		if ( $legacy ) {
			$method = 'paypal';
		}
	}
	
	// Fetch raw unmasked details for the admin.
	$raw_account_label = '';
	if ( 'paypal' === $method ) {
		$raw_account_label = get_user_meta( $user->ID, 'truelysell_paypal_payout_email', true );
	} elseif ( 'stripe' === $method ) {
		$raw_account_label = get_user_meta( $user->ID, 'truelysell_stripe_email', true );
	}

	$status_display = array(
		'connected'     => array( 'text' => '✓ ' . __( 'Connected', 'truelysell' ),           'color' => '#1a7f37' ),
		'pending'       => array( 'text' => '⚠ ' . __( 'Pending Verification', 'truelysell' ), 'color' => '#9a6700' ),
		'not_connected' => array( 'text' => '✗ ' . __( 'Not Connected', 'truelysell' ),        'color' => '#842029' ),
		'disconnected'  => array( 'text' => '✗ ' . __( 'Disconnected', 'truelysell' ),         'color' => '#842029' ),
	);
	$sd = isset( $status_display[ $status ] ) ? $status_display[ $status ] : array( 'text' => esc_html( $status ), 'color' => '#555' );
	?>
	<h2><?php esc_html_e( 'Payout Method (Admin View)', 'truelysell' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th><?php esc_html_e( 'Method', 'truelysell' ); ?></th>
			<td>
				<?php if ( $method ) : ?>
					<strong><?php echo esc_html( custom_truelysell_payout_method_label( $method ) ); ?></strong>
				<?php else : ?>
					<em style="color:#787c82;"><?php esc_html_e( 'Not set', 'truelysell' ); ?></em>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Verification Status', 'truelysell' ); ?></th>
			<td>
				<select name="admin_payout_status" id="admin_payout_status">
					<option value="pending" <?php selected( $status, 'pending' ); ?>><?php esc_html_e( 'Pending Verification', 'truelysell' ); ?></option>
					<option value="connected" <?php selected( $status, 'connected' ); ?>><?php esc_html_e( 'Verified & Connected', 'truelysell' ); ?></option>
					<option value="disconnected" <?php selected( $status, 'disconnected' ); ?>><?php esc_html_e( 'Disconnected', 'truelysell' ); ?></option>
					<option value="not_connected" <?php selected( $status, 'not_connected' ); ?>><?php esc_html_e( 'Not Connected', 'truelysell' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Change this to "Verified & Connected" after reviewing their details.', 'truelysell' ); ?></p>
			</td>
		</tr>
		<?php if ( 'paypal' === $method || 'stripe' === $method ) : ?>
		<tr>
			<th><?php esc_html_e( 'Account Detail', 'truelysell' ); ?></th>
			<td>
				<strong><?php echo esc_html( $raw_account_label ); ?></strong>
				<p class="description"><?php esc_html_e( '(Only visible to Admins)', 'truelysell' ); ?></p>
			</td>
		</tr>
		<?php endif; ?>
		<?php if ( $connected_date ) : ?>
		<tr>
			<th><?php esc_html_e( 'Date Connected', 'truelysell' ); ?></th>
			<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), $connected_date ) ); ?></td>
		</tr>
		<?php endif; ?>
		<?php if ( 'bank' === $method ) : ?>
		<tr>
			<th><?php esc_html_e( 'Bank Details', 'truelysell' ); ?></th>
			<td>
				<?php $holder = get_user_meta( $user->ID, 'truelysell_bank_account_holder', true ); ?>
				<?php $bank   = get_user_meta( $user->ID, 'truelysell_bank_name', true ); ?>
				<?php if ( $holder ) : ?>
					<strong><?php esc_html_e( 'Holder:', 'truelysell' ); ?></strong> <?php echo esc_html( $holder ); ?><br>
				<?php endif; ?>
				<?php if ( $bank ) : ?>
					<strong><?php esc_html_e( 'Bank:', 'truelysell' ); ?></strong> <?php echo esc_html( $bank ); ?><br>
				<?php endif; ?>
				<strong><?php esc_html_e( 'Account:', 'truelysell' ); ?></strong>
				<?php echo esc_html( get_user_meta( $user->ID, 'truelysell_bank_account_number', true ) ); ?><br>
				<strong><?php esc_html_e( 'Routing:', 'truelysell' ); ?></strong>
				<?php echo esc_html( get_user_meta( $user->ID, 'truelysell_bank_routing_number', true ) ); ?>
				<p class="description"><?php esc_html_e( '(Full bank details are only visible to Admins)', 'truelysell' ); ?></p>
			</td>
		</tr>
		<?php endif; ?>
	</table>
	<?php
}

// ============================================================
// ADMIN: PAYOUT METHOD COLUMN IN USERS LIST TABLE
// Shows each technician's payout method and connection status badge
// directly in the Users list, so admin doesn't need to open each profile.
// ============================================================

add_filter( 'manage_users_columns', 'custom_truelysell_add_payout_method_column' );
function custom_truelysell_add_payout_method_column( $columns ) {
	$columns['custom_ts_payout'] = __( 'Payout Method', 'truelysell' );
	return $columns;
}

add_filter( 'manage_users_custom_column', 'custom_truelysell_render_payout_method_column', 10, 3 );
function custom_truelysell_render_payout_method_column( $value, $column_name, $user_id ) {
	if ( 'custom_ts_payout' !== $column_name ) {
		return $value;
	}

	if ( ! custom_truelysell_is_restricted_provider( $user_id ) ) {
		return '—';
	}

	$method = get_user_meta( $user_id, 'truelysell_payout_method', true );
	if ( ! $method ) {
		$legacy = get_user_meta( $user_id, 'truelysell_paypal_payout_email', true );
		if ( $legacy ) {
			$method = 'paypal';
		}
	}

	if ( ! $method ) {
		return '<span style="color:#842029; font-size:12px;">✗ Not set</span>';
	}

	$status       = custom_truelysell_get_payout_status( $user_id );
	$method_label = custom_truelysell_payout_method_label( $method );

	$badge_styles = array(
		'connected'     => 'background:#d4f7dc; color:#1a7f37; border:1px solid #a3e6b3;',
		'pending'       => 'background:#fff3cd; color:#9a6700; border:1px solid #ffe08a;',
		'not_connected' => 'background:#f8d7da; color:#842029; border:1px solid #f5c2c7;',
		'disconnected'  => 'background:#f8d7da; color:#842029; border:1px solid #f5c2c7;',
	);
	$badge_style = isset( $badge_styles[ $status ] ) ? $badge_styles[ $status ] : 'background:#e9ecef; color:#555;';

	$status_texts = array(
		'connected'     => '✓ ' . __( 'Connected', 'truelysell' ),
		'pending'       => '⚠ ' . __( 'Pending', 'truelysell' ),
		'not_connected' => '✗ ' . __( 'Not set', 'truelysell' ),
		'disconnected'  => '✗ ' . __( 'Off', 'truelysell' ),
	);
	$status_text = isset( $status_texts[ $status ] ) ? $status_texts[ $status ] : esc_html( $status );

	return '<div style="white-space:nowrap;">' .
		'<strong>' . esc_html( $method_label ) . '</strong><br>' .
		'<span style="font-size:11px; padding:1px 6px; border-radius:10px; ' . esc_attr( $badge_style ) . '">' . esc_html( $status_text ) . '</span>' .
		'</div>';
}

// ============================================================
// ADMIN: TECHNICIAN PAYOUT MANAGEMENT PAGE
// Users → Technician Payouts — a dedicated sortable table showing
// every technician's payout method, status, masked account label,
// and date connected. No raw credentials are ever shown.
// ============================================================

add_action( 'admin_menu', 'custom_truelysell_register_payout_admin_menu' );
function custom_truelysell_register_payout_admin_menu() {
	if ( ! current_user_can( 'administrator' ) ) {
		return;
	}
	add_users_page(
		__( 'Technician Payouts', 'truelysell' ),
		__( 'Technician Payouts', 'truelysell' ),
		'administrator',
		'custom-truelysell-payouts',
		'custom_truelysell_render_payout_admin_page'
	);
}

function custom_truelysell_render_payout_admin_page() {
	if ( ! current_user_can( 'administrator' ) ) {
		wp_die( esc_html__( 'Access denied.', 'truelysell' ) );
	}

	$filter_method = isset( $_GET['filter_method'] ) ? sanitize_text_field( $_GET['filter_method'] ) : '';
	$filter_status = isset( $_GET['filter_status'] ) ? sanitize_text_field( $_GET['filter_status'] ) : '';

	// Collect all technician users (by role and by is_technician meta).
	$by_role = get_users( array(
		'role__in' => array( 'owner', 'provider' ),
		'orderby'  => 'display_name',
		'order'    => 'ASC',
	) );
	$by_meta = get_users( array(
		'meta_key'   => 'is_technician',
		'meta_value' => '1',
		'orderby'    => 'display_name',
		'order'      => 'ASC',
	) );

	$all_users = array();
	foreach ( array_merge( $by_role, $by_meta ) as $u ) {
		$all_users[ $u->ID ] = $u;
	}

	$rows = array();
	foreach ( $all_users as $user_id => $user ) {
		// Admins are never technicians (same rule as custom_truelysell_is_restricted_provider).
		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			continue;
		}

		$method = get_user_meta( $user_id, 'truelysell_payout_method', true );
		if ( ! $method ) {
			$legacy = get_user_meta( $user_id, 'truelysell_paypal_payout_email', true );
			if ( $legacy ) {
				$method = 'paypal';
			}
		}

		$status = custom_truelysell_get_payout_status( $user_id );
		
		// Fetch raw unmasked details for the admin table.
		$label = '';
		if ( 'paypal' === $method ) {
			$label = get_user_meta( $user_id, 'truelysell_paypal_payout_email', true );
		} elseif ( 'stripe' === $method ) {
			$label = get_user_meta( $user_id, 'truelysell_stripe_email', true );
		} elseif ( 'bank' === $method ) {
			$acct = get_user_meta( $user_id, 'truelysell_bank_account_number', true );
			$rout = get_user_meta( $user_id, 'truelysell_bank_routing_number', true );
			$label = ( $acct || $rout ) ? "Acct: $acct | Rout: $rout" : '';
		}
		
		$date = get_user_meta( $user_id, 'truelysell_payout_connected_date', true );

		if ( $filter_method && $method !== $filter_method ) {
			continue;
		}
		if ( $filter_status && $status !== $filter_status ) {
			continue;
		}

		$rows[] = compact( 'user_id', 'user', 'method', 'status', 'label', 'date' );
	}

	$methods_list = array(
		''       => __( 'All Methods', 'truelysell' ),
		'paypal' => __( 'PayPal', 'truelysell' ),
		'stripe' => __( 'Stripe', 'truelysell' ),
		'bank'   => __( 'Bank Account', 'truelysell' ),
	);
	$statuses_list = array(
		''              => __( 'All Statuses', 'truelysell' ),
		'connected'     => __( 'Connected', 'truelysell' ),
		'pending'       => __( 'Pending', 'truelysell' ),
		'not_connected' => __( 'Not Connected', 'truelysell' ),
		'disconnected'  => __( 'Disconnected', 'truelysell' ),
	);

	$badge_styles = array(
		'connected'     => 'background:#d4f7dc; color:#1a7f37;',
		'pending'       => 'background:#fff3cd; color:#9a6700;',
		'not_connected' => 'background:#f8d7da; color:#842029;',
		'disconnected'  => 'background:#f8d7da; color:#842029;',
	);
	$status_labels = array(
		'connected'     => '✓ ' . __( 'Connected', 'truelysell' ),
		'pending'       => '⚠ ' . __( 'Pending', 'truelysell' ),
		'not_connected' => '✗ ' . __( 'Not Connected', 'truelysell' ),
		'disconnected'  => '✗ ' . __( 'Disconnected', 'truelysell' ),
	);
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Technician Payouts', 'truelysell' ); ?></h1>
		<hr class="wp-header-end">

		<form method="get" style="margin:16px 0; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
			<input type="hidden" name="page" value="custom-truelysell-payouts">
			<select name="filter_method">
				<?php foreach ( $methods_list as $val => $lbl ) : ?>
					<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $filter_method, $val ); ?>><?php echo esc_html( $lbl ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="filter_status">
				<?php foreach ( $statuses_list as $val => $lbl ) : ?>
					<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $filter_status, $val ); ?>><?php echo esc_html( $lbl ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'truelysell' ); ?></button>
			<a href="<?php echo esc_url( admin_url( 'users.php?page=custom-truelysell-payouts' ) ); ?>" class="button"><?php esc_html_e( 'Reset', 'truelysell' ); ?></a>
			<span style="color:#555; margin-left:8px; font-size:13px;">
				<?php printf( esc_html__( '%d technician(s) shown', 'truelysell' ), count( $rows ) ); ?>
			</span>
		</form>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width:180px;"><?php esc_html_e( 'Technician', 'truelysell' ); ?></th>
					<th style="width:200px;"><?php esc_html_e( 'Email', 'truelysell' ); ?></th>
					<th style="width:120px;"><?php esc_html_e( 'Payout Method', 'truelysell' ); ?></th>
					<th style="width:150px;"><?php esc_html_e( 'Status', 'truelysell' ); ?></th>
					<th><?php esc_html_e( 'Account / Connection ID', 'truelysell' ); ?></th>
					<th style="width:120px;"><?php esc_html_e( 'Date Connected', 'truelysell' ); ?></th>
					<th style="width:80px;"><?php esc_html_e( 'Actions', 'truelysell' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr>
					<td colspan="7">
						<em><?php esc_html_e( 'No technicians found matching the selected filters.', 'truelysell' ); ?></em>
					</td>
				</tr>
			<?php else : ?>
				<?php foreach ( $rows as $row ) :
					$bs  = isset( $badge_styles[ $row['status'] ] ) ? $badge_styles[ $row['status'] ] : 'background:#e9ecef; color:#555;';
					$sl  = isset( $status_labels[ $row['status'] ] ) ? $status_labels[ $row['status'] ] : esc_html( $row['status'] );
				?>
				<tr>
					<td><strong><?php echo esc_html( $row['user']->display_name ); ?></strong></td>
					<td style="word-break:break-all;"><?php echo esc_html( $row['user']->user_email ); ?></td>
					<td><?php echo $row['method'] ? esc_html( custom_truelysell_payout_method_label( $row['method'] ) ) : '<em style="color:#999;">—</em>'; ?></td>
					<td>
						<span style="font-size:12px; padding:2px 8px; border-radius:12px; font-weight:600; <?php echo esc_attr( $bs ); ?>">
							<?php echo esc_html( $sl ); ?>
						</span>
					</td>
					<td><?php echo $row['label'] ? esc_html( $row['label'] ) : '<em style="color:#999;">—</em>'; ?></td>
					<td><?php echo $row['date'] ? esc_html( date_i18n( get_option( 'date_format' ), $row['date'] ) ) : '<em style="color:#999;">—</em>'; ?></td>
					<td><a href="<?php echo esc_url( get_edit_user_link( $row['user_id'] ) ); ?>" class="button button-small"><?php esc_html_e( 'View', 'truelysell' ); ?></a></td>
				</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<p class="description" style="margin-top:12px;">
			<strong><?php esc_html_e( 'Security note:', 'truelysell' ); ?></strong>
			<?php esc_html_e( 'Full account numbers and routing details are visible on this page. These details are only visible to Administrators and are masked for all other users.', 'truelysell' ); ?>
		</p>
	</div>
	<?php
}

// ============================================================
// PAYOUT GUARD — Block payouts and show alerts when a technician's
// payout account is not properly connected or verified.
// ============================================================

/**
 * Shows a bottom-bar alert on provider dashboard pages when their payout
 * account is not connected. Guides them to the Payout page to fix it.
 * Not shown on the Payout page itself (where the form already handles this).
 */
add_action( 'wp_footer', 'custom_truelysell_payout_guard_notice' );
function custom_truelysell_payout_guard_notice() {
	$user_id = get_current_user_id();
	if ( ! $user_id || ! custom_truelysell_is_restricted_provider( $user_id ) ) {
		return;
	}

	$status = custom_truelysell_get_payout_status( $user_id );
	if ( 'connected' === $status ) {
		return;
	}

	$payout_page  = function_exists( 'truelysell_fl_framework_getoptions' ) ? truelysell_fl_framework_getoptions( 'payout_page' ) : 0;
	$current_page = get_queried_object_id();

	// Don't show on the Payout page itself — it has its own status card.
	if ( $payout_page && absint( $payout_page ) === absint( $current_page ) ) {
		return;
	}

	// Only show on provider-facing dashboard pages.
	$dashboard_page = function_exists( 'truelysell_fl_framework_getoptions' ) ? truelysell_fl_framework_getoptions( 'dashboard_page' ) : 0;
	$bookings_page  = function_exists( 'truelysell_fl_framework_getoptions' ) ? truelysell_fl_framework_getoptions( 'bookings_page' ) : 0;
	$wallet_page    = function_exists( 'truelysell_fl_framework_getoptions' ) ? truelysell_fl_framework_getoptions( 'wallet_page' ) : 0;

	$on_provider_page = (
		( $dashboard_page && absint( $dashboard_page ) === absint( $current_page ) ) ||
		( $bookings_page  && absint( $bookings_page )  === absint( $current_page ) ) ||
		( $wallet_page    && absint( $wallet_page )    === absint( $current_page ) )
	);

	if ( ! $on_provider_page ) {
		return;
	}

	$messages = array(
		'pending'       => __( 'Your payout account setup is incomplete. Please finish connecting your payout method to ensure you receive payments for completed jobs.', 'truelysell' ),
		'disconnected'  => __( 'Your payout account has been disconnected. Please reconnect your payout method to continue receiving payments.', 'truelysell' ),
		'not_connected' => __( 'You have not connected a payout method. You will not receive payouts until you connect a payout method.', 'truelysell' ),
	);
	$message  = isset( $messages[ $status ] ) ? $messages[ $status ] : $messages['not_connected'];
	$payout_url = $payout_page ? esc_js( get_permalink( $payout_page ) ) : '';
	?>
	<script type="text/javascript">
	document.addEventListener('DOMContentLoaded', function () {
		var banner = document.createElement('div');
		banner.style.cssText = 'position:fixed;bottom:0;left:0;right:0;z-index:9998;background:#fff3cd;border-top:2px solid #ffe08a;color:#664d03;padding:10px 20px;display:flex;align-items:center;justify-content:space-between;gap:12px;font-size:14px;box-shadow:0 -2px 8px rgba(0,0,0,0.1);';
		banner.innerHTML =
			'<span><strong>⚠ <?php echo esc_js( __( 'Payout Account Alert:', 'truelysell' ) ); ?></strong> <?php echo esc_js( $message ); ?></span>' +
			'<div style="display:flex;align-items:center;gap:8px;flex-shrink:0;">' +
			<?php if ( $payout_url ) : ?>
			'<a href="<?php echo $payout_url; ?>" style="background:#ff9f43;color:#fff;padding:6px 14px;border-radius:4px;text-decoration:none;font-weight:600;white-space:nowrap;font-size:13px;"><?php echo esc_js( __( 'Connect Payout Method →', 'truelysell' ) ); ?></a>' +
			<?php endif; ?>
			'<button onclick="this.closest(\'div\').parentNode.style.display=\'none\';" style="background:none;border:none;cursor:pointer;font-size:20px;color:#664d03;padding:0 4px;line-height:1;" aria-label="Dismiss">×</button>' +
			'</div>';
		document.body.appendChild(banner);
	});
	</script>
	<?php
}

// ============================================================
// GLOBAL TEXT FIX: Replace all "Favourites" -> "Favorites" site-wide
// ============================================================
add_action( 'wp_footer', 'custom_global_favorites_text_fix', 99 );
function custom_global_favorites_text_fix() {
    ?>
    <script>
    (function () {
        function replaceTextNodes(node) {
            if (node.nodeType === 3) {
                if (node.nodeValue.indexOf('Favourites') !== -1) {
                    node.nodeValue = node.nodeValue.replace(/Favourites/g, 'Favorites');
                }
            } else if (node.nodeType === 1 && node.tagName !== 'SCRIPT' && node.tagName !== 'STYLE') {
                for (var i = 0; i < node.childNodes.length; i++) {
                    replaceTextNodes(node.childNodes[i]);
                }
            }
        }
        document.addEventListener('DOMContentLoaded', function () {
            replaceTextNodes(document.body);
        });
    })();
    </script>
    <?php
}

// ============================================================
// ASSIGNED TECHNICIAN
// An admin can explicitly assign one technician/provider to a shared
// listing via the metabox below. That assignment drives booking credit
// (the technician, not admin, is credited for bookings on the listing)
// and which provider is shown as "Service Provider" on the public page.
// ============================================================

/**
 * ADMIN: "Assigned Technician" metabox on the listing edit screen.
 */
add_action( 'add_meta_boxes', 'custom_truelysell_add_assigned_technician_metabox' );
function custom_truelysell_add_assigned_technician_metabox() {
	if ( ! current_user_can( 'administrator' ) ) {
		return;
	}

	add_meta_box(
		'custom_truelysell_assigned_technician',
		__( 'Assigned Technician', 'truelysell' ),
		'custom_truelysell_render_assigned_technician_metabox',
		'listing',
		'normal',
		'high'
	);
}

function custom_truelysell_render_assigned_technician_metabox( $post ) {
	wp_nonce_field( 'custom_truelysell_assigned_technician_save', 'custom_truelysell_assigned_technician_nonce' );

	$assigned_id = absint( get_post_meta( $post->ID, '_assigned_technician_id', true ) );

	$technicians = get_users( array(
		'role__in' => array( 'owner', 'provider' ),
		'orderby'  => 'display_name',
		'order'    => 'ASC',
		'fields'   => array( 'ID', 'display_name', 'user_email' ),
	) );
	/*
	 * Filtered through custom_truelysell_is_restricted_provider() (which
	 * returns false for a deleted user), not just the raw linked-provider
	 * IDs — otherwise a deleted provider's ID (nothing previously cleaned
	 * those out of _linked_provider_ids) kept inflating this count even
	 * though their name never showed in the list below it.
	 */
	$linked_provider_ids = array_filter( custom_truelysell_get_listing_linked_provider_ids( $post->ID ), 'custom_truelysell_is_restricted_provider' );
	$linked_names        = array();
	foreach ( $linked_provider_ids as $linked_id ) {
		$linked_user = get_userdata( $linked_id );
		if ( $linked_user ) {
			$has_location   = get_user_meta( $linked_id, 'profile-lat', true ) && get_user_meta( $linked_id, 'profile-lng', true );
			$linked_names[] = $linked_user->display_name . ' (#' . $linked_id . ')' . ( $has_location ? '' : ' — no location set' );
		}
	}
	?>
	<p class="description"><?php esc_html_e( 'Bookings for this service will be credited to the technician assigned here, and they will be shown as the Service Provider on the public listing page.', 'truelysell' ); ?></p>
	<select name="custom_truelysell_assigned_technician_id" style="width:100%;">
		<option value="0"><?php esc_html_e( '— None —', 'truelysell' ); ?></option>
		<?php foreach ( $technicians as $technician ) : ?>
			<option value="<?php echo esc_attr( $technician->ID ); ?>" <?php selected( $assigned_id, $technician->ID ); ?>>
				<?php echo esc_html( $technician->display_name . ' (' . $technician->user_email . ')' ); ?>
			</option>
		<?php endforeach; ?>
	</select>

	<p class="description" style="margin-top:12px; padding-top:12px; border-top:1px solid #dcdcde;">
		<strong><?php printf( esc_html__( 'Linked Providers (%d):', 'truelysell' ), count( $linked_provider_ids ) ); ?></strong>
		<?php if ( $linked_names ) : ?>
			<?php echo esc_html( implode( ', ', $linked_names ) ); ?>
		<?php else : ?>
			<em><?php esc_html_e( 'None linked yet — no provider has added this service to their account.', 'truelysell' ); ?></em>
		<?php endif; ?>
		<br>
		<span style="color:#787c82;"><?php esc_html_e( 'This is every provider who has this service in their "My Services" — customers pick from this same list at booking time.', 'truelysell' ); ?></span>
	</p>
	<?php
}

add_action( 'save_post_listing', 'custom_truelysell_save_assigned_technician_metabox' );
function custom_truelysell_save_assigned_technician_metabox( $post_id ) {
	if ( ! isset( $_POST['custom_truelysell_assigned_technician_nonce'] ) ||
		! wp_verify_nonce( $_POST['custom_truelysell_assigned_technician_nonce'], 'custom_truelysell_assigned_technician_save' ) ) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( ! current_user_can( 'administrator' ) ) {
		return;
	}

	$technician_id = isset( $_POST['custom_truelysell_assigned_technician_id'] ) ? absint( $_POST['custom_truelysell_assigned_technician_id'] ) : 0;

	if ( $technician_id ) {
		update_post_meta( $post_id, '_assigned_technician_id', $technician_id );
	} else {
		delete_post_meta( $post_id, '_assigned_technician_id' );
	}
}


/**
 * ADMIN: show the services a provider/technician selected at registration,
 * both as a count in the Users list table and as a named list on their
 * profile edit screen, so admin doesn't have to look it up manually.
 */
add_filter( 'manage_users_columns', 'custom_truelysell_add_services_column' );
function custom_truelysell_add_services_column( $columns ) {
	$columns['custom_truelysell_services'] = __( 'Services Selected', 'truelysell' );
	return $columns;
}

add_filter( 'manage_users_custom_column', 'custom_truelysell_render_services_column', 10, 3 );
function custom_truelysell_render_services_column( $value, $column_name, $user_id ) {
	if ( 'custom_truelysell_services' !== $column_name ) {
		return $value;
	}

	if ( ! custom_truelysell_is_restricted_provider( $user_id ) ) {
		return '—';
	}

	return (string) count( custom_truelysell_get_provider_real_listing_ids( $user_id ) );
}

add_action( 'show_user_profile', 'custom_truelysell_show_services_on_profile' );
add_action( 'edit_user_profile', 'custom_truelysell_show_services_on_profile' );
function custom_truelysell_show_services_on_profile( $user ) {
	if ( ! custom_truelysell_is_restricted_provider( $user->ID ) ) {
		return;
	}

	$listing_ids = custom_truelysell_get_provider_real_listing_ids( $user->ID );
	?>
	<h2><?php esc_html_e( 'Services Selected', 'truelysell' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th><?php esc_html_e( 'Services', 'truelysell' ); ?></th>
			<td>
				<?php if ( empty( $listing_ids ) ) : ?>
					<p><?php esc_html_e( 'No services selected.', 'truelysell' ); ?></p>
				<?php else : ?>
					<p><strong><?php echo esc_html( count( $listing_ids ) ); ?></strong> <?php esc_html_e( 'service(s):', 'truelysell' ); ?></p>
					<ul style="list-style: disc; margin-left: 20px;">
						<?php foreach ( $listing_ids as $listing_id ) : ?>
							<li><a href="<?php echo esc_url( get_edit_post_link( $listing_id ) ); ?>"><?php echo esc_html( get_the_title( $listing_id ) ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</td>
		</tr>
	</table>
	<?php
}

/**
 * ------------------------------------------------------------------
 * PROVIDER → CUSTOMER REVIEWS
 * ------------------------------------------------------------------
 * The plugin's native review system only supports a customer reviewing a
 * listing (comments attached to the `listing` post) — there is no way for
 * a provider to review the customer they served, and no data model for a
 * review to attach to a person rather than a listing. This is a small
 * parallel system built the same way the plugin builds reviews (WordPress
 * comments + comment meta), but with its own comment_type
 * ('customer_review') so it never mixes with the plugin's own public
 * listing reviews. These reviews only ever show in the customer's own
 * private dashboard — there is no public customer profile page.
 */

/**
 * A single booking row from the `bookings_calendar` table, or null.
 */
function custom_truelysell_get_booking_by_id( $booking_id ) {
	global $wpdb;
	$bookings_table = $wpdb->prefix . 'bookings_calendar';

	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bookings_table} WHERE ID = %d", $booking_id ) );
}

/**
 * One review per booking, matching how the native system limits a
 * customer to one review per listing.
 */
function custom_truelysell_booking_has_customer_review( $booking_id ) {
	$existing = get_comments( array(
		'type'       => 'customer_review',
		'meta_key'   => '_customer_review_booking_id',
		'meta_value' => $booking_id,
		'count'      => true,
	) );

	return $existing > 0;
}

/**
 * The plugin has no distinct "completed" booking status (only waiting,
 * pay_to_confirm, confirmed, paid, cancelled, expired — see
 * truelysell-core/templates/booking/content-booking.php) — this is the
 * closest real equivalent: it actually happened (confirmed/paid, not
 * cancelled) and its scheduled time has passed.
 */
function custom_truelysell_is_booking_completed( $booking ) {
	if ( ! $booking || ! in_array( $booking->status, array( 'confirmed', 'paid' ), true ) ) {
		return false;
	}

	return strtotime( $booking->date_end ) < current_time( 'timestamp' );
}

/**
 * This provider's completed bookings that don't have a customer review
 * yet — used to decide which booking rows get a "Rate Customer" button.
 */
function custom_truelysell_get_reviewable_bookings_for_provider( $provider_id ) {
	if ( ! function_exists( 'truelysell_get_provider_bookings' ) ) {
		return array();
	}

	$bookings = truelysell_get_provider_bookings( $provider_id );

	return array_values( array_filter( $bookings, function( $booking ) {
		return custom_truelysell_is_booking_completed( $booking ) && ! custom_truelysell_booking_has_customer_review( $booking->ID );
	} ) );
}

/**
 * Inject a "Rate Customer" button onto each reviewable booking row on the
 * provider's Booking List page, plus the shared modal that submits it.
 */
add_action( 'wp_footer', 'custom_truelysell_render_rate_customer_ui' );
function custom_truelysell_render_rate_customer_ui() {
	$user_id = get_current_user_id();
	if ( ! $user_id || ! custom_truelysell_is_restricted_provider( $user_id ) ) {
		return;
	}

	/*
	 * A booking row can appear on either the Booking List page OR the
	 * Dashboard page's "Recent Bookings" widget (same row markup, same
	 * #booking-list-{ID} element) — check both, not just Booking List.
	 */
	$bookings_page  = function_exists( 'truelysell_fl_framework_getoptions' ) ? truelysell_fl_framework_getoptions( 'bookings_page' ) : 0;
	$dashboard_page = function_exists( 'truelysell_fl_framework_getoptions' ) ? truelysell_fl_framework_getoptions( 'dashboard_page' ) : 0;
	$current_page   = get_queried_object_id();
	$on_relevant_page = ( $bookings_page && absint( $bookings_page ) === absint( $current_page ) )
		|| ( $dashboard_page && absint( $dashboard_page ) === absint( $current_page ) );

	if ( ! $on_relevant_page ) {
		return;
	}

	$reviewable = custom_truelysell_get_reviewable_bookings_for_provider( $user_id );
	if ( empty( $reviewable ) ) {
		return;
	}

	$bookings_for_js = array();
	foreach ( $reviewable as $booking ) {
		$customer                                  = get_userdata( $booking->bookings_author );
		$bookings_for_js[ absint( $booking->ID ) ] = $customer ? $customer->display_name : __( 'this customer', 'truelysell' );
	}

	$ajax_url = admin_url( 'admin-ajax.php' );
	$nonce    = wp_create_nonce( 'custom_truelysell_customer_review' );
	?>
	<div class="modal fade custom-modal" id="custom-truelysell-rate-customer-modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content">
				<div class="modal-header border-bottom">
					<h5 class="modal-title fw-bold"><?php esc_html_e( 'Rate Customer', 'truelysell' ); ?> — <span id="custom-truelysell-rate-customer-name"></span></h5>
					<a href="javascript:void(0);" data-bs-dismiss="modal" aria-label="Close"><i class="ti ti-circle-x-filled fs-20"></i></a>
				</div>
				<div class="modal-body">
					<div id="custom-truelysell-rate-customer-message"></div>
					<div class="mb-3" id="custom-truelysell-star-rating">
						<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
							<i class="fa fa-star" data-value="<?php echo esc_attr( $i ); ?>" style="cursor:pointer; font-size:24px; margin-right:4px; color:#ddd;"></i>
						<?php endfor; ?>
					</div>
					<textarea class="form-control" id="custom-truelysell-rate-customer-comment" rows="3" placeholder="<?php esc_attr_e( 'Optional comment about this customer...', 'truelysell' ); ?>"></textarea>
				</div>
				<div class="modal-footer border-top">
					<button type="button" class="btn btn-light" data-bs-dismiss="modal"><?php esc_html_e( 'Cancel', 'truelysell' ); ?></button>
					<button type="button" class="btn btn-primary" id="custom-truelysell-submit-customer-review"><?php esc_html_e( 'Submit Rating', 'truelysell' ); ?></button>
				</div>
			</div>
		</div>
	</div>
	<script type="text/javascript">
	document.addEventListener('DOMContentLoaded', function () {
		var ajaxUrl      = <?php echo wp_json_encode( $ajax_url ); ?>;
		var nonce        = <?php echo wp_json_encode( $nonce ); ?>;
		var bookingNames = <?php echo wp_json_encode( $bookings_for_js ); ?>;
		var modalEl = document.getElementById('custom-truelysell-rate-customer-modal');
		if (!modalEl) return;
		var modal = new bootstrap.Modal(modalEl);
		var currentBookingId = 0;
		var currentRating = 0;

		var stars = document.querySelectorAll('#custom-truelysell-star-rating .fa-star');
		function paintStars(value) {
			stars.forEach(function (star) {
				star.style.color = ( parseInt(star.getAttribute('data-value'), 10) <= value ) ? '#f0ad4e' : '#ddd';
			});
		}
		stars.forEach(function (star) {
			star.addEventListener('click', function () {
				currentRating = parseInt(star.getAttribute('data-value'), 10);
				paintStars(currentRating);
			});
		});

		Object.keys(bookingNames).forEach(function (bookingId) {
			var row = document.getElementById('booking-list-' + bookingId);
			if (!row) return;

			var actionsRow = row.querySelector('.d-flex.align-items-center.flex-wrap.row-gap-2');
			if (!actionsRow || !actionsRow.parentNode) return;

			var btn = document.createElement('a');
			btn.href = 'javascript:void(0);';
			btn.className = 'btn w-100 mb-2 btn-outline-primary';
			btn.innerHTML = '<i class="ti ti-star me-2"></i><?php echo esc_js( __( 'Rate Customer', 'truelysell' ) ); ?>';
			btn.addEventListener('click', function () {
				currentBookingId = bookingId;
				currentRating = 0;
				paintStars(0);
				document.getElementById('custom-truelysell-rate-customer-comment').value = '';
				document.getElementById('custom-truelysell-rate-customer-message').innerHTML = '';
				document.getElementById('custom-truelysell-rate-customer-name').textContent = bookingNames[bookingId];
				modal.show();
			});

			var wrapper = document.createElement('div');
			wrapper.className = 'd-flex align-items-center flex-wrap row-gap-2';
			wrapper.appendChild(btn);
			actionsRow.parentNode.insertBefore(wrapper, actionsRow.nextSibling);
		});

		document.getElementById('custom-truelysell-submit-customer-review').addEventListener('click', function () {
			if (!currentBookingId) return;
			if (!currentRating) {
				document.getElementById('custom-truelysell-rate-customer-message').innerHTML =
					'<div class="alert alert-danger"><?php echo esc_js( __( 'Please select a star rating.', 'truelysell' ) ); ?></div>';
				return;
			}

			var submitBtn = this;
			submitBtn.disabled = true;

			var formData = new FormData();
			formData.append('action', 'custom_truelysell_submit_customer_review');
			formData.append('nonce', nonce);
			formData.append('booking_id', currentBookingId);
			formData.append('rating', currentRating);
			formData.append('comment', document.getElementById('custom-truelysell-rate-customer-comment').value);

			fetch(ajaxUrl, { method: 'POST', body: formData })
				.then(function (r) { return r.json(); })
				.then(function (data) {
					submitBtn.disabled = false;
					if (data.success) {
						document.getElementById('custom-truelysell-rate-customer-message').innerHTML =
							'<div class="alert alert-success"><?php echo esc_js( __( 'Rating submitted.', 'truelysell' ) ); ?></div>';
						setTimeout(function () { window.location.reload(); }, 800);
					} else {
						document.getElementById('custom-truelysell-rate-customer-message').innerHTML =
							'<div class="alert alert-danger">' + (data.data || '<?php echo esc_js( __( 'Could not submit rating.', 'truelysell' ) ); ?>') + '</div>';
					}
				})
				.catch(function () {
					submitBtn.disabled = false;
					document.getElementById('custom-truelysell-rate-customer-message').innerHTML =
						'<div class="alert alert-danger"><?php echo esc_js( __( 'Network error. Please try again.', 'truelysell' ) ); ?></div>';
				});
		});
	});
	</script>
	<?php
}

/**
 * AJAX: save a provider's rating/review of a customer for a completed
 * booking. Re-validates everything server-side (ownership, completion,
 * one-per-booking) rather than trusting the button only being shown for
 * eligible bookings.
 */
add_action( 'wp_ajax_custom_truelysell_submit_customer_review', 'custom_truelysell_ajax_submit_customer_review' );
function custom_truelysell_ajax_submit_customer_review() {
	check_ajax_referer( 'custom_truelysell_customer_review', 'nonce' );

	$user_id    = get_current_user_id();
	$booking_id = isset( $_POST['booking_id'] ) ? absint( $_POST['booking_id'] ) : 0;
	$rating     = isset( $_POST['rating'] ) ? absint( $_POST['rating'] ) : 0;
	$comment    = isset( $_POST['comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['comment'] ) ) : '';

	if ( ! $user_id || ! custom_truelysell_is_restricted_provider( $user_id ) ) {
		wp_send_json_error( __( 'You are not permitted to do this.', 'truelysell' ) );
	}

	if ( $rating < 1 || $rating > 5 ) {
		wp_send_json_error( __( 'Please select a rating between 1 and 5.', 'truelysell' ) );
	}

	$booking = custom_truelysell_get_booking_by_id( $booking_id );
	if ( ! $booking || absint( $booking->owner_id ) !== absint( $user_id ) ) {
		wp_send_json_error( __( 'This booking does not belong to you.', 'truelysell' ) );
	}

	if ( ! custom_truelysell_is_booking_completed( $booking ) ) {
		wp_send_json_error( __( 'This booking is not completed yet.', 'truelysell' ) );
	}

	if ( custom_truelysell_booking_has_customer_review( $booking_id ) ) {
		wp_send_json_error( __( 'You already rated this customer for this booking.', 'truelysell' ) );
	}

	$comment_id = wp_insert_comment( array(
		'comment_post_ID'  => $booking->listing_id,
		'comment_author'   => wp_get_current_user()->display_name,
		'user_id'          => $user_id,
		'comment_content'  => $comment,
		'comment_type'     => 'customer_review',
		'comment_approved' => 1,
	) );

	if ( ! $comment_id ) {
		wp_send_json_error( __( 'Could not save rating.', 'truelysell' ) );
	}

	add_comment_meta( $comment_id, '_customer_review_rating', $rating );
	add_comment_meta( $comment_id, '_customer_review_customer_id', absint( $booking->bookings_author ) );
	add_comment_meta( $comment_id, '_customer_review_booking_id', $booking_id );

	wp_send_json_success();
}

/**
 * All approved reviews left about this customer, newest first.
 */
function custom_truelysell_get_customer_received_reviews( $customer_id ) {
	$comments = get_comments( array(
		'type'       => 'customer_review',
		'meta_key'   => '_customer_review_customer_id',
		'meta_value' => $customer_id,
		'status'     => 'approve',
		'orderby'    => 'comment_date',
		'order'      => 'DESC',
	) );

	$reviews = array();
	foreach ( $comments as $comment ) {
		$reviews[] = array(
			'rating'        => absint( get_comment_meta( $comment->comment_ID, '_customer_review_rating', true ) ),
			'comment'       => $comment->comment_content,
			'provider_name' => $comment->comment_author,
			'date'          => $comment->comment_date,
		);
	}

	return $reviews;
}

/**
 * Show "Reviews About You" in the customer's own Reviews dashboard page —
 * the native [truelysell_reviews] shortcode only ever shows reviews the
 * customer themselves wrote, never reviews a provider left about them,
 * since the plugin has no such concept at all (see the provider-side
 * functions above).
 */
add_filter( 'the_content', 'custom_truelysell_append_customer_received_reviews', 20 );
function custom_truelysell_append_customer_received_reviews( $content ) {
	if ( ! is_page() || ! is_user_logged_in() ) {
		return $content;
	}

	$reviews_page = function_exists( 'truelysell_fl_framework_getoptions' ) ? truelysell_fl_framework_getoptions( 'reviews_page' ) : 0;
	if ( ! $reviews_page || absint( $reviews_page ) !== absint( get_queried_object_id() ) ) {
		return $content;
	}

	if ( ! custom_truelysell_is_customer_user( get_current_user_id() ) ) {
		return $content;
	}

	$reviews = custom_truelysell_get_customer_received_reviews( get_current_user_id() );

	ob_start();
	?>
	<div class="card mb-4">
		<div class="card-body">
			<h5 class="mb-3"><?php esc_html_e( 'Reviews About You', 'truelysell' ); ?></h5>
			<?php if ( empty( $reviews ) ) : ?>
				<p class="text-muted mb-0"><?php esc_html_e( 'No provider has rated you yet.', 'truelysell' ); ?></p>
			<?php else : ?>
				<?php foreach ( $reviews as $review ) : ?>
					<div class="border-bottom pb-3 mb-3">
						<div class="d-flex align-items-center justify-content-between mb-1">
							<strong><?php echo esc_html( $review['provider_name'] ); ?></strong>
							<span class="text-muted fs-12"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $review['date'] ) ) ); ?></span>
						</div>
						<div class="mb-1">
							<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
								<i class="fa fa-star<?php echo $i > $review['rating'] ? '-o' : ''; ?>" style="color:#f0ad4e;"></i>
							<?php endfor; ?>
						</div>
						<?php if ( ! empty( $review['comment'] ) ) : ?>
							<p class="mb-0"><?php echo esc_html( $review['comment'] ); ?></p>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
	</div>
	<?php
	$reviews_html = ob_get_clean();

	return $reviews_html . $content;
}

/**
 * The plugin's native "Reviews" dashboard page only ever shows a provider
 * reviews on listings they're literally the post_author of
 * (truelysell-core/templates/account/reviews.php queries
 * get_comments(['post_author' => $current_user->ID, ...])). A provider
 * merely LINKED to a shared Admin listing (via registration category
 * selection or the Add-Service dedup fix) is never that listing's
 * post_author, so customer reviews on it never show up for them at all —
 * even though it's their real, active service. Append a section showing
 * reviews on every listing this provider is actually associated with
 * (custom_truelysell_get_provider_real_listing_ids()), the same pattern
 * as the customer-facing "Reviews About You" section above.
 */
add_filter( 'the_content', 'custom_truelysell_append_provider_received_reviews', 20 );
function custom_truelysell_append_provider_received_reviews( $content ) {
	if ( ! is_page() || ! is_user_logged_in() ) {
		return $content;
	}

	$reviews_page = function_exists( 'truelysell_fl_framework_getoptions' ) ? truelysell_fl_framework_getoptions( 'reviews_page' ) : 0;
	if ( ! $reviews_page || absint( $reviews_page ) !== absint( get_queried_object_id() ) ) {
		return $content;
	}

	$user_id = get_current_user_id();
	if ( ! custom_truelysell_is_restricted_provider( $user_id ) ) {
		return $content;
	}

	/*
	 * custom_truelysell_get_provider_real_listing_ids() just reads the
	 * cached provisioned-services mapping — it never refreshes it. That
	 * refresh normally only happens when the Dashboard/My Services page
	 * calls custom_truelysell_ensure_provider_service_listings() first. On
	 * the Reviews page specifically, nothing else warms that cache, so
	 * without this call it can silently return an incomplete list (a
	 * linked-via-provisioning service can go missing) if this page loads
	 * without the Dashboard having been visited first in the same session.
	 */
	custom_truelysell_ensure_provider_service_listings( $user_id );

	$listing_ids = custom_truelysell_get_provider_real_listing_ids( $user_id );

	$comments = empty( $listing_ids ) ? array() : get_comments( array(
		'post__in' => $listing_ids,
		'status'   => 'approve',
		'parent'   => 0,
		'orderby'  => 'comment_date',
		'order'    => 'DESC',
	) );

	ob_start();
	?>
	<div class="card mb-4">
		<div class="card-body">
			<h5 class="mb-3"><?php esc_html_e( 'Reviews About Your Services', 'truelysell' ); ?></h5>
			<?php if ( empty( $comments ) ) : ?>
				<p class="text-muted mb-0"><?php esc_html_e( 'No customer has reviewed your services yet.', 'truelysell' ); ?></p>
			<?php else : ?>
				<?php foreach ( $comments as $comment ) : ?>
					<?php $rating = absint( get_comment_meta( $comment->comment_ID, 'truelysell-rating', true ) ); ?>
					<div class="border-bottom pb-3 mb-3">
						<div class="d-flex align-items-center justify-content-between mb-1">
							<strong><?php echo esc_html( $comment->comment_author ); ?> — <?php echo esc_html( get_the_title( $comment->comment_post_ID ) ); ?></strong>
							<span class="text-muted fs-12"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $comment->comment_date ) ) ); ?></span>
						</div>
						<?php if ( $rating ) : ?>
							<div class="mb-1">
								<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
									<i class="fa fa-star<?php echo $i > $rating ? '-o' : ''; ?>" style="color:#f0ad4e;"></i>
								<?php endfor; ?>
							</div>
						<?php endif; ?>
						<?php if ( ! empty( $comment->comment_content ) ) : ?>
							<p class="mb-0"><?php echo esc_html( $comment->comment_content ); ?></p>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
	</div>
	<?php
	$reviews_html = ob_get_clean();

	return $reviews_html . $content;
}

/**
 * Several of the plugin's own booking-row templates (the provider
 * Dashboard's "Recent Bookings" widget, and likely the customer's own
 * dashboard/"My Bookings" equivalent) output a "Chat" button targeting a
 * #booking_messages modal, but only ever define that modal's actual HTML
 * on the separate Booking List page and the single listing page — never
 * on these dashboard pages. Clicking Chat there does nothing, since
 * Bootstrap has nothing to open. The modal HTML is injected via JS only
 * when a "Chat" trigger is present AND no #booking_messages modal already
 * exists, to avoid a visible duplicate — safe on every page.
 *
 * The submit handling, however, is attached UNCONDITIONALLY whenever a
 * "Chat" trigger exists, regardless of whether the modal was just injected
 * or already present natively — self-contained rather than depending on
 * the plugin's own frontend.js binding. Confirmed reason this matters: the
 * provider's own Booking List page (native plugin template
 * truelysell-core/templates/dashboard-bookings.php) DOES already define
 * its own #booking_messages modal, yet its "Chat" button still silently
 * failed to deliver — frontend.js wasn't reliably intercepting that form's
 * submit there either (having a modal present is not the same as having a
 * working send handler for it). Separately, frontend.js also stores the
 * clicked booking's recipient/booking-id via jQuery's .data() — which only
 * writes to jQuery's internal cache, never back to the actual HTML
 * data-recipient attribute — so plain JS reading that attribute would
 * always see it empty. Reading directly from the clicked "Chat" link's own
 * attributes avoids depending on jQuery's cache entirely. A capturing-phase
 * listener (the trailing `true` on addEventListener) plus
 * stopImmediatePropagation() ensures this handler wins over frontend.js's
 * own (bubble-phase) binding regardless of script load order.
 */
add_action( 'wp_footer', 'custom_truelysell_add_missing_chat_modal' );
function custom_truelysell_add_missing_chat_modal() {
	if ( ! is_user_logged_in() ) {
		return;
	}
	?>
	<script type="text/javascript">
	document.addEventListener('DOMContentLoaded', function () {
		if ( ! document.querySelector('.booking-message') ) {
			return;
		}

		/*
		 * Only inject the modal HTML if this page genuinely doesn't have
		 * one — to avoid a visible duplicate. But the reliable submit
		 * handling below must attach regardless of which page we're on:
		 * at least one page (the provider's Booking List, native plugin
		 * template truelysell-core/templates/dashboard-bookings.php) DOES
		 * already have its own #booking_messages modal, yet still hits
		 * the exact same "frontend.js isn't reliably intercepting this
		 * form" bug described above — having the modal present doesn't
		 * mean that page's send button actually works.
		 */
		if ( ! document.getElementById('booking_messages') ) {
			var wrapper = document.createElement('div');
			wrapper.innerHTML = <?php echo wp_json_encode(
				'<div class="modal fade custom-modal" id="booking_messages" tabindex="-1" aria-labelledby="custom_truelysell_chat_modal_label" aria-hidden="true">'
				. '<div class="modal-dialog modal-dialog-centered">'
				. '<div class="modal-content">'
				. '<div class="modal-header">'
				. '<h5 class="modal-title" id="custom_truelysell_chat_modal_label">' . esc_html__( 'Send Message', 'truelysell_core' ) . '</h5>'
				. '<button type="button" class="close-btn" data-bs-dismiss="modal" aria-label="Close"><i class="feather-x"></i></button>'
				. '</div>'
				. '<div class="modal-body">'
				. '<form action="" id="send-message-from-widget" class="booking_message" data-booking_id="">'
				. '<div class="form-group">'
				. '<textarea data-recipient="" data-referral="" required cols="40" id="contact-message" class="form-control" name="message" rows="3" placeholder="' . esc_attr__( 'Your message', 'truelysell_core' ) . '"></textarea>'
				. '</div>'
				. '<button type="submit" class="btn btn-primary btn-block btn-lg msg-button">' . esc_html__( 'Send Message', 'truelysell_core' ) . '</button>'
				. '<div class="notification closeable success mt-4"></div>'
				. '</form>'
				. '</div>'
				. '</div>'
				. '</div>'
				. '</div>'
			); ?>;
			document.body.appendChild( wrapper.firstElementChild );
		}

		var form = document.getElementById('send-message-from-widget');
		if ( ! form ) {
			return;
		}

		document.addEventListener('click', function (e) {
			var trigger = e.target.closest('.booking-message');
			if (!trigger) {
				return;
			}

			var textarea = form.querySelector('#contact-message');
			if (!textarea) {
				return;
			}

			textarea.setAttribute( 'data-recipient', trigger.getAttribute('data-recipient') || '' );
			textarea.setAttribute( 'data-referral', trigger.getAttribute('data-booking_id') || '' );
		});

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			e.stopImmediatePropagation();

			var textarea     = form.querySelector('#contact-message');
			var notification = form.querySelector('.notification');
			var button       = form.querySelector('.msg-button');

			var formData = new FormData();
			formData.append('action', 'truelysell_send_message');
			formData.append('recipient', textarea.getAttribute('data-recipient') || '');
			formData.append('referral', textarea.getAttribute('data-referral') || '');
			formData.append('message', textarea.value);

			button.setAttribute('disabled', 'disabled');

			fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', body: formData } )
				.then(function (r) { return r.json(); })
				.then(function (data) {
					button.removeAttribute('disabled');
					notification.className = 'notification closeable mt-4 ' + ( data.type === 'success' ? 'success' : 'error' );
					notification.style.display = 'block';
					notification.innerHTML = data.message;
					if ( data.type === 'success' ) {
						textarea.value = '';
					}
				})
				.catch(function () {
					button.removeAttribute('disabled');
					notification.className = 'notification closeable mt-4 error';
					notification.style.display = 'block';
					notification.innerHTML = <?php echo wp_json_encode( __( 'Network error. Please try again.', 'truelysell' ) ); ?>;
				});
		}, true );
	});
	</script>
	<?php
}

/**
 * Same class of bug as the "Chat" send-message form: the reply box inside
 * an already-open conversation (#send-message-from-chat, in
 * truelysell-core/templates/account/single_message.php) may also fall
 * back to a plain browser GET submit instead of being intercepted by the
 * plugin's own frontend.js AJAX handler on this site — meaning a
 * customer's reply never actually reaches the database at all, which is
 * why the provider never sees it (it isn't a display bug on the
 * provider's side; the message was never saved in the first place).
 * Handle this form's submit ourselves too, using the plugin's own
 * confirmed-working AJAX action/fields.
 */
add_action( 'wp_footer', 'custom_truelysell_fix_conversation_reply_form' );
function custom_truelysell_fix_conversation_reply_form() {
	if ( ! is_user_logged_in() ) {
		return;
	}
	?>
	<script type="text/javascript">
	document.addEventListener('DOMContentLoaded', function () {
		var form = document.getElementById('send-message-from-chat');
		if (!form) {
			return;
		}

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			e.stopImmediatePropagation();

			var textarea       = form.querySelector('#contact-message');
			var conversationId = form.querySelector('#conversation_id');
			var message        = textarea ? textarea.value : '';
			var button         = form.querySelector('.btn_send');

			if (!message || !conversationId || !conversationId.value) {
				return;
			}

			var formData = new FormData();
			formData.append('action', 'truelysell_send_message_chat');
			formData.append('conversation_id', conversationId.value);
			formData.append('message', message);

			if (button) {
				button.setAttribute('disabled', 'disabled');
			}

			fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', body: formData } )
				.then(function (r) { return r.json(); })
				.then(function (data) {
					if (data.type === 'success') {
						window.location.reload();
					} else if (button) {
						button.removeAttribute('disabled');
					}
				})
				.catch(function () {
					if (button) {
						button.removeAttribute('disabled');
					}
				});
		}, true );
	});
	</script>
	<?php
}

/**
 * Customer↔provider interactions (listing reviews, our own
 * customer_review type, and chat messages via the plugin's own custom
 * table) should never need Admin's manual approval before reaching the
 * other person. Chat messages and email notifications already send
 * immediately with no approval step. Reviews are the one exception: the
 * plugin ties them to WordPress's global "Comment must be manually
 * approved" Discussion setting (truelysell-core-reviews.php), so whether
 * a review shows immediately depends on a site-wide WP setting that has
 * nothing to do with this theme's own reviews. Force reviews on listings
 * to always auto-approve regardless of that setting.
 */
add_filter( 'pre_comment_approved', 'custom_truelysell_auto_approve_listing_reviews', 10, 2 );
function custom_truelysell_auto_approve_listing_reviews( $approved, $commentdata ) {
	$post_id = isset( $commentdata['comment_post_ID'] ) ? absint( $commentdata['comment_post_ID'] ) : 0;

	if ( $post_id && 'listing' === get_post_type( $post_id ) ) {
		return 1;
	}

	return $approved;
}

// ============================================================
// RENAME "REGION" TAXONOMY TO "POSTAL CODE"
// The plugin already registers a full taxonomy ("region") for listings —
// its own admin menu ("Regions"), term add/edit UI, and the checkbox
// assignment box on each listing's edit screen. Rather than bolting on a
// separate custom field, just relabel this existing taxonomy everywhere
// via its own documented filter hook — so admin/providers keep using the
// exact same term-assignment mechanism, just now calling it "Postal
// Code". This only changes labels, not the underlying rewrite slug/URLs
// (still /region/... internally) or any existing term data.
// ============================================================

add_filter( 'register_taxonomy_region_args', 'custom_truelysell_rename_region_to_postal_code' );
function custom_truelysell_rename_region_to_postal_code( $args ) {
	$singular = __( 'Postal Code', 'truelysell' );
	$plural   = __( 'Postal Codes', 'truelysell' );

	$args['label']  = $plural;
	$args['labels'] = array(
		'name'              => $plural,
		'singular_name'     => $singular,
		'menu_name'         => $plural,
		'search_items'      => sprintf( __( 'Search %s', 'truelysell' ), $plural ),
		'all_items'         => sprintf( __( 'All %s', 'truelysell' ), $plural ),
		'parent_item'       => sprintf( __( 'Parent %s', 'truelysell' ), $singular ),
		'parent_item_colon' => sprintf( __( 'Parent %s:', 'truelysell' ), $singular ),
		'edit_item'         => sprintf( __( 'Edit %s', 'truelysell' ), $singular ),
		'update_item'       => sprintf( __( 'Update %s', 'truelysell' ), $singular ),
		'add_new_item'      => sprintf( __( 'Add New %s', 'truelysell' ), $singular ),
		'new_item_name'     => sprintf( __( 'New %s Name', 'truelysell' ), $singular ),
	);

	return $args;
}

/**
 * The Services archive/search filter sidebar (template-parts/content-page.php,
 * parent theme — not overridden here) has its own free-text "Location"
 * accordion filter (#location_search), separate from the "region" taxonomy
 * just renamed above. Relabel it to "Postal Code" too, to match, without
 * touching the parent theme's template file or the underlying free-text
 * search behavior.
 */
add_action( 'wp_footer', 'custom_truelysell_relabel_location_filter_to_postal_code' );
function custom_truelysell_relabel_location_filter_to_postal_code() {
	?>
	<script>
	document.addEventListener('DOMContentLoaded', function () {
		var wrap = document.getElementById('truelysell-search-form_location_search');
		if (!wrap) return;

		var header = wrap.querySelector('.accordion-button');
		if (header && header.textContent.trim() === 'Location') {
			header.textContent = 'Postal Code';
		}

		var input = document.getElementById('location_search');
		if (input) {
			input.setAttribute('placeholder', 'Postal Code');
		}
	});
	</script>
	<?php
}

/**
 * The "Postal Code" search box (#location_search) only ever searches the
 * listing's Address field (_address/_friendly_address meta), or does a
 * Google-geocoded radius search — it never looks at the "region" taxonomy
 * (the one just relabeled to "Postal Code" above), which is the ONLY way
 * to actually tag a listing with a postal code right now. Without this,
 * a listing tagged via that taxonomy would never be found by this search.
 * Runs after the plugin's own pre_get_posts_listings() (priority 0) so its
 * post__in is already set, and this only ever ADDS matches to it.
 */
add_action( 'pre_get_posts', 'custom_truelysell_include_postal_code_taxonomy_matches', 20 );
function custom_truelysell_include_postal_code_taxonomy_matches( $query ) {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}

	if ( 'listing' !== $query->get( 'post_type' ) ) {
		return;
	}

	$location = isset( $_REQUEST['location_search'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['location_search'] ) ) : '';
	if ( ! $location ) {
		return;
	}

	$terms = get_terms( array(
		'taxonomy'   => 'region',
		'hide_empty' => false,
		'name__like' => $location,
	) );

	if ( empty( $terms ) || is_wp_error( $terms ) ) {
		return;
	}

	$tagged_post_ids = get_posts( array(
		'post_type'      => 'listing',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'tax_query'      => array(
			array(
				'taxonomy' => 'region',
				'field'    => 'term_id',
				'terms'    => wp_list_pluck( $terms, 'term_id' ),
			),
		),
	) );

	if ( empty( $tagged_post_ids ) ) {
		return;
	}

	$existing = array_filter( (array) $query->get( 'post__in' ) );
	$query->set( 'post__in', array_unique( array_merge( $existing, $tagged_post_ids ) ) );
}

// ============================================================
// LIVE GOOGLE ADDRESS AUTOCOMPLETE ON THE PROVIDER/CUSTOMER PROFILE FORM
// The [truelysell_my_account] shortcode (templates/my-account.php, plugin —
// not overridden here) already has an "Address" field (#profile-address)
// plus separate Country/State/City/Postal Code fields, all plain manual
// text inputs. Wire Google Places Autocomplete onto the Address field so
// picking a live suggestion auto-fills all of them, and also capture the
// place's lat/lng (new — for future map display/verification) since the
// template has no field for that at all.
// ============================================================

// Fallback only — prefer the key already configured in this site's own
// Theme Options (Maps API Server field) so every Google Maps feature on
// the site shares one key. Only used if that option is empty.
define( 'TRUELYSELL_CHILD_GOOGLE_MAPS_API_KEY', 'AIzaSyADyKpKfpym_L-R_9BxGMzwp02wGEcllMM' );

// ============================================================
// OVERSIZED TV SURCHARGE
// Every listing's own price covers TVs up to this size — anything larger
// automatically adds the surcharge on top, both in the customer's booking
// total and in every "Starting at" price display.
// ============================================================
define( 'TRUELYSELL_CHILD_OVERSIZE_TV_THRESHOLD_INCHES', 65 );
define( 'TRUELYSELL_CHILD_OVERSIZE_TV_SURCHARGE', 30 );

/**
 * A listing's base price — same _normal_price-else-_price lookup used
 * throughout this file, centralized here so the surcharge logic and the
 * booking form always agree with the AJAX handler on what the starting
 * price actually is.
 */
function custom_truelysell_get_listing_base_price( $listing_id ) {
	$price = (float) get_post_meta( $listing_id, '_normal_price', true );
	if ( ! $price ) {
		$price = (float) get_post_meta( $listing_id, '_price', true );
	}
	return $price;
}

/**
 * TV size (inches) over the threshold adds a flat surcharge to a
 * listing's base price — used both to show the customer an accurate
 * running total in the booking form, and by the server when actually
 * calculating the deposit.
 */
function custom_truelysell_apply_oversize_tv_surcharge( $base_price, $tv_size_inches ) {
	if ( $tv_size_inches > TRUELYSELL_CHILD_OVERSIZE_TV_THRESHOLD_INCHES ) {
		return $base_price + TRUELYSELL_CHILD_OVERSIZE_TV_SURCHARGE;
	}
	return $base_price;
}

// ============================================================
// GOHIGHLEVEL (GHL) WEBHOOK INTEGRATION
// Sends new customer registrations, new technician registrations, and
// new paid bookings to GoHighLevel. To wire each one up: in GHL, create/
// open a Workflow, add an "Inbound Webhook" trigger, and paste the URL it
// generates into the matching constant below. Any left blank simply never
// fire — nothing else needs to change.
// ============================================================
define( 'TRUELYSELL_CHILD_GHL_WEBHOOK_CUSTOMER_URL', '' );
define( 'TRUELYSELL_CHILD_GHL_WEBHOOK_TECHNICIAN_URL', '' );
define( 'TRUELYSELL_CHILD_GHL_WEBHOOK_BOOKING_URL', '' );

/**
 * Fire-and-forget POST of a JSON payload to a GHL Inbound Webhook URL.
 * Non-blocking (doesn't make the customer/technician wait on GHL's
 * response) and never lets GHL being down/unset break the actual
 * registration or booking that triggered it.
 */
function custom_truelysell_send_ghl_webhook( $url, $event, $payload ) {
	if ( ! $url ) {
		return;
	}

	$payload['event'] = $event;

	wp_remote_post( $url, array(
		'timeout'  => 5,
		'blocking' => false,
		'headers'  => array( 'Content-Type' => 'application/json' ),
		'body'     => wp_json_encode( $payload ),
	) );
}

add_action( 'wp_footer', 'custom_truelysell_profile_address_autocomplete' );
function custom_truelysell_profile_address_autocomplete() {
	/*
	 * Deliberately NOT gated to only the exact "profile_page" theme
	 * option's page ID (that was the original gate here) — if a provider
	 * ever reaches the address field via any other page/tab, this script
	 * would silently never load, leaving a plain text input with no
	 * geocoding at all, no matter how carefully they typed/reselected
	 * their address. The JS itself already safely no-ops via
	 * `if (!input || ...) return;` when #profile-address isn't present on
	 * the page, so it's safe to just always output this for any real
	 * provider account.
	 */
	if ( ! is_user_logged_in() || ! custom_truelysell_is_restricted_provider( get_current_user_id() ) ) {
		return;
	}

	$api_key = get_option( 'truelysell_maps_api_server' );
	if ( ! $api_key ) {
		$api_key = TRUELYSELL_CHILD_GOOGLE_MAPS_API_KEY;
	}

	if ( ! $api_key ) {
		return;
	}
	custom_truelysell_enqueue_google_maps_once( $api_key, 'customTruelysellInitProfileAddressAutocomplete' );
	?>
	<script>
	function customTruelysellInitProfileAddressAutocomplete() {
		var input = document.getElementById('profile-address');
		if ( ! input || ! window.google || ! google.maps || ! google.maps.places ) {
			return;
		}

		function ensureHiddenField( name, id ) {
			var el = document.getElementById( id );
			if ( ! el ) {
				el = document.createElement( 'input' );
				el.type = 'hidden';
				el.name = name;
				el.id = id;
				el.setAttribute( 'form', 'edit_user' );
				input.parentNode.appendChild( el );
			}
			return el;
		}

		var latField = ensureHiddenField( 'profile-lat', 'profile-lat' );
		var lngField = ensureHiddenField( 'profile-lng', 'profile-lng' );

		/*
		 * Google's place_changed only fires (and only then updates
		 * lat/lng) when a dropdown suggestion is actually picked — typing
		 * or editing the visible address text afterward does NOT re-fire
		 * it, so the OLD lat/lng would otherwise silently keep pointing at
		 * a totally different location than the text now shown. That
		 * mismatch is exactly how a technician could end up "eligible" for
		 * bookings hundreds of miles from their real, current address.
		 * Clearing on every manual edit forces a fresh selection before
		 * this profile can be saved with a location again.
		 */
		input.addEventListener( 'input', function () {
			latField.value = '';
			lngField.value = '';
		} );

		var autocomplete = new google.maps.places.Autocomplete( input, { types: [ 'address' ] } );

		autocomplete.addListener( 'place_changed', function () {
			var place = autocomplete.getPlace();
			if ( ! place || ! place.geometry ) {
				return;
			}

			latField.value = place.geometry.location.lat();
			lngField.value = place.geometry.location.lng();

			var data = { country: '', state: '', city: '', postalcode: '' };

			( place.address_components || [] ).forEach( function ( comp ) {
				var types = comp.types;
				if ( types.indexOf( 'country' ) !== -1 ) {
					data.country = comp.long_name;
				}
				if ( types.indexOf( 'administrative_area_level_1' ) !== -1 ) {
					data.state = comp.long_name;
				}
				if ( types.indexOf( 'locality' ) !== -1 ) {
					data.city = comp.long_name;
				}
				if ( ! data.city && types.indexOf( 'postal_town' ) !== -1 ) {
					data.city = comp.long_name;
				}
				if ( types.indexOf( 'postal_code' ) !== -1 ) {
					data.postalcode = comp.long_name;
				}
			} );

			var countryField = document.getElementById( 'profile-country' );
			var stateField   = document.getElementById( 'profile-state' );
			var cityField    = document.getElementById( 'profile-city' );
			var postalField  = document.getElementById( 'profile-postalcode' );

			if ( countryField && data.country ) countryField.value = data.country;
			if ( stateField && data.state ) stateField.value = data.state;
			if ( cityField && data.city ) cityField.value = data.city;
			if ( postalField && data.postalcode ) postalField.value = data.postalcode;
		} );
	}
	</script>
	<?php
}

/**
 * "Maximum Travel Radius" (miles) — provider-only, one value per
 * provider (not per-service). Together with their home address lat/lng,
 * this is what determines whether they're eligible for a given customer
 * booking (see custom_truelysell_find_nearest_eligible_provider()).
 */
add_action( 'wp_footer', 'custom_truelysell_provider_travel_radius_field' );
function custom_truelysell_provider_travel_radius_field() {
	$user_id = get_current_user_id();
	if ( ! $user_id || ! custom_truelysell_is_restricted_provider( $user_id ) ) {
		return;
	}

	/*
	 * Same reasoning as custom_truelysell_profile_address_autocomplete()
	 * above: no longer gated to the exact "profile_page" option's page ID
	 * — the JS itself already safely no-ops when #profile-address isn't
	 * on the page (line ~5913 below), so this can just always run for any
	 * real provider account instead of silently never firing if they
	 * reach their profile via a different page/tab than that one option.
	 */
	$saved_radius = get_user_meta( $user_id, 'profile-travel-radius-miles', true );
	$saved_radius = $saved_radius ? absint( $saved_radius ) : 25; // sensible default, matches the spec's example
	?>
	<script type="text/javascript">
	document.addEventListener('DOMContentLoaded', function () {
		var addressField = document.getElementById('profile-address');
		if (!addressField || document.getElementById('profile-travel-radius-miles')) {
			return;
		}

		var wrap = document.createElement('div');
		wrap.className = 'mb-3';
		wrap.innerHTML =
			'<label for="profile-travel-radius-miles" class="form-label">Maximum Travel Radius (miles)</label>' +
			'<input type="number" min="1" max="500" class="text-input form-control" name="profile-travel-radius-miles" id="profile-travel-radius-miles" value="<?php echo esc_attr( $saved_radius ); ?>" required>' +
			'<p class="description">How far are you willing to travel for a job? Bookings outside this radius from your home address won\'t be assigned to you.</p>';

		// Insert right after the Address field's own wrapping .mb-3.
		var addressWrap = addressField.closest('.mb-3') || addressField.parentNode;
		addressWrap.parentNode.insertBefore(wrap, addressWrap.nextSibling);
	});
	</script>
	<?php
}

add_action( 'init', 'custom_truelysell_save_profile_travel_radius', 20 );
function custom_truelysell_save_profile_travel_radius() {
	if ( ! isset( $_POST['my-account-submission'] ) || '1' !== $_POST['my-account-submission'] ) {
		return;
	}

	$user_id = get_current_user_id();
	if ( ! $user_id || ! custom_truelysell_is_restricted_provider( $user_id ) ) {
		return;
	}

	if ( isset( $_POST['profile-travel-radius-miles'] ) && '' !== $_POST['profile-travel-radius-miles'] ) {
		update_user_meta( $user_id, 'profile-travel-radius-miles', absint( $_POST['profile-travel-radius-miles'] ) );
	}
}

/**
 * The plugin's own my-account save handler (Truelysell_Core_Users::
 * submit_my_account_form(), hooked 'init' priority 10) saves every field
 * on this form EXCEPT lat/lng, since those fields don't exist in its
 * template — it has no idea about them. Save them ourselves, right after,
 * using the exact same $_POST['my-account-submission'] flag it checks.
 */
add_action( 'init', 'custom_truelysell_save_profile_lat_lng', 20 );
function custom_truelysell_save_profile_lat_lng() {
	if ( ! isset( $_POST['my-account-submission'] ) || '1' !== $_POST['my-account-submission'] ) {
		return;
	}

	if ( ! is_user_logged_in() ) {
		return;
	}

	$user_id = get_current_user_id();

	if ( isset( $_POST['profile-lat'] ) && '' !== $_POST['profile-lat'] ) {
		update_user_meta( $user_id, 'profile-lat', sanitize_text_field( wp_unslash( $_POST['profile-lat'] ) ) );
	}

	if ( isset( $_POST['profile-lng'] ) && '' !== $_POST['profile-lng'] ) {
		update_user_meta( $user_id, 'profile-lng', sanitize_text_field( wp_unslash( $_POST['profile-lng'] ) ) );
	}
}

// ============================================================
// "N PROVIDERS AVAILABLE" BADGE ON SERVICE CARDS (catalog/archive pages)
// The card templates (truelysell-core/templates/content-listing*.php) are
// plugin files, not overridden here, and don't expose the listing ID as a
// data attribute — only a link to the listing's permalink. So this finds
// every listing-permalink link on the page client-side, resolves them to
// post IDs + linked-provider counts in one batch AJAX call, and injects a
// badge into each card.
// ============================================================

add_action( 'wp_ajax_custom_truelysell_get_provider_counts', 'custom_truelysell_ajax_get_provider_counts' );
add_action( 'wp_ajax_nopriv_custom_truelysell_get_provider_counts', 'custom_truelysell_ajax_get_provider_counts' );
function custom_truelysell_ajax_get_provider_counts() {
	$urls = isset( $_POST['urls'] ) && is_array( $_POST['urls'] ) ? array_map( 'esc_url_raw', wp_unslash( $_POST['urls'] ) ) : array();
	if ( empty( $urls ) ) {
		wp_send_json_error( 'No URLs provided.' );
	}

	$items = array();
	foreach ( array_slice( $urls, 0, 60 ) as $url ) {
		$post_id = url_to_postid( $url );
		if ( ! $post_id || 'listing' !== get_post_type( $post_id ) ) {
			continue;
		}

		/*
		 * Filter through custom_truelysell_is_restricted_provider(), not
		 * just count the raw linked-provider IDs — that list can contain
		 * IDs of users who were later deleted entirely (nothing
		 * previously cleaned those out), which inflated this count.
		 */
		$real_provider_ids = array_filter( custom_truelysell_get_listing_linked_provider_ids( $post_id ), 'custom_truelysell_is_restricted_provider' );

		$data = array( 'count' => count( $real_provider_ids ) );
		$data = apply_filters( 'custom_truelysell_provider_count_extra_data', $data, $post_id );

		$items[ $url ] = $data;
	}

	wp_send_json_success( array( 'items' => $items ) );
}

add_action( 'wp_footer', 'custom_truelysell_render_provider_count_badges' );
function custom_truelysell_render_provider_count_badges() {
	if ( is_admin() ) {
		return;
	}
	?>
	<script type="text/javascript">
	document.addEventListener('DOMContentLoaded', function () {
		var cards = document.querySelectorAll('.card.listing-grid, .service-list');
		if (!cards.length) return;

		var linkToCard = {};
		cards.forEach(function (card) {
			var link = card.querySelector('a[href]');
			if (!link) return;
			var href = link.getAttribute('href');
			if (!href || href.indexOf('javascript:') === 0 || href.indexOf('#') === 0) return;
			if (!linkToCard[href]) linkToCard[href] = [];
			linkToCard[href].push(card);
		});

		var urls = Object.keys(linkToCard);
		if (!urls.length) return;

		var formData = new FormData();
		formData.append('action', 'custom_truelysell_get_provider_counts');
		urls.forEach(function (u) { formData.append('urls[]', u); });

		fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', {
			method: 'POST',
			body: formData
		})
		.then(function (r) { return r.json(); })
		.then(function (data) {
			var items = (data.success && data.data && data.data.items) ? data.data.items : {};

			Object.keys(items).forEach(function (url) {
				var count = items[url].count;
				var relatedCards = linkToCard[url] || [];

				relatedCards.forEach(function (card) {
					var priceRow = card.querySelector('.p-3') || card.querySelector('.service-cont-info') || card.querySelector('.card-body');
					if (!priceRow) return;

					if (!card.querySelector('.truelysell-provider-count-badge')) {
						var badge = document.createElement('span');
						badge.className = 'truelysell-provider-count-badge badge bg-light text-dark border mb-2 me-1 d-inline-block';
						badge.innerHTML = '<i class="ti ti-users me-1"></i>' + (count > 0
							? (count + (count === 1 ? ' provider available' : ' providers available'))
							: 'No providers linked yet');
						priceRow.insertBefore(badge, priceRow.firstChild);
					}
				});
			});
		})
		.catch(function () {});
	});
	</script>
	<?php
}

// ============================================================
// PROVIDER "AVAILABLE DAYS" — PER SERVICE, not one blanket profile
// setting (a provider can be free Mon-Fri for one service and only
// weekends for another). Set directly on each card in "My Services".
// ============================================================

/**
 * A provider's saved available-days for one specific listing.
 * Stored as one user meta key holding a { listing_id: [days] } map,
 * rather than a separate meta row per listing.
 */
function custom_truelysell_get_provider_listing_available_days( $user_id, $listing_id ) {
	$map = get_user_meta( $user_id, '_available_days_by_listing', true );
	if ( is_array( $map ) && isset( $map[ $listing_id ] ) && is_array( $map[ $listing_id ] ) ) {
		return array_values( $map[ $listing_id ] );
	}

	// Default to "all days" so nobody's flagged as unavailable before
	// they've actually set anything up for this service.
	return custom_truelysell_all_week_days();
}

/**
 * A provider's saved start/end time for one specific day of one specific
 * listing. Stored separately from _available_days_by_listing (see below)
 * so existing day-only data never changes shape — a provider who saved
 * days before this feature existed keeps behaving exactly as before (no
 * time restriction at all) until they explicitly set a range for a day.
 */
function custom_truelysell_get_provider_listing_hours_for_day( $user_id, $listing_id, $day_key ) {
	$map = get_user_meta( $user_id, '_available_hours_by_listing', true );
	if ( is_array( $map ) && isset( $map[ $listing_id ][ $day_key ]['start'], $map[ $listing_id ][ $day_key ]['end'] ) ) {
		return array(
			'start' => $map[ $listing_id ][ $day_key ]['start'],
			'end'   => $map[ $listing_id ][ $day_key ]['end'],
		);
	}

	// No range saved for this day yet — fully open, matching the existing
	// "no days configured = available every day" fallback philosophy.
	return array(
		'start' => '00:00',
		'end'   => '23:59',
	);
}

/**
 * { day_key: {start, end} } for every day currently in this provider's
 * available_days for this listing — used to hand the customer's booking
 * widget everything it needs in one AJAX response.
 */
function custom_truelysell_get_provider_listing_hours_map( $user_id, $listing_id ) {
	$map = array();
	foreach ( custom_truelysell_get_provider_listing_available_days( $user_id, $listing_id ) as $day_key ) {
		$map[ $day_key ] = custom_truelysell_get_provider_listing_hours_for_day( $user_id, $listing_id, $day_key );
	}
	return $map;
}

/**
 * <option> list in 30-minute increments, value in 24hr "HH:MM" (matching
 * what's stored/compared everywhere else) but a friendly 12hr label. Used
 * instead of <input type="time"> for the availability editor — native time
 * inputs render with a locale-dependent AM/PM segment that's wider than
 * expected and doesn't fit reliably in a multi-column card layout; a
 * <select> has a fixed, predictable width regardless of browser/locale.
 */
function custom_truelysell_time_select_options( $selected_value ) {
	$out = '';
	for ( $h = 0; $h < 24; $h++ ) {
		foreach ( array( '00', '30' ) as $m ) {
			$value = sprintf( '%02d:%s', $h, $m );
			$label = date( 'g:i A', strtotime( $value ) );
			$out  .= sprintf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $selected_value, $value, false ),
				esc_html( $label )
			);
		}
	}
	return $out;
}

add_action( 'wp_ajax_custom_truelysell_save_listing_availability', 'custom_truelysell_ajax_save_listing_availability' );
function custom_truelysell_ajax_save_listing_availability() {
	check_ajax_referer( 'custom_truelysell_listing_availability', 'nonce' );

	$user_id = get_current_user_id();
	if ( ! $user_id || ! custom_truelysell_is_restricted_provider( $user_id ) ) {
		wp_send_json_error( 'Not permitted.' );
	}

	$listing_id = isset( $_POST['listing_id'] ) ? absint( $_POST['listing_id'] ) : 0;
	if ( ! $listing_id || ! in_array( $listing_id, custom_truelysell_get_provider_real_listing_ids( $user_id ), true ) ) {
		wp_send_json_error( 'Not your service.' );
	}

	$valid_days = custom_truelysell_all_week_days();
	$submitted  = isset( $_POST['days'] ) && is_array( $_POST['days'] )
		? array_map( 'sanitize_text_field', wp_unslash( $_POST['days'] ) )
		: array();
	$sanitized  = array_values( array_intersect( $valid_days, $submitted ) );

	$map                = get_user_meta( $user_id, '_available_days_by_listing', true );
	$map                = is_array( $map ) ? $map : array();
	$map[ $listing_id ] = $sanitized;
	update_user_meta( $user_id, '_available_days_by_listing', $map );

	// Optional per-day start/end times, only for checked days — anything
	// invalid or for an unchecked day is simply skipped (that day falls
	// back to "no time restriction" via custom_truelysell_get_provider_listing_hours_for_day()).
	$submitted_hours = array();
	$raw_hours       = isset( $_POST['hours'] ) ? json_decode( wp_unslash( $_POST['hours'] ), true ) : null;
	if ( is_array( $raw_hours ) ) {
		foreach ( $raw_hours as $day_key => $range ) {
			if ( ! in_array( $day_key, $sanitized, true ) || ! is_array( $range ) ) {
				continue;
			}
			$start = isset( $range['start'] ) ? sanitize_text_field( $range['start'] ) : '';
			$end   = isset( $range['end'] ) ? sanitize_text_field( $range['end'] ) : '';
			if ( preg_match( '/^\d{2}:\d{2}$/', $start ) && preg_match( '/^\d{2}:\d{2}$/', $end ) && $start < $end ) {
				$submitted_hours[ $day_key ] = array( 'start' => $start, 'end' => $end );
			}
		}
	}

	$hours_map                = get_user_meta( $user_id, '_available_hours_by_listing', true );
	$hours_map                = is_array( $hours_map ) ? $hours_map : array();
	$hours_map[ $listing_id ] = $submitted_hours;
	update_user_meta( $user_id, '_available_hours_by_listing', $hours_map );

	wp_send_json_success( array( 'days' => $sanitized, 'hours' => $submitted_hours ) );
}

/**
 * Wires up the "Save Availability" button on each "My Services" card
 * (see custom_truelysell_render_provider_services_card_inner()). Only
 * outputs anything if the current user is actually a provider — the
 * buttons themselves only render for providers anyway, this just avoids
 * loading the script for everyone else.
 */
add_action( 'wp_footer', 'custom_truelysell_listing_availability_save_script' );
function custom_truelysell_listing_availability_save_script() {
	$user_id = get_current_user_id();
	if ( ! $user_id || ! custom_truelysell_is_restricted_provider( $user_id ) ) {
		return;
	}
	?>
	<script type="text/javascript">
	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.truelysell-listing-availability').forEach(function (wrap) {
			var btn = wrap.querySelector('.truelysell-save-availability');
			var savedMsg = wrap.querySelector('.truelysell-availability-saved-msg');
			if (!btn) return;

			// Toggle each row's time inputs with its checkbox, without
			// clearing the values already typed in (so unchecking and
			// re-checking a day doesn't lose the saved-but-hidden range).
			wrap.querySelectorAll('.truelysell-availability-day').forEach(function (cb) {
				cb.addEventListener('change', function () {
					var row = cb.closest('.truelysell-availability-day-row');
					if (!row) return;
					row.querySelectorAll('select').forEach(function (t) {
						t.disabled = !cb.checked;
					});
				});
			});

			btn.addEventListener('click', function () {
				var listingId = wrap.getAttribute('data-listing-id');
				var days = [];
				var hours = {};
				wrap.querySelectorAll('.truelysell-availability-day').forEach(function (cb) {
					if (!cb.checked) return;
					days.push(cb.value);
					var row = cb.closest('.truelysell-availability-day-row');
					var startEl = row ? row.querySelector('.truelysell-availability-start') : null;
					var endEl = row ? row.querySelector('.truelysell-availability-end') : null;
					if (startEl && endEl && startEl.value && endEl.value) {
						hours[cb.value] = { start: startEl.value, end: endEl.value };
					}
				});

				btn.disabled = true;
				var originalText = btn.textContent;
				btn.textContent = '<?php echo esc_js( __( 'Saving...', 'truelysell' ) ); ?>';

				var formData = new FormData();
				formData.append('action', 'custom_truelysell_save_listing_availability');
				formData.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'custom_truelysell_listing_availability' ) ); ?>);
				formData.append('listing_id', listingId);
				days.forEach(function (d) { formData.append('days[]', d); });
				formData.append('hours', JSON.stringify(hours));

				fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', {
					method: 'POST',
					body: formData
				})
				.then(function (r) { return r.json(); })
				.then(function (data) {
					btn.disabled = false;
					btn.textContent = originalText;
					if (data.success && savedMsg) {
						savedMsg.style.display = 'inline';
						setTimeout(function () { savedMsg.style.display = 'none'; }, 2000);
					}
				})
				.catch(function () {
					btn.disabled = false;
					btn.textContent = originalText;
				});
			});
		});
	});
	</script>
	<?php
}

// ============================================================
// KidsVerse — Shop Page: Direct Checkout (Skip Cart)
// ============================================================

/**
 * 1. Redirect "Add to Cart" straight to checkout page.
 *    Works for both AJAX and non-AJAX add-to-cart.
 */
add_filter( 'woocommerce_add_to_cart_redirect', 'kv_skip_cart_go_checkout', 99 );
function kv_skip_cart_go_checkout( $url ) {
    return wc_get_checkout_url();
}

/**
 * 2. Also disable the "View Cart" notice after adding to cart,
 *    since we're going directly to checkout anyway.
 */
add_filter( 'wc_add_to_cart_message_html', '__return_empty_string', 99 );

/**
 * 3. Remove shop page columns / result count / ordering bar
 *    so single product fills the full width cleanly.
 */
add_action( 'wp', 'kv_shop_single_product_setup' );
function kv_shop_single_product_setup() {
    if ( ! is_shop() && ! is_product_category() && ! is_product_tag() ) return;

    // Show 1 product per row (only 1 product anyway)
    add_filter( 'loop_shop_columns', function() { return 1; } );

    // Remove result count + ordering dropdowns from shop header
    remove_action( 'woocommerce_before_shop_loop', 'woocommerce_result_count', 20 );
    remove_action( 'woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30 );

    // Remove pagination (1 product = no pagination needed)
    remove_action( 'woocommerce_after_shop_loop', 'woocommerce_pagination', 10 );

    // Remove the "X products" breadcrumb count
    remove_action( 'woocommerce_before_shop_loop', 'woocommerce_result_count', 20 );
}

/**
 * 4. On the single-product summary shown in the shop loop,
 *    change the add-to-cart button label to "Buy Now — Checkout".
 */
add_filter( 'woocommerce_product_single_add_to_cart_text', 'kv_change_atc_text', 99 );
function kv_change_atc_text( $text ) {
    return esc_html__( 'Buy Now — Checkout', 'truelysell' );
}

// ============================================================
// End KidsVerse Shop Customizations
// ============================================================

// ── listing_package: override add-to-cart text + enable checkout redirect ──
add_filter( 'woocommerce_product_add_to_cart_text', 'kv_listing_pkg_btn_text', 99, 2 );
function kv_listing_pkg_btn_text( $text, $product ) {
    return esc_html__( 'Buy Now - Checkout', 'truelysell' );
}

// Make listing_package show add-to-cart form like a simple product on shop page
add_filter( 'woocommerce_is_purchasable', '__return_true', 99 );

// Force listing_package to show the standard single-product add-to-cart form
add_action( 'woocommerce_listing_package_add_to_cart', 'woocommerce_simple_add_to_cart', 30 );
