<?php
/**
 * ============================================================
 *  ADMIN - SITE SETTINGS (Step 11)
 *  Whitelisted settings editor (identity, currency, tax rate, etc.)
 * ============================================================
 */
require_once __DIR__ . '/../../includes/init.php';
require_role(array('admin'));

$self = BASE_URL . 'modules/admin/settings.php';
$errors = array();

// Whitelist of editable settings keys
$allowed_keys = array(
    'salon_name', 'salon_slogan', 'salon_email', 'salon_phone', 'salon_address', 'salon_hours',
    'currency_symbol', 'tax_rate', 'invoice_footer',
    'contact_address', 'contact_phone', 'contact_email',
    'smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_secure',
    'smtp_from_email', 'smtp_from_name'
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $settings_post = isset($_POST['settings']) && is_array($_POST['settings']) ? $_POST['settings'] : array();

    // Checkbox handling: if smtp_enabled checkbox was unchecked, set value to '0'
    if (!isset($settings_post['smtp_enabled'])) {
        $settings_post['smtp_enabled'] = '0';
    }

    // Specific validation checks
    if (isset($settings_post['tax_rate'])) {
        $tr = (float)$settings_post['tax_rate'];
        if ($tr < 0 || $tr > 100) {
            $errors['tax_rate'] = 'Tax rate must be between 0% and 100%.';
        }
    }
    if (isset($settings_post['salon_email']) && $settings_post['salon_email'] !== '' && !filter_var($settings_post['salon_email'], FILTER_VALIDATE_EMAIL)) {
        $errors['salon_email'] = 'Invalid email format.';
    }
    if (isset($settings_post['smtp_from_email']) && $settings_post['smtp_from_email'] !== '' && !filter_var($settings_post['smtp_from_email'], FILTER_VALIDATE_EMAIL)) {
        $errors['smtp_from_email'] = 'Invalid sender email format.';
    }

    $action = post('action', 'save');

    if (empty($errors)) {
        foreach ($allowed_keys as $key) {
            if (isset($settings_post[$key])) {
                $val = trim($settings_post[$key]);
                // Upsert into settings table
                $stmt = mysqli_prepare($conn, "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                mysqli_stmt_bind_param($stmt, 'sss', $key, $val, $val);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }

        if ($action === 'test_email') {
            $test_to = post('test_email_recipient');
            if (!filter_var($test_to, FILTER_VALIDATE_EMAIL)) {
                $_SESSION['flash_error'] = 'Settings saved, but invalid test email address provided.';
            } else {
                $salon_name = $settings_post['salon_name'] ?? get_setting('salon_name', SITE_NAME);
                $test_subject = 'SMTP Test - ' . $salon_name;
                $test_content = "This is a test notification from {$salon_name}.\n\nYour SMTP email settings are working successfully! Appointment confirmation emails can now be sent to clients.";
                $html_body = render_email_template($test_subject, 'Admin', $test_content, $salon_name);

                $res = send_email($test_to, 'Admin', $test_subject, $html_body, $test_content);
                if (!empty($res['success'])) {
                    $_SESSION['flash_success'] = "Settings saved & test email was sent successfully to {$test_to}!";
                } else {
                    $_SESSION['flash_error'] = "Settings saved, but test email failed: " . ($res['error'] ?? 'Unknown error');
                }
            }
        } else {
            $_SESSION['flash_success'] = 'Site settings updated successfully.';
        }
        redirect($self);
    }
}

// Load current settings from DB
$current_settings = array();
$res = mysqli_query($conn, "SELECT setting_key, setting_value FROM settings");
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $current_settings[$row['setting_key']] = $row['setting_value'];
    }
}

$page_title = 'Site Settings';
require_once __DIR__ . '/../../includes/dash_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
    <div>
        <h1 class="h3 mb-1 fw-bold text-dark"><i class="bi bi-gear-fill text-salon me-2"></i>Site Settings</h1>
        <p class="text-muted small mb-0">Configure salon identity, email notifications, currency, tax rates, and contact details.</p>
    </div>
</div>

<?php if (isset($_SESSION['flash_success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-2"></i><?= e($_SESSION['flash_success']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['flash_error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle me-2"></i><?= e($_SESSION['flash_error']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<form method="POST" action="">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

    <div class="row g-4">
        <!-- Salon Identity -->
        <div class="col-lg-6">
            <div class="card card-salon h-100">
                <div class="card-header bg-white"><i class="bi bi-shop text-salon me-2"></i>Salon Identity & Branding</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Salon Name</label>
                        <input type="text" name="settings[salon_name]" class="form-control" value="<?= e($current_settings['salon_name'] ?? 'Elegance Salon') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Slogan / Tagline</label>
                        <input type="text" name="settings[salon_slogan]" class="form-control" value="<?= e($current_settings['salon_slogan'] ?? 'Luxury Hair & Spa') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Business Email</label>
                        <input type="email" name="settings[salon_email]" class="form-control <?= isset($errors['salon_email']) ? 'is-invalid' : '' ?>" value="<?= e($current_settings['salon_email'] ?? '') ?>">
                        <?php if (isset($errors['salon_email'])): ?><div class="invalid-feedback"><?= e($errors['salon_email']) ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Business Phone</label>
                        <input type="text" name="settings[salon_phone]" class="form-control" value="<?= e($current_settings['salon_phone'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Physical Address</label>
                        <textarea name="settings[salon_address]" class="form-control" rows="2"><?= e($current_settings['salon_address'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Working Hours Text</label>
                        <input type="text" name="settings[salon_hours]" class="form-control" value="<?= e($current_settings['salon_hours'] ?? 'Mon - Sat: 9:00 AM - 8:00 PM') ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Financial & Billing -->
        <div class="col-lg-6">
            <div class="card card-salon h-100">
                <div class="card-header bg-white"><i class="bi bi-receipt text-salon me-2"></i>Financial & Billing Settings</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Currency Symbol</label>
                        <input type="text" name="settings[currency_symbol]" class="form-control" value="<?= e($current_settings['currency_symbol'] ?? 'Rs.') ?>" required>
                        <div class="form-text small">Drives currency display across POS, invoices, and reports.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Default Tax Rate (%)</label>
                        <input type="number" step="0.01" min="0" max="100" name="settings[tax_rate]" class="form-control <?= isset($errors['tax_rate']) ? 'is-invalid' : '' ?>" value="<?= e($current_settings['tax_rate'] ?? '5.00') ?>" required>
                        <?php if (isset($errors['tax_rate'])): ?><div class="invalid-feedback"><?= e($errors['tax_rate']) ?></div><?php endif; ?>
                        <div class="form-text small">Pre-filled on cashier checkout forms.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Invoice Footer Note</label>
                        <textarea name="settings[invoice_footer]" class="form-control" rows="2"><?= e($current_settings['invoice_footer'] ?? 'Thank you for visiting Elegance Salon!') ?></textarea>
                    </div>

                    <hr class="my-4">
                    <div class="fw-semibold text-dark mb-3"><i class="bi bi-geo-alt text-salon me-2"></i>Public Contact Page Details</div>
                    
                    <div class="mb-3">
                        <label class="form-label">Contact Page Address</label>
                        <input type="text" name="settings[contact_address]" class="form-control" value="<?= e($current_settings['contact_address'] ?? '') ?>">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contact Page Phone</label>
                            <input type="text" name="settings[contact_phone]" class="form-control" value="<?= e($current_settings['contact_phone'] ?? '') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contact Page Email</label>
                            <input type="email" name="settings[contact_email]" class="form-control" value="<?= e($current_settings['contact_email'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Email & SMTP Configuration -->
        <div class="col-12">
            <div class="card card-salon">
                <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <i class="bi bi-envelope-at text-salon me-2"></i>
                        <span class="fw-bold">Client Email Notifications (SMTP)</span>
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="smtp_enabled" name="settings[smtp_enabled]" value="1" <?= ($current_settings['smtp_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold text-dark" for="smtp_enabled">Enable Real Email Sending</label>
                    </div>
                </div>
                <div class="card-body">
                    <div class="alert alert-light border small text-muted mb-4">
                        <i class="bi bi-info-circle text-primary me-1"></i>
                        When enabled, appointment booking receipts, reschedules, confirmations, and cancellations will be delivered automatically to clients who have an email address recorded.
                    </div>

                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label">SMTP Host</label>
                            <input type="text" name="settings[smtp_host]" class="form-control" placeholder="e.g. smtp.gmail.com or sandbox.smtp.mailtrap.io" value="<?= e($current_settings['smtp_host'] ?? '') ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">SMTP Port</label>
                            <input type="number" name="settings[smtp_port]" class="form-control" placeholder="587" value="<?= e($current_settings['smtp_port'] ?? '587') ?>">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Encryption Protocol</label>
                            <select name="settings[smtp_secure]" class="form-select">
                                <option value="tls" <?= ($current_settings['smtp_secure'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS (Recommended for Port 587)</option>
                                <option value="ssl" <?= ($current_settings['smtp_secure'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL (Port 465)</option>
                                <option value="none" <?= ($current_settings['smtp_secure'] ?? '') === 'none' ? 'selected' : '' ?>>None / No Encryption (Local Port 25/1025)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">SMTP Username / Email</label>
                            <input type="text" name="settings[smtp_user]" class="form-control" placeholder="e.g. yoursalon@gmail.com" value="<?= e($current_settings['smtp_user'] ?? '') ?>" autocomplete="off">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">SMTP Password / App Password</label>
                            <input type="password" name="settings[smtp_pass]" class="form-control" placeholder="Account password or Google 16-character App Password" value="<?= e($current_settings['smtp_pass'] ?? '') ?>" autocomplete="new-password">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">From / Sender Email</label>
                            <input type="email" name="settings[smtp_from_email]" class="form-control <?= isset($errors['smtp_from_email']) ? 'is-invalid' : '' ?>" placeholder="e.g. appointments@elegancesalon.com (or leave empty for SMTP user)" value="<?= e($current_settings['smtp_from_email'] ?? '') ?>">
                            <?php if (isset($errors['smtp_from_email'])): ?><div class="invalid-feedback"><?= e($errors['smtp_from_email']) ?></div><?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">From / Sender Name</label>
                            <input type="text" name="settings[smtp_from_name]" class="form-control" placeholder="e.g. Elegance Salon" value="<?= e($current_settings['smtp_from_name'] ?? '') ?>">
                        </div>
                    </div>

                    <!-- Test Email Tool -->
                    <div class="border-top pt-3 mt-4">
                        <label class="form-label fw-bold text-dark"><i class="bi bi-send text-salon me-1"></i>Test SMTP Connection</label>
                        <div class="row g-2 align-items-center">
                            <div class="col-md-6">
                                <input type="email" name="test_email_recipient" class="form-control" placeholder="Enter test recipient email (e.g. your personal email)" value="<?= e($current_settings['salon_email'] ?? '') ?>">
                            </div>
                            <div class="col-md-auto">
                                <button type="submit" name="action" value="test_email" class="btn btn-outline-salon">
                                    <i class="bi bi-envelope-paper me-1"></i>Save & Send Test Email
                                </button>
                            </div>
                        </div>
                        <div class="form-text small text-muted mt-1">Saves all configuration on this page and sends a live test email to verify your SMTP connection.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-4 text-end">
        <button type="submit" name="action" value="save" class="btn btn-salon px-5 py-2"><i class="bi bi-check2-circle me-1"></i>Save Settings</button>
    </div>
</form>

<?php require_once __DIR__ . '/../../includes/dash_footer.php'; ?>
