<?php
/**
 * Plugin Name: WooCommerce Product Tag Cleaner
 * Description: Adds tools in WooCommerce products list to remove product tags from selected products or from all products.
 * Version: 1.0.0
 * Author: Chris Karabinis
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WC_Product_Tag_Cleaner
{
    private const BULK_ACTION = 'wptc_remove_selected_tags';
    private const UNDO_TRANSIENT_PREFIX = 'wptc_undo_';

    public function __construct()
    {
        add_filter('bulk_actions-edit-product', [$this, 'add_bulk_action']);
        add_filter('handle_bulk_actions-edit-product', [$this, 'handle_bulk_action'], 10, 3);
        add_action('load-edit.php', [$this, 'handle_bulk_action_request']);

        add_action('restrict_manage_posts', [$this, 'render_remove_all_button'], 20, 2);
        add_action('admin_post_wptc_remove_all_tags', [$this, 'handle_remove_all_tags']);
        add_action('admin_post_wptc_undo_remove_tags', [$this, 'handle_undo_remove_tags']);

        add_action('admin_notices', [$this, 'admin_notices']);
    }

    public function add_bulk_action(array $bulk_actions): array
    {
        $bulk_actions[self::BULK_ACTION] = __('Remove Tags', 'wpck-woo-product-tag-cleaner');

        return $bulk_actions;
    }

    public function handle_bulk_action(string $redirect_to, string $doaction, array $post_ids): string
    {
        if ($doaction !== self::BULK_ACTION) {
            return $redirect_to;
        }

        if (!current_user_can('edit_products')) {
            return add_query_arg('wptc_error', 'forbidden', $redirect_to);
        }

        $removed_count = 0;

        foreach ($post_ids as $post_id) {
            if (get_post_type($post_id) !== 'product') {
                continue;
            }

            wp_delete_object_term_relationships((int) $post_id, 'product_tag');
            clean_object_term_cache((int) $post_id, 'product');
            $removed_count++;
        }

        return add_query_arg('wptc_removed_selected', (string) $removed_count, $redirect_to);
    }

    public function render_remove_all_button(string $post_type, string $which): void
    {
        if ($which !== 'top' || $post_type !== 'product') {
            return;
        }

        if (!current_user_can('edit_products')) {
            return;
        }

        $action_url = wp_nonce_url(
            add_query_arg('action', 'wptc_remove_all_tags', admin_url('admin-post.php')),
            'wptc_remove_all_tags_action'
        );

        echo '<div class="alignleft actions" style="margin-left:8px;">';
        echo '<a href="' . esc_url($action_url) . '" class="button button-secondary" onclick="return confirm(\'' . esc_js(__('Remove all product tags from ALL products?', 'wpck-woo-product-tag-cleaner')) . '\');">';
        echo esc_html__('Remove all product tags', 'wpck-woo-product-tag-cleaner');
        echo '</a>';
        echo '</div>';
    }

    public function handle_remove_all_tags(): void
    {
        if (!current_user_can('edit_products')) {
            wp_die(esc_html__('You do not have permission.', 'wpck-woo-product-tag-cleaner'));
        }

        check_admin_referer('wptc_remove_all_tags_action');

        $product_ids = get_posts([
            'post_type' => 'product',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'numberposts' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'suppress_filters' => true,
        ]);

        $undo_token = $this->store_undo_snapshot($product_ids);

        $removed_count = 0;

        foreach ($product_ids as $product_id) {
            wp_delete_object_term_relationships((int) $product_id, 'product_tag');
            clean_object_term_cache((int) $product_id, 'product');
            $removed_count++;
        }

        $redirect = add_query_arg(
            [
                'post_type' => 'product',
                'wptc_removed_all' => (string) $removed_count,
                'wptc_undo' => $undo_token,
            ],
            admin_url('edit.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }

    public function admin_notices(): void
    {
        if (!is_admin()) {
            return;
        }

        if (!isset($_GET['post_type']) || sanitize_text_field(wp_unslash($_GET['post_type'])) !== 'product') {
            return;
        }

        if (!empty($_GET['wptc_error']) && sanitize_text_field(wp_unslash($_GET['wptc_error'])) === 'no_items') {
            echo '<div class="notice notice-warning is-dismissible"><p>';
            echo esc_html__('Please select at least one product before running this bulk action.', 'wpck-woo-product-tag-cleaner');
            echo '</p></div>';
        }

        if (!empty($_GET['wptc_error']) && sanitize_text_field(wp_unslash($_GET['wptc_error'])) === 'forbidden') {
            echo '<div class="notice notice-error is-dismissible"><p>';
            echo esc_html__('You do not have permission to remove product tags.', 'wpck-woo-product-tag-cleaner');
            echo '</p></div>';
        }

        if (isset($_GET['wptc_removed_selected'])) {
            $count = (int) $_GET['wptc_removed_selected'];
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo esc_html(sprintf(__('Removed tags from %d selected products.', 'wpck-woo-product-tag-cleaner'), $count));
            $this->render_undo_link();
            echo '</p></div>';
        }

        if (isset($_GET['wptc_removed_all'])) {
            $count = (int) $_GET['wptc_removed_all'];
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo esc_html(sprintf(__('Removed tags from %d products.', 'wpck-woo-product-tag-cleaner'), $count));
            $this->render_undo_link();
            echo '</p></div>';
        }

        if (isset($_GET['wptc_undo_done'])) {
            $count = (int) $_GET['wptc_undo_done'];
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo esc_html(sprintf(__('Undo completed. Restored tags to %d products.', 'wpck-woo-product-tag-cleaner'), $count));
            echo '</p></div>';
        }

        if (!empty($_GET['wptc_error']) && sanitize_text_field(wp_unslash($_GET['wptc_error'])) === 'undo_unavailable') {
            echo '<div class="notice notice-warning is-dismissible"><p>';
            echo esc_html__('Undo is no longer available for this action.', 'wpck-woo-product-tag-cleaner');
            echo '</p></div>';
        }
    }

    public function handle_bulk_action_request(): void
    {
        if (!is_admin()) {
            return;
        }

        $screen_post_type = isset($_REQUEST['post_type']) ? sanitize_text_field(wp_unslash($_REQUEST['post_type'])) : '';
        if ($screen_post_type !== 'product') {
            return;
        }

        $action = $this->get_current_bulk_action();
        if ($action !== self::BULK_ACTION) {
            return;
        }

        if (!current_user_can('edit_products')) {
            $redirect = add_query_arg(['post_type' => 'product', 'wptc_error' => 'forbidden'], admin_url('edit.php'));
            wp_safe_redirect($redirect);
            exit;
        }

        $post_ids = isset($_REQUEST['post']) ? array_map('intval', (array) wp_unslash($_REQUEST['post'])) : [];
        if (empty($post_ids)) {
            $redirect = add_query_arg(['post_type' => 'product', 'wptc_error' => 'no_items'], admin_url('edit.php'));
            wp_safe_redirect($redirect);
            exit;
        }

        $undo_token = $this->store_undo_snapshot($post_ids);

        $removed_count = 0;
        foreach ($post_ids as $post_id) {
            if (get_post_type($post_id) !== 'product') {
                continue;
            }

            wp_set_object_terms((int) $post_id, [], 'product_tag');
            clean_object_term_cache((int) $post_id, 'product');
            $removed_count++;
        }

        $redirect = add_query_arg(
            [
                'post_type' => 'product',
                'wptc_removed_selected' => (string) $removed_count,
                'wptc_undo' => $undo_token,
            ],
            admin_url('edit.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }

    private function get_current_bulk_action(): string
    {
        $action = '';

        if (isset($_REQUEST['action']) && $_REQUEST['action'] !== '-1') {
            $action = sanitize_text_field(wp_unslash($_REQUEST['action']));
        }

        if (empty($action) && isset($_REQUEST['action2']) && $_REQUEST['action2'] !== '-1') {
            $action = sanitize_text_field(wp_unslash($_REQUEST['action2']));
        }

        return $action;
    }

    public function handle_undo_remove_tags(): void
    {
        if (!current_user_can('edit_products')) {
            wp_die(esc_html__('You do not have permission.', 'wpck-woo-product-tag-cleaner'));
        }

        $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
        if ($token === '') {
            $this->redirect_with_error('undo_unavailable');
        }

        check_admin_referer('wptc_undo_remove_tags_' . $token);

        $payload = get_transient($this->undo_transient_key($token));
        if (!is_array($payload) || empty($payload['user_id']) || empty($payload['data'])) {
            $this->redirect_with_error('undo_unavailable');
        }

        if ((int) $payload['user_id'] !== (int) get_current_user_id()) {
            $this->redirect_with_error('forbidden');
        }

        $restored_count = 0;
        foreach ($payload['data'] as $product_id => $term_ids) {
            $product_id = (int) $product_id;
            if (get_post_type($product_id) !== 'product') {
                continue;
            }

            $term_ids = array_map('intval', (array) $term_ids);
            wp_set_object_terms($product_id, $term_ids, 'product_tag', false);
            clean_object_term_cache($product_id, 'product');
            $restored_count++;
        }

        delete_transient($this->undo_transient_key($token));

        $redirect = add_query_arg(
            ['post_type' => 'product', 'wptc_undo_done' => (string) $restored_count],
            admin_url('edit.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }

    private function render_undo_link(): void
    {
        $token = isset($_GET['wptc_undo']) ? sanitize_text_field(wp_unslash($_GET['wptc_undo'])) : '';
        if ($token === '') {
            return;
        }

        $undo_url = wp_nonce_url(
            add_query_arg(
                [
                    'action' => 'wptc_undo_remove_tags',
                    'token' => $token,
                ],
                admin_url('admin-post.php')
            ),
            'wptc_undo_remove_tags_' . $token
        );

        echo '&nbsp;';
        echo '<a href="' . esc_url($undo_url) . '" class="button button-small">';
        echo esc_html__('Undo', 'wpck-woo-product-tag-cleaner');
        echo '</a>';
    }

    private function store_undo_snapshot(array $product_ids): string
    {
        $snapshot = [];

        foreach ($product_ids as $product_id) {
            $product_id = (int) $product_id;
            if (get_post_type($product_id) !== 'product') {
                continue;
            }

            $term_ids = wp_get_object_terms($product_id, 'product_tag', ['fields' => 'ids']);
            if (is_wp_error($term_ids) || empty($term_ids)) {
                continue;
            }

            $snapshot[$product_id] = array_map('intval', $term_ids);
        }

        $token = wp_generate_uuid4();
        set_transient(
            $this->undo_transient_key($token),
            [
                'user_id' => (int) get_current_user_id(),
                'data' => $snapshot,
            ],
            30 * MINUTE_IN_SECONDS
        );

        return $token;
    }

    private function undo_transient_key(string $token): string
    {
        return self::UNDO_TRANSIENT_PREFIX . md5($token);
    }

    private function redirect_with_error(string $error): void
    {
        $redirect = add_query_arg(
            ['post_type' => 'product', 'wptc_error' => $error],
            admin_url('edit.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }
}

new WC_Product_Tag_Cleaner();
