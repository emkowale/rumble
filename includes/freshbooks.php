<?php
if (!defined('ABSPATH')) exit;

const RUMBLE_FB_CLIENT_ID = 'rumble_freshbooks_client_id';
const RUMBLE_FB_CLIENT_SECRET = 'rumble_freshbooks_client_secret';
const RUMBLE_FB_ACCESS_TOKEN = 'rumble_freshbooks_access_token';
const RUMBLE_FB_REFRESH_TOKEN = 'rumble_freshbooks_refresh_token';
const RUMBLE_FB_EXPIRES_AT = 'rumble_freshbooks_expires_at';
const RUMBLE_FB_ACCOUNT_ID = 'rumble_freshbooks_account_id';
const RUMBLE_FB_BUSINESS_ID = 'rumble_freshbooks_business_id';
const RUMBLE_FB_INVOICE_META = '_rumble_freshbooks_invoice_id';
const RUMBLE_FB_CLIENT_META = '_rumble_freshbooks_client_id';
const RUMBLE_FB_ATTACH_PDF_META = '_rumble_freshbooks_attach_pdf';

add_action('admin_menu', 'rumble_register_admin_menu');
add_action('admin_init', 'rumble_register_freshbooks_settings');
add_action('admin_init', 'rumble_handle_freshbooks_oauth_callback');
add_action('wp_ajax_rumble_create_freshbooks_invoice', 'rumble_ajax_create_freshbooks_invoice');

function rumble_freshbooks_current_user_is_eric(){
  $user = wp_get_current_user();
  if (!$user || !$user->exists()) return false;

  $candidates = array_filter([
    $user->user_login,
    $user->display_name,
    $user->user_email,
    get_user_meta($user->ID, 'first_name', true),
  ]);

  foreach ($candidates as $candidate) {
    $candidate = strtolower(trim((string) $candidate));
    if ($candidate === 'eric' || str_starts_with($candidate, 'eric@')) return true;
  }

  return false;
}

function rumble_register_admin_menu(){
  if (!rumble_freshbooks_current_user_is_eric()) return;

  add_menu_page(
    'Rumble',
    'Rumble',
    'manage_options',
    'rumble',
    'rumble_render_freshbooks_settings_page',
    'dashicons-clipboard',
    56
  );

  add_submenu_page(
    'rumble',
    'FreshBooks Settings',
    'FreshBooks',
    'manage_options',
    'rumble',
    'rumble_render_freshbooks_settings_page'
  );

  add_submenu_page(
    null,
    'FreshBooks Authorization',
    'FreshBooks Authorization',
    'manage_options',
    'rumble-freshbooks-auth',
    'rumble_render_freshbooks_settings_page'
  );
}

function rumble_register_freshbooks_settings(){
  register_setting('rumble_freshbooks', RUMBLE_FB_CLIENT_ID, ['sanitize_callback' => 'sanitize_text_field']);
  register_setting('rumble_freshbooks', RUMBLE_FB_CLIENT_SECRET, ['sanitize_callback' => 'sanitize_text_field']);
}

function rumble_freshbooks_redirect_uri(){
  return set_url_scheme(admin_url('admin.php?page=rumble-freshbooks-auth'), 'https');
}

function rumble_freshbooks_is_connected(){
  return (bool) (get_option(RUMBLE_FB_REFRESH_TOKEN) && get_option(RUMBLE_FB_ACCOUNT_ID));
}

function rumble_render_freshbooks_settings_page(){
  if (!current_user_can('manage_options') || !rumble_freshbooks_current_user_is_eric()) wp_die('Permission denied.');

  $client_id = (string) get_option(RUMBLE_FB_CLIENT_ID, '');
  $client_secret = (string) get_option(RUMBLE_FB_CLIENT_SECRET, '');
  $account_id = (string) get_option(RUMBLE_FB_ACCOUNT_ID, '');
  $connected = rumble_freshbooks_is_connected();
  $redirect_uri = rumble_freshbooks_redirect_uri();
  $connect_url = '';

  if ($client_id && $client_secret) {
    $state = wp_create_nonce('rumble_freshbooks_oauth');
    $connect_url = add_query_arg([
      'response_type' => 'code',
      'redirect_uri' => $redirect_uri,
      'client_id' => $client_id,
      'state' => $state,
    ], 'https://auth.freshbooks.com/oauth/authorize/');
  }
  ?>
  <div class="wrap">
    <h1>Rumble FreshBooks Settings</h1>

    <?php if (!empty($_GET['rumble_fb_connected'])): ?>
      <div class="notice notice-success"><p>FreshBooks connected.</p></div>
    <?php elseif (!empty($_GET['rumble_fb_error'])): ?>
      <div class="notice notice-error"><p><?php echo esc_html(wp_unslash($_GET['rumble_fb_error'])); ?></p></div>
    <?php endif; ?>

    <form method="post" action="options.php">
      <?php settings_fields('rumble_freshbooks'); ?>
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><label for="rumble_freshbooks_client_id">Client ID</label></th>
          <td><input class="regular-text" id="rumble_freshbooks_client_id" name="<?php echo esc_attr(RUMBLE_FB_CLIENT_ID); ?>" value="<?php echo esc_attr($client_id); ?>" autocomplete="off"></td>
        </tr>
        <tr>
          <th scope="row"><label for="rumble_freshbooks_client_secret">Client Secret</label></th>
          <td><input class="regular-text" type="password" id="rumble_freshbooks_client_secret" name="<?php echo esc_attr(RUMBLE_FB_CLIENT_SECRET); ?>" value="<?php echo esc_attr($client_secret); ?>" autocomplete="new-password"></td>
        </tr>
        <tr>
          <th scope="row">Redirect URI</th>
          <td>
            <code><?php echo esc_html($redirect_uri); ?></code>
            <p class="description">This must exactly match one Redirect URI in the FreshBooks app.</p>
            <p class="description">Required FreshBooks app scopes: user:profile:read, user:clients:read, user:clients:write, user:invoices:read, user:invoices:write, user:online_payments:read, user:online_payments:write.</p>
          </td>
        </tr>
        <tr>
          <th scope="row">Connection</th>
          <td>
            <?php if ($connected): ?>
              <p><strong>Connected</strong><?php echo $account_id ? ' to account '.esc_html($account_id) : ''; ?>.</p>
            <?php else: ?>
              <p><strong>Not connected.</strong></p>
            <?php endif; ?>
            <?php if ($connect_url): ?>
              <p><a class="button button-primary" href="<?php echo esc_url($connect_url); ?>">Connect FreshBooks</a></p>
            <?php else: ?>
              <p class="description">Save the Client ID and Client Secret first, then connect FreshBooks.</p>
            <?php endif; ?>
          </td>
        </tr>
      </table>
      <?php submit_button('Save FreshBooks Settings'); ?>
    </form>
  </div>
  <?php
}

function rumble_handle_freshbooks_oauth_callback(){
  if (!is_admin() || ($_GET['page'] ?? '') !== 'rumble-freshbooks-auth') return;
  if (!current_user_can('manage_options') || !rumble_freshbooks_current_user_is_eric()) return;
  if (empty($_GET['code'])) return;

  $state = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));
  if (!$state || !wp_verify_nonce($state, 'rumble_freshbooks_oauth')) {
    rumble_freshbooks_redirect_with_error('FreshBooks authorization state was invalid.');
  }

  $code = sanitize_text_field(wp_unslash($_GET['code']));
  $token = rumble_freshbooks_token_request([
    'grant_type' => 'authorization_code',
    'code' => $code,
  ]);
  if (is_wp_error($token)) rumble_freshbooks_redirect_with_error($token->get_error_message());

  rumble_freshbooks_store_token_response($token);
  $identity = rumble_freshbooks_api_request('GET', 'https://api.freshbooks.com/auth/api/v1/users/me', null, ['Api-Version' => 'alpha']);
  if (is_wp_error($identity)) rumble_freshbooks_redirect_with_error($identity->get_error_message());

  $account = rumble_freshbooks_account_from_identity($identity);
  if (empty($account['account_id'])) rumble_freshbooks_redirect_with_error('FreshBooks did not return an account ID for this user.');

  update_option(RUMBLE_FB_ACCOUNT_ID, $account['account_id'], false);
  update_option(RUMBLE_FB_BUSINESS_ID, $account['business_id'] ?? '', false);
  wp_safe_redirect(add_query_arg('rumble_fb_connected', '1', admin_url('admin.php?page=rumble')));
  exit;
}

function rumble_freshbooks_redirect_with_error($message){
  wp_safe_redirect(add_query_arg('rumble_fb_error', rawurlencode($message), admin_url('admin.php?page=rumble')));
  exit;
}

function rumble_freshbooks_token_request(array $body){
  $client_id = (string) get_option(RUMBLE_FB_CLIENT_ID, '');
  $client_secret = (string) get_option(RUMBLE_FB_CLIENT_SECRET, '');
  if (!$client_id || !$client_secret) return new WP_Error('rumble_fb_missing_credentials', 'FreshBooks Client ID and Client Secret are required.');

  $body = array_merge([
    'client_id' => $client_id,
    'client_secret' => $client_secret,
    'redirect_uri' => rumble_freshbooks_redirect_uri(),
  ], $body);

  $response = wp_remote_post('https://api.freshbooks.com/auth/oauth/token', [
    'timeout' => 30,
    'headers' => ['Content-Type' => 'application/json'],
    'body' => wp_json_encode($body),
  ]);
  return rumble_freshbooks_decode_response($response, 'FreshBooks token request failed.');
}

function rumble_freshbooks_store_token_response(array $token){
  if (!empty($token['access_token'])) update_option(RUMBLE_FB_ACCESS_TOKEN, (string) $token['access_token'], false);
  if (!empty($token['refresh_token'])) update_option(RUMBLE_FB_REFRESH_TOKEN, (string) $token['refresh_token'], false);
  $expires_in = isset($token['expires_in']) ? absint($token['expires_in']) : HOUR_IN_SECONDS;
  update_option(RUMBLE_FB_EXPIRES_AT, time() + max(60, $expires_in), false);
}

function rumble_freshbooks_access_token(){
  $access_token = (string) get_option(RUMBLE_FB_ACCESS_TOKEN, '');
  $expires_at = absint(get_option(RUMBLE_FB_EXPIRES_AT, 0));
  if ($access_token && $expires_at > time() + 300) return $access_token;

  $refresh_token = (string) get_option(RUMBLE_FB_REFRESH_TOKEN, '');
  if (!$refresh_token) return new WP_Error('rumble_fb_not_connected', 'FreshBooks is not connected.');

  $token = rumble_freshbooks_token_request([
    'grant_type' => 'refresh_token',
    'refresh_token' => $refresh_token,
  ]);
  if (is_wp_error($token)) return $token;

  rumble_freshbooks_store_token_response($token);
  return (string) get_option(RUMBLE_FB_ACCESS_TOKEN, '');
}

function rumble_freshbooks_api_request($method, $url, $body = null, array $extra_headers = []){
  $token = rumble_freshbooks_access_token();
  if (is_wp_error($token)) return $token;

  $args = [
    'method' => $method,
    'timeout' => 30,
    'headers' => array_merge([
      'Authorization' => 'Bearer '.$token,
      'Content-Type' => 'application/json',
    ], $extra_headers),
  ];
  if ($body !== null) $args['body'] = wp_json_encode($body);

  $response = wp_remote_request($url, $args);
  return rumble_freshbooks_decode_response($response, 'FreshBooks API request failed.');
}

function rumble_freshbooks_decode_response($response, $fallback){
  if (is_wp_error($response)) return $response;
  $code = (int) wp_remote_retrieve_response_code($response);
  $raw = (string) wp_remote_retrieve_body($response);
  $data = json_decode($raw, true);
  if ($code < 200 || $code >= 300) {
    $message = $fallback;
    if (is_array($data)) {
      $message = $data['message'] ?? $data['error_description'] ?? $data['error'] ?? $message;
      if (!empty($data['response']['errors'][0]['message'])) $message = $data['response']['errors'][0]['message'];
    }
    return new WP_Error('rumble_fb_api_error', $message, ['status' => $code, 'body' => $data ?: $raw]);
  }
  return is_array($data) ? $data : [];
}

function rumble_freshbooks_account_from_identity(array $identity){
  $response = $identity['response'] ?? $identity;
  foreach (($response['business_memberships'] ?? []) as $membership) {
    $business = $membership['business'] ?? [];
    if (!empty($business['account_id'])) {
      return [
        'account_id' => (string) $business['account_id'],
        'business_id' => isset($business['id']) ? (string) $business['id'] : '',
      ];
    }
  }
  foreach (($response['roles'] ?? []) as $role) {
    if (!empty($role['accountid'])) return ['account_id' => (string) $role['accountid'], 'business_id' => ''];
  }
  return [];
}

function rumble_ajax_create_freshbooks_invoice(){
  check_ajax_referer('rumble', 'nonce');
  if (!rumble_current_user_can_access() || !rumble_freshbooks_current_user_is_eric()) wp_send_json_error(['message' => 'Unauthorized'], 403);
  if (!function_exists('wc_get_order')) wp_send_json_error(['message' => 'WooCommerce required.'], 500);

  $order_id = absint($_POST['order_id'] ?? 0);
  $order = $order_id ? wc_get_order($order_id) : false;
  if (!$order) wp_send_json_error(['message' => 'Order not found.'], 404);
  if ($order->get_status() !== 'pending') wp_send_json_error(['message' => 'Only Needs Invoicing orders can create FreshBooks invoices.'], 400);

  $existing_invoice_id = (string) $order->get_meta(RUMBLE_FB_INVOICE_META);
  $order->add_order_note(
    $existing_invoice_id ? 'Finish FreshBooks Setup button pressed.' : 'Create FreshBooks Invoice button pressed.',
    false,
    true
  );

  if ($existing_invoice_id) {
    $result = rumble_finish_freshbooks_invoice_setup_for_order($order, $existing_invoice_id);
    if (is_wp_error($result)) {
      $error_data = $result->get_error_data();
      $order->add_order_note('FreshBooks invoice setup failed: '.$result->get_error_message(), false, true);
      wp_send_json_error([
        'message' => $result->get_error_message(),
        'details' => is_array($error_data) ? ($error_data['details'] ?? []) : [],
        'status' => is_array($error_data) ? ($error_data['status'] ?? null) : null,
      ], 500);
    }

    wp_send_json_success([
      'message' => 'FreshBooks invoice setup finished.',
      'invoice_id' => $existing_invoice_id,
      'status' => $order->get_status(),
      'status_label' => function_exists('rumble_order_status_label') ? rumble_order_status_label($order->get_status()) : wc_get_order_status_name($order->get_status()),
    ]);
  }

  $result = rumble_create_freshbooks_invoice_for_order($order);
  if (is_wp_error($result)) {
    $error_data = $result->get_error_data();
    $order->add_order_note('FreshBooks invoice failed: '.$result->get_error_message(), false, true);
    wp_send_json_error([
      'message' => $result->get_error_message(),
      'details' => is_array($error_data) ? ($error_data['details'] ?? []) : [],
      'status' => is_array($error_data) ? ($error_data['status'] ?? null) : null,
    ], 500);
  }

  wp_send_json_success([
    'message' => 'FreshBooks draft invoice created.',
    'invoice_id' => $result['invoice_id'],
    'status' => $order->get_status(),
    'status_label' => function_exists('rumble_order_status_label') ? rumble_order_status_label($order->get_status()) : wc_get_order_status_name($order->get_status()),
  ]);
}

function rumble_create_freshbooks_invoice_for_order(WC_Order $order){
  $account_id = (string) get_option(RUMBLE_FB_ACCOUNT_ID, '');
  if (!$account_id) return new WP_Error('rumble_fb_not_connected', 'FreshBooks is not connected. Open Rumble > FreshBooks and connect the account.');

  $client = rumble_freshbooks_find_or_create_client($order, $account_id);
  if (is_wp_error($client)) return $client;

  $invoice = rumble_freshbooks_invoice_payload($order, $client);
  $response = rumble_freshbooks_api_request(
    'POST',
    'https://api.freshbooks.com/accounting/account/'.rawurlencode($account_id).'/invoices/invoices',
    ['invoice' => $invoice]
  );
  if (is_wp_error($response)) return $response;

  $freshbooks_invoice = $response['response']['result']['invoice'] ?? [];
  $invoice_id = $freshbooks_invoice['invoiceid'] ?? $freshbooks_invoice['id'] ?? '';
  if (!$invoice_id) return new WP_Error('rumble_fb_invoice_missing_id', 'FreshBooks created an invoice but did not return an invoice ID.');

  $order->update_meta_data(RUMBLE_FB_INVOICE_META, (string) $invoice_id);
  if (!empty($client['id'])) $order->update_meta_data(RUMBLE_FB_CLIENT_META, (string) $client['id']);
  $order->update_meta_data(RUMBLE_FB_ATTACH_PDF_META, '1');
  $order->save();

  $payment_options = rumble_freshbooks_enable_online_payments($account_id, $invoice_id);
  if (is_wp_error($payment_options)) return $payment_options;

  $late_fee = rumble_freshbooks_enable_late_fee($account_id, $invoice_id, $freshbooks_invoice);
  if (is_wp_error($late_fee)) return $late_fee;

  $order->set_status('on-hold');
  $order->add_order_note('FreshBooks draft invoice '.$invoice_id.' created with online payments and late fees enabled. Attach PDF copy when sending. Status changed to Order Goods.', false, true);
  $order->save();

  return ['invoice_id' => (string) $invoice_id, 'client_id' => (string) ($client['id'] ?? '')];
}

function rumble_finish_freshbooks_invoice_setup_for_order(WC_Order $order, $invoice_id){
  $account_id = (string) get_option(RUMBLE_FB_ACCOUNT_ID, '');
  if (!$account_id) return new WP_Error('rumble_fb_not_connected', 'FreshBooks is not connected. Open Rumble > FreshBooks and connect the account.');

  $payment_options = rumble_freshbooks_enable_online_payments($account_id, $invoice_id);
  if (is_wp_error($payment_options)) return $payment_options;

  $late_fee = rumble_freshbooks_enable_late_fee($account_id, $invoice_id, []);
  if (is_wp_error($late_fee)) return $late_fee;

  $order->update_meta_data(RUMBLE_FB_ATTACH_PDF_META, '1');
  $order->set_status('on-hold');
  $order->add_order_note('FreshBooks draft invoice '.$invoice_id.' setup finished with online payments and late fees enabled. Attach PDF copy when sending. Status changed to Order Goods.', false, true);
  $order->save();

  return ['invoice_id' => (string) $invoice_id];
}

function rumble_freshbooks_enable_online_payments($account_id, $invoice_id){
  $defaults = rumble_freshbooks_api_request(
    'GET',
    'https://api.freshbooks.com/payments/account/'.rawurlencode($account_id).'/payment_options?entity_type=invoice'
  );
  if (is_wp_error($defaults)) return rumble_freshbooks_contextual_error($defaults, 'FreshBooks refused the online payment lookup.', [
    'If the status is 403 Forbidden, add user:online_payments:read and user:online_payments:write to the FreshBooks app, then reconnect FreshBooks from WP Admin > Rumble.',
  ]);

  $payment_options = $defaults['payment_options'] ?? [];
  $gateway = (string) ($payment_options['gateway_name'] ?? '');
  if (!$gateway) return new WP_Error('rumble_fb_no_payment_gateway', 'FreshBooks has no default online payment gateway configured for invoices.');

  $payload = [
    'gateway_name' => $gateway,
    'entity_id' => (int) $invoice_id,
    'entity_type' => 'invoice',
    'has_credit_card' => true,
    'has_ach_transfer' => true,
    'has_bacs_debit' => true,
    'has_sepa_debit' => true,
    'has_paypal_smart_checkout' => true,
    'allow_partial_payments' => true,
  ];

  $response = rumble_freshbooks_api_request(
    'POST',
    'https://api.freshbooks.com/payments/account/'.rawurlencode($account_id).'/invoice/'.rawurlencode($invoice_id).'/payment_options',
    $payload
  );
  if (is_wp_error($response)) return rumble_freshbooks_contextual_error($response, 'FreshBooks refused the online payment setup.', [
    'If the status is 403 Forbidden, add user:online_payments:read and user:online_payments:write to the FreshBooks app, then reconnect FreshBooks from WP Admin > Rumble.',
    'Also confirm online payments are enabled inside FreshBooks for this account.',
  ]);
  return $response;
}

function rumble_freshbooks_enable_late_fee($account_id, $invoice_id, array $freshbooks_invoice){
  $late_fee = isset($freshbooks_invoice['late_fee']) && is_array($freshbooks_invoice['late_fee'])
    ? $freshbooks_invoice['late_fee']
    : [];

  $late_fee = array_merge(rumble_freshbooks_default_late_fee(), $late_fee);
  $late_fee['enabled'] = true;

  $response = rumble_freshbooks_api_request(
    'PUT',
    'https://api.freshbooks.com/accounting/account/'.rawurlencode($account_id).'/invoices/invoices/'.rawurlencode($invoice_id),
    ['invoice' => ['late_fee' => $late_fee]]
  );
  if (is_wp_error($response)) return rumble_freshbooks_contextual_error($response, 'FreshBooks refused the late fee setup.', [
    'Confirm the FreshBooks app still has user:invoices:write and reconnect FreshBooks if scopes changed.',
  ]);
  return $response;
}

function rumble_freshbooks_contextual_error(WP_Error $error, $message, array $details = []){
  $data = $error->get_error_data();
  $status = is_array($data) ? ($data['status'] ?? null) : null;
  $freshbooks_message = $error->get_error_message();
  $combined_details = [];

  if ($status) $combined_details[] = 'FreshBooks HTTP status: '.$status;
  if ($freshbooks_message && $freshbooks_message !== $message) $combined_details[] = 'FreshBooks response: '.$freshbooks_message;
  foreach ($details as $detail) $combined_details[] = $detail;

  return new WP_Error('rumble_fb_contextual_error', $message, [
    'status' => $status,
    'details' => $combined_details,
    'freshbooks' => is_array($data) ? ($data['body'] ?? null) : null,
  ]);
}

function rumble_freshbooks_find_or_create_client(WC_Order $order, $account_id){
  $email = sanitize_email($order->get_billing_email());
  if ($email) {
    $url = add_query_arg('search[email]', $email, 'https://api.freshbooks.com/accounting/account/'.rawurlencode($account_id).'/users/clients');
    $response = rumble_freshbooks_api_request('GET', $url);
    if (is_wp_error($response)) return $response;
    $clients = $response['response']['result']['clients'] ?? [];
    foreach ($clients as $client) {
      if (strcasecmp((string) ($client['email'] ?? ''), $email) === 0) return $client;
    }
  }

  $payload = rumble_freshbooks_client_payload($order);
  $response = rumble_freshbooks_api_request(
    'POST',
    'https://api.freshbooks.com/accounting/account/'.rawurlencode($account_id).'/users/clients',
    ['client' => $payload]
  );
  if (is_wp_error($response)) return $response;

  $client = $response['response']['result']['client'] ?? [];
  if (empty($client['id'])) return new WP_Error('rumble_fb_client_missing_id', 'FreshBooks created a client but did not return a client ID.');
  return $client;
}

function rumble_freshbooks_client_payload(WC_Order $order){
  $company = rumble_clean($order->get_billing_company());
  $first = rumble_clean($order->get_billing_first_name());
  $last = rumble_clean($order->get_billing_last_name());
  if (!$company && (!$first || !$last)) {
    $company = 'Rumble Order '.$order->get_order_number();
  }
  return [
    'fname' => $first,
    'lname' => $last,
    'email' => sanitize_email($order->get_billing_email()),
    'organization' => $company,
    'bus_phone' => rumble_clean($order->get_billing_phone()),
    'p_street' => rumble_clean($order->get_billing_address_1()),
    'p_street2' => rumble_clean($order->get_billing_address_2()),
    'p_city' => rumble_clean($order->get_billing_city()),
    'p_province' => rumble_clean($order->get_billing_state()),
    'p_code' => rumble_clean($order->get_billing_postcode()),
    'p_country' => rumble_freshbooks_country_name($order->get_billing_country()),
    's_street' => rumble_clean($order->get_shipping_address_1()),
    's_street2' => rumble_clean($order->get_shipping_address_2()),
    's_city' => rumble_clean($order->get_shipping_city()),
    's_province' => rumble_clean($order->get_shipping_state()),
    's_code' => rumble_clean($order->get_shipping_postcode()),
    's_country' => rumble_freshbooks_country_name($order->get_shipping_country()),
    'currency_code' => $order->get_currency() ?: 'USD',
    'language' => 'en',
  ];
}

function rumble_freshbooks_invoice_payload(WC_Order $order, array $client){
  $created = $order->get_date_created();
  $billing_country = rumble_freshbooks_country_name($order->get_billing_country());
  return [
    'customerid' => (string) $client['id'],
    'create_date' => $created ? $created->date_i18n('Y-m-d') : current_time('Y-m-d'),
    'currency_code' => $order->get_currency() ?: 'USD',
    'language' => 'en',
    'fname' => rumble_clean($order->get_billing_first_name()),
    'lname' => rumble_clean($order->get_billing_last_name()),
    'organization' => rumble_clean($order->get_billing_company()),
    'street' => rumble_clean($order->get_billing_address_1()),
    'street2' => rumble_clean($order->get_billing_address_2()),
    'city' => rumble_clean($order->get_billing_city()),
    'province' => rumble_clean($order->get_billing_state()),
    'code' => rumble_clean($order->get_billing_postcode()),
    'country' => $billing_country ?: 'United States',
    'po_number' => 'Rumble Order #'.$order->get_order_number(),
    'notes' => 'Created from Rumble order #'.$order->get_order_number(),
    'terms' => 'Payment is due 30 days after the invoice is sent.',
    'due_offset_days' => 30,
    'late_fee' => rumble_freshbooks_default_late_fee(),
    'lines' => rumble_freshbooks_invoice_lines($order),
  ];
}

function rumble_freshbooks_default_late_fee(){
  return [
    'compounded_taxes' => false,
    'days' => 0,
    'enabled' => true,
    'first_tax_name' => null,
    'first_tax_percent' => 0,
    'repeat' => false,
    'second_tax_name' => null,
    'second_tax_percent' => 0,
    'type' => 'percent',
    'calculation_type' => 'total',
    'value' => 10,
  ];
}

function rumble_freshbooks_invoice_lines(WC_Order $order){
  $currency = $order->get_currency() ?: 'USD';
  $lines = [];

  foreach ($order->get_items('line_item') as $item) {
    $qty = max(1, (float) $item->get_quantity());
    $subtotal = (float) $item->get_total();
    $line = [
      'name' => rumble_freshbooks_line_name($item),
      'description' => rumble_freshbooks_line_description($item),
      'qty' => $qty,
      'unit_cost' => ['amount' => rumble_freshbooks_money($subtotal / $qty), 'code' => $currency],
      'amount' => ['amount' => rumble_freshbooks_money($subtotal), 'code' => $currency],
      'type' => null,
    ];
    $line = rumble_freshbooks_apply_tax($line, $subtotal, (float) $item->get_total_tax());
    $lines[] = $line;
  }

  foreach ($order->get_items('fee') as $item) {
    $subtotal = (float) $item->get_total();
    $line = [
      'name' => $item->get_name(),
      'description' => '',
      'qty' => 1,
      'unit_cost' => ['amount' => rumble_freshbooks_money($subtotal), 'code' => $currency],
      'amount' => ['amount' => rumble_freshbooks_money($subtotal), 'code' => $currency],
      'type' => null,
    ];
    $line = rumble_freshbooks_apply_tax($line, $subtotal, (float) $item->get_total_tax());
    $lines[] = $line;
  }

  foreach ($order->get_items('shipping') as $item) {
    $subtotal = (float) $item->get_total();
    $line = [
      'name' => $item->get_name() ?: 'Shipping',
      'description' => '',
      'qty' => 1,
      'unit_cost' => ['amount' => rumble_freshbooks_money($subtotal), 'code' => $currency],
      'amount' => ['amount' => rumble_freshbooks_money($subtotal), 'code' => $currency],
      'type' => null,
    ];
    $line = rumble_freshbooks_apply_tax($line, $subtotal, (float) $item->get_total_tax());
    $lines[] = $line;
  }

  return $lines ?: [[
    'name' => 'Rumble Order #'.$order->get_order_number(),
    'description' => '',
    'qty' => 1,
    'unit_cost' => ['amount' => rumble_freshbooks_money((float) $order->get_total()), 'code' => $currency],
    'amount' => ['amount' => rumble_freshbooks_money((float) $order->get_total()), 'code' => $currency],
    'type' => null,
  ]];
}

function rumble_freshbooks_line_name($item){
  $name = trim((string) $item->get_name());
  $decor_method = trim((string) $item->get_meta('Decor Method', true));
  if ($decor_method === '') return $name;
  if ($name === '') return $decor_method;
  if (stripos($name, $decor_method) !== false) return $name;
  return $name.' - '.$decor_method;
}

function rumble_freshbooks_line_description($item){
  $parts = [];
  foreach (['Color', 'Sizes', 'Decor Method'] as $key) {
    $value = trim((string) $item->get_meta($key, true));
    if ($value !== '') $parts[] = $key.': '.$value;
  }
  return implode("\n", $parts);
}

function rumble_freshbooks_apply_tax(array $line, $subtotal, $tax_total){
  if ($subtotal > 0 && $tax_total > 0) {
    $line['taxName1'] = 'Sales Tax';
    $line['taxAmount1'] = round(($tax_total / $subtotal) * 100, 4);
  }
  return $line;
}

function rumble_freshbooks_money($amount){
  return number_format((float) $amount, 2, '.', '');
}

function rumble_freshbooks_country_name($country_code){
  $country_code = (string) $country_code;
  if ($country_code === '') return '';
  if (function_exists('WC') && WC()->countries) {
    $countries = WC()->countries->get_countries();
    if (isset($countries[$country_code])) return $countries[$country_code];
  }
  return $country_code === 'US' ? 'United States' : $country_code;
}

function rumble_freshbooks_invoice_url($invoice_id){
  $account_id = (string) get_option(RUMBLE_FB_ACCOUNT_ID, '');
  if (!$invoice_id || !$account_id) return '';
  return 'https://my.freshbooks.com/#/invoice/'.$invoice_id;
}
