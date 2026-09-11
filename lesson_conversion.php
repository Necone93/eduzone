<?php
require_once __DIR__ . '/lesson_html.php';
require_once __DIR__ . '/lesson_python.php';

// Called only after the admin authorization/CSRF checks. No shell or remote fetches.
function ez_convert_lesson(string $pdf): array {
    $source = realpath(__DIR__ . '/' . ltrim($pdf, '/'));
    $root = realpath(__DIR__ . '/uploads/lekcije');
    if (!$source || !$root || strpos($source, $root . DIRECTORY_SEPARATOR) !== 0 || strtolower(pathinfo($source, PATHINFO_EXTENSION)) !== 'pdf') {
        return ['warning', 'HTML nije napravljen: PDF mora biti otpremljen u lekcije.'];
    }
    $existing = ez_lesson_html_load($pdf);
    if ($existing && ($existing['version'] ?? 0) >= 3) return ['success', 'HTML lekcija je spremna.'];
    $python = ez_lesson_python_path();
    if (!is_executable($python) || !function_exists('proc_open')) {
        return ['warning', 'PDF je dostupan. HTML konverter nije podešen na serveru (Python i PyMuPDF).'];
    }
    if (!is_writable(__DIR__ . '/content/lesson-html')) {
        return ['warning', 'PDF je dostupan. Server nema dozvolu da sačuva HTML lekciju.'];
    }
    $command = [$python, '-B', __DIR__ . '/scripts/convert-lesson.py', $source];
    // Intel XAMPP on Apple Silicon otherwise launches the universal Python under Rosetta.
    if (PHP_OS_FAMILY === 'Darwin' && !getenv('EDUZONE_PDF_PYTHON') && is_file(__DIR__ . '/.runtime/pdf-venv/arm64')) {
        $command = array_merge(['/usr/bin/arch', '-arm64'], $command);
    }
    $pipes = [];
    $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__);
    if (!is_resource($process)) return ['warning', 'PDF je dostupan. Pokretanje HTML konvertera nije uspelo.'];
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $started = microtime(true);
    $error = '';
    $timedOut = false;
    do {
        stream_get_contents($pipes[1]);
        $error = substr($error . stream_get_contents($pipes[2]), -4000);
        $state = proc_get_status($process);
        if (!$state['running']) break;
        if (microtime(true) - $started > 20) {
            $timedOut = true;
            proc_terminate($process, 9);
            break;
        }
        usleep(50000);
    } while (true);
    $error = substr($error . stream_get_contents($pipes[2]), -4000);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    clearstatcache();
    $converted = ez_lesson_html_load($pdf);
    if (!$timedOut && $converted && ($converted['version'] ?? 0) >= 3) return ['success', 'HTML lekcija je spremna.'];
    if ($error !== '') error_log('EduZone PDF conversion: ' . $error);
    if ($timedOut) return ['warning', 'PDF je dostupan. HTML obrada je trajala duže od 20 sekundi. Pokušaj sa manjim dokumentom.'];
    if (strpos($error, 'Password-protected') !== false) return ['warning', 'PDF je dostupan. Za HTML otpremi dokument bez lozinke.'];
    if (strpos($error, 'Maximum 150') !== false) return ['warning', 'PDF je dostupan. HTML konverzija podržava najviše 150 strana.'];
    if (strpos($error, 'HTML output exceeds') !== false) return ['warning', 'PDF je dostupan. HTML sa slikama je prevelik; podeli dokument na kraće lekcije.'];
    return ['warning', 'PDF je dostupan, ali HTML konverzija nije uspela. Proveri PDF ili podešavanje konvertera, pa klikni „Pripremi HTML“.'];
}
