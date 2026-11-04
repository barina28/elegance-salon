<?php
/**
 * ============================================================
 *  CLIENTS - LIST + SEARCH + PAGINATION
 *  Actions handled here (POST): activate/deactivate, delete.
 *  Access: admin + receptionist  (can_manage('clients'))
 * ============================================================
 */

require_once __DIR__ . '/../../includes/init.php';
require_role(array('admin', 'receptionist'));

$self = BASE_URL . 'modules/clients/';

/* ============================================================
 *  POST ACTIONS - delete / toggle (all guarded by CSRF)
 * ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = post('action');
    $id     = (int)post('client_id');

    if ($id <= 0 || !in_array($action, array('delete', 'toggle'), true)) {
        set_flash('warning', 'Unknown action.');
        redirect($self . 'index.php');
    }

    /* the client must exist */
    $stmt = mysqli_prepare($conn, "SELECT name, is_active FROM clients WHERE client_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $client = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$client) {
        set_flash('danger', 'Client not found (maybe already deleted).');
        redirect($self . 'index.php');
    }

    if ($action === 'toggle') {
        /* keep old clients archived instead of deleted */
        $new  = $client['is_active'] ? 0 : 1;
        $stmt = mysqli_prepare($conn, "UPDATE clients SET is_active = ? WHERE client_id = ?");
        mysqli_stmt_bind_param($stmt, 'ii', $new, $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        set_flash('success', $client['name'] . ' marked as ' . ($new ? 'active' : 'inactive') . '.');
    } else {
        /* delete is only allowed when the client has no appointment
           history - otherwise the foreign key would break reports */
        $stmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM appointments WHERE client_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $appts = (int)mysqli_fetch_row(mysqli_stmt_get_result($stmt))[0];
        mysqli_stmt_close($stmt);

        if ($appts > 0) {
            set_flash('warning', 'Cannot delete: this client has ' . $appts
                . ' appointment record(s). Deactivate the client instead to keep the history.');
        } else {
            $stmt = mysqli_prepare($conn, "DELETE FROM clients WHERE client_id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            set_flash('success', 'Client deleted.');
        }
    }
    redirect($self . 'index.php');
}

/* ============================================================
 *  LIST + SEARCH + PAGINATION (GET)
 * ========================================================== */
$q        = get('q');
$page     = max(1, (int)get('page', 1));
$per_page = 10;
$offset   = ($page - 1) * $per_page;

$where  = '';
$types  = '';
$params = array();
if ($q !== '') {
    $where  = "WHERE c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?";
    $like   = '%' . $q . '%';
    $types  = 'sss';
    $params = array($like, $like, $like);
}

/* total rows (for the pager) */
$stmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM clients c $where");
if ($types !== '') {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$total_rows = (int)mysqli_fetch_row(mysqli_stmt_get_result($stmt))[0];
mysqli_stmt_close($stmt);

$total_pages = max(1, (int)ceil($total_rows / $per_page));
if ($page > $total_pages) {
    $page   = $total_pages;
    $offset = ($page - 1) * $per_page;
}

/* rows: client + preferred stylist name + completed visits */
$sql = "SELECT c.*, st.name AS stylist_name,
               (SELECT COUNT(*) FROM appointments a
                 WHERE a.client_id = c.client_id AND a.status = 'completed') AS visits
        FROM clients c
        LEFT JOIN staff st ON st.staff_id = c.preferred_stylist_id
        $where
        ORDER BY c.is_active DESC, c.name ASC
        LIMIT ? OFFSET ?";
$types   .= 'ii';
$params[] = $per_page;
$params[] = $offset;

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$clients = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

/* query suffix so pager links keep the search term */
$qs = ($q !== '') ? '&amp;q=' . urlencode($q) : '';

/* pages to show in the pager (window around the current one) */
$pages_to_show = array();
for ($p = 1; $p <= $total_pages; $p++) {
    if ($total_pages <= 9 || $p <= 2 || $p > $total_pages - 2 || abs($p - $page) <= 1) {
        $pages_to_show[] = $p;
    }
}

$page_title = 'Clients';
require_once __DIR__ . '/../../includes/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h5 class="mb-1">Client directory</h5>
        <p class="text-muted small mb-0">Search, register and manage salon clients and their preferences.</p>
    </div>
    <a class="btn btn-salon" href="<?= $self ?>form.php"><i class="bi bi-person-plus"></i> Add client</a>
</div>

<div class="card card-salon">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <form class="d-flex search-bar" method="get" action="<?= $self ?>index.php">
            <input type="search" class="form-control" name="q" value="<?= e($q) ?>"
                   placeholder="Search name, phone or email...">
            <button class="btn btn-outline-salon ms-2" type="submit"><i class="bi bi-search"></i></button>
            <?php if ($q !== ''): ?>
                <a class="btn btn-link text-muted ms-1" href="<?= $self ?>index.php" title="Clear search"><i class="bi bi-x-lg"></i></a>
            <?php endif; ?>
        </form>
        <span class="badge text-bg-light border"><?= $total_rows ?> client<?= $total_rows == 1 ? '' : 's' ?></span>
    </div>

    <div class="card-body p-0">
        <?php if (!$clients): ?>
            <div class="p-5 text-center text-muted">
                <i class="bi bi-people fs-1 d-block mb-2"></i>
                <?php if ($q !== ''): ?>
                    No clients match <b>"<?= e($q) ?>"</b>.
                <?php else: ?>
                    No clients yet - click <b>Add client</b> to register the first one.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Client</th>
                            <th>Phone</th>
                            <th>Preferred stylist</th>
                            <th class="text-center">Visits</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($clients as $c): ?>
                        <tr class="<?= $c['is_active'] ? '' : 'table-light' ?>">
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="avatar-circle" style="width:40px;height:40px;flex:0 0 40px;font-size:1rem;">
                                        <?= e(mb_strtoupper(mb_substr($c['name'], 0, 1))) ?>
                                    </span>
                                    <div>
                                        <a class="fw-semibold text-decoration-none" href="<?= $self ?>view.php?id=<?= (int)$c['client_id'] ?>">
                                            <?= e($c['name']) ?>
                                        </a>
                                        <div class="small text-muted"><?= e($c['email'] !== null && $c['email'] !== '' ? $c['email'] : '-') ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="text-nowrap"><?= e($c['phone']) ?></td>
                            <td><?= e($c['stylist_name'] !== null ? $c['stylist_name'] : '-') ?></td>
                            <td class="text-center"><?= (int)$c['visits'] ?></td>
                            <td>
                                <?php if ($c['is_active']): ?>
                                    <span class="pill pill-confirmed">Active</span>
                                <?php else: ?>
                                    <span class="pill pill-cancelled">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-salon" href="<?= $self ?>view.php?id=<?= (int)$c['client_id'] ?>" title="View profile">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a class="btn btn-sm btn-outline-secondary" href="<?= $self ?>form.php?id=<?= (int)$c['client_id'] ?>" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>

                                <form class="d-inline" method="post" action="<?= $self ?>index.php"
                                      data-confirm="<?= $c['is_active'] ? 'Mark this client as inactive?' : 'Mark this client as active?' ?>">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="client_id" value="<?= (int)$c['client_id'] ?>">
                                    <button class="btn btn-sm btn-outline-warning" type="submit"
                                            title="<?= $c['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                        <i class="bi bi-<?= $c['is_active'] ? 'archive' : 'arrow-counterclockwise' ?>"></i>
                                    </button>
                                </form>

                                <form class="d-inline" method="post" action="<?= $self ?>index.php"
                                      data-confirm="Permanently delete this client? This cannot be undone.">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="client_id" value="<?= (int)$c['client_id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="card-footer bg-white d-flex justify-content-between align-items-center">
        <span class="small text-muted">Page <?= $page ?> of <?= $total_pages ?></span>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= $self ?>index.php?page=<?= ($page - 1) . $qs ?>">&laquo;</a>
                </li>
                <?php $prev = 0; foreach ($pages_to_show as $p): ?>
                    <?php if ($p - $prev > 1): ?>
                        <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                    <?php endif; ?>
                    <li class="page-item <?= $p == $page ? 'active' : '' ?>">
                        <a class="page-link" href="<?= $self ?>index.php?page=<?= $p . $qs ?>"><?= $p ?></a>
                    </li>
                <?php $prev = $p; endforeach; ?>
                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= $self ?>index.php?page=<?= ($page + 1) . $qs ?>">&raquo;</a>
                </li>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/dash_footer.php'; ?>
