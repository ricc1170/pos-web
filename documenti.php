<?php
// ============================================================
//  documenti.php  -  Elenco documenti emessi (cassa / admin)
//  Filtro per tipo; fatture: XML e dettaglio; scontrini: numero della cassa o errore
// ============================================================
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/documenti.php';
richiedi_login();

$op = operatore_loggato();
if (!in_array($op['ruolo'], ['cassa', 'admin'], true)) {
    header('Location: sala.php');
    exit;
}

$tipi = ['' => 'Tutti', 'scontrino' => 'Scontrini', 'fattura' => 'Fatture', 'proforma' => 'Preconti'];
$tipo = array_key_exists($_GET['tipo'] ?? '', $tipi) ? ($_GET['tipo'] ?? '') : '';

$documenti = q('SELECT d.*, f.nome AS pagamento, o.nome AS operatore,
                       COALESCE(cl.denominazione, CONCAT_WS(" ", cl.nome, cl.cognome)) AS cliente,
                       c.tipo AS conto_tipo, c.orario_ritiro, c.nome_cliente, t.nome AS tavolo
                FROM documenti d
                LEFT JOIN forme_pagamento f ON f.id = d.pagamento_id
                LEFT JOIN operatori o ON o.id = d.operatore_id
                LEFT JOIN clienti cl ON cl.id = d.cliente_id
                LEFT JOIN conti c ON c.id = d.conto_id
                LEFT JOIN tavoli t ON t.id = c.tavolo_id
                WHERE ? = "" OR d.tipo = ?
                ORDER BY d.id DESC LIMIT 200', [$tipo, $tipo])->fetchAll();

$valuta = impostazione('valuta', '€');
$euro = fn($n) => $valuta . ' ' . number_format((float)$n, 2, ',', '.');
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title><?= h(impostazione('nome_locale')) ?> - Documenti</title>
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
    <span class="data">Documenti emessi</span>
    <span><?= date('H:i') ?></span>
  </div>

  <div class="sale-tabs">
    <?php foreach ($tipi as $k => $nome): ?>
      <a class="sala-tab <?= $k === $tipo ? 'attiva' : '' ?>" href="documenti.php<?= $k ? "?tipo=$k" : '' ?>"><?= $nome ?></a>
    <?php endforeach; ?>
  </div>

  <div class="main">
    <div class="navcol">
      <a class="btn menu" href="sala.php"><span class="ico">🏠</span>Menu</a>
      <?php if ($op['ruolo'] === 'admin'): ?>
        <a class="btn" href="impostazioni.php"><span class="ico">⚙</span>Impostazioni</a>
      <?php endif; ?>
    </div>

    <div class="tabella-wrap">
      <table class="tabella">
        <thead><tr><th>Data</th><th>Documento</th><th>Conto</th><th>Cliente / Pagamento</th><th class="num">Totale</th><th>Stato</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($documenti as $d):
          $conto = $d['conto_tipo'] === 'tavolo' ? 'Tavolo ' . $d['tavolo']
                 : ($d['orario_ritiro'] ? 'Asporto ' . date('H:i', strtotime($d['orario_ritiro'])) . ' ' . $d['nome_cliente'] : 'Bar');
        ?>
          <tr class="doc-<?= $d['stato'] ?>">
            <td><?= date('d/m/Y H:i', strtotime($d['data'])) ?></td>
            <td><b><?= ['scontrino' => 'Scontrino', 'fattura' => 'Fattura', 'proforma' => 'Preconto'][$d['tipo']] ?></b>
                <?= $d['tipo'] === 'fattura' ? $d['numero'] . '/' . $d['anno'] : ($d['rt_numero'] ? 'n. ' . h($d['rt_numero']) : '') ?></td>
            <td><?= h($conto) ?></td>
            <td><?= h($d['cliente'] ?: '') ?><?= $d['cliente'] && $d['pagamento'] ? ' · ' : '' ?><?= h($d['pagamento'] ?? '') ?></td>
            <td class="num"><?= $euro($d['totale']) ?></td>
            <td><?= ['attesa' => 'in corso…', 'emesso' => 'emesso', 'errore' => 'NON emesso'][$d['stato']] ?>
                <?php if ($d['errore']): ?><small><?= h($d['errore']) ?></small><?php endif; ?></td>
            <td>
              <?php if ($d['tipo'] === 'fattura'): ?>
                <a class="link-doc" href="fattura_xml.php?id=<?= $d['id'] ?>">⬇ XML</a>
                <a class="link-doc" href="fattura.php?doc=<?= $d['id'] ?>">Apri</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$documenti): ?>
          <tr><td colspan="7" class="vuoto">Nessun documento.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</body>
</html>
