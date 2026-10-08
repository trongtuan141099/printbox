<?php
/**
 * QUẢN LÝ XÁC THỰC VÀ PHÂN QUYỀN (RBAC)
 * Roles: Admin (Toàn quyền, duyệt vượt mức), Editor (Nhập liệu/in tem), Viewer (Chỉ xem)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Kiểm tra đã đăng nhập chưa
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Lấy thông tin người dùng đang đăng nhập
 * @return array|null
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    return [
        'id'            => $_SESSION['user_id'] ?? 0,
        'username'      => $_SESSION['username'] ?? '',
        'full_name'     => $_SESSION['full_name'] ?? '',
        'employee_code' => $_SESSION['employee_code'] ?? '',
        'role'          => $_SESSION['role'] ?? 'viewer',
    ];
}

/**
 * Kiểm tra vai trò của người dùng
 * @param string|array $roles Danh sách vai trò cho phép
 * @return bool
 */
function hasRole($roles) {
    if (!isLoggedIn()) {
        return false;
    }
    $currentRole = $_SESSION['role'] ?? 'viewer';
    if (is_array($roles)) {
        return in_array($currentRole, $roles);
    }
    return $currentRole === $roles;
}

function isAdmin() {
    return hasRole('admin');
}

function isEditor() {
    return hasRole('editor');
}

function isViewer() {
    return hasRole('viewer');
}

/**
 * Cho phép thực thi in tem: Viewer, Editor, Admin đều được phép
 */
function canPrint() {
    return isLoggedIn();
}

/**
 * Cho phép tạo mới, chỉnh sửa và import chỉ thị: Editor và Admin
 */
function canManageDirectives() {
    return hasRole(['admin', 'editor']);
}

function canEdit() {
    return canManageDirectives();
}

function canManageSettings() {
    return isAdmin();
}

function canManageUsers() {
    return isAdmin();
}

/**
 * Bắt buộc đăng nhập trước khi truy cập
 */
function requireAuth() {
    if (!isLoggedIn()) {
        $returnUrl = urlencode($_SERVER['REQUEST_URI'] ?? 'index.php');
        header("Location: login.php?redirect=" . $returnUrl);
        exit;
    }
}

/**
 * Yêu cầu quyền cụ thể, nếu không có quyền thì chặn truy cập
 * @param string|array $roles
 */
function requireRole($roles) {
    requireAuth();
    if (!hasRole($roles)) {
        http_response_code(403);
        include __DIR__ . '/../403.php';
        exit;
    }
}

/**
 * Tạo token CSRF
 * @return string
 */
function getCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Xác thực token CSRF
 * @param string $token
 * @return bool
 */
function verifyCsrfToken($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Đăng nhập người dùng vào phiên làm việc
 * @param array $user
 */
function loginUser($user) {
    session_regenerate_id(true);
    $_SESSION['user_id']       = $user['id'];
    $_SESSION['username']      = $user['username'];
    $_SESSION['full_name']     = $user['full_name'];
    $_SESSION['employee_code'] = $user['employee_code'];
    $_SESSION['role']          = $user['role'];
    $_SESSION['logged_time']   = time();
}

/**
 * Đăng xuất
 */
function logoutUser() {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

