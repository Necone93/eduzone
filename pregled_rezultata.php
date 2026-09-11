<?php
// pregled_rezultata.php — detaljan pregled pokušaja
// (v.2025-10-09) — FIX: uvek prikazujemo SVA postavljena pitanja iz attempt-a (sa placeholder-om ako je obrisano)

ini_set('display_errors',1);
error_reporting(E_ALL);

session_start();
if (empty($_SESSION['email'])) { exit('⛔ Pristup dozvoljen samo ulogovanim učenicima.'); }
$userEmail = $_SESSION['email'];

require __DIR__ . '/db_connect.php';
require_once __DIR__ . '/test_visibility.php';

$test_id   = isset($_GET['test_id']) ? (int)$_GET['test_id'] : 0;
$attempt_q = isset($_GET['attempt']) ? (int)$_GET['attempt'] : null;
if ($test_id<=0) exit('Nedostaje test.');

function e($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
function col_exists($c,$t,$col){
  $r=$c->query("SHOW COLUMNS FROM `$t` LIKE '".$c->real_escape_string($col)."'");
  $ok=$r && $r->num_rows>0; if($r) $r->free(); return $ok;
}
function norm($s){
  $s = trim((string)$s);
  return function_exists('mb_strtolower') ? mb_strtolower($s,'UTF-8') : strtolower($s);
}
/* ocena za max 12 */
function ocena_za_12(int $points, int $max): ?int {
  if ($max !== 12) return null;
  if ($points <= 4) return 1;
  if ($points <= 6) return 2;
  if ($points <= 8) return 3;
  if ($points <=10) return 4;
  return 5;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* 1) Meta testa (+ za_ocenu i broj_pitanja ako postoje) */
$has_broj = col_exists($conn,'testovi','broj_pitanja');
$has_flag = col_exists($conn,'testovi','za_ocenu');

$select = "naslov, opis";
if ($has_broj) $select .= ", broj_pitanja";
if ($has_flag) $select .= ", za_ocenu";

$stm = $conn->prepare("SELECT $select FROM testovi WHERE id=?");
$stm->bind_param("i",$test_id);
$stm->execute();
$meta = $stm->get_result()->fetch_assoc();
$stm->close();

$naslov   = $meta['naslov'] ?? ('Test #'.$test_id);
$maxFromTest = $has_broj ? (int)($meta['broj_pitanja'] ?? 0) : 0;
$zaOcenu  = $has_flag ? (int)$meta['za_ocenu'] : 0;

/* Konkretna dodela: stari linkovi bez ak biraju najnoviju dodelu. */
$hasAssignment = col_exists($conn, 'odgovori_korisnika', 'assign_key');
$assignKey = isset($_GET['ak']) ? trim((string)$_GET['ak']) : null;
if ($hasAssignment && $assignKey === null) {
  $latest = $conn->prepare("SELECT assign_key FROM odgovori_korisnika
    WHERE email=? AND test_id=? ORDER BY created_at DESC, id DESC LIMIT 1");
  $latest->bind_param('si', $userEmail, $test_id);
  $latest->execute();
  $latestRow = $latest->get_result()->fetch_assoc();
  $assignKey = $latestRow['assign_key'] ?? '';
  $latest->close();
}
$assignKey = $assignKey === '' ? null : $assignKey;
$assignmentFilter = $hasAssignment
  ? ($assignKey === null ? ' AND assign_key IS NULL'
    : " AND assign_key='".$conn->real_escape_string($assignKey)."'")
  : '';

/* Ako pokušaj nije prosleđen, izaberi poslednji u ovoj dodeli. */
if (is_null($attempt_q)){
  $mx=$conn->prepare("SELECT COALESCE(MAX(attempt_no),1)
                      FROM odgovori_korisnika
                      WHERE email=? AND test_id=? $assignmentFilter");
  $mx->bind_param("si",$userEmail,$test_id);
  $mx->execute(); $mx->bind_result($attempt_q); $mx->fetch(); $mx->close();
}

/* 2a) Vreme rada ovog pokušaja — za zaključavanje (ako kolona postoji) */
$tsDone = null;
if (col_exists($conn,'odgovori_korisnika','created_at')) {
  $tt = $conn->prepare("SELECT UNIX_TIMESTAMP(MAX(created_at)) FROM odgovori_korisnika WHERE email=? AND test_id=? AND attempt_no=? $assignmentFilter");
  $tt->bind_param("sii",$userEmail,$test_id,$attempt_q);
  $tt->execute(); $tt->bind_result($tsDone); $tt->fetch(); $tt->close();
}

/* Odmah nakon predaje propusnica važi 30 minuta; potom pregled nakon 7 dana. */
$k = test_result_key($test_id, $attempt_q, $assignKey);
$grantedAt = isset($_SESSION['grant_view'][$k]) ? (int)$_SESSION['grant_view'][$k] : null;
$allowDetails = test_details_visible($tsDone === null ? null : (int)$tsDone, $grantedAt);

/* Lista svih pokušaja (za dropdown) */
$attemptsList = [];
$al = $conn->prepare("SELECT DISTINCT attempt_no
                      FROM odgovori_korisnika
                      WHERE email=? AND test_id=? $assignmentFilter
                      ORDER BY attempt_no ASC");
$al->bind_param("si",$userEmail,$test_id);
$al->execute();
$ral = $al->get_result();
while($row = $ral->fetch_assoc()) { $attemptsList[] = (int)$row['attempt_no']; }
$al->close();

/* 4) Odgovori ovog pokušaja → skup pitanja (redosled postavljenih pitanja) */
$ans = [];      // pid => ['ids'=>[], 'text'=>null]
$askedIds = []; // redosled pids

$qo = $conn->prepare("SELECT pitanje_id, odgovor_id, text_answer
                      FROM odgovori_korisnika
                      WHERE email=? AND test_id=? AND attempt_no=? $assignmentFilter");
$qo->bind_param("sii",$userEmail,$test_id,$attempt_q);
$qo->execute();
$ro=$qo->get_result();
while($o=$ro->fetch_assoc()){
  $pid=(int)$o['pitanje_id'];
  if (!isset($ans[$pid])) { $ans[$pid]=['ids'=>[], 'text'=>null]; $askedIds[]=$pid; }
  if (!is_null($o['odgovor_id']))  $ans[$pid]['ids'][]=(int)$o['odgovor_id'];
  if (!is_null($o['text_answer'])) $ans[$pid]['text']=$o['text_answer'];
}
$qo->close();

/* Ako nema zapisa */
if (empty($askedIds)) {
  ?>
  <!doctype html><html lang="sr"><head>
<?php require_once __DIR__ . '/analytics.php'; ?>
  <meta charset="utf-8"><title>Pregled rezultata</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  </head><body class="bg-light"><div class="container py-4">
    <div class="alert alert-info">Za odabrani pokušaj nema zabeleženih odgovora.</div>
    <a href="testovi.php" class="btn btn-secondary">Nazad</a>
  </div></body></html>
  <?php
  exit;
}

/* 5) Učitaj postavljena pitanja (koliko god ih ima) */
$askedList = implode(',', array_map('intval', $askedIds));
$pitanja = [];   // pid => ['id','tekst','tip','ans'=>[]]

if ($askedList!=='') {
  $qP = $conn->query("
    SELECT id, tekst, tip
    FROM pitanja
    WHERE test_id = {$test_id} AND id IN ($askedList)
    ORDER BY FIELD(id, $askedList)
  ");
  while($row=$qP->fetch_assoc()){
    $pitanja[(int)$row['id']] = [
      'id'=>(int)$row['id'],
      'tekst'=>$row['tekst'],
      'tip'=>strtolower(trim($row['tip'])),
      'ans'=>[]
    ];
  }
  $qP->close();
}

/* Placeholder za nestala pitanja */
foreach ($askedIds as $pid) {
  if (!isset($pitanja[$pid])) {
    $pitanja[$pid] = [
      'id'   => $pid,
      'tekst'=> '(Pitanje je uklonjeno ili izmenjeno nakon rada testa)',
      'tip'  => 'radio',
      'ans'  => []
    ];
  }
}

/* 6) Ponuđeni odgovori */
$hasTacan = col_exists($conn,'odgovori','tacan');
if ($askedList!=='') {
  $qA = $conn->query("
    SELECT id, pitanje_id, tekst".($hasTacan?",tacan":"")."
    FROM odgovori
    WHERE pitanje_id IN ($askedList)
    ORDER BY id ASC
  ");
  while($a=$qA->fetch_assoc()){
    $pid = (int)$a['pitanje_id'];
    if (isset($pitanja[$pid])) {
      $pitanja[$pid]['ans'][] = [
        'id'   => (int)$a['id'],
        'tekst'=> $a['tekst'],
        'tacan'=> $hasTacan ? (int)$a['tacan'] : 0
      ];
    }
  }
  $qA->close();
}

/* 7) Statistika — UVEK brojimo SVA postavljena pitanja */
$considered = count($askedIds);
$correctCnt = 0;

foreach ($askedIds as $pid) {
  $q   = $pitanja[$pid];
  $tip = strtolower(trim($q['tip']));
  $selIds = isset($ans[$pid]['ids']) ? array_map('intval',$ans[$pid]['ids']) : [];
  $selTxt = $ans[$pid]['text'] ?? null;

  $ok = false;
  if ($tip === 'text') {
    $sample = null;
    if (!empty($q['ans'])) {
      foreach ($q['ans'] as $oa) { if (!empty($oa['tacan'])) { $sample = $oa['tekst']; break; } }
    }
    if ($sample !== null) {
      $ok = ($selTxt !== null && norm($selTxt) === norm($sample));
    }
  } else {
    $corr = [];
    if (!empty($q['ans'])) {
      foreach ($q['ans'] as $oa) if (!empty($oa['tacan'])) $corr[] = (int)$oa['id'];
    }
    if ($corr) {
      sort($corr); $tmp=$selIds; sort($tmp);
      $ok = ($tmp === $corr);
    }
  }
  if ($ok) $correctCnt++;
}
$wrongCnt = max(0, $considered - $correctCnt);
$percent  = $considered ? round($correctCnt*100/$considered) : 0;

$maxForGrade = ($maxFromTest > 0) ? $maxFromTest : $considered;
$ocena = ocena_za_12($correctCnt, $maxForGrade);
?>
<!doctype html>
<html lang="sr">
<head>
<?php require_once __DIR__ . '/analytics.php'; ?>
<meta charset="utf-8">
<title>EduZone — Pregled rezultata</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="icon" type="image/png" href="images/eduZoneLogo.png">

<style>
  :root{
    --ez-yellow:#ffd100; --ez-yellow-2:#ffbf00; --ez-black:#0f0f10;
    --ez-grey-1:#151517; --ez-grey-2:#1f2023; --ez-grey-3:#2b2c31;
    --ez-text:#f6f6f6; --ez-muted:#b6b6b6;
    --ok-bg:#0d2716; --ok-bd:#1f5a33; --bad-bg:#2a1112; --bad-bd:#6d2227; --neu-bg:#1a1b1f; --neu-bd:#2e2f35;
  }
  html,body{min-height:100%}
  body{
    background:
      radial-gradient(1100px 600px at 85% -10%, rgba(255,209,0,.15), transparent 60%),
      radial-gradient(900px 500px at -10% 100%, rgba(255,209,0,.12), transparent 60%),
      var(--ez-black);
    min-height:100vh;
    color:var(--ez-text);
    background-repeat:no-repeat;
    background-attachment:fixed;
    font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;
  }
  .navbar{background: linear-gradient(180deg, #0d0d0e, #121214 70%); border-bottom:1px solid var(--ez-grey-3);}
  .navbar .nav-link{ color:#d9d9d9; } .navbar .nav-link:hover{ color:var(--ez-yellow); }
  .navbar-brand img{ height:36px; }

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

  .btn-primary{ background:linear-gradient(180deg,var(--ez-yellow),var(--ez-yellow-2)); border:none; color:#121212; font-weight:700; border-radius:12px; box-shadow:0 8px 18px rgba(255,209,0,.25); }
  .btn-outline-ez{border:1px solid var(--ez-yellow); color:var(--ez-yellow); border-radius:12px}
  .btn-outline-ez:hover{background:var(--ez-yellow); color:#121212}

  .summary-card{ background:#171719; border:1px solid var(--ez-grey-3); border-radius:14px; }
  .meter{ height:10px; background:#24252a; border:1px solid #303239; border-radius:999px; overflow:hidden; }
  .meter > span{ display:block; height:100%; background:linear-gradient(90deg,#29cf70,#77e69e); width:0%; transition: width .5s ease-in-out; }

  .qcard{ background:#171719; border:1px solid var(--ez-grey-3); border-radius:14px; }
  .qtitle{ border-left:4px solid var(--ez-yellow); padding-left:.6rem; }
  .badge-ans{min-width:1.75rem;display:inline-block;text-align:center}
  .answer{padding:.5rem .65rem;border-radius:.5rem;border:1px solid transparent}
  .answer.correct{background:var(--ok-bg);border-color:var(--ok-bd)}
  .answer.wrong{background:var(--bad-bg);border-color:var(--bad-bd)}
  .answer.neutral{background:var(--neu-bg);border-color:var(--neu-bd)}
  .text-muted-2{color:var(--ez-muted)}
</style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard.php">
      <img src="img/eduZoneLogo.png" alt="EduZone"><span class="fw-bold">EduZone</span>
    </a>
    <a class="navbar-toggler" data-bs-toggle="collapse" data-bs-target="#mainNavbar" href="#mainNavbar"
       aria-controls="mainNavbar" aria-expanded="false" aria-label="Meni"><span class="navbar-toggler-icon"></span></a>
    <div class="collapse navbar-collapse" id="mainNavbar">
      <ul class="navbar-nav ms-auto">
        <li class="nav-item"><a class="nav-link" href="lekcije.php">Lekcije</a></li>
        <li class="nav-item"><a class="nav-link" href="testovi.php">Testovi</a></li>
        <li class="nav-item"><a class="nav-link" href="domaci.php">Domaći</a></li>
        <li class="nav-item"><a class="nav-link" href="chat.php">Pitaj profesora</a></li>
        <li class="nav-item"><a class="nav-link text-danger" href="logout.php">Odjava</a></li>
      </ul>
    </div>
  </div>
</nav>

<div class="ez-container">
  <div class="ez-card">
    <div class="ez-bar"></div>
    <div class="ez-head">
      <div class="ez-logo"><img src="img/eduZoneLogo.png" alt=""></div>
      <div>
        <h1 class="ez-title">Pregled rezultata</h1>
        <p class="ez-sub"><?= e($naslov) ?></p>
      </div>
      <div class="ms-auto d-flex gap-2">
        <a href="testovi.php" class="btn btn-outline-ez btn-sm">Nazad na testove</a>
      </div>
    </div>

    <div class="ez-body">
      <!-- Sažetak -->
      <div class="summary-card p-3 mb-3">
        <div class="row g-3 align-items-end">
          <div class="col-12 col-md">
            <div class="d-flex flex-wrap align-items-center gap-2">
              <div class="h5 mb-0">Pokušaj: #<?= (int)$attempt_q ?></div>
              <div class="text-muted-2">•</div>
              <div class="text-muted-2">Ukupno pitanja: <strong><?= (int)$considered ?></strong></div>
              <div class="text-muted-2">•</div>
              <div class="text-success">Tačnih: <strong><?= (int)$correctCnt ?></strong></div>
              <div class="text-muted-2">•</div>
              <div class="text-danger">Netačnih: <strong><?= (int)$wrongCnt ?></strong></div>
              <div class="text-muted-2">•</div>
              <div>Uspeh: <strong><?= (int)$percent ?>%</strong></div>
              <?php if (!is_null($ocena)): ?>
                <div class="text-muted-2">•</div>
                <div><span class="badge bg-primary">Ocena: <?= (int)$ocena ?></span></div>
              <?php endif; ?>
            </div>
            <div class="meter mt-2"><span style="width: <?= (int)$percent ?>%"></span></div>
            <?php if ($maxFromTest > 0): ?>
              <div class="small text-muted-2 mt-1">Maksimalan broj pitanja (po testu): <?= (int)$maxFromTest ?></div>
            <?php endif; ?>
          </div>

          <?php if (!empty($attemptsList) && count($attemptsList) > 1): ?>
          <div class="col-12 col-md-auto">
            <form method="get" class="d-flex align-items-center gap-2">
              <input type="hidden" name="test_id" value="<?= (int)$test_id ?>">
              <input type="hidden" name="ak" value="<?= e($assignKey ?? '') ?>">
              <label class="text-muted-2">Pogledaj pokušaj:</label>
              <select name="attempt" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach ($attemptsList as $aNo): ?>
                  <option value="<?= (int)$aNo ?>" <?= $aNo===$attempt_q?'selected':'' ?>>#<?= (int)$aNo ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <?php if (!$allowDetails): ?>
        <div class="alert alert-warning">
          <strong>Detaljan pregled pitanja je trenutno zaključan.</strong><br>
          <?php if ($tsDone): ?>
            Uvid u test biće dostupan od: <strong><?= date('d.m.Y H:i', (int)$tsDone + TEST_HISTORY_DELAY) ?></strong>.
          <?php else: ?>
            Uvid u test biće dostupan nakon isteka perioda od 7 dana.
          <?php endif; ?>
        </div>
        <a href="testovi.php" class="btn btn-outline-ez">Nazad na testove</a>
      <?php else: ?>

      <!-- Pitanja & odgovori -->
      <div class="d-flex flex-column gap-3">
        <?php foreach ($askedIds as $pid): ?>
          <?php
            $q   = $pitanja[$pid];
            $tip = strtolower(trim($q['tip']));
            $selIds = isset($ans[$pid]['ids']) ? array_map('intval',$ans[$pid]['ids']) : [];
            $selTxt = $ans[$pid]['text'] ?? null;
          ?>
          <div class="qcard p-3">
            <h6 class="qtitle mb-3"><?= e($q['tekst']) ?></h6>

            <?php if ($tip === 'text'): ?>
              <div class="answer neutral">
                <span class="badge-ans">✍️</span>
                <strong>Vaš odgovor:</strong>
                <span><?= ($selTxt===null || $selTxt==='') ? '<em class="text-muted-2">nema</em>' : e($selTxt) ?></span>
              </div>
              <?php
                $sample = null;
                if (!empty($q['ans'])) { foreach ($q['ans'] as $oa) if (!empty($oa['tacan'])) { $sample=$oa['tekst']; break; } }
                if ($sample!==null):
              ?>
                <div class="mt-2 small text-muted-2">
                  (Primer) tačan odgovor: <em><?= e($sample) ?></em>
                </div>
              <?php endif; ?>

            <?php else: ?>
              <?php if (empty($q['ans'])): ?>
                <div class="text-muted-2"><em>Nema ponuđenih odgovora (pitanje je verovatno uklonjeno).</em></div>
              <?php else: foreach ($q['ans'] as $a):
                $isCorrect = !empty($a['tacan']);
                $isChosen  = in_array((int)$a['id'], $selIds, true);
                $cls='neutral';
                if ($isChosen && $isCorrect) $cls='correct';
                elseif ($isChosen && !$isCorrect) $cls='wrong';
              ?>
                <div class="answer <?= $cls ?> mb-2">
                  <span class="badge-ans"><?= $isCorrect ? '✅' : '◻️' ?></span>
                  <?= e($a['tekst']) ?>
                  <?php if ($isChosen): ?><span class="ms-2 badge bg-warning text-dark">izabrano</span><?php endif; ?>
                </div>
              <?php endforeach; ?>
                <?php if (!$selIds): ?>
                  <div class="text-muted-2"><em>Niste izabrali odgovor.</em></div>
                <?php endif; ?>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; // allowDetails ?>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
