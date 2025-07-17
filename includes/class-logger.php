<?php
/**
 * 日志记录器类
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}
class WP_Disk_Link_Manager_Logger {
    
    /**
     * 记录日志
     */
    public static function log($action, $post_id = null, $user_id = null, $message = '', $details = null) {
        global $wpdb;
        
        $log_data = array(
            'action' => sanitize_text_field($action),
            'post_id' => $post_id ? intval($post_id) : null,
            'user_id' => $user_id ? intval($user_id) : null,
            'message' => sanitize_text_field($message),
            'details' => $details ? wp_json_encode($details) : null,
            'created_time' => current_time('mysql')
        );
        
        $result = $wpdb->insert(
            $wpdb->prefix . 'disk_link_logs',
            $log_data,
            array('%s', '%d', '%d', '%s', '%s', '%s')
        );
        
        return $result !== false;
    }
    
    /**
     * 获取日志
     */
    public static function get_logs($filters = array(), $limit = 50, $offset = 0) {
        global $wpdb;
        
        $where_conditions = array();
        $where_values = array();
        
        if (!empty($filters['action'])) {
            $where_conditions[] = "action = %s";
            $where_values[] = $filters['action'];
        }
        
        if (!empty($filters['post_id'])) {
            $where_conditions[] = "post_id = %d";
            $where_values[] = $filters['post_id'];
        }
        
        if (!empty($filters['user_id'])) {
            $where_conditions[] = "user_id = %d";
            $where_values[] = $filters['user_id'];
        }
        
        if (!empty($filters['date_from'])) {
            $where_conditions[] = "created_time >= %s";
            $where_values[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where_conditions[] = "created_time <= %s";
            $where_values[] = $filters['date_to'];
        }
        
        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        }
        
        $sql = "SELECT * FROM {$wpdb->prefix}disk_link_logs $where_clause ORDER BY created_time DESC";
        
        if ($limit > 0) {
            $sql .= " LIMIT %d";
            $where_values[] = $limit;
            
            if ($offset > 0) {
                $sql .= " OFFSET %d";
                $where_values[] = $offset;
            }
        }
        
        if (!empty($where_values)) {
            $sql = $wpdb->prepare($sql, $where_values);
        }
        
        return $wpdb->get_results($sql);
    }
    
    /**
     * 清理旧日志
     */
    public static function cleanup_old_logs($days = 30) {
        global $wpdb;
        
        $date_threshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}disk_link_logs WHERE created_time < %s",
            $date_threshold
        ));
        
        if ($result !== false) {
            self::log('log_cleanup', null, null, 
                sprintf(__('清理了 %d 条旧日志记录', 'wp-disk-link-manager'), $result)
            );
        }
        
        return $result;
    }
}