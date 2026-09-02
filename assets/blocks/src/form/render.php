<?php
/**
 * Frontend render for the timeslate/form block.
 *
 * Emits a container div with the heading/subheading plus data-* config
 * that view.js reads on mount. Inside the container sits a <noscript>
 * fallback so visitors without JS get a useful message instead of an
 * empty box. React clobbers the noscript when it mounts.
 *
 * @package Timeslate
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sb_heading    = (string) ( $attributes['heading'] ?? '' );
$sb_subheading = (string) ( $attributes['subheading'] ?? '' );

$sb_wrapper = get_block_wrapper_attributes( array( 'class' => 'timeslate-form-wrap' ) );

$sb_rest_base = rest_url( Timeslate_REST::NAMESPACE_PATH );
$ts_max_people = (int) Timeslate_Options::get( 'max_people_online', 10 );
$sb_max_days  = (int) Timeslate_Options::get( 'advance_max_days', 60 );
?>
<div <?php echo $sb_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput -- wrapper pre-escaped by core ?>>
	<?php if ( '' !== $sb_heading ) : ?>
		<h2 class="timeslate-form-wrap__heading"><?php echo esc_html( $sb_heading ); ?></h2>
	<?php endif; ?>
	<?php if ( '' !== $sb_subheading ) : ?>
		<p class="timeslate-form-wrap__sub"><?php echo esc_html( $sb_subheading ); ?></p>
	<?php endif; ?>

	<div
		class="timeslate-form-container"
		data-rest-base="<?php echo esc_attr( $sb_rest_base ); ?>"
		data-max-people="<?php echo esc_attr( (string) $ts_max_people ); ?>"
		data-max-days="<?php echo esc_attr( (string) $sb_max_days ); ?>"
	>
		<noscript>
			<p><?php esc_html_e( 'This booking form requires JavaScript. Please call us directly to book.', 'timeslate' ); ?></p>
		</noscript>
	</div>
</div>
