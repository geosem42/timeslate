<?php
/**
 * Sent to the customer after booking creation when auto-approve is OFF.
 * The intro / outro paragraphs come from the buyer-editable copy in
 * settings (or the shipped default when the buyer hasn't customized
 * anything); this template only arranges them around the summary and
 * the cancel link.
 *
 * @package Timeslate
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// $intro_html and $outro_html arrive pre-escaped + autop'd from the
// Emails class — trusted, output raw.
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
		esc_html__( 'Need to cancel? %s', 'timeslate' ),
		'<a href="' . esc_url( (string) $cancel_url ) . '" style="color:#2271b1;">' . esc_html__( 'Cancel this request', 'timeslate' ) . '</a>'
	);
	?>
</p>
<?php endif; ?>
