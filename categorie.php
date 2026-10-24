<?php
// ============================================================
//  categorie.php  -  Gestione categorie di prodotti (solo admin)
//  Centro: pulsanti categoria come in comanda · Destra: scheda
// ============================================================
require_once __DIR__ . '/includes/auth.php';
$op = richiedi_admin();

$destinazioni = ['cucina' => '🍕 Cucina', 'bar' => '🍹 Bar', 'nessuna' => '— Nessuna'];

$errore = '';
$dati   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    controlla_csrf();
    $id = (int)($_POST['id'] ?? 0);

    if (($_POST['azione'] ?? '') === 'cancella') {
        $c = q('SELECT * FROM categorie WHERE id = ?', [$id])->fetch();
        if ($c) {
            $n = q('SELECT COUNT(*) FROM articoli WHERE categoria_id = ?', [$id])->fetchColumn();
            if ($n) {
                $_SESSION['msg'] = "\"{$c['nome']}\" contiene $n prodotti (anche nascosti): spostali in un'altra categoria o cancellali prima.";
                header("Location: categorie.php?id=$id");
                exit;
            }
            q('DELETE FROM categorie WHERE id = ?', [$id]);
            $_SESSION['msg'] = "Categoria \"{$c['nome']}\" cancellata.";
        }
        header('Location: categorie.php');
        exit;
    }

    $dati = [
        'id'           => $id,
        'nome'         => trim($_POST['nome'] ?? ''),
        'colore'       => $_POST['colore'] ?? '#2b6cb0',
        'ordine'       => (int)($_POST['ordine'] ?? 0),
        'destinazione' => array_key_exists($_POST['destinazione'] ?? '', $destinazioni) ? $_POST['destinazione'] : 'cucina',
    ];

    if ($dati['nome'] === '' || mb_strlen($dati['nome']) > 50) {
        $errore = 'Il nome è obbligatorio (max 50 caratteri)';
    } elseif (!preg_match('/^#[0-9a-fA-F]{6}$/', $dati['colore'])) {
        $errore = 'Colore non valido';
    }

    if (!$errore) {
        $valori = [$dati['nome'], $dati['colore'], $dati['ordine'], $dati['destinazione']];
        if ($id) {
            q('UPDATE categorie SET nome = ?, colore = ?, ordine = ?, destinazione = ? WHERE id = ?', [...$valori, $id]);
        } else {
            q('INSERT INTO categorie (nome, colore, ordine, destinazione) VALUES (?, ?, ?, ?)', $valori);
            $id = (int)db()->lastInsertId();
        }
        $_SESSION['msg'] = "Categoria \"{$dati['nome']}\" salvata.";
        header("Location: categorie.php?id=$id");
        exit;
    }
}

// ------------------------------------------------------------
//  Dati per la pagina
// ------------------------------------------------------------
$categorie = q('SELECT c.*, (SELECT COUNT(*) FROM articoli a WHERE a.categoria_id = c.id) AS prodotti
                FROM categorie c ORDER BY c.ordine, c.id')->fetchAll();

if (!$dati) {
    $id_sel = (int)($_GET['id'] ?? 0);
    $dati = $id_sel ? q('SELECT * FROM categorie WHERE id = ?', [$id_sel])->fetch() : null;
    if (!$dati) {
        $dati = ['id' => 0, 'nome' => '', 'colore' => '#2b6cb0',
                 'ordine' => count($categorie) + 1, 'destinazione' => 'cucina'];
    }
}

$msg = $_SESSION['msg'] ?? '';
unset($_SESSION['msg']);
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title><?= h(impostazione('nome_locale')) ?> - Categorie</title>
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
    <span class="data">Impostazioni · Categorie</span>
    <span><?= date('H:i') ?></span>
  </div>

  <div class="main">
    <div class="navcol">
      <a class="btn menu" href="sala.php"><span class="ico">🏠</span>Menu</a>
      <a class="btn" href="impostazioni.php"><span class="ico">⚙</span>Impostazioni</a>
      <a class="btn verde" href="categorie.php"><span class="ico">＋</span>Nuova</a>
    </div>

    <!-- Categorie, come appaiono in comanda -->
    <div class="prodotti">
      <?php foreach ($categorie as $c): ?>
        <a class="cat cat-gestione <?= $c['id'] == $dati['id'] ? 'attiva' : '' ?>" style="--colore: <?= h($c['colore']) ?>"
           href="categorie.php?id=<?= $c['id'] ?>">
          <span><?= h($c['nome']) ?></span>
          <small><?= (int)$c['prodotti'] ?> prodotti · <?= h($destinazioni[$c['destinazione']]) ?></small>
        </a>
      <?php endforeach; ?>
      <?php if (!$categorie): ?>
        <div class="vuoto">Nessuna categoria. Usa il modulo a destra per crearne una.</div>
      <?php endif; ?>
    </div>

    <!-- Scheda categoria -->
    <form class="scheda" method="post">
      <h2><?= $dati['id'] ? 'Modifica categoria' : 'Nuova categoria' ?></h2>
      <?php if ($msg): ?><div class="msg ok"><?= h($msg) ?></div><?php endif; ?>
      <?php if ($errore): ?><div class="msg errore"><?= h($errore) ?></div><?php endif; ?>

      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
      <input type="hidden" name="id" value="<?= (int)$dati['id'] ?>">

      <label>Nome
        <input type="text" name="nome" maxlength="50" required value="<?= h($dati['nome']) ?>">
      </label>

      <div class="due">
        <label>Colore pulsante
          <input type="color" name="colore" value="<?= h($dati['colore']) ?>">
        </label>
        <label>Ordine
          <input type="number" name="ordine" value="<?= (int)$dati['ordine'] ?>">
        </label>
      </div>

      <label>Destinazione della comanda</label>
      <div class="scelta tre">
        <?php foreach ($destinazioni as $val => $etichetta): ?>
          <label><input type="radio" name="destinazione" value="<?= $val ?>" <?= $dati['destinazione'] === $val ? 'checked' : '' ?>><span><?= $etichetta ?></span></label>
        <?php endforeach; ?>
      </div>
      <div class="hint">Dove andrà stampata la comanda di questi prodotti (stampa: prossime tappe).</div>

      <?php if ($dati['id']): ?>
        <a class="link-scheda" href="articoli.php?cat=<?= (int)$dati['id'] ?>">🍕 Vedi i prodotti di questa categoria ›</a>
      <?php endif; ?>

      <div class="scheda-piede">
        <button type="submit" name="azione" value="salva" class="btn verde"><span class="ico">✔</span>Salva</button>
        <?php if ($dati['id']): ?>
          <button type="submit" name="azione" value="cancella" class="btn rosso" formnovalidate
                  onclick="return confirm('Cancellare questa categoria?')"><span class="ico">🗑</span>Cancella</button>
        <?php endif; ?>
      </div>
    </form>
  </div>

</body>
</html>
