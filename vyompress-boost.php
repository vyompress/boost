<?php
/**
 * Plugin Name:       VyomPress Boost
 * Plugin URI:        https://github.com/vyompress/boost
 * Description:       Page caching, Cloudflare automation, and S3-compatible media offloading made simple.
 * Version:           0.5.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            VyomPress
 * Author URI:        https://vyompress.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vyompress-boost
 *
 * @package VyomPress\Boost
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VYOMPRESS_BOOST_VERSION', '0.5.0' );
define( 'VYOMPRESS_BOOST_FILE', __FILE__ );
define( 'VYOMPRESS_BOOST_PATH', plugin_dir_path( __FILE__ ) );
define( 'VYOMPRESS_BOOST_URL', plugin_dir_url( __FILE__ ) );

require_once VYOMPRESS_BOOST_PATH . 'src/Autoloader.php';

\VyomPress\Boost\Autoloader::register();

register_activation_hook( __FILE__, array( \VyomPress\Boost\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \VyomPress\Boost\Plugin::class, 'deactivate' ) );

\VyomPress\Boost\Plugin::instance()->boot();
