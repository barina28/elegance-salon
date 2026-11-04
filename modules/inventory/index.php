<?php
/**
 * ============================================================
 *  INVENTORY - STOCK LIST (Step 8)
 *  Search + category filter + stock-level filter + pagination.
 *  Stats: total items, items needing reorder, out of stock,
 *  total stock value (quantity x cost price).
 *  POST actions here: delete item (blocked when POs exist),
 *  raise low-stock alerts (simulated email -> notifications).
 *  Access: admin + receptionist  (can_manage('inventory'))
 * ============================================================
 */

require_once __DIR__ . '/../../includes/init.php';
require_role(array('admin', 'receptionist'));

 $self = BASE_URL . 'modules/inventory/';

/** tiny helper: run a prepared query, return the first column */
function inv_scalar($sql, $types = '', $params = array()) {
    global $conn;
    $stmt = mysqli_prepare($conn, $sql);
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ? $row[0] : 0;
}

 $categories = array('Hair Products', 'Beauty Supplies', 'Nail Supplies',
                    'Equipment', 'Retail Products', 'Other');

/* ============================================================
 *  POST ACTIONS - delete / alerts (guarded by CSRF)
 * ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post('action');

    /* ---------- raise low-stock alerts (simulated email) ---------- */
    if ($action === 'alerts') {
        $res = mysqli_query($conn,
            "SELECT item_name, quantity, reorder_level, unit
             FROM inventory_items
             WHERE quantity <= reorder_level
             ORDER BY quantity ASC");
        $raised = 0;
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $state = $row['quantity'] <= 0 ? 'OUT OF STOCK' : 'running low';
            log_notification(array(
                'recipient' => get_setting('salon_email', 'manager@elegance.local'),
                'channel'   => 'email',
                'type'      => 'inventory',
                'subject'   => 'Low stock: ' . $row['item_name'],
                'message'   => $row['item_name'] . ' is ' . $state . ".\n" .
                               'In stock: ' . rtrim(rtrim($row['quantity'], '0'), '.') . ' ' . $row['unit'] .
                               ' (reorder at ' . rtrim(rtrim($row['reorder_level'], '0'), '.') . ' ' . $row['unit'] . ").\n" .
                               'Reorder it under Inventory > Purchase orders ("Auto-generate").',
                'status'    => 'pending',
            ));
            $raised++;
        }
        if ($raised > 0) {
            set_flash('success', $raised . ' low-stock alert(s) queued in the notifications log.');
        } else {
            set_flash('info', 'Nothing to alert - every item is above its reorder level.');
        }
        redirect($self . 'index.php');
    }

    /* ---------- delete an item ---------- */
    if ($action === 'delete') {
        $id = (int)post('item_id');

        $stmt = mysqli_prepare($conn, "SELECT item_name FROM inventory_items WHERE item_id = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $item = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$item) {
            set_flash('danger', 'Item not found (maybe already deleted).');
            redirect($self . 'index.php');
        }

        /* purchase orders reference items with a restricting FK,
           so the row cannot go while orders exist */
        $pos = (int)inv_scalar(
            "SELECT COUNT(*) FROM purchase_orders WHERE item_id = ?", 'i', array($id));
        if ($pos > 0) {
            set_flash('warning', 'Cannot delete ' . $item['item_name'] . ': ' . $pos .
                ' purchase order(s) reference it. Delete or cancel those orders first.');
        } else {
            $stmt = mysqli_prepare($conn, "DELETE FROM inventory_items WHERE item_id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            set_flash('success', $item['item_name'] . ' removed from inventory.');
        }
        redirect($self . 'index.php');
    }

    set_flash('warning', 'Unknown action.');
    redirect($self . 'index.php');
}

/* ============================================================
 *  LIST + SEARCH + FILTERS + PAGINATION (GET)
 * ========================================================== */
 $q     = get('q');
 $cat_f = get('cat');
if (!in_array($cat_f, $categories, true)) {
    $cat_f = '';
}
 $level_f  = get('level');
if (!in_array($level_f, array('reorder', 'out'), true)) {
    $level_f = '';
}
 $page     = max(1, (int)get('page', 1));
 $per_page = 10;
 $offset   = ($page - 1) * $per_page;

 $conds  = array();
 $types  = '';
 $params = array();
if ($q !== '') {
    $conds[]  = '(i.item_name LIKE ? OR i.sku LIKE ?)';
    $like     = '%' . $q . '%';
    $types   .= 'ss';
    $params[] = $like; $params[] = $like;
}
if ($cat_f !== '') {
    $conds[]  = 'i.category = ?';
    $types   .= 's';
    $params[] = $cat_f;
}
if ($level_f === 'reorder') {
    $conds[] = 'i.quantity <= i.reorder_level';
} elseif ($level_f === 'out') {
    $conds[] = 'i.quantity <= 0';
}
 $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

/* ---------- header stats (unfiltered) ---------- */
 $stat_total   = (int)inv_scalar("SELECT COUNT(*) FROM inventory_items");
 $stat_reorder = (int)inv_scalar("SELECT COUNT(*) FROM inventory_items WHERE quantity <= reorder_level");
 $stat_out     = (int)inv_scalar("SELECT COUNT(*) FROM inventory_items WHERE quantity <= 0");
 $stat_value   = (float)inv_scalar("SELECT COALESCE(SUM(quantity * cost_price), 0) FROM inventory_items");

/* ---------- total rows for the pager ---------- */
 $stmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM inventory_items i $where");
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

/* ---------- rows: items + supplier name ---------- */
 $sql = "SELECT i.*, sp.name AS supplier_name
        FROM inventory_items i
        LEFT JOIN suppliers sp ON sp.supplier_id = i.supplier_id
        $where
        ORDER BY (i.quantity <= i.reorder_level) DESC, i.quantity ASC, i.item_name ASC
        LIMIT ? OFFSET ?";
 $types   .= 'ii';
 $params[] = $per_page;
 $params[] = $offset;

 $stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
 $items = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

/* keep search + filters in the pager links */
 $qs = '';
if ($q !== '')       { $qs .= '&amp;q=' . urlencode($q); }
if ($cat_f !== '')   { $qs .= '&amp;cat=' . urlencode($cat_f); }
if ($level_f !== '') { $qs .= '&amp;level=' . urlencode($level_f); }

/* pages to show in the pager (window around the current one) */
 $pages_to_show = array();
for ($p = 1; $p <= $total_pages; $p++) {
    if ($total_pages <= 9 || $p <= 2 || $p > $total_pages - 2 || abs($p - $page) <= 1) {
        $pages_to_show[] = $p;
    }
}

/** stock state: 'out' (red) / 'low' (amber) / 'ok' (green) */
function stock_state($qty, $reorder) {
    if ($qty <= 0)                { return 'out'; }
    if ($qty <= $reorder)         { return 'low'; }
    return 'ok';
}
 $state_pill = array('ok' => 'pill-active', 'low' => 'pill-on_leave', 'out' => 'pill-cancelled');
 $state_lbl  = array('ok' => 'In stock', 'low' => 'Low stock', 'out' => 'Out of stock');

 $page_title = 'Inventory';
require_once __DIR__ . '/../../includes/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h5 class="mb-1"><i class="bi bi-box-seam"></i> Inventory</h5>
        <p class="text-muted small mb-0">
            Salon supplies and retail stock. Items at or below their reorder level
            are highlighted first.
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <form class="d-inline" method="post" action="<?= $self ?>index.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="alerts">
            <button class="btn btn-outline-warning" type="submit" title="Queue an email alert for every item at or below its reorder level">
                <i class="bi bi-bell"></i> Raise low-stock alerts
            </button>
        </form>
        <a class="btn btn-outline-salon" href="<?= $self ?>suppliers.php"><i class="bi bi-truck"></i> Suppliers</a>
        <a class="btn btn-outline-salon" href="<?= $self ?>purchase_orders.php"><i class="bi bi-cart3"></i> Purchase orders</a>
        <a class="btn btn-salon" href="<?= $self ?>form.php"><i class="bi bi-plus-lg"></i> Add item</a>
    </div>
</div>

<!-- ---------------- stat cards ---------------- -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <span class="stat-icon"><i class="bi bi-stack"></i></span>
            <div class="stat-label mt-2">Items tracked</div>
            <div class="stat-value"><?= $stat_total ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <span class="stat-icon"><i class="bi bi-exclamation-triangle"></i></span>
            <div class="stat-label mt-2">Need reorder</div>
            <div class="stat-value"><?= $stat_reorder ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <span class="stat-icon"><i class="bi bi-x-octagon"></i></span>
            <div class="stat-label mt-2">Out of stock</div>
            <div class="stat-value"><?= $stat_out ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <span class="stat-icon"><i class="bi bi-cash-coin"></i></span>
            <div class="stat-label mt-2">Stock value (cost)</div>
            <div class="stat-value"><?= money($stat_value) ?></div>
        </div>
    </div>
</div>

<div class="card card-salon">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <form class="d-flex search-bar" method="get" action="<?= $self ?>index.php">
            <input type="search" class="form-control" name="q" value="<?= e($q) ?>"
                   placeholder="Search item name or SKU...">
            <button class="btn btn-outline-salon ms-2" type="submit"><i class="bi bi-search"></i></button>
            <?php if ($q !== '' || $cat_f !== '' || $level_f !== ''): ?>
                <a class="btn btn-link text-muted ms-1" href="<?= $self ?>index.php" title="Clear filters"><i class="bi bi-x-lg"></i></a>
            <?php endif; ?>
        </form>
        <div class="d-flex align-items-center gap-2">
            <form method="get" action="<?= $self ?>index.php" class="d-flex align-items-center gap-2">
                <?php if ($q !== ''): ?>
                    <input type="hidden" name="q" value="<?= e($q) ?>">
                <?php endif; ?>
                <?php if ($level_f !== ''): ?>
                    <input type="hidden" name="level" value="<?= e($level_f) ?>">
                <?php endif; ?>
                <select name="cat" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto;">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= e($c) ?>" <?= $cat_f === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <form method="get" action="<?= $self ?>index.php" class="d-flex align-items-center gap-2">
                <?php if ($q !== ''): ?>
                    <input type="hidden" name="q" value="<?= e($q) ?>">
                <?php endif; ?>
                <?php if ($cat_f !== ''): ?>
                    <input type="hidden" name="cat" value="<?= e($cat_f) ?>">
                <?php endif; ?>
                <select name="level" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto;">
                    <option value="">All levels</option>
                    <option value="reorder" <?= $level_f === 'reorder' ? 'selected' : '' ?>>Need reorder</option>
                    <option value="out" <?= $level_f === 'out' ? 'selected' : '' ?>>Out of stock</option>
                </select>
            </form>
            <span class="badge text-bg-light border"><?= $total_rows ?> item(s)</span>
        </div>
    </div>

    <div class="card-body p-0">
        <?php if (!$items): ?>
            <div class="p-5 text-center text-muted">
                <i class="bi bi-box-seam fs-1 d-block mb-2"></i>
                <?php if ($q !== '' || $cat_f !== '' || $level_f !== ''): ?>
                    No items match your filters.
                <?php else: ?>
                    No inventory items yet - click <b>Add item</b> to track the first product.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Supplier</th>
                            <th class="text-center">Stock</th>
                            <th class="text-center">Reorder at</th>
                            <th class="text-end">Cost</th>
                            <th class="text-end">Stock value</th>
                            <th>Last restocked</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $it):
                        $state = stock_state((float)$it['quantity'], (float)$it['reorder_level']); ?>
                        <tr class="<?= $state === 'out' ? 'table-light' : '' ?>">
                            <td>
                                <a class="fw-semibold text-decoration-none" href="<?= $self ?>restock.php?id=<?= (int)$it['item_id'] ?>">
                                    <?= e($it['item_name']) ?>
                                </a>
                                <div class="small text-muted">
                                    <?= e($it['category']) ?>
                                    <?php if ($it['sku'] !== null && $it['sku'] !== ''): ?>
                                        &middot; SKU <?= e($it['sku']) ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="small">
                                <?= $it['supplier_name'] !== null ? e($it['supplier_name']) : '<span class="text-muted">no supplier</span>' ?>
                            </td>
                            <td class="text-center text-nowrap">
                                <b><?= e(rtrim(rtrim($it['quantity'], '0'), '.')) ?></b>
                                <?= e($it['unit']) ?>
                                <div><span class="pill <?= $state_pill[$state] ?>"><?= $state_lbl[$state] ?></span></div>
                            </td>
                            <td class="text-center"><?= e(rtrim(rtrim($it['reorder_level'], '0'), '.')) ?></td>
                            <td class="text-end"><?= money($it['cost_price']) ?></td>
                            <td class="text-end"><?= money((float)$it['quantity'] * (float)$it['cost_price']) ?></td>
                            <td class="small text-nowrap">
                                <?= $it['last_restocked'] !== null ? e(date('d M Y', strtotime($it['last_restocked']))) : '<span class="text-muted">never</span>' ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-success" href="<?= $self ?>restock.php?id=<?= (int)$it['item_id'] ?>" title="Stock in / out">
                                    <i class="bi bi-box-arrow-in-up"></i>
                                </a>
                                <a class="btn btn-sm btn-outline-secondary" href="<?= $self ?>form.php?id=<?= (int)$it['item_id'] ?>" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form class="d-inline" method="post" action="<?= $self ?>index.php"
                                      data-confirm="Remove <?= e($it['item_name']) ?> from inventory? This cannot be undone.">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="item_id" value="<?= (int)$it['item_id'] ?>">
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

<p class="text-muted small mt-3 mb-0">
    <i class="bi bi-lightbulb"></i> <b>Stock in / out</b> adjusts quantities by hand;
    receiving a <a href="<?= $self ?>purchase_orders.php">purchase order</a> adds stock automatically.
    Alerts and auto-orders keep the shelf filled - see
    <a href="<?= $self ?>suppliers.php">Suppliers</a> for who to buy from.
</p>

<?php require_once __DIR__ . '/../../includes/dash_footer.php'; ?>