<?php
session_start();
require_once __DIR__ . '/admin_config.php';
if (!ez_is_admin()) { exit('⛔'); }
require __DIR__.'/db_connect.php';
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* === EDUZONE PATCH: helper za provere kolona === */
function col_exists(mysqli $c,string $t,string $col):bool{
  $r=$c->query("SHOW COLUMNS FROM `$t` LIKE '".$c->real_escape_string($col)."'");
  $ok=$r && $r->num_rows>0; if($r) $r->free(); return $ok;
}

$test_id = (int)($_GET['test_id'] ?? 0);
if(!$test_id) exit('Nedostaje test_id.');

/* Učitaj meta + (opciono) broj_pitanja iz testovi */
$ima_broj = col_exists($conn,'testovi','broj_pitanja');
if ($ima_broj) {
  $st=$conn->prepare("SELECT id, naslov, broj_pitanja FROM testovi WHERE id=?");
} else {
  $st=$conn->prepare("SELECT id, naslov FROM testovi WHERE id=?");
}
$st->bind_param("i",$test_id); $st->execute(); $test=$st->get_result()->fetch_assoc(); $st->close();
if(!$test) exit('Test ne postoji.');

$planirano = $ima_broj ? (int)($test['broj_pitanja'] ?? 0) : 0;

/* Da li imamo kolonu tacan u odgovorima? */
$ima_tacan = col_exists($conn,'odgovori','tacan');

/* Pitanja + broj odgovora (+ broj tačnih ako postoji kolona tacan) */
$sql = "
  SELECT p.id, p.tekst, p.tip,
         (SELECT COUNT(*) FROM odgovori o WHERE o.pitanje_id=p.id) AS br_odg"
       .($ima_tacan ? ",
         (SELECT COUNT(*) FROM odgovori o2 WHERE o2.pitanje_id=p.id AND o2.tacan=1) AS br_tacnih" : "")."
  FROM pitanja p
  WHERE p.test_id=?
  ORDER BY p.id ASC";
$q=$conn->prepare($sql);
$q->bind_param("i",$test_id); $q->execute(); $rs=$q->get_result();
$rows=[]; while($r=$rs->fetch_assoc()) $rows[]=$r; $q->close();

/* Za upozorenja: detektuj pitanja bez tačnih (radio/checkbox) */
$warnNoCorrect = [];
if ($ima_tacan) {
  foreach ($rows as $r) {
    $tip = strtolower(trim($r['tip']));
    if (in_array($tip, ['radio','checkbox'], true)) {
      $bt = (int)($r['br_tacnih'] ?? 0);
      if ($bt === 0) $warnNoCorrect[] = (int)$r['id'];
    }
  }
}
?>
<!doctype html><html lang="sr"><head>
<?php require_once __DIR__ . '/analytics.php'; ?>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pitanja — <?= e($test['naslov']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h3 class="mb-0">❓ Pitanja — <small class="text-muted"><?= e($test['naslov']) ?></small></h3>
      <?php if ($planirano>0): ?>
        <div class="text-muted small">Planirano na testu: <strong><?= (int)$planirano ?></strong> pitanja</div>
      <?php endif; ?>
    </div>
    <div>
      <a class="btn btn-secondary" href="tests_admin.php">⬅️ Nazad</a>
      <a class="btn btn-primary" href="question_edit.php?test_id=<?= (int)$test_id ?>">➕ Dodaj pitanje</a>
    </div>
  </div>

  <?php if ($ima_tacan && !empty($warnNoCorrect)): ?>
    <div class="alert alert-warning">
      Neka pitanja tipa <strong>radio/checkbox</strong> nemaju označen tačan odgovor:
      <?= e('#'.implode(', #',$warnNoCorrect)) ?>.
      Automatsko bodovanje za njih neće raditi dok ne postaviš bar jedan tačan.
    </div>
  <?php endif; ?>

  <div class="card shadow-sm"><div class="card-body">
    <?php if (empty($rows)): ?>
      <div class="text-muted">Još nema pitanja.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead>
            <tr>
              <th>#</th><th>Tekst</th><th>Tip</th>
              <th>Odgovora</th>
              <?php if ($ima_tacan): ?><th>Tačnih</th><?php endif; ?>
              <th class="text-end">Akcije</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($rows as $r): 
            $tip = strtolower(trim($r['tip']));
            $bt = $ima_tacan ? (int)($r['br_tacnih'] ?? 0) : null;
            $needs = ($ima_tacan && in_array($tip,['radio','checkbox'],true) && $bt===0);
          ?>
            <tr class="<?= $needs ? 'table-warning':'' ?>">
              <td><?= (int)$r['id'] ?></td>
              <td><?= e($r['tekst']) ?></td>
              <td><?= e($r['tip']) ?></td>
              <td><?= (int)$r['br_odg'] ?></td>
              <?php if ($ima_tacan): ?>
                <td><?= (int)$bt ?></td>
              <?php endif; ?>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-primary" href="question_edit.php?test_id=<?= (int)$test_id ?>&id=<?=
                  (int)$r['id'] ?>">✏️ Izmeni</a>
                <a class="btn btn-sm btn-outline-danger" href="question_delete.php?test_id=<?= (int)$test_id ?>&id=<?=
                  (int)$r['id'] ?>" onclick="return confirm('Obrisati pitanje?')">🗑 Obriši</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div></div>
</div>
</body></html>
