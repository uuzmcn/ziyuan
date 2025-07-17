<?php
/**
 * 管理后台类
 */
class WP_Disk_Link_Manager_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_upload_excel', array($this, 'handle_excel_upload'));
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
                            <p class="description"><?php _e('从浏览器开发者工具中复制完整的Cookie', 'wp-disk-link-manager'); ?></p>
                            
                            <h4><?php _e('夸克网盘Cookie', 'wp-disk-link-manager'); ?></h4>
                            <textarea name="wp_disk_link_manager_quark_cookie" rows="5" cols="50" class="large-text"><?php echo esc_textarea(get_option('wp_disk_link_manager_quark_cookie')); ?></textarea>
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
}