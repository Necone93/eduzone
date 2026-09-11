<?php
if (!isset($_SESSION["razred"])) {
    return;
}

$razred = $_SESSION["razred"];

// Mapiranje razreda u kod predmeta
$mapa_predmeta = [
    "Iit" => "inf",
    "IIit" => "wd",
    "IIIit" => "wp1",
    "IVit" => "wp2"
];

$predmet = $mapa_predmeta[$razred] ?? "wp1";

include 'db_connect.php';
if ($conn->connect_error) {
    return;
}

// Ovde je najbitnija izmena – izvlačimo sve nazive
$sql = "SELECT naziv FROM lekcije WHERE oznaci_kao_novu = 1 AND razred = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $razred);
$stmt->execute();
$result = $stmt->get_result();

$lekcije = [];
while ($row = $result->fetch_assoc()) {
    $lekcije[] = htmlspecialchars($row["naziv"]);
}

$stmt->close();
$conn->close();

// Prikaz ako ima novih lekcija
if (count($lekcije) > 0) {
    echo "<div class='alert alert-info'>";
    echo "<strong>📢 Novi materijali za <u>$razred</u>:</strong><br>";
    echo "<ul class='mb-2'>";
    foreach ($lekcije as $naziv) {
        echo "<li>$naziv</li>";
    }
    echo "</ul>";
    echo "<a href='lekcije.php?predmet=$predmet' class='btn btn-sm btn-primary'>Pogledaj</a>";
    echo "</div>";
}
?>
