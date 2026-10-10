# 🏭 HỆ THỐNG QUẢN LÝ & IN TEM THÙNG SẢN XUẤT (PRINTBOX INTRANET)

> **Phiên bản:** 2.5 Industrial Edition  
> **Môi trường vận hành:** Mạng nội bộ nhà máy (Intranet 100% Offline)  
> **Nền tảng kỹ thuật:** Apache + PHP 8.x + MySQL (MariaDB) + Bootstrap 5.3 Local + QR Code ECI UTF-8

---

## 1. GIỚI THIỆU HỆ THỐNG

**PrintBox Intranet** là phần mềm chuyên dụng phục vụ quản lý chỉ thị sản xuất (CTSX), kiểm soát định mức đóng gói và in tem nhãn thùng (QR Code) tại khu vực nhà xưởng. Hệ thống được thiết kế tối ưu cho môi trường công nghiệp với các đặc tính cốt lõi:

* **Hoạt động độc lập 100% Offline:** Toàn bộ thư viện giao diện (Bootstrap 5.3), bộ sinh mã vạch 2D (`phpqrcode`) và phông chữ đều được đóng gói nội bộ, không phụ thuộc Internet hay CDN bên ngoài.
* **Ánh xạ Quy cách Đa loại thùng (`Product Code + Box Type`):** Hỗ trợ một mã sản phẩm có nhiều quy cách đóng gói khác nhau (Loại thùng 1, Loại thùng 2,...), tự động nạp số lượng con/thùng, trọng lượng và nhà cung cấp chính xác.
* **Kiểm soát chặt chẽ Thùng chẵn / Thùng lẻ & Vượt định mức:** Tự động tính toán số lượng tem thùng cần in, cảnh báo xác nhận khi phát sinh thùng lẻ và yêu cầu xác thực Mã số nhân viên (MSNV) của cấp Quản lý khi in vượt sản lượng chỉ thị.
* **Động hóa Cấu trúc Mã QR:** Cho phép Quản trị viên tùy biến linh hoạt thứ tự trường dữ liệu, ký tự phân cách, chèn **Ký tự cố định (Custom Fixed Text)** và **Ký tự quy ước Năm/Tháng tự động** theo tiêu chuẩn riêng của từng khách hàng/nhà máy.
* **Giao diện Công nghiệp Mật độ cao (High Data Density):** Bảng dữ liệu cố định tiêu đề (`Sticky Header`), bộ lọc tìm kiếm dàn hàng ngang tiết kiệm không gian và thanh trượt nhập lệnh in (`Offcanvas`) thao tác tức thì không cần tải lại trang.

---

## 2. CẤU HÌNH & CÀI ĐẶT HỆ THỐNG

### 2.1. Yêu cầu hệ thống máy chủ (Server Requirements)
* Phần mềm máy chủ web: **XAMPP** (khuyến nghị PHP `8.0` trở lên, MySQL/MariaDB `5.7+` hoặc `10.4+`).
* Tiện ích mở rộng PHP (Extensions): `pdo_mysql`, `gd` (bắt buộc để vẽ ảnh mã QR), `mbstring`, `json`.

### 2.2. Cài đặt Cơ sở dữ liệu qua phpMyAdmin
1. Mở bảng điều khiển **XAMPP Control Panel**, nhấn **Start** cho hai dịch vụ **Apache** và **MySQL**.
2. Đặt toàn bộ thư mục mã nguồn vào đường dẫn: `D:\xampp\htdocs\printbox` (hoặc `C:\xampp\htdocs\printbox`).
3. **Cách 1 — Khởi tạo tự động (Khuyến nghị):**
   * Mở trình duyệt truy cập địa chỉ: `http://localhost/printbox/setup_db.php`
   * Hệ thống sẽ tự động tạo cơ sở dữ liệu `printbox_db`, khởi tạo đầy đủ các bảng (`users`, `product_specs`, `production_orders`, `print_history`, `printed_labels`, `system_settings`) cùng dữ liệu mẫu và tài khoản mặc định.
4. **Cách 2 — Nhập thủ công qua phpMyAdmin:**
   * Truy cập `http://localhost/phpmyadmin`.
   * Tạo cơ sở dữ liệu mới tên `printbox_db` với bảng mã `utf8mb4_unicode_ci`.
   * Chọn thẻ **Import (Nhập)** $\rightarrow$ Chọn tệp `database.sql` trong thư mục gốc dự án $\rightarrow$ Nhấn **Go (Thực hiện)**.
5. Kiểm tra thông số kết nối CSDL tại tệp `config/database.php`:
   ```php
   define('DB_HOST', '127.0.0.1');
   define('DB_NAME', 'printbox_db');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   ```

### 2.3. Kết nối Máy trạm LAN & Thiết lập Máy in Nhiệt (65mm × 30mm)
1. **Truy cập từ các máy tính/tablet trong xưởng:**
   * Xem địa chỉ IP LAN của máy chủ chạy XAMPP (ví dụ: `192.168.1.100`).
   * Trên máy trạm tại xưởng, mở trình duyệt Chrome/Edge truy cập: `http://192.168.1.100/printbox/`.
2. **Cấu hình Máy in tem nhiệt (Zebra, Godex, Xprinter, TSC...):**
   * Kết nối máy in vào máy trạm qua cổng USB hoặc chia sẻ máy in qua mạng LAN (Shared Printer).
   * Trong **Printer Preferences (Thuộc tính máy in)** của Windows, thiết lập kích thước trang giấy (Paper Size / User Defined):
     * **Width (Chiều ngang):** `65 mm`
     * **Height (Chiều cao):** `30 mm`
   * Khi hộp thoại in của trình duyệt (Chrome/Edge) hiện lên, thiết lập thông số một lần duy nhất:
     * **Destination:** Chọn máy in tem nhiệt.
     * **Paper size:** `65 x 30 mm`.
     * **Margins (Lề trang):** Chọn `None` (Không lề).
     * **Scale (Tỷ lệ):** `Default` hoặc `100%`.
     * **Options:** Bỏ tích chọn *Headers and footers* (Tiêu đề đầu trang và chân trang).

---

## 3. PHÂN QUYỀN NGƯỜI DÙNG (RBAC)

Hệ thống áp dụng cơ chế kiểm soát truy cập theo vai trò (**Role-Based Access Control**) gồm 3 cấp độ rõ ràng:

| Vai trò (Role) | Tài khoản mẫu | Mật khẩu | Mã nhân viên (MSNV) | Phạm vi quyền hạn chi tiết |
| :--- | :--- | :--- | :--- | :--- |
| **Viewer** *(Nhân viên In tem)* | `viewer` | `viewer123` | `NV-002` | • Xem danh sách Chỉ thị sản xuất (CTSX) và Lịch sử in tem.<br>• Mở bảng trượt **Offcanvas** để nhập số lượng và thực hiện **In tem thùng**.<br>• Đổi loại thùng khi in, in lại tem cũ và Xuất báo cáo Excel/CSV. |
| **Editor** *(Tổ trưởng / Quản lý SX)* | `editor` | `editor123` | `NV-001` | • Bao gồm toàn bộ quyền của **Viewer**.<br>• **Quản lý Chỉ thị:** Thêm mới, chỉnh sửa, tạm dừng (`⏸️ Dừng`), mở khóa (`▶️ Mở`), xóa chỉ thị và **Import CSV** chỉ thị.<br>• **Quản lý Quy cách (`specs.php`):** Thêm, sửa, xóa, Import/Export quy cách đóng gói.<br>• **Phê duyệt vượt định mức:** Sử dụng MSNV và mật khẩu để duyệt lệnh in vượt sản lượng kế hoạch. |
| **Admin** *(Quản trị hệ thống)* | `admin` | `admin123` | `ADM-001` | • **Toàn quyền hệ thống** (bao gồm mọi quyền của Editor).<br>• **Cấu hình Mã QR (`settings.php`):** Thiết lập tiền tố, ký tự phân cách, hậu tố, ký tự cố định và mã quy ước Năm/Tháng.<br>• **Quản lý Người dùng (`users.php`):** Thêm mới tài khoản, phân quyền, đổi mật khẩu, khóa/mở khóa hoặc xóa nhân viên. |

> **Lưu ý giao diện:** Nút **🚪 Thoát** màu đỏ nổi bật luôn hiển thị ở góc trên bên phải thanh điều hướng cùng với Huy hiệu vai trò (`ADMIN`, `EDITOR`, `VIEWER`) giúp công nhân dễ dàng nhận biết tài khoản đang vận hành và đăng xuất an toàn khi giao ca.

---

## 4. QUY TRÌNH THAO TÁC NGHIỆP VỤ CHI TIẾT

### Bước 1: Thiết lập Danh mục Quy cách Đóng gói (`specs.php`)
> **Nguyên tắc bắt buộc:** Mọi mã sản phẩm phải được khai báo quy cách đóng gói trong mục **📦 Quy Cách (Specs)** trước khi tạo hoặc Import chỉ thị sản xuất.

1. Truy cập menu **📦 Quy Cách (Specs)** (dành cho `Editor` hoặc `Admin`).
2. Hệ thống quản lý quy cách theo cặp khóa duy nhất: **`Mã sản phẩm (Product Code)` + `Loại thùng (Box Type)`**:
   * **Loại thùng 1 (`box_type = 1`):** Quy cách thùng tiêu chuẩn (ví dụ: `50 con/thùng`).
   * **Loại thùng 2 (`box_type = 2`):** Quy cách thùng dự phòng hoặc thùng xuất khẩu (ví dụ: `100 con/thùng`).
3. **Cách thêm quy cách:**
   * **Thủ công:** Điền Mã sản phẩm, Loại thùng (`1` hoặc `2`), Quy cách (con/thùng), Nhà cung cấp, Trọng lượng (Kg) vào khung bên trái rồi nhấn **💾 Lưu Quy Cách**.
   * **Import hàng loạt:** Nhấn nút **📂 Import CSV**, tải tệp mẫu `sample_specs.csv`, điền danh sách quy cách và tải lên hệ thống. Nếu trùng `Mã SP + Loại thùng`, hệ thống sẽ tự động cập nhật thông số mới nhất.

---

### Bước 2: Tạo mới hoặc Import Chỉ thị Sản xuất (`orders.php`)
1. Truy cập menu **📋 Chỉ Thị SX (CTSX)**.
2. **Tạo chỉ thị thủ công:**
   * Nhấn nút **➕ Thêm Chỉ Thị Mới**.
   * Nhập *Mã phiếu chỉ thị*, *Mã chỉ thị (Lot No)*, chọn *Mã sản phẩm* và *Loại thùng*.
   * Hệ thống tự động tra cứu và điền sẵn *Quy cách (con/thùng)*, *Nhà cung cấp* và *Trọng lượng* từ danh mục Quy cách.
   * Nhập *Số lượng chỉ thị (Target Qty)* và nhấn **Lưu Chỉ Thị**.
3. **Import danh sách chỉ thị từ CSV:**
   * Nhấn nút **📂 Import CSV** $\rightarrow$ Tải tệp mẫu `sample_orders.csv` (Cấu trúc 7 cột: `Mã phiếu, Mã chỉ thị, Mã sản phẩm, Loại thùng, Tháng phát hành, Số lượng chỉ thị, Ghi chú`).
   * Khi tải lên, hệ thống sẽ đối chiếu từng dòng với bảng `product_specs`:
     * Các dòng có sẵn quy cách sẽ được nạp tự động.
     * Các dòng **chưa có quy cách** sẽ bị từ chối an toàn và hiển thị bảng tổng hợp chi tiết các mã sản phẩm bị bỏ qua để người dùng bổ sung.
4. **Kiểm soát trạng thái chỉ thị:**
   * Nhấn nút **⏸️ Dừng** để tạm khóa chỉ thị (ngăn công nhân in nhầm). Nhấn **▶️ Mở** để cho phép in trở lại.

---

### Bước 3: Thao tác Mở Offcanvas & In Tem Thùng (`orders.php`)
1. Trên dòng chỉ thị cần in, nhấn nút **🏷️ In** (hoặc nhấn nút **🏷️ Quét / In Tem Thùng** ở góc trên và quét mã vạch phiếu chỉ thị).
2. Thanh trượt **Offcanvas In Tem** sẽ mở ra ở cạnh phải màn hình:
   * **Chọn Loại thùng linh hoạt:** Công nhân có thể chuyển đổi giữa `Loại thùng 1` và `Loại thùng 2` ngay trong bảng trượt. Hệ thống lập tức tra cứu lại số con/thùng tương ứng và tính toán lại số lượng tem.
   * **Nhập Số lượng sản phẩm hoàn thành cần đóng thùng:**
     * Ví dụ: Nhập `120 con` với quy cách `50 con/thùng`.
     * Hệ thống tự động tính: **02 thùng chẵn** (50 con/thùng) + **01 thùng lẻ** (20 con). Tổng cộng = **03 tem thùng**.
3. **Xử lý Cảnh báo Thùng lẻ (Odd Box Protection):**
   * Khi số lượng nhập vào không chia hết cho quy cách đóng gói (có số dư lẻ `> 0`), hộp cảnh báo màu vàng cam sẽ xuất hiện và nút In tạm thời bị khóa.
   * Công nhân phải kiểm tra thực tế và tích chọn ô ***"Tôi xác nhận cho phép in thêm 01 tem thùng lẻ"*** thì nút **🖨️ Xác Nhận & In Tem** mới được kích hoạt.
4. **Xử lý Duyệt Vượt Định Mức (Over-Target Authorization):**
   * Nếu tổng số lượng lũy kế sau lần in này vượt quá *Số lượng chỉ thị*, hệ thống sẽ bật hộp thoại yêu cầu xác thực cấp quản lý.
   * Tổ trưởng (`Editor`) hoặc Quản trị viên (`Admin`) nhập **Mã số nhân viên (MSNV)** và **Mật khẩu** xác nhận.
   * Khi xác thực hợp lệ, hệ thống tự động cấp dãy mã thùng duy nhất (`TU-YYMMDD-XXX`), lưu vết người duyệt vào lịch sử và mở ngay cửa sổ in tem nhiệt 65×30mm.

---

### Bước 4: Cấu hình Chuỗi Mã QR Động (`settings.php`)
Dành riêng cho tài khoản **Admin** tại menu **⚙️ Cấu Hình Mã QR**:

1. **Thiết lập khung chuỗi QR:**
   * **Tiền tố (Prefix):** Chuỗi mở đầu mã QR (ví dụ: `SMC002$` hoặc `SMC4$`).
   * **Ký tự phân cách (Delimiter):** Ký tự ngăn cách giữa các trường dữ liệu (ví dụ: `$` hoặc `|`).
   * **Hậu tố (Suffix):** Ký tự kết thúc chuỗi (nếu có).
   * **Định dạng ngày:** Chọn kiểu hiển thị ngày sản xuất (`DD/MM/YYYY`, `YYYY-MM-DD`, `YYYYMMDD`...).
2. **Sắp xếp Thứ tự các Trường dữ liệu trong chuỗi QR:**
   * Nhấn **➕ Thêm Vị Trí** hoặc **❌** để thêm/xóa vị trí trường dữ liệu.
   * Danh mục trường hỗ trợ đầy đủ:
     * Các trường động theo đơn hàng: `Mã sản phẩm`, `Quy cách con/thùng`, `Mã thùng (Box No)`, `Mã chỉ thị (Lot No)`, `Ngày sản xuất`, `Nhà cung cấp`, `Trọng lượng`, `Tháng phát hành`, `Mã phiếu chỉ thị`...
     * **`🔤 Ký tự cố định (Custom Fixed Text)`:** Khi chọn mục này, một ô nhập văn bản phụ sẽ xuất hiện ngay bên cạnh để bạn gõ chuỗi ký tự tĩnh bất kỳ (ví dụ: `YD`, `FACTORY1`...).
     * **`📅 Ký tự quy ước Năm/Tháng (Year/Month Code)`:** Tự động chuyển đổi thời gian thực hoặc tháng phát hành sang ký tự quy ước nội bộ của nhà máy (ví dụ: Năm 2026 $\rightarrow$ `Y`, Tháng 10 $\rightarrow$ `D`, Tháng 12 $\rightarrow$ `A` $\Rightarrow$ ghép thành `YD` hoặc `YA`).
     * **`Ô trống phân cách (Empty Slot)`:** Tạo khoảng trống giữa hai dấu phân cách (`$$`).
3. **Xem trước trực quan (Live Preview):**
   * Mọi thay đổi đều được tái tạo ngay lập tức ở khung **Xem Trước Chuỗi QR & Tem Nhãn (65mm × 30mm)** bên phải màn hình trước khi nhấn **💾 Lưu Cấu Hình Mã QR**.

---

### Bước 5: Tra cứu Lịch sử In & In lại Tem (`history.php`)
1. Truy cập menu **📜 Lịch Sử In**.
2. Sử dụng **Bộ lọc tìm kiếm ngang** (Từ ngày, Đến ngày, Mã phiếu, Mã chỉ thị, Mã sản phẩm, Người thực hiện, Tình trạng định mức) và nhấn **🔍 Lọc Dữ Liệu**.
3. Nhấn **📊 Xuất Excel** ngay trên thanh công cụ lọc để tải về báo cáo `.csv` (UTF-8 kèm BOM hiển thị chuẩn tiếng Việt trên Microsoft Excel).
4. Nếu tem dán trên thùng bị rách hoặc mờ, nhấn nút **🖨️ In Lại** tại dòng lịch sử tương ứng để in lại đúng bộ mã thùng (`Box No`) đã cấp mà không làm tăng số lượng lũy kế của chỉ thị.

---

## 5. XỬ LÝ SỰ CỐ THƯỜNG GẶP (TROUBLESHOOTING)

### 5.1. Sự cố khi Import file CSV (Chỉ thị hoặc Quy cách)
* **Hiện tượng 1: Thông báo *"Bỏ qua X dòng do sản phẩm chưa được thiết lập quy cách đóng gói"* khi Import chỉ thị.**
  * *Nguyên nhân:* Mã sản phẩm và Loại thùng trong file CSV chỉ thị chưa tồn tại trong danh mục **📦 Quy Cách (Specs)**.
  * *Cách khắc phục:* Ghi nhận danh sách mã sản phẩm báo thiếu trên thông báo, sang mục **📦 Quy Cách (Specs)** để thêm mới hoặc Import quy cách của các mã đó trước, sau đó Import lại file chỉ thị.
* **Hiện tượng 2: File CSV mở bằng Excel bị lỗi phông chữ tiếng Việt.**
  * *Nguyên nhân:* Lưu file CSV từ phần mềm cũ dưới chuẩn ANSI thay vì UTF-8.
  * *Cách khắc phục:* Khi lưu file từ Excel, chọn định dạng **CSV UTF-8 (Comma delimited) (*.csv)**, hoặc sử dụng trực tiếp nút **Tải file mẫu CSV** có sẵn trên hệ thống (đã tích hợp sẵn mã BOM UTF-8).

### 5.2. Sự cố khi Quét mã QR trên Điện thoại hoặc Máy quét Barcode 2D
* **Hiện tượng 1: Điện thoại hoặc máy quét không đọc được mã QR hoặc quét chậm.**
  * *Nguyên nhân:* Chuỗi QR cấu hình quá nhiều trường thừa hoặc đầu in nhiệt bị bẩn/thiếu nhiệt độ (Darkness/Density thấp) làm các điểm ảnh QR bị đứt nét.
  * *Cách khắc phục:*
    1. Vệ sinh đầu in nhiệt bằng cồn chuyên dụng và tăng thông số **Darkness (Độ đậm)** trong Driver máy in lên mức `10 - 14`.
    2. Hệ thống đã tích hợp sẵn chuẩn **ISO ECI UTF-8** và vùng trắng bảo vệ (`Quiet Zone Margin = 4`), đảm bảo tương thích 100% với camera iOS/Android, Zalo và súng quét 2D công nghiệp (Zebra, Honeywell, Datalogic).
* **Hiện tượng 2: Chuỗi kết quả quét ra bị lệch cột dữ liệu trên phần mềm ERP/WMS.**
  * *Nguyên nhân:* Thứ tự các trường hoặc số lượng *Ô trống phân cách (`empty`)* trong mục **⚙️ Cấu Hình Mã QR** chưa khớp với cấu trúc cột của máy đọc.
  * *Cách khắc phục:* Đăng nhập tài khoản `Admin` $\rightarrow$ Vào **⚙️ Cấu Hình Mã QR** $\rightarrow$ Đối chiếu chuỗi văn bản ở mục *Chuỗi QR Code kết quả* hoặc nhấn nút **🔄 Khôi Phục Mặc Định** để đưa về chuẩn 15 trường mặc định của nhà máy.

### 5.3. Sự cố khi In tem ra máy in nhiệt bị đẩy sang trang trắng thứ 2
* **Nguyên nhân:** Khổ giấy trong trình duyệt đang để mặc định là `A4` hoặc `Letter`, hoặc đang bật `Headers and footers`.
* **Cách khắc phục:** Trong cửa sổ Print Preview của trình duyệt, chọn **More settings (Cài đặt khác)** $\rightarrow$ Đổi **Paper size** về đúng khổ **`65 x 30 mm`**, đặt **Margins** là **`None`** và bỏ chọn **Headers and footers**.
