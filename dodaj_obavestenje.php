<?php
session_start();
require_once __DIR__ . '/admin_config.php';

// Dozvoljeno samo tebi
if (!ez_is_admin()) {
    echo "⛔ Pristup dozvoljen samo profesoru.";
    exit;
}

// Obrada forme
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $razred = $_POST["razred"];
    $naslov = $_POST["naslov"];
    $tekst = $_POST["tekst"];

    include 'db_connect.php';
    $sql = "INSERT INTO obavestenja (razred, naslov, tekst) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $razred, $naslov, $tekst);
    $stmt->execute();
    $stmt->close();
    $conn->close();

    echo "<div class='alert alert-success text-center m-4'>✅ Obaveštenje je uspešno dodato!</div>";
}
?>

<!DOCTYPE html>
<html lang="sr">
<head>
<?php require_once __DIR__ . '/analytics.php'; ?>
  <meta charset="UTF-8">
  <title>Dodaj obaveštenje</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light p-4">
  <div class="container">
    <h2 class="mb-4">➕ Dodaj obaveštenje</h2>
    <form method="POST">
      <div class="mb-3">
        <label class="form-label">Za koji razred:</label>
        <select name="razred" class="form-select" required>
          <option value="">-- Izaberi --</option>
          <option value="Iit">Iit</option>
          <option value="IIit">IIit</option>
          <option value="IIIit">IIIit</option>
          <option value="IVit">IVit</option>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label">Naslov:</label>
        <input type="text" name="naslov" class="form-control" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Tekst:</label>
        <textarea name="tekst" class="form-control" rows="5" required></textarea>
      </div>
      <div class="mb-3">
  <label class="form-label">Važi do:</label>
  <input type="date" name="vazi_do" class="form-control">
</div>
      <div class="d-flex justify-content-between">
        <a href="dashboard.php" class="btn btn-secondary">⬅️ Nazad</a>
        <button type="submit" class="btn btn-primary">✅ Dodaj</button>
      </div>
    </form>
  </div>
</body>
</html>
