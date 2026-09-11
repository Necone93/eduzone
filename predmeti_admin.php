<?php
session_start();
require_once __DIR__ . '/admin_config.php';
if (!ez_is_admin()) { exit("⛔ Samo nastavnik."); }

// hvataj mysqli greške umesto belog ekrana
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require __DIR__ . '/db_connect.php'; // koristi iste kredencijale kao i ostale strane
$conn->set_charset('utf8mb4');

/* Napravi tabelu ako ne postoji */
$conn->query("
  CREATE TABLE IF NOT EXISTS predmeti (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kod VARCHAR(50) NOT NULL UNIQUE,
    naziv VARCHAR(255) NOT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$msg = null;

/* DODAJ / IZMENI */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $id    = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
  $kod   = trim($_POST['kod'] ?? '');
  $naziv = trim($_POST['naziv'] ?? '');

  if ($kod === '' || $naziv === '') {
    $msg = ['danger', 'Popuni Kod i Naziv.'];
  } else {
    if ($id) {
      $st = $conn->prepare("UPDATE predmeti SET kod=?, naziv=? WHERE id=?");
      $st->bind_param("ssi", $kod, $naziv, $id);
      $st->execute(); $st->close();
      $msg = ['success', '✅ Sačuvano.'];
    } else {
      $st = $conn->prepare("INSERT INTO predmeti (kod, naziv) VALUES (?, ?)");
      $st->bind_param("ss", $kod, $naziv);
      $st->execute(); $st->close();
      $msg = ['success', '✅ Dodat predmet.'];
    }
  }
}

/* OBRIŠI (ako nema lekcija koje ga koriste) */
if (isset($_GET['obrisi'])) {
  $id = (int)$_GET['obrisi'];

  $q = $conn->prepare("SELECT kod FROM predmeti WHERE id=?");
  $q->bind_param("i", $id); $q->execute(); $q->bind_result($kodSel);
  if ($q->fetch()) {
    $q->close();

    $chk = $conn->prepare("SELECT 1 FROM lekcije WHERE predmet=? LIMIT 1");
    $chk->bind_param("s", $kodSel); $chk->execute();
    $has = $chk->get_result()->num_rows > 0; $chk->close();

    if ($has) {
      $msg = ['danger', 'Ne može brisanje – postoje lekcije za ovaj predmet.'];
    } else {
      $del = $conn->prepare("DELETE FROM predmeti WHERE id=?");
      $del->bind_param("i", $id); $del->execute(); $del->close();
      $msg = ['success', 'Obrisano.'];
    }
  } else {
    $q->close();
  }
}

/* LISTA + eventualno podatak za izmenu */
$list = $conn->query("SELECT id, kod, naziv FROM predmeti ORDER BY naziv");
$edit = null;
if (isset($_GET['izmeni'])) {
  $eid = (int)$_GET['izmeni'];
  $r = $conn->prepare("SELECT id, kod, naziv FROM predmeti WHERE id=?");
  $r->bind_param("i", $eid); $r->execute();
  $edit = $r->get_result()->fetch_assoc(); $r->close();
}
?>
<!doctype html>
<html lang="sr">
<head>
<?php require_once __DIR__ . '/analytics.php'; ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin predmeta</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">📘 Admin predmeta</h3>
    <a class="btn btn-secondary" href="dashboard.php">⬅️ Početna</a>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-<?= htmlspecialchars($msg[0]) ?>"><?= htmlspecialchars($msg[1]) ?></div>
  <?php endif; ?>

  <div class="card mb-4">
    <div class="card-body">
      <h5 class="card-title"><?= $edit ? '✏️ Izmena predmeta' : '➕ Novi predmet' ?></h5>
      <form method="post" class="row g-3">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
        <div class="col-md-3">
          <label class="form-label">Kod</label>
          <input type="text" name="kod" class="form-control" value="<?= htmlspecialchars($edit['kod'] ?? '') ?>" placeholder="npr. wp1" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Naziv</label>
          <input type="text" name="naziv" class="form-control" value="<?= htmlspecialchars($edit['naziv'] ?? '') ?>" placeholder="npr. Web programiranje 1" required>
        </div>
        <div class="col-12">
          <button class="btn btn-success"><?= $edit ? 'Sačuvaj' : 'Dodaj' ?></button>
          <?php if ($edit): ?><a class="btn btn-outline-secondary ms-2" href="predmeti_admin.php">Otkaži</a><?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
      <h5 class="card-title">Svi predmeti</h5>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead><tr><th>ID</th><th>Kod</th><th>Naziv</th><th>Akcije</th></tr></thead>
          <tbody>
            <?php while ($r = $list->fetch_assoc()): ?>
              <tr>
                <td><?= (int)$r['id'] ?></td>
                <td><?= htmlspecialchars($r['kod']) ?></td>
                <td><?= htmlspecialchars($r['naziv']) ?></td>
                <td class="text-nowrap">
                  <a class="btn btn-sm btn-primary" href="predmeti_admin.php?izmeni=<?= (int)$r['id'] ?>">Izmeni</a>
                  <a class="btn btn-sm btn-outline-danger" href="predmeti_admin.php?obrisi=<?= (int)$r['id'] ?>" onclick="return confirm('Obrisati predmet? Ako ima lekcija za njega, moraš ih prvo obrisati/promijeniti.')">Obriši</a>
                </td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>
</body>
</html>
