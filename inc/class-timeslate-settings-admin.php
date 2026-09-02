<?php
/**
 * Settings admin — submenu under Bookings for editing every owner-
 * configurable booking knob: opening hours, blackout dates, the
 * booking window (duration / slot interval / advance min-max / max
 * people), auto-approve, and notification email recipients.
 *
 * Storage goes through `Timeslate_Options` into the single
 * `timeslate_options` blob. The schema owns defaults and
 * sanitization; this class only owns the render + save surface.
 *
 * Save flow is classic admin-post.php with a nonce — there's no SPA
 * need for a flat form like this, and avoiding a REST roundtrip keeps
 * the bootstrap cost to zero when the page isn't open.
 *
 * @package Timeslate
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Timeslate_Settings_Admin {

	public const PAGE_SLUG  = 'timeslate-settings';
	public const CAPABILITY = 'manage_options';
	public const ACTION     = 'timeslate_save_settings';
	public const NONCE      = 'timeslate_settings_nonce';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function add_menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . Timeslate_CPT::POST_TYPE,
			__( 'Booking Settings', 'timeslate' ),
			__( 'Settings', 'timeslate' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function enqueue_assets( string $hook ): void {
		// Submenu page hook is of the form
		// {parent-slug}_page_{submenu-slug}, where parent-slug here is
		// the CPT edit URL rewritten to a menu key.
		$expected = Timeslate_CPT::POST_TYPE . '_page_' . self::PAGE_SLUG;
		if ( $hook !== $expected ) {
			return;
		}

		wp_enqueue_style(
			'timeslate-settings',
			TIMESLATE_URI . 'assets/admin/settings.css',
			array(),
			TIMESLATE_VERSION
		);

		wp_enqueue_script(
			'timeslate-settings',
			TIMESLATE_URI . 'assets/admin/settings.js',
			array(),
			TIMESLATE_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$values = Timeslate_Options::all();
		?>
		<div class="wrap timeslate-settings">
			<h1><?php esc_html_e( 'Booking Settings', 'timeslate' ); ?></h1>

			<?php self::maybe_render_notice(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ts-settings-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION, self::NONCE ); ?>

				<?php self::render_opening_hours( (array) $values['opening_hours'] ); ?>
				<?php self::render_blackout_dates( (array) $values['blackout_dates'] ); ?>
				<?php self::render_booking_window( $values ); ?>
				<?php self::render_notifications( $values ); ?>
				<?php self::render_advanced( $values ); ?>

				<?php submit_button( __( 'Save changes', 'timeslate' ) ); ?>
			</form>
		</div>
		<?php
	}

	// ---- Sections -----------------------------------------------------

	private static function render_opening_hours( array $hours ): void {
		$day_names = self::weekday_names();
		$order     = self::weekday_order();
		?>
		<h2><?php esc_html_e( 'Opening hours', 'timeslate' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Set the days and periods when you take bookings. Each period has its own capacity, so a morning session can take fewer people than an afternoon one.', 'timeslate' ); ?>
		</p>
		<div class="ts-hours">
			<?php foreach ( $order as $day ) :
				$day_data    = is_array( $hours[ $day ] ?? null ) ? $hours[ $day ] : array();
				$enabled     = ! empty( $day_data['enabled'] );
				$periods     = is_array( $day_data['periods'] ?? null ) ? $day_data['periods'] : array();
				$day_label   = $day_names[ $day ];
				?>
				<div class="ts-day" data-day="<?php echo esc_attr( (string) $day ); ?>">
					<label class="ts-day-header">
						<input type="checkbox"
							name="opening_hours[<?php echo esc_attr( (string) $day ); ?>][enabled]"
							value="1"
							<?php checked( $enabled ); ?>>
						<strong><?php echo esc_html( $day_label ); ?></strong>
					</label>
					<div class="ts-day-periods">
						<?php foreach ( $periods as $i => $p ) : ?>
							<?php
							self::render_period_row(
								$day,
								(int) $i,
								(string) ( $p['start'] ?? '' ),
								(string) ( $p['end'] ?? '' ),
								(int) ( $p['capacity'] ?? 0 )
							);
							?>
						<?php endforeach; ?>
					</div>
					<button type="button" class="button-link ts-period-add">
						+ <?php esc_html_e( 'Add period', 'timeslate' ); ?>
					</button>
				</div>
			<?php endforeach; ?>
		</div>
		<template id="ts-period-template">
			<?php self::render_period_row( 0, 0, '', '', 0 ); ?>
		</template>
		<?php
	}

	/**
	 * One period row inside a day. Shared between the server-side render
	 * and the JS template so both produce identical markup — the JS
	 * re-indexes name attributes on insert.
	 */
	private static function render_period_row( int $day, int $i, string $start, string $end, int $capacity ): void {
		$base = sprintf( 'opening_hours[%d][periods][%d]', $day, $i );
		?>
		<div class="ts-period" data-period="<?php echo esc_attr( (string) $i ); ?>">
			<input type="time"
				name="<?php echo esc_attr( $base . '[start]' ); ?>"
				value="<?php echo esc_attr( $start ); ?>"
				aria-label="<?php esc_attr_e( 'Start time', 'timeslate' ); ?>">
			<span class="ts-period-sep"><?php esc_html_e( 'to', 'timeslate' ); ?></span>
			<input type="time"
				name="<?php echo esc_attr( $base . '[end]' ); ?>"
				value="<?php echo esc_attr( $end ); ?>"
				aria-label="<?php esc_attr_e( 'End time', 'timeslate' ); ?>">
			<input type="number"
				name="<?php echo esc_attr( $base . '[capacity]' ); ?>"
				value="<?php echo esc_attr( (string) $capacity ); ?>"
				min="0"
				step="1"
				class="ts-capacity-input"
				aria-label="<?php esc_attr_e( 'Seat capacity', 'timeslate' ); ?>">
			<span class="ts-period-unit"><?php esc_html_e( 'seats', 'timeslate' ); ?></span>
			<button type="button" class="button-link-delete ts-period-remove" aria-label="<?php esc_attr_e( 'Remove period', 'timeslate' ); ?>">×</button>
		</div>
		<?php
	}

	private static function render_blackout_dates( array $dates ): void {
		?>
		<h2><?php esc_html_e( 'Blackout dates', 'timeslate' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Specific dates you are closed, such as holidays or private events. Bookings are blocked on these dates whatever your opening hours say.', 'timeslate' ); ?>
		</p>
		<div class="ts-blackouts">
			<div class="ts-blackout-add-row">
				<input type="date" id="ts-blackout-input">
				<button type="button" class="button" id="ts-blackout-add"><?php esc_html_e( 'Add date', 'timeslate' ); ?></button>
			</div>
			<ul id="ts-blackout-list" class="ts-blackout-list">
				<?php foreach ( $dates as $d ) : ?>
					<?php self::render_blackout_row( (string) $d ); ?>
				<?php endforeach; ?>
			</ul>
		</div>
		<template id="ts-blackout-template">
			<?php self::render_blackout_row( '' ); ?>
		</template>
		<?php
	}

	private static function render_blackout_row( string $date ): void {
		?>
		<li class="ts-blackout-item">
			<input type="hidden" name="blackout_dates[]" value="<?php echo esc_attr( $date ); ?>">
			<span class="ts-blackout-date"><?php echo esc_html( $date ); ?></span>
			<button type="button" class="button-link-delete ts-blackout-remove" aria-label="<?php esc_attr_e( 'Remove date', 'timeslate' ); ?>">×</button>
		</li>
		<?php
	}

	private static function render_booking_window( array $values ): void {
		?>
		<h2><?php esc_html_e( 'Booking window', 'timeslate' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="ts-service-duration"><?php esc_html_e( 'Service duration (minutes)', 'timeslate' ); ?></label>
				</th>
				<td>
					<input type="number"
						id="ts-service-duration"
						name="service_duration_mins"
						value="<?php echo esc_attr( (string) $values['service_duration_mins'] ); ?>"
						min="15" max="480" step="15"
						class="small-text">
					<p class="description">
						<?php esc_html_e( 'How long a table stays occupied per booking. 90–120 minutes is typical.', 'timeslate' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="ts-slot-interval"><?php esc_html_e( 'Slot interval', 'timeslate' ); ?></label>
				</th>
				<td>
					<?php
					$slot_choices = (array) ( Timeslate_Options_Schema::fields()['slot_interval_mins']['choices'] ?? array() );
					?>
					<select id="ts-slot-interval" name="slot_interval_mins">
						<?php foreach ( $slot_choices as $v ) : ?>
							<option value="<?php echo esc_attr( (string) $v ); ?>" <?php selected( (int) $values['slot_interval_mins'], (int) $v ); ?>>
								<?php echo esc_html( sprintf( /* translators: %d minutes */ _n( '%d minute', '%d minutes', (int) $v, 'timeslate' ), (int) $v ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'How often a booking slot appears in the time picker (e.g. every 30 minutes). For single-seating fine-dining formats, pair a longer interval like 120 minutes with a matching service duration.', 'timeslate' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="ts-advance-min"><?php esc_html_e( 'Minimum advance (hours)', 'timeslate' ); ?></label>
				</th>
				<td>
					<input type="number"
						id="ts-advance-min"
						name="advance_min_hours"
						value="<?php echo esc_attr( (string) $values['advance_min_hours'] ); ?>"
						min="0" max="168" step="1"
						class="small-text">
					<p class="description">
						<?php esc_html_e( 'Customers must book at least this far ahead. Set to 0 to accept walk-up-style immediate bookings.', 'timeslate' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="ts-advance-max"><?php esc_html_e( 'Maximum advance (days)', 'timeslate' ); ?></label>
				</th>
				<td>
					<input type="number"
						id="ts-advance-max"
						name="advance_max_days"
						value="<?php echo esc_attr( (string) $values['advance_max_days'] ); ?>"
						min="1" max="365" step="1"
						class="small-text">
					<p class="description">
						<?php esc_html_e( 'Latest a customer can book in advance. 60 days is a common default.', 'timeslate' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="ts-max-people"><?php esc_html_e( 'Max number of people online', 'timeslate' ); ?></label>
				</th>
				<td>
					<input type="number"
						id="ts-max-people"
						name="max_people_online"
						value="<?php echo esc_attr( (string) $values['max_people_online'] ); ?>"
						min="1" max="100" step="1"
						class="small-text">
					<p class="description">
						<?php esc_html_e( 'Larger groups are directed to call instead of booking online.', 'timeslate' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<?php esc_html_e( 'Approval flow', 'timeslate' ); ?>
				</th>
				<td>
					<label>
						<input type="checkbox"
							name="auto_approve"
							value="1"
							<?php checked( ! empty( $values['auto_approve'] ) ); ?>>
						<?php esc_html_e( 'Auto-approve new bookings', 'timeslate' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'When on, bookings that fit the available capacity are confirmed immediately. When off, new bookings arrive as "pending" for you to review.', 'timeslate' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	private static function render_notifications( array $values ): void {
		$emails_str = implode( "\n", (array) ( $values['notify_emails'] ?? array() ) );
		?>
		<h2><?php esc_html_e( 'Notifications', 'timeslate' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="ts-notify-emails"><?php esc_html_e( 'Notify these addresses', 'timeslate' ); ?></label>
				</th>
				<td>
					<textarea id="ts-notify-emails"
						name="notify_emails"
						rows="4"
						class="large-text code"
						placeholder="owner@example.com&#10;host@example.com"><?php echo esc_textarea( $emails_str ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'One email per line (or comma-separated). Every address here gets an email for each new booking.', 'timeslate' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	private static function render_advanced( array $values ): void {
		?>
		<h2><?php esc_html_e( 'Advanced', 'timeslate' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<?php esc_html_e( 'On uninstall', 'timeslate' ); ?>
				</th>
				<td>
					<label>
						<input type="checkbox"
							name="uninstall_delete_data"
							value="1"
							<?php checked( ! empty( $values['uninstall_delete_data'] ) ); ?>>
						<?php esc_html_e( 'Delete all bookings and settings when the plugin is uninstalled', 'timeslate' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Off by default, so deactivating or deleting the plugin preserves your data. Turn on only if you want a clean slate on removal.', 'timeslate' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	// ---- Save handler -------------------------------------------------

	public static function handle_save(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to edit these settings.', 'timeslate' ), '', 403 );
		}
		check_admin_referer( self::ACTION, self::NONCE );

		// Timeslate_Options::replace runs the full schema sanitizer,
		// so we just forward the raw posted shape. Unknown keys are
		// dropped by the schema; missing checkbox fields are coerced to
		// false in sanitize().
		$payload = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above
		unset( $payload['action'], $payload[ self::NONCE ], $payload['_wp_http_referer'], $payload['submit'] );

		Timeslate_Options::replace( $payload );

		wp_safe_redirect(
			add_query_arg(
				array( 'timeslate-saved' => '1' ),
				admin_url( 'edit.php?post_type=' . Timeslate_CPT::POST_TYPE . '&page=' . self::PAGE_SLUG )
			)
		);
		exit;
	}

	private static function maybe_render_notice(): void {
		if ( ! isset( $_GET['timeslate-saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag
			return;
		}
		?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Settings saved.', 'timeslate' ); ?></p>
		</div>
		<?php
	}

	// ---- Weekday helpers ---------------------------------------------

	/**
	 * Translated Sunday-through-Saturday names, indexed 0–6 to match
	 * PHP's `date('w')` convention.
	 */
	private static function weekday_names(): array {
		global $wp_locale;
		$names = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$names[ $i ] = $wp_locale instanceof WP_Locale
				? $wp_locale->get_weekday( $i )
				: gmdate( 'l', strtotime( "Sunday +{$i} days" ) );
		}
		return $names;
	}

	/**
	 * Display order for weekdays, honoring the site's `start_of_week`
	 * setting. Returns an array of 7 day indexes (0–6) in display order.
	 */
	private static function weekday_order(): array {
		$start = (int) get_option( 'start_of_week', 1 );
		$start = max( 0, min( 6, $start ) );
		$out   = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$out[] = ( $start + $i ) % 7;
		}
		return $out;
	}
}
