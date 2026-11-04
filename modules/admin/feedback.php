<?php
/**
 * ============================================================
 *  ADMIN - FEEDBACK & CONTACT MESSAGES (Step 11)
 *  Review customer inquiries, star ratings, and messages.
 * ============================================================
 */
require_once __DIR__ . '/../../includes/init.php';
require_role(array('admin'));

$self = BASE_URL . 'modules/admin/feedback.php';

// ---------- Handle Actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';
    $id     = isset($_POST['feedback_id']) ? (int)$_POST['feedback_id'] : 0;

    if ($action === 'toggle_status' && $id > 0) {
        $new_st = isset($_POST['new_status']) && $_POST['new_status'] === 'reviewed' ? 'reviewed' : 'pending';
        $stmt = mysqli_prepare($conn, "UPDATE feedback SET status = ? WHERE feedback_id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $new_st, $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['flash_success'] = 'Feedback status updated.';
        redirect($self);
    } elseif ($action === 'delete' && $id > 0) {
        $stmt = mysqli_prepare($conn, "DELETE FROM feedback WHERE feedback_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['flash_success'] = 'Feedback message deleted.';
        redirect($self);
    }
}

// ---------- KPI Queries ----------
$tot_res = mysqli_query($conn, "SELECT COUNT(*) AS cnt, COALESCE(AVG(rating), 0) AS avg_rt FROM feedback");
$tot_row = mysqli_fetch_assoc($tot_res);
$total_count = (int)$tot_row['cnt'];
$avg_rating  = (float)$tot_row['avg_rt'];

$pend_res = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM feedback WHERE status = 'pending'");
$pending_count = (int)mysqli_fetch_assoc($pend_res)['cnt'];

// ---------- Filters & Pagination ----------
$search   = isset($_GET['q']) ? trim($_GET['q']) : '';
$f_status = isset($_GET['status']) ? trim($_GET['status']) : '';
$f_rating = isset($_GET['rating']) ? (int)$_GET['rating'] : 0;
$page     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit    = 10;
$offset   = ($page - 1) * $limit;

$where = array('1=1');
$types = '';
$params = array();

if ($search !== '') {
    $where[] = "(name LIKE ? OR email LIKE ? OR message LIKE ?)";
    $like = "%{$search}%";
    $types .= 'sss';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($f_status !== '') {
    $where[] = "status = ?";
    $types .= 's';
    $params[] = $f_status;
}
if ($f_rating > 0 && $f_rating <= 5) {
    $where[] = "rating = ?";
    $types .= 'i';
    $params[] = $f_rating;
}

$where_sql = implode(' AND ', $where);

// Count
$cnt_sql = "SELECT COUNT(*) AS cnt FROM feedback WHERE {$where_sql}";
$stmt = mysqli_prepare($conn, $cnt_sql);
if (!empty($types)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$total_rows = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['cnt'];
mysqli_stmt_close($stmt);
$total_pages = max(1, ceil($total_rows / $limit));

// Fetch
$data_sql = "SELECT * FROM feedback WHERE {$where_sql} ORDER BY created_at DESC LIMIT ?, ?";
$stmt = mysqli_prepare($conn, $data_sql);
$types_limit = $types . 'ii';
$params_limit = array_merge($params, array($offset, $limit));
mysqli_stmt_bind_param($stmt, $types_limit, ...$params_limit);
mysqli_stmt_execute($stmt);
$feedbacks = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$page_title = 'Customer Feedback & Messages';
require_once __DIR__ . '/../../includes/dash_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
    <div>
        <h1 class="h3 mb-1 fw-bold text-dark"><i class="bi bi-chat-heart text-salon me-2"></i>Customer Feedback & Inquiries</h1>
        <p class="text-muted small mb-0">Review messages submitted via the public contact form and customer ratings.</p>
    </div>
</div>

<?php if (isset($_SESSION['flash_success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-2"></i><?= e($_SESSION['flash_success']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card card-salon border-start border-4 border-primary h-100">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Total Submissions</div>
                <div class="fs-4 fw-bold text-dark mt-1"><?= number_format($total_count) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-salon border-start border-4 border-warning h-100">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Pending Review</div>
                <div class="fs-4 fw-bold text-dark mt-1"><?= number_format($pending_count) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-salon border-start border-4 border-success h-100">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Average Rating</div>
                <div class="fs-4 fw-bold text-dark mt-1"><?= number_format($avg_rating, 1) ?> <span class="text-warning fs-5">★</span></div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card card-salon mb-4">
    <div class="card-body py-3">
        <form method="GET" action="" class="row g-2 align-items-center">
            <div class="col-md-5">
                <input type="text" name="q" value="<?= e($search) ?>" class="form-control form-control-sm" placeholder="Search name, email, message...">
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <option value="pending" <?= $f_status === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="reviewed" <?= $f_status === 'reviewed' ? 'selected' : '' ?>>Reviewed</option>
                </select>
            </div>
            <div class="col-md-2">
                <select name="rating" class="form-select form-select-sm">
                    <option value="">All Ratings</option>
                    <?php for($r=5; $r>=1; $r--): ?>
                        <option value="<?= $r ?>" <?= $f_rating === $r ? 'selected' : '' ?>><?= $r ?> Stars</option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-dark w-100">Filter</button>
                <?php if ($search !== '' || $f_status !== '' || $f_rating > 0): ?>
                    <a href="<?= $self ?>" class="btn btn-sm btn-outline-secondary" title="Reset Filters"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Feedback Table -->
<div class="card card-salon">
    <div class="card-header bg-white"><i class="bi bi-table text-salon me-2"></i>Feedback List</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Rating</th>
                        <th>Message</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($feedbacks)): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted small">No feedback messages found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($feedbacks as $fb): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= e($fb['name']) ?></div>
                                    <div class="text-muted small"><?= e($fb['email']) ?></div>
                                    <?php if (!empty($fb['phone'])): ?><div class="text-muted small"><?= e($fb['phone']) ?></div><?php endif; ?>
                                </td>
                                <td>
                                    <span class="text-warning">
                                        <?php for($i=1; $i<=5; $i++): ?>
                                            <?= $i <= (int)$fb['rating'] ? '★' : '☆' ?>
                                        <?php endfor; ?>
                                    </span>
                                </td>
                                <td style="max-width: 320px;">
                                    <div class="text-truncate" title="<?= e($fb['message']) ?>"><?= e($fb['message']) ?></div>
                                </td>
                                <td class="text-muted small text-nowrap"><?= e(date('d M Y, h:i A', strtotime($fb['created_at']))) ?></td>
                                <td>
                                    <?php if ($fb['status'] === 'reviewed'): ?>
                                        <span class="badge text-bg-success">Reviewed</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-warning text-dark">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <form method="POST" action="" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="feedback_id" value="<?= (int)$fb['feedback_id'] ?>">
                                        <?php if ($fb['status'] === 'pending'): ?>
                                            <input type="hidden" name="new_status" value="reviewed">
                                            <button type="submit" class="btn btn-sm btn-outline-success me-1" title="Mark as Reviewed"><i class="bi bi-check-lg"></i></button>
                                        <?php else: ?>
                                            <input type="hidden" name="new_status" value="pending">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary me-1" title="Mark as Pending"><i class="bi bi-arrow-counterclockwise"></i></button>
                                        <?php endif; ?>
                                    </form>
                                    <form method="POST" action="" class="d-inline" data-confirm="Delete this feedback message permanently?">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="feedback_id" value="<?= (int)$fb['feedback_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                    </form>
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
                        <a class="page-link" href="?page=<?= $page - 1 ?>&q=<?= urlencode($search) ?>&status=<?= urlencode($f_status) ?>&rating=<?= $f_rating ?>">&laquo;</a>
                    </li>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $i ?>&q=<?= urlencode($search) ?>&status=<?= urlencode($f_status) ?>&rating=<?= $f_rating ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page + 1 ?>&q=<?= urlencode($search) ?>&status=<?= urlencode($f_status) ?>&rating=<?= $f_rating ?>">&raquo;</a>
                    </li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/dash_footer.php'; ?>