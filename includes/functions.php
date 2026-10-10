<?php
/**
 * CÁC HÀM TIỆN ÍCH VÀ NGHIỆP VỤ CỐT LÕI (PRINTBOX)
 * Hỗ trợ động hóa cấu trúc mã QR, ánh xạ quy cách đóng gói và cấp số Box No
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../libs/phpqrcode/qrlib.php';

/**
 * Lấy cấu hình hệ thống & định dạng mã QR từ bảng system_settings
 * @param PDO|null $pdo
 * @return array
 */
function getSystemSettings(PDO $pdo = null, $forceRefresh = false) {
    static $cachedSettings = null;
    if (!$forceRefresh && $cachedSettings !== null) {
        return $cachedSettings;
    }

    if ($pdo === null) {
        $pdo = getDbConnection();
    }

    $defaults = [
        'qr_prefix'      => 'SMC4$',
        'qr_delimiter'   => '$',
        'qr_suffix'      => '',
        'qr_date_format' => 'd/m/Y',
        'qr_fields'      => '["material_name","empty","qty","empty","empty","empty","empty","box_no","invoice_no","order_no","bundle_no","weight","input_date","lot_no","supplier"]',
        'label_width_mm' => '65',
        'label_height_mm'=> '30',
    ];

    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM `system_settings`");
        $rows = $stmt->fetchAll();
        foreach ($rows as $r) {
            $defaults[$r['setting_key']] = $r['setting_value'];
        }
    } catch (Exception $e) {
        // Sử dụng cấu hình mặc định nếu bảng chưa nạp
    }

    $cachedSettings = $defaults;
    return $cachedSettings;
}

/**
 * Cập nhật cấu hình hệ thống
 */
function updateSystemSetting($key, $value, PDO $pdo = null) {
    if ($pdo === null) $pdo = getDbConnection();
    $stmt = $pdo->prepare("INSERT INTO `system_settings` (`setting_key`, `setting_value`) 
                           VALUES (?, ?) 
                           ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `updated_at` = NOW()");
    return $stmt->execute([$key, $value]);
}

/**
 * Tra cứu quy cách đóng gói tự động từ bảng product_specs theo Mã Sản Phẩm và Loại Thùng
 * @param string $productCode Mã sản phẩm
 * @param string $boxType Phân loại thùng (mặc định '1')
 * @param PDO|null $pdo
 * @return array
 */
function getProductSpec($productCode, $boxType = '1', PDO $pdo = null) {
    if ($pdo === null) $pdo = getDbConnection();
    $productCode = trim($productCode);
    $boxType     = trim((string)$boxType) ?: '1';

    $fallback = [
        'product_code'   => $productCode,
        'box_type'       => $boxType,
        'pack_qty'       => 1,
        'supplier'       => '',
        'weight_per_box' => 0.000,
        'unit'           => 'pcs',
        'description'    => ''
    ];

    if (empty($productCode)) {
        return $fallback;
    }

    // 1. Ưu tiên tra cứu chính xác theo cặp (product_code, box_type)
    $stmt = $pdo->prepare("SELECT * FROM `product_specs` WHERE `product_code` = ? AND `box_type` = ? LIMIT 1");
    $stmt->execute([$productCode, $boxType]);
    $spec = $stmt->fetch();

    // 2. Nếu chưa có loại thùng này, fallback sang bất kỳ cấu hình nào của sản phẩm đó
    if (!$spec) {
        $stmt = $pdo->prepare("SELECT * FROM `product_specs` WHERE `product_code` = ? ORDER BY `box_type` ASC LIMIT 1");
        $stmt->execute([$productCode]);
        $spec = $stmt->fetch();
    }

    if ($spec) {
        return [
            'product_code'   => $spec['product_code'],
            'box_type'       => $spec['box_type'] ?? $boxType,
            'pack_qty'       => max(1, (int)$spec['pack_qty']),
            'supplier'       => $spec['supplier'] ?? '',
            'weight_per_box' => (float)$spec['weight_per_box'],
            'unit'           => $spec['unit'] ?? 'pcs',
            'description'    => $spec['description'] ?? ''
        ];
    }

    return $fallback;
}

/**
 * Check if a product specification exists in product_specs table
 * @param string $productCode
 * @param string|null $boxType
 * @param PDO|null $pdo
 * @return bool
 */
function hasProductSpec($productCode, $boxType = null, PDO $pdo = null) {
    if ($pdo === null) $pdo = getDbConnection();
    $productCode = trim($productCode);
    if (empty($productCode)) {
        return false;
    }

    if ($boxType !== null && $boxType !== '') {
        $stmt = $pdo->prepare("SELECT id FROM `product_specs` WHERE `product_code` = ? AND `box_type` = ? LIMIT 1");
        $stmt->execute([$productCode, trim((string)$boxType)]);
        return (bool)$stmt->fetchColumn();
    }

    $stmt = $pdo->prepare("SELECT id FROM `product_specs` WHERE `product_code` = ? LIMIT 1");
    $stmt->execute([$productCode]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Get product spec if exists, otherwise returns null
 * @param string $productCode
 * @param string|null $boxType
 * @param PDO|null $pdo
 * @return array|null
 */
function getProductSpecStrict($productCode, $boxType = null, PDO $pdo = null) {
    if ($pdo === null) $pdo = getDbConnection();
    $productCode = trim($productCode);
    if (empty($productCode)) {
        return null;
    }

    $spec = null;
    if ($boxType !== null && $boxType !== '') {
        $stmt = $pdo->prepare("SELECT * FROM `product_specs` WHERE `product_code` = ? AND `box_type` = ? LIMIT 1");
        $stmt->execute([$productCode, trim((string)$boxType)]);
        $spec = $stmt->fetch();
    }

    if (!$spec) {
        $stmt = $pdo->prepare("SELECT * FROM `product_specs` WHERE `product_code` = ? ORDER BY `box_type` ASC LIMIT 1");
        $stmt->execute([$productCode]);
        $spec = $stmt->fetch();
    }

    if ($spec) {
        return [
            'product_code'   => $spec['product_code'],
            'box_type'       => $spec['box_type'] ?? '1',
            'pack_qty'       => max(1, (int)$spec['pack_qty']),
            'supplier'       => $spec['supplier'] ?? '',
            'weight_per_box' => (float)$spec['weight_per_box'],
            'unit'           => $spec['unit'] ?? 'pcs',
            'description'    => $spec['description'] ?? ''
        ];
    }
    return null;
}

/**
 * Tạo danh sách Box No tuần tự theo ngày: TU-YYMMDD-XXX
 * Sử dụng transaction & FOR UPDATE bảo đảm an toàn đa tiến trình trong mạng nội bộ
 * @param int $count Số lượng tem thùng cần cấp mã
 * @param PDO $pdo Kết nối CSDL
 * @return array Danh sách mã thùng [TU-261008-001, TU-261008-002,...]
 */
function generateDailyBoxNumbers($count, $pdo) {
    if ($count <= 0) {
        return [];
    }

    $todayDate = date('Y-m-d');
    $datePrefix = date('ymd'); // YYMMDD: ví dụ 261008

    // Đảm bảo có dòng cho ngày hôm nay
    $stmtInit = $pdo->prepare("INSERT INTO `daily_box_seq` (`seq_date`, `current_seq`) 
                               VALUES (?, 0) 
                               ON DUPLICATE KEY UPDATE `seq_date` = `seq_date`");
    $stmtInit->execute([$todayDate]);

    // Khóa dòng để lấy số thứ tự hiện tại an toàn tuyệt đối
    $stmtLock = $pdo->prepare("SELECT `current_seq` FROM `daily_box_seq` WHERE `seq_date` = ? FOR UPDATE");
    $stmtLock->execute([$todayDate]);
    $row = $stmtLock->fetch();
    $currentSeq = $row ? (int)$row['current_seq'] : 0;

    $startSeq = $currentSeq + 1;
    $newCurrentSeq = $currentSeq + $count;

    // Cập nhật số thứ tự mới
    $stmtUpdate = $pdo->prepare("UPDATE `daily_box_seq` SET `current_seq` = ? WHERE `seq_date` = ?");
    $stmtUpdate->execute([$newCurrentSeq, $todayDate]);

    // Tạo danh sách Box No
    $boxNumbers = [];
    for ($i = 0; $i < $count; $i++) {
        $seq = $startSeq + $i;
        $boxNumbers[] = sprintf("TU-%s-%03d", $datePrefix, $seq);
    }

    return $boxNumbers;
}

/**
 * Chuyển đổi mã năm theo quy ước nhà máy công nghiệp (ví dụ: năm 2026 -> 'Y')
 * @param int|string|null $year
 * @return string
 */
function getFactoryYearCode($year = null) {
    $year = $year ? (int)$year : (int)date('Y');
    // Bảng quy ước chữ cái đại diện cho năm công nghiệp
    $yearMap = [
        2020 => 'S', 2021 => 'T', 2022 => 'U', 2023 => 'V', 2024 => 'W', 2025 => 'X',
        2026 => 'Y', 2027 => 'Z', 2028 => 'A', 2029 => 'B', 2030 => 'C'
    ];
    if (isset($yearMap[$year])) {
        return $yearMap[$year];
    }
    $offset = ($year - 2002) % 26;
    if ($offset < 0) $offset += 26;
    return chr(65 + $offset);
}

/**
 * Chuyển đổi mã tháng theo quy ước nhà máy công nghiệp (ví dụ: tháng 10 -> 'D', tháng 12 -> 'A')
 * @param int|string|null $month
 * @return string
 */
function getFactoryMonthCode($month = null) {
    $month = $month ? (int)$month : (int)date('n');
    $monthMap = [
        1  => '1',
        2  => '2',
        3  => '3',
        4  => '4',
        5  => '5',
        6  => '6',
        7  => '7',
        8  => '8',
        9  => '9',
        10 => 'D', // Quy ước tháng 10 -> 'D'
        11 => 'B',
        12 => 'A'  // Quy ước tháng 12 -> 'A'
    ];
    return $monthMap[$month] ?? (string)$month;
}

/**
 * Chuyển đổi mã ngày theo quy ước nhà máy công nghiệp (ví dụ: ngày 10 là chữ 'f')
 * @param int|string|null $day
 * @return string
 */
function getFactoryDayCode($day = null) {
    $day = $day ? (int)$day : (int)date('j');
    $dayMap = [
        1  => '1', 2  => '2', 3  => '3', 4  => '4', 5  => '5', 
        6  => '6', 7  => '7', 8  => '8', 9  => '9',
        10 => 'f', // Quy ước ngày 10 -> 'f'
        11 => 'g', 12 => 'h', 13 => 'i', 14 => 'j', 15 => 'k',
        16 => 'l', 17 => 'm', 18 => 'n', 19 => 'p', 20 => 'q',
        21 => 'r', 22 => 's', 23 => 't', 24 => 'u', 25 => 'v',
        26 => 'w', 27 => 'x', 28 => 'y', 29 => 'z', 30 => 'A',
        31 => 'B'
    ];
    return $dayMap[$day] ?? sprintf('%02d', $day);
}

/**
 * Lấy ký tự quy ước Năm + Tháng hiện tại (ví dụ: 10/2026 -> 'YD', 12/2026 -> 'YA')
 * @param int|string|null $year
 * @param int|string|null $month
 * @return string
 */
function getFactoryYearMonthCode($year = null, $month = null) {
    return getFactoryYearCode($year) . getFactoryMonthCode($month);
}

/**
 * Tạo chuỗi nội dung mã QR động theo cấu hình hệ thống (Admin Settings)
 * Hỗ trợ tiền tố, hậu tố, ký tự phân cách, thứ tự trường dữ liệu, ký tự cố định và quy ước thời gian
 * @param array $data Dữ liệu tem
 * @param PDO|null $pdo
 * @return string
 */
function buildBoxQrContent(array $data, PDO $pdo = null) {
    $settings = getSystemSettings($pdo);

    $prefix    = $settings['qr_prefix'] ?? 'SMC4$';
    $delimiter = $settings['qr_delimiter'] ?? '$';
    $suffix    = $settings['qr_suffix'] ?? '';
    $dateFormat= $settings['qr_date_format'] ?? 'd/m/Y';
    $fieldsJson= $settings['qr_fields'] ?? '[]';

    $fieldsOrder = json_decode($fieldsJson, true);
    if (!is_array($fieldsOrder) || empty($fieldsOrder)) {
        // Fallback định dạng chuẩn SMC4 nếu cấu hình trống
        $fieldsOrder = [
            'material_name', 'empty', 'qty', 'empty', 'empty', 'empty', 'empty',
            'box_no', 'invoice_no', 'order_no', 'bundle_no', 'weight',
            'input_date', 'lot_no', 'supplier'
        ];
    }

    // Chuẩn bị ngày hiển thị
    $rawDate = $data['input_date'] ?? date('Y-m-d');
    $timestamp = strtotime($rawDate) ?: time();
    $formattedDate = date($dateFormat, $timestamp);

    // Xác định năm và tháng phục vụ quy ước mã ký tự động
    $targetYear = (int)date('Y');
    $targetMonth = (int)date('n');
    if (!empty($data['issue_month']) && preg_match('#^(\d{1,2})/(\d{4})$#', trim($data['issue_month']), $m)) {
        $targetMonth = (int)$m[1];
        $targetYear  = (int)$m[2];
    } elseif (!empty($data['input_date'])) {
        $ts = strtotime($data['input_date']);
        if ($ts) {
            $targetYear  = (int)date('Y', $ts);
            $targetMonth = (int)date('n', $ts);
        }
    }

    // Bảng giá trị các trường
    $valuesMap = [
        'material_name' => trim($data['material_name'] ?? $data['product_code'] ?? ''),
        'product_code'  => trim($data['product_code'] ?? $data['material_name'] ?? ''),
        'qty'           => trim((string)($data['qty'] ?? '')),
        'box_no'        => trim($data['box_no'] ?? ''),
        'invoice_no'    => trim($data['invoice_no'] ?? ''),
        'order_no'      => trim($data['order_no'] ?? ''),
        'bundle_no'     => trim($data['bundle_no'] ?? ''),
        'weight'        => trim($data['weight'] ?? ''),
        'input_date'    => $formattedDate,
        'lot_no'        => trim($data['lot_no'] ?? $data['order_code'] ?? ''),
        'order_code'    => trim($data['order_code'] ?? $data['lot_no'] ?? ''),
        'supplier'      => trim($data['supplier'] ?? ''),
        'issue_month'   => trim($data['issue_month'] ?? ''),
        'slip_code'     => trim($data['slip_code'] ?? ''),
        'date_code_ym'  => getFactoryYearMonthCode($targetYear, $targetMonth),
        'date_code_y'   => getFactoryYearCode($targetYear),
        'date_code_m'   => getFactoryMonthCode($targetMonth),
        'empty'         => '',
        ''              => ''
    ];

    $parts = [];
    foreach ($fieldsOrder as $fieldItem) {
        $val = '';
        if (is_array($fieldItem)) {
            $key = trim($fieldItem['key'] ?? $fieldItem['type'] ?? '');
            $customVal = trim($fieldItem['value'] ?? $fieldItem['custom'] ?? '');
            if ($key === 'custom_fixed' || $key === 'fixed') {
                $val = $customVal;
            } else {
                $val = $valuesMap[$key] ?? '';
            }
        } else {
            $itemStr = trim((string)$fieldItem);
            if (str_starts_with($itemStr, 'custom_fixed:')) {
                $val = substr($itemStr, 13);
            } elseif (str_starts_with($itemStr, 'fixed:')) {
                $val = substr($itemStr, 6);
            } else {
                $val = $valuesMap[$itemStr] ?? '';
            }
        }

        // Loại bỏ ký tự xuống dòng và tab làm hỏng cấu trúc dữ liệu QR
        $val = str_replace(["\r", "\n", "\t"], ' ', $val);
        // Nếu trường chứa ký tự phân cách, thay bằng gạch ngang để tránh vỡ số cột
        if ($delimiter !== '' && str_contains($val, $delimiter)) {
            $val = str_replace($delimiter, '-', $val);
        }
        $parts[] = trim($val);
    }

    $body = implode($delimiter, $parts);
    return $prefix . $body . $suffix;
}

/**
 * Sinh mã QR dạng Base64 Data URI (offline)
 * @param string $text
 * @param int $pixelSize
 * @return string
 */
function generateQrDataUri($text, $pixelSize = 6, $margin = 4) {
    return QRcode::base64($text, QRcode::QR_ECLEVEL_M, $pixelSize, $margin);
}

/**
 * Tính toán số lượng thùng và kiểm tra thùng lẻ
 * @param int $printQty Số lượng tem SP đã in
 * @param int $packQty Quy cách con/thùng
 * @return array
 */
function calculateBoxPackaging($printQty, $packQty) {
    if ($packQty <= 0) {
        $packQty = 1;
    }
    $printQty = max(0, (int)$printQty);

    $fullBoxes = (int)floor($printQty / $packQty);
    $oddQty = $printQty % $packQty;
    $hasOdd = ($oddQty > 0);

    $totalBoxes = $fullBoxes + ($hasOdd ? 1 : 0);

    return [
        'full_boxes'  => $fullBoxes,
        'has_odd'     => $hasOdd,
        'odd_qty'     => $oddQty,
        'total_boxes' => $totalBoxes,
        'pack_qty'    => $packQty,
        'print_qty'   => $printQty
    ];
}

/**
 * Xác thực chi tiết quyền Quản lý / Admin / Editor duyệt vượt định mức chỉ thị
 * Truy vấn chính xác nhân viên theo MSNV (hoặc Username), kiểm tra mật khẩu bằng password_verify và plain-text
 * Trả về chi tiết kết quả xác thực kèm thông báo lỗi cụ thể
 * 
 * @param string $msnv Mã số nhân viên (vd: ADM-001, NV-001) hoặc Tên đăng nhập
 * @param string $password Mật khẩu xác thực tài khoản
 * @param PDO $pdo Kết nối CSDL
 * @return array ['success' => bool, 'message' => string, 'user' => array|null]
 */
function verifyManagerOverrideDetailed($msnv, $password, $pdo) {
    $msnv = trim((string)$msnv);
    $password = trim((string)$password);
    
    if ($msnv === '') {
        return [
            'success' => false,
            'message' => 'Vui lòng nhập Mã số nhân viên (MSNV) của người duyệt!'
        ];
    }
    
    if ($password === '') {
        return [
            'success' => false,
            'message' => 'Vui lòng nhập Mật khẩu/PIN xác thực của người duyệt!'
        ];
    }

    // Truy vấn tìm chính xác nhân viên dựa trên MSNV (hoặc Username dự phòng)
    $stmt = $pdo->prepare("SELECT id, username, password, full_name, employee_code, role, status 
                           FROM `users` 
                           WHERE LOWER(TRIM(`employee_code`)) = LOWER(?) OR LOWER(TRIM(`username`)) = LOWER(?)
                           LIMIT 1");
    $stmt->execute([$msnv, $msnv]);
    $user = $stmt->fetch();

    if (!$user) {
        return [
            'success' => false,
            'message' => "Không tìm thấy nhân viên có Mã số (MSNV) '{$msnv}' trong hệ thống!"
        ];
    }

    // Kiểm tra trạng thái kích hoạt tài khoản
    if ((int)$user['status'] !== 1) {
        return [
            'success' => false,
            'message' => "Tài khoản của nhân viên '{$user['full_name']}' ({$user['employee_code']}) hiện đang bị KHÓA!"
        ];
    }

    // Kiểm tra thẩm quyền: Chỉ Quản lý (Admin hoặc Editor) mới được duyệt vượt mức
    $allowedRoles = ['admin', 'editor'];
    if (!in_array(strtolower($user['role']), $allowedRoles, true)) {
        return [
            'success' => false,
            'message' => "Nhân viên '{$user['full_name']}' ({$user['employee_code']}) có quyền '{$user['role']}' - Không có thẩm quyền duyệt vượt mức! Chỉ Quản lý (Admin/Editor) mới được duyệt."
        ];
    }

    // Kiểm tra mật khẩu: Hỗ trợ cả password_verify (Bcrypt/Argon2) và so khớp trực tiếp (Plain text)
    $passwordValid = false;
    if (password_verify($password, $user['password'])) {
        $passwordValid = true;
    } elseif ($user['password'] === $password) {
        $passwordValid = true;
    }

    if (!$passwordValid) {
        return [
            'success' => false,
            'message' => "Sai Mật khẩu/PIN xác thực của người duyệt '{$user['full_name']}' ({$user['employee_code']})! Vui lòng kiểm tra lại."
        ];
    }

    return [
        'success' => true,
        'message' => "Duyệt vượt định mức thành công! Người cấp quyền: {$user['full_name']} ({$user['employee_code']})",
        'user'    => $user
    ];
}

/**
 * Xác thực quyền Quản lý / Admin / Editor duyệt vượt định mức chỉ thị (Hàm tương thích cũ)
 * @param string $msnv Mã số nhân viên
 * @param string $password Mật khẩu
 * @param PDO $pdo
 * @return array|false
 */
function verifyManagerOverride($msnv, $password, $pdo) {
    $res = verifyManagerOverrideDetailed($msnv, $password, $pdo);
    return $res['success'] ? $res['user'] : false;
}

/**
 * Kiểm tra xác thực quyền Admin / Editor thông qua MSNV (kèm mật khẩu nếu có)
 * @param string $msnv
 * @param PDO $pdo
 * @param string|null $password
 * @return array|false
 */
function verifyAdminMsnv($msnv, $pdo, $password = null) {
    if ($password !== null && $password !== '') {
        return verifyManagerOverride($msnv, $password, $pdo);
    }

    $msnv = trim($msnv);
    if (empty($msnv)) {
        return false;
    }

    $stmt = $pdo->prepare("SELECT id, username, full_name, employee_code, role 
                           FROM `users` 
                           WHERE `employee_code` = ? AND `role` IN ('admin', 'editor') AND `status` = 1 
                           LIMIT 1");
    $stmt->execute([$msnv]);
    return $stmt->fetch();
}

/**
 * Định dạng ngày giờ hiển thị
 */
function formatDateTime($dt) {
    if (empty($dt)) return '-';
    $timestamp = strtotime($dt);
    return date('d/m/Y H:i:s', $timestamp);
}

function formatDate($d) {
    if (empty($d)) return '-';
    $timestamp = strtotime($d);
    return date('d/m/Y', $timestamp);
}

/**
 * Tạo thông báo Alert Flash
 */
function setFlash($type, $message) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}
