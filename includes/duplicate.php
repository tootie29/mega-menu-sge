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
		sge_mm_redirect_safely(
			add_query_arg(
				array(
					'sge_mm_dup' => 'err',
					'sge_mm_msg' => rawurlencode( $err_msg !== '' ? $err_msg : __( 'Unknown error.', 'sge-mega-menu' ) ),
				),
				$back
			),
			__( 'Failed to duplicate menu', 'sge-mega-menu' )
		);
		exit;
	}

	// Success: drop the user onto the new menu in the editor.
	sge_mm_redirect_safely(
		add_query_arg(
			array(
				'action'     => 'edit',
				'menu'       => (int) $new_id,
				'sge_mm_dup' => '1',
			),
			admin_url( 'nav-menus.php' )
		),
		__( 'Menu duplicated', 'sge-mega-menu' )
	);
	exit;
}

/**
 * Try HTTP redirect; if anything fatals or headers are already sent, fall back
 * to a JS/meta-refresh page with a manual link. Some host stacks (WPML +
 * WP Rocket + aggressive output filters) throw inside `wp_safe_redirect()` or
 * one of its filters — we don't want that to 500 a duplicate that already
 * succeeded.
 *
 * @param string $url   Destination URL.
 * @param string $title Page title for the fallback HTML page.
 */
function sge_mm_redirect_safely( $url, $title = 'Redirecting' ) {
	$url = (string) $url;
	try {
		if ( ! headers_sent() ) {
			wp_safe_redirect( $url );
			return;
		}
	} catch ( \Throwable $e ) {
		error_log( '[SGE Mega Menu] wp_safe_redirect threw: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
		// fall through to printed page
	}

	$url_attr = esc_url( $url );
	$url_js   = wp_json_encode( $url );
	$title_e  = esc_html( $title );
	echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $title_e . '</title>'
		. '<meta http-equiv="refresh" content="0;url=' . $url_attr . '">'
		. '<style>body{font:14px -apple-system,system-ui,sans-serif;padding:40px;color:#1d2327}a{color:#2271b1}</style>'
		. '</head><body>'
		. '<h2>' . $title_e . '</h2>'
		. '<p>If you are not redirected automatically, <a href="' . $url_attr . '">click here to continue</a>.</p>'
		. '<script>setTimeout(function(){ try { location.href = ' . $url_js . '; } catch(e){} }, 50);</script>'
		. '</body></html>';
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
		return;
	}

	if ( 'item_ok' === $flag ) {
		$count = isset( $_GET['sge_mm_count'] ) ? (int) $_GET['sge_mm_count'] : 0;
		$count = max( 1, $count );
		echo '<div class="notice notice-success is-dismissible"><p>'
			. sprintf(
				/* translators: %d: number of items copied (item + descendants) */
				esc_html( _n(
					'Menu item duplicated (%d item copied including descendants).',
					'Menu item duplicated (%d items copied including descendants).',
					$count,
					'sge-mega-menu'
				) ),
				$count
			)
			. '</p></div>';
		return;
	}

	if ( 'item_err' === $flag ) {
		$msg = isset( $_GET['sge_mm_msg'] )
			? sanitize_text_field( wp_unslash( $_GET['sge_mm_msg'] ) )
			: __( 'Failed to duplicate menu item.', 'sge-mega-menu' );
		echo '<div class="notice notice-error is-dismissible"><p>'
			. esc_html( $msg )
			. '</p></div>';
	}
}
add_action( 'admin_notices', 'sge_mm_duplicate_admin_notice' );

/* --------------------------------------------------------------------------
 * Branch duplicate — clone one menu item + all of its descendants
 *
 * Public API:
 *   sge_mm_duplicate_menu_item_branch( $source_menu_id, $source_item_id, $target_menu_id = 0, $target_parent_item_id = 0 )
 *     - $target_menu_id 0  → use the source menu (clone in-place as siblings of the source)
 *     - $target_parent_item_id 0 → the duplicated root becomes a top-level item in the target menu;
 *       pass an existing item ID in the target menu to nest the duplicated branch under it.
 *     - Returns array{ menu_id, new_root_id, items_copied, items_in_branch } on success or WP_Error on failure.
 *
 *   sge_mm_duplicate_item_url( $menu_id, $item_id )
 *     - Nonced admin-post URL for the per-item Duplicate action.
 * ------------------------------------------------------------------------*/

/**
 * Duplicate a menu item and every descendant beneath it.
 *
 * Descendant collection: preorder DFS over the source menu items so the root
 * is inserted first and parent IDs always exist by the time their children
 * point at them in pass 2.
 *
 * @param int $source_menu_id        Source menu term_id.
 * @param int $source_item_id        Menu item ID inside the source menu (the branch root).
 * @param int $target_menu_id        Optional. Defaults to the source menu (in-place clone).
 * @param int $target_parent_item_id Optional. Existing item ID in the target menu to nest the duplicated root under (0 = top-level).
 * @return array|WP_Error            On success: array with menu_id, new_root_id, items_copied, items_in_branch.
 */
function sge_mm_duplicate_menu_item_branch( $source_menu_id, $source_item_id, $target_menu_id = 0, $target_parent_item_id = 0 ) {
	$source_menu_id        = (int) $source_menu_id;
	$source_item_id        = (int) $source_item_id;
	$target_menu_id        = (int) $target_menu_id;
	$target_parent_item_id = (int) $target_parent_item_id;

	if ( $source_menu_id <= 0 || $source_item_id <= 0 ) {
		return new WP_Error( 'sge_mm_invalid_args', __( 'Invalid menu or item id.', 'sge-mega-menu' ) );
	}
	if ( $target_menu_id <= 0 ) { $target_menu_id = $source_menu_id; }

	$source_menu = wp_get_nav_menu_object( $source_menu_id );
	if ( ! $source_menu || is_wp_error( $source_menu ) ) {
		return new WP_Error( 'sge_mm_source_menu_missing', __( 'Source menu not found.', 'sge-mega-menu' ) );
	}
	$target_menu = ( $target_menu_id === $source_menu_id ) ? $source_menu : wp_get_nav_menu_object( $target_menu_id );
	if ( ! $target_menu || is_wp_error( $target_menu ) ) {
		return new WP_Error( 'sge_mm_target_menu_missing', __( 'Target menu not found.', 'sge-mega-menu' ) );
	}

	$items = wp_get_nav_menu_items( $source_menu_id, array(
		'update_post_term_cache' => false,
	) );
	if ( empty( $items ) ) {
		return new WP_Error( 'sge_mm_no_items', __( 'Source menu has no items.', 'sge-mega-menu' ) );
	}

	// Build id→item lookup and parent→[child ids] map.
	$by_id = array();
	$kids  = array();
	foreach ( $items as $it ) {
		$by_id[ (int) $it->ID ] = $it;
		$pid = (int) $it->menu_item_parent;
		if ( ! isset( $kids[ $pid ] ) ) { $kids[ $pid ] = array(); }
		$kids[ $pid ][] = (int) $it->ID;
	}
	if ( ! isset( $by_id[ $source_item_id ] ) ) {
		return new WP_Error( 'sge_mm_item_not_in_menu', __( 'Item not found in source menu.', 'sge-mega-menu' ) );
	}

	// Preorder DFS: root first, then each child subtree in original order.
	$branch_ids = array();
	$stack      = array( $source_item_id );
	while ( $stack ) {
		$current      = array_shift( $stack );
		$branch_ids[] = $current;
		if ( ! empty( $kids[ $current ] ) ) {
			// Push children to the FRONT of the stack so they're visited next, preserving original order.
			array_splice( $stack, 0, 0, $kids[ $current ] );
		}
	}

	// Pass 1: insert each item as a top-level item, recording old→new id map.
	$id_map = array();
	foreach ( $branch_ids as $old_id ) {
		$item = $by_id[ $old_id ];
		$args = sge_mm_item_args( $item, 0 );
		$new_item_id = wp_update_nav_menu_item( $target_menu_id, 0, $args );
		if ( is_wp_error( $new_item_id ) || ! $new_item_id ) { continue; }
		$id_map[ $old_id ] = (int) $new_item_id;
	}

	// Pass 2: fix parent ids. The branch root parents to $target_parent_item_id;
	// every other item parents to whatever its old parent mapped to.
	foreach ( $branch_ids as $old_id ) {
		if ( ! isset( $id_map[ $old_id ] ) ) { continue; }
		$new_item_id = $id_map[ $old_id ];
		$item        = $by_id[ $old_id ];

		if ( $old_id === $source_item_id ) {
			$new_parent = $target_parent_item_id;
		} else {
			$old_parent = (int) $item->menu_item_parent;
			$new_parent = isset( $id_map[ $old_parent ] ) ? $id_map[ $old_parent ] : 0;
		}

		$args = sge_mm_item_args( $item, $new_parent );
		wp_update_nav_menu_item( $target_menu_id, $new_item_id, $args );
	}

	return array(
		'menu_id'         => $target_menu_id,
		'new_root_id'     => isset( $id_map[ $source_item_id ] ) ? $id_map[ $source_item_id ] : 0,
		'items_copied'    => count( $id_map ),
		'items_in_branch' => count( $branch_ids ),
	);
}

/** Nonced URL for the per-item Duplicate action. */
function sge_mm_duplicate_item_url( $menu_id, $item_id ) {
	return add_query_arg(
		array(
			'action'   => 'sge_mm_duplicate_menu_item_branch',
			'menu_id'  => (int) $menu_id,
			'item_id'  => (int) $item_id,
			'_wpnonce' => wp_create_nonce( 'sge_mm_duplicate_item_' . (int) $item_id ),
		),
		admin_url( 'admin-post.php' )
	);
}

/** admin-post handler — duplicates the branch and redirects with a flash flag. */
function sge_mm_handle_duplicate_menu_item_branch() {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to manage navigation menus.', 'sge-mega-menu' ), '', array( 'response' => 403 ) );
	}

	$menu_id = isset( $_GET['menu_id'] ) ? (int) $_GET['menu_id'] : 0;
	$item_id = isset( $_GET['item_id'] ) ? (int) $_GET['item_id'] : 0;
	if ( $menu_id <= 0 || $item_id <= 0 ) {
		wp_die( esc_html__( 'Invalid menu or item id.', 'sge-mega-menu' ), '', array( 'response' => 400 ) );
	}
	check_admin_referer( 'sge_mm_duplicate_item_' . $item_id );

	@set_time_limit( 120 );
	if ( function_exists( 'wp_raise_memory_limit' ) ) { wp_raise_memory_limit( 'admin' ); }

	$err_msg = '';
	$result  = null;
	try {
		// Default: clone in the same menu, as a sibling of the source (target_parent = 0 = top-level).
		// We could nest under the source's parent instead — that would put the copy literally next
		// to the source — but starting top-level is safer (no accidental hierarchy shifts when the
		// source was deeply nested).
		$result = sge_mm_duplicate_menu_item_branch( $menu_id, $item_id, $menu_id, 0 );
	} catch ( \Throwable $e ) {
		$err_msg = $e->getMessage();
		error_log( '[SGE Mega Menu] Item branch duplicate threw for menu ' . $menu_id . ', item ' . $item_id . ': ' . $err_msg . ' @ ' . $e->getFile() . ':' . $e->getLine() );
	}

	if ( $err_msg !== '' || is_wp_error( $result ) ) {
		if ( is_wp_error( $result ) ) {
			$err_msg = $result->get_error_message();
			error_log( '[SGE Mega Menu] Item branch duplicate returned WP_Error: ' . $err_msg );
		}
		$back = wp_get_referer() ? wp_get_referer() : admin_url( 'nav-menus.php' );
		sge_mm_redirect_safely(
			add_query_arg(
				array(
					'sge_mm_dup' => 'item_err',
					'sge_mm_msg' => rawurlencode( $err_msg !== '' ? $err_msg : __( 'Unknown error.', 'sge-mega-menu' ) ),
				),
				$back
			),
			__( 'Failed to duplicate menu item', 'sge-mega-menu' )
		);
		exit;
	}

	sge_mm_redirect_safely(
		add_query_arg(
			array(
				'action'       => 'edit',
				'menu'         => $menu_id,
				'sge_mm_dup'   => 'item_ok',
				'sge_mm_count' => isset( $result['items_in_branch'] ) ? (int) $result['items_in_branch'] : 0,
			),
			admin_url( 'nav-menus.php' )
		),
		__( 'Menu item duplicated', 'sge-mega-menu' )
	);
	exit;
}
add_action( 'admin_post_sge_mm_duplicate_menu_item_branch', 'sge_mm_handle_duplicate_menu_item_branch' );

/* --------------------------------------------------------------------------
 * Per-item UI — render a "Duplicate item (with descendants)" link inside
 * each menu item's settings panel on Appearance → Menus.
 * ------------------------------------------------------------------------*/

function sge_mm_render_item_duplicate_field( $item_id, $item = null, $depth = 0, $args = null ) {
	if ( ! current_user_can( 'edit_theme_options' ) ) { return; }

	$menu_id = isset( $GLOBALS['nav_menu_selected_id'] ) ? (int) $GLOBALS['nav_menu_selected_id'] : 0;
	if ( $menu_id <= 0 && isset( $_GET['menu'] ) ) { $menu_id = (int) $_GET['menu']; }
	if ( $menu_id <= 0 ) { return; }

	$item_id = (int) $item_id;
	if ( $item_id <= 0 ) { return; }

	$url     = sge_mm_duplicate_item_url( $menu_id, $item_id );
	$confirm = __( 'Duplicate this menu item and all its child items?', 'sge-mega-menu' );
	?>
	<p class="field-sge-mm-duplicate description description-wide">
		<a href="<?php echo esc_url( $url ); ?>"
		   class="sge-mm-item-duplicate"
		   style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border:1px solid #dcdcde;border-radius:4px;text-decoration:none;background:#fff;color:#2271b1;"
		   onclick="return confirm(<?php echo esc_attr( wp_json_encode( $confirm ) ); ?>);">
			<span class="dashicons dashicons-admin-page" style="font-size:14px;width:14px;height:14px;"></span>
			<?php esc_html_e( 'Duplicate item (with descendants)', 'sge-mega-menu' ); ?>
		</a>
	</p>
	<?php
}
add_action( 'wp_nav_menu_item_custom_fields', 'sge_mm_render_item_duplicate_field', 10, 4 );
