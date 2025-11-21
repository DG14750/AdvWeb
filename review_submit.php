<?php
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/auth.php';

// Must be logged in to submit a review
if (!is_logged_in()) {
    redirect('auth_login.php');
}

// Basic input
$gameId   = (int)($_POST['game_id'] ?? 0);
$rating   = (int)($_POST['rating'] ?? 0);
$body     = trim($_POST['body'] ?? '');
$reviewId = isset($_POST['review_id']) ? (int)$_POST['review_id'] : 0;

// Validate
if ($gameId <= 0 || $rating < 1 || $rating > 10 || $body === '') {
    redirect('game.php?id=' . $gameId . '&error=invalid_review');
}

$userId = current_user_id();

if ($reviewId > 0) {
    // Update existing review – only if it belongs to this user
    $upd = $conn->prepare(
        'UPDATE reviews
         SET rating = ?, body = ?, created_at = NOW()
         WHERE id = ? AND user_id = ?'
    );
    $upd->bind_param('isii', $rating, $body, $reviewId, $userId);
    $upd->execute();
    $upd->close();
} else {
    // Insert new review
    $stmt = $conn->prepare(
        'INSERT INTO reviews (game_id, user_id, rating, body)
         VALUES (?,?,?,?)'
    );
    $stmt->bind_param('iiis', $gameId, $userId, $rating, $body);
    $stmt->execute();
    $stmt->close();
}

/**
 * Recalculate and persist the game’s average rating
 * based on all reviews for this game.
 */
recalc_game_rating($conn, $gameId);

// Back to game page, scrolled to reviews
redirect('game.php?id=' . $gameId . '#reviews');
