<?php
/*
 * File: includes/class-eject-admin.php
 * Description: Admin UI for Eject (scan on-hold orders, generate/delete POs).
 */

if (!defined('ABSPATH')) exit;

class Eject_Admin {
    public static function register_menu(): void {
        return;
    }

    /** Hide noisy notices on our screen. */
    public static function suppress_admin_notices(): void {
        remove_all_actions('admin_notices');
        remove_all_actions('all_admin_notices');
        remove_all_actions('network_admin_notices');
        remove_all_actions('user_admin_notices');
        add_filter('screen_options_show_screen', '__return_false');
        add_filter('admin_body_class', function ($classes) { return $classes . ' eject-admin-page'; });
    }

    public static function render_page(): void {
        if (function_exists('rumble_purchasing_current_user_is_eric') && !rumble_purchasing_current_user_is_eric()) {
            wp_die('You do not have permission to view this page.');
        }

        $huffer_materials_url = admin_url('admin.php?page=huffer&tab=materials');
        $missing_cost_link = sprintf(
            '<a class="eject-size-cost-missing" href="%s" target="_blank" rel="noopener noreferrer">N/A</a>',
            esc_url($huffer_materials_url)
        );

        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $debug_enabled = defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG;
        $debug_flag = isset($_GET['traxs_debug']) && $_GET['traxs_debug'] !== '';
        $debug_po = isset($_GET['traxs_po']) ? sanitize_text_field((string) $_GET['traxs_po']) : '';
        if ($debug_enabled) {
            error_log(sprintf('[Traxs debug] render_page triggered; UA=%s', substr($user_agent, 0, 200)));
        }

        $all_pos = get_posts([
            'post_type'      => 'eject_po',
            'post_status'    => ['publish', 'draft'],
            'numberposts'    => 50,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        $pos = [];
        $pos_ordered = [];
        foreach ($all_pos as $po) {
            $is_ordered = (bool) get_post_meta($po->ID, '_ordered', true);
            if ($is_ordered) {
                $pos_ordered[] = $po;
            } else {
                $pos[] = $po;
            }
        }
        ?>
        <div class="wrap eject-wrap">
                <h1>Traxs – Vendor Purchase Orders</h1>

            <div class="eject-card">
                <div class="eject-card-header">
                    <h2>Create POs from On-Hold Orders</h2>
                    <p>Scans all WooCommerce <strong>On hold</strong> orders, groups items by vendor code, and builds PO numbers using <code>BT-{vendorId}-{MMDDYYYY}-{###}</code>. Cost is pulled from vendor item meta when available.</p>
                </div>
                <div class="eject-card-body">
                    <button class="button button-primary" id="eject-generate-pos">Generate POs now</button>
                    <span class="spinner eject-spinner"></span>
                    <div class="eject-hint">Main vendor (SanMar) threshold: you can delete a PO below $200 and rebuild later.</div>
                    <div id="eject-generation-result"></div>
                </div>
            </div>

            <div class="eject-card">
                <div class="eject-card-header">
                    <h2>Existing POs</h2>
                    <p>Each row shows PO number, total cost, linked WooCommerce orders, and item breakdown by Color → Size.</p>
                </div>
                <div class="eject-card-body">
                    <?php if (empty($pos)) : ?>
                        <p class="description">No POs yet. Generate one above to get started.</p>
                    <?php else : ?>
                        <table class="widefat fixed striped eject-table">
                            <thead>
                                <tr>
                                    <th>PO #</th>
                                    <th>Total Items</th>
                                    <th>Total Cost</th>
                                    <th>Orders</th>
                                    <th>Items</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pos as $po) :
                                    $po_number = get_post_meta($po->ID, '_po_number', true) ?: $po->ID;
                                    $order_ids = (array) get_post_meta($po->ID, '_order_ids', true);
                                    $recalc = null;
                                    if (!empty($order_ids) && class_exists('Eject_Service')) {
                                        $recalc = Eject_Service::recalc_po((int) $po->ID);
                                    }
                                    $items_raw = get_post_meta($po->ID, '_items', true);
                                    $items     = class_exists('Eject_Service')
                                        ? Eject_Service::decode_po_items_meta($items_raw)
                                        : (is_string($items_raw) ? json_decode($items_raw, true) : []);
                                    if (!is_array($items)) $items = [];
                                    if ($debug_enabled && $debug_flag && ($debug_po === '' || (string) $debug_po === (string) $po_number)) {
                                        error_log('[Traxs debug] PO meta dump start');
                                        error_log(sprintf('[Traxs debug] po_id=%d po_number=%s vendor=%s orders=%s total_cost=%s items_raw_type=%s items_raw_len=%s items_count=%d',
                                            (int) $po->ID,
                                            (string) $po_number,
                                            (string) get_post_meta($po->ID, '_vendor_id', true),
                                            json_encode(array_values(array_map('intval', $order_ids))),
                                            (string) get_post_meta($po->ID, '_total_cost', true),
                                            is_string($items_raw) ? 'string' : gettype($items_raw),
                                            is_string($items_raw) ? strlen($items_raw) : 'n/a',
                                            is_array($items) ? count($items) : 0
                                        ));
                                        if ($recalc !== null) {
                                            error_log('[Traxs debug] recalc=' . wp_json_encode($recalc));
                                        }
                                        if (is_string($items_raw)) {
                                            $preview = substr($items_raw, 0, 300);
                                            $preview = preg_replace('/\s+/', ' ', $preview);
                                            $tail = substr($items_raw, -300);
                                            $tail = preg_replace('/\s+/', ' ', $tail);
                                            error_log('[Traxs debug] items_raw_preview=' . $preview);
                                            error_log('[Traxs debug] items_raw_tail=' . $tail);
                                            $json_test = json_decode($items_raw, true);
                                            $json_err = json_last_error_msg();
                                            error_log('[Traxs debug] items_raw_json_type=' . gettype($json_test) . ' json_error=' . $json_err);
                                            $maybe = maybe_unserialize($items_raw);
                                            $maybe_type = gettype($maybe);
                                            $maybe_keys = '';
                                            if (is_array($maybe)) {
                                                $maybe_keys = implode(',', array_slice(array_map('strval', array_keys($maybe)), 0, 3));
                                            }
                                            error_log('[Traxs debug] items_raw_unserialize_type=' . $maybe_type . ' keys=' . $maybe_keys);
                                        }
                                        if (!empty($items)) {
                                            $sample = array_slice($items, 0, 2);
                                            error_log('[Traxs debug] items_sample=' . wp_json_encode($sample));
                                        }
                                        if (!empty($order_ids) && class_exists('Eject_Service')) {
                                            $oid = (int) $order_ids[0];
                                            $order = wc_get_order($oid);
                                            if ($order) {
                                                $lines = \Eject_Service::lines_for_order($order, [get_post_meta($po->ID, '_vendor_id', true)]);
                                                error_log(sprintf('[Traxs debug] order_id=%d lines_for_order=%d', $oid, is_array($lines) ? count($lines) : 0));
                                                if (!empty($lines)) {
                                                    $line_sample = array_slice($lines, 0, 2);
                                                    error_log('[Traxs debug] lines_sample=' . wp_json_encode($line_sample));
                                                }
                                            } else {
                                                error_log(sprintf('[Traxs debug] order_id=%d not found', $oid));
                                            }
                                        }
                                        error_log('[Traxs debug] PO meta dump end');
                                    }
                                    $total     = (float) get_post_meta($po->ID, '_total_cost', true);
                                    $total_items = 0;
                                    foreach ($items as $item) {
                                        $total_items += isset($item['qty']) ? (int)$item['qty'] : 0;
                                    }
                                    $run_id    = get_post_meta($po->ID, '_run_id', true) ?: ('po-'.$po->ID);

                                    // Group items as VendorItem -> Color -> Size => Qty
                                    $tree = [];
                                    $size_order = ['NB','06M','12M','18M','24M','XS','S','M','L','XL','2XL','3XL','4XL','5XL'];
                                    foreach ($items as $item) {
                                        $code  = $item['vendor_item'] ?? $item['product'] ?? 'Item';
                                        $color = $item['color'] ?? 'N/A';
                                        $size  = $item['size'] ?? 'N/A';
                                        $qty   = (int) ($item['qty'] ?? 0);
                                        if (!isset($tree[$code])) $tree[$code] = [];
                                        if (!isset($tree[$code][$color])) $tree[$code][$color] = [];
                                        if (!isset($tree[$code][$color][$size])) $tree[$code][$color][$size] = 0;
                                        $tree[$code][$color][$size] += $qty;
                                    }

                                    // Sort sizes according to desired order
                                    $sort_sizes = function(array $sizes) use ($size_order): array {
                                        uksort($sizes, function($a, $b) use ($size_order) {
                                            $a_i = array_search(strtoupper(trim($a)), $size_order, true);
                                            $b_i = array_search(strtoupper(trim($b)), $size_order, true);
                                            $a_i = ($a_i === false) ? 999 : $a_i;
                                            $b_i = ($b_i === false) ? 999 : $b_i;
                                            if ($a_i === $b_i) return strcmp($a, $b);
                                            return $a_i <=> $b_i;
                                        });
                                        return $sizes;
                                    };
                                    ?>
                                    <tr data-po-id="<?php echo esc_attr($po->ID); ?>">
                                        <td><?php echo esc_html($po_number); ?></td>
                                        <td><?php echo esc_html($total_items); ?></td>
                                        <td><?php echo wp_kses_post(wc_price($total)); ?></td>
                                        <td>
                                            <?php if (!empty($order_ids)) : ?>
                                                <div class="eject-order-chips">
                                                    <?php foreach ($order_ids as $oid) : ?>
                                                        <a class="eject-chip" href="<?php echo esc_url(admin_url('post.php?post=' . absint($oid) . '&action=edit')); ?>" target="_blank" rel="noopener noreferrer">#<?php echo esc_html($oid); ?></a>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else : ?>
                                                <span class="description">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($tree)) : ?>
                                                <div class="eject-item-flat">
                                                    <?php foreach ($tree as $code => $colors) : ?>
                                                        <div class="eject-item-block">
                                                            <div class="eject-item-code"><strong><?php echo esc_html($code); ?></strong></div>
                                                            <?php foreach ($colors as $color => $sizes) : ?>
                                                                <?php $sizes = $sort_sizes($sizes); ?>
                                                                <div class="eject-item-color"><?php echo esc_html($color); ?></div>
                                                                <div class="eject-size-lines">
                                                                    <?php foreach ($sizes as $size => $qty) : ?>
                                                                        <?php
                                                                        // Find unit cost for this vendor item/color/size combo
                                                                        $unit_cost = null;
                                                                        $order_ids = [];
                                                                        foreach ($items as $raw_item) {
                                                                            $matches_code  = ($raw_item['vendor_item'] ?? '') === $code;
                                                                            $matches_color = ($raw_item['color'] ?? '') === $color;
                                                                            $matches_size  = ($raw_item['size'] ?? '') === $size;
                                                                            if ($matches_code && $matches_color && $matches_size) {
                                                                                if ($unit_cost === null && isset($raw_item['unit_cost'])) {
                                                                                    $unit_cost = (float) $raw_item['unit_cost'];
                                                                                }
                                                                                $order_ids = array_merge($order_ids, (array) ($raw_item['order_ids'] ?? []));
                                                                            }
                                                                        }
                                                                        $cost_display = $unit_cost && $unit_cost > 0
                                                                            ? wc_price($unit_cost)
                                                                            : $missing_cost_link;
                                                                        $order_ids = array_values(array_unique(array_filter(array_map('absint', $order_ids))));
                                                                        $order_links = [];
                                                                        $order_numbers = [];
                                                                        if (!empty($order_ids)) {
                                                                            foreach ($order_ids as $oid) {
                                                                                $order_links[] = admin_url('post.php?post=' . $oid . '&action=edit');
                                                                                $order = wc_get_order($oid);
                                                                                if ($order) {
                                                                                    $order_numbers[] = (string)$order->get_order_number();
                                                                                } else {
                                                                                    $order_numbers[] = (string)$oid;
                                                                                }
                                                                            }
                                                                        }
                                                                        $order_links_json = $order_links ? wp_json_encode($order_links) : '';
                                                                        $order_labels_json = $order_numbers ? wp_json_encode(array_map(function($num){ return '#'.$num; }, $order_numbers)) : '';
                                                                       $order_ids_json = $order_ids ? wp_json_encode($order_ids) : '';
                                                                        ?>
                                                                        <label class="eject-size-line">
                                                                            <input type="checkbox" class="eject-size-checkbox" data-code="<?php echo esc_attr($code); ?>" data-color="<?php echo esc_attr($color); ?>" data-size="<?php echo esc_attr($size); ?>" />
                                                                            <span class="eject-size-text">
                                                                                <?php if (!empty($order_links)) : ?>
                                                                                <a class="eject-size-link"<?php echo $order_links_json ? ' data-order-links="' . esc_attr($order_links_json) . '"' : ''; ?><?php echo $order_ids_json ? ' data-order-ids="' . esc_attr($order_ids_json) . '"' : ''; ?><?php echo $order_labels_json ? ' data-order-labels="' . esc_attr($order_labels_json) . '"' : ''; ?> href="<?php echo esc_url($order_links[0]); ?>" target="_blank" rel="noopener noreferrer">
                                                                                        <?php echo esc_html($size); ?> – <?php echo esc_html($qty); ?>
                                                                                    </a>
                                                                                <?php else : ?>
                                                                                    <?php echo esc_html($size); ?> – <?php echo esc_html($qty); ?>
                                                                                <?php endif; ?>
                                                                                <span class="eject-size-cost"><?php echo wp_kses_post($cost_display); ?></span>
                                                                            </span>
                                                                        </label>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else : ?>
                                                <span class="description">No item breakdown saved.</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="eject-actions">
                                                <button type="button" class="button button-link-delete eject-delete-po" data-po="<?php echo esc_attr($po->ID); ?>">Delete</button>
                                                <button type="button" class="button eject-prune-po" data-po="<?php echo esc_attr($po->ID); ?>">Remove selected</button>
                                                <button type="button" class="button eject-po-ordered" data-po="<?php echo esc_attr($po->ID); ?>">Order PO</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <div class="eject-card">
                <div class="eject-card-header eject-ordered-header">
                    <div>
                        <h2>Ordered POs</h2>
                        <p>Previously marked as ordered.</p>
                    </div>
                    <div class="eject-ordered-controls">
                        <input type="search" id="eject-ordered-search" placeholder="Search ordered POs" />
                        <div id="eject-ordered-pagination"></div>
                    </div>
                </div>
                <div class="eject-card-body" id="eject-ordered-body">
                    <?php if (empty($pos_ordered)) : ?>
                        <p class="description">No ordered POs yet.</p>
                    <?php else : ?>
                        <table class="widefat fixed striped eject-table eject-ordered-table">
                            <thead>
                                <tr>
                                    <th>PO #</th>
                                    <th>Total Items</th>
                                    <th>Total Cost</th>
                                    <th>Orders</th>
                                    <th>Ordered At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pos_ordered as $po) :
                                    $po_number = get_post_meta($po->ID, '_po_number', true) ?: $po->ID;
                                    $order_ids = (array) get_post_meta($po->ID, '_order_ids', true);
                                    if (!empty($order_ids) && class_exists('Eject_Service')) {
                                        Eject_Service::recalc_po((int) $po->ID);
                                    }
                                    $items_raw = get_post_meta($po->ID, '_items', true);
                                    $items     = class_exists('Eject_Service')
                                        ? Eject_Service::decode_po_items_meta($items_raw)
                                        : (is_string($items_raw) ? json_decode($items_raw, true) : []);
                                    if (!is_array($items)) $items = [];
                                    $total     = (float) get_post_meta($po->ID, '_total_cost', true);
                                    $total_items = 0;
                                    foreach ($items as $item) {
                                        $total_items += isset($item['qty']) ? (int)$item['qty'] : 0;
                                    }
                                    $ordered_at = get_post_meta($po->ID, '_ordered_at', true);

                                    $tree = [];
                                    $size_order = ['NB','06M','12M','18M','24M','XS','S','M','L','XL','2XL','3XL','4XL','5XL'];
                                    foreach ($items as $item) {
                                        $code  = $item['vendor_item'] ?? $item['product'] ?? 'Item';
                                        $color = $item['color'] ?? 'N/A';
                                        $size  = $item['size'] ?? 'N/A';
                                        $qty   = (int) ($item['qty'] ?? 0);
                                        if (!isset($tree[$code])) $tree[$code] = [];
                                        if (!isset($tree[$code][$color])) $tree[$code][$color] = [];
                                        if (!isset($tree[$code][$color][$size])) $tree[$code][$color][$size] = 0;
                                        $tree[$code][$color][$size] += $qty;
                                    }
                                    $sort_sizes = function(array $sizes) use ($size_order): array {
                                        uksort($sizes, function($a, $b) use ($size_order) {
                                            $a_i = array_search(strtoupper(trim($a)), $size_order, true);
                                            $b_i = array_search(strtoupper(trim($b)), $size_order, true);
                                            $a_i = ($a_i === false) ? 999 : $a_i;
                                            $b_i = ($b_i === false) ? 999 : $b_i;
                                            if ($a_i === $b_i) return strcmp($a, $b);
                                            return $a_i <=> $b_i;
                                        });
                                        return $sizes;
                                    };

                                    $search_blob = strtolower($po_number . ' ' . implode(' ', $order_ids));
                                    ?>
                                    <tr class="eject-ordered-summary" data-po="<?php echo esc_attr($po->ID); ?>" data-search="<?php echo esc_attr($search_blob); ?>">
                                        <td class="eject-ordered-toggle">
                                            <button type="button" class="button-link eject-accordion-toggle" aria-expanded="false">Details</button>
                                            <span class="eject-ordered-po"><?php echo esc_html($po_number); ?></span>
                                        </td>
                                        <td><?php echo esc_html($total_items); ?></td>
                                        <td><?php echo wp_kses_post(wc_price($total)); ?></td>
                                        <td><?php echo !empty($order_ids) ? esc_html(count($order_ids) . ' orders') : '—'; ?></td>
                                        <td><?php echo esc_html($ordered_at ?: '—'); ?></td>
                                        <td><span class="description">Expand for actions</span></td>
                                    </tr>
                                    <tr class="eject-ordered-detail" data-po="<?php echo esc_attr($po->ID); ?>">
                                        <td class="eject-ordered-po"><?php echo esc_html($po_number); ?></td>
                                        <td class="eject-ordered-count"><?php echo esc_html($total_items); ?></td>
                                        <td class="eject-ordered-cost"><?php echo wp_kses_post(wc_price($total)); ?></td>
                                        <td class="eject-ordered-orders">
                                            <?php if (!empty($order_ids)) : ?>
                                                <div class="eject-order-chips">
                                                    <?php foreach ($order_ids as $oid) : ?>
                                                        <a class="eject-chip" href="<?php echo esc_url(admin_url('post.php?post=' . absint($oid) . '&action=edit')); ?>" target="_blank" rel="noopener noreferrer">#<?php echo esc_html($oid); ?></a>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else : ?>
                                                <span class="description">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="eject-ordered-items">
                                            <?php if (!empty($tree)) : ?>
                                                <div class="eject-item-flat">
                                                    <?php foreach ($tree as $code => $colors) : ?>
                                                        <div class="eject-item-block">
                                                            <div class="eject-item-code"><strong><?php echo esc_html($code); ?></strong></div>
                                                            <?php foreach ($colors as $color => $sizes) : ?>
                                                                <?php $sizes = $sort_sizes($sizes); ?>
                                                                <div class="eject-item-color"><?php echo esc_html($color); ?></div>
                                                                <div class="eject-size-lines">
                                                                    <?php foreach ($sizes as $size => $qty) : ?>
                                                                        <?php
                                                                        $unit_cost = null;
                                                                        $order_ids = [];
                                                                        foreach ($items as $raw_item) {
                                                                            $matches_code  = ($raw_item['vendor_item'] ?? '') === $code;
                                                                            $matches_color = ($raw_item['color'] ?? '') === $color;
                                                                            $matches_size  = ($raw_item['size'] ?? '') === $size;
                                                                            if ($matches_code && $matches_color && $matches_size) {
                                                                                if ($unit_cost === null && isset($raw_item['unit_cost'])) {
                                                                                    $unit_cost = (float) $raw_item['unit_cost'];
                                                                                }
                                                                                $order_ids = array_merge($order_ids, (array) ($raw_item['order_ids'] ?? []));
                                                                            }
                                                                        }
                                                                        $cost_display = $unit_cost && $unit_cost > 0
                                                                            ? wc_price($unit_cost)
                                                                            : $missing_cost_link;
                                                                        $order_ids = array_values(array_unique(array_filter(array_map('absint', $order_ids))));
                                                                        $order_links = [];
                                                                        $order_numbers = [];
                                                                        if (!empty($order_ids)) {
                                                                            foreach ($order_ids as $oid) {
                                                                                $order_links[] = admin_url('post.php?post=' . $oid . '&action=edit');
                                                                                $order = wc_get_order($oid);
                                                                                if ($order) {
                                                                                    $order_numbers[] = (string)$order->get_order_number();
                                                                                } else {
                                                                                    $order_numbers[] = (string)$oid;
                                                                                }
                                                                            }
                                                                        }
                                                                        $order_links_json = $order_links ? wp_json_encode($order_links) : '';
                                                                        $order_numbers_json = $order_numbers ? wp_json_encode($order_numbers) : '';
                                                                        ?>
                                                                        <label class="eject-size-line">
                                                                            <input type="checkbox" class="eject-size-checkbox" data-code="<?php echo esc_attr($code); ?>" data-color="<?php echo esc_attr($color); ?>" data-size="<?php echo esc_attr($size); ?>" />
                                                                            <span class="eject-size-text">
                                                                                <?php if (!empty($order_links)) : ?>
                                                                                    <a class="eject-size-link"<?php echo $order_links_json ? ' data-order-links="' . esc_attr($order_links_json) . '"' : ''; ?> href="<?php echo esc_url($order_links[0]); ?>" target="_blank" rel="noopener noreferrer">
                                                                                        <?php echo esc_html($size); ?> – <?php echo esc_html($qty); ?>
                                                                                    </a>
                                                                                <?php else : ?>
                                                                                    <?php echo esc_html($size); ?> – <?php echo esc_html($qty); ?>
                                                                                <?php endif; ?>
                                                                                <span class="eject-size-cost"><?php echo wp_kses_post($cost_display); ?></span>
                                                                            </span>
                                                                        </label>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else : ?>
                                                <span class="description">No item breakdown saved.</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="eject-ordered-actions">
                                            <button type="button" class="button button-link-delete eject-delete-po" data-po="<?php echo esc_attr($po->ID); ?>">Delete</button>
                                            <button type="button" class="button eject-prune-po" data-po="<?php echo esc_attr($po->ID); ?>">Remove selected</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

        </div>
        <?php
    }
}
