<?php
/**
 * QUẢN LÝ QUY CÁCH ĐÓNG GÓI SẢN PHẨM RIÊNG BIỆT (PRODUCT SPECS)
 * Nơi định nghĩa quy cách con/thùng và nhà cung cấp tự động cho từng mã sản phẩm
 */
ob_start();
$pageTitle = "Quy Cách Đóng Gói Sản Phẩm";
require_once __DIR__ . '/includes/header.php';
requireRole(['admin', 'editor']);

$pdo = getDbConnection();

// THÊM HOẶC CẬP NHẬT QUY CÁCH
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_spec') {
    $productCode = trim($_POST['product_code'] ?? '');
    $boxType     = trim($_POST['box_type'] ?? '1/2');
    if ($boxType === '') $boxType = '1/2';
    $packQty     = (int)($_POST['pack_qty'] ?? 1);
    $supplier    = trim($_POST['supplier'] ?? '');
    $weight      = (float)($_POST['weight_per_box'] ?? 0);
    $unit        = trim($_POST['unit'] ?? 'pcs');
    $description = trim($_POST['description'] ?? '');

    if (empty($productCode) || $packQty <= 0) {
        setFlash('danger', 'Vui lòng nhập Mã sản phẩm và Quy cách đóng gói (> 0)!');
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO `product_specs` 
                (`product_code`, `box_type`, `pack_qty`, `supplier`, `weight_per_box`, `unit`, `description`, `created_at`) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    `pack_qty` = VALUES(`pack_qty`),
                    `supplier` = VALUES(`supplier`),
                    `weight_per_box` = VALUES(`weight_per_box`),
                    `unit` = VALUES(`unit`),
                    `description` = VALUES(`description`),
                    `updated_at` = NOW()");
            $stmt->execute([$productCode, $boxType, $packQty, $supplier, $weight, $unit, $description]);
            setFlash('success', "Đã lưu quy cách cho mã sản phẩm: {$productCode} (Thùng loại {$boxType}: {$packQty} con/thùng)!");
            header("Location: specs.php");
            exit;
        } catch (PDOException $e) {
            setFlash('danger', 'Lỗi CSDL: ' . $e->getMessage());
        }
    }
}

// XỬ LÝ IMPORT CSV QUY CÁCH ĐÓNG GÓI
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_specs') {
    $isAjax = (
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
        (isset($_POST['ajax']) && $_POST['ajax'] == '1') ||
        (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
    );

    try {
        if (!isset($_FILES['file_upload']) || $_FILES['file_upload']['error'] !== UPLOAD_ERR_OK) {
            $msg = 'Vui lòng chọn file CSV quy cách hợp lệ để tải lên!';
            if ($isAjax) {
                ob_clean();
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => $msg]);
                exit;
            }
            setFlash('danger', $msg);
            header("Location: specs.php");
            exit;
        }

        $file = $_FILES['file_upload'];
        $fileName = $file['name'] ?? 'unknown.csv';
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (!in_array($ext, ['csv', 'txt'])) {
            $msg = 'Hệ thống chỉ hỗ trợ file định dạng CSV (.csv, .txt UTF-8)!';
            if ($isAjax) {
                ob_clean();
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => $msg]);
                exit;
            }
            setFlash('warning', $msg);
            header("Location: specs.php");
            exit;
        }

        $rawContent = file_get_contents($file['tmp_name']);
        if ($rawContent === false || strlen(trim($rawContent)) === 0) {
            $msg = 'Nội dung file CSV tải lên bị rỗng!';
            if ($isAjax) {
                ob_clean();
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => $msg]);
                exit;
            }
            setFlash('danger', $msg);
            header("Location: specs.php");
            exit;
        }

        // 1. Tự động loại bỏ UTF-8 BOM nếu có (\xEF\xBB\xBF)
        $hasBom = false;
        if (substr($rawContent, 0, 3) === "\xEF\xBB\xBF") {
            $hasBom = true;
            $rawContent = substr($rawContent, 3);
        }

        // 2. Chuẩn hóa cơ chế Detect Encoding an toàn (tránh ValueError trên PHP 8.1+)
        $detectedEncoding = 'UTF-8';
        if (!mb_check_encoding($rawContent, 'UTF-8')) {
            $supportedEncodings = array_intersect(['UTF-8', 'Windows-1252', 'ISO-8859-1', 'ASCII'], mb_list_encodings());
            $detected = mb_detect_encoding($rawContent, $supportedEncodings, true);
            if ($detected && $detected !== 'UTF-8') {
                $detectedEncoding = $detected;
                $rawContent = mb_convert_encoding($rawContent, 'UTF-8', $detected);
            }
        }

        // 3. Nhận diện dấu phân cách , hoặc ;
        $lines = preg_split('/\r\n|\r|\n/', trim($rawContent));
        $firstLine = $lines[0] ?? '';
        $commaCount = substr_count($firstLine, ',');
        $semiCount  = substr_count($firstLine, ';');
        $delimiter  = ($semiCount > $commaCount) ? ';' : ',';

        // Ghi log chi tiết tiến trình đọc file
        $totalLines = count($lines);
        error_log("[Import Specs] File: {$fileName} | Lines: {$totalLines} | Encoding: {$detectedEncoding} (BOM: " . ($hasBom ? 'YES' : 'NO') . ") | Delimiter: '{$delimiter}' | Header: '{$firstLine}'");

        set_time_limit(300);

        $tempHandle = fopen('php://memory', 'r+');
        fwrite($tempHandle, $rawContent);
        rewind($tempHandle);

        $insertedCount = 0;
        $updatedCount  = 0;
        $skipCount     = 0;
        $isFirstRow    = true;
        $rowIdx        = 0;
        $rowErrors     = [];

        $stmtFindSpec = $pdo->prepare("SELECT `id` FROM `product_specs` WHERE `product_code` = ? AND `box_type` = ? LIMIT 1");
        $stmtUpdate   = $pdo->prepare("UPDATE `product_specs` SET 
            `pack_qty` = ?, 
            `supplier` = ?, 
            `weight_per_box` = ?, 
            `unit` = ?, 
            `description` = ?, 
            `updated_at` = NOW() 
            WHERE `id` = ?");
        $stmtInsert   = $pdo->prepare("INSERT INTO `product_specs` 
            (`product_code`, `box_type`, `pack_qty`, `supplier`, `weight_per_box`, `unit`, `description`, `created_at`) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");

        $pdo->beginTransaction();

        while (($data = fgetcsv($tempHandle, 2000, $delimiter)) !== false) {
            $rowIdx++;
            if (empty($data) || (count($data) === 1 && trim($data[0]) === '')) continue;

            $colCount = count($data);
            $c0 = trim($data[0] ?? ''); // Cột 0: Mã SP

            if ($isFirstRow) {
                $isFirstRow = false;
                $lowerC0 = mb_strtolower($c0, 'UTF-8');
                if (str_contains($lowerC0, 'mã') || str_contains($lowerC0, 'product')) {
                    continue;
                }
            }

            // Nhận diện cấu trúc 7 cột (có box_type) hoặc 6 cột (cũ, không có box_type)
            if ($colCount >= 7) {
                // Định dạng 7 cột: Mã SP, Loại thùng, Quy cách, Nhà cung cấp, Trọng lượng, Đơn vị, Mô tả
                $productCode = $c0;
                $boxType     = trim($data[1] ?? '1/2');
                if ($boxType === '') $boxType = '1/2';
                $packQty     = (int)($data[2] ?? 0);
                $supplier    = trim($data[3] ?? '');
                $weight      = is_numeric($data[4] ?? '') ? (float)$data[4] : 0.0;
                $unit        = !empty(trim($data[5] ?? '')) ? trim($data[5]) : 'pcs';
                $desc        = trim($data[6] ?? '');
            } else {
                // Định dạng 6 cột cũ: Mã SP, Quy cách, Nhà cung cấp, Trọng lượng, Đơn vị, Mô tả
                $productCode = $c0;
                $boxType     = '1';
                $packQty     = (int)($data[1] ?? 0);
                $supplier    = trim($data[2] ?? '');
                $weight      = is_numeric($data[3] ?? '') ? (float)$data[3] : 0.0;
                $unit        = !empty(trim($data[4] ?? '')) ? trim($data[4]) : 'pcs';
                $desc        = trim($data[5] ?? '');
            }

            if (!empty($productCode) && $packQty > 0) {
                $stmtFindSpec->execute([$productCode, $boxType]);
                $existing = $stmtFindSpec->fetch(PDO::FETCH_ASSOC);

                if ($existing) {
                    $stmtUpdate->execute([$packQty, $supplier, $weight, $unit, $desc, $existing['id']]);
                    $updatedCount++;
                } else {
                    $stmtInsert->execute([$productCode, $boxType, $packQty, $supplier, $weight, $unit, $desc]);
                    $insertedCount++;
                }
            } else {
                $skipCount++;
                $errorMsg = "Dòng {$rowIdx}: Thiếu thông tin Mã SP hoặc Quy cách <= 0 (Mã SP: '{$productCode}')";
                $rowErrors[] = $errorMsg;
                if (count($rowErrors) === 1) {
                    error_log("[Import Specs] First row validation error: {$errorMsg}");
                }
            }
        }

        $pdo->commit();
        fclose($tempHandle);

        $totalDataRows = $insertedCount + $updatedCount + $skipCount;
        $msgLines = [
            "Import hoàn tất",
            "Tổng số dòng: {$totalDataRows}",
            "Thêm mới: {$insertedCount}",
            "Cập nhật: {$updatedCount}",
            "Lỗi/Bỏ qua: {$skipCount}"
        ];
        $successMsg = implode("\n", $msgLines);

        if ($isAjax) {
            ob_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success'        => ($insertedCount + $updatedCount > 0),
                'message'        => $successMsg,
                'total_rows'     => $totalDataRows,
                'inserted_count' => $insertedCount,
                'updated_count'  => $updatedCount,
                'skip_count'     => $skipCount,
                'errors'         => array_slice($rowErrors, 0, 10)
            ]);
            exit;
        }

        setFlash('success', "Import hoàn tất: Tổng số {$totalDataRows} dòng (Thêm mới: {$insertedCount}, Cập nhật: {$updatedCount}, Bỏ qua/lỗi: {$skipCount}).");
        header("Location: specs.php");
        exit;

    } catch (Throwable $e) {
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("[Import Specs Fatal Error] " . $e->getMessage() . "\nStack trace:\n" . $e->getTraceAsString());

        $errorMsg = "Lỗi xử lý file Import: " . $e->getMessage();
        if ($isAjax) {
            ob_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => $errorMsg,
                'trace'   => $e->getTraceAsString()
            ]);
            exit;
        }

        setFlash('danger', $errorMsg);
        header("Location: specs.php");
        exit;
    }
}

// XÓA QUY CÁCH (Admin only)
if (isset($_GET['delete_id']) && isAdmin()) {
    $deleteId = (int)$_GET['delete_id'];
    $stmt = $pdo->prepare("DELETE FROM `product_specs` WHERE `id` = ?");
    $stmt->execute([$deleteId]);
    setFlash('success', 'Đã xóa quy cách sản phẩm!');
    header("Location: specs.php");
    exit;
}

// TÌM KIẾM & PHÂN TRANG (PAGINATION)
$searchKeyword = trim($_GET['k'] ?? '');
$whereSql = " WHERE 1=1";
$params = [];
if (!empty($searchKeyword)) {
    $whereSql .= " AND (`product_code` LIKE ? OR `supplier` LIKE ? OR `description` LIKE ?)";
    $kParam = "%{$searchKeyword}%";
    $params = [$kParam, $kParam, $kParam];
}

// 1. Đếm tổng số bản ghi quy cách (Total Records)
$countSql = "SELECT COUNT(*) FROM `product_specs`" . $whereSql;
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

// 3. Truy vấn danh sách quy cách theo trang với LIMIT và OFFSET
$sql = "SELECT * FROM `product_specs`" . $whereSql . " ORDER BY `product_code` ASC, `box_type` ASC LIMIT {$limit} OFFSET {$offset}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$specsList = $stmt->fetchAll();

$fromRecord = ($totalRecords > 0) ? ($offset + 1) : 0;
$toRecord   = min($offset + $limit, $totalRecords);

// Hàm helper sinh liên kết phân trang duy trì từ khóa tìm kiếm
function buildSpecPageUrl($pageNumber, $customParams = []) {
    $queryParams = $_GET;
    $queryParams['page'] = $pageNumber;
    foreach ($customParams as $k => $v) {
        $queryParams[$k] = $v;
    }
    return 'specs.php?' . http_build_query($queryParams);
}
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
    <div>
        <h1 class="page-title m-0">📦 Quy Cách Đóng Gói (Specs)</h1>
    </div>
    <div class="page-actions d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-primary" onclick="openSpecModal()">
            ➕ Thêm Quy Cách
        </button>
        <button type="button" class="btn btn-outline-secondary" onclick="openImportSpecModal()">
            📂 Import CSV
        </button>
        <a href="export.php?type=specs&k=<?= urlencode($searchKeyword) ?>" class="btn btn-outline-success">
            📊 Xuất Excel/CSV
        </a>
    </div>
</div>

<!-- THANH TÌM KIẾM -->
<div class="card shadow-sm mb-3">
    <div class="card-body py-2 px-3">
        <form method="GET" action="specs.php" class="d-flex gap-2 flex-wrap align-items-center">
            <input type="text" name="k" class="form-control" style="max-width: 360px;" 
                   placeholder="🔍 Tìm kiếm mã sản phẩm, nhà cung cấp..." value="<?= htmlspecialchars($searchKeyword) ?>">
            <button type="submit" class="btn btn-primary">🔍 Tìm Kiếm</button>
            <?php if (!empty($searchKeyword)): ?>
                <a href="specs.php" class="btn btn-secondary">✖ Bỏ lọc</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- BẢNG DANH SÁCH QUY CÁCH -->
<div class="card shadow-sm">
    <div class="card-header bg-white py-2 px-3 d-flex justify-content-between align-items-center">
        <span class="card-title fw-bold text-dark m-0">Danh Mục Quy Cách Sản Phẩm (<?= number_format($totalRecords) ?> linh kiện)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive table-sticky-container">
            <table class="table table-hover align-middle mb-0 table-sticky">
                <thead class="table-light table-sticky-header">
                    <tr>
                        <th style="width: 50px;" class="text-center">STT</th>
                        <th>Mã Sản Phẩm (Material Name)</th>
                        <th class="text-center" style="width: 130px;">Phân Loại Thùng</th>
                        <th class="text-center">Quy Cách (con/thùng)</th>
                        <th>Nhà Cung Cấp</th>
                        <th class="text-end">Trọng Lượng (Kg/thùng)</th>
                        <th>Mô Tả Sản Phẩm</th>
                        <th class="text-center" style="width: 140px;">Thao Tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($specsList)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">
                                Chưa có quy cách sản phẩm nào trong hệ thống.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($specsList as $i => $s): ?>
                        <tr>
                            <td class="text-center text-muted"><?= $fromRecord + $i ?></td>
                            <td>
                                <strong class="text-primary font-monospace"><?= htmlspecialchars($s['product_code']) ?></strong>
                            </td>
                            <td class="text-center">
                                <strong class="text-primary font-monospace"><?= htmlspecialchars($s['box_type']) ?></strong>

                            </td>
                            <td class="text-center">
                                <span class="badge bg-light text-dark border">
                                    <?= (int)$s['pack_qty'] ?> <?= htmlspecialchars($s['unit']) ?>/thùng
                                </span>
                            </td>
                            <td><?= htmlspecialchars($s['supplier'] ?: '-') ?></td>
                            <td class="text-end fw-bold">
                                <?= $s['weight_per_box'] > 0 ? number_format($s['weight_per_box'], 3) . ' Kg' : '-' ?>
                            </td>
                            <td class="small text-muted"><?= htmlspecialchars($s['description'] ?: '-') ?></td>
                            <td class="text-center">
                                <div class="d-inline-flex gap-1 align-items-center">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" 
                                            onclick='editSpec(<?= htmlspecialchars(json_encode($s), ENT_QUOTES, "UTF-8") ?>)' title="Chỉnh sửa">
                                        ✏️ Sửa
                                    </button>
                                    <?php if (isAdmin()): ?>
                                        <a href="specs.php?delete_id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-danger" 
                                            onclick="return confirm('Xác nhận xóa quy cách mã <?= htmlspecialchars($s['product_code']) ?> (Thùng loại <?= htmlspecialchars($s['box_type'] ?? '1') ?>)?');" title="Xóa">
                                            🗑️
                                        </a>
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
                Hiển thị <strong><?= $fromRecord ?></strong> - <strong><?= $toRecord ?></strong> trên tổng số <strong><?= number_format($totalRecords) ?></strong> quy cách
                (Trang <strong><?= $page ?></strong> / <strong><?= $totalPages ?></strong>)
            </span>
            <span class="mx-1 d-none d-sm-inline">|</span>
            <label class="d-inline-flex align-items-center gap-1 mb-0">
                Hiển thị:
                <select class="form-select form-select-sm d-inline-block w-auto" onchange="changeSpecPageLimit(this.value)">
                    <?php foreach ([10, 15, 25, 50, 100] as $limOpt): ?>
                        <option value="<?= $limOpt ?>" <?= ($limit === $limOpt) ? 'selected' : '' ?>><?= $limOpt ?></option>
                    <?php endforeach; ?>
                </select>
                dòng/trang
            </label>
        </div>

        <?php if ($totalPages > 1): ?>
        <nav aria-label="Phân trang quy cách">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= buildSpecPageUrl(1) ?>" title="Trang đầu">&laquo;</a>
                </li>
                <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= buildSpecPageUrl($page - 1) ?>" title="Trang trước">&lsaquo;</a>
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
                    <li class="page-item"><a class="page-link" href="<?= buildSpecPageUrl(1) ?>">1</a></li>
                    <?php if ($startP > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>

                <?php for ($p = $startP; $p <= $endP; $p++): ?>
                    <li class="page-item <?= ($p === $page) ? 'active' : '' ?>">
                        <a class="page-link" href="<?= buildSpecPageUrl($p) ?>"><?= $p ?></a>
                    </li>
                <?php endfor; ?>

                <?php if ($endP < $totalPages): ?>
                    <?php if ($endP < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="<?= buildSpecPageUrl($totalPages) ?>"><?= $totalPages ?></a></li>
                <?php endif; ?>

                <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= buildSpecPageUrl($page + 1) ?>" title="Trang sau">&rsaquo;</a>
                </li>
                <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= buildSpecPageUrl($totalPages) ?>" title="Trang cuối">&raquo;</a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL THÊM / SỬA QUY CÁCH -->
<div class="modal fade" id="modalSpec" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="specs.php" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="save_spec">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="specModalTitle">📦 Thiết Lập Quy Cách Sản Phẩm</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <div class="col-8">
                        <label class="form-label small fw-bold text-secondary required">Mã Sản Phẩm (Material Name)</label>
                        <input type="text" name="product_code" id="spec_product_code" class="form-control" 
                               placeholder="vd: TU0425BU-20Z2" required>
                        <div class="form-text small">Mã định danh sản phẩm/linh kiện</div>
                    </div>
                    <div class="col-4">
                        <label class="form-label small fw-bold text-secondary required">Loại Thùng</label>
                        <select name="box_type" id="spec_box_type" class="form-select" required>
                            <option value="1/2">Loại 1/2</option>
                            <option value="1/4">Loại 1/4</option>
                        </select>
                        <div class="form-text small">Khóa: Mã SP + Loại thùng</div>
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-7">
                        <label class="form-label small fw-bold text-secondary required">Quy Cách Đóng Gói (con/thùng)</label>
                        <input type="number" name="pack_qty" id="spec_pack_qty" class="form-control fw-bold" 
                               placeholder="10" min="1" required>
                    </div>
                    <div class="col-5">
                        <label class="form-label small fw-bold text-secondary">Đơn vị tính</label>
                        <input type="text" name="unit" id="spec_unit" class="form-control" value="pcs">
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-7">
                        <label class="form-label small fw-bold text-secondary">Nhà Cung Cấp Mặc Định</label>
                        <input type="text" name="supplier" id="spec_supplier" class="form-control" placeholder="vd: Omron Corp">
                    </div>
                    <div class="col-5">
                        <label class="form-label small fw-bold text-secondary">Trọng Lượng (Kg/thùng)</label>
                        <input type="number" name="weight_per_box" id="spec_weight" class="form-control" step="0.001" placeholder="2.500">
                    </div>
                </div>

                <div class="mb-2">
                    <label class="form-label small fw-bold text-secondary">Mô Tả Sản Phẩm</label>
                    <textarea name="description" id="spec_desc" class="form-control" rows="2" placeholder="Ghi chú chi tiết linh kiện..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                <button type="submit" class="btn btn-primary fw-bold">💾 Lưu Quy Cách</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL IMPORT CSV QUY CÁCH -->
<div class="modal fade" id="modalImportSpec" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="specs.php" enctype="multipart/form-data" id="form_import_specs" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="import_specs">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">📂 Import Quy Cách Đóng Gói Từ CSV</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2 px-3 small mb-3">
                    <strong>Thứ tự các cột trong file CSV mẫu (7 cột chuẩn hóa):</strong><br>
                    <code>Mã sản phẩm, Loại thùng, Quy cách (con/thùng), Nhà cung cấp, Trọng lượng, Đơn vị, Mô tả</code><br>
                    <span class="text-muted mt-1 d-block">* Cơ chế <strong>Upsert / Overwrite</strong>: Tự động ghi đè cập nhật nếu trùng khớp cặp [Mã sản phẩm + Loại thùng], hoặc thêm mới nếu chưa có. Tương thích ngược với file 6 cột cũ (tự gán Loại thùng 1).</span>
                </div>
                <div class="mb-3">
                    <a href="export.php?type=sample_specs" download="sample_specs.csv" class="btn btn-sm btn-outline-primary">
                        📥 Tải File Mẫu Quy Cách (sample_specs.csv)
                    </a>
                </div>
                <div class="mb-3">
                    <label class="form-label required fw-bold small text-secondary">Chọn File CSV (UTF-8)</label>
                    <input type="file" name="file_upload" class="form-control" accept=".csv, .txt" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                <button type="submit" class="btn btn-primary fw-bold">Bắt Đầu Nạp Dữ Liệu</button>
            </div>
        </form>
    </div>
</div>

<script>
let specModalInstance = null;
let importSpecModalInstance = null;

function getSpecModal() {
    if (!specModalInstance) {
        const el = document.getElementById('modalSpec');
        if (el && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            specModalInstance = bootstrap.Modal.getOrCreateInstance(el);
        }
    }
    return specModalInstance;
}

function getImportSpecModal() {
    if (!importSpecModalInstance) {
        const el = document.getElementById('modalImportSpec');
        if (el && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            importSpecModalInstance = bootstrap.Modal.getOrCreateInstance(el);
        }
    }
    return importSpecModalInstance;
}

function openSpecModal() {
    document.getElementById('specModalTitle').innerText = '➕ Thêm Quy Cách Sản Phẩm Mới';
    document.getElementById('spec_product_code').value = '';
    document.getElementById('spec_product_code').removeAttribute('readonly');
    document.getElementById('spec_box_type').value = '1/2';
    document.getElementById('spec_pack_qty').value = '10';
    document.getElementById('spec_unit').value = 'pcs';
    document.getElementById('spec_supplier').value = '';
    document.getElementById('spec_weight').value = '';
    document.getElementById('spec_desc').value = '';
    const modal = getSpecModal();
    if (modal) modal.show();
}

function editSpec(spec) {
    document.getElementById('specModalTitle').innerText = '✏️ Chỉnh Sửa Quy Cách Sản Phẩm';
    document.getElementById('spec_product_code').value = spec.product_code;
    document.getElementById('spec_box_type').value = spec.box_type || '1/2';
    document.getElementById('spec_pack_qty').value = spec.pack_qty;
    document.getElementById('spec_unit').value = spec.unit || 'pcs';
    document.getElementById('spec_supplier').value = spec.supplier || '';
    document.getElementById('spec_weight').value = spec.weight_per_box > 0 ? spec.weight_per_box : '';
    document.getElementById('spec_desc').value = spec.description || '';
    const modal = getSpecModal();
    if (modal) modal.show();
}

function openImportSpecModal() {
    const modal = getImportSpecModal();
    if (modal) modal.show();
}

window.openSpecModal = openSpecModal;
window.editSpec = editSpec;
window.openImportSpecModal = openImportSpecModal;

// AJAX IMPORT CSV SPECS
const formImportSpecs = document.getElementById('form_import_specs');
if (formImportSpecs) {
    formImportSpecs.addEventListener('submit', function(e) {
        e.preventDefault();
        const submitBtn = this.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerText = '⏳ Đang nạp dữ liệu...';
        }

        const formData = new FormData(this);
        formData.append('ajax', '1');

        fetch('specs.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
        .then(async response => {
            const httpStatus = response.status;
            const contentType = response.headers.get('content-type') || '';
            const responseText = await response.text();

            console.log('[Import Specs] HTTP Status:', httpStatus);
            console.log('[Import Specs] Content-Type:', contentType);
            console.log('[Import Specs] Response Body:', responseText);

            if (!response.ok || !contentType.toLowerCase().includes('application/json')) {
                console.error('[Import Specs] Server returned non-JSON response:', { httpStatus, contentType, responseText });
                throw new Error('INVALID_JSON_RESPONSE');
            }

            try {
                return JSON.parse(responseText);
            } catch (parseError) {
                console.error('[Import Specs] JSON parse error:', parseError, responseText);
                throw new Error('INVALID_JSON_RESPONSE');
            }
        })
        .then(data => {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerText = 'Bắt Đầu Nạp Dữ Liệu';
            }
            if (data.message) {
                alert(data.message);
            } else if (data.errors && data.errors.length > 0) {
                alert('Lỗi nạp file:\n' + data.errors.join('\n'));
            } else {
                alert('Có lỗi xảy ra khi nạp file!');
            }

            if (data.success || data.inserted_count > 0 || data.updated_count > 0) {
                const modal = getImportSpecModal();
                if (modal) modal.hide();
                location.reload();
            }
        })
        .catch(err => {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerText = 'Bắt Đầu Nạp Dữ Liệu';
            }
            if (err.message === 'INVALID_JSON_RESPONSE') {
                alert('Không thể xử lý dữ liệu import. Vui lòng kiểm tra log hệ thống.');
            } else {
                alert('Lỗi kết nối: ' + err.message);
            }
        });
    });
}

function changeSpecPageLimit(newLimit) {
    const url = new URL(window.location.href);
    url.searchParams.set('limit', newLimit);
    url.searchParams.set('page', 1);
    window.location.href = url.toString();
}
window.changeSpecPageLimit = changeSpecPageLimit;
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

