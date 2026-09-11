<?php
session_start();
if (empty($_SESSION['email'])) { header('Location: login.php'); exit; }

require __DIR__ . '/db_connect.php';

$ADMIN_EMAIL = 'admin@example.invalid';

// trag o ulogovanom korisniku (vidi samo profesor u mailu)
$studentEmailSession = $_SESSION['email'];
$studentRazred = $_SESSION['razred'] ?? '';
$studentIme    = ucfirst(explode('.', explode('@', $studentEmailSession)[0])[0]);

$info = '';
$err  = '';

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function ask_len($s){ return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s); }

function ensure_professor_questions_table(mysqli $conn): void {
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
}

ensure_professor_questions_table($conn);

// repopulacija polja
$fromEmail = '';
$naslov = '';
$poruka = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // honeypot
  if (!empty($_POST['website'])) {
    $info = '✅ Poruka je poslata.';
  } else {
    $fromEmail = trim($_POST['from_email'] ?? '');
    $naslov    = trim($_POST['naslov'] ?? '');
    $poruka    = trim($_POST['poruka'] ?? '');

    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
      $err = 'Unesite važeću email adresu.';
    } elseif (ask_len($naslov) < 3 || ask_len($naslov) > 190) {
      $err = 'Naslov je obavezan (3–190 karaktera).';
    } elseif (ask_len($poruka) < 5) {
      $err = 'Poruka je prekratka.';
    } else {
      $subject = "Pitanje sa sajta: ".$naslov;
      $subjectEnc = '=?UTF-8?B?'.base64_encode($subject).'?=';

      $body  = "Stiglo je novo pitanje sa sajta EduZone.\n\n";
      $body .= "Kontakt email (unet u formi): {$fromEmail}\n";
      $body .= "Ulogovan kao: {$studentEmailSession}".($studentRazred ? " ({$studentRazred})" : "")."\n";
      $body .= "Ime (pretpostavljeno iz email-a): {$studentIme}\n";
      $body .= "Vreme: ".date('d.m.Y H:i')."\n";
      $body .= "IP: ".($_SERVER['REMOTE_ADDR'] ?? 'n/a')."\n";
      $body .= "------------------------------\n";
      $body .= "NASLOV: {$naslov}\n\n";
      $body .= $poruka."\n";

      $host = preg_replace('/^www\./','', $_SERVER['HTTP_HOST'] ?? 'localhost');
      $headers  = "MIME-Version: 1.0\r\n";
      $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
      $headers .= "From: EduZone <noreply@{$host}>\r\n";
      $headers .= "Reply-To: ".sprintf('"%s" <%s>', addslashes($studentIme), $fromEmail)."\r\n";

      $ok = @mail($ADMIN_EMAIL, $subjectEnc, $body, $headers);

      $saved = false;
      $mailSent = $ok ? 1 : 0;
      $save = $conn->prepare("
        INSERT INTO pitanja_profesoru (student_email, contact_email, razred, naslov, poruka, mail_sent)
        VALUES (?, ?, ?, ?, ?, ?)
      ");
      if ($save) {
        $save->bind_param("sssssi", $studentEmailSession, $fromEmail, $studentRazred, $naslov, $poruka, $mailSent);
        $saved = $save->execute();
        $save->close();
      }

      if ($saved) {
        $info = $ok
          ? '✅ Poruka je poslata. Profesor će odgovoriti direktno na tvoj mail.'
          : '✅ Poruka je sačuvana. Profesor će je videti u admin panelu.';
        $fromEmail = '';
        $naslov = $poruka = '';
      } else {
        $err = '❌ Poruka nije sačuvana. Pokušajte ponovo kasnije.';
      }
    }
  }
}
?>
<!doctype html>
<html lang="sr">
<head>
<?php require_once __DIR__ . '/analytics.php'; ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pitaj profesora</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
  :root{
    --ez-yellow:#ffd100;
    --ez-yellow-2:#ffbf00;
    --ez-black:#0f0f10;
    --ez-grey-1:#151517;
    --ez-grey-2:#1f2023;
    --ez-grey-3:#2b2c31;
    --ez-text:#f6f6f6;
    --ez-muted:#b6b6b6;
  }
  html,body{min-height:100%}
  body{
    background:
      radial-gradient(1100px 600px at 85% -10%, rgba(255,209,0,.15), transparent 60%),
      radial-gradient(900px 500px at -10% 100%, rgba(255,209,0,.12), transparent 60%),
      var(--ez-black);
    background-repeat:no-repeat;
    background-attachment:fixed;
    min-height:100vh;
    color:var(--ez-text);
    font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;
  }
  .ez-wrap{min-height:100%;display:grid;place-items:center;padding:clamp(1rem,2vw,2rem) 1rem}
  .ez-card{
    width:min(100%, 980px);
    background:linear-gradient(180deg,var(--ez-grey-1),#121214 60%);
    border:1px solid var(--ez-grey-3);
    border-radius:18px;
    box-shadow:0 10px 35px rgba(0,0,0,.45);
    overflow:hidden;
  }
  .ez-bar{height:6px;background:linear-gradient(90deg,var(--ez-yellow),var(--ez-yellow-2))}
  .ez-head{
    display:flex; align-items:center; gap:.75rem;
    padding:1rem clamp(.75rem,2vw,1.25rem) .25rem;
    flex-wrap:wrap;
  }
  .ez-logo{
    width:48px;height:48px;border-radius:10px;
    background:rgba(255,209,0,.12);
    display:grid;place-items:center;
    border:1px solid rgba(255,209,0,.28);
    box-shadow:0 6px 18px rgba(255,209,0,.25);
    overflow:hidden;
  }
  .ez-logo img{width:34px;height:34px;object-fit:contain;display:block}
  .ez-title{font-weight:800;margin:0;font-size:clamp(1.1rem,1rem + 1vw,1.6rem)}
  .ez-sub{color:var(--ez-muted);margin:0;font-size:clamp(.85rem,.8rem + .4vw,1rem)}
  .ez-head .back-slot{margin-left:auto}
  @media (max-width:576px){
    .ez-head{justify-content:center;text-align:center}
    .ez-head .back-slot{width:100%;display:flex;justify-content:center;margin-left:0;margin-top:.5rem}
  }

  .ez-body{padding:clamp(.75rem,1.2vw + .5rem,1.25rem)}
  .form-label{color:var(--ez-muted)}
  .form-control{
    background:var(--ez-grey-2);
    border:1px solid var(--ez-grey-3);
    color:var(--ez-text);
    border-radius:10px;
    padding:.7rem .9rem;
  }
  .form-control::placeholder{color:#8a8a8a}
  .form-control:focus{background:var(--ez-grey-2);border-color:var(--ez-yellow);box-shadow:0 0 0 .2rem rgba(255,209,0,.15)}

  .btn-ez{
    background:linear-gradient(180deg,var(--ez-yellow),var(--ez-yellow-2));
    border:none;color:#121212;font-weight:800;border-radius:12px;
    box-shadow:0 8px 18px rgba(255,209,0,.25);
  }
  .btn-outline-ez{border:1px solid var(--ez-yellow);color:var(--ez-yellow);border-radius:12px}
  .btn-outline-ez:hover{background:var(--ez-yellow);color:#121212}

  .alert-ez{background:#2b1f00;border:1px solid #6b5200;color:#ffd56a;border-radius:12px}

  /* dugmad responzivno */
  .ez-actions{display:flex;gap:.75rem;flex-wrap:wrap}
  .ez-actions .btn{flex:1 1 220px}
  @media (max-width:480px){ .ez-actions .btn{flex-basis:100%} }
</style>
</head>
<body>
<div class="ez-wrap">
  <div class="ez-card">
    <div class="ez-bar"></div>
    <div class="ez-head">
      <div class="ez-logo"><img src="img/eduZoneLogo.png" alt="EduZone"></div>
      <div>
        <h1 class="ez-title">Pitaj profesora</h1>
        <p class="ez-sub">Pošaljite pitanje – odgovor stiže na tvoj mail</p>
      </div>
      <div class="back-slot">
        <a href="dashboard.php" class="btn btn-outline-ez btn-sm">Početna</a>
      </div>
    </div>

    <div class="ez-body">
      <?php if ($info): ?><div class="alert alert-ez mb-3" role="alert" aria-live="polite"><?= e($info) ?></div><?php endif; ?>
      <?php if ($err):  ?><div class="alert alert-danger mb-3" role="alert" aria-live="polite"><?= e($err) ?></div><?php endif; ?>

      <form id="askForm" method="post" novalidate>
        <!-- honeypot -->
        <input type="text" name="website" value="" style="display:none" tabindex="-1" autocomplete="off">

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Tvoj email</label>
            <input type="email" name="from_email" class="form-control"
                   value="<?= e($fromEmail) ?>" placeholder=""
                   inputmode="email" required>
          </div>
          <div class="col-md-6 d-flex align-items-end">
            <div class="small" style="color:white;">Profesor će odgovoriti direktno na tvoj mail.</div>
          </div>

          <div class="col-12">
            <label class="form-label">Naslov poruke</label>
            <input type="text" name="naslov" class="form-control" maxlength="190"
                   value="<?= e($naslov) ?>" placeholder="Kratak i jasan naslov" required>
          </div>

          <div class="col-12">
            <label class="form-label">Poruka</label>
            <textarea name="poruka" class="form-control" rows="8" placeholder="Napišite pitanje…" required><?= e($poruka) ?></textarea>
          </div>
        </div>

        <div class="mt-3 ez-actions">
          <button class="btn btn-ez">📨 Pošalji</button>
          <a class="btn btn-outline-ez" href="dashboard.php">Otkaži</a>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Potvrda slanja
  document.getElementById('askForm').addEventListener('submit', function(e){
    if(!confirm('Da li sigurno želite da pošaljete ovu poruku profesoru?')) e.preventDefault();
  });
</script>
</body>
</html>
