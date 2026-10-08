<?php
/**
 * AJAX: TÌM KIẾM CHỈ THỊ SẢN XUẤT THEO MÃ PHIẾU HOẶC MÃ CHỈ THỊ
 * Khắc phục triệt để lỗi SQLSTATE[HY093]: Invalid parameter number
 * Bằng cách ánh xạ 1-1 chính xác giữa named parameter và mảng execute()
 */
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Phiên làm việc đã hết hạn. Vui lòng đăng nhập lại!']);
    exit;
}

$query = trim($_GET['q'] ?? $_POST['q'] ?? '');

if (empty($query)) {
    echo json_encode(['success' => false, 'message' => 'Vui lòng nhập Mã phiếu chỉ thị hoặc Mã chỉ thị!']);
    exit;
}

try {
    $pdo = getDbConnection();
    
    // 1. Tìm kiếm chính xác mã phiếu hoặc mã chỉ thị (Sử dụng 2 tham số riêng biệt :slip_exact và :order_exact)
    $stmt = $pdo->prepare("SELECT * FROM `production_orders` 
                           WHERE `slip_code` = :slip_exact OR `order_code` = :order_exact 
                           LIMIT 1");
    $stmt->execute([
        ':slip_exact'  => $query,
        ':order_exact' => $query
    ]);
    $order = $stmt->fetch();

    // 2. Tìm kiếm gần đúng nếu chưa tìm thấy chính xác (2 tham số riêng biệt :slip_like và :order_like)
    if (!$order) {
        $likePattern = "%{$query}%";
        $stmtLike = $pdo->prepare("SELECT * FROM `production_orders` 
                                   WHERE `slip_code` LIKE :slip_like OR `order_code` LIKE :order_like 
                                   LIMIT 1");
        $stmtLike->execute([
            ':slip_like'  => $likePattern,
            ':order_like' => $likePattern
        ]);
        $order = $stmtLike->fetch();
    }

    if ($order) {
        $isPaused = ($order['status'] === 'paused');
        
        echo json_encode([
            'success' => true,
            'order' => [
                'id'             => (int)$order['id'],
                'slip_code'      => $order['slip_code'],
                'order_code'     => $order['order_code'],
                'product_code'   => $order['product_code'],
                'target_qty'     => (int)$order['target_qty'],
                'printed_qty'    => (int)$order['printed_qty'],
                'remaining_qty'  => (int)$order['remaining_qty'],
                'pack_qty'       => (int)$order['pack_qty'],
                'supplier'       => $order['supplier'] ?? '',
                'weight_per_box' => (float)$order['weight_per_box'],
                'status'         => $order['status'],
                'is_paused'      => $isPaused,
                'issue_month'    => $order['issue_month'] ?? '',
                'note'           => $order['note'] ?? ''
            ]
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => "Không tìm thấy chỉ thị tương ứng với mã: " . htmlspecialchars($query)
        ]);
    }

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Lỗi truy vấn CSDL: ' . $e->getMessage()
    ]);
}
