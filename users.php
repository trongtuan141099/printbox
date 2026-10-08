<?php
/**
 * QUẢN LÝ NGƯỜI DÙNG VÀ PHÂN QUYỀN (RBAC - ADMIN ONLY)
 */
$pageTitle = "Quản Lý Người Dùng";
require_once __DIR__ . '/includes/header.php';
requireRole('admin');

$pdo = getDbConnection();

// THÊM NGƯỜI DÙNG MỚI
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_user') {
    $username     = trim($_POST['username'] ?? '');
    $password     = trim($_POST['password'] ?? '');
    $fullName     = trim($_POST['full_name'] ?? '');
    $employeeCode = trim($_POST['employee_code'] ?? '');
    $role         = trim($_POST['role'] ?? 'viewer');

    if (empty($username) || empty($password) || empty($fullName) || empty($employeeCode)) {
        setFlash('danger', 'Vui lòng điền đầy đủ các thông tin bắt buộc (*)!');
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO `users` (`username`, `password`, `full_name`, `employee_code`, `role`, `status`, `created_at`) 
                                   VALUES (?, ?, ?, ?, ?, 1, NOW())");
            $stmt->execute([$username, $password, $fullName, $employeeCode, $role]);
            setFlash('success', "Đã thêm người dùng: {$fullName} ({$username})!");
            header("Location: users.php");
            exit;
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                setFlash('danger', 'Tên đăng nhập hoặc Mã số nhân viên (MSNV) đã tồn tại!');
            } else {
                setFlash('danger', 'Lỗi CSDL: ' . $e->getMessage());
            }
        }
    }
}

// KHÓA / KÍCH HOẠT TÀI KHOẢN
if (isset($_GET['toggle_id'])) {
    $toggleId = (int)$_GET['toggle_id'];
    if ($toggleId === (int)$currentUser['id']) {
        setFlash('warning', 'Không thể tự khóa tài khoản của chính mình!');
    } else {
        $stmt = $pdo->prepare("UPDATE `users` SET `status` = 1 - `status` WHERE `id` = ?");
        $stmt->execute([$toggleId]);
        setFlash('success', 'Đã cập nhật trạng thái tài khoản!');
    }
    header("Location: users.php");
    exit;
}

$users = $pdo->query("SELECT * FROM `users` ORDER BY `id` ASC")->fetchAll();
?>

<div class="page-header">
    <div>
        <h1 class="page-title">👥 Quản Lý Người Dùng &amp; Phân Quyền (RBAC)</h1>
        <p style="color:var(--text-muted);font-size:14px;margin-top:2px;">
            Quản trị tài khoản xưởng &bull; Mã số nhân viên (MSNV) &bull; Quyền Admin, Editor, Viewer
        </p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn-primary" onclick="openModal('modal_add_user')">
            ➕ Thêm Người Dùng Mới
        </button>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <span class="card-title">Danh Sách Tài Khoản Hệ Thống (<?= count($users) ?> tài khoản)</span>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th style="width:60px;">ID</th>
                        <th>Họ Và Tên</th>
                        <th>Mã Số NV (MSNV)</th>
                        <th>Tên Đăng Nhập</th>
                        <th style="text-align:center;">Phân Quyền</th>
                        <th style="text-align:center;">Trạng Thái</th>
                        <th>Ngày Tạo</th>
                        <th style="text-align:center;width:140px;">Thao Tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td>#<?= $u['id'] ?></td>
                        <td><strong><?= htmlspecialchars($u['full_name']) ?></strong></td>
                        <td>
                            <code style="font-weight:700;color:#2563eb;"><?= htmlspecialchars($u['employee_code']) ?></code>
                        </td>
                        <td><?= htmlspecialchars($u['username']) ?></td>
                        <td style="text-align:center;">
                            <span class="role-pill role-<?= htmlspecialchars($u['role']) ?>">
                                <?= strtoupper(htmlspecialchars($u['role'])) ?>
                            </span>
                        </td>
                        <td style="text-align:center;">
                            <?php if ($u['status'] == 1): ?>
                                <span class="badge badge-success">Hoạt động</span>
                            <?php else: ?>
                                <span class="badge badge-danger">Đã khóa</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:13px;color:var(--text-muted);"><?= formatDateTime($u['created_at']) ?></td>
                        <td style="text-align:center;">
                            <?php if ((int)$u['id'] !== (int)$currentUser['id']): ?>
                                <a href="users.php?toggle_id=<?= $u['id'] ?>" class="btn btn-sm <?= ($u['status'] == 1) ? 'btn-danger' : 'btn-success' ?>" onclick="return confirm('Bạn có chắc muốn đổi trạng thái tài khoản này?')">
                                    <?= ($u['status'] == 1) ? '🔒 Khóa' : '🔓 Mở' ?>
                                </a>
                            <?php else: ?>
                                <span style="font-size:12px;color:var(--text-muted);">Đang đăng nhập</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL THÊM NGƯỜI DÙNG -->
<div id="modal_add_user" class="modal-overlay">
    <div class="modal-dialog">
        <form method="POST" action="users.php">
            <input type="hidden" name="action" value="add_user">
            <div class="modal-header">
                <span class="modal-title">➕ Tạo Tài Khoản Người Dùng Mới</span>
                <button type="button" class="modal-close" onclick="closeModal('modal_add_user')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label required">Họ và Tên Nhân Viên</label>
                    <input type="text" name="full_name" class="form-control" placeholder="vd: Lê Văn An" required>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label required">Mã Số Nhân Viên (MSNV)</label>
                        <input type="text" name="employee_code" class="form-control" placeholder="vd: NV-105 hoặc ADM-02" required>
                        <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">Dùng xác nhận duyệt vượt mức nếu là Admin</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label required">Tên Đăng Nhập (Username)</label>
                        <input type="text" name="username" class="form-control" placeholder="vd: an.le" required>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label required">Mật Khẩu</label>
                        <input type="password" name="password" class="form-control" placeholder="••••••••" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label required">Phân Quyền (Role)</label>
                        <select name="role" class="form-control" required>
                            <option value="viewer">Viewer (Chỉ xem lịch sử/chỉ thị)</option>
                            <option value="editor" selected>Editor (Nhập liệu và in tem)</option>
                            <option value="admin">Admin (Toàn quyền & duyệt vượt mức)</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal_add_user')">Hủy</button>
                <button type="submit" class="btn btn-primary">Lưu Người Dùng</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id) { 
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.add('active'); 
    document.body.classList.add('modal-open');
    setTimeout(() => {
        const inp = el.querySelector('input:not([type="hidden"]), select');
        if (inp) inp.focus();
    }, 150);
}

function closeModal(id) { 
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.remove('active'); 
    if (!document.querySelector('.modal-overlay.active')) {
        document.body.classList.remove('modal-open');
    }
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const activeModal = document.querySelector('.modal-overlay.active');
        if (activeModal) closeModal(activeModal.id);
    }
});

document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-overlay') && e.target.classList.contains('active')) {
        closeModal(e.target.id);
    }
});

window.openModal = openModal;
window.closeModal = closeModal;
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

