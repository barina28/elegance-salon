<?php
/**
 * ============================================================
 *  ADMIN - SERVICES MANAGEMENT (CRUD)
 *  Add, edit, toggle, and delete salon services with pricing,
 *  category, duration, and stylist commission rates.
 *  Access: admin only.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/init.php';
require_role(array('admin'));

$self = BASE_URL . 'modules/admin/services.php';
$errors = array();

// Whitelist of valid service categories
$categories = array('Hair', 'Nails', 'Facial', 'Makeup', 'Spa', 'Other');

/* ============================================================
 *  HANDLE POST ACTIONS (Add, Edit, Toggle, Delete)
 * ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = post('action');

    // 1. ADD NEW SERVICE
    if ($action === 'add') {
        $name        = post('name');
        $category    = post('category');
        $price       = (float)post('price');
        $duration    = (int)post('duration_minutes');
        $commission  = post('commission_rate') !== '' ? (float)post('commission_rate') : 10.00;
        $description = post('description');

        if (mb_strlen($name) < 2) {
            $errors[] = 'Service name must be at least 2 characters.';
        }
        if (!in_array($category, $categories, true)) {
            $errors[] = 'Please select a valid service category.';
        }
        if ($price <= 0) {
            $errors[] = 'Price must be greater than zero.';
        }
        if ($duration < 5 || $duration > 480) {
            $errors[] = 'Duration must be between 5 and 480 minutes.';
        }

        if (empty($errors)) {
            $stmt = mysqli_prepare($conn,
                "INSERT INTO services (name, category, description, duration_minutes, price, commission_rate, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, 1)");
            mysqli_stmt_bind_param($stmt, 'sssidd', $name, $category, $description, $duration, $price, $commission);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $_SESSION['flash_success'] = "Service '{$name}' added successfully.";
            redirect($self);
        }
    }

    // 2. EDIT SERVICE
    elseif ($action === 'edit') {
        $id          = (int)post('service_id');
        $name        = post('name');
        $category    = post('category');
        $price       = (float)post('price');
        $duration    = (int)post('duration_minutes');
        $commission  = post('commission_rate') !== '' ? (float)post('commission_rate') : 10.00;
        $description = post('description');

        if ($id <= 0) {
            $errors[] = 'Invalid service ID.';
        }
        if (mb_strlen($name) < 2) {
            $errors[] = 'Service name must be at least 2 characters.';
        }
        if (!in_array($category, $categories, true)) {
            $errors[] = 'Please select a valid service category.';
        }
        if ($price <= 0) {
            $errors[] = 'Price must be greater than zero.';
        }
        if ($duration < 5 || $duration > 480) {
            $errors[] = 'Duration must be between 5 and 480 minutes.';
        }

        if (empty($errors)) {
            $stmt = mysqli_prepare($conn,
                "UPDATE services
                 SET name = ?, category = ?, description = ?, duration_minutes = ?, price = ?, commission_rate = ?
                 WHERE service_id = ?");
            mysqli_stmt_bind_param($stmt, 'sssiddi', $name, $category, $description, $duration, $price, $commission, $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $_SESSION['flash_success'] = "Service '{$name}' updated successfully.";
            redirect($self);
        }
    }

    // 3. TOGGLE ACTIVE/INACTIVE
    elseif ($action === 'toggle') {
        $id = (int)post('service_id');
        if ($id > 0) {
            $stmt = mysqli_prepare($conn, "SELECT name, is_active FROM services WHERE service_id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            $svc = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);

            if ($svc) {
                $new_status = $svc['is_active'] ? 0 : 1;
                $stmt = mysqli_prepare($conn, "UPDATE services SET is_active = ? WHERE service_id = ?");
                mysqli_stmt_bind_param($stmt, 'ii', $new_status, $id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                $_SESSION['flash_success'] = "Service '{$svc['name']}' is now " . ($new_status ? 'Active' : 'Inactive') . ".";
            }
        }
        redirect($self);
    }

    // 4. DELETE SERVICE
    elseif ($action === 'delete') {
        $id = (int)post('service_id');
        if ($id > 0) {
            $stmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM appointments WHERE service_id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            $appt_count = (int)mysqli_fetch_row(mysqli_stmt_get_result($stmt))[0];
            mysqli_stmt_close($stmt);

            if ($appt_count > 0) {
                $_SESSION['flash_error'] = "Cannot delete: this service is used in {$appt_count} appointment(s). You can deactivate it instead.";
            } else {
                $stmt = mysqli_prepare($conn, "DELETE FROM services WHERE service_id = ?");
                mysqli_stmt_bind_param($stmt, 'i', $id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                $_SESSION['flash_success'] = "Service deleted successfully.";
            }
        }
        redirect($self);
    }
}

/* ============================================================
 *  FETCH SERVICES WITH OPTIONAL FILTER
 * ========================================================== */
$filter_cat = get('category');
$search_q   = get('q');

$where_clauses = array();
$params = array();
$types  = '';

if ($filter_cat !== '' && in_array($filter_cat, $categories, true)) {
    $where_clauses[] = "category = ?";
    $params[] = $filter_cat;
    $types   .= 's';
}
if ($search_q !== '') {
    $where_clauses[] = "(name LIKE ? OR description LIKE ?)";
    $like = '%' . $search_q . '%';
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ss';
}

$where_sql = $where_clauses ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$stmt = mysqli_prepare($conn, "SELECT * FROM services {$where_sql} ORDER BY FIELD(category,'Hair','Nails','Facial','Makeup','Spa','Other'), price ASC");
if ($types !== '') {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$services_list = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$page_title = 'Service Management';
require_once __DIR__ . '/../../includes/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1 fw-bold text-dark"><i class="bi bi-scissors text-salon me-2"></i>Service Management</h1>
        <p class="text-muted small mb-0">Manage salon treatments, categories, pricing, service durations, and commissions.</p>
    </div>
    <button class="btn btn-salon" data-bs-toggle="modal" data-bs-target="#addServiceModal">
        <i class="bi bi-plus-circle me-1"></i> Add New Service
    </button>
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

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Search and Filter Bar -->
<div class="card card-salon mb-4">
    <div class="card-body p-3">
        <form method="get" action="" class="row g-2 align-items-center">
            <div class="col-md-5">
                <input type="text" name="q" class="form-control" placeholder="Search service name or description..." value="<?= e($search_q) ?>">
            </div>
            <div class="col-md-4">
                <select name="category" class="form-select">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= e($cat) ?>" <?= $filter_cat === $cat ? 'selected' : '' ?>><?= e($cat) ?> Services</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-outline-salon w-100"><i class="bi bi-search me-1"></i> Filter</button>
                <?php if ($search_q !== '' || $filter_cat !== ''): ?>
                    <a href="<?= $self ?>" class="btn btn-outline-secondary" title="Clear Filters"><i class="bi bi-x-circle"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Services Table -->
<div class="card card-salon">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-bold"><i class="bi bi-list-stars text-salon me-2"></i>Active Price List</span>
        <span class="badge bg-light text-dark border"><?= count($services_list) ?> services</span>
    </div>
    <?php if (empty($services_list)): ?>
        <div class="card-body text-center py-5 text-muted">
            <i class="bi bi-scissors fs-1 d-block mb-2 text-muted"></i>
            No services match the selected filter.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light small">
                    <tr>
                        <th>Service</th>
                        <th>Category</th>
                        <th>Duration</th>
                        <th>Price</th>
                        <th>Stylist Commission</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($services_list as $s): ?>
                        <tr class="<?= $s['is_active'] ? '' : 'table-light text-muted' ?>">
                            <td>
                                <div class="fw-bold text-dark"><?= e($s['name']) ?></div>
                                <div class="small text-muted"><?= e($s['description'] ?: 'No description provided') ?></div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border"><?= e($s['category']) ?></span>
                            </td>
                            <td>
                                <span class="small"><i class="bi bi-clock me-1"></i><?= (int)$s['duration_minutes'] ?> min</span>
                            </td>
                            <td>
                                <span class="fw-bold text-dark"><?= money($s['price']) ?></span>
                            </td>
                            <td>
                                <span class="small text-muted"><?= number_format((float)$s['commission_rate'], 1) ?>%</span>
                            </td>
                            <td>
                                <span class="pill pill-<?= $s['is_active'] ? 'confirmed' : 'cancelled' ?>">
                                    <?= $s['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td class="text-end text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-salon" data-bs-toggle="modal" data-bs-target="#editModal<?= (int)$s['service_id'] ?>" title="Edit Service">
                                    <i class="bi bi-pencil"></i>
                                </button>

                                <form method="post" action="" class="d-inline" data-confirm="<?= $s['is_active'] ? 'Deactivate this service? It will no longer appear on the booking menu.' : 'Reactivate this service?' ?>">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="service_id" value="<?= (int)$s['service_id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="Toggle Active">
                                        <i class="bi bi-power"></i>
                                    </button>
                                </form>

                                <form method="post" action="" class="d-inline" data-confirm="Are you sure you want to permanently delete this service?">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="service_id" value="<?= (int)$s['service_id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>

                        <!-- Edit Service Modal for this item -->
                        <div class="modal fade" id="editModal<?= (int)$s['service_id'] ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="post" action="">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="edit">
                                        <input type="hidden" name="service_id" value="<?= (int)$s['service_id'] ?>">

                                        <div class="modal-header">
                                            <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square text-salon me-2"></i>Edit Service</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">Service Name</label>
                                                <input type="text" name="name" class="form-control" required value="<?= e($s['name']) ?>">
                                            </div>
                                            <div class="row g-2 mb-3">
                                                <div class="col-md-6">
                                                    <label class="form-label fw-semibold">Category</label>
                                                    <select name="category" class="form-select" required>
                                                        <?php foreach ($categories as $cat): ?>
                                                            <option value="<?= e($cat) ?>" <?= $s['category'] === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label fw-semibold">Duration (Minutes)</label>
                                                    <input type="number" name="duration_minutes" class="form-control" min="5" max="480" step="5" required value="<?= (int)$s['duration_minutes'] ?>">
                                                </div>
                                            </div>
                                            <div class="row g-2 mb-3">
                                                <div class="col-md-6">
                                                    <label class="form-label fw-semibold">Price (<?= get_setting('currency_symbol', 'Rs.') ?>)</label>
                                                    <input type="number" name="price" class="form-control" min="0" step="10" required value="<?= (float)$s['price'] ?>">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label fw-semibold">Stylist Commission (%)</label>
                                                    <input type="number" name="commission_rate" class="form-control" min="0" max="100" step="0.5" value="<?= (float)$s['commission_rate'] ?>">
                                                </div>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">Description</label>
                                                <textarea name="description" class="form-control" rows="2"><?= e($s['description']) ?></textarea>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-salon">Save Changes</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Add Service Modal -->
<div class="modal fade" id="addServiceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="add">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle text-salon me-2"></i>Add New Service</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Service Name</label>
                        <input type="text" name="name" class="form-control" required placeholder="e.g. Keratin Hair Treatment">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Category</label>
                            <select name="category" class="form-select" required>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= e($cat) ?>"><?= e($cat) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Duration (Minutes)</label>
                            <input type="number" name="duration_minutes" class="form-control" min="5" max="480" step="5" required value="45">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Price (<?= get_setting('currency_symbol', 'Rs.') ?>)</label>
                            <input type="number" name="price" class="form-control" min="0" step="10" required placeholder="1000">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Stylist Commission (%)</label>
                            <input type="number" name="commission_rate" class="form-control" min="0" max="100" step="0.5" value="10.0">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="Brief details about this salon treatment..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-salon"><i class="bi bi-check2 me-1"></i>Create Service</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/dash_footer.php'; ?>

