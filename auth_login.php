<?php
// auth_login.php
// -------------------------------------------
// Login form:
// - Accepts username OR email
// - Verifies password (supports legacy plaintext -> upgrades to hash)
// - Uses CSRF and flash messages
// -------------------------------------------
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/helpers.php';
require __DIR__ . '/inc/auth.php';

$err = null;

// Redirect away if already logged in
if (is_logged_in()) {
  header('Location: /adv-web/GameSeerr/index.php');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // 1) CSRF check
  if (!csrf_check($_POST['csrf'] ?? null)) {
    $err = 'Invalid form token, please try again.';
  } else {
    // 2) Collect login credentials
    $login = trim($_POST['login'] ?? '');   // username OR email
    $pass  = $_POST['password'] ?? '';

    if ($login === '' || $pass === '') {
      $err = 'Please enter your credentials.';
    } else {
      // 3) Fetch user row by username/email
      $stmt = $conn->prepare('SELECT id, username, email, password FROM users WHERE email=? OR username=? LIMIT 1');
      $stmt->bind_param('ss', $login, $login);
      $stmt->execute();
      $u = $stmt->get_result()->fetch_assoc();
      $stmt->close();

      if (!$u) {
        $err = 'Invalid credentials.';
      } else {
        // 4) Compare password.
        // Detect whether stored password is a modern hash or old plaintext.
        $stored = $u['password'];
        $is_hash = str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon2');
        $ok = $is_hash ? password_verify($pass, $stored) : hash_equals($stored, $pass);

        if (!$ok) {
          $err = 'Invalid credentials.';
        } else {
          // 5) Transparent upgrade: convert legacy plaintext to a hash
          if (!$is_hash) {
            $newHash = password_hash($pass, PASSWORD_DEFAULT);
            $up = $conn->prepare('UPDATE users SET password=? WHERE id=?');
            $up->bind_param('si', $newHash, $u['id']);
            $up->execute();
            $up->close();
          }

          // 6) Start session and redirect
          login_user((int)$u['id']);
          flash_set('ok', 'Welcome back!');
          header('Location: /adv-web/GameSeerr/index.php');
          exit;
        }
      }
    }
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Log in – GameSeerr</title>
  <link rel="stylesheet" href="/adv-web/GameSeerr/assets/css/styles.css">
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
      <a class="active" href="/adv-web/GameSeerr/auth_login.php">Log in</a>
      <a href="/adv-web/GameSeerr/auth_signup.php">Sign up</a>
    </nav>
  </aside>

  <main class="main">
    <div class="auth-wrap">
      <h1>Log in</h1>
      <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>
      <?php if ($m = flash_get('ok')): ?><div class="ok"><?= h($m) ?></div><?php endif; ?>

      <!-- POST with CSRF token -->
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <div class="field"><label>Username or Email</label><input name="login" required autofocus></div>
        <div class="field"><label>Password</label><input type="password" name="password" required></div>
        <button class="btn" type="submit">Log in</button>
        <p class="muted" style="margin-top:10px">No account? <a href="/adv-web/GameSeerr/auth_signup.php">Create one</a>.</p>
      </form>
    </div>
  </main>
</div>
</body>
</html>
