<?php
/*
 * Version: 0.1.2
Plugin Name: Rumble
Description: Kiosk quoting for Bear Traxs with quote accept + product creation.
Author: Bear Traxs
Plugin URI: https://github.com/emkowale/rumble
Update URI: https://github.com/emkowale/rumble
Requires Plugins: bumblebee
*/

if (!defined('ABSPATH')) exit;



define('RUMBLE_VERSION', '0.1.2');
define('RUMBLE_PATH', plugin_dir_path(__FILE__));
define('RUMBLE_URL', plugin_dir_url(__FILE__));
define('RUMBLE_SLUG', plugin_basename(__FILE__));

require_once RUMBLE_PATH.'includes/roles.php';
require_once RUMBLE_PATH.'includes/cpt.php';
require_once RUMBLE_PATH.'includes/helpers.php';
require_once RUMBLE_PATH.'includes/logs.php';
require_once RUMBLE_PATH.'includes/freshbooks.php';
require_once RUMBLE_PATH.'includes/shipstation.php';
require_once RUMBLE_PATH.'includes/admin.php';
require_once RUMBLE_PATH.'includes/accept.php';
require_once RUMBLE_PATH.'includes/assets.php';
require_once RUMBLE_PATH.'includes/ajax.php';
require_once RUMBLE_PATH.'includes/email.php';
require_once RUMBLE_PATH.'includes/product.php';
require_once RUMBLE_PATH.'includes/woocommerce_bridge.php';
require_once RUMBLE_PATH.'includes/workorders.php';
require_once RUMBLE_PATH.'includes/reporting.php';
require_once RUMBLE_PATH.'includes/purchasing.php';
require_once RUMBLE_PATH.'includes/front.php';
require_once RUMBLE_PATH.'includes/bumblebee_bridge.php';
require_once RUMBLE_PATH.'includes/updater.php';

register_activation_hook(__FILE__, function(){
  rumble_register_roles();
  rumble_register_cpt();
  rumble_register_order_statuses();
  rumble_logs_install();
  rumble_purchasing_register_post_type();
  rumble_register_route();
  flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function(){ flush_rewrite_rules(); });

add_action('init', function(){ rumble_register_cpt(); });
add_action('init', function(){ rumble_register_order_statuses(); });
add_action('init', function(){ rumble_register_roles(); });

add_action('plugins_loaded', function(){
  if (defined('BUMBLEBEE_VERSION')) return;
  add_action('admin_notices', function(){
    echo '<div class="notice notice-error"><p><strong>Rumble</strong> requires the Bumblebee plugin to be installed and active.</p></div>';
  });
});
