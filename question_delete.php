<?php
session_start();
require_once __DIR__ . '/admin_config.php';
if (!ez_is_admin()) { exit('⛔'); }

require __DIR__.'/db_connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$test_id = (int)($_GET['test_id'] ?? 0);
$id      = (int)($_GET['id'] ?? 0);
$csrf    = $_GET['csrf'] ?? null; // opciono — za kompatibilnost

if(!$test_id || !$id) { exit('Nedostaju parametri.'); }

// (opciono) CSRF: validiraj samo ako je poslato
if ($csrf !== null) {
  if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    exit('CSRF token nije važeći.');
  }
}

try {
  // 1) Proveri da pitanje pripada datom testu (defense-in-depth)
  $chk = $conn->prepare("SELECT 1 FROM pitanja WHERE id=? AND test_id=? LIMIT 1");
  $chk->bind_param("ii", $id, $test_id);
  $chk->execute(); $chk->store_result();
  if ($chk->num_rows === 0) {
    $chk->close();
    header("Location: questions.php?test_id={$test_id}&msg=".rawurlencode("Pitanje nije pronađeno.")); exit;
  }
  $chk->close();

  $conn->begin_transaction();

  // 2) Obriši odgovore vezane za pitanje
  $st=$conn->prepare("DELETE FROM odgovori WHERE pitanje_id=?");
  $st->bind_param("i",$id); 
  $st->execute(); 
  $st->close();

  // 3) Obriši samo to pitanje u okviru tog testa
  $st=$conn->prepare("DELETE FROM pitanja WHERE id=? AND test_id=?");
  $st->bind_param("ii",$id,$test_id); 
  $st->execute(); 
  $rowAff = $st->affected_rows;
  $st->close();

  $conn->commit();

  $msg = $rowAff>0 ? "Pitanje #{$id} obrisano." : "Pitanje nije obrisano.";
  header("Location: questions.php?test_id={$test_id}&msg=".rawurlencode($msg)); 
  exit;

} catch (Throwable $e) {
  if ($conn->errno) { $conn->rollback(); }
  // Prikaz bez 500
  header('Content-Type: text/html; charset=UTF-8');
  echo '<div style="max-width:800px;margin:2rem auto;font-family:system-ui">';
  echo '<h3>Greška pri brisanju pitanja</h3>';
  echo '<pre style="white-space:pre-wrap;background:#f8f9fa;border:1px solid #e9ecef;padding:1rem;border-radius:.5rem;">'
        . e($e->getMessage()) . '</pre>';
  echo '<a href="questions.php?test_id='.(int)$test_id.'" style="display:inline-block;margin-top:10px">⟵ Nazad</a>';
  echo '</div>';
}
