<?php
/**
 * Plugin Name: Inventory Management System
 * Description: Manage products, suppliers and stock movements with low-stock alerts. Includes front-end login, registration, OTP email verification, password reset, change password and profile pages, role-based access control, database-backed rate limiting, an authentication log and a REST API.
 * Version:     1.3.0
 * Author:      Hamza
 * Text Domain: ims
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IMS_VERSION', '1.3.0' );
define( 'IMS_DB_VERSION', '1.1' );
define( 'IMS_PATH', plugin_dir_path( __FILE__ ) );
define( 'IMS_URL', plugin_dir_url( __FILE__ ) );
define( 'IMS_CAP', 'ims_view_inventory' ); // Minimum capability to use the inventory at all.

require_once IMS_PATH . 'includes/class-ims-icons.php';
require_once IMS_PATH . 'includes/class-ims-db.php';
require_once IMS_PATH . 'includes/class-ims-security.php';
require_once IMS_PATH . 'includes/class-ims-mailer.php';
require_once IMS_PATH . 'includes/class-ims-admin.php';
require_once IMS_PATH . 'includes/class-ims-shortcode.php';
require_once IMS_PATH . 'includes/class-ims-auth.php';
require_once IMS_PATH . 'includes/class-ims-home.php';
require_once IMS_PATH . 'includes/class-ims-rest.php';

register_activation_hook(
	__FILE__,
	function () {
		IMS_DB::install();
		IMS_Security::install_roles();
	}
);
register_deactivation_hook( __FILE__, array( 'IMS_Security', 'deactivate' ) );

add_action( 'plugins_loaded', function () {
	if ( get_option( 'ims_db_version' ) !== IMS_DB_VERSION ) {
		IMS_DB::install();
	}
	IMS_Security::init();
	IMS_Mailer::init();
	new IMS_Admin();
	new IMS_Shortcode();
	new IMS_Auth();
	new IMS_Home();
	new IMS_REST();
} );
