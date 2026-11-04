<?php
/**
 * ============================================================
 *  SUBMIT FEEDBACK
 *  Public form - anyone can rate the application 1-5 stars
 *  and leave comments. Stored in the `feedback` table and
 *  reviewed by the admin (Step 11).
 *  Uses the PRG pattern: Post -> Redirect -> Get, so a page
 *  refresh never double-submits.
 * ============================================================
 */

require_once __DIR__ . '/includes/init.php';

$page_title = 'Submit Feedback';
$errors = array();
// keep what the user typed when validation fails
$old = array('name' => '', 'email' => '', 'comments' => '', 'rating' => '5');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $old['name']     = post('name');
    $old['email']    = post('email');
    $old['comments'] = post('comments');
    $old['rating']   = post('rating', '5');

    /* ---- server-side validation ---- */
    if ($old['name'] === '') {
        $errors[] = 'Please tell us your name.';
    }
    if ($old['comments'] === '') {
        $errors[] = 'Please write your feedback in the comments box.';
    }
    if ($old['email'] !== '' && !valid_email($old['email'])) {
        $errors[] = 'The email address does not look valid.';
    }

    $rating = (int)$old['rating'];
    if ($rating < 1 || $rating > 5) {
        $errors[] = 'Please choose a star rating between 1 and 5.';
    }

    /* ---- save ---- */
    if (!$errors) {
        $stmt = mysqli_prepare($conn,
            "INSERT INTO feedback (name, email, rating, comments) VALUES (?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'ssis', $old['name'], $old['email'], $rating, $old['comments']);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        set_flash('success', 'Thank you for your feedback! We read every submission.');
        redirect(BASE_URL . 'feedback.php');   // PRG: no double-submit on refresh
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="h3 mb-1">Submit Feedback</h1>
<p class="text-muted mb-4">How was your experience with the salon and this application?</p>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card card-salon">
            <div class="card-body p-4">

                <?php if ($errors): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($errors as $err): ?>
                            <div><i class="bi bi-exclamation-circle"></i> <?= e($err) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?= BASE_URL ?>feedback.php">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label">Your name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" maxlength="100"
                               placeholder="Enter Name"
                               value="<?= e($old['name']) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Email <span class="text-muted small">(optional)</span></label>
                        <input type="email" name="email" class="form-control" maxlength="120"
                               placeholder="you@example.com"
                               value="<?= e($old['email']) ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label d-block">Your rating <span class="text-danger">*</span></label>
                        <div class="star-rating">
                            <?php for ($i = 5; $i >= 1; $i--): ?>
                                <input type="radio" name="rating" id="star<?= $i ?>" value="<?= $i ?>"
                                    <?= (int)$old['rating'] === $i ? 'checked' : '' ?>>
                                <label for="star<?= $i ?>" title="<?= $i ?> star<?= $i > 1 ? 's' : '' ?>">
                                    <i class="bi bi-star-fill"></i>
                                </label>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Comments <span class="text-danger">*</span></label>
                        <textarea name="comments" class="form-control" rows="5" maxlength="2000"
                                  placeholder="What did you like? What can we improve?"
                                  required><?= e($old['comments']) ?></textarea>
                    </div>

                    <button type="submit" class="btn btn-salon px-4">
                        <i class="bi bi-send"></i> Send feedback
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
