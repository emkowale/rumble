<?php
if (!defined('ABSPATH')) exit;

define('RUMBLE_PURCHASING_DIR', RUMBLE_PATH . 'includes/purchasing/');
define('RUMBLE_PURCHASING_URL', RUMBLE_URL . 'assets/purchasing/');
define('RUMBLE_PURCHASING_VERSION', RUMBLE_VERSION . '.purchasing');

function rumble_purchasing_current_user_is_eric(): bool {
  if (!is_user_logged_in()) return false;

  $user = wp_get_current_user();
  if (!$user || !$user->exists()) return false;

  $first_name = strtolower(trim((string) get_user_meta($user->ID, 'first_name', true)));
  $login = strtolower(trim((string) $user->user_login));
  $display = strtolower(trim((string) $user->display_name));
  $email_local = strtolower(trim((string) strtok((string) $user->user_email, '@')));

  return $login === 'eric'
    || $email_local === 'eric'
    || $first_name === 'eric'
    || preg_match('/^eric\b/', $display) === 1;
}

function rumble_purchasing_load_legacy(): void {
  $files = [
    'class-eject-cpt.php' => 'Eject_CPT',
    'class-eject-service.php' => 'Eject_Service',
    'class-eject-admin.php' => 'Eject_Admin',
    'class-eject-ajax.php' => 'Eject_Ajax',
  ];

  foreach ($files as $file => $class) {
    if (!class_exists($class)) {
      require_once RUMBLE_PURCHASING_DIR . $file;
    }
  }
}

function rumble_purchasing_register_post_type(): void {
  rumble_purchasing_load_legacy();
  if (class_exists('Eject_CPT')) {
    Eject_CPT::register();
  }
}

function rumble_purchasing_register_ajax(): void {
  rumble_purchasing_load_legacy();
  if (class_exists('Eject_Ajax')) {
    Eject_Ajax::register();
  }
}

function rumble_purchasing_hide_traxs_menu(): void {
  remove_menu_page('eject');
}

function rumble_purchasing_render_content(): void {
  if (!rumble_purchasing_current_user_is_eric()) {
    wp_die('You do not have permission to view this page.');
  }

  rumble_purchasing_load_legacy();
  if (!class_exists('Eject_Admin')) {
    wp_die('Purchasing is unavailable.');
  }

  ob_start();
  Eject_Admin::render_page();
  $html = ob_get_clean();
  $html = str_replace('Traxs – Vendor Purchase Orders', 'Purchasing', $html);
  $html = str_replace('Traxs: Purchase Orders', 'Purchasing', $html);
  echo $html;
}

function rumble_purchasing_is_frontend_page(): bool {
  $path = trim((string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
  return $path === 'rumble/purchasing' || ((bool) get_query_var('rumble') && get_query_var('rumble_screen') === 'purchasing');
}

function rumble_purchasing_enqueue_frontend_assets(): void {
  if (!rumble_purchasing_current_user_is_eric()) {
    return;
  }

  if (!rumble_purchasing_is_frontend_page()) {
    return;
  }

  $css_path = RUMBLE_PATH . 'assets/purchasing/admin.css';
  $js_path = RUMBLE_PATH . 'assets/purchasing/admin.js';
  $css_ver = file_exists($css_path) ? filemtime($css_path) : RUMBLE_PURCHASING_VERSION;
  $js_ver = file_exists($js_path) ? filemtime($js_path) : RUMBLE_PURCHASING_VERSION;

  wp_enqueue_style('rumble-purchasing-admin', RUMBLE_PURCHASING_URL . 'admin.css', [], $css_ver);
  wp_enqueue_script('rumble-purchasing-admin', RUMBLE_PURCHASING_URL . 'admin.js', ['jquery'], $js_ver, true);
  wp_localize_script('rumble-purchasing-admin', 'TRAXS_BACKEND', [
    'nonce' => wp_create_nonce('eject_admin'),
    'ajax_url' => admin_url('admin-ajax.php'),
  ]);
}

add_action('init', 'rumble_purchasing_register_post_type');
add_action('init', 'rumble_purchasing_register_ajax');
add_action('admin_menu', 'rumble_purchasing_hide_traxs_menu', 999);
add_action('wp_enqueue_scripts', 'rumble_purchasing_enqueue_frontend_assets');
