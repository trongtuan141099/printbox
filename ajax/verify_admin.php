<?php
/**
 * AJAX: XÁC THỰC MÃ SỐ NHÂN VIÊN (MSNV) & MẬT KHẨU ADMIN/EDITOR DUYỆT VƯỢT ĐỊNH MỨC
 * Bắt buộc đối chiếu cả MSNV và Mật khẩu trong cơ sở dữ liệu
 */
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Phiên làm việc đã hết hạn. Vui lòng đăng nhập lại!']);
    exit;
}

$msnv     = trim($_POST['msnv'] ?? '');
$password = trim($_POST['password'] ?? $_POST['pass'] ?? $_POST['pin'] ?? '');

if (empty($msnv)) {
    echo json_encode(['success' => false, 'message' => 'Vui lòng nhập Mã số nhân viên (MSNV) của Quản lý / Admin / Editor!']);
    exit;
}

if (empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Vui lòng nhập Mật khẩu/PIN xác thực tài khoản của Quản lý / Admin / Editor!']);
    exit;
}

try {
    $pdo = getDbConnection();
    $manager = verifyManagerOverride($msnv, $password, $pdo);

    if ($manager) {
        echo json_encode([
            'success' => true,
            'message' => 'Xác thực duyệt vượt định mức thành công!',
            'admin' => [
                'id'            => $manager['id'],
                'full_name'     => $manager['full_name'],
                'employee_code' => $manager['employee_code'],
                'role'          => $manager['role']
            ]
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'MSNV hoặc Mật khẩu không chính xác, hoặc tài khoản không có quyền Admin/Editor duyệt vượt định mức!'
        ]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Lỗi kiểm tra CSDL: ' . $e->getMessage()]);
}
