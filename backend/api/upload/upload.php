<?php
/**
 * 图片上传API
 */

require_once __DIR__ . '/../../includes/functions.php';

// 验证登录
$user = requireAuth();

// 只允许POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, '请求方式错误', [], 405);
}

$albumId = intval($_POST['album_id'] ?? 0);

if ($albumId <= 0) {
    jsonResponse(false, '请选择相册', [], 400);
}

if (!isset($_FILES['image'])) {
    jsonResponse(false, '请选择要上传的图片', [], 400);
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
    
    // 上传图片
    $result = uploadImage($_FILES['image'], $user['id'], $albumId);
    
    // 如果相册没有封面，设置第一张图片为封面
    if (empty($album['cover_image_id'])) {
        $stmt = $db->prepare("UPDATE albums SET cover_image_id = ? WHERE id = ?");
        $stmt->execute([$result['id'], $albumId]);
    }
    
    jsonResponse(true, '上传成功', [
        'image_id' => $result['id'],
        'url' => UPLOAD_URL . $result['file_path'],
        'width' => $result['width'],
        'height' => $result['height']
    ]);
    
} catch (Exception $e) {
    jsonResponse(false, '上传失败: ' . $e->getMessage(), [], 500);
}
