<?php
session_start();
require_once __DIR__ . '/admin_config.php';
if (!ez_is_admin()) { exit("⛔ Samo nastavnik."); }

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require __DIR__ . '/db_connect.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
  die("Konekcija na bazu nije uspešna.");
}
$conn->set_charset('utf8mb4');

function h($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ensure_radovi_schema(mysqli $conn): void {
  $conn->query("
    CREATE TABLE IF NOT EXISTS radovi_ucenika (
      id INT AUTO_INCREMENT PRIMARY KEY,
      naslov VARCHAR(255) NOT NULL,
      tekst TEXT NOT NULL,
      kratak_opis TEXT NULL,
      ucenik VARCHAR(255) NOT NULL,
      razred VARCHAR(50) NULL,
      project_link VARCHAR(255) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
  $conn->query("
    CREATE TABLE IF NOT EXISTS radovi_ucenika_slike (
      id INT AUTO_INCREMENT PRIMARY KEY,
      rad_id INT NOT NULL,
      image_link VARCHAR(255) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX (rad_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $columns = [];
  if ($result = $conn->query("SHOW COLUMNS FROM radovi_ucenika")) {
    while ($row = $result->fetch_assoc()) {
      $columns[$row['Field']] = $row;
    }
    $result->free();
  }
  if (!isset($columns['kratak_opis'])) {
    $conn->query("ALTER TABLE radovi_ucenika ADD COLUMN kratak_opis TEXT NULL AFTER tekst");
  }
  if (!isset($columns['razred'])) {
    $conn->query("ALTER TABLE radovi_ucenika ADD COLUMN razred VARCHAR(50) NULL AFTER ucenik");
  }
  if (!isset($columns['project_link'])) {
    $conn->query("ALTER TABLE radovi_ucenika ADD COLUMN project_link VARCHAR(255) NULL AFTER razred");
  }
}

function upload_work_images(): array {
  if (!isset($_FILES['slike']) || !is_array($_FILES['slike']['name'])) {
    return [];
  }

  $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
  $dir = __DIR__ . '/uploads/radovi-ucenika';
  if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
    throw new RuntimeException("Ne mogu da kreiram folder za upload.");
  }

  $uploaded = [];
  $count = count($_FILES['slike']['name']);

  for ($i = 0; $i < $count; $i++) {
    if ($_FILES['slike']['error'][$i] === UPLOAD_ERR_NO_FILE) {
      continue;
    }
    if ($_FILES['slike']['error'][$i] !== UPLOAD_ERR_OK) {
      throw new RuntimeException("Upload jedne od slika nije uspeo.");
    }

    $name = $_FILES['slike']['name'][$i];
    $tmpName = $_FILES['slike']['tmp_name'][$i];
    $size = (int)$_FILES['slike']['size'][$i];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowedExt, true)) {
      throw new RuntimeException("Dozvoljene slike su JPG, PNG, WEBP i GIF.");
    }
    if ($size > 8 * 1024 * 1024) {
      throw new RuntimeException("Svaka slika mora biti manja od 8MB.");
    }

    $filename = uniqid('rad_', true) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    $absolutePath = $dir . '/' . $filename;
    if (!move_uploaded_file($tmpName, $absolutePath)) {
      throw new RuntimeException("Upload slike nije uspeo.");
    }
    $uploaded[] = 'uploads/radovi-ucenika/' . $filename;
  }

  return $uploaded;
}

function remove_uploaded_file(?string $relativePath): void {
  if (!$relativePath) return;
  $fullPath = realpath(__DIR__ . '/' . $relativePath);
  $uploadsRoot = realpath(__DIR__ . '/uploads');
  if ($fullPath && $uploadsRoot && str_starts_with($fullPath, $uploadsRoot) && is_file($fullPath)) {
    @unlink($fullPath);
  }
}

function insert_images(mysqli $conn, int $radId, array $uploaded): void {
  if (!$uploaded) return;
  $stmt = $conn->prepare("INSERT INTO radovi_ucenika_slike (rad_id, image_link) VALUES (?, ?)");
  foreach ($uploaded as $imageLink) {
    $stmt->bind_param('is', $radId, $imageLink);
    $stmt->execute();
  }
  $stmt->close();
}

function normalize_project_link(string $value): string {
  $value = trim($value);
  if ($value === '') return '';

  if (preg_match('~<iframe\b[^>]*\bsrc=(["\'])(.*?)\1~i', $value, $match)) {
    $value = html_entity_decode(trim($match[2]), ENT_QUOTES, 'UTF-8');
  }

  if (preg_match('~https?://(?:embed\.)?figma\.com/[^\s\]")<]+~i', $value, $match)) {
    $value = html_entity_decode($match[0], ENT_QUOTES, 'UTF-8');
  }

  if ($value !== '' && !preg_match('~^https?://~i', $value)) {
    $value = 'https://' . $value;
  }

  return $value;
}

ensure_radovi_schema($conn);

$msg = null;
$edit = null;
$editImages = [];

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $akcija = $_POST['akcija'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $naslov = trim($_POST['naslov'] ?? '');
    $tekst = trim($_POST['tekst'] ?? '');
    $kratakOpis = trim($_POST['kratak_opis'] ?? '');
    $ucenik = trim($_POST['ucenik'] ?? '');
    $razred = trim($_POST['razred'] ?? '');
    $projectLink = normalize_project_link($_POST['project_link'] ?? '');

    if ($naslov === '' || $tekst === '' || $ucenik === '') {
      $msg = ['danger', 'Popuni naslov, tekst projekta i ime učenika.'];
    } elseif ($akcija === 'dodaj') {
      $uploaded = upload_work_images();
      if (!$uploaded && $projectLink === '') {
        $msg = ['danger', 'Dodaj bar jednu sliku projekta ili unesi link/iframe za prikaz prototipa.'];
      } else {
        $conn->begin_transaction();
        try {
          $stmt = $conn->prepare("INSERT INTO radovi_ucenika (naslov, tekst, kratak_opis, ucenik, razred, project_link) VALUES (?, ?, ?, ?, ?, ?)");
          $stmt->bind_param('ssssss', $naslov, $tekst, $kratakOpis, $ucenik, $razred, $projectLink);
          $stmt->execute();
          $radId = $stmt->insert_id;
          $stmt->close();

          insert_images($conn, (int)$radId, $uploaded);
          $conn->commit();
          header('Location: radovi_ucenika_admin.php?ok=1');
          exit;
        } catch (Throwable $e) {
          $conn->rollback();
          foreach ($uploaded as $imageLink) remove_uploaded_file($imageLink);
          throw $e;
        }
      }
    } elseif ($akcija === 'izmeni') {
      if ($id <= 0) {
        $msg = ['danger', 'Neispravan rad za izmenu.'];
      } else {
        $conn->begin_transaction();
        try {
          $stmt = $conn->prepare("UPDATE radovi_ucenika SET naslov=?, tekst=?, kratak_opis=?, ucenik=?, razred=?, project_link=? WHERE id=?");
          $stmt->bind_param('ssssssi', $naslov, $tekst, $kratakOpis, $ucenik, $razred, $projectLink, $id);
          $stmt->execute();
          $stmt->close();

          $deleteImageIds = array_map('intval', $_POST['obrisi_slike'] ?? []);
          if ($deleteImageIds) {
            $in = implode(',', array_fill(0, count($deleteImageIds), '?'));
            $types = str_repeat('i', count($deleteImageIds) + 1);
            $stmt = $conn->prepare("SELECT id, image_link FROM radovi_ucenika_slike WHERE rad_id=? AND id IN ($in)");
            $stmt->bind_param($types, $id, ...$deleteImageIds);
            $stmt->execute();
            $imagesToDelete = $stmt->get_result();
            $paths = [];
            while ($image = $imagesToDelete->fetch_assoc()) {
              $paths[] = $image['image_link'];
            }
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM radovi_ucenika_slike WHERE rad_id=? AND id IN ($in)");
            $stmt->bind_param($types, $id, ...$deleteImageIds);
            $stmt->execute();
            $stmt->close();
            foreach ($paths as $path) remove_uploaded_file($path);
          }

          insert_images($conn, $id, upload_work_images());
          $conn->commit();
          header('Location: radovi_ucenika_admin.php?updated=1');
          exit;
        } catch (Throwable $e) {
          $conn->rollback();
          throw $e;
        }
      }
    }
  }

  if (isset($_GET['obrisi'])) {
    $id = (int)$_GET['obrisi'];

    $stmt = $conn->prepare("SELECT image_link FROM radovi_ucenika_slike WHERE rad_id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $images = $stmt->get_result();
    while ($image = $images->fetch_assoc()) {
      remove_uploaded_file($image['image_link']);
    }
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM radovi_ucenika_slike WHERE rad_id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM radovi_ucenika WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    header('Location: radovi_ucenika_admin.php?deleted=1');
    exit;
  }

  if (isset($_GET['izmeni'])) {
    $id = (int)$_GET['izmeni'];
    $stmt = $conn->prepare("SELECT id, naslov, tekst, kratak_opis, ucenik, razred, project_link, created_at FROM radovi_ucenika WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $edit = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($edit) {
      $stmt = $conn->prepare("SELECT id, image_link FROM radovi_ucenika_slike WHERE rad_id=? ORDER BY id ASC");
      $stmt->bind_param('i', $id);
      $stmt->execute();
      $result = $stmt->get_result();
      while ($image = $result->fetch_assoc()) {
        $editImages[] = $image;
      }
      $stmt->close();
    }
  }
} catch (Throwable $e) {
  $msg = ['danger', $e->getMessage()];
}

$list = $conn->query("
  SELECT r.id, r.naslov, r.ucenik, r.razred, r.created_at, COUNT(s.id) AS broj_slika
  FROM radovi_ucenika r
  LEFT JOIN radovi_ucenika_slike s ON s.rad_id = r.id
  GROUP BY r.id, r.naslov, r.ucenik, r.razred, r.created_at
  ORDER BY r.created_at DESC, r.id DESC
");
?>
<!doctype html>
<html lang="sr">
<head>
<?php require_once __DIR__ . '/analytics.php'; ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Radovi učenika - admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">Radovi učenika</h3>
    <div>
      <a class="btn btn-outline-secondary" target="_blank" href="radovi-ucenika.php">Javna strana</a>
      <a class="btn btn-secondary" href="dashboard.php">Početna</a>
    </div>
  </div>

  <?php if (!empty($_GET['ok'])): ?>
    <div class="alert alert-success">Rad je dodat.</div>
  <?php elseif (!empty($_GET['updated'])): ?>
    <div class="alert alert-success">Rad je izmenjen.</div>
  <?php elseif (!empty($_GET['deleted'])): ?>
    <div class="alert alert-success">Rad je obrisan.</div>
  <?php elseif (!empty($msg)): ?>
    <div class="alert alert-<?= h($msg[0]) ?>"><?= h($msg[1]) ?></div>
  <?php endif; ?>

  <div class="card mb-4">
    <div class="card-body">
      <h5 class="card-title"><?= $edit ? 'Izmeni rad učenika' : 'Novi rad učenika' ?></h5>
      <form method="post" enctype="multipart/form-data" class="row g-3">
        <input type="hidden" name="akcija" value="<?= $edit ? 'izmeni' : 'dodaj' ?>">
        <?php if ($edit): ?>
          <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
        <?php endif; ?>

        <div class="col-md-6">
          <label class="form-label">Naslov projekta</label>
          <input type="text" name="naslov" class="form-control" value="<?= h($edit['naslov'] ?? '') ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Učenik</label>
          <input type="text" name="ucenik" class="form-control" value="<?= h($edit['ucenik'] ?? '') ?>" required>
        </div>
        <div class="col-md-2">
          <label class="form-label">Razred</label>
          <input type="text" name="razred" class="form-control" placeholder="npr. IIIit" value="<?= h($edit['razred'] ?? '') ?>">
        </div>

        <div class="col-12">
          <label class="form-label">Kratak opis</label>
          <textarea name="kratak_opis" class="form-control" rows="3" placeholder="Kratak opis koji se prikazuje na kartici rada."><?= h($edit['kratak_opis'] ?? '') ?></textarea>
        </div>

        <div class="col-12">
          <label class="form-label">Tekst članka/rada</label>
          <textarea name="tekst" class="form-control" rows="7" required><?= h($edit['tekst'] ?? '') ?></textarea>
        </div>

        <div class="col-12">
          <label class="form-label">Link do projekta, sajta ili Figma prototipa (opciono)</label>
          <input type="text" name="project_link" id="projectLinkInput" class="form-control" placeholder="https://andorgligor09-source.github.io/petshop/ ili Figma iframe embed kod" value="<?= h($edit['project_link'] ?? '') ?>">
          <div class="form-text">Možeš uneti link do gotovog sajta, Figma prototype/share link ili ceo Figma iframe embed kod. Link će biti prikazan na strani rada i otvoren u novom tabu.</div>
        </div>

        <div class="col-12">
          <label class="form-label">Slike projekta</label>
          <div id="imageInputs" class="d-grid gap-2">
            <input type="file" name="slike[]" accept="image/jpeg,image/png,image/webp,image/gif" class="form-control">
            <input type="file" name="slike[]" accept="image/jpeg,image/png,image/webp,image/gif" class="form-control">
            <input type="file" name="slike[]" accept="image/jpeg,image/png,image/webp,image/gif" class="form-control">
          </div>
          <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="addImageInput">Dodaj još jednu sliku</button>
          <div class="form-text">Slike nisu obavezne ako unosiš Figma embed/link. JPG, PNG, WEBP ili GIF, do 8MB po slici.</div>
        </div>

        <?php if ($editImages): ?>
          <div class="col-12">
            <label class="form-label">Postojeće slike</label>
            <div class="row g-2">
              <?php foreach ($editImages as $image): ?>
                <div class="col-6 col-md-3">
                  <div class="border rounded p-2 bg-light">
                    <img src="<?= h($image['image_link']) ?>" alt="" style="width:100%;aspect-ratio:16/10;object-fit:cover" class="rounded">
                    <div class="form-check mt-2">
                      <input class="form-check-input" type="checkbox" name="obrisi_slike[]" value="<?= (int)$image['id'] ?>" id="obrisiSliku<?= (int)$image['id'] ?>">
                      <label class="form-check-label" for="obrisiSliku<?= (int)$image['id'] ?>">Obriši</label>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <div class="col-12 d-flex gap-2">
          <button class="btn btn-success"><?= $edit ? 'Sačuvaj izmene' : 'Sačuvaj' ?></button>
          <?php if ($edit): ?>
            <a class="btn btn-outline-secondary" href="radovi_ucenika_admin.php">Otkaži izmenu</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
      <h5 class="card-title">Svi radovi</h5>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead><tr><th>ID</th><th>Naslov</th><th>Učenik</th><th>Razred</th><th>Slike</th><th>Datum</th><th>Akcije</th></tr></thead>
          <tbody>
          <?php while ($row = $list->fetch_assoc()): ?>
            <tr>
              <td><?= (int)$row['id'] ?></td>
              <td><?= h($row['naslov']) ?></td>
              <td><?= h($row['ucenik']) ?></td>
              <td><?= h($row['razred'] ?? '') ?></td>
              <td><?= (int)$row['broj_slika'] ?></td>
              <td><?= h(date('d.m.Y', strtotime($row['created_at']))) ?></td>
              <td class="text-nowrap">
                <a class="btn btn-sm btn-primary" target="_blank" href="radovi-ucenika.php?id=<?= (int)$row['id'] ?>">Pregled</a>
                <a class="btn btn-sm btn-outline-secondary" href="radovi_ucenika_admin.php?izmeni=<?= (int)$row['id'] ?>">Izmeni</a>
                <a class="btn btn-sm btn-outline-danger" href="radovi_ucenika_admin.php?obrisi=<?= (int)$row['id'] ?>" onclick="return confirm('Obrisati ovaj rad?')">Obriši</a>
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
document.getElementById('addImageInput')?.addEventListener('click', function () {
  const wrapper = document.getElementById('imageInputs');
  if (!wrapper) return;

  const input = document.createElement('input');
  input.type = 'file';
  input.name = 'slike[]';
  input.accept = 'image/jpeg,image/png,image/webp,image/gif';
  input.className = 'form-control';
  wrapper.appendChild(input);
});

document.querySelector('form[method="post"]')?.addEventListener('submit', function () {
  const input = document.getElementById('projectLinkInput');
  if (!input) return;

  const value = input.value.trim();
  const figmaUrl = value.match(/https?:\/\/(?:embed\.)?figma\.com\/[^\s\]")<]+/i);
  if (figmaUrl && figmaUrl[0]) {
    input.value = figmaUrl[0];
    return;
  }

  const match = value.match(/<iframe\b[^>]*\bsrc=(["'])(.*?)\1/i);
  if (match && match[2]) {
    const textarea = document.createElement('textarea');
    textarea.innerHTML = match[2].trim();
    input.value = textarea.value;
  }
});
</script>
</body>
</html>
