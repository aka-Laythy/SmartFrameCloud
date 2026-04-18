<?php
/**
 * 数据库配置文件
 */

// 数据库配置
define('DB_HOST', 'localhost');
define('DB_NAME', 'smartframe');
define('DB_USER', 'smartframe');
define('DB_PASS', 'f4EWsnKwJdPwj7yh');
define('DB_CHARSET', 'utf8mb4');

// MQTT配置
define('MQTT_HOST', 'localhost');
define('MQTT_PORT', 1883);
define('MQTT_USER', 'smartframecloud');
define('MQTT_PASS', '9x9v95hea7');
define('MQTT_CLIENT_ID', 'smart_album_server_' . uniqid());

// 应用配置
define('APP_NAME', '智能相册云平台');
define('APP_URL', 'http://47.108.232.40:2026/cloud/');
define('UPLOAD_PATH', __DIR__ . '/../../uploads/');
define('UPLOAD_URL', '/uploads/');
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB

// 会话配置
session_start();

// 设置时区
date_default_timezone_set('Asia/Shanghai');

// 错误报告（生产环境请关闭）
error_reporting(E_ALL);
ini_set('display_errors', '1');

/**
 * 获取数据库连接
 * @return PDO
 */
function getDB() {
    static $db = null;
    
    if ($db === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $db = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            throw new Exception("数据库连接失败: " . $e->getMessage());
        }
    }
    
    return $db;
}
