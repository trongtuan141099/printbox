-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Máy chủ: 127.0.0.1
-- Thời gian đã tạo: Th10 08, 2026 lúc 07:50 PM
-- Phiên bản máy phục vụ: 10.4.32-MariaDB
-- Phiên bản PHP: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Cơ sở dữ liệu: `printbox_db`
--

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `box_labels`
--

CREATE TABLE `box_labels` (
  `id` int(11) NOT NULL,
  `history_id` int(11) NOT NULL,
  `box_no` varchar(50) NOT NULL COMMENT 'Mã thùng: TU-YYMMDD-XXX',
  `order_code` varchar(50) NOT NULL,
  `product_code` varchar(100) NOT NULL,
  `qty` int(11) NOT NULL,
  `weight` varchar(50) DEFAULT '',
  `input_date` date NOT NULL,
  `qr_content` text NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `box_labels`
--

INSERT INTO `box_labels` (`id`, `history_id`, `box_no`, `order_code`, `product_code`, `qty`, `weight`, `input_date`, `qr_content`, `created_at`) VALUES
(19, 4, 'TU-261009-022', 'LOT-TEST-998', 'SMC-VALVE-50A', 10, '2.50', '2026-10-09', 'SMC4$SMC-VALVE-50A$$10$$$$$TU-261009-022$$$$2.50$10/09/2026$LOT-TEST-998$SMC Factory 1', '2026-10-09 00:21:53'),
(20, 4, 'TU-261009-023', 'LOT-TEST-998', 'SMC-VALVE-50A', 10, '2.50', '2026-10-09', 'SMC4$SMC-VALVE-50A$$10$$$$$TU-261009-023$$$$2.50$10/09/2026$LOT-TEST-998$SMC Factory 1', '2026-10-09 00:21:53'),
(21, 4, 'TU-261009-024', 'LOT-TEST-998', 'SMC-VALVE-50A', 10, '2.50', '2026-10-09', 'SMC4$SMC-VALVE-50A$$10$$$$$TU-261009-024$$$$2.50$10/09/2026$LOT-TEST-998$SMC Factory 1', '2026-10-09 00:21:53'),
(22, 4, 'TU-261009-025', 'LOT-TEST-998', 'SMC-VALVE-50A', 10, '2.50', '2026-10-09', 'SMC4$SMC-VALVE-50A$$10$$$$$TU-261009-025$$$$2.50$10/09/2026$LOT-TEST-998$SMC Factory 1', '2026-10-09 00:21:53'),
(23, 4, 'TU-261009-026', 'LOT-TEST-998', 'SMC-VALVE-50A', 10, '2.50', '2026-10-09', 'SMC4$SMC-VALVE-50A$$10$$$$$TU-261009-026$$$$2.50$10/09/2026$LOT-TEST-998$SMC Factory 1', '2026-10-09 00:21:53'),
(24, 4, 'TU-261009-027', 'LOT-TEST-998', 'SMC-VALVE-50A', 10, '2.50', '2026-10-09', 'SMC4$SMC-VALVE-50A$$10$$$$$TU-261009-027$$$$2.50$10/09/2026$LOT-TEST-998$SMC Factory 1', '2026-10-09 00:21:53'),
(25, 4, 'TU-261009-028', 'LOT-TEST-998', 'SMC-VALVE-50A', 10, '2.50', '2026-10-09', 'SMC4$SMC-VALVE-50A$$10$$$$$TU-261009-028$$$$2.50$10/09/2026$LOT-TEST-998$SMC Factory 1', '2026-10-09 00:21:54'),
(26, 4, 'TU-261009-029', 'LOT-TEST-998', 'SMC-VALVE-50A', 10, '2.50', '2026-10-09', 'SMC4$SMC-VALVE-50A$$10$$$$$TU-261009-029$$$$2.50$10/09/2026$LOT-TEST-998$SMC Factory 1', '2026-10-09 00:21:54'),
(27, 4, 'TU-261009-030', 'LOT-TEST-998', 'SMC-VALVE-50A', 10, '2.50', '2026-10-09', 'SMC4$SMC-VALVE-50A$$10$$$$$TU-261009-030$$$$2.50$10/09/2026$LOT-TEST-998$SMC Factory 1', '2026-10-09 00:21:54'),
(28, 4, 'TU-261009-031', 'LOT-TEST-998', 'SMC-VALVE-50A', 10, '2.50', '2026-10-09', 'SMC4$SMC-VALVE-50A$$10$$$$$TU-261009-031$$$$2.50$10/09/2026$LOT-TEST-998$SMC Factory 1', '2026-10-09 00:21:54'),
(29, 4, 'TU-261009-032', 'LOT-TEST-998', 'SMC-VALVE-50A', 10, '2.50', '2026-10-09', 'SMC4$SMC-VALVE-50A$$10$$$$$TU-261009-032$$$$2.50$10/09/2026$LOT-TEST-998$SMC Factory 1', '2026-10-09 00:21:54'),
(30, 4, 'TU-261009-033', 'LOT-TEST-998', 'SMC-VALVE-50A', 10, '2.50', '2026-10-09', 'SMC4$SMC-VALVE-50A$$10$$$$$TU-261009-033$$$$2.50$10/09/2026$LOT-TEST-998$SMC Factory 1', '2026-10-09 00:21:54'),
(31, 4, 'TU-261009-034', 'LOT-TEST-998', 'SMC-VALVE-50A', 10, '2.50', '2026-10-09', 'SMC4$SMC-VALVE-50A$$10$$$$$TU-261009-034$$$$2.50$10/09/2026$LOT-TEST-998$SMC Factory 1', '2026-10-09 00:21:54'),
(32, 4, 'TU-261009-035', 'LOT-TEST-998', 'SMC-VALVE-50A', 10, '2.50', '2026-10-09', 'SMC4$SMC-VALVE-50A$$10$$$$$TU-261009-035$$$$2.50$10/09/2026$LOT-TEST-998$SMC Factory 1', '2026-10-09 00:21:54'),
(33, 4, 'TU-261009-036', 'LOT-TEST-998', 'SMC-VALVE-50A', 10, '2.50', '2026-10-09', 'SMC4$SMC-VALVE-50A$$10$$$$$TU-261009-036$$$$2.50$10/09/2026$LOT-TEST-998$SMC Factory 1', '2026-10-09 00:21:54'),
(34, 5, 'TU-261009-037', 'LOT-E505', 'MOTOR-STEP-N24', 8, '4.10', '2026-10-09', 'SMC4$MOTOR-STEP-N24$$8$$$$$TU-261009-037$$$$4.10$10/09/2026$LOT-E505$Nidec Corp', '2026-10-09 00:47:44'),
(35, 5, 'TU-261009-038', 'LOT-E505', 'MOTOR-STEP-N24', 2, '1.03', '2026-10-09', 'SMC4$MOTOR-STEP-N24$$2$$$$$TU-261009-038$$$$1.03$10/09/2026$LOT-E505$Nidec Corp', '2026-10-09 00:47:44'),
(36, 6, 'TU-261009-039', 'LOT-C303', 'PCB-MAIN-V2.1', 10, '1.90', '2026-10-09', 'SMC4$PCB-MAIN-V2.1$$10$$$$$TU-261009-039$$$$1.90$10/09/2026$LOT-C303$Samsung Elect', '2026-10-09 00:49:45');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `daily_box_seq`
--

CREATE TABLE `daily_box_seq` (
  `seq_date` date NOT NULL COMMENT 'Ngày ghi nhận số thứ tự',
  `current_seq` int(11) NOT NULL DEFAULT 0 COMMENT 'Số thứ tự cuối cùng trong ngày'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `daily_box_seq`
--

INSERT INTO `daily_box_seq` (`seq_date`, `current_seq`) VALUES
('2026-10-09', 39);

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `print_history`
--

CREATE TABLE `print_history` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL COMMENT 'ID của chỉ thị sản xuất',
  `slip_code` varchar(50) NOT NULL,
  `order_code` varchar(50) NOT NULL,
  `product_code` varchar(100) NOT NULL,
  `issue_month` varchar(20) DEFAULT '',
  `print_qty` int(11) NOT NULL COMMENT 'Số lượng tem sản phẩm đã in trong lượt này',
  `pack_qty` int(11) NOT NULL,
  `box_count` int(11) NOT NULL COMMENT 'Số tem thùng sinh ra',
  `is_odd_box` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1: Có thùng lẻ, 0: Thùng chẵn',
  `odd_qty` int(11) NOT NULL DEFAULT 0,
  `box_numbers` text NOT NULL COMMENT 'Danh sách Box No đã tạo (JSON Array)',
  `qr_data_sample` text DEFAULT NULL,
  `is_over_target` tinyint(1) NOT NULL DEFAULT 0,
  `approved_by_id` int(11) DEFAULT NULL,
  `approved_by_msnv` varchar(50) DEFAULT NULL,
  `operator_id` int(11) NOT NULL,
  `operator_name` varchar(100) NOT NULL,
  `operator_msnv` varchar(50) NOT NULL,
  `printer_destination` varchar(100) DEFAULT 'Sato Label 65x30 Thermal Printer',
  `note` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `print_history`
--

INSERT INTO `print_history` (`id`, `order_id`, `slip_code`, `order_code`, `product_code`, `issue_month`, `print_qty`, `pack_qty`, `box_count`, `is_odd_box`, `odd_qty`, `box_numbers`, `qr_data_sample`, `is_over_target`, `approved_by_id`, `approved_by_msnv`, `operator_id`, `operator_name`, `operator_msnv`, `printer_destination`, `note`, `created_at`) VALUES
(4, 6, 'PL-TEST-999', 'LOT-TEST-998', 'SMC-VALVE-50A', '', 150, 10, 15, 0, 0, '[\"TU-261009-022\",\"TU-261009-023\",\"TU-261009-024\",\"TU-261009-025\",\"TU-261009-026\",\"TU-261009-027\",\"TU-261009-028\",\"TU-261009-029\",\"TU-261009-030\",\"TU-261009-031\",\"TU-261009-032\",\"TU-261009-033\",\"TU-261009-034\",\"TU-261009-035\",\"TU-261009-036\"]', 'SMC4$SMC-VALVE-50A$$10$$$$$TU-261009-022$$$$2.50$10/09/2026$LOT-TEST-998$SMC Factory 1', 1, 2, 'NV-001', 3, 'Trần Thị Vận Hành (Viewer)', 'NV-002', 'Thermal Label 65x30', '', '2026-10-09 00:21:53'),
(5, 5, 'PL-2610-05', 'LOT-E505', 'MOTOR-STEP-N24', '', 10, 8, 2, 1, 2, '[\"TU-261009-037\",\"TU-261009-038\"]', 'SMC4$MOTOR-STEP-N24$$8$$$$$TU-261009-037$$$$4.10$10/09/2026$LOT-E505$Nidec Corp', 0, NULL, NULL, 2, 'Editor', 'NV-001', 'Thermal Label 65x30', '', '2026-10-09 00:47:44'),
(6, 3, 'PL-2610-03', 'LOT-C303', 'PCB-MAIN-V2.1', '', 10, 20, 1, 1, 10, '[\"TU-261009-039\"]', 'SMC4$PCB-MAIN-V2.1$$10$$$$$TU-261009-039$$$$1.90$10/09/2026$LOT-C303$Samsung Elect', 0, NULL, NULL, 3, 'Viewer', 'NV-002', 'Thermal Label 65x30', '', '2026-10-09 00:49:45');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `production_orders`
--

CREATE TABLE `production_orders` (
  `id` int(11) NOT NULL,
  `slip_code` varchar(50) NOT NULL COMMENT 'Mã phiếu chỉ thị (Barcode/QR phiếu)',
  `order_code` varchar(50) NOT NULL COMMENT 'Mã chỉ thị (Lot No / CTSX)',
  `product_code` varchar(100) NOT NULL COMMENT 'Mã sản phẩm',
  `issue_month` varchar(20) DEFAULT '' COMMENT 'Tháng phát hành (vd: 10/2026 hoặc 2026-10)',
  `target_qty` int(11) NOT NULL COMMENT 'Số lượng chỉ thị ban đầu',
  `printed_qty` int(11) NOT NULL DEFAULT 0 COMMENT 'Số lượng tem sản phẩm đã in tích lũy',
  `remaining_qty` int(11) NOT NULL COMMENT 'Số lượng còn lại = target_qty - printed_qty',
  `pack_qty` int(11) NOT NULL DEFAULT 1 COMMENT 'Quy cách đóng gói (tự động mapping từ product_specs)',
  `supplier` varchar(100) DEFAULT '' COMMENT 'Nhà cung cấp (tự động mapping từ product_specs)',
  `weight_per_box` decimal(8,3) DEFAULT 0.000 COMMENT 'Trọng lượng thùng (Kg)',
  `invoice_no` varchar(50) DEFAULT '' COMMENT 'Số hóa đơn (nếu có)',
  `order_no` varchar(50) DEFAULT '' COMMENT 'Mã đơn PO (nếu có)',
  `bundle_no` varchar(50) DEFAULT '' COMMENT 'Số bundle (nếu có)',
  `status` enum('pending','in_progress','completed','cancelled','paused') NOT NULL DEFAULT 'pending',
  `note` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `production_orders`
--

INSERT INTO `production_orders` (`id`, `slip_code`, `order_code`, `product_code`, `issue_month`, `target_qty`, `printed_qty`, `remaining_qty`, `pack_qty`, `supplier`, `weight_per_box`, `invoice_no`, `order_no`, `bundle_no`, `status`, `note`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'PL-2610-01', 'LOT-A101', 'SMC-VALVE-50A', '10/2026', 100, 0, 100, 10, 'SMC Factory 1', 2.500, '', '', '', 'pending', NULL, NULL, '2026-10-08 23:49:53', '2026-10-08 23:49:53'),
(2, 'PL-2610-02', 'LOT-B202', 'SENSOR-PX-40M', '10/2026', 60, 0, 60, 5, 'Omron Corp', 1.200, '', '', '', 'pending', NULL, NULL, '2026-10-08 23:49:53', '2026-10-08 23:49:53'),
(3, 'PL-2610-03', 'LOT-C303', 'PCB-MAIN-V2.1', '10/2026', 200, 30, 170, 20, 'Samsung Elect', 3.800, '', '', '', 'in_progress', NULL, NULL, '2026-10-08 23:49:53', '2026-10-09 00:49:45'),
(4, 'PL-2610-04', 'LOT-D404', 'CONNECTOR-8PIN', '10/2026', 125, 0, 125, 10, 'Molex Inc', 0.850, '', '', '', 'pending', NULL, NULL, '2026-10-08 23:49:53', '2026-10-08 23:49:53'),
(5, 'PL-2610-05', 'LOT-E505', 'MOTOR-STEP-N24', '10/2026', 80, 10, 70, 8, 'Nidec Corp', 4.100, '', '', '', 'in_progress', NULL, NULL, '2026-10-08 23:49:53', '2026-10-09 00:47:44'),
(6, 'PL-TEST-999', 'LOT-TEST-998', 'SMC-VALVE-50A', '10/2026', 50, 150, -100, 10, 'SMC Factory 1', 2.500, '', '', '', 'completed', '', NULL, '2026-10-09 00:03:24', '2026-10-09 00:21:53');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `product_specs`
--

CREATE TABLE `product_specs` (
  `id` int(11) NOT NULL,
  `product_code` varchar(100) NOT NULL COMMENT 'Mã sản phẩm (Material Name)',
  `pack_qty` int(11) NOT NULL DEFAULT 1 COMMENT 'Quy cách đóng gói (số con / thùng)',
  `supplier` varchar(100) DEFAULT '' COMMENT 'Nhà cung cấp liên kết',
  `weight_per_box` decimal(8,3) DEFAULT 0.000 COMMENT 'Trọng lượng thùng (Kg)',
  `unit` varchar(20) DEFAULT 'pcs' COMMENT 'Đơn vị tính',
  `description` varchar(255) DEFAULT '' COMMENT 'Mô tả chi tiết linh kiện / sản phẩm',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `product_specs`
--

INSERT INTO `product_specs` (`id`, `product_code`, `pack_qty`, `supplier`, `weight_per_box`, `unit`, `description`, `created_at`, `updated_at`) VALUES
(1, 'SMC-VALVE-50A', 10, 'SMC Factory 1', 2.500, 'pcs', 'Van điều khiển khí nén SMC 50A', '2026-10-08 23:49:53', '2026-10-08 23:49:53'),
(2, 'SENSOR-PX-40M', 5, 'Omron Corp', 1.200, 'pcs', 'Cảm biến quang điện tử Omron', '2026-10-08 23:49:53', '2026-10-08 23:49:53'),
(3, 'PCB-MAIN-V2.1', 20, 'Samsung Elect', 3.800, 'pcs', 'Bo mạch chủ điều khiển V2.1', '2026-10-08 23:49:53', '2026-10-08 23:49:53'),
(4, 'CONNECTOR-8PIN', 10, 'Molex Inc', 0.850, 'pcs', 'Đầu nối giắc cắm 8 chân Molex', '2026-10-08 23:49:53', '2026-10-08 23:49:53'),
(5, 'MOTOR-STEP-N24', 8, 'Nidec Corp', 4.100, 'pcs', 'Động cơ bước công nghiệp NEMA 24', '2026-10-08 23:49:53', '2026-10-08 23:49:53'),
(6, 'SMC-CYLINDER-MGPM', 15, 'SMC Corporation', 3.450, 'pcs', 'Xylanh dẫn hướng khí nén', '2026-10-08 23:49:53', '2026-10-08 23:49:53'),
(7, 'RELAY-OMRON-MY4N', 50, 'Omron Industrial', 1.120, 'pcs', 'Rơ-le trung gian 14 chân 24VDC', '2026-10-08 23:49:53', '2026-10-08 23:49:53'),
(8, 'CABLE-SENSOR-M12', 25, 'Phoenix Contact', 2.800, 'pcs', 'Cáp tín hiệu cảm biến M12', '2026-10-08 23:49:53', '2026-10-08 23:49:53'),
(9, 'POWER-SUPPLY-24V', 10, 'Meanwell Tech', 4.250, 'pcs', 'Bộ nguồn tổ ong 24V-10A', '2026-10-08 23:49:53', '2026-10-08 23:49:53'),
(10, 'SOLENOID-VALVE-4V', 12, 'Airtac Vietnam', 1.850, 'pcs', 'Van điện từ 5/2 Airtac', '2026-10-08 23:49:53', '2026-10-08 23:49:53');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(50) NOT NULL COMMENT 'Khóa cấu hình',
  `setting_value` text NOT NULL COMMENT 'Giá trị cấu hình',
  `description` varchar(255) DEFAULT '',
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `system_settings`
--

INSERT INTO `system_settings` (`id`, `setting_key`, `setting_value`, `description`, `updated_at`) VALUES
(1, 'qr_prefix', 'SMC4$', 'Tiền tố định danh mã QR tem thùng', '2026-10-08 23:49:53'),
(2, 'qr_delimiter', '$', 'Ký tự phân cách giữa các trường dữ liệu QR', '2026-10-08 23:49:53'),
(3, 'qr_suffix', '', 'Hậu tố kết thúc chuỗi mã QR', '2026-10-08 23:49:53'),
(4, 'qr_date_format', 'd/m/Y', 'Định dạng ngày trong mã QR (d/m/Y, Y-m-d, Ymd)', '2026-10-08 23:49:53'),
(5, 'qr_fields', '[\"material_name\",\"empty\",\"qty\",\"empty\",\"empty\",\"empty\",\"empty\",\"box_no\",\"invoice_no\",\"order_no\",\"bundle_no\",\"weight\",\"input_date\",\"lot_no\",\"supplier\"]', 'Cấu trúc thứ tự các trường dữ liệu sinh mã QR', '2026-10-08 23:49:53'),
(6, 'label_width_mm', '65', 'Chiều rộng tem nhãn nhiệt (mm)', '2026-10-08 23:49:53'),
(7, 'label_height_mm', '30', 'Chiều cao tem nhãn nhiệt (mm)', '2026-10-08 23:49:53');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `employee_code` varchar(50) NOT NULL COMMENT 'Mã số nhân viên (MSNV) dùng duyệt vượt mức',
  `role` enum('admin','editor','viewer') NOT NULL DEFAULT 'viewer' COMMENT 'Phân quyền người dùng',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1: Hoạt động, 0: Khóa',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `full_name`, `employee_code`, `role`, `status`, `created_at`, `updated_at`) VALUES
(1, 'admin', 'admin123', 'Quản Trị Viên (Admin)', 'ADM-001', 'admin', 1, '2026-10-08 23:49:53', '2026-10-09 00:45:00'),
(2, 'editor', 'editor123', 'Editor', 'NV-001', 'editor', 1, '2026-10-08 23:49:53', '2026-10-09 00:46:27'),
(3, 'viewer', 'viewer123', 'Viewer', 'NV-002', 'viewer', 1, '2026-10-08 23:49:53', '2026-10-09 00:46:59');

--
-- Chỉ mục cho các bảng đã đổ
--

--
-- Chỉ mục cho bảng `box_labels`
--
ALTER TABLE `box_labels`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_label_history` (`history_id`),
  ADD KEY `idx_label_box` (`box_no`);

--
-- Chỉ mục cho bảng `daily_box_seq`
--
ALTER TABLE `daily_box_seq`
  ADD PRIMARY KEY (`seq_date`);

--
-- Chỉ mục cho bảng `print_history`
--
ALTER TABLE `print_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_history_order` (`order_id`),
  ADD KEY `idx_history_slip` (`slip_code`),
  ADD KEY `idx_history_lot` (`order_code`),
  ADD KEY `idx_history_product` (`product_code`),
  ADD KEY `idx_history_date` (`created_at`),
  ADD KEY `idx_history_operator` (`operator_id`);

--
-- Chỉ mục cho bảng `production_orders`
--
ALTER TABLE `production_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slip_code` (`slip_code`),
  ADD KEY `idx_slip_code` (`slip_code`),
  ADD KEY `idx_order_code` (`order_code`),
  ADD KEY `idx_product_code` (`product_code`),
  ADD KEY `idx_issue_month` (`issue_month`),
  ADD KEY `idx_status` (`status`);

--
-- Chỉ mục cho bảng `product_specs`
--
ALTER TABLE `product_specs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `product_code` (`product_code`),
  ADD KEY `idx_spec_product_code` (`product_code`);

--
-- Chỉ mục cho bảng `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Chỉ mục cho bảng `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `employee_code` (`employee_code`);

--
-- AUTO_INCREMENT cho các bảng đã đổ
--

--
-- AUTO_INCREMENT cho bảng `box_labels`
--
ALTER TABLE `box_labels`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT cho bảng `print_history`
--
ALTER TABLE `print_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT cho bảng `production_orders`
--
ALTER TABLE `production_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT cho bảng `product_specs`
--
ALTER TABLE `product_specs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT cho bảng `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT cho bảng `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Các ràng buộc cho các bảng đã đổ
--

--
-- Các ràng buộc cho bảng `box_labels`
--
ALTER TABLE `box_labels`
  ADD CONSTRAINT `box_labels_ibfk_1` FOREIGN KEY (`history_id`) REFERENCES `print_history` (`id`) ON DELETE CASCADE;

--
-- Các ràng buộc cho bảng `print_history`
--
ALTER TABLE `print_history`
  ADD CONSTRAINT `print_history_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `production_orders` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
