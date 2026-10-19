-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Sep 25, 2026 at 11:07 PM
-- Server version: 8.4.3
-- PHP Version: 8.3.26

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `kapehan_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `addons`
--

CREATE TABLE `addons` (
  `id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `addons`
--

INSERT INTO `addons` (`id`, `name`, `price`, `status`, `created_at`) VALUES
(1, 'Espresso', 30.00, 'active', '2026-04-28 11:05:46'),
(2, 'Syrup', 20.00, 'active', '2026-04-28 11:06:10'),
(3, 'Nata', 15.00, 'active', '2026-04-28 11:06:28');

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int UNSIGNED NOT NULL,
  `full_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'bcrypt hash',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`id`, `full_name`, `username`, `password`, `created_at`, `updated_at`) VALUES
(1, 'Jhulmar Bregonia', 'bregonia@kapehan.com', '$2y$10$ojgcxq4VxZcaeiKDZsMcve6r6FYVEWttI43XzqY284WlUVqLl01xG', '2026-04-28 19:02:04', '2026-04-28 19:05:13'),
(2, 'Kapehan Admin', 'Admin', '$2y$10$N13YhYg6i3P.ZIz5rF5FQuq6YND37uiwZlii6qjcZuqJFwn7XTcr.', '2026-06-09 15:29:19', '2026-06-09 15:29:19');

-- --------------------------------------------------------

--
-- Table structure for table `app_settings`
--

CREATE TABLE `app_settings` (
  `setting_key` varchar(80) NOT NULL,
  `setting_value` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `app_settings`
--

INSERT INTO `app_settings` (`setting_key`, `setting_value`) VALUES
('max_cash_preorder_amount', '150'),
('no_show_strike_limit', '3'),
('pickup_slot_capacity', '6'),
('pickup_slot_interval_minutes', '15'),
('store_close_time', '19:00'),
('store_open_time', '07:00');

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `id` int UNSIGNED NOT NULL,
  `actor_type` enum('admin','cashier','student','faculty') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` int UNSIGNED NOT NULL,
  `action` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'e.g. orders, products',
  `target_id` int UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_log`
--

INSERT INTO `audit_log` (`id`, `actor_type`, `actor_id`, `action`, `target`, `target_id`, `ip_address`, `created_at`) VALUES
(1, 'admin', 1, 'login', NULL, NULL, '127.0.0.1', '2026-05-10 20:37:02'),
(2, 'admin', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-05-10 20:37:12'),
(3, 'student', 1, 'login', NULL, NULL, '::1', '2026-05-11 15:54:12'),
(4, 'student', 1, 'place_preorder', 'orders', 1, '::1', '2026-05-11 15:55:05'),
(5, 'student', 1, 'logout', NULL, NULL, '::1', '2026-05-11 15:55:08'),
(6, 'cashier', 1, 'login', NULL, NULL, '::1', '2026-05-11 15:55:24'),
(7, 'cashier', 1, 'locked', 'orders', 1, '::1', '2026-05-11 15:56:24'),
(8, 'cashier', 1, 'status_preparing', 'orders', 1, '::1', '2026-05-11 15:56:26'),
(9, 'cashier', 1, 'locked', 'orders', 1, '::1', '2026-05-11 15:56:29'),
(10, 'cashier', 1, 'status_ready', 'orders', 1, '::1', '2026-05-11 15:56:30'),
(11, 'cashier', 1, 'status_claimed', 'orders', 1, '::1', '2026-05-11 15:56:33'),
(12, 'cashier', 1, 'logout', NULL, NULL, '::1', '2026-05-11 15:56:36'),
(13, 'student', 1, 'login', NULL, NULL, '::1', '2026-05-11 15:56:47'),
(14, 'student', 1, 'place_preorder', 'orders', 2, '::1', '2026-05-11 15:57:24'),
(15, 'student', 1, 'logout', NULL, NULL, '::1', '2026-05-11 15:57:27'),
(16, 'cashier', 1, 'login', NULL, NULL, '::1', '2026-05-11 15:57:36'),
(17, 'cashier', 1, 'logout', NULL, NULL, '::1', '2026-05-11 16:00:41'),
(18, 'student', 1, 'login', NULL, NULL, '::1', '2026-05-11 16:00:51'),
(19, 'student', 1, 'logout', NULL, NULL, '::1', '2026-05-11 16:04:59'),
(20, 'student', 1, 'login', NULL, NULL, '::1', '2026-05-13 09:50:32'),
(21, 'student', 1, 'logout', NULL, NULL, '::1', '2026-05-13 09:51:52'),
(22, 'cashier', 1, 'login', NULL, NULL, '127.0.0.1', '2026-05-17 13:35:16'),
(23, 'cashier', 1, 'locked', 'orders', 2, '127.0.0.1', '2026-05-17 13:40:16'),
(24, 'cashier', 1, 'status_preparing', 'orders', 2, '127.0.0.1', '2026-05-17 13:40:17'),
(25, 'cashier', 1, 'locked', 'orders', 2, '127.0.0.1', '2026-05-17 13:40:18'),
(26, 'cashier', 1, 'status_ready', 'orders', 2, '127.0.0.1', '2026-05-17 13:40:19'),
(27, 'cashier', 1, 'status_claimed', 'orders', 2, '127.0.0.1', '2026-05-17 13:40:22'),
(28, 'cashier', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-05-17 13:51:01'),
(29, 'admin', 1, 'login', NULL, NULL, '127.0.0.1', '2026-05-17 13:51:20'),
(30, 'admin', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-05-17 13:53:30'),
(31, 'cashier', 1, 'login', NULL, NULL, '127.0.0.1', '2026-05-17 13:53:49'),
(32, 'cashier', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-05-17 14:05:32'),
(33, 'cashier', 1, 'login', NULL, NULL, '::1', '2026-05-18 06:56:34'),
(34, 'cashier', 1, 'logout', NULL, NULL, '::1', '2026-05-18 06:57:50'),
(35, 'student', 1, 'login', NULL, NULL, '::1', '2026-06-09 15:05:57'),
(36, 'student', 1, 'logout', NULL, NULL, '::1', '2026-06-09 15:06:12'),
(37, 'cashier', 1, 'login', NULL, NULL, '::1', '2026-06-09 15:08:13'),
(38, 'admin', 1, 'login', NULL, NULL, '::1', '2026-06-09 15:11:46'),
(39, 'cashier', 1, 'create_walkin_order', 'orders', 3, '::1', '2026-06-09 15:13:13'),
(40, 'admin', 1, 'toggle_addon_status', 'addons', 3, '::1', '2026-06-09 15:13:18'),
(41, 'admin', 1, 'toggle_addon_status', 'addons', 3, '::1', '2026-06-09 15:13:20'),
(42, 'admin', 1, 'edit_product', 'products', 18, '::1', '2026-06-09 15:13:47'),
(43, 'admin', 1, 'edit_product', 'products', 17, '::1', '2026-06-09 15:14:05'),
(44, 'admin', 1, 'edit_product', 'products', 19, '::1', '2026-06-09 15:14:17'),
(45, 'cashier', 1, 'create_walkin_order', 'orders', 4, '::1', '2026-06-09 15:14:46'),
(46, 'admin', 1, 'edit_product', 'products', 22, '::1', '2026-06-09 15:14:50'),
(47, 'admin', 1, 'edit_product', 'products', 27, '::1', '2026-06-09 15:15:02'),
(48, 'admin', 1, 'edit_product', 'products', 27, '::1', '2026-06-09 15:15:07'),
(49, 'admin', 1, 'edit_product', 'products', 25, '::1', '2026-06-09 15:15:23'),
(50, 'admin', 1, 'edit_product', 'products', 24, '::1', '2026-06-09 15:15:36'),
(51, 'admin', 1, 'edit_product', 'products', 23, '::1', '2026-06-09 15:15:55'),
(52, 'admin', 1, 'edit_product', 'products', 23, '::1', '2026-06-09 15:16:07'),
(53, 'admin', 1, 'edit_product', 'products', 26, '::1', '2026-06-09 15:16:34'),
(54, 'admin', 1, 'edit_product', 'products', 21, '::1', '2026-06-09 15:16:48'),
(55, 'cashier', 1, 'create_walkin_order', 'orders', 5, '::1', '2026-06-09 15:16:54'),
(56, 'cashier', 1, 'create_walkin_order', 'orders', 6, '::1', '2026-06-09 15:17:31'),
(57, 'admin', 1, 'edit_product', 'products', 16, '::1', '2026-06-09 15:18:12'),
(58, 'admin', 1, 'edit_product', 'products', 28, '::1', '2026-06-09 15:19:29'),
(59, 'admin', 1, 'edit_product', 'products', 32, '::1', '2026-06-09 15:19:44'),
(60, 'admin', 1, 'edit_product', 'products', 29, '::1', '2026-06-09 15:20:03'),
(61, 'admin', 1, 'edit_product', 'products', 29, '::1', '2026-06-09 15:20:18'),
(62, 'admin', 1, 'edit_product', 'products', 30, '::1', '2026-06-09 15:20:40'),
(63, 'admin', 1, 'logout', NULL, NULL, '::1', '2026-06-09 15:25:39'),
(64, 'cashier', 1, 'logout', NULL, NULL, '::1', '2026-06-09 15:29:59'),
(65, 'admin', 2, 'login', NULL, NULL, '::1', '2026-06-09 15:32:20'),
(66, 'admin', 2, 'create_cashier', NULL, NULL, '::1', '2026-06-09 15:34:01'),
(67, 'admin', 2, 'logout', NULL, NULL, '::1', '2026-06-09 15:36:23'),
(68, 'admin', 1, 'login', NULL, NULL, '::1', '2026-06-09 15:50:30'),
(69, 'admin', 1, 'create_cashier', NULL, NULL, '::1', '2026-06-09 15:52:04'),
(70, 'admin', 1, 'logout', NULL, NULL, '::1', '2026-06-09 15:52:11'),
(71, 'cashier', 3, 'login', NULL, NULL, '::1', '2026-06-09 15:52:21'),
(72, 'cashier', 1, 'login', NULL, NULL, '::1', '2026-06-09 18:06:27'),
(73, 'cashier', 1, 'create_walkin_order', 'orders', 7, '::1', '2026-06-09 18:09:44'),
(74, 'cashier', 3, 'logout', NULL, NULL, '::1', '2026-06-09 18:09:51'),
(75, 'cashier', 1, 'logout', NULL, NULL, '::1', '2026-06-09 18:12:29'),
(76, 'admin', 2, 'login', NULL, NULL, '::1', '2026-06-09 18:15:23'),
(77, 'admin', 1, 'login', NULL, NULL, '::1', '2026-06-09 20:59:39'),
(78, 'admin', 1, 'logout', NULL, NULL, '::1', '2026-06-09 21:01:38'),
(79, 'student', 2, 'register', NULL, NULL, '::1', '2026-06-10 10:09:36'),
(80, 'student', 2, 'email_verified', NULL, NULL, '::1', '2026-06-10 10:10:56'),
(81, 'student', 2, 'login', NULL, NULL, '::1', '2026-06-10 10:11:23'),
(82, 'student', 2, 'logout', NULL, NULL, '::1', '2026-06-10 10:18:48'),
(83, 'cashier', 2, 'login', NULL, NULL, '::1', '2026-06-10 10:19:08'),
(84, 'cashier', 2, 'create_walkin_order', 'orders', 8, '::1', '2026-06-10 10:19:57'),
(85, 'cashier', 2, 'create_walkin_order', 'orders', 9, '::1', '2026-06-10 10:21:14'),
(86, 'cashier', 2, 'login', NULL, NULL, '::1', '2026-06-10 12:56:04'),
(87, 'cashier', 2, 'login', NULL, NULL, '::1', '2026-06-10 15:07:00'),
(88, 'cashier', 2, 'create_walkin_order', 'orders', 10, '::1', '2026-06-10 15:07:35'),
(89, 'cashier', 2, 'create_walkin_order', 'orders', 11, '::1', '2026-06-10 15:08:28'),
(90, 'cashier', 1, 'login', NULL, NULL, '::1', '2026-06-10 16:51:42'),
(91, 'cashier', 1, 'logout', NULL, NULL, '::1', '2026-06-10 16:52:00'),
(92, 'admin', 2, 'login', NULL, NULL, '::1', '2026-06-10 16:52:51'),
(93, 'admin', 2, 'create_cashier', NULL, NULL, '::1', '2026-06-10 16:54:45'),
(94, 'admin', 2, 'logout', NULL, NULL, '::1', '2026-06-10 17:12:14'),
(95, 'cashier', 4, 'login', NULL, NULL, '::1', '2026-06-10 17:12:31'),
(96, 'cashier', 4, 'create_walkin_order', 'orders', 12, '::1', '2026-06-10 17:15:35'),
(97, 'cashier', 4, 'logout', NULL, NULL, '::1', '2026-06-10 17:32:11'),
(98, 'cashier', 2, 'login', NULL, NULL, '::1', '2026-06-10 17:32:24'),
(99, 'cashier', 2, 'create_walkin_order', 'orders', 13, '::1', '2026-06-10 17:33:18'),
(100, 'cashier', 3, 'login', NULL, NULL, '::1', '2026-06-11 10:55:41'),
(101, 'cashier', 3, 'logout', NULL, NULL, '::1', '2026-06-11 10:56:06'),
(102, 'cashier', 4, 'login', NULL, NULL, '::1', '2026-06-11 15:38:17'),
(103, 'cashier', 4, 'create_walkin_order', 'orders', 14, '::1', '2026-06-11 15:39:23'),
(104, 'cashier', 4, 'create_walkin_order', 'orders', 15, '::1', '2026-06-11 15:40:57'),
(105, 'cashier', 4, 'create_walkin_order', 'orders', 16, '::1', '2026-06-11 16:07:02'),
(106, 'cashier', 4, 'login', NULL, NULL, '::1', '2026-06-11 17:23:54'),
(107, 'cashier', 1, 'login', NULL, NULL, '::1', '2026-08-24 14:56:46'),
(108, 'cashier', 1, 'logout', NULL, NULL, '::1', '2026-08-24 14:57:21'),
(109, 'cashier', 1, 'login', NULL, NULL, '127.0.0.1', '2026-08-27 13:29:39'),
(110, 'cashier', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-08-27 13:30:47'),
(111, 'student', 1, 'login', NULL, NULL, '127.0.0.1', '2026-08-27 13:33:34'),
(112, 'cashier', 1, 'login', NULL, NULL, '127.0.0.1', '2026-08-27 14:00:26'),
(113, 'cashier', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-08-27 14:02:30'),
(114, 'admin', 1, 'login', NULL, NULL, '127.0.0.1', '2026-08-27 14:03:17'),
(115, 'admin', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-08-27 14:10:50'),
(116, 'student', 1, 'login', NULL, NULL, '127.0.0.1', '2026-08-27 14:11:08'),
(117, 'student', 1, 'login', NULL, NULL, '127.0.0.1', '2026-08-28 19:47:07'),
(118, 'student', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-08-28 20:03:09'),
(119, 'student', 1, 'login', NULL, NULL, '127.0.0.1', '2026-08-29 16:26:10'),
(120, 'student', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-08-29 16:30:27'),
(121, 'student', 1, 'login', NULL, NULL, '127.0.0.1', '2026-08-29 16:57:27'),
(122, 'student', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-08-29 16:57:34'),
(123, 'student', 1, 'login', NULL, NULL, '127.0.0.1', '2026-08-29 16:57:52'),
(124, 'student', 1, 'login', NULL, NULL, '127.0.0.1', '2026-08-29 17:15:20'),
(125, 'student', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-08-29 17:40:55'),
(126, 'student', 1, 'login', NULL, NULL, '127.0.0.1', '2026-08-29 17:41:36'),
(127, 'student', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-08-29 17:46:04'),
(128, 'student', 1, 'login', NULL, NULL, '127.0.0.1', '2026-08-29 17:54:41'),
(129, 'student', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-08-29 17:55:02'),
(130, 'student', 1, 'login', NULL, NULL, '127.0.0.1', '2026-08-29 17:58:24'),
(131, 'student', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-08-29 18:00:13'),
(132, 'student', 1, 'login', NULL, NULL, '127.0.0.1', '2026-08-29 19:53:06'),
(133, 'student', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-08-29 20:08:28'),
(134, 'student', 1, 'login', NULL, NULL, '127.0.0.1', '2026-08-29 20:08:56'),
(135, 'student', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-08-29 20:13:24'),
(136, 'student', 1, 'login', NULL, NULL, '127.0.0.1', '2026-08-29 20:14:33'),
(137, 'student', 1, 'login', NULL, NULL, '127.0.0.1', '2026-08-30 07:19:10'),
(138, 'student', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-08-30 07:19:35'),
(139, 'student', 1, 'login', NULL, NULL, '127.0.0.1', '2026-08-30 07:21:09'),
(140, 'student', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-08-30 07:37:03'),
(141, 'student', 1, 'login', NULL, NULL, '127.0.0.1', '2026-08-30 07:37:41'),
(142, 'student', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-08-30 08:15:47'),
(143, 'student', 1, 'login', NULL, NULL, '127.0.0.1', '2026-08-30 08:15:55'),
(144, 'student', 1, 'login', NULL, NULL, '127.0.0.1', '2026-08-30 21:22:41'),
(145, 'student', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-08-30 21:31:14'),
(146, 'student', 1, 'login', NULL, NULL, '127.0.0.1', '2026-08-30 21:31:44'),
(147, 'student', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-08-30 21:40:38'),
(148, 'cashier', 1, 'login', NULL, NULL, '127.0.0.1', '2026-08-30 21:49:19'),
(149, 'cashier', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-08-30 21:49:25'),
(150, 'admin', 1, 'login', NULL, NULL, '127.0.0.1', '2026-08-30 21:49:44'),
(151, 'admin', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-08-30 21:49:57'),
(152, 'student', 1, 'login', NULL, NULL, '127.0.0.1', '2026-08-30 22:04:25'),
(153, 'student', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-08-30 22:04:47'),
(154, 'admin', 1, 'login', NULL, NULL, '127.0.0.1', '2026-08-31 17:12:49'),
(155, 'admin', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-08-31 17:14:32'),
(156, 'student', 1, 'login', NULL, NULL, '127.0.0.1', '2026-09-06 14:17:40'),
(157, 'student', 1, 'login', NULL, NULL, '127.0.0.1', '2026-09-06 14:49:23'),
(158, 'student', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-09-06 14:54:23'),
(159, 'student', 1, 'login', NULL, NULL, '127.0.0.1', '2026-09-07 13:10:40'),
(160, 'student', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-09-07 13:57:54'),
(161, 'student', 1, 'login', NULL, NULL, '127.0.0.1', '2026-09-08 07:11:21'),
(162, 'student', 1, 'login', NULL, NULL, '127.0.0.1', '2026-09-08 15:48:58'),
(163, 'student', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-09-08 15:54:56'),
(164, 'student', 1, 'login', NULL, NULL, '127.0.0.1', '2026-09-08 15:55:22'),
(165, 'student', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-09-08 15:56:19'),
(166, 'admin', 1, 'login', NULL, NULL, '127.0.0.1', '2026-09-08 16:16:18'),
(167, 'admin', 1, 'update_settings', NULL, NULL, '127.0.0.1', '2026-09-08 16:17:52'),
(168, 'admin', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-09-08 16:36:04'),
(169, 'admin', 1, 'login', NULL, NULL, '::1', '2026-09-16 08:29:08'),
(170, 'admin', 1, 'logout', NULL, NULL, '::1', '2026-09-16 08:31:00'),
(171, 'student', 1, 'login', NULL, NULL, '::1', '2026-09-16 08:31:16'),
(172, 'student', 1, 'logout', NULL, NULL, '::1', '2026-09-16 08:32:19'),
(173, 'admin', 1, 'login', NULL, NULL, '::1', '2026-09-16 08:32:38'),
(174, 'admin', 1, 'logout', NULL, NULL, '::1', '2026-09-16 08:33:22'),
(175, 'student', 1, 'login', NULL, NULL, '::1', '2026-09-16 08:44:38'),
(176, 'student', 1, 'place_preorder', 'orders', 17, '::1', '2026-09-16 08:48:42'),
(177, 'cashier', 1, 'login', NULL, NULL, '::1', '2026-09-16 08:50:02'),
(178, 'cashier', 1, 'locked', 'orders', 17, '::1', '2026-09-16 08:50:37'),
(179, 'cashier', 1, 'status_preparing', 'orders', 17, '::1', '2026-09-16 08:50:38'),
(180, 'cashier', 1, 'locked', 'orders', 17, '::1', '2026-09-16 08:50:40'),
(181, 'cashier', 1, 'status_ready', 'orders', 17, '::1', '2026-09-16 08:50:42'),
(182, 'cashier', 1, 'status_claimed', 'orders', 17, '::1', '2026-09-16 08:50:46'),
(183, 'cashier', 1, 'logout', NULL, NULL, '::1', '2026-09-16 09:48:05'),
(184, 'student', 1, 'login', NULL, NULL, '127.0.0.1', '2026-09-20 22:09:49'),
(185, 'student', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-09-20 22:10:41'),
(186, 'cashier', 1, 'login', NULL, NULL, '127.0.0.1', '2026-09-20 22:11:00'),
(187, 'cashier', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-09-20 22:11:03'),
(188, 'admin', 1, 'login', NULL, NULL, '127.0.0.1', '2026-09-20 22:11:19'),
(189, 'admin', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-09-20 22:11:26'),
(190, 'student', 1, 'login', NULL, NULL, '127.0.0.1', '2026-09-20 22:12:57'),
(191, 'student', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-09-20 22:13:21'),
(192, 'admin', 1, 'login', NULL, NULL, '127.0.0.1', '2026-09-20 22:13:38'),
(193, 'admin', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-09-20 22:14:05'),
(194, 'student', 1, 'login', NULL, NULL, '127.0.0.1', '2026-09-22 07:17:01'),
(195, 'student', 1, 'place_preorder', 'orders', 18, '127.0.0.1', '2026-09-22 07:17:40'),
(196, 'admin', 1, 'login', NULL, NULL, '::1', '2026-09-22 07:18:50'),
(197, 'admin', 1, 'logout', NULL, NULL, '::1', '2026-09-22 07:19:29'),
(198, 'cashier', 3, 'login', NULL, NULL, '::1', '2026-09-22 07:19:37'),
(199, 'cashier', 3, 'locked', 'orders', 18, '::1', '2026-09-22 07:19:52'),
(200, 'cashier', 3, 'status_preparing', 'orders', 18, '::1', '2026-09-22 07:20:04'),
(201, 'cashier', 3, 'locked', 'orders', 18, '::1', '2026-09-22 07:20:05'),
(202, 'cashier', 3, 'status_ready', 'orders', 18, '::1', '2026-09-22 07:20:09'),
(203, 'cashier', 3, 'status_claimed', 'orders', 18, '::1', '2026-09-22 07:20:14'),
(204, 'cashier', 3, 'logout', NULL, NULL, '::1', '2026-09-22 07:20:53'),
(205, 'student', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-09-22 07:42:27'),
(206, 'admin', 1, 'login', NULL, NULL, '127.0.0.1', '2026-09-24 07:53:29'),
(207, 'admin', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-09-24 07:57:29'),
(208, 'cashier', 3, 'login', NULL, NULL, '127.0.0.1', '2026-09-24 07:57:36'),
(209, 'cashier', 3, 'logout', NULL, NULL, '127.0.0.1', '2026-09-24 07:59:09'),
(210, 'admin', 1, 'login', NULL, NULL, '127.0.0.1', '2026-09-24 08:02:29'),
(211, 'admin', 1, 'create_inventory_item', 'inventory_items', 1, '127.0.0.1', '2026-09-24 08:32:33'),
(212, 'admin', 1, 'adjust_inventory', 'inventory_items', 1, '127.0.0.1', '2026-09-24 08:37:54'),
(213, 'admin', 1, 'adjust_inventory', 'inventory_items', 1, '127.0.0.1', '2026-09-24 08:38:27'),
(214, 'admin', 1, 'adjust_inventory', 'inventory_items', 1, '127.0.0.1', '2026-09-24 08:38:37'),
(215, 'admin', 1, 'adjust_inventory', 'inventory_items', 1, '127.0.0.1', '2026-09-24 08:45:35'),
(216, 'admin', 1, 'adjust_inventory', 'inventory_items', 1, '127.0.0.1', '2026-09-24 08:45:40'),
(217, 'admin', 1, 'adjust_inventory', 'inventory_items', 1, '127.0.0.1', '2026-09-24 09:11:53'),
(218, 'admin', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-09-24 09:20:29'),
(219, 'cashier', 3, 'login', NULL, NULL, '127.0.0.1', '2026-09-24 09:20:38'),
(220, 'cashier', 3, 'create_walkin_order', 'orders', 19, '127.0.0.1', '2026-09-24 09:20:54'),
(221, 'cashier', 3, 'logout', NULL, NULL, '127.0.0.1', '2026-09-24 09:21:25'),
(222, 'student', 1, 'login', NULL, NULL, '127.0.0.1', '2026-09-24 09:22:09'),
(223, 'student', 1, 'place_preorder', 'orders', 20, '127.0.0.1', '2026-09-24 09:22:39'),
(224, 'student', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-09-24 09:31:40'),
(225, 'cashier', 3, 'login', NULL, NULL, '127.0.0.1', '2026-09-24 09:31:49'),
(226, 'cashier', 3, 'locked', 'orders', 20, '127.0.0.1', '2026-09-24 09:31:56'),
(227, 'cashier', 3, 'status_preparing', 'orders', 20, '127.0.0.1', '2026-09-24 09:31:57'),
(228, 'cashier', 3, 'locked', 'orders', 20, '127.0.0.1', '2026-09-24 09:31:58'),
(229, 'cashier', 3, 'status_ready', 'orders', 20, '127.0.0.1', '2026-09-24 09:31:59'),
(230, 'cashier', 3, 'status_claimed', 'orders', 20, '127.0.0.1', '2026-09-24 09:32:04'),
(231, 'cashier', 3, 'logout', NULL, NULL, '127.0.0.1', '2026-09-24 09:32:14'),
(232, 'student', 1, 'login', NULL, NULL, '127.0.0.1', '2026-09-24 12:50:28'),
(233, 'student', 1, 'place_preorder', 'orders', 21, '127.0.0.1', '2026-09-24 12:50:55'),
(234, 'student', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-09-24 12:58:41'),
(235, 'cashier', 3, 'login', NULL, NULL, '127.0.0.1', '2026-09-24 12:59:07'),
(236, 'cashier', 3, 'locked', 'orders', 21, '127.0.0.1', '2026-09-24 12:59:13'),
(237, 'cashier', 3, 'status_preparing', 'orders', 21, '127.0.0.1', '2026-09-24 12:59:14'),
(238, 'cashier', 3, 'locked', 'orders', 21, '127.0.0.1', '2026-09-24 12:59:16'),
(239, 'cashier', 3, 'status_ready', 'orders', 21, '127.0.0.1', '2026-09-24 12:59:18'),
(240, 'cashier', 3, 'status_claimed', 'orders', 21, '127.0.0.1', '2026-09-24 12:59:21'),
(241, 'cashier', 3, 'logout', NULL, NULL, '127.0.0.1', '2026-09-24 12:59:29'),
(242, 'cashier', 3, 'login', NULL, NULL, '127.0.0.1', '2026-09-24 13:01:42'),
(243, 'cashier', 3, 'logout', NULL, NULL, '127.0.0.1', '2026-09-24 13:01:48'),
(244, 'admin', 1, 'login', NULL, NULL, '127.0.0.1', '2026-09-24 13:02:06'),
(245, 'admin', 1, 'create_inventory_item', 'inventory_items', 2, '127.0.0.1', '2026-09-24 13:03:19'),
(246, 'admin', 1, 'adjust_inventory', 'inventory_items', 2, '127.0.0.1', '2026-09-24 13:04:04'),
(247, 'admin', 1, 'adjust_inventory', 'inventory_items', 2, '127.0.0.1', '2026-09-24 13:04:30'),
(248, 'admin', 1, 'adjust_inventory', 'inventory_items', 2, '127.0.0.1', '2026-09-24 13:05:57'),
(249, 'admin', 1, 'adjust_inventory', 'inventory_items', 2, '127.0.0.1', '2026-09-24 13:06:06'),
(250, 'admin', 1, 'adjust_inventory', 'inventory_items', 2, '127.0.0.1', '2026-09-24 13:06:15'),
(251, 'admin', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-09-24 13:07:09'),
(252, 'admin', 1, 'login', NULL, NULL, '127.0.0.1', '2026-09-24 13:09:18'),
(253, 'admin', 1, 'logout', NULL, NULL, '127.0.0.1', '2026-09-24 13:23:26');

-- --------------------------------------------------------

--
-- Table structure for table `cashiers`
--

CREATE TABLE `cashiers` (
  `id` int UNSIGNED NOT NULL,
  `full_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_verified` tinyint(1) NOT NULL DEFAULT '0',
  `email_verified_at` datetime DEFAULT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'bcrypt hash',
  `pos_pin` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int UNSIGNED NOT NULL COMMENT 'admin.id who created this account',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `cashiers`
--

INSERT INTO `cashiers` (`id`, `full_name`, `username`, `email`, `email_verified`, `email_verified_at`, `password`, `pos_pin`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'Jay Mark Amilagan', 'amilagan@kapehan.com', NULL, 0, NULL, '$2y$12$mhvu78xgH30ZTpmIv5JyX.3bi8ls6OuLNNmKYPH.dy0W0VmVdWXJy', NULL, 1, 1, '2026-04-28 19:04:33', '2026-04-28 19:04:33'),
(2, 'Khit Dela Cruz', 'Khit', NULL, 0, NULL, '$2y$12$pFHalbl3XagSOTL87PN7/O4C5OUuxXsLMOUKrdMvisPyNbqaDkBwu', NULL, 1, 2, '2026-06-09 15:34:01', '2026-06-09 15:34:01'),
(3, 'Cashier Test', 'cashier@kapehan.com', NULL, 0, NULL, '$2y$12$M3N.kP3eQpYezOiFgHiqaeSg2p0I4UPwMYIZRkpod3cDVACNk5eea', NULL, 1, 1, '2026-06-09 15:52:04', '2026-06-09 15:52:04'),
(4, 'Ara Harina', 'Ara', NULL, 0, NULL, '$2y$12$ujPvaZfHqTbfuxI2BlGuReNYB7tHyDXi92V5bmX2hB5YrR2/7aZuG', NULL, 1, 2, '2026-06-10 16:54:45', '2026-06-10 16:54:45');

-- --------------------------------------------------------

--
-- Table structure for table `cashier_sessions`
--

CREATE TABLE `cashier_sessions` (
  `id` int UNSIGNED NOT NULL,
  `cashier_id` int UNSIGNED NOT NULL,
  `login_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_activity_at` datetime DEFAULT NULL,
  `logout_at` datetime DEFAULT NULL COMMENT 'NULL = still logged in',
  `logout_reason` varchar(20) DEFAULT NULL COMMENT 'manual | timeout | deactivated'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Shared physical drawer records
--
CREATE TABLE `cash_drawer_days` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `business_date` date NOT NULL,
  `opening_amount` decimal(10,2) NOT NULL,
  `opened_by` int UNSIGNED NOT NULL,
  `opened_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('open','closed') NOT NULL DEFAULT 'open',
  `closing_amount` decimal(10,2) DEFAULT NULL,
  `expected_closing_amount` decimal(10,2) DEFAULT NULL,
  `variance` decimal(10,2) DEFAULT NULL COMMENT 'Counted cash minus expected cash',
  `closed_by` int UNSIGNED DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cash_drawer_business_date` (`business_date`),
  KEY `idx_cash_drawer_status` (`status`),
  CONSTRAINT `fk_cash_drawer_opened_by` FOREIGN KEY (`opened_by`) REFERENCES `cashiers` (`id`),
  CONSTRAINT `fk_cash_drawer_closed_by` FOREIGN KEY (`closed_by`) REFERENCES `cashiers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cash_drawer_handoffs` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `drawer_day_id` int UNSIGNED NOT NULL,
  `handed_from_cashier_id` int UNSIGNED NOT NULL,
  `recorded_by` int UNSIGNED NOT NULL,
  `expected_amount` decimal(10,2) NOT NULL,
  `counted_amount` decimal(10,2) NOT NULL,
  `variance` decimal(10,2) NOT NULL,
  `status` enum('pending','confirmed','disputed','unverified') NOT NULL DEFAULT 'unverified',
  `confirmed_by` int UNSIGNED DEFAULT NULL,
  `confirmation_amount` decimal(10,2) DEFAULT NULL,
  `confirmation_variance` decimal(10,2) DEFAULT NULL COMMENT 'Incoming count minus outgoing count',
  `confirmation_note` varchar(255) DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `recorded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_drawer_handoff_day_time` (`drawer_day_id`,`recorded_at`),
  CONSTRAINT `fk_drawer_handoff_day` FOREIGN KEY (`drawer_day_id`) REFERENCES `cash_drawer_days` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_drawer_handoff_from_cashier` FOREIGN KEY (`handed_from_cashier_id`) REFERENCES `cashiers` (`id`),
  CONSTRAINT `fk_drawer_handoff_cashier` FOREIGN KEY (`recorded_by`) REFERENCES `cashiers` (`id`),
  CONSTRAINT `fk_drawer_handoff_confirmed_by` FOREIGN KEY (`confirmed_by`) REFERENCES `cashiers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cash_drawer_movements` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `drawer_day_id` int UNSIGNED NOT NULL,
  `cashier_id` int UNSIGNED NOT NULL,
  `direction` enum('in','out') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_drawer_movement_day_time` (`drawer_day_id`,`created_at`),
  CONSTRAINT `fk_drawer_movement_day` FOREIGN KEY (`drawer_day_id`) REFERENCES `cash_drawer_days` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_drawer_movement_cashier` FOREIGN KEY (`cashier_id`) REFERENCES `cashiers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `cashier_sessions`
--

INSERT INTO `cashier_sessions` (`id`, `cashier_id`, `login_at`, `logout_at`) VALUES
(1, 1, '2026-05-11 15:55:24', '2026-05-11 15:56:36'),
(2, 1, '2026-05-11 15:57:36', '2026-05-11 16:00:41'),
(3, 1, '2026-05-17 13:35:16', '2026-05-17 13:51:01'),
(4, 1, '2026-05-17 13:53:49', '2026-05-17 14:05:32'),
(5, 1, '2026-05-18 06:56:34', '2026-05-18 06:57:50'),
(6, 1, '2026-06-09 15:08:13', '2026-06-09 15:29:59'),
(7, 3, '2026-06-09 15:52:21', '2026-06-09 18:09:51'),
(8, 1, '2026-06-09 18:06:27', '2026-06-09 18:12:29'),
(9, 2, '2026-06-10 10:19:08', NULL),
(10, 2, '2026-06-10 12:56:04', NULL),
(11, 2, '2026-06-10 15:07:00', NULL),
(12, 1, '2026-06-10 16:51:42', '2026-06-10 16:52:00'),
(13, 4, '2026-06-10 17:12:31', '2026-06-10 17:32:11'),
(14, 2, '2026-06-10 17:32:24', NULL),
(15, 3, '2026-06-11 10:55:41', '2026-06-11 10:56:06'),
(16, 4, '2026-06-11 15:38:17', NULL),
(17, 4, '2026-06-11 17:23:54', NULL),
(18, 1, '2026-08-24 14:56:46', '2026-08-24 14:57:21'),
(19, 1, '2026-08-27 13:29:39', '2026-08-27 13:30:47'),
(20, 1, '2026-08-27 14:00:26', '2026-08-27 14:02:30'),
(21, 1, '2026-08-30 21:49:19', '2026-08-30 21:49:25'),
(22, 1, '2026-09-16 08:50:02', '2026-09-16 09:48:05'),
(23, 1, '2026-09-20 22:11:00', '2026-09-20 22:11:03'),
(24, 3, '2026-09-22 07:19:37', '2026-09-22 07:20:53'),
(25, 3, '2026-09-24 07:57:36', '2026-09-24 07:59:09'),
(26, 3, '2026-09-24 09:20:38', '2026-09-24 09:21:25'),
(27, 3, '2026-09-24 09:31:49', '2026-09-24 09:32:14'),
(28, 3, '2026-09-24 12:59:07', '2026-09-24 12:59:29'),
(29, 3, '2026-09-24 13:01:42', '2026-09-24 13:01:48');

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int UNSIGNED NOT NULL,
  `parent_id` int UNSIGNED DEFAULT NULL,
  `name` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` tinyint NOT NULL DEFAULT '0',
  `icon` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `parent_id`, `name`, `sort_order`, `icon`, `created_at`) VALUES
(1, NULL, 'Coffee', 1, NULL, '2026-04-28 19:11:46'),
(2, 1, 'Signature Coffee', 1, NULL, '2026-04-28 19:11:46'),
(3, 1, 'Hot Coffee', 2, NULL, '2026-04-28 19:21:02'),
(4, 1, 'Iced Coffee', 3, NULL, '2026-04-28 20:21:43'),
(5, NULL, 'Other Drinks', 1, NULL, '2026-04-28 22:05:03'),
(6, 5, 'Matcha Series', 1, NULL, '2026-04-28 22:05:03'),
(7, 5, 'Non-Coffee', 2, NULL, '2026-04-28 22:07:09'),
(8, 5, 'Milktea', 3, NULL, '2026-04-28 22:11:04'),
(9, 5, 'Cocktails', 4, NULL, '2026-04-28 22:14:23');

-- --------------------------------------------------------

--
-- Table structure for table `email_otps`
--

CREATE TABLE `email_otps` (
  `id` int UNSIGNED NOT NULL,
  `user_type` enum('student','faculty','cashier') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `otp` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `purpose` enum('verification','password_reset') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `attempts` tinyint NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `email_otps`
--

INSERT INTO `email_otps` (`id`, `user_type`, `user_id`, `email`, `otp`, `purpose`, `expires_at`, `used_at`, `attempts`, `created_at`) VALUES
(1, 'student', 2, 'delacruzkhitm@gmail.com', '091227', 'verification', '2026-06-10 02:24:29', '2026-06-10 10:10:55', 0, '2026-06-10 10:09:29');

-- --------------------------------------------------------

--
-- Table structure for table `faculty`
--

CREATE TABLE `faculty` (
  `id` int UNSIGNED NOT NULL,
  `full_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `faculty_id_no` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'bcrypt hash',
  `email_verified` tinyint(1) NOT NULL DEFAULT '0',
  `email_verified_at` datetime DEFAULT NULL,
  `id_declaration` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Faculty agreed to ID declaration',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `no_show_count` int UNSIGNED NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_items`
--

CREATE TABLE `inventory_items` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `unit` varchar(20) NOT NULL COMMENT 'g, kg, ml, L, pcs',
  `quantity_on_hand` decimal(12,3) NOT NULL DEFAULT '0.000',
  `reorder_level` decimal(12,3) NOT NULL DEFAULT '0.000',
  `cost_per_unit` decimal(10,2) NOT NULL DEFAULT '0.00',
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `inventory_items`
--

INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES
('1', 'Fresh milk', 'L', '19.820', '5.000', '105.00', '2026-10-19 07:59:03'),
('2', '16oz hot cups', 'pcs', '500.000', '200.000', '2.80', '2026-10-19 07:55:54'),
('3', 'Espresso coffee beans', 'kg', '4.975', '1.500', '1000.00', '2026-10-19 08:01:22'),
('4', 'Deacf coffee beans', 'kg', '1.000', '0.300', '1400.00', '2026-10-09 09:06:44'),
('5', 'Oat milk', 'L', '6.000', '2.000', '180.00', '2026-10-09 09:06:26'),
('6', 'Soy milk', 'L', '6.000', '2.000', '130.00', '2026-10-09 09:06:20'),
('7', 'Condensed milk', 'L', '4.000', '1.000', '170.00', '2026-10-09 09:06:48'),
('8', 'Evaporated milk', 'L', '4.000', '1.000', '120.00', '2026-10-09 09:04:52'),
('9', 'Whipping cream', 'L', '3.000', '1.000', '250.00', '2026-10-09 09:05:04'),
('10', 'Vanilla syrup', 'L', '2.000', '0.500', '350.00', '2026-10-09 09:05:18'),
('11', 'Caramel syrup', 'L', '2.000', '0.500', '350.00', '2026-10-09 09:05:29'),
('12', 'Hazelnut syrup', 'L', '2.000', '0.500', '380.00', '2026-10-09 09:05:42'),
('13', 'Sugar syrup', 'L', '3.000', '1.000', '150.00', '2026-10-09 09:05:53'),
('14', 'Caramel sauce', 'L', '2.000', '0.500', '450.00', '2026-10-09 09:06:15'),
('15', 'Chocolate sauce', 'L', '2.000', '0.500', '400.00', '2026-10-09 09:07:07'),
('16', 'White chocolate sauce', 'L', '2.000', '0.500', '450.00', '2026-10-09 09:07:26'),
('17', 'Matcha powder', 'kg', '1.000', '0.250', '1800.00', '2026-10-19 07:43:03'),
('18', 'Chocolate powder', 'kg', '0.980', '0.250', '500.00', '2026-10-19 07:59:03'),
('19', 'Cocoa powder', 'kg', '0.500', '0.150', '700.00', '2026-10-09 09:08:49'),
('20', 'Black tea leaves', 'kg', '0.500', '0.150', '800.00', '2026-10-09 09:09:07'),
('21', 'Green tea leaves', 'kg', '0.500', '0.150', '1000.00', '2026-10-09 09:09:22'),
('22', 'Chai concentrate', 'L', '2.000', '0.500', '250.00', '2026-10-09 09:09:35'),
('23', 'Strawberry puree', 'L', '2.000', '0.500', '350.00', '2026-10-09 09:09:50'),
('24', 'Blueberry syrup', 'L', '2.000', '0.500', '400.00', '2026-10-09 09:10:05'),
('25', 'Fruit jelly or popping boba', 'kg', '2.000', '0.500', '250.00', '2026-10-09 09:10:19'),
('26', 'Ice', 'kg', '20.000', '5.000', '5.00', '2026-10-09 09:10:41'),
('27', '16oz cups', 'pcs', '999.000', '250.000', '2.20', '2026-10-19 07:59:03'),
('28', '22oz cups', 'pcs', '999.000', '250.000', '2.80', '2026-10-19 08:01:22'),
('29', 'Straws', 'pcs', '1000.000', '250.000', '0.45', '2026-10-09 09:18:21'),
('30', 'Cup lids', 'pcs', '1500.000', '350.000', '0.90', '2026-10-09 09:18:45'),
('31', 'Cup sleeves', 'pcs', '500.000', '100.000', '0.70', '2026-10-09 09:19:00'),
('32', 'Stirrers', 'pcs', '500.000', '100.000', '0.20', '2026-10-09 09:19:43'),
('33', 'Napkins', 'pcs', '2000.000', '500.000', '0.10', '2026-10-09 09:19:56'),
('34', 'Cup carriers', 'pcs', '200.000', '50.000', '4.50', '2026-10-09 09:20:16'),
('35', 'Takeaway bags', 'pcs', '300.000', '75.000', '3.00', '2026-10-09 09:20:31'),
('36', 'Order seal stickers', 'pcs', '1000.000', '250.000', '0.15', '2026-10-09 09:20:53');

-- --------------------------------------------------------

--
-- Table structure for table `inventory_log`
--

CREATE TABLE `inventory_log` (
  `id` int UNSIGNED NOT NULL,
  `inventory_item_id` int UNSIGNED NOT NULL,
  `change_amount` decimal(12,3) NOT NULL COMMENT 'negative = deduction, positive = restock',
  `reason` varchar(30) NOT NULL COMMENT 'sale, restock, waste, correction',
  `order_id` int UNSIGNED DEFAULT NULL,
  `actor_role` varchar(20) DEFAULT NULL,
  `actor_id` int UNSIGNED DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_order_deductions`
--

CREATE TABLE `inventory_order_deductions` (
  `order_id` int UNSIGNED NOT NULL,
  `actor_role` varchar(20) DEFAULT NULL,
  `actor_id` int UNSIGNED DEFAULT NULL,
  `processed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Table structure for table `inventory_order_reservations`
--

CREATE TABLE `inventory_order_reservations` (
  `order_id` int UNSIGNED NOT NULL,
  `inventory_item_id` int UNSIGNED NOT NULL,
  `reserved_quantity` decimal(12,3) NOT NULL,
  `actor_role` varchar(20) DEFAULT NULL,
  `actor_id` int UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`order_id`,`inventory_item_id`),
  KEY `idx_inventory_reservation_item` (`inventory_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `inventory_order_restorations`
--

CREATE TABLE `inventory_order_restorations` (
  `order_id` int UNSIGNED NOT NULL,
  `actor_role` varchar(20) DEFAULT NULL,
  `actor_id` int UNSIGNED DEFAULT NULL,
  `restored_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `inventory_log`
--

INSERT INTO `inventory_log` (`id`, `inventory_item_id`, `change_amount`, `reason`, `order_id`, `actor_role`, `actor_id`, `created_at`) VALUES
(1, 1, 20.00, 'restock', NULL, 'admin', 1, '2026-09-24 08:32:33'),
(2, 1, 10.00, 'restock', NULL, 'admin', 1, '2026-09-24 08:37:54'),
(3, 1, -5.00, 'waste', NULL, 'admin', 1, '2026-09-24 08:38:27'),
(4, 1, -1.00, 'correction', NULL, 'admin', 1, '2026-09-24 08:38:37'),
(5, 1, 20.00, 'correction', NULL, 'admin', 1, '2026-09-24 08:45:35'),
(6, 1, -40.00, 'correction', NULL, 'admin', 1, '2026-09-24 08:45:40'),
(7, 1, 10.00, 'restock', NULL, 'admin', 1, '2026-09-24 09:11:53'),
(8, 2, 20.00, 'restock', NULL, 'admin', 1, '2026-09-24 13:03:19'),
(9, 2, -10.00, 'waste', NULL, 'admin', 1, '2026-09-24 13:04:04'),
(10, 2, 30.00, 'restock', NULL, 'admin', 1, '2026-09-24 13:04:30'),
(11, 2, 39.00, 'restock', NULL, 'admin', 1, '2026-09-24 13:05:57'),
(12, 2, -39.00, 'correction', NULL, 'admin', 1, '2026-09-24 13:06:06'),
(13, 2, -39.00, 'correction', NULL, 'admin', 1, '2026-09-24 13:06:15');

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `identifier_hash` char(32) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'md5(identifier) — same identifier the app already used as a session key',
  `attempts` int UNSIGNED NOT NULL DEFAULT '0',
  `locked_until` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Persistent brute-force throttle, replaces the old session-only version';

--
-- Dumping data for table `login_attempts`
--

INSERT INTO `login_attempts` (`identifier_hash`, `attempts`, `locked_until`, `updated_at`) VALUES
('4529ed646254ef7d324177a84ccec230', 1, NULL, '2026-09-24 01:21:59'),
('4b9bba4fbdb7be733edf5fa49e5194cb', 1, NULL, '2026-09-24 05:09:09'),
('56517685ec1abf3d577d9c46a898378e', 1, NULL, '2026-09-24 05:01:35');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int UNSIGNED NOT NULL,
  `order_number` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Human-readable e.g. ORD-20240101-0001',
  `order_type` enum('walk-in','pre-order') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','preparing','ready','claimed','cancelled','no_show') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `student_id` int UNSIGNED DEFAULT NULL COMMENT 'NULL for walk-in, can also reference faculty',
  `faculty_id` int UNSIGNED DEFAULT NULL,
  `cashier_id` int UNSIGNED DEFAULT NULL COMMENT 'NULL until cashier processes',
  `total_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `locked_by` int UNSIGNED DEFAULT NULL COMMENT 'Cashier ID who locked this order for preparation',
  `locked_at` datetime DEFAULT NULL COMMENT 'When the order was locked',
  `lock_expire_at` datetime DEFAULT NULL COMMENT 'When the lock expires (auto-unlock)',
  `pickup_time` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pickup_date` date DEFAULT NULL,
  `customer_arrived_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `order_number`, `order_type`, `status`, `student_id`, `faculty_id`, `cashier_id`, `total_amount`, `notes`, `created_at`, `updated_at`, `locked_by`, `locked_at`, `lock_expire_at`, `pickup_time`, `pickup_date`, `customer_arrived_at`) VALUES
(1, 'ORD-20260511-0001', 'pre-order', 'claimed', 1, NULL, 1, 155.00, '', '2026-05-11 15:55:05', '2026-05-11 15:56:33', NULL, NULL, NULL, 'ASAP', NULL, NULL),
(2, 'ORD-20260511-0002', 'pre-order', 'claimed', 1, NULL, 1, 105.00, '', '2026-05-11 15:57:24', '2026-05-17 13:40:22', NULL, NULL, NULL, '18:00', NULL, NULL),
(3, 'ORD-20260609-0001', 'walk-in', 'claimed', NULL, NULL, 1, 75.00, NULL, '2026-06-09 15:13:13', '2026-06-09 15:13:13', NULL, NULL, NULL, NULL, NULL, NULL),
(4, 'ORD-20260609-0002', 'walk-in', 'claimed', NULL, NULL, 1, 110.00, NULL, '2026-06-09 15:14:46', '2026-06-09 15:14:46', NULL, NULL, NULL, NULL, NULL, NULL),
(5, 'ORD-20260609-0003', 'walk-in', 'claimed', NULL, NULL, 1, 500.00, NULL, '2026-06-09 15:16:54', '2026-06-09 15:16:54', NULL, NULL, NULL, NULL, NULL, NULL),
(6, 'ORD-20260609-0004', 'walk-in', 'claimed', NULL, NULL, 1, 125.00, NULL, '2026-06-09 15:17:31', '2026-06-09 15:17:31', NULL, NULL, NULL, NULL, NULL, NULL),
(7, 'ORD-20260609-0005', 'walk-in', 'claimed', NULL, NULL, 1, 240.00, NULL, '2026-06-09 18:09:44', '2026-06-09 18:09:44', NULL, NULL, NULL, NULL, NULL, NULL),
(8, 'ORD-20260610-0001', 'walk-in', 'claimed', NULL, NULL, 2, 160.00, NULL, '2026-06-10 10:19:56', '2026-06-10 10:19:56', NULL, NULL, NULL, NULL, NULL, NULL),
(9, 'ORD-20260610-0002', 'walk-in', 'claimed', NULL, NULL, 2, 55.00, NULL, '2026-06-10 10:21:14', '2026-06-10 10:21:14', NULL, NULL, NULL, NULL, NULL, NULL),
(10, 'ORD-20260610-0003', 'walk-in', 'claimed', NULL, NULL, 2, 115.00, NULL, '2026-06-10 15:07:35', '2026-06-10 15:07:35', NULL, NULL, NULL, NULL, NULL, NULL),
(11, 'ORD-20260610-0004', 'walk-in', 'claimed', NULL, NULL, 2, 55.00, NULL, '2026-06-10 15:08:28', '2026-06-10 15:08:28', NULL, NULL, NULL, NULL, NULL, NULL),
(12, 'ORD-20260610-0005', 'walk-in', 'claimed', NULL, NULL, 4, 125.00, NULL, '2026-06-10 17:15:35', '2026-06-10 17:15:35', NULL, NULL, NULL, NULL, NULL, NULL),
(13, 'ORD-20260610-0006', 'walk-in', 'claimed', NULL, NULL, 2, 125.00, NULL, '2026-06-10 17:33:18', '2026-06-10 17:33:18', NULL, NULL, NULL, NULL, NULL, NULL),
(14, 'ORD-20260611-0001', 'walk-in', 'claimed', NULL, NULL, 4, 190.00, NULL, '2026-06-11 15:39:23', '2026-06-11 15:39:23', NULL, NULL, NULL, NULL, NULL, NULL),
(15, 'ORD-20260611-0002', 'walk-in', 'claimed', NULL, NULL, 4, 135.00, NULL, '2026-06-11 15:40:57', '2026-06-11 15:40:57', NULL, NULL, NULL, NULL, NULL, NULL),
(16, 'ORD-20260611-0003', 'walk-in', 'claimed', NULL, NULL, 4, 115.00, NULL, '2026-06-11 16:07:02', '2026-06-11 16:07:02', NULL, NULL, NULL, NULL, NULL, NULL),
(17, 'ORD-20260916-0001', 'pre-order', 'claimed', 1, NULL, 1, 145.00, '', '2026-09-16 08:48:42', '2026-09-16 08:50:46', NULL, NULL, NULL, '07:00', '2026-09-16', NULL),
(18, 'ORD-20260922-0001', 'pre-order', 'claimed', 1, NULL, 3, 280.00, '', '2026-09-22 07:17:40', '2026-09-22 07:20:14', NULL, NULL, NULL, '09:30', '2026-09-23', NULL),
(19, 'ORD-20260924-0001', 'walk-in', 'claimed', NULL, NULL, 3, 395.00, NULL, '2026-09-24 09:20:54', '2026-09-24 09:20:54', NULL, NULL, NULL, NULL, NULL, NULL),
(20, 'ORD-20260924-0002', 'pre-order', 'claimed', 1, NULL, 3, 105.00, '', '2026-09-24 09:22:39', '2026-09-24 09:32:04', NULL, NULL, NULL, '09:30', '2026-09-24', NULL),
(21, 'ORD-20260924-0003', 'pre-order', 'claimed', 1, NULL, 3, 395.00, '', '2026-09-24 12:50:55', '2026-09-24 12:59:21', NULL, NULL, NULL, '13:00', '2026-09-24', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `order_details`
--

CREATE TABLE `order_details` (
  `id` int UNSIGNED NOT NULL,
  `order_id` int UNSIGNED NOT NULL,
  `product_id` int UNSIGNED NOT NULL,
  `quantity` tinyint NOT NULL,
  `price_at_time` decimal(8,2) NOT NULL COMMENT 'Snapshot price at time of order',
  `subtotal` decimal(10,2) NOT NULL COMMENT 'quantity * price_at_time',
  `customization_note` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'e.g. Large · Less Sugar · +Oat Milk, +Extra Shot'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `order_details`
--

INSERT INTO `order_details` (`id`, `order_id`, `product_id`, `quantity`, `price_at_time`, `subtotal`, `customization_note`) VALUES
(1, 1, 3, 1, 155.00, 155.00, '16oz · Full Sugar'),
(2, 2, 33, 1, 105.00, 105.00, '16oz · Full Sugar'),
(3, 3, 39, 1, 75.00, 75.00, '22oz · Full Sugar'),
(4, 4, 39, 1, 55.00, 55.00, '16oz · Full Sugar'),
(5, 4, 38, 1, 55.00, 55.00, '16oz · Full Sugar'),
(6, 5, 33, 1, 125.00, 125.00, '22oz · Full Sugar'),
(7, 5, 31, 1, 145.00, 145.00, '16oz · Full Sugar'),
(8, 5, 20, 2, 115.00, 230.00, '16oz · Full Sugar'),
(9, 6, 25, 1, 125.00, 125.00, '16oz · Full Sugar'),
(10, 7, 22, 1, 115.00, 115.00, '16oz · Full Sugar'),
(11, 7, 25, 1, 125.00, 125.00, '16oz · Full Sugar'),
(12, 8, 33, 1, 105.00, 105.00, '16oz · Full Sugar'),
(13, 8, 38, 1, 55.00, 55.00, '16oz · Full Sugar'),
(14, 9, 41, 1, 55.00, 55.00, '16oz · Full Sugar'),
(15, 10, 20, 1, 115.00, 115.00, '16oz · Full Sugar'),
(16, 11, 41, 1, 55.00, 55.00, '16oz · Full Sugar'),
(17, 12, 26, 1, 125.00, 125.00, '16oz · Full Sugar'),
(18, 13, 26, 1, 125.00, 125.00, '16oz · Full Sugar'),
(19, 14, 9, 2, 95.00, 190.00, '16oz · Full Sugar'),
(20, 15, 29, 1, 135.00, 135.00, '16oz · Full Sugar'),
(21, 16, 20, 1, 115.00, 115.00, '16oz · Full Sugar'),
(22, 17, 4, 1, 145.00, 145.00, '16oz \\u00b7 Full Sugar'),
(23, 18, 30, 1, 135.00, 135.00, '16oz \\u00b7 Full Sugar'),
(24, 18, 4, 1, 145.00, 145.00, '16oz \\u00b7 Full Sugar'),
(25, 19, 4, 1, 145.00, 145.00, '16oz · Full Sugar'),
(26, 19, 2, 1, 145.00, 145.00, '16oz · Full Sugar'),
(27, 19, 10, 1, 105.00, 105.00, '16oz · Full Sugar'),
(28, 20, 33, 1, 105.00, 105.00, '16oz \\u00b7 Full Sugar'),
(29, 21, 30, 1, 135.00, 135.00, '16oz - Full Sugar'),
(30, 21, 32, 1, 125.00, 125.00, '16oz - Full Sugar'),
(31, 21, 29, 1, 135.00, 135.00, '16oz - Full Sugar');

-- --------------------------------------------------------

--
-- Table structure for table `order_feedback`
--

CREATE TABLE `order_feedback` (
  `id` int UNSIGNED NOT NULL,
  `order_id` int UNSIGNED NOT NULL COMMENT 'One feedback per order',
  `student_id` int UNSIGNED NOT NULL,
  `faculty_id` int UNSIGNED DEFAULT NULL,
  `cashier_id` int UNSIGNED DEFAULT NULL COMMENT 'NULL for walk-in orders processed by unknown cashier',
  `rating` tinyint NOT NULL,
  `comment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int UNSIGNED NOT NULL,
  `order_id` int UNSIGNED NOT NULL COMMENT 'One payment per order',
  `drawer_day_id` int UNSIGNED DEFAULT NULL,
  `payment_method` enum('cash','online','GCash','PayMaya','Online Banking') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount_paid` decimal(10,2) NOT NULL,
  `change_given` decimal(10,2) NOT NULL DEFAULT '0.00',
  `payment_status` enum('pending','paid','refunded','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `reference_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'For online payments (GCash, PayMaya, etc.)',
  `paid_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `order_id`, `payment_method`, `amount_paid`, `change_given`, `payment_status`, `reference_number`, `paid_at`, `created_at`, `updated_at`) VALUES
(1, 1, 'GCash', 155.00, 0.00, 'paid', '98538158264728', '2026-05-11 15:55:05', '2026-05-11 15:55:05', '2026-05-11 15:55:05'),
(2, 2, 'GCash', 105.00, 0.00, 'paid', '64826384629947', '2026-05-11 15:57:24', '2026-05-11 15:57:24', '2026-05-11 15:57:24'),
(3, 3, 'cash', 75.00, 0.00, 'paid', NULL, '2026-06-09 15:13:13', '2026-06-09 15:13:13', '2026-06-09 15:13:13'),
(4, 4, 'cash', 110.00, 0.00, 'paid', NULL, '2026-06-09 15:14:46', '2026-06-09 15:14:46', '2026-06-09 15:14:46'),
(5, 5, 'cash', 500.00, 0.00, 'paid', NULL, '2026-06-09 15:16:54', '2026-06-09 15:16:54', '2026-06-09 15:16:54'),
(6, 6, 'cash', 125.00, 0.00, 'paid', NULL, '2026-06-09 15:17:31', '2026-06-09 15:17:31', '2026-06-09 15:17:31'),
(7, 7, 'GCash', 240.00, 0.00, 'paid', '1234567890', '2026-06-09 18:09:44', '2026-06-09 18:09:44', '2026-06-09 18:09:44'),
(8, 8, 'cash', 160.00, 0.00, 'paid', NULL, '2026-06-10 10:19:57', '2026-06-10 10:19:57', '2026-06-10 10:19:57'),
(9, 9, 'GCash', 55.00, 0.00, 'paid', '12345678', '2026-06-10 10:21:14', '2026-06-10 10:21:14', '2026-06-10 10:21:14'),
(10, 10, 'cash', 115.00, 0.00, 'paid', NULL, '2026-06-10 15:07:35', '2026-06-10 15:07:35', '2026-06-10 15:07:35'),
(11, 11, 'cash', 55.00, 0.00, 'paid', NULL, '2026-06-10 15:08:28', '2026-06-10 15:08:28', '2026-06-10 15:08:28'),
(12, 12, 'GCash', 125.00, 0.00, 'paid', '3041 718 703362', '2026-06-10 17:15:35', '2026-06-10 17:15:35', '2026-06-10 17:15:35'),
(13, 13, 'cash', 125.00, 0.00, 'paid', NULL, '2026-06-10 17:33:18', '2026-06-10 17:33:18', '2026-06-10 17:33:18'),
(14, 14, 'cash', 190.00, 0.00, 'paid', NULL, '2026-06-11 15:39:23', '2026-06-11 15:39:23', '2026-06-11 15:39:23'),
(15, 15, 'cash', 135.00, 0.00, 'paid', NULL, '2026-06-11 15:40:57', '2026-06-11 15:40:57', '2026-06-11 15:40:57'),
(16, 16, 'cash', 115.00, 0.00, 'paid', NULL, '2026-06-11 16:07:02', '2026-06-11 16:07:02', '2026-06-11 16:07:02'),
(17, 17, 'cash', 145.00, 0.00, 'pending', NULL, NULL, '2026-09-16 08:48:42', '2026-09-16 08:48:42'),
(18, 18, 'GCash', 280.00, 0.00, 'paid', '897123049871234', '2026-09-22 07:17:40', '2026-09-22 07:17:40', '2026-09-22 07:17:40'),
(19, 19, 'cash', 400.00, 5.00, 'paid', NULL, '2026-09-24 09:20:54', '2026-09-24 09:20:54', '2026-09-24 09:20:54'),
(20, 20, 'GCash', 105.00, 0.00, 'paid', '9012837490123874', '2026-09-24 09:22:39', '2026-09-24 09:22:39', '2026-09-24 09:22:39'),
(21, 21, 'GCash', 395.00, 0.00, 'paid', '982772894789', '2026-09-24 12:50:55', '2026-09-24 12:50:55', '2026-09-24 12:50:55');

-- --------------------------------------------------------

--
-- Table structure for table `payment_denominations`
--

CREATE TABLE `payment_denominations` (
  `id` int UNSIGNED NOT NULL,
  `payment_id` int UNSIGNED NOT NULL,
  `denomination` decimal(8,2) NOT NULL COMMENT 'e.g. 1000, 500, 0.50',
  `quantity` smallint UNSIGNED NOT NULL DEFAULT '0',
  `subtotal` decimal(10,2) GENERATED ALWAYS AS ((`denomination` * `quantity`)) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bill and coin breakdown for cash payments';

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int UNSIGNED NOT NULL,
  `category_id` int UNSIGNED NOT NULL,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `has_sizes` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = show Small/Medium/Large size picker',
  `has_sugar` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = show sugar level picker',
  `has_addons` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = show add-ons checkboxes',
  `price` decimal(8,2) NOT NULL,
  `image_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `created_at`, `updated_at`) VALUES
(1, 2, 'Salt Coffee Kluea', '', 1, 1, 0, 145.00, '5b5668ceb80c88c0f5bfd8b48e055234.jpg', 1, '2026-04-28 19:11:46', '2026-04-28 19:11:46'),
(2, 2, 'Vanilla Cold Foam', '', 1, 1, 0, 145.00, '8407445eb927a656b65b89cb95b9706e.jpg', 1, '2026-04-28 19:11:46', '2026-04-28 19:11:46'),
(3, 2, 'Barista Choice', '', 1, 1, 0, 155.00, NULL, 1, '2026-04-28 19:11:46', '2026-04-28 19:11:46'),
(4, 2, 'Chocolate Hazelnut', '', 1, 1, 0, 145.00, '41c8abdc5525fa6aa505b3df6323d992.jpg', 1, '2026-04-28 19:11:46', '2026-04-28 19:11:46'),
(5, 2, 'Tiramisu Latte', '', 1, 1, 0, 145.00, 'f5f5588d1adb9db3e61af166a9137c69.webp', 1, '2026-04-28 19:11:46', '2026-04-28 19:11:46'),
(6, 2, 'Biscoff Latte', '', 1, 1, 0, 155.00, 'b12ca8f51705c25bc422d8c5db7250f0.jpg', 1, '2026-04-28 19:11:46', '2026-04-28 19:11:46'),
(7, 3, 'Americano', '', 1, 1, 0, 75.00, 'e872e618806fdd77b5dfcf94574304aa.webp', 1, '2026-04-28 19:21:02', '2026-04-28 19:21:02'),
(8, 3, 'Cappuccino', '', 1, 1, 0, 85.00, 'c78b9446ef02ee615ca2d7c6f06ada6f.jpg', 1, '2026-04-28 19:21:55', '2026-04-28 19:21:55'),
(9, 3, 'Vietnamese', '', 1, 1, 0, 95.00, '91a778e2a35a18eaf43db18b6e1955ee.webp', 1, '2026-04-28 19:22:52', '2026-04-28 19:22:52'),
(10, 3, 'Hot Mocha', '', 1, 1, 0, 105.00, '77e0db82181247ec1c2fbe194c50b52f.png', 1, '2026-04-28 20:01:11', '2026-04-28 20:01:11'),
(11, 3, 'White Mocha', '', 1, 1, 0, 110.00, 'f574da67b282aae975f29d9752da7baf.jpg', 1, '2026-04-28 20:05:34', '2026-04-28 20:05:45'),
(12, 3, 'Hot Salted Latte', '', 1, 1, 0, 110.00, '1a987a0657182d0bc17adc1f8a0f27e0.jpg', 1, '2026-04-28 20:06:40', '2026-04-28 20:06:40'),
(13, 3, 'Hot Caramel Macchiato', '', 1, 1, 0, 110.00, '5907369f84859e186588e84d540b2a3e.jpg', 1, '2026-04-28 20:07:24', '2026-04-28 20:07:24'),
(14, 3, 'Hot Hazelnut', '', 1, 1, 0, 105.00, 'b3b5152f12c144012ac9443fb0ddc7a2.webp', 1, '2026-04-28 20:09:51', '2026-04-28 20:09:51'),
(15, 3, 'Hot Vanilla', '', 1, 1, 0, 105.00, '2352288ba9446ae7b8b2300e041b4c96.jpg', 1, '2026-04-28 20:10:29', '2026-04-28 20:10:29'),
(16, 3, 'Matcha', '', 1, 1, 0, 120.00, '6124719ed39e98ce8c9423ab34960257.webp', 1, '2026-04-28 20:12:01', '2026-06-09 15:18:12'),
(17, 4, 'Flat White', '', 1, 1, 0, 85.00, '39ad7d6d55f6a3433cafeb27ae472182.jpg', 1, '2026-04-28 20:21:43', '2026-06-09 15:14:05'),
(18, 4, 'Iced Americano', '', 1, 1, 0, 65.00, 'afb1df4b4869433ac55afd910d7a861a.webp', 1, '2026-04-28 20:21:43', '2026-06-09 15:13:47'),
(19, 4, 'Iced Latte', '', 1, 1, 0, 95.00, '510b8e568ea333ea9ee29010cf4be9fb.jpg', 1, '2026-04-28 20:21:43', '2026-06-09 15:14:17'),
(20, 4, 'Spanish Latte', '', 1, 1, 0, 115.00, '706d8c0e0326d2bac68e1b8b0201a195.jpg', 1, '2026-04-28 20:21:43', '2026-04-28 20:21:43'),
(21, 4, 'Iced Mocha', '', 1, 1, 0, 125.00, '22ebfad9dae580d58ff8412dfb5824c6.jpg', 1, '2026-04-28 20:21:43', '2026-06-09 15:16:48'),
(22, 4, 'Vanilla Iced', '', 1, 1, 0, 115.00, '9c715a08505c00d7ef2409c8ff088249.jpg', 1, '2026-04-28 20:21:43', '2026-06-09 15:14:50'),
(23, 4, 'White Mocha Latte', '', 1, 1, 0, 125.00, '125b5bc50b05df32409e32af3814ca47.png', 1, '2026-04-28 20:21:43', '2026-06-09 15:16:07'),
(24, 4, 'Caramel Macchiato', '', 1, 1, 0, 125.00, '503311bfbbcf7428a7c234922aa4a4e9.jpg', 1, '2026-04-28 20:21:43', '2026-06-09 15:15:36'),
(25, 4, 'Hazelnut Latte', '', 1, 1, 0, 125.00, '9b2f4c5477373c3592f31f895496901f.jpg', 1, '2026-04-28 20:24:47', '2026-06-09 15:15:23'),
(26, 4, 'Salted Caramel Latte', '', 1, 1, 0, 125.00, '6c1cf7a7e6ab59bd7164dc9e73c52aec.jpg', 1, '2026-04-28 20:25:10', '2026-06-09 15:16:34'),
(27, 4, 'Vietnamese Ice', '', 1, 1, 0, 120.00, '0ab9c6e6acd937e93bd6c9e9d8d57bf7.jpg', 1, '2026-04-28 20:25:33', '2026-06-09 15:15:07'),
(28, 6, 'White Matcha', '', 1, 1, 0, 145.00, '10e3617bf156dc2ab3d26f10db31443b.jpg', 1, '2026-04-28 22:05:03', '2026-06-09 15:19:29'),
(29, 6, 'Matcha Sea Salt', '', 1, 1, 0, 135.00, '4af41071142a32e1179632ef2cb32622.jpg', 1, '2026-04-28 22:05:03', '2026-06-09 15:20:18'),
(30, 6, 'Espresso Matcha', '', 1, 1, 0, 135.00, '32eb31755222089f976b24c93eb4fbd2.jpg', 1, '2026-04-28 22:05:03', '2026-06-09 15:20:40'),
(31, 6, 'Strawberry Matcha', '', 1, 1, 0, 145.00, '9d12e567f8ca4a8f9c5bcf5ae8db9e41.jpg', 1, '2026-04-28 22:05:03', '2026-04-28 22:05:03'),
(32, 6, 'Matcha Latte', '', 1, 1, 0, 125.00, '916f11b41fa6e6d72daeb7516544aa80.webp', 1, '2026-04-28 22:05:03', '2026-06-09 15:19:44'),
(33, 7, 'Dark Chocolate', '', 1, 1, 0, 105.00, '6ab6ec1dc71d89bd747e1ba4ad8b3fc5.jpg', 1, '2026-04-28 22:07:09', '2026-04-28 22:07:09'),
(34, 8, 'Cookies and Cream', '', 1, 1, 0, 79.00, 'da408520ce4194470dd746612e3a86d1.jpg', 1, '2026-04-28 22:11:04', '2026-04-28 22:11:04'),
(35, 8, 'Dark Chocolate', '', 1, 1, 0, 79.00, '2c9c1d8f49cedd401eb540e83bfec567.jpg', 1, '2026-04-28 22:11:04', '2026-04-28 22:11:04'),
(36, 8, 'Okinawa', '', 1, 1, 0, 79.00, '738599dbae4bdd3f37d20dfe6f7a3f00.webp', 1, '2026-04-28 22:11:04', '2026-04-28 22:11:04'),
(37, 8, 'Wintermelon', '', 1, 1, 0, 79.00, '897cef8a82fb5c148346b1f40d2b0322.jpg', 1, '2026-04-28 22:11:04', '2026-04-28 22:11:04'),
(38, 9, 'Blueberry', '', 1, 1, 0, 55.00, '13c8cc02dd90f85a391b4f2e02bfd786.jpg', 1, '2026-04-28 22:14:23', '2026-04-28 22:14:23'),
(39, 9, 'Strawberry', '', 1, 1, 0, 55.00, 'cfebedbde285a1874d77bafbe91422f6.jpg', 1, '2026-04-28 22:14:23', '2026-04-28 22:14:23'),
(40, 9, 'Green Apple', '', 1, 1, 0, 55.00, '8bc2e1413e39e46e6ebca52c4a166d54.jpg', 1, '2026-04-28 22:14:23', '2026-04-28 22:14:23'),
(41, 9, 'Lychee', '', 1, 1, 0, 55.00, '2698973bb550472079b9fcf46471b2d9.webp', 1, '2026-04-28 22:14:23', '2026-04-28 22:14:23');

-- --------------------------------------------------------

--
-- Table structure for table `product_addons`
--

CREATE TABLE `product_addons` (
  `product_id` int UNSIGNED NOT NULL,
  `addon_id` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `product_addons`
--

INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES
('1', '1'),
('2', '1'),
('3', '1'),
('4', '1'),
('5', '1'),
('6', '1'),
('7', '1'),
('8', '1'),
('9', '1'),
('10', '1'),
('11', '1'),
('12', '1'),
('13', '1'),
('14', '1'),
('15', '1'),
('17', '1'),
('18', '1'),
('19', '1'),
('20', '1'),
('21', '1'),
('22', '1'),
('23', '1'),
('24', '1'),
('25', '1'),
('26', '1'),
('27', '1'),
('30', '1'),
('1', '2'),
('2', '2'),
('3', '2'),
('4', '2'),
('5', '2'),
('6', '2'),
('7', '2'),
('8', '2'),
('9', '2'),
('10', '2'),
('11', '2'),
('12', '2'),
('13', '2'),
('14', '2'),
('15', '2'),
('16', '2'),
('17', '2'),
('18', '2'),
('19', '2'),
('20', '2'),
('21', '2'),
('22', '2'),
('23', '2'),
('24', '2'),
('25', '2'),
('26', '2'),
('27', '2'),
('28', '2'),
('29', '2'),
('30', '2'),
('31', '2'),
('32', '2'),
('34', '3'),
('35', '3'),
('36', '3'),
('37', '3'),
('38', '3'),
('39', '3'),
('40', '3'),
('41', '3');

-- --------------------------------------------------------

--
-- Table structure for table `product_ingredients`
--

CREATE TABLE `product_ingredients` (
  `id` int UNSIGNED NOT NULL,
  `product_id` int UNSIGNED NOT NULL,
  `inventory_item_id` int UNSIGNED NOT NULL,
  `size_label` varchar(30) NOT NULL DEFAULT '',
  `qty_per_unit` decimal(10,3) NOT NULL COMMENT 'amount consumed per 1 unit sold'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `product_ingredients` (`id`, `product_id`, `inventory_item_id`, `size_label`, `qty_per_unit`) VALUES
('1', '1', '3', '', '0.018'),
('2', '1', '3', '22oz', '0.025'),
('3', '1', '1', '', '0.160'),
('4', '1', '1', '22oz', '0.220'),
('5', '1', '9', '', '0.025'),
('6', '1', '9', '22oz', '0.034'),
('7', '1', '13', '', '0.010'),
('8', '1', '13', '22oz', '0.014'),
('9', '2', '3', '', '0.018'),
('10', '2', '3', '22oz', '0.025'),
('11', '2', '1', '', '0.150'),
('12', '2', '1', '22oz', '0.206'),
('13', '2', '10', '', '0.015'),
('14', '2', '10', '22oz', '0.021'),
('15', '2', '9', '', '0.035'),
('16', '2', '9', '22oz', '0.048'),
('17', '3', '3', '', '0.018'),
('18', '3', '3', '22oz', '0.025'),
('19', '3', '1', '', '0.180'),
('20', '3', '1', '22oz', '0.248'),
('21', '4', '3', '', '0.018'),
('22', '4', '3', '22oz', '0.025'),
('23', '4', '1', '', '0.160'),
('24', '4', '1', '22oz', '0.220'),
('25', '4', '15', '', '0.020'),
('26', '4', '15', '22oz', '0.028'),
('27', '4', '12', '', '0.010'),
('28', '4', '12', '22oz', '0.014'),
('29', '5', '3', '', '0.018'),
('30', '5', '3', '22oz', '0.025'),
('31', '5', '1', '', '0.160'),
('32', '5', '1', '22oz', '0.220'),
('33', '5', '18', '', '0.010'),
('34', '5', '18', '22oz', '0.014'),
('35', '5', '9', '', '0.020'),
('36', '5', '9', '22oz', '0.028'),
('37', '6', '3', '', '0.018'),
('38', '6', '3', '22oz', '0.025'),
('39', '6', '1', '', '0.160'),
('40', '6', '1', '22oz', '0.220'),
('41', '6', '14', '', '0.020'),
('42', '6', '14', '22oz', '0.028'),
('43', '7', '3', '', '0.018'),
('44', '7', '3', '22oz', '0.025'),
('45', '8', '3', '', '0.018'),
('46', '8', '3', '22oz', '0.025'),
('47', '8', '1', '', '0.140'),
('48', '8', '1', '22oz', '0.193'),
('49', '9', '3', '', '0.018'),
('50', '9', '3', '22oz', '0.025'),
('51', '9', '7', '', '0.045'),
('52', '9', '7', '22oz', '0.062'),
('53', '9', '8', '', '0.055'),
('54', '9', '8', '22oz', '0.076'),
('55', '10', '3', '', '0.018'),
('56', '10', '3', '22oz', '0.025'),
('57', '10', '1', '', '0.160'),
('58', '10', '1', '22oz', '0.220'),
('59', '10', '15', '', '0.020'),
('60', '10', '15', '22oz', '0.028'),
('61', '11', '3', '', '0.018'),
('62', '11', '3', '22oz', '0.025'),
('63', '11', '1', '', '0.160'),
('64', '11', '1', '22oz', '0.220'),
('65', '11', '16', '', '0.020'),
('66', '11', '16', '22oz', '0.028'),
('67', '12', '3', '', '0.018'),
('68', '12', '3', '22oz', '0.025'),
('69', '12', '1', '', '0.180'),
('70', '12', '1', '22oz', '0.248'),
('71', '13', '3', '', '0.018'),
('72', '13', '3', '22oz', '0.025'),
('73', '13', '1', '', '0.150'),
('74', '13', '1', '22oz', '0.206'),
('75', '13', '11', '', '0.012'),
('76', '13', '11', '22oz', '0.017'),
('77', '13', '14', '', '0.010'),
('78', '13', '14', '22oz', '0.014'),
('79', '14', '3', '', '0.018'),
('80', '14', '3', '22oz', '0.025'),
('81', '14', '1', '', '0.160'),
('82', '14', '1', '22oz', '0.220'),
('83', '14', '12', '', '0.015'),
('84', '14', '12', '22oz', '0.021'),
('85', '15', '3', '', '0.018'),
('86', '15', '3', '22oz', '0.025'),
('87', '15', '1', '', '0.160'),
('88', '15', '1', '22oz', '0.220'),
('89', '15', '10', '', '0.015'),
('90', '15', '10', '22oz', '0.021'),
('91', '16', '17', '', '0.012'),
('92', '16', '17', '22oz', '0.017'),
('93', '16', '1', '', '0.180'),
('94', '16', '1', '22oz', '0.248'),
('95', '17', '3', '', '0.018'),
('96', '17', '3', '22oz', '0.025'),
('97', '17', '1', '', '0.180'),
('98', '17', '1', '22oz', '0.248'),
('99', '18', '3', '', '0.018'),
('100', '18', '3', '22oz', '0.025'),
('101', '18', '26', '', '0.160'),
('102', '18', '26', '22oz', '0.220'),
('103', '19', '3', '', '0.018'),
('104', '19', '3', '22oz', '0.025'),
('105', '19', '1', '', '0.160'),
('106', '19', '1', '22oz', '0.220'),
('107', '19', '26', '', '0.140'),
('108', '19', '26', '22oz', '0.193'),
('109', '20', '3', '', '0.018'),
('110', '20', '3', '22oz', '0.025'),
('111', '20', '1', '', '0.140'),
('112', '20', '1', '22oz', '0.193'),
('113', '20', '7', '', '0.035'),
('114', '20', '7', '22oz', '0.048'),
('115', '20', '26', '', '0.140'),
('116', '20', '26', '22oz', '0.193'),
('117', '21', '3', '', '0.018'),
('118', '21', '3', '22oz', '0.025'),
('119', '21', '1', '', '0.150'),
('120', '21', '1', '22oz', '0.206'),
('121', '21', '15', '', '0.020'),
('122', '21', '15', '22oz', '0.028'),
('123', '21', '26', '', '0.140'),
('124', '21', '26', '22oz', '0.193'),
('125', '22', '3', '', '0.018'),
('126', '22', '3', '22oz', '0.025'),
('127', '22', '1', '', '0.150'),
('128', '22', '1', '22oz', '0.206'),
('129', '22', '10', '', '0.015'),
('130', '22', '10', '22oz', '0.021'),
('131', '22', '26', '', '0.140'),
('132', '22', '26', '22oz', '0.193'),
('133', '23', '3', '', '0.018'),
('134', '23', '3', '22oz', '0.025'),
('135', '23', '1', '', '0.150'),
('136', '23', '1', '22oz', '0.206'),
('137', '23', '16', '', '0.020'),
('138', '23', '16', '22oz', '0.028'),
('139', '23', '26', '', '0.140'),
('140', '23', '26', '22oz', '0.193'),
('141', '24', '3', '', '0.018'),
('142', '24', '3', '22oz', '0.025'),
('143', '24', '1', '', '0.150'),
('144', '24', '1', '22oz', '0.206'),
('145', '24', '11', '', '0.012'),
('146', '24', '11', '22oz', '0.017'),
('147', '24', '14', '', '0.010'),
('148', '24', '14', '22oz', '0.014'),
('149', '24', '26', '', '0.140'),
('150', '24', '26', '22oz', '0.193'),
('151', '25', '3', '', '0.018'),
('152', '25', '3', '22oz', '0.025'),
('153', '25', '1', '', '0.160'),
('154', '25', '1', '22oz', '0.220'),
('155', '25', '12', '', '0.015'),
('156', '25', '12', '22oz', '0.021'),
('157', '25', '26', '', '0.140'),
('158', '25', '26', '22oz', '0.193'),
('159', '26', '3', '', '0.018'),
('160', '26', '3', '22oz', '0.025'),
('161', '26', '1', '', '0.150'),
('162', '26', '1', '22oz', '0.206'),
('163', '26', '11', '', '0.012'),
('164', '26', '11', '22oz', '0.017'),
('165', '26', '14', '', '0.010'),
('166', '26', '14', '22oz', '0.014'),
('167', '26', '26', '', '0.140'),
('168', '26', '26', '22oz', '0.193'),
('169', '27', '3', '', '0.018'),
('170', '27', '3', '22oz', '0.025'),
('171', '27', '7', '', '0.045'),
('172', '27', '7', '22oz', '0.062'),
('173', '27', '8', '', '0.055'),
('174', '27', '8', '22oz', '0.076'),
('175', '27', '26', '', '0.140'),
('176', '27', '26', '22oz', '0.193'),
('177', '28', '17', '', '0.012'),
('178', '28', '17', '22oz', '0.017'),
('179', '28', '1', '', '0.160'),
('180', '28', '1', '22oz', '0.220'),
('181', '28', '16', '', '0.012'),
('182', '28', '16', '22oz', '0.017'),
('183', '28', '26', '', '0.140'),
('184', '28', '26', '22oz', '0.193'),
('185', '29', '17', '', '0.012'),
('186', '29', '17', '22oz', '0.017'),
('187', '29', '1', '', '0.160'),
('188', '29', '1', '22oz', '0.220'),
('189', '29', '9', '', '0.025'),
('190', '29', '9', '22oz', '0.034'),
('191', '29', '26', '', '0.140'),
('192', '29', '26', '22oz', '0.193'),
('193', '30', '17', '', '0.010'),
('194', '30', '17', '22oz', '0.014'),
('195', '30', '3', '', '0.009'),
('196', '30', '3', '22oz', '0.012'),
('197', '30', '1', '', '0.150'),
('198', '30', '1', '22oz', '0.206'),
('199', '30', '26', '', '0.140'),
('200', '30', '26', '22oz', '0.193'),
('201', '31', '17', '', '0.012'),
('202', '31', '17', '22oz', '0.017'),
('203', '31', '1', '', '0.150'),
('204', '31', '1', '22oz', '0.206'),
('205', '31', '23', '', '0.025'),
('206', '31', '23', '22oz', '0.034'),
('207', '31', '26', '', '0.140'),
('208', '31', '26', '22oz', '0.193'),
('209', '32', '17', '', '0.012'),
('210', '32', '17', '22oz', '0.017'),
('211', '32', '1', '', '0.170'),
('212', '32', '1', '22oz', '0.234'),
('213', '32', '26', '', '0.140'),
('214', '32', '26', '22oz', '0.193'),
('215', '33', '18', '', '0.020'),
('216', '33', '18', '22oz', '0.028'),
('217', '33', '1', '', '0.180'),
('218', '33', '1', '22oz', '0.248'),
('219', '34', '1', '', '0.170'),
('220', '34', '1', '22oz', '0.234'),
('221', '34', '9', '', '0.025'),
('222', '34', '9', '22oz', '0.034'),
('223', '34', '18', '', '0.008'),
('224', '34', '18', '22oz', '0.011'),
('225', '35', '20', '', '0.004'),
('226', '35', '20', '22oz', '0.006'),
('227', '35', '18', '', '0.015'),
('228', '35', '18', '22oz', '0.021'),
('229', '35', '1', '', '0.150'),
('230', '35', '1', '22oz', '0.206'),
('231', '36', '20', '', '0.004'),
('232', '36', '20', '22oz', '0.006'),
('233', '36', '1', '', '0.150'),
('234', '36', '1', '22oz', '0.206'),
('235', '36', '13', '', '0.012'),
('236', '36', '13', '22oz', '0.017'),
('237', '37', '20', '', '0.004'),
('238', '37', '20', '22oz', '0.006'),
('239', '37', '13', '', '0.018'),
('240', '37', '13', '22oz', '0.025'),
('241', '38', '24', '', '0.025'),
('242', '38', '24', '22oz', '0.034'),
('243', '38', '26', '', '0.160'),
('244', '38', '26', '22oz', '0.220'),
('245', '39', '23', '', '0.025'),
('246', '39', '23', '22oz', '0.034'),
('247', '39', '26', '', '0.160'),
('248', '39', '26', '22oz', '0.220'),
('249', '40', '13', '', '0.020'),
('250', '40', '13', '22oz', '0.028'),
('251', '40', '26', '', '0.160'),
('252', '40', '26', '22oz', '0.220'),
('253', '41', '13', '', '0.015'),
('254', '41', '13', '22oz', '0.021'),
('255', '41', '26', '', '0.160'),
('256', '41', '26', '22oz', '0.220'),
('257', '1', '27', '', '1.000'),
('258', '1', '27', '22oz', '0.000'),
('259', '1', '28', '22oz', '1.000'),
('260', '2', '27', '', '1.000'),
('261', '2', '27', '22oz', '0.000'),
('262', '2', '28', '22oz', '1.000'),
('263', '3', '27', '', '1.000'),
('264', '3', '27', '22oz', '0.000'),
('265', '3', '28', '22oz', '1.000'),
('266', '4', '27', '', '1.000'),
('267', '4', '27', '22oz', '0.000'),
('268', '4', '28', '22oz', '1.000'),
('269', '5', '27', '', '1.000'),
('270', '5', '27', '22oz', '0.000'),
('271', '5', '28', '22oz', '1.000'),
('272', '6', '27', '', '1.000'),
('273', '6', '27', '22oz', '0.000'),
('274', '6', '28', '22oz', '1.000'),
('275', '7', '2', '', '1.000'),
('276', '7', '2', '22oz', '0.000'),
('277', '7', '28', '22oz', '1.000'),
('278', '8', '2', '', '1.000'),
('279', '8', '2', '22oz', '0.000'),
('280', '8', '28', '22oz', '1.000'),
('281', '9', '2', '', '1.000'),
('282', '9', '2', '22oz', '0.000'),
('283', '9', '28', '22oz', '1.000'),
('284', '10', '2', '', '1.000'),
('285', '10', '2', '22oz', '0.000'),
('286', '10', '28', '22oz', '1.000'),
('287', '11', '2', '', '1.000'),
('288', '11', '2', '22oz', '0.000'),
('289', '11', '28', '22oz', '1.000'),
('290', '12', '2', '', '1.000'),
('291', '12', '2', '22oz', '0.000'),
('292', '12', '28', '22oz', '1.000'),
('293', '13', '2', '', '1.000'),
('294', '13', '2', '22oz', '0.000'),
('295', '13', '28', '22oz', '1.000'),
('296', '14', '2', '', '1.000'),
('297', '14', '2', '22oz', '0.000'),
('298', '14', '28', '22oz', '1.000'),
('299', '15', '2', '', '1.000'),
('300', '15', '2', '22oz', '0.000'),
('301', '15', '28', '22oz', '1.000'),
('302', '16', '2', '', '1.000'),
('303', '16', '2', '22oz', '0.000'),
('304', '16', '28', '22oz', '1.000'),
('305', '17', '27', '', '1.000'),
('306', '17', '27', '22oz', '0.000'),
('307', '17', '28', '22oz', '1.000'),
('308', '18', '27', '', '1.000'),
('309', '18', '27', '22oz', '0.000'),
('310', '18', '28', '22oz', '1.000'),
('311', '19', '27', '', '1.000'),
('312', '19', '27', '22oz', '0.000'),
('313', '19', '28', '22oz', '1.000'),
('314', '20', '27', '', '1.000'),
('315', '20', '27', '22oz', '0.000'),
('316', '20', '28', '22oz', '1.000'),
('317', '21', '27', '', '1.000'),
('318', '21', '27', '22oz', '0.000'),
('319', '21', '28', '22oz', '1.000'),
('320', '22', '27', '', '1.000'),
('321', '22', '27', '22oz', '0.000'),
('322', '22', '28', '22oz', '1.000'),
('323', '23', '27', '', '1.000'),
('324', '23', '27', '22oz', '0.000'),
('325', '23', '28', '22oz', '1.000'),
('326', '24', '27', '', '1.000'),
('327', '24', '27', '22oz', '0.000'),
('328', '24', '28', '22oz', '1.000'),
('329', '25', '27', '', '1.000'),
('330', '25', '27', '22oz', '0.000'),
('331', '25', '28', '22oz', '1.000'),
('332', '26', '27', '', '1.000'),
('333', '26', '27', '22oz', '0.000'),
('334', '26', '28', '22oz', '1.000'),
('335', '27', '27', '', '1.000'),
('336', '27', '27', '22oz', '0.000'),
('337', '27', '28', '22oz', '1.000'),
('338', '28', '27', '', '1.000'),
('339', '28', '27', '22oz', '0.000'),
('340', '28', '28', '22oz', '1.000'),
('341', '29', '27', '', '1.000'),
('342', '29', '27', '22oz', '0.000'),
('343', '29', '28', '22oz', '1.000'),
('344', '30', '27', '', '1.000'),
('345', '30', '27', '22oz', '0.000'),
('346', '30', '28', '22oz', '1.000'),
('347', '31', '27', '', '1.000'),
('348', '31', '27', '22oz', '0.000'),
('349', '31', '28', '22oz', '1.000'),
('350', '32', '27', '', '1.000'),
('351', '32', '27', '22oz', '0.000'),
('352', '32', '28', '22oz', '1.000'),
('353', '33', '27', '', '1.000'),
('354', '33', '27', '22oz', '0.000'),
('355', '33', '28', '22oz', '1.000'),
('356', '34', '27', '', '1.000'),
('357', '34', '27', '22oz', '0.000'),
('358', '34', '28', '22oz', '1.000'),
('359', '35', '27', '', '1.000'),
('360', '35', '27', '22oz', '0.000'),
('361', '35', '28', '22oz', '1.000'),
('362', '36', '27', '', '1.000'),
('363', '36', '27', '22oz', '0.000'),
('364', '36', '28', '22oz', '1.000'),
('365', '37', '27', '', '1.000'),
('366', '37', '27', '22oz', '0.000'),
('367', '37', '28', '22oz', '1.000'),
('368', '38', '27', '', '1.000'),
('369', '38', '27', '22oz', '0.000'),
('370', '38', '28', '22oz', '1.000'),
('371', '39', '27', '', '1.000'),
('372', '39', '27', '22oz', '0.000'),
('373', '39', '28', '22oz', '1.000'),
('374', '40', '27', '', '1.000'),
('375', '40', '27', '22oz', '0.000'),
('376', '40', '28', '22oz', '1.000'),
('377', '41', '27', '', '1.000'),
('378', '41', '27', '22oz', '0.000'),
('379', '41', '28', '22oz', '1.000');

--
-- Table structure for table `addon_ingredients`
--

CREATE TABLE `addon_ingredients` (
  `addon_id` int NOT NULL,
  `inventory_item_id` int UNSIGNED NOT NULL,
  `qty_per_unit` decimal(12,3) NOT NULL COMMENT 'amount consumed per add-on selection',
  PRIMARY KEY (`addon_id`,`inventory_item_id`),
  KEY `idx_addon_ingredients_item` (`inventory_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `addon_ingredients` (`addon_id`, `inventory_item_id`, `qty_per_unit`) VALUES
('1', '3', '0.009'),
('2', '13', '0.015'),
('3', '25', '0.040');

-- Snapshot the customer selection and its inventory mapping at order time.
CREATE TABLE `order_detail_addons` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_detail_id` int UNSIGNED NOT NULL,
  `addon_id` int DEFAULT NULL,
  `addon_name_snapshot` varchar(100) NOT NULL,
  `price_at_time` decimal(10,2) NOT NULL DEFAULT '0.00',
  `quantity_per_product` decimal(8,3) NOT NULL DEFAULT '1.000',
  PRIMARY KEY (`id`),
  KEY `idx_order_detail_addons_line` (`order_detail_id`),
  KEY `idx_order_detail_addons_addon` (`addon_id`),
  CONSTRAINT `fk_order_detail_addons_line` FOREIGN KEY (`order_detail_id`) REFERENCES `order_details` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_detail_addons_addon` FOREIGN KEY (`addon_id`) REFERENCES `addons` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_detail_addon_ingredients` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_detail_addon_id` bigint UNSIGNED NOT NULL,
  `inventory_item_id` int UNSIGNED NOT NULL,
  `inventory_item_name_snapshot` varchar(120) NOT NULL,
  `qty_per_addon` decimal(12,3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_order_detail_addon_ingredients_line` (`order_detail_addon_id`),
  KEY `idx_order_detail_addon_ingredients_item` (`inventory_item_id`),
  CONSTRAINT `fk_order_detail_addon_ingredients_addon_line` FOREIGN KEY (`order_detail_addon_id`) REFERENCES `order_detail_addons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_detail_addon_ingredients_item` FOREIGN KEY (`inventory_item_id`) REFERENCES `inventory_items` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pickup_slot_locks` (
  `pickup_date` date NOT NULL,
  `pickup_time` time NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`pickup_date`,`pickup_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `schema_migrations` (
  `version` varchar(190) NOT NULL,
  `checksum` char(64) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`version`, `checksum`, `applied_at`) VALUES
('2026_09_26_add_spotlight_to_products.sql', 'fb0ae4352a8fdca74ddb212baeb59dad6fffcb6f6544a7e61fd6d12f2df829a0', '2026-10-19 00:00:00'),
('2026_09_29_add_supplier_to_inventory_log.sql', 'e93bf1c8f4dd9781820c826c7a26b25eacc615a705586d8d8a130d63b2d60ea5', '2026-10-19 00:00:00'),
('2026_10_06_add_recipient_to_payments.sql', '90c49b966d7e9f4bfdd156b98990efece6a809630d03fac159edc9cc764d7cfc', '2026-10-19 00:00:00'),
('2026_10_08_add_shared_cash_drawer.sql', '186710640b62d1818ba9283b17fcf4da4293a6a0f40ed922979425c8893f0335', '2026-10-19 00:00:00'),
('2026_10_08_require_two_cashier_handoff_confirmation.sql', 'c246a1909c46677ece15d7c96b72515c352b71718e3aaa161a5a4f5ecc2305e8', '2026-10-19 00:00:00'),
('2026_10_08_track_cashier_session_activity.sql', '39dfd320bf26f3a6d841a7346bc453db3e3428e81b97c0b80caf8a1de50a55e5', '2026-10-19 00:00:00'),
('2026_10_09_add_product_recipes_and_order_deductions.sql', 'c006ebc87205607e865d5d88588fb20a90f0fb6c3206d2a1152f5f581b87a7be', '2026-10-19 00:00:00'),
('2026_10_11_add_inventory_reservations.sql', '66ddaaab6b082f7644118c6fb1fb25c2c55d555d643abeab40b5edcc8c09b4df', '2026-10-19 00:00:00'),
('2026_10_11_add_loyalty_points.sql', '9a6321c814e1f263d1b2b0f58e1bd95d01b36b5a3fb06742d3942fc6b9990341', '2026-10-19 00:00:00'),
('2026_10_19_add_addon_inventory_recipes.sql', '2fcc0720a68cb86d5b5aca3abcab3bb522cfd23d7f392cb3a8e983422efb6c7c', '2026-10-19 00:00:00'),
('2026_10_20_harden_order_integrity.sql', '27afd682bc45d884f77e6c83a1ea26b4c055ccdf867b4617229d9d7065c320d8', '2026-10-19 00:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `product_ratings`
--

CREATE TABLE `product_ratings` (
  `id` int UNSIGNED NOT NULL,
  `feedback_id` int UNSIGNED NOT NULL COMMENT 'Links to order_feedback.id',
  `order_id` int UNSIGNED NOT NULL,
  `product_id` int UNSIGNED NOT NULL,
  `student_id` int UNSIGNED DEFAULT NULL,
  `faculty_id` int UNSIGNED DEFAULT NULL,
  `rating` tinyint NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `product_rating_summary`
-- (See below for the actual view)
--
CREATE TABLE `product_rating_summary` (
`product_id` int unsigned
,`product_name` varchar(150)
,`image_path` varchar(255)
,`category_name` varchar(60)
,`total_ratings` bigint
,`avg_rating` decimal(6,2)
,`five_star` decimal(23,0)
,`four_star` decimal(23,0)
,`three_star` decimal(23,0)
,`two_star` decimal(23,0)
,`one_star` decimal(23,0)
,`total_sold` decimal(25,0)
);

-- --------------------------------------------------------

--
-- Table structure for table `refund_requests`
--

CREATE TABLE `refund_requests` (
  `id` int UNSIGNED NOT NULL,
  `order_id` int UNSIGNED NOT NULL,
  `student_id` int UNSIGNED NOT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','approved','rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `admin_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `reviewed_by` int UNSIGNED DEFAULT NULL COMMENT 'admin.id',
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `size_options`
--

CREATE TABLE `size_options` (
  `id` int UNSIGNED NOT NULL,
  `label` varchar(30) NOT NULL,
  `price_adjustment` decimal(8,2) NOT NULL DEFAULT '0.00',
  `sort_order` tinyint NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `size_options`
--

INSERT INTO `size_options` (`id`, `label`, `price_adjustment`, `sort_order`, `is_active`) VALUES
(1, '16oz', 0.00, 1, 1),
(2, '22oz', 20.00, 2, 1);

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int UNSIGNED NOT NULL,
  `full_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `student_id_no` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `course` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_verified` tinyint(1) NOT NULL DEFAULT '0',
  `email_verified_at` datetime DEFAULT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'bcrypt hash',
  `id_declaration` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Student agreed to ID declaration',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `no_show_count` int UNSIGNED NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`id`, `full_name`, `student_id_no`, `course`, `email`, `email_verified`, `email_verified_at`, `password`, `id_declaration`, `is_active`, `created_at`, `updated_at`, `no_show_count`) VALUES
(1, 'Jhulmar Bregonia', '2316-02097C', 'BS Information Technology', 'zxc.jhulmar@gmail.com', 1, '2026-04-28 19:55:58', '$2y$12$egtJRayCJT3zHqCWw.8jSOlD1Vsw43oiB.3k9NYoJARkg8LlVlURq', 1, 1, '2026-04-28 19:55:18', '2026-04-28 19:55:58', 0),
(2, 'Khit Dela Cruz', '2216-00419C', 'BS Hospitality Management', 'delacruzkhitm@gmail.com', 1, '2026-06-10 10:10:55', '$2y$12$.o.5on9GZjHu7DMk69rK8uDvYFXtftSo5gWnUZ44qUafi58B68A4C', 1, 1, '2026-06-10 10:09:28', '2026-06-10 10:10:55', 0);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `addons`
--
ALTER TABLE `addons`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `app_settings`
--
ALTER TABLE `app_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_actor` (`actor_type`,`actor_id`),
  ADD KEY `idx_audit_created` (`created_at`);

--
-- Indexes for table `cashiers`
--
ALTER TABLE `cashiers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `fk_cashier_admin` (`created_by`);

--
-- Indexes for table `cashier_sessions`
--
ALTER TABLE `cashier_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cs_cashier` (`cashier_id`),
  ADD KEY `idx_cs_login_at` (`login_at`),
  ADD KEY `idx_cs_open` (`logout_at`, `last_activity_at`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `fk_cat_parent` (`parent_id`);

--
-- Indexes for table `email_otps`
--
ALTER TABLE `email_otps`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_otp_lookup` (`user_type`,`user_id`,`purpose`),
  ADD KEY `idx_otp_expires` (`expires_at`),
  ADD KEY `idx_otp_email` (`email`,`purpose`);

--
-- Indexes for table `faculty`
--
ALTER TABLE `faculty`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `faculty_id_no` (`faculty_id_no`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `inventory_items`
--
ALTER TABLE `inventory_items`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `inventory_log`
--
ALTER TABLE `inventory_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `inventory_item_id` (`inventory_item_id`);

--
-- Indexes for table `inventory_order_deductions`
--
ALTER TABLE `inventory_order_deductions`
  ADD PRIMARY KEY (`order_id`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`identifier_hash`),
  ADD KEY `idx_login_attempts_locked_until` (`locked_until`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_number` (`order_number`),
  ADD KEY `fk_order_cashier` (`cashier_id`),
  ADD KEY `idx_orders_status` (`status`),
  ADD KEY `idx_orders_type` (`order_type`),
  ADD KEY `idx_orders_created_at` (`created_at`),
  ADD KEY `idx_orders_student` (`student_id`),
  ADD KEY `idx_orders_locked` (`locked_by`,`locked_at`),
  ADD KEY `idx_orders_lock_expire` (`lock_expire_at`),
  ADD KEY `fk_order_faculty` (`faculty_id`);

--
-- Table structure for table `loyalty_points_ledger`
--
CREATE TABLE `loyalty_points_ledger` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_type` enum('student','faculty') NOT NULL,
  `customer_id` int UNSIGNED NOT NULL,
  `order_id` int UNSIGNED DEFAULT NULL,
  `entry_type` enum('earn','redeem','earn_reversal','redeem_reversal','adjustment') NOT NULL,
  `points_delta` int NOT NULL,
  `amount_value` decimal(10,2) NOT NULL DEFAULT '0.00',
  `actor_type` varchar(20) NOT NULL DEFAULT 'system',
  `actor_id` int UNSIGNED DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_loyalty_order_entry` (`order_id`,`entry_type`),
  KEY `idx_loyalty_customer_history` (`customer_type`,`customer_id`,`created_at`),
  CONSTRAINT `fk_loyalty_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for table `order_details`
--
ALTER TABLE `order_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_od_order` (`order_id`),
  ADD KEY `idx_od_product` (`product_id`);

--
-- Indexes for table `order_feedback`
--
ALTER TABLE `order_feedback`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_id` (`order_id`),
  ADD KEY `idx_fb_student` (`student_id`),
  ADD KEY `idx_fb_cashier` (`cashier_id`),
  ADD KEY `idx_fb_rating` (`rating`),
  ADD KEY `idx_fb_created` (`created_at`),
  ADD KEY `fk_feedback_faculty` (`faculty_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_id` (`order_id`);

--
-- Indexes for table `payment_denominations`
--
ALTER TABLE `payment_denominations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_payment_id` (`payment_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_product_category` (`category_id`);

--
-- Indexes for table `product_addons`
--
ALTER TABLE `product_addons`
  ADD PRIMARY KEY (`product_id`,`addon_id`),
  ADD KEY `fk_pa_addon` (`addon_id`);

--
-- Indexes for table `product_ingredients`
--
ALTER TABLE `product_ingredients`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `inventory_item_id` (`inventory_item_id`),
  ADD KEY `idx_product_ingredients_variant` (`product_id`,`size_label`,`inventory_item_id`);

--
-- Indexes for table `product_ratings`
--
ALTER TABLE `product_ratings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_product_order` (`product_id`,`order_id`),
  ADD KEY `fk_pr_feedback` (`feedback_id`),
  ADD KEY `fk_pr_order` (`order_id`),
  ADD KEY `idx_pr_product` (`product_id`),
  ADD KEY `idx_pr_student` (`student_id`),
  ADD KEY `idx_pr_rating` (`rating`),
  ADD KEY `fk_pr_faculty` (`faculty_id`);

--
-- Indexes for table `refund_requests`
--
ALTER TABLE `refund_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_refund_order` (`order_id`),
  ADD KEY `fk_refund_student` (`student_id`),
  ADD KEY `fk_refund_admin` (`reviewed_by`);

--
-- Indexes for table `size_options`
--
ALTER TABLE `size_options`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `student_id_no` (`student_id_no`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `addons`
--
ALTER TABLE `addons`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=254;

--
-- AUTO_INCREMENT for table `cashiers`
--
ALTER TABLE `cashiers`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `cashier_sessions`
--
ALTER TABLE `cashier_sessions`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `email_otps`
--
ALTER TABLE `email_otps`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `faculty`
--
ALTER TABLE `faculty`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_items`
--
ALTER TABLE `inventory_items`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT for table `inventory_log`
--
ALTER TABLE `inventory_log`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `order_details`
--
ALTER TABLE `order_details`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `order_feedback`
--
ALTER TABLE `order_feedback`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `payment_denominations`
--
ALTER TABLE `payment_denominations`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=42;

--
-- AUTO_INCREMENT for table `product_ingredients`
--
ALTER TABLE `product_ingredients`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_ratings`
--
ALTER TABLE `product_ratings`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `refund_requests`
--
ALTER TABLE `refund_requests`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `size_options`
--
ALTER TABLE `size_options`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

-- --------------------------------------------------------

--
-- Structure for view `product_rating_summary`
--
DROP TABLE IF EXISTS `product_rating_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `product_rating_summary`  AS SELECT `p`.`id` AS `product_id`, `p`.`name` AS `product_name`, `p`.`image_path` AS `image_path`, `c`.`name` AS `category_name`, count(`pr`.`id`) AS `total_ratings`, round(avg(`pr`.`rating`),2) AS `avg_rating`, sum((`pr`.`rating` = 5)) AS `five_star`, sum((`pr`.`rating` = 4)) AS `four_star`, sum((`pr`.`rating` = 3)) AS `three_star`, sum((`pr`.`rating` = 2)) AS `two_star`, sum((`pr`.`rating` = 1)) AS `one_star`, coalesce((select sum(`od`.`quantity`) from `order_details` `od` where (`od`.`product_id` = `p`.`id`)),0) AS `total_sold` FROM ((`products` `p` left join `categories` `c` on((`p`.`category_id` = `c`.`id`))) left join `product_ratings` `pr` on((`pr`.`product_id` = `p`.`id`))) GROUP BY `p`.`id`, `p`.`name`, `p`.`image_path`, `c`.`name` ;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `cashiers`
--
ALTER TABLE `cashiers`
  ADD CONSTRAINT `fk_cashier_admin` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`);

--
-- Constraints for table `cashier_sessions`
--
ALTER TABLE `cashier_sessions`
  ADD CONSTRAINT `fk_cs_cashier` FOREIGN KEY (`cashier_id`) REFERENCES `cashiers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `categories`
--
ALTER TABLE `categories`
  ADD CONSTRAINT `fk_cat_parent` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `inventory_log`
--
ALTER TABLE `inventory_log`
  ADD CONSTRAINT `inventory_log_ibfk_1` FOREIGN KEY (`inventory_item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `inventory_order_deductions`
--
ALTER TABLE `inventory_order_deductions`
  ADD CONSTRAINT `fk_inventory_deduction_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for tables `inventory_order_reservations` and `inventory_order_restorations`
--
ALTER TABLE `inventory_order_reservations`
  ADD CONSTRAINT `fk_inventory_reservation_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_inventory_reservation_item` FOREIGN KEY (`inventory_item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE;

ALTER TABLE `inventory_order_restorations`
  ADD CONSTRAINT `fk_inventory_restoration_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `fk_order_cashier` FOREIGN KEY (`cashier_id`) REFERENCES `cashiers` (`id`),
  ADD CONSTRAINT `fk_order_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `faculty` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_order_locked_by` FOREIGN KEY (`locked_by`) REFERENCES `cashiers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_order_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`);

--
-- Constraints for table `order_details`
--
ALTER TABLE `order_details`
  ADD CONSTRAINT `fk_od_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_od_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `order_feedback`
--
ALTER TABLE `order_feedback`
  ADD CONSTRAINT `fk_fb_cashier` FOREIGN KEY (`cashier_id`) REFERENCES `cashiers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_fb_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_fb_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_feedback_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `faculty` (`id`);

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD KEY `idx_payments_drawer_day` (`drawer_day_id`),
  ADD CONSTRAINT `fk_payment_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  ADD CONSTRAINT `fk_payments_drawer_day` FOREIGN KEY (`drawer_day_id`) REFERENCES `cash_drawer_days` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `payment_denominations`
--
ALTER TABLE `payment_denominations`
  ADD CONSTRAINT `fk_denom_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `fk_product_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`);

--
-- Constraints for table `product_addons`
--
ALTER TABLE `product_addons`
  ADD CONSTRAINT `fk_pa_addon` FOREIGN KEY (`addon_id`) REFERENCES `addons` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pa_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `product_ingredients`
--
ALTER TABLE `product_ingredients`
  ADD CONSTRAINT `product_ingredients_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `product_ingredients_ibfk_2` FOREIGN KEY (`inventory_item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `addon_ingredients`
--
ALTER TABLE `addon_ingredients`
  ADD CONSTRAINT `fk_addon_ingredients_addon` FOREIGN KEY (`addon_id`) REFERENCES `addons` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_addon_ingredients_item` FOREIGN KEY (`inventory_item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `product_ratings`
--
ALTER TABLE `product_ratings`
  ADD CONSTRAINT `fk_pr_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `faculty` (`id`),
  ADD CONSTRAINT `fk_pr_feedback` FOREIGN KEY (`feedback_id`) REFERENCES `order_feedback` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pr_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pr_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pr_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `refund_requests`
--
ALTER TABLE `refund_requests`
  ADD CONSTRAINT `fk_refund_admin` FOREIGN KEY (`reviewed_by`) REFERENCES `admins` (`id`),
  ADD CONSTRAINT `fk_refund_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  ADD CONSTRAINT `fk_refund_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
