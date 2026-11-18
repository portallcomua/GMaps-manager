<?php
/**
 * Plugin Name: GMaps Client Manager
 * Description: Робота менеджерів із картками клієнтів на Google Maps: пошук, створення й редагування картки, попередній перегляд змін, доступ клієнта, оплати й комісії.
 * Version: 2.0.0
 * Author: UAserver
 * Text Domain: gmaps-client-manager
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;

define('GCM_VERSION', '2.0.0');
define('GCM_DB_VERSION', '2.0');
define('GCM_FILE', __FILE__);
define('GCM_DIR', plugin_dir_path(__FILE__));
define('GCM_URL', plugin_dir_url(__FILE__));

require_once GCM_DIR . 'includes/class-gcm-google.php';
require_once GCM_DIR . 'includes/class-gcm-core.php';
require_once GCM_DIR . 'includes/class-gcm-publisher.php';
require_once GCM_DIR . 'includes/class-gcm-admin.php';
require_once GCM_DIR . 'includes/class-gcm-front.php';

register_activation_hook(__FILE__, array('GCM_Core', 'activate'));

GCM_Core::instance();
GCM_Admin::instance();
GCM_Front::instance();
