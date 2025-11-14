<?php
// game.php
// -------------------------------------------
// Game detail page.
// - Loads a single game by id.
// - Shows breadcrumbs.
// - Shows related games by genre.
// -------------------------------------------
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/helpers.php';

// 1) Get the current game id from the query string (default 0)
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// 2) Fetch main game row
$stmt = $conn->prepare("SELECT * FROM games WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$game = $stmt->get_result()->fetch_assoc();
$stmt->close();

// 3) Fetch related games (same genre, excluding current id)
$related = null;
if ($game) {
  $g = trim($game['genre']);
  $rel = $conn->prepare("
    SELECT id, title, image_url, average_rating
    FROM games
    WHERE id <> ? AND genre LIKE CONCAT('%', ?, '%')
    ORDER BY average_rating DESC
    LIMIT 6
  ");
  $rel->bind_param("is", $game['id'], $g);
  $rel->execute();
  $related = $rel->get_result();
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title><?= h($game['title'] ?? 'Game') ?> – GameSeerr</title>
   <?php
    // Works whether the folder is /GameSeerr, /adv-web/GameSeerr, or anything else
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';
  ?>
  <base href="<?= htmlspecialchars($base) ?>">
  <link rel="stylesheet" href="assets/css/styles.css">
  <style>
    .detail{display:grid;grid-template-columns:280px 1fr;gap:24px}
    .cover{border-radius:12px;overflow:hidden;background:#0e1620}
    .kv{background:var(--panel);padding:16px;border-radius:12px}
    .kv dt{color:var(--muted)} .kv dd{margin:0 0 10px}
  </style>
</head>
<body>
<div class="layout">
  <aside class="sidebar">
    <div class="brand">🎮 GameSeerr</div>
    <nav class="nav"><a href="index.php">← Back</a></nav>
  </aside>

  <main class="main">
    <!-- Breadcrumbs (helps "dynamic navigation" marks) -->
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
          <h1><?= h($game['title']) ?></h1>

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
