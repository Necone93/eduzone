<?php
include 'db_connect.php';
session_start();
require_once __DIR__ . '/admin_config.php';
if (!ez_is_admin()) {
    echo "⛔ Pristup dozvoljen samo nastavniku.";
    exit;
}

/* === EDUZONE PATCH: helper za proveru da li kolona postoji (radi kompatibilnosti) === */
function kolona_postoji(mysqli $conn, string $tabela, string $kolona): bool {
    $tbl = $conn->real_escape_string($tabela);
    $col = $conn->real_escape_string($kolona);
    $rs = $conn->query("SHOW COLUMNS FROM `$tbl` LIKE '$col'");
    $ok = $rs && $rs->num_rows > 0;
    if ($rs) $rs->free();
    return $ok;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $naslov  = $_POST["naslov"] ?? "";
    $opis    = $_POST["opis"] ?? "";
    $trajanje= (int)($_POST["trajanje"] ?? 20);
    $razred  = $_POST["razred"] ?? "";

    /* === EDUZONE PATCH: novi parametar iz forme === */
    $za_ocenu = isset($_POST["za_ocenu"]) ? (int)$_POST["za_ocenu"] : 0;
    $broj_pitanja = $za_ocenu ? 12 : 10; // 12 za test za ocenu, 10 za kratku proveru

    if ($naslov === "" || $razred === "") {
        echo "<div class='alert alert-danger m-4'>❌ Nedostaju obavezna polja (naslov/razred).</div>";
        exit;
    }

    /* === EDUZONE PATCH: proveri da li postoje kolone u bazi (backward compatible) === */
    $ima_broj = kolona_postoji($conn, 'testovi', 'broj_pitanja');
    $ima_flag = kolona_postoji($conn, 'testovi', 'za_ocenu');

    if ($ima_broj && $ima_flag) {
        // Nova šema (preporučeno)
        $sql = "INSERT INTO testovi (naslov, opis, trajanje, razred, broj_pitanja, za_ocenu) 
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssissi", $naslov, $opis, $trajanje, $razred, $broj_pitanja, $za_ocenu);
    } else {
        // Stara šema (bez novih kolona) – i dalje radi
        $sql = "INSERT INTO testovi (naslov, opis, trajanje, razred) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssis", $naslov, $opis, $trajanje, $razred);
    }

    $stmt->execute();
    $novi_test_id = $stmt->insert_id;
    $stmt->close();
    $conn->close();

    echo "<div class='alert alert-success text-center m-4'>
            ✅ Test dodat!
            <br>
            <a href='dodaj_pitanje.php?test_id=$novi_test_id' class='btn btn-sm btn-primary mt-2'>
              ➕ Dodaj pitanja za ovaj test
            </a>
          </div>";
    exit;
}
?>

<!DOCTYPE html>
<html lang="sr">
<head>
<?php require_once __DIR__ . '/analytics.php'; ?>
  <meta charset="UTF-8">
  <title>Dodaj test</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light p-5">
  <div class="container">
    <div class="text-center mt-4">
      <a href="dashboard.php" class="btn btn-secondary">⬅️ Nazad na početnu</a>
    </div>
    <h2 class="mb-4">📝 Dodaj novi test</h2>

    <form method="POST" class="card p-3 shadow-sm">
      <div class="mb-3">
        <label class="form-label">Naslov testa:</label>
        <input type="text" name="naslov" class="form-control" required>
      </div>

      <div class="mb-3">
        <label class="form-label">Opis testa:</label>
        <textarea name="opis" class="form-control"></textarea>
      </div>

      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Trajanje testa:</label>
          <select name="trajanje" class="form-select" required>
            <option value="20">20 minuta</option>
            <option value="30">30 minuta</option>
            <option value="45">45 minuta</option>
          </select>
        </div>

        <!-- === EDUZONE PATCH: tip testa utiče na broj pitanja (10 / 12) === -->
        <div class="col-md-4">
          <label class="form-label">Tip testa</label>
          <select name="za_ocenu" class="form-select" required>
            <option value="0" selected>Kratka provera (10 pitanja)</option>
            <option value="1">Test za ocenu (12 pitanja)</option>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">Za koji razred:</label>
          <select name="razred" class="form-select" required>
            <option value="Iit">Iit</option>
            <option value="IIit">IIit</option>
            <option value="IIIit">IIIit</option>
            <option value="IVit">IVit</option>
          </select>
        </div>
      </div>

      <div class="mt-4">
        <button type="submit" class="btn btn-success">✅ Sačuvaj test</button>
      </div>
    </form>
  </div>
</body>
</html>
