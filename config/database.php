<?php
/**
 * CẤU HÌNH KẾT NỐI CƠ SỞ DỮ LIỆU MYSQL / PHPMYADMIN
 * Quản lý kết nối PDO bảo mật, chuẩn UTF-8 Tiếng Việt
 */

// Thiết lập múi giờ Việt Nam
date_default_timezone_set('Asia/Ho_Chi_Minh');

// Cấu hình CSDL
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'printbox_db');

/**
 * Lấy kết nối PDO
 * @param bool $selectDb Có chọn DB ngay không
 * @return PDO
 */
function getDbConnection($selectDb = true) {
    static $pdoInstance = null;

    if ($selectDb && $pdoInstance !== null) {
        return $pdoInstance;
    }

    try {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4";
        if ($selectDb) {
            $dsn .= ";dbname=" . DB_NAME;
        }

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone = '+07:00'"
        ];

        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        
        if ($selectDb) {
            $pdoInstance = $pdo;
        }

        return $pdo;
    } catch (PDOException $e) {
        // Thông báo lỗi thân thiện khi chưa khởi tạo CSDL
        if (php_sapi_name() === 'cli') {
            throw $e;
        }
        die('<div style="font-family:Arial;max-width:600px;margin:50px auto;padding:20px;border:1px solid #e74c3c;border-radius:8px;background:#fdf2f2;">
            <h3 style="color:#c0392b;margin-top:0;">Lỗi kết nối Cơ sở dữ liệu!</h3>
            <p>Không thể kết nối đến máy chủ MySQL hoặc CSDL <strong>' . htmlspecialchars(DB_NAME) . '</strong> chưa được tạo.</p>
            <p><strong>Chi tiết:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>
            <p>Vui lòng đảm bảo MySQL trong XAMPP đang BẬT (Start) và chạy file <code>setup_db.php</code> để khởi tạo.</p>
        </div>');
    }
}

