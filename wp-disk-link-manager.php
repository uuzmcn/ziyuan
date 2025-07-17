<?php
/**
 * Plugin Name: WP Disk Link Manager
 * Plugin URI: https://yourwebsite.com/wp-disk-link-manager
 * Description: 从Excel导入数据并自动转存网盘链接的WordPress插件
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: wp-disk-link-manager
 * Domain Path: /languages
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

// 定义插件常量
define('WP_DISK_LINK_MANAGER_VERSION', '1.0.0');
define('WP_DISK_LINK_MANAGER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WP_DISK_LINK_MANAGER_PLUGIN_PATH', plugin_dir_path(__FILE__));

// 包含核心类文件
require_once WP_DISK_LINK_MANAGER_PLUGIN_PATH . 'includes/class-wp-disk-link-manager.php';
require_once WP_DISK_LINK_MANAGER_PLUGIN_PATH . 'includes/class-admin.php';
require_once WP_DISK_LINK_MANAGER_PLUGIN_PATH . 'includes/class-ajax.php';
require_once WP_DISK_LINK_MANAGER_PLUGIN_PATH . 'includes/class-excel-importer.php';
require_once WP_DISK_LINK_MANAGER_PLUGIN_PATH . 'includes/class-disk-manager.php';
require_once WP_DISK_LINK_MANAGER_PLUGIN_PATH . 'includes/class-logger.php';
require_once WP_DISK_LINK_MANAGER_PLUGIN_PATH . 'includes/class-cron.php';

// 插件激活钩子
register_activation_hook(__FILE__, array('WP_Disk_Link_Manager', 'activate'));

// 插件停用钩子
register_deactivation_hook(__FILE__, array('WP_Disk_Link_Manager', 'deactivate'));

// 插件卸载钩子
register_uninstall_hook(__FILE__, array('WP_Disk_Link_Manager', 'uninstall'));

// 初始化插件
function wp_disk_link_manager_init() {
    $wp_disk_link_manager = new WP_Disk_Link_Manager();
    $wp_disk_link_manager->init();
}
add_action('plugins_loaded', 'wp_disk_link_manager_init');