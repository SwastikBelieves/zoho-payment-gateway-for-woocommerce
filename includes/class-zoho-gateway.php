<?php

if (!defined('ABSPATH')) exit;

class WC_Gateway_Zoho extends WC_Payment_Gateway {

    public function __construct() {
        $this->id = 'zoho_gateway';
        $this->has_fields = false;
        $this->method_title = 'Zoho Payments';
        $this->method_description = 'Accept payments via Zoho Payment Gateway.';

        $this->init_form_fields();
        $this->init_settings();

        foreach ($this->settings as $key => $val) {
            $this->$key = $val;
        }

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page']);
    }

    public function init_form_fields() {
        $this->form_fields = [
            'enabled'       => ['title' => 'Enable', 'type' => 'checkbox', 'label' => 'Enable Zoho Gateway', 'default' => 'yes'],
            'title'         => ['title' => 'Title', 'type' => 'text', 'default' => 'Zoho Pay'],
            'client_id'     => ['title' => 'Client ID', 'type' => 'text'],
            'client_secret' => ['title' => 'Client Secret', 'type' => 'text'],
            'refresh_token' => ['title' => 'Refresh Token', 'type' => 'text'],
            'account_id'    => ['title' => 'Account ID', 'type' => 'text'],
            'api_key'       => ['title' => 'API Key', 'type' => 'text'],
            'business_name' => ['title' => 'Business Name', 'type' => 'text'],
        ];
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        $token = zoho_get_access_token();
        if (!$token) {
            wc_add_notice('Zoho token error.', 'error');
            return;
        }

        $body = json_encode([
            'amount'        => (float) $order->get_total(),
            'currency'      => get_woocommerce_currency(),
            'meta_data'     => [['key' => 'order_id', 'value' => $order_id]],
            'description'   => 'Payment for Order #' . $order_id,
            'invoice_number'=> 'INV-' . $order_id
        ]);

        $response = wp_remote_post("https://payments.zoho.in/api/v1/paymentsessions?account_id=" . $this->account_id, [
            'headers' => [
                'Authorization' => 'Zoho-oauthtoken ' . $token,
                'Content-Type'  => 'application/json'
            ],
            'body' => $body
        ]);

        if (is_wp_error($response)) {
            wc_add_notice('Failed to create payment session.', 'error');
            return;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $session_id = $data['payments_session']['payments_session_id'] ?? null;

        if (!$session_id) {
            wc_add_notice('Could not generate payment session.', 'error');
            return;
        }

        update_post_meta($order_id, '_zoho_payment_session_id', $session_id);
        return ['result' => 'success', 'redirect' => $order->get_checkout_order_received_url()];
    }

    public function thankyou_page($order_id) {
        $order = wc_get_order($order_id);
        $session_id = get_post_meta($order_id, '_zoho_payment_session_id', true);
        if (!$session_id) return;

        echo '<script src="https://static.zohocdn.com/zpay/zpay-js/v1/zpayments.js"></script>';
        echo '<script>
            const config = {
                account_id: "' . esc_js($this->account_id) . '",
                domain: "IN",
                otherOptions: { api_key: "' . esc_js($this->api_key) . '" }
            };
            const instance = new window.ZPayments(config);
            async function initiatePayment() {
                try {
                    let options = {
                        amount: "' . $order->get_total() . '",
                        currency_code: "INR",
                        payments_session_id: "' . esc_js($session_id) . '",
                        currency_symbol: "₹",
                        business: "' . esc_js($this->business_name) . '",
                        description: "Payment for Order #' . $order_id . '",
                        invoice_number: "INV-' . $order_id . '",
                        reference_number: "REF-' . $order_id . '",
                        address: {
                            name: "' . esc_js($order->get_billing_first_name()) . '",
                            email: "' . esc_js($order->get_billing_email()) . '",
                            phone: "' . esc_js($order->get_billing_phone()) . '"
                        }
                    };
                    const data = await instance.requestPaymentMethod(options);
                    console.log("Zoho Payment Result:", data);
                } catch (err) {
                    console.error("Zoho Payment Error:", err);
                } finally {
                    await instance.close();
                }
            }
            initiatePayment();
        </script>';
    }
}
