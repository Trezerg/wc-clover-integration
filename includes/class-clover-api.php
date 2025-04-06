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
        private $client_id;
        private $client_secret;
        private $access_token;
        private $merchant_id;
        private $sandbox_mode;
        private $api_base_url;
        private $oauth_token_url;
        
        /**
         * Constructor
         */
        public function __construct($client_id, $client_secret, $access_token, $merchant_id, $sandbox_mode = false) {
            $this->client_id = $client_id;
            $this->client_secret = $client_secret;
            $this->access_token = $access_token;
            $this->merchant_id = $merchant_id;
            $this->sandbox_mode = $sandbox_mode;
            
            // Set the API endpoints based on sandbox mode
            if ($sandbox_mode) {
                $this->api_base_url = 'https://sandbox.dev.clover.com/v3';
                $this->oauth_token_url = 'https://sandbox.dev.clover.com/oauth/token';
            } else {
                $this->api_base_url = 'https://api.clover.com/v3';
                $this->oauth_token_url = 'https://api.clover.com/oauth/token';
            }
            
            WC_Clover_Logger::log("Initialized Clover API in " . ($sandbox_mode ? "sandbox" : "production") . " mode", 'debug');
        }
        
        /**
         * Get access token from authorization code
         */
        public function get_access_token($code) {
            $callback_url = home_url('clover-oauth-callback');
            
            WC_Clover_Logger::log("Getting access token from " . $this->oauth_token_url, 'debug');
            WC_Clover_Logger::log("Using callback URL: " . $callback_url, 'debug');

            $response = wp_remote_post($this->oauth_token_url, array(
                'method' => 'POST',
                'body' => array(
                    'client_id' => $this->client_id,
                    'client_secret' => $this->client_secret,
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $callback_url
                )
            ));
            
            if (is_wp_error($response)) {
                return $response;
            }
            
            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (!isset($body['access_token'])) {
                return new WP_Error('missing_token', __('Access token not found in response', 'wc-clover-integration'));
            }
            
            return array(
                'access_token' => $body['access_token'],
                'merchant_id' => $body['merchant_id']
            );
        }
        
        /**
         * Create order in Clover
         */
        public function create_order($order_data) {
            if (empty($this->access_token) || empty($this->merchant_id)) {
                return new WP_Error('missing_credentials', __('Clover API credentials are missing', 'wc-clover-integration'));
            }
            
            $url = sprintf('%s/merchants/%s/orders', $this->api_base_url, $this->merchant_id);
            
            WC_Clover_Logger::log("Creating order in Clover: " . $url, 'debug');
            
            $clover_data = $this->convert_order_data_for_clover($order_data);
            WC_Clover_Logger::log("Clover order data: " . json_encode($clover_data), 'debug');
            
            $response = wp_remote_post($url, array(
                'method' => 'POST',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->access_token,
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode($clover_data)
            ));
            
            WC_Clover_Logger::log("Clover API response: " . wp_remote_retrieve_body($response), 'debug');
            
            if (is_wp_error($response)) {
                return $response;
            }
            
            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (!isset($body['id'])) {
                return new WP_Error('order_creation_failed', __('Failed to create order in Clover', 'wc-clover-integration'));
            }
            
            return $body;
        }
        
        /**
         * Convert WooCommerce order data to Clover format
         */
        private function convert_order_data_for_clover($order_data) {
            // Map WooCommerce order data to Clover format
            $clover_order = array(
                'state' => 'open',
                'title' => 'WC Order #' . $order_data['metadata']['order_number'],
                'note' => $order_data['note'],
                'taxRemoved' => false,
                'manualTransaction' => true,
                'groupLineItems' => false
            );
            
            // Add line items
            $clover_line_items = array();
            
            foreach ($order_data['lineItems'] as $item) {
                $line_item = array(
                    'name' => $item['name'],
                    'price' => (int)($item['price'] * 100), // Convert to cents for Clover
                    'unitQty' => $item['quantity'],
                    'note' => $item['notes']
                );
                
                $clover_line_items[] = $line_item;
            }
            
            $clover_order['lineItems'] = array(
                'elements' => $clover_line_items
            );
            
            // Add customer info if available
            if (!empty($order_data['customer']['firstName']) || !empty($order_data['customer']['lastName'])) {
                $clover_order['customerInfo'] = array(
                    'firstName' => $order_data['customer']['firstName'],
                    'lastName' => $order_data['customer']['lastName'],
                    'phoneNumber' => $order_data['customer']['phone'],
                    'emailAddress' => $order_data['customer']['email'],
                    'marketingAllowed' => false
                );
                
                // Add address if available
                if (!empty($order_data['customer']['address']['line1'])) {
                    $clover_order['customerInfo']['address'] = array(
                        'address1' => $order_data['customer']['address']['line1'],
                        'address2' => $order_data['customer']['address']['line2'],
                        'city' => $order_data['customer']['address']['city'],
                        'state' => $order_data['customer']['address']['state'],
                        'zip' => $order_data['customer']['address']['zip'],
                        'country' => $order_data['customer']['address']['country']
                    );
                }
            }
            
            // Add order metadata as attributes
            $clover_order['attributes'] = array();
            
            foreach ($order_data['metadata'] as $key => $value) {
                $clover_order['attributes'][] = array(
                    'name' => $key,
                    'value' => $value
                );
            }
            
            return $clover_order;
        }
        
        /**
         * Print bill from Clover POS
         */
        public function print_bill($order_id) {
            if (empty($this->access_token) || empty($this->merchant_id)) {
                return new WP_Error('missing_credentials', __('Clover API credentials are missing', 'wc-clover-integration'));
            }
            
            $url = sprintf('%s/merchants/%s/orders/%s/print', $this->api_base_url, $this->merchant_id, $order_id);
            
            $response = wp_remote_post($url, array(
                'method' => 'POST',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->access_token,
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode(array(
                    'printerId' => 'default', // Use default printer
                    'type' => 'receipt',
                    'includeItems' => true,
                    'includeTotals' => true
                ))
            ));
            
            WC_Clover_Logger::log("Clover API print response: " . wp_remote_retrieve_body($response), 'debug');
            
            if (is_wp_error($response)) {
                return $response;
            }
            
            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            // Check if printing was successful
            if (wp_remote_retrieve_response_code($response) !== 200) {
                return new WP_Error('print_failed', __('Failed to print bill from Clover', 'wc-clover-integration'));
            }
            
            return true;
        }
        
        /**
         * Get list of printers from Clover
         */
        public function get_printers() {
            if (empty($this->access_token) || empty($this->merchant_id)) {
                return new WP_Error('missing_credentials', __('Clover API credentials are missing', 'wc-clover-integration'));
            }
            
            $url = sprintf('%s/merchants/%s/printers', $this->api_base_url, $this->merchant_id);
            
            $response = wp_remote_get($url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->access_token
                )
            ));
            
            if (is_wp_error($response)) {
                return $response;
            }
            
            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (!isset($body['elements'])) {
                return new WP_Error('printers_not_found', __('Failed to retrieve printers from Clover', 'wc-clover-integration'));
            }
            
            return $body['elements'];
        }
        
        /**
         * Test API connection
         */
        public function test_connection() {
            if (empty($this->access_token) || empty($this->merchant_id)) {
                return new WP_Error('missing_credentials', __('Clover API credentials are missing', 'wc-clover-integration'));
            }
            
            $url = sprintf('%s/merchants/%s', $this->api_base_url, $this->merchant_id);
            
            $response = wp_remote_get($url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->access_token
                )
            ));
            
            if (is_wp_error($response)) {
                return $response;
            }
            
            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (!isset($body['id']) || $body['id'] !== $this->merchant_id) {
                return new WP_Error('connection_failed', __('Failed to connect to Clover API', 'wc-clover-integration'));
            }
            
            return $body;
        }

        /**
         * Generic method to handle authenticated Clover API requests
         */
        public function request($endpoint, $method = 'GET', $body = null) {
            if (empty($this->access_token) || empty($this->merchant_id)) {
                return new WP_Error('clover_auth_error', __('Missing Clover credentials.', 'wc-clover-integration'));
            }

            $url = $this->api_base_url . '/merchants/' . $this->merchant_id . '/' . ltrim($endpoint, '/');

            $args = [
                'method'  => $method,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->access_token,
                    'Content-Type'  => 'application/json'
                ],
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
                'response' => $response_body
            ]);
        }

        /**
         * Get inventory items from Clover
         */
        public function get_inventory_items() {
            return $this->request('items');
        }

        /**
         * Get categories from Clover
         */
        public function get_categories() {
            return $this->request('categories');
        }

        /**
         * Get modifier groups from Clover
         */
        public function get_modifier_groups() {
            return $this->request('modifier_groups');
        }
    }
}