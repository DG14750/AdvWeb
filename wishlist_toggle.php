<?php
header('Content-Type: application/json');
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/auth.php';

header('Content-Type: application/json');

// Must be logged in
if (!is_logged_in()) {
    echo json_encode(['status' => 'login']);
    exit;
}

$uid = current_user_id();

// Game ID must be posted
if (!isset($_POST['game_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'no_game']);
    exit;
}

$gameId = (int)$_POST['game_id'];

// 1) Check if in wishlist already
$check = $conn->prepare("SELECT id FROM wishlist WHERE user_id = ? AND game_id = ? LIMIT 1");
$check->bind_param("ii", $uid, $gameId);
$check->execute();
$exists = $check->get_result()->fetch_assoc();
$check->close();

if ($exists) {
    // 2) Remove it
    $del = $conn->prepare("DELETE FROM wishlist WHERE user_id = ? AND game_id = ? LIMIT 1");
    $del->bind_param("ii", $uid, $gameId);
    $del->execute();
    $del->close();

    echo json_encode(['status' => 'removed']);
    exit;
}

// 3) Add it
$add = $conn->prepare("INSERT INTO wishlist (user_id, game_id) VALUES (?, ?)");
$add->bind_param("ii", $uid, $gameId);
$add->execute();
$add->close();

echo json_encode(['status' => 'added']);
exit;
