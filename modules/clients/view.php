<?php
/**
 * ============================================================
 *  CLIENTS - PROFILE / VIEW
 *  Shows client identity, contact information, appointment
 *  history, upcoming visits, and notification logs.
 *  Roles: admin, receptionist, stylist.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/init.php';
require_role(array('admin', 'receptionist', 'stylist'));

$self = BASE_URL . 'modules/clients/';

$id = (int)get('id');
if ($id <= 0) {
    set_flash('warning', 'No client selected.');
    redirect($self . 'index.php');
}

/* ---------- 1. Fetch client details ---------- */
$stmt = mysqli_prepare($conn,
    "SELECT c.*, st.name AS stylist_name, st.specialization AS stylist_spec
     FROM clients c
     LEFT JOIN staff st ON st.staff_id = c.preferred_stylist_id
     WHERE c.client_id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$client = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$client) {
    set_flash('danger', 'Client not found.');
    redirect($self . 'index.php');
}

/* ---------- 2. Client statistics ---------- */
$stmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM appointments WHERE client_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$total_visits = (int)mysqli_fetch_row(mysqli_stmt_get_result($stmt))[0];
mysqli_stmt_close($stmt);

$stmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM appointments WHERE client_id = ? AND status = 'completed'");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$completed_visits = (int)mysqli_fetch_row(mysqli_stmt_get_result($stmt))[0];
mysqli_stmt_close($stmt);

$stmt = mysqli_prepare($conn, "SELECT COALESCE(SUM(total_amount), 0) FROM payments WHERE client_id = ? AND status = 'paid'");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$total_spent = (float)mysqli_fetch_row(mysqli_stmt_get_result($stmt))[0];
mysqli_stmt_close($stmt);

/* ---------- 3. Upcoming appointments ---------- */
$stmt_up = mysqli_prepare($conn,
    "SELECT a.appointment_id, a.appointment_date, a.start_time, a.end_time, a.status,
            s.name AS service_name, s.price, st.name AS staff_name
     FROM appointments a
     JOIN services s ON s.service_id = a.service_id
     JOIN staff st ON st.staff_id = a.staff_id
     WHERE a.client_id = ? AND a.appointment_date >= CURDATE()
       AND a.status IN ('scheduled', 'confirmed')
     ORDER BY a.appointment_date ASC, a.start_time ASC");
mysqli_stmt_bind_param($stmt_up, 'i', $id);
mysqli_stmt_execute($stmt_up);
$upcoming_appointments = mysqli_fetch_all(mysqli_stmt_get_result($stmt_up), MYSQLI_ASSOC);
mysqli_stmt_close($stmt_up);

/* ---------- 4. Appointment history (all past & recent) ---------- */
$stmt_hist = mysqli_prepare($conn,
    "SELECT a.appointment_id, a.appointment_date, a.start_time, a.end_time, a.status,
            s.name AS service_name, s.price, st.name AS staff_name
     FROM appointments a
     JOIN services s ON s.service_id = a.service_id
     JOIN staff st ON st.staff_id = a.staff_id
     WHERE a.client_id = ?
     ORDER BY a.appointment_date DESC, a.start_time DESC
     LIMIT 15");
mysqli_stmt_bind_param($stmt_hist, 'i', $id);
mysqli_stmt_execute($stmt_hist);
$history = mysqli_fetch_all(mysqli_stmt_get_result($stmt_hist), MYSQLI_ASSOC);
mysqli_stmt_close($stmt_hist);

/* ---------- 5. Notification & Email Log ---------- */
$notifications = array();
$email_q = (string)($client['email'] ?? '');
$name_q  = (string)($client['name'] ?? '');
if ($email_q !== '' || $name_q !== '') {
    $stmt_n = mysqli_prepare($conn,
        "SELECT notification_id, recipient, channel, subject, message, status, created_at, sent_at
         FROM notifications
         WHERE recipient = ? OR recipient = ?
         ORDER BY notification_id DESC
         LIMIT 5");
    mysqli_stmt_bind_param($stmt_n, 'ss', $email_q, $name_q);
    mysqli_stmt_execute($stmt_n);
    $notifications = mysqli_fetch_all(mysqli_stmt_get_result($stmt_n), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt_n);
}

$page_title = 'Client - ' . $client['name'];
require_once __DIR__ . '/../../includes/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div class="d-flex align-items-center gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= $self ?>index.php">
            <i class="bi bi-arrow-left"></i>
        </a>
        <h4 class="mb-0 fw-bold"><?= e($client['name']) ?></h4>
        <span class="pill pill-<?= $client['is_active'] ? 'confirmed' : 'cancelled' ?>">
            <?= $client['is_active'] ? 'Active' : 'Inactive' ?>
        </span>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-salon btn-sm" href="<?= BASE_URL ?>modules/appointments/form.php?client_id=<?= (int)$client['client_id'] ?>">
            <i class="bi bi-calendar-plus me-1"></i> Book Appointment
        </a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= $self ?>form.php?id=<?= (int)$client['client_id'] ?>">
            <i class="bi bi-pencil me-1"></i> Edit Profile
        </a>
    </div>
</div>

<div class="row g-4">
    <!-- ================= Left Column: Client Identity & Info ================= -->
    <div class="col-lg-4">
        <div class="card card-salon mb-4 text-center">
            <div class="card-body p-4">
                <div class="avatar-circle mx-auto mb-3" style="width:72px; height:72px; font-size:1.8rem; background:linear-gradient(135deg, #b76e79, #c9a227); color:#fff; display:flex; align-items:center; justify-content:center; border-radius:50%; box-shadow:0 4px 10px rgba(183,110,121,0.25);">
                    <?= e(mb_strtoupper(mb_substr($client['name'], 0, 1))) ?>
                </div>
                <h5 class="fw-bold mb-1"><?= e($client['name']) ?></h5>
                <p class="text-muted small mb-3">Client ID #<?= (int)$client['client_id'] ?></p>

                <div class="row g-2 text-center border-top border-bottom py-3 mb-3">
                    <div class="col-4">
                        <div class="fw-bold fs-5 text-dark"><?= $total_visits ?></div>
                        <div class="text-muted small">Total Visits</div>
                    </div>
                    <div class="col-4 border-start border-end">
                        <div class="fw-bold fs-5 text-success"><?= $completed_visits ?></div>
                        <div class="text-muted small">Completed</div>
                    </div>
                    <div class="col-4">
                        <div class="fw-bold fs-5 text-salon"><?= money($total_spent) ?></div>
                        <div class="text-muted small">Total Spent</div>
                    </div>
                </div>

                <ul class="list-group list-group-flush text-start small">
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted"><i class="bi bi-telephone me-2"></i>Phone</span>
                        <a class="text-decoration-none fw-semibold" href="tel:+<?= e(digits_only($client['phone'])) ?>">
                            <?= e($client['phone']) ?>
                        </a>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted"><i class="bi bi-envelope me-2"></i>Email</span>
                        <?= $client['email'] !== null && $client['email'] !== ''
                            ? '<a class="text-decoration-none fw-semibold text-truncate" style="max-width:180px;" href="mailto:' . e($client['email']) . '">' . e($client['email']) . '</a>'
                            : '<span class="text-muted">none</span>' ?>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted"><i class="bi bi-gender-ambiguous me-2"></i>Gender</span>
                        <span class="text-dark"><?= e($client['gender'] ?: '-') ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted"><i class="bi bi-cake2 me-2"></i>Date of Birth</span>
                        <span class="text-dark"><?= $client['date_of_birth'] ? e(date('d M Y', strtotime($client['date_of_birth']))) : '-' ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted"><i class="bi bi-person-heart me-2"></i>Preferred Stylist</span>
                        <span class="text-dark fw-semibold"><?= e($client['stylist_name'] ?: 'None') ?></span>
                    </li>
                    <?php if (!empty($client['address'])): ?>
                        <li class="list-group-item px-0">
                            <span class="text-muted d-block mb-1"><i class="bi bi-geo-alt me-2"></i>Address</span>
                            <span class="text-dark"><?= e($client['address']) ?></span>
                        </li>
                    <?php endif; ?>
                    <?php if (!empty($client['preferences'])): ?>
                        <li class="list-group-item px-0">
                            <span class="text-muted d-block mb-1"><i class="bi bi-journal-text me-2"></i>Preferences & Notes</span>
                            <div class="bg-light p-2 rounded text-dark"><?= nl2br(e($client['preferences'])) ?></div>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>

    <!-- ================= Right Column: Appointments & History ================= -->
    <div class="col-lg-8">
        <!-- Upcoming Visits -->
        <div class="card card-salon mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-bold"><i class="bi bi-calendar2-check text-salon me-2"></i>Upcoming Appointments</span>
                <span class="badge bg-light text-dark border"><?= count($upcoming_appointments) ?> booked</span>
            </div>
            <?php if (empty($upcoming_appointments)): ?>
                <div class="card-body text-center py-4 text-muted small">
                    <i class="bi bi-calendar-x fs-4 d-block mb-2 text-muted"></i>
                    No upcoming appointments scheduled for this client.
                    <div class="mt-2">
                        <a href="<?= BASE_URL ?>modules/appointments/form.php?client_id=<?= (int)$client['client_id'] ?>" class="btn btn-sm btn-outline-salon">
                            <i class="bi bi-plus-circle me-1"></i>Schedule a Visit
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th>Date & Time</th>
                                <th>Service</th>
                                <th>Stylist</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($upcoming_appointments as $u): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold"><?= e(date('d M Y', strtotime($u['appointment_date']))) ?></div>
                                        <div class="text-muted"><?= e(date('h:i A', strtotime($u['start_time']))) ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?= e($u['service_name']) ?></div>
                                        <div class="text-muted"><?= money($u['price']) ?></div>
                                    </td>
                                    <td><?= e($u['staff_name']) ?></td>
                                    <td>
                                        <span class="pill pill-<?= e($u['status']) ?>"><?= e(ucwords(str_replace('_', ' ', $u['status']))) ?></span>
                                    </td>
                                    <td class="text-end">
                                        <a href="<?= BASE_URL ?>modules/appointments/view.php?id=<?= (int)$u['appointment_id'] ?>" class="btn btn-sm btn-outline-salon py-0 px-2">
                                            <i class="bi bi-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Appointment History -->
        <div class="card card-salon mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-bold"><i class="bi bi-clock-history text-salon me-2"></i>Appointment History</span>
                <span class="badge bg-light text-dark border"><?= count($history) ?> records</span>
            </div>
            <?php if (empty($history)): ?>
                <div class="card-body text-center py-4 text-muted small">
                    <i class="bi bi-journal-x fs-4 d-block mb-2 text-muted"></i>
                    No appointment history recorded yet.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th>Date & Time</th>
                                <th>Service</th>
                                <th>Stylist</th>
                                <th>Status</th>
                                <th class="text-end">Detail</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $h): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= e(date('d M Y', strtotime($h['appointment_date']))) ?></div>
                                        <div class="text-muted"><?= e(date('h:i A', strtotime($h['start_time']))) ?></div>
                                    </td>
                                    <td>
                                        <div><?= e($h['service_name']) ?></div>
                                        <div class="text-muted"><?= money($h['price']) ?></div>
                                    </td>
                                    <td><?= e($h['staff_name']) ?></td>
                                    <td>
                                        <span class="pill pill-<?= e($h['status']) ?>"><?= e(ucwords(str_replace('_', ' ', $h['status']))) ?></span>
                                    </td>
                                    <td class="text-end">
                                        <a href="<?= BASE_URL ?>modules/appointments/view.php?id=<?= (int)$h['appointment_id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Notification & Email Log -->
        <div class="card card-salon">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-bold"><i class="bi bi-envelope-check text-salon me-2"></i>Notifications Sent to Client</span>
                <span class="badge bg-light text-dark border"><?= count($notifications) ?> logged</span>
            </div>
            <?php if (empty($notifications)): ?>
                <div class="card-body text-muted small py-3">
                    <i class="bi bi-info-circle me-1"></i> No emails or notifications logged for this client yet.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th>Subject</th>
                                <th>Channel</th>
                                <th>Recipient</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($notifications as $n): ?>
                                <tr>
                                    <td class="fw-semibold"><?= e($n['subject']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $n['channel'] === 'email' ? 'primary' : 'secondary' ?>">
                                            <i class="bi bi-<?= $n['channel'] === 'email' ? 'envelope' : 'chat' ?> me-1"></i><?= e($n['channel']) ?>
                                        </span>
                                    </td>
                                    <td class="text-muted"><?= e($n['recipient']) ?></td>
                                    <td>
                                        <?php if ($n['status'] === 'sent'): ?>
                                            <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Sent</span>
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
                                    <td class="text-muted"><?= e(date('d M Y, h:i A', strtotime($n['created_at']))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/dash_footer.php'; ?>
