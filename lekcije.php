<?php
session_start();
require_once __DIR__ . '/admin_config.php';
if (empty($_SESSION["email"])) { header("Location: login.php"); exit; }

require __DIR__ . '/db_connect.php';
$conn->set_charset('utf8mb4');

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ko je nastavnik (profesor ili admin)
$IS_TEACHER = ez_is_admin() || in_array($_SESSION['role'] ?? 'student', ['profesor','admin'], true);

// Učitaj SUBJECT listu iz DB (fallback ako nema tabele/podataka)
$predmeti = [];
$tblPred = $conn->query("SHOW TABLES LIKE 'predmeti'");
if ($tblPred && $tblPred->num_rows > 0) {
  if ($res = $conn->query("SELECT kod, naziv FROM predmeti ORDER BY naziv")) {
    while ($row = $res->fetch_assoc()) { $predmeti[$row['kod']] = $row['naziv']; }
  }
}
if (!$predmeti) {
  $predmeti = [
    "wp1" => "Web programiranje 1",
    "wp2" => "Web programiranje 2",
    "wd"  => "Web dizajn",
    "inf" => "Informatika",
  ];
}

$predmet = $_GET["predmet"] ?? "";

/* ==============================
   1) EKRAN: IZBOR PREDMETA
   ============================== */
if (empty($predmet) || !isset($predmeti[$predmet])): ?>
<!DOCTYPE html>
<html lang="sr">
<head>
<?php require_once __DIR__ . '/analytics.php'; ?>
  <meta charset="UTF-8">
  <title>EduZone — Lekcije (izbor predmeta)</title>
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
    .ez-container{ max-width:1200px; margin:16px auto; padding:0 12px; }
    .ez-card{ background:linear-gradient(180deg,var(--ez-grey-1),#121214 60%); border:1px solid var(--ez-grey-3);
      border-radius:18px; box-shadow:0 10px 35px rgba(0,0,0,.45); overflow:hidden; }
    .ez-bar{height:6px;background:linear-gradient(90deg,var(--ez-yellow),var(--ez-yellow-2))}
    .ez-head{ display:flex; align-items:center; gap:.9rem; padding:1rem clamp(.75rem,2vw,1.25rem) .5rem; flex-wrap:wrap; }
    .ez-logo{ width:48px;height:48px;border-radius:10px;background:rgba(255,209,0,.12);display:grid;place-items:center;border:1px solid rgba(255,209,0,.28);box-shadow:0 6px 18px rgba(255,209,0,.25); overflow:hidden;}
    .ez-logo img{width:34px;height:34px;object-fit:contain}
    .ez-title{margin:0;font-weight:800;font-size:clamp(1.2rem,1rem + 1vw,1.8rem)}
    .ez-sub{margin:0;color:var(--ez-muted)}
    .ez-body{padding:clamp(.9rem,1.2vw + .5rem,1.25rem)}

    .subjects{
      display:grid; grid-template-columns:repeat(4,1fr); gap:14px;
    }
    @media (max-width:992px){ .subjects{grid-template-columns:repeat(2,1fr)} }
    @media (max-width:520px){ .subjects{grid-template-columns:1fr} }

    .subject-tile{
      display:block; text-decoration:none; color:#fff;
      background:#171719; border:1px solid var(--ez-grey-3); border-radius:14px; padding:16px;
      transition:border-color .2s ease, transform .15s ease;
      font-weight:700;
    }
    .subject-tile:hover{ border-color:var(--ez-yellow); transform: translateY(-2px); }
    .subject-tile small{ color:var(--ez-muted); font-weight:500 }
    .btn-primary{ background:linear-gradient(180deg,var(--ez-yellow),var(--ez-yellow-2)); border:none; color:#121212; font-weight:700; border-radius:12px; box-shadow:0 8px 18px rgba(255,209,0,.25); }
    .btn-outline-ez{border:1px solid var(--ez-yellow); color:var(--ez-yellow); border-radius:12px}
    .btn-outline-ez:hover{background:var(--ez-yellow); color:#121212}
  </style>
</head>
<body>

<div class="ez-container">
  <div class="ez-card">
    <div class="ez-bar"></div>
    <div class="ez-head">
      <div class="ez-logo"><img src="img/eduZoneLogo.png" alt=""></div>
      <div>
        <h1 class="ez-title">Izaberi predmet</h1>
        <p class="ez-sub">Klikni na predmet da vidiš dostupne lekcije i materijale.</p>
      </div>
      <div class="ms-auto d-flex gap-2">
        <a href="dashboard.php" class="btn btn-outline-ez btn-sm">Početna</a>
        <?php if ($IS_TEACHER): ?>
          <a href="lekcije_admin.php" class="btn btn-outline-ez btn-sm">Admin lekcija</a>
          <a href="predmeti_admin.php" class="btn btn-outline-ez btn-sm">Admin predmeta</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="ez-body">
      <div class="subjects">
        <?php foreach ($predmeti as $kod => $naziv): ?>
          <a class="subject-tile" href="lekcije.php?predmet=<?= e($kod) ?>">
            <?= e($naziv) ?><br><small>Klik za materijale</small>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
exit; // kraj prvog ekrana
endif;

/* ==============================
   2) EKRAN: LISTA LEKCIJA ZA PREDMET
   ============================== */
$stmt = $conn->prepare("SELECT id, naziv, opis, pdf_link, razred FROM lekcije WHERE predmet = ? ORDER BY id ASC");
$stmt->bind_param("s", $predmet);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="sr">
<head>
<?php require_once __DIR__ . '/analytics.php'; ?>
  <meta charset="UTF-8">
  <title>EduZone — Lekcije: <?= e($predmeti[$predmet]) ?></title>
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
    .ez-container{ max-width:1200px; margin:16px auto; padding:0 12px; }
    .ez-card{ background:linear-gradient(180deg,var(--ez-grey-1),#121214 60%); border:1px solid var(--ez-grey-3);
      border-radius:18px; box-shadow:0 10px 35px rgba(0,0,0,.45); overflow:hidden; }
    .ez-bar{height:6px;background:linear-gradient(90deg,var(--ez-yellow),var(--ez-yellow-2))}
    .ez-head{ display:flex; align-items:center; gap:.9rem; padding:1rem clamp(.75rem,2vw,1.25rem) .5rem; flex-wrap:wrap; }
    .ez-logo{ width:48px;height:48px;border-radius:10px;background:rgba(255,209,0,.12);display:grid;place-items:center;border:1px solid rgba(255,209,0,.28);box-shadow:0 6px 18px rgba(255,209,0,.25); overflow:hidden;}
    .ez-logo img{width:34px;height:34px;object-fit:contain}
    .ez-title{margin:0;font-weight:800;font-size:clamp(1.2rem,1rem + 1vw,1.8rem)}
    .ez-sub{margin:0;color:var(--ez-muted)}
    .ez-body{padding:clamp(.9rem,1.2vw + .5rem,1.25rem)}

    .btn-primary{ background:linear-gradient(180deg,var(--ez-yellow),var(--ez-yellow-2)); border:none; color:#121212; font-weight:700; border-radius:12px; box-shadow:0 8px 18px rgba(255,209,0,.25); }
    .btn-outline-ez{border:1px solid var(--ez-yellow); color:var(--ez-yellow); border-radius:12px}
    .btn-outline-ez:hover{background:var(--ez-yellow); color:#121212}
.lesson-card h5,
.lesson-card .card-title {
  color: #ffffff;
}

    .lesson-card{ background:#171719; border:1px solid var(--ez-grey-3); border-radius:14px; height:100%; display:flex; flex-direction:column; }
    .lesson-card .card-body{ padding:16px; display:flex; flex-direction:column; gap:.5rem; }
    .badge-ez{ background:var(--ez-yellow); color:#121212; border-radius:8px; font-weight:800; }
    .alert-info{ background:#0f1721; color:#cfe7ff; border:1px solid #27425f; border-radius:12px }
  </style>
</head>
<body>

<div class="ez-container">
  <div class="ez-card">
    <div class="ez-bar"></div>

    <div class="ez-head">
      <div class="ez-logo"><img src="img/eduZoneLogo.png" alt=""></div>
      <div>
        <h1 class="ez-title">Lekcije: <?= e($predmeti[$predmet]) ?></h1>
        <p class="ez-sub">Lista dostupnih materijala i preuzimanja.</p>
      </div>
      <div class="ms-auto d-flex gap-2">
        <a href="lekcije.php" class="btn btn-outline-ez btn-sm">Izbor predmeta</a>
        <a href="dashboard.php" class="btn btn-outline-ez btn-sm">Početna</a>
        <?php if ($IS_TEACHER): ?>
          <a href="lekcije_admin.php?predmet=<?= e($predmet) ?>" class="btn btn-outline-ez btn-sm">Admin lekcija</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="ez-body">
      <?php if ($result->num_rows === 0): ?>
        <div class="alert alert-info text-center">Još uvek nema lekcija za ovaj predmet.</div>
      <?php else: ?>
        <div class="row g-3">
          <?php while ($row = $result->fetch_assoc()): ?>
            <div class="col-12 col-md-6 col-lg-4">
              <div class="card lesson-card">
                <div class="card-body">
                  <h5 class="mb-1"><?= e($row["naziv"]) ?></h5>
                  <?php if (!empty($row["razred"])): ?>
                    <span class="badge badge-ez align-self-start mb-1"><?= e($row["razred"]) ?></span>
                  <?php endif; ?>
                  <?php if (!empty($row["opis"])): ?>
                    <p class="mb-2" style="color:var(--ez-muted)"><?= nl2br(e($row["opis"])) ?></p>
                  <?php endif; ?>
                  <div class="mt-auto d-flex gap-2">
                    <a href="lekcija.php?id=<?= (int)$row['id'] ?>" class="btn btn-primary btn-sm">Otvori</a>
                    <?php if (!empty($row['pdf_link'])): ?>
                      <a href="<?= e($row['pdf_link']) ?>" class="btn btn-outline-ez btn-sm" download>Preuzmi</a>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
          <?php endwhile; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
$stmt->close();
$conn->close();
