<?php
// ============================================================
//  operatori.php  -  Gestione operatori (solo admin)
//  Centro: elenco · Destra: scheda (nome, PIN, ruolo, attivo)
//  Il PIN non si mostra mai: in modifica, campo vuoto = PIN invariato.
// ============================================================
require_once __DIR__ . '/includes/auth.php';
$op = richiedi_admin();

$ruoli = ['admin' => 'Admin', 'cassa' => 'Cassa', 'cameriere' => 'Cameriere'];

// Admin attivi diversi da $escludi_id (ne deve restare sempre almeno uno)
function altri_admin(int $escludi_id): int {
    return (int)q('SELECT COUNT(*) FROM operatori WHERE ruolo = "admin" AND attivo = 1 AND id <> ?', [$escludi_id])->fetchColumn();
}

$errore = '';
$dati   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    controlla_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $io = $id === (int)$op['id'];

    if (($_POST['azione'] ?? '') === 'cancella') {
        $o = q('SELECT * FROM operatori WHERE id = ?', [$id])->fetch();
        if ($o && $io) {
            $_SESSION['msg'] = 'Non puoi cancellare te stesso.';
        } elseif ($o && $o['ruolo'] === 'admin' && !altri_admin($id)) {
            $_SESSION['msg'] = 'Deve restare almeno un admin attivo.';
        } elseif ($o) {
            $usato = q('SELECT (SELECT COUNT(*) FROM conti WHERE operatore_id = ?) + (SELECT COUNT(*) FROM righe WHERE operatore_id = ?)',
                       [$id, $id])->fetchColumn();
            if ($usato) {
                // Ha già lavorato: resta nello storico, ma non può più entrare
                q('UPDATE operatori SET attivo = 0 WHERE id = ?', [$id]);
                $_SESSION['msg'] = "\"{$o['nome']}\" ha già delle comande: è stato disattivato invece che cancellato.";
            } else {
                q('DELETE FROM operatori WHERE id = ?', [$id]);
                $_SESSION['msg'] = "\"{$o['nome']}\" cancellato.";
            }
        }
        header('Location: operatori.php');
        exit;
    }

    $dati = [
        'id'     => $id,
        'nome'   => trim($_POST['nome'] ?? ''),
        'ruolo'  => array_key_exists($_POST['ruolo'] ?? '', $ruoli) ? $_POST['ruolo'] : 'cameriere',
        'attivo' => isset($_POST['attivo']) ? 1 : 0,
    ];
    $pin = trim($_POST['pin'] ?? '');
    $vecchio = $id ? q('SELECT * FROM operatori WHERE id = ?', [$id])->fetch() : null;

    if ($id && !$vecchio) {
        header('Location: operatori.php');
        exit;
    } elseif ($dati['nome'] === '' || mb_strlen($dati['nome']) > 50) {
        $errore = 'Il nome è obbligatorio (max 50 caratteri)';
    } elseif (!$id && $pin === '') {
        $errore = 'Scegli un PIN per il nuovo operatore';
    } elseif ($pin !== '' && !pin_valido($pin)) {
        $errore = 'Il PIN deve avere da 4 a 6 cifre';
    } elseif ($pin !== '' && pin_debole($pin)) {
        $errore = 'PIN troppo facile (niente cifre tutte uguali o in sequenza)';
    } elseif ($pin !== '' && q('SELECT id FROM operatori WHERE pin = ? AND id <> ?', [$pin, $id])->fetch()) {
        $errore = 'PIN già usato da un altro operatore';
    } elseif ($io && ($dati['ruolo'] !== 'admin' || !$dati['attivo'])) {
        $errore = 'Non puoi togliere a te stesso il ruolo di admin o disattivarti';
    } elseif ($vecchio && $vecchio['ruolo'] === 'admin' && ($dati['ruolo'] !== 'admin' || !$dati['attivo']) && !altri_admin($id)) {
        $errore = 'Deve restare almeno un admin attivo';
    }

    if (!$errore) {
        if ($id) {
            q('UPDATE operatori SET nome = ?, ruolo = ?, attivo = ? WHERE id = ?',
              [$dati['nome'], $dati['ruolo'], $dati['attivo'], $id]);
            if ($pin !== '') q('UPDATE operatori SET pin = ? WHERE id = ?', [$pin, $id]);
            if ($io) $_SESSION['operatore']['nome'] = $dati['nome'];
            $_SESSION['msg'] = "\"{$dati['nome']}\" salvato." . ($pin !== '' ? ' PIN cambiato.' : '');
        } else {
            q('INSERT INTO operatori (nome, pin, ruolo, attivo) VALUES (?, ?, ?, ?)',
              [$dati['nome'], $pin, $dati['ruolo'], $dati['attivo']]);
            $id = (int)db()->lastInsertId();
            $_SESSION['msg'] = "\"{$dati['nome']}\" inserito.";
        }
        header("Location: operatori.php?id=$id");
        exit;
    }
}

// ------------------------------------------------------------
//  Dati per la pagina
// ------------------------------------------------------------
$operatori = q('SELECT id, nome, ruolo, attivo FROM operatori ORDER BY attivo DESC, FIELD(ruolo, "admin", "cassa", "cameriere"), nome')->fetchAll();

if (!$dati) {
    $id_sel = (int)($_GET['id'] ?? 0);
    $dati = $id_sel ? q('SELECT id, nome, ruolo, attivo FROM operatori WHERE id = ?', [$id_sel])->fetch() : null;
    if (!$dati) $dati = ['id' => 0, 'nome' => '', 'ruolo' => 'cameriere', 'attivo' => 1];
}
$io = (int)$dati['id'] === (int)$op['id'];

$msg = $_SESSION['msg'] ?? '';
unset($_SESSION['msg']);
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title><?= h(impostazione('nome_locale')) ?> - Operatori</title>
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
    <span class="data">Impostazioni · Operatori</span>
    <span><?= date('H:i') ?></span>
  </div>

  <div class="main">
    <div class="navcol">
      <a class="btn menu" href="sala.php"><span class="ico">🏠</span>Menu</a>
      <a class="btn" href="impostazioni.php"><span class="ico">⚙</span>Impostazioni</a>
      <a class="btn verde" href="operatori.php"><span class="ico">＋</span>Nuovo</a>
    </div>

    <!-- Elenco operatori -->
    <div class="ordini">
      <?php foreach ($operatori as $o): ?>
        <a class="ordine operatore <?= $o['attivo'] ? '' : 'sospesa' ?> <?= $o['id'] == $dati['id'] ? 'sel' : '' ?>"
           href="operatori.php?id=<?= $o['id'] ?>">
          <span class="ico-op"><?= $o['ruolo'] === 'admin' ? '🔑' : ($o['ruolo'] === 'cassa' ? '💶' : '🍽') ?></span>
          <span class="cliente"><?= h($o['nome']) ?></span>
          <span class="dettagli"><?= $ruoli[$o['ruolo']] ?><?= $o['id'] == $op['id'] ? ' · sei tu' : '' ?></span>
          <?php if (!$o['attivo']): ?><span class="stato vuota">Disattivato</span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- Scheda operatore -->
    <form class="scheda" method="post" autocomplete="off">
      <h2><?= $dati['id'] ? 'Modifica operatore' : 'Nuovo operatore' ?></h2>
      <?php if ($msg): ?><div class="msg ok"><?= h($msg) ?></div><?php endif; ?>
      <?php if ($errore): ?><div class="msg errore"><?= h($errore) ?></div><?php endif; ?>

      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
      <input type="hidden" name="id" value="<?= (int)$dati['id'] ?>">

      <label>Nome
        <input type="text" name="nome" maxlength="50" required value="<?= h($dati['nome']) ?>">
      </label>

      <label><?= $dati['id'] ? 'Nuovo PIN (lascia vuoto per non cambiarlo)' : 'PIN (da 4 a 6 cifre)' ?>
        <input type="password" name="pin" inputmode="numeric" pattern="\d{4,6}" maxlength="6"
               autocomplete="new-password" <?= $dati['id'] ? '' : 'required' ?>>
      </label>

      <label>Ruolo</label>
      <div class="scelta tre">
        <?php foreach ($ruoli as $val => $etichetta): ?>
          <label><input type="radio" name="ruolo" value="<?= $val ?>" <?= $dati['ruolo'] === $val ? 'checked' : '' ?>
                        <?= $io && $val !== 'admin' ? 'disabled' : '' ?>><span><?= $etichetta ?></span></label>
        <?php endforeach; ?>
      </div>
      <div class="hint">
        Admin: tutto, comprese le impostazioni · Cassa: può modificare comande già inviate ·
        Cameriere: solo comande.
      </div>

      <label class="check">
        <input type="checkbox" name="attivo" <?= $dati['attivo'] ? 'checked' : '' ?> <?= $io ? 'disabled' : '' ?>>
        Attivo (può entrare con il PIN)
      </label>
      <?php if ($io): ?>
        <!-- I campi disabilitati non vengono inviati: per te stesso restano admin e attivo -->
        <input type="hidden" name="attivo" value="1">
      <?php endif; ?>

      <div class="scheda-piede">
        <button type="submit" name="azione" value="salva" class="btn verde"><span class="ico">✔</span>Salva</button>
        <?php if ($dati['id'] && !$io): ?>
          <button type="submit" name="azione" value="cancella" class="btn rosso" formnovalidate
                  onclick="return confirm('Cancellare questo operatore?')"><span class="ico">🗑</span>Cancella</button>
        <?php endif; ?>
      </div>
    </form>
  </div>

</body>
</html>
