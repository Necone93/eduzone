<?php
function ez_admin_emails(): array {
  return [
    'admin@example.invalid',
    // 'drugi.admin@gimeko.edu.rs',
  ];
}

if (!defined('ADMIN_EMAIL')) {
  define('ADMIN_EMAIL', ez_admin_emails()[0]);
}

function ez_is_admin_email(?string $email): bool {
  return in_array(strtolower((string)$email), array_map('strtolower', ez_admin_emails()), true);
}

function ez_is_admin(): bool {
  return ez_is_admin_email($_SESSION['email'] ?? null);
}
