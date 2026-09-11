<?php
session_start();
require_once __DIR__ . '/admin_config.php';
if (!ez_is_admin()) {
  exit("⛔ Pristup dozvoljen samo nastavniku.");
}

require __DIR__ . '/db_connect.php';

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$conn->query("
  CREATE TABLE IF NOT EXISTS pitanja_profesoru (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_email VARCHAR(190) NOT NULL,
    contact_email VARCHAR(190) NOT NULL,
    razred VARCHAR(50) DEFAULT NULL,
    naslov VARCHAR(190) NOT NULL,
    poruka TEXT NOT NULL,
    mail_sent TINYINT(1) NOT NULL DEFAULT 0,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (created_at),
    INDEX (student_email),
    INDEX (is_read)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $id = (int)($_POST['id'] ?? 0);
  $action = $_POST['action'] ?? '';

  if ($id > 0 && $action === 'read') {
    $stmt = $conn->prepare("UPDATE pitanja_profesoru SET is_read = 1 WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
  }

  if ($id > 0 && $action === 'delete') {
    $stmt = $conn->prepare("DELETE FROM pitanja_profesoru WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
  }

  header("Location: pitanja_profesoru_admin.php");
  exit;
}

$questions = [];
$res = $conn->query("
  SELECT id, student_email, contact_email, razred, naslov, poruka, mail_sent, is_read, created_at
  FROM pitanja_profesoru
  ORDER BY created_at DESC, id DESC
");
if ($res) {
  while ($row = $res->fetch_assoc()) {
    $questions[] = $row;
  }
  $res->free();
}
?>
<!doctype html>
<html lang="sr">
<head>
<?php require_once __DIR__ . '/analytics.php'; ?>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pitanja učenika</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{--ez-yellow:#ffd100;--ez-yellow-2:#ffbf00;--ez-black:#0f0f10;--ez-grey-1:#151517;--ez-grey-2:#1f2023;--ez-grey-3:#2b2c31;--ez-text:#f6f6f6;--ez-muted:#b6b6b6}
    body{background:radial-gradient(900px 500px at 90% -10%,rgba(255,209,0,.16),transparent 60%),var(--ez-black);color:var(--ez-text);font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
    .wrap{max-width:1100px;margin:24px auto;padding:0 14px}
    .card-ez{background:linear-gradient(180deg,var(--ez-grey-1),#121214 60%);border:1px solid var(--ez-grey-3);border-radius:16px;box-shadow:0 10px 35px rgba(0,0,0,.35);overflow:hidden}
    .bar{height:6px;background:linear-gradient(90deg,var(--ez-yellow),var(--ez-yellow-2))}
    .head{padding:18px 20px;border-bottom:1px solid var(--ez-grey-3);display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}
    .body{padding:18px 20px}
    .q{background:#171719;border:1px solid var(--ez-grey-3);border-radius:14px;padding:16px;margin-bottom:12px}
    .q.unread{border-color:rgba(255,209,0,.7)}
    .meta{color:var(--ez-muted);font-size:.92rem}
    .message{white-space:pre-wrap;color:#f2f2f2}
    .btn-primary{background:linear-gradient(180deg,var(--ez-yellow),var(--ez-yellow-2));border:none;color:#121212;font-weight:700}
    .btn-outline-light{border-color:var(--ez-grey-3)}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card-ez">
      <div class="bar"></div>
      <div class="head">
        <div>
          <h1 class="h3 mb-1">💬 Pitanja učenika</h1>
          <div class="meta">Poruke poslate kroz formu Pitaj profesora.</div>
        </div>
        <a href="dashboard.php" class="btn btn-outline-light">⬅️ Početna</a>
      </div>
      <div class="body">
        <?php if (!$questions): ?>
          <div class="alert alert-warning mb-0">Nema poslatih pitanja.</div>
        <?php endif; ?>

        <?php foreach ($questions as $q): ?>
          <article class="q <?= (int)$q['is_read'] === 0 ? 'unread' : '' ?>">
            <div class="d-flex justify-content-between gap-3 flex-wrap mb-2">
              <div>
                <h2 class="h5 mb-1"><?= e($q['naslov']) ?></h2>
                <div class="meta">
                  <?= e($q['student_email']) ?>
                  <?php if (!empty($q['razred'])): ?> · <?= e($q['razred']) ?><?php endif; ?>
                  · kontakt: <?= e($q['contact_email']) ?>
                  · <?= e(date('d.m.Y H:i', strtotime($q['created_at']))) ?>
                </div>
              </div>
              <div>
                <?php if ((int)$q['is_read'] === 0): ?>
                  <span class="badge text-bg-warning">Novo</span>
                <?php endif; ?>
                <?php if ((int)$q['mail_sent'] === 1): ?>
                  <span class="badge text-bg-success">Mail poslat</span>
                <?php endif; ?>
              </div>
            </div>
            <div class="message mb-3"><?= e($q['poruka']) ?></div>
            <div class="d-flex gap-2 flex-wrap">
              <a class="btn btn-sm btn-primary" href="mailto:<?= e($q['contact_email']) ?>?subject=<?= rawurlencode('Odgovor: '.$q['naslov']) ?>">Odgovori mailom</a>
              <?php if ((int)$q['is_read'] === 0): ?>
                <form method="post">
                  <input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
                  <input type="hidden" name="action" value="read">
                  <button class="btn btn-sm btn-outline-light">Označi kao pročitano</button>
                </form>
              <?php endif; ?>
              <form method="post" onsubmit="return confirm('Obrisati pitanje?')">
                <input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
                <input type="hidden" name="action" value="delete">
                <button class="btn btn-sm btn-outline-danger">Obriši</button>
              </form>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</body>
</html>
