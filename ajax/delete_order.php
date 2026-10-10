<?php
/**
 * AJAX: XÓA CHỈ THỊ SẢN XUẤT (DELETE PRODUCTION DIRECTIVE)
 * Phân quyền bảo mật: Chỉ cho phép Admin và Editor. Viewer bị từ chối tuyệt đối.
 * Xóa an toàn kèm theo lịch sử in và chi tiết tem liên quan trong Database Transaction.
 */
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// 1. Kiểm tra trạng thái đăng nhập
if (!isLoggedIn()) {
    echo json_encode([
        'success' => false,
        'message' => 'Phiên làm việc đã hết hạn. Vui lòng đăng nhập lại!'
    ]);
    exit;
}

// 2. Phân quyền bảo mật chặt chẽ: Chỉ Admin và Editor được phép xóa
if (!canManageDirectives()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'LỖI PHÂN QUYỀN: Bạn không có quyền xóa chỉ thị sản xuất! Thao tác này chỉ dành riêng cho Admin hoặc Editor.'
    ]);
    exit;
}

$orderId = (int)($_POST['order_id'] ?? 0);
if ($orderId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Mã ID chỉ thị cần xóa không hợp lệ!'
    ]);
    exit;
}

$currentUser = getCurrentUser();
$pdo = getDbConnection();

try {
    $pdo->beginTransaction();

    // Kiểm tra thông tin chỉ thị trước khi xóa
    $stmtCheck = $pdo->prepare("SELECT `id`, `slip_code`, `order_code`, `product_code`, `printed_qty` FROM `production_orders` WHERE `id` = ? FOR UPDATE");
    $stmtCheck->execute([$orderId]);
    $order = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Không tìm thấy dữ liệu chỉ thị cần xóa hoặc chỉ thị đã bị xóa trước đó!'
        ]);
        exit;
    }

    // 1. Xóa các tem thùng chi tiết trong box_labels thuộc chỉ thị này
    $stmtDelLabels = $pdo->prepare("
        DELETE FROM `box_labels` 
        WHERE `history_id` IN (SELECT `id` FROM `print_history` WHERE `order_id` = ?)
    ");
    $stmtDelLabels->execute([$orderId]);

    // 2. Xóa lịch sử in trong print_history
    $stmtDelHist = $pdo->prepare("DELETE FROM `print_history` WHERE `order_id` = ?");
    $stmtDelHist->execute([$orderId]);

    // 3. Xóa chỉ thị trong production_orders
    $stmtDelOrder = $pdo->prepare("DELETE FROM `production_orders` WHERE `id` = ?");
    $stmtDelOrder->execute([$orderId]);

    // Commit Transaction an toàn
    $pdo->commit();

    // Ghi log kiểm toán thao tác xóa
    $logMsg = date('[Y-m-d H:i:s]') . " [DELETE_ORDER] User: {$currentUser['username']} ({$currentUser['employee_code']}, Role: {$currentUser['role']}) đã xóa chỉ thị ID: {$orderId}, Phiếu: {$order['slip_code']}, Lệnh: {$order['order_code']}, SP: {$order['product_code']}, Đã in: {$order['printed_qty']}\n";
    $logFile = __DIR__ . '/../logs/print_audit.log';
    if (file_exists(dirname($logFile))) {
        @file_put_contents($logFile, $logMsg, FILE_APPEND);
    }

    echo json_encode([
        'success' => true,
        'message' => "Đã xóa thành công chỉ thị sản xuất [{$order['slip_code']}] ({$order['order_code']})!"
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("[DELETE_ORDER_ERROR] " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Lỗi cơ sở dữ liệu khi xóa chỉ thị: ' . $e->getMessage()
    ]);
}

