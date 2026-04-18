<?php
/**
 * 获取相册列表API
 */

require_once __DIR__ . '/../../includes/functions.php';

// 验证登录
$user = requireAuth();

try {
    $db = getDB();
    
    // 获取相册列表及图片数量
    $stmt = $db->prepare("
        SELECT 
            a.*,
            COUNT(i.id) as image_count,
            (SELECT file_path FROM images WHERE id = a.cover_image_id) as cover_image
        FROM albums a
        LEFT JOIN images i ON a.id = i.album_id
        WHERE a.user_id = ?
        GROUP BY a.id
        ORDER BY a.created_at DESC
    ");
    $stmt->execute([$user['id']]);
    $albums = $stmt->fetchAll();
    
    jsonResponse(true, '获取成功', ['albums' => $albums]);
    
} catch (Exception $e) {
    jsonResponse(false, '获取失败: ' . $e->getMessage(), [], 500);
}
