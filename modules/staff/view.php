<?php
/**
 * ============================================================
 *  STAFF - PROFILE VIEW (Step 7)
 *  Identity + weekly routine + upcoming appointments + booked
 *  shift exceptions + effective commission per service
 *  (service override wins over the stylist default).
 *  Access: admin only  (can_manage('staff'))
 * ============================================================
 */

require_once __DIR__ . '/../../includes/init.php';
require_role(array('admin'));

 $self = BASE_URL . 'modules/staff/';
 $id   = (int)get('id');

if ($id <= 0) {
    redirect($self . 'index.php');
}

/* ---------- profile + linked login ---------- */
 $stmt = mysqli_prepare($conn,
    "SELECT st.*, u.username, u.email AS login_email, u.status AS login_status
     FROM staff st
     LEFT JOIN users u ON u.user_id = st.user_id
     WHERE st.staff_id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
 $member = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$member) {
    set_flash('danger', 'Staff member not found.');
    redirect($self . 'index.php');
}

/** tiny helper: run a prepared query, return the first column */
function staff_scalar($sql, $types = '', $params = array()) {
    global $conn;
    $stmt = mysqli_prepare($conn, $sql);
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ? $row[0] : 0;
}

/* ---------- counters ---------- */
 $today_count = (int)staff_scalar(
    "SELECT COUNT(*) FROM appointments
     WHERE staff_id = ? AND appointment_date = CURDATE()
       AND status IN ('scheduled','confirmed')", 'i', array($id));

 $upcoming_count = (int)staff_scalar(
    "SELECT COUNT(*) FROM appointments
     WHERE staff_id = ? AND appointment_date > CURDATE()
       AND status IN ('scheduled','confirmed')", 'i', array($id));

 $completed_count = (int)staff_scalar(
    "SELECT COUNT(*) FROM appointments
     WHERE staff_id = ? AND status = 'completed'", 'i', array($id));

/* ---------- upcoming appointments ---------- */
 $stmt = mysqli_prepare($conn,
    "SELECT a.appointment_id, a.appointment_date, a.start_time, a.end_time, a.status,
            c.name AS client_name, s.name AS service_name
     FROM appointments a
     JOIN clients  c ON c.client_id  = a.client_id
     JOIN services s ON s.service_id = a.service_id
     WHERE a.staff_id = ? AND a.appointment_date >= CURDATE()
       AND a.status IN ('scheduled','confirmed')
     ORDER BY a.appointment_date ASC, a.start_time ASC
     LIMIT 8");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
 $upcoming = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

/* ---------- booked shift exceptions (calendar page adds them) ---------- */
 $stmt = mysqli_prepare($conn,
    "SELECT shift_date, start_time, end_time, shift_type, notes
     FROM staff_shifts
     WHERE staff_id = ? AND shift_date >= CURDATE()
     ORDER BY shift_date ASC, start_time ASC
     LIMIT 5");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
 $exceptions = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

/* ---------- effective commission per service ---------- */
 $stmt = mysqli_prepare($conn,
    "SELECT s.name, s.category, s.price, s.commission_rate AS override_rate,
            COALESCE(s.commission_rate, st.commission_rate) AS eff_rate
     FROM services s
     JOIN staff st ON st.staff_id = ?
     WHERE s.is_active = 1
     ORDER BY s.name ASC");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
 $services = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

 $shift_badge = array(
    'regular'  => 'text-bg-secondary',
    'overtime' => 'text-bg-warning',
    'off'      => 'text-bg-danger',
);

 $status_pill = array(
    'active'   => 'pill-active',
    'on_leave' => 'pill-on_leave',
    'inactive' => 'pill-inactive',
);

 $page_title = 'Staff profile';
require_once __DIR__ . '/../../includes/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h5 class="mb-0"><i class="bi bi-person-badge"></i> Staff profile</h5>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= $self ?>index.php">
            <i class="bi bi-arrow-left"></i> Directory
        </a>
        <a class="btn btn-outline-salon btn-sm" href="<?= $self ?>commissions.php">
            <i class="bi bi-coin"></i> Commission rates
        </a>
        <a class="btn btn-salon btn-sm" href="<?= $self ?>form.php?id=<?= $id ?>">
            <i class="bi bi-pencil"></i> Edit profile
        </a>
    </div>
</div>

<div class="row g-3">
    <!-- ---------- left column: identity ---------- -->
    <div class="col-lg-4">
        <div class="card card-salon mb-3">
            <div class="card-body text-center">
                <span class="avatar-circle mx-auto" style="width:72px;height:72px;font-size:2rem;">
                    <?= e(mb_strtoupper(mb_substr($member['name'], 0, 1))) ?>
                </span>
                <h5 class="mt-3 mb-1"><?= e($member['name']) ?></h5>
                <div class="text-muted small mb-2"><?= e($member['specialization'] !== null && $member['specialization'] !== '' ? $member['specialization'] : 'General stylist') ?></div>
                <span class="pill <?= $status_pill[$member['status']] ?>"><?= e(ucwords(str_replace('_', ' ', $member['status']))) ?></span>

                <hr class="my-3">
                <div class="d-flex flex-column gap-1 small text-start">
                    <span><i class="bi bi-envelope text-muted"></i>
                        <?= $member['email'] !== null && $member['email'] !== '' ? e($member['email']) : '<span class="text-muted">no email</span>' ?></span>
                    <span><i class="bi bi-telephone text-muted"></i>
                        <?= $member['phone'] !== null && $member['phone'] !== '' ? e($member['phone']) : '<span class="text-muted">no phone</span>' ?></span>
                    <span><i class="bi bi-calendar-check text-muted"></i> Hired:
                        <?= $member['hire_date'] !== null && $member['hire_date'] !== '' ? e(date('d M Y', strtotime($member['hire_date']))) : '<span class="text-muted">-</span>' ?></span>
                    <span><i class="bi bi-person-circle text-muted"></i> Login:
                        <?php if ($member['username'] !== null): ?>
                            <b><?= e($member['username']) ?></b>
                            <span class="text-muted">(<?= e($member['login_status']) ?>)</span>
                        <?php else: ?>
                            <span class="text-muted">not linked</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- weekly routine -->
        <div class="card card-salon mb-3">
            <div class="card-header"><i class="bi bi-clock"></i> Weekly routine</div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-1 mb-2">
                    <?php foreach (array('Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun') as $d): ?>
                        <span class="badge <?= in_array($d, explode(',', $member['work_days']), true) ? 'text-bg-secondary' : 'text-bg-light border text-muted' ?>">
                            <?= $d ?>
                        </span>
                    <?php endforeach; ?>
                </div>
                <div class="fw-semibold">
                    <i class="bi bi-hourglass-split"></i>
                    <?= e(date('H:i', strtotime($member['shift_start']))) ?>
                    - <?= e(date('H:i', strtotime($member['shift_end']))) ?>
                </div>
                <div class="form-text mb-0">Date-specific exceptions (overtime, swapped days, leave)
                    are managed under <a href="<?= BASE_URL ?>modules/calendar/shifts.php">Calendar &rsaquo; Shifts</a>.</div>
            </div>
        </div>

        <!-- quick counters -->
        <div class="row g-3">
            <div class="col-4">
                <div class="stat-card text-center">
                    <div class="stat-label">Today</div>
                    <div class="stat-value"><?= $today_count ?></div>
                </div>
            </div>
            <div class="col-4">
                <div class="stat-card text-center">
                    <div class="stat-label">Upcoming</div>
                    <div class="stat-value"><?= $upcoming_count ?></div>
                </div>
            </div>
            <div class="col-4">
                <div class="stat-card text-center">
                    <div class="stat-label">Completed</div>
                    <div class="stat-value"><?= $completed_count ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ---------- right column: bookings + commissions ---------- -->
    <div class="col-lg-8">
        <!-- upcoming appointments -->
        <div class="card card-salon mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-calendar2-check"></i> Upcoming appointments</span>
                <a class="btn btn-sm btn-outline-salon" href="<?= BASE_URL ?>modules/appointments/form.php?staff_id=<?= $id ?>">
                    <i class="bi bi-calendar-plus"></i> Book for <?= e(explode(' ', $member['name'])[0]) ?>
                </a>
            </div>
            <div class="card-body p-0">
                <?php if (!$upcoming): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="bi bi-moon-stars fs-3 d-block mb-2"></i>
                        No upcoming bookings for this stylist.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th>When</th>
                                    <th>Client</th>
                                    <th>Service</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($upcoming as $a): ?>
                                <tr>
                                    <td class="text-nowrap">
                                        <b><?= e(date('d M', strtotime($a['appointment_date']))) ?></b>
                                        <span class="small text-muted"><?= e(date('D', strtotime($a['appointment_date']))) ?></span>
                                        <div class="small text-muted"><?= e(date('h:i A', strtotime($a['start_time']))) ?>
                                            - <?= e(date('h:i A', strtotime($a['end_time']))) ?></div>
                                    </td>
                                    <td><?= e($a['client_name']) ?></td>
                                    <td><?= e($a['service_name']) ?></td>
                                    <td><span class="pill pill-<?= e($a['status']) ?>"><?= e(ucwords(str_replace('_', ' ', $a['status']))) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- shift exceptions -->
        <div class="card card-salon mb-3">
            <div class="card-header"><i class="bi bi-clock-history"></i> Booked shift exceptions (next 5)</div>
            <div class="card-body p-0">
                <?php if (!$exceptions): ?>
                    <div class="p-3 text-center text-muted small">Nothing recorded - the weekly routine applies every day.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead>
                                <tr><th>Date</th><th>Window</th><th>Type</th><th>Notes</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($exceptions as $ex): ?>
                                <tr>
                                    <td class="text-nowrap"><b><?= e(date('d M Y', strtotime($ex['shift_date']))) ?></b>
                                        <span class="small text-muted"><?= e(date('D', strtotime($ex['shift_date']))) ?></span></td>
                                    <td class="text-nowrap"><?= e(date('h:i A', strtotime($ex['start_time']))) ?>
                                        - <?= e(date('h:i A', strtotime($ex['end_time']))) ?></td>
                                    <td><span class="badge <?= $shift_badge[$ex['shift_type']] ?>"><?= e(ucfirst($ex['shift_type'])) ?></span></td>
                                    <td class="small"><?= $ex['notes'] !== null && $ex['notes'] !== '' ? e($ex['notes']) : '-' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- effective commissions -->
        <div class="card card-salon">
            <div class="card-header"><i class="bi bi-coin"></i> Effective commission per service</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Service</th>
                                <th class="text-end">Price</th>
                                <th class="text-center">Rate</th>
                                <th class="text-end">Earns per service</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($services as $sv): ?>
                            <tr>
                                <td>
                                    <?= e($sv['name']) ?>
                                    <?php if ($sv['override_rate'] !== null): ?>
                                        <span class="badge text-bg-light border" title="This service has its own override">override</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?= money($sv['price']) ?></td>
                                <td class="text-center">
                                    <span class="<?= $sv['override_rate'] !== null ? 'fw-bold' : '' ?>"><?= e(rtrim(rtrim($sv['eff_rate'], '0'), '.')) ?>%</span>
                                </td>
                                <td class="text-end"><?= money($sv['price'] * $sv['eff_rate'] / 100) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-white small text-muted">
                Default rate <b><?= e(rtrim(rtrim($member['commission_rate'], '0'), '.')) ?>%</b> applies
                unless a service has its own override (marked above).
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/dash_footer.php'; ?>