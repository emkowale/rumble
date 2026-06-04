<?php
if (!defined('ABSPATH')) exit;

add_action('template_redirect', 'rumble_handle_accept_page');

function rumble_handle_accept_page(){
  if (!rumble_is_accept_page()) return;
  $token = sanitize_text_field($_GET['rumble_accept']);
  $post = rumble_quote_by_token($token);
  if (!$post) wp_die('Quote not found.');
  $data = get_post_meta($post->ID, '_rumble', true);
  if (!is_array($data)) wp_die('Invalid quote.');
  if (!empty($data['accepted'])) wp_die('Quote already accepted.');
  if (!empty($data['expires']) && $data['expires'] < time()) wp_die('Quote expired.');
  if ($_SERVER['REQUEST_METHOD']==='POST'){
    check_admin_referer('rumble_accept');
    $msg = rumble_accept_quote($post->ID, $data);
    wp_die($msg, 'Quote Accepted');
  }
  rumble_render_accept_page($data);
  exit;
}

function rumble_quote_by_token($token){
  $q = new WP_Query([
    'post_type' => 'rumble_quote',
    'post_status' => 'publish',
    'meta_key' => '_rumble_token',
    'meta_value' => $token,
    'fields' => 'ids',
    'posts_per_page' => 1,
  ]);
  if (empty($q->posts)) return null;
  return get_post($q->posts[0]);
}

function rumble_render_accept_page($data){
  status_header(200);
  ?>
  <div class="rumble-accept">
    <h1>Review Quote</h1>
    <p class="rumble-meta">Customer: <?php echo esc_html($data['customer']['first_name'].' '.$data['customer']['last_name']); ?> — Expires in 30 days</p>
    <ul>
      <?php foreach ($data['items'] as $item): ?>
        <li><?php echo esc_html($item['title']); ?> — $<?php echo number_format($item['price'],2); ?></li>
      <?php endforeach; ?>
    </ul>
    <p class="rumble-alert">By accepting you agree this quote expires in 30 days.</p>
    <form method="post">
      <?php wp_nonce_field('rumble_accept'); ?>
      <button type="submit">Accept Quote</button>
    </form>
  </div>
  <?php
}
