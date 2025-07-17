<?php
// 简单的语法检查脚本

$files = [
    'wp-disk-link-manager.php',
    'includes/class-wp-disk-link-manager.php',
    'includes/class-admin.php',
    'includes/class-ajax.php',
    'includes/class-disk-manager.php',
    'includes/class-logger.php',
    'includes/class-cron.php',
    'includes/class-excel-importer.php'
];

echo "检查PHP语法错误...\n";

$errors = [];

foreach ($files as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        
        // 检查基本的语法问题
        if (substr_count($content, '{') !== substr_count($content, '}')) {
            $errors[] = "$file: 大括号不匹配";
        }
        
        if (substr_count($content, '(') !== substr_count($content, ')')) {
            $errors[] = "$file: 小括号不匹配";
        }
        
        // 检查PHP标签
        if (!preg_match('/^<\?php/', $content)) {
            $errors[] = "$file: 缺少PHP开始标签";
        }
        
        echo "检查 $file ... ";
        if (empty($errors)) {
            echo "OK\n";
        } else {
            echo "有问题\n";
        }
    } else {
        echo "文件不存在: $file\n";
    }
}

if (!empty($errors)) {
    echo "\n发现的错误:\n";
    foreach ($errors as $error) {
        echo "- $error\n";
    }
} else {
    echo "\n所有文件语法检查通过!\n";
}
?>