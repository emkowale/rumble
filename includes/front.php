<?php
if (!defined('ABSPATH')) exit;

function rumble_register_route(){
  add_rewrite_tag('%rumble%', '1');
  add_rewrite_tag('%rumble_screen%', '([^&]+)');
  add_rewrite_rule('^rumble/?$', 'index.php?rumble=1', 'top');
  add_rewrite_rule('^rumble/(new-order|job-details|order-details|status-details|work-orders|reporting|purchasing)/?$', 'index.php?rumble=1&rumble_screen=$matches[1]', 'top');
}
add_action('init', 'rumble_register_route');

add_filter('query_vars', function($vars){
  $vars[] = 'rumble';
  $vars[] = 'rumble_screen';
  return $vars;
});

add_action('template_redirect', function(){
  global $wp_query;
  $path = trim((string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
  $is_rumble_path = in_array($path, ['rumble', 'rumble/new-order', 'rumble/job-details', 'rumble/order-details', 'rumble/status-details', 'rumble/work-orders', 'rumble/reporting', 'rumble/purchasing'], true);
  if (!isset($wp_query->query_vars['rumble']) && !$is_rumble_path) return;
  if (!is_user_logged_in()) auth_redirect();
  if (!rumble_current_user_can_access()) wp_die('You do not have access to Rumble.', 'Rumble Access Denied', ['response' => 403]);
  $screen = isset($wp_query->query_vars['rumble_screen']) ? (string) $wp_query->query_vars['rumble_screen'] : '';
  if ($screen === '' && $path === 'rumble/new-order') $screen = 'new-order';
  if ($screen === '' && $path === 'rumble/job-details') $screen = 'job-details';
  if ($screen === '' && $path === 'rumble/order-details') $screen = 'job-details';
  if ($screen === '' && $path === 'rumble/status-details') $screen = 'status-details';
  if ($screen === '' && $path === 'rumble/work-orders') $screen = 'work-orders';
  if ($screen === '' && $path === 'rumble/reporting') $screen = 'reporting';
  if ($screen === '' && $path === 'rumble/purchasing') $screen = 'purchasing';
  if ($screen === 'order-details') $screen = 'job-details';
  if (!in_array($screen, ['new-order', 'job-details', 'status-details', 'work-orders', 'reporting', 'purchasing'], true)) $screen = 'dashboard';
  if ($screen === 'reporting' && !rumble_current_user_can_reporting()) wp_die('You do not have access to Rumble reporting.', 'Rumble Access Denied', ['response' => 403]);
  if ($screen === 'purchasing' && !rumble_purchasing_current_user_is_eric()) wp_die('You do not have access to Rumble purchasing.', 'Rumble Access Denied', ['response' => 403]);
  if (function_exists('show_admin_bar')) show_admin_bar(false);
  status_header(200); nocache_headers();
  echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
  echo '<style id="rumble-admin-bar-reset">html{margin-top:0!important;}body{margin-top:0!important;}#wpadminbar{display:none!important;}</style>';
  wp_head();
  echo '<style id="rumble-admin-bar-reset-late">html{margin-top:0!important;}body{margin-top:0!important;padding-top:0!important;}#wpadminbar{display:none!important;}</style>';
  echo '<body class="rumble-kiosk">';
  rumble_render_app_screen($screen);
  wp_footer();
  echo '</body></html>';
  exit;
});

function rumble_render_app_screen($screen){
  $current_user = wp_get_current_user();
  $logo_url = rumble_logo_url();
  $user_name = $current_user && $current_user->exists()
    ? ($current_user->display_name ?: $current_user->user_login)
    : 'Rumble User';
  ?>
  <div class="rumble-app">
    <?php rumble_render_appbar($logo_url, $user_name, $screen); ?>
    <main class="rumble-main">
      <?php
      if ($screen === 'new-order') {
        rumble_render_new_order_screen($logo_url);
      } elseif ($screen === 'job-details') {
        rumble_render_job_details_screen();
      } elseif ($screen === 'status-details') {
        rumble_render_status_details_screen();
      } elseif ($screen === 'work-orders') {
        rumble_render_work_orders_screen();
      } elseif ($screen === 'reporting') {
        rumble_render_reporting_screen();
      } elseif ($screen === 'purchasing') {
        rumble_render_purchasing_screen();
      } else {
        rumble_render_dashboard_screen();
      }
      ?>
    </main>
  </div>
  <?php
}

function rumble_render_appbar($logo_url, $user_name, $screen){
  $links = [
    'dashboard' => ['label' => 'Dashboard', 'url' => home_url('/rumble/')],
    'new-order' => ['label' => 'New Order', 'url' => home_url('/rumble/new-order/')],
    'work-orders' => ['label' => 'Print Work Orders', 'url' => home_url('/rumble/work-orders/')],
  ];
  if (rumble_purchasing_current_user_is_eric()) {
    $links['purchasing'] = ['label' => 'Purchasing', 'url' => home_url('/rumble/purchasing/')];
  }
  if (rumble_current_user_can_reporting()) {
    $links['reporting'] = ['label' => 'Reporting', 'url' => home_url('/rumble/reporting/')];
  }
  ?>
  <header class="rumble-appbar">
    <a class="rumble-brand" href="<?php echo esc_url(home_url('/rumble/')); ?>" aria-label="Rumble dashboard">
      <?php if ($logo_url): ?>
        <img src="<?php echo esc_url($logo_url); ?>" alt="The Bear Traxs">
      <?php endif; ?>
      <span>Rumble</span>
    </a>
    <div class="rumble-search">
      <span class="screen-reader-text">Search Rumble</span>
      <input type="search" placeholder="Search order, customer, invoice, work order..." autocomplete="off" data-rumble-search aria-expanded="false" aria-controls="rumble-search-results">
      <div class="rumble-search-results" id="rumble-search-results" hidden>
        <table>
          <thead>
            <tr>
              <th>Type</th>
              <th>Customer</th>
              <th>Order</th>
              <th>Status</th>
              <th>Due</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
    <nav class="rumble-nav" aria-label="Rumble navigation">
      <button class="rumble-nav-toggle" type="button" aria-expanded="false" aria-controls="rumble-nav-menu" data-rumble-nav-toggle>
        <span class="screen-reader-text">Menu</span>
        <span aria-hidden="true"></span>
      </button>
      <div class="rumble-nav-menu" id="rumble-nav-menu" data-rumble-nav-menu>
      <?php foreach ($links as $key => $link): ?>
        <a class="rumble-nav-link <?php echo $screen === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url($link['url']); ?>"><?php echo esc_html($link['label']); ?></a>
      <?php endforeach; ?>
      </div>
    </nav>
  </header>
  <?php
}

function rumble_render_purchasing_screen(){
  if (!rumble_purchasing_current_user_is_eric()) {
    wp_die('You do not have access to Rumble purchasing.', 'Rumble Access Denied', ['response' => 403]);
  }
  echo '<section class="rumble-purchasing-screen">';
  rumble_purchasing_render_content();
  echo '</section>';
}

function rumble_dashboard_data(){
  $orders = rumble_dashboard_orders();
  $quotes = rumble_dashboard_quotes();
  $today = current_time('Y-m-d');
  $current_user = wp_get_current_user();
  $current_names = rumble_current_user_assignment_names($current_user);

  $stage_defs = rumble_dashboard_stage_definitions();
  $stage_jobs = array_fill_keys(array_keys($stage_defs), []);

  foreach ($orders as $job) {
    $stage = rumble_dashboard_stage_for_job($job);
    if (isset($stage_jobs[$stage])) $stage_jobs[$stage][] = $job;
  }
  foreach ($quotes as $job) $stage_jobs['Quote'][] = $job;

  $stages = [];
  foreach ($stage_defs as $name => $ignored) {
    $jobs = $stage_jobs[$name];
    usort($jobs, 'rumble_dashboard_sort_by_due');
    $stages[] = [
      'name' => $name,
      'slug' => rumble_dashboard_stage_slug($name),
      'url' => rumble_status_details_url(rumble_dashboard_stage_slug($name)),
      'count' => count($jobs),
      'oldest' => rumble_dashboard_oldest_label($jobs),
      'overdue' => count(array_filter($jobs, 'rumble_dashboard_job_is_overdue')),
      'jobs' => array_slice($jobs, 0, 4),
      'all_jobs' => $jobs,
    ];
  }

  $all_jobs = array_merge($orders, $quotes);
  $due_today = count(array_filter($all_jobs, function($job) use ($today){ return $job['due_raw'] === $today; }));
  $overdue = count(array_filter($all_jobs, 'rumble_dashboard_job_is_overdue'));
  $summary = [
    ['label' => 'Draft', 'count' => count($stage_jobs['Draft']), 'tone' => count($stage_jobs['Draft']) ? 'risk' : 'normal'],
    ['label' => 'Quote', 'count' => count($stage_jobs['Quote']), 'tone' => 'normal'],
    ['label' => 'Needs Invoicing', 'count' => count($stage_jobs['Needs Invoicing']), 'tone' => count($stage_jobs['Needs Invoicing']) ? 'waiting' : 'normal'],
    ['label' => 'Due Today', 'count' => $due_today, 'tone' => $due_today ? 'today' : 'normal'],
    ['label' => 'Overdue', 'count' => $overdue, 'tone' => $overdue ? 'danger' : 'normal'],
  ];

  $my_work = array_values(array_filter($all_jobs, function($job) use ($current_names){
    return rumble_job_is_assigned_to_names($job, $current_names);
  }));
  usort($my_work, 'rumble_dashboard_sort_by_due');

  $attention = rumble_dashboard_attention($all_jobs);
  $calendar = rumble_dashboard_production_calendar($all_jobs);

  return [
    'summary' => $summary,
    'stages' => $stages,
    'my_work' => array_slice($my_work, 0, 6),
    'assigned_to_me' => $my_work,
    'assigned_to_me_url' => rumble_assigned_to_me_status_url(),
    'attention' => array_slice($attention, 0, 6),
    'calendar' => $calendar,
  ];
}

function rumble_dashboard_production_calendar(array $jobs){
  $calendar_jobs = array_values(array_filter($jobs, function($job){
    $status = $job['raw_status'] ?? '';
    return !empty($job['production_raw']) && in_array($status, ['pending', 'on-hold', 'on-order', 'processing'], true);
  }));
  $production_jobs = array_values(array_filter($jobs, function($job){
    return ($job['raw_status'] ?? '') === 'processing';
  }));

  $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(wp_timezone_string() ?: 'UTC');
  $month_start = new DateTimeImmutable(current_time('Y-m-01'), $timezone);
  $month_end = $month_start->modify('last day of this month');
  $first_monday = (int) $month_start->format('N') === 1 ? $month_start : $month_start->modify('next monday');

  $jobs_by_date = [];
  $not_set = [];
  foreach ($calendar_jobs as $job) {
    $date = $job['production_raw'] ?? '';
    $jobs_by_date[$date][] = $job;
  }
  foreach ($production_jobs as $job) {
    if (($job['production_raw'] ?? '') === '') $not_set[] = $job;
  }
  usort($not_set, 'rumble_dashboard_sort_by_due');

  $days = [];
  for ($day = $first_monday; $day <= $month_end; $day = $day->modify('+1 day')) {
    if ((int) $day->format('N') > 5) continue;
    $raw = $day->format('Y-m-d');
    $day_jobs = $jobs_by_date[$raw] ?? [];
    usort($day_jobs, 'rumble_dashboard_sort_by_due');
    $days[] = [
      'raw' => $raw,
      'label' => $day->format('D'),
      'date' => $day->format('M j'),
      'jobs' => $day_jobs,
    ];
  }

  return [
    'month' => $month_start->format('F Y'),
    'days' => $days,
    'not_set' => $not_set,
  ];
}

function rumble_dashboard_stage_definitions(){
  return [
    'Draft' => ['rumble-draft', 'checkout-draft', 'draft'],
    'Quote' => ['rumble_quote'],
    'Needs Invoicing' => ['pending'],
    'Order Goods' => ['on-hold'],
    'Design' => [],
    'On Order' => ['on-order'],
    'Processing' => ['processing'],
  ];
}

function rumble_dashboard_stage_for_job($job){
  $status = $job['raw_status'] ?? '';
  if (in_array($status, ['rumble-draft', 'checkout-draft', 'draft'], true)) return 'Draft';
  if ($status === 'rumble_quote') return 'Quote';
  if (!empty($job['needs_design'])) return 'Design';
  if ($status === 'pending') return 'Needs Invoicing';
  if ($status === 'on-hold') return 'Order Goods';
  if ($status === 'processing') return 'Processing';
  if ($status === 'on-order') return 'On Order';
  return 'Draft';
}

function rumble_dashboard_stage_slug($name){
  return sanitize_title($name);
}

function rumble_status_details_url($stage_slug){
  return add_query_arg('status', sanitize_title($stage_slug), home_url('/rumble/status-details/'));
}

function rumble_assigned_to_me_status_slug(){
  return 'assigned-to-' . sanitize_title(rumble_current_user_first_name());
}

function rumble_assigned_to_me_status_url(){
  return rumble_status_details_url(rumble_assigned_to_me_status_slug());
}

function rumble_assignment_name_key($name){
  $name = strtolower(remove_accents(rumble_clean($name)));
  return preg_replace('/\s+/', ' ', $name);
}

function rumble_current_user_assignment_names($user = null){
  $user = $user ?: wp_get_current_user();
  if (!$user || !$user->exists()) return [];

  $display_name = rumble_clean($user->display_name);
  $first_name = rumble_clean(get_user_meta($user->ID, 'first_name', true));
  $last_name = rumble_clean(get_user_meta($user->ID, 'last_name', true));
  $full_name = trim($first_name . ' ' . $last_name);
  $nickname = rumble_clean(get_user_meta($user->ID, 'nickname', true));
  $login = rumble_clean($user->user_login);
  $display_first = $display_name ? strtok($display_name, ' ') : '';

  $keys = [];
  foreach (array_filter([$display_name, $full_name, $first_name, $display_first, $nickname, $login]) as $name) {
    $key = rumble_assignment_name_key($name);
    if ($key !== '') $keys[$key] = true;
  }
  return array_keys($keys);
}

function rumble_job_is_assigned_to_names($job, array $assignment_names){
  if (!$assignment_names) return false;
  foreach (($job['assignment_names'] ?? []) as $assigned_name) {
    $key = rumble_assignment_name_key($assigned_name);
    if ($key !== '' && in_array($key, $assignment_names, true)) return true;
  }
  return false;
}

function rumble_dashboard_orders(){
  if (!function_exists('wc_get_orders')) return [];
  $order_map = [];
  $orders = wc_get_orders([
    'limit' => -1,
    'orderby' => 'date',
    'order' => 'DESC',
    'status' => [
      'rumble-draft',
      'wc-rumble-draft',
      'checkout-draft',
      'wc-checkout-draft',
      'draft',
      'wc-draft',
      'pending',
      'wc-pending',
      'processing',
      'wc-processing',
      'on-hold',
      'wc-on-hold',
      'on-order',
      'wc-on-order',
    ],
  ]);
  foreach ($orders as $order) {
    if ($order instanceof WC_Order) $order_map[$order->get_id()] = $order;
  }

  $draft_orders = wc_get_orders([
    'limit' => -1,
    'orderby' => 'date',
    'order' => 'DESC',
    'meta_key' => '_rumble_intake_state',
    'meta_value' => 'draft',
  ]);
  foreach ($draft_orders as $order) {
    if ($order instanceof WC_Order) $order_map[$order->get_id()] = $order;
  }

  $jobs = [];
  foreach ($order_map as $order) {
    $jobs[] = rumble_dashboard_job_from_order($order);
  }
  return $jobs;
}

function rumble_dashboard_search($term, $limit = 12){
  $term = strtolower(rumble_clean($term));
  if (strlen($term) < 2) return [];
  $jobs = array_merge(rumble_dashboard_orders(), rumble_dashboard_quotes());
  $matches = [];

  foreach ($jobs as $job) {
    $haystack = strtolower(implode(' ', [
      $job['customer'] ?? '',
      $job['title'] ?? '',
      $job['status'] ?? '',
      $job['order_number'] ?? '',
      $job['assignee'] ?? '',
      $job['due_date'] ?? '',
    ]));
    if (strpos($haystack, $term) === false) continue;
    $matches[] = [
      'type' => $job['type'] ?? 'Order',
      'customer' => $job['customer'] ?? '',
      'title' => $job['title'] ?? '',
      'status' => $job['status'] ?? '',
      'due_date' => $job['due_date'] ?: 'Not set',
      'order_number' => $job['order_number'] ?? '',
      'url' => $job['url'] ?? home_url('/rumble/'),
    ];
    if (count($matches) >= $limit) break;
  }

  return $matches;
}

function rumble_dashboard_quotes(){
  $posts = get_posts([
    'post_type' => 'rumble_quote',
    'post_status' => 'publish',
    'numberposts' => 80,
    'orderby' => 'date',
    'order' => 'DESC',
  ]);
  $jobs = [];
  foreach ($posts as $post) {
    $data = get_post_meta($post->ID, '_rumble', true);
    if (is_array($data) && !empty($data['accepted'])) continue;
    $customer = is_array($data) ? ($data['customer'] ?? []) : [];
    $company = rumble_clean($customer['company'] ?? '');
    $first = rumble_clean($customer['first_name'] ?? '');
    $last = rumble_clean($customer['last_name'] ?? '');
    $customer_name = $company ?: trim($first.' '.$last);
    $created = get_post_datetime($post);
    $jobs[] = [
      'customer' => $customer_name ?: 'Quote #'.$post->ID,
      'title' => $post->post_title ?: 'Rumble quote',
      'order_date' => $created ? $created->format('M j') : '',
      'order_raw' => $created ? $created->format('Y-m-d') : '',
      'due_date' => '',
      'due_raw' => '',
      'production_date' => '',
      'assignee' => 'Unassigned',
      'assignee_raw' => '',
      'status' => 'Quote',
      'raw_status' => 'rumble_quote',
      'tone' => 'normal',
      'url' => add_query_arg('quote_id', $post->ID, home_url('/rumble/order-details/')),
      'balance_due' => 0,
      'order_number' => (string) $post->ID,
      'type' => 'Quote',
    ];
  }
  return $jobs;
}

function rumble_dashboard_job_from_order($order){
  $payload = json_decode((string) $order->get_meta('_rumble_new_order_payload'), true);
  $company = $order->get_billing_company();
  $name = trim($order->get_billing_first_name().' '.$order->get_billing_last_name());
  $customer = $company ?: ($name ?: 'Order #'.$order->get_id());
  $needed_by = rumble_clean($order->get_meta('_rumble_needed_by'));
  $event_date = rumble_clean($order->get_meta('_rumble_event_date'));
  $production_date = rumble_clean($order->get_meta('_rumble_production_date') ?: (is_array($payload) ? ($payload['production_date'] ?? '') : ''));
  $entered_by = rumble_clean($order->get_meta('_rumble_entered_by'));
  $outside_sales = rumble_clean($order->get_meta('_rumble_outside_sales'));
  $inside_sales = rumble_clean($order->get_meta('_rumble_inside_sales'));
  $designer = rumble_clean($order->get_meta('_rumble_designer'));
  $created = $order->get_date_created();
  $status = $order->get_status();
  $status_label = rumble_order_status_label($status);
  $title = rumble_dashboard_order_title($order, is_array($payload) ? $payload : []);

  return [
    'customer' => $customer,
    'title' => $title,
    'order_date' => $created ? $created->date_i18n('M j') : '',
    'order_raw' => $created ? $created->date_i18n('Y-m-d') : '',
    'due_date' => $needed_by ? date_i18n('M j', strtotime($needed_by)) : '',
    'due_raw' => $needed_by,
	    'production_date' => $production_date ? date_i18n('M j', strtotime($production_date)) : '',
    'production_raw' => $production_date,
    'assignee' => $entered_by ?: 'Unassigned',
    'assignee_raw' => $entered_by,
    'assignment_names' => array_filter([$outside_sales, $inside_sales, $designer]),
    'status' => $status_label,
    'raw_status' => $status,
    'tone' => rumble_dashboard_tone($needed_by, $status),
    'url' => in_array($status, ['rumble-draft', 'checkout-draft', 'draft'], true) ? rumble_new_order_url($order->get_id()) : rumble_job_details_url($order->get_id()),
    'balance_due' => (float) $order->get_meta('_rumble_balance_due'),
    'needs_design' => rumble_order_needs_design($order),
    'order_number' => (string) $order->get_id(),
    'type' => 'Order',
  ];
}

function rumble_order_status_label($status){
  $labels = [
    'rumble-draft' => 'Draft',
    'checkout-draft' => 'Draft',
    'draft' => 'Draft',
    'pending' => 'Needs Invoicing',
    'on-hold' => 'Order Goods',
    'on-order' => 'On Order',
    'processing' => 'Processing',
    'completed' => 'Completed',
  ];
  return $labels[$status] ?? wc_get_order_status_name($status);
}

function rumble_order_status_options(){
  return [
    'rumble-draft' => 'Draft',
    'pending' => 'Needs Invoicing',
    'on-hold' => 'Order Goods',
    'on-order' => 'On Order',
    'processing' => 'Processing',
    'completed' => 'Completed',
  ];
}

function rumble_order_needs_design($order){
  return in_array((string) $order->get_meta('_rumble_needs_design'), ['1', 'yes', 'true'], true);
}

function rumble_job_details_url($order_id){
  return add_query_arg('order_id', absint($order_id), home_url('/rumble/order-details/'));
}

function rumble_new_order_url($order_id = 0){
  $url = home_url('/rumble/new-order/');
  return $order_id ? add_query_arg('order_id', absint($order_id), $url) : $url;
}

function rumble_dashboard_order_title($order, $payload){
  if (!empty($payload['items']) && is_array($payload['items'])) {
    foreach ($payload['items'] as $item) {
      $description = rumble_clean($item['description'] ?? '');
      if ($description) return $description;
    }
  }
  foreach ($order->get_items() as $item) return $item->get_name();
  return 'WooCommerce order #'.$order->get_id();
}

function rumble_dashboard_tone($due_raw, $status){
  if ($status === 'on-hold') return 'waiting';
  if (!$due_raw) return in_array($status, ['rumble-draft', 'checkout-draft', 'draft'], true) ? 'risk' : 'normal';
  $today = current_time('Y-m-d');
  if ($due_raw < $today) return 'danger';
  if ($due_raw === $today) return 'today';
  return $status === 'processing' ? 'ready' : 'normal';
}

function rumble_dashboard_sort_by_due($a, $b){
  $a_due = $a['due_raw'] ?: '9999-12-31';
  $b_due = $b['due_raw'] ?: '9999-12-31';
  if ($a_due === $b_due) return strcmp($b['order_raw'], $a['order_raw']);
  return strcmp($a_due, $b_due);
}

function rumble_dashboard_job_is_overdue($job){
  return !empty($job['due_raw']) && $job['due_raw'] < current_time('Y-m-d') && !in_array($job['raw_status'], ['completed', 'cancelled', 'refunded', 'failed'], true);
}

function rumble_dashboard_oldest_label($jobs){
  if (!$jobs) return 'none';
  $oldest = '';
  foreach ($jobs as $job) {
    if (!$job['order_raw']) continue;
    if ($oldest === '' || $job['order_raw'] < $oldest) $oldest = $job['order_raw'];
  }
  if (!$oldest) return 'none';
  $days = max(0, (int) floor((current_time('timestamp') - strtotime($oldest.' 00:00:00')) / DAY_IN_SECONDS));
  return $days === 0 ? 'today' : $days.'d';
}

function rumble_dashboard_attention($jobs){
  $items = [];
  foreach ($jobs as $job) {
    if (rumble_dashboard_job_is_overdue($job)) {
      $items[] = [
        'label' => $job['customer'].' is overdue',
        'meta' => 'Due '.$job['due_date'].' · '.$job['status'],
        'tone' => 'danger',
        'url' => $job['url'],
      ];
    } elseif ($job['assignee'] === 'Unassigned' && in_array($job['raw_status'], ['rumble-draft', 'checkout-draft', 'draft', 'pending', 'rumble_quote'], true)) {
      $items[] = [
        'label' => $job['customer'].' has no assignee',
        'meta' => $job['status'].' · Order '.$job['order_date'],
        'tone' => 'risk',
        'url' => $job['url'],
      ];
    } elseif ($job['raw_status'] === 'on-hold') {
      $items[] = [
        'label' => $job['customer'].' is on hold',
        'meta' => $job['title'],
        'tone' => 'waiting',
        'url' => $job['url'],
      ];
    }
  }
  return $items;
}

function rumble_render_dashboard_screen(){
  $dashboard = rumble_dashboard_data();
  $summary = $dashboard['summary'];
  $stages = $dashboard['stages'];
  $my_work = $dashboard['my_work'];
  $assigned_to_me_url = $dashboard['assigned_to_me_url'];
  $attention = $dashboard['attention'];
  $calendar = $dashboard['calendar'];
  ?>
      <section class="rumble-hero" aria-labelledby="rumble-dashboard-title">
        <div>
          <h1 id="rumble-dashboard-title">Status Board</h1>
        </div>
      </section>

      <section class="rumble-summary-grid" aria-label="Workflow summary">
        <?php foreach ($summary as $item): ?>
          <div class="rumble-summary-card is-<?php echo esc_attr($item['tone']); ?>">
            <span><?php echo esc_html($item['label']); ?></span>
            <strong><?php echo esc_html((string) $item['count']); ?></strong>
          </div>
        <?php endforeach; ?>
      </section>

      <section class="rumble-workflow" id="workflow" aria-labelledby="rumble-workflow-title">
        <div class="rumble-section-head">
          <div>
            <p class="rumble-eyebrow">Conveyor belt</p>
            <h2 id="rumble-workflow-title" class="screen-reader-text">Status board stages</h2>
          </div>
        </div>

        <div class="rumble-stage-board">
          <?php foreach ($stages as $stage): ?>
            <article class="rumble-stage" data-rumble-stage-url="<?php echo esc_url($stage['url']); ?>">
              <header>
                <h3><a class="rumble-stage-link" href="<?php echo esc_url($stage['url']); ?>"><?php echo esc_html($stage['name']); ?></a></h3>
                <a class="rumble-stage-count" href="<?php echo esc_url($stage['url']); ?>" aria-label="<?php echo esc_attr('Open '.$stage['name'].' status details'); ?>"><?php echo esc_html((string) $stage['count']); ?></a>
              </header>
              <div class="rumble-stage-meta">
                <span>Oldest <?php echo esc_html($stage['oldest']); ?></span>
                <?php if ($stage['overdue'] > 0): ?>
                  <strong><?php echo esc_html((string) $stage['overdue']); ?> overdue</strong>
                <?php else: ?>
                  <span>No overdue</span>
                <?php endif; ?>
              </div>
              <div class="rumble-job-list">
                <?php foreach ($stage['jobs'] as $job): ?>
                  <a class="rumble-job-card is-<?php echo esc_attr($job['tone']); ?>" href="<?php echo esc_url($job['url']); ?>">
                    <span class="rumble-job-customer"><?php echo esc_html($job['customer']); ?></span>
                    <strong><?php echo esc_html($job['title']); ?></strong>
                    <span class="rumble-job-row">
                      <span>Order <?php echo esc_html($job['order_date'] ?: '-'); ?></span>
                    </span>
                    <span class="rumble-job-foot">
                      <span><?php echo esc_html($job['status']); ?></span>
                      <span>Entered By <?php echo esc_html($job['assignee']); ?> · Due <?php echo esc_html($job['due_date'] ?: 'Not set'); ?></span>
                    </span>
                  </a>
                <?php endforeach; ?>
                <?php if (!$stage['jobs']): ?>
                  <span class="rumble-empty">No active orders</span>
                <?php endif; ?>
              </div>
              <a class="rumble-view-all" href="<?php echo esc_url($stage['url']); ?>">View all</a>
            </article>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="rumble-lower-grid">
        <div class="rumble-panel" id="my-work">
          <div class="rumble-section-head">
            <div>
              <p class="rumble-eyebrow">Assigned to me</p>
              <h2 class="screen-reader-text">Assigned to me</h2>
            </div>
            <a class="rumble-panel-action" href="<?php echo esc_url($assigned_to_me_url); ?>">View All</a>
          </div>
          <div class="rumble-work-list">
            <?php foreach ($my_work as $item): ?>
              <a class="rumble-work-item is-<?php echo esc_attr($item['tone']); ?>" href="<?php echo esc_url($item['url']); ?>">
                <span>
                  <strong><?php echo esc_html($item['customer']); ?></strong>
                  <?php echo esc_html($item['title']); ?>
                </span>
                <small><?php echo esc_html($item['status']); ?> · Order <?php echo esc_html($item['order_date'] ?: '-'); ?> · Due <?php echo esc_html($item['due_date'] ?: 'Not set'); ?></small>
              </a>
            <?php endforeach; ?>
            <?php if (!$my_work): ?>
              <span class="rumble-empty">No assigned work</span>
            <?php endif; ?>
          </div>
        </div>

        <div class="rumble-panel">
          <div class="rumble-section-head">
            <div>
              <p class="rumble-eyebrow">Traffic control</p>
              <h2>Needs attention</h2>
            </div>
            <a class="rumble-panel-action" href="#attention-full">View all</a>
          </div>
          <div class="rumble-attention-list">
            <?php foreach ($attention as $item): ?>
              <a class="rumble-attention-item is-<?php echo esc_attr($item['tone']); ?>" href="<?php echo esc_url($item['url']); ?>">
                <strong><?php echo esc_html($item['label']); ?></strong>
                <span><?php echo esc_html($item['meta']); ?></span>
              </a>
            <?php endforeach; ?>
            <?php if (!$attention): ?>
              <span class="rumble-empty">Nothing needs attention</span>
            <?php endif; ?>
          </div>
        </div>
      </section>

	      <section class="rumble-panel rumble-calendar" id="reports">
	        <div class="rumble-section-head">
	          <div>
	            <h2>Production calendar</h2>
	            <p class="rumble-calendar-month"><?php echo esc_html($calendar['month'] ?? ''); ?></p>
	          </div>
	        </div>
	        <div class="rumble-calendar-grid">
	          <?php foreach (($calendar['days'] ?? []) as $day): ?>
	            <div class="rumble-calendar-day">
	              <header>
	                <strong><?php echo esc_html($day['label']); ?></strong>
	                <span><?php echo esc_html($day['date']); ?></span>
	              </header>
	              <div class="rumble-calendar-day-list">
	                <?php foreach ($day['jobs'] as $item): ?>
	                  <a class="rumble-calendar-item is-<?php echo esc_attr($item['tone']); ?>" href="<?php echo esc_url($item['url']); ?>">
	                    <strong><?php echo esc_html($item['customer']); ?></strong>
	                    <span>Order <?php echo esc_html($item['order_number']); ?></span>
	                  </a>
	                <?php endforeach; ?>
	                <?php if (!$day['jobs']): ?>
	                  <span class="rumble-calendar-empty-day">No production</span>
	                <?php endif; ?>
	              </div>
	            </div>
	          <?php endforeach; ?>
	        </div>
	        <div class="rumble-calendar-not-set">
	          <h3>Production date not set</h3>
	          <div class="rumble-calendar-not-set-list">
	            <?php foreach (($calendar['not_set'] ?? []) as $item): ?>
	              <a class="rumble-calendar-item is-<?php echo esc_attr($item['tone']); ?>" href="<?php echo esc_url($item['url']); ?>">
	                <strong><?php echo esc_html($item['customer']); ?></strong>
	                <span>Order <?php echo esc_html($item['order_number']); ?> · Due <?php echo esc_html($item['due_date'] ?: 'Not set'); ?></span>
	              </a>
	            <?php endforeach; ?>
	            <?php if (empty($calendar['not_set'])): ?>
	              <span class="rumble-empty">All production orders have production dates.</span>
	            <?php endif; ?>
	          </div>
	        </div>
	      </section>
  <?php
}

function rumble_render_status_details_screen(){
  $dashboard = rumble_dashboard_data();
  $requested = sanitize_title((string) ($_GET['status'] ?? ''));
  $selected = null;

  if (strpos($requested, 'assigned-to-') === 0) {
    $jobs = $dashboard['assigned_to_me'] ?? [];
    $selected = [
      'name' => 'Assigned to me',
      'slug' => rumble_assigned_to_me_status_slug(),
      'url' => $dashboard['assigned_to_me_url'] ?? rumble_assigned_to_me_status_url(),
      'count' => count($jobs),
      'oldest' => rumble_dashboard_oldest_label($jobs),
      'overdue' => count(array_filter($jobs, 'rumble_dashboard_job_is_overdue')),
      'jobs' => array_slice($jobs, 0, 4),
      'all_jobs' => $jobs,
    ];
  }

  foreach ($dashboard['stages'] as $stage) {
    if ($selected) break;
    if ($requested === '' || $stage['slug'] === $requested) {
      $selected = $stage;
      break;
    }
  }

  if (!$selected) {
    ?>
    <section class="rumble-panel rumble-status-detail-screen">
      <div class="rumble-section-head">
        <div>
          <p class="rumble-eyebrow">Status details</p>
          <h2>Status not found</h2>
        </div>
        <a href="<?php echo esc_url(home_url('/rumble/')); ?>">Back to board</a>
      </div>
      <p class="rumble-detail-empty">Choose a status from the conveyor belt.</p>
    </section>
    <?php
    return;
  }

  $jobs = $selected['all_jobs'];
  ?>
      <section class="rumble-hero" aria-labelledby="rumble-status-details-title">
        <div>
          <h1 id="rumble-status-details-title"><?php echo esc_html($selected['name']); ?></h1>
        </div>
      </section>

      <section class="rumble-panel rumble-status-detail-screen">
        <div class="rumble-section-head">
          <div>
            <p class="rumble-eyebrow">Status details</p>
            <h2><?php echo esc_html((string) $selected['count']); ?> active <?php echo esc_html($selected['count'] === 1 ? 'order' : 'orders'); ?></h2>
          </div>
          <a href="<?php echo esc_url(home_url('/rumble/')); ?>">Back to board</a>
        </div>

        <div class="rumble-status-metrics">
          <div><span>Oldest</span><strong><?php echo esc_html($selected['oldest']); ?></strong></div>
          <div><span>Overdue</span><strong><?php echo esc_html((string) $selected['overdue']); ?></strong></div>
          <div><span>Status</span><strong><?php echo esc_html($selected['name']); ?></strong></div>
        </div>

        <div class="rumble-status-table-wrap">
          <table class="rumble-status-table">
            <thead>
              <tr>
                <th>Customer</th>
                <th>Order</th>
                <th>Order</th>
                <th>Entered By</th>
                <th>Due</th>
	                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($jobs as $job): ?>
                <tr class="is-<?php echo esc_attr($job['tone']); ?>" data-rumble-row-url="<?php echo esc_url($job['url']); ?>" tabindex="0">
                  <td><strong><?php echo esc_html($job['customer']); ?></strong><span><?php echo esc_html($job['type']); ?> #<?php echo esc_html($job['order_number']); ?></span></td>
                  <td><?php echo esc_html($job['title']); ?><span><?php echo esc_html($job['status']); ?></span></td>
                  <td><?php echo esc_html($job['order_date'] ?: '-'); ?></td>
                  <td><?php echo esc_html($job['assignee']); ?></td>
                  <td><?php echo esc_html($job['due_date'] ?: 'Not set'); ?></td>
	                  <td>
	                    <div class="rumble-status-actions">
	                      <?php if (($job['raw_status'] ?? '') === 'on-order'): ?>
		                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" target="_blank">
		                          <input type="hidden" name="action" value="rumble_generate_workorders_pdf">
			                          <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('rumble_workorders')); ?>">
			                          <input type="hidden" name="order_numbers" value="<?php echo esc_attr((string) $job['order_number']); ?>">
			                          <input type="hidden" name="order_numbers_pdf" value="1">
			                          <button type="submit">Print Work Order</button>
			                        </form>
	                      <?php endif; ?>
	                      <a href="<?php echo esc_url($job['url']); ?>">Open</a>
	                    </div>
	                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$jobs): ?>
                <tr><td colspan="6">No active orders in this status.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
  <?php
}

function rumble_render_work_orders_screen(){
  $nonce = wp_create_nonce('rumble_workorders');
  $action = admin_url('admin-post.php');
  ?>
      <section class="rumble-hero" aria-labelledby="rumble-workorders-title">
        <div>
          <h1 id="rumble-workorders-title">Work Orders</h1>
        </div>
      </section>

      <section class="rumble-panel rumble-workorders-screen">
        <div class="rumble-section-head">
          <div>
            <p class="rumble-eyebrow">Production printouts</p>
            <h2>Print Documents</h2>
          </div>
        </div>

        <div class="rumble-workorder-grid">
          <form class="rumble-workorder-card" method="post" action="<?php echo esc_url($action); ?>" target="_blank">
            <input type="hidden" name="action" value="rumble_generate_workorders_pdf">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
            <input type="hidden" name="statuses" value="on-order,processing">
            <h3>Print New Production Work Orders</h3>
            <p>Prints every unprinted order currently in On Order or Processing, then marks those orders printed.</p>
            <button type="submit">Print Unprinted Work Orders</button>
          </form>

          <form class="rumble-workorder-card" method="post" action="<?php echo esc_url($action); ?>" target="_blank">
            <input type="hidden" name="action" value="rumble_generate_workorders_pdf">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
            <input type="hidden" name="order_numbers_pdf" value="1">
            <h3>Generate by Order Numbers</h3>
            <label>Order numbers
              <textarea name="order_numbers" rows="5" placeholder="44005, 44006, 44007"></textarea>
            </label>
            <label class="rumble-checkline"><input name="combine_order_numbers" type="checkbox" value="1"> Combine listed orders into one popup-store work order</label>
            <button type="submit">Generate PDF</button>
          </form>

          <form class="rumble-workorder-card" method="post" action="<?php echo esc_url($action); ?>" target="_blank">
            <input type="hidden" name="action" value="rumble_generate_packing_slips_pdf">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
            <h3>Packing Slip</h3>
            <label>Order numbers
              <textarea name="order_numbers" rows="5" placeholder="44005, 44006, 44007"></textarea>
            </label>
            <button type="submit">Generate PDF</button>
          </form>

          <form class="rumble-workorder-card" method="post" action="<?php echo esc_url($action); ?>" target="_blank">
            <input type="hidden" name="action" value="rumble_reprint_workorder_pdf">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
            <h3>Reprint Work Order</h3>
            <label>Order number or campaign slug
              <input name="lookup" type="text" required>
            </label>
            <label class="rumble-checkline"><input name="campaign" type="checkbox" value="1"> Treat this as a popup-store campaign</label>
            <button type="submit">Reprint PDF</button>
          </form>

          <form class="rumble-workorder-card" method="post" action="<?php echo esc_url($action); ?>" target="_blank">
            <input type="hidden" name="action" value="rumble_blank_workorder_pdf">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
            <h3>Blank Order Form</h3>
            <p>Printable intake form based on the New Order screen.</p>
            <button type="submit">Print Blank Order Form</button>
          </form>
        </div>
      </section>
  <?php
}

function rumble_new_order_payload_from_order($order){
  if (!$order instanceof WC_Order) return [];
  $created = $order->get_date_created();
  return [
    'order_date' => $created ? $created->date_i18n('Y-m-d') : '',
    'company' => $order->get_billing_company(),
    'buyer_first' => $order->get_billing_first_name(),
    'buyer_last' => $order->get_billing_last_name(),
    'billing_street' => $order->get_billing_address_1(),
    'billing_city' => $order->get_billing_city(),
    'billing_state' => $order->get_billing_state(),
    'billing_zip' => $order->get_billing_postcode(),
    'phone' => $order->get_billing_phone(),
    'email' => $order->get_billing_email(),
    'ship_to' => $order->get_shipping_company(),
    'shipping_street' => $order->get_shipping_address_1(),
    'shipping_city' => $order->get_shipping_city(),
    'shipping_state' => $order->get_shipping_state(),
    'shipping_zip' => $order->get_shipping_postcode(),
	    'needed_by' => rumble_clean($order->get_meta('_rumble_needed_by')),
	    'event_date' => rumble_clean($order->get_meta('_rumble_event_date')),
	    'entered_by' => rumble_clean($order->get_meta('_rumble_entered_by')),
	    'outside_sales' => rumble_clean($order->get_meta('_rumble_outside_sales')),
	    'inside_sales' => rumble_clean($order->get_meta('_rumble_inside_sales')),
	    'designer' => rumble_clean($order->get_meta('_rumble_designer')),
	    'delivery_notes' => rumble_clean($order->get_meta('_rumble_delivery_notes')),
    'hard_deadline_reason' => rumble_clean($order->get_meta('_rumble_hard_deadline_reason')),
    'notes' => $order->get_customer_note(),
    'grand_total' => $order->get_total() ? wc_format_decimal($order->get_total(), wc_get_price_decimals()) : '',
  ];
	}

function rumble_employee_options(){
  $users_by_id = [];
  foreach (get_users([
    'orderby' => 'display_name',
    'order' => 'ASC',
    'fields' => ['ID', 'display_name', 'user_login'],
    'capability' => 'rumble_access',
  ]) as $user) {
    $users_by_id[$user->ID] = $user;
  }
  foreach (get_users([
    'orderby' => 'display_name',
    'order' => 'ASC',
    'fields' => ['ID', 'display_name', 'user_login'],
    'meta_key' => '_rumble_employee',
    'meta_value' => '1',
  ]) as $user) {
    $users_by_id[$user->ID] = $user;
  }
  $options = [];
  foreach ($users_by_id as $user) {
    $name = trim((string) ($user->display_name ?: $user->user_login));
    if ($name === '') continue;
    $options[$name] = $name;
  }
  return array_values($options);
}

function rumble_render_employee_select($name, $label, $selected){
  $selected = rumble_clean($selected);
  ?>
  <label><?php echo esc_html($label); ?>
    <select name="<?php echo esc_attr($name); ?>">
      <option value="">Select employee</option>
      <?php foreach (rumble_employee_options() as $employee): ?>
        <option value="<?php echo esc_attr($employee); ?>" <?php selected($selected, $employee); ?>><?php echo esc_html($employee); ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php
}
	
	function rumble_render_new_order_screen($logo_url){
  $edit_order_id = absint($_GET['order_id'] ?? 0);
  $edit_order = $edit_order_id && function_exists('wc_get_order') ? wc_get_order($edit_order_id) : false;
  $payload = $edit_order ? json_decode((string) $edit_order->get_meta('_rumble_new_order_payload'), true) : [];
  $payload = is_array($payload) ? $payload : [];
  if ($edit_order) {
    $payload = array_merge(rumble_new_order_payload_from_order($edit_order), $payload);
  }
  $draft_items = !empty($payload['items']) && is_array($payload['items']) ? array_values($payload['items']) : [];
  $value = function($key, $default = '') use ($payload) {
    return rumble_clean($payload[$key] ?? $default);
  };
	  $item_value = function($index, $key) use ($draft_items) {
	    return rumble_clean($draft_items[$index][$key] ?? '');
	  };
	  $vendors = function_exists('rumble_bumblebee_vendors') ? rumble_bumblebee_vendors() : [];
  $vendor_codes = is_array($vendors) ? array_column($vendors, 'code') : [];
  $sizes = ['S', 'M', 'L', 'XL', 'Other'];
  $item_row_count = max(8, count($draft_items) + 1);
  $states = [
    'AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD',
    'MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC',
    'SD','TN','TX','UT','VT','VA','WA','WV','WI','WY',
  ];
  ?>
  <section class="rumble-panel rumble-order-screen" id="new-order" aria-labelledby="rumble-new-order-title">
    <div class="rumble-order-header">
      <div></div>
      <?php if ($logo_url): ?>
        <img src="<?php echo esc_url($logo_url); ?>" alt="The Bear Traxs">
      <?php endif; ?>
      <address>
        <strong>10275 Thor Dr.</strong>
        <span>Freeland, MI 48623</span>
        <span>989-326-4140</span>
        <span>info@thebeartraxs.com</span>
      </address>
    </div>

    <div class="rumble-section-head">
      <div>
        <p class="rumble-eyebrow">Order intake</p>
        <h2 id="rumble-new-order-title"><?php echo $edit_order ? 'Edit Order' : 'New Order'; ?></h2>
      </div>
      <div class="rumble-order-actions">
        <button type="button" data-rumble-order-action="auto">Save</button>
      </div>
    </div>

	    <form class="rumble-order-form">
	      <input type="hidden" name="woocommerce_order_number" value="<?php echo esc_attr($edit_order_id ? (string) $edit_order_id : ''); ?>">
	      <div class="rumble-order-meta">
	        <label>In Hands Date<input type="date" name="needed_by" value="<?php echo esc_attr($value('needed_by')); ?>"></label>
	        <label>Event Date<input type="date" name="event_date" value="<?php echo esc_attr($value('event_date')); ?>"></label>
	        <?php rumble_render_employee_select('outside_sales', 'Outside Sales', $value('outside_sales')); ?>
	        <?php rumble_render_employee_select('inside_sales', 'Inside Sales', $value('inside_sales')); ?>
	        <?php rumble_render_employee_select('designer', 'Designer', $value('designer')); ?>
	      </div>

      <div class="rumble-order-grid">
        <fieldset>
          <legend>Customer / Billing</legend>
          <label>Company<input name="company" value="<?php echo esc_attr($value('company')); ?>" autocomplete="off" data-rumble-customer-lookup></label>
          <div class="rumble-two">
            <label>Buyer First<input name="buyer_first" value="<?php echo esc_attr($value('buyer_first')); ?>" autocomplete="off" data-rumble-customer-lookup></label>
            <label>Last<input name="buyer_last" value="<?php echo esc_attr($value('buyer_last')); ?>" autocomplete="off" data-rumble-customer-lookup></label>
          </div>
          <label>Street<input name="billing_street" value="<?php echo esc_attr($value('billing_street')); ?>"></label>
          <div class="rumble-three">
            <label>City<input name="billing_city" value="<?php echo esc_attr($value('billing_city')); ?>"></label>
            <label>State<select name="billing_state"><option value=""></option><?php foreach ($states as $state): ?><option <?php selected($value('billing_state'), $state); ?>><?php echo esc_html($state); ?></option><?php endforeach; ?></select></label>
            <label>Zip<input name="billing_zip" inputmode="numeric" pattern="[0-9]*" value="<?php echo esc_attr($value('billing_zip')); ?>" data-rumble-number-only></label>
          </div>
          <div class="rumble-two">
            <label>Phone<input type="tel" name="phone" inputmode="tel" maxlength="12" pattern="[0-9]{3}-[0-9]{3}-[0-9]{4}" placeholder="xxx-xxx-xxxx" value="<?php echo esc_attr($value('phone')); ?>" data-rumble-phone></label>
            <label>Email<input type="email" name="email" value="<?php echo esc_attr($value('email')); ?>"></label>
          </div>
        </fieldset>

        <fieldset>
          <legend>Shipping / Delivery</legend>
          <div class="rumble-check-row">
            <label><input type="checkbox" name="same_as_billing" <?php checked($value('same_as_billing'), '1'); ?>> Same as billing</label>
            <label><input type="checkbox" name="pickup" <?php checked($value('pickup'), '1'); ?>> Pickup</label>
            <label><input type="checkbox" name="ship" <?php checked($value('ship'), '1'); ?>> Ship</label>
            <label><input type="checkbox" name="deliver" <?php checked($value('deliver'), '1'); ?>> Deliver</label>
          </div>
          <label>Ship To<input name="ship_to" value="<?php echo esc_attr($value('ship_to')); ?>"></label>
          <label>Street<input name="shipping_street" value="<?php echo esc_attr($value('shipping_street')); ?>"></label>
          <div class="rumble-three">
            <label>City<input name="shipping_city" value="<?php echo esc_attr($value('shipping_city')); ?>"></label>
            <label>State<select name="shipping_state"><option value=""></option><?php foreach ($states as $state): ?><option <?php selected($value('shipping_state'), $state); ?>><?php echo esc_html($state); ?></option><?php endforeach; ?></select></label>
            <label>Zip<input name="shipping_zip" inputmode="numeric" pattern="[0-9]*" value="<?php echo esc_attr($value('shipping_zip')); ?>" data-rumble-number-only></label>
          </div>
          <label>Delivery Notes<input name="delivery_notes" value="<?php echo esc_attr($value('delivery_notes')); ?>"></label>
          <label>Hard Deadline Reason<input name="hard_deadline_reason" value="<?php echo esc_attr($value('hard_deadline_reason')); ?>"></label>
        </fieldset>
      </div>

      <fieldset class="rumble-items-fieldset">
        <legend>Items</legend>
        <div class="rumble-items-table" role="table" aria-label="Order items">
          <div class="rumble-items-row rumble-items-head" role="row">
            <span>Qty</span>
            <span>Vendor</span>
            <span>Item Code</span>
            <span>Description</span>
            <span>Color</span>
            <?php foreach ($sizes as $size): ?>
              <span><?php echo esc_html($size); ?></span>
            <?php endforeach; ?>
            <span>Decor Method</span>
            <span>Price Each</span>
            <span>Sales Tax</span>
            <span>Total</span>
          </div>
          <?php for ($i = 1; $i <= $item_row_count; $i++): ?>
            <?php $item_index = $i - 1; ?>
            <div class="rumble-items-row" role="row">
              <input aria-label="Quantity <?php echo esc_attr((string) $i); ?>" name="items[<?php echo esc_attr((string) $i); ?>][qty]" inputmode="numeric" pattern="[0-9]*" placeholder="Qty" value="<?php echo esc_attr($item_value($item_index, 'qty')); ?>" data-rumble-qty data-rumble-number-only readonly>
              <select aria-label="Vendor <?php echo esc_attr((string) $i); ?>" name="items[<?php echo esc_attr((string) $i); ?>][vendor_code]" data-rumble-vendor>
                <option value=""></option>
                <?php foreach ($vendors as $vendor): ?>
                  <option value="<?php echo esc_attr($vendor['code']); ?>" data-name="<?php echo esc_attr($vendor['name']); ?>" <?php selected($item_value($item_index, 'vendor_code'), $vendor['code']); ?>><?php echo esc_html($vendor['name']); ?></option>
                <?php endforeach; ?>
                <?php if ($item_value($item_index, 'vendor_code') && !in_array($item_value($item_index, 'vendor_code'), $vendor_codes, true)): ?>
                  <option value="<?php echo esc_attr($item_value($item_index, 'vendor_code')); ?>" selected><?php echo esc_html($item_value($item_index, 'vendor_code')); ?></option>
                <?php endif; ?>
                <?php if (!$vendors): ?>
                  <option value="" disabled>No Bumblebee vendors found</option>
                <?php endif; ?>
              </select>
              <input type="hidden" name="items[<?php echo esc_attr((string) $i); ?>][vendor]" value="<?php echo esc_attr($item_value($item_index, 'vendor')); ?>" data-rumble-vendor-name>
              <input aria-label="Item code <?php echo esc_attr((string) $i); ?>" name="items[<?php echo esc_attr((string) $i); ?>][item_code]" placeholder="Item Code" value="<?php echo esc_attr($item_value($item_index, 'item_code')); ?>" data-rumble-item-code>
              <textarea aria-label="Description <?php echo esc_attr((string) $i); ?>" name="items[<?php echo esc_attr((string) $i); ?>][description]" rows="1" placeholder="Description" data-rumble-description><?php echo esc_textarea($item_value($item_index, 'description')); ?></textarea>
              <input aria-label="Color <?php echo esc_attr((string) $i); ?>" name="items[<?php echo esc_attr((string) $i); ?>][color]" placeholder="Color" value="<?php echo esc_attr($item_value($item_index, 'color')); ?>">
              <?php foreach ($sizes as $size): ?>
                <input aria-label="<?php echo esc_attr($size . ' quantity ' . $i); ?>" name="items[<?php echo esc_attr((string) $i); ?>][<?php echo esc_attr(strtolower($size)); ?>]" inputmode="numeric" pattern="[0-9]*" placeholder="<?php echo esc_attr($size); ?>" value="<?php echo esc_attr($item_value($item_index, strtolower($size))); ?>" data-rumble-size-qty data-rumble-number-only>
              <?php endforeach; ?>
              <select aria-label="Decor method <?php echo esc_attr((string) $i); ?>" name="items[<?php echo esc_attr((string) $i); ?>][decor_method]">
                <option value=""></option>
                <option <?php selected($item_value($item_index, 'decor_method'), 'Screen Print'); ?>>Screen Print</option>
                <option <?php selected($item_value($item_index, 'decor_method'), 'DTG'); ?>>DTG</option>
                <option <?php selected($item_value($item_index, 'decor_method'), 'Embroidery'); ?>>Embroidery</option>
                <option <?php selected($item_value($item_index, 'decor_method'), 'DTF'); ?>>DTF</option>
              </select>
              <input aria-label="Price each <?php echo esc_attr((string) $i); ?>" name="items[<?php echo esc_attr((string) $i); ?>][price_each]" inputmode="decimal" placeholder="Price Each" value="<?php echo esc_attr($item_value($item_index, 'price_each')); ?>" data-rumble-currency data-rumble-price>
              <label class="rumble-tax-check"><span class="screen-reader-text">Sales tax <?php echo esc_html((string) $i); ?></span><input type="checkbox" name="items[<?php echo esc_attr((string) $i); ?>][sales_tax]" value="1" <?php checked($item_value($item_index, 'sales_tax'), '1'); ?> data-rumble-sales-tax></label>
              <input aria-label="Total <?php echo esc_attr((string) $i); ?>" name="items[<?php echo esc_attr((string) $i); ?>][total]" placeholder="Total" value="<?php echo esc_attr($item_value($item_index, 'total')); ?>" data-rumble-total readonly>
            </div>
          <?php endfor; ?>
        </div>
      </fieldset>

      <div class="rumble-order-bottom">
        <fieldset class="rumble-notes-fieldset">
          <legend>Notes</legend>
          <textarea name="notes" rows="6"><?php echo esc_textarea($value('notes')); ?></textarea>
          <p>Art approval delays may postpone order due date. Custom merchandise is made for the customer and is not subject to cancellation, return, or exchange after approval.</p>
        </fieldset>

        <fieldset class="rumble-totals-fieldset">
          <legend>Totals</legend>
          <label>Art / Setup<input name="art_setup_total" inputmode="decimal" value="<?php echo esc_attr($value('art_setup_total')); ?>" data-rumble-currency></label>
          <label>Rush Fee<input name="rush_fee" inputmode="decimal" value="<?php echo esc_attr($value('rush_fee')); ?>" data-rumble-currency></label>
          <label>Tax<input name="tax" value="<?php echo esc_attr($value('tax')); ?>" data-rumble-tax-total readonly></label>
          <label>Grand Total<input name="grand_total" value="<?php echo esc_attr($value('grand_total')); ?>" data-rumble-grand-total readonly></label>
        </fieldset>
      </div>
    </form>
  </section>
  <?php
}

function rumble_render_job_details_screen(){
  $order_id = absint($_GET['order_id'] ?? 0);
  $quote_id = absint($_GET['quote_id'] ?? 0);
  $order = $order_id && function_exists('wc_get_order') ? wc_get_order($order_id) : false;

  if (!$order && $quote_id) {
    rumble_render_quote_details_screen($quote_id);
    return;
  }

  if (!$order) {
    ?>
    <section class="rumble-panel rumble-job-detail-screen">
      <div class="rumble-section-head">
        <div>
          <p class="rumble-eyebrow">Order details</p>
          <h2>No order selected</h2>
        </div>
      </div>
      <p class="rumble-detail-empty">Pick an order from the dashboard or search results.</p>
    </section>
    <?php
    return;
  }

  $payload = json_decode((string) $order->get_meta('_rumble_new_order_payload'), true);
  $payload = is_array($payload) ? $payload : [];
  $customer_company = rumble_clean($order->get_billing_company() ?: ($payload['company'] ?? ''));
  $customer_first = rumble_clean($order->get_billing_first_name() ?: ($payload['buyer_first'] ?? ''));
  $customer_last = rumble_clean($order->get_billing_last_name() ?: ($payload['buyer_last'] ?? ''));
  $customer = $order->get_billing_company() ?: trim($order->get_billing_first_name().' '.$order->get_billing_last_name());
  $customer = $customer ?: 'Order #'.$order->get_id();
  $raw_status = $order->get_status();
  $selected_status = in_array($raw_status, ['rumble-draft', 'checkout-draft', 'draft'], true) ? 'rumble-draft' : $raw_status;
  $status = rumble_order_status_label($raw_status);
  $needs_design = rumble_order_needs_design($order);
  $order_date = rumble_order_created_date_value($order);
  $production_date = rumble_clean($order->get_meta('_rumble_production_date') ?: ($payload['production_date'] ?? ''));
  $needed_by = rumble_clean($order->get_meta('_rumble_needed_by'));
  $event_date = rumble_clean($order->get_meta('_rumble_event_date'));
  $entered_by = rumble_clean($order->get_meta('_rumble_entered_by'));
  $delivery_notes = rumble_clean($order->get_meta('_rumble_delivery_notes'));
  $deadline_reason = rumble_clean($order->get_meta('_rumble_hard_deadline_reason'));
  $items = rumble_job_detail_items($order, $payload);
  $items_qty_total = array_sum(array_map(function($item){ return absint($item['qty'] ?? 0); }, $items));
  $notes = rumble_order_notes($order->get_id());
  $freshbooks_invoice_id = (string) $order->get_meta(RUMBLE_FB_INVOICE_META);
  $freshbooks_invoice_url = function_exists('rumble_freshbooks_invoice_url') ? rumble_freshbooks_invoice_url($freshbooks_invoice_id) : '';
  $show_freshbooks = function_exists('rumble_freshbooks_current_user_is_eric') && rumble_freshbooks_current_user_is_eric();
  $can_print_work_order = $raw_status === 'on-order';
  $print_work_order_form_id = 'rumble-print-workorder-' . $order->get_id();
  ?>
  <section class="rumble-hero" aria-labelledby="rumble-job-details-title">
    <div>
      <h1 id="rumble-job-details-title"><?php echo esc_html($customer); ?></h1>
    </div>
  </section>

  <section class="rumble-panel rumble-job-detail-screen">
    <div class="rumble-section-head">
      <div>
        <p class="rumble-eyebrow">Order #<?php echo esc_html((string) $order->get_id()); ?></p>
        <h2><?php echo esc_html($status); ?></h2>
      </div>
      <?php if ($can_print_work_order): ?>
        <form id="<?php echo esc_attr($print_work_order_form_id); ?>" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" target="_blank" hidden>
	          <input type="hidden" name="action" value="rumble_generate_workorders_pdf">
	          <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('rumble_workorders')); ?>">
	          <input type="hidden" name="order_numbers" value="<?php echo esc_attr((string) $order->get_order_number()); ?>">
	          <input type="hidden" name="order_numbers_pdf" value="1">
	        </form>
      <?php endif; ?>
      <form class="rumble-status-form" data-rumble-order-id="<?php echo esc_attr((string) $order->get_id()); ?>">
        <label>Status
          <select name="status">
            <?php foreach (rumble_order_status_options() as $value => $label): ?>
              <option value="<?php echo esc_attr($value); ?>" <?php selected($selected_status, $value); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="rumble-design-toggle"><input type="checkbox" name="needs_design" value="1" <?php checked($needs_design); ?>> Needs Design</label>
        <?php if ($can_print_work_order): ?>
          <button type="submit" form="<?php echo esc_attr($print_work_order_form_id); ?>">Print Work Order</button>
        <?php endif; ?>
        <a class="rumble-edit-order-link" href="<?php echo esc_url(rumble_new_order_url($order->get_id())); ?>">Edit</a>
        <?php if ($show_freshbooks && $freshbooks_invoice_id && $raw_status === 'pending'): ?>
          <button type="button" class="rumble-freshbooks-button" data-rumble-freshbooks-invoice data-rumble-order-id="<?php echo esc_attr((string) $order->get_id()); ?>">Finish FreshBooks Setup</button>
        <?php endif; ?>
        <?php if ($show_freshbooks && $raw_status === 'pending' && !$freshbooks_invoice_id): ?>
          <button type="button" class="rumble-freshbooks-button" data-rumble-freshbooks-invoice data-rumble-order-id="<?php echo esc_attr((string) $order->get_id()); ?>">Create FreshBooks Invoice</button>
        <?php endif; ?>
        <button type="submit">Update</button>
      </form>
    </div>

    <div class="rumble-detail-grid">
      <div class="rumble-detail-main">
        <div class="rumble-detail-quick">
          <div><span>In Hands Date</span><strong><?php echo esc_html($needed_by ? date_i18n('M j, Y', strtotime($needed_by)) : 'Not set'); ?></strong></div>
          <div><span>Event Date</span><strong><?php echo esc_html($event_date ? date_i18n('M j, Y', strtotime($event_date)) : 'Not set'); ?></strong></div>
          <div><span>Entered By</span><strong><?php echo esc_html($entered_by ?: 'Unassigned'); ?></strong></div>
        </div>

	        <div class="rumble-detail-card">
	          <h3>Items</h3>
	          <div class="rumble-detail-items rumble-detail-items-grid">
	            <div class="rumble-detail-items-row rumble-detail-items-head">
		              <span>Qty</span>
		              <span>Item</span>
		              <span>Decor Method</span>
		              <span>Vendor Code</span>
		              <span>Item Code</span>
		              <span>Color</span>
	              <span>Sizes</span>
	              <span>Total</span>
	            </div>
		            <?php foreach ($items as $item): ?>
		              <div class="rumble-detail-items-row">
		                <span><?php echo esc_html((string) $item['qty']); ?></span>
		                <span><?php echo esc_html($item['name']); ?></span>
		                <span><?php echo esc_html($item['method'] ?: '-'); ?></span>
		                <span><?php echo esc_html($item['vendor_code'] ?: '-'); ?></span>
		                <span><?php echo esc_html($item['item_code'] ?: '-'); ?></span>
		                <span><?php echo esc_html($item['color'] ?: '-'); ?></span>
	                <span><?php echo esc_html($item['sizes'] ?: '-'); ?></span>
	                <span><?php echo wp_kses_post($item['total']); ?></span>
	              </div>
	            <?php endforeach; ?>
	            <?php if (!$items): ?>
	              <span class="rumble-detail-items-empty">No items entered yet.</span>
	            <?php else: ?>
	              <div class="rumble-detail-items-row rumble-detail-items-total">
	                <span><?php echo esc_html((string) $items_qty_total); ?></span>
	                <span>Totals</span>
		                <span></span>
		                <span></span>
		                <span></span>
		                <span></span>
		                <span></span>
		                <span><?php echo wp_kses_post($order->get_formatted_order_total()); ?></span>
		              </div>
	            <?php endif; ?>
	          </div>
	        </div>

        <div class="rumble-detail-card">
          <h3>Notes</h3>
          <form class="rumble-note-form" data-rumble-order-id="<?php echo esc_attr((string) $order->get_id()); ?>">
            <textarea name="note" rows="3" placeholder="Add a note"></textarea>
            <div>
              <select name="note_type" aria-label="Note type">
                <option value="private" selected>Private note</option>
                <option value="customer">Customer note</option>
              </select>
              <button type="submit">Add Note</button>
            </div>
          </form>
          <div class="rumble-note-list" data-rumble-note-list>
            <?php foreach ($notes as $note): ?>
              <?php rumble_render_order_note($note); ?>
            <?php endforeach; ?>
            <?php if (!$notes): ?>
              <p class="rumble-note-empty">No notes yet.</p>
            <?php endif; ?>
          </div>
          <?php if ($delivery_notes): ?><p><strong>Delivery:</strong> <?php echo esc_html($delivery_notes); ?></p><?php endif; ?>
          <?php if ($deadline_reason): ?><p><strong>Deadline:</strong> <?php echo esc_html($deadline_reason); ?></p><?php endif; ?>
        </div>
      </div>

      <div class="rumble-detail-side-stack">
        <aside class="rumble-detail-side">
          <h3>Customer</h3>
          <dl>
            <dt>Company</dt><dd><?php echo esc_html($customer_company ?: '-'); ?></dd>
            <dt>First Name</dt><dd><?php echo esc_html($customer_first ?: '-'); ?></dd>
            <dt>Last Name</dt><dd><?php echo esc_html($customer_last ?: '-'); ?></dd>
            <dt>Phone</dt><dd><?php echo esc_html($order->get_billing_phone() ?: '-'); ?></dd>
            <dt>Email</dt><dd><?php echo esc_html($order->get_billing_email() ?: '-'); ?></dd>
            <dt>Billing</dt><dd><?php echo esc_html(rumble_order_address_one_line($order, 'billing') ?: '-'); ?></dd>
            <dt>Shipping</dt><dd><?php echo esc_html(rumble_order_address_one_line($order, 'shipping') ?: '-'); ?></dd>
            <dt>Total</dt><dd><?php echo wp_kses_post($order->get_formatted_order_total()); ?></dd>
          </dl>
        </aside>

        <aside class="rumble-detail-side rumble-timeline-card">
          <h3>Timeline</h3>
          <form class="rumble-timeline-form" data-rumble-order-id="<?php echo esc_attr((string) $order->get_id()); ?>">
            <label>Order Date<input type="date" name="order_date" value="<?php echo esc_attr($order_date); ?>" readonly></label>
            <label>Production Date<input type="date" name="production_date" value="<?php echo esc_attr($production_date); ?>"></label>
            <label>In Hands Date<input type="date" name="needed_by" value="<?php echo esc_attr($needed_by); ?>"></label>
            <label>Event Date<input type="date" name="event_date" value="<?php echo esc_attr($event_date); ?>"></label>
            <button type="submit">Update Timeline</button>
          </form>
        </aside>
      </div>
    </div>
  </section>
  <?php
}

function rumble_render_quote_details_screen($quote_id){
  $post = get_post($quote_id);
  if (!$post || $post->post_type !== 'rumble_quote') {
    ?>
    <section class="rumble-panel rumble-job-detail-screen">
      <div class="rumble-section-head">
        <div>
          <p class="rumble-eyebrow">Order details</p>
          <h2>Quote not found</h2>
        </div>
      </div>
    </section>
    <?php
    return;
  }
  ?>
  <section class="rumble-panel rumble-job-detail-screen">
    <div class="rumble-section-head">
      <div>
        <p class="rumble-eyebrow">Quote #<?php echo esc_html((string) $quote_id); ?></p>
        <h2><?php echo esc_html(get_the_title($post)); ?></h2>
      </div>
    </div>
    <p class="rumble-detail-empty">Quote detail view is next. This quote is still inside Rumble.</p>
  </section>
  <?php
}

function rumble_job_detail_items($order, $payload){
  $items = [];
  if (!empty($payload['items']) && is_array($payload['items'])) {
    foreach ($payload['items'] as $raw) {
      if (!is_array($raw)) continue;
      $qty = absint($raw['qty'] ?? 0);
      $description = rumble_clean($raw['description'] ?? '');
      $vendor = rumble_clean($raw['vendor'] ?? '');
      $vendor_code = rumble_clean($raw['vendor_code'] ?? '');
      $item_code = rumble_clean($raw['item_code'] ?? '');
      $vendor_item = $vendor_code && $item_code ? sprintf('%s(%s)', $vendor_code, $item_code) : ($item_code ?: $vendor_code);
      if (!$qty && !$description && !$vendor_item) continue;
      $sizes = [];
      foreach (['s' => 'S', 'm' => 'M', 'l' => 'L', 'xl' => 'XL', 'other' => 'Other'] as $key => $label) {
        $size_qty = absint($raw[$key] ?? 0);
        if ($size_qty) $sizes[] = $label.': '.$size_qty;
      }
      $items[] = [
        'qty' => $qty,
	        'name' => $description ?: $vendor_item,
	        'vendor_code' => $vendor_code,
	        'item_code' => $item_code,
	        'color' => rumble_clean($raw['color'] ?? ''),
        'sizes' => implode(', ', $sizes),
        'method' => rumble_clean($raw['decor_method'] ?? ''),
        'total' => rumble_clean($raw['total'] ?? ''),
      ];
    }
    return $items;
  }

  foreach ($order->get_items() as $item) {
    $items[] = [
	      'qty' => $item->get_quantity(),
	      'name' => $item->get_name(),
	      'vendor_code' => (string) $item->get_meta('Vendor Code'),
	      'item_code' => (string) $item->get_meta('Item Code'),
	      'color' => (string) $item->get_meta('Color'),
      'sizes' => (string) $item->get_meta('Sizes'),
      'method' => (string) $item->get_meta('Decor Method') ?: trim((string) $item->get_meta('Vendor').' '.(string) $item->get_meta('Item Code')),
      'total' => wc_price($item->get_total() + $item->get_total_tax()),
    ];
  }
  return $items;
}

function rumble_order_notes($order_id){
  if (!function_exists('wc_get_order_notes')) return [];
  return wc_get_order_notes([
    'order_id' => $order_id,
    'orderby' => 'date_created',
    'order' => 'DESC',
  ]);
}

function rumble_render_order_note($note){
  $is_customer = !empty($note->customer_note);
  $date = $note->date_created ? $note->date_created->date_i18n('M j, Y g:i a') : '';
  $content = esc_html(wp_strip_all_tags((string) $note->content));
  $content = make_clickable($content);
  $content = nl2br($content);
  $allowed = [
    'a' => [
      'href' => true,
      'title' => true,
      'rel' => true,
      'target' => true,
    ],
    'br' => [],
  ];
  ?>
  <article class="rumble-note <?php echo $is_customer ? 'is-customer' : 'is-private'; ?>">
    <header>
      <strong><?php echo esc_html($is_customer ? 'Customer note' : 'Private note'); ?></strong>
      <span><?php echo esc_html($date); ?></span>
    </header>
    <p><?php echo wp_kses($content, $allowed); ?></p>
  </article>
  <?php
}

function rumble_order_address_one_line($order, $type){
  $parts = $type === 'shipping'
    ? [$order->get_shipping_address_1(), $order->get_shipping_city(), $order->get_shipping_state(), $order->get_shipping_postcode()]
    : [$order->get_billing_address_1(), $order->get_billing_city(), $order->get_billing_state(), $order->get_billing_postcode()];
  return implode(', ', array_filter(array_map('rumble_clean', $parts)));
}
