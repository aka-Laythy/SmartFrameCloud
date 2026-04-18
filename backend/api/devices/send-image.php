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

if ($deviceId <= 0 || $imageId <= 0) {
    jsonResponse(false, '参数错误', [], 400);
}

try {
    $result = sendImageToDevice($deviceId, $imageId, $user['id']);
    jsonResponse(true, $result['message'], $result);
    
} catch (Exception $e) {
    jsonResponse(false, $e->getMessage(), [], 500);
}
