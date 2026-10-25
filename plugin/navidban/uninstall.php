<?php
/**
 * Uninstall routine.
 *
 * Data is preserved by default. Deleting the plugin must never silently destroy
 * a shop's customer list; the site owner has to switch on
 * «حذف داده هنگام حذف افزونه» explicitly.
 *
 * @package Navidban
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove one site's data.
 *
 * @return void
 */
function navidban_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'navidban_settings', array() );

	if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
		// Nothing to delete: the owner kept the default.
		return;
	}

	$prefix = $wpdb->prefix;
	$tables = array(
		$prefix . 'nvb_waitlists',
		$prefix . 'nvb_stock_snapshots',
		$prefix . 'nvb_queue',
		$prefix . 'nvb_logs',
	);

	foreach ( $tables as $table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '', $table ) . '`' );
	}

	// Options.
	$options = array(
		'navidban_settings',
		'navidban_version',
		'navidban_db_version',
		'navidban_queue_lock',
		'navidban_last_reconcile',
		'navidban_snapshot_seeded',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Transients.
	delete_transient( 'navidban_activated' );
	delete_transient( 'navidban_tool_notice' );

	// Capabilities.
	$roles = wp_roles();
	if ( $roles && ! empty( $roles->roles ) ) {
		foreach ( array_keys( $roles->roles ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			$role->remove_cap( 'navidban_manage' );
			$role->remove_cap( 'navidban_view_reports' );
		}
	}

	// Scheduled events.
	wp_clear_scheduled_hook( 'navidban_process_queue' );
	wp_clear_scheduled_hook( 'navidban_reconcile_stock' );
	wp_clear_scheduled_hook( 'navidban_prune_records' );
}

if ( is_multisite() ) {
	$navidban_sites = get_sites( array( 'fields' => 'ids' ) );

	foreach ( $navidban_sites as $navidban_site_id ) {
		switch_to_blog( (int) $navidban_site_id );
		navidban_uninstall_site();
		restore_current_blog();
	}
} else {
	navidban_uninstall_site();
}
