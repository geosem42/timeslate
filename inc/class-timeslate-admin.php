<?php
/**
 * Bookings admin list — custom columns, sortable meta, and per-row
 * status change actions.
 *
 * Replaces WP's default "Title" / "Date" column pair on the
 * `timeslate_booking` post type with columns that actually answer the
 * question an owner asks when they open wp-admin: "Who's coming
 * when?". The default WP title is the customer's name; meta fields
 * carry the booking date, time, number of people, status, email, and
 * phone.
 *
 * Status changes are routed through admin-post.php with a per-post
 * nonce so a stray click or malicious link can't flip a booking's
 * state. The URL helpers are kept small because the same row actions
 * will be reused from the calendar screen.
 *
 * @package Timeslate
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Timeslate_Admin {

	public const STATUS_ACTION = 'timeslate_change_status';

	public static function register(): void {
		$cpt = Timeslate_CPT::POST_TYPE;

		add_filter( "manage_{$cpt}_posts_columns", array( __CLASS__, 'filter_columns' ) );
		add_action( "manage_{$cpt}_posts_custom_column", array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( "manage_edit-{$cpt}_sortable_columns", array( __CLASS__, 'sortable_columns' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_sort' ) );

		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_post_' . self::STATUS_ACTION, array( __CLASS__, 'handle_status_change' ) );

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_notice' ) );

		// The months dropdown that core adds above the list makes no sense
		// for bookings (owners filter by booking date, not creation date).
		add_filter( 'months_dropdown_results', array( __CLASS__, 'hide_months_filter' ), 10, 2 );

		// Edit-screen customizations: details + actions meta boxes,
		// hide the editor, drop the Custom Fields / Slug boxes we don't
		// need. Keeps the URL the native post-edit page but the layout
		// becomes a detail view with status actions on the side.
		add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'disable_block_editor' ), 10, 2 );
		add_action( "add_meta_boxes_{$cpt}", array( __CLASS__, 'register_meta_boxes' ) );
	}

	// ---- Columns ------------------------------------------------------

	public static function filter_columns( array $cols ): array {
		return array(
			'cb'                 => $cols['cb'] ?? '',
			'title'              => __( 'Customer', 'timeslate' ),
			'ts_booking_date'    => __( 'Date', 'timeslate' ),
			'ts_booking_time'    => __( 'Time', 'timeslate' ),
			'ts_people'           => __( 'People', 'timeslate' ),
			'ts_status'          => __( 'Status', 'timeslate' ),
			'ts_email'           => __( 'Email', 'timeslate' ),
			'ts_phone'           => __( 'Phone', 'timeslate' ),
		);
	}

	public static function render_column( string $col, int $post_id ): void {
		switch ( $col ) {
			case 'ts_booking_date':
				$date = (string) get_post_meta( $post_id, '_ts_date', true );
				echo $date ? esc_html( $date ) : '<span class="ts-muted">&mdash;</span>';
				break;

			case 'ts_booking_time':
				$time = (string) get_post_meta( $post_id, '_ts_time', true );
				echo $time ? esc_html( $time ) : '<span class="ts-muted">&mdash;</span>';
				break;

			case 'ts_people':
				$people = (int) get_post_meta( $post_id, '_ts_people', true );
				echo esc_html( (string) $people );
				break;

			case 'ts_status':
				$status = (string) get_post_meta( $post_id, '_ts_status', true );
				$status = $status ?: 'pending';
				printf(
					'<span class="ts-status-badge ts-status-badge--%s">%s</span>',
					esc_attr( $status ),
					esc_html( self::status_label( $status ) )
				);
				break;

			case 'ts_email':
				$email = (string) get_post_meta( $post_id, '_ts_email', true );
				if ( '' === $email ) {
					echo '<span class="ts-muted">&mdash;</span>';
					break;
				}
				printf(
					'<a href="mailto:%1$s">%1$s</a>',
					esc_attr( $email )
				);
				break;

			case 'ts_phone':
				$phone = (string) get_post_meta( $post_id, '_ts_phone', true );
				echo $phone ? esc_html( $phone ) : '<span class="ts-muted">&mdash;</span>';
				break;
		}
	}

	// ---- Sorting ------------------------------------------------------

	public static function sortable_columns( array $cols ): array {
		$cols['ts_booking_date'] = 'ts_booking_date';
		$cols['ts_booking_time'] = 'ts_booking_time';
		return $cols;
	}

	public static function apply_sort( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( $query->get( 'post_type' ) !== Timeslate_CPT::POST_TYPE ) {
			return;
		}

		$orderby = (string) $query->get( 'orderby' );
		if ( 'ts_booking_date' === $orderby ) {
			$query->set( 'meta_key', '_ts_date' );
			$query->set( 'orderby', 'meta_value' );
		} elseif ( 'ts_booking_time' === $orderby ) {
			$query->set( 'meta_key', '_ts_time' );
			$query->set( 'orderby', 'meta_value' );
		}
	}

	// ---- Row actions (status changes) --------------------------------

	public static function row_actions( array $actions, WP_Post $post ): array {
		if ( $post->post_type !== Timeslate_CPT::POST_TYPE ) {
			return $actions;
		}

		$current = (string) get_post_meta( $post->ID, '_ts_status', true );
		$current = $current ?: 'pending';
		$new     = array();

		$transitions = self::allowed_transitions( $current );
		foreach ( $transitions as $to => $label ) {
			$url = self::status_url( $post->ID, $to );
			$new[ 'ts_' . $to ] = sprintf(
				'<a href="%1$s" class="ts-row-action ts-row-action--%2$s">%3$s</a>',
				esc_url( $url ),
				esc_attr( $to ),
				esc_html( $label )
			);
		}

		// Prepend our actions so the most-useful links show first; keep
		// the default Edit / Trash links intact.
		return array_merge( $new, $actions );
	}

	/**
	 * Allowed status transitions from the current state, as
	 * `to_status => link_label`. Keeps the row-actions UI honest —
	 * confirming a cancelled booking doesn't make sense, so we don't
	 * offer the link. Public so the edit-screen Actions box can reuse
	 * the same transition rules.
	 */
	public static function allowed_transitions( string $from ): array {
		switch ( $from ) {
			case 'pending':
				return array(
					'confirmed' => __( 'Confirm', 'timeslate' ),
					'cancelled' => __( 'Cancel', 'timeslate' ),
				);
			case 'confirmed':
				return array(
					'completed' => __( 'Mark completed', 'timeslate' ),
					'no_show'   => __( 'No-show', 'timeslate' ),
					'cancelled' => __( 'Cancel', 'timeslate' ),
				);
			case 'completed':
			case 'cancelled':
			case 'no_show':
				// Terminal states — no forward transitions from the list
				// view. Owners can still edit the post directly to force
				// a state change if they need to.
				return array();
			default:
				return array();
		}
	}

	public static function status_url( int $post_id, string $to ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'     => self::STATUS_ACTION,
					'booking_id' => $post_id,
					'to'         => $to,
				),
				admin_url( 'admin-post.php' )
			),
			self::STATUS_ACTION . '_' . $post_id
		);
	}

	public static function handle_status_change(): void {
		$booking_id = isset( $_GET['booking_id'] ) ? (int) $_GET['booking_id'] : 0;
		$to         = isset( $_GET['to'] ) ? sanitize_key( wp_unslash( (string) $_GET['to'] ) ) : '';

		if ( $booking_id <= 0 || ! current_user_can( 'edit_post', $booking_id ) ) {
			wp_die( esc_html__( 'You do not have permission to change this booking.', 'timeslate' ), '', 403 );
		}
		check_admin_referer( self::STATUS_ACTION . '_' . $booking_id );

		if ( ! in_array( $to, Timeslate_CPT::STATUSES, true ) ) {
			wp_die( esc_html__( 'Invalid booking status.', 'timeslate' ), '', 400 );
		}

		$from = (string) get_post_meta( $booking_id, '_ts_status', true ) ?: 'pending';

		update_post_meta( $booking_id, '_ts_status', $to );

		// Trigger transactional email for transitions that the customer
		// cares about (confirmation, cancellation). Gate on the class
		// existing in case a site has disabled email routing via a
		// plugin file replacement.
		if ( class_exists( 'Timeslate_Emails' ) ) {
			Timeslate_Emails::send_on_status_change( $booking_id, $from, $to );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'              => Timeslate_CPT::POST_TYPE,
					'timeslate-updated'  => $to,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	// ---- Notices + assets --------------------------------------------

	public static function maybe_render_notice(): void {
		$status = isset( $_GET['timeslate-updated'] ) ? sanitize_key( (string) $_GET['timeslate-updated'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag
		if ( '' === $status || ! in_array( $status, Timeslate_CPT::STATUSES, true ) ) {
			return;
		}
		$messages = array(
			'pending'   => __( 'Booking moved back to pending.', 'timeslate' ),
			'confirmed' => __( 'Booking confirmed.', 'timeslate' ),
			'completed' => __( 'Booking marked completed.', 'timeslate' ),
			'cancelled' => __( 'Booking cancelled.', 'timeslate' ),
			'no_show'   => __( 'Booking marked as no-show.', 'timeslate' ),
		);
		?>
		<div class="notice notice-success is-dismissible">
			<p><?php echo esc_html( $messages[ $status ] ?? __( 'Booking updated.', 'timeslate' ) ); ?></p>
		</div>
		<?php
	}

	public static function enqueue_assets( string $hook ): void {
		// admin.css styles both the list view (edit.php) and the single
		// edit screen (post.php / post-new.php). The screen check below
		// scopes it to our CPT either way.
		if ( ! in_array( $hook, array( 'edit.php', 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || Timeslate_CPT::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style(
			'timeslate-admin',
			TIMESLATE_URI . 'assets/admin/admin.css',
			array(),
			TIMESLATE_VERSION
		);
	}

	public static function hide_months_filter( array $months, string $post_type ): array {
		if ( Timeslate_CPT::POST_TYPE === $post_type ) {
			return array();
		}
		return $months;
	}

	// ---- Edit-screen meta boxes --------------------------------------

	/**
	 * Bookings aren't authored content — the classic editor's block
	 * editor adds nothing useful and clutters the screen with a
	 * content area we'd just have to hide. Skip it entirely for this
	 * CPT so WP renders the lightweight classic-editor layout.
	 */
	public static function disable_block_editor( bool $use, string $post_type ): bool {
		if ( Timeslate_CPT::POST_TYPE === $post_type ) {
			return false;
		}
		return $use;
	}

	/**
	 * Register the Booking Details + Actions meta boxes, and drop the
	 * Custom Fields + Slug boxes WP would otherwise add. `custom-fields`
	 * is already absent from the CPT's `supports` array, but removing
	 * the meta box explicitly is belt-and-suspenders in case a third
	 * third party tries to re-enable it.
	 */
	public static function register_meta_boxes(): void {
		$cpt = Timeslate_CPT::POST_TYPE;

		remove_meta_box( 'postcustom', $cpt, 'normal' );
		remove_meta_box( 'slugdiv',    $cpt, 'normal' );

		add_meta_box(
			'timeslate-details',
			__( 'Booking Details', 'timeslate' ),
			array( __CLASS__, 'render_details_box' ),
			$cpt,
			'normal',
			'high'
		);

		add_meta_box(
			'timeslate-actions',
			__( 'Actions', 'timeslate' ),
			array( __CLASS__, 'render_actions_box' ),
			$cpt,
			'side',
			'high'
		);
	}

	/**
	 * Read-only booking details rendered as a label/value list. We
	 * echo the meta directly — no form inputs, no save flow. The title
	 * field above (WP's native) is the only editable thing, kept so
	 * owners can fix typos in customer names.
	 */
	public static function render_details_box( WP_Post $post ): void {
		$date     = (string) get_post_meta( $post->ID, '_ts_date', true );
		$time     = (string) get_post_meta( $post->ID, '_ts_time', true );
		$people    = (int) get_post_meta( $post->ID, '_ts_people', true );
		$duration = (int) get_post_meta( $post->ID, '_ts_duration_mins', true );
		$name     = (string) get_post_meta( $post->ID, '_ts_name', true );
		$email    = (string) get_post_meta( $post->ID, '_ts_email', true );
		$phone    = (string) get_post_meta( $post->ID, '_ts_phone', true );
		$notes    = (string) get_post_meta( $post->ID, '_ts_notes', true );
		$status   = (string) get_post_meta( $post->ID, '_ts_status', true ) ?: 'pending';
		$ip       = (string) get_post_meta( $post->ID, '_ts_ip', true );

		// Friendly date / time using the site's locale + settings.
		$date_str = '';
		$time_str = '';
		if ( '' !== $date && '' !== $time ) {
			$dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $date . ' ' . $time, wp_timezone() );
			if ( $dt ) {
				$date_str = wp_date( (string) get_option( 'date_format', 'F j, Y' ), $dt->getTimestamp() );
				$time_str = wp_date( (string) get_option( 'time_format', 'g:i a' ), $dt->getTimestamp() );
			}
		}

		$submitted_ts = get_post_timestamp( $post );
		$submitted    = $submitted_ts
			? wp_date( (string) get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $submitted_ts )
			: '';

		?>
		<div class="ts-details">
			<?php
			self::render_detail_row( __( 'Date', 'timeslate' ), $date_str ?: $date );
			self::render_detail_row( __( 'Time', 'timeslate' ), $time_str ?: $time );
			self::render_detail_row(
				__( 'Number of people', 'timeslate' ),
				$people > 0
					? sprintf(
						/* translators: %d: number of people. */
						_n( '%d person', '%d people', $people, 'timeslate' ),
						$people
					)
					: '—'
			);
			self::render_detail_row(
				__( 'Service duration', 'timeslate' ),
				$duration > 0
					? sprintf(
						/* translators: %d: duration in minutes. */
						__( '%d minutes', 'timeslate' ),
						$duration
					)
					: '—'
			);
			self::render_detail_row(
				__( 'Status', 'timeslate' ),
				sprintf(
					'<span class="ts-status-badge ts-status-badge--%s">%s</span>',
					esc_attr( $status ),
					esc_html( self::status_label( $status ) )
				),
				true
			);

			echo '<div class="ts-details__divider"></div>';

			self::render_detail_row( __( 'Customer', 'timeslate' ), $name ?: $post->post_title );
			self::render_detail_row(
				__( 'Email', 'timeslate' ),
				$email
					? sprintf( '<a href="mailto:%1$s">%1$s</a>', esc_attr( $email ) )
					: '—',
				true
			);
			self::render_detail_row(
				__( 'Phone', 'timeslate' ),
				$phone
					? sprintf( '<a href="tel:%1$s">%2$s</a>', esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ), esc_html( $phone ) )
					: '—',
				true
			);
			self::render_detail_row( __( 'Notes', 'timeslate' ), $notes ?: '—' );

			echo '<div class="ts-details__divider"></div>';

			self::render_detail_row( __( 'Reference', 'timeslate' ), '#' . $post->ID );
			self::render_detail_row( __( 'Submitted', 'timeslate' ), $submitted ?: '—' );
			if ( '' !== $ip ) {
				self::render_detail_row( __( 'From IP', 'timeslate' ), $ip );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render one label/value row. `$html` indicates whether `$value`
	 * contains already-escaped HTML (status badge, mailto link) or
	 * plain text that still needs escaping.
	 */
	private static function render_detail_row( string $label, string $value, bool $html = false ): void {
		?>
		<div class="ts-details__row">
			<div class="ts-details__label"><?php echo esc_html( $label ); ?></div>
			<div class="ts-details__value">
				<?php
				if ( $html ) {
					echo wp_kses(
						$value,
						array(
							'a'    => array(
								'href'  => true,
								'title' => true,
							),
							'span' => array( 'class' => true ),
						)
					);
				} else {
					echo esc_html( $value );
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Sidebar Actions box — current status on top, then one button per
	 * available transition. Uses the same admin-post.php status-change
	 * handler as the list-view row actions so there's one code path for
	 * state changes.
	 */
	public static function render_actions_box( WP_Post $post ): void {
		$status      = (string) get_post_meta( $post->ID, '_ts_status', true ) ?: 'pending';
		$transitions = self::allowed_transitions( $status );

		?>
		<div class="ts-actions">
			<div class="ts-actions__current">
				<span class="ts-actions__current-label">
					<?php esc_html_e( 'Current status', 'timeslate' ); ?>
				</span>
				<span class="ts-status-badge ts-status-badge--<?php echo esc_attr( $status ); ?>">
					<?php echo esc_html( self::status_label( $status ) ); ?>
				</span>
			</div>

			<?php if ( empty( $transitions ) ) : ?>
				<p class="ts-actions__terminal">
					<?php esc_html_e( 'No further transitions from this state.', 'timeslate' ); ?>
				</p>
			<?php else : ?>
				<div class="ts-actions__buttons">
					<?php foreach ( $transitions as $to => $label ) : ?>
					<?php
						$is_destructive = in_array( $to, array( 'cancelled', 'no_show' ), true );
						$is_primary     = 'confirmed' === $to;
						$classes        = array( 'button', 'ts-actions__btn' );
						if ( $is_primary ) {
							$classes[] = 'button-primary';
						}
						if ( $is_destructive ) {
							$classes[] = 'ts-actions__btn--destructive';
						}
						?>
						<a
							href="<?php echo esc_url( self::status_url( $post->ID, $to ) ); ?>"
							class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
						>
							<?php echo esc_html( $label ); ?>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	// ---- Labels -------------------------------------------------------

	public static function status_label( string $status ): string {
		$labels = array(
			'pending'   => __( 'Pending', 'timeslate' ),
			'confirmed' => __( 'Confirmed', 'timeslate' ),
			'completed' => __( 'Completed', 'timeslate' ),
			'cancelled' => __( 'Cancelled', 'timeslate' ),
			'no_show'   => __( 'No-show', 'timeslate' ),
		);
		return $labels[ $status ] ?? ucfirst( str_replace( '_', ' ', $status ) );
	}
}
