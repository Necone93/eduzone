<?php
session_start();
require_once __DIR__ . '/admin_config.php';
if (!ez_is_admin()) { exit('⛔'); }
require __DIR__.'/db_connect.php';
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$test_id = (int)($_GET['test_id'] ?? $_POST['test_id'] ?? 0);
$qid     = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if(!$test_id) exit('Nedostaje test_id.');

$test = $conn->query("SELECT id, naslov FROM testovi WHERE id={$test_id}")->fetch_assoc();
if(!$test) exit('Test ne postoji.');

$mode='add'; $tekst=''; $tip='radio'; $answers=[]; // answers: [['tekst'=>..., 'tacan'=>0/1], ...]
$err='';

if ($qid){
  $mode='edit';
  $p=$conn->prepare("SELECT id, tekst, tip FROM pitanja WHERE id=? AND test_id=?");
  $p->bind_param("ii",$qid,$test_id); $p->execute(); $pr=$p->get_result()->fetch_assoc(); $p->close();
  if(!$pr) exit('Pitanje ne postoji.');
  $tekst=$pr['tekst']; $tip=$pr['tip'];
  $o=$conn->prepare("SELECT tekst, tacan FROM odgovori WHERE pitanje_id=? ORDER BY id ASC");
  $o->bind_param("i",$qid); $o->execute(); $or=$o->get_result();
  while($row=$or->fetch_assoc()) $answers[]=$row;
  $o->close();
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  $tekst = trim($_POST['tekst'] ?? '');
  $tip   = $_POST['tip'] ?? 'radio';

  // === EDUZONE PATCH: pripremi podatke i validaciju PRE izmene baze
  $ansRaw = $_POST['ans'] ?? [];                     // niz tekstova (radio/checkbox)
  $ansNorm = [];
  foreach($ansRaw as $i=>$txt){
    $t=trim((string)$txt);
    if($t!=='') $ansNorm[(string)$i]=$t;
  }

  $okRadio = $_POST['ok'] ?? '';                     // index jedne tačne (radio)
  $okChecks = array_map('strval', (array)($_POST['ok'] ?? []));  // više tačnih (checkbox)
  $textCorrect = trim($_POST['text_correct'] ?? ''); // tačan tekst (text)

  // VALIDACIJA
  if(!$tekst){
    $err='Unesi tekst pitanja.';
  } else {
    if($tip==='radio'){
      if(count($ansNorm) < 2){
        $err='Za tip "Jedan tačan (radio)" unesi bar 2 ponuđena odgovora.';
      } elseif ($okRadio === '' || !array_key_exists((string)$okRadio, $ansNorm)) {
        $err='Označi koji je odgovor tačan (radio).';
      }
    } elseif ($tip==='checkbox'){
      if(count($ansNorm) < 2){
        $err='Za tip "Više tačnih (checkbox)" unesi bar 2 ponuđena odgovora.';
      } else {
        // bar jedan označen tačan koji postoji u ansNorm
        $hasAny=false;
        foreach($okChecks as $k){ if(array_key_exists((string)$k, $ansNorm)){ $hasAny=true; break; } }
        if(!$hasAny){
          $err='Označi bar jedan tačan odgovor (checkbox).';
        }
      }
    } else { // text
      // nije obavezno navesti primer tačnog, ali može
    }
  }

  if(empty($err)){
    $conn->begin_transaction();
    try{
      if($mode==='add'){
        $ins=$conn->prepare("INSERT INTO pitanja (test_id, tekst, tip) VALUES (?,?,?)");
        $ins->bind_param("iss",$test_id,$tekst,$tip); $ins->execute(); $qid=$ins->insert_id; $ins->close();
      } else {
        $up=$conn->prepare("UPDATE pitanja SET tekst=?, tip=? WHERE id=? AND test_id=?");
        $up->bind_param("ssii",$tekst,$tip,$qid,$test_id); $up->execute(); $up->close();
        // Brišemo stare odgovore tek kad znamo da je validacija prošla
        $del=$conn->prepare("DELETE FROM odgovori WHERE pitanje_id=?");
        $del->bind_param("i",$qid); $del->execute(); $del->close();
      }

      if($tip==='text'){
        if($textCorrect!==''){
          $ao=$conn->prepare("INSERT INTO odgovori (pitanje_id, tekst, tacan) VALUES (?,?,1)");
          $ao->bind_param("is",$qid,$textCorrect); $ao->execute(); $ao->close();
        }
      } else {
        if($tip==='radio'){
          foreach($ansNorm as $i=>$txt){
            $tacan = ((string)$i === (string)$okRadio) ? 1 : 0;
            $ao=$conn->prepare("INSERT INTO odgovori (pitanje_id, tekst, tacan) VALUES (?,?,?)");
            $ao->bind_param("isi",$qid,$txt,$tacan); $ao->execute(); $ao->close();
          }
        } else { // checkbox
          foreach($ansNorm as $i=>$txt){
            $tacan = in_array((string)$i,$okChecks,true) ? 1 : 0;
            $ao=$conn->prepare("INSERT INTO odgovori (pitanje_id, tekst, tacan) VALUES (?,?,?)");
            $ao->bind_param("isi",$qid,$txt,$tacan); $ao->execute(); $ao->close();
          }
        }
      }

      $conn->commit();
      header("Location: questions.php?test_id=".$test_id); exit;
    } catch(Exception $e){
      $conn->rollback(); $err='Greška: '.$e->getMessage();
    }
  } else {
    // Popuni $answers za ponovno iscrtavanje forme sa greškama / unosom
    if($tip==='text'){
      $answers = [];
      if($textCorrect!=='') $answers[] = ['tekst'=>$textCorrect, 'tacan'=>1];
    } else {
      $answers = [];
      foreach($ansNorm as $i=>$txt){
        $answers[] = [
          'tekst'=>$txt,
          'tacan'=> ($tip==='radio'
                      ? ((string)$i === (string)$okRadio ? 1 : 0)
                      : (in_array((string)$i,$okChecks,true) ? 1 : 0))
        ];
      }
    }
  }
}
?>
<!doctype html><html lang="sr"><head>
<?php require_once __DIR__ . '/analytics.php'; ?>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $mode==='add'?'Novo':'Izmena' ?> pitanje — <?= e($test['naslov']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-light p-4">
<div class="container">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3><?= $mode==='add'?'➕ Novo':'✏️ Izmena' ?> pitanja</h3>
    <a class="btn btn-secondary" href="questions.php?test_id=<?= (int)$test_id ?>">⬅️ Nazad</a>
  </div>

  <?php if(!empty($err)): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>

  <form method="post" class="card p-3 shadow-sm" id="qForm">
    <input type="hidden" name="test_id" value="<?= (int)$test_id ?>">
    <input type="hidden" name="id" value="<?= (int)$qid ?>">

    <div class="mb-3">
      <label class="form-label">Tekst pitanja</label>
      <textarea name="tekst" class="form-control" rows="3" required><?= e($tekst) ?></textarea>
    </div>

    <div class="mb-3">
      <label class="form-label">Tip pitanja</label>
      <select name="tip" id="tip" class="form-select" required>
        <option value="radio" <?= $tip==='radio'?'selected':'' ?>>Jedan tačan (radio)</option>
        <option value="checkbox" <?= $tip==='checkbox'?'selected':'' ?>>Više tačnih (checkbox)</option>
        <option value="text" <?= $tip==='text'?'selected':'' ?>>Tekstualni odgovor</option>
      </select>
    </div>

    <!-- Radio/Checkbox blok -->
    <div id="rcBlock" style="<?= $tip==='text'?'display:none':'' ?>">
      <div class="d-flex justify-content-between align-items-center">
        <label class="form-label mb-2">Odgovori</label>
        <button type="button" class="btn btn-sm btn-outline-primary" id="addRow">➕ Dodaj odgovor</button>
      </div>
      <div id="ansWrap">
        <?php
        $base = !empty($answers) ? $answers : [['tekst'=>''],['tekst'=>''],['tekst'=>'']];
        foreach($base as $i=>$a):
        ?>
          <div class="input-group mb-2 align-items-center ans-row" data-i="<?= $i ?>">
            <span class="input-group-text">#<?= $i ?></span>
            <input class="form-control" name="ans[<?= $i ?>]" value="<?= e($a['tekst'] ?? '') ?>" placeholder="Odgovor...">
            <div class="input-group-text">
              <?php if($tip==='radio'): ?>
                <input type="radio" name="ok" value="<?= $i ?>" <?= !empty($a['tacan'])?'checked':'' ?>>
                <span class="ms-1">tačan</span>
              <?php else: ?>
                <input type="checkbox" name="ok[]" value="<?= $i ?>" <?= !empty($a['tacan'])?'checked':'' ?>>
                <span class="ms-1">tačan</span>
              <?php endif; ?>
            </div>
            <button type="button" class="btn btn-outline-danger" onclick="this.closest('.ans-row').remove()">🗑</button>
          </div>
        <?php endforeach; ?>
      </div>
      <!-- === EDUZONE PATCH: hint za validaciju -->
      <div class="form-text">
        Za <strong>radio/checkbox</strong> potrebno je uneti najmanje 2 odgovora i obeležiti bar jedan kao tačan.
      </div>
    </div>

    <!-- Text blok -->
    <div id="textBlock" style="<?= $tip==='text'?'':'display:none' ?>">
      <?php
      $tc=''; if($tip==='text' && !empty($answers)){ foreach($answers as $a){ if(!empty($a['tacan'])){$tc=$a['tekst'];break;} } }
      ?>
      <label class="form-label">Tačan odgovor (opciono)</label>
      <input class="form-control" name="text_correct" value="<?= e($tc) ?>" placeholder="npr. Centralna jedinica">
      <div class="form-text">Primer tačnog odgovora pomaže automatskom bodovanju tekstualnih pitanja.</div>
    </div>

    <div class="text-end mt-3"><button class="btn btn-success">💾 Sačuvaj</button></div>
  </form>
</div>

<script>
const tip = document.getElementById('tip');
const rcBlock = document.getElementById('rcBlock');
const textBlock = document.getElementById('textBlock');
const wrap = document.getElementById('ansWrap');
const addRow = document.getElementById('addRow');

function sync() {
  if (tip.value === 'text') { rcBlock.style.display='none'; textBlock.style.display=''; }
  else {
    rcBlock.style.display=''; textBlock.style.display='none';
    // preimenuj radio/checkbox prema tipu
    wrap.querySelectorAll('.ans-row').forEach(row=>{
      const i = row.getAttribute('data-i');
      const radio = row.querySelector('input[type="radio"]');
      const check = row.querySelector('input[type="checkbox"]');
      let box = radio || check;
      if (tip.value==='radio'){ box.type='radio'; box.name='ok'; box.value=i; }
      else { box.type='checkbox'; box.name='ok[]'; box.value=i; }
    });
  }
}
tip.addEventListener('change', sync);

if (addRow) addRow.addEventListener('click', ()=>{
  let max = -1;
  wrap.querySelectorAll('.ans-row').forEach(r=>{
    const i=parseInt(r.getAttribute('data-i'),10); if(i>max) max=i;
  });
  const i = max+1;
  const isRadio = tip.value==='radio';
  const div = document.createElement('div');
  div.className='input-group mb-2 align-items-center ans-row';
  div.setAttribute('data-i', i);
  div.innerHTML = `
    <span class="input-group-text">#${i}</span>
    <input class="form-control" name="ans[${i}]" placeholder="Odgovor...">
    <div class="input-group-text">
      <input ${isRadio?'type="radio" name="ok"':'type="checkbox" name="ok[]"' } value="${i}">
      <span class="ms-1">tačan</span>
    </div>
    <button type="button" class="btn btn-outline-danger" onclick="this.closest('.ans-row').remove()">🗑</button>
  `;
  wrap.appendChild(div);
});

/* === EDUZONE PATCH: brza front-end provera (server i dalje odlučuje) */
document.getElementById('qForm').addEventListener('submit', function(ev){
  if (tip.value==='text') return; // bez uslova
  const inputs = [...wrap.querySelectorAll('input[name^="ans["]')].map(i=>i.value.trim()).filter(Boolean);
  if (inputs.length < 2){
    ev.preventDefault();
    alert('Unesi najmanje 2 ponuđena odgovora.');
    return;
  }
  if (tip.value==='radio'){
    const ok = wrap.querySelector('input[type="radio"][name="ok"]:checked');
    if (!ok){ ev.preventDefault(); alert('Označi tačan odgovor (radio).'); }
  } else {
    const oks = wrap.querySelectorAll('input[type="checkbox"][name="ok[]"]:checked');
    if (oks.length===0){ ev.preventDefault(); alert('Označi bar jedan tačan odgovor (checkbox).'); }
  }
});

sync();
</script>
</body></html>
