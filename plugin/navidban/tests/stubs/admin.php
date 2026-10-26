<?php
/**
 * Minimal WordPress admin stand-ins for the render smoke test.
 *
 * Only what the plugin's admin screens actually call. These are deliberately
 * dumb: they echo predictable markup so the test can assert "the view produced
 * output and no PHP notice", not "WordPress looks like X".
 *
 * @package Navidban
 */

// phpcs:disable

/**
 * @param string $group Group.
 */
function settings_fields( $group ) {
	echo '<input type="hidden" name="option_page" value="' . htmlspecialchars( (string) $group, ENT_QUOTES ) . '" />';
}

/**
 * @param string $setting Setting slug.
 */
function settings_errors( $setting = '' ) {
	echo '';
}

/**
 * @param string $setting Setting slug.
 */
function add_settings_error( $setting, $code, $message, $type = 'error' ) {
	$GLOBALS['nvb_settings_errors'][] = array( 'code' => $code, 'message' => $message, 'type' => $type );
}

/**
 * @param string $group    Group.
 * @param string $name     Option name.
 * @param array  $args     Args.
 */
function register_setting( $group, $name, $args = array() ) {
	$GLOBALS['nvb_registered_settings'][ $name ] = array( 'group' => $group, 'args' => $args );
}

/**
 * @param string $text Text.
 * @param string $type Type.
 * @param string $name Name.
 * @param bool   $wrap Wrap.
 */
function submit_button( $text = 'Save Changes', $type = 'primary', $name = 'submit', $wrap = true ) {
	// Mirrors core: the class comes from $type, and $wrap controls the
	// <p class="submit"> wrapper. The views rely on wrap=false so the button can
	// live inside their own action bar.
	$class = 'button';
	if ( 'primary' === $type ) {
		$class .= ' button-primary';
	}
	if ( 'delete' === $type ) {
		$class .= ' button-secondary button-link-delete';
	}

	$button = sprintf(
		'<input type="submit" name="%s" id="%s" class="%s" value="%s" />',
		htmlspecialchars( (string) $name, ENT_QUOTES ),
		htmlspecialchars( (string) $name, ENT_QUOTES ),
		esc_attr( $class ),
		htmlspecialchars( (string) $text, ENT_QUOTES )
	);

	echo $wrap ? '<p class="submit">' . $button . '</p>' : $button;
}

/**
 * @param string $text Text.
 * @param string $name Field name.
 */
function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
	$field = '<input type="hidden" name="' . htmlspecialchars( (string) $name, ENT_QUOTES ) . '" value="stub-nonce" />';
	if ( $display ) {
		echo $field;
	}
	return $field;
}

/**
 * @param string $actionurl Action URL.
 * @param string $action    Action.
 * @param string $name      Name.
 * @return string
 */
function wp_nonce_url( $actionurl, $action = -1, $name = '_wpnonce' ) {
	return $actionurl . '&' . $name . '=stub-nonce';
}

/**
 * @param mixed $checked  Value.
 * @param mixed $current  Current.
 * @param bool  $display  Echo.
 * @return string
 */
function checked( $checked, $current = true, $display = true ) {
	$result = (string) $checked === (string) $current ? ' checked="checked"' : '';
	if ( $display ) {
		echo $result;
	}
	return $result;
}

/**
 * @param mixed $selected Value.
 * @param mixed $current  Current.
 * @param bool  $display  Echo.
 * @return string
 */
function selected( $selected, $current = true, $display = true ) {
	$result = (string) $selected === (string) $current ? ' selected="selected"' : '';
	if ( $display ) {
		echo $result;
	}
	return $result;
}

/**
 * @param array $args Args.
 * @return string
 */
function paginate_links( $args = array() ) {
	return '<span class="tablenav-pages">۱ ۲ ۳</span>';
}

/**
 * @return object
 */
function get_current_screen() {
	return (object) array( 'id' => 'toplevel_page_navidban', 'base' => 'toplevel_page_navidban' );
}

/**
 * @param mixed $value Value.
 * @return int
 */
function absint( $value ) {
	return abs( (int) $value );
}

/**
 * @param float $number Number.
 * @return string
 */
function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, (int) $decimals );
}

/**
 * @param string $title    Title.
 * @param string $cap      Capability.
 * @param string $slug     Slug.
 * @param callable $callback Callback.
 * @param string $icon     Icon.
 * @param int    $position Position.
 * @return string
 */
function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null ) {
	$GLOBALS['nvb_admin_menu'][] = array( 'type' => 'menu', 'slug' => $menu_slug, 'title' => $menu_title, 'cap' => $capability );
	return 'toplevel_page_' . $menu_slug;
}

/**
 * @param string $parent   Parent slug.
 * @param string $page     Page title.
 * @param string $menu     Menu title.
 * @param string $cap      Capability.
 * @param string $slug     Slug.
 * @param callable $callback Callback.
 * @return string
 */
function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '' ) {
	$GLOBALS['nvb_admin_menu'][] = array( 'type' => 'submenu', 'parent' => $parent_slug, 'slug' => $menu_slug, 'title' => $menu_title, 'cap' => $capability );
	return $parent_slug . '_page_' . $menu_slug;
}

/**
 * @param string $hook Hook.
 * @return string
 */
function plugin_basename_stub( $hook ) {
	return $hook;
}

/**
 * @param string $content Content.
 * @return string
 */
function wp_kses_post( $content ) {
	return strip_tags( (string) $content, '<a><strong><em><br><span><code>' );
}
