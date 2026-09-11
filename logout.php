<?php
session_start();

$sid = session_id();
$email = $_SESSION['email'] ?? '';

require __DIR__ . '/db_connect.php';
if (isset($conn) && $conn instanceof mysqli) {
    $conn->query("CREATE TABLE IF NOT EXISTS online_users (
        session_id VARCHAR(128) PRIMARY KEY,
        email      VARCHAR(190) NULL,
        ip         VARCHAR(45)  NULL,
        user_agent VARCHAR(190) NULL,
        last_seen  DATETIME     NOT NULL,
        INDEX (last_seen),
        INDEX (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($email !== '') {
        $stmt = $conn->prepare("DELETE FROM online_users WHERE session_id = ? OR email = ?");
        $stmt->bind_param("ss", $sid, $email);
    } else {
        $stmt = $conn->prepare("DELETE FROM online_users WHERE session_id = ?");
        $stmt->bind_param("s", $sid);
    }
    $stmt->execute();
    $stmt->close();
}

$_SESSION = [];
session_destroy();
header('Location: index.php');
exit;
