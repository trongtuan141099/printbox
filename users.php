<?php
/**
 * QUẢN LÝ NGƯỜI DÙNG VÀ PHÂN QUYỀN (RBAC - ADMIN ONLY)
 * Các tính năng cốt lõi: Thêm mới, Chỉnh sửa (Edit), Xóa (Delete), Khóa/Mở tài khoản
 * Ràng buộc an toàn: Chặn Admin tự xóa hoặc tự khóa tài khoản của chính mình
 */
$pageTitle = "Quản Lý Người Dùng";
require_once __DIR__ . '/includes/header.php';
requireRole('admin');

$pdo = getDbConnection();
$currentAdminId = (int)$currentUser['id'];

// =======================================================
// XỬ LÝ POST FALLBACK (NẾU SUBMIT FORM TRUYỀN THỐNG KHÔNG QUA AJAX)
// =======================================================

// 1. THÊM NGƯỜI DÙNG MỚI
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
            // Kiểm tra trùng lặp
            $stmtCheck = $pdo->prepare("SELECT id, username, employee_code FROM `users` WHERE LOWER(`username`) = LOWER(?) OR LOWER(`employee_code`) = LOWER(?) LIMIT 1");
            $stmtCheck->execute([$username, $employeeCode]);
            $dup = $stmtCheck->fetch();

            if ($dup) {
                if (strcasecmp($dup['username'], $username) === 0) {
                    setFlash('danger', "Tên đăng nhập '{$username}' đã tồn tại!");
                } else {
                    setFlash('danger', "Mã số nhân viên (MSNV) '{$employeeCode}' đã tồn tại!");
                }
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO `users` (`username`, `password`, `full_name`, `employee_code`, `role`, `status`, `created_at`) 
                                       VALUES (?, ?, ?, ?, ?, 1, NOW())");
                $stmt->execute([$username, $hashedPassword, $fullName, $employeeCode, $role]);
                setFlash('success', "Đã thêm thành công người dùng: {$fullName} ({$username})!");
            }
            header("Location: users.php");
            exit;
        } catch (PDOException $e) {
            setFlash('danger', 'Lỗi CSDL: ' . $e->getMessage());
        }
    }
}

// 2. CHỈNH SỬA THÔNG TIN NGƯỜI DÙNG (EDIT USER)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_user') {
    $userId       = (int)($_POST['user_id'] ?? 0);
    $fullName     = trim($_POST['full_name'] ?? '');
    $employeeCode = trim($_POST['employee_code'] ?? '');
    $username     = trim($_POST['username'] ?? '');
    $password     = trim($_POST['password'] ?? '');
    $role         = trim($_POST['role'] ?? 'viewer');
    $status       = isset($_POST['status']) ? (int)$_POST['status'] : 1;

    if ($userId <= 0 || empty($fullName) || empty($employeeCode) || empty($username)) {
        setFlash('danger', 'Vui lòng điền đầy đủ Họ tên, Mã số nhân viên và Tên đăng nhập!');
    } else {
        try {
            // Ràng buộc bảo vệ tài khoản của chính mình
            $isSelf = ($userId === $currentAdminId);
            if ($isSelf) {
                $role   = 'admin'; // Không được tự hạ quyền
                $status = 1;       // Không được tự khóa
            }

            // Kiểm tra trùng lặp với người dùng khác
            $stmtDup = $pdo->prepare("SELECT id, username, employee_code FROM `users` 
                                      WHERE (LOWER(`username`) = LOWER(?) OR LOWER(`employee_code`) = LOWER(?)) 
                                        AND `id` != ? LIMIT 1");
            $stmtDup->execute([$username, $employeeCode, $userId]);
            $dup = $stmtDup->fetch();

            if ($dup) {
                if (strcasecmp($dup['username'], $username) === 0) {
                    setFlash('danger', "Tên đăng nhập '{$username}' đã được sử dụng bởi người dùng khác!");
                } else {
                    setFlash('danger', "Mã số nhân viên (MSNV) '{$employeeCode}' đã thuộc về tài khoản khác!");
                }
            } else {
                if (!empty($password)) {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $stmtUpd = $pdo->prepare("UPDATE `users` 
                                              SET `username` = ?, `password` = ?, `full_name` = ?, 
                                                  `employee_code` = ?, `role` = ?, `status` = ?, `updated_at` = NOW() 
                                              WHERE `id` = ?");
                    $stmtUpd->execute([$username, $hashedPassword, $fullName, $employeeCode, $role, $status, $userId]);
                } else {
                    $stmtUpd = $pdo->prepare("UPDATE `users` 
                                              SET `username` = ?, `full_name` = ?, 
                                                  `employee_code` = ?, `role` = ?, `status` = ?, `updated_at` = NOW() 
                                              WHERE `id` = ?");
                    $stmtUpd->execute([$username, $fullName, $employeeCode, $role, $status, $userId]);
                }

                if ($isSelf) {
                    $_SESSION['username']      = $username;
                    $_SESSION['full_name']     = $fullName;
                    $_SESSION['employee_code'] = $employeeCode;
                }

                setFlash('success', "Đã cập nhật thông tin người dùng: {$fullName} ({$username})!");
            }
            header("Location: users.php");
            exit;
        } catch (PDOException $e) {
            setFlash('danger', 'Lỗi CSDL khi cập nhật: ' . $e->getMessage());
        }
    }
}

// 3. XÓA NGƯỜI DÙNG (DELETE USER)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_user') {
    $userId = (int)($_POST['user_id'] ?? 0);

    // RÀNG BUỘC AN TOÀN TUYỆT ĐỐI: Chặn Admin tự xóa chính tài khoản của mình
    if ($userId === $currentAdminId) {
        setFlash('danger', 'RÀNG BUỘC AN TOÀN: Bạn không được phép tự xóa tài khoản của chính mình đang đăng nhập!');
    } elseif ($userId <= 0) {
        setFlash('danger', 'ID người dùng cần xóa không hợp lệ!');
    } else {
        try {
            $stmtFind = $pdo->prepare("SELECT id, username, full_name FROM `users` WHERE `id` = ? LIMIT 1");
            $stmtFind->execute([$userId]);
            $target = $stmtFind->fetch();

            if ($target) {
                $stmtDel = $pdo->prepare("DELETE FROM `users` WHERE `id` = ?");
                $stmtDel->execute([$userId]);
                setFlash('success', "Đã xóa vĩnh viễn tài khoản: {$target['full_name']} ({$target['username']})!");
            } else {
                setFlash('warning', 'Không tìm thấy người dùng cần xóa!');
            }
        } catch (PDOException $e) {
            setFlash('danger', 'Lỗi xóa CSDL: ' . $e->getMessage());
        }
    }
    header("Location: users.php");
    exit;
}

// 4. KHÓA / KÍCH HOẠT TÀI KHOẢN (TOGGLE STATUS GET PARAM)
if (isset($_GET['toggle_id'])) {
    $toggleId = (int)$_GET['toggle_id'];
    if ($toggleId === $currentAdminId) {
        setFlash('warning', 'Không thể tự khóa tài khoản của chính bạn đang đăng nhập!');
    } else {
        $stmt = $pdo->prepare("UPDATE `users` SET `status` = 1 - `status` WHERE `id` = ?");
        $stmt->execute([$toggleId]);
        setFlash('success', 'Đã cập nhật trạng thái hoạt động của tài khoản!');
    }
    header("Location: users.php");
    exit;
}

// LẤY DANH SÁCH TẤT CẢ NGƯỜI DÙNG
$users = $pdo->query("SELECT * FROM `users` ORDER BY `id` ASC")->fetchAll();
?>

<!-- VÙNG THÔNG BÁO FLASH & AJAX TOAST -->
<div id="ajax_alert_container" class="mb-3" style="display:none;"></div>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <h1 class="page-title mb-1">👥 Quản Lý Người Dùng &amp; Phân Quyền (RBAC)</h1>
        <p class="text-muted small mb-0">
            Quản trị tài khoản xưởng &bull; Mã số nhân viên (MSNV) &bull; Quyền Admin, Editor, Viewer &bull; Bảo mật Bcrypt
        </p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn-primary fw-bold" onclick="openModal('modal_add_user')">
            ➕ Thêm Người Dùng Mới
        </button>
    </div>
</div>

<div class="card shadow-sm border">
    <div class="card-header bg-light d-flex justify-content-between align-items-center py-3">
        <span class="card-title fw-bold text-dark mb-0">
            📋 Danh Sách Tài Khoản Hệ Thống (<span id="user_total_count"><?= count($users) ?></span> tài khoản)
        </span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0" id="users_table">
                <thead class="table-dark">
                    <tr>
                        <th style="width:60px;" class="text-center">ID</th>
                        <th>Họ Và Tên</th>
                        <th>Mã Số NV (MSNV)</th>
                        <th>Tên Đăng Nhập</th>
                        <th class="text-center">Phân Quyền</th>
                        <th class="text-center">Trạng Thái</th>
                        <th>Ngày Tạo</th>
                        <th class="text-center" style="width:230px;">Thao Tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr id="row_empty_users">
                            <td colspan="8" class="text-center py-4 text-muted">Chưa có người dùng nào trong hệ thống.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $u): 
                            $isSelf = ((int)$u['id'] === $currentAdminId);
                        ?>
                        <tr id="row_user_<?= (int)$u['id'] ?>">
                            <td class="text-center text-muted fw-bold">#<?= (int)$u['id'] ?></td>
                            <td>
                                <strong class="text-dark col-fullname"><?= htmlspecialchars($u['full_name']) ?></strong>
                                <?php if ($isSelf): ?>
                                    <span class="badge bg-primary ms-1" style="font-size:10px;">Bạn</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <code class="fw-bold fs-6 text-primary col-empcode"><?= htmlspecialchars($u['employee_code']) ?></code>
                            </td>
                            <td class="col-username">
                                <strong><?= htmlspecialchars($u['username']) ?></strong>
                            </td>
                            <td class="text-center col-role">
                                <span class="role-pill role-<?= htmlspecialchars($u['role']) ?>">
                                    <?= strtoupper(htmlspecialchars($u['role'])) ?>
                                </span>
                            </td>
                            <td class="text-center col-status">
                                <?php if ($u['status'] == 1): ?>
                                    <span class="badge badge-success">Hoạt động</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Đã khóa</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small">
                                <?= formatDateTime($u['created_at']) ?>
                            </td>
                            <td class="text-center">
                                <div class="d-inline-flex gap-1 align-items-center">
                                    <!-- NÚT 1: CHỈNH SỬA (EDIT) -->
                                    <button type="button" class="btn btn-sm btn-outline-primary" 
                                            onclick="openEditUserModal(<?= (int)$u['id'] ?>)" 
                                            title="Chỉnh sửa thông tin tài khoản">
                                        ✏️ Sửa
                                    </button>

                                    <!-- NÚT 2: KHÓA / MỞ KHÓA (TOGGLE STATUS) -->
                                    <?php if (!$isSelf): ?>
                                        <button type="button" class="btn btn-sm <?= ($u['status'] == 1) ? 'btn-outline-warning' : 'btn-outline-success' ?> btn-toggle-status" 
                                                onclick="toggleUserStatus(<?= (int)$u['id'] ?>, '<?= htmlspecialchars(addslashes($u['username']), ENT_QUOTES, 'UTF-8') ?>', <?= (int)$u['status'] ?>)" 
                                                title="<?= ($u['status'] == 1) ? 'Khóa tài khoản này' : 'Mở khóa tài khoản' ?>">
                                            <?= ($u['status'] == 1) ? '⏸️ Khóa' : '▶️ Mở' ?>
                                        </button>
                                    <?php endif; ?>

                                    <!-- NÚT 3: XÓA NGƯỜI DÙNG (DELETE) -->
                                    <?php if (!$isSelf): ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger" 
                                                onclick="confirmDeleteUser(<?= (int)$u['id'] ?>)" 
                                                title="Xóa vĩnh viễn tài khoản">
                                            🗑️ Xóa
                                        </button>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted border px-2 py-1" style="font-size:11px;" title="Không thể tự xóa hoặc khóa tài khoản đang đăng nhập">
                                            🛡️ Đang đăng nhập
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- =======================================================
     MODAL 1: THÊM NGƯỜI DÙNG MỚI (ADD USER)
     ======================================================= -->
<div id="modal_add_user" class="modal-overlay" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="users.php" id="form_add_user" autocomplete="off">
            <input type="hidden" name="action" value="add_user">
            <div class="modal-header bg-primary text-white">
                <span class="modal-title fw-bold">➕ Tạo Tài Khoản Người Dùng Mới</span>
                <button type="button" class="modal-close text-white" onclick="closeModal('modal_add_user')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label required fw-bold">Họ và Tên Nhân Viên</label>
                    <input type="text" name="full_name" class="form-control" placeholder="vd: Lê Văn An" required>
                </div>

                <div class="grid-2">
                    <div class="mb-3">
                        <label class="form-label required fw-bold">Mã Số Nhân Viên (MSNV)</label>
                        <input type="text" name="employee_code" class="form-control" placeholder="vd: NV-105 hoặc ADM-02" required>
                        <small class="text-muted d-block mt-1">Dùng xác nhận duyệt vượt mức định mức in</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required fw-bold">Tên Đăng Nhập (Username)</label>
                        <input type="text" name="username" class="form-control" placeholder="vd: an.le" required>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="mb-3">
                        <label class="form-label required fw-bold">Mật Khẩu Khởi Tạo</label>
                        <div class="input-group">
                            <input type="password" name="password" id="add_password_input" class="form-control" placeholder="••••••••" required>
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('add_password_input', this)" title="Ẩn/Hiện">
                                👁️
                            </button>
                        </div>
                        <small class="text-muted d-block mt-1">Mật khẩu được mã hóa an toàn bằng Bcrypt</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required fw-bold">Phân Quyền (Role)</label>
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
                <button type="submit" id="btn_submit_add_user" class="btn btn-primary fw-bold">💾 Lưu Tài Khoản</button>
            </div>
        </form>
    </div>
</div>

<!-- =======================================================
     MODAL 2: CHỈNH SỬA NGƯỜI DÙNG (EDIT USER)
     ======================================================= -->
<div id="modal_edit_user" class="modal-overlay" tabindex="-1">
    <div class="modal-dialog" style="max-width: 580px;">
        <form method="POST" action="users.php" id="form_edit_user" autocomplete="off">
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="user_id" id="edit_user_id" value="">
            
            <div class="modal-header bg-light border-bottom">
                <span class="modal-title fw-bold text-dark">✏️ Chỉnh Sửa Thông Tin Người Dùng</span>
                <button type="button" class="modal-close" onclick="closeModal('modal_edit_user')">&times;</button>
            </div>
            <div class="modal-body">
                <div id="edit_self_alert" class="alert alert-info py-2 px-3 small mb-3" style="display:none;">
                    🛡️ <strong>Lưu ý:</strong> Bạn đang chỉnh sửa tài khoản của <strong>chính mình</strong>. Vai trò Quản trị viên (Admin) và trạng thái Hoạt động được giữ nguyên để bảo vệ quyền đăng nhập.
                </div>

                <div class="mb-3">
                    <label class="form-label required fw-bold">Họ và Tên Nhân Viên</label>
                    <input type="text" name="full_name" id="edit_full_name" class="form-control form-control-lg" required>
                </div>

                <div class="grid-2">
                    <div class="mb-3">
                        <label class="form-label required fw-bold">Mã Số Nhân Viên (MSNV)</label>
                        <input type="text" name="employee_code" id="edit_employee_code" class="form-control" required>
                        <small class="text-muted d-block mt-1">Dùng ủy quyền duyệt vượt mức</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required fw-bold">Tên Đăng Nhập (Username)</label>
                        <input type="text" name="username" id="edit_username" class="form-control" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Mật Khẩu Mới (Tùy chọn)</label>
                    <div class="input-group">
                        <input type="password" name="password" id="edit_password" class="form-control" 
                               placeholder="Để trống nếu muốn giữ nguyên mật khẩu cũ...">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('edit_password', this)" title="Ẩn/Hiện mật khẩu">
                            👁️
                        </button>
                    </div>
                    <small class="text-muted d-block mt-1">
                        * Để trống nếu không muốn đổi mật khẩu. Nếu nhập, mật khẩu mới sẽ được mã hóa an toàn bằng Bcrypt.
                    </small>
                </div>

                <div class="grid-2">
                    <div class="mb-3">
                        <label class="form-label required fw-bold">Phân Quyền (Role)</label>
                        <select name="role" id="edit_role" class="form-control" required>
                            <option value="viewer">Viewer (Chỉ xem lịch sử/chỉ thị)</option>
                            <option value="editor">Editor (Nhập liệu và in tem)</option>
                            <option value="admin">Admin (Toàn quyền & duyệt vượt mức)</option>
                        </select>
                        <small id="edit_role_note" class="text-muted d-block mt-1"></small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required fw-bold">Trạng Thái Tài Khoản</label>
                        <select name="status" id="edit_status" class="form-control" required>
                            <option value="1">✅ Hoạt động (Active)</option>
                            <option value="0">🔒 Đã khóa (Locked)</option>
                        </select>
                        <small id="edit_status_note" class="text-muted d-block mt-1"></small>
                    </div>
                </div>
                
                <div id="edit_feedback" class="mt-2 small fw-bold"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal_edit_user')">Hủy Bỏ</button>
                <button type="submit" id="btn_submit_edit_user" class="btn btn-primary fw-bold">💾 Cập Nhật Người Dùng</button>
            </div>
        </form>
    </div>
</div>

<!-- =======================================================
     MODAL 3: XÁC NHẬN XÓA NGƯỜI DÙNG (DELETE USER)
     ======================================================= -->
<div id="modal_delete_user" class="modal-overlay" tabindex="-1">
    <div class="modal-dialog" style="max-width: 480px;">
        <form method="POST" action="users.php" id="form_delete_user">
            <input type="hidden" name="action" value="delete_user">
            <input type="hidden" name="user_id" id="delete_user_id" value="">

            <div class="modal-header bg-danger text-white">
                <span class="modal-title fw-bold">🗑️ Xác Nhận Xóa Vĩnh Viễn Tài Khoản</span>
                <button type="button" class="modal-close text-white" onclick="closeModal('modal_delete_user')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning mb-3">
                    <strong>CẢNH BÁO:</strong> Bạn có chắc chắn muốn xóa tài khoản người dùng sau đây khỏi hệ thống?
                </div>

                <div class="card bg-light p-3 border mb-3">
                    <div class="mb-1">Họ và tên: <strong id="del_full_name" class="text-dark">...</strong></div>
                    <div class="mb-1">Tên đăng nhập: <strong id="del_username" class="text-primary">...</strong></div>
                    <div class="mb-1">Mã số NV (MSNV): <code id="del_employee_code" class="fw-bold">...</code></div>
                    <div>Phân quyền: <span id="del_role" class="badge bg-secondary">...</span></div>
                </div>

                <p class="text-muted small mb-0">
                    * Hành động này sẽ xóa vĩnh viễn tài khoản khỏi hệ thống và không thể hoàn tác. Lịch sử in tem trước đây vẫn được lưu trữ nguyên vẹn dưới dạng nhật ký.
                </p>
                <div id="delete_feedback" class="mt-2 small fw-bold"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal_delete_user')">Hủy Bỏ</button>
                <button type="submit" id="btn_submit_delete_user" class="btn btn-danger fw-bold">🗑️ Xóa Vĩnh Viễn</button>
            </div>
        </form>
    </div>
</div>

<!-- JAVASCRIPT XỬ LÝ SỰ KIỆN, MODAL VÀ AJAX -->
<script>
// =======================================================
// DỮ LIỆU ĐỒNG BỘ TOÀN CỤC & TÀI KHOẢN ADMIN HIỆN TẠI
// =======================================================
const currentAdminId = <?= (int)$currentAdminId ?>;
const usersDataMap   = <?= json_encode(array_column($users, null, 'id'), JSON_UNESCAPED_UNICODE) ?: '{}' ?>;

// =======================================================
// HÀM MỞ / ĐÓNG MODAL CHUẨN XÁC, KHÔNG BỊ ĐƠ
// =======================================================
function openModal(id) { 
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.add('active'); 
    document.body.classList.add('modal-open');
    setTimeout(() => {
        const inp = el.querySelector('input:not([type="hidden"]):not([readonly]):not([disabled]), select, button.btn-primary, button.btn-danger');
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

document.querySelectorAll('.modal-overlay').forEach(modal => {
    modal.addEventListener('focusin', function(e) {
        e.stopPropagation();
    });
});

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

// =======================================================
// 1. HIỂN THỊ TOAST / ALERT THÔNG BÁO TRỰC QUAN
// =======================================================
function showUserAlert(type, message) {
    const box = document.getElementById('ajax_alert_container');
    if (!box) return;

    const alertClass = (type === 'success') ? 'alert-success' : ((type === 'warning') ? 'alert-warning' : 'alert-danger');
    const icon = (type === 'success') ? '✅' : ((type === 'warning') ? '⚠️' : '❌');

    box.className = `alert ${alertClass} alert-dismissible fade show shadow-sm`;
    box.innerHTML = `
        <div class="d-flex align-items-center justify-content-between">
            <div><strong>${icon}</strong> ${escapeHtml(message)}</div>
            <button type="button" class="btn-close" onclick="this.parentElement.parentElement.style.display='none'"></button>
        </div>
    `;
    box.style.display = 'block';
    box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, function(m) {
        return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[m];
    });
}

// =======================================================
// 2. MỞ MODAL CHỈNH SỬA NGƯỜI DÙNG (EDIT USER)
// =======================================================
function openEditUserModal(userId) {
    const user = usersDataMap[userId];
    if (!user) {
        alert('Không tìm thấy thông tin tài khoản #' + userId);
        return;
    }

    const isSelf = (Number(userId) === currentAdminId);

    // Điền dữ liệu vào form
    document.getElementById('edit_user_id').value       = user.id;
    document.getElementById('edit_full_name').value     = user.full_name;
    document.getElementById('edit_employee_code').value = user.employee_code;
    document.getElementById('edit_username').value      = user.username;
    document.getElementById('edit_password').value      = '';
    document.getElementById('edit_role').value          = user.role;
    document.getElementById('edit_status').value        = user.status;

    const fb = document.getElementById('edit_feedback');
    if (fb) fb.innerText = '';

    // Xử lý ràng buộc an toàn nếu Admin tự sửa chính mình
    const selfAlert   = document.getElementById('edit_self_alert');
    const roleSelect  = document.getElementById('edit_role');
    const statusSelect= document.getElementById('edit_status');

    if (isSelf) {
        if (selfAlert) selfAlert.style.display = 'block';
        if (roleSelect) roleSelect.disabled = true;
        if (statusSelect) statusSelect.disabled = true;
        document.getElementById('edit_role_note').innerText = 'Không thể hạ quyền của tài khoản đang đăng nhập';
        document.getElementById('edit_status_note').innerText = 'Không thể khóa tài khoản đang đăng nhập';
    } else {
        if (selfAlert) selfAlert.style.display = 'none';
        if (roleSelect) roleSelect.disabled = false;
        if (statusSelect) statusSelect.disabled = false;
        document.getElementById('edit_role_note').innerText = '';
        document.getElementById('edit_status_note').innerText = '';
    }

    openModal('modal_edit_user');
}

// =======================================================
// 3. MỞ MODAL XÁC NHẬN XÓA NGƯỜI DÙNG (DELETE USER)
// =======================================================
function confirmDeleteUser(userId) {
    if (Number(userId) === currentAdminId) {
        alert('RÀNG BUỘC AN TOÀN: Bạn không được phép tự xóa tài khoản của chính mình đang đăng nhập!');
        return;
    }

    const user = usersDataMap[userId];
    if (!user) {
        alert('Không tìm thấy thông tin tài khoản #' + userId);
        return;
    }

    document.getElementById('delete_user_id').value       = user.id;
    document.getElementById('del_full_name').innerText    = user.full_name;
    document.getElementById('del_username').innerText     = user.username;
    document.getElementById('del_employee_code').innerText= user.employee_code;
    
    const roleBadge = document.getElementById('del_role');
    if (roleBadge) {
        roleBadge.className = 'badge role-pill role-' + user.role;
        roleBadge.innerText = user.role.toUpperCase();
    }

    const fb = document.getElementById('delete_feedback');
    if (fb) fb.innerText = '';

    openModal('modal_delete_user');
}

// =======================================================
// 4. KHÓA / KÍCH HOẠT TÀI KHOẢN QUA AJAX (TOGGLE STATUS)
// =======================================================
function toggleUserStatus(userId, username, currentStatus) {
    if (Number(userId) === currentAdminId) {
        alert('Không thể tự khóa tài khoản của chính bạn!');
        return;
    }

    const actionText = (Number(currentStatus) === 1) ? 'KHÓA' : 'MỞ KHÓA';
    if (!confirm(`Bạn có chắc chắn muốn ${actionText} tài khoản [${username}]?`)) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'toggle_status');
    formData.append('user_id', userId);

    fetch('ajax/user_actions.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (!data.success) {
            showUserAlert('danger', data.message);
            return;
        }

        showUserAlert('success', data.message);

        // Cập nhật DOM của dòng tương ứng
        if (usersDataMap[userId]) {
            usersDataMap[userId].status = data.new_status;
        }

        const row = document.getElementById('row_user_' + userId);
        if (row) {
            const statusCell = row.querySelector('.col-status');
            const toggleBtn  = row.querySelector('.btn-toggle-status');

            if (data.new_status === 1) {
                if (statusCell) statusCell.innerHTML = '<span class="badge badge-success">Hoạt động</span>';
                if (toggleBtn) {
                    toggleBtn.className = 'btn btn-sm btn-outline-warning btn-toggle-status';
                    toggleBtn.innerHTML = '⏸️ Khóa';
                    toggleBtn.title = 'Khóa tài khoản này';
                }
            } else {
                if (statusCell) statusCell.innerHTML = '<span class="badge badge-danger">Đã khóa</span>';
                if (toggleBtn) {
                    toggleBtn.className = 'btn btn-sm btn-outline-success btn-toggle-status';
                    toggleBtn.innerHTML = '▶️ Mở';
                    toggleBtn.title = 'Mở khóa tài khoản';
                }
            }
        }
    })
    .catch(err => {
        showUserAlert('danger', 'Lỗi kết nối máy chủ: ' + err.message);
    });
}

// =======================================================
// 5. AJAX SUBMIT CHỈNH SỬA NGƯỜI DÙNG (FORM EDIT USER)
// =======================================================
const formEditUser = document.getElementById('form_edit_user');
if (formEditUser) {
    formEditUser.addEventListener('submit', function(e) {
        e.preventDefault();

        const btn = document.getElementById('btn_submit_edit_user');
        const fb  = document.getElementById('edit_feedback');

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Đang lưu...';
        fb.style.color = '#2563eb';
        fb.innerText = 'Đang kiểm tra và cập nhật dữ liệu...';

        const formData = new FormData(this);
        formData.append('action', 'edit_user');

        // Bổ sung role và status nếu bị disable (trường hợp tự sửa chính mình)
        const roleSel = document.getElementById('edit_role');
        const statSel = document.getElementById('edit_status');
        if (roleSel && roleSel.disabled) formData.set('role', roleSel.value);
        if (statSel && statSel.disabled) formData.set('status', statSel.value);

        fetch('ajax/user_actions.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '💾 Cập Nhật Người Dùng';

            if (!data.success) {
                fb.style.color = '#b91c1c';
                fb.innerText = '✗ ' + data.message;
                return;
            }

            fb.style.color = '#15803d';
            fb.innerText = '✓ ' + data.message;

            // Cập nhật dữ liệu vào map
            const u = data.user;
            if (usersDataMap[u.id]) {
                Object.assign(usersDataMap[u.id], u);
            }

            // Cập nhật DOM trên bảng
            const row = document.getElementById('row_user_' + u.id);
            if (row) {
                const fnCell  = row.querySelector('.col-fullname');
                const empCell = row.querySelector('.col-empcode');
                const usCell  = row.querySelector('.col-username');
                const roleCell= row.querySelector('.col-role');
                const statCell= row.querySelector('.col-status');

                if (fnCell) fnCell.innerText   = u.full_name;
                if (empCell) empCell.innerText = u.employee_code;
                if (usCell) usCell.innerHTML   = `<strong>${escapeHtml(u.username)}</strong>`;
                if (roleCell) roleCell.innerHTML = `<span class="role-pill role-${escapeHtml(u.role)}">${u.role.toUpperCase()}</span>`;
                if (statCell) statCell.innerHTML = (u.status == 1) ? '<span class="badge badge-success">Hoạt động</span>' : '<span class="badge badge-danger">Đã khóa</span>';
            }

            setTimeout(() => {
                closeModal('modal_edit_user');
                showUserAlert('success', data.message);
            }, 300);
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '💾 Cập Nhật Người Dùng';
            fb.style.color = '#b91c1c';
            fb.innerText = 'Lỗi kết nối máy chủ: ' + err.message;
        });
    });
}

// =======================================================
// 6. AJAX SUBMIT XÓA NGƯỜI DÙNG (FORM DELETE USER)
// =======================================================
const formDeleteUser = document.getElementById('form_delete_user');
if (formDeleteUser) {
    formDeleteUser.addEventListener('submit', function(e) {
        e.preventDefault();

        const btn = document.getElementById('btn_submit_delete_user');
        const fb  = document.getElementById('delete_feedback');
        const delId = document.getElementById('delete_user_id').value;

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Đang xóa...';
        fb.style.color = '#2563eb';
        fb.innerText = 'Đang xử lý yêu cầu xóa...';

        const formData = new FormData();
        formData.append('action', 'delete_user');
        formData.append('user_id', delId);

        fetch('ajax/user_actions.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '🗑️ Xóa Vĩnh Viễn';

            if (!data.success) {
                fb.style.color = '#b91c1c';
                fb.innerText = '✗ ' + data.message;
                return;
            }

            // Xóa phần tử khỏi bảng giao diện
            delete usersDataMap[delId];
            const row = document.getElementById('row_user_' + delId);
            if (row) {
                row.remove();
            }

            // Cập nhật số lượng
            const countEl = document.getElementById('user_total_count');
            if (countEl) {
                const currentCnt = Object.keys(usersDataMap).length;
                countEl.innerText = currentCnt;
                if (currentCnt === 0) {
                    const tbody = document.querySelector('#users_table tbody');
                    if (tbody) {
                        tbody.innerHTML = '<tr id="row_empty_users"><td colspan="8" class="text-center py-4 text-muted">Chưa có người dùng nào trong hệ thống.</td></tr>';
                    }
                }
            }

            closeModal('modal_delete_user');
            showUserAlert('success', data.message);
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '🗑️ Xóa Vĩnh Viễn';
            fb.style.color = '#b91c1c';
            fb.innerText = 'Lỗi kết nối máy chủ: ' + err.message;
        });
    });
}

// =======================================================
// 7. TIỆN ÍCH ẨN/HIỆN MẬT KHẨU
// =======================================================
function togglePasswordVisibility(inputId, btn) {
    const input = document.getElementById(inputId);
    if (!input) return;
    if (input.type === 'password') {
        input.type = 'text';
        btn.innerText = '🙈';
    } else {
        input.type = 'password';
        btn.innerText = '👁️';
    }
    input.focus();
}

window.openModal                = openModal;
window.closeModal               = closeModal;
window.openEditUserModal        = openEditUserModal;
window.confirmDeleteUser        = confirmDeleteUser;
window.toggleUserStatus         = toggleUserStatus;
window.togglePasswordVisibility = togglePasswordVisibility;
window.showUserAlert            = showUserAlert;
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
