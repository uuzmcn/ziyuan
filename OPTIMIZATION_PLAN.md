# WP Disk Link Manager 优化方案

## 概述

基于对夸克网盘和百度网盘API的深入研究，以下是针对当前插件的优化建议。

## 1. 前端显示优化 ✅

### 已完成修改
- 将"正在转存"修改为"正在获取"
- 将"转存失败"修改为"获取失败"

### 修改文件
- `includes/class-wp-disk-link-manager.php`
- `includes/class-ajax.php`
- `includes/class-disk-manager.php`
- `TROUBLESHOOTING.md`
- `README.md`

## 2. 夸克网盘API优化建议

### 2.1 改进API请求流程

当前插件的夸克网盘实现可以参考以下优化：

```php
// 优化后的夸克网盘请求头
private function get_optimized_quark_headers() {
    return [
        'Cookie' => get_option('wp_disk_link_manager_quark_cookie', ''),
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Referer' => 'https://pan.quark.cn/',
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/plain, */*',
        'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
        'Accept-Encoding' => 'gzip, deflate, br',
        'Connection' => 'keep-alive',
        'Sec-Fetch-Dest' => 'empty',
        'Sec-Fetch-Mode' => 'cors',
        'Sec-Fetch-Site' => 'same-origin'
    ];
}
```

### 2.2 三步式API调用优化

```php
// 步骤1: 获取分享token (优化版)
private function get_quark_share_token($share_url) {
    $parsed_url = parse_url($share_url);
    preg_match('/\/s\/([a-zA-Z0-9]+)/', $parsed_url['path'], $matches);
    $share_key = $matches[1] ?? '';
    
    $api_url = 'https://drive-pc.quark.cn/1/clouddrive/share/sharepage/token';
    
    $data = [
        'url' => $share_url,
        'passcode' => '',
        'force' => 0
    ];
    
    $response = $this->make_request('POST', $api_url, $data, $this->get_optimized_quark_headers());
    return $this->handle_api_response($response);
}

// 步骤2: 获取文件详情 (优化版)
private function get_quark_file_details($token_data) {
    $api_url = 'https://drive-pc.quark.cn/1/clouddrive/share/sharepage/detail';
    
    $data = [
        'pwd_id' => $token_data['pwd_id'],
        'stoken' => $token_data['stoken'],
        'pdir_fid' => '0',
        'force' => 0,
        '_page' => 1,
        '_size' => 50,
        '_sort' => 'file_type:asc,updated_at:desc'
    ];
    
    $response = $this->make_request('POST', $api_url, $data, $this->get_optimized_quark_headers());
    return $this->handle_api_response($response);
}

// 步骤3: 保存文件 (优化版)
private function save_quark_files_optimized($file_list, $token_data) {
    $api_url = 'https://drive-pc.quark.cn/1/clouddrive/share/sharepage/save';
    
    $fid_list = [];
    $fid_token_list = [];
    
    foreach ($file_list as $file) {
        $fid_list[] = $file['fid'];
        $fid_token_list[] = $file['share_fid_token'];
    }
    
    $data = [
        'fid_list' => $fid_list,
        'fid_token_list' => $fid_token_list,
        'to_pdir_fid' => '0',
        'pwd_id' => $token_data['pwd_id'],
        'stoken' => $token_data['stoken'],
        'pdir_fid' => '0',
        'scene' => 'link'
    ];
    
    $response = $this->make_request('POST', $api_url, $data, $this->get_optimized_quark_headers());
    return $this->handle_api_response($response);
}
```

## 3. 百度网盘API优化建议

### 3.1 改进请求处理

```php
private function get_optimized_baidu_headers() {
    return [
        'Cookie' => get_option('wp_disk_link_manager_baidu_cookie', ''),
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Referer' => 'https://pan.baidu.com/',
        'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
        'Accept' => 'application/json, text/javascript, */*; q=0.01',
        'X-Requested-With' => 'XMLHttpRequest'
    ];
}
```

## 4. 错误处理和重试机制

### 4.1 统一的HTTP请求方法

```php
private function make_request($method, $url, $data = null, $headers = []) {
    $args = [
        'method' => $method,
        'headers' => $headers,
        'timeout' => 30,
        'redirection' => 3
    ];
    
    if ($data && $method === 'POST') {
        if (isset($headers['Content-Type']) && strpos($headers['Content-Type'], 'application/json') !== false) {
            $args['body'] = json_encode($data);
        } else {
            $args['body'] = http_build_query($data);
        }
    }
    
    // 记录请求日志
    $this->logger->log(
        'api_request_start',
        null,
        get_current_user_id(),
        "API请求开始: {$method} {$url}",
        ['data' => $data]
    );
    
    $response = wp_remote_request($url, $args);
    
    // 记录响应日志
    $this->logger->log(
        'api_response',
        null,
        get_current_user_id(),
        "API响应: " . wp_remote_retrieve_response_code($response),
        [
            'url' => $url,
            'response_code' => wp_remote_retrieve_response_code($response),
            'response_body' => wp_remote_retrieve_body($response)
        ]
    );
    
    return $response;
}
```

### 4.2 智能重试机制

```php
private function retry_with_backoff($callback, $max_retries = 3) {
    $attempt = 0;
    $base_delay = 1; // 基础延迟1秒
    
    while ($attempt < $max_retries) {
        try {
            return $callback();
        } catch (Exception $e) {
            $attempt++;
            
            if ($attempt >= $max_retries) {
                throw new Exception("重试{$max_retries}次后仍失败: " . $e->getMessage());
            }
            
            // 指数退避算法
            $delay = $base_delay * pow(2, $attempt - 1);
            
            $this->logger->log(
                'api_retry',
                null,
                get_current_user_id(),
                "API请求重试 (第{$attempt}次，{$delay}秒后): " . $e->getMessage()
            );
            
            sleep($delay);
        }
    }
}
```

## 5. 频率控制优化

### 5.1 基于令牌桶的频率控制

```php
private function rate_limit_check($operation_type) {
    $limits = [
        'quark_api' => ['requests' => 10, 'window' => 60], // 10次/分钟
        'baidu_api' => ['requests' => 20, 'window' => 60], // 20次/分钟
    ];
    
    if (!isset($limits[$operation_type])) {
        return;
    }
    
    $limit = $limits[$operation_type];
    $key = "rate_limit_{$operation_type}_" . get_current_user_id();
    
    $current_time = time();
    $requests = get_transient($key) ?: [];
    
    // 清除过期的请求记录
    $requests = array_filter($requests, function($timestamp) use ($current_time, $limit) {
        return ($current_time - $timestamp) < $limit['window'];
    });
    
    if (count($requests) >= $limit['requests']) {
        throw new Exception("操作频率过高，请等待 " . ($limit['window'] - ($current_time - min($requests))) . " 秒后重试");
    }
    
    $requests[] = $current_time;
    set_transient($key, $requests, $limit['window']);
}
```

## 6. 异步处理优化

### 6.1 改进的任务队列

```php
public function queue_transfer_with_priority($post_id, $original_url, $disk_type, $priority = 'normal') {
    // 频率检查
    $this->rate_limit_check($disk_type . '_api');
    
    // 创建转存记录
    $transfer_id = $this->create_transfer_record($post_id, $original_url, $disk_type, $priority);
    
    // 根据优先级调度任务
    $delay = $priority === 'high' ? 1 : 5;
    wp_schedule_single_event(time() + $delay, 'process_disk_transfer', array($transfer_id));
    
    return $transfer_id;
}

// 改进的处理函数
public function process_disk_transfer_optimized($transfer_id) {
    try {
        $transfer = $this->get_transfer_record($transfer_id);
        
        // 更新状态为处理中
        $this->update_transfer_status($transfer_id, 'processing');
        
        // 使用重试机制处理转存
        $result = $this->retry_with_backoff(function() use ($transfer) {
            if ($transfer->disk_type === 'quark') {
                return $this->process_quark_transfer_optimized($transfer->original_url);
            } elseif ($transfer->disk_type === 'baidu') {
                return $this->process_baidu_transfer_optimized($transfer->original_url);
            } else {
                throw new Exception("不支持的网盘类型: " . $transfer->disk_type);
            }
        });
        
        // 更新完成状态
        $this->update_transfer_record($transfer_id, 'completed', $result['share_url']);
        
        // 发送成功通知
        $this->send_transfer_notification($transfer_id, 'success');
        
    } catch (Exception $e) {
        // 更新失败状态
        $this->update_transfer_record($transfer_id, 'failed', null, $e->getMessage());
        
        // 发送失败通知
        $this->send_transfer_notification($transfer_id, 'failed');
        
        // 记录详细错误
        $this->logger->log(
            'transfer_failed',
            $transfer->post_id ?? null,
            $transfer->user_id ?? get_current_user_id(),
            "转存失败: " . $e->getMessage(),
            [
                'transfer_id' => $transfer_id,
                'original_url' => $transfer->original_url ?? '',
                'disk_type' => $transfer->disk_type ?? ''
            ]
        );
    }
}
```

## 7. Cookie管理优化

### 7.1 自动Cookie验证和刷新

```php
public function validate_and_refresh_cookies() {
    $quark_cookie = get_option('wp_disk_link_manager_quark_cookie', '');
    $baidu_cookie = get_option('wp_disk_link_manager_baidu_cookie', '');
    
    $results = [];
    
    // 验证夸克Cookie
    if (!empty($quark_cookie)) {
        $results['quark'] = $this->validate_quark_cookie($quark_cookie);
        if (!$results['quark']) {
            $this->logger->log(
                'cookie_invalid',
                null,
                get_current_user_id(),
                '夸克网盘Cookie已失效'
            );
        }
    }
    
    // 验证百度Cookie
    if (!empty($baidu_cookie)) {
        $results['baidu'] = $this->validate_baidu_cookie($baidu_cookie);
        if (!$results['baidu']) {
            $this->logger->log(
                'cookie_invalid',
                null,
                get_current_user_id(),
                '百度网盘Cookie已失效'
            );
        }
    }
    
    return $results;
}

// 定期检查Cookie有效性
public function schedule_cookie_validation() {
    if (!wp_next_scheduled('validate_disk_cookies')) {
        wp_schedule_event(time(), 'hourly', 'validate_disk_cookies');
    }
}
```

## 8. 安全性增强

### 8.1 Cookie加密存储

```php
private function encrypt_sensitive_data($data) {
    if (!function_exists('openssl_encrypt')) {
        return base64_encode($data);
    }
    
    $key = wp_salt('auth') . wp_salt('secure_auth');
    $key = hash('sha256', $key, true);
    $iv = openssl_random_pseudo_bytes(16);
    
    $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
    return base64_encode($iv . $encrypted);
}

private function decrypt_sensitive_data($encrypted_data) {
    if (!function_exists('openssl_decrypt')) {
        return base64_decode($encrypted_data);
    }
    
    $data = base64_decode($encrypted_data);
    $iv = substr($data, 0, 16);
    $encrypted = substr($data, 16);
    
    $key = wp_salt('auth') . wp_salt('secure_auth');
    $key = hash('sha256', $key, true);
    
    return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
}
```

## 9. 监控和诊断

### 9.1 性能监控

```php
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
```

## 10. 实施建议

### 10.1 分阶段实施
1. **第一阶段**: 实施错误处理和重试机制优化
2. **第二阶段**: 添加频率控制和性能监控
3. **第三阶段**: 实施安全性增强和Cookie管理优化
4. **第四阶段**: 完善异步处理和用户体验

### 10.2 测试策略
- 单元测试：测试各个API方法
- 集成测试：测试完整的转存流程
- 压力测试：测试高并发情况下的性能
- 安全测试：验证数据加密和权限控制

### 10.3 监控指标
- API请求成功率
- 平均响应时间
- 错误率和重试次数
- 用户满意度

## 结论

通过以上优化方案，可以显著提升WP Disk Link Manager插件的稳定性、性能和用户体验。建议按照分阶段实施的方式逐步应用这些优化。