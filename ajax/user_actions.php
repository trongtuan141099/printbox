<?php
/**
 * AJAX ENDPOINT: QUẢN LÝ NGƯỜI DÙNG (USER ACTIONS - ADMIN ONLY)
 * Xử lý: Thêm mới, Chỉnh sửa (Edit), Xóa (Delete), Đổi trạng thái (Toggle status)
 */
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Bắt buộc đăng nhập và phải có quyền Admin
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Phiên làm việc đã hết hạn. Vui lòng đăng nhập lại!']);
    exit;
}

if (!isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Từ chối truy cập: Bạn không có quyền Quản trị viên (Admin)!']);
    exit;
}

$currentUser = getCurrentUser();
$pdo = getDbConnection();
$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

switch ($action) {
    // ---------------------------------------------------------
    // 1. LẤY CHI TIẾT NGƯỜI DÙNG THEO ID (GET USER)
    // ---------------------------------------------------------
    case 'get_user':
        $userId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        if ($userId <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID người dùng không hợp lệ!']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id, username, full_name, employee_code, role, status, created_at, updated_at FROM `users` WHERE `id` = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Không tìm thấy thông tin người dùng!']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'user'    => $user
        ]);
        exit;

    // ---------------------------------------------------------
    // 2. CHỈNH SỬA THÔNG TIN NGƯỜI DÙNG (EDIT USER)
    // ---------------------------------------------------------
    case 'edit_user':
        $userId       = (int)($_POST['user_id'] ?? 0);
        $fullName     = trim($_POST['full_name'] ?? '');
        $employeeCode = trim($_POST['employee_code'] ?? '');
        $username     = trim($_POST['username'] ?? '');
        $password     = trim($_POST['password'] ?? '');
        $role         = trim($_POST['role'] ?? '');
        $status       = isset($_POST['status']) ? (int)$_POST['status'] : 1;

        if ($userId <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID người dùng cần sửa không hợp lệ!']);
            exit;
        }

        if (empty($fullName) || empty($employeeCode) || empty($username)) {
            echo json_encode(['success' => false, 'message' => 'Vui lòng điền đầy đủ Họ tên, Mã số nhân viên (MSNV) và Tên đăng nhập!']);
            exit;
        }

        $allowedRoles = ['admin', 'editor', 'viewer'];
        if (!in_array($role, $allowedRoles, true)) {
            echo json_encode(['success' => false, 'message' => 'Vai trò phân quyền không hợp lệ!']);
            exit;
        }

        // Kiểm tra tồn tại của tài khoản cần sửa
        $stmtCheck = $pdo->prepare("SELECT id, role, status FROM `users` WHERE `id` = ? LIMIT 1");
        $stmtCheck->execute([$userId]);
        $targetUser = $stmtCheck->fetch();

        if (!$targetUser) {
            echo json_encode(['success' => false, 'message' => 'Không tìm thấy tài khoản người dùng cần chỉnh sửa!']);
            exit;
        }

        // RÀNG BUỘC AN TOÀN: Nếu Admin đang sửa tài khoản của chính mình
        $isSelf = ($userId === (int)$currentUser['id']);
        if ($isSelf) {
            // Không được tự hạ quyền của chính mình
            if ($role !== 'admin') {
                $role = 'admin'; // Giữ nguyên admin
            }
            // Không được tự khóa tài khoản của chính mình
            $status = 1;
        }

        // Kiểm tra trùng lặp Username hoặc MSNV với tài khoản khác
        $stmtDup = $pdo->prepare("SELECT id, username, employee_code FROM `users` 
                                  WHERE (LOWER(`username`) = LOWER(?) OR LOWER(`employee_code`) = LOWER(?)) 
                                    AND `id` != ? LIMIT 1");
        $stmtDup->execute([$username, $employeeCode, $userId]);
        $duplicate = $stmtDup->fetch();

        if ($duplicate) {
            if (strcasecmp($duplicate['username'], $username) === 0) {
                echo json_encode(['success' => false, 'message' => "Tên đăng nhập '{$username}' đã được sử dụng bởi tài khoản khác!"]);
                exit;
            }
            if (strcasecmp($duplicate['employee_code'], $employeeCode) === 0) {
                echo json_encode(['success' => false, 'message' => "Mã số nhân viên (MSNV) '{$employeeCode}' đã thuộc về tài khoản khác!"]);
                exit;
            }
        }

        try {
            // Xử lý cập nhật: Nếu có mật khẩu mới thì mã hóa password_hash, ngược lại giữ nguyên mật khẩu cũ
            if (!empty($password)) {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmtUpd = $pdo->prepare("UPDATE `users` 
                                          SET `username` = ?, 
                                              `password` = ?, 
                                              `full_name` = ?, 
                                              `employee_code` = ?, 
                                              `role` = ?, 
                                              `status` = ?, 
                                              `updated_at` = NOW() 
                                          WHERE `id` = ?");
                $stmtUpd->execute([$username, $hashedPassword, $fullName, $employeeCode, $role, $status, $userId]);
            } else {
                $stmtUpd = $pdo->prepare("UPDATE `users` 
                                          SET `username` = ?, 
                                              `full_name` = ?, 
                                              `employee_code` = ?, 
                                              `role` = ?, 
                                              `status` = ?, 
                                              `updated_at` = NOW() 
                                          WHERE `id` = ?");
                $stmtUpd->execute([$username, $fullName, $employeeCode, $role, $status, $userId]);
            }

            // Nếu người dùng tự đổi thông tin của mình, cập nhật lại phiên đăng nhập session
            if ($isSelf) {
                $_SESSION['username']      = $username;
                $_SESSION['full_name']     = $fullName;
                $_SESSION['employee_code'] = $employeeCode;
            }

            echo json_encode([
                'success' => true,
                'message' => "Đã cập nhật thông tin tài khoản '{$fullName}' ({$username}) thành công!",
                'user' => [
                    'id'            => $userId,
                    'username'      => $username,
                    'full_name'     => $fullName,
                    'employee_code' => $employeeCode,
                    'role'          => $role,
                    'status'        => $status
                ]
            ]);
            exit;

        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Lỗi cập nhật CSDL: ' . $e->getMessage()]);
            exit;
        }

    // ---------------------------------------------------------
    // 3. XÓA NGƯỜI DÙNG (DELETE USER)
    // ---------------------------------------------------------
    case 'delete_user':
        $userId = (int)($_POST['user_id'] ?? 0);

        if ($userId <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID người dùng cần xóa không hợp lệ!']);
            exit;
        }

        // RÀNG BUỘC AN TOÀN TUYỆT ĐỐI: CHẶN TỰ XÓA TÀI KHOẢN CHÍNH MÌNH
        if ($userId === (int)$currentUser['id']) {
            echo json_encode([
                'success' => false,
                'message' => 'RÀNG BUỘC BẢO VỆ: Bạn không được phép tự xóa tài khoản của chính mình đang đăng nhập!'
            ]);
            exit;
        }

        // Kiểm tra tồn tại
        $stmtFind = $pdo->prepare("SELECT id, username, full_name, role FROM `users` WHERE `id` = ? LIMIT 1");
        $stmtFind->execute([$userId]);
        $target = $stmtFind->fetch();

        if (!$target) {
            echo json_encode(['success' => false, 'message' => 'Tài khoản không tồn tại hoặc đã bị xóa trước đó!']);
            exit;
        }

        try {
            $stmtDel = $pdo->prepare("DELETE FROM `users` WHERE `id` = ?");
            $stmtDel->execute([$userId]);

            echo json_encode([
                'success'    => true,
                'message'    => "Đã xóa vĩnh viễn tài khoản: {$target['full_name']} ({$target['username']})!",
                'deleted_id' => $userId
            ]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Lỗi xóa người dùng trong CSDL: ' . $e->getMessage()]);
            exit;
        }

    // ---------------------------------------------------------
    // 4. KHÓA / KÍCH HOẠT TÀI KHOẢN (TOGGLE STATUS)
    // ---------------------------------------------------------
    case 'toggle_status':
        $userId = (int)($_POST['user_id'] ?? 0);

        if ($userId <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID người dùng không hợp lệ!']);
            exit;
        }

        if ($userId === (int)$currentUser['id']) {
            echo json_encode(['success' => false, 'message' => 'Bạn không thể tự khóa tài khoản của chính mình!']);
            exit;
        }

        $stmtToggle = $pdo->prepare("UPDATE `users` SET `status` = 1 - `status` WHERE `id` = ?");
        $stmtToggle->execute([$userId]);

        $stmtGet = $pdo->prepare("SELECT id, status, full_name FROM `users` WHERE `id` = ?");
        $stmtGet->execute([$userId]);
        $u = $stmtGet->fetch();

        $statusText = ($u['status'] == 1) ? 'Đã kích hoạt hoạt động' : 'Đã khóa tài khoản';

        echo json_encode([
            'success'    => true,
            'message'    => "{$statusText} cho người dùng: {$u['full_name']}!",
            'new_status' => (int)$u['status'],
            'user_id'    => $userId
        ]);
        exit;

    default:
        echo json_encode(['success' => false, 'message' => "Hành động '{$action}' không được hỗ trợ!"]);
        exit;
}

