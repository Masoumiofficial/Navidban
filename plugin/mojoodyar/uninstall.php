<?php
/**
 * Uninstall routine.
 *
 * Data is preserved by default. Deleting the plugin must never silently destroy
 * a shop's customer list; the site owner has to switch on
 * «حذف داده هنگام حذف افزونه» explicitly.
 *
 * @package Mojoodyar
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove one site's data.
 *
 * @return void
 */
function mojoodyar_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'mojoodyar_settings', array() );

	if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
		// Nothing to delete: the owner kept the default.
		return;
	}

	$prefix = $wpdb->prefix;
	$tables = array(
		$prefix . 'mjy_waitlists',
		$prefix . 'mjy_stock_snapshots',
		$prefix . 'mjy_queue',
		$prefix . 'mjy_logs',
		$prefix . 'mjy_call_log',
	);

	foreach ( $tables as $table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '', $table ) . '`' );
	}

	// Options.
	$options = array(
		'mojoodyar_settings',
		'mojoodyar_version',
		'mojoodyar_db_version',
		'mojoodyar_queue_lock',
		'mojoodyar_last_reconcile',
		'mojoodyar_snapshot_seeded',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Transients.
	delete_transient( 'mojoodyar_activated' );
	delete_transient( 'mojoodyar_tool_notice' );

	// Capabilities.
	$roles = wp_roles();
	if ( $roles && ! empty( $roles->roles ) ) {
		foreach ( array_keys( $roles->roles ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			$role->remove_cap( 'mojoodyar_manage' );
			$role->remove_cap( 'mojoodyar_view_reports' );
		}
	}

	// Scheduled events.
	wp_clear_scheduled_hook( 'mojoodyar_process_queue' );
	wp_clear_scheduled_hook( 'mojoodyar_reconcile_stock' );
	wp_clear_scheduled_hook( 'mojoodyar_prune_records' );
}

if ( is_multisite() ) {
	$mojoodyar_sites = get_sites( array( 'fields' => 'ids' ) );

	foreach ( $mojoodyar_sites as $mojoodyar_site_id ) {
		switch_to_blog( (int) $mojoodyar_site_id );
		mojoodyar_uninstall_site();
		restore_current_blog();
	}
} else {
	mojoodyar_uninstall_site();
}
