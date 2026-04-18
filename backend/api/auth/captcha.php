<?php
/**
 * 验证码生成API
 */

require_once __DIR__ . '/../../includes/functions.php';

// 生成验证码
$code = generateCaptcha();

// 创建图片
$width = 120;
$height = 40;
$image = imagecreatetruecolor($width, $height);

// 背景色
$bgColor = imagecolorallocate($image, 240, 240, 240);
imagefill($image, 0, 0, $bgColor);

// 添加干扰线
for ($i = 0; $i < 5; $i++) {
    $lineColor = imagecolorallocate($image, mt_rand(100, 200), mt_rand(100, 200), mt_rand(100, 200));
    imageline($image, mt_rand(0, $width), mt_rand(0, $height), mt_rand(0, $width), mt_rand(0, $height), $lineColor);
}

// 添加干扰点
for ($i = 0; $i < 50; $i++) {
    $pointColor = imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
    imagesetpixel($image, mt_rand(0, $width), mt_rand(0, $height), $pointColor);
}

// 绘制文字
$fontColor = imagecolorallocate($image, 50, 50, 50);
$fontSize = 20;
$x = 15;
for ($i = 0; $i < 4; $i++) {
    $angle = mt_rand(-15, 15);
    $y = mt_rand(25, 35);
    imagechar($image, 5, $x + $i * 25, $y - 20, $code[$i], $fontColor);
}

// 输出图片
header('Content-Type: image/png');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

imagepng($image);
imagedestroy($image);
