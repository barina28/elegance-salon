<?php
/**
 * ============================================================
 *  PAYMENTS - INVOICE / RECEIPT (Step 9)
 *  Printable receipt for one payment row: salon letterhead,
 *  client block, linked appointment details, line item,
 *  tax/discount breakdown and payment info.
 *  The Print button hides the sidebar/topbar via @media print.
 *  Access: admin + receptionist  (can_manage('payments'))
 * ============================================================
 */

require_once __DIR__ . '/../../includes/init.php';
require_role(array('admin', 'receptionist'));

$self = BASE_URL . 'modules/payments/';
$id   = (int)get('id');

if ($id <= 0) {
    redirect($self . 'index.php');
}

/* ---------- invoice + client + appointment + recorder ---------- */
$stmt = mysqli_prepare($conn,
    "SELECT p.*,
            c.name  AS client_name,  c.phone  AS client_phone,  c.email AS client_email,
            u.name  AS recorded_by_name,
            a.appointment_id, a.appointment_date, a.start_time, a.end_time,
            st.name AS stylist_name, s.name AS service_name
     FROM payments p
     LEFT JOIN clients c      ON c.client_id      = p.client_id
     LEFT JOIN users u        ON u.user_id        = p.recorded_by
     LEFT JOIN appointments a ON a.appointment_id = p.appointment_id
     LEFT JOIN staff st       ON st.staff_id      = a.staff_id
     LEFT JOIN services s     ON s.service_id     = a.service_id
     WHERE p.payment_id = ?
     LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$inv = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$inv) {
    set_flash('danger', 'Invoice not found.');
    redirect($self . 'index.php');
}

/* ---------- derived amounts ---------- */
$amount   = (float)$inv['amount'];
$discount = (float)$inv['discount'];
$rate     = (float)$inv['tax_rate'];
$taxable  = round($amount - $discount, 2);
$tax_amt  = round($taxable * $rate / 100, 2);

$cur      = get_setting('currency_symbol', '₹');
$salon    = get_setting('salon_name', SITE_NAME);

$pay_pill = array('paid' => 'pill-active', 'pending' => 'pill-on_leave', 'refunded' => 'pill-cancelled');
$pay_icon = array('Cash' => 'bi-cash-coin', 'Card' => 'bi-credit-card-2-front',
                  'UPI' => 'bi-phone', 'Bank Transfer' => 'bi-bank', 'Other' => 'bi-wallet2');

$page_title = 'Invoice ' . $inv['invoice_no'];
require_once __DIR__ . '/../../includes/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
    <h5 class="mb-0"><i class="bi bi-receipt"></i> Invoice <?= e($inv['invoice_no']) ?></h5>
    <div class="d-flex gap-2">
        <button class="btn btn-salon btn-sm" type="button" onclick="window.print();">
            <i class="bi bi-printer"></i> Print
        </button>
        <a class="btn btn-outline-secondary btn-sm" href="<?= $self ?>index.php">
            <i class="bi bi-arrow-left"></i> Back to payments
        </a>
    </div>
</div>

<div class="card card-salon receipt-card mx-auto" style="max-width:760px;">
    <div class="card-body p-4">

        <!-- ---------- letterhead ---------- -->
        <div class="d-flex flex-wrap justify-content-between gap-3 pb-3 mb-3" style="border-bottom:2px solid var(--salon-rose);">
            <div>
                <h4 class="mb-1" style="color:var(--salon-rose-d);"><i class="bi bi-scissors"></i> <?= e($salon) ?></h4>
                <div class="small text-muted">
                    <?= e(get_setting('salon_address', '')) ?><br>
                    <?= e(get_setting('salon_phone', '')) ?>
                    <?php if (get_setting('salon_email', '') !== ''): ?>
                        &middot; <?= e(get_setting('salon_email', '')) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="text-md-end">
                <h5 class="mb-1 text-uppercase">Invoice</h5>
                <div class="fw-semibold"><?= e($inv['invoice_no']) ?></div>
                <div class="small text-muted">
                    <?= e(date('d M Y, h:i A', strtotime($inv['payment_date']))) ?><br>
                    <span class="pill <?= $pay_pill[$inv['status']] ?>"><?= e(ucfirst($inv['status'])) ?></span>
                </div>
            </div>
        </div>

        <!-- ---------- client + appointment ---------- -->
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <div class="text-muted small text-uppercase fw-semibold mb-1">Billed to</div>
                <?php if ($inv['client_name'] !== null): ?>
                    <div class="fw-semibold"><?= e($inv['client_name']) ?></div>
                    <?php if ($inv['client_phone'] !== null): ?><div class="small"><?= e($inv['client_phone']) ?></div><?php endif; ?>
                    <?php if ($inv['client_email'] !== null): ?><div class="small text-muted"><?= e($inv['client_email']) ?></div><?php endif; ?>
                <?php else: ?>
                    <div class="fw-semibold">Walk-in guest</div>
                <?php endif; ?>
            </div>
            <?php if ($inv['appointment_id'] !== null): ?>
                <div class="col-md-6">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Appointment</div>
                    <div class="small">
                        <i class="bi bi-calendar2-check"></i>
                        <a class="text-decoration-none" href="<?= BASE_URL ?>modules/appointments/view.php?id=<?= (int)$inv['appointment_id'] ?>">
                            APT #<?= (int)$inv['appointment_id'] ?>
                        </a>
                        &middot; <?= e(date('d M Y', strtotime($inv['appointment_date']))) ?>
                        &middot; <?= e(date('h:i A', strtotime($inv['start_time']))) ?>
                        &ndash; <?= e(date('h:i A', strtotime($inv['end_time']))) ?>
                        <br>
                        <?php if ($inv['service_name'] !== null): ?>
                            <i class="bi bi-stars"></i> <?= e($inv['service_name']) ?>
                        <?php endif; ?>
                        <?php if ($inv['stylist_name'] !== null): ?>
                            &middot; with <?= e($inv['stylist_name']) ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- ---------- line items ---------- -->
        <table class="table align-middle">
            <thead class="table-light">
                <tr>
                    <th>Description</th>
                    <th class="text-end" style="width:130px;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?= e($inv['description']) ?></td>
                    <td class="text-end"><?= e($cur) ?> <?= number_format($amount, 2) ?></td>
                </tr>
            </tbody>
        </table>

        <!-- ---------- totals ---------- -->
        <div class="row justify-content-end">
            <div class="col-md-5 col-sm-7">
                <table class="table table-sm">
                    <tbody>
                        <tr>
                            <td>Subtotal</td>
                            <td class="text-end"><?= e($cur) ?> <?= number_format($amount, 2) ?></td>
                        </tr>
                        <?php if ($discount > 0): ?>
                            <tr class="text-success">
                                <td>Discount</td>
                                <td class="text-end">&minus; <?= e($cur) ?> <?= number_format($discount, 2) ?></td>
                            </tr>
                        <?php endif; ?>
                        <tr>
                            <td>Tax (<?= e(rtrim(rtrim($inv['tax_rate'], '0'), '.')) ?>%)</td>
                            <td class="text-end">+ <?= e($cur) ?> <?= number_format($tax_amt, 2) ?></td>
                        </tr>
                        <tr class="fs-5 fw-bold" style="border-top:2px solid var(--salon-rose);">
                            <td>Total</td>
                            <td class="text-end"><?= e($cur) ?> <?= number_format((float)$inv['total_amount'], 2) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ---------- payment info ---------- -->
        <div class="row g-3 pt-2 mt-0" style="border-top:1px dashed #d8cfc2;">
            <div class="col-md-6">
                <div class="text-muted small text-uppercase fw-semibold mb-1">Payment</div>
                <div class="small">
                    <i class="bi <?= $pay_icon[$inv['payment_method']] ?>"></i>
                    <b><?= e($inv['payment_method']) ?></b>
                    &middot; <span class="pill <?= $pay_pill[$inv['status']] ?>"><?= e(ucfirst($inv['status'])) ?></span>
                </div>
            </div>
            <div class="col-md-6 text-md-end">
                <div class="text-muted small text-uppercase fw-semibold mb-1">Recorded by</div>
                <div class="small">
                    <?= $inv['recorded_by_name'] !== null ? e($inv['recorded_by_name']) : '-' ?>
                    &middot; <?= e(date('d M Y, h:i A', strtotime($inv['payment_date']))) ?>
                </div>
            </div>
        </div>

        <p class="text-center text-muted small fst-italic mb-0 mt-4">
            Thank you for visiting <?= e($salon) ?> - where beauty meets elegance!
        </p>
    </div>
</div>

<?php
$page_scripts = "
<style>
@media print {
    .dash-sidebar, .dash-topbar, .no-print { display: none !important; }
    .dash-main { margin: 0 !important; padding: 0 !important; }
    body { background: #fff !important; }
    .receipt-card { border: none !important; box-shadow: none !important; }
}
</style>";
require_once __DIR__ . '/../../includes/dash_footer.php';
?>
