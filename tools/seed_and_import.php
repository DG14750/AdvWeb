<?php
// tools/seed_and_import.php
// Steam-only importer: fetch metadata from Steam, save 600x900 cover as WebP,
// UPSERT by steam_app_id. If Steam has no rating, generate a random one
// ONLY when the DB also has no rating (NULL or 0); otherwise keep existing.

require __DIR__ . '/../inc/db.php';
require __DIR__ . '/../inc/adjust_image.php'; // save_cover_image($url, $title)

/* ===========================================================
   1) Helper: parse ?id= or ?ids= (manual testing)
   =========================================================== */
function parse_ids_from_query(): array {
  $norm = function (string $csv): array {
    $out = [];
    foreach (preg_split('/\s*,\s*/', $csv) as $p) {
      if ($p === '') continue;
      $n = (int)$p;
      if ($n > 0) $out[$n] = true; // dedupe
    }
    return array_values(array_keys($out));
  };

  if (isset($_GET['id']) && $_GET['id'] !== '') {
    $n = (int)$_GET['id'];
    return $n > 0 ? [$n] : [];
  }
  if (isset($_GET['ids']) && $_GET['ids'] !== '') {
    return $norm($_GET['ids']);
  }
  return [];
}

/* ===========================================================
   2) Determine which Steam App IDs to import
   =========================================================== */
$appIds = parse_ids_from_query();

if (!$appIds) {
  // Pull all Steam IDs we already have in DB
  $ids = [];
  $res = $conn->query("
    SELECT DISTINCT steam_app_id
    FROM games
    WHERE steam_app_id IS NOT NULL AND steam_app_id > 0
  ");
  while ($row = $res->fetch_assoc()) {
    $ids[] = (int)$row['steam_app_id'];
  }
  $res && $res->close();

  // If DB empty (first run), use a tiny seed so the site has content
  if (!$ids) {
    $ids = [
      1245620, 1091500, 1145360, 2081470, 1774580, 1938090,
      1238810, 782330, 1240440, 2357570, 1182900, 936790,
      2050650, 920210, 990080, 1086940, 413150, 949230,
      292030, 275850, 1174180
    ];
  }
  $appIds = $ids;
}

echo "Importing " . count($appIds) . " app IDs...<br>";

/* ===========================================================
   3) Steam helpers (cURL with timeouts)
   =========================================================== */
function http_get_json(string $url, int $timeout = 10): ?array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => $timeout,
    CURLOPT_TIMEOUT => $timeout,
    CURLOPT_USERAGENT => 'GameSeerrSeeder/1.2',
  ]);
  $body = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);

  if ($code !== 200 || $body === false) return null;
  $json = json_decode($body, true);
  return is_array($json) ? $json : null;
}

function steam_cover(int $appId): string {
  return "https://cdn.cloudflare.steamstatic.com/steam/apps/{$appId}/library_600x900.jpg";
}

function steam_appdetails(int $appId): ?array {
  $url = "https://store.steampowered.com/api/appdetails?appids={$appId}&l=en&cc=gb";
  $all = http_get_json($url);
  if (!$all || empty($all[$appId]['success']) || empty($all[$appId]['data'])) return null;

  $d = $all[$appId]['data'];

  $title = $d['name'] ?? null;
  if (!$title) return null;

  $desc = $d['short_description'] ?? '';

$releaseDate = $d['release_date']['date'] ?? null;


  $genre = '';
  if (!empty($d['genres'])) {
    $genre = implode(', ', array_map(fn($g) => $g['description'], $d['genres']));
  }

  $plats = [];
  if (!empty($d['platforms']['windows'])) $plats[] = 'PC';
  if (!empty($d['platforms']['mac']))     $plats[] = 'Mac';
  if (!empty($d['platforms']['linux']))   $plats[] = 'Linux';
  $platform = $plats ? implode(', ', $plats) : '';

  // May be absent
  $rating = isset($d['metacritic']['score']) ? (int)$d['metacritic']['score'] : null;

  return [
    'title'        => $title,
    'description'  => $desc,
    'release_date' => $releaseDate,
    'genre'        => $genre,
    'platform'     => $platform,
    'rating'       => $rating,
  ];
}

/* ===========================================================
   4) Prepared statements (UPSERT by steam_app_id)
   - average_rating only updates when provided (COALESCE)
   =========================================================== */
$ins = $conn->prepare("
  INSERT INTO games (title, genre, platform, release_date, description, image_url, average_rating, steam_app_id)
  VALUES (?, ?, ?, ?, ?, '', ?, ?)
  ON DUPLICATE KEY UPDATE
    title=VALUES(title),
    genre=VALUES(genre),
    platform=VALUES(platform),
    release_date=VALUES(release_date),
    description=VALUES(description),
    average_rating=COALESCE(VALUES(average_rating), average_rating)
");
$ins->bind_param('ssssiii', $title, $genre, $platform, $date, $desc, $rating, $steamId);

$updImg = $conn->prepare("UPDATE games SET image_url=? WHERE steam_app_id=?");
$updImg->bind_param('si', $savedPath, $steamId);

// Check existing rating per game (bind inside the loop)
$selRating = $conn->prepare("SELECT average_rating FROM games WHERE steam_app_id=? LIMIT 1");

/* ===========================================================
   5) Main loop
   =========================================================== */
$ok = 0; $skip = 0; $fail = 0;

foreach ($appIds as $steamId) {
  $steamId = (int)$steamId;

  $meta = steam_appdetails($steamId);
  if (!$meta) {
    echo "Skip {$steamId}: no data from Steam<br>";
    $skip++;
    continue;
  }

  $title    = $meta['title'];
  $desc     = $meta['description'] ?? '';
  $date     = $meta['release_date'] ?? null;
  $genre    = $meta['genre'] ?? '';
  $platform = $meta['platform'] ?? '';

  // Determine rating with preservation logic (treat 0 as missing)
  $steamRating = $meta['rating']; // may be null/0
  $currentDbRating = null;

  $selRating->bind_param('i', $steamId);     // bind per iteration
  $selRating->execute();
  $r = $selRating->get_result()->fetch_row();
  if ($r && $r[0] !== null && (int)$r[0] > 0) {
    $currentDbRating = (int)$r[0];           // only accept positive rating
  }

  if ($steamRating !== null && (int)$steamRating > 0) {
    $rating = (int)$steamRating;             // Steam provided score
  } elseif ($currentDbRating === null) {
    $rating = rand(60, 95);                  // both missing then generate
  } else {
    $rating = null;                          // preserve DB rating via COALESCE
  }

  try {
    $ins->execute();
  } catch (Throwable $e) {
    echo "Upsert failed for #{$steamId} ({$title}): " . htmlspecialchars($e->getMessage()) . "<br>";
    $fail++;
    continue;
  }

  // Cover download → WebP
  $coverUrl  = steam_cover($steamId);
  $savedPath = save_cover_image($coverUrl, $title);
  if ($savedPath) {
    $updImg->execute();
    echo "{$title} → {$savedPath}<br>";
  } else {
    echo "Cover failed for {$title} (URL: {$coverUrl})<br>";
  }

  $ok++;
}

/* =========================================================== */
$ins->close();
$updImg->close();
$selRating->close();

echo "<hr>Done. Success: {$ok}, Skipped: {$skip}, Failed: {$fail}";
