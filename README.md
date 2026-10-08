# HỆ THỐNG QUẢN LÝ IN TEM SẢN XUẤT NHÀ MÁY (PRINTBOX INTRANET)

Hệ thống quản lý và in tem thùng sản xuất chuyên nghiệp chạy trên mạng nội bộ (Intranet), tương thích 100% với môi trường XAMPP (Apache + PHP 7.4 - 8.3+ + MySQL/phpMyAdmin). Hoạt động hoàn toàn Offline, độc lập, bảo mật và ổn định tại nhà xưởng công nghiệp.

---

## 1. TỔNG QUAN NÂNG CẤP & CÁC LỖI ĐÃ KHẮC PHỤC TRIỆT ĐỂ

### 1.1. Khắc phục triệt để lỗi Modal bị đơ & Kẹt lớp phủ Backdrop
- **Nguyên nhân trước đây**: Lớp phủ Modal tùy biến (`.modal-overlay`) có `z-index: 1000`, thấp hơn lớp phủ của Bootstrap Offcanvas (`z-index: 1040`). Khi người dùng mở Modal duyệt vượt mức hoặc Modal xác nhận in từ bên trong Offcanvas in tem, lớp backdrop của Offcanvas nằm đè lên Modal, chặn toàn bộ sự kiện click chuột (`pointer-events`).
- **Giải pháp xử lý**:
  - Tăng `z-index` của `.modal-overlay` lên **2100** và dialog lên **2110** (nổi lên trên mọi thành phần giao diện).
  - Bổ sung `pointer-events: none` khi ẩn và `pointer-events: auto` khi kích hoạt (`.active`), triệt tiêu 100% tình trạng "chết con trỏ".
  - Bổ sung sự kiện đóng Modal mượt mà khi ấn phím `Escape` hoặc click vào vùng nền bên ngoài dialog.
  - Tự động khóa cuộn trang (`body.modal-open`) và focus ngay vào ô nhập liệu khi mở Modal.

### 1.2. Khắc phục lỗi cú pháp JavaScript (`Uncaught SyntaxError: Unexpected end of input`)
- **Nguyên nhân trước đây**: Việc truyền trực tiếp chuỗi JSON object khổng lồ vào thuộc tính HTML inline `onclick='openEditModal({...})'` hoặc `data-order='{...}'` khiến trình duyệt bị lỗi cú pháp khi dữ liệu có chứa dấu nháy đơn, nháy kép hoặc ký tự xuống dòng.
- **Giải pháp xử lý**:
  - Chuyển đổi toàn bộ sang phương thức gọi hàm an toàn theo ID: `openOffcanvasPrintById(orderId)`, `openEditModalById(orderId)`.
  - Dữ liệu chỉ thị được đồng bộ vào biến toàn cục `ordersDataMap` và `allSpecsMap`.
  - Tất cả các hàm sự kiện được khai báo toàn cục trên `window` sẵn sàng trước khi DOM tải xong.

### 1.3. Khắc phục lỗi quét mã QR trên thiết bị di động
- Bổ sung chuẩn phân đoạn **ECI Mode (ECI 26 = UTF-8)** trong bộ sinh mã QR (`libs/phpqrcode/qrlib.php`). Mọi camera điện thoại (iPhone, Samsung, Xiaomi, Zalo, ứng dụng quét mã vạch công nghiệp) đều tự động nhận diện đúng font tiếng Việt, không bị lỗi vỡ font UTF-8 hay trắng màn hình.
- Làm sạch chuỗi dữ liệu đầu vào: loại bỏ ký tự ngắt dòng `\r`, `\n` và ký tự phân cách `$`, đảm bảo cấu trúc dữ liệu không bị vỡ.
- Xuất mã QR dạng Base64 Data URI sắc nét, tải tức thì kèm thuộc tính `loading="eager"`.

---

## 2. NGHIỆP VỤ CỐT LÕI HỆ THỐNG

### 2.1. Quản lý Chỉ thị sản xuất (CTSX) & Trạng thái Tạm dừng
- **Thêm mới / Chỉnh sửa chỉ thị:** Form nhập liệu an toàn, tự động tra cứu Quy cách đóng gói và Nhà cung cấp từ danh mục `product_specs` dựa theo Mã sản phẩm.
- **Tạm dừng / Tiếp tục in (`status = 'paused'`):**
  - Khi một chỉ thị ở trạng thái tạm dừng, hàng hiển thị cảnh báo `table-warning` và nhãn `⏸️ Tạm Dừng`.
  - Khóa hoàn toàn chức năng in tem trong Offcanvas và chặn ở backend (`submit_print.php`).
  - Nút chuyển trạng thái cho phép Editor/Admin mở khóa (`▶️ Mở`) hoặc tạm dừng (`⏸️ Dừng`).
- **Import / Export Chỉ thị:**
  - File CSV tải lên chuẩn hóa 6 cột: `Mã phiếu, Mã chỉ thị, Mã sản phẩm, Tháng phát hành, Số lượng chỉ thị, Ghi chú`. Tự động bỏ qua cột NCC và Quy cách để lấy từ CSDL.
  - Tự động gán Tháng phát hành nếu để trống. Cung cấp file mẫu `sample_orders.csv`.

### 2.2. Cơ sở dữ liệu Quy cách đóng gói độc lập (`specs.php`)
- Bảng `product_specs` lưu trữ: Mã sản phẩm, Quy cách đóng gói (con/thùng), Nhà cung cấp, Trọng lượng, Đơn vị tính, Mô tả.
- **Export Quy cách:** Xuất toàn bộ danh mục ra file CSV UTF-8 kèm BOM qua `export.php?type=specs`.
- **Import Quy cách:** Hỗ trợ nạp nhanh hàng loạt quy cách từ file CSV (`sample_specs.csv`), tự động nhận diện dấu `,` hoặc `;`, cập nhật tự động bằng `ON DUPLICATE KEY UPDATE`.

### 2.3. Quy trình In tem & Thuật toán Thùng lẻ
- **Thuật toán tính thùng:**
  - Số thùng chẵn: `floor(số lượng in / quy cách)`.
  - Số lượng lẻ: `số lượng in % quy cách`.
- **Quy tắc thùng lẻ:**
  - Nếu phần dư = `0`: Tuyệt đối không hiển thị cảnh báo thùng lẻ.
  - Nếu có phần dư (`> 0`): Bắt buộc hiển thị cảnh báo màu vàng và ô tick *"Cho phép in thùng lẻ"* (mặc định để trống/chưa tick). Nhân viên bắt buộc phải tự tay tích chọn mới cho phép xuất lệnh in.

### 2.4. Xác nhận ủy quyền vượt định mức (MSNV & Password)
- Khi số lượng nhập in vượt quá số lượng còn lại của chỉ thị, hệ thống bắt buộc bật Modal yêu cầu nhập **Mã số nhân viên (MSNV)** và **Mật khẩu** của Quản lý / Admin / Editor.
- Áp dụng cho mọi người dùng (kể cả khi đang đăng nhập bằng Admin hay Editor).
- Xác thực trực tiếp qua CSDL bằng AJAX `ajax/authorize_override.php`. Khi duyệt thành công, hệ thống ghi nhận người duyệt vào `print_history`, cấp mã thùng `TU-YYMMDD-XXX` và hiển thị tem in tức thì.

### 2.5. Cấu hình Mã QR Động (`settings.php`)
- Admin toàn quyền tùy chỉnh: Tiền tố (Prefix), Ký tự phân cách (Delimiter), Hậu tố (Suffix), Định dạng ngày (`d/m/Y`, `Y-m-d`, `Ymd`).
- Tùy chỉnh thứ tự các trường dữ liệu đưa vào chuỗi QR.
- **Xem trước trực quan thời gian thực (Live Preview):** Chuỗi ký tự và hình ảnh mã QR mẫu được cập nhật động bằng AJAX ngay khi Admin thay đổi cấu hình.

---

## 3. PHÂN QUYỀN TRUY CẬP (RBAC)

| Phân quyền | Tài khoản mẫu | Mật khẩu | MSNV | Thẩm quyền |
|---|---|---|---|---|
| **Viewer** | `viewer` | `viewer123` | **NV-002** | Xem danh sách chỉ thị, tra cứu và thực thi nhập in tem thùng theo định mức. |
| **Editor** | `editor` | `editor123` | **NV-001** | Xem, thêm mới, chỉnh sửa chỉ thị, tạm dừng/tiếp tục in, import/export chỉ thị và quy cách, in tem và duyệt vượt mức. |
| **Admin** | `admin` | `admin123` | **ADM-001** | Toàn quyền hệ thống: Cấu hình mã QR động, Quản lý tài khoản người dùng, duyệt vượt định mức. |

---

## 4. HƯỚNG DẪN TRIỂN KHAI TRÊN XAMPP

1. **Khởi động dịch vụ:** Mở XAMPP Control Panel, bấm **Start** cả Apache và MySQL.
2. **Khởi tạo cơ sở dữ liệu:**
   - Mở trình duyệt truy cập: `http://localhost/printbox/setup_db.php`
   - Hoặc chạy lệnh trong terminal:
     ```bash
     php setup_db.php
     ```
3. **Truy cập hệ thống:**
   - Tại máy chủ: `http://localhost/printbox/`
   - Từ các thiết bị khác trong mạng LAN: `http://[IP_MAY_CHU]/printbox/` (ví dụ: `http://192.168.1.100/printbox/`)
