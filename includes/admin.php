<?php
/**
 * Admin settings page for the SGE Mega Menu plugin.
 *
 * Top-level menu "SGE Mega Menu" with two tabs:
 *   - General → which existing menus/locations to auto-replace, default mega menu source
 *   - Styles  → CodeMirror CSS editor + side reference panel
 *
 * Settings stored as a single option array under SGE_MM_OPTION.
 *
 * @package SGE_Mega_Menu
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* --------------------------------------------------------------------------
 * Menu registration
 * ------------------------------------------------------------------------*/

function sge_mm_admin_menu() {
	add_menu_page(
		__( 'SGE Mega Menu', 'sge-mega-menu' ),
		__( 'SGE Mega Menu', 'sge-mega-menu' ),
		'manage_options',
		'sge-mega-menu',
		'sge_mm_render_settings_page',
		'dashicons-menu-alt3',
		61
	);
}
add_action( 'admin_menu', 'sge_mm_admin_menu' );

/* --------------------------------------------------------------------------
 * Settings API
 * ------------------------------------------------------------------------*/

function sge_mm_register_settings() {
	register_setting(
		'sge_mm_settings_group',
		SGE_MM_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'sge_mm_sanitize_settings',
			'default'           => array(),
		)
	);
}
add_action( 'admin_init', 'sge_mm_register_settings' );

/** Merge incoming tab fields into the existing record (other tab's values are preserved). */
function sge_mm_sanitize_settings( $input ) {
	$current = sge_mm_get_settings();
	$out     = $current;
	$tab     = isset( $input['__tab'] ) ? sanitize_key( $input['__tab'] ) : '';

	if ( $tab === 'general' ) {
		$out['auto_replace_enabled']    = ! empty( $input['auto_replace_enabled'] ) ? 1 : 0;
		$out['replace_menus']           = isset( $input['replace_menus'] )           ? array_map( 'sanitize_text_field', (array) $input['replace_menus'] )     : array();
		$out['replace_locations']       = isset( $input['replace_locations'] )       ? array_map( 'sanitize_key',         (array) $input['replace_locations'] ) : array();
		$out['default_source_location'] = isset( $input['default_source_location'] ) ? sanitize_key( $input['default_source_location'] ) : 'asla_mega';
		$out['default_source_menu']     = isset( $input['default_source_menu'] )     ? sanitize_text_field( $input['default_source_menu'] ) : '';
		$out['default_source_menu_id']  = isset( $input['default_source_menu_id'] )  ? (int) $input['default_source_menu_id'] : 0;
	}

	if ( $tab === 'styles' ) {
		// Admin-only setting, capability-gated — raw CSS preserved.
		$out['custom_css'] = isset( $input['custom_css'] ) ? wp_unslash( $input['custom_css'] ) : '';
	}

	return $out;
}

/* --------------------------------------------------------------------------
 * Admin assets — page stylesheet + CodeMirror for the Styles tab
 * ------------------------------------------------------------------------*/

function sge_mm_admin_assets( $hook ) {
	if ( $hook !== 'toplevel_page_sge-mega-menu' ) { return; }

	// Page stylesheet (every tab).
	wp_enqueue_style(
		'sge-mm-admin',
		SGE_MM_URL . 'assets/admin.css',
		array(),
		file_exists( SGE_MM_DIR . 'assets/admin.css' ) ? filemtime( SGE_MM_DIR . 'assets/admin.css' ) : SGE_MM_VERSION
	);
	wp_enqueue_style( 'dashicons' );

	$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';

	// Tiny inline JS on the General tab: live-fade the dependent fields when the master toggle flips.
	if ( $active_tab === 'general' ) {
		wp_add_inline_script(
			'jquery-core',
			"jQuery(function(){
				var t = document.getElementById('sge-mm-auto-toggle');
				var deps = document.querySelectorAll('.sge-mm-conditional');
				if (!t || !deps.length) return;
				t.addEventListener('change', function(){
					deps.forEach(function(d){ d.setAttribute('data-disabled', t.checked ? '0' : '1'); });
				});
			});"
		);
	}

	// CodeMirror only on the Styles tab.
	if ( $active_tab !== 'styles' ) { return; }

	$settings = wp_enqueue_code_editor( array( 'type' => 'text/css' ) );
	if ( false === $settings ) { return; }

	wp_add_inline_script(
		'code-editor',
		"jQuery(function(){
			var el = document.getElementById('sge-mm-custom-css');
			if (el && window.wp && wp.codeEditor) { wp.codeEditor.initialize(el, " . wp_json_encode( $settings ) . "); }
			// Click-to-copy snippets in the side reference panel.
			document.querySelectorAll('.sge-mm-snippet').forEach(function(node){
				node.addEventListener('click', function(){
					var text = node.textContent.replace(/\\s+↑ click to copy$/,'').trim();
					navigator.clipboard.writeText(text).then(function(){
						node.classList.add('sge-mm-snippet--copied');
						setTimeout(function(){ node.classList.remove('sge-mm-snippet--copied'); }, 1200);
					});
				});
			});
		});"
	);
}
add_action( 'admin_enqueue_scripts', 'sge_mm_admin_assets' );

/* --------------------------------------------------------------------------
 * Page render — banner + tabs + dispatch
 * ------------------------------------------------------------------------*/

function sge_mm_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }

	$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
	$tabs       = array(
		'general' => array( 'label' => __( 'General', 'sge-mega-menu' ), 'icon' => 'admin-generic' ),
		'styles'  => array( 'label' => __( 'Styles',  'sge-mega-menu' ), 'icon' => 'editor-code' ),
	);
	$s          = sge_mm_get_settings();
	?>
	<div class="wrap sge-mm-wrap">
		<header class="sge-mm-banner">
			<div class="sge-mm-banner__icon"><span class="dashicons dashicons-menu-alt3"></span></div>
			<div>
				<h1 class="sge-mm-banner__title"><?php esc_html_e( 'SGE Mega Menu', 'sge-mega-menu' ); ?></h1>
				<p class="sge-mm-banner__subtitle"><?php esc_html_e( 'Hover-driven mega panel for WordPress nav menus — auto-detects 3-level vs 4-level layouts, simple dropdowns, and plain links from your menu structure.', 'sge-mega-menu' ); ?></p>
			</div>
			<div class="sge-mm-banner__meta">
				<div><span class="sge-mm-banner__version">v<?php echo esc_html( SGE_MM_VERSION ); ?></span></div>
				<div style="margin-top:6px"><?php
					if ( empty( $s['auto_replace_enabled'] ) ) {
						$status = '<span class="sge-mm-badge sge-mm-badge--danger">⏸ Auto-replace disabled</span>';
					} elseif ( sge_mm_auto_replace_is_active() ) {
						$status = '<span class="sge-mm-badge sge-mm-badge--success">● Auto-replace active</span>';
					} else {
						$status = '<span class="sge-mm-badge sge-mm-badge--neutral">○ Shortcode only</span>';
					}
					echo wp_kses_post( $status );
				?></div>
			</div>
		</header>

		<nav class="sge-mm-tabs">
			<?php foreach ( $tabs as $slug => $tab ) :
				$url = add_query_arg( array( 'page' => 'sge-mega-menu', 'tab' => $slug ), admin_url( 'admin.php' ) );
				$cls = 'sge-mm-tab' . ( $active_tab === $slug ? ' is-active' : '' );
				?>
				<a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( $cls ); ?>">
					<span class="dashicons dashicons-<?php echo esc_attr( $tab['icon'] ); ?>"></span>
					<?php echo esc_html( $tab['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>

		<form method="post" action="options.php">
			<?php settings_fields( 'sge_mm_settings_group' ); ?>
			<input type="hidden" name="<?php echo esc_attr( SGE_MM_OPTION ); ?>[__tab]" value="<?php echo esc_attr( $active_tab ); ?>" />

			<?php
			if ( $active_tab === 'styles' ) { sge_mm_render_styles_tab( $s ); }
			else                            { sge_mm_render_general_tab( $s ); }
			?>

			<div class="sge-mm-actions">
				<?php submit_button( __( 'Save Changes', 'sge-mega-menu' ), 'primary', 'submit', false ); ?>
				<span class="sge-mm-actions__hint"><?php echo $active_tab === 'styles'
					? esc_html__( 'Saves the Styles tab. General settings are preserved.', 'sge-mega-menu' )
					: esc_html__( 'Saves the General tab. Custom CSS is preserved.', 'sge-mega-menu' ); ?></span>
			</div>
		</form>

		<footer class="sge-mm-footer">
			<div><?php esc_html_e( 'SGE Mega Menu', 'sge-mega-menu' ); ?> · <?php esc_html_e( 'Self-contained plugin — assets at', 'sge-mega-menu' ); ?> <code>wp-content/plugins/sge-mega-menu/</code></div>
			<div><a href="<?php echo esc_url( admin_url( 'nav-menus.php' ) ); ?>"><?php esc_html_e( 'Edit menus →', 'sge-mega-menu' ); ?></a></div>
		</footer>
	</div>
	<?php
}

/* --------------------------------------------------------------------------
 * General tab
 * ------------------------------------------------------------------------*/

function sge_mm_render_general_tab( $s ) {
	$opt_name      = SGE_MM_OPTION;
	$all_menus     = wp_get_nav_menus();
	$all_locations = get_registered_nav_menus();

	$n_menus = count( (array) $s['replace_menus'] );
	$n_locs  = count( (array) $s['replace_locations'] );
	?>

	<section class="sge-mm-card">
		<header class="sge-mm-card__header">
			<h2 class="sge-mm-card__title"><span class="dashicons dashicons-admin-links"></span> <?php esc_html_e( 'Default mega menu source', 'sge-mega-menu' ); ?></h2>
		</header>
		<p class="sge-mm-card__intro"><?php esc_html_e( 'The menu used by auto-replace and by [sge_mega_menu] when called without arguments. Resolution priority: Menu ID → Menu name → Location.', 'sge-mega-menu' ); ?></p>

		<div class="sge-mm-grid">
			<label class="sge-mm-label" for="sge-mm-default-location"><?php esc_html_e( 'Location', 'sge-mega-menu' ); ?></label>
			<div>
				<select id="sge-mm-default-location" name="<?php echo esc_attr( $opt_name ); ?>[default_source_location]">
					<option value=""><?php esc_html_e( '— None —', 'sge-mega-menu' ); ?></option>
					<?php foreach ( $all_locations as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $s['default_source_location'], $slug ); ?>><?php echo esc_html( $label ); ?> <code>(<?php echo esc_html( $slug ); ?>)</code></option>
					<?php endforeach; ?>
				</select>
				<p class="sge-mm-field-help"><?php esc_html_e( 'Resolves to whichever menu is currently assigned to this location in Appearance → Menus.', 'sge-mega-menu' ); ?></p>
			</div>

			<label class="sge-mm-label" for="sge-mm-default-menu"><?php esc_html_e( 'Menu name', 'sge-mega-menu' ); ?></label>
			<div>
				<select id="sge-mm-default-menu" name="<?php echo esc_attr( $opt_name ); ?>[default_source_menu]">
					<option value=""><?php esc_html_e( '— Use location —', 'sge-mega-menu' ); ?></option>
					<?php foreach ( $all_menus as $m ) : ?>
						<option value="<?php echo esc_attr( $m->name ); ?>" <?php selected( $s['default_source_menu'], $m->name ); ?>><?php echo esc_html( $m->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="sge-mm-field-help"><?php esc_html_e( 'Optional. Overrides the location above if set.', 'sge-mega-menu' ); ?></p>
			</div>

			<label class="sge-mm-label" for="sge-mm-default-menu-id"><?php esc_html_e( 'Menu ID', 'sge-mega-menu' ); ?></label>
			<div>
				<input type="number" id="sge-mm-default-menu-id" name="<?php echo esc_attr( $opt_name ); ?>[default_source_menu_id]" value="<?php echo esc_attr( (int) $s['default_source_menu_id'] ); ?>" min="0" step="1" class="small-text" />
				<p class="sge-mm-field-help"><?php esc_html_e( 'Optional. Direct term_id of the nav menu. Leave 0 to use Menu name or Location.', 'sge-mega-menu' ); ?></p>
			</div>
		</div>
	</section>

	<?php $auto_on = ! empty( $s['auto_replace_enabled'] ); ?>
	<section class="sge-mm-card">
		<header class="sge-mm-card__header">
			<h2 class="sge-mm-card__title"><span class="dashicons dashicons-update-alt"></span> <?php esc_html_e( 'Auto-replace existing menus', 'sge-mega-menu' ); ?></h2>
			<?php if ( $auto_on ) : ?>
				<span class="sge-mm-badge sge-mm-badge--<?php echo ( $n_menus + $n_locs ) > 0 ? 'success' : 'neutral'; ?>">
					<?php
					printf(
						/* translators: 1: number of menus, 2: number of locations */
						esc_html__( '%1$s menu / %2$s location', 'sge-mega-menu' ),
						(int) $n_menus,
						(int) $n_locs
					);
					?>
				</span>
			<?php else : ?>
				<span class="sge-mm-badge sge-mm-badge--danger"><?php esc_html_e( 'Disabled', 'sge-mega-menu' ); ?></span>
			<?php endif; ?>
		</header>

		<div class="sge-mm-toggle-row">
			<label class="sge-mm-switch">
				<input type="checkbox" id="sge-mm-auto-toggle" name="<?php echo esc_attr( $opt_name ); ?>[auto_replace_enabled]" value="1" <?php checked( $auto_on ); ?> />
				<span class="sge-mm-switch__slider" aria-hidden="true"></span>
			</label>
			<div>
				<div class="sge-mm-toggle-row__title"><?php esc_html_e( 'Enable auto-replace', 'sge-mega-menu' ); ?></div>
				<div class="sge-mm-toggle-row__hint"><?php esc_html_e( 'When OFF, the plugin only renders via the [sge_mega_menu] shortcode. Your menu and location selections below are preserved but not active.', 'sge-mega-menu' ); ?></div>
			</div>
		</div>

		<p class="sge-mm-card__intro"><?php esc_html_e( 'Anywhere the theme renders one of these via wp_nav_menu() or [listmenu], the output is swapped for the mega menu above. Mobile-drawer calls (metismenu / #side-menu) are auto-skipped so drawers stay intact.', 'sge-mega-menu' ); ?></p>

		<div class="sge-mm-grid sge-mm-conditional" data-disabled="<?php echo $auto_on ? '0' : '1'; ?>">
			<label class="sge-mm-label"><?php esc_html_e( 'Menu names', 'sge-mega-menu' ); ?></label>
			<div>
				<?php if ( $all_menus ) : ?>
					<div class="sge-mm-checkgroup">
						<?php foreach ( $all_menus as $m ) :
							$checked = in_array( $m->name, (array) $s['replace_menus'], true );
							$count   = $m->count;
							?>
							<label class="sge-mm-checkgroup__item">
								<input type="checkbox" name="<?php echo esc_attr( $opt_name ); ?>[replace_menus][]" value="<?php echo esc_attr( $m->name ); ?>" <?php checked( $checked ); ?> />
								<span><?php echo esc_html( $m->name ); ?></span>
								<code><?php echo (int) $count; ?> items</code>
							</label>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<p class="sge-mm-field-help"><em><?php esc_html_e( 'No nav menus defined yet — create one in Appearance → Menus first.', 'sge-mega-menu' ); ?></em></p>
				<?php endif; ?>
			</div>

			<label class="sge-mm-label"><?php esc_html_e( 'Theme locations', 'sge-mega-menu' ); ?></label>
			<div>
				<?php if ( $all_locations ) : ?>
					<div class="sge-mm-checkgroup">
						<?php foreach ( $all_locations as $slug => $label ) :
							$checked = in_array( $slug, (array) $s['replace_locations'], true );
							?>
							<label class="sge-mm-checkgroup__item">
								<input type="checkbox" name="<?php echo esc_attr( $opt_name ); ?>[replace_locations][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $checked ); ?> />
								<span><?php echo esc_html( $label ); ?></span>
								<code><?php echo esc_html( $slug ); ?></code>
							</label>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<p class="sge-mm-field-help"><em><?php esc_html_e( 'No theme locations registered.', 'sge-mega-menu' ); ?></em></p>
				<?php endif; ?>
			</div>
		</div>
	</section>

	<section class="sge-mm-card">
		<header class="sge-mm-card__header">
			<h2 class="sge-mm-card__title"><span class="dashicons dashicons-shortcode"></span> <?php esc_html_e( 'Shortcode usage', 'sge-mega-menu' ); ?></h2>
		</header>
		<p class="sge-mm-card__intro"><?php esc_html_e( 'Drop the mega menu anywhere — a page, a widget, a template. With no arguments it uses the default source above.', 'sge-mega-menu' ); ?></p>

		<div class="sge-mm-shortcode-list">
			<div><code>[sge_mega_menu]</code> <small><?php esc_html_e( 'Default source (the menu picked above).', 'sge-mega-menu' ); ?></small></div>
			<div><code>[sge_mega_menu location="primary"]</code> <small><?php esc_html_e( 'Render the menu currently assigned to a specific theme location.', 'sge-mega-menu' ); ?></small></div>
			<div><code>[sge_mega_menu menu="My Menu Name"]</code> <small><?php esc_html_e( 'Render a specific menu by its name in Appearance → Menus.', 'sge-mega-menu' ); ?></small></div>
			<div><code>[sge_mega_menu menu_id="42"]</code> <small><?php esc_html_e( 'Render by direct term_id — most stable if menu names may change.', 'sge-mega-menu' ); ?></small></div>
		</div>
	</section>
	<?php
}

/* --------------------------------------------------------------------------
 * Styles tab — CodeMirror editor + reference panel
 * ------------------------------------------------------------------------*/

function sge_mm_render_styles_tab( $s ) {
	$opt_name = SGE_MM_OPTION;
	?>
	<div class="sge-mm-styles-grid">
		<section class="sge-mm-card sge-mm-editor">
			<header class="sge-mm-card__header">
				<h2 class="sge-mm-card__title"><span class="dashicons dashicons-editor-code"></span> <?php esc_html_e( 'Custom CSS', 'sge-mega-menu' ); ?></h2>
				<span class="sge-mm-badge sge-mm-badge--info"><?php esc_html_e( 'Auto !important', 'sge-mega-menu' ); ?></span>
			</header>
			<p class="sge-mm-card__intro">
				<?php esc_html_e( 'Loaded site-wide AFTER the plugin\'s base styles. The plugin auto-appends `!important` to each declaration on output so your rules beat the plugin\'s built-in selectors without you having to think about specificity. Existing !important declarations are preserved (no doubling).', 'sge-mega-menu' ); ?>
			</p>

			<label class="screen-reader-text" for="sge-mm-custom-css"><?php esc_html_e( 'Custom CSS', 'sge-mega-menu' ); ?></label>
			<textarea
				id="sge-mm-custom-css"
				name="<?php echo esc_attr( $opt_name ); ?>[custom_css]"
				rows="24"
				spellcheck="false"
				autocomplete="off"
			><?php echo esc_textarea( $s['custom_css'] ); ?></textarea>
		</section>

		<aside class="sge-mm-card sge-mm-reference">
			<header class="sge-mm-card__header">
				<h2 class="sge-mm-card__title"><span class="dashicons dashicons-clipboard"></span> <?php esc_html_e( 'Quick reference', 'sge-mega-menu' ); ?></h2>
			</header>
			<p class="sge-mm-card__intro"><?php esc_html_e( 'Click any snippet to copy.', 'sge-mega-menu' ); ?></p>

			<h3><?php esc_html_e( 'Active tab colour', 'sge-mega-menu' ); ?></h3>
<pre class="sge-mm-snippet"><code>.asla-mm .asla-mega__tab.is-active {
  background: #2c7be5;
  color: #fff;
}</code></pre>

			<h3><?php esc_html_e( 'Side-tab pill (active)', 'sge-mega-menu' ); ?></h3>
<pre class="sge-mm-snippet"><code>.asla-mm .asla-mega__sidetab.is-active {
  background: #1a8917;
  color: #fff;
}</code></pre>

			<h3><?php esc_html_e( 'Item hover colour', 'sge-mega-menu' ); ?></h3>
<pre class="sge-mm-snippet"><code>.asla-mm .asla-mega__items > li > a:hover {
  color: #2c7be5;
}</code></pre>

			<h3><?php esc_html_e( 'Panel background', 'sge-mega-menu' ); ?></h3>
<pre class="sge-mm-snippet"><code>.asla-mm .asla-mega__panel {
  background: #fafafa;
}</code></pre>

			<h3><?php esc_html_e( 'Top nav link colour', 'sge-mega-menu' ); ?></h3>
<pre class="sge-mm-snippet"><code>.asla-mm .asla-mega-nav__item > a {
  color: #fff;
}</code></pre>

			<h3><?php esc_html_e( 'Simple dropdown background', 'sge-mega-menu' ); ?></h3>
<pre class="sge-mm-snippet"><code>.asla-mm .asla-mega-nav__dropdown {
  background: #fff;
  border-radius: 8px;
}</code></pre>
		</aside>
	</div>
	<?php
}
