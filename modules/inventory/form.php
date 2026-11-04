<?php
/**
 * ============================================================
 *  INVENTORY - ADD / EDIT ITEM (one file for both modes)
 *  ?id=N  -> edit item N,  no id -> add a new item
 *  Fields: name, category, SKU (optional, unique), initial
 *  quantity (add mode only - later changes go through
 *  restock.php so "Last restocked" stays truthful), reorder
 *  level, unit, cost / selling price, supplier.
 *  Access: admin + receptionist  (can_manage('inventory'))
 * ============================================================
 */

require_once __DIR__ . '/../../includes/init.php';
require_role(array('admin', 'receptionist'));

 $self    = BASE_URL . 'modules/inventory/';
 $id      = (int)get('id');
 $is_edit = $id > 0;

 $categories = array('Hair Products', 'Beauty Supplies', 'Nail Supplies',
                    'Equipment', 'Retail Products', 'Other');

/* ---------- suppliers dropdown ---------- */
 $suppliers = mysqli_fetch_all(mysqli_query($conn,
    "SELECT supplier_id, name FROM suppliers ORDER BY name ASC"), MYSQLI_ASSOC);

/* ---------- defaults / existing row ---------- */
 $item = array(
    'item_name'     => '',
    'category'      => 'Hair Products',
    'sku'           => '',
    'quantity'      => '0',
    'reorder_level' => '5',
    'unit'          => 'pcs',
    'cost_price'    => '0.00',
    'selling_price' => '',
    'supplier_id'   => 0,
);

if ($is_edit) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM inventory_items WHERE item_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$row) {
        set_flash('danger', 'Inventory item not found.');
        redirect($self . 'index.php');
    }
    $item = array(
        'item_name'     => $row['item_name'],
        'category'      => $row['category'],
        'sku'           => (string)$row['sku'],
        'quantity'      => $row['quantity'],
        'reorder_level' => $row['reorder_level'],
        'unit'          => $row['unit'],
        'cost_price'    => $row['cost_price'],
        'selling_price' => $row['selling_price'] !== null ? $row['selling_price'] : '',
        'supplier_id'   => (int)$row['supplier_id'],
    );
}

/* ============================================================
 *  HANDLE SUBMIT
 * ========================================================== */
 $errors = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $item['item_name']     = post('item_name');
    $item['category']      = post('category', 'Other');
    $item['sku']           = post('sku');
    $item['reorder_level'] = post('reorder_level', '0');
    $item['unit']          = post('unit', 'pcs');
    $item['cost_price']    = post('cost_price', '0');
    $item['selling_price'] = post('selling_price');
    $item['supplier_id']   = (int)post('supplier_id');
    $qty_in                = post('quantity', '0');   // add mode only

    /* --- name --- */
    if ($item['item_name'] === '') {
        $errors['item_name'] = 'Item name is required.';
    } elseif (mb_strlen($item['item_name']) > 120) {
        $errors['item_name'] = 'Name is too long (max 120 characters).';
    }

    /* --- category --- */
    if (!in_array($item['category'], $categories, true)) {
        $errors['category'] = 'Pick a category from the list.';
    }

    /* --- SKU: optional, unique (empty stored as NULL) --- */
    if ($item['sku'] !== '') {
        if (mb_strlen($item['sku']) > 30) {
            $errors['sku'] = 'SKU is too long (max 30 characters).';
        } else {
            $exclude = $is_edit ? $id : 0;
            $stmt = mysqli_prepare($conn,
                "SELECT item_id FROM inventory_items WHERE sku = ? AND item_id <> ?");
            mysqli_stmt_bind_param($stmt, 'si', $item['sku'], $exclude);
            mysqli_stmt_execute($stmt);
            $dup = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if ($dup) {
                $errors['sku'] = 'Another item already uses this SKU.';
            }
        }
    }

    /* --- numbers --- */
    if (!is_numeric($item['reorder_level']) || (float)$item['reorder_level'] < 0) {
        $errors['reorder_level'] = 'Reorder level must be a number of 0 or more.';
    }
    if (!is_numeric($item['cost_price']) || (float)$item['cost_price'] < 0) {
        $errors['cost_price'] = 'Cost price must be a number of 0 or more.';
    }
    if ($item['selling_price'] !== '' &&
        (!is_numeric($item['selling_price']) || (float)$item['selling_price'] < 0)) {
        $errors['selling_price'] = 'Selling price must be a number of 0 or more (or empty).';
    }
    if (mb_strlen($item['unit']) > 20 || $item['unit'] === '') {
        $errors['unit'] = 'Unit is required (max 20 characters), e.g. pcs, bottle, box.';
    }

    /* --- initial quantity (add mode only) --- */
    if (!$is_edit) {
        if (!is_numeric($qty_in) || (float)$qty_in < 0) {
            $errors['quantity'] = 'Initial quantity must be a number of 0 or more.';
        }
    }

    /* --- supplier: 0 = none, otherwise must exist --- */
    if ($item['supplier_id'] > 0) {
        $stmt = mysqli_prepare($conn, "SELECT supplier_id FROM suppliers WHERE supplier_id = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'i', $item['supplier_id']);
        mysqli_stmt_execute($stmt);
        $ok = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$ok) {
            $errors['supplier_id'] = 'Pick a supplier from the list.';
        }
    }

    /* --- save --- */
    if (!$errors) {
        $sku_v   = $item['sku']           !== '' ? $item['sku']           : null;
        $sell_v  = $item['selling_price'] !== '' ? (float)$item['selling_price'] : null;
        $sup_v   = $item['supplier_id']   > 0   ? $item['supplier_id']   : null;
        $reord_v = round((float)$item['reorder_level'], 2);
        $cost_v  = round((float)$item['cost_price'], 2);
        $name_v  = $item['item_name'];
        $cat_v   = $item['category'];
        $unit_v  = $item['unit'];

        if ($is_edit) {
            $sql = "UPDATE inventory_items SET
                        item_name = ?, category = ?, sku = ?, reorder_level = ?,
                        unit = ?, cost_price = ?, selling_price = ?, supplier_id = ?
                    WHERE item_id = ?";
            $types = 'ssdsdddi' . 'i';   /* 8 fields + id */
            $params = array($name_v, $cat_v, $sku_v, $reord_v,
                            $unit_v, $cost_v, $sell_v, $sup_v, $id);
        } else {
            $qty_v = round((float)$qty_in, 2);
            $sql = "INSERT INTO inventory_items
                        (item_name, category, sku, quantity, reorder_level,
                         unit, cost_price, selling_price, supplier_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $types = 'sssdsdddi';        /* 9 fields */
            $params = array($name_v, $cat_v, $sku_v, $qty_v, $reord_v,
                            $unit_v, $cost_v, $sell_v, $sup_v);
        }

        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        set_flash('success', $is_edit ? 'Item updated.' : 'Item added to inventory.');
        redirect($self . 'index.php');
    }
}

 $page_title = $is_edit ? 'Edit item' : 'Add item';
require_once __DIR__ . '/../../includes/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h5 class="mb-0"><?= $is_edit ? '<i class="bi bi-pencil-square"></i> Edit item' : '<i class="bi bi-plus-lg"></i> Add item' ?></h5>
    <a class="btn btn-outline-secondary btn-sm" href="<?= $self ?>index.php">
        <i class="bi bi-arrow-left"></i> Back to inventory
    </a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> Please fix the highlighted fields and try again.</div>
<?php endif; ?>

<div class="card card-salon">
    <div class="card-body">
        <form method="post" action="<?= $self ?>form.php<?= $is_edit ? '?id=' . $id : '' ?>" novalidate>
            <?= csrf_field() ?>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Item name <span class="text-danger">*</span></label>
                    <input type="text" name="item_name" maxlength="120"
                           class="form-control <?= isset($errors['item_name']) ? 'is-invalid' : '' ?>"
                           value="<?= e($item['item_name']) ?>" placeholder="e.g. Keratin Shampoo 1L" required>
                    <?php if (isset($errors['item_name'])): ?><div class="invalid-feedback"><?= e($errors['item_name']) ?></div><?php endif; ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Category <span class="text-danger">*</span></label>
                    <select name="category" class="form-select <?= isset($errors['category']) ? 'is-invalid' : '' ?>">
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= e($c) ?>" <?= $item['category'] === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['category'])): ?><div class="invalid-feedback"><?= e($errors['category']) ?></div><?php endif; ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label">SKU</label>
                    <input type="text" name="sku" maxlength="30"
                           class="form-control <?= isset($errors['sku']) ? 'is-invalid' : '' ?>"
                           value="<?= e($item['sku']) ?>" placeholder="optional stock code">
                    <?php if (isset($errors['sku'])): ?><div class="invalid-feedback"><?= e($errors['sku']) ?></div><?php endif; ?>
                </div>

                <?php if ($is_edit): ?>
                    <div class="col-md-4">
                        <label class="form-label">Current stock</label>
                        <div class="input-group">
                            <input type="text" class="form-control" value="<?= e(rtrim(rtrim($item['quantity'], '0'), '.')) ?>" disabled>
                            <span class="input-group-text"><?= e($item['unit']) ?></span>
                        </div>
                        <div class="form-text">Adjust stock on the
                            <a href="<?= $self ?>restock.php?id=<?= $id ?>">stock in / out</a> page.</div>
                    </div>
                <?php else: ?>
                    <div class="col-md-4">
                        <label class="form-label">Initial quantity</label>
                        <input type="number" name="quantity" min="0" step="0.01"
                               class="form-control <?= isset($errors['quantity']) ? 'is-invalid' : '' ?>"
                               value="<?= e($item['quantity']) ?>">
                        <?php if (isset($errors['quantity'])): ?><div class="invalid-feedback"><?= e($errors['quantity']) ?></div><?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="col-md-4">
                    <label class="form-label">Reorder level <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="number" name="reorder_level" min="0" step="0.01"
                               class="form-control <?= isset($errors['reorder_level']) ? 'is-invalid' : '' ?>"
                               value="<?= e($item['reorder_level']) ?>" required>
                        <span class="input-group-text"><?= e($item['unit'] !== '' ? $item['unit'] : 'pcs') ?></span>
                    </div>
                    <div class="form-text">Alerts fire when stock falls to this level or below.</div>
                    <?php if (isset($errors['reorder_level'])): ?><div class="invalid-feedback d-block"><?= e($errors['reorder_level']) ?></div><?php endif; ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Unit <span class="text-danger">*</span></label>
                    <input type="text" name="unit" maxlength="20"
                           class="form-control <?= isset($errors['unit']) ? 'is-invalid' : '' ?>"
                           value="<?= e($item['unit']) ?>" placeholder="pcs, bottle, box, ml..." required>
                    <?php if (isset($errors['unit'])): ?><div class="invalid-feedback"><?= e($errors['unit']) ?></div><?php endif; ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Cost price <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="number" name="cost_price" min="0" step="0.01"
                               class="form-control <?= isset($errors['cost_price']) ? 'is-invalid' : '' ?>"
                               value="<?= e($item['cost_price']) ?>" required>
                        <span class="input-group-text"><?= e(get_setting('currency_symbol', '₹')) ?></span>
                    </div>
                    <?php if (isset($errors['cost_price'])): ?><div class="invalid-feedback d-block"><?= e($errors['cost_price']) ?></div><?php endif; ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Selling price</label>
                    <div class="input-group">
                        <input type="number" name="selling_price" min="0" step="0.01"
                               class="form-control <?= isset($errors['selling_price']) ? 'is-invalid' : '' ?>"
                               value="<?= e($item['selling_price']) ?>" placeholder="retail only">
                        <span class="input-group-text"><?= e(get_setting('currency_symbol', '₹')) ?></span>
                    </div>
                    <?php if (isset($errors['selling_price'])): ?><div class="invalid-feedback d-block"><?= e($errors['selling_price']) ?></div><?php endif; ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Supplier</label>
                    <select name="supplier_id" class="form-select <?= isset($errors['supplier_id']) ? 'is-invalid' : '' ?>">
                        <option value="0">No supplier yet</option>
                        <?php foreach ($suppliers as $sp): ?>
                            <option value="<?= (int)$sp['supplier_id'] ?>"
                                <?= $item['supplier_id'] === (int)$sp['supplier_id'] ? 'selected' : '' ?>>
                                <?= e($sp['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Required before auto purchase orders can be raised -
                        <a href="<?= $self ?>suppliers.php">manage suppliers</a>.</div>
                    <?php if (isset($errors['supplier_id'])): ?><div class="invalid-feedback d-block"><?= e($errors['supplier_id']) ?></div><?php endif; ?>
                </div>
            </div>

            <div class="alert alert-info small mt-4 mb-0">
                <i class="bi bi-lightbulb"></i> <b>How stock changes:</b> by hand on the
                <b>stock in / out</b> page (opens from the inventory list), or automatically when a
                <a href="<?= $self ?>purchase_orders.php">purchase order</a> is received.
                Quantity is locked here in edit mode so <i>Last restocked</i> stays accurate.
            </div>

            <div class="mt-4 d-flex gap-2">
                <button class="btn btn-salon px-4" type="submit">
                    <i class="bi bi-check2-circle"></i> <?= $is_edit ? 'Save changes' : 'Add item' ?>
                </button>
                <a class="btn btn-outline-secondary" href="<?= $self ?>index.php">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/dash_footer.php'; ?>