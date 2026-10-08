<?php
/**
 * TRANG NHẬP LIỆU IN TEM SẢN XUẤT (PRINT ENTRY)
 * Dành cho nhân viên Editor & Admin
 * Chuẩn UI/UX xưởng sản xuất, tự động tính thùng, kiểm tra vượt mức, hộp thoại xác nhận
 */
$pageTitle = "Nhập Liệu In Tem";
require_once __DIR__ . '/includes/header.php';
requireRole(['admin', 'editor']);

$prefillSlip = trim($_GET['slip'] ?? '');
?>

<div class="page-header">
    <div>
        <h1 class="page-title">🏷️ Nhập Liệu In Tem Thùng</h1>
        <p style="color:var(--text-muted);font-size:14px;margin-top:2px;">
            Nhập hoặc quét mã chỉ thị &bull; Tự động tính số thùng &bull; Tự động sinh mã QR &bull; In nhãn 65x30mm
        </p>
    </div>
    <div class="page-actions">
        <a href="orders.php" class="btn btn-secondary">📋 Danh sách chỉ thị</a>
        <a href="history.php" class="btn btn-secondary">📜 Lịch sử in</a>
    </div>
</div>

<div class="grid-2">
    <!-- CỘT BÊN TRÁI: FORM NHẬP LIỆU -->
    <div class="card">
        <div class="card-header">
            <span class="card-title">📝 Thông Tin Nhập Liệu In Tem</span>
            <span class="badge badge-info">Bước 1 &amp; Bước 2</span>
        </div>
        <div class="card-body">
            
            <!-- BƯỚC 1: TRA CỨU CHỈ THỊ -->
            <div class="form-group">
                <label class="form-label required">Mã Phiếu Chỉ Thị hoặc Mã Chỉ Thị (Lot No)</label>
                <div class="input-scanner-wrapper">
                    <input type="text" id="input_query" class="form-control form-control-lg" 
                           placeholder="Quét mã vạch hoặc nhập mã (vd: PL-2610-01, LOT-A101)..." 
                           value="<?= htmlspecialchars($prefillSlip) ?>" autofocus autocomplete="off">
                    <button type="button" id="btn_search" class="btn btn-primary" style="margin-left:8px;height:52px;padding:0 20px;">
                        🔍 Tìm
                    </button>
                </div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">
                    💡 Máy quét Barcode sẽ tự động Enter để tìm kiếm dữ liệu.
                </div>
            </div>

            <!-- THÔNG TIN CHỈ THỊ TỰ ĐIỀN -->
            <div id="order_info_card" style="display:none;background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;padding:16px;margin-bottom:20px;">
                <div style="font-weight:700;color:#1e293b;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;">
                    <span>📌 Thông Tin Chỉ Thị Đã Tìm Thấy</span>
                    <span id="badge_order_status" class="badge badge-success">ACTIVE</span>
                </div>
                
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:14px;">
                    <div><strong>Mã phiếu:</strong> <span id="disp_slip_code" style="color:#2563eb;font-weight:700;">-</span></div>
                    <div><strong>Mã chỉ thị (Lot):</strong> <span id="disp_order_code" style="font-weight:700;">-</span></div>
                    <div><strong>Mã sản phẩm:</strong> <span id="disp_product_code" style="font-weight:700;color:#0f172a;">-</span></div>
                    <div><strong>Quy cách:</strong> <span id="disp_pack_qty" style="color:#b45309;font-weight:700;">-</span> con/thùng</div>
                    <div><strong>Nhà cung cấp:</strong> <span id="disp_supplier">-</span></div>
                    <div><strong>Trọng lượng:</strong> <span id="disp_weight">-</span> Kg/thùng</div>
                </div>

                <!-- KPI CARD THỐNG KÊ SỐ LƯỢNG -->
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-top:14px;">
                    <div style="background:#ffffff;border:1px solid #e2e8f0;padding:10px;border-radius:6px;text-align:center;">
                        <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">Chỉ Thị</div>
                        <div id="disp_target_qty" style="font-size:20px;font-weight:800;color:#1e293b;">0</div>
                    </div>
                    <div style="background:#ffffff;border:1px solid #e2e8f0;padding:10px;border-radius:6px;text-align:center;">
                        <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">Đã In</div>
                        <div id="disp_printed_qty" style="font-size:20px;font-weight:800;color:#2563eb;">0</div>
                    </div>
                    <div style="background:#ffffff;border:1px solid #e2e8f0;padding:10px;border-radius:6px;text-align:center;">
                        <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">Còn Lại</div>
                        <div id="disp_remaining_qty" style="font-size:20px;font-weight:800;color:#15803d;">0</div>
                    </div>
                </div>
            </div>

            <!-- CẢNH BÁO TẠM DỪNG IN TEM -->
            <div id="alert_paused_order" class="alert alert-warning" style="display:none;background:#fef3c7;border-color:#fde68a;color:#92400e;">
                <div style="width:100%;">
                    <div style="display:flex;align-items:center;gap:8px;font-weight:800;font-size:15px;margin-bottom:4px;">
                        <span>⛔ CHỈ THỊ ĐANG BỊ TẠM DỪNG IN TEM</span>
                    </div>
                    <div>Chỉ thị sản xuất này đang ở trạng thái <strong>TẠM DỪNG</strong> theo yêu cầu của Quản lý / Kỹ thuật xưởng. Thao tác in tem tạm thời bị khóa. Vui lòng liên hệ Quản lý để kích hoạt lại.</div>
                </div>
            </div>

            <!-- BƯỚC 2: NHẬP SỐ LƯỢNG TEM SP ĐÃ IN -->
            <div id="print_section" style="display:none;">
                <div class="form-group">
                    <label class="form-label required">Số Lượng Tem Sản Phẩm Đã In Lượt Này (con / pcs)</label>
                    <input type="number" id="input_print_qty" class="form-control form-control-lg" 
                           placeholder="Nhập số tem sản phẩm (vd: 20, 25, 50)..." min="1" step="1">
                    <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">
                        Hệ thống sẽ tự động tính số tem thùng = Số lượng in &divide; Quy cách đóng gói.
                    </div>
                </div>

                <!-- TÙY CHỌN IN LẺ KHI CÓ THÙNG LẺ -->
                <div id="odd_box_option" style="display:none;margin-bottom:18px;">
                    <label class="checkbox-group">
                        <input type="checkbox" id="chk_is_odd_box">
                        <span>
                            <strong>Cho phép in thùng lẻ</strong> (Số lượng không đủ quy cách đóng gói 1 thùng chẵn)
                        </span>
                    </label>
                </div>

                <!-- CẢNH BÁO VƯỢT ĐỊNH MỨC -->
                <div id="alert_over_target" class="alert alert-danger" style="display:none;">
                    <div>
                        <strong>⛔ CẢNH BÁO VƯỢT ĐỊNH MỨC:</strong><br>
                        Số lượng in (<span id="txt_excess_print">0</span>) vượt quá số lượng còn lại của chỉ thị (<span id="txt_excess_remain">0</span>)!<br>
                        <span style="font-size:12px;color:#7f1d1d;">Bắt buộc phải có Quản lý/Admin nhập Mã số nhân viên (MSNV) để duyệt vượt mức trước khi in.</span>
                    </div>
                </div>

                <!-- GHI CHÚ BỔ SUNG -->
                <div class="form-group">
                    <label class="form-label">Ghi Chú Đợt In (Tùy chọn)</label>
                    <input type="text" id="input_note" class="form-control" placeholder="Ghi chú thêm về ca sản xuất, máy in hoặc ghi chú đóng gói...">
                </div>

                <!-- NÚT THỰC HIỆN NHẬP & IN -->
                <button type="button" id="btn_submit_step" class="btn btn-success btn-lg" style="width:100%;">
                    📥 XÁC NHẬN NHẬP &amp; IN TEM THÙNG
                </button>
            </div>

        </div>
    </div>

    <!-- CỘT BÊN PHẢI: TÍNH TOÁN & XEM TRƯỚC TEM THÙNG -->
    <div class="card">
        <div class="card-header">
            <span class="card-title">🧮 Kết Quả Tính Tem Thùng Tự Động</span>
            <span id="badge_calc_status" class="badge badge-neutral">Chờ nhập liệu</span>
        </div>
        <div class="card-body">
            
            <div id="calc_placeholder" style="text-align:center;padding:40px 20px;color:var(--text-muted);">
                <div style="font-size:48px;margin-bottom:12px;">📦</div>
                <p>Vui lòng tra cứu chỉ thị và nhập số lượng tem sản phẩm để hệ thống tự động tính số tem thùng.</p>
            </div>

            <div id="calc_result_panel" style="display:none;">
                <div class="calc-preview-box">
                    <div class="calc-row">
                        <span>Quy cách đóng gói:</span>
                        <strong id="calc_pack_qty">10 con/thùng</strong>
                    </div>
                    <div class="calc-row">
                        <span>Số lượng tem SP cần đóng:</span>
                        <strong id="calc_print_qty" style="color:#2563eb;">0 con</strong>
                    </div>
                    <div class="calc-row">
                        <span>Số thùng chẵn (đủ quy cách):</span>
                        <span id="calc_full_boxes">0 thùng</span>
                    </div>
                    <div class="calc-row" id="row_calc_odd" style="display:none;color:#b45309;">
                        <span>Số lượng thùng lẻ:</span>
                        <strong id="calc_odd_qty">0 con</strong>
                    </div>
                    <div class="calc-row">
                        <span>TỔNG SỐ TEM THÙNG CẦN IN:</span>
                        <span id="calc_total_boxes" style="font-size:22px;color:#15803d;">0 TEM</span>
                    </div>
                </div>

                <!-- QUY TẮC MÃ THÙNG -->
                <div style="margin-top:16px;background:#f8fafc;padding:12px 14px;border-radius:6px;border:1px solid #e2e8f0;font-size:13px;">
                    <div><strong>Quy tắc mã thùng:</strong> <code style="color:#2563eb;font-weight:700;">TU-<?= date('ymd') ?>-XXX</code></div>
                    <div style="color:var(--text-muted);margin-top:2px;">
                        Số thứ tự XXX tăng dần trong ngày hôm nay và tự động reset về 001 khi sang ngày mới.
                    </div>
                </div>

                <!-- XEM TRƯỚC MẪU TEM 65x30MM -->
                <div style="margin-top:20px;">
                    <div style="font-weight:700;margin-bottom:8px;font-size:14px;color:#334155;">
                        👁️ Mẫu nhãn in tem thùng (Kích thước 65mm &times; 30mm):
                    </div>
                    <div style="display:flex;justify-content:center;background:#e2e8f0;padding:12px;border-radius:8px;">
                        <div class="box-label-item" style="box-shadow:0 1px 3px rgba(0,0,0,0.2);">
                            <div class="label-qr-col">
                                <div style="width:23mm;height:23mm;border:1px dashed #94a3b8;display:flex;align-items:center;justify-content:center;font-size:9px;color:#64748b;text-align:center;">
                                    Mã QR<br>SMC4
                                </div>
                            </div>
                            <div class="label-text-col">
                                <div class="label-line-1" id="mock_prod_code">PRODUCT-CODE</div>
                                <div class="label-line-2"><span id="mock_qty">10</span> pcs | <span id="mock_weight">0.00</span> Kg</div>
                                <div class="label-line-3">||</div>
                                <div class="label-line-4"><span id="mock_lot">LOT-NO</span> | <span id="mock_supp"></span></div>
                                <div class="label-line-5">TU-<?= date('ymd') ?>-001</div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

        </div>
    </div>
</div>

<!-- =======================================================
     MODAL 1: XÁC NHẬN TRƯỚC KHI THAO TÁC (FACTORY CONFIRMATION)
     ======================================================= -->
<div id="modal_confirm" class="modal-overlay">
    <div class="modal-dialog">
        <div class="modal-header">
            <span class="modal-title">⚠️ Xác Nhận In Tem Thùng</span>
            <button type="button" class="modal-close" onclick="closeModal('modal_confirm')">&times;</button>
        </div>
        <div class="modal-body">
            <p style="margin-bottom:16px;">Vui lòng kiểm tra lại các thông số kỹ thuật trước khi ghi dữ liệu và gửi lệnh in:</p>
            
            <table class="table table-striped" style="margin-bottom:16px;">
                <tr>
                    <td style="width:40%;font-weight:600;">Mã phiếu chỉ thị:</td>
                    <td id="cf_slip_code" style="font-weight:700;color:#2563eb;">-</td>
                </tr>
                <tr>
                    <td style="font-weight:600;">Mã chỉ thị (Lot No):</td>
                    <td id="cf_order_code" style="font-weight:700;">-</td>
                </tr>
                <tr>
                    <td style="font-weight:600;">Mã sản phẩm:</td>
                    <td id="cf_product_code" style="font-weight:700;">-</td>
                </tr>
                <tr>
                    <td style="font-weight:600;">Số lượng tem SP:</td>
                    <td id="cf_print_qty" style="font-weight:800;color:#1e293b;">-</td>
                </tr>
                <tr>
                    <td style="font-weight:600;">Quy cách đóng gói:</td>
                    <td id="cf_pack_qty">-</td>
                </tr>
                <tr>
                    <td style="font-weight:600;">Số tem thùng sinh ra:</td>
                    <td id="cf_box_count" style="font-weight:800;color:#15803d;font-size:16px;">-</td>
                </tr>
                <tr id="cf_row_odd" style="display:none;background:#fef3c7;">
                    <td style="font-weight:600;color:#b45309;">Trạng thái thùng lẻ:</td>
                    <td id="cf_odd_desc" style="font-weight:700;color:#b45309;">-</td>
                </tr>
                <tr id="cf_row_over" style="display:none;background:#fee2e2;">
                    <td style="font-weight:600;color:#991b1b;">Duyệt vượt mức:</td>
                    <td id="cf_over_desc" style="font-weight:700;color:#991b1b;">-</td>
                </tr>
            </table>

            <div style="font-size:13px;color:#64748b;">
                Hệ thống sẽ tự động cập nhật số lượng đã in vào CSDL và sinh mã tem thùng theo thứ tự.
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('modal_confirm')">Quay lại kiểm tra</button>
            <button type="button" id="btn_final_submit" class="btn btn-success btn-lg">
                ✅ ĐỒNG Ý NHẬP &amp; IN NGAY
            </button>
        </div>
    </div>
</div>

<!-- =======================================================
     MODAL 2: DUYỆT VƯỢT ĐỊNH MỨC DÀNH CHO ADMIN (MSNV APPROVAL)
     ======================================================= -->
<div id="modal_admin_approval" class="modal-overlay">
    <div class="modal-dialog">
        <div class="modal-header" style="background:#fee2e2;">
            <span class="modal-title" style="color:#991b1b;">
                🛡️ Yêu Cầu Duyệt Vượt Định Mức
            </span>
            <button type="button" class="modal-close" onclick="closeModal('modal_admin_approval')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="alert alert-warning" style="margin-bottom:16px;">
                Số lượng yêu cầu in (<strong id="modal_req_qty">0</strong> con) lớn hơn số lượng còn lại của chỉ thị (<strong id="modal_rem_qty">0</strong> con).
                Vượt mức: <strong id="modal_diff_qty" style="color:#b91c1c;">+0 con</strong>.
            </div>

            <p style="margin-bottom:12px;font-weight:600;color:#334155;">
                Vui lòng nhờ Quản lý / Admin / Editor nhập Mã số nhân viên (MSNV) và Mật khẩu để xác nhận cho phép vượt định mức:
            </p>

            <div class="form-group">
                <label class="form-label required">Mã số nhân viên người duyệt (MSNV)</label>
                <input type="text" id="input_admin_msnv" class="form-control form-control-lg" 
                       placeholder="Nhập MSNV Admin hoặc Editor (vd: ADM-001, NV-001)..." autocomplete="off">
            </div>

            <div class="form-group">
                <label class="form-label required">Mật khẩu xác thực (Password / PIN)</label>
                <input type="password" id="input_admin_pass" class="form-control form-control-lg" 
                       placeholder="Nhập mật khẩu tài khoản người duyệt..." autocomplete="off">
                <div id="admin_verify_feedback" style="font-size:13px;margin-top:6px;font-weight:600;"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('modal_admin_approval')">Hủy bỏ</button>
            <button type="button" id="btn_verify_and_proceed" class="btn btn-danger">
                🔑 Xác nhận duyệt vượt mức
            </button>
        </div>
    </div>
</div>

<!-- =======================================================
     MODAL 3: XEM VÀ IN TEM THÙNG RA MÁY IN MẠNG / MÁY IN NHIỆT
     ======================================================= -->
<div id="modal_print_result" class="modal-overlay">
    <div class="modal-dialog" style="max-width:850px;">
        <div class="modal-header no-print">
            <span class="modal-title">🖨️ Tem Thùng Đã Sinh - Sẵn Sàng In</span>
            <button type="button" class="modal-close" onclick="closeModal('modal_print_result')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="alert alert-success no-print" style="margin-bottom:16px;">
                <span>✅ Đã lưu lịch sử và sinh thành công <strong id="res_box_count">0</strong> tem thùng!</span>
            </div>

            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;" class="no-print">
                <span style="font-size:13px;color:var(--text-muted);">
                    Khổ in chuẩn: <strong>65mm &times; 30mm</strong>. Tự động ngắt trang cho từng tem.
                </span>
                <button type="button" class="btn btn-primary btn-lg" onclick="triggerDirectPrint()">
                    🖨️ GỬI LỆNH IN RA MÁY IN
                </button>
            </div>

            <!-- DANH SÁCH TEM THỰC TẾ SINH RA -->
            <div id="printable_labels_container" class="label-preview-wrapper">
                <!-- Javascript sẽ điền các tem vào đây -->
            </div>
        </div>
        <div class="modal-footer no-print">
            <button type="button" class="btn btn-secondary" onclick="resetFormForNext()">🔄 Nhập đơn tiếp theo</button>
            <button type="button" class="btn btn-primary" onclick="triggerDirectPrint()">🖨️ In Lại</button>
        </div>
    </div>
</div>

<script>
// BIẾN TOÀN CỤC LƯU TRẠNG THÁI HIỆN TẠI
let currentOrder = null;
let currentCalculation = null;
let verifiedAdminMsnv = null;
let verifiedAdminPass = null;

// HÀM MỞ / ĐÓNG MODAL
function openModal(id) {
    document.getElementById(id).classList.add('active');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

// BƯỚC 1: TRA CỨU CHỈ THỊ THEO MÃ PHIẾU HOẶC MÃ CHỈ THỊ
function searchOrder() {
    const query = document.getElementById('input_query').value.trim();
    if (!query) {
        alert('Vui lòng nhập hoặc quét Mã phiếu chỉ thị / Mã chỉ thị!');
        document.getElementById('input_query').focus();
        return;
    }

    const btn = document.getElementById('btn_search');
    btn.disabled = true;
    btn.innerText = 'Đang tìm...';

    fetch('ajax/get_order.php?q=' + encodeURIComponent(query))
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            btn.innerText = '🔍 Tìm';

            if (!data.success) {
                alert(data.message);
                currentOrder = null;
                document.getElementById('order_info_card').style.display = 'none';
                document.getElementById('print_section').style.display = 'none';
                document.getElementById('calc_placeholder').style.display = 'block';
                document.getElementById('calc_result_panel').style.display = 'none';
                return;
            }

            // Gán dữ liệu chỉ thị
            currentOrder = data.order;
            verifiedAdminMsnv = null;

            // Hiển thị thông tin
            document.getElementById('disp_slip_code').innerText = currentOrder.slip_code;
            document.getElementById('disp_order_code').innerText = currentOrder.order_code;
            document.getElementById('disp_product_code').innerText = currentOrder.product_code;
            document.getElementById('disp_pack_qty').innerText = currentOrder.pack_qty;
            document.getElementById('disp_supplier').innerText = currentOrder.supplier || '(Trống)';
            document.getElementById('disp_weight').innerText = currentOrder.weight_per_box > 0 ? currentOrder.weight_per_box : '-';
            
            document.getElementById('disp_target_qty').innerText = currentOrder.target_qty.toLocaleString();
            document.getElementById('disp_printed_qty').innerText = currentOrder.printed_qty.toLocaleString();
            document.getElementById('disp_remaining_qty').innerText = currentOrder.remaining_qty.toLocaleString();

            const statusBadge = document.getElementById('badge_order_status');
            const pausedAlert = document.getElementById('alert_paused_order');
            const printQtyInput = document.getElementById('input_print_qty');
            const submitBtn = document.getElementById('btn_submit_step');
            const isPaused = (currentOrder.status === 'paused' || currentOrder.is_paused);

            if (isPaused) {
                statusBadge.className = 'badge badge-paused';
                statusBadge.innerText = '⏸️ TẠM DỪNG';
                pausedAlert.style.display = 'block';
                printQtyInput.disabled = true;
                submitBtn.disabled = true;
                submitBtn.innerText = '🚫 ĐÃ KHÓA IN (CHỈ THỊ TẠM DỪNG)';
            } else {
                pausedAlert.style.display = 'none';
                printQtyInput.disabled = false;
                submitBtn.disabled = false;
                submitBtn.innerText = '📥 XÁC NHẬN NHẬP & IN TEM THÙNG';

                if (currentOrder.status === 'completed') {
                    statusBadge.className = 'badge badge-neutral';
                    statusBadge.innerText = 'COMPLETED';
                } else if (currentOrder.status === 'in_progress') {
                    statusBadge.className = 'badge badge-warning';
                    statusBadge.innerText = 'IN PROGRESS';
                } else {
                    statusBadge.className = 'badge badge-success';
                    statusBadge.innerText = 'PENDING';
                }
            }

            // Hiển thị phần nhập số lượng
            document.getElementById('order_info_card').style.display = 'block';
            document.getElementById('print_section').style.display = 'block';
            printQtyInput.value = '';
            if (!isPaused) {
                printQtyInput.focus();
            }

            // Mẫu tem
            document.getElementById('mock_prod_code').innerText = currentOrder.product_code;
            document.getElementById('mock_lot').innerText = currentOrder.order_code;
            document.getElementById('mock_supp').innerText = currentOrder.supplier || '';
            document.getElementById('mock_qty').innerText = currentOrder.pack_qty;
            document.getElementById('mock_weight').innerText = currentOrder.weight_per_box || '0.00';

            recalculate();
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerText = '🔍 Tìm';
            alert('Lỗi kết nối máy chủ: ' + err.message);
        });
}

// BƯỚC 2: TỰ ĐỘNG TÍNH SỐ TEM THÙNG KHI GÕ SỐ LƯỢNG IN
function recalculate() {
    if (!currentOrder) return;

    const printQtyVal = parseInt(document.getElementById('input_print_qty').value, 10);
    const packQty = currentOrder.pack_qty || 1;

    // Reset xác thực duyệt vượt mức khi số lượng thay đổi
    verifiedAdminMsnv = null;
    verifiedAdminPass = null;

    if (isNaN(printQtyVal) || printQtyVal <= 0) {
        document.getElementById('calc_placeholder').style.display = 'block';
        document.getElementById('calc_result_panel').style.display = 'none';
        document.getElementById('odd_box_option').style.display = 'none';
        document.getElementById('chk_is_odd_box').checked = false;
        document.getElementById('alert_over_target').style.display = 'none';
        currentCalculation = null;
        return;
    }

    // Công thức tính chuẩn:
    // full_boxes = floor(printed_qty / package_spec)
    // remainder  = printed_qty % package_spec
    const fullBoxes = Math.floor(printQtyVal / packQty);
    const remainder = printQtyVal % packQty;
    const hasOdd = (remainder > 0);
    const totalBoxes = fullBoxes + (hasOdd ? 1 : 0);

    currentCalculation = {
        printQty: printQtyVal,
        packQty: packQty,
        fullBoxes: fullBoxes,
        remainder: remainder,
        hasOdd: hasOdd,
        totalBoxes: totalBoxes
    };

    // Hiển thị kết quả tính
    document.getElementById('calc_placeholder').style.display = 'none';
    document.getElementById('calc_result_panel').style.display = 'block';

    document.getElementById('calc_pack_qty').innerText = packQty + ' con/thùng';
    document.getElementById('calc_print_qty').innerText = printQtyVal.toLocaleString() + ' con';
    document.getElementById('calc_full_boxes').innerText = fullBoxes + ' thùng (' + (fullBoxes * packQty) + ' con)';
    
    // Condition 1: remainder == 0 -> KHÔNG hiển thị cảnh báo thùng lẻ, KHÔNG yêu cầu in lẻ
    if (!hasOdd) {
        document.getElementById('row_calc_odd').style.display = 'none';
        document.getElementById('odd_box_option').style.display = 'none';
        document.getElementById('chk_is_odd_box').checked = false;
    } else {
        // Condition 2: remainder > 0 -> Hiển thị cảnh báo và bắt buộc tích chọn in lẻ (Unchecked by default)
        document.getElementById('row_calc_odd').style.display = 'flex';
        document.getElementById('calc_odd_qty').innerText = '1 thùng lẻ (' + remainder + ' con)';
        document.getElementById('odd_box_option').style.display = 'block';
    }

    document.getElementById('calc_total_boxes').innerText = totalBoxes + ' TEM THÙNG';
    document.getElementById('mock_qty').innerText = packQty;

    // Kiểm tra vượt định mức
    const remaining = currentOrder.remaining_qty;
    if (printQtyVal > remaining) {
        document.getElementById('alert_over_target').style.display = 'flex';
        document.getElementById('txt_excess_print').innerText = printQtyVal.toLocaleString();
        document.getElementById('txt_excess_remain').innerText = remaining.toLocaleString();
    } else {
        document.getElementById('alert_over_target').style.display = 'none';
    }
}

// BƯỚC 3: XỬ LÝ NÚT BẤM "NHẬP" -> KIỂM TRA ĐỊNH MỨC & MỞ HỘP THOẠI XÁC NHẬN
document.getElementById('btn_submit_step').addEventListener('click', function() {
    if (!currentOrder || !currentCalculation) {
        alert('Vui lòng tra cứu chỉ thị và nhập số lượng tem sản phẩm hợp lệ!');
        return;
    }

    const printQty = currentCalculation.printQty;
    const remaining = currentOrder.remaining_qty;

    // Nếu có thùng lẻ và người dùng không tích chọn in lẻ
    if (currentCalculation.hasOdd && !document.getElementById('chk_is_odd_box').checked) {
        alert('Cảnh báo: Số lượng in (' + printQty + ') dư ' + currentCalculation.remainder + ' con lẻ không đủ 1 thùng chẵn. Vui lòng tích chọn ô "Cho phép in thùng lẻ" để tiếp tục!');
        document.getElementById('chk_is_odd_box').focus();
        return;
    }

    // Nếu in vượt số còn lại và chưa có admin/editor duyệt bằng MSNV + Mật khẩu
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

    // Hiển thị hộp thoại xác nhận trước khi thao tác
    document.getElementById('cf_slip_code').innerText = currentOrder.slip_code;
    document.getElementById('cf_order_code').innerText = currentOrder.order_code;
    document.getElementById('cf_product_code').innerText = currentOrder.product_code;
    document.getElementById('cf_print_qty').innerText = printQty.toLocaleString() + ' con';
    document.getElementById('cf_pack_qty').innerText = currentOrder.pack_qty + ' con/thùng';
    document.getElementById('cf_box_count').innerText = currentCalculation.totalBoxes + ' TEM';

    if (currentCalculation.hasOdd) {
        document.getElementById('cf_row_odd').style.display = 'table-row';
        document.getElementById('cf_odd_desc').innerText = 
            currentCalculation.fullBoxes + ' thùng chẵn (' + currentOrder.pack_qty + ' con) + 1 thùng lẻ (' + currentCalculation.remainder + ' con)';
    } else {
        document.getElementById('cf_row_odd').style.display = 'none';
    }

    if (printQty > remaining) {
        document.getElementById('cf_row_over').style.display = 'table-row';
        document.getElementById('cf_over_desc').innerText = 'Đã ủy quyền duyệt bởi: ' + verifiedAdminMsnv;
    } else {
        document.getElementById('cf_row_over').style.display = 'none';
    }

    openModal('modal_confirm');
});

// XÁC THỰC MSNV & MẬT KHẨU ADMIN/EDITOR DUYỆT VƯỢT MỨC
document.getElementById('btn_verify_and_proceed').addEventListener('click', function() {
    const msnv = document.getElementById('input_admin_msnv').value.trim();
    const pass = document.getElementById('input_admin_pass').value.trim();

    if (!msnv) {
        alert('Vui lòng nhập Mã số nhân viên Admin/Editor!');
        document.getElementById('input_admin_msnv').focus();
        return;
    }

    if (!pass) {
        alert('Vui lòng nhập Mật khẩu/PIN xác thực tài khoản!');
        document.getElementById('input_admin_pass').focus();
        return;
    }

    const fb = document.getElementById('admin_verify_feedback');
    fb.style.color = '#2563eb';
    fb.innerText = 'Đang kiểm tra quyền duyệt...';

    const formData = new FormData();
    formData.append('msnv', msnv);
    formData.append('password', pass);

    fetch('ajax/verify_admin.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            fb.style.color = '#15803d';
            fb.innerText = '✓ Xác thực thành công: ' + data.admin.full_name + ' (' + data.admin.employee_code + ' - ' + data.admin.role.toUpperCase() + ')';
            verifiedAdminMsnv = data.admin.employee_code;
            verifiedAdminPass = pass;
            
            setTimeout(() => {
                closeModal('modal_admin_approval');
                // Tự động mở hộp thoại xác nhận
                document.getElementById('btn_submit_step').click();
            }, 400);
        } else {
            fb.style.color = '#b91c1c';
            fb.innerText = '✗ ' + data.message;
            verifiedAdminMsnv = null;
            verifiedAdminPass = null;
        }
    })
    .catch(err => {
        fb.style.color = '#b91c1c';
        fb.innerText = 'Lỗi kết nối: ' + err.message;
        verifiedAdminMsnv = null;
        verifiedAdminPass = null;
    });
});

// BƯỚC 4: GỬI AJAX LƯU LỊCH SỬ & SINH TEM THÙNG CHÍNH THỨC
document.getElementById('btn_final_submit').addEventListener('click', function() {
    const btn = document.getElementById('btn_final_submit');
    btn.disabled = true;
    btn.innerText = '⏳ Đang lưu & sinh mã QR...';

    const formData = new FormData();
    formData.append('order_id', currentOrder.id);
    formData.append('print_qty', currentCalculation.printQty);
    formData.append('is_odd_box', (currentCalculation.hasOdd && document.getElementById('chk_is_odd_box').checked) ? 1 : 0);
    formData.append('admin_msnv', verifiedAdminMsnv || '');
    formData.append('admin_pass', verifiedAdminPass || '');
    formData.append('note', document.getElementById('input_note').value.trim());

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
                closeModal('modal_confirm');
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
                closeModal('modal_confirm');
                alert(data.message);
                document.getElementById('chk_is_odd_box').focus();
            } else {
                alert('Lỗi: ' + data.message);
            }
            return;
        }

        // Đóng hộp thoại xác nhận
        closeModal('modal_confirm');

        // Hiển thị danh sách tem đã sinh
        renderPrintedLabels(data.labels);
        document.getElementById('res_box_count').innerText = data.labels.length;

        // Mở modal in tem
        openModal('modal_print_result');
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerText = '✅ ĐỒNG Ý NHẬP & IN NGAY';
        alert('Lỗi gửi dữ liệu: ' + err.message);
    });
});

// RENDER DANH SÁCH TEM THÙNG ĐỂ IN (CHUẨN 65x30MM)
function renderPrintedLabels(labels) {
    const container = document.getElementById('printable_labels_container');
    container.innerHTML = '';

    labels.forEach((item, index) => {
        const div = document.createElement('div');
        div.className = 'box-label-item';
        div.innerHTML = `
            <div class="label-qr-col">
                <img src="${item.qr_base64}" alt="QR">
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

// IN TRỰC TIẾP RA MÁY IN MẠNG / IN TRÌNH DUYỆT
function triggerDirectPrint() {
    window.print();
}

// TIẾP TỤC ĐƠN KHÁC
function resetFormForNext() {
    closeModal('modal_print_result');
    document.getElementById('input_query').value = '';
    document.getElementById('input_query').focus();
    document.getElementById('order_info_card').style.display = 'none';
    document.getElementById('print_section').style.display = 'none';
    document.getElementById('calc_placeholder').style.display = 'block';
    document.getElementById('calc_result_panel').style.display = 'none';
    currentOrder = null;
    currentCalculation = null;
    verifiedAdminMsnv = null;
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>"']/g, function(m) {
        return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[m];
    });
}

// SỰ KIỆN PHÍM TỰ ĐỘNG
document.getElementById('input_query').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        searchOrder();
    }
});

document.getElementById('btn_search').addEventListener('click', searchOrder);

document.getElementById('input_print_qty').addEventListener('input', recalculate);
document.getElementById('input_print_qty').addEventListener('change', recalculate);

// NẾU CÓ THAM SỐ URL THÌ TỰ TRA CỨU
window.addEventListener('DOMContentLoaded', () => {
    const q = document.getElementById('input_query').value.trim();
    if (q) {
        searchOrder();
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

