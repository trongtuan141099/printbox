<?php
/**
 * TRA CỨU VÀ QUẢN LÝ LỊCH SỬ IN TEM (PRINT HISTORY)
 * Bộ lọc đa tiêu chí: ngày, chỉ thị, sản phẩm, người thực hiện
 * Hỗ trợ in lại (Reprint) và Xuất dữ liệu Excel
 */
$pageTitle = "Lịch Sử In Tem";
require_once __DIR__ . '/includes/header.php';

$pdo = getDbConnection();

// LẤY DANH SÁCH NHÂN VIÊN ĐỂ LỌC
$operators = $pdo->query("SELECT id, full_name, employee_code FROM `users` ORDER BY `full_name` ASC")->fetchAll();

// BỘ LỌC TÌM KIẾM
$fromDate     = trim($_GET['from_date'] ?? '');
$toDate       = trim($_GET['to_date'] ?? '');
$slipCode     = trim($_GET['slip_code'] ?? '');
$orderCode    = trim($_GET['order_code'] ?? '');
$productCode  = trim($_GET['product_code'] ?? '');
$operatorId   = (int)($_GET['operator_id'] ?? 0);
$isOverTarget = trim($_GET['is_over_target'] ?? '');

$sql = "SELECT ph.*, po.supplier, po.target_qty, po.issue_month 
        FROM `print_history` ph 
        LEFT JOIN `production_orders` po ON ph.order_id = po.id 
        WHERE 1=1";
$params = [];

if (!empty($fromDate)) {
    $sql .= " AND DATE(ph.created_at) >= ?";
    $params[] = $fromDate;
}
if (!empty($toDate)) {
    $sql .= " AND DATE(ph.created_at) <= ?";
    $params[] = $toDate;
}
if (!empty($slipCode)) {
    $sql .= " AND ph.slip_code LIKE ?";
    $params[] = "%{$slipCode}%";
}
if (!empty($orderCode)) {
    $sql .= " AND ph.order_code LIKE ?";
    $params[] = "%{$orderCode}%";
}
if (!empty($productCode)) {
    $sql .= " AND ph.product_code LIKE ?";
    $params[] = "%{$productCode}%";
}
if ($operatorId > 0) {
    $sql .= " AND ph.operator_id = ?";
    $params[] = $operatorId;
}
if ($isOverTarget !== '') {
    $sql .= " AND ph.is_over_target = ?";
    $params[] = (int)$isOverTarget;
}

$sql .= " ORDER BY ph.id DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$historyList = $stmt->fetchAll();

// Chuỗi tham số xuất Excel
$queryString = http_build_query($_GET);
$exportQuery = http_build_query(array_merge($_GET, ['type' => 'history']));
$hasFilter   = (!empty($fromDate) || !empty($toDate) || !empty($slipCode) || !empty($orderCode) || !empty($productCode) || $operatorId > 0 || $isOverTarget !== '');
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
    <div>
        <h1 class="page-title m-0">📜 Lịch Sử In Tem Sản Xuất</h1>
    </div>
    <div class="page-actions d-flex flex-wrap gap-2">
        <a href="export.php?<?= $exportQuery ?>" class="btn btn-sm btn-outline-success fw-bold" title="Xuất toàn bộ dữ liệu lịch sử theo điều kiện lọc ra Excel / CSV">
            📊 Xuất Dữ Liệu Excel / CSV
        </a>
    </div>
</div>

<!-- KHỐI BỘ LỌC TÌM KIẾM THEO CHIỀU NGANG (HORIZONTAL INLINE FILTER LAYOUT) -->
<div class="card filter-card shadow-sm mb-2">
    <div class="card-header bg-white py-1 px-3 d-flex justify-content-between align-items-center">
        <span class="card-title fw-bold text-dark m-0" style="font-size: 13.5px;">
            🔍 Bộ Lọc Tìm Kiếm Nâng Cao
        </span>
        <div class="d-flex align-items-center gap-2">
            <?php if ($hasFilter): ?>
                <span class="badge bg-primary text-white" style="font-size: 10.5px;">
                    Đang lọc dữ liệu
                </span>
            <?php endif; ?>
            <span class="text-muted" style="font-size: 12px;">
                Tìm thấy: <strong class="text-dark"><?= count($historyList) ?></strong> lượt in (tối đa 200)
            </span>
        </div>
    </div>
    <div class="card-body">
        <form method="GET" action="history.php" class="filter-form-horizontal">
            <!-- HÀNG 1: THỜI GIAN & MÃ CHỈ THỊ (4 CỘT NGANG) -->
            <div class="row g-2 align-items-end mb-2">
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label-compact">
                        📅 Từ ngày:
                    </label>
                    <input type="date" name="from_date" class="form-control form-control-compact" value="<?= htmlspecialchars($fromDate) ?>">
                </div>
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label-compact">
                        📅 Đến ngày:
                    </label>
                    <input type="date" name="to_date" class="form-control form-control-compact" value="<?= htmlspecialchars($toDate) ?>">
                </div>
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label-compact">
                        📑 Mã phiếu chỉ thị:
                    </label>
                    <input type="text" name="slip_code" class="form-control form-control-compact font-monospace" placeholder="vd: PL-2610-01..." value="<?= htmlspecialchars($slipCode) ?>">
                </div>
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label-compact">
                        🏷️ Mã chỉ thị (Lot No):
                    </label>
                    <input type="text" name="order_code" class="form-control form-control-compact font-monospace" placeholder="vd: LOT-A101..." value="<?= htmlspecialchars($orderCode) ?>">
                </div>
            </div>

            <!-- HÀNG 2: MÃ SẢN PHẨM, NHÂN VIÊN, ĐỊNH MỨC & NÚT THAO TÁC (4 CỘT NGANG CÂN ĐỐI) -->
            <div class="row g-2 align-items-end">
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label-compact">
                        📦 Mã sản phẩm:
                    </label>
                    <input type="text" name="product_code" class="form-control form-control-compact font-monospace" placeholder="vd: SMC-VALVE..." value="<?= htmlspecialchars($productCode) ?>">
                </div>
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label-compact">
                        👤 Người thực hiện:
                    </label>
                    <select name="operator_id" class="form-select form-select-compact">
                        <option value="">-- Tất cả nhân viên --</option>
                        <?php foreach ($operators as $op): ?>
                            <option value="<?= $op['id'] ?>" <?= ($operatorId === (int)$op['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($op['full_name']) ?> (<?= htmlspecialchars($op['employee_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-md-2">
                    <label class="form-label-compact">
                        ⚖️ Tình trạng định mức:
                    </label>
                    <select name="is_over_target" class="form-select form-select-compact">
                        <option value="">-- Tất cả --</option>
                        <option value="0" <?= ($isOverTarget === '0') ? 'selected' : '' ?>>Đúng định mức</option>
                        <option value="1" <?= ($isOverTarget === '1') ? 'selected' : '' ?>>Vượt định mức</option>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-md-4">
                    <label class="form-label-compact">
                        ⚡ Thao tác:
                    </label>
                    <div class="d-flex gap-1 align-items-center">
                        <button type="submit" class="btn btn-primary btn-filter-action flex-grow-1" title="Áp dụng lọc dữ liệu">
                            🔍 Lọc Dữ Liệu
                        </button>
                        <a href="export.php?<?= $exportQuery ?>" class="btn btn-success btn-filter-action flex-grow-1" title="Xuất dữ liệu Excel / CSV theo điều kiện lọc hiện tại">
                            📊 Xuất Excel
                        </a>
                        <?php if ($hasFilter): ?>
                            <a href="history.php" class="btn btn-outline-secondary btn-filter-action" title="Xóa toàn bộ điều kiện lọc, hiển thị mặc định">
                                ✖ Bỏ Lọc
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- BẢNG KẾT QUẢ LỊCH SỬ -->
<div class="card shadow-sm">
    <div class="card-header bg-white py-2 px-3 d-flex justify-content-between align-items-center">
        <span class="card-title fw-bold text-dark m-0">Kết Quả Lịch Sử In (<?= count($historyList) ?> lượt in)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive table-sticky-container">
            <table class="table table-hover align-middle mb-0 table-sticky">
                <thead class="table-light table-sticky-header">
                    <tr>
                        <th style="width:55px;" class="text-center">ID</th>
                        <th>Thời Gian</th>
                        <th>Mã Phiếu &amp; Chỉ Thị</th>
                        <th>Mã Sản Phẩm</th>
                        <th class="text-end">SL In</th>
                        <th class="text-center">Số Tem Thùng</th>
                        <th>Phân Loại Thùng</th>
                        <th>Danh Sách Box No</th>
                        <th class="text-center">Định Mức</th>
                        <th>Người Thực Hiện</th>
                        <th class="text-center" style="width:110px;">Thao Tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($historyList)): ?>
                        <tr>
                            <td colspan="11" style="text-align:center;padding:30px;color:var(--text-muted);">
                                Không có lịch sử in nào phù hợp với bộ lọc.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($historyList as $row): 
                            $boxNumbers = json_decode($row['box_numbers'], true) ?: [];
                            $boxCount = count($boxNumbers);
                            $boxSummary = '';
                            if ($boxCount > 0) {
                                if ($boxCount === 1) {
                                    $boxSummary = $boxNumbers[0];
                                } else {
                                    $boxSummary = $boxNumbers[0] . ' &rarr; ' . $boxNumbers[$boxCount - 1];
                                }
                            }
                        ?>
                        <tr>
                            <td>#<?= $row['id'] ?></td>
                            <td style="font-size:13px;white-space:nowrap;">
                                <?= formatDateTime($row['created_at']) ?>
                            </td>
                            <td>
                                <strong style="color:#2563eb;"><?= htmlspecialchars($row['slip_code']) ?></strong>
                                <div style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($row['order_code']) ?></div>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($row['product_code']) ?></strong>
                                <?php if (!empty($row['issue_month'])): ?>
                                    <div style="font-size:11px;color:var(--text-muted);">Tháng: <?= htmlspecialchars($row['issue_month']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right;font-weight:700;color:#1e293b;">
                                <?= number_format($row['print_qty']) ?> con
                            </td>
                            <td style="text-align:center;">
                                <strong style="font-size:15px;color:#15803d;"><?= (int)$row['box_count'] ?></strong> tem
                            </td>
                            <td>
                                <?php if ($row['is_odd_box']): ?>
                                    <span class="badge badge-warning">Có thùng lẻ (<?= (int)$row['odd_qty'] ?> con)</span>
                                <?php else: ?>
                                    <span class="badge badge-success">Thùng chẵn đủ</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <code style="font-size:12px;color:#0f172a;font-weight:700;"><?= $boxSummary ?></code>
                                <?php if ($boxCount > 2): ?>
                                    <a href="javascript:void(0)" onclick="showBoxListModal(<?= htmlspecialchars(json_encode($boxNumbers)) ?>)" style="font-size:11px;color:#2563eb;margin-left:4px;">(Xem <?= $boxCount ?> mã)</a>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <?php if ($row['is_over_target']): ?>
                                    <span class="badge badge-danger" title="Duyệt bởi Admin MSNV: <?= htmlspecialchars($row['approved_by_msnv']) ?>">
                                        ⚠️ Vượt Mức
                                    </span>
                                    <div style="font-size:10px;color:#991b1b;margin-top:2px;">Duyệt: <?= htmlspecialchars($row['approved_by_msnv']) ?></div>
                                <?php else: ?>
                                    <span class="badge badge-neutral">Chuẩn</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:13px;">
                                <strong><?= htmlspecialchars($row['operator_name']) ?></strong>
                                <div style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($row['operator_msnv']) ?></div>
                            </td>
                            <td style="text-align:center;">
                                <a href="print_labels.php?history_id=<?= $row['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="In lại bộ tem này">
                                    🖨️ In Lại
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL XEM CHI TIẾT DANH SÁCH MÃ THÙNG -->
<div id="modal_box_list" class="modal-overlay">
    <div class="modal-dialog" style="max-width:480px;">
        <div class="modal-header">
            <span class="modal-title">📦 Danh Sách Box No Đã Tạo</span>
            <button type="button" class="modal-close" onclick="closeModal('modal_box_list')">&times;</button>
        </div>
        <div class="modal-body">
            <ul id="box_list_ul" style="list-style:none;max-height:300px;overflow-y:auto;padding:0;font-family:monospace;font-size:14px;">
            </ul>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('modal_box_list')">Đóng</button>
        </div>
    </div>
</div>

<script>
function showBoxListModal(boxArr) {
    const ul = document.getElementById('box_list_ul');
    if (!ul) return;
    ul.innerHTML = '';
    boxArr.forEach((b, i) => {
        const li = document.createElement('li');
        li.style.padding = '8px 12px';
        li.style.borderBottom = '1px solid #f1f5f9';
        li.innerHTML = `<strong>${i + 1}.</strong> <span style="color:#2563eb;font-weight:700;">${b}</span>`;
        ul.appendChild(li);
    });
    openModal('modal_box_list');
}

function openModal(id) { 
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.add('active'); 
    document.body.classList.add('modal-open');
}

function closeModal(id) { 
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.remove('active'); 
    if (!document.querySelector('.modal-overlay.active')) {
        document.body.classList.remove('modal-open');
    }
}

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

window.openModal = openModal;
window.closeModal = closeModal;
window.showBoxListModal = showBoxListModal;
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

