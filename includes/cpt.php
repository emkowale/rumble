<?php
if (!defined('ABSPATH')) exit;

function rumble_register_cpt(){
  register_post_type('rumble_quote', [
    'labels' => [
      'name' => 'Rumble Quotes',
      'singular_name' => 'Rumble Quote',
    ],
    'public' => false,
    'show_ui' => true,
    'show_in_menu' => false,
    'supports' => ['title'],
  ]);
}

function rumble_register_order_statuses(){
  register_post_status('wc-rumble-draft', [
    'label' => 'Draft',
    'public' => false,
    'exclude_from_search' => false,
    'show_in_admin_all_list' => true,
    'show_in_admin_status_list' => true,
    'label_count' => _n_noop('Draft <span class="count">(%s)</span>', 'Draft <span class="count">(%s)</span>'),
  ]);

  register_post_status('wc-on-order', [
    'label' => 'On Order',
    'public' => true,
    'exclude_from_search' => false,
    'show_in_admin_all_list' => true,
    'show_in_admin_status_list' => true,
    'label_count' => _n_noop('On Order <span class="count">(%s)</span>', 'On Order <span class="count">(%s)</span>'),
  ]);
}

add_filter('wc_order_statuses', function($statuses){
  $next = [];
  foreach ($statuses as $status => $label) {
    if ($status === 'wc-pending') {
      $next['wc-rumble-draft'] = 'Draft';
      $next[$status] = 'Needs Invoicing';
      continue;
    }
    if ($status === 'wc-on-hold') {
      $next[$status] = 'Order Goods';
      $next['wc-on-order'] = 'On Order';
      continue;
    }
    $next[$status] = $label;
  }
  if (!isset($next['wc-rumble-draft'])) $next['wc-rumble-draft'] = 'Draft';
  if (!isset($next['wc-on-order'])) $next['wc-on-order'] = 'On Order';
  return $next;
});
