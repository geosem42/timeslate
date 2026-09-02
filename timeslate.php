<?php
/**
 * Plugin Name:       Timeslate
 * Plugin URI:        https://logicvoid.dev/plugins/timeslate
 * Description:       Online booking for appointments, tables, classes and anything else you schedule. Set your hours and how many people you can take at once.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Tested up to:      7.1
 * Requires PHP:      8.1
 * Author:            George Semaan
 * Author URI:        https://logicvoid.dev
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       timeslate
 * Domain Path:       /languages
 *
 * @package Timeslate
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TIMESLATE_VERSION', '1.0.0' );
define( 'TIMESLATE_FILE', __FILE__ );
define( 'TIMESLATE_DIR', plugin_dir_path( __FILE__ ) );
define( 'TIMESLATE_URI', plugin_dir_url( __FILE__ ) );

require_once TIMESLATE_DIR . 'inc/helpers.php';
require_once TIMESLATE_DIR . 'inc/class-timeslate-options-schema.php';
require_once TIMESLATE_DIR . 'inc/class-timeslate-options.php';
require_once TIMESLATE_DIR . 'inc/class-timeslate-cpt.php';
require_once TIMESLATE_DIR . 'inc/class-timeslate-availability.php';
require_once TIMESLATE_DIR . 'inc/class-timeslate-settings-admin.php';
require_once TIMESLATE_DIR . 'inc/class-timeslate-rest.php';
require_once TIMESLATE_DIR . 'inc/class-timeslate-admin.php';
require_once TIMESLATE_DIR . 'inc/class-timeslate-emails.php';
require_once TIMESLATE_DIR . 'inc/class-timeslate-tokens.php';
require_once TIMESLATE_DIR . 'inc/blocks.php';

Timeslate_CPT::register();
Timeslate_Settings_Admin::register();
Timeslate_REST::register();
Timeslate_Admin::register();
Timeslate_Tokens::register();
