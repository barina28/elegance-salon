<?php
/**
 * ============================================================
 *  APPOINTMENTS - SHARED STATUS-ACTION HANDLER
 *  Included by index.php and view.php AFTER they define:
 *      $transitions, $allowed_actions, $return_url,
 *      $is_stylist, $my_staff_id
 *  Processes POST {action, appointment_id} with CSRF +
 *  transition checks, logs the client notification, and
 *  redirects back to $return_url.
 * ============================================================
 */

/* never allow this include to be opened directly in the browser */
if (!function_exists('verify_csrf')) {
    exit('No direct access.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = post('action');
    $id     = (int)post('appointment_id');

    if ($id <= 0 || !isset($allowed_actions[$action])) {
        set_flash('warning', 'Unknown action.');
        redirect($return_url);
    }

    /* load the appointment with the names used in the messages */
    $stmt = mysqli_prepare($conn,
        "SELECT a.*, c.name AS client_name, st.name AS staff_name, s.name AS service_name
         FROM appointments a
         JOIN clients  c  ON c.client_id  = a.client_id
         JOIN staff    st ON st.staff_id  = a.staff_id
         JOIN services s  ON s.service_id = a.service_id
         WHERE a.appointment_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $appt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$appt) {
        set_flash('danger', 'Appointment not found (maybe already removed).');
        redirect($return_url);
    }

    /* stylists may only touch their own bookings */
    if ($is_stylist && (int)$appt['staff_id'] !== (int)$my_staff_id) {
        set_flash('danger', 'Access denied: this appointment belongs to another stylist.');
        redirect($return_url);
    }

    /* the requested change must be legal for the current status */
    $to = $allowed_actions[$action];
    if (!in_array($to, $transitions[$appt['status']], true)) {
        set_flash('warning', 'Cannot ' . str_replace('_', ' ', $action)
            . ' - the appointment is already ' . str_replace('_', ' ', $appt['status']) . '.');
        redirect($return_url);
    }

    $stmt = mysqli_prepare($conn, "UPDATE appointments SET status = ? WHERE appointment_id = ?");
    mysqli_stmt_bind_param($stmt, 'si', $to, $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    /* simulated client notifications (stored in `notifications`) */
    $when  = date('d M Y', strtotime($appt['appointment_date'])) . ' at '
           . date('h:i A', strtotime($appt['start_time']));
    $salon = get_setting('salon_name', SITE_NAME);

    if ($to === 'confirmed') {
        notify_appointment((int)$appt['client_id'],
            'Appointment confirmed - ' . $salon,
            'Your ' . $appt['service_name'] . ' appointment with ' . $appt['staff_name']
            . ' on ' . $when . ' is CONFIRMED. See you soon!');
    } elseif ($to === 'cancelled') {
        notify_appointment((int)$appt['client_id'],
            'Appointment cancelled - ' . $salon,
            'Your ' . $appt['service_name'] . ' appointment on ' . $when
            . ' has been CANCELLED. Call us any time to rebook.');
    }

    set_flash('success', 'Appointment for ' . $appt['client_name'] . ' is now '
        . str_replace('_', ' ', $to) . '.');
    redirect($return_url);
}