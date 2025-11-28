<?php
// profile.php
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/auth.php';

if (!is_logged_in()) {
    header('Location: auth_login.php');
    exit;
}

$userId = current_user_id();
$tab = ''; // no sidebar item active for this page

// Load user row
$stmt = $conn->prepare("
    SELECT username, email, created_at, avatar_url
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Load user reviews + game info
$reviewsStmt = $conn->prepare("
    SELECT r.id         AS review_id,
           r.rating,
           r.body,
           r.created_at,
           g.id         AS game_id,
           g.title,
           g.image_url
    FROM reviews r
    JOIN games g ON r.game_id = g.id
    WHERE r.user_id = ?
    ORDER BY r.created_at DESC
");
$reviewsStmt->bind_param('i', $userId);
$reviewsStmt->execute();
$reviewsRes = $reviewsStmt->get_result();
$reviewsStmt->close();

// Load wishlist games
$wishStmt = $conn->prepare("
    SELECT g.id, g.title, g.genre, g.platform, g.image_url, g.average_rating
    FROM wishlist w
    JOIN games g ON w.game_id = g.id
    WHERE w.user_id = ?
    ORDER BY g.title ASC
");
$wishStmt->bind_param('i', $userId);
$wishStmt->execute();
$wishRes = $wishStmt->get_result();
$wishStmt->close();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Your profile - GameSeerr</title>
  <?php $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/'; ?>
  <base href="<?= htmlspecialchars($base) ?>">

  <link rel="stylesheet" href="assets/css/styles.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
</head>
<body>

<div class="layout">

  <!-- SIDEBAR (same as index.php) -->
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

  <!-- RIGHT SIDE -->
  <div class="main-column">
    <main class="main">
      <div class="main-wrap">

        <!-- PROFILE HEADER -->
        <section class="profile-header">
            <?php if (!empty($user['avatar_url'])): ?>
                <div class="profile-avatar-big">
                <img src="<?= h($user['avatar_url']) ?>"
                    alt="<?= h($user['username']) ?>"
                    onerror="this.src='assets/img/placeholder.webp'">
                </div>
            <?php else: ?>
                <div class="profile-avatar-big avatar-circle">
                <?= strtoupper($user['username'][0] ?? 'U') ?>
                </div>
            <?php endif; ?>

            <div class="profile-header-text">
                <h1>Your profile</h1>
                <div class="profile-username"><?= h($user['username']) ?></div>
                <div class="profile-email"><?= h($user['email']) ?></div>
                <div class="profile-joined">
                Member since <?= date('F j, Y', strtotime($user['created_at'])) ?>
                </div>

                <div class="profile-header-actions">
                <a href="profile_edit.php" class="btn-sm-outline">Edit profile</a>
                </div>
            </div>
        </section>


        <!-- MY REVIEWS -->
        <section class="profile-section">
          <h2>My reviews</h2>

          <?php if ($reviewsRes->num_rows): ?>
            <div class="profile-review-list">
              <?php while ($r = $reviewsRes->fetch_assoc()): ?>
                <article class="profile-review-row">
                  <a href="game.php?id=<?= (int)$r['game_id'] ?>" class="profile-review-thumb">
                    <img src="<?= h($r['image_url']) ?>"
                         alt="<?= h($r['title']) ?>"
                         onerror="this.src='assets/img/placeholder.webp'">
                  </a>

                  <div class="profile-review-main">
                    <div class="profile-review-top">
                      <a href="game.php?id=<?= (int)$r['game_id'] ?>" class="profile-review-title">
                        <?= h($r['title']) ?>
                      </a>
                      <span class="profile-review-score"><?= (int)$r['rating'] ?>/10</span>
                    </div>

                    <div class="profile-review-meta">
                      <?= date('M j, Y', strtotime($r['created_at'])) ?>
                    </div>

                    <p class="profile-review-body">
                      <?= nl2br(h($r['body'])) ?>
                    </p>

                    <div class="profile-review-actions">
                        <!-- Edit: just go to the game page -->
                        <a href="game.php?id=<?= (int)$r['game_id'] ?>#your-review"
                            class="btn-sm-outline">
                            Edit
                        </a>

                        <!-- Delete: reuse the same POST pattern as on game.php -->
                        <form method="post"
                                action="review_delete.php"
                                class="inline-form"
                                onsubmit="return confirm('Delete this review?');">
                            <input type="hidden" name="review_id" value="<?= (int)$r['review_id'] ?>">
                            <input type="hidden" name="game_id"  value="<?= (int)$r['game_id'] ?>">
                            <button type="submit" class="btn-sm-outline danger">
                            Delete
                            </button>
                        </form>
                    </div>

                  </div>
                </article>
              <?php endwhile; ?>
            </div>
          <?php else: ?>
            <p>You haven’t written any reviews yet.</p>
          <?php endif; ?>
        </section>

        <!-- MY WISHLIST -->
        <section class="profile-section">
          <h2>My wishlist</h2>

          <?php if ($wishRes->num_rows): ?>
            <div class="grid">
              <?php while ($g = $wishRes->fetch_assoc()): ?>
                <?php
                  $gameId = (int)$g['id'];
                  $score  = (float)$g['average_rating'];
                  $barClass = 'bar';
                  if ($score >= 80)      $barClass .= ' bar-good';
                  elseif ($score >= 50)  $barClass .= ' bar-mid';
                  else                   $barClass .= ' bar-bad';
                ?>
                <a data-card class="card" href="game.php?id=<?= $gameId ?>">
                  <img src="<?= h($g['image_url']) ?>"
                       onerror="this.src='assets/img/placeholder.webp'"
                       alt="<?= h($g['title']) ?>">

                  <button class="heart-btn is-active" data-game-id="<?= $gameId ?>">
                    <i class="fa-solid fa-heart"></i>
                  </button>

                  <div class="meta">
                    <div class="title"><?= h($g['title']) ?></div>
                    <div class="badge"><?= h($g['genre']) ?> · <?= h($g['platform']) ?></div>
                    <?php if ($score > 0): ?>
                      <div class="<?= $barClass ?>">
                        <div class="fill" style="width:<?= rating_fill($score) ?>"></div>
                      </div>
                    <?php endif; ?>
                  </div>
                </a>
              <?php endwhile; ?>
            </div>
          <?php else: ?>
            <p>No games on your wishlist yet.</p>
          <?php endif; ?>
        </section>

      </div>
    </main>

    <!-- reuse same footer if you like, or skip on profile -->
  </div>
</div>

<script>
  // Reuse wishlist toggle on profile, same as index.php
  $(document).on('click', '.heart-btn', function(e){
    e.preventDefault();
    e.stopPropagation();
    const btn = $(this);
    $.post('wishlist_toggle.php', {game_id: btn.data('game-id')}, resp => {
      if (!resp) return;
      if (resp.status === 'removed') {
        btn.closest('[data-card]').fadeOut(200, function(){ $(this).remove(); });
      } else if (resp.status === 'login') {
        window.location = 'auth_login.php';
      }
    }, 'json');
  });
</script>
</body>
</html>
