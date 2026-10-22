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

$orderId        = (int)($_POST['order_id'] ?? 0);
$printQty       = (int)($_POST['print_qty'] ?? 0);
$isOddBox       = (int)($_POST['is_odd_box'] ?? 0);
$adminMsnv      = trim($_POST['admin_msnv'] ?? '');
$adminPass      = trim($_POST['admin_pass'] ?? $_POST['admin_password'] ?? '');
$overrideReason = trim($_POST['override_reason'] ?? '');
$note           = trim($_POST['note'] ?? '');

if ($orderId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Chưa chọn chỉ thị sản xuất hợp lệ!']);
    exit;
}

if ($printQty <= 0) {
    echo json_encode(['success' => false, 'message' => 'Số lượng tem sản phẩm in phải lớn hơn 0!']);
    exit;
}

$pdo = getDbConnection();
ensurePrintHistorySchema($pdo);

$printerDestination = trim($_POST['printer_name'] ?? $_POST['printer_destination'] ?? '');
if (empty($printerDestination)) {
    $printerDestination = getDefaultPrinter($pdo);
} elseif (strcasecmp($printerDestination, 'Browser Print') !== 0) {
    saveDefaultPrinter($printerDestination, $pdo);
}

if (empty($printerDestination)) {
    $printerDestination = 'Browser Print';
}

$labelSize = strtolower(trim($_POST['label_size'] ?? ''));
if (in_array($labelSize, ['80x110', '100x80', '80x100'], true)) {
    $labelSize = '110x80';
}
if (empty($labelSize)) {
    $labelSize = getDefaultLabelSize($pdo);
}
// Auto-match label size if printer name explicitly indicates 80x110 / 110x80 / 80x100 / 100x80
if (preg_match('/(80\s*x\s*110|110\s*x\s*80|80\s*x\s*100|100\s*x\s*80)/i', $printerDestination)) {
    $labelSize = '110x80';
} elseif (preg_match('/65\s*x\s*30/i', $printerDestination)) {
    $labelSize = '65x30';
}
if (!in_array($labelSize, ['65x30', '110x80'], true)) {
    echo json_encode(['success' => false, 'message' => 'Lỗi cấu hình in: Kích thước tem không hợp lệ (hỗ trợ 65x30mm hoặc 80x100mm / 100x80mm / 110x80mm)!']);
    exit;
}
saveDefaultLabelSize($labelSize, $pdo);

$printMode = strtolower(trim($_POST['print_mode'] ?? 'direct'));
if (!in_array($printMode, ['direct', 'preview'], true)) {
    $printMode = 'direct';
}

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

    if (empty(trim((string)($order['order_code'] ?? '')))) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Dữ liệu không hợp lệ: Chỉ thị thiếu Mã CTSX (Lot No)!']);
        exit;
    }

    if (empty(trim((string)($order['product_code'] ?? '')))) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Dữ liệu không hợp lệ: Chỉ thị thiếu Mã sản phẩm!']);
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
    $savedOverrideReason = null;

    // KIỂM TRA ĐỊNH MỨC: Bắt buộc xác thực MSNV, Mật khẩu VÀ Lý do in vượt của Admin/Editor nếu in vượt số còn lại
    if ($printQty > $remainingQty) {
        if (empty($adminMsnv) || empty($adminPass) || empty($overrideReason)) {
            $pdo->rollBack();
            echo json_encode([
                'success' => false,
                'require_admin' => true,
                'remaining_qty' => $remainingQty,
                'print_qty' => $printQty,
                'excess_qty' => ($printQty - $remainingQty),
                'message' => "CẢNH BÁO VƯỢT ĐỊNH MỨC: Số lượng in ({$printQty}) vượt quá số lượng còn lại ({$remainingQty})! Bắt buộc phải nhập MSNV, Mật khẩu người duyệt và Lý do in vượt."
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
        $excessQty      = $printQty - $remainingQty;
        $savedOverrideReason = "Lý do: {$overrideReason} | Nội dung: In {$printQty} con / Còn lại {$remainingQty} con (Vượt +{$excessQty} con) | Người duyệt: {$managerInfo['full_name']} ({$approvedByMsnv})";
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
    if ($isOverTarget && !empty($overrideReason)) {
        $auditNote = "[Vượt mức - Lý do: {$overrideReason}] " . $auditNote;
    }
    $auditNote = mb_substr($auditNote, 0, 250, 'UTF-8');

    $stmtHist = $pdo->prepare("INSERT INTO `print_history` 
        (`order_id`, `slip_code`, `order_code`, `product_code`, `box_type`, `print_qty`, `pack_qty`, 
         `box_count`, `is_odd_box`, `odd_qty`, `box_numbers`, `qr_data_sample`, 
         `is_over_target`, `approved_by_id`, `approved_by_msnv`, `override_reason`, `operator_id`, `operator_name`, 
         `operator_msnv`, `printer_destination`, `note`, `created_at`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

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
        $savedOverrideReason,
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

        // Sinh ảnh QR offline dạng Base64 (độ phân giải cao pixelSize=8, margin=1 giúp phóng lớn QR tối đa và quét ổn định)
        $qrBase64 = generateQrDataUri($qrContent, 8, 1);

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

    // Kiểm tra toàn diện dữ liệu trước khi hoàn tất và gửi lệnh in (Mã CTSX, Mã SP, QR Code, Số lượng tem, Máy in, Kích thước tem)
    $validation = validatePrintJobData([
        'order_code'   => $order['order_code'],
        'product_code' => $order['product_code'],
        'printer_name' => $printerDestination,
        'label_size'   => $labelSize,
        'label_count'  => $totalBoxes,
        'labels'       => $labels
    ]);

    if (!$validation['valid']) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => $validation['message']
        ]);
        exit;
    }

    // Cập nhật mẫu QR vào lịch sử
    $pdo->prepare("UPDATE `print_history` SET `qr_data_sample` = ? WHERE `id` = ?")->execute([$sampleQrContent, $historyId]);

    // Commit Transaction
    $pdo->commit();

    // Tự động gửi lệnh in trực tiếp tới máy in đã chọn nếu ở chế độ In trực tiếp (direct)
    $directPrintRes = ['success' => false, 'message' => '', 'pages_printed' => 0];
    if ($printMode !== 'preview') {
        // Only trigger Windows Spooler on local server machine; remote clients use Zebra Browser Print
        if (isClientLocalMachine() && strcasecmp($printerDestination, 'Browser Print') !== 0) {
            $directPrintRes = dispatchWindowsDirectPrint($labels, $printerDestination, $labelSize);
        }
    }

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
            "[%s] PRINT_DISPATCHED | User: %s (%s) | Slip: %s | Lot: %s | Product: %s | Qty: %d pcs | Boxes: %d [%s] | Size: %s Landscape | Printer: %s | DirectSpooler: %s | Status: SUCCESS\n",
            date('Y-m-d H:i:s'),
            $currentUser['full_name'],
            $currentUser['employee_code'],
            $order['slip_code'],
            $order['order_code'],
            $order['product_code'],
            $printQty,
            $totalBoxes,
            $boxSummaryStr,
            $labelSize,
            $printerDestination,
            !empty($directPrintRes['success']) ? 'YES' : 'NO'
        );
        @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
    } catch (Exception $logEx) {
        // Fail-safe: do not fail print operation if log write encounters disk error
        error_log('Print audit log error: ' . $logEx->getMessage());
    }

    echo json_encode([
        'success'             => true,
        'message'             => 'Đã nhập liệu thành công, sinh ' . $totalBoxes . ' tem thùng (' . $labelSize . 'mm Ngang) và tự động gửi lệnh in tới máy in ' . $printerDestination . '!' . ($isOverTarget ? " (Đã duyệt vượt mức bởi {$approvedByMsnv})" : ''),
        'history_id'          => $historyId,
        'box_count'           => $totalBoxes,
        'new_printed'         => $newPrintedQty,
        'new_remaining'       => $newRemainingQty,
        'is_over_target'      => $isOverTarget,
        'approved_by'         => $approvedByMsnv,
        'approved_by_id'      => $approvedById,
        'printer_destination' => $printerDestination,
        'label_size'          => $labelSize,
        'print_mode'          => $printMode,
        'direct_printed'      => !empty($directPrintRes['success']),
        'direct_print_msg'    => $directPrintRes['message'] ?? '',
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

