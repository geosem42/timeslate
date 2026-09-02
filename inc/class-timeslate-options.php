<?php
/**
 * Options wrapper — single read/write surface for plugin config.
 *
 * Storage: one serialized WP option `timeslate_options`. Merges
 * schema defaults on read so partial saves remain safe. Cached per
 * request; call flush_cache() only from tests.
 *
 * Templates and handlers MUST go through this class instead of
 * get_option() directly — keeps defaults-merge and caching in one place.
 *
 * @package Timeslate
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Timeslate_Options {

	private const OPTION_KEY = 'timeslate_options';

	private static ?array $cache = null;

	/**
	 * Full options array, defaults merged with stored values.
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION_KEY, array() );
			$defaults    = Timeslate_Options_Schema::defaults();
			self::$cache = array_merge( $defaults, is_array( $stored ) ? $stored : array() );
		}
		return self::$cache;
	}

	/**
	 * Single option value with a caller-supplied fallback.
	 */
	public static function get( string $key, mixed $fallback = null ): mixed {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Merge the given key/value pairs into the stored options after
	 * sanitizing through the schema. Returns the final merged array.
	 */
	public static function update( array $values ): array {
		$merged  = array_merge( self::all(), $values );
		$cleaned = Timeslate_Options_Schema::sanitize( $merged );
		update_option( self::OPTION_KEY, $cleaned );
		self::$cache = $cleaned;
		return $cleaned;
	}

	/**
	 * Replace the entire options payload. Unknown keys are dropped.
	 */
	public static function replace( array $values ): array {
		$cleaned = Timeslate_Options_Schema::sanitize( $values );
		update_option( self::OPTION_KEY, $cleaned );
		self::$cache = $cleaned;
		return $cleaned;
	}

	/**
	 * Reset to schema defaults.
	 */
	public static function reset(): array {
		$defaults = Timeslate_Options_Schema::defaults();
		update_option( self::OPTION_KEY, $defaults );
		self::$cache = $defaults;
		return $defaults;
	}

	/**
	 * Drop the in-memory cache. Tests only.
	 */
	public static function flush_cache(): void {
		self::$cache = null;
	}
}
