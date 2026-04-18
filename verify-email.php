<?php
/**
 * Email Verification Handler
 * Verifies user email addresses after registration
 */

// Get token and user ID from URL
$token = $_GET['token'] ?? '';
$userId = $_GET['id'] ?? '';

if (empty($token) || empty($userId)) {
    header('Location: register.html?error=invalid_token');
    exit;
}

require_once __DIR__ . '/admin/admin-config.php';
require_once __DIR__ . '/includes/runtime-site-config.php';

$siteName = 'MotorLink';

try {
    $db = getDatabase();
    $siteConfig = motorlink_get_site_runtime_config($db, [
        'include_private' => true,
        'runtime_base_url' => motorlink_get_runtime_origin_fallback()
    ]);
    $siteName = trim((string)($siteConfig['site_name'] ?? $siteName)) ?: $siteName;
    
    // Verify token and get user
    $stmt = $db->prepare("
        SELECT id, email, full_name, verification_token, email_verified, status 
        FROM users 
        WHERE id = ? AND verification_token = ?
    ");
    $stmt->execute([$userId, $token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        header('Location: register.html?error=invalid_token');
        exit;
    }
    
    // Check if already verified
    if ($user['email_verified'] && $user['status'] === 'active') {
        // Already verified and active - log them in if not already logged in
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION['user_id'])) {
            // Set user session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['user_type'] = $user['user_type'] ?? 'individual';
            $_SESSION['last_activity'] = time();
        }
        
        header('Location: index.html?verified=already');
        exit;
    }
    
    // Start session for auto-login
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    // Update email_verified status and activate account
    $stmt = $db->prepare("
        UPDATE users 
        SET email_verified = 1, 
            verification_token = NULL,
            status = 'active',
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$userId]);
    
    // Log the user in automatically
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['email'] = $user['email'];
    
    // Get user_type from database (in case it wasn't in the SELECT)
    $stmt = $db->prepare("SELECT user_type FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userType = $stmt->fetchColumn();
    
    $_SESSION['user_type'] = $userType ?? 'individual';
    $_SESSION['last_activity'] = time();
    
    // Show success page with auto-redirect
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars('Email Verified - ' . $siteName, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .verification-container {
            background: white;
            border-radius: 16px;
            padding: 40px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            max-width: 500px;
            width: 100%;
            text-align: center;
        }
        .success-icon {
            width: 80px;
            height: 80px;
            background: #4caf50;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
        }
        .success-icon i {
            font-size: 40px;
            color: white;
        }
        h1 {
            color: #1a1a1a;
            margin-bottom: 16px;
            font-size: 28px;
        }
        p {
            color: #666;
            line-height: 1.6;
            margin-bottom: 24px;
        }
        .info-box {
            background: #f8f9fa;
            border-left: 4px solid #00c853;
            padding: 16px;
            border-radius: 4px;
            text-align: left;
            margin: 24px 0;
        }
        .info-box p {
            margin: 8px 0;
            font-size: 14px;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #00c853;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            margin: 8px;
            transition: background 0.2s;
        }
        .btn:hover {
            background: #00a843;
        }
        .btn-secondary {
            background: #6c757d;
        }
        .btn-secondary:hover {
            background: #545b62;
        }
    </style>
</head>
<body>
    <div class="verification-container">
        <div class="success-icon">
            <i class="fas fa-check"></i>
        </div>
        <h1>Email Verified Successfully!</h1>
        <p>Your email address has been verified.</p>
        
        <div class="info-box">
            <p><strong>Account Activated!</strong></p>
            <p>Your email has been verified and your account is now active. You have been automatically logged in.</p>
            <p>You can now start using all <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?> features!</p>
        </div>
        
        <div>
            <a href="index.html" class="btn" id="continueBtn">
                <i class="fas fa-home"></i> Continue to Homepage
            </a>
        </div>
        
        <script>
            // Auto-redirect after 3 seconds
            setTimeout(function() {
                window.location.href = 'index.html';
            }, 3000);
        </script>
    </div>
</body>
</html>
    <?php
    
} catch (Exception $e) {
    error_log("Email verification error: " . $e->getMessage());
    header('Location: register.html?error=verification_failed');
    exit;
}
?>

