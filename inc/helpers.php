<?php
// Escape HTML safely and calculate rating bar width
// h($s): converts special characters to HTML entities to prevent XSS
// rating_fill($n): converts a number (0–100) to a percentage string like "85%"
function h($s){ return htmlspecialchars($s ?? "", ENT_QUOTES, 'UTF-8'); }

function rating_fill($n){      // returns 0–100% width
  $n = max(0, min(100, floatval($n)));  // make sure $n stays between 0 and 100
  return $n."%";                        // append % so it can be used in CSS width
}
