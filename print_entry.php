<?php
/**
 * TRANG NHẬP LIỆU IN TEM SẢN XUẤT (PRINT ENTRY)
 * Dành cho nhân viên Editor & Admin
 * Chuẩn UI/UX xưởng sản xuất, tự động tính thùng, kiểm tra vượt mức, hộp thoại xác nhận
 */
$pageTitle = "Nhập Liệu In Tem";
require_once __DIR__ . '/includes/header.php';
requireRole(['admin', 'editor']);

$pdo = getDbConnection();
$connectedPrinters = [];
$defaultPrinter    = getDefaultPrinter($pdo);
$defaultLabelSize  = getDefaultLabelSize($pdo);
$currentUserId     = (int)($currentUser['id'] ?? 0);
$prefillSlip = trim($_GET['slip'] ?? '');
?>

<div class="page-header">
    <div>
        <h1 class="page-title">🏷️ Nhập Liệu In Tem Thùng</h1>
        <p style="color:var(--text-muted);font-size:14px;margin-top:2px;">
            Nhập hoặc quét mã chỉ thị &bull; Tự động tính số thùng &bull; Tự động sinh mã QR &bull; Tự động in nhãn 65&times;30mm / 110&times;80mm (Ngang)
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
                        <span style="font-size:12px;color:#7f1d1d;">Bắt buộc phải có Quản lý/Admin nhập Mã số nhân viên (MSNV), Mật khẩu và Lý do in vượt để duyệt trước khi in.</span>
                    </div>
                </div>

                <!-- MÁY IN TEM SỬ DỤNG MẶC ĐỊNH -->
                <div class="form-group">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                        <label class="form-label fw-bold" style="margin-bottom:0;">🖨️ Máy In Tem Sử Dụng (Mặc định)</label>
                        <div class="d-flex flex-wrap gap-1 align-items-center">
                            <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none" onclick="promptAddEntryCustomPrinter()" title="Khai báo thêm máy in đã cài đặt trên máy tính này">
                                ➕ Thêm
                            </button>
                            <span class="text-muted" style="font-size:11px;">|</span>
                            <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none text-danger" onclick="removeEntrySavedPrinter()" title="Xóa máy in đang chọn khỏi cấu hình">
                                🗑️ Xóa
                            </button>
                            <span class="text-muted" style="font-size:11px;">|</span>
                            <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none text-warning-emphasis" onclick="resetEntryPrinterConfig()" title="Đặt lại toàn bộ cấu hình máy in trên máy tính này">
                                ⚙️ Đặt lại
                            </button>
                            <span class="text-muted" style="font-size:11px;">|</span>
                            <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none" onclick="refreshEntryClientPrinters(true, true)" title="Quét lại máy in thực tế từ máy tính hiện tại">
                                🔄 Quét lại
                            </button>
                        </div>
                    </div>
                    <select id="input_printer_select" class="form-select border-primary fw-bold" onchange="onEntryPrinterChange(this.value)">
                        <option value="">⏳ Đang tải danh sách máy in trên máy tính hiện tại...</option>
                    </select>
                    <div id="entry_printer_status_msg" style="display:none;font-size:12.5px;font-weight:700;margin-top:4px;"></div>
                    <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">
                        Chỉ hiển thị các máy in kết nối thực tế với máy tính hiện tại (Client PC). Tự động lưu làm mặc định theo người dùng và trình duyệt.
                    </div>
                </div>

                <!-- KÍCH THƯỚC TEM IN & CHẾ ĐỘ IN -->
                <div class="form-group">
                    <label class="form-label fw-bold">📐 Kích Thước Tem In</label>
                    <select id="input_label_size_select" class="form-select border-primary fw-bold" onchange="onEntryLabelSizeChange(this.value)">
                        <option value="65x30" <?= ($defaultLabelSize === '65x30') ? 'selected' : '' ?>>Tem nhỏ (65 × 30 mm - Ngang) - Mặc định xưởng</option>
                        <option value="110x80" <?= ($defaultLabelSize === '110x80') ? 'selected' : '' ?>>Tem lớn (80 × 100 / 100 × 80 / 110 × 80 mm - Ngang)</option>
                    </select>
                </div>

                <div class="form-group" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 14px;">
                    <label class="form-label fw-bold mb-1">⚡ Chế Độ Xuất Lệnh In</label>
                    <div style="display:flex;gap:20px;align-items:center;">
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:700;color:#15803d;">
                            <input type="radio" name="entry_print_mode" value="direct" checked>
                            🖨️ In trực tiếp (Tự động gửi lệnh in)
                        </label>
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:600;color:#475569;">
                            <input type="radio" name="entry_print_mode" value="preview">
                            👁️ Xem trước (Preview)
                        </label>
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

                <!-- XEM TRƯỚC MẪU TEM -->
                <div style="margin-top:20px;">
                    <div style="font-weight:700;margin-bottom:8px;font-size:14px;color:#334155;">
                        👁️ Mẫu nhãn in tem thùng (<span id="entry_mock_size_txt"><?= ($defaultLabelSize === '110x80') ? '110mm × 80mm - Ngang' : '65mm × 30mm - Ngang' ?></span>):
                    </div>
                    <div style="display:flex;justify-content:center;background:#e2e8f0;padding:12px;border-radius:8px;overflow-x:auto;">
                        <div id="entry_mock_label_box" class="box-label-item label-size-<?= $defaultLabelSize ?>" style="box-shadow:0 1px 3px rgba(0,0,0,0.2);">
                            <div class="label-qr-col">
                                <div style="width:100%;height:100%;border:1px dashed #94a3b8;display:flex;align-items:center;justify-content:center;font-size:9px;color:#64748b;text-align:center;">
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
                <tr>
                    <td style="font-weight:600;">Máy in mặc định:</td>
                    <td id="cf_printer_name" style="font-weight:700;color:#2563eb;">-</td>
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
                Hệ thống sẽ tự động cập nhật số lượng đã in vào CSDL, sinh mã tem thùng và gửi lệnh in trực tiếp đến máy in mặc định.
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
     MODAL 2: DUYỆT VƯỢT ĐỊNH MỨC DÀNH CHO ADMIN (MSNV, PASSWORD & LÝ DO)
     ======================================================= -->
<div id="modal_admin_approval" class="modal-overlay">
    <div class="modal-dialog">
        <div class="modal-header" style="background:#fee2e2;">
            <span class="modal-title" style="color:#991b1b;">
                🛡️ Yêu Cầu Ủy Quyền Duyệt Vượt Định Mức
            </span>
            <button type="button" class="modal-close" onclick="closeModal('modal_admin_approval')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="alert alert-warning" style="margin-bottom:16px;">
                Số lượng yêu cầu in (<strong id="modal_req_qty">0</strong> con) lớn hơn số lượng còn lại của chỉ thị (<strong id="modal_rem_qty">0</strong> con).
                Vượt mức: <strong id="modal_diff_qty" style="color:#b91c1c;">+0 con</strong>.
            </div>

            <p style="margin-bottom:12px;font-weight:600;color:#334155;">
                Vui lòng nhờ Quản lý / Admin / Editor nhập Mã số nhân viên (MSNV), Mật khẩu và Lý do in vượt để xác nhận ủy quyền:
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
            </div>

            <div class="form-group">
                <label class="form-label required">Lý do in vượt định mức</label>
                <textarea id="input_override_reason" class="form-control" rows="2"
                          placeholder="Nhập rõ lý do in vượt định mức (bắt buộc, vd: In bù hàng NG, thay thế tem hỏng)..."></textarea>
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
            <button type="button" class="modal-close" onclick="closeModal('modal_print_result')" title="Thoát">&times;</button>
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
            <button type="button" class="btn btn-primary" onclick="triggerDirectPrint()">🖨️ Gửi Lệnh In</button>
        </div>
    </div>
</div>

<script>
// BIẾN TOÀN CỤC LƯU TRẠNG THÁI HIỆN TẠI
let currentOrder = null;
let currentCalculation = null;
let verifiedAdminMsnv = null;
let verifiedAdminPass = null;
let verifiedOverrideReason = null;

// HÀM MỞ / ĐÓNG MODAL
function openModal(id) {
    document.getElementById(id).classList.add('active');
}
function closeModal(id, forceClose = false) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.remove('active');
}

let activeEntryLabelSize = '<?= $defaultLabelSize ?>';

function normalizeEntrySize(size) {
    const s = String(size || '').trim().toLowerCase();
    if (s === '110x80' || s === '80x110' || s === '100x80' || s === '80x100') {
        return '110x80';
    }
    return '65x30';
}

function detectEntrySizeFromPrinter(printerName) {
    const name = String(printerName || '').toLowerCase();
    if (/(80\s*x\s*110|110\s*x\s*80|80\s*x\s*100|100\s*x\s*80)/.test(name)) {
        return '110x80';
    }
    if (/65\s*x\s*30/.test(name)) {
        return '65x30';
    }
    return null;
}

function getEntryLabelSize() {
    const sel = document.getElementById('input_label_size_select');
    if (sel && sel.value) {
        return normalizeEntrySize(sel.value);
    }
    return normalizeEntrySize(activeEntryLabelSize);
}

function getEntryPrintMode() {
    const r = document.querySelector('input[name="entry_print_mode"]:checked');
    return (r && r.value === 'preview') ? 'preview' : 'direct';
}

function applyEntryPrintPageStyle(size) {
    size = normalizeEntrySize(size);
    let styleEl = document.getElementById('dynamic_print_page_style');
    if (!styleEl) {
        styleEl = document.createElement('style');
        styleEl.id = 'dynamic_print_page_style';
        document.head.appendChild(styleEl);
    }
    if (size === '110x80') {
        styleEl.textContent = `
            @page { size: 104mm 80mm !important; margin: 0mm !important; }
            @media print {
                html, body { width: 104mm !important; height: auto !important; min-height: 0 !important; display: block !important; margin: 0 !important; padding: 0 !important; transform: none !important; zoom: 1 !important; scale: 1 !important; }
                #print_standalone_zone { width: 104mm !important; margin: 0 !important; padding: 0 !important; transform: none !important; zoom: 1 !important; }
            }
        `;
    } else {
        styleEl.textContent = `
            @page { size: 65mm 30mm !important; margin: 0mm !important; }
            @media print {
                html, body { width: 65mm !important; height: auto !important; min-height: 0 !important; display: block !important; margin: 0 !important; padding: 0 !important; transform: none !important; zoom: 1 !important; scale: 1 !important; }
                #print_standalone_zone { width: 65mm !important; margin: 0 !important; padding: 0 !important; transform: none !important; zoom: 1 !important; }
            }
        `;
    }
}

function onEntryLabelSizeChange(size) {
    size = normalizeEntrySize(size);
    activeEntryLabelSize = size;
    const sel = document.getElementById('input_label_size_select');
    if (sel && sel.value !== size) {
        sel.value = size;
    }
    try { localStorage.setItem('printbox_default_label_size', size); } catch (e) {}
    applyEntryPrintPageStyle(size);

    const mockBox = document.getElementById('entry_mock_label_box');
    if (mockBox) {
        mockBox.classList.remove('label-size-65x30', 'label-size-110x80');
        mockBox.classList.add('label-size-' + size);
    }
    const mockTxt = document.getElementById('entry_mock_size_txt');
    if (mockTxt) {
        mockTxt.innerText = (size === '110x80') ? '80 × 100 / 100 × 80 mm - Ngang' : '65mm × 30mm - Ngang';
    }

    const fd = new FormData();
    fd.append('action', 'save_default_label_size');
    fd.append('label_size', size);
    fetch('orders.php', { method: 'POST', body: fd }).catch(() => {});
}

(function initEntryDefaultSize() {
    const pSel = document.getElementById('input_printer_select');
    const matched = pSel ? detectEntrySizeFromPrinter(pSel.value) : null;
    if (matched) {
        activeEntryLabelSize = matched;
    }
    const sel = document.getElementById('input_label_size_select');
    if (sel && sel.value !== activeEntryLabelSize) {
        sel.value = activeEntryLabelSize;
    }
    applyEntryPrintPageStyle(activeEntryLabelSize);
})();

const currentEntryUserId = <?= (int)$currentUserId ?>;
let serverEntryUserDefaultPrinter = <?= json_encode($defaultPrinter, JSON_UNESCAPED_UNICODE) ?: '""' ?>;
let entryClientPrintersRegistry = [];
const ENTRY_NO_PRINTER_MSG = 'Không tìm thấy máy in trên máy tính hiện tại. Vui lòng kiểm tra dịch vụ in hoặc kết nối máy in.';

function isEntryVirtualPrinter(name) {
    const n = String(name || '').trim().toLowerCase();
    if (!n) return true;
    return ['microsoft print to pdf', 'microsoft xps document writer', 'onenote', 'send to onenote', 'fax', 'adobe pdf', 'foxit reader pdf printer', 'pdfcreator', 'cutepdf'].some(p => n.includes(p));
}

function isEntryPageOnLocalhost() {
    const h = window.location.hostname || '';
    return h === 'localhost' || h === '127.0.0.1' || h === '::1';
}

function getEntrySavedDefaultPrinter() {
    try {
        // 1. Ưu tiên máy in lưu theo User trên máy tính hiện tại
        const uVal = localStorage.getItem('printbox_default_printer_u_' + currentEntryUserId);
        if (uVal && !isEntryVirtualPrinter(uVal)) return uVal;
        // 2. Tiếp theo là máy in lưu theo thiết bị (Browser) trên máy tính này
        const bVal = localStorage.getItem('printbox_client_default_printer');
        if (bVal && !isEntryVirtualPrinter(bVal)) return bVal;
        const legacyVal = localStorage.getItem('printbox_default_printer');
        if (legacyVal && !isEntryVirtualPrinter(legacyVal)) return legacyVal;
        // 3. Chỉ lấy máy in mặc định server nếu đang ở chính máy server (localhost)
        if (isEntryPageOnLocalhost() && serverEntryUserDefaultPrinter && !isEntryVirtualPrinter(serverEntryUserDefaultPrinter)) {
            return serverEntryUserDefaultPrinter;
        }
    } catch (e) {}
    return '';
}

function getEntryCustomPrinters() {
    try {
        const raw = localStorage.getItem('printbox_client_custom_printers');
        if (raw) {
            const list = JSON.parse(raw);
            if (Array.isArray(list)) {
                return list
                    .map(item => (typeof item === 'string') ? { name: item, default: false, source: 'client_custom' } : item)
                    .filter(p => p && p.name && !isEntryVirtualPrinter(p.name));
            }
        }
    } catch (e) {}
    return [];
}

function saveEntryCustomPrinters(list) {
    try {
        localStorage.setItem('printbox_client_custom_printers', JSON.stringify(list));
    } catch (e) {}
}

function removeEntrySavedPrinter() {
    const sel = document.getElementById('input_printer_select');
    const current = (sel ? sel.value : '') || getEntrySavedDefaultPrinter();
    if (!current || current === 'Browser Print') {
        alert('Vui lòng chọn máy in bạn muốn xóa khỏi danh sách cấu hình!');
        return;
    }
    if (!confirm(`Bạn có chắc chắn muốn xóa cấu hình máy in "${current}" khỏi máy tính này không?`)) {
        return;
    }
    const customList = getEntryCustomPrinters().filter(p => p.name.toLowerCase() !== current.toLowerCase());
    saveEntryCustomPrinters(customList);

    try {
        localStorage.removeItem('printbox_default_printer_u_' + currentEntryUserId);
        localStorage.removeItem('printbox_client_default_printer');
        localStorage.removeItem('printbox_default_printer');
        localStorage.removeItem('mes_default_printer');
    } catch(e) {}

    onEntryPrinterChange('Browser Print');
    refreshEntryClientPrinters(true, true);
    setEntryPrinterMsg(`✓ Đã xóa cấu hình máy in "${current}" khỏi máy tính này!`, '#15803d');
}

function resetEntryPrinterConfig() {
    if (!confirm("Bạn có chắc chắn muốn ĐẶT LẠI TOÀN BỘ cấu hình máy in trên máy tính này không?\n\n- Toàn bộ máy in tùy chỉnh đã thêm sẽ được xóa.\n- Cấu hình máy in mặc định sẽ được đặt lại.\n- Hệ thống sẽ quét lại từ đầu.")) {
        return;
    }
    try {
        localStorage.removeItem('printbox_default_printer_u_' + currentEntryUserId);
        localStorage.removeItem('printbox_client_default_printer');
        localStorage.removeItem('printbox_default_printer');
        localStorage.removeItem('printbox_client_custom_printers');
        localStorage.removeItem('mes_default_printer');
    } catch(e) {}

    onEntryPrinterChange('Browser Print');
    refreshEntryClientPrinters(true, true);
    setEntryPrinterMsg('✓ Đã đặt lại toàn bộ cấu hình máy in trên máy tính này!', '#15803d');
}

function logEntryPrintOperation({
    user = 'User #' + currentEntryUserId,
    printer = '',
    labelSize = '',
    printMode = '',
    resultStatus = '',
    details = '',
    labelCount = 0
}) {
    const timeStr = new Date().toLocaleString('vi-VN');
    const badgeColor = resultStatus === 'DIRECT_SUCCESS' ? '#16a34a' :
                       resultStatus === 'BROWSER_DIALOG_FALLBACK' ? '#d97706' : '#dc2626';
    const statusText = resultStatus === 'DIRECT_SUCCESS' ? '✅ In trực tiếp thành công' :
                       resultStatus === 'BROWSER_DIALOG_FALLBACK' ? '⚠️ Chuyển sang Print Dialog của trình duyệt' :
                       resultStatus === 'PRINTER_NOT_FOUND' ? '❌ Không tìm thấy máy in' : '❌ Lỗi gửi lệnh in';

    console.group(`%c[NHẬT KÝ IN TEM / PRINT LOG (ENTRY)] - ${statusText} - ${timeStr}`, `color:${badgeColor};font-weight:bold;`);
    console.log('👤 Người dùng:', user);
    console.log('🖨️ Máy in được chọn:', printer || '(Chưa chọn máy in)');
    console.log('📐 Khổ tem:', labelSize || 'Mặc định');
    console.log('⚙️ Chế độ in:', printMode || 'direct');
    console.log('⏰ Thời gian in:', timeStr);
    console.log('📦 Số lượng tem:', labelCount);
    console.log('📊 Kết quả gửi lệnh in:', statusText);
    if (details) console.log('📝 Chi tiết / Lý do:', details);
    console.groupEnd();
}

function buildZplForEntryZebra(labels, labelSize) {
    const isLarge = (labelSize === '110x80');
    const pw = isLarge ? 832 : 520;
    const ll = isLarge ? 640 : 240;
    let zpl = '';
    labels.forEach(lb => {
        const qrText = String(lb.qr_content || '').replace(/\^/g, ' ');
        const mat = String(lb.material_name || '').replace(/\^/g, ' ');
        const qty = String(lb.qty || '') + ' pcs' + (lb.weight ? ' | ' + lb.weight + ' Kg' : '');
        const lot = String(lb.lot_no || '') + (lb.supplier ? ' | ' + lb.supplier : '');
        const box = String(lb.box_no || '').replace(/\^/g, ' ');
        if (isLarge) {
            zpl += `^XA^CI28^PW${pw}^LL${ll}^LH0,0`;
            zpl += `^FO28,45^BQN,2,10^FDMA,${qrText}^FS`;
            zpl += `^FO390,55^A0N,42,42^FB420,2,4,L,0^FD${mat}^FS`;
            zpl += `^FO390,175^GB415,3,3^FS`;
            zpl += `^FO390,200^A0N,50,50^FD${qty}^FS`;
            zpl += `^FO390,275^GB415,3,3^FS`;
            zpl += `^FO390,295^A0N,34,34^FD||^FS`;
            zpl += `^FO390,345^GB415,3,3^FS`;
            zpl += `^FO390,370^A0N,38,38^FB420,2,4,L,0^FD${lot}^FS`;
            zpl += `^FO390,475^GB415,3,3^FS`;
            zpl += `^FO390,500^A0N,36,36^FD${box}^FS`;
            zpl += `^XZ\n`;
        } else {
            zpl += `^XA^CI28^PW${pw}^LL${ll}^LH0,0`;
            zpl += `^FO18,20^BQN,2,6^FDMA,${qrText}^FS`;
            zpl += `^FO230,22^A0N,28,28^FB280,2,2,L,0^FD${mat}^FS`;
            zpl += `^FO230,78^GB280,2,2^FS`;
            zpl += `^FO230,86^A0N,28,28^FD${qty}^FS`;
            zpl += `^FO230,126^GB280,2,2^FS`;
            zpl += `^FO230,132^A0N,22,22^FD||^FS`;
            zpl += `^FO230,162^GB280,2,2^FS`;
            zpl += `^FO230,168^A0N,22,22^FD${lot}^FS`;
            zpl += `^FO230,198^GB280,2,2^FS`;
            zpl += `^FO230,204^A0N,26,26^FD${box}^FS`;
            zpl += `^XZ\n`;
        }
    });
    return zpl;
}

async function dispatchEntryClientDirectPrint(labels, selectedPrinter, labelSize) {
    if (!selectedPrinter || selectedPrinter === 'Browser Print') {
        return { success: false, fallback_to_browser: true, message: 'Chế độ In Qua Trình Duyệt được chọn' };
    }
    const printerEntry = entryClientPrintersRegistry.find(p => p.name.toLowerCase() === String(selectedPrinter || '').toLowerCase());

    const isHttps = (window.location.protocol === 'https:');
    const port = isHttps ? '9101' : '9100';
    const proto = isHttps ? 'https:' : 'http:';
    const writeUrl = `${proto}//127.0.0.1:${port}/write`;

    const zplData = buildZplForEntryZebra(labels, labelSize);

    const dev = (printerEntry && printerEntry.device) ? printerEntry.device : {
        name: selectedPrinter,
        uid: (printerEntry && printerEntry.uid) ? printerEntry.uid : selectedPrinter,
        connection: 'driver',
        deviceType: 'printer',
        version: 4
    };

    try {
        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), 6000);
        const res = await fetch(writeUrl, {
            method: 'POST',
            mode: 'cors',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                device: dev,
                data: zplData
            }),
            signal: ctrl.signal
        });
        clearTimeout(timer);
        if (res.ok) {
            return {
                success: true,
                message: `Đã gửi trực tiếp ${labels.length} tem đến máy in "${selectedPrinter}" qua Zebra Browser Print.`
            };
        }
    } catch (e) {
        console.warn('Lỗi gửi lệnh in Zebra Browser Print (Entry):', e);
    }

    return {
        success: false,
        message: `Dịch vụ Zebra Browser Print không phản hồi hoặc không thể in ra "${selectedPrinter}".`
    };
}

function promptAddEntryCustomPrinter() {
    const printerName = prompt('Nhập tên máy in đã cài đặt trên máy tính này (Ví dụ: Zebra ZD421, Godex G500, XPrinter XP-350B, Brother QL-820NWB):');
    if (!printerName || !printerName.trim()) return;
    const cleanName = printerName.trim();
    if (isEntryVirtualPrinter(cleanName)) {
        alert('Vui lòng nhập tên máy in tem vật lý, không nhập tên máy in ảo (PDF, XPS...).');
        return;
    }
    const current = getEntryCustomPrinters();
    const existingIndex = current.findIndex(p => p.name.toLowerCase() === cleanName.toLowerCase());
    if (existingIndex < 0) {
        current.push({ name: cleanName, default: true, source: 'client_custom' });
        saveEntryCustomPrinters(current);
    }
    onEntryPrinterChange(cleanName);
    refreshEntryClientPrinters(true, true);
}

function setEntryPrinterMsg(msg, color = '#b91c1c') {
    const el = document.getElementById('entry_printer_status_msg');
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

function onEntryPrinterChange(printerName) {
    if (!printerName) return;
    serverEntryUserDefaultPrinter = printerName;
    try {
        localStorage.setItem('printbox_default_printer_u_' + currentEntryUserId, printerName);
        localStorage.setItem('printbox_client_default_printer', printerName);
        localStorage.setItem('printbox_default_printer', printerName);
        localStorage.setItem('mes_default_printer', printerName);
    } catch (e) {}
    setEntryPrinterMsg('');
    const matchedSize = detectEntrySizeFromPrinter(printerName);
    if (matchedSize && matchedSize !== getEntryLabelSize()) {
        onEntryLabelSizeChange(matchedSize);
    }
    const fd = new FormData();
    fd.append('action', 'save_default_printer');
    fd.append('printer_name', printerName);
    fetch('orders.php', { method: 'POST', body: fd }).catch(() => {});
}

async function probeEntryZebraBrowserPrintClient(forceRefresh = false) {
    const isHttps = (window.location.protocol === 'https:');
    const port = isHttps ? '9101' : '9100';
    const proto = isHttps ? 'https:' : 'http:';
    const hosts = ['127.0.0.1', 'localhost'];

    for (const host of hosts) {
        const baseUrl = `${proto}//${host}:${port}`;
        try {
            const ctrl = new AbortController();
            const timer = setTimeout(() => ctrl.abort(), 1800);
            const res = await fetch(`${baseUrl}/available`, { method: 'GET', mode: 'cors', signal: ctrl.signal });
            clearTimeout(timer);
            if (!res.ok) continue;

            const data = await res.json();
            const list = [];
            const addPrinter = (p, isDef = false) => {
                if (!p) return;
                const name = typeof p === 'string' ? p.trim() : String(p.name || '').trim();
                if (!name || isEntryVirtualPrinter(name)) return;
                const isZebra = /zdesigner|zebra|zd|zt|gk|gx|gc|gt/i.test(name) ||
                                (p.manufacturer && /zebra/i.test(p.manufacturer)) ||
                                (p.deviceType === 'printer' || p.connection === 'driver');
                if (isZebra) {
                    list.push({
                        name: name,
                        default: isDef || Boolean(p.default),
                        source: 'zebra_browser_print',
                        device: typeof p === 'object' ? p : { name: name, uid: name, connection: 'driver', deviceType: 'printer', version: 4 },
                        uid: (p && p.uid) ? p.uid : name,
                        is_thermal: true
                    });
                }
            };

            if (data && Array.isArray(data.printer)) {
                data.printer.forEach(p => addPrinter(p, false));
            } else if (Array.isArray(data)) {
                data.forEach(p => addPrinter(p, false));
            } else if (data && data.device) {
                if (Array.isArray(data.device)) {
                    data.device.forEach(p => addPrinter(p, false));
                } else {
                    addPrinter(data.device, true);
                }
            }

            if (list.length > 0) {
                try {
                    const defCtrl = new AbortController();
                    const defTimer = setTimeout(() => defCtrl.abort(), 800);
                    const defRes = await fetch(`${baseUrl}/default?type=printer`, { method: 'GET', mode: 'cors', signal: defCtrl.signal });
                    clearTimeout(defTimer);
                    if (defRes.ok) {
                        const defData = await defRes.json();
                        const defName = defData && (defData.name || (defData.device && defData.device.name));
                        if (defName) {
                            const found = list.find(item => item.name.toLowerCase() === String(defName).trim().toLowerCase());
                            if (found) found.default = true;
                        }
                    }
                } catch (e) {}
                return list;
            }
        } catch (e) {}
    }
    return [];
}

async function discoverEntryClientPrinters() {
    // ƯU TIÊN 1: ZEBRA BROWSER PRINT LÀ CƠ CHẾ CHÍNH (PRIMARY)
    const zebraList = await probeEntryZebraBrowserPrintClient();
    if (Array.isArray(zebraList) && zebraList.length > 0) {
        // Tự động lấy danh sách từ Browser Print. Không lấy máy in từ Server, Không lấy từ Database.
        return zebraList;
    }

    // ƯU TIÊN 2: NẾU BROWSER PRINT KHÔNG HOẠT ĐỘNG -> FALLBACK IN QUA TRÌNH DUYỆT
    return [];
}

async function refreshEntryClientPrinters(showFeedback = false, forceRefresh = false, retryCount = 0) {
    if (showFeedback) {
        setEntryPrinterMsg('⏳ Đang quét các máy in trên máy tính hiện tại...', '#2563eb');
    }
    const discovered = await discoverEntryClientPrinters();
    entryClientPrintersRegistry = discovered;
    const saved = getEntrySavedDefaultPrinter();
    const sel = document.getElementById('input_printer_select');
    if (!sel) return;
    sel.innerHTML = '';

    let selectedPrinter = '';
    let savedMissing = false;

    if (discovered.length > 0) {
        if (saved && saved !== 'Browser Print') {
            const found = discovered.find(p => p.name.toLowerCase() === saved.toLowerCase());
            if (found) {
                selectedPrinter = found.name;
            } else {
                savedMissing = true;
            }
        }
        if (!selectedPrinter && !savedMissing) {
            const defP = discovered.find(p => p.default) || discovered[0];
            if (defP) {
                selectedPrinter = defP.name;
                onEntryPrinterChange(selectedPrinter);
            }
        }
    }

    if (discovered.length === 0) {
        const opt = document.createElement('option');
        opt.value = 'Browser Print';
        opt.textContent = '🌐 In Qua Trình Duyệt (Sử dụng máy in của máy tính này)';
        opt.selected = true;
        sel.appendChild(opt);
        setEntryPrinterMsg('Không phát hiện dịch vụ máy in cục bộ. Bạn vẫn có thể sử dụng In Qua Trình Duyệt.', '#2563eb');
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
            let connBadge = '';
            if (p.source === 'zebra' || p.source === 'zebra_browser_print') {
                connBadge = ' [Zebra Local]';
            } else if (p.source === 'qz' || p.source === 'qz_tray') {
                connBadge = ' [Windows/QZ Tray]';
            } else if (p.source === 'webusb' || p.source === 'usb') {
                connBadge = ' [USB Client]';
            } else if (p.source === 'client_custom') {
                connBadge = ' [Máy in cục bộ]';
            } else if (p.is_network) {
                connBadge = ` [Mạng LAN${p.port ? ': ' + p.port : ''}]`;
            } else if (p.is_shared) {
                connBadge = ' [Shared]';
            } else {
                connBadge = ' [Cục bộ]';
            }

            if (p.is_thermal) connBadge += ' (Tem)';
            if (p.default)    connBadge += ' (Mặc định)';

            opt.textContent = '🖨️ ' + p.name + connBadge;
            if (selectedPrinter && p.name.toLowerCase() === selectedPrinter.toLowerCase()) opt.selected = true;
            sel.appendChild(opt);
        });

        // Option In Qua Trình Duyệt
        const browserOpt = document.createElement('option');
        browserOpt.value = 'Browser Print';
        browserOpt.textContent = '🌐 In Qua Trình Duyệt (Sử dụng máy in của máy tính này)';
        if (selectedPrinter === 'Browser Print') browserOpt.selected = true;
        sel.appendChild(browserOpt);

        if (savedMissing) {
            setEntryPrinterMsg(`⚠️ Máy in mặc định đã lưu ("${saved}") không còn tồn tại trên máy tính này. Vui lòng chọn lại máy in!`, '#b45309');
        } else if (showFeedback) {
            setEntryPrinterMsg(`✓ Đã tìm thấy ${discovered.length} máy in trên máy tính hiện tại!`, '#15803d');
        } else {
            setEntryPrinterMsg('');
        }
    }

    // BỔ SUNG LOG KIỂM TRA CHI TIẾT THEO YÊU CẦU
    const comboboxOptionsList = Array.from(sel.options).map(o => ({
        value: o.value,
        text: o.textContent,
        selected: o.selected
    }));

    console.group('[KIỂM TRA MÁY IN CLIENT / PRINTER INSPECTION (PRINT ENTRY)]');
    console.log('1. Danh sách lấy từ Client:', discovered.map(p => ({
        name: p.name,
        source: p.source || 'client',
        default: Boolean(p.default),
        is_network: Boolean(p.is_network)
    })));
    console.log('2. Danh sách hiển thị combobox:', comboboxOptionsList);
    console.log('3. Máy in mặc định đang chọn:', selectedPrinter || (savedMissing ? `(Máy in "${saved}" không còn kết nối - Yêu cầu chọn lại)` : '(Chưa chọn - vui lòng chọn)'));
    console.groupEnd();
}

refreshEntryClientPrinters(false);

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
            verifiedAdminPass = null;
            verifiedOverrideReason = null;

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
    verifiedOverrideReason = null;

    if (isNaN(printQtyVal) || printQtyVal <= 0) {
        document.getElementById('calc_placeholder').style.display = 'block';
        document.getElementById('calc_result_panel').style.display = 'none';
        document.getElementById('odd_box_option').style.display = 'none';
        document.getElementById('chk_is_odd_box').checked = false;
        document.getElementById('alert_over_target').style.display = 'none';
        currentCalculation = null;
        return;
    }

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
    
    if (!hasOdd) {
        document.getElementById('row_calc_odd').style.display = 'none';
        document.getElementById('odd_box_option').style.display = 'none';
        document.getElementById('chk_is_odd_box').checked = false;
    } else {
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

// BƯỚC 3 & 4: KIỂM TRA DỮ LIỆU TRƯỚC KHI IN & TỰ ĐỘNG GỬI LỆNH IN
function validateEntryPrePrint() {
    const errors = [];
    if (!currentOrder) {
        errors.push('Chưa chọn Chỉ Thị Sản Xuất.');
    } else {
        if (!currentOrder.order_code || String(currentOrder.order_code).trim() === '') {
            errors.push('Thiếu Mã CTSX (Lot No).');
        }
        if (!currentOrder.product_code || String(currentOrder.product_code).trim() === '') {
            errors.push('Thiếu Mã sản phẩm.');
        }
    }
    if (!currentCalculation || !currentCalculation.printQty || currentCalculation.printQty <= 0 || !currentCalculation.totalBoxes || currentCalculation.totalBoxes <= 0) {
        errors.push('Số lượng tem sản phẩm / số thùng không hợp lệ (phải lớn hơn 0).');
    }
    const printerSelect = document.getElementById('input_printer_select');
    const selectedPrinter = printerSelect ? printerSelect.value.trim() : '';
    if (entryClientPrintersRegistry.length === 0) {
        errors.push(ENTRY_NO_PRINTER_MSG);
    } else if (!selectedPrinter) {
        errors.push('Vui lòng chọn máy in tem đang kết nối trên máy tính hiện tại.');
    }
    const selectedSize = getEntryLabelSize();
    if (selectedSize !== '65x30' && selectedSize !== '110x80') {
        errors.push('Kích thước tem không hợp lệ.');
    }
    return {
        valid: errors.length === 0,
        errors: errors,
        printer: selectedPrinter,
        labelSize: selectedSize
    };
}

function executeEntryPrintSubmission() {
    const check = validateEntryPrePrint();
    if (!check.valid) {
        alert('LỖI DỮ LIỆU TRƯỚC KHI IN:\n- ' + check.errors.join('\n- '));
        return;
    }

    const btnStep = document.getElementById('btn_submit_step');
    const btnFinal = document.getElementById('btn_final_submit');
    if (btnStep) {
        btnStep.disabled = true;
        btnStep.innerText = '⏳ ĐANG TỰ ĐỘNG SINH TEM & GỬI LỆNH IN...';
    }
    if (btnFinal) {
        btnFinal.disabled = true;
        btnFinal.innerText = '⏳ Đang lưu & gửi lệnh in...';
    }

    const formData = new FormData();
    formData.append('order_id', currentOrder.id);
    formData.append('print_qty', currentCalculation.printQty);
    formData.append('is_odd_box', (currentCalculation.hasOdd && document.getElementById('chk_is_odd_box').checked) ? 1 : 0);
    formData.append('admin_msnv', verifiedAdminMsnv || '');
    formData.append('admin_pass', verifiedAdminPass || '');
    formData.append('override_reason', verifiedOverrideReason || '');
    formData.append('printer_name', check.printer);
    formData.append('label_size', check.labelSize);
    formData.append('print_mode', getEntryPrintMode());
    formData.append('note', document.getElementById('input_note').value.trim());

    fetch('ajax/submit_print.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (btnStep) {
            btnStep.disabled = false;
            btnStep.innerText = '📥 XÁC NHẬN NHẬP & IN TEM THÙNG';
        }
        if (btnFinal) {
            btnFinal.disabled = false;
            btnFinal.innerText = '✅ ĐỒNG Ý NHẬP & IN NGAY';
        }

        if (!data.success) {
            if (data.require_admin) {
                closeModal('modal_confirm', true);
                verifiedAdminMsnv = null;
                verifiedAdminPass = null;
                verifiedOverrideReason = null;
                document.getElementById('modal_req_qty').innerText = (data.print_qty || currentCalculation.printQty).toLocaleString();
                document.getElementById('modal_rem_qty').innerText = (data.remaining_qty !== undefined ? data.remaining_qty : currentOrder.remaining_qty).toLocaleString();
                document.getElementById('modal_diff_qty').innerText = '+' + ((data.excess_qty !== undefined ? data.excess_qty : (currentCalculation.printQty - currentOrder.remaining_qty))).toLocaleString() + ' con';
                document.getElementById('input_admin_msnv').value = '';
                document.getElementById('input_admin_pass').value = '';
                document.getElementById('input_override_reason').value = '';
                document.getElementById('admin_verify_feedback').innerText = data.message;
                document.getElementById('admin_verify_feedback').style.color = '#b91c1c';
                openModal('modal_admin_approval');
            } else if (data.require_odd_confirm) {
                closeModal('modal_confirm', true);
                alert(data.message);
                document.getElementById('chk_is_odd_box').focus();
            } else {
                alert('Lỗi: ' + data.message);
            }
            return;
        }

        if (!Array.isArray(data.labels) || data.labels.length === 0) {
            alert('Lỗi dữ liệu in: Không sinh được danh sách tem QR!');
            return;
        }

        closeModal('modal_confirm', true);
        renderPrintedLabels(data.labels);
        document.getElementById('res_box_count').innerText = data.labels.length;

        if (getEntryPrintMode() === 'preview') {
            logEntryPrintOperation({
                printer: check.printer,
                labelSize: check.labelSize,
                printMode: 'preview',
                resultStatus: 'DIRECT_SUCCESS',
                details: 'Mở xem trước tem',
                labelCount: data.labels.length
            });
            openModal('modal_print_result');
        const entryPrinterObj = entryClientPrintersRegistry.find(p => p.name.toLowerCase() === String(check.printer || '').toLowerCase());
        const isEntryZebraDirect = entryPrinterObj && entryPrinterObj.source === 'zebra_browser_print';

        if (check.printer === 'Browser Print' || !check.printer) {
            logEntryPrintOperation({
                printer: 'Browser Print',
                labelSize: check.labelSize,
                printMode: 'browser',
                resultStatus: 'BROWSER_DIALOG_FALLBACK',
                details: 'Người dùng chọn chế độ In Qua Trình Duyệt thủ công',
                labelCount: data.labels.length
            });
            triggerDirectPrint(() => {
                resetFormForNext();
            });
        } else if (isEntryZebraDirect) {
            // CƠ CHẾ CHÍNH: Gửi trực tiếp qua Zebra Browser Print trên Client
            dispatchEntryClientDirectPrint(data.labels, check.printer, check.labelSize).then(clientRes => {
                if (clientRes && clientRes.success) {
                    logEntryPrintOperation({
                        printer: check.printer,
                        labelSize: check.labelSize,
                        printMode: 'direct',
                        resultStatus: 'DIRECT_SUCCESS',
                        details: clientRes.message || 'In trực tiếp qua Zebra Browser Print thành công',
                        labelCount: data.labels.length
                    });
                    alert(clientRes.message || `✓ Đã gửi trực tiếp ${data.labels.length} tem đến máy in "${check.printer}".`);
                    resetFormForNext();
                } else {
                    logEntryPrintOperation({
                        printer: check.printer,
                        labelSize: check.labelSize,
                        printMode: 'direct',
                        resultStatus: 'BROWSER_DIALOG_FALLBACK',
                        details: (clientRes.message || 'Lỗi gửi Browser Print') + ' => Chuyển sang Print Dialog làm phương án dự phòng.',
                        labelCount: data.labels.length
                    });
                    alert(`⚠️ Không thể in trực tiếp tới máy in "${check.printer}". Hệ thống sẽ mở hộp thoại In Trình Duyệt để bạn in tem.`);
                    triggerDirectPrint(() => {
                        resetFormForNext();
                    });
                }
            });
        } else if (data.direct_printed) {
            logEntryPrintOperation({
                printer: check.printer,
                labelSize: check.labelSize,
                printMode: 'direct',
                resultStatus: 'DIRECT_SUCCESS',
                details: data.direct_print_msg || 'In trực tiếp qua Windows Print Spooler thành công',
                labelCount: data.labels.length
            });
            alert(`✓ Đã tự động in trực tiếp ${data.labels.length} tem (${check.labelSize}mm Ngang) đến máy in "${check.printer}".`);
            resetFormForNext();
        } else {
            // Thử gửi trực tiếp qua client daemon (Zebra)
            dispatchEntryClientDirectPrint(data.labels, check.printer, check.labelSize).then(clientRes => {
                if (clientRes && clientRes.success) {
                    logEntryPrintOperation({
                        printer: check.printer,
                        labelSize: check.labelSize,
                        printMode: 'direct',
                        resultStatus: 'DIRECT_SUCCESS',
                        details: clientRes.message || 'In trực tiếp qua Client Print Daemon thành công',
                        labelCount: data.labels.length
                    });
                    alert(clientRes.message || `✓ Đã gửi trực tiếp ${data.labels.length} tem đến máy in "${check.printer}".`);
                    resetFormForNext();
                } else {
                    // CHỈ mở Print Dialog khi in trực tiếp thất bại
                    const reason = clientRes && clientRes.message ? clientRes.message : `Máy in "${check.printer}" không hỗ trợ in trực tiếp hoặc dịch vụ in không hoạt động.`;
                    logEntryPrintOperation({
                        printer: check.printer,
                        labelSize: check.labelSize,
                        printMode: 'direct',
                        resultStatus: 'BROWSER_DIALOG_FALLBACK',
                        details: reason + ' => Chuyển sang Print Dialog làm phương án dự phòng.',
                        labelCount: data.labels.length
                    });
                    alert(`⚠️ Không thể in trực tiếp tới máy in "${check.printer}". Hệ thống sẽ mở hộp thoại In Trình Duyệt để bạn in tem.`);
                    triggerDirectPrint(() => {
                        resetFormForNext();
                    });
                }
            });
        }
    })
    .catch(err => {
        if (btnStep) {
            btnStep.disabled = false;
            btnStep.innerText = '📥 XÁC NHẬN NHẬP & IN TEM THÙNG';
        }
        if (btnFinal) {
            btnFinal.disabled = false;
            btnFinal.innerText = '✅ ĐỒNG Ý NHẬP & IN NGAY';
        }
        alert('Lỗi gửi dữ liệu: ' + err.message);
    });
}

document.getElementById('btn_verify_and_proceed').addEventListener('click', function() {
    const msnv = document.getElementById('input_admin_msnv').value.trim();
    const pass = document.getElementById('input_admin_pass').value.trim();
    const overrideReason = document.getElementById('input_override_reason').value.trim();

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

    if (!overrideReason) {
        alert('Vui lòng nhập Lý do in vượt định mức (bắt buộc)!');
        document.getElementById('input_override_reason').focus();
        return;
    }

    const fb = document.getElementById('admin_verify_feedback');
    fb.style.color = '#2563eb';
    fb.innerText = 'Đang kiểm tra quyền duyệt...';

    const formData = new FormData();
    formData.append('msnv', msnv);
    formData.append('password', pass);
    formData.append('override_reason', overrideReason);

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
            verifiedOverrideReason = overrideReason;
            
            setTimeout(() => {
                closeModal('modal_admin_approval', true);
                executeEntryPrintSubmission();
            }, 250);
        } else {
            fb.style.color = '#b91c1c';
            fb.innerText = '✗ ' + data.message;
            verifiedAdminMsnv = null;
            verifiedAdminPass = null;
            verifiedOverrideReason = null;
        }
    })
    .catch(err => {
        fb.style.color = '#b91c1c';
        fb.innerText = 'Lỗi kết nối: ' + err.message;
        verifiedAdminMsnv = null;
        verifiedAdminPass = null;
        verifiedOverrideReason = null;
    });
});

document.getElementById('btn_final_submit').addEventListener('click', function() {
    executeEntryPrintSubmission();
});

// RENDER DANH SÁCH TEM THÙNG ĐỂ IN (CHUẨN 65x30MM & 110x80MM NGANG)
function renderPrintedLabels(labels) {
    const currentSize = getEntryLabelSize();
    applyEntryPrintPageStyle(currentSize);

    const container = document.getElementById('printable_labels_container');
    if (container) {
        container.className = 'label-preview-wrapper label-size-' + currentSize;
        container.innerHTML = '';
    }

    let standaloneZone = document.getElementById('print_standalone_zone');
    if (!standaloneZone) {
        standaloneZone = document.createElement('div');
        standaloneZone.id = 'print_standalone_zone';
        document.body.appendChild(standaloneZone);
    }
    standaloneZone.className = 'print-standalone-zone label-size-' + currentSize;
    standaloneZone.innerHTML = '';

    labels.forEach((item) => {
        const labelHtml = `
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
        if (container) {
            const div = document.createElement('div');
            div.className = 'box-label-item label-size-' + currentSize;
            div.innerHTML = labelHtml;
            container.appendChild(div);
        }
        const printDiv = document.createElement('div');
        printDiv.className = 'box-label-item label-size-' + currentSize;
        printDiv.innerHTML = labelHtml;
        standaloneZone.appendChild(printDiv);
    });
}

// IN TRỰC TIẾP RA MÁY IN MẠNG / IN TRÌNH DUYỆT (CHỜ QR RENDER HOÀN TẤT TRƯỚC KHI IN)
function triggerDirectPrint(onAfterPrint) {
    applyEntryPrintPageStyle(getEntryLabelSize());
    const standaloneZone = document.getElementById('print_standalone_zone');
    const imgs = standaloneZone ? Array.from(standaloneZone.querySelectorAll('img')) : [];
    const waitPromises = imgs.map(img => {
        if (img.complete && img.naturalHeight !== 0) return Promise.resolve();
        if (img.decode) return img.decode().catch(() => Promise.resolve());
        return new Promise(resolve => { img.onload = resolve; img.onerror = resolve; });
    });
    Promise.all(waitPromises).then(() => {
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                setTimeout(() => {
                    window.print();
                    if (typeof onAfterPrint === 'function') {
                        onAfterPrint();
                    }
                }, 120);
            });
        });
    });
}

// TIẾP TỤC ĐƠN KHÁC
function resetFormForNext() {
    closeModal('modal_print_result');
    if (document.getElementById('modal_print_result').classList.contains('active')) {
        return;
    }
    document.getElementById('input_query').value = '';
    document.getElementById('input_query').focus();
    document.getElementById('order_info_card').style.display = 'none';
    document.getElementById('print_section').style.display = 'none';
    document.getElementById('calc_placeholder').style.display = 'block';
    document.getElementById('calc_result_panel').style.display = 'none';
    currentOrder = null;
    currentCalculation = null;
    verifiedAdminMsnv = null;
    verifiedAdminPass = null;
    verifiedOverrideReason = null;
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

