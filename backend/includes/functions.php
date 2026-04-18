<?php
/**
 * 通用函数库
 */

require_once __DIR__ . '/../config/database.php';

/**
 * JSON响应
 * @param bool $success
 * @param string $message
 * @param array $data
 * @param int $code
 */
function jsonResponse($success, $message = '', $data = [], $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

/**
 * 验证用户是否登录
 * @return array|null
 */
function checkAuth() {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, username, email, created_at FROM users WHERE id = ? AND status = 1");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        
        if (!$user) {
            session_destroy();
            return null;
        }
        
        return $user;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * 要求登录
 */
function requireAuth() {
    $user = checkAuth();
    if (!$user) {
        jsonResponse(false, '请先登录', [], 401);
    }
    return $user;
}

/**
 * 生成验证码
 */
function generateCaptcha() {
    $code = sprintf('%04d', mt_rand(0, 9999));
    $_SESSION['captcha'] = $code;
    $_SESSION['captcha_time'] = time();
    return $code;
}

/**
 * 验证验证码
 * @param string $code
 * @return bool
 */
function verifyCaptcha($code) {
    if (empty($_SESSION['captcha']) || empty($code)) {
        return false;
    }
    
    // 验证码5分钟有效
    if (time() - $_SESSION['captcha_time'] > 300) {
        unset($_SESSION['captcha']);
        unset($_SESSION['captcha_time']);
        return false;
    }
    
    $valid = strcasecmp($_SESSION['captcha'], $code) === 0;
    
    if ($valid) {
        unset($_SESSION['captcha']);
        unset($_SESSION['captcha_time']);
    }
    
    return $valid;
}

/**
 * 生成64位设备唯一ID（16位十六进制字符）
 * @return string
 */
function generateDeviceUID() {
    return bin2hex(random_bytes(8));
}

/**
 * 验证设备UID格式
 * @param string $uid
 * @return bool
 */
function validateDeviceUID($uid) {
    return preg_match('/^[a-f0-9]{16}$/i', $uid);
}

/**
 * 上传图片
 * @param array $file $_FILES中的文件
 * @param int $user_id
 * @param int $album_id
 * @return array
 */
function uploadImage($file, $user_id, $album_id) {
    // 检查上传错误
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('文件上传失败: ' . $file['error']);
    }
    
    // 检查文件大小
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        throw new Exception('文件大小超过限制');
    }
    
    // 获取图片尺寸和MIME类型（不依赖fileinfo扩展）
    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        throw new Exception('无法读取图片，文件可能已损坏或不是有效的图片');
    }
    list($width, $height) = $imageInfo;
    $mimeType = $imageInfo['mime'] ?? '';
    
    // 检查MIME类型
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mimeType, $allowedTypes)) {
        throw new Exception('不支持的文件类型: ' . ($mimeType ?: '未知'));
    }
    
    // 生成文件名
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $newFileName = uniqid() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    
    // 按日期创建目录
    $dateDir = date('Y/m');
    $uploadDir = UPLOAD_PATH . $dateDir . '/';
    
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $filePath = $uploadDir . $newFileName;
    
    // 移动文件
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        throw new Exception('文件保存失败');
    }
    
    // 保存到数据库
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO images (album_id, user_id, original_name, file_name, file_path, file_size, mime_type, width, height) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $album_id,
        $user_id,
        $file['name'],
        $newFileName,
        $dateDir . '/' . $newFileName,
        $file['size'],
        $mimeType,
        $width,
        $height
    ]);
    
    $imageId = $db->lastInsertId();
    
    return [
        'id' => $imageId,
        'file_path' => $dateDir . '/' . $newFileName,
        'width' => $width,
        'height' => $height
    ];
}

/**
 * 编码MQTT剩余长度（Variable Byte Integer）
 * @param int $length
 * @return string
 */
function encodeMqttRemainingLength($length) {
    $result = '';
    do {
        $encodedByte = $length % 128;
        $length = intval($length / 128);
        if ($length > 0) {
            $encodedByte |= 128;
        }
        $result .= chr($encodedByte);
    } while ($length > 0);
    return $result;
}

/**
 * 发布MQTT消息
 * @param string $topic
 * @param array $payload
 * @return bool
 */
function publishMQTT($topic, $payload) {
    try {
        $socket = @fsockopen(MQTT_HOST, MQTT_PORT, $errno, $errstr, 5);
        if (!$socket) {
            error_log("MQTT连接失败: $errstr ($errno)");
            return false;
        }
        
        stream_set_timeout($socket, 5);
        
        // 构建正确的MQTT v3.1.1 CONNECT报文
        $clientId = MQTT_CLIENT_ID . '_' . uniqid();
        $hasUser = defined('MQTT_USER') && !empty(MQTT_USER);
        $hasPass = defined('MQTT_PASS') && !empty(MQTT_PASS);
        
        $connectFlags = 0x02; // Clean Session = 1
        if ($hasUser) $connectFlags |= 0x80; // User Name Flag
        if ($hasPass) $connectFlags |= 0x40; // Password Flag
        
        $variableHeader = pack('n', 4) . 'MQTT' . chr(4) . chr($connectFlags) . pack('n', 60);
        
        $payloadData = pack('n', strlen($clientId)) . $clientId;
        if ($hasUser) $payloadData .= pack('n', strlen(MQTT_USER)) . MQTT_USER;
        if ($hasPass) $payloadData .= pack('n', strlen(MQTT_PASS)) . MQTT_PASS;
        
        $remainingLength = strlen($variableHeader) + strlen($payloadData);
        $connect = chr(0x10) . encodeMqttRemainingLength($remainingLength) . $variableHeader . $payloadData;
        
        fwrite($socket, $connect);
        
        // 读取CONNACK（4字节：0x20 0x02 0x00 0x00）
        $response = fread($socket, 4);
        if ($response === false || strlen($response) < 4) {
            error_log("MQTT CONNACK读取失败，可能连接被拒绝");
            fclose($socket);
            return false;
        }
        $resp = unpack('C4', $response);
        if ($resp[1] != 0x20 || $resp[2] != 0x02 || $resp[3] != 0x00 || $resp[4] != 0x00) {
            error_log("MQTT CONNACK响应异常: " . bin2hex($response));
            fclose($socket);
            return false;
        }
        
        // 构建PUBLISH报文（支持超过127字节的消息）
        $message = json_encode($payload);
        $topicLength = strlen($topic);
        $messageLength = strlen($message);
        
        $publishVariableHeader = pack('n', $topicLength) . $topic;
        $publishPayload = $message;
        $publishRemainingLength = strlen($publishVariableHeader) + strlen($publishPayload);
        
        $publish = chr(0x30) . encodeMqttRemainingLength($publishRemainingLength) . $publishVariableHeader . $publishPayload;
        
        $bytesWritten = fwrite($socket, $publish);
        if ($bytesWritten === false || $bytesWritten < strlen($publish)) {
            error_log("MQTT PUBLISH发送不完整");
            fclose($socket);
            return false;
        }
        
        // 发送DISCONNECT
        fwrite($socket, chr(0xE0) . chr(0x00));
        fclose($socket);
        
        return true;
    } catch (Exception $e) {
        error_log("MQTT发布失败: " . $e->getMessage());
        return false;
    }
}

/**
 * 发送图片到设备
 * @param int $device_id
 * @param int $image_id
 * @param int $user_id
 * @return array
 */
function sendImageToDevice($device_id, $image_id, $user_id) {
    $db = getDB();
    
    // 获取设备信息
    $stmt = $db->prepare("SELECT * FROM devices WHERE id = ? AND user_id = ?");
    $stmt->execute([$device_id, $user_id]);
    $device = $stmt->fetch();
    
    if (!$device) {
        throw new Exception('设备不存在或未绑定');
    }
    
    // 获取图片信息
    $stmt = $db->prepare("SELECT * FROM images WHERE id = ? AND user_id = ?");
    $stmt->execute([$image_id, $user_id]);
    $image = $stmt->fetch();
    
    if (!$image) {
        throw new Exception('图片不存在');
    }
    
    // 生成BMP
    require_once __DIR__ . '/../api/imgprocess/imgprocess.php';
    $sourcePath = UPLOAD_PATH . $image['file_path'];
    if (!file_exists($sourcePath)) {
        throw new Exception('源图片文件不存在: ' . $sourcePath);
    }
    
    $bmpResult = process_image_to_bmp($sourcePath);
    if (!$bmpResult['success']) {
        throw new Exception('BMP生成失败: ' . $bmpResult['message']);
    }
    
    // 生成BMP图片URL
    $imageUrl = 'http://47.108.232.40:2026/cloud/imget.php?file=' . urlencode($bmpResult['bmp_file']);
    
    // 构建MQTT消息
    $topic = "device/{$device['device_uid']}/image";
    $payload = [
        'image_id' => $image['id'],
        'image_url' => $imageUrl,
        'original_name' => pathinfo($image['original_name'], PATHINFO_FILENAME) . '.bmp',
        'width' => 800,
        'height' => 480,
        'timestamp' => time()
    ];
    
    // 记录下发日志
    $stmt = $db->prepare("INSERT INTO device_image_logs (device_id, image_id, user_id, status) VALUES (?, ?, ?, 0)");
    $stmt->execute([$device_id, $image_id, $user_id]);
    $logId = $db->lastInsertId();
    
    // 发布MQTT消息
    $mqttResult = publishMQTT($topic, $payload);
    
    if ($mqttResult) {
        // 更新设备当前图片
        $stmt = $db->prepare("UPDATE devices SET current_image_id = ? WHERE id = ?");
        $stmt->execute([$image_id, $device_id]);
        
        // 更新日志状态
        $stmt = $db->prepare("UPDATE device_image_logs SET status = 1 WHERE id = ?");
        $stmt->execute([$logId]);
        
        return [
            'success' => true,
            'message' => '图片下发成功',
            'log_id' => $logId
        ];
    } else {
        // 更新日志状态为失败
        $stmt = $db->prepare("UPDATE device_image_logs SET status = 2, error_message = ? WHERE id = ?");
        $stmt->execute(['MQTT发布失败', $logId]);
        
        throw new Exception('图片下发失败，MQTT服务异常');
    }
}
