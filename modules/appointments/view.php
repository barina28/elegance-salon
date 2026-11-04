<?php
/**
 * ============================================================
 *  APPOINTMENTS - DETAIL / VIEW
 *  One appointment: full details, client card, status actions
 *  and the client's other upcoming visits.
 *  Roles: admin + receptionist (all), stylist (own only).
 * ============================================================
 */

require_once __DIR__ . '/../../includes/init.php';
require_role(array('admin', 'receptionist', 'stylist'));

$self = BASE_URL . 'modules/appointments/';
$u    = current_user();
$is_stylist = ($u['role'] === 'stylist');

$my_staff_id = 0;
if ($is_stylist) {
    $stmt = mysqli_prepare($conn, "SELECT staff_id FROM staff WHERE user_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $u['user_id']);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    $my_staff_id = $row ? (int)$row['staff_id'] : 0;
}

$id = (int)get('id');
if ($id <= 0) {
    set_flash('warning', 'No appointment selected.');
    redirect($self . 'index.php');
}

/* ---------- appointment with all names ---------- */
$stmt = mysqli_prepare($conn,
    "SELECT a.*, c.name AS client_name, c.phone AS client_phone, c.email AS client_email,
            st.name AS staff_name, st.specialization,
            s.name AS service_name, s.duration_minutes, s.price,
            bu.name AS booked_by_name
     FROM appointments a
     JOIN clients  c  ON c.client_id  = a.client_id
     JOIN staff    st ON st.staff_id  = a.staff_id
     JOIN services s  ON s.service_id = a.service_id
     LEFT JOIN users bu ON bu.user_id = a.booked_by
     WHERE a.appointment_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$appt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$appt) {
    set_flash('danger', 'Appointment not found.');
    redirect($self . 'index.php');
}

if ($is_stylist && (int)$appt['staff_id'] !== $my_staff_id) {
    set_flash('danger', 'Access denied: this appointment belongs to another stylist.');
    redirect($self . 'index.php');
}

/* ---------- transition map + shared POST handler ---------- */
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
$return_url = $self . 'view.php?id=' . $id;

require_once __DIR__ . '/_actions.php';

/* ---------- the client's other upcoming appointments ---------- */
$others = array();
$stmt = mysqli_prepare($conn,
    "SELECT a.appointment_id, a.appointment_date, a.start_time, a.status,
            s.name AS service_name, st.name AS staff_name
     FROM appointments a
     JOIN services s ON s.service_id = a.service_id
     JOIN staff    st ON st.staff_id  = a.staff_id
     WHERE a.client_id = ? AND a.appointment_id <> ?
       AND a.appointment_date >= CURDATE()
       AND a.status IN ('scheduled','confirmed')
     ORDER BY a.appointment_date ASC, a.start_time ASC
     LIMIT 5");
mysqli_stmt_bind_param($stmt, 'ii', $appt['client_id'], $id);
mysqli_stmt_execute($stmt);
$others = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

/* ---------- recent notifications related to this client/appointment ---------- */
$notifications = array();
$email_param = (string)($appt['client_email'] ?? '');
$name_param  = (string)($appt['client_name'] ?? '');
if ($email_param !== '' || $name_param !== '') {
    $stmt_notif = mysqli_prepare($conn,
        "SELECT notification_id, recipient, channel, subject, message, status, created_at, sent_at
         FROM notifications
         WHERE (recipient = ? OR recipient = ?) AND type = 'appointment'
         ORDER BY notification_id DESC
         LIMIT 5");
    mysqli_stmt_bind_param($stmt_notif, 'ss', $email_param, $name_param);
    mysqli_stmt_execute($stmt_notif);
    $notifications = mysqli_fetch_all(mysqli_stmt_get_result($stmt_notif), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt_notif);
}

/* labels for the action buttons */
$action_ui = array(
    'confirmed' => array('label' => 'Confirm appointment',  'icon' => 'bi-check2-circle',         'btn' => 'btn-outline-success', 'confirm' => ''),
    'completed' => array('label' => 'Mark completed',       'icon' => 'bi-clipboard-check',       'btn' => 'btn-outline-primary', 'confirm' => ''),
    'cancelled' => array('label' => 'Cancel appointment',   'icon' => 'bi-x-circle',              'btn' => 'btn-outline-danger',  'confirm' => 'Cancel this appointment? The client will be notified.'),
    'no_show'   => array('label' => 'Mark as no-show',      'icon' => 'bi-person-dash',           'btn' => 'btn-outline-warning', 'confirm' => 'Mark this client as a no-show?'),
    'scheduled' => array('label' => 'Reopen booking',       'icon' => 'bi-arrow-counterclockwise','btn' => 'btn-outline-secondary','confirm' => ''),
);

$page_title = 'Appointment details';
require_once __DIR__ . '/../../includes/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h5 class="mb-0"><i class="bi bi-calendar2-check"></i> Appointment details</h5>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= $self ?>index.php">
            <i class="bi bi-arrow-left"></i> Appointment book
        </a>
        <?php if (!$is_stylist && in_array($appt['status'], array('scheduled','confirmed'), true)): ?>
            <a class="btn btn-salon btn-sm" href="<?= $self ?>form.php?id=<?= (int)$appt['appointment_id'] ?>">
                <i class="bi bi-pencil"></i> Reschedule
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <!-- ================= main details ================= -->
    <div class="col-lg-8">
        <div class="card card-salon">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-card-text"></i> Booking</span>
                <span class="pill pill-<?= e($appt['status']) ?>"><?= e(ucwords(str_replace('_', ' ', $appt['status']))) ?></span>
            </div>
            <ul class="list-group list-group-flush">
                <li class="list-group-item d-flex justify-content-between">
                    <span class="text-muted"><i class="bi bi-calendar3 me-2"></i>Date</span>
                    <b><?= e(date('l, d M Y', strtotime($appt['appointment_date']))) ?></b>
                </li>
                <li class="list-group-item d-flex justify-content-between">
                    <span class="text-muted"><i class="bi bi-clock me-2"></i>Time</span>
                    <b><?= e(date('h:i A', strtotime($appt['start_time']))) ?>
                        - <?= e(date('h:i A', strtotime($appt['end_time']))) ?>
                        <span class="text-muted fw-normal">(<?= (int)$appt['duration_minutes'] ?> min)</span></b>
                </li>
                <li class="list-group-item d-flex justify-content-between">
                    <span class="text-muted"><i class="bi bi-scissors me-2"></i>Service</span>
                    <b><?= e($appt['service_name']) ?> &middot; <?= money($appt['price']) ?></b>
                </li>
                <li class="list-group-item d-flex justify-content-between">
                    <span class="text-muted"><i class="bi bi-person-badge me-2"></i>Stylist</span>
                    <b><?= e($appt['staff_name']) ?></b>
                </li>
                <li class="list-group-item d-flex justify-content-between">
                    <span class="text-muted"><i class="bi bi-person me-2"></i>Client</span>
                    <?php if (!$is_stylist): ?>
                        <b><a class="text-decoration-none" href="<?= BASE_URL ?>modules/clients/view.php?id=<?= (int)$appt['client_id'] ?>">
                            <?= e($appt['client_name']) ?>
                        </a></b>
                    <?php else: ?>
                        <b><?= e($appt['client_name']) ?></b>
                    <?php endif; ?>
                </li>
                <li class="list-group-item d-flex justify-content-between">
                    <span class="text-muted"><i class="bi bi-telephone me-2"></i>Phone</span>
                    <a class="text-decoration-none" href="tel:+<?= e(digits_only($appt['client_phone'])) ?>">
                        <?= e($appt['client_phone']) ?></a>
                </li>
                <li class="list-group-item d-flex justify-content-between">
                    <span class="text-muted"><i class="bi bi-envelope me-2"></i>Email</span>
                    <?= $appt['client_email'] !== null && $appt['client_email'] !== ''
                        ? '<a class="text-decoration-none" href="mailto:' . e($appt['client_email']) . '">' . e($appt['client_email']) . '</a>'
                        : '<span class="text-muted">no email on file</span>' ?>
                </li>
                <li class="list-group-item d-flex justify-content-between">
                    <span class="text-muted"><i class="bi bi-person-plus me-2"></i>Booked by</span>
                    <span><?= e($appt['booked_by_name'] !== null ? $appt['booked_by_name'] : 'online / unknown') ?></span>
                </li>
                <?php if ($appt['notes'] !== null && $appt['notes'] !== ''): ?>
                    <li class="list-group-item">
                        <span class="text-muted"><i class="bi bi-journal-text me-2"></i>Notes</span>
                        <div class="mt-1" style="white-space:pre-wrap;"><?= e($appt['notes']) ?></div>
                    </li>
                <?php endif; ?>
                <li class="list-group-item d-flex justify-content-between small text-muted">
                    <span>Created <?= e(date('d M Y, h:i A', strtotime($appt['created_at']))) ?></span>
                    <span>Updated <?= e(date('d M Y, h:i A', strtotime($appt['updated_at']))) ?></span>
                </li>
            </ul>
        </div>

        <!-- Notification History -->
        <div class="card card-salon mt-3">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span><i class="bi bi-envelope-check text-salon me-1"></i> Client Notifications & Email Log</span>
                <span class="badge bg-light text-dark border"><?= count($notifications) ?> logged</span>
            </div>
            <?php if (empty($notifications)): ?>
                <div class="card-body text-muted small">
                    <i class="bi bi-info-circle me-1"></i> No notifications logged for this client yet.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light small">
                            <tr>
                                <th>Subject</th>
                                <th>Channel</th>
                                <th>Recipient</th>
                                <th>Status</th>
                                <th>Date / Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($notifications as $n): ?>
                                <tr>
                                    <td class="small fw-semibold"><?= e($n['subject']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $n['channel'] === 'email' ? 'primary' : 'secondary' ?>">
                                            <i class="bi bi-<?= $n['channel'] === 'email' ? 'envelope' : 'phone' ?> me-1"></i><?= e($n['channel']) ?>
                                        </span>
                                    </td>
                                    <td class="small text-muted"><?= e($n['recipient']) ?></td>
                                    <td>
                                        <?php if ($n['status'] === 'sent'): ?>
                                            <span class="badge bg-success" title="Sent at <?= e($n['sent_at']) ?>"><i class="bi bi-check-circle me-1"></i>Sent</span>
                                        <?php elseif ($n['status'] === 'pending'): ?>
                                            <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i>Pending</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Failed</span>
                                            <?php 
                                            if (strpos($n['message'], '[Delivery Status]:') !== false) {
                                                $parts = explode('[Delivery Status]:', $n['message']);
                                                echo '<div class="text-danger small mt-1" style="font-size:0.75rem;">' . e(trim($parts[1])) . '</div>';
                                            } else {
                                                echo '<div class="text-danger small mt-1" style="font-size:0.75rem;">SMTP not configured</div>';
                                            }
                                            ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted"><?= e(date('d M Y, h:i A', strtotime($n['created_at']))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ================= actions + client extras ================= -->
    <div class="col-lg-4">
        <div class="card card-salon mb-3">
            <div class="card-header"><i class="bi bi-lightning"></i> Actions</div>
            <div class="card-body d-grid gap-2">
                <?php if (!$transitions[$appt['status']]): ?>
                    <div class="text-muted small">
                        <i class="bi bi-lock"></i> This appointment is completed - the record is locked.
                    </div>
                <?php else: ?>
                    <?php foreach ($transitions[$appt['status']] as $to): ?>
                        <form method="post" action="<?= $return_url ?>"
                              <?= $action_ui[$to]['confirm'] !== '' ? 'data-confirm="' . e($action_ui[$to]['confirm']) . '"' : '' ?>>
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="<?= array_search($to, $allowed_actions, true) ?>">
                            <input type="hidden" name="appointment_id" value="<?= (int)$appt['appointment_id'] ?>">
                            <button type="submit" class="btn w-100 <?= $action_ui[$to]['btn'] ?>">
                                <i class="bi <?= $action_ui[$to]['icon'] ?>"></i> <?= $action_ui[$to]['label'] ?>
                            </button>
                        </form>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$others): ?>
            <div class="alert alert-light border small mb-0">
                <i class="bi bi-calendar-x"></i> No other upcoming appointments for this client.
            </div>
        <?php else: ?>
            <div class="card card-salon">
                <div class="card-header"><i class="bi bi-calendar-week"></i> Client's next visits</div>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($others as $o): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span>
                                <b><?= e(date('d M', strtotime($o['appointment_date']))) ?></b>
                                <?= e(date('h:i A', strtotime($o['start_time']))) ?>
                                <div class="text-muted"><?= e($o['service_name']) ?> &middot; <?= e($o['staff_name']) ?></div>
                            </span>
                            <span class="pill pill-<?= e($o['status']) ?>"><?= e(ucwords(str_replace('_', ' ', $o['status']))) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/dash_footer.php'; ?>
