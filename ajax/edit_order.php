<?php
/**
 * AJAX: CHỈNH SỬA THÔNG TIN CHỈ THỊ SẢN XUẤT (EDIT ORDER)
 * Phân quyền: Editor & Admin
 * Cập nhật định mức, tự động tính lại số lượng còn lại & trạng thái
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
    echo json_encode(['success' => false, 'message' => 'Bạn không có quyền chỉnh sửa chỉ thị sản xuất!']);
    exit;
}

$orderId     = (int)($_POST['order_id'] ?? 0);
$orderCode   = trim($_POST['order_code'] ?? '');
$productCode = trim($_POST['product_code'] ?? '');
$issueMonth  = trim($_POST['issue_month'] ?? '');
$targetQty   = (int)($_POST['target_qty'] ?? 0);
$packQty     = (int)($_POST['pack_qty'] ?? 1);
$supplier    = trim($_POST['supplier'] ?? '');
$weight      = (float)($_POST['weight_per_box'] ?? 0);
$note        = trim($_POST['note'] ?? '');

if ($orderId <= 0 || empty($orderCode) || empty($productCode) || $targetQty <= 0 || $packQty <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Dữ liệu chỉnh sửa không hợp lệ hoặc thiếu thông tin bắt buộc (*): Mã chỉ thị, Mã SP, Định mức > 0, Quy cách > 0!'
    ]);
    exit;
}

$pdo = getDbConnection();

try {
    $stmtCur = $pdo->prepare("SELECT * FROM `production_orders` WHERE `id` = ?");
    $stmtCur->execute([$orderId]);
    $cur = $stmtCur->fetch();

    if (!$cur) {
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy dữ liệu chỉ thị cần chỉnh sửa!']);
        exit;
    }

    $printedQty   = (int)$cur['printed_qty'];
    $newRemaining = $targetQty - $printedQty;

    // Giữ nguyên trạng thái paused nếu đang bị tạm dừng, ngược lại tự động tính trạng thái mới
    $newStatus = $cur['status'];
    if ($newStatus !== 'paused') {
        if ($newRemaining <= 0) {
            $newStatus = 'completed';
        } else {
            $newStatus = ($printedQty > 0) ? 'in_progress' : 'pending';
        }
    }

    $stmtUpd = $pdo->prepare("UPDATE `production_orders` SET 
        `order_code`     = ?,
        `product_code`   = ?,
        `issue_month`    = ?,
        `target_qty`     = ?,
        `remaining_qty`  = ?,
        `pack_qty`       = ?,
        `supplier`       = ?,
        `weight_per_box` = ?,
        `note`           = ?,
        `status`         = ?,
        `updated_at`     = NOW()
        WHERE `id` = ?");

    $stmtUpd->execute([
        $orderCode,
        $productCode,
        $issueMonth,
        $targetQty,
        $newRemaining,
        $packQty,
        $supplier,
        $weight,
        $note,
        $newStatus,
        $orderId
    ]);

    echo json_encode([
        'success' => true,
        'message' => "Đã cập nhật thành công chỉ thị {$cur['slip_code']} ({$orderCode})!",
        'order'   => [
            'id'             => $orderId,
            'slip_code'      => $cur['slip_code'],
            'order_code'     => $orderCode,
            'product_code'   => $productCode,
            'issue_month'    => $issueMonth,
            'target_qty'     => $targetQty,
            'printed_qty'    => $printedQty,
            'remaining_qty'  => $newRemaining,
            'pack_qty'       => $packQty,
            'supplier'       => $supplier,
            'weight_per_box' => $weight,
            'status'         => $newStatus,
            'note'           => $note
        ]
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Lỗi cập nhật CSDL: ' . $e->getMessage()]);
}

