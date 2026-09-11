<?php
session_start();
require_once __DIR__ . '/admin_config.php';
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
if (!ez_is_admin()) {
    http_response_code(403);
    exit('Pristup je dozvoljen samo administratoru. Prijavi se svojim administratorskim nalogom.');
}
require_once __DIR__ . '/lesson_python.php';
if (empty($_SESSION['hosting_check_csrf'])) $_SESSION['hosting_check_csrf'] = bin2hex(random_bytes(32));
function hc_escape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function hc_probe(array $command): ?array {
    $pipes = [];
    $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__);
    if (!is_resource($process)) return null;
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $output = '';
    $started = microtime(true);
    do {
        $output = substr($output . stream_get_contents($pipes[1]), -8000);
        stream_get_contents($pipes[2]);
        $state = proc_get_status($process);
        if (!$state['running']) break;
        if (microtime(true) - $started > 3) { proc_terminate($process, 9); break; }
        usleep(50000);
    } while (true);
    $output = substr($output . stream_get_contents($pipes[1]), -8000);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
    $result = json_decode($output, true);
    return is_array($result) && isset($result['python']) ? $result : null;
}
$results = [];
$add = function ($name, $ok, $detail) use (&$results) { $results[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail]; };
$ran = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($ran) {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['hosting_check_csrf'], $_POST['csrf'])) {
        http_response_code(403); exit('Osveži stranicu i ponovo pokreni proveru.');
    }
    $add('PHP', version_compare(PHP_VERSION, '8.0', '>='), PHP_VERSION . ' (potreban PHP 8.0 ili noviji za postojeći kod sajta)');
    $canRun = true;
    foreach (['proc_open', 'proc_get_status', 'proc_terminate', 'proc_close'] as $function) {
        if (!function_exists($function)) $canRun = false;
    }
    $add('Pokretanje konvertera iz PHP-a', $canRun, $canRun ? 'Potrebne proc_* funkcije su dostupne; rezultat Python probe potvrđuje izvršavanje.' : 'Hosting je isključio jednu ili više potrebnih proc_* funkcija. Obrati se podršci hostinga.');
    $filesReady = true;
    foreach (['lesson_conversion.php', 'lesson_html.php', 'lesson_python.php', 'scripts/convert-lesson.py', 'scripts/requirements-pdf.txt'] as $file) {
        $present = is_readable(__DIR__ . '/' . $file);
        $filesReady = $filesReady && $present;
        $add($file, $present, $present ? 'Fajl je dostupan.' : 'Prenesi ovaj fajl u isti folder kao u projektu.');
    }
    $cacheDir = __DIR__ . '/content/lesson-html';
    $written = false;
    if (is_dir($cacheDir) && is_writable($cacheDir)) {
        $probeFile = $cacheDir . '/hosting-check-' . bin2hex(random_bytes(12)) . '.tmp';
        $written = @file_put_contents($probeFile, 'EduZone probe', LOCK_EX) !== false;
        if ($written) { $written = @file_get_contents($probeFile) === 'EduZone probe'; @unlink($probeFile); }
    }
    $add('Čuvanje HTML lekcija', $written, $written ? 'PHP može da upiše i pročita probni fajl u content/lesson-html.' : 'Napravi content/lesson-html i omogući pisanje PHP korisniku.');
    $protected = is_readable($cacheDir . '/.htaccess') && strpos(file_get_contents($cacheDir . '/.htaccess'), 'Require all denied') !== false;
    $add('Zaštita foldera konverzija', $protected, $protected ? '.htaccess je prisutan. Proveri da hosting primenjuje Apache pravila; ova proba ne potvrđuje HTTP zabranu.' : 'Prenesi content/lesson-html/.htaccess. Na drugom web serveru traži ekvivalentnu zabranu pristupa.');
    $configured = ez_lesson_python_path();
    $pythonReady = false;
    $code = <<<'PY'
import sys, json
result = {'python': sys.version.split()[0], 'compatible': sys.version_info >= (3, 9), 'library': None, 'render': False}
try:
    import pymupdf
    result['library'] = pymupdf.VersionBind
    doc = pymupdf.open()
    page = doc.new_page(width=100, height=100)
    page.insert_text((10, 20), 'Test')
    result['render'] = bool(page.get_text('rawdict')['blocks']) and bool(page.get_pixmap().tobytes('png'))
    doc.close()
except Exception as exc:
    result['error'] = type(exc).__name__
print(json.dumps(result))
PY;
    if ($canRun) {
        $candidates = array_values(array_unique([$configured, '/usr/bin/python3', '/usr/local/bin/python3', 'python3']));
        foreach ($candidates as $index => $python) {
            $command = [$python, '-B', '-c', $code];
            if ($index === 0 && PHP_OS_FAMILY === 'Darwin' && !getenv('EDUZONE_PDF_PYTHON') && is_file(__DIR__ . '/.runtime/pdf-venv/arm64')) {
                $command = array_merge(['/usr/bin/arch', '-arm64'], $command);
            }
            $probe = hc_probe($command);
            $ok = $probe && !empty($probe['compatible']) && !empty($probe['render']);
            if ($index === 0) $pythonReady = $ok;
            $detail = !$probe ? 'Python nije pronađen ili nije mogao da se pokrene.' : 'Python ' . $probe['python'] . '; PyMuPDF: ' . ($probe['library'] ?? 'nije dostupan') . '; probna obrada: ' . (!empty($probe['render']) ? 'uspešna' : 'neuspešna');
            if ($index !== 0 && $ok) $detail .= '. Dostupna alternativa — putanju treba podesiti kroz EDUZONE_PDF_PYTHON pre automatskog uvoza.';
            $add(($index === 0 ? 'Podešeni Python: ' : 'Alternativni Python: ') . $python, (bool)$ok, $detail);
            if ($index === 0 && $ok) break;
        }
    }
    $ready = version_compare(PHP_VERSION, '8.0', '>=') && $canRun && $filesReady && $written && $pythonReady;
    $summary = $ready ? 'Osnovni uslovi za konverziju su ispunjeni. Završna provera: dodaj jednu PDF lekciju kroz admin i proveri HTML prikaz.' : 'Automatska konverzija još nije spremna. Pogledaj stavke označene sa PROVERITI.';
}
?>
<!doctype html>
<html lang="sr">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Provera hostinga za HTML lekcije</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#080b12;color:#eef2f8;font:16px/1.6 system-ui,sans-serif}main{max-width:950px;margin:40px auto;padding:0 20px}h1{font-size:30px}a{color:#ffd600}button{background:#ffd600;color:#111;border:0;border-radius:8px;padding:13px 20px;font:700 16px system-ui;cursor:pointer}button:disabled{opacity:.65}table{border-collapse:collapse;width:100%;margin:24px 0}td,th{padding:14px;text-align:left;border-bottom:1px solid #303746;overflow-wrap:anywhere}.table-wrap{overflow-x:auto}.ok{color:#72dfb1}.warning{color:#ffd600}.summary{padding:20px;background:#111620;border:1px solid #394052;border-radius:12px}textarea{width:100%;min-height:260px;background:#111620;color:#eef2f8;border:1px solid #485266;border-radius:8px;padding:14px}small{color:#a6b0c2}
</style>
</head>
<body><main>
<a href="lekcije_admin.php">← Admin lekcija</a>
<h1>Da li hosting podržava PDF → HTML?</h1>
<p>Provera ne koristi bazu niti menja lekcije. Kratko pokreće Python i upisuje privremeni fajl koji zatim briše.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= hc_escape($_SESSION['hosting_check_csrf']) ?>"><button type="submit">Pokreni proveru</button></form>
<?php if ($ran): ?>
<p class="summary"><?= hc_escape($summary) ?></p>
<div class="table-wrap"><table><thead><tr><th>Provera</th><th>Rezultat</th></tr></thead><tbody>
<?php foreach ($results as $result): ?><tr><td><?= hc_escape($result['name']) ?></td><td><strong class="<?= $result['ok'] ? 'ok' : 'warning' ?>"><?= $result['ok'] ? 'OK' : 'PROVERITI' ?></strong><br><?= hc_escape($result['detail']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<p><small>PHP ograničenja: upload_max_filesize = <?= hc_escape(ini_get('upload_max_filesize')) ?>; post_max_size = <?= hc_escape(ini_get('post_max_size')) ?>; memory_limit = <?= hc_escape(ini_get('memory_limit')) ?>; max_execution_time = <?= hc_escape(ini_get('max_execution_time')) ?> s. Hosting može imati i dodatna ograničenja.</small></p>
<label for="report">Kopiraj ovaj rezultat i pošalji ga za sledeći korak:</label>
<textarea id="report" readonly><?= hc_escape($summary . "\n\n" . implode("\n", array_map(function ($r) { return ($r['ok'] ? 'OK' : 'PROVERITI') . ' | ' . $r['name'] . ' | ' . $r['detail']; }, $results))) ?></textarea>
<?php endif; ?>
</main><script>document.querySelector('form').addEventListener('submit',function(){const button=this.querySelector('button');button.disabled=true;button.textContent='Provera je u toku…';});</script></body></html>
