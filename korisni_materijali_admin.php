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

function ensure_it_kutak_schema(mysqli $conn): void {
  $conn->query("
    CREATE TABLE IF NOT EXISTS korisni_materijali (
      id INT AUTO_INCREMENT PRIMARY KEY,
      naslov VARCHAR(255) NOT NULL,
      tekst TEXT NULL,
      pdf_link VARCHAR(255) NULL,
      image_link VARCHAR(255) NULL,
      gallery_images TEXT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $columns = [];
  if ($result = $conn->query("SHOW COLUMNS FROM korisni_materijali")) {
    while ($row = $result->fetch_assoc()) {
      $columns[$row['Field']] = $row;
    }
    $result->free();
  }

  if (!isset($columns['image_link'])) {
    $conn->query("ALTER TABLE korisni_materijali ADD COLUMN image_link VARCHAR(255) NULL AFTER pdf_link");
  }
  if (!isset($columns['gallery_images'])) {
    $conn->query("ALTER TABLE korisni_materijali ADD COLUMN gallery_images TEXT NULL AFTER image_link");
  }
  if (isset($columns['pdf_link']) && strtoupper((string)$columns['pdf_link']['Null']) === 'NO') {
    $conn->query("ALTER TABLE korisni_materijali MODIFY pdf_link VARCHAR(255) NULL");
  }
}

function upload_file(string $field, array $allowedExt, int $maxBytes, string $prefix): ?string {
  if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
    return null;
  }
  if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
    throw new RuntimeException("Upload fajla nije uspeo.");
  }

  $file = $_FILES[$field];
  if ($file['size'] > $maxBytes) {
    throw new RuntimeException("Fajl je veći od dozvoljene veličine.");
  }

  $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  if (!in_array($ext, $allowedExt, true)) {
    throw new RuntimeException("Format fajla nije dozvoljen.");
  }

  $dir = __DIR__ . '/uploads/it-kutak';
  if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
    throw new RuntimeException("Ne mogu da kreiram folder za upload.");
  }

  $filename = uniqid($prefix . '_', true) . '.' . $ext;
  $absolutePath = $dir . '/' . $filename;
  if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
    throw new RuntimeException("Upload fajla nije uspeo.");
  }

  return 'uploads/it-kutak/' . $filename;
}

function upload_multiple_files(string $field, array $allowedExt, int $maxBytes, string $prefix): array {
  if (!isset($_FILES[$field]) || !is_array($_FILES[$field]['name'])) {
    return [];
  }

  $uploaded = [];
  $count = count($_FILES[$field]['name']);

  for ($i = 0; $i < $count; $i++) {
    if ($_FILES[$field]['error'][$i] === UPLOAD_ERR_NO_FILE) {
      continue;
    }
    if ($_FILES[$field]['error'][$i] !== UPLOAD_ERR_OK) {
      throw new RuntimeException("Upload dodatne slike nije uspeo.");
    }

    $single = [
      'name' => $_FILES[$field]['name'][$i],
      'type' => $_FILES[$field]['type'][$i],
      'tmp_name' => $_FILES[$field]['tmp_name'][$i],
      'error' => $_FILES[$field]['error'][$i],
      'size' => $_FILES[$field]['size'][$i],
    ];

    $_FILES['_single_gallery_upload'] = $single;
    $uploaded[] = upload_file('_single_gallery_upload', $allowedExt, $maxBytes, $prefix);
    unset($_FILES['_single_gallery_upload']);
  }

  return array_values(array_filter($uploaded));
}

function gallery_images_from_value($value): array {
  $images = json_decode((string)$value, true);
  return is_array($images) ? array_values(array_filter($images, 'is_string')) : [];
}

function remove_uploaded_file(?string $relativePath): void {
  if (!$relativePath) return;
  $fullPath = realpath(__DIR__ . '/' . $relativePath);
  $uploadsRoot = realpath(__DIR__ . '/uploads');
  if ($fullPath && $uploadsRoot && str_starts_with($fullPath, $uploadsRoot) && is_file($fullPath)) {
    @unlink($fullPath);
  }
}

ensure_it_kutak_schema($conn);

$msg = null;
$edit = null;

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $akcija = $_POST['akcija'] ?? '';
    $naslov = trim($_POST['naslov'] ?? '');
    $tekst = trim($_POST['tekst'] ?? '');

    if ($naslov === '') {
      $msg = ['danger', 'Naslov je obavezan.'];
    } elseif ($tekst === '') {
      $msg = ['danger', 'Tekst članka je obavezan.'];
    } elseif ($akcija === 'dodaj') {
      $imageLink = upload_file('slika', ['jpg', 'jpeg', 'png', 'webp', 'gif'], 5 * 1024 * 1024, 'slika');
      $pdfLink = upload_file('pdf', ['pdf'], 25 * 1024 * 1024, 'prilog');
      $galleryImages = upload_multiple_files('dodatne_slike', ['jpg', 'jpeg', 'png', 'webp', 'gif'], 5 * 1024 * 1024, 'dodatna');
      $galleryJson = $galleryImages ? json_encode($galleryImages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

      $stmt = $conn->prepare("INSERT INTO korisni_materijali (naslov, tekst, pdf_link, image_link, gallery_images) VALUES (?, ?, ?, ?, ?)");
      $stmt->bind_param('sssss', $naslov, $tekst, $pdfLink, $imageLink, $galleryJson);
      $stmt->execute();
      $stmt->close();

      header('Location: korisni_materijali_admin.php?ok=1');
      exit;
    } elseif ($akcija === 'izmeni') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) {
        $msg = ['danger', 'Neispravan članak za izmenu.'];
      } else {
        $stmt = $conn->prepare("SELECT pdf_link, image_link, gallery_images FROM korisni_materijali WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $current = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$current) {
          $msg = ['danger', 'Članak nije pronađen.'];
        } else {
          $imageLink = upload_file('slika', ['jpg', 'jpeg', 'png', 'webp', 'gif'], 5 * 1024 * 1024, 'slika') ?? $current['image_link'];
          $pdfLink = upload_file('pdf', ['pdf'], 25 * 1024 * 1024, 'prilog') ?? $current['pdf_link'];
          $galleryImages = gallery_images_from_value($current['gallery_images'] ?? '');
          $deleteGalleryIndexes = array_map('intval', $_POST['obrisi_dodatne_slike'] ?? []);
          foreach ($deleteGalleryIndexes as $deleteIndex) {
            if (isset($galleryImages[$deleteIndex])) {
              remove_uploaded_file($galleryImages[$deleteIndex]);
              unset($galleryImages[$deleteIndex]);
            }
          }
          $galleryImages = array_values($galleryImages);
          $galleryImages = array_merge(
            $galleryImages,
            upload_multiple_files('dodatne_slike', ['jpg', 'jpeg', 'png', 'webp', 'gif'], 5 * 1024 * 1024, 'dodatna')
          );
          $galleryJson = $galleryImages ? json_encode($galleryImages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

          if (!empty($_POST['obrisi_sliku'])) {
            remove_uploaded_file($current['image_link']);
            $imageLink = null;
          } elseif ($imageLink !== $current['image_link']) {
            remove_uploaded_file($current['image_link']);
          }

          if (!empty($_POST['obrisi_pdf'])) {
            remove_uploaded_file($current['pdf_link']);
            $pdfLink = null;
          } elseif ($pdfLink !== $current['pdf_link']) {
            remove_uploaded_file($current['pdf_link']);
          }

          $stmt = $conn->prepare("UPDATE korisni_materijali SET naslov=?, tekst=?, pdf_link=?, image_link=?, gallery_images=? WHERE id=?");
          $stmt->bind_param('sssssi', $naslov, $tekst, $pdfLink, $imageLink, $galleryJson, $id);
          $stmt->execute();
          $stmt->close();

          header('Location: korisni_materijali_admin.php?updated=1');
          exit;
        }
      }
    }
  }

  if (isset($_GET['obrisi'])) {
    $id = (int)$_GET['obrisi'];

    $stmt = $conn->prepare("SELECT pdf_link, image_link, gallery_images FROM korisni_materijali WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
      remove_uploaded_file($row['pdf_link']);
      remove_uploaded_file($row['image_link']);
      foreach (gallery_images_from_value($row['gallery_images'] ?? '') as $galleryImage) {
        remove_uploaded_file($galleryImage);
      }
    }

    $stmt = $conn->prepare("DELETE FROM korisni_materijali WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    header('Location: korisni_materijali_admin.php?deleted=1');
    exit;
  }

  if (isset($_GET['izmeni'])) {
    $id = (int)$_GET['izmeni'];
    $stmt = $conn->prepare("SELECT id, naslov, tekst, pdf_link, image_link, gallery_images, created_at FROM korisni_materijali WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $edit = $stmt->get_result()->fetch_assoc();
    $stmt->close();
  }
} catch (Throwable $e) {
  $msg = ['danger', $e->getMessage()];
}

$list = $conn->query("SELECT id, naslov, tekst, pdf_link, image_link, gallery_images, created_at FROM korisni_materijali ORDER BY created_at DESC, id DESC");
$editGalleryImages = $edit ? gallery_images_from_value($edit['gallery_images'] ?? '') : [];
?>
<!doctype html>
<html lang="sr">
<head>
<?php require_once __DIR__ . '/analytics.php'; ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>IT kutak - admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">IT kutak</h3>
    <div>
      <a class="btn btn-outline-secondary" target="_blank" href="korisni-materijali.php">Javna strana</a>
      <a class="btn btn-secondary" href="dashboard.php">Početna</a>
    </div>
  </div>

  <?php if (!empty($_GET['ok'])): ?>
    <div class="alert alert-success">Članak je dodat.</div>
  <?php elseif (!empty($_GET['updated'])): ?>
    <div class="alert alert-success">Članak je izmenjen.</div>
  <?php elseif (!empty($_GET['deleted'])): ?>
    <div class="alert alert-success">Članak je obrisan.</div>
  <?php elseif (!empty($msg)): ?>
    <div class="alert alert-<?= h($msg[0]) ?>"><?= h($msg[1]) ?></div>
  <?php endif; ?>

  <div class="card mb-4">
    <div class="card-body">
      <h5 class="card-title"><?= $edit ? 'Izmeni članak' : 'Novi članak' ?></h5>
      <form method="post" enctype="multipart/form-data" class="row g-3">
        <input type="hidden" name="akcija" value="<?= $edit ? 'izmeni' : 'dodaj' ?>">
        <?php if ($edit): ?>
          <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
        <?php endif; ?>

        <div class="col-12">
          <label class="form-label">Naslov</label>
          <input type="text" name="naslov" class="form-control" value="<?= h($edit['naslov'] ?? '') ?>" required>
        </div>

        <div class="col-md-6">
          <label class="form-label">Slika članka</label>
          <input type="file" name="slika" accept="image/jpeg,image/png,image/webp,image/gif,.jpg,.jpeg,.png,.webp,.gif" class="form-control">
          <div class="form-text">JPG, PNG, WEBP ili GIF, do 5MB.</div>
          <?php if (!empty($edit['image_link'])): ?>
            <div class="mt-2">
              <img src="<?= h($edit['image_link']) ?>" alt="" style="max-width:180px;max-height:110px;object-fit:cover" class="rounded border">
              <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" name="obrisi_sliku" value="1" id="obrisiSliku">
                <label class="form-check-label" for="obrisiSliku">Obriši postojeću sliku</label>
              </div>
            </div>
          <?php endif; ?>
        </div>

        <div class="col-md-6">
          <label class="form-label">PDF prilog (opciono)</label>
          <input type="file" name="pdf" accept="application/pdf,.pdf" class="form-control">
          <div class="form-text">Možeš dodati PDF, ali nije obavezan.</div>
          <?php if (!empty($edit['pdf_link'])): ?>
            <div class="mt-2">
              <a target="_blank" href="<?= h($edit['pdf_link']) ?>">Trenutni PDF</a>
              <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" name="obrisi_pdf" value="1" id="obrisiPdf">
                <label class="form-check-label" for="obrisiPdf">Obriši postojeći PDF</label>
              </div>
            </div>
          <?php endif; ?>
        </div>

        <div class="col-12">
          <label class="form-label">Tekst članka</label>
          <textarea name="tekst" class="form-control" rows="12" required><?= h($edit['tekst'] ?? '') ?></textarea>
          <div class="form-text">Podnaslov pišeš kao: ## Moj podnaslov. Dodatne slike ubacuješ markerima: [slika:1], [slika:2], [slika:3]...</div>
        </div>

        <div class="col-12">
          <label class="form-label">Dodatne slike za tekst (opciono)</label>
          <input type="file" name="dodatne_slike[]" accept="image/jpeg,image/png,image/webp,image/gif,.jpg,.jpeg,.png,.webp,.gif" class="form-control" multiple>
          <div class="form-text">Redosled uploadovanih slika odgovara markerima [slika:1], [slika:2]...</div>
          <?php if ($editGalleryImages): ?>
            <div class="row g-2 mt-2">
              <?php foreach ($editGalleryImages as $index => $imagePath): ?>
                <div class="col-6 col-md-3">
                  <div class="border rounded p-2 bg-light">
                    <img src="<?= h($imagePath) ?>" alt="" style="width:100%;aspect-ratio:16/9;object-fit:cover" class="rounded">
                    <code class="d-block mt-2">[slika:<?= $index + 1 ?>]</code>
                    <div class="form-check mt-1">
                      <input class="form-check-input" type="checkbox" name="obrisi_dodatne_slike[]" value="<?= (int)$index ?>" id="obrisiDodatnu<?= (int)$index ?>">
                      <label class="form-check-label" for="obrisiDodatnu<?= (int)$index ?>">Obriši</label>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="col-12 d-flex gap-2">
          <button class="btn btn-success"><?= $edit ? 'Sačuvaj izmene' : 'Objavi članak' ?></button>
          <?php if ($edit): ?>
            <a class="btn btn-outline-secondary" href="korisni_materijali_admin.php">Otkaži izmenu</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
      <h5 class="card-title">Sve objave</h5>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead><tr><th>ID</th><th>Naslov</th><th>Datum</th><th>Slika</th><th>Akcije</th></tr></thead>
          <tbody>
          <?php while ($row = $list->fetch_assoc()): ?>
            <tr>
              <td><?= (int)$row['id'] ?></td>
              <td><?= h($row['naslov']) ?></td>
              <td><?= h(date('d.m.Y', strtotime($row['created_at']))) ?></td>
              <td>
                <?php if (!empty($row['image_link'])): ?>
                  <img src="<?= h($row['image_link']) ?>" alt="" style="width:56px;height:36px;object-fit:cover" class="rounded border">
                <?php else: ?>
                  <span class="text-muted">-</span>
                <?php endif; ?>
              </td>
              <td class="text-nowrap">
                <a class="btn btn-sm btn-primary" target="_blank" href="korisni-materijali.php?id=<?= (int)$row['id'] ?>">Otvori</a>
                <a class="btn btn-sm btn-outline-secondary" href="korisni_materijali_admin.php?izmeni=<?= (int)$row['id'] ?>">Izmeni</a>
                <a class="btn btn-sm btn-outline-danger" href="korisni_materijali_admin.php?obrisi=<?= (int)$row['id'] ?>" onclick="return confirm('Obrisati ovaj članak?')">Obriši</a>
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
