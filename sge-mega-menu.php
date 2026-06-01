<?php
/**
 * Plugin Name: SGE Mega Menu
 * Description: Portable mega-menu engine — renders WP nav menus as a hover-driven mega panel with 3-level or 4-level layouts, simple dropdowns, and plain links. Drop-in for any theme.
 * Version: 1.1.0
 * Author: SGE
 * Requires PHP: 7.4
 * Text Domain: sge-mega-menu
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'SGE_MM_VERSION', '1.1.0' );
define( 'SGE_MM_DIR', plugin_dir_path( __FILE__ ) );
define( 'SGE_MM_URL', plugin_dir_url( __FILE__ ) );
define( 'SGE_MM_OPTION', 'sge_mm_settings' );

/* ----------------------------------------------------------------------------
 * Settings access (single option array stored in wp_options)
 * --------------------------------------------------------------------------*/

/** Return all plugin settings merged with defaults. */
function sge_mm_get_settings() {
	$defaults = array(
		'auto_replace_enabled'    => 1,          // master on/off for the auto-replace feature
		'replace_menus'           => array(),    // menu names to auto-replace
		'replace_locations'       => array(),    // theme locations to auto-replace
		'default_source_location' => 'asla_mega',
		'default_source_menu'     => '',         // optional override (menu name); empty = use location
		'default_source_menu_id'  => 0,          // optional override (menu term_id); takes precedence over menu/location
		'custom_css'              => '',
	);
	$opts = get_option( SGE_MM_OPTION, array() );
	return wp_parse_args( is_array( $opts ) ? $opts : array(), $defaults );
}

/** Default menu source used by asla_render_mega_menu() and the shortcode. */
function sge_mm_get_default_source() {
	$s = sge_mm_get_settings();
	return array(
		'location' => $s['default_source_location'],
		'menu'     => $s['default_source_menu'],
		'menu_id'  => (int) $s['default_source_menu_id'],
	);
}

/* ----------------------------------------------------------------------------
 * Nav location
 * --------------------------------------------------------------------------*/

/** Register the desktop mega-menu nav location. Themes can override the label via filter. */
function asla_register_mega_menu_location() {
	register_nav_menu( 'asla_mega', __( 'ASLA Mega Menu (desktop)', 'sge-mega-menu' ) );
}
add_action( 'after_setup_theme', 'asla_register_mega_menu_location' );

/* ----------------------------------------------------------------------------
 * Asset registration (themes opt-in by wp_enqueue_style/script('asla-mega-menu'))
 * --------------------------------------------------------------------------*/

/** Register CSS and JS as 'asla-mega-menu' — themes enqueue when/where they want.
 * Custom CSS from the Styles tab is ATTACHED to the registered style at register time
 * (via wp_add_inline_style). That means it only loads when something genuinely enqueues
 * the 'asla-mega-menu' handle — auto-replace, shortcode, or a manual theme call. When
 * none of those happen (e.g. auto-replace toggle is off + no shortcode on the page),
 * nothing from this plugin leaks onto the front-end. */
function asla_mega_register_assets() {
	wp_register_style(
		'asla-mega-menu',
		SGE_MM_URL . 'assets/mega-menu.css',
		array(),
		file_exists( SGE_MM_DIR . 'assets/mega-menu.css' ) ? filemtime( SGE_MM_DIR . 'assets/mega-menu.css' ) : SGE_MM_VERSION
	);
	wp_register_script(
		'asla-mega-menu',
		SGE_MM_URL . 'assets/mega-menu.js',
		array(),
		file_exists( SGE_MM_DIR . 'assets/mega-menu.js' ) ? filemtime( SGE_MM_DIR . 'assets/mega-menu.js' ) : SGE_MM_VERSION,
		true
	);

	$s = sge_mm_get_settings();
	if ( ! empty( $s['custom_css'] ) ) {
		wp_add_inline_style( 'asla-mega-menu', sge_mm_forceify_css( $s['custom_css'] ) );
	}
}
add_action( 'wp_enqueue_scripts', 'asla_mega_register_assets', 5 );

/* ----------------------------------------------------------------------------
 * Renderer
 * --------------------------------------------------------------------------*/
require_once SGE_MM_DIR . 'includes/renderer.php';

/* ----------------------------------------------------------------------------
 * Auto-replace existing site menus with the mega menu
 *
 * Themes opt in by returning a list of menu NAMES (e.g. "New_Menu") and/or
 * THEME_LOCATIONS via the `sge_mm_replace_menus` / `sge_mm_replace_locations` filters.
 * When a wp_nav_menu() call (or anything wrapping it, like the [listmenu] shortcode)
 * targets one of those menus, the plugin replaces the output with asla_render_mega_menu()
 * — without modifying the theme.
 *
 * Mobile drawers built with metismenu (menu_class contains "metismenu") are skipped so
 * the existing drawer experience stays intact. Pass `bypass_sge_mm => true` in
 * wp_nav_menu args to force the original output for a specific call.
 * --------------------------------------------------------------------------*/

/** Should this wp_nav_menu() call be replaced with our mega menu? */
function sge_mm_should_replace( $args ) {
	if ( ! empty( $args->bypass_sge_mm ) ) { return false; }

	$s = sge_mm_get_settings();
	// Master switch — when off, no replacement happens regardless of which menus/locations are checked.
	if ( empty( $s['auto_replace_enabled'] ) ) { return false; }

	// Skip drawer/metismenu calls so the mobile drawer isn't replaced.
	if ( ! empty( $args->menu_class ) && false !== strpos( (string) $args->menu_class, 'metismenu' ) ) { return false; }
	if ( ! empty( $args->container_id ) && 'side-menu' === $args->container_id ) { return false; }

	$names     = (array) apply_filters( 'sge_mm_replace_menus',     $s['replace_menus'] );
	$locations = (array) apply_filters( 'sge_mm_replace_locations', $s['replace_locations'] );

	if ( ! empty( $args->theme_location ) && in_array( $args->theme_location, $locations, true ) ) { return true; }

	if ( ! empty( $args->menu ) ) {
		$name = is_object( $args->menu ) && isset( $args->menu->name ) ? $args->menu->name : (string) $args->menu;
		if ( in_array( $name, $names, true ) ) { return true; }
	}
	return false;
}

/** Short-circuit wp_nav_menu() with our mega menu output when the call targets a replaced menu. */
function sge_mm_short_circuit_nav_menu( $output, $args ) {
	if ( null !== $output ) { return $output; } // someone else already short-circuited
	if ( ! sge_mm_should_replace( $args ) ) { return $output; }
	ob_start();
	asla_render_mega_menu();
	$html = ob_get_clean();
	// Respect the echo flag — pre_wp_nav_menu output isn't echoed by core, so honour it manually.
	if ( ! empty( $args->echo ) ) { echo $html; return ''; }
	return $html;
}
add_filter( 'pre_wp_nav_menu', 'sge_mm_short_circuit_nav_menu', 10, 2 );

/** When auto-replace is active anywhere, ensure assets are enqueued site-wide and `.asla-mm` is on body. */
function sge_mm_auto_replace_is_active() {
	static $cached = null;
	if ( null !== $cached ) { return $cached; }
	$s = sge_mm_get_settings();
	if ( empty( $s['auto_replace_enabled'] ) ) { $cached = false; return $cached; }
	$names     = (array) apply_filters( 'sge_mm_replace_menus',     $s['replace_menus'] );
	$locations = (array) apply_filters( 'sge_mm_replace_locations', $s['replace_locations'] );
	$cached = ! empty( $names ) || ! empty( $locations );
	return $cached;
}

function sge_mm_auto_enqueue() {
	if ( ! sge_mm_auto_replace_is_active() ) { return; }
	if ( wp_style_is( 'asla-mega-menu', 'registered' ) )  { wp_enqueue_style( 'asla-mega-menu' ); }
	if ( wp_script_is( 'asla-mega-menu', 'registered' ) ) { wp_enqueue_script( 'asla-mega-menu' ); }
}
add_action( 'wp_enqueue_scripts', 'sge_mm_auto_enqueue', 20 );

function sge_mm_body_class( $classes ) {
	if ( sge_mm_auto_replace_is_active() && ! in_array( 'asla-mm', $classes, true ) ) {
		$classes[] = 'asla-mm';
	}
	return $classes;
}
add_filter( 'body_class', 'sge_mm_body_class' );

/** Strip legacy *_dermatology classes from any nav menu output when auto-replace is on.
 * Background: assurance_skin's custom.js has a dd=0/lis.length>0 infinite loop that fires
 * when `.SiteMenu .col_grid.medical_dermatology li.menu-item` doesn't exist (length=0 → dd=0)
 * but `.medical_dermatology ul li` does exist somewhere in the DOM. Once we replace the
 * desktop nav (which had those classes), any remaining drawer output that still carries them
 * triggers the bug. Renaming the classes here keeps the drawer visually intact (those classes
 * were styling hooks for the now-replaced desktop nav) and dodges the loop without touching
 * the theme. Scoped to auto-replace-active sites so unaffected sites stay untouched. */
function sge_mm_strip_legacy_classes( $nav_html ) {
	if ( ! sge_mm_auto_replace_is_active() ) { return $nav_html; }
	return preg_replace( '/\b(medical|surgical|aesthetic)_dermatology\b/i', '$1-derm-mm', $nav_html );
}
add_filter( 'wp_nav_menu', 'sge_mm_strip_legacy_classes', 99 );

/* ----------------------------------------------------------------------------
 * Shortcode: [sge_mega_menu]
 * Args: location | menu | menu_id (resolution priority: menu_id > menu > location)
 * --------------------------------------------------------------------------*/

function sge_mm_shortcode( $atts ) {
	$atts = shortcode_atts( array(
		'location' => '',
		'menu'     => '',
		'menu_id'  => 0,
	), $atts, 'sge_mega_menu' );

	// Drop empty values so asla_render_mega_menu() falls back to settings defaults.
	$args = array();
	if ( $atts['location'] !== '' )      { $args['location'] = $atts['location']; }
	if ( $atts['menu']     !== '' )      { $args['menu']     = $atts['menu']; }
	if ( (int) $atts['menu_id'] > 0 )    { $args['menu_id']  = (int) $atts['menu_id']; }

	// Make sure assets are enqueued — shortcodes can land on any page.
	if ( wp_style_is( 'asla-mega-menu', 'registered' ) )  { wp_enqueue_style( 'asla-mega-menu' ); }
	if ( wp_script_is( 'asla-mega-menu', 'registered' ) ) { wp_enqueue_script( 'asla-mega-menu' ); }

	ob_start();
	asla_render_mega_menu( $args );
	return ob_get_clean();
}
add_shortcode( 'sge_mega_menu', 'sge_mm_shortcode' );

/* ----------------------------------------------------------------------------
 * Custom CSS sanitizer — attached to the asla-mega-menu style handle at register
 * time (see asla_mega_register_assets above), so it only loads when the handle
 * itself loads. No standalone enqueue hook = no leakage when auto-replace is off.
 * --------------------------------------------------------------------------*/

/**
 * Auto-append `!important` to every declaration that doesn't already have it.
 * Reason: the plugin's base CSS uses high-specificity selectors (prefixed with
 * `.primary-nav .SiteMenu`) to defeat the legacy theme stylesheet — a custom rule
 * written by hand in the Styles tab rarely matches that specificity. Marking each
 * declaration `!important` makes user CSS win predictably without requiring the
 * user to know about specificity.
 *
 * Handles: ignores comments (preserves them), respects existing !important,
 * skips at-rules' bodies that are non-declaration (@keyframes still gets processed
 * — animation keyframe declarations can take !important too).
 */
function sge_mm_forceify_css( $css ) {
	// Process only inside { ... } blocks. The regex finds each declaration body
	// and walks declarations one at a time.
	return preg_replace_callback(
		'/\{([^{}]*)\}/s',
		function ( $m ) {
			$body  = $m[1];
			$lines = preg_split( '/(?<=;|^)/', $body );
			$out   = '';
			foreach ( $lines as $line ) {
				// Skip empty / pure-whitespace pieces and CSS comments.
				if ( '' === trim( $line ) || preg_match( '/^\s*\/\*/', $line ) ) {
					$out .= $line;
					continue;
				}
				// Must look like a declaration: "prop: value" optionally ending in ;
				if ( ! preg_match( '/^(\s*)([\w-]+\s*:\s*[^;]+?)(\s*;?)(\s*)$/s', $line, $parts ) ) {
					$out .= $line;
					continue;
				}
				$indent  = $parts[1];
				$decl    = rtrim( $parts[2] );
				$semi    = $parts[3];
				$trail   = $parts[4];
				// Don't double up if user already wrote !important.
				if ( preg_match( '/!\s*important\s*$/i', $decl ) ) {
					$out .= $line;
					continue;
				}
				$out .= $indent . $decl . ' !important' . ( '' === $semi ? ';' : $semi ) . $trail;
			}
			return '{' . $out . '}';
		},
		(string) $css
	);
}

/* ----------------------------------------------------------------------------
 * Admin settings page (loaded only in wp-admin)
 * --------------------------------------------------------------------------*/

if ( is_admin() ) {
	require_once SGE_MM_DIR . 'includes/admin.php';
	require_once SGE_MM_DIR . 'includes/duplicate.php';
}
