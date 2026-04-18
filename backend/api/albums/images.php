<?php
/**
 * 获取相册图片列表API
 */

require_once __DIR__ . '/../../includes/functions.php';

// 验证登录
$user = requireAuth();

$albumId = intval($_GET['album_id'] ?? 0);

if ($albumId <= 0) {
    jsonResponse(false, '参数错误', [], 400);
}

try {
    $db = getDB();
    
    // 检查相册是否存在且属于当前用户
    $stmt = $db->prepare("SELECT * FROM albums WHERE id = ? AND user_id = ?");
    $stmt->execute([$albumId, $user['id']]);
    $album = $stmt->fetch();
    
    if (!$album) {
        jsonResponse(false, '相册不存在', [], 404);
    }
    
    // 获取图片列表
    $stmt = $db->prepare("
        SELECT 
            id,
            original_name,
            file_path,
            file_size,
            width,
            height,
            uploaded_at
        FROM images 
        WHERE album_id = ? 
        ORDER BY uploaded_at DESC
    ");
    $stmt->execute([$albumId]);
    $images = $stmt->fetchAll();
    
    // 添加完整URL
    foreach ($images as &$image) {
        $image['url'] = UPLOAD_URL . $image['file_path'];
    }
    
    jsonResponse(true, '获取成功', [
        'album' => $album,
        'images' => $images
    ]);
    
} catch (Exception $e) {
    jsonResponse(false, '获取失败: ' . $e->getMessage(), [], 500);
}
