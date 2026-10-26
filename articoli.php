<?php
// ============================================================
//  articoli.php  -  Gestione prodotti: inserisci / modifica / cancella
//  Solo admin. Sinistra: elenco filtrato per categoria · Destra: scheda
// ============================================================
require_once __DIR__ . '/includes/auth.php';
richiedi_login();

$op = operatore_loggato();
if ($op['ruolo'] !== 'admin') {
    header('Location: sala.php');
    exit;
}

// Token anti-CSRF per i form
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$categorie = q('SELECT * FROM categorie ORDER BY ordine, id')->fetchAll();
$cat_ids   = array_column($categorie, 'id');

$errore = '';
$dati   = null;   // valori del form da ripresentare dopo un errore

// ------------------------------------------------------------
//  Salvataggio / cancellazione (POST → redirect)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        die('Richiesta non valida, ricarica la pagina');
    }

    $id = (int)($_POST['id'] ?? 0);

    if (($_POST['azione'] ?? '') === 'cancella') {
        $art = q('SELECT * FROM articoli WHERE id = ?', [$id])->fetch();
        if ($art) {
            $usato = q('SELECT COUNT(*) FROM righe WHERE articolo_id = ?', [$id])->fetchColumn();
            if ($usato) {
                // Già presente in qualche conto: non si può cancellare, lo nascondo
                q('UPDATE articoli SET visibile = 0 WHERE id = ?', [$id]);
                $_SESSION['msg'] = "\"{$art['nome']}\" è già stato venduto: è stato nascosto invece che cancellato.";
            } else {
                q('DELETE FROM articoli WHERE id = ?', [$id]);
                $_SESSION['msg'] = "\"{$art['nome']}\" cancellato.";
            }
        }
        header('Location: articoli.php?cat=' . (int)($art['categoria_id'] ?? 0));
        exit;
    }

    // Inserimento o modifica
    $dati = [
        'id'           => $id,
        'nome'         => trim($_POST['nome'] ?? ''),
        'categoria_id' => (int)($_POST['categoria_id'] ?? 0),
        'prezzo'       => str_replace(',', '.', trim($_POST['prezzo'] ?? '')),
        'aliquota_iva' => str_replace(',', '.', trim($_POST['aliquota_iva'] ?? '10')),
        'colore'       => $_POST['colore'] ?? '#ffffff',
        'ordine'       => (int)($_POST['ordine'] ?? 0),
        'visibile'     => isset($_POST['visibile']) ? 1 : 0,
        'a_peso'       => ($_POST['tipo_prezzo'] ?? '') === 'peso' ? 1 : 0,   // prezzo fisso oppure al kg
    ];

    if ($dati['nome'] === '' || mb_strlen($dati['nome']) > 80) {
        $errore = 'Il nome è obbligatorio (max 80 caratteri)';
    } elseif (!in_array($dati['categoria_id'], $cat_ids)) {
        $errore = 'Scegli una categoria';
    } elseif (!is_numeric($dati['prezzo']) || $dati['prezzo'] < 0 || $dati['prezzo'] > 999999) {
        $errore = 'Prezzo non valido';
    } elseif (!is_numeric($dati['aliquota_iva']) || $dati['aliquota_iva'] < 0 || $dati['aliquota_iva'] > 100) {
        $errore = 'Aliquota IVA non valida';
    } elseif (!preg_match('/^#[0-9a-fA-F]{6}$/', $dati['colore'])) {
        $errore = 'Colore non valido';
    }

    if (!$errore) {
        $valori = [$dati['categoria_id'], $dati['nome'], $dati['prezzo'], $dati['aliquota_iva'],
                   $dati['colore'], $dati['ordine'], $dati['visibile'], $dati['a_peso']];
        if ($id) {
            q('UPDATE articoli SET categoria_id=?, nome=?, prezzo=?, aliquota_iva=?, colore=?, ordine=?, visibile=?, a_peso=?
               WHERE id=?', [...$valori, $id]);
            $_SESSION['msg'] = "\"{$dati['nome']}\" salvato.";
        } else {
            q('INSERT INTO articoli (categoria_id, nome, prezzo, aliquota_iva, colore, ordine, visibile, a_peso)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?)', $valori);
            $id = (int)db()->lastInsertId();
            $_SESSION['msg'] = "\"{$dati['nome']}\" inserito.";
        }
        header("Location: articoli.php?cat={$dati['categoria_id']}&id=$id");
        exit;
    }
}

// ------------------------------------------------------------
//  Dati per la pagina
// ------------------------------------------------------------
$cat_sel = (int)($_GET['cat'] ?? ($dati['categoria_id'] ?? 0));
if (!in_array($cat_sel, $cat_ids)) $cat_sel = (int)($categorie[0]['id'] ?? 0);

$articoli = q('SELECT * FROM articoli WHERE categoria_id = ? ORDER BY ordine, nome', [$cat_sel])->fetchAll();

// Articolo da mostrare nella scheda: dopo un errore i dati inviati, altrimenti quello scelto o uno nuovo
if (!$dati) {
    $id_sel = (int)($_GET['id'] ?? 0);
    $dati = $id_sel ? q('SELECT * FROM articoli WHERE id = ?', [$id_sel])->fetch() : null;
    if (!$dati) {
        $dati = ['id' => 0, 'nome' => '', 'categoria_id' => $cat_sel, 'prezzo' => '',
                 'aliquota_iva' => '10.00', 'colore' => '#ffffff', 'ordine' => 0, 'visibile' => 1,
                 'a_peso' => 0];
    }
}

$msg = $_SESSION['msg'] ?? '';
unset($_SESSION['msg']);

$valuta = impostazione('valuta', '€');
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title><?= h(impostazione('nome_locale')) ?> - Articoli</title>
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
    <span class="data">Gestione Articoli</span>
    <span><?= date('H:i') ?></span>
  </div>

  <!-- Tab categorie -->
  <div class="sale-tabs cat-tabs">
    <?php foreach ($categorie as $c): ?>
      <a class="sala-tab <?= $c['id'] == $cat_sel ? 'attiva' : '' ?>"
         href="articoli.php?cat=<?= $c['id'] ?>"><?= h($c['nome']) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="main">
    <div class="navcol">
      <a class="btn menu" href="sala.php"><span class="ico">🏠</span>Menu</a>
      <a class="btn" href="impostazioni.php"><span class="ico">⚙</span>Impostazioni</a>
      <a class="btn verde" href="articoli.php?cat=<?= $cat_sel ?>"><span class="ico">＋</span>Nuovo</a>
    </div>

    <!-- Elenco articoli della categoria -->
    <div class="prodotti">
      <?php foreach ($articoli as $a): ?>
        <a class="prod <?= $a['id'] == $dati['id'] ? 'sel' : '' ?> <?= $a['visibile'] ? '' : 'nascosto' ?>"
           style="--colore: <?= h($a['colore']) ?>"
           href="articoli.php?cat=<?= $cat_sel ?>&id=<?= $a['id'] ?>">
          <span class="nome"><?= h($a['nome']) ?></span>
          <span class="prezzo"><?= $valuta . ' ' . number_format($a['prezzo'], 2, ',', '.') . ($a['a_peso'] ? '/kg ⚖' : '') ?></span>
          <?php if (!$a['visibile']): ?><span class="prezzo">(nascosto)</span><?php endif; ?>
        </a>
      <?php endforeach; ?>
      <?php if (!$articoli): ?>
        <div class="vuoto">Nessun articolo in questa categoria. Usa il modulo a destra per inserirne uno.</div>
      <?php endif; ?>
    </div>

    <!-- Scheda articolo -->
    <form class="scheda" method="post">
      <h2><?= $dati['id'] ? 'Modifica articolo' : 'Nuovo articolo' ?></h2>

      <?php if ($msg): ?><div class="msg ok"><?= h($msg) ?></div><?php endif; ?>
      <?php if ($errore): ?><div class="msg errore"><?= h($errore) ?></div><?php endif; ?>

      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
      <input type="hidden" name="id" value="<?= (int)$dati['id'] ?>">

      <label>Nome
        <input type="text" name="nome" maxlength="80" required value="<?= h($dati['nome']) ?>" autofocus>
      </label>

      <label>Categoria
        <select name="categoria_id">
          <?php foreach ($categorie as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $c['id'] == $dati['categoria_id'] ? 'selected' : '' ?>><?= h($c['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <!-- Tipo di prezzo: fisso (al pezzo) oppure a peso (al kg, quantità letta dalla bilancia della cassa) -->
      <label>Tipo di prezzo</label>
      <div class="scelta">
        <label><input type="radio" name="tipo_prezzo" value="fisso" <?= empty($dati['a_peso']) ? 'checked' : '' ?>>
          <span>Prezzo fisso</span></label>
        <label><input type="radio" name="tipo_prezzo" value="peso" <?= !empty($dati['a_peso']) ? 'checked' : '' ?>>
          <span>⚖ A peso</span></label>
      </div>

      <div class="due">
        <label><span id="etichettaPrezzo">Prezzo (<?= h($valuta) ?>)</span>
          <input type="text" name="prezzo" inputmode="decimal" required
                 value="<?= $dati['prezzo'] === '' ? '' : h(str_replace('.', ',', $dati['prezzo'])) ?>">
        </label>
        <label>IVA %
          <input type="text" name="aliquota_iva" inputmode="decimal" required
                 value="<?= h(str_replace('.', ',', $dati['aliquota_iva'])) ?>">
        </label>
      </div>

      <div class="due">
        <label>Colore pulsante
          <input type="color" name="colore" value="<?= h($dati['colore']) ?>">
        </label>
        <label>Ordine
          <input type="number" name="ordine" value="<?= (int)$dati['ordine'] ?>">
        </label>
      </div>

      <label class="check">
        <input type="checkbox" name="visibile" <?= $dati['visibile'] ? 'checked' : '' ?>>
        Visibile nella comanda
      </label>

      <!-- Salva per primo: è il pulsante usato quando si preme Invio -->
      <div class="scheda-piede">
        <button type="submit" name="azione" value="salva" class="btn verde"><span class="ico">✔</span>Salva</button>
        <?php if ($dati['id']): ?>
          <button type="submit" name="azione" value="cancella" class="btn rosso" formnovalidate
                  onclick="return confirm('Cancellare questo articolo?')"><span class="ico">🗑</span>Cancella</button>
        <?php endif; ?>
      </div>
    </form>
  </div>

<script>
  // Prezzo fisso / a peso: l'etichetta dice se il prezzo è al pezzo o al kg
  const VALUTA = <?= json_encode($valuta) ?>;
  function aggiornaPrezzo() {
    const peso = document.querySelector('[name=tipo_prezzo]:checked').value === 'peso';
    document.getElementById('etichettaPrezzo').textContent = peso ? 'Prezzo al kg (' + VALUTA + '/kg)' : 'Prezzo (' + VALUTA + ')';
  }
  document.querySelectorAll('[name=tipo_prezzo]').forEach(r => r.addEventListener('change', aggiornaPrezzo));
  aggiornaPrezzo();
</script>
</body>
</html>
