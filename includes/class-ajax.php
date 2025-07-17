<?php
/**
 * AJAX处理类
 */
class WP_Disk_Link_Manager_Ajax {
    
    private $logger;
    private $disk_manager;
    
    public function __construct() {
        $this->logger = new WP_Disk_Link_Manager_Logger();
        $this->disk_manager = new WP_Disk_Link_Manager_Disk_Manager();
        
        // 用户端AJAX处理
        add_action('wp_ajax_transfer_disk_link', array($this, 'handle_transfer_request'));
        add_action('wp_ajax_nopriv_transfer_disk_link', array($this, 'handle_transfer_request'));
        
        // 获取转存状态
        add_action('wp_ajax_get_transfer_status', array($this, 'get_transfer_status'));
        add_action('wp_ajax_nopriv_get_transfer_status', array($this, 'get_transfer_status'));
    }
    
    /**
     * 性能监控装饰器
     */
    private function monitor_performance($operation, $callback) {
        $start_time = microtime(true);
        $start_memory = memory_get_usage();
        
        try {
            $result = $callback();
            
            $end_time = microtime(true);
            $end_memory = memory_get_usage();
            
            $this->logger->log(
                'performance_monitor',
                null,
                get_current_user_id(),
                "操作性能: {$operation}",
                [
                    'execution_time' => round($end_time - $start_time, 3),
                    'memory_usage' => $end_memory - $start_memory,
                    'peak_memory' => memory_get_peak_usage()
                ]
            );
            
            return $result;
            
        } catch (Exception $e) {
            $end_time = microtime(true);
            
            $this->logger->log(
                'performance_error',
                null,
                get_current_user_id(),
                "操作失败: {$operation}",
                [
                    'execution_time' => round($end_time - $start_time, 3),
                    'error' => $e->getMessage()
                ]
            );
            
            throw $e;
        }
    }
    
    /**
     * 处理转存请求 (优化版)
     */
    public function handle_transfer_request() {
        check_ajax_referer('wp_disk_link_manager_nonce', 'nonce');
        
        $post_id = intval($_POST['post_id']);
        $link_index = intval($_POST['link_index']);
        $original_url = sanitize_url($_POST['original_url']);
        $disk_type = sanitize_text_field($_POST['disk_type']);
        
        // 验证参数
        if (!$post_id || !isset($link_index) || !$original_url || !$disk_type) {
            wp_send_json_error(__('参数错误', 'wp-disk-link-manager'));
        }
        
        // 验证文章是否存在
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(__('文章不存在', 'wp-disk-link-manager'));
        }
        
        // 获取文章的网盘链接
        $disk_links = get_post_meta($post_id, '_disk_links', true);
        if (!isset($disk_links[$link_index])) {
            wp_send_json_error(__('链接不存在', 'wp-disk-link-manager'));
        }
        
        // 验证链接是否匹配
        if ($disk_links[$link_index]['url'] !== $original_url) {
            wp_send_json_error(__('链接验证失败', 'wp-disk-link-manager'));
        }
        
        // 检查是否已经转存过
        global $wpdb;
        $existing_transfer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}disk_link_transfers 
             WHERE post_id = %d AND link_index = %d AND expire_time > NOW() AND status = 'completed'",
            $post_id, $link_index
        ));
        
        if ($existing_transfer) {
            wp_send_json_success(array(
                'url' => $existing_transfer->transferred_url,
                'expire_time' => $existing_transfer->expire_time,
                'from_cache' => true
            ));
        }
        
        // 检查是否有进行中的转存
        $pending_transfer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}disk_link_transfers 
             WHERE post_id = %d AND link_index = %d AND status = 'pending'",
            $post_id, $link_index
        ));
        
        if ($pending_transfer) {
            wp_send_json_error(__('转存正在进行中，请稍后', 'wp-disk-link-manager'));
        }
        
        // 开始转存过程
        try {
            $transfer_id = $this->start_transfer($post_id, $link_index, $original_url, $disk_type);
            
            wp_send_json_success(array(
                'transfer_id' => $transfer_id,
                'message' => __('转存已开始，请稍等...', 'wp-disk-link-manager')
            ));
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * 获取转存状态
     */
    public function get_transfer_status() {
        check_ajax_referer('wp_disk_link_manager_nonce', 'nonce');
        
        $transfer_id = intval($_POST['transfer_id']);
        
        if (!$transfer_id) {
            wp_send_json_error(__('转存ID无效', 'wp-disk-link-manager'));
        }
        
        global $wpdb;
        $transfer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}disk_link_transfers WHERE id = %d",
            $transfer_id
        ));
        
        if (!$transfer) {
            wp_send_json_error(__('转存记录不存在', 'wp-disk-link-manager'));
        }
        
        if ($transfer->status === 'completed') {
            wp_send_json_success(array(
                'status' => 'completed',
                'url' => $transfer->transferred_url,
                'expire_time' => $transfer->expire_time
            ));
        } elseif ($transfer->status === 'failed') {
                            wp_send_json_error(__('获取失败', 'wp-disk-link-manager'));
        } else {
            wp_send_json_success(array(
                'status' => 'pending',
                'message' => __('转存进行中...', 'wp-disk-link-manager')
            ));
        }
    }
    
    /**
     * 开始转存过程
     */
    private function start_transfer($post_id, $link_index, $original_url, $disk_type) {
        global $wpdb;
        
        // 计算过期时间
        $expire_hours = get_option('wp_disk_link_manager_transfer_expire_hours', 72);
        $expire_time = date('Y-m-d H:i:s', time() + ($expire_hours * 3600));
        
        // 创建转存记录
        $result = $wpdb->insert(
            $wpdb->prefix . 'disk_link_transfers',
            array(
                'post_id' => $post_id,
                'link_index' => $link_index,
                'original_url' => $original_url,
                'disk_type' => $disk_type,
                'expire_time' => $expire_time,
                'status' => 'pending',
                'user_id' => get_current_user_id(),
                'created_time' => current_time('mysql')
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s')
        );
        
        if ($result === false) {
            throw new Exception(__('创建转存记录失败', 'wp-disk-link-manager'));
        }
        
        $transfer_id = $wpdb->insert_id;
        
        // 异步执行转存
        $this->execute_transfer_async($transfer_id);
        
        return $transfer_id;
    }
    
    /**
     * 异步执行转存
     */
    private function execute_transfer_async($transfer_id) {
        // 在实际生产环境中，应该使用队列系统或者后台任务
        // 这里使用WordPress的wp_schedule_single_event来模拟异步执行
        wp_schedule_single_event(time(), 'wp_disk_link_manager_execute_transfer', array($transfer_id));
        
        // 立即执行调度的事件（开发阶段）
        if (wp_next_scheduled('wp_disk_link_manager_execute_transfer', array($transfer_id))) {
            spawn_cron();
        }
    }
    
    /**
     * 执行实际转存操作
     */
    public static function execute_transfer($transfer_id) {
        global $wpdb;
        
        $transfer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}disk_link_transfers WHERE id = %d",
            $transfer_id
        ));
        
        if (!$transfer || $transfer->status !== 'pending') {
            return;
        }
        
        try {
            $disk_manager = new WP_Disk_Link_Manager_Disk_Manager();
            $transferred_url = $disk_manager->transfer_link($transfer->original_url, $transfer->disk_type);
            
            // 更新转存记录
            $wpdb->update(
                $wpdb->prefix . 'disk_link_transfers',
                array(
                    'transferred_url' => $transferred_url,
                    'status' => 'completed'
                ),
                array('id' => $transfer_id),
                array('%s', '%s'),
                array('%d')
            );
            
            // 记录日志
            WP_Disk_Link_Manager_Logger::log(
                'transfer_completed',
                $transfer->post_id,
                $transfer->user_id,
                __('转存完成', 'wp-disk-link-manager'),
                array(
                    'original_url' => $transfer->original_url,
                    'transferred_url' => $transferred_url,
                    'disk_type' => $transfer->disk_type
                )
            );
            
        } catch (Exception $e) {
            // 更新为失败状态
            $wpdb->update(
                $wpdb->prefix . 'disk_link_transfers',
                array('status' => 'failed'),
                array('id' => $transfer_id),
                array('%s'),
                array('%d')
            );
            
            // 记录错误日志
            WP_Disk_Link_Manager_Logger::log(
                'transfer_failed',
                $transfer->post_id,
                $transfer->user_id,
                __('获取失败: ', 'wp-disk-link-manager') . $e->getMessage(),
                array(
                    'original_url' => $transfer->original_url,
                    'disk_type' => $transfer->disk_type,
                    'error' => $e->getMessage()
                )
            );
        }
    }
}

// 添加转存执行钩子
add_action('wp_disk_link_manager_execute_transfer', array('WP_Disk_Link_Manager_Ajax', 'execute_transfer'));