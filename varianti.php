<?php
// ============================================================
//  varianti.php  -  Gestione varianti / ingredienti (solo admin)
//  Ogni variante ha un prezzo (aggiunta "+"; la tolta "−" è gratis)
//  e vale per alcune categorie o per tutte.
//  In alto: filtro per categoria · Centro: elenco · Destra: scheda
// ============================================================
require_once __DIR__ . '/includes/auth.php';
$op = richiedi_admin();

$categorie = q('SELECT id, nome FROM categorie ORDER BY ordine, id')->fetchAll();
$cat_ids   = array_map('intval', array_column($categorie, 'id'));

$errore = '';
$dati   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    controlla_csrf();
    $id = (int)($_POST['id'] ?? 0);

    if (($_POST['azione'] ?? '') === 'cancella') {
        $v = q('SELECT * FROM varianti WHERE id = ?', [$id])->fetch();
        if ($v) {
            // Le righe dei conti già fatte conservano nome e prezzo della variante
            q('DELETE FROM varianti WHERE id = ?', [$id]);
            $_SESSION['msg'] = "Variante \"{$v['nome']}\" cancellata.";
        }
        header('Location: varianti.php' . (isset($_POST['filtro']) ? '?cat=' . (int)$_POST['filtro'] : ''));
        exit;
    }

    $dati = [
        'id'        => $id,
        'nome'      => trim($_POST['nome'] ?? ''),
        'prezzo'    => str_replace(',', '.', trim($_POST['prezzo'] ?? '0')),
        'ordine'    => (int)($_POST['ordine'] ?? 0),
        'attiva'    => isset($_POST['attiva']) ? 1 : 0,
        'tutte'     => isset($_POST['tutte']) ? 1 : 0,
        'categorie' => array_values(array_intersect(array_map('intval', (array)($_POST['categorie'] ?? [])), $cat_ids)),
    ];

    if ($dati['nome'] === '' || mb_strlen($dati['nome']) > 50) {
        $errore = 'Il nome è obbligatorio (max 50 caratteri)';
    } elseif (!is_numeric($dati['prezzo']) || $dati['prezzo'] < 0 || $dati['prezzo'] > 999) {
        $errore = 'Prezzo non valido (0 = gratis)';
    } elseif (!$dati['tutte'] && !$dati['categorie']) {
        $errore = 'Scegli almeno una categoria (o "Tutte le categorie")';
    }

    if (!$errore) {
        $valori = [$dati['nome'], $dati['prezzo'], $dati['ordine'], $dati['attiva'], $dati['tutte']];
        if ($id) {
            q('UPDATE varianti SET nome = ?, prezzo = ?, ordine = ?, attiva = ?, tutte = ? WHERE id = ?', [...$valori, $id]);
        } else {
            q('INSERT INTO varianti (nome, prezzo, ordine, attiva, tutte) VALUES (?, ?, ?, ?, ?)', $valori);
            $id = (int)db()->lastInsertId();
        }
        q('DELETE FROM varianti_categorie WHERE variante_id = ?', [$id]);
        if (!$dati['tutte']) {
            foreach ($dati['categorie'] as $c) {
                q('INSERT INTO varianti_categorie (variante_id, categoria_id) VALUES (?, ?)', [$id, $c]);
            }
        }
        $_SESSION['msg'] = "Variante \"{$dati['nome']}\" salvata.";
        header("Location: varianti.php?cat=" . (int)($_POST['filtro'] ?? 0) . "&id=$id");
        exit;
    }
}

// ------------------------------------------------------------
//  Dati per la pagina
// ------------------------------------------------------------
$filtro = (int)($_GET['cat'] ?? ($_POST['filtro'] ?? 0));   // 0 = tutte le varianti
if ($filtro && !in_array($filtro, $cat_ids, true)) $filtro = 0;

$varianti = q('SELECT v.*, (SELECT GROUP_CONCAT(c.nome ORDER BY c.ordine SEPARATOR ", ")
                            FROM varianti_categorie vc JOIN categorie c ON c.id = vc.categoria_id
                            WHERE vc.variante_id = v.id) AS cat_nomi
               FROM varianti v
               WHERE ? = 0 OR v.tutte = 1
                  OR EXISTS (SELECT 1 FROM varianti_categorie vc WHERE vc.variante_id = v.id AND vc.categoria_id = ?)
               ORDER BY v.attiva DESC, v.ordine, v.nome', [$filtro, $filtro])->fetchAll();

if (!$dati) {
    $id_sel = (int)($_GET['id'] ?? 0);
    $dati = $id_sel ? q('SELECT * FROM varianti WHERE id = ?', [$id_sel])->fetch() : null;
    if ($dati) {
        $dati['categorie'] = array_map('intval', q('SELECT categoria_id FROM varianti_categorie WHERE variante_id = ?',
                                                   [$dati['id']])->fetchAll(PDO::FETCH_COLUMN));
    } else {
        $dati = ['id' => 0, 'nome' => '', 'prezzo' => '0.00', 'ordine' => 0, 'attiva' => 1, 'tutte' => 0,
                 'categorie' => $filtro ? [$filtro] : []];
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
<title><?= h(impostazione('nome_locale')) ?> - Varianti</title>
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
    <span class="data">Impostazioni · Varianti</span>
    <span><?= date('H:i') ?></span>
  </div>

  <!-- Filtro per categoria -->
  <div class="sale-tabs cat-tabs">
    <a class="sala-tab <?= $filtro === 0 ? 'attiva' : '' ?>" href="varianti.php">Tutte</a>
    <?php foreach ($categorie as $c): ?>
      <a class="sala-tab <?= (int)$c['id'] === $filtro ? 'attiva' : '' ?>" href="varianti.php?cat=<?= $c['id'] ?>"><?= h($c['nome']) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="main">
    <div class="navcol">
      <a class="btn menu" href="sala.php"><span class="ico">🏠</span>Menu</a>
      <a class="btn" href="impostazioni.php"><span class="ico">⚙</span>Impostazioni</a>
      <a class="btn verde" href="varianti.php?cat=<?= $filtro ?>"><span class="ico">＋</span>Nuova</a>
    </div>

    <!-- Elenco varianti -->
    <div class="ordini varianti-gestione">
      <?php foreach ($varianti as $v): ?>
        <a class="ordine <?= $v['attiva'] ? '' : 'sospesa' ?> <?= $v['id'] == $dati['id'] ? 'sel' : '' ?>"
           href="varianti.php?cat=<?= $filtro ?>&id=<?= $v['id'] ?>">
          <span class="cliente"><?= h($v['nome']) ?></span>
          <span class="dettagli"><b><?= (float)$v['prezzo'] ? '+' . $valuta . ' ' . number_format($v['prezzo'], 2, ',', '.') : 'gratis' ?></b></span>
          <span class="dettagli"><?= $v['tutte'] ? 'Tutte le categorie' : h($v['cat_nomi'] ?: '—') ?></span>
          <?php if (!$v['attiva']): ?><span class="stato vuota">Disattivata</span><?php endif; ?>
        </a>
      <?php endforeach; ?>
      <?php if (!$varianti): ?>
        <div class="vuoto">Nessuna variante<?= $filtro ? ' per questa categoria' : '' ?>. Usa il modulo a destra per aggiungerne una.</div>
      <?php endif; ?>
    </div>

    <!-- Scheda variante -->
    <form class="scheda" method="post">
      <h2><?= $dati['id'] ? 'Modifica variante' : 'Nuova variante' ?></h2>
      <?php if ($msg): ?><div class="msg ok"><?= h($msg) ?></div><?php endif; ?>
      <?php if ($errore): ?><div class="msg errore"><?= h($errore) ?></div><?php endif; ?>

      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
      <input type="hidden" name="id" value="<?= (int)$dati['id'] ?>">
      <input type="hidden" name="filtro" value="<?= $filtro ?>">

      <label>Nome (es. Mozzarella di bufala)
        <input type="text" name="nome" maxlength="50" required value="<?= h($dati['nome']) ?>">
      </label>

      <div class="due">
        <label>Prezzo aggiunta (<?= h($valuta) ?>)
          <input type="text" name="prezzo" inputmode="decimal" required value="<?= h(str_replace('.', ',', $dati['prezzo'])) ?>">
        </label>
        <label>Ordine
          <input type="number" name="ordine" value="<?= (int)$dati['ordine'] ?>">
        </label>
      </div>
      <div class="hint">Con "+" si aggiunge il prezzo; con "−" (senza) è gratis.</div>

      <label>Vale per</label>
      <label class="check">
        <input type="checkbox" name="tutte" id="tutte" <?= $dati['tutte'] ? 'checked' : '' ?>> Tutte le categorie
      </label>
      <div class="elenco-categorie" id="elencoCategorie">
        <?php foreach ($categorie as $c): ?>
          <label class="check"><input type="checkbox" name="categorie[]" value="<?= $c['id'] ?>"
                 <?= in_array((int)$c['id'], $dati['categorie'], true) ? 'checked' : '' ?>> <?= h($c['nome']) ?></label>
        <?php endforeach; ?>
      </div>

      <label class="check">
        <input type="checkbox" name="attiva" <?= $dati['attiva'] ? 'checked' : '' ?>> Attiva
      </label>

      <div class="scheda-piede">
        <button type="submit" name="azione" value="salva" class="btn verde"><span class="ico">✔</span>Salva</button>
        <?php if ($dati['id']): ?>
          <button type="submit" name="azione" value="cancella" class="btn rosso" formnovalidate
                  onclick="return confirm('Cancellare questa variante?')"><span class="ico">🗑</span>Cancella</button>
        <?php endif; ?>
      </div>
    </form>
  </div>

<script>
  // Con "Tutte le categorie" l'elenco delle singole categorie non serve
  const tutte = document.getElementById('tutte');
  const elenco = document.getElementById('elencoCategorie');
  const aggiorna = () => elenco.classList.toggle('spento', tutte.checked);
  tutte.addEventListener('change', aggiorna);
  aggiorna();
</script>
</body>
</html>
