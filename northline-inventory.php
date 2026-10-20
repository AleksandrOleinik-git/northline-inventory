<?php
/**
 * Plugin Name: Northline Inventory
 * Plugin URI:  https://github.com/AleksandrOleinik-git/northline-inventory
 * Description: Vehicle inventory for car dealers: custom post type, CSV import, VIN decoding via NHTSA, filterable catalogue, REST API and lead forms.
 * Version:     1.0.0
 * Author:      Aleksandr Oleinik
 * Author URI:  https://clientus.vip
 * License:     GPL-2.0-or-later
 * Text Domain: northline-inventory
 * Requires PHP: 8.0
 * Requires at least: 6.4
 */

defined( 'ABSPATH' ) || exit;

define( 'NL_INV_VERSION', '1.0.0' );
define( 'NL_INV_FILE', __FILE__ );
define( 'NL_INV_DIR', plugin_dir_path( __FILE__ ) );
define( 'NL_INV_URL', plugin_dir_url( __FILE__ ) );

require_once NL_INV_DIR . 'includes/class-post-types.php';
require_once NL_INV_DIR . 'includes/class-meta.php';
require_once NL_INV_DIR . 'includes/class-vin-decoder.php';
require_once NL_INV_DIR . 'includes/class-query.php';
require_once NL_INV_DIR . 'includes/class-importer.php';
require_once NL_INV_DIR . 'includes/class-admin.php';
require_once NL_INV_DIR . 'includes/class-rest.php';
require_once NL_INV_DIR . 'includes/class-leads.php';
require_once NL_INV_DIR . 'includes/template-functions.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once NL_INV_DIR . 'includes/class-cli.php';
}

add_action( 'plugins_loaded', static function () {
	NL_Post_Types::init();
	NL_Meta::init();
	NL_Admin::init();
	NL_Rest::init();
	NL_Leads::init();
} );

register_activation_hook( __FILE__, static function () {
	NL_Post_Types::register();
	NL_Leads::register();
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
