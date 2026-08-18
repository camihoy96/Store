-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 18, 2026 at 11:46 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `store`
--

-- --------------------------------------------------------

--
-- Table structure for table `breads`
--

CREATE TABLE `breads` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `breads`
--

INSERT INTO `breads` (`id`, `name`, `price`, `created_at`) VALUES
(2, 'Pandesal', 2.00, '2025-08-03 14:02:52'),
(4, 'Meat Bread', 15.00, '2025-08-03 14:04:07'),
(5, 'Pandecoco', 5.00, '2025-08-04 03:11:17');

-- --------------------------------------------------------

--
-- Table structure for table `bread_remain`
--

CREATE TABLE `bread_remain` (
  `id` int(11) NOT NULL,
  `bread_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `date_recorded` date NOT NULL,
  `recorded_by` int(11) NOT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bread_remain`
--

INSERT INTO `bread_remain` (`id`, `bread_id`, `quantity`, `price`, `date_recorded`, `recorded_by`, `recorded_at`) VALUES
(25, 4, 30, 15.00, '2025-08-04', 7, '2025-08-04 14:52:06'),
(28, 5, 30, 5.00, '2025-08-14', 7, '2025-08-14 14:39:55'),
(29, 2, 40, 2.00, '2025-08-14', 7, '2025-08-14 13:53:36'),
(30, 4, 25, 15.00, '2025-08-14', 7, '2025-08-14 14:39:10'),
(31, 4, 10, 15.00, '2025-08-25', 7, '2025-08-25 06:07:28'),
(33, 4, 20, 15.00, '2026-08-18', 7, '2026-08-18 02:46:10'),
(34, 4, 20, 15.00, '2026-08-18', 7, '2026-08-18 03:29:09');

-- --------------------------------------------------------

--
-- Table structure for table `edit_deletion_log`
--

CREATE TABLE `edit_deletion_log` (
  `id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `edit_id` int(11) NOT NULL,
  `deleted_by` int(11) NOT NULL,
  `deleted_at` datetime NOT NULL,
  `reason` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `position` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `new_user`
--

CREATE TABLE `new_user` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `fullname` varchar(100) NOT NULL,
  `type` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `new_user`
--

INSERT INTO `new_user` (`id`, `username`, `fullname`, `type`, `email`, `password`, `created_at`) VALUES
(7, 'st4nger', 'St4nger Dev', 'admin', 'st4nger@gmail.com', '$2y$10$.qDVEO/SZ8jwG70kLbJHleQ6NnJotqNj/cbwkkIRwhNlv/1k0JwBa', '2025-07-09 08:09:33'),
(11, 'camihoy96', 'Charlie Amihoy', 'staff', 'camihoy96@gmail.com', '$2y$10$LEqdFTlYWCamEMHZBKSXwefqwZwnbdcYq2cWI66ZNmO4BxMonzYHq', '2025-08-25 05:26:11'),
(12, 'Angela', 'Angela Catapusan', 'staff', 'angela@gmail.com', '$2y$10$7Dqlg8SrzFWBR9DVLkmQt.hbM1isZTWK./O2U17cd1Yz2G9sZAd6m', '2025-10-07 11:33:29'),
(13, 'marty12', 'john mart', 'cashier', 'johnmartamihoy@gmail.com', '$2y$10$ICX1Pu6Yk8v1RJx7DG0S/eyOTJwT8TA3Tb9gKMMF2A.ONqhaVJsDW', '2025-11-14 04:49:57');

-- --------------------------------------------------------

--
-- Table structure for table `payment_methods`
--

CREATE TABLE `payment_methods` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `provider` varchar(50) NOT NULL,
  `qr_code_path` varchar(255) DEFAULT NULL,
  `account_name` varchar(100) DEFAULT NULL,
  `account_number` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `display_order` int(11) DEFAULT 0,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payment_methods`
--

INSERT INTO `payment_methods` (`id`, `name`, `provider`, `qr_code_path`, `account_name`, `account_number`, `is_active`, `display_order`, `description`, `created_at`, `updated_at`) VALUES
(2, 'Maya', 'Maya', 'qr/maya_1775288639.png', '', '', 1, 2, '', '2026-03-29 07:35:03', '2026-04-04 07:43:59'),
(3, 'GrabPay', 'GrabPay', 'qr/grabpay_1775288811.png', '', '', 1, 3, '', '2026-03-29 07:35:03', '2026-04-04 07:46:51'),
(4, 'GCash', 'GCash', 'qr/gcash_1775288893.png', 'John', '095265225335', 1, 1, '', '2026-04-04 07:48:13', '2026-04-04 07:48:13');

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `code` varchar(100) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `seller_store` varchar(100) DEFAULT NULL,
  `expiry_date` varchar(10) DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `pieces` int(11) DEFAULT 0,
  `kg` decimal(10,2) DEFAULT NULL,
  `measurement_type` varchar(50) DEFAULT NULL,
  `purchase_price` decimal(10,2) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `date_added` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `code`, `name`, `category`, `price`, `brand`, `seller_store`, `expiry_date`, `purchase_date`, `pieces`, `kg`, `measurement_type`, `purchase_price`, `image_path`, `date_added`) VALUES
(1, 'P1', 'Swack', 'Goods', 12.00, 'Bear Brand', 'Cangz', '2026-08-09', '2025-07-01', 2109, NULL, NULL, 865.00, NULL, '2025-07-11 00:00:51'),
(2, 'P2', 'Coke ', 'Drinks', 40.00, 'Coca Cola', 'Lee Plaza', '2026-07-10', '0000-00-00', 32, NULL, NULL, 954.00, NULL, '2025-07-11 00:00:51'),
(3, 'P3', 'Sprite', 'Drinks', 40.00, 'Coca Cola', 'Lee Plaza', '2026-07-02', '0000-00-00', 35, NULL, NULL, 850.00, NULL, '2025-07-11 00:00:51'),
(4, 'P4', 'Coke Sakto', 'Drinks', 15.00, 'Coca Cola', 'Lee Plaza', '2026-07-09', '0000-00-00', 66, NULL, NULL, 750.00, NULL, '2025-07-11 00:00:51'),
(5, 'P5', 'Royal', 'Drinks', 40.00, 'Coca Cola', 'Lee Plaza', '2026-07-09', '0000-00-00', 23, NULL, NULL, 580.00, NULL, '2025-07-11 00:00:51'),
(6, 'P6', 'Sprite Sakto', 'Drinks', 15.00, 'Coca Cola', 'Lee Plaza', '2026-07-09', '2025-07-02', 70, NULL, NULL, 700.00, NULL, '2025-07-11 00:00:51'),
(7, 'P7', 'Royal Sakto', 'Drinks', 15.00, 'Coca Cola', 'Lee Plaza', '2026-07-09', '2025-07-02', 96, NULL, NULL, 600.00, NULL, '2025-07-11 00:00:51'),
(12, NULL, 'Royal Sakto', 'Drinks', 15.00, 'Coca Cola', 'Lee Plaza', '2026-07-09', '2025-07-02', 100, NULL, NULL, 600.00, NULL, '2025-07-11 00:00:51'),
(13, NULL, 'Royal Sakto', 'Drinks', 15.00, 'Coca Cola', 'Lee Plaza', '2026-07-09', '2025-07-02', 89, NULL, NULL, 600.00, NULL, '2025-07-11 00:00:51'),
(14, NULL, 'Cellphone', 'Mobile', 9000.00, 'Infinix', '7-11', '2026-07-16', '2025-07-06', 2, NULL, 'pieces', 14000.00, NULL, '2025-07-11 00:00:51'),
(15, NULL, 'Volt', 'Aluminum', 20.00, 'Rolex', 'Beton Volts', NULL, '2025-07-06', 250, NULL, 'pieces', 500.00, 'uploads/prod_686e6d587d5757.26847650.png', '2025-07-11 00:00:51'),
(32, NULL, 'Carne Norte', 'Goods', 45.00, 'Sardines', 'Lee Plaza', '2025-11-12', '2025-07-11', 85, NULL, 'pieces', 1800.00, 'uploads/prod_687a005e907df2.97738693.jpg', '2025-07-18 03:05:50'),
(33, NULL, 'Ganador', 'Bugas', 2000.00, 'Rice ', 'Acme Traders', NULL, '2025-07-15', -2, 738.00, 'kg', 22500.00, 'uploads/prod_687a027f34dbe5.88907356.jpg', '2025-07-18 03:14:55'),
(34, NULL, 'Mais', 'Goods', 51.00, 'Corn and Grits', 'Acme Traders', 'N/A', '2025-07-16', -3, 247.00, 'kg', 8000.00, 'uploads/prod_687a48f96cc6d8.89263075.jpg', '2025-07-18 21:15:37'),
(36, '', 'Milo', 'Powder Drinks', 13.00, 'Nestle', 'Unitops', '2025-12-09', '2025-07-16', 14, 0.00, 'pieces', 700.00, 'uploads/prod_687a6421aaa114.86455761.jfif', '2025-07-18 22:43:58'),
(37, 'P43', 'Noddles', 'Goods', 13.00, 'Nestle', 'Lee Plaza', '2025-12-09', '2025-07-16', 1, 0.00, 'pieces', 700.00, 'uploads/prod_687a6bfc0656f1.46124442.jfif', '2025-07-18 23:07:10'),
(48, 'P50', 'Luckey Me', 'Goods', 13.00, 'Nestle', 'Lee Plaza', '2025-12-09', '2025-07-16', 18, 0.00, 'pieces', 700.00, 'uploads/prod_687b060232d145.08444802.jpg', '2025-07-19 00:11:58'),
(55, 'P56', 'Redhorse ', 'Liquer', 150.00, 'Redhorse', 'Lee Plaza', '2027-07-20', '2025-07-16', 47, 0.00, 'pieces', 2500.00, 'uploads/prod_698819a33be4b3.29610480.png', '2025-07-19 01:46:59'),
(57, 'P58', 'Sardines', 'Goods', 25.00, 'Mega', 'Lee Plaza', '2026-01-05', '2025-07-17', 78, 0.00, 'pieces', 1700.00, 'uploads/prod_687b4f1ef09904.25285599.jpg', '2025-07-19 02:53:39'),
(58, 'P59', 'Bugas', 'Goods', 250.00, 'Rice', 'Acme Traders', NULL, '2025-07-17', -2, 9.00, 'kg', 500.00, 'uploads/prod_687bb1b90852f3.13313996.jpeg', '2025-07-19 03:55:05');

-- --------------------------------------------------------

--
-- Table structure for table `registration_keys`
--

CREATE TABLE `registration_keys` (
  `id` int(11) NOT NULL,
  `reg_key` varchar(255) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `registration_keys`
--

INSERT INTO `registration_keys` (`id`, `reg_key`, `updated_at`) VALUES
(1, 'ANGEL', '2026-04-04 07:31:38');

-- --------------------------------------------------------

--
-- Table structure for table `reserved_items`
--

CREATE TABLE `reserved_items` (
  `id` int(11) NOT NULL,
  `code` varchar(100) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `seller_store` varchar(100) DEFAULT NULL,
  `purchase_price` decimal(10,2) DEFAULT NULL,
  `quantity` decimal(10,2) DEFAULT 0.00,
  `unit` varchar(10) DEFAULT 'pcs',
  `image_path` varchar(255) DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `date_added` datetime DEFAULT current_timestamp(),
  `measurement_type` varchar(250) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reserved_items`
--

INSERT INTO `reserved_items` (`id`, `code`, `name`, `price`, `category`, `brand`, `seller_store`, `purchase_price`, `quantity`, `unit`, `image_path`, `purchase_date`, `expiry_date`, `date_added`, `measurement_type`) VALUES
(1, NULL, 'Swack', NULL, 'Goods', 'Bear Brand', 'Cangz', 865.00, 80.00, 'g', '', '2025-07-01', '0000-00-00', '2025-07-11 00:01:28', NULL),
(2, NULL, 'Coke ', NULL, 'Drinks', 'Coca Cola', 'Lee Plaza', 954.00, 10.00, 'pcs', 'uploads/prod_68839bc2b53cb6.37341877.jpg', '0000-00-00', '2026-07-10', '2025-07-11 00:01:28', NULL),
(3, NULL, 'Sprite', NULL, 'Drinks', 'Coca Cola', 'Lee Plaza', 850.00, 15.00, 'kg', 'uploads/prod_688391fe7f81b7.94855992.jpg', '0000-00-00', '2026-07-02', '2025-07-11 00:01:28', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_type` varchar(50) DEFAULT 'text',
  `description` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `updated_at`, `updated_by`) VALUES
(1, 'business_name', 'St4nger POS', 'text', 'Business name displayed throughout the system', '2026-08-18 09:27:55', NULL),
(2, 'business_subtitle', 'INVENTORY SYSTEM', 'text', 'Subtitle/System name', '2026-08-18 09:28:35', NULL),
(3, 'business_address', 'Dumaguete City, Negros Oriental 6200', 'text', 'Business address', '2026-08-18 09:27:06', NULL),
(4, 'business_phone', '0905 615 2262', 'text', 'Business contact number', '2026-03-29 07:34:53', NULL),
(5, 'receipt_footer', 'Thank you for your purchase!', 'text', 'Footer text printed on receipts', '2026-03-29 07:34:53', NULL),
(6, 'currency_symbol', '₱', 'text', 'Currency symbol', '2026-03-29 07:34:53', NULL),
(7, 'tax_rate', '0', 'decimal', 'Tax rate percentage', '2026-03-29 07:34:53', NULL),
(8, 'low_stock_threshold_pieces', '20', 'number', 'Low stock threshold for pieces', '2026-03-29 07:34:53', NULL),
(9, 'low_stock_threshold_kg', '20', 'decimal', 'Low stock threshold for kilograms', '2026-03-29 07:34:53', NULL),
(10, 'auto_print_receipt', '0', 'boolean', 'Automatically print receipt after payment', '2026-04-04 07:59:30', NULL),
(11, 'receipt_width', '58', 'number', 'Receipt print width in mm', '2026-03-29 07:34:53', NULL),
(12, 'enable_ewallet', '1', 'boolean', 'Enable e-wallet payments', '2026-03-29 07:34:53', NULL),
(13, 'enable_cash', '1', 'boolean', 'Enable cash payments', '2026-03-29 07:34:53', NULL),
(27, 'logo_path', 'image/logo_1775288910.png', 'text', NULL, '2026-04-04 07:48:30', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
  `cashier_name` varchar(100) DEFAULT NULL,
  `date` date DEFAULT NULL,
  `time` time DEFAULT NULL,
  `total` decimal(10,2) DEFAULT NULL,
  `paid` decimal(10,2) DEFAULT NULL,
  `change_due` decimal(10,2) DEFAULT NULL,
  `items` text DEFAULT NULL,
  `original_items` text DEFAULT NULL,
  `status` varchar(20) DEFAULT NULL,
  `voided_by` varchar(100) DEFAULT NULL,
  `edited_by` varchar(100) DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  `edited_at` datetime DEFAULT NULL,
  `void_reason` text DEFAULT NULL,
  `edit_remarks` text DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT 'Cash',
  `reference_no` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`id`, `cashier_name`, `date`, `time`, `total`, `paid`, `change_due`, `items`, `original_items`, `status`, `voided_by`, `edited_by`, `voided_at`, `edited_at`, `void_reason`, `edit_remarks`, `payment_method`, `reference_no`) VALUES
(1, 'Anna', '2025-07-01', '14:03:09', 239.00, 239.00, 0.00, '[{\"id\":\"\",\"name\":\"Rice\",\"qty\":2,\"price\":52,\"unit\":\"\",\"status\":\"\",\"measurement_type\":\"pieces\"},{\"id\":\"\",\"name\":\"Soda\",\"qty\":3,\"price\":20,\"unit\":\"\",\"status\":\"\",\"measurement_type\":\"pieces\"},{\"id\":\"\",\"name\":\"Meat Bread\",\"qty\":5,\"price\":15,\"unit\":\"\",\"status\":\"\",\"measurement_type\":\"pieces\"}]', '[]', 'edited', NULL, 'camihoy96', NULL, '2025-07-21 14:35:10', NULL, '[2025-07-21 08:35:10] Edit reason: Giusob\n[2025-07-21 08:35:10] Edited item: Rice\n[2025-07-21 08:35:10] Edited item: Soda\n[2025-07-21 08:35:10] Edited item: Meat Bread\n', 'Cash', NULL),
(2, 'Charlie', '2025-07-01', '14:08:37', 397.75, 500.00, 102.25, '[{\"id\":\"\",\"name\":\"Bread\",\"qty\":2,\"price\":29.5,\"unit\":\"\",\"status\":\"\",\"measurement_type\":\"pieces\"},{\"id\":\"\",\"name\":\"Soda\",\"qty\":5,\"price\":19.75,\"unit\":\"\",\"status\":\"\",\"measurement_type\":\"pieces\"},{\"id\":\"\",\"name\":\"Rice\",\"qty\":5,\"price\":48,\"unit\":\"\",\"status\":\"\",\"measurement_type\":\"pieces\"}]', '[{\"id\":\"\",\"name\":\"Bread\",\"qty\":2,\"price\":29.5,\"unit\":\"\",\"status\":\"\",\"measurement_type\":\"pieces\"},{\"id\":\"\",\"name\":\"Soda\",\"qty\":5,\"price\":19.75,\"unit\":\"\",\"status\":\"\",\"measurement_type\":\"pieces\"},{\"id\":\"\",\"name\":\"Rice\",\"qty\":2,\"price\":48,\"unit\":\"\",\"status\":\"\",\"measurement_type\":\"pieces\"}]', 'edited', NULL, 'camihoy96', NULL, '2025-07-21 15:43:33', NULL, '[2025-07-21 09:36:41] Edit reason: nasayup\n[2025-07-21 09:36:41] Edited item: Rice\n[2025-07-21 09:43:33] Edit reason: Gipun-an\n[2025-07-21 09:43:33] Edited item: Rice\n', 'Cash', NULL),
(10, 'Carla', '2025-07-02', '15:51:03', 540.00, 540.00, 0.00, '[{\"id\":\"\",\"name\":\"Meat \",\"qty\":2,\"price\":250,\"unit\":\"\",\"status\":\"\",\"measurement_type\":\"pieces\"},{\"id\":\"\",\"name\":\"Pandesal\",\"qty\":20,\"price\":2,\"unit\":\"\",\"status\":\"\",\"measurement_type\":\"pieces\"}]', '[{\"name\":\"Meat Bread\",\"qty\":4,\"price\":15,\"total\":\"60.00\"},{\"name\":\"Pandesal\",\"qty\":20,\"price\":2,\"total\":\"40.00\"}]', 'edited', NULL, 'camihoy96', NULL, '2025-07-21 15:35:30', NULL, '[2025-07-21 09:33:18] Edit reason: Nasayop\n[2025-07-21 09:33:18] Edited item: Meat \n[2025-07-21 09:35:30] Edit reason: giilisan\n[2025-07-21 09:35:30] Edited item: Meat \n', 'Cash', NULL),
(11, 'Ben', '2025-07-02', '16:13:30', 160.00, 160.00, 0.00, '[{\"id\":\"\",\"name\":\"Sprite\",\"qty\":3,\"price\":40,\"unit\":\"\",\"status\":\"\",\"measurement_type\":\"pieces\"},{\"id\":\"\",\"name\":\"Royal\",\"qty\":1,\"price\":40,\"unit\":\"\",\"status\":\"\",\"measurement_type\":\"pieces\"}]', '[{\"name\":\"Sprite\",\"qty\":1,\"price\":40,\"total\":\"40.00\"},{\"name\":\"Royal\",\"qty\":1,\"price\":40,\"total\":\"40.00\"}]', 'edited', NULL, 'st4nger', NULL, '2025-08-09 21:38:14', NULL, '[2025-08-09 15:38:14] Edit reason: gipun an\n[2025-08-09 15:38:14] Edited item: Sprite\n', 'Cash', NULL),
(13, 'Anna', '2025-07-02', '16:27:36', 135.00, 135.00, 0.00, '[{\"id\":\"\",\"name\":\"Coke \",\"qty\":3,\"price\":40,\"unit\":\"\",\"status\":\"\",\"measurement_type\":\"pieces\"},{\"id\":\"\",\"name\":\"Coke Sakto\",\"qty\":1,\"price\":15,\"unit\":\"\",\"status\":\"\",\"measurement_type\":\"pieces\"}]', '[{\"name\":\"Coke \",\"qty\":1,\"price\":40,\"total\":\"40.00\"},{\"name\":\"Coke Sakto\",\"qty\":1,\"price\":15,\"total\":\"15.00\"}]', 'edited', NULL, 'st4nger', NULL, '2025-08-09 21:27:32', NULL, '[2025-08-09 15:27:32] Edit reason: gipun an\n[2025-08-09 15:27:32] Edited item: Coke \n', 'Cash', NULL),
(14, 'Ben', '2025-07-02', '16:31:38', 322.00, 500.00, 178.00, '[{\"name\":\"Coke Sakto\",\"qty\":2,\"price\":15,\"total\":\"30.00\"},{\"name\":\"Royal\",\"qty\":2,\"price\":40,\"total\":\"80.00\"},{\"name\":\"Sprite\",\"qty\":2,\"price\":40,\"total\":\"80.00\"},{\"name\":\"Bugas\",\"qty\":3,\"price\":44,\"total\":\"132.00\"}]', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Cash', NULL),
(15, 'Carla', '2025-07-02', '16:44:07', 55.00, 70.00, 15.00, '[{\"name\":\"Sprite\",\"qty\":1,\"price\":40,\"total\":\"40.00\"},{\"name\":\"Coke Sakto\",\"qty\":1,\"price\":15,\"total\":\"15.00\"}]', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Cash', NULL),
(16, 'Anna', '2025-07-02', '16:45:41', 190.00, 190.00, 0.00, '[{\"id\":\"\",\"name\":\"Coke Sakto\",\"qty\":2,\"price\":15,\"unit\":\"\",\"status\":\"\",\"measurement_type\":\"pieces\"},{\"id\":\"\",\"name\":\"Sprite\",\"qty\":3,\"price\":40,\"unit\":\"\",\"status\":\"\",\"measurement_type\":\"pieces\"},{\"id\":\"\",\"name\":\"Royal\",\"qty\":1,\"price\":40,\"unit\":\"\",\"status\":\"\",\"measurement_type\":\"pieces\"}]', '[{\"id\":\"\",\"name\":\"Coke Sakto\",\"qty\":2,\"price\":15,\"unit\":\"\",\"status\":\"\",\"measurement_type\":\"pieces\"},{\"id\":\"\",\"name\":\"Sprite\",\"qty\":1,\"price\":40,\"unit\":\"\",\"status\":\"\",\"measurement_type\":\"pieces\"},{\"id\":\"\",\"name\":\"Royal\",\"qty\":1,\"price\":40,\"unit\":\"\",\"status\":\"\",\"measurement_type\":\"pieces\"}]', 'edited', NULL, 'st4nger', NULL, '2025-08-09 20:26:28', NULL, '[2025-08-08 16:11:16] Edit reason: Gipun-an\n[2025-08-08 16:11:16] Edited item: Coke Sakto\n[2025-08-08 16:38:16] Edit reason: gipun an\n[2025-08-08 16:38:16] Edited item: Coke Sakto\n[2025-08-09 14:26:28] Edit reason: Gipun an\n[2025-08-09 14:26:28] Edited item: Sprite\n', 'Cash', NULL),
(19, 'Ben', '2025-07-02', '22:41:48', 120.00, 200.00, 80.00, '[{\"id\":\"2\",\"name\":\"Coke \",\"qty\":2,\"price\":20,\"unit\":\"\",\"status\":\"\",\"measurement_type\":\"pieces\"},{\"id\":\"3\",\"name\":\"Sprite\",\"qty\":2,\"price\":40,\"unit\":\"\",\"status\":\"\",\"measurement_type\":\"pieces\"}]', '[{\"id\":\"2\",\"name\":\"Coke \",\"qty\":3,\"price\":20,\"unit\":\"\",\"status\":\"\",\"measurement_type\":\"pieces\"},{\"id\":\"3\",\"name\":\"Sprite\",\"qty\":2,\"price\":40,\"unit\":\"\",\"status\":\"\",\"measurement_type\":\"pieces\"}]', 'edited', NULL, 'st4nger', NULL, '2025-08-08 22:12:22', NULL, 'Edits made. Reason: NASAYOP[2025-08-08 16:00:34] Edit reason: gipun an\n[2025-08-08 16:00:34] Edited item: Coke \n[2025-08-08 16:00:39] Edit reason: gipun an\n[2025-08-08 16:00:39] Edited item: Coke \n[2025-08-08 16:00:44] Edit reason: gipun an\n[2025-08-08 16:00:44] Edited item: Coke \n[2025-08-08 16:00:52] Edit reason: gipun an\n[2025-08-08 16:00:52] Edited item: Coke \n[2025-08-08 16:12:22] Edit reason: Gikuhaan\n[2025-08-08 16:12:22] Edited item: Coke \n', 'Cash', NULL),
(35, 'Anna', '2025-07-18', '01:35:05', 230.00, 1000.00, 770.00, '[{\"id\":\"4\",\"name\":\"Coke Sakto\",\"qty\":2,\"price\":15,\"total\":\"30.00\"},{\"id\":\"5\",\"name\":\"Royal\",\"qty\":3,\"price\":40,\"total\":\"120.00\"},{\"id\":\"6\",\"name\":\"Sprite Sakto\",\"qty\":2,\"price\":15,\"total\":\"30.00\"},{\"id\":\"7\",\"name\":\"Royal Sakto\",\"qty\":2,\"price\":15,\"total\":\"30.00\"},{\"id\":\"15\",\"name\":\"Volt\",\"qty\":1,\"price\":20,\"total\":\"20.00\"}]', NULL, 'voided', 'camihoy96', NULL, '2025-07-21 12:10:30', NULL, 'sayop', 'Entire transaction voided. Reason: sayop', 'Cash', NULL),
(39, 'St4nger Dev', '2025-07-26', '21:03:59', 38.25, 50.00, 11.75, '[{\"id\":\"34\",\"name\":\"Corn\",\"qty\":0.75,\"price\":51,\"unit\":\"kg\",\"status\":\"\",\"measurement_type\":\"kg\"}]', '[{\"id\":\"34\",\"name\":\"Corn\",\"qty\":0.75,\"price\":51,\"unit\":\"kg\",\"status\":\"\",\"measurement_type\":\"kg\"}]', 'completed', NULL, 'st4nger', NULL, '2025-08-09 22:19:24', NULL, '[2025-08-04 17:47:57] Edit reason: Nasayop\n[2025-08-09 16:19:24] Edit reason: sdss\n', 'Cash', NULL),
(40, 'St4nger Dev', '2025-08-04', '11:37:04', 95.00, 100.00, 5.00, '[{\"id\":\"custom-1754278421042\",\"name\":\"Milk\",\"qty\":3,\"price\":15,\"unit\":\"pcs\",\"status\":\"\",\"measurement_type\":\"pieces\"},{\"id\":\"custom-1754278618443\",\"name\":\"Pandecoco (Pandecoco)\",\"qty\":10,\"price\":5,\"unit\":\"pcs\",\"status\":\"\",\"measurement_type\":\"pieces\"}]', '[{\"id\":\"custom-1754278421042\",\"name\":\"Milk\",\"qty\":3,\"price\":15,\"unit\":\"pcs\",\"status\":\"\",\"measurement_type\":\"pieces\"},{\"id\":\"custom-1754278618443\",\"name\":\"Pandecoco (Pandecoco)\",\"qty\":10,\"price\":5,\"unit\":\"pcs\",\"status\":\"\",\"measurement_type\":\"pieces\"}]', 'completed', NULL, 'st4nger', NULL, '2025-08-09 21:46:03', NULL, '[2025-08-04 17:22:44] Edit reason: Na wrong\n[2025-08-04 17:22:44] Edited item: Milk\n[2025-08-09 15:46:03] Edit reason: dfhnbfdsa\n', 'Cash', NULL),
(41, 'john mart', '2025-11-14', '12:51:23', 135.00, 500.00, 365.00, '[{\"id\":\"32\",\"name\":\"Carne Norte\",\"qty\":3,\"price\":45,\"measurement_type\":\"pieces\",\"unit\":\"pcs\",\"total\":\"135.00\"}]', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Cash', NULL),
(42, 'St4nger Dev', '2026-02-08', '13:01:24', 97.00, 100.00, 3.00, '[{\"id\":\"1\",\"name\":\"Swack\",\"qty\":1,\"price\":12,\"measurement_type\":\"pieces\",\"unit\":\"pcs\",\"total\":\"12.00\"},{\"id\":\"2\",\"name\":\"Coke \",\"qty\":1,\"price\":40,\"measurement_type\":\"pieces\",\"unit\":\"pcs\",\"total\":\"40.00\"},{\"id\":\"3\",\"name\":\"Sprite\",\"qty\":1,\"price\":40,\"measurement_type\":\"pieces\",\"unit\":\"pcs\",\"total\":\"40.00\"},{\"id\":\"custom-1770526870077\",\"name\":\"Pandecoco (Pandecoco)\",\"qty\":1,\"price\":5,\"measurement_type\":\"pieces\",\"unit\":\"pcs\",\"total\":\"5.00\"}]', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Cash', NULL),
(43, 'St4nger Dev', '2026-03-29', '16:35:56', 51.00, 51.00, 0.00, '[{\"id\":\"34\",\"name\":\"Mais\",\"qty\":1,\"price\":51,\"measurement_type\":\"kg\",\"unit\":\"kg\",\"total\":\"51.00\"}]', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Cash', NULL),
(44, 'St4nger Dev', '2026-03-29', '16:37:11', 51.00, 100.00, 49.00, '[{\"id\":\"34\",\"name\":\"Mais\",\"qty\":1,\"price\":51,\"measurement_type\":\"kg\",\"unit\":\"kg\",\"total\":\"51.00\"}]', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Cash', NULL),
(45, 'St4nger Dev', '2026-03-29', '16:39:04', 25.00, 50.00, 25.00, '[{\"id\":\"57\",\"name\":\"Sardines\",\"qty\":1,\"price\":25,\"measurement_type\":\"pieces\",\"unit\":\"pcs\",\"total\":\"25.00\"}]', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Cash', NULL),
(46, 'St4nger Dev', '2026-03-29', '16:58:35', 45.00, 50.00, 5.00, '[{\"id\":\"32\",\"name\":\"Carne Norte\",\"qty\":1,\"price\":45,\"measurement_type\":\"pieces\",\"unit\":\"pcs\",\"total\":\"45.00\"}]', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Cash', NULL),
(47, 'St4nger Dev', '2026-03-29', '16:59:01', 25.00, 25.00, 0.00, '[{\"id\":\"57\",\"name\":\"Sardines\",\"qty\":1,\"price\":25,\"measurement_type\":\"pieces\",\"unit\":\"pcs\",\"total\":\"25.00\"}]', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Cash', NULL),
(48, 'St4nger Dev', '2026-03-29', '17:12:56', 2000.00, 2000.00, 0.00, '[{\"id\":\"33\",\"name\":\"Ganador\",\"qty\":1,\"price\":2000,\"measurement_type\":\"kg\",\"unit\":\"kg\",\"total\":\"2000.00\"}]', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Maya', '4548545682036'),
(49, 'St4nger Dev', '2026-03-29', '17:13:07', 2000.00, 2000.00, 0.00, '[{\"id\":\"33\",\"name\":\"Ganador\",\"qty\":1,\"price\":2000,\"measurement_type\":\"kg\",\"unit\":\"kg\",\"total\":\"2000.00\"}]', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Maya', '586224566312'),
(50, 'St4nger Dev', '2026-03-29', '17:13:18', 2000.00, 2000.00, 0.00, '[{\"id\":\"33\",\"name\":\"Ganador\",\"qty\":1,\"price\":2000,\"measurement_type\":\"kg\",\"unit\":\"kg\",\"total\":\"2000.00\"}]', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Maya', '586224566312'),
(51, 'St4nger Dev', '2026-03-29', '17:14:22', 2000.00, 2000.00, 0.00, '[{\"id\":\"33\",\"name\":\"Ganador\",\"qty\":1,\"price\":2000,\"measurement_type\":\"kg\",\"unit\":\"kg\",\"total\":\"2000.00\"}]', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Maya', '586224566312'),
(52, 'St4nger Dev', '2026-03-29', '17:14:30', 2000.00, 2000.00, 0.00, '[{\"id\":\"33\",\"name\":\"Ganador\",\"qty\":1,\"price\":2000,\"measurement_type\":\"kg\",\"unit\":\"kg\",\"total\":\"2000.00\"}]', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Maya', '586224566312'),
(53, 'St4nger Dev', '2026-03-29', '17:15:47', 2000.00, 2000.00, 0.00, '[{\"id\":\"33\",\"name\":\"Ganador\",\"qty\":1,\"price\":2000,\"measurement_type\":\"kg\",\"unit\":\"kg\",\"total\":\"2000.00\"}]', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Maya', '586224566312'),
(54, 'St4nger Dev', '2026-03-29', '17:18:51', 2000.00, 2000.00, 0.00, '[{\"id\":\"33\",\"name\":\"Ganador\",\"qty\":1,\"price\":2000,\"measurement_type\":\"kg\",\"unit\":\"kg\",\"total\":\"2000.00\"}]', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Cash', ''),
(55, 'St4nger Dev', '2026-03-29', '17:18:59', 2000.00, 2000.00, 0.00, '[{\"id\":\"33\",\"name\":\"Ganador\",\"qty\":1,\"price\":2000,\"measurement_type\":\"kg\",\"unit\":\"kg\",\"total\":\"2000.00\"}]', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Cash', ''),
(56, 'St4nger Dev', '2026-03-29', '17:19:14', 2000.00, 2000.00, 0.00, '[{\"id\":\"33\",\"name\":\"Ganador\",\"qty\":1,\"price\":2000,\"measurement_type\":\"kg\",\"unit\":\"kg\",\"total\":\"2000.00\"}]', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Cash', ''),
(57, 'St4nger Dev', '2026-03-29', '17:19:17', 2000.00, 2000.00, 0.00, '[{\"id\":\"33\",\"name\":\"Ganador\",\"qty\":1,\"price\":2000,\"measurement_type\":\"kg\",\"unit\":\"kg\",\"total\":\"2000.00\"}]', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Cash', ''),
(58, 'St4nger Dev', '2026-03-29', '17:19:49', 2000.00, 2000.00, 0.00, '[{\"id\":\"33\",\"name\":\"Ganador\",\"qty\":1,\"price\":2000,\"measurement_type\":\"kg\",\"unit\":\"kg\",\"total\":\"2000.00\"}]', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Cash', ''),
(59, 'St4nger Dev', '2026-03-29', '17:23:53', 2000.00, 2000.00, 0.00, '[{\"id\":\"33\",\"name\":\"Ganador\",\"qty\":1,\"price\":2000,\"measurement_type\":\"kg\",\"unit\":\"kg\",\"total\":\"2000.00\"}]', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'GCash', '586224566312'),
(60, 'St4nger Dev', '2026-03-29', '17:24:56', 40.00, 40.00, 0.00, '[{\"id\":\"2\",\"name\":\"Coke \",\"qty\":1,\"price\":40,\"measurement_type\":\"pieces\",\"unit\":\"pcs\",\"total\":\"40.00\"}]', NULL, 'voided', 'St4nger Dev', NULL, '2026-04-03 22:33:07', NULL, NULL, NULL, 'Maya', '4548545682036'),
(61, 'St4nger Dev', '2026-03-29', '17:25:26', 15.00, 15.00, 0.00, '[{\"id\":\"4\",\"name\":\"Coke Sakto\",\"qty\":1,\"price\":15,\"measurement_type\":\"pieces\",\"unit\":\"pcs\",\"total\":\"15.00\"}]', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'GCash', '4548545682036'),
(63, 'St4nger Dev', '2026-04-03', '11:04:46', 250.00, 500.00, 250.00, '[{\"id\":\"58\",\"name\":\"Bugas\",\"qty\":1,\"price\":250,\"measurement_type\":\"kg\",\"unit\":\"kg\",\"total\":\"250.00\"}]', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Cash', ''),
(64, 'St4nger Dev', '2026-04-03', '11:07:22', 275.00, 500.00, 225.00, '[{\"id\":\"57\",\"name\":\"Sardines\",\"qty\":11,\"price\":25,\"measurement_type\":\"pieces\",\"unit\":\"pcs\",\"total\":\"275.00\"}]', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Cash', ''),
(66, 'St4nger Dev', '2026-04-03', '23:43:57', 51.00, 51.00, 0.00, '[{\"id\":\"34\",\"name\":\"Mais\",\"qty\":1,\"price\":51,\"measurement_type\":\"kg\",\"unit\":\"kg\",\"total\":\"51.00\"}]', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'GCash', '5466747'),
(67, 'St4nger Dev', '2026-04-04', '15:51:13', 51.00, 60.00, 9.00, '[{\"id\":\"34\",\"name\":\"Mais\",\"qty\":1,\"price\":51,\"measurement_type\":\"kg\",\"unit\":\"kg\",\"total\":\"51.00\"}]', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Cash', ''),
(68, 'St4nger Dev', '2026-06-01', '16:21:17', 45.00, 45.00, 0.00, '[{\"id\":\"32\",\"name\":\"Carne Norte\",\"qty\":1,\"price\":45,\"measurement_type\":\"pieces\",\"unit\":\"pcs\",\"total\":\"45.00\"}]', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'GCash', '5445641565456'),
(69, 'St4nger Dev', '2026-06-01', '17:06:11', 13.00, 20.00, 7.00, '[{\"id\":\"48\",\"name\":\"Luckey Me\",\"qty\":1,\"price\":13,\"measurement_type\":\"pieces\",\"unit\":\"pcs\",\"total\":\"13.00\"}]', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Cash', ''),
(70, 'St4nger Dev', '2026-08-18', '14:36:30', 55.00, 100.00, 45.00, '[{\"id\":\"2\",\"name\":\"Coke \",\"qty\":1,\"price\":40,\"measurement_type\":\"pieces\",\"unit\":\"pcs\",\"total\":\"40.00\"},{\"id\":\"13\",\"name\":\"Royal Sakto\",\"qty\":1,\"price\":15,\"measurement_type\":\"pieces\",\"unit\":\"pcs\",\"total\":\"15.00\"}]', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Cash', '');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `fullname` varchar(100) DEFAULT NULL,
  `role` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `type` varchar(50) NOT NULL DEFAULT 'User'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `fullname`, `role`, `created_at`, `type`) VALUES
(1, 'ECWS', '$2y$10$cPoaONuIU6qP0C8BPToeQeMNPa1rnhVlKHDWgexhowoQy11LApPA6', 'Engineering and Civil Works', 'admin', '2025-07-04 05:41:47', 'admin');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `breads`
--
ALTER TABLE `breads`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `bread_remain`
--
ALTER TABLE `bread_remain`
  ADD PRIMARY KEY (`id`),
  ADD KEY `bread_id` (`bread_id`),
  ADD KEY `date_recorded` (`date_recorded`);

--
-- Indexes for table `edit_deletion_log`
--
ALTER TABLE `edit_deletion_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `transaction_id` (`transaction_id`),
  ADD KEY `deleted_by` (`deleted_by`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `new_user`
--
ALTER TABLE `new_user`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `fullname` (`fullname`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `payment_methods`
--
ALTER TABLE `payment_methods`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_active` (`is_active`),
  ADD KEY `idx_provider` (`provider`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `registration_keys`
--
ALTER TABLE `registration_keys`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `reserved_items`
--
ALTER TABLE `reserved_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`),
  ADD KEY `idx_key` (`setting_key`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `breads`
--
ALTER TABLE `breads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `bread_remain`
--
ALTER TABLE `bread_remain`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `edit_deletion_log`
--
ALTER TABLE `edit_deletion_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `new_user`
--
ALTER TABLE `new_user`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `payment_methods`
--
ALTER TABLE `payment_methods`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=61;

--
-- AUTO_INCREMENT for table `registration_keys`
--
ALTER TABLE `registration_keys`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `reserved_items`
--
ALTER TABLE `reserved_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=119;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=71;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `bread_remain`
--
ALTER TABLE `bread_remain`
  ADD CONSTRAINT `bread_remain_ibfk_1` FOREIGN KEY (`bread_id`) REFERENCES `breads` (`id`);

--
-- Constraints for table `edit_deletion_log`
--
ALTER TABLE `edit_deletion_log`
  ADD CONSTRAINT `edit_deletion_log_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`),
  ADD CONSTRAINT `edit_deletion_log_ibfk_2` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
