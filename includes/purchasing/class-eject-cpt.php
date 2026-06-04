<?php
/*
 * File: includes/class-eject-cpt.php
 * Description: Registers the Eject purchase-order custom post type.
 * Plugin: Eject
 */

if (!defined('ABSPATH')) exit;

class Eject_CPT {
    public static function register(): void {
        $labels = [
            'name'               => 'Purchase Orders',
            'singular_name'      => 'Purchase Order',
            'menu_name'          => 'Traxs POs',
            'name_admin_bar'     => 'PO',
            'add_new'            => 'Add New',
            'add_new_item'       => 'Add New PO',
            'new_item'           => 'New PO',
            'edit_item'          => 'Edit PO',
            'view_item'          => 'View PO',
            'all_items'          => 'All POs',
            'search_items'       => 'Search POs',
            'not_found'          => 'No POs found.',
            'not_found_in_trash' => 'No POs found in Trash.',
        ];

        register_post_type('eject_po', [
            'labels'             => $labels,
            'public'             => false,
            'exclude_from_search'=> true,
            'publicly_queryable' => false,
            'show_ui'            => true,
            'show_in_menu'       => false,
            'query_var'          => false,
            'rewrite'            => false,
            'capability_type'    => 'post',
            'has_archive'        => false,
            'hierarchical'       => false,
            'supports'           => ['title'],
        ]);
    }
}
