<?php
/**
 * AJAX: TẠM DỪNG / TIẾP TỤC IN TEM CHỈ THỊ SẢN XUẤT (TOGGLE STATUS)
 * Phân quyền: Editor & Admin
 * Khóa hoặc kích hoạt khả năng in tem của một chỉ thị cụ thể
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

if (!canManageDirectives()) {
    echo json_encode(['success' => false, 'message' => 'Bạn không có quyền thay đổi trạng thái in của chỉ thị!']);
    exit;
}

$orderId = (int)($_POST['order_id'] ?? 0);

if ($orderId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Mã chỉ thị không hợp lệ!']);
    exit;
}

$pdo = getDbConnection();

try {
    $stmtCur = $pdo->prepare("SELECT * FROM `production_orders` WHERE `id` = ?");
    $stmtCur->execute([$orderId]);
    $order = $stmtCur->fetch();

    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy chỉ thị trong cơ sở dữ liệu!']);
        exit;
    }

    $isCurrentlyPaused = ($order['status'] === 'paused');

    if ($isCurrentlyPaused) {
        $remaining = (int)$order['remaining_qty'];
        $printed   = (int)$order['printed_qty'];
        $newStatus = ($remaining <= 0) ? 'completed' : (($printed > 0) ? 'in_progress' : 'pending');
        
        $stmtToggle = $pdo->prepare("UPDATE `production_orders` SET `status` = ?, `updated_at` = NOW() WHERE `id` = ?");
        $stmtToggle->execute([$newStatus, $orderId]);

        echo json_encode([
            'success'     => true,
            'is_paused'   => false,
            'new_status'  => $newStatus,
            'order_id'    => $orderId,
            'slip_code'   => $order['slip_code'],
            'order_code'  => $order['order_code'],
            'message'     => "Đã TIẾP TỤC cho phép in tem chỉ thị {$order['slip_code']} ({$order['order_code']})!"
        ]);
    } else {
        $stmtToggle = $pdo->prepare("UPDATE `production_orders` SET `status` = 'paused', `updated_at` = NOW() WHERE `id` = ?");
        $stmtToggle->execute([$orderId]);

        echo json_encode([
            'success'     => true,
            'is_paused'   => true,
            'new_status'  => 'paused',
            'order_id'    => $orderId,
            'slip_code'   => $order['slip_code'],
            'order_code'  => $order['order_code'],
            'message'     => "Đã TẠM DỪNG in tem chỉ thị {$order['slip_code']} ({$order['order_code']})! Nhân viên sẽ không thể in tem cho đến khi được kích hoạt lại."
        ]);
    }

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Lỗi CSDL: ' . $e->getMessage()]);
}

