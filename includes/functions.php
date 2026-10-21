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
        2020 => 'y', 2021 => 'Z', 2022 => 'A', 2023 => 'B', 2024 => 'C', 2025 => 'D',
        2026 => 'E', 2027 => 'F', 2028 => 'G', 2029 => 'H', 2030 => 'I'
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
        1  => 'o',
        2  => 'P',
        3  => 'Q',
        4  => 'R',
        5  => 'S',
        6  => 'T',
        7  => 'U',
        8  => 'V',
        9  => 'W',
        10 => 'X', // Quy ước tháng 10 -> 'D'
        11 => 'y',
        12 => 'Z'  // Quy ước tháng 12 -> 'A'
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
        1  => 'A', 2  => 'B', 3  => 'C', 4  => 'D', 5  => '=E', 
        6  => 'F', 7  => 'G', 8  => 'H', 9  => 'i',
        10 => 'J', // Quy ước ngày 10 -> 'f'
        11 => 'K', 12 => 'L', 13 => 'M', 14 => 'N', 15 => 'o',
        16 => 'P', 17 => 'Q', 18 => 'R', 19 => 'S', 20 => 'T',
        21 => 'U', 22 => 'V', 23 => 'W', 24 => 'X', 25 => 'y',
        26 => 'Z', 27 => 'd', 28 => 'e', 29 => 'f', 30 => 'g',
        31 => 'h'
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
 * Optimize margin = 1 & pixelSize = 8 to maximize QR area on label while preserving scan stability
 * @param string $text
 * @param int $pixelSize
 * @param int $margin
 * @return string
 */
function generateQrDataUri($text, $pixelSize = 8, $margin = 1) {
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

/**
 * Đảm bảo bảng print_history có cột override_reason để lưu lý do in vượt định mức
 */
function ensurePrintHistorySchema(PDO $pdo = null) {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    if ($pdo === null) {
        $pdo = getDbConnection();
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `print_history` LIKE 'override_reason'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `print_history` ADD COLUMN `override_reason` TEXT DEFAULT NULL AFTER `approved_by_msnv`");
        }
    } catch (Exception $e) {
        // Ignore if already exists or lacks DDL privileges
    }
}

/**
 * Lấy danh sách các máy in được cài đặt và kết nối trên hệ thống Windows hiện tại
 * Ưu tiên sắp xếp các máy in đang kết nối trực tuyến (WorkOffline = false) và máy in tem chuyên dụng lên đầu
 * @param bool $forceRefresh Bỏ qua cache để quét lại máy in
 * @return array Danh sách máy in [['name' => string, 'default' => bool, 'port' => string, 'status' => int, 'online' => bool]]
 */
function getConnectedPrinters($forceRefresh = false) {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    if (
        !$forceRefresh &&
        isset($_SESSION['connected_printers_cache'], $_SESSION['connected_printers_ts']) &&
        (time() - (int)$_SESSION['connected_printers_ts']) < 60 &&
        is_array($_SESSION['connected_printers_cache']) &&
        !empty($_SESSION['connected_printers_cache'])
    ) {
        return $_SESSION['connected_printers_cache'];
    }

    $printers = [];

    if (stripos(PHP_OS_FAMILY, 'Windows') !== false && function_exists('shell_exec')) {
        $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -Command "Get-CimInstance Win32_Printer | Select-Object Name, Default, WorkOffline, PrinterStatus, PortName, DriverName | ConvertTo-Json -Compress" 2>nul';
        $output = @shell_exec($cmd);
        if (!empty($output)) {
            $decoded = json_decode(trim($output), true);
            if (is_array($decoded)) {
                if (isset($decoded['Name'])) {
                    $decoded = [$decoded];
                }
                $onlineList  = [];
                $offlineList = [];
                foreach ($decoded as $item) {
                    if (!is_array($item) || empty($item['Name'])) continue;
                    $pName = trim((string)$item['Name']);
                    if (strcasecmp($pName, 'Fax') === 0) continue;

                    $workOffline   = !empty($item['WorkOffline']);
                    $printerStatus = (int)($item['PrinterStatus'] ?? 3);
                    $isOnline      = (!$workOffline && $printerStatus !== 7);
                    $driverName    = trim((string)($item['DriverName'] ?? ''));
                    $isThermal     = (
                        stripos($pName, 'ZDesigner') !== false ||
                        stripos($pName, 'Zebra') !== false ||
                        stripos($pName, '65x30') !== false ||
                        stripos($pName, '80x110') !== false ||
                        stripos($pName, '110x80') !== false ||
                        stripos($driverName, 'ZDesigner') !== false
                    );

                    $entry = [
                        'name'       => $pName,
                        'default'    => !empty($item['Default']),
                        'port'       => trim((string)($item['PortName'] ?? '')),
                        'status'     => $printerStatus,
                        'online'     => $isOnline,
                        'is_thermal' => $isThermal
                    ];

                    if ($isOnline || $isThermal) {
                        $onlineList[] = $entry;
                    } else {
                        $offlineList[] = $entry;
                    }
                }
                // Prioritize thermal label printers & online printers first
                usort($onlineList, function($a, $b) {
                    if ($a['is_thermal'] !== $b['is_thermal']) {
                        return $a['is_thermal'] ? -1 : 1;
                    }
                    if ($a['online'] !== $b['online']) {
                        return $a['online'] ? -1 : 1;
                    }
                    return 0;
                });
                $printers = !empty($onlineList) ? $onlineList : $offlineList;
            }
        }
    }

    $_SESSION['connected_printers_cache'] = $printers;
    $_SESSION['connected_printers_ts']    = time();

    return $printers;
}

/**
 * Lấy máy in mặc định đã cấu hình cho hệ thống/người dùng (khớp với danh sách máy in kết nối thực tế)
 * @param PDO|null $pdo
 * @param bool $forceRefresh
 * @return string
 */
function getDefaultPrinter(PDO $pdo = null, $forceRefresh = false) {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    if ($pdo === null) {
        $pdo = getDbConnection();
    }

    $connected = getConnectedPrinters($forceRefresh);
    $connectedNames = array_column($connected, 'name');

    $settings = getSystemSettings($pdo, $forceRefresh);
    $savedUserPrinter = trim($_SESSION['default_printer'] ?? '');
    $savedSysPrinter  = trim($settings['default_printer'] ?? '');

    if (!empty($connectedNames)) {
        if ($savedUserPrinter !== '' && in_array($savedUserPrinter, $connectedNames, true)) {
            return $savedUserPrinter;
        }
        if ($savedSysPrinter !== '' && in_array($savedSysPrinter, $connectedNames, true)) {
            return $savedSysPrinter;
        }
        foreach ($connected as $p) {
            if (!empty($p['default'])) {
                return $p['name'];
            }
        }
        return $connectedNames[0];
    }

    if ($savedUserPrinter !== '') return $savedUserPrinter;
    if ($savedSysPrinter !== '') return $savedSysPrinter;
    return 'Microsoft Print to PDF';
}

/**
 * Lưu máy in mặc định cho hệ thống và người dùng, đồng thời đặt làm máy in mặc định của Windows
 * @param string $printerName
 * @param PDO|null $pdo
 * @return bool
 */
function saveDefaultPrinter($printerName, PDO $pdo = null) {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $printerName = trim((string)$printerName);
    if ($printerName === '') {
        return false;
    }

    if ($pdo === null) {
        $pdo = getDbConnection();
    }

    $_SESSION['default_printer'] = $printerName;
    updateSystemSetting('default_printer', $printerName, $pdo);

    if (stripos(PHP_OS_FAMILY, 'Windows') !== false && function_exists('shell_exec')) {
        $escapedPrinter = str_replace('"', '\"', $printerName);
        @shell_exec('rundll32 printui.dll,PrintUIEntry /y /n "' . $escapedPrinter . '" 2>nul');
        if (isset($_SESSION['connected_printers_cache']) && is_array($_SESSION['connected_printers_cache'])) {
            foreach ($_SESSION['connected_printers_cache'] as &$p) {
                $p['default'] = ($p['name'] === $printerName);
            }
            unset($p);
        }
    }

    return true;
}


/**
 * Normalize label size code: supports '65x30' and '110x80' (with aliases '80x110', '100x80', '80x100')
 * @param string $size
 * @return string '65x30' or '110x80'
 */
function normalizeLabelSize($size) {
    $size = strtolower(trim((string)$size));
    if (in_array($size, ['110x80', '80x110', '100x80', '80x100'], true)) {
        return '110x80';
    }
    return '65x30';
}

/**
 * Lấy khổ tem in mặc định ('65x30' hoặc '110x80')
 * @param PDO|null $pdo
 * @param bool $forceRefresh
 * @return string
 */
function getDefaultLabelSize(PDO $pdo = null, $forceRefresh = false) {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    if ($pdo === null) {
        $pdo = getDbConnection();
    }

    // Check user session first
    $userSize = strtolower(trim($_SESSION['default_label_size'] ?? ''));
    if (in_array($userSize, ['110x80', '80x110', '100x80', '80x100', '65x30'], true)) {
        return normalizeLabelSize($userSize);
    }

    $settings = getSystemSettings($pdo, $forceRefresh);
    $savedDefault = strtolower(trim($settings['default_label_size'] ?? ''));
    if (in_array($savedDefault, ['110x80', '80x110', '100x80', '80x100', '65x30'], true)) {
        return normalizeLabelSize($savedDefault);
    }

    $w = (int)($settings['label_width_mm'] ?? 65);
    $h = (int)($settings['label_height_mm'] ?? 30);

    if (($w >= 80 && $h >= 75) || ($w === 110 && $h === 80) || ($w === 80 && $h === 110) || ($w === 100 && $h === 80) || ($w === 80 && $h === 100)) {
        return '110x80';
    }
    return '65x30';
}

/**
 * Lưu khổ tem in mặc định ('65x30' hoặc '110x80') vào session và CSDL
 * Chuẩn tem lớn (80x100 / 100x80 / 110x80): Hướng ngang (LANDSCAPE)
 * @param string $size
 * @param PDO|null $pdo
 * @return bool
 */
function saveDefaultLabelSize($size, PDO $pdo = null) {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $size = normalizeLabelSize($size);

    if ($pdo === null) {
        $pdo = getDbConnection();
    }

    $_SESSION['default_label_size'] = $size;
    // Store Landscape dimensions (Width x Height in mm)
    $w = ($size === '110x80') ? 100 : 65;
    $h = ($size === '110x80') ? 80 : 30;

    updateSystemSetting('label_width_mm', (string)$w, $pdo);
    updateSystemSetting('label_height_mm', (string)$h, $pdo);
    updateSystemSetting('default_label_size', $size, $pdo);

    return true;
}

/**
 * Kiểm tra tính hợp lệ của toàn bộ dữ liệu trước khi gửi lệnh in
 * Bao gồm: Mã CTSX, Mã sản phẩm, QR Code, Số lượng tem, Máy in, Kích thước tem
 * @param array $params
 * @return array ['valid' => bool, 'message' => string, 'errors' => array]
 */
function validatePrintJobData(array $params) {
    $orderCode   = trim((string)($params['order_code'] ?? ''));
    $productCode = trim((string)($params['product_code'] ?? ''));
    $printerName = trim((string)($params['printer_name'] ?? ''));
    $rawSize     = strtolower(trim((string)($params['label_size'] ?? '')));
    $labels      = is_array($params['labels'] ?? null) ? $params['labels'] : [];
    $labelCount  = (int)($params['label_count'] ?? $params['box_count'] ?? count($labels));

    if ($orderCode === '') {
        $msg = 'Lỗi dữ liệu in: Thiếu Mã chỉ thị sản xuất (Mã CTSX)!';
        return ['valid' => false, 'message' => $msg, 'errors' => [$msg]];
    }
    if ($productCode === '') {
        $msg = 'Lỗi dữ liệu in: Thiếu Mã sản phẩm!';
        return ['valid' => false, 'message' => $msg, 'errors' => [$msg]];
    }
    if ($labelCount <= 0 || empty($labels)) {
        $msg = 'Lỗi dữ liệu in: Số lượng tem cần in không hợp lệ!';
        return ['valid' => false, 'message' => $msg, 'errors' => [$msg]];
    }
    if ($printerName === '') {
        $msg = 'Lỗi cấu hình in: Chưa chọn máy in tem!';
        return ['valid' => false, 'message' => $msg, 'errors' => [$msg]];
    }
    if (!in_array($rawSize, ['65x30', '110x80', '80x110', '100x80', '80x100'], true)) {
        $msg = 'Lỗi cấu hình in: Kích thước tem không hợp lệ (hỗ trợ 65x30 hoặc 80x100 / 100x80 / 110x80)!';
        return ['valid' => false, 'message' => $msg, 'errors' => [$msg]];
    }

    foreach ($labels as $idx => $lbl) {
        $qrContent = trim((string)($lbl['qr_content'] ?? ''));
        $qrBase64  = trim((string)($lbl['qr_base64'] ?? ''));
        $boxNo     = trim((string)($lbl['box_no'] ?? ''));
        if ($qrContent === '' || $qrBase64 === '' || !str_starts_with($qrBase64, 'data:image/')) {
            $msg = 'Lỗi dữ liệu in: Mã QR Code ở tem thứ ' . ($idx + 1) . ' không hợp lệ hoặc bị rỗng!';
            return ['valid' => false, 'message' => $msg, 'errors' => [$msg]];
        }
        if ($boxNo === '') {
            $msg = 'Lỗi dữ liệu in: Thiếu Mã thùng (Box No) ở tem thứ ' . ($idx + 1) . '!';
            return ['valid' => false, 'message' => $msg, 'errors' => [$msg]];
        }
    }

    return ['valid' => true, 'message' => 'Dữ liệu hợp lệ', 'errors' => []];
}

/**
 * Tự động gửi lệnh in trực tiếp đến máy in Windows đã chọn thông qua Windows Print Spooler (.NET PrintDocument)
 * Không mở hộp thoại Print thủ công, đảm bảo 1 tem = 1 trang, 10 tem = 10 trang, không phát sinh trang trắng.
 * Hỗ trợ chuẩn tem 65x30mm (Landscape) và 80x100mm / 100x80mm / 110x80mm (Landscape).
 *
 * @param array $labels Danh sách dữ liệu tem
 * @param string $printerName Tên máy in đích
 * @param string $labelSize Kích thước tem ('65x30' hoặc '110x80' / '80x100' / '100x80')
 * @return array ['success' => bool, 'message' => string, 'pages_printed' => int]
 */
function dispatchWindowsDirectPrint(array $labels, $printerName, $labelSize = '65x30') {
    $printerName = trim((string)$printerName);
    $labelSize   = normalizeLabelSize($labelSize);

    // Auto-detect large label printer if printer name explicitly indicates 80x110 / 110x80 / 80x100 / 100x80
    if (preg_match('/(80\s*x\s*110|110\s*x\s*80|80\s*x\s*100|100\s*x\s*80)/i', $printerName)) {
        $labelSize = '110x80';
    } elseif (preg_match('/65\s*x\s*30/i', $printerName)) {
        $labelSize = '65x30';
    }

    // Ensure every label has a valid PNG Base64 QR code before sending to Windows Print Spooler
    foreach ($labels as &$lbl) {
        $b64 = trim((string)($lbl['qr_base64'] ?? ''));
        $qrTxt = trim((string)($lbl['qr_content'] ?? ''));
        if ($qrTxt !== '' && ($b64 === '' || !preg_match('/^data:image\/png;base64,/i', $b64))) {
            $lbl['qr_base64'] = generateQrDataUri($qrTxt, 8, 1);
        }
    }
    unset($lbl);

    if (empty($labels) || $printerName === '') {
        return [
            'success'       => false,
            'message'       => 'Thiếu dữ liệu tem hoặc chưa chọn máy in.',
            'pages_printed' => 0
        ];
    }

    if (stripos(PHP_OS_FAMILY, 'Windows') === false || !function_exists('shell_exec')) {
        return [
            'success'       => false,
            'message'       => 'Môi trường máy chủ không hỗ trợ Windows Print Spooler trực tiếp.',
            'pages_printed' => 0
        ];
    }

    $tempDir = __DIR__ . '/../logs/spool';
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0777, true);
    }

    $jobId    = uniqid('printjob_', true);
    $jsonFile = $tempDir . '/' . $jobId . '.json';
    $ps1File  = $tempDir . '/direct_print_worker.ps1';

    $payload = [
        'printer_name' => $printerName,
        'label_size'   => $labelSize,
        'pdf_fallback' => $tempDir . '/last_printed_labels.pdf',
        'labels'       => array_values($labels)
    ];

    if (@file_put_contents($jsonFile, json_encode($payload, JSON_UNESCAPED_UNICODE)) === false) {
        return [
            'success'       => false,
            'message'       => 'Không thể khởi tạo tệp dữ liệu lệnh in tạm thời.',
            'pages_printed' => 0
        ];
    }

    // Create or update the reusable PowerShell direct print worker script
    $psScriptContent = <<<'POWERSHELL'
param(
    [Parameter(Mandatory=$true)]
    [string]$JobJsonPath
)

$ErrorActionPreference = "Stop"

try {
    if (-not (Test-Path -LiteralPath $JobJsonPath)) {
        Write-Output '{"success":false,"message":"Job JSON file not found"}'
        exit 1
    }

    $jsonRaw = [System.IO.File]::ReadAllText($JobJsonPath, [System.Text.Encoding]::UTF8)
    $job = $jsonRaw | ConvertFrom-Json

    $printerName = [string]$job.printer_name
    $labelSize   = [string]$job.label_size
    $labels      = @($job.labels)

    if ($labels.Count -eq 0) {
        Write-Output '{"success":false,"message":"No labels to print"}'
        exit 1
    }

    Add-Type -AssemblyName System.Drawing

    $doc = New-Object System.Drawing.Printing.PrintDocument
    $doc.DocumentName = "MES_BoxLabels_" + $labelSize + "_" + $labels.Count + "p"
    $doc.PrintController = New-Object System.Drawing.Printing.StandardPrintController
    $doc.OriginAtMargins = $false

    # Match printer name (exact or case-insensitive)
    $doc.PrinterSettings.PrinterName = $printerName
    if (-not $doc.PrinterSettings.IsValid) {
        foreach ($installed in [System.Drawing.Printing.PrinterSettings]::InstalledPrinters) {
            if ($installed.ToString().Trim().ToLower() -eq $printerName.Trim().ToLower()) {
                $doc.PrinterSettings.PrinterName = $installed.ToString()
                break
            }
        }
    }

    if (-not $doc.PrinterSettings.IsValid) {
        $errMsg = "May in '" + $printerName + "' khong ton tai tren he thong Windows"
        $outObj = @{ success = $false; message = $errMsg; pages_printed = 0 }
        Write-Output ($outObj | ConvertTo-Json -Compress)
        exit 1
    }

    # Handle virtual file printers (Microsoft Print to PDF / XPS) without blocking on Save As dialog
    $pLower = $doc.PrinterSettings.PrinterName.ToLower()
    if ($pLower -match "print to pdf" -or $pLower -match "xps document writer") {
        $doc.PrinterSettings.PrintToFile = $true
        $doc.PrinterSettings.PrintFileName = [string]$job.pdf_fallback
    }

    $ps = $doc.DefaultPageSettings
    $ps.Margins = New-Object System.Drawing.Printing.Margins(0, 0, 0, 0)

    # Configure PaperSize & Landscape orientation (Width > Height)
    # Large label (80x100 / 100x80 / 110x80): ~394-433 x 300-315 hundredths of an inch (100-110mm wide x 76-80mm high)
    # Small label (65x30): 256 x 118 hundredths of an inch (65mm wide x 30mm high)
    $curW = $ps.PaperSize.Width
    $curH = $ps.PaperSize.Height

    if ($labelSize -eq "110x80") {
        $isCalibratedLarge = (($curW -ge 370 -and $curW -le 450 -and $curH -ge 275 -and $curH -le 345) -or ($curH -ge 370 -and $curH -le 450 -and $curW -ge 275 -and $curW -le 345))
        if (-not $isCalibratedLarge) {
            $ps.PaperSize = New-Object System.Drawing.Printing.PaperSize("MES_Label_100x80_Landscape", 394, 315)
        }
    } else {
        $isCalibratedSmall = (($curW -ge 230 -and $curW -le 280 -and $curH -ge 100 -and $curH -le 140) -or ($curH -ge 230 -and $curH -le 280 -and $curW -ge 100 -and $curW -le 140))
        if (-not $isCalibratedSmall) {
            $ps.PaperSize = New-Object System.Drawing.Printing.PaperSize("MES_Label_65x30_Landscape", 256, 118)
        }
    }

    # Guarantee LANDSCAPE when PaperSize is defined with Width < Height (e.g. 80mm x 100mm)
    if ($ps.PaperSize.Width -lt $ps.PaperSize.Height) {
        $ps.Landscape = $true
    } else {
        $ps.Landscape = $false
    }

    $script:pageIndex = 0

    $doc.add_PrintPage({
        param($sender, $e)

        $g = $e.Graphics
        $g.PageUnit = [System.Drawing.GraphicsUnit]::Millimeter

        $item = $labels[$script:pageIndex]

        $targetW = if ($labelSize -eq "110x80") { 104.0 } else { 65.0 }
        $targetH = if ($labelSize -eq "110x80") { 80.0 } else { 30.0 }

        $clipW = [double]$g.VisibleClipBounds.Width
        $clipH = [double]$g.VisibleClipBounds.Height

        # Adapt to thermal printhead printable area (e.g., 103.97mm x 76.2mm on Zebra 4-inch roll) while capping at targetW x targetH on A4/uncalibrated drivers
        $drawW = if ($clipW -gt 20.0 -and $clipW -lt $targetW) { $clipW } else { $targetW }
        $drawH = if ($clipH -gt 15.0 -and $clipH -lt $targetH) { $clipH } else { $targetH }

        # Render onto an offscreen 24-bpp RGB Bitmap at the printer's native DPI (e.g. 300 DPI).
        # This prevents ZDesigner monochrome GDI drivers from corrupting scaled PNG bitmaps into solid black boxes.
        $dpiX = if ($g.DpiX -gt 0) { [float]$g.DpiX } else { 300.0 }
        $dpiY = if ($g.DpiY -gt 0) { [float]$g.DpiY } else { 300.0 }
        $pxW  = [Math]::Max(120, [int][Math]::Round(($drawW / 25.4) * $dpiX))
        $pxH  = [Math]::Max(60,  [int][Math]::Round(($drawH / 25.4) * $dpiY))

        $canvas = New-Object System.Drawing.Bitmap($pxW, $pxH, [System.Drawing.Imaging.PixelFormat]::Format24bppRgb)
        $canvas.SetResolution($dpiX, $dpiY)

        $cg = [System.Drawing.Graphics]::FromImage($canvas)
        $cg.Clear([System.Drawing.Color]::White)
        $cg.PageUnit = [System.Drawing.GraphicsUnit]::Millimeter
        $cg.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
        $cg.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit
        $cg.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::NearestNeighbor
        $cg.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::Half

        # Helper to auto-fit bold font within available width so text never clips or truncates with ellipsis
        $fitFont = {
            param([string]$txt, [float]$maxPt, [float]$minPt, [float]$maxW, [System.Drawing.FontStyle]$style = [System.Drawing.FontStyle]::Bold)
            if ([string]::IsNullOrEmpty($txt)) {
                return New-Object System.Drawing.Font("Arial", $maxPt, $style)
            }
            $safeMaxW = [Math]::Max(10.0, ($maxW - 1.2))
            $pt = $maxPt
            while ($pt -gt $minPt) {
                $fTest = New-Object System.Drawing.Font("Arial", $pt, $style)
                $sz = $cg.MeasureString($txt, $fTest)
                if ($sz.Width -le $safeMaxW) {
                    return $fTest
                }
                $fTest.Dispose()
                $pt = [float]($pt - 0.5)
            }
            return New-Object System.Drawing.Font("Arial", $minPt, $style)
        }

        # Decode Base64 QR Image (keep MemoryStream alive until after DrawImage completes)
        $qrImg = $null
        $ms = $null
        $b64 = [string]$item.qr_base64
        if ($b64 -match "^data:image\/[a-zA-Z0-9\+\-\.]+;base64,(.+)$") {
            $bytes = [System.Convert]::FromBase64String($Matches[1])
            $ms = New-Object System.IO.MemoryStream(,$bytes)
            $qrImg = [System.Drawing.Image]::FromStream($ms)
        }

        $line1 = [string]$item.material_name
        $weightVal = [string]$item.weight
        $line2 = if ([string]::IsNullOrWhiteSpace($weightVal)) { "$($item.qty) pcs" } else { "$($item.qty) pcs | $weightVal Kg" }
        $line3 = "||"
        $suppVal = [string]$item.supplier
        $line4 = if ([string]::IsNullOrWhiteSpace($suppVal)) { "$($item.lot_no)" } else { "$($item.lot_no) | $suppVal" }
        $line5 = [string]$item.box_no

        $sf = New-Object System.Drawing.StringFormat
        $sf.FormatFlags = [System.Drawing.StringFormatFlags]::NoWrap
        $sf.Trimming = [System.Drawing.StringTrimming]::None
        $sf.Alignment = [System.Drawing.StringAlignment]::Near
        $sf.LineAlignment = [System.Drawing.StringAlignment]::Center

        if ($labelSize -eq "110x80") {
            # =========================================================
            # LAYOUT TEM LON 80 x 100 / 100 x 80 / 110 x 80 MM (LANDSCAPE)
            # Full-area utilization: Maximized 58mm QR Code & Full-Width Bold Typography
            # =========================================================
            $padX = 1.8
            $padY = 2.0
            $qrSize = [Math]::Min(58.0, ($drawH - ($padY * 2.0)))
            $qrX = $padX
            $qrY = ($drawH - $qrSize) / 2.0

            if ($qrImg -ne $null) {
                $cg.DrawImage($qrImg, [System.Drawing.RectangleF]::new([float]$qrX, [float]$qrY, [float]$qrSize, [float]$qrSize))
                $qrImg.Dispose()
            }
            if ($ms -ne $null) {
                $ms.Dispose()
            }

            $gapX = 2.0
            $textX = $qrX + $qrSize + $gapX
            $textW = [Math]::Max(32.0, ($drawW - $textX - $padX))

            $usableH = $drawH - ($padY * 2.0)
            $rowH = $usableH / 5.0

            $f1 = & $fitFont $line1 18.0 8.5 $textW ([System.Drawing.FontStyle]::Bold)
            $f2 = & $fitFont $line2 16.0 9.0 $textW ([System.Drawing.FontStyle]::Bold)
            $f3 = New-Object System.Drawing.Font("Arial", 15.0, [System.Drawing.FontStyle]::Bold)
            $f4 = & $fitFont $line4 14.5 8.5 $textW ([System.Drawing.FontStyle]::Bold)
            $f5 = & $fitFont $line5 18.0 9.5 $textW ([System.Drawing.FontStyle]::Bold)

            $cg.DrawString($line1, $f1, [System.Drawing.Brushes]::Black, [System.Drawing.RectangleF]::new([float]$textX, [float]($padY + ($rowH * 0.0)), [float]$textW, [float]$rowH), $sf)
            $cg.DrawString($line2, $f2, [System.Drawing.Brushes]::Black, [System.Drawing.RectangleF]::new([float]$textX, [float]($padY + ($rowH * 1.0)), [float]$textW, [float]$rowH), $sf)
            $cg.DrawString($line3, $f3, [System.Drawing.Brushes]::Black, [System.Drawing.RectangleF]::new([float]$textX, [float]($padY + ($rowH * 2.0)), [float]$textW, [float]$rowH), $sf)

            # Allow 2-line wrap on Line 4 (Lot | Supplier) if long so full supplier info is visible
            $sfWrap = New-Object System.Drawing.StringFormat
            $sfWrap.Trimming = [System.Drawing.StringTrimming]::EllipsisCharacter
            $sfWrap.Alignment = [System.Drawing.StringAlignment]::Near
            $sfWrap.LineAlignment = [System.Drawing.StringAlignment]::Center
            $cg.DrawString($line4, $f4, [System.Drawing.Brushes]::Black, [System.Drawing.RectangleF]::new([float]$textX, [float]($padY + ($rowH * 3.0)), [float]$textW, [float]$rowH), $sfWrap)
            $sfWrap.Dispose()

            $lineY = [float]($padY + ($rowH * 4.0))
            $penDiv = New-Object System.Drawing.Pen([System.Drawing.Color]::Black, 0.75)
            $cg.DrawLine($penDiv, [float]$textX, $lineY, [float]($textX + $textW - 0.8), $lineY)
            $penDiv.Dispose()

            $cg.DrawString($line5, $f5, [System.Drawing.Brushes]::Black, [System.Drawing.RectangleF]::new([float]$textX, [float]($lineY + 0.8), [float]$textW, [float]($rowH - 0.8)), $sf)

            $f1.Dispose(); $f2.Dispose(); $f3.Dispose(); $f4.Dispose(); $f5.Dispose()
        } else {
            # =========================================================
            # LAYOUT TEM 65 x 30 MM (LANDSCAPE: RONG 65MM x CAO 30MM)
            # =========================================================
            $padX = 1.5
            $padY = 1.2
            $qrSize = [Math]::Min(25.5, ($drawH - ($padY * 2.0)))
            $qrX = $padX
            $qrY = ($drawH - $qrSize) / 2.0

            if ($qrImg -ne $null) {
                $cg.DrawImage($qrImg, [System.Drawing.RectangleF]::new([float]$qrX, [float]$qrY, [float]$qrSize, [float]$qrSize))
                $qrImg.Dispose()
            }
            if ($ms -ne $null) {
                $ms.Dispose()
            }

            $gapX = 1.6
            $textX = $qrX + $qrSize + $gapX
            $textW = [Math]::Max(20.0, ($drawW - $textX - $padX))

            $usableH = $drawH - ($padY * 2.0)
            $rowH = $usableH / 5.0

            $f1 = & $fitFont $line1 9.0 6.5 $textW ([System.Drawing.FontStyle]::Bold)
            $f2 = & $fitFont $line2 8.0 6.0 $textW ([System.Drawing.FontStyle]::Bold)
            $f3 = New-Object System.Drawing.Font("Arial", 7.2, [System.Drawing.FontStyle]::Bold)
            $f4 = & $fitFont $line4 7.2 5.5 $textW ([System.Drawing.FontStyle]::Bold)
            $f5 = & $fitFont $line5 9.0 6.5 $textW ([System.Drawing.FontStyle]::Bold)

            $cg.DrawString($line1, $f1, [System.Drawing.Brushes]::Black, [System.Drawing.RectangleF]::new([float]$textX, [float]($padY + ($rowH * 0.0)), [float]$textW, [float]$rowH), $sf)
            $cg.DrawString($line2, $f2, [System.Drawing.Brushes]::Black, [System.Drawing.RectangleF]::new([float]$textX, [float]($padY + ($rowH * 1.0)), [float]$textW, [float]$rowH), $sf)
            $cg.DrawString($line3, $f3, [System.Drawing.Brushes]::Black, [System.Drawing.RectangleF]::new([float]$textX, [float]($padY + ($rowH * 2.0)), [float]$textW, [float]$rowH), $sf)
            $cg.DrawString($line4, $f4, [System.Drawing.Brushes]::Black, [System.Drawing.RectangleF]::new([float]$textX, [float]($padY + ($rowH * 3.0)), [float]$textW, [float]$rowH), $sf)

            $lineY = [float]($padY + ($rowH * 4.0))
            $penDiv = New-Object System.Drawing.Pen([System.Drawing.Color]::Black, 0.4)
            $cg.DrawLine($penDiv, [float]$textX, $lineY, [float]($textX + $textW - 0.5), $lineY)
            $penDiv.Dispose()

            $cg.DrawString($line5, $f5, [System.Drawing.Brushes]::Black, [System.Drawing.RectangleF]::new([float]$textX, [float]($lineY + 0.4), [float]$textW, [float]($rowH - 0.4)), $sf)

            $f1.Dispose(); $f2.Dispose(); $f3.Dispose(); $f4.Dispose(); $f5.Dispose()
        }

        $sf.Dispose()
        $cg.Dispose()

        # Blit the 300-DPI rendered bitmap 1:1 in Pixel units onto the printer Graphics surface
        $g.PageUnit = [System.Drawing.GraphicsUnit]::Pixel
        $g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::NearestNeighbor
        $g.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::Half
        $g.DrawImage($canvas, 0, 0, $pxW, $pxH)
        $canvas.Dispose()

        # Strict pagination: 1 label = 1 page, N labels = N pages, never any blank page
        $script:pageIndex++
        if ($script:pageIndex -lt $labels.Count) {
            $e.HasMorePages = $true
        } else {
            $e.HasMorePages = $false
        }
    })

    $doc.Print()
    $doc.Dispose()

    $res = @{
        success       = $true
        message       = "Da gui lenh in truc tiep (" + $labels.Count + " tem, kho " + $labelSize + " Landscape) den may in: " + $printerName
        pages_printed = $labels.Count
    }
    Write-Output ($res | ConvertTo-Json -Compress)
    exit 0
} catch {
    $errRes = @{
        success       = $false
        message       = "Loi Windows Print Spooler: " + $_.Exception.Message
        pages_printed = 0
    }
    Write-Output ($errRes | ConvertTo-Json -Compress)
    exit 1
}
POWERSHELL;

    @file_put_contents($ps1File, $psScriptContent);

    $escapedPs1  = escapeshellarg($ps1File);
    $escapedJson = escapeshellarg($jsonFile);
    $cmd = "powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File {$escapedPs1} -JobJsonPath {$escapedJson} 2>&1";
    $rawOut = @shell_exec($cmd);
    @unlink($jsonFile);

    if (!empty($rawOut)) {
        // Find JSON line in output
        $lines = preg_split('/\r\n|\r|\n/', trim($rawOut));
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if (str_starts_with($line, '{') && str_ends_with($line, '}')) {
                $decoded = json_decode($line, true);
                if (is_array($decoded) && isset($decoded['success'])) {
                    return [
                        'success'       => (bool)$decoded['success'],
                        'message'       => (string)($decoded['message'] ?? ''),
                        'pages_printed' => (int)($decoded['pages_printed'] ?? 0)
                    ];
                }
            }
        }
    }

    return [
        'success'       => false,
        'message'       => 'Không nhận được phản hồi từ trình điều khiển máy in: ' . trim((string)$rawOut),
        'pages_printed' => 0
    ];
}
