<?php
/**
 * 调试图片下发接口
 * 默认只做 dry-run，不写日志、不更新数据库、不发 MQTT
 * 当 publish=1 时，执行真实 MQTT 发布
 */

require_once __DIR__ . '/../../includes/functions.php';

$user = requireAuth();

$deviceId = intval($_REQUEST['device_id'] ?? 0);
$imageId = intval($_REQUEST['image_id'] ?? 0);
$publish = isset($_REQUEST['publish']) && strval($_REQUEST['publish']) === '1';
$renderOptions = [
    'orientation' => $_REQUEST['orientation'] ?? 'landscape',
    'rotate' => $_REQUEST['rotate'] ?? 0,
    'crop_center_x' => $_REQUEST['crop_center_x'] ?? 0.5,
    'crop_center_y' => $_REQUEST['crop_center_y'] ?? 0.5,
    'crop_zoom' => $_REQUEST['crop_zoom'] ?? 1
];

if ($deviceId <= 0 || $imageId <= 0) {
    jsonResponse(false, '缺少 device_id 或 image_id', [], 400);
}

try {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT id, user_id, device_uid, name, description, status, bound_at, current_image_id
        FROM devices
        WHERE id = ? AND user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$deviceId, $user['id']]);
    $device = $stmt->fetch();

    if (!$device) {
        jsonResponse(false, '设备不存在或未绑定到当前用户', [], 404);
    }

    $stmt = $db->prepare("
        SELECT id, album_id, user_id, original_name, file_path, mime_type, width, height
        FROM images
        WHERE id = ? AND user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$imageId, $user['id']]);
    $image = $stmt->fetch();

    if (!$image) {
        jsonResponse(false, '图片不存在或无权访问', [], 404);
    }

    require_once __DIR__ . '/../imgprocess/imgprocess.php';

    $normalizedOptions = normalizeBmpRenderOptions($renderOptions);
    $sourcePath = UPLOAD_PATH . $image['file_path'];
    $bmpResult = process_image_to_bmp($sourcePath, $normalizedOptions);

    if (!$bmpResult['success']) {
        jsonResponse(false, 'BMP 生成失败', [
            'device' => $device,
            'image' => $image,
            'source_path' => $sourcePath,
            'source_exists' => file_exists($sourcePath),
            'source_readable' => is_readable($sourcePath),
            'render_options' => $normalizedOptions,
            'bmp_result' => $bmpResult,
            'publish' => $publish
        ], 500);
    }

    $imageUrl = buildAppAbsoluteUrl('imget.php?file=' . urlencode($bmpResult['bmp_file']));
    $topic = 'device/' . $device['device_uid'] . '/image';
    $payload = [
        'image_id' => intval($image['id']),
        'image_url' => $imageUrl,
        'original_name' => pathinfo($image['original_name'], PATHINFO_FILENAME) . '.bmp',
        'width' => $bmpResult['target_width'],
        'height' => $bmpResult['target_height'],
        'orientation' => $normalizedOptions['orientation'],
        'rotate' => $normalizedOptions['rotate'],
        'timestamp' => time()
    ];

    $mqttPublished = null;
    if ($publish) {
        $mqttPublished = publishMQTT($topic, $payload);
        if (!$mqttPublished) {
            jsonResponse(false, 'MQTT 发布失败', [
                'device' => $device,
                'image' => $image,
                'source_path' => $sourcePath,
                'render_options' => $normalizedOptions,
                'bmp_result' => $bmpResult,
                'topic' => $topic,
                'payload' => $payload,
                'publish' => true,
                'mqtt_published' => false
            ], 500);
        }
    }

    jsonResponse(true, $publish ? '调试下发成功' : 'dry-run 成功', [
        'device' => $device,
        'image' => $image,
        'source_path' => $sourcePath,
        'source_exists' => file_exists($sourcePath),
        'source_readable' => is_readable($sourcePath),
        'render_options' => $normalizedOptions,
        'bmp_result' => $bmpResult,
        'topic' => $topic,
        'payload' => $payload,
        'publish' => $publish,
        'mqtt_published' => $mqttPublished
    ]);
} catch (Exception $e) {
    jsonResponse(false, '调试失败: ' . $e->getMessage(), [], 500);
}
