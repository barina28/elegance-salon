<?php
/**
 * ============================================================
 *  INVENTORY - PURCHASE ORDERS (Step 8)
 *  Workflow:  pending -> ordered -> received   (or cancelled)
 *    - "Auto-generate" scans items at/below their reorder
 *      level that have NO open PO yet, and creates one
 *      pending PO each (top-up to 2x reorder level, unit cost
 *      from the item). Items without a supplier are reported.
 *    - "Mark ordered" stamps order_date.
 *    - "Receive" adds the PO quantity to stock and stamps
 *      Last restocked (order_date defaults to today if the PO
 *      was never marked ordered).
 *  Access: admin + receptionist  (can_manage('inventory'))
 * ============================================================
 */

require_once __DIR__ . '/../../includes/init.php';
require_role(array('admin', 'receptionist'));

 $self = BASE_URL . 'modules/inventory/purchase_orders.php';

/** tiny helper: run a prepared query, return the first column */
function po_scalar($sql, $types = '', $params = array()) {
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

/** next free PO number for today: PO-YYYYMMDD-001, -002 ... */
function po_next_number() {
    global $conn;
    $prefix = 'PO-' . date('Ymd') . '-';
    $like   = $prefix . '%';
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM purchase_orders WHERE po_number LIKE ?");
    mysqli_stmt_bind_param($stmt, 's', $like);
    mysqli_stmt_execute($stmt);
    $seq = (int)mysqli_fetch_row(mysqli_stmt_get_result($stmt))[0];
    mysqli_stmt_close($stmt);
    return $prefix . str_pad((string)($seq + 1), 3, '0', STR_PAD_LEFT);
}

/* ============================================================
 *  POST ACTIONS (guarded by CSRF)
 * ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post('action');

    /* ---------- AUTO-GENERATE for low-stock items ---------- */
    if ($action === 'auto') {
        /* items at/below reorder level, with a supplier, without an open PO */
        $res = mysqli_query($conn,
            "SELECT i.item_id, i.item_name, i.quantity, i.reorder_level, i.unit, i.cost_price,
                    i.supplier_id, sp.name AS supplier_name
             FROM inventory_items i
             JOIN suppliers sp ON sp.supplier_id = i.supplier_id
             WHERE i.quantity <= i.reorder_level
               AND NOT EXISTS (SELECT 1 FROM purchase_orders po
                                WHERE po.item_id = i.item_id
                                  AND po.status IN ('pending','ordered'))
             ORDER BY (i.quantity <= 0) DESC, i.item_name ASC");

        $created = 0;
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            /* top up to twice the reorder level (at least 1) */
            $suggest = max(1, round((float)$row['reorder_level'] * 2 - (float)$row['quantity'], 2));
            $cost    = (float)$row['cost_price'];
            $total   = round($suggest * $cost, 2);
            $po_no   = po_next_number();
            $uid     = (int)$_SESSION['user_id'];

            $stmt = mysqli_prepare($conn,
                "INSERT INTO purchase_orders
                    (po_number, supplier_id, item_id, quantity, unit_cost, total_cost, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)");
            mysqli_stmt_bind_param($stmt, 'siiddii',
                $po_no, $row['supplier_id'], $row['item_id'], $suggest, $cost, $total, $uid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $created++;
        }

        /* explain what was skipped */
        $no_supplier = (int)po_scalar(
            "SELECT COUNT(*) FROM inventory_items
             WHERE quantity <= reorder_level AND supplier_id IS NULL");

        if ($created > 0) {
            log_notification(array(
                'recipient' => get_setting('salon_email', 'manager@elegance.local'),
                'channel'   => 'email',
                'type'      => 'inventory',
                'subject'   => 'Auto purchase orders created',
                'message'   => $created . " purchase order(s) were generated for low-stock items.\n" .
                               "They start as PENDING - review the suggested quantities, then mark them ordered.",
                'status'    => 'pending',
            ));
            set_flash('success', $created . ' purchase order(s) created automatically' .
                ($no_supplier > 0 ? ' - see the warning below.' : '.'));
        } else {
            set_flash('info', 'Nothing to order - every low-stock item already has an open purchase order.');
        }
        if ($no_supplier > 0) {
            set_flash('warning', $no_supplier . ' low-stock item(s) have NO supplier, so no order could be raised. Assign suppliers under Inventory > Add item.');
        }
        redirect($self);
    }

    /* ---------- MARK ORDERED ---------- */
    if ($action === 'mark_ordered') {
        $po_id = (int)post('po_id');
        $stmt = mysqli_prepare($conn,
            "UPDATE purchase_orders SET status = 'ordered', order_date = CURDATE()
             WHERE po_id = ? AND status = 'pending'");
        mysqli_stmt_bind_param($stmt, 'i', $po_id);
        mysqli_stmt_execute($stmt);
        $done = mysqli_stmt_affected_rows($stmt) > 0;
        mysqli_stmt_close($stmt);

        set_flash($done ? 'success' : 'warning',
            $done ? 'Purchase order marked as ordered.' : 'Only pending orders can be marked ordered.');
        redirect($self);
    }

    /* ---------- RECEIVE (adds stock) ---------- */
    if ($action === 'receive') {
        $po_id = (int)post('po_id');

        $stmt = mysqli_prepare($conn,
            "SELECT po.po_number, po.item_id, po.quantity, po.status, po.order_date,
                    i.item_name, i.quantity AS item_qty, i.reorder_level
             FROM purchase_orders po
             JOIN inventory_items i ON i.item_id = po.item_id
             WHERE po.po_id = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'i', $po_id);
        mysqli_stmt_execute($stmt);
        $po = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$po || !in_array($po['status'], array('pending', 'ordered'), true)) {
            set_flash('warning', 'Only pending or ordered orders can be received.');
            redirect($self);
        }

        $new_qty = round((float)$po['item_qty'] + (float)$po['quantity'], 2);

        $stmt = mysqli_prepare($conn,
            "UPDATE purchase_orders
             SET status = 'received', received_date = CURDATE(), order_date = COALESCE(order_date, CURDATE())
             WHERE po_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $po_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $stmt = mysqli_prepare($conn,
            "UPDATE inventory_items SET quantity = ?, last_restocked = CURDATE() WHERE item_id = ?");
        mysqli_stmt_bind_param($stmt, 'di', $new_qty, $po['item_id']);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        set_flash('success', $po['po_number'] . ' received - ' .
            rtrim(rtrim($po['quantity'], '0'), '.') . ' added to ' . $po['item_name'] .
            ' (now ' . rtrim(rtrim($new_qty, '0'), '.') . ').');
        redirect($self);
    }

    /* ---------- CANCEL ---------- */
    if ($action === 'cancel') {
        $po_id = (int)post('po_id');
        $stmt = mysqli_prepare($conn,
            "UPDATE purchase_orders SET status = 'cancelled'
             WHERE po_id = ? AND status IN ('pending','ordered')");
        mysqli_stmt_bind_param($stmt, 'i', $po_id);
        mysqli_stmt_execute($stmt);
        $done = mysqli_stmt_affected_rows($stmt) > 0;
        mysqli_stmt_close($stmt);

        set_flash($done ? 'success' : 'warning',
            $done ? 'Purchase order cancelled.' : 'Only pending or ordered orders can be cancelled.');
        redirect($self);
    }

    set_flash('warning', 'Unknown action.');
    redirect($self);
}

/* ============================================================
 *  LIST + STATUS FILTER + PAGINATION (GET)
 * ========================================================== */
 $status_f = get('status');
if (!in_array($status_f, array('pending', 'ordered', 'received', 'cancelled'), true)) {
    $status_f = '';
}
 $page     = max(1, (int)get('page', 1));
 $per_page = 10;
 $offset   = ($page - 1) * $per_page;

 $conds  = array();
 $types  = '';
 $params = array();
if ($status_f !== '') {
    $conds[]  = 'po.status = ?';
    $types   .= 's';
    $params[] = $status_f;
}
 $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

/* ---------- header stats ---------- */
 $stat_open      = (int)po_scalar("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('pending','ordered')");
 $stat_received  = (int)po_scalar("SELECT COUNT(*) FROM purchase_orders WHERE status = 'received'");
 $stat_eligible  = (int)po_scalar(
    "SELECT COUNT(*) FROM inventory_items i
     WHERE i.quantity <= i.reorder_level
       AND i.supplier_id IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM purchase_orders po
                        WHERE po.item_id = i.item_id
                          AND po.status IN ('pending','ordered'))");

/* ---------- total rows for the pager ---------- */
 $stmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM purchase_orders po $where");
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

/* ---------- rows ---------- */
 $sql = "SELECT po.*, i.item_name, i.sku, i.unit, sp.name AS supplier_name
        FROM purchase_orders po
        JOIN inventory_items i ON i.item_id  = po.item_id
        JOIN suppliers sp      ON sp.supplier_id = po.supplier_id
        $where
        ORDER BY FIELD(po.status, 'pending', 'ordered', 'received', 'cancelled'), po.po_id DESC
        LIMIT ? OFFSET ?";
 $types   .= 'ii';
 $params[] = $per_page;
 $params[] = $offset;

 $stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
 $orders = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

 $qs = '';
if ($status_f !== '') { $qs .= '&amp;status=' . urlencode($status_f); }

 $pages_to_show = array();
for ($p = 1; $p <= $total_pages; $p++) {
    if ($total_pages <= 9 || $p <= 2 || $p > $total_pages - 2 || abs($p - $page) <= 1) {
        $pages_to_show[] = $p;
    }
}

 $po_pill = array(
    'pending'   => 'pill-on_leave',
    'ordered'   => 'pill-scheduled',
    'received'  => 'pill-active',
    'cancelled' => 'pill-cancelled',
);

 $page_title = 'Purchase orders';
require_once __DIR__ . '/../../includes/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h5 class="mb-1"><i class="bi bi-cart3"></i> Purchase orders</h5>
        <p class="text-muted small mb-0">
            Restock trail for low inventory: pending &rarr; ordered &rarr; received.
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <form class="d-inline" method="post" action="<?= $self ?>"
              data-confirm="Scan low-stock items and create one pending purchase order for each item that has no open order?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="auto">
            <button class="btn btn-outline-warning" type="submit" title="Create pending orders for every low-stock item without an open PO">
                <i class="bi bi-magic"></i> Auto-generate
            </button>
        </form>
        <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>modules/inventory/index.php">
            <i class="bi bi-arrow-left"></i> Inventory
        </a>
    </div>
</div>

<!-- ---------------- stat cards ---------------- -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <span class="stat-icon"><i class="bi bi-hourglass-split"></i></span>
            <div class="stat-label mt-2">Open orders</div>
            <div class="stat-value"><?= $stat_open ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <span class="stat-icon"><i class="bi bi-check2-all"></i></span>
            <div class="stat-label mt-2">Received (all time)</div>
            <div class="stat-value"><?= $stat_received ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <span class="stat-icon"><i class="bi bi-magic"></i></span>
            <div class="stat-label mt-2">Ready for auto-order</div>
            <div class="stat-value"><?= $stat_eligible ?></div>
        </div>
    </div>
</div>

<div class="card card-salon">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span class="fw-semibold">All orders</span>
        <form method="get" action="<?= $self ?>" class="d-flex align-items-center gap-2">
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto;">
                <option value="">All statuses</option>
                <option value="pending"   <?= $status_f === 'pending'   ? 'selected' : '' ?>>Pending</option>
                <option value="ordered"   <?= $status_f === 'ordered'   ? 'selected' : '' ?>>Ordered</option>
                <option value="received"  <?= $status_f === 'received'  ? 'selected' : '' ?>>Received</option>
                <option value="cancelled" <?= $status_f === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
            </select>
        </form>
        <span class="badge text-bg-light border"><?= $total_rows ?> order(s)</span>
    </div>

    <div class="card-body p-0">
        <?php if (!$orders): ?>
            <div class="p-5 text-center text-muted">
                <i class="bi bi-cart-x fs-1 d-block mb-2"></i>
                <?php if ($status_f !== ''): ?>
                    No purchase orders with this status.
                <?php else: ?>
                    No purchase orders yet - click <b>Auto-generate</b> when stock runs low,
                    or adjust stock on the item's <i>stock in / out</i> page.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>PO #</th>
                            <th>Item</th>
                            <th>Supplier</th>
                            <th class="text-center">Qty</th>
                            <th class="text-end">Total</th>
                            <th>Status</th>
                            <th>Dates</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($orders as $po): ?>
                        <tr class="<?= $po['status'] === 'cancelled' ? 'table-light' : '' ?>">
                            <td class="fw-semibold"><?= e($po['po_number']) ?></td>
                            <td>
                                <?= e($po['item_name']) ?>
                                <div class="small text-muted">
                                    @ <?= money($po['unit_cost']) ?>
                                    <?php if ($po['sku'] !== null && $po['sku'] !== ''): ?>
                                        &middot; SKU <?= e($po['sku']) ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="small"><?= e($po['supplier_name']) ?></td>
                            <td class="text-center text-nowrap">
                                <?= e(rtrim(rtrim($po['quantity'], '0'), '.')) ?> <?= e($po['unit']) ?>
                            </td>
                            <td class="text-end"><?= money($po['total_cost']) ?></td>
                            <td><span class="pill <?= $po_pill[$po['status']] ?>"><?= e(ucfirst($po['status'])) ?></span></td>
                            <td class="small text-nowrap">
                                <?= $po['order_date'] !== null ? 'ord ' . e(date('d M', strtotime($po['order_date']))) : '<span class="text-muted">not ordered</span>' ?>
                                <div class="text-muted">
                                    <?= $po['received_date'] !== null ? 'rcv ' . e(date('d M', strtotime($po['received_date']))) : '' ?>
                                </div>
                            </td>
                            <td class="text-end text-nowrap">
                                <?php if ($po['status'] === 'pending'): ?>
                                    <form class="d-inline" method="post" action="<?= $self ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="mark_ordered">
                                        <input type="hidden" name="po_id" value="<?= (int)$po['po_id'] ?>">
                                        <button class="btn btn-sm btn-outline-primary" type="submit" title="Mark as ordered">
                                            <i class="bi bi-send"></i>
                                        </button>
                                    </form>
                                    <form class="d-inline" method="post" action="<?= $self ?>"
                                          data-confirm="Receive now? This adds <?= e(rtrim(rtrim($po['quantity'], '0'), '.')) ?> <?= e($po['unit']) ?> to <?= e($po['item_name']) ?>.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="receive">
                                        <input type="hidden" name="po_id" value="<?= (int)$po['po_id'] ?>">
                                        <button class="btn btn-sm btn-outline-success" type="submit" title="Receive (adds stock)">
                                            <i class="bi bi-box-arrow-in-down"></i>
                                        </button>
                                    </form>
                                    <form class="d-inline" method="post" action="<?= $self ?>"
                                          data-confirm="Cancel this purchase order?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="cancel">
                                        <input type="hidden" name="po_id" value="<?= (int)$po['po_id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit" title="Cancel">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </form>
                                <?php elseif ($po['status'] === 'ordered'): ?>
                                    <form class="d-inline" method="post" action="<?= $self ?>"
                                          data-confirm="Receive now? This adds <?= e(rtrim(rtrim($po['quantity'], '0'), '.')) ?> <?= e($po['unit']) ?> to <?= e($po['item_name']) ?>.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="receive">
                                        <input type="hidden" name="po_id" value="<?= (int)$po['po_id'] ?>">
                                        <button class="btn btn-sm btn-outline-success" type="submit" title="Receive (adds stock)">
                                            <i class="bi bi-box-arrow-in-down"></i> Receive
                                        </button>
                                    </form>
                                    <form class="d-inline" method="post" action="<?= $self ?>"
                                          data-confirm="Cancel this purchase order?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="cancel">
                                        <input type="hidden" name="po_id" value="<?= (int)$po['po_id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit" title="Cancel">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted small">&mdash;</span>
                                <?php endif; ?>
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
                    <a class="page-link" href="<?= $self ?>?page=<?= ($page - 1) . $qs ?>">&laquo;</a>
                </li>
                <?php $prev = 0; foreach ($pages_to_show as $p): ?>
                    <?php if ($p - $prev > 1): ?>
                        <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                    <?php endif; ?>
                    <li class="page-item <?= $p == $page ? 'active' : '' ?>">
                        <a class="page-link" href="<?= $self ?>?page=<?= $p . $qs ?>"><?= $p ?></a>
                    </li>
                <?php $prev = $p; endforeach; ?>
                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= $self ?>?page=<?= ($page + 1) . $qs ?>">&raquo;</a>
                </li>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<p class="text-muted small mt-3 mb-0">
    <i class="bi bi-lightbulb"></i> <b>How auto-orders work:</b> an item qualifies when its quantity is at
    or below the reorder level, it has a supplier, and no pending/ordered PO exists for it. The suggested
    quantity tops the stock up to <b>twice the reorder level</b> at the item's cost price - adjust the order
    with your supplier before marking it ordered.
</p>

<?php require_once __DIR__ . '/../../includes/dash_footer.php'; ?>