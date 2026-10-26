<?php
// ============================================================
//  comanda.php  -  Presa comanda (schermate 2/3/5 di TouchEat)
//  Sinistra: categorie · Centro: prodotti · Destra: conto + tastierino
//  Il conto si aggiorna via AJAX con api/conto.php (vedi assets/js/comanda.js)
//
//  comanda.php?tavolo=N  -> conto del tavolo
//  comanda.php?bar=1     -> nuova comanda Bar (poi "Parcheggio": orario, nome, note)
//  comanda.php?conto=N   -> comanda Bar parcheggiata, riaperta da bar.php
// ============================================================
require_once __DIR__ . '/includes/auth.php';
richiedi_login();

$op = operatore_loggato();

$tavolo_id = (int)($_GET['tavolo'] ?? 0);
$conto_id  = (int)($_GET['conto'] ?? 0);
$bar       = $conto_id || isset($_GET['bar']);

if ($conto_id) {
    $tavolo_id = 0;
    if (!q('SELECT id FROM conti WHERE id = ? AND tipo = "asporto" AND stato = "aperto"', [$conto_id])->fetch()) {
        header('Location: bar.php');
        exit;
    }
}

if ($bar) {
    $titolo   = 'Bar · Asporto';
    $indietro = 'sala.php';
    // Comande Bar parcheggiate (numero sul pulsante Richiama)
    $n_asporto = (int)q('SELECT COUNT(*) FROM conti
                         WHERE tipo = "asporto" AND stato = "aperto" AND orario_ritiro IS NOT NULL')->fetchColumn();
    // Operatori del banco: ognuno ha la sua comanda in corso, si passa dall'uno all'altro col tasto
    $operatori_banco = q('SELECT id, nome FROM operatori WHERE attivo = 1 ORDER BY id')->fetchAll();
} else {
    $tavolo = q('SELECT t.*, s.nome AS sala FROM tavoli t JOIN sale s ON s.id=t.sala_id WHERE t.id=?', [$tavolo_id])->fetch();
    if (!$tavolo) { die('Tavolo non trovato'); }
    $titolo   = 'Tavolo ' . $tavolo['nome'] . ' · ' . $tavolo['sala'];
    $indietro = 'sala.php?sala=' . $tavolo['sala_id'];
}

$categorie = q('SELECT * FROM categorie ORDER BY ordine, id')->fetchAll();
$articoli  = q('SELECT * FROM articoli WHERE visibile = 1 ORDER BY ordine, nome')->fetchAll();

// Varianti attive con le categorie per cui valgono (la finestra Varianti mostra quelle della categoria del prodotto)
$varianti = q('SELECT v.id, v.nome, v.prezzo, v.tutte,
                      (SELECT GROUP_CONCAT(vc.categoria_id) FROM varianti_categorie vc WHERE vc.variante_id = v.id) AS categorie
               FROM varianti v WHERE v.attiva = 1 ORDER BY v.ordine, v.nome')->fetchAll();
foreach ($varianti as &$v) {
    $v = ['id' => (int)$v['id'], 'nome' => $v['nome'], 'prezzo' => (float)$v['prezzo'], 'tutte' => (bool)$v['tutte'],
          'categorie' => $v['categorie'] ? array_map('intval', explode(',', $v['categorie'])) : []];
}
unset($v);

// Orari rapidi per il parcheggio: prossimi quarti d'ora per le 3 ore successive
$slot  = [];
$primo = (int)ceil(time() / 900) * 900;
for ($i = 0; $i < 12; $i++) {
    $slot[] = date('H:i', $primo + $i * 900);
}

$valuta = impostazione('valuta', '€');
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>Comanda - <?= h($titolo) ?></title>
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
    <span class="data"><?= h($titolo) ?></span>
    <span><?= date('H:i') ?></span>
  </div>

  <div class="main">
    <!-- Categorie -->
    <div class="catcol">
      <?php foreach ($categorie as $i => $c): ?>
        <button type="button" class="cat <?= $i === 0 ? 'attiva' : '' ?>"
                style="--colore: <?= h($c['colore']) ?>"
                data-cat="<?= $c['id'] ?>"><?= h($c['nome']) ?></button>
      <?php endforeach; ?>
      <!-- Varianti dell'ultimo prodotto inserito (o della riga selezionata) -->
      <button type="button" class="cat cat-varianti" id="catVarianti">VARIANTI</button>
    </div>

    <!-- Pulsanti varianti: tocco = "+" (aggiunta); "−" prima o subito dopo = "senza" -->
    <div class="prodotti griglia-varianti" id="grigliaVarianti" hidden>
      <div class="varianti-testa">
        <span>Varianti per: <b id="variantiPer"></b></span>
        <button type="button" class="var-senza" id="btnSenza">− SENZA</button>
      </div>
      <div class="varianti-pulsanti" id="variantiPulsanti"></div>
    </div>

    <!-- Prodotti (tutti nella pagina, si mostrano quelli della categoria scelta) -->
    <div class="prodotti">
      <?php foreach ($articoli as $a): ?>
        <button type="button" class="prod" style="--colore: <?= h($a['colore']) ?>"
                data-cat="<?= $a['categoria_id'] ?>" data-id="<?= $a['id'] ?>" data-peso="<?= $a['a_peso'] ? 1 : 0 ?>">
          <span class="nome"><?= h($a['nome']) ?></span>
          <span class="prezzo"><?= $valuta . ' ' . number_format($a['prezzo'], 2, ',', '.') . ($a['a_peso'] ? '/kg' : '') ?></span>
        </button>
      <?php endforeach; ?>
      <div class="vuoto">Nessun articolo in questa categoria</div>
    </div>

    <!-- Pannello conto -->
    <div class="contopanel">
      <?php if ($bar): ?>
        <!-- Tasti operatore del banco (il numero = articoli nella sua comanda in corso) -->
        <div class="banco-tabs">
          <?php foreach ($operatori_banco as $o): ?>
            <a class="banco-op" href="comanda.php?bar=1" data-op="<?= $o['id'] ?>">
              <span class="nome"><?= h($o['nome']) ?></span><span class="pezzi"></span></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <div class="conto-testa">
        <?php if ($bar): ?>
          <span id="testaBar">Comanda <b>Bar</b></span>
        <?php else: ?>
          <span>Tavolo <b><?= h($tavolo['nome']) ?></b></span>
          <button type="button" class="link" id="copertiLbl" data-az="coperti">Coperti 0</button>
        <?php endif; ?>
      </div>

      <div class="righe-head"><span>Qtà</span><span>Descrizione</span><span>Prezzo</span></div>
      <div class="righe" id="righe"></div>
      <?php if ($bar): ?>
        <!-- Comanda Bar: numero totale di prodotti (somma delle quantità) -->
        <div class="righe-piede"><span>Totale prodotti</span><b id="contaProdotti">0</b></div>
      <?php endif; ?>

      <div class="riga-azioni">
        <button type="button" class="btn" data-az="meno">−</button>
        <button type="button" class="btn" data-az="piu">+</button>
        <button type="button" class="btn viola" data-az="varianti">Varianti</button>
        <button type="button" class="btn" data-az="nota">Note</button>
        <button type="button" class="btn rosso" data-az="elimina">Elimina</button>
      </div>

      <div class="totale"><span>TOTALE</span><span id="totale"><?= $valuta ?> 0,00</span></div>

      <!-- Tastierino nascosto: si apre col pulsante -->
      <div class="tastierino chiuso" id="tastierino">
        <button type="button" class="btn apri-tastierino" id="btnTastierino">
          <span class="ico">🔢</span><span class="testo">Tastierino</span></button>
        <div class="qta-display" id="qtaDisplay"></div>
        <div class="keypad">
          <?php foreach ([7,8,9,4,5,6,1,2,3] as $n): ?>
            <button type="button" class="key" data-num="<?= $n ?>"><?= $n ?></button>
          <?php endforeach; ?>
          <button type="button" class="key canc" data-num="C">C</button>
          <button type="button" class="key" data-num="0">0</button>
          <?php if ($bar): ?>
            <button type="button" class="key" disabled></button>
          <?php else: ?>
            <button type="button" class="key" data-az="coperti">Cop.</button>
          <?php endif; ?>
        </div>
      </div>

      <!-- Fattura (chiude il conto) per cassa e admin. Proforma e scontrino si fanno dal programma della cassa -->
      <?php if (in_array($op['ruolo'], ['cassa', 'admin'], true)): ?>
        <div class="conto-cassa">
          <button type="button" class="btn viola" data-az="fattura"><span class="ico">📑</span>Fattura</button>
        </div>
      <?php endif; ?>

      <div class="conto-piede <?= $bar ? 'quattro' : '' ?>">
        <a class="btn scuro" href="<?= h($indietro) ?>" id="btnSala"><span class="ico">🪑</span>Sala</a>
        <button type="button" class="btn verde" data-az="invia"><span class="ico">🍕</span>Invia</button>
        <?php if ($bar): ?>
          <button type="button" class="btn arancio" data-az="parcheggio"><span class="ico">🅿</span>Parcheggio</button>
          <!-- Elenco delle comande parcheggiate, per richiamarne una -->
          <a class="btn" href="bar.php" id="btnRichiama">
            <span class="ico">📋</span><span id="richiamaTesto">Richiama<?= $n_asporto ? " ($n_asporto)" : '' ?></span></a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($bar): ?>
  <!-- Finestra Parcheggio: orario di ritiro, nome, note -->
  <div class="finestra-sfondo" id="parcheggio" hidden>
    <form class="finestra scheda" id="formParcheggio">
      <h2>Parcheggio comanda</h2>

      <label>Orario di ritiro
        <input type="time" name="orario" id="orario" required>
      </label>
      <div class="slot">
        <?php foreach ($slot as $s): ?>
          <button type="button" class="key" data-ora="<?= $s ?>"><?= $s ?></button>
        <?php endforeach; ?>
      </div>

      <label>Nome cliente
        <input type="text" name="nome" id="nome" maxlength="60">
      </label>

      <label>Note
        <textarea name="note" id="noteConto" maxlength="255" rows="2"></textarea>
      </label>

      <!-- Si sceglie col pulsante se inviare in cucina i prodotti non ancora inviati -->
      <div class="scheda-piede parcheggia-scelta">
        <button type="submit" name="invia" value="1" class="btn verde"><span class="ico">🍕</span>Parcheggia e invia</button>
        <button type="submit" name="invia" value="0" class="btn arancio"><span class="ico">🅿</span>Parcheggia senza inviare</button>
      </div>
      <div class="scheda-piede">
        <button type="button" class="btn scuro" id="chiudiParcheggio"><span class="ico">✕</span>Indietro</button>
        <button type="button" class="btn rosso" id="annullaComanda"><span class="ico">🗑</span>Elimina comanda</button>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <?php if (!$bar): ?>
  <!-- Finestra Coperti: compare aprendo un tavolo libero -->
  <div class="finestra-sfondo" id="finestraCoperti" hidden>
    <div class="finestra scheda coperti-finestra">
      <h2>Tavolo <?= h($tavolo['nome']) ?> · quanti coperti?</h2>
      <div class="coperti-numeri">
        <?php for ($n = 1; $n <= 12; $n++): ?>
          <button type="button" class="key" data-coperti="<?= $n ?>"><?= $n ?></button>
        <?php endfor; ?>
      </div>
      <div class="scheda-piede tre">
        <button type="button" class="btn" id="copertiAltro"><span class="ico">➕</span>Altro…</button>
        <button type="button" class="btn scuro" id="copertiNessuno"><span class="ico">0</span>Nessuno</button>
        <a class="btn scuro" href="<?= h($indietro) ?>"><span class="ico">🪑</span>Sala</a>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Finestra Varianti: per ogni ingrediente "−" (senza, gratis) e "+" (aggiunta, a pagamento) -->
  <div class="finestra-sfondo" id="varianti" hidden>
    <div class="finestra scheda varianti-finestra">
      <h2 id="variantiTitolo">Varianti</h2>
      <div class="varianti-elenco" id="variantiElenco"></div>
      <div class="varianti-riepilogo" id="variantiRiepilogo"></div>
      <div class="scheda-piede">
        <button type="button" class="btn verde" id="variantiFatto"><span class="ico">✔</span>Fatto</button>
        <button type="button" class="btn scuro" id="variantiChiudi"><span class="ico">✕</span>Annulla</button>
      </div>
    </div>
  </div>

  <div class="avviso" id="avviso"></div>

<script>
  const POS = {
    tavolo: <?= $tavolo_id ?>,
    conto: <?= $conto_id ?>,
    bar: <?= $bar ? 'true' : 'false' ?>,
    operatore: <?= (int)$op['id'] ?>,
    varianti: <?= json_encode($varianti) ?>,
    valuta: <?= json_encode($valuta) ?>,
    puoStornare: <?= in_array($op['ruolo'], ['cassa', 'admin'], true) ? 'true' : 'false' ?>
  };
</script>
<script src="assets/js/comanda.js?v=<?= @filemtime(__DIR__ . '/assets/js/comanda.js') ?>"></script>
</body>
</html>
