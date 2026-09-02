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
 *   • every `timeslate_booking` post (bypassing trash)
 *   • every `_ts_*` post meta row (covered by the post delete, but we
 *     issue a direct DELETE for any orphans too)
 *   • the `timeslate_options` option
 *   • every `sb_rl_*` rate-limit transient
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
	$post_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
			'timeslate_booking'
		)
	);
	foreach ( (array) $post_ids as $post_id ) {
		wp_delete_post( (int) $post_id, true );
	}

	// Sweep any orphaned _ts_* meta rows. Post-delete should have taken
	// them, but a stale row here would leak silently.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( '_ts_' ) . '%'
		)
	);

	// Drop the plugin's options row.
	delete_option( 'timeslate_options' );

	// Clean up rate-limit transients (one per unique IP that booked).
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_ts_rl_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_ts_rl_' ) . '%'
		)
	);
}

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields'                 => 'ids',
			'number'                 => 0,
			'update_site_cache'      => false,
			'update_site_meta_cache' => false,
		)
	);
	foreach ( (array) $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		timeslate_uninstall_site();
		restore_current_blog();
	}
} else {
	timeslate_uninstall_site();
}
