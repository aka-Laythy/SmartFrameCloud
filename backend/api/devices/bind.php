<?php
/**
 * 绑定设备API
 */

require_once __DIR__ . '/../../includes/functions.php';

// 验证登录
$user = requireAuth();

// 只允许POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, '请求方式错误', [], 405);
}

$bindCode = trim($_POST['dyn_bound_code'] ?? '');
$name = trim($_POST['name'] ?? '');
$description = trim($_POST['description'] ?? '');

if (empty($bindCode)) {
    jsonResponse(false, '请输入6位动态绑定码', [], 400);
}

if (!validateDynamicBindCode($bindCode)) {
    jsonResponse(false, '动态绑定码格式不正确，应为6位数字', [], 400);
}

try {
    $db = getDB();
    
    // 检查动态绑定码对应的未绑定设备
    $stmt = $db->prepare("
        SELECT *
        FROM devices
        WHERE dyn_bound_code = ?
          AND user_id IS NULL
          AND dyn_bound_code_expires_at IS NOT NULL
          AND dyn_bound_code_expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([$bindCode]);
    $device = $stmt->fetch();
    
    if (!$device) {
        jsonResponse(false, '动态绑定码无效或已过期', [], 404);
    }

    $stmt = $db->prepare("
        UPDATE devices 
        SET user_id = ?, name = ?, description = ?, bound_at = NOW(),
            dyn_bound_code = NULL, dyn_bound_code_issued_at = NULL, dyn_bound_code_expires_at = NULL
        WHERE id = ?
    ");
    $stmt->execute([$user['id'], $name, $description, $device['id']]);

    jsonResponse(true, '设备绑定成功', [
        'device_id' => $device['id'],
        'device_uid' => $device['device_uid']
    ]);
    
} catch (Exception $e) {
    jsonResponse(false, '绑定失败: ' . $e->getMessage(), [], 500);
}
