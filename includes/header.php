<?php
/**
 * HEADER HỆ THỐNG - RESPONSIVE NAVBAR VỚI BOOTSTRAP 5
 * Điều hướng tinh gọn: Chỉ Thị SX (CTSX) & Lịch Sử In, cộng với Quản trị hệ thống
 */
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
    <style>
        .navbar-brand-badge {
            background-color: #2563eb;
            color: #ffffff;
            font-size: 13px;
            font-weight: 800;
            padding: 4px 8px;
            border-radius: 4px;
            letter-spacing: 0.5px;
        }
        .nav-link-custom {
            color: #cbd5e1 !important;
            font-weight: 600;
            font-size: 14.5px;
            padding: 8px 14px !important;
            border-radius: 6px;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .nav-link-custom:hover {
            color: #ffffff !important;
            background-color: rgba(255, 255, 255, 0.1);
        }
        .nav-link-custom.active {
            color: #ffffff !important;
            background-color: #2563eb !important;
        }
        .user-pill {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 4px 12px;
            color: #f8fafc;
            font-size: 13px;
        }
        @media print {
            .navbar, .app-header-bar, .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100 bg-light">

<!-- NAVBAR RESPONSIVE CHUẨN NHÀ MÁY (BOOTSTRAP 5) -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark py-2 px-3 shadow-sm sticky-top app-header-bar">
    <div class="container-fluid">
        <!-- LOGO BRAND -->
        <a class="navbar-brand d-flex align-items-center gap-2" href="orders.php">
            <span class="navbar-brand-badge">DX PLASTIC</span>
            <!-- <div>
                <div class="fw-bold fs-6 text-white lh-1">PRINTBOX INTRANET</div>
                <small class="text-secondary" style="font-size: 11px;">Quản Lý Tem Nhãn Sản Xuất</small>
            </div> -->
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

                <!-- QUẢN TRỊ CẤU HÌNH & QUY CÁCH -->
                <?php if (canManageDirectives() || isAdmin()): ?>
                <li class="nav-item">
                    <a class="nav-link nav-link-custom <?= ($currentPage === 'specs.php') ? 'active' : '' ?>" href="specs.php">
                        <span>📦 Quy Cách (Specs)</span>
                    </a>
                </li>
                <?php endif; ?>

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

            <!-- THÔNG TIN NGƯỜI DÙNG & ĐĂNG XUẤT -->
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <div class="user-pill d-flex align-items-center gap-2">
                    <!-- <span class="badge <?= ($currentUser['role'] === 'admin') ? 'bg-danger' : (($currentUser['role'] === 'editor') ? 'bg-primary' : 'bg-secondary') ?> text-uppercase">
                        <?= htmlspecialchars($currentUser['role']) ?>
                    </span> -->
                    <span class="fw-bold"><?= htmlspecialchars($currentUser['full_name']) ?></span>
                    <span class="text-secondary small">(<?= htmlspecialchars($currentUser['employee_code']) ?>)</span>
                </div>
                <a href="logout.php" class="btn btn-outline-secondary btn-sm text-light" title="Đăng xuất" onclick="return confirm('Bạn có chắc chắn muốn đăng xuất?')">
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
