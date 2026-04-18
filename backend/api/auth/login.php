<?php
/**
 * 用户登录API
 */

require_once __DIR__ . '/../../includes/functions.php';

// 只允许POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, '请求方式错误', [], 405);
}

// 获取参数
$account = trim($_POST['account'] ?? '');
$password = $_POST['password'] ?? '';
$captcha = $_POST['captcha'] ?? '';

// 验证参数
if (empty($account)) {
    jsonResponse(false, '请输入用户名或邮箱', [], 400);
}

if (empty($password)) {
    jsonResponse(false, '请输入密码', [], 400);
}

if (empty($captcha)) {
    jsonResponse(false, '请输入验证码', [], 400);
} elseif (!verifyCaptcha($captcha)) {
    jsonResponse(false, '验证码错误或已过期', [], 400);
}

try {
    $db = getDB();
    
    // 查询用户（支持用户名或邮箱登录）
    $stmt = $db->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND status = 1");
    $stmt->execute([$account, $account]);
    $user = $stmt->fetch();
    
    if (!$user) {
        jsonResponse(false, '用户名或密码错误', [], 401);
    }
    
    // 验证密码
    if (!password_verify($password, $user['password_hash'])) {
        jsonResponse(false, '用户名或密码错误', [], 401);
    }
    
    // 更新最后登录时间
    $stmt = $db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
    $stmt->execute([$user['id']]);
    
    // 设置会话
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    
    jsonResponse(true, '登录成功', [
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email']
        ]
    ]);
    
} catch (Exception $e) {
    jsonResponse(false, '登录失败: ' . $e->getMessage(), [], 500);
}
