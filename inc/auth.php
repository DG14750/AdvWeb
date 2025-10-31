<?php
// inc/auth.php
// Handles sessions + login helpers

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  session_start();
}

// ---------- FLASH MESSAGES ----------
function flash_set(string $key, string $msg): void {
  $_SESSION['flash'][$key] = $msg;
}
function flash_get(string $key): ?string {
  if (!empty($_SESSION['flash'][$key])) {
    $m = $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);
    return $m;
  }
  return null;
}

// ---------- CSRF ----------
function csrf_token(): string {
  if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
  }
  return $_SESSION['csrf'];
}
function csrf_check(?string $t): bool {
  return is_string($t) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}

// ---------- USER SESSION ----------
function current_user_id(): ?int {
  return $_SESSION['uid'] ?? null;
}
function is_logged_in(): bool {
  return isset($_SESSION['uid']);
}
function require_login(): void {
  if (!is_logged_in()) {
    flash_set('error', 'Please log in to continue.');
    header('Location: /adv-web/GameSeerr/auth_login.php');
    exit;
  }
}
function login_user(int $userId): void {
  session_regenerate_id(true);
  $_SESSION['uid'] = $userId;
}
function logout_user(): void {
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'] ?? '', $p['secure'] ?? false, $p['httponly'] ?? true);
  }
  session_destroy();
}
