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

/**
 * Build a nonced URL to duplicate one menu item branch.
 *
 * @param int $menu_id Menu term_id.
 * @param int $item_id Menu item post ID.
 * @return string
 */
function sge_mm_duplicate_item_url( $menu_id, $item_id ) {
	$menu_id = (int) $menu_id;
	$item_id = (int) $item_id;

	return add_query_arg(
		array(
			'action'   => 'sge_mm_duplicate_menu_item_branch',
			'menu_id'  => $menu_id,
			'item_id'  => $item_id,
			'_wpnonce' => wp_create_nonce( 'sge_mm_duplicate_item_' . $menu_id . '_' . $item_id ),
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

	$new_id = sge_mm_duplicate_menu_object( $source );

	if ( is_wp_error( $new_id ) ) {
		$back = wp_get_referer() ? wp_get_referer() : admin_url( 'nav-menus.php' );
		wp_safe_redirect( add_query_arg(
			array(
				'sge_mm_dup' => 'err',
				'sge_mm_msg' => rawurlencode( $new_id->get_error_message() ),
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

/**
 * Handler — runs on admin-post.php?action=sge_mm_duplicate_menu_item_branch
 */
function sge_mm_handle_duplicate_menu_item_branch() {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to manage navigation menus.', 'sge-mega-menu' ), '', array( 'response' => 403 ) );
	}

	$menu_id = isset( $_GET['menu_id'] ) ? (int) $_GET['menu_id'] : 0;
	$item_id = isset( $_GET['item_id'] ) ? (int) $_GET['item_id'] : 0;
	if ( $menu_id <= 0 || $item_id <= 0 ) {
		wp_die( esc_html__( 'Invalid menu item.', 'sge-mega-menu' ), '', array( 'response' => 400 ) );
	}
	check_admin_referer( 'sge_mm_duplicate_item_' . $menu_id . '_' . $item_id );

	$result = sge_mm_duplicate_menu_item_branch( $menu_id, $item_id );
	if ( is_wp_error( $result ) ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'action'      => 'edit',
					'menu'        => $menu_id,
					'sge_mm_dup'  => 'item_err',
					'sge_mm_msg'  => rawurlencode( $result->get_error_message() ),
				),
				admin_url( 'nav-menus.php' )
			)
		);
		exit;
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'action'      => 'edit',
				'menu'        => $menu_id,
				'sge_mm_dup'  => 'item_ok',
				'sge_mm_item' => (int) $result,
			),
			admin_url( 'nav-menus.php' )
		)
	);
	exit;
}
add_action( 'admin_post_sge_mm_duplicate_menu_item_branch', 'sge_mm_handle_duplicate_menu_item_branch' );

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

/**
 * Duplicate one menu item branch (item + all descendants) into a menu.
 *
 * By default it duplicates into the same source menu as a new root item.
 * Pass $target_parent_item_id to nest the cloned branch under an existing
 * item in the target menu.
 *
 * @param int $source_menu_id       Source menu term_id where the original item lives.
 * @param int $source_item_id       Source menu item post ID to duplicate.
 * @param int $target_menu_id       Optional target menu term_id (0 = source menu).
 * @param int $target_parent_item_id Optional target parent menu item ID (0 = root).
 * @return int|WP_Error New root menu item ID on success.
 */
function sge_mm_duplicate_menu_item_branch( $source_menu_id, $source_item_id, $target_menu_id = 0, $target_parent_item_id = 0 ) {
	$source_menu_id        = (int) $source_menu_id;
	$source_item_id        = (int) $source_item_id;
	$target_menu_id        = (int) $target_menu_id;
	$target_parent_item_id = (int) $target_parent_item_id;

	if ( $source_menu_id <= 0 || $source_item_id <= 0 ) {
		return new WP_Error( 'sge_mm_invalid_args', __( 'Invalid menu or item ID.', 'sge-mega-menu' ) );
	}

	if ( $target_menu_id <= 0 ) {
		$target_menu_id = $source_menu_id;
	}

	$source_menu = wp_get_nav_menu_object( $source_menu_id );
	$target_menu = wp_get_nav_menu_object( $target_menu_id );
	if ( ! $source_menu || is_wp_error( $source_menu ) ) {
		return new WP_Error( 'sge_mm_source_menu_not_found', __( 'Source menu not found.', 'sge-mega-menu' ) );
	}
	if ( ! $target_menu || is_wp_error( $target_menu ) ) {
		return new WP_Error( 'sge_mm_target_menu_not_found', __( 'Target menu not found.', 'sge-mega-menu' ) );
	}

	$items = wp_get_nav_menu_items(
		$source_menu_id,
		array(
			'update_post_term_cache' => false,
		)
	);
	if ( empty( $items ) ) {
		return new WP_Error( 'sge_mm_empty_source_menu', __( 'Source menu has no items.', 'sge-mega-menu' ) );
	}

	$items_by_id = array();
	$children_of = array();
	foreach ( $items as $item ) {
		$item_id                     = (int) $item->ID;
		$parent_id                   = (int) $item->menu_item_parent;
		$items_by_id[ $item_id ]     = $item;
		$children_of[ $parent_id ][] = $item;
	}

	if ( ! isset( $items_by_id[ $source_item_id ] ) ) {
		return new WP_Error( 'sge_mm_source_item_not_found', __( 'Source menu item not found.', 'sge-mega-menu' ) );
	}

	foreach ( $children_of as $parent_id => &$children ) {
		usort(
			$children,
			function ( $a, $b ) {
				return (int) $a->menu_order <=> (int) $b->menu_order;
			}
		);
	}
	unset( $children );

	$duplicate_node = function ( $old_item_id, $new_parent_id ) use ( &$duplicate_node, $items_by_id, $children_of, $target_menu_id ) {
		$old_item = $items_by_id[ (int) $old_item_id ];
		$args     = sge_mm_item_args( $old_item, (int) $new_parent_id );

		$new_item_id = wp_update_nav_menu_item( (int) $target_menu_id, 0, $args );
		if ( is_wp_error( $new_item_id ) || ! $new_item_id ) {
			return new WP_Error( 'sge_mm_branch_item_create_failed', __( 'Failed to duplicate menu branch item.', 'sge-mega-menu' ) );
		}
		$new_item_id = (int) $new_item_id;

		$children = isset( $children_of[ (int) $old_item_id ] ) ? $children_of[ (int) $old_item_id ] : array();
		foreach ( $children as $child_item ) {
			$child_result = $duplicate_node( (int) $child_item->ID, $new_item_id );
			if ( is_wp_error( $child_result ) ) {
				return $child_result;
			}
		}
		return $new_item_id;
	};

	return $duplicate_node( $source_item_id, $target_parent_item_id );
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

/**
 * Appearance → Menus — inject a "Duplicate Item" action into each menu item panel.
 */
function sge_mm_nav_menus_duplicate_item_links() {
	if ( ! current_user_can( 'edit_theme_options' ) ) { return; }

	$screen = get_current_screen();
	if ( ! $screen || 'nav-menus' !== $screen->id ) { return; }

	$menu_id = isset( $_GET['menu'] ) ? (int) $_GET['menu'] : 0;
	if ( $menu_id <= 0 ) {
		$menu_id = (int) ( isset( $GLOBALS['nav_menu_selected_id'] ) ? $GLOBALS['nav_menu_selected_id'] : 0 );
	}
	if ( $menu_id <= 0 ) { return; }
	?>
	<script>
	(function(){
		function buildUrl(itemId){
			var base = <?php echo wp_json_encode( admin_url( 'admin-post.php' ) ); ?>;
			var menuId = <?php echo (int) $menu_id; ?>;
			var nonce = <?php echo wp_json_encode( wp_create_nonce( 'sge_mm_duplicate_item_' . $menu_id . '_' ) ); ?>;
			// Per-item nonce is generated server-side in PHP URL builder endpoint format.
			// For DOM injection we request a concrete URL from server by using known scheme.
			// Replace trailing nonce seed with item-specific nonce string created in PHP below.
			var itemNonces = window.sgeMmItemNonces || {};
			var itemNonce = itemNonces[itemId] || '';
			if (!itemNonce) { return ''; }
			return base + '?action=sge_mm_duplicate_menu_item_branch&menu_id=' + menuId + '&item_id=' + itemId + '&_wpnonce=' + encodeURIComponent(itemNonce);
		}

		function inject(){
			var items = document.querySelectorAll('#menu-to-edit li.menu-item');
			if (!items.length) { return; }

			items.forEach(function(item){
				var idAttr = item.id || '';
				var m = idAttr.match(/^menu-item-(\d+)$/);
				if (!m) { return; }
				var itemId = parseInt(m[1], 10);
				if (!itemId) { return; }

				if (item.querySelector('.sge-mm-duplicate-item-link')) { return; }
				var actions = item.querySelector('.item-controls .item-order');
				if (!actions || !actions.parentNode) { return; }

				var url = buildUrl(itemId);
				if (!url) { return; }

				var sep = document.createElement('span');
				sep.className = 'sge-mm-dup-sep';
				sep.textContent = ' | ';

				var link = document.createElement('a');
				link.href = url;
				link.className = 'sge-mm-duplicate-item-link';
				link.textContent = <?php echo wp_json_encode( __( 'Duplicate Item', 'sge-mega-menu' ) ); ?>;
				link.addEventListener('click', function(e){
					if (!window.confirm(<?php echo wp_json_encode( __( 'Duplicate this menu item and all its descendants?', 'sge-mega-menu' ) ); ?>)) {
						e.preventDefault();
					}
				});

				actions.parentNode.insertBefore(sep, actions.nextSibling);
				actions.parentNode.insertBefore(link, sep.nextSibling);
			});
		}

		document.addEventListener('DOMContentLoaded', inject);
		setTimeout(inject, 80);
	})();
	</script>
	<?php
}
add_action( 'admin_print_footer_scripts-nav-menus.php', 'sge_mm_nav_menus_duplicate_item_links', 20 );

/**
 * Nonces map consumed by duplicate-item JS injector.
 */
function sge_mm_nav_menus_duplicate_item_nonce_map() {
	if ( ! current_user_can( 'edit_theme_options' ) ) { return; }

	$screen = get_current_screen();
	if ( ! $screen || 'nav-menus' !== $screen->id ) { return; }

	$menu_id = isset( $_GET['menu'] ) ? (int) $_GET['menu'] : 0;
	if ( $menu_id <= 0 ) {
		$menu_id = (int) ( isset( $GLOBALS['nav_menu_selected_id'] ) ? $GLOBALS['nav_menu_selected_id'] : 0 );
	}
	if ( $menu_id <= 0 ) { return; }

	$items = wp_get_nav_menu_items( $menu_id, array( 'update_post_term_cache' => false ) );
	if ( empty( $items ) ) { return; }

	$nonces = array();
	foreach ( $items as $item ) {
		$item_id = (int) $item->ID;
		if ( $item_id <= 0 ) { continue; }
		$nonces[ $item_id ] = wp_create_nonce( 'sge_mm_duplicate_item_' . $menu_id . '_' . $item_id );
	}
	?>
	<script>
	window.sgeMmItemNonces = <?php echo wp_json_encode( $nonces ); ?>;
	</script>
	<?php
}
add_action( 'admin_print_footer_scripts-nav-menus.php', 'sge_mm_nav_menus_duplicate_item_nonce_map', 19 );

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
		return;
	}

	if ( 'item_ok' === $flag ) {
		echo '<div class="notice notice-success is-dismissible"><p>'
			. esc_html__( 'Menu item branch duplicated successfully.', 'sge-mega-menu' )
			. '</p></div>';
		return;
	}

	if ( 'item_err' === $flag ) {
		$msg = isset( $_GET['sge_mm_msg'] )
			? sanitize_text_field( wp_unslash( $_GET['sge_mm_msg'] ) )
			: __( 'Failed to duplicate menu item branch.', 'sge-mega-menu' );
		echo '<div class="notice notice-error is-dismissible"><p>'
			. esc_html( $msg )
			. '</p></div>';
	}
}
add_action( 'admin_notices', 'sge_mm_duplicate_admin_notice' );
