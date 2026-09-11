<?php
session_start();
if (!isset($_SESSION["email"])) { header("Location: index.php"); exit; }

$email  = $_SESSION["email"];
$testId = isset($_POST["test_id"]) ? (int)$_POST["test_id"] : 0;
$asked  = $_POST["asked"] ?? [];
$answers= $_POST["odgovori"] ?? [];

// privremeno uključi detaljne greške da ne dobijemo “prazan” 500
ini_set('display_errors','1');
error_reporting(E_ALL);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function tolower_norm($s){
  return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

/* === EDUZONE PATCH: funkcije za proveru kolone i ocenu za 12 pitanja === */
function col_exists(mysqli $c,string $t,string $col):bool{
  $r=$c->query("SHOW COLUMNS FROM `$t` LIKE '".$c->real_escape_string($col)."'");
  $ok=$r && $r->num_rows>0; if($r) $r->free(); return $ok;
}
function ocena_za_12(int $points, int $max): ?int {
  if ($max !== 12) return null;
  if ($points <= 4) return 1;
  if ($points <= 6) return 2;
  if ($points <= 8) return 3;
  if ($points <=10) return 4;
  return 5; // 11-12 = 5
}

?><!doctype html>
<html lang="sr">
<head>
<?php require_once __DIR__ . '/analytics.php'; ?>
<meta charset="utf-8">
<title>Slanje testa</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  .q-card.correct { border-color:#198754; }
  .q-card.wrong   { border-color:#dc3545; }
</style>
</head>
<body class="bg-light p-4">
<div class="container">
<?php
if (!$testId || empty($asked)) {
  echo '<div class="alert alert-danger">Nedostaju podaci o testu. <a class="btn btn-sm btn-secondary ms-2" href="dashboard.php">Nazad</a></div>';
  exit;
}

require __DIR__ . '/db_connect.php';

// Uključi “exceptions” za mysqli, pa ćemo ih elegantno uhvatiti
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
  // === Test (naslov) ===
  /* === EDUZONE PATCH: uzmi i broj_pitanja ako kolona postoji === */
  $imaBroj = col_exists($conn, 'testovi', 'broj_pitanja');
  $sqlTest = $imaBroj 
    ? "SELECT id, naslov, broj_pitanja FROM testovi WHERE id = {$testId}"
    : "SELECT id, naslov FROM testovi WHERE id = {$testId}";
  $rs = $conn->query($sqlTest);
  $test = $rs->fetch_assoc();
  $rs->free();
  $naslov = $test ? $test['naslov'] : ("Test #".$testId);

  // === Pitanja koja su učeniku prikazana ===
  $ids = array_values(array_unique(array_map('intval', $asked)));
  if (empty($ids)) { throw new Exception("Lista pitanja je prazna."); }
  $idsList = implode(',', $ids);

  // === Postoji li kolona 'tacan' u tabeli 'odgovori'? ===
  $hasTacan = col_exists($conn,'odgovori','tacan');

  // === Pitanja (tekst, tip) ===
  $pitanjaInfo = []; // pid => ['tekst'=>..., 'tip'=>...]
  $qP = $conn->query("SELECT id, tekst, tip FROM pitanja WHERE id IN ($idsList)");
  while ($row = $qP->fetch_assoc()) {
    $pitanjaInfo[(int)$row['id']] = ['tekst'=>$row['tekst'], 'tip'=>$row['tip']];
  }
  $qP->free();

  // === Tačni odgovori (ako imamo kolonu 'tacan') ===
  $correctMap = []; // pid => [tekst...]
  foreach ($ids as $pid) {
    $pid = (int)$pid;
    $corr = [];
    if ($hasTacan) {
      $res = $conn->query("SELECT tekst FROM odgovori WHERE pitanje_id={$pid} AND tacan=1");
      while ($c = $res->fetch_assoc()) { $corr[] = $c['tekst']; }
      $res->free();
    }
    $correctMap[$pid] = $corr;
  }

  // === Učenikovi odgovori (uvek niz) ===
  $studentMap = []; // pid => [odg...]
  foreach ($ids as $pid) {
    if (!isset($answers[$pid])) { $studentMap[$pid] = []; }
    else {
      $v = $answers[$pid];
      $studentMap[$pid] = is_array($v) ? array_map('strval',$v) : [ (string)$v ];
    }
  }

  // === Gradiranje ===
  /* === EDUZONE PATCH: maksimalan broj pitanja iz baze (ako postoji kolona), inače count($ids) === */
  $maxPoints = $imaBroj ? (int)($test['broj_pitanja'] ?? count($ids)) : count($ids);
  if ($maxPoints < 1) $maxPoints = count($ids);

  $correctCount = 0;
  $perQuestion = [];

  foreach ($ids as $pid) {
    $tip  = $pitanjaInfo[$pid]['tip'] ?? 'radio';
    $stud = $studentMap[$pid] ?? [];
    $corr = $correctMap[$pid] ?? [];

    $isCorrect = false;
    if ($tip === 'text') {
      $studNorm = array_map(function($s){ return tolower_norm(trim($s)); }, $stud);
      $corrNorm = array_map(function($s){ return tolower_norm(trim($s)); }, $corr);
      foreach ($studNorm as $s) { if ($s !== '' && in_array($s, $corrNorm, true)) { $isCorrect = true; break; } }
    } else {
      $s = $stud; $c = $corr; sort($s); sort($c);
      // Ako nema 'tacan', ne možemo znati tačno – tretiramo kao netačno (i naglasimo u UI/mailu)
      $isCorrect = ($hasTacan ? ($s == $c) : false);
    }
    if ($isCorrect) $correctCount++;

    $perQuestion[] = [
      'pid' => $pid,
      'tekst' => $pitanjaInfo[$pid]['tekst'] ?? ('Pitanje #'.$pid),
      'tip' => $tip,
      'student' => $stud,
      'correct' => $corr,
      'is_correct' => $isCorrect
    ];
  }

  /* === EDUZONE PATCH: izračun ocene po tvojoj skali kad je max=12 === */
  $ocena = ocena_za_12($correctCount, $maxPoints);

  // === Označi “odradjen” (auto-detekcija kolone vreme/datum) ===
  $doneCol = null;
  $c1 = $conn->query("SHOW COLUMNS FROM odradjeni_testovi LIKE 'vreme'");
  if ($c1 && $c1->num_rows > 0) { $doneCol = 'vreme'; }
  if ($c1) { $c1->free_result(); }
  if (!$doneCol) {
    $c2 = $conn->query("SHOW COLUMNS FROM odradjeni_testovi LIKE 'datum'");
    if ($c2 && $c2->num_rows > 0) { $doneCol = 'datum'; }
    if ($c2) { $c2->free_result(); }
  }
  if (!$doneCol) {
    throw new Exception("Tabela 'odradjeni_testovi' nema kolonu 'vreme' niti 'datum'. Dodaj jednu od njih (DATETIME).");
  }

  $ins = $conn->prepare("INSERT INTO odradjeni_testovi (email, test_id, {$doneCol}) VALUES (?, ?, NOW())");
  $ins->bind_param("si", $email, $testId);
  $ins->execute(); 
  $ins->close();

  // (opciono) ako postoje kolone tacnih/max/ocena — upiši ih
  $has_tacnih = col_exists($conn,'odradjeni_testovi','tacnih');
  $has_max    = col_exists($conn,'odradjeni_testovi','max');
  $has_ocena  = col_exists($conn,'odradjeni_testovi','ocena');
  if ($has_tacnih || $has_max || $has_ocena) {
    $set = [];
    if ($has_tacnih) $set[] = "tacnih=".((int)$correctCount);
    if ($has_max)    $set[] = "max=".((int)$maxPoints);
    if ($has_ocena)  $set[] = "ocena=". (is_null($ocena) ? "NULL" : (int)$ocena);
    if ($set) {
      $sql = "UPDATE odradjeni_testovi SET ".implode(',', $set)." WHERE email=? AND test_id=?";
      $up  = $conn->prepare($sql);
      $up->bind_param("si",$email,$testId);
      $up->execute(); $up->close();
    }
  }

  // === Email nastavniku — ✔️/❌ + učenikov i tačan odgovor ===
  $ADMIN_EMAIL = 'admin@example.invalid';
  /* === EDUZONE PATCH: ocena u subject-u ako postoji === */
  $subject = "Rezultat testa: {$naslov} — {$email} ({$correctCount}/{$maxPoints})"
           . (!is_null($ocena) ? " | Ocena: {$ocena}" : "");

  $bodyHtml = "<h3>Rezultat testa: ".h($naslov)."</h3>
  <p>Učenik: ".h($email)."<br>Tačnih: <strong>{$correctCount}</strong> / {$maxPoints}</p>"
  .(!is_null($ocena) ? "<p><strong>Ocena:</strong> {$ocena}</p>" : "")
  .($hasTacan ? "" : "<p style='color:#dc3545'><strong>Napomena:</strong> u tabeli <code>odgovori</code> ne postoji kolona <code>tacan</code>, pa tačni odgovori nisu označeni. Dodaj je:<br><code>ALTER TABLE odgovori ADD COLUMN tacan TINYINT(1) NOT NULL DEFAULT 0 AFTER tekst;</code></p>")
  ."<hr>";

  foreach ($perQuestion as $i => $q) {
      $n = $i+1;
      $mark = $q['is_correct'] ? "✔️" : "❌";
      $studTxt = empty($q['student']) ? "<em>(prazno)</em>" : h(implode(', ', $q['student']));
      $corrTxt = empty($q['correct']) ? "<em>(nije definisano)</em>" : h(implode(', ', $q['correct']));
      $bodyHtml .= "<p><strong>{$n}. ".h($q['tekst'])."</strong> {$mark}<br>
      <strong>Učenik:</strong> {$studTxt}<br>
      <strong>Tačno:</strong> {$corrTxt}</p>";
  }

  require_once __DIR__ . '/result_mail.php';
  ez_send_result_mail($subject, $bodyHtml);

  // === Prikaz rezultata učeniku (zeleno/crveno) ===
  echo '<div class="card shadow-sm mb-4"><div class="card-body">';
  echo '<h4 class="mb-0">🧪 '.h($naslov).'</h4>';
  echo '<div>Učenik: <strong>'.h($email).'</strong></div>';
  echo '<div class="mt-2 fs-5">Rezultat: <strong>'.$correctCount.' / '.$maxPoints.'</strong></div>';
  /* === EDUZONE PATCH: prikaži ocenu ako postoji === */
  if (!is_null($ocena)) {
    echo '<div class="mt-2"><span class="badge bg-primary">Ocena: '.(int)$ocena.'</span></div>';
  }
  if (!$hasTacan) {
    echo '<div class="alert alert-warning mt-3"><strong>Napomena:</strong> u tabeli <code>odgovori</code> nema kolone <code>tacan</code>, pa tačni odgovori nisu označeni. Dodaj je:<br><code>ALTER TABLE odgovori ADD COLUMN tacan TINYINT(1) NOT NULL DEFAULT 0 AFTER tekst;</code></div>';
  }
  echo '</div></div>';

  foreach ($perQuestion as $i => $q) {
    $isOk = $q['is_correct'];
    $studTxt = empty($q['student']) ? "<em>(prazno)</em>" : h(implode(', ', $q['student']));
    $corrTxt = empty($q['correct']) ? "<em>(nije definisano)</em>" : h(implode(', ', $q['correct']));
    echo '<div class="card mb-3 q-card '.($isOk?'correct border-success':'wrong border-danger').'"><div class="card-body">';
    echo '<h6 class="card-title mb-2">'.($i+1).'. '.h($q['tekst']).' '.($isOk?'✅':'❌').'</h6>';
    echo '<p class="mb-1"><strong>Vaš odgovor:</strong> '.$studTxt.'</p>';
    if ($isOk) { echo '<p class="mb-0 text-success"><strong>Tačno.</strong></p>'; }
    else { echo '<p class="mb-0 text-danger"><strong>Tačan odgovor:</strong> '.$corrTxt.'</p>'; }
    echo '</div></div>';
  }

  echo '<a href="dashboard.php" class="btn btn-secondary mt-2">⬅️ Nazad na početnu</a>';

} catch (Throwable $e) {
  echo '<div class="alert alert-danger"><strong>Greška:</strong> '.h($e->getMessage()).'</div>';
  echo '<a href="dashboard.php" class="btn btn-secondary mt-2">⬅️ Nazad na početnu</a>';
}
?>
</div>
</body>
</html>
