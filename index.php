<?php
// ============================================================
//  index.php  -  Login operatore con PIN (stile touch)
// ============================================================
require_once __DIR__ . '/includes/auth.php';

// Se già loggato, vai alla sala
if (operatore_loggato()) {
    header('Location: sala.php');
    exit;
}

$errore = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pin = $_POST['pin'] ?? '';
    if ($minuti = login_bloccato()) {
        $errore = "Troppi PIN sbagliati: riprova tra $minuti minut" . ($minuti == 1 ? 'o' : 'i');
    } elseif (login_pin($pin)) {
        header('Location: sala.php');
        exit;
    } else {
        $errore = 'PIN non valido';
    }
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title><?= h(impostazione('nome_locale', 'POS')) ?> - Accesso</title>
<link rel="stylesheet" href="assets/css/style.css?v=<?= @filemtime(__DIR__ . '/assets/css/style.css') ?>">
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0a1a3a">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="apple-touch-icon" href="assets/icona-192.png">
<link rel="icon" href="assets/icona-192.png">
<script src="assets/js/schermo.js?v=<?= @filemtime(__DIR__ . '/assets/js/schermo.js') ?>" defer></script>
</head>
<body>
  <div class="topbar">
    <span>POS - <?= h(impostazione('nome_locale')) ?></span>
    <span class="cassa">Cassa: <?= h(impostazione('numero_cassa', '1')) ?></span>
  </div>

  <form class="login-screen" method="post" id="loginForm">
    <h1>Accesso Operatore</h1>
    <div class="sottotitolo">Inserisci il tuo PIN</div>

    <div class="pin-display" id="pinDisplay"></div>
    <div class="login-errore"><?= h($errore) ?></div>

    <div class="keypad">
      <?php foreach ([1,2,3,4,5,6,7,8,9] as $n): ?>
        <button type="button" class="key" onclick="premi('<?= $n ?>')"><?= $n ?></button>
      <?php endforeach; ?>
      <button type="button" class="key canc" onclick="cancella()">←</button>
      <button type="button" class="key" onclick="premi('0')">0</button>
      <button type="button" class="key ok" onclick="conferma()">OK</button>
    </div>

    <input type="hidden" name="pin" id="pinInput">
  </form>

<script>
let pin = '';
function render(){ document.getElementById('pinDisplay').textContent = '•'.repeat(pin.length); }
function premi(n){ if(pin.length < 10){ pin += n; render(); } }
function cancella(){ pin = pin.slice(0, -1); render(); }
function conferma(){ document.getElementById('pinInput').value = pin; document.getElementById('loginForm').submit(); }
// supporto tastiera fisica
document.addEventListener('keydown', e => {
  if(e.key >= '0' && e.key <= '9') premi(e.key);
  else if(e.key === 'Backspace') cancella();
  else if(e.key === 'Enter') conferma();
});
</script>
</body>
</html>
