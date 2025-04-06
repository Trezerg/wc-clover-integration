<?php
/**
 * Clover API client class
 * 
 * @package WC_Clover_Integration
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

if (!class_exists('WC_Clover_API')) {
    class WC_Clover_API {
        
        /**
         * API credentials and config
         */
        private $access_token;
        private $merchant_id;
        private $api_base_url;
        private $ecommerce_base_url;

        /**
         * Constructor
         */
        public function __construct($access_token, $merchant_id) {
            $this->access_token = $access_token;
            $this->merchant_id = $merchant_id;

            // Set the API endpoints
            $this->api_base_url = 'https://api.clover.com/v3/merchants/' . $this->merchant_id . '/';
            $this->ecommerce_base_url = 'https://apisandbox.dev.clover.com/ecommerce/v1/';
        }

        /**
         * Generic method to handle authenticated Clover API requests
         */
        private function request($url, $method = 'GET', $body = null, $is_ecommerce = false) {
            $headers = [
                'Authorization' => 'Bearer ' . $this->access_token,
                'Content-Type'  => 'application/json',
            ];

            $args = [
                'method'  => $method,
                'headers' => $headers,
            ];

            if ($body) {
                $args['body'] = json_encode($body);
            }

            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code($response);
            $response_body = json_decode(wp_remote_retrieve_body($response), true);

            if ($code >= 200 && $code < 300) {
                return $response_body;
            }

            return new WP_Error('clover_api_error', __('API call failed', 'wc-clover-integration'), [
                'code' => $code,
                'response' => $response_body,
            ]);
        }

        /**
         * Create an order using the Clover REST API
         */
        public function create_order($order_data) {
            $url = $this->api_base_url . 'orders';
            return $this->request($url, 'POST', $order_data);
        }

        /**
         * Create an order using the Clover eCommerce API
         */
        public function create_ecommerce_order($order_data) {
            $url = $this->ecommerce_base_url . 'orders';
            return $this->request($url, 'POST', $order_data, true);
        }

        /**
         * Get inventory items
         */
        public function get_inventory_items() {
            $url = $this->api_base_url . 'items';
            return $this->request($url);
        }

        /**
         * Get categories
         */
        public function get_categories() {
            $url = $this->api_base_url . 'categories';
            return $this->request($url);
        }

        /**
         * Get modifier groups
         */
        public function get_modifier_groups() {
            $url = $this->api_base_url . 'modifier_groups';
            return $this->request($url);
        }

        /**
         * Test API connection
         */
        public function test_connection() {
            $url = $this->api_base_url;
            return $this->request($url);
        }
    }
}