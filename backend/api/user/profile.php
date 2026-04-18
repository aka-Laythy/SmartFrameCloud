<?php
/**
 * 获取用户信息API
 */

require_once __DIR__ . '/../../includes/functions.php';

// 验证登录
$user = requireAuth();

try {
    $db = getDB();
    
    // 获取用户统计信息
    $stmt = $db->prepare("SELECT COUNT(*) as album_count FROM albums WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $albumCount = $stmt->fetch()['album_count'];
    
    $stmt = $db->prepare("SELECT COUNT(*) as image_count FROM images WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $imageCount = $stmt->fetch()['image_count'];
    
    $stmt = $db->prepare("SELECT COUNT(*) as device_count FROM devices WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $deviceCount = $stmt->fetch()['device_count'];
    
    jsonResponse(true, '获取成功', [
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'created_at' => $user['created_at']
        ],
        'stats' => [
            'album_count' => $albumCount,
            'image_count' => $imageCount,
            'device_count' => $deviceCount
        ]
    ]);
    
} catch (Exception $e) {
    jsonResponse(false, '获取失败: ' . $e->getMessage(), [], 500);
}
