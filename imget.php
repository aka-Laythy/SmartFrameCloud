<?php
/**
 * BMP 图片获取接口
 * 访问：http://47.108.232.40:2026/imget.php?file=2026/04/xxx.bmp
 * 
 * 部署说明：此文件应放在网站根目录（和 /cloud/ 同级）
 * 例如：/www/wwwroot/47.108.232.40_2026/imget.php
 */

// 自动探测缓存目录位置（兼容 imget.php 放在 website root 或 /cloud/ 内的情况）
$possible_cache_paths = [
    __DIR__ . '/cloud/cache/',   // 当 imget.php 在 website root 时
    __DIR__ . '/cache/',          // 当 imget.php 在 /cloud/ 内时
];

$cache_dir = null;
foreach ($possible_cache_paths as $path) {
    if (is_dir($path)) {
        $cache_dir = $path;
        break;
    }
}

// 如果都没有，默认用 website root 下的 cloud/cache
if (!$cache_dir) {
    $cache_dir = __DIR__ . '/cloud/cache/';
}

$file = isset($_GET['file']) ? $_GET['file'] : '';
if (empty($file)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    die('缺少 file 参数');
}

// 安全校验：防止目录遍历，但保留子目录结构
$file = str_replace(['..', '\\'], ['', '/'], $file);
$file = ltrim($file, '/');

$cache_dir_real = realpath($cache_dir);
if (!$cache_dir_real) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    die('缓存目录不存在: ' . $cache_dir);
}

$target_path = $cache_dir_real . '/' . $file;

// Linux 上 realpath 对不存在的文件返回 false，需要特殊处理
$target_real = realpath(dirname($target_path));
if ($target_real === false) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    die('文件不存在');
}

// 安全检查：确保在缓存目录内
if (strpos(str_replace('\\', '/', $target_real), str_replace('\\', '/', $cache_dir_real)) !== 0) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    die('非法路径');
}

if (!file_exists($target_path) || !is_readable($target_path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    die('文件不存在');
}

$ext = strtolower(pathinfo($target_path, PATHINFO_EXTENSION));
if ($ext !== 'bmp') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    die('仅支持 bmp 文件');
}

$file_size = filesize($target_path);
if ($file_size < 54) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    die('文件异常');
}

// 清理缓冲区，确保二进制输出干净
while (ob_get_level()) ob_end_clean();

header('Content-Type: image/bmp');
header('Content-Length: ' . $file_size);
header('Cache-Control: public, max-age=3600');

readfile($target_path);
exit;
