<?php
/**
 * AJAX: DUYỆT VƯỢT ĐỊNH MỨC & THỰC THI LỆNH IN LIỀN MẠCH
 * Xác thực MSNV & Mật khẩu Admin/Editor, tự động ghi nhận người duyệt và sinh tem thùng
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

$msnv           = trim($_POST['msnv'] ?? '');
$password       = trim($_POST['password'] ?? $_POST['pass'] ?? '');
$overrideReason = trim($_POST['override_reason'] ?? '');

if (empty($msnv) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Vui lòng nhập đầy đủ Mã số nhân viên (MSNV) và Mật khẩu người duyệt!']);
    exit;
}

if (empty($overrideReason)) {
    echo json_encode(['success' => false, 'message' => 'Vui lòng nhập Lý do in vượt định mức!']);
    exit;
}

$pdo = getDbConnection();

// 1. Xác thực chi tiết quyền Quản lý / Admin / Editor
$authResult = verifyManagerOverrideDetailed($msnv, $password, $pdo);
if (!$authResult['success']) {
    echo json_encode([
        'success' => false,
        'message' => $authResult['message']
    ]);
    exit;
}

$managerInfo = $authResult['user'];

// 2. Nếu không gửi kèm thông tin đơn in, chỉ trả về kết quả xác thực ủy quyền
$orderId  = (int)($_POST['order_id'] ?? 0);
$printQty = (int)($_POST['print_qty'] ?? 0);

if ($orderId <= 0 || $printQty <= 0) {
    echo json_encode([
        'success'         => true,
        'message'         => $authResult['message'],
        'override_reason' => $overrideReason,
        'manager'         => [
            'id'            => $managerInfo['id'],
            'employee_code' => $managerInfo['employee_code'],
            'full_name'     => $managerInfo['full_name'],
            'role'          => $managerInfo['role']
        ]
    ]);
    exit;
}

// 3. Thực thi in trực tiếp: truyền thông tin người duyệt và lý do vượt định mức vào submit_print.php
$_POST['admin_msnv']      = $managerInfo['employee_code'];
$_POST['admin_pass']      = $password;
$_POST['override_reason'] = $overrideReason;

require __DIR__ . '/submit_print.php';


