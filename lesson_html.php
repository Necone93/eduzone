<?php
// The caller is responsible for lesson access checks; this helper exposes no endpoint.
function ez_lesson_html_load(string $pdf): ?array {
    if (preg_match('~^[a-z][a-z0-9+.-]*:|^//~i', $pdf)) return null;
    $source = realpath(__DIR__ . '/' . ltrim($pdf, '/'));
    $root = realpath(__DIR__ . '/uploads/lekcije');
    if (!$source || !$root || strpos($source, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($source)) return null;
    $hash = hash_file('sha256', $source);
    $cache = __DIR__ . '/content/lesson-html/' . $hash . '.json';
    if (!is_file($cache)) return null;
    if (!is_readable($cache)) {
        error_log('EduZone: HTML lesson cache is not readable: ' . basename($cache));
        return null;
    }
    $contents = file_get_contents($cache);
    if ($contents === false) return null;
    $data = json_decode($contents, true);
    return is_array($data) && in_array($data['version'] ?? null, [1, 2, 3], true) && ($data['sha256'] ?? '') === $hash && !empty($data['pages']) ? $data : null;
}
function ez_lesson_html_escape(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function ez_lesson_html_image(string $png, string $alt, string $class = ''): string {
    if (!preg_match('~^[A-Za-z0-9+/=]+$~D', $png)) return '';
    return '<img class="' . ez_lesson_html_escape($class) . '" src="data:image/png;base64,' . $png . '" alt="' . ez_lesson_html_escape($alt) . '" loading="lazy">';
}
function ez_lesson_html_url($url): ?string {
    if (!is_string($url) || preg_match('~[\\x00-\\x20\\x7f\\\\]~', $url)) return null;
    $parts = parse_url($url);
    return is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) && !empty($parts['host']) ? $url : null;
}
function ez_lesson_html_link(string $html, $url): string {
    $safe = ez_lesson_html_url($url);
    return $safe ? '<a href="' . ez_lesson_html_escape($safe) . '" target="_blank" rel="noopener noreferrer" title="Otvara se u novoj kartici">' . $html . '</a>' : $html;
}
function ez_lesson_html_linked_image(string $png, string $alt, array $links): string {
    $html = '<span class="lesson-linked-image">' . ez_lesson_html_image($png, $alt);
    foreach ($links as $link) {
        $safe = ez_lesson_html_url($link['href'] ?? null);
        if (!$safe) continue;
        $left = max(0, min(100, (float)($link['left'] ?? 0)));
        $top = max(0, min(100, (float)($link['top'] ?? 0)));
        $width = max(0, min(100 - $left, (float)($link['width'] ?? 0)));
        $height = max(0, min(100 - $top, (float)($link['height'] ?? 0)));
        if (!$width || !$height) continue;
        $style = sprintf('left:%.4F%%;top:%.4F%%;width:%.4F%%;height:%.4F%%', $left, $top, $width, $height);
        $label = ez_lesson_html_escape('Otvori ' . $safe . ' (nova kartica)');
        $html .= '<a class="lesson-image-link" href="' . ez_lesson_html_escape($safe) . '" target="_blank" rel="noopener noreferrer" aria-label="' . $label . '" title="' . $label . '" style="' . $style . '"></a>';
    }
    return $html . '</span>';
}
function ez_lesson_html_render(array $data): void {
    echo '<article class="converted-lesson" aria-label="Sadržaj lekcije">';
    foreach ($data['pages'] as $page) {
        $number = (int)$page['number'];
        echo '<section class="converted-page" id="strana-' . $number . '"><div class="page-label">Strana ' . $number . ' / ' . count($data['pages']) . '</div>';
        if (!$page['has_text']) {
            echo '<p>Ova stranica je skenirana i prikazana je kao slika.</p>';
            echo ez_lesson_html_linked_image($page['original'], 'Skenirana stranica ' . $number, $page['links'] ?? []);
        } else {
            foreach ($page['items'] as $item) {
                if ($item['type'] === 'table') {
                    echo '<figure class="converted-table"><figcaption>Tabela iz lekcije</figcaption>'
                        . ez_lesson_html_linked_image($item['png'], 'Tabela iz lekcije, strana ' . $number, $item['links'] ?? [])
                        . '</figure>';
                    continue;
                }
                if ($item['type'] === 'image') {
                    echo '<figure>' . ez_lesson_html_linked_image($item['png'], 'Ilustracija iz lekcije, strana ' . $number, $item['links'] ?? []) . '</figure>';
                    continue;
                }
                $tag = $item['type'] === 'heading' ? 'h2' : ($item['type'] === 'code' ? 'pre' : 'p');
                echo '<' . $tag . '>' . ($tag === 'pre' ? '<code>' : '');
                foreach ($item['lines'] as $index => $line) {
                    if ($index > 0) echo $tag === 'pre' ? "\n" : ' ';
                    foreach ($line as $span) {
                        $text = ez_lesson_html_escape($span['text']);
                        if ($span['bold']) $text = '<strong>' . $text . '</strong>';
                        if ($span['italic']) $text = '<em>' . $text . '</em>';
                        echo ez_lesson_html_link($text, $span['href'] ?? null);
                    }
                }
                echo ($tag === 'pre' ? '</code>' : '') . '</' . $tag . '>';
            }
            echo '<details class="original-page"><summary>Uporedi sa originalnom stranicom ' . $number . '</summary>' . ez_lesson_html_linked_image($page['original'], 'Originalna stranica ' . $number, $page['links'] ?? []) . '</details>';
        }
        if (!empty($page['extra_links']) && $page['has_text']) {
            echo '<nav class="lesson-extra-links" aria-label="Linkovi sa strane ' . $number . '"><p>Linkovi sa ove strane:</p><ul>';
            foreach ($page['extra_links'] as $url) {
                if (ez_lesson_html_url($url)) echo '<li>' . ez_lesson_html_link(ez_lesson_html_escape($url), $url) . '</li>';
            }
            echo '</ul></nav>';
        }
        echo '</section>';
    }
    echo '</article>';
}
