<?php
/**
 * 解绑设备API
 */

require_once __DIR__ . '/../../includes/functions.php';

// 验证登录
$user = requireAuth();

// 只允许POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, '请求方式错误', [], 405);
}

$deviceId = intval($_POST['device_id'] ?? 0);

if ($deviceId <= 0) {
    jsonResponse(false, '参数错误', [], 400);
}

try {
    $db = getDB();
    
    // 检查设备是否存在且属于当前用户
    $stmt = $db->prepare("SELECT * FROM devices WHERE id = ? AND user_id = ?");
    $stmt->execute([$deviceId, $user['id']]);
    $device = $stmt->fetch();
    
    if (!$device) {
        jsonResponse(false, '设备不存在', [], 404);
    }
    
    // 解绑设备
    $stmt = $db->prepare("
        UPDATE devices 
        SET user_id = NULL, name = NULL, description = NULL, status = 0, 
            bound_at = NULL, current_image_id = NULL,
            dyn_bound_code = NULL, dyn_bound_code_issued_at = NULL, dyn_bound_code_expires_at = NULL
        WHERE id = ?
    ");
    $stmt->execute([$deviceId]);
    
    jsonResponse(true, '设备解绑成功');
    
} catch (Exception $e) {
    jsonResponse(false, '解绑失败: ' . $e->getMessage(), [], 500);
}
