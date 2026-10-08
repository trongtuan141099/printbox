import os
import time
import qrcode
import pandas as pd
import win32api
import win32print
from reportlab.pdfgen import canvas
from reportlab.lib.units import mm
from pathlib import Path

def read_excel_data(file_path, sheet_name):
    """Đọc dữ liệu từ file Excel"""
    try:
        df = pd.read_excel(file_path, sheet_name=sheet_name)
        required_columns = ['Material Name', 'Qty (pcs)', 'Weight (Kg)', 'Box No',
                           'Invoice No', 'Order No', 'Bundle No', 'Input Date']
        missing_cols = [col for col in required_columns if col not in df.columns]
        if missing_cols:
            print(f"Lỗi: Không tìm thấy các cột: {missing_cols}")
            print(f"Các cột có sẵn: {list(df.columns)}")
            return None
        return df.dropna(subset=required_columns).to_dict('records')
    except Exception as e:
        print(f"Lỗi khi đọc file Excel: {e}")
        return None

def build_qr_content(row):
    """Xây dựng nội dung QR code"""
    material_name = str(row.get('Material Name', ''))
    supplier = str(row.get('Supplier', ''))
    lot_no = str(row.get('Lot No', ''))
    qty = str(row.get('Qty (pcs)', ''))
    box_no = str(row.get('Box No', ''))
    invoice_no = str(row.get('Invoice No', ''))
    order_no = str(row.get('Order No', ''))
    bundle_no = str(row.get('Bundle No', ''))
    weight = str(row.get('Weight (Kg)', ''))

    input_date_raw = row.get('Input Date', '')
    if pd.notna(input_date_raw):
        input_date = pd.to_datetime(input_date_raw).strftime('%Y-%m-%d')
    else:
        input_date = ''

    qr_content = f"SMC4${material_name}$${qty}$$$$${box_no}${invoice_no}${order_no}${bundle_no}${weight}${input_date}${lot_no}${supplier}"
    return qr_content

def format_label_text(row):
    """Tạo nội dung văn bản cho tem"""
    line1 = str(row.get('Material Name', ''))
    qty = str(row.get('Qty (pcs)', ''))
    weight = str(row.get('Weight (Kg)', ''))
    line2 = f"{qty} pcs | {weight} Kg"

    invoice = str(row.get('Invoice No', ''))
    order = str(row.get('Order No', ''))
    bundle = str(row.get('Bundle No', ''))
    line3 = f"{invoice} | {order} | {bundle}"    

    lot_no = str(row.get('Lot No', ''))
    supplier = str(row.get('Supplier', ''))
    line4 = f"{lot_no} | {supplier}"

    line5 = str(row.get('Box No', ''))

    return line1, line2, line3, line4, line5

def generate_location_labels_pdf(rows_data, output_pdf):
    """Tạo file PDF chứa các tem hộp (65mm x 30mm)"""
    PAGE_WIDTH = 65 * mm
    PAGE_HEIGHT = 30 * mm

    c = canvas.Canvas(output_pdf, pagesize=(PAGE_WIDTH, PAGE_HEIGHT))

    for idx, row in enumerate(rows_data):
        # 1. Tạo QR code từ nội dung được xây dựng
        qr_content = build_qr_content(row)
        qr = qrcode.QRCode(version=2, box_size=8, border=1)
        qr.add_data(qr_content)
        qr.make(fit=True)

        # Lưu QR ra file ảnh tạm
        temp_qr = f"temp_qr_{idx}.png"
        qr_img = qr.make_image(fill_color="black", back_color="white")
        qr_img.save(temp_qr)

        # 2. Vẽ QR code (22x22mm) bên trái tem
        c.drawImage(temp_qr, 1.5*mm, 3*mm, width=25*mm, height=25*mm)

        # 3. Vẽ thông tin bên phải (4 dòng)
        line1, line2, line3, line4, line5 = format_label_text(row)

        right_x = 45 * mm  # Căn giữa phần bên phải của tem
        start_y = 25 * mm
        line_spacing = 4 * mm

        c.setFont("Helvetica-Bold", 8)
        c.drawCentredString(right_x, start_y, line1)

        c.setFont("Helvetica", 7)
        c.drawCentredString(right_x, start_y - line_spacing, line2)
        c.drawCentredString(right_x, start_y - 2*line_spacing, line3)
        c.drawCentredString(right_x, start_y - 3*line_spacing, line4)

        c.setFont("Helvetica-Bold", 7)
        c.drawCentredString(right_x, start_y - 4*line_spacing, line5)

        # Hoàn thành 1 tem (1 trang)
        c.showPage()

        # Xóa file ảnh tạm
        os.remove(temp_qr)

    c.save()
    print(f"✓ Đã tạo file PDF: {output_pdf}")
    return output_pdf

def print_pdf_to_default_printer(pdf_path):
    """In file PDF tới máy in mặc định"""
    try:
        printer_name = win32print.GetDefaultPrinter()
        print(f"Đang gửi tem tới máy in: {printer_name}...")

        win32api.ShellExecute(
            0,
            "print",
            pdf_path,
            f'/d:"{printer_name}"',
            ".",
            0
        )
        time.sleep(3)
        print("✓ Đã gửi lệnh in thành công!")
    except Exception as e:
        print(f"Lỗi khi in: {e}")

def main():
    """Chương trình chính"""
    base_path = Path("s-wms/input")

    # 1. Hiển thị danh sách file Excel có sẵn
    excel_files = list(base_path.glob("*.xlsx")) + list(base_path.glob("*.xlsm"))

    if not excel_files:
        print("Không tìm thấy file Excel trong thư mục s-wms/input")
        return

    print("\n=== DANH SÁCH FILE EXCEL CÓ SẵN ===")
    for i, file in enumerate(excel_files, 1):
        print(f"{i}. {file.name}")

    # 2. Yêu cầu chọn file
    while True:
        try:
            choice = int(input(f"\nChọn file (1-{len(excel_files)}): ")) - 1
            if 0 <= choice < len(excel_files):
                selected_file = excel_files[choice]
                break
            print("Lựa chọn không hợp lệ!")
        except ValueError:
            print("Vui lòng nhập số!")

    # 3. Đọc danh sách sheet
    try:
        xls = pd.ExcelFile(selected_file)
        sheets = xls.sheet_names
    except Exception as e:
        print(f"Lỗi: {e}")
        return

    print(f"\n=== DANH SÁCH SHEET TRONG '{selected_file.name}' ===")
    for i, sheet in enumerate(sheets, 1):
        print(f"{i}. {sheet}")

    # 4. Yêu cầu chọn sheet
    while True:
        try:
            choice = int(input(f"\nChọn sheet (1-{len(sheets)}): ")) - 1
            if 0 <= choice < len(sheets):
                selected_sheet = sheets[choice]
                break
            print("Lựa chọn không hợp lệ!")
        except ValueError:
            print("Vui lòng nhập số!")

    # 5. Đọc dữ liệu từ các cột cần thiết
    print(f"\nĐang đọc dữ liệu từ sheet '{selected_sheet}'...")
    rows_data = read_excel_data(selected_file, selected_sheet)

    if not rows_data:
        return

    print(f"✓ Đã tìm thấy {len(rows_data)} hàng dữ liệu")

    # 6. Tạo file PDF
    output_pdf = f"s-wms/pdf/box_labels_{selected_sheet}.pdf"
    generate_location_labels_pdf(rows_data, output_pdf)

    # 7. Hỏi có in không
    print_choice = input("\nBạn có muốn in ngay không? (y/n): ").lower()
    if print_choice == 'y':
        print_pdf_to_default_printer(output_pdf)
    else:
        print(f"File PDF đã lưu: {output_pdf}")

if __name__ == "__main__":
    main()
