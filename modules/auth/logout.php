<?php
/**
 * LOGOUT - clears the session completely, then returns to login.
 */

require_once __DIR__ . '/../../includes/init.php';

// 1. wipe all session variables
$_SESSION = array();

// 2. remove the session cookie from the browser too
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

// 3. destroy the session on the server
session_destroy();

// 4. start a fresh session just for the goodbye flash message
session_start();
set_flash('success', 'You have been logged out. See you soon!');
redirect(BASE_URL . 'modules/auth/login.php');
