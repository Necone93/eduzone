<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

require __DIR__ . '/db_connect.php';

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

function gallery_images_from_value($value): array {
  $images = json_decode((string)$value, true);
  return is_array($images) ? array_values(array_filter($images, 'is_string')) : [];
}

function excerpt_from_text(string $text, int $limit = 180): string {
  $text = preg_replace('/\[slika:\s*\d+\]/iu', '', $text);
  $text = trim(preg_replace('/\s+/', ' ', $text));
  if (function_exists('mb_strlen') && function_exists('mb_substr')) {
    if (mb_strlen($text, 'UTF-8') <= $limit) return $text;
    return rtrim(mb_substr($text, 0, $limit, 'UTF-8')) . '...';
  }
  if (strlen($text) <= $limit) return $text;
  return rtrim(substr($text, 0, $limit)) . '...';
}

function render_article_text(string $text, array $galleryImages): string {
  $parts = preg_split('/(\[slika:\s*\d+\]|^##\s+.+$)/ium', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
  $html = '';

  foreach ($parts as $part) {
    $trimmedPart = trim($part);

    if (preg_match('/^\[slika:\s*(\d+)\]$/iu', $trimmedPart, $match)) {
      $index = (int)$match[1] - 1;
      if (isset($galleryImages[$index])) {
        $src = h($galleryImages[$index]);
        $html .= '<figure class="article-inline-image"><img src="' . $src . '" alt=""><figcaption>Slika ' . ($index + 1) . '</figcaption></figure>';
      }
      continue;
    }

    if (preg_match('/^##\s+(.+)$/u', $trimmedPart, $match)) {
      $html .= '<h2 class="article-subtitle">' . h($match[1]) . '</h2>';
      continue;
    }

    $html .= nl2br(h($part));
  }

  return $html;
}

$conn->set_charset('utf8mb4');
ensure_it_kutak_schema($conn);

$articleId = isset($_GET['id']) ? max(0, (int)$_GET['id']) : 0;
$article = null;
$homeLink = !empty($_SESSION['email']) ? 'dashboard.php' : 'index';

if ($articleId > 0) {
  $stmt = $conn->prepare("SELECT id, naslov, tekst, pdf_link, image_link, gallery_images, created_at FROM korisni_materijali WHERE id=? LIMIT 1");
  $stmt->bind_param('i', $articleId);
  $stmt->execute();
  $article = $stmt->get_result()->fetch_assoc();
  $stmt->close();
}

if ($articleId > 0 && !$article) {
  http_response_code(404);
}

$pageTitle = $article ? $article['naslov'] . ' | IT kutak' : 'IT kutak | EduZone';
$pageDescription = $article
  ? excerpt_from_text((string)$article['tekst'], 155)
  : 'IT kutak sa novostima iz sveta informacionih tehnologija, savetima za učenje, karijeru i razvoj digitalnih veština.';
$ogImage = !empty($article['image_link'])
  ? 'https://eduzone.rs/' . ltrim((string)$article['image_link'], '/')
  : 'https://eduzone.rs/img/eduZoneLogo.png';
$canonical = $article
  ? 'https://eduzone.rs/korisni-materijali?id=' . (int)$article['id']
  : 'https://eduzone.rs/korisni-materijali';
$structuredData = $article ? [
  '@context' => 'https://schema.org',
  '@type' => 'BlogPosting',
  'headline' => (string)$article['naslov'],
  'description' => $pageDescription,
  'image' => $ogImage,
  'url' => $canonical,
  'datePublished' => date('c', strtotime((string)$article['created_at'])),
  'dateModified' => date('c', strtotime((string)$article['created_at'])),
  'author' => [
    '@type' => 'Person',
    'name' => 'Nenad Dmitrović',
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
  'name' => 'IT kutak',
  'description' => $pageDescription,
  'url' => $canonical,
  'isPartOf' => [
    '@type' => 'WebSite',
    'name' => 'EduZone',
    'url' => 'https://eduzone.rs/',
  ],
];

if (!$article) {
  $materijali = $conn->query("SELECT id, naslov, tekst, pdf_link, image_link, gallery_images, created_at FROM korisni_materijali ORDER BY created_at DESC, id DESC");
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
  <meta property="og:type" content="<?= $article ? 'article' : 'website' ?>">
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
    .hero-nav {
      position: relative;
      padding: 1.25rem 1.5rem;
      z-index: 20;
      display: flex;
      align-items: center;
      justify-content: flex-end;
    }
    .nav-menu { display: flex; gap: 1.75rem; align-items: center; }
    .nav-menu a {
      color: var(--ez-text);
      text-decoration: none;
      font-weight: 600;
      font-size: 0.95rem;
      transition: color 0.3s;
    }
    .nav-menu a:hover { color: var(--ez-accent); }
    .page-header { padding: 4.5rem 1.5rem 2.5rem; text-align: center; }
    .page-header h1 {
      font-size: clamp(2rem, 4vw, 2.8rem);
      font-weight: 800;
      line-height: 1.1;
      margin-bottom: 0.8rem;
    }
    .page-header p { color: var(--ez-muted); font-size: 1.05rem; margin: 0 auto; max-width: 760px; }
    .content-section { flex: 1; padding: 2rem 0 4rem; }
    .article-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 1.25rem;
    }
    .article-card {
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
    .article-card:hover { transform: translateY(-4px); border-color: var(--ez-accent); color: var(--ez-text); }
    .article-cover {
      width: 100%;
      aspect-ratio: 16 / 9;
      background: #10131f;
      object-fit: cover;
      display: block;
    }
    .article-cover.placeholder {
      display: grid;
      place-items: center;
      color: var(--ez-muted);
      border-bottom: 1px solid var(--ez-border);
    }
    .article-card-body { padding: 1.25rem; display: flex; flex-direction: column; gap: .75rem; flex: 1; }
    .article-card h2 { font-size: 1.2rem; margin: 0; font-weight: 800; }
    .article-card p { color: var(--ez-muted); margin: 0; }
    .article-meta { color: var(--ez-muted); font-size: .9rem; }
    .article-read { color: var(--ez-accent); font-weight: 800; margin-top: auto; }
    .article-view {
      max-width: 900px;
      margin: 0 auto;
      background: linear-gradient(180deg, var(--ez-surface), #131829 98%);
      border: 1px solid var(--ez-border);
      border-radius: 12px;
      overflow: hidden;
    }
    .article-view-cover {
      width: 100%;
      max-height: 520px;
      object-fit: cover;
      display: block;
      background: #10131f;
    }
    .article-view-body { padding: clamp(1.25rem, 3vw, 2.25rem); }
    .article-view h1 { font-size: clamp(2rem, 4vw, 3rem); font-weight: 850; margin-bottom: .75rem; }
    .article-text {
      color: var(--ez-text);
      font-size: 1.08rem;
      line-height: 1.75;
    }
    .article-inline-image {
      margin: 1.75rem 0;
      border: 1px solid var(--ez-border);
      border-radius: 12px;
      overflow: hidden;
      background: #10131f;
    }
    .article-inline-image img {
      width: 100%;
      max-height: 520px;
      object-fit: cover;
      display: block;
    }
    .article-inline-image figcaption {
      color: var(--ez-muted);
      font-size: .85rem;
      padding: .6rem .85rem;
      margin: 0;
    }
    .article-subtitle {
      margin: 2rem 0 .75rem;
      font-size: clamp(1.35rem, 2vw, 1.8rem);
      font-weight: 850;
      color: var(--ez-text);
      line-height: 1.2;
    }
    .article-actions { display: flex; gap: .75rem; flex-wrap: wrap; margin-top: 1.75rem; }
    .btn-read {
      background: var(--ez-accent);
      color: #000;
      border: none;
      border-radius: 8px;
      font-weight: 800;
    }
    .btn-read:hover { background: #ffed4e; color: #000; }
    .btn-outline-ez {
      border: 1px solid var(--ez-border);
      color: var(--ez-text);
      border-radius: 8px;
      font-weight: 800;
    }
    .btn-outline-ez:hover { border-color: var(--ez-accent); color: var(--ez-accent); }
    .empty-state {
      border: 1px solid var(--ez-border);
      border-radius: 12px;
      padding: 2rem;
      text-align: center;
      color: var(--ez-muted);
      background: linear-gradient(180deg, var(--ez-surface), #131829 98%);
    }
    .footer {
      border-top: 1px solid var(--ez-border);
      padding: 1.25rem 1.5rem 1.5rem;
      color: var(--ez-muted);
      font-size: 0.9rem;
      text-align: center;
      margin-top: auto;
    }
    .footer a { color: var(--ez-accent); text-decoration: none; }
    .footer a:hover { text-decoration: underline; }
    @media (max-width: 991.98px) { .article-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 575.98px) {
      .hero-nav { justify-content: flex-end; }
      .nav-menu { gap: 1rem; flex-wrap: wrap; justify-content: center; }
      .article-grid { grid-template-columns: 1fr; }
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

<?php if ($article): ?>
  <section class="content-section">
    <div class="container">
      <article class="article-view">
        <?php if (!empty($article['image_link'])): ?>
          <img class="article-view-cover" src="<?= h($article['image_link']) ?>" alt="<?= h($article['naslov']) ?>">
        <?php endif; ?>
        <div class="article-view-body">
          <div class="article-meta"><?= h(date('d.m.Y', strtotime($article['created_at']))) ?></div>
          <h1><?= h($article['naslov']) ?></h1>
          <div class="article-text"><?= render_article_text((string)$article['tekst'], gallery_images_from_value($article['gallery_images'] ?? '')) ?></div>
          <div class="article-actions">
            <a class="btn btn-outline-ez" href="korisni-materijali">Nazad na IT kutak</a>
            <?php if (!empty($article['pdf_link'])): ?>
              <a class="btn btn-read" target="_blank" rel="noopener" href="<?= h($article['pdf_link']) ?>">Otvori prilog</a>
            <?php endif; ?>
          </div>
        </div>
      </article>
    </div>
  </section>
<?php elseif ($articleId > 0): ?>
  <section class="page-header">
    <div class="container">
      <h1>Članak nije pronađen</h1>
      <p>Proveri link ili se vrati na listu objava.</p>
    </div>
  </section>
  <section class="content-section">
    <div class="container text-center">
      <a class="btn btn-read" href="korisni-materijali">Nazad na IT kutak</a>
    </div>
  </section>
<?php else: ?>
  <section class="page-header">
    <div class="container">
      <h1>IT kutak</h1>
      <p>Novosti, saveti za učenje, karijeru i korisni tekstovi iz sveta informacionih tehnologija.</p>
    </div>
  </section>

  <section class="content-section">
    <div class="container">
      <?php if ($materijali->num_rows === 0): ?>
        <div class="empty-state">Još uvek nema objava.</div>
      <?php else: ?>
        <div class="article-grid">
          <?php while ($materijal = $materijali->fetch_assoc()): ?>
            <a class="article-card" href="korisni-materijali?id=<?= (int)$materijal['id'] ?>">
              <?php if (!empty($materijal['image_link'])): ?>
                <img class="article-cover" src="<?= h($materijal['image_link']) ?>" alt="<?= h($materijal['naslov']) ?>">
              <?php else: ?>
                <div class="article-cover placeholder">IT kutak</div>
              <?php endif; ?>
              <div class="article-card-body">
                <div class="article-meta"><?= h(date('d.m.Y', strtotime($materijal['created_at']))) ?></div>
                <h2><?= h($materijal['naslov']) ?></h2>
                <?php if (trim((string)$materijal['tekst']) !== ''): ?>
                  <p><?= h(excerpt_from_text((string)$materijal['tekst'])) ?></p>
                <?php endif; ?>
                <span class="article-read">Pročitaj članak</span>
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
</body>
</html>
