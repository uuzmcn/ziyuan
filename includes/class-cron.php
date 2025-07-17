<?php
/**
 * 定时任务类
 */
class WP_Disk_Link_Manager_Cron {
    
    public function __construct() {
        add_action('wp_disk_link_manager_cleanup', array($this, 'cleanup_expired_transfers'));
    }
    
    /**
     * 清理过期转存文件
     */
    public function cleanup_expired_transfers() {
        global $wpdb;
        
        // 获取过期的转存记录
        $expired_transfers = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}disk_link_transfers 
             WHERE status = 'completed' AND expire_time <= NOW()"
        );
        
        if (empty($expired_transfers)) {
            WP_Disk_Link_Manager_Logger::log(
                'cleanup_check',
                null,
                null,
                __('定时清理检查：没有过期的转存记录', 'wp-disk-link-manager')
            );
            return;
        }
        
        $disk_manager = new WP_Disk_Link_Manager_Disk_Manager();
        $cleanup_count = 0;
        $error_count = 0;
        
        foreach ($expired_transfers as $transfer) {
            try {
                // 删除网盘文件
                $disk_manager->delete_expired_files(array($transfer));
                
                // 更新数据库状态
                $wpdb->update(
                    $wpdb->prefix . 'disk_link_transfers',
                    array('status' => 'expired'),
                    array('id' => $transfer->id),
                    array('%s'),
                    array('%d')
                );
                
                $cleanup_count++;
                
                // 记录清理日志
                WP_Disk_Link_Manager_Logger::log(
                    'cleanup_success',
                    $transfer->post_id,
                    null,
                    __('清理过期转存文件', 'wp-disk-link-manager'),
                    array(
                        'transfer_id' => $transfer->id,
                        'original_url' => $transfer->original_url,
                        'transferred_url' => $transfer->transferred_url,
                        'disk_type' => $transfer->disk_type,
                        'expire_time' => $transfer->expire_time
                    )
                );
                
            } catch (Exception $e) {
                $error_count++;
                
                // 记录错误日志
                WP_Disk_Link_Manager_Logger::log(
                    'cleanup_error',
                    $transfer->post_id,
                    null,
                    __('清理过期转存文件失败: ', 'wp-disk-link-manager') . $e->getMessage(),
                    array(
                        'transfer_id' => $transfer->id,
                        'error' => $e->getMessage()
                    )
                );
            }
        }
        
        // 记录清理汇总
        WP_Disk_Link_Manager_Logger::log(
            'cleanup_summary',
            null,
            null,
            sprintf(
                __('定时清理完成：成功清理 %d 个，失败 %d 个', 'wp-disk-link-manager'),
                $cleanup_count,
                $error_count
            ),
            array(
                'total_expired' => count($expired_transfers),
                'success_count' => $cleanup_count,
                'error_count' => $error_count
            )
        );
        
        // 清理旧日志（保留30天）
        WP_Disk_Link_Manager_Logger::cleanup_old_logs(30);
    }
    
    /**
     * 手动触发清理
     */
    public static function manual_cleanup() {
        $cron = new self();
        $cron->cleanup_expired_transfers();
    }
    
    /**
     * 获取下次清理时间
     */
    public static function get_next_cleanup_time() {
        return wp_next_scheduled('wp_disk_link_manager_cleanup');
    }
    
    /**
     * 重新调度清理任务
     */
    public static function reschedule_cleanup($interval = 'hourly') {
        // 清除现有调度
        wp_clear_scheduled_hook('wp_disk_link_manager_cleanup');
        
        // 重新调度
        wp_schedule_event(time(), $interval, 'wp_disk_link_manager_cleanup');
    }
}