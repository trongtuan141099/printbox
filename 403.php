<?php
$pageTitle = "403 Không có quyền truy cập";
require_once __DIR__ . '/includes/header.php';
?>
<div class="card" style="max-width:600px;margin:40px auto;text-align:center;padding:40px 20px;">
    <div style="font-size:64px;margin-bottom:16px;">🚫</div>
    <h2 style="color:var(--danger);margin-bottom:10px;">403 - Quyền Truy Cập Bị Từ Chối</h2>
    <p style="color:var(--text-muted);margin-bottom:24px;">
        Tài khoản của bạn (<strong><?= htmlspecialchars($currentUser['username']) ?></strong> - Quyền: <strong><?= strtoupper(htmlspecialchars($currentUser['role'])) ?></strong>) không có thẩm quyền thực hiện thao tác này.
    </p>
    <div>
        <a href="index.php" class="btn btn-primary">⬅️ Về Trang Chủ</a>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

