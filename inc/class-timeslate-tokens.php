<?php
/**
 * Customer-facing cancel links — one-time-per-booking tokens stored
 * alongside the booking as `_ts_token`, with URL helpers + a
 * template_redirect handler that renders the confirm-cancel page.
 *
 * Flow:
 *   1. Booking creation stores a random 32-char token in `_ts_token`.
 *   2. Confirmation / cancellation emails include a link of the form
 *        {home_url}/?timeslate_cancel=1&booking={id}&token={t}
 *   3. When the customer clicks, this class intercepts on
 *      template_redirect, loads the booking, verifies the token, and
 *      renders a styled page inside the active theme's chrome
 *      (get_header / get_footer).
 *   4. The confirmation form POSTs back to the same URL with a nonce;
 *      on valid POST the booking is moved to `cancelled`, which fires
 *      the cancellation email through the shared email path.
 *
 * Tokens are NOT consumed on cancel — a customer might open the email
 * twice, and the second visit should still show a friendly "already
 * cancelled" page rather than a 404. The terminal `cancelled` status
 * itself prevents further action via the same link.
 *
 * @package Timeslate
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Timeslate_Tokens {

	public const QUERY_FLAG  = 'timeslate_cancel';
	public const NONCE_KEY   = 'timeslate_cancel_nonce';
	public const NONCE_FIELD = '_ts_cancel_nonce';

	public static function register(): void {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_cancel' ) );
	}

	/**
	 * Build the cancel URL for a given booking + token. Uses the site's
	 * home URL so the link resolves regardless of admin / frontend
	 * routing rules.
	 */
	public static function cancel_url( int $post_id, string $token ): string {
		return add_query_arg(
			array(
				self::QUERY_FLAG => 1,
				'booking'        => $post_id,
				'token'          => rawurlencode( $token ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Verify a token against the stored `_ts_token` meta. Uses
	 * `hash_equals` to sidestep timing attacks, even though the attack
	 * surface here is narrow — the token space is 62^32 and the attacker
	 * would need to know the booking ID to guess against.
	 */
	public static function verify( int $post_id, string $token ): bool {
		if ( $post_id <= 0 || '' === $token ) {
			return false;
		}
		$stored = (string) get_post_meta( $post_id, '_ts_token', true );
		if ( '' === $stored ) {
			return false;
		}
		return hash_equals( $stored, $token );
	}

	// ---- template_redirect handler -----------------------------------

	public static function maybe_handle_cancel(): void {
		if ( ! isset( $_GET[ self::QUERY_FLAG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only; auth is the per-booking token below.
			return;
		}

		$post_id = isset( $_GET['booking'] ) ? absint( wp_unslash( $_GET['booking'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token   = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$post = $post_id > 0 ? get_post( $post_id ) : null;
		$ok   = $post
			&& Timeslate_CPT::POST_TYPE === $post->post_type
			&& self::verify( $post_id, $token );

		if ( ! $ok ) {
			self::render_page(
				__( 'Link is invalid or expired', 'timeslate' ),
				sprintf(
					'<p>%s</p>',
					esc_html__( 'We couldn\'t find that booking, or the link is no longer valid. If you need help, please contact us directly.', 'timeslate' )
				)
			);
			exit;
		}

		// POST path — customer pressed "Confirm cancellation".
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$nonce_ok = isset( $_POST[ self::NONCE_FIELD ] )
				&& wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_KEY . '_' . $post_id );
			if ( ! $nonce_ok ) {
				self::render_page(
					__( 'Something went wrong', 'timeslate' ),
					sprintf(
						'<p>%s</p>',
						esc_html__( 'Your session may have expired. Please return to the email and click the cancel link again.', 'timeslate' )
					)
				);
				exit;
			}

			$current = (string) get_post_meta( $post_id, '_ts_status', true ) ?: 'pending';

			if ( in_array( $current, array( 'cancelled', 'completed', 'no_show' ), true ) ) {
				self::render_cancel_page( $post, $current, /* just_cancelled */ false );
				exit;
			}

			update_post_meta( $post_id, '_ts_status', 'cancelled' );

			if ( class_exists( 'Timeslate_Emails' ) ) {
				Timeslate_Emails::send_on_status_change( $post_id, $current, 'cancelled' );
			}

			self::render_cancel_page( $post, 'cancelled', /* just_cancelled */ true );
			exit;
		}

		// GET — render confirmation form (or success page if already cancelled).
		$current = (string) get_post_meta( $post_id, '_ts_status', true ) ?: 'pending';
		self::render_cancel_page( $post, $current, /* just_cancelled */ false );
		exit;
	}

	// ---- Rendering ---------------------------------------------------

	/**
	 * Render the cancel / already-cancelled page using the active
	 * theme's header and footer so the page sits inside the theme's
	 * chrome. The body HTML is loaded from `templates/cancel-page.php`
	 * and receives the current booking plus action URL / nonce field.
	 */
	private static function render_cancel_page( WP_Post $post, string $status, bool $just_cancelled ): void {
		$vars = Timeslate_Emails::template_vars( (int) $post->ID );
		if ( empty( $vars ) ) {
			self::render_page(
				__( 'Booking not found', 'timeslate' ),
				sprintf( '<p>%s</p>', esc_html__( 'We couldn\'t load this booking.', 'timeslate' ) )
			);
			return;
		}

		$vars['status']         = $status;
		$vars['status_label']   = Timeslate_Admin::status_label( $status );
		$vars['just_cancelled'] = $just_cancelled;
		$vars['action_url']     = self::cancel_url( (int) $post->ID, (string) get_post_meta( $post->ID, '_ts_token', true ) );
		$vars['nonce_field']    = wp_nonce_field( self::NONCE_KEY . '_' . $post->ID, self::NONCE_FIELD, true, false );

		$title = $just_cancelled
			? __( 'Booking cancelled', 'timeslate' )
			: ( 'cancelled' === $status
				? __( 'This booking is already cancelled', 'timeslate' )
				: __( 'Cancel your booking', 'timeslate' ) );

		$path = TIMESLATE_DIR . 'templates/cancel-page.php';
		$theme = locate_template( 'timeslate/cancel-page.php' );
		if ( $theme ) {
			$path = $theme;
		}

		ob_start();
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- template scope.
		extract( $vars, EXTR_SKIP );
		if ( is_readable( $path ) ) {
			include $path;
		}
		$body = (string) ob_get_clean();

		self::render_page( $title, $body );
	}

	/**
	 * Shell renderer used for both the main flow and error states. Sets
	 * the title, fires get_header / get_footer, and prints the body
	 * wrapped in a centered container so the page doesn't float at the
	 * top-left of an otherwise empty template.
	 */
	private static function render_page( string $title, string $body ): void {
		// Prevent search engines from indexing these URLs — they're
		// per-booking and have no content value.
		status_header( 200 );
		nocache_headers();

		add_filter(
			'pre_get_document_title',
			static function () use ( $title ) {
				return $title . ' – ' . wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
			}
		);
		add_action(
			'wp_head',
			static function (): void {
				echo '<meta name="robots" content="noindex,nofollow">' . "\n";
			}
		);

		wp_enqueue_style(
			'timeslate-cancel-page',
			TIMESLATE_URI . 'assets/public/cancel-page.css',
			array(),
			TIMESLATE_VERSION
		);

		get_header();
		?>
		<main id="primary" class="timeslate-cancel-page">
			<div class="timeslate-cancel-page__inner">
				<h1 class="timeslate-cancel-page__title">
					<?php echo esc_html( $title ); ?>
				</h1>
				<?php
				// Body is composed inside trusted templates with esc_*
				// calls at every interpolation point; passing it through
				// wp_kses_post would strip the <form> needed for the
				// cancel button. Echo raw.
				echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</div>
		</main>
		<?php
		get_footer();
	}
}
