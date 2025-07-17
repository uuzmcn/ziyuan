<?php
echo "修复WP Disk Link Manager插件的致命错误...\n";

// 要检查和修复的文件列表
$files_to_check = [
    'includes/class-wp-disk-link-manager.php',
    'includes/class-disk-manager.php', 
    'includes/class-admin.php',
    'includes/class-ajax.php',
    'includes/class-cron.php',
    'includes/class-excel-importer.php',
    'includes/class-logger.php'
];

foreach ($files_to_check as $file) {
    if (!file_exists($file)) {
        echo "错误: 文件不存在 $file\n";
        continue;
    }
    
    $content = file_get_contents($file);
    $original_content = $content;
    $changes = 0;
    
    // 修复常见的语法问题
    
    // 1. 确保所有类都有正确的PHP开始标签
    if (!preg_match('/^<\?php/', $content)) {
        $content = '<?php' . "\n" . $content;
        $changes++;
    }
    
    // 2. 修复可能的末尾问题
    $content = rtrim($content);
    if (!preg_match('/\}$/', $content)) {
        // 如果文件不以}结尾，可能缺少类的结束大括号
        echo "警告: $file 可能缺少结束大括号\n";
    }
    
    // 3. 检查是否有多余的PHP结束标签
    $content = preg_replace('/\?>\s*$/', '', $content);
    
    // 4. 确保没有BOM
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    
    if ($content !== $original_content) {
        file_put_contents($file, $content);
        echo "修复了 $file (应用了 $changes 个修复)\n";
    } else {
        echo "检查 $file ... OK\n";
    }
}

echo "\n检查WordPress兼容性问题...\n";

// 检查可能的WordPress函数调用问题
$wp_functions_to_check = [
    'wp_enqueue_script',
    'wp_enqueue_style', 
    'wp_localize_script',
    'add_action',
    'add_filter',
    'get_current_user_id',
    'current_user_can',
    'wp_create_nonce',
    'check_ajax_referer'
];

foreach ($files_to_check as $file) {
    if (!file_exists($file)) continue;
    
    $content = file_get_contents($file);
    
    foreach ($wp_functions_to_check as $func) {
        if (strpos($content, $func) !== false && strpos($content, "function_exists('$func')") === false) {
            // 这个文件使用了WordPress函数，确保在WordPress环境中运行
            if (strpos($content, "if (!defined('ABSPATH'))") === false) {
                echo "建议在 $file 开头添加 ABSPATH 检查\n";
                break;
            }
        }
    }
}

echo "\n修复完成!\n";

// 创建一个简单的测试文件
$test_content = '<?php
// 简单的插件测试
if (!defined("ABSPATH")) {
    exit;
}

// 测试类加载
$test_classes = [
    "WP_Disk_Link_Manager",
    "WP_Disk_Link_Manager_Logger", 
    "WP_Disk_Link_Manager_Disk_Manager",
    "WP_Disk_Link_Manager_Admin",
    "WP_Disk_Link_Manager_Ajax",
    "WP_Disk_Link_Manager_Cron",
    "WP_Disk_Link_Manager_Excel_Importer"
];

foreach ($test_classes as $class) {
    if (!class_exists($class)) {
        echo "错误: 类 $class 不存在\\n";
    } else {
        echo "OK: 类 $class 已加载\\n";
    }
}
?>';

file_put_contents('test-classes.php', $test_content);
echo "创建了测试文件 test-classes.php\n";
?>