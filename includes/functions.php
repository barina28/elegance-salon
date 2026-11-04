<?php
/**
 * ============================================================
 *  ELEGANCE SALON - COMMON HELPER FUNCTIONS
 *  Small procedural functions used by every module:
 *  escaping, redirects, form input, settings, flash
 *  messages, CSRF protection, validators, notifications.
 * ============================================================
 */

/* ------------------------------------------------------------
 * 1. OUTPUT ESCAPING - prevents XSS.
 *    Rule: EVERY piece of data printed inside HTML goes
 *    through e(), e.g.  <?= e($client['name']) ?>
 * ---------------------------------------------------------- */
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* ------------------------------------------------------------
 * 2. REDIRECT
 * ---------------------------------------------------------- */
function redirect($url) {
    header('Location: ' . $url);
    exit;
}

/* ------------------------------------------------------------
 * 3. FORM INPUT HELPERS (trimmed strings, never undefined
 *    index warnings)
 * ---------------------------------------------------------- */
function post($key, $default = '') {
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function get($key, $default = '') {
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

/* ------------------------------------------------------------
 * 4. SETTINGS - read editable key/value pairs from the
 *    `settings` table (cached, so it is one query per page)
 * ---------------------------------------------------------- */
function get_setting($key, $default = '') {
    global $conn;
    static $cache = null;

    if ($cache === null) {
        $cache = array();
        if (isset($conn) && $conn instanceof mysqli) {
            $res = mysqli_query($conn, "SELECT setting_key, setting_value FROM settings");
            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) {
                    $cache[$row['setting_key']] = $row['setting_value'];
                }
            }
        }
    }
    return isset($cache[$key]) ? $cache[$key] : $default;
}

/** Format an amount with the currency symbol from settings */
function money($amount) {
    return get_setting('currency_symbol', 'Rs') . ' '
         . number_format((float)$amount, 0);
}

/* ------------------------------------------------------------
 * 5. FLASH MESSAGES - one-time banners after an action.
 *    set_flash('success', 'Client saved.');
 *    ...then the layout calls show_flash().
 * ---------------------------------------------------------- */
function set_flash($type, $message) {
    $_SESSION['flash'][] = array('type' => $type, 'message' => $message);
}

function show_flash() {
    if (empty($_SESSION['flash'])) {
        return;
    }
    $allowed = array('success', 'danger', 'warning', 'info');
    foreach ($_SESSION['flash'] as $f) {
        $type = in_array($f['type'], $allowed, true) ? $f['type'] : 'info';
        echo '<div class="alert alert-' . $type . ' alert-dismissible fade show" role="alert">';
        echo e($f['message']);
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
        echo '</div>';
    }
    unset($_SESSION['flash']);
}

/* ------------------------------------------------------------
 * 6. CSRF PROTECTION - every POST form carries a secret token
 *    that the server verifies before touching the database.
 * ---------------------------------------------------------- */
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function verify_csrf() {
    $sent  = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    $known = isset($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
    if ($sent === '' || !hash_equals($known, $sent)) {
        http_response_code(403);
        die('<h3 style="font-family:Arial">403 - Invalid form token.</h3>'
          . '<p>Go back, refresh the page and submit again.</p>');
    }
}

/* ------------------------------------------------------------
 * 7. SIMPLE VALIDATORS
 * ---------------------------------------------------------- */
function valid_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/** Expects YYYY-MM-DD */
function valid_date($date) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

/** Expects HH:MM or HH:MM:SS (24-hour) */
function valid_time($time) {
    return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time);
}

/** Keeps only digits (for phone numbers) */
function digits_only($value) {
    return preg_replace('/[^0-9]/', '', $value);
}

/* ------------------------------------------------------------
 * 8. NOTIFICATION LOGGER
 *    Every email/SMS/system alert is also stored in the
 *    `notifications` table so it appears in staff inboxes
 *    and can be audited later.
 * ---------------------------------------------------------- */
function log_notification($data) {
    global $conn;

    $user_id   = isset($data['user_id'])  ? (int)$data['user_id'] : null;
    $recipient = isset($data['recipient']) ? $data['recipient']   : null;
    $channel   = isset($data['channel'])  ? $data['channel']      : 'system';
    $type      = isset($data['type'])     ? $data['type']         : 'system';
    $subject   = isset($data['subject'])  ? $data['subject']      : '';
    $message   = isset($data['message'])  ? $data['message']      : '';
    $status    = isset($data['status'])   ? $data['status']       : 'pending';

    $sql = "INSERT INTO notifications
              (user_id, recipient, channel, type, subject, message, status)
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'issssss',
        $user_id, $recipient, $channel, $type, $subject, $message, $status);
    mysqli_stmt_execute($stmt);
    $id = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return $id;
}


/* ------------------------------------------------------------
 * 9. APPOINTMENT HELPERS (Step 5)
 * ---------------------------------------------------------- */

/**
 * Build the bookable START times for one stylist/day/service.
 * Uses the stylist's own shift (staff.shift_start / shift_end)
 * in 15-minute steps; a slot must fit the whole service length.
 * Returns an array of 'HH:MM' strings.
 */
function build_slot_grid($shift_start, $shift_end, $duration_min) {
    $slots = array();
    $start = strtotime('today ' . $shift_start);
    $end   = strtotime('today ' . $shift_end);
    $step  = 15 * 60;
    for ($t = $start; $t + ((int)$duration_min * 60) <= $end; $t += $step) {
        $slots[] = date('H:i', $t);
    }
    return $slots;
}

/**
 * Send an email notification for an appointment. Clients WITH an email
 * receive a real email if SMTP is configured and enabled in Settings,
 * and the event is recorded in the `notifications` table. Clients WITHOUT
 * an email get a 'system' row so the front desk knows to phone them.
 * Returns the notification id.
 */
function notify_appointment($client_id, $subject, $message) {
    global $conn;

    $stmt = mysqli_prepare($conn, "SELECT name, email FROM clients WHERE client_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $client_id);
    mysqli_stmt_execute($stmt);
    $client = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$client) {
        return 0;
    }

    if ($client['email'] !== null && $client['email'] !== '') {
        $full_message = 'Hi ' . $client['name'] . ",\n\n" . $message;

        $notif_id = log_notification(array(
            'recipient' => $client['email'],
            'channel'   => 'email',
            'type'      => 'appointment',
            'subject'   => $subject,
            'message'   => $full_message,
            'status'    => 'pending',
        ));

        // Format HTML email and send via SMTP
        $fallback_name = defined('SITE_NAME') ? SITE_NAME : 'Elegance Salon';
        $salon_name = get_setting('salon_name', $fallback_name);
        $html_body  = render_email_template($subject, $client['name'], $message, $salon_name);
        $result     = send_email($client['email'], $client['name'], $subject, $html_body, $full_message);

        // Update database record with final status
        $new_status = (!empty($result['success'])) ? 'sent' : 'failed';
        $sent_at    = ($new_status === 'sent') ? date('Y-m-d H:i:s') : null;
        $updated_msg = $full_message;
        if ($new_status === 'failed' && !empty($result['error'])) {
            $updated_msg .= "\n\n[Delivery Status]: " . $result['error'];
        }

        $update = mysqli_prepare($conn, "UPDATE notifications SET status = ?, sent_at = ?, message = ? WHERE notification_id = ?");
        mysqli_stmt_bind_param($update, 'sssi', $new_status, $sent_at, $updated_msg, $notif_id);
        mysqli_stmt_execute($update);
        mysqli_stmt_close($update);

        return $notif_id;
    }

    return log_notification(array(
        'recipient' => $client['name'],
        'channel'   => 'system',
        'type'      => 'appointment',
        'subject'   => $subject,
        'message'   => "No email on file - please phone the client.\n\n" . $message,
        'status'    => 'sent',
    ));
}

/* ------------------------------------------------------------
 * 10. REAL EMAIL SENDER (PHPMailer / SMTP)
 * ---------------------------------------------------------- */

/**
 * Generate a clean, branded HTML email template matching salon branding
 */
function render_email_template($subject, $recipient_name, $content, $salon_name = '') {
    if ($salon_name === '') {
        $fallback_name = defined('SITE_NAME') ? SITE_NAME : 'Elegance Salon';
        $salon_name = get_setting('salon_name', $fallback_name);
    }
    $salon_phone   = get_setting('salon_phone', '');
    $salon_address = get_setting('salon_address', '');
    $salon_hours   = get_setting('salon_hours', '');

    $safe_content = nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8'));
    $safe_name    = htmlspecialchars($recipient_name, ENT_QUOTES, 'UTF-8');
    $safe_subject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
    $safe_salon   = htmlspecialchars($salon_name, ENT_QUOTES, 'UTF-8');

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$safe_subject}</title>
</head>
<body style="margin:0; padding:0; background-color:#faf7f2; font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color:#2d2a26;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#faf7f2; padding:30px 15px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" style="max-width:600px; background-color:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 4px 15px rgba(0,0,0,0.06); border:1px solid #ebdcd5;" cellspacing="0" cellpadding="0" border="0">
          <tr>
            <td style="background: linear-gradient(135deg, #b76e79 0%, #96525d 60%, #c9a227 100%); padding:32px 24px; text-align:center; color:#ffffff;">
              <h1 style="margin:0; font-size:26px; font-weight:700; letter-spacing:0.5px; color:#ffffff;">{$safe_salon}</h1>
              <p style="margin:6px 0 0; font-size:13px; text-transform:uppercase; letter-spacing:2px; color:#fdf5e6; opacity:0.9;">Appointment Notification</p>
            </td>
          </tr>
          <tr>
            <td style="padding:32px 28px 24px; font-size:15px; line-height:1.6; color:#3b3632;">
              <p style="margin-top:0; font-size:16px;">Dear <strong>{$safe_name}</strong>,</p>
              
              <div style="background-color:#fcf9f5; border-left:4px solid #b76e79; padding:16px 20px; border-radius:4px; margin:22px 0; font-size:15px; color:#2d2a26;">
                {$safe_content}
              </div>

              <p style="margin:20px 0 0; color:#555;">If you have any questions or need to make adjustments to your visit, please feel free to reach out to our team.</p>
              
              <p style="margin:24px 0 0; font-size:14px; color:#777;">
                Warm regards,<br>
                <strong style="color:#b76e79;">The {$safe_salon} Team</strong>
              </p>
            </td>
          </tr>
          <tr>
            <td style="background-color:#2d2a26; padding:22px 24px; text-align:center; color:#cfc7bd; font-size:12px; line-height:1.6;">
              <strong style="color:#ffffff; font-size:13px;">{$safe_salon}</strong><br>
HTML;

    if ($salon_address !== '') {
        $html .= htmlspecialchars($salon_address, ENT_QUOTES, 'UTF-8') . '<br>';
    }
    if ($salon_phone !== '') {
        $html .= 'Phone: ' . htmlspecialchars($salon_phone, ENT_QUOTES, 'UTF-8') . '<br>';
    }
    if ($salon_hours !== '') {
        $html .= 'Hours: ' . htmlspecialchars($salon_hours, ENT_QUOTES, 'UTF-8') . '<br>';
    }

    $html .= <<<HTML
              <p style="margin:10px 0 0; font-size:11px; color:#888;">This is an automated notification from our salon management system.</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;

    return $html;
}

/**
 * Send an email using PHPMailer via configured SMTP
 * Returns array('success' => bool, 'error' => string)
 */
function send_email($to_email, $to_name, $subject, $body_html, $body_text = '') {
    if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        return array('success' => false, 'error' => 'Invalid recipient email address.');
    }

    $smtp_enabled = get_setting('smtp_enabled', '0');
    if ($smtp_enabled !== '1') {
        return array('success' => false, 'error' => 'SMTP is not enabled. Please configure and enable SMTP in Site Settings.');
    }

    $host       = get_setting('smtp_host', '');
    $port       = (int)get_setting('smtp_port', 587);
    $user       = get_setting('smtp_user', '');
    $pass       = get_setting('smtp_pass', '');
    $encryption = strtolower(get_setting('smtp_secure', 'tls'));
    $from_email = get_setting('smtp_from_email', '');
    $from_name  = get_setting('smtp_from_name', '');

    if ($from_email === '') {
        $from_email = get_setting('salon_email', $user);
    }
    if ($from_name === '') {
        $from_name = get_setting('salon_name', defined('SITE_NAME') ? SITE_NAME : 'Elegance Salon');
    }

    if (empty($host)) {
        return array('success' => false, 'error' => 'SMTP Host is not configured in Site Settings.');
    }

    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        return array('success' => false, 'error' => 'PHPMailer library is not available.');
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->Port       = $port;
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 15;

        if ($user !== '' || $pass !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = $user;
            $mail->Password = $pass;
        } else {
            $mail->SMTPAuth = false;
        }

        if ($encryption === 'ssl') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }

        $mail->setFrom($from_email, $from_name);
        $mail->addAddress($to_email, $to_name);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body_html;
        if ($body_text !== '') {
            $mail->AltBody = $body_text;
        } else {
            $mail->AltBody = strip_tags(str_replace(array('<br>', '<br/>', '<br />', '</p>'), "\n", $body_html));
        }

        $mail->send();
        return array('success' => true);
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        return array('success' => false, 'error' => $mail->ErrorInfo ?: $e->getMessage());
    } catch (\Throwable $t) {
        return array('success' => false, 'error' => $t->getMessage());
    }
}