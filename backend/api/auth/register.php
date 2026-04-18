<?php
/**
 * 用户注册API
 */

require_once __DIR__ . '/../../includes/functions.php';

// 只允许POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, '请求方式错误', [], 405);
}

// 获取参数
$username = trim($_POST['username'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';
$captcha = $_POST['captcha'] ?? '';
$agreeTos = $_POST['agree_tos'] ?? false;

// 验证参数
$errors = [];

if (empty($username)) {
    $errors[] = '用户名不能为空';
} elseif (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)) {
    $errors[] = '用户名只能包含字母、数字和下划线，长度3-32位';
}

if (empty($email)) {
    $errors[] = '邮箱不能为空';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = '邮箱格式不正确';
}

if (empty($password)) {
    $errors[] = '密码不能为空';
} elseif (strlen($password) < 6) {
    $errors[] = '密码长度至少6位';
}

if ($password !== $confirmPassword) {
    $errors[] = '两次输入的密码不一致';
}

if (empty($captcha)) {
    $errors[] = '验证码不能为空';
} elseif (!verifyCaptcha($captcha)) {
    $errors[] = '验证码错误或已过期';
}

if (!$agreeTos) {
    $errors[] = '请同意服务条款';
}

if (!empty($errors)) {
    jsonResponse(false, implode(', ', $errors), [], 400);
}

try {
    $db = getDB();
    
    // 检查用户名是否已存在
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        jsonResponse(false, '用户名已被注册', [], 400);
    }
    
    // 检查邮箱是否已存在
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        jsonResponse(false, '邮箱已被注册', [], 400);
    }
    
    // 创建用户
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
    $stmt->execute([$username, $email, $passwordHash]);
    
    $userId = $db->lastInsertId();
    
    // 创建默认相册
    $stmt = $db->prepare("INSERT INTO albums (user_id, name, description) VALUES (?, '默认相册', '系统自动创建的相册')");
    $stmt->execute([$userId]);
    
    jsonResponse(true, '注册成功', ['user_id' => $userId]);
    
} catch (Exception $e) {
    jsonResponse(false, '注册失败: ' . $e->getMessage(), [], 500);
}
