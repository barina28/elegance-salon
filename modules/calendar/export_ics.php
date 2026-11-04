<?php
/**
 * ============================================================
 *  CALENDAR - ICALENDAR (.ICS) EXPORT
 *  Export a single appointment or all upcoming appointments
 *  into standard .ics format for Google Calendar, Outlook, and Apple iCal.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/init.php';

$appointment_id = (int)get('appointment_id');
$staff_id       = (int)get('staff_id');

$salon_name    = get_setting('salon_name', defined('SITE_NAME') ? SITE_NAME : 'Elegance Salon');
$salon_address = get_setting('salon_address', 'Beauty District');

$appointments = array();

if ($appointment_id > 0) {
    // Single appointment export
    $stmt = mysqli_prepare($conn,
        "SELECT a.*, c.name AS client_name, c.phone AS client_phone,
                s.name AS service_name, s.duration_minutes, st.name AS staff_name
         FROM appointments a
         JOIN clients c ON c.client_id = a.client_id
         JOIN services s ON s.service_id = a.service_id
         JOIN staff st ON st.staff_id = a.staff_id
         WHERE a.appointment_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $appointment_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if ($row) {
        $appointments[] = $row;
    }
    $filename = "appointment-{$appointment_id}.ics";
} else {
    // All upcoming appointments
    require_login();
    $u = current_user();
    if ($u['role'] === 'stylist') {
        $st_res = mysqli_fetch_assoc(mysqli_query($conn, "SELECT staff_id FROM staff WHERE user_id = " . (int)$u['user_id']));
        $staff_id = $st_res ? (int)$st_res['staff_id'] : -1;
    }

    $where = "WHERE a.appointment_date >= CURDATE() AND a.status IN ('scheduled','confirmed')";
    if ($staff_id > 0) {
        $where .= " AND a.staff_id = " . $staff_id;
    }

    $res = mysqli_query($conn,
        "SELECT a.*, c.name AS client_name, c.phone AS client_phone,
                s.name AS service_name, s.duration_minutes, st.name AS staff_name
         FROM appointments a
         JOIN clients c ON c.client_id = a.client_id
         JOIN services s ON s.service_id = a.service_id
         JOIN staff st ON st.staff_id = a.staff_id
         {$where}
         ORDER BY a.appointment_date ASC, a.start_time ASC
         LIMIT 100");
    if ($res) {
        $appointments = mysqli_fetch_all($res, MYSQLI_ASSOC);
    }
    $filename = "salon-calendar.ics";
}

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:-//" . preg_replace('/[^a-zA-Z0-9 ]/', '', $salon_name) . "//Salon Calendar//EN\r\n";
echo "CALSCALE:GREGORIAN\r\n";
echo "METHOD:PUBLISH\r\n";

foreach ($appointments as $a) {
    $start_ts = strtotime($a['appointment_date'] . ' ' . $a['start_time']);
    $end_ts   = strtotime($a['appointment_date'] . ' ' . $a['end_time']);

    $dtstart = gmdate('Ymd\THis\Z', $start_ts);
    $dtend   = gmdate('Ymd\THis\Z', $end_ts);
    $created = gmdate('Ymd\THis\Z', strtotime($a['created_at']));
    $uid     = 'appt-' . $a['appointment_id'] . '-' . $start_ts . '@elegancesalon.local';

    $summary     = $a['service_name'] . ' with ' . $a['staff_name'];
    $description = "Client: " . $a['client_name'] . " (" . $a['client_phone'] . ")\\nService: " . $a['service_name'] . "\\nStatus: " . ucfirst($a['status']);
    if (!empty($a['notes'])) {
        $description .= "\\nNotes: " . str_replace("\n", "\\n", $a['notes']);
    }

    echo "BEGIN:VEVENT\r\n";
    echo "UID:{$uid}\r\n";
    echo "DTSTAMP:{$created}\r\n";
    echo "DTSTART:{$dtstart}\r\n";
    echo "DTEND:{$dtend}\r\n";
    echo "SUMMARY:" . addcslashes($summary, ",;\\") . "\r\n";
    echo "DESCRIPTION:" . addcslashes($description, ",;") . "\r\n";
    echo "LOCATION:" . addcslashes($salon_name . ', ' . $salon_address, ",;\\") . "\r\n";
    echo "STATUS:CONFIRMED\r\n";
    echo "END:VEVENT\r\n";
}

echo "END:VCALENDAR\r\n";
exit;

