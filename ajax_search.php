<?php
// ajax_search.php
// -----------------------------------------------
// Returns ONLY the cards HTML for the grid.
// Called by jQuery $.ajax from index.php.
// Accepts: q (search), genre (filter), tab (for ORDER BY / wishlist).
// -----------------------------------------------
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/auth.php';

// 1) Read inputs (GET)
$q     = trim($_GET['q'] ?? '');
$genre = trim($_GET['genre'] ?? '');
$tab   = $_GET['tab'] ?? 'home';

// 2) Sort order logic (same as index.php)
switch ($tab) {
  case 'top':
    $order = "average_rating DESC";
    break;
  case 'new':
  case 'upcoming':
    $order = "release_date DESC, id DESC";
    break;
  case 'trending':
    $order = "id DESC";
    break;
  case 'wish':
    $order = "id DESC"; // no wishlist column
    break;
  default:
    $order = "id DESC";
}

$baseSql = "SELECT id,title,genre,platform,average_rating,image_url FROM games";

// Are we on the wishlist tab AND logged in?
$wishlistOnly = ($tab === 'wish' && is_logged_in());
$uid          = $wishlistOnly ? current_user_id() : null;

// 3) Build prepared statement based on q + genre (+wishlist)
if ($q !== '') {
    // There is a search term
    $like = "%{$q}%";

    if ($genre !== '') {
        // Search + genre filter
        if ($wishlistOnly) {
            $stmt = $conn->prepare("$baseSql
              WHERE (title LIKE ? OR genre LIKE ? OR platform LIKE ? OR description LIKE ?)
                AND genre LIKE CONCAT('%', ?, '%')
                AND id IN (SELECT game_id FROM wishlist WHERE user_id=?)
              ORDER BY $order");
            $stmt->bind_param("sssssi", $like, $like, $like, $like, $genre, $uid);
        } else {
            $stmt = $conn->prepare("$baseSql
              WHERE (title LIKE ? OR genre LIKE ? OR platform LIKE ? OR description LIKE ?)
                AND genre LIKE CONCAT('%', ?, '%')
              ORDER BY $order");
            $stmt->bind_param("sssss", $like, $like, $like, $like, $genre);
        }
    } else {
        // Search only
        if ($wishlistOnly) {
            $stmt = $conn->prepare("$baseSql
              WHERE (title LIKE ? OR genre LIKE ? OR platform LIKE ? OR description LIKE ?)
                AND id IN (SELECT game_id FROM wishlist WHERE user_id=?)
              ORDER BY $order");
            $stmt->bind_param("ssssi", $like, $like, $like, $like, $uid);
        } else {
            $stmt = $conn->prepare("$baseSql
              WHERE (title LIKE ? OR genre LIKE ? OR platform LIKE ? OR description LIKE ?)
              ORDER BY $order");
            $stmt->bind_param("ssss", $like, $like, $like, $like);
        }
    }
} else {
    // No search term
    if ($genre !== '') {
        // Genre filter only
        if ($wishlistOnly) {
            $stmt = $conn->prepare("$baseSql
              WHERE genre LIKE CONCAT('%', ?, '%')
                AND id IN (SELECT game_id FROM wishlist WHERE user_id=?)
              ORDER BY $order");
            $stmt->bind_param("si", $genre, $uid);
        } else {
            $stmt = $conn->prepare("$baseSql
              WHERE genre LIKE CONCAT('%', ?, '%')
              ORDER BY $order");
            $stmt->bind_param("s", $genre);
        }
    } else {
        // No search, no genre filter
        if ($wishlistOnly) {
            $stmt = $conn->prepare("$baseSql
              WHERE id IN (SELECT game_id FROM wishlist WHERE user_id=?)
              ORDER BY $order");
            $stmt->bind_param("i", $uid);
        } else {
            // Plain home / other tabs
            $stmt = $conn->prepare("$baseSql ORDER BY $order");
        }
    }
}

$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

// 4) Build wishlist id set for current user (for hearts)
$wishlistIds = [];
if (is_logged_in()) {
  $uid = current_user_id();
  $w = $conn->prepare('SELECT game_id FROM wishlist WHERE user_id=?');
  $w->bind_param('i', $uid);
  $w->execute();
  $wr = $w->get_result();
  while ($row = $wr->fetch_assoc()) {
    $wishlistIds[(int)$row['game_id']] = true;
  }
  $w->close();
}

// 5) Emit just the <a class="card">…</a> items (same structure as index.php)
if ($res && $res->num_rows) {
  while ($g = $res->fetch_assoc()) {
    $gameId   = (int)$g['id'];
    $inWish   = isset($wishlistIds[$gameId]);
    $heartCls = $inWish ? 'heart-btn is-active' : 'heart-btn';
    ?>
    <a data-card class="card" href="game.php?id=<?= $gameId ?>">
      <img src="<?= h($g['image_url']) ?>"
           alt="<?= h($g['title']) ?>"
           onerror="this.src='assets/img/placeholder.webp'">

      <?php if (is_logged_in()): ?>
        <button type="button"
                class="<?= $heartCls ?>"
                data-game-id="<?= $gameId ?>"
                aria-label="Toggle wishlist">
          <i class="fa-solid fa-heart"></i>
        </button>
      <?php endif; ?>

      <div class="meta">
        <div class="title"><?= h($g['title']) ?></div>
        <div class="badge"><?= h($g['genre']) ?> · <?= h($g['platform']) ?></div>
        <div class="bar">
          <div class="fill" style="width:<?= rating_fill($g['average_rating']) ?>"></div>
        </div>
      </div>
    </a>
    <?php
  }
} else {
  echo '<p>No games found.</p>';
}
