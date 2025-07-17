<?php
/**
 * 网盘管理器类
 */
class WP_Disk_Link_Manager_Disk_Manager {
    
    /**
     * 转存链接
     */
    public function transfer_link($original_url, $disk_type) {
        switch ($disk_type) {
            case 'baidu':
                return $this->transfer_baidu_link($original_url);
            case 'quark':
                return $this->transfer_quark_link($original_url);
            default:
                throw new Exception(__('不支持的网盘类型', 'wp-disk-link-manager'));
        }
    }
    
    /**
     * 转存百度网盘链接
     */
    private function transfer_baidu_link($original_url) {
        $cookie = get_option('wp_disk_link_manager_baidu_cookie');
        
        if (empty($cookie)) {
            throw new Exception(__('百度网盘Cookie未配置', 'wp-disk-link-manager'));
        }
        
        try {
            // 解析原始链接，获取分享信息
            $share_info = $this->parse_baidu_share_url($original_url);
            
            // 获取文件列表
            $file_list = $this->get_baidu_file_list($share_info, $cookie);
            
            // 转存文件到自己的网盘
            $save_result = $this->save_baidu_files($file_list, $cookie);
            
            // 创建新的分享链接
            $new_share_url = $this->create_baidu_share($save_result['file_paths'], $cookie);
            
            return $new_share_url;
            
        } catch (Exception $e) {
            throw new Exception(__('百度网盘转存失败: ', 'wp-disk-link-manager') . $e->getMessage());
        }
    }
    
    /**
     * 转存夸克网盘链接
     */
    private function transfer_quark_link($original_url) {
        $cookie = get_option('wp_disk_link_manager_quark_cookie');
        
        if (empty($cookie)) {
            throw new Exception(__('夸克网盘Cookie未配置', 'wp-disk-link-manager'));
        }
        
        // 记录详细的调试信息
        WP_Disk_Link_Manager_Logger::log(
            'quark_transfer_start',
            null,
            get_current_user_id(),
            __('开始夸克网盘转存', 'wp-disk-link-manager'),
            array('original_url' => $original_url)
        );
        
        try {
            // 解析原始链接，获取分享信息
            $share_info = $this->parse_quark_share_url($original_url);
            
            // 检查Cookie有效性
            $this->validate_quark_cookie($cookie);
            
            // 获取文件列表
            $file_list = $this->get_quark_file_list($share_info, $cookie);
            
            if (empty($file_list)) {
                throw new Exception(__('未找到可转存的文件', 'wp-disk-link-manager'));
            }
            
            // 转存文件到自己的网盘
            $save_result = $this->save_quark_files($file_list, $share_info, $cookie);
            
            if (empty($save_result['file_ids'])) {
                throw new Exception(__('文件转存失败，没有成功保存的文件', 'wp-disk-link-manager'));
            }
            
            // 创建新的分享链接
            $new_share_url = $this->create_quark_share($save_result['file_ids'], $cookie);
            
            // 记录成功日志
            WP_Disk_Link_Manager_Logger::log(
                'quark_transfer_success',
                null,
                get_current_user_id(),
                __('夸克网盘转存成功', 'wp-disk-link-manager'),
                array(
                    'original_url' => $original_url,
                    'new_url' => $new_share_url,
                    'file_count' => count($save_result['file_ids'])
                )
            );
            
            return $new_share_url;
            
        } catch (Exception $e) {
            // 记录详细错误日志
            WP_Disk_Link_Manager_Logger::log(
                'quark_transfer_error',
                null,
                get_current_user_id(),
                __('夸克网盘转存失败: ', 'wp-disk-link-manager') . $e->getMessage(),
                array(
                    'original_url' => $original_url,
                    'error_message' => $e->getMessage(),
                    'error_trace' => $e->getTraceAsString()
                )
            );
            
            throw new Exception(__('夸克网盘转存失败: ', 'wp-disk-link-manager') . $e->getMessage());
        }
    }
    
    /**
     * 解析百度网盘分享链接
     */
    private function parse_baidu_share_url($url) {
        // 匹配百度网盘分享链接格式
        if (preg_match('/https:\/\/pan\.baidu\.com\/s\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
            $share_id = $matches[1];
        } else {
            throw new Exception(__('无效的百度网盘链接格式', 'wp-disk-link-manager'));
        }
        
        // 提取提取码（如果有）
        $password = '';
        if (preg_match('/(?:pwd=|提取码[:：]\s*)([a-zA-Z0-9]{4})/', $url, $pwd_matches)) {
            $password = $pwd_matches[1];
        }
        
        return array(
            'share_id' => $share_id,
            'password' => $password,
            'url' => $url
        );
    }
    
    /**
     * 解析夸克网盘分享链接
     */
    private function parse_quark_share_url($url) {
        // 匹配夸克网盘分享链接格式
        if (preg_match('/https:\/\/pan\.quark\.cn\/s\/([a-zA-Z0-9]+)/', $url, $matches)) {
            $share_id = $matches[1];
        } else {
            throw new Exception(__('无效的夸克网盘链接格式', 'wp-disk-link-manager'));
        }
        
        // 提取提取码（如果有）
        $password = '';
        if (preg_match('/(?:pwd=|提取码[:：]\s*)([a-zA-Z0-9]{4})/', $url, $pwd_matches)) {
            $password = $pwd_matches[1];
        }
        
        return array(
            'share_id' => $share_id,
            'password' => $password,
            'url' => $url
        );
    }
    
    /**
     * 获取百度网盘文件列表
     */
    private function get_baidu_file_list($share_info, $cookie) {
        $headers = array(
            'Cookie: ' . $cookie,
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Referer: https://pan.baidu.com/'
        );
        
        // 首先获取分享页面，提取必要的参数
        $share_url = 'https://pan.baidu.com/s/' . $share_info['share_id'];
        $response = $this->make_http_request($share_url, 'GET', null, $headers);
        
        // 解析页面，获取bdstoken、logid等参数
        $bdstoken = $this->extract_from_response($response, '/bdstoken":"([^"]+)"/', 1);
        $logid = $this->extract_from_response($response, '/logid":"([^"]+)"/', 1);
        
        if (!$bdstoken) {
            throw new Exception(__('无法获取百度网盘访问令牌', 'wp-disk-link-manager'));
        }
        
        // 如果有密码，需要先验证密码
        if (!empty($share_info['password'])) {
            $this->verify_baidu_password($share_info, $bdstoken, $cookie);
        }
        
        // 获取文件列表
        $list_url = 'https://pan.baidu.com/share/list?app_id=250528&channel=chunlei&clienttype=0&desc=0&num=100&order=time&page=1&root=1&showempty=0&web=1';
        $list_url .= '&bdstoken=' . $bdstoken;
        $list_url .= '&shareid=' . $share_info['share_id'];
        
        $response = $this->make_http_request($list_url, 'GET', null, $headers);
        $data = json_decode($response, true);
        
        if (!$data || $data['errno'] !== 0) {
            throw new Exception(__('获取百度网盘文件列表失败', 'wp-disk-link-manager'));
        }
        
        return $data['list'];
    }
    
    /**
     * 获取夸克网盘文件列表
     */
    private function get_quark_file_list($share_info, $cookie) {
        $headers = array(
            'Cookie: ' . $cookie,
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Referer: https://pan.quark.cn/',
            'Accept: application/json, text/plain, */*',
            'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
            'Origin: https://pan.quark.cn'
        );
        
        try {
            // 第一步：获取分享页面基本信息
            $share_url = 'https://pan.quark.cn/s/' . $share_info['share_id'];
            $response = $this->make_http_request($share_url, 'GET', null, array(
                'Cookie: ' . $cookie,
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
            ));
            
            // 第二步：获取分享令牌
            $detail_url = 'https://drive-pc.quark.cn/1/clouddrive/share/sharepage/token';
            $post_data = array(
                'pwd_id' => $share_info['share_id'],
                'passcode' => $share_info['password'] ?: ''
            );
            
            $response = $this->make_http_request($detail_url, 'POST', json_encode($post_data), array_merge($headers, array(
                'Content-Type: application/json'
            )));
            
            $data = json_decode($response, true);
            
            if (!$data) {
                throw new Exception(__('夸克网盘响应解析失败', 'wp-disk-link-manager'));
            }
            
            if ($data['code'] !== 0) {
                $error_msg = isset($data['message']) ? $data['message'] : __('未知错误', 'wp-disk-link-manager');
                throw new Exception(__('获取夸克网盘分享令牌失败: ', 'wp-disk-link-manager') . $error_msg);
            }
            
            if (!isset($data['data']['token'])) {
                throw new Exception(__('夸克网盘返回数据中缺少令牌', 'wp-disk-link-manager'));
            }
            
            $token = $data['data']['token'];
            
            // 第三步：获取文件列表
            $list_url = 'https://drive-pc.quark.cn/1/clouddrive/share/sharepage/detail';
            $post_data = array(
                'pwd_id' => $share_info['share_id'],
                'stoken' => $token,
                'pdir_fid' => '0',
                'force' => 0,
                'page' => 1,
                'size' => 50,
                'fetch_banner' => 1,
                'fetch_share' => 1,
                'fetch_total' => 1,
                'sort' => 'updated_at:desc'
            );
            
            $response = $this->make_http_request($list_url, 'POST', json_encode($post_data), array_merge($headers, array(
                'Content-Type: application/json'
            )));
            
            $data = json_decode($response, true);
            
            if (!$data) {
                throw new Exception(__('夸克网盘文件列表响应解析失败', 'wp-disk-link-manager'));
            }
            
            if ($data['code'] !== 0) {
                $error_msg = isset($data['message']) ? $data['message'] : __('未知错误', 'wp-disk-link-manager');
                throw new Exception(__('获取夸克网盘文件列表失败: ', 'wp-disk-link-manager') . $error_msg);
            }
            
            if (!isset($data['data']['list'])) {
                throw new Exception(__('夸克网盘返回数据中缺少文件列表', 'wp-disk-link-manager'));
            }
            
            // 在文件信息中添加必要的令牌信息
            $file_list = $data['data']['list'];
            foreach ($file_list as &$file) {
                $file['stoken'] = $token;
                $file['pwd_id'] = $share_info['share_id'];
            }
            
            return $file_list;
            
        } catch (Exception $e) {
            // 记录详细错误信息
            WP_Disk_Link_Manager_Logger::log(
                'quark_get_file_list_error',
                null,
                get_current_user_id(),
                __('获取夸克网盘文件列表失败: ', 'wp-disk-link-manager') . $e->getMessage(),
                array(
                    'share_info' => $share_info,
                    'error' => $e->getMessage()
                )
            );
            
            throw $e;
        }
    }
    
    /**
     * 保存百度网盘文件
     */
    private function save_baidu_files($file_list, $cookie) {
        // 这里需要实现百度网盘的文件保存逻辑
        // 由于百度网盘API比较复杂，这里提供一个框架
        
        $headers = array(
            'Cookie: ' . $cookie,
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Referer: https://pan.baidu.com/'
        );
        
        // 获取用户信息和bdstoken
        $user_info = $this->get_baidu_user_info($cookie);
        
        $saved_files = array();
        
        foreach ($file_list as $file) {
            // 构建保存请求
            $save_url = 'https://pan.baidu.com/share/transfer';
            $post_data = array(
                'shareid' => $file['shareid'],
                'from' => $file['from'],
                'bdstoken' => $user_info['bdstoken'],
                'channel' => 'chunlei',
                'web' => '1',
                'app_id' => '250528',
                'logid' => time(),
                'clienttype' => '0'
            );
            
            $response = $this->make_http_request($save_url, 'POST', http_build_query($post_data), $headers);
            $result = json_decode($response, true);
            
            if ($result && $result['errno'] === 0) {
                $saved_files[] = $file['path'];
            }
        }
        
        return array('file_paths' => $saved_files);
    }
    
    /**
     * 保存夸克网盘文件
     */
    private function save_quark_files($file_list, $share_info, $cookie) {
        $headers = array(
            'Cookie: ' . $cookie,
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Referer: https://pan.quark.cn/',
            'Content-Type: application/json',
            'Accept: application/json, text/plain, */*',
            'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
            'Origin: https://pan.quark.cn'
        );
        
        $saved_files = array();
        $failed_files = array();
        
        foreach ($file_list as $file) {
            try {
                // 检查必要的文件信息
                if (!isset($file['fid']) || !isset($file['share_fid_token'])) {
                    $failed_files[] = array(
                        'file' => isset($file['file_name']) ? $file['file_name'] : 'unknown',
                        'error' => __('文件信息不完整', 'wp-disk-link-manager')
                    );
                    continue;
                }
                
                $save_url = 'https://drive-pc.quark.cn/1/clouddrive/share/sharepage/save';
                $post_data = array(
                    'fid_list' => array($file['fid']),
                    'fid_token_list' => array($file['share_fid_token']),
                    'to_pdir_fid' => '0',
                    'pwd_id' => $file['pwd_id'],
                    'stoken' => $file['stoken'],
                    'pdir_fid' => '0',
                    'scene' => 'link'
                );
                
                $response = $this->make_http_request($save_url, 'POST', json_encode($post_data), $headers);
                $result = json_decode($response, true);
                
                if (!$result) {
                    throw new Exception(__('夸克网盘保存文件响应解析失败', 'wp-disk-link-manager'));
                }
                
                if ($result['code'] === 0) {
                    // 保存成功，获取新的文件ID
                    if (isset($result['data']['task_id'])) {
                        // 异步任务，需要等待完成
                        $new_fid = $this->wait_for_quark_save_task($result['data']['task_id'], $cookie);
                        if ($new_fid) {
                            $saved_files[] = $new_fid;
                        }
                    } elseif (isset($result['data']['file'])) {
                        // 直接返回文件信息
                        $saved_files[] = $result['data']['file']['fid'];
                    } else {
                        // 使用原文件ID（可能已存在）
                        $saved_files[] = $file['fid'];
                    }
                    
                    WP_Disk_Link_Manager_Logger::log(
                        'quark_save_file_success',
                        null,
                        get_current_user_id(),
                        sprintf(__('夸克网盘文件保存成功: %s', 'wp-disk-link-manager'), $file['file_name'] ?? 'unknown'),
                        array('file_info' => $file, 'response' => $result)
                    );
                } else {
                    $error_msg = isset($result['message']) ? $result['message'] : __('未知错误', 'wp-disk-link-manager');
                    $failed_files[] = array(
                        'file' => $file['file_name'] ?? 'unknown',
                        'error' => $error_msg
                    );
                    
                    WP_Disk_Link_Manager_Logger::log(
                        'quark_save_file_failed',
                        null,
                        get_current_user_id(),
                        sprintf(__('夸克网盘文件保存失败: %s - %s', 'wp-disk-link-manager'), $file['file_name'] ?? 'unknown', $error_msg),
                        array('file_info' => $file, 'response' => $result)
                    );
                }
                
                // 添加延迟避免请求过频
                usleep(500000); // 0.5秒
                
            } catch (Exception $e) {
                $failed_files[] = array(
                    'file' => $file['file_name'] ?? 'unknown',
                    'error' => $e->getMessage()
                );
                
                WP_Disk_Link_Manager_Logger::log(
                    'quark_save_file_exception',
                    null,
                    get_current_user_id(),
                    sprintf(__('夸克网盘文件保存异常: %s - %s', 'wp-disk-link-manager'), $file['file_name'] ?? 'unknown', $e->getMessage()),
                    array('file_info' => $file, 'error' => $e->getMessage())
                );
            }
        }
        
        // 如果没有成功保存任何文件，抛出异常
        if (empty($saved_files) && !empty($file_list)) {
            $error_details = array();
            foreach ($failed_files as $failed) {
                $error_details[] = $failed['file'] . ': ' . $failed['error'];
            }
            throw new Exception(__('所有文件保存失败: ', 'wp-disk-link-manager') . implode('; ', $error_details));
        }
        
        return array(
            'file_ids' => $saved_files,
            'failed_files' => $failed_files,
            'success_count' => count($saved_files),
            'failed_count' => count($failed_files)
        );
    }
    
    /**
     * 创建百度网盘分享链接
     */
    private function create_baidu_share($file_paths, $cookie) {
        $headers = array(
            'Cookie: ' . $cookie,
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Referer: https://pan.baidu.com/'
        );
        
        $user_info = $this->get_baidu_user_info($cookie);
        
        $share_url = 'https://pan.baidu.com/share/set';
        $post_data = array(
            'schannel' => '4',
            'channel_list' => '[]',
            'period' => '7', // 7天有效期
            'pwd' => $this->generate_random_password(),
            'fid_list' => json_encode($file_paths),
            'bdstoken' => $user_info['bdstoken']
        );
        
        $response = $this->make_http_request($share_url, 'POST', http_build_query($post_data), $headers);
        $result = json_decode($response, true);
        
        if (!$result || $result['errno'] !== 0) {
            throw new Exception(__('创建百度网盘分享链接失败', 'wp-disk-link-manager'));
        }
        
        return $result['link'] . '?pwd=' . $post_data['pwd'];
    }
    
    /**
     * 创建夸克网盘分享链接
     */
    private function create_quark_share($file_ids, $cookie) {
        $headers = array(
            'Cookie: ' . $cookie,
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Referer: https://pan.quark.cn/',
            'Content-Type: application/json'
        );
        
        $share_url = 'https://drive-pc.quark.cn/1/clouddrive/share';
        $post_data = array(
            'fid_list' => $file_ids,
            'title' => '分享文件',
            'url_type' => 1,
            'expired_type' => 1, // 7天有效期
            'passcode' => $this->generate_random_password()
        );
        
        $response = $this->make_http_request($share_url, 'POST', json_encode($post_data), $headers);
        $result = json_decode($response, true);
        
        if (!$result || $result['code'] !== 0) {
            throw new Exception(__('创建夸克网盘分享链接失败', 'wp-disk-link-manager'));
        }
        
        return $result['data']['share_url'] . '?pwd=' . $post_data['passcode'];
    }
    
    /**
     * 删除过期文件
     */
    public function delete_expired_files($transfer_records) {
        foreach ($transfer_records as $record) {
            try {
                if ($record->disk_type === 'baidu') {
                    $this->delete_baidu_share($record->transferred_url);
                } elseif ($record->disk_type === 'quark') {
                    $this->delete_quark_share($record->transferred_url);
                }
            } catch (Exception $e) {
                // 记录删除失败的日志
                WP_Disk_Link_Manager_Logger::log(
                    'delete_failed',
                    $record->post_id,
                    0,
                    __('删除过期文件失败: ', 'wp-disk-link-manager') . $e->getMessage(),
                    array('transfer_id' => $record->id, 'url' => $record->transferred_url)
                );
            }
        }
    }
    
    /**
     * 生成随机密码
     */
    private function generate_random_password($length = 4) {
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[rand(0, strlen($chars) - 1)];
        }
        return $password;
    }
    
    /**
     * 发送HTTP请求
     */
    private function make_http_request($url, $method = 'GET', $data = null, $headers = array()) {
        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => 60, // 增加超时时间
            'sslverify' => false,
            'redirection' => 5,
            'blocking' => true,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        );
        
        if ($data && $method === 'POST') {
            $args['body'] = $data;
        }
        
        // 记录请求详情（用于调试）
        WP_Disk_Link_Manager_Logger::log(
            'http_request_debug',
            null,
            get_current_user_id(),
            sprintf(__('HTTP请求: %s %s', 'wp-disk-link-manager'), $method, $url),
            array(
                'url' => $url,
                'method' => $method,
                'headers' => $headers,
                'data_length' => $data ? strlen($data) : 0
            )
        );
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            WP_Disk_Link_Manager_Logger::log(
                'http_request_error',
                null,
                get_current_user_id(),
                sprintf(__('HTTP请求错误: %s', 'wp-disk-link-manager'), $error_message),
                array('url' => $url, 'error' => $error_message)
            );
            throw new Exception($error_message);
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        // 记录响应状态
        WP_Disk_Link_Manager_Logger::log(
            'http_response_debug',
            null,
            get_current_user_id(),
            sprintf(__('HTTP响应: %d', 'wp-disk-link-manager'), $status_code),
            array(
                'url' => $url,
                'status_code' => $status_code,
                'response_length' => strlen($response_body),
                'response_headers' => wp_remote_retrieve_headers($response)
            )
        );
        
        if ($status_code >= 400) {
            // 尝试解析错误响应
            $error_data = json_decode($response_body, true);
            $error_message = __('HTTP请求失败，状态码: ', 'wp-disk-link-manager') . $status_code;
            
            if ($error_data && isset($error_data['message'])) {
                $error_message .= ' - ' . $error_data['message'];
            }
            
            // 记录详细错误
            WP_Disk_Link_Manager_Logger::log(
                'http_error_response',
                null,
                get_current_user_id(),
                $error_message,
                array(
                    'url' => $url,
                    'status_code' => $status_code,
                    'response_body' => substr($response_body, 0, 1000) // 只记录前1000字符
                )
            );
            
            throw new Exception($error_message);
        }
        
        return $response_body;
    }
    
    /**
     * 从响应中提取数据
     */
    private function extract_from_response($response, $pattern, $group = 0) {
        if (preg_match($pattern, $response, $matches)) {
            return $matches[$group];
        }
        return null;
    }
    
    /**
     * 获取百度网盘用户信息
     */
    private function get_baidu_user_info($cookie) {
        // 这里应该实现获取用户信息的逻辑
        // 返回包含bdstoken等必要信息的数组
        return array(
            'bdstoken' => 'example_token',
            'user_id' => 'example_user_id'
        );
    }
    
    /**
     * 验证百度网盘密码
     */
    private function verify_baidu_password($share_info, $bdstoken, $cookie) {
        // 实现密码验证逻辑
        return true;
    }
    
    /**
     * 删除百度网盘分享
     */
    private function delete_baidu_share($share_url) {
        // 实现删除分享的逻辑
    }
    
    /**
     * 删除夸克网盘分享
     */
    private function delete_quark_share($share_url) {
        // 实现删除分享的逻辑
    }
    
    /**
     * 验证夸克网盘Cookie有效性
     */
    private function validate_quark_cookie($cookie) {
        try {
            $headers = array(
                'Cookie: ' . $cookie,
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Referer: https://pan.quark.cn/'
            );
            
            // 尝试获取用户信息来验证Cookie
            $user_info_url = 'https://drive-pc.quark.cn/1/clouddrive/capacity';
            $response = $this->make_http_request($user_info_url, 'GET', null, $headers);
            $data = json_decode($response, true);
            
            if (!$data || $data['code'] !== 0) {
                throw new Exception(__('夸克网盘Cookie已失效，请重新配置', 'wp-disk-link-manager'));
            }
            
            return true;
            
        } catch (Exception $e) {
            WP_Disk_Link_Manager_Logger::log(
                'quark_cookie_validation_error',
                null,
                get_current_user_id(),
                __('夸克网盘Cookie验证失败: ', 'wp-disk-link-manager') . $e->getMessage(),
                array('error' => $e->getMessage())
            );
            
            throw new Exception(__('夸克网盘Cookie验证失败: ', 'wp-disk-link-manager') . $e->getMessage());
        }
    }
    
    /**
     * 等待夸克网盘保存任务完成
     */
    private function wait_for_quark_save_task($task_id, $cookie, $max_wait = 30) {
        $headers = array(
            'Cookie: ' . $cookie,
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Referer: https://pan.quark.cn/',
            'Content-Type: application/json'
        );
        
        $waited = 0;
        while ($waited < $max_wait) {
            try {
                $task_url = 'https://drive-pc.quark.cn/1/clouddrive/task';
                $post_data = json_encode(array('task_id' => $task_id));
                
                $response = $this->make_http_request($task_url, 'POST', $post_data, $headers);
                $data = json_decode($response, true);
                
                if ($data && $data['code'] === 0) {
                    $task_status = $data['data']['status'];
                    
                    if ($task_status === 'FINISH') {
                        // 任务完成，返回文件ID
                        if (isset($data['data']['save_as']['save_as_top_fids'][0])) {
                            return $data['data']['save_as']['save_as_top_fids'][0];
                        }
                        return null;
                    } elseif ($task_status === 'FAILED') {
                        throw new Exception(__('夸克网盘保存任务失败', 'wp-disk-link-manager'));
                    }
                    // 任务还在进行中，继续等待
                }
                
                sleep(2); // 等待2秒
                $waited += 2;
                
            } catch (Exception $e) {
                WP_Disk_Link_Manager_Logger::log(
                    'quark_task_wait_error',
                    null,
                    get_current_user_id(),
                    __('等待夸克网盘任务完成时出错: ', 'wp-disk-link-manager') . $e->getMessage(),
                    array('task_id' => $task_id, 'error' => $e->getMessage())
                );
                break;
            }
        }
        
        // 超时或出错，返回null
        return null;
    }
}