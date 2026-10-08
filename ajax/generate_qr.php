<?php
/**
 * AJAX / HTTP: TẠO MÃ QR CODE CHUẨN ISO OFFLINE
 * Hỗ trợ xuất trực tiếp PNG hoặc SVG hoặc chuỗi Data URI Base64
 * Zero external dependency, tương thích 100% PHP 8.0+
 */
require_once __DIR__ . '/../libs/phpqrcode/qrlib.php';

$text   = trim($_GET['text'] ?? $_POST['text'] ?? '');
$format = strtolower(trim($_GET['format'] ?? $_POST['format'] ?? 'png'));
$size   = max(1, min(20, (int)($_GET['size'] ?? $_POST['size'] ?? 6)));
$margin = max(2, min(10, (int)($_GET['margin'] ?? $_POST['margin'] ?? 4)));

if (empty($text)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Thiếu tham số nội dung mã QR (text)!';
    exit;
}

if ($format === 'svg') {
    header('Content-Type: image/svg+xml');
    echo QRcode::svg($text, false, QRcode::QR_ECLEVEL_M, $size, $margin);
    exit;
}

if ($format === 'base64') {
    header('Content-Type: application/json; charset=utf-8');
    $dataUri = QRcode::base64($text, QRcode::QR_ECLEVEL_M, $size, $margin);
    echo json_encode(['success' => true, 'data_uri' => $dataUri, 'text' => $text]);
    exit;
}

// Mặc định xuất ảnh PNG chuẩn
header('Content-Type: image/png');
QRcode::png($text, false, QRcode::QR_ECLEVEL_M, $size, $margin, false);

