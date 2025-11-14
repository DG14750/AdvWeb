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
<link rel="stylesheet" href="assets/css/styles.css">
<link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
    .detail {display:grid;grid-template-columns:280px 1fr;gap:24px}
    .cover{border-radius:12px;overflow:hidden;background:#0e1620}
    .kv{background:var(--panel);padding:16px;border-radius:12px}
    .kv dt{color:var(--muted)} .kv dd{margin:0 0 10px}

    /* nicer layout for title + wishlist button */
    .detail-header {
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:16px;
    }
    .detail-header h1 { margin:0; }

    /* reuse existing heart styles */
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
  <aside class="sidebar">
    <div class="brand">🎮 GameSeerr</div>
    <nav class="nav"><a href="index.php">← Back</a></nav>
  </aside>

  <main class="main">

    <!-- Breadcrumb -->
    <nav style="font-size:14px;color:var(--muted);margin:0 0 12px">
      <a href="index.php" style="color:var(--text)">Home</a>
      <span style="opacity:.6"> / </span>
      <a href="index.php?tab=home" style="color:var(--text)">Games</a>
      <span style="opacity:.6"> / </span>
      <span><?= h($game['title'] ?? 'Game') ?></span>
    </nav>

    <?php if (!$game): ?>
      <p>Sorry game not found.</p>

    <?php else: ?>
      <div class="detail">

        <div>
          <img class="cover" src="<?= h($game['image_url']) ?>" alt="<?= h($game['title']) ?>">
        </div>

        <div>

          <!-- title + wishlist button -->
          <div class="detail-header">
            <h1><?= h($game['title']) ?></h1>

            <?php if (is_logged_in()): ?>
              <button id="detail-heart"
                      class="heart-btn <?= $isInWishlist ? 'is-active' : '' ?>"
                      data-game-id="<?= $id ?>"
                      aria-label="Toggle Wishlist">
                <i class="fa-solid fa-heart"></i>
              </button>
            <?php endif; ?>
          </div>

          <div class="bar" style="max-width:360px">
            <div class="fill" style="width:<?= rating_fill($game['average_rating']) ?>"></div>
          </div>

          <p style="color:var(--muted)">
            <?= h($game['genre']) ?> · <?= h($game['platform']) ?> · <?= h($game['release_date']) ?>
          </p>

          <?php if (!empty($game['description'])): ?>
            <p><?= nl2br(h($game['description'])) ?></p>
          <?php endif; ?>

          <dl class="kv">
            <dt>Average Rating</dt><dd><?= round($game['average_rating']) ?>%</dd>
            <dt>Platforms</dt><dd><?= h($game['platform']) ?></dd>
            <dt>Genre</dt><dd><?= h($game['genre']) ?></dd>
            <dt>Release Date</dt><dd><?= h($game['release_date']) ?></dd>
          </dl>

          <?php if (!empty($game['steam_app_id'])): ?>
            <p style="margin-top:12px">
              <a href="https://store.steampowered.com/app/<?= (int)$game['steam_app_id'] ?>/"
                 target="_blank" rel="noopener"
                 class="btn"
                 style="display:inline-block;background:#2b7dfa;padding:10px 14px;border-radius:10px;color:#fff">
                View on Steam
              </a>
            </p>
          <?php endif; ?>

        </div>
      </div>
    <?php endif; ?>

    <?php if ($related && $related->num_rows): ?>
      <h2 style="margin:28px 0 12px">Related in <?= h($game['genre']) ?></h2>
      <section class="grid" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px">
        <?php while($r = $related->fetch_assoc()): ?>
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
              <div class="bar" style="height:8px;margin-top:8px">
                <div class="fill" style="width:<?= rating_fill($r['average_rating']) ?>"></div>
              </div>
            </div>
          </a>
        <?php endwhile; ?>
      </section>
    <?php endif; ?>

  </main>
</div>

</body>
</html>
