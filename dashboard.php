<?php
session_start();
require_once __DIR__ . '/admin_config.php';
date_default_timezone_set('Europe/Belgrade');

if (empty($_SESSION["email"])) {
  header("Location: login.php");
  exit;
}

// Ime za pozdrav (iz username-a ili iz email-a)
$ime = "";
if (!empty($_SESSION['username'])) {
    $ime = ucfirst(explode('.', $_SESSION['username'])[0]);
} else {
    $email = $_SESSION["email"];
    $ime = ucfirst(explode('.', explode('@', $email)[0])[0]);
}

include __DIR__ . '/online_tracker.php';

// Admin detekcija
$FORCE_ADMIN = false;
$IS_ADMIN = ez_is_admin() || $FORCE_ADMIN;
$ADMIN_EMAIL = ADMIN_EMAIL;

function dashboard_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function dashboard_chat_name(string $email, string $adminEmail): string {
    if ($email === $adminEmail) {
        return 'Profesor ' . ucfirst(strtolower(explode('.', explode('@', $email)[0])[0]));
    }

    $local = explode('@', $email)[0];
    $parts = array_filter(explode('.', $local), static fn($part) => $part !== '');
    if (!$parts) {
        return $local;
    }

    return implode(' ', array_map(static fn($part) => ucfirst(strtolower($part)), $parts));
}

function dashboard_chat_time(string $createdAt): string {
    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $createdAt, new DateTimeZone('Europe/Belgrade'));
    if (!$date) {
        $date = new DateTimeImmutable($createdAt, new DateTimeZone('Europe/Belgrade'));
    }

    return $date->format('H:i');
}

$initialChatMessages = [];
$initialOnlineStudents = [];
try {
    $conn->query("SET time_zone = '" . date('P') . "'");
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

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['chat_action'] ?? '') === 'send') {
        $chatMessage = trim($_POST['chat_message'] ?? '');
        $chatMessage = preg_replace("/\r\n|\r/", "\n", $chatMessage);

        if ($chatMessage !== '' && strlen($chatMessage) <= 1000) {
            $stmt = $conn->prepare("INSERT INTO public_chat_messages (sender_email, message) VALUES (?, ?)");
            $senderEmail = $_SESSION['email'];
            $stmt->bind_param("ss", $senderEmail, $chatMessage);
            $stmt->execute();
            $stmt->close();
        }

        header("Location: dashboard.php#javni-chat");
        exit;
    }

    $chatRes = $conn->query("
      SELECT id, sender_email, message, created_at
      FROM (
        SELECT id, sender_email, message, created_at
        FROM public_chat_messages
        ORDER BY id DESC
        LIMIT 100
      ) recent
      ORDER BY id ASC
    ");
    while ($row = $chatRes->fetch_assoc()) {
        $initialChatMessages[] = $row;
    }

    $onlineStmt = $conn->prepare("
      SELECT email
      FROM online_users
      WHERE email IS NOT NULL
        AND email <> ''
        AND last_seen >= (NOW() - INTERVAL 300 SECOND)
      GROUP BY email
      ORDER BY email ASC
    ");
    $onlineStmt->execute();
    $onlineRes = $onlineStmt->get_result();
    while ($row = $onlineRes->fetch_assoc()) {
        $initialOnlineStudents[] = $row['email'];
    }
    $onlineStmt->close();
} catch (Throwable $e) {
    error_log('EduZone dashboard initial chat error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="sr">
<head>
<?php require_once __DIR__ . '/analytics.php'; ?>
  <meta charset="UTF-8">
  <title>EduZone - Početna</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="icon" type="image/png" href="images/eduZoneLogo.png">

  <style>
    :root{ --ez-yellow:#ffd100; --ez-yellow-2:#ffbf00; --ez-black:#0f0f10; --ez-grey-1:#151517; --ez-grey-2:#1f2023; --ez-grey-3:#2b2c31; --ez-text:#f6f6f6; --ez-muted:#b6b6b6; }
    html,body{min-height:100%}
    body{
      background:
        radial-gradient(1100px 600px at 85% -10%, rgba(255,209,0,.15), transparent 60%),
        radial-gradient(900px 500px at -10% 100%, rgba(255,209,0,.12), transparent 60%),
        var(--ez-black);
      color:var(--ez-text);
      background-repeat: no-repeat;
      background-attachment:fixed;
      font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;
    }
    .ez-container{ max-width: 1180px; margin: 16px auto; padding: 0 14px; }
    .ez-layout{ display: grid; grid-template-columns: <?php echo $IS_ADMIN ? '280px 1fr' : '1fr'; ?>; gap: 16px; align-items: start; }
    @media (max-width: 991.98px){ .ez-layout{ grid-template-columns: 1fr; } }

    .ez-sidebar{
      background: linear-gradient(180deg, var(--ez-grey-1), #121214 60%);
      border:1px solid var(--ez-grey-3); border-radius: 16px;
      box-shadow: 0 10px 35px rgba(0,0,0,.35); overflow: hidden;
      position: sticky; top: 16px; max-height: calc(100vh - 32px);
      display:flex; flex-direction:column;
    }
    .ez-sidebar .ez-sbar-head{ padding:12px 14px; border-bottom:1px solid var(--ez-grey-3); display:flex; align-items:center; gap:10px; background: rgba(255,209,0,.06); }
    .ez-sbar-head .bee{ width:36px;height:36px;display:grid;place-items:center; border-radius:10px;border:1px solid rgba(255,209,0,.28); background:rgba(255,209,0,.12); overflow:hidden; }
    .ez-sbar-head .bee img{width:26px;height:26px;object-fit:contain}
    .ez-sbar-title{margin:0;font-weight:800;font-size:1.05rem}
    .ez-sbar-body{ padding:12px 12px 16px; overflow:auto; }
    .ez-sbar-link{
      display:block; background:#171719; color:#fff; text-decoration:none;
      border:1px solid var(--ez-grey-3); border-radius:12px; padding:10px 12px; margin-bottom:8px; font-weight:600;
    }
    .ez-sbar-link:hover{ border-color: var(--ez-yellow); }

    .ez-card{ background:linear-gradient(180deg,var(--ez-grey-1),#121214 60%); border:1px solid var(--ez-grey-3); border-radius:18px; box-shadow:0 10px 35px rgba(0,0,0,.45); overflow:hidden; }
    .ez-bar{height:6px;background:linear-gradient(90deg,var(--ez-yellow),var(--ez-yellow-2))}
    .ez-head{ display:flex; align-items:center; gap:.9rem; padding:1rem clamp(.75rem,2vw,1.25rem) .5rem; }
    .ez-logo{ width:48px;height:48px;border-radius:10px;background:rgba(255,209,0,.12);display:grid;place-items:center;border:1px solid rgba(255,209,0,.28);box-shadow:0 6px 18px rgba(255,209,0,.25); overflow:hidden;}
    .ez-logo img{width:34px;height:34px;object-fit:contain;display:block}
    .ez-head-main{display:flex;align-items:center;gap:.9rem;min-width:0}
    .ez-head-copy{min-width:0}
    .ez-title{margin:0;font-weight:800;font-size:clamp(1.2rem,1rem + 1vw,1.8rem)}
    .ez-sub{margin:0;color:var(--ez-muted);font-size:clamp(.9rem,.85rem + .4vw,1rem)}
    .logout-image-link{
      width:44px;
      height:44px;
      margin-left:auto;
      display:grid;
      place-items:center;
      border-radius:10px;
      border:1px solid transparent;
      flex:0 0 auto;
    }
    .logout-image-link:hover{border-color:var(--ez-yellow);background:rgba(255,209,0,.08)}
    .logout-image-link img{width:32px;height:32px;object-fit:contain;display:block}
    .ez-body{padding:clamp(.9rem,1.2vw + .5rem,1.25rem)}

    .btn-primary{ background:linear-gradient(180deg,var(--ez-yellow),var(--ez-yellow-2)); border:none; color:#121212; font-weight:700; border-radius:12px; box-shadow:0 8px 18px rgba(255,209,0,.25); }
    .btn-primary:hover{ color:#000; }
    .btn-outline-ez{border:1px solid var(--ez-yellow); color:var(--ez-yellow); border-radius:12px}
    .btn-outline-ez:hover{background:var(--ez-yellow); color:#121212}

    .quick-links{display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); gap:10px}
    @media (max-width:1100px){ .quick-links{grid-template-columns:repeat(3,1fr)} }
    @media (max-width:700px){ .quick-links{grid-template-columns:repeat(2,1fr)} }
    @media (max-width:480px){
      .quick-links{grid-template-columns:1fr}
      .ez-head{align-items:flex-start}
      .logout-image-link{width:40px;height:40px}
      .logout-image-link img{width:30px;height:30px}
    }
    .ql-card{
      min-height:58px;
      display:flex;
      align-items:center;
      background:#171719;
      border:1px solid var(--ez-grey-3);
      border-radius:12px;
      padding:12px 14px;
    }
    .ql-card strong{font-size:.98rem;line-height:1.15}
    .ql-card:hover{ border-color:var(--ez-yellow); }

    .chat-panel{
      margin-top:16px;
      background:#171719;
      border:1px solid var(--ez-grey-3);
      border-radius:16px;
      overflow:hidden;
    }
    .chat-head{
      padding:14px 16px;
      border-bottom:1px solid var(--ez-grey-3);
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      flex-wrap:wrap;
    }
    .chat-head h2{margin:0;font-size:1.1rem;font-weight:800}
    .chat-head span{color:var(--ez-muted);font-size:.9rem}
    .chat-head-meta{
      display:flex;
      align-items:center;
      gap:10px;
      flex-wrap:wrap;
      margin-left:auto;
    }
    .chat-grid{
      display:grid;
      grid-template-columns:minmax(0,1fr) 260px;
      height:456px;
      min-height:0;
    }
    .chat-main{
      display:flex;
      flex-direction:column;
      min-width:0;
      min-height:0;
      border-right:1px solid var(--ez-grey-3);
    }
    .chat-messages{
      flex:1 1 auto;
      min-height:0;
      overflow:auto;
      padding:16px;
      display:flex;
      flex-direction:column;
      gap:10px;
      scroll-behavior:auto;
    }
    .chat-empty{
      color:var(--ez-muted);
      text-align:center;
      margin:auto;
    }
    .chat-message{
      max-width:min(78%, 680px);
      background:#202126;
      border:1px solid var(--ez-grey-3);
      border-radius:14px;
      padding:10px 12px;
      align-self:flex-start;
    }
    .chat-message.mine{
      align-self:flex-end;
      background:rgba(255,209,0,.13);
      border-color:rgba(255,209,0,.34);
    }
    .chat-message-actions{
      display:flex;
      gap:6px;
      align-items:center;
      flex:0 0 auto;
    }
    .chat-action-btn{
      border:1px solid var(--ez-grey-3);
      background:#151517;
      color:var(--ez-muted);
      border-radius:8px;
      padding:2px 7px;
      font-size:.76rem;
      line-height:1.35;
    }
    .chat-action-btn:hover{
      border-color:var(--ez-yellow);
      color:var(--ez-yellow);
    }
    .chat-action-btn.danger:hover{
      border-color:#ff5a5a;
      color:#ff8b8b;
    }
    .chat-message-top{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      color:var(--ez-muted);
      font-size:.78rem;
      margin-bottom:4px;
    }
    .chat-message-name{color:var(--ez-text);font-weight:800}
    .chat-message-text{
      white-space:pre-wrap;
      overflow-wrap:anywhere;
      line-height:1.35;
    }
    .chat-form{
      display:flex;
      gap:10px;
      padding:12px;
      border-top:1px solid var(--ez-grey-3);
      background:#141416;
    }
    .chat-form textarea{
      flex:1;
      min-height:46px;
      max-height:120px;
      resize:vertical;
      background:var(--ez-grey-2);
      border:1px solid var(--ez-grey-3);
      color:var(--ez-text);
      border-radius:12px;
      padding:10px 12px;
    }
    .chat-form textarea:focus{
      outline:none;
      border-color:var(--ez-yellow);
      box-shadow:0 0 0 .2rem rgba(255,209,0,.12);
    }
    .chat-form button{
      min-width:110px;
      background:linear-gradient(180deg,var(--ez-yellow),var(--ez-yellow-2));
      color:#121212;
      border:none;
      border-radius:12px;
      font-weight:800;
    }
    .chat-online{
      padding:14px;
      background:#141416;
      min-height:0;
      overflow-y:auto;
    }
    .online-list{
      display:flex;
      flex-direction:column;
      gap:8px;
      margin:0;
      padding:0;
      list-style:none;
    }
    .online-item{
      display:flex;
      align-items:center;
      gap:8px;
      padding:9px 10px;
      border:1px solid var(--ez-grey-3);
      border-radius:12px;
      background:#1b1c20;
      color:var(--ez-text);
      font-weight:700;
      overflow:hidden;
    }
    .online-dot{
      width:9px;
      height:9px;
      border-radius:50%;
      background:#35d07f;
      box-shadow:0 0 0 4px rgba(53,208,127,.13);
      flex:0 0 auto;
    }
    .online-name{
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }
    .chat-status{
      color:var(--ez-muted);
      font-size:.85rem;
      min-height:1.2em;
    }
    @media (max-width:900px){
      .chat-grid{
        grid-template-columns:1fr;
        height:auto;
      }
      .chat-main{border-right:none;border-bottom:1px solid var(--ez-grey-3)}
      .chat-online{
        order:-1;
        max-height:220px;
      }
      .chat-messages{
        flex:none;
        height:320px;
      }
    }
    @media (max-width:575px){
      .chat-form{flex-direction:column}
      .chat-form button{min-height:44px}
      .chat-message{max-width:92%}
    }

    .alert-info{ background:#0f1721; color:#cfe7ff; border:1px solid #27425f; border-radius:12px }
    .alert-warning{ background:#2b1f00; color:#ffd56a; border:1px solid #6b5200; border-radius:12px }
    .alert-success{ background:#10361b; color:#b7f3c2; border:1px solid #1d6c3b; border-radius:12px }
    .dashboard-notifications{
      max-height:260px;
      overflow:auto;
    }

    .card{ background:#171719; color:var(--ez-text); border:1px solid var(--ez-grey-3) }
    .badge.bg-success{ background:var(--ez-yellow); color:#121212 }

    @media (max-width: 991.98px){ .ez-sidebar{ position: static; max-height:none; } }
  </style>
</head>
<body>

<div class="ez-container">
  <div class="ez-layout">
    <?php if ($IS_ADMIN): ?>
      <aside class="ez-sidebar" aria-label="Admin meni">
        <div class="ez-sbar-head">
          <div class="bee"><img src="img/eduZoneLogo.png" alt=""></div>
          <h6 class="ez-sbar-title mb-0">Admin panel</h6>
        </div>
        <div class="ez-sbar-body">
          <a class="ez-sbar-link" href="dodaj_obavestenje.php">Dodaj obaveštenje</a>
          <a class="ez-sbar-link" href="pitanja_profesoru_admin.php">Pitanja učenika</a>
          <a class="ez-sbar-link" href="lekcije_admin.php">Lekcije (admin)</a>
          <a class="ez-sbar-link" href="korisni_materijali_admin.php">IT kutak</a>
          <a class="ez-sbar-link" href="radovi_ucenika_admin.php">Radovi učenika</a>
          <a class="ez-sbar-link" href="predmeti_admin.php">Predmeti</a>
          <a class="ez-sbar-link" href="dodaj_test.php">Dodaj test</a>
          <a class="ez-sbar-link" href="tests_admin.php">🛠 Upravljanje testovima</a>
          <a class="ez-sbar-link" href="dodeli_testove.php">📤 Dodeli testove</a>
          <a class="ez-sbar-link" href="korisnici_admin.php">Učenici (upravljanje)</a>
        </div>
      </aside>
    <?php endif; ?>

    <main class="ez-card">
	      <div class="ez-bar"></div>
	      <div class="ez-head">
	        <div class="ez-head-main">
	          <div class="ez-logo"><img src="img/eduZoneLogo.png" alt="EduZone"></div>
	          <div class="ez-head-copy">
	            <h1 class="ez-title"><?= htmlspecialchars($ime, ENT_QUOTES, 'UTF-8') ?></h1>
	            <p class="ez-sub">Pregledaj lekcije, obaveštenja i aktivne testove.</p>
	          </div>
	        </div>
	        <a class="logout-image-link" href="logout.php" title="Odjava" aria-label="Odjava">
	          <img src="img/logout-button.svg" alt="">
	        </a>
	      </div>

      <div class="ez-body">
        <div class="quick-links mb-3">
          <a class="ql-card text-decoration-none text-light" href="lekcije.php"><strong>Lekcije</strong></a>
          <a class="ql-card text-decoration-none text-light" href="testovi.php"><strong>Testovi</strong></a>
          <a class="ql-card text-decoration-none text-light" href="korisni-materijali"><strong>IT kutak</strong></a>
	          <a class="ql-card text-decoration-none text-light" href="radovi-ucenika"><strong>Radovi učenika</strong></a>
	          <a class="ql-card text-decoration-none text-light" href="domaci.php"><strong>Domaći</strong></a>
	          <a class="ql-card text-decoration-none text-light" href="chat.php"><strong>Pitaj profesora</strong></a>
	        </div>

        <?php
        include __DIR__ . '/db_connect.php';
        $razred    = $_SESSION["razred"] ?? null;
        $userEmail = $_SESSION["email"] ?? null;

        try {
        /* ====== AKTIVNI DODELJENI TESTOVI ====== */
        $pending = [];
        if (!empty($userEmail)) {
          $hasRez = false;
          if ($r = $conn->query("SHOW TABLES LIKE 'test_rezultati'")) { $hasRez = ($r->num_rows > 0); $r->free(); }
          $hasAnswersTbl = false;
          if ($r = $conn->query("SHOW TABLES LIKE 'odgovori_korisnika'")) { $hasAnswersTbl = ($r->num_rows > 0); $r->free(); }
          $hasDoneTbl = false;
          if ($r = $conn->query("SHOW TABLES LIKE 'odradjeni_testovi'")) { $hasDoneTbl = ($r->num_rows > 0); $r->free(); }
          $hasStartedTbl = false;
          if ($r = $conn->query("SHOW TABLES LIKE 'zapoceti_testovi'")) { $hasStartedTbl = ($r->num_rows > 0); $r->free(); }

          // opcione kolone u testovi
          $colZaOcenu = $conn->query("SHOW COLUMNS FROM testovi LIKE 'za_ocenu'")->num_rows>0;
          $colBrojP   = $conn->query("SHOW COLUMNS FROM testovi LIKE 'broj_pitanja'")->num_rows>0;
          $extraCols = '';
          if ($colZaOcenu) $extraCols .= ', t.za_ocenu';
          if ($colBrojP)   $extraCols .= ', t.broj_pitanja';

          $hasAssignedAt = false;
          if ($r = $conn->query("SHOW COLUMNS FROM dodeljeni_testovi LIKE 'assigned_at'")) { $hasAssignedAt = ($r->num_rows > 0); $r->free(); }
          $hasRezAssign = false;
          if ($hasRez && ($r = $conn->query("SHOW COLUMNS FROM test_rezultati LIKE 'assign_key'"))) { $hasRezAssign = ($r->num_rows > 0); $r->free(); }
          $hasAnswersAssign = false;
          if ($hasAnswersTbl && ($r = $conn->query("SHOW COLUMNS FROM odgovori_korisnika LIKE 'assign_key'"))) { $hasAnswersAssign = ($r->num_rows > 0); $r->free(); }
          $hasDoneAssign = false;
          if ($hasDoneTbl && ($r = $conn->query("SHOW COLUMNS FROM odradjeni_testovi LIKE 'assign_key'"))) { $hasDoneAssign = ($r->num_rows > 0); $r->free(); }
          $hasStartedAssign = false;
          if ($hasStartedTbl && ($r = $conn->query("SHOW COLUMNS FROM zapoceti_testovi LIKE 'assign_key'"))) { $hasStartedAssign = ($r->num_rows > 0); $r->free(); }
          $assignCol = $hasAssignedAt ? ', dt.assigned_at' : '';

          if ($hasRez) {
            $sql = "
              SELECT dt.test_id, t.naslov, t.razred, dt.attempts_allowed, dt.deadline {$assignCol},
                     COALESCE(r.used, 0) AS used_attempts
                     {$extraCols}
              FROM dodeljeni_testovi dt
              JOIN testovi t ON t.id = dt.test_id
              LEFT JOIN (
                SELECT test_id, email, ".($hasAssignedAt && $hasRezAssign ? "assign_key," : "")." COUNT(*) AS used
                FROM test_rezultati
                WHERE email = ?
                GROUP BY test_id, email ".($hasAssignedAt && $hasRezAssign ? ", assign_key" : "")."
              ) r ON r.test_id = dt.test_id AND r.email = dt.email ".($hasAssignedAt && $hasRezAssign ? "AND r.assign_key = dt.assigned_at" : "")."
              WHERE dt.email = ?
              ORDER BY COALESCE(dt.deadline, '9999-12-31') ASC, dt.test_id DESC
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $userEmail, $userEmail);
          } else {
            $sql = "
              SELECT dt.test_id, t.naslov, t.razred, dt.attempts_allowed, dt.deadline {$assignCol},
                     0 AS used_attempts
                     {$extraCols}
              FROM dodeljeni_testovi dt
              JOIN testovi t ON t.id = dt.test_id
              WHERE dt.email = ?
              ORDER BY COALESCE(dt.deadline, '9999-12-31') ASC, dt.test_id DESC
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $userEmail);
          }

          // === FIX: koristimo get_result() umesto bind_result()
          $stmt->execute();
          $res = $stmt->get_result();

          $nowTs = time();
          while ($row = $res->fetch_assoc()) {
            $tid = (int)$row['test_id'];
            $assignKey = $hasAssignedAt && !empty($row['assigned_at']) ? (string)$row['assigned_at'] : null;

            $allowed = is_null($row['attempts_allowed']) ? 1 : (int)$row['attempts_allowed'];
            $used    = (int)$row['used_attempts'];

            if ($hasRez && $hasRezAssign && $assignKey !== null) {
              $legacyRezStmt = $conn->prepare("SELECT COUNT(*) FROM test_rezultati WHERE email=? AND test_id=? AND assign_key IS NULL");
              $legacyRezStmt->bind_param("si", $userEmail, $tid);
              $legacyRezStmt->execute();
              $legacyRezStmt->bind_result($legacyRezUsed);
              $legacyRezStmt->fetch();
              $legacyRezStmt->close();
              $used = max($used, (int)$legacyRezUsed);
            }

            if ($hasAnswersTbl) {
              if ($hasAnswersAssign && $assignKey !== null) {
                $usedStmt = $conn->prepare("SELECT COUNT(DISTINCT attempt_no) FROM odgovori_korisnika WHERE email=? AND test_id=? AND (assign_key=? OR assign_key IS NULL)");
                $usedStmt->bind_param("sis", $userEmail, $tid, $assignKey);
              } elseif ($hasAnswersAssign) {
                $usedStmt = $conn->prepare("SELECT COUNT(DISTINCT attempt_no) FROM odgovori_korisnika WHERE email=? AND test_id=? AND assign_key IS NULL");
                $usedStmt->bind_param("si", $userEmail, $tid);
              } else {
                $usedStmt = $conn->prepare("SELECT COUNT(DISTINCT attempt_no) FROM odgovori_korisnika WHERE email=? AND test_id=?");
                $usedStmt->bind_param("si", $userEmail, $tid);
              }
              $usedStmt->execute();
              $usedStmt->bind_result($answersUsed);
              $usedStmt->fetch();
              $usedStmt->close();
              $used = max($used, (int)$answersUsed);
            }

            if ($hasDoneTbl) {
              if ($hasDoneAssign && $assignKey !== null) {
                $doneStmt = $conn->prepare("SELECT COUNT(*) FROM odradjeni_testovi WHERE email=? AND test_id=? AND (assign_key=? OR assign_key IS NULL)");
                $doneStmt->bind_param("sis", $userEmail, $tid, $assignKey);
              } elseif ($hasDoneAssign) {
                $doneStmt = $conn->prepare("SELECT COUNT(*) FROM odradjeni_testovi WHERE email=? AND test_id=? AND assign_key IS NULL");
                $doneStmt->bind_param("si", $userEmail, $tid);
              } else {
                $doneStmt = $conn->prepare("SELECT COUNT(*) FROM odradjeni_testovi WHERE email=? AND test_id=?");
                $doneStmt->bind_param("si", $userEmail, $tid);
              }
              $doneStmt->execute();
              $doneStmt->bind_result($doneUsed);
              $doneStmt->fetch();
              $doneStmt->close();
              $used = max($used, (int)$doneUsed);
            }

            if ($hasStartedTbl) {
              if ($hasStartedAssign && $assignKey !== null) {
                $startedStmt = $conn->prepare("SELECT COUNT(*) FROM zapoceti_testovi WHERE email=? AND test_id=? AND (assign_key=? OR assign_key IS NULL)");
                $startedStmt->bind_param("sis", $userEmail, $tid, $assignKey);
              } elseif ($hasStartedAssign) {
                $startedStmt = $conn->prepare("SELECT COUNT(*) FROM zapoceti_testovi WHERE email=? AND test_id=? AND assign_key IS NULL");
                $startedStmt->bind_param("si", $userEmail, $tid);
              } else {
                $startedStmt = $conn->prepare("SELECT COUNT(*) FROM zapoceti_testovi WHERE email=? AND test_id=?");
                $startedStmt->bind_param("si", $userEmail, $tid);
              }
              $startedStmt->execute();
              $startedStmt->bind_result($startedUsed);
              $startedStmt->fetch();
              $startedStmt->close();
              $used = max($used, (int)$startedUsed);
            }

            $deadlineOk = true;
            if (!empty($row['deadline'])) { $deadlineOk = (strtotime($row['deadline']) >= $nowTs); }
            $hasAttempts = ($used < $allowed);

            if ($deadlineOk && $hasAttempts) {
              $item = [
                'test_id'  => $tid,
                'naslov'   => $row['naslov'],
                'razred'   => $row['razred'],
                'deadline' => $row['deadline'],
                'assigned_at' => $assignKey
              ];
              if (isset($row['za_ocenu']))     $item['za_ocenu']     = (int)$row['za_ocenu'];
              if (isset($row['broj_pitanja'])) $item['broj_pitanja'] = (int)$row['broj_pitanja'];
              $pending[] = $item;
            }
          }
          $stmt->close();
        }

        if (!empty($pending)): ?>
          <div class="alert alert-warning shadow-sm">
            <div class="d-flex align-items-center gap-2">
              <span style="font-size:1.4rem;">⚠️</span>
              <div>
                <strong>Imate aktivne testove za rad!</strong>
                <div class="small">Kliknite na dugme ispod da započnete.</div>
              </div>
            </div>
            <hr>
            <div class="row g-2">
              <?php foreach ($pending as $p): ?>
                <div class="col-12 col-md-6 col-lg-4">
                  <div class="card border-0 shadow-sm">
                    <div class="card-body">
                      <div class="fw-bold mb-1"><?= htmlspecialchars($p['naslov']) ?></div>
                      <div class="text-muted small mb-2">Razred: <?= htmlspecialchars($p['razred']) ?></div>

                      <?php if (isset($p['za_ocenu']) && (int)$p['za_ocenu']===1): ?>
                        <span class="badge bg-warning text-dark me-1">Test za ocenu</span>
                      <?php endif; ?>
                      <?php if (isset($p['broj_pitanja']) && (int)$p['broj_pitanja']>0): ?>
                        <span class="badge bg-secondary"><?= (int)$p['broj_pitanja'] ?> pitanja</span>
                      <?php endif; ?>

                      <?php if (!empty($p['deadline'])): ?>
                        <div class="small mt-2">Rok: <?= htmlspecialchars(date('d.m.Y H:i', strtotime($p['deadline']))) ?></div>
                      <?php endif; ?>
                      <a class="btn btn-sm btn-primary mt-2" href="start_test.php?id=<?= (int)$p['test_id'] ?><?= !empty($p['assigned_at']) ? '&ak=' . urlencode($p['assigned_at']) : '' ?>">Započni test</a>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php
        /* ====== OBAVEŠTENJA ====== */
        if ($razred || $IS_ADMIN) {
          if (isset($_GET["obrisi_obavestenje"]) && $IS_ADMIN) {
            $obrisi_id = intval($_GET["obrisi_obavestenje"]);
            $conn->query("DELETE FROM obavestenja WHERE id = $obrisi_id");
            echo "<div class='alert alert-success'>✅ Obaveštenje obrisano.</div>";
          }

          if ($IS_ADMIN) {
              $sql = "SELECT * FROM obavestenja ORDER BY datum DESC";
              $result = $conn->query($sql);
          } else {
              $sql = "SELECT * FROM obavestenja WHERE razred = ? AND (vazi_do IS NULL OR vazi_do >= CURDATE()) ORDER BY datum DESC";
              $stmt = $conn->prepare($sql);
              $stmt->bind_param("s", $razred);
              $stmt->execute();
              $result = $stmt->get_result();
          }

          if ($result->num_rows > 0) {
              echo "<div class='alert alert-info dashboard-notifications'><h5>📢 Obaveštenja:</h5>";
              while ($row = $result->fetch_assoc()) {
                  echo "<div><strong>" . htmlspecialchars($row["naslov"]) . "</strong><br>";
                  echo nl2br(htmlspecialchars($row["tekst"])) . "</div>";
                  if ($IS_ADMIN) {
                      echo "<div class='text-end'><a class='btn btn-sm btn-outline-ez mt-1' href='?obrisi_obavestenje={$row["id"]}'>🗑 Obriši</a></div><hr>";
                  } else {
                      echo "<hr>";
                  }
              }
              echo "</div>";
          }

          if (isset($stmt)) { $stmt->close(); }
        }
        } catch (Throwable $e) {
          error_log('EduZone dashboard content error: ' . $e->getMessage());
        }
        ?>

        <section class="chat-panel" id="javni-chat">
          <div class="chat-head">
            <div class="chat-head-meta">
              <span class="badge bg-success" id="chatOnlineBadge">Online: <?= isset($ONLINE_TOTAL) ? (int)$ONLINE_TOTAL : 0 ?></span>
              <div class="chat-status" id="chatStatus"></div>
            </div>
          </div>
          <div class="chat-grid">
            <div class="chat-main">
              <div class="chat-messages" id="chatMessages">
                <?php if (!$initialChatMessages): ?>
                  <div class="chat-empty">Još nema poruka.</div>
                <?php else: ?>
                  <?php foreach ($initialChatMessages as $message): ?>
                    <?php $mine = ($message['sender_email'] === ($_SESSION['email'] ?? '')); ?>
                    <article class="chat-message<?= $mine ? ' mine' : '' ?>">
                      <div class="chat-message-top">
                        <span class="chat-message-name"><?= dashboard_h(dashboard_chat_name($message['sender_email'], $ADMIN_EMAIL)) ?></span>
                        <span><?= dashboard_h(dashboard_chat_time($message['created_at'])) ?></span>
                      </div>
                      <div class="chat-message-text"><?= nl2br(dashboard_h($message['message'])) ?></div>
                      <?php if ($IS_ADMIN): ?>
                        <div class="chat-message-actions mt-2">
                          <button type="button" class="chat-action-btn" data-chat-edit="<?= (int)$message['id'] ?>">Izmeni</button>
                          <button type="button" class="chat-action-btn danger" data-chat-delete="<?= (int)$message['id'] ?>">Obriši</button>
                        </div>
                      <?php endif; ?>
                    </article>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
              <form class="chat-form" id="chatForm" method="post" action="dashboard#javni-chat" autocomplete="off">
                <input type="hidden" name="chat_action" value="send">
                <textarea id="chatInput" name="chat_message" maxlength="1000" placeholder="Napiši poruku..." required></textarea>
                <button type="submit">Pošalji</button>
              </form>
            </div>
            <aside class="chat-online" aria-label="Online učenici">
              <ul class="online-list" id="onlineList">
                <?php if (!$initialOnlineStudents): ?>
                  <li class="online-item"><span class="online-dot"></span><span class="online-name">Nema online učenika</span></li>
                <?php else: ?>
                  <?php foreach ($initialOnlineStudents as $studentEmail): ?>
                    <li class="online-item" title="<?= dashboard_h($studentEmail) ?>">
                      <span class="online-dot"></span>
                      <span class="online-name"><?= dashboard_h(dashboard_chat_name($studentEmail, $ADMIN_EMAIL)) ?></span>
                    </li>
                  <?php endforeach; ?>
                <?php endif; ?>
              </ul>
            </aside>
          </div>
        </section>

        <?php

        /* ====== PROMO POSLEDNJI AKTIVAN TEST (prioritet: za ocenu) ====== */
        if ($razred) {
          $hasZaOcenu = $conn->query("SHOW COLUMNS FROM testovi LIKE 'za_ocenu'")->num_rows>0;

          if ($hasZaOcenu) {
            $sql = "SELECT id, naslov, trajanje FROM testovi
                    WHERE aktivan = 1 AND razred = ? AND za_ocenu = 1
                    ORDER BY id DESC LIMIT 1";
          } else {
            $sql = "SELECT id, naslov, trajanje FROM testovi
                    WHERE aktivan = 1 AND razred = ?
                    ORDER BY id DESC LIMIT 1";
          }
          $stmt = $conn->prepare($sql);
          $stmt->bind_param("s", $razred);
          $stmt->execute();
          $result = $stmt->get_result();
          if ($result->num_rows > 0) {
              $test = $result->fetch_assoc();

              // ne dupliraj ako je već u pending
              $alreadyPending = false;
              foreach ($pending as $p) {
                if ((int)$p['test_id'] === (int)$test['id']) { $alreadyPending = true; break; }
              }

              if (!$alreadyPending) {
                $check = $conn->prepare("SELECT 1 FROM odradjeni_testovi WHERE email = ? AND test_id = ?");
                $check->bind_param("si", $_SESSION["email"], $test["id"]);
                $check->execute();
                $check_result = $check->get_result();
                if ($check_result->num_rows === 0) {
                    echo "<div class='alert alert-warning d-flex justify-content-between align-items-center'>";
                    echo "<div>🧪 " . ($hasZaOcenu ? "<strong>Test za ocenu</strong>: " : "Novi test: ") .
                         htmlspecialchars($test["naslov"]) .
                         " (" . htmlspecialchars($test["trajanje"]) . " min)</div>";
                    echo "<a href='start_test.php?id={$test["id"]}' class='btn btn-sm btn-primary'>🎯 Počni</a></div>";
                }
                $check->close();
              }
          }
          $stmt->close();
          $conn->close();
        }
        ?>
      </div>
    </main>
  </div>
</div>

<script>
const chatMessages = document.getElementById('chatMessages');
const onlineList = document.getElementById('onlineList');
const chatForm = document.getElementById('chatForm');
const chatInput = document.getElementById('chatInput');
const chatStatus = document.getElementById('chatStatus');
const chatOnlineBadge = document.getElementById('chatOnlineBadge');
const chatApiUrl = 'chat_api';
let lastMessageSignature = <?= json_encode(implode(',', array_map(static fn($message) => (int)$message['id'] . ':' . md5((string)$message['message']), $initialChatMessages))) ?>;

if (!chatMessages || !onlineList || !chatForm || !chatInput || !chatStatus) {
  console.error('EduZone chat elementi nisu pronađeni.');
} else {

function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, function (char) {
    return {'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'}[char];
  });
}

function renderMessages(messages) {
  messages = Array.isArray(messages) ? messages : [];
  const signature = messages.map(function (message) { return message.id + ':' + message.message; }).join(',');
  if (signature === lastMessageSignature) return;

  const shouldStickToBottom = chatMessages.scrollTop + chatMessages.clientHeight >= chatMessages.scrollHeight - 60;
  lastMessageSignature = signature;

  if (!messages.length) {
    chatMessages.innerHTML = '<div class="chat-empty">Još nema poruka.</div>';
    return;
  }

  chatMessages.innerHTML = messages.map(function (message) {
    const actions = message.can_manage
      ? '<div class="chat-message-actions mt-2">' +
          '<button type="button" class="chat-action-btn" data-chat-edit="' + String(message.id) + '">Izmeni</button>' +
          '<button type="button" class="chat-action-btn danger" data-chat-delete="' + String(message.id) + '">Obriši</button>' +
        '</div>'
      : '';

    return '<article class="chat-message' + (message.mine ? ' mine' : '') + '">' +
      '<div class="chat-message-top">' +
        '<span class="chat-message-name">' + escapeHtml(message.sender_name) + '</span>' +
        '<span>' + escapeHtml(message.time) + '</span>' +
      '</div>' +
      '<div class="chat-message-text">' + escapeHtml(message.message) + '</div>' +
      actions +
    '</article>';
  }).join('');

  if (shouldStickToBottom) {
    chatMessages.scrollTop = chatMessages.scrollHeight;
  }
}

function scrollChatToBottom() {
  chatMessages.scrollTop = chatMessages.scrollHeight;
}

function renderOnline(students) {
  students = Array.isArray(students) ? students : [];
  if (!students.length) {
    onlineList.innerHTML = '<li class="online-item"><span class="online-dot"></span><span class="online-name">Nema online učenika</span></li>';
    return;
  }

  onlineList.innerHTML = students.map(function (student) {
    return '<li class="online-item" title="' + escapeHtml(student.email) + '">' +
      '<span class="online-dot"></span>' +
      '<span class="online-name">' + escapeHtml(student.name) + '</span>' +
    '</li>';
  }).join('');
}

function renderOnlineCount(count) {
  if (typeof count === 'undefined') return;
  if (chatOnlineBadge) chatOnlineBadge.textContent = 'Online: ' + count;
}

function renderChatError(message) {
  chatMessages.innerHTML = '<div class="chat-empty">' + escapeHtml(message) + '</div>';
  onlineList.innerHTML = '<li class="online-item"><span class="online-dot"></span><span class="online-name">Nije dostupno</span></li>';
  chatStatus.textContent = message;
}

async function fetchChatJson(url, options) {
  const response = await fetch(url, options);
  const raw = await response.text();
  let data = null;

  try {
    data = JSON.parse(raw);
  } catch (error) {
    const preview = raw.trim().slice(0, 160);
    throw new Error(preview ? 'Chat API ne vraća JSON: ' + preview : 'Chat API je vratio prazan odgovor.');
  }

  if (!response.ok || !data.ok) {
    throw new Error(data.error || ('Chat nije dostupan (' + response.status + ').'));
  }

  return data;
}

async function syncChat() {
  try {
    const data = await fetchChatJson(chatApiUrl + '?action=sync&_=' + Date.now(), {
      cache: 'no-store',
      credentials: 'same-origin'
    });

    renderOnlineCount(data.online_total);
    renderMessages(data.messages);
    renderOnline(data.online);
    chatStatus.textContent = '';
  } catch (error) {
    renderChatError(error.message || 'Chat trenutno nije dostupan.');
  }
}

chatForm.addEventListener('submit', async function (event) {
  event.preventDefault();
  const message = chatInput.value.trim();
  if (!message) return;

  const body = new URLSearchParams();
  body.set('action', 'send');
  body.set('message', message);

  chatInput.disabled = true;
  try {
    const data = await fetchChatJson(chatApiUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
      body: body.toString()
    });

    chatInput.value = '';
    renderOnlineCount(data.online_total);
    renderMessages(data.messages);
    renderOnline(data.online);
    scrollChatToBottom();
    chatStatus.textContent = '';
  } catch (error) {
    chatStatus.textContent = error.message || 'Slanje nije uspelo.';
    HTMLFormElement.prototype.submit.call(chatForm);
  } finally {
    chatInput.disabled = false;
    chatInput.focus();
  }
});

chatMessages.addEventListener('click', async function (event) {
  const editButton = event.target.closest('[data-chat-edit]');
  const deleteButton = event.target.closest('[data-chat-delete]');
  const button = editButton || deleteButton;
  if (!button) return;

  const id = button.getAttribute(editButton ? 'data-chat-edit' : 'data-chat-delete');
  if (!id) return;

  const body = new URLSearchParams();
  body.set('id', id);

  if (editButton) {
    const article = editButton.closest('.chat-message');
    const textEl = article ? article.querySelector('.chat-message-text') : null;
    const currentMessage = textEl ? textEl.textContent : '';
    const nextMessage = prompt('Izmeni poruku:', currentMessage);
    if (nextMessage === null) return;

    const trimmedMessage = nextMessage.trim();
    if (!trimmedMessage) {
      chatStatus.textContent = 'Poruka ne može biti prazna.';
      return;
    }

    body.set('action', 'edit');
    body.set('message', trimmedMessage);
  } else {
    if (!confirm('Obrisati ovu poruku?')) return;
    body.set('action', 'delete');
  }

  button.disabled = true;
  try {
    const data = await fetchChatJson(chatApiUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
      body: body.toString()
    });

    renderOnlineCount(data.online_total);
    lastMessageSignature = '';
    renderMessages(data.messages);
    renderOnline(data.online);
    chatStatus.textContent = '';
  } catch (error) {
    chatStatus.textContent = error.message || 'Akcija nije uspela.';
  } finally {
    button.disabled = false;
  }
});

scrollChatToBottom();
requestAnimationFrame(scrollChatToBottom);
syncChat();
setInterval(syncChat, 2500);
}
</script>
</body>
</html>
