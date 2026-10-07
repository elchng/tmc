<?php
/**
 * Staff access: the "Employee" user role, the staff-only header button
 * ([celectric_claim_button] or automatic menu item), and keeping employees
 * out of wp-admin.
 *
 * Who counts as staff: Employees, plus Editors and Administrators (HR/Finance).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CEL_CLAIM_CAP', 'cel_submit_claims' );

/** Creates the Employee role (once) and gives the claim permission to staff roles. */
add_action( 'init', 'cel_claim_setup_roles', 5 );
function cel_claim_setup_roles() {
	if ( get_option( 'cel_claim_roles_version' ) === CEL_CLAIM_VERSION && get_role( 'employee' ) ) {
		return;
	}
	if ( ! get_role( 'employee' ) ) {
		add_role( 'employee', 'Employee', array( 'read' => true, CEL_CLAIM_CAP => true ) );
	}
	foreach ( array( 'employee', 'editor', 'administrator' ) as $name ) {
		$role = get_role( $name );
		if ( $role && ! $role->has_cap( CEL_CLAIM_CAP ) ) {
			$role->add_cap( CEL_CLAIM_CAP );
		}
	}
	update_option( 'cel_claim_roles_version', CEL_CLAIM_VERSION, false );
}

/** True for logged-in staff (Employee, Editor, Administrator). */
function cel_claim_is_staff( $user_id = null ) {
	$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
	return $user_id && ( user_can( $user_id, CEL_CLAIM_CAP ) || user_can( $user_id, 'edit_others_posts' ) );
}

/** Where the button and the after-login redirect go. */
function cel_claim_page_url() {
	$url = trim( (string) cel_claim_setting( 'claim_page_url' ) );
	if ( '' === $url ) {
		$url = '/claim-form/';
	}
	return 0 === strpos( $url, 'http' ) ? $url : home_url( '/' . ltrim( $url, '/' ) );
}

/* ---------- the button ---------- */

/**
 * [celectric_claim_button text="Claim Form" url="/claim-form/"]
 * Prints nothing for visitors and for logged-in users who are not staff.
 */
add_shortcode( 'celectric_claim_button', 'cel_claim_button_shortcode' );
function cel_claim_button_shortcode( $atts = array() ) {
	if ( ! cel_claim_is_staff() ) {
		return '';
	}
	$atts = shortcode_atts(
		array(
			'text' => cel_claim_setting( 'menu_button_text' ),
			'url'  => '',
		),
		$atts,
		'celectric_claim_button'
	);
	$url = '' !== $atts['url'] ? ( 0 === strpos( $atts['url'], 'http' ) ? $atts['url'] : home_url( '/' . ltrim( $atts['url'], '/' ) ) ) : cel_claim_page_url();
	return '<a class="cel-header-btn" href="' . esc_url( $url ) . '">' . esc_html( $atts['text'] ) . '</a>' . cel_claim_button_css();
}

/** A few lines of CSS for the button, printed once. */
function cel_claim_button_css() {
	static $done = false;
	if ( $done ) {
		return '';
	}
	$done = true;
	return '<style>.cel-header-btn{display:inline-block;padding:8px 18px;border-radius:4px;background:#2F5496;color:#fff!important;font-weight:600;line-height:1.2;text-decoration:none!important;white-space:nowrap}.cel-header-btn:hover{background:#1F4E78}.menu-item.cel-menu-btn>a{background:#2F5496;color:#fff!important;border-radius:4px;padding-left:16px!important;padding-right:16px!important}.menu-item.cel-menu-btn>a:hover{background:#1F4E78}</style>';
}

/**
 * Optional: adds the button to a chosen WordPress menu (Claims → Settings →
 * General), for staff only. Works with theme headers and Elementor's
 * Nav Menu widget alike.
 */
add_filter( 'wp_nav_menu_items', 'cel_claim_menu_button', 20, 2 );
function cel_claim_menu_button( $items, $args ) {
	$menu_id = (int) cel_claim_setting( 'menu_button_menu' );
	if ( ! $menu_id || ! cel_claim_is_staff() ) {
		return $items;
	}
	$menu = wp_get_nav_menu_object( $args->menu ? $args->menu : ( $args->theme_location ? ( get_nav_menu_locations()[ $args->theme_location ] ?? 0 ) : 0 ) );
	if ( ! $menu || (int) $menu->term_id !== $menu_id ) {
		return $items;
	}
	$current = untrailingslashit( strtok( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '?' ) ) === untrailingslashit( (string) wp_parse_url( cel_claim_page_url(), PHP_URL_PATH ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	return $items . '<li class="menu-item cel-menu-btn' . ( $current ? ' current-menu-item' : '' ) . '"><a href="' . esc_url( cel_claim_page_url() ) . '">' . esc_html( cel_claim_setting( 'menu_button_text' ) ) . '</a></li>';
}
add_action( 'wp_head', 'cel_claim_menu_button_css' );
function cel_claim_menu_button_css() {
	if ( (int) cel_claim_setting( 'menu_button_menu' ) && cel_claim_is_staff() ) {
		echo cel_claim_button_css(); // phpcs:ignore WordPress.Security.EscapeOutput -- static CSS.
	}
}

/* ---------- keep employees on the website, not in wp-admin ---------- */

/** After logging in, employees go straight to the claim page. */
add_filter( 'login_redirect', 'cel_claim_login_redirect', 20, 3 );
function cel_claim_login_redirect( $redirect_to, $requested, $user ) {
	if ( $user instanceof WP_User && in_array( 'employee', (array) $user->roles, true ) && ( '' === $requested || false !== strpos( $requested, 'wp-admin' ) ) ) {
		return cel_claim_page_url();
	}
	return $redirect_to;
}

/** No admin toolbar for employees. */
add_filter( 'show_admin_bar', 'cel_claim_hide_admin_bar' );
function cel_claim_hide_admin_bar( $show ) {
	$user = wp_get_current_user();
	return in_array( 'employee', (array) $user->roles, true ) && count( $user->roles ) === 1 ? false : $show;
}

/** Employees opening wp-admin are sent to the claim page (their Profile page, AJAX and the form submit still work). */
add_action( 'admin_init', 'cel_claim_block_admin_for_employees', 1 );
function cel_claim_block_admin_for_employees() {
	if ( wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) || in_array( $GLOBALS['pagenow'] ?? '', array( 'admin-post.php', 'profile.php' ), true ) ) {
		return;
	}
	$user = wp_get_current_user();
	if ( in_array( 'employee', (array) $user->roles, true ) && count( $user->roles ) === 1 && ! current_user_can( 'edit_posts' ) ) {
		wp_safe_redirect( cel_claim_page_url() );
		exit;
	}
}
