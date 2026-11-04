</main>

<footer class="salon-footer mt-auto">
  <div class="container py-4">
    <div class="row g-3">
      <div class="col-md-4">
        <h6 class="footer-title"><?= e(get_setting('salon_name', SITE_NAME)) ?></h6>
        <p class="mb-1 small"><?= e(get_setting('salon_tagline', '')) ?></p>
        <p class="mb-0 small">Salon Management System</p>
      </div>
      <div class="col-md-4">
        <h6 class="footer-title">Reach us</h6>
        <p class="mb-0 small">
          <i class="bi bi-geo-alt"></i> <?= e(get_setting('salon_address', '')) ?><br>
          <i class="bi bi-telephone"></i> <?= e(get_setting('salon_phone', '')) ?><br>
          <i class="bi bi-envelope"></i> <?= e(get_setting('salon_email', '')) ?>
        </p>
      </div>
      <div class="col-md-4">
        <h6 class="footer-title">Opening hours</h6>
        <p class="mb-0 small"><?= e(get_setting('opening_hours', '')) ?></p>
      </div>
    </div>
    <hr class="footer-hr">
    <p class="text-center small mb-0">&copy; <?= date('Y') ?> <?= e(get_setting('salon_name', SITE_NAME)) ?>. All rights reserved.</p>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>assets/js/main.js"></script>
</body>
</html>
