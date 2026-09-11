<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/* ========= PODEŠAVANJE ========= */
const SCHOOL_DOMAIN = 'gimeko.edu.rs';   // vaš domen (koristi se za username -> email)
require_once __DIR__ . '/admin_config.php';

/* ========= HELPERI ========= */
function normalize_login_to_email(string $login, string $domain = SCHOOL_DOMAIN): string {
    $login = trim(strtolower($login));
    if ($login === '') return '';
    // ako korisnik unese ceo email, koristi ga
    if (strpos($login, '@') !== false) return $login;
    // u suprotnom, tretiraj kao "ime.prezime"
    return $login . '@' . $domain;
}

/* ========= LOGIN ========= */
$greska = '';


if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // podrži i staro ime polja 'email' (ako negde ostane)
    $rawLogin = trim($_POST["login"] ?? ($_POST["email"] ?? ''));
    $lozinka  = trim($_POST["lozinka"] ?? '');

    $email = normalize_login_to_email($rawLogin);

    require __DIR__ . '/db_connect.php';

    $sql  = "SELECT email, razred FROM korisnici WHERE email = ? AND lozinka = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $greska = "Greška upita: " . $conn->error;
    } else {
        $stmt->bind_param("ss", $email, $lozinka);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 1) {
            $stmt->bind_result($dbEmail, $dbRazred);
            $stmt->fetch();

            session_regenerate_id(true);
            $_SESSION["email"]    = $dbEmail;                       // nastavljamo da koristimo email svuda
            $_SESSION["username"] = explode('@', $dbEmail)[0] ?? ''; // zgodno za prikaz
            $_SESSION["razred"]   = $dbRazred ?? null;

            $stmt->close(); $conn->close();
            header("Location: dashboard.php");
            exit;
        } else {
            $greska = "Pogrešno korisničko ime ili lozinka!";
        }

        $stmt->close();
        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="sr">
<head>
<?php require_once __DIR__ . '/analytics.php'; ?>
  <meta charset="UTF-8">
  <title>Prijava učenika</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <!-- Bootstrap -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

  <!-- Crno-žuta tema -->
  <style>
    :root{
      --ez-yellow: #ffd100;   /* glavna žuta */
      --ez-yellow-2:#ffbf00;  /* tamnija žuta */
      --ez-black:  #0f0f10;   /* pozadina */
      --ez-grey-1: #151517;   /* kartica */
      --ez-grey-2: #1f2023;   /* input bg */
      --ez-grey-3: #2b2c31;   /* border */
      --ez-text:   #f6f6f6;   /* tekst */
      --ez-muted:  #b6b6b6;   /* sekundarni tekst */
    }
    html, body { height: 100%; }
    body{
      background: radial-gradient(1100px 600px at 85% -10%, rgba(255,209,0,0.15), transparent 60%),
                  radial-gradient(900px 500px at -10% 100%, rgba(255,209,0,0.12), transparent 60%),
                  var(--ez-black);
      color: var(--ez-text);
      font-family: system-ui, -apple-system, Segoe UI, Roboto, "Helvetica Neue", Arial, "Noto Sans", "Liberation Sans", sans-serif;
    }
    .ez-wrap{
      min-height: 100%;
      display: grid;
      place-items: center;
      padding: 2rem 1rem;
    }
    .ez-card{
      width: min(420px, 95vw);
      background: linear-gradient(180deg, var(--ez-grey-1), #121214 60%);
      border: 1px solid var(--ez-grey-3);
      border-radius: 18px;
      box-shadow: 0 10px 35px rgba(0,0,0,.45), 0 0 0 1px rgba(255,209,0,.05) inset;
      overflow: hidden;
    }
    .ez-card .ez-bar{
      height: 6px;
      background: linear-gradient(90deg, var(--ez-yellow), var(--ez-yellow-2));
    }
    .ez-card .ez-head{
      padding: 1.25rem 1.25rem 0.75rem;
      display: flex; align-items: center; gap: .75rem;
    }

    /* NOVO: pčelica umesto “EZ” */
    .ez-logo{
      width: 48px; height: 48px; border-radius: 10px;
      background: rgba(255,209,0,.12);
      display: grid; place-items: center;
      box-shadow: 0 6px 18px rgba(255,209,0,.25);
      border: 1px solid rgba(255,209,0,.28);
      overflow: hidden;
    }
    .ez-logo img{
      width: 34px; height: 34px; object-fit: contain;
      display: block;
      filter: none; /* zadrži originalne boje logotipa */
    }

    .ez-title{ font-weight: 700; margin: 0; }
    .ez-sub{ color: var(--ez-muted); font-size: .92rem; margin: 0; }

    .ez-body{ padding: 1rem 1.25rem 1.25rem; }

    .form-label{
      color: var(--ez-muted);
      font-size: .9rem;
    }
    .form-control{
      background-color: var(--ez-grey-2);
      border: 1px solid var(--ez-grey-3);
      color: var(--ez-text);
      border-radius: 10px;
      padding: .7rem .9rem;
    }
    .form-control::placeholder{ color: #8a8a8a; }
    .form-control:focus{
      background-color: var(--ez-grey-2);
      border-color: var(--ez-yellow);
      box-shadow: 0 0 0 .2rem rgba(255,209,0,.15);
      color: var(--ez-text);
    }

    .btn-ez{
      --bs-btn-padding-y:.7rem;
      --bs-btn-padding-x:1rem;
      --bs-btn-font-weight:700;
      background: linear-gradient(180deg, var(--ez-yellow), var(--ez-yellow-2));
      border: none;
      color: #121212;
      border-radius: 12px;
      transition: transform .06s ease, box-shadow .2s ease;
      box-shadow: 0 8px 18px rgba(255,209,0,.25), 0 0 0 1px rgba(0,0,0,.1) inset;
    }
    .btn-ez:hover{
      transform: translateY(-1px);
      box-shadow: 0 10px 22px rgba(255,209,0,.32);
      color: #000;
    }
    .btn-ez:active{ transform: translateY(0); }

    .ez-footer{
      padding: .9rem 1.25rem 1.25rem;
      display: flex; justify-content: space-between; align-items: center;
      color: var(--ez-muted);
      font-size: .9rem;
    }
    .ez-link{
      color: var(--ez-yellow); text-decoration: none; font-weight: 600;
    }
    .ez-link:hover{ color: var(--ez-yellow-2); }

    .alert-ez{
      background: #2b1f00;
      color: #ffd56a;
      border: 1px solid #6b5200;
      border-radius: 12px;
    }

    .ez-eye{
      cursor: pointer;
      user-select: none;
      position: absolute; right: .75rem; top: 50%;
      transform: translateY(-50%);
      color: #c9a800;
    }
    .position-relative .form-control{ padding-right: 2.3rem; }
  </style>
</head>
<body>
  <div class="ez-wrap">
    <div class="ez-card">
      <div class="ez-bar"></div>
      <div class="ez-head">
        <!-- OVDE JE ZAMENA: pčelica iz navigacije -->
        <div class="ez-logo">
          <img src="img/eduZoneLogo.png" alt="EduZone logo">
        </div>
        <div>
          <h1 class="ez-title">EduZone</h1>
          <p class="ez-sub">Prijava učenika</p>
        </div>
      </div>

      <div class="ez-body">
        <?php if (!empty($greska)): ?>
          <div class="alert alert-ez mb-3" role="alert">
            <?= htmlspecialchars($greska, ENT_QUOTES, 'UTF-8') ?>
          </div>
        <?php endif; ?>

        <form method="POST" novalidate>
          <div class="mb-3">
            <label class="form-label">Korisničko ime (ime.prezime)</label>
            <input type="text" name="login" class="form-control" placeholder="npr. milan.mitic" autocomplete="username" required>
          </div>

          <div class="mb-3 position-relative">
            <label class="form-label">Lozinka</label>
            <input type="password" name="lozinka" id="pass" class="form-control" autocomplete="current-password" required>
            <span class="ez-eye" id="toggleEye" title="Prikaži/ sakrij">👁️</span>
          </div>

	          <button type="submit" class="btn btn-ez w-100">Prijavi se</button>
	        </form>

	      </div>

      <div class="ez-footer">
        <span>© <?= date('Y') ?> EduZone</span>
      </div>
    </div>
  </div>

  <!-- Bootstrap bundle -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <!-- Sitni UX: prikaz lozinke -->
  <script>
    (function(){
      const eye = document.getElementById('toggleEye');
      const pass = document.getElementById('pass');
      if (!eye || !pass) return;
      eye.addEventListener('click', function(){
        const type = pass.getAttribute('type') === 'password' ? 'text' : 'password';
        pass.setAttribute('type', type);
        eye.textContent = (type === 'password') ? '👁️' : '🙈';
      });
    })();
  </script>
</body>
</html>
