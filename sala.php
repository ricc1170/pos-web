<?php
// ============================================================
//  sala.php  -  Mappa tavoli (schermata 1 di TouchEat)
// ============================================================
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/stampa.php';
require_once __DIR__ . '/includes/cambio_tavolo.php';
richiedi_login();

$op = operatore_loggato();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

// ------------------------------------------------------------
//  Cambio tavolo: sala.php?cambio=1 (scegli il tavolo da spostare)
//                 sala.php?cambio=1&da=N (scegli la destinazione) -> POST
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['azione'] ?? '') === 'cambio') {
    controlla_csrf();
    $da = (int)($_POST['da'] ?? 0);
    $a  = (int)($_POST['a'] ?? 0);
    try {
        [$esito, $testo] = cambia_tavolo($da, $a, !empty($_POST['unisci']));
        $_SESSION[$esito === 'ok' ? 'msg' : 'err'] = $testo;
    } catch (ErroreCambio $e) {
        $_SESSION['err'] = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Cambio tavolo: ' . $e->getMessage());
        $_SESSION['err'] = 'Cambio tavolo non riuscito (errore del database)';
    }
    $sala_a = (int)q('SELECT sala_id FROM tavoli WHERE id = ?', [$a])->fetchColumn();
    header('Location: sala.php' . ($sala_a ? "?sala=$sala_a" : ''));
    exit;
}

$cambio = isset($_GET['cambio']);
$da_tavolo = $cambio && isset($_GET['da'])
    ? q('SELECT t.*, c.id AS conto_id FROM tavoli t JOIN conti c ON c.tavolo_id = t.id AND c.stato = "aperto"
         WHERE t.id = ?', [(int)$_GET['da']])->fetch() ?: null
    : null;
// Parametri da conservare cambiando sala durante il cambio tavolo
$param_cambio = $cambio ? '&cambio=1' . ($da_tavolo ? '&da=' . $da_tavolo['id'] : '') : '';

$msg = $_SESSION['msg'] ?? '';
$err = $_SESSION['err'] ?? '';
unset($_SESSION['msg'], $_SESSION['err']);

// Avviso se si stampa tramite ponte (sito online), ci sono stampanti attive e il ponte non è collegato
$avviso_ponte = serve_ponte()
    && q('SELECT 1 FROM stampanti WHERE attiva = 1 LIMIT 1')->fetch()
    && !ponte_collegato();

// Elenco sale per le tab in alto
$sale = q('SELECT * FROM sale ORDER BY ordine, id')->fetchAll();

// Sala selezionata (default: la prima)
$sala_id = (int)($_GET['sala'] ?? ($sale[0]['id'] ?? 0));

// Tavoli della sala + eventuale conto aperto (per colorarli)
$tavoli = q('
    SELECT t.*, c.id AS conto_id, c.totale
    FROM tavoli t
    LEFT JOIN conti c ON c.tavolo_id = t.id AND c.stato = "aperto"
    WHERE t.sala_id = ? AND t.attivo = 1
    ORDER BY t.nome
', [$sala_id])->fetchAll();

$valuta = impostazione('valuta', '€');

// ------------------------------------------------------------
//  Info Sala: tavoli occupati della sala con coperti, totale, tempi; riepilogo di tutto il locale
// ------------------------------------------------------------
$info_tavoli = q('
    SELECT t.id, t.nome, c.coperti, c.totale, o.nome AS operatore,
           TIMESTAMPDIFF(MINUTE, c.data_apertura, NOW()) AS min_aperto,
           (SELECT TIMESTAMPDIFF(MINUTE, MAX(r.creata_il), NOW()) FROM righe r WHERE r.conto_id = c.id AND r.invio > 0) AS min_comanda,
           (SELECT COUNT(*) FROM righe r WHERE r.conto_id = c.id AND r.invio = 0)            AS da_inviare
    FROM tavoli t
    JOIN conti c ON c.tavolo_id = t.id AND c.stato = "aperto"
    LEFT JOIN operatori o ON o.id = c.operatore_id
    WHERE t.sala_id = ? AND t.attivo = 1
    ORDER BY c.data_apertura', [$sala_id])->fetchAll();
$info_locale = q('
    SELECT
      (SELECT COUNT(*) FROM tavoli WHERE attivo = 1)                                                        AS tavoli,
      (SELECT COUNT(*) FROM conti WHERE stato = "aperto" AND tipo = "tavolo")                               AS occupati,
      (SELECT COALESCE(SUM(coperti), 0) FROM conti WHERE stato = "aperto" AND tipo = "tavolo")              AS coperti,
      (SELECT COALESCE(SUM(totale), 0) FROM conti WHERE stato = "aperto" AND tipo = "tavolo")               AS totale_tavoli,
      (SELECT COUNT(*) FROM conti WHERE stato = "aperto" AND tipo = "asporto")                              AS bar,
      (SELECT COALESCE(SUM(totale), 0) FROM conti WHERE stato = "aperto" AND tipo = "asporto")              AS totale_bar')->fetch();

// "1 h 25 min" da un numero di minuti (calcolati dal database: niente problemi di fuso orario)
function da_quanto($min): string {
    if ($min === null) return '—';
    $min = max(0, (int)$min);
    return $min < 60 ? "$min min" : intdiv($min, 60) . ' h ' . ($min % 60) . ' min';
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title><?= h(impostazione('nome_locale')) ?> - Sala</title>
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

  <!-- Barra titolo (stile TouchEat) -->
  <div class="topbar">
    <span>POS - Rel. 1.0 - Int.: <?= h(impostazione('nome_locale')) ?></span>
    <span class="cassa">Cassa: <?= h(impostazione('numero_cassa', '1')) ?></span>
  </div>

  <!-- Riga info: utente, data, ora -->
  <div class="inforow">
    <span>Utente <?= h($op['nome']) ?></span>
    <span class="data">Data Lavoro <?= date('d/m/Y') ?></span>
    <span><?= date('H:i') ?></span>
  </div>

  <?php if ($avviso_ponte): ?>
    <!-- Sparisce da solo quando il ponte si collega (controllo ogni 10 secondi) -->
    <div class="avviso-ponte" id="avvisoPonte">
      ⚠ Ponte di stampa NON collegato: le comande restano in coda e non escono.
      Accendi il PC del locale (il ponte parte da solo all'accesso a Windows) o avvia <b>avvia-ponte.bat</b>.
    </div>
  <?php endif; ?>

  <?php if ($msg): ?><div class="striscia ok"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="striscia errore"><?= h($err) ?></div><?php endif; ?>

  <?php if ($cambio): ?>
    <!-- Modalità cambio tavolo -->
    <div class="striscia cambio">
      <span>🔁 <?= $da_tavolo
          ? 'Tavolo <b>' . h($da_tavolo['nome']) . '</b> → tocca il tavolo di destinazione (anche in un\'altra sala)'
          : 'Cambio tavolo: tocca il tavolo da spostare' ?></span>
      <a class="btn scuro" href="sala.php?sala=<?= $sala_id ?>">✕ Annulla</a>
    </div>
  <?php endif; ?>

  <!-- Tab delle sale -->
  <div class="sale-tabs">
    <?php foreach ($sale as $s): ?>
      <a class="sala-tab <?= $s['id'] == $sala_id ? 'attiva' : '' ?>"
         href="sala.php?sala=<?= $s['id'] ?><?= $param_cambio ?>"><?= h($s['nome']) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="main">
    <!-- Colonna navigazione sinistra -->
    <div class="navcol">
      <a class="btn scuro" href="logout.php"><span class="ico">⏻</span>Esci</a>
      <?php if ($op['ruolo'] === 'admin'): ?>
        <a class="btn" href="impostazioni.php"><span class="ico">⚙</span>Impostazioni</a>
      <?php endif; ?>
    </div>

    <!-- Piantina con i tavoli -->
    <div class="mappa-wrap">
      <div class="mappa">
        <?php foreach ($tavoli as $t):
          $occupato = !empty($t['conto_id']);
          $stile = "left:{$t['pos_x']}px; top:{$t['pos_y']}px; width:{$t['larghezza']}px; height:{$t['altezza']}px;";
          // Dove porta il tocco: comanda; in cambio tavolo: scelta origine / destinazione
          $classe = '';
          if (!$cambio) {
              $link = "comanda.php?tavolo={$t['id']}";
          } elseif (!$da_tavolo) {
              $link = $occupato ? "sala.php?sala=$sala_id&cambio=1&da={$t['id']}" : '';
              $classe = $occupato ? 'scegli' : 'spento';
          } elseif ($t['id'] == $da_tavolo['id']) {
              $link = '';
              $classe = 'origine';
          } else {
              $link = '#';
              $classe = 'scegli destinazione';
          }
        ?>
          <a class="tavolo <?= h($t['forma']) ?> <?= $occupato ? 'occupato' : '' ?> <?= $classe ?>"
             style="<?= $stile ?>"
             <?= $link !== '' ? 'href="' . h($link) . '"' : '' ?>
             data-id="<?= $t['id'] ?>" data-nome="<?= h($t['nome']) ?>" data-occupato="<?= $occupato ? 1 : 0 ?>">
            <?= h($t['nome']) ?>
            <?php if ($occupato): ?>
              <span class="badge"><?= $valuta . number_format($t['totale'], 2, ',', '.') ?></span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Colonna strumenti destra (per ora segnaposto) -->
    <div class="toolcol">
      <a class="btn" href="comanda.php?bar=1"><span class="ico">🍹</span>Bar</a>
      <div class="btn"><span class="ico">🪑</span>Apre Tav</div>
      <div class="btn"><span class="ico">💶</span>Incasso</div>
      <a class="btn <?= $cambio ? 'verde' : '' ?>" href="sala.php?sala=<?= $sala_id ?><?= $cambio ? '' : '&cambio=1' ?>">
        <span class="ico">🔁</span>Cambio Tav</a>
      <button type="button" class="btn" id="btnInfoSala"><span class="ico">ℹ️</span>Info Sala</button>
    </div>
  </div>

<?php
  $nome_sala = '';
  foreach ($sale as $s) if ($s['id'] == $sala_id) $nome_sala = $s['nome'];
  $euro = fn($n) => $valuta . ' ' . number_format((float)$n, 2, ',', '.');
  $coperti_sala = array_sum(array_column($info_tavoli, 'coperti'));
  $totale_sala  = array_sum(array_column($info_tavoli, 'totale'));
?>
  <!-- Finestra Info Sala -->
  <div class="finestra-sfondo" id="infoSala" hidden>
    <div class="finestra scheda info-sala">
      <h2>Info sala · <?= h($nome_sala) ?> <small>alle <?= date('H:i') ?></small></h2>

      <div class="info-numeri">
        <div><b><?= count($info_tavoli) ?></b><span>occupati</span></div>
        <div><b><?= count($tavoli) - count($info_tavoli) ?></b><span>liberi</span></div>
        <div><b><?= (int)$coperti_sala ?></b><span>coperti</span></div>
        <div class="totale-info"><b><?= $euro($totale_sala) ?></b><span>da incassare</span></div>
      </div>

      <div class="info-elenco">
        <?php foreach ($info_tavoli as $t): ?>
          <a class="info-tavolo <?= $t['da_inviare'] ? 'da-inviare' : '' ?>" href="comanda.php?tavolo=<?= $t['id'] ?>">
            <span class="nome">Tavolo <b><?= h($t['nome']) ?></b></span>
            <span><?= (int)$t['coperti'] ?> cop.</span>
            <span><b><?= $euro($t['totale']) ?></b></span>
            <span>aperto da <?= da_quanto($t['min_aperto']) ?></span>
            <span>ult. comanda <?= $t['min_comanda'] !== null ? da_quanto($t['min_comanda']) . ' fa' : '—' ?></span>
            <span><?= h($t['operatore'] ?? '') ?></span>
            <?php if ($t['da_inviare']): ?><span class="avviso-righe">⚠ <?= (int)$t['da_inviare'] ?> da inviare</span><?php endif; ?>
          </a>
        <?php endforeach; ?>
        <?php if (!$info_tavoli): ?><div class="vuoto">Nessun tavolo occupato in questa sala.</div><?php endif; ?>
      </div>

      <div class="info-locale">
        <b>Tutto il locale:</b>
        <?= (int)$info_locale['occupati'] ?> tavoli occupati su <?= (int)$info_locale['tavoli'] ?> ·
        <?= (int)$info_locale['coperti'] ?> coperti · tavoli <?= $euro($info_locale['totale_tavoli']) ?> ·
        Bar <?= (int)$info_locale['bar'] ?> comande <?= $euro($info_locale['totale_bar']) ?> ·
        <b>totale aperto <?= $euro($info_locale['totale_tavoli'] + $info_locale['totale_bar']) ?></b>
      </div>

      <div class="scheda-piede">
        <button type="button" class="btn scuro" id="chiudiInfoSala"><span class="ico">✕</span>Chiudi</button>
      </div>
    </div>
  </div>
  <script>
    document.getElementById('btnInfoSala').addEventListener('click', () => document.getElementById('infoSala').hidden = false);
    document.getElementById('chiudiInfoSala').addEventListener('click', () => document.getElementById('infoSala').hidden = true);
  </script>

<?php if ($da_tavolo): ?>
  <!-- Cambio tavolo: invio della scelta (con conferma se la destinazione è occupata) -->
  <form method="post" id="formCambio" hidden>
    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
    <input type="hidden" name="azione" value="cambio">
    <input type="hidden" name="da" value="<?= $da_tavolo['id'] ?>">
    <input type="hidden" name="a" id="cambioA">
    <input type="hidden" name="unisci" id="cambioUnisci">
  </form>
  <script>
    document.querySelectorAll('.tavolo.destinazione').forEach(t => t.addEventListener('click', e => {
      e.preventDefault();
      const da = <?= json_encode($da_tavolo['nome']) ?>;
      if (t.dataset.occupato === '1') {
        if (!confirm('Il tavolo ' + t.dataset.nome + ' è occupato: unire il tavolo ' + da + ' al tavolo ' + t.dataset.nome + '?')) return;
        document.getElementById('cambioUnisci').value = '1';
      }
      document.getElementById('cambioA').value = t.dataset.id;
      document.getElementById('formCambio').submit();
    }));
  </script>
<?php endif; ?>

<script>
  // Avviso ponte: ricontrollo finché non si collega
  if (document.getElementById('avvisoPonte')) {
    const controlla = setInterval(async () => {
      try {
        const r = await (await fetch('api/stato_ponte.php')).json();
        if (r.collegato) { document.getElementById('avvisoPonte').remove(); clearInterval(controlla); }
      } catch (e) {}
    }, 10000);
  }

  // Aggiorna l'orologio ogni minuto senza ricaricare
  setInterval(() => {
    const el = document.querySelector('.inforow span:last-child');
    const d = new Date();
    el.textContent = String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0');
  }, 10000);
</script>
</body>
</html>
