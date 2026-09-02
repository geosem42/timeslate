<?php
/**
 * Sent to the customer when the booking is confirmed — either
 * immediately (auto-approve on) or after the owner clicks "Confirm"
 * in the admin.
 *
 * @package Timeslate
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! empty( $intro_html ) ) {
	echo $intro_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

include __DIR__ . '/email-summary.php';

if ( ! empty( $outro_html ) ) {
	echo $outro_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
?>

<?php if ( ! empty( $cancel_url ) ) : ?>
<p style="margin:16px 0 0 0;font-size:13px;color:#646970;">
	<?php
	printf(
		/* translators: %s: cancel link. */
		esc_html__( "Plans changed? %s so we can offer the slot to someone else.", 'timeslate' ),
		'<a href="' . esc_url( (string) $cancel_url ) . '" style="color:#2271b1;">' . esc_html__( 'Cancel your booking', 'timeslate' ) . '</a>'
	);
	?>
</p>
<?php endif; ?>
