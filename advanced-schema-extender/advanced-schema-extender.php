<?php
/**
 * Plugin Name:       Advanced Schema Extender for Yoast
 * Plugin URI:        https://github.com/kinanumo/advanced-schema-extender
 * Description:       Extends your site schema graph with Organization enrichment, multi-location LocalBusiness support, and a per-post FAQ builder.
 * Version:           4.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Kendrick Omar Salting
 * Author URI:        https://neirdkc.xyz
 * License:           Custom (see README.md)
 * Text Domain:       advanced-schema-extender
 *
 * @package AdvancedSchemaExtender
 */

defined( 'ABSPATH' ) || exit;

define( 'ASE_VERSION', '4.0.0' );
define( 'ASE_FILE', __FILE__ );
define( 'ASE_DIR', plugin_dir_path( __FILE__ ) );
define( 'ASE_URL', plugin_dir_url( __FILE__ ) );
define( 'ASE_OPTION_KEY', 'advanced_schema_extender_settings' );
define( 'ASE_PAGE_SLUG', 'advanced-schema-extender' );

require_once ASE_DIR . 'class-ase-agency-ui.php';

/**
 * Bootstrap the plugin after dependencies have registered.
 *
 * @return void
 */
function ase_bootstrap(): void {
	if ( is_admin() && ! class_exists( 'WPSEO_Options' ) ) {
		add_action( 'admin_notices', 'ase_dependency_missing_notice' );
	}
	ASE_Agency_UI::instance();
}
add_action( 'plugins_loaded', 'ase_bootstrap' );

/**
 * Display the Yoast SEO dependency notice.
 *
 * @return void
 */
function ase_dependency_missing_notice(): void {
	echo '<div class="notice notice-warning"><p><strong>Advanced Schema Extender for Yoast</strong> requires <strong>Yoast SEO</strong> to actually output schema. Install or activate Yoast SEO to see merged graph results.</p></div>';
}
