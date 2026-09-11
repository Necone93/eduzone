<?php
ini_set('display_errors',1);
error_reporting(E_ALL);

session_start();
if (empty($_SESSION['email'])) { header('Location: login.php'); exit; }

$flash_ok = '';
$flash_err = '';

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $ime       = trim($_POST["ime"] ?? "");
    $prezime   = trim($_POST["prezime"] ?? "");
    $emailIn   = trim($_POST["email"] ?? "");
    $komentar  = trim($_POST["komentar"] ?? "");

    // Validacije
    if ($ime === "" || $prezime === "") {
        $flash_err = "Unesi ime i prezime.";
    } elseif ($emailIn === "" || !filter_var($emailIn, FILTER_VALIDATE_EMAIL)) {
        $flash_err = "Unesi ispravan email.";
    } elseif (empty($_FILES["fajl"]["tmp_name"]) || $_FILES["fajl"]["error"] !== UPLOAD_ERR_OK) {
        $flash_err = "Fajl nije otpremljen.";
    } else {
        $allowed  = ['pdf','zip','ppt','pptx','odp'];
        $maxSize  = 15 * 1024 * 1024; // 15 MB
        $origName = $_FILES["fajl"]["name"] ?? '';
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed, true)) {
            $flash_err = "Dozvoljeni su PDF, ZIP ili prezentacija.";
        } elseif ($_FILES["fajl"]["size"] > $maxSize) {
            $flash_err = "Fajl je prevelik (maks. 15 MB).";
        } else {
            // Priprema emaila sa prilogom
            $to         = "admin@example.invalid";
            $subject    = "📥 Novi domaći — {$ime} {$prezime}";
            $subjectEnc = '=?UTF-8?B?'.base64_encode($subject).'?=';

            $message  = "Učenik: {$ime} {$prezime}\r\n";
            $message .= "Kontakt email: {$emailIn}\r\n\r\n";
            $message .= "Komentar:\r\n{$komentar}\r\n";

            // OBAVEZNO koristi svoj domen ovde:
            $from    = "no-reply@eduzone.rs";
            $replyTo = $emailIn;

            $uid      = md5(uniqid((string)time(), true));
            $boundary = "==Multipart_Boundary_x{$uid}x";

            $headers  = "From: EduZone <{$from}>\r\n";
            $headers .= "Reply-To: {$replyTo}\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";

            $body  = "--{$boundary}\r\n";
            $body .= "Content-Type: text/plain; charset=\"utf-8\"\r\n";
            $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $body .= $message . "\r\n";

            $fileData = chunk_split(base64_encode(file_get_contents($_FILES["fajl"]["tmp_name"])));
            $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $origName);
            $mimeTypes = [
                'pdf'  => 'application/pdf',
                'zip'  => 'application/zip',
                'ppt'  => 'application/vnd.ms-powerpoint',
                'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'odp'  => 'application/vnd.oasis.opendocument.presentation',
            ];
            $ctype = $mimeTypes[$ext] ?? 'application/octet-stream';

            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: {$ctype}; name=\"{$safeName}\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= "Content-Disposition: attachment; filename=\"{$safeName}\"\r\n\r\n";
            $body .= $fileData . "\r\n";
            $body .= "--{$boundary}--";

            // Envelope sender (za shared hosting često neophodan)
            $envelope = "-f{$from}";

            if (@mail($to, $subjectEnc, $body, $headers, $envelope)) {
                $flash_ok = "Domaći je uspešno poslat! Profesor će odgovoriti direktno na tvoj mail: {$emailIn}";
                // Očisti formu nakon uspeha
                $_POST = [];
            } else {
                $flash_err = "Greška pri slanju mejla (mail() nije dostupan ili je odbijeno).";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="sr">
<head>
<?php require_once __DIR__ . '/analytics.php'; ?>
  <meta charset="UTF-8">
  <title>EduZone — Pošalji domaći</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="icon" type="image/png" href="images/eduZoneLogo.png">

  <style>
    :root{
      --ez-yellow:#ffd100; --ez-yellow-2:#ffbf00; --ez-black:#0f0f10;
      --ez-grey-1:#151517; --ez-grey-2:#1f2023; --ez-grey-3:#2b2c31;
      --ez-text:#f6f6f6; --ez-muted:#b6b6b6;
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
    .ez-container{ max-width:900px; margin:16px auto; padding:0 12px; }
    .ez-card{ background:linear-gradient(180deg,var(--ez-grey-1),#121214 60%); border:1px solid var(--ez-grey-3);
      border-radius:18px; box-shadow:0 10px 35px rgba(0,0,0,.45); overflow:hidden; }
    .ez-bar{height:6px;background:linear-gradient(90deg,var(--ez-yellow),var(--ez-yellow-2))}
    .ez-head{ display:flex; align-items:center; gap:.9rem; padding:1rem clamp(.75rem,2vw,1.25rem) .75rem; flex-wrap:wrap; }
    .ez-logo{ width:48px;height:48px;border-radius:10px;background:rgba(255,209,0,.12);display:grid;place-items:center;border:1px solid rgba(255,209,0,.28);box-shadow:0 6px 18px rgba(255,209,0,.25); overflow:hidden;}
    .ez-logo img{width:34px;height:34px;object-fit:contain}
    .ez-title{margin:0;font-weight:800;font-size:clamp(1.2rem,1rem + 1vw,1.8rem)}
    .ez-sub{margin:0;color:var(--ez-muted)}
    .ez-body{padding:clamp(.9rem,1.2vw + .5rem,1.25rem)}

    .form-control, .form-select{
      background:#171719; border:1px solid var(--ez-grey-3); color:#fff;
    }
    .form-control::file-selector-button{
      background:#24252a; border:1px solid #2f3036; color:#ddd; border-radius:.5rem; padding:.35rem .75rem; margin-right:.6rem;
    }
    .form-label{ color:#eaeaea; }

    .btn-primary{
      background:linear-gradient(180deg,var(--ez-yellow),var(--ez-yellow-2));
      border:none; color:#121212; font-weight:700; border-radius:12px;
      box-shadow:0 8px 18px rgba(255,209,0,.25);
    }
    .btn-outline-ez{border:1px solid var(--ez-yellow); color:var(--ez-yellow); border-radius:12px}
    .btn-outline-ez:hover{background:var(--ez-yellow); color:#121212}

    .alert-success{ background:#10361b; color:#b7f3c2; border:1px solid #1d6c3b; border-radius:12px }
    .alert-danger{ background:#341515; color:#ffc9c9; border:1px solid #6b2b2b; border-radius:12px }
    .form-text{ color:var(--ez-muted); }
  </style>
</head>
<body>

<div class="ez-container">
  <div class="ez-card">
    <div class="ez-bar"></div>

    <div class="ez-head">
      <div class="ez-logo"><img src="img/eduZoneLogo.png" alt=""></div>
      <div>
        <h1 class="ez-title">Pošalji domaći</h1>
        <p class="ez-sub">Profesor će odgovoriti direktno na tvoj mail.</p>
      </div>
      <div class="ms-auto">
        <a href="dashboard.php" class="btn btn-outline-ez btn-sm">Početna</a>
      </div>
    </div>

    <div class="ez-body">
      <?php if ($flash_ok): ?>
        <div class="alert alert-success mb-3"><?= e($flash_ok) ?></div>
      <?php endif; ?>
      <?php if ($flash_err): ?>
        <div class="alert alert-danger mb-3"><?= e($flash_err) ?></div>
      <?php endif; ?>

      <form method="POST" enctype="multipart/form-data" novalidate>
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Ime</label>
            <input type="text" name="ime" class="form-control" required value="<?= e($_POST['ime'] ?? '') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Prezime</label>
            <input type="text" name="prezime" class="form-control" required value="<?= e($_POST['prezime'] ?? '') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Tvoj email</label>
            <input type="email" name="email" class="form-control" required
                   value="<?= e($_POST['email'] ?? '') ?>"
                   placeholder="npr. ime.prezime@gimeko.edu.rs">
            <div class="form-text">Na ovaj mail će doći odgovor.</div>
          </div>

          <div class="col-12">
            <label class="form-label">Komentar (opciono)</label>
            <textarea name="komentar" class="form-control" rows="4"><?= e($_POST['komentar'] ?? '') ?></textarea>
          </div>

          <div class="col-12">
            <label class="form-label">Domaći (.pdf, .zip, .ppt, .pptx ili .odp, do 15 MB)</label>
            <input type="file" name="fajl" class="form-control" accept=".pdf,.zip,.ppt,.pptx,.odp" required>
          </div>
        </div>

        <div class="mt-3 d-flex gap-2">
          <button type="submit" class="btn btn-primary">📤 Pošalji domaći</button>
          <a href="dashboard.php" class="btn btn-outline-ez">Otkaži</a>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
