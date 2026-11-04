<?php
/**
 * ============================================================
 *  APPOINTMENTS - SLOT AVAILABILITY (JSON endpoint)
 *  GET: staff_id, date, service_id, exclude
 *  Returns every 15-minute start time of the stylist's shift
 *  with an available/busy flag. Read-only; requires login.
 *  Used by assets/js/appointments.js on the booking form.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/init.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

function json_out($data) {
    echo json_encode($data);
    exit;
}

 $staff_id   = (int)get('staff_id');
 $service_id = (int)get('service_id');
 $date       = get('date');
 $exclude    = (int)get('exclude');

if ($staff_id <= 0 || $service_id <= 0 || !valid_date($date)) {
    json_out(array('ok' => false, 'message' => 'Pick a stylist, service and date first.', 'slots' => array()));
}
if ($date < date('Y-m-d')) {
    json_out(array('ok' => false, 'message' => 'That date is in the past.', 'slots' => array()));
}

/* ---------- staff + service ---------- */
 $stmt = mysqli_prepare($conn,
    "SELECT staff_id, name, shift_start, shift_end, work_days
     FROM staff WHERE staff_id = ? AND status = 'active' LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $staff_id);
mysqli_stmt_execute($stmt);
 $st = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$st) {
    json_out(array('ok' => false, 'message' => 'This stylist is not available.', 'slots' => array()));
}

 $stmt = mysqli_prepare($conn,
    "SELECT service_id, name, duration_minutes FROM services
     WHERE service_id = ? AND is_active = 1 LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $service_id);
mysqli_stmt_execute($stmt);
 $sv = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$sv) {
    json_out(array('ok' => false, 'message' => 'This service is not available.', 'slots' => array()));
}

/* ---------- does the stylist work that weekday? ---------- */
 $days   = explode(',', $st['work_days']);
 $token  = date('D', strtotime($date));
if (!in_array($token, $days, true)) {
    json_out(array(
        'ok'      => false,
        'message' => $st['name'] . ' is off on ' . date('l, d M Y', strtotime($date))
                   . '. Working days: ' . implode(', ', $days) . '.',
        'slots'   => array(),
    ));
}

/* ---------- existing bookings for that stylist/day ---------- */
 $busy = array();
 $stmt = mysqli_prepare($conn,
    "SELECT start_time, end_time FROM appointments
     WHERE staff_id = ? AND appointment_date = ?
       AND status IN ('scheduled','confirmed')
       AND appointment_id <> ?");
mysqli_stmt_bind_param($stmt, 'isi', $staff_id, $date, $exclude);
mysqli_stmt_execute($stmt);
 $busy = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

/* ---------- build the slot list ---------- */
 $grid     = build_slot_grid($st['shift_start'], $st['shift_end'], $sv['duration_minutes']);
 $is_today = ($date === date('Y-m-d'));
 $now_hm   = date('H:i');

 $slots = array();
foreach ($grid as $slot) {
    $slot_end = date('H:i', strtotime($slot) + ((int)$sv['duration_minutes'] * 60));
    $reason   = '';

    if ($is_today && $slot <= $now_hm) {
        $reason = 'past';
    } else {
        foreach ($busy as $b) {
            $bs = substr($b['start_time'], 0, 5);
            $be = substr($b['end_time'], 0, 5);
            if ($slot < $be && $slot_end > $bs) {
                $reason = 'busy';
                break;
            }
        }
    }

    $slots[] = array(
        't'      => $slot,
        'label'  => date('h:i A', strtotime($slot)),
        'ok'     => ($reason === ''),
        'reason' => $reason,
    );
}

json_out(array('ok' => true, 'message' => '', 'slots' => $slots));