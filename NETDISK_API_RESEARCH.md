# 网盘API控制研究文档

## 概述

基于对现有网盘转存项目的研究，总结夸克网盘和百度网盘的API控制方式。

## 1. 夸克网盘API控制方式

### 1.1 基础认证

夸克网盘主要使用Cookie认证方式：

```php
// 夸克网盘Cookie配置
$quark_cookie = get_option('wp_disk_link_manager_quark_cookie', '');

// 请求头配置
$headers = [
    'Cookie' => $quark_cookie,
    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    'Referer' => 'https://pan.quark.cn/',
    'Content-Type' => 'application/json',
    'Accept' => 'application/json, text/plain, */*'
];
```

### 1.2 文件保存API

夸克网盘的文件保存通常分为三个步骤：

```php
// 步骤1: 获取分享信息
public function get_quark_share_info($share_url) {
    $api_url = 'https://drive-pc.quark.cn/1/clouddrive/share/sharepage/token';
    
    $data = [
        'url' => $share_url,
        'passcode' => '', // 提取码
        'force' => 0
    ];
    
    $response = wp_remote_post($api_url, [
        'headers' => $this->get_quark_headers(),
        'body' => json_encode($data),
        'timeout' => 30
    ]);
    
    return json_decode(wp_remote_retrieve_body($response), true);
}

// 步骤2: 获取文件列表
public function get_quark_file_list($share_info) {
    $api_url = 'https://drive-pc.quark.cn/1/clouddrive/share/sharepage/detail';
    
    $data = [
        'pwd_id' => $share_info['pwd_id'],
        'stoken' => $share_info['stoken'],
        'pdir_fid' => '0',
        'force' => 0,
        '_page' => 1,
        '_size' => 50,
        '_sort' => 'file_type:asc,updated_at:desc'
    ];
    
    $response = wp_remote_post($api_url, [
        'headers' => $this->get_quark_headers(),
        'body' => json_encode($data),
        'timeout' => 30
    ]);
    
    return json_decode(wp_remote_retrieve_body($response), true);
}

// 步骤3: 保存文件到我的网盘
public function save_quark_files($file_list, $share_info) {
    $api_url = 'https://drive-pc.quark.cn/1/clouddrive/share/sharepage/save';
    
    $data = [
        'fid_list' => array_column($file_list, 'fid'),
        'fid_token_list' => array_column($file_list, 'share_fid_token'),
        'to_pdir_fid' => '0', // 保存到根目录
        'pwd_id' => $share_info['pwd_id'],
        'stoken' => $share_info['stoken'],
        'pdir_fid' => '0',
        'scene' => 'link'
    ];
    
    $response = wp_remote_post($api_url, [
        'headers' => $this->get_quark_headers(),
        'body' => json_encode($data),
        'timeout' => 30
    ]);
    
    return json_decode(wp_remote_retrieve_body($response), true);
}
```

### 1.3 分享链接创建

保存文件后创建新的分享链接：

```php
public function create_quark_share_link($file_ids) {
    $api_url = 'https://drive-pc.quark.cn/1/clouddrive/share';
    
    $data = [
        'fid_list' => $file_ids,
        'title' => '分享文件',
        'url_type' => 1,
        'expired_type' => 1, // 1天有效期
        'passcode' => ''
    ];
    
    $response = wp_remote_post($api_url, [
        'headers' => $this->get_quark_headers(),
        'body' => json_encode($data),
        'timeout' => 30
    ]);
    
    return json_decode(wp_remote_retrieve_body($response), true);
}
```

## 2. 百度网盘API控制方式

### 2.1 基础认证

百度网盘支持多种认证方式：

```php
// 方式1: Cookie认证
$baidu_cookie = get_option('wp_disk_link_manager_baidu_cookie', '');

// 方式2: Access Token认证
$access_token = get_option('wp_disk_link_manager_baidu_access_token', '');

// 请求头配置
$headers = [
    'Cookie' => $baidu_cookie,
    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    'Referer' => 'https://pan.baidu.com/',
    'Content-Type' => 'application/x-www-form-urlencoded'
];
```

### 2.2 文件转存API

百度网盘的转存流程：

```php
// 步骤1: 解析分享链接
public function parse_baidu_share_url($share_url) {
    preg_match('/\/s\/([a-zA-Z0-9_-]+)/', $share_url, $matches);
    $shareid = $matches[1] ?? '';
    
    // 获取分享页面信息
    $response = wp_remote_get($share_url, [
        'headers' => $this->get_baidu_headers(),
        'timeout' => 30
    ]);
    
    $body = wp_remote_retrieve_body($response);
    
    // 提取必要参数
    preg_match('/yunData.SHARE_ID = "(\d+)"/', $body, $share_id_matches);
    preg_match('/yunData.SHARE_UK = "(\d+)"/', $body, $share_uk_matches);
    
    return [
        'shareid' => $share_id_matches[1] ?? '',
        'uk' => $share_uk_matches[1] ?? '',
        'surl' => $shareid
    ];
}

// 步骤2: 获取文件列表
public function get_baidu_file_list($share_info, $pwd = '') {
    $api_url = 'https://pan.baidu.com/share/list';
    
    $data = [
        'shareid' => $share_info['shareid'],
        'uk' => $share_info['uk'],
        'dir' => '/',
        'pwd' => $pwd,
        'page' => 1,
        'num' => 100,
        'order' => 'time',
        'desc' => 1
    ];
    
    $response = wp_remote_post($api_url, [
        'headers' => $this->get_baidu_headers(),
        'body' => http_build_query($data),
        'timeout' => 30
    ]);
    
    return json_decode(wp_remote_retrieve_body($response), true);
}

// 步骤3: 转存文件
public function save_baidu_files($file_list, $share_info) {
    $api_url = 'https://pan.baidu.com/share/transfer';
    
    $data = [
        'shareid' => $share_info['shareid'],
        'uk' => $share_info['uk'],
        'filelist' => json_encode($file_list),
        'path' => '/apps/转存文件', // 保存路径
        'ondup' => 'newcopy' // 重名处理
    ];
    
    $response = wp_remote_post($api_url, [
        'headers' => $this->get_baidu_headers(),
        'body' => http_build_query($data),
        'timeout' => 30
    ]);
    
    return json_decode(wp_remote_retrieve_body($response), true);
}
```

### 2.3 分享链接创建

```php
public function create_baidu_share_link($file_paths) {
    $api_url = 'https://pan.baidu.com/share/set';
    
    $data = [
        'fid_list' => json_encode($file_paths),
        'schannel' => 4,
        'channel_list' => '[]',
        'period' => 1 // 1天有效期
    ];
    
    $response = wp_remote_post($api_url, [
        'headers' => $this->get_baidu_headers(),
        'body' => http_build_query($data),
        'timeout' => 30
    ]);
    
    return json_decode(wp_remote_retrieve_body($response), true);
}
```

## 3. 错误处理和重试机制

### 3.1 通用错误处理

```php
public function handle_api_error($response, $context = '') {
    if (is_wp_error($response)) {
        throw new Exception("网络请求失败: " . $response->get_error_message());
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code !== 200) {
        throw new Exception("HTTP请求失败，状态码: " . $status_code);
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("响应数据解析失败");
    }
    
    return $data;
}
```

### 3.2 重试机制

```php
public function retry_request($callback, $max_retries = 3, $delay = 1) {
    $attempt = 0;
    
    while ($attempt < $max_retries) {
        try {
            return $callback();
        } catch (Exception $e) {
            $attempt++;
            
            if ($attempt >= $max_retries) {
                throw $e;
            }
            
            // 指数退避
            sleep($delay * pow(2, $attempt - 1));
            
            // 记录重试日志
            $this->logger->log(
                'api_retry',
                null,
                get_current_user_id(),
                "API请求重试 (第{$attempt}次): " . $e->getMessage()
            );
        }
    }
}
```

## 4. 频率控制

```php
public function rate_limit($key, $max_requests = 10, $time_window = 60) {
    $current_time = time();
    $requests = get_transient("rate_limit_$key") ?: [];
    
    // 清除过期请求
    $requests = array_filter($requests, function($timestamp) use ($current_time, $time_window) {
        return ($current_time - $timestamp) < $time_window;
    });
    
    if (count($requests) >= $max_requests) {
        throw new Exception("请求频率过高，请稍后再试");
    }
    
    $requests[] = $current_time;
    set_transient("rate_limit_$key", $requests, $time_window);
}
```

## 5. Cookie管理

### 5.1 Cookie验证

```php
public function validate_quark_cookie($cookie) {
    $test_url = 'https://drive-pc.quark.cn/1/clouddrive/capacity';
    
    $response = wp_remote_get($test_url, [
        'headers' => [
            'Cookie' => $cookie,
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ],
        'timeout' => 15
    ]);
    
    if (is_wp_error($response)) {
        return false;
    }
    
    $data = json_decode(wp_remote_retrieve_body($response), true);
    return isset($data['data']) && !empty($data['data']);
}

public function validate_baidu_cookie($cookie) {
    $test_url = 'https://pan.baidu.com/api/quota';
    
    $response = wp_remote_get($test_url, [
        'headers' => [
            'Cookie' => $cookie,
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ],
        'timeout' => 15
    ]);
    
    if (is_wp_error($response)) {
        return false;
    }
    
    $data = json_decode(wp_remote_retrieve_body($response), true);
    return isset($data['errno']) && $data['errno'] === 0;
}
```

## 6. 异步处理

### 6.1 异步任务队列

```php
public function queue_transfer_task($post_id, $original_url, $disk_type) {
    // 创建转存记录
    $transfer_id = $this->create_transfer_record($post_id, $original_url, $disk_type);
    
    // 添加到WordPress cron队列
    wp_schedule_single_event(time() + 5, 'process_disk_transfer', array($transfer_id));
    
    return $transfer_id;
}

// Cron处理函数
public function process_disk_transfer($transfer_id) {
    try {
        $transfer = $this->get_transfer_record($transfer_id);
        
        if ($transfer->disk_type === 'quark') {
            $result = $this->transfer_quark_file($transfer->original_url);
        } elseif ($transfer->disk_type === 'baidu') {
            $result = $this->transfer_baidu_file($transfer->original_url);
        }
        
        // 更新转存记录
        $this->update_transfer_record($transfer_id, 'completed', $result['share_url']);
        
    } catch (Exception $e) {
        $this->update_transfer_record($transfer_id, 'failed', null, $e->getMessage());
    }
}
```

## 7. 安全考虑

### 7.1 数据加密

```php
public function encrypt_cookie($cookie) {
    if (!function_exists('openssl_encrypt')) {
        return base64_encode($cookie);
    }
    
    $key = wp_salt('auth');
    $iv = openssl_random_pseudo_bytes(16);
    $encrypted = openssl_encrypt($cookie, 'AES-256-CBC', $key, 0, $iv);
    
    return base64_encode($iv . $encrypted);
}

public function decrypt_cookie($encrypted_cookie) {
    if (!function_exists('openssl_decrypt')) {
        return base64_decode($encrypted_cookie);
    }
    
    $data = base64_decode($encrypted_cookie);
    $iv = substr($data, 0, 16);
    $encrypted = substr($data, 16);
    
    $key = wp_salt('auth');
    return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
}
```

## 8. 监控和日志

### 8.1 详细日志记录

```php
public function log_api_request($method, $url, $data, $response) {
    $log_data = [
        'method' => $method,
        'url' => $url,
        'request_data' => $data,
        'response_code' => wp_remote_retrieve_response_code($response),
        'response_body' => wp_remote_retrieve_body($response),
        'timestamp' => current_time('mysql')
    ];
    
    $this->logger->log(
        'api_request',
        null,
        get_current_user_id(),
        'API请求详情',
        $log_data
    );
}
```

## 总结

以上研究总结了夸克网盘和百度网盘的API控制方式，包括：

1. **认证机制**: Cookie和Token两种方式
2. **文件操作**: 分享解析、文件列表获取、文件保存、分享创建
3. **错误处理**: 统一的错误处理和重试机制
4. **频率控制**: 避免API调用频率过高
5. **安全性**: Cookie加密存储和传输
6. **异步处理**: 提高用户体验的异步任务队列
7. **监控日志**: 详细的API请求和响应日志

这些方法可以作为优化当前插件网盘操作功能的参考。