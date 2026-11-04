<?php
/**
 * ============================================================
 *  ELEGANCE SALON MANAGEMENT SYSTEM
 *  Global Configuration
 * ============================================================
 *  Edit the values below to match your XAMPP setup.
 */

/* ---------- 1. DATABASE SETTINGS (XAMPP defaults) ---------- */
define('DB_HOST', 'localhost');
define('DB_NAME', 'elegance_salon');
define('DB_USER', 'root');
define('DB_PASS', '');            // XAMPP default = empty password

/* ---------- 2. SITE SETTINGS ---------- */
define('SITE_NAME', 'Elegance Salon');

// Custom session name (avoids clashing with other apps on localhost)
define('SESSION_NAME', 'ELEGANCE_SESSID');

// URL where the app lives, WITH a trailing slash.
// If your folder is C:\xampp\htdocs\elegance-salon  ->  keep the value below.
// If you renamed the folder, change it here (nothing else needs editing).
define('BASE_URL', 'http://localhost/elegance-salon/');

// Physical path of the project on disk (used for file uploads, FPDF, etc.)
define('APP_ROOT', dirname(__DIR__));

/* ---------- 3. LOCAL SETTINGS ---------- */
define('APP_TIMEZONE', 'Asia/Kolkata');   // change to your own timezone
define('CURRENCY_DEFAULT', '₹');          // fallback if not set in settings table

/* ---------- 4. DEBUGGING ---------- */
// true  = show errors on screen (good while developing)
// false = hide errors (use on a live/public server)
define('DEBUG_MODE', true);

if (DEBUG_MODE) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

date_default_timezone_set(APP_TIMEZONE);
