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
	}

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
		'8298' => 'Ceiling TV Mounting',
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
	if ( $current_assignee ) {
		return;
	}

	update_post_meta( $listing_id, '_assigned_technician_id', absint( $user_id ) );
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
 * The public single listing page's "Service Provider" box
 * (truelysell-core/templates/single-listing.php) is hardcoded to
 * `$author_id = $post->post_author` — always the listing's original
 * author (Admin, for a shared catalog listing), with no hook to override
 * it. Once a listing has an assigned technician (see
 * custom_truelysell_maybe_assign_technician_to_listing()), customers
 * should see THAT technician here instead — they're who will actually
 * show up to do the job, not the generic Admin account. Fetch the
 * technician's info and swap it into the box after page load.
 */
add_action( 'wp_footer', 'custom_truelysell_show_assigned_technician_as_provider' );
function custom_truelysell_show_assigned_technician_as_provider() {
	if ( ! is_singular( 'listing' ) ) {
		return;
	}

	$listing_id      = get_the_ID();
	$technician_id   = absint( get_post_meta( $listing_id, '_assigned_technician_id', true ) );
	if ( ! $technician_id ) {
		return;
	}

	$technician = get_userdata( $technician_id );
	if ( ! $technician ) {
		return;
	}

	$provider_details_page = function_exists( 'truelysell_fl_framework_getoptions' ) ? truelysell_fl_framework_getoptions( 'provider_details_page' ) : 0;
	$profile_url           = $provider_details_page ? add_query_arg( 'author_id', $technician_id, get_permalink( $provider_details_page ) ) : '';
	$avatar_html            = get_avatar( $technician_id, 64, '', '', array( 'class' => 'img-fluid rounded-circle' ) );
	$member_since           = date( 'd M Y', strtotime( $technician->user_registered ) );
	$listing_count          = count( custom_truelysell_get_provider_real_listing_ids( $technician_id ) );
	$show_email             = ! ( function_exists( 'truelysell_fl_framework_getoptions' ) && truelysell_fl_framework_getoptions( 'disable_email' ) );
	?>
	<script type="text/javascript">
	document.addEventListener('DOMContentLoaded', function() {
		var box = document.querySelector('.provider-info');
		if (!box) {
			return;
		}

		var avatarImg = box.querySelector('.avatar img');
		if (avatarImg) {
			var wrapper = document.createElement('div');
			wrapper.innerHTML = <?php echo wp_json_encode( $avatar_html ); ?>;
			var newAvatar = wrapper.querySelector('img');
			if (newAvatar) {
				avatarImg.replaceWith(newAvatar);
			}
		}

		var nameLink = box.querySelector('h5 a');
		if (nameLink) {
			nameLink.textContent = <?php echo wp_json_encode( $technician->display_name ); ?>;
			<?php if ( $profile_url ) : ?>
			nameLink.setAttribute('href', <?php echo wp_json_encode( $profile_url ); ?>);
			<?php endif; ?>
		}

		function replaceValueFor(labelText, newValue) {
			var rows = document.querySelectorAll('.card-body .d-flex.justify-content-between');
			for (var i = 0; i < rows.length; i++) {
				var label = rows[i].querySelector('h6');
				if (label && label.textContent.trim().indexOf(labelText) !== -1) {
					var value = rows[i].querySelector('p');
					if (value) {
						value.textContent = newValue;
					}
					break;
				}
			}
		}

		replaceValueFor('Member Since', <?php echo wp_json_encode( $member_since ); ?>);
		<?php if ( $show_email ) : ?>
		replaceValueFor('Email', <?php echo wp_json_encode( $technician->user_email ); ?>);
		<?php endif; ?>
		replaceValueFor('No of Listings', <?php echo wp_json_encode( $listing_count ); ?>);
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
    ?>
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
                            <div><?php esc_html_e( 'Fill in your details to book this service. A 20% deposit is required to confirm your booking — you\'ll be taken to a secure payment page next. The provider will contact you to arrange a time, and collects the remaining balance directly.', 'truelysell' ); ?></div>
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

    <script type="text/javascript">
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
            });
        });

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
// AJAX HANDLER: customer_book_service
// ============================================================
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

    if ( ! $listing_id ) {
        wp_send_json_error( 'Invalid service.' );
    }
    if ( ! $email || ! is_email( $email ) ) {
        wp_send_json_error( 'Please enter a valid email.' );
    }
    if ( ! $phone ) {
        wp_send_json_error( 'Please enter a phone number.' );
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
    $owner_id = $listing_post->post_author;

    // Get product ID linked to this listing
    $product_id = get_post_meta( $listing_id, '_product_id', true );

    // Get normal price
    $normal_price = (float) get_post_meta( $listing_id, '_normal_price', true );
    if ( ! $normal_price ) {
        $normal_price = (float) get_post_meta( $listing_id, '_price', true );
    }

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
    $item_id = $order->add_product( wc_get_product( $product_id ), 1, $args );
    $item    = $item_id ? $order->get_item( $item_id ) : null;
    if ( $item ) {
        $item->set_name( $item->get_name() . ' — 20% Booking Deposit' );
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
    $order->update_meta_data( '_truelysell_full_price', $normal_price );
    $order->update_meta_data( '_truelysell_deposit_amount', $deposit_amount );

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
    $full_price     = (float) $order->get_meta( '_truelysell_full_price' );
    $deposit_amount = (float) $order->get_meta( '_truelysell_deposit_amount' );

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
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'email'        => $email,
            'phone'        => $phone,
            'message'      => $message,
            'deposit_paid' => $deposit_amount,
            'total_price'  => $full_price,
        ),
    ) );

    $now = current_time( 'mysql' );

    global $wpdb;
    $table = $wpdb->prefix . 'bookings_calendar';

    $inserted = $wpdb->insert(
        $table,
        array(
            'bookings_author' => $user_id,
            'owner_id'        => $owner_id,
            'listing_id'      => $listing_id,
            'staff_id'        => 0,
            'date_start'      => $now,
            'date_end'        => $now,
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

    $remaining_balance = $full_price - $deposit_amount;

    // Notify the owner/technician
    $owner_info = get_userdata( $owner_id );
    if ( $owner_info && is_email( $owner_info->user_email ) ) {
        $subject = sprintf( __( 'New Booking (Deposit Paid): %s', 'truelysell' ), get_the_title( $listing_id ) );
        $body    = sprintf(
            "A new booking has been made and the 20%% deposit has been paid.\n\nService: %s\nCustomer: %s %s (%s)\nPhone: %s\nMessage: %s\n\nDeposit Paid: %s\nRemaining Balance (collect from customer): %s\n\nPlease contact the customer to arrange a time.\n\nBooking ID: #%d",
            get_the_title( $listing_id ),
            $first_name, $last_name, $email,
            $phone, $message,
            wp_strip_all_tags( wc_price( $deposit_amount ) ), wp_strip_all_tags( wc_price( $remaining_balance ) ),
            $booking_id
        );
        wp_mail( $owner_info->user_email, $subject, $body );
    }

    // Confirmation to the customer
    if ( is_email( $email ) ) {
        $subject = sprintf( __( 'Booking Confirmation: %s', 'truelysell' ), get_the_title( $listing_id ) );
        $body    = sprintf(
            "Thank you for your booking!\n\nService: %s\nDeposit Paid: %s\nRemaining Balance Due: %s\n\nBooking ID: #%d\n\nThe provider will contact you shortly to arrange a time.",
            get_the_title( $listing_id ),
            wp_strip_all_tags( wc_price( $deposit_amount ) ), wp_strip_all_tags( wc_price( $remaining_balance ) ),
            $booking_id
        );
        wp_mail( $email, $subject, $body );
    }
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
    $phone = isset( $_POST['phone_number'] ) ? trim( sanitize_text_field( $_POST['phone_number'] ) ) : '';
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
 * Bootstrap has nothing to open. Rather than patch each page individually
 * (and risk creating a duplicate modal on pages that already have one
 * correctly), inject the modal via JS only when a "Chat" trigger is
 * present AND no #booking_messages modal already exists — safe on every
 * page, for both provider and customer accounts.
 *
 * The submit handling is also self-contained rather than depending on the
 * plugin's own frontend.js binding: on at least one of these pages, that
 * binding wasn't intercepting the form (it fell back to a plain browser
 * GET submit), and separately, frontend.js stores the clicked booking's
 * recipient/booking-id via jQuery's .data() — which only writes to
 * jQuery's internal cache, never back to the actual HTML data-recipient
 * attribute — so plain JS reading that attribute would always see it
 * empty. Reading directly from the clicked "Chat" link's own attributes
 * avoids depending on jQuery's cache entirely.
 */
add_action( 'wp_footer', 'custom_truelysell_add_missing_chat_modal' );
function custom_truelysell_add_missing_chat_modal() {
	if ( ! is_user_logged_in() ) {
		return;
	}
	?>
	<script type="text/javascript">
	document.addEventListener('DOMContentLoaded', function () {
		if ( ! document.querySelector('.booking-message') || document.getElementById('booking_messages') ) {
			return;
		}

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

		var form = document.getElementById('send-message-from-widget');

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
