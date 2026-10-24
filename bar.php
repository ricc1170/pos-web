<?php
// ============================================================
//  bar.php  -  Elenco comande Bar parcheggiate (asporto)
//  Ordinate per orario di ritiro; toccandone una si riapre in comanda.php
//  Le comande si creano dal pulsante Bar (comanda.php?bar=1) e si
//  parcheggiano dalla finestra "Parcheggio" (orario, nome, note).
// ============================================================
require_once __DIR__ . '/includes/auth.php';
richiedi_login();

$op = operatore_loggato();
// Cassa e admin eliminano qualsiasi comanda; il cameriere solo quelle non ancora inviate in cucina
$puo_stornare = in_array($op['ruolo'], ['cassa', 'admin'], true);

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

// Eliminazione di una comanda (pulsantino 🗑 su ogni comanda)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['azione'] ?? '') === 'elimina') {
    controlla_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $c  = q('SELECT * FROM conti WHERE id = ? AND tipo = "asporto" AND stato = "aperto"', [$id])->fetch();
    if ($c) {
        $inviate = q('SELECT COUNT(*) FROM righe WHERE conto_id = ? AND invio > 0', [$id])->fetchColumn();
        if ($inviate && !$puo_stornare) {
            $_SESSION['err'] = 'Comanda già inviata in cucina: per eliminarla chiedi alla cassa.';
        } else {
            q('DELETE FROM righe WHERE conto_id = ?', [$id]);
            q('DELETE FROM conti WHERE id = ?', [$id]);
            $_SESSION['msg'] = 'Comanda ' . ($c['nome_cliente'] ? "di \"{$c['nome_cliente']}\" " : '') . 'eliminata.';
        }
    }
    header('Location: bar.php');
    exit;
}

// Prima quelle in corso al banco (senza orario), poi le parcheggiate per orario di ritiro
$comande = q('
    SELECT c.*, o.nome AS operatore,
           (SELECT COALESCE(SUM(IF(a.a_peso = 1, 1, r.quantita)), 0)
            FROM righe r LEFT JOIN articoli a ON a.id = r.articolo_id WHERE r.conto_id = c.id) AS pezzi,
           (SELECT COUNT(*)                   FROM righe WHERE conto_id = c.id AND invio = 0) AS da_inviare,
           (SELECT COUNT(*)                   FROM righe WHERE conto_id = c.id AND invio > 0) AS inviate
    FROM conti c
    LEFT JOIN operatori o ON o.id = c.operatore_id
    WHERE c.tipo = "asporto" AND c.stato = "aperto"
    ORDER BY c.orario_ritiro IS NOT NULL, c.orario_ritiro, c.id
')->fetchAll();

$valuta = impostazione('valuta', '€');
$adesso = date('Y-m-d H:i:s');

$msg = $_SESSION['msg'] ?? '';
$err = $_SESSION['err'] ?? '';
unset($_SESSION['msg'], $_SESSION['err']);
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title><?= h(impostazione('nome_locale')) ?> - Parcheggi</title>
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
    <span class="data">Comande Bar parcheggiate</span>
    <span><?= date('H:i') ?></span>
  </div>

  <div class="main">
    <div class="navcol">
      <a class="btn menu" href="sala.php"><span class="ico">🏠</span>Menu</a>
      <a class="btn verde" href="comanda.php?bar=1"><span class="ico">🍹</span>Nuova Bar</a>
    </div>

    <div class="ordini">
      <?php if ($msg): ?><div class="msg ok riga-intera"><?= h($msg) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="msg errore riga-intera"><?= h($err) ?></div><?php endif; ?>

      <?php foreach ($comande as $c):
        $parcheggiata = $c['orario_ritiro'] !== null;
        $ritardo      = $parcheggiata && $c['orario_ritiro'] < $adesso;
        $eliminabile  = $puo_stornare || !$c['inviate'];
      ?>
        <div class="ordine-box">
        <a class="ordine <?= $ritardo ? 'ritardo' : '' ?> <?= $parcheggiata ? '' : 'sospesa' ?>"
           href="comanda.php?conto=<?= $c['id'] ?>">
          <span class="ora"><?= $parcheggiata ? date('H:i', strtotime($c['orario_ritiro'])) : '--:--' ?></span>
          <span class="cliente"><?= h($c['nome_cliente'] ?: 'Senza nome') ?></span>
          <span class="dettagli">
            <?= (float)$c['pezzi'] == 1 ? '1 pezzo' : (float)$c['pezzi'] . ' pezzi' ?>
            · <b><?= $valuta . ' ' . number_format($c['totale'], 2, ',', '.') ?></b>
          </span>
          <?php if ($c['note']): ?>
            <span class="note-ordine">» <?= h($c['note']) ?></span>
          <?php endif; ?>
          <?php if (!$parcheggiata): ?>
            <span class="stato vuota">In corso · <?= h($c['operatore'] ?: '?') ?></span>
          <?php elseif ($c['da_inviare'] > 0): ?>
            <span class="stato da-inviare">Da inviare</span>
          <?php else: ?>
            <span class="stato inviata">Inviata</span>
          <?php endif; ?>
        </a>
        <?php if ($eliminabile): ?>
          <form method="post" class="elimina-ordine">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
            <input type="hidden" name="id" value="<?= $c['id'] ?>">
            <button type="submit" name="azione" value="elimina" title="Elimina comanda">🗑</button>
          </form>
        <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (!$comande): ?>
        <div class="vuoto">Nessuna comanda parcheggiata. Premi "Nuova Bar" per crearne una.</div>
      <?php endif; ?>
    </div>
  </div>

</body>
</html>
