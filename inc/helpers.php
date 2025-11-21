<?php
/**
 * -------------------------------------------------------
 * GameSeerr – Helper Functions
 * -------------------------------------------------------
 */

/**
 * Escape output (HTML safe)
 */
function h($s): string
{
    return htmlspecialchars($s ?? "", ENT_QUOTES, 'UTF-8');
}


/**
 * Convert a 0–100 numeric rating into a CSS width string ("85%")
 */
function rating_fill($n): string
{
    $n = max(0, min(100, floatval($n)));  // clamp between 0 and 100
    return $n . "%";
}


/**
 * Build application base URL
 */
function app_base(): string
{
    return rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';
}


/**
 * Redirect user to another page
 */
function redirect(string $path): void
{
    header("Location: " . app_base() . ltrim($path, '/'));
    exit;
}


/**
 * -------------------------------------------------------
 * Recalculate a game's average rating using reviews table
 *
 * - Get the average rating (/10)
 * - Convert to /100 scale used by GameSeerr
 * - Store in games.average_rating
 *
 * @param mysqli $conn
 * @param int    $gameId
 * -------------------------------------------------------
 */
function recalc_game_rating(mysqli $conn, int $gameId): void
{
    // Get average rating (/10 scale)
    $stmt = $conn->prepare("
        SELECT AVG(rating) AS avg_rating
        FROM reviews
        WHERE game_id = ?
    ");
    $stmt->bind_param('i', $gameId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    // Null if no reviews exist
    $avg10 = ($row && $row['avg_rating'] !== null)
        ? (float)$row['avg_rating']
        : null;

    // Convert /10 → /100 scale (your UI uses /100)
    $avg100 = ($avg10 !== null)
        ? round($avg10 * 10)   // Example: 7.3 → 73
        : 0;                   // No reviews → 0

    // Update the games table
    $up = $conn->prepare("
        UPDATE games
        SET average_rating = ?
        WHERE id = ?
    ");
    $up->bind_param('ii', $avg100, $gameId);
    $up->execute();
    $up->close();
}
