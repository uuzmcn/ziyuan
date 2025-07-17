<?php
/**
 * 定时任务类 (优化版)
 */
class WP_Disk_Link_Manager_Cron {
    
    private $logger;
    private $disk_manager;
    
    public function __construct() {
        $this->logger = new WP_Disk_Link_Manager_Logger();
        $this->disk_manager = new WP_Disk_Link_Manager_Disk_Manager();
        
        // 注册定时任务
        add_action('wp_disk_link_manager_cleanup_expired_files', array($this, 'cleanup_expired_files'));
        add_action('wp_disk_link_manager_validate_cookies', array($this, 'validate_all_cookies'));
        add_action('wp_disk_link_manager_performance_report', array($this, 'generate_performance_report'));
        
        // 注册定时任务钩子
        if (!wp_next_scheduled('wp_disk_link_manager_cleanup_expired_files')) {
            wp_schedule_event(time(), 'daily', 'wp_disk_link_manager_cleanup_expired_files');
        }
        
        if (!wp_next_scheduled('wp_disk_link_manager_validate_cookies')) {
            wp_schedule_event(time(), 'hourly', 'wp_disk_link_manager_validate_cookies');
        }
        
        if (!wp_next_scheduled('wp_disk_link_manager_performance_report')) {
            wp_schedule_event(time(), 'weekly', 'wp_disk_link_manager_performance_report');
        }
    }
    
    /**
     * 清理过期文件
     */
    public function cleanup_expired_files() {
        global $wpdb;
        
        $this->logger->log(
            'cron_cleanup_start',
            null,
            0,
            '开始清理过期文件'
        );
        
        try {
            $table_name = $wpdb->prefix . 'disk_link_transfers';
            
            // 查找过期的转存记录
            $expired_transfers = $wpdb->get_results($wpdb->prepare("
                SELECT id, post_id, original_url, transferred_url, disk_type, expire_time 
                FROM {$table_name} 
                WHERE status = 'completed' 
                AND expire_time < %s
                LIMIT 100
            ", current_time('mysql')));
            
            $cleanup_count = 0;
            
            foreach ($expired_transfers as $transfer) {
                try {
                    // 这里可以添加实际的网盘文件删除逻辑
                    // 暂时只更新数据库状态
                    $wpdb->update(
                        $table_name,
                        array('status' => 'expired'),
                        array('id' => $transfer->id),
                        array('%s'),
                        array('%d')
                    );
                    
                    $cleanup_count++;
                    
                    $this->logger->log(
                        'file_expired',
                        $transfer->post_id,
                        0,
                        '文件已过期',
                        array(
                            'transfer_id' => $transfer->id,
                            'original_url' => $transfer->original_url,
                            'expire_time' => $transfer->expire_time
                        )
                    );
                    
                } catch (Exception $e) {
                    $this->logger->log(
                        'cleanup_error',
                        $transfer->post_id,
                        0,
                        '清理文件失败: ' . $e->getMessage(),
                        array('transfer_id' => $transfer->id)
                    );
                }
            }
            
            $this->logger->log(
                'cron_cleanup_complete',
                null,
                0,
                "清理完成，处理了 {$cleanup_count} 个过期文件"
            );
            
        } catch (Exception $e) {
            $this->logger->log(
                'cron_cleanup_error',
                null,
                0,
                '清理任务失败: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * 验证所有Cookie有效性
     */
    public function validate_all_cookies() {
        $this->logger->log(
            'cron_cookie_validation_start',
            null,
            0,
            '开始定期Cookie验证'
        );
        
        $results = [];
        
        // 验证夸克Cookie
        $quark_cookie = get_option('wp_disk_link_manager_quark_cookie', '');
        if (!empty($quark_cookie)) {
            try {
                $results['quark'] = $this->disk_manager->validate_quark_cookie($quark_cookie);
                
                if (!$results['quark']) {
                    $this->logger->log(
                        'cookie_invalid_alert',
                        null,
                        0,
                        '夸克网盘Cookie已失效，需要重新配置'
                    );
                    
                    // 可以在这里添加邮件通知管理员的逻辑
                    $this->notify_admin_cookie_invalid('quark');
                }
                
            } catch (Exception $e) {
                $results['quark'] = false;
                $this->logger->log(
                    'cookie_validation_error',
                    null,
                    0,
                    '夸克网盘Cookie验证失败: ' . $e->getMessage()
                );
            }
        }
        
        // 验证百度Cookie
        $baidu_cookie = get_option('wp_disk_link_manager_baidu_cookie', '');
        if (!empty($baidu_cookie)) {
            try {
                $results['baidu'] = $this->disk_manager->validate_baidu_cookie($baidu_cookie);
                
                if (!$results['baidu']) {
                    $this->logger->log(
                        'cookie_invalid_alert',
                        null,
                        0,
                        '百度网盘Cookie已失效，需要重新配置'
                    );
                    
                    $this->notify_admin_cookie_invalid('baidu');
                }
                
            } catch (Exception $e) {
                $results['baidu'] = false;
                $this->logger->log(
                    'cookie_validation_error',
                    null,
                    0,
                    '百度网盘Cookie验证失败: ' . $e->getMessage()
                );
            }
        }
        
        $this->logger->log(
            'cron_cookie_validation_complete',
            null,
            0,
            'Cookie验证完成',
            $results
        );
    }
    
    /**
     * 生成性能报告
     */
    public function generate_performance_report() {
        global $wpdb;
        
        $this->logger->log(
            'performance_report_start',
            null,
            0,
            '开始生成性能报告'
        );
        
        try {
            $log_table = $wpdb->prefix . 'disk_link_logs';
            $transfer_table = $wpdb->prefix . 'disk_link_transfers';
            
            // 获取过去一周的统计数据
            $week_ago = date('Y-m-d H:i:s', strtotime('-1 week'));
            
            // 转存成功率
            $success_rate = $wpdb->get_var($wpdb->prepare("
                SELECT 
                    (COUNT(CASE WHEN status = 'completed' THEN 1 END) * 100.0 / COUNT(*)) as success_rate
                FROM {$transfer_table} 
                WHERE created_at >= %s
            ", $week_ago));
            
            // 平均处理时间
            $avg_processing_time = $wpdb->get_var($wpdb->prepare("
                SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, updated_at)) as avg_time
                FROM {$transfer_table} 
                WHERE status = 'completed' 
                AND created_at >= %s
            ", $week_ago));
            
            // 各网盘类型统计
            $disk_stats = $wpdb->get_results($wpdb->prepare("
                SELECT 
                    disk_type,
                    COUNT(*) as total_requests,
                    COUNT(CASE WHEN status = 'completed' THEN 1 END) as successful_requests
                FROM {$transfer_table} 
                WHERE created_at >= %s
                GROUP BY disk_type
            ", $week_ago), ARRAY_A);
            
            // 错误统计
            $error_stats = $wpdb->get_results($wpdb->prepare("
                SELECT 
                    action,
                    COUNT(*) as error_count
                FROM {$log_table} 
                WHERE level = 'error' 
                AND created_at >= %s
                GROUP BY action
                ORDER BY error_count DESC
                LIMIT 10
            ", $week_ago), ARRAY_A);
            
            $report = [
                'period' => '过去7天',
                'success_rate' => round($success_rate, 2),
                'avg_processing_time' => round($avg_processing_time, 2),
                'disk_stats' => $disk_stats,
                'error_stats' => $error_stats,
                'generated_at' => current_time('mysql')
            ];
            
            $this->logger->log(
                'performance_report',
                null,
                0,
                '性能报告生成完成',
                $report
            );
            
            // 保存报告到选项表
            update_option('wp_disk_link_manager_performance_report', $report);
            
        } catch (Exception $e) {
            $this->logger->log(
                'performance_report_error',
                null,
                0,
                '生成性能报告失败: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * 通知管理员Cookie失效
     */
    private function notify_admin_cookie_invalid($disk_type) {
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        
        $subject = "[{$site_name}] 网盘Cookie失效通知";
        $message = "
您好，

您的{$disk_type}网盘Cookie已失效，请及时登录管理后台重新配置。

管理后台地址：" . admin_url('admin.php?page=wp-disk-link-manager-settings') . "

此邮件由系统自动发送，请勿回复。
        ";
        
        wp_mail($admin_email, $subject, $message);
    }
    
    /**
     * 清理所有定时任务
     */
    public static function clear_scheduled_events() {
        wp_clear_scheduled_hook('wp_disk_link_manager_cleanup_expired_files');
        wp_clear_scheduled_hook('wp_disk_link_manager_validate_cookies');
        wp_clear_scheduled_hook('wp_disk_link_manager_performance_report');
    }
}