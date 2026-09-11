<?php
session_start();
require_once __DIR__ . '/admin_config.php';
if (!ez_is_admin()) {
    echo "⛔ Pristup dozvoljen samo nastavniku.";
    exit;
}
$id = $_GET["id"] ?? null;
if (!$id) {
    echo "Nedostaje ID testa.";
    exit;
}
$conn = new mysqli("localhost", "root", "", "materijali_sajt");
$stmt = $conn->prepare("UPDATE testovi SET aktivan = 1 WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->close();
$conn->close();
header("Location: dodeli_testove.php");
exit;
?>