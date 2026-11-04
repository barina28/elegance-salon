<?php
/**
 * ============================================================
 *  ELEGANCE SALON - BOOK AN APPOINTMENT & CONTACT (public)
 *  Clients can choose a service, artist, date, time slot,
 *  and submit their booking. Submissions are stored in
 *  `appointments`, recorded in `clients`, and dispatched
 *  via email to the person who booked.
 * ============================================================
 */

require_once __DIR__ . '/includes/init.php';

$errors = array();

// Load active services grouped by category
$services_grouped = array();
$res = mysqli_query($conn,
    "SELECT service_id, name, category, duration_minutes, price
     FROM services
     WHERE is_active = 1
     ORDER BY FIELD(category,'Hair','Nails','Facial','Makeup','Spa','Other'), price ASC");
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $services_grouped[$row['category']][] = $row;
    }
}

// Load active artists / staff
$staff_list = array();
$res = mysqli_query($conn,
    "SELECT staff_id, name, specialization
     FROM staff
     WHERE status = 'active'
     ORDER BY name ASC");
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $staff_list[] = $row;
    }
}

// Pre-selections from GET query parameters
$selected_service_id = (int)(post('service_id') !== '' ? post('service_id') : get('service_id'));
$selected_staff_id   = (int)(post('staff_id') !== '' ? post('staff_id') : get('artist_id'));

// Fallback search by service name if 'service' string was passed
if ($selected_service_id <= 0 && get('service') !== '') {
    $search_service = get('service');
    foreach ($services_grouped as $cat_items) {
        foreach ($cat_items as $s_item) {
            if (strcasecmp($s_item['name'], $search_service) === 0) {
                $selected_service_id = (int)$s_item['service_id'];
                break 2;
            }
        }
    }
}

// Fallback search by artist name if 'artist' string was passed
if ($selected_staff_id <= 0 && get('artist') !== '') {
    $search_artist = get('artist');
    foreach ($staff_list as $st_item) {
        if (stripos($st_item['name'], $search_artist) !== false) {
            $selected_staff_id = (int)$st_item['staff_id'];
            break;
        }
    }
}

// Default date = today or tomorrow
$default_date = post('appointment_date') !== '' ? post('appointment_date') : date('Y-m-d');
$default_time = post('appointment_time') !== '' ? post('appointment_time') : '10:00:00';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $name             = post('name');
    $email            = post('email');
    $phone            = post('phone');
    $service_id       = (int)post('service_id');
    $staff_id         = (int)post('staff_id');
    $appointment_date = post('appointment_date');
    $appointment_time = post('appointment_time');
    $message          = post('message'); // Completely optional

    if (mb_strlen($name) < 2) {
        $errors[] = 'Please enter your name (at least 2 characters).';
    }
    if (!valid_email($email)) {
        $errors[] = 'Please enter a valid email address so we can email your booking confirmation.';
    }
    if (mb_strlen(digits_only($phone)) < 7) {
        $errors[] = 'Please enter a valid phone number (at least 7 digits).';
    }
    if ($service_id <= 0) {
        $errors[] = 'Please select a service from the dropdown list.';
    }
    if ($appointment_date === '' || !valid_date($appointment_date)) {
        $errors[] = 'Please select a valid appointment date.';
    } elseif ($appointment_date < date('Y-m-d')) {
        $errors[] = 'The appointment date cannot be in the past.';
    }
    if ($appointment_time === '') {
        $appointment_time = '10:00:00';
    }

    if (!$errors) {
        // Fetch selected service
        $s_stmt = mysqli_prepare($conn, "SELECT service_id, name, duration_minutes, price FROM services WHERE service_id = ? AND is_active = 1 LIMIT 1");
        mysqli_stmt_bind_param($s_stmt, 'i', $service_id);
        mysqli_stmt_execute($s_stmt);
        $service = mysqli_fetch_assoc(mysqli_stmt_get_result($s_stmt));
        mysqli_stmt_close($s_stmt);

        if (!$service) {
            $errors[] = 'The selected service is not available.';
        } else {
            // Find or choose staff
            $staff_name = 'Any available artist';
            if ($staff_id > 0) {
                $st_stmt = mysqli_prepare($conn, "SELECT staff_id, name FROM staff WHERE staff_id = ? AND status = 'active' LIMIT 1");
                mysqli_stmt_bind_param($st_stmt, 'i', $staff_id);
                mysqli_stmt_execute($st_stmt);
                $st_row = mysqli_fetch_assoc(mysqli_stmt_get_result($st_stmt));
                mysqli_stmt_close($st_stmt);
                if ($st_row) {
                    $staff_name = $st_row['name'];
                } else {
                    $staff_id = 0;
                }
            }

            if ($staff_id <= 0) {
                // Auto-assign first active artist
                $first_st = mysqli_fetch_assoc(mysqli_query($conn, "SELECT staff_id, name FROM staff WHERE status = 'active' ORDER BY staff_id ASC LIMIT 1"));
                if ($first_st) {
                    $staff_id   = (int)$first_st['staff_id'];
                    $staff_name = $first_st['name'];
                }
            }

            // Find or create client in `clients` table
            $c_stmt = mysqli_prepare($conn, "SELECT client_id FROM clients WHERE phone = ? OR (email = ? AND email <> '') LIMIT 1");
            mysqli_stmt_bind_param($c_stmt, 'ss', $phone, $email);
            mysqli_stmt_execute($c_stmt);
            $c_row = mysqli_fetch_assoc(mysqli_stmt_get_result($c_stmt));
            mysqli_stmt_close($c_stmt);

            if ($c_row) {
                $client_id = (int)$c_row['client_id'];
                // Update email if it was missing
                $u_stmt = mysqli_prepare($conn, "UPDATE clients SET email = COALESCE(NULLIF(email, ''), ?), name = COALESCE(name, ?) WHERE client_id = ?");
                mysqli_stmt_bind_param($u_stmt, 'ssi', $email, $name, $client_id);
                mysqli_stmt_execute($u_stmt);
                mysqli_stmt_close($u_stmt);
            } else {
                $in_stmt = mysqli_prepare($conn, "INSERT INTO clients (name, email, phone) VALUES (?, ?, ?)");
                mysqli_stmt_bind_param($in_stmt, 'sss', $name, $email, $phone);
                mysqli_stmt_execute($in_stmt);
                $client_id = mysqli_insert_id($conn);
                mysqli_stmt_close($in_stmt);
            }

            // Calculate start and end time
            $start_time = date('H:i:s', strtotime($appointment_time));
            $duration   = (int)$service['duration_minutes'];
            $end_time   = date('H:i:s', strtotime($start_time) + ($duration * 60));
            $status     = 'scheduled';
            $notes_val  = $message !== '' ? mb_substr($message, 0, 255) : null;

            // Insert into appointments table
            $a_stmt = mysqli_prepare($conn,
                "INSERT INTO appointments
                    (client_id, staff_id, service_id, appointment_date, start_time, end_time, status, notes, booked_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL)");
            mysqli_stmt_bind_param($a_stmt, 'iiisssss',
                $client_id, $staff_id, $service_id, $appointment_date, $start_time, $end_time, $status, $notes_val);
            mysqli_stmt_execute($a_stmt);
            $new_appointment_id = mysqli_insert_id($conn);
            mysqli_stmt_close($a_stmt);

            // Send notification & email to client
            $salon_name = get_setting('salon_name', defined('SITE_NAME') ? SITE_NAME : 'Elegance Salon');
            $when_str   = date('l, d M Y', strtotime($appointment_date)) . ' at ' . date('h:i A', strtotime($start_time));

            $confirm_msg = "We have received your appointment booking for " . $service['name'] . " with "
                         . $staff_name . " on " . $when_str . ".\n\n"
                         . "Our front desk will confirm your appointment shortly. If you need to make any changes, please feel free to call us.";
            if ($message !== '') {
                $confirm_msg .= "\n\nYour notes: " . $message;
            }

            notify_appointment($client_id, 'Appointment Booking Request - ' . $salon_name, $confirm_msg);

            set_flash('success', "Thank you, {$name}! Your appointment for {$service['name']} with {$staff_name} on {$when_str} has been booked. A confirmation email has been sent to {$email}.");
            redirect(BASE_URL . 'contact.php');
        }
    }
}

$page_title = 'Book an Appointment';
require_once __DIR__ . '/includes/header.php';
?>

<h1 class="section-title mb-1">Book an appointment<span class="dot">.</span></h1>
<p class="text-muted mb-4">Choose your preferred service, beauty artist, and reserve your visit online in seconds.</p>

<div class="row g-4">

  <!-- ---------- Salon Details Sidebar ---------- -->
  <div class="col-lg-4">
    <div class="card card-salon h-100">
      <div class="card-body">
        <h5 class="fw-bold mb-3"><i class="bi bi-shop text-salon me-2"></i><?= e(get_setting('salon_name', defined('SITE_NAME') ? SITE_NAME : 'Elegance Salon')) ?></h5>
        <p class="mb-2"><i class="bi bi-geo-alt text-danger me-2"></i><?= e(get_setting('salon_address', '12 Rose Street, Beauty District')) ?></p>
        <p class="mb-2"><i class="bi bi-telephone text-danger me-2"></i><?= e(get_setting('salon_phone', '+91 98765 43210')) ?></p>
        <p class="mb-2"><i class="bi bi-envelope text-danger me-2"></i><?= e(get_setting('salon_email', 'info@elegancesalon.com')) ?></p>
        <hr>
        <h6 class="fw-bold mb-2 small text-uppercase text-muted"><i class="bi bi-clock me-1"></i> Opening Hours</h6>
        <p class="small text-muted mb-0"><?= e(get_setting('opening_hours', 'Mon-Sat: 9:00 AM - 8:00 PM | Sun: 10:00 AM - 5:00 PM')) ?></p>

        <hr class="mt-4">
        <div class="alert alert-light border small mb-0">
          <i class="bi bi-shield-check text-success me-1"></i>
          <strong>Instant Confirmation:</strong> Once you book, your slot is reserved and an instant confirmation email is dispatched to you.
        </div>
      </div>
    </div>
  </div>

  <!-- ---------- Booking Form ---------- -->
  <div class="col-lg-8">
    <div class="card card-salon">
      <div class="card-body p-4">
        <h5 class="fw-bold mb-3"><i class="bi bi-calendar2-heart text-salon me-2"></i>Book an Appointment</h5>

        <?php if ($errors): ?>
          <div class="alert alert-danger">
            <ul class="mb-0 ps-3">
              <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <form method="post" action="<?= BASE_URL ?>contact.php" novalidate>
          <?= csrf_field() ?>
          <div class="row g-3">
            <!-- Client Name -->
            <div class="col-md-6">
              <label class="form-label fw-semibold">Your Name <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" required
                     placeholder="Enter name"
                     value="<?= e(post('name')) ?>" maxlength="100">
            </div>

            <!-- Phone Number -->
            <div class="col-md-6">
              <label class="form-label fw-semibold">Phone Number <span class="text-danger">*</span></label>
              <input type="tel" name="phone" class="form-control" required
                     placeholder="0300-1234567"
                     value="<?= e(post('phone')) ?>" maxlength="25">
            </div>

            <!-- Email Address -->
            <div class="col-md-12">
              <label class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control" required
                     placeholder="Enter email"
                     value="<?= e(post('email')) ?>" maxlength="120">
              <div class="form-text small text-muted">We will send your booking receipt and appointment updates to this email.</div>
            </div>

            <!-- Service Dropdown (Replaces Subject) -->
            <div class="col-md-6">
              <label class="form-label fw-semibold">Select Service <span class="text-danger">*</span></label>
              <select name="service_id" class="form-select" required>
                <option value="">-- Choose a Service --</option>
                <?php foreach ($services_grouped as $cat_name => $cat_services): ?>
                  <optgroup label="<?= e($cat_name) ?> Services">
                    <?php foreach ($cat_services as $s): ?>
                      <option value="<?= (int)$s['service_id'] ?>" <?= $selected_service_id === (int)$s['service_id'] ? 'selected' : '' ?>>
                        <?= e($s['name']) ?> (<?= money($s['price']) ?> &bull; <?= (int)$s['duration_minutes'] ?> min)
                      </option>
                    <?php endforeach; ?>
                  </optgroup>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Artist / Stylist Box Dropdown -->
            <div class="col-md-6">
              <label class="form-label fw-semibold">Select Artist / Stylist</label>
              <select name="staff_id" class="form-select">
                <option value="0">Select Artist / Stylist</option>
                <?php foreach ($staff_list as $st): ?>
                  <option value="<?= (int)$st['staff_id'] ?>" <?= $selected_staff_id === (int)$st['staff_id'] ? 'selected' : '' ?>>
                    <?= e($st['name']) ?> (<?= e($st['specialization']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Preferred Date -->
            <div class="col-md-6">
              <label class="form-label fw-semibold">Preferred Date <span class="text-danger">*</span></label>
              <input type="date" name="appointment_date" class="form-control" required
                     min="<?= date('Y-m-d') ?>"
                     value="<?= e($default_date) ?>">
            </div>

            <!-- Preferred Time Slot -->
            <div class="col-md-6">
              <label class="form-label fw-semibold">Preferred Time Slot <span class="text-danger">*</span></label>
              <select name="appointment_time" class="form-select" required>
                <?php
                $time_slots = array(
                    '09:30:00' => '09:30 AM',
                    '10:00:00' => '10:00 AM',
                    '10:30:00' => '10:30 AM',
                    '11:00:00' => '11:00 AM',
                    '11:30:00' => '11:30 AM',
                    '12:00:00' => '12:00 PM',
                    '12:30:00' => '12:30 PM',
                    '13:00:00' => '01:00 PM',
                    '14:00:00' => '02:00 PM',
                    '14:30:00' => '02:30 PM',
                    '15:00:00' => '03:00 PM',
                    '15:30:00' => '03:30 PM',
                    '16:00:00' => '04:00 PM',
                    '16:30:00' => '04:30 PM',
                    '17:00:00' => '05:00 PM',
                    '17:30:00' => '05:30 PM',
                    '18:00:00' => '06:00 PM',
                    '18:30:00' => '06:30 PM',
                    '19:00:00' => '07:00 PM',
                );
                foreach ($time_slots as $t_val => $t_label): ?>
                  <option value="<?= $t_val ?>" <?= $default_time === $t_val ? 'selected' : '' ?>><?= $t_label ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Message / Notes Box (OPTIONAL) -->
            <div class="col-12">
              <label class="form-label fw-semibold">
                Special Requests / Notes <span class="text-muted fw-normal small">(optional)</span>
              </label>
              <textarea name="message" class="form-control" rows="3"
                        placeholder="Any hair length details, allergies, design preferences, or questions (optional)..."
                        maxlength="1000"><?= e(post('message')) ?></textarea>
            </div>

            <!-- Submit Button -->
            <div class="col-12 mt-4">
              <button type="submit" class="btn btn-salon px-5 py-2">
                <i class="bi bi-calendar-check me-2"></i>Book Appointment
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
