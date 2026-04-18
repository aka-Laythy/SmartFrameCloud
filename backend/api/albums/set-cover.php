<?php
/**
 * 设置相册封面图片API
 */

require_once __DIR__ . '/../../includes/functions.php';

// 验证登录
$user = requireAuth();

// 只允许POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, '请求方式错误', [], 405);
}

// 获取参数
$album_id = intval($_POST['album_id'] ?? 0);
$image_id = intval($_POST['image_id'] ?? 0);

// 验证参数
if ($album_id <= 0) {
    jsonResponse(false, '相册ID无效', [], 400);
}

if ($image_id <= 0) {
    jsonResponse(false, '图片ID无效', [], 400);
}

try {
    $db = getDB();
    
    // 验证相册是否存在且属于当前用户
    $stmt = $db->prepare("SELECT id FROM albums WHERE id = ? AND user_id = ?");
    $stmt->execute([$album_id, $user['id']]);
    if (!$stmt->fetch()) {
        jsonResponse(false, '相册不存在或无权限', [], 403);
    }
    
    // 验证图片是否存在且属于当前用户且属于该相册
    $stmt = $db->prepare("SELECT id FROM images WHERE id = ? AND user_id = ? AND album_id = ?");
    $stmt->execute([$image_id, $user['id'], $album_id]);
    if (!$stmt->fetch()) {
        jsonResponse(false, '图片不存在或无权限', [], 403);
    }
    
    // 更新相册封面
    $stmt = $db->prepare("UPDATE albums SET cover_image_id = ? WHERE id = ?");
    $stmt->execute([$image_id, $album_id]);
    
    jsonResponse(true, '封面设置成功');
    
} catch (Exception $e) {
    jsonResponse(false, '设置失败: ' . $e->getMessage(), [], 500);
}