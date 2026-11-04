<?php
/**
 * ============================================================
 *  CALENDAR - SALON CALENDAR (Step 6)
 *  FullCalendar week / day / month view of every appointment,
 *  with date-specific staff_shifts drawn on top (shaded working
 *  hours, "Day off" blocks).
 *
 *  Roles: admin + receptionist see and filter everyone;
 *  stylists are locked to their own schedule.
 *  Admin + receptionist can click / drag an empty slot to open
 *  the booking form pre-filled with stylist, date and time.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/init.php';
require_role(array('admin', 'receptionist', 'stylist'));

 $self   = BASE_URL . 'modules/calendar/';
 $u      = current_user();

/* ---------- stylist: locked to own staff row ---------- */
 $my_staff_id = 0;
if ($u['role'] === 'stylist') {
    $stmt = mysqli_prepare($conn, "SELECT staff_id FROM staff WHERE user_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $u['user_id']);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    $my_staff_id = $row ? (int)$row['staff_id'] : 0;
}

 $can_book   = in_array($u['role'], array('admin', 'receptionist'), true);
 $can_shifts = ($u['role'] === 'admin');

/* ---------- stylist dropdown for the filter ---------- */
 $staff_rows = array();
if ($u['role'] !== 'stylist') {
    $res = mysqli_query($conn,
        "SELECT staff_id, name FROM staff WHERE status = 'active' ORDER BY name ASC");
    while ($row = mysqli_fetch_assoc($res)) {
        $staff_rows[] = $row;
    }
}

 $page_title = 'Calendar';
require_once __DIR__ . '/../../includes/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h5 class="mb-1"><i class="bi bi-calendar3"></i> Salon calendar</h5>
        <p class="text-muted small mb-0">
            <?php if ($can_book): ?>
                Click or drag on an empty slot to book it. Click a booking to open its details.
            <?php else: ?>
                Your bookings at a glance. Click one to open its details.
            <?php endif; ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($can_shifts): ?>
            <a class="btn btn-outline-salon btn-sm" href="<?= $self ?>shifts.php">
                <i class="bi bi-clock-history"></i> Shifts &amp; time off
            </a>
        <?php endif; ?>
        <?php if ($can_book): ?>
            <a class="btn btn-salon btn-sm" href="<?= BASE_URL ?>modules/appointments/form.php">
                <i class="bi bi-calendar-plus"></i> New appointment
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($u['role'] === 'stylist' && !$my_staff_id): ?>
    <div class="alert alert-warning">
        <i class="bi bi-info-circle"></i> Your login is not linked to a staff profile yet,
        so the calendar cannot show your schedule. Ask the administrator to add you under <b>Staff</b>.
    </div>
<?php endif; ?>

<!-- ---------- filter bar + legend ---------- -->
<div class="card card-salon mb-3">
    <div class="card-body py-2 d-flex flex-wrap align-items-center gap-3">
        <?php if ($u['role'] !== 'stylist'): ?>
            <div class="d-flex align-items-center gap-2">
                <label class="small text-muted mb-0" for="cal-staff">Stylist</label>
                <select id="cal-staff" class="form-select form-select-sm" style="width:auto">
                    <option value="0">All stylists</option>
                    <?php foreach ($staff_rows as $s): ?>
                        <option value="<?= (int)$s['staff_id'] ?>"><?= e($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php else: ?>
            <input type="hidden" id="cal-staff" value="<?= (int)$my_staff_id ?>">
        <?php endif; ?>

        <div class="form-check mb-0">
            <input class="form-check-input" type="checkbox" id="cal-hide-cancelled">
            <label class="form-check-label small" for="cal-hide-cancelled">Hide cancelled &amp; no-shows</label>
        </div>

        <div class="cal-legend ms-auto">
            <span><i class="dot-ev ev-scheduled"></i> Scheduled</span>
            <span><i class="dot-ev ev-confirmed"></i> Confirmed</span>
            <span><i class="dot-ev ev-completed"></i> Completed</span>
            <span><i class="dot-ev ev-cancelled"></i> Cancelled</span>
            <span><i class="dot-ev ev-no_show"></i> No-show</span>
            <span><i class="dot-ev ev-shift"></i> Working hours</span>
        </div>
    </div>
</div>

<!-- ---------- the calendar ---------- -->
<div class="card card-salon">
    <div class="card-body">
        <div id="salon-calendar"
             data-feed="<?= $self ?>feed.php"
             data-book-url="<?= BASE_URL ?>modules/appointments/form.php"
             data-view-url="<?= BASE_URL ?>modules/appointments/view.php"
             data-can-book="<?= $can_book ? '1' : '0' ?>"></div>
    </div>
</div>

<p class="text-muted small mt-3 mb-0">
    <i class="bi bi-shield-check"></i> Availability is always re-checked on the server -
    time-off windows, the stylist's weekly shift and existing bookings can never be double-booked,
    even from the calendar.
</p>

<?php
 $page_scripts = '<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>' . "\n"
              . '<script src="' . BASE_URL . 'assets/js/calendar.js"></script>';
require_once __DIR__ . '/../../includes/dash_footer.php';