<?php
/**
 * ============================================================
 *  REPORTS - SALON PERFORMANCE (Step 10, admin only)
 *  One page, one date range (defaults to this month):
 *    - KPI cards: collected revenue, invoices, completed
 *      appointments, new clients, average invoice value
 *    - Daily revenue bar chart (pure CSS, no JS library)
 *    - Revenue by payment method
 *    - Top services (completed bookings + service value)
 *    - Stylist performance (bookings, service value,
 *      estimated commission using per-service override)
 *    - Appointment status breakdown
 *    - Top clients (visits + spend)
 *    - Low stock snapshot with links to Inventory
 *  Print button hides sidebar/topbar via @media print.
 *  Access: admin  (can_manage('reports'))
 * ============================================================
 */

require_once __DIR__ . '/../../includes/init.php';
require_role(array('admin'));

$self = BASE_URL . 'modules/reports/';

/** tiny helper: run a prepared query, return the first column */
function rpt_scalar($sql, $types = '', $params = array()) {
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

/** tiny helper: prepared query -> all rows */
function rpt_rows($sql, $types = '', $params = array()) {
    global $conn;
    $stmt = mysqli_prepare($conn, $sql);
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    return $rows;
}

/* ============================================================
 *  DATE RANGE FILTER (GET)
 * ========================================================== */
$date_from = get('date_from');
$date_to   = get('date_to');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) { $date_from = date('Y-m-01'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   { $date_to   = date('Y-m-d'); }
if (strtotime($date_from) > strtotime($date_to)) {          /* swap if reversed */
    $tmp = $date_from; $date_from = $date_to; $date_to = $tmp;
}
$days_in_range = (int)floor((strtotime($date_to) - strtotime($date_from)) / 86400) + 1;

/* quick presets for the busy manager */
$presets = array(
    'This month'    => array(date('Y-m-01'), date('Y-m-d')),
    'Last month'    => array(date('Y-m-d', strtotime('first day of last month')),
                              date('Y-m-d', strtotime('last day of last month'))),
    'Last 7 days'   => array(date('Y-m-d', strtotime('-6 days')), date('Y-m-d')),
    'This year'     => array(date('Y-01-01'), date('Y-m-d')),
);

/* ============================================================
 *  KPI QUERIES (all scoped to the range)
 * ========================================================== */
$rng = array($date_from, $date_to);

/* revenue: paid invoices dated inside the range */
$stmt = mysqli_prepare($conn,
    "SELECT COALESCE(SUM(total_amount), 0), COUNT(*)
     FROM payments
     WHERE status = 'paid'
       AND DATE(payment_date) BETWEEN ? AND ?");
mysqli_stmt_bind_param($stmt, 'ss', $date_from, $date_to);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
$rev_total = (float)$row[0];
$rev_count = (int)$row[1];

/* refunded in range (shown as a deduction note) */
$ref_total = (float)rpt_scalar(
    "SELECT COALESCE(SUM(total_amount), 0) FROM payments
     WHERE status = 'refunded' AND DATE(payment_date) BETWEEN ? AND ?", 'ss', $rng);
$ref_count = (int)rpt_scalar(
    "SELECT COUNT(*) FROM payments
     WHERE status = 'refunded' AND DATE(payment_date) BETWEEN ? AND ?", 'ss', $rng);

/* pending invoices (created in range) */
$pend_count = (int)rpt_scalar(
    "SELECT COUNT(*) FROM payments
     WHERE status = 'pending' AND DATE(payment_date) BETWEEN ? AND ?", 'ss', $rng);

/* completed appointments in range (by appointment date) */
$appt_done = (int)rpt_scalar(
    "SELECT COUNT(*) FROM appointments
     WHERE status = 'completed'
       AND appointment_date BETWEEN ? AND ?", 'ss', $rng);

/* new clients registered in range */
$new_clients = (int)rpt_scalar(
    "SELECT COUNT(*) FROM clients
     WHERE DATE(created_at) BETWEEN ? AND ?", 'ss', $rng);

$avg_invoice = $rev_count > 0 ? round($rev_total / $rev_count, 2) : 0.0;

/* ============================================================
 *  DAILY REVENUE (bar chart, capped at the last 31 days)
 * ========================================================== */
$chart_from = $days_in_range > 31
    ? date('Y-m-d', strtotime($date_to . ' -30 days'))
    : $date_from;

$per_day = array();
foreach (rpt_rows(
    "SELECT DATE(payment_date) AS d, COUNT(*) AS n, SUM(total_amount) AS amt
     FROM payments
     WHERE status = 'paid'
       AND DATE(payment_date) BETWEEN ? AND ?
     GROUP BY DATE(payment_date)", 'ss', array($chart_from, $date_to)) as $r) {
    $per_day[$r['d']] = array('n' => (int)$r['n'], 'amt' => (float)$r['amt']);
}

$days = array();
$max_day = 0.0;
for ($t = strtotime($chart_from); $t <= strtotime($date_to); $t += 86400) {
    $d  = date('Y-m-d', $t);
    $amt = isset($per_day[$d]) ? $per_day[$d]['amt'] : 0.0;
    $max_day = max($max_day, $amt);
    $days[] = array(
        'date' => $d,
        'amt'  => $amt,
        'n'    => isset($per_day[$d]) ? $per_day[$d]['n'] : 0,
        'lbl'  => date('j M', $t),
    );
}
foreach ($days as $i => $d) {
    $days[$i]['pct'] = $max_day > 0 && $d['amt'] > 0
        ? max(4, round($d['amt'] / $max_day * 100))
        : 0;
}

/* ============================================================
 *  REVENUE BY PAYMENT METHOD
 * ========================================================== */
$by_method = rpt_rows(
    "SELECT payment_method, COUNT(*) AS n, SUM(total_amount) AS amt
     FROM payments
     WHERE status = 'paid'
       AND DATE(payment_date) BETWEEN ? AND ?
     GROUP BY payment_method
     ORDER BY amt DESC", 'ss', $rng);
$method_max = 0.0;
foreach ($by_method as $m) { $method_max = max($method_max, (float)$m['amt']); }

/* ============================================================
 *  TOP SERVICES (by completed bookings)
 * ========================================================== */
$top_services = rpt_rows(
    "SELECT s.service_id, s.name, s.category, s.price,
            COUNT(*) AS done, SUM(s.price) AS val
     FROM appointments a
     JOIN services s ON s.service_id = a.service_id
     WHERE a.status = 'completed'
       AND a.appointment_date BETWEEN ? AND ?
     GROUP BY s.service_id, s.name, s.category, s.price
     ORDER BY done DESC, val DESC
     LIMIT 8", 'ss', $rng);
$svc_max = 0.0;
foreach ($top_services as $s) { $svc_max = max($svc_max, (int)$s['done']); }

/* ============================================================
 *  STYLIST PERFORMANCE (bookings, value, est. commission)
 * ========================================================== */
$stylists = rpt_rows(
    "SELECT st.staff_id, st.name,
            COUNT(*) AS done,
            SUM(s.price) AS val,
            SUM(s.price * COALESCE(s.commission_rate, st.commission_rate) / 100) AS comm
     FROM appointments a
     JOIN services s ON s.service_id = a.service_id
     JOIN staff st   ON st.staff_id  = a.staff_id
     WHERE a.status = 'completed'
       AND a.appointment_date BETWEEN ? AND ?
     GROUP BY st.staff_id, st.name
     ORDER BY val DESC", 'ss', $rng);

/* no-show / cancellation pain per stylist (same range) */
$leak_rows = rpt_rows(
    "SELECT st.staff_id, 
            SUM(a.status = 'cancelled') AS cancelled,
            SUM(a.status = 'no_show')   AS no_show
     FROM appointments a
     JOIN staff st ON st.staff_id = a.staff_id
     WHERE a.status IN ('cancelled','no_show')
       AND a.appointment_date BETWEEN ? AND ?
     GROUP BY st.staff_id", 'ss', $rng);
$leak = array();
foreach ($leak_rows as $l) { $leak[(int)$l['staff_id']] = $l; }

/* ============================================================
 *  APPOINTMENT STATUS BREAKDOWN
 * ========================================================== */
$appt_statuses = array('scheduled', 'confirmed', 'completed', 'cancelled', 'no_show');
$status_map = array_fill_keys($appt_statuses, 0);
foreach (rpt_rows(
    "SELECT status, COUNT(*) AS n FROM appointments
     WHERE appointment_date BETWEEN ? AND ?
     GROUP BY status", 'ss', $rng) as $r) {
    if (isset($status_map[$r['status']])) { $status_map[$r['status']] = (int)$r['n']; }
}
$appt_all = array_sum($status_map);

/* ============================================================
 *  TOP CLIENTS (completed visits + collected spend, merged)
 * ========================================================== */
$client_names = array();
foreach (rpt_rows("SELECT client_id, name FROM clients") as $r) {
    $client_names[(int)$r['client_id']] = $r['name'];
}

$visits = array();
foreach (rpt_rows(
    "SELECT a.client_id, c.name, COUNT(*) AS v, MAX(a.appointment_date) AS last_visit
     FROM appointments a
     JOIN clients c ON c.client_id = a.client_id
     WHERE a.status = 'completed'
       AND a.appointment_date BETWEEN ? AND ?
     GROUP BY a.client_id, c.name", 'ss', $rng) as $r) {
    $visits[(int)$r['client_id']] = array(
        'name' => $r['name'], 'v' => (int)$r['v'], 'spend' => 0.0, 'last' => $r['last_visit'],
    );
}
foreach (rpt_rows(
    "SELECT client_id, SUM(total_amount) AS s
     FROM payments
     WHERE status = 'paid' AND client_id IS NOT NULL
       AND DATE(payment_date) BETWEEN ? AND ?
     GROUP BY client_id", 'ss', $rng) as $r) {
    $cid = (int)$r['client_id'];
    if (isset($visits[$cid])) {
        $visits[$cid]['spend'] = (float)$r['s'];
    } else {
        /* spend but no completed visit in range (e.g. walk-in client row) */
        $visits[$cid] = array(
            'name' => isset($client_names[$cid]) ? $client_names[$cid] : 'Client #' . $cid,
            'v' => 0, 'spend' => (float)$r['s'], 'last' => null,
        );
    }
}
uasort($visits, function ($a, $b) {
    if ($b['spend'] !== $a['spend']) { return $b['spend'] <=> $a['spend']; }
    return $b['v'] <=> $a['v'];
});
$top_clients = array_slice($visits, 0, 8, true);
$client_max = 0.0;
foreach ($top_clients as $c) { $client_max = max($client_max, $c['spend']); }

/* ============================================================
 *  LOW STOCK SNAPSHOT
 * ========================================================== */
$low_total = (int)rpt_scalar(
    "SELECT COUNT(*) FROM inventory_items WHERE quantity <= reorder_level");
$low_items = rpt_rows(
    "SELECT i.item_name, i.quantity, i.reorder_level, i.unit, sp.name AS supplier_name
     FROM inventory_items i
     LEFT JOIN suppliers sp ON sp.supplier_id = i.supplier_id
     WHERE i.quantity <= i.reorder_level
     ORDER BY i.quantity ASC
     LIMIT 8");
$open_pos = (int)rpt_scalar(
    "SELECT COUNT(*) FROM purchase_orders WHERE status IN ('pending','ordered')");

$pay_icon = array('Cash' => 'bi-cash-coin', 'Card' => 'bi-credit-card-2-front',
                  'UPI' => 'bi-phone', 'Bank Transfer' => 'bi-bank', 'Other' => 'bi-wallet2');

$page_title = 'Reports';
require_once __DIR__ . '/../../includes/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
    <div>
        <h5 class="mb-1"><i class="bi bi-graph-up"></i> Reports</h5>
        <p class="text-muted small mb-0">Salon performance for the selected date range.</p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-salon btn-sm" type="button" onclick="window.print();">
            <i class="bi bi-printer"></i> Print
        </button>
    </div>
</div>

<!-- ---------------- range selector ---------------- -->
<div class="card card-salon mb-4 no-print">
    <div class="card-body py-3">
        <form class="row g-2 align-items-center" method="get" action="<?= $self ?>index.php">
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1">From</label>
                <input type="date" class="form-control form-control-sm" name="date_from" value="<?= e($date_from) ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1">To</label>
                <input type="date" class="form-control form-control-sm" name="date_to" value="<?= e($date_to) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small mb-1 d-none d-md-block">&nbsp;</label>
                <button class="btn btn-sm btn-salon w-100" type="submit">
                    <i class="bi bi-funnel"></i> Apply range
                </button>
            </div>
            <div class="col-12 col-md-3 small text-md-end text-muted">
                <?= (int)$days_in_range ?> day(s) selected
            </div>
        </form>
        <div class="d-flex flex-wrap gap-2 mt-2">
            <?php foreach ($presets as $lbl => $p): ?>
                <a class="btn btn-sm btn-outline-salon"
                   href="<?= $self ?>index.php?date_from=<?= $p[0] ?>&amp;date_to=<?= $p[1] ?>"><?= e($lbl) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<p class="small text-muted no-print">
    Showing <b><?= e(date('d M Y', strtotime($date_from))) ?></b> &ndash;
    <b><?= e(date('d M Y', strtotime($date_to))) ?></b>
    (<?= (int)$days_in_range ?> day<?= $days_in_range === 1 ? '' : 's' ?>)
</p>

<!-- ---------------- KPI cards ---------------- -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <span class="stat-icon"><i class="bi bi-cash-stack"></i></span>
            <div class="stat-label mt-2">Revenue collected</div>
            <div class="stat-value"><?= money($rev_total) ?></div>
            <?php if ($ref_total > 0): ?>
                <div class="small text-danger">&minus; <?= money($ref_total) ?> refunded (<?= $ref_count ?>)</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <span class="stat-icon"><i class="bi bi-receipt"></i></span>
            <div class="stat-label mt-2">Paid invoices</div>
            <div class="stat-value"><?= $rev_count ?></div>
            <div class="small text-muted">avg <?= money($avg_invoice) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <span class="stat-icon"><i class="bi bi-check2-circle"></i></span>
            <div class="stat-label mt-2">Completed appointments</div>
            <div class="stat-value"><?= $appt_done ?></div>
            <?php if ($pend_count > 0): ?>
                <div class="small text-warning"><?= $pend_count ?> invoice(s) pending</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <span class="stat-icon"><i class="bi bi-person-plus"></i></span>
            <div class="stat-label mt-2">New clients</div>
            <div class="stat-value"><?= $new_clients ?></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- ---------------- daily revenue chart ---------------- -->
    <div class="col-lg-7">
        <div class="card card-salon h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-bar-chart"></i> Revenue by day</span>
                <span class="badge text-bg-light border">peak <?= money($max_day) ?></span>
            </div>
            <div class="card-body">
                <?php if ($max_day <= 0): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="bi bi-bar-chart fs-1 d-block mb-2"></i>
                        No paid invoices in this range yet.
                    </div>
                <?php else: ?>
                    <div class="rpt-chart">
                        <?php foreach ($days as $d): ?>
                            <div class="rpt-bar <?= $d['amt'] > 0 ? '' : 'rpt-bar-zero' ?>"
                                 style="height: <?= $d['pct'] > 0 ? $d['pct'] : 2 ?>%;"
                                 title="<?= e($d['lbl']) ?>: <?= money($d['amt']) ?> (<?= $d['n'] ?> invoice<?= $d['n'] === 1 ? '' : 's' ?>)"></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="d-flex justify-content-between small text-muted mt-1">
                        <span><?= e($days[0]['lbl']) ?></span>
                        <span><?= e($days[(int)floor(count($days) / 2)]['lbl']) ?></span>
                        <span><?= e($days[count($days) - 1]['lbl']) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ---------------- by payment method ---------------- -->
    <div class="col-lg-5">
        <div class="card card-salon h-100">
            <div class="card-header"><i class="bi bi-wallet2"></i> Revenue by payment method</div>
            <div class="card-body p-0">
                <?php if (!$by_method): ?>
                    <div class="p-4 text-center text-muted">No payments in this range.</div>
                <?php else: ?>
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Method</th>
                                <th class="text-center">Invoices</th>
                                <th class="text-end">Amount</th>
                                <th style="width:90px;">Share</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($by_method as $m):
                            $share = $rev_total > 0 ? round((float)$m['amt'] / $rev_total * 100) : 0; ?>
                            <tr>
                                <td class="text-nowrap">
                                    <i class="bi <?= isset($pay_icon[$m['payment_method']]) ? $pay_icon[$m['payment_method']] : 'bi-wallet2' ?>"></i>
                                    <?= e($m['payment_method']) ?>
                                </td>
                                <td class="text-center"><?= (int)$m['n'] ?></td>
                                <td class="text-end"><?= money($m['amt']) ?></td>
                                <td>
                                    <div class="progress" style="height:6px;">
                                        <div class="progress-bar" style="width: <?= $share ?>%; background:var(--salon-rose);"></div>
                                    </div>
                                    <span class="small text-muted"><?= $share ?>%</span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- ---------------- top services ---------------- -->
    <div class="col-lg-6">
        <div class="card card-salon h-100">
            <div class="card-header"><i class="bi bi-stars"></i> Top services</div>
            <div class="card-body p-0">
                <?php if (!$top_services): ?>
                    <div class="p-4 text-center text-muted">No completed appointments in this range.</div>
                <?php else: ?>
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Service</th>
                                <th class="text-center">Done</th>
                                <th class="text-end">Value</th>
                                <th style="width:90px;">Volume</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($top_services as $s):
                            $pct = $svc_max > 0 ? round((int)$s['done'] / $svc_max * 100) : 0; ?>
                            <tr>
                                <td>
                                    <span class="fw-semibold"><?= e($s['name']) ?></span>
                                    <div class="small text-muted"><?= e($s['category']) ?> &middot; <?= money($s['price']) ?> each</div>
                                </td>
                                <td class="text-center"><?= (int)$s['done'] ?></td>
                                <td class="text-end"><?= money($s['val']) ?></td>
                                <td>
                                    <div class="progress" style="height:6px;">
                                        <div class="progress-bar" style="width: <?= $pct ?>%; background:var(--salon-gold);"></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ---------------- stylist performance ---------------- -->
    <div class="col-lg-6">
        <div class="card card-salon h-100">
            <div class="card-header"><i class="bi bi-person-badge"></i> Stylist performance</div>
            <div class="card-body p-0">
                <?php if (!$stylists): ?>
                    <div class="p-4 text-center text-muted">No completed appointments in this range.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th>Stylist</th>
                                    <th class="text-center">Done</th>
                                    <th class="text-end">Service value</th>
                                    <th class="text-end">Est. commission</th>
                                    <th class="text-center">Lost</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($stylists as $st):
                                $lid   = (int)$st['staff_id'];
                                $canc  = isset($leak[$lid]) ? (int)$leak[$lid]['cancelled'] : 0;
                                $nosh  = isset($leak[$lid]) ? (int)$leak[$lid]['no_show'] : 0; ?>
                                <tr>
                                    <td>
                                        <a class="fw-semibold text-decoration-none"
                                           href="<?= BASE_URL ?>modules/staff/view.php?id=<?= $lid ?>">
                                            <?= e($st['name']) ?>
                                        </a>
                                    </td>
                                    <td class="text-center"><?= (int)$st['done'] ?></td>
                                    <td class="text-end"><?= money($st['val']) ?></td>
                                    <td class="text-end text-success"><?= money($st['comm']) ?></td>
                                    <td class="text-center small">
                                        <?php if ($canc + $nosh > 0): ?>
                                            <span class="text-danger"><?= $canc ?> canc</span>
                                            <?php if ($nosh > 0): ?>/ <?= $nosh ?> no-show<?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <div class="card-footer bg-white small text-muted">
                    <i class="bi bi-info-circle"></i> Commission uses the service's own rate when set,
                    otherwise the stylist's default (same rule as payouts).
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- ---------------- appointment status ---------------- -->
    <div class="col-lg-6">
        <div class="card card-salon h-100">
            <div class="card-header"><i class="bi bi-pie-chart"></i> Appointment outcomes</div>
            <div class="card-body p-0">
                <?php if ($appt_all === 0): ?>
                    <div class="p-4 text-center text-muted">No appointments in this range.</div>
                <?php else: ?>
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th class="text-center">Count</th>
                                <th style="width:140px;">Share</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($appt_statuses as $stt):
                            $n   = $status_map[$stt];
                            $pct = $appt_all > 0 ? round($n / $appt_all * 100) : 0; ?>
                            <tr>
                                <td><span class="pill pill-<?= e($stt) ?>"><?= e(ucwords(str_replace('_', ' ', $stt))) ?></span></td>
                                <td class="text-center"><?= $n ?></td>
                                <td>
                                    <div class="progress" style="height:6px;">
                                        <div class="progress-bar" style="width: <?= $pct ?>%; background:var(--salon-rose);"></div>
                                    </div>
                                    <span class="small text-muted"><?= $pct ?>%</span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ---------------- top clients ---------------- -->
    <div class="col-lg-6">
        <div class="card card-salon h-100">
            <div class="card-header"><i class="bi bi-people"></i> Top clients</div>
            <div class="card-body p-0">
                <?php if (!$top_clients): ?>
                    <div class="p-4 text-center text-muted">No client activity in this range.</div>
                <?php else: ?>
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Client</th>
                                <th class="text-center">Visits</th>
                                <th class="text-end">Spend</th>
                                <th>Last visit</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($top_clients as $cid => $c): ?>
                            <tr>
                                <td>
                                    <a class="fw-semibold text-decoration-none"
                                       href="<?= BASE_URL ?>modules/clients/view.php?id=<?= (int)$cid ?>">
                                        <?= e($c['name']) ?>
                                    </a>
                                </td>
                                <td class="text-center"><?= $c['v'] ?></td>
                                <td class="text-end"><?= money($c['spend']) ?></td>
                                <td class="small text-nowrap">
                                    <?= $c['last'] !== null ? e(date('d M Y', strtotime($c['last']))) : '<span class="text-muted">&mdash;</span>' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ---------------- low stock snapshot ---------------- -->
<div class="card card-salon mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-box-seam"></i> Low stock snapshot</span>
        <a class="small text-decoration-none" href="<?= BASE_URL ?>modules/inventory/index.php?level=reorder">
            Open inventory <i class="bi bi-arrow-right"></i>
        </a>
    </div>
    <div class="card-body p-0">
        <?php if (!$low_items): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-check2-all fs-3 d-block mb-2"></i>
                Every item is above its reorder level.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Supplier</th>
                            <th class="text-center">In stock</th>
                            <th class="text-center">Reorder at</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($low_items as $it): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($it['item_name']) ?></td>
                            <td class="small"><?= $it['supplier_name'] !== null ? e($it['supplier_name']) : '<span class="text-muted">no supplier</span>' ?></td>
                            <td class="text-center">
                                <b><?= e(rtrim(rtrim($it['quantity'], '0'), '.')) ?></b> <?= e($it['unit']) ?>
                            </td>
                            <td class="text-center"><?= e(rtrim(rtrim($it['reorder_level'], '0'), '.')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <div class="card-footer bg-white small text-muted">
        <i class="bi bi-cart3"></i> <?= $low_total ?> item(s) need reordering,
        <?= $open_pos ?> purchase order(s) still open -
        <a href="<?= BASE_URL ?>modules/inventory/purchase_orders.php">review orders</a>.
    </div>
</div>

<?php
$page_scripts = "
<style>
.rpt-chart {
    display: flex;
    align-items: flex-end;
    gap: 3px;
    height: 170px;
    padding: 6px 2px 0;
}
.rpt-bar {
    flex: 1;
    min-width: 6px;
    background: linear-gradient(180deg, var(--salon-rose), var(--salon-rose-d));
    border-radius: 4px 4px 0 0;
    opacity: .85;
}
.rpt-bar:hover { opacity: 1; }
.rpt-bar-zero { background: #e8e2da; }
@media print {
    .dash-sidebar, .dash-topbar, .no-print { display: none !important; }
    .dash-main { margin: 0 !important; padding: 0 !important; }
    body { background: #fff !important; }
    .card-salon { box-shadow: none !important; break-inside: avoid; }
}
</style>";
require_once __DIR__ . '/../../includes/dash_footer.php';
?>
