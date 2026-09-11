<?php
// Collect analytics only on the live EduZone domain.
$analyticsHost = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
if (!in_array($analyticsHost, ['eduzone.rs', 'www.eduzone.rs'], true)) {
    return;
}
?>
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-S81LR8Q4GH"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-S81LR8Q4GH');
</script>
