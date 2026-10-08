<?php
/**
 * TRANG IN LẠI / XUẤT NHÃN IN TEM THÙNG CHUYÊN DỤNG (PRINT LABELS)
 * Kích thước 65mm x 30mm & Tự động gọi máy in
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireAuth();

$historyId = (int)($_GET['history_id'] ?? 0);
$boxId     = (int)($_GET['box_id'] ?? 0);
$autoPrint = isset($_GET['autoprint']) ? 1 : 0;

$pdo = getDbConnection();
$labels = [];

if ($boxId > 0) {
    // In 1 tem đơn lẻ
    $stmt = $pdo->prepare("SELECT bl.*, po.supplier 
                           FROM `box_labels` bl 
                           JOIN `print_history` ph ON bl.history_id = ph.id 
                           JOIN `production_orders` po ON ph.order_id = po.id 
                           WHERE bl.id = ? LIMIT 1");
    $stmt->execute([$boxId]);
    $row = $stmt->fetch();
    if ($row) {
        $labels[] = $row;
    }
} elseif ($historyId > 0) {
    // In toàn bộ lô tem của lượt in đó
    $stmt = $pdo->prepare("SELECT bl.*, po.supplier 
                           FROM `box_labels` bl 
                           JOIN `print_history` ph ON bl.history_id = ph.id 
                           JOIN `production_orders` po ON ph.order_id = po.id 
                           WHERE bl.history_id = ? 
                           ORDER BY bl.id ASC");
    $stmt->execute([$historyId]);
    $labels = $stmt->fetchAll();
}

if (empty($labels)) {
    die("Không tìm thấy dữ liệu tem cần in!");
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>In Tem Thùng - <?= htmlspecialchars($labels[0]['order_code']) ?></title>
    <link rel="stylesheet" href="assets/css/factory.css">
    <style>
        body {
            background-color: #f1f5f9;
            margin: 0;
            padding: 20px;
        }
        .print-toolbar {
            max-width: 800px;
            margin: 0 auto 20px auto;
            background: #ffffff;
            padding: 14px 20px;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .print-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
        }
        @media print {
            body {
                background: #ffffff !important;
                padding: 0 !important;
            }
            .print-toolbar {
                display: none !important;
            }
            .print-container {
                gap: 0 !important;
            }
        }
    </style>
</head>
<body>

<div class="print-toolbar no-print">
    <div>
        <strong>Tổng số tem: <?= count($labels) ?></strong> | 
        <span>Khổ tem: 65mm &times; 30mm</span>
    </div>
    <div style="display:flex;gap:10px;">
        <button type="button" class="btn btn-secondary" onclick="window.close()">Đóng cửa sổ</button>
        <button type="button" class="btn btn-primary" onclick="window.print()">🖨️ In Ngay (Print)</button>
    </div>
</div>

<div class="print-container">
    <?php foreach ($labels as $label): 
        $qrBase64 = generateQrDataUri($label['qr_content'], 6, 4);
    ?>
    <div class="box-label-item">
        <div class="label-qr-col">
            <img src="<?= $qrBase64 ?>" alt="QR">
        </div>
        <div class="label-text-col">
            <div class="label-line-1"><?= htmlspecialchars($label['product_code']) ?></div>
            <div class="label-line-2">
                <?= (int)$label['qty'] ?> pcs <?= !empty($label['weight']) ? '| ' . htmlspecialchars($label['weight']) . ' Kg' : '' ?>
            </div>
            <div class="label-line-3">||</div>
            <div class="label-line-4">
                <?= htmlspecialchars($label['order_code']) ?> <?= !empty($label['supplier']) ? '| ' . htmlspecialchars($label['supplier']) : '' ?>
            </div>
            <div class="label-line-5"><?= htmlspecialchars($label['box_no']) ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($autoPrint): ?>
<script>
window.addEventListener('load', function() {
    window.print();
});
</script>
<?php endif; ?>

</body>
</html>

