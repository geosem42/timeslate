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

$ts_heading    = (string) ( $attributes['heading'] ?? '' );
$ts_subheading = (string) ( $attributes['subheading'] ?? '' );

$ts_wrapper = get_block_wrapper_attributes( array( 'class' => 'timeslate-form-wrap' ) );

$ts_rest_base = rest_url( Timeslate_REST::NAMESPACE_PATH );
$ts_max_people = (int) Timeslate_Options::get( 'max_people_online', 10 );
$ts_max_days  = (int) Timeslate_Options::get( 'advance_max_days', 60 );
?>
<div <?php echo $ts_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput -- wrapper pre-escaped by core ?>>
	<?php if ( '' !== $ts_heading ) : ?>
		<h2 class="timeslate-form-wrap__heading"><?php echo esc_html( $ts_heading ); ?></h2>
	<?php endif; ?>
	<?php if ( '' !== $ts_subheading ) : ?>
		<p class="timeslate-form-wrap__sub"><?php echo esc_html( $ts_subheading ); ?></p>
	<?php endif; ?>

	<div
		class="timeslate-form-container"
		data-rest-base="<?php echo esc_attr( $ts_rest_base ); ?>"
		data-max-people="<?php echo esc_attr( (string) $ts_max_people ); ?>"
		data-max-days="<?php echo esc_attr( (string) $ts_max_days ); ?>"
	>
		<noscript>
			<p><?php esc_html_e( 'This booking form requires JavaScript. Please call us directly to book.', 'timeslate' ); ?></p>
		</noscript>
	</div>
</div>
