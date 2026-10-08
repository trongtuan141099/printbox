<?php
/**
 * QUẢN LÝ QUY CÁCH ĐÓNG GÓI SẢN PHẨM RIÊNG BIỆT (PRODUCT SPECS)
 * Nơi định nghĩa quy cách con/thùng và nhà cung cấp tự động cho từng mã sản phẩm
 */
$pageTitle = "Quy Cách Đóng Gói Sản Phẩm";
require_once __DIR__ . '/includes/header.php';
requireRole(['admin', 'editor']);

$pdo = getDbConnection();

// THÊM HOẶC CẬP NHẬT QUY CÁCH
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_spec') {
    $productCode = trim($_POST['product_code'] ?? '');
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
                (`product_code`, `pack_qty`, `supplier`, `weight_per_box`, `unit`, `description`, `created_at`) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    `pack_qty` = VALUES(`pack_qty`),
                    `supplier` = VALUES(`supplier`),
                    `weight_per_box` = VALUES(`weight_per_box`),
                    `unit` = VALUES(`unit`),
                    `description` = VALUES(`description`),
                    `updated_at` = NOW()");
            $stmt->execute([$productCode, $packQty, $supplier, $weight, $unit, $description]);
            setFlash('success', "Đã lưu quy cách cho mã sản phẩm: {$productCode} ({$packQty} con/thùng)!");
            header("Location: specs.php");
            exit;
        } catch (PDOException $e) {
            setFlash('danger', 'Lỗi CSDL: ' . $e->getMessage());
        }
    }
}

// XỬ LÝ IMPORT CSV QUY CÁCH ĐÓNG GÓI
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_specs') {
    if (!isset($_FILES['file_upload']) || $_FILES['file_upload']['error'] !== UPLOAD_ERR_OK) {
        setFlash('danger', 'Vui lòng chọn file CSV quy cách hợp lệ để tải lên!');
    } else {
        $file = $_FILES['file_upload'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, ['csv', 'txt'])) {
            setFlash('warning', 'Hệ thống chỉ hỗ trợ file định dạng CSV (.csv, .txt UTF-8)!');
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

                // Nhận diện dấu phân cách , hoặc ;
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

                $stmtInsert = $pdo->prepare("INSERT INTO `product_specs` 
                    (`product_code`, `pack_qty`, `supplier`, `weight_per_box`, `unit`, `description`, `created_at`) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE 
                        `pack_qty` = VALUES(`pack_qty`),
                        `supplier` = VALUES(`supplier`),
                        `weight_per_box` = VALUES(`weight_per_box`),
                        `unit` = VALUES(`unit`),
                        `description` = VALUES(`description`),
                        `updated_at` = NOW()");

                while (($data = fgetcsv($tempHandle, 2000, $delimiter)) !== false) {
                    if (empty($data) || (count($data) === 1 && trim($data[0]) === '')) continue;

                    $c0 = trim($data[0] ?? ''); // Mã SP
                    $c1 = trim($data[1] ?? ''); // Quy cách
                    $c2 = trim($data[2] ?? ''); // Nhà cung cấp
                    $c3 = trim($data[3] ?? ''); // Trọng lượng
                    $c4 = trim($data[4] ?? 'pcs'); // Đơn vị
                    $c5 = trim($data[5] ?? ''); // Mô tả

                    if ($isFirstRow) {
                        $isFirstRow = false;
                        $lowerC0 = mb_strtolower($c0, 'UTF-8');
                        if (str_contains($lowerC0, 'mã') || str_contains($lowerC0, 'product') || !is_numeric($c1)) {
                            continue;
                        }
                    }

                    $productCode = $c0;
                    $packQty     = (int)$c1;
                    $supplier    = $c2;
                    $weight      = (float)$c3;
                    $unit        = !empty($c4) ? $c4 : 'pcs';
                    $desc        = $c5;

                    if (!empty($productCode) && $packQty > 0) {
                        $stmtInsert->execute([$productCode, $packQty, $supplier, $weight, $unit, $desc]);
                        $successCount++;
                    } else {
                        $skipCount++;
                    }
                }
                fclose($tempHandle);
                setFlash('success', "Đã import thành công {$successCount} quy cách sản phẩm! (Bỏ qua/lỗi: {$skipCount}).");
                header("Location: specs.php");
                exit;
            }
        }
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

// TÌM KIẾM
$searchKeyword = trim($_GET['k'] ?? '');
$sql = "SELECT * FROM `product_specs` WHERE 1=1";
$params = [];
if (!empty($searchKeyword)) {
    $sql .= " AND (`product_code` LIKE ? OR `supplier` LIKE ? OR `description` LIKE ?)";
    $kParam = "%{$searchKeyword}%";
    $params = [$kParam, $kParam, $kParam];
}
$sql .= " ORDER BY `product_code` ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$specsList = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 fw-bold text-dark mb-1">📦 Cơ Sở Dữ Liệu Quy Cách Đóng Gói (Product Specs)</h1>
        <p class="text-secondary small mb-0">Hệ thống tự động tra cứu số con/thùng và NCC theo Mã sản phẩm khi tạo hoặc nạp chỉ thị từ Excel</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
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
<div class="card border-0 shadow-sm rounded-3 mb-4">
    <div class="card-body p-3">
        <form method="GET" action="specs.php" class="d-flex gap-2 flex-wrap align-items-center">
            <input type="text" name="k" class="form-control form-control-sm" style="max-width: 350px;" 
                   placeholder="Tìm kiếm mã sản phẩm, nhà cung cấp..." value="<?= htmlspecialchars($searchKeyword) ?>">
            <button type="submit" class="btn btn-sm btn-primary">🔍 Tìm Kiếm</button>
            <?php if (!empty($searchKeyword)): ?>
                <a href="specs.php" class="btn btn-sm btn-outline-secondary">✖ Bỏ lọc</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- BẢNG DANH SÁCH QUY CÁCH -->
<div class="card border-0 shadow-sm rounded-3">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0 fw-bold">Danh Mục Quy Cách Sản Phẩm (<?= count($specsList) ?> linh kiện)</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-striped align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 50px;">STT</th>
                        <th>Mã Sản Phẩm (Material Name)</th>
                        <th style="text-align: center;">Quy Cách (con/thùng)</th>
                        <th>Nhà Cung Cấp</th>
                        <th style="text-align: right;">Trọng Lượng (Kg/thùng)</th>
                        <th>Mô Tả Sản Phẩm</th>
                        <th style="text-align: center; width: 140px;">Thao Tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($specsList)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">
                                Chưa có quy cách sản phẩm nào trong hệ thống.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($specsList as $i => $s): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <strong class="text-primary font-monospace"><?= htmlspecialchars($s['product_code']) ?></strong>
                            </td>
                            <td style="text-align: center;">
                                <span class="badge bg-primary-subtle text-primary-emphasis px-3 py-2 fs-6">
                                    <?= (int)$s['pack_qty'] ?> <?= htmlspecialchars($s['unit']) ?>/thùng
                                </span>
                            </td>
                            <td><?= htmlspecialchars($s['supplier'] ?: '-') ?></td>
                            <td style="text-align: right; font-weight: 600;">
                                <?= $s['weight_per_box'] > 0 ? number_format($s['weight_per_box'], 3) . ' Kg' : '-' ?>
                            </td>
                            <td class="small text-secondary"><?= htmlspecialchars($s['description'] ?: '-') ?></td>
                            <td style="text-align: center;">
                                <button type="button" class="btn btn-sm btn-outline-secondary me-1" 
                                        onclick='editSpec(<?= htmlspecialchars(json_encode($s), ENT_QUOTES, "UTF-8") ?>)' title="Chỉnh sửa">
                                    ✏️ Sửa
                                </button>
                                <?php if (isAdmin()): ?>
                                    <a href="specs.php?delete_id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-danger" 
                                       onclick="return confirm('Xác nhận xóa quy cách mã <?= htmlspecialchars($s['product_code']) ?>?');" title="Xóa">
                                        🗑️
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
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
                <div class="mb-3">
                    <label class="form-label small fw-bold text-secondary required">Mã Sản Phẩm (Material Name)</label>
                    <input type="text" name="product_code" id="spec_product_code" class="form-control" 
                           placeholder="vd: SMC-VALVE-50A" required>
                    <div class="form-text small">Mã định danh duy nhất của sản phẩm/linh kiện</div>
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
        <form method="POST" action="specs.php" enctype="multipart/form-data" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="import_specs">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">📂 Import Quy Cách Đóng Gói Từ CSV</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2 px-3 small mb-3">
                    <strong>Thứ tự các cột trong file CSV mẫu:</strong><br>
                    <code>Mã sản phẩm, Quy cách (con/thùng), Nhà cung cấp, Trọng lượng, Đơn vị, Mô tả</code>
                </div>
                <div class="mb-3">
                    <a href="sample_specs.csv" download class="btn btn-sm btn-outline-primary">
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
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

