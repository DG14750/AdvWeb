<?php
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/auth.php';


// 1) Tab handling (decides default ORDER BY)
$tab = $_GET['tab'] ?? 'home';

switch ($tab) {
  case 'top':
    $order = "average_rating DESC";
    break;
  case 'new':
  case 'upcoming':
    $order = "release_year DESC, id DESC";
    break;
  case 'trending':   // placeholder – currently latest
    $order = "id DESC";
    break;
  case 'fav':        // placeholder – requires auth later
    $order = "id DESC";
    break;
  default:
    $order = "id DESC";
}

// Base query used when no search term
$sql = "SELECT id,title,genre,platform,average_rating,image_url FROM games ORDER BY $order";

// 2) Search handling (title/genre/platform/description)
// Create a variable $q from the 'q' parameter in the URL (e.g., ?q=halo)
// If not provided, default to an empty string, and remove extra spaces
$q = trim($_GET['q'] ?? '');

// If user typed something in the search bar, run a search query
if ($q !== '') {

  // Prepare SQL to find matches in title, genre, platform or description
  // Using ? placeholders keeps it safe
  $stmt = $conn->prepare(
    "SELECT id, title, genre, platform, average_rating, image_url
     FROM games
     WHERE title LIKE ? OR genre LIKE ? OR platform LIKE ? OR description LIKE ?
     ORDER BY $order"
  );

  // Add % around the search text so LIKE finds partial matches (e.g. 'zelda' → '%zelda%')
  $like = "%{$q}%";

  // Bind 4 string parameters for the 4 ? in the query (title, genre, platform, description)
  $stmt->bind_param("ssss", $like, $like, $like, $like);

  // Run the search
  $stmt->execute();

  // Get the results from the database
  $res = $stmt->get_result();

} else {
  // If search is empty, just show default list (whatever $sql is)
  $res = $conn->query($sql);
}

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>GameSeerr – Discover</title>
  <link rel="stylesheet" href="/adv-web/GameSeerr/assets/css/styles.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>
<div class="layout">
  <!-- Sidebar navigation with active tab highlighting -->
  <aside class="sidebar">
    <div class="brand">🎮 GameSeerr</div>
    <nav class="nav">
      <a class="<?= $tab==='home'?'active':'' ?>" href="index.php"><i class="fa-solid fa-house"></i> Home</a>
      <a class="<?= $tab==='trending'?'active':'' ?>" href="index.php?tab=trending"><i class="fa-solid fa-fire"></i> Trending</a>
      <a class="<?= $tab==='upcoming'?'active':'' ?>" href="index.php?tab=upcoming"><i class="fa-regular fa-calendar"></i> Upcoming</a>
      <a class="<?= $tab==='top'?'active':'' ?>" href="index.php?tab=top"><i class="fa-solid fa-star"></i> Top Rated</a>
      <a class="<?= $tab==='fav'?'active':'' ?>" href="index.php?tab=fav"><i class="fa-regular fa-heart"></i> Favourites</a>
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
    <!-- Single topbar form; keeps tab when searching -->
    <form class="topbar" method="get" action="index.php">
      <input type="hidden" name="tab" value="<?= h($tab) ?>">
      <input class="search" type="search" name="q" placeholder="Search games" value="<?= h($_GET['q'] ?? '') ?>">
    </form>

    <div class="banner">Featured Game Banner</div>

    <section class="grid">
      <?php if ($res && $res->num_rows): ?>
        <?php while ($g = $res->fetch_assoc()): ?>
          <a class="card" href="/adv-web/GameSeerr/game.php?id=<?= (int)$g['id'] ?>">
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
