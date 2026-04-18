<?php
/**
 * 获取设备列表API
 */

require_once __DIR__ . '/../../includes/functions.php';

// 验证登录
$user = requireAuth();

try {
    $db = getDB();
    
    // 获取用户的设备列表
    $stmt = $db->prepare("
        SELECT 
            d.*,
            i.original_name as current_image_name,
            i.file_path as current_image_path
        FROM devices d
        LEFT JOIN images i ON d.current_image_id = i.id
        WHERE d.user_id = ?
        ORDER BY d.bound_at DESC
    ");
    $stmt->execute([$user['id']]);
    $devices = $stmt->fetchAll();
    
    // 添加完整URL
    foreach ($devices as &$device) {
        if ($device['current_image_path']) {
            $device['current_image_url'] = UPLOAD_URL . $device['current_image_path'];
        }
    }
    
    jsonResponse(true, '获取成功', ['devices' => $devices]);
    
} catch (Exception $e) {
    jsonResponse(false, '获取失败: ' . $e->getMessage(), [], 500);
}
