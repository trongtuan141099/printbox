<?php
/**
 * QUẢN LÝ CHỈ THỊ SẢN XUẤT (PRODUCTION ORDERS - CTSX)
 * Tính năng chính:
 * - Tra cứu, lọc theo tháng phát hành, trạng thái, mã chỉ thị
 * - Nhập liệu in tem trực tiếp qua Offcanvas UI mượt mà, phản hồi ngay trên hàng dữ liệu
 * - Tự động tính số tem thùng & cảnh báo thùng lẻ
 * - Kiểm soát định mức, xác thực Admin duyệt vượt mức bằng MSNV
 * - Tự động mapping Quy cách đóng gói (Specs) từ CSDL riêng biệt
 * - Quản lý trạng thái: Tạm dừng (Pause) / Kích hoạt lại (Resume)
 * - Import CSV chuẩn không có NCC & Quy cách (Tự động map specs)
 * - Xuất Excel danh sách chỉ thị với bộ lọc chi tiết
 */

// =======================================================
// 0. ENDPOINT NỘI BỘ DÀNH RIÊNG CHO CLIENT PC (127.0.0.1 / LOCALHOST)
// Cho phép trình duyệt trên máy trạm (Client PC) truy vấn danh sách máy in
// và gửi lệnh in trực tiếp qua dịch vụ in cục bộ trên chính máy tính người dùng.
// Tuyệt đối không trả về máy in của Server nếu gọi từ máy khác qua mạng.
// =======================================================
if (isset($_REQUEST['action']) && in_array($_REQUEST['action'], ['client_local_printers', 'client_direct_print'], true)) {
    require_once __DIR__ . '/includes/functions.php';
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    header('Access-Control-Allow-Private-Network: true');
    header('Content-Type: application/json; charset=utf-8');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    if (!isClientLocalMachine()) {
        echo json_encode([
            'success'  => false,
            'printers' => [],
            'message'  => 'Endpoint chỉ phục vụ truy vấn cục bộ trên máy tính người dùng (Client PC).'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_REQUEST['action'] === 'client_local_printers') {
        $printers = getConnectedPrinters(true);
        echo json_encode([
            'success'  => true,
            'source'   => 'client_os_spooler',
            'printers' => array_values($printers)
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_REQUEST['action'] === 'client_direct_print') {
        $printerName = trim($_POST['printer_name'] ?? '');
        $labelSize   = normalizeLabelSize($_POST['label_size'] ?? '65x30');
        $labelsJson  = $_POST['labels_json'] ?? '[]';
        $labels      = json_decode($labelsJson, true);

        if (empty($printerName) || !is_array($labels) || empty($labels)) {
            echo json_encode([
                'success' => false,
                'message' => 'Thiếu tên máy in hoặc dữ liệu tem in trên Client PC!'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $res = dispatchWindowsDirectPrint($labels, $printerName, $labelSize);
        echo json_encode([
            'success'        => $res['success'],
            'direct_printed' => $res['success'],
            'message'        => $res['message'],
            'printer'        => $res['printer'] ?? $printerName,
            'pages'          => $res['pages_printed'] ?? count($labels)
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$pageTitle = "Quản Lý Chỉ Thị Sản Xuất";
require_once __DIR__ . '/includes/header.php';

$pdo = getDbConnection();

// =======================================================
// 1. XỬ LÝ THÊM MỚI CHỈ THỊ THỦ CÔNG (Editor & Admin)
// =======================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_order') {
    if (!canManageDirectives()) {
        setFlash('danger', 'Bạn không có quyền thêm mới chỉ thị sản xuất!');
        header("Location: orders.php");
        exit;
    }

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
        setFlash('danger', 'Vui lòng nhập đầy đủ các thông tin bắt buộc (*)!');
        header("Location: orders.php");
        exit;
    }

    // Kiểm tra bắt buộc: Sản phẩm phải có Quy Cách đã được khai báo trước trong hệ thống
    $spec = getProductSpecStrict($productCode, $boxType, $pdo);
    if (!$spec) {
        setFlash('danger', "Chưa thiết lập quy cách cho sản phẩm [{$productCode}] (Thùng loại {$boxType}). Vui lòng tạo quy cách trước khi tạo chỉ thị sản xuất!");
        header("Location: orders.php");
        exit;
    }

    // Nếu người dùng không nhập quy cách hoặc NCC, tự động tra cứu từ product_specs
    if ($packQty <= 0) {
        $packQty = (int)$spec['pack_qty'];
    }
    if (empty($supplier)) {
        $supplier = $spec['supplier'];
    }
    if ($weight <= 0) {
        $weight = (float)$spec['weight_per_box'];
    }
    if ($packQty <= 0) $packQty = 1;

    try {
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
            setFlash('success', "Đã tạo thành công chỉ thị: {$slipCode} ({$orderCode}) - Tháng: {$issueMonth}!");
            header("Location: orders.php");
            exit;
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                setFlash('danger', "Mã phiếu chỉ thị '{$slipCode}' đã tồn tại trong hệ thống!");
            } else {
                setFlash('danger', "Lỗi lưu CSDL: " . $e->getMessage());
            }
        }
}

// =======================================================
// 2. XỬ LÝ CHỈNH SỬA CHỈ THỊ (Editor & Admin)
// =======================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_order') {
    if (!canManageDirectives()) {
        setFlash('danger', 'Bạn không có quyền chỉnh sửa chỉ thị sản xuất!');
        header("Location: orders.php");
        exit;
    }

    $orderId     = (int)($_POST['order_id'] ?? 0);
    $orderCode   = trim($_POST['order_code'] ?? '');
    $productCode = trim($_POST['product_code'] ?? '');
    $boxType     = trim($_POST['box_type'] ?? '1');
    if (empty($boxType)) $boxType = '1';
    $issueMonth  = trim($_POST['issue_month'] ?? '');
    $targetQty   = (int)($_POST['target_qty'] ?? 0);
    $packQty     = (int)($_POST['pack_qty'] ?? 1);
    $supplier    = trim($_POST['supplier'] ?? '');
    $weight      = (float)($_POST['weight_per_box'] ?? 0);
    $note        = trim($_POST['note'] ?? '');

    if ($orderId <= 0 || empty($orderCode) || empty($productCode) || $targetQty <= 0 || $packQty <= 0) {
        setFlash('danger', 'Dữ liệu chỉnh sửa không hợp lệ hoặc thiếu thông tin bắt buộc (*)!');
    } else {
        try {
            $stmtCur = $pdo->prepare("SELECT * FROM `production_orders` WHERE `id` = ?");
            $stmtCur->execute([$orderId]);
            $cur = $stmtCur->fetch();

            if (!$cur) {
                setFlash('danger', 'Không tìm thấy chỉ thị cần chỉnh sửa!');
            } else {
                $printedQty = (int)$cur['printed_qty'];
                $newRemaining = $targetQty - $printedQty;
                
                $newStatus = $cur['status'];
                if ($newStatus !== 'paused') {
                    if ($newRemaining <= 0) {
                        $newStatus = 'completed';
                    } else {
                        $newStatus = ($printedQty > 0) ? 'in_progress' : 'pending';
                    }
                }

                $stmtUpd = $pdo->prepare("UPDATE `production_orders` SET 
                    `order_code` = ?,
                    `product_code` = ?,
                    `box_type` = ?,
                    `issue_month` = ?,
                    `target_qty` = ?,
                    `remaining_qty` = ?,
                    `pack_qty` = ?,
                    `supplier` = ?,
                    `weight_per_box` = ?,
                    `note` = ?,
                    `status` = ?,
                    `updated_at` = NOW()
                    WHERE `id` = ?");

                $stmtUpd->execute([
                    $orderCode,
                    $productCode,
                    $boxType,
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

                setFlash('success', "Đã cập nhật thành công chỉ thị {$cur['slip_code']}!");
                header("Location: orders.php");
                exit;
            }
        } catch (PDOException $e) {
            setFlash('danger', "Lỗi cập nhật CSDL: " . $e->getMessage());
        }
    }
}

// =======================================================
// 3. XỬ LÝ TẠM DỪNG / TIẾP TỤC IN TEM (Editor & Admin)
// =======================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_pause') {
    if (!canManageDirectives()) {
        setFlash('danger', 'Bạn không có quyền thay đổi trạng thái in của chỉ thị!');
        header("Location: orders.php");
        exit;
    }

    $orderId = (int)($_POST['order_id'] ?? 0);

    try {
        $stmtCur = $pdo->prepare("SELECT * FROM `production_orders` WHERE `id` = ?");
        $stmtCur->execute([$orderId]);
        $order = $stmtCur->fetch();

        if ($order) {
            if ($order['status'] === 'paused') {
                $newStatus = ((int)$order['remaining_qty'] <= 0) ? 'completed' : (((int)$order['printed_qty'] > 0) ? 'in_progress' : 'pending');
                $stmtToggle = $pdo->prepare("UPDATE `production_orders` SET `status` = ?, `updated_at` = NOW() WHERE `id` = ?");
                $stmtToggle->execute([$newStatus, $orderId]);
                setFlash('success', "Đã TIẾP TỤC cho phép in tem chỉ thị {$order['slip_code']} ({$order['order_code']})!");
            } else {
                $stmtToggle = $pdo->prepare("UPDATE `production_orders` SET `status` = 'paused', `updated_at` = NOW() WHERE `id` = ?");
                $stmtToggle->execute([$orderId]);
                setFlash('warning', "Đã TẠM DỪNG in tem chỉ thị {$order['slip_code']} ({$order['order_code']})! Nhân viên sẽ không thể in tem cho đến khi được kích hoạt lại.");
            }
        } else {
            setFlash('danger', 'Không tìm thấy chỉ thị!');
        }
    } catch (PDOException $e) {
        setFlash('danger', 'Lỗi CSDL: ' . $e->getMessage());
    }

    header("Location: orders.php");
    exit;
}

// =======================================================
// 3.2. XỬ LÝ TẠM DỪNG IN HÀNG LOẠT (BATCH PAUSE - Editor & Admin)
// =======================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'batch_pause') {
    if (!canManageDirectives()) {
        setFlash('danger', 'Bạn không có quyền tạm dừng chỉ thị sản xuất!');
        header("Location: orders.php");
        exit;
    }

    $orderIds = $_POST['order_ids'] ?? [];
    if (is_string($orderIds)) {
        $orderIds = array_filter(array_map('intval', explode(',', $orderIds)));
    } elseif (is_array($orderIds)) {
        $orderIds = array_filter(array_map('intval', $orderIds));
    } else {
        $orderIds = [];
    }

    if (!empty($orderIds)) {
        try {
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $stmtBatch = $pdo->prepare("UPDATE `production_orders` SET `status` = 'paused', `updated_at` = NOW() WHERE `id` IN ({$placeholders}) AND `status` != 'paused'");
            $stmtBatch->execute(array_values($orderIds));
            $affected = $stmtBatch->rowCount();
            setFlash('warning', "Đã TẠM DỪNG in tem thành công cho {$affected} chỉ thị sản xuất đã chọn!");
        } catch (PDOException $e) {
            setFlash('danger', 'Lỗi CSDL khi tạm dừng hàng loạt: ' . $e->getMessage());
        }
    } else {
        setFlash('warning', 'Vui lòng chọn ít nhất 1 chỉ thị sản xuất để tạm dừng!');
    }

    header("Location: orders.php");
    exit;
}

// =======================================================
// 3.3. XỬ LÝ LƯU / LÀM MỚI MÁY IN TEM MẶC ĐỊNH & GỬI LỆNH IN TRỰC TIẾP (AJAX JSON)
// =======================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['save_default_printer', 'refresh_printers', 'save_default_label_size', 'direct_print_batch'], true)) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    if ($_POST['action'] === 'direct_print_batch') {
        $printerName = trim($_POST['printer_name'] ?? getDefaultPrinter($pdo));
        $labelSize   = normalizeLabelSize($_POST['label_size'] ?? getDefaultLabelSize($pdo));
        $labelsJson  = $_POST['labels_json'] ?? '[]';
        $labels      = json_decode($labelsJson, true);

        if (!is_array($labels) || empty($labels)) {
            echo json_encode([
                'success' => false,
                'message' => 'Không có dữ liệu tem hợp lệ để gửi lệnh in!'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $firstLabel = $labels[0];
        $validation = validatePrintJobData([
            'order_code'   => $firstLabel['lot_no'] ?? '',
            'product_code' => $firstLabel['material_name'] ?? '',
            'print_qty'    => $firstLabel['qty'] ?? 1,
            'label_count'  => count($labels),
            'printer_name' => $printerName,
            'label_size'   => $labelSize,
            'labels'       => $labels
        ]);
        if (!$validation['valid']) {
            echo json_encode([
                'success' => false,
                'message' => $validation['message'] ?? 'Dữ liệu in không hợp lệ!'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $res = dispatchWindowsDirectPrint($labels, $printerName, $labelSize);
        echo json_encode([
            'success'        => $res['success'],
            'direct_printed' => $res['success'],
            'message'        => $res['message'],
            'printer'        => $res['printer'] ?? $printerName,
            'pages'          => $res['pages_printed'] ?? count($labels)
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_POST['action'] === 'refresh_printers') {
        $printers = getConnectedPrinters(true);
        $defPrinter = getDefaultPrinter($pdo, true);
        echo json_encode([
            'success'           => true,
            'service_available' => !empty($printers),
            'is_local_machine'  => isClientLocalMachine(),
            'printers'          => array_values($printers),
            'default_printer'   => $defPrinter
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_POST['action'] === 'save_default_label_size') {
        $rawSize = strtolower(trim($_POST['label_size'] ?? ''));
        if (!in_array($rawSize, ['65x30', '110x80', '80x110', '100x80', '80x100'], true)) {
            echo json_encode([
                'success' => false,
                'message' => 'Khổ tem không hợp lệ!'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $labelSize = normalizeLabelSize($rawSize);
        saveDefaultLabelSize($labelSize, $pdo);
        echo json_encode([
            'success'    => true,
            'label_size' => $labelSize,
            'message'    => "Đã lưu cấu hình khổ tem mặc định: " . ($labelSize === '110x80' ? '80×100 / 100×80 mm (Ngang)' : '65×30 mm (Ngang)')
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $printerName = trim($_POST['printer_name'] ?? '');
    if (empty($printerName)) {
        echo json_encode([
            'success' => false,
            'message' => 'Vui lòng chọn máy in tem hợp lệ!'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    saveDefaultPrinter($printerName, $pdo);
    echo json_encode([
        'success'         => true,
        'default_printer' => $printerName,
        'message'         => "Đã lưu máy in mặc định: {$printerName}"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// =======================================================
// 3.5. XỬ LÝ XÓA CHỈ THỊ SẢN XUẤT (DELETE ORDER - CHỈ ADMIN)
// =======================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_order') {
    if (!isAdmin()) {
        setFlash('danger', 'LỖI PHÂN QUYỀN: Chỉ tài khoản Admin mới có quyền xóa chỉ thị sản xuất!');
        header("Location: orders.php");
        exit;
    }

    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId > 0) {
        try {
            $pdo->beginTransaction();
            $stmtCheck = $pdo->prepare("SELECT `id`, `slip_code`, `order_code` FROM `production_orders` WHERE `id` = ? FOR UPDATE");
            $stmtCheck->execute([$orderId]);
            $orderToDelete = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($orderToDelete) {
                // Xóa chi tiết tem thùng và lịch sử in
                $pdo->prepare("DELETE FROM `box_labels` WHERE `history_id` IN (SELECT `id` FROM `print_history` WHERE `order_id` = ?)")->execute([$orderId]);
                $pdo->prepare("DELETE FROM `print_history` WHERE `order_id` = ?")->execute([$orderId]);
                $pdo->prepare("DELETE FROM `production_orders` WHERE `id` = ?")->execute([$orderId]);
                $pdo->commit();

                // Ghi audit log
                $logMsg = date('[Y-m-d H:i:s]') . " [DELETE_ORDER_POST] User: {$currentUser['username']} ({$currentUser['employee_code']}) đã xóa chỉ thị ID: {$orderId}, Phiếu: {$orderToDelete['slip_code']}\n";
                $logFile = __DIR__ . '/logs/print_audit.log';
                if (file_exists(dirname($logFile))) {
                    @file_put_contents($logFile, $logMsg, FILE_APPEND);
                }

                setFlash('success', "Đã xóa thành công chỉ thị sản xuất [{$orderToDelete['slip_code']}] ({$orderToDelete['order_code']})!");
            } else {
                $pdo->rollBack();
                setFlash('danger', 'Không tìm thấy chỉ thị cần xóa hoặc chỉ thị đã bị xóa trước đó!');
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            setFlash('danger', 'Lỗi CSDL khi xóa chỉ thị: ' . $e->getMessage());
        }
    } else {
        setFlash('danger', 'Mã chỉ thị cần xóa không hợp lệ!');
    }

    header("Location: orders.php");
    exit;
}

// =======================================================
// 4. XỬ LÝ IMPORT CSV / EXCEL (Editor & Admin)
// Cột file mẫu: Mã phiếu, Mã chỉ thị, Mã sản phẩm, Tháng phát hành, Số lượng chỉ thị, Ghi chú
// Quy cách & Nhà cung cấp được tự động map từ bảng product_specs
// =======================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_excel') {
    if (!canManageDirectives()) {
        setFlash('danger', 'Bạn không có quyền Import chỉ thị sản xuất!');
        header("Location: orders.php");
        exit;
    }

    if (!isset($_FILES['file_upload']) || $_FILES['file_upload']['error'] !== UPLOAD_ERR_OK) {
        setFlash('danger', 'Vui lòng chọn file CSV hợp lệ!');
    } else {
        $file = $_FILES['file_upload'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, ['csv', 'txt'])) {
            setFlash('warning', 'Hệ thống hỗ trợ nạp nhanh qua file CSV (UTF-8 Comma Delimited)! Vui lòng lưu file Excel thành CSV UTF-8.');
        } else {
        $rawContent = file_get_contents($file['tmp_name']);
        if ($rawContent === false || strlen(trim($rawContent)) === 0) {
            setFlash('danger', 'Nội dung file CSV tải lên bị rỗng!');
        } else {
            // Loại bỏ UTF-8 BOM nếu có
            if (substr($rawContent, 0, 3) === "\xEF\xBB\xBF") {
                $rawContent = substr($rawContent, 3);
            }
            if (!mb_check_encoding($rawContent, 'UTF-8')) {
                $supportedEncodings = array_intersect(['UTF-8', 'Windows-1252', 'ISO-8859-1'], mb_list_encodings());
                $encoding = mb_detect_encoding($rawContent, $supportedEncodings, true);
                if ($encoding && $encoding !== 'UTF-8') {
                    $rawContent = mb_convert_encoding($rawContent, 'UTF-8', $encoding);
                }
            }

            // Tự động nhận diện dấu phân cách (, hoặc ;)
            $lines = preg_split('/\r\n|\r|\n/', trim($rawContent));
            $firstLine = $lines[0] ?? '';
            $commaCount = substr_count($firstLine, ',');
            $semiCount  = substr_count($firstLine, ';');
            $delimiter  = ($semiCount > $commaCount) ? ';' : ',';

            $tempHandle = fopen('php://memory', 'r+');
            fwrite($tempHandle, $rawContent);
            rewind($tempHandle);

            $totalDataRows = 0;
            $insertedCount = 0;
            $updatedCount  = 0;
            $errorCount    = 0;
            $missingSpecs  = [];

            $stmtFindSpec  = $pdo->prepare("SELECT product_code, pack_qty, supplier, weight_per_box FROM `product_specs` WHERE `product_code` = ? LIMIT 1");
            $stmtFindOrder = $pdo->prepare("SELECT id, printed_qty FROM `production_orders` WHERE `slip_code` = ? LIMIT 1");

            $stmtInsert = $pdo->prepare("INSERT INTO `production_orders` 
                (`slip_code`, `order_code`, `product_code`, `issue_month`, `target_qty`, `printed_qty`, `remaining_qty`, `pack_qty`, `supplier`, `weight_per_box`, `status`, `note`, `created_by`, `created_at`) 
                VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 'pending', ?, ?, NOW())");

            $stmtUpdate = $pdo->prepare("UPDATE `production_orders` SET 
                `order_code`     = ?,
                `product_code`   = ?,
                `issue_month`    = ?,
                `target_qty`     = ?,
                `remaining_qty`  = ?,
                `pack_qty`       = ?,
                `supplier`       = ?,
                `weight_per_box` = ?,
                `note`           = ?,
                `updated_at`     = NOW()
                WHERE `id` = ?");

            $pdo->beginTransaction();

            while (($data = fgetcsv($tempHandle, 2000, $delimiter)) !== false) {
                if (empty($data) || (count($data) === 1 && trim($data[0]) === '')) continue;
                
                $c0 = trim($data[0] ?? '');
                $c1 = trim($data[1] ?? '');
                $c2 = trim($data[2] ?? '');
                $c3 = trim($data[3] ?? '');
                $c4 = trim($data[4] ?? '');
                $c5 = trim($data[5] ?? '');

                if ($isFirstRow) {
                    $isFirstRow = false;
                    $lowerC0 = mb_strtolower($c0, 'UTF-8');
                    if (str_contains($lowerC0, 'phiếu') || str_contains($lowerC0, 'mã') || str_contains($lowerC0, 'slip') || !is_numeric($c4)) {
                        continue;
                    }
                }

                $totalDataRows++;

                $slipCode    = $c0;
                $orderCode   = $c1;
                $productCode = $c2;
                $issueMonth  = !empty($c3) ? $c3 : date('m/Y');
                $targetQty   = (int)$c4;
                $note        = $c5;

                if (empty($slipCode) || empty($orderCode) || empty($productCode) || $targetQty <= 0) {
                    $errorCount++;
                    continue;
                }

                // ĐỐI CHIẾU MÃ SẢN PHẨM VỚI DỮ LIỆU QUY CÁCH (SPECS)
                $stmtFindSpec->execute([$productCode]);
                $spec = $stmtFindSpec->fetch(PDO::FETCH_ASSOC);

                if (!$spec) {
                    $errorCount++;
                    $missingSpecs[$productCode] = true;
                    continue;
                }

                $packQty  = (int)$spec['pack_qty'];
                $supplier = $spec['supplier'] ?? '';
                $weight   = (float)$spec['weight_per_box'];

                // XỬ LÝ UPSERT THEO slip_code
                $stmtFindOrder->execute([$slipCode]);
                $existingOrder = $stmtFindOrder->fetch(PDO::FETCH_ASSOC);

                try {
                    if ($existingOrder) {
                        $orderId    = (int)$existingOrder['id'];
                        $printedQty = (int)$existingOrder['printed_qty'];
                        $remainQty  = max(0, $targetQty - $printedQty);

                        $stmtUpdate->execute([
                            $orderCode,
                            $productCode,
                            $issueMonth,
                            $targetQty,
                            $remainQty,
                            $packQty,
                            $supplier,
                            $weight,
                            $note,
                            $orderId
                        ]);
                        $updatedCount++;
                    } else {
                        $stmtInsert->execute([
                            $slipCode,
                            $orderCode,
                            $productCode,
                            $issueMonth,
                            $targetQty,
                            $targetQty,
                            $packQty,
                            $supplier,
                            $weight,
                            $note,
                            $currentUser['id']
                        ]);
                        $insertedCount++;
                    }
                } catch (PDOException $e) {
                    $errorCount++;
                }
            }

            $pdo->commit();
            fclose($tempHandle);

            $missingList = array_keys($missingSpecs);
            $flashMsg = "Import hoàn tất: Tổng số {$totalDataRows} dòng (Thêm mới: {$insertedCount}, Cập nhật: {$updatedCount}, Lỗi: {$errorCount}).";
            if (!empty($missingList)) {
                $flashMsg .= " Các sản phẩm chưa có quy cách: " . implode(', ', $missingList);
                setFlash('warning', $flashMsg);
            } else {
                setFlash('success', $flashMsg);
            }
            header("Location: orders.php");
            exit;
        }
        }
    }
}

// =======================================================
// BỘ LỌC TÌM KIẾM & PHÂN TRANG CHỈ THỊ (PAGINATION)
// =======================================================
$searchKeyword = trim($_GET['k'] ?? '');
$filterStatus  = trim($_GET['status'] ?? '');
$filterMonth   = trim($_GET['month'] ?? '');

// Màn hình chỉ thị sản xuất (Viewer): Chỉ hiển thị các chỉ thị ở trạng thái được phép in; ẩn hoàn toàn chỉ thị đã tạm dừng in
if (isViewer() && $filterStatus === 'paused') {
    $filterStatus = '';
}

$whereSql = " WHERE 1=1";
$params   = [];

if (isViewer()) {
    $whereSql .= " AND po.`status` != 'paused'";
}

if (!empty($searchKeyword)) {
    $whereSql .= " AND (po.`slip_code` LIKE ? OR po.`order_code` LIKE ? OR po.`product_code` LIKE ? OR po.`supplier` LIKE ?)";
    $kParam = "%{$searchKeyword}%";
    $params[] = $kParam;
    $params[] = $kParam;
    $params[] = $kParam;
    $params[] = $kParam;
}

if (!empty($filterStatus)) {
    $whereSql .= " AND po.`status` = ?";
    $params[] = $filterStatus;
}

if (!empty($filterMonth)) {
    $whereSql .= " AND po.`issue_month` LIKE ?";
    $params[] = "%{$filterMonth}%";
}

// 1. Đếm tổng số bản ghi (Total Records)
$countSql = "SELECT COUNT(*) FROM `production_orders` po " . $whereSql;
$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute($params);
$totalRecords = (int)$stmtCount->fetchColumn();

// 2. Cấu hình phân trang (Pagination Settings)
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = (int)($_GET['limit'] ?? 15);
if (!in_array($limit, [10, 15, 25, 50, 100])) {
    $limit = 15;
}
$totalPages = max(1, (int)ceil($totalRecords / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $limit;

// 3. Truy vấn danh sách chỉ thị theo trang với LIMIT và OFFSET
// Thứ tự sắp xếp ưu tiên:
// - Hoàn thành (completed) luôn được đẩy xuống vị trí cuối cùng của bảng
// - Ưu tiên 1 (Theo thời gian): Tháng phát hành mới nhất (issue_month gần nhất) lên trên cùng
// - Ưu tiên 2 (Theo trạng thái): Đang in (in_progress) -> Chờ in (pending) -> Tạm dừng (paused) -> Hoàn thành (completed)
$orderBySql = " ORDER BY 
    CASE WHEN po.`status` = 'completed' THEN 1 ELSE 0 END ASC,
    COALESCE(
        STR_TO_DATE(TRIM(po.`issue_month`), '%d/%m/%Y'),
        STR_TO_DATE(TRIM(po.`issue_month`), '%Y-%m-%d'),
        STR_TO_DATE(TRIM(po.`issue_month`), '%Y/%m/%d'),
        STR_TO_DATE(CONCAT('01/', TRIM(po.`issue_month`)), '%d/%m/%Y'),
        STR_TO_DATE(CONCAT('01-', TRIM(po.`issue_month`)), '%d-%m-%Y'),
        STR_TO_DATE(CONCAT('01-', TRIM(po.`issue_month`)), '%d-%b-%Y'),
        STR_TO_DATE(CONCAT('01/', TRIM(po.`issue_month`)), '%d/%b/%Y'),
        STR_TO_DATE(CONCAT(TRIM(po.`issue_month`), '-01'), '%Y-%m-%d'),
        STR_TO_DATE(CONCAT(TRIM(po.`issue_month`), '/01'), '%Y/%m/%d'),
        DATE(po.`created_at`)
    ) DESC,
    CASE po.`status`
        WHEN 'in_progress' THEN 1
        WHEN 'pending'     THEN 2
        WHEN 'paused'      THEN 3
        WHEN 'completed'   THEN 4
        ELSE 5
    END ASC,
    po.`created_at` DESC,
    po.`id` DESC";

$sql = "SELECT po.*, 
               COALESCE(ps.pack_qty, po.pack_qty) AS pack_qty,
               COALESCE(ps.supplier, po.supplier) AS supplier,
               COALESCE(ps.weight_per_box, po.weight_per_box) AS weight_per_box,
               COALESCE(ps.unit, 'pcs') AS unit,
               (CASE WHEN ps.id IS NOT NULL THEN 1 ELSE 0 END) AS has_spec 
        FROM `production_orders` po 
        LEFT JOIN `product_specs` ps ON po.`product_code` = ps.`product_code` AND po.`box_type` = ps.`box_type` "
        . $whereSql
        . $orderBySql
        . " LIMIT {$limit} OFFSET {$offset}";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$fromRecord = ($totalRecords > 0) ? ($offset + 1) : 0;
$toRecord   = min($offset + $limit, $totalRecords);

// Hàm helper sinh liên kết phân trang duy trì đầy đủ bộ lọc
function buildOrderPageUrl($pageNumber, $customParams = []) {
    $queryParams = $_GET;
    $queryParams['page'] = $pageNumber;
    foreach ($customParams as $k => $v) {
        $queryParams[$k] = $v;
    }
    return 'orders.php?' . http_build_query($queryParams);
}

// Lấy danh mục Specs để phục vụ Auto-complete / Datalist và tra cứu đổi loại thùng
$allSpecs = $pdo->query("SELECT product_code, box_type, pack_qty, supplier, weight_per_box, unit FROM `product_specs` ORDER BY `product_code` ASC, `box_type` ASC")->fetchAll();

// Lấy máy in mặc định đã lưu của người dùng hiện tại (danh sách máy in sẽ được lấy trực tiếp từ Client PC khi mở màn hình)
$connectedPrinters = [];
$defaultPrinter    = getDefaultPrinter($pdo);
$defaultLabelSize  = getDefaultLabelSize($pdo);
$currentUserId     = (int)($currentUser['id'] ?? 0);

// Tham số xuất Excel
$exportParams = http_build_query([
    'type'   => 'orders',
    'k'      => $searchKeyword,
    'status' => $filterStatus,
    'month'  => $filterMonth
]);
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
    <div>
        <h1 class="page-title m-0">📋 Quản Lý Chỉ Thị Sản Xuất (CTSX)</h1>
    </div>
    <div class="page-actions d-flex flex-wrap gap-2">
        <!-- NÚT MỞ BẢNG IN TEM NHANH (MỌI VAI TRÒ ĐỀU DÙNG ĐƯỢC) -->
        <button type="button" class="btn btn-primary" onclick="openOffcanvasPrint(null)">
            🏷️ Quét / In Tem Thùng
        </button>

        <!-- NÚT CẤU HÌNH MÁY IN TEM MẶC ĐỊNH -->
        <!-- <button type="button" class="btn btn-outline-dark" onclick="openModal('modal_printer_config')" title="Cấu hình máy in tem mặc định kết nối với máy tính">
            🖨️ Máy In: <strong id="header_default_printer_label"><?= htmlspecialchars($defaultPrinter ?: 'Chưa chọn') ?></strong>
        </button> -->

        <?php if (canManageDirectives()): ?>
            <button type="button" class="btn btn-outline-primary" onclick="openModal('modal_add_order')">
                ➕ Thêm Chỉ Thị Mới
            </button>
            <button type="button" class="btn btn-outline-secondary" onclick="openModal('modal_import_excel')">
                📂 Import CSV
            </button>
        <?php endif; ?>

        <a href="export.php?<?= $exportParams ?>" class="btn btn-outline-success">
            📊 Xuất Excel CTSX
        </a>
    </div>
</div>

<!-- THANH TÌM KIẾM & BỘ LỌC -->
<div class="card shadow-sm mb-3">
    <div class="card-body py-2 px-3">
        <form method="GET" action="orders.php" class="row g-2 align-items-center">
            <div class="col-md-5 col-12">
                <input type="text" name="k" class="form-control" 
                       placeholder="🔍 Tìm mã phiếu, mã chỉ thị (Lot), mã sản phẩm, nhà cung cấp..." 
                       value="<?= htmlspecialchars($searchKeyword) ?>">
            </div>
            <div class="col-md-3 col-sm-6 col-12">
                <select name="status" class="form-select">
                    <option value="">-- Tất cả trạng thái --</option>
                    <option value="pending" <?= ($filterStatus === 'pending') ? 'selected' : '' ?>>Chờ in (Pending)</option>
                    <option value="in_progress" <?= ($filterStatus === 'in_progress') ? 'selected' : '' ?>>Đang in (In Progress)</option>
                    <?php if (!isViewer()): ?>
                        <option value="paused" <?= ($filterStatus === 'paused') ? 'selected' : '' ?>>⏸️ Tạm dừng in (Paused)</option>
                    <?php endif; ?>
                    <option value="completed" <?= ($filterStatus === 'completed') ? 'selected' : '' ?>>Hoàn thành (Completed)</option>
                </select>
            </div>
            <div class="col-md-2 col-sm-6 col-12">
                <input type="text" name="month" class="form-control" placeholder="Tháng (vd: 10/2026)" value="<?= htmlspecialchars($filterMonth) ?>">
            </div>
            <div class="col-md-2 col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill">🔍 Lọc</button>
                <?php if (!empty($searchKeyword) || !empty($filterStatus) || !empty($filterMonth)): ?>
                    <a href="orders.php" class="btn btn-secondary">✖ Bỏ</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- BẢNG DANH SÁCH CHỈ THỊ SẢN XUẤT -->
<div class="card shadow-sm">
    <div class="card-header bg-white py-2 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="card-title fw-bold text-dark m-0">
                Danh Sách Chỉ Thị Sản Xuất <span class="badge bg-secondary ms-1"><?= number_format($totalRecords) ?> chỉ thị</span>
            </span>
            <?php if (canManageDirectives()): ?>
                <div class="d-inline-flex flex-wrap align-items-center gap-1 ms-md-2">
                    <button type="button" id="btn_batch_pause_selected" class="btn btn-sm btn-warning fw-bold" onclick="executeBatchPauseSelected()" disabled>
                        ⏸️ Tạm dừng in đã chọn (<span id="selected_orders_count">0</span>)
                    </button>
                    <?php if ($totalRecords > 0 && $filterStatus !== 'paused'): ?>
                        <button type="button" id="btn_batch_pause_filtered" class="btn btn-sm btn-outline-warning text-dark fw-bold" onclick="executeBatchPauseFiltered()">
                            ⏸️ Tạm dừng tất cả theo bộ lọc (<?= number_format($totalRecords) ?>)
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <span class="text-muted small">Nhấn <strong>"🏷️ In"</strong> trên từng dòng để mở form in trượt tiện lợi</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive table-sticky-container">
            <table class="table table-hover align-middle mb-0 table-sticky" id="orders_table">
                <thead class="table-light table-sticky-header">
                    <tr>
                        <?php if (canManageDirectives()): ?>
                        <th style="width:42px;" class="text-center">
                            <input type="checkbox" class="form-check-input" id="chk_select_all_orders" title="Chọn tất cả chỉ thị được phép tạm dừng trên trang này">
                        </th>
                        <?php endif; ?>
                        <th style="width:50px;" class="text-center">STT</th>
                        <th style="width:50px;" class="text-center">Mã Phiếu</th>
                        <th>Mã Chỉ Thị</th>
                        <th>Mã Sản Phẩm</th>
                        <th class="text-center">Tháng PH</th>
                        <th class="text-end">SL Chỉ Thị</th>
                        <th class="text-end">Đã In</th>
                        <th class="text-end">Còn Lại</th>
                        <th class="text-center">Quy Cách</th>
                        <th style="width:120px;">Tiến Độ</th>
                        <th class="text-center">Trạng Thái</th>
                        <th class="text-center" style="width:230px;">Thao Tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="<?= canManageDirectives() ? 13 : 12 ?>" class="text-center py-4 text-muted">
                                Không tìm thấy chỉ thị nào phù hợp.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($orders as $idx => $row): 
                            $target   = (int)$row['target_qty'];
                            $printed  = (int)$row['printed_qty'];
                            $remain   = (int)$row['remaining_qty'];
                            $pct      = ($target > 0) ? min(100, round(($printed / $target) * 100)) : 0;
                            $isPaused = ($row['status'] === 'paused');
                            
                            $statusBadgeClass = 'bg-secondary';
                            $statusText = 'Chờ in';
                            if ($row['status'] === 'paused') {
                                $statusBadgeClass = 'bg-warning text-dark';
                                $statusText = '⏸️ Tạm Dừng';
                            } elseif ($row['status'] === 'completed') {
                                $statusBadgeClass = 'bg-success';
                                $statusText = 'Hoàn thành';
                            } elseif ($row['status'] === 'in_progress') {
                                $statusBadgeClass = 'bg-primary';
                                $statusText = 'Đang in';
                            }
                        ?>
                        <tr id="row_order_<?= $row['id'] ?>" class="<?= $isPaused ? 'table-warning' : '' ?>">
                            <?php if (canManageDirectives()): ?>
                            <td class="text-center">
                                <input type="checkbox" class="form-check-input order-row-checkbox" 
                                       value="<?= (int)$row['id'] ?>" 
                                       data-slip="<?= htmlspecialchars($row['slip_code']) ?>"
                                       data-status="<?= htmlspecialchars($row['status']) ?>"
                                       <?= $isPaused ? 'disabled title="Chỉ thị đã ở trạng thái tạm dừng"' : 'title="Chọn chỉ thị để tạm dừng hàng loạt"' ?>>
                            </td>
                            <?php endif; ?>
                            <td class="text-center text-muted"><?= $fromRecord + $idx ?></td>
                            <td>
                                <strong class="text-primary text-break"><?= htmlspecialchars($row['slip_code']) ?></strong>
                            </td>
                            <td>
                                <strong class="text-dark"><?= htmlspecialchars($row['order_code']) ?></strong>
                            </td>
                            <td>
                                <div class="fw-bold text-dark"><?= htmlspecialchars($row['product_code']) ?></div>

                            </td>
                            <td class="text-center">
                                <span class="badge bg-light text-dark border"><?= htmlspecialchars($row['issue_month'] ?: '-') ?></span>
                            </td>
                            <td class="text-end fw-bold">
                                <?= number_format($target) ?>
                            </td>
                            <td class="text-end fw-bold text-primary cell-printed">
                                <?= number_format($printed) ?>
                            </td>
                            <td class="text-end fw-bold cell-remaining <?= ($remain <= 0) ? 'text-success' : 'text-danger' ?>">
                                <?= number_format($remain) ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-light text-dark border"><?= (int)$row['pack_qty'] ?> con/thùng</span>

                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-1">
                                    <div class="progress flex-grow-1" style="height: 7px;">
                                         <div class="progress-bar <?= ($pct >= 100) ? 'bg-success' : ($isPaused ? 'bg-warning' : 'bg-primary') ?> cell-progressbar" 
                                              role="progressbar" style="width: <?= $pct ?>%;" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                    <small class="fw-bold text-muted cell-pct" style="font-size:11px;min-width:32px;text-align:right;"><?= $pct ?>%</small>
                                </div>
                            </td>
                            <td class="text-center cell-status">
                                <span class="badge <?= $statusBadgeClass ?>"><?= $statusText ?></span>
                            </td>
                            <td class="text-center">
                                <div class="d-inline-flex gap-1 align-items-center">
                                    <!-- NÚT 1: IN TEM OFFCANVAS (VIEWER, EDITOR, ADMIN ĐỀU ĐƯỢC PHÉP) -->
                                    <button type="button" 
                                            class="btn btn-sm <?= $isPaused ? 'btn-secondary' : 'btn-primary' ?> btn-action-print" 
                                            onclick="openOffcanvasPrintById(<?= (int)$row['id'] ?>)"
                                            title="<?= $isPaused ? 'Chỉ thị đang tạm dừng in' : 'Nhập liệu in tem thùng' ?>">
                                        🏷️ In
                                    </button>

                                    <!-- NÚT 2 & 3: CHỈNH SỬA & TẠM DỪNG (DÀNH CHO EDITOR & ADMIN) -->
                                    <?php if (canManageDirectives()): ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" 
                                                onclick="openEditModalById(<?= (int)$row['id'] ?>)" 
                                                title="Chỉnh sửa thông số chỉ thị">
                                            ✏️
                                        </button>

                                        <?php if ($isPaused): ?>
                                            <button type="button" class="btn btn-sm btn-success" 
                                                    onclick="requestResume(<?= (int)$row['id'] ?>, '<?= htmlspecialchars(addslashes($row['slip_code']), ENT_QUOTES, 'UTF-8') ?>')" 
                                                    title="Tiếp tục cho phép in">
                                                ▶️ Mở
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-warning" 
                                                    onclick="requestPause(<?= (int)$row['id'] ?>, '<?= htmlspecialchars(addslashes($row['slip_code']), ENT_QUOTES, 'UTF-8') ?>')" 
                                                    title="Tạm dừng in tem chỉ thị này">
                                                ⏸️ Dừng
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <!-- NÚT 4: XÓA CHỈ THỊ SẢN XUẤT (CHỈ DÀNH CHO ADMIN) -->
                                    <?php if (isAdmin()): ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger" 
                                                onclick="requestDeleteOrder(<?= (int)$row['id'] ?>, '<?= htmlspecialchars(addslashes($row['slip_code']), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(addslashes($row['order_code']), ENT_QUOTES, 'UTF-8') ?>')" 
                                                title="Xóa chỉ thị sản xuất này (Chỉ Admin)">
                                            🗑️
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- THANH ĐIỀU HƯỚNG PHÂN TRANG (PAGINATION) -->
    <div class="card-footer bg-white py-3 border-top d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div class="d-flex align-items-center gap-2 small text-muted flex-wrap">
            <span>
                Hiển thị <strong><?= $fromRecord ?></strong> - <strong><?= $toRecord ?></strong> trên tổng số <strong><?= number_format($totalRecords) ?></strong> chỉ thị
                (Trang <strong><?= $page ?></strong> / <strong><?= $totalPages ?></strong>)
            </span>
            <span class="mx-1 d-none d-sm-inline">|</span>
            <label class="d-inline-flex align-items-center gap-1 mb-0">
                Hiển thị:
                <select class="form-select form-select-sm d-inline-block w-auto" onchange="changePageLimit(this.value)">
                    <?php foreach ([10, 15, 25, 50, 100] as $limOpt): ?>
                        <option value="<?= $limOpt ?>" <?= ($limit === $limOpt) ? 'selected' : '' ?>><?= $limOpt ?></option>
                    <?php endforeach; ?>
                </select>
                dòng/trang
            </label>
        </div>

        <?php if ($totalPages > 1): ?>
        <nav aria-label="Phân trang chỉ thị">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= buildOrderPageUrl(1) ?>" title="Trang đầu">&laquo;</a>
                </li>
                <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= buildOrderPageUrl($page - 1) ?>" title="Trang trước">&lsaquo;</a>
                </li>

                <?php
                $startP = max(1, $page - 2);
                $endP   = min($totalPages, $page + 2);
                if ($endP - $startP < 4) {
                    if ($startP === 1) {
                        $endP = min($totalPages, $startP + 4);
                    } elseif ($endP === $totalPages) {
                        $startP = max(1, $endP - 4);
                    }
                }
                if ($startP > 1): ?>
                    <li class="page-item"><a class="page-link" href="<?= buildOrderPageUrl(1) ?>">1</a></li>
                    <?php if ($startP > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>

                <?php for ($p = $startP; $p <= $endP; $p++): ?>
                    <li class="page-item <?= ($p === $page) ? 'active' : '' ?>">
                        <a class="page-link" href="<?= buildOrderPageUrl($p) ?>"><?= $p ?></a>
                    </li>
                <?php endfor; ?>

                <?php if ($endP < $totalPages): ?>
                    <?php if ($endP < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="<?= buildOrderPageUrl($totalPages) ?>"><?= $totalPages ?></a></li>
                <?php endif; ?>

                <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= buildOrderPageUrl($page + 1) ?>" title="Trang sau">&rsaquo;</a>
                </li>
                <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= buildOrderPageUrl($totalPages) ?>" title="Trang cuối">&raquo;</a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- =======================================================
     OFFCANVAS: NHẬP LIỆU & IN TEM THÙNG TRỰC TIẾP (INTERACTIVE SLIDE-OVER)
     ======================================================= -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasPrint" aria-labelledby="offcanvasPrintLabel" style="width: 580px; max-width: 95vw;">
    <div class="offcanvas-header bg-dark text-white py-3">
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-primary fs-6">🏷️ IN TEM</span>
            <h5 class="offcanvas-title mb-0" id="offcanvasPrintLabel">Nhập Liệu In Tem Thùng</h5>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body p-4">
        
        <!-- Ô TRA CỨU / QUÉT MÃ VẠCH TRONG OFFCANVAS -->
        <div class="mb-3" id="offcanvas_search_container">
            <label class="form-label fw-bold">Mã Phiếu hoặc Mã Chỉ Thị (Quét Barcode / Nhập mã)</label>
            <div class="input-group">
                <input type="text" id="offcanvas_query_input" class="form-control form-control-lg" 
                       placeholder="Quét mã vạch phiếu (vd: PL-2610-01)..." autocomplete="off">
                <button class="btn btn-primary px-3" type="button" id="btn_offcanvas_search">
                    🔍 Tìm
                </button>
            </div>
            <small class="text-muted">Máy quét Barcode sẽ tự động gửi lệnh tìm kiếm khi Enter.</small>
        </div>

        <!-- THÔNG TIN CHỈ THỊ (HIỂN THỊ KHI ĐÃ CHỌN HOẶC TÌM THẤY) -->
        <div id="offcanvas_order_card" class="card bg-light border p-3 mb-3" style="display:none;">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-bold text-dark">📌 Thông Tin Chỉ Thị</span>
                <span id="offcanvas_badge_status" class="badge bg-success">PENDING</span>
            </div>
            <div class="row g-2 small mb-3">
                <div class="col-6"><strong>Mã phiếu:</strong> <span id="oc_disp_slip" class="text-primary fw-bold">-</span></div>
                <div class="col-6"><strong>Mã chỉ thị:</strong> <span id="oc_disp_order" class="fw-bold">-</span></div>
                <div class="col-6"><strong>Mã sản phẩm:</strong> <span id="oc_disp_product" class="fw-bold">-</span></div>
                <div class="col-6"><strong>Tháng phát hành:</strong> <span id="oc_disp_month" class="badge bg-secondary">-</span></div>
                <div class="col-6"><strong>Quy cách:</strong> <span id="oc_disp_pack" class="text-warning fw-bold">-</span> con/thùng</div>
                <div class="col-6"><strong>Loại thùng:</strong> <span id="oc_disp_box_type" class="badge bg-primary">Thùng loại 1</span></div>
                <div class="col-12"><strong>Nhà cung cấp:</strong> <span id="oc_disp_supp">-</span></div>
            </div>

            <!-- KPI THỐNG KÊ SỐ LƯỢNG -->
            <div class="row g-2 text-center">
                <div class="col-4">
                    <div class="bg-white border rounded p-2">
                        <small class="text-muted d-block fw-bold" style="font-size:10px;">CHỈ THỊ</small>
                        <span id="oc_disp_target" class="fw-bold fs-5 text-dark">0</span>
                    </div>
                </div>
                <div class="col-4">
                    <div class="bg-white border rounded p-2">
                        <small class="text-muted d-block fw-bold" style="font-size:10px;">ĐÃ IN</small>
                        <span id="oc_disp_printed" class="fw-bold fs-5 text-primary">0</span>
                    </div>
                </div>
                <div class="col-4">
                    <div class="bg-white border rounded p-2">
                        <small class="text-muted d-block fw-bold" style="font-size:10px;">CÒN LẠI</small>
                        <span id="oc_disp_remaining" class="fw-bold fs-5 text-success">0</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- CẢNH BÁO TẠM DỪNG IN -->
        <div id="offcanvas_alert_paused" class="alert alert-warning border-warning" style="display:none;">
            <div class="fw-bold">⛔ CHỈ THỊ ĐANG BỊ TẠM DỪNG IN TEM!</div>
            <div class="small mt-1">Chỉ thị này đã bị tạm dừng theo yêu cầu kỹ thuật/quản lý. Thao tác in tem tạm thời bị khóa. Vui lòng liên hệ Editor hoặc Admin để mở khóa.</div>
        </div>

        <!-- FORM NHẬP SỐ LƯỢNG IN -->
        <div id="offcanvas_print_inputs" style="display:none;">
            <!-- LỰA CHỌN LOẠI THÙNG KHI IN -->
            <div class="mb-3">
                <label class="form-label fw-bold required text-primary">📦 Chọn Loại Thùng Khi In:</label>
                <select id="offcanvas_box_type" class="form-select form-select-lg fw-bold border-primary shadow-sm">
                    <option value="1/2">Thùng loại 1/2</option>
                    <option value="1/4">Thùng loại 1/4</option>
                </select>
                
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold required">Số Lượng Tem Sản Phẩm Đã In Lượt Này (con / pcs)</label>
                <input type="number" id="offcanvas_print_qty" class="form-control form-control-lg fw-bold" 
                       placeholder="Nhập số lượng tem (vd: 20, 50, 100)..." min="1" step="1">
                <small class="text-muted">Hệ thống sẽ tự động tính số tem thùng = Số lượng in &divide; Quy cách đóng gói.</small>
            </div>

            <!-- BẢNG TÍNH TOÁN TEM THÙNG REALTIME -->
            <div id="offcanvas_calc_panel" class="card border-primary bg-light p-3 mb-3" style="display:none;">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted small">Loại thùng áp dụng:</span>
                    <strong id="oc_calc_box_type_label" class="text-primary">Thùng loại 1</strong>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted small">Quy cách đóng gói:</span>
                    <strong id="oc_calc_pack">0 con/thùng</strong>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted small">Số thùng chẵn (đủ quy cách):</span>
                    <span id="oc_calc_full_boxes" class="fw-bold">0 thùng</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2" id="oc_row_calc_odd" style="display:none;">
                    <span class="text-warning small fw-bold">⚠️ Thùng lẻ:</span>
                    <strong id="oc_calc_odd_qty" class="text-warning">0 con</strong>
                </div>
                <hr class="my-2">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-bold text-dark">TỔNG SỐ TEM THÙNG:</span>
                    <span id="oc_calc_total_boxes" class="fs-4 fw-bold text-success">0 TEM</span>
                </div>
            </div>

            <!-- TÙY CHỌN & CẢNH BÁO IN THÙNG LẺ (KHI REMAINDER > 0) -->
            <div id="offcanvas_odd_option" class="alert alert-warning border-warning p-3 mb-3" style="display:none;">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="fs-4">⚠️</span>
                    <div>
                        <strong class="text-dark">Phát hiện thùng lẻ:</strong>
                        <div id="oc_odd_warning_text" class="text-danger fw-bold small"></div>
                    </div>
                </div>
                <div class="form-check pt-2 border-top border-warning">
                    <input class="form-check-input" type="checkbox" id="oc_chk_odd_box">
                    <label class="form-check-label fw-bold text-dark" for="oc_chk_odd_box">
                        Xác nhận cho phép in thùng lẻ (Allow Odd Box Printing)
                    </label>
                </div>
                <small class="text-muted d-block mt-1" style="font-size: 11.5px;">
                    * Bắt buộc tích chọn ô trên để xác nhận in số lượng dư chưa đủ quy cách 1 thùng chẵn.
                </small>
            </div>

            <!-- CẢNH BÁO VƯỢT ĐỊNH MỨC -->
            <div id="offcanvas_alert_over" class="alert alert-danger mb-3" style="display:none;">
                <div class="fw-bold">⚠️ CẢNH BÁO VƯỢT ĐỊNH MỨC CÒN LẠI!</div>
                <div class="small">
                    Số lượng in (<span id="oc_txt_excess_print">0</span>) vượt quá số còn lại (<span id="oc_txt_excess_rem">0</span>)!<br>
                    Cần Quản lý/Admin nhập <strong>Mã số nhân viên (MSNV)</strong>, <strong>Mật khẩu</strong> và <strong>Lý do in vượt</strong> để duyệt.
                </div>
            </div>

            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label fw-bold mb-0">🖨️ Máy In Tem Sử Dụng (Mặc định)</label>
                    <div class="d-flex flex-wrap gap-1 align-items-center">
                        <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none" onclick="promptAddClientCustomPrinter()" title="Khai báo thêm máy in đã cài đặt trên máy tính này">
                            ➕ Thêm
                        </button>
                        <span class="text-muted" style="font-size:11px;">|</span>
                        <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none text-danger" onclick="removeActiveSavedPrinter()" title="Xóa máy in đang chọn khỏi cấu hình">
                            🗑️ Xóa
                        </button>
                        <span class="text-muted" style="font-size:11px;">|</span>
                        <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none text-warning-emphasis" onclick="resetAllPrinterConfig()" title="Đặt lại toàn bộ cấu hình máy in trên máy tính này">
                            ⚙️ Đặt lại
                        </button>
                        <span class="text-muted" style="font-size:11px;">|</span>
                        <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none" onclick="refreshConnectedPrinters(true, true)" title="Quét lại máy in thực tế từ máy tính hiện tại">
                            🔄 Quét lại
                        </button>
                    </div>
                </div>
                <select id="offcanvas_printer_select" class="form-select border-primary fw-bold" onchange="onDefaultPrinterChange(this.value)">
                    <option value="Browser Print">⏳ Đang kiểm tra dịch vụ máy in trên máy tính hiện tại...</option>
                </select>
                <div id="offcanvas_printer_status_msg" class="mt-1 small fw-bold" style="display:none;"></div>

                <!-- THÔNG BÁO THÂN THIỆN & FALLBACK KHI KHÔNG TÌM THẤY DỊCH VỤ IN CỤC BỘ -->
                <div id="offcanvas_no_service_panel" class="alert alert-warning border-warning p-2 mt-2 mb-0" style="display:none; background:#fffbeb; border-radius:8px;">
                    <div class="fw-bold text-warning-emphasis mb-1" style="font-size:0.9rem;">
                        ⚠️ Không phát hiện dịch vụ máy in cục bộ trên máy tính này
                    </div>
                    <div class="small text-dark mb-2" style="line-height:1.45;">
                        Không phát hiện dịch vụ máy in cục bộ trên máy tính này.<br>
                        Bạn vẫn có thể sử dụng chức năng In Qua Trình Duyệt.<br>
                        Để sử dụng In Trực Tiếp, vui lòng cài đặt phần mềm hỗ trợ in.
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-sm btn-outline-dark fw-bold" onclick="openPrinterSetupGuideModal()">
                            📖 Hướng Dẫn Cài Đặt
                        </button>
                        <button type="button" class="btn btn-sm btn-primary fw-bold" onclick="activateBrowserPrintFallback(true)">
                            🖨️ In Qua Trình Duyệt
                        </button>
                    </div>
                </div>


            </div>

            <!-- CHỌN KHỔ TEM IN -->
            <div class="mb-3">
                <label class="form-label fw-bold mb-1">📐 Kích Thước Tem In:</label>
                <select id="offcanvas_label_size_select" class="form-select border-primary fw-bold" onchange="onLabelSizeChange(this.value)">
                    <option value="65x30" <?= ($defaultLabelSize === '65x30') ? 'selected' : '' ?>>Tem nhỏ (65 × 30 mm - Ngang) - Mặc định xưởng</option>
                    <option value="110x80" <?= ($defaultLabelSize === '110x80') ? 'selected' : '' ?>>Tem lớn (80 × 100 / 100 × 80 / 110 × 80 mm - Ngang)</option>
                </select>

            </div>

            <!-- TÙY CHỌN CHẾ ĐỘ IN: IN TRỰC TIẾP HOẶC XEM TRƯỚC -->
            <div class="mb-3 p-2 bg-light border rounded">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label fw-bold mb-0 small text-dark">⚡ Chế Độ Xuất Lệnh In:</label>
                    <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size: 0.78rem;" onclick="previewDraftLayoutOffcanvas()">
                        🔍 Xem mẫu bố cục
                    </button>
                </div>
                <div class="d-flex gap-3 mt-1">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="offcanvas_print_mode" id="oc_mode_direct" value="direct" checked onchange="onPrintModeChange(this.value)">
                        <label class="form-check-label fw-bold text-success small" for="oc_mode_direct">
                            🖨️ In trực tiếp (Tự động gửi lệnh in)
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="offcanvas_print_mode" id="oc_mode_preview" value="preview" onchange="onPrintModeChange(this.value)">
                        <label class="form-check-label fw-bold text-secondary small" for="oc_mode_preview">
                            👁️ Xem trước (Preview)
                        </label>
                    </div>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Ghi Chú Đợt In (Tùy chọn)</label>
                <input type="text" id="offcanvas_note" class="form-control" placeholder="Ghi chú máy in, ca kíp...">
            </div>

            <button type="button" id="btn_offcanvas_submit" class="btn btn-success btn-lg w-100 fw-bold py-3 shadow-sm">
                📥 XÁC NHẬN NHẬP &amp; IN TEM THÙNG
            </button>
        </div>

    </div>
</div>

<!-- =======================================================
     MODAL 1: XÁC NHẬN TRƯỚC KHI IN TEM THÙNG (FACTORY CONFIRMATION)
     ======================================================= -->
<div id="modal_confirm_print" class="modal-overlay">
    <div class="modal-dialog">
        <div class="modal-header bg-light">
            <span class="modal-title fw-bold text-dark">⚠️ Xác Nhận In Tem Thùng</span>
            <button type="button" class="modal-close" onclick="closeModal('modal_confirm_print')">&times;</button>
        </div>
        <div class="modal-body">
            <p class="mb-3">Vui lòng kiểm tra lại các thông số trước khi lưu và xuất lệnh in:</p>
            
            <table class="table table-bordered mb-3">
                <tr>
                    <td style="width:40%;" class="fw-bold bg-light">Mã phiếu chỉ thị:</td>
                    <td id="cf_slip_code" class="fw-bold text-primary">-</td>
                </tr>
                <tr>
                    <td class="fw-bold bg-light">Mã chỉ thị (Lot):</td>
                    <td id="cf_order_code" class="fw-bold">-</td>
                </tr>
                <tr>
                    <td class="fw-bold bg-light">Mã sản phẩm:</td>
                    <td id="cf_product_code" class="fw-bold">-</td>
                </tr>
                <tr>
                    <td class="fw-bold bg-light">Phân loại thùng:</td>
                    <td id="cf_box_type" class="fw-bold text-dark">-</td>
                </tr>
                <tr>
                    <td class="fw-bold bg-light">Số lượng tem SP:</td>
                    <td id="cf_print_qty" class="fw-bold text-dark">-</td>
                </tr>
                <tr>
                    <td class="fw-bold bg-light">Quy cách đóng gói:</td>
                    <td id="cf_pack_qty">-</td>
                </tr>
                <tr>
                    <td class="fw-bold bg-light">Số tem thùng sinh ra:</td>
                    <td id="cf_box_count" class="fw-bold text-success fs-5">-</td>
                </tr>
                <tr>
                    <td class="fw-bold bg-light">Máy in mặc định:</td>
                    <td id="cf_printer_name" class="fw-bold text-primary">-</td>
                </tr>
                <tr>
                    <td class="fw-bold bg-light">Khổ tem in:</td>
                    <td id="cf_label_size" class="fw-bold text-dark">-</td>
                </tr>
                <tr id="cf_row_odd" style="display:none;" class="table-warning">
                    <td class="fw-bold text-warning">Chi tiết thùng lẻ:</td>
                    <td id="cf_odd_desc" class="fw-bold text-warning">-</td>
                </tr>
                <tr id="cf_row_over" style="display:none;" class="table-danger">
                    <td class="fw-bold text-danger">Duyệt vượt mức:</td>
                    <td id="cf_over_desc" class="fw-bold text-danger">-</td>
                </tr>
            </table>

            <small class="text-muted d-block">
                Sau khi xác nhận, hệ thống tự động sinh các mã thùng tuần tự <code class="text-primary">TU-<?= date('ymd') ?>-XXX</code>, hiển thị tem kèm mã QR theo khổ đã chọn (<span id="cf_label_size_note" class="fw-bold">65x30mm</span>) và gửi lệnh in trực tiếp đến máy in mặc định.
            </small>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('modal_confirm_print')">Quay lại kiểm tra</button>
            <button type="button" id="btn_confirm_print_execute" class="btn btn-success btn-lg fw-bold">
                ✅ ĐỒNG Ý NHẬP &amp; IN NGAY
            </button>
        </div>
    </div>
</div>

<!-- =======================================================
     MODAL 2: DUYỆT VƯỢT ĐỊNH MỨC DÀNH CHO ADMIN / EDITOR (MSNV, PASSWORD & LÝ DO)
     ======================================================= -->
<div id="modal_admin_approval" class="modal-overlay" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="modal_approval_title">
    <div class="modal-dialog" style="max-width: 540px;">
        <form id="form_admin_approval" autocomplete="off" onsubmit="return false;" novalidate>
            <div class="modal-header bg-danger text-white">
                <span class="modal-title fw-bold" id="modal_approval_title">🛡️ Yêu Cầu Ủy Quyền Duyệt Vượt Định Mức</span>
                <button type="button" class="modal-close text-white" onclick="closeModal('modal_admin_approval')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning mb-3">
                    Số lượng in (<strong id="modal_req_qty">0</strong> con) vượt quá số còn lại của chỉ thị (<strong id="modal_rem_qty">0</strong> con).<br>
                    Chênh lệch vượt mức: <strong id="modal_diff_qty" class="text-danger">+0 con</strong>.
                </div>

                <p class="fw-bold text-dark mb-2">
                    Bắt buộc Quản lý / Admin / Editor nhập Mã số nhân viên (MSNV), Mật khẩu và Lý do in vượt để xác nhận ủy quyền:
                </p>

                <div class="mb-3">
                    <label for="input_admin_msnv" class="form-label required fw-bold">Mã số nhân viên người duyệt (MSNV)</label>
                    <input type="text" id="input_admin_msnv" name="msnv" class="form-control form-control-lg" 
                           placeholder="Nhập MSNV Admin/Editor (vd: ADM-001, NV-001)..." 
                           tabindex="1" autocomplete="off" required>
                    <small class="text-muted">Nhập mã MSNV của tài khoản có vai trò Admin hoặc Editor.</small>
                </div>

                <div class="mb-3">
                    <label for="input_admin_pass" class="form-label required fw-bold">Mật khẩu xác thực (Password / PIN)</label>
                    <div class="input-group">
                        <input type="password" id="input_admin_pass" name="password" class="form-control form-control-lg" 
                               placeholder="Nhập mật khẩu tài khoản người duyệt..." 
                               tabindex="2" autocomplete="current-password" required>
                        <button class="btn btn-outline-secondary" type="button" id="btn_toggle_approval_pass" tabindex="-1" title="Ẩn/Hiện mật khẩu">
                            👁️
                        </button>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="input_override_reason" class="form-label required fw-bold">Lý do in vượt định mức</label>
                    <textarea id="input_override_reason" name="override_reason" class="form-control" rows="2"
                              placeholder="Nhập rõ lý do in vượt định mức (bắt buộc, vd: In bù hàng NG, thay thế tem hỏng, bổ sung theo yêu cầu)..."
                              tabindex="3" required></textarea>
                    <small class="text-muted">Thông tin MSNV, thời gian, nội dung và lý do vượt định mức sẽ được lưu vào Lịch sử in.</small>
                    <div id="admin_verify_feedback" class="mt-2 small fw-bold"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal_admin_approval')">Hủy bỏ</button>
                <button type="submit" id="btn_verify_and_proceed" class="btn btn-danger fw-bold" tabindex="4">
                    🔑 Xác nhận duyệt vượt mức
                </button>
            </div>
        </form>
    </div>
</div>

<!-- =======================================================
     MODAL 3: XEM VÀ IN TEM THÙNG CHUẨN CÔNG NGHIỆP 65x30MM
     ======================================================= -->
<div id="modal_print_result" class="modal-overlay">
    <div class="modal-dialog" style="max-width:760px;">
        <div class="modal-header no-print bg-light py-2">
            <div class="d-flex align-items-center gap-2">
                <span class="fs-5">🏷️</span>
                <span class="modal-title fw-bold text-dark" id="modal_print_preview_title">Xem Trước &amp; In Tem QR</span>
            </div>
            <button type="button" class="modal-close" onclick="closeModal('modal_print_result')" title="Thoát">&times;</button>
        </div>
        <div class="modal-body p-3">
            <!-- THANH THÔNG TIN PREVIEW TINH GỌN (CHỈ HIỆN TRÊN MÀN HÌNH, ẨN HOÀN TOÀN KHI IN) -->
            <div class="no-print d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-2 bg-light border rounded">
                <div class="small d-flex flex-wrap align-items-center gap-3">
                    <div><strong>Số tem:</strong> <span id="res_box_count" class="badge bg-success fs-6">0</span> tem</div>
                    <div><strong>Máy in:</strong> <span id="res_printer_name" class="text-primary fw-bold"><?= htmlspecialchars($defaultPrinter ?: 'Máy in tem mặc định') ?></span></div>
                    <div class="d-flex align-items-center gap-1">
                        <strong>Khổ tem:</strong>
                        <select id="preview_label_size_select" class="form-select form-select-sm border-primary fw-bold py-0" style="width: auto;" onchange="onLabelSizeChange(this.value, true)">
                            <option value="65x30" <?= ($defaultLabelSize === '65x30') ? 'selected' : '' ?>>Tem nhỏ (65 × 30 mm - Ngang)</option>
                            <option value="110x80" <?= ($defaultLabelSize === '110x80') ? 'selected' : '' ?>>Tem lớn (80 × 100 / 100 × 80 mm - Ngang)</option>
                        </select>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="closeModal('modal_print_result')">Đóng</button>
                    <button type="button" class="btn btn-success btn-sm fw-bold" id="btn_preview_direct_spool" onclick="sendPreviewToDirectSpooler()">⚡ In Trực Tiếp</button>
                    <button type="button" class="btn btn-outline-primary btn-sm fw-bold" onclick="triggerDirectPrint()">🖨️ In Trình Duyệt</button>
                </div>
            </div>

            <!-- VÙNG PREVIEW VÀ IN TEM DUY NHẤT (CHUẨN 65x30MM & 110x80MM NGANG, KHÔNG THANH CUỘN LÀM BIẾN DẠNG) -->
            <div id="printable_labels_container" class="label-preview-wrapper label-size-<?= $defaultLabelSize ?>">
                <!-- Javascript điền tem vào đây -->
            </div>
        </div>
        <div class="modal-footer no-print py-2">
            <span class="text-muted small me-auto" id="preview_footer_note">💡 Khổ tem <span id="preview_footer_size_txt"><?= ($defaultLabelSize === '110x80') ? '110mm × 80mm (Ngang)' : '65mm × 30mm (Ngang)' ?></span>, chuẩn 1 tem = 1 trang (không trang trắng).</span>
            <button type="button" class="btn btn-secondary" onclick="closeModal('modal_print_result')">Đóng</button>
            <button type="button" class="btn btn-success fw-bold" onclick="sendPreviewToDirectSpooler()">⚡ Gửi Lệnh In Trực Tiếp</button>
            <button type="button" class="btn btn-outline-primary fw-bold" onclick="triggerDirectPrint()">🖨️ In Qua Trình Duyệt</button>
        </div>
    </div>
</div>

<!-- =======================================================
     MODAL 4: CHỈNH SỬA THÔNG TIN CHỈ THỊ (EDIT ORDER)
     ======================================================= -->
<?php if (canManageDirectives()): ?>
<div id="modal_edit_order" class="modal-overlay">
    <div class="modal-dialog">
        <form method="POST" action="orders.php" id="form_edit_order">
            <input type="hidden" name="action" value="edit_order">
            <input type="hidden" name="order_id" id="edit_order_id">
            
            <div class="modal-header bg-light">
                <span class="modal-title fw-bold text-dark">✏️ Chỉnh Sửa Thông Tin Chỉ Thị</span>
                <button type="button" class="modal-close" onclick="closeModal('modal_edit_order')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Mã Phiếu Chỉ Thị</label>
                        <input type="text" id="edit_slip_code" class="form-control" readonly title="Mã phiếu cố định không đổi">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required">Mã Chỉ Thị (Lot No)</label>
                        <input type="text" name="order_code" id="edit_order_code" class="form-control" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label required">Mã Sản Phẩm</label>
                        <input type="text" name="product_code" id="edit_product_code" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required">Phân Loại Thùng</label>
                        <select name="box_type" id="edit_box_type" class="form-select" required>
                            <option value="1/2">Thùng loại 1/2</option>
                            <option value="1/4">Thùng loại 1/4</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Tháng Phát Hành</label>
                        <input type="text" name="issue_month" id="edit_issue_month" class="form-control" placeholder="vd: 10/2026">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label required">Số Lượng Chỉ Thị (Target)</label>
                        <input type="number" name="target_qty" id="edit_target_qty" class="form-control" min="1" required>
                        <small class="text-muted">Đã in: <strong id="edit_printed_disp">0</strong> con. Số còn lại sẽ tính lại.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required">Quy Cách (con/thùng)</label>
                        <input type="number" name="pack_qty" id="edit_pack_qty" class="form-control" min="1" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Nhà Cung Cấp</label>
                        <input type="text" name="supplier" id="edit_supplier" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Trọng Lượng (Kg/thùng)</label>
                        <input type="number" name="weight_per_box" id="edit_weight" class="form-control" step="0.001">
                    </div>

                    <div class="col-12">
                        <label class="form-label">Ghi Chú</label>
                        <input type="text" name="note" id="edit_note" class="form-control" placeholder="Ghi chú điều chỉnh...">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal_edit_order')">Hủy Bỏ</button>
                <button type="submit" class="btn btn-primary fw-bold">💾 Lưu Thay Đổi</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- =======================================================
     MODAL 5: THÊM MỚI CHỈ THỊ THỦ CÔNG
     ======================================================= -->
<?php if (canManageDirectives()): ?>
<div id="modal_add_order" class="modal-overlay">
    <div class="modal-dialog">
        <form method="POST" action="orders.php" id="form_add_order">
            <input type="hidden" name="action" value="create_order">
            <div class="modal-header bg-light">
                <span class="modal-title fw-bold text-dark">➕ Thêm Mới Chỉ Thị Sản Xuất</span>
                <button type="button" class="modal-close" onclick="closeModal('modal_add_order')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label required">Mã Phiếu Chỉ Thị</label>
                        <input type="text" name="slip_code" class="form-control" placeholder="vd: PL-2610-18" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required">Mã Chỉ Thị (Lot No)</label>
                        <input type="text" name="order_code" class="form-control" placeholder="vd: LOT-F618" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label required">Mã Sản Phẩm</label>
                        <input type="text" name="product_code" id="add_product_code" list="product_specs_datalist" class="form-control" placeholder="Chọn hoặc nhập mã SP..." required>
                        <datalist id="product_specs_datalist">
                            <?php foreach ($allSpecs as $sp): ?>
                                <option value="<?= htmlspecialchars($sp['product_code']) ?>">
                                    <?= htmlspecialchars($sp['product_code']) ?> (Thùng loại <?= $sp['box_type'] ?? '1/2' ?>: <?= $sp['pack_qty'] ?> con/thùng)
                                </option>
                            <?php endforeach; ?>
                        </datalist>
                        <small class="text-muted">Tự động điền Quy cách khi chọn mã từ danh mục Specs.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required">Phân Loại Thùng</label>
                        <select name="box_type" id="add_box_type" class="form-select" required>
                            <option value="1/2">Thùng loại 1/2</option>
                            <option value="1/4">Thùng loại 1/4</option>
                        </select>
                        <small class="text-muted">Hệ thống tự động tra quy cách theo Loại thùng.</small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Tháng Phát Hành</label>
                        <input type="text" name="issue_month" class="form-control" value="<?= date('m/Y') ?>" placeholder="vd: 10/2026">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required">Số Lượng Chỉ Thị (Target)</label>
                        <input type="number" name="target_qty" class="form-control"  min="1" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required">Quy Cách (con/thùng)</label>
                        <input type="number" name="pack_qty" id="add_pack_qty" class="form-control"  min="1" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Nhà Cung Cấp</label>
                        <input type="text" name="supplier" id="add_supplier" class="form-control" >
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Trọng Lượng (Kg/thùng)</label>
                        <input type="number" name="weight_per_box" id="add_weight" class="form-control" step="0.001" placeholder="2.500">
                    </div>

                    <div class="col-12">
                        <label class="form-label">Ghi Chú</label>
                        <input type="text" name="note" class="form-control" placeholder="Ghi chú...">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal_add_order')">Hủy</button>
                <button type="submit" class="btn btn-primary fw-bold">💾 Lưu Chỉ Thị</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- =======================================================
     MODAL 6: IMPORT CSV (Editor & Admin)
     ======================================================= -->
<?php if (canManageDirectives()): ?>
<div id="modal_import_excel" class="modal-overlay">
    <div class="modal-dialog">
        <form method="POST" action="orders.php" id="form_import_orders" enctype="multipart/form-data">
            <input type="hidden" name="action" value="import_excel">
            <div class="modal-header bg-light">
                <span class="modal-title fw-bold text-dark">📂 Import Chỉ Thị Từ File CSV</span>
                <button type="button" class="modal-close" onclick="closeModal('modal_import_excel')">&times;</button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-3">
                    Hệ thống đọc trực tiếp file <strong>CSV (Comma Delimited UTF-8)</strong> mà không cần thư viện ngoài, tối ưu 100% cho mạng nội bộ.
                </p>

                <div class="alert alert-info py-2 px-3 mb-3 small">
                    <strong>Thứ tự các cột trong file CSV mẫu:</strong><br>
                    <code>Mã phiếu, Mã chỉ thị, Mã sản phẩm, Tháng phát hành, Số lượng chỉ thị, Ghi chú</code><br>
                    <span class="text-muted mt-1 d-block">
                        * Ghi chú: Cột <strong>Nhà cung cấp</strong> và <strong>Quy cách</strong> đã được loại bỏ khỏi file nạp và sẽ <strong>tự động lấy từ bảng Danh mục Specs</strong> theo Mã sản phẩm.
                    </span>
                </div>

                <div class="mb-3">
                    <a href="export.php?type=sample_orders" class="btn btn-sm btn-outline-primary">
                        📥 Tải File Mẫu (sample_orders.csv)
                    </a>
                </div>

                <div class="mb-3">
                    <label class="form-label required fw-bold">Chọn file CSV</label>
                    <input type="file" name="file_upload" class="form-control" accept=".csv, .txt" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal_import_excel')">Đóng</button>
                <button type="submit" class="btn btn-primary fw-bold">Bắt Đầu Import</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- =======================================================
     MODAL 7: XÁC NHẬN TẠM DỪNG / TIẾP TỤC IN
     ======================================================= -->
<div id="modal_confirm_toggle" class="modal-overlay">
    <div class="modal-dialog" style="max-width:480px;">
        <form method="POST" action="orders.php" id="form_toggle_order">
            <input type="hidden" name="action" value="toggle_pause">
            <input type="hidden" name="order_id" id="toggle_order_id">
            
            <div class="modal-header bg-light" id="toggle_modal_header">
                <span class="modal-title fw-bold" id="toggle_modal_title">Xác Nhận Thao Tác</span>
                <button type="button" class="modal-close" onclick="closeModal('modal_confirm_toggle')">&times;</button>
            </div>
            <div class="modal-body">
                <div id="toggle_modal_message" class="mb-0">
                    Nội dung xác nhận...
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal_confirm_toggle')">Đóng</button>
                <button type="submit" id="toggle_submit_btn" class="btn btn-warning fw-bold">Xác Nhận</button>
            </div>
        </form>
    </div>
</div>

<!-- =======================================================
     MODAL 8: XÁC NHẬN XÓA CHỈ THỊ SẢN XUẤT (CHỈ DÀNH CHO ADMIN)
     ======================================================= -->
<?php if (isAdmin()): ?>
<div id="modal_confirm_delete_order" class="modal-overlay">
    <div class="modal-dialog" style="max-width: 480px;">
        <form method="POST" action="orders.php" id="form_delete_order">
            <input type="hidden" name="action" value="delete_order">
            <input type="hidden" name="order_id" id="delete_order_id">
            
            <div class="modal-header bg-danger text-white">
                <span class="modal-title fw-bold">🗑️ Xác Nhận Xóa Chỉ Thị Sản Xuất (Admin)</span>
                <button type="button" class="modal-close text-white" onclick="closeModal('modal_confirm_delete_order')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger mb-3 py-2 px-3">
                    <strong>⚠️ CẢNH BÁO NGUY HIỂM:</strong><br>
                    Bạn có chắc chắn muốn <strong>XÓA VĨNH VIỄN</strong> chỉ thị sau khỏi cơ sở dữ liệu?
                </div>

                <table class="table table-bordered small mb-3">
                    <tr>
                        <td class="bg-light fw-bold" style="width: 42%;">Mã phiếu chỉ thị:</td>
                        <td id="del_disp_slip" class="fw-bold text-primary">-</td>
                    </tr>
                    <tr>
                        <td class="bg-light fw-bold">Mã chỉ thị (Lot No):</td>
                        <td id="del_disp_order" class="fw-bold text-dark">-</td>
                    </tr>
                </table>

                <div class="text-muted small">
                    * Lưu ý: Thao tác này sẽ xóa toàn bộ lịch sử in và tem thùng liên quan đến chỉ thị này. Thao tác không thể khôi phục!
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal_confirm_delete_order')">Hủy Bỏ</button>
                <button type="submit" id="btn_confirm_delete_submit" class="btn btn-danger fw-bold">
                    🗑️ Xác Nhận Xóa Vĩnh Viễn
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- =======================================================
     MODAL 9: CẤU HÌNH MÁY IN TEM SỬ DỤNG MẶC ĐỊNH
     ======================================================= -->
<div id="modal_printer_config" class="modal-overlay">
    <div class="modal-dialog" style="max-width: 520px;">
        <div class="modal-header bg-dark text-white">
            <span class="modal-title fw-bold">🖨️ Cấu Hình Máy In Tem Sử Dụng Mặc Định</span>
            <button type="button" class="modal-close text-white" onclick="closeModal('modal_printer_config')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="alert alert-info py-2 px-3 mb-3 small">
                Hệ thống tự động lọc và chỉ hiển thị danh sách các máy in <strong>đang kết nối thực tế</strong> với máy tính hiện tại.
                Lựa chọn của bạn sẽ được lưu làm <strong>mặc định</strong> cho tất cả các lần in sau.
            </div>

            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label fw-bold mb-0">Chọn Máy In Tem Mặc Định:</label>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="promptAddClientCustomPrinter()">
                            ➕ Thêm máy in
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeActiveSavedPrinter()">
                            🗑️ Xóa máy in đã lưu
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-warning" onclick="resetAllPrinterConfig()">
                            ⚙️ Đặt lại cấu hình
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="refreshConnectedPrinters(true, true)">
                            🔄 Quét lại máy in
                        </button>
                    </div>
                </div>
                <select id="modal_printer_select" class="form-select form-select-lg fw-bold border-primary">
                    <option value="Browser Print">⏳ Đang kiểm tra dịch vụ máy in trên máy tính hiện tại...</option>
                </select>
                <div id="printer_config_feedback" class="mt-2 small fw-bold"></div>

                <!-- THÔNG BÁO THÂN THIỆN KHI KHÔNG TÌM THẤY DỊCH VỤ IN CỤC BỘ TRONG MODAL -->
                <div id="modal_no_service_panel" class="alert alert-warning border-warning p-2 mt-2 mb-0" style="display:none; background:#fffbeb; border-radius:8px;">
                    <div class="fw-bold text-warning-emphasis mb-1" style="font-size:0.9rem;">
                        ⚠️ Không phát hiện dịch vụ máy in cục bộ trên máy tính này
                    </div>
                    <div class="small text-dark mb-2" style="line-height:1.45;">
                        Không phát hiện dịch vụ máy in cục bộ trên máy tính này.<br>
                        Bạn vẫn có thể sử dụng chức năng In Qua Trình Duyệt.<br>
                        Để sử dụng In Trực Tiếp, vui lòng cài đặt phần mềm hỗ trợ in.
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-sm btn-outline-dark fw-bold" onclick="openPrinterSetupGuideModal()">
                            📖 Hướng Dẫn Cài Đặt
                        </button>
                        <button type="button" class="btn btn-sm btn-primary fw-bold" onclick="activateBrowserPrintFallback(true); closeModal('modal_printer_config');">
                            🖨️ In Qua Trình Duyệt
                        </button>
                    </div>
                </div>
            </div>

            <!-- CHỌN KHỔ TEM MẶC ĐỊNH -->
            <div class="mb-3">
                <label class="form-label fw-bold mb-1">📐 Khổ Tem In Mặc Định:</label>
                <select id="modal_label_size_select" class="form-select form-select-lg fw-bold border-primary">
                    <option value="65x30" <?= ($defaultLabelSize === '65x30') ? 'selected' : '' ?>>Tem nhỏ (65 × 30 mm - Ngang) - Mặc định chuẩn</option>
                    <option value="110x80" <?= ($defaultLabelSize === '110x80') ? 'selected' : '' ?>>Tem lớn (80 × 100 / 100 × 80 / 110 × 80 mm - Ngang)</option>
                </select>
                <small class="text-muted d-block mt-1">Cấu hình khổ giấy mặc định khi sinh tem và gửi lệnh in.</small>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('modal_printer_config')">Đóng</button>
            <button type="button" class="btn btn-primary fw-bold" id="btn_save_printer_config" onclick="savePrinterFromModal()">
                💾 Lưu Làm Máy In Mặc Định
            </button>
        </div>
    </div>
</div>

<!-- =======================================================
     MODAL 10: HƯỚNG DẪN CÀI ĐẶT DỊCH VỤ MÁY IN CỤC BỘ TRÊN CLIENT PC
     ======================================================= -->
<div id="modal_printer_setup_guide" class="modal-overlay">
    <div class="modal-dialog" style="max-width: 600px;">
        <div class="modal-header bg-primary text-white">
            <span class="modal-title fw-bold">📖 Hướng Dẫn Cài Đặt Dịch Vụ Hỗ Trợ In Cục Bộ</span>
            <button type="button" class="modal-close text-white" onclick="closeModal('modal_printer_setup_guide')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="alert alert-info py-2 px-3 mb-3 small">
                Để sử dụng tính năng <strong>In Trực Tiếp (Silent / Direct Print)</strong> không qua hộp thoại trình duyệt trên máy tính trạm, vui lòng thực hiện theo một trong các cách dưới đây:
            </div>

            <div class="mb-3 p-3 border rounded bg-light">
                <div class="fw-bold text-primary mb-1">Cách 1: Cài đặt Zebra Browser Print / QZ Tray (Khuyên dùng cho máy in Zebra/ZDesigner)</div>
                <ol class="small mb-0 ps-3" style="line-height: 1.55;">
                    <li>Cắm cáp USB hoặc kết nối mạng LAN cho máy in tem (vd: <strong>ZDesigner ZD621</strong>, <strong>Zebra GK420D</strong>, <strong>80x110</strong>, <strong>65x30</strong>) và cài đặt Driver máy in trên Windows.</li>
                    <li>Cài đặt và khởi chạy phần mềm <strong>Zebra Browser Print</strong> (chạy ngầm ở cổng <code>127.0.0.1:9100</code>) hoặc <strong>QZ Tray</strong> trên máy tính này.</li>
                    <li>Sau khi bật phần mềm, nhấn nút <strong>"🔍 Kiểm Tra Dịch Vụ In Cục Bộ"</strong> bên dưới để hệ thống nhận diện danh sách máy in.</li>
                </ol>
            </div>

            <div class="mb-3 p-3 border rounded bg-light">
                <div class="fw-bold text-success mb-1">Cách 2: Sử dụng trực tiếp chức năng "In Qua Trình Duyệt" (Không cần cài phần mềm phụ trợ)</div>
                <ul class="small mb-0 ps-3" style="line-height: 1.55;">
                    <li>Nếu máy tính chưa cài phần mềm hỗ trợ in cục bộ, hệ thống <strong>tự động chuyển sang chế độ In Qua Trình Duyệt</strong>.</li>
                    <li>Khi nhấn <strong>"📥 Xác Nhận Nhập &amp; In Tem Thùng"</strong>, hộp thoại in chuẩn của trình duyệt sẽ tự động mở ra với đúng khổ tem (<code>65×30mm</code> hoặc <code>80×100 / 100×80mm</code>). Bạn chỉ cần chọn máy in của máy tính và nhấn <strong>Print (In)</strong>.</li>
                </ul>
            </div>

            <div id="setup_guide_probe_feedback" class="small fw-bold mt-2"></div>
        </div>
        <div class="modal-footer d-flex justify-content-between">
            <button type="button" class="btn btn-outline-primary fw-bold" id="btn_probe_local_agent" onclick="testLocalhostPrintServiceFromGuide()">
                🔍 Kiểm Tra Dịch Vụ In Cục Bộ
            </button>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-primary fw-bold" onclick="activateBrowserPrintFallback(true); closeModal('modal_printer_setup_guide');">
                    🖨️ Dùng Chế Độ In Qua Trình Duyệt
                </button>
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal_printer_setup_guide')">Đóng</button>
            </div>
        </div>
    </div>
</div>

<script>
// =======================================================
// DỮ LIỆU ĐỒNG BỘ TOÀN CỤC & BIẾN TRẠNG THÁI
// =======================================================
const ordersDataMap = <?= json_encode(array_column($orders, null, 'id'), JSON_UNESCAPED_UNICODE) ?: '{}' ?>;
const allSpecsList  = <?= json_encode($allSpecs, JSON_UNESCAPED_UNICODE) ?: '[]' ?>;
const allSpecsMapByKey = {};
const specsByProduct   = {};

allSpecsList.forEach(item => {
    const boxType = item.box_type || '1';
    const key = item.product_code + '___' + boxType;
    allSpecsMapByKey[key] = item;
    if (!specsByProduct[item.product_code]) {
        specsByProduct[item.product_code] = [];
    }
    specsByProduct[item.product_code].push(item);
});

// Tương thích ngược allSpecsMap[product_code]
const allSpecsMap = {};
allSpecsList.forEach(item => {
    if (!allSpecsMap[item.product_code] || item.box_type === '1') {
        allSpecsMap[item.product_code] = item;
    }
});

let currentOrder           = null;
let currentCalculation     = null;
let verifiedAdminMsnv      = null;
let verifiedAdminPass      = null;
let verifiedOverrideReason = null;

// =======================================================
// HÀM MỞ / ĐÓNG MODAL TIỆN ÍCH CHUẨN XÁC, KHÔNG BAO GIỜ BỊ ĐƠ
// =======================================================
function openModal(id) { 
    const el = document.getElementById(id);
    if (!el) return;

    // VÔ HIỆU HÓA FOCUS TRAP CỦA BOOTSTRAP OFFCANVAS (TRÁNH CƯỚP FOCUS KHỎI MODAL)
    const offcanvasEl = document.getElementById('offcanvasPrint');
    if (offcanvasEl && typeof bootstrap !== 'undefined' && bootstrap.Offcanvas) {
        const bsOffcanvas = bootstrap.Offcanvas.getInstance(offcanvasEl);
        if (bsOffcanvas && bsOffcanvas._focustrap) {
            bsOffcanvas._focustrap.deactivate();
        }
    }

    el.classList.add('active');
    document.body.classList.add('modal-open');

    // Tự động focus vào ô nhập liệu
    setTimeout(() => {
        if (id === 'modal_admin_approval') {
            const msnvInput = document.getElementById('input_admin_msnv');
            if (msnvInput) {
                msnvInput.disabled = false;
                msnvInput.readOnly = false;
                msnvInput.focus();
                msnvInput.select();
            }
        } else {
            const input = el.querySelector('input:not([type="hidden"]):not([readonly]):not([disabled]), select, textarea, button.btn-primary, button.btn-danger');
            if (input) input.focus();
        }
    }, 100);
}

function closeModal(id, forceClose = false) { 
    const el = document.getElementById(id);
    if (!el) return;

    el.classList.remove('active');
    if (!document.querySelector('.modal-overlay.active')) {
        document.body.classList.remove('modal-open');
    }
}

// Ngăn sự kiện focusin nổi lên document để Bootstrap FocusTrap không cướp focus của bất kỳ modal nào
document.querySelectorAll('.modal-overlay').forEach(modal => {
    modal.addEventListener('focusin', function(e) {
        e.stopPropagation();
    });
});

// Hỗ trợ phím Escape và click ngoài nền để đóng Modal
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const activeModal = document.querySelector('.modal-overlay.active');
        if (activeModal) closeModal(activeModal.id);
    }
});

document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-overlay') && e.target.classList.contains('active')) {
        closeModal(e.target.id);
    }
});

// Khi Offcanvas mở, lập tức vô hiệu hóa FocusTrap và tự động cập nhật danh sách máy in trên máy tính hiện tại (Client PC)
const offcanvasPrintElement = document.getElementById('offcanvasPrint');
if (offcanvasPrintElement) {
    offcanvasPrintElement.addEventListener('shown.bs.offcanvas', function() {
        if (typeof bootstrap !== 'undefined' && bootstrap.Offcanvas) {
            const inst = bootstrap.Offcanvas.getInstance(offcanvasPrintElement);
            if (inst && inst._focustrap) {
                inst._focustrap.deactivate();
            }
        }
        refreshConnectedPrinters(false);
    });
}

// =======================================================
// CẤU HÌNH & TỰ ĐỘNG HÓA MÁY IN TEM TRÊN MÁY TÍNH NGƯỜI DÙNG (CLIENT PC)
// =======================================================
const currentUserId = <?= (int)$currentUserId ?>;
let serverUserDefaultPrinter = <?= json_encode($defaultPrinter, JSON_UNESCAPED_UNICODE) ?: '""' ?>;
let clientPrintersRegistry = []; // Stores discovered printers on the Client PC: { name, default, source, device }
let isLocalPrintServiceProbed = false;
let isLocalPrintServiceAvailable = null;
let isProbingPrinters = false;

// Check whether current page is running directly on localhost/local server machine
function isCurrentPageOnLocalhost() {
    const h = window.location.hostname || '';
    return h === 'localhost' || h === '127.0.0.1' || h === '::1';
}

const NO_PRINTER_MESSAGE = 'Không tìm thấy máy in trên máy tính hiện tại. Vui lòng kiểm tra dịch vụ in hoặc kết nối máy in.';

// Filter out Windows virtual software printers on client side
function isVirtualPrinterNameJS(name) {
    const n = String(name || '').trim().toLowerCase();
    if (!n) return true;
    const patterns = [
        'microsoft print to pdf',
        'microsoft xps document writer',
        'onenote',
        'send to onenote',
        'fax',
        'adobe pdf',
        'foxit reader pdf printer',
        'pdfcreator',
        'cute writer',
        'cutepdf',
        'novapdf',
        'primopdf',
        'do pdf',
        'dopdf',
        'bullzip pdf'
    ];
    return patterns.some(p => n.includes(p));
}

function getSavedUserDefaultPrinter() {
    try {
        // 1. Ưu tiên máy in lưu theo User trên máy tính hiện tại
        const userScoped = localStorage.getItem('printbox_default_printer_u_' + currentUserId);
        if (userScoped && !isVirtualPrinterNameJS(userScoped)) {
            return userScoped;
        }
        // 2. Tiếp theo là máy in lưu theo thiết bị (Browser) trên máy tính này
        const browserScoped = localStorage.getItem('printbox_client_default_printer');
        if (browserScoped && !isVirtualPrinterNameJS(browserScoped)) {
            return browserScoped;
        }
        const legacyScoped = localStorage.getItem('printbox_default_printer');
        if (legacyScoped && !isVirtualPrinterNameJS(legacyScoped)) {
            return legacyScoped;
        }
        // 3. Chỉ lấy máy in mặc định server nếu đang ở chính máy server (localhost)
        if (isCurrentPageOnLocalhost() && serverUserDefaultPrinter && !isVirtualPrinterNameJS(serverUserDefaultPrinter)) {
            return serverUserDefaultPrinter;
        }
    } catch (e) {}
    return '';
}

function saveUserDefaultPrinterLocal(printerName) {
    if (!printerName) return;
    try {
        // Lưu theo User trên thiết bị này
        localStorage.setItem('printbox_default_printer_u_' + currentUserId, printerName);
        // Lưu theo thiết bị (Browser) trên máy tính này
        localStorage.setItem('printbox_client_default_printer', printerName);
        localStorage.setItem('printbox_default_printer', printerName);
        localStorage.setItem('mes_default_printer', printerName);
    } catch (e) {}
}

// -------------------------------------------------------------
// QUẢN LÝ DANH SÁCH MÁY IN CỤC BỘ TRÊN MÁY TÍNH CLIENT (LOCALSTORAGE)
// -------------------------------------------------------------
function getClientCustomPrinters() {
    try {
        const raw = localStorage.getItem('printbox_client_custom_printers');
        if (raw) {
            const list = JSON.parse(raw);
            if (Array.isArray(list)) {
                return list
                    .map(item => (typeof item === 'string') ? { name: item, default: false, source: 'client_custom' } : item)
                    .filter(p => p && p.name && !isVirtualPrinterNameJS(p.name));
            }
        }
    } catch (e) {}
    return [];
}

function saveClientCustomPrinters(list) {
    try {
        localStorage.setItem('printbox_client_custom_printers', JSON.stringify(list));
    } catch (e) {}
}

function removeActiveSavedPrinter() {
    const ocSel = document.getElementById('offcanvas_printer_select');
    const mdSel = document.getElementById('modal_printer_select');
    const current = (ocSel ? ocSel.value : '') || (mdSel ? mdSel.value : '') || getSavedUserDefaultPrinter();
    if (!current || current === 'Browser Print') {
        alert('Vui lòng chọn máy in bạn muốn xóa khỏi danh sách cấu hình!');
        return;
    }
    if (!confirm(`Bạn có chắc chắn muốn xóa cấu hình máy in "${current}" khỏi máy tính này không?`)) {
        return;
    }
    // 1. Xóa khỏi danh sách máy in tùy chỉnh
    const customList = getClientCustomPrinters().filter(p => p.name.toLowerCase() !== current.toLowerCase());
    saveClientCustomPrinters(customList);

    // 2. Xóa khỏi cấu hình máy in mặc định
    try {
        localStorage.removeItem('printbox_default_printer_u_' + currentUserId);
        localStorage.removeItem('printbox_client_default_printer');
        localStorage.removeItem('printbox_default_printer');
        localStorage.removeItem('mes_default_printer');
    } catch(e) {}

    onDefaultPrinterChange('Browser Print');
    refreshConnectedPrinters(true, true);
    setPrinterStatusMessage(`✓ Đã xóa cấu hình máy in "${current}" khỏi máy tính này!`, 'success');
}

function resetAllPrinterConfig() {
    if (!confirm("Bạn có chắc chắn muốn ĐẶT LẠI TOÀN BỘ cấu hình máy in trên máy tính này không?\n\n- Toàn bộ máy in tùy chỉnh đã thêm sẽ được xóa.\n- Cấu hình máy in mặc định sẽ được đặt lại.\n- Hệ thống sẽ quét lại từ đầu.")) {
        return;
    }
    try {
        localStorage.removeItem('printbox_default_printer_u_' + currentUserId);
        localStorage.removeItem('printbox_client_default_printer');
        localStorage.removeItem('printbox_default_printer');
        localStorage.removeItem('printbox_client_custom_printers');
        localStorage.removeItem('mes_default_printer');
    } catch(e) {}

    onDefaultPrinterChange('Browser Print');
    refreshConnectedPrinters(true, true);
    setPrinterStatusMessage('✓ Đã đặt lại toàn bộ cấu hình máy in trên máy tính này!', 'success');
}

function logPrintOperation({
    user = (typeof currentUserName !== 'undefined' ? currentUserName : '') || 'User #' + currentUserId,
    printer = '',
    labelSize = '',
    printMode = '',
    resultStatus = '',
    details = '',
    labelCount = 0
}) {
    const timeStr = new Date().toLocaleString('vi-VN');
    const badgeColor = resultStatus === 'DIRECT_SUCCESS' ? '#16a34a' :
                       resultStatus === 'BROWSER_DIALOG_FALLBACK' ? '#d97706' : '#dc2626';
    const statusText = resultStatus === 'DIRECT_SUCCESS' ? '✅ In trực tiếp thành công' :
                       resultStatus === 'BROWSER_DIALOG_FALLBACK' ? '⚠️ Chuyển sang Print Dialog của trình duyệt' :
                       resultStatus === 'PRINTER_NOT_FOUND' ? '❌ Không tìm thấy máy in' : '❌ Lỗi gửi lệnh in';

    console.group(`%c[NHẬT KÝ IN TEM / PRINT LOG] - ${statusText} - ${timeStr}`, `color:${badgeColor};font-weight:bold;`);
    console.log('👤 Người dùng:', user);
    console.log('🖨️ Máy in được chọn:', printer || '(Chưa chọn máy in)');
    console.log('📐 Khổ tem:', labelSize || 'Mặc định');
    console.log('⚙️ Chế độ in:', printMode || 'direct');
    console.log('⏰ Thời gian in:', timeStr);
    console.log('📦 Số lượng tem:', labelCount);
    console.log('📊 Kết quả gửi lệnh in:', statusText);
    if (details) console.log('📝 Chi tiết / Lý do:', details);
    console.groupEnd();
}

function promptAddClientCustomPrinter() {
    const printerName = prompt('Nhập tên máy in đã cài đặt trên máy tính này (Ví dụ: Zebra ZD421, Godex G500, XPrinter XP-350B, Brother QL-820NWB):');
    if (!printerName || !printerName.trim()) return;
    const cleanName = printerName.trim();
    if (isVirtualPrinterNameJS(cleanName)) {
        alert('Vui lòng nhập tên máy in tem vật lý, không nhập tên máy in ảo (PDF, XPS...).');
        return;
    }
    const current = getClientCustomPrinters();
    const existingIndex = current.findIndex(p => p.name.toLowerCase() === cleanName.toLowerCase());
    if (existingIndex < 0) {
        current.push({ name: cleanName, default: true, source: 'client_custom' });
        saveClientCustomPrinters(current);
    }
    saveUserDefaultPrinterLocal(cleanName);
    refreshConnectedPrinters(true, true);
}

function setPrinterStatusMessage(message, type = 'info') {
    const ocMsg = document.getElementById('offcanvas_printer_status_msg');
    const mdMsg = document.getElementById('printer_config_feedback');
    const colorMap = {
        success: '#15803d',
        warning: '#b45309',
        danger:  '#b91c1c',
        info:    '#2563eb'
    };
    const color = colorMap[type] || '#2563eb';

    if (ocMsg) {
        if (message && (type === 'danger' || type === 'warning')) {
            ocMsg.style.display = 'block';
            ocMsg.style.color = color;
            ocMsg.innerText = message;
        } else {
            ocMsg.style.display = 'none';
            ocMsg.innerText = '';
        }
    }
    if (mdMsg) {
        mdMsg.style.color = color;
        mdMsg.innerText = message || '';
    }
}

function syncPrinterSelectUI(printerName) {
    const ocSelect = document.getElementById('offcanvas_printer_select');
    if (ocSelect) {
        for (let i = 0; i < ocSelect.options.length; i++) {
            if (ocSelect.options[i].value === printerName) {
                ocSelect.value = printerName;
                break;
            }
        }
    }
    const mdSelect = document.getElementById('modal_printer_select');
    if (mdSelect) {
        for (let i = 0; i < mdSelect.options.length; i++) {
            if (mdSelect.options[i].value === printerName) {
                mdSelect.value = printerName;
                break;
            }
        }
    }
    const headerLabel = document.getElementById('header_default_printer_label');
    if (headerLabel) headerLabel.innerText = printerName || 'Chưa chọn';
    const resPrinterEl = document.getElementById('res_printer_name');
    if (resPrinterEl) resPrinterEl.innerText = printerName || 'Chưa chọn máy in';
}

function detectLabelSizeFromPrinterName(printerName) {
    const name = String(printerName || '').toLowerCase();
    if (/(80\s*x\s*110|110\s*x\s*80|80\s*x\s*100|100\s*x\s*80)/.test(name)) {
        return '110x80';
    }
    if (/65\s*x\s*30/.test(name)) {
        return '65x30';
    }
    return null;
}

function onDefaultPrinterChange(printerName, showFeedback = false) {
    if (!printerName) {
        syncPrinterSelectUI('');
        return;
    }
    saveUserDefaultPrinterLocal(printerName);
    syncPrinterSelectUI(printerName);
    setPrinterStatusMessage('', 'info');

    // Auto-switch label size when printer name explicitly indicates size
    const matchedSize = detectLabelSizeFromPrinterName(printerName);
    if (matchedSize && matchedSize !== activeLabelSize) {
        onLabelSizeChange(matchedSize, false, false);
    }

    const fd = new FormData();
    fd.append('action', 'save_default_printer');
    fd.append('printer_name', printerName);

    fetch('orders.php', {
        method: 'POST',
        body: fd
    })
    .then(res => res.json())
    .then(data => {
        if (showFeedback) {
            setPrinterStatusMessage(data.success ? ('✓ ' + data.message) : ('✗ ' + data.message), data.success ? 'success' : 'danger');
        }
    })
    .catch(() => {});
}

function savePrinterFromModal() {
    const mdSelect = document.getElementById('modal_printer_select');
    if (!mdSelect || !mdSelect.value) {
        setPrinterStatusMessage('Vui lòng chọn máy in tem hợp lệ trước khi lưu!', 'danger');
        return;
    }
    onDefaultPrinterChange(mdSelect.value, true);
    const sizeSelect = document.getElementById('modal_label_size_select');
    if (sizeSelect && sizeSelect.value) {
        onLabelSizeChange(sizeSelect.value, false, true);
    }
    setTimeout(() => {
        closeModal('modal_printer_config');
    }, 350);
}

// 1. Probe Zebra Browser Print on Client PC (127.0.0.1:9100 / 9101)
async function probeZebraBrowserPrintClient(forceRefresh = false) {
    if (!forceRefresh && sessionStorage.getItem('zebra_bp_unavailable') === 'true') {
        return [];
    }
    const isHttps = (window.location.protocol === 'https:');
    const endpoint = isHttps ? 'https://127.0.0.1:9101/available' : 'http://127.0.0.1:9100/available';
    try {
        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), 400);
        const res = await fetch(endpoint, { method: 'GET', mode: 'cors', signal: ctrl.signal });
        clearTimeout(timer);
        if (!res.ok) {
            sessionStorage.setItem('zebra_bp_unavailable', 'true');
            return [];
        }
        const data = await res.json();
        sessionStorage.removeItem('zebra_bp_unavailable');
        const list = [];
        if (data && Array.isArray(data.printer)) {
            data.printer.forEach(p => {
                if (p && p.name && !isVirtualPrinterNameJS(p.name)) {
                    list.push({ name: String(p.name).trim(), default: false, source: 'zebra_browser_print', device: p });
                }
            });
        } else if (data && data.device && data.device.name && !isVirtualPrinterNameJS(data.device.name)) {
            list.push({ name: String(data.device.name).trim(), default: true, source: 'zebra_browser_print', device: data.device });
        }
        return list;
    } catch (e) {
        sessionStorage.setItem('zebra_bp_unavailable', 'true');
        return [];
    }
}

// 2. Probe QZ Tray on Client PC (ws://127.0.0.1:8182 / wss://127.0.0.1:8181)
async function probeQzTrayClient() {
    if (typeof window.qz !== 'undefined' && window.qz && window.qz.websocket) {
        try {
            if (!window.qz.websocket.isActive()) {
                await window.qz.websocket.connect({ retries: 0, delay: 0 });
            }
            const found = await window.qz.printers.find();
            if (Array.isArray(found)) {
                return found
                    .map(n => String(n || '').trim())
                    .filter(n => n && !isVirtualPrinterNameJS(n))
                    .map(n => ({ name: n, default: false, source: 'qz_tray' }));
            }
        } catch (e) {}
    }
    return [];
}

// 3. Probe WebUSB connected printers on Client PC
async function probeWebUsbPrintersClient() {
    if (typeof navigator !== 'undefined' && navigator.usb && typeof navigator.usb.getDevices === 'function') {
        try {
            const devices = await navigator.usb.getDevices();
            if (Array.isArray(devices) && devices.length > 0) {
                return devices
                    .filter(d => d && (d.productName || d.manufacturerName))
                    .map(d => ({
                        name: ((d.manufacturerName ? d.manufacturerName + ' ' : '') + (d.productName || 'USB Printer')).trim(),
                        default: false,
                        source: 'webusb',
                        device: d
                    }))
                    .filter(p => !isVirtualPrinterNameJS(p.name));
            }
        } catch (e) {}
    }
    return [];
}

// 4. Discover all printers strictly belonging to the Client PC
async function discoverClientPrinters(forceRefresh = false) {
    const mergedMap = new Map();
    const addPrinters = (list, srcLabel) => {
        if (!Array.isArray(list)) return;
        list.forEach(item => {
            if (!item || !item.name) return;
            const name = String(item.name).trim();
            if (!name || isVirtualPrinterNameJS(name) || name === 'Browser Print') return;
            const key = name.toLowerCase();
            if (!mergedMap.has(key)) {
                mergedMap.set(key, item);
            } else {
                const existing = mergedMap.get(key);
                if (item.default) existing.default = true;
                if (item.is_thermal) existing.is_thermal = true;
                if (item.is_network) existing.is_network = true;
                if (item.source && !existing.source) existing.source = item.source;
            }
        });
    };

    let zebraList = [];
    let qzList = [];
    let usbList = [];
    let customList = [];
    let serverList = [];

    // Luôn ưu tiên quét các dịch vụ và thiết bị trực tiếp trên Client PC
    const probeTasks = [
        probeZebraBrowserPrintClient(forceRefresh).then(res => { zebraList = res; addPrinters(res, 'zebra'); }).catch(() => {}),
        probeQzTrayClient().then(res => { qzList = res; addPrinters(res, 'qz'); }).catch(() => {}),
        probeWebUsbPrintersClient().then(res => { usbList = res; addPrinters(res, 'usb'); }).catch(() => {}),
        (async () => {
            customList = getClientCustomPrinters();
            addPrinters(customList, 'client_custom');
        })()
    ];

    // CHỈ truy vấn Windows Spooler từ Server khi chính máy đang mở Web là Localhost (máy Server đồng thời là máy trạm)
    // Đối với các máy Client khác qua mạng LAN: TUYỆT ĐỐI KHÔNG lấy máy in của Server!
    if (isCurrentPageOnLocalhost()) {
        probeTasks.push((async () => {
            try {
                const fd = new FormData();
                fd.append('action', 'refresh_printers');
                const res = await fetch('orders.php', { method: 'POST', body: fd });
                if (res.ok) {
                    const data = await res.json();
                    if (data && data.success && Array.isArray(data.printers)) {
                        serverList = data.printers;
                        addPrinters(data.printers.map(p => ({
                            name: (typeof p === 'object' && p !== null) ? String(p.name || '').trim() : String(p || '').trim(),
                            default: Boolean(typeof p === 'object' && p !== null && p.default),
                            port: (typeof p === 'object' && p !== null) ? (p.port || '') : '',
                            driver: (typeof p === 'object' && p !== null) ? (p.driver || '') : '',
                            is_thermal: Boolean(typeof p === 'object' && p !== null && p.is_thermal),
                            is_network: Boolean(typeof p === 'object' && p !== null && p.is_network),
                            is_shared: Boolean(typeof p === 'object' && p !== null && p.is_shared),
                            source: (typeof p === 'object' && p !== null && p.source) ? p.source : 'windows_spooler'
                        })), 'windows_spooler');
                    }
                }
            } catch (e) {}
        })());
    }

    await Promise.allSettled(probeTasks);

    isLocalPrintServiceProbed = true;
    isLocalPrintServiceAvailable = (mergedMap.size > 0);

    return Array.from(mergedMap.values());
}

async function refreshConnectedPrinters(showUserFeedback = false, forceRefresh = false, retryAttempt = 0) {
    if (isProbingPrinters) return;
    isProbingPrinters = true;

    try {
        if (showUserFeedback) {
            setPrinterStatusMessage('⏳ Đang quét toàn bộ máy in trên máy tính hiện tại...', 'info');
        }

        const discovered = await discoverClientPrinters(forceRefresh);

        // Retry nếu lần đầu chưa tìm thấy
        if (discovered.length === 0 && retryAttempt < 2) {
            isProbingPrinters = false;
            await new Promise(r => setTimeout(r, 400));
            return refreshConnectedPrinters(showUserFeedback, true, retryAttempt + 1);
        }

        const savedPrinter = getSavedUserDefaultPrinter();
        let selectedPrinter = '';
        let savedPrinterMissing = false;

        if (discovered.length > 0) {
            clientPrintersRegistry = discovered;
            if (savedPrinter && savedPrinter !== 'Browser Print') {
                const matched = discovered.find(p => p.name.toLowerCase() === savedPrinter.toLowerCase());
                if (matched) {
                    selectedPrinter = matched.name;
                } else {
                    selectedPrinter = '';
                    savedPrinterMissing = true;
                }
            }
            if (!selectedPrinter && !savedPrinterMissing) {
                // Tự động chọn: Máy in mặc định client -> Máy in đầu tiên tìm thấy
                const defP = discovered.find(p => p.default) || discovered[0];
                if (defP) {
                    selectedPrinter = defP.name;
                    saveUserDefaultPrinterLocal(selectedPrinter);
                }
            }
        } else {
            clientPrintersRegistry = [{ name: 'Browser Print', default: true, source: 'browser_print' }];
            selectedPrinter = 'Browser Print';
        }

        const isFallback = (discovered.length === 0);

        // Hiển thị / ẩn cảnh báo thân thiện
        const offPanel = document.getElementById('offcanvas_no_service_panel');
        if (offPanel) offPanel.style.display = isFallback ? 'block' : 'none';
        const modalPanel = document.getElementById('modal_no_service_panel');
        if (modalPanel) modalPanel.style.display = isFallback ? 'block' : 'none';

        ['offcanvas_printer_select', 'modal_printer_select'].forEach(selId => {
            const sel = document.getElementById(selId);
            if (!sel) return;
            sel.innerHTML = '';

            if (isFallback) {
                const opt = document.createElement('option');
                opt.value = 'Browser Print';
                opt.textContent = '🌐 In Qua Trình Duyệt (Sử dụng máy in của máy tính này)';
                opt.selected = true;
                sel.appendChild(opt);
                sel.value = 'Browser Print';
            } else {
                if (savedPrinterMissing || !selectedPrinter) {
                    const promptOpt = document.createElement('option');
                    promptOpt.value = '';
                    promptOpt.textContent = savedPrinterMissing
                        ? `-- Máy in "${savedPrinter}" không còn kết nối, vui lòng chọn lại --`
                        : '-- Chọn máy in tem sử dụng --';
                    promptOpt.selected = true;
                    sel.appendChild(promptOpt);
                }

                discovered.forEach(pObj => {
                    const opt = document.createElement('option');
                    opt.value = pObj.name;
                    let connBadge = '';
                    if (pObj.source === 'zebra' || pObj.source === 'zebra_browser_print') {
                        connBadge = ' [Zebra Local]';
                    } else if (pObj.source === 'qz' || pObj.source === 'qz_tray') {
                        connBadge = ' [Windows/QZ Tray]';
                    } else if (pObj.source === 'webusb' || pObj.source === 'usb') {
                        connBadge = ' [USB Client]';
                    } else if (pObj.source === 'client_custom') {
                        connBadge = ' [Máy in cục bộ]';
                    } else if (pObj.is_network) {
                        connBadge = ` [Mạng LAN${pObj.port ? ': ' + pObj.port : ''}]`;
                    } else if (pObj.is_shared) {
                        connBadge = ' [Shared]';
                    } else {
                        connBadge = ' [Cục bộ]';
                    }

                    if (pObj.is_thermal) connBadge += ' (Tem)';
                    if (pObj.default)    connBadge += ' (Mặc định)';

                    opt.textContent = '🖨️ ' + pObj.name + connBadge;
                    if (selectedPrinter && pObj.name.toLowerCase() === selectedPrinter.toLowerCase()) {
                        opt.selected = true;
                    }
                    sel.appendChild(opt);
                });

                // Luôn có tùy chọn In Qua Trình Duyệt ở cuối
                const browserOpt = document.createElement('option');
                browserOpt.value = 'Browser Print';
                browserOpt.textContent = '🌐 In Qua Trình Duyệt (Sử dụng máy in của máy tính này)';
                if (selectedPrinter === 'Browser Print') browserOpt.selected = true;
                sel.appendChild(browserOpt);
            }
        });

        syncPrinterSelectUI(selectedPrinter);

        // Tự động đồng bộ khổ tem nếu tên máy in có chứa kích thước
        if (selectedPrinter && selectedPrinter !== 'Browser Print') {
            const matchedSize = detectLabelSizeFromPrinterName(selectedPrinter);
            if (matchedSize && matchedSize !== activeLabelSize) {
                onLabelSizeChange(matchedSize, false, false);
            }
        }

        // BỔ SUNG LOG KIỂM TRA CHI TIẾT THEO YÊU CẦU
        const ocSelect = document.getElementById('offcanvas_printer_select');
        const comboboxOptionsList = Array.from(ocSelect ? ocSelect.options : []).map(o => ({
            value: o.value,
            text: o.textContent,
            selected: o.selected
        }));

        console.group('[KIỂM TRA MÁY IN CLIENT / PRINTER INSPECTION]');
        console.log('1. Danh sách lấy từ Client:', discovered.map(p => ({
            name: p.name,
            source: p.source || 'client',
            default: Boolean(p.default),
            is_network: Boolean(p.is_network)
        })));
        console.log('2. Danh sách hiển thị combobox:', comboboxOptionsList);
        console.log('3. Máy in mặc định đang chọn:', selectedPrinter || (savedPrinterMissing ? `(Máy in "${savedPrinter}" không còn kết nối - Yêu cầu chọn lại)` : '(Chưa chọn - vui lòng chọn)'));
        console.groupEnd();

        if (isFallback) {
            setPrinterStatusMessage('', 'info');
        } else if (savedPrinterMissing) {
            setPrinterStatusMessage(`⚠️ Máy in mặc định đã lưu ("${savedPrinter}") không còn tồn tại trên máy tính hiện tại. Vui lòng chọn lại máy in!`, 'warning');
        } else if (showUserFeedback) {
            setPrinterStatusMessage(`✓ Đã tìm thấy ${discovered.length} máy in sẵn sàng trên máy tính này!`, 'success');
        } else {
            setPrinterStatusMessage('', 'info');
        }
    } finally {
        isProbingPrinters = false;
    }
}

// Kích hoạt thủ công chế độ In Qua Trình Duyệt
function activateBrowserPrintFallback(showToast = false) {
    onDefaultPrinterChange('Browser Print', false);
    syncPrinterSelectUI('Browser Print');
    setPrinterStatusMessage('', 'info');
    if (showToast) {
        showDirectPrintToast('Đã chuyển sang chế độ In Qua Trình Duyệt. Bạn có thể in tem bình thường.', true);
    }
}

// Mở modal hướng dẫn cài đặt dịch vụ in
function openPrinterSetupGuideModal() {
    const feedback = document.getElementById('setup_guide_probe_feedback');
    if (feedback) feedback.innerHTML = '';
    openModal('modal_printer_setup_guide');
}

// Kiểm tra lại dịch vụ in từ bên trong modal hướng dẫn
async function testLocalhostPrintServiceFromGuide() {
    const feedback = document.getElementById('setup_guide_probe_feedback');
    const btn = document.getElementById('btn_probe_local_agent');
    if (btn) btn.disabled = true;
    if (feedback) {
        feedback.style.color = '#2563eb';
        feedback.innerText = '⏳ Đang quét kiểm tra lại dịch vụ máy in trên máy tính...';
    }

    try {
        await refreshConnectedPrinters(false, true);
        const physical = clientPrintersRegistry.filter(p => p.name && p.name !== 'Browser Print');
        if (physical.length > 0) {
            if (feedback) {
                feedback.style.color = '#15803d';
                feedback.innerHTML = `✓ Đã phát hiện ${physical.length} máy in cục bộ (${escapeHtml(physical.map(p => p.name).join(', '))})! Bạn có thể sử dụng In Trực Tiếp.`;
            }
        } else {
            if (feedback) {
                feedback.style.color = '#b45309';
                feedback.innerHTML = '⚠️ Chưa phát hiện dịch vụ máy in cục bộ trên máy tính này. Bạn vẫn có thể sử dụng chức năng In Qua Trình Duyệt.';
            }
        }
    } catch (e) {
        if (feedback) {
            feedback.style.color = '#b45309';
            feedback.innerHTML = '⚠️ Không thể kết nối dịch vụ máy in cục bộ. Vui lòng sử dụng In Qua Trình Duyệt.';
        }
    } finally {
        if (btn) btn.disabled = false;
    }
}


// Khởi tạo đồng bộ máy in mặc định từ localStorage nếu khớp danh sách máy in đang kết nối

// =======================================================
// QUẢN LÝ CẤU HÌNH KHỔ TEM IN (65x30MM & 80x100 / 100x80 / 110x80MM)
// =======================================================
let activeLabelSize = '<?= $defaultLabelSize ?>';

function normalizeSizeCode(size) {
    const s = String(size || '').trim().toLowerCase();
    if (s === '110x80' || s === '80x110' || s === '100x80' || s === '80x100') {
        return '110x80';
    }
    return '65x30';
}

function getActiveLabelSize() {
    // Primary source of truth: currently selected dropdown value in UI
    const previewModal = document.getElementById('modal_print_result');
    if (previewModal && previewModal.classList.contains('active')) {
        const prevSel = document.getElementById('preview_label_size_select');
        if (prevSel && prevSel.value) {
            return normalizeSizeCode(prevSel.value);
        }
    }
    const ocSel = document.getElementById('offcanvas_label_size_select');
    if (ocSel && ocSel.value) {
        return normalizeSizeCode(ocSel.value);
    }
    return normalizeSizeCode(activeLabelSize);
}

function applyDynamicPrintPageStyle(size) {
    size = normalizeSizeCode(size);
    let styleEl = document.getElementById('dynamic_print_page_style');
    if (!styleEl) {
        styleEl = document.createElement('style');
        styleEl.id = 'dynamic_print_page_style';
        document.head.appendChild(styleEl);
    }
    if (size === '110x80') {
        styleEl.textContent = `
            @page {
                size: 104mm 80mm !important;
                margin: 0mm !important;
            }
            @media print {
                html, body {
                    width: 104mm !important;
                    height: auto !important;
                    min-height: 0 !important;
                    display: block !important;
                    margin: 0 !important;
                    padding: 0 !important;
                    transform: none !important;
                    zoom: 1 !important;
                    scale: 1 !important;
                }
                #print_standalone_zone {
                    width: 104mm !important;
                    margin: 0 !important;
                    padding: 0 !important;
                    transform: none !important;
                    zoom: 1 !important;
                }
            }
        `;
    } else {
        styleEl.textContent = `
            @page {
                size: 65mm 30mm !important;
                margin: 0mm !important;
            }
            @media print {
                html, body {
                    width: 65mm !important;
                    height: auto !important;
                    min-height: 0 !important;
                    display: block !important;
                    margin: 0 !important;
                    padding: 0 !important;
                    transform: none !important;
                    zoom: 1 !important;
                    scale: 1 !important;
                }
                #print_standalone_zone {
                    width: 65mm !important;
                    margin: 0 !important;
                    padding: 0 !important;
                    transform: none !important;
                    zoom: 1 !important;
                }
            }
        `;
    }
}

function syncLabelSizeUI(size) {
    size = normalizeSizeCode(size);
    const sizeTxt = (size === '110x80') ? '80 × 100 / 100 × 80 mm (Ngang)' : '65mm × 30mm (Ngang)';
    const sizeFullTxt = (size === '110x80') ? 'Tem lớn (80 × 100 / 100 × 80 mm - Ngang)' : 'Tem nhỏ (65 × 30 mm - Ngang)';

    ['offcanvas_label_size_select', 'preview_label_size_select', 'modal_label_size_select'].forEach(id => {
        const el = document.getElementById(id);
        if (el && el.value !== size) {
            el.value = size;
        }
    });

    const cfSizeEl = document.getElementById('cf_label_size');
    if (cfSizeEl) cfSizeEl.innerText = sizeFullTxt;

    const cfNoteEl = document.getElementById('cf_label_size_note');
    if (cfNoteEl) cfNoteEl.innerText = sizeTxt;

    const prevTitle = document.getElementById('modal_print_preview_title');
    if (prevTitle) prevTitle.innerText = 'Xem Trước & In Tem QR (' + sizeTxt + ')';

    const prevFooterTxt = document.getElementById('preview_footer_size_txt');
    if (prevFooterTxt) prevFooterTxt.innerText = sizeTxt;
}

let activePrintMode = 'direct';
let lastRenderedLabels = [];

function getActivePrintMode() {
    const radio = document.querySelector('input[name="offcanvas_print_mode"]:checked');
    if (radio && (radio.value === 'preview' || radio.value === 'direct')) {
        return radio.value;
    }
    return activePrintMode || 'direct';
}

function onPrintModeChange(mode) {
    activePrintMode = (mode === 'preview') ? 'preview' : 'direct';
    try {
        localStorage.setItem('printbox_print_mode', activePrintMode);
    } catch (e) {}
}

function onLabelSizeChange(newSize, fromPreview = false, saveToDb = false) {
    newSize = normalizeSizeCode(newSize);
    activeLabelSize = newSize;

    try {
        localStorage.setItem('printbox_default_label_size', newSize);
    } catch(e) {}

    syncLabelSizeUI(newSize);
    applyDynamicPrintPageStyle(newSize);

    // Update DOM classes on Preview container and Standalone Print Zone
    const previewContainer = document.getElementById('printable_labels_container');
    if (previewContainer) {
        previewContainer.classList.remove('label-size-65x30', 'label-size-110x80');
        previewContainer.classList.add('label-size-' + newSize);
        previewContainer.querySelectorAll('.box-label-item').forEach(item => {
            item.classList.remove('label-size-65x30', 'label-size-110x80');
            item.classList.add('label-size-' + newSize);
        });
    }

    const standaloneZone = document.getElementById('print_standalone_zone');
    if (standaloneZone) {
        standaloneZone.classList.remove('label-size-65x30', 'label-size-110x80');
        standaloneZone.classList.add('label-size-' + newSize);
        standaloneZone.querySelectorAll('.box-label-item').forEach(item => {
            item.classList.remove('label-size-65x30', 'label-size-110x80');
            item.classList.add('label-size-' + newSize);
        });
    }

    // Save default label size configuration to server session & DB
    const fd = new FormData();
    fd.append('action', 'save_default_label_size');
    fd.append('label_size', newSize);
    fetch('orders.php', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            if (saveToDb) {
                const fb = document.getElementById('printer_config_feedback');
                if (fb && data.success) {
                    fb.innerText += ' | ' + data.message;
                }
            }
        })
        .catch(err => console.warn('Lỗi lưu khổ tem mặc định:', err));
}

(function initDefaultLabelSizeSync() {
    // Server $defaultLabelSize is authoritative; also auto-sync if selected printer name specifies size
    const ocPrinter = document.getElementById('offcanvas_printer_select');
    const printerMatchedSize = ocPrinter ? detectLabelSizeFromPrinterName(ocPrinter.value) : null;
    if (printerMatchedSize) {
        activeLabelSize = printerMatchedSize;
    } else {
        activeLabelSize = normalizeSizeCode(activeLabelSize);
    }
    try {
        localStorage.setItem('printbox_default_label_size', activeLabelSize);
    } catch(e) {}
    syncLabelSizeUI(activeLabelSize);
    applyDynamicPrintPageStyle(activeLabelSize);
})();

(function initDefaultPrinterSync() {
    // Discover printers on the Client PC immediately when page loads
    refreshConnectedPrinters(false);
})();

// =======================================================
// OFFCANVAS WORKFLOW: MỞ PANEL & TÍNH TOÁN TEM THÙNG
// =======================================================
function openOffcanvasPrintById(orderId) {
    const order = ordersDataMap[orderId];
    if (!order) {
        alert('Không tìm thấy dữ liệu chỉ thị #' + orderId);
        return;
    }
    loadOrderIntoOffcanvas(order);
    const offcanvasEl = document.getElementById('offcanvasPrint');
    const bsOffcanvas = bootstrap.Offcanvas.getOrCreateInstance(offcanvasEl);
    bsOffcanvas.show();
}

function openEditModalById(orderId) {
    const order = ordersDataMap[orderId];
    if (!order) {
        alert('Không tìm thấy dữ liệu chỉ thị #' + orderId);
        return;
    }
    openEditModal(order);
}

function openOffcanvasPrint(triggerEl) {
    const offcanvasEl = document.getElementById('offcanvasPrint');
    const bsOffcanvas = bootstrap.Offcanvas.getOrCreateInstance(offcanvasEl);

    if (triggerEl) {
        if (typeof triggerEl === 'number' || typeof triggerEl === 'string') {
            const order = ordersDataMap[triggerEl];
            if (order) loadOrderIntoOffcanvas(order);
        } else if (triggerEl.getAttribute) {
            const orderId = triggerEl.getAttribute('data-order-id');
            if (orderId && ordersDataMap[orderId]) {
                loadOrderIntoOffcanvas(ordersDataMap[orderId]);
            } else {
                const orderDataRaw = triggerEl.getAttribute('data-order');
                if (orderDataRaw) {
                    try {
                        const orderData = JSON.parse(orderDataRaw);
                        loadOrderIntoOffcanvas(orderData);
                    } catch(e) {
                        console.error("Lỗi parse order:", e);
                    }
                }
            }
        }
    } else {
        // Mở từ nút chung "Quét / In Tem"
        resetOffcanvasState();
        setTimeout(() => {
            const inp = document.getElementById('offcanvas_query_input');
            if (inp) inp.focus();
        }, 200);
    }

    bsOffcanvas.show();
}

function resetOffcanvasState() {
    currentOrder = null;
    currentCalculation = null;
    verifiedAdminMsnv = null;
    verifiedAdminPass = null;
    verifiedOverrideReason = null;
    document.getElementById('offcanvas_order_card').style.display = 'none';
    document.getElementById('offcanvas_print_inputs').style.display = 'none';
    document.getElementById('offcanvas_alert_paused').style.display = 'none';
    document.getElementById('offcanvas_calc_panel').style.display = 'none';
    document.getElementById('offcanvas_odd_option').style.display = 'none';
    document.getElementById('oc_chk_odd_box').checked = false;
    document.getElementById('offcanvas_query_input').value = '';
    document.getElementById('offcanvas_print_qty').value = '';
}

// Gắn sự kiện thay đổi loại thùng cập nhật quy cách và tính toán lại realtime
function onOffcanvasBoxTypeChange() {
    if (!currentOrder) return;
    const selectedBoxType = document.getElementById('offcanvas_box_type')?.value || '1/2';
    currentOrder.box_type = selectedBoxType;
    const key = currentOrder.product_code + '___' + selectedBoxType;
    const spec = allSpecsMapByKey[key] || allSpecsMap[currentOrder.product_code];
    if (spec) {
        currentOrder.pack_qty = parseInt(spec.pack_qty, 10) || 1;
        if (spec.weight_per_box) {
            currentOrder.weight_per_box = parseFloat(spec.weight_per_box);
        }
        if (spec.supplier) {
            currentOrder.supplier = spec.supplier;
            const suppEl = document.getElementById('oc_disp_supp');
            if (suppEl) suppEl.innerText = spec.supplier;
        }
    }
    const dispPackEl = document.getElementById('oc_disp_pack');
    if (dispPackEl) dispPackEl.innerText = currentOrder.pack_qty;
    const dispBox = document.getElementById('oc_disp_box_type');
    if (dispBox) dispBox.innerText = 'Thùng loại ' + selectedBoxType;
    const calcBox = document.getElementById('oc_calc_box_type_label');
    if (calcBox) calcBox.innerText = 'Thùng loại ' + selectedBoxType;
    recalculateOffcanvas();
}
window.onOffcanvasBoxTypeChange = onOffcanvasBoxTypeChange;

function loadOrderIntoOffcanvas(order) {
    currentOrder = Object.assign({}, order);
    currentCalculation = null;
    verifiedAdminMsnv = null;
    verifiedAdminPass = null;
    verifiedOverrideReason = null;

    document.getElementById('oc_disp_slip').innerText = currentOrder.slip_code;
    document.getElementById('oc_disp_order').innerText = currentOrder.order_code;
    document.getElementById('oc_disp_product').innerText = currentOrder.product_code;
    document.getElementById('oc_disp_month').innerText = currentOrder.issue_month || 'N/A';
    document.getElementById('oc_disp_pack').innerText = currentOrder.pack_qty;
    document.getElementById('oc_disp_supp').innerText = currentOrder.supplier || '(Trống)';

    const curBoxType = currentOrder.box_type || '1';
    const dispBoxTypeEl = document.getElementById('oc_disp_box_type');
    if (dispBoxTypeEl) dispBoxTypeEl.innerText = 'Thùng loại ' + curBoxType;
    const calcBoxTypeEl = document.getElementById('oc_calc_box_type_label');
    if (calcBoxTypeEl) calcBoxTypeEl.innerText = 'Thùng loại ' + curBoxType;

    // Nạp danh sách tùy chọn loại thùng động theo sản phẩm
    const boxTypeSelect = document.getElementById('offcanvas_box_type');
    if (boxTypeSelect) {
        boxTypeSelect.innerHTML = '';
        const prodSpecs = specsByProduct[currentOrder.product_code] || [];
        if (prodSpecs.length > 0) {
            prodSpecs.forEach(sp => {
                const opt = document.createElement('option');
                opt.value = sp.box_type || '1/2';
                opt.textContent = `Thùng loại ${sp.box_type || '1/2'} (${sp.pack_qty} con/thùng)`;
                boxTypeSelect.appendChild(opt);
            });
            const hasCurrent = prodSpecs.some(sp => (sp.box_type || '1/2') === curBoxType);
            if (!hasCurrent) {
                const opt = document.createElement('option');
                opt.value = curBoxType;
                opt.textContent = `Thùng loại ${curBoxType}`;
                boxTypeSelect.appendChild(opt);
            }
        } else {
            boxTypeSelect.innerHTML = `
                <option value="1/2">Thùng loại 1/2</option>
                <option value="1/4">Thùng loại 1/4</option>
            `;
        }
        boxTypeSelect.value = curBoxType;
    }

    const selBoxType = document.getElementById('offcanvas_box_type');
    if (selBoxType) {
        selBoxType.onchange = onOffcanvasBoxTypeChange;
    }

    document.getElementById('oc_disp_target').innerText = Number(currentOrder.target_qty).toLocaleString();
    document.getElementById('oc_disp_printed').innerText = Number(currentOrder.printed_qty).toLocaleString();
    document.getElementById('oc_disp_remaining').innerText = Number(currentOrder.remaining_qty).toLocaleString();

    // Checkbox in thùng lẻ luôn luôn UNCHECKED by default
    document.getElementById('oc_chk_odd_box').checked = false;

    const isPaused = (currentOrder.status === 'paused');
    const statusBadge = document.getElementById('offcanvas_badge_status');
    const pausedAlert = document.getElementById('offcanvas_alert_paused');
    const printInputs = document.getElementById('offcanvas_print_inputs');
    const submitBtn   = document.getElementById('btn_offcanvas_submit');
    const printQtyInput = document.getElementById('offcanvas_print_qty');

    if (isPaused) {
        statusBadge.className = 'badge bg-warning text-dark';
        statusBadge.innerText = '⏸️ TẠM DỪNG';
        pausedAlert.style.display = 'block';
        printInputs.style.display = 'none';
    } else {
        pausedAlert.style.display = 'none';
        printInputs.style.display = 'block';
        printQtyInput.disabled = false;
        submitBtn.disabled = false;

        if (currentOrder.status === 'completed') {
            statusBadge.className = 'badge bg-success';
            statusBadge.innerText = 'HOÀN THÀNH';
        } else if (currentOrder.status === 'in_progress') {
            statusBadge.className = 'badge bg-primary';
            statusBadge.innerText = 'ĐANG IN';
        } else {
            statusBadge.className = 'badge bg-secondary';
            statusBadge.innerText = 'CHỜ IN';
        }

        printQtyInput.value = '';
        setTimeout(() => printQtyInput.focus(), 300);
    }

    document.getElementById('offcanvas_order_card').style.display = 'block';
    recalculateOffcanvas();
}

// TRA CỨU QUA Ô TÌM KIẾM OFFCANVAS
function searchOrderOffcanvas() {
    const q = document.getElementById('offcanvas_query_input').value.trim();
    if (!q) {
        alert('Vui lòng nhập hoặc quét Mã phiếu chỉ thị / Mã chỉ thị!');
        return;
    }

    const btn = document.getElementById('btn_offcanvas_search');
    btn.disabled = true;
    btn.innerText = '...';

    fetch('ajax/get_order.php?q=' + encodeURIComponent(q))
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            btn.innerText = '🔍 Tìm';

            if (!data.success) {
                alert(data.message);
                return;
            }

            // Đồng bộ vào ordersDataMap
            if (data.order && data.order.id) {
                ordersDataMap[data.order.id] = data.order;
            }
            loadOrderIntoOffcanvas(data.order);
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerText = '🔍 Tìm';
            alert('Lỗi tra cứu: ' + err.message);
        });
}

const btnOffSearch = document.getElementById('btn_offcanvas_search');
if (btnOffSearch) btnOffSearch.addEventListener('click', searchOrderOffcanvas);

const inpOffQuery = document.getElementById('offcanvas_query_input');
if (inpOffQuery) {
    inpOffQuery.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchOrderOffcanvas();
        }
    });
}

// TÍNH TOÁN TEM THÙNG REALTIME THEO CÔNG THỨC CHUẨN
function recalculateOffcanvas() {
    if (!currentOrder) return;

    const printQtyVal = parseInt(document.getElementById('offcanvas_print_qty').value, 10);
    const packageSpec = Number(currentOrder.pack_qty);

    // Reset xác thực vượt mức mỗi khi thay đổi số lượng in
    verifiedAdminMsnv = null;
    verifiedAdminPass = null;
    verifiedOverrideReason = null;

    if (isNaN(printQtyVal) || printQtyVal <= 0) {
        document.getElementById('offcanvas_calc_panel').style.display = 'none';
        document.getElementById('offcanvas_odd_option').style.display = 'none';
        document.getElementById('oc_chk_odd_box').checked = false;
        document.getElementById('offcanvas_alert_over').style.display = 'none';
        currentCalculation = null;
        return;
    }

    const fullBoxes = Math.floor(printQtyVal / packageSpec);
    const remainder = printQtyVal % packageSpec;
    const hasOdd    = (remainder > 0);
    const totalBoxes = fullBoxes + (hasOdd ? 1 : 0);

    currentCalculation = {
        printQty: printQtyVal,
        packageSpec: packageSpec,
        fullBoxes: fullBoxes,
        remainder: remainder,
        hasOdd: hasOdd,
        totalBoxes: totalBoxes
    };

    document.getElementById('oc_calc_pack').innerText = packageSpec + ' con/thùng';
    document.getElementById('oc_calc_full_boxes').innerText = fullBoxes + ' thùng (' + (fullBoxes * packageSpec) + ' con)';
    
    // CONDITION 1: remainder == 0 -> TUYỆT ĐỐI KHÔNG hiển thị cảnh báo thùng lẻ, KHÔNG yêu cầu in lẻ
    if (!hasOdd) {
        document.getElementById('oc_row_calc_odd').style.display = 'none';
        document.getElementById('offcanvas_odd_option').style.display = 'none';
        document.getElementById('oc_chk_odd_box').checked = false;
        document.getElementById('oc_calc_odd_qty').innerText = '';
        document.getElementById('oc_odd_warning_text').innerText = '';
    } else {
        // CONDITION 2: remainder > 0 -> Bắt buộc hiển thị cảnh báo & yêu cầu tick chọn in lẻ
        document.getElementById('oc_row_calc_odd').style.display = 'flex';
        document.getElementById('oc_calc_odd_qty').innerText = '1 thùng lẻ (' + remainder + ' con)';
        document.getElementById('oc_odd_warning_text').innerText = 'Dư ' + remainder + ' con lẻ (Quy cách ' + packageSpec + ' con/thùng).';
        document.getElementById('offcanvas_odd_option').style.display = 'block';
    }

    document.getElementById('oc_calc_total_boxes').innerText = totalBoxes + ' TEM';
    document.getElementById('offcanvas_calc_panel').style.display = 'block';

    // Kiểm tra định mức còn lại
    const remaining = parseInt(currentOrder.remaining_qty, 10);
    if (printQtyVal > remaining) {
        document.getElementById('offcanvas_alert_over').style.display = 'block';
        document.getElementById('oc_txt_excess_print').innerText = printQtyVal.toLocaleString();
        document.getElementById('oc_txt_excess_rem').innerText = remaining.toLocaleString();
    } else {
        document.getElementById('offcanvas_alert_over').style.display = 'none';
    }
}

const inpPrintQty = document.getElementById('offcanvas_print_qty');
if (inpPrintQty) inpPrintQty.addEventListener('input', recalculateOffcanvas);

// =======================================================
// KIỂM TRA DỮ LIỆU TRƯỚC KHI IN & THỰC THI GỬI LỆNH IN TỰ ĐỘNG
// =======================================================
function validatePrePrintFrontend() {
    const errors = [];
    if (!currentOrder) {
        errors.push('Chưa chọn Chỉ Thị Sản Xuất.');
    } else {
        if (!currentOrder.order_code || String(currentOrder.order_code).trim() === '') {
            errors.push('Thiếu Mã CTSX (Lot No).');
        }
        if (!currentOrder.product_code || String(currentOrder.product_code).trim() === '') {
            errors.push('Thiếu Mã sản phẩm.');
        }
    }

    if (!currentCalculation || !currentCalculation.printQty || currentCalculation.printQty <= 0 || !currentCalculation.totalBoxes || currentCalculation.totalBoxes <= 0) {
        errors.push('Số lượng tem sản phẩm / số thùng không hợp lệ (phải lớn hơn 0).');
    }

    const printerSelect = document.getElementById('offcanvas_printer_select');
    const selectedPrinter = printerSelect ? printerSelect.value.trim() : '';
    const isBrowserPrint = (selectedPrinter === 'Browser Print' || selectedPrinter === '');
    if (!isBrowserPrint) {
        const physical = clientPrintersRegistry.filter(p => p.name && p.name !== 'Browser Print');
        if (physical.length === 0) {
            errors.push('Không tìm thấy máy in trực tiếp "' + selectedPrinter + '" trên máy tính này. Vui lòng chuyển sang In Qua Trình Duyệt hoặc cài đặt phần mềm hỗ trợ in.');
        } else if (!selectedPrinter) {
            errors.push('Vui lòng chọn máy in tem đang kết nối trên máy tính hiện tại.');
        }
    }

    const selectedSize = getActiveLabelSize();
    if (selectedSize !== '65x30' && selectedSize !== '110x80') {
        errors.push('Kích thước tem không hợp lệ (chỉ hỗ trợ 65x30mm hoặc 110x80mm).');
    }

    return {
        valid: errors.length === 0,
        errors: errors,
        printer: selectedPrinter,
        labelSize: selectedSize
    };
}

function showDirectPrintToast(message, isSuccess = true) {
    let toastEl = document.getElementById('direct_print_status_toast');
    if (!toastEl) {
        toastEl = document.createElement('div');
        toastEl.id = 'direct_print_status_toast';
        toastEl.className = 'no-print';
        toastEl.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:1090;max-width:440px;padding:12px 16px;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,0.22);font-weight:600;font-size:0.92rem;display:flex;align-items:center;gap:10px;transition:opacity 0.25s ease;';
        document.body.appendChild(toastEl);
    }
    toastEl.style.backgroundColor = isSuccess ? '#15803d' : '#b91c1c';
    toastEl.style.color = '#ffffff';
    toastEl.style.opacity = '1';
    toastEl.style.display = 'flex';
    toastEl.innerHTML = `
        <span style="font-size:1.25rem;">${isSuccess ? '🖨️' : '⚠️'}</span>
        <div style="flex:1;line-height:1.35;">${escapeHtml(message)}</div>
        <button type="button" class="btn btn-sm btn-light fw-bold py-0 px-2" style="font-size:0.78rem;" onclick="openModal('modal_print_result')">Xem mẫu</button>
    `;
    if (window._directPrintToastTimer) clearTimeout(window._directPrintToastTimer);
    window._directPrintToastTimer = setTimeout(() => {
        if (toastEl) toastEl.style.display = 'none';
    }, 6000);
}

// Build ZPL payload for Zebra Browser Print on Client PC
function buildZplForClientZebra(labels, labelSize) {
    const isLarge = (normalizeSizeCode(labelSize) === '110x80');
    // 203 DPI dots: 104mm = 832 dots, 80mm = 640 dots; 65mm = 520 dots, 30mm = 240 dots
    const pw = isLarge ? 832 : 520;
    const ll = isLarge ? 640 : 240;
    let zpl = '';
    labels.forEach(lb => {
        const qrText = String(lb.qr_content || '').replace(/\^/g, ' ');
        const mat = String(lb.material_name || '').replace(/\^/g, ' ');
        const qty = String(lb.qty || '') + ' pcs' + (lb.weight ? ' | ' + lb.weight + ' Kg' : '');
        const lot = String(lb.lot_no || '') + (lb.supplier ? ' | ' + lb.supplier : '');
        const box = String(lb.box_no || '').replace(/\^/g, ' ');
        if (isLarge) {
            zpl += `^XA^CI28^PW${pw}^LL${ll}^LH0,0`;
            zpl += `^FO28,45^BQN,2,10^FDMA,${qrText}^FS`;
            zpl += `^FO390,55^A0N,42,42^FB420,2,4,L,0^FD${mat}^FS`;
            zpl += `^FO390,175^GB415,3,3^FS`;
            zpl += `^FO390,200^A0N,50,50^FD${qty}^FS`;
            zpl += `^FO390,275^GB415,3,3^FS`;
            zpl += `^FO390,295^A0N,34,34^FD||^FS`;
            zpl += `^FO390,345^GB415,3,3^FS`;
            zpl += `^FO390,370^A0N,38,38^FB420,2,4,L,0^FD${lot}^FS`;
            zpl += `^FO390,475^GB415,3,3^FS`;
            zpl += `^FO390,500^A0N,36,36^FD${box}^FS`;
            zpl += `^XZ\n`;
        } else {
            zpl += `^XA^CI28^PW${pw}^LL${ll}^LH0,0`;
            zpl += `^FO14,16^BQN,2,5^FDMA,${qrText}^FS`;
            zpl += `^FO215,18^A0N,24,24^FB295,1,0,L,0^FD${mat}^FS`;
            zpl += `^FO215,50^GB290,2,2^FS`;
            zpl += `^FO215,60^A0N,26,26^FD${qty}^FS`;
            zpl += `^FO215,94^GB290,2,2^FS`;
            zpl += `^FO215,102^A0N,20,20^FD||^FS`;
            zpl += `^FO215,126^GB290,2,2^FS`;
            zpl += `^FO215,136^A0N,22,22^FB295,1,0,L,0^FD${lot}^FS`;
            zpl += `^FO215,168^GB290,2,2^FS`;
            zpl += `^FO215,178^A0N,22,22^FD${box}^FS`;
            zpl += `^XZ\n`;
        }
    });
    return zpl;
}

// Dispatch Direct Print strictly to the selected printer on the Client PC
async function dispatchClientDirectPrint(labels, selectedPrinter, labelSize) {
    if (!selectedPrinter || selectedPrinter === 'Browser Print') {
        return { success: false, fallback_to_browser: true };
    }
    const printerEntry = clientPrintersRegistry.find(p => p.name.toLowerCase() === String(selectedPrinter || '').toLowerCase());

    // 1. Zebra Browser Print on Client PC (127.0.0.1:9100/write)
    if (printerEntry && printerEntry.source === 'zebra_browser_print' && printerEntry.device) {
        try {
            const zplData = buildZplForClientZebra(labels, labelSize);
            const res = await fetch('http://127.0.0.1:9100/write', {
                method: 'POST',
                mode: 'cors',
                headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
                body: JSON.stringify({
                    device: printerEntry.device,
                    data: zplData
                })
            });
            if (res.ok) {
                return {
                    success: true,
                    message: `Đã gửi trực tiếp ${labels.length} tem đến máy in "${selectedPrinter}" qua Zebra Browser Print trên máy tính hiện tại.`
                };
            }
        } catch (e) {}
    }

    // 2. QZ Tray on Client PC
    if (printerEntry && printerEntry.source === 'qz_tray' && typeof window.qz !== 'undefined') {
        try {
            if (!window.qz.websocket.isActive()) {
                await window.qz.websocket.connect({ retries: 0, delay: 0 });
            }
            const cfg = window.qz.configs.create(selectedPrinter);
            const zplData = buildZplForClientZebra(labels, labelSize);
            await window.qz.print(cfg, [zplData]);
            return {
                success: true,
                message: `Đã gửi trực tiếp ${labels.length} tem đến máy in "${selectedPrinter}" qua QZ Tray trên máy tính hiện tại.`
            };
        } catch (e) {}
    }

    // Client PC is an independent workstation without XAMPP/PHP web server;
    // Direct print is strictly handled via local print bridge (Zebra / QZ Tray / Kiosk mode).
    return { success: false };
}

function handlePrintJobSuccess(data, selectedPrinter) {
    // Kiểm tra dữ liệu QR Code trả về từ server trước khi in
    if (!Array.isArray(data.labels) || data.labels.length === 0) {
        alert('Lỗi dữ liệu in: Hệ thống không sinh được danh sách tem QR!');
        return;
    }
    for (let i = 0; i < data.labels.length; i++) {
        const lb = data.labels[i];
        if (!lb.qr_content || !lb.qr_base64 || !String(lb.qr_base64).startsWith('data:image/')) {
            alert('Lỗi dữ liệu in: Tem #' + (i + 1) + ' thiếu dữ liệu QR Code hợp lệ!');
            return;
        }
    }

    lastRenderedLabels = data.labels;

    // Cập nhật dòng tương ứng trên bảng dữ liệu
    updateTableRowAfterPrint(currentOrder.id, data.new_printed, data.new_remaining, currentOrder.target_qty);

    // Đóng các modal xác nhận và offcanvas
    closeModal('modal_confirm_print', true);
    closeModal('modal_admin_approval', true);
    const offcanvasEl = document.getElementById('offcanvasPrint');
    if (offcanvasEl && typeof bootstrap !== 'undefined' && bootstrap.Offcanvas) {
        const bsOffcanvas = bootstrap.Offcanvas.getInstance(offcanvasEl);
        if (bsOffcanvas) bsOffcanvas.hide();
    }

    // Render sẵn dữ liệu tem vào cả vùng Preview và vùng Standalone Print Zone
    renderPrintedLabels(data.labels);
    const boxCountEl = document.getElementById('res_box_count');
    if (boxCountEl) boxCountEl.innerText = data.labels.length;
    const resPrinterEl = document.getElementById('res_printer_name');
    if (resPrinterEl) resPrinterEl.innerText = selectedPrinter;

    const mode = getActivePrintMode();
    if (mode === 'preview') {
        // Người dùng chủ động chọn chế độ Xem trước (Preview)
        logPrintOperation({
            printer: selectedPrinter,
            labelSize: getActiveLabelSize(),
            printMode: 'preview',
            resultStatus: 'DIRECT_SUCCESS',
            details: 'Người dùng mở modal xem trước tem trước khi in',
            labelCount: data.labels.length
        });
        openModal('modal_print_result');
        return;
    }

    // Chế độ In trực tiếp hoặc In qua trình duyệt
    if (selectedPrinter === 'Browser Print' || selectedPrinter === '') {
        logPrintOperation({
            printer: 'Browser Print',
            labelSize: getActiveLabelSize(),
            printMode: 'browser',
            resultStatus: 'BROWSER_DIALOG_FALLBACK',
            details: 'Người dùng chọn chế độ In Qua Trình Duyệt thủ công',
            labelCount: data.labels.length
        });
        autoDispatchPrint();
    } else if (data.direct_printed) {
        logPrintOperation({
            printer: selectedPrinter,
            labelSize: getActiveLabelSize(),
            printMode: 'direct',
            resultStatus: 'DIRECT_SUCCESS',
            details: data.direct_print_msg || 'In trực tiếp qua Windows Print Spooler thành công',
            labelCount: data.labels.length
        });
        showDirectPrintToast(
            `Đã tự động in ${data.labels.length} tem (${getActiveLabelSize() === '110x80' ? '80×100 / 100×80mm Ngang' : '65×30mm Ngang'}) đến máy in "${selectedPrinter}".`,
            true
        );
    } else {
        // Thử gửi trực tiếp qua dịch vụ Client (Zebra Browser Print / QZ Tray / WebUSB)
        dispatchClientDirectPrint(data.labels, selectedPrinter, getActiveLabelSize()).then(clientRes => {
            if (clientRes && clientRes.success) {
                logPrintOperation({
                    printer: selectedPrinter,
                    labelSize: getActiveLabelSize(),
                    printMode: 'direct',
                    resultStatus: 'DIRECT_SUCCESS',
                    details: clientRes.message || 'In trực tiếp qua Client Print Daemon thành công',
                    labelCount: data.labels.length
                });
                showDirectPrintToast(clientRes.message, true);
            } else {
                // CHỈ mở Print Dialog khi in trực tiếp thất bại
                const reason = clientRes && clientRes.message ? clientRes.message : `Máy in "${selectedPrinter}" không có dịch vụ in trực tiếp chạy trên máy tính này.`;
                logPrintOperation({
                    printer: selectedPrinter,
                    labelSize: getActiveLabelSize(),
                    printMode: 'direct',
                    resultStatus: 'BROWSER_DIALOG_FALLBACK',
                    details: reason + ' => Chuyển sang Print Dialog làm phương án dự phòng.',
                    labelCount: data.labels.length
                });
                showDirectPrintToast(`⚠️ Không thể in trực tiếp tới "${selectedPrinter}". Đang chuyển sang In Qua Trình Duyệt...`, false);
                autoDispatchPrint();
            }
        });
    }
}

function executePrintSubmission() {
    const check = validatePrePrintFrontend();
    if (!check.valid) {
        alert('LỖI DỮ LIỆU TRƯỚC KHI IN:\n- ' + check.errors.join('\n- '));
        return;
    }

    const btnOff = document.getElementById('btn_offcanvas_submit');
    const btnConfirm = document.getElementById('btn_confirm_print_execute');
    if (btnOff) {
        btnOff.disabled = true;
        btnOff.innerHTML = '⏳ ĐANG TỰ ĐỘNG SINH TEM & GỬI LỆNH IN...';
    }
    if (btnConfirm) {
        btnConfirm.disabled = true;
        btnConfirm.innerText = '⏳ Đang lưu & gửi lệnh in...';
    }

    const formData = new FormData();
    formData.append('order_id', currentOrder.id);
    formData.append('print_qty', currentCalculation.printQty);
    formData.append('is_odd_box', (currentCalculation.hasOdd && document.getElementById('oc_chk_odd_box').checked) ? 1 : 0);
    formData.append('box_type', document.getElementById('offcanvas_box_type')?.value || currentOrder.box_type || '1/2');
    formData.append('admin_msnv', verifiedAdminMsnv || '');
    formData.append('admin_pass', verifiedAdminPass || '');
    formData.append('override_reason', verifiedOverrideReason || '');
    formData.append('printer_name', check.printer);
    formData.append('label_size', check.labelSize);
    formData.append('print_mode', getActivePrintMode());
    formData.append('note', document.getElementById('offcanvas_note').value.trim());

    fetch('ajax/submit_print.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (btnOff) {
            btnOff.disabled = false;
            btnOff.innerHTML = '📥 XÁC NHẬN NHẬP &amp; IN TEM THÙNG';
        }
        if (btnConfirm) {
            btnConfirm.disabled = false;
            btnConfirm.innerText = '✅ ĐỒNG Ý NHẬP & IN NGAY';
        }

        if (!data.success) {
            if (data.require_admin) {
                closeModal('modal_confirm_print', true);
                verifiedAdminMsnv = null;
                verifiedAdminPass = null;
                verifiedOverrideReason = null;
                document.getElementById('modal_req_qty').innerText = (data.print_qty || currentCalculation.printQty).toLocaleString();
                document.getElementById('modal_rem_qty').innerText = (data.remaining_qty !== undefined ? data.remaining_qty : currentOrder.remaining_qty).toLocaleString();
                document.getElementById('modal_diff_qty').innerText = '+' + ((data.excess_qty !== undefined ? data.excess_qty : (currentCalculation.printQty - currentOrder.remaining_qty))).toLocaleString() + ' con';
                document.getElementById('input_admin_msnv').value = '';
                document.getElementById('input_admin_pass').value = '';
                const reasonEl = document.getElementById('input_override_reason');
                if (reasonEl) reasonEl.value = '';
                document.getElementById('admin_verify_feedback').innerText = data.message;
                document.getElementById('admin_verify_feedback').style.color = '#b91c1c';
                openModal('modal_admin_approval');
            } else if (data.require_odd_confirm) {
                closeModal('modal_confirm_print', true);
                alert(data.message);
                document.getElementById('oc_chk_odd_box').focus();
            } else {
                alert('Lỗi: ' + data.message);
            }
            return;
        }

        handlePrintJobSuccess(data, check.printer);
    })
    .catch(err => {
        if (btnOff) {
            btnOff.disabled = false;
            btnOff.innerHTML = '📥 XÁC NHẬN NHẬP &amp; IN TEM THÙNG';
        }
        if (btnConfirm) {
            btnConfirm.disabled = false;
            btnConfirm.innerText = '✅ ĐỒNG Ý NHẬP & IN NGAY';
        }
        alert('Lỗi gửi dữ liệu: ' + err.message);
    });
}

// BẤM NÚT XÁC NHẬN NHẬP & IN TEM TRONG OFFCANVAS -> KIỂM TRA DỮ LIỆU & TỰ ĐỘNG GỬI LỆNH IN NGAY
const btnOffSubmit = document.getElementById('btn_offcanvas_submit');
if (btnOffSubmit) {
    btnOffSubmit.addEventListener('click', function() {
        const check = validatePrePrintFrontend();
        if (!check.valid) {
            alert('VUI LÒNG KIỂM TRA DỮ LIỆU TRƯỚC KHI IN:\n- ' + check.errors.join('\n- '));
            return;
        }

        const printQty = currentCalculation.printQty;
        const remaining = parseInt(currentOrder.remaining_qty, 10);

        // BẮT BUỘC KIỂM TRA THÙNG LẺ: Nếu có phần dư (remainder > 0) mà chưa tích chọn
        if (currentCalculation.hasOdd && !document.getElementById('oc_chk_odd_box').checked) {
            alert('CẢNH BÁO THÙNG LẺ:\nSố lượng in (' + printQty + ' con) không chia hết cho quy cách (' + currentCalculation.packageSpec + ' con/thùng), dư ' + currentCalculation.remainder + ' con lẻ.\n\nBạn bắt buộc phải tích chọn ô "Xác nhận cho phép in thùng lẻ" để tiếp tục!');
            document.getElementById('oc_chk_odd_box').focus();
            return;
        }

        // BẮT BUỘC KIỂM TRA VƯỢT ĐỊNH MỨC:
        // Khi printQty > remaining BẮT BUỘC PHẢI QUA BƯỚC XÁC THỰC MSNV, MẬT KHẨU & LÝ DO IN VƯỢT!
        if (printQty > remaining && (!verifiedAdminMsnv || !verifiedAdminPass || !verifiedOverrideReason)) {
            document.getElementById('modal_req_qty').innerText = printQty.toLocaleString();
            document.getElementById('modal_rem_qty').innerText = remaining.toLocaleString();
            document.getElementById('modal_diff_qty').innerText = '+' + (printQty - remaining).toLocaleString() + ' con';
            document.getElementById('input_admin_msnv').value = '';
            document.getElementById('input_admin_pass').value = '';
            const reasonEl = document.getElementById('input_override_reason');
            if (reasonEl) reasonEl.value = '';
            document.getElementById('admin_verify_feedback').innerText = '';
            openModal('modal_admin_approval');
            return;
        }

        // Tự động sinh tem và gửi lệnh in ngay lập tức sau khi nhấn "Xác nhận nhập và in tem"
        executePrintSubmission();
    });
}

// XEM MẪU BỐ CỤC TEM TRƯỚC KHI LƯU (CHỈ DÙNG ĐỂ KIỂM TRA BỐ CỤC KHI CẦN)
function previewDraftLayoutOffcanvas() {
    if (!currentOrder) {
        alert('Vui lòng chọn chỉ thị sản xuất trước khi xem mẫu bố cục!');
        return;
    }
    const packQty = Number(currentOrder.pack_qty) || 100;
    const sampleBoxNo = 'TU-' + new Date().toISOString().slice(2,10).replace(/-/g,'') + '-001';
    const sampleQrContent = currentOrder.product_code + '$' + packQty + '$' + sampleBoxNo + '$' + currentOrder.order_code;

    fetch('ajax/generate_qr.php?format=base64&size=8&margin=1&text=' + encodeURIComponent(sampleQrContent))
        .then(res => res.json())
        .then(data => {
            const qrDataUri = (data && data.success && data.data_uri) ? data.data_uri : '';
            const sampleLabels = [{
                box_no: sampleBoxNo,
                qr_content: sampleQrContent,
                qr_base64: qrDataUri,
                material_name: currentOrder.product_code,
                qty: packQty,
                lot_no: currentOrder.order_code,
                supplier: currentOrder.supplier || '',
                weight: currentOrder.weight_per_box || ''
            }];

            lastRenderedLabels = sampleLabels;
            renderPrintedLabels(sampleLabels);
            const boxCountEl = document.getElementById('res_box_count');
            if (boxCountEl) boxCountEl.innerText = '1 (Mẫu thử)';
            openModal('modal_print_result');
        })
        .catch(err => {
            alert('Không thể tạo mã QR xem trước: ' + err.message);
        });
}

// GỬI LỆNH IN TRỰC TIẾP TỪ CỬA SỔ PREVIEW RA MÁY IN TRÊN CLIENT PC
function sendPreviewToDirectSpooler() {
    if (!Array.isArray(lastRenderedLabels) || lastRenderedLabels.length === 0) {
        triggerDirectPrint();
        return;
    }
    const printerSelect = document.getElementById('offcanvas_printer_select');
    const selectedPrinter = printerSelect ? printerSelect.value.trim() : '';
    if (!selectedPrinter) {
        alert(clientPrintersRegistry.length === 0 ? NO_PRINTER_MESSAGE : 'Vui lòng chọn máy in tem trên máy tính hiện tại trước khi in!');
        return;
    }
    const currentSize = getActiveLabelSize();
    const btn = document.getElementById('btn_preview_direct_spool');
    if (btn) {
        btn.disabled = true;
        btn.innerText = '⏳ Đang gửi lệnh in...';
    }

    dispatchClientDirectPrint(lastRenderedLabels, selectedPrinter, currentSize).then(clientRes => {
        if (clientRes && clientRes.success) {
            if (btn) {
                btn.disabled = false;
                btn.innerText = '⚡ In Trực Tiếp';
            }
            closeModal('modal_print_result', true);
            showDirectPrintToast(clientRes.message, true);
            return;
        }

        const fd = new FormData();
        fd.append('action', 'direct_print_batch');
        fd.append('printer_name', selectedPrinter);
        fd.append('label_size', currentSize);
        fd.append('labels_json', JSON.stringify(lastRenderedLabels));

        fetch('orders.php', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                if (btn) {
                    btn.disabled = false;
                    btn.innerText = '⚡ In Trực Tiếp';
                }
                if (data.success && data.direct_printed) {
                    closeModal('modal_print_result', true);
                    showDirectPrintToast(data.message || `Đã gửi lệnh in trực tiếp đến ${selectedPrinter}`, true);
                } else {
                    triggerDirectPrint();
                }
            })
            .catch(() => {
                if (btn) {
                    btn.disabled = false;
                    btn.innerText = '⚡ In Trực Tiếp';
                }
                triggerDirectPrint();
            });
    });
}

// XÁC THỰC MSNV, MẬT KHẨU & LÝ DO DUYỆT VƯỢT MỨC CỦA ADMIN/EDITOR VÀ THỰC THI IN NGAY
function submitAdminApproval() {
    const msnvInput   = document.getElementById('input_admin_msnv');
    const passInput   = document.getElementById('input_admin_pass');
    const reasonInput = document.getElementById('input_override_reason');
    const fb          = document.getElementById('admin_verify_feedback');
    const btn         = document.getElementById('btn_verify_and_proceed');

    const msnv           = msnvInput ? msnvInput.value.trim() : '';
    const pass           = passInput ? passInput.value.trim() : '';
    const overrideReason = reasonInput ? reasonInput.value.trim() : '';

    if (!msnv) {
        if (fb) {
            fb.style.color = '#b91c1c';
            fb.innerText = '✗ Vui lòng nhập Mã số nhân viên (MSNV) của Quản lý / Admin / Editor!';
        }
        if (msnvInput) {
            msnvInput.focus();
            msnvInput.select();
        }
        return;
    }

    if (!pass) {
        if (fb) {
            fb.style.color = '#b91c1c';
            fb.innerText = '✗ Vui lòng nhập Mật khẩu/PIN xác thực của Quản lý / Admin / Editor!';
        }
        if (passInput) {
            passInput.focus();
            passInput.select();
        }
        return;
    }

    if (!overrideReason) {
        if (fb) {
            fb.style.color = '#b91c1c';
            fb.innerText = '✗ Vui lòng nhập Lý do in vượt định mức (bắt buộc)!';
        }
        if (reasonInput) {
            reasonInput.focus();
        }
        return;
    }

    const check = validatePrePrintFrontend();
    if (!check.valid) {
        alert('LỖI DỮ LIỆU TRƯỚC KHI IN:\n- ' + check.errors.join('\n- '));
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Đang xác thực & tự động gửi lệnh in...';
    fb.style.color = '#2563eb';
    fb.innerText = 'Đang kiểm tra quyền duyệt & tự động in tem...';

    const formData = new FormData();
    formData.append('order_id', currentOrder.id);
    formData.append('print_qty', currentCalculation.printQty);
    formData.append('is_odd_box', (currentCalculation.hasOdd && document.getElementById('oc_chk_odd_box').checked) ? 1 : 0);
    formData.append('box_type', document.getElementById('offcanvas_box_type')?.value || currentOrder.box_type || '1/2');
    formData.append('msnv', msnv);
    formData.append('password', pass);
    formData.append('override_reason', overrideReason);
    formData.append('printer_name', check.printer);
    formData.append('label_size', check.labelSize);
    formData.append('print_mode', getActivePrintMode());
    formData.append('note', document.getElementById('offcanvas_note').value.trim());

    fetch('ajax/authorize_override.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '🔑 Xác nhận duyệt vượt mức';

        if (!data.success) {
            fb.style.color = '#b91c1c';
            fb.innerText = '✗ ' + data.message;
            if (passInput) {
                passInput.focus();
                passInput.select();
            }
            return;
        }

        fb.style.color = '#15803d';
        fb.innerText = '✓ ' + data.message;
        verifiedAdminMsnv = msnv;
        verifiedAdminPass = pass;
        verifiedOverrideReason = overrideReason;

        handlePrintJobSuccess(data, check.printer);
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '🔑 Xác nhận duyệt vượt mức';
        fb.style.color = '#b91c1c';
        fb.innerText = 'Lỗi kết nối máy chủ: ' + err.message;
    });
}

const formAdminApproval = document.getElementById('form_admin_approval');
if (formAdminApproval) {
    formAdminApproval.addEventListener('submit', function(e) {
        e.preventDefault();
        submitAdminApproval();
    });
}

const btnVerifyProceed = document.getElementById('btn_verify_and_proceed');
if (btnVerifyProceed) {
    btnVerifyProceed.addEventListener('click', function(e) {
        e.preventDefault();
        submitAdminApproval();
    });
}

// Nút ẩn/hiện mật khẩu trong modal duyệt vượt mức
const btnToggleApprovalPass = document.getElementById('btn_toggle_approval_pass');
if (btnToggleApprovalPass) {
    btnToggleApprovalPass.addEventListener('click', function(e) {
        e.preventDefault();
        const passInput = document.getElementById('input_admin_pass');
        if (passInput) {
            if (passInput.type === 'password') {
                passInput.type = 'text';
                btnToggleApprovalPass.innerText = '🙈';
            } else {
                passInput.type = 'password';
                btnToggleApprovalPass.innerText = '👁️';
            }
            passInput.focus();
        }
    });
}

// GỬI AJAX LƯU LỊCH SỬ & SINH TEM THÙNG KHI NHẤN "ĐỒNG Ý NHẬP & IN NGAY"
const btnConfirmExecute = document.getElementById('btn_confirm_print_execute');
if (btnConfirmExecute) {
    btnConfirmExecute.addEventListener('click', function() {
        executePrintSubmission();
    });
}

// SẮP XẾP LẠI DÒNG TRÊN BẢNG THEO ĐÚNG THỨ TỰ ƯU TIÊN NGHIỆP VỤ
function parseIssueMonthTimestamp(issueMonthStr, createdAtStr) {
    const raw = (issueMonthStr || '').trim();
    if (raw) {
        // MM/YYYY hoặc M/YYYY hoặc MM-YYYY
        let m = raw.match(/^(\d{1,2})[\/\-](\d{4})$/);
        if (m) return new Date(parseInt(m[2], 10), parseInt(m[1], 10) - 1, 1).getTime();
        // MM/YY hoặc M/YY
        m = raw.match(/^(\d{1,2})[\/\-](\d{2})$/);
        if (m) return new Date(2000 + parseInt(m[2], 10), parseInt(m[1], 10) - 1, 1).getTime();
        // YYYY-MM hoặc YYYY/MM
        m = raw.match(/^(\d{4})[\/\-](\d{1,2})$/);
        if (m) return new Date(parseInt(m[1], 10), parseInt(m[2], 10) - 1, 1).getTime();
        // DD/MM/YYYY
        m = raw.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/);
        if (m) return new Date(parseInt(m[3], 10), parseInt(m[2], 10) - 1, parseInt(m[1], 10)).getTime();
        // Mon-YY hoặc Mon-YYYY (vd: Oct-26)
        const parsed = Date.parse('01 ' + raw.replace(/[\-\/]/g, ' '));
        if (!isNaN(parsed)) return parsed;
    }
    if (createdAtStr) {
        const fb = Date.parse(createdAtStr.replace(' ', 'T'));
        if (!isNaN(fb)) return fb;
    }
    return 0;
}

function getStatusSortRank(status) {
    switch (status) {
        case 'in_progress': return 1; // Đang in
        case 'pending':     return 2; // Chờ in
        case 'paused':      return 3; // Tạm dừng in
        case 'completed':   return 4; // Hoàn thành (cuối bảng)
        default:            return 5;
    }
}

function sortOrdersTableRows() {
    const tbody = document.querySelector('#orders_table tbody');
    if (!tbody) return;
    const rows = Array.from(tbody.querySelectorAll('tr[id^="row_order_"]'));
    if (rows.length <= 1) return;

    rows.sort((trA, trB) => {
        const idA = parseInt(trA.id.replace('row_order_', ''), 10) || 0;
        const idB = parseInt(trB.id.replace('row_order_', ''), 10) || 0;
        const ordA = ordersDataMap[idA] || {};
        const ordB = ordersDataMap[idB] || {};

        const statusA = ordA.status || 'pending';
        const statusB = ordB.status || 'pending';

        // Hoàn thành (completed) luôn được đẩy xuống vị trí cuối cùng của bảng
        const isCompA = (statusA === 'completed') ? 1 : 0;
        const isCompB = (statusB === 'completed') ? 1 : 0;
        if (isCompA !== isCompB) return isCompA - isCompB;

        // Ưu tiên 1: Tháng phát hành mới nhất lên trên cùng (DESC)
        const timeA = parseIssueMonthTimestamp(ordA.issue_month, ordA.created_at);
        const timeB = parseIssueMonthTimestamp(ordB.issue_month, ordB.created_at);
        if (timeA !== timeB) return timeB - timeA;

        // Ưu tiên 2: Theo trạng thái (Đang in -> Chờ in -> Tạm dừng -> Hoàn thành)
        const rankA = getStatusSortRank(statusA);
        const rankB = getStatusSortRank(statusB);
        if (rankA !== rankB) return rankA - rankB;

        // Cùng tháng & cùng trạng thái: ID mới nhất lên trước
        return idB - idA;
    });

    const baseStt = <?= (int)$fromRecord ?> || 1;
    rows.forEach((tr, idx) => {
        tbody.appendChild(tr);
        const sttCell = tr.querySelector('td.text-muted');
        if (sttCell) sttCell.innerText = baseStt + idx;
    });
}

// CẬP NHẬT DÒNG TRÊN BẢNG SAU KHI IN
function updateTableRowAfterPrint(orderId, newPrinted, newRemaining, targetQty) {
    const newStatus = (newRemaining <= 0) ? 'completed' : 'in_progress';
    if (ordersDataMap[orderId]) {
        ordersDataMap[orderId].printed_qty = newPrinted;
        ordersDataMap[orderId].remaining_qty = newRemaining;
        ordersDataMap[orderId].status = newStatus;
    }

    const row = document.getElementById('row_order_' + orderId);
    if (!row) return;

    const rowChk = row.querySelector('.order-row-checkbox');
    if (rowChk) {
        rowChk.setAttribute('data-status', newStatus);
    }

    const cellPrinted   = row.querySelector('.cell-printed');
    const cellRemaining = row.querySelector('.cell-remaining');
    const progressBar   = row.querySelector('.cell-progressbar');
    const cellPct       = row.querySelector('.cell-pct');
    const cellStatus    = row.querySelector('.cell-status');

    if (cellPrinted) cellPrinted.innerText = Number(newPrinted).toLocaleString();
    if (cellRemaining) {
        cellRemaining.innerText = Number(newRemaining).toLocaleString();
        cellRemaining.className = 'text-end fw-bold cell-remaining ' + (newRemaining <= 0 ? 'text-success' : 'text-danger');
    }

    const target = parseInt(targetQty, 10);
    const pct = target > 0 ? Math.min(100, Math.round((newPrinted / target) * 100)) : 0;
    if (progressBar) {
        progressBar.style.width = pct + '%';
        progressBar.className = 'progress-bar cell-progressbar ' + (pct >= 100 ? 'bg-success' : 'bg-primary');
    }
    if (cellPct) cellPct.innerText = pct + '%';

    if (cellStatus) {
        if (newRemaining <= 0) {
            cellStatus.innerHTML = '<span class="badge bg-success">Hoàn thành</span>';
        } else {
            cellStatus.innerHTML = '<span class="badge bg-primary">Đang in</span>';
        }
    }

    // Tự động sắp xếp lại vị trí dòng trên bảng theo thứ tự ưu tiên mới
    sortOrdersTableRows();
}

// RENDER DANH SÁCH TEM THÙNG RA CẢ 2 VÙNG: PREVIEW VÀ STANDALONE PRINT ZONE
function renderPrintedLabels(labels) {
    console.group('[PRINT_DEBUG] QUY TRÌNH SINH VÀ RENDER TEM IN');
    console.log('[PRINT_DEBUG 1] Dữ liệu tem đầu vào nhận từ máy chủ:', labels);

    if (!Array.isArray(labels) || labels.length === 0) {
        console.warn('[PRINT_DEBUG 1] Không có dữ liệu tem để render!');
        console.groupEnd();
        return;
    }

    const currentSize = getActiveLabelSize();
    applyDynamicPrintPageStyle(currentSize);
    syncLabelSizeUI(currentSize);

    // 1. Vùng xem trước trong modal
    const previewContainer = document.getElementById('printable_labels_container');
    if (previewContainer) {
        previewContainer.className = 'label-preview-wrapper label-size-' + currentSize;
        previewContainer.innerHTML = '';
    }

    // 2. Vùng in độc lập gắn trực tiếp ở cấp cao nhất của document.body
    let standaloneZone = document.getElementById('print_standalone_zone');
    if (!standaloneZone) {
        standaloneZone = document.createElement('div');
        standaloneZone.id = 'print_standalone_zone';
        document.body.appendChild(standaloneZone);
    }
    standaloneZone.className = 'print-standalone-zone label-size-' + currentSize;
    standaloneZone.innerHTML = '';

    labels.forEach((item, index) => {
        // HTML của 1 tem chuẩn công nghiệp (thống nhất chung cho cả 65x30mm và 110x80mm)
        const labelHtml = `
            <div class="label-qr-col">
                <img src="${item.qr_base64}" alt="QR" loading="eager">
            </div>
            <div class="label-text-col">
                <div class="label-line-1">${escapeHtml(item.material_name)}</div>
                <div class="label-line-2">${item.qty} pcs ${item.weight ? '| ' + item.weight + ' Kg' : ''}</div>
                <div class="label-line-3">||</div>
                <div class="label-line-4">${escapeHtml(item.lot_no)} ${item.supplier ? '| ' + escapeHtml(item.supplier) : ''}</div>
                <div class="label-line-5">${escapeHtml(item.box_no)}</div>
            </div>
        `;

        // Đưa vào preview container trên màn hình
        if (previewContainer) {
            const previewDiv = document.createElement('div');
            previewDiv.className = 'box-label-item label-size-' + currentSize;
            previewDiv.innerHTML = labelHtml;
            previewContainer.appendChild(previewDiv);
        }

        // Đưa vào standalone print zone (dành riêng cho @media print, không bị modal ẩn)
        const printDiv = document.createElement('div');
        printDiv.className = 'box-label-item label-size-' + currentSize;
        printDiv.innerHTML = labelHtml;
        standaloneZone.appendChild(printDiv);
    });

    console.log('[PRINT_DEBUG 2] Render HTML hoàn tất:');
    console.log(' - Preview container nodes:', previewContainer ? previewContainer.children.length : 0);
    console.log(' - Standalone print zone nodes:', standaloneZone.children.length);
    console.log(' - Mẫu HTML tem đầu tiên:', standaloneZone.firstElementChild ? standaloneZone.firstElementChild.outerHTML : '');
    console.groupEnd();
}

// TỰ ĐỘNG GỬI LỆNH IN RA MÁY IN SAU KHI TẤT CẢ ẢNH QR ĐÃ NẠP SẴN SÀNG
function autoDispatchPrint() {
    console.group('[PRINT_DEBUG] KIỂM TRA ẢNH QR & GỬI LỆNH IN');
    const standaloneZone = document.getElementById('print_standalone_zone');
    const previewContainer = document.getElementById('printable_labels_container');

    const targetZone = standaloneZone || previewContainer;
    if (!targetZone) {
        console.error('[PRINT_DEBUG 3] Không tìm thấy vùng in tem!');
        console.groupEnd();
        return;
    }

    const images = Array.from(targetZone.querySelectorAll('img'));
    console.log('[PRINT_DEBUG 3] Tổng số ảnh QR cần kiểm tra:', images.length);

    if (images.length === 0) {
        console.warn('[PRINT_DEBUG 3] Không tìm thấy thẻ img QR nào trong vùng in!');
        console.groupEnd();
        applyDynamicPrintPageStyle(getActiveLabelSize());
        window.print();
        return;
    }

    const imagePromises = images.map((img, idx) => {
        const isBase64 = img.src && img.src.startsWith('data:image/');
        console.log(` - QR #${idx + 1}: Base64 Valid=${isBase64}, Length=${img.src ? img.src.length : 0}, Complete=${img.complete}`);

        if (img.complete && img.naturalHeight !== 0) {
            return Promise.resolve();
        }

        if (img.decode) {
            return img.decode().catch(err => {
                console.warn(` - QR #${idx + 1} decode warning:`, err);
                return Promise.resolve();
            });
        }

        return new Promise(resolve => {
            img.onload = () => resolve();
            img.onerror = () => {
                console.error(` - QR #${idx + 1} load error!`);
                resolve();
            };
        });
    });

    Promise.all(imagePromises).then(() => {
        console.log('[PRINT_DEBUG 3] Toàn bộ ảnh QR đã nạp sẵn sàng 100%!');
        console.log('[PRINT_DEBUG 4] Trạng thái DOM trước khi gọi print:');
        console.log(' - Standalone Zone tồn tại:', !!standaloneZone);
        console.log(' - Standalone Zone số tem:', standaloneZone ? standaloneZone.children.length : 0);
        console.log('[PRINT_DEBUG 5] Thời điểm kích hoạt window.print():', new Date().toISOString());
        console.groupEnd();

        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                setTimeout(() => {
                    applyDynamicPrintPageStyle(getActiveLabelSize());
                    window.print();
                }, 120);
            });
        });
    });
}

function triggerDirectPrint() {
    console.log('[PRINT_DEBUG] Kiểm tra ảnh QR hoàn tất trước khi kích hoạt window.print().');
    applyDynamicPrintPageStyle(getActiveLabelSize());
    autoDispatchPrint();
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, function(m) {
        return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[m];
    });
}

// =======================================================
// CHỌN NHIỀU CHỈ THỊ & TẠM DỪNG IN HÀNG LOẠT (BATCH PAUSE)
// =======================================================
function updateBatchSelectionState() {
    const rowCheckboxes = Array.from(document.querySelectorAll('.order-row-checkbox:not(:disabled)'));
    const checkedBoxes  = rowCheckboxes.filter(cb => cb.checked);
    const countEl       = document.getElementById('selected_orders_count');
    const btnBatch      = document.getElementById('btn_batch_pause_selected');
    const selectAllChk  = document.getElementById('chk_select_all_orders');

    if (countEl) countEl.innerText = checkedBoxes.length;
    if (btnBatch) btnBatch.disabled = (checkedBoxes.length === 0);
    if (selectAllChk) {
        selectAllChk.checked = (rowCheckboxes.length > 0 && checkedBoxes.length === rowCheckboxes.length);
        selectAllChk.indeterminate = (checkedBoxes.length > 0 && checkedBoxes.length < rowCheckboxes.length);
    }
}

const chkSelectAllOrders = document.getElementById('chk_select_all_orders');
if (chkSelectAllOrders) {
    chkSelectAllOrders.addEventListener('change', function() {
        const isChecked = this.checked;
        document.querySelectorAll('.order-row-checkbox:not(:disabled)').forEach(cb => {
            cb.checked = isChecked;
        });
        updateBatchSelectionState();
    });
}

document.querySelectorAll('.order-row-checkbox').forEach(cb => {
    cb.addEventListener('change', updateBatchSelectionState);
});

function executeBatchPauseSelected() {
    const checkedBoxes = Array.from(document.querySelectorAll('.order-row-checkbox:not(:disabled):checked'));
    if (checkedBoxes.length === 0) {
        alert('Vui lòng tích chọn ít nhất 1 chỉ thị để tạm dừng in!');
        return;
    }

    const slipList = checkedBoxes.map(cb => cb.getAttribute('data-slip')).filter(Boolean);
    const previewSlips = slipList.slice(0, 8).join(', ') + (slipList.length > 8 ? ` ... (+${slipList.length - 8} chỉ thị)` : '');

    if (!window.confirm(`Xác nhận TẠM DỪNG in tem cùng lúc cho ${checkedBoxes.length} chỉ thị đã chọn?\n(${previewSlips})`)) {
        return;
    }

    const btn = document.getElementById('btn_batch_pause_selected');
    if (btn) btn.disabled = true;

    const fd = new FormData();
    fd.append('action', 'batch_pause');
    checkedBoxes.forEach(cb => fd.append('order_ids[]', cb.value));

    fetch('ajax/toggle_status.php', {
        method: 'POST',
        body: fd
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            if (btn) btn.disabled = false;
            alert('Lỗi: ' + data.message);
        }
    })
    .catch(err => {
        if (btn) btn.disabled = false;
        alert('Lỗi kết nối: ' + err.message);
    });
}

function executeBatchPauseFiltered() {
    const totalFiltered = <?= (int)$totalRecords ?>;
    if (totalFiltered <= 0) return;

    if (!window.confirm(`Bạn có chắc chắn muốn TẠM DỪNG TẤT CẢ các chỉ thị theo bộ lọc hiện tại (${totalFiltered} chỉ thị) cùng lúc không?`)) {
        return;
    }

    const btn = document.getElementById('btn_batch_pause_filtered');
    if (btn) btn.disabled = true;

    const fd = new FormData();
    fd.append('action', 'batch_pause');
    fd.append('apply_filtered', '1');
    fd.append('filter_k', <?= json_encode($searchKeyword, JSON_UNESCAPED_UNICODE) ?>);
    fd.append('filter_status', <?= json_encode($filterStatus, JSON_UNESCAPED_UNICODE) ?>);
    fd.append('filter_month', <?= json_encode($filterMonth, JSON_UNESCAPED_UNICODE) ?>);

    fetch('ajax/toggle_status.php', {
        method: 'POST',
        body: fd
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            if (btn) btn.disabled = false;
            alert('Lỗi: ' + data.message);
        }
    })
    .catch(err => {
        if (btn) btn.disabled = false;
        alert('Lỗi kết nối: ' + err.message);
    });
}

// =======================================================
// QUẢN TRỊ: SỬA CHỈ THỊ & TẠM DỪNG / RESUME
// =======================================================
function openEditModal(order) {
    document.getElementById('edit_order_id').value = order.id;
    document.getElementById('edit_slip_code').value = order.slip_code;
    document.getElementById('edit_order_code').value = order.order_code;
    document.getElementById('edit_product_code').value = order.product_code;
    const editBoxTypeEl = document.getElementById('edit_box_type');
    if (editBoxTypeEl) editBoxTypeEl.value = order.box_type || '1';
    document.getElementById('edit_issue_month').value = order.issue_month || '';
    document.getElementById('edit_target_qty').value = order.target_qty;
    document.getElementById('edit_pack_qty').value = order.pack_qty;
    document.getElementById('edit_supplier').value = order.supplier || '';
    document.getElementById('edit_weight').value = order.weight_per_box > 0 ? order.weight_per_box : '';
    document.getElementById('edit_note').value = order.note || '';
    document.getElementById('edit_printed_disp').innerText = Number(order.printed_qty).toLocaleString();

    openModal('modal_edit_order');
}

function confirmEditSubmit() {
    const slip = document.getElementById('edit_slip_code').value;
    return confirm(`Xác nhận lưu các thông tin điều chỉnh cho chỉ thị [${slip}]?`);
}

function requestPause(orderId, slipCode) {
    document.getElementById('toggle_order_id').value = orderId;
    document.getElementById('toggle_modal_title').innerText = '⏸️ Tạm Dừng In Tem';
    document.getElementById('toggle_modal_title').className = 'modal-title fw-bold text-warning';
    document.getElementById('toggle_modal_message').innerHTML = `
        <div class="alert alert-warning mb-3">
            <strong>CẢNH BÁO TẠM DỪNG:</strong><br>
            Bạn có chắc chắn muốn <strong>TẠM DỪNG</strong> in tem cho chỉ thị <strong>${escapeHtml(slipCode)}</strong>?
        </div>
        <p class="text-muted small mb-0">
            Khi tạm dừng, mọi thao tác in tem thùng tại xưởng cho chỉ thị này sẽ bị khóa cho đến khi được mở lại.
        </p>
    `;
    const btn = document.getElementById('toggle_submit_btn');
    btn.className = 'btn btn-warning fw-bold';
    btn.innerText = '⏸️ Xác Nhận Tạm Dừng';
    openModal('modal_confirm_toggle');
}

function requestResume(orderId, slipCode) {
    document.getElementById('toggle_order_id').value = orderId;
    document.getElementById('toggle_modal_title').innerText = '▶️ Kích Hoạt Lại In Tem';
    document.getElementById('toggle_modal_title').className = 'modal-title fw-bold text-success';
    document.getElementById('toggle_modal_message').innerHTML = `
        <div class="alert alert-success mb-3">
            <strong>KÍCH HOẠT LẠI:</strong><br>
            Xác nhận <strong>TIẾP TỤC</strong> cho phép in tem cho chỉ thị <strong>${escapeHtml(slipCode)}</strong>?
        </div>
        <p class="text-muted small mb-0">
            Chỉ thị sẽ được mở khóa ngay lập tức và sẵn sàng cho công nhân in tem thùng theo định mức.
        </p>
    `;
    const btn = document.getElementById('toggle_submit_btn');
    btn.className = 'btn btn-success fw-bold';
    btn.innerText = '▶️ Kích Hoạt Lại';
    openModal('modal_confirm_toggle');
}

// AUTO MAP SPECS KHI SỬA CHỈ THỊ (EDIT ORDER)
function autoSyncEditSpecs() {
    const code = document.getElementById('edit_product_code')?.value.trim();
    const box = document.getElementById('edit_box_type')?.value || '1';
    if (!code) return;
    const spec = allSpecsMapByKey[code + '___' + box] || allSpecsMap[code];
    if (spec) {
        if (spec.pack_qty) document.getElementById('edit_pack_qty').value = spec.pack_qty;
        if (spec.supplier) document.getElementById('edit_supplier').value = spec.supplier;
        if (spec.weight_per_box) document.getElementById('edit_weight').value = spec.weight_per_box;
    }
}
const editBoxTypeSelect = document.getElementById('edit_box_type');
if (editBoxTypeSelect) editBoxTypeSelect.addEventListener('change', autoSyncEditSpecs);
const editProdCodeInput = document.getElementById('edit_product_code');
if (editProdCodeInput) editProdCodeInput.addEventListener('change', autoSyncEditSpecs);

// AUTO MAP SPECS KHI NHẬP PRODUCT_CODE HOẶC CHỌN BOX_TYPE TRONG FORM THÊM MỚI (ADD ORDER)
function autoSyncAddSpecs() {
    const code = document.getElementById('add_product_code')?.value.trim();
    const box = document.getElementById('add_box_type')?.value || '1';
    if (!code) return;
    const spec = allSpecsMapByKey[code + '___' + box] || allSpecsMap[code];
    if (spec) {
        const pQty = document.getElementById('add_pack_qty');
        const supp = document.getElementById('add_supplier');
        const wgt  = document.getElementById('add_weight');
        if (pQty) pQty.value = spec.pack_qty || 1;
        if (supp) supp.value = spec.supplier || '';
        if (wgt)  wgt.value  = spec.weight_per_box > 0 ? spec.weight_per_box : '';
    }
}
const addProdInput = document.getElementById('add_product_code');
if (addProdInput) addProdInput.addEventListener('change', autoSyncAddSpecs);
const addBoxInput = document.getElementById('add_box_type');
if (addBoxInput) addBoxInput.addEventListener('change', autoSyncAddSpecs);

// =======================================================
// CÁC HÀM TIỆN ÍCH QUẢN TRỊ & PHÂN TRANG
// =======================================================
function requestDeleteOrder(orderId, slipCode, orderCode) {
    const inpId = document.getElementById('delete_order_id');
    if (inpId) inpId.value = orderId;
    const slipEl = document.getElementById('del_disp_slip');
    if (slipEl) slipEl.innerText = slipCode;
    const orderEl = document.getElementById('del_disp_order');
    if (orderEl) orderEl.innerText = orderCode;
    openModal('modal_confirm_delete_order');
}

function changePageLimit(newLimit) {
    const url = new URL(window.location.href);
    url.searchParams.set('limit', newLimit);
    url.searchParams.set('page', 1);
    window.location.href = url.toString();
}

// =======================================================
// GẮN TẤT CẢ CÁC HÀM XỬ LÝ LÊN WINDOW TOÀN CỤC (GLOBAL EXPORTS)
// =======================================================
window.openModal                  = openModal;
window.closeModal                 = closeModal;
window.openOffcanvasPrint         = openOffcanvasPrint;
window.openOffcanvasPrintById     = openOffcanvasPrintById;
window.openEditModalById          = openEditModalById;
window.openEditModal              = openEditModal;
window.confirmEditSubmit          = confirmEditSubmit;
window.resetOffcanvasState        = resetOffcanvasState;
window.loadOrderIntoOffcanvas     = loadOrderIntoOffcanvas;
window.onOffcanvasBoxTypeChange   = onOffcanvasBoxTypeChange;
window.searchOrderOffcanvas       = searchOrderOffcanvas;
window.recalculateOffcanvas       = recalculateOffcanvas;
window.updateTableRowAfterPrint   = updateTableRowAfterPrint;
window.renderPrintedLabels        = renderPrintedLabels;
window.triggerDirectPrint         = triggerDirectPrint;
window.requestPause               = requestPause;
window.requestResume              = requestResume;
window.submitAdminApproval        = submitAdminApproval;
window.requestDeleteOrder         = requestDeleteOrder;
window.changePageLimit            = changePageLimit;
window.onDefaultPrinterChange     = onDefaultPrinterChange;
window.onLabelSizeChange          = onLabelSizeChange;
window.getActiveLabelSize         = getActiveLabelSize;
window.savePrinterFromModal       = savePrinterFromModal;
window.refreshConnectedPrinters       = refreshConnectedPrinters;
window.promptAddClientCustomPrinter = promptAddClientCustomPrinter;
window.removeActiveSavedPrinter    = removeActiveSavedPrinter;
window.resetAllPrinterConfig        = resetAllPrinterConfig;
window.logPrintOperation            = logPrintOperation;
window.activateBrowserPrintFallback   = activateBrowserPrintFallback;
window.openPrinterSetupGuideModal     = openPrinterSetupGuideModal;
window.testLocalhostPrintServiceFromGuide = testLocalhostPrintServiceFromGuide;
window.activateBrowserPrintFallback   = activateBrowserPrintFallback;
window.openPrinterSetupGuideModal     = openPrinterSetupGuideModal;
window.testLocalhostPrintServiceFromGuide = testLocalhostPrintServiceFromGuide;
window.executeBatchPauseSelected  = executeBatchPauseSelected;
window.executeBatchPauseFiltered  = executeBatchPauseFiltered;

// =======================================================
// AJAX SUBMISSIONS DÀNH CHO CÁC MODAL THAO TÁC CHỈ THỊ (CRUD & STATUS)
// =======================================================

// 1. AJAX CHỈNH SỬA CHỈ THỊ (EDIT ORDER)
const formEditOrder = document.getElementById('form_edit_order');
if (formEditOrder) {
    formEditOrder.addEventListener('submit', function(e) {
        e.preventDefault();
        if (!confirmEditSubmit()) return;

        const submitBtn = this.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerText = '⏳ Đang lưu...';
        }

        const formData = new FormData(this);
        fetch('ajax/edit_order.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerText = '💾 Lưu Thay Đổi';
            }
            if (data.success) {
                closeModal('modal_edit_order');
                alert(data.message);
                location.reload();
            } else {
                alert('Lỗi cập nhật: ' + data.message);
            }
        })
        .catch(err => {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerText = '💾 Lưu Thay Đổi';
            }
            alert('Lỗi kết nối: ' + err.message);
        });
    });
}

// 2. AJAX THÊM MỚI CHỈ THỊ (ADD ORDER)
const formAddOrder = document.getElementById('form_add_order');
if (formAddOrder) {
    formAddOrder.addEventListener('submit', function(e) {
        e.preventDefault();
        const submitBtn = this.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerText = '⏳ Đang lưu...';
        }

        const formData = new FormData(this);
        fetch('ajax/add_order.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerText = '💾 Lưu Chỉ Thị';
            }
            if (data.success) {
                closeModal('modal_add_order');
                alert(data.message);
                location.reload();
            } else {
                alert('Lỗi thêm mới: ' + data.message);
            }
        })
        .catch(err => {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerText = '💾 Lưu Chỉ Thị';
            }
            alert('Lỗi kết nối: ' + err.message);
        });
    });
}

// 3. AJAX TẠM DỪNG / TIẾP TỤC IN (TOGGLE PAUSE / RESUME)
const formToggleOrder = document.getElementById('form_toggle_order');
if (formToggleOrder) {
    formToggleOrder.addEventListener('submit', function(e) {
        e.preventDefault();
        const submitBtn = document.getElementById('toggle_submit_btn');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerText = '⏳ Đang xử lý...';
        }

        const formData = new FormData(this);
        fetch('ajax/toggle_status.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (submitBtn) submitBtn.disabled = false;
            if (data.success) {
                closeModal('modal_confirm_toggle');
                alert(data.message);
                location.reload();
            } else {
                alert('Lỗi: ' + data.message);
            }
        })
        .catch(err => {
            if (submitBtn) submitBtn.disabled = false;
            alert('Lỗi kết nối: ' + err.message);
        });
    });
}

// 4. AJAX IMPORT FILE CSV (IMPORT ORDERS)
const formImportOrders = document.getElementById('form_import_orders');
if (formImportOrders) {
    formImportOrders.addEventListener('submit', function(e) {
        e.preventDefault();
        const submitBtn = this.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerText = '⏳ Đang tải lên & nạp dữ liệu...';
        }

        const formData = new FormData(this);
        fetch('ajax/import_orders.php', {
            method: 'POST',
            body: formData
        })
        .then(async response => {
            const httpStatus = response.status;
            const contentType = response.headers.get('content-type') || '';
            const responseText = await response.text();

            // Log detailed response information for debugging
            console.log('[Import CTSX] HTTP Status:', httpStatus);
            console.log('[Import CTSX] Content-Type:', contentType);
            console.log('[Import CTSX] Response Body:', responseText);

            // Validate HTTP Status and Content-Type before parsing JSON
            if (!response.ok || !contentType.toLowerCase().includes('application/json')) {
                console.error('[Import CTSX] Server returned non-JSON response:', { httpStatus, contentType, responseText });
                throw new Error('INVALID_JSON_RESPONSE');
            }

            try {
                return JSON.parse(responseText);
            } catch (parseError) {
                console.error('[Import CTSX] JSON parse error:', parseError, responseText);
                throw new Error('INVALID_JSON_RESPONSE');
            }
        })
        .then(data => {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerText = 'Bắt Đầu Import';
            }
            if (data.message) {
                alert(data.message);
            } else if (data.errors && data.errors.length > 0) {
                alert('Lỗi nạp file:\n' + data.errors.join('\n'));
            } else {
                alert('Có lỗi xảy ra trong quá trình nạp file!');
            }

            if (data.success || data.inserted_count > 0 || data.updated_count > 0) {
                closeModal('modal_import_excel');
                location.reload();
            }
        })
        .catch(err => {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerText = 'Bắt Đầu Import';
            }
            if (err.message === 'INVALID_JSON_RESPONSE') {
                alert('Không thể xử lý dữ liệu import. Vui lòng kiểm tra log hệ thống.');
            } else {
                alert('Lỗi kết nối: ' + err.message);
            }
        });
    });
}

// 5. AJAX XÓA CHỈ THỊ SẢN XUẤT (DELETE ORDER)
const formDeleteOrder = document.getElementById('form_delete_order');
if (formDeleteOrder) {
    formDeleteOrder.addEventListener('submit', function(e) {
        e.preventDefault();
        const submitBtn = document.getElementById('btn_confirm_delete_submit');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Đang xóa...';
        }

        const formData = new FormData(this);
        fetch('ajax/delete_order.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerText = '🗑️ Xác Nhận Xóa Vĩnh Viễn';
            }
            if (data.success) {
                closeModal('modal_confirm_delete_order');
                alert(data.message);
                location.reload();
            } else {
                alert('Lỗi xóa chỉ thị: ' + data.message);
            }
        })
        .catch(err => {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerText = '🗑️ Xác Nhận Xóa Vĩnh Viễn';
            }
            alert('Lỗi kết nối: ' + err.message);
        });
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
