<!DOCTYPE html>
<html lang="sr">
<head>
<?php require_once __DIR__ . '/analytics.php'; ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin panel test</title>
<style>
  :root{
    --y:#ffd100; --bg:#0f0f10; --g3:#2b2c31; --txt:#f6f6f6;
  }
  body{margin:0;background:#0f0f10;color:#f6f6f6;font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif}
  .wrap{padding:20px}
  .btn{display:inline-block;padding:10px 14px;border-radius:10px;border:1px solid var(--y);color:var(--y);text-decoration:none;font-weight:700;cursor:pointer}
  .btn:hover{background:var(--y);color:#111}

  /* PURE CSS drawer (checkbox hack) – unikatni ID-ovi */
  #ezd_toggle_x { position:absolute; left:-9999px; }
  .ezd_backdrop_x{
    position:fixed; inset:0; background:rgba(0,0,0,.55);
    opacity:0; pointer-events:none; transition:.25s ease; z-index:999998;
  }
  .ezd_drawer_x{
    position:fixed; top:0; left:0; height:100vh; width:min(360px,92vw);
    background:#121214; color:var(--txt); border-right:1px solid var(--g3);
    transform:translateX(-110%); transition:.25s ease; z-index:999999;
    display:flex; flex-direction:column;
  }
  .ezd_hdr_x{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid var(--g3)}
  .ezd_close_x{cursor:pointer; font-size:20px; line-height:1; color:#fff}
  .ezd_body_x{padding:12px 16px; overflow:auto; flex:1}
  .ezd_link_x{display:block;color:#fff;text-decoration:none;border:1px solid var(--g3);border-radius:10px;padding:10px 12px;margin-bottom:8px;background:#171719}
  .ezd_link_x:hover{border-color:var(--y)}

  #ezd_toggle_x:checked ~ .ezd_drawer_x{ transform:translateX(0) }
  #ezd_toggle_x:checked ~ .ezd_backdrop_x{ opacity:1; pointer-events:auto }
</style>
</head>
<body>
  <div class="wrap">
    <!-- Dugme je label za checkbox -->
    <label for="ezd_toggle_x" class="btn">⚙️ Admin panel</label>
    <p>Ovo je izolovani test. Ako ovde radi, konflikt je u tvom index.php (dupli ID, overlay, stari offcanvas...).</p>
  </div>

  <!-- Checkbox mora biti PRE drawer/backdrop u DOM-u -->
  <input type="checkbox" id="ezd_toggle_x">
  <aside class="ezd_drawer_x" role="dialog" aria-labelledby="ezd_title_x">
    <div class="ezd_hdr_x">
      <strong id="ezd_title_x">⚙️ Admin Meni</strong>
      <label for="ezd_toggle_x" class="ezd_close_x" aria-label="Zatvori">×</label>
    </div>
    <div class="ezd_body_x">
      <a class="ezd_link_x" href="#">➕ Dodaj obaveštenje</a>
      <a class="ezd_link_x" href="#">📚 Lekcije (admin)</a>
      <a class="ezd_link_x" href="#">📘 Predmeti</a>
    </div>
  </aside>
  <label for="ezd_toggle_x" class="ezd_backdrop_x" aria-hidden="true"></label>
</body>
</html>
