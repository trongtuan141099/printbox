<?php
/**
 * HEADER HỆ THỐNG - RESPONSIVE NAVBAR VỚI BOOTSTRAP 5
 * Điều hướng tinh gọn: Chỉ Thị SX (CTSX) & Lịch Sử In, cộng với Quản trị hệ thống
 */
if (ob_get_level() === 0) {
    ob_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

// Kiểm tra bắt buộc đăng nhập
requireAuth();
$currentUser = getCurrentUser();
$flash = getFlash();

$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle) . ' - ' : '' ?>Hệ Thống In Tem</title>
    <!-- BOOTSTRAP 5.3 OFFLINE LOCAL -->
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <!-- FACTORY INDUSTRIAL THEME & PRINT STYLES -->
    <link rel="stylesheet" href="assets/css/factory.css">
</head>
<body class="d-flex flex-column min-vh-100 bg-light">

<!-- NAVBAR RESPONSIVE CHUẨN NHÀ MÁY (BOOTSTRAP 5) -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark py-2 px-3 shadow-sm sticky-top app-header-bar">
    <div class="container-fluid">
        <!-- LOGO BRAND -->
        <a class="navbar-brand d-flex align-items-center gap-2" href="orders.php">
            <span class="navbar-brand-badge">DX PLASTIC</span>
        </a>

        <!-- NÚT HAMBURGER MOBILE -->
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar" 
                aria-controls="mainNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- MENU ĐIỀU HƯỚNG CHÍNH -->
        <div class="collapse navbar-collapse" id="mainNavbar">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0 ms-lg-3 gap-1">
                <!-- 1. CHỈ THỊ SẢN XUẤT (CTSX) -->
                <li class="nav-item">
                    <a class="nav-link nav-link-custom <?= ($currentPage === 'orders.php') ? 'active' : '' ?>" href="orders.php">
                        <span>📋 Chỉ Thị SX (CTSX)</span>
                    </a>
                </li>

                <!-- 2. LỊCH SỬ IN (PRINT HISTORY) -->
                <li class="nav-item">
                    <a class="nav-link nav-link-custom <?= ($currentPage === 'history.php') ? 'active' : '' ?>" href="history.php">
                        <span>📜 Lịch Sử In</span>
                    </a>
                </li>

                <!-- QUẢN TRỊ CẤU HÌNH & QUY CÁCH (EDITOR & ADMIN) -->
                <?php if (canManageDirectives() || isAdmin()): ?>
                <li class="nav-item">
                    <a class="nav-link nav-link-custom <?= ($currentPage === 'specs.php') ? 'active' : '' ?>" href="specs.php">
                        <span>📦 Quy Cách (Specs)</span>
                    </a>
                </li>
                <?php endif; ?>

                <!-- CẤU HÌNH QR & NGƯỜI DÙNG (CHỈ ADMIN) -->
                <?php if (isAdmin()): ?>
                <li class="nav-item">
                    <a class="nav-link nav-link-custom <?= ($currentPage === 'settings.php') ? 'active' : '' ?>" href="settings.php">
                        <span>⚙️ Cấu Hình Mã QR</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link nav-link-custom <?= ($currentPage === 'users.php') ? 'active' : '' ?>" href="users.php">
                        <span>👥 Người Dùng</span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>

            <!-- THÔNG TIN NGƯỜI DÙNG & NÚT ĐĂNG XUẤT MÀU ĐỎ NỔI BẬT -->
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <div class="user-pill d-flex align-items-center gap-2">
                    <span class="role-pill role-<?= htmlspecialchars($currentUser['role']) ?>">
                        <?= htmlspecialchars($currentUser['role']) ?>
                    </span>
                    <span class="fw-bold"><?= htmlspecialchars($currentUser['full_name']) ?></span>
                    <span class="text-secondary small">(<?= htmlspecialchars($currentUser['employee_code']) ?>)</span>
                </div>
                <a href="logout.php" class="btn btn-danger btn-sm btn-logout fw-bold text-white" title="Đăng xuất khỏi hệ thống" onclick="return confirm('Bạn có chắc chắn muốn đăng xuất?')">
                    🚪 Thoát
                </a>
            </div>
        </div>
    </div>
</nav>

<!-- PHẦN NỘI DUNG CHÍNH (MAIN CONTENT) -->
<main class="flex-grow-1 container-fluid py-3 px-3 px-lg-4">
    <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show shadow-sm mb-3" role="alert">
            <div class="d-flex align-items-center">
                <span class="me-2 fs-5">
                    <?php if ($flash['type'] === 'success'): ?>✅
                    <?php elseif ($flash['type'] === 'warning'): ?>⚠️
                    <?php elseif ($flash['type'] === 'danger'): ?>❌
                    <?php else: ?>ℹ️<?php endif; ?>
                </span>
                <div><?= htmlspecialchars($flash['message']) ?></div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
