<?php
/**
 * ============================================================
 *  APPOINTMENTS - BOOK NEW / EDIT (RESCHEDULE)
 *  Access: admin + receptionist (stylists work from the list).
 *  The chosen time is re-validated on the SERVER against the
 *  stylist's shift, working days and existing bookings, so the
 *  browser widget can never sneak in a double booking.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/init.php';
require_role(array('admin', 'receptionist'));

 $self = BASE_URL . 'modules/appointments/';

 $id      = (int)get('id');
 $editing = $id > 0;
 $today   = date('Y-m-d');

/* ---------- dropdown data ---------- */
 $clients = mysqli_fetch_all(mysqli_query($conn,
    "SELECT client_id, name, phone FROM clients WHERE is_active = 1 ORDER BY name ASC"),
    MYSQLI_ASSOC);
 $staff_rows = mysqli_fetch_all(mysqli_query($conn,
    "SELECT staff_id, name, specialization FROM staff WHERE status = 'active' ORDER BY name ASC"),
    MYSQLI_ASSOC);
 $services = mysqli_fetch_all(mysqli_query($conn,
    "SELECT service_id, name, duration_minutes, price FROM services WHERE is_active = 1 ORDER BY name ASC"),
    MYSQLI_ASSOC);

/* ---------- appointment being edited ---------- */
 $appt = null;
if ($editing) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM appointments WHERE appointment_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $appt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$appt) {
        set_flash('danger', 'Appointment not found.');
        redirect($self . 'index.php');
    }
    if ($appt['status'] === 'completed') {
        set_flash('warning', 'Completed appointments are locked and cannot be edited.');
        redirect($self . 'view.php?id=' . $id);
    }
}

/* ---------- values (POST -> old input -> defaults) ---------- */
 $old = array(
    'client_id'        => 0,
    'staff_id'         => 0,
    'service_id'       => 0,
    'appointment_date' => $today,
    'start_time'       => '',
    'notes'            => '',
);

if ($editing) {
    $old['client_id']        = (int)$appt['client_id'];
    $old['staff_id']         = (int)$appt['staff_id'];
    $old['service_id']       = (int)$appt['service_id'];
    $old['appointment_date'] = $appt['appointment_date'];
    $old['start_time']       = substr($appt['start_time'], 0, 5);
    $old['notes']            = (string)$appt['notes'];
} elseif (get('client_id') !== '') {
    /* "Book appointment" button on a client profile */
    $old['client_id'] = (int)get('client_id');
    /* sensible default: the client's preferred stylist */
    $stmt = mysqli_prepare($conn, "SELECT preferred_stylist_id FROM clients WHERE client_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $old['client_id']);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if ($row && $row['preferred_stylist_id']) {
        $old['staff_id'] = (int)$row['preferred_stylist_id'];
    }
}

 $errors = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $old['client_id']        = (int)post('client_id');
    $old['staff_id']         = (int)post('staff_id');
    $old['service_id']       = (int)post('service_id');
    $old['appointment_date'] = post('appointment_date');
    $old['start_time']       = post('start_time');
    $old['notes']            = post('notes');

    /* ---- 1. the three selections must exist and be active ---- */
    $client = null; $staff = null; $service = null;

    $stmt = mysqli_prepare($conn,
        "SELECT client_id, name FROM clients WHERE client_id = ? AND is_active = 1 LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $old['client_id']);
    mysqli_stmt_execute($stmt);
    $client = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$client) {
        $errors[] = 'Please choose a valid client.';
    }

    $stmt = mysqli_prepare($conn,
        "SELECT staff_id, name, shift_start, shift_end, work_days
         FROM staff WHERE staff_id = ? AND status = 'active' LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $old['staff_id']);
    mysqli_stmt_execute($stmt);
    $staff = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$staff) {
        $errors[] = 'Please choose a valid stylist.';
    }

    $stmt = mysqli_prepare($conn,
        "SELECT service_id, name, duration_minutes FROM services
         WHERE service_id = ? AND is_active = 1 LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $old['service_id']);
    mysqli_stmt_execute($stmt);
    $service = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$service) {
        $errors[] = 'Please choose a valid service.';
    }

    /* ---- 2. date ---- */
    if (!valid_date($old['appointment_date'])) {
        $errors[] = 'Please pick a valid date.';
    } elseif (!$editing && $old['appointment_date'] < $today) {
        $errors[] = 'The date cannot be in the past.';
    }

    /* ---- 3. time slot: shift + working day + duration fit ---- */
    $start_v = '';
    $end_v   = '';
    if (!$errors) {
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $old['start_time'])) {
            $errors[] = 'Please choose a start time from the list.';
        } else {
            /* does the stylist work that weekday? */
            $day_tokens = explode(',', $staff['work_days']);
            $day_token  = date('D', strtotime($old['appointment_date']));
            if (!in_array($day_token, $day_tokens, true)) {
                $errors[] = $staff['name'] . ' does not work on '
                    . date('l', strtotime($old['appointment_date'])) . 's. Working days: '
                    . implode(', ', $day_tokens) . '.';
            }

            /* must sit on the stylist's 15-minute shift grid */
            $grid = build_slot_grid($staff['shift_start'], $staff['shift_end'], $service['duration_minutes']);
            if (!in_array($old['start_time'], $grid, true)) {
                $errors[] = 'That time does not fit ' . $staff['name'] . "'s shift ("
                    . date('h:i A', strtotime($staff['shift_start'])) . ' - '
                    . date('h:i A', strtotime($staff['shift_end'])) . ') with a '
                    . (int)$service['duration_minutes'] . ' minute service.';
            }

            if (!$errors) {
                $start_v = $old['start_time'] . ':00';
                $end_v   = date('H:i:s', strtotime(
                    $old['appointment_date'] . ' ' . $old['start_time']
                    . ' +' . (int)$service['duration_minutes'] . ' minutes'));
            }
        }
    }

    /* ---- 4. conflict detection (stylist + client) ---- */
    if (!$errors) {
        $exclude = $editing ? $id : 0;

        /* the stylist must be free in that window */
        $stmt = mysqli_prepare($conn,
            "SELECT COUNT(*) FROM appointments
             WHERE staff_id = ? AND appointment_date = ?
               AND status IN ('scheduled','confirmed')
               AND start_time < ? AND end_time > ?
               AND appointment_id <> ?");
        mysqli_stmt_bind_param($stmt, 'isssi',
            $staff['staff_id'], $old['appointment_date'], $end_v, $start_v, $exclude);
        mysqli_stmt_execute($stmt);
        $clash = (int)mysqli_fetch_row(mysqli_stmt_get_result($stmt))[0];
        mysqli_stmt_close($stmt);
        if ($clash > 0) {
            $errors[] = $staff['name'] . ' already has an appointment overlapping '
                . date('h:i A', strtotime($start_v)) . ' - ' . date('h:i A', strtotime($end_v))
                . ' on ' . date('d M Y', strtotime($old['appointment_date']))
                . '. Pick another time, date or stylist.';
        }

        /* the client cannot be in two chairs at once */
        if (!$errors) {
            $stmt = mysqli_prepare($conn,
                "SELECT COUNT(*) FROM appointments
                 WHERE client_id = ? AND appointment_date = ?
                   AND status IN ('scheduled','confirmed')
                   AND start_time < ? AND end_time > ?
                   AND appointment_id <> ?");
            mysqli_stmt_bind_param($stmt, 'isssi',
                $client['client_id'], $old['appointment_date'], $end_v, $start_v, $exclude);
            mysqli_stmt_execute($stmt);
            $clash = (int)mysqli_fetch_row(mysqli_stmt_get_result($stmt))[0];
            mysqli_stmt_close($stmt);
            if ($clash > 0) {
                $errors[] = $client['name'] . ' already has another appointment overlapping that time.';
            }
        }
    }

    /* ---- 5. save ---- */
    if (!$errors) {
        $notes_v = $old['notes'] !== '' ? $old['notes'] : null;
        $salon   = get_setting('salon_name', SITE_NAME);
        $when    = date('l, d M Y', strtotime($old['appointment_date'])) . ' at '
                 . date('h:i A', strtotime($start_v));

        if ($editing) {
            $time_moved = ($old['appointment_date'] !== $appt['appointment_date'])
                       || ($start_v !== substr($appt['start_time'], 0, 8));

            $stmt = mysqli_prepare($conn,
                "UPDATE appointments
                 SET client_id = ?, staff_id = ?, service_id = ?,
                     appointment_date = ?, start_time = ?, end_time = ?, notes = ?
                 WHERE appointment_id = ?");
            mysqli_stmt_bind_param($stmt, 'iiissssi',
                $old['client_id'], $old['staff_id'], $old['service_id'],
                $old['appointment_date'], $start_v, $end_v, $notes_v, $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            if ($time_moved) {
                notify_appointment($old['client_id'],
                    'Appointment rescheduled - ' . $salon,
                    'Your appointment has been moved to ' . $when
                    . '. If this does not suit you, please call us.');
            }
            set_flash('success', 'Appointment updated.');
            redirect($self . 'view.php?id=' . $id);
        } else {
            $status   = 'scheduled';
            $u        = current_user();
            $booked_by = (int)$u['user_id'];

            $stmt = mysqli_prepare($conn,
                "INSERT INTO appointments
                    (client_id, staff_id, service_id, appointment_date,
                     start_time, end_time, status, notes, booked_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'iiisssssi',
                $old['client_id'], $old['staff_id'], $old['service_id'],
                $old['appointment_date'], $start_v, $end_v, $status, $notes_v, $booked_by);
            mysqli_stmt_execute($stmt);
            $new_id = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);

            /* client: booking received (confirmation follows) */
            notify_appointment($old['client_id'],
                'Appointment received - ' . $salon,
                'We have received your booking for ' . $service['name'] . ' with '
                . $staff['name'] . ' on ' . $when . '. We will confirm it shortly.');

            /* stylist inbox (only if the staff member has a login) */
            $stmt = mysqli_prepare($conn, "SELECT user_id, name FROM staff WHERE staff_id = ? LIMIT 1");
            mysqli_stmt_bind_param($stmt, 'i', $old['staff_id']);
            mysqli_stmt_execute($stmt);
            $st = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if ($st && $st['user_id']) {
                log_notification(array(
                    'user_id'   => (int)$st['user_id'],
                    'recipient' => $st['name'],
                    'channel'   => 'system',
                    'type'      => 'appointment',
                    'subject'   => 'New appointment assigned',
                    'message'   => $client['name'] . ' - ' . $service['name'] . ' on ' . $when . '.',
                    'status'    => 'sent',
                ));
            }

            set_flash('success', 'Appointment booked for ' . $client['name'] . '.');
            redirect($self . 'view.php?id=' . $new_id);
        }
    }
}

/* ---------- static fallback grid for the time select (JS replaces it) ---------- */
 $fallback_slots = array();
for ($h = 9; $h <= 19; $h++) {
    foreach (array('00', '15', '30', '45') as $mm) {
        $t = sprintf('%02d:%s', $h, $mm);
        if ($t <= '19:45') {
            $fallback_slots[] = $t;
        }
    }
}

 $page_title = $editing ? 'Reschedule appointment' : 'Book appointment';
require_once __DIR__ . '/../../includes/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h5 class="mb-0">
        <i class="bi bi-calendar-plus"></i>
        <?= $editing ? 'Reschedule / edit appointment' : 'Book appointment' ?>
    </h5>
    <a class="btn btn-outline-secondary btn-sm" href="<?= $self ?>index.php">
        <i class="bi bi-arrow-left"></i> Appointment book
    </a>
</div>

<?php if ($editing): ?>
    <div class="alert alert-info py-2">
        <i class="bi bi-info-circle"></i> Editing the
        <b><?= e(date('d M Y', strtotime($appt['appointment_date']))) ?></b> booking
        (currently <span class="pill pill-<?= e($appt['status']) ?>"><?= e(ucwords(str_replace('_', ' ', $appt['status']))) ?></span>).
        Changing the date or time saves the new slot and notifies the client.
    </div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $err): ?>
            <div><i class="bi bi-exclamation-circle"></i> <?= e($err) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card card-salon">
    <div class="card-body p-4">
        <form id="appt-form" method="post"
              action="<?= $self ?>form.php<?= $editing ? '?id=' . $id : '' ?>"
              data-base="<?= $self ?>" class="row g-3">

            <?= csrf_field() ?>
            <input type="hidden" name="exclude_id" id="exclude_id" value="<?= $editing ? $id : 0 ?>">

            <div class="col-md-6">
                <label class="form-label">Client <span class="text-danger">*</span></label>
                <select name="client_id" id="client_id" class="form-select" required>
                    <option value="">-- choose client --</option>
                    <?php foreach ($clients as $c): ?>
                        <option value="<?= (int)$c['client_id'] ?>"
                            <?= $old['client_id'] === (int)$c['client_id'] ? 'selected' : '' ?>>
                            <?= e($c['name']) ?> — <?= e($c['phone']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Client missing? Add them under <b>Clients</b> first.</div>
            </div>

            <div class="col-md-6">
                <label class="form-label">Service <span class="text-danger">*</span></label>
                <select name="service_id" id="service_id" class="form-select" required>
                    <option value="">-- choose service --</option>
                    <?php foreach ($services as $s): ?>
                        <option value="<?= (int)$s['service_id'] ?>"
                            data-duration="<?= (int)$s['duration_minutes'] ?>"
                            data-price="<?= e($s['price']) ?>"
                            <?= $old['service_id'] === (int)$s['service_id'] ? 'selected' : '' ?>>
                            <?= e($s['name']) ?> (<?= (int)$s['duration_minutes'] ?> min)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label">Stylist <span class="text-danger">*</span></label>
                <select name="staff_id" id="staff_id" class="form-select" required>
                    <option value="">-- choose stylist --</option>
                    <?php foreach ($staff_rows as $s): ?>
                        <option value="<?= (int)$s['staff_id'] ?>"
                            <?= $old['staff_id'] === (int)$s['staff_id'] ? 'selected' : '' ?>>
                            <?= e($s['name']) ?><?= $s['specialization'] ? ' — ' . e($s['specialization']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label">Date <span class="text-danger">*</span></label>
                <input type="date" name="appointment_date" id="appointment_date"
                       class="form-control" required
                       value="<?= e($old['appointment_date']) ?>"
                       <?= $editing ? '' : 'min="' . e($today) . '"' ?>>
            </div>

            <div class="col-md-3">
                <label class="form-label">Start time <span class="text-danger">*</span></label>
                <select name="start_time" id="start_time" class="form-select" required>
                    <?php if ($old['start_time'] === ''): ?>
                        <option value="">-- choose time --</option>
                    <?php endif; ?>
                    <?php foreach ($fallback_slots as $t): ?>
                        <option value="<?= $t ?>" <?= $old['start_time'] === $t ? 'selected' : '' ?>>
                            <?= e(date('h:i A', strtotime($t))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div id="slot-note" class="form-text"></div>
            </div>

            <div class="col-12">
                <label class="form-label">Notes <span class="text-muted small">(optional, e.g. client requests)</span></label>
                <textarea name="notes" class="form-control" rows="2" maxlength="255"
                          placeholder="Anything the stylist should know..."><?= e($old['notes']) ?></textarea>
            </div>

            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-salon px-4">
                    <i class="bi bi-check2-circle"></i> <?= $editing ? 'Save changes' : 'Book appointment' ?>
                </button>
                <a class="btn btn-outline-secondary" href="<?= $editing ? $self . 'view.php?id=' . $id : $self . 'index.php' ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>

<p class="text-muted small mt-3 mb-0">
    <i class="bi bi-shield-check"></i> Availability is re-checked on the server when you save -
    overlapping stylist or client bookings are always rejected, even if the browser missed them.
</p>

<?php
 $page_scripts = '<script src="' . BASE_URL . 'assets/js/appointments.js"></script>';
require_once __DIR__ . '/../../includes/dash_footer.php';