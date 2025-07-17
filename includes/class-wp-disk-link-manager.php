<?php
/**
 * 主插件类
 */
class WP_Disk_Link_Manager {
    
    /**
     * 初始化插件
     */
    public function init() {
        // 初始化管理后台
        if (is_admin()) {
            new WP_Disk_Link_Manager_Admin();
        }
        
        // 初始化AJAX处理
        new WP_Disk_Link_Manager_Ajax();
        
        // 初始化定时任务
        new WP_Disk_Link_Manager_Cron();
        
        // 添加前端脚本和样式
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        
        // 添加文章内容过滤器
        add_filter('the_content', array($this, 'filter_post_content'));
        
        // 添加meta box到文章编辑页面
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_meta_boxes'));
    }
    
    /**
     * 加载前端脚本和样式
     */
    public function enqueue_frontend_scripts() {
        wp_enqueue_script(
            'wp-disk-link-manager-frontend',
            WP_DISK_LINK_MANAGER_PLUGIN_URL . 'assets/js/frontend.js',
            array('jquery'),
            WP_DISK_LINK_MANAGER_VERSION,
            true
        );
        
        wp_enqueue_style(
            'wp-disk-link-manager-frontend',
            WP_DISK_LINK_MANAGER_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            WP_DISK_LINK_MANAGER_VERSION
        );
        
        // 本地化脚本
        wp_localize_script('wp-disk-link-manager-frontend', 'wpDiskLinkManager', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_disk_link_manager_nonce'),
            'loading_text' => __('正在获取...', 'wp-disk-link-manager'),
            'error_text' => __('获取失败，请稍后重试', 'wp-disk-link-manager')
        ));
    }
    
    /**
     * 过滤文章内容，替换网盘链接为按钮
     */
    public function filter_post_content($content) {
        global $post;
        
        if (!$post) {
            return $content;
        }
        
        // 获取文章的网盘链接
        $disk_links = get_post_meta($post->ID, '_disk_links', true);
        
        if (empty($disk_links)) {
            return $content;
        }
        
        foreach ($disk_links as $index => $link_data) {
            $original_link = $link_data['url'];
            $show_directly = $link_data['show_directly'] ?? false;
            
            // 检查全局设置和文章级别设置
            $disk_type = $this->get_disk_type($original_link);
            $global_show_directly = get_option('wp_disk_link_manager_show_directly_' . $disk_type, false);
            
            if ($show_directly || $global_show_directly || $disk_type === 'magnet') {
                // 直接显示链接
                $button_html = $this->generate_direct_link_button($original_link, $disk_type);
            } else {
                // 需要转存的链接
                $button_html = $this->generate_transfer_button($original_link, $disk_type, $post->ID, $index);
            }
            
            // 替换占位符
            $placeholder = "{{DISK_LINK_$index}}";
            $content = str_replace($placeholder, $button_html, $content);
        }
        
        return $content;
    }
    
    /**
     * 获取网盘类型
     */
    private function get_disk_type($url) {
        if (strpos($url, 'pan.quark.cn') !== false) {
            return 'quark';
        } elseif (strpos($url, 'pan.baidu.com') !== false) {
            return 'baidu';
        } elseif (strpos($url, 'xunlei.com') !== false) {
            return 'xunlei';
        } elseif (strpos($url, 'magnet:') === 0) {
            return 'magnet';
        }
        return 'unknown';
    }
    
    /**
     * 生成直接链接按钮
     */
    private function generate_direct_link_button($url, $disk_type) {
        $button_text = $this->get_button_text($disk_type);
        
        if ($disk_type === 'magnet') {
            return "<a href='$url' class='wp-disk-link-button magnet-link' target='_blank'>$button_text</a>";
        }
        
        return "<a href='$url' class='wp-disk-link-button direct-link' target='_blank'>$button_text</a>";
    }
    
    /**
     * 生成转存按钮
     */
    private function generate_transfer_button($original_link, $disk_type, $post_id, $link_index) {
        $button_text = $this->get_button_text($disk_type);
        
        return "<button class='wp-disk-link-button transfer-button' 
                        data-post-id='$post_id' 
                        data-link-index='$link_index' 
                        data-original-url='$original_link' 
                        data-disk-type='$disk_type'>
                    $button_text
                </button>";
    }
    
    /**
     * 获取按钮文字
     */
    private function get_button_text($disk_type) {
        switch ($disk_type) {
            case 'quark':
                return __('夸克网盘', 'wp-disk-link-manager');
            case 'baidu':
                return __('百度网盘', 'wp-disk-link-manager');
            case 'xunlei':
                return __('迅雷网盘', 'wp-disk-link-manager');
            case 'magnet':
                return __('磁力链', 'wp-disk-link-manager');
            default:
                return __('下载链接', 'wp-disk-link-manager');
        }
    }
    
    /**
     * 添加meta box
     */
    public function add_meta_boxes() {
        add_meta_box(
            'wp-disk-link-manager-settings',
            __('网盘链接设置', 'wp-disk-link-manager'),
            array($this, 'render_meta_box'),
            'post',
            'side',
            'high'
        );
    }
    
    /**
     * 渲染meta box
     */
    public function render_meta_box($post) {
        wp_nonce_field('wp_disk_link_manager_meta_box', 'wp_disk_link_manager_meta_box_nonce');
        
        $disk_links = get_post_meta($post->ID, '_disk_links', true);
        
        if (empty($disk_links)) {
            echo '<p>' . __('此文章没有网盘链接', 'wp-disk-link-manager') . '</p>';
            return;
        }
        
        echo '<div class="wp-disk-link-settings">';
        foreach ($disk_links as $index => $link_data) {
            $url = $link_data['url'];
            $show_directly = $link_data['show_directly'] ?? false;
            $disk_type = $this->get_disk_type($url);
            
            echo '<div class="disk-link-item">';
            echo '<p><strong>' . $this->get_button_text($disk_type) . '</strong></p>';
            echo '<p><small>' . esc_html(substr($url, 0, 50)) . '...</small></p>';
            echo '<label>';
            echo '<input type="checkbox" name="disk_links[' . $index . '][show_directly]" value="1" ' . checked($show_directly, true, false) . '>';
            echo ' ' . __('直接显示链接（不转存）', 'wp-disk-link-manager');
            echo '</label>';
            echo '<hr>';
            echo '</div>';
        }
        echo '</div>';
    }
    
    /**
     * 保存meta box数据
     */
    public function save_meta_boxes($post_id) {
        if (!isset($_POST['wp_disk_link_manager_meta_box_nonce']) || 
            !wp_verify_nonce($_POST['wp_disk_link_manager_meta_box_nonce'], 'wp_disk_link_manager_meta_box')) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        if (isset($_POST['disk_links'])) {
            $existing_links = get_post_meta($post_id, '_disk_links', true);
            
            if (!empty($existing_links)) {
                foreach ($existing_links as $index => $link_data) {
                    $show_directly = isset($_POST['disk_links'][$index]['show_directly']);
                    $existing_links[$index]['show_directly'] = $show_directly;
                }
                
                update_post_meta($post_id, '_disk_links', $existing_links);
            }
        }
    }
    
    /**
     * 插件激活
     */
    public static function activate() {
        self::create_tables();
        
        // 添加默认选项
        add_option('wp_disk_link_manager_transfer_expire_hours', 72);
        add_option('wp_disk_link_manager_show_directly_baidu', false);
        add_option('wp_disk_link_manager_show_directly_quark', false);
        add_option('wp_disk_link_manager_show_directly_xunlei', false);
        add_option('wp_disk_link_manager_show_directly_magnet', true);
        
        // 调度定时任务
        if (!wp_next_scheduled('wp_disk_link_manager_cleanup')) {
            wp_schedule_event(time(), 'hourly', 'wp_disk_link_manager_cleanup');
        }
    }
    
    /**
     * 插件停用
     */
    public static function deactivate() {
        // 清除定时任务
        wp_clear_scheduled_hook('wp_disk_link_manager_cleanup');
    }
    
    /**
     * 插件卸载
     */
    public static function uninstall() {
        global $wpdb;
        
        // 删除数据库表
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}disk_link_transfers");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}disk_link_logs");
        
        // 删除选项
        delete_option('wp_disk_link_manager_baidu_cookie');
        delete_option('wp_disk_link_manager_quark_cookie');
        delete_option('wp_disk_link_manager_transfer_expire_hours');
        delete_option('wp_disk_link_manager_show_directly_baidu');
        delete_option('wp_disk_link_manager_show_directly_quark');
        delete_option('wp_disk_link_manager_show_directly_xunlei');
        delete_option('wp_disk_link_manager_show_directly_magnet');
        
        // 删除所有文章的meta数据
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_disk_links'");
    }
    
    /**
     * 创建数据库表
     */
    private static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // 转存记录表
        $table_transfers = $wpdb->prefix . 'disk_link_transfers';
        $sql_transfers = "CREATE TABLE $table_transfers (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            link_index int(11) NOT NULL,
            original_url text NOT NULL,
            transferred_url text,
            disk_type varchar(20) NOT NULL,
            expire_time datetime NOT NULL,
            created_time datetime DEFAULT CURRENT_TIMESTAMP,
            status varchar(20) DEFAULT 'pending',
            user_id bigint(20) NOT NULL,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY expire_time (expire_time),
            KEY status (status)
        ) $charset_collate;";
        
        // 日志表
        $table_logs = $wpdb->prefix . 'disk_link_logs';
        $sql_logs = "CREATE TABLE $table_logs (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            action varchar(50) NOT NULL,
            post_id bigint(20),
            user_id bigint(20),
            message text,
            details text,
            created_time datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY action (action),
            KEY created_time (created_time)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_transfers);
        dbDelta($sql_logs);
    }
}