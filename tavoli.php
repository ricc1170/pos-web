<?php
// ============================================================
//  tavoli.php  -  Gestione sale e tavoli (solo admin)
//  In alto: tab delle sale · Centro: piantina modificabile (trascina i tavoli)
//  Destra: scheda del tavolo selezionato, oppure della sala
// ============================================================
require_once __DIR__ . '/includes/auth.php';
richiedi_login();

$op = operatore_loggato();
if ($op['ruolo'] !== 'admin') {
    header('Location: sala.php');
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function vai(string $url) {
    header("Location: $url");
    exit;
}

// Nome già usato da un altro tavolo attivo (in qualsiasi sala)?
function nome_tavolo_usato(string $nome, int $escludi_id): bool {
    return (bool)q('SELECT id FROM tavoli WHERE nome = ? AND attivo = 1 AND id <> ?', [$nome, $escludi_id])->fetch();
}

$sale    = q('SELECT * FROM sale ORDER BY ordine, id')->fetchAll();
$sale_id = array_column($sale, 'id');

// ------------------------------------------------------------
//  Spostamento tavolo trascinato sulla piantina (fetch → JSON)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['azione'] ?? '') === 'sposta') {
    header('Content-Type: application/json; charset=utf-8');
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit(json_encode(['errore' => 'Richiesta non valida, ricarica la pagina']));
    }
    q('UPDATE tavoli SET pos_x = ?, pos_y = ? WHERE id = ?',
      [max(0, min(5000, (int)$_POST['x'])), max(0, min(5000, (int)$_POST['y'])), (int)$_POST['id']]);
    exit(json_encode(['ok' => true]));
}

// ------------------------------------------------------------
//  Form (POST → redirect)
// ------------------------------------------------------------
$errore = '';
$form_tavolo = null;   // valori da ripresentare dopo un errore
$form_sala   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        die('Richiesta non valida, ricarica la pagina');
    }
    $azione  = $_POST['azione'] ?? '';
    $sala_id = (int)($_POST['sala_id'] ?? 0);

    switch ($azione) {

        case 'nuovo_tavolo':
            if (!in_array($sala_id, $sale_id)) vai('tavoli.php');
            // Nome proposto: il numero più alto tra i tavoli attivi + 1
            $max = (int)q('SELECT COALESCE(MAX(CAST(nome AS UNSIGNED)), 0) FROM tavoli WHERE attivo = 1')->fetchColumn();
            q('INSERT INTO tavoli (sala_id, nome, posti, pos_x, pos_y, larghezza, altezza, forma)
               VALUES (?, ?, 4, 20, 20, 90, 90, "rettangolo")', [$sala_id, (string)($max + 1)]);
            $_SESSION['msg'] = 'Tavolo ' . ($max + 1) . ' aggiunto in alto a sinistra: trascinalo dove vuoi.';
            vai("tavoli.php?sala=$sala_id&tavolo=" . db()->lastInsertId());

        case 'salva_tavolo':
            $id = (int)($_POST['id'] ?? 0);
            $form_tavolo = [
                'id'        => $id,
                'sala_id'   => $sala_id,
                'nome'      => trim($_POST['nome'] ?? ''),
                'posti'     => (int)($_POST['posti'] ?? 0),
                'forma'     => ($_POST['forma'] ?? '') === 'tondo' ? 'tondo' : 'rettangolo',
                'larghezza' => (int)($_POST['larghezza'] ?? 0),
                'altezza'   => (int)($_POST['altezza'] ?? 0),
                'attivo'    => isset($_POST['attivo']) ? 1 : 0,
            ];
            $t = $form_tavolo;
            if ($t['nome'] === '' || mb_strlen($t['nome']) > 20) {
                $errore = 'Il nome è obbligatorio (max 20 caratteri)';
            } elseif ($t['attivo'] && nome_tavolo_usato($t['nome'], $id)) {
                $errore = "Esiste già un tavolo \"{$t['nome']}\"";
            } elseif (!in_array($t['sala_id'], $sale_id)) {
                $errore = 'Scegli una sala';
            } elseif ($t['posti'] < 1 || $t['posti'] > 99) {
                $errore = 'Posti: da 1 a 99';
            } elseif ($t['larghezza'] < 40 || $t['larghezza'] > 600 || $t['altezza'] < 40 || $t['altezza'] > 600) {
                $errore = 'Larghezza e altezza: da 40 a 600';
            } elseif (!$t['attivo'] && q('SELECT id FROM conti WHERE tavolo_id = ? AND stato = "aperto"', [$id])->fetch()) {
                $errore = 'Il tavolo ha un conto aperto: non si può nascondere';
            }
            if ($errore) break;

            q('UPDATE tavoli SET sala_id=?, nome=?, posti=?, forma=?, larghezza=?, altezza=?, attivo=? WHERE id=?',
              [$t['sala_id'], $t['nome'], $t['posti'], $t['forma'], $t['larghezza'], $t['altezza'], $t['attivo'], $id]);
            $_SESSION['msg'] = "Tavolo \"{$t['nome']}\" salvato.";
            vai("tavoli.php?sala={$t['sala_id']}&tavolo=$id");

        case 'cancella_tavolo':
            $id = (int)($_POST['id'] ?? 0);
            $t  = q('SELECT * FROM tavoli WHERE id = ?', [$id])->fetch();
            if (!$t) vai('tavoli.php');
            if (q('SELECT id FROM conti WHERE tavolo_id = ? AND stato = "aperto"', [$id])->fetch()) {
                $_SESSION['msg'] = "Il tavolo \"{$t['nome']}\" ha un conto aperto: chiudilo prima di cancellarlo.";
                vai("tavoli.php?sala={$t['sala_id']}&tavolo=$id");
            }
            if (q('SELECT COUNT(*) FROM conti WHERE tavolo_id = ?', [$id])->fetchColumn()) {
                // Ha uno storico di conti: non si può cancellare, lo nascondo
                q('UPDATE tavoli SET attivo = 0 WHERE id = ?', [$id]);
                $_SESSION['msg'] = "Il tavolo \"{$t['nome']}\" ha già dei conti: è stato nascosto invece che cancellato.";
            } else {
                q('DELETE FROM tavoli WHERE id = ?', [$id]);
                $_SESSION['msg'] = "Tavolo \"{$t['nome']}\" cancellato.";
            }
            vai("tavoli.php?sala={$t['sala_id']}");

        case 'salva_sala':
            $id = (int)($_POST['id'] ?? 0);
            $form_sala = [
                'id'     => $id,
                'nome'   => trim($_POST['nome'] ?? ''),
                'ordine' => (int)($_POST['ordine'] ?? 0),
            ];
            if ($form_sala['nome'] === '' || mb_strlen($form_sala['nome']) > 50) {
                $errore = 'Il nome della sala è obbligatorio (max 50 caratteri)';
                break;
            }
            if ($id) {
                q('UPDATE sale SET nome = ?, ordine = ? WHERE id = ?', [$form_sala['nome'], $form_sala['ordine'], $id]);
            } else {
                q('INSERT INTO sale (nome, ordine) VALUES (?, ?)', [$form_sala['nome'], $form_sala['ordine']]);
                $id = (int)db()->lastInsertId();
            }
            $_SESSION['msg'] = "Sala \"{$form_sala['nome']}\" salvata.";
            vai("tavoli.php?sala=$id");

        case 'cancella_sala':
            $id = (int)($_POST['id'] ?? 0);
            $s  = q('SELECT * FROM sale WHERE id = ?', [$id])->fetch();
            if (!$s) vai('tavoli.php');
            if (q('SELECT COUNT(*) FROM tavoli WHERE sala_id = ?', [$id])->fetchColumn()) {
                $_SESSION['msg'] = "La sala \"{$s['nome']}\" contiene dei tavoli (anche nascosti): spostali o cancellali prima.";
                vai("tavoli.php?sala=$id");
            }
            q('DELETE FROM sale WHERE id = ?', [$id]);
            $_SESSION['msg'] = "Sala \"{$s['nome']}\" cancellata.";
            vai('tavoli.php');
    }
}

// ------------------------------------------------------------
//  Dati per la pagina
// ------------------------------------------------------------
$nuova_sala = isset($_GET['nuova_sala']) || ($form_sala && !$form_sala['id']);

$sala_sel = (int)($_GET['sala'] ?? ($form_tavolo['sala_id'] ?? ($form_sala['id'] ?? 0)));
if (!in_array($sala_sel, $sale_id)) $sala_sel = (int)($sale[0]['id'] ?? 0);
$sala = null;
foreach ($sale as $s) if ($s['id'] == $sala_sel) $sala = $s;

$tavoli = $sala && !$nuova_sala ? q('SELECT t.*, c.id AS conto_id FROM tavoli t
                     LEFT JOIN conti c ON c.tavolo_id = t.id AND c.stato = "aperto"
                     WHERE t.sala_id = ? ORDER BY t.attivo DESC, t.nome', [$sala_sel])->fetchAll() : [];

// Tavolo nella scheda: dopo un errore i dati inviati, altrimenti quello selezionato
$tavolo = $form_tavolo;
if (!$tavolo && !$nuova_sala && isset($_GET['tavolo'])) {
    $tavolo = q('SELECT * FROM tavoli WHERE id = ?', [(int)$_GET['tavolo']])->fetch() ?: null;
}

// Sala nella scheda (quando non c'è un tavolo selezionato)
$scheda_sala = $form_sala ?? ($nuova_sala ? ['id' => 0, 'nome' => '', 'ordine' => count($sale) + 1] : $sala);

$msg = $_SESSION['msg'] ?? '';
unset($_SESSION['msg']);
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title><?= h(impostazione('nome_locale')) ?> - Sale e tavoli</title>
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
    <span class="data">Gestione Sale e Tavoli</span>
    <span><?= date('H:i') ?></span>
  </div>

  <!-- Tab delle sale + nuova sala -->
  <div class="sale-tabs">
    <?php foreach ($sale as $s): ?>
      <a class="sala-tab <?= $s['id'] == $sala_sel && !$nuova_sala ? 'attiva' : '' ?>"
         href="tavoli.php?sala=<?= $s['id'] ?>"><?= h($s['nome']) ?></a>
    <?php endforeach; ?>
    <a class="sala-tab nuova <?= $nuova_sala ? 'attiva' : '' ?>" href="tavoli.php?nuova_sala=1">＋ Nuova sala</a>
  </div>

  <div class="main">
    <div class="navcol">
      <a class="btn menu" href="sala.php?sala=<?= $sala_sel ?>"><span class="ico">🏠</span>Menu</a>
      <a class="btn" href="impostazioni.php"><span class="ico">⚙</span>Impostazioni</a>
      <?php if ($sala && !$nuova_sala): ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
          <input type="hidden" name="sala_id" value="<?= $sala_sel ?>">
          <button type="submit" name="azione" value="nuovo_tavolo" class="btn verde" style="width:100%">
            <span class="ico">＋</span>Tavolo</button>
        </form>
        <a class="btn" href="tavoli.php?sala=<?= $sala_sel ?>"><span class="ico">✎</span>Modifica sala</a>
      <?php endif; ?>
    </div>

    <!-- Piantina modificabile -->
    <div class="mappa-wrap">
      <div class="mappa modifica" id="mappa">
        <?php foreach ($tavoli as $t): ?>
          <div class="tavolo <?= h($t['forma']) ?> <?= $t['attivo'] ? '' : 'nascosto' ?>
                      <?= $t['conto_id'] ? 'occupato' : '' ?> <?= $tavolo && $tavolo['id'] == $t['id'] ? 'sel' : '' ?>"
               style="left:<?= $t['pos_x'] ?>px; top:<?= $t['pos_y'] ?>px; width:<?= $t['larghezza'] ?>px; height:<?= $t['altezza'] ?>px;"
               data-id="<?= $t['id'] ?>">
            <?= h($t['nome']) ?>
            <span class="badge posti"><?= (int)$t['posti'] ?>p</span>
          </div>
        <?php endforeach; ?>
        <?php if ($sala && !$tavoli && !$nuova_sala): ?>
          <div class="mappa-vuota">Nessun tavolo in questa sala. Premi "＋ Tavolo" per aggiungerne uno.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Scheda: tavolo selezionato oppure sala -->
    <?php if ($tavolo): ?>
      <form class="scheda" method="post">
        <h2>Tavolo <?= h($tavolo['nome']) ?></h2>
        <?php if ($msg): ?><div class="msg ok"><?= h($msg) ?></div><?php endif; ?>
        <?php if ($errore): ?><div class="msg errore"><?= h($errore) ?></div><?php endif; ?>

        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <input type="hidden" name="id" value="<?= (int)$tavolo['id'] ?>">

        <div class="due">
          <label>Nome / numero
            <input type="text" name="nome" maxlength="20" required value="<?= h($tavolo['nome']) ?>">
          </label>
          <label>Posti
            <input type="number" name="posti" min="1" max="99" required value="<?= (int)$tavolo['posti'] ?>">
          </label>
        </div>

        <label>Sala
          <select name="sala_id">
            <?php foreach ($sale as $s): ?>
              <option value="<?= $s['id'] ?>" <?= $s['id'] == $tavolo['sala_id'] ? 'selected' : '' ?>><?= h($s['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>Forma</label>
        <div class="scelta">
          <label><input type="radio" name="forma" value="rettangolo" <?= $tavolo['forma'] !== 'tondo' ? 'checked' : '' ?>><span>▭ Rettangolo</span></label>
          <label><input type="radio" name="forma" value="tondo" <?= $tavolo['forma'] === 'tondo' ? 'checked' : '' ?>><span>◯ Tondo</span></label>
        </div>

        <div class="due">
          <label>Larghezza (px)
            <input type="number" name="larghezza" min="40" max="600" step="10" required value="<?= (int)$tavolo['larghezza'] ?>">
          </label>
          <label>Altezza (px)
            <input type="number" name="altezza" min="40" max="600" step="10" required value="<?= (int)$tavolo['altezza'] ?>">
          </label>
        </div>

        <label class="check">
          <input type="checkbox" name="attivo" <?= $tavolo['attivo'] ? 'checked' : '' ?>>
          Visibile in sala
        </label>

        <div class="hint">Per spostare il tavolo trascinalo sulla piantina.</div>

        <!-- Salva per primo: è il pulsante usato quando si preme Invio -->
        <div class="scheda-piede tre">
          <button type="submit" name="azione" value="salva_tavolo" class="btn verde"><span class="ico">✔</span>Salva</button>
          <a class="btn scuro" href="tavoli.php?sala=<?= $sala_sel ?>"><span class="ico">✕</span>Chiudi</a>
          <button type="submit" name="azione" value="cancella_tavolo" class="btn rosso" formnovalidate
                  onclick="return confirm('Cancellare questo tavolo?')"><span class="ico">🗑</span>Cancella</button>
        </div>
      </form>

    <?php elseif ($scheda_sala): ?>
      <form class="scheda" method="post">
        <h2><?= $scheda_sala['id'] ? 'Sala ' . h($scheda_sala['nome']) : 'Nuova sala' ?></h2>
        <?php if ($msg): ?><div class="msg ok"><?= h($msg) ?></div><?php endif; ?>
        <?php if ($errore): ?><div class="msg errore"><?= h($errore) ?></div><?php endif; ?>

        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <input type="hidden" name="id" value="<?= (int)$scheda_sala['id'] ?>">

        <label>Nome sala
          <input type="text" name="nome" maxlength="50" required value="<?= h($scheda_sala['nome']) ?>">
        </label>
        <label>Ordine (posizione della scheda)
          <input type="number" name="ordine" value="<?= (int)$scheda_sala['ordine'] ?>">
        </label>

        <?php if ($scheda_sala['id']): ?>
          <div class="hint">Tocca un tavolo per modificarlo, trascinalo per spostarlo.</div>
        <?php endif; ?>

        <div class="scheda-piede">
          <button type="submit" name="azione" value="salva_sala" class="btn verde">
            <span class="ico">✔</span><?= $scheda_sala['id'] ? 'Salva sala' : 'Crea sala' ?></button>
          <?php if ($scheda_sala['id']): ?>
            <button type="submit" name="azione" value="cancella_sala" class="btn rosso" formnovalidate
                    onclick="return confirm('Cancellare questa sala?')"><span class="ico">🗑</span>Cancella sala</button>
          <?php endif; ?>
        </div>
      </form>
    <?php endif; ?>
  </div>

  <div class="avviso" id="avviso"></div>

<script>
  // ---------- Trascinamento tavoli (mouse e touch) ----------
  const CSRF = <?= json_encode($_SESSION['csrf']) ?>;
  const SALA = <?= $sala_sel ?>;
  const GRIGLIA = 10;   // i tavoli si agganciano a una griglia di 10px

  function avviso(testo) {
    const el = document.getElementById('avviso');
    el.textContent = testo;
    el.classList.add('visibile');
    setTimeout(() => el.classList.remove('visibile'), 2500);
  }

  document.querySelectorAll('#mappa .tavolo').forEach(el => {
    let inizio = null;

    el.addEventListener('pointerdown', e => {
      el.setPointerCapture(e.pointerId);
      inizio = { x: e.clientX, y: e.clientY, left: el.offsetLeft, top: el.offsetTop, mosso: false };
    });

    el.addEventListener('pointermove', e => {
      if (!inizio) return;
      const dx = e.clientX - inizio.x, dy = e.clientY - inizio.y;
      if (!inizio.mosso && Math.abs(dx) + Math.abs(dy) < 6) return;   // piccolo tremolio = tocco
      inizio.mosso = true;
      el.classList.add('trascina');
      el.style.left = Math.max(0, Math.round((inizio.left + dx) / GRIGLIA) * GRIGLIA) + 'px';
      el.style.top  = Math.max(0, Math.round((inizio.top + dy) / GRIGLIA) * GRIGLIA) + 'px';
    });

    el.addEventListener('pointerup', async () => {
      if (!inizio) return;
      const mosso = inizio.mosso;
      inizio = null;
      el.classList.remove('trascina');

      // Tocco semplice: apro la scheda del tavolo
      if (!mosso) { location.href = 'tavoli.php?sala=' + SALA + '&tavolo=' + el.dataset.id; return; }

      const dati = new FormData();
      dati.append('azione', 'sposta');
      dati.append('csrf', CSRF);
      dati.append('id', el.dataset.id);
      dati.append('x', parseInt(el.style.left, 10));
      dati.append('y', parseInt(el.style.top, 10));
      try {
        const res = await fetch('tavoli.php', { method: 'POST', body: dati });
        const json = await res.json();
        if (json.errore) avviso(json.errore);
      } catch (err) {
        avviso('Posizione non salvata: connessione non riuscita');
      }
    });
  });
</script>
</body>
</html>
