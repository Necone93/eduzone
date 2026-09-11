<?php
$lessons = require __DIR__ . '/content/digitalna-ucionica.php';
$contentVersion = (string) filemtime(__DIR__ . '/content/digitalna-ucionica.php');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$subjects = ['informatika' => 'Informatika', 'web-dizajn' => 'Web dizajn', 'web-programiranje' => 'Web programiranje'];
$descriptions = ['informatika' => 'Razumi računar i savladaj digitalne osnove.', 'web-dizajn' => 'Pretvori ideju u preglednu i lepo uređenu stranicu.', 'web-programiranje' => 'Dodaj logiku i oživi svoje web stranice.'];
$slug = isset($_GET['lekcija']) && is_string($_GET['lekcija']) ? $_GET['lekcija'] : '';
$lesson = $lessons[$slug] ?? null;
$area = isset($_GET['oblast']) && is_string($_GET['oblast']) ? $_GET['oblast'] : '';
$catalog = isset($_GET['oblast']) || isset($_GET['q']);
$query = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$missing = (isset($_GET['lekcija']) && !$lesson) || ($catalog && $area !== '' && $area !== 'sve' && !isset($subjects[$area]));
$normalize = function ($value) { return strtr(mb_strtolower($value, 'UTF-8'), ['č'=>'c', 'ć'=>'c', 'š'=>'s', 'ž'=>'z', 'đ'=>'dj']); };
$filtered = array_filter($lessons, function ($item) use ($area, $query, $subjects, $normalize) {
    return ($area === '' || $area === 'sve' || $item['oblast'] === $area)
        && ($query === '' || strpos($normalize($item['naslov'] . ' ' . $item['opis'] . ' ' . $subjects[$item['oblast']]), $normalize($query)) !== false);
});
if ($missing) http_response_code(404);
function classroom_escape($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$title = $lesson ? $lesson['naslov'] . ' | Digitalna učionica' : ($missing ? 'Lekcija nije pronađena' : 'Digitalna učionica | EduZone');
$description = $lesson ? $lesson['opis'] : 'Lekcije iz informatike, web dizajna i web programiranja, uz primere i praktične zadatke.';
$canonical = 'https://eduzone.rs/digitalna-ucionica' . ($lesson ? '?lekcija=' . rawurlencode($slug) : '');
$imageUrl = 'https://eduzone.rs/img/eduZoneLogo.png';
$lastModified = date('c', filemtime(__DIR__ . '/content/digitalna-ucionica.php'));
$structuredData = $lesson ? [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'LearningResource',
            '@id' => $canonical . '#lekcija',
            'name' => $lesson['naslov'],
            'description' => $lesson['opis'],
            'url' => $canonical,
            'inLanguage' => 'sr',
            'learningResourceType' => 'Lesson',
            'educationalLevel' => 'Početni nivo',
            'timeRequired' => 'PT' . (int)$lesson['minuti'] . 'M',
            'dateModified' => $lastModified,
            'isPartOf' => ['@id' => 'https://eduzone.rs/digitalna-ucionica#ucionica'],
            'provider' => ['@type' => 'EducationalOrganization', 'name' => 'EduZone', 'url' => 'https://eduzone.rs/'],
        ],
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Početna', 'item' => 'https://eduzone.rs/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Digitalna učionica', 'item' => 'https://eduzone.rs/digitalna-ucionica'],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $lesson['naslov'], 'item' => $canonical],
            ],
        ],
    ],
] : [
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    '@id' => 'https://eduzone.rs/digitalna-ucionica#ucionica',
    'name' => 'Digitalna učionica | EduZone',
    'description' => $description,
    'url' => 'https://eduzone.rs/digitalna-ucionica',
    'inLanguage' => 'sr',
    'provider' => ['@type' => 'EducationalOrganization', 'name' => 'EduZone', 'url' => 'https://eduzone.rs/'],
];
?>
<!doctype html>
<html lang="sr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= classroom_escape($title) ?></title>
  <meta name="description" content="<?= classroom_escape($description) ?>">
  <?php if ($missing || $catalog): ?><meta name="robots" content="noindex, follow"><?php endif; ?>
  <link rel="canonical" href="<?= classroom_escape($canonical) ?>">
  <link rel="alternate" hreflang="sr" href="<?= classroom_escape($canonical) ?>">
  <meta property="og:title" content="<?= classroom_escape($title) ?>">
  <meta property="og:description" content="<?= classroom_escape($description) ?>">
  <meta property="og:url" content="<?= classroom_escape($canonical) ?>">
  <meta property="og:type" content="<?= $lesson ? 'article' : 'website' ?>">
  <meta property="og:site_name" content="EduZone">
  <meta property="og:locale" content="sr_RS">
  <meta property="og:image" content="<?= classroom_escape($imageUrl) ?>">
  <meta name="twitter:card" content="summary">
  <meta name="twitter:title" content="<?= classroom_escape($title) ?>">
  <meta name="twitter:description" content="<?= classroom_escape($description) ?>">
  <meta name="twitter:image" content="<?= classroom_escape($imageUrl) ?>">
  <script type="application/ld+json"><?= json_encode($structuredData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
  <link rel="icon" href="img/eduZoneLogo.png" type="image/png">
  <link rel="stylesheet" href="assets/css/site.css">
  <link rel="stylesheet" href="assets/css/digitalna-ucionica.css?v=<?= filemtime(__DIR__ . '/assets/css/digitalna-ucionica.css') ?>">
  <?php require_once __DIR__ . '/analytics.php'; ?>
</head>
<body class="classroom">
<a class="skip-link" href="#sadrzaj">Preskoči na sadržaj</a>
<header class="classroom-header">
  <nav aria-label="Glavna navigacija">
    <a href="./">Početna</a>
    <a href="korisni-materijali">IT kutak</a>
    <a href="radovi-ucenika">Radovi učenika</a>
    <a href="digitalna-ucionica" aria-current="page">Digitalna učionica</a>
  </nav>
</header>
<main id="sadrzaj" class="classroom-main">
<?php if ($missing): ?>
  <section class="classroom-hero"><p class="eyebrow">404 · Lekcija nije pronađena</p><h1>Ova lekcija nije dostupna.</h1><p>Izaberi neku od lekcija u Digitalnoj učionici.</p><a class="primary-link" href="digitalna-ucionica">Sve lekcije →</a></section>
<?php elseif ($lesson): ?>
  <a class="back-link" href="digitalna-ucionica?oblast=<?= classroom_escape($lesson['oblast']) ?>">← Sve lekcije · <?= classroom_escape($subjects[$lesson['oblast']]) ?></a>
  <article class="lesson-reader">
    <p class="eyebrow"><?= classroom_escape($subjects[$lesson['oblast']]) ?> / Početni nivo / <?= $lesson['minuti'] ?> min čitanja</p>
    <h1><?= classroom_escape($lesson['naslov']) ?></h1>
    <p class="lesson-intro"><?= classroom_escape($lesson['opis']) ?></p>
    <?php foreach ($lesson['delovi'] as $part): ?>
      <section class="lesson-section">
        <h2><?= classroom_escape($part[0]) ?></h2>
        <?php foreach ((array)$part[1] as $paragraph): ?><p><?= classroom_escape($paragraph) ?></p><?php endforeach; ?>
        <?php if (!empty($part[2])): ?>
          <ul class="lesson-points"><?php foreach ($part[2] as $point): ?><li><?= classroom_escape($point) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <?php if (!empty($part[3]['slika'])): ?>
          <figure class="lesson-figure"><img src="<?= classroom_escape($part[3]['slika']) ?>" alt="<?= classroom_escape($part[3]['alt'] ?? '') ?>" loading="lazy"><figcaption><?= classroom_escape($part[3]['alt'] ?? '') ?></figcaption></figure>
        <?php endif; ?>
        <?php if (!empty($part[4])): ?>
          <div class="lesson-resources"><?php foreach ($part[4] as $resource): ?><a href="<?= classroom_escape($resource['url']) ?>" target="_blank" rel="noopener noreferrer">▶ <?= classroom_escape($resource['tekst']) ?> <span aria-hidden="true">↗</span></a><?php endforeach; ?></div>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
    <?php if (isset($lesson['kod'])): ?><section><h2>Primer za vežbu</h2><pre><code><?= classroom_escape($lesson['kod']) ?></code></pre></section><?php endif; ?>
    <section class="practice"><p class="eyebrow">Sada ti</p><h2>Proveri šta si naučio</h2><p><?= classroom_escape($lesson['zadatak']) ?></p><details><summary>Pogledaj rešenje</summary><p><?= classroom_escape($lesson['resenje']) ?></p></details></section>
    <?php if (!empty($lesson['provera'])): ?>
      <section class="practice" aria-labelledby="provera-znanja">
        <h2 id="provera-znanja">Kratka provera znanja</h2>
        <p>Najpre odgovori samostalno, pa otvori objašnjenje.</p>
        <?php foreach ($lesson['provera'] as $index => $question): ?>
          <p><strong><?= $index + 1 ?>. <?= classroom_escape($question[0]) ?></strong></p>
          <details><summary>Prikaži odgovor na pitanje <?= $index + 1 ?></summary><p><?= classroom_escape($question[1]) ?></p></details>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>
    <?php $keys = array_keys($lessons); $next = $keys[array_search($slug, $keys, true) + 1] ?? null; ?>
    <div class="reader-actions"><a href="digitalna-ucionica?osvezi=<?= classroom_escape($contentVersion) ?>">← Nazad u učionicu</a><?php if ($next): ?><a class="primary-link" href="digitalna-ucionica?lekcija=<?= classroom_escape($next) ?>">Sledeća lekcija →</a><?php endif; ?></div>
  </article>
<?php else: ?>
  <?php if (!$catalog): ?>
  <section class="classroom-hero">
    <div><p class="eyebrow"><span class="status-dot"></span> Znanje dostupno svima</p><h1>Digitalna<br><span>učionica.</span></h1><p>Tvoj prostor za informatiku, web dizajn i programiranje. Kreni od osnova, isprobaj primere i uči svojim tempom.</p><a class="primary-link" href="#lekcije">Istraži lekcije <span aria-hidden="true">↗</span></a></div>
    <div class="hero-code" aria-hidden="true"><div class="code-window"><span>● ● ●</span> moja-prva-stranica.html</div><pre><span>&lt;učionica&gt;</span>
  ideja + znanje
  + malo radoznalosti

  <b>= tvoj sledeći projekat</b>
<span>&lt;/učionica&gt;</span></pre><div class="code-caption">Uči. Isprobaj. Napravi.</div></div>
  </section>
  <?php else: ?>
  <a class="back-link" href="digitalna-ucionica">← Digitalna učionica</a>
  <?php endif; ?>
  <section id="lekcije" class="catalog" aria-labelledby="catalog-title">
    <div class="catalog-heading"><div><p class="eyebrow">Korak po korak</p><h2 id="catalog-title">Šta želiš da naučiš?</h2></div></div>
    <form class="catalog-search" action="digitalna-ucionica" method="get" role="search">
      <input type="hidden" name="oblast" value="<?= classroom_escape($area ?: 'sve') ?>">
      <label for="lesson-search">Pretraži lekcije<?= isset($subjects[$area]) ? ' u oblasti ' . classroom_escape($subjects[$area]) : '' ?></label>
      <div><input id="lesson-search" name="q" value="<?= classroom_escape($query) ?>" type="search" placeholder="Npr. HTML, računar, CSS…"><button class="primary-link" type="submit">Pretraži</button></div>
    </form>
    <nav class="subject-grid" aria-label="Oblasti učenja">
      <?php $number = 0; foreach ($subjects as $key => $name): $number++; ?>
      <a class="subject-card" data-subject="<?= classroom_escape($key) ?>" href="digitalna-ucionica?<?= classroom_escape(http_build_query(['oblast' => $key, 'q' => $query])) ?>#rezultati" <?= $area === $key ? 'aria-current="true"' : '' ?>>
        <span class="subject-number">0<?= $number ?></span><h2><?= classroom_escape($name) ?></h2><p><?= classroom_escape($descriptions[$key]) ?></p><span class="card-action">Pogledaj lekcije <span aria-hidden="true">→</span></span>
      </a>
      <?php endforeach; ?>
    </nav>
    <section class="lesson-results" id="rezultati" <?= (isset($subjects[$area]) || $query !== '') ? '' : 'hidden' ?> aria-live="polite">
      <div class="results-heading"><h2 id="results-title"><?= isset($subjects[$area]) ? classroom_escape($subjects[$area]) : 'Rezultati pretrage' ?></h2><a id="clear-results" href="digitalna-ucionica#lekcije">Sve oblasti</a></div>
      <p class="search-count" id="search-count" role="status"><?= count($filtered) ? 'Broj lekcija: ' . count($filtered) : 'Nema lekcija za ovu pretragu. Pokušaj drugi pojam.' ?></p>
      <div class="lesson-grid">
        <?php foreach ($lessons as $id => $item): ?>
        <a class="lesson-card" data-subject="<?= classroom_escape($item['oblast']) ?>" data-search="<?= classroom_escape($subjects[$item['oblast']] . ' ' . $item['naslov'] . ' ' . $item['opis']) ?>" href="digitalna-ucionica?lekcija=<?= classroom_escape($id) ?>"><div class="card-meta"><span><?= classroom_escape($subjects[$item['oblast']]) ?></span><span><?= $item['minuti'] ?> min</span></div><h3><?= classroom_escape($item['naslov']) ?></h3><p><?= classroom_escape($item['opis']) ?></p><span class="card-action">Otvori lekciju <span aria-hidden="true">→</span></span></a>
        <?php endforeach; ?>
      </div>
    </section>
  </section>
<?php endif; ?>
 </main>
<footer class="classroom-footer"><a class="classroom-brand" href="./"><img src="img/eduZoneLogo.png" width="60" height="60" alt="EduZone — početna stranica"></a><p>Digitalna učionica · Znanje dostupno svima.</p><span>© <?= date('Y') ?> EduZone</span></footer>

<script>
(() => {
  const form = document.querySelector('.catalog-search');
  const input = document.getElementById('lesson-search');
  const results = document.getElementById('rezultati');
  if (!form || !input || !results) return;

  const subjectCards = [...document.querySelectorAll('.subject-card')];
  const lessonCards = [...document.querySelectorAll('.lesson-card')];
  const title = document.getElementById('results-title');
  const count = document.getElementById('search-count');
  const clear = document.getElementById('clear-results');
  const subjects = <?= json_encode($subjects, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  let activeSubject = <?= json_encode(isset($subjects[$area]) ? $area : '') ?>;
  const normalize = value => value.toLocaleLowerCase('sr').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'dj');

  function updateResults(scroll) {
    const query = normalize(input.value.trim());
    let visible = 0;
    lessonCards.forEach(card => {
      const show = (!activeSubject || card.dataset.subject === activeSubject) && (!query || normalize(card.dataset.search).includes(query));
      card.hidden = !show;
      if (show) visible++;
    });
    subjectCards.forEach(card => card.toggleAttribute('aria-current', card.dataset.subject === activeSubject));
    results.hidden = !activeSubject && !query;
    if (activeSubject) title.textContent = subjects[activeSubject];
    else title.textContent = 'Rezultati pretrage';
    count.textContent = visible ? 'Broj lekcija: ' + visible : 'Nema lekcija za ovu pretragu. Pokušaj drugi pojam.';
    if (scroll) results.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  subjectCards.forEach(card => card.addEventListener('click', event => {
    event.preventDefault();
    activeSubject = card.dataset.subject;
    const url = new URL(window.location);
    url.searchParams.set('oblast', activeSubject);
    if (input.value.trim()) url.searchParams.set('q', input.value.trim()); else url.searchParams.delete('q');
    history.replaceState(null, '', url.pathname + url.search + '#rezultati');
    updateResults(true);
  }));
  form.addEventListener('submit', event => { event.preventDefault(); updateResults(true); });
  input.addEventListener('input', () => updateResults(false));
  clear.addEventListener('click', event => { event.preventDefault(); activeSubject = ''; input.value = ''; history.replaceState(null, '', 'digitalna-ucionica#lekcije'); updateResults(false); document.getElementById('lekcije').scrollIntoView({ behavior: 'smooth' }); });
  updateResults(false);
})();
</script>
</body>
</html>
