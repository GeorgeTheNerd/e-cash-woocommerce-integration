<?php

add_filter('woocommerce_payment_gateways', 'add_gateway_class');
function add_gateway_class($gateways)
{
    $gateways[] = 'ECASH_WC_GATEWAY';
    return $gateways;
}

add_action('plugins_loaded', 'init_gateway_class');

function init_gateway_class(): void
{
    class ECASH_WC_GATEWAY extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id = 'e-cash';
            $this->icon = '';
            $this->has_fields = false;
            $this->method_title = 'E-cash Gateway';
            $this->method_description = 'Pay with E-cash';
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');
            $this->testmode = 'yes' === $this->get_option('testmode');
            $this->username = $this->get_option('username');
            $this->password = $this->get_option('password');
            $this->terminalId = $this->get_option('terminal_id');
            $this->supports = array(
                'products'
            );

            $this->init_form_fields();
            $this->init_settings();
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_api_ecash_payment_confirmation', array($this, 'payment_confirmation'));
        }

        public function init_form_fields(): void
        {
            $this->form_fields = include("form_fields.php");
        }

        public function payment_fields(): void
        {
            echo "<p>$this->description</p>";
        }

        public function validate_fields(): bool
        {
            return true;
        }

        private function can_process_payment(): bool
        {
            return
                (is_cart() || is_checkout()) &&
                'no' !== $this->enabled &&
                !empty($this->username) &&
                !empty($this->password) &&
                !empty($this->terminalId) &&
                ($this->testmode || is_ssl());
        }

        private function build_response_array(bool $success, string $redirect = null): array
        {
            return [
                'result' => $success ? 'success' : 'failure',
                'redirect' => $redirect
            ];
        }

        private function failure_response_array(): array
        {
            return $this->build_response_array(false);
        }

        private function set_transaction_id(WC_Order $order, string $transactionId): void
        {
            $order->add_meta_data('transaction_id', $transactionId);
        }

        private function get_transaction_id(WC_Order $order): string
        {
            return $order->get_meta('transaction_id');
        }

        private function process_payment_success(WC_Order $order, string $transactionId): void
        {
            global $woocommerce;
            $woocommerce->cart->empty_cart();
            $order->set_status('wc-processing-p');
            $this->set_transaction_id($order, $transactionId);
            $order->save();
        }

        private function get_request_header(): array
        {
            $username = $this->username;
            $password = $this->password;
            return array(
                'username' => $username,
                'password' => $password,
            );
        }

        private function get_create_payment_request_body($total): array
        {
            $lang = get_locale() == "en_GB" ? "en" : "ar";
            $terminalId = $this->terminalId;
            $amount = $total;
            $callbackUrl = get_site_url() . "/my-account/orders/";
            $triggerUrl = get_site_url() . "add_gateway.php/";

            return array(
                "lang" => $lang,
                "terminalId" => $terminalId,
                "amount" => $amount,
                "callbackURL" => $callbackUrl,
                "triggerURL" => $triggerUrl,
            );
        }

        private function get_endpoint(): string
        {
            return $this->testmode == "no" ? 'https://egate-t.ecash.me/api' : 'https://egate.ecash.me/api';
        }

        public function process_payment($order_id): array
        {
            if (!$this->can_process_payment())
                return $this->failure_response_array();

            $url = $this->get_endpoint() . '/create-payment';

            $order = wc_get_order($order_id);
            $response = wp_remote_post($url, array(
                'headers' => $this->get_request_header(),
                'body' => $this->get_create_payment_request_body($order->get_total()),
            ));


            if (!($response instanceof WP_Error)) {
                $responseBody = json_decode($response['body']);
                if ($responseBody->ErrorCode == 0) {
                    $this->process_payment_success($order, $responseBody['Data']['paymentId']);
                    return $this->build_response_array(true, $responseBody['Data']['url']);
                }
            }

            return $this->failure_response_array();
        }

        private function process_payment_accepted(WC_Order $order)
        {
            $order->payment_complete();
            wc_reduce_stock_levels($order->get_id());
        }

        private function process_payment_rejected(WC_Order $order)
        {
            $order->set_status('wc-failed');
        }

        public function payment_confirmation(): void
        {
            $orderId = $_GET['id'];
            $order = wc_get_order($orderId);

            $transaction_id = $this->get_transaction_id($order);
            $response = wp_remote_get($this->get_endpoint() . '/get-payment-status/' . $transaction_id);
            if (!($response instanceof WP_Error)) {
                $responseBody = json_decode($response['body']);
                if ($responseBody->ErrorCode == 0 && $responseBody['Data']['Status'] == 'A') {
                    $this->process_payment_accepted($order);
                }
            }
            $this->process_payment_rejected($order);
        }
    }

}