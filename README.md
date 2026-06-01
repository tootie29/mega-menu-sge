# SGE Mega Menu

A portable WordPress mega-menu engine. Renders any WP nav menu as a hover-driven mega panel with auto-detected 3-level or 4-level layouts, simple dropdowns, and plain links — driven entirely by your menu hierarchy in **Appearance → Menus**. No site-specific code; drop it into any theme.

## Features

- **Auto-detected layouts** per top-level menu item, based on hierarchy depth and a single `has-mega` CSS class:
  - **4-level mega** — top → horizontal tabs → side-tab rail → 2-column item list (e.g. a Services panel)
  - **3-level mega** — top → side-tab rail → 2-column item list (no tab row)
  - **Simple dropdown** — top → flat list of children (e.g. About Us with sub-pages)
  - **Plain link** — top item with no children
- **Mobile drawer-safe**: skips `wp_nav_menu()` calls that look like drawers (`menu_class=metismenu` or `container_id=side-menu`) so existing mobile patterns keep working.
- **Site-wide auto-replace** (optional): intercept the theme's existing `wp_nav_menu()` calls (or `[listmenu]` shortcode) for chosen menu names / theme locations and swap the output to the mega menu — without modifying theme files.
- **`[sge_mega_menu]` shortcode** with `location` / `menu` / `menu_id` arguments for drop-in placement.
- **Admin settings page** (Top-level menu in WP Admin):
  - **General tab** — master enable/disable toggle, default mega menu source, auto-replace menu/location picker, shortcode reference
  - **Styles tab** — CodeMirror CSS editor with syntax highlighting, line numbers, auto-indent, and a click-to-copy reference panel of common selectors
- **Duplicate menu** action — clone any WP nav menu (items + parent/child hierarchy) with one click. Surfaces in two places: a `Duplicate` button next to *Save Menu* / *Delete Menu* on **Appearance → Menus**, and a per-menu `Duplicate` button on **SGE Mega Menu → General**. The new menu is auto-named `<Original> (Copy)` (suffixed `(Copy 2)`, `(Copy 3)`… on collision) and starts unassigned to any theme location.
- **Duplicate menu item branch** — expand any item in **Appearance → Menus** and click `Duplicate item (with descendants)` to clone that single item plus every child, grandchild, and deeper descendant beneath it. The branch root is inserted as a top-level item in the same menu by default; use the programmatic API to nest it under a different parent or push it into another menu entirely.
- **Custom CSS** entered in the Styles tab is auto-marked `!important` on output so user overrides always win the cascade — no need to think about specificity. Existing `!important` declarations are preserved (no doubling).
- **Self-contained**: when auto-replace is OFF and no shortcode is on the page, **nothing** from the plugin loads on the front-end. Custom CSS only ships when the menu CSS itself ships.

## Installation

1. Copy this `sge-mega-menu/` folder into `wp-content/plugins/`.
2. Activate **SGE Mega Menu** in WP Admin → Plugins.
3. In WP Admin → Appearance → Menus → Manage Locations, assign a menu to the new **"ASLA Mega Menu (desktop)"** location (slug: `asla_mega`).
4. Configure auto-replace and/or use the shortcode (see below).

## Menu structure conventions

The plugin reads a standard WP nav menu and infers the layout from these rules:

| Top item has… | Renders as |
|---|---|
| `has-mega` CSS class **and** grandchildren-of-children | **4-level mega panel** (tabs row → side-tab rail → item list) |
| `has-mega` CSS class **and** children only | **3-level mega panel** (side-tab rail → item list, no tab row) |
| children but no `has-mega` class | **Simple dropdown** |
| no children | **Plain link** |

To mark a top item as a mega panel, edit it in WP Admin → Appearance → Menus → expand the item → set **CSS Classes** to `has-mega`. (If the CSS Classes field isn't visible, enable it under **Screen Options** at the top.)

Inside a 3- or 4-level mega panel:
- **Side-tabs with NO children render as label headings** (not clickable pills). Useful for "Overview" rows in the Figma reference layout.
- The first side-tab WITH children is the default-active state on hover.
- Side-tabs intended to be labels (no children) should be **custom links with URL `#`** rather than linked to pages — otherwise WPML and some other plugins will rewrite the displayed title to match the linked page.

## Usage

### Shortcode

```text
[sge_mega_menu]                           — render the menu at the default source (configured in admin)
[sge_mega_menu location="primary"]        — render the menu at a specific theme location
[sge_mega_menu menu="My Menu Name"]       — render a specific menu by name
[sge_mega_menu menu_id="42"]              — render by direct term_id
```

### Direct PHP call (in theme templates)

```php
<?php if ( function_exists( 'asla_render_mega_menu' ) ) : ?>
  <div class="asla-mm">
    <?php asla_render_mega_menu( array( 'location' => 'asla_mega' ) ); ?>
  </div>
<?php endif; ?>
```

The wrapping `.asla-mm` class is REQUIRED — all of the plugin's CSS is scoped under that class for isolation from the rest of the theme. You can put `.asla-mm` on `<body>`, the `<header>`, or any direct ancestor of where the menu renders.

When using the shortcode, the plugin auto-adds `.asla-mm` to `<body>` via the `body_class` filter (only when auto-replace is enabled). For direct theme calls, add it yourself.

### Auto-replace (no theme edits)

In WP Admin → SGE Mega Menu → General:

1. Toggle **Enable auto-replace** ON.
2. Pick the **default mega menu source** (a location or a specific menu).
3. Check the menus and/or theme locations the plugin should intercept.

Anywhere the theme calls `wp_nav_menu()` (or the `[listmenu]` shortcode, or anything else wrapping `wp_nav_menu`) for one of the selected menus, the output is replaced with the mega menu. The plugin auto-adds `.asla-mm` to `<body>` and enqueues its CSS/JS site-wide while auto-replace is on.

**Per-call escape hatch**: pass `bypass_sge_mm => true` in any `wp_nav_menu()` args to force the original output for that specific call.

## Developer filters

```php
// Add to the list of menus to auto-replace (in addition to the admin settings).
add_filter( 'sge_mm_replace_menus', function ( $menus ) {
    $menus[] = 'My_Other_Menu';
    return $menus;
} );

// Same for theme locations.
add_filter( 'sge_mm_replace_locations', function ( $locs ) {
    $locs[] = 'primary';
    return $locs;
} );
```

## Developer API — programmatic duplication

```php
// Duplicate item 123 (and all descendants) inside the same menu, as a top-level branch:
$result = sge_mm_duplicate_menu_item_branch( $source_menu_id = 47, $source_item_id = 123 );

// Duplicate item 123 from menu 47 into menu 52 as a top-level branch:
$result = sge_mm_duplicate_menu_item_branch( 47, 123, $target_menu_id = 52 );

// Duplicate item 123 from menu 47 into menu 52, nested under item 900 in menu 52:
$result = sge_mm_duplicate_menu_item_branch( 47, 123, 52, $target_parent_item_id = 900 );

// $result is either:
//   array{ menu_id: int, new_root_id: int, items_copied: int, items_in_branch: int }
// or a WP_Error on failure.
```

## File structure

```
sge-mega-menu/
├── sge-mega-menu.php       # Plugin header, settings access, nav location, asset register, auto-replace, shortcode, custom CSS auto-!important
├── includes/
│   ├── renderer.php        # asla_render_mega_menu() + helpers (4-level / 3-level / dropdown / plain link)
│   ├── admin.php           # Top-level admin page, two tabs, Settings API, CodeMirror integration
│   └── duplicate.php       # Duplicate-menu handler (admin-post action) + nav-menus.php / plugin-admin link injection
├── assets/
│   ├── mega-menu.css       # All scoped under .asla-mm; @media (min-width: 992px) for desktop
│   ├── mega-menu.js        # ASLAMega hover/tab/side-tab interaction (vanilla JS, no jQuery)
│   └── admin.css           # Admin settings page chrome (banner, cards, toggle switch, etc.)
├── .gitignore
└── README.md
```

## Browser & PHP support

- **PHP**: 7.4+ (uses null-coalesce, typed arrays defensively).
- **Browsers**: any browser that supports CSS flexbox + custom properties + `clamp()` (Chrome 79+, Safari 13.1+, Firefox 75+, Edge 79+). The mega menu is **desktop-only** at `min-width: 992px`. Below that, the plugin renders nothing and the theme's existing mobile experience takes over.

## License

GPL-2.0-or-later (matches WordPress core).
