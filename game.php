<?php
// game.php
// -------------------------------------------
// Game detail page.
// -------------------------------------------
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/auth.php';

// 1) Get the current game id
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Not sure if its beeter to highlight "Home" or no tab at all
// $tab = 'home';

// 2) Fetch game row
$stmt = $conn->prepare("SELECT * FROM games WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$game = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Detect wishlist status
$isInWishlist = false;
if ($game && is_logged_in()) {
    $uid = current_user_id();
    $w = $conn->prepare("SELECT 1 FROM wishlist WHERE user_id=? AND game_id=? LIMIT 1");
    $w->bind_param("ii", $uid, $id);
    $w->execute();
    $w->store_result();
    $isInWishlist = $w->num_rows > 0;
    $w->close();
}

// 3) Fetch related games (same genre, excluding current id)
$related = null;
if ($game) {
    // Use primary genre term so matching is symmetric
    $genreTokens   = preg_split('/\s*,\s*/', $game['genre']);
    $primaryGenre  = trim($genreTokens[0] ?? '');

    $rel = $conn->prepare("
        SELECT id, title, image_url, average_rating
        FROM games
        WHERE id <> ? AND genre LIKE CONCAT('%', ?, '%')
        ORDER BY average_rating DESC
        LIMIT 6
    ");
    $rel->bind_param("is", $game['id'], $primaryGenre);
    $rel->execute();
    $related = $rel->get_result();
    $rel->close();
}

// 4) Fetch reviews for this game
$reviews = null;
if ($game) {
    $rev = $conn->prepare("
        SELECT r.id, r.rating, r.body, r.created_at, u.username
        FROM reviews r
        JOIN users u ON u.id = r.user_id
        WHERE r.game_id = ?
        ORDER BY r.created_at DESC
    ");
    $rev->bind_param('i', $id);
    $rev->execute();
    $reviews = $rev->get_result();
    $rev->close();
}

// 4b) Current user's review (if logged in)
$userReview = null;
if ($game && is_logged_in()) {
    $uid = current_user_id();
    $ur = $conn->prepare("
        SELECT id, rating, body, created_at
        FROM reviews
        WHERE game_id = ? AND user_id = ?
        LIMIT 1
    ");
    $ur->bind_param('ii', $id, $uid);
    $ur->execute();
    $userReview = $ur->get_result()->fetch_assoc() ?: null;
    $ur->close();
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title><?= h($game['title'] ?? 'Game') ?> – GameSeerr</title>

<?php
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';
?>
<base href="<?= htmlspecialchars($base) ?>">

<link rel="stylesheet" href="assets/css/styles.css">
<link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
  .detail {
    display:grid;
    grid-template-columns:280px 1fr;
    gap:24px;
  }
  .cover{
    border-radius:12px;
    overflow:hidden;
    background:#0e1620;
  }
  .kv{
    background:var(--panel);
    padding:16px;
    border-radius:12px;
  }
  .kv dt{color:var(--muted)}
  .kv dd{margin:0 0 10px}

  .detail-header {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
  }
  .detail-header h1 { margin:0; }

  .heart-btn {
    position: relative;
    background:none;
    border:none;
    cursor:pointer;
    font-size: 26px;
    color:#666;
  }
  .heart-btn.is-active { color:#e63946; }
</style>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<!-- Wishlist JS -->
<script>
$(function () {
    $('#detail-heart').on('click', function(e) {
        e.preventDefault();

        const $btn = $(this);
        const gameId = $btn.data('game-id');

        $.post('wishlist_toggle.php', { game_id: gameId }, function(resp) {
            if (!resp) return;

            if (resp.status === 'added') {
                $btn.addClass('is-active');
            } else if (resp.status === 'removed') {
                $btn.removeClass('is-active');
            } else if (resp.status === 'login') {
                window.location.href = 'auth_login.php';
            }
        }, 'json').fail(function() {
            alert('Sorry, could not update your wishlist.');
        });
    });
});
</script>

</head>
<body>

<div class="layout">
  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="brand">
      <div class="brand-icon">
        <i class="fa-solid fa-gamepad"></i>
      </div>
      <div class="brand-text">
        <div class="brand-title">GameSeerr</div>
        <div class="brand-sub">Discover games</div>
      </div>
    </div>

    <nav class="nav">
      <a class="<?= $tab === 'home'      ? 'active' : '' ?>" href="index.php">
        <span class="nav-icon"><i class="fa-solid fa-house"></i></span>
        <span class="nav-label">Home</span>
      </a>
      <a class="<?= $tab === 'upcoming'  ? 'active' : '' ?>" href="index.php?tab=upcoming">
        <span class="nav-icon"><i class="fa-regular fa-calendar"></i></span>
        <span class="nav-label">Upcoming</span>
      </a>
      <a class="<?= $tab === 'top'       ? 'active' : '' ?>" href="index.php?tab=top">
        <span class="nav-icon"><i class="fa-solid fa-star"></i></span>
        <span class="nav-label">Top Rated</span>
      </a>
      <a class="<?= $tab === 'new'       ? 'active' : '' ?>" href="index.php?tab=new">
        <span class="nav-icon"><i class="fa-solid fa-bolt"></i></span>
        <span class="nav-label">Newest</span>
      </a>
      <a class="<?= $tab === 'wish'      ? 'active' : '' ?>" href="index.php?tab=wish">
        <span class="nav-icon"><i class="fa-regular fa-heart"></i></span>
        <span class="nav-label">Wishlist</span>
      </a>
    </nav>

    <div class="sidebar-auth">
      <?php if (is_logged_in()): ?>
        <a href="auth_logout.php" class="auth-link">
          <i class="fa-solid fa-right-from-bracket"></i>
          <span>Log out</span>
        </a>
      <?php else: ?>
        <a href="auth_login.php" class="auth-link">
          <i class="fa-regular fa-circle-user"></i>
          <span>Log in</span>
        </a>
        <a href="auth_signup.php" class="auth-link secondary">
          <i class="fa-solid fa-user-plus"></i>
          <span>Sign up</span>
        </a>
      <?php endif; ?>
    </div>

    <div class="sidebar-footer-pill">
      <div class="pill-main">GameSeerr</div>
      <div class="pill-sub">Adv Web Project</div>
    </div>
  </aside>

  <!-- Main column (content + footer) -->
  <div class="main-column">

    <main class="main">

      <!-- Breadcrumb -->
      <nav style="font-size:14px;color:var(--muted);margin:0 0 12px">
        <a href="index.php" style="color:var(--text)">Home</a>
        <span style="opacity:.6"> / </span>
        <a href="index.php?tab=home" style="color:var(--text)">Games</a>
        <span style="opacity:.6"> / </span>
        <span><?= h($game['title'] ?? 'Game') ?></span>
      </nav>

      <?php if (!$game) { ?>
        <p>Sorry game not found.</p>
      <?php } else { ?>

        <?php
          // coloured bar for this game's average rating
          $detailScore    = (float)$game['average_rating'];
          $detailBarClass = 'bar';
          if ($detailScore >= 80) {
              $detailBarClass .= ' bar-good';
          } elseif ($detailScore >= 50) {
              $detailBarClass .= ' bar-mid';
          } else {
              $detailBarClass .= ' bar-bad';
          }
        ?>

        <div class="detail">

          <div>
            <img class="cover" src="<?= h($game['image_url']) ?>" alt="<?= h($game['title']) ?>">
          </div>

          <div>
            <div class="detail-header">
              <h1><?= h($game['title']) ?></h1>

              <?php if (is_logged_in()) { ?>
                <button id="detail-heart"
                        class="heart-btn <?= $isInWishlist ? 'is-active' : '' ?>"
                        data-game-id="<?= $id ?>"
                        aria-label="Toggle Wishlist">
                  <i class="fa-solid fa-heart"></i>
                </button>
              <?php } ?>
            </div>

            <div class="<?= $detailBarClass ?>" style="max-width:360px">
              <div class="fill" style="width:<?= rating_fill($detailScore) ?>"></div>
            </div>

            <p style="color:var(--muted)">
              <?= h($game['genre']) ?> · <?= h($game['platform']) ?> · <?= h($game['release_date']) ?>
            </p>

            <?php if (!empty($game['description'])) { ?>
              <p><?= nl2br(h($game['description'])) ?></p>
            <?php } ?>

            <dl class="kv">
              <dt>Average Rating</dt><dd><?= round($game['average_rating']) ?>%</dd>
              <dt>Platforms</dt><dd><?= h($game['platform']) ?></dd>
              <dt>Genre</dt><dd><?= h($game['genre']) ?></dd>
              <dt>Release Date</dt><dd><?= h($game['release_date']) ?></dd>
            </dl>

            <?php if (!empty($game['steam_app_id'])) { ?>
              <p style="margin-top:12px">
                <a href="https://store.steampowered.com/app/<?= (int)$game['steam_app_id'] ?>/"
                   target="_blank" rel="noopener"
                   class="btn"
                   style="display:inline-block;background:#2b7dfa;padding:10px 14px;border-radius:10px;color:#fff">
                  View on Steam
                </a>
              </p>
            <?php } ?>

          </div>
        </div>
      <?php } ?>

      <?php if ($game) { ?>
        <!-- Reviews -->
        <section id="reviews" class="reviews-section">
          <h2 class="section-title">Player reviews</h2>

          <?php if ($reviews && $reviews->num_rows) { ?>
            <div class="reviews-list">
              <?php while ($r = $reviews->fetch_assoc()) { ?>
                <?php
                  $isOwn      = ($userReview && $userReview['id'] == $r['id']);
                  $rating10   = (int)$r['rating'];
                  $ratingClass = 'review-rating';
                  if ($rating10 >= 8) {
                      $ratingClass .= ' good';
                  } elseif ($rating10 >= 5) {
                      $ratingClass .= ' ok';
                  } else {
                      $ratingClass .= ' bad';
                  }
                ?>

                <article class="review-card <?= $isOwn ? 'review-card-own' : '' ?>">
                  <header class="review-header">
                    <div class="review-user">
                      <div class="review-avatar">
                        <?= strtoupper(substr($r['username'], 0, 1)) ?>
                      </div>
                      <div>
                        <div class="review-username">
                          <?= h($r['username']) ?>
                          <?php if ($isOwn) { ?>
                            <span class="review-badge-own">Your review</span>
                          <?php } ?>
                        </div>
                        <div class="review-date">
                          <?= date('M j, Y', strtotime($r['created_at'])) ?>
                        </div>
                      </div>
                    </div>

                    <div class="review-header-right">
                      <div class="<?= $ratingClass ?>">
                        <?= $rating10 ?>/10
                      </div>

                      <?php if ($isOwn) { ?>
                        <div class="review-actions">
                          <button type="button" class="btn-sm-outline btn-edit-own">Edit</button>

                          <form action="review_delete.php" method="post" class="inline-form"
                                onsubmit="return confirm('Delete your review?');">
                            <input type="hidden" name="review_id" value="<?= (int)$r['id'] ?>">
                            <input type="hidden" name="game_id" value="<?= (int)$id ?>">
                            <button type="submit" class="btn-sm-outline danger">Delete</button>
                          </form>
                        </div>
                      <?php } ?>
                    </div>
                  </header>

                  <p class="review-body"><?= nl2br(h($r['body'])) ?></p>
                </article>
              <?php } ?>
            </div>
          <?php } else { ?>
            <p class="reviews-empty">No reviews yet. Be the first to share your thoughts!</p>
          <?php } ?>

          <div class="review-form-wrap<?= $userReview ? ' is-edit-form' : '' ?>" id="edit-review">
            <?php if (is_logged_in()) { ?>
              <?php if ($userReview) { ?>
                <!-- Edit existing review -->
                <h3>Edit your review</h3>
                <form class="review-form" action="review_submit.php" method="post">
                  <input type="hidden" name="game_id" value="<?= (int)$id ?>">
                  <input type="hidden" name="review_id" value="<?= (int)$userReview['id'] ?>">

                  <label class="review-label">
                    Rating
                    <select name="rating" required>
                      <option value="">Select...</option>
                      <?php for ($i = 10; $i >= 1; $i--) { ?>
                        <option value="<?= $i ?>" <?= ($userReview['rating'] == $i ? 'selected' : '') ?>>
                          <?= $i ?>/10
                        </option>
                      <?php } ?>
                    </select>
                  </label>

                  <label class="review-label">
                    Your review
                    <textarea name="body" rows="4" maxlength="2000" required><?= h($userReview['body']) ?></textarea>
                  </label>

                  <button type="submit" class="btn-primary">Save changes</button>
                </form>
              <?php } else { ?>
                <!-- New review -->
                <h3>Write a review</h3>
                <form class="review-form" action="review_submit.php" method="post">
                  <input type="hidden" name="game_id" value="<?= (int)$id ?>">

                  <label class="review-label">
                    Rating
                    <select name="rating" required>
                      <option value="">Select...</option>
                      <?php for ($i = 10; $i >= 1; $i--) { ?>
                        <option value="<?= $i ?>"><?= $i ?>/10</option>
                      <?php } ?>
                    </select>
                  </label>

                  <label class="review-label">
                    Your review
                    <textarea name="body" rows="4" maxlength="2000"
                              placeholder="What did you like or dislike?" required></textarea>
                  </label>

                  <button type="submit" class="btn-primary">Submit review</button>
                </form>
              <?php } ?>
            <?php } else { ?>
              <p class="reviews-login-hint">
                <a href="auth_login.php">Log in</a> or <a href="auth_signup.php">sign up</a> to write a review.
              </p>
            <?php } ?>
          </div>
        </section>
      <?php } ?>

      <!-- Related games -->
      <?php if ($related && $related->num_rows) { ?>
        <h2 style="margin:28px 0 12px">Related in <?= h($game['genre']) ?></h2>
        <section class="grid" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px">
          <?php while ($r = $related->fetch_assoc()) { ?>
            <?php
              $relScore    = (float)$r['average_rating'];
              $relBarClass = 'bar';
              if ($relScore >= 80) {
                  $relBarClass .= ' bar-good';
              } elseif ($relScore >= 50) {
                  $relBarClass .= ' bar-mid';
              } else {
                  $relBarClass .= ' bar-bad';
              }
            ?>
            <a class="card" href="game.php?id=<?= (int)$r['id'] ?>"
               style="background:var(--panel);border-radius:12px;overflow:hidden">
              <img src="<?= h($r['image_url']) ?>"
                   alt="<?= h($r['title']) ?>"
                   style="width:100%;aspect-ratio:2/3;object-fit:cover"
                   onerror="this.src='assets/img/placeholder.webp'">
              <div class="meta" style="padding:8px 10px">
                <div class="title" style="font-weight:600;font-size:14px;line-height:1.2">
                  <?= h($r['title']) ?>
                </div>
                <div class="<?= $relBarClass ?>" style="height:8px;margin-top:8px">
                  <div class="fill" style="width:<?= rating_fill($relScore) ?>"></div>
                </div>
              </div>
            </a>
          <?php } ?>
        </section>
      <?php } ?>

      <!-- FOOTER -->
      <footer class="footer">
        <div class="footer-inner">

          <div class="footer-brand">
            <div class="footer-icon"><i class="fa-solid fa-gamepad"></i></div>
            <div class="footer-brand-text">
              <div class="footer-title">GameSeerr</div>
              <div class="footer-sub">A student project</div>
            </div>
          </div>

          <div class="footer-links-group">
            <div class="footer-links-title">Explore</div>
            <a href="index.php?tab=top">Top rated</a>
            <a href="index.php?tab=new">Newest</a>
          </div>

          <div class="footer-links-group">
            <div class="footer-links-title">Account</div>
            <?php if (is_logged_in()) { ?>
              <a href="auth_logout.php">Log out</a>
            <?php } else { ?>
              <a href="auth_login.php">Log in</a>
              <a href="auth_signup.php">Sign up</a>
            <?php } ?>
          </div>

          <div class="footer-social">
            <a href="https://twitter.com" target="_blank" rel="noopener">
              <i class="fa-brands fa-twitter"></i>
            </a>
            <a href="https://facebook.com" target="_blank" rel="noopener">
              <i class="fa-brands fa-facebook"></i>
            </a>
            <a href="https://instagram.com" target="_blank" rel="noopener">
              <i class="fa-brands fa-instagram"></i>
            </a>
            <a href="https://youtube.com" target="_blank" rel="noopener">
              <i class="fa-brands fa-youtube"></i>
            </a>
            <a href="https://tiktok.com" target="_blank" rel="noopener">
              <i class="fa-brands fa-tiktok"></i>
            </a>
          </div>

          <div class="footer-pill">
            <div>GameSeerr v1.0</div>
            <div class="footer-pill-sub">Advanced Web Project</div>
          </div>

        </div>
      </footer>

    </main>
  </div><!-- /.main-column -->
</div><!-- /.layout -->

<!-- Edit / Cancel toggle for own review -->
<script>
document.addEventListener('DOMContentLoaded', function () {
  const editBtn   = document.querySelector('.btn-edit-own');
  const editPanel = document.getElementById('edit-review');

  if (!editBtn || !editPanel) return;

  let open = false;

  editBtn.addEventListener('click', function (e) {
    e.preventDefault();
    open = !open;

    if (open) {
      editPanel.style.display = 'block';
      editBtn.textContent = 'Cancel';

      const rectTop = editPanel.getBoundingClientRect().top + window.scrollY - 80;
      window.scrollTo({ top: rectTop, behavior: 'smooth' });
    } else {
      editPanel.style.display = 'none';
      editBtn.textContent = 'Edit';
    }
  });
});
</script>
</body>
</html>
