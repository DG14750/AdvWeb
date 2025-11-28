<?php
// profile_avatar_upload.php
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/auth.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo "ERROR: Not logged in";
    exit;
}

$userId = current_user_id();

if (!isset($_FILES['avatar'])) {
    http_response_code(400);
    echo "ERROR: No file uploaded";
    exit;
}

$file = $_FILES['avatar'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo "ERROR: Upload error";
    exit;
}

$allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($file['type'], $allowed)) {
    http_response_code(400);
    echo "ERROR: Invalid file type (jpg/png/gif/webp only)";
    exit;
}

// Folder: same as profile_edit.php now
$uploadDir = __DIR__ . '/uploads/avatars/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) ?: 'jpg';
$newName = 'user-' . $userId . '-' . time() . '.' . $ext;
$targetPath = $uploadDir . $newName;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    http_response_code(500);
    echo "ERROR: Failed to move uploaded file";
    exit;
}

// This is what <img src> will use
$dbPath = 'uploads/avatars/' . $newName;

// Update DB
$stmt = $conn->prepare("UPDATE users SET avatar_url = ? WHERE id = ?");
$stmt->bind_param('si', $dbPath, $userId);
$stmt->execute();
$stmt->close();

// Just echo the new path as plain text
echo $dbPath;
