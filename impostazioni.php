<?php
// ============================================================
//  impostazioni.php  -  Pagina unica delle impostazioni (solo admin)
//  Un riquadro per ogni sezione: operatori, sale e tavoli, prodotti,
//  categorie, dati fiscali, dati del locale. (Stampanti e cassa fiscale stanno nel programma desktop.)
// ============================================================
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/documenti.php';
$op = richiedi_admin();

$conta = fn(string $sql) => (int)q($sql)->fetchColumn();

$sezioni = [
    ['operatori.php', '👤', 'Operatori',
     $conta('SELECT COUNT(*) FROM operatori WHERE attivo = 1') . ' attivi', 'Nomi, PIN e ruoli'],
    ['tavoli.php', '🪑', 'Sale e tavoli',
     $conta('SELECT COUNT(*) FROM sale') . ' sale · ' . $conta('SELECT COUNT(*) FROM tavoli WHERE attivo = 1') . ' tavoli',
     'Piantina, posizione e forma dei tavoli'],
    ['articoli.php', '🍕', 'Prodotti',
     $conta('SELECT COUNT(*) FROM articoli WHERE visibile = 1') . ' visibili', 'Nome, prezzo, IVA e colore'],
    ['categorie.php', '🗂', 'Categorie',
     $conta('SELECT COUNT(*) FROM categorie') . ' categorie', 'Gruppi di prodotti e destinazione (cucina/pizzeria/bar/banco)'],
    ['varianti.php', '➕', 'Varianti',
     $conta('SELECT COUNT(*) FROM varianti WHERE attiva = 1') . ' attive', 'Aggiunte e tolte (ingredienti) per categoria'],
    ['note.php', '📝', 'Note rapide',
     $conta('SELECT COUNT(*) FROM note_rapide WHERE attiva = 1') . ' attive', 'Integrale, bianca, celiaco...'],
    ['fiscale.php', '🧾', 'Fiscale',
     dati_azienda_mancanti() ? 'Dati azienda incompleti' : 'Dati azienda completi', 'Dati azienda e numerazione delle fatture'],
    ['statistiche.php', '📊', 'Statistiche',
     'Oggi ' . impostazione('valuta', '€') . ' ' . number_format((float)q('SELECT COALESCE(SUM(totale), 0) FROM conti
        WHERE stato = "chiuso" AND ' . giorno_lavoro_sql('data_chiusura') . ' = ' . giorno_lavoro_sql('NOW()'))->fetchColumn(), 2, ',', '.'),
     'Incassi, prodotti, categorie, orari e operatori'],
    ['documenti.php', '📄', 'Documenti',
     $conta('SELECT COUNT(*) FROM documenti WHERE tipo <> "proforma"') . ' emessi', 'Scontrini e fatture, XML da scaricare'],
    ['generali.php', '🏪', 'Dati del locale',
     impostazione('nome_locale', ''), 'Nome, numero cassa, coperto, valuta'],
];
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title><?= h(impostazione('nome_locale')) ?> - Impostazioni</title>
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
    <span class="data">Impostazioni</span>
    <span><?= date('H:i') ?></span>
  </div>

  <div class="main">
    <div class="navcol">
      <a class="btn menu" href="sala.php"><span class="ico">🏠</span>Menu</a>
    </div>

    <div class="sezioni">
      <?php foreach ($sezioni as [$link, $ico, $titolo, $dato, $descr]): ?>
        <a class="sezione" href="<?= $link ?>">
          <span class="ico"><?= $ico ?></span>
          <span class="titolo"><?= h($titolo) ?></span>
          <span class="dato"><?= h($dato) ?></span>
          <span class="descr"><?= h($descr) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

</body>
</html>
