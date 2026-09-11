<?php
// start_test.php — pokretanje testa po KONKRETNOJ dodeli (ak = assigned_at)
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (empty($_SESSION['email'])) { header('Location: index.php'); exit; }

$email        = $_SESSION['email'];
$test_id      = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_GET['test_id']) ? (int)$_GET['test_id'] : 0);
$assign_key_q = isset($_GET['ak']) ? trim((string)$_GET['ak']) : null; // očekujemo vrednost iz dt.assigned_at

if ($test_id <= 0) { exit('Nedostaje ID testa.'); }

require __DIR__ . '/db_connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function table_exists(mysqli $c, string $t): bool {
  $r = $c->query("SHOW TABLES LIKE '".$c->real_escape_string($t)."'");
  $ok = $r && $r->num_rows>0; if ($r) $r->free(); return $ok;
}
function col_exists(mysqli $c, string $t, string $col): bool {
  $r = $c->query("SHOW COLUMNS FROM `$t` LIKE '".$c->real_escape_string($col)."'");
  $ok = $r && $r->num_rows>0; if ($r) $r->free(); return $ok;
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
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
    if ($columns === ['email', 'test_id']) {
      $conn->query("ALTER TABLE `$table` DROP INDEX `$key`");
    }
  }
}

try {
  // 0) Provera da test postoji
  $chk = $conn->prepare("SELECT 1 FROM testovi WHERE id=? LIMIT 1");
  $chk->bind_param("i", $test_id);
  $chk->execute(); $chk->store_result();
  if ($chk->num_rows === 0) { $chk->close(); exit('Traženi test ne postoji.'); }
  $chk->close();

  // 1) Obavezna tabela za evidenciju startova; KEY uključuje assign_key
  $conn->query("
    CREATE TABLE IF NOT EXISTS zapoceti_testovi (
      id INT AUTO_INCREMENT PRIMARY KEY,
      test_id INT NOT NULL,
      email VARCHAR(190) NOT NULL,
      assign_key VARCHAR(32) NULL,                -- čuvamo dt.assigned_at kao “ključ dodele”
      started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_start (test_id, email(100), assign_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
  if (!col_exists($conn, 'zapoceti_testovi', 'assign_key')) {
    $conn->query("ALTER TABLE zapoceti_testovi ADD COLUMN assign_key VARCHAR(32) NULL AFTER email");
  }
  drop_unique_email_test($conn, 'zapoceti_testovi');

  // 2) Nađi konkretno dodeljivanje (iz dodeljeni_testovi)
  //    - Ako je prosleđen ?ak= koristimo baš taj red (assigned_at)
  //    - Inače, najskorije dodeljivanje za ovog učenika/test (ORDER BY id DESC)
  $assignKey = null;          // vrednost koju upisujemo u zapoceti_testovi.assign_key
  $attemptsAllowed = 1;       // podrazumevani limit pokušaja po dodeli
  $deadline = null;

  if (table_exists($conn, 'dodeljeni_testovi')) {
    // Uveri se da tabela ima očekivane kolone
    $hasAssignedAt = col_exists($conn,'dodeljeni_testovi','assigned_at');

    if ($assign_key_q && $hasAssignedAt) {
      $d = $conn->prepare("
        SELECT attempts_allowed, deadline, assigned_at
        FROM dodeljeni_testovi
        WHERE email=? AND test_id=? AND assigned_at=?
        LIMIT 1
      ");
      $d->bind_param("sis", $email, $test_id, $assign_key_q);
    } else {
      // bez ?ak= — uzmi najskoriji red (po auto-inc id)
      if ($hasAssignedAt) {
        $d = $conn->prepare("
          SELECT attempts_allowed, deadline, assigned_at
          FROM dodeljeni_testovi
          WHERE email=? AND test_id=?
          ORDER BY id DESC
          LIMIT 1
        ");
        $d->bind_param("si", $email, $test_id);
      } else {
        // fallback ako nema assigned_at kolone: ponašaj se kao “jedna globalna dodela”
        $d = $conn->prepare("
          SELECT attempts_allowed, deadline, NULL AS assigned_at
          FROM dodeljeni_testovi
          WHERE email=? AND test_id=?
          ORDER BY id DESC
          LIMIT 1
        ");
        $d->bind_param("si", $email, $test_id);
      }
    }

    $d->execute();
    $d->bind_result($attAllowed, $dl, $ak);
    if ($d->fetch()) {
      $attemptsAllowed = is_null($attAllowed) ? 1 : (int)$attAllowed;
      $deadline = $dl ? (string)$dl : null;
      $assignKey = $ak ? (string)$ak : null; // ako nema kolone, biće null
    }
    $d->close();

    // Ako nema uopšte dodeljivanja za ovog učenika/test → ne dozvoli start
    if ($assignKey === null && !$hasAssignedAt) {
      // imamo red bez assigned_at, to je “globalna dodela” — dozvoli start, ali i dalje ograniči pokušaje
      // (assignKey = NULL je validan deo UNIQUE ključa)
    } elseif ($assignKey === null && $hasAssignedAt) {
      exit('Ovaj test trenutno nije dodeljen Vašem nalogu.');
    }

    // Deadline check (ako postoji)
    if (!empty($deadline) && strtotime($deadline) < time()) {
      exit('⏰ Rok za ovu dodelu je istekao.');
    }

    // Koliko je puta već startovan baš ovaj ključ dodele?
    $cnt = $conn->prepare("
      SELECT COUNT(*) FROM zapoceti_testovi
      WHERE test_id=? AND email=? AND ".($assignKey===null ? "assign_key IS NULL" : "assign_key=?")
    );
    if ($assignKey===null) {
      $cnt->bind_param("is", $test_id, $email);
    } else {
      $cnt->bind_param("iss", $test_id, $email, $assignKey);
    }
    $cnt->execute(); $cnt->bind_result($used); $cnt->fetch(); $cnt->close();

    if ($used >= $attemptsAllowed) {
      exit('✅ Već ste završili ovaj test za ovu dodelu.');
    }
  } else {
    // Nema dodeljeni_testovi — dozvoli jedno pokretanje “globalno”
    $attemptsAllowed = 1;
    $assignKey = null;
    $cnt = $conn->prepare("
      SELECT COUNT(*) FROM zapoceti_testovi
      WHERE test_id=? AND email=? AND assign_key IS NULL
    ");
    $cnt->bind_param("is", $test_id, $email);
    $cnt->execute(); $cnt->bind_result($used); $cnt->fetch(); $cnt->close();
    if ($used >= $attemptsAllowed) {
      exit('✅ Već ste završili ovaj test.');
    }
  }

  // 3) Evidentiraj start (IGNORE: bez dupliranja na refresh)
  if ($assignKey === null) {
    $ins = $conn->prepare("
      INSERT IGNORE INTO zapoceti_testovi (test_id, email, assign_key)
      VALUES (?, ?, NULL)
    ");
    $ins->bind_param("is", $test_id, $email);
  } else {
    $ins = $conn->prepare("
      INSERT IGNORE INTO zapoceti_testovi (test_id, email, assign_key)
      VALUES (?, ?, ?)
    ");
    $ins->bind_param("iss", $test_id, $email, $assignKey);
  }
  $ins->execute(); 
  $ins->close();

  // 4) Preusmeri na sam test
  header('Location: test.php?id=' . $test_id . ($assignKey !== null ? '&ak=' . urlencode($assignKey) : ''));
  exit;

} catch (Throwable $e) {
  echo '<div style="max-width:800px;margin:2rem auto;font-family:system-ui">';
  echo '<h3>Greška pri pokretanju testa</h3>';
  echo '<p style="margin:.25rem 0 .75rem;color:#6c757d">Ako si upravo menjao kolone u bazi, osveži stranicu i pokušaj ponovo.</p>';
  echo '<pre style="white-space:pre-wrap;background:#f8f9fa;border:1px solid #e9ecef;padding:1rem;border-radius:.5rem;">'
       . h($e->getMessage()) . '</pre>';
  echo '<a href="dashboard.php" style="display:inline-block;margin-top:10px">⟵ Nazad</a>';
  echo '</div>';
}
