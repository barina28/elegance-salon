<?php
/**
 * ============================================================
 *  CALENDAR - STAFF SHIFTS & TIME OFF MANAGEMENT
 *  Allows admins to schedule date-specific shifts, overtime,
 *  or time-off / leave for stylists.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/init.php';
require_role(array('admin'));

$self = BASE_URL . 'modules/calendar/shifts.php';
$errors = array();

// Defaults for the form
$old = array(
    'staff_id'   => post('staff_id', get('staff_id', '')),
    'shift_date' => post('shift_date', get('date', date('Y-m-d'))),
    'start_time' => post('start_time', '09:00'),
    'end_time'   => post('end_time', '18:00'),
    'shift_type' => post('shift_type', 'regular'),
    'notes'      => post('notes', ''),
);

/* ============================================================
 *  HANDLE ACTIONS (Add & Delete)
 * ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post('action');

    // 1. DELETE SHIFT
    if ($action === 'delete') {
        $del_id = (int)post('shift_id');
        if ($del_id > 0) {
            $stmt = mysqli_prepare($conn, "DELETE FROM staff_shifts WHERE shift_id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $del_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            set_flash('success', 'Shift entry deleted successfully.');
            redirect($self);
        }
    }

    // 2. ADD SHIFT / TIME-OFF
    if ($action === 'add') {
        $staff_id   = (int)$old['staff_id'];
        $shift_date = $old['shift_date'];
        $start_time = $old['start_time'];
        $end_time   = $old['end_time'];
        $shift_type = $old['shift_type'];
        $notes      = $old['notes'];

        /* ---------- add: validate ---------- */
        if ($staff_id <= 0) {
            $errors[] = 'Please select a staff member.';
        }
        if (!valid_date($shift_date)) {
            $errors[] = 'Please provide a valid shift date.';
        }
        if (!in_array($shift_type, array('regular', 'overtime', 'off'), true)) {
            $errors[] = 'Please choose a valid shift type.';
        }
        if (!valid_time($start_time) || !valid_time($end_time)) {
            $errors[] = 'Start and end times are required (HH:MM).';
        } elseif ($end_time <= $start_time) {
            $errors[] = 'The end time must be after the start time.';
        }

        if (!$errors) {
            /* the unique key (staff_id, shift_date, start_time) gives a
               friendly message instead of a database error */
            $stmt_chk = mysqli_prepare($conn, "SELECT shift_id FROM staff_shifts WHERE staff_id = ? AND shift_date = ? AND start_time = ? LIMIT 1");
            $st_db = substr($start_time, 0, 5) . ':00';
            mysqli_stmt_bind_param($stmt_chk, 'iss', $staff_id, $shift_date, $st_db);
            mysqli_stmt_execute($stmt_chk);
            $dup = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_chk));
            mysqli_stmt_close($stmt_chk);

            if ($dup) {
                $errors[] = 'A shift entry already exists for this staff member on this date and start time.';
            }

            // duplicate check, then warn when time-off covers existing bookings:
            if (!$errors && $shift_type === 'off') {
                $st_full = substr($start_time, 0, 5) . ':00';
                $et_full = substr($end_time, 0, 5) . ':00';

                $stmt_app = mysqli_prepare($conn,
                    "SELECT COUNT(*) AS cnt FROM appointments
                     WHERE staff_id = ? AND appointment_date = ?
                       AND status IN ('scheduled', 'confirmed')
                       AND start_time < ? AND end_time > ?"
                );
                mysqli_stmt_bind_param($stmt_app, 'isss', $staff_id, $shift_date, $et_full, $st_full);
                mysqli_stmt_execute($stmt_app);
                $conflict_count = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_app))['cnt'];
                mysqli_stmt_close($stmt_app);

                if ($conflict_count > 0) {
                    set_flash('warning', "Heads up: {$conflict_count} booking" . ($conflict_count > 1 ? 's fall' : ' falls') . " inside this time-off window. Please check and reschedule if needed.");
                }
            }
        }

        if (!$errors) {
            $st_db = substr($start_time, 0, 5) . ':00';
            $et_db = substr($end_time, 0, 5) . ':00';
            $stmt_ins = mysqli_prepare($conn,
                "INSERT INTO staff_shifts (staff_id, shift_date, start_time, end_time, shift_type, notes)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            mysqli_stmt_bind_param($stmt_ins, 'isssss', $staff_id, $shift_date, $st_db, $et_db, $shift_type, $notes);
            mysqli_stmt_execute($stmt_ins);
            mysqli_stmt_close($stmt_ins);

            set_flash('success', 'Shift / time-off scheduled successfully.');
            redirect($self . '?staff_id=' . $staff_id . '&date=' . urlencode($shift_date));
        }
    }
}

/* ============================================================
 *  FETCH ACTIVE STAFF LIST FOR DROPDOWN
 * ========================================================== */
$staff_list = array();
$res_st = mysqli_query($conn, "SELECT staff_id, name, specialization FROM staff WHERE status = 'active' ORDER BY name ASC");
while ($r = mysqli_fetch_assoc($res_st)) {
    $staff_list[] = $r;
}

/* ============================================================
 *  FETCH UPCOMING & RECENT SHIFTS LIST
 * ========================================================== */
$f_staff = (int)get('staff_id');
$f_date  = get('date');
$f_type  = get('type');

$where = array('1=1');
$types = '';
$params = array();

if ($f_staff > 0) {
    $where[] = "ss.staff_id = ?";
    $types .= 'i';
    $params[] = $f_staff;
}
if (valid_date($f_date)) {
    $where[] = "ss.shift_date = ?";
    $types .= 's';
    $params[] = $f_date;
}
if (in_array($f_type, array('regular', 'overtime', 'off'), true)) {
    $where[] = "ss.shift_type = ?";
    $types .= 's';
    $params[] = $f_type;
}

$where_sql = implode(' AND ', $where);
$sql = "SELECT ss.*, st.name AS staff_name, st.specialization
        FROM staff_shifts ss
        JOIN staff st ON st.staff_id = ss.staff_id
        WHERE {$where_sql}
        ORDER BY ss.shift_date DESC, ss.start_time ASC
        LIMIT 100";

$stmt_list = mysqli_prepare($conn, $sql);
if (!empty($types)) {
    mysqli_stmt_bind_param($stmt_list, $types, ...$params);
}
mysqli_stmt_execute($stmt_list);
$shifts = mysqli_fetch_all(mysqli_stmt_get_result($stmt_list), MYSQLI_ASSOC);
mysqli_stmt_close($stmt_list);

$page_title = 'Staff Shifts & Time Off';
require_once __DIR__ . '/../../includes/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h5 class="mb-1"><i class="bi bi-clock-history"></i> Shifts &amp; Time Off</h5>
        <p class="text-muted small mb-0">Schedule date-specific working shifts, overtime, or block leaves and days off.</p>
    </div>
    <div>
        <a href="<?= BASE_URL ?>modules/calendar/index.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Back to Calendar
        </a>
    </div>
</div>

<?php show_flash(); ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger py-2">
    <?php foreach ($errors as $err): ?>
        <div><i class="bi bi-exclamation-circle"></i> <?= e($err) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="row g-3">
    <!-- ADD SHIFT FORM -->
    <div class="col-lg-4">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 fw-semibold">
                <i class="bi bi-calendar-plus text-salon"></i> Add Shift / Time Off
            </div>
            <div class="card-body">
                <form method="post" action="<?= $self ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Staff Member <span class="text-danger">*</span></label>
                        <select name="staff_id" class="form-select" required>
                            <option value="">-- Choose Stylist / Staff --</option>
                            <?php foreach ($staff_list as $st): ?>
                                <option value="<?= (int)$st['staff_id'] ?>" <?= (int)$old['staff_id'] === (int)$st['staff_id'] ? 'selected' : '' ?>>
                                    <?= e($st['name']) ?> (<?= e($st['specialization'] ?: 'Stylist') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Shift Date <span class="text-danger">*</span></label>
                        <input type="date" name="shift_date" class="form-control" value="<?= e($old['shift_date']) ?>" required>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Start Time <span class="text-danger">*</span></label>
                            <input type="time" name="start_time" class="form-control" value="<?= e(substr($old['start_time'], 0, 5)) ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">End Time <span class="text-danger">*</span></label>
                            <input type="time" name="end_time" class="form-control" value="<?= e(substr($old['end_time'], 0, 5)) ?>" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Shift Type <span class="text-danger">*</span></label>
                        <select name="shift_type" class="form-select" required>
                            <option value="regular" <?= $old['shift_type'] === 'regular' ? 'selected' : '' ?>>Regular Shift</option>
                            <option value="overtime" <?= $old['shift_type'] === 'overtime' ? 'selected' : '' ?>>Overtime</option>
                            <option value="off" <?= $old['shift_type'] === 'off' ? 'selected' : '' ?>>Day Off / Leave</option>
                        </select>
                        <div class="form-text small">"Day Off" blocks appointment bookings and appears shaded on calendar.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Notes / Reason</label>
                        <input type="text" name="notes" class="form-control" placeholder="e.g. Sick leave, Morning rush coverage" value="<?= e($old['notes']) ?>" maxlength="255">
                    </div>

                    <button type="submit" class="btn btn-salon w-100">
                        <i class="bi bi-save"></i> Save Shift Entry
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- SHIFTS TABLE & FILTERS -->
    <div class="col-lg-8">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3">
                <form method="get" action="<?= $self ?>" class="row g-2 align-items-center">
                    <div class="col-md-4">
                        <select name="staff_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Staff</option>
                            <?php foreach ($staff_list as $st): ?>
                                <option value="<?= (int)$st['staff_id'] ?>" <?= $f_staff === (int)$st['staff_id'] ? 'selected' : '' ?>>
                                    <?= e($st['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Shift Types</option>
                            <option value="regular" <?= $f_type === 'regular' ? 'selected' : '' ?>>Regular</option>
                            <option value="overtime" <?= $f_type === 'overtime' ? 'selected' : '' ?>>Overtime</option>
                            <option value="off" <?= $f_type === 'off' ? 'selected' : '' ?>>Day Off</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <input type="date" name="date" class="form-control form-control-sm" value="<?= e($f_date) ?>" onchange="this.form.submit()">
                    </div>
                    <div class="col-md-2 d-flex gap-1">
                        <a href="<?= $self ?>" class="btn btn-sm btn-outline-secondary w-100" title="Reset Filters">Reset</a>
                    </div>
                </form>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small">
                            <tr>
                                <th>Staff Member</th>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Type</th>
                                <th>Notes</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($shifts)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">
                                        <i class="bi bi-calendar-x fs-3 d-block mb-1"></i>
                                        No custom shifts or time off records found.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($shifts as $s): ?>
                                    <?php
                                    $type_badges = array(
                                        'regular'  => '<span class="badge bg-primary-subtle text-primary border border-primary-subtle">Regular</span>',
                                        'overtime' => '<span class="badge bg-success-subtle text-success border border-success-subtle">Overtime</span>',
                                        'off'      => '<span class="badge bg-danger-subtle text-danger border border-danger-subtle">Day Off</span>',
                                    );
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?= e($s['staff_name']) ?></div>
                                            <div class="small text-muted"><?= e($s['specialization'] ?: 'Stylist') ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?= date('d M Y', strtotime($s['shift_date'])) ?></div>
                                            <div class="small text-muted"><?= date('l', strtotime($s['shift_date'])) ?></div>
                                        </td>
                                        <td>
                                            <?= e(date('h:i A', strtotime($s['start_time']))) ?> - <?= e(date('h:i A', strtotime($s['end_time']))) ?>
                                        </td>
                                        <td>
                                            <?= $type_badges[$s['shift_type']] ?? e($s['shift_type']) ?>
                                        </td>
                                        <td>
                                            <span class="small text-muted"><?= e($s['notes'] ?: '-') ?></span>
                                        </td>
                                        <td class="text-end">
                                            <form method="post" action="<?= $self ?>" onsubmit="return confirm('Are you sure you want to remove this shift entry?');" class="d-inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="shift_id" value="<?= (int)$s['shift_id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2" title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/dash_footer.php'; ?>
