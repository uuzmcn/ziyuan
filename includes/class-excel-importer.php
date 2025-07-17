<?php
/**
 * Excel导入器类
 */
class WP_Disk_Link_Manager_Excel_Importer {
    
    /**
     * 导入Excel文件
     */
    public function import($file, $skip_first_row = true, $post_status = 'draft') {
        // 验证文件
        $this->validate_file($file);
        
        // 移动上传文件
        $upload_dir = wp_upload_dir();
        $target_dir = $upload_dir['basedir'] . '/wp-disk-link-manager/';
        
        if (!file_exists($target_dir)) {
            wp_mkdir_p($target_dir);
        }
        
        $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $target_file = $target_dir . 'import_' . time() . '.' . $file_extension;
        
        if (!move_uploaded_file($file['tmp_name'], $target_file)) {
            throw new Exception(__('文件上传失败', 'wp-disk-link-manager'));
        }
        
        try {
            // 读取Excel数据
            $data = $this->read_excel_file($target_file);
            
            // 处理数据
            $result = $this->process_data($data, $skip_first_row, $post_status);
            
            // 记录日志
            WP_Disk_Link_Manager_Logger::log('excel_import', null, get_current_user_id(), 
                sprintf(__('导入了 %d 篇文章', 'wp-disk-link-manager'), $result['success_count']), 
                array('total' => $result['total'], 'success' => $result['success_count'], 'errors' => $result['errors'])
            );
            
            return $result;
            
        } finally {
            // 清理临时文件
            if (file_exists($target_file)) {
                unlink($target_file);
            }
        }
    }
    
    /**
     * 验证文件
     */
    private function validate_file($file) {
        // 检查错误
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception(__('文件上传错误', 'wp-disk-link-manager'));
        }
        
        // 检查文件大小 (最大10MB)
        $max_size = 10 * 1024 * 1024;
        if ($file['size'] > $max_size) {
            throw new Exception(__('文件大小超过限制 (10MB)', 'wp-disk-link-manager'));
        }
        
        // 检查文件类型
        $allowed_types = array('xls', 'xlsx');
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_extension, $allowed_types)) {
            throw new Exception(__('不支持的文件格式，只支持.xls和.xlsx', 'wp-disk-link-manager'));
        }
        
        // 检查MIME类型
        $allowed_mimes = array(
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/excel',
            'application/x-excel'
        );
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mime_type, $allowed_mimes)) {
            throw new Exception(__('文件类型验证失败', 'wp-disk-link-manager'));
        }
    }
    
    /**
     * 读取Excel文件
     */
    private function read_excel_file($file_path) {
        // 检查是否有SimpleXLSX库（用于读取xlsx）
        if (!class_exists('SimpleXLSX')) {
            require_once WP_DISK_LINK_MANAGER_PLUGIN_PATH . 'vendor/simplexlsx/SimpleXLSX.php';
        }
        
        $file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        
        try {
            if ($file_extension === 'xlsx') {
                return $this->read_xlsx_file($file_path);
            } else {
                return $this->read_xls_file($file_path);
            }
        } catch (Exception $e) {
            throw new Exception(__('Excel文件读取失败: ', 'wp-disk-link-manager') . $e->getMessage());
        }
    }
    
    /**
     * 读取XLSX文件
     */
    private function read_xlsx_file($file_path) {
        if (class_exists('SimpleXLSX')) {
            $xlsx = SimpleXLSX::parse($file_path);
            if ($xlsx === false) {
                throw new Exception(SimpleXLSX::parseError());
            }
            return $xlsx->rows();
        }
        
        // 备用方法：使用ZipArchive和XML解析
        return $this->read_xlsx_with_zip($file_path);
    }
    
    /**
     * 使用ZipArchive读取XLSX文件
     */
    private function read_xlsx_with_zip($file_path) {
        if (!extension_loaded('zip')) {
            throw new Exception(__('需要PHP ZIP扩展来读取XLSX文件', 'wp-disk-link-manager'));
        }
        
        $zip = new ZipArchive();
        $result = $zip->open($file_path);
        
        if ($result !== TRUE) {
            throw new Exception(__('无法打开XLSX文件', 'wp-disk-link-manager'));
        }
        
        // 读取共享字符串
        $shared_strings = array();
        $shared_strings_xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($shared_strings_xml) {
            $xml = simplexml_load_string($shared_strings_xml);
            foreach ($xml->si as $si) {
                $shared_strings[] = (string)$si->t;
            }
        }
        
        // 读取工作表数据
        $worksheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!$worksheet_xml) {
            throw new Exception(__('无法读取工作表数据', 'wp-disk-link-manager'));
        }
        
        $xml = simplexml_load_string($worksheet_xml);
        $rows = array();
        
        foreach ($xml->sheetData->row as $row) {
            $row_data = array();
            $col_index = 0;
            
            foreach ($row->c as $cell) {
                $value = '';
                
                if (isset($cell->v)) {
                    if (isset($cell['t']) && (string)$cell['t'] === 's') {
                        // 共享字符串
                        $index = (int)$cell->v;
                        $value = isset($shared_strings[$index]) ? $shared_strings[$index] : '';
                    } else {
                        $value = (string)$cell->v;
                    }
                }
                
                $row_data[$col_index] = $value;
                $col_index++;
            }
            
            $rows[] = $row_data;
        }
        
        $zip->close();
        return $rows;
    }
    
    /**
     * 读取XLS文件
     */
    private function read_xls_file($file_path) {
        // 简单的XLS读取（限制功能）
        // 对于复杂的XLS文件，建议用户转换为XLSX格式
        throw new Exception(__('XLS格式支持有限，请将文件另存为XLSX格式', 'wp-disk-link-manager'));
    }
    
    /**
     * 处理数据
     */
    private function process_data($data, $skip_first_row, $post_status) {
        $result = array(
            'total' => 0,
            'success_count' => 0,
            'errors' => array()
        );
        
        if (empty($data)) {
            throw new Exception(__('Excel文件为空', 'wp-disk-link-manager'));
        }
        
        $start_row = $skip_first_row ? 1 : 0;
        $total_rows = count($data);
        
        for ($i = $start_row; $i < $total_rows; $i++) {
            $row = $data[$i];
            
            // 跳过空行
            if (empty($row[0]) && empty($row[1])) {
                continue;
            }
            
            $result['total']++;
            
            try {
                $title = trim($row[0] ?? '');
                $disk_link = trim($row[1] ?? '');
                
                if (empty($title)) {
                    throw new Exception(__('标题不能为空', 'wp-disk-link-manager'));
                }
                
                if (empty($disk_link)) {
                    throw new Exception(__('网盘链接不能为空', 'wp-disk-link-manager'));
                }
                
                // 验证网盘链接格式
                if (!$this->validate_disk_link($disk_link)) {
                    throw new Exception(__('不支持的网盘链接格式', 'wp-disk-link-manager'));
                }
                
                // 创建文章
                $post_id = $this->create_post($title, $disk_link, $post_status);
                $result['success_count']++;
                
            } catch (Exception $e) {
                $result['errors'][] = sprintf(__('第%d行: %s', 'wp-disk-link-manager'), $i + 1, $e->getMessage());
            }
        }
        
        return $result;
    }
    
    /**
     * 验证网盘链接
     */
    private function validate_disk_link($url) {
        // 支持的网盘类型
        $supported_patterns = array(
            '/^https?:\/\/pan\.baidu\.com\//',      // 百度网盘
            '/^https?:\/\/pan\.quark\.cn\//',       // 夸克网盘
            '/^https?:\/\/.*xunlei\.com\//',        // 迅雷网盘
            '/^magnet:\?xt=urn:btih:[a-fA-F0-9]+/', // 磁力链
        );
        
        foreach ($supported_patterns as $pattern) {
            if (preg_match($pattern, $url)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 创建文章
     */
    private function create_post($title, $disk_link, $post_status) {
        // 检查是否已存在相同标题的文章
        $existing_post = get_page_by_title($title, OBJECT, 'post');
        if ($existing_post) {
            throw new Exception(__('已存在相同标题的文章', 'wp-disk-link-manager'));
        }
        
        // 创建文章内容
        $content = $this->generate_post_content($disk_link);
        
        // 创建文章数据
        $post_data = array(
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => $post_status,
            'post_author'  => get_current_user_id(),
            'post_type'    => 'post',
        );
        
        // 插入文章
        $post_id = wp_insert_post($post_data);
        
        if (is_wp_error($post_id)) {
            throw new Exception($post_id->get_error_message());
        }
        
        // 保存网盘链接信息
        $disk_links = array(
            array(
                'url' => $disk_link,
                'show_directly' => false
            )
        );
        
        update_post_meta($post_id, '_disk_links', $disk_links);
        
        return $post_id;
    }
    
    /**
     * 生成文章内容
     */
    private function generate_post_content($disk_link) {
        $disk_type = $this->get_disk_type($disk_link);
        $button_text = $this->get_button_text($disk_type);
        
        $content = "<p>点击下方按钮获取下载链接：</p>\n\n";
        $content .= "{{DISK_LINK_0}}\n\n";
        $content .= "<p><em>注意：链接可能有时效性，请及时下载。</em></p>";
        
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
}