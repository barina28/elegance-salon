<?php
/**
 * ============================================================
 *  CALENDAR - JSON EVENT FEED (Step 6)
 *  GET params (sent by FullCalendar / assets/js/calendar.js):
 *    start, end      - visible range (ISO datetimes)
 *    staff_id        - 0 = all stylists (stylists are locked
 *                      to their own id server-side)
 *    hide_cancelled  - 1 = drop cancelled + no-show bookings
 *  Returns an array of FullCalendar events:
 *    - one per appointment (coloured by status, click = details)
 *    - one per staff_shifts row: regular/overtime render as
 *      shaded background, 'off' renders as a "Day off" block.
 *  Read-only; requires login.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/init.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

function feed_out($data) {
    echo json_encode($data);
    exit;
}

/* ---------- visible range ---------- */
 $start_raw = get('start');
 $end_raw   = get('end');
 $start_d   = $start_raw !== '' ? date('Y-m-d', strtotime($start_raw)) : '';
 $end_d     = $end_raw   !== '' ? date('Y-m-d', strtotime($end_raw))   : '';

if ($start_d === '' || $end_d === '' || $end_d <= $start_d) {
    feed_out(array());
}
/* never answer more than 70 days in one call */
if (strtotime($end_d) - strtotime($start_d) > 70 * 86400) {
    $end_d = date('Y-m-d', strtotime($start_d . ' +70 days'));
}

/* ---------- who is asking ---------- */
 $u              = current_user();
 $staff_filter   = (int)get('staff_id');
 $hide_cancelled = (get('hide_cancelled') === '1');
if ($staff_filter < 0) {
    $staff_filter = 0;                 /* ignore negative ids from the URL */
}

if ($u['role'] === 'stylist') {
    $stmt = mysqli_prepare($conn, "SELECT staff_id FROM staff WHERE user_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $u['user_id']);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row) {
        feed_out(array());          /* stylist without a staff profile */
    }
    $staff_filter = (int)$row['staff_id'];
}

 $events   = array();
 $view_url = BASE_URL . 'modules/appointments/view.php?id=';

/* ============================================================
 *  1) APPOINTMENTS
 * ========================================================== */
if ($staff_filter >= 0) {
    $sql    = "SELECT a.appointment_id, a.appointment_date, a.start_time, a.end_time,
                      a.status, c.name AS client_name, st.name AS staff_name,
                      s.name AS service_name
               FROM appointments a
               JOIN clients  c  ON c.client_id  = a.client_id
               JOIN staff    st ON st.staff_id  = a.staff_id
               JOIN services s  ON s.service_id = a.service_id
               WHERE a.appointment_date >= ? AND a.appointment_date < ?";
    $types  = 'ss';
    $params = array($start_d, $end_d);

    if ($staff_filter > 0) {
        $sql     .= ' AND a.staff_id = ?';
        $types   .= 'i';
        $params[] = $staff_filter;
    }
    if ($hide_cancelled) {
        $sql .= " AND a.status NOT IN ('cancelled','no_show')";
    }

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    foreach ($rows as $r) {
        $title = $r['client_name'] . ' - ' . $r['service_name'];
        if ($staff_filter === 0) {
            $title .= ' (' . $r['staff_name'] . ')';
        }
        $events[] = array(
            'id'           => 'appt-' . $r['appointment_id'],
            'title'        => $title,
            'start'        => $r['appointment_date'] . 'T' . substr($r['start_time'], 0, 8),
            'end'          => $r['appointment_date'] . 'T' . substr($r['end_time'],   0, 8),
            'classNames'   => array('ev-' . $r['status']),
            'extendedProps' => array(
                'view_url' => $view_url . (int)$r['appointment_id'],
                'status'   => $r['status'],
            ),
        );
    }
}

/* ============================================================
 *  2) DATE-SPECIFIC SHIFTS (staff_shifts)
 * ========================================================== */
 $sql    = "SELECT ss.shift_id, ss.staff_id, ss.shift_date, ss.start_time, ss.end_time,
                  ss.shift_type, ss.notes, st.name AS staff_name
           FROM staff_shifts ss
           JOIN staff st ON st.staff_id = ss.staff_id
           WHERE ss.shift_date >= ? AND ss.shift_date < ?";
 $types  = 'ss';
 $params = array($start_d, $end_d);

if ($staff_filter > 0) {
    $sql     .= ' AND ss.staff_id = ?';
    $types   .= 'i';
    $params[] = $staff_filter;
}

 $stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
 $shifts = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

foreach ($shifts as $sh) {
    $s = $sh['shift_date'] . 'T' . substr($sh['start_time'], 0, 8);
    $e = $sh['shift_date'] . 'T' . substr($sh['end_time'],   0, 8);

    if ($sh['shift_type'] === 'off') {
        /* leave / holiday - visible block, booking is blocked too */
        $events[] = array(
            'id'         => 'shift-' . $sh['shift_id'],
            'title'      => 'Day off - ' . $sh['staff_name']
                            . ($sh['notes'] !== null && $sh['notes'] !== '' ? ' (' . $sh['notes'] . ')' : ''),
            'start'      => $s,
            'end'        => $e,
            'classNames' => array('ev-off'),
        );
    } else {
        /* working window - shaded background strip */
        $events[] = array(
            'id'         => 'shift-' . $sh['shift_id'],
            'display'    => 'background',
            'start'      => $s,
            'end'        => $e,
            'classNames' => array('ev-shift'),
        );
    }
}

feed_out($events);