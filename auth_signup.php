<?php
// auth_signup.php
// -------------------------------------------
// Registration form:
// - Validates username/email/password
// - Prevents duplicates
// - Stores password hashes
// - Uses CSRF + flash
// -------------------------------------------
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/helpers.php';
require __DIR__ . '/inc/auth.php';

$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? null)) {
    $err = 'Invalid form token, please try again.';
  } else {
    $username = trim($_POST['username'] ?? '');
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $pass     = $_POST['password'] ?? '';
    $pass2    = $_POST['password2'] ?? '';

    // Basic validation
    if ($username === '' || $email === '' || $pass === '' || $pass2 === '') {
      $err = 'Please fill in all fields.';
    } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $username)) {
      $err = 'Username should be 3–32 chars (letters, numbers, _ . -).';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $err = 'Please enter a valid email address.';
    } elseif ($pass !== $pass2) {
      $err = 'Passwords do not match.';
    } elseif (strlen($pass) < 6) {
      $err = 'Password must be at least 6 characters.';
    } else {
      // Uniqueness check
      $s = $conn->prepare('SELECT id FROM users WHERE email=? OR username=? LIMIT 1');
      $s->bind_param('ss', $email, $username);
      $s->execute();
      $exists = $s->get_result()->fetch_assoc();
      $s->close();

      if ($exists) {
        $err = 'That email or username is already taken.';
      } else {
        // Insert with secure hash
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $ins = $conn->prepare('INSERT INTO users (username, email, password) VALUES (?,?,?)');
        $ins->bind_param('sss', $username, $email, $hash);
        $ins->execute();
        $ins->close();

        flash_set('ok', 'Account created! Please sign in.');
        header('Location: auth_login.php');
        exit;
      }
    }
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Sign up GameSeerr</title>
   <?php
    // Works whether the folder is /GameSeerr, /adv-web/GameSeerr, or anything else
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';
  ?>
  <base href="<?= htmlspecialchars($base) ?>">
  <link rel="stylesheet" href="assets/css/styles.css">
  <style>
    .auth-wrap{max-width:420px;margin:60px auto;padding:24px;background:var(--panel);border-radius:14px;box-shadow:0 6px 18px rgb(0 0 0 /.25)}
    .field{display:flex;flex-direction:column;margin:10px 0}
    .field input{background:var(--panel-2);border:1px solid #223146;border-radius:10px;padding:12px 14px;color:var(--text)}
    .btn{display:inline-block;background:var(--accent);color:#0b1118;border:0;border-radius:10px;padding:12px 16px;font-weight:600;cursor:pointer}
    .muted{color:var(--muted)} .err{background:#3a1d1f;border:1px solid #7a3c41;color:#ffd8db;padding:10px 12px;border-radius:10px;margin-bottom:10px}
    .ok{background:#193a2b;border:1px solid #2f6f54;color:#c8f3dd;padding:10px 12px;border-radius:10px;margin-bottom:10px}
  </style>
</head>
<body>
<div class="layout">
  <aside class="sidebar">
    <div class="brand">🎮 GameSeerr</div>
    <nav class="nav">
      <a class="active" href="auth_signup.php">Sign up</a>
      <a href="auth_login.php">Log in</a>
    </nav>
  </aside>

  <main class="main">
    <div class="auth-wrap">
      <h1>Create account</h1>
      <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>
      <?php if ($m = flash_get('ok')): ?><div class="ok"><?= h($m) ?></div><?php endif; ?>

      <!-- POST with CSRF token -->
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <div class="field"><label>Username</label><input name="username" required></div>
        <div class="field"><label>Email</label><input type="email" name="email" required></div>
        <div class="field"><label>Password</label><input type="password" name="password" minlength="6" required></div>
        <div class="field"><label>Confirm password</label><input type="password" name="password2" minlength="6" required></div>
        <button class="btn" type="submit">Sign up</button>
        <p class="muted" style="margin-top:10px">Already have an account? <a href="auth_login.php">Log in</a>.</p>
      </form>
    </div>
  </main>
</div>
</body>
</html>
