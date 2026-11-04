<?php
/**
 * MY PROFILE - shows account info and lets the logged-in user
 * change their own password (requires the current password).
 */

require_once __DIR__ . '/../../includes/init.php';
require_login();

$u = current_user();
$errors = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $current = post('current_password');
    $new     = post('new_password');
    $confirm = post('confirm_password');

    /* ---- validate ---- */
    if ($current === '' || $new === '' || $confirm === '') {
        $errors[] = 'All three fields are required.';
    }
    if (strlen($new) < 6) {
        $errors[] = 'New password must be at least 6 characters long.';
    }
    if ($new !== $confirm) {
        $errors[] = 'New password and confirmation do not match.';
    }

    /* ---- verify current password ---- */
    if (!$errors) {
        $stmt = mysqli_prepare($conn, "SELECT password FROM users WHERE user_id = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'i', $u['user_id']);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$row || !password_verify($current, $row['password'])) {
            $errors[] = 'Your current password is not correct.';
        }
    }

    /* ---- save new password ---- */
    if (!$errors) {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = mysqli_prepare($conn, "UPDATE users SET password = ? WHERE user_id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $hash, $u['user_id']);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        set_flash('success', 'Password changed successfully.');
        redirect(BASE_URL . 'modules/auth/profile.php');
    }
}

$page_title = 'My Profile';
require_once __DIR__ . '/../../includes/dash_header.php';
?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card card-salon">
            <div class="card-header"><i class="bi bi-person-circle"></i> Account details</div>
            <div class="card-body">
                <p class="mb-2"><b>Name:</b> <?= e($u['name']) ?></p>
                <p class="mb-2"><b>Username:</b> <?= e($u['username']) ?></p>
                <p class="mb-2"><b>Email:</b> <?= e($u['email']) ?></p>
                <p class="mb-0"><b>Role:</b> <span class="badge badge-role"><?= e(ucfirst($u['role'])) ?></span></p>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card card-salon">
            <div class="card-header"><i class="bi bi-key"></i> Change password</div>
            <div class="card-body">
                <?php if ($errors): ?>
                    <div class="alert alert-danger py-2">
                        <?php foreach ($errors as $err): ?>
                            <div><i class="bi bi-exclamation-circle"></i> <?= e($err) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?= BASE_URL ?>modules/auth/profile.php" autocomplete="off">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label">Current password</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New password <span class="text-muted small">(min 6 chars)</span></label>
                        <input type="password" name="new_password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm new password</label>
                        <input type="password" name="confirm_password" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-salon"><i class="bi bi-check2-circle"></i> Update password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/dash_footer.php'; ?>
