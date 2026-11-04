<?php
/**
 * ============================================================
 *  ADMIN - USER MANAGEMENT (Step 11)
 *  Two-column layout: Add/Edit form + Filterable account list
 *  with role badges and robust self-protection checks.
 * ============================================================
 */
require_once __DIR__ . '/../../includes/init.php';
require_role(array('admin'));

$self = BASE_URL . 'modules/admin/users.php';
$current_admin_id = (int)$_SESSION['user_id'];

$errors = array();
$success = '';

// Edit state setup
$edit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user_edit = null;

if ($edit_id > 0) {
    $stmt = mysqli_prepare($conn, "SELECT user_id, name, username, email, role, status, last_login FROM users WHERE user_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $edit_id);
    mysqli_stmt_execute($stmt);
    $user_edit = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$user_edit) {
        redirect($self);
    }
}

// ---------- Handle Form POST (Add / Edit) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = isset($_POST['action']) ? trim($_POST['action']) : '';

    if ($action === 'save') {
        $post_id  = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
        $name     = isset($_POST['name']) ? trim($_POST['name']) : '';
        $username = isset($_POST['username']) ? trim($_POST['username']) : '';
        $email    = isset($_POST['email']) ? trim($_POST['email']) : '';
        $role     = isset($_POST['role']) ? trim($_POST['role']) : 'receptionist';
        $status   = isset($_POST['status']) ? trim($_POST['status']) : 'active';
        $password = isset($_POST['password']) ? trim($_POST['password']) : '';

        // Validations
        if ($name === '') {
            $errors['name'] = 'Full name is required.';
        }
        if ($username === '' || !preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
            $errors['username'] = 'Username must be 3-30 chars (letters, numbers, underscores).';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email address is required.';
        }
        if (!in_array($role, array('admin', 'receptionist', 'stylist'), true)) {
            $errors['role'] = 'Invalid role selected.';
        }
        if (!in_array($status, array('active', 'inactive'), true)) {
            $errors['status'] = 'Invalid status selected.';
        }

        // Self-protection: cannot demote or deactivate yourself
        if ($post_id === $current_admin_id) {
            if ($role !== 'admin') {
                $errors['role'] = 'You cannot change your own admin role.';
            }
            if ($status !== 'active') {
                $errors['status'] = 'You cannot deactivate your own account.';
            }
        }

        // Unique username check
        $stmt = mysqli_prepare($conn, "SELECT user_id FROM users WHERE username = ? AND user_id != ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'si', $username, $post_id);
        mysqli_stmt_execute($stmt);
        if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
            $errors['username'] = 'This username is already taken.';
        }
        mysqli_stmt_close($stmt);

        // Unique email check
        $stmt = mysqli_prepare($conn, "SELECT user_id FROM users WHERE email = ? AND user_id != ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'si', $email, $post_id);
        mysqli_stmt_execute($stmt);
        if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
            $errors['email'] = 'This email address is already registered.';
        }
        mysqli_stmt_close($stmt);

        if ($post_id === 0 && $password === '') {
            $errors['password'] = 'Password is required for new users.';
        }

        if (empty($errors)) {
            if ($post_id > 0) {
                // Update
                if ($password !== '') {
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = mysqli_prepare($conn, "UPDATE users SET name = ?, username = ?, email = ?, role = ?, status = ?, password = ? WHERE user_id = ?");
                    mysqli_stmt_bind_param($stmt, 'ssssssi', $name, $username, $email, $role, $status, $hashed, $post_id);
                } else {
                    $stmt = mysqli_prepare($conn, "UPDATE users SET name = ?, username = ?, email = ?, role = ?, status = ? WHERE user_id = ?");
                    mysqli_stmt_bind_param($stmt, 'sssssi', $name, $username, $email, $role, $status, $post_id);
                }
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $_SESSION['flash_success'] = 'User account updated successfully.';
                redirect($self);
            } else {
                // Insert
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = mysqli_prepare($conn, "INSERT INTO users (name, username, email, password, role, status) VALUES (?, ?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param($stmt, 'ssssss', $name, $username, $email, $hashed, $role, $status);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $_SESSION['flash_success'] = 'New user account created successfully.';
                redirect($self);
            }
        }
    } elseif ($action === 'delete') {
        $del_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
        if ($del_id === $current_admin_id) {
            $_SESSION['flash_error'] = 'You cannot delete your own account.';
            redirect($self);
        }

        // Check if linked to a staff profile
        $st_chk = mysqli_prepare($conn, "SELECT staff_id FROM staff WHERE user_id = ? LIMIT 1");
        mysqli_stmt_bind_param($st_chk, 'i', $del_id);
        mysqli_stmt_execute($st_chk);
        $has_staff = mysqli_fetch_assoc(mysqli_stmt_get_result($st_chk));
        mysqli_stmt_close($st_chk);

        if ($has_staff) {
            $_SESSION['flash_error'] = 'Cannot delete account because it is linked to a staff profile. Deactivate the account instead.';
            redirect($self);
        }

        $stmt = mysqli_prepare($conn, "DELETE FROM users WHERE user_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $del_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['flash_success'] = 'User account deleted permanently.';
        redirect($self);
    }
}

// ---------- Filters & Pagination ----------
$search   = isset($_GET['q']) ? trim($_GET['q']) : '';
$f_role   = isset($_GET['role']) ? trim($_GET['role']) : '';
$f_status = isset($_GET['status']) ? trim($_GET['status']) : '';
$page     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit    = 10;
$offset   = ($page - 1) * $limit;

$where = array('1=1');
$types = '';
$params = array();

if ($search !== '') {
    $where[] = "(name LIKE ? OR username LIKE ? OR email LIKE ?)";
    $like = "%{$search}%";
    $types .= 'sss';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($f_role !== '') {
    $where[] = "role = ?";
    $types .= 's';
    $params[] = $f_role;
}
if ($f_status !== '') {
    $where[] = "status = ?";
    $types .= 's';
    $params[] = $f_status;
}

$where_sql = implode(' AND ', $where);

// Count total
$cnt_sql = "SELECT COUNT(*) AS cnt FROM users WHERE {$where_sql}";
$stmt = mysqli_prepare($conn, $cnt_sql);
if (!empty($types)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$total_rows = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['cnt'];
mysqli_stmt_close($stmt);
$total_pages = max(1, ceil($total_rows / $limit));

// Fetch records
$data_sql = "SELECT u.*, s.staff_id FROM users u LEFT JOIN staff s ON s.user_id = u.user_id WHERE {$where_sql} ORDER BY u.user_id DESC LIMIT ?, ?";
$stmt = mysqli_prepare($conn, $data_sql);
$types_limit = $types . 'ii';
$params_limit = array_merge($params, array($offset, $limit));
mysqli_stmt_bind_param($stmt, $types_limit, ...$params_limit);
mysqli_stmt_execute($stmt);
$users = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$page_title = 'User Accounts Management';
require_once __DIR__ . '/../../includes/dash_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
    <div>
        <h1 class="h3 mb-1 fw-bold text-dark"><i class="bi bi-people-fill text-salon me-2"></i>User Accounts</h1>
        <p class="text-muted small mb-0">Manage system login accounts, roles, and access permissions.</p>
    </div>
</div>

<?php if (isset($_SESSION['flash_success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-2"></i><?= e($_SESSION['flash_success']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['flash_error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle me-2"></i><?= e($_SESSION['flash_error']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<div class="row g-4">
    <!-- Left Column: Add / Edit Form -->
    <div class="col-lg-4">
        <div class="card card-salon">
            <div class="card-header bg-white">
                <span class="fw-semibold"><i class="bi bi-<?= $edit_id > 0 ? 'pencil-square' : 'person-plus' ?> text-salon me-2"></i><?= $edit_id > 0 ? 'Edit User Account' : 'Add New User' ?></span>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="user_id" value="<?= $edit_id ?>">

                    <div class="mb-3">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>" value="<?= e($user_edit ? $user_edit['name'] : ($_POST['name'] ?? '')) ?>" required>
                        <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?= e($errors['name']) ?></div><?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control <?= isset($errors['username']) ? 'is-invalid' : '' ?>" value="<?= e($user_edit ? $user_edit['username'] : ($_POST['username'] ?? '')) ?>" required>
                        <div class="form-text small">3-30 chars, letters, numbers, underscores.</div>
                        <?php if (isset($errors['username'])): ?><div class="invalid-feedback"><?= e($errors['username']) ?></div><?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Email Address <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" value="<?= e($user_edit ? $user_edit['email'] : ($_POST['email'] ?? '')) ?>" required>
                        <?php if (isset($errors['email'])): ?><div class="invalid-feedback"><?= e($errors['email']) ?></div><?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Password <?= $edit_id > 0 ? '(Leave blank to keep current)' : '<span class="text-danger">*</span>' ?></label>
                        <input type="password" name="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>" <?= $edit_id === 0 ? 'required' : '' ?>>
                        <?php if (isset($errors['password'])): ?><div class="invalid-feedback"><?= e($errors['password']) ?></div><?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Role <span class="text-danger">*</span></label>
                        <select name="role" class="form-select <?= isset($errors['role']) ? 'is-invalid' : '' ?>" <?= ($edit_id === $current_admin_id) ? 'disabled' : '' ?>>
                            <?php foreach (array('admin' => 'Administrator', 'receptionist' => 'Receptionist', 'stylist' => 'Stylist') as $k => $lbl): ?>
                                <option value="<?= $k ?>" <?= (($user_edit ? $user_edit['role'] : ($_POST['role'] ?? 'receptionist')) === $k) ? 'selected' : '' ?>><?= $lbl ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($edit_id === $current_admin_id): ?>
                            <input type="hidden" name="role" value="admin">
                            <div class="form-text small text-warning">Your own admin role cannot be changed.</div>
                        <?php endif; ?>
                        <?php if (isset($errors['role'])): ?><div class="invalid-feedback"><?= e($errors['role']) ?></div><?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Status <span class="text-danger">*</span></label>
                        <select name="status" class="form-select <?= isset($errors['status']) ? 'is-invalid' : '' ?>" <?= ($edit_id === $current_admin_id) ? 'disabled' : '' ?>>
                            <option value="active" <?= (($user_edit ? $user_edit['status'] : 'active') === 'active') ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= (($user_edit ? $user_edit['status'] : 'active') === 'inactive') ? 'selected' : '' ?>>Inactive</option>
                        </select>
                        <?php if ($edit_id === $current_admin_id): ?>
                            <input type="hidden" name="status" value="active">
                            <div class="form-text small text-warning">You cannot deactivate your own account.</div>
                        <?php endif; ?>
                        <?php if (isset($errors['status'])): ?><div class="invalid-feedback"><?= e($errors['status']) ?></div><?php endif; ?>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-salon flex-grow-1"><i class="bi bi-check2-circle me-1"></i><?= $edit_id > 0 ? 'Save Changes' : 'Create Account' ?></button>
                        <?php if ($edit_id > 0): ?>
                            <a href="<?= $self ?>" class="btn btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Right Column: Account List & Filters -->
    <div class="col-lg-8">
        <div class="card card-salon mb-4">
            <div class="card-body py-3">
                <form method="GET" action="" class="row g-2 align-items-center">
                    <div class="col-md-5">
                        <input type="text" name="q" value="<?= e($search) ?>" class="form-control form-control-sm" placeholder="Search name, username, email...">
                    </div>
                    <div class="col-md-3">
                        <select name="role" class="form-select form-select-sm">
                            <option value="">All Roles</option>
                            <option value="admin" <?= $f_role === 'admin' ? 'selected' : '' ?>>Administrator</option>
                            <option value="receptionist" <?= $f_role === 'receptionist' ? 'selected' : '' ?>>Receptionist</option>
                            <option value="stylist" <?= $f_role === 'stylist' ? 'selected' : '' ?>>Stylist</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All Status</option>
                            <option value="active" <?= $f_status === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $f_status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex gap-1">
                        <button type="submit" class="btn btn-sm btn-dark w-100">Filter</button>
                        <?php if ($search !== '' || $f_role !== '' || $f_status !== ''): ?>
                            <a href="<?= $self ?>" class="btn btn-sm btn-outline-secondary" title="Reset Filters"><i class="bi bi-x-lg"></i></a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="card card-salon">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span><i class="bi bi-list-ul text-salon me-2"></i>Accounts Directory (<?= $total_rows ?>)</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Name / Username</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Last Login</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                                <tr><td colspan="5" class="text-center py-4 text-muted small">No user accounts found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($users as $u): 
                                    $role_badge = array('admin' => 'bg-danger', 'receptionist' => 'bg-primary', 'stylist' => 'bg-success')[$u['role']] ?? 'bg-secondary';
                                    $is_self = ((int)$u['user_id'] === $current_admin_id);
                                ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold text-dark"><?= e($u['name']) ?></div>
                                            <div class="text-muted small">@<?= e($u['username']) ?> · <?= e($u['email']) ?></div>
                                        </td>
                                        <td><span class="badge <?= $role_badge ?>"><?= ucfirst($u['role']) ?></span></td>
                                        <td>
                                            <?php if ($u['status'] === 'active'): ?>
                                                <span class="badge text-bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge text-bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-muted small"><?= $u['last_login'] ? e(date('d M Y, h:i A', strtotime($u['last_login']))) : 'Never' ?></td>
                                        <td class="text-end text-nowrap">
                                            <a href="<?= $self ?>?id=<?= (int)$u['user_id'] ?>" class="btn btn-sm btn-outline-primary me-1" title="Edit User"><i class="bi bi-pencil"></i></a>
                                            <?php if (!$is_self): ?>
                                                <form method="POST" action="" class="d-inline" data-confirm="Are you sure you want to delete this user account?">
                                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Account"><i class="bi bi-trash"></i></button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($total_pages > 1): ?>
                <div class="card-footer bg-white d-flex justify-content-between align-items-center">
                    <span class="small text-muted">Page <?= $page ?> of <?= $total_pages ?></span>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="?page=<?= $page - 1 ?>&q=<?= urlencode($search) ?>&role=<?= urlencode($f_role) ?>&status=<?= urlencode($f_status) ?>">&laquo;</a>
                            </li>
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?>&q=<?= urlencode($search) ?>&role=<?= urlencode($f_role) ?>&status=<?= urlencode($f_status) ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                <a class="page-link" href="?page=<?= $page + 1 ?>&q=<?= urlencode($search) ?>&role=<?= urlencode($f_role) ?>&status=<?= urlencode($f_status) ?>">&raquo;</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/dash_footer.php'; ?>