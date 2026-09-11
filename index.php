<!DOCTYPE html>
<html lang="sr">
<head>
<?php require_once __DIR__ . '/analytics.php'; ?>
  <meta charset="UTF-8">
  <title>EduZone | Platforma za učenje</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="EduZone je platforma za učenje koja povezuje lekcije, testove, praktične zadatke i obrazovne materijale za učenike i nastavnike.">
  <meta name="robots" content="index, follow">
  <meta name="author" content="EduZone">
  <link rel="canonical" href="https://eduzone.rs/">
  <meta property="og:type" content="website">
  <meta property="og:locale" content="sr_RS">
  <meta property="og:site_name" content="EduZone">
  <meta property="og:title" content="EduZone | Platforma za učenje">
  <meta property="og:description" content="EduZone je platforma za učenje koja povezuje lekcije, testove, praktične zadatke i obrazovne materijale za učenike i nastavnike.">
  <meta property="og:url" content="https://eduzone.rs/">
  <meta property="og:image" content="https://eduzone.rs/img/eduZoneLogo.png">
  <meta name="twitter:card" content="summary">
  <meta name="twitter:title" content="EduZone | Platforma za učenje">
  <meta name="twitter:description" content="EduZone je platforma za učenje koja povezuje lekcije, testove, praktične zadatke i obrazovne materijale za učenike i nastavnike.">
  <meta name="twitter:image" content="https://eduzone.rs/img/eduZoneLogo.png">
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@graph": [
      {
        "@type": "WebSite",
        "@id": "https://eduzone.rs/#website",
        "url": "https://eduzone.rs/",
        "name": "EduZone",
        "alternateName": "eduzone.rs",
        "description": "EduZone je platforma za učenje koja povezuje lekcije, testove, praktične zadatke i obrazovne materijale za učenike i nastavnike.",
        "inLanguage": "sr"
      }
    ]
  }
  </script>
  
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/site.css">
  <link rel="icon" type="image/png" sizes="1024x1024" href="/img/eduZoneLogo.png">
  <link rel="apple-touch-icon" href="/img/eduZoneLogo.png">
  
  <style>
    body.landing-page {
      overflow: hidden;
      height: 100vh;
    }

    .hero-nav {
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      padding: 1.25rem 1.5rem;
      z-index: 20;
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 1.75rem;
    }

    .btn-prijava {
      background: var(--ez-accent);
      color: #000 !important;
      border: none;
      padding: 0.55rem 1.35rem;
      border-radius: 8px;
      font-weight: 700;
      text-decoration: none !important;
      font-size: 0.9rem;
      transition: background 0.25s, transform 0.2s;
      display: inline-block;
    }

    .btn-prijava:hover {
      background: #ffed4e;
      color: #000 !important;
      transform: translateY(-1px);
    }

    .nav-menu {
      display: flex;
      gap: 1.75rem;
      align-items: center;
    }

    .nav-menu a {
      color: var(--ez-text);
      text-decoration: none;
      font-weight: 600;
      font-size: 0.95rem;
      transition: color 0.3s;
    }

    .nav-menu a:hover {
      color: var(--ez-accent);
    }

    .hero-section {
      position: relative;
      height: 100vh;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      background: radial-gradient(circle at top right, rgba(255, 214, 66, 0.15), transparent 30%),
                  radial-gradient(circle at bottom left, rgba(255, 214, 66, 0.08), transparent 35%);
      overflow: hidden;
    }

    .hero-content {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
      position: relative;
      z-index: 10;
      padding: 5rem 1.5rem 2rem;
    }

    #matrix-canvas {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      opacity: 0.14;
      pointer-events: none;
    }

    .hero-title {
      font-size: clamp(2rem, 4vw, 2.8rem);
      font-weight: 800;
      line-height: 1.1;
      margin-bottom: 0;
      color: var(--ez-text);
    }

    .bee-writer {
      position: relative;
      width: min(760px, 92vw);
      height: clamp(130px, 22vw, 210px);
      margin-bottom: 1rem;
      display: grid;
      place-items: center;
    }

    .bee-word {
      width: 100%;
      height: 100%;
      overflow: visible;
      filter: drop-shadow(0 12px 26px rgba(0, 0, 0, .34));
    }

    .bee-word-shadow {
      fill: none;
      stroke: rgba(255, 214, 0, .22);
      stroke-width: 19;
      stroke-linecap: round;
      stroke-linejoin: round;
    }

    .bee-word-trail {
      fill: none;
      stroke: var(--ez-accent);
      stroke-width: 13;
      stroke-linecap: round;
      stroke-linejoin: round;
      filter: drop-shadow(0 0 16px rgba(255, 214, 0, .45));
      opacity: 1;
    }

    .blog-section {
      padding: 4rem 0;
    }

    .blog-card {
      background: linear-gradient(180deg, var(--ez-surface), #131829 98%);
      border: 1px solid var(--ez-border);
      border-radius: 12px;
      padding: 2rem;
      margin-bottom: 2rem;
      transition: transform 0.3s ease, border-color 0.3s ease;
      display: flex;
      gap: 1.5rem;
    }

    .blog-card:hover {
      transform: translateY(-2px);
      border-color: var(--ez-accent);
    }

    .blog-icon {
      font-size: 2.5rem;
      min-width: 60px;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .blog-content {
      flex: 1;
    }

    .blog-content h3 {
      color: var(--ez-text);
      margin-bottom: 0.5rem;
      font-weight: 700;
    }

    .blog-content p {
      color: var(--ez-muted);
      margin-bottom: 1rem;
      font-size: 0.95rem;
    }

    .blog-content .btn {
      border-radius: 8px;
      font-weight: 600;
    }

    .public-footer {
      position: relative;
      z-index: 10;
      border-top: 1px solid var(--ez-border);
      padding: 1.25rem 1.5rem 1.5rem;
      color: var(--ez-muted);
      font-size: 0.9rem;
      text-align: center;
      margin-top: auto;
    }

    .public-footer a {
      color: var(--ez-muted);
      text-decoration: none;
    }

    .public-footer a:hover {
      text-decoration: underline;
    }

    .designeca-logo {
      height: 18px;
      width: auto;
      vertical-align: middle;
      opacity: 0.78;
    }

    @media (prefers-reduced-motion: reduce) {
      .bee-word-trail {
        stroke-dasharray: none !important;
        stroke-dashoffset: 0 !important;
      }
    }
    @media (max-width: 600px) {
      .hero-nav { padding: 1rem; gap: 1rem; flex-wrap: wrap; justify-content: center; }
      .nav-menu { gap: 1rem; flex-wrap: wrap; justify-content: center; }
      .nav-menu a { font-size: .8rem; }
      body.landing-page { overflow: auto; height: auto; }
      .hero-section { height: auto; min-height: 100svh; }
      .hero-content { padding-top: 9rem; }
    }
  </style>
</head>
<body class="landing-page">

<!-- Hero Section (Full Landing Page) -->
<section class="hero-section">
  <canvas id="matrix-canvas"></canvas>

  <nav class="hero-nav">
    <div class="nav-menu">
      <a href="korisni-materijali">IT kutak</a>
      <a href="radovi-ucenika">Radovi učenika</a>
      <a href="digitalna-ucionica">Digitalna učionica</a>
    </div>
    <a href="login" class="btn-prijava">Prijava</a>
  </nav>

  <div class="hero-content">
    <div class="bee-writer" aria-label="EduZone">
      <svg class="bee-word" viewBox="0 0 900 230" role="img" aria-hidden="true">
        <path class="bee-word-shadow" d="M70 65 H205 M70 65 V165 H210 M70 115 H180 M330 48 V165 M330 142 C290 185 225 158 245 106 C260 68 318 76 330 120 M360 88 V138 C360 178 420 176 420 130 V88 M450 165 L565 72 H465 M455 165 H575 M620 72 C690 72 710 165 638 165 C570 165 550 72 620 72 M760 165 V108 C760 70 675 75 675 130 V165 M805 110 H870 C865 70 790 68 780 120 C770 172 840 180 872 145" />
        <path id="edu-flight-path" class="bee-word-trail" d="M70 65 H205 M70 65 V165 H210 M70 115 H180 M330 48 V165 M330 142 C290 185 225 158 245 106 C260 68 318 76 330 120 M360 88 V138 C360 178 420 176 420 130 V88 M450 165 L565 72 H465 M455 165 H575 M620 72 C690 72 710 165 638 165 C570 165 550 72 620 72 M760 165 V108 C760 70 675 75 675 130 V165 M805 110 H870 C865 70 790 68 780 120 C770 172 840 180 872 145" />
      </svg>
    </div>
    <h1 class="hero-title">EduZone — platforma za učenje</h1>
    <p style="color: var(--ez-muted); font-size: 1.1rem; margin-top: 1rem;">Lekcije, testovi i praktični zadaci na jednom mestu.</p>
    <p style="color: var(--ez-muted); font-size: 1.1rem; margin-top: 1rem;">Gimnazija i srednja stručna škola "Branko Radičević", Kovin</p>
  </div>

  <footer class="public-footer">
    <p style="margin-bottom: 0.5rem;">&copy; <?= date('Y') ?> EduZone</p>
  </footer>
</section>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Matrix animation
  (function () {
    const canvas = document.getElementById('matrix-canvas');
    if (!canvas) return;

    const ctx = canvas.getContext('2d');
    const chars = '01アイウエオカキクケコサシスセソタチツテトナニヌネノハヒフヘホマミムメモヤユヨラリルレロワヲン';
    const charArray = chars.split('');
    const fontSize = 14;
    let columns = 0;
    let drops = [];
    let speeds = [];
    let animationId = null;

    function resizeCanvas() {
      const dpr = window.devicePixelRatio || 1;
      const rect = canvas.getBoundingClientRect();
      canvas.width = rect.width * dpr;
      canvas.height = rect.height * dpr;
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

      columns = Math.ceil(rect.width / fontSize);
      drops = Array.from({ length: columns }, () => Math.random() * rect.height / fontSize);
      speeds = Array.from({ length: columns }, () => 0.4 + Math.random() * 0.8);
    }

    function draw() {
      const w = canvas.getBoundingClientRect().width;
      const h = canvas.getBoundingClientRect().height;

      ctx.fillStyle = 'rgba(8, 11, 18, 0.08)';
      ctx.fillRect(0, 0, w, h);

      ctx.font = fontSize + 'px "SF Mono", "Fira Code", monospace';

      for (let i = 0; i < columns; i++) {
        const x = i * fontSize;
        const y = drops[i] * fontSize;
        const char = charArray[Math.floor(Math.random() * charArray.length)];

        ctx.shadowBlur = 6;
        ctx.shadowColor = 'rgba(0, 255, 120, 0.6)';
        ctx.fillStyle = '#b8ffc8';
        ctx.fillText(char, x, y);

        ctx.shadowBlur = 0;
        ctx.fillStyle = 'rgba(0, 180, 80, 0.35)';
        ctx.fillText(charArray[Math.floor(Math.random() * charArray.length)], x, y - fontSize);

        if (y > h && Math.random() > 0.985) {
          drops[i] = 0;
          speeds[i] = 0.4 + Math.random() * 0.8;
        }

        drops[i] += speeds[i];
      }

      animationId = requestAnimationFrame(draw);
    }

    resizeCanvas();
    draw();

    window.addEventListener('resize', () => {
      cancelAnimationFrame(animationId);
      resizeCanvas();
      draw();
    });
  })();

  // Animated EduZone wordmark
  (function () {
    const path = document.getElementById('edu-flight-path');
    if (!path) return;

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const totalLength = path.getTotalLength();
    path.style.strokeDasharray = totalLength;
    path.style.strokeDashoffset = totalLength;

    if (reducedMotion) {
      path.style.strokeDashoffset = 0;
      return;
    }

    let startTime = null;
    const duration = 9200;
    const pause = 1100;

    function animate(timestamp) {
      if (!startTime) startTime = timestamp;
      const elapsed = (timestamp - startTime) % (duration + pause);
      const progress = elapsed > duration ? 1 : elapsed / duration;

      path.style.strokeDashoffset = totalLength * (1 - progress);

      requestAnimationFrame(animate);
    }

    requestAnimationFrame(animate);
  })();

  // Smooth scroll for anchor links
  document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
      const href = this.getAttribute('href');
      if (href !== '#') {
        e.preventDefault();
        const target = document.querySelector(href);
        if (target) {
          target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      }
    });
  });
</script>
</body>
</html>
