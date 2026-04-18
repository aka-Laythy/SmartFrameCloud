<?php
/**
 * 下发图片到设备API
 */

require_once __DIR__ . '/../../includes/functions.php';

// 验证登录
$user = requireAuth();

// 只允许POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, '请求方式错误', [], 405);
}

$deviceId = intval($_POST['device_id'] ?? 0);
$imageId = intval($_POST['image_id'] ?? 0);
$renderOptions = [
    'orientation' => $_POST['orientation'] ?? 'landscape',
    'rotate' => $_POST['rotate'] ?? 0,
    'crop_center_x' => $_POST['crop_center_x'] ?? 0.5,
    'crop_center_y' => $_POST['crop_center_y'] ?? 0.5,
    'crop_zoom' => $_POST['crop_zoom'] ?? 1
];

if ($deviceId <= 0 || $imageId <= 0) {
    jsonResponse(false, '参数错误', [], 400);
}

try {
    $result = sendImageToDevice($deviceId, $imageId, $user['id'], $renderOptions);
    jsonResponse(true, $result['message'], $result);
    
} catch (Exception $e) {
    error_log(sprintf(
        'send-image.php failed: user_id=%d device_id=%d image_id=%d options=%s error=%s',
        (int) $user['id'],
        $deviceId,
        $imageId,
        json_encode($renderOptions, JSON_UNESCAPED_UNICODE),
        $e->getMessage()
    ));
    jsonResponse(false, $e->getMessage(), [], 500);
}
