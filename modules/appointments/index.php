<?php
/**
 * ============================================================
 *  APPOINTMENTS - LIST + FILTERS + STATUS ACTIONS
 *  Roles: admin + receptionist see and manage every booking;
 *  stylists see only their own schedule.
 *  Quick ranges (Today / Tomorrow / Upcoming / Past / All),
 *  optional status + stylist + client-name filters, pagination.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/init.php';
require_role(array('admin', 'receptionist', 'stylist'));

 $self       = BASE_URL . 'modules/appointments/';
 $u          = current_user();
 $is_stylist = ($u['role'] === 'stylist');

/* ---------- stylist: find own staff row ---------- */
 $my_staff_id = 0;
if ($is_stylist) {
    $stmt = mysqli_prepare($conn, "SELECT staff_id FROM staff WHERE user_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $u['user_id']);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    $my_staff_id = $row ? (int)$row['staff_id'] : 0;
}

/* ---------- status transition map ---------- */
 $transitions = array(
    'scheduled' => array('confirmed', 'cancelled', 'no_show'),
    'confirmed' => array('completed', 'cancelled', 'no_show'),
    'cancelled' => array('scheduled'),
    'no_show'   => array('scheduled'),
    'completed' => array(),
);
 $allowed_actions = array(
    'confirm'  => 'confirmed',
    'complete' => 'completed',
    'cancel'   => 'cancelled',
    'no_show'  => 'no_show',
    'reopen'   => 'scheduled',
);

/* ---------- filters (GET, all whitelisted) ---------- */
 $range = get('range', 'today');
if (!in_array($range, array('today', 'tomorrow', 'upcoming', 'past', 'all'), true)) {
    $range = 'today';
}
 $status = get('status');
if (!in_array($status, array('scheduled', 'confirmed', 'completed', 'cancelled', 'no_show'), true)) {
    $status = '';
}
 $staff_filter = (int)get('staff_id');
 $q            = get('q');
 $page         = max(1, (int)get('page', 1));
 $per_page     = 10;

/* keep the current filters in links + redirects */
 $filter_params = array();
if ($range !== 'today')                    { $filter_params['range'] = $range; }
if ($status !== '')                        { $filter_params['status'] = $status; }
if (!$is_stylist && $staff_filter > 0)     { $filter_params['staff_id'] = $staff_filter; }
if ($q !== '')                             { $filter_params['q'] = $q; }
 $back_qs = http_build_query($filter_params);
 $return_url = $self . 'index.php' . ($back_qs !== '' ? '?' . $back_qs : '');

/* ---------- POST status actions (shared handler) ---------- */
require_once __DIR__ . '/_actions.php';

/* ============================================================
 *  LIST QUERY
 * ========================================================== */
 $where  = '';
 $types  = '';
 $params = array();

if ($range === 'today') {
    $where = ' WHERE a.appointment_date = CURDATE()';
} elseif ($range === 'tomorrow') {
    $where = ' WHERE a.appointment_date = CURDATE() + INTERVAL 1 DAY';
} elseif ($range === 'upcoming') {
    $where = ' WHERE a.appointment_date >= CURDATE()';
} elseif ($range === 'past') {
    $where = ' WHERE a.appointment_date < CURDATE()';
}

if ($is_stylist) {
    if (!$my_staff_id) {
        /* stylist login without a staff profile -> nothing to show */
        $where .= ($where ? ' AND ' : ' WHERE ') . '1 = 0';
    } else {
        $where   .= ($where ? ' AND ' : ' WHERE ') . 'a.staff_id = ?';
        $types   .= 'i';
        $params[] = $my_staff_id;
    }
} elseif ($staff_filter > 0) {
    $where   .= ($where ? ' AND ' : ' WHERE ') . 'a.staff_id = ?';
    $types   .= 'i';
    $params[] = $staff_filter;
}

if ($status !== '') {
    $where   .= ($where ? ' AND ' : ' WHERE ') . 'a.status = ?';
    $types   .= 's';
    $params[] = $status;
}
if ($q !== '') {
    $where   .= ($where ? ' AND ' : ' WHERE ') . 'c.name LIKE ?';
    $types   .= 's';
    $params[] = '%' . $q . '%';
}

/* total rows for the pager */
 $stmt = mysqli_prepare($conn,
    "SELECT COUNT(*)
     FROM appointments a
     JOIN clients c ON c.client_id = a.client_id
     $where");
if ($types !== '') {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
 $total_rows = (int)mysqli_fetch_row(mysqli_stmt_get_result($stmt))[0];
mysqli_stmt_close($stmt);

 $total_pages = max(1, (int)ceil($total_rows / $per_page));
if ($page > $total_pages) {
    $page   = $total_pages;
}
 $offset = ($page - 1) * $per_page;

/* rows for this page */
 $sql = "SELECT a.appointment_id, a.appointment_date, a.start_time, a.end_time,
               a.status, a.notes,
               c.client_id, c.name AS client_name, c.phone AS client_phone,
               st.name AS staff_name, s.name AS service_name, s.duration_minutes
        FROM appointments a
        JOIN clients  c  ON c.client_id  = a.client_id
        JOIN staff    st ON st.staff_id  = a.staff_id
        JOIN services s  ON s.service_id = a.service_id
        $where
        ORDER BY a.appointment_date ASC, a.start_time ASC
        LIMIT ? OFFSET ?";
 $row_types   = $types . 'ii';
 $row_params  = $params;
 $row_params[] = $per_page;
 $row_params[] = $offset;

 $stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $row_types, ...$row_params);
mysqli_stmt_execute($stmt);
 $appointments = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

/* stylist dropdown for the filter (staff-side only) */
 $stylists = array();
if (!$is_stylist) {
    $res = mysqli_query($conn,
        "SELECT staff_id, name FROM staff WHERE status = 'active' ORDER BY name ASC");
    while ($row = mysqli_fetch_assoc($res)) {
        $stylists[] = $row;
    }
}

/* labels for the status action buttons */
 $action_ui = array(
    'confirmed' => array('label' => 'Confirm',  'icon' => 'bi-check2-circle',         'btn' => 'btn-outline-success', 'confirm' => ''),
    'completed' => array('label' => 'Complete', 'icon' => 'bi-clipboard-check',       'btn' => 'btn-outline-primary', 'confirm' => ''),
    'cancelled' => array('label' => 'Cancel',   'icon' => 'bi-x-circle',              'btn' => 'btn-outline-danger',  'confirm' => 'Cancel this appointment? The client will be notified.'),
    'no_show'   => array('label' => 'No-show',  'icon' => 'bi-person-dash',           'btn' => 'btn-outline-warning', 'confirm' => 'Mark this client as a no-show?'),
    'scheduled' => array('label' => 'Reopen',   'icon' => 'bi-arrow-counterclockwise','btn' => 'btn-outline-secondary','confirm' => ''),
);

/* links keep the filters (escaped for href) */
 $qs = ($back_qs !== '') ? '&amp;' . e($back_qs) : '';

 $pages_to_show = array();
for ($p = 1; $p <= $total_pages; $p++) {
    if ($total_pages <= 9 || $p <= 2 || $p > $total_pages - 2 || abs($p - $page) <= 1) {
        $pages_to_show[] = $p;
    }
}

 $page_title = 'Appointments';
require_once __DIR__ . '/../../includes/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h5 class="mb-1">Appointment book</h5>
        <p class="text-muted small mb-0">
            <?= $is_stylist ? 'Your personal schedule - mark visits complete or cancelled.'
                            : 'Book, confirm, reschedule and track every salon visit.' ?>
        </p>
    </div>
    <?php if (!$is_stylist): ?>
        <a class="btn btn-salon" href="<?= $self ?>form.php"><i class="bi bi-calendar-plus"></i> New appointment</a>
    <?php endif; ?>
</div>

<?php if ($is_stylist && !$my_staff_id): ?>
    <div class="alert alert-warning">
        <i class="bi bi-info-circle"></i> Your login is not linked to a staff profile yet,
        so no schedule can be shown. Ask the administrator to add you under <b>Staff</b>.
    </div>
<?php endif; ?>

<!-- ---------- quick ranges ---------- -->
<div class="btn-group btn-group-sm mb-3" role="group">
    <?php
    $ranges = array(
        'today'    => 'Today',
        'tomorrow' => 'Tomorrow',
        'upcoming' => 'Upcoming',
        'past'     => 'Past',
        'all'      => 'All',
    );
    foreach ($ranges as $key => $label): ?>
        <a class="btn <?= $range === $key ? 'btn-salon' : 'btn-outline-salon' ?>"
           href="<?= $self ?>index.php?range=<?= $key ?>"><?= $label ?></a>
    <?php endforeach; ?>
</div>

<!-- ---------- filter bar ---------- -->
<div class="card card-salon mb-3">
    <div class="card-body py-2">
        <form class="row g-2 align-items-center" method="get" action="<?= $self ?>index.php">
            <input type="hidden" name="range" value="<?= e($range) ?>">
            <div class="col-6 col-md-3">
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All statuses</option>
                    <?php foreach (array('scheduled','confirmed','completed','cancelled','no_show') as $st): ?>
                        <option value="<?= $st ?>" <?= $status === $st ? 'selected' : '' ?>>
                            <?= ucwords(str_replace('_', ' ', $st)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!$is_stylist): ?>
            <div class="col-6 col-md-3">
                <select name="staff_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All stylists</option>
                    <?php foreach ($stylists as $s): ?>
                        <option value="<?= (int)$s['staff_id'] ?>" <?= $staff_filter === (int)$s['staff_id'] ? 'selected' : '' ?>>
                            <?= e($s['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-8 col-md-4">
                <input type="search" name="q" class="form-control form-control-sm"
                       placeholder="Search client name..." value="<?= e($q) ?>">
            </div>
            <div class="col-4 col-md-2 d-flex gap-2 justify-content-end">
                <button class="btn btn-sm btn-outline-salon flex-grow-1" type="submit">Go</button>
                <?php if ($q !== '' || $status !== '' || $staff_filter > 0): ?>
                    <a class="btn btn-sm btn-link text-muted" href="<?= $self ?>index.php?range=<?= e($range) ?>" title="Clear filters"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <div class="card-footer bg-white py-1">
        <span class="badge text-bg-light border"><?= $total_rows ?> appointment<?= $total_rows == 1 ? '' : 's' ?></span>
    </div>
</div>

<!-- ---------- list ---------- -->
<div class="card card-salon">
    <div class="card-body p-0">
        <?php if (!$appointments): ?>
            <div class="p-5 text-center text-muted">
                <i class="bi bi-calendar-x fs-1 d-block mb-2"></i>
                No appointments for this view.
                <?php if (!$is_stylist): ?>
                    Click <b>New appointment</b> to book one.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Client</th>
                            <th>Service</th>
                            <?php if (!$is_stylist): ?><th>Stylist</th><?php endif; ?>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($appointments as $a): ?>
                        <tr class="<?= $a['appointment_date'] < date('Y-m-d') && in_array($a['status'], array('scheduled','confirmed'), true) ? 'table-warning' : '' ?>">
                            <td class="text-nowrap">
                                <b><?= e(date('d M Y', strtotime($a['appointment_date']))) ?></b>
                                <div class="small text-muted"><?= e(date('D', strtotime($a['appointment_date']))) ?></div>
                            </td>
                            <td class="text-nowrap">
                                <?= e(date('h:i A', strtotime($a['start_time']))) ?>
                                <div class="small text-muted">- <?= e(date('h:i A', strtotime($a['end_time']))) ?></div>
                            </td>
                            <td>
                                <?php if (!$is_stylist): ?>
                                    <a class="fw-semibold text-decoration-none" href="<?= BASE_URL ?>modules/clients/view.php?id=<?= (int)$a['client_id'] ?>">
                                        <?= e($a['client_name']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="fw-semibold"><?= e($a['client_name']) ?></span>
                                <?php endif; ?>
                                <div class="small text-muted"><?= e($a['client_phone']) ?></div>
                            </td>
                            <td>
                                <?= e($a['service_name']) ?>
                                <div class="small text-muted"><?= (int)$a['duration_minutes'] ?> min</div>
                            </td>
                            <?php if (!$is_stylist): ?><td><?= e($a['staff_name']) ?></td><?php endif; ?>
                            <td><span class="pill pill-<?= e($a['status']) ?>"><?= e(ucwords(str_replace('_', ' ', $a['status']))) ?></span></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" href="<?= $self ?>view.php?id=<?= (int)$a['appointment_id'] ?>" title="View details">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?php if (!$is_stylist && in_array($a['status'], array('scheduled','confirmed'), true)): ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= $self ?>form.php?id=<?= (int)$a['appointment_id'] ?>" title="Reschedule / edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                <?php endif; ?>
                                <?php foreach ($transitions[$a['status']] as $to): ?>
                                    <form class="d-inline" method="post" action="<?= $self ?>index.php<?= $qs !== '' ? '?' . $qs : '' ?>"
                                          <?= $action_ui[$to]['confirm'] !== '' ? 'data-confirm="' . e($action_ui[$to]['confirm']) . '"' : '' ?>>
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="<?= array_search($to, $allowed_actions, true) ?>">
                                        <input type="hidden" name="appointment_id" value="<?= (int)$a['appointment_id'] ?>">
                                        <button class="btn btn-sm <?= $action_ui[$to]['btn'] ?>" type="submit"
                                                title="<?= $action_ui[$to]['label'] ?>">
                                            <i class="bi <?= $action_ui[$to]['icon'] ?>"></i>
                                        </button>
                                    </form>
                                <?php endforeach; ?>
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
    <i class="bi bi-info-circle"></i> Status flow: <b>Scheduled &rarr; Confirmed &rarr; Completed</b>,
    with <b>Cancel</b> / <b>No-show</b> from either open state. Cancelled and no-show bookings can be reopened.
</p>

<?php require_once __DIR__ . '/../../includes/dash_footer.php'; ?>