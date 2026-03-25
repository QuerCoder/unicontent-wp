<?php
/**
 * Plugin Name: UNICONTENT AI Content Generator
 * Description: Generates product descriptions, SEO meta tags, and content for WordPress posts with bulk processing support.
 * Version: 0.2.7.7
 * Author: UNICONTENT
 * Author URI: https://unicontent.net
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: unicontent-ai-generator
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('UCG_VERSION')) {
    $ucg_plugin_data = get_file_data(__FILE__, ['Version' => 'Version']);
    define('UCG_VERSION', $ucg_plugin_data['Version']);
}

if (!defined('UCG_PLUGIN_FILE')) {
    define('UCG_PLUGIN_FILE', __FILE__);
}

if (!defined('UCG_PLUGIN_DIR')) {
    define('UCG_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

if (!defined('UCG_PLUGIN_URL')) {
    define('UCG_PLUGIN_URL', plugin_dir_url(__FILE__));
}

require_once UCG_PLUGIN_DIR . 'includes/class-ucg-updater.php';
require_once UCG_PLUGIN_DIR . 'includes/class-ucg-settings.php';
require_once UCG_PLUGIN_DIR . 'includes/class-ucg-i18n.php';
require_once UCG_PLUGIN_DIR . 'includes/class-ucg-db.php';
require_once UCG_PLUGIN_DIR . 'includes/class-ucg-api-client.php';
require_once UCG_PLUGIN_DIR . 'includes/class-ucg-tokens.php';
require_once UCG_PLUGIN_DIR . 'includes/class-ucg-generator.php';
require_once UCG_PLUGIN_DIR . 'includes/class-ucg-admin.php';
require_once UCG_PLUGIN_DIR . 'includes/class-ucg-activator.php';
require_once UCG_PLUGIN_DIR . 'includes/class-ucg-plugin.php';

register_activation_hook(__FILE__, array('UCG_Activator', 'activate'));
register_deactivation_hook(__FILE__, array('UCG_Activator', 'deactivate'));

if (!function_exists('ucg_run_plugin')) {
    function ucg_load_textdomain() {
        load_plugin_textdomain(
            'unicontent-ai-generator',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }

    function ucg_run_plugin() {
        if (!class_exists('UCG_Plugin')) {
            return;
        }

        $plugin = new UCG_Plugin();
        $plugin->run();
    }
}

add_action('plugins_loaded', 'ucg_load_textdomain');
if (class_exists('UCG_I18n')) {
    UCG_I18n::hooks();
}
ucg_run_plugin();

// Автообновления через unicontent.net (fallback: GitHub)
if (class_exists('UCG_Updater')) {
    new UCG_Updater(UCG_PLUGIN_FILE, UCG_VERSION);
}
