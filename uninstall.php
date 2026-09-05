<?php
/**
 * Uninstall handler — runs when a site admin deletes the plugin from
 * the Plugins screen (not on plain deactivation).
 *
 * Default behavior: preserve everything (bookings, settings, post meta)
 * so re-installing the plugin picks up where the site left off. Opt in
 * to destructive cleanup via the `uninstall_delete_data` flag under
 * Bookings → Settings → Advanced; when that flag is on, we drop:
 *
 *   • every `timeslate_booking` post (bypassing trash), which takes
 *     its meta with it
 *   • the `timeslate_options` option
 *   • every `timeslate_rl_*` rate-limit transient
 *
 * Multisite: each site in the network is purged individually so a
 * network-uninstall handles per-site data. We do NOT touch users,
 * capabilities, or anything registered globally — those are owned by
 * core.
 *
 * @package Timeslate
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Run the per-site cleanup. Called once on single-site installs and
 * once per site on multisite networks.
 */
function timeslate_uninstall_site(): void {
	$options     = get_option( 'timeslate_options', array() );
	$delete_data = is_array( $options ) && ! empty( $options['uninstall_delete_data'] );

	if ( ! $delete_data ) {
		return;
	}

	global $wpdb;

	// Delete every booking post, bypassing trash.
	$post_ids = get_posts(
		array(
			'post_type'      => 'timeslate_booking',
			'post_status'    => array_keys( get_post_stati() ),
			'numberposts'    => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);
	foreach ( (array) $post_ids as $post_id ) {
		wp_delete_post( (int) $post_id, true );
	}

	// Drop the plugin's options row.
	delete_option( 'timeslate_options' );

	// Clean up rate-limit transients (one per unique IP that booked).
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_timeslate_rl_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_timeslate_rl_' ) . '%'
		)
	);
}

if ( is_multisite() ) {
	$timeslate_site_ids = get_sites(
		array(
			'fields'                 => 'ids',
			'number'                 => 0,
			'update_site_cache'      => false,
			'update_site_meta_cache' => false,
		)
	);
	foreach ( (array) $timeslate_site_ids as $timeslate_site_id ) {
		switch_to_blog( (int) $timeslate_site_id );
		timeslate_uninstall_site();
		restore_current_blog();
	}
} else {
	timeslate_uninstall_site();
}
