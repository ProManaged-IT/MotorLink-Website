<?php
/**
 * Password Reset Handler
 * Allows users to reset their password using a token from email
 */

// Get token and user ID from URL
$token = $_GET['token'] ?? '';
$userId = $_GET['id'] ?? '';

require_once __DIR__ . '/admin/admin-config.php';
require_once __DIR__ . '/includes/runtime-site-config.php';

$error = null;
$validToken = false;
$userEmail = '';
$siteName = 'MotorLink';

try {
    $db = getDatabase();
    $siteConfig = motorlink_get_site_runtime_config($db, [
        'include_private' => true,
        'runtime_base_url' => motorlink_get_runtime_origin_fallback()
    ]);
    $siteName = trim((string)($siteConfig['site_name'] ?? $siteName)) ?: $siteName;
    
    if (!empty($token) && !empty($userId)) {
        // Verify token and get user
        $stmt = $db->prepare("
            SELECT id, email, full_name, reset_token, reset_token_expires, status 
            FROM users 
            WHERE id = ? AND reset_token = ?
        ");
        $stmt->execute([$userId, $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // Check if token has expired
            $now = new DateTime();
            $expiresAt = new DateTime($user['reset_token_expires']);
            
            if ($now > $expiresAt) {
                $error = 'This password reset link has expired. Please request a new one.';
            } else if ($user['status'] !== 'active' && $user['status'] !== 'pending') {
                $error = 'Your account is not active. Please contact support.';
            } else {
                $validToken = true;
                $userEmail = $user['email'];
            }
        } else {
            $error = 'Invalid password reset link. Please request a new one.';
        }
    } else {
        $error = 'Invalid password reset link. Please check your email for the correct link.';
    }
    
} catch (Exception $e) {
    error_log("Reset password page error: " . $e->getMessage());
    $error = 'An error occurred. Please try again later.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(($validToken ? 'Reset Password' : 'Invalid Link') . ' - ' . $siteName, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/login.css">
    <link rel="stylesheet" href="css/common.css">
    <script src="config.js"></script>
    <style>
        .reset-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .reset-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            padding: 40px;
            max-width: 500px;
            width: 100%;
        }
        .reset-icon {
            text-align: center;
            font-size: 64px;
            color: <?php echo $validToken ? '#28a745' : '#dc3545'; ?>;
            margin-bottom: 20px;
        }
        .reset-card h1 {
            text-align: center;
            color: #333;
            margin-bottom: 10px;
        }
        .reset-card p {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
        }
        .error-box {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .success-box {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: none;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }
        .input-wrapper {
            position: relative;
        }
        .form-control {
            width: 100%;
            padding: 12px 15px 12px 45px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s;
            box-sizing: border-box;
        }
        .form-control:focus {
            outline: none;
            border-color: #667eea;
        }
        .input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
        }
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #999;
            cursor: pointer;
            font-size: 18px;
        }
        .error-message {
            display: none;
            color: #dc3545;
            font-size: 14px;
            margin-top: 5px;
        }
        .error-message.show {
            display: block;
        }
        .btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .loading-spinner {
            display: none;
        }
        .loading-spinner.show {
            display: inline-block;
        }
        .auth-links {
            text-align: center;
            margin-top: 20px;
            color: #666;
        }
        .auth-links a {
            color: #667eea;
            text-decoration: none;
        }
        .auth-links a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="reset-container">
        <div class="reset-card">
            <?php if ($error && !$validToken): ?>
                <!-- Error State -->
                <div class="reset-icon">
                    <i class="fas fa-times-circle"></i>
                </div>
                <h1>Invalid Reset Link</h1>
                <div class="error-box">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
                <div class="auth-links">
                    <p><a href="forgot-password.html">Request a new password reset link</a></p>
                    <p><a href="login.html">Back to Login</a></p>
                </div>
            <?php elseif ($validToken): ?>
                <!-- Reset Password Form -->
                <div class="reset-icon">
                    <i class="fas fa-key"></i>
                </div>
                <h1>Reset Your Password</h1>
                <p>Enter your new password below</p>
                
                <div class="success-box" id="successBox">
                    <i class="fas fa-check-circle"></i> <span id="successMessage"></span>
                </div>
                
                <form id="resetPasswordForm" novalidate>
                    <input type="hidden" id="token" value="<?php echo htmlspecialchars($token); ?>">
                    <input type="hidden" id="userId" value="<?php echo htmlspecialchars($userId); ?>">
                    
                    <div class="form-group">
                        <label class="form-label" for="passwordInput">New Password</label>
                        <div class="input-wrapper">
                            <input 
                                type="password" 
                                id="passwordInput"
                                name="password" 
                                class="form-control" 
                                placeholder="Enter your new password"
                                required 
                                autocomplete="new-password"
                                minlength="6"
                            >
                            <i class="fas fa-lock input-icon"></i>
                            <button type="button" class="password-toggle" id="passwordToggle">
                                <i class="fas fa-eye" id="passwordToggleIcon"></i>
                            </button>
                        </div>
                        <div class="error-message" id="passwordError"></div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="confirmPasswordInput">Confirm New Password</label>
                        <div class="input-wrapper">
                            <input 
                                type="password" 
                                id="confirmPasswordInput"
                                name="confirm_password" 
                                class="form-control" 
                                placeholder="Confirm your new password"
                                required 
                                autocomplete="new-password"
                                minlength="6"
                            >
                            <i class="fas fa-lock input-icon"></i>
                            <button type="button" class="password-toggle" id="confirmPasswordToggle">
                                <i class="fas fa-eye" id="confirmPasswordToggleIcon"></i>
                            </button>
                        </div>
                        <div class="error-message" id="confirmPasswordError"></div>
                    </div>
                    
                    <button type="submit" class="btn" id="submitButton">
                        <i class="fas fa-key"></i>
                        <span id="buttonText">Reset Password</span>
                        <div class="loading-spinner" id="submitSpinner">
                            <i class="fas fa-spinner fa-spin"></i>
                        </div>
                    </button>
                </form>
                
                <div class="auth-links">
                    <p><a href="login.html">Back to Login</a></p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Get API URL from config
        const API_URL = CONFIG.API_URL;
        
        <?php if ($validToken): ?>
        const form = document.getElementById('resetPasswordForm');
        const passwordInput = document.getElementById('passwordInput');
        const confirmPasswordInput = document.getElementById('confirmPasswordInput');
        const passwordError = document.getElementById('passwordError');
        const confirmPasswordError = document.getElementById('confirmPasswordError');
        const submitButton = document.getElementById('submitButton');
        const buttonText = document.getElementById('buttonText');
        const submitSpinner = document.getElementById('submitSpinner');
        const successBox = document.getElementById('successBox');
        const successMessage = document.getElementById('successMessage');
        const passwordToggle = document.getElementById('passwordToggle');
        const confirmPasswordToggle = document.getElementById('confirmPasswordToggle');

        // Password toggle functionality
        passwordToggle.addEventListener('click', () => {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            passwordToggle.querySelector('i').classList.toggle('fa-eye');
            passwordToggle.querySelector('i').classList.toggle('fa-eye-slash');
        });

        confirmPasswordToggle.addEventListener('click', () => {
            const type = confirmPasswordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            confirmPasswordInput.setAttribute('type', type);
            confirmPasswordToggle.querySelector('i').classList.toggle('fa-eye');
            confirmPasswordToggle.querySelector('i').classList.toggle('fa-eye-slash');
        });

        // Form submission
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            // Reset errors
            passwordError.classList.remove('show');
            confirmPasswordError.classList.remove('show');
            passwordError.textContent = '';
            confirmPasswordError.textContent = '';
            successBox.style.display = 'none';
            
            const password = passwordInput.value;
            const confirmPassword = confirmPasswordInput.value;
            const token = document.getElementById('token').value;
            const userId = document.getElementById('userId').value;
            
            // Validate passwords
            if (!password) {
                passwordError.textContent = 'Password is required';
                passwordError.classList.add('show');
                return;
            }
            
            if (password.length < 6) {
                passwordError.textContent = 'Password must be at least 6 characters long';
                passwordError.classList.add('show');
                return;
            }
            
            if (!confirmPassword) {
                confirmPasswordError.textContent = 'Please confirm your password';
                confirmPasswordError.classList.add('show');
                return;
            }
            
            if (password !== confirmPassword) {
                confirmPasswordError.textContent = 'Passwords do not match';
                confirmPasswordError.classList.add('show');
                return;
            }
            
            // Show loading state
            submitButton.disabled = true;
            buttonText.textContent = 'Resetting...';
            submitSpinner.classList.add('show');
            
            try {
                const response = await fetch(API_URL, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'reset_password',
                        token: token,
                        user_id: userId,
                        password: password,
                        confirm_password: confirmPassword
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Show success message
                    form.style.display = 'none';
                    successBox.style.display = 'block';
                    successMessage.textContent = data.message || 'Password has been reset successfully!';
                    
                    // Redirect to login after 3 seconds
                    setTimeout(() => {
                        window.location.href = 'login.html?password_reset=success';
                    }, 3000);
                } else {
                    // Show error
                    const errorMsg = data.message || 'Failed to reset password. Please try again.';
                    if (errorMsg.includes('expired') || errorMsg.includes('Invalid')) {
                        passwordError.textContent = errorMsg;
                        passwordError.classList.add('show');
                    } else {
                        confirmPasswordError.textContent = errorMsg;
                        confirmPasswordError.classList.add('show');
                    }
                }
            } catch (error) {
                console.error('Error:', error);
                confirmPasswordError.textContent = 'Network error. Please check your connection and try again.';
                confirmPasswordError.classList.add('show');
            } finally {
                // Reset loading state
                submitButton.disabled = false;
                buttonText.textContent = 'Reset Password';
                submitSpinner.classList.remove('show');
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>

