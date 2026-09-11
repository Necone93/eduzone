<?php
require __DIR__ . '/db_connect.php';

header('Content-Type: application/xml; charset=UTF-8');

$baseUrl = 'https://eduzone.rs';
$today = date('Y-m-d');

function xml_escape(string $value): string {
  return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function sitemap_url(string $loc, string $lastmod, string $changefreq, string $priority): string {
  return "  <url>\n"
    . "    <loc>" . xml_escape($loc) . "</loc>\n"
    . "    <lastmod>" . xml_escape($lastmod) . "</lastmod>\n"
    . "    <changefreq>" . xml_escape($changefreq) . "</changefreq>\n"
    . "    <priority>" . xml_escape($priority) . "</priority>\n"
    . "  </url>\n";
}

$conn->set_charset('utf8mb4');
$urls = [];

$urls[] = sitemap_url($baseUrl . '/', $today, 'weekly', '1.0');
$urls[] = sitemap_url($baseUrl . '/korisni-materijali', $today, 'weekly', '0.8');
$urls[] = sitemap_url($baseUrl . '/radovi-ucenika', $today, 'weekly', '0.8');
$urls[] = sitemap_url($baseUrl . '/digitalna-ucionica', $today, 'weekly', '0.8');
foreach (require __DIR__ . '/content/digitalna-ucionica.php' as $slug => $lesson) {
  $urls[] = sitemap_url($baseUrl . '/digitalna-ucionica?lekcija=' . rawurlencode($slug), date('Y-m-d', filemtime(__DIR__ . '/content/digitalna-ucionica.php')), 'monthly', '0.7');
}

if ($result = @$conn->query("SELECT id, created_at FROM korisni_materijali ORDER BY created_at DESC, id DESC")) {
  while ($row = $result->fetch_assoc()) {
    $lastmod = !empty($row['created_at']) ? date('Y-m-d', strtotime($row['created_at'])) : $today;
    $urls[] = sitemap_url($baseUrl . '/korisni-materijali?id=' . (int)$row['id'], $lastmod, 'monthly', '0.7');
  }
  $result->free();
}

if ($result = @$conn->query("SELECT id, created_at FROM radovi_ucenika ORDER BY created_at DESC, id DESC")) {
  while ($row = $result->fetch_assoc()) {
    $lastmod = !empty($row['created_at']) ? date('Y-m-d', strtotime($row['created_at'])) : $today;
    $urls[] = sitemap_url($baseUrl . '/radovi-ucenika?id=' . (int)$row['id'], $lastmod, 'monthly', '0.7');
  }
  $result->free();
}

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
echo implode('', $urls);
echo "</urlset>\n";
