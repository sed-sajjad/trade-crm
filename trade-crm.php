<?php
/**
 * Plugin Name: CRM بازرگان
 * Description: مدیریت مخاطبان، سوابق ارتباط و پیگیری‌های تجاری.
 * Version: 1.1.3
 * Requires at least: 6.8
 * Requires PHP: 8.1
 * Text Domain: trade-crm
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'TRADE_CRM_VERSION', '1.1.3' );
define( 'TRADE_CRM_DB_VERSION', '1.1.0' );
define( 'TRADE_CRM_FILE', __FILE__ );
define( 'TRADE_CRM_DIR', plugin_dir_path( __FILE__ ) );
define( 'TRADE_CRM_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( TRADE_CRM_DIR . 'vendor/autoload.php' ) ) {
	require_once TRADE_CRM_DIR . 'vendor/autoload.php';
}

require_once TRADE_CRM_DIR . 'includes/class-tables.php';
require_once TRADE_CRM_DIR . 'includes/class-normalizer.php';
require_once TRADE_CRM_DIR . 'includes/class-industry-catalog.php';
require_once TRADE_CRM_DIR . 'includes/class-phone-numbers.php';
require_once TRADE_CRM_DIR . 'includes/class-repository.php';
require_once TRADE_CRM_DIR . 'includes/class-app.php';
require_once TRADE_CRM_DIR . 'includes/class-pwa.php';
require_once TRADE_CRM_DIR . 'includes/class-rest.php';
require_once TRADE_CRM_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'TradeCRM\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'TradeCRM\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', static function (): void {
	( new TradeCRM\Plugin() )->boot();
}, 20 );
