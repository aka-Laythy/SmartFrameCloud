-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- 主机： localhost
-- 生成日期： 2026-04-18 23:26:09
-- 服务器版本： 5.7.43-log
-- PHP 版本： 8.1.32

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- 数据库： `smartframe`
--

-- --------------------------------------------------------

--
-- 表的结构 `albums`
--

CREATE TABLE `albums` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL COMMENT '所属用户ID',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '相册名称',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT '相册描述',
  `cover_image_id` int(10) UNSIGNED DEFAULT NULL COMMENT '封面图片ID',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='相册表';

-- --------------------------------------------------------

--
-- 表的结构 `devices`
--

CREATE TABLE `devices` (
  `id` int(10) UNSIGNED NOT NULL,
  `device_uid` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '设备64位全球唯一ID',
  `user_id` int(10) UNSIGNED DEFAULT NULL COMMENT '绑定用户ID',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '设备名称',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT '设备描述',
  `status` tinyint(4) DEFAULT '0' COMMENT '状态: 0-未绑定, 1-在线, 2-离线',
  `bound_at` timestamp NULL DEFAULT NULL COMMENT '绑定时间',
  `last_online_at` timestamp NULL DEFAULT NULL COMMENT '最后在线时间',
  `dyn_bound_code` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '动态绑定码，未绑定设备待展示，已绑定后置空',
  `dyn_bound_code_issued_at` timestamp NULL DEFAULT NULL COMMENT '动态绑定码下发时间',
  `dyn_bound_code_expires_at` timestamp NULL DEFAULT NULL COMMENT '动态绑定码过期时间',
  `current_image_id` int(10) UNSIGNED DEFAULT NULL COMMENT '当前显示图片ID',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='设备表';

-- --------------------------------------------------------

--
-- 表的结构 `device_image_logs`
--

CREATE TABLE `device_image_logs` (
  `id` int(10) UNSIGNED NOT NULL,
  `device_id` int(10) UNSIGNED NOT NULL COMMENT '设备ID',
  `image_id` int(10) UNSIGNED NOT NULL COMMENT '图片ID',
  `user_id` int(10) UNSIGNED NOT NULL COMMENT '操作用户ID',
  `sent_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT '下发时间',
  `status` tinyint(4) DEFAULT '0' COMMENT '状态: 0-发送中, 1-成功, 2-失败',
  `mqtt_message_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'MQTT消息ID',
  `error_message` text COLLATE utf8mb4_unicode_ci COMMENT '错误信息'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='设备图片下发记录表';

-- --------------------------------------------------------

--
-- 表的结构 `images`
--

CREATE TABLE `images` (
  `id` int(10) UNSIGNED NOT NULL,
  `album_id` int(10) UNSIGNED NOT NULL COMMENT '所属相册ID',
  `user_id` int(10) UNSIGNED NOT NULL COMMENT '上传用户ID',
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '原始文件名',
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '存储文件名',
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '文件路径',
  `file_size` int(10) UNSIGNED NOT NULL COMMENT '文件大小(字节)',
  `mime_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'MIME类型',
  `width` int(10) UNSIGNED DEFAULT NULL COMMENT '图片宽度',
  `height` int(10) UNSIGNED DEFAULT NULL COMMENT '图片高度',
  `uploaded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT '上传时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='图片表';

-- --------------------------------------------------------

--
-- 表的结构 `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `username` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '用户名',
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '邮箱',
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '密码哈希',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `last_login_at` timestamp NULL DEFAULT NULL COMMENT '最后登录时间',
  `status` tinyint(4) DEFAULT '1' COMMENT '状态: 0-禁用, 1-正常'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户表';

--
-- 转储表的索引
--

--
-- 表的索引 `albums`
--
ALTER TABLE `albums`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `cover_image_id` (`cover_image_id`);

--
-- 表的索引 `devices`
--
ALTER TABLE `devices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `device_uid` (`device_uid`),
  ADD KEY `idx_device_uid` (`device_uid`),
  ADD KEY `idx_dyn_bound_code` (`dyn_bound_code`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `current_image_id` (`current_image_id`);

--
-- 表的索引 `device_image_logs`
--
ALTER TABLE `device_image_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `image_id` (`image_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_device_id` (`device_id`),
  ADD KEY `idx_sent_at` (`sent_at`);

--
-- 表的索引 `images`
--
ALTER TABLE `images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_album_id` (`album_id`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- 表的索引 `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_username` (`username`);

--
-- 在导出的表使用AUTO_INCREMENT
--

--
-- 使用表AUTO_INCREMENT `albums`
--
ALTER TABLE `albums`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `devices`
--
ALTER TABLE `devices`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `device_image_logs`
--
ALTER TABLE `device_image_logs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `images`
--
ALTER TABLE `images`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 限制导出的表
--

--
-- 限制表 `albums`
--
ALTER TABLE `albums`
  ADD CONSTRAINT `albums_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `albums_ibfk_2` FOREIGN KEY (`cover_image_id`) REFERENCES `images` (`id`) ON DELETE SET NULL;

--
-- 限制表 `devices`
--
ALTER TABLE `devices`
  ADD CONSTRAINT `devices_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `devices_ibfk_2` FOREIGN KEY (`current_image_id`) REFERENCES `images` (`id`) ON DELETE SET NULL;

--
-- 限制表 `device_image_logs`
--
ALTER TABLE `device_image_logs`
  ADD CONSTRAINT `device_image_logs_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `device_image_logs_ibfk_2` FOREIGN KEY (`image_id`) REFERENCES `images` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `device_image_logs_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- 限制表 `images`
--
ALTER TABLE `images`
  ADD CONSTRAINT `images_ibfk_1` FOREIGN KEY (`album_id`) REFERENCES `albums` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `images_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
