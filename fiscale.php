<?php
// ============================================================
//  fiscale.php  -  Dati fiscali (solo admin)
//  Sinistra: dati dell'azienda per le fatture elettroniche
//  Destra: registratore telematico Epson (IP, reparti per aliquota IVA, verifica collegamento)
// ============================================================
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/documenti.php';
$op = richiedi_admin();

$regimi = ['RF01' => 'RF01 - Ordinario', 'RF02' => 'RF02 - Contribuenti minimi', 'RF19' => 'RF19 - Forfettario'];
$aliquote = ['10' => 'IVA 10%', '22' => 'IVA 22%', '4' => 'IVA 4%', '5' => 'IVA 5%'];

// Reparti attuali per aliquota ("22=1;10=2;4=3;5=4", come programmati nella cassa Epson)
$reparti = [];
foreach (explode(';', (string)impostazione('rt_reparti', '22=1;10=2;4=3;5=4')) as $coppia) {
    [$a, $r] = array_pad(explode('=', $coppia), 2, '');
    if ($a !== '') $reparti[$a] = (int)$r;
}

$errore = '';
$campi_azienda = ['azienda_denominazione', 'azienda_piva', 'azienda_cf', 'azienda_regime',
                  'azienda_indirizzo', 'azienda_cap', 'azienda_comune', 'azienda_provincia'];
$dati = [];
foreach ($campi_azienda as $k) $dati[$k] = (string)impostazione($k, $k === 'azienda_regime' ? 'RF01' : '');
$dati['rt_modo']      = rt_modo();
$dati['rt_ip']        = (string)impostazione('rt_ip', '');
$dati['rt_operatore'] = (string)impostazione('rt_operatore', '1');
$anno = date('Y');
$dati["fattura_ultimo_$anno"] = (string)impostazione("fattura_ultimo_$anno", '0');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    controlla_csrf();

    if (($_POST['azione'] ?? '') === 'verifica') {
        // Chiede lo stato alla cassa (non stampa niente); con il ponte la risposta arriva dopo qualche secondo
        if (rt_simulata()) {
            $_SESSION['msg'] = 'Cassa in simulazione: non c\'è nessun registratore da verificare.';
        } elseif (!rt_configurato()) {
            $_SESSION['err'] = 'Scegli "Epson in rete" e inserisci l\'indirizzo IP, poi salva.';
        } else {
            invia_rt(xml_stato_rt(), 'Verifica cassa', null);
            $_SESSION['msg'] = usa_ponte((string)impostazione('rt_ip'))
                ? 'Verifica inviata tramite il ponte: ricarica la pagina tra qualche secondo per vedere l\'esito.'
                : 'Verifica eseguita: vedi l\'esito qui sotto.';
        }
        header('Location: fiscale.php');
        exit;
    }

    foreach ($campi_azienda as $k) $dati[$k] = trim((string)($_POST[$k] ?? ''));
    $dati['azienda_piva']      = preg_replace('/\s+/', '', $dati['azienda_piva']);
    $dati['azienda_cf']        = strtoupper(preg_replace('/\s+/', '', $dati['azienda_cf']));
    $dati['azienda_provincia'] = strtoupper($dati['azienda_provincia']);
    $dati['rt_modo']      = in_array($_POST['rt_modo'] ?? '', ['spenta', 'simulata', 'epson'], true) ? $_POST['rt_modo'] : 'spenta';
    $dati['rt_ip']        = trim((string)($_POST['rt_ip'] ?? ''));
    $dati['rt_operatore'] = (string)max(1, min(12, (int)($_POST['rt_operatore'] ?? 1)));
    $dati["fattura_ultimo_$anno"] = (string)max(0, (int)($_POST['fattura_ultimo'] ?? 0));
    foreach ($aliquote as $a => $_) $reparti[$a] = max(1, min(99, (int)($_POST["reparto_$a"] ?? 1)));

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
    } elseif ($dati['rt_modo'] === 'epson' && !filter_var($dati['rt_ip'], FILTER_VALIDATE_IP)) {
        $errore = 'Indirizzo IP della cassa non valido (es. 192.168.1.50)';
    }

    if (!$errore) {
        foreach ($dati as $k => $v) salva_impostazione($k, $v);
        salva_impostazione('rt_reparti', implode(';', array_map(fn($a) => "$a={$reparti[$a]}", array_keys($aliquote))));
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
      </div>

      <!-- Registratore telematico -->
      <div class="scheda">
        <h2>Registratore telematico Epson</h2>
        <label>Cassa per gli scontrini</label>
        <div class="scelta tre">
          <?php foreach (['spenta' => 'Spenta', 'simulata' => 'Simulazione', 'epson' => 'Epson in rete'] as $k => $nome): ?>
            <label><input type="radio" name="rt_modo" value="<?= $k ?>" <?= $dati['rt_modo'] === $k ? 'checked' : '' ?>><span><?= $nome ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="hint">Simulazione: lo scontrino chiude il conto e stampa un foglio "NON FISCALE" sulla stampante Cassa
          (numeri SIM-1, SIM-2...). Solo per le prove: non vale come scontrino.</div>
        <div class="due">
          <label>Indirizzo IP della cassa
            <input type="text" name="rt_ip" maxlength="45" inputmode="decimal" placeholder="es. 192.168.1.50" value="<?= h($dati['rt_ip']) ?>">
          </label>
          <label>Operatore cassa
            <input type="number" name="rt_operatore" min="1" max="12" value="<?= h($dati['rt_operatore']) ?>">
          </label>
        </div>

        <label>Reparto della cassa per ogni aliquota IVA</label>
        <div class="reparti">
          <?php foreach ($aliquote as $a => $nome): ?>
            <label><?= $nome ?>
              <input type="number" name="reparto_<?= $a ?>" min="1" max="99" value="<?= (int)($reparti[$a] ?? 1) ?>">
            </label>
          <?php endforeach; ?>
        </div>
        <div class="hint">
          I reparti devono corrispondere a quelli programmati sulla cassa (stessa aliquota IVA).
          Online lo scontrino passa dal ponte di stampa, che deve essere collegato.
        </div>

        <?php if (impostazione('rt_ultima_verifica')): ?>
          <div class="msg <?= strpos((string)impostazione('rt_ultima_verifica'), 'pronta') !== false ? 'ok' : 'errore' ?>">
            Ultima verifica: <?= h(impostazione('rt_ultima_verifica')) ?></div>
        <?php endif; ?>

        <div class="scheda-piede">
          <button type="submit" name="azione" value="salva" class="btn verde"><span class="ico">✔</span>Salva</button>
          <button type="submit" name="azione" value="verifica" class="btn" formnovalidate><span class="ico">🔌</span>Verifica cassa</button>
        </div>
      </div>
    </form>
  </div>

</body>
</html>
