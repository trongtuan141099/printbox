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

$pdo = getDbConnection();
$action = trim($_POST['action'] ?? 'toggle_pause');

// XỬ LÝ TẠM DỪNG HÀNG LOẠT (BATCH PAUSE)
if ($action === 'batch_pause') {
    $applyFiltered = !empty($_POST['apply_filtered']) && $_POST['apply_filtered'] == '1';
    $orderIdsRaw = $_POST['order_ids'] ?? [];
    if (is_string($orderIdsRaw)) {
        $decodedIds = json_decode($orderIdsRaw, true);
        if (is_array($decodedIds)) {
            $orderIdsRaw = $decodedIds;
        } else {
            $orderIdsRaw = array_filter(explode(',', $orderIdsRaw));
        }
    }
    $orderIds = array_values(array_unique(array_filter(array_map('intval', (array)$orderIdsRaw), fn($id) => $id > 0)));

    try {
        $pdo->beginTransaction();

        if ($applyFiltered) {
            $searchKeyword = trim($_POST['filter_k'] ?? '');
            $filterStatus  = trim($_POST['filter_status'] ?? '');
            $filterMonth   = trim($_POST['filter_month'] ?? '');

            $whereSql = " WHERE `status` != 'paused'";
            $params   = [];

            if (!empty($searchKeyword)) {
                $whereSql .= " AND (`slip_code` LIKE ? OR `order_code` LIKE ? OR `product_code` LIKE ? OR `supplier` LIKE ?)";
                $kParam = "%{$searchKeyword}%";
                $params[] = $kParam;
                $params[] = $kParam;
                $params[] = $kParam;
                $params[] = $kParam;
            }
            if (!empty($filterStatus)) {
                $whereSql .= " AND `status` = ?";
                $params[] = $filterStatus;
            }
            if (!empty($filterMonth)) {
                $whereSql .= " AND `issue_month` LIKE ?";
                $params[] = "%{$filterMonth}%";
            }

            $stmtBatch = $pdo->prepare("UPDATE `production_orders` SET `status` = 'paused', `updated_at` = NOW()" . $whereSql);
            $stmtBatch->execute($params);
            $affected = $stmtBatch->rowCount();

            $pdo->commit();
            echo json_encode([
                'success'      => true,
                'paused_count' => $affected,
                'message'      => "Đã TẠM DỪNG in thành công {$affected} chỉ thị sản xuất theo bộ lọc!"
            ]);
            exit;
        }

        if (empty($orderIds)) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Vui lòng tích chọn ít nhất một chỉ thị để tạm dừng!']);
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $stmtBatch = $pdo->prepare("UPDATE `production_orders` SET `status` = 'paused', `updated_at` = NOW() WHERE `id` IN ({$placeholders})");
        $stmtBatch->execute($orderIds);
        $affected = count($orderIds);

        $pdo->commit();
        echo json_encode([
            'success'      => true,
            'paused_count' => $affected,
            'order_ids'    => $orderIds,
            'message'      => "Đã TẠM DỪNG in thành công {$affected} chỉ thị sản xuất đã chọn!"
        ]);
        exit;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'Lỗi CSDL khi tạm dừng hàng loạt: ' . $e->getMessage()]);
        exit;
    }
}

$orderId = (int)($_POST['order_id'] ?? 0);

if ($orderId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Mã chỉ thị không hợp lệ!']);
    exit;
}

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


