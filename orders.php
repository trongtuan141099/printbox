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
    $issueMonth  = trim($_POST['issue_month'] ?? date('m/Y'));
    $targetQty   = (int)($_POST['target_qty'] ?? 0);
    $packQty     = (int)($_POST['pack_qty'] ?? 0);
    $supplier    = trim($_POST['supplier'] ?? '');
    $weight      = (float)($_POST['weight_per_box'] ?? 0);
    $note        = trim($_POST['note'] ?? '');

    // Nếu người dùng không nhập quy cách hoặc NCC, tự động tra cứu từ product_specs
    if ($packQty <= 0 || empty($supplier)) {
        $spec = getProductSpec($productCode, $pdo);
        if ($packQty <= 0) {
            $packQty = (int)$spec['pack_qty'];
        }
        if (empty($supplier)) {
            $supplier = $spec['supplier'];
        }
        if ($weight <= 0) {
            $weight = (float)$spec['weight_per_box'];
        }
    }
    if ($packQty <= 0) $packQty = 1;

    if (empty($slipCode) || empty($orderCode) || empty($productCode) || $targetQty <= 0) {
        setFlash('danger', 'Vui lòng nhập đầy đủ các thông tin bắt buộc (*)!');
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO `production_orders` 
                (`slip_code`, `order_code`, `product_code`, `issue_month`, `target_qty`, `printed_qty`, `remaining_qty`, `pack_qty`, `supplier`, `weight_per_box`, `status`, `note`, `created_by`, `created_at`) 
                VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 'pending', ?, ?, NOW())");
            $stmt->execute([
                $slipCode,
                $orderCode,
                $productCode,
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
            $encoding = mb_detect_encoding($rawContent, ['UTF-8', 'ISO-8859-1', 'WINDOWS-1252', 'WINDOWS-1258'], true);
            if ($encoding && $encoding !== 'UTF-8') {
                $rawContent = mb_convert_encoding($rawContent, 'UTF-8', $encoding);
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

            $successCount = 0;
            $skipCount = 0;
            $isFirstRow = true;

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

                $slipCode    = $c0;
                $orderCode   = $c1;
                $productCode = $c2;
                $issueMonth  = !empty($c3) ? $c3 : date('m/Y');
                $targetQty   = (int)$c4;
                $note        = $c5;

                if (!empty($slipCode) && !empty($orderCode) && !empty($productCode) && $targetQty > 0) {
                    // TỰ ĐỘNG TRA CỨU QUY CÁCH & NHÀ CUNG CẤP TỪ PRODUCT_SPECS
                    $spec = getProductSpec($productCode, $pdo);
                    $packQty  = (int)$spec['pack_qty'];
                    $supplier = $spec['supplier'];
                    $weight   = (float)$spec['weight_per_box'];

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
                } else {
                    $skipCount++;
                }
            }
            fclose($tempHandle);
            setFlash('success', "Đã import thành công {$successCount} chỉ thị! (Bỏ qua/lỗi: {$skipCount}). Quy cách đóng gói đã tự động map từ danh mục Specs.");
            header("Location: orders.php");
            exit;
        }
        }
    }
}

// =======================================================
// BỘ LỌC TÌM KIẾM CHỈ THỊ
// =======================================================
$searchKeyword = trim($_GET['k'] ?? '');
$filterStatus  = trim($_GET['status'] ?? '');
$filterMonth   = trim($_GET['month'] ?? '');

$sql = "SELECT * FROM `production_orders` WHERE 1=1";
$params = [];

if (!empty($searchKeyword)) {
    $sql .= " AND (`slip_code` LIKE ? OR `order_code` LIKE ? OR `product_code` LIKE ? OR `supplier` LIKE ?)";
    $kParam = "%{$searchKeyword}%";
    $params[] = $kParam;
    $params[] = $kParam;
    $params[] = $kParam;
    $params[] = $kParam;
}

if (!empty($filterStatus)) {
    $sql .= " AND `status` = ?";
    $params[] = $filterStatus;
}

if (!empty($filterMonth)) {
    $sql .= " AND `issue_month` LIKE ?";
    $params[] = "%{$filterMonth}%";
}

$sql .= " ORDER BY `id` DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Lấy danh mục Specs để phục vụ Auto-complete / Datalist trong form
$allSpecs = $pdo->query("SELECT product_code, pack_qty, supplier, weight_per_box FROM `product_specs` ORDER BY `product_code` ASC")->fetchAll();

// Tham số xuất Excel
$exportParams = http_build_query([
    'type'   => 'orders',
    'k'      => $searchKeyword,
    'status' => $filterStatus,
    'month'  => $filterMonth
]);
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="page-title m-0">📋 Quản Lý Chỉ Thị Sản Xuất (CTSX)</h1>
        <!-- <p class="text-muted small mt-1 mb-0">
            Quản lý chỉ thị &bull; Tự động map quy cách &bull; Nhập liệu &amp; in tem trực tiếp qua Offcanvas &bull; Quản lý tiến độ
        </p> -->
    </div>
    <div class="page-actions d-flex flex-wrap gap-2">
        <!-- NÚT MỞ BẢNG IN TEM NHANH (MỌI VAI TRÒ ĐỀU DÙNG ĐƯỢC) -->
        <button type="button" class="btn btn-primary" onclick="openOffcanvasPrint(null)">
            🏷️ Quét / In Tem Thùng
        </button>

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
<div class="card shadow-sm mb-4">
    <div class="card-body py-3 px-3">
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
                    <option value="paused" <?= ($filterStatus === 'paused') ? 'selected' : '' ?>>⏸️ Tạm dừng in (Paused)</option>
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
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <span class="card-title fw-bold text-dark m-0">
            Danh Sách Chỉ Thị Sản Xuất <span class="badge bg-secondary ms-1"><?= count($orders) ?> chỉ thị</span>
        </span>
        <span class="text-muted small">Nhấn <strong>"🏷️ In Tem"</strong> trên từng dòng để mở form in trượt tiện lợi</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="orders_table">
                <thead class="table-light">
                    <tr>
                        <th style="width:50px;" class="text-center">STT</th>
                        <th>Mã Phiếu Chỉ Thị</th>
                        <th>Mã Chỉ Thị (Lot)</th>
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
                            <td colspan="12" class="text-center py-4 text-muted">
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
                            <td class="text-center text-muted"><?= $idx + 1 ?></td>
                            <td>
                                <strong class="text-primary text-break"><?= htmlspecialchars($row['slip_code']) ?></strong>
                            </td>
                            <td>
                                <strong class="text-dark"><?= htmlspecialchars($row['order_code']) ?></strong>
                            </td>
                            <td>
                                <div class="fw-bold text-dark"><?= htmlspecialchars($row['product_code']) ?></div>
                                <?php if (!empty($row['supplier'])): ?>
                                    <small class="text-muted"><?= htmlspecialchars($row['supplier']) ?></small>
                                <?php endif; ?>
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
                                        🏷️ In Tem
                                    </button>

                                    <!-- NÚT 2 & 3: CHỈNH SỬA & TẠM DỪNG (EDITOR & ADMIN) -->
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
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
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
                <div class="col-6"><strong>Nhà cung cấp:</strong> <span id="oc_disp_supp">-</span></div>
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
            <div class="mb-3">
                <label class="form-label fw-bold required">Số Lượng Tem Sản Phẩm Đã In Lượt Này (con / pcs)</label>
                <input type="number" id="offcanvas_print_qty" class="form-control form-control-lg fw-bold" 
                       placeholder="Nhập số lượng tem (vd: 20, 50, 100)..." min="1" step="1">
                <small class="text-muted">Hệ thống sẽ tự động tính số tem thùng = Số lượng in &divide; Quy cách đóng gói.</small>
            </div>

            <!-- BẢNG TÍNH TOÁN TEM THÙNG REALTIME -->
            <div id="offcanvas_calc_panel" class="card border-primary bg-light p-3 mb-3" style="display:none;">
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
                    Cần Quản lý/Admin nhập <strong>Mã số nhân viên (MSNV)</strong> để duyệt.
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
                Sau khi xác nhận, hệ thống tự động sinh các mã thùng tuần tự <code class="text-primary">TU-<?= date('ymd') ?>-XXX</code> và hiển thị tem in 65x30mm kèm mã QR.
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
     MODAL 2: DUYỆT VƯỢT ĐỊNH MỨC DÀNH CHO ADMIN / EDITOR (MSNV & PASSWORD)
     ======================================================= -->
<div id="modal_admin_approval" class="modal-overlay" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="modal_approval_title">
    <div class="modal-dialog" style="max-width: 520px;">
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
                    Bắt buộc Quản lý / Admin / Editor nhập Mã số nhân viên (MSNV) và Mật khẩu để xác nhận ủy quyền:
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
                    <div id="admin_verify_feedback" class="mt-2 small fw-bold"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal_admin_approval')">Hủy bỏ</button>
                <button type="submit" id="btn_verify_and_proceed" class="btn btn-danger fw-bold" tabindex="3">
                    🔑 Xác nhận duyệt vượt mức
                </button>
            </div>
        </form>
    </div>
</div>

<!-- =======================================================
     MODAL 3: XEM VÀ IN TEM THÙNG SATO 65x30MM
     ======================================================= -->
<div id="modal_print_result" class="modal-overlay">
    <div class="modal-dialog" style="max-width:850px;">
        <div class="modal-header no-print bg-light">
            <span class="modal-title fw-bold text-dark">🖨️ Tem Thùng Đã Sinh - Sẵn Sàng In</span>
            <button type="button" class="modal-close" onclick="closeModal('modal_print_result')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="alert alert-success no-print mb-3 d-flex justify-content-between align-items-center">
                <span>✅ Đã lưu lịch sử và sinh thành công <strong id="res_box_count">0</strong> tem thùng!</span>
                <button type="button" class="btn btn-primary fw-bold" onclick="triggerDirectPrint()">
                    🖨️ GỬI LỆNH IN RA MÁY IN
                </button>
            </div>

            <div class="no-print mb-2 text-muted small">
                Khổ in nhiệt chuẩn: <strong>65mm &times; 30mm</strong>. Mỗi tem thùng tự động ngắt trang riêng biệt.
            </div>

            <!-- CONTAINER CHỨA DANH SÁCH TEM ĐÃ SINH -->
            <div id="printable_labels_container" class="label-preview-wrapper">
                <!-- Javascript điền tem vào đây -->
            </div>
        </div>
        <div class="modal-footer no-print">
            <button type="button" class="btn btn-secondary" onclick="closeModal('modal_print_result')">Đóng</button>
            <button type="button" class="btn btn-primary fw-bold" onclick="triggerDirectPrint()">🖨️ In Lại</button>
        </div>
    </div>
</div>

<!-- =======================================================
     MODAL 4: CHỈNH SỬA THÔNG TIN CHỈ THỊ (EDIT ORDER)
     ======================================================= -->
<?php if (canManageDirectives()): ?>
<div id="modal_edit_order" class="modal-overlay">
    <div class="modal-dialog">
        <form method="POST" action="orders.php" id="form_edit_order" onsubmit="return confirmEditSubmit();">
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
                                    <?= htmlspecialchars($sp['product_code']) ?> (Quy cách: <?= $sp['pack_qty'] ?> con/thùng)
                                </option>
                            <?php endforeach; ?>
                        </datalist>
                        <small class="text-muted">Tự động điền Quy cách khi chọn mã từ danh mục Specs.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Tháng Phát Hành</label>
                        <input type="text" name="issue_month" class="form-control" value="<?= date('m/Y') ?>" placeholder="vd: 10/2026">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label required">Số Lượng Chỉ Thị (Target)</label>
                        <input type="number" name="target_qty" class="form-control" placeholder="100" min="1" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required">Quy Cách (con/thùng)</label>
                        <input type="number" name="pack_qty" id="add_pack_qty" class="form-control" placeholder="10" min="1" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Nhà Cung Cấp</label>
                        <input type="text" name="supplier" id="add_supplier" class="form-control" placeholder="Tên đối tác / xưởng">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Trọng Lượng (Kg/thùng)</label>
                        <input type="number" name="weight_per_box" id="add_weight" class="form-control" step="0.001" placeholder="2.500">
                    </div>

                    <div class="col-12">
                        <label class="form-label">Ghi Chú</label>
                        <input type="text" name="note" class="form-control" placeholder="Ghi chú đơn hàng...">
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
                    <a href="sample_orders.csv" download class="btn btn-sm btn-outline-primary">
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

<script>
// =======================================================
// DỮ LIỆU ĐỒNG BỘ TOÀN CỤC & BIẾN TRẠNG THÁI
// =======================================================
const ordersDataMap = <?= json_encode(array_column($orders, null, 'id'), JSON_UNESCAPED_UNICODE) ?: '{}' ?>;
const allSpecsMap   = <?= json_encode(array_column($allSpecs, null, 'product_code'), JSON_UNESCAPED_UNICODE) ?: '{}' ?>;

let currentOrder        = null;
let currentCalculation  = null;
let verifiedAdminMsnv   = null;
let verifiedAdminPass   = null;

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

function closeModal(id) { 
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

// Khi Offcanvas mở, lập tức vô hiệu hóa FocusTrap của nó để tránh cướp focus các modal và input
const offcanvasPrintElement = document.getElementById('offcanvasPrint');
if (offcanvasPrintElement) {
    offcanvasPrintElement.addEventListener('shown.bs.offcanvas', function() {
        if (typeof bootstrap !== 'undefined' && bootstrap.Offcanvas) {
            const inst = bootstrap.Offcanvas.getInstance(offcanvasPrintElement);
            if (inst && inst._focustrap) {
                inst._focustrap.deactivate();
            }
        }
    });
}

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
    document.getElementById('offcanvas_order_card').style.display = 'none';
    document.getElementById('offcanvas_print_inputs').style.display = 'none';
    document.getElementById('offcanvas_alert_paused').style.display = 'none';
    document.getElementById('offcanvas_calc_panel').style.display = 'none';
    document.getElementById('offcanvas_odd_option').style.display = 'none';
    document.getElementById('oc_chk_odd_box').checked = false;
    document.getElementById('offcanvas_query_input').value = '';
    document.getElementById('offcanvas_print_qty').value = '';
}

function loadOrderIntoOffcanvas(order) {
    currentOrder = order;
    currentCalculation = null;
    verifiedAdminMsnv = null;
    verifiedAdminPass = null;

    document.getElementById('oc_disp_slip').innerText = order.slip_code;
    document.getElementById('oc_disp_order').innerText = order.order_code;
    document.getElementById('oc_disp_product').innerText = order.product_code;
    document.getElementById('oc_disp_month').innerText = order.issue_month || 'N/A';
    document.getElementById('oc_disp_pack').innerText = order.pack_qty;
    document.getElementById('oc_disp_supp').innerText = order.supplier || '(Trống)';

    document.getElementById('oc_disp_target').innerText = Number(order.target_qty).toLocaleString();
    document.getElementById('oc_disp_printed').innerText = Number(order.printed_qty).toLocaleString();
    document.getElementById('oc_disp_remaining').innerText = Number(order.remaining_qty).toLocaleString();

    // Checkbox in thùng lẻ luôn luôn UNCHECKED by default
    document.getElementById('oc_chk_odd_box').checked = false;

    const isPaused = (order.status === 'paused');
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

        if (order.status === 'completed') {
            statusBadge.className = 'badge bg-success';
            statusBadge.innerText = 'HOÀN THÀNH';
        } else if (order.status === 'in_progress') {
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
    const packageSpec = parseInt(currentOrder.pack_qty, 10) || 1;

    // Reset xác thực vượt mức mỗi khi thay đổi số lượng in
    verifiedAdminMsnv = null;
    verifiedAdminPass = null;

    if (isNaN(printQtyVal) || printQtyVal <= 0) {
        document.getElementById('offcanvas_calc_panel').style.display = 'none';
        document.getElementById('offcanvas_odd_option').style.display = 'none';
        document.getElementById('oc_chk_odd_box').checked = false;
        document.getElementById('offcanvas_alert_over').style.display = 'none';
        currentCalculation = null;
        return;
    }

    // CÔNG THỨC:
    // full_boxes = floor(printed_qty / package_spec)
    // remainder  = printed_qty % package_spec
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
    } else {
        // CONDITION 2: remainder > 0 -> Bắt buộc hiển thị cảnh báo & yêu cầu tick chọn in lẻ
        // CHECKBOX RULE: Mặc định để trống/chưa tick, nhân viên phải chủ động tích chọn
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

// BẤM NÚT XÁC NHẬN NHẬP & IN TEM TRONG OFFCANVAS
const btnOffSubmit = document.getElementById('btn_offcanvas_submit');
if (btnOffSubmit) {
    btnOffSubmit.addEventListener('click', function() {
        if (!currentOrder || !currentCalculation) {
            alert('Vui lòng nhập số lượng tem sản phẩm hợp lệ!');
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
        // Bất kể người dùng đang đăng nhập là Viewer, Editor hay Admin,
        // khi printQty > remaining BẮT BUỘC PHẢI QUA BƯỚC XÁC THỰC MSNV & MẬT KHẨU!
        if (printQty > remaining && (!verifiedAdminMsnv || !verifiedAdminPass)) {
            document.getElementById('modal_req_qty').innerText = printQty.toLocaleString();
            document.getElementById('modal_rem_qty').innerText = remaining.toLocaleString();
            document.getElementById('modal_diff_qty').innerText = '+' + (printQty - remaining).toLocaleString() + ' con';
            document.getElementById('input_admin_msnv').value = '';
            document.getElementById('input_admin_pass').value = '';
            document.getElementById('admin_verify_feedback').innerText = '';
            openModal('modal_admin_approval');
            return;
        }

        // Điền modal xác nhận nhà máy
        document.getElementById('cf_slip_code').innerText = currentOrder.slip_code;
        document.getElementById('cf_order_code').innerText = currentOrder.order_code;
        document.getElementById('cf_product_code').innerText = currentOrder.product_code;
        document.getElementById('cf_print_qty').innerText = printQty.toLocaleString() + ' con';
        document.getElementById('cf_pack_qty').innerText = currentOrder.pack_qty + ' con/thùng';
        document.getElementById('cf_box_count').innerText = currentCalculation.totalBoxes + ' TEM';

        if (currentCalculation.hasOdd) {
            document.getElementById('cf_row_odd').style.display = 'table-row';
            document.getElementById('cf_odd_desc').innerText = 
                currentCalculation.fullBoxes + ' thùng chẵn + 1 thùng lẻ (' + currentCalculation.remainder + ' con)';
        } else {
            document.getElementById('cf_row_odd').style.display = 'none';
        }

        if (printQty > remaining) {
            document.getElementById('cf_row_over').style.display = 'table-row';
            document.getElementById('cf_over_desc').innerText = 'Đã ủy quyền duyệt bởi: ' + verifiedAdminMsnv;
        } else {
            document.getElementById('cf_row_over').style.display = 'none';
        }

        openModal('modal_confirm_print');
    });
}

// XÁC THỰC MSNV & MẬT KHẨU ADMIN/EDITOR DUYỆT VƯỢT MỨC VÀ THỰC THI IN NGAY
function submitAdminApproval() {
    const msnvInput = document.getElementById('input_admin_msnv');
    const passInput = document.getElementById('input_admin_pass');
    const fb = document.getElementById('admin_verify_feedback');
    const btn = document.getElementById('btn_verify_and_proceed');

    const msnv = msnvInput ? msnvInput.value.trim() : '';
    const pass = passInput ? passInput.value.trim() : '';

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

    if (!currentOrder || !currentCalculation) {
        alert('Không tìm thấy dữ liệu chỉ thị cần in!');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Đang xác thực & thực thi in...';
    fb.style.color = '#2563eb';
    fb.innerText = 'Đang kiểm tra quyền duyệt & sinh mã tem...';

    const formData = new FormData();
    formData.append('order_id', currentOrder.id);
    formData.append('print_qty', currentCalculation.printQty);
    formData.append('is_odd_box', (currentCalculation.hasOdd && document.getElementById('oc_chk_odd_box').checked) ? 1 : 0);
    formData.append('msnv', msnv);
    formData.append('password', pass);
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

        // CẬP NHẬT DÒNG TƯƠNG ỨNG TRÊN BẢNG DỮ LIỆU
        updateTableRowAfterPrint(currentOrder.id, data.new_printed, data.new_remaining, currentOrder.target_qty);

        // Đóng modal duyệt vượt mức & đóng offcanvas
        setTimeout(() => {
            closeModal('modal_admin_approval');
            const offcanvasEl = document.getElementById('offcanvasPrint');
            const bsOffcanvas = bootstrap.Offcanvas.getInstance(offcanvasEl);
            if (bsOffcanvas) bsOffcanvas.hide();

            // Hiển thị kết quả in tem với mã QR
            renderPrintedLabels(data.labels);
            document.getElementById('res_box_count').innerText = data.labels.length;
            openModal('modal_print_result');

            // Tự động kích hoạt lệnh in ra máy in nhiệt mạng
            setTimeout(() => {
                triggerDirectPrint();
            }, 500);
        }, 250);
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

// GỬI AJAX LƯU LỊCH SỬ & SINH TEM THÙNG
const btnConfirmExecute = document.getElementById('btn_confirm_print_execute');
if (btnConfirmExecute) {
    btnConfirmExecute.addEventListener('click', function() {
        const btn = document.getElementById('btn_confirm_print_execute');
        btn.disabled = true;
        btn.innerText = '⏳ Đang lưu & sinh mã QR...';

        const formData = new FormData();
        formData.append('order_id', currentOrder.id);
        formData.append('print_qty', currentCalculation.printQty);
        formData.append('is_odd_box', (currentCalculation.hasOdd && document.getElementById('oc_chk_odd_box').checked) ? 1 : 0);
        formData.append('admin_msnv', verifiedAdminMsnv || '');
        formData.append('admin_pass', verifiedAdminPass || '');
        formData.append('note', document.getElementById('offcanvas_note').value.trim());

        fetch('ajax/submit_print.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            btn.innerText = '✅ ĐỒNG Ý NHẬP & IN NGAY';

            if (!data.success) {
                if (data.require_admin) {
                    closeModal('modal_confirm_print');
                    verifiedAdminMsnv = null;
                    verifiedAdminPass = null;
                    document.getElementById('modal_req_qty').innerText = (data.print_qty || currentCalculation.printQty).toLocaleString();
                    document.getElementById('modal_rem_qty').innerText = (data.remaining_qty !== undefined ? data.remaining_qty : currentOrder.remaining_qty).toLocaleString();
                    document.getElementById('modal_diff_qty').innerText = '+' + ((data.excess_qty !== undefined ? data.excess_qty : (currentCalculation.printQty - currentOrder.remaining_qty))).toLocaleString() + ' con';
                    document.getElementById('input_admin_msnv').value = '';
                    document.getElementById('input_admin_pass').value = '';
                    document.getElementById('admin_verify_feedback').innerText = data.message;
                    document.getElementById('admin_verify_feedback').style.color = '#b91c1c';
                    openModal('modal_admin_approval');
                } else if (data.require_odd_confirm) {
                    closeModal('modal_confirm_print');
                    alert(data.message);
                    document.getElementById('oc_chk_odd_box').focus();
                } else {
                    alert('Lỗi: ' + data.message);
                }
                return;
            }

            // CẬP NHẬT DÒNG TƯƠNG ỨNG TRÊN BẢNG DỮ LIỆU
            updateTableRowAfterPrint(currentOrder.id, data.new_printed, data.new_remaining, currentOrder.target_qty);

            // Đóng modal xác nhận & đóng offcanvas
            closeModal('modal_confirm_print');
            const offcanvasEl = document.getElementById('offcanvasPrint');
            const bsOffcanvas = bootstrap.Offcanvas.getInstance(offcanvasEl);
            if (bsOffcanvas) bsOffcanvas.hide();

            // Hiển thị kết quả in tem
            renderPrintedLabels(data.labels);
            document.getElementById('res_box_count').innerText = data.labels.length;
            openModal('modal_print_result');
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerText = '✅ ĐỒNG Ý NHẬP & IN NGAY';
            alert('Lỗi gửi dữ liệu: ' + err.message);
        });
    });
}

// CẬP NHẬT DÒNG TRÊN BẢNG SAU KHI IN
function updateTableRowAfterPrint(orderId, newPrinted, newRemaining, targetQty) {
    if (ordersDataMap[orderId]) {
        ordersDataMap[orderId].printed_qty = newPrinted;
        ordersDataMap[orderId].remaining_qty = newRemaining;
        ordersDataMap[orderId].status = (newRemaining <= 0) ? 'completed' : 'in_progress';
    }

    const row = document.getElementById('row_order_' + orderId);
    if (!row) return;

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
}

// RENDER DANH SÁCH TEM THÙNG RA GIAO DIỆN IN SATO 65x30MM
function renderPrintedLabels(labels) {
    const container = document.getElementById('printable_labels_container');
    if (!container) return;
    container.innerHTML = '';

    labels.forEach(item => {
        const div = document.createElement('div');
        div.className = 'box-label-item';
        div.innerHTML = `
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
        container.appendChild(div);
    });
}

function triggerDirectPrint() {
    window.print();
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, function(m) {
        return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[m];
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

// AUTO MAP SPECS KHI NHẬP PRODUCT_CODE TRONG FORM THÊM MỚI
const addProdInput = document.getElementById('add_product_code');
if (addProdInput) {
    addProdInput.addEventListener('change', function() {
        const val = this.value.trim();
        if (allSpecsMap && allSpecsMap[val]) {
            const spec = allSpecsMap[val];
            const pQty = document.getElementById('add_pack_qty');
            const supp = document.getElementById('add_supplier');
            const wgt  = document.getElementById('add_weight');
            if (pQty) pQty.value = spec.pack_qty || 1;
            if (supp) supp.value = spec.supplier || '';
            if (wgt)  wgt.value  = spec.weight_per_box > 0 ? spec.weight_per_box : '';
        }
    });
}

// =======================================================
// GẮN TẤT CẢ CÁC HÀM XỬ LÝ LÊN WINDOW TOÀN CỤC
// =======================================================
window.openModal              = openModal;
window.closeModal             = closeModal;
window.openOffcanvasPrint     = openOffcanvasPrint;
window.openOffcanvasPrintById = openOffcanvasPrintById;
window.openEditModalById      = openEditModalById;
window.resetOffcanvasState    = resetOffcanvasState;
window.loadOrderIntoOffcanvas = loadOrderIntoOffcanvas;
window.searchOrderOffcanvas   = searchOrderOffcanvas;
window.recalculateOffcanvas   = recalculateOffcanvas;
window.updateTableRowAfterPrint = updateTableRowAfterPrint;
window.renderPrintedLabels    = renderPrintedLabels;
window.triggerDirectPrint     = triggerDirectPrint;
window.openEditModal          = openEditModal;
window.confirmEditSubmit      = confirmEditSubmit;
window.requestPause           = requestPause;
window.requestResume          = requestResume;
window.submitAdminApproval    = submitAdminApproval;

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
        .then(res => res.json())
        .then(data => {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerText = 'Bắt Đầu Import';
            }
            if (data.success) {
                closeModal('modal_import_excel');
                alert(data.message);
                location.reload();
            } else {
                alert('Lỗi nạp file: ' + data.message + (data.errors ? '\n' + data.errors.join('\n') : ''));
            }
        })
        .catch(err => {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerText = 'Bắt Đầu Import';
            }
            alert('Lỗi kết nối: ' + err.message);
        });
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
