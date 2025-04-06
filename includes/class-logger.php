<?php
/**
 * Logger class for the plugin
 * 
 * @package WC_Clover_Integration
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

if (!class_exists('WC_Clover_Logger')) {
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
                $log_dir = WP_CONTENT_DIR . '/uploads/wc-clover-logs/';
                
                // Create log directory if it doesn't exist
                if (!file_exists($log_dir)) {
                    wp_mkdir_p($log_dir);
                }
                
                $log_file = $log_dir . self::$log_file;
                
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
            $log_dir = WP_CONTENT_DIR . '/uploads/wc-clover-logs/';
            $log_file = $log_dir . self::$log_file;
            
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
            $html = '<div class="wc-clover-logs-container" style="max-height: 400px; overflow-y: auto; margin-bottom: 20px;">';
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
                    $row_style = '';
                    
                    // Add color based on log level
                    if (strtolower($level) === 'error') {
                        $row_style = 'background-color: #ffebe8;';
                    } elseif (strtolower($level) === 'warning') {
                        $row_style = 'background-color: #fff8e5;';
                    } elseif (strtolower($level) === 'info') {
                        $row_style = 'background-color: #e8f4f8;';
                    }
                    
                    $html .= '<tr class="' . $row_class . '" style="' . $row_style . '">';
                    $html .= '<td>' . esc_html($timestamp) . '</td>';
                    $html .= '<td>' . esc_html($level) . '</td>';
                    $html .= '<td>' . esc_html($message) . '</td>';
                    $html .= '</tr>';
                }
            }
            
            $html .= '</tbody></table>';
            $html .= '</div>';
            
            // Add clear logs button
            $html .= '<form method="post">';
            $html .= wp_nonce_field('wc_clover_clear_logs', 'wc_clover_clear_logs_nonce', true, false);
            $html .= '<button type="submit" name="wc_clover_clear_logs" class="button">' . __('Clear Logs', 'wc-clover-integration') . '</button>';
            $html .= '</form>';
            
            return $html;
        }
        
        /**
         * Clear logs
         */
        public static function clear_logs() {
            // Get log file path
            $log_dir = WP_CONTENT_DIR . '/uploads/wc-clover-logs/';
            $log_file = $log_dir . self::$log_file;
            
            // Check if log file exists
            if (file_exists($log_file)) {
                // Clear log file
                file_put_contents($log_file, '');
                return true;
            }
            
            return false;
        }
    }
}