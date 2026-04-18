<?php
/**
 * 用户登出API
 */

require_once __DIR__ . '/../../includes/functions.php';

// 清除会话
session_destroy();

jsonResponse(true, '登出成功');
