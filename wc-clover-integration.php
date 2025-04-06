<?php
/**
 * Plugin Name: WooCommerce to Clover POS Integration
 * Plugin URI: https://yourwebsite.com/wc-clover-integration
 * Description: Syncs WooCommerce orders with Clover POS and prints bills automatically
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://yourwebsite.com
 * Text Domain: wc-clover-integration
 * Domain Path: /languages
 * WC requires at least: 5.0.0
 * WC tested up to: 8.5.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('WC_CLOVER_INTEGRATION_VERSION', '1.0.0');
define('WC_CLOVER_INTEGRATION_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_CLOVER_INTEGRATION_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * The core plugin class
 */
class WC_Clover_Integration {

    /**
     * Instance of this class
     */
    protected static $instance = null;

    /**
     * Plugin settings
     */
    private $settings;

    /**
     * Clover API client
     */
    private $clover_api;
    
    /**
     * Main plugin instance
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    public function __construct() {
        // Check if WooCommerce is active
        if (!$this->check_woocommerce_active()) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        // Initialize the plugin
        $this->init();
    }

    /**
     * Initialize the plugin
     */
    private function init() {
        // Load dependencies
        $this->load_dependencies();
        
        // Load plugin settings
        $this->settings = get_option('wc_clover_integration_settings', array(
            'client_id' => '',
            'client_secret' => '',
            'access_token' => '',
            'merchant_id' => '',
            'debug_mode' => 'no',
            'auto_print' => 'yes',
        ));
        
        // Initialize Clover API client
        $this->init_clover_api();
        
        // Register hooks
        $this->register_hooks();
        
        // Register admin settings page
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_init', array($this, 'register_settings'));
        }
    }

    /**
     * Load dependencies
     */
    private function load_dependencies() {
        // Load Clover API client
        require_once WC_CLOVER_INTEGRATION_PLUGIN_DIR . 'includes/class-clover-api.php';
        
        // Load logger
        require_once WC_CLOVER_INTEGRATION_PLUGIN_DIR . 'includes/class-logger.php';
    }

    /**
     * Initialize Clover API client
     */
    private function init_clover_api() {
        $sandbox_mode = isset($this->settings['sandbox_mode']) && $this->settings['sandbox_mode'] === 'yes';
        WC_Clover_Logger::log("Initializing Clover API with sandbox mode: " . ($sandbox_mode ? "yes" : "no"), 'debug');
        
        $this->clover_api = new WC_Clover_API(
            $this->settings['client_id'],
            $this->settings['client_secret'],
            $this->settings['access_token'],
            $this->settings['merchant_id'],
            $sandbox_mode
        );
    }

    /**
     * Register hooks
     */
    private function register_hooks() {
        // Hook into WooCommerce order creation
        add_action('woocommerce_checkout_order_processed', array($this, 'process_new_order'), 10, 3);
        
        // Register query vars and handle OAuth callback
        add_action('init', array($this, 'register_query_vars'));
        add_action('init', array($this, 'handle_oauth_callback'));
        
        // Add settings link to plugins page
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));
    }

    /**
     * Process new WooCommerce order
     */
    public function process_new_order($order_id, $posted_data, $order) {
        // Get the WooCommerce order
        $wc_order = wc_get_order($order_id);
        
        if (!$wc_order) {
            WC_Clover_Logger::log("Failed to load WooCommerce order #{$order_id}", 'error');
            return;
        }
        
        // Log the start of processing
        WC_Clover_Logger::log("Processing order #{$order_id} for Clover sync", 'info');
        
        try {
            // Check if API is properly configured
            if (empty($this->settings['client_id']) || empty($this->settings['client_secret']) || 
                empty($this->settings['access_token']) || empty($this->settings['merchant_id'])) {
                throw new Exception("Clover API not properly configured. Please check settings.");
            }

            // Create order in Clover
            $clover_order_id = $this->create_clover_order($wc_order);
            
            if (!$clover_order_id) {
                throw new Exception("Failed to create order in Clover POS");
            }
            
            // Store Clover order ID in WooCommerce order meta
            $wc_order->update_meta_data('_clover_order_id', $clover_order_id);
            $wc_order->save();
            
            // Add note to the order
            $wc_order->add_order_note(
                sprintf(__('Order successfully synced to Clover POS. Clover Order ID: %s', 'wc-clover-integration'), 
                $clover_order_id)
            );
            
            // Print bill if auto-print is enabled
            if ($this->settings['auto_print'] === 'yes') {
                $this->print_bill($clover_order_id, $wc_order);
            }
            
            WC_Clover_Logger::log("Order #{$order_id} successfully synced to Clover (ID: {$clover_order_id})", 'info');
            
        } catch (Exception $e) {
            WC_Clover_Logger::log("Error processing order #{$order_id}: " . $e->getMessage(), 'error');
            $wc_order->add_order_note(
                sprintf(__('Error syncing to Clover POS: %s', 'wc-clover-integration'), 
                $e->getMessage())
            );
        }
    }

    /**
     * Create an order in Clover POS
     */
    private function create_clover_order($wc_order) {
        // Prepare order data
        $order_data = $this->prepare_order_data($wc_order);
        
        // Create order in Clover
        $response = $this->clover_api->create_order($order_data);
        
        // Check if order was created successfully
        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }
        
        // Return Clover order ID
        return isset($response['id']) ? $response['id'] : false;
    }

    /**
     * Prepare WooCommerce order data for Clover
     */
    private function prepare_order_data($wc_order) {
        // Get customer info
        $customer_info = $this->get_customer_info($wc_order);
        
        // Get order line items
        $line_items = $this->get_line_items($wc_order);
        
        // Get order metadata
        $metadata = $this->get_order_metadata($wc_order);
        
        // Prepare order data
        $order_data = array(
            'customer' => $customer_info,
            'lineItems' => $line_items,
            'metadata' => $metadata,
            'total' => (float) $wc_order->get_total(),
            'taxAmount' => (float) $wc_order->get_total_tax(),
            'note' => $this->get_order_notes($wc_order)
        );
        
        return $order_data;
    }

    /**
     * Get customer information from WooCommerce order
     */
    private function get_customer_info($wc_order) {
        return array(
            'firstName' => $wc_order->get_billing_first_name(),
            'lastName' => $wc_order->get_billing_last_name(),
            'phone' => $wc_order->get_billing_phone(),
            'email' => $wc_order->get_billing_email(),
            'address' => array(
                'line1' => $wc_order->get_billing_address_1(),
                'line2' => $wc_order->get_billing_address_2(),
                'city' => $wc_order->get_billing_city(),
                'state' => $wc_order->get_billing_state(),
                'zip' => $wc_order->get_billing_postcode(),
                'country' => $wc_order->get_billing_country()
            )
        );
    }

    /**
     * Get line items from WooCommerce order
     */
    private function get_line_items($wc_order) {
        $line_items = array();
        
        // Loop through order items
        foreach ($wc_order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            
            if (!$product) {
                continue;
            }
            
            // Get product name
            $name = $item->get_name();
            
            // Get product variation attributes (size, etc.)
            $variation_attributes = $this->get_variation_attributes($item);
            
            // Get YITH product add-ons
            $addons = $this->get_yith_addons($item_id, $item);
            
            // Calculate item price
            $price = (float) $item->get_total() / max(1, $item->get_quantity());
            
            // Prepare line item data
            $line_item = array(
                'name' => $name,
                'price' => $price,
                'quantity' => $item->get_quantity(),
                'variations' => $variation_attributes,
                'addOns' => $addons,
                'notes' => $this->format_item_notes($variation_attributes, $addons)
            );
            
            $line_items[] = $line_item;
        }
        
        return $line_items;
    }

    /**
     * Get variation attributes from order item
     */
    private function get_variation_attributes($item) {
        $variation_attributes = array();
        
        // Check if item is a variation
        if ($item->get_variation_id()) {
            $variation_data = $item->get_meta_data();
            
            foreach ($variation_data as $meta) {
                if (strpos($meta->key, 'pa_') === 0 || strpos($meta->key, 'attribute_pa_') === 0) {
                    $attribute_name = wc_attribute_label(str_replace('attribute_', '', $meta->key));
                    $variation_attributes[$attribute_name] = $meta->value;
                }
            }
        }
        
        return $variation_attributes;
    }

    /**
     * Get YITH product add-ons from order item
     */
    private function get_yith_addons($item_id, $item) {
        $addons = array();
        
        // Check if YITH WooCommerce Product Add-Ons is active
        if (!function_exists('YITH_WAPO')) {
            return $addons;
        }
        
        // Get add-ons data from order item
        $item_meta = $item->get_meta_data();
        
        foreach ($item_meta as $meta) {
            // YITH add-ons are typically stored with the prefix 'yith_wapo_'
            if (strpos($meta->key, 'yith_wapo_') === 0 || strpos($meta->key, '_ywapo_') === 0) {
                $addon_name = str_replace(array('yith_wapo_', '_ywapo_'), '', $meta->key);
                $addon_value = $meta->value;
                
                // Get add-on price if available
                $addon_price = 0;
                
                // Add to add-ons array
                $addons[] = array(
                    'name' => $addon_name,
                    'value' => $addon_value,
                    'price' => $addon_price
                );
            }
        }
        
        return $addons;
    }

    /**
     * Format item notes for Clover
     */
    private function format_item_notes($variation_attributes, $addons) {
        $notes = array();
        
        // Add variation attributes to notes
        foreach ($variation_attributes as $attr_name => $attr_value) {
            $notes[] = $attr_name . ': ' . $attr_value;
        }
        
        // Add add-ons to notes
        foreach ($addons as $addon) {
            $notes[] = $addon['name'] . ': ' . $addon['value'];
        }
        
        return implode(', ', $notes);
    }

    /**
     * Get order metadata for Clover
     */
    private function get_order_metadata($wc_order) {
        return array(
            'woocommerce_order_id' => $wc_order->get_id(),
            'order_number' => $wc_order->get_order_number(),
            'payment_method' => $wc_order->get_payment_method_title(),
            'shipping_method' => $wc_order->get_shipping_method(),
            'order_date' => $wc_order->get_date_created()->date('Y-m-d H:i:s')
        );
    }

    /**
     * Get order notes for Clover
     */
    private function get_order_notes($wc_order) {
        $notes = array();
        
        // Add shipping method
        $shipping_method = $wc_order->get_shipping_method();
        if ($shipping_method) {
            $notes[] = "Shipping: " . $shipping_method;
        }
        
        // Add payment method
        $payment_method = $wc_order->get_payment_method_title();
        if ($payment_method) {
            $notes[] = "Payment: " . $payment_method;
        }
        
        // Add customer note
        $customer_note = $wc_order->get_customer_note();
        if ($customer_note) {
            $notes[] = "Customer note: " . $customer_note;
        }
        
        return implode(' | ', $notes);
    }

    /**
     * Print bill from Clover POS
     */
    private function print_bill($clover_order_id, $wc_order) {
        try {
            // Call Clover API to print bill
            $response = $this->clover_api->print_bill($clover_order_id);
            
            // Check if bill was printed successfully
            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }
            
            // Add note to the order
            $wc_order->add_order_note(__('Bill printed successfully from Clover POS.', 'wc-clover-integration'));
            
            WC_Clover_Logger::log("Bill printed successfully for order #{$wc_order->get_id()}", 'info');
            
            return true;
            
        } catch (Exception $e) {
            WC_Clover_Logger::log("Error printing bill for order #{$wc_order->get_id()}: " . $e->getMessage(), 'error');
            $wc_order->add_order_note(
                sprintf(__('Error printing bill from Clover POS: %s', 'wc-clover-integration'), 
                $e->getMessage())
            );
            
            return false;
        }
    }

    /**
     * Register query variables
     */
    public function register_query_vars() {
        add_rewrite_tag('%clover-action%', '([^&]+)');
        
        // Flush rewrite rules if needed
        if (get_option('wc_clover_flush_rules', 'yes') === 'yes') {
            flush_rewrite_rules();
            update_option('wc_clover_flush_rules', 'no');
            WC_Clover_Logger::log('Flushed rewrite rules', 'debug');
        }
    }

    /**
     * Handle OAuth callback
     */
    public function handle_oauth_callback() {
        if (isset($_GET['clover-action']) && $_GET['clover-action'] === 'oauth-callback') {
            WC_Clover_Logger::log('OAuth callback received. GET params: ' . json_encode($_GET), 'debug');
            
            if (!empty($_GET['code'])) {
                $auth_code = sanitize_text_field($_GET['code']);
                
                try {
                    // Re-initialize the Clover API
                    $this->init_clover_api();
                    
                    // Exchange code for access token
                    $response = $this->clover_api->get_access_token($auth_code);
                    
                    if (is_wp_error($response)) {
                        throw new Exception($response->get_error_message());
                    }
                    
                    // Save access token and merchant ID
                    $this->settings['access_token'] = $response['access_token'];
                    $this->settings['merchant_id'] = $response['merchant_id'];
                    update_option('wc_clover_integration_settings', $this->settings);
                    
                    WC_Clover_Logger::log('OAuth successful. Access token and merchant ID saved.', 'info');
                    
                    // Redirect to settings page
                    wp_redirect(admin_url('admin.php?page=wc-clover-integration&oauth=success'));
                    exit;
                    
                } catch (Exception $e) {
                    WC_Clover_Logger::log('OAuth error: ' . $e->getMessage(), 'error');
                    wp_die('Error during OAuth: ' . esc_html($e->getMessage()));
                }
            } else {
                WC_Clover_Logger::log('OAuth callback missing code parameter', 'error');
                wp_die('Invalid OAuth callback: No authorization code received.');
            }
        }
    }

    /**
     * Register OAuth endpoint for Clover API
     */
    public function register_oauth_endpoint() {
        // Register query vars
        add_rewrite_tag('%clover-action%', '([^&]+)');
        
        // Check if we need to flush rewrite rules
        if (get_option('wc_clover_flush_rules', 'yes') === 'yes') {
            flush_rewrite_rules();
            update_option('wc_clover_flush_rules', 'no');
            WC_Clover_Logger::log('Flushed rewrite rules', 'debug');
        }
        
        // Handle OAuth callback via query parameter
        if (isset($_GET['clover-action']) && $_GET['clover-action'] === 'oauth-callback') {
            $this->handle_oauth_callback();
            exit;
        }
    }

    // Remove these duplicate methods:
    // - handle_direct_oauth_callback()
    // - process_oauth_callback()
    // - The duplicate handle_oauth_callback()

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Clover POS Integration', 'wc-clover-integration'),
            __('Clover POS', 'wc-clover-integration'),
            'manage_woocommerce',
            'wc-clover-integration',
            array($this, 'display_settings_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('wc_clover_integration', 'wc_clover_integration_settings');
        
        add_settings_section(
            'wc_clover_api_settings',
            __('Clover API Settings', 'wc-clover-integration'),
            array($this, 'settings_section_callback'),
            'wc_clover_integration'
        );
        
        add_settings_field(
            'client_id',
            __('Client ID', 'wc-clover-integration'),
            array($this, 'client_id_callback'),
            'wc_clover_integration',
            'wc_clover_api_settings'
        );
        
        add_settings_field(
            'client_secret',
            __('Client Secret', 'wc-clover-integration'),
            array($this, 'client_secret_callback'),
            'wc_clover_integration',
            'wc_clover_api_settings'
        );
        
        add_settings_field(
            'access_token',
            __('Access Token', 'wc-clover-integration'),
            array($this, 'access_token_callback'),
            'wc_clover_integration',
            'wc_clover_api_settings'
        );
        
        add_settings_field(
            'merchant_id',
            __('Merchant ID', 'wc-clover-integration'),
            array($this, 'merchant_id_callback'),
            'wc_clover_integration',
            'wc_clover_api_settings'
        );
        
        add_settings_field(
            'debug_mode',
            __('Debug Mode', 'wc-clover-integration'),
            array($this, 'debug_mode_callback'),
            'wc_clover_integration',
            'wc_clover_api_settings'
        );
        
        add_settings_field(
            'auto_print',
            __('Auto-Print Bills', 'wc-clover-integration'),
            array($this, 'auto_print_callback'),
            'wc_clover_integration',
            'wc_clover_api_settings'
        );

        add_settings_field(
            'sandbox_mode',
            __('Sandbox Mode', 'wc-clover-integration'),
            array($this, 'sandbox_mode_callback'),
            'wc_clover_integration',
            'wc_clover_api_settings'
        );
    }

    /**
     * Settings section callback
     */
    public function settings_section_callback() {
        echo '<p>' . __('Connect your WooCommerce store to Clover POS. Enter your Clover API credentials below.', 'wc-clover-integration') . '</p>';
    }

    /**
     * Client ID field callback
     */
    public function client_id_callback() {
        $value = isset($this->settings['client_id']) ? $this->settings['client_id'] : '';
        echo '<input type="text" name="wc_clover_integration_settings[client_id]" value="' . esc_attr($value) . '" class="regular-text">';
        echo '<p class="description">' . __('Your Clover API Client ID', 'wc-clover-integration') . '</p>';
    }

    /**
     * Client Secret field callback
     */
    public function client_secret_callback() {
        $value = isset($this->settings['client_secret']) ? $this->settings['client_secret'] : '';
        echo '<input type="password" name="wc_clover_integration_settings[client_secret]" value="' . esc_attr($value) . '" class="regular-text">';
        echo '<p class="description">' . __('Your Clover API Client Secret', 'wc-clover-integration') . '</p>';
    }

    /**
     * Access Token field callback
     */
    public function access_token_callback() {
        $value = isset($this->settings['access_token']) ? $this->settings['access_token'] : '';
        echo '<input type="text" name="wc_clover_integration_settings[access_token]" value="' . esc_attr($value) . '" class="regular-text" readonly>';
        echo '<p class="description">' . __('Obtained automatically via OAuth', 'wc-clover-integration') . '</p>';
        
        // Add OAuth button if client ID and secret are set
        if (!empty($this->settings['client_id']) && !empty($this->settings['client_secret'])) {
            $client_id = $this->settings['client_id'];
            $redirect_uri = urlencode(home_url('/clover-callback'));
            $oauth_url = "https://www.clover.com/oauth/authorize?client_id={$client_id}&response_type=code&redirect_uri={$redirect_uri}";
            
            echo '<a href="' . esc_url($oauth_url) . '" class="button button-primary">' . __('Connect to Clover', 'wc-clover-integration') . '</a>';
            
            // Debug output in development/debug mode
            if (isset($this->settings['debug_mode']) && $this->settings['debug_mode'] === 'yes') {
                echo '<p class="description">Debug: OAuth URL is: ' . esc_html($oauth_url) . '</p>';
            }
        } else {
            echo '<p class="description">' . __('Enter Client ID and Client Secret, then save settings to enable OAuth connection.', 'wc-clover-integration') . '</p>';
        }
    }

    /**
     * Merchant ID field callback
     */
    public function merchant_id_callback() {
        $value = isset($this->settings['merchant_id']) ? $this->settings['merchant_id'] : '';
        echo '<input type="text" name="wc_clover_integration_settings[merchant_id]" value="' . esc_attr($value) . '" class="regular-text" readonly>';
        echo '<p class="description">' . __('Obtained automatically via OAuth', 'wc-clover-integration') . '</p>';
    }

    /**
     * Debug Mode field callback
     */
    public function debug_mode_callback() {
        $value = isset($this->settings['debug_mode']) ? $this->settings['debug_mode'] : 'no';
        echo '<select name="wc_clover_integration_settings[debug_mode]">';
        echo '<option value="no" ' . selected($value, 'no', false) . '>' . __('Off', 'wc-clover-integration') . '</option>';
        echo '<option value="yes" ' . selected($value, 'yes', false) . '>' . __('On', 'wc-clover-integration') . '</option>';
        echo '</select>';
        echo '<p class="description">' . __('Enable debug logging', 'wc-clover-integration') . '</p>';
    }

    /**
     * Auto-Print field callback
     */
    public function auto_print_callback() {
        $value = isset($this->settings['auto_print']) ? $this->settings['auto_print'] : 'yes';
        echo '<select name="wc_clover_integration_settings[auto_print]">';
        echo '<option value="no" ' . selected($value, 'no', false) . '>' . __('Off', 'wc-clover-integration') . '</option>';
        echo '<option value="yes" ' . selected($value, 'yes', false) . '>' . __('On', 'wc-clover-integration') . '</option>';
        echo '</select>';
        echo '<p class="description">' . __('Automatically print bills when orders are created', 'wc-clover-integration') . '</p>';
    }

    /**
     * Sandbox Mode field callback
     */
    public function sandbox_mode_callback() {
        $value = isset($this->settings['sandbox_mode']) ? $this->settings['sandbox_mode'] : 'no';
        echo '<select name="wc_clover_integration_settings[sandbox_mode]">';
        echo '<option value="no" ' . selected($value, 'no', false) . '>' . __('Production', 'wc-clover-integration') . '</option>';
        echo '<option value="yes" ' . selected($value, 'yes', false) . '>' . __('Sandbox', 'wc-clover-integration') . '</option>';
        echo '</select>';
        echo '<p class="description">' . __('Switch between production and sandbox environments.', 'wc-clover-integration') . '</p>';
    }

    /**
     * Display settings page
     */
    public function display_settings_page() {
        // Check if OAuth was successful
        if (isset($_GET['oauth']) && $_GET['oauth'] === 'success') {
            echo '<div class="notice notice-success"><p>' . __('Successfully connected to Clover!', 'wc-clover-integration') . '</p></div>';
        }
        
        // Display settings page
        echo '<div class="wrap">';
        echo '<h1>' . __('WooCommerce to Clover POS Integration', 'wc-clover-integration') . '</h1>';
        
        // Display debug logs if in debug mode
        if (isset($this->settings['debug_mode']) && $this->settings['debug_mode'] === 'yes') {
            echo '<h2>' . __('Debug Logs', 'wc-clover-integration') . '</h2>';
            echo '<div class="wc-clover-logs">';
            echo WC_Clover_Logger::get_logs_html();
            echo '</div>';
        }
        
        // Display settings form
        echo '<form method="post" action="options.php">';
        settings_fields('wc_clover_integration');
        do_settings_sections('wc_clover_integration');
        submit_button();
        echo '</form>';
        echo '</div>';
    }

    /**
     * Get OAuth URL for Clover
     */
    private function get_oauth_url() {
        // Use direct callback approach with query parameter
        $callback_url = add_query_arg('clover-action', 'oauth-callback', home_url('/'));
        
        // Determine the base URL based on sandbox mode
        $base_url = isset($this->settings['sandbox_mode']) && $this->settings['sandbox_mode'] === 'yes'
            ? 'https://sandbox.dev.clover.com/oauth/authorize'
            : 'https://api.clover.com/oauth/authorize';

        $oauth_params = array(
            'client_id' => $this->settings['client_id'],
            'response_type' => 'code',
            'redirect_uri' => $callback_url
        );

        $oauth_url = $base_url . '?' . http_build_query($oauth_params);
        WC_Clover_Logger::log('Generated OAuth URL: ' . $oauth_url, 'debug');
        
        return $oauth_url;
    }

    /**
     * Add settings link to plugins page
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=wc-clover-integration') . '">' . __('Settings', 'wc-clover-integration') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Check if WooCommerce is active
     */
    private function check_woocommerce_active() {
        return class_exists('WooCommerce');
    }

    /**
     * Display notice if WooCommerce is missing
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="error">
            <p><?php _e('WooCommerce to Clover POS Integration requires WooCommerce to be installed and active.', 'wc-clover-integration'); ?></p>
        </div>
        <?php
    }

}

// Clover API Class
class WC_Clover_API {
    
    /**
     * API credentials
     */
    private $client_id;
    private $client_secret;
    private $access_token;
    private $merchant_id;
    
    /**
     * API endpoints
     */
    private $api_base_url = 'https://api.clover.com/v3';
    private $oauth_token_url = 'https://api.clover.com/oauth/token';
    
    /**
     * Constructor
     */
    public function __construct($client_id, $client_secret, $access_token, $merchant_id) {
        $this->client_id = $client_id;
        $this->client_secret = $client_secret;
        $this->access_token = $access_token;
        $this->merchant_id = $merchant_id;
    }
    
    /**
     * Get access token from authorization code
     */
    public function get_access_token($code) {
        $callback_url = home_url('clover-oauth-callback');
        
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
        
        $response = wp_remote_post($url, array(
            'method' => 'POST',
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->access_token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($this->convert_order_data_for_clover($order_data))
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
                'price' => (int)($item['price'] * 100), // Convert to cents
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
}

/**
 * Logger class for the plugin
 */
class WC_Clover_Logger {
    
    /**
     * Log file name
     */
    private static $log_file = 'wc-clover-integration.log';
    
    /**
     * Log a message
     */
    public static function log($message, $level = 'info') {
        // Get plugin settings
        $settings = get_option('wc_clover_integration_settings', array());
        
        // Only log if debug mode is enabled or if error level
        if ($level === 'error' || (isset($settings['debug_mode']) && $settings['debug_mode'] === 'yes')) {
            // Get timestamp
            $timestamp = date('Y-m-d H:i:s');
            
            // Format log entry
            $log_entry = sprintf('[%s] [%s] %s', $timestamp, strtoupper($level), $message) . PHP_EOL;
            
            // Get log file path
            $log_file = WP_CONTENT_DIR . '/uploads/' . self::$log_file;
            
            // Write to log file
            file_put_contents($log_file, $log_entry, FILE_APPEND);
            
            // Also log to WooCommerce logs if available
            if (function_exists('wc_get_logger')) {
                $logger = wc_get_logger();
                $logger->log($level, $message, array('source' => 'wc-clover-integration'));
            }
        }
    }
    
    /**
     * Get logs as HTML
     */
    public static function get_logs_html($limit = 50) {
        // Get log file path
        $log_file = WP_CONTENT_DIR . '/uploads/' . self::$log_file;
        
        // Check if log file exists
        if (!file_exists($log_file)) {
            return '<p>' . __('No logs available.', 'wc-clover-integration') . '</p>';
        }
        
        // Read log file
        $logs = file($log_file);
        
        // Reverse logs to show newest first
        $logs = array_reverse($logs);
        
        // Limit logs
        $logs = array_slice($logs, 0, $limit);
        
        // Format logs as HTML
        $html = '<div class="wc-clover-logs-container">';
        $html .= '<table class="widefat">';
        $html .= '<thead><tr><th>' . __('Timestamp', 'wc-clover-integration') . '</th><th>' . __('Level', 'wc-clover-integration') . '</th><th>' . __('Message', 'wc-clover-integration') . '</th></tr></thead>';
        $html .= '<tbody>';
        
        foreach ($logs as $log) {
            // Parse log entry
            if (preg_match('/\[(.*?)\] \[(.*?)\] (.*?)$/', $log, $matches)) {
                $timestamp = $matches[1];
                $level = $matches[2];
                $message = $matches[3];
                
                // Set row class based on log level
                $row_class = 'wc-clover-log-' . strtolower($level);
                
                $html .= '<tr class="' . $row_class . '">';
                $html .= '<td>' . esc_html($timestamp) . '</td>';
                $html .= '<td>' . esc_html($level) . '</td>';
                $html .= '<td>' . esc_html($message) . '</td>';
                $html .= '</tr>';
            }
        }
        
        $html .= '</tbody></table>';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Clear logs
     */
    public static function clear_logs() {
        // Get log file path
        $log_file = WP_CONTENT_DIR . '/uploads/' . self::$log_file;
        
        // Check if log file exists
        if (file_exists($log_file)) {
            // Clear log file
            file_put_contents($log_file, '');
        }
    }
}

// Initialize the plugin
function wc_clover_integration_init() {
    return WC_Clover_Integration::instance();
}

// Start the plugin
add_action('plugins_loaded', 'wc_clover_integration_init');

/**
 * Register the clover-action query var
 */
add_action('init', 'clover_register_query_var');
function clover_register_query_var() {
    add_rewrite_tag('%clover-action%', '([^&]+)');
}

/**
 * Flush rewrite rules on plugin activation
 */
register_activation_hook(__FILE__, function () {
    clover_register_query_var();
    flush_rewrite_rules();
});

/**
 * Handle the OAuth callback
 */
add_action('template_redirect', 'clover_handle_oauth_callback');
function clover_handle_oauth_callback() {
    if (get_query_var('clover-action') === 'oauth-callback') {
        // Log the callback for debugging
        WC_Clover_Logger::log('OAuth callback triggered. GET params: ' . json_encode($_GET), 'debug');

        // Get the auth code and other parameters
        $auth_code = sanitize_text_field($_GET['code'] ?? '');
        $merchant_id = sanitize_text_field($_GET['merchant_id'] ?? '');
        $client_id = sanitize_text_field($_GET['client_id'] ?? '');

        if ($auth_code) {
            // Exchange the auth code for an access token
            $response = wp_remote_post('https://api.clover.com/oauth/token', [
                'body' => [
                    'client_id' => 'YOUR_CLIENT_ID', // Replace with your actual client ID
                    'client_secret' => 'YOUR_CLIENT_SECRET', // Replace with your actual client secret
                    'code' => $auth_code,
                    'redirect_uri' => home_url('/?clover-action=oauth-callback'),
                    'grant_type' => 'authorization_code'
                ]
            ]);

            if (is_wp_error($response)) {
                WC_Clover_Logger::log('Error exchanging auth code: ' . $response->get_error_message(), 'error');
                wp_die('Error: ' . $response->get_error_message());
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);

            if (isset($body['access_token'])) {
                // Save the access token and merchant ID
                update_option('clover_access_token', $body['access_token']);
                update_option('clover_merchant_id', $merchant_id);

                WC_Clover_Logger::log('Clover connected successfully. Access token saved.', 'info');
                wp_die('✅ Clover connected! Token saved.');
            } else {
                WC_Clover_Logger::log('Token request failed. Response: ' . json_encode($body), 'error');
                wp_die('⚠️ Token request failed. Response: <pre>' . print_r($body, true) . '</pre>');
            }
        } else {
            WC_Clover_Logger::log('OAuth callback missing auth code.', 'error');
            wp_die('❌ Missing auth code.');
        }

        exit;
    }
}

/**
 * Handle /clover-callback endpoint
 */
add_action('init', function () {
    $request_uri = $_SERVER['REQUEST_URI'];

    if (strpos($request_uri, '/clover-callback') !== false) {
        $auth_code = sanitize_text_field($_GET['code'] ?? '');
        $merchant_id = sanitize_text_field($_GET['merchant_id'] ?? '');

        if (!$auth_code) {
            wp_die('Missing authorization code');
        }

        // Exchange code for token
        $response = wp_remote_post('https://api.clover.com/oauth/token', [
            'body' => [
                'client_id' => 'WHCTTCCZTRHN2', // Replace with your actual client ID
                'client_secret' => 'YOUR_CLIENT_SECRET', // Replace with your actual client secret
                'code' => $auth_code,
                'redirect_uri' => home_url('/clover-callback'),
                'grant_type' => 'authorization_code'
            ]
        ]);

        if (is_wp_error($response)) {
            wp_die('Token error: ' . $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($body['access_token'])) {
            update_option('clover_access_token', $body['access_token']);
            update_option('clover_merchant_id', $merchant_id);
            wp_die('✅ Clover connected successfully!');
        } else {
            wp_die('❌ Failed to retrieve access token. Response: <pre>' . print_r($body, true) . '</pre>');
        }

        exit;
    }
});

/**
 * Handle /clover-callback endpoint for OAuth
 */
add_action('init', function () {
    if (strpos($_SERVER['REQUEST_URI'], '/clover-callback') !== false) {
        $auth_code = sanitize_text_field($_GET['code'] ?? '');
        $merchant_id = sanitize_text_field($_GET['merchant_id'] ?? '');
        $client_id = 'WHCTTCCZTRHN2';
        $client_secret = get_option('clover_client_secret'); // fetched from backend settings

        if (empty($client_secret) || strlen($client_secret) < 40) {
            wp_die('❌ Client secret is missing or invalid length.');
        }

        if (empty($auth_code)) {
            wp_die('❌ Missing authorization code from Clover.');
        }

        $response = wp_remote_post('https://api.clover.com/oauth/token', [
            'body' => [
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'code' => $auth_code,
                'redirect_uri' => 'https://nyckingsdeliandpizza.com/clover-callback',
                'grant_type' => 'authorization_code'
            ]
        ]);

        if (is_wp_error($response)) {
            wp_die('❌ Error requesting token: ' . $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($body['access_token'])) {
            update_option('clover_access_token', $body['access_token']);
            update_option('clover_merchant_id', $merchant_id);
            wp_die('✅ Clover connected successfully!');
        } else {
            wp_die('❌ Failed to retrieve access token. Response: <pre>' . print_r($body, true) . '</pre>');
        }
    }
});

/**
 * Add admin settings link for testing "Connect to Clover" button
 */
add_action('admin_menu', function () {
    add_menu_page('Clover Integration', 'Clover Integration', 'manage_options', 'clover-integration', function () {
        $client_id = 'WHCTTCCZTRHN2';
        $redirect_uri = urlencode('https://nyckingsdeliandpizza.com/clover-callback');
        $oauth_url = "https://www.clover.com/oauth/authorize?client_id={$client_id}&response_type=code&redirect_uri={$redirect_uri}";

        echo '<div class="wrap">';
        echo '<h1>Clover Integration</h1>';
        echo '<a class="button button-primary" href="' . esc_url($oauth_url) . '">Connect to Clover</a>';
        echo '</div>';
    });
});