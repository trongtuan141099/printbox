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
?>

<div class="page-header">
    <div>
        <h1 class="page-title">📜 Lịch Sử In Tem Sản Xuất</h1>
        <!-- <p style="color:var(--text-muted);font-size:14px;margin-top:2px;">
            Tra cứu vết in tem thùng &bull; Chi tiết Box No &bull; In lại nhãn (Reprint) &bull; Xuất báo cáo Excel
        </p> -->
    </div>
    <div class="page-actions">
        <a href="export.php?<?= $queryString ?>" class="btn btn-success">
            📊 Xuất Dữ Liệu Excel / CSV
        </a>
        <!-- <a href="orders.php" class="btn btn-primary">
            📋 Chỉ Thị SX &amp; In Tem
        </a> -->
    </div>
</div>

<!-- KHỐI BỘ LỌC TÌM KIẾM NÂNG CAO -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-header">
        <span class="card-title">🔍 Bộ Lọc Tìm Kiếm Nâng Cao</span>
    </div>
    <div class="card-body">
        <form method="GET" action="history.php">
            <div class="grid-4" style="margin-bottom:14px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Từ ngày</label>
                    <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($fromDate) ?>">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Đến ngày</label>
                    <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($toDate) ?>">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Mã phiếu chỉ thị</label>
                    <input type="text" name="slip_code" class="form-control" placeholder="vd: PL-2610-01" value="<?= htmlspecialchars($slipCode) ?>">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Mã chỉ thị (Lot No)</label>
                    <input type="text" name="order_code" class="form-control" placeholder="vd: LOT-A101" value="<?= htmlspecialchars($orderCode) ?>">
                </div>
            </div>

            <div class="grid-4" style="align-items:flex-end;">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Mã sản phẩm</label>
                    <input type="text" name="product_code" class="form-control" placeholder="vd: SMC-VALVE" value="<?= htmlspecialchars($productCode) ?>">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Người thực hiện</label>
                    <select name="operator_id" class="form-control">
                        <option value="">-- Tất cả nhân viên --</option>
                        <?php foreach ($operators as $op): ?>
                            <option value="<?= $op['id'] ?>" <?= ($operatorId === (int)$op['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($op['full_name']) ?> (<?= htmlspecialchars($op['employee_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Tình trạng định mức</label>
                    <select name="is_over_target" class="form-control">
                        <option value="">-- Tất cả --</option>
                        <option value="0" <?= ($isOverTarget === '0') ? 'selected' : '' ?>>Đúng định mức</option>
                        <option value="1" <?= ($isOverTarget === '1') ? 'selected' : '' ?>>Vượt định mức (Đã duyệt)</option>
                    </select>
                </div>
                <div style="display:flex;gap:8px;">
                    <button type="submit" class="btn btn-primary" style="flex:1;">🔍 Lọc Dữ Liệu</button>
                    <a href="history.php" class="btn btn-secondary">✖ Bỏ Lọc</a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- BẢNG KẾT QUẢ LỊCH SỬ -->
<div class="card">
    <div class="card-header">
        <span class="card-title">Kết Quả Lịch Sử In (<?= count($historyList) ?> lượt in)</span>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th style="width:60px;">ID</th>
                        <th>Thời Gian</th>
                        <th>Mã Phiếu &amp; Chỉ Thị</th>
                        <th>Mã Sản Phẩm</th>
                        <th style="text-align:right;">SL In</th>
                        <th style="text-align:center;">Số Tem Thùng</th>
                        <th>Phân Loại Thùng</th>
                        <th>Danh Sách Box No</th>
                        <th style="text-align:center;">Định Mức</th>
                        <th>Người Thực Hiện</th>
                        <th style="text-align:center;width:120px;">Thao Tác</th>
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
                                <a href="print_labels.php?history_id=<?= $row['id'] ?>" target="_blank" class="btn btn-sm btn-secondary" title="In lại bộ tem này">
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

