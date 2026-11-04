<?php
/**
 * ============================================================
 *  CLIENTS - ADD / EDIT FORM (one file for both modes)
 *  ?id=N  -> edit client N,  no id -> register a new client
 *  Access: admin + receptionist  (can_manage('clients'))
 * ============================================================
 */

require_once __DIR__ . '/../../includes/init.php';
require_role(array('admin', 'receptionist'));

$self     = BASE_URL . 'modules/clients/';
$id       = (int)get('id');
$is_edit  = $id > 0;

/* ---------- active stylists for the preference dropdown ---------- */
$stylists = array();
$res = mysqli_query($conn,
    "SELECT staff_id, name FROM staff
     WHERE status IN ('active','on_leave') ORDER BY name ASC");
while ($res && ($row = mysqli_fetch_assoc($res))) {
    $stylists[] = $row;
}

/* ---------- load existing client (edit mode) ---------- */
$client = array(
    'name'                 => '',
    'email'                => '',
    'phone'                => '',
    'gender'               => '',
    'date_of_birth'        => '',
    'address'              => '',
    'preferred_stylist_id' => 0,
    'preferences'          => '',
    'is_active'            => 1,
);

if ($is_edit) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM clients WHERE client_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$row) {
        set_flash('danger', 'Client not found.');
        redirect($self . 'index.php');
    }
    $client = array(
        'name'                 => $row['name'],
        'email'                => (string)$row['email'],
        'phone'                => $row['phone'],
        'gender'               => (string)$row['gender'],
        'date_of_birth'        => (string)$row['date_of_birth'],
        'address'              => (string)$row['address'],
        'preferred_stylist_id' => (int)$row['preferred_stylist_id'],
        'preferences'          => (string)$row['preferences'],
        'is_active'            => (int)$row['is_active'],
    );
}

/* ============================================================
 *  HANDLE SUBMIT
 * ========================================================== */
$errors = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    foreach (array('name', 'email', 'phone', 'gender', 'date_of_birth', 'address', 'preferences') as $f) {
        $client[$f] = post($f);
    }
    $client['preferred_stylist_id'] = (int)post('preferred_stylist_id');
    $client['is_active']            = post('is_active') === '1' ? 1 : 0;

    /* --- name --- */
    if ($client['name'] === '') {
        $errors['name'] = 'Client name is required.';
    } elseif (mb_strlen($client['name']) > 100) {
        $errors['name'] = 'Name is too long (max 100 characters).';
    }

    /* --- phone --- */
    if ($client['phone'] === '') {
        $errors['phone'] = 'Phone number is required.';
    } elseif (!preg_match('/^[0-9+\-() ]{7,20}$/', $client['phone'])) {
        $errors['phone'] = 'Use digits, spaces and + - ( ) only (7-20 characters).';
    }

    /* --- email: optional, but valid + unique among clients --- */
    if ($client['email'] !== '') {
        if (!valid_email($client['email'])) {
            $errors['email'] = 'That email address does not look valid.';
        } elseif (mb_strlen($client['email']) > 120) {
            $errors['email'] = 'Email is too long (max 120 characters).';
        } else {
            $exclude = $is_edit ? $id : 0;
            $stmt = mysqli_prepare($conn,
                "SELECT client_id FROM clients WHERE email = ? AND client_id <> ?");
            mysqli_stmt_bind_param($stmt, 'si', $client['email'], $exclude);
            mysqli_stmt_execute($stmt);
            $dup = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if ($dup) {
                $errors['email'] = 'Another client is already registered with this email.';
            }
        }
    }

    /* --- date of birth --- */
    if ($client['date_of_birth'] !== '') {
        if (!valid_date($client['date_of_birth'])) {
            $errors['date_of_birth'] = 'Use the date picker (YYYY-MM-DD).';
        } elseif ($client['date_of_birth'] > date('Y-m-d')) {
            $errors['date_of_birth'] = 'Date of birth cannot be in the future.';
        }
    }

    /* --- gender / address --- */
    if ($client['gender'] !== '' && !in_array($client['gender'], array('Male', 'Female', 'Other'), true)) {
        $errors['gender'] = 'Pick a value from the list.';
    }
    if (mb_strlen($client['address']) > 255) {
        $errors['address'] = 'Address is too long (max 255 characters).';
    }

    /* --- preferred stylist must exist (defensive FK check) --- */
    $stylist_id = $client['preferred_stylist_id'] > 0 ? $client['preferred_stylist_id'] : 0;
    if ($stylist_id > 0) {
        $stmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM staff WHERE staff_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $stylist_id);
        mysqli_stmt_execute($stmt);
        $exists = (int)mysqli_fetch_row(mysqli_stmt_get_result($stmt))[0];
        mysqli_stmt_close($stmt);
        if (!$exists) {
            $stylist_id = 0;   // silently fall back to "no preference"
        }
    }

    /* --- save --- */
    if (!$errors) {
        $email_v   = $client['email']         !== '' ? $client['email']         : null;
        $gender_v  = $client['gender']        !== '' ? $client['gender']        : null;
        $dob_v     = $client['date_of_birth'] !== '' ? $client['date_of_birth'] : null;
        $stylist_v = $stylist_id > 0                  ? $stylist_id              : null;
        $active_v  = (int)$client['is_active'];

        if ($is_edit) {
            $sql = "UPDATE clients SET
                        name = ?, email = ?, phone = ?, gender = ?,
                        date_of_birth = ?, address = ?, preferred_stylist_id = ?,
                        preferences = ?, is_active = ?
                    WHERE client_id = ?";
            $types = 'ssssssssii';
            $params = array(
                $client['name'], $email_v, $client['phone'], $gender_v,
                $dob_v, $client['address'], $stylist_v,
                $client['preferences'], $active_v, $id,
            );
            $msg = 'Client details updated.';
        } else {
            $sql = "INSERT INTO clients
                        (name, email, phone, gender, date_of_birth,
                         address, preferred_stylist_id, preferences, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $types = 'ssssssssi';
            $params = array(
                $client['name'], $email_v, $client['phone'], $gender_v,
                $dob_v, $client['address'], $stylist_v,
                $client['preferences'], $active_v,
            );
            $msg = 'New client registered.';
        }

        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $saved_id = $is_edit ? $id : mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        set_flash('success', $msg);
        redirect($self . 'view.php?id=' . $saved_id);
    }

    /* refill stylist dropdown selection after a failed submit */
    $client['preferred_stylist_id'] = $stylist_id;
}

$page_title = $is_edit ? 'Edit client' : 'Add client';
require_once __DIR__ . '/../../includes/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h5 class="mb-0"><?= $is_edit ? '<i class="bi bi-pencil-square"></i> Edit client' : '<i class="bi bi-person-plus"></i> Register new client' ?></h5>
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
                           value="<?= e($client['name']) ?>" placeholder="e.g. Ayesha Khan" required>
                    <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?= e($errors['name']) ?></div><?php endif; ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Phone <span class="text-danger">*</span></label>
                    <input type="text" name="phone" maxlength="20"
                           class="form-control <?= isset($errors['phone']) ? 'is-invalid' : '' ?>"
                           value="<?= e($client['phone']) ?>" placeholder="e.g. +91 98765 43210" required>
                    <?php if (isset($errors['phone'])): ?><div class="invalid-feedback"><?= e($errors['phone']) ?></div><?php endif; ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" maxlength="120"
                           class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                           value="<?= e($client['email']) ?>" placeholder="optional">
                    <?php if (isset($errors['email'])): ?><div class="invalid-feedback"><?= e($errors['email']) ?></div><?php endif; ?>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Gender</label>
                    <select name="gender" class="form-select <?= isset($errors['gender']) ? 'is-invalid' : '' ?>">
                        <option value="">Prefer not to say</option>
                        <?php foreach (array('Female', 'Male', 'Other') as $g): ?>
                            <option value="<?= $g ?>" <?= $client['gender'] === $g ? 'selected' : '' ?>><?= $g ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Date of birth</label>
                    <input type="date" name="date_of_birth"
                           class="form-control <?= isset($errors['date_of_birth']) ? 'is-invalid' : '' ?>"
                           value="<?= e($client['date_of_birth']) ?>" max="<?= date('Y-m-d') ?>">
                    <?php if (isset($errors['date_of_birth'])): ?><div class="invalid-feedback"><?= e($errors['date_of_birth']) ?></div><?php endif; ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Preferred stylist</label>
                    <select name="preferred_stylist_id" class="form-select">
                        <option value="0">No preference</option>
                        <?php foreach ($stylists as $s): ?>
                            <option value="<?= (int)$s['staff_id'] ?>"
                                <?= (int)$client['preferred_stylist_id'] === (int)$s['staff_id'] ? 'selected' : '' ?>>
                                <?= e($s['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Used when booking appointments (coming in Step 5).</div>
                </div>

                <div class="col-md-6 d-flex align-items-end">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" value="1" id="is_active"
                               name="is_active" <?= $client['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Active client</label>
                    </div>
                </div>

                <div class="col-12">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" maxlength="255" class="form-control"
                           value="<?= e($client['address']) ?>" placeholder="optional">
                </div>

                <div class="col-12">
                    <label class="form-label">Preferences / notes</label>
                    <textarea name="preferences" rows="3" class="form-control"
                              placeholder="Allergies, favourite products, preferred appointment times..."><?= e($client['preferences']) ?></textarea>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button class="btn btn-salon px-4" type="submit">
                    <i class="bi bi-check2-circle"></i> <?= $is_edit ? 'Save changes' : 'Register client' ?>
                </button>
                <a class="btn btn-outline-secondary" href="<?= $is_edit ? $self . 'view.php?id=' . $id : $self . 'index.php' ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/dash_footer.php'; ?>
