<?php
if (!defined('ABSPATH')) exit;

add_action('admin_post_rumble_generate_workorders_pdf', 'rumble_handle_generate_workorders_pdf');
add_action('admin_post_rumble_generate_packing_slips_pdf', 'rumble_handle_generate_packing_slips_pdf');
add_action('admin_post_rumble_reprint_workorder_pdf', 'rumble_handle_reprint_workorder_pdf');
add_action('admin_post_rumble_blank_workorder_pdf', 'rumble_handle_blank_workorder_pdf');

function rumble_workorders_can_print(){
  return is_user_logged_in() && rumble_current_user_can_access();
}

function rumble_workorders_check_request(){
  if (!rumble_workorders_can_print()) wp_die('Permission denied.');
  $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field((string) $_POST['_wpnonce']) : '';
  if (!wp_verify_nonce($nonce, 'rumble_workorders')) wp_die('Bad nonce.');
}

function rumble_workorders_load_pdf(){
  $cache = trailingslashit(wp_normalize_path(WP_CONTENT_DIR.'/uploads/rumble-tcpdf-cache'));
  if (!file_exists($cache)) {
    wp_mkdir_p($cache);
  }
  if (!defined('K_PATH_CACHE')) {
    define('K_PATH_CACHE', $cache);
  }
  if (!defined('K_CURLOPTS')) {
    define('K_CURLOPTS', []);
  }
  if (class_exists('TCPDF')) return true;
  $path = WP_PLUGIN_DIR.'/traxs/includes/traxs-backend/lib/tcpdf.php';
  if (file_exists($path)) {
    require_once $path;
  }
  return class_exists('TCPDF');
}

function rumble_handle_generate_workorders_pdf(){
  rumble_workorders_check_request();
  if (!function_exists('wc_get_orders')) wp_die('WooCommerce required.');

  $order_numbers = rumble_workorders_parse_order_numbers($_POST['order_numbers'] ?? '');
  $statuses = rumble_workorders_statuses($_POST['statuses'] ?? 'processing,on-hold,on-order');
  $campaign = sanitize_title((string) ($_POST['campaign'] ?? ''));
  $include_printed = !empty($_POST['include_printed']);
  $combine_popup = !empty($_POST['combine_popup']);
  $combine_order_numbers = !empty($_POST['combine_order_numbers']);

  if ($order_numbers) {
    $orders = rumble_workorders_find_orders_by_numbers($order_numbers);
    if (!$orders) wp_die('No matching order numbers found.');
    $missing = rumble_workorders_missing_order_numbers($order_numbers, $orders);
    if ($missing) wp_die('Order number(s) not found: '.esc_html(implode(', ', $missing)));
  } else {
    $orders = rumble_workorders_find_orders($statuses, $include_printed);
  }
  if (!$order_numbers && $campaign !== '') {
    $orders = array_values(array_filter($orders, function($order) use ($campaign){
      return rumble_workorders_order_matches_campaign($order, $campaign);
    }));
  }
  if (!$orders) wp_die('No matching work orders found.');

  $jobs = [];
  if ($order_numbers) {
    if ($combine_order_numbers) {
      $jobs[] = rumble_workorders_batch_job($campaign ?: 'listed-orders', $orders);
    } else {
      foreach ($orders as $order) $jobs[] = rumble_workorders_single_job($order);
    }
  } elseif ($combine_popup) {
    if ($campaign !== '') {
      $jobs[] = rumble_workorders_batch_job($campaign, $orders);
    } else {
      $jobs = rumble_workorders_auto_jobs($orders);
    }
  } else {
    foreach ($orders as $order) $jobs[] = rumble_workorders_single_job($order);
  }

  rumble_workorders_mark_printed($orders, $campaign);
  rumble_workorders_output_pdf($jobs, $order_numbers ? 'rumble-listed-work-orders' : 'rumble-work-orders');
}

function rumble_handle_reprint_workorder_pdf(){
  rumble_workorders_check_request();
  if (!function_exists('wc_get_order')) wp_die('WooCommerce required.');
  $lookup = trim((string) ($_POST['lookup'] ?? ''));
  if ($lookup === '') wp_die('Order number or campaign slug is required.');

  if (!empty($_POST['campaign'])) {
    $campaign = sanitize_title($lookup);
    $orders = rumble_workorders_find_orders(['processing', 'completed', 'on-hold', 'on-order'], true);
    $orders = array_values(array_filter($orders, function($order) use ($campaign){
      return rumble_workorders_order_matches_campaign($order, $campaign);
    }));
    if (!$orders) wp_die('No matching campaign orders found.');
    rumble_workorders_mark_printed($orders, $campaign);
    rumble_workorders_output_pdf([rumble_workorders_batch_job($campaign, $orders)], 'work-order-'.$campaign);
  }

  $order = wc_get_order(absint($lookup));
  if (!$order) wp_die('Order not found.');
  rumble_workorders_mark_printed([$order]);
  rumble_workorders_output_pdf([rumble_workorders_single_job($order)], 'work-order-'.$order->get_order_number());
}

function rumble_handle_generate_packing_slips_pdf(){
  rumble_workorders_check_request();
  if (!function_exists('wc_get_orders')) wp_die('WooCommerce required.');

  $order_numbers = rumble_workorders_parse_order_numbers($_POST['order_numbers'] ?? '');
  if (!$order_numbers) wp_die('Order numbers are required.');

  $orders = rumble_workorders_find_orders_by_numbers($order_numbers);
  if (!$orders) wp_die('No matching order numbers found.');
  $missing = rumble_workorders_missing_order_numbers($order_numbers, $orders);
  if ($missing) wp_die('Order number(s) not found: '.esc_html(implode(', ', $missing)));

  rumble_workorders_output_packing_slips_pdf($orders, 'rumble-packing-slips');
}

function rumble_handle_blank_workorder_pdf(){
  rumble_workorders_check_request();
  rumble_workorders_output_blank_order_form_pdf('blank-order-form');
}

function rumble_workorders_statuses($raw){
  $statuses = array_values(array_filter(array_map('sanitize_key', preg_split('/[\s,]+/', (string) $raw))));
  return $statuses ?: ['processing', 'on-hold', 'on-order'];
}

function rumble_workorders_parse_order_numbers($raw){
  $numbers = preg_split('/[\s,;]+/', (string) $raw);
  $numbers = array_map(function($number){
    return trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '', (string) $number));
  }, is_array($numbers) ? $numbers : []);
  return array_values(array_unique(array_filter($numbers)));
}

function rumble_workorders_find_orders_by_numbers(array $numbers){
  $wanted = array_fill_keys(array_map('strtolower', $numbers), true);
  $orders = wc_get_orders([
    'limit' => -1,
    'status' => array_keys(wc_get_order_statuses()),
    'orderby' => 'date',
    'order' => 'ASC',
  ]);
  $matched = [];
  foreach ($orders as $order) {
    if (!$order instanceof WC_Order) continue;
    $candidates = [
      (string) $order->get_id(),
      (string) $order->get_order_number(),
    ];
    foreach ($candidates as $candidate) {
      if (isset($wanted[strtolower($candidate)])) {
        $matched[$candidate] = $order;
        break;
      }
    }
  }

  $ordered = [];
  foreach ($numbers as $number) {
    $needle = strtolower($number);
    foreach ($matched as $order) {
      if (!$order instanceof WC_Order) continue;
      if (strtolower((string) $order->get_id()) === $needle || strtolower((string) $order->get_order_number()) === $needle) {
        $ordered[] = $order;
        break;
      }
    }
  }
  return $ordered;
}

function rumble_workorders_missing_order_numbers(array $numbers, array $orders){
  $found = [];
  foreach ($orders as $order) {
    if (!$order instanceof WC_Order) continue;
    $found[strtolower((string) $order->get_id())] = true;
    $found[strtolower((string) $order->get_order_number())] = true;
  }
  $missing = [];
  foreach ($numbers as $number) {
    if (!isset($found[strtolower((string) $number)])) $missing[] = $number;
  }
  return $missing;
}

function rumble_workorders_find_orders(array $statuses, bool $include_printed){
  $orders = wc_get_orders([
    'limit' => -1,
    'status' => $statuses,
    'orderby' => 'date',
    'order' => 'ASC',
  ]);
  if ($include_printed) return $orders;
  return array_values(array_filter($orders, function($order){
    return $order instanceof WC_Order && !get_post_meta($order->get_id(), 'traxs_workorder_printed', true);
  }));
}

function rumble_workorders_auto_jobs(array $orders){
  $buckets = [];
  foreach ($orders as $order) {
    $signature = rumble_workorders_order_signature($order);
    $buckets[$signature][] = $order;
  }

  $jobs = [];
  foreach ($buckets as $signature => $bucket) {
    if (count($bucket) > 1 && $signature !== 'single') {
      $jobs[] = rumble_workorders_batch_job('batch-'.$signature, $bucket);
    } else {
      foreach ($bucket as $order) $jobs[] = rumble_workorders_single_job($order);
    }
  }
  return $jobs;
}

function rumble_workorders_order_signature(WC_Order $order){
  $lines = rumble_workorders_lines_for_order($order);
  if (!$lines) return 'single';
  $line = $lines[0];
  $parts = [
    $line['vendor_code'] ?? '',
    $line['vendor_item_code'] ?? '',
    $line['product_name'] ?? '',
    $line['production'] ?? '',
  ];
  $signature = sanitize_title(implode('-', array_filter(array_map('strval', $parts))));
  return $signature ?: 'single';
}

function rumble_workorders_single_job(WC_Order $order){
  $created = $order->get_date_created();
  return [
    'type' => 'single',
    'number' => (string) $order->get_order_number(),
    'title' => 'Work Order #'.$order->get_order_number(),
    'date' => $created ? $created->date_i18n(get_option('date_format')) : '',
    'items' => rumble_workorders_group_lines(rumble_workorders_lines_for_order($order)),
    'packing' => [rumble_workorders_packing_row($order)],
    'billing' => rumble_workorders_address($order, 'billing'),
    'shipping' => rumble_workorders_address($order, 'shipping'),
  ];
}

function rumble_workorders_batch_job(string $label, array $orders){
  $lines = [];
  $packing = [];
  $dates = [];
  foreach ($orders as $order) {
    if (!$order instanceof WC_Order) continue;
    $lines = array_merge($lines, rumble_workorders_lines_for_order($order));
    $packing[] = rumble_workorders_packing_row($order);
    $created = $order->get_date_created();
    if ($created) $dates[] = $created->date_i18n(get_option('date_format'));
  }
  $number = strtoupper(sanitize_title($label));
  return [
    'type' => 'batch',
    'number' => $number,
    'title' => 'Popup Store Work Order',
    'date' => rumble_workorders_date_range($dates),
    'items' => rumble_workorders_group_lines($lines),
    'packing' => $packing,
    'billing' => "Campaign: ".$number."\nSource Orders: ".count($orders),
    'shipping' => rumble_workorders_order_numbers($orders),
  ];
}

function rumble_workorders_lines_for_order(WC_Order $order){
  if (class_exists('Eject_Service')) {
    try {
      return Eject_Service::lines_for_order($order, []);
    } catch (Throwable $e) {
      error_log('[Rumble workorders] Falling back to local line parser for order '.$order->get_id().': '.$e->getMessage());
    }
  }

  $lines = [];
  foreach ($order->get_items('line_item') as $item_id => $item) {
    if (!$item instanceof WC_Order_Item_Product) continue;
    $lines[] = [
      'order_id' => (int) $order->get_id(),
      'order_number' => (string) $order->get_order_number(),
      'item_id' => (int) $item_id,
      'product_name' => $item->get_name(),
      'vendor_item_code' => (string) ($item->get_meta('Vendor Item', true) ?: $item->get_meta('Vendor Code', true)),
      'vendor_code' => (string) ($item->get_meta('Vendor Code', true) ?: $item->get_meta('vendor_code', true)),
      'production' => (string) ($item->get_meta('Production', true) ?: $item->get_meta('Decor Method', true)),
      'special_instructions' => (string) $item->get_meta('Special Instructions for production', true),
      'color' => (string) ($item->get_meta('Color', true) ?: $item->get_meta('pa_color', true)),
      'size' => (string) ($item->get_meta('Size', true) ?: $item->get_meta('pa_size', true) ?: $item->get_meta('Sizes', true)),
      'qty' => max(1, (int) $item->get_quantity()),
    ];
  }
  return $lines;
}

function rumble_workorders_group_lines(array $lines){
  $items = [];
  foreach ($lines as $line) {
    $key = md5(strtolower(implode('|', [
      $line['vendor_code'] ?? '',
      $line['vendor_item_code'] ?? '',
      $line['product_name'] ?? '',
      $line['production'] ?? '',
      $line['special_instructions'] ?? '',
      $line['color'] ?? '',
      $line['size'] ?? '',
    ])));
    if (!isset($items[$key])) {
      $items[$key] = [
        'product' => $line['product_name'] ?? '',
        'vendor_code' => $line['vendor_code'] ?? '',
        'color' => trim((string) ($line['color'] ?? '')) ?: 'N/A',
        'size' => trim((string) ($line['size'] ?? '')) ?: 'N/A',
        'qty' => 0,
        'production' => $line['production'] ?? '',
        'instructions' => $line['special_instructions'] ?? '',
      ];
    }
    $items[$key]['qty'] += max(1, (int) ($line['qty'] ?? 0));
  }
  return array_values($items);
}

function rumble_workorders_packing_row(WC_Order $order){
  $customer = trim($order->get_shipping_company()) ?: trim($order->get_formatted_shipping_full_name()) ?: trim($order->get_billing_company()) ?: trim($order->get_formatted_billing_full_name());
  $items = [];
  foreach ($order->get_items('line_item') as $item) {
    if (!$item instanceof WC_Order_Item_Product) continue;
    $bits = [$item->get_name()];
    $color = trim((string) ($item->get_meta('Color', true) ?: $item->get_meta('pa_color', true)));
    $size = trim((string) ($item->get_meta('Size', true) ?: $item->get_meta('pa_size', true) ?: $item->get_meta('Sizes', true)));
    if ($color !== '') $bits[] = $color;
    if ($size !== '') $bits[] = $size;
    $items[] = implode(' / ', $bits).' x '.max(1, (int) $item->get_quantity());
  }
  return [
    'order' => (string) $order->get_order_number(),
    'customer' => $customer,
    'address' => rumble_workorders_address($order, 'shipping'),
    'items' => $items,
  ];
}

function rumble_workorders_address(WC_Order $order, string $type){
  $method = 'get_formatted_'.$type.'_address';
  $address = method_exists($order, $method) ? (string) $order->$method() : '';
  $address = str_replace(["<br/>", "<br>", "<br />"], "\n", $address);
  $address = trim(wp_strip_all_tags($address));
  if ($type === 'billing') {
    $phone = trim((string) $order->get_billing_phone());
    $email = trim((string) $order->get_billing_email());
    if ($phone !== '') $address .= "\nPhone: ".$phone;
    if ($email !== '') $address .= "\nEmail: ".$email;
  }
  return trim($address);
}

function rumble_workorders_order_matches_campaign(WC_Order $order, string $campaign){
  foreach (['_rumble_campaign', '_rumble_campaign_slug', '_popup_store', '_popup_store_slug', 'campaign', 'campaign_slug', 'popup_store'] as $key) {
    if (rumble_workorders_token_match((string) $order->get_meta($key, true), $campaign)) return true;
  }
  foreach ($order->get_items('line_item') as $item) {
    if (!$item instanceof WC_Order_Item_Product) continue;
    $candidates = [$item->get_name(), $item->get_meta('Campaign', true), $item->get_meta('Popup Store', true)];
    $product = $item->get_product();
    if ($product instanceof WC_Product) {
      $candidates[] = $product->get_slug();
      $candidates[] = $product->get_sku();
      $terms = wp_get_post_terms($product->get_id(), ['product_cat', 'product_tag'], ['fields' => 'slugs']);
      if (!is_wp_error($terms)) $candidates = array_merge($candidates, $terms);
    }
    foreach ($candidates as $candidate) {
      if (rumble_workorders_token_match((string) $candidate, $campaign)) return true;
    }
  }
  return false;
}

function rumble_workorders_token_match(string $candidate, string $needle){
  $candidate = strtolower(sanitize_title($candidate));
  $needle = strtolower(sanitize_title($needle));
  if ($candidate === '' || $needle === '') return false;
  return strpos($candidate, $needle) !== false || strpos(str_replace('-', '', $candidate), str_replace('-', '', $needle)) !== false;
}

function rumble_workorders_mark_printed(array $orders, string $campaign = ''){
  foreach ($orders as $order) {
    if (!$order instanceof WC_Order) continue;
    update_post_meta($order->get_id(), 'traxs_workorder_printed', 'yes');
    if ($campaign !== '') update_post_meta($order->get_id(), 'rumble_popup_workorder', sanitize_title($campaign));
    $order->add_order_note($campaign ? 'Rumble: popup store work order printed for '.sanitize_title($campaign) : 'Rumble: work order printed', false, true);
  }
}

function rumble_workorders_output_pdf(array $jobs, string $filename){
  if (!rumble_workorders_load_pdf()) wp_die('PDF engine unavailable.');
  $pdf = new TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);
  $pdf->SetCreator('Rumble');
  $pdf->SetAuthor('The Bear Traxs');
  $pdf->SetTitle('Work Orders');
  $pdf->SetMargins(6.35, 6.35, 6.35);
  $pdf->SetAutoPageBreak(false);
  $pdf->setPrintHeader(false);
  $pdf->setPrintFooter(false);

  foreach ($jobs as $job) {
    rumble_workorders_render_job($pdf, $job);
  }

  $tmp = tempnam(sys_get_temp_dir(), 'rumble_wo_');
  if ($tmp === false) wp_die('Unable to create PDF.');
  $pdf->Output($tmp, 'F');
  if (function_exists('ob_get_length') && ob_get_length()) @ob_end_clean();
  header('Content-Type: application/pdf');
  header('Content-Disposition: inline; filename="'.sanitize_file_name($filename).'.pdf"');
  header('Content-Length: '.filesize($tmp));
  readfile($tmp);
  @unlink($tmp);
  exit;
}

function rumble_workorders_output_packing_slips_pdf(array $orders, string $filename){
  if (!rumble_workorders_load_pdf()) wp_die('PDF engine unavailable.');
  $pdf = new TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);
  $pdf->SetCreator('Rumble');
  $pdf->SetAuthor('The Bear Traxs');
  $pdf->SetTitle('Packing Slips');
  $pdf->SetMargins(6.35, 6.35, 6.35);
  $pdf->SetAutoPageBreak(false);
  $pdf->setPrintHeader(false);
  $pdf->setPrintFooter(false);

  $total = count($orders);
  foreach ($orders as $index => $order) {
    if ($order instanceof WC_Order) {
      rumble_workorders_render_packing_slip($pdf, $order, $index + 1, $total);
    }
  }

  $tmp = tempnam(sys_get_temp_dir(), 'rumble_pack_');
  if ($tmp === false) wp_die('Unable to create PDF.');
  $pdf->Output($tmp, 'F');
  if (function_exists('ob_get_length') && ob_get_length()) @ob_end_clean();
  header('Content-Type: application/pdf');
  header('Content-Disposition: inline; filename="'.sanitize_file_name($filename).'.pdf"');
  header('Content-Length: '.filesize($tmp));
  readfile($tmp);
  @unlink($tmp);
  exit;
}

function rumble_workorders_output_blank_order_form_pdf(string $filename){
  if (!rumble_workorders_load_pdf()) wp_die('PDF engine unavailable.');
  $pdf = new TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);
  $pdf->SetCreator('Rumble');
  $pdf->SetAuthor('The Bear Traxs');
  $pdf->SetTitle('Blank Order Form');
  $pdf->SetMargins(7, 7, 7);
  $pdf->SetAutoPageBreak(false);
  $pdf->setPrintHeader(false);
  $pdf->setPrintFooter(false);
  $pdf->AddPage();
  rumble_workorders_render_blank_order_form($pdf);

  $tmp = tempnam(sys_get_temp_dir(), 'rumble_order_form_');
  if ($tmp === false) wp_die('Unable to create PDF.');
  $pdf->Output($tmp, 'F');
  if (function_exists('ob_get_length') && ob_get_length()) @ob_end_clean();
  header('Content-Type: application/pdf');
  header('Content-Disposition: inline; filename="'.sanitize_file_name($filename).'.pdf"');
  header('Content-Length: '.filesize($tmp));
  readfile($tmp);
  @unlink($tmp);
  exit;
}

function rumble_workorders_render_job(TCPDF $pdf, array $job){
  $start = $pdf->getNumPages() + 1;
  $pdf->AddPage();
  rumble_workorders_render_header($pdf, $job);
  if (($job['type'] ?? '') === 'blank') {
    rumble_workorders_render_blank_body($pdf);
  } else {
    rumble_workorders_render_items($pdf, $job['items'] ?? []);
    $pdf->Ln(5);
    rumble_workorders_render_packing($pdf, $job['packing'] ?? []);
  }
  $end = $pdf->getNumPages();
  $current = $pdf->getPage();
  for ($page = $start, $i = 1; $page <= $end; $page++, $i++) {
    $pdf->setPage($page);
    $pdf->SetY(-10);
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->Cell(0, 6, 'Work Order #'.($job['number'] ?? '').' Page '.$i.' of '.($end - $start + 1), 0, 0, 'C');
  }
  $pdf->setPage($current);
}

function rumble_workorders_render_packing_slip(TCPDF $pdf, WC_Order $order, int $page_number, int $total_pages){
  $pdf->AddPage();
  $m = $pdf->getMargins();
  $order_number = (string) $order->get_order_number();
  $created = $order->get_date_created();
  $date = $created ? $created->date_i18n(get_option('date_format')) : '';

  $pdf->SetFont('dejavusans', 'B', 12);
  $pdf->SetXY($m['left'], $m['top']);
  $pdf->MultiCell(0, 5, "The Bear Traxs\nPacking Slip #".$order_number, 0, 'L', false, 1);
  $pdf->SetFont('dejavusans', '', 10);
  if ($date !== '') $pdf->MultiCell(0, 5, 'Date: '.rumble_workorders_pdf_text($date), 0, 'L', false, 1);

  $logo = function_exists('rumble_logo_url') ? rumble_logo_url() : '';
  if ($logo) {
    try {
      @$pdf->Image($logo, ($pdf->getPageWidth() - 25.4) / 2, $m['top'], 25.4, 25.4);
    } catch (Throwable $e) {
      error_log('[Rumble packing slips] Logo failed to render: '.$e->getMessage());
    }
  }
  $qr = home_url('/rumble/order-details/?order_id='.$order->get_id());
  $pdf->write2DBarcode($qr, 'QRCODE,H', $pdf->getPageWidth() - $m['right'] - 25.4, $m['top'], 25.4, 25.4, ['border'=>0,'padding'=>0,'fgcolor'=>[0,0,0],'bgcolor'=>false], 'N');

  $pdf->SetY(max($pdf->GetY() + 4, $m['top'] + 31));
  rumble_workorders_render_packing_slip_addresses($pdf, $order);
  rumble_workorders_render_packing_slip_items($pdf, $order);

  $pdf->SetY(-10);
  $pdf->SetFont('dejavusans', '', 9);
  $pdf->Cell(0, 6, 'Packing Slip #'.$order_number.' Page '.$page_number.' of '.$total_pages, 0, 0, 'C');
}

function rumble_workorders_render_blank_order_form(TCPDF $pdf){
  $m = $pdf->getMargins();
  $available = $pdf->getPageWidth() - $m['left'] - $m['right'];
  $bottom = $pdf->getPageHeight() - $m['bottom'];

  $pdf->SetXY($m['left'], $m['top']);
  $pdf->SetFont('dejavusans', 'B', 15);
  $pdf->Cell(74, 8, 'The Bear Traxs', 0, 2, 'L');
  $pdf->SetFont('dejavusans', '', 8);
  $pdf->MultiCell(74, 4, "10275 Thor Dr.\nFreeland, MI 48623\n989-326-4140\ninfo@thebeartraxs.com", 0, 'L', false, 0);

  $logo = function_exists('rumble_logo_url') ? rumble_logo_url() : '';
  if ($logo) {
    try {
      @$pdf->Image($logo, ($pdf->getPageWidth() - 24) / 2, $m['top'], 24, 24);
    } catch (Throwable $e) {
      error_log('[Rumble blank order form] Logo failed to render: '.$e->getMessage());
    }
  }

  $pdf->SetXY($pdf->getPageWidth() - $m['right'] - 86, $m['top']);
  $pdf->SetFont('dejavusans', 'B', 18);
  $pdf->Cell(86, 8, 'Blank Order Form', 0, 2, 'R');
  $pdf->SetFont('dejavusans', '', 8);
  $pdf->Cell(86, 5, 'For handwritten intake, then entry into Rumble', 0, 2, 'R');

  $pdf->SetY($m['top'] + 28);
  rumble_workorders_render_blank_meta_row($pdf, $available);

  $pdf->Ln(3);
  $y = $pdf->GetY();
  $gap = 4;
  $col = ($available - $gap) / 2;
  rumble_workorders_render_blank_customer_box($pdf, $m['left'], $y, $col);
  rumble_workorders_render_blank_shipping_box($pdf, $m['left'] + $col + $gap, $y, $col);
  $pdf->SetY($y + 59);

  rumble_workorders_render_blank_items_table($pdf, $available);

  $pdf->Ln(3);
  $y = $pdf->GetY();
  $notes_w = $available * .62;
  $totals_w = $available - $notes_w - $gap;
  $extras_height = 28;
  rumble_workorders_render_blank_notes_box($pdf, $m['left'], $y, $notes_w, $extras_height);
  rumble_workorders_render_blank_totals_box($pdf, $m['left'] + $notes_w + $gap, $y, $totals_w);
}

function rumble_workorders_render_blank_meta_row(TCPDF $pdf, float $available){
  $x = $pdf->GetX();
  $y = $pdf->GetY();
  $cols = [
    ['Order Date', $available * .22],
    ['Entered By', $available * .28],
    ['In Hands Date', $available * .25],
    ['Event Date', $available * .25],
  ];
  foreach ($cols as $col) {
    rumble_workorders_blank_field_at($pdf, $x, $y, $col[1], 11, $col[0]);
    $x += $col[1];
  }
  $pdf->SetY($y + 11);
}

function rumble_workorders_render_blank_customer_box(TCPDF $pdf, float $x, float $y, float $w){
  $pdf->SetXY($x, $y);
  rumble_workorders_blank_section($pdf, 'Customer / Billing', $w);
  $row_y = $y + 7;
  rumble_workorders_blank_field_at($pdf, $x, $row_y, $w, 8, 'Company');
  $row_y += 8;
  rumble_workorders_blank_field_at($pdf, $x, $row_y, $w * .5, 8, 'Buyer First');
  rumble_workorders_blank_field_at($pdf, $x + ($w * .5), $row_y, $w * .5, 8, 'Last');
  $row_y += 8;
  rumble_workorders_blank_field_at($pdf, $x, $row_y, $w, 8, 'Street');
  $row_y += 8;
  rumble_workorders_blank_field_at($pdf, $x, $row_y, $w * .5, 8, 'City');
  rumble_workorders_blank_field_at($pdf, $x + ($w * .5), $row_y, $w * .2, 8, 'State');
  rumble_workorders_blank_field_at($pdf, $x + ($w * .7), $row_y, $w * .3, 8, 'Zip');
  $row_y += 8;
  rumble_workorders_blank_field_at($pdf, $x, $row_y, $w * .4, 8, 'Phone');
  rumble_workorders_blank_field_at($pdf, $x + ($w * .4), $row_y, $w * .6, 8, 'Email');
}

function rumble_workorders_render_blank_shipping_box(TCPDF $pdf, float $x, float $y, float $w){
  $pdf->SetXY($x, $y);
  rumble_workorders_blank_section($pdf, 'Shipping / Delivery', $w);
  $row_y = $y + 7;
  $pdf->SetFont('dejavusans', '', 8);
  $pdf->SetXY($x, $row_y);
  $pdf->Cell($w, 8, '[ ] Same as billing    [ ] Pickup    [ ] Ship    [ ] Deliver', 1, 1, 'L');
  $row_y += 8;
  rumble_workorders_blank_field_at($pdf, $x, $row_y, $w, 8, 'Ship To');
  $row_y += 8;
  rumble_workorders_blank_field_at($pdf, $x, $row_y, $w, 8, 'Street');
  $row_y += 8;
  rumble_workorders_blank_field_at($pdf, $x, $row_y, $w * .5, 8, 'City');
  rumble_workorders_blank_field_at($pdf, $x + ($w * .5), $row_y, $w * .2, 8, 'State');
  rumble_workorders_blank_field_at($pdf, $x + ($w * .7), $row_y, $w * .3, 8, 'Zip');
  $row_y += 8;
  rumble_workorders_blank_field_at($pdf, $x, $row_y, $w, 8, 'Delivery Notes');
  $row_y += 8;
  rumble_workorders_blank_field_at($pdf, $x, $row_y, $w, 8, 'Hard Deadline Reason');
}

function rumble_workorders_render_blank_items_table(TCPDF $pdf, float $available){
  rumble_workorders_blank_section($pdf, 'Items', $available);
  $cols = [
    ['Vendor', $available * .095],
    ['Item Code', $available * .09],
    ['Description', $available * .27],
    ['Color', $available * .075],
    ['S', $available * .045],
    ['M', $available * .045],
    ['L', $available * .045],
    ['XL', $available * .045],
    ['Other', $available * .06],
    ['Decor Method', $available * .11],
    ['Price Each', $available * .085],
    ['Tax', $available * .035],
  ];

  $pdf->SetFont('dejavusans', 'B', 6.6);
  foreach ($cols as $i => $col) {
    $pdf->MultiCell($col[1], 7, $col[0], 1, 'C', true, $i === count($cols) - 1 ? 1 : 0, '', '', true, 0, false, true, 7, 'M');
  }

  $pdf->SetFont('dejavusans', '', 7);
  for ($row = 0; $row < 16; $row++) {
    foreach ($cols as $i => $col) {
      $value = $col[0] === 'Tax' ? '[ ]' : '';
      $pdf->Cell($col[1], 7, $value, 1, $i === count($cols) - 1 ? 1 : 0, 'C');
    }
  }
}

function rumble_workorders_render_blank_notes_box(TCPDF $pdf, float $x, float $y, float $w, float $h){
  $pdf->SetXY($x, $y);
  rumble_workorders_blank_section($pdf, 'Notes', $w);
  $pdf->Cell($w, max(30, $h - 7), '', 1, 1, 'L');
}

function rumble_workorders_render_blank_totals_box(TCPDF $pdf, float $x, float $y, float $w){
  $pdf->SetXY($x, $y);
  rumble_workorders_blank_section($pdf, 'Extras', $w);
  $row_y = $y + 7;
  foreach (['Art / Setup', 'Rush Fee', 'Digitizing'] as $label) {
    rumble_workorders_blank_field_at($pdf, $x, $row_y, $w, 7, $label);
    $row_y += 7;
  }
}

function rumble_workorders_blank_section(TCPDF $pdf, string $label, float $w){
  $pdf->SetFont('dejavusans', 'B', 9);
  $pdf->SetFillColor(238, 242, 247);
  $pdf->Cell($w, 7, $label, 1, 1, 'L', true);
  $pdf->SetFillColor(255, 255, 255);
}

function rumble_workorders_blank_field_at(TCPDF $pdf, float $x, float $y, float $w, float $h, string $label){
  $pdf->SetXY($x, $y);
  $pdf->SetFont('dejavusans', 'B', 6.6);
  $pdf->Cell($w, 3.5, $label, 'LTR', 0, 'L');
  $pdf->SetXY($x, $y + 3.5);
  $pdf->SetFont('dejavusans', '', 8);
  $pdf->Cell($w, $h - 3.5, '', 'LBR', 0, 'L');
}

function rumble_workorders_render_packing_slip_addresses(TCPDF $pdf, WC_Order $order){
  $m = $pdf->getMargins();
  $w = ($pdf->getPageWidth() - $m['left'] - $m['right']) / 2;
  $pdf->SetFont('dejavusans', 'B', 10);
  $pdf->Cell($w, 8, 'Bill To', 1, 0, 'C');
  $pdf->Cell($w, 8, 'Ship To', 1, 1, 'C');
  $y = $pdf->GetY();
  $left = rumble_workorders_pdf_text(rumble_workorders_address($order, 'billing'));
  $right = rumble_workorders_pdf_text(rumble_workorders_address($order, 'shipping'));
  if ($right === '') $right = $left;
  $h = max(30, $pdf->getStringHeight($w - 5, $left), $pdf->getStringHeight($w - 5, $right));
  $pdf->Rect($m['left'], $y, $w, $h);
  $pdf->Rect($m['left'] + $w, $y, $w, $h);
  $pdf->SetFont('dejavusans', '', 9);
  $pdf->SetXY($m['left'] + 2, $y + 2);
  $pdf->MultiCell($w - 4, 5, $left ?: '-', 0, 'L', false, 0);
  $pdf->SetXY($m['left'] + $w + 2, $y + 2);
  $pdf->MultiCell($w - 4, 5, $right ?: '-', 0, 'L', false, 1);
  $pdf->SetY($y + $h + 8);
}

function rumble_workorders_render_packing_slip_items(TCPDF $pdf, WC_Order $order){
  $m = $pdf->getMargins();
  $available = $pdf->getPageWidth() - $m['left'] - $m['right'];
  $cols = ['qty'=>$available*.1, 'item'=>$available*.54, 'color'=>$available*.18, 'size'=>$available*.18];
  $pdf->SetFont('dejavusans', 'B', 11);
  $pdf->Cell(0, 7, 'Items', 0, 1, 'L');
  $pdf->SetFont('dejavusans', 'B', 8);
  $pdf->Cell($cols['qty'], 7, 'Qty', 1, 0, 'C');
  $pdf->Cell($cols['item'], 7, 'Item', 1, 0, 'L');
  $pdf->Cell($cols['color'], 7, 'Color', 1, 0, 'C');
  $pdf->Cell($cols['size'], 7, 'Size', 1, 1, 'C');
  $pdf->SetFont('dejavusans', '', 8);
  foreach ($order->get_items('line_item') as $item) {
    if (!$item instanceof WC_Order_Item_Product) continue;
    $name = rumble_workorders_pdf_text((string) $item->get_name());
    $color = rumble_workorders_pdf_text((string) ($item->get_meta('Color', true) ?: $item->get_meta('pa_color', true)));
    $size = rumble_workorders_pdf_text((string) ($item->get_meta('Size', true) ?: $item->get_meta('pa_size', true) ?: $item->get_meta('Sizes', true)));
    $h = max(9, $pdf->getStringHeight($cols['item'], $name) + 2);
    rumble_workorders_ensure_space($pdf, $h + 16);
    $pdf->MultiCell($cols['qty'], $h, (string) max(1, (int) $item->get_quantity()), 1, 'C', false, 0, '', '', true, 0, false, true, $h, 'M');
    $pdf->MultiCell($cols['item'], $h, $name, 1, 'L', false, 0, '', '', true, 0, false, true, $h, 'M');
    $pdf->MultiCell($cols['color'], $h, $color ?: '-', 1, 'C', false, 0, '', '', true, 0, false, true, $h, 'M');
    $pdf->MultiCell($cols['size'], $h, $size ?: '-', 1, 'C', false, 1, '', '', true, 0, false, true, $h, 'M');
  }
}

function rumble_workorders_render_header(TCPDF $pdf, array $job){
  $m = $pdf->getMargins();
  $number = rumble_workorders_pdf_text((string) ($job['number'] ?? ''));
  $pdf->SetFont('dejavusans', 'B', 12);
  $pdf->SetXY($m['left'], $m['top']);
  $pdf->MultiCell(0, 5, "The Bear Traxs\nWork Order #".$number, 0, 'L', false, 1);
  $pdf->SetFont('dejavusans', '', 10);
  $pdf->MultiCell(0, 5, rumble_workorders_pdf_text((string) ($job['title'] ?? '')), 0, 'L', false, 1);
  if (!empty($job['date'])) $pdf->MultiCell(0, 5, 'Date: '.rumble_workorders_pdf_text((string) $job['date']), 0, 'L', false, 1);

  $logo = function_exists('rumble_logo_url') ? rumble_logo_url() : '';
  if ($logo) {
    try {
      @$pdf->Image($logo, ($pdf->getPageWidth() - 25.4) / 2, $m['top'], 25.4, 25.4);
    } catch (Throwable $e) {
      error_log('[Rumble workorders] Logo failed to render: '.$e->getMessage());
    }
  }
  $qr = home_url('/rumble/work-orders/');
  $pdf->write2DBarcode($qr, 'QRCODE,H', $pdf->getPageWidth() - $m['right'] - 25.4, $m['top'], 25.4, 25.4, ['border'=>0,'padding'=>0,'fgcolor'=>[0,0,0],'bgcolor'=>false], 'N');

  $pdf->SetY(max($pdf->GetY() + 4, $m['top'] + 31));
  rumble_workorders_render_address_boxes($pdf, $job);
}

function rumble_workorders_render_address_boxes(TCPDF $pdf, array $job){
  $m = $pdf->getMargins();
  $w = ($pdf->getPageWidth() - $m['left'] - $m['right']) / 2;
  $pdf->SetFont('dejavusans', 'B', 10);
  $pdf->Cell($w, 8, 'Billing / Campaign', 1, 0, 'C');
  $pdf->Cell($w, 8, 'Shipping / Orders', 1, 1, 'C');
  $y = $pdf->GetY();
  $left = rumble_workorders_pdf_text((string) ($job['billing'] ?? ''));
  $right = rumble_workorders_pdf_text((string) ($job['shipping'] ?? ''));
  $h = max(18, $pdf->getStringHeight($w - 5, $left), $pdf->getStringHeight($w - 5, $right));
  $pdf->Rect($m['left'], $y, $w, $h);
  $pdf->Rect($m['left'] + $w, $y, $w, $h);
  $pdf->SetFont('dejavusans', '', 9);
  $pdf->SetXY($m['left'] + 2, $y + 2);
  $pdf->MultiCell($w - 4, 5, $left ?: '-', 0, 'L', false, 0);
  $pdf->SetXY($m['left'] + $w + 2, $y + 2);
  $pdf->MultiCell($w - 4, 5, $right ?: '-', 0, 'L', false, 1);
  $pdf->SetY($y + $h + 5);
}

function rumble_workorders_render_items(TCPDF $pdf, array $items){
  $m = $pdf->getMargins();
  $available = $pdf->getPageWidth() - $m['left'] - $m['right'];
  $cols = ['item'=>$available*.42, 'vendor'=>$available*.16, 'color'=>$available*.12, 'size'=>$available*.1, 'qty'=>$available*.08, 'prod'=>$available*.12];
  $pdf->SetFont('dejavusans', 'B', 8);
  $pdf->Cell($cols['item'], 7, 'Items', 1, 0, 'L');
  $pdf->Cell($cols['vendor'], 7, 'Vendor Code', 1, 0, 'C');
  $pdf->Cell($cols['color'], 7, 'Color', 1, 0, 'C');
  $pdf->Cell($cols['size'], 7, 'Size', 1, 0, 'C');
  $pdf->Cell($cols['qty'], 7, 'Qty', 1, 0, 'C');
  $pdf->Cell($cols['prod'], 7, 'Production', 1, 1, 'C');
  $pdf->SetFont('dejavusans', '', 8);
  foreach ($items as $item) {
    rumble_workorders_ensure_space($pdf, 10);
    $text = rumble_workorders_pdf_text((string) ($item['product'] ?? ''));
    if (!empty($item['instructions'])) $text .= "\nSpecial instructions: ".rumble_workorders_pdf_text((string) $item['instructions']);
    $h = max(8, $pdf->getStringHeight($cols['item'], $text));
    $pdf->MultiCell($cols['item'], $h, $text, 1, 'L', false, 0, '', '', true, 0, false, true, $h, 'M');
    $pdf->MultiCell($cols['vendor'], $h, rumble_workorders_pdf_text((string) ($item['vendor_code'] ?? '')), 1, 'C', false, 0, '', '', true, 0, false, true, $h, 'M');
    $pdf->MultiCell($cols['color'], $h, rumble_workorders_pdf_text((string) ($item['color'] ?? '')), 1, 'C', false, 0, '', '', true, 0, false, true, $h, 'M');
    $pdf->MultiCell($cols['size'], $h, rumble_workorders_pdf_text((string) ($item['size'] ?? '')), 1, 'C', false, 0, '', '', true, 0, false, true, $h, 'M');
    $pdf->MultiCell($cols['qty'], $h, (string) ($item['qty'] ?? ''), 1, 'C', false, 0, '', '', true, 0, false, true, $h, 'M');
    $pdf->MultiCell($cols['prod'], $h, rumble_workorders_pdf_text((string) ($item['production'] ?? '')), 1, 'C', false, 1, '', '', true, 0, false, true, $h, 'M');
  }
}

function rumble_workorders_render_packing(TCPDF $pdf, array $rows){
  if (!$rows) return;
  rumble_workorders_ensure_space($pdf, 16);
  $pdf->SetFont('dejavusans', 'B', 11);
  $pdf->Cell(0, 7, 'Packing / Shipping List', 0, 1, 'L');
  $m = $pdf->getMargins();
  $available = $pdf->getPageWidth() - $m['left'] - $m['right'];
  $cols = ['order'=>$available*.14, 'customer'=>$available*.2, 'address'=>$available*.28, 'items'=>$available*.38];
  $pdf->SetFont('dejavusans', 'B', 8);
  $pdf->Cell($cols['order'], 7, 'Order', 1, 0, 'C');
  $pdf->Cell($cols['customer'], 7, 'Customer', 1, 0, 'L');
  $pdf->Cell($cols['address'], 7, 'Ship To', 1, 0, 'L');
  $pdf->Cell($cols['items'], 7, 'Items', 1, 1, 'L');
  $pdf->SetFont('dejavusans', '', 7);
  foreach ($rows as $row) {
    $items = rumble_workorders_pdf_text(implode("\n", (array) ($row['items'] ?? [])));
    $address = rumble_workorders_pdf_text((string) ($row['address'] ?? ''));
    $h = max(10, $pdf->getStringHeight($cols['items'], $items), $pdf->getStringHeight($cols['address'], $address));
    rumble_workorders_ensure_space($pdf, $h + 2);
    $pdf->MultiCell($cols['order'], $h, rumble_workorders_pdf_text((string) ($row['order'] ?? '')), 1, 'C', false, 0, '', '', true, 0, false, true, $h, 'M');
    $pdf->MultiCell($cols['customer'], $h, rumble_workorders_pdf_text((string) ($row['customer'] ?? '')), 1, 'L', false, 0, '', '', true, 0, false, true, $h, 'M');
    $pdf->MultiCell($cols['address'], $h, $address, 1, 'L', false, 0, '', '', true, 0, false, true, $h, 'M');
    $pdf->MultiCell($cols['items'], $h, $items, 1, 'L', false, 1, '', '', true, 0, false, true, $h, 'M');
  }
}

function rumble_workorders_render_blank_body(TCPDF $pdf){
  $pdf->SetFont('dejavusans', 'B', 9);
  $labels = ['Customer', 'Due Date', 'Production', 'Art Location', 'Garment / Item', 'Color', 'Sizes / Qty', 'Notes'];
  foreach ($labels as $label) {
    rumble_workorders_ensure_space($pdf, 16);
    $pdf->Cell(38, 12, $label, 1, 0, 'L');
    $pdf->Cell(0, 12, '', 1, 1, 'L');
  }
}

function rumble_workorders_ensure_space(TCPDF $pdf, float $height){
  if ($pdf->GetY() + $height > $pdf->getPageHeight() - 18) {
    $pdf->AddPage();
  }
}

function rumble_workorders_pdf_text(string $value){
  $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $value = str_replace(["\r\n", "\r", "\xc2\xa0"], ["\n", "\n", ' '], $value);
  return trim((string) preg_replace('/[^\S\n]+/u', ' ', $value));
}

function rumble_workorders_date_range(array $dates){
  $dates = array_values(array_unique(array_filter($dates)));
  if (!$dates) return '';
  return count($dates) === 1 ? $dates[0] : $dates[0].' - '.end($dates);
}

function rumble_workorders_order_numbers(array $orders){
  $numbers = [];
  foreach ($orders as $order) {
    if ($order instanceof WC_Order) $numbers[] = (string) $order->get_order_number();
  }
  $lines = [];
  foreach (array_chunk($numbers, 8) as $chunk) $lines[] = implode(', ', $chunk);
  return implode("\n", $lines);
}
