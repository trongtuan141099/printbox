<?php
/**
 * TRANG CHỦ / ĐIỀU HƯỚNG TỚI QUẢN LÝ CHỈ THỊ SẢN XUẤT (CTSX)
 * Theo yêu cầu: Tạm thời ẩn trang Tổng quan, giữ luồng làm việc tập trung vào CTSX và Lịch sử in
 */
require_once __DIR__ . '/includes/auth.php';
requireAuth();

// Chuyển hướng ngay tới trang Chỉ thị sản xuất (CTSX)
header("Location: orders.php");
exit;
