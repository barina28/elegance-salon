<?php
/**
 * ============================================================
 *  AUTH - FORGOT & RESET PASSWORD
 *  Step 1: Request reset link by entering username or email.
 *  Step 2: Follow token link, validate expiry, enter new password.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/init.php';

$self  = BASE_URL . 'modules/auth/forgot.php';
$token = get('token');
$mode  = ($token !== '') ? 'reset' : 'request';

$errors = array();
$info   = '';

/* ============================================================
 *  MODE 1: REQUEST RESET LINK
 * ========================================================== */
if ($mode === 'request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $identifier = post('identifier');
    if ($identifier === '') {
        $errors[] = 'Please enter your username or email address.';
    } else {
        $stmt = mysqli_prepare($conn,
            "SELECT user_id, name, username, email FROM users
             WHERE (username = ? OR email = ?) AND status = 'active' LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'ss', $identifier, $identifier);
        mysqli_stmt_execute($stmt);
        $u = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$u) {
            // Generic message for security
            $info = 'If an account exists with that username or email, a reset link has been generated.';
        } else {
            $reset_token = bin2hex(random_bytes(20));
            $expiry      = time() + 3600; // 1 hour
            $val_data    = $u['user_id'] . '|' . $expiry;

            $key = 'pwd_reset_' . $reset_token;
            $stmt = mysqli_prepare($conn, "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            mysqli_stmt_bind_param($stmt, 'sss', $key, $val_data, $val_data);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $reset_url = BASE_URL . 'modules/auth/forgot.php?token=' . $reset_token;

            // Send via email if email exists
            if (!empty($u['email'])) {
                $salon_name = get_setting('salon_name', defined('SITE_NAME') ? SITE_NAME : 'Elegance Salon');
                $email_content = "Hi {$u['name']},\n\nA password reset was requested for your account ({$u['username']}).\n\nClick the link below to set a new password:\n{$reset_url}\n\nThis link is valid for 1 hour. If you did not request this, you can ignore this email.";
                $html = render_email_template('Password Reset Request - ' . $salon_name, $u['name'], $email_content, $salon_name);
                send_email($u['email'], $u['name'], 'Password Reset - ' . $salon_name, $html, $email_content);
            }

            $info = 'Password reset instructions have been created. <br><br><strong>Reset Link:</strong> <a href="' . e($reset_url) . '">' . e($reset_url) . '</a>';
        }
    }
}

/* ============================================================
 *  MODE 2: RESET PASSWORD WITH TOKEN
 * ========================================================== */
$token_valid = false;
$user_to_reset = null;

if ($mode === 'reset') {
    $key = 'pwd_reset_' . $token;
    $stmt = mysqli_prepare($conn, "SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 's', $key);
    mysqli_stmt_execute($stmt);
    $res = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if ($res) {
        $parts = explode('|', $res['setting_value']);
        if (count($parts) === 2) {
            $user_id = (int)$parts[0];
            $exp     = (int)$parts[1];

            if ($exp >= time()) {
                $stmt_u = mysqli_prepare($conn, "SELECT user_id, username, name FROM users WHERE user_id = ? AND status = 'active' LIMIT 1");
                mysqli_stmt_bind_param($stmt_u, 'i', $user_id);
                mysqli_stmt_execute($stmt_u);
                $user_to_reset = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_u));
                mysqli_stmt_close($stmt_u);

                if ($user_to_reset) {
                    $token_valid = true;
                }
            }
        }
    }

    if (!$token_valid) {
        $errors[] = 'This password reset link is invalid or has expired. Please request a new one.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();

        $pass1 = post('new_password');
        $pass2 = post('confirm_password');

        if (mb_strlen($pass1) < 6) {
            $errors[] = 'New password must be at least 6 characters.';
        } elseif ($pass1 !== $pass2) {
            $errors[] = 'Passwords do not match.';
        } else {
            $hash = password_hash($pass1, PASSWORD_BCRYPT);
            $stmt_up = mysqli_prepare($conn, "UPDATE users SET password = ? WHERE user_id = ?");
            mysqli_stmt_bind_param($stmt_up, 'si', $hash, $user_to_reset['user_id']);
            mysqli_stmt_execute($stmt_up);
            mysqli_stmt_close($stmt_up);

            // Delete token
            $stmt_del = mysqli_prepare($conn, "DELETE FROM settings WHERE setting_key = ?");
            mysqli_stmt_bind_param($stmt_del, 's', $key);
            mysqli_stmt_execute($stmt_del);
            mysqli_stmt_close($stmt_del);

            set_flash('success', 'Your password has been reset successfully! Please log in with your new password.');
            redirect(BASE_URL . 'modules/auth/login.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $mode === 'reset' ? 'Set New Password' : 'Forgot Password' ?> - <?= e(get_setting('salon_name', defined('SITE_NAME') ? SITE_NAME : 'Elegance Salon')) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>assets/css/style.css" rel="stylesheet">
<style>
.login-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #faf7f2; }
.login-card { width: 100%; max-width: 440px; border-radius: 14px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); border: 1px solid #ebdcd5; background: #fff; }
</style>
</head>
<body class="login-wrap p-3">

<div class="login-card p-4">
    <div class="text-center mb-3">
        <span style="width:60px; height:60px; border-radius:50%; background:linear-gradient(135deg, #b76e79, #c9a227); color:#fff; display:inline-flex; align-items:center; justify-content:center; font-size:1.75rem;">
            <i class="bi bi-key"></i>
        </span>
    </div>

    <h4 class="text-center fw-bold mb-1"><?= $mode === 'reset' ? 'Set New Password' : 'Forgot Password' ?></h4>
    <p class="text-center text-muted small mb-4">
        <?= $mode === 'reset' ? 'Enter and confirm your new account password.' : 'Enter your username or email to recover your account.' ?>
    </p>

    <?php if ($errors): ?>
        <div class="alert alert-danger py-2 small">
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($info): ?>
        <div class="alert alert-success small">
            <?= $info ?>
        </div>
    <?php endif; ?>

    <?php if ($mode === 'request'): ?>
        <form method="post" action="">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label fw-semibold">Username or Email</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                    <input type="text" name="identifier" class="form-control" placeholder="e.g. admin or stylist1" required autofocus>
                </div>
            </div>
            <button type="submit" class="btn btn-salon w-100 py-2 mb-3">
                <i class="bi bi-send me-1"></i> Send Reset Link
            </button>
            <div class="text-center">
                <a href="<?= BASE_URL ?>modules/auth/login.php" class="small text-muted text-decoration-none">
                    <i class="bi bi-arrow-left me-1"></i> Back to Log In
                </a>
            </div>
        </form>
    <?php elseif ($mode === 'reset' && $token_valid): ?>
        <form method="post" action="">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label fw-semibold">New Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="password" name="new_password" class="form-control" placeholder="At least 6 characters" required autofocus>
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label fw-semibold">Confirm New Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                    <input type="password" name="confirm_password" class="form-control" placeholder="Re-type password" required>
                </div>
            </div>
            <button type="submit" class="btn btn-salon w-100 py-2 mb-3">
                <i class="bi bi-check-circle me-1"></i> Save New Password
            </button>
            <div class="text-center">
                <a href="<?= BASE_URL ?>modules/auth/login.php" class="small text-muted text-decoration-none">
                    <i class="bi bi-arrow-left me-1"></i> Cancel &amp; Log In
                </a>
            </div>
        </form>
    <?php else: ?>
        <div class="text-center">
            <a href="<?= $self ?>" class="btn btn-outline-salon btn-sm">Request a New Link</a>
        </div>
    <?php endif; ?>
</div>

</body>
</html>

