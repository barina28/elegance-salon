<?php
/**
 * ============================================================
 *  STAFF - COMMISSION RATES (Step 7, admin only)
 *  Two tables on one page:
 *    1) each stylist's DEFAULT commission rate
 *    2) per-service OVERRIDES - an override replaces the
 *       stylist default for that service; "inherit" stores NULL
 *  Earnings use  COALESCE(service.commission_rate,
 *                        staff.commission_rate)
 *  (the same rule the dashboard and reports use).
 *  Access: admin only  (can_manage('staff'))
 * ============================================================
 */

require_once __DIR__ . '/../../includes/init.php';
require_role(array('admin'));

 $self = BASE_URL . 'modules/staff/commissions.php';

/* ============================================================
 *  POST - save the two rate groups (guarded by CSRF)
 * ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post('action');

    /* ---------- stylist defaults ---------- */
    if ($action === 'save_staff') {
        $saved = 0;
        $bad   = array();
        if (isset($_POST['staff_rate']) && is_array($_POST['staff_rate'])) {
            foreach ($_POST['staff_rate'] as $sid => $val) {
                $sid = (int)$sid;
                if ($sid <= 0) { continue; }
                if (!is_numeric($val) || (float)$val < 0 || (float)$val > 100) {
                    $bad[] = '#' . $sid;
                    continue;
                }
                $stmt = mysqli_prepare($conn,
                    "UPDATE staff SET commission_rate = ? WHERE staff_id = ?");
                mysqli_stmt_bind_param($stmt, 'di', round((float)$val, 2), $sid);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $saved++;
            }
        }
        if ($bad) {
            set_flash('danger', 'Not saved for ' . count($bad) . ' profile(s) - rates must be numbers between 0 and 100.');
        } else {
            set_flash('success', 'Default rates saved for ' . $saved . ' stylist' . ($saved == 1 ? '' : 's') . '.');
        }
        redirect($self);
    }

    /* ---------- per-service overrides ---------- */
    if ($action === 'save_services') {
        $saved    = 0;
        $inherit  = 0;
        $bad      = array();
        if (isset($_POST['svc_rate']) && is_array($_POST['svc_rate'])) {
            foreach ($_POST['svc_rate'] as $svcid => $val) {
                $svcid = (int)$svcid;
                if ($svcid <= 0) { continue; }

                /* ticked "use stylist default" stores NULL */
                $use_default = isset($_POST['svc_inherit'][$svcid]);
                if ($use_default) {
                    $rate_v = null;
                    $inherit++;
                } else {
                    if (!is_numeric($val) || (float)$val < 0 || (float)$val > 100) {
                        $bad[] = '#' . $svcid;
                        continue;
                    }
                    $rate_v = round((float)$val, 2);
                }
                $stmt = mysqli_prepare($conn,
                    "UPDATE services SET commission_rate = ? WHERE service_id = ?");
                mysqli_stmt_bind_param($stmt, 'di', $rate_v, $svcid);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $saved++;
            }
        }
        if ($bad) {
            set_flash('danger', 'Not saved for ' . count($bad) . ' service(s) - override rates must be numbers between 0 and 100.');
        } else {
            set_flash('success', 'Commission overrides saved for ' . $saved . ' service'
                . ($saved == 1 ? '' : 's') . ' (' . $inherit . ' inherit the stylist default).');
        }
        redirect($self);
    }

    set_flash('warning', 'Unknown action.');
    redirect($self);
}

/* ============================================================
 *  DATA
 * ========================================================== */

/* ---------- stylists (all non-inactive + inactive for context) ---------- */
 $staff_rows = mysqli_fetch_all(mysqli_query($conn,
    "SELECT staff_id, name, specialization, status, commission_rate
     FROM staff
     ORDER BY FIELD(status, 'active', 'on_leave', 'inactive'), name ASC"), MYSQLI_ASSOC);

 $active_rates = array();
foreach ($staff_rows as $s) {
    if ($s['status'] !== 'inactive') {
        $active_rates[] = (float)$s['commission_rate'];
    }
}
 $rate_min = $active_rates ? min($active_rates) : null;
 $rate_max = $active_rates ? max($active_rates) : null;

/* ---------- services with current overrides ---------- */
 $services = mysqli_fetch_all(mysqli_query($conn,
    "SELECT service_id, name, category, price, duration_minutes, commission_rate
     FROM services
     WHERE is_active = 1
     ORDER BY FIELD(category, 'Hair', 'Nails', 'Facial', 'Makeup', 'Spa', 'Other'), name ASC"), MYSQLI_ASSOC);

 $page_title = 'Commission rates';
require_once __DIR__ . '/../../includes/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h5 class="mb-1"><i class="bi bi-coin"></i> Commission rates</h5>
        <p class="text-muted small mb-0">
            How much of each service price the stylist earns. A service override always
            wins over the stylist's default.
        </p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>modules/staff/index.php">
        <i class="bi bi-arrow-left"></i> Back to staff
    </a>
</div>

<div class="alert alert-info small">
    <i class="bi bi-calculator"></i> <b>How it is calculated:</b>
    stylist earns <code>service price &times; COALESCE(service override, stylist default) / 100</code>.
    Example: a &#8377;1,500 colouring at 12% earns <b>&#8377;180</b> per completed appointment.
    Commissions are paid out per completed appointment - see Reports (Step 10) for totals.
</div>

<div class="row g-3">
    <!-- ---------- stylist defaults ---------- -->
    <div class="col-lg-6">
        <div class="card card-salon h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-person-badge"></i> Stylist default rates</span>
                <span class="badge text-bg-light border"><?= count($staff_rows) ?></span>
            </div>
            <div class="card-body">
                <form method="post" action="<?= $self ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_staff">

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-3">
                            <thead>
                                <tr><th>Stylist</th><th style="width:140px;">Default %</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($staff_rows as $s): ?>
                                <tr class="<?= $s['status'] === 'inactive' ? 'table-light' : '' ?>">
                                    <td>
                                        <?= e($s['name']) ?>
                                        <?php if ($s['status'] !== 'active'): ?>
                                            <span class="badge text-bg-light border"><?= e(ucwords(str_replace('_', ' ', $s['status']))) ?></span>
                                        <?php endif; ?>
                                        <div class="small text-muted"><?= e($s['specialization'] !== null && $s['specialization'] !== '' ? $s['specialization'] : '') ?></div>
                                    </td>
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <input type="number" class="form-control" step="0.01" min="0" max="100"
                                                   name="staff_rate[<?= (int)$s['staff_id'] ?>]"
                                                   value="<?= e($s['commission_rate']) ?>">
                                            <span class="input-group-text">%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <button class="btn btn-salon w-100" type="submit">
                        <i class="bi bi-check2-circle"></i> Save default rates
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- ---------- service overrides ---------- -->
    <div class="col-lg-6">
        <div class="card card-salon h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-scissors"></i> Per-service overrides</span>
                <span class="badge text-bg-light border"><?= count($services) ?> services</span>
            </div>
            <div class="card-body">
                <form method="post" action="<?= $self ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_services">

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-3">
                            <thead>
                                <tr>
                                    <th>Service</th>
                                    <th class="text-end">Price</th>
                                    <th style="width:130px;">Override</th>
                                    <th style="width:70px;" class="text-center">Inherit</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($services as $sv): ?>
                                <tr>
                                    <td>
                                        <?= e($sv['name']) ?>
                                        <div class="small text-muted"><?= e($sv['category']) ?> · <?= (int)$sv['duration_minutes'] ?> min</div>
                                    </td>
                                    <td class="text-end text-nowrap"><?= money($sv['price']) ?></td>
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <input type="number" class="form-control" step="0.01" min="0" max="100"
                                                   id="svc-<?= (int)$sv['service_id'] ?>"
                                                   name="svc_rate[<?= (int)$sv['service_id'] ?>]"
                                                   value="<?= $sv['commission_rate'] !== null ? e($sv['commission_rate']) : '' ?>"
                                                   placeholder="<?= $rate_min !== null ? e(rtrim(rtrim($rate_min, '0'), '.')) : '' ?><?= ($rate_max !== null && $rate_max != $rate_min) ? '-' . e(rtrim(rtrim($rate_max, '0'), '.')) : '' ?>%">
                                            <span class="input-group-text">%</span>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <input class="form-check-input inh-check" type="checkbox"
                                               data-id="<?= (int)$sv['service_id'] ?>"
                                               name="svc_inherit[<?= (int)$sv['service_id'] ?>]"
                                               title="Use each stylist's default rate"
                                               <?= $sv['commission_rate'] === null ? 'checked' : '' ?>>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <button class="btn btn-salon w-100" type="submit">
                        <i class="bi bi-check2-circle"></i> Save overrides
                    </button>
                    <div class="form-text text-center">Leave a service on <b>Inherit</b> to use each stylist's
                        default rate; type a number to force the same rate for everyone.</div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
 $page_scripts = '<script>
document.querySelectorAll(".inh-check").forEach(function (cb) {
    var apply = function () {
        var input = document.getElementById("svc-" + cb.getAttribute("data-id"));
        if (input) { input.disabled = cb.checked; }
    };
    cb.addEventListener("change", apply);
    apply();
});
</script>';
require_once __DIR__ . '/../../includes/dash_footer.php';
?>