<?php
/**
 * Availability engine — answers the single question "given this date and
 * this number of people, which time slots are bookable?" with a pure static
 * function. No hooks, no side effects, no HTTP — safe to call from the
 * REST endpoint, the admin calendar, CLI tools, or tests.
 *
 * Inputs come straight from the plugin's saved config (opening hours,
 * service duration, slot interval, advance min/max, max online people,
 * blackout dates) plus the booking CPT for the requested date. All
 * time arithmetic happens in minutes-since-midnight to keep the code
 * readable and to sidestep the DST traps that appear the moment you
 * do `DateTime + interval` across a fall-back.
 *
 * Capacity model is capacity-only (see CLAUDE.md): each service period
 * has a total seat count, bookings consume `people` places for the span
 * they occupy (their `time` + `duration_mins`), and a candidate slot is
 * bookable if remaining capacity in its period >= requested people at
 * every instant the slot would occupy.
 *
 * @package Timeslate
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Timeslate_Availability {

	public const STATUS_OPEN            = 'open';
	public const STATUS_CLOSED          = 'closed';
	public const STATUS_BLACKOUT        = 'blackout';
	public const STATUS_PAST            = 'past';
	public const STATUS_TOO_FAR         = 'too_far';
	public const STATUS_TOO_MANY_PEOPLE = 'too_many_people';
	public const STATUS_INVALID_DATE    = 'invalid_date';

	/**
	 * Compute bookable slots for the given date and number of people.
	 *
	 * Returns an associative array:
	 *   - date   : YYYY-MM-DD echoed back
	 *   - people : int echoed back
	 *   - status : self::STATUS_* indicating day-level state
	 *   - slots  : list of { time: HH:MM, places_remaining: int, period: int }
	 *
	 * The array is always returned (never null). When `status` is
	 * anything other than STATUS_OPEN, `slots` is empty. When status is
	 * STATUS_OPEN, `slots` may still be empty if every candidate was
	 * filtered by the advance-min window or existing bookings — callers
	 * should render "no slots available" in that case.
	 */
	public static function slots_for_date( string $date, int $people ): array {
		$result = array(
			'date'   => $date,
			'people'  => $people,
			'status' => self::STATUS_OPEN,
			'slots'  => array(),
		);

		// ---- Validate date ------------------------------------------------
		$date_obj = DateTimeImmutable::createFromFormat( 'Y-m-d', $date );
		if ( ! $date_obj || $date_obj->format( 'Y-m-d' ) !== $date ) {
			$result['status'] = self::STATUS_INVALID_DATE;
			return $result;
		}

		$options = Timeslate_Options::all();

		// ---- People cap ----------------------------------------------------
		if ( $people < 1 || $people > (int) $options['max_people_online'] ) {
			$result['status'] = self::STATUS_TOO_MANY_PEOPLE;
			return $result;
		}

		// ---- Date window (past / too-far) --------------------------------
		// `wp_timezone()` returns the site's tz; we anchor `now` to it so
		// midnight-edge comparisons line up with the owner's clock rather
		// than the server's.
		$tz       = wp_timezone();
		$now      = new DateTimeImmutable( 'now', $tz );
		$today    = $now->setTime( 0, 0, 0 );
		$date_cmp = $date_obj->setTimezone( $tz )->setTime( 0, 0, 0 );

		if ( $date_cmp < $today ) {
			$result['status'] = self::STATUS_PAST;
			return $result;
		}

		$max_date = $today->modify( '+' . (int) $options['advance_max_days'] . ' days' );
		if ( $date_cmp > $max_date ) {
			$result['status'] = self::STATUS_TOO_FAR;
			return $result;
		}

		// ---- Blackouts ----------------------------------------------------
		if ( in_array( $date, (array) $options['blackout_dates'], true ) ) {
			$result['status'] = self::STATUS_BLACKOUT;
			return $result;
		}

		// ---- Opening hours for this day of week --------------------------
		$dow   = (int) $date_obj->format( 'w' ); // 0=Sun … 6=Sat, matches PHP date('w').
		$hours = is_array( $options['opening_hours'][ $dow ] ?? null ) ? $options['opening_hours'][ $dow ] : array();
		if ( empty( $hours['enabled'] ) || empty( $hours['periods'] ) ) {
			$result['status'] = self::STATUS_CLOSED;
			return $result;
		}

		// ---- Candidate slots × existing bookings -------------------------
		$slot_interval  = (int) $options['slot_interval_mins'];
		$service_mins   = (int) $options['service_duration_mins'];
		$min_advance_m  = (int) $options['advance_min_hours'] * 60;

		$bookings = self::normalize_bookings( self::fetch_bookings_for_date( $date ) );

		$slots = array();
		foreach ( (array) $hours['periods'] as $period_idx => $period ) {
			$p_start  = self::time_to_minutes( (string) ( $period['start'] ?? '' ) );
			$p_end    = self::time_to_minutes( (string) ( $period['end'] ?? '' ) );
			$capacity = max( 0, (int) ( $period['capacity'] ?? 0 ) );

			if ( $p_start < 0 || $p_end <= $p_start || $capacity <= 0 ) {
				continue;
			}

			// Last bookable slot start: a slot must fit entirely within its
			// period so bookings don't silently bleed into closed time. If
			// service duration exceeds period length, the period produces
			// no bookable slots.
			$last_start = $p_end - $service_mins;
			if ( $last_start < $p_start ) {
				continue;
			}

			for ( $t = $p_start; $t <= $last_start; $t += $slot_interval ) {
				// Advance-min filter: the slot's start instant must be at
				// least `advance_min_hours` after now.
				$slot_dt   = $date_cmp->setTime( intdiv( $t, 60 ), $t % 60 );
				$delta_min = (int) ( ( $slot_dt->getTimestamp() - $now->getTimestamp() ) / 60 );
				if ( $delta_min < $min_advance_m ) {
					continue;
				}

				$seats_used = self::seats_used_for_slot( $bookings, $t, $service_mins, $p_start, $p_end );
				$remaining  = $capacity - $seats_used;
				if ( $remaining < $people ) {
					continue;
				}

				$slots[] = array(
					'time'            => self::minutes_to_time( $t ),
					'places_remaining' => $remaining,
					'period'          => (int) $period_idx,
				);
			}
		}

		$result['slots'] = $slots;
		return $result;
	}

	// ---- Booking fetch + normalization --------------------------------

	/**
	 * Load every live booking on $date — includes pending and confirmed
	 * (both hold seats) plus completed (for historical / same-day
	 * queries), excludes cancelled + no-show (those released their seats).
	 */
	private static function fetch_bookings_for_date( string $date ): array {
		return get_posts(
			array(
				'post_type'      => Timeslate_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_ts_date',
						'value' => $date,
					),
					array(
						'key'     => '_ts_status',
						'value'   => array( 'pending', 'confirmed', 'completed' ),
						'compare' => 'IN',
					),
				),
			)
		);
	}

	/**
	 * Turn WP_Post objects into { start, duration, people } tuples in the
	 * minutes-since-midnight space the slot loop works in. Bookings with
	 * garbage meta (missing time / sub-minimum duration) are dropped
	 * rather than counted — their seats are effectively treated as
	 * available, which is safer than crashing the picker on bad data.
	 */
	private static function normalize_bookings( array $posts ): array {
		$out = array();
		foreach ( $posts as $post ) {
			$time     = (string) get_post_meta( $post->ID, '_ts_time', true );
			$people    = (int) get_post_meta( $post->ID, '_ts_people', true );
			$duration = (int) get_post_meta( $post->ID, '_ts_duration_mins', true );

			$start = self::time_to_minutes( $time );
			if ( $start < 0 || $people < 1 || $duration < 15 ) {
				continue;
			}
			$out[] = array(
				'start'    => $start,
				'duration' => $duration,
				'people'    => $people,
			);
		}
		return $out;
	}

	/**
	 * Sum number of peoples of bookings that (a) belong to the period we're
	 * evaluating (their start is within [p_start, p_end)) and (b) overlap
	 * the candidate slot's occupation window [slot_start, slot_start + dur).
	 *
	 * Period membership is decided by booking start alone: a booking
	 * started during lunch doesn't count against dinner capacity even if
	 * its duration runs into dinner hours. In practice owners set period
	 * boundaries so this isn't an issue; if duration bleeds across
	 * periods, the capacity-only model can't model it faithfully anyway
	 * (that's what the discrete-tables model we deferred is for).
	 */
	private static function seats_used_for_slot( array $bookings, int $slot_start, int $slot_dur, int $p_start, int $p_end ): int {
		$slot_end = $slot_start + $slot_dur;
		$used     = 0;
		foreach ( $bookings as $b ) {
			if ( $b['start'] < $p_start || $b['start'] >= $p_end ) {
				continue;
			}
			$b_end = $b['start'] + $b['duration'];
			// Strict overlap — endpoint-touching doesn't count as overlap,
			// so a booking that ends at 20:00 doesn't consume seats at
			// the 20:00 slot.
			if ( $b['start'] < $slot_end && $b_end > $slot_start ) {
				$used += $b['people'];
			}
		}
		return $used;
	}

	// ---- Time math ----------------------------------------------------

	private static function time_to_minutes( string $hhmm ): int {
		if ( ! preg_match( '/^(\d{2}):(\d{2})$/', $hhmm, $m ) ) {
			return -1;
		}
		return ( (int) $m[1] ) * 60 + ( (int) $m[2] );
	}

	private static function minutes_to_time( int $mins ): string {
		return sprintf( '%02d:%02d', intdiv( $mins, 60 ), $mins % 60 );
	}
}
