<?php
/**
 * Bookings CPT + meta registration.
 *
 * One post = one booking. Post title holds the customer's name so
 * the admin list is scannable. Date / time / people / status are stored
 * as post meta with `_ts_` prefixes; the leading underscore hides them
 * from the classic custom-fields box so the dedicated booking-details
 * admin UI owns the edit surface.
 *
 * @package Timeslate
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Timeslate_CPT {

	public const POST_TYPE = 'timeslate_booking';

	/**
	 * All allowed booking status values. Used by the meta sanitizer
	 * and by the admin UI's status-change dropdown.
	 *
	 *   pending    — submitted, awaiting owner review (or auto-confirmed
	 *                when auto_approve is on)
	 *   confirmed  — owner approved; email sent to customer
	 *   completed  — the booking was honoured
	 *   cancelled  — customer or owner cancelled before service
	 *   no_show    — confirmed booking that never arrived
	 */
	public const STATUSES = array(
		'pending',
		'confirmed',
		'completed',
		'cancelled',
		'no_show',
	);

	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		// Run meta registration on a later priority so register_post_type
		// has already created the post type's REST controller.
		add_action( 'init', array( __CLASS__, 'register_meta' ), 11 );
	}

	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'                  => __( 'Bookings', 'timeslate' ),
					'singular_name'         => __( 'Booking', 'timeslate' ),
					'menu_name'             => __( 'Bookings', 'timeslate' ),
					'add_new'               => __( 'Add New', 'timeslate' ),
					'add_new_item'          => __( 'Add New Booking', 'timeslate' ),
					'edit_item'             => __( 'Edit Booking', 'timeslate' ),
					'new_item'              => __( 'New Booking', 'timeslate' ),
					'view_item'             => __( 'View Booking', 'timeslate' ),
					'search_items'          => __( 'Search Bookings', 'timeslate' ),
					'not_found'             => __( 'No bookings found.', 'timeslate' ),
					'not_found_in_trash'    => __( 'No bookings found in Trash.', 'timeslate' ),
					'all_items'             => __( 'All Bookings', 'timeslate' ),
					'filter_items_list'     => __( 'Filter bookings list', 'timeslate' ),
					'items_list'            => __( 'Bookings list', 'timeslate' ),
					'items_list_navigation' => __( 'Bookings list navigation', 'timeslate' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => true,
				'show_in_nav_menus'   => false,
				'menu_position'       => 22,
				'menu_icon'           => 'dashicons-calendar-alt',
				'hierarchical'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	public static function register_meta(): void {
		$auth_edit = static fn(): bool => current_user_can( 'edit_posts' );

		$keys = array(
			'_ts_date'           => 'string',
			'_ts_time'           => 'string',
			'_ts_people'          => 'integer',
			'_ts_duration_mins'  => 'integer',
			'_ts_name'           => 'string',
			'_ts_email'          => 'string',
			'_ts_phone'          => 'string',
			'_ts_notes'          => 'string',
			'_ts_status'         => 'string',
			'_ts_token'          => 'string',
			'_ts_ip'             => 'string',
		);

		foreach ( $keys as $key => $type ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'type'              => $type,
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => self::sanitize_callback_for( $key ),
					'auth_callback'     => $auth_edit,
				)
			);
		}
	}

	/**
	 * Per-key sanitizer. Centralized here so storage shape stays a single
	 * source of truth — callers may re-validate at their own boundary,
	 * but everything written via update_post_meta also falls through
	 * these rules.
	 */
	private static function sanitize_callback_for( string $key ): callable {
		return match ( $key ) {
			'_ts_date'          => static fn( $v ): string => self::sanitize_date( (string) $v ),
			'_ts_time'          => static fn( $v ): string => self::sanitize_time( (string) $v ),
			'_ts_people'         => static fn( $v ): int    => max( 1, (int) $v ),
			'_ts_duration_mins' => static fn( $v ): int    => max( 15, (int) $v ),
			'_ts_email'         => static fn( $v ): string => sanitize_email( (string) $v ),
			'_ts_phone'         => static fn( $v ): string => sanitize_text_field( (string) $v ),
			'_ts_notes'         => static fn( $v ): string => sanitize_textarea_field( (string) $v ),
			'_ts_status'        => static fn( $v ): string => in_array( (string) $v, self::STATUSES, true ) ? (string) $v : 'pending',
			'_ts_token'         => static fn( $v ): string => (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) $v ),
			'_ts_ip'            => static fn( $v ): string => sanitize_text_field( (string) $v ),
			default             => 'sanitize_text_field',
		};
	}

	private static function sanitize_date( string $v ): string {
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
	}

	private static function sanitize_time( string $v ): string {
		return preg_match( '/^\d{2}:\d{2}$/', $v ) ? $v : '';
	}
}
