<?php
// Shared by the admin converter and the hosting diagnostic.
function ez_lesson_python_path(): string {
    $configured = getenv('EDUZONE_PDF_PYTHON');
    if ($configured) return $configured;
    $local = __DIR__ . '/.runtime/pdf-venv/bin/python';
    if (is_executable($local)) return $local;
    // cPanel app eduzone_pdf, Python 3.11; public_html is inside the account home.
    $hosting = dirname(__DIR__) . '/virtualenv/eduzone_pdf/3.11/bin/python';
    return $hosting;
}
