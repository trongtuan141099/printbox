<?php
/**
 * TRANG CẤU HÌNH HỆ THỐNG & ĐỘNG HÓA MÃ QR (ADMIN SETTINGS)
 * Cho phép Quản trị viên tùy biến cấu trúc chuỗi QR tem thùng:
 * Tiền tố, hậu tố, ký tự phân cách, thứ tự trường dữ liệu và định dạng ngày.
 */
$pageTitle = "Cấu Hình Hệ Thống & Mã QR";
require_once __DIR__ . '/includes/header.php';
requireRole('admin');

$pdo = getDbConnection();
$currentSettings = getSystemSettings($pdo);

// XỬ LÝ LƯU CẤU HÌNH
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    $qrPrefix     = trim($_POST['qr_prefix'] ?? 'SMC4$');
    $qrDelimiter  = trim($_POST['qr_delimiter'] ?? '$');
    $qrSuffix     = trim($_POST['qr_suffix'] ?? '');
    $qrDateFormat = trim($_POST['qr_date_format'] ?? 'd/m/Y');
    $selectedFields = $_POST['qr_fields'] ?? [];

    if (!is_array($selectedFields)) {
        $selectedFields = [];
    }

    $qrFieldsJson = json_encode(array_values($selectedFields), JSON_UNESCAPED_UNICODE);

    try {
        updateSystemSetting('qr_prefix', $qrPrefix, $pdo);
        updateSystemSetting('qr_delimiter', $qrDelimiter, $pdo);
        updateSystemSetting('qr_suffix', $qrSuffix, $pdo);
        updateSystemSetting('qr_date_format', $qrDateFormat, $pdo);
        updateSystemSetting('qr_fields', $qrFieldsJson, $pdo);

        if (isset($_POST['label_width_mm'])) {
            updateSystemSetting('label_width_mm', (int)$_POST['label_width_mm'], $pdo);
        }
        if (isset($_POST['label_height_mm'])) {
            updateSystemSetting('label_height_mm', (int)$_POST['label_height_mm'], $pdo);
        }

        setFlash('success', 'Đã lưu thành công cấu hình cấu trúc mã QR tem thùng!');
        header("Location: settings.php");
        exit;
    } catch (Exception $e) {
        setFlash('danger', 'Lỗi lưu cấu hình: ' . $e->getMessage());
    }
}

// XỬ LÝ KHÔI PHỤC MẶC ĐỊNH
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_defaults') {
    $defaultFields = [
        'material_name', 'empty', 'qty', 'empty', 'empty', 'empty', 'empty',
        'box_no', 'invoice_no', 'order_no', 'bundle_no', 'weight',
        'input_date', 'lot_no', 'supplier'
    ];
    updateSystemSetting('qr_prefix', 'SMC4$', $pdo);
    updateSystemSetting('qr_delimiter', '$', $pdo);
    updateSystemSetting('qr_suffix', '', $pdo);
    updateSystemSetting('qr_date_format', 'd/m/Y', $pdo);
    updateSystemSetting('qr_fields', json_encode($defaultFields), $pdo);

    setFlash('info', 'Đã khôi phục cấu hình mã QR về mặc định chuẩn nhà máy SMC4!');
    header("Location: settings.php");
    exit;
}

$activeFields = json_decode($currentSettings['qr_fields'] ?? '[]', true) ?: [];

// Danh mục tất cả các trường dữ liệu có sẵn
$availableFieldDefs = [
    'material_name' => 'Mã sản phẩm (Material Name)',
    'qty'           => 'Quy cách con/thùng (Quantity)',
    'box_no'        => 'Mã thùng TU-YYMMDD-XXX (Box No)',
    'lot_no'        => 'Mã chỉ thị / Số Lô (Lot No / CTSX)',
    'input_date'    => 'Ngày nhập / Ngày sản xuất (Input Date)',
    'supplier'      => 'Nhà cung cấp (Supplier)',
    'weight'        => 'Trọng lượng thùng Kg (Weight)',
    'issue_month'   => 'Tháng phát hành (Month of Issue)',
    'slip_code'     => 'Mã phiếu chỉ thị (Slip Code)',
    'invoice_no'    => 'Số hóa đơn (Invoice No)',
    'order_no'      => 'Mã đơn PO (Order No)',
    'bundle_no'     => 'Số Bundle (Bundle No)',
    'empty'         => 'Ô trống phân cách (Empty Slot)'
];

// Sinh chuỗi mẫu xem trước
$sampleQrStr = buildBoxQrContent([
    'material_name' => 'SMC-VALVE-50A',
    'qty'           => 10,
    'box_no'        => 'TU-' . date('ymd') . '-001',
    'lot_no'        => 'LOT-A101',
    'input_date'    => date('Y-m-d'),
    'supplier'      => 'SMC Factory 1',
    'weight'        => '2.50',
    'issue_month'   => '10/2026',
    'slip_code'     => 'PL-2610-01',
    'invoice_no'    => '',
    'order_no'      => '',
    'bundle_no'     => ''
], $pdo);
$sampleQrImg = generateQrDataUri($sampleQrStr, 6, 4);
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 fw-bold text-dark mb-1">⚙️ Cấu Hình Mã QR &amp; Nhãn In Tem Thùng</h1>
        <p class="text-secondary small mb-0">Tùy biến cấu trúc chuỗi dữ liệu mã QR &bull; Thứ tự trường dữ liệu &bull; Tiền tố &amp; Ký tự phân cách</p>
    </div>
    <div>
        <form method="POST" action="settings.php" onsubmit="return confirm('Khôi phục cấu trúc QR về mặc định chuẩn SMC4$?');" class="d-inline">
            <input type="hidden" name="action" value="reset_defaults">
            <button type="submit" class="btn btn-outline-secondary btn-sm">
                🔄 Khôi Phục Mặc Định
            </button>
        </form>
    </div>
</div>

<div class="row g-4">
    <!-- CỘT BÊN TRÁI: FORM CẤU HÌNH -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="card-title mb-0 fw-bold text-primary">🛠️ Thiết Lập Cấu Trúc Mã QR</h5>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="settings.php" id="settingsForm">
                    <input type="hidden" name="action" value="save_settings">

                    <!-- HÀNG 1: TIỀN TỐ, PHÂN CÁCH & HẬU TỐ -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold small text-secondary">Tiền tố (Prefix)</label>
                            <input type="text" name="qr_prefix" id="input_prefix" class="form-control" 
                                   value="<?= htmlspecialchars($currentSettings['qr_prefix']) ?>" placeholder="vd: SMC4$ hoặc PROD|">
                            <div class="form-text small">Ký tự mở đầu chuỗi QR</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small text-secondary">Ký tự phân cách (Delimiter)</label>
                            <input type="text" name="qr_delimiter" id="input_delimiter" class="form-control fw-bold text-center" 
                                   value="<?= htmlspecialchars($currentSettings['qr_delimiter']) ?>" placeholder="vd: $ hoặc |">
                            <div class="form-text small">Ngăn cách các trường</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small text-secondary">Hậu tố (Suffix)</label>
                            <input type="text" name="qr_suffix" id="input_suffix" class="form-control" 
                                   value="<?= htmlspecialchars($currentSettings['qr_suffix']) ?>" placeholder="Tùy chọn kết thúc">
                            <div class="form-text small">Ký tự cuối chuỗi (nếu có)</div>
                        </div>
                    </div>

                    <!-- HÀNG 2: ĐỊNH DẠNG NGÀY -->
                    <div class="mb-4">
                        <label class="form-label fw-bold small text-secondary">Định dạng ngày trong mã QR</label>
                        <select name="qr_date_format" id="input_date_format" class="form-select">
                            <option value="d/m/Y" <?= ($currentSettings['qr_date_format'] === 'd/m/Y') ? 'selected' : '' ?>>DD/MM/YYYY (Ngày/Tháng/Năm - vd: <?= date('d/m/Y') ?>)</option>
                            <option value="Y-m-d" <?= ($currentSettings['qr_date_format'] === 'Y-m-d') ? 'selected' : '' ?>>YYYY-MM-DD (Năm-Tháng-Ngày - vd: <?= date('Y-m-d') ?>)</option>
                            <option value="Ymd" <?= ($currentSettings['qr_date_format'] === 'Ymd') ? 'selected' : '' ?>>YYYYMMDD (Viết liền - vd: <?= date('Ymd') ?>)</option>
                            <option value="d-m-Y" <?= ($currentSettings['qr_date_format'] === 'd-m-Y') ? 'selected' : '' ?>>DD-MM-YYYY (vd: <?= date('d-m-Y') ?>)</option>
                        </select>
                    </div>

                    <!-- HÀNG 3: DANH SÁCH THỨ TỰ CÁC TRƯỜNG DỮ LIỆU -->
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label fw-bold small text-secondary mb-0">Thứ tự các trường trong chuỗi QR (Kéo thả hoặc thêm bớt)</label>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addFieldSlot()">
                                ➕ Thêm Vị Trí
                            </button>
                        </div>
                        <p class="text-muted small">Mã QR sẽ nối giá trị của các trường theo thứ tự từ trên xuống dưới bằng ký tự phân cách.</p>

                        <div id="fieldsListContainer" class="d-flex flex-column gap-2 p-2 bg-light rounded-3 border">
                            <?php foreach ($activeFields as $idx => $fKey): ?>
                                <div class="field-item-row d-flex align-items-center gap-2 p-2 bg-white rounded border shadow-sm">
                                    <span class="badge bg-secondary-subtle text-dark-emphasis px-2 py-1 field-index"><?= $idx + 1 ?></span>
                                    <select name="qr_fields[]" class="form-select form-select-sm field-select" onchange="updatePreview()">
                                        <?php foreach ($availableFieldDefs as $k => $label): ?>
                                            <option value="<?= $k ?>" <?= ($k === $fKey) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="btn btn-sm btn-outline-danger border-0" onclick="removeFieldRow(this)" title="Xóa vị trí này">
                                        ❌
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold shadow-sm">
                        💾 LƯU CẤU HÌNH MÃ QR
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- CỘT BÊN PHẢI: XEM TRƯỚC TRỰC QUAN (REAL-TIME PREVIEW) -->
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm rounded-3 sticky-top" style="top: 80px;">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="card-title mb-0 fw-bold text-dark">👁️ Xem Trước Chuỗi QR &amp; Tem Nhãn</h5>
            </div>
            <div class="card-body p-4 text-center">
                <div class="mb-3">
                    <img id="previewQrImg" src="<?= $sampleQrImg ?>" alt="Preview QR" 
                         class="border p-2 bg-white rounded shadow-sm" style="width: 160px; height: 160px; image-rendering: pixelated;">
                </div>

                <div class="mb-3 text-start">
                    <label class="form-label fw-bold small text-secondary">Chuỗi QR Code kết quả:</label>
                    <div id="previewQrText" class="p-2 bg-light border rounded small font-monospace text-break" style="max-height: 120px; overflow-y: auto;">
                        <?= htmlspecialchars($sampleQrStr) ?>
                    </div>
                </div>

                <!-- MẪU TEM NHÃN 65x30MM -->
                <div class="text-start mt-4 pt-3 border-top">
                    <label class="form-label fw-bold small text-secondary mb-2">Xem trước định dạng tem nhiệt (65mm &times; 30mm):</label>
                    <div class="d-flex justify-content-center p-3 bg-secondary-subtle rounded-3">
                        <div class="box-label-item" style="box-shadow: 0 4px 6px rgba(0,0,0,0.15);">
                            <div class="label-qr-col">
                                <img id="previewMiniQr" src="<?= $sampleQrImg ?>" alt="QR">
                            </div>
                            <div class="label-text-col">
                                <div class="label-line-1">SMC-VALVE-50A</div>
                                <div class="label-line-2">10 pcs | 2.50 Kg</div>
                                <div class="label-line-3">||</div>
                                <div class="label-line-4">LOT-A101 | SMC Factory 1</div>
                                <div class="label-line-5">TU-<?= date('ymd') ?>-001</div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
const availableDefs = <?= json_encode($availableFieldDefs, JSON_UNESCAPED_UNICODE) ?>;

function addFieldSlot() {
    const container = document.getElementById('fieldsListContainer');
    const count = container.querySelectorAll('.field-item-row').length + 1;

    let optionsHtml = '';
    for (const [k, label] of Object.entries(availableDefs)) {
        optionsHtml += `<option value="${k}">${label}</option>`;
    }

    const row = document.createElement('div');
    row.className = 'field-item-row d-flex align-items-center gap-2 p-2 bg-white rounded border shadow-sm';
    row.innerHTML = `
        <span class="badge bg-secondary-subtle text-dark-emphasis px-2 py-1 field-index">${count}</span>
        <select name="qr_fields[]" class="form-select form-select-sm field-select" onchange="updatePreview()">
            ${optionsHtml}
        </select>
        <button type="button" class="btn btn-sm btn-outline-danger border-0" onclick="removeFieldRow(this)" title="Xóa vị trí này">
            ❌
        </button>
    `;
    container.appendChild(row);
    renumberFields();
    updatePreview();
}

function removeFieldRow(btn) {
    const row = btn.closest('.field-item-row');
    if (document.querySelectorAll('.field-item-row').length <= 1) {
        alert('Cấu trúc mã QR cần ít nhất 1 trường dữ liệu!');
        return;
    }
    row.remove();
    renumberFields();
    updatePreview();
}

function renumberFields() {
    document.querySelectorAll('.field-item-row').forEach((row, idx) => {
        row.querySelector('.field-index').innerText = idx + 1;
    });
}

function updatePreview() {
    const prefix = document.getElementById('input_prefix').value;
    const delimiter = document.getElementById('input_delimiter').value;
    const suffix = document.getElementById('input_suffix').value;

    const sampleValues = {
        'material_name': 'SMC-VALVE-50A',
        'qty': '10',
        'box_no': 'TU-<?= date('ymd') ?>-001',
        'lot_no': 'LOT-A101',
        'input_date': '<?= date('d/m/Y') ?>',
        'supplier': 'SMC Factory 1',
        'weight': '2.50',
        'issue_month': '10/2026',
        'slip_code': 'PL-2610-01',
        'invoice_no': '',
        'order_no': '',
        'bundle_no': '',
        'empty': ''
    };

    const selects = document.querySelectorAll('.field-select');
    const parts = [];
    selects.forEach(sel => {
        const val = sel.value;
        parts.push(sampleValues[val] !== undefined ? sampleValues[val] : '');
    });

    const qrText = prefix + parts.join(delimiter) + suffix;
    const txtEl = document.getElementById('previewQrText');
    if (txtEl) txtEl.innerText = qrText;

    refreshQrImages(qrText);
}

let qrUpdateTimer = null;
function refreshQrImages(text) {
    if (!text) return;
    clearTimeout(qrUpdateTimer);
    qrUpdateTimer = setTimeout(() => {
        fetch('ajax/generate_qr.php?format=base64&text=' + encodeURIComponent(text))
            .then(res => res.json())
            .then(data => {
                if (data.success && data.data_uri) {
                    const img1 = document.getElementById('previewQrImg');
                    const img2 = document.getElementById('previewMiniQr');
                    if (img1) img1.src = data.data_uri;
                    if (img2) img2.src = data.data_uri;
                }
            })
            .catch(err => console.log('Preview QR error:', err));
    }, 250);
}

document.getElementById('input_prefix').addEventListener('input', updatePreview);
document.getElementById('input_delimiter').addEventListener('input', updatePreview);
document.getElementById('input_suffix').addEventListener('input', updatePreview);
document.getElementById('input_date_format').addEventListener('change', updatePreview);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

