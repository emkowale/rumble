<?php
if (!defined('ABSPATH')) exit;

function rumble_clean($text){
  return trim(wp_strip_all_tags((string)$text));
}

function rumble_parse_pairs($text){
  $pairs = [];
  foreach (explode(',', (string)$text) as $chunk){
    if(strpos($chunk, ':')===false) continue;
    [$k,$v] = array_map('trim', explode(':', $chunk, 2));
    if($k==='') continue;
    $pairs[$k] = is_numeric($v) ? (int)$v : $v;
  }
  return $pairs;
}

function rumble_is_accept_page(){
  return isset($_GET['rumble_accept']);
}

function rumble_is_kiosk_page(){
  if ((bool) get_query_var('rumble')) return true;
  $path = trim((string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
  return in_array($path, ['rumble', 'rumble/new-order', 'rumble/job-details', 'rumble/order-details', 'rumble/status-details', 'rumble/work-orders', 'rumble/reporting', 'rumble/purchasing'], true);
}

function rumble_current_user_first_name(){
  $user = wp_get_current_user();
  if (!$user || !$user->exists()) return '';
  $first_name = rumble_clean(get_user_meta($user->ID, 'first_name', true));
  return $first_name ?: rumble_clean($user->display_name ?: $user->user_login);
}

function rumble_current_user_can_access(){
  if (!is_user_logged_in()) return false;
  if (current_user_can('rumble_access')) return true;
  $user = wp_get_current_user();
  $roles = $user && $user->exists() ? (array) $user->roles : [];
  foreach ($roles as $role) {
    if (strpos((string) $role, 'employee') !== false) return true;
  }
  return $user && $user->exists() && get_user_meta($user->ID, '_rumble_employee', true) === '1';
}

function rumble_current_user_can_reporting(){
  if (!is_user_logged_in()) return false;
  if (!current_user_can('manage_options')) return false;
  if (current_user_can('rumble_access')) return true;
  $user = wp_get_current_user();
  $roles = $user && $user->exists() ? (array) $user->roles : [];
  foreach ($roles as $role) {
    if (strpos((string) $role, 'employee') !== false) return true;
  }
  return $user && $user->exists() && get_user_meta($user->ID, '_rumble_employee', true) === '1';
}

function rumble_logo_url(){
  if (defined('RUMBLE_LOGO_URL') && RUMBLE_LOGO_URL) return RUMBLE_LOGO_URL;
  return content_url('uploads/2025/05/The-Bear-Traxs-Logo.png');
}

function rumble_wc_base_tax_rates(){
  if (!class_exists('WC_Tax')) return [];
  return WC_Tax::get_base_tax_rates();
}

function rumble_wc_base_tax_total($amount){
  $amount = (float) $amount;
  if ($amount <= 0 || !class_exists('WC_Tax')) return 0.0;
  return array_sum(WC_Tax::calc_tax($amount, rumble_wc_base_tax_rates(), false));
}

function rumble_wc_base_tax_rate(){
  if (!class_exists('WC_Tax')) return 0.0;
  $tax = rumble_wc_base_tax_total(100);
  return $tax > 0 ? $tax / 100 : 0.0;
}
