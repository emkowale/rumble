<?php
if (!defined('ABSPATH')) exit;

add_action('admin_enqueue_scripts', function($hook){
  if ($hook !== 'toplevel_page_rumble') return;
  $ver = file_exists(RUMBLE_PATH.'assets/admin.css') ? filemtime(RUMBLE_PATH.'assets/admin.css') : '0.1.1';
  wp_enqueue_style('rumble-admin', RUMBLE_URL.'assets/admin.css', [], $ver);
  wp_enqueue_script('rumble-admin', RUMBLE_URL.'assets/admin.js', ['jquery'], $ver, true);
  if (function_exists('bumblebee_enqueue_create_assets')) {
    $bb_ver = file_exists(RUMBLE_PATH.'assets/bridge-bb.js') ? filemtime(RUMBLE_PATH.'assets/bridge-bb.js') : $ver;
    wp_enqueue_script('rumble-bb-bridge', RUMBLE_URL.'assets/bridge-bb.js', ['jquery'], $bb_ver, true);
  }
  wp_localize_script('rumble-admin', 'rumbleData', [
    'ajax' => admin_url('admin-ajax.php'),
    'nonce' => wp_create_nonce('rumble'),
    'bumblebee' => function_exists('bumblebee_enqueue_create_assets'),
  ]);
});

add_action('wp_enqueue_scripts', function(){
  if (rumble_is_kiosk_page()){
    $ver = file_exists(RUMBLE_PATH.'assets/front.css') ? filemtime(RUMBLE_PATH.'assets/front.css') : '0.1.1';
    $ver_js = file_exists(RUMBLE_PATH.'assets/front.js') ? filemtime(RUMBLE_PATH.'assets/front.js') : $ver;
    wp_enqueue_style('rumble-front', RUMBLE_URL.'assets/front.css', [], $ver);
    wp_enqueue_script('rumble-front', RUMBLE_URL.'assets/front.js', [], $ver_js, true);
    wp_localize_script('rumble-front', 'rumbleData', [
      'ajax' => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce('rumble'),
      'taxRate' => rumble_wc_base_tax_rate(),
    ]);
  }
  if (rumble_is_accept_page()){
    $ver = file_exists(RUMBLE_PATH.'assets/front.css') ? filemtime(RUMBLE_PATH.'assets/front.css') : '0.1.1';
    wp_enqueue_style('rumble-front', RUMBLE_URL.'assets/front.css', [], $ver);
  }
});
