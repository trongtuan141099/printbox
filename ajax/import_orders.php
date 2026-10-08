<?php
/**
 * AJAX: IMPORT CHỈ THỊ SẢN XUẤT TỪ FILE CSV
 * Phân quyền: Editor & Admin
 * Hỗ trợ tự động nhận diện dấu phân cách (phẩy , hoặc chấm phẩy ;)
 * Tự động bỏ UTF-8 BOM, tự động map Quy cách & NCC từ bảng Specs
 * Khắc phục triệt để lỗi import và trả về kết quả JSON chi tiết
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
    echo json_encode(['success' => false, 'message' => 'Bạn không có quyền Import chỉ thị sản xuất!']);
    exit;
}

if (!isset($_FILES['file_upload']) || $_FILES['file_upload']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Vui lòng chọn file CSV để tải lên!']);
    exit;
}

$file = $_FILES['file_upload'];
$ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($ext, ['csv', 'txt'])) {
    echo json_encode([
        'success' => false, 
        'message' => 'Định dạng file không được hỗ trợ! Hệ thống chỉ chấp nhận file CSV UTF-8 (.csv hoặc .txt).'
    ]);
    exit;
}

$rawContent = file_get_contents($file['tmp_name']);
if ($rawContent === false || strlen(trim($rawContent)) === 0) {
    echo json_encode(['success' => false, 'message' => 'Nội dung file tải lên bị rỗng!']);
    exit;
}

// 1. Tự động loại bỏ UTF-8 BOM nếu có (\xEF\xBB\xBF)
if (substr($rawContent, 0, 3) === "\xEF\xBB\xBF") {
    $rawContent = substr($rawContent, 3);
}

// 2. Chuyển đổi mã hóa sang UTF-8 nếu cần (nhận diện Windows-1258, CP1252...)
$encoding = mb_detect_encoding($rawContent, ['UTF-8', 'ISO-8859-1', 'WINDOWS-1252', 'WINDOWS-1258'], true);
if ($encoding && $encoding !== 'UTF-8') {
    $rawContent = mb_convert_encoding($rawContent, 'UTF-8', $encoding);
}

// 3. Tự động nhận diện dấu phân cách CSV (Dấu phẩy , hoặc Chấm phẩy ;)
$lines = preg_split('/\r\n|\r|\n/', trim($rawContent));
$firstDataLine = $lines[0] ?? '';
$commaCount = substr_count($firstDataLine, ',');
$semiCount  = substr_count($firstDataLine, ';');
$delimiter  = ($semiCount > $commaCount) ? ';' : ',';

$pdo = getDbConnection();
$currentUser = getCurrentUser();

$successCount = 0;
$updatedCount = 0;
$skipCount    = 0;
$rowErrors    = [];

$tempHandle = fopen('php://memory', 'r+');
fwrite($tempHandle, $rawContent);
rewind($tempHandle);

$isFirstRow = true;
$rowIdx = 0;

$stmtInsert = $pdo->prepare("INSERT INTO `production_orders` 
    (`slip_code`, `order_code`, `product_code`, `issue_month`, `target_qty`, `printed_qty`, `remaining_qty`, `pack_qty`, `supplier`, `weight_per_box`, `status`, `note`, `created_by`, `created_at`) 
    VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 'pending', ?, ?, NOW())
    ON DUPLICATE KEY UPDATE 
        `order_code`     = VALUES(`order_code`),
        `product_code`   = VALUES(`product_code`),
        `issue_month`    = VALUES(`issue_month`),
        `target_qty`     = VALUES(`target_qty`),
        `remaining_qty`  = VALUES(`target_qty`) - `printed_qty`,
        `pack_qty`       = VALUES(`pack_qty`),
        `supplier`       = VALUES(`supplier`),
        `weight_per_box` = VALUES(`weight_per_box`),
        `note`           = VALUES(`note`),
        `updated_at`     = NOW()");

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

    $slipCode    = $c0;
    $orderCode   = $c1;
    $productCode = $c2;
    $issueMonth  = !empty($c3) ? $c3 : date('m/Y');
    $targetQty   = (int)$c4;
    $note        = $c5;

    // Kiểm tra dữ liệu hợp lệ tối thiểu
    if (empty($slipCode) || empty($orderCode) || empty($productCode) || $targetQty <= 0) {
        $skipCount++;
        $rowErrors[] = "Dòng {$rowIdx}: Thiếu thông tin bắt buộc hoặc số lượng <= 0 (Mã phiếu: '{$slipCode}')";
        continue;
    }

    // Tự động map Quy cách & NCC từ bảng Specs theo Mã sản phẩm
    $spec = getProductSpec($productCode, $pdo);
    $packQty  = (int)$spec['pack_qty'];
    $supplier = $spec['supplier'];
    $weight   = (float)$spec['weight_per_box'];

    try {
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

        $successCount++;
    } catch (PDOException $e) {
        $skipCount++;
        $rowErrors[] = "Dòng {$rowIdx} (Mã phiếu '{$slipCode}'): " . $e->getMessage();
    }
}

fclose($tempHandle);

echo json_encode([
    'success'       => ($successCount > 0),
    'message'       => "Đã xử lý xong: Nạp thành công {$successCount} chỉ thị! (Bỏ qua/lỗi: {$skipCount})",
    'success_count' => $successCount,
    'skip_count'    => $skipCount,
    'errors'        => array_slice($rowErrors, 0, 10)
]);

