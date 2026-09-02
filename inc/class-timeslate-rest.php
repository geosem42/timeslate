<?php
/**
 * REST controller — public availability lookup and booking creation.
 *
 * Both routes are public (no auth) because customers book without
 * logging in. Abuse mitigation:
 *
 *   - Honeypot field (`website`): should always be empty on a real
 *     submission; bots fill it out. Non-empty → reject with 400.
 *   - Soft rate limit: 5 POSTs per 10 minutes per IP, enforced via a
 *     transient. Window extends on each hit, so abusive clients stay
 *     locked out until the 10-minute streak lapses.
 *   - Server-side re-validation: every POST re-runs the availability
 *     engine and rejects with 409 if the requested slot no longer
 *     fits. Prevents race conditions where two customers booked the
 *     same seats simultaneously.
 *
 * Namespace: timeslate/v1.
 *
 * @package Timeslate
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Timeslate_REST {

	public const NAMESPACE_PATH = 'timeslate/v1';

	/**
	 * Rate-limit configuration — 5 POST /bookings per 10 minutes per IP.
	 */
	private const RATE_LIMIT_MAX    = 5;
	private const RATE_LIMIT_WINDOW = 600;

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_PATH,
			'/availability',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_availability' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'date'  => array(
						'type'     => 'string',
						'required' => true,
					),
					'people' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_PATH,
			'/bookings',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_create' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * GET /availability — thin wrapper around the availability engine.
	 * No sanitization beyond what the engine already does, since the
	 * engine validates date format / people range internally.
	 */
	public static function handle_availability( WP_REST_Request $request ): WP_REST_Response {
		$date  = (string) $request->get_param( 'date' );
		$people = (int) $request->get_param( 'people' );

		$data = Timeslate_Availability::slots_for_date( $date, $people );
		return new WP_REST_Response( $data );
	}

	/**
	 * POST /bookings — validate, re-check availability, persist.
	 *
	 * Returns 201 + { id, status, token, message } on success, or a
	 * WP_Error with an appropriate status code:
	 *   400 — invalid input (bad date/time/people/name/email/phone)
	 *   409 — slot no longer available (raced with another booking)
	 *   429 — rate-limited
	 *   500 — insert failed
	 */
	public static function handle_create( WP_REST_Request $request ) {
		// Honeypot — bots fill this, humans don't.
		$honeypot = trim( (string) $request->get_param( 'website' ) );
		if ( '' !== $honeypot ) {
			return new WP_Error(
				'timeslate_invalid',
				__( 'Invalid submission.', 'timeslate' ),
				array( 'status' => 400 )
			);
		}

		// Rate limit by IP.
		$ip    = self::client_ip();
		$limit = self::check_rate_limit( $ip );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}

		// ---- Validate inputs ----
		$date  = (string) $request->get_param( 'date' );
		$time  = (string) $request->get_param( 'time' );
		$people = (int) $request->get_param( 'people' );
		$name  = trim( sanitize_text_field( (string) $request->get_param( 'name' ) ) );
		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		$phone = trim( sanitize_text_field( (string) $request->get_param( 'phone' ) ) );
		$notes = trim( sanitize_textarea_field( (string) $request->get_param( 'notes' ) ) );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return self::bad_request( 'timeslate_invalid_date', __( 'Invalid date.', 'timeslate' ) );
		}
		if ( ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			return self::bad_request( 'timeslate_invalid_time', __( 'Invalid time.', 'timeslate' ) );
		}
		if ( $people < 1 ) {
			return self::bad_request( 'timeslate_invalid_people', __( 'Please choose at least one person.', 'timeslate' ) );
		}
		if ( '' === $name || mb_strlen( $name ) > 100 ) {
			return self::bad_request( 'timeslate_invalid_name', __( 'Please enter a valid name.', 'timeslate' ) );
		}
		if ( '' === $email || ! is_email( $email ) ) {
			return self::bad_request( 'timeslate_invalid_email', __( 'Please enter a valid email address.', 'timeslate' ) );
		}
		if ( '' === $phone ) {
			return self::bad_request( 'timeslate_invalid_phone', __( 'Please enter a phone number.', 'timeslate' ) );
		}
		if ( mb_strlen( $notes ) > 500 ) {
			return self::bad_request( 'timeslate_invalid_notes', __( 'Notes must be under 500 characters.', 'timeslate' ) );
		}

		// ---- Re-check availability ----
		// Even though the form queried /availability before submit, the
		// world may have changed since — another customer could have
		// taken the last seats in the interim. Re-run the engine at
		// submit time to catch races.
		$availability = Timeslate_Availability::slots_for_date( $date, $people );
		if ( Timeslate_Availability::STATUS_OPEN !== $availability['status'] ) {
			return self::conflict( self::availability_message( $availability['status'] ) );
		}

		$slot_ok = false;
		foreach ( $availability['slots'] as $slot ) {
			if ( (string) $slot['time'] === $time ) {
				$slot_ok = true;
				break;
			}
		}
		if ( ! $slot_ok ) {
			return self::conflict( __( 'That time slot is no longer available.', 'timeslate' ) );
		}

		// ---- Persist ----
		$options      = Timeslate_Options::all();
		$duration     = (int) $options['service_duration_mins'];
		$auto_approve = ! empty( $options['auto_approve'] );
		$status       = $auto_approve ? 'confirmed' : 'pending';
		$token        = wp_generate_password( 32, false );

		$post_id = wp_insert_post(
			array(
				'post_type'   => Timeslate_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $name,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error(
				'timeslate_create_failed',
				__( 'Could not save the booking. Please try again.', 'timeslate' ),
				array( 'status' => 500 )
			);
		}

		update_post_meta( $post_id, '_ts_date', $date );
		update_post_meta( $post_id, '_ts_time', $time );
		update_post_meta( $post_id, '_ts_people', $people );
		update_post_meta( $post_id, '_ts_duration_mins', $duration );
		update_post_meta( $post_id, '_ts_name', $name );
		update_post_meta( $post_id, '_ts_email', $email );
		update_post_meta( $post_id, '_ts_phone', $phone );
		update_post_meta( $post_id, '_ts_notes', $notes );
		update_post_meta( $post_id, '_ts_status', $status );
		update_post_meta( $post_id, '_ts_token', $token );
		update_post_meta( $post_id, '_ts_ip', $ip );

		// Fire confirmation + owner-notification emails. Failures from
		// wp_mail don't block the HTTP response — the booking is already
		// saved and an owner can resend by re-triggering the transition
		// from the admin.
		Timeslate_Emails::send_on_created( (int) $post_id );

		$message = $auto_approve
			? __( 'Your booking is confirmed.', 'timeslate' )
			: __( "Thanks. We have received your request and will confirm by email shortly.", 'timeslate' );

		return new WP_REST_Response(
			array(
				'id'      => (int) $post_id,
				'status'  => $status,
				'token'   => $token,
				'message' => $message,
			),
			201
		);
	}

	// ---- Helpers ----

	private static function client_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '0.0.0.0';
	}

	private static function check_rate_limit( string $ip ) {
		$key   = 'ts_rl_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT_MAX ) {
			return new WP_Error(
				'timeslate_rate_limited',
				__( 'Too many attempts. Please try again in a few minutes.', 'timeslate' ),
				array( 'status' => 429 )
			);
		}
		set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );
		return true;
	}

	private static function bad_request( string $code, string $message ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => 400 ) );
	}

	private static function conflict( string $message ): WP_Error {
		return new WP_Error( 'timeslate_unavailable', $message, array( 'status' => 409 ) );
	}

	/**
	 * Translate an availability status code into a user-facing message
	 * for the POST path. The customer-facing copy is intentionally
	 * softer than the internal status enum.
	 */
	private static function availability_message( string $status ): string {
		switch ( $status ) {
			case Timeslate_Availability::STATUS_CLOSED:
				return __( 'We are closed on that day.', 'timeslate' );
			case Timeslate_Availability::STATUS_BLACKOUT:
				return __( 'Bookings are not accepted on that date.', 'timeslate' );
			case Timeslate_Availability::STATUS_PAST:
				return __( 'Please choose a future date.', 'timeslate' );
			case Timeslate_Availability::STATUS_TOO_FAR:
				return __( 'That date is beyond the booking window.', 'timeslate' );
			case Timeslate_Availability::STATUS_TOO_MANY_PEOPLE:
				return __( 'For parties that large, please call us directly.', 'timeslate' );
			case Timeslate_Availability::STATUS_INVALID_DATE:
				return __( 'Invalid date.', 'timeslate' );
			default:
				return __( 'That slot is no longer available.', 'timeslate' );
		}
	}
}
