<?php
session_start();
require_once __DIR__ . '/admin_config.php';
if (!ez_is_admin()) {
    exit("⛔ Pristup dozvoljen samo nastavniku.");
}

require __DIR__ . '/db_connect.php'; // očekuje $conn = new mysqli(...)

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ===== Podesi admin email i školski domen =====
$ADMIN_EMAIL = ADMIN_EMAIL;
const SCHOOL_DOMAIN = 'gimeko.edu.rs';

function normalize_to_email($s) {
  $s = trim(strtolower((string)$s));
  if ($s === '') return '';
  return (strpos($s,'@') !== false) ? $s : ($s . '@' . SCHOOL_DOMAIN);
}

// ---- Pomoćne stvari
$RAZREDI = ["Iit","IIit","IIIit","IVit"];

function sledeci_razred($r){
    $map = ["Iit"=>"IIit","IIit"=>"IIIit","IIIit"=>"IVit","IVit"=>"IVit"];
    return $map[$r] ?? $r;
}

// ---- Poruka za UI
$msg = null;

// ---- DODAVANJE / IZMENA
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Dodaj novog
    if (isset($_POST['akcija']) && $_POST['akcija']==='add') {
        $email   = normalize_to_email($_POST['email'] ?? "");
        $lozinka = trim($_POST['lozinka'] ?? "gsucenik");
        $razred  = trim($_POST['razred'] ?? "");
        if (!$email || !$razred) {
            $msg = ["danger","Popuni korisničko ime/email i razred."];
        } else {
            $st = $conn->prepare("INSERT INTO korisnici (email, lozinka, razred) VALUES (?, ?, ?)");
            $st->bind_param("sss", $email, $lozinka, $razred);
            $st->execute(); $st->close();
            $msg = ["success","✅ Učenik dodat."];
        }
    }

    // Izmeni postojeceg
    if (isset($_POST['akcija']) && $_POST['akcija']==='edit') {
        $id      = (int)($_POST['id'] ?? 0);
        $email   = normalize_to_email($_POST['email'] ?? "");
        $lozinka = trim($_POST['lozinka'] ?? "");
        $razred  = trim($_POST['razred'] ?? "");

        if ($id<=0 || !$email || !$razred) {
            $msg = ["danger","Neispravni podaci za izmenu."];
        } else {
            // spreči menjanje super-admin naloga na druge vrednosti (osim lozinke po potrebi)
            if ($email === $GLOBALS['ADMIN_EMAIL'] && $id) {
                // dozvolimo promenu lozinke, ali razred i email ostavljamo netaknuto
                $st = $conn->prepare("UPDATE korisnici SET lozinka=? WHERE id=?");
                $st->bind_param("si", $lozinka, $id);
            } else {
                $st = $conn->prepare("UPDATE korisnici SET email=?, lozinka=?, razred=? WHERE id=?");
                $st->bind_param("sssi", $email, $lozinka, $razred, $id);
            }
            $st->execute(); $st->close();
            $msg = ["success","📝 Izmene sačuvane."];
        }
    }

    // MASOVNO: promocija čekiranih
    if (isset($_POST['akcija']) && $_POST['akcija']==='promote_selected') {
        $ids = array_map('intval', $_POST['ids'] ?? []);
        if ($ids) {
            // dohvatimo id admina po emailu i izbacimo ga
            $admQ = $conn->prepare("SELECT id FROM korisnici WHERE email=? LIMIT 1");
            $admQ->bind_param("s",$ADMIN_EMAIL); $admQ->execute(); $admQ->bind_result($adminId);
            $adminId = ($admQ->fetch()) ? (int)$adminId : 0; $admQ->close();

            $ids = array_values(array_filter($ids, fn($x)=>$x!==$adminId));
            if ($ids) {
                $in    = implode(',', array_fill(0,count($ids),'?'));
                $types = str_repeat('i', count($ids));
                $stmt  = $conn->prepare("SELECT id, razred FROM korisnici WHERE id IN ($in)");
                $stmt->bind_param($types, ...$ids);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $novi = sledeci_razred($row['razred']);
                    $u = $conn->prepare("UPDATE korisnici SET razred=? WHERE id=?");
                    $u->bind_param("si", $novi, $row['id']);
                    $u->execute(); $u->close();
                }
                $stmt->close();
                $msg = ["success","✅ Izabrani učenici su prebačeni u sledeći razred."];
            } else {
                $msg = ["info","Nema važećih učenika za promociju."];
            }
        } else {
            $msg = ["warning","Nisi čekirao nijednog učenika."];
        }
    }

    // MASOVNO: promocija celog razreda
    if (isset($_POST['akcija']) && $_POST['akcija']==='promote_grade') {
        $r = trim($_POST['razred_src'] ?? "");
        if ($r && in_array($r,$RAZREDI,true)) {
            $novi = sledeci_razred($r);
            $st = $conn->prepare("UPDATE korisnici SET razred=? WHERE razred=? AND email<>?");
            $st->bind_param("sss", $novi, $r, $ADMIN_EMAIL);
            $st->execute(); $st->close();
            $msg = ["success","🎓 Svi učenici iz razreda $r su prebačeni u $novi."];
        } else {
            $msg = ["warning","Izaberi razred za promociju."];
        }
    }
}

// ---- BRISANJE
if (isset($_GET['obrisi'])) {
    $id = (int)$_GET['obrisi'];
    // ne dozvoljavamo brisanje admina
    $q = $conn->prepare("SELECT email FROM korisnici WHERE id=?");
    $q->bind_param("i",$id); $q->execute(); $q->bind_result($em);
    if ($q->fetch()) {
        $q->close();
        if ($em === $ADMIN_EMAIL) {
            $msg = ["danger","Ne možeš obrisati admin nalog."];
        } else {
            $d = $conn->prepare("DELETE FROM korisnici WHERE id=?");
            $d->bind_param("i",$id); $d->execute(); $d->close();
            $msg = ["success","🗑 Nalog obrisan."];
        }
    } else {
        $q->close();
    }
}

// ---- PRIPREMA ZA IZMENU
$edit = null;
if (isset($_GET['izmeni'])) {
    $eid = (int)$_GET['izmeni'];
    $r = $conn->prepare("SELECT id,email,lozinka,razred FROM korisnici WHERE id=?");
    $r->bind_param("i",$eid); $r->execute();
    $res = $r->get_result(); $edit = $res->fetch_assoc(); $r->close();
}

// ---- FILTER
$filter = $_GET['razred'] ?? "";
if ($filter && !in_array($filter,$RAZREDI,true)) $filter = "";

if ($filter) {
    $ls = $conn->prepare("SELECT id,email,lozinka,razred FROM korisnici WHERE razred=? ORDER BY razred,email");
    $ls->bind_param("s",$filter);
    $ls->execute(); $users = $ls->get_result();
} else {
    $users = $conn->query("SELECT id,email,lozinka,razred FROM korisnici ORDER BY razred,email");
}
?>
<!doctype html>
<html lang="sr">
<head>
<?php require_once __DIR__ . '/analytics.php'; ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>👥 Učenici — Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">👥 Učenici — administracija</h3>
    <div class="d-flex gap-2">
      <a href="dashboard.php" class="btn btn-secondary">⬅️ Početna</a>
    </div>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-<?= e($msg[0]) ?>"><?= e($msg[1]) ?></div>
  <?php endif; ?>

  <!-- Forma: dodavanje / izmena -->
  <div class="card mb-4 shadow-sm">
    <div class="card-body">
      <h5 class="card-title"><?= $edit ? "✏️ Izmena učenika #".(int)$edit['id'] : "➕ Dodaj učenika" ?></h5>
      <form method="post" class="row g-3">
        <?php if ($edit): ?>
          <input type="hidden" name="akcija" value="edit">
          <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
        <?php else: ?>
          <input type="hidden" name="akcija" value="add">
        <?php endif; ?>

        <div class="col-md-4">
          <label class="form-label">Email ili korisničko ime (ime.prezime)</label>
          <input type="text" name="email" class="form-control"
                 value="<?= e($edit['email'] ?? '') ?>"
                 <?= ($edit && $edit['email']===$ADMIN_EMAIL)?'readonly':'' ?> required>
          <div class="form-text">
            Ako uneseš samo korisničko ime, automatski se dodaje <code>@<?= e(SCHOOL_DOMAIN) ?></code>.
            <?php if ($edit && $edit['email']===$ADMIN_EMAIL): ?>
              Email admin naloga ne može da se menja.
            <?php endif; ?>
          </div>
        </div>

        <div class="col-md-3">
          <label class="form-label">Lozinka</label>
          <input type="text" name="lozinka" class="form-control" value="<?= e($edit['lozinka'] ?? 'gsucenik') ?>" required>
          <div class="form-text">Za zajedničku lozinku koristi „gsucenik“.</div>
        </div>

        <div class="col-md-3">
          <label class="form-label">Razred</label>
          <select name="razred" class="form-select" <?= ($edit && $edit['email']===$ADMIN_EMAIL)?'disabled':'' ?> required>
            <option value="" disabled <?= empty($edit['razred']??'')?'selected':'' ?>>— izaberi —</option>
            <?php foreach ($RAZREDI as $r): ?>
              <option value="<?= e($r) ?>" <?= (!empty($edit['razred']) && $edit['razred']===$r)?'selected':'' ?>><?= e($r) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($edit && $edit['email']===$ADMIN_EMAIL): ?>
            <div class="form-text">Admin nema razred.</div>
          <?php endif; ?>
        </div>

        <div class="col-12">
          <button class="btn btn-success"><?= $edit ? "Sačuvaj izmene" : "Dodaj učenika" ?></button>
          <?php if ($edit): ?>
            <a href="korisnici_admin.php" class="btn btn-outline-secondary ms-2">Otkaži</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <!-- Akcije razreda -->
  <div class="card mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-auto">
          <label class="form-label mb-0">Filter po razredu:</label>
        </div>
        <div class="col-auto">
          <select name="razred" class="form-select">
            <option value="">(svi)</option>
            <?php foreach ($RAZREDI as $r): ?>
              <option value="<?= e($r) ?>" <?= $filter===$r?'selected':'' ?>><?= e($r) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto">
          <button class="btn btn-outline-primary">Primeni</button>
          <?php if ($filter): ?><a class="btn btn-outline-secondary" href="korisnici_admin.php">Poništi</a><?php endif; ?>
        </div>
      </form>

      <hr>

      <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="akcija" value="promote_grade">
        <div class="col-auto">
          <label class="form-label mb-0">Prebaci ceo razred u sledeći:</label>
        </div>
        <div class="col-auto">
          <select name="razred_src" class="form-select" required>
            <option value="" disabled selected>— izaberi —</option>
            <?php foreach ($RAZREDI as $r): ?>
              <option value="<?= e($r) ?>"><?= e($r) ?> → <?= e(sledeci_razred($r)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto">
          <button class="btn btn-warning" onclick="return confirm('Prebaciti SVE učenike iz izabranog razreda u sledeći?')">Promote ceo razred</button>
        </div>
      </form>
    </div>
  </div>

  <!-- TABELA -->
  <form method="post">
    <input type="hidden" name="akcija" value="promote_selected">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="card-title mb-0">Nalozi (<?= $filter ? e($filter) : 'svi' ?>)</h5>
          <button class="btn btn-outline-warning btn-sm" onclick="return confirm('Prebaciti čekirane učenike u sledeći razred?')">Promote čekirane</button>
        </div>

        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead>
              <tr>
                <th><input type="checkbox" id="chk_all" onclick="document.querySelectorAll('.rowchk').forEach(c=>c.checked=this.checked)"></th>
                <th>ID</th>
                <th>Email</th>
                <th>Lozinka</th>
                <th>Razred</th>
                <th class="text-end">Akcije</th>
              </tr>
            </thead>
            <tbody>
            <?php while($u = $users->fetch_assoc()): ?>
              <tr>
                <td>
                  <?php if ($u['email'] !== $ADMIN_EMAIL): ?>
                    <input type="checkbox" class="rowchk" name="ids[]" value="<?= (int)$u['id'] ?>">
                  <?php endif; ?>
                </td>
                <td><?= (int)$u['id'] ?></td>
                <td><?= e($u['email']) ?><?= $u['email']===$ADMIN_EMAIL ? ' <span class="badge text-bg-dark">admin</span>' : '' ?></td>
                <td><code><?= e($u['lozinka']) ?></code></td>
                <td><?= e($u['razred']) ?></td>
                <td class="text-end">
                  <a class="btn btn-sm btn-primary" href="korisnici_admin.php?izmeni=<?= (int)$u['id'] ?>">Izmeni</a>
                  <?php if ($u['email'] !== $ADMIN_EMAIL): ?>
                    <a class="btn btn-sm btn-outline-danger" href="korisnici_admin.php?obrisi=<?= (int)$u['id'] ?>" onclick="return confirm('Obrisati ovog učenika?')">Obriši</a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endwhile; ?>
            </tbody>
          </table>
        </div>

      </div>
    </div>
  </form>

</div>
</body>
</html>
