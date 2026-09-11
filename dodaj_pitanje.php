<?php
session_start();
require_once __DIR__ . '/admin_config.php';
require __DIR__ . '/db_connect.php';

if (!ez_is_admin()) {
    exit("⛔ Pristup dozvoljen samo nastavniku.");
}

$test_id = isset($_GET['test_id']) ? (int)$_GET['test_id'] : 0;
if (!$test_id) { exit("Nedostaje test_id."); }

// Učitaj test (naslov u UI)
$test = $conn->query("SELECT id, naslov FROM testovi WHERE id = $test_id")->fetch_assoc();
if (!$test) { exit("Test ne postoji."); }

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tekst = trim($_POST['tekst'] ?? '');
    $tip   = $_POST['tip'] ?? 'radio';

    if ($tekst === '' || !in_array($tip, ['radio','checkbox','text'], true)) {
        $msg = "⚠️ Popunite tekst pitanja i izaberite ispravan tip.";
    } else {
        // upiši pitanje
        $q = $conn->prepare("INSERT INTO pitanja (test_id, tekst, tip) VALUES (?,?,?)");
        $q->bind_param("iss", $test_id, $tekst, $tip);
        $q->execute();
        $pitanje_id = $q->insert_id;
        $q->close();

        if ($tip === 'text') {
            // TEXT: svaka linija je dozvoljen tačan odgovor
            $tacniTekstovi = trim($_POST['tacni_text'] ?? '');
            if ($tacniTekstovi !== '') {
                $lines = preg_split('/\r\n|\r|\n/', $tacniTekstovi);
                $ins = $conn->prepare("INSERT INTO odgovori (pitanje_id, tekst, tacan) VALUES (?,?,1)");
                foreach ($lines as $line) {
                    $ans = trim($line);
                    if ($ans === '') continue;
                    $ins->bind_param("is", $pitanje_id, $ans);
                    $ins->execute();
                }
                $ins->close();
            }
        } else {
            // RADIO / CHECKBOX: ponuđeni + obeleženi tačni
            $offered = $_POST['odgovori'] ?? [];       // [idx => tekst]
            $ins = $conn->prepare("INSERT INTO odgovori (pitanje_id, tekst, tacan) VALUES (?,?,?)");

            if ($tip === 'radio') {
                // tačno samo jedno: radio marker
                $chosen = isset($_POST['tacan_one']) ? (string)$_POST['tacan_one'] : '';
                foreach ($offered as $idx => $txt) {
                    $ans = trim($txt);
                    if ($ans === '') continue;
                    $isTrue = ((string)$idx === $chosen) ? 1 : 0;
                    $ins->bind_param("isi", $pitanje_id, $ans, $isTrue);
                    $ins->execute();
                }
            } else { // checkbox
                $tacni = $_POST['tacni'] ?? []; // niz indeksa
                foreach ($offered as $idx => $txt) {
                    $ans = trim($txt);
                    if ($ans === '') continue;
                    $isTrue = in_array((string)$idx, (array)$tacni, true) ? 1 : 0;
                    $ins->bind_param("isi", $pitanje_id, $ans, $isTrue);
                    $ins->execute();
                }
            }
            $ins->close();
        }

        $msg = "✅ Pitanje dodato.";
    }
}
?>
<!doctype html>
<html lang="sr">
<head>
<?php require_once __DIR__ . '/analytics.php'; ?>
<meta charset="utf-8">
<title>Dodaj pitanje</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light p-4">
<div class="container">
  <div class="text-center mb-3">
    <a href="dashboard.php" class="btn btn-secondary">⬅️ Nazad na početnu</a>
  </div>

  <h3 class="mb-3">➕ Dodaj pitanje — Test: <?= htmlspecialchars($test['naslov']) ?></h3>
  <?php if ($msg): ?><div class="alert alert-info"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

  <form method="post" class="bg-white p-3 rounded shadow-sm">
    <div class="mb-3">
      <label class="form-label">Tekst pitanja</label>
      <textarea name="tekst" class="form-control" rows="2" required></textarea>
    </div>

    <div class="mb-3">
      <label class="form-label">Tip pitanja</label>
      <select name="tip" id="tip" class="form-select" required>
        <option value="radio">Radio (jedan tačan)</option>
        <option value="checkbox">Checkbox (više tačnih)</option>
        <option value="text">Tekst (slobodan unos)</option>
      </select>
    </div>

    <!-- RADIO/CHECKBOX BLOK -->
    <div id="blok-radio-checkbox">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <label class="form-label mb-0">Ponuđeni odgovori</label>
        <button class="btn btn-sm btn-outline-primary" type="button" id="addAnswer">Dodaj odgovor</button>
      </div>

      <div id="answers">
        <!-- red 0 -->
        <div class="input-group mb-2">
          <span class="input-group-text">
            <input class="mark-true" type="radio" name="tacan_one" value="0" title="Označi kao tačno">
          </span>
          <input type="text" name="odgovori[0]" class="form-control" placeholder="Odgovor 1">
        </div>
        <!-- red 1 -->
        <div class="input-group mb-2">
          <span class="input-group-text">
            <input class="mark-true" type="radio" name="tacan_one" value="1" title="Označi kao tačno">
          </span>
          <input type="text" name="odgovori[1]" class="form-control" placeholder="Odgovor 2">
        </div>
      </div>

      <div class="form-text">Za tip “radio” može biti označen tačno jedan odgovor.</div>
    </div>

    <!-- TEXT BLOK -->
    <div id="blok-text" class="d-none">
      <label class="form-label">Tačni tekstualni odgovori (jedan po liniji)</label>
      <textarea name="tacni_text" class="form-control" rows="4" placeholder="npr. JavaScript&#10;JS"></textarea>
    </div>

    <div class="mt-3 d-flex gap-2">
      <button class="btn btn-success">💾 Sačuvaj i dodaj sledeće</button>
      <a class="btn btn-outline-secondary" href="dashboard.php">Završi i nazad</a>
    </div>
  </form>
</div>

<script>
const tip = document.getElementById('tip');
const blokRC = document.getElementById('blok-radio-checkbox');
const blokText = document.getElementById('blok-text');
const answersWrap = document.getElementById('answers');

function applyMarkerTypes() {
  const markers = answersWrap.querySelectorAll('.mark-true');
  if (tip.value === 'radio') { markers.forEach(m => { m.type = 'radio'; m.name = 'tacan_one'; }); }
  else { markers.forEach(m => { m.type = 'checkbox'; m.name = 'tacni[]'; }); }
}
function toggleBlocks(){
  if (tip.value === 'text') { blokRC.classList.add('d-none'); blokText.classList.remove('d-none'); }
  else { blokText.classList.add('d-none'); blokRC.classList.remove('d-none'); applyMarkerTypes(); }
}
tip.addEventListener('change', toggleBlocks); toggleBlocks();

let idx = 2;
document.getElementById('addAnswer').onclick = function(){
  const wrap = document.createElement('div');
  wrap.className = 'input-group mb-2';
  wrap.innerHTML = `
    <span class="input-group-text">
      <input class="mark-true" ${tip.value === 'radio' ? 'type="radio" name="tacan_one"' : 'type="checkbox" name="tacni[]"' } value="${idx}" title="Označi kao tačno">
    </span>
    <input type="text" name="odgovori[${idx}]" class="form-control" placeholder="Odgovor ${idx+1}">
  `;
  answersWrap.appendChild(wrap);
  idx++;
};
</script>
</body>
</html>
