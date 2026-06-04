<?php
if (!defined('ABSPATH')) exit;

function rumble_send_quote_email($post_id, $data){
  $accept_url = add_query_arg('rumble_accept', $data['token'], home_url('/'));
  $lines = [];
  foreach ($data['items'] as $item){
    $qtys = $item['quantities'] ? 'Qty: '.http_build_query($item['quantities'], '', ', ') : '';
    $lines[] = sprintf('%s — $%0.2f (%s)', $item['title'], $item['price'], $qtys ?: 'No qty');
  }
  $body = [];
  $body[] = 'Hello '.$data['customer']['first_name'].',';
  $body[] = '';
  $body[] = 'Here is your quote from Bear Traxs:';
  $body[] = implode("\n", $lines);
  $body[] = '';
  $body[] = 'Quote expires in 30 days.';
  $body[] = 'Accept here: '.$accept_url;
  $body[] = '';
  $body[] = 'If paying by check you will need to log in.';
  wp_mail($data['customer']['email'], 'Your Bear Traxs Quote', implode("\n", $body));
}
