<?php
/**
 * TRANG CẤU HÌNH HỆ THỐNG & ĐỘNG HÓA MÃ QR (ADMIN SETTINGS)
 * Cho phép Quản trị viên tùy biến cấu trúc chuỗi QR tem thùng:
 * Tiền tố, hậu tố, ký tự phân cách, thứ tự trường dữ liệu và định dạng ngày.
 */
ob_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Kiểm tra bắt buộc đăng nhập và phân quyền Quản trị viên trước khi thao tác
requireAuth();
requireRole('admin');

$pdo = getDbConnection();

// ========================================================
// 1. XỬ LÝ LƯU CẤU HÌNH (POST: save_settings)
// ========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    $qrPrefix     = trim($_POST['qr_prefix'] ?? 'SMC4$');
    $qrDelimiter  = trim($_POST['qr_delimiter'] ?? '$');
    $qrSuffix     = trim($_POST['qr_suffix'] ?? '');
    $qrDateFormat = trim($_POST['qr_date_format'] ?? 'd/m/Y');
    $selectedTypes   = $_POST['qr_field_types'] ?? $_POST['qr_fields'] ?? [];
    $selectedCustoms = $_POST['qr_field_customs'] ?? [];

    $savedFields = [];
    if (is_array($selectedTypes)) {
        foreach ($selectedTypes as $idx => $type) {
            $type = trim($type);
            if ($type === '') continue;

            if ($type === 'custom_fixed') {
                $customVal = trim($selectedCustoms[$idx] ?? '');
                $savedFields[] = 'custom_fixed:' . $customVal;
            } else {
                $savedFields[] = $type;
            }
        }
    }

    $qrFieldsJson = json_encode(array_values($savedFields), JSON_UNESCAPED_UNICODE);

    try {
        updateSystemSetting('qr_prefix', $qrPrefix, $pdo);
        updateSystemSetting('qr_delimiter', $qrDelimiter, $pdo);
        updateSystemSetting('qr_suffix', $qrSuffix, $pdo);
        updateSystemSetting('qr_date_format', $qrDateFormat, $pdo);
        updateSystemSetting('qr_fields', $qrFieldsJson, $pdo);

        if (!empty($_POST['default_label_size'])) {
            saveDefaultLabelSize($_POST['default_label_size'], $pdo);
        }
        if (isset($_POST['label_width_mm'])) {
            updateSystemSetting('label_width_mm', (int)$_POST['label_width_mm'], $pdo);
        }
        if (isset($_POST['label_height_mm'])) {
            updateSystemSetting('label_height_mm', (int)$_POST['label_height_mm'], $pdo);
        }
        if (!empty($_POST['default_printer'])) {
            saveDefaultPrinter(trim($_POST['default_printer']), $pdo);
        }

        setFlash('success', 'Đã lưu thành công cấu hình hệ thống, máy in mặc định và cấu trúc mã QR tem thùng!');
        header("Location: settings.php");
        exit;
    } catch (Exception $e) {
        setFlash('danger', 'Lỗi lưu cấu hình: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_printer_only') {
    $printerName = trim($_POST['default_printer'] ?? '');
    if ($printerName !== '') {
        saveDefaultPrinter($printerName, $pdo);
    }
    if (!empty($_POST['default_label_size'])) {
        saveDefaultLabelSize($_POST['default_label_size'], $pdo);
    }
    setFlash('success', "Đã lưu thành công cấu hình máy in và khổ tem in mặc định!");
    header("Location: settings.php");
    exit;
}

// ========================================================
// 2. XỬ LÝ KHÔI PHỤC MẶC ĐỊNH (POST: reset_defaults)
// ========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_defaults') {
    $defaultFields = [
        'material_name', 'empty', 'qty', 'empty', 'empty', 'empty', 'empty',
        'box_no', 'invoice_no', 'order_no', 'bundle_no', 'weight',
        'input_date', 'lot_no', 'supplier'
    ];
    updateSystemSetting('qr_prefix', 'SMC002$', $pdo);
    updateSystemSetting('qr_delimiter', '$', $pdo);
    updateSystemSetting('qr_suffix', '', $pdo);
    updateSystemSetting('qr_date_format', 'd/m/Y', $pdo);
    updateSystemSetting('qr_fields', json_encode($defaultFields), $pdo);

    setFlash('info', 'Đã khôi phục cấu hình mã QR về mặc định chuẩn nhà máy SMC002!');
    header("Location: settings.php");
    exit;
}

// ========================================================
// 3. TẢI DỮ LIỆU CẤU HÌNH CHO GIAO DIỆN HIỂN THỊ
// ========================================================
$forceRefreshPrinters = isset($_GET['refresh_printers']);
$connectedPrinters = [];
$defaultPrinter = getDefaultPrinter($pdo, $forceRefreshPrinters);
$defaultLabelSize = getDefaultLabelSize($pdo, $forceRefreshPrinters);
$currentSettingsUser = getCurrentUser();
$currentSettingsUserId = (int)($currentSettingsUser['id'] ?? 0);
$currentSettings = getSystemSettings($pdo, true);
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
    'custom_fixed'  => '🔤 Ký tự cố định (Custom Fixed Text)',
    'date_code_ym'  => '📅 Ký tự quy ước Năm/Tháng (Year/Month Code)',
    'date_code_y'   => '📅 Ký tự quy ước Năm (Year Code)',
    'date_code_m'   => '📅 Ký tự quy ước Tháng (Month Code)',
    'empty'         => 'Ô trống phân cách (Empty Slot)'
];

// Sinh chuỗi mẫu xem trước
$sampleQrStr = buildBoxQrContent([
    'material_name' => 'TU0425BU-20Z2',
    'qty'           => 10,
    'box_no'        => 'TU-' . date('ymd') . '-001',
    'lot_no'        => 'P260906297',
    'input_date'    => date('Ymd'),
    'supplier'      => 'SMC Factory 2',
    'weight'        => '15',
    'issue_month'   => '10/2026',
    'slip_code'     => '579387',
    'invoice_no'    => '',
    'order_no'      => '',
    'bundle_no'     => ''
], $pdo);
$sampleQrImg = generateQrDataUri($sampleQrStr, 8, 1);

// NẠP GIAO DIỆN HEADER (CHỈ GỌI KHI KHÔNG CÓ REDIRECT)
$pageTitle = "Cấu Hình Hệ Thống & Mã QR";
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
    <div>
        <h1 class="page-title m-0">⚙️ Cấu Hình Mã QR &amp; Nhãn In Tem Thùng</h1>
        <p class="text-muted small mb-0 mt-1">Tùy biến cấu trúc chuỗi dữ liệu mã QR &bull; Thứ tự trường dữ liệu &bull; Tiền tố &amp; Ký tự phân cách</p>
    </div>
    <div>
        <form method="POST" action="settings.php" onsubmit="return confirm('Khôi phục cấu trúc QR về mặc định chuẩn SMC002$?');" class="d-inline">
            <input type="hidden" name="action" value="reset_defaults">
            <button type="submit" class="btn btn-outline-secondary btn-sm">
                🔄 Khôi Phục Mặc Định
            </button>
        </form>
    </div>
</div>

<div class="row g-3">
    <!-- CỘT BÊN TRÁI: FORM CẤU HÌNH -->
    <div class="col-lg-7">
        <!-- CẤU HÌNH MÁY IN TEM SỬ DỤNG MẶC ĐỊNH -->
        <div class="card shadow-sm mb-3 border-primary">
            <div class="card-header bg-white py-2 px-3 d-flex justify-content-between align-items-center">
                <span class="card-title fw-bold text-primary m-0">🖨️ Cấu Hình Máy In Tem Sử Dụng Mặc Định</span>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="refreshSettingsClientPrinters(true)" title="Quét lại danh sách máy in kết nối trên máy tính hiện tại">
                    🔄 Quét lại máy in
                </button>
            </div>
            <div class="card-body p-3">
                <form method="POST" action="settings.php" id="printerSettingsForm" onsubmit="return onSettingsPrinterFormSubmit();">
                    <input type="hidden" name="action" value="save_printer_only">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-5">
                            <label class="form-label fw-bold small text-secondary mb-1">
                                🖨️ Máy In Tem Sử Dụng (Mặc định)
                            </label>
                            <select name="default_printer" id="settings_default_printer" class="form-select fw-bold" onchange="onSettingsPrinterSelectChange(this.value)">
                                <option value="">⏳ Đang tải danh sách máy in trên máy tính hiện tại...</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small text-secondary mb-1">
                                📐 Khổ Tem In Mặc Định
                            </label>
                            <select name="default_label_size" id="settings_default_label_size" class="form-select fw-bold" onchange="updateSettingsLabelPreview(this.value)">
                                <option value="65x30" <?= ($defaultLabelSize === '65x30') ? 'selected' : '' ?>>Tem nhỏ (65 × 30 mm - Ngang)</option>
                                <option value="110x80" <?= ($defaultLabelSize === '110x80') ? 'selected' : '' ?>>Tem lớn (80 × 100 / 100 × 80 / 110 × 80 mm - Ngang)</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-success w-100 fw-bold">
                                💾 Lưu Cấu Hình In
                            </button>
                        </div>
                    </div>
                    <div id="settings_printer_status_msg" class="small fw-bold mt-2" style="display:none;"></div>
                    <div class="form-text small mt-1">
                        Chỉ hiển thị các máy in kết nối thực tế với máy tính hiện tại (Client PC). Lựa chọn này được lưu làm mặc định theo người dùng và trình duyệt.
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white py-2 px-3">
                <span class="card-title fw-bold text-primary m-0">🛠️ Thiết Lập Cấu Trúc Mã QR</span>
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
                            <?php foreach ($activeFields as $idx => $fieldItem): 
                                $fType = $fieldItem;
                                $fCustom = '';
                                if (str_starts_with($fieldItem, 'custom_fixed:')) {
                                    $fType = 'custom_fixed';
                                    $fCustom = substr($fieldItem, 13);
                                } elseif (str_starts_with($fieldItem, 'fixed:')) {
                                    $fType = 'custom_fixed';
                                    $fCustom = substr($fieldItem, 6);
                                } elseif ($fieldItem === 'custom_fixed') {
                                    $fType = 'custom_fixed';
                                    $fCustom = '';
                                }
                                $isFixed = ($fType === 'custom_fixed');
                            ?>
                                <div class="field-item-row d-flex align-items-center gap-2 p-2 bg-white rounded border shadow-sm">
                                    <span class="badge bg-secondary-subtle text-dark-emphasis px-2 py-1 field-index"><?= $idx + 1 ?></span>
                                    <select name="qr_field_types[]" class="form-select form-select-sm field-select flex-grow-1" onchange="onFieldTypeChange(this)">
                                        <?php foreach ($availableFieldDefs as $k => $label): ?>
                                            <option value="<?= $k ?>" <?= ($k === $fType) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" name="qr_field_customs[]" class="form-control form-control-sm field-custom-input" 
                                           placeholder="Nhập ký tự (vd: YD)..." 
                                           value="<?= htmlspecialchars($fCustom) ?>"
                                           style="<?= $isFixed ? 'display:block;' : 'display:none;' ?> max-width: 170px;" 
                                           oninput="updatePreview()" title="Ký tự cố định chèn vào vị trí này">
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
        <div class="card shadow-sm sticky-top" style="top: 75px;">
            <div class="card-header bg-white py-2 px-3">
                <span class="card-title fw-bold text-dark m-0">👁️ Xem Trước Chuỗi QR &amp; Tem Nhãn</span>
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

                <!-- MẪU TEM NHÃN XEM TRƯỚC -->
                <div class="text-start mt-4 pt-3 border-top">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label fw-bold small text-secondary mb-0">Xem trước mẫu tem in (<span id="settings_preview_size_label"><?= ($defaultLabelSize === '110x80') ? '110mm × 80mm (Ngang)' : '65mm × 30mm (Ngang)' ?></span>):</label>
                    </div>
                    <div class="d-flex justify-content-center p-3 bg-secondary-subtle rounded-3" id="settings_preview_container">
                        <div class="box-label-item <?= ($defaultLabelSize === '110x80') ? 'label-size-110x80' : 'label-size-65x30' ?>" id="settings_preview_box" style="box-shadow: 0 4px 6px rgba(0,0,0,0.15);">
                            <div class="label-qr-col">
                                <img id="previewMiniQr" src="<?= $sampleQrImg ?>" alt="QR">
                            </div>
                            <div class="label-text-col">
                                <div class="label-line-1">TU0425BU-20Z2</div>
                                <div class="label-line-2">10 pcs | 2.50 Kg</div>
                                <div class="label-line-3">||</div>
                                <div class="label-line-4">P260906297 | SMC2</div>
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
const settingsCurrentUserId = <?= (int)$currentSettingsUserId ?>;
let settingsServerDefaultPrinter = <?= json_encode($defaultPrinter, JSON_UNESCAPED_UNICODE) ?: '""' ?>;
const SETTINGS_NO_PRINTER_MSG = 'Không tìm thấy máy in trên máy tính hiện tại. Vui lòng kiểm tra dịch vụ in hoặc kết nối máy in.';

function isSettingsVirtualPrinter(name) {
    const n = String(name || '').trim().toLowerCase();
    if (!n) return true;
    return ['microsoft print to pdf', 'microsoft xps document writer', 'onenote', 'send to onenote', 'fax', 'adobe pdf', 'foxit reader pdf printer', 'pdfcreator', 'cutepdf'].some(p => n.includes(p));
}

function getSettingsSavedDefaultPrinter() {
    try {
        const uVal = localStorage.getItem('printbox_default_printer_u_' + settingsCurrentUserId);
        if (uVal && !isSettingsVirtualPrinter(uVal)) return uVal;
        if (settingsServerDefaultPrinter && !isSettingsVirtualPrinter(settingsServerDefaultPrinter)) return settingsServerDefaultPrinter;
        const bVal = localStorage.getItem('printbox_client_default_printer');
        if (bVal && !isSettingsVirtualPrinter(bVal)) return bVal;
    } catch (e) {}
    return settingsServerDefaultPrinter || '';
}

function setSettingsPrinterMsg(msg, color = '#b91c1c') {
    const el = document.getElementById('settings_printer_status_msg');
    if (!el) return;
    if (!msg) {
        el.style.display = 'none';
        el.innerText = '';
    } else {
        el.style.display = 'block';
        el.style.color = color;
        el.innerText = msg;
    }
}

function onSettingsPrinterSelectChange(printerName) {
    const name = String(printerName || '').toLowerCase();
    const sizeSel = document.getElementById('settings_default_label_size');
    if (!sizeSel) return;
    if (/(80\s*x\s*110|110\s*x\s*80|80\s*x\s*100|100\s*x\s*80)/.test(name)) {
        sizeSel.value = '110x80';
        updateSettingsLabelPreview('110x80');
    } else if (/65\s*x\s*30/.test(name)) {
        sizeSel.value = '65x30';
        updateSettingsLabelPreview('65x30');
    }
}

function onSettingsPrinterFormSubmit() {
    const sel = document.getElementById('settings_default_printer');
    const sizeSel = document.getElementById('settings_default_label_size');
    if (!sel || !sel.value) {
        alert('Vui lòng chọn máy in tem đang kết nối trên máy tính hiện tại trước khi lưu!');
        return false;
    }
    try {
        localStorage.setItem('printbox_default_printer_u_' + settingsCurrentUserId, sel.value);
        localStorage.setItem('printbox_client_default_printer', sel.value);
        localStorage.setItem('printbox_default_printer', sel.value);
        localStorage.setItem('mes_default_printer', sel.value);
        if (sizeSel && sizeSel.value) {
            localStorage.setItem('printbox_default_label_size', sizeSel.value);
        }
    } catch (e) {}
    return true;
}

async function refreshSettingsClientPrinters(showFeedback = false) {
    if (showFeedback) {
        setSettingsPrinterMsg('⏳ Đang quét các máy in trên máy tính hiện tại...', '#2563eb');
    }
    const merged = new Map();
    const addList = (arr) => {
        if (!Array.isArray(arr)) return;
        arr.forEach(item => {
            if (!item || !item.name) return;
            const n = String(item.name).trim();
            if (!n || isSettingsVirtualPrinter(n)) return;
            const k = n.toLowerCase();
            if (!merged.has(k)) merged.set(k, item);
        });
    };

    const isLocalWorkstation = ['localhost', '127.0.0.1', '::1'].includes(window.location.hostname || '');
    const isHttps = (window.location.protocol === 'https:');
    const zebraUrl = isHttps ? 'https://127.0.0.1:9101/available' : 'http://127.0.0.1:9100/available';

    // Only query loopback web server if client is running directly on localhost
    const localUrls = [zebraUrl];
    if (isLocalWorkstation) {
        const basePath = (window.location.pathname || '/printbox/settings.php').replace(/settings\.php.*$/, 'orders.php');
        localUrls.push('http://127.0.0.1' + basePath + '?action=client_local_printers');
    }

    for (const url of localUrls) {
        try {
            const ctrl = new AbortController();
            const timer = setTimeout(() => ctrl.abort(), 600);
            const res = await fetch(url, { method: 'GET', mode: 'cors', signal: ctrl.signal });
            clearTimeout(timer);
            if (!res.ok) continue;
            const data = await res.json();
            if (data && Array.isArray(data.printer)) {
                addList(data.printer.map(p => ({ name: p.name, default: false })));
            } else if (data && Array.isArray(data.printers)) {
                addList(data.printers.map(p => ({
                    name: (typeof p === 'object' && p !== null) ? (p.name || '') : String(p),
                    default: Boolean(typeof p === 'object' && p !== null && p.default)
                })));
            }
        } catch (e) {
            // Suppress connection refused exceptions
        }
    }
    if (merged.size === 0) {
        try {
            const fd = new FormData();
            fd.append('action', 'refresh_printers');
            const res = await fetch('orders.php', { method: 'POST', body: fd });
            if (res.ok) {
                const data = await res.json();
                if (data && data.success && Array.isArray(data.printers)) {
                    addList(data.printers.map(p => ({
                        name: (typeof p === 'object' && p !== null) ? (p.name || '') : String(p),
                        default: Boolean(typeof p === 'object' && p !== null && p.default)
                    })));
                }
            }
        } catch (e) {}
    }

    const discovered = Array.from(merged.values());
    const saved = getSettingsSavedDefaultPrinter();
    const sel = document.getElementById('settings_default_printer');
    if (!sel) return;
    sel.innerHTML = '';

    let selectedPrinter = '';
    let savedMissing = false;

    if (discovered.length > 0) {
        if (saved) {
            const found = discovered.find(p => p.name.toLowerCase() === saved.toLowerCase());
            if (found) {
                selectedPrinter = found.name;
            } else {
                savedMissing = true;
            }
        } else {
            const defP = discovered.find(p => p.default) || discovered[0];
            if (defP) {
                selectedPrinter = defP.name;
            }
        }
    }

    if (discovered.length === 0) {
        const opt = document.createElement('option');
        opt.value = 'Browser Print';
        opt.textContent = '🖨️ In Qua Trình Duyệt (Mặc định)';
        opt.selected = true;
        sel.appendChild(opt);
        setSettingsPrinterMsg('Không phát hiện dịch vụ máy in cục bộ. Hệ thống sẽ tự động dùng In Qua Trình Duyệt.', '#2563eb');
    } else {
        if (savedMissing || !selectedPrinter) {
            const promptOpt = document.createElement('option');
            promptOpt.value = '';
            promptOpt.textContent = savedMissing
                ? `-- Máy in "${saved}" không còn kết nối, vui lòng chọn lại --`
                : '-- Chọn máy in trên máy tính hiện tại --';
            promptOpt.selected = true;
            sel.appendChild(promptOpt);
        }
        discovered.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.name;
            opt.textContent = '🖨️ ' + p.name + (p.default ? ' (Mặc định máy trạm)' : '');
            if (selectedPrinter && p.name === selectedPrinter) opt.selected = true;
            sel.appendChild(opt);
        });

        if (savedMissing) {
            setSettingsPrinterMsg(`⚠️ Máy in mặc định đã lưu ("${saved}") không còn tồn tại trên máy tính này. Vui lòng chọn lại máy in!`, '#b45309');
        } else if (showFeedback) {
            setSettingsPrinterMsg(`✓ Đã tìm thấy ${discovered.length} máy in trên máy tính hiện tại!`, '#15803d');
        } else {
            setSettingsPrinterMsg('');
        }
    }
}

refreshSettingsClientPrinters(false);

function updateSettingsLabelPreview(size) {
    const box = document.getElementById('settings_preview_box');
    const lbl = document.getElementById('settings_preview_size_label');
    if (!box) return;
    box.classList.remove('label-size-65x30', 'label-size-110x80');
    box.classList.add('label-size-' + size);
    if (lbl) lbl.innerText = (size === '110x80') ? '80 × 100 / 100 × 80 mm (Ngang)' : '65mm × 30mm (Ngang)';
}

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
        <select name="qr_field_types[]" class="form-select form-select-sm field-select flex-grow-1" onchange="onFieldTypeChange(this)">
            ${optionsHtml}
        </select>
        <input type="text" name="qr_field_customs[]" class="form-control form-control-sm field-custom-input" 
               placeholder="Nhập ký tự (vd: YD)..." style="display:none; max-width: 170px;" oninput="updatePreview()" title="Ký tự cố định chèn vào vị trí này">
        <button type="button" class="btn btn-sm btn-outline-danger border-0" onclick="removeFieldRow(this)" title="Xóa vị trí này">
            ❌
        </button>
    `;
    container.appendChild(row);
    renumberFields();
    updatePreview();
}

function onFieldTypeChange(selectEl) {
    const row = selectEl.closest('.field-item-row');
    if (!row) return;
    const customInput = row.querySelector('.field-custom-input');
    if (customInput) {
        if (selectEl.value === 'custom_fixed') {
            customInput.style.display = 'block';
            customInput.focus();
        } else {
            customInput.style.display = 'none';
        }
    }
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
        'date_code_ym': '<?= getFactoryYearMonthCode() ?>',
        'date_code_y': '<?= getFactoryYearCode() ?>',
        'date_code_m': '<?= getFactoryMonthCode() ?>',
        'empty': ''
    };

    const rows = document.querySelectorAll('.field-item-row');
    const parts = [];
    rows.forEach(row => {
        const sel = row.querySelector('.field-select');
        const customInput = row.querySelector('.field-custom-input');
        if (!sel) return;
        const val = sel.value;
        if (val === 'custom_fixed') {
            parts.push(customInput ? customInput.value : '');
        } else {
            parts.push(sampleValues[val] !== undefined ? sampleValues[val] : '');
        }
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

