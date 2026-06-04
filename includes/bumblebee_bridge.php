<?php
if (!defined('ABSPATH')) exit;

function rumble_bumblebee_available(){
  return function_exists('bumblebee_enqueue_create_assets') && function_exists('bumblebee_render_create_page');
}

function rumble_bumblebee_vendors(){
  $vendors = [];

  if (function_exists('bumblebee_hub_get_vendors')) {
    $hub_vendors = bumblebee_hub_get_vendors();
    if (!is_wp_error($hub_vendors) && is_array($hub_vendors)) $vendors = $hub_vendors;
  }

  if (!$vendors && defined('BEE_OPT_APPROVED_VENDORS')) {
    $approved = get_option(BEE_OPT_APPROVED_VENDORS, []);
    if (is_array($approved)) $vendors = $approved;
  }

  $clean = [];
  foreach ($vendors as $vendor) {
    if (!is_array($vendor)) continue;
    $name = rumble_clean($vendor['name'] ?? '');
    $code = rumble_clean($vendor['code'] ?? '');
    if ($name === '' && $code === '') continue;
    $clean[] = [
      'name' => $name ?: $code,
      'code' => $code,
    ];
  }

  usort($clean, function($a, $b){ return strcasecmp($a['name'], $b['name']); });
  return $clean;
}

function rumble_render_bumblebee_form(){
  if (!rumble_bumblebee_available()) return;
  bumblebee_enqueue_create_assets();
  $filter = function($caps, $cap){ if($cap==='manage_woocommerce') $caps[$cap]=true; return $caps; };
  add_filter('user_has_cap', $filter, 10, 2);
  ob_start();
  bumblebee_render_create_page();
  echo '<div class="rumble-bb-note">After creating, use "Add to Quote" to attach the product.</div>';
  echo ob_get_clean();
  remove_filter('user_has_cap', $filter, 10);
}

add_action('wp_ajax_rumble_bumblebee_create', function(){
  if (!rumble_bumblebee_available()) wp_send_json_error(['message'=>'Bumblebee is required.'], 400);
  $_POST['nonce'] = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
  $_POST['action'] = 'bumblebee_create_product';
  $req = bumblebee_parse_create_request();
  $result = bumblebee_build_product($req);
  wp_send_json_success([
    'product_id' => $result['product_id'],
    'title' => $req['title'],
    'price' => floatval($req['price']),
    'taxable' => $req['tax_status'] !== 'none',
    'production' => $req['production'],
  ]);
});
