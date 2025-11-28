<?php
// index.php
// -----------------------------
// Home / listing page
// -----------------------------

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/auth.php';

// 1) Decide tab + ORDER BY
$tab = $_GET['tab'] ?? 'home';

// "Upcoming" is stored as text in release_date, so we use a text-based rule.
$upcomingClause = "(
    release_date = 'To be announced'
    OR release_date LIKE '%2026%'
)";

// Flags for which extra filter we want
$useUpcoming  = false;

switch ($tab) {
  case 'upcoming':
    $order       = "id DESC";
    $useUpcoming = true;
    break;

  case 'top':
    $order = "average_rating DESC";
    break;

  case 'new':
    // Extract numeric year from release_date and sort newest first
    $order = "CAST(REGEXP_SUBSTR(release_date, '[0-9]{4}') AS UNSIGNED) DESC,
              id DESC";
    break;

  case 'wish':
    $order = "title ASC";
    break;

  default:
    $order = "title ASC";
    break;
}

// Build a single filter clause based on the selected tab
$filterClauseParts = [];

if ($useUpcoming) {
    $filterClauseParts[] = $upcomingClause;
}
$filterClause = '';
if ($filterClauseParts) {
    $filterClause = implode(' AND ', $filterClauseParts);
}

// These are what we actually plug into SQL
$andFilter   = $filterClause ? " AND $filterClause"   : "";
$whereFilter = $filterClause ? " WHERE $filterClause" : "";

// 2) Build distinct genre list for <select>
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
$q        = trim($_GET['q'] ?? '');
$selGenre = trim($_GET['genre'] ?? '');

// Base SELECT
$baseSql = "SELECT id,title,genre,platform,average_rating,image_url,release_date FROM games";

// Wishlist-only?
$wishlistOnly = ($tab === 'wish' && is_logged_in());
$uid = $wishlistOnly ? current_user_id() : null;

// 4) Server-side filtering
if ($q !== '') {
    $like = "%{$q}%";

    if ($selGenre !== '') {
        if ($wishlistOnly) {
            $stmt = $conn->prepare("$baseSql
              WHERE (title LIKE ? OR genre LIKE ? OR platform LIKE ? OR description LIKE ?)
                AND genre LIKE CONCAT('%', ?, '%')
                AND id IN (SELECT game_id FROM wishlist WHERE user_id=?)
                $andFilter
              ORDER BY $order");
            $stmt->bind_param("sssssi", $like, $like, $like, $like, $selGenre, $uid);
        } else {
            $stmt = $conn->prepare("$baseSql
              WHERE (title LIKE ? OR genre LIKE ? OR platform LIKE ? OR description LIKE ?)
                AND genre LIKE CONCAT('%', ?, '%')
                $andFilter
              ORDER BY $order");
            $stmt->bind_param("sssss", $like, $like, $like, $like, $selGenre);
        }
    } else {
        if ($wishlistOnly) {
            $stmt = $conn->prepare("$baseSql
              WHERE (title LIKE ? OR genre LIKE ? OR platform LIKE ? OR description LIKE ?)
                AND id IN (SELECT game_id FROM wishlist WHERE user_id=?)
                $andFilter
              ORDER BY $order");
            $stmt->bind_param("ssssi", $like, $like, $like, $like, $uid);
        } else {
            $stmt = $conn->prepare("$baseSql
              WHERE (title LIKE ? OR genre LIKE ? OR platform LIKE ? OR description LIKE ?)
                $andFilter
              ORDER BY $order");
            $stmt->bind_param("ssss", $like, $like, $like, $like);
        }
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

} else {
    if ($selGenre !== '') {
        if ($wishlistOnly) {
            $stmt = $conn->prepare("$baseSql
              WHERE genre LIKE CONCAT('%', ?, '%')
                AND id IN (SELECT game_id FROM wishlist WHERE user_id=?)
                $andFilter
              ORDER BY $order");
            $stmt->bind_param("si", $selGenre, $uid);
        } else {
            $stmt = $conn->prepare("$baseSql
              WHERE genre LIKE CONCAT('%', ?, '%')
                $andFilter
              ORDER BY $order");
            $stmt->bind_param("s", $selGenre);
        }

        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();

    } else {
        if ($wishlistOnly) {
            $stmt = $conn->prepare("$baseSql
              WHERE id IN (SELECT game_id FROM wishlist WHERE user_id=?)
                $andFilter
              ORDER BY $order");
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $res = $stmt->get_result();
            $stmt->close();
        } else {
            // plain home / upcoming / new / other tabs
            $res = $conn->query("$baseSql $whereFilter ORDER BY $order");
        }
    }
}

// 5) Load username for welcome chip
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

// 6) Wishlist for hearts
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
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>🎮 GameSeerr - Discover</title>
  <?php $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/'; ?>
  <base href="<?= htmlspecialchars($base) ?>">

  <link rel="stylesheet" href="assets/css/styles.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

  <script>
    $(function () {
      const $q     = $('input[name="q"]');
      const $genre = $('select[name="genre"]');
      const $grid  = $('#grid');
      const $count = $('#count');
      let timer    = null;

      function fetchResults() {
        const data = {
          q: $q.val(),
          genre: $genre.val(),
          tab: $('input[name="tab"]').val() || 'home'
        };
        $.ajax({
          url: 'ajax_search.php',
          data: data,
          method: 'GET',
          dataType: 'html',
          success(html){
            $grid.html(html);
            const n = $grid.find('[data-card]').length;
            if ($count.length) $count.text(n + ' results');
          }
        });
      }

      $q.on('input', () => { clearTimeout(timer); timer = setTimeout(fetchResults, 250); });
      $genre.on('change', fetchResults);

      // wishlist toggle
      $(document).on('click', '.heart-btn', function(e){
        e.preventDefault();
        e.stopPropagation();
        const btn = $(this);
        $.post('wishlist_toggle.php', {game_id:btn.data('game-id')}, resp => {
          if (!resp) return;
          if (resp.status === 'added') btn.addClass('is-active');
          else if (resp.status === 'removed') {
            btn.removeClass('is-active');
            if ($('input[name="tab"]').val() === 'wish')
              btn.closest('[data-card]').fadeOut(200, function(){ $(this).remove(); });
          } else if (resp.status === 'login') {
            window.location='auth_login.php';
          }
        }, 'json');
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

  <!-- RIGHT SIDE COLUMN (content + footer) -->
  <div class="main-column">

    <main class="main">
      <div class="main-wrap">

        <!-- TOPBAR -->
        <div class="topbar">
          <form class="topbar-form" method="get" action="index.php">
            <input type="hidden" name="tab" value="<?= h($tab) ?>">

            <input class="search" type="search" name="q"
                  placeholder="Search games"
                  value="<?= h($_GET['q'] ?? '') ?>">

            <div class="topbar-right">
              <div class="select-wrap">
                <select name="genre" class="genre-select">
                  <option value="">All genres</option>
                  <?php foreach ($genres as $g): ?>
                    <option value="<?= h($g) ?>" <?= $selGenre===$g ? 'selected' : '' ?>>
                      <?= h($g) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </form>

          <?php if ($displayName): ?>

              <?php
                $uid = current_user_id();
                $stmt = $conn->prepare("SELECT avatar_url FROM users WHERE id=?");
                $stmt->bind_param('i', $uid);
                $stmt->execute();
                $avatarRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $avatarUrl = $avatarRow['avatar_url'] ?? null;
              ?>

              <a href="profile.php" class="profile-avatar-link">
                <?php if ($avatarUrl): ?>
                  <div class="avatar-circle topbar-avatar">
                    <img src="<?= h($avatarUrl) ?>"
                        alt="Avatar"
                        class="avatar-img">
                  </div>
                <?php else: ?>
                  <div class="avatar-circle topbar-avatar">
                    <?= strtoupper($displayName[0]) ?>
                  </div>
                <?php endif; ?>
              </a>

          <?php endif; ?>

        </div>


        <!-- GAME GRID -->
        <section class="grid" id="grid">
          <?php if ($res && $res->num_rows): ?>
            <?php while ($g = $res->fetch_assoc()): ?>
              <?php
                $gameId   = (int)$g['id'];
                $inWish   = isset($wishlistIds[$gameId]);
                $heartCls = 'heart-btn' . ($inWish ? ' is-active' : '');

                $score    = (float)$g['average_rating'];
                $barClass = 'bar';
                if ($score >= 80) {
                    $barClass .= ' bar-good';
                } elseif ($score >= 50) {
                    $barClass .= ' bar-mid';
                } else {
                    $barClass .= ' bar-bad';
                }
              ?>
              <a data-card class="card" href="game.php?id=<?= $gameId ?>">
                <img src="<?= h($g['image_url']) ?>"
                     onerror="this.src='assets/img/placeholder.webp'"
                     alt="<?= h($g['title']) ?>">

                <?php if (is_logged_in()): ?>
                  <button class="<?= $heartCls ?>" data-game-id="<?= $gameId ?>">
                    <i class="fa-solid fa-heart"></i>
                  </button>
                <?php endif; ?>

                <div class="meta">
                  <div class="title"><?= h($g['title']) ?></div>
                  <div class="badge">
                    <?= h($g['genre']) ?> · <?= h($g['platform']) ?>
                  </div>
                  <div class="<?= $barClass ?>">
                    <div class="fill" style="width:<?= rating_fill($score) ?>"></div>
                  </div>
                </div>
              </a>
            <?php endwhile; ?>
          <?php else: ?>
            <p>No games found.</p>
          <?php endif; ?>
        </section>

      </div><!-- /.main-wrap -->
    </main>

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
  </div><!-- /.main-column -->
</div><!-- /.layout -->

</body>
</html>
