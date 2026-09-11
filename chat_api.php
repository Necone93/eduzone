<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
  @session_start();
}

require_once __DIR__ . '/admin_config.php';
date_default_timezone_set('Europe/Belgrade');

function send_json(array $payload, int $status = 200): void {
  if (ob_get_length() !== false) {
    ob_clean();
  }
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload);
  exit;
}

if (empty($_SESSION['email'])) {
  send_json(['ok' => false, 'error' => 'Niste prijavljeni.'], 401);
}

$ADMIN_EMAIL = ADMIN_EMAIL;
$ONLINE_WINDOW = 300;

function is_chat_admin(): bool {
  return ez_is_admin();
}

function display_name_from_email(string $email): string {
  if (ez_is_admin_email($email)) {
    return 'Profesor ' . ucfirst(strtolower(explode('.', explode('@', $email)[0])[0]));
  }

  $local = explode('@', $email)[0];
  $parts = array_filter(explode('.', $local), static fn($part) => $part !== '');
  if (!$parts) {
    return $local;
  }

  return implode(' ', array_map(
    static fn($part) => ucfirst(strtolower($part)),
    $parts
  ));
}

function chat_local_time(string $createdAt): string {
  $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $createdAt, new DateTimeZone('Europe/Belgrade'));
  if (!$date) {
    $date = new DateTimeImmutable($createdAt, new DateTimeZone('Europe/Belgrade'));
  }

  return $date->format('H:i');
}

function ensure_chat_table(mysqli $conn): void {
  $conn->query("
    CREATE TABLE IF NOT EXISTS public_chat_messages (
      id INT AUTO_INCREMENT PRIMARY KEY,
      sender_email VARCHAR(190) NOT NULL,
      message TEXT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX (created_at),
      INDEX (sender_email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $conn->query("DELETE FROM public_chat_messages WHERE created_at < (NOW() - INTERVAL 20 DAY)");
}

function refresh_online_user(mysqli $conn, int $onlineWindow): void {
  $conn->query("CREATE TABLE IF NOT EXISTS online_users (
    session_id VARCHAR(128) PRIMARY KEY,
    email      VARCHAR(190) NULL,
    ip         VARCHAR(45)  NULL,
    user_agent VARCHAR(190) NULL,
    last_seen  DATETIME     NOT NULL,
    INDEX (last_seen),
    INDEX (email)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $sid = session_id();
  $email = $_SESSION['email'] ?? null;
  $ip = $_SERVER['REMOTE_ADDR'] ?? '';
  $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 190);
  $now = date('Y-m-d H:i:s');

  $stmt = $conn->prepare("
    INSERT INTO online_users (session_id, email, ip, user_agent, last_seen)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      email=VALUES(email),
      ip=VALUES(ip),
      user_agent=VALUES(user_agent),
      last_seen=VALUES(last_seen)
  ");
  $stmt->bind_param('sssss', $sid, $email, $ip, $ua, $now);
  $stmt->execute();
  $stmt->close();

  $stmt = $conn->prepare("DELETE FROM online_users WHERE last_seen < (NOW() - INTERVAL ? SECOND)");
  $stmt->bind_param('i', $onlineWindow);
  $stmt->execute();
  $stmt->close();
}

function fetch_messages(mysqli $conn): array {
  $result = $conn->query("
    SELECT id, sender_email, message, created_at
    FROM (
      SELECT id, sender_email, message, created_at
      FROM public_chat_messages
      ORDER BY id DESC
      LIMIT 100
    ) recent
    ORDER BY id ASC
  ");

  $messages = [];
  while ($row = $result->fetch_assoc()) {
    $messages[] = [
      'id' => (int)$row['id'],
      'sender_email' => $row['sender_email'],
      'sender_name' => display_name_from_email($row['sender_email']),
      'message' => $row['message'],
      'time' => chat_local_time($row['created_at']),
      'mine' => $row['sender_email'] === ($_SESSION['email'] ?? ''),
      'can_manage' => is_chat_admin(),
    ];
  }

  return $messages;
}

function fetch_online_students(mysqli $conn, int $onlineWindow): array {
  $stmt = $conn->prepare("
    SELECT email, MAX(last_seen) AS last_seen
    FROM online_users
    WHERE email IS NOT NULL
      AND email <> ''
      AND last_seen >= (NOW() - INTERVAL ? SECOND)
    GROUP BY email
    ORDER BY email ASC
  ");
  $stmt->bind_param('i', $onlineWindow);
  $stmt->execute();
  $result = $stmt->get_result();

  $students = [];
  while ($row = $result->fetch_assoc()) {
    $students[] = [
      'email' => $row['email'],
      'name' => display_name_from_email($row['email']),
    ];
  }
  $stmt->close();

  return $students;
}

function fetch_online_counts(mysqli $conn, int $onlineWindow): array {
  $stmt = $conn->prepare("
    SELECT COUNT(*) AS total, COUNT(DISTINCT email) AS logged
    FROM online_users
    WHERE last_seen >= (NOW() - INTERVAL ? SECOND)
  ");
  $stmt->bind_param('i', $onlineWindow);
  $stmt->execute();
  $counts = $stmt->get_result()->fetch_assoc() ?: ['total' => 0, 'logged' => 0];
  $stmt->close();

  return [
    'total' => (int)$counts['total'],
    'logged' => (int)$counts['logged'],
  ];
}

try {
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
  require __DIR__ . '/db_connect.php';
  if (!isset($conn) || !($conn instanceof mysqli)) {
    throw new RuntimeException('Konekcija na bazu nije dostupna.');
  }
  $conn->set_charset('utf8mb4');
  $conn->query("SET time_zone = '" . date('P') . "'");

  refresh_online_user($conn, $ONLINE_WINDOW);
  ensure_chat_table($conn);

  $action = $_POST['action'] ?? $_GET['action'] ?? 'sync';

  if ($action === 'send') {
    $message = trim($_POST['message'] ?? '');
    $message = preg_replace("/\r\n|\r/", "\n", $message);

    if ($message === '') {
      send_json(['ok' => false, 'error' => 'Poruka je prazna.']);
    }

    if (strlen($message) > 1000) {
      send_json(['ok' => false, 'error' => 'Poruka može imati najviše 1000 karaktera.']);
    }

    $senderEmail = $_SESSION['email'];
    $stmt = $conn->prepare("INSERT INTO public_chat_messages (sender_email, message) VALUES (?, ?)");
    $stmt->bind_param('ss', $senderEmail, $message);
    $stmt->execute();
    $stmt->close();
  } elseif ($action === 'edit') {
    if (!is_chat_admin()) {
      send_json(['ok' => false, 'error' => 'Nemate dozvolu za izmenu poruka.'], 403);
    }

    $messageId = (int)($_POST['id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    $message = preg_replace("/\r\n|\r/", "\n", $message);

    if ($messageId <= 0) {
      send_json(['ok' => false, 'error' => 'Nedostaje ID poruke.']);
    }

    if ($message === '') {
      send_json(['ok' => false, 'error' => 'Poruka je prazna.']);
    }

    if (strlen($message) > 1000) {
      send_json(['ok' => false, 'error' => 'Poruka može imati najviše 1000 karaktera.']);
    }

    $stmt = $conn->prepare("UPDATE public_chat_messages SET message=? WHERE id=?");
    $stmt->bind_param('si', $message, $messageId);
    $stmt->execute();
    $stmt->close();
  } elseif ($action === 'delete') {
    if (!is_chat_admin()) {
      send_json(['ok' => false, 'error' => 'Nemate dozvolu za brisanje poruka.'], 403);
    }

    $messageId = (int)($_POST['id'] ?? 0);
    if ($messageId <= 0) {
      send_json(['ok' => false, 'error' => 'Nedostaje ID poruke.']);
    }

    $stmt = $conn->prepare("DELETE FROM public_chat_messages WHERE id=?");
    $stmt->bind_param('i', $messageId);
    $stmt->execute();
    $stmt->close();
  }

  $onlineCounts = fetch_online_counts($conn, $ONLINE_WINDOW);

  send_json([
    'ok' => true,
    'messages' => fetch_messages($conn),
    'online' => fetch_online_students($conn, $ONLINE_WINDOW),
    'online_total' => $onlineCounts['total'],
    'online_logged' => $onlineCounts['logged'],
    'current_email' => $_SESSION['email'],
  ]);
} catch (Throwable $e) {
  error_log('EduZone chat_api error: ' . $e->getMessage());
  send_json(['ok' => false, 'error' => 'Greška u chatu.'], 500);
}
