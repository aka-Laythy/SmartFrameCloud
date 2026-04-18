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
 * 处理图片生成BMP（7色有序抖动）
 * 规则：cloud/uploads/yyyy/mm/xxx.png  =>  cloud/cache/yyyy/mm/xxx.bmp
 * 
 * @param string $source_path 源图片绝对路径
 * @param array $options 渲染参数
 * @return array ['success' => bool, 'bmp_file' => string, 'message' => string]
 */
function process_image_to_bmp($source_path, $options = []) {
    if (!extension_loaded('gd')) {
        return ['success' => false, 'bmp_file' => '', 'message' => 'PHP 未启用 GD 库'];
    }
    
    if (!file_exists($source_path) || !is_readable($source_path)) {
        return ['success' => false, 'bmp_file' => '', 'message' => '源文件不存在或不可读: ' . $source_path];
    }
    
    // 计算相对于 uploads 的路径
    $uploads_base = getNormalizedUploadsBase();
    $source_norm = str_replace('\\', '/', realpath($source_path));
    
    if ($source_norm && strpos($source_norm, $uploads_base . '/') === 0) {
        $relative_path = substr($source_norm, strlen($uploads_base) + 1);
    } else {
        $relative_path = basename($source_path);
    }
    
    $normalizedOptions = normalizeBmpOptions($options);
    $cacheSignature = substr(md5(json_encode($normalizedOptions)), 0, 12);
    $bmp_name = pathinfo($relative_path, PATHINFO_FILENAME) . '__' . $cacheSignature . '.bmp';
    $relative_dir = dirname($relative_path);
    if ($relative_dir === '.' || $relative_dir === '/') {
        $relative_dir = '';
    }
    
    // cache_base 指向 /cloud/cache/ （imgprocess.php 在 backend/api/imgprocess/，向上3级到 cloud/）
    $cache_base = getNormalizedCacheBase();
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
        
        $result = generate_bmp($source_path, $cache_file, $normalizedOptions);
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
    
    return [
        'success' => true,
        'bmp_file' => $bmp_relative,
        'message' => '生成成功',
        'target_width' => $normalizedOptions['target_width'],
        'target_height' => $normalizedOptions['target_height']
    ];
}

function getNormalizedUploadsBase() {
    static $uploadsBase = null;

    if ($uploadsBase !== null) {
        return $uploadsBase;
    }

    $resolved = realpath(UPLOAD_PATH);
    if ($resolved !== false) {
        $uploadsBase = rtrim(str_replace('\\', '/', $resolved), '/');
    } else {
        $uploadsBase = rtrim(str_replace('\\', '/', UPLOAD_PATH), '/');
    }

    return $uploadsBase;
}

function getNormalizedCacheBase() {
    static $cacheBase = null;

    if ($cacheBase !== null) {
        return $cacheBase;
    }

    $rawPath = dirname(__DIR__, 3) . '/cache/';
    $resolved = realpath($rawPath);
    if ($resolved !== false) {
        $cacheBase = rtrim(str_replace('\\', '/', $resolved), '/');
    } else {
        $cacheBase = rtrim(str_replace('\\', '/', $rawPath), '/');
    }

    return $cacheBase;
}

function normalizeBmpOptions($options = []) {
    if (function_exists('normalizeBmpRenderOptions')) {
        return normalizeBmpRenderOptions($options);
    }

    $orientation = ($options['orientation'] ?? 'landscape') === 'portrait' ? 'portrait' : 'landscape';

    $rotate = intval($options['rotate'] ?? 0);
    if ($rotate !== 90) {
        $rotate = 0;
    }

    $cropCenterX = isset($options['crop_center_x']) ? floatval($options['crop_center_x']) : 0.5;
    $cropCenterY = isset($options['crop_center_y']) ? floatval($options['crop_center_y']) : 0.5;
    $cropZoom = isset($options['crop_zoom']) ? floatval($options['crop_zoom']) : 1.0;

    return array(
        'orientation' => $orientation,
        'rotate' => $rotate,
        'crop_center_x' => max(0.0, min(1.0, $cropCenterX)),
        'crop_center_y' => max(0.0, min(1.0, $cropCenterY)),
        'crop_zoom' => max(1.0, min(4.0, $cropZoom)),
        'target_width' => $orientation === 'portrait' ? 480 : 800,
        'target_height' => $orientation === 'portrait' ? 800 : 480
    );
}

function load_source_image($input, $imageType) {
    $image = false;

    switch ($imageType) {
        case IMAGETYPE_JPEG:
            $image = @imagecreatefromjpeg($input);
            break;
        case IMAGETYPE_PNG:
            $image = @imagecreatefrompng($input);
            break;
        case IMAGETYPE_GIF:
            $image = @imagecreatefromgif($input);
            break;
        case IMAGETYPE_WEBP:
            $image = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($input) : false;
            break;
    }

    if ($image !== false) {
        return $image;
    }

    $raw = @file_get_contents($input);
    if ($raw === false) {
        return false;
    }

    return @imagecreatefromstring($raw);
}

function create_oriented_source($src, $rotate) {
    if ($rotate === 90) {
        $rotated = @imagerotate($src, -90, 0);
        if ($rotated !== false) {
            imagedestroy($src);
            return $rotated;
        }

        error_log('create_oriented_source failed when rotate=90');
    }

    return $src;
}

function calculate_crop_rect($srcWidth, $srcHeight, $targetWidth, $targetHeight, $zoom, $centerX, $centerY) {
    $srcRatio = $srcWidth / $srcHeight;
    $targetRatio = $targetWidth / $targetHeight;

    if ($srcRatio > $targetRatio) {
        $baseCropHeight = $srcHeight;
        $baseCropWidth = $srcHeight * $targetRatio;
    } else {
        $baseCropWidth = $srcWidth;
        $baseCropHeight = $srcWidth / $targetRatio;
    }

    $cropWidth = max(1.0, $baseCropWidth / $zoom);
    $cropHeight = max(1.0, $baseCropHeight / $zoom);

    $centerPixelX = $centerX * $srcWidth;
    $centerPixelY = $centerY * $srcHeight;

    $halfCropWidth = $cropWidth / 2;
    $halfCropHeight = $cropHeight / 2;

    $minCenterX = $halfCropWidth;
    $maxCenterX = $srcWidth - $halfCropWidth;
    $minCenterY = $halfCropHeight;
    $maxCenterY = $srcHeight - $halfCropHeight;

    if ($maxCenterX < $minCenterX) {
        $centerPixelX = $srcWidth / 2;
    } else {
        $centerPixelX = min(max($centerPixelX, $minCenterX), $maxCenterX);
    }

    if ($maxCenterY < $minCenterY) {
        $centerPixelY = $srcHeight / 2;
    } else {
        $centerPixelY = min(max($centerPixelY, $minCenterY), $maxCenterY);
    }

    return [
        'x' => (int) round($centerPixelX - $halfCropWidth),
        'y' => (int) round($centerPixelY - $halfCropHeight),
        'width' => (int) round($cropWidth),
        'height' => (int) round($cropHeight)
    ];
}

/**
 * 生成 24位 BMP V3（7色有序抖动）
 */
function generate_bmp($input, $output, $options) {
    $targetWidth = $options['target_width'];
    $targetHeight = $options['target_height'];
    $info = @getimagesize($input);
    if (!$info) return false;

    $src = load_source_image($input, $info[2]);
    if (!$src) return false;

    $src = create_oriented_source($src, $options['rotate']);

    $dst = imagecreatetruecolor($targetWidth, $targetHeight);
    if (!$dst) {
        imagedestroy($src);
        return false;
    }

    $srcWidth = imagesx($src);
    $srcHeight = imagesy($src);
    $cropRect = calculate_crop_rect(
        $srcWidth,
        $srcHeight,
        $targetWidth,
        $targetHeight,
        $options['crop_zoom'],
        $options['crop_center_x'],
        $options['crop_center_y']
    );

    imagecopyresampled(
        $dst,
        $src,
        0,
        0,
        $cropRect['x'],
        $cropRect['y'],
        $targetWidth,
        $targetHeight,
        $cropRect['width'],
        $cropRect['height']
    );
    imagedestroy($src);

    apply_ordered_dither($dst, $targetWidth, $targetHeight);

    $result = write_bmp_v3($dst, $output, $targetWidth, $targetHeight);
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
    $options = [
        'orientation' => $_GET['orientation'] ?? ($_POST['orientation'] ?? 'landscape'),
        'rotate' => $_GET['rotate'] ?? ($_POST['rotate'] ?? 0),
        'crop_center_x' => $_GET['crop_center_x'] ?? ($_POST['crop_center_x'] ?? 0.5),
        'crop_center_y' => $_GET['crop_center_y'] ?? ($_POST['crop_center_y'] ?? 0.5),
        'crop_zoom' => $_GET['crop_zoom'] ?? ($_POST['crop_zoom'] ?? 1)
    ];
    
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
    
    $result = process_image_to_bmp($source, $options);
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'bmp_file' => $result['bmp_file'],
            'bmp_url' => 'http://47.108.232.40:2026/cloud/imget.php?file=' . urlencode($result['bmp_file']),
            'target_width' => $result['target_width'],
            'target_height' => $result['target_height'],
            'message' => $result['message']
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $result['message']]);
    }
    exit;
}
