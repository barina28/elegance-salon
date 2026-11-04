<?php
/**
 * ============================================================
 *  ELEGANCE SALON - BOOTSTRAP FILE
 *  Include this single line at the top of every page:
 *
 *      require_once __DIR__ . '/includes/init.php';
 *
 *  It loads: config -> database -> helpers -> session/auth.
 * ============================================================
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';