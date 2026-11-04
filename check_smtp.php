<?php
require_once 'includes/init.php';
$conn = get_db_connection();

echo "=== SMTP SETTINGS ===\n";
$keys = ['smtp_host','smtp_port','smtp_encryption','smtp_username','smtp_password','smtp_from_email','smtp_from_name','email_enabled'];
foreach($keys as $k) {
    $v = get_setting($conn, $k);
    echo $k . ' = ' . ($v === null ? '(null/not set)' : ($k === 'smtp_password' ? str_repeat('*', strlen((string)$v)) : $v)) . "\n";
}

echo "\n=== RECENT NOTIFICATION FAILURES ===\n";
$r = mysqli_query($conn, 'SELECT id, subject, channel, recipient, status, message, created_at FROM notifications ORDER BY id DESC LIMIT 5');
while($row = mysqli_fetch_assoc($r)) {
    echo "--- Notification ID: " . $row['id'] . "\n";
    echo "Status: " . $row['status'] . "\n";
    echo "Message: " . $row['message'] . "\n\n";
}

echo "\n=== PHPMAILER CHECK ===\n";
if (file_exists('vendor/autoload.php')) {
    echo "vendor/autoload.php: EXISTS\n";
    require_once 'vendor/autoload.php';
    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        echo "PHPMailer class: FOUND\n";
    } else {
        echo "PHPMailer class: NOT FOUND\n";
    }
} else {
    echo "vendor/autoload.php: MISSING\n";
}

