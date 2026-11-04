<?php
/**
 * ============================================================
 *  ELEGANCE SALON - DATABASE CONNECTION (procedural MySQLi)
 *  Creates the $conn variable that every page uses.
 * ============================================================
 */

require_once __DIR__ . '/config.php';

// Connect to MySQL and select the database in one step
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if (!$conn) {
    // Friendly message instead of a raw fatal error
    die(
        '<div style="font-family:Arial,sans-serif;max-width:600px;margin:60px auto;padding:20px;'
        . 'border:1px solid #f5c6cb;background:#f8d7da;color:#721c24;border-radius:8px">'
        . '<h2>Database connection failed</h2>'
        . '<p>1. Is MySQL running in the XAMPP Control Panel?</p>'
        . '<p>2. Did you import <code>database/elegance_salon.sql</code> in phpMyAdmin?</p>'
        . '<p>3. Check DB_USER / DB_PASS in <code>config/config.php</code>.</p>'
        . '<p><b>MySQL said:</b> ' . htmlspecialchars(mysqli_connect_error()) . '</p>'
        . '</div>'
    );
}

// Make sure all text travels as UTF-8 (needed for ₹, accented names, etc.)
mysqli_set_charset($conn, 'utf8mb4');
