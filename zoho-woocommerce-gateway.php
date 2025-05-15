<?php
/*
 * Plugin Name:       Zoho WooCommerce Gateway
 * Plugin URI:        https://github.com/SwastikBelieves/zoho-payment-gateway-for-woocommerce
 * Description:       Accept payments via Zoho Payments for WooCommerce. For assistance get in touch with me at hello@swastik.dev
 * Version:           1.1
 * Requires at least: 5.7
 * Requires PHP:      7.4
 * Author:            Swastik Chakraborty
 * Author URI:        https://swastik.dev/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://github.com/SwastikBelieves/zoho-payment-gateway-for-woocommerce
 * Text Domain:       zoho-woocommerce-gateway
 * Domain Path:       /languages
 * Requires Plugins:  WooCommerce
 */


if (!defined('ABSPATH')) exit;

// Load gateway class
add_action('plugins_loaded', 'zoho_wc_gateway_init', 11);
function zoho_wc_gateway_init() {
    if (class_exists('WC_Payment_Gateway')) {
        include_once plugin_dir_path(__FILE__) . 'includes/class-zoho-gateway.php';
    }
}

add_filter('woocommerce_payment_gateways', 'zoho_add_to_gateways');
function zoho_add_to_gateways($methods) {
    $methods[] = 'WC_Gateway_Zoho';
    return $methods;
}

// Cron for token refresh every 55 minutes
add_filter('cron_schedules', function ($schedules) {
    $schedules['fiftyfive_minutes'] = [
        'interval' => 55 * 60,
        'display'  => __('Every 55 Minutes')
    ];
    return $schedules;
});

add_action('init', function () {
    if (!wp_next_scheduled('zoho_refresh_access_token_event')) {
        wp_schedule_event(time(), 'fiftyfive_minutes', 'zoho_refresh_access_token_event');
    }
});

add_action('zoho_refresh_access_token_event', 'zoho_refresh_access_token');
function zoho_refresh_access_token() {
    $settings = get_option('woocommerce_zoho_gateway_settings');
    $token = zoho_get_new_access_token($settings);
    if ($token) {
        update_option('zoho_access_token', $token);
        update_option('zoho_last_token_time', time());
    }
}

function zoho_get_new_access_token($settings = []) {
    if (empty($settings)) {
        $settings = get_option('woocommerce_zoho_gateway_settings');
    }

    $body = [
        'refresh_token' => $settings['refresh_token'],
        'client_id'     => $settings['client_id'],
        'client_secret' => $settings['client_secret'],
        'grant_type'    => 'refresh_token'
    ];

    $response = wp_remote_post('https://accounts.zoho.in/oauth/v2/token', ['body' => $body]);
    if (is_wp_error($response)) return null;

    $data = json_decode(wp_remote_retrieve_body($response), true);
    return $data['access_token'] ?? null;
}

function zoho_get_access_token() {
    $token = get_option('zoho_access_token');
    $last_time = get_option('zoho_last_token_time');

    if (!$token || !$last_time || (time() - $last_time) > (59 * 60)) {
        $settings = get_option('woocommerce_zoho_gateway_settings');
        $token = zoho_get_new_access_token($settings);
        if ($token) {
            update_option('zoho_access_token', $token);
            update_option('zoho_last_token_time', time());
        }
    }

    return $token;
}

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('zoho_refresh_access_token_event');
});


add_action('wp_ajax_zoho_confirm_payment', 'zoho_confirm_payment');
add_action('wp_ajax_nopriv_zoho_confirm_payment', 'zoho_confirm_payment');

function zoho_confirm_payment() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'zoho_payment_update')) {
        wp_send_json_error(__('Security check failed', 'zoho-woocommerce-gateway'));
    }

    $order_id   = intval($_POST['order_id']);
    $session_id = sanitize_text_field($_POST['session_id']);
    $order      = wc_get_order($order_id);

    if (!$order || !$session_id) {
        wp_send_json_error(__('Invalid order or session ID.', 'zoho-woocommerce-gateway'));
    }

    $token      = zoho_get_access_token(); // Make sure this returns a valid token
    
    $account_id = sanitize_text_field($_POST['account_id']);

    $url = "https://payments.zoho.in/api/v1/paymentsessions/{$session_id}?account_id={$account_id}";

    $response = wp_remote_get($url, [
        'headers' => [
            'Authorization' => 'Zoho-oauthtoken ' . $token,
        ]
    ]);

    if (!is_wp_error($response)) {
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (
            isset($body['payments_session']['status']) &&
            $body['payments_session']['status'] === 'succeeded'
        ) {
            $payment_id = $body['payments_session']['payments'][0]['payment_id'] ?? '';
            $order->payment_complete($payment_id);
            $order->add_order_note(__('Zoho payment confirmed. Payment ID: ', 'zoho-woocommerce-gateway') . $payment_id);
            wp_send_json_success();
        } else {
            $error_message = $body['message'] ?? __('Unknown payment status', 'zoho-woocommerce-gateway');
            $order->update_status('failed', $error_message);
            wp_send_json_error($error_message);
        }
    }

    wp_send_json_error(__('Payment verification failed', 'zoho-woocommerce-gateway'));
}

