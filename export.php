<?php
/**
 * XUẤT DỮ LIỆU EXCEL / CSV (UTF-8 KÈM BOM)
 * Hỗ trợ xuất:
 * 1. Danh sách chỉ thị sản xuất (type=orders) kèm bộ lọc theo tháng, từ khóa, trạng thái, ngày tạo
 * 2. Lịch sử in tem (type=history) kèm bộ lọc theo ngày, chỉ thị, sản phẩm, người in, vượt định mức
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$type = trim($_GET['type'] ?? 'history');

// ========================================================
// TẢI FILE MẪU IMPORT (UTF-8 KÈM BOM CHO EXCEL)
// ========================================================
if ($type === 'sample_orders') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $candidates = [
        __DIR__ . '/data/sample_orders.csv',
        __DIR__ . '/data/sample_orders_0910.csv',
        __DIR__ . '/data/sample_orders 0910.csv',
        __DIR__ . '/sample_orders.csv'
    ];
    $filePath = null;
    foreach ($candidates as $cand) {
        if (file_exists($cand) && is_readable($cand)) {
            $filePath = $cand;
            break;
        }
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sample_orders.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Cache-Control: no-store, no-cache, must-revalidate, post-check=0, pre-check=0');

    if ($filePath !== null) {
        $content = file_get_contents($filePath);
        if (substr($content, 0, 3) !== "\xEF\xBB\xBF") {
            $content = "\xEF\xBB\xBF" . $content;
        }
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit;
    }

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, ['Mã phiếu', 'Mã chỉ thị', 'Mã sản phẩm', 'Tháng phát hành', 'Số lượng chỉ thị', 'Ghi chú']);
    fputcsv($output, ['579142', 'P260906052', 'TU0425B-20Z2', '10/2026', '10575', '']);
    fputcsv($output, ['579143', 'P260906053', 'TU0425BU-20Z2', '10/2026', '10800', '']);
    fclose($output);
    exit;
}

if ($type === 'sample_specs') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $candidates = [
        __DIR__ . '/data/sample_specs.csv',
        __DIR__ . '/data/sample_specs_0910.csv',
        __DIR__ . '/data/sample_specs 0910.csv',
        __DIR__ . '/sample_specs.csv'
    ];
    $filePath = null;
    foreach ($candidates as $cand) {
        if (file_exists($cand) && is_readable($cand)) {
            $filePath = $cand;
            break;
        }
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sample_specs.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Cache-Control: no-store, no-cache, must-revalidate, post-check=0, pre-check=0');

    if ($filePath !== null) {
        $content = file_get_contents($filePath);
        if (substr($content, 0, 3) !== "\xEF\xBB\xBF") {
            $content = "\xEF\xBB\xBF" . $content;
        }
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit;
    }

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, ['Mã sản phẩm', 'Loại thùng', 'Quy cách (con/thùng)', 'Nhà cung cấp', 'Trọng lượng (Kg/thùng)', 'Đơn vị tính', 'Mô tả']);
    fputcsv($output, ['TU0425B-20Z2', '1/2', '225', 'SMC Factory 2', '', 'roll', '']);
    fputcsv($output, ['TU0425BU-20Z2', '1/2', '225', 'SMC Factory 2', '', 'roll', '']);
    fclose($output);
    exit;
}

requireAuth();

$pdo = getDbConnection();
ensurePrintHistorySchema($pdo);

if ($type === 'orders') {
    // ========================================================
    // XUẤT DANH SÁCH CHỈ THỊ SẢN XUẤT (CTSX)
    // ========================================================
    $keyword    = trim($_GET['k'] ?? '');
    $status     = trim($_GET['status'] ?? '');
    $issueMonth = trim($_GET['month'] ?? $_GET['issue_month'] ?? '');
    $fromDate   = trim($_GET['from_date'] ?? '');
    $toDate     = trim($_GET['to_date'] ?? '');

    $sql = "SELECT po.*, u.full_name AS creator_name, u.employee_code AS creator_msnv,
                   COALESCE(ps.pack_qty, po.pack_qty) AS pack_qty,
                   COALESCE(ps.supplier, po.supplier) AS supplier,
                   COALESCE(ps.weight_per_box, po.weight_per_box) AS weight_per_box
            FROM `production_orders` po 
            LEFT JOIN `users` u ON po.created_by = u.id 
            LEFT JOIN `product_specs` ps ON po.product_code = ps.product_code AND po.box_type = ps.box_type
            WHERE 1=1";
    $params = [];

    if (isViewer()) {
        $sql .= " AND po.`status` != 'paused'";
    }

    if (!empty($keyword)) {
        $sql .= " AND (po.`slip_code` LIKE ? OR po.`order_code` LIKE ? OR po.`product_code` LIKE ? OR po.`supplier` LIKE ?)";
        $kParam = "%{$keyword}%";
        $params[] = $kParam;
        $params[] = $kParam;
        $params[] = $kParam;
        $params[] = $kParam;
    }

    if (!empty($status) && !(isViewer() && $status === 'paused')) {
        $sql .= " AND po.`status` = ?";
        $params[] = $status;
    }

    if (!empty($issueMonth)) {
        $sql .= " AND po.`issue_month` LIKE ?";
        $params[] = "%{$issueMonth}%";
    }

    if (!empty($fromDate)) {
        $sql .= " AND DATE(po.`created_at`) >= ?";
        $params[] = $fromDate;
    }

    if (!empty($toDate)) {
        $sql .= " AND DATE(po.`created_at`) <= ?";
        $params[] = $toDate;
    }

    $sql .= " ORDER BY 
        CASE WHEN po.`status` = 'completed' THEN 1 ELSE 0 END ASC,
        COALESCE(
            STR_TO_DATE(TRIM(po.`issue_month`), '%d/%m/%Y'),
            STR_TO_DATE(TRIM(po.`issue_month`), '%Y-%m-%d'),
            STR_TO_DATE(TRIM(po.`issue_month`), '%Y/%m/%d'),
            STR_TO_DATE(CONCAT('01/', TRIM(po.`issue_month`)), '%d/%m/%Y'),
            STR_TO_DATE(CONCAT('01-', TRIM(po.`issue_month`)), '%d-%m-%Y'),
            STR_TO_DATE(CONCAT('01-', TRIM(po.`issue_month`)), '%d-%b-%Y'),
            STR_TO_DATE(CONCAT('01/', TRIM(po.`issue_month`)), '%d/%b/%Y'),
            STR_TO_DATE(CONCAT(TRIM(po.`issue_month`), '-01'), '%Y-%m-%d'),
            STR_TO_DATE(CONCAT(TRIM(po.`issue_month`), '/01'), '%Y/%m/%d'),
            DATE(po.`created_at`)
        ) DESC,
        CASE po.`status`
            WHEN 'in_progress' THEN 1
            WHEN 'pending'     THEN 2
            WHEN 'paused'      THEN 3
            WHEN 'completed'   THEN 4
            ELSE 5
        END ASC,
        po.`created_at` DESC,
        po.`id` DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();

    $filename = "Danh_Sach_Chi_Thi_CTSX_" . date('Ymd_His') . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    // Ghi UTF-8 BOM để Excel hiển thị đúng tiếng Việt
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    $headers = [
        'ID',
        'Mã phiếu chỉ thị',
        'Mã chỉ thị (Lot No)',
        'Mã sản phẩm',
        'Loại thùng',
        'Tháng phát hành',
        'Số lượng chỉ thị',
        'Đã in tích lũy',
        'Còn lại',
        'Quy cách (con/thùng)',
        'Nhà cung cấp',
        'Trọng lượng (Kg/thùng)',
        'Tiến độ (%)',
        'Trạng thái',
        'Ghi chú',
        'Người tạo',
        'MSNV người tạo',
        'Ngày tạo'
    ];
    fputcsv($output, $headers);

    $statusMap = [
        'pending'     => 'Chờ in',
        'in_progress' => 'Đang in',
        'completed'   => 'Hoàn thành',
        'paused'      => 'Tạm dừng',
        'cancelled'   => 'Đã hủy'
    ];

    foreach ($orders as $o) {
        $target  = (int)$o['target_qty'];
        $printed = (int)$o['printed_qty'];
        $pct = ($target > 0) ? min(100, round(($printed / $target) * 100)) : 0;
        $statusStr = $statusMap[$o['status']] ?? $o['status'];

        fputcsv($output, [
            $o['id'],
            $o['slip_code'],
            $o['order_code'],
            $o['product_code'],
            'Thùng loại ' . ($o['box_type'] ?? '1'),
            $o['issue_month'],
            $target,
            $printed,
            $o['remaining_qty'],
            $o['pack_qty'],
            $o['supplier'] ?? '',
            $o['weight_per_box'],
            $pct . '%',
            $statusStr,
            $o['note'] ?? '',
            $o['creator_name'] ?? '',
            $o['creator_msnv'] ?? '',
            $o['created_at']
        ]);
    }

    fclose($output);
    exit;

} elseif ($type === 'specs' || $type === 'package_spec') {
    // ========================================================
    // XUẤT DANH MỤC QUY CÁCH ĐÓNG GÓI (PRODUCT_SPECS / PACKAGE_SPEC)
    // ========================================================
    $keyword = trim($_GET['k'] ?? '');
    $sql = "SELECT * FROM `product_specs` WHERE 1=1";
    $params = [];

    if (!empty($keyword)) {
        $sql .= " AND (`product_code` LIKE ? OR `supplier` LIKE ? OR `description` LIKE ?)";
        $kParam = "%{$keyword}%";
        $params = [$kParam, $kParam, $kParam];
    }

    $sql .= " ORDER BY `product_code` ASC, `box_type` ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $specs = $stmt->fetchAll();

    $filename = "Quy_Cach_Dong_Goi_" . date('Ymd_His') . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    // Ghi UTF-8 BOM
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    $headers = [
        'Mã sản phẩm',
        'Loại thùng',
        'Quy cách (con/thùng)',
        'Nhà cung cấp',
        'Trọng lượng (Kg/thùng)',
        'Đơn vị tính',
        'Mô tả'
    ];
    fputcsv($output, $headers);

    foreach ($specs as $s) {
        fputcsv($output, [
            $s['product_code'],
            $s['box_type'] ?? '1',
            $s['pack_qty'],
            $s['supplier'] ?? '',
            $s['weight_per_box'],
            $s['unit'] ?? 'pcs',
            $s['description'] ?? ''
        ]);
    }

    fclose($output);
    exit;

} else {
    // ========================================================
    // XUẤT LỊCH SỬ IN TEM (PRINT HISTORY)
    // ========================================================
    $fromDate     = trim($_GET['from_date'] ?? '');
    $toDate       = trim($_GET['to_date'] ?? '');
    $slipCode     = trim($_GET['slip_code'] ?? '');
    $orderCode    = trim($_GET['order_code'] ?? '');
    $productCode  = trim($_GET['product_code'] ?? '');
    $operatorId   = (int)($_GET['operator_id'] ?? 0);
    $isOverTarget = trim($_GET['is_over_target'] ?? '');

    $sql = "SELECT ph.*, po.supplier, po.issue_month 
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

    $sql .= " ORDER BY ph.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $filename = "Lich_Su_In_Tem_" . date('Ymd_His') . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    // Ghi UTF-8 BOM
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    $headers = [
        'ID',
        'Thời gian in',
        'Mã phiếu chỉ thị',
        'Mã chỉ thị (Lot No)',
        'Mã sản phẩm',
        'Loại thùng',
        'Tháng phát hành',
        'Số lượng in (con)',
        'Quy cách (con/thùng)',
        'Số tem thùng',
        'Thùng lẻ',
        'Số lượng lẻ',
        'Danh sách Box No',
        'Tình trạng định mức',
        'MSNV duyệt vượt mức',
        'Nội dung & Lý do in vượt',
        'Người thực hiện',
        'MSNV người in',
        'Ghi chú'
    ];
    fputcsv($output, $headers);

    foreach ($rows as $r) {
        $boxNumbersArr = json_decode($r['box_numbers'], true) ?: [];
        $boxListStr = implode("; ", $boxNumbersArr);

        $overTargetStr = $r['is_over_target'] ? 'Vượt định mức' : 'Đúng định mức';
        $oddBoxStr = $r['is_odd_box'] ? 'Có lẻ' : 'Đủ chẵn';

        fputcsv($output, [
            $r['id'],
            $r['created_at'],
            $r['slip_code'],
            $r['order_code'],
            $r['product_code'],
            'Thùng loại ' . ($r['box_type'] ?? '1'),
            $r['issue_month'] ?? '',
            $r['print_qty'],
            $r['pack_qty'],
            $r['box_count'],
            $oddBoxStr,
            $r['odd_qty'],
            $boxListStr,
            $overTargetStr,
            $r['approved_by_msnv'] ?? '',
            $r['override_reason'] ?? '',
            $r['operator_name'],
            $r['operator_msnv'],
            $r['note'] ?? ''
        ]);
    }

    fclose($output);
    exit;
}
