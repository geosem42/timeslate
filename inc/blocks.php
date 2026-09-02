<?php
/**
 * Block registration for the plugin.
 *
 * Registers the `timeslate` block category and auto-registers every
 * compiled block under assets/blocks/build/. The build directory is a
 * wp-scripts artifact; adding a new block means dropping its source
 * under assets/blocks/src/<slug>/ and running `npm run build`.
 *
 * The category is scoped to this plugin so our blocks stay grouped whether the
 * theme is active or not.
 *
 * @package Timeslate
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prepend the Timeslate block category.
 */
function timeslate_register_block_category( array $categories ): array {
	foreach ( $categories as $cat ) {
		if ( ( $cat['slug'] ?? '' ) === 'timeslate' ) {
			return $categories;
		}
	}
	return array_merge(
		array(
			array(
				'slug'  => 'timeslate',
				'title' => __( 'Timeslate', 'timeslate' ),
				'icon'  => null,
			),
		),
		$categories
	);
}
add_filter( 'block_categories_all', 'timeslate_register_block_category' );

/**
 * Register every compiled block directory under assets/blocks/build/.
 */
function timeslate_register_blocks(): void {
	$build_dir = TIMESLATE_DIR . 'assets/blocks/build';
	if ( ! is_dir( $build_dir ) ) {
		return;
	}

	$entries = scandir( $build_dir );
	if ( false === $entries ) {
		return;
	}

	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$block_path = $build_dir . '/' . $entry;
		if ( is_dir( $block_path ) && file_exists( $block_path . '/block.json' ) ) {
			register_block_type( $block_path );
		}
	}
}
add_action( 'init', 'timeslate_register_blocks' );
