<?php
/**
 * ============================================================
 *  STAFF - EMPLOYEE DIRECTORY (Step 7)
 *  List + search + status filter + pagination.
 *  Actions handled here (POST): activate/deactivate, delete.
 *  The weekly routine edited here feeds the calendar, the
 *  booking form and the slots endpoint (Steps 5-6 read it).
 *  Access: admin only  (can_manage('staff'))
 * ============================================================
 */

require_once __DIR__ . '/../../includes/init.php';
require_role(array('admin'));

 $self = BASE_URL . 'modules/staff/';

/* ============================================================
 *  POST ACTIONS - toggle / delete (guarded by CSRF)
 * ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = post('action');
    $id     = (int)post('staff_id');

    if ($id <= 0 || !in_array($action, array('delete', 'toggle'), true)) {
        set_flash('warning', 'Unknown action.');
        redirect($self . 'index.php');
    }

    /* the staff member must exist */
    $stmt = mysqli_prepare($conn, "SELECT name, status FROM staff WHERE staff_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $member = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$member) {
        set_flash('danger', 'Staff member not found (maybe already deleted).');
        redirect($self . 'index.php');
    }

    if ($action === 'toggle') {
        /* active <-> inactive (use the form for on_leave) */
        $new  = ($member['status'] === 'active') ? 'inactive' : 'active';
        $stmt = mysqli_prepare($conn, "UPDATE staff SET status = ? WHERE staff_id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $new, $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        set_flash('success', $member['name'] . ' marked as ' . $new . '.');
    } else {
        /* delete only when no appointment history - otherwise
           reports would lose the service/stylist they came with */
        $stmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM appointments WHERE staff_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $appts = (int)mysqli_fetch_row(mysqli_stmt_get_result($stmt))[0];
        mysqli_stmt_close($stmt);

        if ($appts > 0) {
            set_flash('warning', 'Cannot delete: ' . $member['name'] . ' has ' . $appts
                . ' appointment record(s). Mark the profile inactive instead to keep the history.');
        } else {
            /* staff_shifts rows cascade away; a linked login and
               client preferences are released automatically */
            $stmt = mysqli_prepare($conn, "DELETE FROM staff WHERE staff_id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            set_flash('success', 'Staff profile deleted.');
        }
    }
    redirect($self . 'index.php');
}

/* ============================================================
 *  LIST + SEARCH + FILTER + PAGINATION (GET)
 * ========================================================== */
 $q        = get('q');
 $status_f = get('status');
if (!in_array($status_f, array('active', 'on_leave', 'inactive'), true)) {
    $status_f = '';
}
 $page     = max(1, (int)get('page', 1));
 $per_page = 10;
 $offset   = ($page - 1) * $per_page;

 $conds  = array();
 $types  = '';
 $params = array();
if ($q !== '') {
    $conds[]  = '(st.name LIKE ? OR st.specialization LIKE ? OR st.phone LIKE ? OR st.email LIKE ?)';
    $like     = '%' . $q . '%';
    $types   .= 'ssss';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($status_f !== '') {
    $conds[]  = 'st.status = ?';
    $types   .= 's';
    $params[] = $status_f;
}
 $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

/* total rows (for the pager) */
 $stmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM staff st $where");
if ($types !== '') {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
 $total_rows = (int)mysqli_fetch_row(mysqli_stmt_get_result($stmt))[0];
mysqli_stmt_close($stmt);

 $total_pages = max(1, (int)ceil($total_rows / $per_page));
if ($page > $total_pages) {
    $page   = $total_pages;
    $offset = ($page - 1) * $per_page;
}

/* rows: staff + linked login + upcoming appointment count */
 $sql = "SELECT st.*, u.username AS login_username,
               (SELECT COUNT(*) FROM appointments a
                 WHERE a.staff_id = st.staff_id
                   AND a.appointment_date >= CURDATE()
                   AND a.status IN ('scheduled','confirmed')) AS upcoming
        FROM staff st
        LEFT JOIN users u ON u.user_id = st.user_id
        $where
        ORDER BY FIELD(st.status, 'active', 'on_leave', 'inactive'), st.name ASC
        LIMIT ? OFFSET ?";
 $types   .= 'ii';
 $params[] = $per_page;
 $params[] = $offset;

 $stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
 $members = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

/* keep search + filter in the pager links */
 $qs = '';
if ($q !== '')        { $qs .= '&amp;q=' . urlencode($q); }
if ($status_f !== '') { $qs .= '&amp;status=' . urlencode($status_f); }

/* pages to show in the pager (window around the current one) */
 $pages_to_show = array();
for ($p = 1; $p <= $total_pages; $p++) {
    if ($total_pages <= 9 || $p <= 2 || $p > $total_pages - 2 || abs($p - $page) <= 1) {
        $pages_to_show[] = $p;
    }
}

 $status_pill = array(
    'active'   => 'pill-active',
    'on_leave' => 'pill-on_leave',
    'inactive' => 'pill-inactive',
);

 $page_title = 'Staff';
require_once __DIR__ . '/../../includes/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h5 class="mb-1"><i class="bi bi-person-badge"></i> Staff directory</h5>
        <p class="text-muted small mb-0">
            Employee profiles, weekly routines and commission defaults.
            The routine below drives the calendar and the booking form.
        </p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-salon" href="<?= $self ?>commissions.php">
            <i class="bi bi-coin"></i> Commission rates
        </a>
        <a class="btn btn-salon" href="<?= $self ?>form.php"><i class="bi bi-person-plus"></i> Add staff</a>
    </div>
</div>

<div class="card card-salon">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <form class="d-flex search-bar" method="get" action="<?= $self ?>index.php">
            <input type="search" class="form-control" name="q" value="<?= e($q) ?>"
                   placeholder="Search name, specialty, phone or email...">
            <button class="btn btn-outline-salon ms-2" type="submit"><i class="bi bi-search"></i></button>
            <?php if ($q !== '' || $status_f !== ''): ?>
                <a class="btn btn-link text-muted ms-1" href="<?= $self ?>index.php" title="Clear filters"><i class="bi bi-x-lg"></i></a>
            <?php endif; ?>
        </form>
        <form method="get" action="<?= $self ?>index.php" class="d-flex align-items-center gap-2">
            <?php if ($q !== ''): ?>
                <input type="hidden" name="q" value="<?= e($q) ?>">
            <?php endif; ?>
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All statuses</option>
                <option value="active"   <?= $status_f === 'active'   ? 'selected' : '' ?>>Active</option>
                <option value="on_leave" <?= $status_f === 'on_leave' ? 'selected' : '' ?>>On leave</option>
                <option value="inactive" <?= $status_f === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </form>
        <span class="badge text-bg-light border"><?= $total_rows ?> staff</span>
    </div>

    <div class="card-body p-0">
        <?php if (!$members): ?>
            <div class="p-5 text-center text-muted">
                <i class="bi bi-person-badge fs-1 d-block mb-2"></i>
                <?php if ($q !== '' || $status_f !== ''): ?>
                    No staff match your filters.
                <?php else: ?>
                    No staff profiles yet - click <b>Add staff</b> to create the first one.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Stylist</th>
                            <th>Contact</th>
                            <th>Weekly routine</th>
                            <th class="text-center">Commission</th>
                            <th class="text-center">Upcoming</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($members as $m): ?>
                        <tr class="<?= $m['status'] === 'inactive' ? 'table-light' : '' ?>">
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="avatar-circle" style="width:40px;height:40px;flex:0 0 40px;font-size:1rem;">
                                        <?= e(mb_strtoupper(mb_substr($m['name'], 0, 1))) ?>
                                    </span>
                                    <div>
                                        <a class="fw-semibold text-decoration-none" href="<?= $self ?>view.php?id=<?= (int)$m['staff_id'] ?>">
                                            <?= e($m['name']) ?>
                                        </a>
                                        <div class="small text-muted"><?= e($m['specialization'] !== null && $m['specialization'] !== '' ? $m['specialization'] : '-') ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="small">
                                <?= e($m['phone'] !== null && $m['phone'] !== '' ? $m['phone'] : '-') ?>
                                <div class="text-muted"><?= e($m['email'] !== null && $m['email'] !== '' ? $m['email'] : '') ?></div>
                            </td>
                            <td class="small text-nowrap">
                                <?= e(str_replace(',', ', ', $m['work_days'])) ?>
                                <div class="text-muted">
                                    <?= e(date('H:i', strtotime($m['shift_start']))) ?>
                                    - <?= e(date('H:i', strtotime($m['shift_end']))) ?>
                                </div>
                            </td>
                            <td class="text-center"><?= e(rtrim(rtrim($m['commission_rate'], '0'), '.')) ?>%</td>
                            <td class="text-center">
                                <span class="badge <?= $m['upcoming'] > 0 ? 'text-bg-light border' : 'text-bg-light border text-muted' ?>">
                                    <?= (int)$m['upcoming'] ?>
                                </span>
                            </td>
                            <td><span class="pill <?= $status_pill[$m['status']] ?>"><?= e(ucwords(str_replace('_', ' ', $m['status']))) ?></span></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-salon" href="<?= $self ?>view.php?id=<?= (int)$m['staff_id'] ?>" title="View profile">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a class="btn btn-sm btn-outline-secondary" href="<?= $self ?>form.php?id=<?= (int)$m['staff_id'] ?>" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>

                                <form class="d-inline" method="post" action="<?= $self ?>index.php"
                                      data-confirm="<?= $m['status'] === 'active' ? 'Mark this profile as inactive?' : 'Mark this profile as active?' ?>">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="staff_id" value="<?= (int)$m['staff_id'] ?>">
                                    <button class="btn btn-sm btn-outline-warning" type="submit"
                                            title="<?= $m['status'] === 'active' ? 'Deactivate' : 'Activate' ?>">
                                        <i class="bi bi-<?= $m['status'] === 'active' ? 'archive' : 'arrow-counterclockwise' ?>"></i>
                                    </button>
                                </form>

                                <form class="d-inline" method="post" action="<?= $self ?>index.php"
                                      data-confirm="Permanently delete this staff profile? This cannot be undone.">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="staff_id" value="<?= (int)$m['staff_id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="card-footer bg-white d-flex justify-content-between align-items-center">
        <span class="small text-muted">Page <?= $page ?> of <?= $total_pages ?></span>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= $self ?>index.php?page=<?= ($page - 1) . $qs ?>">&laquo;</a>
                </li>
                <?php $prev = 0; foreach ($pages_to_show as $p): ?>
                    <?php if ($p - $prev > 1): ?>
                        <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                    <?php endif; ?>
                    <li class="page-item <?= $p == $page ? 'active' : '' ?>">
                        <a class="page-link" href="<?= $self ?>index.php?page=<?= $p . $qs ?>"><?= $p ?></a>
                    </li>
                <?php $prev = $p; endforeach; ?>
                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= $self ?>index.php?page=<?= ($page + 1) . $qs ?>">&raquo;</a>
                </li>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<p class="text-muted small mt-3 mb-0">
    <i class="bi bi-lightbulb"></i> One-off days (swapped days, overtime, leave) are managed under
    <a href="<?= BASE_URL ?>modules/calendar/shifts.php">Calendar &rsaquo; Shifts &amp; time off</a>;
    this weekly routine is the base schedule for every week.
</p>

<?php require_once __DIR__ . '/../../includes/dash_footer.php'; ?>