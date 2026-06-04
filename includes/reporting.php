<?php
if (!defined('ABSPATH')) exit;

function rumble_reporting_active_statuses(){
  return ['rumble-draft', 'pending', 'on-hold', 'on-order', 'processing'];
}

function rumble_reporting_status_targets(){
  return [
    'rumble-draft' => 2,
    'pending' => 1,
    'on-hold' => 3,
    'on-order' => 7,
    'processing' => 5,
  ];
}

function rumble_reporting_duration_label($hours){
  $hours = max(0, (float) $hours);
  if ($hours <= 0) return '0h';
  if ($hours < 24) return max(1, (int) round($hours)).'h';
  $days = floor($hours / 24);
  $remaining = (int) round($hours - ($days * 24));
  return $remaining > 0 ? $days.'d '.$remaining.'h' : $days.'d';
}

function rumble_reporting_status_started_at(WC_Order $order){
  $meta_started = (int) $order->get_meta('_rumble_status_started_at');
  if ($meta_started > 0) return $meta_started;

  $current = $order->get_status();
  $current_label = function_exists('rumble_order_status_label') ? rumble_order_status_label($current) : wc_get_order_status_name($current);
  $notes = function_exists('wc_get_order_notes') ? wc_get_order_notes([
    'order_id' => $order->get_id(),
    'type' => 'internal',
    'orderby' => 'date_created',
    'order' => 'DESC',
    'limit' => 40,
  ]) : [];

  foreach ($notes as $note) {
    $content = wp_strip_all_tags((string) $note->content);
    if (stripos($content, 'status changed') === false) continue;
    if (stripos($content, ' to '.$current_label) === false && stripos($content, ' to '.$current) === false) continue;
    if ($note->date_created) return $note->date_created->getTimestamp();
  }

  $created = $order->get_date_created();
  return $created ? $created->getTimestamp() : current_time('timestamp');
}

function rumble_reporting_order_assignees(WC_Order $order){
  $names = [];
  foreach (['_rumble_entered_by', '_rumble_inside_sales', '_rumble_outside_sales'] as $meta_key) {
    $name = rumble_clean($order->get_meta($meta_key));
    if ($name !== '') $names[$name] = $name;
  }
  if (function_exists('rumble_order_needs_design') && rumble_order_needs_design($order)) {
    $designer = rumble_clean($order->get_meta('_rumble_designer'));
    if ($designer !== '') $names[$designer] = $designer;
  }
  return array_values($names);
}

function rumble_reporting_order_rows(){
  if (!function_exists('wc_get_orders')) return [];

  $orders = wc_get_orders([
    'limit' => -1,
    'orderby' => 'date',
    'order' => 'DESC',
    'status' => array_merge(rumble_reporting_active_statuses(), [
      'wc-rumble-draft',
      'wc-pending',
      'wc-on-hold',
      'wc-on-order',
      'wc-processing',
    ]),
  ]);

  $targets = rumble_reporting_status_targets();
  $now = current_time('timestamp');
  $rows = [];

  foreach ($orders as $order) {
    if (!$order instanceof WC_Order) continue;
    $status = $order->get_status();
    $started = rumble_reporting_status_started_at($order);
    $hours = max(0, ($now - $started) / HOUR_IN_SECONDS);
    $days = $hours / 24;
    $target = (float) ($targets[$status] ?? 5);
    $payload = json_decode((string) $order->get_meta('_rumble_new_order_payload'), true);
    $job = function_exists('rumble_dashboard_job_from_order') ? rumble_dashboard_job_from_order($order) : [];
    $assignees = rumble_reporting_order_assignees($order);

    $rows[] = [
      'order_id' => $order->get_id(),
      'order_number' => $order->get_order_number(),
      'customer' => $job['customer'] ?? ($order->get_billing_company() ?: trim($order->get_billing_first_name().' '.$order->get_billing_last_name()) ?: 'Order #'.$order->get_id()),
      'title' => $job['title'] ?? (is_array($payload) ? rumble_clean($payload['items'][0]['description'] ?? '') : ''),
      'status' => $status,
      'status_label' => function_exists('rumble_order_status_label') ? rumble_order_status_label($status) : wc_get_order_status_name($status),
      'started' => $started,
      'started_label' => date_i18n('M j, Y g:i a', $started),
      'hours' => $hours,
      'days' => $days,
      'duration_label' => rumble_reporting_duration_label($hours),
      'target_days' => $target,
      'over_target' => $days > $target,
      'over_by_days' => max(0, $days - $target),
      'assignees' => $assignees,
      'assignee_label' => implode(', ', $assignees) ?: 'Unassigned',
      'url' => function_exists('rumble_job_details_url') ? rumble_job_details_url($order->get_id()) : '#',
    ];
  }

  usort($rows, function($a, $b){
    if ($a['over_target'] !== $b['over_target']) return $a['over_target'] ? -1 : 1;
    return $b['hours'] <=> $a['hours'];
  });

  return $rows;
}

function rumble_reporting_summary(array $rows){
  $targets = rumble_reporting_status_targets();
  $summary = [];
  foreach (rumble_reporting_active_statuses() as $status) {
    $summary[$status] = [
      'status' => $status,
      'label' => function_exists('rumble_order_status_label') ? rumble_order_status_label($status) : wc_get_order_status_name($status),
      'count' => 0,
      'total_hours' => 0,
      'oldest_hours' => 0,
      'over_target' => 0,
      'target_days' => $targets[$status] ?? 5,
    ];
  }
  foreach ($rows as $row) {
    if (!isset($summary[$row['status']])) continue;
    $summary[$row['status']]['count']++;
    $summary[$row['status']]['total_hours'] += $row['hours'];
    $summary[$row['status']]['oldest_hours'] = max($summary[$row['status']]['oldest_hours'], $row['hours']);
    if ($row['over_target']) $summary[$row['status']]['over_target']++;
  }
  foreach ($summary as &$item) {
    $item['average_hours'] = $item['count'] ? $item['total_hours'] / $item['count'] : 0;
  }
  unset($item);
  return array_values($summary);
}

function rumble_reporting_user_summary(array $rows){
  $users = [];
  foreach ($rows as $row) {
    $assignees = $row['assignees'] ?: ['Unassigned'];
    foreach ($assignees as $name) {
      if (!isset($users[$name])) {
        $users[$name] = [
          'name' => $name,
          'orders' => 0,
          'over_target' => 0,
          'total_over_by_days' => 0,
          'worst_order' => null,
        ];
      }
      $users[$name]['orders']++;
      if ($row['over_target']) {
        $users[$name]['over_target']++;
        $users[$name]['total_over_by_days'] += $row['over_by_days'];
        if (!$users[$name]['worst_order'] || $row['over_by_days'] > $users[$name]['worst_order']['over_by_days']) {
          $users[$name]['worst_order'] = $row;
        }
      }
    }
  }

  usort($users, function($a, $b){
    if ($a['over_target'] !== $b['over_target']) return $b['over_target'] <=> $a['over_target'];
    return $b['total_over_by_days'] <=> $a['total_over_by_days'];
  });

  return $users;
}

function rumble_record_status_change_start($order_id, $old_status, $new_status, $order){
  if (!$order instanceof WC_Order) return;
  $now = current_time('timestamp');
  $history = $order->get_meta('_rumble_status_history');
  $history = is_array($history) ? $history : [];
  $history[] = [
    'from' => sanitize_key($old_status),
    'to' => sanitize_key($new_status),
    'at' => $now,
    'user_id' => get_current_user_id(),
  ];
  if (count($history) > 100) $history = array_slice($history, -100);
  $order->update_meta_data('_rumble_status_started_at', $now);
  $order->update_meta_data('_rumble_status_history', $history);
  $order->save_meta_data();
}
add_action('woocommerce_order_status_changed', 'rumble_record_status_change_start', 10, 4);

function rumble_render_reporting_screen(){
  if (!rumble_current_user_can_reporting()) {
    wp_die('You do not have access to Rumble reporting.', 'Rumble Access Denied', ['response' => 403]);
  }

  $rows = rumble_reporting_order_rows();
  $status_summary = rumble_reporting_summary($rows);
  $user_summary = rumble_reporting_user_summary($rows);
  $late_count = count(array_filter($rows, function($row){ return $row['over_target']; }));
  ?>
      <section class="rumble-hero" aria-labelledby="rumble-reporting-title">
        <div>
          <h1 id="rumble-reporting-title">Reporting</h1>
        </div>
      </section>

      <section class="rumble-summary-grid rumble-report-summary" aria-label="Reporting summary">
        <div class="rumble-summary-card"><span>Active Orders</span><strong><?php echo esc_html((string) count($rows)); ?></strong></div>
        <div class="rumble-summary-card <?php echo $late_count ? 'is-danger' : ''; ?>"><span>Over Target</span><strong><?php echo esc_html((string) $late_count); ?></strong></div>
        <div class="rumble-summary-card"><span>Statuses</span><strong><?php echo esc_html((string) count($status_summary)); ?></strong></div>
      </section>

      <section class="rumble-panel rumble-reporting-screen">
        <div class="rumble-section-head">
          <div>
            <p class="rumble-eyebrow">Status aging</p>
            <h2>How long orders are in each status</h2>
          </div>
        </div>
        <div class="rumble-status-table-wrap">
          <table class="rumble-status-table rumble-report-table">
            <thead><tr><th>Status</th><th>Active</th><th>Average Time</th><th>Oldest</th><th>Target</th><th>Over Target</th></tr></thead>
            <tbody>
              <?php foreach ($status_summary as $item): ?>
                <tr class="<?php echo $item['over_target'] ? 'is-danger' : ''; ?>">
                  <td><strong><?php echo esc_html($item['label']); ?></strong></td>
                  <td><?php echo esc_html((string) $item['count']); ?></td>
                  <td><?php echo esc_html(rumble_reporting_duration_label($item['average_hours'])); ?></td>
                  <td><?php echo esc_html(rumble_reporting_duration_label($item['oldest_hours'])); ?></td>
                  <td><?php echo esc_html((string) $item['target_days']); ?>d</td>
                  <td><?php echo esc_html((string) $item['over_target']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="rumble-panel rumble-reporting-screen">
        <div class="rumble-section-head">
          <div>
            <p class="rumble-eyebrow">Employee aging</p>
            <h2>Users taking too long</h2>
          </div>
        </div>
        <div class="rumble-status-table-wrap">
          <table class="rumble-status-table rumble-report-table">
            <thead><tr><th>User</th><th>Assigned Orders</th><th>Over Target</th><th>Total Over By</th><th>Worst Order</th></tr></thead>
            <tbody>
              <?php foreach ($user_summary as $user): ?>
                <?php $worst = $user['worst_order']; ?>
                <tr class="<?php echo $user['over_target'] ? 'is-danger' : ''; ?>">
                  <td><strong><?php echo esc_html($user['name']); ?></strong></td>
                  <td><?php echo esc_html((string) $user['orders']); ?></td>
                  <td><?php echo esc_html((string) $user['over_target']); ?></td>
                  <td><?php echo esc_html(rumble_reporting_duration_label($user['total_over_by_days'] * 24)); ?></td>
                  <td>
                    <?php if ($worst): ?>
                      <a href="<?php echo esc_url($worst['url']); ?>">#<?php echo esc_html((string) $worst['order_number']); ?> <?php echo esc_html($worst['customer']); ?></a>
                      <span><?php echo esc_html($worst['status_label']); ?> · <?php echo esc_html($worst['duration_label']); ?></span>
                    <?php else: ?>
                      <span>No late orders</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$user_summary): ?>
                <tr><td colspan="5">No active assigned orders found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="rumble-panel rumble-reporting-screen">
        <div class="rumble-section-head">
          <div>
            <p class="rumble-eyebrow">Order detail</p>
            <h2>Current status age by order</h2>
          </div>
        </div>
        <div class="rumble-status-table-wrap">
          <table class="rumble-status-table rumble-report-table">
            <thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Status Started</th><th>Time In Status</th><th>Target</th><th>Assigned Users</th></tr></thead>
            <tbody>
              <?php foreach ($rows as $row): ?>
                <tr class="<?php echo $row['over_target'] ? 'is-danger' : ''; ?>" data-rumble-row-url="<?php echo esc_url($row['url']); ?>" tabindex="0">
                  <td><a href="<?php echo esc_url($row['url']); ?>">#<?php echo esc_html((string) $row['order_number']); ?></a><span><?php echo esc_html($row['title'] ?: 'Order'); ?></span></td>
                  <td><strong><?php echo esc_html($row['customer']); ?></strong></td>
                  <td><?php echo esc_html($row['status_label']); ?></td>
                  <td><?php echo esc_html($row['started_label']); ?></td>
                  <td><?php echo esc_html($row['duration_label']); ?></td>
                  <td><?php echo esc_html((string) $row['target_days']); ?>d</td>
                  <td><?php echo esc_html($row['assignee_label']); ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$rows): ?>
                <tr><td colspan="7">No active orders found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
  <?php
}
