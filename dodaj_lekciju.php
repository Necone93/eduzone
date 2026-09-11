<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/analytics.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-5">
        <h2 class ="mb-4">Dodavanje nove lekcije</h2>
        <?php
  // povezivanje sa bazom
  include 'db_connect.php'; 
  if ($conn->connect_error) {
      die("<div class='alert alert-danger'>Greška u konekciji: " . $conn->connect_error . "</div>");
  }

  // kad je forma poslata
  if ($_SERVER["REQUEST_METHOD"] === "POST") {
      $predmet = $_POST["predmet"];
      $naziv = $_POST["naziv"];
      $opis = $_POST["opis"];
      $razred = $_POST["razred"];
      $pdf_link = $_POST["pdf_link"];

      $sql = "INSERT INTO lekcije (predmet, naziv, opis, razred, pdf_link) VALUES (?, ?, ?, ?, ?)";
      $stmt = $conn->prepare($sql);
      $stmt->bind_param("sssss", $predmet, $naziv, $opis, $razred, $pdf_link);

      if ($stmt->execute()) {
          echo "<div class='alert alert-success'>✅ Lekcija uspešno dodata!</div>";
      } else {
          echo "<div class='alert alert-danger'>Greška prilikom dodavanja.</div>";
      }

      $stmt->close();
  }

  $conn->close();
  ?>

  <form method="POST" class="bg-white p-4 shadow-sm rounded">
    <div class="mb-3">
      <label for="predmet" class="form-label">Predmet</label>
      <select class="form-select" name="predmet" id="predmet" required>
        <option value="wp1">Web programiranje 1</option>
        <option value="wp2">Web programiranje 2</option>
        <option value="wd">Web dizajn</option>
        <option value="inf">Informatika</option>
      </select>
    </div>
    <div class="mb-3">
      <label for="naziv" class="form-label">Naziv lekcije</label>
      <input type="text" class="form-control" id="naziv" name="naziv" required>
    </div>
    <div class="mb-3">
      <label for="opis" class="form-label">Opis</label>
      <textarea class="form-control" id="opis" name="opis" rows="3" required></textarea>
    </div>
    <div class="mb-3">
      <label for="razred" class="form-label">Razred</label>
      <input type="text" class="form-control" id="razred" name="razred" required placeholder="npr. I4">
    </div>
    <div class="mb-3">
      <label for="pdf_link" class="form-label">Link do PDF fajla</label>
      <input type="text" class="form-control" id="pdf_link" name="pdf_link" required>
    </div>
    <button type="submit" class="btn btn-success">💾 Sačuvaj lekciju</button>
  </form>
    </div>
</body>
</html>