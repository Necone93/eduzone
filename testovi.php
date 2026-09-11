<?php
// testovi.php — Aktivni (neurađeni) i odrađeni testovi sa tamnom tabelom i skraćenim kolonama
ini_set('display_errors',1);
error_reporting(E_ALL);

session_start();
if (empty($_SESSION['email'])) { header("Location: login.php"); exit; }
$userEmail = $_SESSION['email'];

require __DIR__ . '/db_connect.php';
require_once __DIR__ . '/test_visibility.php';

function t_exists(mysqli $c,string $t):bool{
  $r=$c->query("SHOW TABLES LIKE '".$c->real_escape_string($t)."'");
  $ok=$r&&$r->num_rows>0; if($r) $r->free(); return $ok;
}
function c_exists(mysqli $c,string $t,string $col):bool{
  $r=$c->query("SHOW COLUMNS FROM `$t` LIKE '".$c->real_escape_string($col)."'");
  $ok=$r&&$r->num_rows>0; if($r) $r->free(); return $ok;
}
function h($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ============ AKTIVNI (SAMO NEURAĐENI) ============ */
$aktivni = [];
if (t_exists($conn,'dodeljeni_testovi') && t_exists($conn,'testovi')) {

  $has_deadline = c_exists($conn,'dodeljeni_testovi','deadline');
  $has_attempts = c_exists($conn,'dodeljeni_testovi','attempts_allowed');
  $has_assigned = c_exists($conn,'dodeljeni_testovi','assigned_at');

  $has_zaocenu  = c_exists($conn,'testovi','za_ocenu');
  $has_brojp    = c_exists($conn,'testovi','broj_pitanja');

  $select = "dt.test_id, t.naslov";
  if ($has_attempts) $select .= ", dt.attempts_allowed";
  if ($has_deadline) $select .= ", dt.deadline";
  if ($has_assigned) $select .= ", dt.assigned_at";
  if ($has_zaocenu)  $select .= ", t.za_ocenu";
  if ($has_brojp)    $select .= ", t.broj_pitanja";

  $order = $has_deadline
    ? "ORDER BY COALESCE(dt.deadline,'9999-12-31') ASC, dt.test_id DESC"
    : "ORDER BY dt.test_id DESC";

  $sql = "SELECT $select
          FROM dodeljeni_testovi dt
          JOIN testovi t ON t.id = dt.test_id
          WHERE dt.email = ?
          $order";

  $st = $conn->prepare($sql);
  $st->bind_param("s",$userEmail);
  $st->execute();
  $rs = $st->get_result();

  $has_ok = t_exists($conn,'odgovori_korisnika');
  $has_ok_assign = $has_ok && c_exists($conn,'odgovori_korisnika','assign_key');
  $has_ot_active = t_exists($conn,'odradjeni_testovi');
  $has_ot_assign = $has_ot_active && c_exists($conn,'odradjeni_testovi','assign_key');
  $has_started_active = t_exists($conn,'zapoceti_testovi');
  $has_started_assign = $has_started_active && c_exists($conn,'zapoceti_testovi','assign_key');

  while ($row = $rs->fetch_assoc()) {
    $testId = (int)$row['test_id'];

    $assignKey = ($has_assigned && !empty($row['assigned_at'])) ? (string)$row['assigned_at'] : null;

    // ako postoji bar jedan attempt za ovu konkretnu dodelu → NE prikazuj u aktivnim
    $used = 0;
    if ($has_ok) {
      if ($has_ok_assign && $assignKey !== null) {
        $q = $conn->prepare("SELECT COUNT(DISTINCT attempt_no) FROM odgovori_korisnika WHERE email=? AND test_id=? AND (assign_key=? OR assign_key IS NULL)");
        $q->bind_param("sis",$userEmail,$testId,$assignKey);
      } elseif ($has_ok_assign) {
        $q = $conn->prepare("SELECT COUNT(DISTINCT attempt_no) FROM odgovori_korisnika WHERE email=? AND test_id=? AND assign_key IS NULL");
        $q->bind_param("si",$userEmail,$testId);
      } else {
        $q = $conn->prepare("SELECT COUNT(DISTINCT attempt_no) FROM odgovori_korisnika WHERE email=? AND test_id=?");
        $q->bind_param("si",$userEmail,$testId);
      }
      $q->execute(); $q->bind_result($used); $q->fetch(); $q->close();
    }

    if ($has_ot_active) {
      if ($has_ot_assign && $assignKey !== null) {
        $q = $conn->prepare("SELECT COUNT(*) FROM odradjeni_testovi WHERE email=? AND test_id=? AND (assign_key=? OR assign_key IS NULL)");
        $q->bind_param("sis",$userEmail,$testId,$assignKey);
      } elseif ($has_ot_assign) {
        $q = $conn->prepare("SELECT COUNT(*) FROM odradjeni_testovi WHERE email=? AND test_id=? AND assign_key IS NULL");
        $q->bind_param("si",$userEmail,$testId);
      } else {
        $q = $conn->prepare("SELECT COUNT(*) FROM odradjeni_testovi WHERE email=? AND test_id=?");
        $q->bind_param("si",$userEmail,$testId);
      }
      $q->execute(); $q->bind_result($doneUsed); $q->fetch(); $q->close();
      $used = max($used, (int)$doneUsed);
    }

    if ($has_started_active) {
      if ($has_started_assign && $assignKey !== null) {
        $q = $conn->prepare("SELECT COUNT(*) FROM zapoceti_testovi WHERE email=? AND test_id=? AND (assign_key=? OR assign_key IS NULL)");
        $q->bind_param("sis",$userEmail,$testId,$assignKey);
      } elseif ($has_started_assign) {
        $q = $conn->prepare("SELECT COUNT(*) FROM zapoceti_testovi WHERE email=? AND test_id=? AND assign_key IS NULL");
        $q->bind_param("si",$userEmail,$testId);
      } else {
        $q = $conn->prepare("SELECT COUNT(*) FROM zapoceti_testovi WHERE email=? AND test_id=?");
        $q->bind_param("si",$userEmail,$testId);
      }
      $q->execute(); $q->bind_result($startedUsed); $q->fetch(); $q->close();
      $used = max($used, (int)$startedUsed);
    }

    if ($used > 0) continue;

    // rok (ako postoji)
    $deadlineOk = true;
    if ($has_deadline && !empty($row['deadline'])) $deadlineOk = (strtotime($row['deadline']) >= time());
    if (!$deadlineOk) continue;

    $row['_za_ocenu']     = $has_zaocenu ? (int)$row['za_ocenu'] : 0;
    $row['_broj_pitanja'] = $has_brojp   ? (int)$row['broj_pitanja'] : null;
    $row['_deadline']     = ($has_deadline && !empty($row['deadline'])) ? $row['deadline'] : null;
    $row['_assigned_at']  = $assignKey;

    $aktivni[] = $row;
  }
  $st->close();
}

/* ============ ODRAĐENI (ISTORIJA) ============ */
$istorija = [];
if (t_exists($conn, 'testovi')) {
  $hasFlag = c_exists($conn, 'testovi', 'za_ocenu');
  // Svaki red predstavlja konkretnu dodelu i pokušaj.
  if (t_exists($conn, 'odgovori_korisnika')) {
    $source = 'odgovori_korisnika';
    $timeColumn = c_exists($conn, $source, 'created_at') ? 'created_at' : null;
  } elseif (t_exists($conn, 'odradjeni_testovi')) {
    $source = 'odradjeni_testovi';
    $timeColumn = c_exists($conn, $source, 'vreme') ? 'vreme'
      : (c_exists($conn, $source, 'datum') ? 'datum' : null);
  } else {
    $source = null;
    $timeColumn = null;
  }
  if ($source && $timeColumn) {
    $assignment = c_exists($conn, $source, 'assign_key') ? 'r.assign_key' : 'NULL';
    $attempt = c_exists($conn, $source, 'attempt_no') ? 'r.attempt_no' : '1';
    $flag = $hasFlag ? 't.za_ocenu' : '0';
    $group = 'r.test_id, t.naslov';
    if ($hasFlag) $group .= ', t.za_ocenu';
    if ($assignment !== 'NULL') $group .= ', r.assign_key';
    if ($attempt !== '1') $group .= ', r.attempt_no';
    $st = $conn->prepare("SELECT r.test_id, t.naslov, $flag AS _za_ocenu,
        $assignment AS _assign_key, $attempt AS _attempt,
        MAX(r.`$timeColumn`) AS _when,
        UNIX_TIMESTAMP(MAX(r.`$timeColumn`)) AS _submitted_at
      FROM `$source` r JOIN testovi t ON t.id = r.test_id
      WHERE r.email=? GROUP BY $group ORDER BY _when DESC");
    $st->bind_param('s', $userEmail);
    $st->execute();
    $rs = $st->get_result();
    while ($row = $rs->fetch_assoc()) {
      $submittedAt = $row['_submitted_at'] === null ? null : (int)$row['_submitted_at'];
      if (test_history_visible($submittedAt)) $istorija[] = $row;
    }
    $st->close();
  }
}
?>
<!doctype html>
<html lang="sr">
<head>
<?php require_once __DIR__ . '/analytics.php'; ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>EduZone — Testovi</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="icon" type="image/png" href="images/eduZoneLogo.png">

<style>
  :root{
    --ez-yellow:#ffd100; --ez-yellow-2:#ffbf00; --ez-black:#0f0f10;
    --ez-grey-1:#151517; --ez-grey-2:#1f2023; --ez-grey-3:#2b2c31;
    --ez-text:#f6f6f6; --ez-muted:#b6b6b6;
  }
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
  .ez-container{ max-width:1100px; margin:16px auto; padding:0 12px; }
  .ez-card{ background:linear-gradient(180deg,var(--ez-grey-1),#121214 60%); border:1px solid var(--ez-grey-3);
    border-radius:18px; box-shadow:0 10px 35px rgba(0,0,0,.45); overflow:hidden; }
  .ez-bar{height:6px;background:linear-gradient(90deg,var(--ez-yellow),var(--ez-yellow-2))}
  .ez-head{ display:flex; align-items:center; gap:.9rem; padding:1rem clamp(.75rem,2vw,1.25rem) .75rem; flex-wrap:wrap; }
  .ez-logo{ width:48px;height:48px;border-radius:10px;background:rgba(255,209,0,.12);display:grid;place-items:center;border:1px solid rgba(255,209,0,.28);box-shadow:0 6px 18px rgba(255,209,0,.25); overflow:hidden;}
  .ez-logo img{width:34px;height:34px;object-fit:contain}
  .ez-title{margin:0;font-weight:800;font-size:clamp(1.2rem,1rem + 1vw,1.8rem)}
  .ez-sub{margin:0;color:var(--ez-muted)}
  .ez-body{padding:clamp(.9rem,1.2vw + .5rem,1.25rem)}

  /* Aktivni testovi */
  .grid{display:grid; gap:12px; grid-template-columns:repeat(3,1fr)}
  @media (max-width:992px){ .grid{grid-template-columns:repeat(2,1fr)} }
  @media (max-width:576px){ .grid{grid-template-columns:1fr} }

  .tcard{ background:#171719; border:1px solid var(--ez-grey-3); border-radius:14px; padding:14px; display:flex; flex-direction:column; gap:6px; }
  .tmeta{ color:#bdbdbd; font-size:.95rem }
  .tsep{ height:1px; background:var(--ez-grey-3); margin:.25rem 0 .5rem }
  .badge-soft{ background:#24252a; border:1px solid #2f3036; color:#d5d5d5; }
  .badge-flag{ background:#2b2536; border:1px solid #3b2f53; color:#d9c7ff; }

  .btn-primary{ background:linear-gradient(180deg,var(--ez-yellow),var(--ez-yellow-2)); border:none; color:#121212; font-weight:700; border-radius:12px; box-shadow:0 8px 18px rgba(255,209,0,.25); }
  .btn-outline-ez{border:1px solid var(--ez-yellow); color:var(--ez-yellow); border-radius:12px}
  .btn-outline-ez:hover{background:var(--ez-yellow); color:#121212}

  /* Tamna tabela (bez belih traka) */
  .table-ez{
    --bs-table-bg: #171719;
    --bs-table-striped-bg: #1b1c20;
    --bs-table-striped-color: var(--ez-text);
    --bs-table-color: var(--ez-text);
    --bs-table-border-color: #2b2c33;
    background-color: var(--bs-table-bg);
    color: var(--ez-text);
  }
  .table-ez thead th{
    background:#141416;
    color:#cfcfcf;
    border-color:#2b2c33;
    font-weight:600;
  }
  .table-ez tbody td{
    border-color:#2b2c33;
  }
</style>
</head>
<body>

<div class="ez-container">
  <div class="ez-card">
    <div class="ez-bar"></div>
    <div class="ez-head">
      <div class="ez-logo"><img src="img/eduZoneLogo.png" alt=""></div>
      <div>
        <h1 class="ez-title">Testovi</h1>
        <p class="ez-sub">Aktivni testovi i istorija pokušaja</p>
      </div>
      <div class="ms-auto">
        <a href="dashboard.php" class="btn btn-outline-ez btn-sm">Početna</a>
      </div>
    </div>

    <div class="ez-body">

      <!-- AKTIVNI -->
      <h5 class="mb-2">Aktivni testovi</h5>
      <?php if (empty($aktivni)): ?>
        <div class="alert alert-info">Nema aktivnih testova — sve dodeljene testove ste već uradili ✅</div>
      <?php else: ?>
        <div class="grid mb-4">
          <?php foreach ($aktivni as $t): ?>
            <div class="tcard">
              <div class="d-flex align-items-start justify-content-between gap-2">
                <div class="h6 mb-1"><?= h($t['naslov']) ?></div>
              </div>
              <div class="mb-1">
                <span class="badge <?= ($t['_za_ocenu'] ? 'badge-flag' : 'badge-soft') ?>">
                  <?= $t['_za_ocenu'] ? 'Za ocenu' : 'Kratka provera' ?>
                </span>
                <?php if (!empty($t['_broj_pitanja'])): ?>
                  <span class="badge badge-soft ms-1"><?= (int)$t['_broj_pitanja'] ?> pitanja</span>
                <?php endif; ?>
              </div>
              <div class="tmeta">Rok: <?= !empty($t['_deadline']) ? h(date('d.m.Y H:i', strtotime($t['_deadline']))) : '—' ?></div>
              <div class="tsep"></div>
              <div class="d-flex justify-content-end">
                <a class="btn btn-primary btn-sm" href="start_test.php?id=<?= (int)$t['test_id'] ?><?= !empty($t['_assigned_at']) ? '&ak=' . urlencode($t['_assigned_at']) : '' ?>">Započni</a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <!-- ISTORIJA -->
      <h5 class="mb-2">Odrađeni testovi</h5>
      <p class="ez-sub mb-3">Završeni testovi pojavljuju se ovde sedam dana nakon predaje.</p>
      <?php if (empty($istorija)): ?>
        <div class="alert alert-info">Trenutno nema testova dostupnih za pregled.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm table-ez align-middle">
            <thead>
              <tr>
                <th>Naslov</th>
                <th style="width:160px">Datum</th>
                <th class="text-end" style="width:140px">Akcija</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($istorija as $r):
              $tid = (int)$r['test_id'];
              $att = (int)$r['_attempt'];
              $resultUrl = 'pregled_rezultata?'.http_build_query([
                'test_id' => $tid, 'attempt' => $att, 'ak' => $r['_assign_key'] ?? ''
              ]);
              ?>
              <tr>
                <td>
                  <?= h($r['naslov']) ?>
                  <?php if (!empty($r['_za_ocenu'])): ?>
                    <span class="badge badge-flag ms-1">Za ocenu</span>
                  <?php endif; ?>
                </td>
                <td><?= !empty($r['_when']) ? h(date('d.m.Y', strtotime($r['_when']))) : '—' ?></td>
                <td class="text-end">
                  <a class="btn btn-sm btn-outline-ez" href="<?= h($resultUrl) ?>">
                     Pregled
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
