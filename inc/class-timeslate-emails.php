<?php
/**
 * Email dispatch — sends customer confirmations, owner notifications,
 * and cancellation notices via wp_mail().
 *
 * Emails are HTML-first with a plain-text alternative generated from
 * the HTML at send time. Each email type has its own template under
 * `templates/email-{type}.php`. Themes and integrators can override any
 * template by placing a file at `{theme}/timeslate/email-{type}.php`,
 * or by filtering `timeslate_email_template_path_{type}`.
 *
 * Calls are made synchronously at the point of state change (REST create,
 * admin status-change, customer cancel). No wp-cron, no queue — if
 * wp_mail() fails, the booking still saved and an owner can re-send by
 * re-triggering the status transition.
 *
 * @package Timeslate
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Timeslate_Emails {

	/**
	 * The four email types shipped by the plugin. Order matters for the
	 * settings UI — it renders sections in this sequence.
	 */
	public const EMAIL_TYPES = array(
		'customer-pending',
		'customer-confirmed',
		'customer-cancelled',
		'owner-new',
	);

	/**
	 * Which copy blocks each email type exposes to the settings UI.
	 * `subject` always applies. `intro` renders above the summary table;
	 * `outro` renders below. Both are empty-string-safe — empty means
	 * "use the default from default_text()".
	 */
	public const EDITABLE_KEYS = array( 'subject', 'intro', 'outro' );

	/**
	 * Entry point for booking creation. Sends a customer-facing email
	 * matching the booking's state (pending vs confirmed) plus an owner
	 * notification to every address configured in settings.
	 */
	public static function send_on_created( int $post_id ): void {
		$status = (string) get_post_meta( $post_id, '_timeslate_status', true ) ?: 'pending';

		if ( 'confirmed' === $status ) {
			self::send( 'customer-confirmed', $post_id );
		} else {
			self::send( 'customer-pending', $post_id );
		}

		self::send( 'owner-new', $post_id );
	}

	/**
	 * Entry point for status changes. Routes the transition to the right
	 * customer-facing email. Transitions that don't change the customer's
	 * situation (e.g. confirmed → completed) don't fire mail — completed
	 * is an internal bookkeeping state.
	 */
	public static function send_on_status_change( int $post_id, string $from, string $to ): void {
		if ( $from === $to ) {
			return;
		}

		switch ( $to ) {
			case 'confirmed':
				if ( 'pending' === $from ) {
					self::send( 'customer-confirmed', $post_id );
				}
				break;
			case 'cancelled':
				self::send( 'customer-cancelled', $post_id );
				break;
		}
	}

	/**
	 * Load a template, resolve recipients, and dispatch via wp_mail.
	 * Returns the wp_mail return value so callers can log failures if
	 * they care; most callers don't — the booking is already persisted
	 * and owners will follow up manually if mail doesn't arrive.
	 */
	public static function send( string $type, int $post_id ): bool {
		$vars = self::template_vars( $post_id, $type );
		if ( empty( $vars ) ) {
			return false;
		}

		$recipients = self::recipients_for( $type, $post_id, $vars );
		if ( empty( $recipients ) ) {
			return false;
		}

		$subject = self::subject_for( $type, $vars );
		$body    = self::render_template( $type, $vars );

		/**
		 * Filter the final HTML body before send. Receives the rendered
		 * template as a string, followed by the type slug, post_id, and
		 * variable bag. Return the HTML to dispatch.
		 */
		$body = (string) apply_filters( "timeslate_email_body_{$type}", $body, $post_id, $vars );

		$headers = self::headers();

		add_filter( 'wp_mail_content_type', array( __CLASS__, 'force_html_content_type' ) );
		$ok = wp_mail( $recipients, $subject, $body, $headers );
		remove_filter( 'wp_mail_content_type', array( __CLASS__, 'force_html_content_type' ) );

		return (bool) $ok;
	}

	/**
	 * Content-type filter, scoped to our sends via add/remove around the
	 * wp_mail call. Prevents our HTML sends from flipping the mime type
	 * for every other wp_mail caller in the request.
	 */
	public static function force_html_content_type(): string {
		return 'text/html';
	}

	// ---- Recipients + headers ----------------------------------------

	private static function recipients_for( string $type, int $post_id, array $vars ): array {
		$recipients = array();

		if ( str_starts_with( $type, 'customer-' ) ) {
			$email = (string) ( $vars['email'] ?? '' );
			if ( '' !== $email && is_email( $email ) ) {
				$recipients[] = $email;
			}
		} elseif ( 'owner-new' === $type ) {
			$notify = (array) Timeslate_Options::get( 'notify_emails', array() );
			foreach ( $notify as $addr ) {
				$addr = sanitize_email( (string) $addr );
				if ( '' !== $addr && is_email( $addr ) ) {
					$recipients[] = $addr;
				}
			}
			// Fall back to the site admin when no notify addresses are set
			// — better a stray alert in the admin inbox than silent
			// bookings no one knows about.
			if ( empty( $recipients ) ) {
				$admin = sanitize_email( (string) get_option( 'admin_email' ) );
				if ( '' !== $admin && is_email( $admin ) ) {
					$recipients[] = $admin;
				}
			}
		}

		/**
		 * Filter the recipients list for a given email type. Receives
		 * `array $recipients, int $post_id, array $vars`. Return an array
		 * of valid email addresses; empty array suppresses the send.
		 */
		$recipients = (array) apply_filters( "timeslate_email_recipients_{$type}", $recipients, $post_id, $vars );

		// Final validation — callers may have injected junk.
		$clean = array();
		foreach ( $recipients as $addr ) {
			$addr = sanitize_email( (string) $addr );
			if ( '' !== $addr && is_email( $addr ) && ! in_array( $addr, $clean, true ) ) {
				$clean[] = $addr;
			}
		}
		return $clean;
	}

	private static function headers(): array {
		$site_name  = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$site_email = sanitize_email( (string) get_option( 'admin_email' ) );

		/**
		 * Filter the from-name used on all Timeslate emails.
		 */
		$from_name  = (string) apply_filters( 'timeslate_email_from_name', $site_name );
		/**
		 * Filter the from-email address. Defaults to admin_email, which
		 * is typically matched by the server's MTA; custom addresses may
		 * need SPF / DMARC alignment to avoid deliverability hits.
		 */
		$from_email = (string) apply_filters( 'timeslate_email_from_email', $site_email );

		$headers = array();
		if ( '' !== $from_email && is_email( $from_email ) ) {
			$headers[] = sprintf( 'From: %s <%s>', self::sanitize_header( $from_name ), $from_email );
		}
		return $headers;
	}

	/**
	 * Strip CR/LF so attacker-controlled names can't inject headers.
	 * Belt-and-suspenders — blogname / filters are admin-controlled, but
	 * this closes the door anyway.
	 */
	private static function sanitize_header( string $v ): string {
		return trim( preg_replace( '/[\r\n]+/', ' ', $v ) ?? '' );
	}

	// ---- Subject + template ------------------------------------------

	/**
	 * Build the final subject line: merge buyer override (if set) with
	 * the default, interpolate {placeholder} tokens, apply the filter.
	 */
	private static function subject_for( string $type, array $vars ): string {
		$subject = self::interpolate( self::resolved_text( $type, 'subject' ), $vars );

		/**
		 * Filter the subject line. Receives the resolved subject plus
		 * the type slug and the template variable bag.
		 */
		return (string) apply_filters( "timeslate_email_subject_{$type}", $subject, $vars );
	}

	// ---- Editable copy (subject / intro / outro) ---------------------

	/**
	 * Final buyer-facing text for a given email + copy block. Returns
	 * the stored override if the buyer has one, otherwise the default
	 * shipped with the plugin. No interpolation happens here — callers
	 * run `interpolate()` after reading.
	 */
	public static function resolved_text( string $type, string $key ): string {
		$override = self::stored_override( $type, $key );
		return '' !== $override ? $override : self::default_text( $type, $key );
	}

	/**
	 * Read the buyer-saved override from `email_templates`. Empty string
	 * = "fall back to the default".
	 */
	public static function stored_override( string $type, string $key ): string {
		$templates = (array) Timeslate_Options::get( 'email_templates', array() );
		$entry     = is_array( $templates[ $type ] ?? null ) ? $templates[ $type ] : array();
		return (string) ( $entry[ $key ] ?? '' );
	}

	/**
	 * Default copy for every type/key combination. Kept in PHP (not in
	 * the template files) so the settings UI can show these as
	 * placeholder text — "leave blank to use: …" — and so overrides
	 * stay data-only.
	 *
	 * All strings are translatable and use `{placeholder}` syntax for
	 * interpolation. `interpolate()` handles the substitution.
	 */
	public static function default_text( string $type, string $key ): string {
		$defaults = array(
			'customer-pending'   => array(
				'subject' => __( 'We received your booking request at {site_name}', 'timeslate' ),
				'intro'   => __( "Hi {name},\n\nThanks for your booking request at {site_name}. We've received it and will confirm by email as soon as we can.", 'timeslate' ),
				'outro'   => '',
			),
			'customer-confirmed' => array(
				'subject' => __( 'Your booking at {site_name} is confirmed for {date} at {time}', 'timeslate' ),
				'intro'   => __( "Hi {name},\n\nYour booking at {site_name} is confirmed.", 'timeslate' ),
				'outro'   => '',
			),
			'customer-cancelled' => array(
				'subject' => __( 'Your booking at {site_name} has been cancelled', 'timeslate' ),
				'intro'   => __( "Hi {name},\n\nYour booking at {site_name} has been cancelled. The details below are for your records.", 'timeslate' ),
				'outro'   => '',
			),
			'owner-new'          => array(
				'subject' => __( 'New booking: {people} on {date} at {time}', 'timeslate' ),
				'intro'   => __( 'A new booking has been received ({status}).', 'timeslate' ),
				'outro'   => '',
			),
		);
		return (string) ( $defaults[ $type ][ $key ] ?? '' );
	}

	/**
	 * Replace `{placeholder}` tokens in a string with values from the
	 * template variable bag. Unknown tokens are left in place so a
	 * typo'd placeholder is visible to the buyer instead of being
	 * silently dropped.
	 */
	public static function interpolate( string $s, array $vars ): string {
		$people_num  = (int) ( $vars['people'] ?? 0 );
		$people_text = sprintf(
			/* translators: %d: number of people. */
			_n( '%d person', '%d people', $people_num, 'timeslate' ),
			$people_num
		);

		$map = array(
			'{name}'       => (string) ( $vars['name'] ?? '' ),
			'{email}'      => (string) ( $vars['email'] ?? '' ),
			'{phone}'      => (string) ( $vars['phone'] ?? '' ),
			'{date}'       => (string) ( $vars['date_long'] ?? $vars['date'] ?? '' ),
			'{time}'       => (string) ( $vars['time_pretty'] ?? $vars['time'] ?? '' ),
			'{people}'      => $people_text,
			'{party_num}'  => (string) $people_num,
			'{status}'     => (string) ( $vars['status_label'] ?? $vars['status'] ?? '' ),
			'{site_name}'  => (string) ( $vars['site_name'] ?? '' ),
			'{site_url}'   => (string) ( $vars['site_url'] ?? '' ),
			'{cancel_url}' => (string) ( $vars['cancel_url'] ?? '' ),
			'{admin_url}'  => (string) ( $vars['admin_url'] ?? '' ),
		);
		return strtr( $s, $map );
	}

	/**
	 * The list of placeholders to surface in the settings UI help text.
	 * Keys are the token; values are short translatable descriptions.
	 */
	public static function placeholder_descriptions(): array {
		return array(
			'{name}'       => __( "Customer's name", 'timeslate' ),
			'{date}'       => __( 'Booking date', 'timeslate' ),
			'{time}'       => __( 'Booking time', 'timeslate' ),
			'{people}'      => __( 'Number of people (for example "4 people")', 'timeslate' ),
			'{status}'     => __( 'Current status label', 'timeslate' ),
			'{site_name}'  => __( 'Business name', 'timeslate' ),
			'{email}'      => __( "Customer's email address", 'timeslate' ),
			'{phone}'      => __( "Customer's phone number", 'timeslate' ),
			'{cancel_url}' => __( 'Self-service cancel link (customer emails only)', 'timeslate' ),
			'{admin_url}'  => __( 'Deep link into wp-admin (owner email only)', 'timeslate' ),
		);
	}

	/**
	 * Human-readable label for a given email type, used by the settings
	 * UI headings and any debug logging.
	 */
	public static function type_label( string $type ): string {
		$labels = array(
			'customer-pending'   => __( 'Customer: request received', 'timeslate' ),
			'customer-confirmed' => __( 'Customer: booking confirmed', 'timeslate' ),
			'customer-cancelled' => __( 'Customer: booking cancelled', 'timeslate' ),
			'owner-new'          => __( 'Owner: new booking notification', 'timeslate' ),
		);
		return $labels[ $type ] ?? $type;
	}

	/**
	 * Render a template file to a string. Looks first in the active theme
	 * (`{theme}/timeslate/email-{type}.php`), then in the plugin's
	 * own templates/ directory. Either lookup can be overridden by
	 * filtering `timeslate_email_template_path_{type}`.
	 *
	 * Templates receive two local variables:
	 *   $vars  — booking-specific fields (name, date_long, people, etc.)
	 *   $type  — the template slug being rendered
	 *
	 * The rendered body is always wrapped in the shared `email-wrapper`
	 * unless the inner template signals otherwise by returning a string
	 * containing the sentinel `<!-- timeslate:no-wrap -->`.
	 */
	public static function render_template( string $type, array $vars ): string {
		$inner = self::capture_template( "email-{$type}", $vars, $type );

		if ( str_contains( $inner, '<!-- timeslate:no-wrap -->' ) ) {
			return $inner;
		}

		$wrap_vars         = $vars;
		$wrap_vars['body'] = $inner;
		$wrap_vars['type'] = $type;

		return self::capture_template( 'email-wrapper', $wrap_vars, $type );
	}

	private static function capture_template( string $template, array $vars, string $type ): string {
		// Locals here must not collide with keys of $vars, or the
		// extract() below skips them: `$name` is the customer's name.
		$default = TIMESLATE_DIR . 'templates/' . $template . '.php';
		$theme   = locate_template( 'timeslate/' . $template . '.php' );
		$path    = $theme ?: $default;

		/**
		 * Filter the resolved template path. Useful for integrators who
		 * ship templates from a plugin or mu-plugin rather than a theme.
		 */
		$path = (string) apply_filters(
			"timeslate_email_template_path_{$type}",
			$path,
			$template,
			$vars
		);

		if ( ! is_readable( $path ) ) {
			return '';
		}

		ob_start();
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- template scope.
		extract( $vars, EXTR_SKIP );
		include $path;
		return (string) ob_get_clean();
	}

	// ---- Template vars -----------------------------------------------

	/**
	 * Build the canonical variable bag passed to every template. Keeps
	 * template files small — no meta-fetching logic lives in them.
	 *
	 * Returns an empty array when the post doesn't exist or isn't a
	 * booking, which short-circuits the send.
	 */
	public static function template_vars( int $post_id, string $type = '' ): array {
		$post = get_post( $post_id );
		if ( ! $post || Timeslate_CPT::POST_TYPE !== $post->post_type ) {
			return array();
		}

		$date   = (string) get_post_meta( $post_id, '_timeslate_date', true );
		$time   = (string) get_post_meta( $post_id, '_timeslate_time', true );
		$people  = (int) get_post_meta( $post_id, '_timeslate_people', true );
		$name   = (string) get_post_meta( $post_id, '_timeslate_name', true ) ?: $post->post_title;
		$email  = (string) get_post_meta( $post_id, '_timeslate_email', true );
		$phone  = (string) get_post_meta( $post_id, '_timeslate_phone', true );
		$notes  = (string) get_post_meta( $post_id, '_timeslate_notes', true );
		$status = (string) get_post_meta( $post_id, '_timeslate_status', true ) ?: 'pending';
		$token  = (string) get_post_meta( $post_id, '_timeslate_token', true );

		$date_long   = $date;
		$time_pretty = $time;
		if ( '' !== $date && '' !== $time ) {
			$dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $date . ' ' . $time, wp_timezone() );
			if ( $dt ) {
				$date_long   = wp_date( (string) get_option( 'date_format', 'l, F j, Y' ), $dt->getTimestamp() );
				$time_pretty = wp_date( (string) get_option( 'time_format', 'g:i a' ), $dt->getTimestamp() );
			}
		}

		$site_name = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$site_url  = home_url( '/' );

		// Not get_edit_post_link(): that returns null unless the current
		// user can edit the post, and bookings are created by visitors.
		$admin_url  = '' !== $token
			? add_query_arg(
				array(
					'post'   => $post_id,
					'action' => 'edit',
				),
				admin_url( 'post.php' )
			)
			: '';
		$cancel_url = '' !== $token && class_exists( 'Timeslate_Tokens' )
			? Timeslate_Tokens::cancel_url( $post_id, $token )
			: '';

		$vars = array(
			'post_id'     => $post_id,
			'name'        => $name,
			'email'       => $email,
			'phone'       => $phone,
			'notes'       => $notes,
			'date'        => $date,
			'date_long'   => $date_long,
			'time'        => $time,
			'time_pretty' => $time_pretty,
			'people'       => $people,
			'status'      => $status,
			'status_label'=> Timeslate_Admin::status_label( $status ),
			'site_name'   => $site_name,
			'site_url'    => $site_url,
			'cancel_url'  => $cancel_url,
			'admin_url'   => $admin_url,
		);

		// Pre-render the buyer-customizable intro / outro blocks so the
		// email template files stay simple (one echo each, no logic).
		// `intro` / `outro` stays as the raw text so integrators and the
		// settings UI can inspect it; `intro_html` / `outro_html` is the
		// safe-for-output escaped + autop'd HTML.
		if ( '' !== $type && in_array( $type, self::EMAIL_TYPES, true ) ) {
			$intro_raw         = self::interpolate( self::resolved_text( $type, 'intro' ), $vars );
			$outro_raw         = self::interpolate( self::resolved_text( $type, 'outro' ), $vars );
			$vars['intro']     = $intro_raw;
			$vars['outro']     = $outro_raw;
			$vars['intro_html']= '' !== $intro_raw ? wpautop( esc_html( $intro_raw ) ) : '';
			$vars['outro_html']= '' !== $outro_raw ? wpautop( esc_html( $outro_raw ) ) : '';
		} else {
			$vars['intro']      = '';
			$vars['outro']      = '';
			$vars['intro_html'] = '';
			$vars['outro_html'] = '';
		}

		/**
		 * Filter the full template variable bag. Integrators can add or
		 * mutate keys before the template renders.
		 */
		return (array) apply_filters( 'timeslate_email_template_vars', $vars, $post_id );
	}
}
