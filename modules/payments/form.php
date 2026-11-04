<?php
/**
 * ============================================================
 *  PAYMENTS - NEW PAYMENT / CHECKOUT (Step 9)
 *  One form for both flows:
 *    - pick a completed/scheduled appointment (not billed
 *      yet) -> client, description and amount prefill from
 *      the service price;
 *    - or a walk-in / manual sale (no appointment, optional
 *      client, free description and amount).
 *  Total = (amount - discount) + tax, tax rate defaults from
 *  settings. Invoice number INV-YYYYMMDD-### is generated on
 *  save. Paying an appointment invoice marks the appointment
 *  completed and queues a simulated email notification.
 *  Access: admin + receptionist  (can_manage('payments'))
 * ============================================================
 */

require_once __DIR__ . '/../../includes/init.php';
require_role(array('admin', 'receptionist'));

$self = BASE_URL . 'modules/payments/';

$methods = array('Cash', 'Card', 'UPI', 'Bank Transfer', 'Other');

/* ---------- billable appointments: not cancelled, no invoice yet ---------- */
$appointments = mysqli_fetch_all(mysqli_query($conn,
    "SELECT a.appointment_id, a.appointment_date, a.start_time, a.status,
            a.client_id, c.name AS client_name, s.name AS service_name,
            s.price, st.name AS stylist_name
     FROM appointments a
     JOIN clients c ON c.client_id = a.client_id
     JOIN services s ON s.service_id = a.service_id
     JOIN staff st   ON st.staff_id  = a.staff_id
     WHERE a.status IN ('scheduled','confirmed','completed')
       AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.appointment_id = a.appointment_id)
     ORDER BY a.appointment_date DESC, a.start_time DESC
     LIMIT 100"), MYSQLI_ASSOC);

/* ---------- clients dropdown ---------- */
$clients = mysqli_fetch_all(mysqli_query($conn,
    "SELECT client_id, name, phone FROM clients
     WHERE is_active = 1
     ORDER BY name ASC"), MYSQLI_ASSOC);

/* ---------- defaults (overwritten by POST on error) ---------- */
$in = array(
    'appointment_id' => (int)get('appointment_id'),
    'client_id'      => (int)get('client_id'),
    'description'    => '',
    'amount'         => '',
    'discount'       => '0',
    'tax_rate'       => get_setting('tax_rate', '5'),
    'method'         => 'Cash',
    'status'         => 'paid',
);

/* ============================================================
 *  HANDLE SUBMIT
 * ========================================================== */
$errors = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $in['appointment_id'] = (int)post('appointment_id');
    $in['client_id']      = (int)post('client_id');
    $in['description']    = post('description');
    $in['amount']         = post('amount');
    $in['discount']       = post('discount', '0');
    $in['tax_rate']       = post('tax_rate', '0');
    $in['method']         = post('method', 'Cash');
    $in['status']         = post('status', 'paid');

    /* --- appointment: 0 = walk-in, otherwise must still be billable --- */
    $appt = null;
    if ($in['appointment_id'] > 0) {
        $stmt = mysqli_prepare($conn,
            "SELECT a.appointment_id, a.status, s.name AS service_name, st.name AS stylist_name
             FROM appointments a
             JOIN services s ON s.service_id = a.service_id
             JOIN staff st   ON st.staff_id  = a.staff_id
             WHERE a.appointment_id = ?
               AND a.status IN ('scheduled','confirmed','completed')
               AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.appointment_id = a.appointment_id)
             LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'i', $in['appointment_id']);
        mysqli_stmt_execute($stmt);
        $appt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$appt) {
            $errors['appointment_id'] = 'That appointment cannot be billed (already invoiced or cancelled).';
        }
    }

    /* --- client: 0 = walk-in guest, otherwise must exist --- */
    $client_email = null;
    if ($in['client_id'] > 0) {
        $stmt = mysqli_prepare($conn,
            "SELECT email FROM clients WHERE client_id = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'i', $in['client_id']);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$row) {
            $errors['client_id'] = 'Pick a client from the list.';
        } else {
            $client_email = $row['email'];
        }
    }

    /* --- description (falls back to the service name) --- */
    if ($in['description'] === '' && $appt) {
        $in['description'] = $appt['service_name'] . ' with ' . $appt['stylist_name'];
    }
    if ($in['description'] === '') {
        $errors['description'] = 'Description is required (e.g. the service or product sold).';
    } elseif (mb_strlen($in['description']) > 255) {
        $errors['description'] = 'Description is too long (max 255 characters).';
    }

    /* --- amounts --- */
    if (!is_numeric($in['amount']) || (float)$in['amount'] < 0) {
        $errors['amount'] = 'Amount must be a number of 0 or more.';
    } elseif ((float)$in['amount'] > 9999999) {
        $errors['amount'] = 'That amount is too large.';
    }
    if (!is_numeric($in['discount']) || (float)$in['discount'] < 0) {
        $errors['discount'] = 'Discount must be a number of 0 or more.';
    } elseif ((float)$in['discount'] > (float)$in['amount']) {
        $errors['discount'] = 'Discount cannot be larger than the amount.';
    }
    if (!is_numeric($in['tax_rate']) || (float)$in['tax_rate'] < 0 || (float)$in['tax_rate'] > 100) {
        $errors['tax_rate'] = 'Tax rate must be between 0 and 100 (%).';
    }

    /* --- method + status --- */
    if (!in_array($in['method'], $methods, true)) {
        $errors['method'] = 'Pick a payment method from the list.';
    }
    if (!in_array($in['status'], array('paid', 'pending'), true)) {
        $errors['status'] = 'Pick paid or pending.';
    }

    /* --- save --- */
    if (!$errors) {
        $amount   = round((float)$in['amount'], 2);
        $discount = round((float)$in['discount'], 2);
        $rate     = round((float)$in['tax_rate'], 2);
        $taxable  = round($amount - $discount, 2);
        $tax      = round($taxable * $rate / 100, 2);
        $total    = round($taxable + $tax, 2);

        /* next free invoice number for today: INV-YYYYMMDD-001, -002 ... */
        $prefix = 'INV-' . date('Ymd') . '-';
        $like   = $prefix . '%';
        $stmt = mysqli_prepare($conn,
            "SELECT COUNT(*) FROM payments WHERE invoice_no LIKE ?");
        mysqli_stmt_bind_param($stmt, 's', $like);
        mysqli_stmt_execute($stmt);
        $seq = (int)mysqli_fetch_row(mysqli_stmt_get_result($stmt))[0];
        mysqli_stmt_close($stmt);
        $inv_no = $prefix . str_pad((string)($seq + 1), 3, '0', STR_PAD_LEFT);

        $appt_v   = $in['appointment_id'] > 0 ? $in['appointment_id'] : null;
        $client_v = $in['client_id']      > 0 ? $in['client_id']      : null;
        $uid      = (int)$_SESSION['user_id'];

        $stmt = mysqli_prepare($conn,
            "INSERT INTO payments
                (invoice_no, appointment_id, client_id, description, amount, tax_rate,
                 discount, total_amount, payment_method, status, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'siisddddssi',
            $inv_no, $appt_v, $client_v, $in['description'], $amount, $rate,
            $discount, $total, $in['method'], $in['status'], $uid);
        mysqli_stmt_execute($stmt);
        $new_id = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        /* paying for an appointment closes it as completed */
        $marked = false;
        if ($appt_v !== null && $in['status'] === 'paid') {
            $stmt = mysqli_prepare($conn,
                "UPDATE appointments SET status = 'completed'
                 WHERE appointment_id = ? AND status IN ('scheduled','confirmed')");
            mysqli_stmt_bind_param($stmt, 'i', $appt_v);
            mysqli_stmt_execute($stmt);
            $marked = mysqli_stmt_affected_rows($stmt) > 0;
            mysqli_stmt_close($stmt);
        }

        /* simulated email to the client (or the salon inbox for walk-ins) */
        log_notification(array(
            'recipient' => ($client_email !== null && $client_email !== '')
                ? $client_email
                : get_setting('salon_email', 'billing@elegance.local'),
            'channel'   => 'email',
            'type'      => 'payment',
            'subject'   => ($in['status'] === 'paid' ? 'Payment received: ' : 'Invoice created: ') . $inv_no,
            'message'   => ($in['status'] === 'paid'
                            ? 'Thank you for your visit to ' . get_setting('salon_name', 'Elegance Salon') . '!'
                            : 'An invoice was raised for you by ' . get_setting('salon_name', 'Elegance Salon') . '.') . "\n" .
                           'Invoice: ' . $inv_no . "\n" .
                           'For: ' . $in['description'] . "\n" .
                           'Total: ' . money($total) . ($in['status'] === 'pending' ? ' (payment pending)' : ' - paid by ' . $in['method']),
            'status'    => 'pending',
        ));

        set_flash('success', 'Invoice ' . $inv_no . ' recorded' .
            ($in['status'] === 'paid' ? ' - payment received.' : ' - awaiting payment.') .
            ($marked ? ' The appointment was marked completed.' : ''));
        redirect($self . 'view.php?id=' . $new_id);
    }
}

$page_title = 'New payment';
require_once __DIR__ . '/../../includes/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h5 class="mb-0"><i class="bi bi-cash-coin"></i> New payment</h5>
    <a class="btn btn-outline-secondary btn-sm" href="<?= $self ?>index.php">
        <i class="bi bi-arrow-left"></i> Back to payments
    </a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> Please fix the highlighted fields and try again.</div>
<?php endif; ?>

<div class="row g-3">
    <!-- ---------- left: checkout form ---------- -->
    <div class="col-lg-7">
        <div class="card card-salon">
            <div class="card-header"><i class="bi bi-basket"></i> Sale details</div>
            <div class="card-body">
                <form method="post" action="<?= $self ?>form.php" novalidate>
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label">Appointment <span class="text-muted small">(optional)</span></label>
                        <select name="appointment_id" id="apptSel"
                                class="form-select <?= isset($errors['appointment_id']) ? 'is-invalid' : '' ?>">
                            <option value="0">&mdash; Walk-in / manual sale, no appointment &mdash;</option>
                            <?php foreach ($appointments as $a): ?>
                                <option value="<?= (int)$a['appointment_id'] ?>"
                                    data-client="<?= (int)$a['client_id'] ?>"
                                    data-desc="<?= e($a['service_name'] . ' with ' . $a['stylist_name']) ?>"
                                    data-amount="<?= e($a['price']) ?>"
                                    <?= $in['appointment_id'] === (int)$a['appointment_id'] ? 'selected' : '' ?>>
                                    #<?= (int)$a['appointment_id'] ?>
                                    &middot; <?= e(date('d M Y, h:i A', strtotime($a['appointment_date'] . ' ' . $a['start_time']))) ?>
                                    &middot; <?= e($a['client_name']) ?>
                                    &middot; <?= e($a['service_name']) ?>
                                    (<?= e($a['status']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Completed appointments without an invoice are listed here.
                            Picking one fills the client, description and price below.</div>
                        <?php if (isset($errors['appointment_id'])): ?><div class="invalid-feedback d-block"><?= e($errors['appointment_id']) ?></div><?php endif; ?>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Client</label>
                            <select name="client_id" id="clientSel"
                                    class="form-select <?= isset($errors['client_id']) ? 'is-invalid' : '' ?>">
                                <option value="0">Walk-in guest</option>
                                <?php foreach ($clients as $c): ?>
                                    <option value="<?= (int)$c['client_id'] ?>"
                                        <?= $in['client_id'] === (int)$c['client_id'] ? 'selected' : '' ?>>
                                        <?= e($c['name']) ?><?= $c['phone'] !== '' ? ' &mdash; ' . e($c['phone']) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['client_id'])): ?><div class="invalid-feedback d-block"><?= e($errors['client_id']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Description <span class="text-danger">*</span></label>
                            <input type="text" name="description" id="descInput" maxlength="255"
                                   class="form-control <?= isset($errors['description']) ? 'is-invalid' : '' ?>"
                                   value="<?= e($in['description']) ?>" placeholder="e.g. Keratin treatment / retail shampoo">
                            <?php if (isset($errors['description'])): ?><div class="invalid-feedback d-block"><?= e($errors['description']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Amount <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><?= e(get_setting('currency_symbol', '₹')) ?></span>
                                <input type="number" name="amount" id="amountInput" min="0" step="0.01"
                                       class="form-control <?= isset($errors['amount']) ? 'is-invalid' : '' ?>"
                                       value="<?= e($in['amount']) ?>" placeholder="0.00" required>
                            </div>
                            <?php if (isset($errors['amount'])): ?><div class="invalid-feedback d-block"><?= e($errors['amount']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Discount</label>
                            <div class="input-group">
                                <span class="input-group-text"><?= e(get_setting('currency_symbol', '₹')) ?></span>
                                <input type="number" name="discount" id="discountInput" min="0" step="0.01"
                                       class="form-control <?= isset($errors['discount']) ? 'is-invalid' : '' ?>"
                                       value="<?= e($in['discount']) ?>">
                            </div>
                            <?php if (isset($errors['discount'])): ?><div class="invalid-feedback d-block"><?= e($errors['discount']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Tax rate (%)</label>
                            <input type="number" name="tax_rate" id="taxInput" min="0" max="100" step="0.01"
                                   class="form-control <?= isset($errors['tax_rate']) ? 'is-invalid' : '' ?>"
                                   value="<?= e($in['tax_rate']) ?>">
                            <div class="form-text">Default from Settings.</div>
                            <?php if (isset($errors['tax_rate'])): ?><div class="invalid-feedback d-block"><?= e($errors['tax_rate']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Payment method <span class="text-danger">*</span></label>
                            <select name="method" class="form-select <?= isset($errors['method']) ? 'is-invalid' : '' ?>">
                                <?php foreach ($methods as $m): ?>
                                    <option value="<?= e($m) ?>" <?= $in['method'] === $m ? 'selected' : '' ?>><?= e($m) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['method'])): ?><div class="invalid-feedback d-block"><?= e($errors['method']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label d-block">Status <span class="text-danger">*</span></label>
                            <div class="d-flex gap-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="status" id="st-paid" value="paid"
                                           <?= $in['status'] !== 'pending' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="st-paid">
                                        <i class="bi bi-check2-circle text-success"></i> Paid now
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="status" id="st-pending" value="pending"
                                           <?= $in['status'] === 'pending' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="st-pending">
                                        <i class="bi bi-hourglass-split text-warning"></i> Bill later
                                    </label>
                                </div>
                            </div>
                            <div class="form-text">Pending invoices can be collected from the payments list.</div>
                        </div>
                    </div>

                    <div class="mt-4 d-flex gap-2">
                        <button class="btn btn-salon px-4" type="submit">
                            <i class="bi bi-receipt"></i> Save invoice
                        </button>
                        <a class="btn btn-outline-secondary" href="<?= $self ?>index.php">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ---------- right: live totals ---------- -->
    <div class="col-lg-5">
        <div class="card card-salon">
            <div class="card-header"><i class="bi bi-calculator"></i> Invoice summary</div>
            <div class="card-body">
                <table class="table mb-2">
                    <tbody>
                        <tr>
                            <td>Subtotal</td>
                            <td class="text-end"><?= e(get_setting('currency_symbol', '₹')) ?> <span id="s_sub">0.00</span></td>
                        </tr>
                        <tr>
                            <td>Discount</td>
                            <td class="text-end text-success">&minus; <?= e(get_setting('currency_symbol', '₹')) ?> <span id="s_disc">0.00</span></td>
                        </tr>
                        <tr>
                            <td>Tax <span id="taxLbl">(0%)</span></td>
                            <td class="text-end">+ <?= e(get_setting('currency_symbol', '₹')) ?> <span id="s_tax">0.00</span></td>
                        </tr>
                        <tr class="fs-5 fw-bold">
                            <td>Total</td>
                            <td class="text-end"><?= e(get_setting('currency_symbol', '₹')) ?> <span id="s_total">0.00</span></td>
                        </tr>
                    </tbody>
                </table>
                <div class="alert alert-info small mb-0">
                    <i class="bi bi-info-circle"></i> Tax is applied to <b>amount &minus; discount</b>.
                    Totals are recalculated on the server when saving, so the invoice is always exact.
                </div>
            </div>
        </div>

        <div class="alert alert-light border small mt-3 mb-0">
            <i class="bi bi-lightbulb"></i> <b>How it works:</b> invoice numbers are generated as
            <b>INV-YYYYMMDD-###</b>. Paying an appointment's invoice marks that appointment
            <b>completed</b>, and the client receives a simulated email receipt in the
            notifications log.
        </div>
    </div>
</div>

<?php
$page_scripts = "
<script>
(function () {
    var appt  = document.getElementById('apptSel');
    var cl    = document.getElementById('clientSel');
    var desc  = document.getElementById('descInput');
    var amt   = document.getElementById('amountInput');
    var disc  = document.getElementById('discountInput');
    var tax   = document.getElementById('taxInput');

    function num(v) { var x = parseFloat(v); return isNaN(x) ? 0 : x; }
    function two(x) { return (Math.round(x * 100) / 100).toFixed(2); }

    function calc() {
        var a = num(amt.value), d = num(disc.value), t = num(tax.value);
        if (d > a) { d = a; }
        var taxable = a - d;
        var tAmt = taxable * t / 100;
        document.getElementById('s_sub').textContent   = two(a);
        document.getElementById('s_disc').textContent  = two(d);
        document.getElementById('s_tax').textContent   = two(tAmt);
        document.getElementById('s_total').textContent = two(taxable + tAmt);
        document.getElementById('taxLbl').textContent  = '(' + t + '%)';
    }

    function applyAppt() {
        if (appt.value !== '0') {
            var o = appt.options[appt.selectedIndex];
            if (o.dataset.client) { cl.value = o.dataset.client; }
            if (o.dataset.desc)   { desc.value = o.dataset.desc; }
            if (o.dataset.amount) { amt.value  = o.dataset.amount; }
        }
        calc();
    }

    appt.addEventListener('change', applyAppt);
    [amt, disc, tax].forEach(function (el) { el.addEventListener('input', calc); });
    applyAppt();
})();
</script>";
require_once __DIR__ . '/../../includes/dash_footer.php';
?>