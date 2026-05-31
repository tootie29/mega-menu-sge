<?php
/**
 * Tree renderer for the SGE Mega Menu plugin.
 * Reads a WP nav menu assigned to the `asla_mega` location and emits 4-level
 * (top → tab → side-tab → item) or 3-level (top → side-tab → item) markup,
 * or a simple dropdown / plain link based on the structure of each top item.
 *
 * @package SGE_Mega_Menu
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Render the desktop mega menu.
 *
 * Accepts either a string (treated as a theme location, back-compat) or an args array:
 *   asla_render_mega_menu();                                     // default location (sge_mm_get_default_source)
 *   asla_render_mega_menu( 'asla_mega' );                        // explicit location
 *   asla_render_mega_menu( array( 'location' => 'my_loc' ) );    // location
 *   asla_render_mega_menu( array( 'menu' => 'Mega Menu 2026' ) );// menu by name
 *   asla_render_mega_menu( array( 'menu_id' => 61 ) );           // menu by ID
 *
 * Resolution order: menu_id → menu (name/slug) → location.
 *
 * Depth map per top item:
 *   - has-mega + grandchildren of children → 4-level mega (Services)
 *   - has-mega + children only             → 3-level mega (Treatments)
 *   - children but no has-mega             → simple dropdown (About Us)
 *   - no children                          → plain link (Homepage / Blog / Product)
 */
function asla_render_mega_menu( $args = array() ) {
	if ( is_string( $args ) )   { $args = array( 'location' => $args ); }
	if ( ! is_array( $args ) )  { $args = array(); }

	$defaults = function_exists( 'sge_mm_get_default_source' )
		? sge_mm_get_default_source()
		: array( 'location' => 'asla_mega', 'menu' => '', 'menu_id' => 0 );
	$args = wp_parse_args( $args, $defaults );

	$menu = null;
	if ( ! empty( $args['menu_id'] ) ) {
		$menu = wp_get_nav_menu_object( (int) $args['menu_id'] );
	} elseif ( ! empty( $args['menu'] ) ) {
		$menu = wp_get_nav_menu_object( $args['menu'] );
	} elseif ( ! empty( $args['location'] ) ) {
		$locations = get_nav_menu_locations();
		if ( ! empty( $locations[ $args['location'] ] ) ) {
			$menu = wp_get_nav_menu_object( $locations[ $args['location'] ] );
		}
	}
	if ( ! $menu ) { return; }
	$items = wp_get_nav_menu_items( $menu->term_id );
	if ( empty( $items ) ) { return; }

	$by_parent = array();
	foreach ( $items as $item ) {
		$by_parent[ (int) $item->menu_item_parent ][] = $item;
	}
	$tops = isset( $by_parent[0] ) ? $by_parent[0] : array();

	echo '<ul class="asla-mega-nav">';
	foreach ( $tops as $top ) {
		$kids    = isset( $by_parent[ $top->ID ] ) ? $by_parent[ $top->ID ] : array();
		$is_mega = in_array( 'has-mega', (array) $top->classes, true ) && ! empty( $kids );
		if ( $is_mega ) {
			asla_render_mega_item( $top, $by_parent );
		} elseif ( ! empty( $kids ) ) {
			asla_render_dropdown_item( $top, $kids );
		} else {
			printf(
				'<li class="asla-mega-nav__item"><a href="%s">%s</a></li>',
				esc_url( $top->url ),
				esc_html( $top->title )
			);
		}
	}
	echo '</ul>';
}

/** Render a simple-dropdown top item (children but no `has-mega` class). */
function asla_render_dropdown_item( $top, $kids ) {
	echo '<li class="asla-mega-nav__item asla-mega-nav__item--has-dropdown">';
	printf(
		'<a href="%s">%s<span class="asla-mega__caret" aria-hidden="true"></span></a>',
		esc_url( $top->url ),
		esc_html( $top->title )
	);
	echo '<ul class="asla-mega-nav__dropdown">';
	foreach ( $kids as $kid ) {
		printf(
			'<li><a href="%s">%s</a></li>',
			esc_url( $kid->url ),
			esc_html( $kid->title )
		);
	}
	echo '</ul></li>';
}

/** Render one mega-enabled top item with its panel. Auto-detects 3-level vs 4-level. */
function asla_render_mega_item( $top, $by_parent ) {
	$children = $by_parent[ $top->ID ];

	// 4-level if any grandchild has children of its own; else 3-level.
	$is_four_level = false;
	foreach ( $children as $child ) {
		if ( empty( $by_parent[ $child->ID ] ) ) { continue; }
		foreach ( $by_parent[ $child->ID ] as $grand ) {
			if ( ! empty( $by_parent[ $grand->ID ] ) ) { $is_four_level = true; break 2; }
		}
	}

	echo '<li class="asla-mega-nav__item asla-mega has-mega">';
	printf(
		'<a href="%s" class="asla-mega__top">%s<span class="asla-mega__caret" aria-hidden="true"></span></a>',
		esc_url( $top->url ),
		esc_html( $top->title )
	);
	echo '<div class="asla-mega__panel"><div class="asla-mega__inner">';

	if ( $is_four_level ) {
		// Horizontal tabs.
		echo '<ul class="asla-mega__tabs" role="tablist">';
		foreach ( $children as $i => $tab ) {
			printf(
				'<li><a href="%s" class="asla-mega__tab%s" data-tab="tab-%d">%s</a></li>',
				esc_url( $tab->url ),
				0 === $i ? ' is-active' : '',
				(int) $tab->ID,
				esc_html( $tab->title )
			);
		}
		echo '</ul>';

		// One body per tab.
		echo '<div class="asla-mega__bodies">';
		foreach ( $children as $i => $tab ) {
			$sides = isset( $by_parent[ $tab->ID ] ) ? $by_parent[ $tab->ID ] : array();
			printf(
				'<div class="asla-mega__body%s" data-tab-body="tab-%d">',
				0 === $i ? ' is-active' : '',
				(int) $tab->ID
			);
			asla_render_sides_and_content( $sides, $by_parent );
			echo '</div>';
		}
		echo '</div>';
	} else {
		// 3-level: single always-active body with the side-rail + content.
		echo '<div class="asla-mega__bodies">';
		printf( '<div class="asla-mega__body is-active" data-tab-body="tab-%d">', (int) $top->ID );
		asla_render_sides_and_content( $children, $by_parent );
		echo '</div>';
		echo '</div>';
	}

	echo '</div></div></li>'; // .asla-mega__inner .asla-mega__panel .asla-mega-nav__item
}

/** Render the side-tabs rail + right-content groups. Used by both 3-level and 4-level mega menus. */
function asla_render_sides_and_content( $sides, $by_parent ) {
	// First side-tab that has items becomes default-active.
	$active_side_id = 0;
	foreach ( $sides as $side ) {
		if ( ! empty( $by_parent[ $side->ID ] ) ) { $active_side_id = (int) $side->ID; break; }
	}

	// Side-tabs rail.
	echo '<ul class="asla-mega__sidetabs">';
	foreach ( $sides as $side ) {
		$side_items = isset( $by_parent[ $side->ID ] ) ? $by_parent[ $side->ID ] : array();
		$is_label   = empty( $side_items );
		$classes    = 'asla-mega__sidetab';
		if ( $is_label ) { $classes .= ' asla-mega__sidetab--label'; }
		if ( (int) $side->ID === $active_side_id ) { $classes .= ' is-active'; }
		printf(
			'<li><a href="%s" class="%s" data-side="side-%d">%s%s</a></li>',
			esc_url( $side->url ),
			esc_attr( $classes ),
			(int) $side->ID,
			esc_html( $side->title ),
			$is_label ? '' : '<span class="asla-mega__arrow" aria-hidden="true"></span>'
		);
	}
	echo '</ul>';

	// Right-content groups (one per side-tab that has items).
	echo '<div class="asla-mega__content">';
	foreach ( $sides as $side ) {
		$side_items = isset( $by_parent[ $side->ID ] ) ? $by_parent[ $side->ID ] : array();
		if ( empty( $side_items ) ) { continue; }
		printf(
			'<div class="asla-mega__group%s" data-side-body="side-%d">',
			(int) $side->ID === $active_side_id ? ' is-active' : '',
			(int) $side->ID
		);
		printf( '<h3 class="asla-mega__heading">%s</h3>', esc_html( $side->title ) );
		echo '<ul class="asla-mega__items">';
		foreach ( $side_items as $leaf ) {
			printf( '<li><a href="%s">%s</a></li>', esc_url( $leaf->url ), esc_html( $leaf->title ) );
		}
		echo '</ul></div>';
	}
	echo '</div>';
}
