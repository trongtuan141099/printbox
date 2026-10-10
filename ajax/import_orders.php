<?php
/**
 * AJAX: IMPORT CHỈ THỊ SẢN XUẤT TỪ FILE CSV
 * Phân quyền: Editor & Admin
 * Hỗ trợ tự động nhận diện dấu phân cách (phẩy , hoặc chấm phẩy ;)
 * Tự động bỏ UTF-8 BOM, kiểm tra ràng buộc Quy cách (Specs), và cơ chế UPSERT
 */

// Start output buffering to prevent unexpected notices/warnings from polluting JSON output
ob_start();

// Ensure clean JSON header
header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/functions.php';

    if (!isLoggedIn()) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Phiên làm việc đã hết hạn. Vui lòng đăng nhập lại!']);
        exit;
    }

    if (!canManageDirectives()) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Bạn không có quyền Import chỉ thị sản xuất!']);
        exit;
    }

    if (!isset($_FILES['file_upload']) || $_FILES['file_upload']['error'] !== UPLOAD_ERR_OK) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Vui lòng chọn file CSV để tải lên!']);
        exit;
    }

    $file = $_FILES['file_upload'];
    $fileName = $file['name'] ?? 'unknown.csv';
    $ext  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    if (!in_array($ext, ['csv', 'txt'])) {
        ob_clean();
        echo json_encode([
            'success' => false, 
            'message' => 'Định dạng file không được hỗ trợ! Hệ thống chỉ chấp nhận file CSV UTF-8 (.csv hoặc .txt).'
        ]);
        exit;
    }

    $rawContent = file_get_contents($file['tmp_name']);
    if ($rawContent === false || strlen(trim($rawContent)) === 0) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Nội dung file tải lên bị rỗng!']);
        exit;
    }

    // 1. Tự động loại bỏ UTF-8 BOM nếu có (\xEF\xBB\xBF)
    $hasBom = false;
    if (substr($rawContent, 0, 3) === "\xEF\xBB\xBF") {
        $hasBom = true;
        $rawContent = substr($rawContent, 3);
    }

    // 2. Chuyển đổi mã hóa an toàn sang UTF-8 nếu cần (tránh ValueError trên PHP 8.1+)
    $detectedEncoding = 'UTF-8';
    if (!mb_check_encoding($rawContent, 'UTF-8')) {
        $supportedEncodings = array_intersect(['UTF-8', 'Windows-1252', 'ISO-8859-1', 'ASCII'], mb_list_encodings());
        $detected = mb_detect_encoding($rawContent, $supportedEncodings, true);
        if ($detected && $detected !== 'UTF-8') {
            $detectedEncoding = $detected;
            $rawContent = mb_convert_encoding($rawContent, 'UTF-8', $detected);
        }
    }

    // 3. Tự động nhận diện dấu phân cách CSV (Dấu phẩy , hoặc Chấm phẩy ;)
    $lines = preg_split('/\r\n|\r|\n/', trim($rawContent));
    $firstDataLine = $lines[0] ?? '';
    $commaCount = substr_count($firstDataLine, ',');
    $semiCount  = substr_count($firstDataLine, ';');
    $delimiter  = ($semiCount > $commaCount) ? ';' : ',';

    // Log chi tiết tiến trình đọc file trước khi import
    $totalLines = count($lines);
    error_log("[Import CTSX] File: {$fileName} | Lines: {$totalLines} | Encoding: {$detectedEncoding} (BOM: " . ($hasBom ? 'YES' : 'NO') . ") | Delimiter: '{$delimiter}' | Header: '{$firstDataLine}'");

    set_time_limit(300);

    $pdo = getDbConnection();
    $currentUser = getCurrentUser();

    $totalDataRows = 0;
    $insertedCount = 0;
    $updatedCount  = 0;
    $errorCount    = 0;
    $rowErrors     = [];
    $missingSpecs  = []; // Mã sản phẩm chưa có quy cách: [product_code => true]

    $tempHandle = fopen('php://memory', 'r+');
    fwrite($tempHandle, $rawContent);
    rewind($tempHandle);

    $isFirstRow = true;
    $rowIdx = 0;

    // Prepared statements kiểm tra Quy cách & UPSERT Chỉ thị
    $stmtFindSpec  = $pdo->prepare("SELECT product_code, box_type, pack_qty, supplier, weight_per_box FROM `product_specs` WHERE `product_code` = ? ORDER BY `box_type` ASC LIMIT 1");
    $stmtFindOrder = $pdo->prepare("SELECT id, printed_qty, box_type FROM `production_orders` WHERE `slip_code` = ? LIMIT 1");

    $stmtInsert = $pdo->prepare("INSERT INTO `production_orders` 
        (`slip_code`, `order_code`, `product_code`, `box_type`, `issue_month`, `target_qty`, `printed_qty`, `remaining_qty`, `pack_qty`, `supplier`, `weight_per_box`, `status`, `note`, `created_by`, `created_at`) 
        VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 'pending', ?, ?, NOW())");

    $stmtUpdate = $pdo->prepare("UPDATE `production_orders` SET 
        `order_code`     = ?,
        `product_code`   = ?,
        `box_type`       = ?,
        `issue_month`    = ?,
        `target_qty`     = ?,
        `remaining_qty`  = ?,
        `pack_qty`       = ?,
        `supplier`       = ?,
        `weight_per_box` = ?,
        `note`           = ?,
        `updated_at`     = NOW()
        WHERE `id` = ?");

    // Dùng transaction tối ưu hiệu năng và an toàn dữ liệu
    $pdo->beginTransaction();

    while (($data = fgetcsv($tempHandle, 2000, $delimiter)) !== false) {
        $rowIdx++;
        
        // Bỏ qua dòng trống
        if (empty($data) || (count($data) === 1 && trim($data[0]) === '')) {
            continue;
        }

        $c0 = trim($data[0] ?? '');
        $c1 = trim($data[1] ?? '');
        $c2 = trim($data[2] ?? '');
        $c3 = trim($data[3] ?? '');
        $c4 = trim($data[4] ?? '');
        $c5 = trim($data[5] ?? '');

        // Kiểm tra và bỏ qua dòng tiêu đề
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

        // 1. Kiểm tra dữ liệu hợp lệ tối thiểu
        if (empty($slipCode) || empty($orderCode) || empty($productCode) || $targetQty <= 0) {
            $errorCount++;
            $errorMsg = "Dòng {$rowIdx}: Thiếu thông tin bắt buộc hoặc số lượng <= 0 (Mã phiếu: '{$slipCode}')";
            $rowErrors[] = $errorMsg;
            if (count($rowErrors) === 1) {
                error_log("[Import CTSX] Validation error: {$errorMsg}");
            }
            continue;
        }

        // 2. KIỂM TRA ĐỐI CHIẾU MÃ SẢN PHẨM VỚI DỮ LIỆU QUY CÁCH (SPECS)
        $stmtFindSpec->execute([$productCode]);
        $spec = $stmtFindSpec->fetch(PDO::FETCH_ASSOC);

        if (!$spec) {
            // Chưa tồn tại Quy Cách -> Không cho phép tạo/cập nhật chỉ thị
            $errorCount++;
            $missingSpecs[$productCode] = true;
            $errorMsg = "Dòng {$rowIdx}: Chưa thiết lập quy cách cho sản phẩm [{$productCode}]. Vui lòng tạo quy cách trước khi import chỉ thị sản xuất.";
            $rowErrors[] = $errorMsg;
            if (count($missingSpecs) === 1) {
                error_log("[Import CTSX] Missing spec error: {$errorMsg}");
            }
            continue;
        }

        // Đã có quy cách -> Lấy thông số từ danh mục Specs
        $boxType  = $spec['box_type'] ?? '1';
        $packQty  = (int)$spec['pack_qty'];
        $supplier = $spec['supplier'] ?? '';
        $weight   = (float)$spec['weight_per_box'];

        // 3. XỬ LÝ UPSERT THEO KHÓA DUY NHẤT (slip_code)
        $stmtFindOrder->execute([$slipCode]);
        $existingOrder = $stmtFindOrder->fetch(PDO::FETCH_ASSOC);

        try {
            if ($existingOrder) {
                // ĐÃ TỒN TẠI -> UPDATE DỮ LIỆU MỚI NHẤT
                $orderId    = (int)$existingOrder['id'];
                $printedQty = (int)$existingOrder['printed_qty'];
                $remainQty  = max(0, $targetQty - $printedQty);
                $finalBoxType = !empty($existingOrder['box_type']) ? $existingOrder['box_type'] : $boxType;

                $stmtUpdate->execute([
                    $orderCode,
                    $productCode,
                    $finalBoxType,
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
                // CHƯA TỒN TẠI -> INSERT MỚI
                $stmtInsert->execute([
                    $slipCode,
                    $orderCode,
                    $productCode,
                    $boxType,
                    $issueMonth,
                    $targetQty,
                    $targetQty, // remaining = target
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
            $errorMsg = "Dòng {$rowIdx} (Mã phiếu '{$slipCode}'): " . $e->getMessage();
            $rowErrors[] = $errorMsg;
            if (count($rowErrors) === 1) {
                error_log("[Import CTSX] DB error: {$errorMsg}");
            }
        }
    }

    $pdo->commit();
    fclose($tempHandle);

    $missingList = array_keys($missingSpecs);

    // Xây dựng thông báo tổng hợp theo đúng mẫu yêu cầu
    $msgLines = [
        "Import hoàn tất",
        "Tổng số dòng: {$totalDataRows}",
        "Thêm mới: {$insertedCount}",
        "Cập nhật: {$updatedCount}",
        "Lỗi: {$errorCount}"
    ];

    if (!empty($missingList)) {
        $msgLines[] = "";
        $msgLines[] = "Sản phẩm chưa có quy cách:";
        foreach ($missingList as $mp) {
            $msgLines[] = "- {$mp}";
        }
    }

    $summaryMessage = implode("\n", $msgLines);

    // Clean buffer before outputting JSON
    ob_clean();
    echo json_encode([
        'success'        => ($insertedCount + $updatedCount > 0),
        'message'        => $summaryMessage,
        'total_rows'     => $totalDataRows,
        'inserted_count' => $insertedCount,
        'updated_count'  => $updatedCount,
        'error_count'    => $errorCount,
        'missing_specs'  => $missingList,
        'errors'         => array_slice($rowErrors, 0, 15)
    ]);
    exit;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("[Import CTSX Fatal Error] " . $e->getMessage() . "\nStack trace:\n" . $e->getTraceAsString());
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Lỗi xử lý file Import: ' . $e->getMessage(),
        'trace'   => $e->getTraceAsString()
    ]);
    exit;
}
