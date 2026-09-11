<?php
session_start();
require_once __DIR__ . '/admin_config.php';
if (!ez_is_admin()) { exit('⛔ Pristup dozvoljen samo nastavniku.'); }
require __DIR__.'/db_connect.php';
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* === EDUZONE PATCH: helper da bezbedno proverimo kolone (radi kompatibilnosti) === */
function col_exists(mysqli $c,string $t,string $col):bool{
  $r=$c->query("SHOW COLUMNS FROM `$t` LIKE '".$c->real_escape_string($col)."'");
  $ok=$r && $r->num_rows>0; if($r) $r->free(); return $ok;
}

$flash = $_GET['msg'] ?? '';

/* === EDUZONE PATCH: ako postoje nove kolone, uključujemo ih u SELECT === */
$ima_broj = col_exists($conn,'testovi','broj_pitanja');
$ima_flag = col_exists($conn,'testovi','za_ocenu');

$extra = '';
if ($ima_broj) $extra .= ', t.broj_pitanja';
if ($ima_flag) $extra .= ', t.za_ocenu';

$q = $conn->query("
  SELECT t.id, t.naslov, t.razred, t.trajanje,
         (SELECT COUNT(*) FROM pitanja p WHERE p.test_id=t.id) AS br_pitanja
         {$extra}
  FROM testovi t
  ORDER BY t.id DESC
");
$tests=[]; while($r=$q->fetch_assoc()){ $tests[]=$r; } $q->free();
?>
<!doctype html><html lang="sr"><head>
<?php require_once __DIR__ . '/analytics.php'; ?>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Upravljanje testovima</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">🛠 Upravljanje testovima</h3>
    <div class="d-flex gap-2">
      <a class="btn btn-secondary" href="dashboard.php">⬅️ Početna</a>
      <a class="btn btn-primary" href="dodaj_test.php">➕ Dodaj test</a>
    </div>
  </div>

  <?php if ($flash): ?><div class="alert alert-info"><?= e($flash) ?></div><?php endif; ?>

  <div class="card shadow-sm"><div class="card-body">
    <?php if (empty($tests)): ?>
      <div class="text-muted">Još nema testova.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead>
            <tr>
              <th>ID</th>
              <th>Naslov</th>
              <th>Razred</th>
              <th>Trajanje</th>
              <?php if ($ima_flag): ?><th>Tip</th><?php endif; ?><!-- === EDUZONE PATCH -->
              <?php if ($ima_broj): ?><th>Na testu</th><?php endif; ?><!-- === EDUZONE PATCH -->
              <th>Pitanja (u bazi)</th><!-- pre je bilo "Pitanja" -->
              <th class="text-end">Akcije</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($tests as $t): ?>
            <tr>
              <td>#<?= (int)$t['id'] ?></td>
              <td><?= e($t['naslov']) ?></td>
              <td><?= e($t['razred']) ?></td>
              <td><?= (int)$t['trajanje'] ?> min</td>
              <?php if ($ima_flag): ?>
                <td>
                  <?php
                    $flag = isset($t['za_ocenu']) ? (int)$t['za_ocenu'] : 0;
                    echo $flag ? '<span class="badge bg-primary">Za ocenu</span>' : '<span class="badge bg-secondary">Kratka</span>';
                  ?>
                </td>
              <?php endif; ?>
              <?php if ($ima_broj): ?>
                <td><?= isset($t['broj_pitanja']) ? (int)$t['broj_pitanja'] : '' ?></td>
              <?php endif; ?>
              <td><?= (int)$t['br_pitanja'] ?></td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-primary" href="edit_test.php?id=<?= (int)$t['id'] ?>">✏️ Izmeni</a>
                <a class="btn btn-sm btn-outline-secondary" href="questions.php?test_id=<?= (int)$t['id'] ?>">❓ Pitanja</a>
                <a class="btn btn-sm btn-outline-danger" href="delete_test.php?id=<?= (int)$t['id'] ?>" onclick="return confirm('Obrisati ceo test i sva pitanja/odgovore?')">🗑 Obriši</a>
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
