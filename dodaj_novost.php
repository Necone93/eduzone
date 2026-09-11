<?php
session_start();
require_once __DIR__ . '/admin_config.php';
if (!ez_is_admin()) {
    echo "⛔ Pristup dozvoljen samo nastavniku.";
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $naslov = $_POST["naslov"];
    $opis = $_POST["opis"];
    $link = $_POST["link"];
    $slika = $_POST["slika"];

    include 'db_connect.php';
    $sql = "INSERT INTO novosti (naslov, opis, link, slika) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssss", $naslov, $opis, $link, $slika);
    $stmt->execute();
    $stmt->close();
    $conn->close();

    echo "<div class='alert alert-success text-center m-4'>✅ Uspešno dodata novost!</div>";
}
?>

<!DOCTYPE html>
<html lang="sr">
<head>
<?php require_once __DIR__ . '/analytics.php'; ?>
  <meta charset="UTF-8">
  <title>Dodaj novost</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-5 bg-light">
  <div class="container">
    <h2 class="mb-4 text-center">➕ Dodaj novu IT vest</h2>
    <form method="POST" class="mb-4">
      <div class="mb-3">
        <label class="form-label">Naslov:</label>
        <input type="text" name="naslov" class="form-control" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Opis:</label>
        <textarea name="opis" class="form-control" rows="4"></textarea>
      </div>
      <div class="mb-3">
        <label class="form-label">Link ka izvoru (opciono):</label>
        <input type="url" name="link" class="form-control">
      </div>
      <div class="mb-3">
        <label class="form-label">URL slike (opciono):</label>
        <input type="url" name="slika" class="form-control">
      </div>
      <div class="d-flex justify-content-between">
        <a href="dashboard.php" class="btn btn-secondary">⬅️ Nazad</a>
        <button type="submit" class="btn btn-success">✅ Dodaj</button>
      </div>
    </form>
  </div>
</body>
</html>
