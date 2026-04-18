<?php
/**
 * 创建相册API
 */

require_once __DIR__ . '/../../includes/functions.php';

// 验证登录
$user = requireAuth();

// 只允许POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, '请求方式错误', [], 405);
}

// 获取参数
$name = trim($_POST['name'] ?? '');
$description = trim($_POST['description'] ?? '');

// 验证参数
if (empty($name)) {
    jsonResponse(false, '相册名称不能为空', [], 400);
}

if (strlen($name) > 100) {
    jsonResponse(false, '相册名称不能超过100个字符', [], 400);
}

try {
    $db = getDB();
    
    // 检查相册名称是否已存在
    $stmt = $db->prepare("SELECT id FROM albums WHERE user_id = ? AND name = ?");
    $stmt->execute([$user['id'], $name]);
    if ($stmt->fetch()) {
        jsonResponse(false, '您已创建过同名相册', [], 400);
    }
    
    // 创建相册
    $stmt = $db->prepare("INSERT INTO albums (user_id, name, description) VALUES (?, ?, ?)");
    $stmt->execute([$user['id'], $name, $description]);
    
    $albumId = $db->lastInsertId();
    
    jsonResponse(true, '相册创建成功', ['album_id' => $albumId]);
    
} catch (Exception $e) {
    jsonResponse(false, '相册创建失败: ' . $e->getMessage(), [], 500);
}
