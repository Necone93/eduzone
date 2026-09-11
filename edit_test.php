<?php
session_start();
require_once __DIR__ . '/admin_config.php';
if (!ez_is_admin()) { exit('⛔'); }
require __DIR__.'/db_connect.php';
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* === EDUZONE PATCH: helper za proveru postojanja kolone (radi kompatibilnosti) === */
function kolona_postoji(mysqli $conn, string $tabela, string $kolona): bool {
  $tbl = $conn->real_escape_string($tabela);
  $col = $conn->real_escape_string($kolona);
  $rs  = $conn->query("SHOW COLUMNS FROM `$tbl` LIKE '$col'");
  $ok  = $rs && $rs->num_rows > 0;
  if ($rs) $rs->free();
  return $ok;
}

$ima_broj = kolona_postoji($conn, 'testovi', 'broj_pitanja');
$ima_flag = kolona_postoji($conn, 'testovi', 'za_ocenu');

$id = (int)($_GET['id'] ?? 0);
if(!$id) exit('Nedostaje id.');

if($_SERVER['REQUEST_METHOD']==='POST'){
  $naslov = trim($_POST['naslov'] ?? '');
  $opis   = trim($_POST['opis'] ?? '');
  $traj   = (int)($_POST['trajanje'] ?? 30);
  $razred = trim($_POST['razred'] ?? '');

  /* === EDUZONE PATCH: nova polja iz forme (bezbedno i ako kolone ne postoje) === */
  $za_ocenu = (int)($_POST['za_ocenu'] ?? 0);
  // Ako je test za ocenu → 12 pitanja, inače uzmi iz inputa (fallback 10)
  $broj_pitanja = $za_ocenu ? 12 : (int)($_POST['broj_pitanja'] ?? 10);
  if ($broj_pitanja < 1) $broj_pitanja = 1;

  if($naslov && $razred){
    if ($ima_broj && $ima_flag) {
      // nova šema – ažuriraj i broj_pitanja i za_ocenu
      $st=$conn->prepare("UPDATE testovi SET naslov=?, opis=?, trajanje=?, razred=?, broj_pitanja=?, za_ocenu=? WHERE id=?");
      $st->bind_param("ssisiii",$naslov,$opis,$traj,$razred,$broj_pitanja,$za_ocenu,$id);
    } else {
      // stara šema – ažuriraj samo postojeća polja (radi bez novih kolona)
      $st=$conn->prepare("UPDATE testovi SET naslov=?, opis=?, trajanje=?, razred=? WHERE id=?");
      $st->bind_param("ssisi",$naslov,$opis,$traj,$razred,$id);
    }
    $st->execute(); $st->close();
    header("Location: tests_admin.php?msg=Izmenjen test #$id"); exit;
  }
}

/* Učitavanje meta (čita sve kolone, ako postoje) */
$st=$conn->prepare("SELECT * FROM testovi WHERE id=?");
$st->bind_param("i",$id); 
$st->execute(); 
$test=$st->get_result()->fetch_assoc(); 
$st->close();
if(!$test) exit('Test nije pronađen.');

/* === EDUZONE PATCH: vrednosti za formu (bezbedno i ako kolone ne postoje) === */
$curr_za_ocenu    = $ima_flag ? (int)$test['za_ocenu'] : 0;
$curr_broj_pitanja= $ima_broj ? (int)$test['broj_pitanja'] : ($curr_za_ocenu ? 12 : 10);
?>
<!doctype html><html lang="sr"><head>
<?php require_once __DIR__ . '/analytics.php'; ?>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Izmena testa</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-light p-4">
<div class="container">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3>✏️ Izmena testa #<?= (int)$id ?></h3>
    <a class="btn btn-secondary" href="tests_admin.php">⬅️ Nazad</a>
  </div>

  <form method="post" class="card p-3 shadow-sm">
    <div class="mb-3">
      <label class="form-label">Naslov</label>
      <input class="form-control" name="naslov" required value="<?= e($test['naslov']) ?>">
    </div>

    <div class="mb-3">
      <label class="form-label">Opis</label>
      <textarea class="form-control" name="opis" rows="3"><?= e($test['opis']) ?></textarea>
    </div>

    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label">Trajanje (min)</label>
        <input type="number" min="1" class="form-control" name="trajanje" required value="<?= (int)$test['trajanje'] ?>">
      </div>

      <div class="col-md-4">
        <label class="form-label">Razred</label>
        <input class="form-control" name="razred" required value="<?= e($test['razred']) ?>">
      </div>

      <!-- === EDUZONE PATCH: Tip testa utiče na broj pitanja (10/12) === -->
      <div class="col-md-4">
        <label class="form-label">Tip testa</label>
        <select name="za_ocenu" class="form-select" <?= $ima_flag ? '' : 'disabled' ?>>
          <option value="0" <?= $curr_za_ocenu ? '' : 'selected' ?>>Kratka provera (10 pitanja)</option>
          <option value="1" <?= $curr_za_ocenu ? 'selected' : '' ?>>Test za ocenu (12 pitanja)</option>
        </select>
        <?php if(!$ima_flag): ?>
          <div class="form-text text-warning">Napomena: kolona <code>za_ocenu</code> ne postoji u bazi (forma je read-only).</div>
        <?php endif; ?>
      </div>

      <!-- === EDUZONE PATCH: ručno polje za broj pitanja (readonly ako je za_ocenu=1) === -->
      <div class="col-md-4">
        <label class="form-label">Broj pitanja</label>
        <input type="number" name="broj_pitanja" class="form-control"
               value="<?= (int)$curr_broj_pitanja ?>" min="1" max="50"
               <?= $curr_za_ocenu ? 'readonly' : '' ?> <?= $ima_broj ? '' : 'disabled' ?>>
        <div class="form-text">
          Za <strong>test za ocenu</strong> se koristi fiksno 12.
          <?php if(!$ima_broj): ?>
            <br><span class="text-warning">Napomena: kolona <code>broj_pitanja</code> ne postoji u bazi (forma je read-only).</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="text-end mt-3"><button class="btn btn-success">💾 Sačuvaj</button></div>
  </form>
</div>

<!-- === EDUZONE PATCH: mali JS da onemogući ručno menjanje kada je za_ocenu=1 === -->
<script>
(function(){
  const sel = document.querySelector('select[name="za_ocenu"]');
  const num = document.querySelector('input[name="broj_pitanja"]');
  if (!sel || !num) return;
  function sync(){ 
    if (sel.value === '1') { num.value = 12; num.setAttribute('readonly','readonly'); }
    else { num.removeAttribute('readonly'); if (+num.value < 1) num.value = 10; }
  }
  sel.addEventListener('change', sync);
  sync();
})();
</script>
</body></html>
