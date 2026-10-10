<?php
/**
 * AJAX: THÊM MỚI CHỈ THỊ SẢN XUẤT (PRODUCTION ORDERS)
 * Phân quyền: Editor & Admin
 * Tự động mapping Quy cách (Specs) & Nhà cung cấp từ CSDL
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
    echo json_encode(['success' => false, 'message' => 'Bạn không có quyền thêm mới chỉ thị sản xuất!']);
    exit;
}

$currentUser = getCurrentUser();

$slipCode    = trim($_POST['slip_code'] ?? '');
$orderCode   = trim($_POST['order_code'] ?? '');
$productCode = trim($_POST['product_code'] ?? '');
$boxType     = trim($_POST['box_type'] ?? '1');
if (empty($boxType)) $boxType = '1';
$issueMonth  = trim($_POST['issue_month'] ?? date('m/Y'));
$targetQty   = (int)($_POST['target_qty'] ?? 0);
$packQty     = (int)($_POST['pack_qty'] ?? 0);
$supplier    = trim($_POST['supplier'] ?? '');
$weight      = (float)($_POST['weight_per_box'] ?? 0);
$note        = trim($_POST['note'] ?? '');

if (empty($slipCode) || empty($orderCode) || empty($productCode) || $targetQty <= 0) {
    echo json_encode([
        'success' => false, 
        'message' => 'Vui lòng điền đầy đủ các thông tin bắt buộc (*): Mã phiếu, Mã chỉ thị, Mã SP và Số lượng chỉ thị > 0!'
    ]);
    exit;
}

$pdo = getDbConnection();

// Kiểm tra bắt buộc: Sản phẩm phải có Quy Cách đã được khai báo trước trong hệ thống
$spec = getProductSpecStrict($productCode, $boxType, $pdo);
if (!$spec) {
    echo json_encode([
        'success' => false,
        'message' => "Chưa thiết lập quy cách cho sản phẩm [{$productCode}] (Thùng loại {$boxType}). Vui lòng tạo quy cách trước khi tạo chỉ thị sản xuất!"
    ]);
    exit;
}

// Tự động mapping quy cách từ bảng product_specs nếu chưa nhập hoặc nhập 0
if ($packQty <= 0) {
    $packQty = (int)$spec['pack_qty'];
}
if (empty($supplier)) {
    $supplier = $spec['supplier'];
}
if ($weight <= 0) {
    $weight = (float)$spec['weight_per_box'];
}
if ($packQty <= 0) {
    $packQty = 1;
}

try {
    // Kiểm tra trùng mã phiếu
    $stmtCheck = $pdo->prepare("SELECT id FROM `production_orders` WHERE `slip_code` = ? LIMIT 1");
    $stmtCheck->execute([$slipCode]);
    if ($stmtCheck->fetch()) {
        echo json_encode([
            'success' => false,
            'message' => "Mã phiếu chỉ thị '{$slipCode}' đã tồn tại trong hệ thống! Vui lòng sử dụng mã phiếu khác."
        ]);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO `production_orders` 
        (`slip_code`, `order_code`, `product_code`, `box_type`, `issue_month`, `target_qty`, `printed_qty`, `remaining_qty`, `pack_qty`, `supplier`, `weight_per_box`, `status`, `note`, `created_by`, `created_at`) 
        VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 'pending', ?, ?, NOW())");
    
    $stmt->execute([
        $slipCode,
        $orderCode,
        $productCode,
        $boxType,
        $issueMonth,
        $targetQty,
        $targetQty, // remaining = target lúc tạo mới
        $packQty,
        $supplier,
        $weight,
        $note,
        $currentUser['id']
    ]);

    $newId = (int)$pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => "Đã tạo thành công chỉ thị: {$slipCode} ({$orderCode}) - Tháng: {$issueMonth}!",
        'order' => [
            'id'             => $newId,
            'slip_code'      => $slipCode,
            'order_code'     => $orderCode,
            'product_code'   => $productCode,
            'issue_month'    => $issueMonth,
            'target_qty'     => $targetQty,
            'printed_qty'    => 0,
            'remaining_qty'  => $targetQty,
            'pack_qty'       => $packQty,
            'supplier'       => $supplier,
            'weight_per_box' => $weight,
            'status'         => 'pending',
            'note'           => $note
        ]
    ]);

} catch (PDOException $e) {
    if ($e->getCode() == 23000) {
        echo json_encode(['success' => false, 'message' => "Mã phiếu chỉ thị '{$slipCode}' đã tồn tại!"]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Lỗi lưu CSDL: ' . $e->getMessage()]);
    }
}

