<?php
/**
 * ============================================================
 *  LOGIN - username OR email + password (bcrypt verified)
 *  Includes: CSRF check, brute-force brake (5 tries/30s),
 *  session regeneration on success.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/init.php';

// Already logged in? Straight to the dashboard.
if (is_logged_in()) {
    redirect(dashboard_url());
}

$errors = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $identifier = post('identifier');
    $password   = post('password');

    /* ---- brute-force brake: after 5 failures wait 30s ---- */
    $attempts = isset($_SESSION['login_attempts']) ? (int)$_SESSION['login_attempts'] : 0;
    $last_try = isset($_SESSION['last_attempt'])   ? (int)$_SESSION['last_attempt']   : 0;

    if ($attempts >= 5 && (time() - $last_try) < 30) {
        set_flash('danger', 'Too many failed attempts. Please wait 30 seconds and try again.');
        redirect(BASE_URL . 'modules/auth/login.php');
    }

    /* ---- basic validation ---- */
    if ($identifier === '') {
        $errors[] = 'Please enter your username or email.';
    }
    if ($password === '') {
        $errors[] = 'Please enter your password.';
    }

    /* ---- check credentials ---- */
    if (!$errors) {
        $sql = "SELECT user_id, name, username, email, password, role
                FROM users
                WHERE (username = ? OR email = ?) AND status = 'active'
                LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'ss', $identifier, $identifier);
        mysqli_stmt_execute($stmt);
        $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if ($user && password_verify($password, $user['password'])) {
            /* ---------- SUCCESS ---------- */
            session_regenerate_id(true);   // prevents session fixation attacks

            $uid = (int)$user['user_id'];
            $_SESSION['user_id']  = $uid;
            $_SESSION['name']     = $user['name'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email']    = $user['email'];
            $_SESSION['role']     = $user['role'];

            unset($_SESSION['login_attempts'], $_SESSION['last_attempt']);

            // record last login time
            $stmt = mysqli_prepare($conn, "UPDATE users SET last_login = NOW() WHERE user_id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $uid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            set_flash('success', 'Welcome back, ' . $user['name'] . '!');
            redirect(dashboard_url());
        }

        /* ---------- FAILURE ---------- */
        $_SESSION['login_attempts'] = $attempts + 1;
        $_SESSION['last_attempt']   = time();
        usleep(400000);   // 0.4s delay slows automated guessing
        $errors[] = 'Invalid credentials. Check your username/email and password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Staff Login - <?= e(get_setting('salon_name', SITE_NAME)) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>assets/css/style.css" rel="stylesheet">
</head>

<body>

    <div class="login-wrap py-5">
        <div class="login-card card p-4">
            <div class="login-icon"><i class="bi bi-scissors"></i></div>

            <h4 class="text-center mb-1"><?= e(get_setting('salon_name', SITE_NAME)) ?></h4>
            <p class="text-center text-muted small mb-4">Staff login - admin, receptionist &amp; stylists</p>

            <?php show_flash(); ?>

            <?php if ($errors): ?>
            <div class="alert alert-danger py-2">
                <?php foreach ($errors as $err): ?>
                <div><i class="bi bi-exclamation-circle"></i> <?= e($err) ?></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <form method="post" action="<?= BASE_URL ?>modules/auth/login.php" autocomplete="off">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label">Username or Email</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" name="identifier" class="form-control"
                            placeholder="e.g. admin or admin@elegance.local" value="<?= e(post('identifier')) ?>"
                            required autofocus>
                    </div>
                </div>
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label mb-0">Password</label>
                        <a href="<?= BASE_URL ?>modules/auth/forgot.php" class="small text-decoration-none text-muted">Forgot password?</a>
                    </div>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" name="password" class="form-control" placeholder="Your password"
                            required>
                    </div>
                </div>
                <button type="submit" class="btn btn-salon w-100 py-2">
                    <i class="bi bi-box-arrow-in-right"></i> Log In
                </button>
            </form>

            <div class="alert alert-light border mt-4 mb-0 small">
                <b>Demo accounts:</b><br>
                <code>admin / admin123</code> &nbsp;&middot;&nbsp;
                <code>reception / recep123</code><br>
                <div class="mt-1 pt-1 border-top text-muted fw-semibold">Stylists &amp; Artists:</div>
                <code>stylist1 / style100</code> &nbsp;&middot;&nbsp;
                <code>stylist2 / style200</code> &nbsp;&middot;&nbsp;
                <code>stylist3 / style300</code><br>
                <code>stylist4 / style400</code> &nbsp;&middot;&nbsp;
                <code>stylist5 / style500</code><br>
                <code>nail5 / nail500</code> &nbsp;&middot;&nbsp;
                <code>nail6 / nail600</code><br>
                <code>makeup5 / makeup500</code> &nbsp;&middot;&nbsp;
                <code>makeup6 / makeup600</code> &nbsp;&middot;&nbsp;
                <code>facial5 / facial500</code>
            </div>

            <p class="text-center small mt-3 mb-0">
                <a href="<?= BASE_URL ?>index.php"><i class="bi bi-arrow-left"></i> Back to website</a>
            </p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= BASE_URL ?>assets/js/main.js"></script>
</body>

</html>