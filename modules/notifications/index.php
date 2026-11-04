<?php
/**
 * ============================================================
 *  NOTIFICATIONS - INBOX & DISPATCH AUDIT LOG
 *  View all emails, SMS notices, and system alerts generated
 *  across appointments, inventory, and payments.
 *  Allows admins and receptionists to retry failed deliveries.
 *  Access: admin + receptionist
 * ============================================================
 */

require_once __DIR__ . '/../../includes/init.php';
require_role(array('admin', 'receptionist'));

$self = BASE_URL . 'modules/notifications/';

/* ============================================================
 *  POST ACTIONS - Resend Email / Delete
 * ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = post('action');
    $id     = (int)post('notification_id');

    if ($action === 'resend' && $id > 0) {
        $stmt = mysqli_prepare($conn, "SELECT * FROM notifications WHERE notification_id = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $notif = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$notif) {
            $_SESSION['flash_error'] = 'Notification not found.';
        } elseif ($notif['channel'] !== 'email') {
            $_SESSION['flash_error'] = 'Only email notifications can be dispatched via SMTP.';
        } else {
            $salon_name = get_setting('salon_name', defined('SITE_NAME') ? SITE_NAME : 'Elegance Salon');
            $html_body  = render_email_template($notif['subject'], $notif['recipient'], $notif['message'], $salon_name);
            $res        = send_email($notif['recipient'], $notif['recipient'], $notif['subject'], $html_body, $notif['message']);

            if (!empty($res['success'])) {
                $now = date('Y-m-d H:i:s');
                $update = mysqli_prepare($conn, "UPDATE notifications SET status = 'sent', sent_at = ? WHERE notification_id = ?");
                mysqli_stmt_bind_param($update, 'si', $now, $id);
                mysqli_stmt_execute($update);
                mysqli_stmt_close($update);

                $_SESSION['flash_success'] = "Email successfully resent to {$notif['recipient']}!";
            } else {
                $err_msg = $notif['message'];
                if (strpos($err_msg, '[Delivery Status]:') === false) {
                    $err_msg .= "\n\n[Delivery Status]: " . ($res['error'] ?? 'Unknown error');
                }
                $update = mysqli_prepare($conn, "UPDATE notifications SET status = 'failed', message = ? WHERE notification_id = ?");
                mysqli_stmt_bind_param($update, 'si', $err_msg, $id);
                mysqli_stmt_execute($update);
                mysqli_stmt_close($update);

                $_SESSION['flash_error'] = "Failed to send email: " . ($res['error'] ?? 'Unknown error');
            }
        }
        redirect($self);
    } elseif ($action === 'delete' && $id > 0) {
        $stmt = mysqli_prepare($conn, "DELETE FROM notifications WHERE notification_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $_SESSION['flash_success'] = 'Notification deleted.';
        redirect($self);
    }
}

/* ============================================================
 *  SEARCH & FILTERS
 * ========================================================== */
$filter_status  = get('status');
$filter_channel = get('channel');
$filter_type    = get('type');
$search_q       = get('q');

$where_clauses = array();
$params = array();
$types  = '';

if ($filter_status !== '' && in_array($filter_status, array('pending', 'sent', 'failed'), true)) {
    $where_clauses[] = "status = ?";
    $params[] = $filter_status;
    $types   .= 's';
}
if ($filter_channel !== '' && in_array($filter_channel, array('email', 'system', 'sms'), true)) {
    $where_clauses[] = "channel = ?";
    $params[] = $filter_channel;
    $types   .= 's';
}
if ($filter_type !== '' && in_array($filter_type, array('appointment', 'inventory', 'payment', 'system'), true)) {
    $where_clauses[] = "type = ?";
    $params[] = $filter_type;
    $types   .= 's';
}
if ($search_q !== '') {
    $where_clauses[] = "(recipient LIKE ? OR subject LIKE ? OR message LIKE ?)";
    $like = '%' . $search_q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types   .= 'sss';
}

$where_sql = $where_clauses ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Stats
$total_all     = (int)mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM notifications"))[0];
$total_sent    = (int)mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM notifications WHERE status = 'sent'"))[0];
$total_pending = (int)mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM notifications WHERE status = 'pending'"))[0];
$total_failed  = (int)mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM notifications WHERE status = 'failed'"))[0];

// Query notifications with limit
$stmt = mysqli_prepare($conn, "SELECT * FROM notifications {$where_sql} ORDER BY notification_id DESC LIMIT 50");
if ($types !== '') {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$notifications = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$page_title = 'Notification Logs';
require_once __DIR__ . '/../../includes/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1 fw-bold text-dark"><i class="bi bi-bell text-salon me-2"></i>Notification Center</h1>
        <p class="text-muted small mb-0">Audit log of appointment booking receipts, reschedules, low-stock alerts, and client emails.</p>
    </div>
</div>

<?php if (isset($_SESSION['flash_success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-2"></i><?= e($_SESSION['flash_success']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['flash_error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle me-2"></i><?= e($_SESSION['flash_error']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<!-- Summary Counters -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card card-salon text-center p-3">
            <div class="text-muted small">Total Logged</div>
            <div class="fs-4 fw-bold text-dark"><?= $total_all ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-salon text-center p-3">
            <div class="text-muted small">Successfully Sent</div>
            <div class="fs-4 fw-bold text-success"><?= $total_sent ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-salon text-center p-3">
            <div class="text-muted small">Pending</div>
            <div class="fs-4 fw-bold text-warning"><?= $total_pending ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-salon text-center p-3">
            <div class="text-muted small">Failed</div>
            <div class="fs-4 fw-bold text-danger"><?= $total_failed ?></div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card card-salon mb-4">
    <div class="card-body p-3">
        <form method="get" action="" class="row g-2 align-items-center">
            <div class="col-md-4">
                <input type="text" name="q" class="form-control" placeholder="Search recipient, subject..." value="<?= e($search_q) ?>">
            </div>
            <div class="col-md-2">
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="sent" <?= $filter_status === 'sent' ? 'selected' : '' ?>>Sent</option>
                    <option value="pending" <?= $filter_status === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="failed" <?= $filter_status === 'failed' ? 'selected' : '' ?>>Failed</option>
                </select>
            </div>
            <div class="col-md-2">
                <select name="channel" class="form-select">
                    <option value="">All Channels</option>
                    <option value="email" <?= $filter_channel === 'email' ? 'selected' : '' ?>>Email</option>
                    <option value="system" <?= $filter_channel === 'system' ? 'selected' : '' ?>>System</option>
                    <option value="sms" <?= $filter_channel === 'sms' ? 'selected' : '' ?>>SMS</option>
                </select>
            </div>
            <div class="col-md-2">
                <select name="type" class="form-select">
                    <option value="">All Types</option>
                    <option value="appointment" <?= $filter_type === 'appointment' ? 'selected' : '' ?>>Appointment</option>
                    <option value="inventory" <?= $filter_type === 'inventory' ? 'selected' : '' ?>>Inventory</option>
                    <option value="payment" <?= $filter_type === 'payment' ? 'selected' : '' ?>>Payment</option>
                    <option value="system" <?= $filter_type === 'system' ? 'selected' : '' ?>>System</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-outline-salon w-100"><i class="bi bi-filter"></i> Filter</button>
                <?php if ($search_q !== '' || $filter_status !== '' || $filter_channel !== '' || $filter_type !== ''): ?>
                    <a href="<?= $self ?>" class="btn btn-outline-secondary" title="Clear Filters"><i class="bi bi-x-circle"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Notifications Table -->
<div class="card card-salon">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-bold"><i class="bi bi-envelope-paper text-salon me-2"></i>Notification Dispatches</span>
        <span class="badge bg-light text-dark border"><?= count($notifications) ?> displayed</span>
    </div>
    <?php if (empty($notifications)): ?>
        <div class="card-body text-center py-5 text-muted">
            <i class="bi bi-inbox fs-1 d-block mb-2 text-muted"></i>
            No notifications match your search criteria.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Subject & Recipient</th>
                        <th>Channel</th>
                        <th>Type</th>
                        <th>Status & Delivery Note</th>
                        <th>Logged At</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($notifications as $n): ?>
                        <tr>
                            <td>
                                <div class="fw-bold text-dark"><?= e($n['subject']) ?></div>
                                <div class="text-muted"><?= e($n['recipient']) ?></div>
                            </td>
                            <td>
                                <span class="badge bg-<?= $n['channel'] === 'email' ? 'primary' : ($n['channel'] === 'sms' ? 'success' : 'secondary') ?>">
                                    <i class="bi bi-<?= $n['channel'] === 'email' ? 'envelope' : 'chat' ?> me-1"></i><?= e($n['channel']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border"><?= e(ucfirst($n['type'])) ?></span>
                            </td>
                            <td>
                                <?php if ($n['status'] === 'sent'): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Sent</span>
                                    <?php if ($n['sent_at']): ?>
                                        <div class="text-muted" style="font-size:0.75rem;"><?= e(date('h:i A, d M', strtotime($n['sent_at']))) ?></div>
                                    <?php endif; ?>
                                <?php elseif ($n['status'] === 'pending'): ?>
                                    <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i>Pending</span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Failed</span>
                                    <?php
                                    if (strpos($n['message'], '[Delivery Status]:') !== false) {
                                        $parts = explode('[Delivery Status]:', $n['message']);
                                        echo '<div class="text-danger mt-1" style="font-size:0.75rem;">' . e(trim($parts[1])) . '</div>';
                                    } elseif (strpos($n['message'], '[Delivery Note]:') !== false) {
                                        $parts = explode('[Delivery Note]:', $n['message']);
                                        echo '<div class="text-danger mt-1" style="font-size:0.75rem;">' . e(trim($parts[1])) . '</div>';
                                    }
                                    ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted text-nowrap">
                                <?= e(date('d M Y, h:i A', strtotime($n['created_at']))) ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" data-bs-toggle="modal" data-bs-target="#viewModal<?= (int)$n['notification_id'] ?>" title="View Message">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <?php if ($n['channel'] === 'email'): ?>
                                    <form method="post" action="" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="resend">
                                        <input type="hidden" name="notification_id" value="<?= (int)$n['notification_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-salon py-0 px-2" title="Resend Email">
                                            <i class="bi bi-arrow-repeat"></i> Resend
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>

                        <!-- View Message Modal -->
                        <div class="modal fade" id="viewModal<?= (int)$n['notification_id'] ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title fw-bold"><i class="bi bi-envelope text-salon me-2"></i>Notification Details</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <p class="mb-1"><strong>Subject:</strong> <?= e($n['subject']) ?></p>
                                        <p class="mb-1"><strong>Recipient:</strong> <?= e($n['recipient']) ?></p>
                                        <p class="mb-3"><strong>Channel:</strong> <?= e($n['channel']) ?> &bull; <strong>Status:</strong> <?= e($n['status']) ?></p>
                                        <hr>
                                        <label class="form-label fw-bold">Message Content:</label>
                                        <div class="bg-light p-3 rounded text-dark" style="white-space:pre-wrap; font-size:0.9rem;"><?= e($n['message']) ?></div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/dash_footer.php'; ?>

