<?php
/**
 * ============================================================
 *  INVENTORY - STOCK IN / OUT (Step 8)
 *  ?id=N - adjust one item's quantity by hand.
 *    stock IN  -> quantity + n, Last restocked = today
 *    stock OUT -> quantity - n (cannot go below 0)
 *  When a movement pushes the item INTO the low-stock zone
 *  (from above reorder level to at/below it) a simulated email
 *  alert is written to the notifications log.
 *  Access: admin + receptionist  (can_manage('inventory'))
 * ============================================================
 */

require_once __DIR__ . '/../../includes/init.php';
require_role(array('admin', 'receptionist'));

 $self = BASE_URL . 'modules/inventory/';
 $id   = (int)get('id');

if ($id <= 0) {
    redirect($self . 'index.php');
}

/* ---------- item + supplier ---------- */
 $stmt = mysqli_prepare($conn,
    "SELECT i.*, sp.name AS supplier_name
     FROM inventory_items i
     LEFT JOIN suppliers sp ON sp.supplier_id = i.supplier_id
     WHERE i.item_id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
 $item = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$item) {
    set_flash('danger', 'Inventory item not found.');
    redirect($self . 'index.php');
}

/* ============================================================
 *  HANDLE SUBMIT
 * ========================================================== */
 $errors = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $movement = post('movement');
    $amount   = post('amount');

    if (!in_array($movement, array('in', 'out'), true)) {
        $errors['movement'] = 'Pick stock in or stock out.';
    }
    if (!is_numeric($amount) || (float)$amount <= 0) {
        $errors['amount'] = 'Enter a quantity greater than 0.';
    } elseif ((float)$amount > 100000) {
        $errors['amount'] = 'That quantity is too large (max 100,000).';
    }

    $qty  = (float)$item['quantity'];
    $add  = round((float)$amount, 2);

    if (!$errors && $movement === 'out' && $add > $qty) {
        $errors['amount'] = 'Cannot remove more than the current stock ('
            . rtrim(rtrim($item['quantity'], '0'), '.') . ' ' . $item['unit'] . ').';
    }

    if (!$errors) {
        $new_qty = ($movement === 'in') ? $qty + $add : $qty - $add;
        $new_qty = round($new_qty, 2);

        if ($movement === 'in') {
            $sql = "UPDATE inventory_items SET quantity = ?, last_restocked = CURDATE() WHERE item_id = ?";
        } else {
            $sql = "UPDATE inventory_items SET quantity = ? WHERE item_id = ?";
        }
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'di', $new_qty, $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        /* crossing INTO the low-stock zone raises one alert */
        $reorder = (float)$item['reorder_level'];
        if ($new_qty <= $reorder && $qty > $reorder) {
            $state = $new_qty <= 0 ? 'OUT OF STOCK' : 'running low';
            log_notification(array(
                'recipient' => get_setting('salon_email', 'manager@elegance.local'),
                'channel'   => 'email',
                'type'      => 'inventory',
                'subject'   => 'Low stock: ' . $item['item_name'],
                'message'   => $item['item_name'] . ' is now ' . $state . " after a stock movement.\n" .
                               'In stock: ' . rtrim(rtrim($new_qty, '0'), '.') . ' ' . $item['unit'] .
                               ' (reorder at ' . rtrim(rtrim($reorder, '0'), '.') . ' ' . $item['unit'] . ").\n" .
                               'Raise a purchase order under Inventory > Purchase orders.',
                'status'    => 'pending',
            ));
            set_flash('warning', 'Stock saved - ' . $item['item_name'] .
                ' has dropped to its reorder level, a low-stock alert was queued.');
        } else {
            set_flash('success', ($movement === 'in' ? 'Stocked in ' : 'Stocked out ')
                . rtrim(rtrim($add, '0'), '.') . ' ' . $item['unit'] . ' - new quantity: '
                . rtrim(rtrim($new_qty, '0'), '.') . ' ' . $item['unit'] . '.');
        }
        redirect($self . 'restock.php?id=' . $id);
    }
}

/* ---------- recent purchase orders for this item ---------- */
 $stmt = mysqli_prepare($conn,
    "SELECT po.po_number, po.quantity, po.unit_cost, po.status, po.order_date, po.received_date,
            sp.name AS supplier_name
     FROM purchase_orders po
     JOIN suppliers sp ON sp.supplier_id = po.supplier_id
     WHERE po.item_id = ?
     ORDER BY po.po_id DESC
     LIMIT 5");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
 $pos = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

 $po_pill = array(
    'pending'   => 'pill-on_leave',
    'ordered'   => 'pill-scheduled',
    'received'  => 'pill-active',
    'cancelled' => 'pill-cancelled',
);

 $page_title = 'Stock in / out';
require_once __DIR__ . '/../../includes/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h5 class="mb-0"><i class="bi bi-box-arrow-in-up"></i> Stock in / out</h5>
    <a class="btn btn-outline-secondary btn-sm" href="<?= $self ?>index.php">
        <i class="bi bi-arrow-left"></i> Back to inventory
    </a>
</div>

<div class="row g-3">
    <!-- ---------- left: item summary + movement form ---------- -->
    <div class="col-lg-5">
        <div class="card card-salon mb-3">
            <div class="card-body">
                <h5 class="mb-1"><?= e($item['item_name']) ?></h5>
                <div class="small text-muted mb-3">
                    <?= e($item['category']) ?>
                    <?php if ($item['sku'] !== null && $item['sku'] !== ''): ?>
                        &middot; SKU <?= e($item['sku']) ?>
                    <?php endif; ?>
                    &middot; <?= $item['supplier_name'] !== null ? e($item['supplier_name']) : 'no supplier' ?>
                </div>

                <div class="row g-2 text-center mb-2">
                    <div class="col-4">
                        <div class="stat-card">
                            <div class="stat-label">In stock</div>
                            <div class="stat-value"><?= e(rtrim(rtrim($item['quantity'], '0'), '.')) ?></div>
                            <div class="small text-muted"><?= e($item['unit']) ?></div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="stat-card">
                            <div class="stat-label">Reorder at</div>
                            <div class="stat-value"><?= e(rtrim(rtrim($item['reorder_level'], '0'), '.')) ?></div>
                            <div class="small text-muted"><?= e($item['unit']) ?></div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="stat-card">
                            <div class="stat-label">Stock value</div>
                            <div class="stat-value" style="font-size:1.05rem;"><?= money((float)$item['quantity'] * (float)$item['cost_price']) ?></div>
                            <div class="small text-muted">@ <?= money($item['cost_price']) ?></div>
                        </div>
                    </div>
                </div>
                <div class="small text-muted">
                    Last restocked:
                    <?= $item['last_restocked'] !== null ? e(date('d M Y', strtotime($item['last_restocked']))) : 'never' ?>
                </div>
            </div>
        </div>

        <div class="card card-salon">
            <div class="card-header"><i class="bi bi-arrow-left-right"></i> Record a movement</div>
            <div class="card-body">
                <form method="post" action="<?= $self ?>restock.php?id=<?= $id ?>" novalidate>
                    <?= csrf_field() ?>

                    <label class="form-label d-block">Movement type <span class="text-danger">*</span></label>
                    <div class="d-flex gap-3 mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="movement" id="mv-in" value="in"
                                   <?= post('movement') !== 'out' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="mv-in">
                                <i class="bi bi-box-arrow-in-up text-success"></i> Stock in (delivery / found stock)
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="movement" id="mv-out" value="out"
                                   <?= post('movement') === 'out' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="mv-out">
                                <i class="bi bi-box-arrow-up text-danger"></i> Stock out (used / damaged)
                            </label>
                        </div>
                    </div>
                    <?php if (isset($errors['movement'])): ?><div class="text-danger small mb-2"><?= e($errors['movement']) ?></div><?php endif; ?>

                    <label class="form-label">Quantity (<?= e($item['unit']) ?>) <span class="text-danger">*</span></label>
                    <input type="number" name="amount" min="0.01" step="0.01"
                           class="form-control <?= isset($errors['amount']) ? 'is-invalid' : '' ?>"
                           value="<?= e(post('amount')) ?>" placeholder="e.g. 6" required>
                    <?php if (isset($errors['amount'])): ?><div class="invalid-feedback d-block"><?= e($errors['amount']) ?></div><?php endif; ?>

                    <div class="alert alert-info small mt-3 mb-3">
                        <i class="bi bi-bell"></i> Stock <b>in</b> stamps <i>Last restocked</i> with today.
                        If a movement drops the item to its reorder level or below, a low-stock
                        email alert is queued automatically.
                    </div>

                    <button class="btn btn-salon w-100" type="submit">
                        <i class="bi bi-check2-circle"></i> Save movement
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- ---------- right: purchase order history ---------- -->
    <div class="col-lg-7">
        <div class="card card-salon">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-cart3"></i> Recent purchase orders</span>
                <a class="btn btn-sm btn-outline-salon" href="<?= $self ?>purchase_orders.php">
                    <i class="bi bi-list-ul"></i> All orders
                </a>
            </div>
            <div class="card-body p-0">
                <?php if (!$pos): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="bi bi-cart-x fs-3 d-block mb-2"></i>
                        No purchase orders for this item yet -
                        <a href="<?= $self ?>purchase_orders.php">generate one automatically</a>
                        when stock runs low.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th>PO #</th>
                                    <th>Supplier</th>
                                    <th class="text-center">Qty</th>
                                    <th class="text-end">Unit cost</th>
                                    <th>Status</th>
                                    <th>Dates</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($pos as $po): ?>
                                <tr>
                                    <td class="fw-semibold"><?= e($po['po_number']) ?></td>
                                    <td class="small"><?= e($po['supplier_name']) ?></td>
                                    <td class="text-center"><?= e(rtrim(rtrim($po['quantity'], '0'), '.')) ?></td>
                                    <td class="text-end"><?= money($po['unit_cost']) ?></td>
                                    <td><span class="pill <?= $po_pill[$po['status']] ?>"><?= e(ucfirst($po['status'])) ?></span></td>
                                    <td class="small text-nowrap">
                                        <?= $po['order_date'] !== null ? 'ordered ' . e(date('d M', strtotime($po['order_date']))) : 'not ordered yet' ?>
                                        <?php if ($po['received_date'] !== null): ?>
                                            &middot; received <?= e(date('d M', strtotime($po['received_date']))) ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="alert alert-light border small mt-3 mb-0">
            <i class="bi bi-info-circle"></i> Tip: receiving a purchase order (status
            <b>Received</b>) adds its quantity here automatically and stamps
            <i>Last restocked</i> - use this page only for corrections and quick top-ups.
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/dash_footer.php'; ?>