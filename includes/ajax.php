<?php
if (!defined('ABSPATH')) exit;

add_action('wp_ajax_rumble_save_quote', 'rumble_handle_save_quote');
add_action('wp_ajax_rumble_save_new_order', 'rumble_handle_save_new_order');
add_action('wp_ajax_rumble_search', 'rumble_handle_search');
add_action('wp_ajax_rumble_customer_lookup', 'rumble_handle_customer_lookup');
add_action('wp_ajax_rumble_add_order_note', 'rumble_handle_add_order_note');
add_action('wp_ajax_rumble_update_order_status', 'rumble_handle_update_order_status');
add_action('wp_ajax_rumble_update_order_timeline', 'rumble_handle_update_order_timeline');

function rumble_handle_save_quote(){
  check_ajax_referer('rumble', 'nonce');
  if (!rumble_current_user_can_access()) wp_send_json_error(['message'=>'Unauthorized'], 403);
  $raw = $_POST['payload'] ?? '';
  $payload = json_decode(stripslashes($raw), true);
  if (!$payload || empty($payload['items'])) wp_send_json_error(['message'=>'Missing data'], 400);

  $cust = array_map('rumble_clean', [
    'first_name'=>$payload['customer']['first_name'] ?? '',
    'last_name'=>$payload['customer']['last_name'] ?? '',
    'phone'=>$payload['customer']['phone'] ?? '',
    'email'=>$payload['customer']['email'] ?? '',
    'company'=>$payload['customer']['company'] ?? '',
  ]);
  if (!is_email($cust['email'])) wp_send_json_error(['message'=>'Email required'], 400);

  $user = get_user_by('email', $cust['email']);
  if (!$user){
    $user_id = wp_insert_user([
      'user_login' => sanitize_user($cust['email']),
      'user_email' => $cust['email'],
      'first_name' => $cust['first_name'],
      'last_name'  => $cust['last_name'],
      'role'       => 'customer',
      'user_pass'  => wp_generate_password(),
    ]);
    if (is_wp_error($user_id)) wp_send_json_error(['message'=>'User creation failed'], 500);
    $user = get_user_by('id', $user_id);
  }
  $items = [];
  foreach ($payload['items'] as $item){
    $items[] = [
      'product_id' => isset($item['product_id']) ? intval($item['product_id']) : 0,
      'title' => rumble_clean($item['title'] ?? ''),
      'price' => floatval($item['price'] ?? 0),
      'taxable' => $item['taxable']==='none' ? 'none' : 'taxable',
      'colors' => array_filter(array_map('rumble_clean', explode(',', $item['colors'] ?? ''))),
      'sizes' => array_filter(array_map('rumble_clean', explode(',', $item['sizes'] ?? ''))),
      'quantities' => rumble_parse_pairs($item['quantities'] ?? ''),
      'vendor_codes' => array_filter(array_map('rumble_clean', explode("\n", $item['vendor_codes'] ?? ''))),
      'production' => rumble_clean($item['production'] ?? ''),
      'locations' => array_filter(array_map('rumble_clean', explode(',', $item['locations'] ?? ''))),
      'notes' => rumble_clean($item['notes'] ?? ''),
    ];
  }

  $data = [
    'customer' => $cust,
    'billing' => array_map('rumble_clean', $payload['billing'] ?? []),
    'shipping' => array_map('rumble_clean', $payload['shipping'] ?? []),
    'items' => $items,
    'token' => wp_generate_password(20, false),
    'expires' => time() + DAY_IN_SECONDS * 30,
    'user_id' => $user->ID,
    'status' => 'pending',
  ];
  $data['billing']['first_name'] = $cust['first_name'];
  $data['billing']['last_name'] = $cust['last_name'];
  $data['billing']['company'] = $cust['company'];
  $data['billing']['phone'] = $cust['phone'];
  $data['billing']['email'] = $cust['email'];
  $data['shipping']['first_name'] = $cust['first_name'];
  $data['shipping']['last_name'] = $cust['last_name'];
  $data['shipping']['company'] = $cust['company'];
  $data['shipping']['phone'] = $cust['phone'];
  $data['shipping']['email'] = $cust['email'];

  $title = trim(($cust['company'] ?: 'Quote').' - '.$cust['first_name'].' '.$cust['last_name']);
  $post_id = wp_insert_post([
    'post_type' => 'rumble_quote',
    'post_status' => 'publish',
    'post_title' => $title,
  ], true);
  if (is_wp_error($post_id)) wp_send_json_error(['message'=>'Unable to save quote'], 500);

  update_post_meta($post_id, '_rumble', $data);
  update_post_meta($post_id, '_rumble_token', $data['token']);
  rumble_send_quote_email($post_id, $data);

  wp_send_json(['success'=>true,'message'=>'Quote saved and sent.','edit_url'=>get_edit_post_link($post_id, 'raw')]);
}

function rumble_handle_search(){
  check_ajax_referer('rumble', 'nonce');
  if (!rumble_current_user_can_access()) wp_send_json_error(['message'=>'Unauthorized'], 403);
  if (!function_exists('rumble_dashboard_search')) wp_send_json_error(['message'=>'Search unavailable.'], 500);

  $term = rumble_clean($_GET['term'] ?? $_POST['term'] ?? '');
  wp_send_json_success([
    'results' => rumble_dashboard_search($term),
  ]);
}

function rumble_handle_customer_lookup(){
  check_ajax_referer('rumble', 'nonce');
  if (!rumble_current_user_can_access()) wp_send_json_error(['message'=>'Unauthorized'], 403);
  if (!function_exists('wc_get_order')) wp_send_json_error(['message'=>'WooCommerce required.'], 500);

  $term = rumble_clean($_GET['term'] ?? $_POST['term'] ?? '');
  if (strlen($term) < 2) wp_send_json_success(['results' => []]);

  $order_ids = rumble_customer_lookup_order_ids($term, 20);
  $results = [];
  $seen = [];
  foreach ($order_ids as $order_id) {
    $order = wc_get_order($order_id);
    if (!$order instanceof WC_Order) continue;

    $company = rumble_clean($order->get_billing_company());
    $first = rumble_clean($order->get_billing_first_name());
    $last = rumble_clean($order->get_billing_last_name());
    $key = strtolower(trim($company.'|'.$first.'|'.$last));
    if ($key === '||' || isset($seen[$key])) continue;
    $seen[$key] = true;

    $payload = json_decode((string) $order->get_meta('_rumble_new_order_payload'), true);
    $payload = is_array($payload) ? $payload : [];

    $results[] = [
      'order_id' => $order->get_id(),
      'order_number' => $order->get_order_number(),
      'label' => trim(($company ?: trim($first.' '.$last)).' - #'.$order->get_order_number()),
      'company' => $company,
      'buyer_first' => $first,
      'buyer_last' => $last,
      'billing_street' => rumble_clean($order->get_billing_address_1()),
      'billing_city' => rumble_clean($order->get_billing_city()),
      'billing_state' => rumble_clean($order->get_billing_state()),
      'billing_zip' => rumble_clean($order->get_billing_postcode()),
      'phone' => rumble_clean($order->get_billing_phone()),
      'email' => sanitize_email($order->get_billing_email()),
      'same_as_billing' => rumble_clean($payload['same_as_billing'] ?? ''),
    ];
    if (count($results) >= 8) break;
  }

  wp_send_json_success(['results' => $results]);
}

function rumble_customer_lookup_order_ids(string $term, int $limit = 20): array {
  global $wpdb;
  $like = '%' . $wpdb->esc_like($term) . '%';
  $ids = [];

  $legacy_ids = $wpdb->get_col($wpdb->prepare(
    "SELECT DISTINCT post_id
     FROM {$wpdb->postmeta}
     WHERE meta_key IN ('_billing_company', '_billing_first_name', '_billing_last_name')
       AND meta_value LIKE %s
     ORDER BY post_id DESC
     LIMIT %d",
    $like,
    max(1, $limit * 3)
  ));
  foreach ((array) $legacy_ids as $id) {
    $ids[(int) $id] = (int) $id;
  }

  $addresses_table = $wpdb->prefix . 'wc_order_addresses';
  $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $addresses_table));
  if ($table_exists === $addresses_table) {
    $hpos_ids = $wpdb->get_col($wpdb->prepare(
      "SELECT DISTINCT order_id
       FROM {$addresses_table}
       WHERE address_type = 'billing'
         AND (company LIKE %s OR first_name LIKE %s OR last_name LIKE %s)
       ORDER BY order_id DESC
       LIMIT %d",
      $like,
      $like,
      $like,
      max(1, $limit * 3)
    ));
    foreach ((array) $hpos_ids as $id) {
      $ids[(int) $id] = (int) $id;
    }
  }

  return array_slice(array_values(array_filter($ids)), 0, $limit);
}

function rumble_handle_add_order_note(){
  check_ajax_referer('rumble', 'nonce');
  if (!rumble_current_user_can_access()) wp_send_json_error(['message'=>'Unauthorized'], 403);
  if (!function_exists('wc_get_order')) wp_send_json_error(['message'=>'WooCommerce required.'], 500);

  $order_id = absint($_POST['order_id'] ?? 0);
  $order = $order_id ? wc_get_order($order_id) : false;
  if (!$order) wp_send_json_error(['message'=>'Order not found.'], 404);

  $content = trim(wp_strip_all_tags((string) ($_POST['note'] ?? '')));
  if ($content === '') wp_send_json_error(['message'=>'Note is required.'], 400);

  $is_customer_note = ($_POST['note_type'] ?? 'private') === 'customer';
  $note_id = $order->add_order_note($content, $is_customer_note, true);
  if (!$note_id) wp_send_json_error(['message'=>'Unable to add note.'], 500);

  $notes = function_exists('wc_get_order_notes') ? wc_get_order_notes(['order_id' => $order_id, 'limit' => 1, 'orderby' => 'date_created', 'order' => 'DESC']) : [];
  $note = $notes ? $notes[0] : null;

  wp_send_json_success([
    'message' => 'Note added.',
    'note' => [
      'type' => $is_customer_note ? 'Customer note' : 'Private note',
      'date' => $note && $note->date_created ? $note->date_created->date_i18n('M j, Y g:i a') : current_time('M j, Y g:i a'),
      'content' => $content,
      'customer_note' => $is_customer_note,
    ],
  ]);
}

function rumble_handle_update_order_status(){
  check_ajax_referer('rumble', 'nonce');
  if (!rumble_current_user_can_access()) wp_send_json_error(['message'=>'Unauthorized'], 403);
  if (!function_exists('wc_get_order')) wp_send_json_error(['message'=>'WooCommerce required.'], 500);

  $order_id = absint($_POST['order_id'] ?? 0);
  $order = $order_id ? wc_get_order($order_id) : false;
  if (!$order) wp_send_json_error(['message'=>'Order not found.'], 404);

  $status = sanitize_key($_POST['status'] ?? '');
  $allowed = function_exists('rumble_order_status_options') ? array_keys(rumble_order_status_options()) : ['rumble-draft', 'pending', 'on-hold', 'on-order', 'processing', 'completed'];
  if (!in_array($status, $allowed, true)) wp_send_json_error(['message'=>'Invalid status.'], 400);

  $needs_design = !empty($_POST['needs_design']);
  $order->set_status($status);
  $order->update_meta_data('_rumble_needs_design', $needs_design ? '1' : '');
  $order->update_meta_data('_rumble_intake_state', $status === 'rumble-draft' ? 'draft' : 'created');
  $order->save();

  wp_send_json_success([
    'message' => 'Status updated.',
    'status' => $order->get_status(),
    'status_label' => function_exists('rumble_order_status_label') ? rumble_order_status_label($order->get_status()) : wc_get_order_status_name($order->get_status()),
    'needs_design' => $needs_design,
  ]);
}

function rumble_handle_update_order_timeline(){
  check_ajax_referer('rumble', 'nonce');
  if (!rumble_current_user_can_access()) wp_send_json_error(['message'=>'Unauthorized'], 403);
  if (!function_exists('wc_get_order')) wp_send_json_error(['message'=>'WooCommerce required.'], 500);

  $order_id = absint($_POST['order_id'] ?? 0);
  $order = $order_id ? wc_get_order($order_id) : false;
  if (!$order) wp_send_json_error(['message'=>'Order not found.'], 404);

  $production_date = rumble_sanitize_date_value($_POST['production_date'] ?? '');
  $needed_by = rumble_sanitize_date_value($_POST['needed_by'] ?? '');
  $event_date = rumble_sanitize_date_value($_POST['event_date'] ?? '');

  $order->update_meta_data('_rumble_production_date', $production_date);
  $order->update_meta_data('_rumble_needed_by', $needed_by);
  $order->update_meta_data('_rumble_event_date', $event_date);

  $payload = json_decode((string) $order->get_meta('_rumble_new_order_payload'), true);
  if (is_array($payload)) {
    $payload['production_date'] = $production_date;
    $payload['needed_by'] = $needed_by;
    $payload['event_date'] = $event_date;
    $order->update_meta_data('_rumble_new_order_payload', wp_json_encode($payload));
  }

  $order->save();

  wp_send_json_success([
    'message' => 'Timeline updated.',
    'production_date' => $production_date,
    'needed_by' => $needed_by,
    'event_date' => $event_date,
  ]);
}

function rumble_sanitize_date_value($value){
  $value = rumble_clean($value);
  if ($value === '') return '';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return '';
  [$year, $month, $day] = array_map('intval', explode('-', $value));
  return checkdate($month, $day, $year) ? $value : '';
}

function rumble_handle_save_new_order(){
  check_ajax_referer('rumble', 'nonce');
  if (!rumble_current_user_can_access()) wp_send_json_error(['message'=>'Unauthorized'], 403);
  if (!function_exists('wc_create_order')) wp_send_json_error(['message'=>'WooCommerce required.'], 500);

  $raw = $_POST['payload'] ?? '';
  $payload = json_decode(stripslashes($raw), true);
  if (!is_array($payload)) wp_send_json_error(['message'=>'Missing order data.'], 400);

  $requested_intent = (string) ($payload['intent'] ?? '');
  $missing_requirements = rumble_new_order_missing_requirements($payload);
  $intent = $requested_intent === 'draft' ? 'draft' : (empty($missing_requirements) ? 'create' : 'draft');
  $existing_order_id = absint($payload['woocommerce_order_number'] ?? 0);
  $order = $existing_order_id ? wc_get_order($existing_order_id) : false;
  $is_new_order = !$order;
  if (!$order) {
    $order = wc_create_order(['created_via' => 'rumble_new_order']);
    if (is_wp_error($order)) wp_send_json_error(['message'=>'Unable to create WooCommerce order.'], 500);
  }

  rumble_apply_new_order_payload($order, $payload, $intent, $is_new_order);

  wp_send_json_success([
    'message' => $intent === 'draft' ? 'Draft saved.' : 'Order created.',
    'missing_requirements' => $intent === 'draft' ? $missing_requirements : [],
    'order_id' => $order->get_id(),
    'order_date' => rumble_order_created_date_value($order),
    'edit_url' => method_exists($order, 'get_edit_order_url') ? $order->get_edit_order_url() : admin_url('post.php?post='.$order->get_id().'&action=edit'),
    'status' => $order->get_status(),
  ]);
}

function rumble_apply_new_order_payload($order, $payload, $intent, $is_new_order = false){
  $submitted_order_date = rumble_clean($payload['order_date'] ?? '');
  $order_date = $submitted_order_date;
  if ($is_new_order && !$order_date) $order_date = current_time('Y-m-d');
  if (!$order_date) $order_date = rumble_order_created_date_value($order);
  $payload['order_date'] = $order_date;
  if (!rumble_clean($payload['entered_by'] ?? '')) {
    $payload['entered_by'] = rumble_clean($order->get_meta('_rumble_entered_by')) ?: rumble_current_user_first_name();
  }

  $customer = [
    'first_name' => rumble_clean($payload['buyer_first'] ?? ''),
    'last_name' => rumble_clean($payload['buyer_last'] ?? ''),
    'company' => rumble_clean($payload['company'] ?? ''),
    'phone' => rumble_clean($payload['phone'] ?? ''),
    'email' => sanitize_email($payload['email'] ?? ''),
  ];

  if ($customer['email'] && is_email($customer['email'])) {
    $user = get_user_by('email', $customer['email']);
    if ($user) $order->set_customer_id($user->ID);
  }

  $billing = [
    'first_name' => $customer['first_name'],
    'last_name' => $customer['last_name'],
    'company' => $customer['company'],
    'address_1' => rumble_clean($payload['billing_street'] ?? ''),
    'city' => rumble_clean($payload['billing_city'] ?? ''),
    'state' => rumble_clean($payload['billing_state'] ?? ''),
    'postcode' => rumble_clean($payload['billing_zip'] ?? ''),
    'phone' => $customer['phone'],
    'email' => $customer['email'],
  ];
  $ship_to = rumble_clean($payload['ship_to'] ?? '');
  $shipping = [
    'first_name' => $customer['first_name'],
    'last_name' => $customer['last_name'],
    'company' => $ship_to ?: $customer['company'],
    'address_1' => rumble_clean($payload['shipping_street'] ?? ''),
    'city' => rumble_clean($payload['shipping_city'] ?? ''),
    'state' => rumble_clean($payload['shipping_state'] ?? ''),
    'postcode' => rumble_clean($payload['shipping_zip'] ?? ''),
    'phone' => $customer['phone'],
  ];
  $order->set_address($billing, 'billing');
  $order->set_address($shipping, 'shipping');

  $order->remove_order_items();
  $items = rumble_new_order_items($payload['items'] ?? []);
  foreach ($items as $item) {
    $order_item = new WC_Order_Item_Product();
    $order_item->set_name($item['name']);
    $order_item->set_quantity($item['qty']);
    $order_item->set_subtotal($item['subtotal']);
    $order_item->set_total($item['subtotal']);
    if (!empty($item['taxes'])) $order_item->set_taxes(['subtotal' => $item['taxes'], 'total' => $item['taxes']]);
    foreach ($item['meta'] as $key => $value) {
      if ($value !== '') $order_item->add_meta_data($key, $value, true);
    }
    $order->add_item($order_item);
  }

  rumble_add_fee_item($order, 'Art / Setup', rumble_money($payload['art_setup_total'] ?? 0));
  rumble_add_fee_item($order, 'Rush Fee', rumble_money($payload['rush_fee'] ?? 0));
  rumble_add_shipping_item($order, rumble_money($payload['shipping_fee'] ?? 0));

  $order->set_payment_method('cheque');
  $order->set_customer_note(rumble_clean($payload['notes'] ?? ''));
  $order->update_meta_data('_rumble_new_order_payload', wp_json_encode($payload));
  $order->update_meta_data('_rumble_production_date', rumble_clean($payload['production_date'] ?? $order->get_meta('_rumble_production_date')));
  $order->update_meta_data('_rumble_needed_by', rumble_clean($payload['needed_by'] ?? ''));
	  $order->update_meta_data('_rumble_event_date', rumble_clean($payload['event_date'] ?? ''));
	  $order->update_meta_data('_rumble_entered_by', rumble_clean($payload['entered_by'] ?? ''));
	  $order->update_meta_data('_rumble_outside_sales', rumble_clean($payload['outside_sales'] ?? ''));
	  $order->update_meta_data('_rumble_inside_sales', rumble_clean($payload['inside_sales'] ?? ''));
	  $order->update_meta_data('_rumble_designer', rumble_clean($payload['designer'] ?? ''));
	  $order->update_meta_data('_rumble_delivery_notes', rumble_clean($payload['delivery_notes'] ?? ''));
  $order->update_meta_data('_rumble_hard_deadline_reason', rumble_clean($payload['hard_deadline_reason'] ?? ''));
  $order->update_meta_data('_rumble_deposit', rumble_money($payload['deposit'] ?? 0));
  $order->update_meta_data('_rumble_balance_due', rumble_money($payload['balance_due'] ?? 0));
  $order->update_meta_data('_rumble_buyer_signature', rumble_clean($payload['buyer_signature'] ?? ''));
  $order->update_meta_data('_rumble_signature_date', rumble_clean($payload['signature_date'] ?? ''));
  $order->update_meta_data('_rumble_intake_state', $intent === 'draft' ? 'draft' : 'created');

  if ($is_new_order || $submitted_order_date) $order->set_date_created($order_date.' 00:00:00');

  $order->calculate_totals(false);
  $grand_total = rumble_money($payload['grand_total'] ?? 0);
  if ($grand_total > 0) $order->set_total($grand_total);
  $current_status = $order->get_status();
  $draft_statuses = [rumble_draft_order_status(), 'checkout-draft', 'draft'];
  if ($intent === 'draft') {
    $order->set_status(rumble_draft_order_status());
  } elseif ($is_new_order || in_array($current_status, $draft_statuses, true)) {
    $order->set_status('pending');
  }
  $order->save();
}

function rumble_new_order_payload_is_complete($payload){
  return empty(rumble_new_order_missing_requirements($payload));
}

function rumble_new_order_missing_requirements($payload){
  $missing = [];
  $company = rumble_clean($payload['company'] ?? '');
  $first = rumble_clean($payload['buyer_first'] ?? '');
  $last = rumble_clean($payload['buyer_last'] ?? '');
  $phone = rumble_clean($payload['phone'] ?? '');
  $email = sanitize_email($payload['email'] ?? '');
  $needed_by = rumble_clean($payload['needed_by'] ?? '');

  if (!$company && (!$first || !$last)) $missing[] = 'Customer company or buyer first and last name';
  if (!$needed_by) $missing[] = 'In Hands Date';
  if (!$phone && (!$email || !is_email($email))) $missing[] = 'Phone or valid email';

  $items = $payload['items'] ?? [];
  if (!is_array($items)) {
    $missing[] = 'At least one item with description or item code, quantity, and price';
    return $missing;
  }

  $has_complete_item = false;
  foreach ($items as $item) {
    if (!is_array($item)) continue;
    $name = rumble_clean(($item['description'] ?? '') ?: (($item['vendor_code'] ?? '') . ' ' . ($item['item_code'] ?? '')));
    $qty = absint($item['qty'] ?? 0);
    $price = rumble_money($item['price_each'] ?? 0);
    if ($name && $qty > 0 && $price > 0) {
      $has_complete_item = true;
      break;
    }
  }

  if (!$has_complete_item) $missing[] = 'At least one item with description or item code, quantity, and price';
  return $missing;
}

function rumble_order_created_date_value($order){
  $created = $order && method_exists($order, 'get_date_created') ? $order->get_date_created() : null;
  return $created ? $created->date_i18n('Y-m-d') : current_time('Y-m-d');
}

function rumble_traxs_vendor_code($vendor_code, $item_code){
  $vendor_code = rumble_clean($vendor_code);
  $item_code = rumble_clean($item_code);
  if ($vendor_code !== '' && preg_match('/^([^(]+)\(([^)]+)\)/', $vendor_code)) return $vendor_code;
  if ($vendor_code !== '' && $item_code !== '') return sprintf('%s(%s)', $vendor_code, $item_code);
  return $vendor_code ?: $item_code;
}

function rumble_new_order_items($raw_items){
  $items = [];
  if (!is_array($raw_items)) return $items;

  foreach ($raw_items as $raw) {
    if (!is_array($raw)) continue;
    $qty = absint($raw['qty'] ?? 0);
    $price = rumble_money($raw['price_each'] ?? 0);
    $description = rumble_clean($raw['description'] ?? '');
    $vendor = rumble_clean($raw['vendor'] ?? '');
    $vendor_code = rumble_clean($raw['vendor_code'] ?? '');
    $item_code = rumble_clean($raw['item_code'] ?? '');
    $vendor_item = rumble_traxs_vendor_code($vendor_code, $item_code);
    if (!$qty && !$price && !$description && !$vendor_code) continue;
    if (!$qty) $qty = 1;
    $subtotal = $price * $qty;
    $taxes = !empty($raw['sales_tax']) && class_exists('WC_Tax') ? WC_Tax::calc_tax($subtotal, rumble_wc_base_tax_rates(), false) : [];
    $tax_total = array_sum($taxes);

    $sizes = [];
    foreach (['s' => 'S', 'm' => 'M', 'l' => 'L', 'xl' => 'XL', 'other' => 'Other'] as $key => $label) {
      $size_qty = absint($raw[$key] ?? 0);
      if ($size_qty) $sizes[] = $label.': '.$size_qty;
    }

    $items[] = [
      'name' => $description ?: ($vendor_item ?: 'Rumble order item'),
      'qty' => $qty,
      'subtotal' => $subtotal,
      'taxes' => $taxes,
      'meta' => [
        'Vendor' => $vendor,
        'Vendor Code' => $vendor_item,
        'vendor_code' => $vendor_item,
        'Item Code' => $item_code,
        'Vendor Item' => $vendor_item,
        'Color' => rumble_clean($raw['color'] ?? ''),
        'Sizes' => implode(', ', $sizes),
        'Decor Method' => rumble_clean($raw['decor_method'] ?? ''),
        'Price Each' => $price ? wc_format_decimal($price, wc_get_price_decimals()) : '',
        'Sales Tax' => $tax_total > 0 ? wc_format_decimal($tax_total, wc_get_price_decimals()) : '',
      ],
    ];
  }

  return $items;
}

function rumble_add_fee_item($order, $name, $amount){
  if ($amount <= 0) return;
  $fee = new WC_Order_Item_Fee();
  $fee->set_name($name);
  $fee->set_amount($amount);
  $fee->set_total($amount);
  $order->add_item($fee);
}

function rumble_add_shipping_item($order, $amount){
  if ($amount <= 0) return;
  $shipping = new WC_Order_Item_Shipping();
  $shipping->set_method_title('Shipping');
  $shipping->set_method_id('rumble_shipping');
  $shipping->set_total($amount);
  $order->add_item($shipping);
}

function rumble_money($value){
  return (float) preg_replace('/[^0-9.\-]/', '', (string) $value);
}

function rumble_draft_order_status(){
  return 'rumble-draft';
}
