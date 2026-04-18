<?php
/**
 * 删除相册API
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
    
    // 获取相册中的所有图片
    $stmt = $db->prepare("SELECT * FROM images WHERE album_id = ?");
    $stmt->execute([$albumId]);
    $images = $stmt->fetchAll();
    
    // 删除物理文件
    foreach ($images as $image) {
        $filePath = UPLOAD_PATH . $image['file_path'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }
    
    // 删除相册（关联的图片会通过外键级联删除）
    $stmt = $db->prepare("DELETE FROM albums WHERE id = ?");
    $stmt->execute([$albumId]);
    
    jsonResponse(true, '相册删除成功');
    
} catch (Exception $e) {
    jsonResponse(false, '删除失败: ' . $e->getMessage(), [], 500);
}
