<?php
/**
 * TRANG ĐĂNG NHẬP HỆ THỐNG (MODERN INTRANET LOGIN)
 * Thiết kế giao diện Clean White Card chuẩn tài liệu tham khảo:
 * - Icon ổ khóa màu xanh nổi bật ở đầu thẻ
 * - Ô nhập Username/Email có icon tiền tố
 * - Ô nhập Password có icon tiền tố và nút mắt (Eye toggle) ẩn/hiện mật khẩu
 * - Tùy chọn "Ghi nhớ đăng nhập" và "Quên mật khẩu?"
 * - Nút "Đăng nhập hệ thống" màu xanh dương công nghiệp
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Nếu đã đăng nhập, chuyển hướng ngay về trang Chỉ thị sản xuất (CTSX)
if (isLoggedIn()) {
    header("Location: orders.php");
    exit;
}

$error = '';
$redirect = $_GET['redirect'] ?? 'orders.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error = "Vui lòng nhập đầy đủ tên đăng nhập và mật khẩu!";
    } else {
        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare("SELECT * FROM `users` WHERE `username` = ? LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            $passValid = false;
            if ($user) {
                if (password_verify($password, $user['password'])) {
                    $passValid = true;
                } elseif ($password === $user['password']) {
                    $passValid = true;
                }
            }

            if ($user && $passValid) {
                if ($user['status'] != 1) {
                    $error = "Tài khoản của bạn đang bị khóa! Vui lòng liên hệ Quản trị viên hệ thống.";
                } else {
                    // Đăng nhập thành công
                    loginUser($user);
                    header("Location: " . $redirect);
                    exit;
                }
            } else {
                $error = "Tên đăng nhập hoặc mật khẩu không chính xác!";
            }
        } catch (PDOException $e) {
            $error = "Lỗi kết nối cơ sở dữ liệu: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng Nhập Hệ Thống - PrintBox Intranet</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/factory.css">
    <style>
        body {
            background-color: #f1f5f9;
            background-image: radial-gradient(#cbd5e1 1px, transparent 1px);
            background-size: 24px 24px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }

        .login-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.08), 0 8px 10px -6px rgba(15, 23, 42, 0.04);
            border: 1px solid #e2e8f0;
            width: 100%;
            max-width: 440px;
            padding: 40px 36px 36px;
            transition: all 0.2s ease;
        }

        .lock-badge {
            width: 68px;
            height: 68px;
            background-color: #eff6ff;
            border: 2px solid #bfdbfe;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px auto;
            color: #2563eb;
        }

        .lock-badge svg {
            width: 34px;
            height: 34px;
        }

        .login-title {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            text-align: center;
            margin-bottom: 6px;
            letter-spacing: -0.3px;
        }

        .login-subtitle {
            font-size: 13.5px;
            color: #64748b;
            text-align: center;
            margin-bottom: 28px;
        }

        .input-group-custom {
            position: relative;
            margin-bottom: 20px;
        }

        .input-group-custom .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            pointer-events: none;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .input-group-custom .input-icon svg {
            width: 18px;
            height: 18px;
        }

        .input-group-custom .form-control {
            height: 48px;
            padding-left: 44px;
            padding-right: 44px;
            font-size: 14.5px;
            border-radius: 8px;
            border: 1.5px solid #cbd5e1;
            transition: all 0.15s ease;
        }

        .input-group-custom .form-control:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }

        .toggle-password-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            padding: 4px;
            color: #94a3b8;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            transition: color 0.15s ease;
        }

        .toggle-password-btn:hover {
            color: #334155;
        }

        .toggle-password-btn svg {
            width: 19px;
            height: 19px;
        }

        .login-btn {
            height: 48px;
            background-color: #2563eb;
            border-color: #2563eb;
            font-size: 15px;
            font-weight: 700;
            border-radius: 8px;
            box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.25);
            transition: all 0.15s ease;
        }

        .login-btn:hover {
            background-color: #1d4ed8;
            border-color: #1d4ed8;
            box-shadow: 0 6px 10px -1px rgba(37, 99, 235, 0.35);
        }

        .form-options-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            font-size: 13.5px;
        }

        .form-check-input {
            width: 17px;
            height: 17px;
            margin-top: 1px;
            cursor: pointer;
        }

        .form-check-label {
            cursor: pointer;
            color: #475569;
            user-select: none;
        }

        .forgot-link {
            color: #2563eb;
            text-decoration: none;
            font-weight: 600;
        }

        .forgot-link:hover {
            text-decoration: underline;
            color: #1d4ed8;
        }

        .footer-note {
            text-align: center;
            margin-top: 24px;
            font-size: 12px;
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
    </style>
</head>
<body>

<div class="login-card">
    <!-- LOCK ICON HEADER -->
    <div class="lock-badge">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                  d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z">
            </path>
        </svg>
    </div>

    <h1 class="login-title">Đăng Nhập Hệ Thống</h1>
    <p class="login-subtitle">Quản lý in tem nhãn sản xuất</p>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger py-2 px-3 mb-3 small d-flex align-items-center" role="alert">
            <svg style="width:18px;height:18px;margin-right:8px;flex-shrink:0;" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
            </svg>
            <div><?= htmlspecialchars($error) ?></div>
        </div>
    <?php endif; ?>

    <form method="POST" action="login.php?redirect=<?= urlencode($redirect) ?>" autocomplete="off">
        
        <!-- INPUT USERNAME / EMAIL WITH ICON -->
        <div class="mb-3">
            <label class="form-label small fw-bold text-secondary mb-1">Tên đăng nhập hoặc Email</label>
            <div class="input-group-custom">
                <span class="input-icon">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                              d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z">
                        </path>
                    </svg>
                </span>
                <input type="text" name="username" id="username" class="form-control" 
                       placeholder="Nhập tên đăng nhập..." required autofocus>
            </div>
        </div>

        <!-- INPUT PASSWORD WITH ICON AND EYE TOGGLE -->
        <div class="mb-2">
            <label class="form-label small fw-bold text-secondary mb-1">Mật khẩu</label>
            <div class="input-group-custom">
                <span class="input-icon">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                              d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z">
                        </path>
                    </svg>
                </span>
                <input type="password" name="password" id="password" class="form-control" 
                       placeholder="Nhập mật khẩu..." required>
                <button type="button" class="toggle-password-btn" id="togglePasswordBtn" title="Hiện/ẩn mật khẩu">
                    <svg id="eyeIcon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                              d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                              d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                    </svg>
                </button>
            </div>
        </div>

        <!-- REMEMBER ME & FORGOT PASSWORD ROW -->
        <div class="form-options-row">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="remember" id="rememberMe">
                <label class="form-check-label" for="rememberMe">
                    Ghi nhớ đăng nhập
                </label>
            </div>
            <a href="javascript:void(0)" onclick="alert('Vui lòng liên hệ Quản trị viên (Admin) xưởng để cấp lại mật khẩu.')" class="forgot-link">
                Quên mật khẩu?
            </a>
        </div>

        <!-- PRIMARY BLUE SUBMIT BUTTON -->
        <button type="submit" class="btn btn-primary w-100 login-btn">
            Đăng nhập hệ thống
        </button>
    </form>

    <div class="footer-note">
        <svg style="width:14px;height:14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                  d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z">
            </path>
        </svg>
        <span>DX Plastic Team - Design by 3T</span>
    </div>
</div>

<script>
// NÚT ẨN / HIỆN MẬT KHẨU (EYE TOGGLE)
const toggleBtn = document.getElementById('togglePasswordBtn');
const passwordInput = document.getElementById('password');
const eyeIcon = document.getElementById('eyeIcon');

toggleBtn.addEventListener('click', function() {
    const isPassword = passwordInput.getAttribute('type') === 'password';
    passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
    
    if (isPassword) {
        // Biểu tượng gạch chéo mắt (Eye-off)
        eyeIcon.innerHTML = `
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                  d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18">
            </path>
        `;
    } else {
        // Biểu tượng mắt thường (Eye)
        eyeIcon.innerHTML = `
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                  d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                  d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
        `;
    }
});
</script>

</body>
</html>
