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

$deviceUid = trim($_POST['device_uid'] ?? '');
$name = trim($_POST['name'] ?? '');
$description = trim($_POST['description'] ?? '');

// 验证设备UID
if (empty($deviceUid)) {
    jsonResponse(false, '请输入设备ID', [], 400);
}

if (!validateDeviceUID($deviceUid)) {
    jsonResponse(false, '设备ID格式不正确，应为16位十六进制字符（64位芯片ID）', [], 400);
}

try {
    $db = getDB();
    
    // 检查设备是否存在
    $stmt = $db->prepare("SELECT * FROM devices WHERE device_uid = ?");
    $stmt->execute([$deviceUid]);
    $device = $stmt->fetch();
    
    if ($device) {
        // 设备已存在
        if ($device['user_id'] !== null) {
            if ($device['user_id'] == $user['id']) {
                jsonResponse(false, '该设备已经绑定到您的账号', [], 400);
            } else {
                jsonResponse(false, '该设备已被其他用户绑定', [], 400);
            }
        }
        
        // 绑定设备
        $stmt = $db->prepare("
            UPDATE devices 
            SET user_id = ?, name = ?, description = ?, status = 2, bound_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$user['id'], $name, $description, $device['id']]);
        
        jsonResponse(true, '设备绑定成功', ['device_id' => $device['id']]);
    } else {
        // 新设备，自动创建并绑定
        $stmt = $db->prepare("
            INSERT INTO devices (device_uid, user_id, name, description, status, bound_at) 
            VALUES (?, ?, ?, ?, 2, NOW())
        ");
        $stmt->execute([$deviceUid, $user['id'], $name, $description]);
        
        $deviceId = $db->lastInsertId();
        
        jsonResponse(true, '设备绑定成功', ['device_id' => $deviceId]);
    }
    
} catch (Exception $e) {
    jsonResponse(false, '绑定失败: ' . $e->getMessage(), [], 500);
}
