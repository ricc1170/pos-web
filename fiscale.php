<?php
// ============================================================
//  fiscale.php  -  Dati fiscali (solo admin)
//  Dati dell'azienda per le fatture elettroniche e numerazione delle fatture.
//  Il registratore telematico (Epson) si configura nel programma desktop della cassa.
// ============================================================
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/documenti.php';
$op = richiedi_admin();

$regimi = ['RF01' => 'RF01 - Ordinario', 'RF02' => 'RF02 - Contribuenti minimi', 'RF19' => 'RF19 - Forfettario'];

$errore = '';
$campi_azienda = ['azienda_denominazione', 'azienda_piva', 'azienda_cf', 'azienda_regime',
                  'azienda_indirizzo', 'azienda_cap', 'azienda_comune', 'azienda_provincia'];
$dati = [];
foreach ($campi_azienda as $k) $dati[$k] = (string)impostazione($k, $k === 'azienda_regime' ? 'RF01' : '');
$anno = date('Y');
$dati["fattura_ultimo_$anno"] = (string)impostazione("fattura_ultimo_$anno", '0');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    controlla_csrf();

    foreach ($campi_azienda as $k) $dati[$k] = trim((string)($_POST[$k] ?? ''));
    $dati['azienda_piva']      = preg_replace('/\s+/', '', $dati['azienda_piva']);
    $dati['azienda_cf']        = strtoupper(preg_replace('/\s+/', '', $dati['azienda_cf']));
    $dati['azienda_provincia'] = strtoupper($dati['azienda_provincia']);
    $dati["fattura_ultimo_$anno"] = (string)max(0, (int)($_POST['fattura_ultimo'] ?? 0));

    if ($dati['azienda_piva'] !== '' && !preg_match('/^\d{11}$/', $dati['azienda_piva'])) {
        $errore = 'La partita IVA deve avere 11 cifre';
    } elseif ($dati['azienda_cf'] !== '' && !preg_match('/^([A-Z0-9]{16}|\d{11})$/', $dati['azienda_cf'])) {
        $errore = 'Codice fiscale non valido (16 caratteri, o 11 cifre per le società)';
    } elseif ($dati['azienda_cap'] !== '' && !preg_match('/^\d{5}$/', $dati['azienda_cap'])) {
        $errore = 'Il CAP deve avere 5 cifre';
    } elseif ($dati['azienda_provincia'] !== '' && !preg_match('/^[A-Z]{2}$/', $dati['azienda_provincia'])) {
        $errore = 'Provincia: sigla di 2 lettere (es. SP)';
    } elseif (!array_key_exists($dati['azienda_regime'], $regimi)) {
        $errore = 'Scegli il regime fiscale';
    }

    if (!$errore) {
        foreach ($dati as $k => $v) salva_impostazione($k, $v);
        $_SESSION['msg'] = 'Dati fiscali salvati.';
        header('Location: fiscale.php');
        exit;
    }
}

$msg = $_SESSION['msg'] ?? '';
$err = $_SESSION['err'] ?? '';
unset($_SESSION['msg'], $_SESSION['err']);
$mancano = dati_azienda_mancanti();
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title><?= h(impostazione('nome_locale')) ?> - Fiscale</title>
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
    <span class="data">Impostazioni · Fiscale</span>
    <span><?= date('H:i') ?></span>
  </div>

  <div class="main">
    <div class="navcol">
      <a class="btn menu" href="sala.php"><span class="ico">🏠</span>Menu</a>
      <a class="btn" href="impostazioni.php"><span class="ico">⚙</span>Impostazioni</a>
      <a class="btn" href="documenti.php"><span class="ico">🧾</span>Documenti</a>
    </div>

    <form class="fiscale" method="post">
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">

      <!-- Dati dell'azienda -->
      <div class="scheda">
        <h2>Azienda (per le fatture)</h2>
        <?php if ($msg): ?><div class="msg ok"><?= h($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="msg errore"><?= h($err) ?></div><?php endif; ?>
        <?php if ($errore): ?><div class="msg errore"><?= h($errore) ?></div><?php endif; ?>
        <?php if ($mancano && !$errore): ?>
          <div class="msg errore">Per emettere fatture mancano: <?= h(implode(', ', $mancano)) ?></div>
        <?php endif; ?>

        <label>Ragione sociale
          <input type="text" name="azienda_denominazione" maxlength="80" value="<?= h($dati['azienda_denominazione']) ?>">
        </label>
        <div class="due">
          <label>Partita IVA
            <input type="text" name="azienda_piva" maxlength="11" inputmode="numeric" value="<?= h($dati['azienda_piva']) ?>">
          </label>
          <label>Codice fiscale
            <input type="text" name="azienda_cf" maxlength="16" value="<?= h($dati['azienda_cf']) ?>">
          </label>
        </div>
        <label>Regime fiscale
          <select name="azienda_regime">
            <?php foreach ($regimi as $k => $nome): ?>
              <option value="<?= $k ?>" <?= $dati['azienda_regime'] === $k ? 'selected' : '' ?>><?= h($nome) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Indirizzo (via e numero)
          <input type="text" name="azienda_indirizzo" maxlength="80" value="<?= h($dati['azienda_indirizzo']) ?>">
        </label>
        <div class="tre-campi">
          <label>CAP
            <input type="text" name="azienda_cap" maxlength="5" inputmode="numeric" value="<?= h($dati['azienda_cap']) ?>">
          </label>
          <label>Comune
            <input type="text" name="azienda_comune" maxlength="60" value="<?= h($dati['azienda_comune']) ?>">
          </label>
          <label>Prov.
            <input type="text" name="azienda_provincia" maxlength="2" value="<?= h($dati['azienda_provincia']) ?>">
          </label>
        </div>
        <label>Ultima fattura del <?= $anno ?> già emessa con altri programmi (0 se nessuna)
          <input type="number" name="fattura_ultimo" min="0" value="<?= (int)$dati["fattura_ultimo_$anno"] ?>">
        </label>
        <div class="hint">Le fatture del POS continuano la numerazione da qui: la prossima sarà la
          <b><?= prossimo_numero_fattura((int)$anno) ?>/<?= $anno ?></b>.</div>

        <div class="hint">Il registratore telematico (Epson) si configura nel programma della cassa:
          <b>Impostazioni &gt; Cassa fiscale</b>.</div>

        <div class="scheda-piede">
          <button type="submit" name="azione" value="salva" class="btn verde"><span class="ico">✔</span>Salva</button>
        </div>
      </div>
    </form>
  </div>

</body>
</html>
