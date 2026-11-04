<?php
/**
 * ============================================================
 *  ELEGANCE SALON - SESSION & ROLE BASED ACCESS CONTROL
 *  Roles: admin > receptionist > stylist
 * ============================================================
 */

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

/* ---------------- login state ---------------- */

function is_logged_in() {
    return !empty($_SESSION['user_id']);
}

function current_user() {
    if (!is_logged_in()) {
        return null;
    }
    return array(
        'user_id'  => $_SESSION['user_id'],
        'name'     => $_SESSION['name'],
        'username' => $_SESSION['username'],
        'email'    => $_SESSION['email'],
        'role'     => $_SESSION['role'],
    );
}

function current_role() {
    return is_logged_in() ? $_SESSION['role'] : null;
}

/* ---------------- access guards ----------------
 * Put ONE line at the top of every protected page:
 *    require_role('admin');
 *    require_role(array('admin','receptionist'));
 * ---------------------------------------------- */
function require_login() {
    if (!is_logged_in()) {
        if (empty($_SESSION['flash'])) {
            set_flash('warning', 'Please log in to continue.');
        }
        redirect(BASE_URL . 'modules/auth/login.php');
    }
}

function require_role($roles) {
    require_login();
    if (!in_array(current_role(), (array)$roles, true)) {
        set_flash('danger', 'Access denied: your role cannot open that page.');
        redirect(dashboard_url());
    }
}

/* ---------------- helpers ---------------- */

/** Where each role lands after login (single smart dashboard) */
function dashboard_url() {
    return BASE_URL . 'modules/dashboard/index.php';
}

/**
 * Feature permission map - used to build menus and to double
 * check what each role is allowed to manage.
 */
function can_manage($feature) {
    $map = array(
        'clients'        => array('admin', 'receptionist'),
        'appointments'   => array('admin', 'receptionist', 'stylist'),
        'staff'          => array('admin'),
        'services'       => array('admin'),
        'inventory'      => array('admin', 'receptionist'),
        'payments'       => array('admin', 'receptionist'),
        'reports'        => array('admin'),
        'users'          => array('admin'),
        'settings'       => array('admin'),
        'feedback'       => array('admin'),
        'notifications'  => array('admin', 'receptionist'),
    );
    $role = current_role();
    return isset($map[$feature]) && in_array($role, $map[$feature], true);
}
