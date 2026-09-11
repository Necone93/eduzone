<?php
session_start();
if (!isset($_SESSION["email"])) { header("Location: index.php"); exit; }

require __DIR__ . '/db_connect.php';
$conn->set_charset('utf8mb4');

// ——— mala “debug” pomoć: ako zapne, videćeš poruku umesto 500 ———
function fail($msg, $code=500){
  http_response_code($code);
  echo "<!doctype html><meta charset='utf-8'><div style='padding:20px;font-family:system-ui'>
          <h3>Greška</h3><pre style='white-space:pre-wrap;background:#fee;border:1px solid #f99;padding:10px;border-radius:6px;'>"
          . htmlspecialchars($msg) .
       "</pre></div>";
  exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) fail("Pogrešan ili nedostajući ID lekcije.", 400);

// SELECT * da izbegnemo problem ako je naziv kolone drugačiji
$stmt = $conn->prepare("SELECT * FROM lekcije WHERE id = ? LIMIT 1");
if (!$stmt) fail("SQL priprema nije uspela: ".$conn->error);
$stmt->bind_param("i", $id);
if (!$stmt->execute()) fail("SQL izvršenje nije uspelo: ".$stmt->error);
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

if (!$row) fail("Lekcija (#$id) nije pronađena.", 404);

$naziv = $row['naziv'] ?? ("Lekcija #".$id);

// Prati isti redosled kao lekcije.php: ID rastuće unutar predmeta.
$subject = (string)($row['predmet'] ?? '');
$previousLesson = null;
$nextLesson = null;
foreach (['previous' => ['<', 'DESC'], 'next' => ['>', 'ASC']] as $direction => [$operator, $order]) {
    $neighborStmt = $conn->prepare("SELECT id, naziv FROM lekcije WHERE predmet = ? AND id $operator ? ORDER BY id $order LIMIT 1");
    if (!$neighborStmt) continue;
    $neighborStmt->bind_param('si', $subject, $id);
    if ($neighborStmt->execute()) {
        $neighbor = $neighborStmt->get_result()->fetch_assoc();
        if ($direction === 'previous') $previousLesson = $neighbor;
        else $nextLesson = $neighbor;
    }
    $neighborStmt->close();
}
$conn->close();
$lessonListUrl = 'lekcije.php?' . http_build_query(['predmet' => $subject]);

function render_lesson_navigation(?array $previous, ?array $next, string $position): void {
    if (!$previous && !$next) return;
    ?>
    <nav class="lesson-navigation" aria-label="<?= htmlspecialchars('Navigacija kroz lekcije — ' . $position, ENT_QUOTES, 'UTF-8') ?>">
      <?php if ($previous): ?>
      <a class="lesson-neighbor lesson-previous" href="lekcija.php?id=<?= (int)$previous['id'] ?>" rel="prev">
        <span>← Vrati se na lekciju</span>
        <strong><?= htmlspecialchars((string)$previous['naziv'], ENT_QUOTES, 'UTF-8') ?></strong>
      </a>
      <?php endif; ?>
      <?php if ($next): ?>
      <a class="lesson-neighbor lesson-next" href="lekcija.php?id=<?= (int)$next['id'] ?>" rel="next">
        <span>Idi na lekciju →</span>
        <strong><?= htmlspecialchars((string)$next['naziv'], ENT_QUOTES, 'UTF-8') ?></strong>
      </a>
      <?php endif; ?>
    </nav>
    <?php
}


// pronađi kolonu sa linkom PDF-a (više mogućih naziva)
$pdf = "";
foreach (['pdf_link','pdf','link','file','fajl','putanja','url'] as $k) {
  if (!empty($row[$k])) { $pdf = $row[$k]; break; }
}
if ($pdf === "") fail("U tabeli 'lekcije' ne postoji kolona sa linkom PDF-a (očekivano: pdf_link/pdf/link/file/fajl/putanja). Dodaj je ili javi koji je tačan naziv.");

// ako je sačuvana samo relativna putanja, probaj tipičnu lokaciju
if (preg_match('~^https?://|^/~i', $pdf) !== 1) {
  // ako već počinje na 'uploads/' ili 'lekcije/' ostavi tako; inače prefiksuj
  if (!preg_match('~^(uploads|lekcije|files)/~i', $pdf)) {
    $pdf = 'uploads/lekcije/' . ltrim($pdf, '/');
  }
}
?>
<!doctype html>
<html lang="sr">
<head>
<?php require_once __DIR__ . '/analytics.php'; ?>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($naziv) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/site.css">
  <link rel="stylesheet" href="assets/css/lesson-html.css?v=<?= filemtime(__DIR__ . '/assets/css/lesson-html.css') ?>">
  <style>
    body {
      min-height: 100vh;
      margin: 0;
      background: radial-gradient(circle at top right, rgba(255, 214, 66, .15), transparent 30%), var(--ez-bg);
      color: var(--ez-text);
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }
    .lesson-toolbar {
      position: sticky;
      top: 0;
      z-index: 20;
      background: rgba(10, 12, 18, .96);
      backdrop-filter: blur(12px);
      border-bottom: 1px solid var(--ez-border);
      box-shadow: 0 8px 24px rgba(15, 23, 42, .08);
    }
    .lesson-title {
      font-weight: 800;
      color: var(--ez-text);
    }
    .lesson-pages {
      width: min(100%, 980px);
      margin: 0 auto;
      padding: clamp(1rem, 3vw, 2rem);
    }
    .lesson-page {
      display: block;
      width: 100%;
      height: auto;
      margin: 0 auto 1.25rem;
      background: #fff;
      border-radius: 10px;
      box-shadow: 0 18px 50px rgba(15, 23, 42, .16);
    }
    .lesson-status {
      margin: 2rem auto;
      max-width: 720px;
      padding: 1.2rem 1.4rem;
      border-radius: 12px;
      background: var(--ez-surface);
      border: 1px solid var(--ez-border);
      color: var(--ez-muted);
      text-align: center;
    }
    .pdf-fallback {
      display: none;
    }
    .pdf-fallback iframe {
      width: 100%;
      height: min(75vh, 900px);
      border: 1px solid var(--ez-border);
      border-radius: 8px;
      background: #fff;
    }
    @media print {
      .lesson-toolbar { display: none; }
      .lesson-pages { padding: 0; width: 100%; }
      .lesson-page { box-shadow: none; border-radius: 0; margin-bottom: 0; }
    }
  </style>
</head>
<body>
<div class="lesson-toolbar">
  <div class="container-fluid py-2 px-3 d-flex justify-content-between align-items-center gap-3">
    <div class="lesson-title"><?= htmlspecialchars($naziv) ?></div>
    <div class="d-flex gap-2">
      <a class="btn btn-sm btn-secondary" href="<?= htmlspecialchars($lessonListUrl, ENT_QUOTES, 'UTF-8') ?>">Sve lekcije</a>
      <a class="btn btn-sm btn-primary" href="<?= htmlspecialchars($pdf) ?>" download>Preuzmi PDF</a>
    </div>
  </div>
</div>

<?php render_lesson_navigation($previousLesson, $nextLesson, 'početak'); ?>
<noscript><p class="lesson-status">Za prikaz stranica uključi JavaScript ili koristi dugme „Preuzmi PDF“.</p></noscript>
<main class="lesson-pages" id="lesson-pages" data-pdf="<?= htmlspecialchars($pdf, ENT_QUOTES) ?>">
  <div class="lesson-status" id="lesson-status">Učitavam lekciju...</div>
  <div class="lesson-status pdf-fallback" id="pdf-fallback">
    <p>PDF se prikazuje u okviru ove stranice.</p>
    <iframe src="<?= htmlspecialchars($pdf, ENT_QUOTES) ?>" title="<?= htmlspecialchars($naziv, ENT_QUOTES) ?>"></iframe>
    <p class="mt-3 mb-0"><a class="btn btn-outline-primary" href="<?= htmlspecialchars($pdf) ?>" download>Preuzmi PDF</a></p>
  </div>
</main>

<script type="module">
  let pdfjsLib;

  const pagesEl = document.getElementById('lesson-pages');
  const statusEl = document.getElementById('lesson-status');
  const fallbackEl = document.getElementById('pdf-fallback');
  const pdfUrl = pagesEl?.dataset.pdf;

  async function renderLesson() {
    if (!pdfUrl) throw new Error('PDF nije dostupan.');

    pdfjsLib = await import('https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/build/pdf.min.mjs');
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/build/pdf.worker.min.mjs';
    const pdf = await pdfjsLib.getDocument(pdfUrl).promise;
    const maxWidth = Math.min(pagesEl.clientWidth, 940);

    for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber++) {
      statusEl.textContent = `Učitavam stranicu ${pageNumber} od ${pdf.numPages}...`;
      const page = await pdf.getPage(pageNumber);
      const baseViewport = page.getViewport({ scale: 1 });
      const scale = maxWidth / baseViewport.width;
      const viewport = page.getViewport({ scale });
      const canvas = document.createElement('canvas');
      const context = canvas.getContext('2d');
      const outputScale = window.devicePixelRatio || 1;

      canvas.className = 'lesson-page';
      canvas.width = Math.floor(viewport.width * outputScale);
      canvas.height = Math.floor(viewport.height * outputScale);
      canvas.style.width = `${Math.floor(viewport.width)}px`;
      canvas.style.maxWidth = '100%';

      context.setTransform(outputScale, 0, 0, outputScale, 0, 0);
      pagesEl.appendChild(canvas);
      await page.render({ canvasContext: context, viewport }).promise;
    }

    statusEl.remove();
  }

  renderLesson().catch(() => {
    statusEl.style.display = 'none';
    fallbackEl.style.display = 'block';
  });
</script>
<?php render_lesson_navigation($previousLesson, $nextLesson, 'kraj'); ?>
</body>
</html>
