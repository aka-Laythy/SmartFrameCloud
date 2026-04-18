<?php
/**
 * 调试图片渲染接口
 * 支持 GET / POST
 */

require_once __DIR__ . '/../../includes/functions.php';

$user = requireAuth();

$imageId = intval($_REQUEST['image_id'] ?? 0);
$renderOptions = [
    'orientation' => $_REQUEST['orientation'] ?? 'landscape',
    'rotate' => $_REQUEST['rotate'] ?? 0,
    'crop_center_x' => $_REQUEST['crop_center_x'] ?? 0.5,
    'crop_center_y' => $_REQUEST['crop_center_y'] ?? 0.5,
    'crop_zoom' => $_REQUEST['crop_zoom'] ?? 1
];

if ($imageId <= 0) {
    jsonResponse(false, '缺少 image_id', [], 400);
}

try {
    $db = getDB();
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
            'image' => $image,
            'source_path' => $sourcePath,
            'source_exists' => file_exists($sourcePath),
            'source_readable' => is_readable($sourcePath),
            'render_options' => $normalizedOptions,
            'bmp_result' => $bmpResult
        ], 500);
    }

    jsonResponse(true, 'BMP 生成成功', [
        'image' => $image,
        'source_path' => $sourcePath,
        'source_exists' => file_exists($sourcePath),
        'source_readable' => is_readable($sourcePath),
        'render_options' => $normalizedOptions,
        'bmp_result' => $bmpResult,
        'bmp_url' => buildAppAbsoluteUrl('imget.php?file=' . urlencode($bmpResult['bmp_file']))
    ]);
} catch (Exception $e) {
    jsonResponse(false, '调试失败: ' . $e->getMessage(), [], 500);
}
