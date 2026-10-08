<?php
/**
 * Database Initializer & Migrator
 * Chạy script này để tự động thiết lập CSDL printbox_db
 */

require_once __DIR__ . '/config/database.php';

try {
    $pdo = getDbConnection(false); // Kết nối không chọn DB trước
    
    // Tạo CSDL nếu chưa có
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `printbox_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
    $pdo->exec("USE `printbox_db`;");

    // Đọc file SQL
    $sqlFile = __DIR__ . '/database.sql';
    if (!file_exists($sqlFile)) {
        die("Không tìm thấy file database.sql!");
    }

    $sqlContent = file_get_contents($sqlFile);
    $pdo->exec($sqlContent);

    echo "=== KHỞI TẠO CSDL THÀNH CÔNG ===\n";
    echo "Database: printbox_db\n";
    echo "1. Đã tạo bảng product_specs (Quy cách đóng gói riêng biệt)\n";
    echo "2. Đã tạo bảng production_orders với trường issue_month (Tháng phát hành)\n";
    echo "3. Đã tạo bảng system_settings (Cấu hình động mã QR)\n";
    echo "Tài khoản mặc định (Mật khẩu Plain Text):\n";
    echo "1. admin   / admin123  (MSNV: ADM-001) - Quyền: Admin (Toàn quyền, cấu hình hệ thống, duyệt vượt mức)\n";
    echo "2. editor  / editor123 (MSNV: NV-001)  - Quyền: Editor (Thêm/sửa chỉ thị, import/export Excel, in tem)\n";
    echo "3. viewer  / viewer123 (MSNV: NV-002)  - Quyền: Viewer (Xem dữ liệu & thực thi in tem thùng)\n";

} catch (PDOException $e) {
    die("Lỗi khởi tạo CSDL: " . $e->getMessage() . "\n");
}
