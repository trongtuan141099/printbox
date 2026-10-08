-- =======================================================
-- HỆ THỐNG QUẢN LÝ IN TEM SẢN XUẤT NHÀ MÁY (PRINTBOX INTRANET)
-- CSDL MYSQL / PHPMYADMIN - CẬP NHẬT: THÁNG PHÁT HÀNH, BẢNG QUY CÁCH ĐỘC LẬP & CẤU HÌNH QR ĐỘNG
-- =======================================================

CREATE DATABASE IF NOT EXISTS `printbox_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `printbox_db`;

-- XÓA BẢNG CŨ THEO THỨ TỰ RÀNG BUỘC KHÓA NGOẠI
DROP TABLE IF EXISTS `box_labels`;
DROP TABLE IF EXISTS `print_history`;
DROP TABLE IF EXISTS `daily_box_seq`;
DROP TABLE IF EXISTS `production_orders`;
DROP TABLE IF EXISTS `product_specs`;
DROP TABLE IF EXISTS `system_settings`;
DROP TABLE IF EXISTS `users`;

-- 1. BẢNG NGƯỜI DÙNG & PHÂN QUYỀN RBAC (Admin, Editor, Viewer)
CREATE TABLE `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(100) NOT NULL,
    `employee_code` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Mã số nhân viên (MSNV) dùng duyệt vượt mức',
    `role` ENUM('admin', 'editor', 'viewer') NOT NULL DEFAULT 'viewer' COMMENT 'Phân quyền người dùng',
    `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1: Hoạt động, 0: Khóa',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. BẢNG QUY CÁCH ĐÓNG GÓI SẢN PHẨM RIÊNG BIỆT (PRODUCT_SPECS)
-- Tự động ánh xạ quy cách đóng gói và nhà cung cấp theo mã sản phẩm
CREATE TABLE `product_specs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_code` VARCHAR(100) NOT NULL UNIQUE COMMENT 'Mã sản phẩm (Material Name)',
    `pack_qty` INT NOT NULL DEFAULT 1 COMMENT 'Quy cách đóng gói (số con / thùng)',
    `supplier` VARCHAR(100) DEFAULT '' COMMENT 'Nhà cung cấp liên kết',
    `weight_per_box` DECIMAL(8,3) DEFAULT 0.000 COMMENT 'Trọng lượng thùng (Kg)',
    `unit` VARCHAR(20) DEFAULT 'pcs' COMMENT 'Đơn vị tính',
    `description` VARCHAR(255) DEFAULT '' COMMENT 'Mô tả chi tiết linh kiện / sản phẩm',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_spec_product_code` (`product_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. BẢNG CHỈ THỊ SẢN XUẤT (PRODUCTION_ORDERS - CTSX)
-- Đã thêm: issue_month (Tháng phát hành). Quy cách & NCC tự động lấy từ product_specs
CREATE TABLE `production_orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `slip_code` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Mã phiếu chỉ thị (Barcode/QR phiếu)',
    `order_code` VARCHAR(50) NOT NULL COMMENT 'Mã chỉ thị (Lot No / CTSX)',
    `product_code` VARCHAR(100) NOT NULL COMMENT 'Mã sản phẩm',
    `issue_month` VARCHAR(20) DEFAULT '' COMMENT 'Tháng phát hành (vd: 10/2026 hoặc 2026-10)',
    `target_qty` INT NOT NULL COMMENT 'Số lượng chỉ thị ban đầu',
    `printed_qty` INT NOT NULL DEFAULT 0 COMMENT 'Số lượng tem sản phẩm đã in tích lũy',
    `remaining_qty` INT NOT NULL COMMENT 'Số lượng còn lại = target_qty - printed_qty',
    `pack_qty` INT NOT NULL DEFAULT 1 COMMENT 'Quy cách đóng gói (tự động mapping từ product_specs)',
    `supplier` VARCHAR(100) DEFAULT '' COMMENT 'Nhà cung cấp (tự động mapping từ product_specs)',
    `weight_per_box` DECIMAL(8,3) DEFAULT 0.000 COMMENT 'Trọng lượng thùng (Kg)',
    `invoice_no` VARCHAR(50) DEFAULT '' COMMENT 'Số hóa đơn (nếu có)',
    `order_no` VARCHAR(50) DEFAULT '' COMMENT 'Mã đơn PO (nếu có)',
    `bundle_no` VARCHAR(50) DEFAULT '' COMMENT 'Số bundle (nếu có)',
    `status` ENUM('pending', 'in_progress', 'completed', 'cancelled', 'paused') NOT NULL DEFAULT 'pending',
    `note` VARCHAR(255) DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_slip_code` (`slip_code`),
    INDEX `idx_order_code` (`order_code`),
    INDEX `idx_product_code` (`product_code`),
    INDEX `idx_issue_month` (`issue_month`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. BẢNG CẤU HÌNH HỆ THỐNG & ĐỘNG HÓA MÃ QR (SYSTEM_SETTINGS)
CREATE TABLE `system_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Khóa cấu hình',
    `setting_value` TEXT NOT NULL COMMENT 'Giá trị cấu hình',
    `description` VARCHAR(255) DEFAULT '',
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. BẢNG QUẢN LÝ SỐ THỨ TỰ THÙNG THEO NGÀY (DAILY_BOX_SEQ)
-- Định dạng Box No: TU-YYMMDD-XXX (reset về 001 mỗi ngày mới)
CREATE TABLE `daily_box_seq` (
    `seq_date` DATE NOT NULL PRIMARY KEY COMMENT 'Ngày ghi nhận số thứ tự',
    `current_seq` INT NOT NULL DEFAULT 0 COMMENT 'Số thứ tự cuối cùng trong ngày'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. BẢNG LỊCH SỬ IN TEM (PRINT_HISTORY)
CREATE TABLE `print_history` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL COMMENT 'ID của chỉ thị sản xuất',
    `slip_code` VARCHAR(50) NOT NULL,
    `order_code` VARCHAR(50) NOT NULL,
    `product_code` VARCHAR(100) NOT NULL,
    `issue_month` VARCHAR(20) DEFAULT '',
    `print_qty` INT NOT NULL COMMENT 'Số lượng tem sản phẩm đã in trong lượt này',
    `pack_qty` INT NOT NULL,
    `box_count` INT NOT NULL COMMENT 'Số tem thùng sinh ra',
    `is_odd_box` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1: Có thùng lẻ, 0: Thùng chẵn',
    `odd_qty` INT NOT NULL DEFAULT 0,
    `box_numbers` TEXT NOT NULL COMMENT 'Danh sách Box No đã tạo (JSON Array)',
    `qr_data_sample` TEXT DEFAULT NULL,
    `is_over_target` TINYINT(1) NOT NULL DEFAULT 0,
    `approved_by_id` INT DEFAULT NULL COMMENT 'User ID của Admin/Editor duyệt vượt mức',
    `approved_by_msnv` VARCHAR(50) DEFAULT NULL,
    `operator_id` INT NOT NULL,
    `operator_name` VARCHAR(100) NOT NULL,
    `operator_msnv` VARCHAR(50) NOT NULL,
    `printer_destination` VARCHAR(100) DEFAULT 'Sato Label 65x30 Thermal Printer',
    `note` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_history_order` (`order_id`),
    INDEX `idx_history_slip` (`slip_code`),
    INDEX `idx_history_lot` (`order_code`),
    INDEX `idx_history_product` (`product_code`),
    INDEX `idx_history_date` (`created_at`),
    INDEX `idx_history_operator` (`operator_id`),
    FOREIGN KEY (`order_id`) REFERENCES `production_orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. BẢNG CHI TIẾT TỪNG TEM THÙNG (BOX_LABELS)
CREATE TABLE `box_labels` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `history_id` INT NOT NULL,
    `box_no` VARCHAR(50) NOT NULL COMMENT 'Mã thùng: TU-YYMMDD-XXX',
    `order_code` VARCHAR(50) NOT NULL,
    `product_code` VARCHAR(100) NOT NULL,
    `qty` INT NOT NULL,
    `weight` VARCHAR(50) DEFAULT '',
    `input_date` DATE NOT NULL,
    `qr_content` TEXT NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_label_history` (`history_id`),
    INDEX `idx_label_box` (`box_no`),
    FOREIGN KEY (`history_id`) REFERENCES `print_history`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================
-- DỮ LIỆU KHỞI TẠO MẪU
-- Mật khẩu dạng Plain Text theo yêu cầu
-- =======================================================
INSERT INTO `users` (`id`, `username`, `password`, `full_name`, `employee_code`, `role`, `status`) VALUES
(1, 'admin', 'admin123', 'Quản Trị Viên (Admin)', 'ADM-001', 'admin', 1),
(2, 'editor', 'editor123', 'Nguyễn Văn Kỹ Thuật (Editor)', 'NV-001', 'editor', 1),
(3, 'viewer', 'viewer123', 'Trần Thị Vận Hành (Viewer)', 'NV-002', 'viewer', 1);

-- DỮ LIỆU BẢNG QUY CÁCH ĐÓNG GÓI ĐỘC LẬP (PRODUCT_SPECS)
INSERT INTO `product_specs` (`product_code`, `pack_qty`, `supplier`, `weight_per_box`, `unit`, `description`) VALUES
('SMC-VALVE-50A', 10, 'SMC Factory 1', 2.500, 'pcs', 'Van điều khiển khí nén SMC 50A'),
('SENSOR-PX-40M', 5, 'Omron Corp', 1.200, 'pcs', 'Cảm biến quang điện tử Omron'),
('PCB-MAIN-V2.1', 20, 'Samsung Elect', 3.800, 'pcs', 'Bo mạch chủ điều khiển V2.1'),
('CONNECTOR-8PIN', 10, 'Molex Inc', 0.850, 'pcs', 'Đầu nối giắc cắm 8 chân Molex'),
('MOTOR-STEP-N24', 8, 'Nidec Corp', 4.100, 'pcs', 'Động cơ bước công nghiệp NEMA 24'),
('SMC-CYLINDER-MGPM', 15, 'SMC Corporation', 3.450, 'pcs', 'Xylanh dẫn hướng khí nén'),
('RELAY-OMRON-MY4N', 50, 'Omron Industrial', 1.120, 'pcs', 'Rơ-le trung gian 14 chân 24VDC'),
('CABLE-SENSOR-M12', 25, 'Phoenix Contact', 2.800, 'pcs', 'Cáp tín hiệu cảm biến M12'),
('POWER-SUPPLY-24V', 10, 'Meanwell Tech', 4.250, 'pcs', 'Bộ nguồn tổ ong 24V-10A'),
('SOLENOID-VALVE-4V', 12, 'Airtac Vietnam', 1.850, 'pcs', 'Van điện từ 5/2 Airtac');

-- CẤU HÌNH HỆ THỐNG MÃ QR ĐỘNG (SYSTEM_SETTINGS)
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
('qr_prefix', 'SMC4$', 'Tiền tố định danh mã QR tem thùng'),
('qr_delimiter', '$', 'Ký tự phân cách giữa các trường dữ liệu QR'),
('qr_suffix', '', 'Hậu tố kết thúc chuỗi mã QR'),
('qr_date_format', 'd/m/Y', 'Định dạng ngày trong mã QR (d/m/Y, Y-m-d, Ymd)'),
('qr_fields', '[\"material_name\",\"empty\",\"qty\",\"empty\",\"empty\",\"empty\",\"empty\",\"box_no\",\"invoice_no\",\"order_no\",\"bundle_no\",\"weight\",\"input_date\",\"lot_no\",\"supplier\"]', 'Cấu trúc thứ tự các trường dữ liệu sinh mã QR'),
('label_width_mm', '65', 'Chiều rộng tem nhãn nhiệt (mm)'),
('label_height_mm', '30', 'Chiều cao tem nhãn nhiệt (mm)');

-- DỮ LIỆU CHỈ THỊ SẢN XUẤT BAN ĐẦU (CTSX)
-- Ghi nhận issue_month (Tháng phát hành) và quy cách tự động từ product_specs
INSERT INTO `production_orders` 
(`slip_code`, `order_code`, `product_code`, `issue_month`, `target_qty`, `printed_qty`, `remaining_qty`, `pack_qty`, `supplier`, `weight_per_box`, `status`) 
VALUES
('PL-2610-01', 'LOT-A101', 'SMC-VALVE-50A', '10/2026', 100, 0, 100, 10, 'SMC Factory 1', 2.500, 'pending'),
('PL-2610-02', 'LOT-B202', 'SENSOR-PX-40M', '10/2026', 60, 0, 60, 5, 'Omron Corp', 1.200, 'pending'),
('PL-2610-03', 'LOT-C303', 'PCB-MAIN-V2.1', '10/2026', 200, 20, 180, 20, 'Samsung Elect', 3.800, 'in_progress'),
('PL-2610-04', 'LOT-D404', 'CONNECTOR-8PIN', '10/2026', 125, 0, 125, 10, 'Molex Inc', 0.850, 'pending'),
('PL-2610-05', 'LOT-E505', 'MOTOR-STEP-N24', '10/2026', 80, 0, 80, 8, 'Nidec Corp', 4.100, 'pending');
