<?php
// online_tracker.php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/db_connect.php';

$ONLINE_WINDOW = 300; // koliko sekundi smatramo da je korisnik "online" (5 min)

// Tabela za evidenciju
$conn->query("CREATE TABLE IF NOT EXISTS online_users (
  session_id VARCHAR(128) PRIMARY KEY,
  email      VARCHAR(190) NULL,
  ip         VARCHAR(45)  NULL,
  user_agent VARCHAR(190) NULL,
  last_seen  DATETIME     NOT NULL,
  INDEX (last_seen),
  INDEX (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$sid   = session_id();
$email = $_SESSION['email'] ?? null;
$ip    = $_SERVER['REMOTE_ADDR'] ?? '';
$ua    = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 190);
$now   = date('Y-m-d H:i:s');

// Upis / osvežavanje prisustva
$stmt = $conn->prepare("
  INSERT INTO online_users (session_id, email, ip, user_agent, last_seen)
  VALUES (?,?,?,?,?)
  ON DUPLICATE KEY UPDATE
    email=VALUES(email),
    ip=VALUES(ip),
    user_agent=VALUES(user_agent),
    last_seen=VALUES(last_seen)
");
$stmt->bind_param("sssss", $sid, $email, $ip, $ua, $now);
$stmt->execute();
$stmt->close();

// Čišćenje starih (offline)
$del = $conn->prepare("DELETE FROM online_users WHERE last_seen < (NOW() - INTERVAL ? SECOND)");
$del->bind_param("i", $ONLINE_WINDOW);
$del->execute();
$del->close();

// Broj trenutno online (po sesijama)
$c1 = $conn->prepare("SELECT COUNT(*) FROM online_users WHERE last_seen >= (NOW() - INTERVAL ? SECOND)");
$c1->bind_param("i", $ONLINE_WINDOW);
$c1->execute();
$c1->bind_result($onlineTotal);
$c1->fetch();
$c1->close();

// Broj ulogovanih online (po jedinstvenom emailu)
$c2 = $conn->prepare("SELECT COUNT(DISTINCT email) FROM online_users WHERE email IS NOT NULL AND last_seen >= (NOW() - INTERVAL ? SECOND)");
$c2->bind_param("i", $ONLINE_WINDOW);
$c2->execute();
$c2->bind_result($onlineLogged);
$c2->fetch();
$c2->close();

$GLOBALS['ONLINE_TOTAL']  = (int)$onlineTotal;  // svi (i gosti, ako ih ima)
$GLOBALS['ONLINE_LOGGED'] = (int)$onlineLogged; // samo ulogovani
