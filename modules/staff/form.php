<?php
/**
 * ============================================================
 *  STAFF - ADD / EDIT PROFILE (one file for both modes)
 *  ?id=N  -> edit staff N,  no id -> add a new staff member
 *  Fields: identity, contact, weekly routine (work_days SET +
 *  shift hours), status, default commission, optional link to
 *  a stylist LOGIN (lets them see their own calendar).
 *  Access: admin only  (can_manage('staff'))
 * ============================================================
 */

require_once __DIR__ . '/../../includes/init.php';
require_role(array('admin'));

 $self    = BASE_URL . 'modules/staff/';
 $id      = (int)get('id');
 $is_edit = $id > 0;

 $week_days = array('Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun');

/* ---------- load existing staff (edit mode) ---------- */
 $staff = array(
    'name'            => '',
    'email'           => '',
    'phone'           => '',
    'specialization'  => '',
    'commission_rate' => '10.00',
    'work_days'       => array('Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'),
    'shift_start'     => '09:00',
    'shift_end'       => '18:00',
    'hire_date'       => '',
    'status'          => 'active',
    'user_id'         => 0,
);

if ($is_edit) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM staff WHERE staff_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$row) {
        set_flash('danger', 'Staff member not found.');
        redirect($self . 'index.php');
    }
    $staff = array(
        'name'            => $row['name'],
        'email'           => (string)$row['email'],
        'phone'           => (string)$row['phone'],
        'specialization'  => (string)$row['specialization'],
        'commission_rate' => $row['commission_rate'],
        'work_days'       => explode(',', $row['work_days']),
        'shift_start'     => substr($row['shift_start'], 0, 5),
        'shift_end'       => substr($row['shift_end'],   0, 5),
        'hire_date'       => (string)$row['hire_date'],
        'status'          => $row['status'],
        'user_id'         => (int)$row['user_id'],
    );
}

/* ============================================================
 *  HANDLE SUBMIT
 * ========================================================== */
 $errors = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $staff['name']           = post('name');
    $staff['email']          = post('email');
    $staff['phone']          = post('phone');
    $staff['specialization'] = post('specialization');
    $staff['commission_rate'] = post('commission_rate');
    $staff['shift_start']    = post('shift_start');
    $staff['shift_end']      = post('shift_end');
    $staff['hire_date']      = post('hire_date');
    $staff['status']         = post('status');
    $staff['user_id']        = (int)post('user_id');
    $days_in                 = isset($_POST['work_days']) && is_array($_POST['work_days'])
                                ? $_POST['work_days'] : array();

    /* --- name --- */
    if ($staff['name'] === '') {
        $errors['name'] = 'Staff name is required.';
    } elseif (mb_strlen($staff['name']) > 100) {
        $errors['name'] = 'Name is too long (max 100 characters).';
    }

    /* --- email: optional, but valid + unique among staff --- */
    if ($staff['email'] !== '') {
        if (!valid_email($staff['email'])) {
            $errors['email'] = 'That email address does not look valid.';
        } elseif (mb_strlen($staff['email']) > 120) {
            $errors['email'] = 'Email is too long (max 120 characters).';
        } else {
            $exclude = $is_edit ? $id : 0;
            $stmt = mysqli_prepare($conn,
                "SELECT staff_id FROM staff WHERE email = ? AND staff_id <> ?");
            mysqli_stmt_bind_param($stmt, 'si', $staff['email'], $exclude);
            mysqli_stmt_execute($stmt);
            $dup = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if ($dup) {
                $errors['email'] = 'Another staff profile already uses this email.';
            }
        }
    }

    /* --- phone: optional, but valid when given --- */
    if ($staff['phone'] !== '' && !preg_match('/^[0-9+\-() ]{7,20}$/', $staff['phone'])) {
        $errors['phone'] = 'Use digits, spaces and + - ( ) only (7-20 characters).';
    }

    /* --- specialization --- */
    if (mb_strlen($staff['specialization']) > 150) {
        $errors['specialization'] = 'Specialization is too long (max 150 characters).';
    }

    /* --- commission rate: 0-100 --- */
    if (!is_numeric($staff['commission_rate'])) {
        $errors['commission_rate'] = 'Enter the default commission as a number.';
    } else {
        $rate = round((float)$staff['commission_rate'], 2);
        if ($rate < 0 || $rate > 100) {
            $errors['commission_rate'] = 'Commission must be between 0 and 100 percent.';
        } else {
            $staff['commission_rate'] = $rate;
        }
    }

    /* --- weekly routine: days --- */
    $days_clean = array();
    foreach ($days_in as $d) {
        if (in_array($d, $week_days, true)) {
            $days_clean[] = $d;
        }
    }
    $days_clean = array_values(array_unique($days_clean));
    if (!$days_clean) {
        $errors['work_days'] = 'Pick at least one working day.';
    } else {
        $staff['work_days'] = $days_clean;
    }

    /* --- weekly routine: hours --- */
    if (!valid_time($staff['shift_start']) || !valid_time($staff['shift_end'])) {
        $errors['shift_start'] = 'Shift start and end are required (HH:MM).';
    } elseif (substr($staff['shift_end'], 0, 5) <= substr($staff['shift_start'], 0, 5)) {
        $errors['shift_start'] = 'The shift end must be after the shift start.';
    }

    /* --- hire date: optional, not in the future --- */
    if ($staff['hire_date'] !== '') {
        if (!valid_date($staff['hire_date'])) {
            $errors['hire_date'] = 'Use the date picker (YYYY-MM-DD).';
        } elseif ($staff['hire_date'] > date('Y-m-d')) {
            $errors['hire_date'] = 'Hire date cannot be in the future.';
        }
    }

    /* --- status --- */
    if (!in_array($staff['status'], array('active', 'on_leave', 'inactive'), true)) {
        $errors['status'] = 'Pick a status from the list.';
    }

    /* --- login link: must be a stylist user not linked elsewhere --- */
    $uid = $staff['user_id'];
    if ($uid > 0) {
        $stmt = mysqli_prepare($conn,
            "SELECT u.user_id FROM users u
             LEFT JOIN staff s ON s.user_id = u.user_id AND s.staff_id <> ?
             WHERE u.user_id = ? AND u.role = 'stylist' AND s.staff_id IS NULL");
        mysqli_stmt_bind_param($stmt, 'ii', $id, $uid);
        mysqli_stmt_execute($stmt);
        $ok = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$ok) {
            $errors['user_id'] = 'That login is unavailable (it must belong to a stylist who is not linked to another profile).';
        }
    } else {
        $uid = null;
    }

    /* --- save --- */
    if (!$errors) {
        $email_v   = $staff['email']          !== '' ? $staff['email']          : null;
        $phone_v   = $staff['phone']          !== '' ? $staff['phone']          : null;
        $spec_v    = $staff['specialization'] !== '' ? $staff['specialization'] : null;
        $hire_v    = $staff['hire_date']      !== '' ? $staff['hire_date']      : null;
        $days_v    = implode(',', $staff['work_days']);
        $start_v   = substr($staff['shift_start'], 0, 5) . ':00';
        $end_v     = substr($staff['shift_end'],   0, 5) . ':00';
        $rate_v    = (float)$staff['commission_rate'];
        $status_v  = $staff['status'];

        if ($is_edit) {
            $sql = "UPDATE staff SET
                        user_id = ?, name = ?, email = ?, phone = ?, specialization = ?,
                        commission_rate = ?, work_days = ?, shift_start = ?, shift_end = ?,
                        hire_date = ?, status = ?
                    WHERE staff_id = ?";
            $types = 'issssdsssssi';
        } else {
            $sql = "INSERT INTO staff
                        (user_id, name, email, phone, specialization,
                         commission_rate, work_days, shift_start, shift_end,
                         hire_date, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $types = 'issssdsssss';
        }

        $params = array($uid, $staff['name'], $email_v, $phone_v, $spec_v,
                        $rate_v, $days_v, $start_v, $end_v, $hire_v, $status_v);
        if ($is_edit) {
            $params[] = $id;
        }

        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $saved_id = $is_edit ? $id : mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        set_flash('success', ($is_edit ? 'Staff profile updated.' : 'New staff profile created.'));
        redirect($self . 'view.php?id=' . $saved_id);
    }
}

/* ---------- login dropdown: free stylist users (+ current link) ---------- */
 $free_users = array();
 $current_uid = (int)$staff['user_id'];
 $sql = "SELECT u.user_id, u.username, u.name
        FROM users u
        LEFT JOIN staff s ON s.user_id = u.user_id AND s.staff_id <> " . ($is_edit ? $id : 0) . "
        WHERE u.role = 'stylist' AND s.staff_id IS NULL";
if ($is_edit && $current_uid) {
    /* keep the currently linked user selectable even if inactive */
    $sql .= " OR (u.user_id = $current_uid AND u.role = 'stylist')";
}
 $sql .= " ORDER BY u.username ASC";
 $res = mysqli_query($conn, $sql);
while ($res && ($row = mysqli_fetch_assoc($res))) {
    $free_users[] = $row;
}

 $page_title = $is_edit ? 'Edit staff profile' : 'Add staff';
require_once __DIR__ . '/../../includes/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h5 class="mb-0"><?= $is_edit ? '<i class="bi bi-pencil-square"></i> Edit staff profile' : '<i class="bi bi-person-plus"></i> Add staff' ?></h5>
    <a class="btn btn-outline-secondary btn-sm" href="<?= $is_edit ? $self . 'view.php?id=' . $id : $self . 'index.php' ?>">
        <i class="bi bi-arrow-left"></i> Back
    </a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> Please fix the highlighted fields and try again.</div>
<?php endif; ?>

<div class="card card-salon">
    <div class="card-body">
        <form method="post" action="<?= $self ?>form.php<?= $is_edit ? '?id=' . $id : '' ?>" novalidate>
            <?= csrf_field() ?>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Full name <span class="text-danger">*</span></label>
                    <input type="text" name="name" maxlength="100"
                           class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                           value="<?= e($staff['name']) ?>" placeholder="e.g. Priya Sharma" required>
                    <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?= e($errors['name']) ?></div><?php endif; ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Specialization</label>
                    <input type="text" name="specialization" maxlength="150" class="form-control"
                           value="<?= e($staff['specialization']) ?>" placeholder="e.g. Hair styling, colouring">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" maxlength="120"
                           class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                           value="<?= e($staff['email']) ?>" placeholder="optional">
                    <?php if (isset($errors['email'])): ?><div class="invalid-feedback"><?= e($errors['email']) ?></div><?php endif; ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" maxlength="20"
                           class="form-control <?= isset($errors['phone']) ? 'is-invalid' : '' ?>"
                           value="<?= e($staff['phone']) ?>" placeholder="optional">
                    <?php if (isset($errors['phone'])): ?><div class="invalid-feedback"><?= e($errors['phone']) ?></div><?php endif; ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Default commission % <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="number" name="commission_rate" min="0" max="100" step="0.01"
                               class="form-control <?= isset($errors['commission_rate']) ? 'is-invalid' : '' ?>"
                               value="<?= e($staff['commission_rate']) ?>" required>
                        <span class="input-group-text">%</span>
                    </div>
                    <div class="form-text">Earned on each service unless that service has its own override
                        (<a href="<?= $self ?>commissions.php">Commission rates</a>).</div>
                    <?php if (isset($errors['commission_rate'])): ?><div class="invalid-feedback d-block"><?= e($errors['commission_rate']) ?></div><?php endif; ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Hire date</label>
                    <input type="date" name="hire_date"
                           class="form-control <?= isset($errors['hire_date']) ? 'is-invalid' : '' ?>"
                           value="<?= e($staff['hire_date']) ?>" max="<?= date('Y-m-d') ?>">
                    <?php if (isset($errors['hire_date'])): ?><div class="invalid-feedback"><?= e($errors['hire_date']) ?></div><?php endif; ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select <?= isset($errors['status']) ? 'is-invalid' : '' ?>">
                        <?php foreach (array('active' => 'Active', 'on_leave' => 'On leave', 'inactive' => 'Inactive') as $v => $lbl): ?>
                            <option value="<?= $v ?>" <?= $staff['status'] === $v ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['status'])): ?><div class="invalid-feedback"><?= e($errors['status']) ?></div><?php endif; ?>
                </div>

                <div class="col-12">
                    <label class="form-label d-block">Weekly working days <span class="text-danger">*</span></label>
                    <div class="d-flex flex-wrap gap-3">
                        <?php foreach ($week_days as $d): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="work_days[]"
                                       id="wd-<?= strtolower($d) ?>" value="<?= $d ?>"
                                       <?= in_array($d, $staff['work_days'], true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="wd-<?= strtolower($d) ?>"><?= $d ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (isset($errors['work_days'])): ?><div class="text-danger small"><?= e($errors['work_days']) ?></div><?php endif; ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Shift starts <span class="text-danger">*</span></label>
                    <input type="time" name="shift_start"
                           class="form-control <?= isset($errors['shift_start']) ? 'is-invalid' : '' ?>"
                           value="<?= e($staff['shift_start']) ?>" required>
                    <?php if (isset($errors['shift_start'])): ?><div class="invalid-feedback d-block"><?= e($errors['shift_start']) ?></div><?php endif; ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Shift ends <span class="text-danger">*</span></label>
                    <input type="time" name="shift_end"
                           class="form-control <?= isset($errors['shift_end']) ? 'is-invalid' : '' ?>"
                           value="<?= e($staff['shift_end']) ?>" required>
                    <?php if (isset($errors['shift_end'])): ?><div class="invalid-feedback"><?= e($errors['shift_end']) ?></div><?php endif; ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Login account</label>
                    <select name="user_id" class="form-select <?= isset($errors['user_id']) ? 'is-invalid' : '' ?>">
                        <option value="0">No login linked</option>
                        <?php foreach ($free_users as $fu): ?>
                            <option value="<?= (int)$fu['user_id'] ?>"
                                <?= $current_uid === (int)$fu['user_id'] ? 'selected' : '' ?>>
                                <?= e($fu['username']) ?> - <?= e($fu['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Link a stylist's login so they can see their own calendar and dashboard.</div>
                    <?php if (isset($errors['user_id'])): ?><div class="invalid-feedback d-block"><?= e($errors['user_id']) ?></div><?php endif; ?>
                </div>
            </div>

            <div class="alert alert-info small mt-4 mb-0">
                <i class="bi bi-lightbulb"></i> This weekly routine is the <b>base schedule</b> - the booking
                form and calendar use it every week. For one-off changes (a swapped Sunday, an extra evening,
                a holiday) add date-specific entries under
                <a href="<?= BASE_URL ?>modules/calendar/shifts.php">Calendar &rsaquo; Shifts &amp; time off</a>.
            </div>

            <div class="mt-4 d-flex gap-2">
                <button class="btn btn-salon px-4" type="submit">
                    <i class="bi bi-check2-circle"></i> <?= $is_edit ? 'Save changes' : 'Create profile' ?>
                </button>
                <a class="btn btn-outline-secondary" href="<?= $is_edit ? $self . 'view.php?id=' . $id : $self . 'index.php' ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/dash_footer.php'; ?>