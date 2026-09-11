<?php
session_start();
require_once __DIR__ . '/admin_config.php';
if (!ez_is_admin()) { exit("⛔ Samo nastavnik."); }
if (empty($_SESSION['lesson_csrf'])) $_SESSION['lesson_csrf'] = bin2hex(random_bytes(32));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['lesson_csrf'], $_POST['csrf']))) {
  http_response_code(403); exit('Sesija forme je istekla. Osvezi stranicu i pokusaj ponovo.');
}


/* Vidljive greške umesto „500“ */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* Konekcija db_connect.php (koristi cPanel kredencijale) */
require __DIR__ . '/db_connect.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
  die("❗ Konekcija na bazu nije uspešna. Proveri db_connect.php");
}
$conn->set_charset('utf8mb4');

try {
  /* helper – BEZ prepared za SHOW TABLES */
  $table_exists = function(string $name) use($conn): bool {
    $like = $conn->real_escape_string($name);
    $res  = $conn->query("SHOW TABLES LIKE '$like'");
    if (!$res) return false;
    $ok = $res->num_rows > 0;
    $res->free();
    return $ok;
  };

  /* napravi tabele ako fale */
  if (!$table_exists('predmeti')) {
    $conn->query("
      CREATE TABLE IF NOT EXISTS predmeti (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kod VARCHAR(50) NOT NULL UNIQUE,
        naziv VARCHAR(255) NOT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
  }
  if (!$table_exists('lekcije')) {
    $conn->query("
      CREATE TABLE IF NOT EXISTS lekcije (
        id INT AUTO_INCREMENT PRIMARY KEY,
        naziv VARCHAR(255) NOT NULL,
        opis TEXT NULL,
        predmet VARCHAR(50) NOT NULL,
        razred VARCHAR(50) NULL,
        pdf_link VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
  }
  /* učitaj predmete (ili default ako su prazni) */
  $predmeti = [];
  $res = $conn->query("SELECT kod, naziv FROM predmeti ORDER BY naziv");
  while ($row = $res->fetch_assoc()) { $predmeti[$row['kod']] = $row['naziv']; }
  if (!$predmeti) {
    $predmeti = ["wp1"=>"Web programiranje 1","wp2"=>"Web programiranje 2","wd"=>"Web dizajn","inf"=>"Informatika"];
  }

  $normalizeRazred = function(string $razred): string {
    $clean = preg_replace('/[^a-z0-9]/i', '', trim($razred));
    $map = [
      'iit' => 'Iit',
      '1it' => 'Iit',
      'iiit' => 'IIit',
      '2it' => 'IIit',
      'iiiit' => 'IIIit',
      '3it' => 'IIIit',
      'ivit' => 'IVit',
      '4it' => 'IVit',
    ];
    return $map[strtolower((string)$clean)] ?? trim($razred);
  };

  $msg = null;

  /* dodavanje lekcije */
  if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['akcija'] ?? '')==='dodaj') {
    $naziv   = trim($_POST['naziv'] ?? "");
    $opis    = trim($_POST['opis'] ?? "");
    $predmet = $_POST['predmet'] ?? "";
    $razred  = $normalizeRazred((string)($_POST['razred'] ?? ""));

    if ($naziv==="" || !isset($predmeti[$predmet])) {
      $msg = ["danger","Popuni Naziv i Predmet."];
    } elseif (!isset($_FILES['pdf']) || $_FILES['pdf']['error']!==UPLOAD_ERR_OK) {
      $msg = ["danger","PDF je obavezan."];
    } else {
      $f = $_FILES['pdf'];
      $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
      if ($ext!=='pdf' || (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) !== 'application/pdf') {
        $msg = ["danger","Dozvoljen je samo PDF."];
      } elseif ($f['size'] > 25*1024*1024) {
        $msg = ["danger","PDF je veći od 25MB."];
      } else {
        $dir = __DIR__ . "/uploads/lekcije";
        if (!is_dir($dir)) { if (!mkdir($dir, 0775, true)) { throw new RuntimeException("Ne mogu da kreiram $dir"); } }
        $fname = uniqid("lekcija_", true) . ".pdf";
        $abs = $dir . "/" . $fname;
        if (!move_uploaded_file($f['tmp_name'], $abs)) { throw new RuntimeException("Upload nije uspeo (move_uploaded_file)."); }
        $rel = "uploads/lekcije/" . $fname;

        $st = $conn->prepare("INSERT INTO lekcije (naziv, opis, predmet, razred, pdf_link) VALUES (?,?,?,?,?)");
        $st->bind_param("sssss", $naziv, $opis, $predmet, $razred, $rel);
        $st->execute(); $st->close();
        $msg = ["success", "Lekcija dodata."];
      }
    }
  }

  /* brisanje */
  if (isset($_GET['obrisi'])) {
    $id = (int)$_GET['obrisi'];
    $q = $conn->prepare("SELECT pdf_link FROM lekcije WHERE id=?");
    $q->bind_param("i",$id); $q->execute(); $q->bind_result($pdf);
    if ($q->fetch()) { @unlink(__DIR__ . "/" . $pdf); }
    $q->close();
    $d = $conn->prepare("DELETE FROM lekcije WHERE id=?");
    $d->bind_param("i",$id); $d->execute(); $d->close();
    header("Location: lekcije_admin.php?ok=1"); exit;
  }

  /* lista + filter */
  $filterPredmet = $_GET['predmet'] ?? "";
  if ($filterPredmet && !isset($predmeti[$filterPredmet])) $filterPredmet = "";

  $sqlList = "SELECT id,naziv,predmet,razred,pdf_link FROM lekcije "
           . ($filterPredmet ? "WHERE predmet=? " : "")
           . "ORDER BY id DESC";
  if ($filterPredmet) {
    $listSt = $conn->prepare($sqlList);
    $listSt->bind_param("s", $filterPredmet);
    $listSt->execute();
    $list = $listSt->get_result();
  } else {
    $list = $conn->query($sqlList);
  }

} catch (Throwable $e) {
  http_response_code(500);
  echo "<pre style='padding:1rem; background:#222; color:#eee; white-space:pre-wrap'>".
       "Greška: ".$e->getMessage()."\n\n".
       "Sled:\n".$e->getTraceAsString()."</pre>";
  exit;
}
?>
<!doctype html>
<html lang="sr">
<head>
<?php require_once __DIR__ . '/analytics.php'; ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin lekcija</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">⚙️ Admin lekcija</h3>
    <div>
      <a class="btn btn-outline-secondary" href="provera-hostinga.php">Provera hostinga</a>
      <a class="btn btn-outline-secondary" href="predmeti_admin.php">📘 Predmeti</a>
      <a class="btn btn-secondary" href="dashboard.php">⬅️ Početna</a>
    </div>
  </div>

  <?php if (!empty($_GET['ok'])): ?>
    <div class="alert alert-success">✔️ Operacija uspešna.</div>
  <?php elseif (!empty($msg)): ?>
    <div class="alert alert-<?= htmlspecialchars($msg[0]) ?>"><?= htmlspecialchars($msg[1]) ?></div>
  <?php endif; ?>

  <div class="card mb-4">
    <div class="card-body">
      <h5 class="card-title">➕ Nova lekcija</h5>
      <form method="post" enctype="multipart/form-data" class="row g-3">
        <input type="hidden" name="akcija" value="dodaj">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['lesson_csrf']) ?>">
        <div class="col-md-6">
          <label class="form-label">Naslov</label>
          <input type="text" name="naziv" class="form-control" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Predmet</label>
          <select name="predmet" class="form-select" required>
            <option value="">— izaberi —</option>
            <?php foreach ($predmeti as $kod=>$naziv): ?>
              <option value="<?= htmlspecialchars($kod) ?>"><?= htmlspecialchars($naziv) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Razred (opciono)</label>
          <input type="text" name="razred" class="form-control" placeholder="npr. IIIit">
        </div>
        <div class="col-12">
          <label class="form-label">Opis (opciono)</label>
          <textarea name="opis" class="form-control" rows="2"></textarea>
        </div>
        <div class="col-md-6">
          <label class="form-label">PDF</label>
          <input type="file" name="pdf" accept="application/pdf" class="form-control" required>
          <div class="form-text">PDF se učenicima prikazuje unutar stranice lekcije.</div>
        </div>
        <div class="col-12">
          <button class="btn btn-success">Sačuvaj</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="card-title mb-0">📂 Sve lekcije</h5>
        <form class="d-flex" method="get">
          <select name="predmet" class="form-select me-2" onchange="this.form.submit()">
            <option value="">Svi predmeti</option>
            <?php foreach ($predmeti as $kod=>$naziv): ?>
              <option value="<?= htmlspecialchars($kod) ?>" <?= ($filterPredmet===$kod)?'selected':'' ?>>
                <?= htmlspecialchars($naziv) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <noscript><button class="btn btn-outline-secondary">Filtriraj</button></noscript>
        </form>
      </div>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead><tr><th>ID</th><th>Naslov</th><th>Predmet</th><th>Razred</th><th>Akcije</th></tr></thead>
          <tbody>
          <?php while ($r = $list->fetch_assoc()): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= htmlspecialchars($r['naziv']) ?></td>
              <td><?= htmlspecialchars($predmeti[$r['predmet']] ?? $r['predmet']) ?></td>
              <td><?= htmlspecialchars($r['razred']) ?></td>
              <td class="text-nowrap">
                <a class="btn btn-sm btn-primary" target="_blank" href="lekcija.php?id=<?=
                  (int)$r['id'] ?>">Pregled</a>
                <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($r['pdf_link']) ?>" download>Preuzmi</a>
                <a class="btn btn-sm btn-outline-danger" href="lekcije_admin.php?obrisi=<?= (int)$r['id'] ?>" onclick="return confirm('Obrisati lekciju?')">Obriši</a>
              </td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>
<script>
document.querySelectorAll('form[method="post"]').forEach(form => {
  form.addEventListener('submit', () => {
    const button = form.querySelector('button');
    if (button) { button.disabled = true; button.textContent = 'Pripremam lekciju…'; }
    form.setAttribute('aria-busy', 'true');
  });
});
</script>
</body>
</html>
