<?php
// ============================================================
//  fattura.php  -  Emissione fattura per un conto (cassa / admin)
//  fattura.php?conto=N         scelta cliente (ricerca o nuovo) + pagamento -> Emetti
//  fattura.php?doc=N           fattura emessa: scarica XML
// ============================================================
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/documenti.php';
richiedi_login();

$op = operatore_loggato();
if (!in_array($op['ruolo'], ['cassa', 'admin'], true)) {
    header('Location: sala.php');
    exit;
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

// Pagina della comanda da cui si arriva (per tornarci)
function link_comanda(array $c): string {
    if ($c['tipo'] === 'tavolo') return 'comanda.php?tavolo=' . $c['tavolo_id'];
    return $c['orario_ritiro'] ? 'comanda.php?conto=' . $c['id'] : 'comanda.php?bar=1';
}

$campi_cliente = ['denominazione', 'nome', 'cognome', 'piva', 'cf', 'indirizzo', 'cap', 'comune', 'provincia',
                  'codice_destinatario', 'pec'];

$errore = '';
$form   = null;   // dati del nuovo cliente da ripresentare dopo un errore

// ------------------------------------------------------------
//  Fattura già emessa: riepilogo, XML
// ------------------------------------------------------------
if (isset($_GET['doc'])) {
    $doc = q('SELECT d.*, f.nome AS pagamento FROM documenti d LEFT JOIN forme_pagamento f ON f.id = d.pagamento_id
              WHERE d.id = ? AND d.tipo = "fattura"', [(int)$_GET['doc']])->fetch();
    if (!$doc) { header('Location: documenti.php'); exit; }
    $cli = q('SELECT * FROM clienti WHERE id = ?', [$doc['cliente_id']])->fetch();
} else {
    // ------------------------------------------------------------
    //  Conto da fatturare
    // ------------------------------------------------------------
    $c = dati_conto((int)($_GET['conto'] ?? 0));
    if (!$c || $c['stato'] !== 'aperto') {
        $_SESSION['err'] = 'Conto non trovato o già chiuso.';
        header('Location: sala.php');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        controlla_csrf();
        $azione = $_POST['azione'] ?? '';

        if ($azione === 'salva_cliente') {
            $form = [];
            foreach ($campi_cliente as $k) $form[$k] = trim((string)($_POST[$k] ?? ''));
            $form['piva'] = preg_replace('/\s+/', '', $form['piva']);
            $form['cf']   = strtoupper(preg_replace('/\s+/', '', $form['cf']));
            $form['provincia'] = strtoupper($form['provincia']);
            $form['codice_destinatario'] = strtoupper($form['codice_destinatario'] ?: '0000000');

            if ($form['denominazione'] === '' && ($form['nome'] === '' || $form['cognome'] === '')) {
                $errore = 'Inserisci la ragione sociale, oppure nome e cognome';
            } elseif ($form['piva'] === '' && $form['cf'] === '') {
                $errore = 'Serve la partita IVA o il codice fiscale';
            } elseif ($form['piva'] !== '' && !preg_match('/^\d{11}$/', $form['piva'])) {
                $errore = 'La partita IVA deve avere 11 cifre';
            } elseif ($form['cf'] !== '' && !preg_match('/^([A-Z0-9]{16}|\d{11})$/', $form['cf'])) {
                $errore = 'Codice fiscale non valido';
            } elseif ($form['indirizzo'] === '' || $form['comune'] === '' || !preg_match('/^\d{5}$/', $form['cap'])) {
                $errore = 'Indirizzo, CAP (5 cifre) e comune sono obbligatori';
            } elseif ($form['provincia'] !== '' && !preg_match('/^[A-Z]{2}$/', $form['provincia'])) {
                $errore = 'Provincia: sigla di 2 lettere';
            } elseif (!preg_match('/^[A-Z0-9]{7}$/', $form['codice_destinatario'])) {
                $errore = 'Codice destinatario: 7 caratteri (0000000 se non lo ha)';
            } elseif ($form['pec'] !== '' && !filter_var($form['pec'], FILTER_VALIDATE_EMAIL)) {
                $errore = 'PEC non valida';
            }
            if (!$errore) {
                $valori = array_map(fn($k) => $form[$k] === '' ? null : $form[$k], $campi_cliente);
                q('INSERT INTO clienti (' . implode(', ', $campi_cliente) . ') VALUES (' . implode(', ', array_fill(0, count($campi_cliente), '?')) . ')', $valori);
                header("Location: fattura.php?conto={$c['id']}&cliente=" . db()->lastInsertId());
                exit;
            }
        }

        if ($azione === 'emetti') {
            $cliente_id = (int)($_POST['cliente'] ?? 0);
            $pag        = (int)($_POST['pagamento'] ?? 0);
            $cli = q('SELECT * FROM clienti WHERE id = ?', [$cliente_id])->fetch();
            if ($mancano = dati_azienda_mancanti()) {
                $errore = 'Completa i dati dell\'azienda in Impostazioni > Fiscale (' . implode(', ', $mancano) . ')';
            } elseif (!$cli) {
                $errore = 'Scegli il cliente';
            } elseif (!q('SELECT id FROM forme_pagamento WHERE id = ?', [$pag])->fetch()) {
                $errore = 'Scegli il pagamento';
            } else {
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    // Il conto non deve essere chiuso nel frattempo da un altro dispositivo
                    $stato = q('SELECT stato FROM conti WHERE id = ? FOR UPDATE', [$c['id']])->fetchColumn();
                    if ($stato !== 'aperto') throw new RuntimeException('Il conto è già stato chiuso');
                    $anno = (int)date('Y');
                    $numero = prossimo_numero_fattura($anno);
                    q('INSERT INTO documenti (tipo, conto_id, numero, anno, data, totale, pagamento_id, cliente_id, operatore_id)
                       VALUES ("fattura", ?, ?, ?, NOW(), ?, ?, ?, ?)',
                      [$c['id'], $numero, $anno, $c['totale'], $pag, $cliente_id, $op['id']]);
                    $doc_id = (int)$pdo->lastInsertId();
                    chiudi_conto((int)$c['id'], $pag);
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $errore = $e instanceof RuntimeException && !($e instanceof PDOException) ? $e->getMessage() : 'Errore del database';
                    if (!($e instanceof RuntimeException) || $e instanceof PDOException) error_log('fattura.php: ' . $e->getMessage());
                }
                if (!$errore) {
                    $_SESSION['ritorno'] = link_comanda($c);
                    $_SESSION['msg'] = "Fattura $numero/$anno emessa, conto chiuso.";
                    header("Location: fattura.php?doc=$doc_id");
                    exit;
                }
            }
        }
    }

    // Ricerca clienti
    $cerca = trim((string)($_GET['q'] ?? ''));
    $clienti = $cerca === ''
        ? q('SELECT * FROM clienti ORDER BY id DESC LIMIT 12')->fetchAll()
        : q('SELECT * FROM clienti WHERE denominazione LIKE ? OR cognome LIKE ? OR nome LIKE ? OR piva LIKE ? OR cf LIKE ?
             ORDER BY denominazione, cognome LIMIT 30', array_fill(0, 5, "%$cerca%"))->fetchAll();
    $cliente_sel = (int)($_GET['cliente'] ?? ($_POST['cliente'] ?? 0));
    $pagamenti = q('SELECT * FROM forme_pagamento ORDER BY ordine, id')->fetchAll();
}

$msg = $_SESSION['msg'] ?? '';
$err = $_SESSION['err'] ?? '';
unset($_SESSION['msg'], $_SESSION['err']);
$valuta = impostazione('valuta', '€');
$euro = fn($n) => $valuta . ' ' . number_format((float)$n, 2, ',', '.');
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title><?= h(impostazione('nome_locale')) ?> - Fattura</title>
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
    <span class="data"><?= isset($doc) ? "Fattura {$doc['numero']}/{$doc['anno']}" : 'Fattura · ' . h(titolo_conto($c)) ?></span>
    <span><?= date('H:i') ?></span>
  </div>

<?php if (isset($doc)): ?>
  <!-- ===== Fattura emessa ===== -->
  <div class="main">
    <div class="navcol">
      <a class="btn menu" href="sala.php"><span class="ico">🏠</span>Menu</a>
      <?php if (!empty($_SESSION['ritorno'])): ?>
        <a class="btn" href="<?= h($_SESSION['ritorno']) ?>"><span class="ico">↩</span>Comanda</a>
      <?php endif; ?>
      <a class="btn" href="documenti.php"><span class="ico">🧾</span>Documenti</a>
    </div>
    <div class="install">
      <div class="scheda">
        <h2>Fattura <?= $doc['numero'] ?>/<?= $doc['anno'] ?> del <?= date('d/m/Y', strtotime($doc['data'])) ?></h2>
        <?php if ($msg): ?><div class="msg ok"><?= h($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="msg errore"><?= h($err) ?></div><?php endif; ?>
        <div class="riepilogo-doc">
          <div><span>Cliente</span><b><?= h(nome_cliente($cli)) ?></b></div>
          <div><span><?= $cli['piva'] ? 'P.IVA' : 'C.F.' ?></span><b><?= h($cli['piva'] ?: $cli['cf']) ?></b></div>
          <div><span>Totale</span><b><?= $euro($doc['totale']) ?></b></div>
          <div><span>Pagamento</span><b><?= h($doc['pagamento']) ?></b></div>
        </div>
        <div class="hint">Scarica il file XML e caricalo sul portale "Fatture e Corrispettivi" dell'Agenzia delle Entrate
          (o passalo al commercialista): è quello il documento valido.</div>
        <div class="scheda-piede">
          <a class="btn verde" href="fattura_xml.php?id=<?= $doc['id'] ?>"><span class="ico">⬇</span>Scarica XML</a>
        </div>
      </div>
    </div>
  </div>

<?php else: ?>
  <!-- ===== Emissione: cliente + pagamento ===== -->
  <div class="main">
    <div class="navcol">
      <a class="btn scuro" href="<?= h(link_comanda($c)) ?>"><span class="ico">↩</span>Comanda</a>
    </div>

    <!-- Clienti -->
    <div class="fattura-clienti">
      <form class="cerca-cliente" method="get">
        <input type="hidden" name="conto" value="<?= $c['id'] ?>">
        <input type="text" name="q" placeholder="Cerca cliente: nome, P.IVA, C.F." value="<?= h($cerca) ?>">
        <button type="submit" class="btn">🔍</button>
      </form>
      <div class="ordini">
        <?php foreach ($clienti as $cl): ?>
          <a class="ordine <?= $cl['id'] == $cliente_sel ? 'sel' : '' ?>"
             href="fattura.php?conto=<?= $c['id'] ?>&cliente=<?= $cl['id'] ?><?= $cerca !== '' ? '&q=' . urlencode($cerca) : '' ?>">
            <span class="cliente"><?= h(nome_cliente($cl)) ?></span>
            <span class="dettagli"><?= h($cl['piva'] ? 'P.IVA ' . $cl['piva'] : 'C.F. ' . $cl['cf']) ?></span>
            <span class="dettagli"><?= h($cl['comune']) ?></span>
          </a>
        <?php endforeach; ?>
        <?php if (!$clienti): ?>
          <div class="vuoto"><?= $cerca !== '' ? 'Nessun cliente trovato.' : 'Nessun cliente ancora.' ?> Inseriscilo qui sotto.</div>
        <?php endif; ?>
      </div>

      <!-- Nuovo cliente -->
      <details class="nuovo-cliente" <?= $form || !$clienti ? 'open' : '' ?>>
        <summary>＋ Nuovo cliente</summary>
        <form class="scheda" method="post">
          <?php if ($errore && $form): ?><div class="msg errore"><?= h($errore) ?></div><?php endif; ?>
          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
          <label>Ragione sociale (azienda)
            <input type="text" name="denominazione" maxlength="80" value="<?= h($form['denominazione'] ?? '') ?>">
          </label>
          <div class="due">
            <label>oppure Nome <input type="text" name="nome" maxlength="60" value="<?= h($form['nome'] ?? '') ?>"></label>
            <label>Cognome <input type="text" name="cognome" maxlength="60" value="<?= h($form['cognome'] ?? '') ?>"></label>
          </div>
          <div class="due">
            <label>Partita IVA <input type="text" name="piva" maxlength="11" inputmode="numeric" value="<?= h($form['piva'] ?? '') ?>"></label>
            <label>Codice fiscale <input type="text" name="cf" maxlength="16" value="<?= h($form['cf'] ?? '') ?>"></label>
          </div>
          <label>Indirizzo <input type="text" name="indirizzo" maxlength="80" value="<?= h($form['indirizzo'] ?? '') ?>"></label>
          <div class="tre-campi">
            <label>CAP <input type="text" name="cap" maxlength="5" inputmode="numeric" value="<?= h($form['cap'] ?? '') ?>"></label>
            <label>Comune <input type="text" name="comune" maxlength="60" value="<?= h($form['comune'] ?? '') ?>"></label>
            <label>Prov. <input type="text" name="provincia" maxlength="2" value="<?= h($form['provincia'] ?? '') ?>"></label>
          </div>
          <div class="due">
            <label>Codice destinatario SDI <input type="text" name="codice_destinatario" maxlength="7" placeholder="0000000"
                   value="<?= h($form['codice_destinatario'] ?? '') ?>"></label>
            <label>PEC <input type="text" name="pec" maxlength="100" value="<?= h($form['pec'] ?? '') ?>"></label>
          </div>
          <button type="submit" name="azione" value="salva_cliente" class="btn verde"><span class="ico">✔</span>Salva cliente</button>
        </form>
      </details>
    </div>

    <!-- Riepilogo conto + emissione -->
    <form class="scheda" method="post">
      <h2>Totale <?= $euro($c['totale']) ?></h2>
      <?php if ($err): ?><div class="msg errore"><?= h($err) ?></div><?php endif; ?>
      <?php if ($errore && !$form): ?><div class="msg errore"><?= h($errore) ?></div><?php endif; ?>
      <?php if ($m = dati_azienda_mancanti()): ?>
        <div class="msg errore">Mancano i dati dell'azienda (<?= h(implode(', ', $m)) ?>): Impostazioni &gt; Fiscale</div>
      <?php endif; ?>
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
      <input type="hidden" name="cliente" value="<?= $cliente_sel ?>">

      <div class="righe-fattura">
        <?php foreach ($c['righe'] as $r): ?>
          <div><span><?= (float)$r['quantita'] == (int)$r['quantita'] ? (int)$r['quantita'] : $r['quantita'] ?> x <?= h(descrizione_riga($r)) ?></span>
               <b><?= $euro($r['quantita'] * $r['prezzo']) ?></b></div>
        <?php endforeach; ?>
        <?php if ($c['coperti'] && $c['prezzo_coperto'] > 0): ?>
          <div><span><?= (int)$c['coperti'] ?> x Coperto</span><b><?= $euro($c['coperti'] * $c['prezzo_coperto']) ?></b></div>
        <?php endif; ?>
      </div>

      <label>Cliente</label>
      <div class="msg <?= $cliente_sel ? 'ok' : 'errore' ?>">
        <?php $cs = $cliente_sel ? q('SELECT * FROM clienti WHERE id = ?', [$cliente_sel])->fetch() : null; ?>
        <?= $cs ? h(nome_cliente($cs)) : 'Scegli un cliente a sinistra (o inseriscine uno nuovo)' ?>
      </div>

      <label>Pagamento</label>
      <div class="scelta tre">
        <?php foreach ($pagamenti as $i => $p): ?>
          <label><input type="radio" name="pagamento" value="<?= $p['id'] ?>" <?= $i === 0 ? 'checked' : '' ?>><span><?= h($p['nome']) ?></span></label>
        <?php endforeach; ?>
      </div>

      <div class="scheda-piede">
        <button type="submit" name="azione" value="emetti" class="btn verde grande" <?= $cliente_sel ? '' : 'disabled' ?>>
          <span class="ico">🧾</span>Emetti fattura</button>
      </div>
    </form>
  </div>
<?php endif; ?>

</body>
</html>
