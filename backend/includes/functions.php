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
 * 规范化 BMP 渲染参数
 * @param array $options
 * @return array
 */
function normalizeBmpRenderOptions(array $options = []) {
    $orientation = ($options['orientation'] ?? 'landscape') === 'portrait' ? 'portrait' : 'landscape';

    $rotate = intval($options['rotate'] ?? 0);
    $rotate = (($rotate % 360) + 360) % 360;
    if (!in_array($rotate, [0, 90, 180, 270], true)) {
        $rotate = 0;
    }

    $cropCenterX = isset($options['crop_center_x']) ? floatval($options['crop_center_x']) : 0.5;
    $cropCenterY = isset($options['crop_center_y']) ? floatval($options['crop_center_y']) : 0.5;
    $cropZoom = isset($options['crop_zoom']) ? floatval($options['crop_zoom']) : 1.0;

    $cropCenterX = max(0.0, min(1.0, $cropCenterX));
    $cropCenterY = max(0.0, min(1.0, $cropCenterY));
    $cropZoom = max(0.5, min(4.0, $cropZoom));

    return [
        'orientation' => $orientation,
        'rotate' => $rotate,
        'crop_center_x' => round($cropCenterX, 6),
        'crop_center_y' => round($cropCenterY, 6),
        'crop_zoom' => round($cropZoom, 4),
        'target_width' => $orientation === 'portrait' ? 480 : 800,
        'target_height' => $orientation === 'portrait' ? 800 : 480
    ];
}

/**
 * 构建站点绝对URL
 * @param string $path
 * @return string
 */
function buildAppAbsoluteUrl($path) {
    $baseUrl = rtrim(APP_URL, '/');
    $normalizedPath = '/' . ltrim($path, '/');
    return $baseUrl . $normalizedPath;
}

/**
 * 生成6位动态绑定码
 * @return string
 */
function generateDynamicBindCode() {
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * 判断动态绑定码格式
 * @param string $code
 * @return bool
 */
function validateDynamicBindCode($code) {
    return preg_match('/^\d{6}$/', $code) === 1;
}

/**
 * 为未绑定设备生成不冲突的动态绑定码
 * @param PDO $db
 * @return string
 */
function issueUniqueDynamicBindCode($db) {
    for ($i = 0; $i < 10; $i++) {
        $code = generateDynamicBindCode();
        $stmt = $db->prepare("
            SELECT id
            FROM devices
            WHERE dyn_bound_code = ?
              AND user_id IS NULL
              AND dyn_bound_code_expires_at IS NOT NULL
              AND dyn_bound_code_expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$code]);

        if (!$stmt->fetch()) {
            return $code;
        }
    }

    throw new Exception('动态绑定码生成失败，请稍后重试');
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
 * 下发动态绑定码到设备
 * @param string $deviceUid
 * @param string $bindCode
 * @param int $expiresIn
 * @return bool
 */
function publishDynamicBindCodeToDevice($deviceUid, $bindCode, $expiresIn = 300) {
    $topic = 'device/' . strtoupper($deviceUid) . '/bound';
    $payload = [
        'event' => 'dyn_bound_code',
        'device_uid' => strtoupper($deviceUid),
        'dyn_bound_code' => $bindCode,
        'expires_in' => $expiresIn,
        'timestamp' => time()
    ];

    return publishMQTT($topic, $payload);
}

/**
 * 发送图片到设备
 * @param int $device_id
 * @param int $image_id
 * @param int $user_id
 * @param array $renderOptions
 * @return array
 */
function sendImageToDevice($device_id, $image_id, $user_id, array $renderOptions = []) {
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

    $normalizedOptions = normalizeBmpRenderOptions($renderOptions);
    $bmpResult = process_image_to_bmp($sourcePath, $normalizedOptions);
    if (!$bmpResult['success']) {
        error_log(sprintf(
            'sendImageToDevice BMP failed: device_id=%d image_id=%d user_id=%d source=%s options=%s message=%s',
            $device_id,
            $image_id,
            $user_id,
            $sourcePath,
            json_encode($normalizedOptions, JSON_UNESCAPED_UNICODE),
            $bmpResult['message']
        ));
        throw new Exception('BMP生成失败: ' . $bmpResult['message']);
    }
    
    // 生成BMP图片URL
    $imageUrl = buildAppAbsoluteUrl('imget.php?file=' . urlencode($bmpResult['bmp_file']));
    
    // 构建MQTT消息
    $topic = "device/{$device['device_uid']}/image";
    $payload = [
        'image_id' => $image['id'],
        'image_url' => $imageUrl,
        'original_name' => pathinfo($image['original_name'], PATHINFO_FILENAME) . '.bmp',
        'width' => $bmpResult['target_width'],
        'height' => $bmpResult['target_height'],
        'orientation' => $normalizedOptions['orientation'],
        'rotate' => $normalizedOptions['rotate'],
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

        error_log(sprintf(
            'sendImageToDevice MQTT failed: device_id=%d image_id=%d user_id=%d topic=%s payload=%s',
            $device_id,
            $image_id,
            $user_id,
            $topic,
            json_encode($payload, JSON_UNESCAPED_UNICODE)
        ));
        
        throw new Exception('图片下发失败，MQTT服务异常');
    }
}
