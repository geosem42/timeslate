<?php
/**
 * Options schema — the single source of truth for defaults and
 * sanitization of the plugin's stored config. Mirrors the theme's
 * options schema pattern, so the shape of a setting lives in one place
 * recognize the other.
 *
 * Adding a field: append to fields() with 'type' + 'default' +
 * 'sanitize'. Both defaults() and sanitize() pick it up automatically.
 *
 * Days of the week are stored 0-indexed with 0=Sunday, matching PHP's
 * `date('w')` convention. The settings UI re-orders them for display
 * according to the site's `start_of_week` option.
 *
 * @package Timeslate
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Timeslate_Options_Schema {

	/**
	 * Field definitions. Each entry:
	 *   - type     : data shape hint used by the settings UI to pick a
	 *                control (text, integer, select_int, boolean,
	 *                opening_hours, blackout_dates, notify_emails)
	 *   - default  : returned when the key is unset; also the fallback
	 *                when sanitization rejects input
	 *   - sanitize : rule name; see sanitize_field()
	 *   - min/max  : bounds for int_range
	 *   - choices  : allowed values for select_int
	 */
	public static function fields(): array {
		return array(
			'opening_hours' => array(
				'type'     => 'opening_hours',
				'default'  => self::default_opening_hours(),
				'sanitize' => 'opening_hours',
			),
			'service_duration_mins' => array(
				'type'     => 'integer',
				'default'  => 120,
				'sanitize' => 'int_range',
				'min'      => 15,
				'max'      => 480,
			),
			'slot_interval_mins' => array(
				'type'     => 'select_int',
				'default'  => 30,
				'sanitize' => 'select_int',
				'choices'  => array( 15, 30, 60, 90, 120 ),
			),
			'advance_min_hours' => array(
				'type'     => 'integer',
				'default'  => 2,
				'sanitize' => 'int_range',
				'min'      => 0,
				'max'      => 168,
			),
			'advance_max_days' => array(
				'type'     => 'integer',
				'default'  => 60,
				'sanitize' => 'int_range',
				'min'      => 1,
				'max'      => 365,
			),
			'max_people_online' => array(
				'type'     => 'integer',
				'default'  => 10,
				'sanitize' => 'int_range',
				'min'      => 1,
				'max'      => 100,
			),
			'blackout_dates' => array(
				'type'     => 'blackout_dates',
				'default'  => array(),
				'sanitize' => 'blackout_dates',
			),
			'auto_approve' => array(
				'type'     => 'boolean',
				'default'  => false,
				'sanitize' => 'boolean',
			),
			'notify_emails' => array(
				'type'     => 'notify_emails',
				'default'  => array(),
				'sanitize' => 'notify_emails',
			),
			'email_templates' => array(
				'type'     => 'email_templates',
				'default'  => array(),
				'sanitize' => 'email_templates',
			),
			'uninstall_delete_data' => array(
				'type'     => 'boolean',
				'default'  => false,
				'sanitize' => 'boolean',
			),
		);
	}

	/**
	 * Slug => default value, derived from fields().
	 */
	public static function defaults(): array {
		$fields   = self::fields();
		$defaults = array();
		foreach ( $fields as $key => $field ) {
			$defaults[ $key ] = $field['default'] ?? null;
		}
		return $defaults;
	}

	/**
	 * Sanitize an input payload against the schema. Unknown keys are
	 * dropped; missing keys fall back to the field default — except
	 * booleans, where missing means false (a stock HTML checkbox
	 * doesn't post when unchecked, so absence must equal off, not
	 * "revert to the default").
	 */
	public static function sanitize( array $input ): array {
		$fields = self::fields();
		$output = array();

		foreach ( $fields as $key => $field ) {
			$type = $field['type'] ?? 'string';
			$has  = array_key_exists( $key, $input );

			if ( 'boolean' === $type ) {
				$raw = $has ? (bool) $input[ $key ] : false;
			} elseif ( $has ) {
				$raw = $input[ $key ];
			} else {
				$raw = $field['default'] ?? null;
			}

			$output[ $key ] = self::sanitize_field( $raw, $field );
		}

		return $output;
	}

	private static function sanitize_field( mixed $value, array $field ): mixed {
		$rule = $field['sanitize'] ?? 'text';

		return match ( $rule ) {
			'boolean'        => (bool) $value,
			'text'           => sanitize_text_field( (string) $value ),
			'int_range'      => self::sanitize_int_range( $value, $field ),
			'select_int'     => self::sanitize_select_int( $value, $field ),
			'opening_hours'   => self::sanitize_opening_hours( $value ),
			'blackout_dates'  => self::sanitize_blackout_dates( $value ),
			'notify_emails'   => self::sanitize_notify_emails( $value ),
			'email_templates' => self::sanitize_email_templates( $value ),
			default           => $value,
		};
	}

	private static function sanitize_int_range( mixed $value, array $field ): int {
		$v   = (int) $value;
		$min = (int) ( $field['min'] ?? PHP_INT_MIN );
		$max = (int) ( $field['max'] ?? PHP_INT_MAX );
		return max( $min, min( $max, $v ) );
	}

	private static function sanitize_select_int( mixed $value, array $field ): int {
		$v       = (int) $value;
		$choices = array_map( 'intval', (array) ( $field['choices'] ?? array() ) );
		return in_array( $v, $choices, true ) ? $v : (int) ( $field['default'] ?? 0 );
	}

	/**
	 * Normalize a 7-day opening-hours payload. Each day gets:
	 *   { enabled: bool, periods: [ { start: HH:MM, end: HH:MM, capacity: int } ] }
	 *
	 * Periods with missing / malformed times or start >= end are dropped
	 * silently — the settings UI validates as the owner types so this is
	 * a safety net, not the first line of defense.
	 */
	private static function sanitize_opening_hours( mixed $value ): array {
		$out   = array();
		$input = is_array( $value ) ? $value : array();

		for ( $day = 0; $day < 7; $day++ ) {
			$day_in  = is_array( $input[ $day ] ?? null ) ? $input[ $day ] : array();
			$enabled = ! empty( $day_in['enabled'] );
			$periods = array();

			$raw_periods = is_array( $day_in['periods'] ?? null ) ? $day_in['periods'] : array();
			foreach ( $raw_periods as $p ) {
				if ( ! is_array( $p ) ) {
					continue;
				}
				$start = self::coerce_time( (string) ( $p['start'] ?? '' ) );
				$end   = self::coerce_time( (string) ( $p['end'] ?? '' ) );
				$cap   = max( 0, (int) ( $p['capacity'] ?? 0 ) );
				if ( '' === $start || '' === $end || $start >= $end ) {
					continue;
				}
				$periods[] = array(
					'start'    => $start,
					'end'      => $end,
					'capacity' => $cap,
				);
			}

			$out[ $day ] = array(
				'enabled' => $enabled,
				'periods' => $periods,
			);
		}

		return $out;
	}

	private static function sanitize_blackout_dates( mixed $value ): array {
		$out   = array();
		$input = is_array( $value ) ? $value : array();
		foreach ( $input as $d ) {
			$d = (string) $d;
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) && ! in_array( $d, $out, true ) ) {
				$out[] = $d;
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * Accept either an array (one email per entry) or a newline/comma-
	 * separated string (which is what the settings page textarea posts).
	 * Invalid or duplicate addresses are dropped; order preserved.
	 */
	private static function sanitize_notify_emails( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n,]+/', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();
		foreach ( $value as $email ) {
			$email = sanitize_email( trim( (string) $email ) );
			if ( '' !== $email && is_email( $email ) && ! in_array( $email, $out, true ) ) {
				$out[] = $email;
			}
		}
		return $out;
	}

	/**
	 * Per-email subject/intro/outro overrides. Only known types and keys
	 * are stored; empty strings are dropped so the defaults kick in at
	 * render time. Storage shape:
	 *
	 *   [ 'customer-pending' => [ 'subject' => '...', 'intro' => '...', 'outro' => '...' ], ... ]
	 *
	 * Subject fields are `sanitize_text_field`'d; intro/outro are
	 * textareas (`sanitize_textarea_field`) so buyers can include line
	 * breaks. No HTML is allowed — the template chrome supplies all
	 * markup; buyer copy is escaped + wpautop'd at render time.
	 */
	private static function sanitize_email_templates( mixed $value ): array {
		$input = is_array( $value ) ? $value : array();
		$out   = array();

		$types = array( 'customer-pending', 'customer-confirmed', 'customer-cancelled', 'owner-new' );
		$keys  = array( 'subject', 'intro', 'outro' );

		foreach ( $types as $type ) {
			$entry = is_array( $input[ $type ] ?? null ) ? $input[ $type ] : array();
			foreach ( $keys as $key ) {
				$raw   = (string) ( $entry[ $key ] ?? '' );
				$clean = 'subject' === $key
					? sanitize_text_field( $raw )
					: sanitize_textarea_field( $raw );
				if ( '' !== $clean ) {
					$out[ $type ][ $key ] = $clean;
				}
			}
		}

		return $out;
	}

	private static function coerce_time( string $v ): string {
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $v ) ? $v : '';
	}

	/**
	 * Opening-hours default — all seven days open for one dinner service
	 * (17:00–22:00) with a capacity of 40. Gives a freshly activated
	 * plugin a sensible starting shape; owners trim and tune from there.
	 */
	private static function default_opening_hours(): array {
		$period = array(
			array(
				'start'    => '17:00',
				'end'      => '22:00',
				'capacity' => 40,
			),
		);
		$hours = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$hours[ $i ] = array(
				'enabled' => true,
				'periods' => $period,
			);
		}
		return $hours;
	}
}
