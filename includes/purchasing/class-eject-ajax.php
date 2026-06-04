<?php
/*
 * File: includes/class-eject-ajax.php
 * Description: AJAX handlers for generating, deleting, and marking POs ordered.
 */

if (!defined('ABSPATH')) exit;

class Eject_Ajax {
    public static function register(): void {
        add_action('wp_ajax_eject_generate_pos', [self::class, 'generate_pos']);
        add_action('wp_ajax_eject_delete_po', [self::class, 'delete_po']);
        add_action('wp_ajax_eject_mark_po_ordered', [self::class, 'mark_po_ordered']);
        add_action('wp_ajax_eject_prune_po', [self::class, 'prune_po']);
        add_action('wp_ajax_eject_emergency_cleanup', [self::class, 'emergency_cleanup']);
    }

    public static function generate_pos(): void {
        self::guard();
        $result = Eject_Service::create_pos_from_on_hold();
        wp_send_json_success($result);
    }

    public static function delete_po(): void {
        self::guard();
        $po_id = isset($_POST['po_id']) ? absint($_POST['po_id']) : 0;
        if (!$po_id) wp_send_json_error(['message' => 'Missing PO ID'], 400);
        $out = Eject_Service::delete_po($po_id);
        if (empty($out['success'])) {
            wp_send_json_error(['message' => $out['message'] ?? 'Failed to delete PO'], 400);
        }
        wp_send_json_success(['message' => $out['message'] ?? 'PO deleted']);
    }

    /** Mark a PO ordered and update related orders. */
    public static function mark_po_ordered(): void {
        self::guard();
        $po_id = isset($_POST['po_id']) ? absint($_POST['po_id']) : 0;
        $has_unordered = !empty($_POST['has_unordered']);
        $keep_items = [];
        if (isset($_POST['keep_items']) && is_array($_POST['keep_items'])) {
            foreach ($_POST['keep_items'] as $it) {
                $code  = isset($it['code']) ? sanitize_text_field($it['code']) : '';
                $color = isset($it['color']) ? sanitize_text_field($it['color']) : '';
                $size  = isset($it['size']) ? sanitize_text_field($it['size']) : '';
                if ($code === '' || $color === '' || $size === '') continue;
                $keep_items[] = ['code' => $code, 'color' => $color, 'size' => $size];
            }
        }
        if (!$po_id) wp_send_json_error(['message' => 'Missing PO ID'], 400);
        $res = Eject_Service::mark_po_ordered($po_id, $has_unordered, $keep_items);
        if (empty($res['success'])) {
            wp_send_json_error(['message' => $res['message'] ?? 'Failed to mark ordered'], 400);
        }
        wp_send_json_success(['message' => 'PO marked ordered', 'po_id' => $po_id]);
    }

    private static function guard(string $cap = 'manage_woocommerce', string $nonce_action = 'eject_admin'): void {
        if (function_exists('rumble_purchasing_current_user_is_eric')) {
            if (!rumble_purchasing_current_user_is_eric()) {
                wp_send_json_error(['message' => 'Permission denied'], 403);
            }
        } elseif (!current_user_can($cap)) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }
        $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field($_REQUEST['_wpnonce']) : '';
        if (!wp_verify_nonce($nonce, $nonce_action)) wp_send_json_error(['message' => 'Bad nonce'], 400);
    }

    /** Remove selected items from a PO and reset order statuses accordingly. */
    public static function prune_po(): void {
        self::guard();
        $po_id = isset($_POST['po_id']) ? absint($_POST['po_id']) : 0;
        $items = isset($_POST['items']) ? (array) $_POST['items'] : [];
        if (!$po_id || empty($items)) {
            wp_send_json_error(['message' => 'Missing PO ID or items'], 400);
        }
        $clean = [];
        foreach ($items as $it) {
            $code  = isset($it['code']) ? sanitize_text_field($it['code']) : '';
            $color = isset($it['color']) ? sanitize_text_field($it['color']) : '';
            $size  = isset($it['size']) ? sanitize_text_field($it['size']) : '';
            if ($code === '' || $color === '' || $size === '') continue;
            $clean[] = ['code' => $code, 'color' => $color, 'size' => $size];
        }
        if (empty($clean)) {
            wp_send_json_error(['message' => 'No valid items to remove'], 400);
        }
        $res = Eject_Service::prune_po_items($po_id, $clean);
        if (empty($res['success'])) {
            wp_send_json_error(['message' => $res['message'] ?? 'Failed to prune PO'], 400);
        }
        wp_send_json_success($res);
    }

    /** Emergency cleanup to remove duplicate POs and reset selected orders. */
    public static function emergency_cleanup(): void {
        self::guard();
        $res = Eject_Service::emergency_cleanup();
        if (empty($res['success'])) {
            wp_send_json_error(['message' => $res['message'] ?? 'Cleanup failed'], 400);
        }
        wp_send_json_success($res);
    }
}
