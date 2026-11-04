<?php
/**
 * ============================================================
 *  DASHBOARD - one smart page that adapts to the role:
 *    admin        -> salon-wide stats + today's appointments
 *    receptionist -> front-desk stats + today's appointments
 *    stylist      -> own schedule, own commissions
 * ============================================================
 */

require_once __DIR__ . '/../../includes/init.php';
require_login();

$u    = current_user();
$role = $u['role'];

/** tiny helper: run a prepared query, return the first column */
function scalar($sql, $types = '', $params = array()) {
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

$stats   = array();
$styx    = 0;   // this stylist's staff_id (0 = not linked yet)

/* ---------- STYLIST: own numbers ---------- */
if ($role === 'stylist') {
    $stmt = mysqli_prepare($conn, "SELECT staff_id FROM staff WHERE user_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $u['user_id']);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    $styx = $row ? (int)$row['staff_id'] : 0;

    $stats['my_today']       = 0;
    $stats['my_upcoming']    = 0;
    $stats['my_done_today']  = 0;
    $stats['my_commission']  = 0;

    if ($styx) {
        $stats['my_today'] = scalar(
            "SELECT COUNT(*) FROM appointments
             WHERE staff_id = ? AND appointment_date = CURDATE()
               AND status IN ('scheduled','confirmed')", 'i', array($styx));

        $stats['my_upcoming'] = scalar(
            "SELECT COUNT(*) FROM appointments
             WHERE staff_id = ? AND appointment_date > CURDATE()
               AND status IN ('scheduled','confirmed')", 'i', array($styx));

        $stats['my_done_today'] = scalar(
            "SELECT COUNT(*) FROM appointments
             WHERE staff_id = ? AND appointment_date = CURDATE()
               AND status = 'completed'", 'i', array($styx));

        // completed services this month x commission rate
        // (service rate overrides the stylist's default when set)
        $stats['my_commission'] = scalar(
            "SELECT COALESCE(SUM(s.price * COALESCE(s.commission_rate, st.commission_rate) / 100), 0)
             FROM appointments a
             JOIN services s ON s.service_id = a.service_id
             JOIN staff st   ON st.staff_id  = a.staff_id
             WHERE a.staff_id = ? AND a.status = 'completed'
               AND DATE_FORMAT(a.appointment_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')",
            'i', array($styx));
    }
}

/* ---------- ADMIN / RECEPTIONIST: salon-wide numbers ---------- */
if ($role === 'admin' || $role === 'receptionist') {
    $stats['today_appts'] = scalar(
        "SELECT COUNT(*) FROM appointments
         WHERE appointment_date = CURDATE()
           AND status IN ('scheduled','confirmed')");

    $stats['total_clients'] = scalar("SELECT COUNT(*) FROM clients WHERE is_active = 1");

    $stats['low_stock'] = scalar(
        "SELECT COUNT(*) FROM inventory_items WHERE quantity <= reorder_level");

    $stats['month_revenue'] = scalar(
        "SELECT COALESCE(SUM(total_amount), 0) FROM payments
         WHERE status = 'paid'
           AND DATE_FORMAT(payment_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')");

    if ($role === 'admin') {
        $stats['active_staff']      = scalar("SELECT COUNT(*) FROM staff WHERE status = 'active'");
        $stats['pending_feedback']  = scalar("SELECT COUNT(*) FROM feedback WHERE status = 'new'");
    }
}

/* ---------- today's appointment list ---------- */
$today = array();
$sql_today = "SELECT a.appointment_id, a.start_time, a.end_time, a.status,
                     c.name AS client_name, st.name AS staff_name, s.name AS service_name
              FROM appointments a
              JOIN clients  c  ON c.client_id  = a.client_id
              JOIN staff    st ON st.staff_id  = a.staff_id
              JOIN services s  ON s.service_id = a.service_id
              WHERE a.appointment_date = CURDATE()
                AND a.status IN ('scheduled','confirmed')";
$params_today = array();
$types_today  = '';

if ($role === 'stylist' && $styx) {
    $sql_today   .= " AND a.staff_id = ?";
    $types_today  = 'i';
    $params_today = array($styx);
}
$sql_today .= " ORDER BY a.start_time LIMIT 10";

$stmt = mysqli_prepare($conn, $sql_today);
if ($types_today !== '') {
    mysqli_stmt_bind_param($stmt, $types_today, ...$params_today);
}
mysqli_stmt_execute($stmt);
$res_today = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($res_today)) {
    $today[] = $row;
}
mysqli_stmt_close($stmt);

$page_title = 'Dashboard';
require_once __DIR__ . '/../../includes/dash_header.php';
?>

<?php if ($role === 'stylist' && !$styx): ?>
    <div class="alert alert-warning">
        <i class="bi bi-info-circle"></i> Your login is not linked to a staff profile yet.
        Ask the administrator to add you under <b>Staff</b> so your schedule appears here.
    </div>
<?php endif; ?>

<!-- ---------------- stat cards ---------------- -->
<div class="row g-3 mb-4">

<?php if ($role === 'admin' || $role === 'receptionist'): ?>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <span class="stat-icon"><i class="bi bi-calendar2-check"></i></span>
            <div class="stat-label mt-2">Today's appointments</div>
            <div class="stat-value"><?= (int)$stats['today_appts'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <span class="stat-icon"><i class="bi bi-people"></i></span>
            <div class="stat-label mt-2">Active clients</div>
            <div class="stat-value"><?= (int)$stats['total_clients'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <span class="stat-icon"><i class="bi bi-cash-stack"></i></span>
            <div class="stat-label mt-2">Revenue this month</div>
            <div class="stat-value"><?= money($stats['month_revenue']) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <span class="stat-icon"><i class="bi bi-box-seam"></i></span>
            <div class="stat-label mt-2">Low stock items</div>
            <div class="stat-value"><?= (int)$stats['low_stock'] ?></div>
        </div>
    </div>
<?php endif; ?>

<?php if ($role === 'admin'): ?>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <span class="stat-icon"><i class="bi bi-person-badge"></i></span>
            <div class="stat-label mt-2">Active staff</div>
            <div class="stat-value"><?= (int)$stats['active_staff'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <span class="stat-icon"><i class="bi bi-chat-heart"></i></span>
            <div class="stat-label mt-2">New feedback</div>
            <div class="stat-value"><?= (int)$stats['pending_feedback'] ?></div>
        </div>
    </div>
<?php endif; ?>

<?php if ($role === 'stylist'): ?>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <span class="stat-icon"><i class="bi bi-calendar2-check"></i></span>
            <div class="stat-label mt-2">My appointments today</div>
            <div class="stat-value"><?= (int)$stats['my_today'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <span class="stat-icon"><i class="bi bi-calendar-week"></i></span>
            <div class="stat-label mt-2">Upcoming</div>
            <div class="stat-value"><?= (int)$stats['my_upcoming'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <span class="stat-icon"><i class="bi bi-check2-circle"></i></span>
            <div class="stat-label mt-2">Done today</div>
            <div class="stat-value"><?= (int)$stats['my_done_today'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <span class="stat-icon"><i class="bi bi-coin"></i></span>
            <div class="stat-label mt-2">Commission this month</div>
            <div class="stat-value"><?= money($stats['my_commission']) ?></div>
        </div>
    </div>
<?php endif; ?>
</div>

<!-- ---------------- today's appointments ---------------- -->
<div class="card card-salon">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clock-history"></i>
            <?= $role === 'stylist' ? 'My schedule today' : "Today's appointments" ?>
            (<?= date('d M Y') ?>)</span>
        <span class="badge text-bg-light border"><?= count($today) ?> shown</span>
    </div>
    <div class="card-body p-0">
        <?php if (!$today): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-moon-stars fs-3 d-block mb-2"></i>
                No appointments booked for today yet.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Client</th>
                            <th>Service</th>
                            <?php if ($role !== 'stylist'): ?><th>Stylist</th><?php endif; ?>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($today as $a): ?>
                        <tr>
                            <td class="fw-semibold"><?= date('h:i A', strtotime($a['start_time'])) ?>
                                - <?= date('h:i A', strtotime($a['end_time'])) ?></td>
                            <td><?= e($a['client_name']) ?></td>
                            <td><?= e($a['service_name']) ?></td>
                            <?php if ($role !== 'stylist'): ?><td><?= e($a['staff_name']) ?></td><?php endif; ?>
                            <td><span class="pill pill-<?= e($a['status']) ?>"><?= e(ucwords(str_replace('_', ' ', $a['status']))) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<p class="text-muted small mt-4 mb-0">
    <i class="bi bi-lightbulb"></i> Tip: menu items marked with a step number unlock as we build
    each module in the next steps.
</p>

<?php require_once __DIR__ . '/../../includes/dash_footer.php'; ?>
