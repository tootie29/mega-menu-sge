<?php
/**
 * Duplicate Menu — admin-post handler + nav-menus.php and plugin admin links.
 *
 * Public API:
 *   sge_mm_duplicate_url( $menu_id )       Nonced admin-post URL for the duplicate action.
 *   sge_mm_duplicate_menu_object( $term )  Programmatic clone of a nav menu (items + hierarchy).
 *
 * @package SGE_Mega_Menu
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* --------------------------------------------------------------------------
 * URL builder
 * ------------------------------------------------------------------------*/

function sge_mm_duplicate_url( $menu_id ) {
	$menu_id = (int) $menu_id;
	// Return RAW URL with literal `&` separators. wp_nonce_url() runs the result
	// through esc_html(), which encodes `&` → `&amp;` — that's fine for an href
	// attribute (the browser decodes it), but if the URL is fed to JS as a
	// string (link.href = url), the entity stays literal and PHP ends up parsing
	// the query as `amp;menu_id` instead of `menu_id`. Callers that emit into
	// HTML should still wrap this in esc_url() — that's the correct attribute
	// encoder.
	return add_query_arg(
		array(
			'action'   => 'sge_mm_duplicate_menu',
			'menu_id'  => $menu_id,
			'_wpnonce' => wp_create_nonce( 'sge_mm_duplicate_' . $menu_id ),
		),
		admin_url( 'admin-post.php' )
	);
}

/* --------------------------------------------------------------------------
 * Handler — runs on admin-post.php?action=sge_mm_duplicate_menu
 * ------------------------------------------------------------------------*/

function sge_mm_handle_duplicate_menu() {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to manage navigation menus.', 'sge-mega-menu' ), '', array( 'response' => 403 ) );
	}

	$menu_id = isset( $_GET['menu_id'] ) ? (int) $_GET['menu_id'] : 0;
	if ( $menu_id <= 0 ) {
		wp_die( esc_html__( 'Invalid menu.', 'sge-mega-menu' ), '', array( 'response' => 400 ) );
	}
	check_admin_referer( 'sge_mm_duplicate_' . $menu_id );

	$source = wp_get_nav_menu_object( $menu_id );
	if ( ! $source || is_wp_error( $source ) ) {
		wp_die( esc_html__( 'Source menu not found.', 'sge-mega-menu' ), '', array( 'response' => 404 ) );
	}

	// Wrap in try/catch so a fatal inside another plugin's filter (e.g. WPML
	// hooks firing on wp_update_nav_menu_item) becomes a friendly redirect with
	// the message instead of a blank 500. Also extends PHP's time/memory limits
	// since large menus iterate many wp_update_nav_menu_item() calls which each
	// trigger every plugin's hooks.
	@set_time_limit( 120 );
	if ( function_exists( 'wp_raise_memory_limit' ) ) {
		wp_raise_memory_limit( 'admin' );
	}

	$err_msg = '';
	$new_id  = null;
	try {
		$new_id = sge_mm_duplicate_menu_object( $source );
	} catch ( \Throwable $e ) {
		$err_msg = $e->getMessage();
		error_log( '[SGE Mega Menu] Duplicate failed for menu ' . $menu_id . ': ' . $err_msg . ' @ ' . $e->getFile() . ':' . $e->getLine() );
	}

	if ( $err_msg !== '' || is_wp_error( $new_id ) ) {
		if ( is_wp_error( $new_id ) ) {
			$err_msg = $new_id->get_error_message();
			error_log( '[SGE Mega Menu] Duplicate returned WP_Error for menu ' . $menu_id . ': ' . $err_msg );
		}
		$back = wp_get_referer() ? wp_get_referer() : admin_url( 'nav-menus.php' );
		wp_safe_redirect( add_query_arg(
			array(
				'sge_mm_dup' => 'err',
				'sge_mm_msg' => rawurlencode( $err_msg !== '' ? $err_msg : __( 'Unknown error.', 'sge-mega-menu' ) ),
			),
			$back
		) );
		exit;
	}

	// Success: drop the user onto the new menu in the editor.
	wp_safe_redirect( add_query_arg(
		array(
			'action'     => 'edit',
			'menu'       => (int) $new_id,
			'sge_mm_dup' => '1',
		),
		admin_url( 'nav-menus.php' )
	) );
	exit;
}
add_action( 'admin_post_sge_mm_duplicate_menu', 'sge_mm_handle_duplicate_menu' );

/* --------------------------------------------------------------------------
 * Core duplicate logic
 * ------------------------------------------------------------------------*/

/**
 * Duplicate a nav menu and all its items, preserving parent/child relationships.
 *
 * Theme-location assignments are intentionally NOT copied — locations are 1:1 with
 * menus in WordPress, so the new menu starts unassigned and the user can wire it up
 * to whatever locations they want without disturbing the original.
 *
 * @param WP_Term $source Source menu term.
 * @return int|WP_Error New menu term_id on success.
 */
function sge_mm_duplicate_menu_object( $source ) {
	if ( ! ( $source instanceof WP_Term ) ) {
		return new WP_Error( 'sge_mm_invalid_source', __( 'Invalid source menu.', 'sge-mega-menu' ) );
	}

	// Pick a unique name: "Foo (Copy)", "Foo (Copy 2)", …
	$name = $source->name . ' (Copy)';
	$i    = 2;
	while ( wp_get_nav_menu_object( $name ) ) {
		$name = $source->name . ' (Copy ' . $i . ')';
		$i++;
	}

	$new_id = wp_create_nav_menu( $name );
	if ( is_wp_error( $new_id ) ) {
		return $new_id;
	}

	$items = wp_get_nav_menu_items( $source->term_id, array(
		'update_post_term_cache' => false,
	) );
	if ( empty( $items ) ) {
		return (int) $new_id;
	}

	// Two-pass: insert items as roots first (build old→new id map), then fix parents.
	// Single-pass would require parents to exist before children, which the source
	// order doesn't always guarantee.
	$map = array();
	foreach ( $items as $item ) {
		$args = sge_mm_item_args( $item, 0 );
		$new_item_id = wp_update_nav_menu_item( (int) $new_id, 0, $args );
		if ( is_wp_error( $new_item_id ) || ! $new_item_id ) { continue; }
		$map[ (int) $item->ID ] = (int) $new_item_id;
	}

	foreach ( $items as $item ) {
		$old_parent = (int) $item->menu_item_parent;
		if ( $old_parent <= 0 )                     { continue; }
		if ( ! isset( $map[ (int) $item->ID ] ) )   { continue; }
		if ( ! isset( $map[ $old_parent ] ) )       { continue; }

		$new_item_id   = $map[ (int) $item->ID ];
		$new_parent_id = $map[ $old_parent ];

		$args = sge_mm_item_args( $item, $new_parent_id );
		wp_update_nav_menu_item( (int) $new_id, $new_item_id, $args );
	}

	return (int) $new_id;
}

/** Build a wp_update_nav_menu_item args array from a source item. */
function sge_mm_item_args( $item, $parent_id ) {
	$classes = isset( $item->classes ) ? $item->classes : array();
	if ( is_array( $classes ) ) { $classes = implode( ' ', $classes ); }

	return array(
		'menu-item-db-id'       => 0,
		'menu-item-object-id'   => (int) $item->object_id,
		'menu-item-object'      => (string) $item->object,
		'menu-item-parent-id'   => (int) $parent_id,
		'menu-item-position'    => (int) $item->menu_order,
		'menu-item-type'        => (string) $item->type,
		'menu-item-title'       => (string) $item->title,
		'menu-item-url'         => (string) $item->url,
		'menu-item-description' => (string) $item->description,
		'menu-item-attr-title'  => (string) $item->attr_title,
		'menu-item-target'      => (string) $item->target,
		'menu-item-classes'     => (string) $classes,
		'menu-item-xfn'         => (string) $item->xfn,
		'menu-item-status'      => 'publish',
	);
}

/* --------------------------------------------------------------------------
 * Appearance → Menus screen — inject "Duplicate" link in the menu footer.
 * Uses JS to slot the link next to .publishing-action (Save Menu) so we don't
 * have to fight core's markup with output buffering.
 * ------------------------------------------------------------------------*/

function sge_mm_nav_menus_duplicate_link() {
	if ( ! current_user_can( 'edit_theme_options' ) ) { return; }

	$screen = get_current_screen();
	if ( ! $screen || 'nav-menus' !== $screen->id ) { return; }

	$menu_id = isset( $_GET['menu'] ) ? (int) $_GET['menu'] : 0;
	if ( $menu_id <= 0 ) {
		// nav-menus.php normalises the current menu into a global; fall back to that.
		$menu_id = (int) ( isset( $GLOBALS['nav_menu_selected_id'] ) ? $GLOBALS['nav_menu_selected_id'] : 0 );
	}
	if ( $menu_id <= 0 ) { return; }

	$menu = wp_get_nav_menu_object( $menu_id );
	if ( ! $menu || is_wp_error( $menu ) ) { return; }

	$url     = sge_mm_duplicate_url( $menu_id );
	$confirm = sprintf(
		/* translators: %s: menu name */
		__( 'Duplicate the menu "%s"? A new menu will be created with the same items.', 'sge-mega-menu' ),
		$menu->name
	);
	?>
	<script>
	(function(){
		var url     = <?php echo wp_json_encode( $url ); ?>;
		var label   = <?php echo wp_json_encode( __( 'Duplicate', 'sge-mega-menu' ) ); ?>;
		var confirm = <?php echo wp_json_encode( $confirm ); ?>;

		function inject(){
			// Avoid double-insertion if this runs more than once.
			if (document.querySelector('.sge-mm-duplicate-link')) { return true; }

			// nav-menus.php has TWO .publishing-action blocks: the first is the hidden
			// "Save Menu Header" helper button at the top of the menu structure area
			// (parent .major-publishing-actions.wp-clearfix has zero height), and the
			// second is the visible bottom action bar with Save Menu + Delete Menu.
			// We want the visible bottom bar — pick the LAST .publishing-action.
			var pas = document.querySelectorAll('#menu-management .publishing-action, .menu-management .publishing-action, .major-publishing-actions .publishing-action');
			var anchor = pas.length ? pas[pas.length - 1] : null;
			if (!anchor || !anchor.parentNode) { return false; }

			var link = document.createElement('a');
			link.href = url;
			link.className = 'sge-mm-duplicate-link button';
			link.style.marginRight = '8px';
			link.textContent = label;
			link.addEventListener('click', function(e){
				if (!window.confirm(confirm)) { e.preventDefault(); }
			});
			anchor.parentNode.insertBefore(link, anchor);
			return true;
		}

		if (!inject()) {
			// DOM may not be ready yet — retry once after DOMContentLoaded.
			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', inject);
			} else {
				// Last-ditch retry after a tick in case core scripts mutate the footer.
				setTimeout(inject, 50);
			}
		}
	})();
	</script>
	<?php
}
add_action( 'admin_print_footer_scripts-nav-menus.php', 'sge_mm_nav_menus_duplicate_link' );

/* --------------------------------------------------------------------------
 * Admin notices — success/error flash after a duplicate redirect
 * ------------------------------------------------------------------------*/

function sge_mm_duplicate_admin_notice() {
	if ( ! isset( $_GET['sge_mm_dup'] ) ) { return; }
	$flag = sanitize_key( wp_unslash( $_GET['sge_mm_dup'] ) );

	if ( '1' === $flag ) {
		$menu_id = isset( $_GET['menu'] ) ? (int) $_GET['menu'] : 0;
		$menu    = $menu_id ? wp_get_nav_menu_object( $menu_id ) : null;
		$name    = ( $menu && ! is_wp_error( $menu ) ) ? $menu->name : __( 'New menu', 'sge-mega-menu' );
		echo '<div class="notice notice-success is-dismissible"><p>'
			. sprintf(
				/* translators: %s: new menu name */
				esc_html__( 'Menu duplicated as "%s".', 'sge-mega-menu' ),
				esc_html( $name )
			)
			. '</p></div>';
		return;
	}

	if ( 'err' === $flag ) {
		$msg = isset( $_GET['sge_mm_msg'] )
			? sanitize_text_field( wp_unslash( $_GET['sge_mm_msg'] ) )
			: __( 'Failed to duplicate menu.', 'sge-mega-menu' );
		echo '<div class="notice notice-error is-dismissible"><p>'
			. esc_html( $msg )
			. '</p></div>';
	}
}
add_action( 'admin_notices', 'sge_mm_duplicate_admin_notice' );
