<?php
// index.php
// -----------------------------
// Home/listing page.
// - Supports server-side search & genre filtering (for no-JS users)
// - Enhances with jQuery AJAX live-search (updates the grid without reload)
// - Shows "Welcome, username" when logged in.
// -----------------------------

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/auth.php';

// 1) Decide default ORDER BY based on tab
$tab = $_GET['tab'] ?? 'home';
switch ($tab) {
  case 'top':       $order = "average_rating DESC"; break;
  case 'new':
  case 'upcoming':  $order = "release_year DESC, id DESC"; break;
  case 'trending':  $order = "id DESC"; break;  // placeholder
  case 'fav':       $order = "id DESC"; break;  // placeholder (future auth feature)
  default:          $order = "id DESC";
}

// 2) Build a simple distinct genre list (for the <select> filter)
$genresRes = $conn->query("SELECT DISTINCT genre FROM games ORDER BY genre ASC");
$genreSet = [];
while ($row = $genresRes->fetch_assoc()) {
  foreach (preg_split('/\s*,\s*/', $row['genre']) as $g) {
    if ($g !== '') $genreSet[$g] = true;
  }
}
$genres = array_keys($genreSet);
sort($genres, SORT_NATURAL | SORT_FLAG_CASE);

// 3) Read query params for server-side fallback rendering
$q         = trim($_GET['q'] ?? '');           // search text (title/genre/platform/desc)
$selGenre  = trim($_GET['genre'] ?? '');       // genre filter
$baseSql   = "SELECT id,title,genre,platform,average_rating,image_url FROM games";

// 4) Server-side data (for initial load / no-JS). AJAX to replace grid later potentionally
if ($q !== '') {
  // Search across fields
  if ($selGenre !== '') {
    $stmt = $conn->prepare("$baseSql
      WHERE (title LIKE ? OR genre LIKE ? OR platform LIKE ? OR description LIKE ?)
        AND genre LIKE CONCAT('%', ?, '%')
      ORDER BY $order");
    $like = "%{$q}%";
    $stmt->bind_param("sssss", $like, $like, $like, $like, $selGenre);
  } else {
    $stmt = $conn->prepare("$baseSql
      WHERE title LIKE ? OR genre LIKE ? OR platform LIKE ? OR description LIKE ?
      ORDER BY $order");
    $like = "%{$q}%";
    $stmt->bind_param("ssss", $like, $like, $like, $like);
  }
  $stmt->execute();
  $res = $stmt->get_result();
  $stmt->close();
} else {
  if ($selGenre !== '') {
    $stmt = $conn->prepare("$baseSql
      WHERE genre LIKE CONCAT('%', ?, '%')
      ORDER BY $order");
    $stmt->bind_param("s", $selGenre);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
  } else {
    $res = $conn->query("$baseSql ORDER BY $order");
  }
}

// 5) Load username for the welcome chip (if logged in) Need to move elsewhere maybe top!!!
$displayName = null;
if (is_logged_in()) {
  $uid = current_user_id();
  $uS = $conn->prepare('SELECT username FROM users WHERE id=? LIMIT 1');
  $uS->bind_param('i', $uid);
  $uS->execute();
  if ($row = $uS->get_result()->fetch_assoc()) {
    $displayName = $row['username'];
  }
  $uS->close();
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>GameSeerr - Discover</title>
  <link rel="stylesheet" href="/adv-web/GameSeerr/assets/css/styles.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <!-- jQuery (for rubric: we will use $.ajax for live search updates) -->
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"
          integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo="
          crossorigin="anonymous"></script>
  <script>
    // -----------------------------------------------
    // jQuery AJAX live search (Rubric requirement)
    // - On typing in the search box or changing genre,
    //   we call ajax_search.php and replace the grid.
    // - Debounced to reduce requests while typing.
    // -----------------------------------------------
    $(function(){
      const $q = $('input[name="q"]');
      const $genre = $('select[name="genre"]');
      const $grid = $('#grid');  // where cards render
      const $count = $('#count'); // optional count feedback

      let timer = null;
      function fetchResults(){
        // Compose query params we want to send to server
        const data = {
          q: $q.val(),
          genre: $genre.val(),
          tab: $('input[name="tab"]').val() || 'home'
        };
        // jQuery AJAX call: GET -> /ajax_search.php
        $.ajax({
          url: '/adv-web/GameSeerr/ajax_search.php',
          method: 'GET',
          data: data,
          dataType: 'html',     // server returns HTML cards we can drop in
          success: function(html){
            $grid.html(html);   // update grid without page reload
            // Optionally, update a count badge if server included it via data-* attr
            const n = $('#grid').find('[data-card]').length;
            $count.text(n + ' results');
          },
          error: function(){
            $grid.html('<p style="color:#f88">Error loading results.</p>');
          }
        });
      }
      // Debounce typing
      $q.on('input', function(){
        clearTimeout(timer);
        timer = setTimeout(fetchResults, 250);
      });
      // Immediate fetch on genre change
      $genre.on('change', fetchResults);
      // Optional: initial fetch to demonstrate async even on first load
      // Comment out if you prefer the initial PHP-rendered results only.
      // fetchResults();
    });
  </script>
</head>
<body>
<div class="layout">
  <!-- Sidebar with navigation + welcome chip -->
  <aside class="sidebar">
    <div class="brand">🎮 GameSeerr</div>
    <nav class="nav">
      <a class="<?= $tab==='home'?'active':'' ?>" href="index.php"><i class="fa-solid fa-house"></i> Home</a>
      <a class="<?= $tab==='trending'?'active':'' ?>" href="index.php?tab=trending"><i class="fa-solid fa-fire"></i> Trending</a>
      <a class="<?= $tab==='upcoming'?'active':'' ?>" href="index.php?tab=upcoming"><i class="fa-regular fa-calendar"></i> Upcoming</a>
      <a class="<?= $tab==='top'?'active':'' ?>" href="index.php?tab=top"><i class="fa-solid fa-star"></i> Top Rated</a>
      <a class="<?= $tab==='fav'?'active':'' ?>" href="index.php?tab=fav"><i class="fa-regular fa-heart"></i> Wishlist</a>
      <a class="<?= $tab==='new'?'active':'' ?>" href="index.php?tab=new"><i class="fa-solid fa-bolt"></i> New Releases</a>

      <?php if (is_logged_in()): ?>
    <a href="/adv-web/GameSeerr/auth_logout.php">Log out</a>
    <?php else: ?>
      <a href="/adv-web/GameSeerr/auth_login.php">Log in</a>
      <a href="/adv-web/GameSeerr/auth_signup.php">Sign up</a>
    <?php endif; ?>
</nav>
  </aside>

  <main class="main">
    <!-- Topbar = hidden 'tab', search box, and genre filter -->
   <form class="topbar" method="get" action="index.php">
  <!-- keep tab when searching -->
  <input type="hidden" name="tab" value="<?= h($tab) ?>">

  <!-- search -->
  <input class="search" type="search" name="q" placeholder="Search games"
         value="<?= h($_GET['q'] ?? '') ?>">

  <!-- RIGHT CLUSTER -->
  <div class="topbar-right">
    <div class="select-wrap">
      <select name="genre" class="genre-select">
        <option value="">All genres</option>
        <?php foreach ($genres as $g): ?>
          <option value="<?= h($g) ?>" <?= $selGenre===$g?'selected':'' ?>><?= h($g) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <?php if ($displayName): ?>
      <span class="welcome-chip">👋 Welcome, <?= h($displayName) ?></span>
    <?php endif; ?>
  </div>
</form>

    <!-- Simple banner -->
    <div class="banner">Featured Game Banner</div>

    <!-- GRID -->
    <section class="grid" id="grid">
      <?php if ($res && $res->num_rows): ?>
        <?php while ($g = $res->fetch_assoc()): ?>
          <!-- data-card attr helps us count cards after AJAX updates -->
          <a data-card class="card" href="/adv-web/GameSeerr/game.php?id=<?= (int)$g['id'] ?>">
            <img src="/adv-web/GameSeerr/<?= h($g['image_url']) ?>"
                 alt="<?= h($g['title']) ?>"
                 onerror="this.src='/adv-web/GameSeerr/assets/img/placeholder.webp'">
            <div class="meta">
              <div class="title"><?= h($g['title']) ?></div>
              <div class="badge"><?= h($g['genre']) ?> · <?= h($g['platform']) ?></div>
              <div class="bar"><div class="fill" style="width:<?= rating_fill($g['average_rating']) ?>"></div></div>
            </div>
          </a>
        <?php endwhile; ?>
      <?php else: ?>
        <p>No games found.</p>
      <?php endif; ?>
    </section>
  </main>
</div>
</body>
</html>
