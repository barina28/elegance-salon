<?php
/**
 * ============================================================
 *  INVENTORY - SUPPLIERS (Step 8)
 *  One page, two panels:
 *    left  - add / edit a supplier (?id=N fills the form)
 *    right - supplier list with item + open-PO counts
 *  Delete rules:
 *    - blocked while OPEN purchase orders reference the
 *      supplier (restricting FK on purchase_orders)
 *    - otherwise allowed; items fall back to "no supplier"
 *      (FK is ON DELETE SET NULL)
 *  Access: admin + receptionist  (can_manage('inventory'))
 * ============================================================
 */

require_once __DIR__ . '/../../includes/init.php';
require_role(array('admin', 'receptionist'));

 $self    = BASE_URL . 'modules/inventory/suppliers.php';
 $back    = BASE_URL . 'modules/inventory/index.php';
 $edit_id = (int)get('id');

/* ============================================================
 *  POST ACTIONS - save / delete (guarded by CSRF)
 * ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post('action');

    /* ---------- save (create or update) ---------- */
    if ($action === 'save') {
        $sid      = (int)post('supplier_id');
        $is_edit  = $sid > 0;
        $errors   = array();

        $name      = post('name');
        $contact   = post('contact_person');
        $phone     = post('phone');
        $email     = post('email');
        $address   = post('address');

        if ($name === '') {
            $errors['name'] = 'Supplier name is required.';
        } elseif (mb_strlen($name) > 120) {
            $errors['name'] = 'Name is too long (max 120 characters).';
        }
        if (mb_strlen($contact) > 100) {
            $errors['contact_person'] = 'Contact person is too long (max 100 characters).';
        }
        if ($phone !== '' && !preg_match('/^[0-9+\-() ]{7,20}$/', $phone)) {
            $errors['phone'] = 'Use digits, spaces and + - ( ) only (7-20 characters).';
        }
        if ($email !== '') {
            if (!valid_email($email)) {
                $errors['email'] = 'That email address does not look valid.';
            } elseif (mb_strlen($email) > 120) {
                $errors['email'] = 'Email is too long (max 120 characters).';
            }
        }
        if (mb_strlen($address) > 255) {
            $errors['address'] = 'Address is too long (max 255 characters).';
        }

        if ($errors) {
            /* surface the first problem and bounce back to the form */
            $first = reset($errors);
            set_flash('danger', $first);
            redirect($self . ($is_edit ? '?id=' . $sid : ''));
        }

        $name_v    = $name;
        $contact_v = $contact !== '' ? $contact : null;
        $phone_v   = $phone   !== '' ? $phone   : null;
        $email_v   = $email   !== '' ? $email   : null;
        $address_v = $address !== '' ? $address : null;

        if ($is_edit) {
            /* must exist */
            $stmt = mysqli_prepare($conn, "SELECT supplier_id FROM suppliers WHERE supplier_id = ? LIMIT 1");
            mysqli_stmt_bind_param($stmt, 'i', $sid);
            mysqli_stmt_execute($stmt);
            $ok = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if (!$ok) {
                set_flash('danger', 'Supplier not found (maybe already deleted).');
                redirect($self);
            }
            $stmt = mysqli_prepare($conn,
                "UPDATE suppliers SET name = ?, contact_person = ?, phone = ?, email = ?, address = ?
                 WHERE supplier_id = ?");
            mysqli_stmt_bind_param($stmt, 'sssssi', $name_v, $contact_v, $phone_v, $email_v, $address_v, $sid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            set_flash('success', 'Supplier updated.');
        } else {
            $stmt = mysqli_prepare($conn,
                "INSERT INTO suppliers (name, contact_person, phone, email, address)
                 VALUES (?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'sssss', $name_v, $contact_v, $phone_v, $email_v, $address_v);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            set_flash('success', 'Supplier added.');
        }
        redirect($self);
    }

    /* ---------- delete ---------- */
    if ($action === 'delete') {
        $sid = (int)post('supplier_id');

        $stmt = mysqli_prepare($conn, "SELECT name FROM suppliers WHERE supplier_id = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'i', $sid);
        mysqli_stmt_execute($stmt);
        $sup = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$sup) {
            set_flash('danger', 'Supplier not found (maybe already deleted).');
            redirect($self);
        }

        /* open POs block the delete (restricting FK) */
        $stmt = mysqli_prepare($conn,
            "SELECT COUNT(*) FROM purchase_orders
             WHERE supplier_id = ? AND status IN ('pending','ordered')");
        mysqli_stmt_bind_param($stmt, 'i', $sid);
        mysqli_stmt_execute($stmt);
        $open_pos = (int)mysqli_fetch_row(mysqli_stmt_get_result($stmt))[0];
        mysqli_stmt_close($stmt);

        if ($open_pos > 0) {
            set_flash('warning', 'Cannot delete ' . $sup['name'] . ': ' . $open_pos .
                ' open purchase order(s) reference this supplier. Receive or cancel them first.');
        } else {
            /* items linked to this supplier fall back to "no supplier" (SET NULL) */
            $stmt = mysqli_prepare($conn,
                "SELECT COUNT(*) FROM inventory_items WHERE supplier_id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $sid);
            mysqli_stmt_execute($stmt);
            $linked = (int)mysqli_fetch_row(mysqli_stmt_get_result($stmt))[0];
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare($conn, "DELETE FROM suppliers WHERE supplier_id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $sid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            set_flash('success', 'Supplier deleted.' .
                ($linked > 0 ? ' ' . $linked . ' inventory item(s) now have no supplier - assign one before auto-ordering.' : ''));
        }
        redirect($self);
    }

    set_flash('warning', 'Unknown action.');
    redirect($self);
}

/* ============================================================
 *  DATA (GET)
 * ========================================================== */

/* ---------- edit mode: load the row into the form ---------- */
 $sup = array(
    'name'           => '',
    'contact_person' => '',
    'phone'          => '',
    'email'          => '',
    'address'        => '',
);
if ($edit_id > 0) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM suppliers WHERE supplier_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $edit_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$row) {
        set_flash('danger', 'Supplier not found.');
        redirect($self);
    }
    $sup = array(
        'name'           => $row['name'],
        'contact_person' => (string)$row['contact_person'],
        'phone'          => (string)$row['phone'],
        'email'          => (string)$row['email'],
        'address'        => (string)$row['address'],
    );
}

/* ---------- list with item + open-PO counts ---------- */
 $suppliers = mysqli_fetch_all(mysqli_query($conn,
    "SELECT sp.supplier_id, sp.name, sp.contact_person, sp.phone, sp.email, sp.address,
            (SELECT COUNT(*) FROM inventory_items i
              WHERE i.supplier_id = sp.supplier_id) AS items,
            (SELECT COUNT(*) FROM purchase_orders po
              WHERE po.supplier_id = sp.supplier_id
                AND po.status IN ('pending','ordered')) AS open_pos
     FROM suppliers sp
     ORDER BY sp.name ASC"), MYSQLI_ASSOC);

 $page_title = 'Suppliers';
require_once __DIR__ . '/../../includes/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h5 class="mb-1"><i class="bi bi-truck"></i> Suppliers</h5>
        <p class="text-muted small mb-0">Who the salon buys stock from. Auto purchase orders
            need a supplier on each low-stock item.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= $back ?>">
        <i class="bi bi-arrow-left"></i> Back to inventory
    </a>
</div>

<div class="row g-3">
    <!-- ---------- left: add / edit form ---------- -->
    <div class="col-lg-4">
        <div class="card card-salon">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><?= $edit_id > 0 ? '<i class="bi bi-pencil-square"></i> Edit supplier' : '<i class="bi bi-plus-lg"></i> Add supplier' ?></span>
                <?php if ($edit_id > 0): ?>
                    <a class="small text-decoration-none" href="<?= $self ?>"><i class="bi bi-x-lg"></i> new</a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <form method="post" action="<?= $self ?>" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="supplier_id" value="<?= $edit_id > 0 ? $edit_id : 0 ?>">

                    <div class="mb-3">
                        <label class="form-label">Supplier name <span class="text-danger">*</span></label>
                        <input type="text" name="name" maxlength="120" class="form-control"
                               value="<?= e($sup['name']) ?>" placeholder="e.g. GlowBeauty Distributors" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Contact person</label>
                        <input type="text" name="contact_person" maxlength="100" class="form-control"
                               value="<?= e($sup['contact_person']) ?>" placeholder="optional">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" maxlength="20" class="form-control"
                               value="<?= e($sup['phone']) ?>" placeholder="optional">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" maxlength="120" class="form-control"
                               value="<?= e($sup['email']) ?>" placeholder="optional">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea name="address" maxlength="255" rows="2" class="form-control"
                                  placeholder="optional"><?= e($sup['address']) ?></textarea>
                    </div>

                    <button class="btn btn-salon w-100" type="submit">
                        <i class="bi bi-check2-circle"></i> <?= $edit_id > 0 ? 'Save changes' : 'Add supplier' ?>
                    </button>
                    <?php if ($edit_id > 0): ?>
                        <a class="btn btn-outline-secondary w-100 mt-2" href="<?= $self ?>">Cancel</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <!-- ---------- right: supplier list ---------- -->
    <div class="col-lg-8">
        <div class="card card-salon">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-list-ul"></i> All suppliers</span>
                <span class="badge text-bg-light border"><?= count($suppliers) ?></span>
            </div>
            <div class="card-body p-0">
                <?php if (!$suppliers): ?>
                    <div class="p-5 text-center text-muted">
                        <i class="bi bi-truck fs-1 d-block mb-2"></i>
                        No suppliers yet - add the first one on the left.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th>Supplier</th>
                                    <th>Contact</th>
                                    <th class="text-center">Items</th>
                                    <th class="text-center">Open POs</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($suppliers as $sp): ?>
                                <tr class="<?= $edit_id === (int)$sp['supplier_id'] ? 'table-light' : '' ?>">
                                    <td>
                                        <span class="fw-semibold"><?= e($sp['name']) ?></span>
                                        <?php if ($sp['address'] !== null && $sp['address'] !== ''): ?>
                                            <div class="small text-muted"><?= e($sp['address']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small">
                                        <?= $sp['contact_person'] !== null ? e($sp['contact_person']) : '<span class="text-muted">-</span>' ?>
                                        <div class="text-muted">
                                            <?= $sp['phone'] !== null ? e($sp['phone']) : '' ?>
                                            <?= $sp['email'] !== null ? '&middot; ' . e($sp['email']) : '' ?>
                                        </div>
                                    </td>
                                    <td class="text-center"><span class="badge text-bg-light border"><?= (int)$sp['items'] ?></span></td>
                                    <td class="text-center">
                                        <span class="badge <?= $sp['open_pos'] > 0 ? 'text-bg-warning' : 'text-bg-light border text-muted' ?>">
                                            <?= (int)$sp['open_pos'] ?>
                                        </span>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <a class="btn btn-sm btn-outline-secondary" href="<?= $self ?>?id=<?= (int)$sp['supplier_id'] ?>" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <form class="d-inline" method="post" action="<?= $self ?>"
                                              data-confirm="Delete supplier <?= e($sp['name']) ?>? Linked items will keep existing but lose their supplier.">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="supplier_id" value="<?= (int)$sp['supplier_id'] ?>">
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
            <div class="card-footer bg-white small text-muted">
                <i class="bi bi-shield-lock"></i> Deleting is blocked while open purchase orders
                reference the supplier - receive or cancel those orders first.
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/dash_footer.php'; ?>