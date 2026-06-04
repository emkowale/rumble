<?php
if (!defined('ABSPATH')) exit;

function rumble_accept_quote($post_id, $data){
  if (!class_exists('WC_Product_Simple')) return 'WooCommerce required.';
  $cat = rumble_company_term($data['customer']['company']);
  $products = [];
  foreach ($data['items'] as $item){
    $pid = !empty($item['product_id']) ? intval($item['product_id']) : 0;
    if(!$pid){
      $p = new WC_Product_Simple();
      $p->set_name($item['title']);
      $p->set_regular_price($item['price']);
      $p->set_tax_status($item['taxable']==='none' ? 'none' : 'taxable');
      if ($cat) $p->set_category_ids([$cat]);
      $p->set_short_description($item['notes']);
      $pid = $p->save();
      if ($item['vendor_codes']) update_post_meta($pid, '_rumble_vendor_codes', $item['vendor_codes']);
    }
    $products[] = ['id'=>$pid,'item'=>$item];
  }

  $order = wc_create_order(['customer_id'=>$data['user_id']]);
  $order->set_address(rumble_wc_addr($data['billing']), 'billing');
  $order->set_address(rumble_wc_addr($data['shipping']), 'shipping');
  foreach ($products as $prod){
    $qty = array_sum($prod['item']['quantities']) ?: 1;
    $order->add_product(wc_get_product($prod['id']), $qty);
  }
  $order->set_payment_method('cheque');
  $order->calculate_totals();
  $order->save();
  WC()->mailer()->get_emails()['WC_Email_Customer_Invoice']->trigger($order->get_id());

  $data['accepted'] = current_time('mysql');
  $data['order_id'] = $order->get_id();
  update_post_meta($post_id, '_rumble', $data);
  return 'Quote accepted. Invoice sent. Order #'.$order->get_id();
}

function rumble_company_term($company){
  $company = rumble_clean($company);
  if (!$company) return 0;
  $term = get_term_by('name', $company, 'product_cat');
  if ($term) return $term->term_id;
  $created = wp_insert_term($company, 'product_cat');
  return is_wp_error($created) ? 0 : ($created['term_id'] ?? 0);
}

function rumble_wc_addr($a){
  return [
    'first_name' => $a['first_name'] ?? '',
    'last_name' => $a['last_name'] ?? '',
    'company' => $a['company'] ?? '',
    'address_1' => $a['line1'] ?? '',
    'address_2' => $a['line2'] ?? '',
    'city' => $a['city'] ?? '',
    'state' => $a['state'] ?? '',
    'postcode' => $a['zip'] ?? '',
    'phone' => $a['phone'] ?? '',
    'email' => $a['email'] ?? '',
  ];
}
