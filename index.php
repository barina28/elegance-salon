<?php
/**
 * ============================================================
 *  ELEGANCE SALON - PUBLIC HOME PAGE
 *  Hero + services price list (from the services table) +
 *  team strip + opening hours. All content comes from the
 *  `settings` / `services` / `staff` tables.
 * ============================================================
 */

require_once __DIR__ . '/includes/init.php';

/* ---------- active services grouped by category ---------- */
$grouped = array();
$res = mysqli_query(
    $conn,
    "SELECT service_id, name, category, description, duration_minutes, price
     FROM services
     WHERE is_active = 1
     ORDER BY FIELD(category,'Hair','Nails','Facial','Makeup','Spa','Other'), price ASC"
);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $grouped[$row['category']][] = $row;
    }
}

/* category -> bootstrap icon for the section headings */
$cat_icons = array(
    'Hair'   => 'bi-scissors',
    'Nails'  => 'bi-stars',
    'Facial' => 'bi-emoji-smile',
    'Makeup' => 'bi-palette',
    'Spa'    => 'bi-flower1',
    'Other'  => 'bi-droplet',
);

/* ---------- active stylists / artists (all active team members) ---------- */
$team = array();
$res = mysqli_query(
    $conn,
    "SELECT staff_id, name, specialization FROM staff WHERE status = 'active' ORDER BY staff_id ASC"
);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $team[] = $row;
    }
}

/* a couple of happy reviews (4 stars and up) for social proof */
$reviews = array();
$res = mysqli_query(
    $conn,
    "SELECT name, rating, comments FROM feedback
     WHERE rating >= 4 ORDER BY feedback_id DESC LIMIT 3"
);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $reviews[] = $row;
    }
}

$page_title = 'Home';
require_once __DIR__ . '/includes/header.php';
?>

<!-- ================= HERO ================= -->
<section class="hero rounded-4 mt-2">
  <div class="row g-0 align-items-stretch">
    <div class="col-lg-6 hero-text">
      <p class="eyebrow mb-2">Welcome to <?= e(get_setting('salon_name', SITE_NAME)) ?></p>
      <h1 class="display-5 fw-bold mb-3">
        <?= e(get_setting('salon_tagline', 'Where beauty meets elegance')) ?>
      </h1>
      <p class="lead mb-4">
        Hair, nails, facials, makeup and spa rituals - delivered by certified artists
        using premium products. Book your appointment online in minutes.
      </p>
      <div class="d-flex gap-2 flex-wrap mb-4">
        <a class="btn btn-salon btn-lg px-4" href="<?= BASE_URL ?>contact.php">
          <i class="bi bi-calendar-heart me-1"></i> Book an appointment
        </a>
        <a class="btn btn-outline-light btn-lg px-4" href="#services">
          <i class="bi bi-list-ul me-1"></i> Price list
        </a>
      </div>
      <div class="d-flex gap-4 flex-wrap small text-white-50">
        <span><i class="bi bi-person-check me-1"></i> Certified artists</span>
        <span><i class="bi bi-droplet me-1"></i> Premium products</span>
        <span><i class="bi bi-stars me-1"></i> Luxury experience</span>
      </div>
    </div>
    <div class="col-lg-6 hero-visual d-none d-lg-block"></div>
  </div>
</section>

<!-- ================= WHY US ================= -->
<section class="py-5">
  <h2 class="section-title text-center mb-1">Why clients choose us<span class="dot">.</span></h2>
  <p class="text-center text-muted mb-4">A few reasons behind our five-star reviews</p>
  <div class="row g-4">
    <div class="col-md-4">
      <div class="feature-item">
        <span class="fi"><i class="bi bi-person-badge"></i></span>
        <div>
          <h6 class="fw-bold mb-1">Expert team</h6>
          <p class="text-muted small mb-0">Every stylist is trained, certified and specialises in
          their own craft - from colouring to bridal makeup and nail art.</p>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="feature-item">
        <span class="fi"><i class="bi bi-shield-check"></i></span>
        <div>
          <h6 class="fw-bold mb-1">Hygiene first</h6>
          <p class="text-muted small mb-0">Hospital-grade sanitisation of tools and stations
          before every single appointment.</p>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="feature-item">
        <span class="fi"><i class="bi bi-clock-history"></i></span>
        <div>
          <h6 class="fw-bold mb-1">On-time service</h6>
          <p class="text-muted small mb-0">Smart scheduling means your slot starts when it should -
          no long waiting, ever.</p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ================= ABOUT + IMAGE ================= -->
<section class="pb-5">
  <div class="row g-4 align-items-center">
    <div class="col-lg-6">
      <div class="about-visual rounded-4"></div>
    </div>
    <div class="col-lg-6">
      <p class="eyebrow mb-2">About the salon</p>
      <h2 class="section-title mb-3">Your beauty, our craft<span class="dot">.</span></h2>
      <p class="text-muted">
        <?= e(get_setting('salon_name', SITE_NAME)) ?> has been serving the
        <?= e(get_setting('salon_address', 'neighbourhood')) ?> community with a simple promise:
        every guest leaves feeling more confident than when they arrived.
      </p>
      <ul class="list-unstyled text-muted small mb-4">
        <li class="mb-2"><i class="bi bi-check2-circle text-success me-2"></i>Personalised consultation before every service</li>
        <li class="mb-2"><i class="bi bi-check2-circle text-success me-2"></i>Ammonia-free colours and dermatologist-approved products</li>
        <li class="mb-2"><i class="bi bi-check2-circle text-success me-2"></i>Comfortable private space for bridal &amp; party makeup</li>
      </ul>
      <a class="btn btn-outline-salon" href="<?= BASE_URL ?>contact.php">Plan your visit <i class="bi bi-arrow-right"></i></a>
    </div>
  </div>
</section>

<!-- ================= SERVICES / PRICE LIST ================= -->
<section id="services" class="pb-5">
  <h2 class="section-title text-center mb-1">Our services<span class="dot">.</span></h2>
  <p class="text-center text-muted mb-4">Transparent pricing - no surprises at checkout</p>

  <?php if (!$grouped): ?>
      <div class="alert alert-info text-center">The price list is being updated. Please call us at
          <b><?= e(get_setting('salon_phone', '')) ?></b>.</div>
  <?php else: ?>
      <div class="row g-4">
        <?php foreach ($grouped as $cat => $items): ?>
          <div class="col-md-6">
            <h5 class="cat-title mb-3">
              <i class="bi <?= isset($cat_icons[$cat]) ? $cat_icons[$cat] : 'bi-droplet' ?> me-1"></i>
              <?= e($cat) ?>
            </h5>
            <?php foreach ($items as $s): ?>
              <div class="service-card p-3 mb-3">
                <div class="d-flex justify-content-between align-items-baseline gap-2">
                  <h6 class="fw-bold mb-1"><?= e($s['name']) ?></h6>
                  <span class="service-price text-nowrap"><?= money($s['price']) ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-1">
                  <p class="text-muted small mb-0">
                    <?= e($s['description'] !== null && $s['description'] !== '' ? $s['description'] : 'Professional ' . strtolower($cat) . ' service.') ?>
                    <span class="ms-1 text-nowrap"><i class="bi bi-hourglass-split"></i> <?= (int)$s['duration_minutes'] ?> min</span>
                  </p>
                  <a href="<?= BASE_URL ?>contact.php?service_id=<?= (int)$s['service_id'] ?>" class="btn btn-sm btn-outline-salon py-0 px-2 text-nowrap" style="font-size:0.75rem;">
                    <i class="bi bi-calendar-plus me-1"></i>Book
                  </a>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
  <?php endif; ?>
</section>

<!-- ================= TEAM / ARTISTS ================= -->
<?php if ($team): ?>
<section class="pb-5" id="artists">
  <div class="text-center mb-4">
    <h2 class="section-title mb-1">Meet our beauty artists<span class="dot">.</span></h2>
    <p class="text-muted">Certified artists specializing in hair styling, luxury nail art, glam makeup, and skin care rituals.</p>
  </div>
  <div class="row g-4 justify-content-center">
    <?php foreach ($team as $t): 
      $spec_lower = strtolower($t['specialization'] ?? '');
      $artist_icon = 'bi-scissors';
      $cat_tag = 'Hair Stylist';
      if (strpos($spec_lower, 'nail') !== false) {
          $artist_icon = 'bi-stars';
          $cat_tag = 'Nail Artist';
      } elseif (strpos($spec_lower, 'makeup') !== false || strpos($spec_lower, 'bridal') !== false) {
          $artist_icon = 'bi-palette';
          $cat_tag = 'Makeup Artist';
      } elseif (strpos($spec_lower, 'facial') !== false || strpos($spec_lower, 'skin') !== false) {
          $artist_icon = 'bi-emoji-smile';
          $cat_tag = 'Skin Specialist';
      } elseif (strpos($spec_lower, 'spa') !== false) {
          $artist_icon = 'bi-flower1';
          $cat_tag = 'Spa Therapist';
      }
    ?>
      <div class="col-6 col-md-4 col-lg-3">
        <div class="card card-salon text-center h-100 p-3 shadow-sm d-flex flex-column">
          <div class="team-avatar mx-auto mb-3" style="width:64px; height:64px; border-radius:50%; background:linear-gradient(135deg, #b76e79, #c9a227); color:#fff; display:flex; align-items:center; justify-content:center; font-size:1.6rem; box-shadow:0 4px 10px rgba(183,110,121,0.25);">
            <i class="bi <?= $artist_icon ?>"></i>
          </div>
          <span class="badge bg-light text-dark border mb-2 align-self-center small"><?= e($cat_tag) ?></span>
          <h6 class="fw-bold mb-1"><?= e($t['name']) ?></h6>
          <p class="text-muted small mb-3 flex-grow-1"><?= e($t['specialization'] !== null && $t['specialization'] !== '' ? $t['specialization'] : 'Beauty professional') ?></p>
          <a class="btn btn-outline-salon btn-sm rounded-pill mt-auto" href="<?= BASE_URL ?>contact.php?artist_id=<?= (int)$t['staff_id'] ?>">
            <i class="bi bi-calendar-heart me-1"></i> Book with <?= e(explode(' ', $t['name'])[0]) ?>
          </a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<!-- ================= REVIEWS ================= -->
<?php if ($reviews): ?>
<section class="pb-5">
  <h2 class="section-title text-center mb-1">What clients say<span class="dot">.</span></h2>
  <p class="text-center text-muted mb-4">Real feedback from our guest book</p>
  <div class="row g-4">
    <?php foreach ($reviews as $r): ?>
      <div class="col-md-4">
        <div class="card card-salon h-100 p-3">
          <div class="text-warning mb-2">
            <?php for ($i = 0; $i < (int)$r['rating']; $i++): ?><i class="bi bi-star-fill"></i><?php endfor; ?>
            <?php for ($i = (int)$r['rating']; $i < 5; $i++): ?><i class="bi bi-star"></i><?php endfor; ?>
          </div>
          <p class="small mb-2">&ldquo;<?= e(mb_strimwidth($r['comments'], 0, 140, '...')) ?>&rdquo;</p>
          <p class="text-muted small mb-0">&mdash; <?= e($r['name']) ?></p>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="text-center mt-4 mb-0">
    <a href="<?= BASE_URL ?>feedback.php" class="btn btn-outline-salon btn-sm">
      <i class="bi bi-chat-heart"></i> Share your own experience
    </a>
  </p>
</section>
<?php endif; ?>

<!-- ================= INFO STRIP ================= -->
<section class="pb-5">
  <div class="info-strip p-4">
    <div class="row text-center g-3">
      <div class="col-md-4">
        <i class="bi bi-geo-alt ic"></i>
        <p class="mb-0 small mt-1"><?= e(get_setting('salon_address', '')) ?></p>
      </div>
      <div class="col-md-4">
        <i class="bi bi-telephone ic"></i>
        <p class="mb-0 small mt-1"><?= e(get_setting('salon_phone', '')) ?></p>
      </div>
      <div class="col-md-4">
        <i class="bi bi-clock ic"></i>
        <p class="mb-0 small mt-1"><?= e(get_setting('opening_hours', '')) ?></p>
      </div>
    </div>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
