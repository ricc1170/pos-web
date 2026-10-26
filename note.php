<?php
// ============================================================
//  note.php  -  Note rapide delle righe (solo admin)
//  Pulsanti della finestra Note (INTEGRALE, BIANCA, CELIACO...).
//  La stampante dedicata a una nota (es. CELIACO) si sceglie nel programma desktop della cassa.
//  Centro: elenco · Destra: scheda
// ============================================================
require_once __DIR__ . '/includes/auth.php';
$op = richiedi_admin();

$errore = '';
$dati   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    controlla_csrf();
    $id = (int)($_POST['id'] ?? 0);

    if (($_POST['azione'] ?? '') === 'cancella') {
        $n = q('SELECT * FROM note_rapide WHERE id = ?', [$id])->fetch();
        if ($n) {
            // Le note già scritte sulle righe restano (sono testo)
            q('DELETE FROM note_rapide WHERE id = ?', [$id]);
            $_SESSION['msg'] = "Nota \"{$n['testo']}\" cancellata.";
        }
        header('Location: note.php');
        exit;
    }

    $dati = [
        'id'           => $id,
        'testo'        => mb_strtoupper(trim($_POST['testo'] ?? '')),
        'ordine'       => (int)($_POST['ordine'] ?? 0),
        'attiva'       => isset($_POST['attiva']) ? 1 : 0,
    ];

    if ($dati['testo'] === '' || mb_strlen($dati['testo']) > 30) {
        $errore = 'Il testo è obbligatorio (max 30 caratteri)';
    } elseif (strpos($dati['testo'], ',') !== false) {
        $errore = 'Il testo non può contenere virgole (separano le note sulla riga)';
    } elseif (q('SELECT 1 FROM note_rapide WHERE testo = ? AND id <> ?', [$dati['testo'], $id])->fetch()) {
        $errore = 'Esiste già una nota con questo testo';
    }

    if (!$errore) {
        $valori = [$dati['testo'], $dati['ordine'], $dati['attiva']];
        if ($id) {
            q('UPDATE note_rapide SET testo = ?, ordine = ?, attiva = ? WHERE id = ?', [...$valori, $id]);
        } else {
            q('INSERT INTO note_rapide (testo, ordine, attiva) VALUES (?, ?, ?)', $valori);
            $id = (int)db()->lastInsertId();
        }
        $_SESSION['msg'] = "Nota \"{$dati['testo']}\" salvata.";
        header("Location: note.php?id=$id");
        exit;
    }
}

// ------------------------------------------------------------
//  Dati per la pagina
// ------------------------------------------------------------
$note = q('SELECT * FROM note_rapide ORDER BY attiva DESC, ordine, id')->fetchAll();

if (!$dati) {
    $id_sel = (int)($_GET['id'] ?? 0);
    $dati = ($id_sel ? q('SELECT * FROM note_rapide WHERE id = ?', [$id_sel])->fetch() : null)
         ?: ['id' => 0, 'testo' => '', 'ordine' => count($note) + 1, 'attiva' => 1];
}

$msg = $_SESSION['msg'] ?? '';
unset($_SESSION['msg']);
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title><?= h(impostazione('nome_locale')) ?> - Note rapide</title>
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
    <span class="data">Impostazioni · Note rapide</span>
    <span><?= date('H:i') ?></span>
  </div>

  <div class="main">
    <div class="navcol">
      <a class="btn menu" href="sala.php"><span class="ico">🏠</span>Menu</a>
      <a class="btn" href="impostazioni.php"><span class="ico">⚙</span>Impostazioni</a>
      <a class="btn verde" href="note.php"><span class="ico">＋</span>Nuova</a>
    </div>

    <!-- Elenco note -->
    <div class="ordini varianti-gestione">
      <?php foreach ($note as $n): ?>
        <a class="ordine <?= $n['attiva'] ? '' : 'sospesa' ?> <?= $n['id'] == $dati['id'] ? 'sel' : '' ?>" href="note.php?id=<?= $n['id'] ?>">
          <span class="cliente"><?= h($n['testo']) ?></span>
          <?php if (!$n['attiva']): ?><span class="stato vuota">Disattivata</span><?php endif; ?>
        </a>
      <?php endforeach; ?>
      <?php if (!$note): ?>
        <div class="vuoto">Nessuna nota rapida. Usa il modulo a destra per aggiungerne una.</div>
      <?php endif; ?>
    </div>

    <!-- Scheda nota -->
    <form class="scheda" method="post">
      <h2><?= $dati['id'] ? 'Modifica nota' : 'Nuova nota' ?></h2>
      <?php if ($msg): ?><div class="msg ok"><?= h($msg) ?></div><?php endif; ?>
      <?php if ($errore): ?><div class="msg errore"><?= h($errore) ?></div><?php endif; ?>

      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
      <input type="hidden" name="id" value="<?= (int)$dati['id'] ?>">

      <div class="due">
        <label>Testo (es. INTEGRALE)
          <input type="text" name="testo" maxlength="30" required value="<?= h($dati['testo']) ?>">
        </label>
        <label>Ordine
          <input type="number" name="ordine" value="<?= (int)$dati['ordine'] ?>">
        </label>
      </div>

      <div class="hint">La stampante dedicata a una nota (es. CELIACO su una stampante a parte) si sceglie
        nel programma della cassa.</div>

      <label class="check">
        <input type="checkbox" name="attiva" <?= $dati['attiva'] ? 'checked' : '' ?>> Attiva
      </label>

      <div class="scheda-piede">
        <button type="submit" name="azione" value="salva" class="btn verde"><span class="ico">✔</span>Salva</button>
        <?php if ($dati['id']): ?>
          <button type="submit" name="azione" value="cancella" class="btn rosso" formnovalidate
                  onclick="return confirm('Cancellare questa nota rapida?')"><span class="ico">🗑</span>Cancella</button>
        <?php endif; ?>
      </div>
    </form>
  </div>

</body>
</html>
