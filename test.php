<?php
session_start();
if (empty($_SESSION['email'])) { header('Location: index.php'); exit; }
$email  = $_SESSION['email'];
$testId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$assignKey = isset($_GET['ak']) ? trim((string)$_GET['ak']) : '';
if ($testId <= 0) { exit('Nedostaje ID testa.'); }

require __DIR__ . '/db_connect.php';

/* === EDUZONE PATCH: helper za bezbednu kompatibilnost sa starom šemom === */
function kolona_postoji(mysqli $conn, string $tabela, string $kolona): bool {
  $tbl = $conn->real_escape_string($tabela);
  $col = $conn->real_escape_string($kolona);
  $rs  = $conn->query("SHOW COLUMNS FROM `$tbl` LIKE '$col'");
  $ok  = $rs && $rs->num_rows > 0;
  if ($rs) $rs->free();
  return $ok;
}

function table_exists(mysqli $c, string $t): bool {
  $r = $c->query("SHOW TABLES LIKE '".$c->real_escape_string($t)."'");
  $ok = $r && $r->num_rows>0; if($r) $r->free(); return $ok;
}

/* === meta testa ===
   Uzimamo broj_pitanja ako kolona postoji; inače radi fallback na 10. */
$ima_broj = kolona_postoji($conn, 'testovi', 'broj_pitanja');

if ($ima_broj) {
  $t = $conn->prepare("SELECT id, naslov, trajanje, broj_pitanja FROM testovi WHERE id=?");
} else {
  $t = $conn->prepare("SELECT id, naslov, trajanje FROM testovi WHERE id=?");
}
$t->bind_param("i",$testId); 
$t->execute();
$test = $t->get_result()->fetch_assoc(); 
$t->close();

if (!$test) { exit('Test ne postoji.'); }

$trajanjeMin = (int)($test['trajanje'] ?? 0);
/* === EDUZONE PATCH: broj pitanja iz baze ili 10 kao fallback === */
$N = $ima_broj ? (int)($test['broj_pitanja'] ?? 10) : 10;
if ($N < 1) $N = 10;

/* === izvuci N nasumičnih umesto fiksnih 10 === */
$q = $conn->prepare("SELECT id, tekst, tip FROM pitanja WHERE test_id=? ORDER BY RAND() LIMIT ?");
$q->bind_param("ii",$testId, $N); 
$q->execute();
$pitanja = $q->get_result();
?>
<!doctype html>
<html lang="sr">
<head>
<?php require_once __DIR__ . '/analytics.php'; ?>
<meta charset="utf-8">
<title><?= htmlspecialchars($test['naslov']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  .timer{position:sticky;top:0}.timer .badge{font-size:1rem}
  /* === EDUZONE PATCH: spreči selekciju teksta (mali anti-cheat) === */
  .anti-select { user-select: none; }
</style>
</head>
<body class="bg-light p-4 anti-select">
<div class="container">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">🧪 <?= htmlspecialchars($test['naslov']) ?></h2>
    <?php if ($trajanjeMin>0): ?>
      <div class="timer"><span class="badge bg-danger" id="tm"><?= (int)$trajanjeMin ?>:00</span></div>
    <?php endif; ?>
  </div>

  <form method="POST" action="zavrsi_test" id="test-form">
    <input type="hidden" name="test_id" value="<?= (int)$testId ?>">
    <input type="hidden" name="assign_key" value="<?= htmlspecialchars($assignKey, ENT_QUOTES, 'UTF-8') ?>">
    <?php
    $br=1;
    while($p=$pitanja->fetch_assoc()):
      $pid=(int)$p['id'];
      $tip=strtolower(trim($p['tip'])); // radio|checkbox|text
    ?>
      <input type="hidden" name="asked[]" value="<?= $pid ?>">
      <div class="mb-4">
        <strong><?= $br++.'. '.htmlspecialchars($p['tekst']) ?></strong>

        <?php if ($tip==='text'): ?>
          <textarea name="odgovor_text[<?= $pid ?>]" class="form-control mt-2" rows="3" placeholder="Upiši odgovor..."></textarea>
        <?php else:
          $htmlType = ($tip==='checkbox')?'checkbox':'radio';
          $ans = $conn->query("SELECT id, tekst FROM odgovori WHERE pitanje_id={$pid} ORDER BY id ASC");
          while($a=$ans->fetch_assoc()):
            $aid=(int)$a['id']; ?>
            <div class="form-check">
              <input class="form-check-input" type="<?= $htmlType ?>"
                name="<?= $htmlType==='checkbox' ? "odgovor_multi[$pid][]" : "odgovor[$pid]" ?>"
                value="<?= $aid ?>">
              <label class="form-check-label"><?= htmlspecialchars($a['tekst']) ?></label>
            </div>
          <?php endwhile; ?>
        <?php endif; ?>
      </div>
    <?php endwhile; ?>
    <button type="submit" class="btn btn-success">📤 Pošalji test</button>
  </form>
</div>

<script>
  (function(){
    const form = document.getElementById('test-form');
    let submitted = false;
    function submitOnce(){ if(!submitted){ submitted=true; form.submit(); } }

    /* === Anti-cheat (postojeće) === */
    window.addEventListener('blur', submitOnce);
    document.addEventListener('visibilitychange', ()=>{ if(document.hidden) submitOnce(); });
    window.addEventListener('pagehide', submitOnce);
    form.addEventListener('submit', ()=>{ submitted=true; });

    /* === EDUZONE PATCH: Fullscreen lock — izlazak iz fullscreen-a prekida test === */
    async function goFull() {
      try { if (!document.fullscreenElement) { await document.documentElement.requestFullscreen(); } }
      catch(e){}
    }
    goFull();
    document.addEventListener('fullscreenchange', ()=>{ if(!document.fullscreenElement) submitOnce(); });

    /* === EDUZONE PATCH: Blokiraj desni klik i većinu Ctrl-kratica === */
    document.addEventListener('contextmenu', e => e.preventDefault());
    document.addEventListener('keydown', (e)=>{
      const k = (e.key || '').toLowerCase();
      if ((e.ctrlKey && ['p','s','c','x','v','a','l','n','t','w'].includes(k)) || e.key === 'PrintScreen') {
        e.preventDefault(); e.stopPropagation();
      }
    });

    /* === Tajmer ostaje kao i ranije === */
    const mins = <?= (int)$trajanjeMin ?>;
    if (mins>0){
      let t = mins*60, el=document.getElementById('tm');
      const iv = setInterval(()=>{
        if(--t<=0){ clearInterval(iv); submitOnce(); return; }
        const m=Math.floor(t/60), s=String(t%60).padStart(2,'0');
        if(el) el.textContent = m+':'+s;
      },1000);
    }
  })();
</script>
</body>
</html>
