<?php
// ajax_search.php
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/helpers.php';

// 1) Inputs
$q     = trim($_GET['q'] ?? '');
$genre = trim($_GET['genre'] ?? '');
$tab   = $_GET['tab'] ?? 'home';

// 2) Sort order
switch ($tab) {
  case 'top':       $order = "average_rating DESC"; break;
  case 'new':
  case 'upcoming':  $order = "release_year DESC, id DESC"; break;
  case 'trending':  $order = "id DESC"; break;
  case 'wish':      $order = "id DESC"; break; 
  default:          $order = "id DESC";
}

$baseSql = "SELECT id,title,genre,platform,average_rating,image_url FROM games";

// 3) Query (prepared)
if ($q !== '') {
  if ($genre !== '') {
    $stmt = $conn->prepare("$baseSql
      WHERE (title LIKE ? OR genre LIKE ? OR platform LIKE ? OR description LIKE ?)
        AND genre LIKE CONCAT('%', ?, '%')
      ORDER BY $order");
    $like = "%{$q}%";
    $stmt->bind_param("sssss", $like, $like, $like, $like, $genre);
  } else {
    $stmt = $conn->prepare("$baseSql
      WHERE title LIKE ? OR genre LIKE ? OR platform LIKE ? OR description LIKE ?
      ORDER BY $order");
    $like = "%{$q}%";
    $stmt->bind_param("ssss", $like, $like, $like, $like);
  }
} else {
  if ($genre !== '') {
    $stmt = $conn->prepare("$baseSql
      WHERE genre LIKE CONCAT('%', ?, '%')
      ORDER BY $order");
    $stmt->bind_param("s", $genre);
  } else {
    $stmt = $conn->prepare("$baseSql ORDER BY $order");
  }
}

$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

// 4) Emit only the cards (RELATIVE paths)
if ($res && $res->num_rows) {
  while ($g = $res->fetch_assoc()) {

    // normalise image path:
    $img = $g['image_url'] ?? '';
    if (strpos($img, 'http://') !== 0 && strpos($img, 'https://') !== 0) {
      $img = ltrim($img, '/'); // e.g. "assets/covers/xyz.webp"
    }
    ?>
    <a data-card class="card" href="game.php?id=<?= (int)$g['id'] ?>">
      <img src="<?= h($img) ?>"
           alt="<?= h($g['title']) ?>"
           onerror="this.src='assets/img/placeholder.webp'">
      <div class="meta">
        <div class="title"><?= h($g['title']) ?></div>
        <div class="badge"><?= h($g['genre']) ?> · <?= h($g['platform']) ?></div>
        <div class="bar"><div class="fill" style="width:<?= rating_fill($g['average_rating']) ?>"></div></div>
      </div>
    </a>
    <?php
  }
} else {
  echo '<p>No games found.</p>';
}
