<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

require_once __DIR__ . '/admin_config.php';
require __DIR__ . '/db_connect.php';

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

function excerpt_from_text(string $text, int $limit = 170): string {
  $text = trim(preg_replace('/\s+/', ' ', $text));
  if (function_exists('mb_strlen') && function_exists('mb_substr')) {
    if (mb_strlen($text, 'UTF-8') <= $limit) return $text;
    return rtrim(mb_substr($text, 0, $limit, 'UTF-8')) . '...';
  }
  if (strlen($text) <= $limit) return $text;
  return rtrim(substr($text, 0, $limit)) . '...';
}

function figma_embed_url(?string $url): ?string {
  $url = trim((string)$url);
  if ($url === '') return null;

  $parts = parse_url($url);
  if (!$parts || empty($parts['host']) || empty($parts['path'])) return null;

  $host = strtolower($parts['host']);
  if (str_starts_with($host, 'www.')) {
    $host = substr($host, 4);
  }
  if (!in_array($host, ['figma.com', 'embed.figma.com'], true)) {
    return null;
  }

  $path = $parts['path'];
  if (!preg_match('~^/(design|proto|file)/[^/]+/.+~', $path)) {
    return null;
  }

  if (str_starts_with($path, '/file/')) {
    $path = preg_replace('~^/file/~', '/design/', $path, 1);
  }

  parse_str($parts['query'] ?? '', $query);
  $query['embed-host'] = 'share';

  return 'https://embed.figma.com' . $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

function project_embed_url(?string $url): ?string {
  $url = trim((string)$url);
  if ($url === '') return null;

  $figmaUrl = figma_embed_url($url);
  if ($figmaUrl !== null) return $figmaUrl;

  $parts = parse_url($url);
  if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return null;

  if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
    return null;
  }

  return $url;
}

function load_work_images(mysqli $conn, int $radId): array {
  $stmt = $conn->prepare("SELECT image_link FROM radovi_ucenika_slike WHERE rad_id=? ORDER BY id ASC");
  $stmt->bind_param('i', $radId);
  $stmt->execute();
  $result = $stmt->get_result();
  $images = [];
  while ($row = $result->fetch_assoc()) {
    $images[] = $row['image_link'];
  }
  $stmt->close();
  return $images;
}

$conn->set_charset('utf8mb4');
ensure_radovi_schema($conn);
$isAdmin = ez_is_admin();

$workId = isset($_GET['id']) ? max(0, (int)$_GET['id']) : 0;
$work = null;
$workImages = [];
$projectEmbedUrl = null;
$homeLink = !empty($_SESSION['email']) ? 'dashboard.php' : 'index';

if ($workId > 0) {
  $stmt = $conn->prepare("SELECT id, naslov, tekst, kratak_opis, ucenik, razred, project_link, created_at FROM radovi_ucenika WHERE id=? LIMIT 1");
  $stmt->bind_param('i', $workId);
  $stmt->execute();
  $work = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if ($work) {
    $workImages = load_work_images($conn, (int)$work['id']);
    $projectEmbedUrl = project_embed_url($work['project_link'] ?? '');
  } else {
    http_response_code(404);
  }
}

$pageTitle = $work ? $work['naslov'] . ' | Radovi učenika' : 'Radovi učenika | EduZone';
$pageDescription = $work
  ? excerpt_from_text((string)($work['kratak_opis'] ?: $work['tekst']), 155)
  : 'Pogledajte projekte i radove učenika smera Tehničar informacionih tehnologija.';
$ogImage = $workImages
  ? 'https://eduzone.rs/' . ltrim($workImages[0], '/')
  : 'https://eduzone.rs/img/eduZoneLogo.png';
$canonical = $work
  ? 'https://eduzone.rs/radovi-ucenika?id=' . (int)$work['id']
  : 'https://eduzone.rs/radovi-ucenika';
$structuredData = $work ? [
  '@context' => 'https://schema.org',
  '@type' => 'CreativeWork',
  'name' => (string)$work['naslov'],
  'description' => $pageDescription,
  'image' => $ogImage,
  'url' => $canonical,
  'datePublished' => date('c', strtotime((string)$work['created_at'])),
  'creator' => [
    '@type' => 'Person',
    'name' => (string)$work['ucenik'],
  ],
  'publisher' => [
    '@type' => 'EducationalOrganization',
    'name' => 'EduZone',
    'logo' => [
      '@type' => 'ImageObject',
      'url' => 'https://eduzone.rs/img/eduZoneLogo.png',
    ],
  ],
] : [
  '@context' => 'https://schema.org',
  '@type' => 'CollectionPage',
  'name' => 'Radovi učenika',
  'description' => $pageDescription,
  'url' => $canonical,
  'isPartOf' => [
    '@type' => 'WebSite',
    'name' => 'EduZone',
    'url' => 'https://eduzone.rs/',
  ],
];

if (!$work) {
  $radovi = $conn->query("
    SELECT r.id, r.naslov, r.tekst, r.kratak_opis, r.ucenik, r.razred, r.project_link, r.created_at,
           (SELECT image_link FROM radovi_ucenika_slike s WHERE s.rad_id = r.id ORDER BY s.id ASC LIMIT 1) AS cover_image
    FROM radovi_ucenika r
    ORDER BY r.created_at DESC, r.id DESC
  ");
}
?>
<!DOCTYPE html>
<html lang="sr">
<head>
<?php require_once __DIR__ . '/analytics.php'; ?>
  <meta charset="UTF-8">
  <title><?= h($pageTitle) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="<?= h($pageDescription) ?>">
  <meta name="robots" content="index, follow">
  <meta name="author" content="EduZone">
  <link rel="canonical" href="<?= h($canonical) ?>">
  <meta property="og:type" content="<?= $work ? 'article' : 'website' ?>">
  <meta property="og:locale" content="sr_RS">
  <meta property="og:site_name" content="EduZone">
  <meta property="og:title" content="<?= h($pageTitle) ?>">
  <meta property="og:description" content="<?= h($pageDescription) ?>">
  <meta property="og:url" content="<?= h($canonical) ?>">
  <meta property="og:image" content="<?= h($ogImage) ?>">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= h($pageTitle) ?>">
  <meta name="twitter:description" content="<?= h($pageDescription) ?>">
  <meta name="twitter:image" content="<?= h($ogImage) ?>">
  <script type="application/ld+json">
<?= json_encode($structuredData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
  </script>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/site.css">

  <style>
    html, body { color: var(--ez-text); min-height: 100%; }
    body {
      min-height: 100vh;
      background: radial-gradient(circle at top right, rgba(255, 214, 66, 0.18), transparent 22%),
                  radial-gradient(circle at bottom left, rgba(255, 214, 66, 0.12), transparent 24%),
                  var(--ez-bg);
    }
    .public-page { min-height: 100vh; display: flex; flex-direction: column; }
    .hero-nav { position: relative; padding: 1.25rem 1.5rem; z-index: 20; display: flex; align-items: center; justify-content: flex-end; }
    .nav-menu { display: flex; gap: 1.75rem; align-items: center; }
    .nav-menu a { color: var(--ez-text); text-decoration: none; font-weight: 600; font-size: 0.95rem; transition: color 0.3s; }
    .nav-menu a:hover { color: var(--ez-accent); }
    .page-header { padding: 4.5rem 1.5rem 2.5rem; text-align: center; }
    .page-header h1 { font-size: clamp(2rem, 4vw, 2.8rem); font-weight: 800; line-height: 1.1; margin-bottom: 0.8rem; }
    .page-header p { color: var(--ez-muted); font-size: 1.05rem; margin: 0 auto; max-width: 720px; }
    .content-section { flex: 1; padding: 2rem 0 4rem; }
    .work-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1.25rem; }
    .work-card {
      display: flex;
      flex-direction: column;
      min-height: 100%;
      background: linear-gradient(180deg, var(--ez-surface), #131829 98%);
      border: 1px solid var(--ez-border);
      border-radius: 12px;
      overflow: hidden;
      color: var(--ez-text);
      text-decoration: none;
      transition: transform 0.3s, border-color 0.3s;
    }
    .work-card:hover { transform: translateY(-4px); border-color: var(--ez-accent); color: var(--ez-text); }
    .work-cover { width: 100%; aspect-ratio: 16 / 10; object-fit: cover; background: #10131f; display: block; }
    .work-cover.placeholder { display: grid; place-items: center; color: var(--ez-muted); border-bottom: 1px solid var(--ez-border); }
    .work-card-body { padding: 1.25rem; display: flex; flex-direction: column; gap: .7rem; flex: 1; }
    .work-card h2 { font-size: 1.2rem; margin: 0; font-weight: 850; }
    .work-meta { color: var(--ez-muted); font-size: .92rem; }
    .work-card p { color: var(--ez-muted); margin: 0; }
    .work-read { color: var(--ez-accent); font-weight: 800; margin-top: auto; }
    .work-view {
      max-width: 980px;
      margin: 0 auto;
      background: linear-gradient(180deg, var(--ez-surface), #131829 98%);
      border: 1px solid var(--ez-border);
      border-radius: 12px;
      overflow: hidden;
    }
    .work-view-body { padding: clamp(1.25rem, 3vw, 2.25rem); }
    .work-view h1 { font-size: clamp(2rem, 4vw, 3rem); font-weight: 850; margin-bottom: .75rem; }
    .work-description { color: var(--ez-text); font-size: 1.08rem; line-height: 1.75; white-space: pre-line; }
    .work-embed {
      padding: clamp(1rem, 2vw, 1.5rem);
      border-top: 1px solid var(--ez-border);
      background: #10131f;
    }
    .work-embed-frame {
      width: 100%;
      height: clamp(430px, 70vh, 760px);
      border: 1px solid var(--ez-border);
      border-radius: 10px;
      background: #080a12;
      display: block;
    }
    .work-gallery { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .9rem; padding: clamp(1rem, 2vw, 1.5rem); border-top: 1px solid var(--ez-border); }
    .work-gallery.single { grid-template-columns: 1fr; }
    .work-gallery img {
      width: 100%;
      height: auto;
      max-height: 760px;
      object-fit: contain;
      border-radius: 10px;
      border: 1px solid var(--ez-border);
      background: #10131f;
      display: block;
    }
    .work-slider {
      position: relative;
      padding: clamp(1rem, 2vw, 1.5rem);
      border-top: 1px solid var(--ez-border);
      background:
        radial-gradient(circle at 50% 35%, rgba(255, 213, 0, .08), transparent 34%),
        #10131f;
      overflow: hidden;
    }
    .slider-stage {
      position: relative;
      height: clamp(330px, 52vw, 680px);
      perspective: 1200px;
    }
    .work-slide {
      position: absolute;
      top: 0;
      left: 50%;
      width: min(78%, 980px);
      height: 100%;
      border-radius: 14px;
      opacity: 0;
      filter: blur(7px) saturate(.78);
      transform: translateX(-50%) scale(.72);
      transition: transform .42s ease, opacity .42s ease, filter .42s ease;
      cursor: pointer;
      pointer-events: none;
    }
    .work-slide img {
      width: 100%;
      height: 100%;
      object-fit: contain;
      border-radius: 14px;
      border: 1px solid var(--ez-border);
      background: #080a12;
      display: block;
      box-shadow: 0 22px 60px rgba(0, 0, 0, .35);
    }
    .work-slide.active {
      opacity: 1;
      filter: none;
      pointer-events: auto;
    }
    .work-slide.side {
      opacity: .42;
      pointer-events: auto;
    }
    .work-slide.far {
      opacity: 0;
    }
    .slider-btn {
      position: absolute;
      top: 50%;
      transform: translateY(-50%);
      width: 58px;
      height: 58px;
      display: grid;
      place-items: center;
      border-radius: 999px;
      border: 1px solid rgba(255, 213, 0, .5);
      background:
        linear-gradient(180deg, rgba(18, 22, 34, .78), rgba(8, 10, 18, .82));
      color: rgba(255, 213, 0, .9);
      font-size: 2.1rem;
      font-weight: 900;
      line-height: 1;
      z-index: 10;
      box-shadow: 0 14px 36px rgba(0, 0, 0, .45), inset 0 0 0 1px rgba(255, 255, 255, .06);
      backdrop-filter: blur(12px);
      transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease, background .18s ease;
    }
    .slider-btn:hover {
      color: var(--ez-accent);
      border-color: rgba(255, 213, 0, .9);
      background:
        linear-gradient(180deg, rgba(25, 29, 42, .88), rgba(10, 12, 20, .92));
      transform: translateY(-50%) scale(1.06);
      box-shadow: 0 18px 44px rgba(0, 0, 0, .52), 0 0 0 6px rgba(255, 213, 0, .1);
    }
    .slider-prev { left: clamp(1rem, 2.5vw, 1.75rem); }
    .slider-next { right: clamp(1rem, 2.5vw, 1.75rem); }
    .slider-dots {
      display: flex;
      justify-content: center;
      gap: .45rem;
      margin-top: .9rem;
    }
    .slider-dot {
      width: 10px;
      height: 10px;
      border-radius: 999px;
      border: 1px solid var(--ez-border);
      background: #242733;
      padding: 0;
    }
    .slider-dot.active {
      background: var(--ez-accent);
      border-color: var(--ez-accent);
    }
    @media (max-width: 760px) {
      .slider-stage { height: clamp(250px, 62vw, 390px); }
      .work-slide { width: 88%; }
      .work-slide.side { opacity: .22; }
      .slider-btn { width: 48px; height: 48px; font-size: 1.75rem; }
      .slider-prev { left: .75rem; }
      .slider-next { right: .75rem; }
    }
    .work-actions { display: flex; gap: .75rem; flex-wrap: wrap; margin-top: 1.75rem; }
    .admin-actions {
      display: flex;
      gap: .6rem;
      flex-wrap: wrap;
      margin-top: 1rem;
      padding-top: 1rem;
      border-top: 1px solid var(--ez-border);
    }
    .btn-read { background: var(--ez-accent); color: #000; border: none; border-radius: 8px; font-weight: 800; }
    .btn-read:hover { background: #ffed4e; color: #000; }
    .btn-outline-ez { border: 1px solid var(--ez-border); color: var(--ez-text); border-radius: 8px; font-weight: 800; }
    .btn-outline-ez:hover { border-color: var(--ez-accent); color: var(--ez-accent); }
    .btn-danger-ez { border: 1px solid #ff5a5a; color: #ff9b9b; border-radius: 8px; font-weight: 800; }
    .btn-danger-ez:hover { background: #ff5a5a; color: #121212; }
    .empty-state { border: 1px solid var(--ez-border); border-radius: 12px; padding: 2rem; text-align: center; color: var(--ez-muted); background: linear-gradient(180deg, var(--ez-surface), #131829 98%); }
    .footer { border-top: 1px solid var(--ez-border); padding: 1.25rem 1.5rem 1.5rem; color: var(--ez-muted); font-size: 0.9rem; text-align: center; margin-top: auto; }
    .footer a { color: var(--ez-accent); text-decoration: none; }
    .footer a:hover { text-decoration: underline; }
    @media (max-width: 991.98px) { .work-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 575.98px) {
      .hero-nav { justify-content: flex-end; }
      .nav-menu { gap: 1rem; flex-wrap: wrap; justify-content: center; }
      .work-grid, .work-gallery { grid-template-columns: 1fr; }
      .work-embed-frame { height: 78vh; min-height: 520px; }
    }
  </style>
</head>
<body>
<div class="public-page">

<nav class="hero-nav">
  <div class="nav-menu">
    <a href="<?= h($homeLink) ?>">Početna</a>
    <a href="korisni-materijali">IT kutak</a>
    <a href="radovi-ucenika">Radovi učenika</a>
      <a href="digitalna-ucionica">Digitalna učionica</a>
  </div>
</nav>

<?php if ($work): ?>
  <section class="content-section">
    <div class="container">
      <article class="work-view">
        <div class="work-view-body">
          <div class="work-meta">
            <?= h($work['ucenik']) ?><?= !empty($work['razred']) ? ' · ' . h($work['razred']) : '' ?> · <?= h(date('d.m.Y', strtotime($work['created_at']))) ?>
          </div>
          <h1><?= h($work['naslov']) ?></h1>
          <?php if (trim((string)$work['kratak_opis']) !== ''): ?>
            <p class="work-meta"><?= h($work['kratak_opis']) ?></p>
          <?php endif; ?>
          <div class="work-description"><?= h($work['tekst']) ?></div>
          <div class="work-actions">
            <a class="btn btn-outline-ez" href="radovi-ucenika">Nazad na radove</a>
            <?php if (trim((string)$work['project_link']) !== ''): ?>
              <a class="btn btn-read" href="<?= h($work['project_link']) ?>" target="_blank" rel="noopener noreferrer">Otvori projekat</a>
            <?php endif; ?>
          </div>
          <?php if ($isAdmin): ?>
            <div class="admin-actions">
              <a class="btn btn-outline-ez" href="radovi_ucenika_admin.php?izmeni=<?= (int)$work['id'] ?>">Izmeni rad</a>
              <a class="btn btn-danger-ez" href="radovi_ucenika_admin.php?obrisi=<?= (int)$work['id'] ?>" onclick="return confirm('Obrisati ovaj rad?')">Obriši rad</a>
            </div>
          <?php endif; ?>
        </div>
        <?php if ($projectEmbedUrl): ?>
          <div class="work-embed">
            <iframe
              class="work-embed-frame"
              src="<?= h($projectEmbedUrl) ?>"
              allowfullscreen
              loading="lazy"
              title="<?= h($work['naslov']) ?> - prikaz projekta"></iframe>
          </div>
        <?php endif; ?>
        <?php if (count($workImages) > 1): ?>
          <div class="work-slider" data-work-slider>
            <div class="slider-stage">
              <?php foreach ($workImages as $index => $image): ?>
                <div class="work-slide" data-slide-index="<?= (int)$index ?>">
                  <img src="<?= h($image) ?>" alt="<?= h($work['naslov']) ?>">
                </div>
              <?php endforeach; ?>
            </div>
            <button type="button" class="slider-btn slider-prev" data-slider-prev aria-label="Prethodna slika">‹</button>
            <button type="button" class="slider-btn slider-next" data-slider-next aria-label="Sledeća slika">›</button>
            <div class="slider-dots" aria-label="Slike projekta">
              <?php foreach ($workImages as $index => $_): ?>
                <button type="button" class="slider-dot" data-slider-dot="<?= (int)$index ?>" aria-label="Slika <?= (int)$index + 1 ?>"></button>
              <?php endforeach; ?>
            </div>
          </div>
        <?php elseif ($workImages): ?>
          <div class="work-gallery single">
            <?php foreach ($workImages as $image): ?>
              <img src="<?= h($image) ?>" alt="<?= h($work['naslov']) ?>">
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </article>
    </div>
  </section>
<?php elseif ($workId > 0): ?>
  <section class="page-header">
    <div class="container">
      <h1>Rad nije pronađen</h1>
      <p>Proveri link ili se vrati na listu radova.</p>
    </div>
  </section>
  <section class="content-section">
    <div class="container text-center">
      <a class="btn btn-read" href="radovi-ucenika">Nazad na radove</a>
    </div>
  </section>
<?php else: ?>
  <section class="page-header">
    <div class="container">
      <h1>Radovi učenika</h1>
      <p>Primeri odličnih projekata i radova naših učenika</p>
    </div>
  </section>

  <section class="content-section">
    <div class="container">
      <?php if ($radovi->num_rows === 0): ?>
        <div class="empty-state">Još uvek nema objavljenih radova.</div>
      <?php else: ?>
        <div class="work-grid">
          <?php while ($rad = $radovi->fetch_assoc()): ?>
            <a class="work-card" href="radovi-ucenika?id=<?= (int)$rad['id'] ?>">
              <?php if (!empty($rad['cover_image'])): ?>
                <img class="work-cover" src="<?= h($rad['cover_image']) ?>" alt="<?= h($rad['naslov']) ?>">
              <?php else: ?>
                <div class="work-cover placeholder">Rad učenika</div>
              <?php endif; ?>
              <div class="work-card-body">
                <div class="work-meta"><?= h($rad['ucenik']) ?><?= !empty($rad['razred']) ? ' · ' . h($rad['razred']) : '' ?></div>
                <h2><?= h($rad['naslov']) ?></h2>
                <p><?= h(excerpt_from_text((string)($rad['kratak_opis'] ?: $rad['tekst']))) ?></p>
                <span class="work-read">Pogledaj rad</span>
              </div>
            </a>
          <?php endwhile; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>
<?php endif; ?>

<footer class="footer">
  <div class="container">
    <p style="margin-bottom: 0.5rem;">&copy; <?= date('Y') ?> EduZone</p>
  </div>
</footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('[data-work-slider]').forEach(function (slider) {
  const slides = Array.from(slider.querySelectorAll('.work-slide'));
  const dots = Array.from(slider.querySelectorAll('[data-slider-dot]'));
  const prev = slider.querySelector('[data-slider-prev]');
  const next = slider.querySelector('[data-slider-next]');
  let current = 0;

  function showSlide(index) {
    if (!slides.length) return;
    current = (index + slides.length) % slides.length;
    slides.forEach(function (slide, slideIndex) {
      let offset = slideIndex - current;
      if (offset > slides.length / 2) offset -= slides.length;
      if (offset < -slides.length / 2) offset += slides.length;

      const absOffset = Math.abs(offset);
      const isActive = offset === 0;
      const isSide = absOffset === 1;

      slide.classList.toggle('active', isActive);
      slide.classList.toggle('side', isSide);
      slide.classList.toggle('far', absOffset > 1);
      slide.style.zIndex = String(20 - absOffset);
      slide.style.transform = `translateX(calc(-50% + ${offset * 42}%)) scale(${isActive ? 1 : .78})`;
    });
    dots.forEach(function (dot, dotIndex) {
      dot.classList.toggle('active', dotIndex === current);
    });
  }

  prev?.addEventListener('click', function () { showSlide(current - 1); });
  next?.addEventListener('click', function () { showSlide(current + 1); });
  dots.forEach(function (dot) {
    dot.addEventListener('click', function () {
      showSlide(Number(dot.getAttribute('data-slider-dot') || 0));
    });
  });
  slides.forEach(function (slide) {
    slide.addEventListener('click', function () {
      showSlide(Number(slide.getAttribute('data-slide-index') || 0));
    });
  });

  showSlide(0);
});
</script>
</body>
</html>
