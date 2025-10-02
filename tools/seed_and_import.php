<?php
// Run at: http://localhost/adv-web/GameSeerr/tools/seed_and_import.php
// Purpose: Upsert games using Steam metadata, save Steam cover -> WebP, and update image_url.

require __DIR__ . '/../inc/db.php';
require __DIR__ . '/../inc/adjust_image.php'; // your save_cover_image() with resize -> webp

/* ----------------------------------------------------
   Input list (Steam app IDs + your fallbacks).
   We’ll query Steam for name/desc/year/genres/platforms.
   If Steam doesn’t return something, we keep the fallback.
   ---------------------------------------------------- */
$items = [
  // steam_id, fallback_title, fallback_genre,                 fallback_platforms,                     fallback_year, fallback_rating, fallback_desc
  [1245620,'Elden Ring','Action RPG','PC, PS5, Xbox Series X',2022,96,'An expansive dark fantasy world with open exploration and punishing combat.'],
  [1091500,'Cyberpunk 2077','RPG','PC, PS5, Xbox Series X',2020,81,'Open-world RPG set in Night City with branching story and futuristic combat.'],
  [1145360,'Hades','Roguelike','PC, PS5, Xbox Series X, Switch',2020,93,'Fast, stylish roguelike dungeon crawler from Supergiant Games.'],
  [2081470,'Ghostrunner 2','Action','PC, PS5, Xbox Series X',2023,82,'Lightning-fast parkour and katana combat in a cyberpunk world.'],
  [1774580,'Star Wars Jedi: Survivor','Action Adventure','PC, PS5, Xbox Series X',2023,86,'Cinematic lightsaber combat and exploration across new planets.'],
  [1938090,'Call of Duty: Modern Warfare III','Shooter','PC, PS5, Xbox Series X',2023,72,'Multiplayer shooter with fast action and cooperative modes.'],
  [1238810,'Battlefield V','Shooter','PC, PS5, Xbox Series X',2018,81,'Large-scale online battles set across World War II theaters.'],
  [782330,'DOOM Eternal','Shooter','PC, PS5, Xbox Series X',2020,89,'High-velocity demon slaying with heavy metal energy.'],
  [1240440,'Halo Infinite','Shooter','PC, Xbox Series X',2021,83,'Classic arena shooting and an open-world campaign on Zeta Halo.'],
  [2357570,'Overwatch 2','Hero Shooter','PC, PS5, Xbox Series X, Switch',2022,79,'Team-based hero shooter with objectives and seasonal content.'],
  [1182900,'A Plague Tale: Requiem','Adventure','PC, PS5, Xbox Series X',2022,85,'Story-driven stealth adventure with stunning visuals and drama.'],
  [936790,'Life is Strange: True Colors','Adventure','PC, PS5, Xbox Series X',2021,81,'Narrative adventure about empathy and choices in a small town.'],
  [2050650,'Resident Evil 4','Survival Horror','PC, PS5, Xbox Series X',2023,93,'Remake of the classic — tense horror and refined combat.'],
  [920210,'Alan Wake 2','Survival Horror','PC, PS5, Xbox Series X',2023,89,'Psychological horror thriller with dual protagonists.'],
  [990080,'Hogwarts Legacy','Action RPG','PC, PS5, Xbox Series X, Switch',2023,84,'Open-world wizarding adventure in the 1800s Hogwarts era.'],
  [1086940,'Baldur\'s Gate 3','CRPG','PC, PS5',2023,96,'Deep D&D role-playing with reactive story and party combat.'],
  [413150,'Stardew Valley','Simulation','PC, PS5, Xbox Series X, Switch',2016,90,'Farming/life sim with cozy vibes and endless progression.'],
  [949230,'Cities: Skylines II','City Builder','PC, PS5, Xbox Series X',2023,75,'Next-gen city-building with detailed simulation.'],
  [292030,'The Witcher 3: Wild Hunt','RPG','PC, PS5, Xbox Series X, Switch',2015,98,'Epic open-world fantasy with rich quests and storytelling.'],
  [275850,'No Man\'s Sky','Exploration','PC, PS5, Xbox Series X, Switch',2016,88,'Procedurally generated universe with base building and co-op.'],
];

/* -----------------------------
   Helpers for Steam integration
   ----------------------------- */

// Build the 600x900 library cover URL
function steam_cover(int $appId): string {
  return "https://cdn.cloudflare.steamstatic.com/steam/apps/{$appId}/library_600x900.jpg";
}

// Fetch and parse Steam Storefront metadata for one app
function steam_appdetails(int $appId): ?array {
  // l=en = English text; cc=gb = UK date formats (adjust if you like)
  $url  = "https://store.steampowered.com/api/appdetails?appids={$appId}&l=en&cc=gb";
  $json = @file_get_contents($url);
  if (!$json) return null;

  $all = json_decode($json, true);
  if (!$all || empty($all[$appId]['success']) || empty($all[$appId]['data'])) return null;

  $d = $all[$appId]['data'];

  // Title
  $title = $d['name'] ?? null;

  // Short description
  $desc  = $d['short_description'] ?? null;

  // Release year (Steam gives various date strings; pull the first 4-digit year)
  $releaseYear = null;
  if (!empty($d['release_date']['date']) && preg_match('/\b(19|20)\d{2}\b/', $d['release_date']['date'], $m)) {
    $releaseYear = (int)$m[0];
  }

  // Genres array -> "Action, RPG" etc.
  $genre = null;
  if (!empty($d['genres'])) {
    $genre = implode(', ', array_map(fn($g)=>$g['description'], $d['genres']));
  }

  // Platforms (Steam only knows PC/Mac/Linux; we’ll merge with your console fallbacks later)
  $plats = [];
  if (!empty($d['platforms']['windows'])) $plats[] = 'PC';
  if (!empty($d['platforms']['mac']))     $plats[] = 'Mac';
  if (!empty($d['platforms']['linux']))   $plats[] = 'Linux';
  $platform = $plats ? implode(', ', $plats) : null;

  // Optional Metacritic score (0–100) if Steam exposes it
  $rating = $d['metacritic']['score'] ?? null;

  return [
    'title'        => $title,
    'description'  => $desc,
    'release_year' => $releaseYear,
    'genre'        => $genre,
    'platform'     => $platform, // PC/Mac/Linux only
    'rating'       => $rating,   // may be null
  ];
}

/* ----------------------------------------------------
   Prepared statements for UPSERT and image update
   (requires UNIQUE KEY on `title` in your `games` table)
   ---------------------------------------------------- */
$ins = $conn->prepare("
  INSERT INTO games (title, genre, platform, release_year, description, image_url, average_rating, steam_app_id)
  VALUES (?, ?, ?, ?, ?, '', ?, ?)
  ON DUPLICATE KEY UPDATE
    genre=VALUES(genre),
    platform=VALUES(platform),
    release_year=VALUES(release_year),
    description=VALUES(description),
    average_rating=VALUES(average_rating),
    steam_app_id=VALUES(steam_app_id)
");
$ins->bind_param('sssisdi', $title, $genre, $platform, $year, $desc, $rating, $steamId);

$updImg = $conn->prepare("UPDATE games SET image_url=? WHERE title=?");

/* --------------------------------------------
   Main loop: pull Steam meta, merge with fallback,
   upsert row, download cover, update image_url.
   -------------------------------------------- */
$ok=0; $fail=0;

foreach ($items as $row) {
  [$steamId, $fbTitle, $fbGenre, $fbPlatform, $fbYear, $fbRating, $fbDesc] = $row;

  // 1) Get Steam metadata (may be null or partial)
  $meta = steam_appdetails((int)$steamId);

  // 2) Merge Steam fields with your fallbacks (Steam first, then fallback)
  $title    = $meta['title']        ?? $fbTitle;
  $desc     = $meta['description']  ?? $fbDesc;
  $year     = $meta['release_year'] ?? $fbYear;
  $genre    = $meta['genre']        ?? $fbGenre;

  // Platforms: Steam only knows PC/Mac/Linux — merge with your console string if present
  if (!empty($meta['platform'])) {
    // Avoid duplicate "PC" etc. when merging
    $parts = array_map('trim', array_filter(array_merge(
      explode(',', $meta['platform']),
      explode(',', $fbPlatform)
    )));
    $parts = array_values(array_unique($parts));
    $platform = implode(', ', $parts);
  } else {
    $platform = $fbPlatform;
  }

  // Rating: prefer Steam’s Metacritic score if present; else keep your fallback
  $rating = isset($meta['rating']) ? (int)$meta['rating'] : (int)$fbRating;

  // 3) Upsert base row (image_url left empty for now)
  try {
    $ins->execute();
  } catch (Throwable $e) {
    echo "Insert/Update failed for {$fbTitle}: ".htmlspecialchars($e->getMessage())."<br>";
    $fail++; 
    continue;
  }

  // 4) Download & save 600x900 WebP cover, then update image_url
  $coverUrl = steam_cover((int)$steamId);
  $saved    = save_cover_image($coverUrl, $title); // returns 'assets/img/<slug>.webp' or false

  if ($saved) {
    $updImg->bind_param('ss', $saved, $title);
    $updImg->execute();
    echo "✅ {$title} → {$saved}<br>";
    $ok++;
  } else {
    echo "⚠️  Cover failed for {$title} (URL: {$coverUrl})<br>";
    $fail++;
  }
}

/* ---------- tidy up ---------- */
$ins->close();
$updImg->close();

echo "<hr>Done. Success: {$ok}, Failed: {$fail}";
