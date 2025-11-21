<?php
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/auth.php';

if (!is_logged_in()) {
    header('Location: auth_login.php');
    exit;
}

$reviewId = (int)($_POST['review_id'] ?? 0);
$gameId   = (int)($_POST['game_id'] ?? 0);

if ($reviewId <= 0 || $gameId <= 0) {
    header('Location: index.php');
    exit;
}

$userId = current_user_id();

// Delete only if this review belongs to the current user
$del = $conn->prepare('DELETE FROM reviews WHERE id=? AND user_id=?');
$del->bind_param('ii', $reviewId, $userId);
$del->execute();
$del->close();

recalc_game_rating($conn, $gameId);

header('Location: game.php?id=' . $gameId . '#reviews');
exit;
