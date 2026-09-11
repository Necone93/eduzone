<?php
session_start();
require_once __DIR__ . '/admin_config.php';

if (!ez_is_admin()) {
    exit('⛔ Pristup dozvoljen samo nastavniku.');
}

require __DIR__ . '/db_connect.php';

// Stabilnije izbacivanje SQL grešaka u poruku umesto 500
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
error_reporting(E_ALL);
ini_set('display_errors', '1');

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function normalize_dtlocal_to_mysql(?string $s): ?string {
    // "2025-09-01T10:30" -> "2025-09-01 10:30:00"
    $s = trim((string)$s);
    if ($s === '') return null;
    $s = str_replace('T',' ',$s);
    // dodaj :00 ako nema sekundi
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) {
        $s .= ':00';
    }
    return $s;
}

function col_exists(mysqli $conn, string $table, string $column): bool {
  $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '".$conn->real_escape_string($column)."'");
  $ok = $res && $res->num_rows > 0;
  if ($res) $res->free();
  return $ok;
}

function ensure_assignments_schema(mysqli $conn): void {
  $conn->query("
    CREATE TABLE IF NOT EXISTS dodeljeni_testovi (
      id INT AUTO_INCREMENT PRIMARY KEY,
      test_id INT NOT NULL,
      email VARCHAR(190) NOT NULL,
      attempts_allowed SMALLINT NULL DEFAULT 1,
      deadline DATETIME NULL,
      assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX ix_student_test (email, test_id),
      INDEX ix_assigned_at (assigned_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  if (!col_exists($conn, 'dodeljeni_testovi', 'assigned_at')) {
    $conn->query("ALTER TABLE dodeljeni_testovi ADD COLUMN assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
  }
  if (!col_exists($conn, 'dodeljeni_testovi', 'attempts_allowed')) {
    $conn->query("ALTER TABLE dodeljeni_testovi ADD COLUMN attempts_allowed SMALLINT NULL DEFAULT 1");
  }
  if (!col_exists($conn, 'dodeljeni_testovi', 'deadline')) {
    $conn->query("ALTER TABLE dodeljeni_testovi ADD COLUMN deadline DATETIME NULL");
  }

  $indexes = $conn->query("SHOW INDEX FROM dodeljeni_testovi WHERE Non_unique = 0");
  $unique = [];
  while ($idx = $indexes->fetch_assoc()) {
    $key = $idx['Key_name'];
    if ($key === 'PRIMARY') continue;
    $unique[$key][] = $idx['Column_name'];
  }
  $indexes->free();

  foreach ($unique as $key => $columns) {
    sort($columns);
    if ($columns === ['email', 'test_id']) {
      $conn->query("ALTER TABLE dodeljeni_testovi DROP INDEX `$key`");
    }
  }
}

ensure_assignments_schema($conn);

/* ===== Usaglašavanje tabele: dozvoli NULL za attempts_allowed (NULL = bez ograničenja) ===== */
try {
  $conn->query("ALTER TABLE dodeljeni_testovi MODIFY COLUMN attempts_allowed SMALLINT NULL DEFAULT NULL");
} catch (Throwable $e) {
  // ignorisi ako nema privilegija ili je već tako
}

/* ===== RAZRED (prvi korak) =====
   Dinamički skup razreda iz korisnika, da se poklopi sa onim što već postoji u bazi. */
$razredi = [];
$res = $conn->query("SELECT DISTINCT razred FROM korisnici WHERE razred IS NOT NULL AND razred<>'' ORDER BY razred");
while ($row = $res->fetch_assoc()) { $razredi[] = $row['razred']; }
$res->free();

// Trenutno izabrani razred (GET ima prednost da bi radili linkovi/refresh bez POST-a)
$selRazred = trim($_GET['r'] ?? ($_POST['r'] ?? ''));
if ($selRazred !== '' && !in_array($selRazred, $razredi, true)) {
    // ako razred ne postoji među korisnicima, tretiraj kao nepodešeno
    $selRazred = '';
}

/* ===== TESTOVI (filtrirani po izabranom razredu) ===== */
$testovi = [];
if ($selRazred !== '') {
    $ts = $conn->prepare("SELECT id, naslov FROM testovi WHERE razred=? ORDER BY id DESC");
    $ts->bind_param("s", $selRazred);
} else {
    // ništa ne prikazuj dok se ne izabere razred (čuva UX)
    $ts = $conn->prepare("SELECT id, naslov FROM testovi WHERE 1=0");
}
$ts->execute(); $ts->bind_result($tid,$tnaslov);
while ($ts->fetch()){ $testovi[]=['id'=>$tid,'naslov'=>$tnaslov]; }
$ts->close();

/* ===== FILTRIRANI UČENICI PO RAZREDU ===== */
$ucenici = [];
if ($selRazred !== '') {
    $st=$conn->prepare("SELECT email, razred FROM korisnici WHERE razred=? ORDER BY email");
    $st->bind_param("s",$selRazred);
    $st->execute(); $st->bind_result($uemail,$urazred);
    while($st->fetch()){
      $namePart = explode('@',$uemail)[0];
      $ime = ucwords(str_replace('.',' ',$namePart));
      $ucenici[]=['ime'=>$ime,'email'=>$uemail,'razred'=>$urazred];
    }
    $st->close();
}

/* ===== SUBMIT: dodela ===== */
$msg = '';
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['__assign'])) {
  try{
    $postRazred = trim($_POST['r'] ?? '');
    // osnovna validacija protoka: mora razred + test iz tog razreda
    $test_id   = (int)($_POST['test_id'] ?? 0);
    $attempts = 1;
    $deadline = normalize_dtlocal_to_mysql($_POST['deadline'] ?? '');
    $emails   = $_POST['emails'] ?? [];

    if ($postRazred === '' || $test_id<=0 || empty($emails)) {
        $msg='⚠️ Izaberi razred, test i bar jednog učenika.';
    } else {
        // obavezno proveri da izabrani test pripada izabranom razredu
        $chk = $conn->prepare("SELECT COUNT(*) FROM testovi WHERE id=? AND razred=?");
        $chk->bind_param("is", $test_id, $postRazred);
        $chk->execute();
        $chk->bind_result($okCount); $chk->fetch(); $chk->close();
        if ((int)$okCount !== 1) {
            throw new RuntimeException("Izabrani test ne pripada razredu ".$postRazred.".");
        }

        $q=$conn->prepare("INSERT INTO dodeljeni_testovi (test_id,email,attempts_allowed,deadline,assigned_at) VALUES (?,?,?,?,NOW())");
        foreach($emails as $em){
            $em=trim($em); if($em==='') continue;
            $q->bind_param("isis", $test_id, $em, $attempts, $deadline);
            $q->execute();
        }
        $q->close();
        $msg='✅ Nova dodela je sačuvana. Učenik može jednom da uradi ovu dodelu.';
    }
  } catch (Throwable $ex){
    $msg = "❌ Greška dodele: ".$ex->getMessage();
  }

  // nakon POST-a želimo da ostanemo na istom razredu u UI:
  if ($selRazred === '' && !empty($_POST['r'])) {
      $selRazred = $_POST['r'];
  }
}
?>
<!doctype html>
<html lang="sr">
<head>
<?php require_once __DIR__ . '/analytics.php'; ?>
<meta charset="utf-8">
<title>Dodeli testove</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">📤 Dodeli testove</h3>
    <a href="dashboard.php" class="btn btn-secondary">⬅️ Nazad</a>
  </div>

  <?php if($msg): ?><div class="alert alert-info"><?= e($msg) ?></div><?php endif; ?>

  <!-- KORAK 1: IZBOR RAZREDA (GET forma da bi radilo bez JS) -->
  <form method="get" class="bg-white p-3 rounded shadow-sm mb-3">
    <div class="row g-3 align-items-end">
      <div class="col-md-4">
        <label class="form-label">1) Izaberi razred</label>
        <select name="r" class="form-select" onchange="this.form.submit()" required>
          <option value="">— izaberi razred —</option>
          <?php foreach($razredi as $r): ?>
            <option value="<?= e($r) ?>" <?= $selRazred===$r?'selected':'' ?>><?= e($r) ?></option>
          <?php endforeach; ?>
        </select>
        <noscript><button class="btn btn-primary mt-2">Primeni</button></noscript>
      </div>
      <div class="col-md-8">
        <div class="form-text">
          Prvo izaberi razred — zatim će se učitati testovi i lista učenika tog razreda.
        </div>
      </div>
    </div>
  </form>

  <!-- KORAK 2..4: TEST + OPCIJE + UČENICI (POST forma za dodelu) -->
  <form method="post" class="bg-white p-3 rounded shadow-sm">
    <input type="hidden" name="__assign" value="1">
    <input type="hidden" name="r" value="<?= e($selRazred) ?>">

    <div class="row g-3">
      <div class="col-md-5">
        <label class="form-label">2) Test za razred <?= $selRazred ? '<span class="badge bg-secondary">'.e($selRazred).'</span>' : '' ?></label>
        <select name="test_id" class="form-select" <?= $selRazred===''?'disabled':'' ?> required>
          <option value=""><?= $selRazred===''?'— prvo izaberi razred —':'— izaberi test —' ?></option>
          <?php foreach($testovi as $t): ?>
            <option value="<?= (int)$t['id'] ?>">#<?= (int)$t['id'] ?> — <?= e($t['naslov']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($selRazred!=='' && count($testovi)===0): ?>
          <div class="form-text text-danger">Nema testova za izabrani razred.</div>
        <?php endif; ?>
      </div>

      <div class="col-md-2">
        <label class="form-label">Pokušaja</label>
        <input type="number" value="1" class="form-control" disabled>
        <div class="form-text">Svaka dodela se radi jednom.</div>
      </div>

      <div class="col-md-3">
        <label class="form-label">Rok (opciono)</label>
        <input type="datetime-local" name="deadline" class="form-control" <?= $selRazred===''?'disabled':'' ?>>
      </div>

      <div class="col-md-12">
        <div class="alert alert-secondary mb-0">Ponovna dodela istog testa pravi novu dodelu za učenika.</div>
      </div>
    </div>

    <hr>

    <div class="d-flex justify-content-between align-items-center">
      <h5 class="mb-2">3) Učenici <?= $selRazred ? '(' . e($selRazred) . ')' : '' ?></h5>
      <div class="btn-group">
        <button class="btn btn-sm btn-outline-primary" type="button" id="checkAll" <?= $selRazred===''?'disabled':'' ?>>Označi sve</button>
        <button class="btn btn-sm btn-outline-secondary" type="button" id="uncheckAll" <?= $selRazred===''?'disabled':'' ?>>Poništi sve</button>
        <button class="btn btn-sm btn-outline-success" type="button" id="firstHalf" <?= $selRazred===''?'disabled':'' ?>>1. polovina</button>
        <button class="btn btn-sm btn-outline-success" type="button" id="secondHalf" <?= $selRazred===''?'disabled':'' ?>>2. polovina</button>
      </div>
    </div>

    <div class="table-responsive" style="max-height:420px;overflow:auto;">
      <table class="table table-sm align-middle">
        <thead><tr><th></th><th>Ime i prezime</th><th>Email</th><th>Razred</th></tr></thead>
        <tbody id="studentsTable">
          <?php if ($selRazred==='' || count($ucenici)===0): ?>
            <tr><td colspan="4" class="text-muted"><?= $selRazred==='' ? 'Prvo izaberi razred.' : 'Nema učenika u ovom razredu.' ?></td></tr>
          <?php else: ?>
            <?php foreach($ucenici as $u): ?>
              <tr>
                <td><input type="checkbox" name="emails[]" value="<?= e($u['email']) ?>"></td>
                <td><?= e($u['ime']) ?></td>
                <td><?= e($u['email']) ?></td>
                <td><?= e($u['razred']) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="mt-3 text-end">
      <button class="btn btn-success" <?= $selRazred===''?'disabled':'' ?>> 💾 Sačuvaj dodelu</button>
    </div>
  </form>
</div>

<script>
const $ = s => document.querySelector(s);
const $$ = s => Array.from(document.querySelectorAll(s));

function enableOps(enabled){
  ['checkAll','uncheckAll','firstHalf','secondHalf'].forEach(id=>{
    const el = document.getElementById(id);
    if (el) el.disabled = !enabled;
  });
}
enableOps(<?= $selRazred!=='' ? 'true' : 'false' ?>);

if ($('#checkAll')) $('#checkAll').onclick = ()=> $$('input[name="emails[]"]').forEach(cb=>cb.checked=true);
if ($('#uncheckAll')) $('#uncheckAll').onclick = ()=> $$('input[name="emails[]"]').forEach(cb=>cb.checked=false);

function selectHalf(first){
  const rows = $$('#studentsTable tr');
  const checks = rows.map(r=>r.querySelector('input[type="checkbox"]')).filter(Boolean);
  const half = Math.ceil(checks.length/2);
  checks.forEach((cb,i)=>{ cb.checked = first ? (i<half) : (i>=half); });
}
if ($('#firstHalf'))  $('#firstHalf').onclick  = ()=> selectHalf(true);
if ($('#secondHalf')) $('#secondHalf').onclick = ()=> selectHalf(false);
</script>
</body>
</html>
