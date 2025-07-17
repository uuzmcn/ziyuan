<?php
/**
 * 管理后台类
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}
class WP_Disk_Link_Manager_Admin {
    
    private $disk_manager;
    private $logger;
    
    public function __construct() {
        // 延迟初始化以避免循环依赖
        $this->disk_manager = null;
        $this->logger = null;
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_upload_excel', array($this, 'handle_excel_upload'));
        add_action('wp_ajax_import_plugin_file', array($this, 'handle_plugin_file_import'));
        add_action('wp_ajax_preview_plugin_file', array($this, 'handle_plugin_file_preview'));
        add_action('wp_ajax_test_disk_cookie', array($this, 'handle_test_disk_cookie'));
        add_action('wp_ajax_validate_all_cookies', array($this, 'handle_validate_all_cookies'));
    }
    
    /**
     * 获取disk_manager实例
     */
    private function get_disk_manager() {
        if ($this->disk_manager === null) {
            $this->disk_manager = new WP_Disk_Link_Manager_Disk_Manager();
        }
        return $this->disk_manager;
    }
    
    /**
     * 获取logger实例
     */
    private function get_logger() {
        if ($this->logger === null) {
            $this->logger = new WP_Disk_Link_Manager_Logger();
        }
        return $this->logger;
    }
    
    /**
     * 添加管理菜单
     */
    public function add_admin_menu() {
        add_menu_page(
            __('网盘链接管理', 'wp-disk-link-manager'),
            __('网盘链接', 'wp-disk-link-manager'),
            'manage_options',
            'wp-disk-link-manager',
            array($this, 'admin_page'),
            'dashicons-cloud',
            30
        );
        
        add_submenu_page(
            'wp-disk-link-manager',
            __('设置', 'wp-disk-link-manager'),
            __('设置', 'wp-disk-link-manager'),
            'manage_options',
            'wp-disk-link-manager-settings',
            array($this, 'settings_page')
        );
        
        add_submenu_page(
            'wp-disk-link-manager',
            __('导入Excel', 'wp-disk-link-manager'),
            __('导入Excel', 'wp-disk-link-manager'),
            'manage_options',
            'wp-disk-link-manager-import',
            array($this, 'import_page')
        );
        
        add_submenu_page(
            'wp-disk-link-manager',
            __('日志', 'wp-disk-link-manager'),
            __('日志', 'wp-disk-link-manager'),
            'manage_options',
            'wp-disk-link-manager-logs',
            array($this, 'logs_page')
        );
    }
    
    /**
     * 注册设置
     */
    public function register_settings() {
        // 网盘账号设置
        register_setting('wp_disk_link_manager_settings', 'wp_disk_link_manager_baidu_cookie');
        register_setting('wp_disk_link_manager_settings', 'wp_disk_link_manager_quark_cookie');
        
        // 显示设置
        register_setting('wp_disk_link_manager_settings', 'wp_disk_link_manager_show_directly_baidu');
        register_setting('wp_disk_link_manager_settings', 'wp_disk_link_manager_show_directly_quark');
        register_setting('wp_disk_link_manager_settings', 'wp_disk_link_manager_show_directly_xunlei');
        register_setting('wp_disk_link_manager_settings', 'wp_disk_link_manager_show_directly_magnet');
        
        // 转存设置
        register_setting('wp_disk_link_manager_settings', 'wp_disk_link_manager_transfer_expire_hours');
    }
    
    /**
     * 加载管理脚本
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'wp-disk-link-manager') === false) {
            return;
        }
        
        wp_enqueue_script(
            'wp-disk-link-manager-admin',
            WP_DISK_LINK_MANAGER_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            WP_DISK_LINK_MANAGER_VERSION,
            true
        );
        
        wp_enqueue_style(
            'wp-disk-link-manager-admin',
            WP_DISK_LINK_MANAGER_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WP_DISK_LINK_MANAGER_VERSION
        );
        
        wp_localize_script('wp-disk-link-manager-admin', 'wpDiskLinkManagerAdmin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_disk_link_manager_admin_nonce')
        ));
    }
    
    /**
     * 主管理页面
     */
    public function admin_page() {
        global $wpdb;
        
        // 获取统计数据
        $total_posts = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_disk_links'");
        $total_transfers = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}disk_link_transfers");
        $active_transfers = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}disk_link_transfers WHERE status = 'completed' AND expire_time > NOW()");
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="wp-disk-link-dashboard">
                <div class="dashboard-stats">
                    <div class="stat-box">
                        <h3><?php _e('总文章数', 'wp-disk-link-manager'); ?></h3>
                        <p class="stat-number"><?php echo $total_posts; ?></p>
                    </div>
                    <div class="stat-box">
                        <h3><?php _e('总转存数', 'wp-disk-link-manager'); ?></h3>
                        <p class="stat-number"><?php echo $total_transfers; ?></p>
                    </div>
                    <div class="stat-box">
                        <h3><?php _e('有效转存', 'wp-disk-link-manager'); ?></h3>
                        <p class="stat-number"><?php echo $active_transfers; ?></p>
                    </div>
                </div>
                
                <div class="quick-actions">
                    <h2><?php _e('快速操作', 'wp-disk-link-manager'); ?></h2>
                    <p>
                        <a href="<?php echo admin_url('admin.php?page=wp-disk-link-manager-import'); ?>" class="button button-primary">
                            <?php _e('导入Excel文件', 'wp-disk-link-manager'); ?>
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=wp-disk-link-manager-settings'); ?>" class="button">
                            <?php _e('插件设置', 'wp-disk-link-manager'); ?>
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=wp-disk-link-manager-logs'); ?>" class="button">
                            <?php _e('查看日志', 'wp-disk-link-manager'); ?>
                        </a>
                    </p>
                </div>
                
                <div class="recent-transfers">
                    <h2><?php _e('最近转存', 'wp-disk-link-manager'); ?></h2>
                    <?php $this->display_recent_transfers(); ?>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * 设置页面
     */
    public function settings_page() {
        if (isset($_POST['submit'])) {
            if (wp_verify_nonce($_POST['_wpnonce'], 'wp_disk_link_manager_settings-options')) {
                // 保存设置
                update_option('wp_disk_link_manager_baidu_cookie', sanitize_textarea_field($_POST['wp_disk_link_manager_baidu_cookie']));
                update_option('wp_disk_link_manager_quark_cookie', sanitize_textarea_field($_POST['wp_disk_link_manager_quark_cookie']));
                update_option('wp_disk_link_manager_show_directly_baidu', isset($_POST['wp_disk_link_manager_show_directly_baidu']));
                update_option('wp_disk_link_manager_show_directly_quark', isset($_POST['wp_disk_link_manager_show_directly_quark']));
                update_option('wp_disk_link_manager_show_directly_xunlei', isset($_POST['wp_disk_link_manager_show_directly_xunlei']));
                update_option('wp_disk_link_manager_show_directly_magnet', isset($_POST['wp_disk_link_manager_show_directly_magnet']));
                update_option('wp_disk_link_manager_transfer_expire_hours', intval($_POST['wp_disk_link_manager_transfer_expire_hours']));
                
                echo '<div class="notice notice-success"><p>' . __('设置已保存', 'wp-disk-link-manager') . '</p></div>';
            }
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('wp_disk_link_manager_settings-options'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('网盘账号设置', 'wp-disk-link-manager'); ?></th>
                        <td>
                            <h4><?php _e('百度网盘Cookie', 'wp-disk-link-manager'); ?></h4>
                            <textarea name="wp_disk_link_manager_baidu_cookie" rows="5" cols="50" class="large-text"><?php echo esc_textarea(get_option('wp_disk_link_manager_baidu_cookie')); ?></textarea>
                            <button type="button" class="test-cookie-btn" data-disk-type="baidu"><?php _e('测试Cookie', 'wp-disk-link-manager'); ?></button>
                            <p class="description"><?php _e('从浏览器开发者工具中复制完整的Cookie', 'wp-disk-link-manager'); ?></p>
                            
                            <h4><?php _e('夸克网盘Cookie', 'wp-disk-link-manager'); ?></h4>
                            <textarea name="wp_disk_link_manager_quark_cookie" rows="5" cols="50" class="large-text"><?php echo esc_textarea(get_option('wp_disk_link_manager_quark_cookie')); ?></textarea>
                            <button type="button" class="test-cookie-btn" data-disk-type="quark"><?php _e('测试Cookie', 'wp-disk-link-manager'); ?></button>
                            <p class="description"><?php _e('从浏览器开发者工具中复制完整的Cookie', 'wp-disk-link-manager'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('链接显示设置', 'wp-disk-link-manager'); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="wp_disk_link_manager_show_directly_baidu" value="1" <?php checked(get_option('wp_disk_link_manager_show_directly_baidu')); ?>>
                                    <?php _e('百度网盘链接直接显示（不转存）', 'wp-disk-link-manager'); ?>
                                </label><br>
                                
                                <label>
                                    <input type="checkbox" name="wp_disk_link_manager_show_directly_quark" value="1" <?php checked(get_option('wp_disk_link_manager_show_directly_quark')); ?>>
                                    <?php _e('夸克网盘链接直接显示（不转存）', 'wp-disk-link-manager'); ?>
                                </label><br>
                                
                                <label>
                                    <input type="checkbox" name="wp_disk_link_manager_show_directly_xunlei" value="1" <?php checked(get_option('wp_disk_link_manager_show_directly_xunlei')); ?>>
                                    <?php _e('迅雷网盘链接直接显示（不转存）', 'wp-disk-link-manager'); ?>
                                </label><br>
                                
                                <label>
                                    <input type="checkbox" name="wp_disk_link_manager_show_directly_magnet" value="1" <?php checked(get_option('wp_disk_link_manager_show_directly_magnet')); ?>>
                                    <?php _e('磁力链直接显示', 'wp-disk-link-manager'); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('转存设置', 'wp-disk-link-manager'); ?></th>
                        <td>
                            <input type="number" name="wp_disk_link_manager_transfer_expire_hours" value="<?php echo esc_attr(get_option('wp_disk_link_manager_transfer_expire_hours', 72)); ?>" min="1" max="8760">
                            <?php _e('小时', 'wp-disk-link-manager'); ?>
                            <p class="description"><?php _e('转存链接的有效期（小时），过期后自动删除', 'wp-disk-link-manager'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * 导入页面
     */
    public function import_page() {
        // 处理文件上传到插件目录
        if (isset($_POST['upload_to_plugin_dir']) && wp_verify_nonce($_POST['_wpnonce'], 'upload_to_plugin_dir')) {
            $this->handle_plugin_dir_upload();
        }
        
        // 处理删除插件目录文件
        if (isset($_POST['delete_plugin_file']) && wp_verify_nonce($_POST['_wpnonce'], 'delete_plugin_file')) {
            $this->handle_plugin_file_delete();
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="excel-import-container">
                <div class="import-instructions">
                    <h2><?php _e('导入说明', 'wp-disk-link-manager'); ?></h2>
                    <p><?php _e('Excel文件格式要求：', 'wp-disk-link-manager'); ?></p>
                    <ul>
                        <li><?php _e('第一列：文章标题', 'wp-disk-link-manager'); ?></li>
                        <li><?php _e('第二列：网盘链接', 'wp-disk-link-manager'); ?></li>
                        <li><?php _e('支持.xls和.xlsx格式', 'wp-disk-link-manager'); ?></li>
                        <li><?php _e('第一行可以是标题行（会自动跳过）', 'wp-disk-link-manager'); ?></li>
                    </ul>
                </div>
                
                <!-- 导入方式选择 -->
                <div class="import-method-selector">
                    <h3><?php _e('选择导入方式', 'wp-disk-link-manager'); ?></h3>
                    <label>
                        <input type="radio" name="import_method" value="upload" checked>
                        <?php _e('上传文件导入', 'wp-disk-link-manager'); ?>
                    </label>
                    <label>
                        <input type="radio" name="import_method" value="plugin_dir">
                        <?php _e('从插件目录选择', 'wp-disk-link-manager'); ?>
                    </label>
                </div>
                
                <!-- 上传文件导入 -->
                <div id="upload-import-section" class="import-section">
                    <h3><?php _e('上传Excel文件', 'wp-disk-link-manager'); ?></h3>
                    
                    <form id="excel-upload-form" enctype="multipart/form-data">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('选择Excel文件', 'wp-disk-link-manager'); ?></th>
                                <td>
                                    <input type="file" name="excel_file" id="excel_file" accept=".xls,.xlsx" required>
                                    <p class="description"><?php _e('选择要导入的Excel文件', 'wp-disk-link-manager'); ?></p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row"><?php _e('导入选项', 'wp-disk-link-manager'); ?></th>
                                <td>
                                    <fieldset>
                                        <label>
                                            <input type="checkbox" name="skip_first_row" value="1" checked>
                                            <?php _e('跳过第一行（标题行）', 'wp-disk-link-manager'); ?>
                                        </label><br>
                                        
                                        <label>
                                            <input type="radio" name="post_status" value="draft" checked>
                                            <?php _e('导入为草稿', 'wp-disk-link-manager'); ?>
                                        </label><br>
                                        
                                        <label>
                                            <input type="radio" name="post_status" value="publish">
                                            <?php _e('直接发布', 'wp-disk-link-manager'); ?>
                                        </label>
                                    </fieldset>
                                </td>
                            </tr>
                        </table>
                        
                        <p class="submit">
                            <button type="submit" class="button button-primary" id="import-btn">
                                <?php _e('开始导入', 'wp-disk-link-manager'); ?>
                            </button>
                        </p>
                    </form>
                </div>
                
                <!-- 插件目录文件选择 -->
                <div id="plugin-dir-import-section" class="import-section" style="display: none;">
                    <h3><?php _e('插件目录文件管理', 'wp-disk-link-manager'); ?></h3>
                    
                    <!-- 上传到插件目录 -->
                    <div class="plugin-dir-upload">
                        <h4><?php _e('上传文件到插件目录', 'wp-disk-link-manager'); ?></h4>
                        <form method="post" enctype="multipart/form-data">
                            <?php wp_nonce_field('upload_to_plugin_dir'); ?>
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><?php _e('选择Excel文件', 'wp-disk-link-manager'); ?></th>
                                    <td>
                                        <input type="file" name="plugin_excel_file" accept=".xls,.xlsx" required>
                                        <input type="submit" name="upload_to_plugin_dir" class="button" value="<?php _e('上传到插件目录', 'wp-disk-link-manager'); ?>">
                                        <p class="description"><?php _e('文件将保存到插件的excel目录中，供重复使用', 'wp-disk-link-manager'); ?></p>
                                    </td>
                                </tr>
                            </table>
                        </form>
                    </div>
                    
                    <!-- 插件目录文件列表 -->
                    <div class="plugin-dir-files">
                        <h4><?php _e('插件目录中的Excel文件', 'wp-disk-link-manager'); ?></h4>
                        <?php $this->display_plugin_dir_files(); ?>
                    </div>
                    
                    <!-- 从插件目录导入 -->
                    <form id="plugin-dir-import-form">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('选择文件', 'wp-disk-link-manager'); ?></th>
                                <td>
                                    <select name="plugin_file" id="plugin_file" required>
                                        <option value=""><?php _e('请选择文件', 'wp-disk-link-manager'); ?></option>
                                        <?php $this->render_plugin_file_options(); ?>
                                    </select>
                                    <p class="description"><?php _e('从插件目录选择要导入的Excel文件', 'wp-disk-link-manager'); ?></p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row"><?php _e('导入选项', 'wp-disk-link-manager'); ?></th>
                                <td>
                                    <fieldset>
                                        <label>
                                            <input type="checkbox" name="skip_first_row_plugin" value="1" checked>
                                            <?php _e('跳过第一行（标题行）', 'wp-disk-link-manager'); ?>
                                        </label><br>
                                        
                                        <label>
                                            <input type="radio" name="post_status_plugin" value="draft" checked>
                                            <?php _e('导入为草稿', 'wp-disk-link-manager'); ?>
                                        </label><br>
                                        
                                        <label>
                                            <input type="radio" name="post_status_plugin" value="publish">
                                            <?php _e('直接发布', 'wp-disk-link-manager'); ?>
                                        </label>
                                    </fieldset>
                                </td>
                            </tr>
                        </table>
                        
                        <p class="submit">
                            <button type="submit" class="button button-primary" id="plugin-import-btn">
                                <?php _e('从插件目录导入', 'wp-disk-link-manager'); ?>
                            </button>
                        </p>
                    </form>
                </div>
                
                <div id="import-progress" style="display: none;">
                    <h3><?php _e('导入进度', 'wp-disk-link-manager'); ?></h3>
                    <div class="progress-bar">
                        <div class="progress-fill"></div>
                    </div>
                    <p class="progress-text"></p>
                </div>
                
                <div id="import-results" style="display: none;">
                    <h3><?php _e('导入结果', 'wp-disk-link-manager'); ?></h3>
                    <div class="import-summary"></div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * 日志页面
     */
    public function logs_page() {
        global $wpdb;
        
        $page = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}disk_link_logs ORDER BY created_time DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));
        
        $total_logs = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}disk_link_logs");
        $total_pages = ceil($total_logs / $per_page);
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('时间', 'wp-disk-link-manager'); ?></th>
                        <th><?php _e('操作', 'wp-disk-link-manager'); ?></th>
                        <th><?php _e('用户', 'wp-disk-link-manager'); ?></th>
                        <th><?php _e('文章', 'wp-disk-link-manager'); ?></th>
                        <th><?php _e('消息', 'wp-disk-link-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($logs): ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo $log->created_time; ?></td>
                                <td><?php echo esc_html($log->action); ?></td>
                                <td>
                                    <?php 
                                    if ($log->user_id) {
                                        $user = get_user_by('id', $log->user_id);
                                        echo $user ? $user->display_name : $log->user_id;
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    if ($log->post_id) {
                                        $post = get_post($log->post_id);
                                        if ($post) {
                                            echo '<a href="' . get_edit_post_link($post->ID) . '">' . esc_html($post->post_title) . '</a>';
                                        } else {
                                            echo $log->post_id;
                                        }
                                    }
                                    ?>
                                </td>
                                <td><?php echo esc_html($log->message); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5"><?php _e('暂无日志记录', 'wp-disk-link-manager'); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <?php if ($total_pages > 1): ?>
                <div class="tablenav">
                    <div class="tablenav-pages">
                        <?php
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => __('&laquo;'),
                            'next_text' => __('&raquo;'),
                            'total' => $total_pages,
                            'current' => $page
                        ));
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * 显示最近转存记录
     */
    private function display_recent_transfers() {
        global $wpdb;
        
        $transfers = $wpdb->get_results(
            "SELECT t.*, p.post_title 
             FROM {$wpdb->prefix}disk_link_transfers t 
             LEFT JOIN {$wpdb->posts} p ON t.post_id = p.ID 
             ORDER BY t.created_time DESC 
             LIMIT 10"
        );
        
        if (empty($transfers)) {
            echo '<p>' . __('暂无转存记录', 'wp-disk-link-manager') . '</p>';
            return;
        }
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>' . __('文章', 'wp-disk-link-manager') . '</th>';
        echo '<th>' . __('网盘类型', 'wp-disk-link-manager') . '</th>';
        echo '<th>' . __('状态', 'wp-disk-link-manager') . '</th>';
        echo '<th>' . __('过期时间', 'wp-disk-link-manager') . '</th>';
        echo '<th>' . __('创建时间', 'wp-disk-link-manager') . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        foreach ($transfers as $transfer) {
            echo '<tr>';
            echo '<td>';
            if ($transfer->post_title) {
                echo '<a href="' . get_edit_post_link($transfer->post_id) . '">' . esc_html($transfer->post_title) . '</a>';
            } else {
                echo $transfer->post_id;
            }
            echo '</td>';
            echo '<td>' . esc_html(ucfirst($transfer->disk_type)) . '</td>';
            echo '<td>';
            if ($transfer->status === 'completed') {
                echo '<span style="color: green;">' . __('已完成', 'wp-disk-link-manager') . '</span>';
            } elseif ($transfer->status === 'failed') {
                echo '<span style="color: red;">' . __('失败', 'wp-disk-link-manager') . '</span>';
            } else {
                echo '<span style="color: orange;">' . __('进行中', 'wp-disk-link-manager') . '</span>';
            }
            echo '</td>';
            echo '<td>' . $transfer->expire_time . '</td>';
            echo '<td>' . $transfer->created_time . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
    }
    
    /**
     * 处理Excel上传
     */
    public function handle_excel_upload() {
        check_ajax_referer('wp_disk_link_manager_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('权限不足', 'wp-disk-link-manager'));
        }
        
        if (!isset($_FILES['excel_file'])) {
            wp_send_json_error(__('未选择文件', 'wp-disk-link-manager'));
        }
        
        $file = $_FILES['excel_file'];
        $skip_first_row = isset($_POST['skip_first_row']);
        $post_status = sanitize_text_field($_POST['post_status']);
        
        try {
            $importer = new WP_Disk_Link_Manager_Excel_Importer();
            $result = $importer->import($file, $skip_first_row, $post_status);
            
            wp_send_json_success($result);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * 处理上传文件到插件目录
     */
    private function handle_plugin_dir_upload() {
        if (!current_user_can('manage_options')) {
            wp_die(__('权限不足', 'wp-disk-link-manager'));
        }
        
        if (!isset($_FILES['plugin_excel_file'])) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>' . __('未选择文件', 'wp-disk-link-manager') . '</p></div>';
            });
            return;
        }
        
        $file = $_FILES['plugin_excel_file'];
        
        // 验证文件
        if ($file['error'] !== UPLOAD_ERR_OK) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>' . __('文件上传错误', 'wp-disk-link-manager') . '</p></div>';
            });
            return;
        }
        
        $allowed_types = array('xls', 'xlsx');
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_extension, $allowed_types)) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>' . __('只支持.xls和.xlsx格式', 'wp-disk-link-manager') . '</p></div>';
            });
            return;
        }
        
        // 创建插件Excel目录
        $excel_dir = $this->get_plugin_excel_dir();
        if (!file_exists($excel_dir)) {
            wp_mkdir_p($excel_dir);
        }
        
        // 生成安全的文件名
        $safe_filename = $this->generate_safe_filename($file['name']);
        $target_file = $excel_dir . $safe_filename;
        
        // 检查文件是否已存在
        if (file_exists($target_file)) {
            add_action('admin_notices', function() use ($safe_filename) {
                echo '<div class="notice notice-error"><p>' . sprintf(__('文件 %s 已存在', 'wp-disk-link-manager'), $safe_filename) . '</p></div>';
            });
            return;
        }
        
        // 移动文件
        if (move_uploaded_file($file['tmp_name'], $target_file)) {
            add_action('admin_notices', function() use ($safe_filename) {
                echo '<div class="notice notice-success"><p>' . sprintf(__('文件 %s 上传成功', 'wp-disk-link-manager'), $safe_filename) . '</p></div>';
            });
            
            // 记录日志
            $this->get_logger()->log(
                'plugin_file_upload',
                null,
                get_current_user_id(),
                sprintf(__('上传Excel文件到插件目录: %s', 'wp-disk-link-manager'), $safe_filename)
            );
        } else {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>' . __('文件上传失败', 'wp-disk-link-manager') . '</p></div>';
            });
        }
    }
    
    /**
     * 处理删除插件目录文件
     */
    private function handle_plugin_file_delete() {
        if (!current_user_can('manage_options')) {
            wp_die(__('权限不足', 'wp-disk-link-manager'));
        }
        
        $filename = sanitize_file_name($_POST['filename']);
        if (empty($filename)) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>' . __('文件名无效', 'wp-disk-link-manager') . '</p></div>';
            });
            return;
        }
        
        $file_path = $this->get_plugin_excel_dir() . $filename;
        
        if (!file_exists($file_path)) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>' . __('文件不存在', 'wp-disk-link-manager') . '</p></div>';
            });
            return;
        }
        
        if (unlink($file_path)) {
            add_action('admin_notices', function() use ($filename) {
                echo '<div class="notice notice-success"><p>' . sprintf(__('文件 %s 删除成功', 'wp-disk-link-manager'), $filename) . '</p></div>';
            });
            
            // 记录日志
            $this->get_logger()->log(
                'plugin_file_delete',
                null,
                get_current_user_id(),
                sprintf(__('删除插件目录Excel文件: %s', 'wp-disk-link-manager'), $filename)
            );
        } else {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>' . __('文件删除失败', 'wp-disk-link-manager') . '</p></div>';
            });
        }
    }
    
    /**
     * 显示插件目录文件列表
     */
    private function display_plugin_dir_files() {
        $excel_dir = $this->get_plugin_excel_dir();
        
        if (!is_dir($excel_dir)) {
            echo '<p>' . __('插件Excel目录不存在', 'wp-disk-link-manager') . '</p>';
            return;
        }
        
        $files = glob($excel_dir . '*.{xls,xlsx}', GLOB_BRACE);
        
        if (empty($files)) {
            echo '<p>' . __('插件目录中没有Excel文件', 'wp-disk-link-manager') . '</p>';
            return;
        }
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . __('文件名', 'wp-disk-link-manager') . '</th>';
        echo '<th>' . __('大小', 'wp-disk-link-manager') . '</th>';
        echo '<th>' . __('修改时间', 'wp-disk-link-manager') . '</th>';
        echo '<th>' . __('操作', 'wp-disk-link-manager') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        
        foreach ($files as $file_path) {
            $filename = basename($file_path);
            $file_size = $this->format_file_size(filesize($file_path));
            $file_time = date('Y-m-d H:i:s', filemtime($file_path));
            
            echo '<tr>';
            echo '<td><strong>' . esc_html($filename) . '</strong></td>';
            echo '<td>' . $file_size . '</td>';
            echo '<td>' . $file_time . '</td>';
            echo '<td>';
            
            // 预览按钮
            echo '<button type="button" class="button preview-file-btn" data-filename="' . esc_attr($filename) . '">' . __('预览', 'wp-disk-link-manager') . '</button> ';
            
            // 删除按钮
            echo '<form method="post" style="display: inline-block;" onsubmit="return confirm(\'' . __('确定要删除这个文件吗？', 'wp-disk-link-manager') . '\')">';
            wp_nonce_field('delete_plugin_file');
            echo '<input type="hidden" name="filename" value="' . esc_attr($filename) . '">';
            echo '<input type="submit" name="delete_plugin_file" class="button button-link-delete" value="' . __('删除', 'wp-disk-link-manager') . '">';
            echo '</form>';
            
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
    /**
     * 渲染插件文件选项
     */
    private function render_plugin_file_options() {
        $excel_dir = $this->get_plugin_excel_dir();
        
        if (!is_dir($excel_dir)) {
            return;
        }
        
        $files = glob($excel_dir . '*.{xls,xlsx}', GLOB_BRACE);
        
        foreach ($files as $file_path) {
            $filename = basename($file_path);
            $file_size = $this->format_file_size(filesize($file_path));
            
            echo '<option value="' . esc_attr($filename) . '">' . esc_html($filename) . ' (' . $file_size . ')</option>';
        }
    }
    
    /**
     * 获取插件Excel目录路径
     */
    private function get_plugin_excel_dir() {
        return WP_DISK_LINK_MANAGER_PLUGIN_PATH . 'excel/';
    }
    
    /**
     * 生成安全的文件名
     */
    private function generate_safe_filename($original_name) {
        $info = pathinfo($original_name);
        $name = sanitize_file_name($info['filename']);
        $extension = strtolower($info['extension']);
        
        // 添加时间戳避免重名
        $timestamp = date('YmdHis');
        
        return $name . '_' . $timestamp . '.' . $extension;
    }
    
    /**
     * 格式化文件大小
     */
    private function format_file_size($bytes) {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' B';
        }
    }
    
    /**
     * 处理插件文件导入
     */
    public function handle_plugin_file_import() {
        check_ajax_referer('wp_disk_link_manager_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('权限不足', 'wp-disk-link-manager'));
        }
        
        $filename = sanitize_file_name($_POST['plugin_file']);
        if (empty($filename)) {
            wp_send_json_error(__('未选择文件', 'wp-disk-link-manager'));
        }
        
        $file_path = $this->get_plugin_excel_dir() . $filename;
        
        if (!file_exists($file_path)) {
            wp_send_json_error(__('文件不存在', 'wp-disk-link-manager'));
        }
        
        $skip_first_row = isset($_POST['skip_first_row_plugin']);
        $post_status = sanitize_text_field($_POST['post_status_plugin']);
        
        try {
            // 创建临时文件数组模拟上传文件格式
            $file_array = array(
                'name' => $filename,
                'tmp_name' => $file_path,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($file_path)
            );
            
            $importer = new WP_Disk_Link_Manager_Excel_Importer();
            $result = $importer->import_from_plugin_dir($file_path, $skip_first_row, $post_status);
            
            wp_send_json_success($result);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * 处理插件文件预览
     */
    public function handle_plugin_file_preview() {
        check_ajax_referer('wp_disk_link_manager_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('权限不足', 'wp-disk-link-manager'));
        }
        
        $filename = sanitize_file_name($_POST['filename']);
        if (empty($filename)) {
            wp_send_json_error(__('文件名无效', 'wp-disk-link-manager'));
        }
        
        $file_path = $this->get_plugin_excel_dir() . $filename;
        
        if (!file_exists($file_path)) {
            wp_send_json_error(__('文件不存在', 'wp-disk-link-manager'));
        }
        
        try {
            $importer = new WP_Disk_Link_Manager_Excel_Importer();
            $preview_data = $importer->preview_file($file_path);
            
            wp_send_json_success($preview_data);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * 处理Cookie测试 (优化版)
     */
    public function handle_test_disk_cookie() {
        check_ajax_referer('wp_disk_link_manager_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('权限不足', 'wp-disk-link-manager'));
        }
        
        $disk_type = sanitize_text_field($_POST['disk_type']);
        $cookie = sanitize_textarea_field($_POST['cookie']);
        
        if (empty($cookie)) {
            wp_send_json_error(__('Cookie不能为空', 'wp-disk-link-manager'));
        }
        
        // 记录测试开始
        $this->get_logger()->log(
            'cookie_test_start',
            null,
            get_current_user_id(),
            "开始测试{$disk_type}网盘Cookie",
            ['disk_type' => $disk_type]
        );
        
        try {
            $result = null;
            
            if ($disk_type === 'quark') {
                $result = $this->get_disk_manager()->validate_quark_cookie($cookie);
                $message = $result ? '夸克网盘Cookie有效' : '夸克网盘Cookie无效';
            } elseif ($disk_type === 'baidu') {
                $result = $this->get_disk_manager()->validate_baidu_cookie($cookie);
                $message = $result ? '百度网盘Cookie有效' : '百度网盘Cookie无效';
            } else {
                wp_send_json_error(__('不支持的网盘类型', 'wp-disk-link-manager'));
            }
            
            // 记录测试结果
            $this->get_logger()->log(
                'cookie_test_result',
                null,
                get_current_user_id(),
                "Cookie测试结果: {$message}",
                ['disk_type' => $disk_type, 'valid' => $result]
            );
            
            if ($result) {
                wp_send_json_success(array('message' => $message));
            } else {
                wp_send_json_error($message);
            }
            
        } catch (Exception $e) {
            // 记录测试错误
            $this->get_logger()->log(
                'cookie_test_error',
                null,
                get_current_user_id(),
                "Cookie测试失败: " . $e->getMessage(),
                ['disk_type' => $disk_type, 'error' => $e->getMessage()]
            );
            
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * 验证所有Cookie有效性
     */
    public function handle_validate_all_cookies() {
        check_ajax_referer('wp_disk_link_manager_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('权限不足', 'wp-disk-link-manager'));
        }
        
        $results = [];
        
        // 验证夸克Cookie
        $quark_cookie = get_option('wp_disk_link_manager_quark_cookie', '');
        if (!empty($quark_cookie)) {
            try {
                $results['quark'] = $this->get_disk_manager()->validate_quark_cookie($quark_cookie);
            } catch (Exception $e) {
                $results['quark'] = false;
            }
        } else {
            $results['quark'] = null; // 未配置
        }
        
        // 验证百度Cookie
        $baidu_cookie = get_option('wp_disk_link_manager_baidu_cookie', '');
        if (!empty($baidu_cookie)) {
            try {
                $results['baidu'] = $this->get_disk_manager()->validate_baidu_cookie($baidu_cookie);
            } catch (Exception $e) {
                $results['baidu'] = false;
            }
        } else {
            $results['baidu'] = null; // 未配置
        }
        
        wp_send_json_success($results);
    }
    
    /**
     * 测试夸克网盘Cookie
     */
    private function test_quark_cookie($cookie) {
        $headers = array(
            'Cookie: ' . $cookie,
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Referer: https://pan.quark.cn/'
        );
        
        // 测试获取用户容量信息
        $user_info_url = 'https://drive-pc.quark.cn/1/clouddrive/capacity';
        
        $args = array(
            'method' => 'GET',
            'headers' => $headers,
            'timeout' => 30,
            'sslverify' => false
        );
        
        $response = wp_remote_request($user_info_url, $args);
        
        if (is_wp_error($response)) {
            throw new Exception(__('网络请求失败: ', 'wp-disk-link-manager') . $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($status_code !== 200) {
            throw new Exception(__('HTTP请求失败，状态码: ', 'wp-disk-link-manager') . $status_code);
        }
        
        $data = json_decode($response_body, true);
        
        if (!$data) {
            throw new Exception(__('响应数据解析失败', 'wp-disk-link-manager'));
        }
        
        if ($data['code'] !== 0) {
            throw new Exception(__('夸克网盘Cookie无效或已过期', 'wp-disk-link-manager'));
        }
        
        // 获取用户信息
        $user_info = array(
            'total_capacity' => $this->format_file_size($data['data']['total_capacity'] ?? 0),
            'used_capacity' => $this->format_file_size($data['data']['used_capacity'] ?? 0)
        );
        
        return array(
            'message' => __('夸克网盘Cookie有效', 'wp-disk-link-manager'),
            'user_info' => $user_info
        );
    }
    
    /**
     * 测试百度网盘Cookie
     */
    private function test_baidu_cookie($cookie) {
        $headers = array(
            'Cookie: ' . $cookie,
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Referer: https://pan.baidu.com/'
        );
        
        // 测试获取用户信息
        $user_info_url = 'https://pan.baidu.com/api/quota';
        
        $args = array(
            'method' => 'GET',
            'headers' => $headers,
            'timeout' => 30,
            'sslverify' => false
        );
        
        $response = wp_remote_request($user_info_url, $args);
        
        if (is_wp_error($response)) {
            throw new Exception(__('网络请求失败: ', 'wp-disk-link-manager') . $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($status_code !== 200) {
            throw new Exception(__('HTTP请求失败，状态码: ', 'wp-disk-link-manager') . $status_code);
        }
        
        $data = json_decode($response_body, true);
        
        if (!$data) {
            throw new Exception(__('响应数据解析失败', 'wp-disk-link-manager'));
        }
        
        if ($data['errno'] !== 0) {
            throw new Exception(__('百度网盘Cookie无效或已过期', 'wp-disk-link-manager'));
        }
        
        // 获取用户信息
        $user_info = array(
            'total_capacity' => $this->format_file_size($data['total'] ?? 0),
            'used_capacity' => $this->format_file_size($data['used'] ?? 0)
        );
        
        return array(
            'message' => __('百度网盘Cookie有效', 'wp-disk-link-manager'),
            'user_info' => $user_info
        );
    }
}