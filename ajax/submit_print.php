<?php
/**
 * AJAX: XỬ LÝ NHẬP LIỆU IN TEM & TỰ ĐỘNG SINH TEM THÙNG
 * Transaction an toàn, kiểm tra vượt mức, tự động cấp chuỗi Box No TU-YYMMDD-XXX
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

if (!canPrint()) {
    echo json_encode(['success' => false, 'message' => 'Bạn không có quyền nhập liệu và in tem!']);
    exit;
}

$currentUser = getCurrentUser();

$orderId    = (int)($_POST['order_id'] ?? 0);
$printQty   = (int)($_POST['print_qty'] ?? 0);
$isOddBox   = (int)($_POST['is_odd_box'] ?? 0);
$adminMsnv  = trim($_POST['admin_msnv'] ?? '');
$adminPass  = trim($_POST['admin_pass'] ?? $_POST['admin_password'] ?? '');
$note       = trim($_POST['note'] ?? '');
$printerDestination = trim($_POST['printer_name'] ?? $_POST['printer_destination'] ?? 'ZDesigner / Zebra (65x30mm)');
if (empty($printerDestination)) {
    $printerDestination = 'ZDesigner / Zebra (65x30mm)';
}

if ($orderId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Chưa chọn chỉ thị sản xuất hợp lệ!']);
    exit;
}

if ($printQty <= 0) {
    echo json_encode(['success' => false, 'message' => 'Số lượng tem sản phẩm in phải lớn hơn 0!']);
    exit;
}

$pdo = getDbConnection();

try {
    $pdo->beginTransaction();

    // Khóa chỉ thị để tính toán chính xác
    $stmt = $pdo->prepare("SELECT * FROM `production_orders` WHERE `id` = ? FOR UPDATE");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();

    if (!$order) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy dữ liệu chỉ thị!']);
        exit;
    }

    // KIỂM TRA TRẠNG THÁI: Tạm dừng hoặc hủy bỏ
    if ($order['status'] === 'paused') {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'is_paused' => true,
            'message' => "LỖI CHẶN: Chỉ thị '{$order['slip_code']}' đang bị TẠM DỪNG in tem! Vui lòng liên hệ Quản lý / Editor để kích hoạt lại."
        ]);
        exit;
    }

    if ($order['status'] === 'cancelled') {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => "LỖI CHẶN: Chỉ thị '{$order['slip_code']}' đã bị HỦY BỎ!"
        ]);
        exit;
    }

    // Tra cứu quy cách đóng gói động dựa theo Loại Thùng (box_type)
    $boxType = trim($_POST['box_type'] ?? '');
    if (empty($boxType)) {
        $boxType = !empty($order['box_type']) ? $order['box_type'] : '1';
    }

    $spec = getProductSpec($order['product_code'], $boxType, $pdo);
    $packQty = (int)$spec['pack_qty'];
    if ($packQty <= 0) $packQty = (int)$order['pack_qty'];
    if ($packQty <= 0) $packQty = 1;

    $weightPerBox = (float)$spec['weight_per_box'];
    if ($weightPerBox <= 0) $weightPerBox = (float)$order['weight_per_box'];

    $orderSupplier = !empty($spec['supplier']) ? $spec['supplier'] : ($order['supplier'] ?? '');

    $remainingQty = (int)$order['remaining_qty'];
    $isOverTarget = 0;
    $approvedByMsnv = null;
    $approvedById   = null;

    // KIỂM TRA ĐỊNH MỨC: Bắt buộc xác thực MSNV VÀ Mật khẩu của Admin/Editor nếu in vượt số còn lại
    // Ngay cả khi người dùng đăng nhập là Admin hoặc Editor, vẫn bắt buộc cung cấp MSNV + Mật khẩu để ủy quyền!
    if ($printQty > $remainingQty) {
        if (empty($adminMsnv) || empty($adminPass)) {
            $pdo->rollBack();
            echo json_encode([
                'success' => false,
                'require_admin' => true,
                'remaining_qty' => $remainingQty,
                'print_qty' => $printQty,
                'excess_qty' => ($printQty - $remainingQty),
                'message' => "CẢNH BÁO VƯỢT ĐỊNH MỨC: Số lượng in ({$printQty}) vượt quá số lượng còn lại ({$remainingQty})! Bắt buộc phải có Quản lý / Admin / Editor xác nhận bằng MSNV và Mật khẩu."
            ]);
            exit;
        }

        // Xác thực MSNV & Mật khẩu Admin/Editor trong CSDL chi tiết
        $authResult = verifyManagerOverrideDetailed($adminMsnv, $adminPass, $pdo);
        if (!$authResult['success']) {
            $pdo->rollBack();
            echo json_encode([
                'success' => false,
                'require_admin' => true,
                'remaining_qty' => $remainingQty,
                'print_qty' => $printQty,
                'excess_qty' => ($printQty - $remainingQty),
                'message' => "Xác thực duyệt vượt mức thất bại: " . $authResult['message']
            ]);
            exit;
        }

        $managerInfo    = $authResult['user'];
        $isOverTarget   = 1;
        $approvedByMsnv = $managerInfo['employee_code'];
        $approvedById   = (int)$managerInfo['id'];
    }

    // TÍNH TOÁN SỐ THÙNG VÀ KIỂM TRA THÙNG LẺ
    $packaging = calculateBoxPackaging($printQty, $packQty);
    $totalBoxes = $packaging['total_boxes'];
    $fullBoxes  = $packaging['full_boxes'];
    $hasOdd     = $packaging['has_odd'];
    $oddQty     = $packaging['odd_qty'];

    if ($totalBoxes <= 0) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Số lượng thùng tính toán không hợp lệ!']);
        exit;
    }

    // KIỂM TRA THÙNG LẺ: Nếu có phần dư (hasOdd) mà chưa tích chọn in lẻ (isOddBox !== 1) -> CHẶN
    if ($hasOdd && $isOddBox !== 1) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'require_odd_confirm' => true,
            'odd_qty' => $oddQty,
            'message' => "CẢNH BÁO THÙNG LẺ: Số lượng in ({$printQty}) dư {$oddQty} con lẻ (Quy cách {$packQty} con/thùng theo Thùng loại {$boxType}). Bạn phải tích chọn 'Cho phép in thùng lẻ' để xác nhận trước khi in!"
        ]);
        exit;
    }

    // CẤP PHÁT DANH SÁCH MÃ THÙNG: TU-YYMMDD-XXX
    $boxNumbers = generateDailyBoxNumbers($totalBoxes, $pdo);

    // CẬP NHẬT CHỈ THỊ SẢN XUẤT: Cộng dồn số đã in, trừ số còn lại
    $newPrintedQty = (int)$order['printed_qty'] + $printQty;
    $newRemainingQty = (int)$order['target_qty'] - $newPrintedQty;
    $newStatus = ($newRemainingQty <= 0) ? 'completed' : 'in_progress';

    $stmtUpd = $pdo->prepare("UPDATE `production_orders` 
                             SET `printed_qty` = ?, 
                                 `remaining_qty` = ?, 
                                 `status` = ?, 
                                 `box_type` = ?,
                                 `updated_at` = NOW() 
                             WHERE `id` = ?");
    $stmtUpd->execute([$newPrintedQty, $newRemainingQty, $newStatus, $boxType, $orderId]);

    // LƯU LỊCH SỬ IN (PRINT_HISTORY)
    $auditNote = !empty($note) ? ($note . " | [Thùng loại {$boxType}] [Trạng thái: Đã gửi lệnh in (Printed)]") : "[Thùng loại {$boxType}] [Trạng thái: Đã gửi lệnh in (Printed)]";

    $stmtHist = $pdo->prepare("INSERT INTO `print_history` 
        (`order_id`, `slip_code`, `order_code`, `product_code`, `box_type`, `print_qty`, `pack_qty`, 
         `box_count`, `is_odd_box`, `odd_qty`, `box_numbers`, `qr_data_sample`, 
         `is_over_target`, `approved_by_id`, `approved_by_msnv`, `operator_id`, `operator_name`, 
         `operator_msnv`, `printer_destination`, `note`, `created_at`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

    $stmtHist->execute([
        $orderId,
        $order['slip_code'],
        $order['order_code'],
        $order['product_code'],
        $boxType,
        $printQty,
        $packQty,
        $totalBoxes,
        ($hasOdd ? 1 : 0),
        $oddQty,
        json_encode($boxNumbers, JSON_UNESCAPED_UNICODE),
        '', // Sẽ cập nhật mẫu sau
        $isOverTarget,
        $approvedById,
        $approvedByMsnv,
        $currentUser['id'],
        $currentUser['full_name'],
        $currentUser['employee_code'],
        $printerDestination,
        $auditNote
    ]);

    $historyId = (int)$pdo->lastInsertId();

    // SINH CHI TIẾT TỪNG TEM THÙNG VÀ MÃ QR
    $labels = [];
    $stmtLabel = $pdo->prepare("INSERT INTO `box_labels` 
        (`history_id`, `box_no`, `order_code`, `product_code`, `box_type`, `qty`, `weight`, `input_date`, `qr_content`, `created_at`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

    $todayFormatted = date('d/m/Y');
    $todayDb = date('Y-m-d');
    $sampleQrContent = '';

    for ($i = 0; $i < $totalBoxes; $i++) {
        $boxNo = $boxNumbers[$i];
        
        // Nếu là thùng cuối cùng và có lẻ, gán số lượng lẻ
        $isLastAndOdd = ($hasOdd && $i === ($totalBoxes - 1));
        $currentBoxQty = $isLastAndOdd ? $oddQty : $packQty;

        // Trọng lượng tỉ lệ theo số lượng nếu có
        $weightStr = '';
        if ($weightPerBox > 0) {
            $calcWeight = round(($weightPerBox / $packQty) * $currentBoxQty, 2);
            $weightStr = number_format($calcWeight, 2, '.', '');
        }

        // Tạo nội dung QR Code theo chuẩn nhà máy
        $qrData = [
            'material_name' => $order['product_code'],
            'supplier'      => $orderSupplier,
            'bundle_no'     => $order['bundle_no'] ?? '',
            'lot_no'        => $order['order_code'],
            'input_date'    => $todayFormatted,
            'qty'           => $currentBoxQty,
            'weight'        => $weightStr,
            'invoice_no'    => $order['invoice_no'] ?? '',
            'order_no'      => $order['order_no'] ?? '',
            'box_no'        => $boxNo,
            'issue_month'   => $order['issue_month'] ?? '',
            'slip_code'     => $order['slip_code'] ?? '',
            'box_type'      => $boxType
        ];

        $qrContent = buildBoxQrContent($qrData, $pdo);
        if (empty($sampleQrContent)) {
            $sampleQrContent = $qrContent;
        }

        // Sinh ảnh QR offline dạng Base64 (độ phân giải cao, margin 4 chuẩn ISO cho camera quét nhanh)
        $qrBase64 = generateQrDataUri($qrContent, 6, 4);

        // Lưu vào CSDL
        $stmtLabel->execute([
            $historyId,
            $boxNo,
            $order['order_code'],
            $order['product_code'],
            $boxType,
            $currentBoxQty,
            $weightStr,
            $todayDb,
            $qrContent
        ]);

        $labels[] = [
            'box_no'        => $boxNo,
            'box_type'      => $boxType,
            'material_name' => $order['product_code'],
            'lot_no'        => $order['order_code'],
            'qty'           => $currentBoxQty,
            'weight'        => $weightStr,
            'supplier'      => $orderSupplier,
            'input_date'    => $todayFormatted,
            'is_odd'        => $isLastAndOdd,
            'qr_base64'     => $qrBase64,
            'qr_content'    => $qrContent
        ];
    }

    // Cập nhật mẫu QR vào lịch sử
    $pdo->prepare("UPDATE `print_history` SET `qr_data_sample` = ? WHERE `id` = ?")->execute([$sampleQrContent, $historyId]);

    // Commit Transaction
    $pdo->commit();

    // Ghi log kiểm toán in tem chi tiết ra file logs/print_audit.log
    try {
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        $logFile = $logDir . '/print_audit.log';
        $firstBox = $boxNumbers[0] ?? 'N/A';
        $lastBox  = $boxNumbers[$totalBoxes - 1] ?? 'N/A';
        $boxSummaryStr = ($totalBoxes > 1) ? "{$firstBox} -> {$lastBox}" : $firstBox;
        $logLine = sprintf(
            "[%s] PRINT_DISPATCHED | User: %s (%s) | Slip: %s | Lot: %s | Product: %s | Qty: %d pcs | Boxes: %d [%s] | Printer: %s | Status: SUCCESS\n",
            date('Y-m-d H:i:s'),
            $currentUser['full_name'],
            $currentUser['employee_code'],
            $order['slip_code'],
            $order['order_code'],
            $order['product_code'],
            $printQty,
            $totalBoxes,
            $boxSummaryStr,
            $printerDestination
        );
        @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
    } catch (Exception $logEx) {
        // Fail-safe: do not fail print operation if log write encounters disk error
        error_log('Print audit log error: ' . $logEx->getMessage());
    }

    echo json_encode([
        'success'             => true,
        'message'             => 'Đã nhập liệu thành công và sinh ' . $totalBoxes . ' tem thùng!' . ($isOverTarget ? " (Đã duyệt vượt mức bởi {$approvedByMsnv})" : ''),
        'history_id'          => $historyId,
        'box_count'           => $totalBoxes,
        'new_printed'         => $newPrintedQty,
        'new_remaining'       => $newRemainingQty,
        'is_over_target'      => $isOverTarget,
        'approved_by'         => $approvedByMsnv,
        'approved_by_id'      => $approvedById,
        'printer_destination' => $printerDestination,
        'print_status'        => 'dispatched',
        'labels'              => $labels
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'message' => 'Lỗi xử lý hệ thống: ' . $e->getMessage()
    ]);
}

