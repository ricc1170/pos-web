<?php
// ============================================================
//  generali.php  -  Dati del locale (solo admin)
//  Valori della tabella impostazioni: nome locale, numero cassa,
//  prezzo del coperto, simbolo della valuta, ora di inizio della giornata di lavoro.
//  Da qui si scarica anche "POS a tutto schermo.bat" (apre il sito in modalità chiosco su un PC).
// ============================================================
require_once __DIR__ . '/includes/auth.php';
$op = richiedi_admin();

// Download di "POS a tutto schermo.bat" con dentro l'indirizzo del sito.
// Online sempre https, anche se la pagina è stata aperta con http; in rete locale si lascia com'è.
if (($_GET['scarica'] ?? '') === 'schermo') {
    $host   = $_SERVER['HTTP_HOST'];
    $locale = preg_match('/^(localhost|127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $host);
    $https  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $url    = ($https || !$locale ? 'https' : 'http') . '://' . $host
            . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="POS a tutto schermo.bat"');
    header('Cache-Control: no-store');
    exit(str_replace(["\r\n", "\n"], ["\n", "\r\n"],
                     strtr(file_get_contents(__DIR__ . '/includes/schermo-modello.bat'), ['{{URL}}' => $url])));
}

$errore = '';
$dati = [
    'nome_locale'  => impostazione('nome_locale', ''),
    'numero_cassa' => impostazione('numero_cassa', '1'),
    'coperto'      => impostazione('coperto', '0.00'),
    'valuta'       => impostazione('valuta', '€'),
    'giornata_inizio' => orario_giornata()[0],
    'giornata_fine'   => orario_giornata()[1],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    controlla_csrf();
    $dati = [
        'nome_locale'  => trim($_POST['nome_locale'] ?? ''),
        'numero_cassa' => trim($_POST['numero_cassa'] ?? ''),
        'coperto'      => str_replace(',', '.', trim($_POST['coperto'] ?? '')),
        'valuta'       => trim($_POST['valuta'] ?? ''),
        'giornata_inizio' => trim($_POST['giornata_inizio'] ?? ''),
        'giornata_fine'   => trim($_POST['giornata_fine'] ?? ''),
    ];

    if ($dati['nome_locale'] === '' || mb_strlen($dati['nome_locale']) > 100) {
        $errore = 'Il nome del locale è obbligatorio (max 100 caratteri)';
    } elseif (!preg_match('/^\d{1,2}$/', $dati['numero_cassa']) || (int)$dati['numero_cassa'] < 1) {
        $errore = 'Numero cassa: da 1 a 99';
    } elseif (!is_numeric($dati['coperto']) || $dati['coperto'] < 0 || $dati['coperto'] > 100) {
        $errore = 'Prezzo del coperto non valido';
    } elseif ($dati['valuta'] === '' || mb_strlen($dati['valuta']) > 5) {
        $errore = 'Valuta: da 1 a 5 caratteri (es. €)';
    } elseif (!orario_valido($dati['giornata_inizio']) || !orario_valido($dati['giornata_fine'])) {
        $errore = 'Giornata di lavoro: inizio e fine nel formato ore:minuti (es. 10:00 e 03:00)';
    }

    if (!$errore) {
        $dati['coperto'] = number_format((float)$dati['coperto'], 2, '.', '');
        foreach ($dati as $chiave => $valore) {
            q('INSERT INTO impostazioni (chiave, valore) VALUES (?, ?)
               ON DUPLICATE KEY UPDATE valore = VALUES(valore)', [$chiave, $valore]);
        }
        $_SESSION['msg'] = 'Dati del locale salvati.';
        header('Location: generali.php');
        exit;
    }
}

$msg = $_SESSION['msg'] ?? '';
unset($_SESSION['msg']);
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title><?= h(impostazione('nome_locale')) ?> - Dati del locale</title>
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
    <span>POS - Rel. 1.0 - Int.: <?= h(impostazione('nome_locale')) ?></span>
    <span class="cassa">Cassa: <?= h(impostazione('numero_cassa', '1')) ?></span>
  </div>

  <div class="inforow">
    <span>Utente <?= h($op['nome']) ?></span>
    <span class="data">Impostazioni · Dati del locale</span>
    <span><?= date('H:i') ?></span>
  </div>

  <div class="main">
    <div class="navcol">
      <a class="btn menu" href="sala.php"><span class="ico">🏠</span>Menu</a>
      <a class="btn" href="impostazioni.php"><span class="ico">⚙</span>Impostazioni</a>
      <!-- Apre il POS a tutto schermo (chiosco) su un PC: il file va messo sul Desktop -->
      <a class="btn" href="generali.php?scarica=schermo"><span class="ico">⬇</span>Tutto schermo</a>
    </div>

    <div class="install">
      <form class="scheda" method="post">
        <h2>Dati del locale</h2>
        <?php if ($msg): ?><div class="msg ok"><?= h($msg) ?></div><?php endif; ?>
        <?php if ($errore): ?><div class="msg errore"><?= h($errore) ?></div><?php endif; ?>

        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">

        <label>Nome del locale (appare in alto e sugli scontrini)
          <input type="text" name="nome_locale" maxlength="100" required value="<?= h($dati['nome_locale']) ?>">
        </label>

        <div class="due">
          <label>Numero cassa
            <input type="number" name="numero_cassa" min="1" max="99" required value="<?= h($dati['numero_cassa']) ?>">
          </label>
          <label>Valuta
            <input type="text" name="valuta" maxlength="5" required value="<?= h($dati['valuta']) ?>">
          </label>
        </div>

        <label>Prezzo del coperto (a persona, 0 = niente coperto)
          <input type="text" name="coperto" inputmode="decimal" required
                 value="<?= h(str_replace('.', ',', $dati['coperto'])) ?>">
        </label>
        <div class="hint">Il nuovo prezzo vale da subito; i conti già aperti si ricalcolano alla prossima modifica.</div>

        <div class="due">
          <label>Giornata di lavoro: inizio
            <input type="time" name="giornata_inizio" required value="<?= h($dati['giornata_inizio']) ?>">
          </label>
          <label>fine (anche dopo mezzanotte)
            <input type="time" name="giornata_fine" required value="<?= h($dati['giornata_fine']) ?>">
          </label>
        </div>
        <div class="hint">Per le statistiche il giorno cambia a metà delle ore di chiusura: con 10:00 – 03:00 alle 6:30,
          quindi un conto chiuso all'1:30 di sabato notte va nel venerdì. Vale anche per i dati già registrati.</div>

        <button type="submit" class="btn verde grande"><span class="ico">✔</span>Salva</button>
      </form>
    </div>
  </div>

</body>
</html>
