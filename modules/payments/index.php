<?php
/**
 * ============================================================
 *  PAYMENTS - INVOICE LIST (Step 9)
 *  Stats: collected today, collected this month, pending
 *  invoices, completed appointments still awaiting billing.
 *  Search (invoice no / client / description) + status,
 *  method and date-range filters + pagination.
 *  POST actions: mark pending invoice paid, refund a paid
 *  invoice, delete (admin only).
 *  Access: admin + receptionist  (can_manage('payments'))
 * ============================================================
 */

require_once __DIR__ . '/../../includes/init.php';
require_role(array('admin', 'receptionist'));

$self = BASE_URL . 'modules/payments/';

/** tiny helper: run a prepared query, return the first column */
function pay_scalar($sql, $types = '', $params = array()) {
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

$methods  = array('Cash', 'Card', 'UPI', 'Bank Transfer', 'Other');
$statuses = array('paid', 'pending', 'refunded');

/* ============================================================
 *  POST ACTIONS - mark paid / refund / delete (guarded by CSRF)
 * ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post('action');
    $pid    = (int)post('payment_id');

    /* ---------- mark a pending invoice as paid ---------- */
    if ($action === 'mark_paid') {
        $stmt = mysqli_prepare($conn,
            "UPDATE payments SET status = 'paid', payment_date = NOW()
             WHERE payment_id = ? AND status = 'pending'");
        mysqli_stmt_bind_param($stmt, 'i', $pid);
        mysqli_stmt_execute($stmt);
        $done = mysqli_stmt_affected_rows($stmt) > 0;
        mysqli_stmt_close($stmt);

        set_flash($done ? 'success' : 'warning',
            $done ? 'Payment collected - invoice marked as paid.'
                  : 'Only pending invoices can be marked paid.');
        redirect($self . 'index.php');
    }

    /* ---------- refund a paid invoice ---------- */
    if ($action === 'refund') {
        $stmt = mysqli_prepare($conn,
            "SELECT p.invoice_no, p.total_amount, p.description, c.email AS client_email
             FROM payments p
             LEFT JOIN clients c ON c.client_id = p.client_id
             WHERE p.payment_id = ? AND p.status = 'paid' LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'i', $pid);
        mysqli_stmt_execute($stmt);
        $inv = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$inv) {
            set_flash('warning', 'Only paid invoices can be refunded.');
            redirect($self . 'index.php');
        }

        $stmt = mysqli_prepare($conn,
            "UPDATE payments SET status = 'refunded' WHERE payment_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $pid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        log_notification(array(
            'recipient' => $inv['client_email'] !== null && $inv['client_email'] !== ''
                ? $inv['client_email']
                : get_setting('salon_email', 'manager@elegance.local'),
            'channel'   => 'email',
            'type'      => 'payment',
            'subject'   => 'Refund issued: ' . $inv['invoice_no'],
            'message'   => "A refund of " . money($inv['total_amount']) . " was issued for invoice "
                           . $inv['invoice_no'] . " (" . $inv['description'] . ").\n"
                           . 'The invoice is now marked as refunded in the salon books.',
            'status'    => 'pending',
        ));
        set_flash('success', $inv['invoice_no'] . ' refunded - '
            . money($inv['total_amount']) . ' returned, client notified.');
        redirect($self . 'index.php');
    }

    /* ---------- delete (admin only - financial audit trail) ---------- */
    if ($action === 'delete') {
        if (current_role() !== 'admin') {
            set_flash('danger', 'Only the admin can delete invoice records. Use Refund instead.');
            redirect($self . 'index.php');
        }
        $stmt = mysqli_prepare($conn, "SELECT invoice_no FROM payments WHERE payment_id = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'i', $pid);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$row) {
            set_flash('danger', 'Invoice not found (maybe already deleted).');
        } else {
            $stmt = mysqli_prepare($conn, "DELETE FROM payments WHERE payment_id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $pid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            set_flash('success', $row['invoice_no'] . ' deleted from the books.');
        }
        redirect($self . 'index.php');
    }

    set_flash('warning', 'Unknown action.');
    redirect($self . 'index.php');
}

/* ============================================================
 *  LIST + SEARCH + FILTERS + PAGINATION (GET)
 * ========================================================== */
$q        = get('q');
$status_f = get('status');
if (!in_array($status_f, $statuses, true)) {
    $status_f = '';
}
$method_f = get('method');
if (!in_array($method_f, $methods, true)) {
    $method_f = '';
}
$date_from = get('date_from');
$date_to   = get('date_to');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) { $date_from = ''; }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   { $date_to   = ''; }

$page     = max(1, (int)get('page', 1));
$per_page = 10;
$offset   = ($page - 1) * $per_page;

$conds  = array();
$types  = '';
$params = array();
if ($q !== '') {
    $conds[]  = '(p.invoice_no LIKE ? OR c.name LIKE ? OR p.description LIKE ?)';
    $like     = '%' . $q . '%';
    $types   .= 'sss';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($status_f !== '') {
    $conds[]  = 'p.status = ?';
    $types   .= 's';
    $params[] = $status_f;
}
if ($method_f !== '') {
    $conds[]  = 'p.payment_method = ?';
    $types   .= 's';
    $params[] = $method_f;
}
if ($date_from !== '') {
    $conds[]  = 'DATE(p.payment_date) >= ?';
    $types   .= 's';
    $params[] = $date_from;
}
if ($date_to !== '') {
    $conds[]  = 'DATE(p.payment_date) <= ?';
    $types   .= 's';
    $params[] = $date_to;
}
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

/* ---------- header stats (unfiltered) ---------- */
$stat_today  = (float)pay_scalar(
    "SELECT COALESCE(SUM(total_amount), 0) FROM payments
     WHERE status = 'paid' AND DATE(payment_date) = CURDATE()");
$stat_month  = (float)pay_scalar(
    "SELECT COALESCE(SUM(total_amount), 0) FROM payments
     WHERE status = 'paid'
       AND YEAR(payment_date)  = YEAR(CURDATE())
       AND MONTH(payment_date) = MONTH(CURDATE())");
$stat_pend   = (int)pay_scalar("SELECT COUNT(*) FROM payments WHERE status = 'pending'");
$stat_await  = (int)pay_scalar(
    "SELECT COUNT(*) FROM appointments a
     WHERE a.status = 'completed'
       AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.appointment_id = a.appointment_id)");

/* ---------- total rows for the pager ---------- */
$stmt = mysqli_prepare($conn,
    "SELECT COUNT(*)
     FROM payments p
     LEFT JOIN clients c ON c.client_id = p.client_id
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
    $offset = ($page - 1) * $per_page;
}

/* ---------- rows ---------- */
$sql = "SELECT p.*, c.name AS client_name, u.name AS recorded_by_name,
               a.appointment_date, s.name AS service_name, st.name AS stylist_name
        FROM payments p
        LEFT JOIN clients c     ON c.client_id     = p.client_id
        LEFT JOIN users u       ON u.user_id       = p.recorded_by
        LEFT JOIN appointments a ON a.appointment_id = p.appointment_id
        LEFT JOIN services s    ON s.service_id    = a.service_id
        LEFT JOIN staff st      ON st.staff_id     = a.staff_id
        $where
        ORDER BY p.payment_date DESC, p.payment_id DESC
        LIMIT ? OFFSET ?";
$types   .= 'ii';
$params[] = $per_page;
$params[] = $offset;

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$invoices = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

/* keep search + filters in the pager links */
$qs = '';
if ($q !== '')         { $qs .= '&amp;q=' . urlencode($q); }
if ($status_f !== '')  { $qs .= '&amp;status=' . urlencode($status_f); }
if ($method_f !== '')  { $qs .= '&amp;method=' . urlencode($method_f); }
if ($date_from !== '') { $qs .= '&amp;date_from=' . urlencode($date_from); }
if ($date_to !== '')   { $qs .= '&amp;date_to=' . urlencode($date_to); }

$pages_to_show = array();
for ($p = 1; $p <= $total_pages; $p++) {
    if ($total_pages <= 9 || $p <= 2 || $p > $total_pages - 2 || abs($p - $page) <= 1) {
        $pages_to_show[] = $p;
    }
}

$pay_pill = array('paid' => 'pill-active', 'pending' => 'pill-on_leave', 'refunded' => 'pill-cancelled');
$pay_icon = array('Cash' => 'bi-cash-coin', 'Card' => 'bi-credit-card-2-front',
                  'UPI' => 'bi-phone', 'Bank Transfer' => 'bi-bank', 'Other' => 'bi-wallet2');

$has_filters = ($q !== '' || $status_f !== '' || $method_f !== '' || $date_from !== '' || $date_to !== '');

$page_title = 'Payments';
require_once __DIR__ . '/../../includes/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h5 class="mb-1"><i class="bi bi-receipt"></i> Payments</h5>
        <p class="text-muted small mb-0">
            Invoices and receipts. Completed appointments that are not billed yet
            are counted below - checkout takes one click.
        </p>
    </div>
    <a class="btn btn-salon" href="<?= $self ?>form.php">
        <i class="bi bi-plus-lg"></i> New payment
    </a>
</div>

<!-- ---------------- stat cards ---------------- -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <span class="stat-icon"><i class="bi bi-cash-stack"></i></span>
            <div class="stat-label mt-2">Collected today</div>
            <div class="stat-value"><?= money($stat_today) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <span class="stat-icon"><i class="bi bi-graph-up-arrow"></i></span>
            <div class="stat-label mt-2">Collected this month</div>
            <div class="stat-value"><?= money($stat_month) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <a class="text-decoration-none" href="<?= $self ?>index.php?status=pending">
            <div class="stat-card">
                <span class="stat-icon"><i class="bi bi-hourglass-split"></i></span>
                <div class="stat-label mt-2">Pending invoices</div>
                <div class="stat-value"><?= $stat_pend ?></div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a class="text-decoration-none" href="<?= $self ?>form.php">
            <div class="stat-card">
                <span class="stat-icon"><i class="bi bi-clipboard-check"></i></span>
                <div class="stat-label mt-2">Completed, not billed</div>
                <div class="stat-value"><?= $stat_await ?></div>
                <div class="small text-primary">bill now &rarr;</div>
            </div>
        </a>
    </div>
</div>

<div class="card card-salon">
    <div class="card-header">
        <form class="row g-2 align-items-center" method="get" action="<?= $self ?>index.php">
            <div class="col-12 col-md-4">
                <div class="d-flex search-bar w-100">
                    <input type="search" class="form-control" name="q" value="<?= e($q) ?>"
                           placeholder="Invoice no, client or description...">
                    <button class="btn btn-outline-salon ms-2" type="submit"><i class="bi bi-search"></i></button>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All statuses</option>
                    <?php foreach ($statuses as $s): ?>
                        <option value="<?= e($s) ?>" <?= $status_f === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="method" class="form-select form-select-sm">
                    <option value="">All methods</option>
                    <?php foreach ($methods as $m): ?>
                        <option value="<?= e($m) ?>" <?= $method_f === $m ? 'selected' : '' ?>><?= e($m) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <input type="date" class="form-control form-control-sm" name="date_from"
                       value="<?= e($date_from) ?>" title="From date">
            </div>
            <div class="col-6 col-md-2">
                <div class="d-flex">
                    <input type="date" class="form-control form-control-sm" name="date_to"
                           value="<?= e($date_to) ?>" title="To date">
                    <button class="btn btn-sm btn-outline-salon ms-1" type="submit" title="Apply filters">
                        <i class="bi bi-funnel"></i>
                    </button>
                    <?php if ($has_filters): ?>
                        <a class="btn btn-sm btn-link text-muted" href="<?= $self ?>index.php" title="Clear filters">
                            <i class="bi bi-x-lg"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <div class="card-body p-0">
        <?php if (!$invoices): ?>
            <div class="p-5 text-center text-muted">
                <i class="bi bi-receipt fs-1 d-block mb-2"></i>
                <?php if ($has_filters): ?>
                    No invoices match your filters.
                <?php else: ?>
                    No payments recorded yet - click <b>New payment</b> to check out an
                    appointment or a walk-in sale.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Client / description</th>
                            <th class="text-end">Total</th>
                            <th>Method</th>
                            <th class="text-center">Status</th>
                            <th>Date</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($invoices as $inv): ?>
                        <tr class="<?= $inv['status'] === 'refunded' ? 'table-light' : '' ?>">
                            <td>
                                <a class="fw-semibold text-decoration-none" href="<?= $self ?>view.php?id=<?= (int)$inv['payment_id'] ?>">
                                    <?= e($inv['invoice_no']) ?>
                                </a>
                                <?php if ($inv['appointment_date'] !== null): ?>
                                    <div class="small text-muted">
                                        <a class="text-decoration-none" href="<?= BASE_URL ?>modules/appointments/view.php?id=<?= (int)$inv['appointment_id'] ?>">
                                            APT #<?= (int)$inv['appointment_id'] ?>
                                        </a>
                                        <?php if ($inv['service_name'] !== null): ?>
                                            &middot; <?= e($inv['service_name']) ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <span class="fw-semibold"><?= $inv['client_name'] !== null ? e($inv['client_name']) : 'Walk-in guest' ?></span>
                                <?php if ($inv['description'] !== null && $inv['description'] !== ''): ?>
                                    <div class="text-muted text-truncate" style="max-width:240px;"><?= e($inv['description']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <b><?= money($inv['total_amount']) ?></b>
                                <?php if ((float)$inv['discount'] > 0): ?>
                                    <div class="small text-success">-&nbsp;<?= money($inv['discount']) ?> off</div>
                                <?php endif; ?>
                            </td>
                            <td class="small text-nowrap">
                                <i class="bi <?= $pay_icon[$inv['payment_method']] ?>"></i>
                                <?= e($inv['payment_method']) ?>
                            </td>
                            <td class="text-center">
                                <span class="pill <?= $pay_pill[$inv['status']] ?>"><?= e(ucfirst($inv['status'])) ?></span>
                            </td>
                            <td class="small text-nowrap">
                                <?= e(date('d M Y', strtotime($inv['payment_date']))) ?>
                                <div class="text-muted"><?= e(date('h:i A', strtotime($inv['payment_date']))) ?></div>
                            </td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" href="<?= $self ?>view.php?id=<?= (int)$inv['payment_id'] ?>" title="View receipt">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?php if ($inv['status'] === 'pending'): ?>
                                    <form class="d-inline" method="post" action="<?= $self ?>index.php">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="mark_paid">
                                        <input type="hidden" name="payment_id" value="<?= (int)$inv['payment_id'] ?>">
                                        <button class="btn btn-sm btn-outline-success" type="submit" title="Mark as paid (money received)">
                                            <i class="bi bi-send"></i>
                                        </button>
                                    </form>
                                <?php elseif ($inv['status'] === 'paid'): ?>
                                    <form class="d-inline" method="post" action="<?= $self ?>index.php"
                                          data-confirm="Refund <?= e($inv['invoice_no']) ?> (<?= money($inv['total_amount']) ?>)? The invoice will be marked refunded.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="refund">
                                        <input type="hidden" name="payment_id" value="<?= (int)$inv['payment_id'] ?>">
                                        <button class="btn btn-sm btn-outline-warning" type="submit" title="Refund">
                                            <i class="bi bi-arrow-counterclockwise"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if (current_role() === 'admin'): ?>
                                    <form class="d-inline" method="post" action="<?= $self ?>index.php"
                                          data-confirm="Delete invoice <?= e($inv['invoice_no']) ?> from the books? This cannot be undone - prefer Refund for corrections.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="payment_id" value="<?= (int)$inv['payment_id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit" title="Delete (admin only)">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
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
    <i class="bi bi-lightbulb"></i> Recording a payment for an appointment marks that appointment
    <b>completed</b> automatically. Pending invoices can be collected later with the
    <i class="bi bi-send"></i> action; refunds never delete the record - the invoice stays as
    <b>Refunded</b> for the books.
</p>

<?php require_once __DIR__ . '/../../includes/dash_footer.php'; ?>
