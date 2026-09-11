<?php
session_start();
require_once __DIR__ . '/admin_config.php';
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
if (!ez_is_admin()) {
    http_response_code(403);
    exit('Pristup je dozvoljen samo administratoru. Prijavi se svojim administratorskim nalogom.');
}
require_once __DIR__ . '/result_mail.php';
if (empty($_SESSION['mail_check_csrf'])) $_SESSION['mail_check_csrf'] = bin2hex(random_bytes(32));
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf'] ?? null;
    if (!is_string($token) || !hash_equals($_SESSION['mail_check_csrf'], $token)) {
        http_response_code(403);
        exit('Osveži stranicu i pokušaj ponovo.');
    }
    if (time() - (int)($_SESSION['mail_check_last'] ?? 0) < 60) {
        $result = ['accepted' => false, 'error' => 'Sačekaj minut pre ponovnog probnog slanja.', 'reference' => '—'];
    } else {
        $_SESSION['mail_check_last'] = time();
        $result = ez_send_result_mail('EduZone — provera slanja rezultata',
            '<h2>Provera slanja rezultata</h2><p>Ovo je probna poruka sa sajta EduZone.</p>');
    }
}
function mail_check_escape($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="sr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>EduZone — Provera mejla</title>
<style>body{font:17px/1.6 system-ui;max-width:760px;margin:40px auto;padding:0 20px;background:#151517;color:#f6f6f6}button{padding:12px 18px;background:#ffd100;border:0;border-radius:8px;font:inherit;cursor:pointer}a{color:#ffd100}.result{padding:18px;border:1px solid #888;border-radius:8px;overflow-wrap:anywhere}</style>
</head><body>
<h1>Provera slanja rezultata</h1>
<p>Primalac: <strong><?= mail_check_escape(ADMIN_EMAIL) ?></strong><br>
Pošiljalac: <?= mail_check_escape(ez_result_mail_from()) ?></p>
<?php if ($result !== null): ?>
<div class="result" role="status">
<?php if ($result['accepted']): ?>
<strong>Server je prihvatio probnu poruku za slanje.</strong>
<p>Ovo još ne potvrđuje isporuku. Proveri prijemno sanduče i spam. Ako poruke nema,
podršci hostinga prosledi referencu ispod i zatraži proveru isporuke, SPF/DKIM podešavanja i eventualne povratne poruke.</p>
<?php else: ?>
<strong>Probno slanje nije uspelo.</strong>
<p><?= mail_check_escape($result['error']) ?></p>
<p>Prosledi ovu grešku podršci hostinga radi provere slanja sa adrese pošiljaoca.</p>
<?php endif; ?>
<p>Referenca: <code><?= mail_check_escape($result['reference']) ?></code></p>
</div>
<?php endif; ?>
<p>Proba šalje jednu poruku na administratorski mejl. Učenik ne mora ponovo da radi test.</p>
<form method="post" action="provera-maila">
<input type="hidden" name="csrf" value="<?= mail_check_escape($_SESSION['mail_check_csrf']) ?>">
<button type="submit">Pošalji probni mejl</button>
</form>
<p><a href="dashboard">Nazad na početnu</a></p>
</body></html>
