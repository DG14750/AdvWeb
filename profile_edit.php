<?php
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/auth.php';

if (!is_logged_in()) {
    header('Location: auth_login.php');
    exit;
}

$userId = current_user_id();
$errors = [];
$success = false;

// Load current user data
$stmt = $conn->prepare("
    SELECT username, email, created_at, avatar_url
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    die('User not found');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $newPass  = $_POST['new_password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    // Basic validation
    if ($username === '') {
        $errors[] = 'Username is required.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email is required.';
    }

    // Password change 
    $changePassword = false;
    if ($newPass !== '' || $confirm !== '') {
        if ($newPass !== $confirm) {
            $errors[] = 'New password and confirmation do not match.';
        } elseif (strlen($newPass) < 6) {
            $errors[] = 'New password should be at least 6 characters.';
        } else {
            $changePassword = true;
        }
    }

    // Handle avatar upload (optional)
    $avatarPath = $user['avatar_url']; // keep existing by default

    if (!empty($_FILES['avatar']['name']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['avatar']['tmp_name'];
        $origName = $_FILES['avatar']['name'];

        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','webp'];

        if (!in_array($ext, $allowed, true)) {
            $errors[] = 'Avatar must be JPG, PNG or WEBP.';
        } else {
            $uploadDir  = __DIR__ . '/uploads/avatars/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $fileName   = 'user-' . $userId . '.' . $ext;
            $destAbs    = $uploadDir . $fileName;
            $destRel    = 'uploads/avatars/' . $fileName;


            if (!move_uploaded_file($tmpName, $destAbs)) {
                $errors[] = 'Failed to upload avatar file.';
            } else {
                $avatarPath = $destRel;
            }
        }
    }

    if (!$errors) {
        // 1) Update username + email + avatar_url
        $stmt = $conn->prepare("
            UPDATE users
            SET username = ?, email = ?, avatar_url = ?
            WHERE id = ?
        ");
        $stmt->bind_param('sssi', $username, $email, $avatarPath, $userId);
        $stmt->execute();
        $stmt->close();

        // 2) Update password if requested
        if ($changePassword) {
            // hash the new password as usual
            $hash = password_hash($newPass, PASSWORD_DEFAULT);

            $stmt = $conn->prepare("
                UPDATE users
                SET password = ?
                WHERE id = ?
            ");
            $stmt->bind_param('si', $hash, $userId);
            $stmt->execute();
            $stmt->close();
        }


        $success = true;

        // Reload user row for the form
        $stmt = $conn->prepare("
            SELECT username, email, created_at, avatar_url
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Edit profile - GameSeerr</title>
  <?php $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/'; ?>
  <base href="<?= htmlspecialchars($base) ?>">
  <link rel="stylesheet" href="assets/css/styles.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>

<div class="layout">
  <!-- reuse sidebar -->
  <aside class="sidebar">
    <div class="brand">
      <div class="brand-icon"><i class="fa-solid fa-gamepad"></i></div>
      <div class="brand-text">
        <div class="brand-title">GameSeerr</div>
        <div class="brand-sub">Discover games</div>
      </div>
    </div>
    <nav class="nav">
      <a href="index.php"><span class="nav-icon"><i class="fa-solid fa-house"></i></span><span class="nav-label">Home</span></a>
      <a href="index.php?tab=upcoming"><span class="nav-icon"><i class="fa-regular fa-calendar"></i></span><span class="nav-label">Upcoming</span></a>
      <a href="index.php?tab=top"><span class="nav-icon"><i class="fa-solid fa-star"></i></span><span class="nav-label">Top Rated</span></a>
      <a href="index.php?tab=new"><span class="nav-icon"><i class="fa-solid fa-bolt"></i></span><span class="nav-label">Newest</span></a>
      <a href="index.php?tab=wish"><span class="nav-icon"><i class="fa-regular fa-heart"></i></span><span class="nav-label">Wishlist</span></a>
    </nav>
    <div class="sidebar-auth">
      <a href="auth_logout.php" class="auth-link"><i class="fa-solid fa-right-from-bracket"></i><span>Log out</span></a>
    </div>
    <div class="sidebar-footer-pill">
      <div class="pill-main">GameSeerr</div>
      <div class="pill-sub">Adv Web Project</div>
    </div>
  </aside>

  <div class="main-column">
    <main class="main">
      <div class="main-wrap">

        <h1>Edit profile</h1>

        <?php if ($success): ?>
          <p class="flash-success">Profile updated successfully.</p>
        <?php endif; ?>

        <?php if ($errors): ?>
          <div class="flash-error">
            <ul>
              <?php foreach ($errors as $e): ?>
                <li><?= h($e) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <form class="profile-edit-form" method="post" enctype="multipart/form-data">
          <div class="profile-edit-grid">
            <div class="profile-edit-avatar">
              <?php if (!empty($user['avatar_url'])): ?>
                <img src="<?= h($user['avatar_url']) ?>"
                     alt="<?= h($user['username']) ?>"
                     onerror="this.src='assets/img/placeholder.webp'">
              <?php else: ?>
                <div class="profile-avatar-big avatar-circle">
                  <?= strtoupper($user['username'][0] ?? 'U') ?>
                </div>
              <?php endif; ?>

              <label class="btn-sm-outline" style="margin-top:10px; cursor:pointer;">
                Change avatar
                <input type="file" name="avatar" accept="image/*" style="display:none;">
              </label>
            </div>

            <div class="profile-edit-fields">
              <label class="profile-edit-label">
                <span>Username</span>
                <input type="text" name="username" value="<?= h($user['username']) ?>">
              </label>

              <label class="profile-edit-label">
                <span>Email</span>
                <input type="email" name="email" value="<?= h($user['email']) ?>">
              </label>

              <label class="profile-edit-label">
                <span>New password (optional)</span>
                <input type="password" name="new_password">
              </label>

              <label class="profile-edit-label">
                <span>Confirm new password</span>
                <input type="password" name="confirm_password">
              </label>

              <div style="margin-top:10px;">
                <button type="submit" class="btn-primary">Save changes</button>
                <a href="profile.php" class="btn-sm-outline" style="margin-left:8px;">Cancel</a>
              </div>
            </div>
          </div>
        </form>

      </div>
    </main>
  </div>
</div>

</body>
</html>
