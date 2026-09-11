<?php
// zavrsi_test.php
ini_set('display_errors',1);
error_reporting(E_ALL);

session_start();
if (empty($_SESSION['email'])) { exit('⛔ Morate biti ulogovani.'); }
$userEmail = $_SESSION['email'];

$test_id = isset($_POST['test_id']) ? (int)$_POST['test_id'] : 0;
$assignKey = trim((string)($_POST['assign_key'] ?? ''));
$assignKeyDb = $assignKey !== '' ? $assignKey : null;
$asked   = array_map('intval', $_POST['asked'] ?? []);
if ($test_id<=0 || !$asked){ exit('Nedostaju podaci o testu.'); }

require __DIR__ . '/db_connect.php';
require_once __DIR__ . '/test_visibility.php';

function table_exists(mysqli $c,string $t):bool{
  $r=$c->query("SHOW TABLES LIKE '".$c->real_escape_string($t)."'");
  $ok=$r && $r->num_rows>0; if($r) $r->free(); return $ok;
}
function col_exists(mysqli $c,string $t,string $col):bool{
  $r=$c->query("SHOW COLUMNS FROM `$t` LIKE '".$c->real_escape_string($col)."'");
  $ok=$r && $r->num_rows>0; if($r) $r->free(); return $ok;
}
function h($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }

function drop_unique_email_test(mysqli $conn, string $table): void {
  $idx = $conn->query("SHOW INDEX FROM `$table` WHERE Non_unique = 0");
  $keys = [];
  while ($row = $idx->fetch_assoc()) {
    if ($row['Key_name'] === 'PRIMARY') continue;
    $keys[$row['Key_name']][] = $row['Column_name'];
  }
  $idx->free();

  foreach ($keys as $key => $columns) {
    sort($columns);
    if ($columns === ['email', 'test_id'] || $columns === ['attempt_no', 'email', 'test_id']) {
      $conn->query("ALTER TABLE `$table` DROP INDEX `$key`");
    }
  }
}

/* Ocena kada je maksimalno 12 poena */
function izracunaj_ocenu(int $points, int $max): ?int {
  if ($max !== 12) return null;
  if ($points <= 4) return 1;
  if ($points <= 6) return 2;
  if ($points <= 8) return 3;
  if ($points <=10) return 4;
  return 5; // 11-12
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* Meta testa (naslov + opciono broj_pitanja) */
$ima_broj = col_exists($conn,'testovi','broj_pitanja');

if ($ima_broj) {
  $tt = $conn->prepare("SELECT naslov, broj_pitanja FROM testovi WHERE id=?");
} else {
  $tt = $conn->prepare("SELECT naslov FROM testovi WHERE id=?");
}
$tt->bind_param("i",$test_id);
$tt->execute();
$meta = $tt->get_result()->fetch_assoc() ?: [];
$tt->close();

$naslov = $meta['naslov'] ?? ('Test #'.$test_id);
$maxPoints = $ima_broj ? (int)($meta['broj_pitanja'] ?? count($asked)) : count($asked);
if ($maxPoints < 1) $maxPoints = count($asked);

/* Osiguraj tabelu odgovori_korisnika */
$conn->query("
CREATE TABLE IF NOT EXISTS odgovori_korisnika (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(191) NOT NULL,
  test_id INT NOT NULL,
  assign_key VARCHAR(32) NULL,
  attempt_no INT NOT NULL DEFAULT 1,
  pitanje_id INT NOT NULL,
  odgovor_id INT NULL,
  text_answer TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX ok_ix (email(100), test_id, attempt_no, pitanje_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
if (!col_exists($conn,'odgovori_korisnika','assign_key')) {
  $conn->query("ALTER TABLE odgovori_korisnika ADD COLUMN assign_key VARCHAR(32) NULL AFTER test_id");
}

/* Sledeći attempt */
if ($assignKeyDb === null) {
  $mx = $conn->prepare("SELECT COALESCE(MAX(attempt_no),0) FROM odgovori_korisnika WHERE email=? AND test_id=? AND assign_key IS NULL");
  $mx->bind_param("si",$userEmail,$test_id);
} else {
  $mx = $conn->prepare("SELECT COALESCE(MAX(attempt_no),0) FROM odgovori_korisnika WHERE email=? AND test_id=? AND assign_key=?");
  $mx->bind_param("sis",$userEmail,$test_id,$assignKeyDb);
}
$mx->execute(); $mx->bind_result($m); $mx->fetch(); $mx->close();
$attempt_no = (int)$m + 1;
if ($attempt_no > 1) {
  exit('✅ Već ste završili ovaj test za ovu dodelu.');
}

/* Ulazi */
$radio = $_POST['odgovor']       ?? [];   // qid => aid
$multi = $_POST['odgovor_multi'] ?? [];   // qid => [aid,...]
$texts = $_POST['odgovor_text']  ?? [];   // qid => string

/* Tipovi i tačni */
$idList = implode(',', $asked);
$qInfo = []; // pid => ['tip'=>..., 'tekst'=>...]
$hasTacan = col_exists($conn,'odgovori','tacan');

$qp = $conn->query("SELECT id, tekst, tip FROM pitanja WHERE id IN ($idList)");
while($r=$qp->fetch_assoc()){ $qInfo[(int)$r['id']] = ['tekst'=>$r['tekst'], 'tip'=>strtolower(trim($r['tip']))]; }
$qp->close();

$correct = []; // pid => [aid,...]
$sampTxt = []; // pid => primer teksta
if ($hasTacan){
  $qa = $conn->query("SELECT pitanje_id, id, tekst, tacan FROM odgovori WHERE pitanje_id IN ($idList)");
  while($a=$qa->fetch_assoc()){
    $pid=(int)$a['pitanje_id'];
    if ((int)$a['tacan']===1){
      $correct[$pid] = $correct[$pid] ?? [];
      $correct[$pid][] = (int)$a['id'];
      $sampTxt[$pid] = $a['tekst'];
    }
  }
  $qa->close();
}

/* Snimi odgovore za attempt */
$conn->begin_transaction();
$ins = $conn->prepare("INSERT INTO odgovori_korisnika (email,test_id,assign_key,attempt_no,pitanje_id,odgovor_id,text_answer) VALUES (?,?,?,?,?,?,?)");

foreach ($asked as $pid){
  $tip = $qInfo[$pid]['tip'] ?? 'radio';

  if ($tip==='checkbox'){
    $sel = [];
    if (isset($multi[$pid]) && is_array($multi[$pid])) foreach($multi[$pid] as $v) $sel[]=(int)$v;
    if (!$sel){ $tmp=null; $txt=null; $ins->bind_param("sisiiis",$userEmail,$test_id,$assignKeyDb,$attempt_no,$pid,$tmp,$txt); $ins->execute(); }
    else {
      foreach($sel as $aid){ $tmp=$aid; $txt=null; $ins->bind_param("sisiiis",$userEmail,$test_id,$assignKeyDb,$attempt_no,$pid,$tmp,$txt); $ins->execute(); }
    }
  } elseif ($tip==='text'){
    $tmp=null; $txt = isset($texts[$pid]) ? trim((string)$texts[$pid]) : '';
    $ins->bind_param("sisiiis",$userEmail,$test_id,$assignKeyDb,$attempt_no,$pid,$tmp,$txt); $ins->execute();
  } else { // radio
    $sel = isset($radio[$pid]) ? (int)$radio[$pid] : null;
    $txt=null; $tmp=$sel;
    $ins->bind_param("sisiiis",$userEmail,$test_id,$assignKeyDb,$attempt_no,$pid,$tmp,$txt); $ins->execute();
  }
}
$ins->close();
$conn->commit();

/* Bodovanje */
$points=0;
$details=[];
foreach ($asked as $pid){
  $tip = $qInfo[$pid]['tip'] ?? 'radio';
  $qTxt= $qInfo[$pid]['tekst'] ?? ('Pitanje #'.$pid);

  $studIds=[]; $studTxt=null;
  if ($tip==='checkbox'){
    if (isset($multi[$pid]) && is_array($multi[$pid])) foreach($multi[$pid] as $v) $studIds[]=(int)$v;
    sort($studIds);
  } elseif ($tip==='text'){
    $studTxt = isset($texts[$pid]) ? trim((string)$texts[$pid]) : '';
  } else {
    if (isset($radio[$pid]) && $radio[$pid]!=='') $studIds[]=(int)$radio[$pid];
  }

  $isCorrect=false;
  if ($tip==='text'){
    if ($hasTacan && isset($sampTxt[$pid])){
      $isCorrect = (mb_strtolower(trim($sampTxt[$pid])) === mb_strtolower((string)$studTxt) && $studTxt!=='');
    }
  } elseif ($tip==='checkbox'){
    $corr = $correct[$pid] ?? [];
    sort($corr);
    $isCorrect = $hasTacan ? ($studIds===$corr) : false;
  } else {
    $corr = $correct[$pid] ?? [];
    $isCorrect = $hasTacan ? (count($studIds)==1 && in_array($studIds[0], $corr,true)) : false;
  }
  if ($isCorrect) $points++;

  $details[] = [
    'pid'=>$pid,'tip'=>$tip,'q'=>$qTxt,
    'studIds'=>$studIds,'studTxt'=>$studTxt,
    'corrIds'=>$correct[$pid]??[], 'corrTxt'=>$sampTxt[$pid]??null,
    'ok'=>$isCorrect
  ];
}

/* Ocena (ako max=12) */
$ocena = izracunaj_ocenu($points, $maxPoints);

/* Evidencija odrađenog testa */
if (!table_exists($conn,'odradjeni_testovi')){
  $conn->query("CREATE TABLE odradjeni_testovi(
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(191) NOT NULL,
    test_id INT NOT NULL,
    assign_key VARCHAR(32) NULL,
    vreme DATETIME NOT NULL,
    UNIQUE KEY u1 (email(100),test_id,assign_key)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
if (!col_exists($conn,'odradjeni_testovi','assign_key')) {
  $conn->query("ALTER TABLE odradjeni_testovi ADD COLUMN assign_key VARCHAR(32) NULL AFTER test_id");
}
drop_unique_email_test($conn, 'odradjeni_testovi');
$timeCol = col_exists($conn,'odradjeni_testovi','vreme') ? 'vreme' :
           (col_exists($conn,'odradjeni_testovi','datum') ? 'datum' : null);
if (!$timeCol){
  $conn->query("ALTER TABLE odradjeni_testovi ADD COLUMN vreme DATETIME NULL");
  $timeCol = 'vreme';
}
$mk = $conn->prepare("INSERT IGNORE INTO odradjeni_testovi (email,test_id,assign_key,{$timeCol}) VALUES (?,?,?,NOW())");
$mk->bind_param("sis",$userEmail,$test_id,$assignKeyDb); $mk->execute(); $mk->close();

/* Opciona dopuna kolona tacnih/max/ocena */
$has_tacnih = col_exists($conn,'odradjeni_testovi','tacnih');
$has_max    = col_exists($conn,'odradjeni_testovi','max');
$has_ocena  = col_exists($conn,'odradjeni_testovi','ocena');
if ($has_tacnih || $has_max || $has_ocena) {
  $set = [];
  if ($has_tacnih) $set[] = "tacnih=".((int)$points);
  if ($has_max)    $set[] = "max=".((int)$maxPoints);
  if ($has_ocena)  $set[] = "ocena=". (is_null($ocena) ? "NULL" : (int)$ocena);
  if ($set) {
    $sql = "UPDATE odradjeni_testovi SET ".implode(',', $set).", {$timeCol}=NOW() WHERE email=? AND test_id=? AND ".($assignKeyDb === null ? "assign_key IS NULL" : "assign_key=?");
    $up  = $conn->prepare($sql);
    if ($assignKeyDb === null) $up->bind_param("si",$userEmail,$test_id);
    else $up->bind_param("sis",$userEmail,$test_id,$assignKeyDb);
    $up->execute(); $up->close();
  }
}

/* ❗ SNAPSHOT rezultata — da se istorija ne menja posle izmene pitanja */
$conn->query("
CREATE TABLE IF NOT EXISTS test_rezultati (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(191) NOT NULL,
  test_id INT NOT NULL,
  assign_key VARCHAR(32) NULL,
  attempt_no INT NOT NULL,
  points INT NOT NULL,
  max_points INT NOT NULL,
  submitted_at DATETIME NOT NULL,
  UNIQUE KEY u_email_test_attempt (email(100), test_id, assign_key, attempt_no),
  KEY ix_test_time (test_id, submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
if (!col_exists($conn,'test_rezultati','assign_key')) {
  $conn->query("ALTER TABLE test_rezultati ADD COLUMN assign_key VARCHAR(32) NULL AFTER test_id");
}
drop_unique_email_test($conn, 'test_rezultati');

$insRes = $conn->prepare("
  INSERT INTO test_rezultati (email,test_id,assign_key,attempt_no,points,max_points,submitted_at)
  VALUES (?,?,?,?,?,?,NOW())
  ON DUPLICATE KEY UPDATE
    points=VALUES(points),
    max_points=VALUES(max_points),
    submitted_at=VALUES(submitted_at)
");
$insRes->bind_param("sisiii", $userEmail, $test_id, $assignKeyDb, $attempt_no, $points, $maxPoints);
$insRes->execute();
$insRes->close();

/* Email nastavniku (neobavezno) */
$ADMIN_EMAIL = 'admin@example.invalid';
$body = "<h3>Rezultat testa: ".h($naslov)."</h3>"
      . "<p>Učenik: ".h($userEmail)."<br>Tačnih: <strong>{$points}</strong> / {$maxPoints} (pokušaj #{$attempt_no})</p>"
      . (!is_null($ocena) ? "<p><strong>Ocena:</strong> {$ocena}</p>" : "")
      . (!$hasTacan ? "<p style='color:#d00'>Napomena: kolona <code>tacan</code> ne postoji u tabeli <code>odgovori</code>, zato automatsko bodovanje nije potpuno.</p>" : "")
      . "<hr>";
$idToTxt=[];
$map = $conn->query("SELECT id, tekst FROM odgovori WHERE pitanje_id IN ($idList)");
while($r=$map->fetch_assoc()) $idToTxt[(int)$r['id']]=$r['tekst'];
$map->close();
foreach($details as $i=>$d){
  $studTxt = ($d['tip']==='text') ? ($d['studTxt']!==''? h($d['studTxt']) : '<em>(prazno)</em>')
                                  : ( $d['studIds'] ? h(implode(', ', array_map(fn($x)=>$idToTxt[$x]??('#'.$x), $d['studIds']))) : '<em>(prazno)</em>');
  $corrTxt = ($d['tip']==='text') ? ($d['corrTxt']? h($d['corrTxt']) : '<em>(nije definisano)</em>')
                                  : ( $d['corrIds'] ? h(implode(', ', array_map(fn($x)=>$idToTxt[$x]??('#'.$x), $d['corrIds']))) : '<em>(nije definisano)</em>');
  $body .= "<p><strong>".($i+1).". ".h($d['q'])."</strong> ".($d['ok']?'✔️':'❌')."<br>"
         . "<strong>Učenik:</strong> {$studTxt}<br>"
         . "<strong>Tačno:</strong> {$corrTxt}</p>";
}
require_once __DIR__ . '/result_mail.php';
$subject = "Rezultat: {$naslov} ({$points}/{$maxPoints})" . (!is_null($ocena) ? " | Ocena: {$ocena}" : "");
ez_send_result_mail($subject, $body);

/* 🔑 Propusnica za detaljan pregled (30 min u ovoj sesiji) */
if (!isset($_SESSION['grant_view'])) $_SESSION['grant_view'] = [];
$_SESSION['grant_view'][test_result_key($test_id, $attempt_no, $assignKeyDb)] = time();

/* Pregled */
header('Location: pregled_rezultata?' . http_build_query([
  'test_id' => $test_id, 'attempt' => $attempt_no, 'ak' => $assignKey
]), true, 303);
exit;
