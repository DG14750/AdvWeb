<?php
// Stolen from stack overflow cause I like it better than stars
// Calculate rating bar width
// h($s): converts special characters to HTML entities to prevent XSS
// rating_fill($n): converts a number (0–100) to a percentage string like "85%"
function h($s){ return htmlspecialchars($s ?? "", ENT_QUOTES, 'UTF-8'); }

function rating_fill($n){      // returns 0–100% width
  $n = max(0, min(100, floatval($n)));  // Ensure $n is a float between 0 and 100
  return $n."%";                        // append % so it can be used in CSS width
}

// Helpers to redirect 
function app_base(): string {
  return rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';
}

function redirect(string $path): void {
  header('Location: ' . app_base() . ltrim($path, '/'));
  exit;
}
