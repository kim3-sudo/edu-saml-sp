<?php
/**
 * Plugin Name:       EDU SAML SP
 * Plugin URI:        https://github.com/kim3-sudo/edu-saml-sp
 * Description:       Barebones SAML2 Service Provider for WordPress. Authenticates users against an external
 *                     Identity Provider (IdP), with configurable NameID/attribute mapping, JIT provisioning,
 *                     group-to-role sync, forced-SSO, and break-glass admin accounts.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Sejin Kim
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       edu-saml-sp
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EDU_SAML_SP_VERSION', '1.0.0' );
define( 'EDU_SAML_SP_FILE', __FILE__ );
define( 'EDU_SAML_SP_DIR', plugin_dir_path( __FILE__ ) );
define( 'EDU_SAML_SP_URL', plugin_dir_url( __FILE__ ) );
define( 'EDU_SAML_SP_OPTION_KEY', 'edu_saml_sp_options' );

/**
 * Composer autoloader for the bundled onelogin/php-saml library.
 *
 * This plugin does NOT ship a vendor/ directory. Run `composer install`
 * inside this plugin's directory before activating. See README.md.
 */
if ( file_exists( EDU_SAML_SP_DIR . 'vendor/autoload.php' ) ) {
	require_once EDU_SAML_SP_DIR . 'vendor/autoload.php';
}

require_once EDU_SAML_SP_DIR . 'includes/class-saml-settings.php';
require_once EDU_SAML_SP_DIR . 'includes/class-saml-sp.php';
require_once EDU_SAML_SP_DIR . 'includes/class-saml-provisioning.php';
require_once EDU_SAML_SP_DIR . 'includes/class-saml-breakglass.php';
require_once EDU_SAML_SP_DIR . 'includes/class-saml-auth-handler.php';
require_once EDU_SAML_SP_DIR . 'includes/class-saml-idp-metadata-importer.php';
require_once EDU_SAML_SP_DIR . 'includes/class-saml-admin-page.php';

/**
 * Verify that the SAML library is available. If not, show an admin notice
 * and refuse to run the SAML-dependent parts of the plugin (settings page
 * still loads so the admin can read setup instructions).
 */
function edu_saml_sp_library_missing() {
	return ! class_exists( '\OneLogin\Saml2\Auth' );
}

function edu_saml_sp_missing_library_notice() {
	if ( ! edu_saml_sp_library_missing() ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p><strong>EDU SAML SP:</strong> ';
	echo 'The onelogin/php-saml library was not found in <code>vendor/</code>. ';
	echo 'Run <code>composer install</code> inside the plugin directory to enable SAML login. ';
	echo 'See the plugin README.md for instructions.</p></div>';
}
add_action( 'admin_notices', 'edu_saml_sp_missing_library_notice' );

/**
 * Bootstrap the plugin.
 */
function edu_saml_sp_init() {
	EDU_SAML_Settings::instance();
	EDU_SAML_Admin_Page::instance();
	EDU_SAML_IdP_Metadata_Importer::instance();

	if ( ! edu_saml_sp_library_missing() ) {
		EDU_SAML_Auth_Handler::instance();
	}
}

add_action( 'plugins_loaded', 'edu_saml_sp_init' );

/**
 * Activation: seed default options if they do not exist yet.
 */
function edu_saml_sp_activate() {
	if ( false === get_option( EDU_SAML_SP_OPTION_KEY ) ) {
		update_option( EDU_SAML_SP_OPTION_KEY, EDU_SAML_Settings::get_defaults(), false );
	}
}
register_activation_hook( __FILE__, 'edu_saml_sp_activate' );
