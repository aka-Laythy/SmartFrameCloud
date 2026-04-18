<?php
/**
 * 图片处理API - 生成BMP
 * 
 * 支持参数：
 *   ?image_id=1                    通过图片ID查询数据库
 *   ?file=2026/04/xxxxxxx.png      通过 uploads 目录下的相对路径
 *   ?source=/abs/path/to/img.jpg   通过服务器绝对路径
 */

require_once __DIR__ . '/../../config/database.php';

/**
 * 处理图片生成BMP（7色有序抖动，800x480）
 * 规则：cloud/uploads/yyyy/mm/xxx.png  =>  cloud/cache/yyyy/mm/xxx.bmp
 * 
 * @param string $source_path 源图片绝对路径
 * @return array ['success' => bool, 'bmp_file' => string, 'message' => string]
 */
function process_image_to_bmp($source_path) {
    if (!extension_loaded('gd')) {
        return ['success' => false, 'bmp_file' => '', 'message' => 'PHP 未启用 GD 库'];
    }
    
    if (!file_exists($source_path) || !is_readable($source_path)) {
        return ['success' => false, 'bmp_file' => '', 'message' => '源文件不存在或不可读: ' . $source_path];
    }
    
    // 计算相对于 uploads 的路径
    $uploads_base = rtrim(str_replace('\\', '/', UPLOAD_PATH), '/');
    $source_norm = str_replace('\\', '/', realpath($source_path));
    
    if ($source_norm && strpos($source_norm, $uploads_base . '/') === 0) {
        $relative_path = substr($source_norm, strlen($uploads_base) + 1);
    } else {
        $relative_path = basename($source_path);
    }
    
    $bmp_name = pathinfo($relative_path, PATHINFO_FILENAME) . '.bmp';
    $relative_dir = dirname($relative_path);
    if ($relative_dir === '.' || $relative_dir === '/') {
        $relative_dir = '';
    }
    
    // cache_base 指向 /cloud/cache/ （imgprocess.php 在 backend/api/imgprocess/，向上3级到 cloud/）
    $cache_base = rtrim(str_replace('\\', '/', dirname(__DIR__, 3) . '/cache/'), '/');
    $cache_dir = $cache_base . ($relative_dir ? '/' . $relative_dir : '');
    $cache_file = $cache_dir . '/' . $bmp_name;
    
    // 检查并修复异常缓存（文件太小或源文件已更新）
    if (file_exists($cache_file)) {
        $cache_size = @filesize($cache_file);
        $src_mtime = @filemtime($source_path);
        $cache_mtime = @filemtime($cache_file);
        if ($cache_size === false || $cache_size < 54 || ($src_mtime && $cache_mtime && $cache_mtime < $src_mtime)) {
            @unlink($cache_file);
        }
    }
    
    // 需要生成新缓存
    if (!file_exists($cache_file)) {
        if (!file_exists($cache_dir)) {
            $mkdir_ok = @mkdir($cache_dir, 0755, true);
            if (!$mkdir_ok && !is_dir($cache_dir)) {
                return ['success' => false, 'bmp_file' => '', 'message' => '无法创建缓存目录，请检查权限或 open_basedir 限制: ' . $cache_dir];
            }
        }
        
        $result = generate_bmp($source_path, $cache_file, 800, 480);
        if (!$result) {
            return ['success' => false, 'bmp_file' => '', 'message' => 'BMP 生成失败（GD处理异常）'];
        }
    }
    
    // 双重校验：文件必须存在且大小合法
    if (!file_exists($cache_file) || filesize($cache_file) < 54) {
        @unlink($cache_file);
        return ['success' => false, 'bmp_file' => '', 'message' => '缓存文件校验失败，请重试'];
    }
    
    $cache_file_real = realpath($cache_file);
    if (!$cache_file_real) {
        return ['success' => false, 'bmp_file' => '', 'message' => '无法获取缓存文件真实路径'];
    }
    
    $bmp_relative = ltrim(substr(str_replace('\\', '/', $cache_file_real), strlen($cache_base) + 1), '/');
    
    return ['success' => true, 'bmp_file' => $bmp_relative, 'message' => '生成成功'];
}

/**
 * 生成 24位 BMP V3（7色有序抖动）
 */
function generate_bmp($input, $output, $width, $height) {
    $info = @getimagesize($input);
    if (!$info) return false;
    
    switch($info[2]) {
        case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($input); break;
        case IMAGETYPE_PNG: $src = @imagecreatefrompng($input); break;
        default: return false;
    }
    
    if (!$src) return false;
    
    $dst = imagecreatetruecolor($width, $height);
    if (!$dst) {
        imagedestroy($src);
        return false;
    }
    
    // 等比缩放+居中裁剪
    $src_w = imagesx($src);
    $src_h = imagesy($src);
    $src_ratio = $src_w / $src_h;
    $dst_ratio = $width / $height;
    
    if ($src_ratio > $dst_ratio) {
        $crop_h = $src_h;
        $crop_w = intval($src_h * $dst_ratio);
        $src_x = intval(($src_w - $crop_w) / 2);
        $src_y = 0;
    } else {
        $crop_w = $src_w;
        $crop_h = intval($src_w / $dst_ratio);
        $src_x = 0;
        $src_y = intval(($src_h - $crop_h) / 2);
    }
    
    imagecopyresampled($dst, $src, 0, 0, $src_x, $src_y, $width, $height, $crop_w, $crop_h);
    imagedestroy($src);
    
    apply_ordered_dither($dst, $width, $height);
    
    $result = write_bmp_v3($dst, $output, $width, $height);
    imagedestroy($dst);
    
    return $result;
}

/**
 * 有序抖动（Ordered Dithering）- 内存友好型
 */
function apply_ordered_dither($img, $width, $height) {
    $palette = [
        [0, 0, 0],       // 0: 黑
        [255, 255, 255], // 1: 白
        [255, 0, 0],     // 2: 红
        [255, 255, 0],   // 3: 黄
        [0, 0, 255],     // 4: 蓝
        [0, 255, 0],     // 5: 绿
        [255, 165, 0]    // 6: 橙
    ];
    
    $bayer = [
        [  0, 128,  32, 160],
        [192,  64, 224,  96],
        [ 48, 176,  16, 144],
        [240, 112, 208,  80]
    ];
    
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $rgb = imagecolorat($img, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            
            $threshold = $bayer[$y % 4][$x % 4];
            $r_dither = min(255, $r + $threshold - 128);
            $g_dither = min(255, $g + $threshold - 128);
            $b_dither = min(255, $b + $threshold - 128);
            
            $nearest = find_nearest_color($r_dither, $g_dither, $b_dither, $palette);
            $color = imagecolorallocate($img, 
                $palette[$nearest][0], 
                $palette[$nearest][1], 
                $palette[$nearest][2]
            );
            imagesetpixel($img, $x, $y, $color);
        }
    }
}

function find_nearest_color($r, $g, $b, $palette) {
    $min_dist = 999999;
    $nearest = 0;
    foreach ($palette as $i => $color) {
        $dist = ($r - $color[0])**2 + ($g - $color[1])**2 + ($b - $color[2])**2;
        if ($dist < $min_dist) {
            $min_dist = $dist;
            $nearest = $i;
        }
    }
    return $nearest;
}

/**
 * 写入标准 BMP V3（24位，无压缩，54字节头）
 */
function write_bmp_v3($img, $filename, $width, $height) {
    $row_size = intval((24 * $width + 31) / 32) * 4;
    $pixel_size = $row_size * $height;
    $file_size = 54 + $pixel_size;
    
    $fp = fopen($filename, 'wb');
    if (!$fp) return false;
    
    fwrite($fp, 'BM');
    fwrite($fp, pack('V', $file_size));
    fwrite($fp, pack('v', 0));
    fwrite($fp, pack('v', 0));
    fwrite($fp, pack('V', 54));
    
    fwrite($fp, pack('V', 40));
    fwrite($fp, pack('V', $width));
    fwrite($fp, pack('V', $height));
    fwrite($fp, pack('v', 1));
    fwrite($fp, pack('v', 24));
    fwrite($fp, pack('V', 0));
    fwrite($fp, pack('V', $pixel_size));
    fwrite($fp, pack('V', 2835));
    fwrite($fp, pack('V', 2835));
    fwrite($fp, pack('V', 0));
    fwrite($fp, pack('V', 0));
    
    for ($y = $height - 1; $y >= 0; $y--) {
        $row = '';
        for ($x = 0; $x < $width; $x++) {
            $rgb = imagecolorat($img, $x, $y);
            $row .= chr($rgb & 0xFF);
            $row .= chr(($rgb >> 8) & 0xFF);
            $row .= chr(($rgb >> 16) & 0xFF);
        }
        $padding = $row_size - ($width * 3);
        if ($padding > 0) $row .= str_repeat("\x00", $padding);
        fwrite($fp, $row);
    }
    
    fclose($fp);
    
    // 确保文件确实写入磁盘
    clearstatcache(true, $filename);
    return file_exists($filename) && filesize($filename) >= 54;
}

// ===== API 入口 =====
if (basename($_SERVER['SCRIPT_NAME']) === 'imgprocess.php') {
    header('Content-Type: application/json; charset=utf-8');
    
    $source = '';
    $imageId = intval($_GET['image_id'] ?? ($_POST['image_id'] ?? 0));
    $file = $_GET['file'] ?? ($_POST['file'] ?? '');       // uploads相对路径
    $absSource = $_GET['source'] ?? ($_POST['source'] ?? ''); // 绝对路径
    
    // 1. 优先使用绝对路径
    if (!empty($absSource) && file_exists($absSource)) {
        $source = $absSource;
    }
    // 2. 其次使用相对 uploads 的路径，如 ?file=2026/04/napkin.png
    elseif (!empty($file)) {
        $source = UPLOAD_PATH . ltrim($file, '/');
    }
    // 3. 最后通过 image_id 查数据库
    elseif ($imageId > 0) {
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT file_path FROM images WHERE id = ?");
            $stmt->execute([$imageId]);
            $img = $stmt->fetch();
            if ($img) {
                $source = UPLOAD_PATH . $img['file_path'];
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }
    
    if (empty($source) || !file_exists($source)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '源文件不存在: ' . ($source ?: '空')]);
        exit;
    }
    
    $result = process_image_to_bmp($source);
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'bmp_file' => $result['bmp_file'],
            'bmp_url' => 'http://47.108.232.40:2026/cloud/imget.php?file=' . urlencode($result['bmp_file']),
            'message' => $result['message']
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $result['message']]);
    }
    exit;
}
